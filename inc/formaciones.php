<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   LISTADO DE FORMACIONES — page-formaciones.php

   Reemplaza al [oec-list filters="yes"] del plugin: filtra, ordena,
   busca y pagina en memoria sobre listing.json (abiertas + cerradas,
   armado por el sync nocturno de OEC_AI_Catalog), sin ninguna llamada
   a la API al cargar la página.

   Los parámetros de la URL son los mismos que usaba el plugin
   (oec_subject, oec_type, oec_sync, oec_modality, oec_month,
   oec_enrollment, oec_order), así siguen andando los links que ya hay
   en el sitio, en los mails y en Google. Nuevo: ?q= (búsqueda) y
   ?oec_community= (comunidad, si hay tokens secundarios).
   La página va en la ruta, igual que en /articulos: /formaciones/page/N.
   El ?oec_pg=N viejo del plugin redirige (301) a esa URL.
   ============================================================ */

const OEC_FORMACIONES_POR_PAGINA = 12;

/**
 * Grupos del sidebar, en orden. Es la misma configuración que tenían los
 * atributos filter-* del shortcode en la página (ahora la fuente única es
 * esta). Temática: mismos slugs que OEC_AI_Catalog::TEMATICA_SLUGS.
 */
function oec_formaciones_grupos(): array {
	static $grupos = null;
	if ( null !== $grupos ) {
		return $grupos;
	}
	$grupos = [
		'tematica'    => [
			'param'   => 'oec_subject',
			'label'   => __( 'Temática', 'oec-theme' ),
			'multi'   => 2, // hasta 2 a la vez (la tercera reemplaza a la primera)
			'options' => [
				'nutricion-deportiva' => 'Nutrición Deportiva',
				'fuerza'              => 'Entrenamiento de la Fuerza',
				'fisiologia'          => 'Fisiología del Ejercicio',
				'salud-ejercicio'     => 'Salud y Ejercicio',
				'deportes'            => 'Deportes',
				'futbol'              => 'Fútbol',
				'endurance'           => 'Deportes de Endurance',
			],
		],
		'tipo'        => [
			'param'   => 'oec_type',
			'label'   => __( 'Tipo de formación', 'oec-theme' ),
			'options' => [
				'Curso'    => 'Curso',
				'Taller'   => 'Taller',
				'Webinar'  => 'Webinar',
				'Posgrado' => 'Posgrado',
				'Master'   => 'Master',
				'Simposio' => 'Simposio',
			],
		],
		'inscripcion' => [
			'param'   => 'oec_enrollment',
			'label'   => __( 'Inscripción', 'oec-theme' ),
			'default' => 'opened', // sin el parámetro: solo abiertas
			'options' => [
				'opened' => __( 'Abierta', 'oec-theme' ),
				'closed' => __( 'Cerrada', 'oec-theme' ),
				'all'    => __( 'Todas', 'oec-theme' ),
			],
		],
		'mes'         => [
			'param'   => 'oec_month',
			'label'   => __( 'Fecha de comienzo', 'oec-theme' ),
			'options' => array_map( fn( $m ) => $m['label'], oec_formaciones_meses() ),
		],
		'sync'        => [
			'param'   => 'oec_sync',
			'label'   => __( 'Sincronicidad', 'oec-theme' ),
			'options' => [
				'SYNC'    => '100% sincrónica',
				'MIXED'   => 'Mixta',
				'ASYNC-F' => 'Asincrónica con foros',
				'ASYNC'   => '100% asincrónica',
			],
		],
		'modalidad'   => [
			'param'   => 'oec_modality',
			'label'   => __( 'Modalidad', 'oec-theme' ),
			'options' => [
				'ONLINE' => '100% online',
				'ONSITE' => '100% presencial',
				'BLEND'  => 'Mixta',
			],
		],
	];
	// Comunidad: solo si el listado trae formaciones de más de una (tokens secundarios).
	$comunidades = oec_formaciones_comunidades();
	if ( count( $comunidades ) > 1 ) {
		$grupos = array_slice( $grupos, 0, 1, true ) + [
			'comunidad' => [
				'param'   => 'oec_community',
				'label'   => __( 'Comunidad', 'oec-theme' ),
				'options' => array_combine( $comunidades, $comunidades ),
			],
		] + $grupos;
	}
	return $grupos;
}

