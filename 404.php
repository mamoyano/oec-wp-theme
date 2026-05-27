<?php get_header(); ?>

<main id="main-content">
	<section class="not-found-section">
		<div class="container">
			<div class="not-found-code" aria-hidden="true">404</div>
			<h1><?php esc_html_e( 'Página no encontrada', 'oec-theme' ); ?></h1>
			<p><?php esc_html_e( 'Lo sentimos, la página que buscás no existe o fue movida.', 'oec-theme' ); ?></p>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-primary btn-lg">
				<?php esc_html_e( 'Volver al inicio', 'oec-theme' ); ?>
			</a>
		</div>
	</section>
</main>

<?php get_footer(); ?>
