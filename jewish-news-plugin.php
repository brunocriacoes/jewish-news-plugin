<?php
/**
 * Plugin Name: Migrador .NET de Notícias
 * Description: Importa notícias de um sitemap XML usando uma fila AJAX controlada pelo painel administrativo.
 * Version: 1.0.0
 * Author: Seu Nome
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'MIGRADOR_NOTICIAS_FILE', __FILE__ );
define( 'MIGRADOR_NOTICIAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGRADOR_NOTICIAS_URL', plugin_dir_url( __FILE__ ) );
define( 'MIGRADOR_NOTICIAS_DATA_DIR', MIGRADOR_NOTICIAS_DIR . 'data/' );

require_once MIGRADOR_NOTICIAS_DIR . 'config.php';

/** Cria arquivos de estado vazios e impede a listagem direta do diretório. */
function migrador_noticias_activate() {
	if ( ! wp_mkdir_p( MIGRADOR_NOTICIAS_DATA_DIR ) ) {
		return;
	}

	foreach ( array( 'queue.json', 'completed.json', 'errors.json' ) as $file ) {
		$path = MIGRADOR_NOTICIAS_DATA_DIR . $file;
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, '[]', LOCK_EX );
		}
	}

	if ( ! file_exists( MIGRADOR_NOTICIAS_DATA_DIR . 'index.php' ) ) {
		file_put_contents( MIGRADOR_NOTICIAS_DATA_DIR . 'index.php', "<?php // Silence is golden.\n", LOCK_EX );
	}
	if ( ! file_exists( MIGRADOR_NOTICIAS_DATA_DIR . '.htaccess' ) ) {
		file_put_contents( MIGRADOR_NOTICIAS_DATA_DIR . '.htaccess', "Deny from all\n", LOCK_EX );
	}
}
register_activation_hook( __FILE__, 'migrador_noticias_activate' );

function migrador_noticias_can_manage() {
	check_ajax_referer( 'migrador_noticias_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Você não tem permissão para executar a migração.', 'migrador-noticias' ) ), 403 );
	}
}

function migrador_noticias_read_state( $file ) {
	$path = MIGRADOR_NOTICIAS_DATA_DIR . basename( $file );
	if ( ! file_exists( $path ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $data ) ? $data : array();
}

function migrador_noticias_write_state( $file, array $data ) {
	return false !== file_put_contents( MIGRADOR_NOTICIAS_DATA_DIR . basename( $file ), wp_json_encode( array_values( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), LOCK_EX );
}

/** Mantém compatibilidade com filas antigas que armazenavam somente a URL. */
function migrador_noticias_queue_entry_url( $entry ) {
	return is_array( $entry ) && ! empty( $entry['url'] ) ? $entry['url'] : (string) $entry;
}

function migrador_noticias_queue_entry_lastmod( $entry ) {
	return is_array( $entry ) && ! empty( $entry['lastmod'] ) ? $entry['lastmod'] : '';
}

function migrador_noticias_get_queue_entry( $url ) {
	foreach ( migrador_noticias_read_state( 'queue.json' ) as $entry ) {
		if ( $url === migrador_noticias_queue_entry_url( $entry ) ) {
			return $entry;
		}
	}
	return array();
}

/** Converte a data do sitemap para os campos local e GMT aceitos pelo WordPress. */
function migrador_noticias_get_post_dates( $lastmod ) {
	$timestamp = $lastmod ? strtotime( $lastmod ) : false;
	if ( false === $timestamp ) {
		return array();
	}
	return array(
		'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
	);
}

function migrador_noticias_status() {
	$queue     = migrador_noticias_read_state( 'queue.json' );
	$completed = migrador_noticias_read_state( 'completed.json' );
	$errors    = migrador_noticias_read_state( 'errors.json' );
	$total     = count( $queue ) + count( $completed ) + count( $errors );
	$category_status = migrador_noticias_category_status( $queue );

	return array(
		'queue'     => count( $queue ),
		'completed' => count( $completed ),
		'errors'    => count( $errors ),
		'total'     => $total,
		'processed' => count( $completed ) + count( $errors ),
		'next_url'  => ! empty( $queue ) ? migrador_noticias_queue_entry_url( reset( $queue ) ) : '',
		'categories_site_total' => $category_status['site_total'],
		'categories_existing'   => $category_status['existing'],
		'categories_pending'    => $category_status['pending'],
	);
}

/** Confere se a URL pertence ao site legado definido em config.php. */
function migrador_noticias_is_legacy_url( $url ) {
	$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$site_host = strtolower( (string) wp_parse_url( MIGRATION_SITE_URL, PHP_URL_HOST ) );
	return $url_host && $site_host && $url_host === $site_host;
}

/** Obtém os <loc> do sitemap e reinicia os estados da migração. */
function migrador_noticias_read_sitemap() {
	migrador_noticias_can_manage();
	$response = wp_remote_get( MIGRATION_SITEMAP_URL, array( 'timeout' => 30, 'redirection' => 3 ) );
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		wp_send_json_error( array( 'message' => sprintf( 'Sitemap respondeu HTTP %d.', (int) wp_remote_retrieve_response_code( $response ) ) ), 502 );
	}

	$xml_string = wp_remote_retrieve_body( $response );
	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( $xml_string );
	if ( false === $xml ) {
		wp_send_json_error( array( 'message' => 'Não foi possível interpretar o XML do sitemap.' ), 422 );
	}

	$urls = array();
	foreach ( $xml->xpath( '//*[local-name()="url"]' ) as $url_node ) {
		$loc_nodes = $url_node->xpath( './*[local-name()="loc"]' );
		$lastmod_nodes = $url_node->xpath( './*[local-name()="lastmod"]' );
		$url = ! empty( $loc_nodes ) ? esc_url_raw( trim( (string) $loc_nodes[0] ) ) : '';
		if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) && migrador_noticias_is_legacy_url( $url ) ) {
			$urls[ $url ] = array( 'url' => $url, 'lastmod' => ! empty( $lastmod_nodes ) ? sanitize_text_field( trim( (string) $lastmod_nodes[0] ) ) : '' );
		}
	}
	$urls = array_values( $urls );
	if ( empty( $urls ) ) {
		wp_send_json_error( array( 'message' => 'Nenhuma URL válida foi encontrada no sitemap.' ), 422 );
	}

	migrador_noticias_write_state( 'queue.json', $urls );
	migrador_noticias_write_state( 'completed.json', array() );
	migrador_noticias_write_state( 'errors.json', array() );
	@unlink( MIGRADOR_NOTICIAS_DATA_DIR . 'process.lock' );
	wp_send_json_success( array_merge( migrador_noticias_status(), array( 'message' => count( $urls ) . ' URLs adicionadas à fila.' ) ) );
}
add_action( 'wp_ajax_migrador_noticias_read_sitemap', 'migrador_noticias_read_sitemap' );

