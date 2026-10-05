<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   0. EDITOR CLÁSICO — deshabilitar Gutenberg completamente
      El editor de bloques se reemplaza por el editor HTML clásico
      en todos los tipos de contenido (páginas, posts, etc.).
   ============================================================ */
add_filter( 'use_block_editor_for_post_type', '__return_false', 10 );
add_filter( 'use_widgets_block_editor',       '__return_false' );

// Limpiar patrones de bloques registrados por WP core
add_action( 'after_setup_theme', function (): void {
	remove_theme_support( 'core-block-patterns' );
} );

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
	// Se conserva ?ver= en el tema Y en el plugin OEC: los dos versionan sus assets
	// (el plugin con filemtime()) para que, al actualizar, el navegador baje los
	// archivos nuevos en vez de mezclar JS viejo con HTML nuevo.
	if ( strpos( $src, '?ver=' ) && strpos( $src, '/themes/oec-wp-theme/' ) === false && strpos( $src, '/plugins/oec-wordpress-plugin/' ) === false ) {
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

/* ============================================================
   11. ROBOTS META — AEO / LLMs
       max-snippet:-1 y max-video-preview:-1 permiten que Google
       y crawlers de IA muestren fragmentos largos del contenido,
       mejorando la visibilidad en respuestas de LLMs y SGE.
   ============================================================ */
add_filter( 'wp_robots', function ( array $robots ): array {
	$robots['max-snippet']       = '-1';
	$robots['max-video-preview'] = '-1';
	return $robots;
} );

/* ============================================================
   12. PRECONNECT — imgrsize.oe-img.center
       Las fotos de las formaciones y los docentes salen de ahí; abrir
       la conexión temprano ahorra ~200 ms a la primera imagen.
       Bootstrap Icons ya no sale de jsdelivr (va en el tema), así que
       tampoco hace falta conectarse a ese CDN.
   ============================================================ */
add_filter( 'wp_resource_hints', function ( array $hints, string $relation_type ): array {
	if ( 'preconnect' === $relation_type ) {
		$hints[] = 'https://imgrsize.oe-img.center';
	}
	return $hints;
}, 10, 2 );

/* ============================================================
   12b. BOOTSTRAP ICONS — siempre la copia del tema
       El plugin OEC encola el mismo handle desde jsdelivr en la ficha de
       formación, y como carga antes que el tema, su URL gana. Se pisa acá
       para que en todo el sitio sea la misma hoja (una sola en caché).
   ============================================================ */
add_action( 'wp_enqueue_scripts', function (): void {
	$styles = wp_styles();
	if ( isset( $styles->registered['bootstrap-icons'] ) ) {
		$styles->registered['bootstrap-icons']->src = OEC_THEME_URI . '/assets/fonts/bootstrap-icons/bootstrap-icons.min.css';
		$styles->registered['bootstrap-icons']->ver = '1.11.3';
	}
}, 100 );

/* ============================================================
   12c. JQUERY — fuera de las páginas que no lo usan
       Llegaba en el <head> (bloqueando el render) a todas las páginas
       porque wp-automatic encola su galería en todo el sitio, aunque
       solo sirve en los posts que traen .wp_automatic_gallery. El
       tema no usa jQuery; el plugin OEC solo en /formacion.
       Si después de esto queda jQuery sin ningún script que dependa
       de él, se saca también.
   ============================================================ */
add_action( 'wp_enqueue_scripts', function (): void {
	if ( is_page( 'formacion' ) ) {
		return;
	}
	$post = is_singular() ? get_queried_object() : null;
	if ( ! $post instanceof WP_Post || false === strpos( $post->post_content, 'wp_automatic_gallery' ) ) {
		wp_dequeue_script( 'wp_automatic_gallery' );
		wp_dequeue_style( 'wp_automatic_gallery_style' );
	}

	$scripts = wp_scripts();
	if ( ! in_array( 'jquery', $scripts->queue, true ) ) {
		return;
	}
	$needs = function ( string $handle, int $depth = 0 ) use ( &$needs, $scripts ): bool {
		$deps = $scripts->registered[ $handle ]->deps ?? [];
		if ( array_intersect( $deps, [ 'jquery', 'jquery-core' ] ) ) {
			return true;
		}
		foreach ( $deps as $dep ) {
			if ( $depth < 5 && $needs( $dep, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	};
	foreach ( $scripts->queue as $handle ) {
		if ( ! in_array( $handle, [ 'jquery', 'jquery-core' ], true ) && $needs( $handle ) ) {
			return;
		}
	}
	wp_dequeue_script( 'jquery' );
}, 999 );

/* ============================================================
   12d. CSS/JS SIN EL PREFIJO DEL SUBSITIO
       En el multisite por carpeta, los assets salen como
       /es/wp-content/… y /es/wp-includes/…: el Nginx de Cloudways solo
       pone Cache-Control largo en /wp-content/… y /wp-includes/…, así que
       con el prefijo el navegador no los cacheaba. Es el mismo archivo
       físico; sin el prefijo además /es y /en comparten la caché.
   ============================================================ */
function oec_unprefix_asset_src( string $src ): string {
	static $from = null, $to = null;
	if ( null === $from ) {
		$from = $to = '';
		if ( is_multisite() && ! is_subdomain_install() && ! is_main_site() ) {
			$from = untrailingslashit( site_url() ) . '/';
			$to   = untrailingslashit( network_site_url() ) . '/';
		}
	}
	if ( $from && $from !== $to && str_starts_with( $src, $from ) ) {
		$rest = substr( $src, strlen( $from ) );
		if ( str_starts_with( $rest, 'wp-content/' ) || str_starts_with( $rest, 'wp-includes/' ) ) {
			return $to . $rest;
		}
	}
	return $src;
}
add_filter( 'style_loader_src',  'oec_unprefix_asset_src', 20 );
add_filter( 'script_loader_src', 'oec_unprefix_asset_src', 20 );

/* ============================================================
   13. CACHE-CONTROL — para que Cloudflare pueda cachear en el
       borde con stale-while-revalidate.
       Solo en el frontend público y para visitantes NO logueados:
       - Nunca en wp-admin ni en la API REST (esos endpoints son
         POST igualmente, así que Cloudflare no los cachearía, pero
         mejor no mandar la señal de "cacheable" ahí ni por error).
       - Nunca para un usuario logueado: esa respuesta puede traer
         admin bar, enlaces de edición o contenido de vista previa
         que NO debe terminar cacheado y servido a otra persona.
         (Además, del lado de Cloudflare conviene una Cache Rule que
         haga bypass cuando viaja la cookie wordpress_logged_in_*,
         como doble seguro.)
       - Un 404 recibe un TTL corto en vez de 24 h: si mañana esa URL
         pasa a existir (o era un typo pasajero), no queda "pegado"
         un 404 cacheado todo un día.
       - HTML: 5 minutos en el navegador (max-age: a un navegador no se
         le puede borrar la caché) y en el borde de Cloudflare (s-maxage)
         según el tipo de página — posts 30 días, listados 1 día,
         búsquedas 1 h —, con borrado automático cuando algo cambia
         (inc/cloudflare.php). CSS/JS/imágenes no pasan por acá (los sirve
         el servidor web con su propia caché larga, y el ?ver= del enqueue
         invalida cuando cambian).
   ============================================================ */
function oec_send_cache_headers(): void {
	if ( is_admin() || defined( 'REST_REQUEST' ) || is_user_logged_in() ) {
		return;
	}
	// Tiempo en Cloudflare según el tipo de página (posts 30 días, listados
	// 1 día, búsquedas 1 h, 404 1 min): ver inc/cloudflare.php.
	header( 'Cache-Control: ' . oec_cf_cache_control() );
}
// En 'wp' y no en 'send_headers': send_headers corre ANTES de la consulta,
// cuando is_404() todavía es siempre false (los 404 se cacheaban 10 min).
// 'wp' corre después de handle_404() y antes de template_redirect, así que
// los nocache_headers() de las plantillas (docente/organización
// inexistente, newsletter…) siguen pisando este header.
add_action( 'wp', 'oec_send_cache_headers' );

/* ============================================================
   14. OCULTAR VERSIÓN DE PHP — el header X-Powered-By lo agrega el
       propio PHP (ini "expose_php"), no WordPress; header_remove()
       lo saca de la respuesta sin necesitar tocar el php.ini del
       hosting. Se llama directo (sin action hook) porque este archivo
       ya se incluye desde functions.php en TODA request — frontend,
       wp-admin, wp-login.php — antes de que exista ningún redirect
       temprano (p. ej. auth_redirect() en wp-admin) que corte la
       ejecución antes de que un hook como admin_init llegue a disparar.
   ============================================================ */
if ( ! headers_sent() ) {
	header_remove( 'X-Powered-By' );
}
