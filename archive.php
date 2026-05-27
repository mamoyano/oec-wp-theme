<?php get_header(); ?>

<div class="page-hero">
	<div class="container">
		<h1>
			<?php
			if ( is_category() ) {
				single_cat_title();
			} elseif ( is_tag() ) {
				single_tag_title( '#' );
			} elseif ( is_author() ) {
				the_author();
			} elseif ( is_year() ) {
				get_the_date( 'Y' );
			} else {
				esc_html_e( 'Archivo', 'oec-theme' );
			}
			?>
		</h1>
		<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Ruta de navegación', 'oec-theme' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Inicio', 'oec-theme' ); ?></a>
			<span class="sep" aria-hidden="true">/</span>
			<span><?php esc_html_e( 'Archivo', 'oec-theme' ); ?></span>
		</nav>
	</div>
</div>

<main id="main-content">
	<section>
		<div class="container">

			<?php if ( have_posts() ) : ?>

			<div class="grid-3">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/content', 'card' );
				endwhile;
				?>
			</div>

			<?php the_posts_pagination( [
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
				'class'     => 'pagination',
			] ); ?>

			<?php else : ?>

			<p class="text-center"><?php esc_html_e( 'No se encontraron entradas.', 'oec-theme' ); ?></p>

			<?php endif; ?>

		</div>
	</section>
</main>

<?php get_footer(); ?>
