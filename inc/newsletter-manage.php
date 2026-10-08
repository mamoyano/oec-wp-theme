<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   NEWSLETTER — "Mis suscripciones" y herramientas del admin
   (lo carga inc/newsletter.php)

   Página /mis-suscripciones/ (sitio de configuración):
   1. La persona escribe su email → /newsletter/manage-link le manda un
      link firmado (vale 48 h, token con p = 'manage', en ?acceso=). Así nadie puede ver
      ni cambiar las suscripciones de otro.
   2. Con el link, /newsletter/prefs devuelve las listas (general +
      temáticas) marcando las suscriptas.
   3. /newsletter/prefs/save suma y saca de listas en Elastic Email. Sumarse
      a una lista da los créditos de esa lista una sola vez (misma referencia
      que el Hero y las landings).
   Los newsletters traen "Gestionar suscripciones" con el email ya cargado.

   Admin (Tema OEC → Newsletter): pestañas "Suscriptos" (cantidades por
   lista, listado paginado y búsqueda por email) y "Enviar un email" (editor
   de WordPress → email con el diseño G-SE → campaña a una lista, con prueba
   previa a la lista Prueba).
   ============================================================ */

const OEC_NL_MANAGE_SLUG    = 'mis-suscripciones';
const OEC_NL_MANAGE_HOURS   = 48;
const OEC_NL_COMPOSE_OPTION = 'oec_newsletter_compose';
const OEC_NL_ADMIN_PER_PAGE = 50;
// Campo de contacto de Elastic Email con la clave de cada suscriptor (lo
// creó una importación de CSV el 2026-10-08; desde el panel solo se pueden
// crear campos con plan PRO). Los newsletters lo usan como {oecclave} en
// "Gestionar mis suscripciones" para entrar sin pedir el link por email.
const OEC_NL_KEY_FIELD      = 'oecclave';
const OEC_NL_KEYS_JOB       = 'oec_newsletter_keys_job';
const OEC_NL_KEYS_CRON      = 'oec_nl_keys_tick';
const OEC_NL_KEYS_PER_RUN   = 300;

/**
 * Clave personal de un suscriptor: firma de su email que solo el sitio puede
 * generar (no vence; cambiar las salts de WordPress invalida todas).
 */
function oec_nl_member_key( string $email ): string {
	return 'k1' . substr( oec_nl_b64( hash_hmac( 'sha256', 'member|' . strtolower( trim( $email ) ), oec_nl_secret(), true ) ), 0, 22 );
}

/**
 * Guarda la clave en el contacto si no la tiene (sin perder sus otros datos:
 * oec_nl_update_contact reenvía el contacto completo).
 *
 * @return bool true si ya la tenía o se guardó.
 */
function oec_nl_ensure_member_key( string $email, ?array $contact = null ): bool {
	if ( null === $contact ) {
		$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
		if ( is_wp_error( $contact ) ) {
			return false;
		}
	}
	$key = oec_nl_member_key( $email );
	if ( ( $contact['CustomFields'][ OEC_NL_KEY_FIELD ] ?? '' ) === $key ) {
		return true;
	}
	$res = oec_nl_update_contact( $email, [], [ OEC_NL_KEY_FIELD => $key ], $contact );
	return ! is_wp_error( $res );
}

/**
 * Quién está gestionando: por el token del link del email (?acceso=) o por
 * email + clave (link de los newsletters o el dispositivo recordado).
 */
function oec_nl_manage_identity( WP_REST_Request $request ): ?string {
	$token = (string) $request->get_param( 'token' );
	if ( '' !== $token ) {
		$data = oec_nl_read_manage_token( $token );
		return $data ? $data['e'] : null;
	}
	// Elastic Email no codifica {email} en la URL: un "+" llega como espacio.
	$email = sanitize_email( str_replace( ' ', '+', (string) $request->get_param( 'email' ) ) );
	$clave = (string) $request->get_param( 'clave' );
	return ( is_email( $email ) && '' !== $clave && hash_equals( oec_nl_member_key( $email ), $clave ) ) ? $email : null;
}

function oec_nl_manage_url( string $email = '' ): string {
	$url = oec_config_url( '/' . OEC_NL_MANAGE_SLUG . '/' );
	return $email ? $url . '?email=' . rawurlencode( $email ) : $url;
}

/** Listas que se pueden gestionar desde la página, con su nombre y descripción. */
function oec_nl_manage_lists(): array {
	$landings = [];
	if ( function_exists( 'oec_get_especiales_list' ) ) {
		foreach ( oec_get_especiales_list() as $e ) {
			$landings[ $e['tematica'] ] = $e;
		}
	}
	$especiales = oec_nl_especiales_lists();
	$out        = [];
	foreach ( oec_nl_subscribable_lists() as $name ) {
		$e     = isset( $especiales[ $name ] ) ? ( $landings[ $especiales[ $name ]['tematica'] ] ?? null ) : null;
		$out[] = [
			'name'   => $name,
			'label'  => OEC_NL_GENERAL_LIST === $name ? __( 'Newsletter semanal de G-SE', 'oec-theme' ) : oec_nl_list_label( $name ),
			'desc'   => OEC_NL_GENERAL_LIST === $name
				? __( 'Lo más importante de cada semana: artículos y formaciones de todas las temáticas.', 'oec-theme' )
				: (string) ( $e['desc'] ?? '' ),
			'accent' => (string) ( $e['accent'] ?? '' ),
		];
	}
	return $out;
}

/** Datos del token de "Gestionar mis suscripciones" (o null si no sirve). */
function oec_nl_read_manage_token( string $token ): ?array {
	$data = oec_nl_read_token( $token );
	return ( $data && 'manage' === ( $data['p'] ?? '' ) && is_email( $data['e'] ?? '' ) ) ? $data : null;
}

/* ============================================================
   EMAIL CON EL LINK
   ============================================================ */
function oec_nl_render_manage_email( string $first, string $url ): string {
	$c = oec_nl_colors();
	$p = 'margin:0 0 16px;font-family:' . OEC_NL_FONT . ';font-size:15px;line-height:23px;color:' . $c['muted'] . ';';

	$body = '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:32px 32px 8px;">'
		. '<p style="' . $p . '">Desde este link ves a qué newsletters estás suscripto, te sumás a otros (y sumás ' . (int) OEC_NL_SUBSCRIBE_CREDITS . ' créditos por cada uno nuevo) o te das de baja de los que no quieras.</p>'
		. oec_nl_btn( esc_url( $url ), 'Gestionar mis suscripciones', $c['accent'], $c['dark'] )
		. '<p style="' . $p . 'margin:16px 0 0;font-size:13px;line-height:19px;">El link vence en ' . (int) OEC_NL_MANAGE_HOURS . ' horas. Si no lo pediste, ignorá este email: no cambia nada.</p>'
		. '</td></tr>';

	return oec_nl_email_shell( [
		'title'      => 'Tus suscripciones',
		'preheader'  => 'Tu link para gestionar tus suscripciones a los newsletters de G-SE.',
		'label'      => 'Newsletters',
		'heading'    => $first ? 'Hola, ' . esc_html( $first ) : 'Tus suscripciones',
		'intro'      => esc_html( 'Gestioná tus suscripciones a los newsletters de G-SE.' ),
		'body'       => $body,
		'subscribed' => false,
	] );
}

