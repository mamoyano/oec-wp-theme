<?php
/**
 * Template: Quiénes somos.
 * Se activa automáticamente para la página con slug "quienes-somos" (la crea
 * oec_create_quienes_somos_page(), más abajo en inc/template-functions.php).
 *
 * Contenido: el texto institucional de la página anterior de g-se.com, la
 * historia, misión, visión y valores de Online Education Center (resumidos
 * de onlineeducation.center/es/quienes-somos, que se enlaza para leerla
 * completa) y números que el sitio ya conoce (catálogo, docentes,
 * organizaciones y artículos), así nunca quedan desactualizados. Reusa los
 * estilos de /creditos-por-descuentos (.cred-*).
 *
 * Hero centrado (.page-hero), como el resto de las páginas sin contenido a
 * la derecha del hero; los heroes alineados a la izquierda son los que
 * llevan algo al costado (home, landings, créditos).
 */

$qs_formaciones = class_exists( 'OEC_AI_Catalog' ) ? count( OEC_AI_Catalog::get_index() ) : 0;
$qs_docentes    = function_exists( 'oec_docentes_catalog' ) ? count( oec_docentes_catalog() ) : 0;
$qs_orgs        = class_exists( 'OEC_AI_Catalog' ) ? count( OEC_AI_Catalog::get_organizations( 1 ) ) : 0;
$qs_articulos   = (int) wp_count_posts( 'post' )->publish;
$qs_whatsapp    = 'https://api.whatsapp.com/send?phone=5493512584960';
$qs_contact     = sanitize_key( wp_unslash( $_GET['contact'] ?? '' ) );
$qs_oec         = 'https://onlineeducation.center/es/quienes-somos';

