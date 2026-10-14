<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   VIMEO — clases de nuestra cuenta para [oec-clases]

   Con el token de Vimeo (Ajustes → Integraciones), el tema trae de la
   cuenta los videos de la categoría configurada ("Educativo") con su
   título, descripción, duración, miniatura y tags. [oec-clases] los usa
   en lugar de la lista cargada a mano: en el home, todos; en una landing,
   los que tienen el tag de su temática (nutricion-deportiva, fuerza…).

   - El link al curso sale de la descripción del video: la primera URL que
     apunte a una ficha (/formacion/…), o si no la primera URL. En el texto
     que se muestra, las URLs se sacan.
   - Al azar: cada vez que se conecta a Vimeo, el orden de todos los videos
     se sortea y se guarda. Cada página muestra los primeros de su temática
     en ese orden ([oec-clases] max="7"): mismas clases y mismo orden para
     todos hasta la próxima conexión (y la página queda cacheada igual).
   - La biblioteca se guarda (opción oec_vimeo_lib) y se renueva cada
     OEC_VIMEO_TTL en segundo plano (WP-Cron): las visitas nunca esperan a
     Vimeo, salvo la primera de todas. Ajustes → Integraciones tiene
     "Actualizar ahora".
   - Sin token, o si Vimeo no respondió nunca, [oec-clases] sigue con la
     lista a mano de la página.
   ============================================================ */

const OEC_VIMEO_TTL    = 6 * HOUR_IN_SECONDS;
const OEC_VIMEO_OPTION = 'oec_vimeo_lib';
const OEC_VIMEO_CRON   = 'oec_vimeo_refresh';

/**
 * Token y categoría: los del sitio actual (Tema OEC → Integraciones existe en
 * cada sitio de la red) y, si ahí no se cargó token, los del sitio de
 * configuración (/es/). Antes se leía solo /es/: un token cargado en otro
 * sitio quedaba "Activo" en su tarjeta pero no se usaba.
 */
function oec_vimeo_settings(): array {
	$o      = oec_get_options();
	$origen = 'este sitio';
	if ( '' === oec_vimeo_limpiar_token( (string) ( $o['vimeo_token'] ?? '' ) ) && function_exists( 'oec_config_theme_options' ) && ! oec_is_config_site() ) {
		$o      = oec_config_theme_options();
		$origen = 'el sitio /es/';
	}
	return [
		'token'     => oec_vimeo_limpiar_token( (string) ( $o['vimeo_token'] ?? '' ) ),
		'categoria' => trim( (string) ( $o['vimeo_category'] ?? '' ) ) ?: 'Educativo',
		'origen'    => $origen,
	];
}

/** Sin espacios ni un "Bearer " pegado por error delante. */
function oec_vimeo_limpiar_token( string $t ): string {
	return (string) preg_replace( '/\s+/', '', (string) preg_replace( '/^\s*bearer\s+/i', '', $t ) );
}

/** "Educativo" / "educativo" / "Educativó" → "educativo" (para comparar). */
function oec_vimeo_norm( string $s ): string {
	return sanitize_title( remove_accents( $s ) );
}

/**
 * Trae TODOS los videos de la cuenta (paginado) y guarda los de la
 * categoría. Devuelve la biblioteca guardada o WP_Error.
 *
 * @return array|WP_Error
 */
