<?php
/**
 * Template: Landing de un docente.
 * Se activa automáticamente para la página con slug "docente".
 * El docente a mostrar viaja en ?slug=... (ver inc/docentes.php).
 */

$oec_docente = oec_current_docente();

// Opiniones de alumnos de sus formaciones: las más completas primero.
$oec_docente_reviews = [];
if ( $oec_docente ) {
	foreach ( $oec_docente['items'] as $item ) {
		$f = OEC_AI_Catalog::get_formation( (string) $item['id'] );
		foreach ( $f['reviews'] ?? [] as $r ) {
			$comment = trim( preg_replace( '/\s+/', ' ', (string) ( $r['comment'] ?? '' ) ) );
			if ( (int) ( $r['rating'] ?? 0 ) >= 4 && mb_strlen( $comment ) >= 40 ) {
				$oec_docente_reviews[] = [
					'author'    => $r['author'] ?? '',
					'rating'    => (int) $r['rating'],
					'comment'   => $comment,
					'formacion' => $item['title'],
				];
			}
		}
	}
	usort( $oec_docente_reviews, fn( $a, $b ) => mb_strlen( $b['comment'] ) <=> mb_strlen( $a['comment'] ) );
	$oec_docente_reviews = array_slice( $oec_docente_reviews, 0, 6 );
}

$oec_abiertas = $oec_docente ? array_values( array_filter( $oec_docente['items'], fn( $i ) => $i['open'] ) ) : [];
$oec_cerradas = $oec_docente ? array_values( array_filter( $oec_docente['items'], fn( $i ) => ! $i['open'] ) ) : [];
usort( $oec_abiertas, fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ) );

get_header();
?>

