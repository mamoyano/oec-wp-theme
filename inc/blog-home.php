<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   BLOG EN EL HOME — [oec-blog limit="6" categoria="" title=""]

   Formato revista: una nota destacada grande (la más reciente con imagen)
   + las siguientes en formato compacto, chips de temáticas con cantidad y
   un encabezado que muestra que el contenido está vivo (total y fecha de
   la última publicación). Las notas sin imagen llevan una portada
   generada (degradé del color de su tipo + ícono + temática) en vez de
   una tarjeta vacía.
   ============================================================ */

/** Temáticas del blog: todas las categorías salvo las de TIPO y las genéricas. */
function oec_blog_tematicas( int $post_id ): array {
	return array_values( array_filter(
		get_the_category( $post_id ),
		fn( $c ) => ! in_array( $c->slug, [ 'articulos', 'blogs', 'vigia', 'general', 'sin-categoria', 'uncategorized' ], true )
	) );
}

/** Tipo (articulos/blogs/vigia) de un post: [slug, etiqueta]. */
function oec_blog_tipo( int $post_id ): array {
	$labels = oec_tipo_labels();
	foreach ( get_the_category( $post_id ) as $c ) {
		if ( isset( $labels[ $c->slug ] ) ) {
			return [ $c->slug, $labels[ $c->slug ] ];
		}
	}
	return [ '', '' ];
}

/** Portada: la imagen destacada o, si no hay, una generada con tipo + temática. */
function oec_blog_cover( int $post_id, string $size, string $tipo, string $tema ): string {
	if ( has_post_thumbnail( $post_id ) ) {
		return get_the_post_thumbnail( $post_id, $size, [ 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '' ] );
	}
	$iconos = [ 'articulos' => 'bi-journal-text', 'blogs' => 'bi-pencil-square', 'vigia' => 'bi-broadcast' ];
	return sprintf(
		'<span class="oec-blog__cover oec-blog__cover--%1$s" aria-hidden="true"><i class="bi %2$s"></i><span>%3$s</span></span>',
		esc_attr( $tipo ?: 'articulos' ),
		esc_attr( $iconos[ $tipo ] ?? 'bi-journal-text' ),
		esc_html( $tema )
	);
}

