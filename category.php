<?php
/**
 * Template: Archivo de categoría (TIPO o TEMÁTICA).
 * Página canónica e indexable para cada categoría — pensada para SEO/AEO:
 * URL limpia (/category/slug/), sin query strings, apta para sitemap.
 * El listado con filtros combinables vive en /articulos.
 */

$term        = get_queried_object();
$tipo_slugs  = [ 'articulos', 'blogs', 'vigia' ];
$tipo_labels = oec_tipo_labels();
$is_tipo     = ( $term instanceof WP_Term ) && in_array( $term->slug, $tipo_slugs, true );
$term_label  = $is_tipo ? ( $tipo_labels[ $term->slug ] ?? $term->name ) : $term->name;

get_header();
?>

<div class="page-hero page-hero--articulos">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Artículos', 'oec-theme' ), oec_articulos_url( [] ) ],
			[ $term_label ],
		] );
		?>
		<h1><?php echo esc_html( $term_label ); ?></h1>
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

		<a href="<?php echo esc_url( oec_articulos_url( $is_tipo ? [ 'tipo' => $term->slug ] : [ 'tematica' => $term->slug ] ) ); ?>"
		   class="btn btn-ghost btn-sm">
			<?php esc_html_e( 'Ver con más filtros', 'oec-theme' ); ?>
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
		<p><?php esc_html_e( 'No hay entradas en esta categoría todavía.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( oec_articulos_url( [] ) ); ?>" class="btn btn-ghost">
			<?php esc_html_e( 'Ver todas las entradas', 'oec-theme' ); ?>
		</a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
