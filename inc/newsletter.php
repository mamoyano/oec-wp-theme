<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   NEWSLETTER — integración con Elastic Email

   Circuito de suscripción (propio, sin el doble opt-in de Elastic Email):
   1. El bloque de créditos del hero ([oec-credits-widget]) o el
      shortcode [oec_newsletter] consultan /newsletter/status. Si el email
      no está en la lista general, se le ofrece suscribirse (+50 créditos).
   2. /newsletter/subscribe manda un email de confirmación (API v4
      transactional) con un link firmado a /newsletter-confirmado/.
   3. Esa página postea el token a /newsletter/confirm: se da de alta el
      contacto como Active en las listas elegidas y se otorgan los 50
      créditos (una sola vez, referencia OEC_NL_REF_SUBSCRIBE).

   Newsletter semanal: WP-Cron revisa cada hora. El día/hora configurado
   arma, para la lista general y para cada temática activa, un email que
   intercala artículos (WP) y formaciones (API OAS) y crea la campaña en
   Elastic Email (template + campaign, igual que el script anterior). El
   último HTML enviado de cada lista se guarda para mostrarlo en
   /?oec_newsletter=ultimo.

   Créditos semanales: el email trae un link a /otorgar-creditos/ con
   {email} (merge de Elastic Email) + fecha de envío firmada. Solo otorga
   si el email está suscripto, una vez por semana ISO y hasta 14 días
   después del envío.

   Admin: Apariencia → Newsletter, solo en el sitio de configuración de la
   red (/es/, OEC_CONFIG_SITE_PATH). Todos los sitios leen de ahí las API
   keys, listas, estado y último envío (oec_config_get_option()).
   ============================================================ */

/* ── Datos fijos de G-SE ────────────────────────────────────── */
// Lista general. TODO: pasar a 'G-SE General' cuando terminen las pruebas.
const OEC_NL_GENERAL_LIST = 'Prueba';
const OEC_NL_FROM         = 'Grupo Sobre Entrenamiento <newsletter@g-se.com>';
const OEC_NL_BRAND        = 'Grupo Sobre Entrenamiento';
// PNG dentro del tema: el logo del sitio es WebP (Outlook no lo muestra) y el
// PNG viejo de uploads/2025 desapareció con la migración del sitio.
const OEC_NL_LOGO         = OEC_THEME_URI . '/assets/img/email-logo.png';
const OEC_NL_LOGO_LEGACY  = 'https://g-se.com/wp-content/uploads/2025/01/g-se-2.png';
const OEC_NL_FACEBOOK     = 'https://www.facebook.com/gsesocial';
const OEC_NL_INSTAGRAM    = 'https://www.instagram.com/gsesocial/';
const OEC_NL_WHATSAPP     = 'https://api.whatsapp.com/send?phone=5493512584960&text=Hola%2C+quiero+informaci%C3%B3n+%28o+tengo+consultas%29+%2Asobre+formaciones%2A+que+aqu%C3%AD+se+ofrecen.%0D%0A_%28Entiendo+que+no+pueden+responderme+otro+tipo+de+consultas%29_';
const OEC_NL_REDIRECTOR   = 'https://links.onlineeducation.center/srdt';
const OEC_NL_ADDRESS      = 'G-SE, Av. Colón 4933, Córdoba, Córdoba, 5000, Argentina';
const OEC_NL_OAS_API      = 'https://oas-api.onlineeducation.center/api-oas/v1/trainings';

const OEC_NL_SUBSCRIBE_CREDITS = 50;
const OEC_NL_WEEKLY_CREDITS    = 20;
const OEC_NL_REF_SUBSCRIBE     = 'newsletter-general-g-se';
const OEC_NL_REF_WEEKLY        = 'newsletter-semanal-'; // + semana ISO, ej. 2026-W39
const OEC_NL_MAX_POSTS         = 10;
const OEC_NL_MAX_TRAININGS     = 7;
const OEC_NL_CLAIM_DAYS        = 14;
const OEC_NL_CONFIRM_HOURS     = 48;

/* ── Internos ───────────────────────────────────────────────── */
const OEC_NL_OPTION  = 'oec_newsletter';
const OEC_NL_STATE   = 'oec_newsletter_state';
const OEC_NL_LATEST  = 'oec_newsletter_latest';
// Nombres de TODAS las listas de la cuenta (solo para el buscador del admin;
// en producción son cientos, por eso no van en OEC_NL_OPTION).
const OEC_NL_REMOTE_LISTS = 'oec_newsletter_remote_lists';
const OEC_NL_UNSUPPRESS_DAYS = 30; // rebotes y bajas: un intento de reactivación cada 30 días
const OEC_NL_UNABUSE_DAYS    = 90; // spam: una reactivación cada 90 días
const OEC_NL_CRON    = 'oec_newsletter_cron';
const OEC_NL_API_V4  = 'https://api.elasticemail.com/v4';
const OEC_NL_API_V2  = 'https://api.elasticemail.com/v2';
const OEC_NL_LOG_MAX = 30;

require __DIR__ . '/newsletter-emails.php';

/* ============================================================
   OPCIONES Y ESTADO
   ============================================================ */
function oec_nl_defaults(): array {
	return [
		'api_key'        => '',
		// Temáticas: [ ListName => [ label, enabled, cats[], subject ] ]
		// "subject" = subject-id de la API OAS (ej. "nutricion-deportiva").
		'lists'          => [],
		'digest_enabled' => false,
		'weekday'        => 1, // 1 = lunes … 7 = domingo
		'hour'           => 9,
		'send_mode'      => 'active', // active | draft
	];
}

function oec_nl_opts(): array {
	return wp_parse_args( (array) oec_config_get_option( OEC_NL_OPTION, [] ), oec_nl_defaults() );
}

function oec_nl_api_key(): string {
	return trim( (string) oec_nl_opts()['api_key'] );
}

function oec_nl_state(): array {
	return wp_parse_args( (array) oec_config_get_option( OEC_NL_STATE, [] ), [
		'next_run'  => 0,
		'last_sent' => [],
		'log'       => [],
	] );
}

function oec_nl_save_state( array $state ): void {
	oec_config_update_option( OEC_NL_STATE, $state, false );
}

function oec_nl_log( string $level, string $message ): void {
	$state = oec_nl_state();
	array_unshift( $state['log'], [ 'time' => time(), 'level' => $level, 'msg' => $message ] );
	$state['log'] = array_slice( $state['log'], 0, OEC_NL_LOG_MAX );
	oec_nl_save_state( $state );
}

/** Temáticas activas (sin la general). */
function oec_nl_topic_lists(): array {
	$lists = array_filter( oec_nl_opts()['lists'], fn( $l ) => ! empty( $l['enabled'] ) );
	unset( $lists[ OEC_NL_GENERAL_LIST ] );
	return $lists;
}

/** General + temáticas activas, con los datos que usa el digest. */
function oec_nl_all_lists(): array {
	$out = [
		OEC_NL_GENERAL_LIST => [
			'name'    => OEC_NL_GENERAL_LIST,
			'label'   => __( 'Newsletter semanal', 'oec-theme' ),
			'general' => true,
			'cats'    => [],
			'subject' => '',
		],
	];
	foreach ( oec_nl_topic_lists() as $name => $list ) {
		$out[ $name ] = [
			'name'    => $name,
			'label'   => $list['label'] ?: $name,
			'general' => false,
			'cats'    => array_map( 'intval', $list['cats'] ?? [] ),
			'subject' => (string) ( $list['subject'] ?? '' ),
		];
	}
	return $out;
}

/* ── Newsletters por temática: uno por landing especial ─────────
   Cada landing de oec_get_especiales_list() tiene su lista ("lista", por
   defecto "G-SE - {title}"). Su hero ofrece esa lista en vez de la
   general ([oec-credits-widget tematica="…"]), con sus propios +50
   créditos (una vez por lista). Si la lista no existe en Elastic Email,
   se crea sola y queda registrada para el envío semanal
   (oec_nl_ensure_especiales_lists()). */

/** [ ListName => [ tematica, label, categoria ] ] de las landings especiales. */
function oec_nl_especiales_lists(): array {
	if ( ! function_exists( 'oec_get_especiales_list' ) ) {
		return [];
	}
	$out = [];
	foreach ( oec_get_especiales_list() as $e ) {
		$name         = $e['lista'] ?? 'G-SE - ' . $e['title'];
		$out[ $name ] = [
			'tematica'  => $e['tematica'],
			'label'     => $e['title'],
			'categoria' => $e['categoria'] ?? $e['tematica'],
		];
	}
	return $out;
}

/** Lista de la landing de una temática ('' si no hay landing). */
function oec_nl_list_for_tematica( string $tematica ): string {
	foreach ( oec_nl_especiales_lists() as $name => $e ) {
		if ( $e['tematica'] === $tematica ) {
			return $name;
		}
	}
	return '';
}

/**
 * Listas a las que alguien puede suscribirse desde el sitio: la general,
 * las temáticas activas y las de las landings (aunque su envío semanal
 * esté pausado en el admin).
 */
function oec_nl_subscribable_lists(): array {
	return array_values( array_unique( array_merge( array_keys( oec_nl_all_lists() ), array_keys( oec_nl_especiales_lists() ) ) ) );
}

/** La lista pedida en un request ("list"), si es válida; si no, la general. */
function oec_nl_request_list( WP_REST_Request $request ): string {
	$list = sanitize_text_field( (string) $request->get_param( 'list' ) );
	return in_array( $list, oec_nl_subscribable_lists(), true ) ? $list : OEC_NL_GENERAL_LIST;
}

/** Nombre visible de una lista ("Nutrición Deportiva"). */
function oec_nl_list_label( string $list ): string {
	$esp = oec_nl_especiales_lists()[ $list ] ?? null;
	if ( $esp ) {
		return $esp['label'];
	}
	return oec_nl_all_lists()[ $list ]['label'] ?? $list;
}

/**
 * Referencia de los créditos por suscribirse: una vez por lista. Con la
 * general entre las elegidas, la de siempre; si no, la de la temática
 * (newsletter-nutricion-deportiva-g-se, …).
 */
function oec_nl_subscribe_ref( array $lists ): string {
	if ( ! $lists || in_array( OEC_NL_GENERAL_LIST, $lists, true ) ) {
		return OEC_NL_REF_SUBSCRIBE;
	}
	$esp = oec_nl_especiales_lists()[ $lists[0] ] ?? null;
	return 'newsletter-' . ( $esp ? $esp['tematica'] : sanitize_title( $lists[0] ) ) . '-g-se';
}

/**
 * Crea en Elastic Email la lista de cada landing que todavía no la tenga y
 * la registra en la configuración: activa, con la temática de la API
 * (formaciones) y la categoría del blog (artículos) de la landing, para que
 * entre en el envío semanal igual que la general. Solo toca las que nunca
 * se registraron ("especial" vacío): después manda lo que se edite en
 * Apariencia → Newsletter.
 *
 * @return int|WP_Error Cantidad de listas registradas.
 */
function oec_nl_ensure_especiales_lists() {
	$opts = oec_nl_opts();

	// Ya registradas pero sin categoría del blog (la categoría no existía al
	// registrarla, p. ej. un slug que cambió): se completa apenas exista.
	// Sin esto, el newsletter de esa temática llevaría artículos de todas.
	$fixed = 0;
	foreach ( oec_nl_especiales_lists() as $name => $e ) {
		if ( ! empty( $opts['lists'][ $name ]['especial'] ) && empty( $opts['lists'][ $name ]['cats'] ) ) {
			$cat = get_category_by_slug( $e['categoria'] );
			if ( $cat ) {
				$opts['lists'][ $name ]['cats'] = [ (int) $cat->term_id ];
				++$fixed;
			}
		}
	}
	if ( $fixed ) {
		oec_config_update_option( OEC_NL_OPTION, $opts );
	}

	$pending = array_filter(
		oec_nl_especiales_lists(),
		fn( $name ) => empty( $opts['lists'][ $name ]['especial'] ),
		ARRAY_FILTER_USE_KEY
	);
	if ( ! $pending ) {
		return 0;
	}

	$remote = oec_nl_api( 'GET', '/lists' );
	if ( is_wp_error( $remote ) ) {
		return $remote;
	}
	// Comparación sin distinguir mayúsculas ni la codificación de los acentos:
	// una "ó" escrita distinto no debe terminar en una lista duplicada.
	$norm     = fn( $n ) => mb_strtolower( class_exists( 'Normalizer' ) ? (string) Normalizer::normalize( $n ) : $n );
	$existing = array_map( $norm, array_column( $remote, 'ListName' ) );

	$done = 0;
	foreach ( $pending as $name => $e ) {
		if ( ! in_array( $norm( $name ), $existing, true ) ) {
			$created = oec_nl_api( 'POST', '/lists', [ 'ListName' => $name, 'AllowUnsubscribe' => true ] );
			if ( is_wp_error( $created ) ) {
				oec_nl_log( 'error', sprintf( 'No se pudo crear la lista %s: %s', $name, $created->get_error_message() ) );
				continue;
			}
			oec_nl_log( 'success', sprintf( 'Lista creada en Elastic Email para la landing %s: %s', $e['tematica'], $name ) );
		}

		$old = $opts['lists'][ $name ] ?? [];
		$cat = get_category_by_slug( $e['categoria'] );

		$opts['lists'][ $name ] = [
			'label'    => ! empty( $old['label'] ) && $old['label'] !== $name ? $old['label'] : $e['label'],
			'enabled'  => true,
			'cats'     => ! empty( $old['cats'] ) ? $old['cats'] : ( $cat ? [ (int) $cat->term_id ] : [] ),
			'subject'  => ! empty( $old['subject'] ) ? $old['subject'] : $e['tematica'],
			'especial' => $e['tematica'],
		];
		++$done;
	}

	ksort( $opts['lists'] );
	oec_config_update_option( OEC_NL_OPTION, $opts );
	return $done;
}

