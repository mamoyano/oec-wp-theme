<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   REDIRECCIONES DE URLS VIEJAS
   Migración de g-se.com (WordPress simple + WPML en SiteGround) a este
   multisitio (2026-09). Solo actúa sobre URLs que dan 404, así que nunca
   pisa una página que exista. Reglas armadas a partir de las URLs con
   tráfico de Search Console (12 meses):

   En /es:
   - /socio/{slug}          → landing de la organización (o el listado)
   - /formaciones-filtradas → /formaciones con los mismos filtros
   - /formacion-incrustada/{slug} → /formacion/{slug}
   - URLs cortadas ("/masa-") → el único post que empieza así
   - slugs de la plataforma vieja: "-t-{código}" → ficha de formación,
     "-sa-{código}" (artículo no migrado) → búsqueda por el título
   - /especiales/{slug}     → landing de la temática o formaciones filtradas
   - /blogs, /blogs/page/N  → artículos filtrados por blogs
   - posts depurados        → 410 Gone (inc/redirects-gone.php)
   En la raíz (sitio principal):
   - /{página}              → /es/{página} (/links, /formaciones, /especiales/…)
   - /{slug-de-post}        → /es/{slug} (WPML servía español sin prefijo)
   - /u/{nombre}-u-{id}     → autor en /es, o búsqueda por su nombre
   (los /wp-content/uploads/… viejos los resuelve el .htaccess del servidor)

   En /en (sin contenido por ahora; como en g-se.com, todo va a /es):
   - /en/{ruta} → /es/{ruta} si existe, o la home de /es. wp-admin y el
     login de /en siguen funcionando. /en queda fuera del sitemap y llms.txt.

   Además se apaga la "adivinanza" de WordPress para los 404
   (redirect_guess_404_permalink): mandaba /es/socio/francis-holway a un
   episodio de podcast y /es/blogs a un post cualquiera — para Google, un
   301 a una página que no tiene nada que ver es peor que un 404.
   ============================================================ */

add_filter( 'do_redirect_guess_404_permalink', '__return_false' );

/** Ruta del sitio de contenido en español (donde están los posts). */
const OEC_REDIRECT_ES_PATH = '/es/';

/** Sitios de la red sin contenido propio que redirigen todo a /es. */
const OEC_REDIRECT_TO_ES = [ '/en/' ];

add_filter( 'oec_seo_sites', function ( array $sites ): array {
	return array_values( array_filter( $sites, function ( array $s ): bool {
		return ! in_array( (string) wp_parse_url( $s['url'], PHP_URL_PATH ), OEC_REDIRECT_TO_ES, true );
	} ) );
} );