function migrador_noticias_acquire_lock() {
	$path = MIGRADOR_NOTICIAS_DATA_DIR . 'process.lock';
	// Lock abandonado por uma requisição encerrada não deve bloquear a migração indefinidamente.
	if ( file_exists( $path ) && ( time() - filemtime( $path ) ) > 120 ) {
		@unlink( $path );
	}
	$handle = @fopen( $path, 'x' );
	if ( ! $handle ) {
		return false;
	}
	fwrite( $handle, (string) time() );
	return $handle;
}

function migrador_noticias_release_lock( $handle ) {
	if ( is_resource( $handle ) ) {
		fclose( $handle );
	}
	@unlink( MIGRADOR_NOTICIAS_DATA_DIR . 'process.lock' );
}

function migrador_noticias_inner_html( DOMDocument $document, DOMNode $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $document->saveHTML( $child );
	}
	return $html;
}

function migrador_noticias_convert_to_utf8( $html ) {
	if ( function_exists( 'mb_detect_encoding' ) ) {
		$encoding = mb_detect_encoding( $html, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
		if ( $encoding && 'UTF-8' !== strtoupper( $encoding ) ) {
			return mb_convert_encoding( $html, 'UTF-8', $encoding );
		}
	}
	return $html;
}

/** Converte uma URL de mídia relativa em URL absoluta usando a página de origem como referência. */
function migrador_noticias_resolve_url( $raw_url, $page_url ) {
	$raw_url = trim( html_entity_decode( (string) $raw_url ) );
	if ( ! $raw_url ) {
		return '';
	}
	if ( 0 === strpos( $raw_url, '//' ) ) {
		return (string) wp_parse_url( $page_url, PHP_URL_SCHEME ) . ':' . $raw_url;
	}
	if ( wp_parse_url( $raw_url, PHP_URL_HOST ) ) {
		return esc_url_raw( $raw_url );
	}
	$scheme = (string) wp_parse_url( $page_url, PHP_URL_SCHEME );
	$host   = (string) wp_parse_url( $page_url, PHP_URL_HOST );
	if ( 0 === strpos( $raw_url, '/' ) ) {
		return esc_url_raw( $scheme . '://' . $host . $raw_url );
	}
	$directory = trailingslashit( dirname( (string) wp_parse_url( $page_url, PHP_URL_PATH ) ) );
	return esc_url_raw( $scheme . '://' . $host . $directory . $raw_url );
}

function migrador_noticias_selector_to_xpath( $selector ) {
	// A configuração aceita um seletor de classe simples, como .conten_site_content.
	if ( 0 === strpos( $selector, '.' ) && preg_match( '/^\.([A-Za-z0-9_-]+)$/', $selector, $matches ) ) {
		return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $matches[1] . ' ")]';
	}
	return '';
}

/** URLs com categoria e slug (/categoria/slug) são posts; /slug é uma página. */
function migrador_noticias_get_content_type( $url ) {
	$segments = array_values( array_filter( explode( '/', trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) ) );
	return count( $segments ) >= 2 ? 'post' : 'page';
}

/** Lê todas as categorias indicadas pelos links no bloco .post-meta da notícia. */
function migrador_noticias_get_post_meta_categories( DOMXPath $xpath ) {
	$links = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " post-meta ")]//a[@href]' );
	if ( ! $links || ! $links->length ) {
		return array();
	}
	$categories = array();
	foreach ( $links as $link ) {
		$name = sanitize_text_field( trim( $link->textContent ) );
		if ( $name ) {
			$categories[ sanitize_title( $name ) ] = $name;
		}
	}
	return array_values( $categories );
}

