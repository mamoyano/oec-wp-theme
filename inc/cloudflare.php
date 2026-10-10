<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   CACHÉ EN CLOUDFLARE
   Cloudflare guarda las páginas mucho tiempo y el tema borra lo que
   corresponde cuando algo cambia. Los navegadores guardan solo 5 min:
   a Cloudflare se le puede borrar la caché, a un navegador no.

   1. Tiempo en el borde (s-maxage) según el tipo de página:
      posts y páginas fijas 30 días; home, landings, listados y ficha de
      formación 1 día; búsquedas/filtros 1 h; 404 1 min. Más
      stale-while-revalidate y stale-if-error (si el servidor se cae,
      Cloudflare sigue mostrando la copia). Lo aplica una Cache Rule de
      Cloudflare con "Edge TTL: usar el Cache-Control del origen".
   2. Borrado automático: al publicar/editar/despublicar un post o una
      página, al terminar la sincronización del catálogo, y todo el sitio
      al actualizar el tema o el plugin, guardar Ajustes OEC o cambiar un menú.
   3. Barra de administración: "Caché → Borrar esta página / todo el sitio".
   4. /purge-cache.php?url=…: el enlace que usan los cargadores desde el
      campus (reemplaza al script del sitio viejo, misma URL). Borra la
      página en Cloudflare y, si es una ficha, los datos de la API que el
      plugin guarda un día.

   Credenciales en wp-config.php (nunca en el repo, que es público):
     define( 'OEC_CF_API_TOKEN', '…' ); // token con permiso Zone → Cache Purge
     define( 'OEC_CF_ZONE_ID', '…' );
   ============================================================ */

const OEC_CF_HOUR  = HOUR_IN_SECONDS;
const OEC_CF_DAY   = DAY_IN_SECONDS;
const OEC_CF_MONTH = 30 * DAY_IN_SECONDS;

/** Páginas (por slug) que muestran datos del catálogo o listas: 1 día. */
const OEC_CF_LISTADOS = [ 'formaciones', 'formacion', 'docentes', 'docente', 'organizaciones', 'organizacion', 'articulos', 'especiales', 'links' ];

/** Parámetros que no cambian el contenido (no bajan el tiempo a 1 h). */
const OEC_CF_PARAMS_NEUTROS = [ 'slug', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'trackers' ];

/* ── 1. Tiempo en Cloudflare según el tipo de página ─────────── */

