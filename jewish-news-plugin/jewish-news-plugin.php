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

function migrador_noticias_status() {
	$queue     = migrador_noticias_read_state( 'queue.json' );
	$completed = migrador_noticias_read_state( 'completed.json' );
	$errors    = migrador_noticias_read_state( 'errors.json' );
	$total     = count( $queue ) + count( $completed ) + count( $errors );

	return array(
		'queue'     => count( $queue ),
		'completed' => count( $completed ),
		'errors'    => count( $errors ),
		'total'     => $total,
		'processed' => count( $completed ) + count( $errors ),
		'next_url'  => ! empty( $queue ) ? reset( $queue ) : '',
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
	foreach ( $xml->xpath( '//*[local-name()="loc"]' ) as $loc ) {
		$url = esc_url_raw( trim( (string) $loc ) );
		if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) && migrador_noticias_is_legacy_url( $url ) ) {
			$urls[] = $url;
		}
	}
	$urls = array_values( array_unique( $urls ) );
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

function migrador_noticias_selector_to_xpath( $selector ) {
	// A configuração aceita um seletor de classe simples, como .conten_site_content.
	if ( 0 === strpos( $selector, '.' ) && preg_match( '/^\.([A-Za-z0-9_-]+)$/', $selector, $matches ) ) {
		return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $matches[1] . ' ")]';
	}
	return '';
}

function migrador_noticias_finish_item( $url, $success, $details ) {
	$queue = migrador_noticias_read_state( 'queue.json' );
	$index = array_search( $url, $queue, true );
	if ( false !== $index ) {
		unset( $queue[ $index ] );
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
		$image_node = $xpath->query( '//meta[@property="og:image"]/@content' )->item( 0 );
		if ( ! $image_node ) {
			$image_node = $xpath->query( './/img[@src]/@src', $container )->item( 0 );
		}
		$image_url = $image_node ? esc_url_raw( trim( $image_node->nodeValue ) ) : '';
		if ( $image_url && ! wp_parse_url( $image_url, PHP_URL_HOST ) ) {
			$image_url = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $url, PHP_URL_HOST ) . '/' . ltrim( $image_url, '/' );
		}

		$existing = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_url_origem_net', 'meta_value' => $url, 'fields' => 'ids', 'posts_per_page' => 1 ) );
		$post_id = $existing ? (int) $existing[0] : wp_insert_post( array( 'post_title' => $title, 'post_content' => wp_kses_post( $content ), 'post_status' => 'publish', 'post_type' => 'post', 'post_name' => $slug ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}
		update_post_meta( $post_id, '_url_origem_net', $url );

		if ( $image_url && ! has_post_thumbnail( $post_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, (int) $attachment_id );
			}
		}

		migrador_noticias_finish_item( $url, true, array( 'post_id' => $post_id, 'title' => $title, 'http_code' => $http_code ) );
		migrador_noticias_release_lock( $lock );
		wp_send_json_success( array_merge( migrador_noticias_status(), array( 'url' => $url, 'title' => $title, 'post_id' => $post_id, 'http_code' => $http_code, 'message' => 'Notícia importada.' ) ) );
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
