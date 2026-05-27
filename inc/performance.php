<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   1. EMOJIS — eliminar completamente
      WP carga un script de detección + CSS + DNS prefetch
      para emojis que nadie usa. ~15 KB ahorrados.
   ============================================================ */
function oec_disable_emojis(): void {
	remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles',     'print_emoji_styles' );
	remove_action( 'admin_print_styles',  'print_emoji_styles' );
	remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
	remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );
	add_filter( 'tiny_mce_plugins',       'oec_remove_emoji_tinymce' );
	add_filter( 'wp_resource_hints',      'oec_remove_emoji_dns_prefetch', 10, 2 );
}
add_action( 'init', 'oec_disable_emojis' );

function oec_remove_emoji_tinymce( array $plugins ): array {
	return array_diff( $plugins, [ 'wpemoji' ] );
}

function oec_remove_emoji_dns_prefetch( array $urls, string $relation_type ): array {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls = array_values( array_diff( $urls, [ 'https://s.w.org' ] ) );
	}
	return $urls;
}

/* ============================================================
   2. COMENTARIOS — desactivar globalmente
      Cierra comentarios en posts existentes y nuevos,
      oculta el menú de admin y elimina los scripts relacionados.
   ============================================================ */

// Cerrar comentarios y pings en todos los posts
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open',    '__return_false', 20 );

// Devolver siempre lista de comentarios vacía
add_filter( 'comments_array', '__return_empty_array', 20 );

// Quitar la opción de comentarios del admin sidebar
function oec_remove_comments_menu(): void {
	remove_menu_page( 'edit-comments.php' );
	remove_submenu_page( 'options-general.php', 'options-discussion.php' );
}
add_action( 'admin_menu', 'oec_remove_comments_menu' );

// Quitar el widget de comentarios recientes del dashboard
function oec_remove_comments_dashboard(): void {
	remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
}
add_action( 'wp_dashboard_setup', 'oec_remove_comments_dashboard' );

// Quitar el contador de comentarios de la barra de admin
function oec_remove_comments_adminbar( WP_Admin_Bar $wp_admin_bar ): void {
	$wp_admin_bar->remove_node( 'comments' );
}
add_action( 'admin_bar_menu', 'oec_remove_comments_adminbar', 999 );

// Quitar los links de feed de comentarios del <head>
remove_action( 'wp_head', 'feed_links_extra', 3 );

/* ============================================================
   3. WP_HEAD BLOAT — tags innecesarios
      Cada uno es una petición HTTP o información expuesta
      que no aporta nada al visitante.
   ============================================================ */
function oec_clean_wp_head(): void {
	// Enlace al manifiesto de Windows Live Writer (nadie lo usa)
	remove_action( 'wp_head', 'wlwmanifest_link' );

	// Enlace RSD (Really Simple Discovery, para XML-RPC)
	remove_action( 'wp_head', 'rsd_link' );

	// Versión de WordPress (información sensible)
	remove_action( 'wp_head', 'wp_generator' );

	// Shortlink del post (/?p=123)
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );

	// Links de posts anterior/siguiente en el <head>
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

	// Descubrimiento de la API REST en el <head>
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );

	// oEmbed (descubrimiento de embeds externos)
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
}
add_action( 'after_setup_theme', 'oec_clean_wp_head' );

/* ============================================================
   4. JQUERY MIGRATE — eliminar en frontend
      WP carga jquery-migrate para compatibilidad con plugins
      viejos. Como nuestro tema no lo necesita, lo quitamos.
      OJO: si algún plugin deja de funcionar, reactivarlo.
   ============================================================ */
function oec_dequeue_jquery_migrate( WP_Scripts $scripts ): void {
	if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			[ 'jquery-migrate' ]
		);
	}
}
add_action( 'wp_default_scripts', 'oec_dequeue_jquery_migrate' );

/* ============================================================
   5. BLOCK EDITOR (GUTENBERG) — estilos en frontend
      Si no usás el editor de bloques para el diseño del sitio,
      estos estilos son peso muerto (~80 KB).
   ============================================================ */
function oec_dequeue_block_styles(): void {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'oec_dequeue_block_styles', 100 );

/* ============================================================
   6. DASHICONS en frontend — solo para admins logueados
      WP carga dashicons para todos los visitantes si la
      barra de admin está activa. Lo limitamos.
   ============================================================ */
function oec_dequeue_dashicons(): void {
	if ( ! is_user_logged_in() ) {
		wp_dequeue_style( 'dashicons' );
	}
}
add_action( 'wp_enqueue_scripts', 'oec_dequeue_dashicons', 100 );

/* ============================================================
   7. HEARTBEAT API — reducir polling
      Por defecto WordPress hace una petición AJAX cada 15 seg
      en el editor. En el frontend lo desactivamos, en el admin
      lo espaciamos a 60 seg.
   ============================================================ */
function oec_control_heartbeat( array $settings ): array {
	$settings['interval'] = 60;
	return $settings;
}
add_filter( 'heartbeat_settings', 'oec_control_heartbeat' );

function oec_disable_heartbeat_frontend(): void {
	if ( ! is_admin() ) {
		wp_deregister_script( 'heartbeat' );
	}
}
add_action( 'init', 'oec_disable_heartbeat_frontend' );

/* ============================================================
   8. XML-RPC — desactivar
      Vector de ataques de fuerza bruta. Si no usás apps
      móviles de WP ni Jetpack, no lo necesitás.
   ============================================================ */
add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'rsd_link' );

/* ============================================================
   9. QUERY STRINGS en assets
      Los ?ver=x.x.x en CSS/JS dificultan el cacheo en algunos
      servidores y CDNs. Los eliminamos de recursos estáticos.
   ============================================================ */
function oec_remove_query_strings( string $src ): string {
	if ( strpos( $src, '?ver=' ) ) {
		$src = remove_query_arg( 'ver', $src );
	}
	return $src;
}
add_filter( 'style_loader_src',  'oec_remove_query_strings', 15 );
add_filter( 'script_loader_src', 'oec_remove_query_strings', 15 );

/* ============================================================
   10. SELF-PING — WordPress se manda pings a sí mismo
       cuando publicás un post con links internos. Innecesario.
   ============================================================ */
function oec_no_self_ping( array &$links ): void {
	$home = home_url();
	foreach ( $links as $key => $link ) {
		if ( str_starts_with( $link, $home ) ) {
			unset( $links[ $key ] );
		}
	}
}
add_action( 'pre_ping', 'oec_no_self_ping' );
