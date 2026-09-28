<?php get_header(); ?>

<main id="main-content">

	<!-- Page hero -->
	<div class="page-hero">
		<div class="container">
			<?php
			oec_breadcrumb( [
				[ __( 'Blog', 'oec-theme' ) ],
			] );
			?>
			<h1><?php esc_html_e( 'Blog', 'oec-theme' ); ?></h1>
		</div>
	</div>

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

			<div class="text-center mt-4">
				<p><?php esc_html_e( 'No se encontraron entradas.', 'oec-theme' ); ?></p>
			</div>

			<?php endif; ?>

		</div>
	</section>

</main>

<?php get_footer(); ?>
