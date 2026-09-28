<?php
/**
 * Template: Archivo de etiqueta.
 * Misma línea que category.php: URL limpia (/tag/slug/), indexable cuando
 * la etiqueta tiene OEC_SITEMAP_TAG_MIN posts o más (ver inc/crawlers.php).
 * /articulos no filtra por etiqueta, así que el CTA lleva al listado completo.
 */

$term       = get_queried_object();
$term_label = ( $term instanceof WP_Term ) ? $term->name : single_tag_title( '', false );

add_action( 'wp_head', 'oec_output_og_image_meta', 20 );

get_header();
?>

<div class="page-hero page-hero--articulos">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Artículos', 'oec-theme' ), oec_articulos_url( [] ) ],
			[ '#' . $term_label ],
		] );
		?>
		<h1>#<?php echo esc_html( $term_label ); ?></h1>
		<?php if ( $term instanceof WP_Term && $term->description ) : ?>
		<p class="page-hero__lead"><?php echo esc_html( $term->description ); ?></p>
		<?php endif; ?>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap">
<div class="container">

	<div class="art-results-header">
		<?php if ( $term instanceof WP_Term && $term->count > 0 ) : ?>
		<p class="art-results-count">
			<?php printf(
				/* translators: %d: total de entradas */
				esc_html( _n( '%d entrada encontrada', '%d entradas encontradas', (int) $term->count, 'oec-theme' ) ),
				(int) $term->count
			); ?>
		</p>
		<?php else : ?>
		<p class="art-results-count"><?php esc_html_e( 'No se encontraron entradas.', 'oec-theme' ); ?></p>
		<?php endif; ?>

		<a href="<?php echo esc_url( oec_articulos_url( [] ) ); ?>" class="btn btn-ghost btn-sm">
			<?php esc_html_e( 'Ver todos los artículos', 'oec-theme' ); ?>
		</a>
	</div>

	<?php if ( have_posts() ) : ?>

	<div class="art-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php get_template_part( 'template-parts/content', 'card' ); ?>
		<?php endwhile; ?>
	</div>

	<?php
	echo paginate_links( [
		'prev_text' => '&larr;',
		'next_text' => '&rarr;',
		'type'      => 'list',
	] );
	?>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'No hay entradas con esta etiqueta todavía.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( oec_articulos_url( [] ) ); ?>" class="btn btn-ghost">
			<?php esc_html_e( 'Ver todas las entradas', 'oec-theme' ); ?>
		</a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