function oec_vimeo_fetch() {
	$cfg = oec_vimeo_settings();
	if ( '' === $cfg['token'] ) {
		return new WP_Error( 'oec_vimeo_no_token', __( 'Falta el token de Vimeo.', 'oec-theme' ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // cuentas grandes: varias páginas de 100 videos
	}
	$fields = 'uri,name,description,link,duration,release_time,created_time,player_embed_url,pictures.base_link,tags.name,tags.canonical,categories.name,categories.uri';
	$url    = 'https://api.vimeo.com/me/videos?per_page=100&sort=date&direction=desc&fields=' . rawurlencode( $fields );
	$cat    = oec_vimeo_norm( $cfg['categoria'] );
	$videos = [];
	$cats   = []; // categorías vistas en la cuenta (diagnóstico en el admin)
	$total  = 0;

	for ( $page = 0; $url && $page < 60; $page++ ) {
		$res = wp_remote_get( $url, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'bearer ' . $cfg['token'],
				'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
			],
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = is_array( $body ) ? ( $body['developer_message'] ?? $body['error'] ?? '' ) : '';
			if ( 401 === $code ) {
				/* translators: 1: últimos 4 caracteres, 2: de qué sitio sale el token */
				$msg = sprintf( __( 'Vimeo rechazó el token que termina en …%1$s (cargado en %2$s). Compará esos 4 caracteres con el token de developer.vimeo.com: si no coinciden, el guardado se quedó con otro valor.', 'oec-theme' ), substr( $cfg['token'], -4 ), $cfg['origen'] ) . ' (' . $msg . ')';
			}
			return new WP_Error( 'oec_vimeo_http', sprintf( 'Vimeo (%d): %s', $code, $msg ?: __( 'respuesta inválida', 'oec-theme' ) ) );
		}

		foreach ( (array) ( $body['data'] ?? [] ) as $v ) {
			++$total;
			$v_cats = array_map( fn( $c ) => (string) ( $c['name'] ?? '' ), (array) ( $v['categories'] ?? [] ) );
			foreach ( $v_cats as $c ) {
				$cats[ $c ] = ( $cats[ $c ] ?? 0 ) + 1;
			}
			$es_cat = (bool) array_filter( (array) ( $v['categories'] ?? [] ), fn( $c ) => in_array( $cat, [
				oec_vimeo_norm( (string) ( $c['name'] ?? '' ) ),
				oec_vimeo_norm( basename( (string) ( $c['uri'] ?? '' ) ) ),
			], true ) );
			if ( ! $es_cat ) {
				continue;
			}
			$video = oec_vimeo_video( $v );
			if ( $video ) {
				$videos[] = $video;
			}
		}
		$next = $body['paging']['next'] ?? '';
		$url  = $next ? 'https://api.vimeo.com' . $next : '';
	}

	arsort( $cats );
	shuffle( $videos ); // el sorteo: dura hasta la próxima conexión
	$lib = [ 'time' => time(), 'videos' => $videos, 'total' => $total, 'cats' => $cats, 'error' => '' ];
	update_option( OEC_VIMEO_OPTION, $lib, false );
	return $lib;
}

/** Un video de la API → lo que usa [oec-clases]. null si no se puede embeber. */
function oec_vimeo_video( array $v ): ?array {
	$id = (int) basename( (string) ( $v['uri'] ?? '' ) );
	if ( ! $id ) {
		return null;
	}
	$desc = (string) ( $v['description'] ?? '' );

	// Link al curso: la primera URL a una ficha; si no hay, la primera URL.
	preg_match_all( '#https?://[^\s<>"\')]+#u', $desc, $m );
	$urls = array_map( fn( $u ) => rtrim( $u, '.,;:!?' ), $m[0] );
	$curso = '';
	foreach ( $urls as $u ) {
		if ( false !== strpos( $u, '/formacion/' ) ) {
			$curso = $u;
			break;
		}
	}
	$curso = $curso ?: ( $urls[0] ?? '' );

	// Texto: sin las URLs, ni la etiqueta corta que las presenta
	// ("Inscribite:", "Más info:", "Link al curso:" — hasta 4 palabras).
	$texto = preg_replace( '#(?:[^\s:.!?]+(?:[ \t]+[^\s:.!?]+){0,3}[ \t]*:[ \t]*)?https?://[^\s<>"\')]+#u', '', $desc );
	$texto = preg_replace( '/\s+/u', ' ', (string) $texto );
	$texto = trim( (string) preg_replace( '/[\s:–—-]+$/u', '', trim( (string) $texto ) ) );

	// Miniatura de 1280 px de ancho: base_link + "-d_1280" (la lista la
	// pide a 480 cambiando ese número, como con las del oEmbed).
	$base = (string) ( $v['pictures']['base_link'] ?? '' );

	// Embed: los videos "ocultos" necesitan el h= que trae player_embed_url.
	parse_str( (string) wp_parse_url( (string) ( $v['player_embed_url'] ?? '' ), PHP_URL_QUERY ), $q );

	return [
		'id'          => $id,
		'h'           => (string) ( $q['h'] ?? '' ),
		'title'       => trim( (string) ( $v['name'] ?? '' ) ),
		'description' => $texto,
		'more'        => esc_url_raw( $curso ),
		'url'         => (string) ( $v['link'] ?? 'https://vimeo.com/' . $id ),
		'thumb'       => $base ? $base . '-d_1280' : '',
		'duration'    => (int) ( $v['duration'] ?? 0 ),
		// Fecha y hora con zona (ISO 8601): va tal cual al uploadDate del JSON-LD.
		'date'        => (string) ( $v['release_time'] ?? $v['created_time'] ?? '' ),
		// Nombre del tag tal cual ("nutricion-deportiva"); el "canonical" de
		// Vimeo puede venir sin guiones, por eso se compara sin ellos.
		'tags'        => array_values( array_unique( array_filter( array_map(
			fn( $t ) => oec_vimeo_norm( (string) ( $t['name'] ?? $t['canonical'] ?? '' ) ),
			(array) ( $v['tags'] ?? [] )
		) ) ) ),
	];
}

/**
 * Biblioteca guardada; si venció, pide renovarla en segundo plano. La
 * primera vez (nunca se trajo) la trae en el momento.
 */
function oec_vimeo_library(): array {
	if ( '' === oec_vimeo_settings()['token'] ) {
		return [];
	}
	$lib = get_option( OEC_VIMEO_OPTION );
	if ( ! is_array( $lib ) ) {
		if ( get_transient( 'oec_vimeo_fail' ) ) {
			return []; // falló hace poco: no reintentar en cada visita
		}
		$lib = oec_vimeo_fetch();
		if ( is_wp_error( $lib ) ) {
			set_transient( 'oec_vimeo_fail', $lib->get_error_message(), 15 * MINUTE_IN_SECONDS );
			return [];
		}
	} elseif ( time() - (int) $lib['time'] > OEC_VIMEO_TTL && ! wp_next_scheduled( OEC_VIMEO_CRON ) ) {
		wp_schedule_single_event( time(), OEC_VIMEO_CRON );
	}
	return (array) ( $lib['videos'] ?? [] );
}

add_action( OEC_VIMEO_CRON, function () {
	$res = oec_vimeo_fetch();
	if ( is_wp_error( $res ) ) {
		// Se queda con lo que había; el error se ve en Ajustes → Integraciones.
		$lib = get_option( OEC_VIMEO_OPTION );
		if ( is_array( $lib ) ) {
			$lib['error'] = $res->get_error_message();
			$lib['time']  = time(); // no reintentar en cada visita
			update_option( OEC_VIMEO_OPTION, $lib, false );
		}
	}
} );

/** Videos para una temática ('' = todos los de la categoría). */
function oec_vimeo_videos( string $tematica = '' ): array {
	$videos = oec_vimeo_library();
	if ( '' === $tematica ) {
		return $videos;
	}
	$sin_guiones = fn( $t ) => str_replace( '-', '', $t );
	$tag         = $sin_guiones( oec_vimeo_norm( $tematica ) );
	return array_values( array_filter( $videos, fn( $v ) => in_array( $tag, array_map( $sin_guiones, $v['tags'] ), true ) ) );
}

/** Temática de la landing que se está viendo ('' en el home y el resto). */
function oec_vimeo_tematica_actual(): string {
	if ( ! function_exists( 'oec_get_especiales_list' ) || ! is_page() ) {
		return '';
	}
	$aqui = untrailingslashit( (string) get_permalink() );
	foreach ( oec_get_especiales_list() as $e ) {
		if ( untrailingslashit( (string) $e['url'] ) === $aqui ) {
			return (string) $e['tematica'];
		}
	}
	return '';
}

/* ── Ajustes → Integraciones: "Actualizar ahora" ── */
add_action( 'admin_post_oec_vimeo_refresh', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_vimeo_refresh' );
	delete_transient( 'oec_vimeo_fail' );
	$res = oec_vimeo_fetch();
	if ( is_wp_error( $res ) ) {
		// Que se vea en la tarjeta (antes el error se perdía).
		set_transient( 'oec_vimeo_fail', $res->get_error_message(), 15 * MINUTE_IN_SECONDS );
	}
	$url = remove_query_arg( 'oec_vimeo', wp_get_referer() ?: admin_url( 'admin.php?page=oec-integraciones' ) );
	wp_safe_redirect( add_query_arg( 'oec_vimeo', is_wp_error( $res ) ? 'error' : 'ok', $url ) );
	exit;
} );