function oec_cf_ttl(): int {
	if ( array_diff_key( $_GET, array_flip( OEC_CF_PARAMS_NEUTROS ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return OEC_CF_HOUR; // búsquedas y filtros: infinitas combinaciones
	}
	if ( is_singular( 'post' ) ) {
		return OEC_CF_MONTH;
	}
	if ( is_front_page() ) {
		return OEC_CF_DAY;
	}
	if ( is_page() ) {
		$page   = get_queried_object();
		$parent = $page instanceof WP_Post && $page->post_parent ? get_post( $page->post_parent ) : null;
		$slugs  = array_filter( [ $page->post_name ?? '', $parent->post_name ?? '' ] );
		return array_intersect( $slugs, OEC_CF_LISTADOS ) ? OEC_CF_DAY : OEC_CF_MONTH;
	}
	return OEC_CF_DAY; // categorías, etiquetas, autores y el resto
}

/** Cache-Control de las páginas públicas (lo llama inc/performance.php). */
function oec_cf_cache_control(): string {
	if ( is_404() ) {
		return 'public, max-age=60';
	}
	$ttl = oec_cf_ttl();
	return sprintf( 'public, max-age=300, s-maxage=%d, stale-while-revalidate=%d, stale-if-error=604800', $ttl, min( $ttl, OEC_CF_DAY ) );
}

/* ── 2. API de Cloudflare ────────────────────────────────────── */

function oec_cf_configured(): bool {
	return defined( 'OEC_CF_API_TOKEN' ) && defined( 'OEC_CF_ZONE_ID' ) && OEC_CF_API_TOKEN && OEC_CF_ZONE_ID;
}

/** Un pedido de borrado. true, o WP_Error con el motivo. Queda registrado para la barra de admin. */
function oec_cf_request( array $body, string $detalle ) {
	if ( ! oec_cf_configured() ) {
		$result = new WP_Error( 'oec_cf', __( 'Falta configurar OEC_CF_API_TOKEN y OEC_CF_ZONE_ID en wp-config.php.', 'oec-theme' ) );
	} else {
		$res  = wp_remote_post( 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( OEC_CF_ZONE_ID ) . '/purge_cache', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . OEC_CF_API_TOKEN, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		$data = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $data['success'] ) ) {
			$result = true;
		} else {
			$msg    = is_wp_error( $res ) ? $res->get_error_message() : implode( '; ', array_column( (array) ( $data['errors'] ?? [] ), 'message' ) );
			$result = new WP_Error( 'oec_cf', $msg ?: 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
	}
	update_site_option( 'oec_cf_last', [
		'time'    => time(),
		'detalle' => $detalle,
		'ok'      => true === $result,
		'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
	] );
	if ( is_wp_error( $result ) ) {
		error_log( '[OEC Cloudflare] ' . $detalle . ': ' . $result->get_error_message() );
	}
	return $result;
}

/** Borra URLs puntuales (Cloudflare acepta 30 por pedido). */
function oec_cf_purge_urls( array $urls ) {
	$urls = array_values( array_unique( array_filter( array_map( fn( $u ) => strtok( (string) $u, '#' ), $urls ) ) ) );
	if ( ! $urls ) {
		return true;
	}
	$result = true;
	foreach ( array_chunk( $urls, 30 ) as $lote ) {
		$r = oec_cf_request( [ 'files' => $lote ], count( $urls ) === 1 ? $urls[0] : sprintf( '%d URLs', count( $urls ) ) );
		if ( is_wp_error( $r ) ) {
			$result = $r;
		}
	}
	return $result;
}

function oec_cf_purge_all() {
	return oec_cf_request( [ 'purge_everything' => true ], __( 'todo el sitio', 'oec-theme' ) );
}

/* ── 3. Cola: los borrados automáticos se mandan al final del pedido ── */

function oec_cf_queue( array $urls = [], bool $todo = false ): void {
	static $registered = false;
	$GLOBALS['oec_cf_queue']['urls'] = array_merge( $GLOBALS['oec_cf_queue']['urls'] ?? [], $urls );
	$GLOBALS['oec_cf_queue']['all']  = ( $GLOBALS['oec_cf_queue']['all'] ?? false ) || $todo;
	if ( ! $registered ) {
		$registered = true;
		add_action( 'shutdown', 'oec_cf_flush_queue' );
	}
}

function oec_cf_flush_queue(): void {
	$q = $GLOBALS['oec_cf_queue'] ?? [];
	$GLOBALS['oec_cf_queue'] = [];
	if ( empty( $q['all'] ) && empty( $q['urls'] ) ) {
		return;
	}
	if ( ! oec_cf_configured() ) {
		return; // sin credenciales (p. ej. en local) no se registra nada
	}
	// La respuesta ya salió: el borrado no demora a quien guardó el post.
	if ( function_exists( 'fastcgi_finish_request' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		fastcgi_finish_request();
	}
	! empty( $q['all'] ) ? oec_cf_purge_all() : oec_cf_purge_urls( $q['urls'] );
}

/* ── 4. Qué borrar cuando cambia algo ────────────────────────── */

/** URLs que muestran un post o una página. */
function oec_cf_urls_for_post( WP_Post $post ): array {
	$urls = [ get_permalink( $post ) ];
	if ( 'post' === $post->post_type ) {
		$urls[] = home_url( '/' );
		$urls[] = oec_articulos_url( [] );
		foreach ( wp_get_post_terms( $post->ID, [ 'category', 'post_tag' ] ) as $term ) {
			$urls[] = get_term_link( $term );
		}
		$urls[] = get_author_posts_url( (int) $post->post_author );
		if ( function_exists( 'oec_sitemap_url' ) ) {
			$urls[] = oec_sitemap_url( 'articulos' );
		}
	} elseif ( $post->post_parent ) {
		$urls[] = get_permalink( $post->post_parent );
	}
	if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
		$urls[] = home_url( '/' );
	}
	return array_filter( $urls, 'is_string' );
}

add_action( 'transition_post_status', function ( string $new, string $old, WP_Post $post ): void {
	if ( in_array( $post->post_type, [ 'post', 'page' ], true ) && ( 'publish' === $new || 'publish' === $old ) && ! wp_is_post_revision( $post ) ) {
		oec_cf_queue( oec_cf_urls_for_post( $post ) );
	}
}, 10, 3 );

// Cambio de slug: también la URL vieja.
add_action( 'post_updated', function ( int $id, WP_Post $after, WP_Post $before ): void {
	if ( 'publish' === $before->post_status && $before->post_name !== $after->post_name ) {
		oec_cf_queue( [ str_replace( '/' . $after->post_name, '/' . $before->post_name, (string) get_permalink( $after ) ) ] );
	}
}, 10, 3 );

// Catálogo sincronizado: home, listados y landings.
add_action( 'oec_ai_catalog_synced', function (): void {
	$urls = [ home_url( '/' ) ];
	foreach ( [ 'formaciones', 'docentes', 'organizaciones', 'especiales', 'links' ] as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$urls[] = get_permalink( $page );
			foreach ( get_pages( [ 'child_of' => $page->ID ] ) as $child ) {
				$urls[] = get_permalink( $child );
			}
		}
	}
	oec_cf_queue( $urls );
} );

// Cambios que tocan todas las páginas: todo el sitio.
add_action( 'upgrader_process_complete', function ( $upgrader, array $options ): void {
	$temas   = (array) ( $options['themes'] ?? ( isset( $options['theme'] ) ? [ $options['theme'] ] : [] ) );
	$plugins = (array) ( $options['plugins'] ?? ( isset( $options['plugin'] ) ? [ $options['plugin'] ] : [] ) );
	if ( in_array( get_template(), $temas, true ) || in_array( 'oec-wordpress-plugin/oec-main.php', $plugins, true ) ) {
		oec_cf_queue( [], true );
	}
}, 10, 2 );
foreach ( [ 'update_option_' . OEC_OPTION, 'wp_update_nav_menu', 'customize_save_after', 'switch_theme' ] as $oec_cf_hook ) {
	add_action( $oec_cf_hook, fn() => oec_cf_queue( [], true ) );
}
unset( $oec_cf_hook );

/* ── 5. Ficha de formación: datos de la API guardados por el plugin ── */

/**
 * El plugin guarda un día los datos de cada ficha (wp_options "oec_cache_…",
 * ver oec-wordpress-plugin/oec-main.php). Si en el campus cambian algo y solo
 * se borra Cloudflare, la página se regenera con esos datos viejos.
 */
function oec_cf_purge_formacion_data( string $url ): bool {
	if ( ! preg_match( '/t-[a-zA-Z0-9]{14}/', $url, $m ) ) {
		return false;
	}
	$id = $m[0];
	foreach ( [
		"trainings/{$id}",
		"https://api.g-se.com/v2/content/trainings/{$id}/reviews?page=1",
		"https://oas-api.onlineeducation.center/api-oas/v1/trainings/{$id}/reviews/summary",
		"trainings/{$id}/parents",
	] as $endpoint ) {
		delete_option( 'oec_cache_' . md5( $endpoint ) );
	}
	return true;
}

/** Borra una URL del sitio en Cloudflare (y los datos de la ficha si es una formación). */
function oec_cf_purge_page( string $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$site = get_site_by_path( get_network()->domain, $path );
	if ( $site && is_multisite() ) {
		switch_to_blog( (int) $site->blog_id );
		oec_cf_purge_formacion_data( $url );
		restore_current_blog();
	} else {
		oec_cf_purge_formacion_data( $url );
	}
	$alt = str_ends_with( $url, '/' ) ? rtrim( $url, '/' ) : $url . '/';
	return oec_cf_purge_urls( [ $url, $alt ] );
}

/* ── 6. Barra de administración ──────────────────────────────── */

add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$last  = get_site_option( 'oec_cf_last' );
	$back  = rawurlencode( remove_query_arg( [ 'oec_cf', 'oec_cf_msg' ], ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
	$link  = fn( string $scope, string $url = '' ) => wp_nonce_url( admin_url( 'admin-post.php?action=oec_cf_purge&scope=' . $scope . '&back=' . $back . ( $url ? '&url=' . rawurlencode( $url ) : '' ) ), 'oec_cf_purge' );

	$bar->add_node( [ 'id' => 'oec-cf', 'title' => '⚡ ' . __( 'Caché', 'oec-theme' ), 'href' => false ] );
	if ( ! is_admin() ) {
		$bar->add_node( [ 'parent' => 'oec-cf', 'id' => 'oec-cf-page', 'title' => __( 'Borrar esta página', 'oec-theme' ), 'href' => $link( 'page', rawurldecode( $back ) ) ] );
	}
	if ( current_user_can( 'manage_options' ) ) {
		$bar->add_node( [
			'parent' => 'oec-cf',
			'id'     => 'oec-cf-all',
			'title'  => __( 'Borrar todo el sitio', 'oec-theme' ),
			'href'   => $link( 'all' ),
			'meta'   => [ 'onclick' => "return confirm('" . esc_js( __( '¿Borrar la caché de todo el sitio? Las próximas visitas van a cargar un poco más lento hasta que se vuelva a llenar.', 'oec-theme' ) ) . "');" ],
		] );
	}
	$estado = ! oec_cf_configured()
		? __( 'Sin configurar (wp-config.php)', 'oec-theme' )
		: ( $last ? sprintf( '%s %s · %s', $last['ok'] ? '✓' : '✗', sprintf( __( 'hace %s', 'oec-theme' ), human_time_diff( $last['time'] ) ), $last['detalle'] ) : __( 'Sin borrados todavía', 'oec-theme' ) );
	$bar->add_node( [ 'parent' => 'oec-cf', 'id' => 'oec-cf-last', 'title' => esc_html( $estado ), 'href' => false ] );
}, 90 );

add_action( 'admin_post_oec_cf_purge', function (): void {
	check_admin_referer( 'oec_cf_purge' );
	$scope = sanitize_key( $_GET['scope'] ?? '' );
	if ( ! current_user_can( 'all' === $scope ? 'manage_options' : 'edit_posts' ) ) {
		wp_die( esc_html__( 'No tenés permiso para borrar la caché.', 'oec-theme' ), 403 );
	}
	$url    = esc_url_raw( wp_unslash( $_GET['url'] ?? '' ) );
	$result = 'all' === $scope ? oec_cf_purge_all() : oec_cf_purge_page( $url );
	$back   = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['back'] ?? '' ) ), admin_url() );
	wp_safe_redirect( add_query_arg( [
		'oec_cf'     => true === $result ? 'ok' : 'error',
		'oec_cf_msg' => is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : false,
	], $back ) );
	exit;
} );

