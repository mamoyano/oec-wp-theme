<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   [oec-url]/formaciones?oec_subject=x[/oec-url] — URL "de este sitio"
   para links escritos a mano en el HTML de una página
   (<a href="[oec-url]/formaciones[/oec-url]">), donde no hay atributo de
   shortcode que se resuelva solo. Antes vivía en el plugin OEC; se movió
   acá porque solo lo usan las landings y el home del tema.

   Una ruta con UNA sola "/" inicial es relativa a la raíz del DOMINIO: en
   un multisite por subdirectorio (oec-test.local/es/) apuntaría afuera del
   subsitio → 404. Se la ancla al sitio actual con home_url() y, si ya trae
   el prefijo del subsitio ("/es/formaciones"), no se lo duplica. Lo demás
   (URL absoluta, "//cdn…", "#ancla", ruta sin "/") pasa sin tocar.
   Se usa el formato con contenido (no path="…") para no anidar comillas
   dentro del atributo href.
   ============================================================ */

function oec_resolve_site_url( string $url ): string {
	$url = trim( $url );
	if ( '' === $url || '/' !== $url[0] || ( isset( $url[1] ) && '/' === $url[1] ) ) {
		return $url;
	}
	$home   = wp_parse_url( home_url() );
	$prefix = rtrim( $home['path'] ?? '', '/' ); // "/es" o ""
	if ( '' !== $prefix && preg_match( '#^' . preg_quote( $prefix, '#' ) . '(?:[/?\#]|$)#', $url ) ) {
		return $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
	}
	return home_url( $url );
}

add_shortcode( 'oec-url', function ( $atts, $content = '' ) {
	return esc_url( oec_resolve_site_url( wp_specialchars_decode( wp_strip_all_tags( (string) $content ) ) ) );
} );
