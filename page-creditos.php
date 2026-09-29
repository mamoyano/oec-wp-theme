<?php
/**
 * Template Name: Créditos por Descuentos
 *
 * /creditos-por-descuentos/ — qué son los créditos, cómo se consiguen,
 * cómo se usan y la invitación al newsletter general (créditos todas las
 * semanas). Modelo: 1 crédito por cada dólar invertido (la forma
 * principal); los créditos se cambian por descuentos en la ficha de cada
 * formación (box "Canjea créditos por descuentos" del plugin: pedido →
 * email de confirmación → código de descuento → se pega al inscribirse).
 * Los regalos (bienvenida, newsletter) ayudan a acumular más rápido. El saldo lo consulta el mismo [oec-credits-widget] de los
 * heroes (mismas claves de storage que el badge del header y la ficha de
 * formación), así que no hay un segundo formulario que mantener. Los
 * montos salen de las constantes del newsletter: si cambian, la página
 * los sigue. Los porcentajes de descuento no se nombran a propósito:
 * cambian según la formación y la época.
 */

// Description / Open Graph (inc/seo.php): el título y la bajada del hero.
add_filter( 'oec_seo', function ( $c ) {
	return is_array( $c ) ? array_merge( $c, [
		'title'       => __( 'Sumá créditos y pagá menos por tu próxima formación', 'oec-theme' ),
		'description' => __( 'Por cada dólar que invertís en formaciones sumás 1 crédito, y con tus créditos «comprás» descuentos para las próximas. Para que acumules más rápido, te regalamos créditos de bienvenida y con el newsletter.', 'oec-theme' ),
	] ) : $c;
} );

get_header();

$oec_nl_on   = function_exists( 'oec_nl_api_key' ) && oec_nl_api_key();
$oec_sub     = defined( 'OEC_NL_SUBSCRIBE_CREDITS' ) ? (int) OEC_NL_SUBSCRIBE_CREDITS : 50;
$oec_weekly  = defined( 'OEC_NL_WEEKLY_CREDITS' ) ? (int) OEC_NL_WEEKLY_CREDITS : 20;
$oec_days    = defined( 'OEC_NL_CLAIM_DAYS' ) ? (int) OEC_NL_CLAIM_DAYS : 14;
$oec_weekday = function_exists( 'oec_nl_weekday_plural' ) ? oec_nl_weekday_plural() : 'lunes';
$oec_esp     = function_exists( 'oec_get_especiales_list' ) ? oec_get_especiales_list() : [];
$oec_welcome = 50; // bienvenida: la otorga oec_credits_handler()

$oec_faqs = [
	[
		'¿Qué son los créditos?',
		'Son un saldo a tu favor asociado a tu email: por cada dólar (o su equivalente) que invertís en formaciones sumás 1 crédito. Después los cambiás por descuentos: con tus créditos «comprás» un descuento para tu próxima formación.',
	],
	[
		'¿Cuánto descuento obtengo con mis créditos?',
		'Depende de cada formación. En su ficha vas a ver los descuentos disponibles y cuántos créditos cuesta cada uno. Los porcentajes cambian según la formación y la época del año, y si la formación ya tiene un descuento igual o mayor vigente, no hace falta canjear créditos.',
	],
	[
		'¿Puedo usar créditos en cualquier formación?',
		'En las formaciones que tienen habilitado el canje. Al entrar a cada ficha vas a ver si acepta créditos y qué descuentos ofrece.',
	],
	[
		'¿Cómo aplico el descuento al inscribirme?',
		'Cuando confirmás el canje desde el email que te enviamos, se descuentan los créditos y recibís un código de descuento (en pantalla y por email). Iniciá la inscripción y, en el resumen de compra, pegalo en el campo «Código de descuento» y presioná «Aplicar».',
	],
	[
		'¿Qué pasa si no confirmo el email del canje?',
		'No se descuenta nada de tu saldo. El link de confirmación es de un solo uso y vence a los 30 minutos: si venció, pedí el descuento de nuevo desde la ficha de la formación.',
	],
	[
		'¿Los créditos vencen?',
		'No. Tus créditos no tienen fecha de vencimiento y se mantienen en tu cuenta.',
	],
	[
		'¿Cómo consigo créditos todas las semanas?',
		sprintf( 'Suscribiéndote al newsletter: cada %1$s te llega con un botón para sumar %2$d créditos. Tenés %3$d días desde el envío para reclamarlos.', $oec_weekday, $oec_weekly, $oec_days ),
	],
	[
		'Si me suscribo a varios newsletters, ¿sumo más?',
		sprintf( 'Sí: cada newsletter (el general y los de cada temática) suma %1$d créditos la primera vez que te suscribís. Los %2$d créditos semanales son uno por semana, llegue el newsletter que llegue.', $oec_sub, $oec_weekly ),
	],
	[
		'No encuentro el newsletter de esta semana, ¿qué hago?',
		'Ingresá tu email arriba en esta página: si tenés créditos semanales sin reclamar, te ofrecemos reenviarte el newsletter. Revisá también las carpetas de spam y promociones.',
	],
	[
		'¿Dónde veo mi saldo?',
		'Arriba en esta página, en el encabezado del sitio (una vez que ingresaste tu email) y en la ficha de cada formación, en el box «Canjea créditos por descuentos».',
	],
];
?>