// Aviso del resultado (solo usuarios logueados: esas páginas no se cachean).
$oec_cf_notice = function (): void {
	$estado = sanitize_key( $_GET['oec_cf'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $estado || ! is_user_logged_in() ) {
		return;
	}
	$msg = 'ok' === $estado
		? __( 'Caché borrada en Cloudflare. Los cambios ya se ven (en tu navegador puede tardar hasta 5 minutos: recargá con Ctrl+F5 / Cmd+Shift+R).', 'oec-theme' )
		: __( 'No se pudo borrar la caché: ', 'oec-theme' ) . sanitize_text_field( wp_unslash( $_GET['oec_cf_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	printf(
		'<div class="notice notice-%1$s is-dismissible oec-cf-notice" style="%2$s"><p>%3$s</p></div>',
		'ok' === $estado ? 'success' : 'error',
		is_admin() ? '' : 'position:fixed;bottom:16px;left:16px;right:16px;max-width:560px;z-index:99999;padding:12px 16px;border-radius:8px;background:' . ( 'ok' === $estado ? '#ecfdf5;color:#065f46' : '#fef2f2;color:#991b1b' ) . ';box-shadow:0 8px 24px rgba(0,0,0,.15);font:14px/1.4 system-ui,sans-serif',
		esc_html( $msg )
	);
};
add_action( 'admin_notices', $oec_cf_notice );
add_action( 'wp_footer', $oec_cf_notice );
unset( $oec_cf_notice );

/* ── 7. /purge-cache.php?url=… (botón de los cargadores en el campus) ── */

/**
 * Misma URL que el script del sitio viejo, así el botón del campus sigue
 * funcionando sin cambios. No pide usuario (como antes), pero solo acepta
 * URLs de este sitio y tiene límites: la misma URL una vez cada 15 s y
 * 60 pedidos por hora por IP.
 *
 * Nginx/Apache responden 404 a un .php que no existe antes de llegar a
 * WordPress, así que en la raíz del sitio va tools/purge-cache.php (dos
 * líneas que cargan WordPress y llaman a esta función):
 *   cp wp-content/themes/oec-wp-theme/tools/purge-cache.php ./purge-cache.php
 */
function oec_cf_purge_endpoint(): void {
	nocache_headers();
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex' );

	$lineas = [];
	$valida = false;
	$url    = esc_url_raw( wp_unslash( $_GET['url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$parts  = wp_parse_url( $url );
	$host   = get_network()->domain;
	$ip     = preg_replace( '/[^0-9a-f:.]/i', '', (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ) );

	if ( ! $url || empty( $parts['host'] ) || ! in_array( $parts['host'], array_unique( [ $host, 'g-se.com', 'nuevo.g-se.com' ] ), true ) ) {
		$lineas[] = '❌ ' . esc_html__( 'Indicá una URL de este sitio con el parámetro ?url=', 'oec-theme' );
	} else {
		$valida = true;
		$url    = 'https://' . $host . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$ip_key  = 'oec_cf_ip_' . md5( $ip );
		$url_key = 'oec_cf_url_' . md5( $url );
		$hits    = (int) get_site_transient( $ip_key );
		if ( $hits >= 60 ) {
			$lineas[] = '❌ ' . esc_html__( 'Demasiados pedidos. Probá de nuevo en un rato.', 'oec-theme' );
		} elseif ( get_site_transient( $url_key ) ) {
			$lineas[] = '✅ ' . esc_html__( 'Esta página se acaba de borrar hace unos segundos.', 'oec-theme' );
		} else {
			set_site_transient( $ip_key, $hits + 1, HOUR_IN_SECONDS );
			set_site_transient( $url_key, 1, 15 );
			$es_ficha = (bool) preg_match( '/t-[a-zA-Z0-9]{14}/', $url );
			$result   = oec_cf_purge_page( $url );
			if ( $es_ficha ) {
				$lineas[] = '✅ ' . esc_html__( 'Datos de la formación (se vuelven a pedir a la API)', 'oec-theme' );
			}
			$lineas[] = true === $result
				? '✅ Cloudflare'
				: '❌ Cloudflare: ' . esc_html( $result->get_error_message() );
		}
	}

	printf(
		'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title><body style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 18px"><h1 style="font-size:20px">%1$s</h1><p>%2$s</p>%3$s</body>',
		esc_html__( 'Borrar caché', 'oec-theme' ),
		implode( '<br>', $lineas ),
		$valida ? '<p><b>' . esc_html__( 'Revisar cambios:', 'oec-theme' ) . '</b> <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>' : ''
	);
	exit;
}

// Por si el servidor sí deja pasar /purge-cache.php a WordPress.
add_action( 'parse_request', function (): void {
	if ( '/purge-cache.php' === (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
		oec_cf_purge_endpoint();
	}
}, 0 );

/* ── Rutas REST públicas del tema: sin nonce ─────────────────────
   Las páginas guardadas en Cloudflare llevan incrustado el nonce de
   WordPress, que vence a las 12–24 h y además depende del usuario: una
   copia vieja (o un visitante con sesión iniciada viendo la copia
   anónima) manda un nonce inválido y la REST API responde 403
   "rest_cookie_invalid_nonce" — el chat IA mostraba "error de conexión"
   y el widget de créditos fallaba. Todas las rutas oec/v1 son públicas
   (permission_callback __return_true) y no usan al usuario logueado:
   se atienden como visitante anónimo, sin mirar el nonce. Va antes del
   chequeo de cookies del núcleo (prioridad 100). */
add_filter( 'rest_authentication_errors', function ( $result ) {
	if ( ! empty( $result ) ) {
		return $result;
	}
	$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
	if ( str_starts_with( $route, '/oec/v1/' ) ) {
		wp_set_current_user( 0 );
		return true;
	}
	return $result;
}, 90 );
