<?php
/**
 * Template: Landing de una organización.
 * Se activa automáticamente para la página con slug "organizacion".
 * La organización a mostrar viaja en ?slug=... (ver inc/organizations.php).
 */

$oec_org_slug = sanitize_title( wp_unslash( $_GET['slug'] ?? '' ) );
$oec_org      = ( $oec_org_slug && class_exists( 'OEC_AI_Catalog' ) )
	? OEC_AI_Catalog::get_organization( $oec_org_slug )
	: null;

get_header();
?>

<div class="page-hero page-hero--articulos">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Formaciones', 'oec-theme' ), home_url( user_trailingslashit( '/formaciones' ) ) ],
			[ __( 'Organizaciones', 'oec-theme' ), oec_organizaciones_url() ],
			[ $oec_org['name'] ?? __( 'Organización', 'oec-theme' ) ],
		] );
		?>

		<?php if ( $oec_org ) : ?>
			<?php if ( ! empty( $oec_org['logo'] ) ) : ?>
			<img class="org-hero-logo" src="<?php echo esc_url( oec_cdn_resize( $oec_org['logo'], 400, 90 ) ); ?>" alt="<?php echo esc_attr( $oec_org['name'] ); ?>">
			<?php endif; ?>
			<h1><?php echo esc_html( $oec_org['name'] ); ?></h1>
			<?php if ( ! empty( $oec_org['short_description'] ) ) : ?>
			<p class="page-hero__lead"><?php echo esc_html( $oec_org['short_description'] ); ?></p>
			<?php endif; ?>
			<?php $oec_sitio = function_exists( 'oec_sitio_de' ) ? oec_sitio_de( 'org', $oec_org['slug'] ) : null; ?>
			<?php if ( $oec_sitio ) : ?>
			<a class="oec-visit-site" href="<?php echo esc_url( $oec_sitio['url'] ); ?>" target="_blank" rel="noopener">
				<i class="bi bi-globe2" aria-hidden="true"></i> <?php esc_html_e( 'Visitar su sitio web', 'oec-theme' ); ?> <span><?php echo esc_html( $oec_sitio['domain'] ); ?></span> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
			</a>
			<?php endif; ?>
		<?php else : ?>
			<h1><?php esc_html_e( 'Organización no encontrada', 'oec-theme' ); ?></h1>
		<?php endif; ?>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap">
<div class="container">

	<?php if ( $oec_org && ! empty( $oec_org['formations'] ) ) : ?>

	<div class="art-results-header">
		<p class="art-results-count">
			<?php printf(
				/* translators: 1: cantidad de formaciones, 2: nombre de la organización */
				esc_html( _n( '%1$d formación abierta de %2$s', '%1$d formaciones abiertas de %2$s', count( $oec_org['formations'] ), 'oec-theme' ) ),
				count( $oec_org['formations'] ),
				esc_html( $oec_org['name'] )
			); ?>
		</p>
	</div>

	<div class="art-grid">
		<?php foreach ( $oec_org['formations'] as $f ) : ?>
		<a class="org-formation-card" href="<?php echo esc_url( oec_formation_url( $f ) ); ?>"<?php echo oec_formation_link_attrs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php if ( ! empty( $f['image'] ) ) : ?>
			<div class="org-formation-card__thumb">
				<img src="<?php echo esc_url( oec_cdn_resize( $f['image'], 640, 89 ) ); ?>" alt="" loading="lazy">
				<?php echo oec_community_badge( $f, 'oec-community--card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			</div>
			<?php endif; ?>
			<div class="org-formation-card__body">
				<?php if ( empty( $f['image'] ) ) : ?><?php echo oec_community_badge( $f ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?><?php endif; ?>
				<?php if ( ! empty( $f['type'] ) ) : ?>
				<span class="post-tipo org-formation-card__type"><?php echo esc_html( $f['type'] ); ?></span>
				<?php endif; ?>
				<h3 class="org-formation-card__title"><?php echo esc_html( $f['title'] ); ?></h3>
				<?php if ( ! empty( $f['enrollment_end'] ) ) : ?>
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

	<?php elseif ( $oec_org ) : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'Esta organización no tiene formaciones con inscripción abierta en este momento.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>" class="btn btn-ghost">
			<?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?>
		</a>
	</div>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'No pudimos encontrar esta organización.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( oec_organizaciones_url() ); ?>" class="btn btn-ghost">
			<?php esc_html_e( 'Ver todas las organizaciones', 'oec-theme' ); ?>
		</a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
