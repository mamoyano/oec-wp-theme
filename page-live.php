<?php
/**
 * Template: LIVE — /especiales/live (ver inc/live.php).
 * WordPress la elige sola para la página con slug "live" (la crea
 * oec_create_live_page()). Formaciones SYNC y MIXED con relevancia 2,
 * agrupadas por temática, con el video del corazón en el hero.
 */

$oec_live_rows   = oec_live_formaciones();
$oec_live_grupos = oec_live_grupos( $oec_live_rows );
$oec_live_docs   = oec_live_docentes( $oec_live_rows );
$oec_live_hoy    = current_time( 'Y-m-d' );
// Las que todavía no empezaron (ya vienen ordenadas por fecha de inicio).
$oec_live_pronto = array_slice( array_values( array_filter( $oec_live_rows, fn( $r ) => $r['start'] && $r['start'] >= $oec_live_hoy ) ), 0, 12 );
$oec_live_img    = OEC_THEME_URI . '/assets/img/live';
$oec_live_c      = OEC_LIVE_COLORS;

$oec_live_faq = [
	[
		__( '¿Qué es LIVE?', 'oec-theme' ),
		__( 'Es la selección de nuestras mejores formaciones con docentes en vivo: clases sincrónicas donde podés preguntar, debatir y escuchar casos reales de quienes trabajan en la élite del deporte, la nutrición y la fisiología. Solo entran las formaciones con la máxima calificación de la plataforma.', 'oec-theme' ),
	],
	[
		__( '¿Qué diferencia hay entre una formación sincrónica y una mixta?', 'oec-theme' ),
		__( 'En una formación 100% sincrónica todas las clases son en vivo, en días y horarios fijos. En una mixta se combinan clases en vivo con material para ver a tu ritmo. En las dos tenés contacto directo con los docentes.', 'oec-theme' ),
	],
	[
		__( '¿Por qué en vivo?', 'oec-theme' ),
		__( 'Porque buena parte de lo que saben los mejores profesionales sale de su práctica diaria y todavía no está escrito: no lo vas a encontrar en un paper ni en una inteligencia artificial. En una clase en vivo te lo cuentan ellos, con sus casos, sus errores y sus decisiones.', 'oec-theme' ),
	],
	[
		__( '¿Qué pasa si no puedo conectarme a alguna clase?', 'oec-theme' ),
		__( 'Cada formación detalla en su ficha, en «Metodología y horarios», los días y horarios de las clases. Todas las clases en vivo, las consultas de tus compañeros y toda la interacción online quedan grabadas para que las reproduzcas cuantas veces quieras, hasta un año después de terminada la formación.', 'oec-theme' ),
	],
];

add_filter( 'oec_seo', function ( $c ) use ( $oec_live_rows, $oec_live_img ) {
	return array_merge( $c, [
		'title'       => __( 'LIVE: formaciones en vivo con docentes de élite', 'oec-theme' ),
		'description' => sprintf( __( 'Made for humans, by humans: %d formaciones en vivo con docentes de la élite del deporte, la nutrición y la fisiología. Experiencia que todavía no está escrita: ni en papers ni en la IA.', 'oec-theme' ), count( $oec_live_rows ) ),
		'image'       => $oec_live_img . '/og.jpg',
	] );
} );