<div class="page-hero page-hero--docente">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Formaciones', 'oec-theme' ), home_url( user_trailingslashit( '/formaciones' ) ) ],
			[ __( 'Docentes', 'oec-theme' ), oec_docentes_url() ],
			[ $oec_docente['name'] ?? __( 'Docente', 'oec-theme' ) ],
		], false );
		?>

		<?php if ( $oec_docente ) : ?>
		<div class="docente-hero">
			<img class="docente-hero__photo" src="<?php echo esc_url( oec_docente_photo_url( $oec_docente['photo'], 600 ) ); ?>" alt="<?php echo esc_attr( $oec_docente['name'] ); ?>" width="600" height="750">
			<div class="docente-hero__text">
				<span class="docente-hero__eyebrow"><?php esc_html_e( 'Docente', 'oec-theme' ); ?></span>
				<h1><?php echo esc_html( $oec_docente['name'] ); ?></h1>
				<?php if ( $oec_docente['background'] ) : ?>
				<p class="docente-hero__role"><?php echo esc_html( $oec_docente['background'] ); ?></p>
				<?php endif; ?>

				<ul class="docente-hero__stats">
					<?php if ( $oec_docente['alumnos'] > 0 ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( $oec_docente['alumnos'] ) ); ?></strong> <?php esc_html_e( 'alumnos formados', 'oec-theme' ); ?></li>
					<?php endif; ?>
					<li><strong><?php echo esc_html( number_format_i18n( $oec_docente['formaciones'] ) ); ?></strong> <?php echo esc_html( _n( 'formación dictada', 'formaciones dictadas', $oec_docente['formaciones'], 'oec-theme' ) ); ?></li>
					<?php if ( $oec_abiertas ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( count( $oec_abiertas ) ) ); ?></strong> <?php echo esc_html( _n( 'abierta ahora', 'abiertas ahora', count( $oec_abiertas ), 'oec-theme' ) ); ?></li>
					<?php endif; ?>
				</ul>

				<?php
				$oec_tematicas = array_intersect_key( OEC_DOCENTE_TEMATICAS, array_flip( $oec_docente['tags'] ) );
				if ( $oec_tematicas ) :
				?>
				<div class="docente-hero__tags">
					<?php foreach ( $oec_tematicas as $slug => $label ) : ?>
					<a class="docente-hero__tag" href="<?php echo esc_url( home_url( '/formaciones?oec_subject=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( $oec_abiertas ) : ?>
				<a class="btn docente-hero__cta" href="#docente-formaciones"><?php esc_html_e( 'Ver sus formaciones abiertas', 'oec-theme' ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				<?php endif; ?>
				<?php $oec_sitio = function_exists( 'oec_sitio_de' ) ? oec_sitio_de( 'docente', $oec_docente['slug'] ) : null; ?>
				<?php if ( $oec_sitio ) : ?>
				<a class="oec-visit-site" href="<?php echo esc_url( $oec_sitio['url'] ); ?>" target="_blank" rel="noopener">
					<i class="bi bi-globe2" aria-hidden="true"></i> <?php esc_html_e( 'Visitar su sitio web', 'oec-theme' ); ?> <span><?php echo esc_html( $oec_sitio['domain'] ); ?></span> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
				</a>
				<?php endif; ?>
			</div>
		</div>
		<?php else : ?>
		<h1><?php esc_html_e( 'Docente no encontrado', 'oec-theme' ); ?></h1>
		<?php endif; ?>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap docente-wrap">
<div class="container">

	<?php if ( $oec_docente ) : ?>

		<?php if ( $oec_docente['bio'] ) : ?>
		<section class="docente-section docente-bio">
			<h2 class="docente-section__title"><?php esc_html_e( 'Trayectoria', 'oec-theme' ); ?></h2>
			<p><?php echo esc_html( $oec_docente['bio'] ); ?></p>
		</section>
		<?php endif; ?>

		<section class="docente-section" id="docente-formaciones">
			<h2 class="docente-section__title">
				<?php echo esc_html( $oec_abiertas ? __( 'Formaciones con inscripción abierta', 'oec-theme' ) : __( 'Sin formaciones abiertas por ahora', 'oec-theme' ) ); ?>
			</h2>
			<?php if ( $oec_abiertas ) : ?>
			<div class="art-grid">
				<?php foreach ( $oec_abiertas as $f ) : ?>
				<a class="org-formation-card" href="<?php echo esc_url( oec_formation_url( $f ) ); ?>"<?php echo oec_formation_link_attrs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<?php if ( $f['image'] ) : ?>
					<div class="org-formation-card__thumb">
						<img src="<?php echo esc_url( oec_cdn_resize( $f['image'], 640, 89 ) ); ?>" alt="" loading="lazy">
						<?php echo oec_community_badge( $f, 'oec-community--card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					</div>
					<?php endif; ?>
					<div class="org-formation-card__body">
						<?php if ( ! $f['image'] ) : ?><?php echo oec_community_badge( $f ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?><?php endif; ?>
						<?php if ( $f['type'] ) : ?>
						<span class="post-tipo org-formation-card__type"><?php echo esc_html( $f['type'] ); ?></span>
						<?php endif; ?>
						<h3 class="org-formation-card__title"><?php echo esc_html( $f['title'] ); ?></h3>
						<?php if ( $f['enrollment_end'] ) : ?>
						<span class="org-formation-card__meta">
							<i class="bi bi-calendar3" aria-hidden="true"></i>
							<?php printf(
								esc_html__( 'Inscripción hasta %s', 'oec-theme' ),
								esc_html( date_i18n( 'd \d\e M Y', strtotime( $f['enrollment_end'] ) ) )
							); ?>
						</span>
						<?php endif; ?>
					</div>
				</a>
				<?php endforeach; ?>
			</div>
			<?php else : ?>
			<div class="art-empty">
				<p><?php esc_html_e( 'En este momento no tiene formaciones con inscripción abierta.', 'oec-theme' ); ?></p>
				<a href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?></a>
			</div>
			<?php endif; ?>
		</section>

		<?php if ( $oec_docente_reviews ) : ?>
		<section class="docente-section">
			<h2 class="docente-section__title"><?php esc_html_e( 'Lo que dicen sus alumnos', 'oec-theme' ); ?></h2>
			<div class="docente-reviews">
				<?php foreach ( $oec_docente_reviews as $r ) : ?>
				<figure class="docente-review">
					<span class="docente-review__stars" aria-label="<?php echo esc_attr( sprintf( __( '%d de 5 estrellas', 'oec-theme' ), $r['rating'] ) ); ?>">
						<?php echo str_repeat( '<i class="bi bi-star-fill" aria-hidden="true"></i>', $r['rating'] ); ?>
					</span>
					<blockquote><?php echo esc_html( wp_trim_words( $r['comment'], 60, '…' ) ); ?></blockquote>
					<figcaption>
						<strong><?php echo esc_html( $r['author'] ); ?></strong>
						<span><?php echo esc_html( $r['formacion'] ); ?></span>
					</figcaption>
				</figure>
				<?php endforeach; ?>
			</div>
		</section>
		<?php endif; ?>

		<?php if ( $oec_cerradas ) : ?>
		<section class="docente-section">
			<h2 class="docente-section__title"><?php esc_html_e( 'Formaciones que dictó', 'oec-theme' ); ?></h2>
			<ul class="docente-past">
				<?php foreach ( array_slice( $oec_cerradas, 0, 12 ) as $f ) : ?>
				<li>
					<?php if ( $f['type'] ) : ?><span class="docente-past__type"><?php echo esc_html( $f['type'] ); ?></span><?php endif; ?>
					<a href="<?php echo esc_url( oec_formation_url( $f ) ); ?>"<?php echo oec_formation_link_attrs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $f['title'] ); ?><?php echo oec_community_badge( $f, 'oec-community--inline' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></a>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $oec_cerradas ) > 12 ) : ?>
			<p class="docente-past__more"><?php echo esc_html( sprintf( __( 'Y %s formaciones más.', 'oec-theme' ), number_format_i18n( count( $oec_cerradas ) - 12 ) ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php endif; ?>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'No pudimos encontrar a este docente.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( oec_docentes_url() ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Ver todos los docentes', 'oec-theme' ); ?></a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