/* ============================================================
   REST
   ============================================================ */
add_action( 'rest_api_init', function () {
	$routes = [
		'/newsletter/manage-link' => 'oec_nl_rest_manage_link',
		'/newsletter/prefs'       => 'oec_nl_rest_prefs',
		'/newsletter/prefs/save'  => 'oec_nl_rest_prefs_save',
	];
	foreach ( $routes as $route => $cb ) {
		register_rest_route( 'oec/v1', $route, [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => $cb,
			'permission_callback' => '__return_true',
		] );
	}
} );

/** POST { email, website } → manda el link para gestionar las suscripciones. */
function oec_nl_rest_manage_link( WP_REST_Request $request ): WP_REST_Response {
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	$ok    = [
		'ok'      => true,
		'message' => sprintf(
			/* translators: %s: email */
			__( 'Te enviamos un email a %s con el link para gestionar tus suscripciones (revisá también spam y promociones).', 'oec-theme' ),
			$email
		),
	];
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return oec_nl_json( $ok ); // honeypot
	}
	if ( oec_nl_rate_limited( 'manage', 5 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	if ( ! is_email( $email ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Ingresá un email válido.', 'oec-theme' ) ], 400 );
	}

	// Un link cada 2 minutos por dirección.
	$throttle = 'oec_nl_mlink_' . md5( strtolower( $email ) );
	if ( get_transient( $throttle ) ) {
		return oec_nl_json( $ok );
	}

	// Suprimida en Elastic Email: el link no llegaría. Rebotes/inactivos se
	// reactivan (como al suscribirse); bajas y quejas no se pueden reactivar
	// por API, así que se le ofrece suscribirse de nuevo (doble opt-in).
	$sup = oec_nl_suppression( $email );
	if ( ! is_wp_error( $sup ) && $sup ) {
		if ( in_array( $sup['status'], [ 'Unsubscribed', 'Abuse', 'NotConfirmed' ], true ) ) {
			return oec_nl_json( [
				'ok'      => false,
				'code'    => 'unsubscribed',
				'message' => __( 'Tu email está dado de baja de todos nuestros envíos, así que no podemos mandarte el link. Si querés volver a recibir newsletters, suscribite con el formulario de abajo.', 'oec-theme' ),
			] );
		}
		$changed = oec_nl_api_v2( '/contact/changestatus', [ 'emails' => $email, 'status' => 'Active' ] );
		if ( is_wp_error( $changed ) ) {
			oec_nl_log( 'error', 'Link de suscripciones: no se pudo reactivar ' . $email . ': ' . $changed->get_error_message() );
		} else {
			oec_nl_log( 'success', sprintf( 'Dirección reactivada para mandarle el link de suscripciones: %s (estaba %s).', $email, $sup['status'] ) );
			sleep( 2 );
		}
	}

	$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
	$first   = is_wp_error( $contact ) ? '' : (string) ( $contact['FirstName'] ?? '' );
	if ( ! is_wp_error( $contact ) ) {
		oec_nl_ensure_member_key( $email, $contact ); // así sus próximos newsletters ya traen el acceso directo
	}
	$token   = oec_nl_sign_token( [ 'e' => $email, 'p' => 'manage', 'exp' => time() + OEC_NL_MANAGE_HOURS * HOUR_IN_SECONDS ] );
	// ?acceso= y no ?m=: 'm' es una variable reservada de WordPress (archivo por mes) y da 404.
	$url     = add_query_arg( 'acceso', $token, oec_nl_manage_url() );

	$sent = oec_nl_api( 'POST', '/emails/transactional', [
		'Recipients' => [ 'To' => [ $email ] ],
		'Content'    => [
			'From'    => OEC_NL_FROM,
			'Subject' => __( 'Tu link para gestionar tus suscripciones', 'oec-theme' ),
			'Body'    => [ [ 'ContentType' => 'HTML', 'Content' => oec_nl_render_manage_email( $first, $url ), 'Charset' => 'utf-8' ] ],
		],
	] );
	if ( is_wp_error( $sent ) ) {
		oec_nl_log( 'error', 'Link de suscripciones a ' . $email . ': ' . $sent->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos enviarte el email. Probá de nuevo más tarde.', 'oec-theme' ) ], 502 );
	}
	set_transient( $throttle, 1, 2 * MINUTE_IN_SECONDS );
	return oec_nl_json( $ok );
}

/** Estado de las listas para un email (para la página). */
function oec_nl_prefs_payload( string $email, array $member_of ): array {
	$lists = [];
	foreach ( oec_nl_manage_lists() as $l ) {
		$l['subscribed'] = in_array( $l['name'], $member_of, true );
		$lists[]         = $l;
	}
	return [ 'email' => $email, 'clave' => oec_nl_member_key( $email ), 'lists' => $lists, 'credits' => OEC_NL_SUBSCRIBE_CREDITS ];
}

/** POST { token } → listas y cuáles tiene. */
function oec_nl_rest_prefs( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'prefs', 20 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	$email = oec_nl_manage_identity( $request );
	if ( ! $email ) {
		return oec_nl_json( [ 'ok' => false, 'code' => 'expired', 'message' => __( 'El link no es válido o venció. Pedí uno nuevo con tu email.', 'oec-theme' ) ], 400 );
	}
	oec_nl_forget_member( $email ); // datos frescos: puede haber cambiado algo desde Elastic Email
	$member_of = oec_nl_member_of( $email );
	if ( is_wp_error( $member_of ) ) {
		oec_nl_log( 'error', 'Mis suscripciones (' . $email . '): ' . $member_of->get_error_message() );
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos consultar tus suscripciones. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}
	if ( '' !== (string) $request->get_param( 'token' ) && $member_of ) {
		oec_nl_ensure_member_key( $email ); // entró con el link del email: que sus newsletters traigan la clave
	}
	return oec_nl_json( [ 'ok' => true ] + oec_nl_prefs_payload( $email, $member_of ) );
}

/** POST { token, lists[] } → suma y saca de listas. */
function oec_nl_rest_prefs_save( WP_REST_Request $request ): WP_REST_Response {
	if ( oec_nl_rate_limited( 'prefs_save', 10 ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'Demasiados intentos. Probá de nuevo en un minuto.', 'oec-theme' ) ], 429 );
	}
	$email = oec_nl_manage_identity( $request );
	if ( ! $email ) {
		return oec_nl_json( [ 'ok' => false, 'code' => 'expired', 'message' => __( 'El link venció. Pedí uno nuevo con tu email.', 'oec-theme' ) ], 400 );
	}
	$available = oec_nl_subscribable_lists();
	$wanted    = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $request->get_param( 'lists' ) ), $available ) );

	oec_nl_forget_member( $email );
	$member_of = oec_nl_member_of( $email );
	if ( is_wp_error( $member_of ) ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos consultar tus suscripciones. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}
	$current = array_values( array_intersect( $member_of, $available ) );
	$adds    = array_values( array_diff( $wanted, $current ) );
	$removes = array_values( array_diff( $current, $wanted ) );
	$notes   = [];
	$errors  = 0;

	// Bajas: se saca de cada lista (no es una baja global de Elastic Email:
	// la persona puede seguir en otras listas y recibir emails transaccionales).
	foreach ( $removes as $list ) {
		$res = oec_nl_api( 'POST', '/lists/' . rawurlencode( $list ) . '/contacts/remove', [ 'Emails' => [ $email ] ] );
		if ( is_wp_error( $res ) ) {
			++$errors;
			oec_nl_log( 'error', sprintf( 'Mis suscripciones: no se pudo sacar a %s de %s: %s', $email, $list, $res->get_error_message() ) );
		}
	}
	if ( $removes && ! $errors ) {
		oec_nl_log( 'info', sprintf( 'Mis suscripciones: %s se dio de baja de %s.', $email, implode( ', ', $removes ) ) );
	}

	$balance = null;
	if ( $adds ) {
		$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $email ) );
		$first   = is_wp_error( $contact ) ? '' : (string) ( $contact['FirstName'] ?? '' );
		$last    = is_wp_error( $contact ) ? '' : (string) ( $contact['LastName'] ?? '' );

		// Suprimida: el link ya probó que es su casilla, así que cuenta como
		// declaración explícita (también para quejas por spam).
		$sup = oec_nl_suppression( $email );
		if ( ! is_wp_error( $sup ) && $sup ) {
			$handled = oec_nl_handle_suppression( $email, $sup['status'], true, $first, $last, $adds );
			if ( $handled instanceof WP_REST_Response ) {
				// Doble opt-in de Elastic Email (bajas/quejas) o error: lo informa.
				oec_nl_forget_member( $email );
				return $handled;
			}
		}

		$added = oec_nl_add_contact( $email, $first, $last, $adds );
		if ( is_wp_error( $added ) ) {
			++$errors;
			oec_nl_log( 'error', 'Mis suscripciones: no se pudo sumar a ' . $email . ': ' . $added->get_error_message() );
		} else {
			oec_nl_log( 'success', sprintf( 'Mis suscripciones: %s se sumó a %s.', $email, implode( ', ', $adds ) ) );
			$granted = 0;
			foreach ( $adds as $list ) {
				$credits = function_exists( 'oec_credits_grant_once' ) ? oec_credits_grant_once( $email, OEC_NL_SUBSCRIBE_CREDITS, oec_nl_subscribe_ref( [ $list ] ) ) : null;
				if ( is_array( $credits ) ) {
					$balance  = $credits['balance'];
					$granted += $credits['granted'] ? OEC_NL_SUBSCRIBE_CREDITS : 0;
				}
			}
			if ( $granted ) {
				/* translators: %d: créditos */
				$notes[] = sprintf( __( 'Sumamos %d créditos a tu cuenta.', 'oec-theme' ), $granted );
			}
		}
	}

	oec_nl_forget_member( $email );
	if ( $errors ) {
		return oec_nl_json( [ 'ok' => false, 'message' => __( 'No pudimos guardar todos los cambios. Probá de nuevo en unos minutos.', 'oec-theme' ) ], 502 );
	}

	if ( ! $adds && ! $removes ) {
		$msg = __( 'No había cambios para guardar.', 'oec-theme' );
	} elseif ( ! $wanted ) {
		$msg = __( 'Listo: ya no vas a recibir nuestros newsletters. Podés volver a sumarte cuando quieras.', 'oec-theme' );
	} else {
		$msg = __( '¡Listo! Guardamos tus cambios.', 'oec-theme' );
	}

	return oec_nl_json( [
		'ok'      => true,
		'message' => trim( $msg . ' ' . implode( ' ', $notes ) ),
		'balance' => $balance,
	] + oec_nl_prefs_payload( $email, $wanted ) );
}

