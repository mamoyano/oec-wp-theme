<?php
/**
 * Template: Quiénes somos.
 * Se activa automáticamente para la página con slug "quienes-somos" (la crea
 * oec_create_quienes_somos_page(), más abajo en inc/template-functions.php).
 *
 * Contenido: el texto institucional de la página anterior de g-se.com, más
 * números que el sitio ya conoce (catálogo, docentes, organizaciones y
 * artículos), así nunca quedan desactualizados. Reusa los estilos de
 * /creditos-por-descuentos (.cred-*).
 */

$qs_formaciones = class_exists( 'OEC_AI_Catalog' ) ? count( OEC_AI_Catalog::get_index() ) : 0;
$qs_docentes    = function_exists( 'oec_docentes_catalog' ) ? count( oec_docentes_catalog() ) : 0;
$qs_orgs        = class_exists( 'OEC_AI_Catalog' ) ? count( OEC_AI_Catalog::get_organizations( 1 ) ) : 0;
$qs_articulos   = (int) wp_count_posts( 'post' )->publish;
$qs_whatsapp    = 'https://api.whatsapp.com/send?phone=5493512584960';
$qs_contact     = sanitize_key( wp_unslash( $_GET['contact'] ?? '' ) );

// Description / Open Graph (inc/seo.php): el título y la bajada del hero.
add_filter( 'oec_seo', function ( $c ) {
	return is_array( $c ) ? array_merge( $c, [
		'title'       => __( 'La comunidad de las ciencias del ejercicio en español', 'oec-theme' ),
		'description' => __( 'G-SE es una comunidad de profesionales de las ciencias del ejercicio físico con publicaciones recientes de journals, blogs, redes sociales y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ),
	] ) : $c;
} );

get_header();
?>

<main id="main-content" class="cred-page qs-page">

	<?php /* ── HERO ── */ ?>
	<section class="cred-hero">
		<div class="container cred-hero__grid">
			<div class="cred-hero__content">
				<?php oec_breadcrumb( [ [ __( 'Quiénes somos', 'oec-theme' ) ] ] ); ?>
				<span class="cred-eyebrow"><?php esc_html_e( 'Quiénes somos', 'oec-theme' ); ?></span>
				<h1><?php esc_html_e( 'La comunidad de las ciencias del ejercicio en español', 'oec-theme' ); ?></h1>
				<p class="cred-hero__lead"><?php esc_html_e( 'G-SE es una comunidad de profesionales de las ciencias del ejercicio físico con publicaciones recientes de journals, blogs, redes sociales y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ); ?></p>
				<ul class="cred-hero__facts">
					<?php if ( $qs_formaciones ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( $qs_formaciones ) ); ?></strong><span><?php esc_html_e( 'formaciones abiertas', 'oec-theme' ); ?></span></li>
					<?php endif; ?>
					<?php if ( $qs_docentes ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( $qs_docentes ) ); ?></strong><span><?php esc_html_e( 'docentes', 'oec-theme' ); ?></span></li>
					<?php endif; ?>
					<?php if ( $qs_orgs ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( $qs_orgs ) ); ?></strong><span><?php esc_html_e( 'organizaciones', 'oec-theme' ); ?></span></li>
					<?php endif; ?>
					<li><strong><?php echo esc_html( number_format_i18n( $qs_articulos ) ); ?></strong><span><?php esc_html_e( 'artículos publicados', 'oec-theme' ); ?></span></li>
				</ul>
			</div>
		</div>
	</section>

	<?php /* ── QUÉ VAS A ENCONTRAR ── */ ?>
	<section class="cred-section">
		<div class="container">
			<div class="cred-head">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Qué vas a encontrar', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Todo lo que un profesional del ejercicio necesita, en un solo lugar', 'oec-theme' ); ?></h2>
			</div>
			<div class="cred-ways">
				<article class="cred-way">
					<i class="bi bi-journal-text cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Publicaciones científicas', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Todas las publicaciones científicas de tu área, con lo más reciente de los journals explicado en español.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="<?php echo esc_url( oec_articulos_url( [] ) ); ?>"><?php esc_html_e( 'Ver artículos', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				</article>
				<article class="cred-way">
					<i class="bi bi-people cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Referentes y organizaciones', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Lo que publican en blogs y redes sociales los profesionales y organizaciones referentes de las ciencias del ejercicio.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="<?php echo esc_url( oec_articulos_url( [ 'tipo' => 'blogs' ] ) ); ?>"><?php esc_html_e( 'Ver blogs', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				</article>
				<article class="cred-way cred-way--main">
					<i class="bi bi-mortarboard cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Formaciones online', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Las mejores formaciones online de todo el mundo de habla hispana: cursos, talleres, posgrados y másteres.', 'oec-theme' ); ?></p>
					<a class="cred-way__link" href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>"><?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				</article>
			</div>
		</div>
	</section>

	<?php /* ── QUIÉNES ESTAMOS DETRÁS ── */ ?>
	<section class="cred-section cred-section--alt">
		<div class="container">
			<div class="cred-head">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Quiénes estamos detrás', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Una comunidad hecha por profesionales, para profesionales', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'G-SE nace de la asociación de dos equipos:', 'oec-theme' ); ?></p>
			</div>
			<div class="cred-ways qs-duo">
				<article class="cred-way">
					<i class="bi bi-globe-americas cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Un Comité Editorial internacional', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Entrenadores, investigadores, nutricionistas, preparadores físicos y docentes de todo el mundo.', 'oec-theme' ); ?></p>
				</article>
				<article class="cred-way">
					<i class="bi bi-laptop cred-way__icon" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Online Education Center', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'La empresa líder en educación online enfocada en el alumno, que desarrolla la plataforma.', 'oec-theme' ); ?></p>
				</article>
			</div>
			<p class="qs-legal">
				<?php
				printf(
					/* translators: 1: enlace a políticas de privacidad, 2: enlace a términos de uso */
					esc_html__( 'Como esta comunidad está desarrollada por Online Education Center, compartimos sus %1$s y %2$s.', 'oec-theme' ),
					'<a href="https://kb.onlineeducation.center/es/privacy/" target="_blank" rel="noopener">' . esc_html__( 'Políticas de Privacidad', 'oec-theme' ) . '</a>',
					'<a href="https://kb.onlineeducation.center/es/terms/" target="_blank" rel="noopener">' . esc_html__( 'Términos de Uso', 'oec-theme' ) . '</a>'
				);
				?>
			</p>
		</div>
	</section>

	<?php /* ── CONTACTO ── */ ?>
	<section class="cred-section" id="contacto">
		<div class="container qs-contact">
			<div class="qs-contact__intro">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Contacto', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( '¿Tenés una consulta?', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'Escribinos y te respondemos a la brevedad. Si preferís, también podés hablarnos por WhatsApp.', 'oec-theme' ); ?></p>
				<a class="btn btn-ghost qs-whatsapp" href="<?php echo esc_url( $qs_whatsapp ); ?>" target="_blank" rel="noopener">
					<i class="bi bi-whatsapp" aria-hidden="true"></i> <?php esc_html_e( 'Escribinos por WhatsApp', 'oec-theme' ); ?>
				</a>
			</div>

			<?php if ( 'success' === $qs_contact ) : ?>
			<div class="qs-form qs-form__done" role="status">
				<i class="bi bi-check-circle" aria-hidden="true"></i>
				<h3><?php esc_html_e( '¡Gracias! Recibimos tu mensaje.', 'oec-theme' ); ?></h3>
				<p><?php esc_html_e( 'Te vamos a responder por email a la brevedad.', 'oec-theme' ); ?></p>
			</div>
			<?php else : ?>
			<form class="qs-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php if ( 'error' === $qs_contact ) : ?>
				<p class="qs-form__error" role="alert"><?php esc_html_e( 'Revisá tu nombre y tu email e intentá de nuevo.', 'oec-theme' ); ?></p>
				<?php endif; ?>
				<input type="hidden" name="action" value="oec_contact_form">
				<?php wp_nonce_field( 'oec_contact_nonce', 'oec_nonce' ); ?>
				<label>
					<span><?php esc_html_e( 'Nombre', 'oec-theme' ); ?></span>
					<input type="text" name="cf_name" required autocomplete="name">
				</label>
				<label>
					<span><?php esc_html_e( 'Email', 'oec-theme' ); ?></span>
					<input type="email" name="cf_email" required autocomplete="email">
				</label>
				<label>
					<span><?php esc_html_e( 'Mensaje', 'oec-theme' ); ?></span>
					<textarea name="cf_message" rows="5" required></textarea>
				</label>
				<?php /* Trampa para bots: invisible para personas. */ ?>
				<label class="qs-form__hp" aria-hidden="true">
					<span>Web</span>
					<input type="text" name="cf_website" tabindex="-1" autocomplete="off">
				</label>
				<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Enviar mensaje', 'oec-theme' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
	</section>

</main>

<?php get_footer(); ?>