get_header();
?>
<main id="main-content" class="oec-live" style="--live-accent: <?php echo esc_attr( $oec_live_c['accent'] ); ?>; --live-dark: <?php echo esc_attr( $oec_live_c['dark'] ); ?>; --live-dark-2: <?php echo esc_attr( $oec_live_c['dark-2'] ); ?>;">

	<?php /* ── HERO: globos con nuestros docentes dando clase, que aparecen y se desvanecen en la mitad derecha (main.js) ── */ ?>
	<section class="oec-live-hero">
		<?php echo oec_live_globos_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
		<div class="oec-live-wrap oec-live-hero__inner">
			<?php oec_breadcrumb( [ [ __( 'Especiales', 'oec-theme' ), home_url( user_trailingslashit( '/especiales' ) ) ], [ 'LIVE' ] ] ); ?>
			<?php echo oec_live_logo( 'oec-live-hero__logo' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fijo ?>
			<h1 class="oec-live-hero__title"><?php esc_html_e( 'Formaciones en vivo con docentes de élite', 'oec-theme' ); ?></h1>
			<p class="oec-live-hero__claim">Made for humans, by humans.</p>
			<p class="oec-live-hero__lead"><?php esc_html_e( 'Clases en vivo con quienes están en la élite mundial del entrenamiento, la nutrición y la fisiología. Lo que saben de su práctica diaria todavía no está escrito: no lo vas a encontrar en un paper ni en una inteligencia artificial.', 'oec-theme' ); ?></p>
			<div class="oec-live-hero__actions">
				<a class="oec-live-btn" href="#live-tematicas"><?php esc_html_e( 'Ver formaciones LIVE', 'oec-theme' ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				<a class="oec-live-btn oec-live-btn--ghost" href="#live-que-es"><?php esc_html_e( '¿Qué es LIVE?', 'oec-theme' ); ?></a>
			</div>
			<?php if ( $oec_live_rows ) : ?>
			<ul class="oec-live-hero__stats">
				<li><strong><?php echo esc_html( number_format_i18n( count( $oec_live_rows ) ) ); ?></strong> <?php echo esc_html( _n( 'formación en vivo', 'formaciones en vivo', count( $oec_live_rows ), 'oec-theme' ) ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( count( $oec_live_docs ) ) ); ?></strong> <?php esc_html_e( 'docentes', 'oec-theme' ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( count( array_filter( $oec_live_grupos, fn( $g ) => '' !== $g['tematica'] ) ) ) ); ?></strong> <?php esc_html_e( 'temáticas', 'oec-theme' ); ?></li>
			</ul>
			<?php endif; ?>
		</div>
	</section>

	<?php /* ── QUÉ ES LIVE: el manifiesto en tres ideas ── */ ?>
	<section class="oec-live-block" id="live-que-es">
		<div class="oec-live-wrap">
			<p class="oec-live-eyebrow"><span class="oec-live-dot" aria-hidden="true"></span> <?php esc_html_e( 'Por qué en vivo', 'oec-theme' ); ?></p>
			<h2 class="oec-live-h2"><?php esc_html_e( 'Lo que no está escrito en ningún lado', 'oec-theme' ); ?></h2>
			<div class="oec-live-pilares">
				<div class="oec-live-pilar">
					<i class="bi bi-person-video3" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'De persona a persona', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Docentes con años de trabajo en equipos, laboratorios y clínicas, frente a vos. Conocimiento transmitido como siempre se transmitió: de quien ya lo vivió a quien lo está por vivir.', 'oec-theme' ); ?></p>
				</div>
				<div class="oec-live-pilar">
					<i class="bi bi-journal-x" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Experiencia que no llegó a los papers', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Los mejores profesionales están ocupados haciendo, no publicando. Sus decisiones, sus casos y sus errores tardan años en llegar a un paper, a internet o a una IA, si es que llegan.', 'oec-theme' ); ?></p>
				</div>
				<div class="oec-live-pilar">
					<i class="bi bi-chat-heart" aria-hidden="true"></i>
					<h3><?php esc_html_e( 'Preguntás, debatís, te responden', 'oec-theme' ); ?></h3>
					<p><?php esc_html_e( 'Clases sincrónicas donde traés tus dudas y tus casos. La experiencia, las anécdotas y la emoción de aprender con otros no se descargan: se viven.', 'oec-theme' ); ?></p>
				</div>
			</div>
		</div>
	</section>

	<?php if ( $oec_live_pronto ) : ?>
	<?php /* ── PRÓXIMOS INICIOS ── */ ?>
	<section class="oec-live-block oec-live-block--alt">
		<div class="oec-live-wrap">
			<p class="oec-live-eyebrow"><span class="oec-live-dot" aria-hidden="true"></span> <?php esc_html_e( 'Próximos inicios', 'oec-theme' ); ?></p>
			<h2 class="oec-live-h2"><?php esc_html_e( 'Arrancan pronto', 'oec-theme' ); ?></h2>
			<?php echo oec_tira_html( $oec_live_pronto, [ 'almanaque' => true, 'more_url' => home_url( '/formaciones?oec_sync=SYNC&oec_order=start_date' ), 'more_label' => __( 'Ver todas las formaciones 100% en vivo', 'oec-theme' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
		</div>
	</section>
	<?php endif; ?>

	<?php /* ── POR TEMÁTICA ── */ ?>
	<section class="oec-live-block" id="live-tematicas">
		<div class="oec-live-wrap">
			<p class="oec-live-eyebrow"><span class="oec-live-dot" aria-hidden="true"></span> <?php esc_html_e( 'Por temática', 'oec-theme' ); ?></p>
			<h2 class="oec-live-h2"><?php esc_html_e( 'Lo mejor de cada especialidad, en vivo', 'oec-theme' ); ?></h2>
			<?php if ( ! $oec_live_grupos ) : ?>
			<p class="oec-live-vacio"><?php esc_html_e( 'Estamos sumando nuevas formaciones LIVE. Mientras tanto, mirá todas las formaciones en vivo.', 'oec-theme' ); ?> <a href="<?php echo esc_url( home_url( '/formaciones?oec_sync=SYNC' ) ); ?>"><?php esc_html_e( 'Ver formaciones en vivo', 'oec-theme' ); ?></a></p>
			<?php endif; ?>
			<?php foreach ( $oec_live_grupos as $g ) : ?>
			<div class="oec-live-grupo" style="--grupo-accent: <?php echo esc_attr( $g['accent'] ); ?>;">
				<div class="oec-live-grupo__head">
					<h3 class="oec-live-grupo__title"><span class="oec-live-grupo__dot" aria-hidden="true"></span><?php echo esc_html( $g['title'] ); ?> <span class="oec-live-grupo__n"><?php echo esc_html( sprintf( _n( '%d formación', '%d formaciones', count( $g['rows'] ), 'oec-theme' ), count( $g['rows'] ) ) ); ?></span></h3>
					<?php if ( $g['url'] ) : ?><a class="oec-live-grupo__more" href="<?php echo esc_url( $g['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Especial de %s', 'oec-theme' ), $g['title'] ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
				</div>
				<?php echo oec_tira_html( $g['rows'], [] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			</div>
			<?php endforeach; ?>
		</div>
	</section>

	<?php if ( $oec_live_docs ) : ?>
	<?php /* ── QUIÉNES ENSEÑAN: todos del mismo tamaño ── */ ?>
	<section class="oec-live-block oec-live-block--dark">
		<div class="oec-live-wrap">
			<p class="oec-live-eyebrow"><span class="oec-live-dot" aria-hidden="true"></span> <?php esc_html_e( 'Quiénes enseñan', 'oec-theme' ); ?></p>
			<h2 class="oec-live-h2"><?php esc_html_e( 'Humanos que ya la vivieron', 'oec-theme' ); ?></h2>
			<ul class="oec-live-docentes">
				<?php foreach ( array_slice( $oec_live_docs, 0, 21 ) as $d ) :
					$tag = $d['url'] ? 'a' : 'div';
					?>
				<li>
					<<?php echo $tag; // phpcs:ignore ?> class="oec-live-docente"<?php echo $d['url'] ? ' href="' . esc_url( $d['url'] ) . '"' : ''; ?>>
						<span class="oec-live-docente__foto">
							<?php if ( $d['photo'] ) : ?>
							<img src="<?php echo esc_url( oec_docente_photo_url( $d['photo'], 240 ) ); ?>" alt="" width="120" height="120" loading="lazy" decoding="async">
							<?php else : ?>
							<span aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $d['name'], 0, 1 ) ) ); ?></span>
							<?php endif; ?>
						</span>
						<strong><?php echo esc_html( $d['name'] ); ?></strong>
						<?php if ( $d['area'] ) : ?><span class="oec-live-docente__area"><?php echo esc_html( $d['area'] ); ?></span><?php endif; ?>
					</<?php echo $tag; // phpcs:ignore ?>>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $oec_live_docs ) > 21 ) : ?>
			<p class="oec-live-docentes__mas"><?php echo esc_html( sprintf( _n( 'y %d docente más en estas formaciones.', 'y %d docentes más en estas formaciones.', count( $oec_live_docs ) - 21, 'oec-theme' ), count( $oec_live_docs ) - 21 ) ); ?></p>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php /* ── PREGUNTAS FRECUENTES (+ FAQPage con el mismo texto visible) ── */ ?>
	<section class="oec-live-block">
		<div class="oec-live-wrap oec-live-faq">
			<h2 class="oec-live-h2"><?php esc_html_e( 'Preguntas frecuentes', 'oec-theme' ); ?></h2>
			<?php foreach ( $oec_live_faq as $i => [ $q, $a ] ) : ?>
			<details class="oec-faq-item"<?php echo 0 === $i ? ' open' : ''; ?>>
				<summary><?php echo esc_html( $q ); ?></summary>
				<p><?php echo esc_html( $a ); ?></p>
			</details>
			<?php endforeach; ?>
		</div>
		<script type="application/ld+json"><?php echo wp_json_encode( [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map( fn( $qa ) => [
				'@type'          => 'Question',
				'name'           => $qa[0],
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $qa[1] ],
			], $oec_live_faq ),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
	</section>

	<?php /* ── CIERRE ── */ ?>
	<section class="oec-live-block oec-live-block--dark oec-live-cierre">
		<div class="oec-live-wrap">
			<?php echo oec_live_logo( 'oec-live-cierre__logo' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fijo ?>
			<h2 class="oec-live-h2"><?php esc_html_e( '¿Buscás más formaciones en vivo?', 'oec-theme' ); ?></h2>
			<p><?php esc_html_e( 'En el catálogo completo podés filtrar todas las formaciones por sincronicidad, temática y fecha de inicio.', 'oec-theme' ); ?></p>
			<div class="oec-live-hero__actions">
				<a class="oec-live-btn" href="<?php echo esc_url( home_url( '/formaciones?oec_sync=SYNC' ) ); ?>"><?php esc_html_e( '100% en vivo', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				<a class="oec-live-btn oec-live-btn--ghost" href="<?php echo esc_url( home_url( '/formaciones?oec_sync=MIXED' ) ); ?>"><?php esc_html_e( 'Mixtas (en vivo + a tu ritmo)', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