/** Comunidad de una fila del listado ("swimming.science"); sin "community", la de este sitio. */
function oec_formaciones_comunidad( array $r ): string {
	return oec_community_host( (string) ( $r['community'] ?? '' ) ) ?: oec_site_community_host();
}

/** Comunidades del listado: la de este sitio primero, después las demás por cantidad. */
function oec_formaciones_comunidades(): array {
	$n = [];
	foreach ( class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_listing( true ) : [] as $r ) {
		$c       = oec_formaciones_comunidad( $r );
		$n[ $c ] = ( $n[ $c ] ?? 0 ) + 1;
	}
	arsort( $n );
	$site = oec_site_community_host();
	return array_values( array_unique( array_merge( isset( $n[ $site ] ) ? [ $site ] : [], array_keys( $n ) ) ) );
}

/** Opciones de orden (oec_order). '' = orden sugerido por la API (o relevancia, si hay búsqueda). */
function oec_formaciones_ordenes(): array {
	return [
		''               => __( 'Orden sugerido', 'oec-theme' ),
		'start_date'     => __( 'Fecha de inicio', 'oec-theme' ),
		'enrollment_end' => __( 'Cierre de inscripción', 'oec-theme' ),
		'published_date' => __( 'Más nuevas', 'oec-theme' ),
	];
}

/**
 * Este mes y los 5 siguientes + "Más adelante" (mismas claves que el
 * plugin: "october-2026", "posterior"). clave => [label, from, to].
 */
function oec_formaciones_meses(): array {
	static $meses = null;
	if ( null !== $meses ) {
		return $meses;
	}
	$nombres = [ 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' ];
	$d       = new DateTimeImmutable( 'first day of this month', wp_timezone() );
	$meses   = [];
	for ( $i = 0; $i < 6; $i++ ) {
		$m = $d->modify( "+$i month" );
		$meses[ strtolower( $m->format( 'F-Y' ) ) ] = [
			'label' => $nombres[ (int) $m->format( 'n' ) - 1 ] . ' ' . $m->format( 'Y' ),
			'from'  => $m->format( 'Y-m-01' ),
			'to'    => $m->format( 'Y-m-t' ),
		];
	}
	$meses['posterior'] = [
		'label' => __( 'Más adelante', 'oec-theme' ),
		'from'  => $d->modify( '+6 month' )->format( 'Y-m-01' ),
		'to'    => '',
	];
	return $meses;
}

/**
 * Filtros activos según la URL, ya validados contra las opciones:
 * ['tematica' => [..], 'tipo' => '', …, 'orden' => '', 'q' => '', 'pg' => 1].
 */
function oec_formaciones_estado(): array {
	static $estado = null;
	if ( null !== $estado ) {
		return $estado;
	}
	$get    = fn( $k ) => trim( sanitize_text_field( wp_unslash( $_GET[ $k ] ?? '' ) ) );
	$estado = [];
	foreach ( oec_formaciones_grupos() as $key => $g ) {
		$raw = $get( $g['param'] );
		if ( ! empty( $g['multi'] ) ) {
			$vals             = array_values( array_intersect( array_unique( explode( ',', $raw ) ), array_keys( $g['options'] ) ) );
			$estado[ $key ]   = array_slice( $vals, -$g['multi'] );
			continue;
		}
		$estado[ $key ] = isset( $g['options'][ $raw ] ) ? $raw : ( $g['default'] ?? '' );
	}
	$orden           = $get( 'oec_order' );
	$estado['orden'] = isset( oec_formaciones_ordenes()[ $orden ] ) ? $orden : '';
	$estado['q']     = mb_substr( $get( 'q' ), 0, 80 );
	// /formaciones/page/N (WordPress la pone en la query var 'paged');
	// ?oec_pg=N es el formato viejo, solo para la redirección de abajo.
	$estado['pg']    = max( 1, absint( get_query_var( 'paged' ) ?: ( $_GET['oec_pg'] ?? 1 ) ) );
	return $estado;
}

/**
 * URL del listado con el estado actual + $cambios (null o '' borra el
 * filtro). Cualquier cambio vuelve a la página 1, salvo que se pase 'pg'.
 */
function oec_formaciones_url( array $cambios = [] ): string {
	$page   = get_page_by_path( 'formaciones' );
	$base   = $page ? get_permalink( $page ) : home_url( '/formaciones/' );
	$estado = array_merge( oec_formaciones_estado(), [ 'pg' => 1 ], $cambios );
	$params = [];
	foreach ( oec_formaciones_grupos() as $key => $g ) {
		$v = $estado[ $key ] ?? '';
		$v = is_array( $v ) ? implode( ',', $v ) : (string) $v;
		if ( '' !== $v && $v !== ( $g['default'] ?? '' ) ) {
			$params[ $g['param'] ] = $v;
		}
	}
	if ( '' !== ( $estado['orden'] ?? '' ) ) {
		$params['oec_order'] = $estado['orden'];
	}
	if ( '' !== ( $estado['q'] ?? '' ) ) {
		$params['q'] = $estado['q'];
	}
	if ( ( $estado['pg'] ?? 1 ) > 1 ) {
		$base = user_trailingslashit( trailingslashit( $base ) . 'page/' . (int) $estado['pg'], 'paged' );
	}
	return $params ? $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) : $base;
}

