<?php
/** Configurações centralizadas da migração. */
defined( 'ABSPATH' ) || exit;

define( 'MIGRATION_SITE_URL', 'https://portuguesejewishnews.com' );
define( 'MIGRATION_SITEMAP_URL', 'https://portuguesejewishnews.com/sitemap/' );
define( 'MIGRATION_TARGET_CONTAINER', '.blog-post' );
define( 'MIGRATION_DELAY_MS', 2000 );
// URLs que começam com /news/ serão importadas como posts; as outras serão páginas.
define( 'MIGRATION_POST_PATH_PREFIX', 'news' );
