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
     OEC_VIMEO_TTL en segundo plano (WP-Cron, por pasos): las visitas nunca
     esperan a Vimeo. Ajustes → Integraciones tiene "Guardar y actualizar
     ahora", con el avance a la vista.
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
 * Nombres con los que puede venir la categoría. La API de Vimeo da los
 * nombres en inglés aunque la web se vea en castellano: "Educativo" llega
 * como "Educational". Se aceptan el nombre cargado y su equivalente.
 */
function oec_vimeo_cat_alias( string $categoria ): array {
	$es_en = [
		'educativo'     => [ 'educational', 'education' ],
		'educacion'     => [ 'educational', 'education' ],
		'deportes'      => [ 'sports' ],
		'documental'    => [ 'documentary' ],
		'instructivos'  => [ 'instructionals' ],
		'charlas'       => [ 'talks' ],
		'periodismo'    => [ 'journalism' ],
		'comida'        => [ 'food' ],
		'musica'        => [ 'music' ],
		'animacion'     => [ 'animation' ],
		'personal'      => [ 'personal' ],
	];
	$c = oec_vimeo_norm( $categoria );
	$t = $es_en[ $c ] ?? [];
	foreach ( $es_en as $es => $en ) {
		if ( in_array( $c, $en, true ) ) {
			$t[] = $es; // también al revés: "Educational" → "educativo"
		}
	}
	return array_values( array_unique( array_merge( [ $c ], $t ) ) );
}

/*
 * La cuenta tiene miles de videos y Vimeo da 100 por pedido: recorrerla
 * entera en una sola carga pasaba el límite del servidor (error 500 por
 * timeout). Ahora se recorre por pasos de OEC_VIMEO_STEP segundos: el
 * avance queda en la opción oec_vimeo_job y cada paso sigue desde ahí. Los
 * pasos los da el admin (Integraciones, por AJAX, con barra de avance) o
 * WP-Cron (uno tras otro) cuando se renueva solo.
 */
const OEC_VIMEO_JOB  = 'oec_vimeo_job';
const OEC_VIMEO_STEP = 20; // segundos por paso: muy por debajo de cualquier timeout

/** Empieza una recorrida nueva (descarta la que hubiera a medias). */
function oec_vimeo_job_start(): void {
	$fields = 'uri,name,description,link,duration,release_time,created_time,player_embed_url,pictures.base_link,tags.name,tags.canonical,categories.name,categories.uri';
	update_option( OEC_VIMEO_JOB, [
		'url'     => 'https://api.vimeo.com/me/videos?per_page=100&sort=date&direction=desc&fields=' . rawurlencode( $fields ),
		'videos'  => [],
		'cats'    => [],
		'total'   => 0,
		'cuenta'  => 0, // total de la cuenta según Vimeo (para el avance)
		'started' => time(),
		'buscada' => oec_vimeo_cat_alias( oec_vimeo_settings()['categoria'] ),
	], false );
	delete_transient( 'oec_vimeo_fail' );
}

/**
 * Un paso de la recorrida: pide páginas hasta gastar OEC_VIMEO_STEP
 * segundos. Al terminar, sortea y guarda la biblioteca.
 *
 * @return array|WP_Error ['done' => bool, 'total' => revisados, 'cuenta' => de la cuenta, 'n' => de la categoría]
 */