// Canonical de /formaciones/page/N sin filtros: a sí misma, como /articulos
// (WordPress apunta todas a la página 1). Con filtros sigue yendo al
// listado general.
add_filter( 'get_canonical_url', function ( $url, $post ) {
	if ( 'formaciones' !== $post->post_name ) {
		return $url;
	}
	$estado = oec_formaciones_estado();
	if ( $estado['pg'] > 1 && oec_formaciones_url( [ 'pg' => $estado['pg'] ] ) === oec_formaciones_url( [ 'pg' => $estado['pg'], 'tematica' => [], 'comunidad' => '', 'tipo' => '', 'inscripcion' => '', 'mes' => '', 'sync' => '', 'modalidad' => '', 'orden' => '', 'q' => '' ] ) ) {
		return oec_formaciones_url( [ 'pg' => $estado['pg'] ] );
	}
	return $url;
}, 10, 2 );

// ?oec_pg=N (links viejos del plugin, mails, Google) → /formaciones/page/N
// con los mismos filtros.
add_action( 'template_redirect', function () {
	if ( isset( $_GET['oec_pg'] ) && is_page( 'formaciones' ) ) {
		wp_safe_redirect( oec_formaciones_url( [ 'pg' => oec_formaciones_estado()['pg'] ] ), 301 );
		exit;
	}
} );

/**
 * La búsqueda nativa de WordPress (?s=) no tiene plantilla: el buscador del
 * header es el chat con IA. Los links viejos o de terceros a ?s= van al
 * buscador del listado de formaciones con el mismo término.
 */
add_action( 'template_redirect', function () {
	if ( ! is_search() || is_admin() ) {
		return;
	}
	$q = mb_substr( trim( get_search_query( false ) ), 0, 80 );
	wp_safe_redirect( oec_formaciones_url( [ 'q' => $q ] ), 301 );
	exit;
} );

/** ¿Sigue abierta hoy? (la inscripción cierra al terminar ese día, en la hora del sitio). */
function oec_formacion_abierta( array $r ): bool {
	static $hoy = null;
	$hoy = $hoy ?? current_time( 'Y-m-d' );
	return ! empty( $r['open'] ) && ( '' === ( $r['enrollment_end'] ?? '' ) || $r['enrollment_end'] >= $hoy );
}

