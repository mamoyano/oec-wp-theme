<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="page-hero">
	<div class="container">
		<h1 class="sr-only"><?php the_title(); ?></h1>
		<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Ruta de navegación', 'oec-theme' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Inicio', 'oec-theme' ); ?></a>
			<span class="sep" aria-hidden="true">/</span>
			<a href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ); ?>"><?php esc_html_e( 'Blog', 'oec-theme' ); ?></a>
			<span class="sep" aria-hidden="true">/</span>
			<span><?php the_title(); ?></span>
		</nav>
	</div>
</div>

<main id="main-content">
	<section>
		<div class="container">
			<div class="single-layout">

				<article <?php post_class(); ?> id="post-<?php the_ID(); ?>">

					<header class="post-header">
						<div class="post-meta">
							<?php the_category( ' ' ); ?>
							<span><?php oec_posted_on(); ?></span>
							<span><?php oec_posted_by(); ?></span>
						</div>
						<h1 class="post-title"><?php the_title(); ?></h1>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
					<div class="post-featured-image">
						<?php the_post_thumbnail( 'oec-hero', [ 'loading' => 'eager' ] ); ?>
					</div>
					<?php endif; ?>

					<div class="post-content entry-content">
						<?php the_content(); ?>
					</div>

					<?php
					wp_link_pages( [
						'before' => '<div class="page-links">' . esc_html__( 'Páginas:', 'oec-theme' ),
						'after'  => '</div>',
					] );
					?>

					<?php the_tags( '<footer class="post-tags mt-3"><div class="text-sm text-muted">' . esc_html__( 'Tags: ', 'oec-theme' ), ', ', '</div></footer>' ); ?>

				</article>

				<aside class="sidebar" role="complementary">
					<?php get_sidebar(); ?>
				</aside>

			</div>
		</div>
	</section>
</main>

<?php endwhile; ?>

<?php get_footer(); ?>