function oec_vimeo_step() {
	$job = get_option( OEC_VIMEO_JOB );
	if ( ! is_array( $job ) ) {
		$lib = get_option( OEC_VIMEO_OPTION );
		return [ 'done' => true, 'total' => (int) ( $lib['total'] ?? 0 ), 'cuenta' => (int) ( $lib['total'] ?? 0 ), 'n' => count( (array) ( $lib['videos'] ?? [] ) ) ];
	}
	// Un paso a la vez (el admin y el cron podrían coincidir).
	if ( get_transient( 'oec_vimeo_lock' ) ) {
		return [ 'done' => false, 'total' => (int) $job['total'], 'cuenta' => (int) $job['cuenta'], 'n' => count( $job['videos'] ) ];
	}
	set_transient( 'oec_vimeo_lock', 1, OEC_VIMEO_STEP + 40 );

	$cfg = oec_vimeo_settings();
	$cat = oec_vimeo_cat_alias( $cfg['categoria'] );
	// Empezada con otra categoría u otra versión de la búsqueda: lo revisado
	// no sirve, de cero (antes una recorrida a medias terminaba con la regla vieja).
	if ( ( $job['buscada'] ?? null ) !== $cat ) {
		oec_vimeo_job_start();
		$job = get_option( OEC_VIMEO_JOB );
	}
	$t0  = microtime( true );
	$err = null;
	if ( '' === $cfg['token'] ) {
		$err = new WP_Error( 'oec_vimeo_no_token', __( 'Falta el token de Vimeo.', 'oec-theme' ) );
	}

	while ( ! $err && $job['url'] && microtime( true ) - $t0 < OEC_VIMEO_STEP ) {
		$body = oec_vimeo_get( $job['url'], $cfg );
		if ( is_wp_error( $body ) ) {
			$err = $body;
			break;
		}
		$job['cuenta'] = (int) ( $body['total'] ?? $job['cuenta'] );
		foreach ( (array) ( $body['data'] ?? [] ) as $v ) {
			++$job['total'];
			foreach ( (array) ( $v['categories'] ?? [] ) as $c ) {
				$n                 = (string) ( $c['name'] ?? '' );
				$job['cats'][ $n ] = ( $job['cats'][ $n ] ?? 0 ) + 1;
			}
			$es_cat = (bool) array_filter( (array) ( $v['categories'] ?? [] ), fn( $c ) => array_intersect( $cat, [
				oec_vimeo_norm( (string) ( $c['name'] ?? '' ) ),
				oec_vimeo_norm( basename( (string) ( $c['uri'] ?? '' ) ) ),
			] ) );
			$video = $es_cat ? oec_vimeo_video( $v ) : null;
			if ( $video ) {
				$job['videos'][] = $video;
			}
		}
		$next       = $body['paging']['next'] ?? '';
		$job['url'] = $next ? 'https://api.vimeo.com' . $next : '';
	}
	delete_transient( 'oec_vimeo_lock' );

	if ( $err ) {
		// Se queda con la biblioteca anterior; el error se ve en Integraciones.
		delete_option( OEC_VIMEO_JOB );
		set_transient( 'oec_vimeo_fail', $err->get_error_message(), 15 * MINUTE_IN_SECONDS );
		$lib = get_option( OEC_VIMEO_OPTION );
		if ( is_array( $lib ) ) {
			$lib['error'] = $err->get_error_message();
			$lib['time']  = time(); // no reintentar en cada visita
			update_option( OEC_VIMEO_OPTION, $lib, false );
		}
		return $err;
	}
	if ( $job['url'] ) {
		update_option( OEC_VIMEO_JOB, $job, false );
		return [ 'done' => false, 'total' => $job['total'], 'cuenta' => $job['cuenta'], 'n' => count( $job['videos'] ) ];
	}

	$cats = $job['cats'];
	arsort( $cats );
	$videos = $job['videos'];
	shuffle( $videos ); // el sorteo: dura hasta la próxima conexión
	update_option( OEC_VIMEO_OPTION, [ 'time' => time(), 'videos' => $videos, 'total' => $job['total'], 'cats' => $cats, 'buscada' => $cat, 'error' => '' ], false );
	delete_option( OEC_VIMEO_JOB );
	return [ 'done' => true, 'total' => $job['total'], 'cuenta' => $job['total'], 'n' => count( $videos ) ];
}

