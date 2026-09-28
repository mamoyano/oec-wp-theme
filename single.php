<?php get_header(); ?>

<?php while ( have_posts() ) : the_post();

$tipo_term    = oec_get_tipo_term();
$reading_time = oec_reading_time( get_the_ID() );
$tipo_labels  = [ 'articulos' => 'Artículo', 'blogs' => 'Blog', 'vigia' => 'VigIA' ];
$tipo_colors  = [ 'articulos' => 'tipo--articulo', 'blogs' => 'tipo--blog', 'vigia' => 'tipo--vigia' ];
$tipo_label   = $tipo_term ? ( $tipo_labels[ $tipo_term->slug ] ?? $tipo_term->name ) : '';
$tipo_class   = $tipo_term ? ( $tipo_colors[ $tipo_term->slug ] ?? '' ) : '';

$tematicas = array_filter(
	get_the_category(),
	fn( $c ) => ! in_array( $c->slug, [ 'articulos', 'blogs', 'vigia', 'general' ], true )
);
?>

<div class="page-hero page-hero--single">
	<div class="container">
		<?php
		$oec_crumbs = [ [ __( 'Artículos', 'oec-theme' ), oec_articulos_url( [] ) ] ];
		if ( $tipo_term ) {
			$oec_crumbs[] = [ $tipo_label, oec_articulos_url( [ 'tipo' => $tipo_term->slug ] ) ];
		}
		$oec_crumbs[] = [ get_the_title() ];
		oec_breadcrumb( $oec_crumbs );
		?>
	</div>
</div>

<main id="main-content">
<div class="container">
<div class="single-wrap">

	<article <?php post_class( 'single-article' ); ?> id="post-<?php the_ID(); ?>">

		<header class="single-header">
			<div class="single-header-meta">
				<?php if ( $tipo_label ) : ?>
				<span class="post-tipo <?php echo esc_attr( $tipo_class ); ?>"><?php echo esc_html( $tipo_label ); ?></span>
				<?php endif; ?>
				<?php foreach ( $tematicas as $t ) : ?>
				<a href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => $t->slug ] ) ); ?>"
				   class="single-tematica-tag"><?php echo esc_html( $t->name ); ?></a>
				<?php endforeach; ?>
			</div>

			<h1 class="single-title"><?php the_title(); ?></h1>

			<div class="single-byline">
				<span class="single-author">
					<i class="bi bi-person" aria-hidden="true"></i>
					<?php esc_html_e( 'Por', 'oec-theme' ); ?>
					<a href="<?php echo esc_url( oec_articulos_url( [ 'autor' => get_the_author_meta( 'ID' ) ] ) ); ?>">
						<?php echo esc_html( get_the_author() ); ?>
					</a>
				</span>
				<time class="single-date" datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
					<i class="bi bi-calendar3" aria-hidden="true"></i>
					<?php echo esc_html( get_the_date( 'd M Y' ) ); ?>
				</time>
				<span class="single-reading">
					<i class="bi bi-clock" aria-hidden="true"></i>
					<?php printf( esc_html__( '%d min de lectura', 'oec-theme' ), $reading_time ); ?>
				</span>
			</div>

			<?php get_template_part( 'template-parts/content', 'share' ); ?>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
		<div class="single-featured-image">
			<?php the_post_thumbnail( 'oec-hero', [ 'loading' => 'eager' ] ); ?>
		</div>
		<?php endif; ?>

		<div class="single-content entry-content">
			<?php the_content(); ?>
			<?php wp_link_pages( [
				'before' => '<div class="page-links">' . esc_html__( 'Páginas:', 'oec-theme' ),
				'after'  => '</div>',
			] ); ?>
		</div>

		<?php if ( $tematicas ) : ?>
		<footer class="single-footer">
			<div class="single-tags-row">
				<span class="single-tags-label"><?php esc_html_e( 'Temáticas:', 'oec-theme' ); ?></span>
				<?php foreach ( $tematicas as $t ) : ?>
				<a href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => $t->slug ] ) ); ?>"
				   class="single-tematica-tag"><?php echo esc_html( $t->name ); ?></a>
				<?php endforeach; ?>
			</div>
		</footer>
		<?php endif; ?>

	</article>

	<?php
	// Posts relacionados — misma temática principal
	$related_term = reset( $tematicas );
	if ( $related_term ) :
		$related = new WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 3,
			'post__not_in'   => [ get_the_ID() ],
			'tax_query'      => [ [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $related_term->term_id,
			] ],
		] );
		if ( $related->have_posts() ) :
	?>
	<section class="single-related" aria-labelledby="related-title">
		<h2 id="related-title" class="single-related-title">
			<?php printf(
				esc_html__( 'Más sobre %s', 'oec-theme' ),
				esc_html( $related_term->name )
			); ?>
		</h2>
		<div class="art-grid art-grid--related">
			<?php while ( $related->have_posts() ) : $related->the_post(); ?>
				<?php get_template_part( 'template-parts/content', 'card' ); ?>
			<?php endwhile; wp_reset_postdata(); ?>
		</div>
	</section>
	<?php endif; endif; ?>

</div><!-- .single-wrap -->
</div><!-- .container -->
</main>

<?php endwhile; ?>

<?php get_footer(); ?>
