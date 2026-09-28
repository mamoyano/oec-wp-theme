<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   INDEXNOW — avisa al instante a Bing, Yandex, Seznam, Naver…
   (api.indexnow.org reparte a todos) cuando una URL se publica o
   cambia, en vez de esperar a que vuelvan a leer el sitemap. Bing es
   además el índice de ChatGPT Search y Copilot. Google no usa IndexNow:
   a Google le alcanza con el sitemap y su lastmod.

   - Posts y páginas: al publicar o actualizar.
   - Formaciones, docentes, organizaciones: las que el sitemap detecta
     como nuevas o cambiadas después del sync diario del catálogo.
   - Clave: se genera sola y se sirve en /{clave}.txt (ver sitemap.php).
   - Solo en producción (WP_ENVIRONMENT_TYPE) y en sitios públicos.
     Las URLs se juntan y salen por cron un minuto después, en un envío.
   ============================================================ */

function oec_indexnow_enabled(): bool {
	return 'production' === wp_get_environment_type()
		&& (int) get_option( 'blog_public' ) === 1
		&& oec_seo_is_indexed_site()
		&& apply_filters( 'oec_indexnow_enabled', true );
}

/** Clave del sitio ('' si IndexNow está apagado y $create es false). */
function oec_indexnow_key( bool $create = true ): string {
	$key = (string) get_option( 'oec_indexnow_key', '' );
	if ( '' === $key && $create && oec_indexnow_enabled() ) {
		$key = md5( wp_generate_password( 32, true, true ) );
		update_option( 'oec_indexnow_key', $key );
	}
	return $key;
}

function oec_indexnow_queue( array $urls ): void {
	if ( ! $urls || ! oec_indexnow_enabled() ) {
		return;
	}
	$queue = array_values( array_unique( array_merge( (array) get_option( 'oec_indexnow_queue', [] ), $urls ) ) );
	update_option( 'oec_indexnow_queue', array_slice( $queue, -10000 ), false );
	if ( ! wp_next_scheduled( 'oec_indexnow_flush' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'oec_indexnow_flush' );
	}
}

add_action( 'oec_indexnow_flush', function () {
	$queue = (array) get_option( 'oec_indexnow_queue', [] );
	delete_option( 'oec_indexnow_queue' );
	$key = oec_indexnow_key();
	if ( ! $queue || ! $key ) {
		return;
	}
	// Solo URLs de este host (la API rechaza el envío entero si hay otras).
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$queue = array_values( array_filter( $queue, fn( $u ) => wp_parse_url( $u, PHP_URL_HOST ) === $host ) );
	if ( ! $queue ) {
		return;
	}
	$res = wp_remote_post( 'https://api.indexnow.org/indexnow', [
		'timeout' => 20,
		'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
		'body'    => wp_json_encode( [
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( "/{$key}.txt" ),
			'urlList'     => $queue,
		] ),
	] );
	// Último envío, para revisar si hace falta (200/202 = aceptado).
	update_option( 'oec_indexnow_last', [
		'time'  => current_time( 'mysql' ),
		'count' => count( $queue ),
		'code'  => is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ),
	], false );
} );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' === $new && in_array( $post->post_type, [ 'post', 'page' ], true ) && ! wp_is_post_revision( $post ) ) {
		oec_indexnow_queue( [ get_permalink( $post ) ] );
	}
}, 20, 3 );

add_action( 'oec_seo_urls_changed', 'oec_indexnow_queue' );