// Se revisa al entrar al admin del sitio de configuración (una vez por día,
// o apenas cambia la lista de landings) y antes de cada envío semanal.
add_action( 'admin_init', function () {
	if ( ! oec_is_config_site() || ! current_user_can( 'manage_options' ) || ! oec_nl_api_key() ) {
		return;
	}
	$sig = md5( implode( '|', array_keys( oec_nl_especiales_lists() ) ) );
	if ( get_transient( 'oec_nl_especiales_checked' ) === $sig ) {
		return;
	}
	if ( ! is_wp_error( oec_nl_ensure_especiales_lists() ) ) {
		set_transient( 'oec_nl_especiales_checked', $sig, DAY_IN_SECONDS );
	}
} );

function oec_nl_weekday_plural(): string {
	$names = [ 1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados', 7 => 'domingos' ];
	return $names[ (int) oec_nl_opts()['weekday'] ] ?? 'lunes';
}

function oec_nl_client_ip(): string {
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/** true si se superó el límite de $max requests por minuto para esta IP. */
function oec_nl_rate_limited( string $bucket, int $max ): bool {
	$key   = 'oec_nl_rate_' . $bucket . '_' . md5( oec_nl_client_ip() );
	$count = (int) get_transient( $key );
	if ( $count >= $max ) {
		return true;
	}
	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	return false;
}

/* ============================================================
   FIRMA DE LINKS (confirmación y créditos semanales)
   ============================================================ */
function oec_nl_secret(): string {
	return wp_salt( 'auth' ) . '|oec-newsletter';
}

function oec_nl_b64( string $raw ): string {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

function oec_nl_unb64( string $str ): string {
	return (string) base64_decode( strtr( $str, '-_', '+/' ) );
}

function oec_nl_sign_token( array $payload ): string {
	$body = oec_nl_b64( wp_json_encode( $payload ) );
	return $body . '.' . oec_nl_b64( hash_hmac( 'sha256', $body, oec_nl_secret(), true ) );
}

function oec_nl_read_token( string $token ): ?array {
	$parts = explode( '.', $token );
	if ( 2 !== count( $parts ) ) {
		return null;
	}
	$expected = oec_nl_b64( hash_hmac( 'sha256', $parts[0], oec_nl_secret(), true ) );
	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return null;
	}
	$data = json_decode( oec_nl_unb64( $parts[0] ), true );
	if ( ! is_array( $data ) || empty( $data['exp'] ) || time() > (int) $data['exp'] ) {
		return null;
	}
	return $data;
}

/** Firma del link de créditos semanales: depende solo de la fecha de envío. */
function oec_nl_claim_sig( int $sent_at ): string {
	return substr( hash_hmac( 'sha256', 'claim|' . $sent_at, oec_nl_secret() ), 0, 20 );
}

/* ============================================================
   CLIENTE API ELASTIC EMAIL
   ============================================================ */

/**
 * API v4. $query admite arrays (se repiten como key=a&key=b, que es lo
 * que espera Elastic Email para listnames).
 *
 * @return array|WP_Error
 */
function oec_nl_api( string $method, string $path, $body = null, array $query = [] ) {
	$key = oec_nl_api_key();
	if ( ! $key ) {
		return new WP_Error( 'oec_nl_no_key', __( 'Falta la API key de Elastic Email (Apariencia → Newsletter).', 'oec-theme' ) );
	}

	$qs = [];
	foreach ( $query as $k => $v ) {
		foreach ( (array) $v as $item ) {
			$qs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $item );
		}
	}
	$url  = OEC_NL_API_V4 . $path . ( $qs ? '?' . implode( '&', $qs ) : '' );
	$args = [
		'method'  => $method,
		'timeout' => 30,
		'headers' => [ 'X-ElasticEmail-ApiKey' => $key, 'Accept' => 'application/json' ],
	];
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		$msg = is_array( $data ) ? ( $data['Error'] ?? $data['error'] ?? $raw ) : $raw;
		return new WP_Error( 'oec_nl_http_' . $code, sprintf( 'Elastic Email (%d): %s', $code, wp_strip_all_tags( (string) $msg ) ) );
	}
	return is_array( $data ) ? $data : [];
}

/**
 * API v2 (legacy). Solo para contact/findcontact, que la v4 no tiene:
 * devuelve todas las listas a las que pertenece un email.
 *
 * @return mixed|WP_Error El campo "data" de la respuesta.
 */
function oec_nl_api_v2( string $path, array $params ) {
	$key = oec_nl_api_key();
	if ( ! $key ) {
		return new WP_Error( 'oec_nl_no_key', 'Falta la API key de Elastic Email.' );
	}
	$response = wp_remote_post( OEC_NL_API_V2 . $path, [
		'timeout' => 15,
		'body'    => array_merge( [ 'apikey' => $key ], $params ),
	] );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['success'] ) ) {
		return new WP_Error( 'oec_nl_v2', 'Elastic Email v2: ' . ( $data['error'] ?? 'respuesta inválida' ) );
	}
	return $data['data'] ?? [];
}

/** ¿El error de Elastic Email significa "ese contacto no existe"? */
function oec_nl_is_not_found( WP_Error $error ): bool {
	return 'oec_nl_http_404' === $error->get_error_code()
		|| false !== stripos( $error->get_error_message(), 'could not find contact' );
}

/**
 * Nombres de las listas de la respuesta de findcontact. Formato real:
 * {"lists":[{"id":584,"name":"Prueba"}],"segments":[…]} — solo listas,
 * los segmentos ("Engaged Contacts", etc.) no cuentan.
 */
function oec_nl_list_names( $data ): array {
	$names = [];
	foreach ( (array) ( $data['lists'] ?? $data['Lists'] ?? [] ) as $list ) {
		$name = is_array( $list ) ? ( $list['name'] ?? $list['Name'] ?? '' ) : '';
		if ( is_string( $name ) && '' !== $name ) {
			$names[] = $name;
		}
	}
	return $names;
}

/**
 * ¿El email está suscripto (contacto Active/Engaged) a alguna de $lists?
 * Resultado cacheado 1 h por email.
 *
 * @return bool|WP_Error
 */
function oec_nl_is_subscribed( string $email, array $lists ) {
	$cache_key = 'oec_nl_sub_' . md5( strtolower( $email ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return (bool) array_intersect( $lists, $cached );
	}

	$member_of = [];
	$contact   = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
	if ( is_wp_error( $contact ) ) {
		// Contacto inexistente = no suscripto. Elastic Email responde 400
		// "Could not find contact" (no 404).
		if ( ! oec_nl_is_not_found( $contact ) ) {
			return $contact;
		}
	} elseif ( in_array( $contact['Status'] ?? '', [ 'Active', 'Engaged' ], true ) ) {
		$found = oec_nl_api_v2( '/contact/findcontact', [ 'email' => $email ] );
		if ( is_wp_error( $found ) && ! oec_nl_is_not_found( $found ) ) {
			return $found;
		}
		if ( ! is_wp_error( $found ) ) {
			$member_of = oec_nl_list_names( $found );
		}
	}

	set_transient( $cache_key, array_values( array_unique( $member_of ) ), HOUR_IN_SECONDS );
	return (bool) array_intersect( $lists, $member_of );
}

/**
 * Alta/actualización del contacto como Active en las listas indicadas.
 *
 * @return true|WP_Error
 */
function oec_nl_add_contact( string $email, string $first, string $last, array $lists ) {
	$payload = [ [
		'Email'     => $email,
		'Status'    => 'Active',
		'FirstName' => $first,
		'LastName'  => $last,
		'Consent'   => [
			'ConsentIP'       => oec_nl_client_ip(),
			'ConsentDate'     => gmdate( 'Y-m-d\TH:i:s' ),
			'ConsentTracking' => 'Allow',
		],
	] ];

	$res = oec_nl_api( 'POST', '/contacts', $payload, [ 'listnames' => $lists ] );
	if ( is_wp_error( $res ) ) {
		// Contacto existente: actualizamos nombre y lo sumamos a cada lista.
		$upd = oec_nl_api( 'PUT', '/contacts/' . rawurlencode( $email ), [ 'FirstName' => $first, 'LastName' => $last ] );
		if ( is_wp_error( $upd ) ) {
			return $res;
		}
		foreach ( $lists as $list ) {
			$add = oec_nl_api( 'POST', '/lists/' . rawurlencode( $list ) . '/contacts', [ 'Emails' => [ $email ] ] );
			if ( is_wp_error( $add ) ) {
				return $add;
			}
		}
	}

	delete_transient( 'oec_nl_sub_' . md5( strtolower( $email ) ) );
	return true;
}

/**
 * ¿Esta lista forma parte de la configuración? La general, las de las
 * landings de temática, las activas y las que tienen algo cargado
 * (categorías, subject-id o un nombre público propio). El resto de las
 * listas de la cuenta no se guardan en la config ni se muestran.
 */
function oec_nl_list_is_configured( string $name, array $l ): bool {
	return OEC_NL_GENERAL_LIST === $name
		|| ! empty( $l['enabled'] )
		|| ! empty( $l['especial'] )
		|| ! empty( $l['cats'] )
		|| ! empty( $l['subject'] )
		|| ( isset( $l['label'] ) && '' !== $l['label'] && $l['label'] !== $name );
}

function oec_nl_prune_lists( array $lists ): array {
	return array_filter( $lists, fn( $l, $name ) => oec_nl_list_is_configured( (string) $name, (array) $l ), ARRAY_FILTER_USE_BOTH );
}

/** Nombres de todas las listas de la cuenta (última sincronización). */
function oec_nl_remote_list_names(): array {
	return (array) oec_config_get_option( OEC_NL_REMOTE_LISTS, [] );
}

/**
 * Trae los nombres de todas las listas de la cuenta (para el buscador de
 * "Agregar temática") y deja en la config solo las configuradas que siguen
 * existiendo en Elastic Email.
 *
 * @return int|WP_Error Cantidad de listas en la cuenta.
 */
function oec_nl_sync_lists() {
	$remote = oec_nl_api( 'GET', '/lists' );
	if ( is_wp_error( $remote ) ) {
		return $remote;
	}
	$names = array_values( array_unique( array_filter( array_map(
		fn( $l ) => sanitize_text_field( $l['ListName'] ?? '' ),
		(array) $remote
	) ) ) );
	natcasesort( $names );
	oec_config_update_option( OEC_NL_REMOTE_LISTS, array_values( $names ), false );

	$opts  = oec_nl_opts();
	$lists = [];
	foreach ( oec_nl_prune_lists( $opts['lists'] ) as $name => $l ) {
		// Las de las landings se crean solas si faltan (oec_nl_ensure_especiales_lists).
		if ( in_array( $name, $names, true ) || ! empty( $l['especial'] ) ) {
			$lists[ $name ] = $l;
		}
	}
	ksort( $lists );
	$opts['lists'] = $lists;
	oec_config_update_option( OEC_NL_OPTION, $opts );
	return count( $names );
}

/* ============================================================
   EMAIL DE CONFIRMACIÓN (plantilla en inc/newsletter-emails.php)
   ============================================================ */

/** @return true|WP_Error */
/** Link firmado a /newsletter-confirmado/ (lo usan nuestro email y la reactivación de Elastic Email). */
function oec_nl_confirm_url( string $email, string $first, string $last, array $lists ): string {
	$token = oec_nl_sign_token( [
		'e'   => $email,
		'f'   => $first,
		'l'   => $last,
		'ls'  => $lists,
		'exp' => time() + OEC_NL_CONFIRM_HOURS * HOUR_IN_SECONDS,
	] );
	return add_query_arg( 't', $token, oec_config_url( '/newsletter-confirmado/' ) );
}

function oec_nl_send_confirmation( string $email, string $first, string $last, array $lists ) {
	$url = oec_nl_confirm_url( $email, $first, $last, $lists );

	$res = oec_nl_api( 'POST', '/emails/transactional', [
		'Recipients' => [ 'To' => [ $email ] ],
		'Content'    => [
			'From'    => OEC_NL_FROM,
			'Subject' => sprintf( __( 'Confirmá tu suscripción y sumá %d créditos', 'oec-theme' ), OEC_NL_SUBSCRIBE_CREDITS ),
			'Body'    => [ [ 'ContentType' => 'HTML', 'Content' => oec_nl_render_confirm_email( $first, $url ), 'Charset' => 'utf-8' ] ],
		],
	] );
	return is_wp_error( $res ) ? $res : true;
}

/* ============================================================
   REST
   ============================================================ */
add_action( 'rest_api_init', function () {
	$routes = [
		'/newsletter/status'    => 'oec_nl_rest_status',
		'/newsletter/subscribe' => 'oec_nl_rest_subscribe',
		'/newsletter/confirm'   => 'oec_nl_rest_confirm',
		'/newsletter/claim'     => 'oec_nl_rest_claim',
		'/newsletter/resend'    => 'oec_nl_rest_resend',
	];
	foreach ( $routes as $route => $cb ) {
		register_rest_route( 'oec/v1', $route, [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => $cb,
			'permission_callback' => '__return_true',
		] );
	}
} );

function oec_nl_json( array $data, int $status = 200 ): WP_REST_Response {
	return new WP_REST_Response( $data, $status );
}

/** POST { email, list? } → { offer: bool } — ¿le ofrecemos ese newsletter (por defecto, el general)? */
function oec_nl_rest_status( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'status', 20 ) ) {
		return oec_nl_json( [ 'offer' => false ], 429 );
	}
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	if ( ! is_email( $email ) ) {
		return oec_nl_json( [ 'offer' => false ], 400 );
	}

	$list       = oec_nl_request_list( $request );
	$subscribed = oec_nl_is_subscribed( $email, [ $list ] );
	if ( is_wp_error( $subscribed ) ) {
		// Si Elastic Email no responde, no mostramos nada (mejor que un formulario roto).
		oec_nl_log( 'error', 'Status: ' . $subscribed->get_error_message() );
		return oec_nl_json( [ 'offer' => false, 'weekly' => null ] );
	}
	return oec_nl_json( [
		'offer'      => ! $subscribed,
		'subscribed' => (bool) $subscribed, // solo en respuestas confirmadas (los errores no lo traen)
		'weekly'     => $subscribed ? oec_nl_weekly_status( $email, $list ) : null,
	] );
}

