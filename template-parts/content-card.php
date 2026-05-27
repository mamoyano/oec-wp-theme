<article <?php post_class( 'post-card' ); ?> id="post-<?php the_ID(); ?>">

	<?php if ( has_post_thumbnail() ) : ?>
	<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php oec_post_thumbnail( 'oec-card' ); ?>
	</a>
	<?php endif; ?>

	<div class="post-card-body">

		<div class="post-meta">
			<?php the_category( ' ' ); ?>
			<?php oec_posted_on(); ?>
		</div>

		<h2 class="post-card-title">
			<a href="<?php the_permalink(); ?>" rel="bookmark"><?php the_title(); ?></a>
		</h2>

		<p class="post-card-excerpt"><?php the_excerpt(); ?></p>

		<a href="<?php the_permalink(); ?>" class="read-more">
			<?php esc_html_e( 'Leer más', 'oec-theme' ); ?>
		</a>

	</div>

</article>
