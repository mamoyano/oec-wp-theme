<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   COMUNIDADES — formaciones de otros sitios de OEC

   Las comunidades (swimming.science, fisio.one, traumato.site…) son
   sitios propios, con su dominio, que salieron de g-se.com. Sus
   formaciones llegan al catálogo por los tokens secundarios (ver
   OEC_AI_Catalog::sources()) y cada fila trae "community" (p. ej.
   "https://swimming.science").

   Una formación es EXTERNA cuando su comunidad no es la de este sitio:
   se muestra con el logo redondo de su comunidad y el ícono de enlace
   externo, y lleva a community + /es/formacion/ + slug en otra pestaña.
   La comunidad de este sitio es la que más aparece en el token
   principal (meta "site_community" del sync); si todavía no hay sync, el
   dominio del sitio.
   ============================================================ */

/** Logos de las comunidades (en la biblioteca de g-se.com/es): nombre del archivo sin .webp.
 *  Se usa la miniatura de 150×150 que genera WordPress (el original es de 1080). */
const OEC_COMMUNITY_LOGOS = [ 'g-se-com', 'voley-org', 'is-fitness', 'swimming-science', 'fisio-one', 'traumato-site' ];
const OEC_COMMUNITY_LOGO_BASE = 'https://g-se.com/es/wp-content/uploads/sites/2/2026/10/';

/** ¿Es del token principal? Las entradas sin "primary" (anteriores a los secundarios) cuentan como principales. */
function oec_formation_is_primary( array $r ): bool {
	return ! array_key_exists( 'primary', $r ) || ! empty( $r['primary'] );
}

/** "https://www.voley.org/" → "https://www.voley.org" ('' si no es una URL). */
function oec_community_origin( string $url ): string {
	$host = strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_HOST ) );
	return $host ? 'https://' . $host : '';
}

/** "https://www.voley.org" → "voley.org". */
function oec_community_host( string $url ): string {
	$host = strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_HOST ) );
	return (string) preg_replace( '/^www\./', '', $host );
}

/** Dominio de la comunidad de este sitio ("g-se.com"). */
function oec_site_community_host(): string {
	static $host = null;
	if ( null === $host ) {
		$meta = class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_meta() : [];
		$host = (string) ( $meta['site_community'] ?? '' ) ?: oec_community_host( home_url() );
	}
	return $host;
}

/** ¿La formación es de otra comunidad? $r: fila, ficha o entrada de índice con "community". */
function oec_formation_is_external( array $r ): bool {
	$host = oec_community_host( (string) ( $r['community'] ?? '' ) );
	return '' !== $host && $host !== oec_site_community_host();
}

/** Slug de la formación (las fichas viejas no lo guardan: sale de su URL). */
function oec_formation_slug( array $r ): string {
	return (string) ( ( $r['slug'] ?? '' ) ?: basename( (string) wp_parse_url( (string) ( $r['url'] ?? '' ), PHP_URL_PATH ) ) );
}

/** Link a la formación: la ficha de este sitio o, si es externa, la de su comunidad. */
function oec_formation_url( array $r ): string {
	$slug = oec_formation_slug( $r );
	if ( oec_formation_is_external( $r ) ) {
		return oec_community_origin( (string) $r['community'] ) . '/es/formacion/' . $slug;
	}
	return home_url( user_trailingslashit( '/formacion/' . $slug ) );
}

/**
 * Canonical de una formación de ESTE sitio, con la estructura de URLs nueva.
 * La API manda el canonical que tenía la comunidad en su sitio viejo
 * (voley.org: "https://www.voley.org/capacitacion/{slug}"), que tras la
 * migración redirige: la ficha le diría a Google que la página verdadera es
 * otra. Si el host es el de este sitio (con o sin www), se rearma como
 * {sitio}/es/formacion/{slug}. Las de otras comunidades no se tocan.
 * Lo usan la ficha del plugin (filtro oec_training_canonical) y el sitemap.
 */