add_shortcode( 'oec-blog', 'oec_render_blog_shortcode' );
function oec_render_blog_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'limit'     => 6,
		'categoria' => '',
		'title'     => __( 'Ciencia aplicada, explicada', 'oec-theme' ),
	], $atts, 'oec-blog' );

	$args = [
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 2, (int) $atts['limit'] ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	];
	if ( $atts['categoria'] ) {
		$args['category_name'] = sanitize_title( $atts['categoria'] );
	}
	$posts = get_posts( $args );
	if ( ! $posts ) {
		return '';
	}

	// La destacada: la más reciente con imagen (si ninguna tiene, la primera).
	$idx = 0;
	foreach ( $posts as $i => $p ) {
		if ( has_post_thumbnail( $p ) ) {
			$idx = $i;
			break;
		}
	}
	$destacada = $posts[ $idx ];
	unset( $posts[ $idx ] );
	$resto = array_values( $posts );

	// Total y temáticas con cantidad (del alcance: todo el blog o la categoría).
	$total = $atts['categoria']
		? (int) ( get_category_by_slug( sanitize_title( $atts['categoria'] ) )->count ?? 0 )
		: (int) wp_count_posts( 'post' )->publish;
	$cat    = $atts['categoria'] ? get_category_by_slug( sanitize_title( $atts['categoria'] ) ) : null;
	$ultima = get_posts( array_filter( [ 'post_type' => 'post', 'posts_per_page' => 1, 'fields' => 'ids', 'category_name' => $cat->slug ?? '' ] ) );
	$temas  = $atts['categoria'] ? [] : get_categories( [
		'orderby'    => 'count',
		'order'      => 'DESC',
		'hide_empty' => true,
		'exclude'    => array_filter( array_map( fn( $s ) => get_category_by_slug( $s )->term_id ?? 0, [ 'articulos', 'blogs', 'vigia', 'general', 'sin-categoria', 'uncategorized' ] ) ),
		'number'     => 7,
	] );

	$meta = function ( WP_Post $p ): string {
		return sprintf(
			'<span class="oec-blog__meta"><time datetime="%1$s">%2$s</time> · <i class="bi bi-clock" aria-hidden="true"></i> %3$s</span>',
			esc_attr( get_the_date( DATE_W3C, $p ) ),
			esc_html( get_the_date( 'j M Y', $p ) ),
			esc_html( sprintf( __( '%d min de lectura', 'oec-theme' ), oec_reading_time( $p->ID ) ) )
		);
	};

	[ $d_tipo, $d_label ] = oec_blog_tipo( $destacada->ID );
	$d_temas              = oec_blog_tematicas( $destacada->ID );
	$more_url             = $atts['categoria'] ? oec_articulos_url( [ 'tematica' => sanitize_title( $atts['categoria'] ) ] ) : oec_articulos_url( [] );

	ob_start();
	?>
	<section class="oec-blog" aria-labelledby="oec-blog-title">
		<div class="oec-agenda__head">
			<div>
				<span class="oec-agenda__eyebrow"><i class="bi bi-journal-text" aria-hidden="true"></i> <?php esc_html_e( 'Blog y artículos', 'oec-theme' ); ?></span>
				<h2 class="oec-agenda__title" id="oec-blog-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="oec-agenda__sub">
					<?php
					$fecha = $ultima ? get_the_date( 'j \d\e F', $ultima[0] ) : '';
					echo esc_html( $cat
						/* translators: 1: cantidad, 2: temática, 3: fecha de la última */
						? sprintf( __( '%1$s publicaciones sobre %2$s. Última publicación: %3$s.', 'oec-theme' ), number_format_i18n( $total ), mb_strtolower( $cat->name ), $fecha )
						/* translators: 1: cantidad, 2: fecha de la última */
						: sprintf( __( '%1$s publicaciones sobre nutrición, fuerza, fisiología y más. Última publicación: %2$s.', 'oec-theme' ), number_format_i18n( $total ), $fecha ) );
					?>
				</p>
			</div>
			<a class="oec-cierres__more" href="<?php echo esc_url( $more_url ); ?>"><?php esc_html_e( 'Ver todos los artículos', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
		</div>

		<?php if ( $temas ) : ?>
		<div class="oec-blog__temas">
			<?php foreach ( $temas as $t ) : ?>
			<a class="docentes-chip" href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => $t->slug ] ) ); ?>"><?php echo esc_html( $t->name ); ?> <span><?php echo esc_html( number_format_i18n( $t->count ) ); ?></span></a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="oec-blog__layout">
			<article class="oec-blog__featured">
				<a class="oec-blog__featured-img" href="<?php echo esc_url( get_permalink( $destacada ) ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo oec_blog_cover( $destacada->ID, 'large', $d_tipo, $d_temas[0]->name ?? '' ); // phpcs:ignore ?>
					<?php if ( $d_label ) : ?><span class="post-tipo tipo--<?php echo esc_attr( rtrim( $d_tipo, 's' ) ); ?>"><?php echo esc_html( $d_label ); ?></span><?php endif; ?>
				</a>
				<div class="oec-blog__featured-body">
					<?php echo $meta( $destacada ); // phpcs:ignore ?>
					<h3 class="oec-blog__featured-title"><a href="<?php echo esc_url( get_permalink( $destacada ) ); ?>"><?php echo esc_html( get_the_title( $destacada ) ); ?></a></h3>
					<p class="oec-blog__excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $destacada->post_excerpt ?: $destacada->post_content ), 42, '…' ) ); ?></p>
					<?php if ( $d_temas ) : ?>
					<div class="oec-blog__tags">
						<?php foreach ( array_slice( $d_temas, 0, 3 ) as $t ) : ?>
						<a class="art-tag-link" href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => $t->slug ] ) ); ?>"><?php echo esc_html( $t->name ); ?></a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</article>

			<?php if ( $resto ) : ?>
			<ol class="oec-blog__list">
				<?php foreach ( $resto as $p ) :
					[ $tipo, $label ] = oec_blog_tipo( $p->ID );
					$temas_p          = oec_blog_tematicas( $p->ID );
					?>
				<li>
					<a class="oec-blog__item" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
						<span class="oec-blog__thumb"><?php echo oec_blog_cover( $p->ID, 'medium', $tipo, $temas_p[0]->name ?? '' ); // phpcs:ignore ?></span>
						<span class="oec-blog__item-body">
							<?php if ( $label ) : ?><span class="oec-blog__item-tipo tipo-text--<?php echo esc_attr( $tipo ); ?>"><?php echo esc_html( $label ); ?><?php echo $temas_p ? ' · ' . esc_html( $temas_p[0]->name ) : ''; ?></span><?php endif; ?>
							<strong><?php echo esc_html( get_the_title( $p ) ); ?></strong>
							<?php echo $meta( $p ); // phpcs:ignore ?>
						</span>
					</a>
				</li>
				<?php endforeach; ?>
			</ol>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