/**
 * Créditos semanales del último newsletter de una lista para un suscriptor
 * (los créditos son uno por semana, vengan del newsletter que vengan).
 * null = no hay newsletter reclamable (ninguno enviado, venció el plazo
 * o la API de créditos no respondió).
 *
 * @return array{claimed: bool, credits: int, date: string}|null
 */
function oec_nl_weekly_status( string $email, string $list = OEC_NL_GENERAL_LIST ): ?array {
	$latest = ( (array) oec_config_get_option( OEC_NL_LATEST, [] ) )[ $list ] ?? null;
	if ( empty( $latest['time'] ) || time() - (int) $latest['time'] > OEC_NL_CLAIM_DAYS * DAY_IN_SECONDS ) {
		return null;
	}
	if ( ! function_exists( 'oec_credits_has_reference' ) ) {
		return null;
	}
	$claimed = oec_credits_has_reference( $email, OEC_NL_REF_WEEKLY . gmdate( 'o-\WW', (int) $latest['time'] ) );
	if ( is_wp_error( $claimed ) ) {
		oec_nl_log( 'error', 'Créditos semanales (consulta) ' . $email . ': ' . $claimed->get_error_message() );
		return null;
	}
	return [
		'claimed' => $claimed,
		'credits' => OEC_NL_WEEKLY_CREDITS,
		'date'    => wp_date( 'l j \d\e F', (int) $latest['time'] ),
	];
}

/**
 * Reemplaza los merge tags de Elastic Email con los datos del contacto,
 * para reenviar el newsletter como email transaccional individual.
 * {unsubscribe} se deja: Elastic Email lo resuelve también en envíos
 * transaccionales.
 */
function oec_nl_personalize_html( string $html, string $email, string $first, string $last, string $list = OEC_NL_GENERAL_LIST ): string {
	// Sin nombre en el contacto, que no quede "Hola , estos son…".
	$greetings = '' === $first ? [ 'Hola {firstname}, estos' => '¡Hola! Estos', '{firstname}, nuestros' => 'Hola, nuestros', 'para {firstname}.' => 'para vos.' ] : [];
	return strtr( oec_nl_fix_saved_html( $html ), $greetings + [
		'{{ encodeURIComponent(email) }}'     => rawurlencode( $email ),
		'{{ encodeURIComponent(firstname) }}' => rawurlencode( $first ),
		'{{ encodeURIComponent(lastname) }}'  => rawurlencode( $last ),
		'{email}'                             => rawurlencode( $email ), // solo aparece en el link de créditos
		'{firstname}'                         => esc_html( $first ),
		'{lastname}'                          => esc_html( $last ),
		'{country}'                           => '',
		'{view}'                              => esc_url( oec_nl_latest_url( $list ) ),
		'{accountaddress}'                    => esc_html( OEC_NL_ADDRESS ),
	] );
}