/** ¿La fila cumple el filtro $grupo = $valor? ($valor: string, o lista para temática). */
function oec_formaciones_cumple( array $r, string $grupo, $valor ): bool {
	switch ( $grupo ) {
		case 'tematica':
			return (bool) array_intersect( (array) $valor, $r['tematicas'] ?? [] );
		case 'comunidad':
			return oec_formaciones_comunidad( $r ) === $valor;
		case 'tipo':
			return 0 === strcasecmp( $r['type'] ?? '', $valor );
		case 'inscripcion':
			return 'all' === $valor || ( 'opened' === $valor ) === oec_formacion_abierta( $r );
		case 'mes':
			$m = oec_formaciones_meses()[ $valor ] ?? null;
			$s = $r['start'] ?? '';
			return $m && '' !== $s && $s >= $m['from'] && ( '' === $m['to'] || $s <= $m['to'] );
		case 'sync':
			return ( $r['synchronicity'] ?? '' ) === $valor;
		case 'modalidad':
			return 'BLEND' === $valor ? false !== strpos( $r['modality'] ?? '', 'BLEND' ) : ( $r['modality'] ?? '' ) === $valor;
	}
	return true;
}

/**
 * Filtra, cuenta, ordena y pagina. Devuelve:
 *   items  → las filas de la página pedida
 *   total  → cantidad de resultados
 *   pages  → cantidad de páginas
 *   page   → página mostrada (la pedida, acotada a pages)
 *   counts → [grupo => [opción => n]]: cuántos resultados daría cada
 *            opción con el resto de los filtros (y la búsqueda) activos.
 */
function oec_formaciones_query( array $estado ): array {
	// Token principal + secundarios: las de otras comunidades van con su logo (oec_formacion_card()).
	$rows   = class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_listing( true ) : [];
	$grupos = oec_formaciones_grupos();
	$words  = array_filter( explode( ' ', oec_search_normalize( $estado['q'] ) ) );

	// Filtros activos (cada grupo con su valor) y, por fila, qué grupos no cumple.
	$activos = [];
	foreach ( $grupos as $key => $g ) {
		$v = $estado[ $key ];
		if ( ( is_array( $v ) && $v ) || ( ! is_array( $v ) && '' !== $v && 'all' !== $v ) ) {
			$activos[ $key ] = $v;
		}
	}

	$counts  = array_fill_keys( array_keys( $grupos ), [] );
	$results = [];
	foreach ( $rows as $i => $r ) {
		if ( $words ) {
			foreach ( $words as $w ) {
				if ( false === strpos( $r['search'] ?? '', $w ) ) {
					continue 2;
				}
			}
		}
		$falla = [];
		foreach ( $activos as $key => $v ) {
			if ( ! oec_formaciones_cumple( $r, $key, $v ) ) {
				$falla[] = $key;
				if ( count( $falla ) > 1 ) {
					break; // ya no suma en ningún conteo
				}
			}
		}
		if ( ! $falla ) {
			$results[] = $i;
		}
		// Conteo de cada grupo: la fila tiene que cumplir todos los DEMÁS filtros.
		foreach ( $grupos as $key => $g ) {
			if ( $falla && [ $key ] !== $falla ) {
				continue;
			}
			foreach ( $g['options'] as $opt => $label ) {
				if ( oec_formaciones_cumple( $r, $key, ! empty( $g['multi'] ) ? [ $opt ] : $opt ) ) {
					$counts[ $key ][ $opt ] = ( $counts[ $key ][ $opt ] ?? 0 ) + 1;
				}
			}
		}
	}

	// Orden: abiertas siempre primero. Dentro de cada bloque, el criterio
	// elegido — con fechas, las abiertas de la más próxima a la más lejana
	// y las cerradas de la más reciente a la más vieja.
	$score = [];
	if ( $words && '' === $estado['orden'] ) {
		foreach ( $results as $i ) {
			$title       = oec_search_normalize( $rows[ $i ]['title'] ?? '' );
			$score[ $i ] = 0;
			foreach ( $words as $w ) {
				$score[ $i ] += false !== strpos( $title, $w ) ? 3 : 0;
			}
		}
	}
	$campo = [ 'start_date' => 'start', 'enrollment_end' => 'enrollment_end', 'published_date' => 'publish_start' ][ $estado['orden'] ] ?? '';
	usort( $results, function ( $a, $b ) use ( $rows, $campo, $score ) {
		$ra = $rows[ $a ];
		$rb = $rows[ $b ];
		$oa = oec_formacion_abierta( $ra );
		$ob = oec_formacion_abierta( $rb );
		if ( $oa !== $ob ) {
			return $oa ? -1 : 1;
		}
		if ( $score && $score[ $a ] !== $score[ $b ] ) {
			return $score[ $b ] <=> $score[ $a ];
		}
		if ( $campo ) {
			// Sin fecha de publicación (ficha de cerrada aún no bajada): la de inicio.
			$va  = (string) ( $ra[ $campo ] ?: $ra['start'] );
			$vb  = (string) ( $rb[ $campo ] ?: $rb['start'] );
			$asc = $oa && 'publish_start' !== $campo;
			if ( $va !== $vb ) {
				return $asc ? strcmp( $va, $vb ) : strcmp( $vb, $va );
			}
		}
		return $a <=> $b; // orden sugerido por la API
	} );

	$total = count( $results );
	$pages = max( 1, (int) ceil( $total / OEC_FORMACIONES_POR_PAGINA ) );
	$page  = min( $estado['pg'], $pages );
	$slice = array_slice( $results, ( $page - 1 ) * OEC_FORMACIONES_POR_PAGINA, OEC_FORMACIONES_POR_PAGINA );

	return [
		'items'  => array_map( fn( $i ) => $rows[ $i ], $slice ),
		'total'  => $total,
		'pages'  => $pages,
		'page'   => $page,
		'counts' => $counts,
	];
}

