<?php
/**
 * Template: hub de landings de temática ("Especiales").
 * Se activa automáticamente para la página con slug "especiales".
 * Lista las landings de temática ya armadas — la fuente de datos vive en
 * oec_get_especiales_list() (inc/tematica-landing.php), compartida con
 * [oec-tematica-nav] del home para no mantener la lista en dos lugares.
 * 'image' es opcional — sin ella, la card muestra un bloque de color liso
 * con el acento de la temática (ver el fallback en el bucle de abajo).
 */

$oec_especiales = oec_get_especiales_list();

$oec_seo_desc = 'Landings de temática de OEC: cursos, talleres y diplomados agrupados por especialidad, con formaciones, docentes y organizaciones de referencia en cada área.';

add_action( 'wp_head', function () use ( $oec_seo_desc, $oec_especiales ) {
	$url   = home_url( user_trailingslashit( '/especiales' ) );
	$title = 'Especiales';
	$img   = $oec_especiales[0]['image'] ?? '';
	echo "\n\n";
	echo '<meta name="description" content="' . esc_attr( $oec_seo_desc ) . '">' . "\n";
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:locale" content="es_ES">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_html( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $oec_seo_desc ) . '">' . "\n";
	if ( $img ) {
		echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n";
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_html( $title ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $oec_seo_desc ) . '">' . "\n";
	?>
	<script type="application/ld+json">
	{
		"@context": "https://schema.org",
		"@type": "BreadcrumbList",
		"itemListElement": [
			{ "@type": "ListItem", "position": 1, "name": "Inicio", "item": "<?php echo esc_url( home_url( '/' ) ); ?>" },
			{ "@type": "ListItem", "position": 2, "name": "Especiales", "item": "<?php echo esc_url( $url ); ?>" }
		]
	}
	</script>
	<?php
	echo "\n\n";
}, 5 );

get_header();
?>

<div class="page-hero">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Especiales', 'oec-theme' ) ],
		], false );
		?>
		<h1><?php esc_html_e( 'Especiales', 'oec-theme' ); ?></h1>
		<p class="page-hero__lead"><?php esc_html_e( 'Formaciones agrupadas por especialidad, con docentes, opiniones y organizaciones de referencia en cada área.', 'oec-theme' ); ?></p>
	</div>
</div>

<main id="main-content">
<div class="container">
	<div class="oec-especiales-grid">
		<?php foreach ( $oec_especiales as $esp ) :
			$count = null;
			if ( class_exists( 'OEC_AI_Catalog' ) ) {
				$count = count( array_filter(
					OEC_AI_Catalog::get_index(),
					fn( $f ) => in_array( $esp['tematica'], $f['tags'] ?? [], true )
				) );
			}
			?>
		<a class="oec-especiales-card" href="<?php echo esc_url( $esp['url'] ); ?>" style="--card-accent: <?php echo esc_attr( $esp['accent'] ); ?>;">
			<div class="oec-especiales-card__thumb">
				<?php if ( $esp['image'] ) : ?>
				<img src="<?php echo esc_url( $esp['image'] ); ?>" alt="<?php echo esc_attr( $esp['title'] ); ?>" loading="lazy">
				<?php else : ?>
				<div class="oec-especiales-card__thumb-fallback"></div>
				<?php endif; ?>
				<?php if ( ! empty( $esp['tinte'] ) && $esp['image'] ) : ?>
				<span class="oec-especiales-card__tint" style="--card-tint: <?php echo esc_attr( $esp['tinte'] ); ?>;" aria-hidden="true"></span>
				<?php endif; ?>
				<span class="oec-especiales-card__accent" aria-hidden="true"></span>
			</div>
			<div class="oec-especiales-card__body">
				<h2 class="oec-especiales-card__title"><?php echo esc_html( $esp['title'] ); ?></h2>
				<p class="oec-especiales-card__desc"><?php echo esc_html( $esp['desc'] ); ?></p>
				<?php if ( null !== $count ) : ?>
				<span class="oec-especiales-card__stat">
					<?php printf(
						/* translators: %d: cantidad de formaciones */
						esc_html( _n( '%d formación abierta', '%d formaciones abiertas', $count, 'oec-theme' ) ),
						$count
					); ?>
				</span>
				<?php endif; ?>
				<span class="oec-especiales-card__cta">
					<?php esc_html_e( 'Ver formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
				</span>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
</div>
</main>

<?php get_footer(); ?>
