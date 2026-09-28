<?php get_header(); ?>

<div class="page-hero">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Archivo', 'oec-theme' ) ],
		] );
		?>
		<h1>
			<?php
			if ( is_category() ) {
				single_cat_title();
			} elseif ( is_tag() ) {
				single_tag_title( '#' );
			} elseif ( is_author() ) {
				the_author();
			} elseif ( is_year() ) {
				echo esc_html( get_the_date( 'Y' ) );
			} else {
				esc_html_e( 'Archivo', 'oec-theme' );
			}
			?>
		</h1>
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