// /en → /es (antes que cualquier otra cosa, incluso si la página existe en /en).
add_action( 'template_redirect', function (): void {
	if ( ! is_multisite() || ! in_array( get_site()->path, OEC_REDIRECT_TO_ES, true ) ) {
		return;
	}
	$es = get_sites( [ 'path' => OEC_REDIRECT_ES_PATH, 'number' => 1, 'fields' => 'ids' ] )[0] ?? 0;
	if ( ! $es ) {
		return;
	}
	$path = rawurldecode( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
	$rel  = trim( substr( $path, strlen( get_site()->path ) ), '/' );

	switch_to_blog( $es );
	$target = home_url( '/' );
	if ( '' !== $rel ) {
		$found = get_page_by_path( $rel ) ?: get_page_by_path( $rel, OBJECT, 'post' );
		if ( $found && 'publish' === $found->post_status ) {
			$target = get_permalink( $found );
		}
	}
	restore_current_blog();

	wp_redirect( $target, 301, 'OEC' );
	exit;
}, 0 );

/** Socios viejos (/es/socio/{slug}) → slug de la organización actual. */
function oec_redirect_socios(): array {
	return [
		'2842d4d047d87ff65798bb688d28c96b'  => 'ejercicio-y-corazon',
		'7cd7c35a15c049af6a7a708f229327f9'  => 'x-move-human-performance',
		'8ebf7c54b556b0344a0b442fe0038c50'  => 'trainer',
		'a422ed353bed6f5792ad23b1f7cc6b2c'  => 'rg-nutricion-deportiva',
		'ariel_couceiro_online'             => 'ariel-couceiro',
		'behap'                             => 'behap',
		'biokinetics'                       => 'biokinetics',
		'c4c'                               => 'c4c',
		'ciencias_del_ejercicio'            => 'ciencias-del-ejercicio',
		'cristian_ventura'                  => 'Cristian-Ventura',
		'entrenamiento_ciclismo'            => 'entrenamiento-ciclismo',
		'f1f0948ea34820bfd3606c444f6ae604'  => 'ariel-couceiro',
		'francis-holway'                    => 'francis-holway',
		'instituto_deporte_y_vida'          => 'instituto-deporte-y-vida',
		'international_endurance_group'     => 'iewg',
		'lisandro_digiuni_capacitaciones'   => 'lisandro-digiuni-capacitaciones',
		'neurocontrol_motor'                => 'neurocontrol-motor',
		'nsca_spain'                        => 'nsca-spain',
		'pablo-anon'                        => 'pablo-anon',
		'physicalexercisehealth-consulting' => 'physical-exercise-health',
		'rodrigo_ovide_capacitaciones'      => 'rodrigo-ovide-capacitaciones',
		'rugby_center'                      => 'juan-casajus-rugby',
		'sinermica_capacitaciones'          => 'sinermica-capacitaciones',
		'universidadeuropea'                => 'ue',
	];
}

/**
 * Especiales viejas (/es/especiales/{slug}): las que tienen landing propia
 * van ahí; las de temática, al listado de formaciones filtrado; las
 * promociones viejas (black-days, cyber…), al listado completo.
 * Se compara por palabra clave: cubre también las variantes -testing, -old…
 */
function oec_redirect_especial( string $slug ): string {
	$landings = [
		'nutricion'                 => 'especiales/nutricion-deportiva',
		'entrenamiento-de-la-fuerza' => 'especiales/entrenamiento-de-la-fuerza',
		'entrenamiento-de-fuerza'    => 'especiales/entrenamiento-de-la-fuerza',
	];
	foreach ( $landings as $key => $path ) {
		if ( str_contains( $slug, $key ) ) {
			$page = get_page_by_path( $path );
			if ( $page ) {
				return get_permalink( $page );
			}
		}
	}
	$tematicas = [
		'fisiologia' => 'fisiologia',
		'futbol'     => 'futbol',
		'endurance'  => 'endurance',
		'salud'      => 'salud-ejercicio',
		'deportes'   => 'deportes',
	];
	foreach ( $tematicas as $key => $tematica ) {
		if ( str_contains( $slug, $key ) ) {
			return oec_formaciones_url( [ 'tematica' => [ $tematica ] ] );
		}
	}
	return oec_formaciones_url();
}

/**
 * Listado filtrado del plugin viejo (/es/formaciones-filtradas?type=curso&subject=fuerza…)
 * → /formaciones con los mismos filtros. Los valores que ya no existen (un mes
 * pasado, una temática vieja) se descartan.
 */
function oec_redirect_formaciones_filtradas(): string {
	$grupos  = oec_formaciones_grupos();
	$get     = fn( $k ) => sanitize_text_field( wp_unslash( $_GET[ $k ] ?? '' ) );
	$valida  = fn( $grupo, $v ) => isset( $grupos[ $grupo ]['options'][ $v ] ) ? $v : '';
	$cambios = [];

	$tematicas = array_values( array_filter( explode( ',', $get( 'subject' ) ), fn( $t ) => $valida( 'tematica', $t ) ) );
	if ( $tematicas ) {
		$cambios['tematica'] = array_slice( $tematicas, 0, 2 );
	}
	$map = [
		'tipo'        => ucfirst( strtolower( $get( 'type' ) ) ),
		'modalidad'   => str_starts_with( $get( 'modality' ), 'BLEND' ) ? 'BLEND' : $get( 'modality' ),
		'inscripcion' => $get( 'enrollment' ),
		'sync'        => $get( 'synchronicity' ),
		'mes'         => $get( 'month' ),
	];
	foreach ( $map as $grupo => $v ) {
		if ( '' !== $v && $valida( $grupo, $v ) ) {
			$cambios[ $grupo ] = $v;
		}
	}
	return oec_formaciones_url( $cambios );
}

/** Ficha de formación: /formacion/{slug}. */
function oec_redirect_formacion( string $slug ): string {
	return home_url( user_trailingslashit( '/formacion/' . $slug ) );
}

/**
 * URL cortada al copiarla ("/masa-", "/gasto-energetico-en-reposo)"): si el
 * resto de la URL es el comienzo de UN solo post publicado, va a ese post.
 * Con varios candidatos no se adivina.
 */
function oec_redirect_truncado( string $slug ): ?string {
	global $wpdb;
	$prefix = rtrim( $slug, '-_).,;:' );
	if ( $prefix === $slug || strlen( $prefix ) < 4 ) {
		return null;
	}
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name LIKE %s LIMIT 2",
		$wpdb->esc_like( $prefix ) . '%'
	) );
	return 1 === count( $ids ) ? get_permalink( (int) $ids[0] ) : null;
}

/**
 * Slugs de la plataforma anterior a WordPress:
 * - "…-t-K6019a7d39d3e8": formación → su ficha (g-se.com ya hacía ese 301).
 * - "…-550-sa-l57cfb27158937": artículo que no se migró (ya daba 404 en
 *   g-se.com) → búsqueda con las primeras palabras del título.
 * Llamar con el sitio /es activo.
 */
function oec_redirect_slug_viejo( string $slug ): ?string {
	if ( preg_match( '/-t-[A-Za-z0-9]{10,}$/', $slug ) ) {
		return oec_redirect_formacion( $slug );
	}
	if ( preg_match( '/^(.+?)(?:-\d+)?-sa-[A-Za-z0-9]{10,}$/', $slug, $m ) ) {
		$palabras = array_slice( array_filter( explode( '-', $m[1] ), fn( $w ) => strlen( $w ) > 2 ), 0, 6 );
		return $palabras ? oec_articulos_url( [ 'q' => implode( ' ', $palabras ) ] ) : null;
	}
	return null;
}