function oec_local_canonical( string $url ): string {
	$host = oec_community_host( $url );
	if ( '' === $host || oec_community_host( home_url() ) !== $host ) {
		return $url;
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( preg_match( '#^/(?:[a-z]{2}/)?(?:capacitacion|formacion)/([^/]+)/?$#', $path, $m ) ) {
		return oec_config_url( user_trailingslashit( '/formacion/' . $m[1] ) );
	}
	return $url;
}
add_filter( 'oec_training_canonical', 'oec_local_canonical' );

/** target/rel para el <a> de una formación externa ('' si es de este sitio). */
function oec_formation_link_attrs( array $r ): string {
	return oec_formation_is_external( $r ) ? ' target="_blank" rel="noopener"' : '';
}

/** URL del logo de una comunidad ('' si no hay). Acepta "https://www.voley.org" o "voley.org". */
function oec_community_logo( string $community ): string {
	$host = oec_community_host( false === strpos( $community, '//' ) ? 'https://' . $community : $community );
	if ( '' === $host ) {
		return '';
	}
	$norm = fn( string $s ) => preg_replace( '/[^a-z0-9]/', '', $s );
	foreach ( OEC_COMMUNITY_LOGOS as $file ) {
		// "swimming.science" ↔ swimming-science · "isfitness.com" ↔ is-fitness
		if ( $norm( $file ) === $norm( $host ) || $norm( $file ) === $norm( strtok( $host, '.' ) ) ) {
			return OEC_COMMUNITY_LOGO_BASE . $file . '-150x150.webp';
		}
	}
	return '';
}

/**
 * Marca de formación externa: logo redondo de la comunidad + ícono de
 * enlace externo. '' si la formación es de este sitio.
 */
function oec_community_badge( array $r, string $class = '' ): string {
	if ( ! oec_formation_is_external( $r ) ) {
		return '';
	}
	$host = oec_community_host( (string) $r['community'] );
	$logo = oec_community_logo( $host );
	/* translators: %s: dominio de la comunidad, p. ej. swimming.science */
	$label = sprintf( __( 'Formación de %s (se abre en otra pestaña)', 'oec-theme' ), $host );
	return '<span class="oec-community' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" title="' . esc_attr( $label ) . '">'
		. ( $logo ? '<img class="oec-community__logo" src="' . esc_url( $logo ) . '" alt="" width="20" height="20" loading="lazy" decoding="async">' : '' )
		. '<span class="oec-community__name">' . esc_html( $host ) . '</span>'
		. '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>'
		. '<span class="screen-reader-text">' . esc_html__( '(se abre en otra pestaña)', 'oec-theme' ) . '</span>'
		. '</span>';
}

/**
 * Textos de la comunidad de este sitio para "Quiénes somos" (y lo que se
 * quiera reutilizar): según el dominio de la red, sin "www." ni "nuevo.".
 * Las que todavía no tienen textos propios usan los de G-SE.
 */
function oec_qs_comunidad(): array {
	$host = preg_replace( '/^(www|nuevo)\./', '', (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
	$todas = [
		'g-se.com'         => [
			'nombre'    => 'G-SE',
			'area'      => __( 'las ciencias del ejercicio', 'oec-theme' ),
			'titulo'    => __( 'La comunidad de las ciencias del ejercicio en español', 'oec-theme' ),
			'bajada'    => __( 'G-SE es una comunidad de profesionales de las ciencias del ejercicio físico con publicaciones recientes de journals, blogs, redes sociales y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
			'encontrar' => __( 'Todo lo que un profesional del ejercicio necesita, en un solo lugar', 'oec-theme' ),
			'historia'  => __( 'Desde 1997 conectando profesionales del ejercicio con conocimiento de calidad', 'oec-theme' ),
			'comite'    => __( 'Entrenadores, investigadores, nutricionistas, preparadores físicos y docentes de todo el mundo.', 'oec-theme' ),
		],
		'voley.org'        => [
			'nombre'    => 'Voley.org',
			'area'      => __( 'el vóley', 'oec-theme' ),
			'titulo'    => __( 'La comunidad del vóley en español', 'oec-theme' ),
			'bajada'    => __( 'Voley.org es una comunidad de entrenadores, preparadores físicos y profesionales del vóley con la investigación más reciente, blogs y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
			'encontrar' => __( 'Todo lo que un profesional del vóley necesita, en un solo lugar', 'oec-theme' ),
			'historia'  => __( 'Desde 1997 conectando profesionales del deporte con conocimiento de calidad', 'oec-theme' ),
			'comite'    => __( 'Entrenadores, preparadores físicos, investigadores, kinesiólogos y docentes del vóley de todo el mundo.', 'oec-theme' ),
		],
		'traumato.site'    => [
			'nombre'    => 'Traumato Site',
			'area'      => __( 'la traumatología y la ortopedia', 'oec-theme' ),
			'titulo'    => __( 'La comunidad de la traumatología y la ortopedia en español', 'oec-theme' ),
			'bajada'    => __( 'Traumato Site es una comunidad de traumatólogos, ortopedistas, kinesiólogos y fisioterapeutas con la investigación más reciente resumida cada día y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
			'encontrar' => __( 'Todo lo que un profesional de la traumatología necesita, en un solo lugar', 'oec-theme' ),
			'historia'  => __( 'Desde 1997 conectando profesionales de la salud con conocimiento de calidad', 'oec-theme' ),
			'comite'    => __( 'Traumatólogos, ortopedistas, kinesiólogos, investigadores y docentes de todo el mundo.', 'oec-theme' ),
		],
		'fisio.one'        => [
			'nombre'    => 'Fisio One',
			'area'      => __( 'la fisioterapia', 'oec-theme' ),
			'titulo'    => __( 'La comunidad de la fisioterapia en español', 'oec-theme' ),
			'bajada'    => __( 'Fisio One es una comunidad de fisioterapeutas y kinesiólogos con la investigación más reciente, blogs y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
			'encontrar' => __( 'Todo lo que un profesional de la fisioterapia necesita, en un solo lugar', 'oec-theme' ),
			'historia'  => __( 'Desde 1997 conectando profesionales de la salud con conocimiento de calidad', 'oec-theme' ),
			'comite'    => __( 'Fisioterapeutas, kinesiólogos, investigadores y docentes de todo el mundo.', 'oec-theme' ),
		],
		'swimming.science' => [
			'nombre'    => 'Swimming Science',
			'area'      => __( 'la natación', 'oec-theme' ),
			'titulo'    => __( 'La comunidad de la natación en español', 'oec-theme' ),
			'bajada'    => __( 'Swimming Science es una comunidad de entrenadores, preparadores físicos y profesionales de la natación con la investigación más reciente, blogs y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
			'encontrar' => __( 'Todo lo que un profesional de la natación necesita, en un solo lugar', 'oec-theme' ),
			'historia'  => __( 'Desde 1997 conectando profesionales del deporte con conocimiento de calidad', 'oec-theme' ),
			'comite'    => __( 'Entrenadores, preparadores físicos, investigadores y docentes de la natación de todo el mundo.', 'oec-theme' ),
		],
	];
	$c         = $todas[ $host ] ?? $todas['g-se.com'];
	$c['host'] = isset( $todas[ $host ] ) ? $host : 'g-se.com';
	return $c;
}