/** Una página de la API. @return array|WP_Error */
function oec_vimeo_get( string $url, array $cfg ) {
	$res = wp_remote_get( $url, [
		'timeout' => 15,
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
	if ( 200 === $code && is_array( $body ) ) {
		return $body;
	}
	$msg = is_array( $body ) ? ( $body['developer_message'] ?? $body['error'] ?? '' ) : '';
	if ( 401 === $code ) {
		/* translators: 1: últimos 4 caracteres, 2: de qué sitio sale el token */
		$msg = sprintf( __( 'Vimeo rechazó el token que termina en …%1$s (cargado en %2$s). Compará esos 4 caracteres con el token de developer.vimeo.com: si no coinciden, el guardado se quedó con otro valor.', 'oec-theme' ), substr( $cfg['token'], -4 ), $cfg['origen'] ) . ' (' . $msg . ')';
	}
	return new WP_Error( 'oec_vimeo_http', sprintf( 'Vimeo (%d): %s', $code, $msg ?: __( 'respuesta inválida', 'oec-theme' ) ) );
}

/** Que WP-Cron siga la recorrida (o la empiece) en segundo plano. */
function oec_vimeo_schedule(): void {
	if ( ! wp_next_scheduled( OEC_VIMEO_CRON ) ) {
		wp_schedule_single_event( time(), OEC_VIMEO_CRON );
	}
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
 * Biblioteca guardada. Si nunca se trajo o venció, la recorrida se hace en
 * segundo plano (WP-Cron): la visita nunca espera a Vimeo. Mientras no
 * haya biblioteca, [oec-clases] usa la lista a mano.
 */
function oec_vimeo_library(): array {
	if ( '' === oec_vimeo_settings()['token'] ) {
		return [];
	}
	$lib = get_option( OEC_VIMEO_OPTION );
	$job = get_option( OEC_VIMEO_JOB );
	if ( is_array( $job ) ) {
		oec_vimeo_schedule(); // hay una a medias: que siga
	} elseif ( ! is_array( $lib ) ? ! get_transient( 'oec_vimeo_fail' ) : time() - (int) $lib['time'] > OEC_VIMEO_TTL ) {
		oec_vimeo_job_start();
		oec_vimeo_schedule();
	}
	return is_array( $lib ) ? (array) ( $lib['videos'] ?? [] ) : [];
}

add_action( OEC_VIMEO_CRON, function () {
	if ( ! is_array( get_option( OEC_VIMEO_JOB ) ) ) {
		return;
	}
	$res = oec_vimeo_step();
	if ( ! is_wp_error( $res ) && ! $res['done'] ) {
		wp_schedule_single_event( time(), OEC_VIMEO_CRON ); // el paso siguiente
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

/* ── Ajustes → Integraciones: "Guardar y actualizar ahora" ──
   Empieza la recorrida y vuelve a la tarjeta, que la avanza por AJAX
   (oec_vimeo_step) mostrando cuántos videos lleva. */
function oec_vimeo_refrescar_tras_guardar( $location ) {
	remove_filter( 'wp_redirect', 'oec_vimeo_refrescar_tras_guardar' );
	oec_vimeo_job_start();
	oec_vimeo_schedule(); // por si se cierra la pestaña: el cron la termina
	return remove_query_arg( 'oec_vimeo', $location );
}

add_action( 'admin_post_oec_vimeo_refresh', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_vimeo_refresh' );
	wp_safe_redirect( oec_vimeo_refrescar_tras_guardar( wp_get_referer() ?: admin_url( 'admin.php?page=oec-integraciones' ) ) );
	exit;
} );

add_action( 'wp_ajax_oec_vimeo_step', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'oec-theme' ) ], 403 );
	}
	check_ajax_referer( 'oec_vimeo_step' );
	$res = oec_vimeo_step();
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( [ 'message' => $res->get_error_message() ] );
	}
	wp_send_json_success( $res );
} );

/** Resumen para el admin: cuántos videos, por tag, cuándo, error. */
function oec_vimeo_status(): array {
	$lib  = get_option( OEC_VIMEO_OPTION );
	$fail = (string) get_transient( 'oec_vimeo_fail' );
	$job  = get_option( OEC_VIMEO_JOB );
	$curso = is_array( $job ) ? [ 'total' => (int) $job['total'], 'cuenta' => (int) $job['cuenta'], 'n' => count( $job['videos'] ) ] : null;
	if ( ! is_array( $lib ) ) {
		return [ 'error' => $fail, 'job' => $curso ];
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
		'buscada'  => (array) ( $lib['buscada'] ?? [] ),
		'sin_link' => count( array_filter( (array) $lib['videos'], fn( $v ) => '' === $v['more'] ) ),
		'error'    => $fail ?: (string) ( $lib['error'] ?? '' ),
		'job'      => $curso, // recorrida en curso (null si no hay)
	];
}