/** Tras guardar Integraciones con "Guardar y actualizar ahora". */
function oec_vimeo_refrescar_tras_guardar( $location ) {
	remove_filter( 'wp_redirect', 'oec_vimeo_refrescar_tras_guardar' );
	delete_transient( 'oec_vimeo_fail' );
	$res = oec_vimeo_fetch();
	if ( is_wp_error( $res ) ) {
		set_transient( 'oec_vimeo_fail', $res->get_error_message(), 15 * MINUTE_IN_SECONDS );
	}
	return add_query_arg( 'oec_vimeo', is_wp_error( $res ) ? 'error' : 'ok', remove_query_arg( 'oec_vimeo', $location ) );
}

/** Resumen para el admin: cuántos videos, por tag, cuándo, error. */
function oec_vimeo_status(): array {
	$lib  = get_option( OEC_VIMEO_OPTION );
	$fail = (string) get_transient( 'oec_vimeo_fail' );
	if ( ! is_array( $lib ) ) {
		return [ 'error' => $fail ];
	}
	$tags = [];
	foreach ( (array) $lib['videos'] as $v ) {
		foreach ( $v['tags'] as $t ) {
			$tags[ $t ] = ( $tags[ $t ] ?? 0 ) + 1;
		}
	}
	arsort( $tags );
	return [
		'time'     => (int) $lib['time'],
		'n'        => count( (array) $lib['videos'] ),
		'total'    => (int) ( $lib['total'] ?? 0 ),
		'tags'     => $tags,
		'cats'     => (array) ( $lib['cats'] ?? [] ),
		'sin_link' => count( array_filter( (array) $lib['videos'], fn( $v ) => '' === $v['more'] ) ),
		'error'    => $fail ?: (string) ( $lib['error'] ?? '' ),
	];
}