/* ============================================================
   PÁGINA /mis-suscripciones/
   ============================================================ */
function oec_nl_render_manage_page(): void {
	oec_nl_enqueue_js();
	?>
	<div class="oec-nl-landing oec-nl-manage"
		data-link="<?php echo esc_url( rest_url( 'oec/v1/newsletter/manage-link' ) ); ?>"
		data-prefs="<?php echo esc_url( rest_url( 'oec/v1/newsletter/prefs' ) ); ?>"
		data-save="<?php echo esc_url( rest_url( 'oec/v1/newsletter/prefs/save' ) ); ?>">

		<div class="oec-nl-landing__state" data-state="request">
			<span class="oec-nl-landing__icon"><i class="bi bi-envelope-paper-heart" aria-hidden="true"></i></span>
			<h1 class="oec-nl-landing__title"><?php esc_html_e( 'Mis suscripciones', 'oec-theme' ); ?></h1>
			<p class="oec-nl-landing__note"><?php esc_html_e( 'Elegí qué newsletters querés recibir, sumate a nuevas temáticas o date de baja. Para proteger tus datos, te mandamos un link a tu email.', 'oec-theme' ); ?></p>
			<form class="oec-nl-manage__request" novalidate>
				<label class="screen-reader-text" for="oec-nl-manage-email"><?php esc_html_e( 'Email', 'oec-theme' ); ?></label>
				<input id="oec-nl-manage-email" class="oec-nl__input" type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'tu@email.com', 'oec-theme' ); ?>" required>
				<input type="text" name="website" class="oec-nl__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Enviarme el link', 'oec-theme' ); ?></button>
			</form>
			<p class="oec-nl-manage__msg" role="status" aria-live="polite" hidden></p>
			<div class="oec-nl-manage__subscribe" hidden>
				<?php echo oec_nl_render_form( [ 'title' => '', 'text' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>

		<div class="oec-nl-landing__state" data-state="loading" hidden>
			<i class="bi bi-arrow-repeat oec-credits-widget__spinner" aria-hidden="true"></i>
			<p><?php esc_html_e( 'Cargando tus suscripciones…', 'oec-theme' ); ?></p>
		</div>

		<div class="oec-nl-landing__state" data-state="prefs" hidden>
			<h1 class="oec-nl-landing__title"><?php esc_html_e( 'Tus newsletters', 'oec-theme' ); ?></h1>
			<p class="oec-nl-landing__note">
				<?php esc_html_e( 'Suscripciones de', 'oec-theme' ); ?> <strong class="oec-nl-manage__email"></strong>
				· <button type="button" class="oec-nl-manage__forget"><?php esc_html_e( '¿No sos vos? Cambiar de email', 'oec-theme' ); ?></button>
			</p>
			<form class="oec-nl-manage__form" novalidate>
				<ul class="oec-nl-manage__lists"></ul>
				<p class="oec-nl-manage__hint">
					<?php printf( esc_html__( 'Los newsletters marcados con +%d te suman créditos la primera vez que te suscribís. Todos llegan los %s.', 'oec-theme' ), (int) OEC_NL_SUBSCRIBE_CREDITS, esc_html( oec_nl_weekday_plural() ) ); ?>
				</p>
				<div class="oec-nl-landing__actions">
					<button type="submit" class="btn btn-primary oec-nl-manage__save"><?php esc_html_e( 'Guardar cambios', 'oec-theme' ); ?></button>
					<button type="button" class="btn btn-ghost oec-nl-manage__none"><?php esc_html_e( 'Darme de baja de todos', 'oec-theme' ); ?></button>
				</div>
			</form>
			<p class="oec-nl-manage__msg" role="status" aria-live="polite" hidden></p>
		</div>

		<div class="oec-nl-landing__state" data-state="error" hidden>
			<span class="oec-nl-landing__icon oec-nl-landing__icon--error"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i></span>
			<h1 class="oec-nl-landing__title"><?php esc_html_e( 'Algo no salió bien', 'oec-theme' ); ?></h1>
			<p class="oec-nl-landing__error"></p>
			<div class="oec-nl-landing__actions">
				<a class="btn btn-primary" href="<?php echo esc_url( oec_nl_manage_url() ); ?>"><?php esc_html_e( 'Pedir un link nuevo', 'oec-theme' ); ?></a>
			</div>
		</div>
	</div>
	<?php
}

/* ============================================================
   ADMIN: pestañas, cantidades y listados
   ============================================================ */
function oec_nl_admin_view(): string {
	$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $view, [ 'suscriptos', 'enviar' ], true ) ? $view : 'config';
}