/**
 * Título del hero según tipo y temática, como antes:
 * "Formaciones" · "Cursos" · "Formaciones de Fútbol" · "Cursos de Fútbol".
 */
function oec_formaciones_titulo( array $estado ): string {
	$grupos   = oec_formaciones_grupos();
	$tipo     = '' !== $estado['tipo'] ? oec_pluralize_es( $grupos['tipo']['options'][ $estado['tipo'] ] ) : '';
	$tematica = 1 === count( $estado['tematica'] ) ? $grupos['tematica']['options'][ $estado['tematica'][0] ] : '';
	if ( $tipo && $tematica ) {
		/* translators: 1: tipo de formación en plural (p. ej. "Simposios"), 2: nombre de la temática */
		return sprintf( __( '%1$s de %2$s', 'oec-theme' ), $tipo, $tematica );
	}
	if ( $tipo ) {
		return $tipo;
	}
	if ( $tematica ) {
		/* translators: %s: nombre de la temática */
		return sprintf( __( 'Formaciones de %s', 'oec-theme' ), $tematica );
	}
	return get_the_title() ?: __( 'Formaciones', 'oec-theme' );
}

/* ── Página /formaciones: CSS/JS de las cards (los del plugin, como en
   [oec-tira]) y noindex para las búsquedas (el plugin ya lo pone para
   sus parámetros oec_*). ── */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page( 'formaciones' ) ) {
		return;
	}
	$dir = WP_PLUGIN_DIR . '/oec-wordpress-plugin';
	$url = plugins_url( 'oec-wordpress-plugin' );
	if ( ! wp_style_is( 'oec-formaciones', 'enqueued' ) && file_exists( $dir . '/css/oec-formaciones.css' ) ) {
		wp_enqueue_style( 'oec-formaciones', $url . '/css/oec-formaciones.css', [], (string) filemtime( $dir . '/css/oec-formaciones.css' ) );
	}
	// Color de marca de las cards (botón, íconos, destacados): antes lo
	// definía el Twig del [oec-list]; sin esto quedan transparentes.
	wp_add_inline_style( 'oec-formaciones', ':root{--oec-brand:' . sanitize_hex_color( get_option( 'oec_brand_color', '#a435f0' ) ) . ';}' );
}, 20 );

add_filter( 'wp_robots', function ( $robots ) {
	if ( is_page( 'formaciones' ) && '' !== trim( (string) ( $_GET['q'] ?? '' ) ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );
