<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   API KEY — hardcodeada a propósito, mismo criterio que
   OEC_BOTMAKER_PROJECT_ID en el plugin: este tema siempre lo instalamos
   nosotros, así que el sistema de créditos funciona apenas se activa,
   sin depender de que alguien la cargue a mano en Ajustes. Si algún
   proyecto puntual necesitara otra key, el campo "credits_api_key" de
   Ajustes > OEC sigue funcionando como override (ver más abajo).
   ============================================================ */
if ( ! defined( 'OEC_CREDITS_API_KEY' ) ) {
	define( 'OEC_CREDITS_API_KEY', 'TODO_PEGAR_API_KEY_REAL_ACA' );
}

/**
 * API key de créditos: la de Ajustes OEC del sitio de configuración de la
 * red (/es/, ver oec_config_blog_id()); si está vacía, la constante.
 */
function oec_credits_api_key(): string {
	return trim( oec_config_theme_options()['credits_api_key'] ?? '' ) ?: OEC_CREDITS_API_KEY;
}

/* ============================================================
   REST ENDPOINT: POST /wp-json/oec/v1/credits
   Body: { email: string }
   Verifica si el usuario ya tiene créditos de bienvenida.
   Si no, otorga 50. Devuelve { granted, balance, message }.
   La API key nunca se expone al navegador.
   ============================================================ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'oec/v1', '/credits', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'oec_credits_handler',
		'permission_callback' => '__return_true',
		'args'                => [
			'email' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => fn( $v ) => is_email( $v ),
			],
		],
	] );
} );

function oec_credits_handler( WP_REST_Request $request ): WP_REST_Response {
	$api_key = oec_credits_api_key();
	$email   = $request->get_param( 'email' );
	$ref     = 'bienvenida-oec';

	if ( ! $api_key ) {
		return new WP_REST_Response( [ 'error' => 'credits_not_configured' ], 200 );
	}

	// Rate limit: 10 requests per IP per minute
	$ip       = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	$rate_key = 'oec_credits_rate_' . md5( $ip );
	$count    = (int) get_transient( $rate_key );
	if ( $count >= 10 ) {
		return new WP_REST_Response( [ 'error' => 'too_many_requests' ], 429 );
	}
	set_transient( $rate_key, $count + 1, 60 );

	// Check existing credits for this reference
	$check_url  = "https://api.onlineeducation.center/contable/api/points/{$email}/details?reference={$ref}";
	$check_resp = wp_remote_get( $check_url, [
		'headers' => [ 'X-API-KEY' => $api_key ],
		'timeout' => 25,
	] );

	if ( is_wp_error( $check_resp ) ) {
		return new WP_REST_Response( [ 'error' => 'api_unreachable' ], 200 );
	}

	$check_data = json_decode( wp_remote_retrieve_body( $check_resp ), true );

	if ( ! is_array( $check_data ) ) {
		return new WP_REST_Response( [ 'error' => 'invalid_response' ], 200 );
	}

	$balance = (int) ( $check_data['balance'] ?? 0 );

	// Already has welcome credits → return current balance
	if ( ! empty( $check_data['details'] ) ) {
		return new WP_REST_Response( [
			'granted' => false,
			'balance' => $balance,
			'message' => sprintf(
				/* translators: %d = credit balance */
				__( 'Ya tienes tus créditos de bienvenida. Tu balance actual es de %d créditos.', 'oec-theme' ),
				$balance
			),
		] );
	}

	// Grant 50 welcome credits
	$add_resp = wp_remote_post( 'https://api.onlineeducation.center/contable/api/points', [
		'headers' => [
			'X-API-KEY'    => $api_key,
			'Content-Type' => 'application/json',
		],
		'body'    => wp_json_encode( [
			'email'     => $email,
			'amount'    => 50,
			'reference' => $ref,
		] ),
		'timeout' => 25,
	] );

	if ( is_wp_error( $add_resp ) ) {
		error_log( '[OEC créditos] alta falló para ' . $email . ': ' . $add_resp->get_error_message() );
		return new WP_REST_Response( [ 'error' => 'grant_failed' ], 200 );
	}

	$add_data    = json_decode( wp_remote_retrieve_body( $add_resp ), true );
	$new_balance = (int) ( $add_data['balance'] ?? 0 );

	if ( ( $add_data['result'] ?? '' ) !== 'OK' ) {
		error_log( '[OEC créditos] alta rechazada para ' . $email . ': HTTP ' . wp_remote_retrieve_response_code( $add_resp ) . ' ' . substr( wp_remote_retrieve_body( $add_resp ), 0, 300 ) );
		return new WP_REST_Response( [ 'error' => 'grant_failed' ], 200 );
	}

	return new WP_REST_Response( [
		'granted' => true,
		'balance' => $new_balance,
		'message' => sprintf(
			/* translators: %d = credit balance */
			__( '¡Se añadieron 50 créditos de bienvenida a tu cuenta! Tu balance es de %d créditos.', 'oec-theme' ),
			$new_balance
		),
	] );
}

/* ============================================================
   REST ENDPOINT: POST /wp-json/oec/v1/credits/balance
   Sólo consulta el balance actual sin otorgar créditos.
   ============================================================ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'oec/v1', '/credits/balance', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'oec_credits_balance_handler',
		'permission_callback' => '__return_true',
		'args'                => [
			'email' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => fn( $v ) => is_email( $v ),
			],
		],
	] );
} );

function oec_credits_balance_handler( WP_REST_Request $request ): WP_REST_Response {
	$api_key = oec_credits_api_key();
	$email   = $request->get_param( 'email' );

	if ( ! $api_key ) {
		return new WP_REST_Response( [ 'balance' => null ], 200 );
	}

	$url  = "https://api.onlineeducation.center/contable/api/points/{$email}/details";
	$resp = wp_remote_get( $url, [
		'headers' => [ 'X-API-KEY' => $api_key ],
		'timeout' => 25,
	] );

	if ( is_wp_error( $resp ) ) {
		return new WP_REST_Response( [ 'balance' => null ], 200 );
	}

	$data = json_decode( wp_remote_retrieve_body( $resp ), true );

	return new WP_REST_Response( [
		'balance' => is_array( $data ) ? (int) ( $data['balance'] ?? 0 ) : null,
	] );
}

/* ============================================================
   HELPER: otorgar créditos una sola vez por referencia
   Lo usa el newsletter (suscripción y créditos semanales). Misma
   lógica que oec_credits_handler(): si ya existe un movimiento con esa
   referencia no se vuelve a otorgar.

   @return array{granted: bool, balance: int}|WP_Error
   ============================================================ */
function oec_credits_grant_once( string $email, int $amount, string $ref ) {
	$api_key = oec_credits_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'credits_not_configured', 'Créditos no configurados.' );
	}

	$check = wp_remote_get(
		'https://api.onlineeducation.center/contable/api/points/' . rawurlencode( $email ) . '/details?reference=' . rawurlencode( $ref ),
		[ 'headers' => [ 'X-API-KEY' => $api_key ], 'timeout' => 25 ]
	);
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$check_data = json_decode( wp_remote_retrieve_body( $check ), true );
	if ( ! is_array( $check_data ) ) {
		return new WP_Error( 'invalid_response', 'Respuesta inválida de la API de créditos.' );
	}
	if ( ! empty( $check_data['details'] ) ) {
		return [ 'granted' => false, 'balance' => (int) ( $check_data['balance'] ?? 0 ) ];
	}

	$add = wp_remote_post( 'https://api.onlineeducation.center/contable/api/points', [
		'headers' => [ 'X-API-KEY' => $api_key, 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [ 'email' => $email, 'amount' => $amount, 'reference' => $ref ] ),
		'timeout' => 25,
	] );
	if ( is_wp_error( $add ) ) {
		return $add;
	}
	$add_data = json_decode( wp_remote_retrieve_body( $add ), true );
	if ( ( $add_data['result'] ?? '' ) !== 'OK' ) {
		return new WP_Error( 'grant_failed', sprintf(
			'No se pudieron otorgar los créditos (ref. %s): HTTP %d %s',
			$ref,
			wp_remote_retrieve_response_code( $add ),
			substr( wp_remote_retrieve_body( $add ), 0, 200 )
		) );
	}
	return [ 'granted' => true, 'balance' => (int) ( $add_data['balance'] ?? 0 ) ];
}

/**
 * ¿El email ya recibió créditos con esta referencia?
 *
 * @return bool|WP_Error
 */
function oec_credits_has_reference( string $email, string $ref ) {
	$res = wp_remote_get(
		'https://api.onlineeducation.center/contable/api/points/' . rawurlencode( $email ) . '/details?reference=' . rawurlencode( $ref ),
		[ 'headers' => [ 'X-API-KEY' => oec_credits_api_key() ], 'timeout' => 25 ]
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invalid_response', 'Respuesta inválida de la API de créditos.' );
	}
	return ! empty( $data['details'] );
}

/* ============================================================
   AUTO-CREAR PÁGINA AL ACTIVAR EL TEMA
   ============================================================ */

function oec_create_credits_page(): void {
	$slug = 'creditos-por-descuentos';

	if ( get_page_by_path( $slug ) ) {
		return;
	}

	$page_id = wp_insert_post( [
		'post_title'   => 'Créditos por Descuentos',
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_author'  => 1,
	] );

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_wp_page_template', 'page-creditos.php' );
	}
}
add_action( 'after_switch_theme', 'oec_create_credits_page' );

// Also runs once on admin_init so an already-active theme creates the page on next admin load.
add_action( 'admin_init', function () {
	if ( get_transient( 'oec_credits_page_created' ) ) {
		return;
	}
	oec_create_credits_page();
	set_transient( 'oec_credits_page_created', true, YEAR_IN_SECONDS );
} );

/* ============================================================
   [oec-credits-widget] — mismo flujo de 3 estados (formulario /
   cargando / balance) que /creditos-por-descuentos, en formato
   compacto para insertar en un hero u otra sección. Usa las MISMAS
   claves de localStorage/sessionStorage que esa página y que el
   badge de créditos del header (header.php), así quedan todos
   sincronizados sin recargar la página.

   [oec-credits-widget tematica="nutricion-deportiva"]: en una landing, la
   invitación al newsletter es la de su temática (su lista de Elastic
   Email, oec_nl_list_for_tematica()) en vez de la general.
   ayuda="#como-se-usan": a dónde lleva "¿Cómo se usan?" (por defecto, la
   página de créditos; en esa misma página, a su sección).
   newsletter="no": sin la invitación ni el recordatorio semanal del
   newsletter (la página de créditos los tiene en su propia sección).
   ============================================================ */
add_shortcode( 'oec-credits-widget', 'oec_render_credits_widget_shortcode' );
function oec_render_credits_widget_shortcode( $atts = [] ): string {
	$atts     = shortcode_atts( [ 'tematica' => '', 'ayuda' => '/creditos-por-descuentos/', 'newsletter' => 'si' ], (array) $atts, 'oec-credits-widget' );
	$endpoint = esc_url( rest_url( 'oec/v1/credits' ) );
	$nonce    = wp_create_nonce( 'wp_rest' );
	ob_start();
	?>
	<div class="oec-credits-widget">
		<div class="oec-credits-widget__state oec-credits-widget__state--form" data-state="form">
			<span class="oec-credits-widget__badge"><i class="bi bi-gift" aria-hidden="true"></i> 50 créditos de regalo</span>
			<h3><?php printf( esc_html__( '¿Ya sos parte de %s?', 'oec-theme' ), esc_html( get_bloginfo( 'name' ) ) ); ?></h3>
			<p>Ingresá tu email y recibí tus créditos de bienvenida, o consultá tu balance actual.</p>
			<form class="oec-credits-widget__form">
				<div class="oec-credits-widget__input-group">
					<input type="email" placeholder="tu@email.com" autocomplete="email" required>
					<button type="submit">Verificar <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
				</div>
			</form>
			<p class="oec-credits-widget__error" hidden></p>
			<p class="oec-credits-widget__hint"><i class="bi bi-shield-lock" aria-hidden="true"></i> Tu email solo se usa para identificar tu cuenta.</p>
		</div>
		<div class="oec-credits-widget__state oec-credits-widget__state--loading" data-state="loading" hidden>
			<i class="bi bi-arrow-repeat oec-credits-widget__spinner" aria-hidden="true"></i>
			<p>Consultando tu cuenta…</p>
		</div>
		<div class="oec-credits-widget__state oec-credits-widget__state--balance" data-state="balance" hidden>
			<span class="oec-credits-widget__badge"><i class="bi bi-person-check" aria-hidden="true"></i> ¡Hola de nuevo!</span>
			<div class="oec-credits-widget__balance-value"><span class="oec-credits-widget__balance-num">0</span> créditos</div>
			<p class="oec-credits-widget__balance-msg">Canjealos por un descuento en tu próxima formación.</p>
			<div class="oec-credits-widget__actions">
				<?php // Secundarios a propósito: las formaciones ya están debajo del hero. ?>
				<a href="<?php echo esc_url( 0 === strpos( $atts['ayuda'], '#' ) ? $atts['ayuda'] : home_url( user_trailingslashit( untrailingslashit( $atts['ayuda'] ) ) ) ); ?>" class="oec-credits-widget__btn oec-credits-widget__btn--ghost oec-credits-widget__btn--quiet"><i class="bi bi-question-circle" aria-hidden="true"></i> ¿Cómo se usan?</a>
				<button type="button" class="oec-credits-widget__btn oec-credits-widget__btn--ghost oec-credits-widget__btn--quiet" data-action="reset">Otro email</button>
			</div>
			<?php
			// Invitación al newsletter (+50 créditos). La completa y la
			// muestra assets/js/newsletter.js si el email no está suscripto.
			if ( 'no' !== $atts['newsletter'] && function_exists( 'oec_nl_render_offer' ) ) {
				$nl_list = $atts['tematica'] ? oec_nl_list_for_tematica( sanitize_title( $atts['tematica'] ) ) : '';
				echo oec_nl_render_offer( $nl_list ?: OEC_NL_GENERAL_LIST ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	</div>
	<script>
	(function () {
		function initCreditsWidget(root) {
			const ENDPOINT = <?php echo wp_json_encode( $endpoint ); ?>;
			const BALANCE_ENDPOINT = <?php echo wp_json_encode( esc_url_raw( rest_url( 'oec/v1/credits/balance' ) ) ); ?>;
			const NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
			const LS_EMAIL = 'userEmail';
			const SS_CREDITS = 'userCredits';
			const LS_CREDITS_FALLBACK = 'oec_credits_balance';

			const states = {
				form: root.querySelector('[data-state="form"]'),
				loading: root.querySelector('[data-state="loading"]'),
				balance: root.querySelector('[data-state="balance"]'),
			};
			function showState(name) {
				Object.keys(states).forEach(k => { states[k].hidden = k !== name; });
			}
			function showBalance(balance, message) {
				root.querySelector('.oec-credits-widget__balance-num').textContent = parseInt(balance, 10).toLocaleString('es-AR');
				if (message) root.querySelector('.oec-credits-widget__balance-msg').textContent = message;
				showState('balance');
			}
			function showError(msg) {
				const err = root.querySelector('.oec-credits-widget__error');
				err.textContent = msg;
				err.hidden = false;
			}

			// El usuario se identifica por el email (mismo criterio que el badge
			// del header): con email y saldo guardados, directo al saldo; con
			// email pero sin saldo (sesión nueva, o lo borró otra pantalla), se
			// vuelve a consultar — sin otorgar nada — en vez de pedir el email.
			try {
				const savedEmail = localStorage.getItem(LS_EMAIL);
				const savedBalance = sessionStorage.getItem(SS_CREDITS) || localStorage.getItem(LS_CREDITS_FALLBACK);
				if (savedEmail && savedBalance !== null && savedBalance !== '') {
					showBalance(savedBalance, 'Canjealos por un descuento en tu próxima formación.');
				} else if (savedEmail) {
					showState('loading');
					fetch(BALANCE_ENDPOINT, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
						body: JSON.stringify({ email: savedEmail }),
					}).then(r => r.json()).then(data => {
						if (data.balance === null || data.balance === undefined) throw new Error('sin saldo');
						try {
							sessionStorage.setItem(SS_CREDITS, String(data.balance));
							localStorage.setItem(LS_CREDITS_FALLBACK, String(data.balance));
						} catch (_e) {}
						showBalance(data.balance, 'Canjealos por un descuento en tu próxima formación.');
						window.dispatchEvent(new CustomEvent('oec:credits-updated', { detail: { balance: data.balance } }));
					}).catch(() => {
						// Sin respuesta: el formulario, con el email ya cargado.
						root.querySelector('.oec-credits-widget__form input[type="email"]').value = savedEmail;
						showState('form');
					});
				}
			} catch (_e) {}

			const form = root.querySelector('.oec-credits-widget__form');
			form.addEventListener('submit', async function (e) {
				e.preventDefault();
				const input = form.querySelector('input[type="email"]');
				const email = input.value.trim();
				if (!email) return;
				root.querySelector('.oec-credits-widget__error').hidden = true;
				showState('loading');
				try {
					const res = await fetch(ENDPOINT, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
						body: JSON.stringify({ email }),
					});
					const data = await res.json();
					if (data.error) {
						showState('form');
						showError('No pudimos verificar tu cuenta. Intentá de nuevo en un momento.');
						return;
					}
					try {
						localStorage.setItem(LS_EMAIL, email);
						sessionStorage.setItem(SS_CREDITS, String(data.balance));
						localStorage.setItem(LS_CREDITS_FALLBACK, String(data.balance));
					} catch (_e) {}
					window.dispatchEvent(new CustomEvent('oec:credits-updated', { detail: { balance: data.balance } }));
					showBalance(data.balance, data.message);
				} catch (_err) {
					showState('form');
					showError('Error de conexión. Intentá de nuevo.');
				}
			});

			const resetBtn = root.querySelector('[data-action="reset"]');
			resetBtn.addEventListener('click', function () {
				try {
					localStorage.removeItem(LS_EMAIL);
					sessionStorage.removeItem(SS_CREDITS);
					localStorage.removeItem(LS_CREDITS_FALLBACK);
				} catch (_e) {}
				window.dispatchEvent(new CustomEvent('oec:credits-reset'));
				form.reset();
				showState('form');
			});
		}

		function init() {
			document.querySelectorAll('.oec-credits-widget').forEach(initCreditsWidget);
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
	</script>
	<?php
	return ob_get_clean();
}
