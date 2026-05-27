<?php get_header(); ?>

<main id="main-content">

	<!-- Page hero -->
	<div class="page-hero">
		<div class="container">
			<h1><?php esc_html_e( 'Blog', 'oec-theme' ); ?></h1>
			<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Ruta de navegación', 'oec-theme' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Inicio', 'oec-theme' ); ?></a>
				<span class="sep" aria-hidden="true">/</span>
				<span><?php esc_html_e( 'Blog', 'oec-theme' ); ?></span>
			</nav>
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