/** POST { email, list? } → reenvía el último newsletter de esa lista (por defecto, el general) a ese suscriptor. */
function oec_nl_rest_resend( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'resend', 3 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	if ( ! is_email( $email ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Ingresá un email válido.', 'oec-theme' ) ], 400 );
	}

	$list       = oec_nl_request_list( $request );
	$subscribed = oec_nl_is_subscribed( $email, [ $list ] );
	if ( true !== $subscribed ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Este email no está suscripto al newsletter.', 'oec-theme' ) ], 403 );
	}

	$weekly = oec_nl_weekly_status( $email, $list );
	if ( ! $weekly ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No hay un newsletter disponible para reenviar en este momento.', 'oec-theme' ) ], 404 );
	}
	if ( $weekly['claimed'] ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Ya reclamaste los créditos de esta semana. ¡Te esperamos el próximo newsletter!', 'oec-theme' ) ] );
	}

	$ok = [
		'ok'      => true,
		'message' => sprintf(
			/* translators: %s: email */
			__( '¡Listo! Te lo reenviamos a %s. Abrilo y tocá "Obtener mis créditos" (revisá también spam y promociones).', 'oec-theme' ),
			$email
		),
	];

	// Un reenvío cada 30 minutos por email.
	$throttle = 'oec_nl_resent_' . md5( strtolower( $email ) );
	if ( get_transient( $throttle ) ) {
		return oec_nl_json( $ok );
	}

	$latest  = ( (array) oec_config_get_option( OEC_NL_LATEST, [] ) )[ $list ];
	$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
	$first   = is_wp_error( $contact ) ? '' : (string) ( $contact['FirstName'] ?? '' );
	$last    = is_wp_error( $contact ) ? '' : (string) ( $contact['LastName'] ?? '' );

	$sent = oec_nl_api( 'POST', '/emails/transactional', [
		'Recipients' => [ 'To' => [ $email ] ],
		'Content'    => [
			'From'    => OEC_NL_FROM,
			'Subject' => $latest['subject'] ?? __( 'Tu newsletter de esta semana', 'oec-theme' ),
			'Body'    => [ [ 'ContentType' => 'HTML', 'Content' => oec_nl_personalize_html( $latest['html'], $email, $first, $last, $list ), 'Charset' => 'utf-8' ] ],
		],
	] );
	if ( is_wp_error( $sent ) ) {
		oec_nl_log( 'error', 'Reenvío a ' . $email . ': ' . $sent->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos reenviarte el newsletter. Probá de nuevo más tarde.', 'oec-theme' ) ], 502 );
	}

	set_transient( $throttle, 1, 30 * MINUTE_IN_SECONDS );
	oec_nl_log( 'info', 'Newsletter reenviado a ' . $email );
	return oec_nl_json( $ok );
}

/* ============================================================
   DIRECCIONES SUPRIMIDAS
   Elastic Email no envía a direcciones suprimidas: el email de
   confirmación "sale" pero nunca llega. Antes de mandarlo se revisa el
   estado del contacto:
   - Rebotado / inactivo: se pasa a Active (API v2 contact/changestatus) y
     sale nuestro email. Una vez cada 30 días por dirección: si vuelve a
     rebotar, Elastic Email la suprime otra vez.
     Ojo: DELETE /suppressions NO sirve: borra la supresión pero el contacto
     conserva el estado y el próximo envío lo vuelve a suprimir.
   - Baja / spam / sin confirmar: Elastic Email no deja reactivarlos por API
     ("You can not change an existing contact's status to Active for
     Unsubscribed, Complaint or NotConfirmed contacts"). La única vía es su
     propio doble opt-in (v2 contact/add con sendActivation): Elastic Email
     manda su email de activación y, al hacer clic, reactiva el contacto y
     redirige a /newsletter-confirmado/ con nuestro token. Hasta ese clic el
     contacto queda NotConfirmed y no recibe campañas. Para spam además se
     pide una declaración explícita (abuse_consent), una vez cada 90 días.
   ============================================================ */

/**
 * @return array{status: string}|null|WP_Error null = no está suprimida.
 */
function oec_nl_suppression( string $email ) {
	$s = oec_nl_api( 'GET', '/suppressions/' . rawurlencode( $email ) );
	if ( is_wp_error( $s ) ) {
		return 'oec_nl_http_404' === $s->get_error_code() ? null : $s;
	}
	// La supresión no dice el motivo: lo da el estado del contacto.
	$c = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
	return [ 'status' => is_wp_error( $c ) ? 'Unknown' : (string) ( $c['Status'] ?? 'Unknown' ) ];
}

function oec_nl_abuse_consent_label(): string {
	return sprintf( __( 'Quiero volver a recibir los correos de %s.', 'oec-theme' ), OEC_NL_BRAND );
}

/** Public Account ID de la cuenta (para contact/add), cacheado 30 días. */
function oec_nl_public_account_id(): string {
	$id = get_transient( 'oec_nl_public_account_id' );
	if ( false === $id ) {
		$acc = oec_nl_api_v2( '/account/load', [] );
		$id  = is_wp_error( $acc ) ? '' : (string) ( $acc['publicaccountid'] ?? '' );
		if ( $id ) {
			set_transient( 'oec_nl_public_account_id', $id, 30 * DAY_IN_SECONDS );
		}
	}
	return (string) $id;
}

/**
 * Doble opt-in de Elastic Email (v2 contact/add con sendActivation): la vía
 * que acepta para volver a suscribir bajas, quejas y no confirmados.
 *
 * @return true|WP_Error
 */
function oec_nl_send_activation( string $email, string $first, string $last, array $lists ) {
	$public = oec_nl_public_account_id();
	if ( ! $public ) {
		return new WP_Error( 'oec_nl_no_public_id', 'No se pudo obtener el Public Account ID de Elastic Email.' );
	}
	$params = [
		'publicAccountID'     => $public,
		'email'               => $email,
		'firstName'           => $first,
		'lastName'            => $last,
		'sendActivation'      => 'true',
		'activationReturnUrl' => oec_nl_confirm_url( $email, $first, $last, $lists ),
		'consentIP'           => oec_nl_client_ip(),
		'consentDate'         => gmdate( 'Y-m-d\TH:i:s' ),
		'sourceUrl'           => oec_config_url( '/' ),
	];
	$pairs = [];
	foreach ( array_filter( $params, 'strlen' ) as $k => $v ) {
		$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
	}
	foreach ( $lists as $list ) {
		$pairs[] = 'listName=' . rawurlencode( $list ); // se repite: es una lista
	}
	$res = wp_remote_post( OEC_NL_API_V2 . '/contact/add', [
		'timeout' => 20,
		'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
		'body'    => implode( '&', $pairs ),
	] );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return ! empty( $data['success'] ) ? true : new WP_Error( 'oec_nl_activation', 'Elastic Email v2: ' . ( $data['error'] ?? 'respuesta inválida' ) );
}

/**
 * Reactiva una dirección suprimida si corresponde.
 *
 * @return string|WP_REST_Response Texto a anteponer al mensaje de éxito (y
 *         seguir con nuestro email), o la respuesta final a devolver.
 */
function oec_nl_handle_suppression( string $email, string $status, bool $abuse_consent, string $first, string $last, array $lists ) {
	$key        = md5( strtolower( $email ) );
	$abuse      = 'Abuse' === $status;
	$activation = in_array( $status, [ 'Unsubscribed', 'Abuse', 'NotConfirmed' ], true );

	if ( $abuse && ! $abuse_consent ) {
		oec_nl_log( 'info', 'Suscripción frenada: ' . $email . ' figura como spam en Elastic Email (se pidió confirmación).' );
		return oec_nl_json( [
			'ok'            => false,
			'code'          => 'abuse',
			'message'       => __( 'Hace un tiempo marcaste nuestros correos como spam, por eso no podemos escribirte. Si ahora querés recibirlos, tildá la casilla y volvé a tocar el botón: te mandamos un único email para confirmar.', 'oec-theme' ),
			'consent_label' => oec_nl_abuse_consent_label(),
		] );
	}

	$lock = ( $abuse ? 'oec_nl_unabuse_' : 'oec_nl_unsup_' ) . $key;
	if ( get_transient( $lock ) ) {
		oec_nl_log( 'info', sprintf( 'Suscripción frenada: %s (%s) ya se intentó reactivar hace poco.', $email, $status ) );
		return oec_nl_json( [
			'ok'      => false,
			'code'    => 'suppressed',
			'message' => $activation
				? __( 'Hace poco te enviamos un email para reactivar tu suscripción. Buscalo en tu bandeja (y en spam) o escribinos y lo resolvemos.', 'oec-theme' )
				: __( 'Tu casilla rechazó nuestros correos hace poco. Revisá que el email esté bien escrito o probá con otro.', 'oec-theme' ),
		] );
	}

	if ( ! $activation ) {
		$changed = oec_nl_api_v2( '/contact/changestatus', [ 'emails' => $email, 'status' => 'Active' ] );
		if ( is_wp_error( $changed ) ) {
			// Por si el estado real es uno de los que Elastic Email no deja cambiar.
			if ( false === stripos( $changed->get_error_message(), 'can not change' ) ) {
				oec_nl_log( 'error', 'No se pudo reactivar ' . $email . ': ' . $changed->get_error_message() );
				return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos enviarte el email de confirmación. Probá de nuevo más tarde.', 'oec-theme' ) ], 502 );
			}
			$activation = true;
		}
	}

	if ( $activation ) {
		$sent = oec_nl_send_activation( $email, $first, $last, $lists );
		if ( is_wp_error( $sent ) ) {
			oec_nl_log( 'error', 'Reactivación por doble opt-in de ' . $email . ': ' . $sent->get_error_message() );
			return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos enviarte el email de confirmación. Probá de nuevo más tarde.', 'oec-theme' ) ], 502 );
		}
		set_transient( $lock, 1, ( $abuse ? OEC_NL_UNABUSE_DAYS : OEC_NL_UNSUPPRESS_DAYS ) * DAY_IN_SECONDS );
		delete_transient( 'oec_nl_sub_' . $key );
		oec_nl_log( 'success', sprintf( 'Reactivación pedida a Elastic Email (doble opt-in) para %s (estaba %s%s).', $email, $status, $abuse ? ', con declaración del usuario' : '' ) );
		return oec_nl_json( [
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: email */
				__( 'Tu dirección estaba dada de baja de nuestros envíos. Te mandamos un email para reactivarla a %s: tocá el botón de confirmación y sumás tus créditos (revisá también spam).', 'oec-theme' ),
				$email
			),
		] );
	}

	set_transient( $lock, 1, OEC_NL_UNSUPPRESS_DAYS * DAY_IN_SECONDS );
	delete_transient( 'oec_nl_sub_' . $key );
	oec_nl_log( 'success', sprintf( 'Dirección reactivada en Elastic Email: %s (estaba %s).', $email, $status ) );
	return __( 'Tu dirección estaba bloqueada para nuestros envíos por un rebote anterior y ya la reactivamos.', 'oec-theme' );
}

/** POST { email, first_name, last_name, lists[]?, abuse_consent?, website } → manda el email de confirmación. */
function oec_nl_rest_subscribe( WP_REST_Request $request ): WP_REST_Response {
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	$ok    = [
		'ok'      => true,
		'message' => sprintf(
			/* translators: %s: email */
			__( '¡Listo! Te enviamos un email a %s. Confirmá desde ahí tu suscripción para sumar tus créditos (revisá también spam).', 'oec-theme' ),
			$email
		),
	];

	// Honeypot: a los bots les respondemos "éxito" sin hacer nada.
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return oec_nl_json( $ok );
	}
	if ( oec_nl_rate_limited( 'subscribe', 5 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	if ( ! is_email( $email ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Ingresá un email válido.', 'oec-theme' ) ], 400 );
	}

	$first = mb_substr( sanitize_text_field( (string) $request->get_param( 'first_name' ) ), 0, 60 );
	$last  = mb_substr( sanitize_text_field( (string) $request->get_param( 'last_name' ) ), 0, 60 );
	if ( '' === $first || '' === $last ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Completá tus nombres y apellidos.', 'oec-theme' ) ], 400 );
	}

	$available = oec_nl_subscribable_lists();
	$requested = array_map( 'sanitize_text_field', (array) ( $request->get_param( 'lists' ) ?: [ OEC_NL_GENERAL_LIST ] ) );
	$lists     = array_values( array_intersect( $requested, $available ) );
	if ( ! $lists ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Elegí al menos un newsletter.', 'oec-theme' ) ], 400 );
	}

	// Un email de confirmación cada 2 minutos por dirección (evita usarnos para spamear a alguien).
	$throttle = 'oec_nl_sent_' . md5( strtolower( $email ) );
	if ( get_transient( $throttle ) ) {
		return oec_nl_json( $ok );
	}

	// Dirección suprimida en Elastic Email: el email no llegaría nunca.
	$sup = oec_nl_suppression( $email );
	if ( is_wp_error( $sup ) ) {
		// Si la consulta falla, mejor intentar el envío que frenar a todos.
		oec_nl_log( 'error', 'No se pudo revisar la supresión de ' . $email . ': ' . $sup->get_error_message() );
	} elseif ( $sup ) {
		$handled = oec_nl_handle_suppression( $email, $sup['status'], rest_sanitize_boolean( $request->get_param( 'abuse_consent' ) ), $first, $last, $lists );
		if ( $handled instanceof WP_REST_Response ) {
			return $handled;
		}
		$ok['message'] = $handled . ' ' . $ok['message'];
		sleep( 2 ); // margen para que Elastic Email aplique el cambio de estado antes del envío
	}

	$sent = oec_nl_send_confirmation( $email, $first, $last, $lists );
	if ( is_wp_error( $sent ) ) {
		oec_nl_log( 'error', 'Email de confirmación: ' . $sent->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos enviarte el email de confirmación. Probá de nuevo más tarde.', 'oec-theme' ) ], 502 );
	}
	set_transient( $throttle, 1, 2 * MINUTE_IN_SECONDS );
	return oec_nl_json( $ok );
}

/** POST { token } → alta en Elastic Email + 50 créditos. */
function oec_nl_rest_confirm( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'confirm', 10 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	$data = oec_nl_read_token( (string) $request->get_param( 'token' ) );
	if ( ! $data || ! is_email( $data['e'] ?? '' ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'El link de confirmación no es válido o venció. Volvé a suscribirte desde el sitio.', 'oec-theme' ) ], 400 );
	}

	$email = $data['e'];
	$lists = array_values( array_intersect( (array) ( $data['ls'] ?? [] ), oec_nl_subscribable_lists() ) ) ?: [ OEC_NL_GENERAL_LIST ];

	$added = oec_nl_add_contact( $email, (string) ( $data['f'] ?? '' ), (string) ( $data['l'] ?? '' ), $lists );
	if ( is_wp_error( $added ) ) {
		oec_nl_log( 'error', 'Confirmación ' . $email . ': ' . $added->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos completar tu suscripción. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}

	// Una vez por lista: suscribirse a la general y después a una temática suma dos veces.
	$credits = function_exists( 'oec_credits_grant_once' ) ? oec_credits_grant_once( $email, OEC_NL_SUBSCRIBE_CREDITS, oec_nl_subscribe_ref( $lists ) ) : null;
	if ( is_wp_error( $credits ) || null === $credits ) {
		oec_nl_log( 'error', 'Créditos suscripción ' . $email . ': ' . ( is_wp_error( $credits ) ? $credits->get_error_message() : 'módulo de créditos ausente' ) );
		return oec_nl_json( [
			'ok'      => true,
			'email'   => $email,
			'balance' => null,
			'message' => __( '¡Ya estás suscripto! No pudimos acreditar tus créditos en este momento; escribinos y lo resolvemos.', 'oec-theme' ),
		] );
	}

	oec_nl_log( 'success', 'Nuevo suscriptor: ' . $email . ' → ' . implode( ', ', $lists ) );
	return oec_nl_json( [
		'ok'      => true,
		'email'   => $email,
		'balance' => $credits['balance'],
		'granted' => $credits['granted'],
		'message' => $credits['granted']
			? sprintf( __( '¡Ya estás suscripto! Sumamos %d créditos a tu cuenta.', 'oec-theme' ), OEC_NL_SUBSCRIBE_CREDITS )
			: __( '¡Ya estás suscripto! Los créditos por suscribirte ya se habían acreditado antes.', 'oec-theme' ),
	] );
}

/** POST { email, t, s } → 20 créditos semanales. */
function oec_nl_rest_claim( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'claim', 10 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}

	// Elastic Email no codifica {email} en la URL: un "+" llega como espacio.
	$email   = sanitize_email( str_replace( ' ', '+', (string) $request->get_param( 'email' ) ) );
	$sent_at = (int) $request->get_param( 't' );
	$sig     = (string) $request->get_param( 's' );

	if ( ! is_email( $email ) || ! $sent_at || ! hash_equals( oec_nl_claim_sig( $sent_at ), $sig ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'El link no es válido. Abrilo desde el botón del newsletter.', 'oec-theme' ) ], 400 );
	}
	if ( time() - $sent_at > OEC_NL_CLAIM_DAYS * DAY_IN_SECONDS ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Este link ya venció. Esperá el newsletter del próximo lunes para reclamar los créditos de esa semana.', 'oec-theme' ) ], 410 );
	}

	$subscribed = oec_nl_is_subscribed( $email, oec_nl_subscribable_lists() );
	if ( is_wp_error( $subscribed ) ) {
		oec_nl_log( 'error', 'Claim ' . $email . ': ' . $subscribed->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos verificar tu suscripción. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}
	if ( ! $subscribed ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Este email no está suscripto al newsletter.', 'oec-theme' ) ], 403 );
	}

	$week    = gmdate( 'o-\WW', $sent_at );
	$credits = function_exists( 'oec_credits_grant_once' ) ? oec_credits_grant_once( $email, OEC_NL_WEEKLY_CREDITS, OEC_NL_REF_WEEKLY . $week ) : null;
	if ( is_wp_error( $credits ) || null === $credits ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos acreditar tus créditos. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}

	return oec_nl_json( [
		'ok'      => true,
		'email'   => $email,
		'balance' => $credits['balance'],
		'granted' => $credits['granted'],
		'message' => $credits['granted']
			? sprintf( __( '¡Sumamos tus %d créditos de esta semana!', 'oec-theme' ), OEC_NL_WEEKLY_CREDITS )
			: __( 'Ya reclamaste los créditos de esta semana. ¡Te esperamos el próximo newsletter!', 'oec-theme' ),
	] );
}

/* ============================================================
   FRONT: invitación del hero, shortcode y widget
   ============================================================ */
function oec_nl_enqueue_js(): void {
	wp_enqueue_script(
		'oec-newsletter',
		OEC_THEME_URI . '/assets/js/newsletter.js',
		[],
		oec_asset_version( 'assets/js/newsletter.js' ),
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);
}

function oec_nl_latest_url( string $list = OEC_NL_GENERAL_LIST ): string {
	$args = [ 'oec_newsletter' => 'ultimo' ];
	if ( OEC_NL_GENERAL_LIST !== $list ) {
		$args['lista'] = $list;
	}
	return add_query_arg( $args, oec_config_url( '/' ) );
}

function oec_nl_endpoints_attrs(): string {
	return sprintf(
		'data-status="%s" data-subscribe="%s" data-resend="%s"',
		esc_url( rest_url( 'oec/v1/newsletter/status' ) ),
		esc_url( rest_url( 'oec/v1/newsletter/subscribe' ) ),
		esc_url( rest_url( 'oec/v1/newsletter/resend' ) )
	);
}

/**
 * Bloque del newsletter dentro del estado "balance" de [oec-credits-widget].
 * Arranca oculto; newsletter.js consulta /status y muestra una de dos:
 * - .oec-nl-offer: no está suscripto → invitación (+50 créditos).
 * - .oec-nl-weekly: suscripto pero sin reclamar los créditos del último
 *   newsletter → "¿Recibiste tus 20 créditos?" + reenviar.
 * $list: la general (home) o la de una landing ("G-SE - Nutrición
 * Deportiva"): el estado, la suscripción y el reenvío van contra esa lista.
 */
function oec_nl_render_offer( string $list = OEC_NL_GENERAL_LIST ): string {
	if ( ! oec_nl_api_key() ) {
		return '';
	}
	oec_nl_enqueue_js();
	$general = OEC_NL_GENERAL_LIST === $list;
	$label   = oec_nl_list_label( $list );
	// "Ver el último enviado" solo si la página puede mostrarlo (lista activa).
	$latest  = isset( oec_nl_all_lists()[ $list ] ) ? oec_nl_latest_url( $list ) : '';
	ob_start();
	?>
	<div class="oec-nl-hero" data-list="<?php echo esc_attr( $list ); ?>" <?php echo oec_nl_endpoints_attrs(); // phpcs:ignore ?>>
	<?php // Cada bloque es un .oec-nl-reveal: newsletter.js lo despliega con slide-down + fade. ?>
	<div class="oec-nl-checking oec-nl-reveal" hidden><div class="oec-nl-reveal__inner">
		<?php // <p> para heredar el color de texto del widget (el hero oscuro lo aclara). ?>
		<p class="oec-nl-checking__row" role="status">
			<span class="oec-nl-dots" aria-hidden="true"><i></i><i></i><i></i></span>
			<span><?php esc_html_e( 'Buscando novedades para vos…', 'oec-theme' ); ?></span>
		</p>
	</div></div>
	<div class="oec-nl-weekly oec-nl-reveal" hidden><div class="oec-nl-reveal__inner">
		<span class="oec-credits-widget__badge oec-nl-offer__badge"><i class="bi bi-calendar-check" aria-hidden="true"></i> <?php printf( esc_html__( '+%d créditos esta semana', 'oec-theme' ), (int) OEC_NL_WEEKLY_CREDITS ); ?></span>
		<h3><?php printf( esc_html__( '¿Recibiste tus %d créditos de esta semana?', 'oec-theme' ), (int) OEC_NL_WEEKLY_CREDITS ); ?></h3>
		<p>
			<?php esc_html_e( 'Vienen en el newsletter del', 'oec-theme' ); ?> <span class="oec-nl-weekly__date"></span>.
			<?php esc_html_e( 'Si no lo encontrás, te lo reenviamos y los reclamás desde el botón "Obtener mis créditos".', 'oec-theme' ); ?>
			<?php if ( $latest ) : ?><a href="<?php echo esc_url( $latest ); ?>" target="_blank" rel="noopener" class="oec-nl-offer__latest"><?php esc_html_e( 'Ver el newsletter', 'oec-theme' ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
		</p>
		<button type="button" class="oec-credits-widget__btn oec-credits-widget__btn--primary oec-nl-offer__submit oec-nl-weekly__resend"><?php esc_html_e( 'Reenviámelo', 'oec-theme' ); ?> <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i></button>
		<p class="oec-nl-offer__msg" role="status" aria-live="polite" hidden></p>
	</div></div>
	<div class="oec-nl-offer oec-nl-reveal" hidden><div class="oec-nl-reveal__inner">
		<span class="oec-credits-widget__badge oec-nl-offer__badge"><i class="bi bi-envelope-heart" aria-hidden="true"></i> <?php printf( esc_html__( '+%d créditos', 'oec-theme' ), (int) OEC_NL_SUBSCRIBE_CREDITS ); ?></span>
		<?php if ( $general ) : ?>
		<h3><?php esc_html_e( 'Sumate al newsletter de G-SE', 'oec-theme' ); ?></h3>
		<p>
			<?php printf( esc_html__( 'Un solo correo por semana, todos los %s, con artículos y formaciones seleccionadas. Suscribite y sumá %d créditos más.', 'oec-theme' ), esc_html( oec_nl_weekday_plural() ), (int) OEC_NL_SUBSCRIBE_CREDITS ); ?>
		<?php else : ?>
		<h3><?php printf( esc_html__( 'Sumate al newsletter de %s', 'oec-theme' ), esc_html( $label ) ); ?></h3>
		<p>
			<?php printf( esc_html__( 'Un solo correo por semana, todos los %1$s, con lo nuevo en %2$s: artículos y formaciones seleccionadas. Suscribite y sumá %3$d créditos más.', 'oec-theme' ), esc_html( oec_nl_weekday_plural() ), esc_html( $label ), (int) OEC_NL_SUBSCRIBE_CREDITS ); ?>
		<?php endif; ?>
			<?php if ( $latest ) : ?><a href="<?php echo esc_url( $latest ); ?>" target="_blank" rel="noopener" class="oec-nl-offer__latest"><?php esc_html_e( 'Ver el último enviado', 'oec-theme' ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
		</p>
		<form class="oec-nl-offer__form" novalidate>
			<div class="oec-credits-widget__input-group oec-nl-offer__names">
				<input type="text" name="first_name" placeholder="<?php esc_attr_e( 'Nombres', 'oec-theme' ); ?>" aria-label="<?php esc_attr_e( 'Nombres', 'oec-theme' ); ?>" autocomplete="given-name" required>
				<input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Apellidos', 'oec-theme' ); ?>" aria-label="<?php esc_attr_e( 'Apellidos', 'oec-theme' ); ?>" autocomplete="family-name" required>
			</div>
			<input type="text" name="website" class="oec-nl__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
			<button type="submit" class="oec-credits-widget__btn oec-credits-widget__btn--primary oec-nl-offer__submit"><?php printf( esc_html__( 'Quiero mis %d créditos', 'oec-theme' ), (int) OEC_NL_SUBSCRIBE_CREDITS ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
			<p class="oec-nl-offer__legal"><?php esc_html_e( 'Te enviaremos un email para confirmar. Podés darte de baja cuando quieras.', 'oec-theme' ); ?></p>
		</form>
		<p class="oec-nl-offer__msg" role="status" aria-live="polite" hidden></p>
	</div></div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * [oec_newsletter title="" text="" button="" topics="yes"]
 * Formulario autónomo: nombres, apellidos, email y (si topics="yes")
 * las temáticas activas además del newsletter general.
 */
function oec_nl_render_form( $atts = [] ): string {
	$atts = shortcode_atts( [
		'title'  => __( 'Suscribite al newsletter', 'oec-theme' ),
		'text'   => sprintf( __( 'Un correo por semana, todos los %s, con artículos y formaciones. Al confirmar sumás %d créditos.', 'oec-theme' ), oec_nl_weekday_plural(), OEC_NL_SUBSCRIBE_CREDITS ),
		'button' => __( 'Suscribirme', 'oec-theme' ),
		'topics' => 'yes',
	], (array) $atts, 'oec_newsletter' );

	if ( ! oec_nl_api_key() ) {
		return current_user_can( 'manage_options' )
			? '<p class="oec-nl-notice">' . esc_html__( 'Newsletter: falta la API key de Elastic Email (Apariencia → Newsletter).', 'oec-theme' ) . '</p>'
			: '';
	}

	oec_nl_enqueue_js();
	$lists  = oec_nl_all_lists();
	$topics = 'yes' === $atts['topics'] && count( $lists ) > 1;
	$uid    = wp_unique_id( 'oec-nl-' );

	ob_start();
	?>
	<form class="oec-nl" <?php echo oec_nl_endpoints_attrs(); // phpcs:ignore ?> novalidate>
		<?php if ( $atts['title'] ) : ?><h3 class="oec-nl__title"><?php echo esc_html( $atts['title'] ); ?></h3><?php endif; ?>
		<?php if ( $atts['text'] ) : ?>
		<p class="oec-nl__text"><?php echo esc_html( $atts['text'] ); ?> <a href="<?php echo esc_url( oec_nl_latest_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver el último enviado', 'oec-theme' ); ?></a></p>
		<?php endif; ?>

		<?php if ( $topics ) : ?>
		<fieldset class="oec-nl__topics">
			<legend><?php esc_html_e( 'Newsletters', 'oec-theme' ); ?></legend>
			<?php foreach ( $lists as $name => $list ) : ?>
			<label class="oec-nl__topic">
				<input type="checkbox" name="lists[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $list['general'] ); ?>>
				<span><?php echo esc_html( $list['label'] ); ?></span>
			</label>
			<?php endforeach; ?>
		</fieldset>
		<?php else : ?>
		<input type="hidden" name="lists[]" value="<?php echo esc_attr( OEC_NL_GENERAL_LIST ); ?>">
		<?php endif; ?>

		<div class="oec-nl__fields">
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-fn"><?php esc_html_e( 'Nombres', 'oec-theme' ); ?></label>
			<input class="oec-nl__input" id="<?php echo esc_attr( $uid ); ?>-fn" type="text" name="first_name" autocomplete="given-name" placeholder="<?php esc_attr_e( 'Nombres', 'oec-theme' ); ?>" required>
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-ln"><?php esc_html_e( 'Apellidos', 'oec-theme' ); ?></label>
			<input class="oec-nl__input" id="<?php echo esc_attr( $uid ); ?>-ln" type="text" name="last_name" autocomplete="family-name" placeholder="<?php esc_attr_e( 'Apellidos', 'oec-theme' ); ?>" required>
		</div>
		<div class="oec-nl__fields">
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-em"><?php esc_html_e( 'Email', 'oec-theme' ); ?></label>
			<input class="oec-nl__input" id="<?php echo esc_attr( $uid ); ?>-em" type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'tu@email.com', 'oec-theme' ); ?>" required>
			<button type="submit" class="btn btn-primary oec-nl__submit"><?php echo esc_html( $atts['button'] ); ?></button>
		</div>
		<input type="text" name="website" class="oec-nl__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
		<p class="oec-nl__legal"><?php esc_html_e( 'Te enviaremos un email para confirmar. Podés darte de baja cuando quieras.', 'oec-theme' ); ?>
			<?php if ( get_privacy_policy_url() ) : ?><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Política de privacidad', 'oec-theme' ); ?></a><?php endif; ?>
		</p>
		<p class="oec-nl__msg" role="status" aria-live="polite" hidden></p>
	</form>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'oec_newsletter', 'oec_nl_render_form' );

class OEC_Newsletter_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct( 'oec_newsletter', __( 'OEC · Newsletter', 'oec-theme' ), [
			'description' => __( 'Formulario de suscripción al newsletter (Elastic Email).', 'oec-theme' ),
		] );
	}

	public function widget( $args, $instance ): void {
		$form = oec_nl_render_form( [
			'title'  => $instance['title'] ?? '',
			'topics' => empty( $instance['topics'] ) ? 'no' : 'yes',
		] );
		if ( $form ) {
			echo $args['before_widget'] . $form . $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function form( $instance ): string {
		$instance = wp_parse_args( (array) $instance, [ 'title' => __( 'Suscribite al newsletter', 'oec-theme' ), 'topics' => 1 ] );
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s"></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Título', 'oec-theme' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $instance['title'] )
		);
		printf(
			'<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>',
			esc_attr( $this->get_field_name( 'topics' ) ),
			checked( ! empty( $instance['topics'] ), true, false ),
			esc_html__( 'Mostrar temáticas', 'oec-theme' )
		);
		return 'form';
	}

	public function update( $new_instance, $old_instance ): array {
		return [
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'topics' => empty( $new_instance['topics'] ) ? 0 : 1,
		];
	}
}
add_action( 'widgets_init', fn() => register_widget( 'OEC_Newsletter_Widget' ) );

/* ============================================================
   PÁGINAS: /newsletter-confirmado/ y /otorgar-creditos/
   ============================================================ */
function oec_nl_create_pages(): void {
	// Los links de los emails apuntan siempre al sitio de configuración.
	if ( ! oec_is_config_site() ) {
		return;
	}
	$pages = [
		'newsletter-confirmado' => [ 'Newsletter confirmado', 'page-newsletter-confirmado.php' ],
		'otorgar-creditos'      => [ 'Créditos semanales', 'page-otorgar-creditos.php' ],
	];
	foreach ( $pages as $slug => [ $title, $template ] ) {
		if ( get_page_by_path( $slug ) ) {
			continue;
		}
		$id = wp_insert_post( [
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_author' => 1,
		] );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}
	}
}
add_action( 'after_switch_theme', 'oec_nl_create_pages' );
add_action( 'admin_init', function () {
	if ( get_transient( 'oec_nl_pages_created' ) ) {
		return;
	}
	oec_nl_create_pages();
	set_transient( 'oec_nl_pages_created', true, YEAR_IN_SECONDS );
} );

// Ninguna de las dos páginas tiene sentido en buscadores.
add_action( 'wp_head', function () {
	if ( is_page_template( [ 'page-newsletter-confirmado.php', 'page-otorgar-creditos.php' ] ) ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}, 1 );

/**
 * Markup compartido de las dos páginas: estado cargando / resultado.
 * newsletter.js lee los parámetros de la URL y postea a $endpoint.
 */
function oec_nl_render_landing( string $mode ): void {
	oec_nl_enqueue_js();
	$endpoint = rest_url( 'confirm' === $mode ? 'oec/v1/newsletter/confirm' : 'oec/v1/newsletter/claim' );
	?>
	<div class="oec-nl-landing" data-oec-nl-landing="<?php echo esc_attr( $mode ); ?>" data-endpoint="<?php echo esc_url( $endpoint ); ?>">
		<div class="oec-nl-landing__state" data-state="loading">
			<i class="bi bi-arrow-repeat oec-credits-widget__spinner" aria-hidden="true"></i>
			<p><?php echo esc_html( 'confirm' === $mode ? __( 'Confirmando tu suscripción…', 'oec-theme' ) : __( 'Acreditando tus créditos…', 'oec-theme' ) ); ?></p>
		</div>
		<div class="oec-nl-landing__state" data-state="done" hidden>
			<span class="oec-nl-landing__icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></span>
			<h1 class="oec-nl-landing__title"></h1>
			<div class="oec-nl-landing__balance" hidden><span class="oec-nl-landing__balance-num">0</span> <?php esc_html_e( 'créditos', 'oec-theme' ); ?></div>
			<p class="oec-nl-landing__note"><?php esc_html_e( 'Canjealos por un descuento en tu próxima formación.', 'oec-theme' ); ?></p>
			<div class="oec-nl-landing__actions">
				<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/formaciones' ) ); ?>"><?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?></a>
				<a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/creditos-por-descuentos' ) ); ?>"><?php esc_html_e( 'Cómo usar mis créditos', 'oec-theme' ); ?></a>
			</div>
		</div>
		<div class="oec-nl-landing__state" data-state="error" hidden>
			<span class="oec-nl-landing__icon oec-nl-landing__icon--error"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i></span>
			<h1 class="oec-nl-landing__title"><?php esc_html_e( 'Algo no salió bien', 'oec-theme' ); ?></h1>
			<p class="oec-nl-landing__error"></p>
			<div class="oec-nl-landing__actions">
				<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Ir al inicio', 'oec-theme' ); ?></a>
			</div>
		</div>
	</div>
	<?php
}

/* ============================================================
   "ÚLTIMO ENVIADO": /?oec_newsletter=ultimo[&lista=…]
   ============================================================ */
add_action( 'template_redirect', function () {
	if ( 'ultimo' !== ( $_GET['oec_newsletter'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$list   = sanitize_text_field( wp_unslash( $_GET['lista'] ?? OEC_NL_GENERAL_LIST ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$latest = (array) oec_config_get_option( OEC_NL_LATEST, [] );
	$html   = $latest[ $list ]['html'] ?? '';

	if ( ! $html ) {
		// Todavía no se envió ninguno: mostramos cómo sería el de esta semana.
		$lists = oec_nl_all_lists();
		if ( ! isset( $lists[ $list ] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$html = oec_nl_build_email( $lists[ $list ], time(), 0 );
		if ( is_wp_error( $html ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo oec_nl_public_html( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
} );

/**
 * Arreglos para newsletters ya guardados (último enviado / reenvíos): el
 * HTML se guarda tal como salió, así que puede apuntar al logo viejo.
 */
function oec_nl_fix_saved_html( string $html ): string {
	return str_replace( OEC_NL_LOGO_LEGACY, OEC_NL_LOGO, $html );
}

/** Neutraliza los merge tags de Elastic Email para mostrar el HTML en la web. */
function oec_nl_public_html( string $html ): string {
	// strtr prueba primero las claves más largas: los saludos van antes que {firstname}.
	return strtr( oec_nl_fix_saved_html( $html ), [
		'Hola {firstname}, estos'                  => '¡Hola! Estos',
		'{firstname}, nuestros'                    => 'Hola, nuestros', // plantilla anterior a la v1.0.50
		'para {firstname}.'                        => 'para vos.',
		'{firstname}'                              => '',
		'{lastname}'                               => '',
		'{email}'                                  => '',
		'{country}'                                => '',
		'{unsubscribe}'                            => '#',
		'{view}'                                   => '#',
		'{accountaddress}'                         => '',
		'{{ encodeURIComponent(email) }}'          => '',
		'{{ encodeURIComponent(firstname) }}'      => '',
		'{{ encodeURIComponent(lastname) }}'       => '',
	] );
}

/* ============================================================
   NEWSLETTER SEMANAL: contenido
   ============================================================ */

/** @return WP_Post[] */
function oec_nl_collect_posts( array $list, int $since ): array {
	$args = [
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => OEC_NL_MAX_POSTS,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
	];
	if ( $since ) {
		$args['date_query'] = [ [ 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $since ) ] ];
	}
	if ( $list['cats'] ) {
		$args['category__in'] = $list['cats'];
	}
	return get_posts( $args );
}

/** Próximas formaciones desde la API OAS (token de Ajustes → Integraciones). */
function oec_nl_collect_trainings( array $list ): array {
	$token = trim( oec_config_theme_options()['oec_api_token'] ?? '' );
	if ( ! $token ) {
		oec_nl_log( 'error', 'Sin token de la API OAS (Ajustes → Integraciones): el newsletter va sin formaciones.' );
		return [];
	}
	$url = OEC_NL_OAS_API . '?pagination=' . OEC_NL_MAX_TRAININGS;
	if ( $list['subject'] ) {
		$url .= '&subject-id=' . rawurlencode( $list['subject'] );
	}
	$res = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => [ 'X-API-TOKEN' => $token ] ] );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		oec_nl_log( 'error', 'API OAS: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
		return [];
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return array_slice( (array) ( $data['data'] ?? [] ), 0, OEC_NL_MAX_TRAININGS );
}

function oec_nl_subject( array $list ): string {
	$date = wp_date( 'l j \d\e F' );
	return $list['general']
		? sprintf( 'Novedades de esta semana: %s', $date )
		: sprintf( 'Novedades en %s: %s', $list['label'], $date );
}

/** Link de formación con el redirector de OEC (merge tags de Elastic Email, sin codificar). */
function oec_nl_training_link( array $training ): string {
	$target = oec_config_url( '/formacion/' . ( $training['slug'] ?? '' ) )
		. '?utm_source=mailing&utm_medium=elastic&utm_campaign=newsletter+semanal&utm_content=' . wp_date( 'Y-m-d' );
	return OEC_NL_REDIRECTOR
		. '?c_mail={{ encodeURIComponent(email) }}&c_fn={{ encodeURIComponent(firstname) }}&c_ln={{ encodeURIComponent(lastname) }}&c_country={country}&l='
		. rawurlencode( $target );
}

function oec_nl_post_link( WP_Post $post ): string {
	return add_query_arg( [
		'utm_source'   => 'mailing',
		'utm_medium'   => 'elastic',
		'utm_campaign' => 'newsletter+semanal',
		'utm_content'  => wp_date( 'Y-m-d' ),
	], get_permalink( $post ) );
}

function oec_nl_truncate( string $text, int $limit ): string {
	$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
	return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit ) ) . '…' : $text;
}

/**
 * Arma el HTML completo del newsletter de una lista.
 *
 * @param int $sent_at Fecha de envío (firma el link de créditos semanales).
 * @param int $since   Solo artículos posteriores (0 = los últimos).
 * @return string|WP_Error
 */
function oec_nl_build_email( array $list, int $sent_at, int $since ) {
	$posts     = oec_nl_collect_posts( $list, $since );
	$trainings = oec_nl_collect_trainings( $list );
	if ( ! $posts && ! $trainings ) {
		return new WP_Error( 'oec_nl_empty', 'Sin artículos nuevos ni formaciones.' );
	}

	$claim = oec_config_url( '/otorgar-creditos/' ) . '?t=' . $sent_at . '&s=' . oec_nl_claim_sig( $sent_at ) . '&email={email}';

	return oec_nl_render_email( [
		'date'    => wp_date( 'l j \d\e F', $sent_at ),
		'topic'   => $list['general'] ? '' : $list['label'],
		'content' => oec_nl_mix_html( $posts, $trainings ),
		'claim'   => $claim,
	] );
}

/* ============================================================
   NEWSLETTER SEMANAL: envío
   ============================================================ */

/**
 * Crea template + campaña en Elastic Email para una lista (mismo flujo
 * que el script original) y guarda el HTML como "último enviado".
 *
 * @return string|WP_Error 'sent' | 'draft' | 'empty'
 */
function oec_nl_send_digest( string $list_name ) {
	$lists = oec_nl_all_lists();
	$list  = $lists[ $list_name ] ?? null;
	if ( ! $list ) {
		return new WP_Error( 'oec_nl_no_list', 'Lista inexistente o inactiva.' );
	}

	$state   = oec_nl_state();
	$sent_at = time();
	$since   = (int) ( $state['last_sent'][ $list_name ] ?? 0 ) ?: $sent_at - WEEK_IN_SECONDS;

	// Sin artículos nuevos no se envía (las formaciones solas no justifican el correo).
	if ( ! oec_nl_collect_posts( $list, $since ) ) {
		oec_nl_log( 'info', sprintf( '%s: sin artículos nuevos, no se envía.', $list_name ) );
		return 'empty';
	}

	$html = oec_nl_build_email( $list, $sent_at, $since );
	if ( is_wp_error( $html ) ) {
		oec_nl_log( 'error', sprintf( '%s: %s', $list_name, $html->get_error_message() ) );
		return $html;
	}

	$subject  = oec_nl_subject( $list );
	$template = sprintf( 'Newsletter %s %s %d', $list_name, wp_date( 'Y-m-d' ), $sent_at );

	$tpl = oec_nl_api( 'POST', '/templates', [
		'Name'          => $template,
		'Subject'       => $subject,
		'Body'          => [ [ 'ContentType' => 'HTML', 'Content' => $html, 'Charset' => 'utf-8' ] ],
		'TemplateScope' => 'Global',
	] );
	if ( is_wp_error( $tpl ) ) {
		oec_nl_log( 'error', sprintf( '%s (template): %s', $list_name, $tpl->get_error_message() ) );
		return $tpl;
	}

	$status = 'draft' === oec_nl_opts()['send_mode'] ? 'Draft' : 'Active';
	$camp   = oec_nl_api( 'POST', '/campaigns', [
		'Name'       => $template,
		'Status'     => $status,
		'Content'    => [ [
			'From'         => OEC_NL_FROM,
			'ReplyTo'      => OEC_NL_FROM,
			'Subject'      => $subject,
			'TemplateName' => $tpl['Name'] ?? $template,
			'Utm'          => [ 'Source' => 'newsletter', 'Medium' => 'email', 'Campaign' => 'newsletter+semanal' ],
		] ],
		'Recipients' => [ 'ListNames' => [ $list_name ] ],
		'Options'    => [
			'DeliveryOptimization' => 'ToEngagedFirst',
			'TrackOpens'           => true,
			'TrackClicks'          => true,
		],
	] );
	if ( is_wp_error( $camp ) ) {
		oec_nl_log( 'error', sprintf( '%s (campaña): %s', $list_name, $camp->get_error_message() ) );
		return $camp;
	}

	$state                            = oec_nl_state();
	$state['last_sent'][ $list_name ] = $sent_at;
	oec_nl_save_state( $state );

	$latest               = (array) oec_config_get_option( OEC_NL_LATEST, [] );
	$latest[ $list_name ] = [ 'html' => $html, 'time' => $sent_at, 'subject' => $subject ];
	oec_config_update_option( OEC_NL_LATEST, $latest, false );

	oec_nl_log( 'success', sprintf( '%s: %s.', $list_name, 'Draft' === $status ? 'borrador creado' : 'campaña enviada' ) );
	return 'Draft' === $status ? 'draft' : 'sent';
}

/** Envío de prueba del newsletter de una lista a un solo email. */
function oec_nl_send_test( string $list_name, string $to ) {
	$lists = oec_nl_all_lists();
	if ( ! isset( $lists[ $list_name ] ) ) {
		return new WP_Error( 'oec_nl_no_list', 'Lista inexistente o inactiva.' );
	}
	$html = oec_nl_build_email( $lists[ $list_name ], time(), 0 );
	if ( is_wp_error( $html ) ) {
		return $html;
	}
	return oec_nl_api( 'POST', '/emails/transactional', [
		'Recipients' => [ 'To' => [ $to ] ],
		'Content'    => [
			'From'    => OEC_NL_FROM,
			'Subject' => '[PRUEBA] ' . oec_nl_subject( $lists[ $list_name ] ),
			'Body'    => [ [ 'ContentType' => 'HTML', 'Content' => $html, 'Charset' => 'utf-8' ] ],
		],
	] );
}

/* ============================================================
   PROGRAMACIÓN (WP-Cron cada hora)
   ============================================================ */
function oec_nl_compute_next_run( int $from ): int {
	$opts    = oec_nl_opts();
	$now     = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() );
	$weekday = min( 7, max( 1, (int) $opts['weekday'] ) );
	$hour    = min( 23, max( 0, (int) $opts['hour'] ) );
	$diff    = ( $weekday - (int) $now->format( 'N' ) + 7 ) % 7;
	$slot    = $now->modify( "+{$diff} days" )->setTime( $hour, 0 );
	if ( $slot <= $now ) {
		$slot = $slot->modify( '+7 days' );
	}
	return $slot->getTimestamp();
}

function oec_nl_reschedule(): void {
	$state             = oec_nl_state();
	$state['next_run'] = oec_nl_opts()['digest_enabled'] ? oec_nl_compute_next_run( time() ) : 0;
	oec_nl_save_state( $state );
}

// El envío corre solo en el sitio de configuración (/es/): sus artículos
// son los del newsletter y ahí vive el estado. En el resto de la red se
// limpia el evento por si quedó programado.
add_action( 'init', function () {
	if ( ! oec_is_config_site() ) {
		if ( wp_next_scheduled( OEC_NL_CRON ) ) {
			wp_clear_scheduled_hook( OEC_NL_CRON );
		}
		return;
	}
	if ( ! wp_next_scheduled( OEC_NL_CRON ) ) {
		wp_schedule_event( time() + 300, 'hourly', OEC_NL_CRON );
	}
} );

add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( OEC_NL_CRON );
} );

add_action( OEC_NL_CRON, function () {
	if ( ! oec_is_config_site() ) {
		return;
	}
	$state = oec_nl_state();
	if ( ! oec_nl_opts()['digest_enabled'] || ! $state['next_run'] || time() < $state['next_run'] ) {
		return;
	}
	// Lock para que dos requests de cron simultáneos no dupliquen el envío.
	if ( get_transient( 'oec_nl_cron_lock' ) ) {
		return;
	}
	set_transient( 'oec_nl_cron_lock', 1, 15 * MINUTE_IN_SECONDS );

	// Reprogramamos antes de enviar: si algo falla no reintenta cada hora.
	oec_nl_reschedule();
	oec_nl_ensure_especiales_lists(); // landings nuevas: su lista entra en este envío
	foreach ( array_keys( oec_nl_all_lists() ) as $list_name ) {
		oec_nl_send_digest( $list_name );
	}
	delete_transient( 'oec_nl_cron_lock' );
} );

/* ============================================================
   ADMIN: Apariencia → Newsletter
   ============================================================ */
// La configuración vive en un solo sitio de la red (/es/).
add_action( 'admin_menu', function () {
	if ( ! oec_is_config_site() ) {
		return;
	}
	add_theme_page(
		__( 'Newsletter — Elastic Email', 'oec-theme' ),
		__( 'Newsletter', 'oec-theme' ),
		'manage_options',
		'oec-newsletter',
		'oec_nl_render_admin'
	);
} );

function oec_nl_redirect_notice( string $type, string $message ): void {
	set_transient( 'oec_nl_notice_' . get_current_user_id(), [ 'type' => $type, 'msg' => $message ], 60 );
	wp_safe_redirect( add_query_arg( 'page', 'oec-newsletter', admin_url( 'themes.php' ) ) );
	exit;
}

add_action( 'admin_post_oec_nl_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! oec_is_config_site() ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_nl_save' );

	$in   = wp_unslash( $_POST );
	$opts = oec_nl_opts();

	$new_key = trim( sanitize_text_field( $in['api_key'] ?? '' ) );
	if ( '' !== $new_key ) {
		$opts['api_key'] = $new_key;
	} elseif ( ! empty( $in['api_key_clear'] ) ) {
		$opts['api_key'] = '';
	}

	foreach ( (array) ( $in['lists'] ?? [] ) as $row ) {
		$name = sanitize_text_field( $row['name'] ?? '' );
		if ( ! isset( $opts['lists'][ $name ] ) ) {
			continue;
		}
		if ( ! empty( $row['remove'] ) && empty( $opts['lists'][ $name ]['especial'] ) ) {
			unset( $opts['lists'][ $name ] );
			continue;
		}
		$opts['lists'][ $name ] = [
			'label'    => sanitize_text_field( $row['label'] ?? $name ) ?: $name,
			'enabled'  => ! empty( $row['enabled'] ),
			'cats'     => array_values( array_filter( array_map( 'intval', (array) ( $row['cats'] ?? [] ) ) ) ),
			'subject'  => sanitize_title( $row['subject'] ?? '' ),
			'especial' => $opts['lists'][ $name ]['especial'] ?? '', // registrada por una landing
		];
	}

	// "Agregar temática": tiene que ser una lista que exista en la cuenta.
	$add_error = '';
	$add_name  = trim( sanitize_text_field( $in['add_list'] ?? '' ) );
	if ( '' !== $add_name ) {
		$match = '';
		foreach ( oec_nl_remote_list_names() as $remote_name ) {
			if ( 0 === strcasecmp( $remote_name, $add_name ) ) {
				$match = $remote_name;
				break;
			}
		}
		if ( $match ) {
			$opts['lists'][ $match ] = ( $opts['lists'][ $match ] ?? [] ) + [
				'label'    => $match,
				'cats'     => [],
				'subject'  => '',
				'especial' => '',
			];
			$opts['lists'][ $match ]['enabled'] = true;
			ksort( $opts['lists'] );
		} else {
			/* translators: %s: nombre de lista */
			$add_error = sprintf( __( 'No hay ninguna lista "%s" en Elastic Email. Si la acabás de crear, tocá "Guardar y sincronizar listas".', 'oec-theme' ), $add_name );
		}
	}
	$opts['lists'] = oec_nl_prune_lists( $opts['lists'] );

	$opts['digest_enabled'] = ! empty( $in['digest_enabled'] );
	$opts['weekday']        = min( 7, max( 1, (int) ( $in['weekday'] ?? 1 ) ) );
	$opts['hour']           = min( 23, max( 0, (int) ( $in['hour'] ?? 9 ) ) );
	$opts['send_mode']      = 'draft' === ( $in['send_mode'] ?? '' ) ? 'draft' : 'active';

	oec_config_update_option( OEC_NL_OPTION, $opts );
	oec_nl_reschedule();

	$do = sanitize_key( $in['oec_nl_do'] ?? 'save' );
	if ( 'test' === $do ) {
		$res = oec_nl_api( 'GET', '/lists' );
		if ( is_wp_error( $res ) ) {
			oec_nl_redirect_notice( 'error', $res->get_error_message() );
		}
		oec_nl_redirect_notice( 'success', sprintf( __( 'Conexión correcta. La cuenta tiene %d listas.', 'oec-theme' ), count( $res ) ) );
	}
	if ( 'sync' === $do ) {
		$res = oec_nl_sync_lists();
		if ( is_wp_error( $res ) ) {
			oec_nl_redirect_notice( 'error', $res->get_error_message() );
		}
		oec_nl_ensure_especiales_lists();
		oec_nl_redirect_notice( 'success', sprintf( __( 'Listas sincronizadas: %d.', 'oec-theme' ), $res ) );
	}
	if ( $add_error ) {
		oec_nl_redirect_notice( 'error', $add_error );
	}
	oec_nl_redirect_notice( 'success', $add_name ? sprintf( __( 'Temática agregada: %s.', 'oec-theme' ), $add_name ) : __( 'Configuración guardada.', 'oec-theme' ) );
} );

add_action( 'admin_post_oec_nl_action', function () {
	if ( ! current_user_can( 'manage_options' ) || ! oec_is_config_site() ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_nl_action' );

	$in   = wp_unslash( $_POST );
	$list = sanitize_text_field( $in['list'] ?? '' );
	$do   = sanitize_key( $in['oec_nl_do'] ?? '' );

	if ( 'test' === $do ) {
		$to = sanitize_email( $in['test_email'] ?? '' );
		if ( ! is_email( $to ) ) {
			oec_nl_redirect_notice( 'error', __( 'Email de prueba inválido.', 'oec-theme' ) );
		}
		$res = oec_nl_send_test( $list, $to );
		if ( is_wp_error( $res ) ) {
			oec_nl_redirect_notice( 'error', $res->get_error_message() );
		}
		oec_nl_redirect_notice( 'success', sprintf( __( 'Prueba enviada a %s.', 'oec-theme' ), $to ) );
	}

	if ( 'run' === $do ) {
		$names   = 'all' === $list ? array_keys( oec_nl_all_lists() ) : [ $list ];
		$summary = [];
		foreach ( $names as $name ) {
			$res       = oec_nl_send_digest( $name );
			$summary[] = $name . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : $res );
		}
		oec_nl_redirect_notice( 'info', implode( ' · ', $summary ) );
	}

	oec_nl_redirect_notice( 'error', __( 'Acción desconocida.', 'oec-theme' ) );
} );

add_action( 'admin_post_oec_nl_preview', function () {
	if ( ! current_user_can( 'manage_options' ) || ! oec_is_config_site() ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_nl_preview' );

	$lists = oec_nl_all_lists();
	$name  = sanitize_text_field( wp_unslash( $_GET['list'] ?? '' ) );
	if ( ! isset( $lists[ $name ] ) ) {
		wp_die( esc_html__( 'Lista inexistente o inactiva.', 'oec-theme' ) );
	}
	$since = (int) ( oec_nl_state()['last_sent'][ $name ] ?? 0 );
	$html  = oec_nl_build_email( $lists[ $name ], time(), $since );
	if ( is_wp_error( $html ) ) {
		$html = oec_nl_build_email( $lists[ $name ], time(), 0 );
	}
	if ( is_wp_error( $html ) ) {
		wp_die( esc_html( $html->get_error_message() ) );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo oec_nl_public_html( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
} );

function oec_nl_render_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$opts = oec_nl_opts();

	// Configs anteriores guardaban TODAS las listas de la cuenta: pasamos
	// los nombres al buscador y dejamos solo las configuradas.
	$pruned = oec_nl_prune_lists( $opts['lists'] );
	if ( count( $pruned ) !== count( $opts['lists'] ) ) {
		if ( ! oec_nl_remote_list_names() ) {
			$names = array_keys( $opts['lists'] );
			natcasesort( $names );
			oec_config_update_option( OEC_NL_REMOTE_LISTS, array_values( $names ), false );
		}
		$opts['lists'] = $pruned;
		oec_config_update_option( OEC_NL_OPTION, $opts );
	}

	$remote_names = oec_nl_remote_list_names();
	$state        = oec_nl_state();
	$all          = oec_nl_all_lists();
	$latest     = (array) oec_config_get_option( OEC_NL_LATEST, [] );
	$categories = get_categories( [ 'hide_empty' => false ] );
	$has_oas    = (bool) trim( oec_config_theme_options()['oec_api_token'] ?? '' );
	$notice     = get_transient( 'oec_nl_notice_' . get_current_user_id() );
	delete_transient( 'oec_nl_notice_' . get_current_user_id() );

	$weekdays = [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];

	if ( function_exists( 'oec_page_inline_styles' ) ) {
		oec_page_inline_styles();
	}
	?>
	<style>
	.oec-nl-admin .oec-card { margin-bottom: 1.5rem; }
	.oec-nl-admin .form-table th { width: 200px; }
	.oec-nl-lists { width: 100%; border-collapse: collapse; }
	.oec-nl-lists th, .oec-nl-lists td { text-align: left; padding: .75rem .5rem; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
	.oec-nl-lists tr.is-general td { background: #f0f6fc; }
	.oec-nl-tag { display: inline-block; margin-top: .25rem; padding: 1px 6px; border-radius: 4px; background: #f0f6fc; color: #194872; font-size: 11px; }
	.oec-nl-add { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: 1.25rem 0 .5rem; }
	.oec-nl-cats { max-height: 140px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 6px; padding: .5rem .75rem; background: #fff; }
	.oec-nl-cats label { display: block; margin: .15rem 0; }
	.oec-nl-log { font-family: Menlo, monospace; font-size: 12px; max-height: 280px; overflow-y: auto; margin: 0; }
	.oec-nl-log li { margin: 0; padding: .3rem 0; border-bottom: 1px solid #f0f0f1; }
	.oec-nl-log .is-error { color: #b32d2e; }
	.oec-nl-log .is-success { color: #007017; }
	.oec-nl-actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin: 0 0 1rem; }
	.oec-nl-facts { margin: 0; display: grid; grid-template-columns: 200px 1fr; gap: .4rem 1rem; }
	.oec-nl-facts dt { font-weight: 600; }
	.oec-nl-facts dd { margin: 0; }
	</style>
	<div class="wrap oec-settings-wrap oec-nl-admin">

		<div class="oec-page-header">
			<div class="oec-page-header__left">
				<span class="oec-page-header__logo">OEC<span>.</span></span>
				<div>
					<h1><?php esc_html_e( 'Newsletter', 'oec-theme' ); ?></h1>
					<p><?php esc_html_e( 'Suscripciones con créditos y newsletter semanal con Elastic Email.', 'oec-theme' ); ?></p>
				</div>
			</div>
		</div>

		<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oec_nl_save">
			<?php wp_nonce_field( 'oec_nl_save' ); ?>

			<div class="oec-card">
				<div class="oec-card__header">
					<h2><?php esc_html_e( 'Conexión', 'oec-theme' ); ?></h2>
					<p><?php esc_html_e( 'API key de Elastic Email con permisos de Contacts, Lists, Templates, Campaigns y Send.', 'oec-theme' ); ?></p>
				</div>
				<div class="oec-card__body">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="oec-nl-key"><?php esc_html_e( 'API key', 'oec-theme' ); ?></label></th>
							<td>
								<input id="oec-nl-key" type="password" name="api_key" class="regular-text" autocomplete="off"
									placeholder="<?php echo $opts['api_key'] ? esc_attr__( '•••••••• guardada — dejá vacío para mantenerla', 'oec-theme' ) : ''; ?>">
								<?php if ( $opts['api_key'] ) : ?>
								<label><input type="checkbox" name="api_key_clear" value="1"> <?php esc_html_e( 'Borrar', 'oec-theme' ); ?></label>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<dl class="oec-nl-facts">
						<dt><?php esc_html_e( 'Lista general', 'oec-theme' ); ?></dt><dd><code><?php echo esc_html( OEC_NL_GENERAL_LIST ); ?></code></dd>
						<dt><?php esc_html_e( 'Remitente', 'oec-theme' ); ?></dt><dd><code><?php echo esc_html( OEC_NL_FROM ); ?></code></dd>
						<dt><?php esc_html_e( 'Créditos', 'oec-theme' ); ?></dt><dd><?php printf( esc_html__( '%1$d por suscribirse · %2$d semanales', 'oec-theme' ), (int) OEC_NL_SUBSCRIBE_CREDITS, (int) OEC_NL_WEEKLY_CREDITS ); ?></dd>
						<dt><?php esc_html_e( 'Formaciones (API OAS)', 'oec-theme' ); ?></dt><dd><?php echo $has_oas ? esc_html__( 'Token configurado', 'oec-theme' ) : '<strong style="color:#b32d2e">' . esc_html__( 'Falta el token en Ajustes → Integraciones', 'oec-theme' ) . '</strong>'; ?></dd>
					</dl>
					<p><button type="submit" name="oec_nl_do" value="test" class="button"><?php esc_html_e( 'Guardar y probar conexión', 'oec-theme' ); ?></button></p>
				</div>
			</div>

			<div class="oec-card">
				<div class="oec-card__header">
					<h2><?php esc_html_e( 'Temáticas', 'oec-theme' ); ?></h2>
					<p><?php esc_html_e( 'Cada lista de Elastic Email activada recibe su propio newsletter: artículos de las categorías elegidas + formaciones del subject-id de OAS (ej. nutricion-deportiva, fuerza). La lista general siempre se envía, con todo el contenido.', 'oec-theme' ); ?></p>
				</div>
				<div class="oec-card__body">
					<?php
					// La general siempre arriba, aunque todavía no se haya sincronizado.
					$rows = [ OEC_NL_GENERAL_LIST => $opts['lists'][ OEC_NL_GENERAL_LIST ] ?? [] ] + $opts['lists'];
					?>
					<table class="oec-nl-lists">
						<thead><tr>
							<th><?php esc_html_e( 'Activa', 'oec-theme' ); ?></th>
							<th><?php esc_html_e( 'Lista', 'oec-theme' ); ?></th>
							<th><?php esc_html_e( 'Nombre público', 'oec-theme' ); ?></th>
							<th><?php esc_html_e( 'Subject-id OAS', 'oec-theme' ); ?></th>
							<th><?php esc_html_e( 'Categorías', 'oec-theme' ); ?></th>
							<th><?php esc_html_e( 'Quitar', 'oec-theme' ); ?></th>
						</tr></thead>
						<tbody>
						<?php
						$i = 0;
						foreach ( $rows as $name => $list ) :
							$field      = 'lists[' . $i++ . ']';
							$is_general = OEC_NL_GENERAL_LIST === $name;
							?>
							<tr class="<?php echo $is_general ? 'is-general' : ''; ?>">
								<?php if ( $is_general ) : ?>
								<td>✔</td>
								<td colspan="5"><strong><?php echo esc_html( $name ); ?></strong> — <?php esc_html_e( 'lista general (fija en el tema)', 'oec-theme' ); ?></td>
								<?php else : ?>
								<td><input type="hidden" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $name ); ?>"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $list['enabled'] ) ); ?>></td>
								<td>
									<strong><?php echo esc_html( $name ); ?></strong>
									<?php if ( ! empty( $list['especial'] ) ) : ?>
									<br><span class="oec-nl-tag"><?php printf( esc_html__( 'Landing: %s', 'oec-theme' ), esc_html( $list['especial'] ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><input type="text" name="<?php echo esc_attr( $field ); ?>[label]" value="<?php echo esc_attr( $list['label'] ?? $name ); ?>"></td>
								<td><input type="text" name="<?php echo esc_attr( $field ); ?>[subject]" value="<?php echo esc_attr( $list['subject'] ?? '' ); ?>" placeholder="fuerza" style="width:160px;"></td>
								<td>
									<div class="oec-nl-cats">
									<?php foreach ( $categories as $cat ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[cats][]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, $list['cats'] ?? [], true ) ); ?>> <?php echo esc_html( $cat->name ); ?> <span style="color:#999">#<?php echo (int) $cat->term_id; ?></span></label>
									<?php endforeach; ?>
									</div>
								</td>
								<td>
									<?php if ( empty( $list['especial'] ) ) : ?>
									<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[remove]" value="1" aria-label="<?php echo esc_attr( sprintf( __( 'Quitar %s', 'oec-theme' ), $name ) ); ?>">
									<?php else : ?>
									<span title="<?php esc_attr_e( 'La administra la landing de temática', 'oec-theme' ); ?>">—</span>
									<?php endif; ?>
								</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $remote_names && ! in_array( OEC_NL_GENERAL_LIST, $remote_names, true ) ) : ?>
					<p style="color:#b32d2e"><?php printf( esc_html__( 'Atención: la lista general "%s" no existe en la cuenta de Elastic Email.', 'oec-theme' ), esc_html( OEC_NL_GENERAL_LIST ) ); ?></p>
					<?php endif; ?>

					<div class="oec-nl-add">
						<label for="oec-nl-add"><strong><?php esc_html_e( 'Agregar temática', 'oec-theme' ); ?></strong></label>
						<?php if ( $remote_names ) : ?>
						<input id="oec-nl-add" type="text" name="add_list" list="oec-nl-remote-lists" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( sprintf( __( 'Buscar entre las %d listas de Elastic Email…', 'oec-theme' ), count( $remote_names ) ) ); ?>">
						<datalist id="oec-nl-remote-lists">
							<?php foreach ( $remote_names as $remote_name ) : ?>
								<?php if ( ! isset( $rows[ $remote_name ] ) ) : ?>
								<option value="<?php echo esc_attr( $remote_name ); ?>"></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</datalist>
						<button type="submit" name="oec_nl_do" value="add" class="button"><?php esc_html_e( 'Agregar', 'oec-theme' ); ?></button>
						<?php else : ?>
						<span class="description"><?php esc_html_e( 'Sincronizá para poder buscar entre las listas de la cuenta.', 'oec-theme' ); ?></span>
						<?php endif; ?>
					</div>
					<p><button type="submit" name="oec_nl_do" value="sync" class="button"><?php esc_html_e( 'Guardar y sincronizar listas', 'oec-theme' ); ?></button> <span class="description"><?php esc_html_e( 'Actualiza el buscador con las listas de la cuenta; la tabla no cambia.', 'oec-theme' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'Formulario independiente:', 'oec-theme' ); ?> <code>[oec_newsletter]</code> · <code>[oec_newsletter topics="no"]</code> · <?php esc_html_e( 'widget “OEC · Newsletter”. La invitación del hero sale sola dentro de [oec-credits-widget].', 'oec-theme' ); ?></p>
				</div>
			</div>

			<div class="oec-card">
				<div class="oec-card__header">
					<h2><?php esc_html_e( 'Envío semanal', 'oec-theme' ); ?></h2>
					<p>
						<?php esc_html_e( 'Artículos publicados desde el envío anterior (máx. 10) intercalados con 7 formaciones. Si una lista no tiene artículos nuevos, esa semana no se envía.', 'oec-theme' ); ?>
						<?php if ( $opts['digest_enabled'] && $state['next_run'] ) : ?>
						<br><strong><?php printf( esc_html__( 'Próximo envío: %s', 'oec-theme' ), esc_html( wp_date( 'l j \d\e F, H:i', $state['next_run'] ) ) ); ?></strong>
						<?php endif; ?>
					</p>
				</div>
				<div class="oec-card__body">
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Activar', 'oec-theme' ); ?></th>
							<td><label><input type="checkbox" name="digest_enabled" value="1" <?php checked( $opts['digest_enabled'] ); ?>> <?php esc_html_e( 'Enviar automáticamente', 'oec-theme' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Día y hora', 'oec-theme' ); ?></th>
							<td>
								<select name="weekday" aria-label="<?php esc_attr_e( 'Día', 'oec-theme' ); ?>">
									<?php foreach ( $weekdays as $n => $label ) : ?>
									<option value="<?php echo (int) $n; ?>" <?php selected( (int) $opts['weekday'], $n ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<label><?php esc_html_e( 'a las', 'oec-theme' ); ?> <input type="number" name="hour" min="0" max="23" value="<?php echo (int) $opts['hour']; ?>" style="width:60px;"> h</label>
								<p class="description"><?php esc_html_e( 'Hora del sitio (Ajustes → Generales). WP-Cron depende de las visitas: para puntualidad, configurá un cron real del servidor.', 'oec-theme' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Modo', 'oec-theme' ); ?></th>
							<td>
								<label><input type="radio" name="send_mode" value="active" <?php checked( $opts['send_mode'], 'active' ); ?>> <?php esc_html_e( 'Enviar directamente', 'oec-theme' ); ?></label><br>
								<label><input type="radio" name="send_mode" value="draft" <?php checked( $opts['send_mode'], 'draft' ); ?>> <?php esc_html_e( 'Crear como borrador en Elastic Email', 'oec-theme' ); ?></label>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<?php submit_button( __( 'Guardar cambios', 'oec-theme' ) ); ?>
		</form>

		<?php if ( oec_nl_api_key() ) : ?>
		<div class="oec-card">
			<div class="oec-card__header">
				<h2><?php esc_html_e( 'Probar y enviar', 'oec-theme' ); ?></h2>
			</div>
			<div class="oec-card__body">
				<p class="oec-nl-actions">
					<strong><?php esc_html_e( 'Vista previa:', 'oec-theme' ); ?></strong>
					<?php foreach ( $all as $name => $list ) : ?>
					<a class="button" target="_blank" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'oec_nl_preview', 'list' => $name ], admin_url( 'admin-post.php' ) ), 'oec_nl_preview' ) ); ?>"><?php echo esc_html( $list['general'] ? $name : $list['label'] ); ?></a>
					<?php endforeach; ?>
				</p>
				<p class="oec-nl-actions">
					<strong><?php esc_html_e( 'Último enviado:', 'oec-theme' ); ?></strong>
					<?php foreach ( $all as $name => $list ) : ?>
						<?php if ( isset( $latest[ $name ] ) ) : ?>
						<a target="_blank" href="<?php echo esc_url( oec_nl_latest_url( $name ) ); ?>"><?php echo esc_html( $name . ' (' . wp_date( 'd/m', $latest[ $name ]['time'] ) . ')' ); ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( ! $latest ) : ?><em><?php esc_html_e( 'ninguno todavía (la web muestra una vista previa en vivo)', 'oec-theme' ); ?></em><?php endif; ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oec-nl-actions">
					<input type="hidden" name="action" value="oec_nl_action">
					<?php wp_nonce_field( 'oec_nl_action' ); ?>
					<select name="list" aria-label="<?php esc_attr_e( 'Lista', 'oec-theme' ); ?>">
						<?php foreach ( $all as $name => $list ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="email" name="test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" aria-label="<?php esc_attr_e( 'Email de prueba', 'oec-theme' ); ?>">
					<button type="submit" name="oec_nl_do" value="test" class="button"><?php esc_html_e( 'Enviar prueba', 'oec-theme' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oec-nl-actions"
					onsubmit="return confirm('<?php echo esc_js( __( '¿Enviar ahora el newsletter a TODOS los suscriptores de todas las listas activas?', 'oec-theme' ) ); ?>');">
					<input type="hidden" name="action" value="oec_nl_action">
					<input type="hidden" name="list" value="all">
					<?php wp_nonce_field( 'oec_nl_action' ); ?>
					<button type="submit" name="oec_nl_do" value="run" class="button button-secondary"><?php esc_html_e( 'Enviar newsletter ahora (todas las listas)', 'oec-theme' ); ?></button>
				</form>
			</div>
		</div>
		<?php endif; ?>

		<div class="oec-card">
			<div class="oec-card__header"><h2><?php esc_html_e( 'Actividad reciente', 'oec-theme' ); ?></h2></div>
			<div class="oec-card__body">
				<?php if ( ! $state['log'] ) : ?>
					<p><?php esc_html_e( 'Sin actividad todavía.', 'oec-theme' ); ?></p>
				<?php else : ?>
				<ul class="oec-nl-log">
					<?php foreach ( $state['log'] as $entry ) : ?>
					<li class="is-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i', $entry['time'] ) . ' — ' . $entry['msg'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}
