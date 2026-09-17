<?php
defined( 'ABSPATH' ) || exit;

function migrador_noticias_render_admin_page() {
	$status = migrador_noticias_status();
	?>
	<div class="wrap migrador-noticias-wrap">
		<h1>Migrador .NET</h1>
		<p>Importação sequencial e pausável para reduzir a carga no site de origem.</p>
		<div class="notice notice-info inline"><p><strong>Sitemap:</strong> <?php echo esc_html( MIGRATION_SITEMAP_URL ); ?><br><strong>Container CSS:</strong> <?php echo esc_html( MIGRATION_TARGET_CONTAINER ); ?></p></div>
		<p>
			<button type="button" class="button button-secondary" id="migrador-read-sitemap">1. Ler Sitemap e Gerar Fila</button>
			<button type="button" class="button button-primary" id="migrador-start">2. Iniciar Importação</button>
			<button type="button" class="button" id="migrador-pause" disabled>Pausar</button>
		</p>
		<div class="migrador-progress" aria-label="Progresso da migração"><div id="migrador-progress-bar" style="width:0%"></div></div>
		<p id="migrador-progress-text"><?php echo esc_html( sprintf( '%d / %d processados', $status['processed'], $status['total'] ) ); ?></p>
		<p><strong>Na fila:</strong> <span id="migrador-queue"><?php echo esc_html( $status['queue'] ); ?></span> · <strong>Concluídas:</strong> <span id="migrador-completed"><?php echo esc_html( $status['completed'] ); ?></span> · <strong>Erros:</strong> <span id="migrador-errors"><?php echo esc_html( $status['errors'] ); ?></span></p>
		<h2>Log</h2><pre id="migrador-log" aria-live="polite">Aguardando início.</pre>
	</div>
	<style>
		.migrador-progress { max-width:720px; height:24px; background:#dcdcde; border-radius:3px; overflow:hidden; }
		#migrador-progress-bar { height:100%; background:#2271b1; transition:width .25s ease; }
		#migrador-log { max-width:900px; min-height:210px; padding:14px; overflow:auto; color:#d7e4ef; background:#1d2327; white-space:pre-wrap; }
	</style>
	<?php
}
