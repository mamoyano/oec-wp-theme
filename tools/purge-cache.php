<?php
/**
 * Botón "borrar caché" de los cargadores de formaciones (campus virtual):
 * https://g-se.com/purge-cache.php?url=…
 * Va copiado en la RAÍZ del sitio, junto a wp-load.php:
 *   cp wp-content/themes/oec-wp-theme/tools/purge-cache.php ./purge-cache.php
 * Toda la lógica está en el tema: inc/cloudflare.php (oec_cf_purge_endpoint).
 */
define( 'WP_USE_THEMES', false );
// En el multisitio, cargar WordPress como el sitio /es/ (siempre tiene el
// tema OEC; la raíz de la red podría tener otro).
$_SERVER['REQUEST_URI'] = '/es/purge-cache.php?' . ( $_SERVER['QUERY_STRING'] ?? '' );
require __DIR__ . '/wp-load.php';
if ( function_exists( 'oec_cf_purge_endpoint' ) ) {
	oec_cf_purge_endpoint();
}
http_response_code( 503 );
echo 'El tema OEC no está activo.';