/** Retorna categorias da fila já existentes e as que ainda precisam ser criadas. */
function migrador_noticias_category_status( array $queue ) {
	$category_slugs = array();
	foreach ( $queue as $entry ) {
		$url = migrador_noticias_queue_entry_url( $entry );
		if ( 'post' !== migrador_noticias_get_content_type( $url ) ) {
			continue;
		}
		$segments = array_values( array_filter( explode( '/', trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) ) );
		$category = ! empty( $segments[0] ) ? sanitize_text_field( ucwords( str_replace( '-', ' ', rawurldecode( $segments[0] ) ) ) ) : '';
		if ( $category ) {
			$category_slugs[ sanitize_title( $category ) ] = $category;
		}
	}

	$existing = 0;
	foreach ( $category_slugs as $slug => $name ) {
		if ( get_category_by_slug( $slug ) || get_term_by( 'name', $name, 'category' ) ) {
			$existing++;
		}
	}
	return array(
		'site_total' => count( get_categories( array( 'hide_empty' => false ) ) ),
		'existing'   => $existing,
		'pending'    => count( $category_slugs ) - $existing,
	);
}

/** Localiza a categoria pelo slug/nome ou cria uma categoria nova para a notícia. */
function migrador_noticias_get_or_create_categories( array $category_names ) {
	$result = array( 'ids' => array(), 'names' => array(), 'created' => array(), 'reused' => array() );
	foreach ( $category_names as $category_name ) {
		$category_name = sanitize_text_field( $category_name );
		if ( ! $category_name ) {
			continue;
		}
		$slug = sanitize_title( $category_name );
		$term = get_category_by_slug( $slug );
		if ( ! $term ) {
			$term = get_term_by( 'name', $category_name, 'category' );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			$result['ids'][] = (int) $term->term_id;
			$result['reused'][] = $term->name;
			$result['names'][] = $term->name;
			continue;
		}
		$term_id = wp_insert_category( array( 'cat_name' => $category_name, 'category_nicename' => $slug ) );
		if ( ! is_wp_error( $term_id ) && $term_id ) {
			$result['ids'][] = (int) $term_id;
			$result['created'][] = $category_name;
			$result['names'][] = $category_name;
		}
	}
	$result['ids'] = array_values( array_unique( $result['ids'] ) );
	return $result;
}

/** Extrai o nome do autor exibido no bloco .post-author-details, quando houver. */
function migrador_noticias_get_post_author_name( DOMXPath $xpath ) {
	$author_nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " post-author-details ")]//*[contains(concat(" ", normalize-space(@class), " "), " author-name ") or @rel="author" or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]' );
	if ( ! $author_nodes || ! $author_nodes->length ) {
		$author_nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " post-author-details ")]//a[normalize-space()]' );
	}
	if ( ! $author_nodes || ! $author_nodes->length ) {
		return '';
	}
	return sanitize_text_field( trim( $author_nodes->item( 0 )->textContent ) );
}