function oec_nl_admin_tabs( string $view ): void {
	$tabs = [
		'config'     => __( 'Configuración', 'oec-theme' ),
		'suscriptos' => __( 'Suscriptos', 'oec-theme' ),
		'enviar'     => __( 'Enviar un email', 'oec-theme' ),
	];
	echo '<nav class="nav-tab-wrapper" style="margin-bottom:1.5rem;">';
	foreach ( $tabs as $key => $label ) {
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( oec_admin_url( 'oec-newsletter', 'config' === $key ? [] : [ 'view' => $key ] ) ),
			$key === $view ? ' nav-tab-active' : '',
			esc_html( $label )
		);
	}
	echo '</nav>';
}

/** Suscriptos activos (Active o Engaged) de una lista, cacheado 1 h. null si falla. */
function oec_nl_list_count( string $list, bool $fresh = false ): ?int {
	$key = 'oec_nl_count_' . md5( $list );
	if ( ! $fresh ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
	}
	$rule  = "listname = '" . str_replace( "'", "''", $list ) . "' AND (status = 'Active' OR status = 'Engaged')";
	$count = oec_nl_api_v2( '/contact/count', [ 'rule' => $rule ] );
	if ( is_wp_error( $count ) ) {
		return null;
	}
	set_transient( $key, (int) $count, HOUR_IN_SECONDS );
	return (int) $count;
}

/** Listas que se muestran en las pestañas del admin: las del sitio + la de pruebas. */
function oec_nl_admin_lists(): array {
	return array_values( array_unique( array_merge( oec_nl_subscribable_lists(), [ OEC_NL_TEST_LIST ] ) ) );
}

function oec_nl_format_number( ?int $n ): string {
	return null === $n ? '—' : number_format_i18n( $n );
}

function oec_nl_render_admin_subscribers(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- solo lectura
	$lists  = oec_nl_admin_lists();
	$fresh  = ! empty( $_GET['fresh'] );
	$list   = sanitize_text_field( wp_unslash( $_GET['lista'] ?? '' ) );
	$list   = in_array( $list, $lists, true ) ? $list : '';
	$page   = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
	$search = sanitize_email( wp_unslash( $_GET['buscar'] ?? '' ) );
	// phpcs:enable
	$base = oec_admin_url( 'oec-newsletter', [ 'view' => 'suscriptos' ] );
	oec_nl_render_keys_card();
	?>
	<div class="oec-card">
		<div class="oec-card__header">
			<h2><?php esc_html_e( 'Suscriptos por lista', 'oec-theme' ); ?></h2>
			<p><?php esc_html_e( 'Contactos activos de cada lista en Elastic Email (no cuenta bajas, rebotes ni quejas). Los números se actualizan cada hora.', 'oec-theme' ); ?></p>
		</div>
		<div class="oec-card__body">
			<table class="widefat striped" style="max-width:720px;">
				<thead><tr><th><?php esc_html_e( 'Lista', 'oec-theme' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Suscriptos activos', 'oec-theme' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $lists as $name ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( oec_nl_list_label( $name ) ); ?></strong>
							<?php if ( oec_nl_list_label( $name ) !== $name ) : ?><br><code style="font-size:11px;"><?php echo esc_html( $name ); ?></code><?php endif; ?>
							<?php if ( OEC_NL_GENERAL_LIST === $name ) : ?> <span class="oec-nl-tag"><?php esc_html_e( 'general', 'oec-theme' ); ?></span><?php endif; ?>
							<?php if ( OEC_NL_TEST_LIST === $name ) : ?> <span class="oec-nl-tag"><?php esc_html_e( 'pruebas', 'oec-theme' ); ?></span><?php endif; ?>
						</td>
						<td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo esc_html( oec_nl_format_number( oec_nl_list_count( $name, $fresh ) ) ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( 'lista', $name, $base ) ); ?>"><?php esc_html_e( 'Ver suscriptos', 'oec-theme' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( add_query_arg( 'fresh', 1, $base ) ); ?>"><?php esc_html_e( 'Actualizar números', 'oec-theme' ); ?></a></p>
		</div>
	</div>

	<div class="oec-card">
		<div class="oec-card__header">
			<h2><?php esc_html_e( 'Buscar un suscriptor', 'oec-theme' ); ?></h2>
			<p><?php esc_html_e( 'Estado del contacto en Elastic Email y a qué newsletters de G-SE está suscripto.', 'oec-theme' ); ?></p>
		</div>
		<div class="oec-card__body">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="oec-nl-actions">
				<input type="hidden" name="page" value="oec-newsletter">
				<input type="hidden" name="view" value="suscriptos">
				<input type="email" name="buscar" class="regular-text" value="<?php echo esc_attr( $search ); ?>" placeholder="email@ejemplo.com" aria-label="<?php esc_attr_e( 'Email', 'oec-theme' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'oec-theme' ); ?></button>
			</form>
			<?php
			if ( $search ) :
				$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $search ) );
				if ( is_wp_error( $contact ) ) :
					?>
					<p><?php echo esc_html( oec_nl_is_not_found( $contact ) ? __( 'Ese email no es un contacto de Elastic Email.', 'oec-theme' ) : $contact->get_error_message() ); ?></p>
					<?php
				else :
					oec_nl_forget_member( $search );
					$member  = oec_nl_member_of( $search );
					$found   = oec_nl_api_v2( '/contact/findcontact', [ 'email' => $search ] );
					$in_list = is_wp_error( $found ) ? [] : array_values( array_intersect( oec_nl_list_names( $found ), $lists ) );
					?>
					<dl class="oec-nl-facts">
						<dt><?php esc_html_e( 'Nombre', 'oec-theme' ); ?></dt><dd><?php echo esc_html( trim( ( $contact['FirstName'] ?? '' ) . ' ' . ( $contact['LastName'] ?? '' ) ) ?: '—' ); ?></dd>
						<dt><?php esc_html_e( 'Estado', 'oec-theme' ); ?></dt><dd><code><?php echo esc_html( $contact['Status'] ?? '?' ); ?></code> <?php echo in_array( $contact['Status'] ?? '', [ 'Active', 'Engaged' ], true ) ? '' : esc_html__( '— no recibe campañas', 'oec-theme' ); ?></dd>
						<dt><?php esc_html_e( 'Alta', 'oec-theme' ); ?></dt><dd><?php echo esc_html( ! empty( $contact['DateAdded'] ) ? wp_date( 'j/m/Y', strtotime( $contact['DateAdded'] ) ) : '—' ); ?></dd>
						<dt><?php esc_html_e( 'Newsletters de G-SE', 'oec-theme' ); ?></dt>
						<dd><?php echo $in_list ? esc_html( implode( ' · ', array_map( 'oec_nl_list_label', $in_list ) ) ) : esc_html__( 'ninguno', 'oec-theme' ); ?></dd>
						<dt><?php esc_html_e( 'Actividad', 'oec-theme' ); ?></dt>
						<dd><?php printf( esc_html__( '%1$s enviados · %2$s abiertos · %3$s clics', 'oec-theme' ), esc_html( number_format_i18n( (int) ( $contact['Activity']['TotalSent'] ?? 0 ) ) ), esc_html( number_format_i18n( (int) ( $contact['Activity']['TotalOpened'] ?? 0 ) ) ), esc_html( number_format_i18n( (int) ( $contact['Activity']['TotalClicked'] ?? 0 ) ) ) ); ?></dd>
					</dl>
					<?php
				endif;
			endif;
			?>
		</div>
	</div>

	<?php if ( $list ) :
		$offset   = ( $page - 1 ) * OEC_NL_ADMIN_PER_PAGE;
		$contacts = oec_nl_api( 'GET', '/lists/' . rawurlencode( $list ) . '/contacts', null, [ 'limit' => OEC_NL_ADMIN_PER_PAGE, 'offset' => $offset ] );
		$list_url = add_query_arg( 'lista', $list, $base );
		?>
	<div class="oec-card">
		<div class="oec-card__header">
			<h2><?php printf( esc_html__( 'Suscriptos de %s', 'oec-theme' ), esc_html( oec_nl_list_label( $list ) ) ); ?></h2>
			<p><?php printf( esc_html__( 'Página %d. Se listan todos los contactos de la lista, también los que se dieron de baja o rebotaron (columna Estado).', 'oec-theme' ), (int) $page ); ?></p>
		</div>
		<div class="oec-card__body">
			<?php if ( is_wp_error( $contacts ) ) : ?>
				<p style="color:#b32d2e"><?php echo esc_html( $contacts->get_error_message() ); ?></p>
			<?php elseif ( ! $contacts ) : ?>
				<p><?php esc_html_e( 'No hay más contactos.', 'oec-theme' ); ?></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'oec-theme' ); ?></th>
					<th><?php esc_html_e( 'Nombre', 'oec-theme' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'oec-theme' ); ?></th>
					<th><?php esc_html_e( 'Alta', 'oec-theme' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $contacts as $c ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'buscar', $c['Email'] ?? '', $base ) ); ?>"><?php echo esc_html( $c['Email'] ?? '' ); ?></a></td>
						<td><?php echo esc_html( trim( ( $c['FirstName'] ?? '' ) . ' ' . ( $c['LastName'] ?? '' ) ) ); ?></td>
						<td><code><?php echo esc_html( $c['Status'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( ! empty( $c['DateAdded'] ) ? wp_date( 'j/m/Y', strtotime( $c['DateAdded'] ) ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<p class="oec-nl-actions" style="margin-top:1rem;">
				<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'pg', $page - 1, $list_url ) ); ?>">&larr; <?php esc_html_e( 'Anteriores', 'oec-theme' ); ?></a>
				<?php endif; ?>
				<?php if ( is_array( $contacts ) && count( $contacts ) === OEC_NL_ADMIN_PER_PAGE ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'pg', $page + 1, $list_url ) ); ?>"><?php esc_html_e( 'Siguientes', 'oec-theme' ); ?> &rarr;</a>
				<?php endif; ?>
				<span class="description"><?php esc_html_e( 'Para exportar la lista completa usá Elastic Email → Contactos → Exportar.', 'oec-theme' ); ?></span>
			</p>
		</div>
	</div>
	<?php endif;
}

/* ============================================================
   ADMIN: enviar un email a una lista
   ============================================================ */
function oec_nl_compose_defaults(): array {
	return [ 'list' => OEC_NL_GENERAL_LIST, 'subject' => '', 'heading' => '', 'body' => '', 'cta_text' => '', 'cta_url' => '', 'only_test' => true ];
}

function oec_nl_compose_draft(): array {
	return wp_parse_args( (array) oec_config_get_option( OEC_NL_COMPOSE_OPTION, [] ), oec_nl_compose_defaults() );
}

/**
 * Contenido del editor de WordPress → HTML para email: estilos inline en
 * párrafos, títulos, listas y links, e imágenes que no pasen los 536 px.
 */
function oec_nl_style_content( string $html ): string {
	$c    = oec_nl_colors();
	$f    = OEC_NL_FONT;
	$html = wpautop( strip_shortcodes( wp_kses_post( $html ) ) );
	$base = 'font-family:' . $f . ';color:' . $c['text'] . ';';
	$css  = [
		'p'          => 'margin:0 0 16px;font-size:16px;line-height:25px;' . $base,
		'h2'         => 'margin:28px 0 10px;font-size:22px;line-height:28px;font-weight:800;' . $base,
		'h3'         => 'margin:24px 0 8px;font-size:19px;line-height:25px;font-weight:800;' . $base,
		'h4'         => 'margin:20px 0 8px;font-size:17px;line-height:23px;font-weight:800;' . $base,
		'ul'         => 'margin:0 0 16px;padding-left:22px;font-size:16px;line-height:25px;' . $base,
		'ol'         => 'margin:0 0 16px;padding-left:22px;font-size:16px;line-height:25px;' . $base,
		'li'         => 'margin:0 0 6px;',
		'blockquote' => 'margin:0 0 16px;padding:8px 16px;border-left:4px solid ' . $c['accent'] . ';font-style:italic;color:' . $c['muted'] . ';',
		'a'          => 'color:' . $c['primary'] . ';font-weight:700;text-decoration:underline;',
	];
	$html = (string) preg_replace_callback(
		'#<(p|h2|h3|h4|ul|ol|li|blockquote|a)(\s[^>]*)?>#i',
		function ( $m ) use ( $css ) {
			$tag   = strtolower( $m[1] );
			$attrs = $m[2] ?? '';
			if ( preg_match( '/\sstyle="([^"]*)"/i', $attrs ) ) {
				$attrs = preg_replace( '/\sstyle="([^"]*)"/i', ' style="' . $css[ $tag ] . '$1"', $attrs, 1 );
			} else {
				$attrs .= ' style="' . $css[ $tag ] . '"';
			}
			return '<' . $tag . $attrs . '>';
		},
		$html
	);
	// Imágenes: ancho máximo del email, sin alto fijo (proporción correcta).
	return (string) preg_replace_callback(
		'#<img\b[^>]*>#i',
		function ( $m ) {
			preg_match( '/\ssrc="([^"]*)"/i', $m[0], $src );
			preg_match( '/\salt="([^"]*)"/i', $m[0], $alt );
			preg_match( '/\swidth="(\d+)"/i', $m[0], $w );
			$width = min( 536, (int) ( $w[1] ?? 536 ) ?: 536 );
			return '<img src="' . ( $src[1] ?? '' ) . '" alt="' . ( $alt[1] ?? '' ) . '" width="' . $width . '" style="display:block;width:100%;max-width:' . $width . 'px;height:auto;border:0;border-radius:8px;margin:0 0 16px;">';
		},
		$html
	);
}

/** Email "suelto" con el diseño G-SE (colores de la temática si la lista es de una). */
function oec_nl_render_custom_email( array $d ): string {
	$c      = oec_nl_colors();
	$topic  = oec_nl_confirm_topic( [ $d['list'] ] );
	$accent = $topic ? ( sanitize_hex_color( $topic['accent'] ?? '' ) ?: $c['accent'] ) : $c['accent'];
	$dark   = $topic ? ( sanitize_hex_color( $topic['tinte'] ?? '' ) ?: $c['dark'] ) : $c['dark'];

	$body = '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:32px 32px 8px;">' . oec_nl_style_content( $d['body'] ) . '</td></tr>';
	if ( $d['cta_text'] && $d['cta_url'] ) {
		$body .= '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:8px 32px 16px;">' . oec_nl_btn( esc_url( $d['cta_url'] ), $d['cta_text'], $accent, $c['dark'] ) . '</td></tr>';
	}

	return oec_nl_email_shell( [
		'title'       => $d['subject'],
		'preheader'   => wp_trim_words( wp_strip_all_tags( $d['body'] ), 18, '…' ),
		'label'       => $topic ? 'Newsletter · ' . $topic['title'] : OEC_NL_BRAND,
		'sublabel'    => wp_date( 'j \d\e F' ),
		'label_color' => $accent,
		'header_bg'   => $dark,
		'heading'     => esc_html( $d['heading'] ?: $d['subject'] ),
		'intro'       => '',
		'body'        => $body,
		'subscribed'  => true,
	] );
}