$qs_historia = [
	[ '1997', __( 'A y S Preparación Física', 'oec-theme' ), __( 'Dos estudiantes del Instituto del Profesorado en Educación Física de Córdoba, Mario Agustín Moyano y Sebastián Del Rosso, crean un sitio para compartir gratis información sobre entrenamiento deportivo con todo el mundo de habla hispana.', 'oec-theme' ) ],
	[ '2000', __( 'Sobre Entrenamiento', 'oec-theme' ), __( 'Mario y Carlos Julio Moyano lanzan sobreentrenamiento.com, que reúne a los profesionales emergentes más prestigiosos de las ciencias del ejercicio de Argentina. Nacen PubliCE, las publicaciones, y el primer curso a distancia: Entrenamiento de la Fuerza y la Potencia, con Darío Cappa y Horacio Anselmi.', 'oec-theme' ) ],
	[ '2000s', __( 'Grupo Sobre Entrenamiento', 'oec-theme' ), __( 'Más de mil artículos, miles de alumnos y, con la sociedad entre María Celeste Pascale y Mario Agustín Moyano, simposios virtuales con conferencistas de todo el mundo y traducción simultánea.', 'oec-theme' ) ],
	[ '2012', __( 'Nace G-SE', 'oec-theme' ), __( 'Universidades e instituciones piden sumarse a la plataforma. Con Gustavo Burgi al frente de la tecnología, se abre el campus y el know how educativo a socios de todo el mundo, y nace G-SE en honor al viejo Grupo Sobre Entrenamiento.', 'oec-theme' ) ],
	[ '2023', __( 'Nuevas comunidades', 'oec-theme' ), __( 'Para profesionales cada vez más especializados, Online Education Center crea Traumato Site, Fisio One, Swimming Science y la International Society of Fitness, junto a G-SE.', 'oec-theme' ) ],
];
$qs_valores = [
	[ 'bi-people', __( 'Jugamos para un equipo', 'oec-theme' ), __( 'Nos ponemos la camiseta de nuestros socios educativos para armar juntos la mejor oferta académica y los mejores contenidos.', 'oec-theme' ) ],
	[ 'bi-person-heart', __( 'Y para el otro', 'oec-theme' ), __( 'Nos situamos del lado de los profesionales y alumnos: sus problemas son nuestros y sus soluciones, nuestra alegría.', 'oec-theme' ) ],
	[ 'bi-fire', __( 'Nos apasionamos', 'oec-theme' ), __( 'Somos fanáticos de la excelencia en nuestro trabajo y curiosos por el de todo el equipo.', 'oec-theme' ) ],
	[ 'bi-share', __( 'Compartimos de verdad', 'oec-theme' ), __( 'Casos de éxito, fracasos y metodologías con los socios; experiencias y opiniones reales con los futuros alumnos.', 'oec-theme' ) ],
	[ 'bi-lightbulb', __( 'Nos arriesgamos', 'oec-theme' ), __( 'La innovación es parte de nuestra historia: escuchamos, investigamos y después nos arriesgamos con inteligencia.', 'oec-theme' ) ],
	[ 'bi-shield-check', __( 'Somos honestos, siempre', 'oec-theme' ), __( 'Para negociar, informar, responder una consulta, comunicar y hasta para redactar estos valores.', 'oec-theme' ) ],
];
$qs_comunidades = [
	[ 'G-SE', __( 'Ciencias del ejercicio', 'oec-theme' ), '' ],
	[ 'Traumato Site', __( 'Traumatología', 'oec-theme' ), 'https://traumato.site/es' ],
	[ 'Fisio One', __( 'Fisioterapia', 'oec-theme' ), 'https://fisio.one/es' ],
	[ 'Swimming Science', __( 'Natación', 'oec-theme' ), 'https://swimming.science/es' ],
	[ 'International Society of Fitness', __( 'Fitness', 'oec-theme' ), 'https://is.fitness/es' ],
];

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

	<?php /* ── HERO (centrado: no lleva nada a la derecha) ── */ ?>
	<div class="page-hero page-hero--articulos qs-hero">
		<div class="container">
			<?php oec_breadcrumb( [ [ __( 'Quiénes somos', 'oec-theme' ) ] ] ); ?>
			<h1><?php esc_html_e( 'La comunidad de las ciencias del ejercicio en español', 'oec-theme' ); ?></h1>
			<p class="page-hero__lead"><?php esc_html_e( 'G-SE es una comunidad de profesionales de las ciencias del ejercicio físico con publicaciones recientes de journals, blogs, redes sociales y las mejores formaciones online del mundo de habla hispana.', 'oec-theme' ); ?></p>
			<ul class="cred-hero__facts qs-hero__facts">
				<li><strong><?php echo esc_html( (int) current_time( 'Y' ) - 1997 ); ?></strong><span><?php esc_html_e( 'años de trayectoria', 'oec-theme' ); ?></span></li>
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

	<?php /* ── NUESTRA HISTORIA ── */ ?>
	<section class="cred-section cred-section--alt" id="historia">
		<div class="container qs-story">
			<div class="qs-story__intro">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Nuestra historia', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Desde 1997 conectando profesionales del ejercicio con conocimiento de calidad', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'Empezamos cuando Internet era un directorio de Yahoo y el módem hacía ruidos extraños: con la misma pasión por compartir que hoy, de un sitio de estudiantes a una red de comunidades profesionales.', 'oec-theme' ); ?></p>
				<a class="cred-way__link" href="<?php echo esc_url( $qs_oec ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leer la historia completa', 'oec-theme' ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
			</div>
			<ol class="qs-timeline">
				<?php foreach ( $qs_historia as [ $anio, $titulo, $texto ] ) : ?>
				<li>
					<span class="qs-timeline__year"><?php echo esc_html( $anio ); ?></span>
					<h3><?php echo esc_html( $titulo ); ?></h3>
					<p><?php echo esc_html( $texto ); ?></p>
				</li>
				<?php endforeach; ?>
				<li class="qs-timeline__now">
					<span class="qs-timeline__year"><?php esc_html_e( 'Hoy', 'oec-theme' ); ?></span>
					<h3><?php esc_html_e( '¡Esto recién comienza!', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Un equipo en oficinas regionales, freelancers, asesores externos y un Comité Editorial internacional, con relaciones con instituciones como NSCA, ACSM, NASM, ISSA, ASEP, UCAM y UEMC.', 'oec-theme' ); ?></p>
				</li>
			</ol>
		</div>
	</section>

	<?php /* ── MISIÓN, VISIÓN Y VALORES ── */ ?>
	<section class="cred-section" id="valores">
		<div class="container">
			<div class="qs-mv">
				<div class="qs-mv__item">
					<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Misión', 'oec-theme' ); ?></span>
					<p><?php esc_html_e( 'Contribuir al desarrollo profesional de los usuarios y a la sustentabilidad de las organizaciones asociadas: para los profesionales, contenidos de calidad, interacción entre pares y programas educativos con docentes y certificaciones de alto impacto; para las organizaciones, una comunidad especializada, una solución integral y asesoramiento para sus capacitaciones a distancia.', 'oec-theme' ); ?></p>
				</div>
				<div class="qs-mv__item">
					<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Visión', 'oec-theme' ); ?></span>
					<p class="qs-mv__vision"><?php esc_html_e( 'Liderar la interacción digital entre usuarios y organizaciones generadoras de capacitación e información de calidad.', 'oec-theme' ); ?></p>
				</div>
			</div>
			<div class="cred-head qs-values-head">
				<span class="cred-eyebrow cred-eyebrow--dark"><?php esc_html_e( 'Valores', 'oec-theme' ); ?></span>
				<h2><?php esc_html_e( 'Cómo jugamos', 'oec-theme' ); ?></h2>
			</div>
			<div class="cred-ways qs-values">
				<?php foreach ( $qs_valores as [ $icono, $titulo, $texto ] ) : ?>
				<article class="cred-way">
					<i class="bi <?php echo esc_attr( $icono ); ?> cred-way__icon" aria-hidden="true"></i>
					<h3><?php echo esc_html( $titulo ); ?></h3>
					<p><?php echo esc_html( $texto ); ?></p>
				</article>
				<?php endforeach; ?>
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
					<a class="cred-way__link" href="<?php echo esc_url( $qs_oec ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Conocé al equipo', 'oec-theme' ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
				</article>
			</div>
			<div class="qs-communities">
				<p><?php esc_html_e( 'G-SE es una de las comunidades de Online Education Center:', 'oec-theme' ); ?></p>
				<ul>
					<?php foreach ( $qs_comunidades as [ $nombre, $area, $url ] ) : ?>
					<li>
						<?php if ( $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $nombre ); ?></strong> <span><?php echo esc_html( $area ); ?></span> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
						<?php else : ?>
						<span class="is-current"><strong><?php echo esc_html( $nombre ); ?></strong> <span><?php echo esc_html( $area ); ?></span></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
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