/** Reutiliza o autor existente ou cria uma conta de autor usando nome@dominio-do-wordpress. */
function migrador_noticias_get_or_create_author( $author_name ) {
	$author_name = sanitize_text_field( $author_name );
	if ( ! $author_name ) {
		return array( 'id' => 1, 'name' => '', 'created' => false );
	}

	$login_base = sanitize_user( sanitize_title( $author_name ), true );
	$login_base = $login_base ? $login_base : 'autor-migrado';
	$user = get_user_by( 'login', $login_base );
	if ( ! $user ) {
		$user = get_user_by( 'slug', sanitize_title( $author_name ) );
	}
	if ( $user ) {
		return array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'created' => false );
	}

	$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$domain = preg_replace( '/^www\./i', '', $domain );
	$login = $login_base;
	$index = 2;
	while ( username_exists( $login ) ) {
		$login = $login_base . $index;
		$index++;
	}
	$email = sanitize_email( $login . '@' . $domain );
	while ( email_exists( $email ) ) {
		$email = sanitize_email( $login . $index . '@' . $domain );
		$index++;
	}

	$user_id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'display_name' => $author_name, 'nickname' => $author_name, 'user_pass' => wp_generate_password( 32, true, true ), 'role' => 'author' ) );
	if ( is_wp_error( $user_id ) ) {
		return array( 'id' => 1, 'name' => '', 'created' => false );
	}
	return array( 'id' => (int) $user_id, 'name' => $author_name, 'created' => true );
}

function migrador_noticias_finish_item( $url, $success, $details ) {
	$queue = migrador_noticias_read_state( 'queue.json' );
	$removed = false;
	foreach ( $queue as $index => $entry ) {
		if ( $url === migrador_noticias_queue_entry_url( $entry ) ) {
			unset( $queue[ $index ] );
			$removed = true;
			break;
		}
	}
	if ( $removed ) {
		migrador_noticias_write_state( 'queue.json', $queue );
	}
	$file  = $success ? 'completed.json' : 'errors.json';
	$items = migrador_noticias_read_state( $file );
	$items[] = array_merge( array( 'url' => $url, 'date' => current_time( 'mysql' ) ), $details );
	migrador_noticias_write_state( $file, $items );
}

