<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   REDIRECT DE IDIOMA EN LA RAÍZ DE LA RED
   En el sitio principal (g-se.com/), la home redirige a /es/ o /en/
   según el idioma del navegador. Antes era un mu-plugin
   (oec-root-redirect.php); vive en el tema porque el tema también está
   activo en el sitio principal, y así viaja con cada release.
   Si en algún servidor quedó el mu-plugin viejo, borrarlo: no rompe
   (las funciones tienen otro nombre) pero es código duplicado.
   ============================================================ */

/**
 * Detecta el idioma preferido del visitante a partir del header Accept-Language.
 * Cualquier idioma que no sea inglés cae en español (mercado principal histórico).
 */
function oec_root_preferred_lang(): string {
	$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
	if ( ! $header ) {
		return 'es';
	}
	$first   = explode( ',', $header )[0] ?? '';
	$primary = strtolower( substr( trim( $first ), 0, 2 ) );
	return $primary === 'en' ? 'en' : 'es';
}

add_action( 'template_redirect', function (): void {
	// Solo en el sitio principal de la red (el dominio raíz).
	if ( ! is_multisite() || ! is_main_site() ) {
		return;
	}
	// Solo la home exacta, sin query params (evita loops y no toca /wp-admin, /wp-json, etc.).
	if ( ! is_front_page() || ! empty( $_GET ) ) {
		return;
	}
	// No redirigir a usuarios con sesión iniciada (evita interferir con el admin).
	if ( is_user_logged_in() ) {
		return;
	}
	// No redirigir a bots/crawlers: dejamos que indexen la raíz normalmente.
	$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
	if ( preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview/i', $ua ) ) {
		return;
	}

	$lang = oec_root_preferred_lang();
	// Las comunidades chicas no tienen /en/: ahí todos van a /es/.
	if ( 'es' !== $lang && ! get_sites( [ 'path' => "/{$lang}/", 'number' => 1, 'fields' => 'ids' ] ) ) {
		$lang = 'es';
	}
	$target = home_url( "/{$lang}/" );

	nocache_headers(); // Evita que el redirect quede cacheado para todos los visitantes.
	wp_safe_redirect( $target, 302 );
	exit;
}, 5 );
