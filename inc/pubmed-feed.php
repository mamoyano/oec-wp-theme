<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   RSS DE PUBMED "LIMPIO" PARA WP-AUTOMATIC
   GET /wp-json/oec/v1/pubmed-rss?feed={id del RSS}[&limit=50]

   PubMed agrega a cada <link> parámetros que cambian en cada lectura
   (ff = hora del pedido, utm_source según quién lo lee). wp-automatic
   reconoce lo ya importado por ese enlace, así que re-importaba el mismo
   artículo (y volvía a pagar la IA). Este endpoint devuelve el mismo feed
   con los enlaces de PubMed/PMC sin parámetros: estables y limpios (también
   el botón "Publicación Original", que sale de [source_link]).

   - Solo lee feeds de PubMed (el id se valida): no es un proxy abierto.
   - Caché de 30 min; si PubMed falla, sirve la última copia buena.
   El id es la parte de la URL de "Create RSS" en PubMed:
   https://pubmed.ncbi.nlm.nih.gov/rss/search/{id}/?limit=50…
   ============================================================ */

const OEC_PUBMED_FEED_TTL = 30 * MINUTE_IN_SECONDS;

add_action( 'rest_api_init', function (): void {
	register_rest_route( 'oec/v1', '/pubmed-rss', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => [
			'feed'  => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $v ),
			],
			'limit' => [
				'default'           => 50,
				'sanitize_callback' => fn( $v ) => max( 1, min( 100, (int) $v ) ),
			],
		],
		'callback'            => 'oec_pubmed_feed_serve',
	] );
} );

/** Saca los parámetros de las URLs de PubMed y PMC (dejan de variar entre lecturas). */
function oec_pubmed_feed_clean( string $xml ): string {
	return (string) preg_replace(
		'#(https://(?:pubmed\.ncbi\.nlm\.nih\.gov|www\.ncbi\.nlm\.nih\.gov)/[^?"\'<>\s]*)\?[^"\'<>\s]*#',
		'$1',
		$xml
	);
}

function oec_pubmed_feed_serve( WP_REST_Request $req ): void {
	$feed  = $req['feed'];
	$limit = (int) $req['limit'];
	$key   = 'oec_pubmed_' . md5( $feed . '|' . $limit );

	$xml = get_transient( $key );
	if ( ! is_string( $xml ) ) {
		$res = wp_remote_get( "https://pubmed.ncbi.nlm.nih.gov/rss/search/{$feed}/?limit={$limit}", [
			'timeout'    => 25,
			'user-agent' => 'Mozilla/5.0 (compatible; G-SE feed; +' . home_url( '/' ) . ')',
		] );
		$body = wp_remote_retrieve_body( $res );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) && str_contains( $body, '<rss' ) ) {
			$xml = oec_pubmed_feed_clean( $body );
			set_transient( $key, $xml, OEC_PUBMED_FEED_TTL );
			update_option( $key . '_ultimo', $xml, false ); // respaldo si PubMed falla
		} else {
			$xml = get_option( $key . '_ultimo' );
		}
	}

	if ( ! is_string( $xml ) || '' === $xml ) {
		status_header( 502 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'No se pudo leer el feed de PubMed.';
		exit;
	}

	header( 'Content-Type: application/rss+xml; charset=UTF-8' );
	header( 'Cache-Control: no-store' );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML del feed, solo reescrito.
	exit;
}
