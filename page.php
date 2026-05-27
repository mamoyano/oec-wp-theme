<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="page-hero">
	<div class="container">
		<h1><?php the_title(); ?></h1>
		<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Ruta de navegación', 'oec-theme' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Inicio', 'oec-theme' ); ?></a>
			<span class="sep" aria-hidden="true">/</span>
			<span><?php the_title(); ?></span>
		</nav>
	</div>
</div>

<main id="main-content">
	<section>
		<div class="container">
			<article <?php post_class( 'post-content' ); ?> id="post-<?php the_ID(); ?>">
				<?php the_content(); ?>
			</article>
		</div>
	</section>
</main>

<?php endwhile; ?>

<?php get_footer(); ?>