/** Destino para una ruta del sitio /es (relativa, sin barras de borde). */
function oec_redirect_es( string $rel ): ?string {
	$parts = explode( '/', $rel );

	if ( 'formaciones-filtradas' === $rel ) {
		return oec_redirect_formaciones_filtradas();
	}
	if ( str_starts_with( $rel, 'formaciones-filtradas' ) && 1 === count( $parts ) ) {
		return oec_redirect_formacion( substr( $rel, strlen( 'formaciones-filtradas' ) ) );
	}
	if ( 'formacion-incrustada' === $parts[0] && isset( $parts[1] ) ) {
		return oec_redirect_formacion( $parts[1] );
	}
	if ( 'organizaciones-educativas' === $rel ) {
		return oec_organizaciones_url();
	}
	if ( 1 === count( $parts ) && ( $post = oec_redirect_truncado( $rel ) ?: oec_redirect_slug_viejo( $rel ) ) ) {
		return $post;
	}
	if ( 'socio' === $parts[0] && isset( $parts[1] ) ) {
		$org = oec_redirect_socios()[ $parts[1] ] ?? '';
		return $org ? oec_organizacion_url( $org ) : oec_organizaciones_url();
	}
	if ( 'especiales' === $parts[0] && isset( $parts[1] ) ) {
		return oec_redirect_especial( $parts[1] );
	}
	if ( 'blogs' === $parts[0] ) {
		return oec_articulos_url( [ 'tipo' => 'blogs' ] );
	}
	return null;
}

/** ¿El slug es de un post depurado a propósito? */
function oec_redirect_is_gone( string $slug ): bool {
	static $gone = null;
	$gone ??= array_flip( (array) require __DIR__ . '/redirects-gone.php' );
	return isset( $gone[ $slug ] );
}

/** Destino para una ruta de la raíz de la red (relativa, sin barras de borde). */
function oec_redirect_root( string $rel ): ?string {
	$es = get_sites( [ 'path' => OEC_REDIRECT_ES_PATH, 'number' => 1, 'fields' => 'ids' ] )[0] ?? 0;
	if ( ! $es ) {
		return null;
	}
	$target = null;
	switch_to_blog( $es );

	// Perfiles de la plataforma vieja: /u/martin-f-bottaro-u-p57cfb20bcd15c
	if ( preg_match( '#^u/(.+?)-u-[A-Za-z0-9]+$#', $rel, $m ) ) {
		$name   = sanitize_title( $m[1] );
		$user   = get_user_by( 'slug', $name );
		$target = ( $user && count_user_posts( $user->ID, 'post', true ) )
			? get_author_posts_url( $user->ID )
			: oec_articulos_url( [ 'q' => str_replace( '-', ' ', $name ) ] );
	} elseif ( '' !== $rel && ( $page = get_page_by_path( $rel ) ) && 'publish' === $page->post_status ) {
		// Página de /es pedida sin el prefijo de idioma: g-se.com/links,
		// /formaciones, /especiales/futbol… (con sus parámetros, si los trae).
		$target = get_permalink( $page );
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$target .= '?' . wp_unslash( $_SERVER['QUERY_STRING'] );
		}
	} elseif ( ! str_contains( $rel, '/' ) && '' !== $rel ) {
		// Post en español servido sin prefijo de idioma (o con la URL cortada).
		$post   = get_page_by_path( $rel, OBJECT, 'post' );
		$target = ( $post && 'publish' === $post->post_status )
			? get_permalink( $post )
			: ( oec_redirect_truncado( $rel ) ?: oec_redirect_slug_viejo( $rel ) );
	}
	// Las imágenes y PDFs viejos (/wp-content/uploads/…) no llegan a PHP:
	// los resuelve el .htaccess (ver el bloque "OEC uploads" en el servidor).

	restore_current_blog();
	return $target;
}

add_action( 'template_redirect', function (): void {
	if ( ! is_404() || ! is_multisite() ) {
		return;
	}
	$site_path = get_site()->path; // "/" o "/es/"
	$path      = rawurldecode( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
	if ( ! str_starts_with( $path, $site_path ) ) {
		return;
	}
	$rel = trim( substr( $path, strlen( $site_path ) ), '/' );
	if ( '' === $rel ) {
		return;
	}

	$target = null;
	if ( OEC_REDIRECT_ES_PATH === $site_path ) {
		$target = oec_redirect_es( $rel );
		$slug   = basename( $rel );
	} elseif ( is_main_site() ) {
		$target = oec_redirect_root( $rel );
		$slug   = str_contains( $rel, '/' ) ? '' : $rel;
	} else {
		return;
	}

	if ( $target ) {
		wp_redirect( $target, 301, 'OEC' );
		exit;
	}
	if ( $slug && oec_redirect_is_gone( $slug ) ) {
		// 410: "eliminado a propósito". Se muestra la misma página de 404.
		status_header( 410 );
		nocache_headers();
	}
}, 1 );