function oec_nl_render_admin_compose(): void {
	$d      = oec_nl_compose_draft();
	$lists  = oec_nl_admin_lists();
	$counts = [];
	foreach ( $lists as $name ) {
		$counts[ $name ] = oec_nl_list_count( $name );
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="oec-nl-compose">
		<input type="hidden" name="action" value="oec_nl_compose">
		<?php wp_nonce_field( 'oec_nl_compose' ); ?>
		<div class="oec-card">
			<div class="oec-card__header">
				<h2><?php esc_html_e( 'Enviar un email a una lista', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'Se arma con el diseño de los newsletters (cabecera, colores de la temática, pie con baja) y sale como campaña de Elastic Email. El borrador se guarda con cada acción.', 'oec-theme' ); ?></p>
			</div>
			<div class="oec-card__body">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="oec-nl-c-list"><?php esc_html_e( 'Lista', 'oec-theme' ); ?></label></th>
						<td>
							<select id="oec-nl-c-list" name="list">
								<?php foreach ( $lists as $name ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>" data-count="<?php echo esc_attr( (string) ( $counts[ $name ] ?? '' ) ); ?>" <?php selected( $d['list'], $name ); ?>>
									<?php echo esc_html( oec_nl_list_label( $name ) . ' (' . oec_nl_format_number( $counts[ $name ] ) . ')' ); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="oec-nl-c-subject"><?php esc_html_e( 'Asunto', 'oec-theme' ); ?></label></th>
						<td><input id="oec-nl-c-subject" type="text" name="subject" class="large-text" value="<?php echo esc_attr( $d['subject'] ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="oec-nl-c-heading"><?php esc_html_e( 'Título', 'oec-theme' ); ?></label></th>
						<td>
							<input id="oec-nl-c-heading" type="text" name="heading" class="large-text" value="<?php echo esc_attr( $d['heading'] ); ?>">
							<p class="description"><?php esc_html_e( 'El título grande de la cabecera. Vacío = el asunto.', 'oec-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Contenido', 'oec-theme' ); ?></th>
						<td>
							<?php wp_editor( $d['body'], 'oec_nl_body', [ 'textarea_name' => 'body', 'media_buttons' => true, 'textarea_rows' => 14 ] ); ?>
							<p class="description"><?php esc_html_e( 'Podés usar {firstname:colega} para el nombre del suscriptor (con un texto por si no lo tiene cargado).', 'oec-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Botón (opcional)', 'oec-theme' ); ?></th>
						<td>
							<input type="text" name="cta_text" value="<?php echo esc_attr( $d['cta_text'] ); ?>" placeholder="<?php esc_attr_e( 'Texto: Ver la formación', 'oec-theme' ); ?>" aria-label="<?php esc_attr_e( 'Texto del botón', 'oec-theme' ); ?>">
							<input type="url" name="cta_url" class="regular-text" value="<?php echo esc_attr( $d['cta_url'] ); ?>" placeholder="https://g-se.com/es/…" aria-label="<?php esc_attr_e( 'Link del botón', 'oec-theme' ); ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Destino', 'oec-theme' ); ?></th>
						<td><label><input type="checkbox" name="only_test" value="1" <?php checked( ! empty( $d['only_test'] ) ); ?>> <?php printf( esc_html__( 'Solo a la lista %s (prueba, con [PRUEBA] en el asunto)', 'oec-theme' ), '<code>' . esc_html( OEC_NL_TEST_LIST ) . '</code>' ); ?></label></td>
					</tr>
				</table>
				<p class="oec-nl-actions">
					<button type="submit" name="do" value="save" class="button"><?php esc_html_e( 'Guardar borrador', 'oec-theme' ); ?></button>
					<button type="submit" name="do" value="preview" class="button" formtarget="_blank"><?php esc_html_e( 'Vista previa', 'oec-theme' ); ?></button>
					<button type="submit" name="do" value="test" class="button"><?php printf( esc_html__( 'Enviarme una prueba (%s)', 'oec-theme' ), esc_html( wp_get_current_user()->user_email ) ); ?></button>
					<button type="submit" name="do" value="send" class="button button-primary" id="oec-nl-c-send"><?php esc_html_e( 'Enviar', 'oec-theme' ); ?></button>
				</p>
			</div>
		</div>
	</form>
	<script>
	(function () {
		var form = document.getElementById('oec-nl-compose');
		document.getElementById('oec-nl-c-send').addEventListener('click', function (e) {
			if (form.only_test.checked) return;
			var opt = form.list.options[form.list.selectedIndex];
			var n = opt.getAttribute('data-count');
			var msg = '¿Enviar este email AHORA a ' + (n ? Number(n).toLocaleString('es-AR') + ' suscriptores de ' : 'todos los suscriptores de ') + opt.text.replace(/\s*\(.*\)$/, '') + '? No se puede deshacer.';
			if (!window.confirm(msg)) e.preventDefault();
		});
	})();
	</script>
	<?php
}

add_action( 'admin_post_oec_nl_compose', function () {
	if ( ! current_user_can( 'manage_options' ) || ! oec_is_config_site() ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_nl_compose' );

	$in = wp_unslash( $_POST );
	$d  = [
		'list'      => in_array( $in['list'] ?? '', oec_nl_admin_lists(), true ) ? $in['list'] : OEC_NL_GENERAL_LIST,
		'subject'   => sanitize_text_field( $in['subject'] ?? '' ),
		'heading'   => sanitize_text_field( $in['heading'] ?? '' ),
		'body'      => wp_kses_post( $in['body'] ?? '' ),
		'cta_text'  => sanitize_text_field( $in['cta_text'] ?? '' ),
		'cta_url'   => esc_url_raw( $in['cta_url'] ?? '' ),
		'only_test' => ! empty( $in['only_test'] ),
	];
	oec_config_update_option( OEC_NL_COMPOSE_OPTION, $d, false );

	$do = sanitize_key( $in['do'] ?? 'save' );
	if ( 'save' === $do ) {
		oec_nl_redirect_notice( 'success', __( 'Borrador guardado.', 'oec-theme' ), 'enviar' );
	}
	if ( '' === $d['subject'] || '' === trim( wp_strip_all_tags( $d['body'] ) ) ) {
		oec_nl_redirect_notice( 'error', __( 'Completá el asunto y el contenido.', 'oec-theme' ), 'enviar' );
	}

	$html = oec_nl_render_custom_email( $d );

	if ( 'preview' === $do ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo oec_nl_public_html( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	if ( 'test' === $do ) {
		$to      = wp_get_current_user()->user_email;
		$contact = oec_nl_api( 'GET', '/contacts/' . rawurlencode( $to ) );
		$res     = oec_nl_api( 'POST', '/emails/transactional', [
			'Recipients' => [ 'To' => [ $to ] ],
			'Content'    => [
				'From'    => OEC_NL_FROM,
				'Subject' => '[PRUEBA] ' . $d['subject'],
				'Body'    => [ [ 'ContentType' => 'HTML', 'Content' => oec_nl_personalize_html( $html, $to, is_wp_error( $contact ) ? '' : (string) ( $contact['FirstName'] ?? '' ), is_wp_error( $contact ) ? '' : (string) ( $contact['LastName'] ?? '' ), $d['list'] ), 'Charset' => 'utf-8' ] ],
			],
		] );
		is_wp_error( $res )
			? oec_nl_redirect_notice( 'error', $res->get_error_message(), 'enviar' )
			: oec_nl_redirect_notice( 'success', sprintf( __( 'Prueba enviada a %s.', 'oec-theme' ), $to ), 'enviar' );
	}

	if ( 'send' === $do ) {
		$test    = $d['only_test'];
		$target  = $test ? OEC_NL_TEST_LIST : $d['list'];
		$subject = ( $test ? '[PRUEBA] ' : '' ) . $d['subject'];
		$name    = sprintf( '%sEmail %s %s %d', $test ? 'PRUEBA ' : '', $target, wp_date( 'Y-m-d' ), time() );
		$res     = oec_nl_post_campaign( $name, $subject, $html, $target, 'Active', 'email+' . sanitize_title( $d['subject'] ) );
		if ( is_wp_error( $res ) ) {
			oec_nl_log( 'error', sprintf( 'Email "%s" a %s: %s', $d['subject'], $target, $res->get_error_message() ) );
			oec_nl_redirect_notice( 'error', $res->get_error_message(), 'enviar' );
		}
		oec_nl_log( 'success', sprintf( 'Email "%s" enviado a la lista %s%s.', $d['subject'], $target, $test ? ' (prueba)' : '' ) );
		if ( ! $test ) {
			// Enviado de verdad: se vacía el borrador para no reenviarlo sin querer.
			oec_config_update_option( OEC_NL_COMPOSE_OPTION, oec_nl_compose_defaults(), false );
		}
		oec_nl_redirect_notice( 'success', sprintf( __( 'Email enviado a la lista %s. Lo ves en Elastic Email → Campañas.', 'oec-theme' ), $target ), 'enviar' );
	}

	oec_nl_redirect_notice( 'error', __( 'Acción desconocida.', 'oec-theme' ), 'enviar' );
} );

/* ============================================================
   CLAVES DE LOS SUSCRIPTORES ACTUALES (segundo plano)
   Recorre las listas del sitio y, a cada contacto activo sin su clave,
   se la guarda en OEC_NL_KEY_FIELD. Va de a pedazos desde WP-Cron (en
   producción, el cron del servidor cada 5 minutos) para no saturar la API.
   Cada escritura reenvía el contacto completo (oec_nl_update_contact), que
   es la única forma de no borrarle los otros campos.
   ============================================================ */
function oec_nl_keys_job(): array {
	return wp_parse_args( (array) oec_config_get_option( OEC_NL_KEYS_JOB, [] ), [
		'status'     => 'idle', // idle | running | paused | done
		'lists'      => [],
		'list'       => 0,
		'offset'     => 0,
		'done'       => 0,
		'skipped'    => 0,
		'errors'     => 0,
		'last_error' => '',
		'started'    => 0,
		'updated'    => 0,
	] );
}

function oec_nl_keys_job_save( array $job ): void {
	$job['updated'] = time();
	oec_config_update_option( OEC_NL_KEYS_JOB, $job, false );
}

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['oec_minute'] = [ 'interval' => MINUTE_IN_SECONDS, 'display' => 'Cada minuto (OEC)' ];
	return $schedules;
} );

function oec_nl_keys_schedule( bool $on ): void {
	$next = wp_next_scheduled( OEC_NL_KEYS_CRON );
	if ( $on && ! $next ) {
		wp_schedule_event( time() + 30, 'oec_minute', OEC_NL_KEYS_CRON );
	} elseif ( ! $on && $next ) {
		wp_clear_scheduled_hook( OEC_NL_KEYS_CRON );
	}
}

add_action( OEC_NL_KEYS_CRON, 'oec_nl_keys_tick' );

function oec_nl_keys_tick(): void {
	if ( ! oec_is_config_site() ) {
		return;
	}
	$job = oec_nl_keys_job();
	if ( 'running' !== $job['status'] ) {
		oec_nl_keys_schedule( false );
		return;
	}
	if ( get_transient( 'oec_nl_keys_lock' ) ) {
		return; // la pasada anterior todavía está corriendo
	}
	set_transient( 'oec_nl_keys_lock', 1, 5 * MINUTE_IN_SECONDS );

	$deadline = time() + 100;
	$written  = 0;
	while ( $written < OEC_NL_KEYS_PER_RUN && time() < $deadline ) {
		$list = $job['lists'][ $job['list'] ] ?? null;
		if ( null === $list ) {
			$job['status'] = 'done';
			break;
		}
		$page = oec_nl_api( 'GET', '/lists/' . rawurlencode( $list ) . '/contacts', null, [ 'limit' => 100, 'offset' => $job['offset'] ] );
		if ( is_wp_error( $page ) ) {
			++$job['errors'];
			$job['last_error'] = $list . ': ' . $page->get_error_message();
			break; // se reintenta en la próxima pasada
		}
		foreach ( (array) $page as $c ) {
			$email = (string) ( $c['Email'] ?? '' );
			$key   = is_email( $email ) ? oec_nl_member_key( $email ) : '';
			if ( ! $key || ! in_array( $c['Status'] ?? '', [ 'Active', 'Engaged' ], true ) || ( $c['CustomFields'][ OEC_NL_KEY_FIELD ] ?? '' ) === $key ) {
				++$job['skipped'];
				continue;
			}
			$res = oec_nl_update_contact( $email, [], [ OEC_NL_KEY_FIELD => $key ], $c );
			if ( is_wp_error( $res ) ) {
				++$job['errors'];
				$job['last_error'] = $email . ': ' . $res->get_error_message();
			} else {
				++$job['done'];
			}
			++$written;
		}
		$job['offset'] += count( (array) $page );
		if ( count( (array) $page ) < 100 ) {
			++$job['list'];
			$job['offset'] = 0;
		}
		oec_nl_keys_job_save( $job ); // progreso por página: si se corta, sigue desde acá
	}

	if ( 'done' === $job['status'] ) {
		oec_nl_keys_schedule( false );
		oec_nl_log( 'success', sprintf( 'Claves de "Gestionar mis suscripciones": terminado (%s guardadas, %s ya estaban o no corresponden, %s errores).', number_format_i18n( $job['done'] ), number_format_i18n( $job['skipped'] ), number_format_i18n( $job['errors'] ) ) );
	}
	oec_nl_keys_job_save( $job );
	delete_transient( 'oec_nl_keys_lock' );
}

add_action( 'admin_post_oec_nl_keys', function () {
	if ( ! current_user_can( 'manage_options' ) || ! oec_is_config_site() ) {
		wp_die( esc_html__( 'Sin permisos.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_nl_keys' );
	$job = oec_nl_keys_job();
	$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );

	if ( 'start' === $do || 'restart' === $do ) {
		$job = array_merge( oec_nl_keys_job(), [
			'status' => 'running', 'lists' => oec_nl_subscribable_lists(), 'list' => 0, 'offset' => 0,
			'done' => 0, 'skipped' => 0, 'errors' => 0, 'last_error' => '', 'started' => time(),
		] );
		oec_nl_log( 'info', 'Claves de "Gestionar mis suscripciones": proceso iniciado.' );
	} elseif ( 'pause' === $do && 'running' === $job['status'] ) {
		$job['status'] = 'paused';
	} elseif ( 'resume' === $do && 'paused' === $job['status'] ) {
		$job['status'] = 'running';
	}
	oec_nl_keys_job_save( $job );
	oec_nl_keys_schedule( 'running' === $job['status'] );
	oec_nl_redirect_notice( 'success', __( 'Listo.', 'oec-theme' ), 'suscriptos' );
} );

/** Tarjeta del admin (pestaña Suscriptos). */
function oec_nl_render_keys_card(): void {
	$job = oec_nl_keys_job();
	if ( 'running' === $job['status'] ) {
		oec_nl_keys_schedule( true ); // por si el evento se perdió
	}
	$with_key = get_transient( 'oec_nl_keys_count' );
	if ( false === $with_key ) {
		$with_key = oec_nl_api_v2( '/contact/count', [ 'rule' => OEC_NL_KEY_FIELD . " <> ''" ] );
		$with_key = is_wp_error( $with_key ) ? null : (int) $with_key;
		set_transient( 'oec_nl_keys_count', $with_key, 5 * MINUTE_IN_SECONDS );
	}
	$labels = [
		'idle'    => __( 'Sin iniciar', 'oec-theme' ),
		'running' => __( 'En curso', 'oec-theme' ),
		'paused'  => __( 'Pausado', 'oec-theme' ),
		'done'    => __( 'Terminado', 'oec-theme' ),
	];
	$total_lists = count( $job['lists'] );
	$current     = $job['lists'][ $job['list'] ] ?? '';
	?>
	<div class="oec-card">
		<div class="oec-card__header">
			<h2><?php esc_html_e( 'Acceso directo desde los newsletters', 'oec-theme' ); ?></h2>
			<p><?php printf( esc_html__( '"Gestionar mis suscripciones" entra directo si el contacto tiene su clave en el campo %s de Elastic Email. Los nuevos la reciben solos; este proceso se la carga a los suscriptores actuales, de a pedazos y en segundo plano (puede tardar horas con listas grandes).', 'oec-theme' ), '<code>' . esc_html( OEC_NL_KEY_FIELD ) . '</code>' ); ?></p>
		</div>
		<div class="oec-card__body">
			<dl class="oec-nl-facts">
				<dt><?php esc_html_e( 'Contactos con clave', 'oec-theme' ); ?></dt><dd><?php echo esc_html( oec_nl_format_number( $with_key ) ); ?></dd>
				<dt><?php esc_html_e( 'Estado', 'oec-theme' ); ?></dt><dd><strong><?php echo esc_html( $labels[ $job['status'] ] ?? $job['status'] ); ?></strong>
					<?php if ( in_array( $job['status'], [ 'running', 'paused' ], true ) && $current ) : ?>
						— <?php printf( esc_html__( 'lista %1$d de %2$d (%3$s), contacto %4$s', 'oec-theme' ), (int) $job['list'] + 1, (int) $total_lists, esc_html( oec_nl_list_label( $current ) ), esc_html( number_format_i18n( (int) $job['offset'] ) ) ); ?>
					<?php endif; ?>
				</dd>
				<?php if ( $job['started'] ) : ?>
				<dt><?php esc_html_e( 'Progreso', 'oec-theme' ); ?></dt>
				<dd><?php printf( esc_html__( '%1$s claves guardadas · %2$s ya la tenían o no reciben campañas · %3$s errores', 'oec-theme' ), esc_html( number_format_i18n( (int) $job['done'] ) ), esc_html( number_format_i18n( (int) $job['skipped'] ) ), esc_html( number_format_i18n( (int) $job['errors'] ) ) ); ?>
					<?php if ( $job['updated'] ) : ?><br><span class="description"><?php printf( esc_html__( 'Última actividad: %s', 'oec-theme' ), esc_html( wp_date( 'j/m H:i', (int) $job['updated'] ) ) ); ?></span><?php endif; ?>
				</dd>
				<?php endif; ?>
				<?php if ( $job['last_error'] ) : ?>
				<dt><?php esc_html_e( 'Último error', 'oec-theme' ); ?></dt><dd style="color:#b32d2e"><?php echo esc_html( $job['last_error'] ); ?></dd>
				<?php endif; ?>
			</dl>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oec-nl-actions" style="margin-top:1rem;">
				<input type="hidden" name="action" value="oec_nl_keys">
				<?php wp_nonce_field( 'oec_nl_keys' ); ?>
				<?php if ( 'idle' === $job['status'] ) : ?>
					<button type="submit" name="do" value="start" class="button button-primary"><?php esc_html_e( 'Cargar claves en los suscriptores actuales', 'oec-theme' ); ?></button>
				<?php elseif ( 'running' === $job['status'] ) : ?>
					<button type="submit" name="do" value="pause" class="button"><?php esc_html_e( 'Pausar', 'oec-theme' ); ?></button>
				<?php elseif ( 'paused' === $job['status'] ) : ?>
					<button type="submit" name="do" value="resume" class="button button-primary"><?php esc_html_e( 'Reanudar', 'oec-theme' ); ?></button>
				<?php else : ?>
					<button type="submit" name="do" value="restart" class="button"><?php esc_html_e( 'Volver a recorrer las listas', 'oec-theme' ); ?></button>
				<?php endif; ?>
			</form>
		</div>
	</div>
	<?php
}