/** Busca e importa exatamente uma URL. O JavaScript decide quando chamar novamente. */
function migrador_noticias_import_single_url() {
	migrador_noticias_can_manage();
	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! migrador_noticias_is_legacy_url( $url ) ) {
		wp_send_json_error( array( 'message' => 'URL inválida.' ), 400 );
	}
	$queue_entry = migrador_noticias_get_queue_entry( $url );
	$sitemap_date = migrador_noticias_queue_entry_lastmod( $queue_entry );
	$post_dates = migrador_noticias_get_post_dates( $sitemap_date );

	$lock = migrador_noticias_acquire_lock();
	if ( ! $lock ) {
		wp_send_json_error( array( 'message' => 'Outra importação está em andamento. Tente novamente em alguns segundos.', 'busy' => true ), 409 );
	}

	try {
		$response = wp_remote_get( $url, array( 'timeout' => 45, 'redirection' => 3, 'user-agent' => 'WordPress Migration Bot/1.0' ) );
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			throw new Exception( sprintf( 'Página respondeu HTTP %d.', $http_code ) );
		}

		$html = migrador_noticias_convert_to_utf8( wp_remote_retrieve_body( $response ) );
		libxml_use_internal_errors( true );
		$document = new DOMDocument();
		if ( ! $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			throw new Exception( 'Não foi possível interpretar o HTML da página.' );
		}
		$xpath = new DOMXPath( $document );
		$container_xpath = migrador_noticias_selector_to_xpath( MIGRATION_TARGET_CONTAINER );
		$containers = $container_xpath ? $xpath->query( $container_xpath ) : false;
		if ( ! $containers || ! $containers->length ) {
			throw new Exception( 'Container de conteúdo não encontrado: ' . MIGRATION_TARGET_CONTAINER );
		}
		$container = $containers->item( 0 );
		$content = migrador_noticias_inner_html( $document, $container );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			throw new Exception( 'O container de conteúdo está vazio.' );
		}

		$title_node = $xpath->query( '//title' )->item( 0 );
		if ( ! $title_node ) {
			$title_node = $xpath->query( '//h1' )->item( 0 );
		}
		$title = $title_node ? sanitize_text_field( trim( $title_node->textContent ) ) : '';
		if ( ! $title ) {
			$title = 'Notícia migrada';
		}
		$slug = sanitize_title( basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$content_type = migrador_noticias_get_content_type( $url );
		$category_data = 'post' === $content_type ? migrador_noticias_get_or_create_categories( migrador_noticias_get_post_meta_categories( $xpath ) ) : array( 'ids' => array(), 'names' => array(), 'created' => array(), 'reused' => array() );
		$author_data = 'post' === $content_type ? migrador_noticias_get_or_create_author( migrador_noticias_get_post_author_name( $xpath ) ) : array( 'id' => 1, 'name' => '', 'created' => false );
		// A imagem do conteúdo tem prioridade sobre og:image para garantir que a miniatura represente a notícia.
		$content_image_node   = $xpath->query( './/img[@src]', $container )->item( 0 );
		$content_image_source = $content_image_node ? $content_image_node->getAttribute( 'src' ) : '';
		$image_source         = $content_image_source;
		if ( ! $image_source ) {
			$image_node   = $xpath->query( '//meta[@property="og:image"]/@content' )->item( 0 );
			$image_source = $image_node ? $image_node->nodeValue : '';
		}
		$image_url = migrador_noticias_resolve_url( $image_source, $url );

		$existing = get_posts( array( 'post_type' => $content_type, 'post_status' => 'any', 'meta_key' => '_url_origem_net', 'meta_value' => $url, 'fields' => 'ids', 'posts_per_page' => 1 ) );
		$post_args = array_merge( array( 'post_title' => $title, 'post_content' => wp_kses_post( $content ), 'post_status' => 'publish', 'post_type' => $content_type, 'post_name' => $slug, 'post_author' => $author_data['id'] ), $post_dates );
		$post_id = $existing ? (int) $existing[0] : wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}
		if ( $existing ) {
			wp_update_post( array_merge( array( 'ID' => $post_id, 'post_author' => $author_data['id'] ), $post_dates ) );
		}
		update_post_meta( $post_id, '_url_origem_net', $url );
		if ( 'post' === $content_type && ! empty( $category_data['ids'] ) ) {
			wp_set_post_categories( $post_id, $category_data['ids'], false );
		}

		if ( $image_url && ! has_post_thumbnail( $post_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, (int) $attachment_id );
				// Quando a imagem veio do conteúdo, substitui o endereço remoto pela cópia da biblioteca.
				if ( $content_image_source ) {
					$local_image_url = wp_get_attachment_url( (int) $attachment_id );
					$local_content   = str_replace( $content_image_source, $local_image_url, $content );
					if ( $local_content !== $content ) {
						wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $local_content ) ) );
					}
				}
			}
		}

		migrador_noticias_finish_item( $url, true, array( 'post_id' => $post_id, 'title' => $title, 'sitemap_date' => $sitemap_date, 'content_type' => $content_type, 'author' => $author_data['name'], 'author_created' => $author_data['created'], 'categories' => $category_data['names'], 'categories_created' => $category_data['created'], 'categories_reused' => $category_data['reused'], 'http_code' => $http_code ) );
		migrador_noticias_release_lock( $lock );
		wp_send_json_success( array_merge( migrador_noticias_status(), array( 'url' => $url, 'title' => $title, 'post_id' => $post_id, 'content_type' => $content_type, 'author' => $author_data['name'], 'categories' => $category_data['names'], 'http_code' => $http_code, 'message' => 'Conteúdo importado.' ) ) );
	} catch ( Exception $exception ) {
		migrador_noticias_finish_item( $url, false, array( 'message' => $exception->getMessage() ) );
		migrador_noticias_release_lock( $lock );
		wp_send_json_error( array_merge( migrador_noticias_status(), array( 'url' => $url, 'message' => $exception->getMessage() ) ), 422 );
	}
}
add_action( 'wp_ajax_migrador_noticias_import_single_url', 'migrador_noticias_import_single_url' );

function migrador_noticias_get_status() {
	migrador_noticias_can_manage();
	wp_send_json_success( migrador_noticias_status() );
}
add_action( 'wp_ajax_migrador_noticias_get_status', 'migrador_noticias_get_status' );

function migrador_noticias_admin_menu() {
	add_menu_page( 'Migrador .NET', 'Migrador .NET', 'manage_options', 'migrador-noticias', 'migrador_noticias_render_admin_page', 'dashicons-database-import', 80 );
}
add_action( 'admin_menu', 'migrador_noticias_admin_menu' );

function migrador_noticias_admin_assets( $hook ) {
	if ( 'toplevel_page_migrador-noticias' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'migrador-noticias-script', MIGRADOR_NOTICIAS_URL . 'migrador-script.js', array(), '1.0.0', true );
	wp_localize_script( 'migrador-noticias-script', 'MigradorNoticias', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'migrador_noticias_nonce' ), 'delay' => (int) MIGRATION_DELAY_MS, 'initialStatus' => migrador_noticias_status() ) );
}
add_action( 'admin_enqueue_scripts', 'migrador_noticias_admin_assets' );

require_once MIGRADOR_NOTICIAS_DIR . 'admin-page.php';
