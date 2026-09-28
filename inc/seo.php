<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SEO DEL HOME — todo sale de Ajustes > Generales, así el tema se
   instala en cualquier plataforma sin tocar código ni contenido:
     - <title>            → "Título del sitio" (sin la descripción corta)
     - meta description   → "Descripción corta"
     - og:image           → imagen destacada de la página de inicio
                            (o el logo del sitio si no tiene)
     - JSON-LD            → WebSite + EducationalOrganization, para que
                            buscadores e IAs identifiquen la marca.
   ============================================================ */

// WordPress arma "Título – Descripción corta" en el home; la descripción
// corta ya va como meta description y es demasiado larga para un title.
add_filter( 'document_title_parts', function ( array $parts ): array {
	if ( is_front_page() ) {
		unset( $parts['tagline'] );
	}
	return $parts;
} );

add_action( 'wp_head', 'oec_front_page_seo_meta', 5 );
function oec_front_page_seo_meta(): void {
	if ( ! is_front_page() ) {
		return;
	}

	$name = get_bloginfo( 'name' );
	$desc = wp_strip_all_tags( get_bloginfo( 'description' ) );
	$url  = home_url( '/' );

	$logo  = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' ) ?: get_site_icon_url();
	$image = get_the_post_thumbnail_url( (int) get_option( 'page_on_front' ), 'full' ) ?: $logo;

	if ( $desc ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
	}
	printf( '<meta property="og:type" content="website">' . "\n" );
	printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( $name ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $name ) );
	if ( $desc ) {
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
	}
	if ( $image ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
	}
	printf( '<meta name="twitter:card" content="%s">' . "\n", $image ? 'summary_large_image' : 'summary' );

	$org = array_filter( [
		'@type'       => 'EducationalOrganization',
		'@id'         => $url . '#organization',
		'name'        => $name,
		'url'         => $url,
		'description' => $desc,
		'logo'        => $logo ?: null,
	] );
	$jsonld = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			$org,
			array_filter( [
				'@type'       => 'WebSite',
				'@id'         => $url . '#website',
				'name'        => $name,
				'url'         => $url,
				'description' => $desc,
				'inLanguage'  => get_bloginfo( 'language' ),
				'publisher'   => [ '@id' => $url . '#organization' ],
			] ),
		],
	];
	echo '<script type="application/ld+json">' . wp_json_encode( $jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}