<main id="main-content" class="cred-page">

	<?php /* ── HERO: explicación + saldo / bienvenida / newsletter ── */ ?>
	<section class="cred-hero" id="mi-saldo">
		<div class="container cred-hero__grid">
			<div class="cred-hero__content">
				<?php
				oec_breadcrumb( [
					[ __( 'Créditos', 'oec-theme' ) ],
				] );
				?>
				<span class="cred-eyebrow"><?php esc_html_e( 'Programa de créditos', 'oec-theme' ); ?></span>
				<h1><?php esc_html_e( 'Sumá créditos y pagá menos por tu próxima formación', 'oec-theme' ); ?></h1>
				<p class="cred-hero__lead"><?php esc_html_e( 'Por cada dólar que invertís en formaciones sumás 1 crédito, y con tus créditos «comprás» descuentos para las próximas. Para que acumules más rápido, te regalamos créditos de bienvenida y con el newsletter.', 'oec-theme' ); ?></p>
				<ul class="cred-hero__facts">
					<li><strong>1</strong><span><?php esc_html_e( 'por cada dólar invertido', 'oec-theme' ); ?></span></li>
					<li><strong>+<?php echo esc_html( $oec_welcome ); ?></strong><span><?php esc_html_e( 'de bienvenida', 'oec-theme' ); ?></span></li>
					<?php if ( $oec_nl_on ) : ?>
					<li><strong>+<?php echo esc_html( $oec_sub ); ?></strong><span><?php esc_html_e( 'al suscribirte al newsletter', 'oec-theme' ); ?></span></li>
					<li><strong>+<?php echo esc_html( $oec_weekly ); ?></strong><span><?php esc_html_e( 'todas las semanas', 'oec-theme' ); ?></span></li>
					<?php endif; ?>
				</ul>
			</div>
			<div class="cred-hero__widget">
				<?php echo oec_render_credits_widget_shortcode( [ 'ayuda' => '#como-se-usan', 'newsletter' => 'no' ] ); // el newsletter tiene su sección (#newsletter) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</section>

	<?php /* ── CÓMO SE CONSIGUEN ── */ ?>
	<section class="cred-section" id="como-se-consiguen">
		<div class="container">
			<div class="cred-head">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Cómo se consiguen', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Invertís, acumulás y te regalamos más', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'La forma principal es formarte: cada dólar invertido suma 1 crédito. Además, te regalamos créditos para que llegues antes a tu próximo descuento.', 'oec-theme' ); ?></p>
			</div>

			<div class="cred-ways">
				<article class="cred-way cred-way--main">
					<span class="cred-way__amount">1 <small><?php esc_html_e( 'crédito por USD', 'oec-theme' ); ?></small></span>
					<i class="bi bi-bag-check cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Con cada inscripción', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Por cada dólar (o su equivalente) que invertís en formaciones de la plataforma, sumás 1 crédito. Se acreditan solos en tu cuenta.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>"><?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				</article>

				<article class="cred-way">
					<span class="cred-way__amount">+<?php echo esc_html( $oec_welcome ); ?></span>
					<i class="bi bi-gift cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Regalo de bienvenida', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'La primera vez que ingresás tu email, acá o en el inicio del sitio, te regalamos tus primeros créditos.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="#mi-saldo"><?php esc_html_e( 'Ingresar mi email', 'oec-theme' ); ?> <i class="bi bi-arrow-up" aria-hidden="true"></i></a>
				</article>

				<?php if ( $oec_nl_on ) : ?>
				<article class="cred-way cred-way--featured">
					<span class="cred-way__amount">+<?php echo esc_html( $oec_sub ); ?></span>
					<i class="bi bi-envelope-heart cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Suscripción al newsletter', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Al confirmar tu suscripción desde el email que te enviamos, se acreditan en tu cuenta.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="#newsletter"><?php esc_html_e( 'Suscribirme', 'oec-theme' ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				</article>

				<article class="cred-way cred-way--featured">
					<span class="cred-way__amount">+<?php echo esc_html( $oec_weekly ); ?></span>
					<i class="bi bi-calendar-check cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Todas las semanas', 'oec-theme' ); ?></h3>
					<p><?php printf( esc_html__( 'Cada %1$s el newsletter trae el botón «Obtener mis créditos». Tenés %2$d días para reclamarlos.', 'oec-theme' ), esc_html( $oec_weekday ), (int) $oec_days ); ?></p>
					<a class="cred-way__link" href="#newsletter"><?php esc_html_e( 'Cómo funciona', 'oec-theme' ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				</article>

				<?php if ( $oec_esp ) : ?>
				<article class="cred-way">
					<span class="cred-way__amount">+<?php echo esc_html( $oec_sub ); ?> <small><?php esc_html_e( 'c/u', 'oec-theme' ); ?></small></span>
					<i class="bi bi-collection cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Newsletters por temática', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Cada especial tiene su propio newsletter. Suscribite desde su página y sumá créditos por cada uno.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="#tematicas"><?php printf( esc_html( _n( 'Ver %d temática', 'Ver las %d temáticas', count( $oec_esp ), 'oec-theme' ) ), count( $oec_esp ) ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				</article>
				<?php endif; ?>
				<?php endif; ?>

			</div>

			<?php // Franja a todo el ancho: entran todos los especiales que se sumen (oec_get_especiales_list()). ?>
			<?php if ( $oec_nl_on && $oec_esp ) : ?>
			<div class="cred-topics" id="tematicas">
				<div class="cred-topics__text">
					<strong><i class="bi bi-collection" aria-hidden="true"></i> <?php esc_html_e( 'Newsletters por temática', 'oec-theme' ); ?></strong>
					<span><?php printf( esc_html__( '+%d créditos por cada uno. Suscribite desde la página de cada especial.', 'oec-theme' ), (int) $oec_sub ); ?></span>
				</div>
				<div class="cred-topics__list">
					<?php foreach ( $oec_esp as $e ) : ?>
					<a class="cred-chip" href="<?php echo esc_url( $e['url'] ); ?>" style="--chip: <?php echo esc_attr( $e['accent'] ?? '' ); ?>"><?php echo esc_html( $e['title'] ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php /* ── NEWSLETTER GENERAL ── */ ?>
	<?php if ( $oec_nl_on ) : ?>
	<section class="cred-nl" id="newsletter">
		<div class="container cred-nl__grid">
			<div class="cred-nl__text">
				<span class="cred-eyebrow"><?php esc_html_e( 'Newsletter semanal', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Créditos gratis, todas las semanas', 'oec-theme' ); ?></h2>
				<p><?php printf( esc_html__( 'Suscribite y sumá %1$d créditos al confirmar. Después, cada %2$s, %3$d más con un solo clic.', 'oec-theme' ), (int) $oec_sub, esc_html( $oec_weekday ), (int) $oec_weekly ); ?></p>
				<ul class="cred-nl__list">
					<li><i class="bi bi-envelope-open" aria-hidden="true"></i> <?php printf( esc_html__( 'Un solo correo por semana, los %s.', 'oec-theme' ), esc_html( $oec_weekday ) ); ?></li>
					<li><i class="bi bi-journal-text" aria-hidden="true"></i> <?php esc_html_e( 'Artículos nuevos y formaciones seleccionadas.', 'oec-theme' ); ?></li>
					<li><i class="bi bi-hand-index-thumb" aria-hidden="true"></i> <?php printf( esc_html__( 'Botón «Obtener mis créditos»: +%d por semana.', 'oec-theme' ), (int) $oec_weekly ); ?></li>
					<li><i class="bi bi-x-circle" aria-hidden="true"></i> <?php esc_html_e( 'Te das de baja cuando quieras.', 'oec-theme' ); ?></li>
				</ul>
				<?php if ( function_exists( 'oec_nl_latest_url' ) ) : ?>
				<a class="cred-nl__latest" href="<?php echo esc_url( oec_nl_latest_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver el último newsletter', 'oec-theme' ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
				<?php endif; ?>
			</div>
			<?php
			// data-oec-nl-aware: si ya conocemos el email (localStorage, el mismo
			// del bloque de créditos) newsletter.js consulta /status y, si está
			// suscripto, cambia el formulario por "Ya estás suscripto" (+ el
			// recordatorio de los créditos de la semana si no los reclamó).
			?>
			<div class="cred-nl__form" data-oec-nl-aware data-list="<?php echo esc_attr( OEC_NL_GENERAL_LIST ); ?>" <?php echo oec_nl_endpoints_attrs(); // phpcs:ignore ?>>
				<?php
				echo oec_nl_render_form( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'title'  => sprintf( __( 'Quiero mis %d créditos', 'oec-theme' ), $oec_sub ),
					'text'   => __( 'Completá tus datos y confirmá desde el email que te enviamos.', 'oec-theme' ),
					'button' => __( 'Suscribirme', 'oec-theme' ),
					'topics' => 'no',
				] );
				?>
				<div class="cred-nl__subscribed" data-nl-subscribed hidden>
					<span class="cred-nl__check" aria-hidden="true"><i class="bi bi-envelope-check"></i></span>
					<h3><?php esc_html_e( '¡Ya estás suscripto!', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Recibís el newsletter en', 'oec-theme' ); ?> <strong data-nl-email></strong>.</p>
					<div class="cred-nl__weekly" data-nl-weekly hidden>
						<p><?php printf( esc_html__( 'Tenés %s sin reclamar del newsletter del', 'oec-theme' ), '<strong>+' . (int) $oec_weekly . ' ' . esc_html__( 'créditos', 'oec-theme' ) . '</strong>' ); // phpcs:ignore ?> <span data-nl-date></span>. <?php esc_html_e( 'Si no lo encontrás, te lo reenviamos.', 'oec-theme' ); ?></p>
						<button type="button" class="btn btn-primary" data-nl-resend><?php esc_html_e( 'Reenviámelo', 'oec-theme' ); ?> <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i></button>
					</div>
					<p class="cred-nl__next" data-nl-next><?php printf( esc_html__( 'Te esperamos el próximo %1$s con +%2$d créditos.', 'oec-theme' ), esc_html( in_array( $oec_weekday, [ 'sábados', 'domingos' ], true ) ? substr( $oec_weekday, 0, -1 ) : $oec_weekday ), (int) $oec_weekly ); ?></p>
					<p class="oec-nl__msg" data-nl-msg role="status" aria-live="polite" hidden></p>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php /* ── CÓMO SE USAN ── */ ?>
	<section class="cred-section cred-section--alt" id="como-se-usan">
		<div class="container cred-use">
			<div class="cred-use__steps">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Cómo se usan', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Cambialos por un descuento en cuatro pasos', 'oec-theme' ); ?></h2>
				<ol class="cred-steps">
					<li>
						<h3><?php esc_html_e( 'Entrá a la formación', 'oec-theme' ); ?></h3>
						<p><?php esc_html_e( 'En su ficha, junto al botón de inscripción, está el box «Canjea créditos por descuentos».', 'oec-theme' ); ?></p>
					</li>
					<li>
						<h3><?php esc_html_e( 'Elegí tu descuento', 'oec-theme' ); ?></h3>
						<p><?php esc_html_e( 'Con tu email ves tu saldo y los descuentos disponibles: cada uno indica cuántos créditos cuesta. Tocá «Obtener descuento».', 'oec-theme' ); ?></p>
					</li>
					<li>
						<h3><?php esc_html_e( 'Confirmá desde tu email', 'oec-theme' ); ?></h3>
						<p><?php esc_html_e( 'Te enviamos un link (vale 30 minutos). Al confirmar, se descuentan los créditos y recibís tu código de descuento.', 'oec-theme' ); ?></p>
					</li>
					<li>
						<h3><?php esc_html_e( 'Aplicalo al inscribirte', 'oec-theme' ); ?></h3>
						<p><?php esc_html_e( 'Iniciá la inscripción y, en el resumen de compra, pegá el código en «Código de descuento» y presioná «Aplicar».', 'oec-theme' ); ?></p>
					</li>
				</ol>
				<a class="btn btn-primary" href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>"><?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			</div>

			<figure class="cred-mock" aria-label="<?php esc_attr_e( 'Ejemplo del box de créditos en la ficha de una formación', 'oec-theme' ); ?>">
				<div class="cred-mock__card">
					<div class="cred-mock__head"><i class="bi bi-tags-fill" aria-hidden="true"></i> <?php esc_html_e( 'Canjea créditos por descuentos', 'oec-theme' ); ?></div>
					<p class="cred-mock__balance"><?php esc_html_e( 'Tienes', 'oec-theme' ); ?> <strong>190</strong> <?php esc_html_e( 'créditos', 'oec-theme' ); ?></p>
					<?php
					foreach ( [ [ 25, 175, true ], [ 20, 80, true ], [ 30, 210, false ] ] as [ $pct, $pts, $ok ] ) :
						?>
					<div class="cred-mock__opt<?php echo $ok ? '' : ' is-disabled'; ?>">
						<span class="cred-mock__pct"><?php echo (int) $pct; ?>%</span>
						<div>
							<p><?php printf( esc_html__( 'Canjea %1$s créditos por este %2$d%% de descuento.', 'oec-theme' ), '<strong>' . (int) $pts . '</strong>', (int) $pct ); // phpcs:ignore ?></p>
							<?php if ( $ok ) : ?>
							<span class="cred-mock__btn"><?php esc_html_e( 'Obtener descuento', 'oec-theme' ); ?></span>
							<?php else : ?>
							<small><?php esc_html_e( 'Créditos insuficientes.', 'oec-theme' ); ?></small>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<figcaption><?php esc_html_e( 'Ejemplo ilustrativo: los descuentos y los créditos que cuesta cada uno cambian según la formación.', 'oec-theme' ); ?></figcaption>
			</figure>
		</div>
	</section>

	<?php /* ── PREGUNTAS FRECUENTES ── */ ?>
	<section class="cred-section" id="preguntas">
		<div class="container">
			<div class="cred-head">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Preguntas frecuentes', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Todo sobre tus créditos', 'oec-theme' ); ?></h2>
			</div>
			<div class="cred-faq">
				<?php foreach ( $oec_faqs as [ $q, $a ] ) : ?>
				<details class="cred-faq__item">
					<summary><?php echo esc_html( $q ); ?></summary>
					<p><?php echo esc_html( $a ); ?></p>
				</details>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php /* ── CIERRE ── */ ?>
	<section class="cred-cta">
		<div class="container cred-cta__inner">
			<div>
				<h2><?php esc_html_e( 'Tus créditos te esperan en la ficha de cada formación', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'Cursos, talleres, diplomados y posgrados dictados por referentes internacionales.', 'oec-theme' ); ?></p>
			</div>
			<div class="cred-cta__actions">
				<a class="btn btn-primary" href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>"><?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				<?php if ( $oec_esp ) : ?>
				<a class="btn cred-cta__ghost" href="<?php echo esc_url( home_url( user_trailingslashit( '/especiales' ) ) ); ?>"><?php esc_html_e( 'Explorar especiales', 'oec-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</section>

</main>

<script type="application/ld+json">
<?php
echo wp_json_encode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	'@context'   => 'https://schema.org',
	'@type'      => 'FAQPage',
	'mainEntity' => array_map( fn( $f ) => [
		'@type'          => 'Question',
		'name'           => $f[0],
		'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $f[1] ],
	], $oec_faqs ),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
?>
</script>

<?php get_footer(); ?>
