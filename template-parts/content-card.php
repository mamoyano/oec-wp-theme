<?php
$tipo_term    = oec_get_tipo_term();
$reading_time = oec_reading_time( get_the_ID() );
$tipo_colors  = [ 'articulos' => 'tipo--articulo', 'blogs' => 'tipo--blog', 'vigia' => 'tipo--vigia' ];
$tipo_labels  = [ 'articulos' => 'Artículo', 'blogs' => 'Blog', 'vigia' => 'VigIA' ];
$tipo_class   = $tipo_term ? ( $tipo_colors[ $tipo_term->slug ] ?? '' ) : '';
$tipo_label   = $tipo_term ? ( $tipo_labels[ $tipo_term->slug ] ?? $tipo_term->name ) : '';
?>
<article <?php post_class( 'post-card' ); ?> id="post-<?php the_ID(); ?>">

	<?php if ( has_post_thumbnail() ) : ?>
	<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true" class="post-card-thumb-link">
		<?php oec_post_thumbnail( 'oec-card' ); ?>
		<?php if ( $tipo_label ) : ?>
		<span class="post-tipo <?php echo esc_attr( $tipo_class ); ?>"><?php echo esc_html( $tipo_label ); ?></span>
		<?php endif; ?>
	</a>
	<?php else : ?>
	<?php if ( $tipo_label ) : ?>
	<div class="post-card-no-thumb">
		<span class="post-tipo <?php echo esc_attr( $tipo_class ); ?>"><?php echo esc_html( $tipo_label ); ?></span>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<div class="post-card-body">

		<div class="post-meta">
			<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>" class="post-meta-date">
				<?php echo esc_html( get_the_date( 'd M Y' ) ); ?>
			</time>
			<span class="post-meta-reading">
				<i class="bi bi-clock" aria-hidden="true"></i>
				<?php printf( esc_html__( '%d min', 'oec-theme' ), $reading_time ); ?>
			</span>
		</div>

		<h2 class="post-card-title">
			<a href="<?php the_permalink(); ?>" rel="bookmark"><?php the_title(); ?></a>
		</h2>

		<p class="post-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_content(), 55 ) ); ?></p>

		<?php
		$tematicas = array_filter(
			get_the_category(),
			fn( $c ) => ! in_array( $c->slug, [ 'articulos', 'blogs', 'vigia', 'general' ], true )
		);
		?>
		<div class="post-card-footer">
			<?php if ( $tematicas ) : ?>
			<div class="post-card-tematicas-wrap">
				<div class="post-card-tematicas">
					<?php foreach ( array_slice( $tematicas, 0, 2 ) as $t ) : ?>
					<a href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => $t->slug ] ) ); ?>"
					   class="art-tag-link" aria-label="<?php echo esc_attr( sprintf( __( 'Artículos de %s', 'oec-theme' ), $t->name ) ); ?>"><?php echo esc_html( $t->name ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<a href="<?php the_permalink(); ?>" class="read-more">
				<?php esc_html_e( 'Leer más', 'oec-theme' ); ?>
			</a>
		</div>

	</div>

</article>
