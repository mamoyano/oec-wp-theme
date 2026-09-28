<?php
/**
 * Template: Archivo de autor.
 * Mismo tratamiento que category.php (hero temático, grilla de artículos,
 * invitación a /articulos con más filtros) — el listado con filtros
 * combinables vive en /articulos.
 */

$author      = get_queried_object();
$author_name = ( $author instanceof WP_User ) ? $author->display_name : get_the_author();
$author_bio  = ( $author instanceof WP_User ) ? get_the_author_meta( 'description', $author->ID ) : '';

add_action( 'wp_head', 'oec_output_og_image_meta', 20 );

get_header();
?>

<div class="page-hero page-hero--articulos">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Artículos', 'oec-theme' ), oec_articulos_url( [] ) ],
			[ $author_name ],
		] );
		?>
		<h1><?php echo esc_html( $author_name ); ?></h1>
		<p class="page-hero__lead">
			<?php
			echo esc_html( $author_bio ? $author_bio : sprintf(
				/* translators: %s: nombre del autor */
				__( 'Artículos y blogs publicados por %s en Online Education Center.', 'oec-theme' ),
				$author_name
			) );
			?>
		</p>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap">
<div class="container">

	<div class="art-results-header">
		<?php global $wp_query; ?>
		<?php if ( $wp_query->found_posts > 0 ) : ?>
		<p class="art-results-count">
			<?php printf(
				/* translators: %d: total de entradas */
				esc_html( _n( '%d entrada encontrada', '%d entradas encontradas', (int) $wp_query->found_posts, 'oec-theme' ) ),
				(int) $wp_query->found_posts
			); ?>
		</p>
		<?php else : ?>
		<p class="art-results-count"><?php esc_html_e( 'No se encontraron entradas.', 'oec-theme' ); ?></p>
		<?php endif; ?>

		<?php if ( $author instanceof WP_User ) : ?>
		<a href="<?php echo esc_url( oec_articulos_url( [ 'autor' => $author->ID ] ) ); ?>"
		   class="btn btn-ghost btn-sm">
			<?php esc_html_e( 'Ver con más filtros', 'oec-theme' ); ?>
		</a>
		<?php endif; ?>
	</div>

	<?php if ( have_posts() ) : ?>

	<div class="art-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php get_template_part( 'template-parts/content', 'card' ); ?>
		<?php endwhile; ?>
	</div>

	<?php
	$oec_auth_tp = (int) $wp_query->max_num_pages;
	if ( $oec_auth_tp > 1 ) :
		$oec_auth_cp = max( 1, absint( get_query_var( 'paged' ) ?: 1 ) );
		$oec_auth_page_url = function ( $p ) {
			return esc_url( $p > 1 ? get_pagenum_link( $p ) : get_pagenum_link( 1 ) );
		};
		?>
	<nav class="oec-pager" aria-label="<?php esc_attr_e( 'Paginación de artículos', 'oec-theme' ); ?>">
		<?php if ( $oec_auth_cp > 1 ) : ?>
			<a href="<?php echo $oec_auth_page_url( $oec_auth_cp - 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página anterior', 'oec-theme' ); ?>" rel="prev"><i class="bi bi-chevron-left"></i></a>
		<?php else : ?>
			<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
		<?php endif; ?>

		<?php for ( $p = 1; $p <= $oec_auth_tp; $p++ ) :
			if ( 1 === $p || $oec_auth_tp === $p || ( $p >= $oec_auth_cp - 1 && $p <= $oec_auth_cp + 1 ) ) :
				if ( $p === $oec_auth_cp ) : ?>
					<span class="oec-pager-num is-active" aria-current="page"><?php echo (int) $p; ?></span>
				<?php else : ?>
					<a href="<?php echo $oec_auth_page_url( $p ); ?>" class="oec-pager-num"><?php echo (int) $p; ?></a>
				<?php endif;
			elseif ( $p === $oec_auth_cp - 2 || $p === $oec_auth_cp + 2 ) : ?>
				<span class="oec-pager-dots">&hellip;</span>
			<?php endif;
		endfor; ?>

		<?php if ( $oec_auth_cp < $oec_auth_tp ) : ?>
			<a href="<?php echo $oec_auth_page_url( $oec_auth_cp + 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página siguiente', 'oec-theme' ); ?>" rel="next"><i class="bi bi-chevron-right"></i></a>
		<?php else : ?>
			<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
		<?php endif; ?>
	</nav>
	<?php endif; ?>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'Este autor todavía no tiene entradas publicadas.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( oec_articulos_url( [] ) ); ?>" class="btn btn-ghost">
			<?php esc_html_e( 'Ver todas las entradas', 'oec-theme' ); ?>
		</a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
