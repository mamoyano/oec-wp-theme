<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<?php
/*
 * Páginas "aplicación": traen un shortcode (del plugin OEC, o cualquier
 * otro que declare oec_post_uses_shortcodes(), o [oec-tira] del tema — ver
 * oec_is_app_content() en inc/tiras.php) que ya renderiza su PROPIO
 * hero, título y navegación — mostrar además el hero genérico de acá
 * duplicaba el <h1>/breadcrumb (mal para SEO: dos H1 en la misma página)
 * y encima metía ese contenido, pensado para ser full-bleed, adentro del
 * ".container" centrado y con padding de una página de texto normal.
 * function_exists() de por medio para que este archivo no dependa de que
 * el plugin esté activo.
 */
global $post;
$oec_is_app_page = $post && oec_is_app_content( $post->post_content );
?>

<?php if ( ! $oec_is_app_page ) : ?>
<div class="page-hero">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ get_the_title() ],
		] );
		?>
		<h1><?php the_title(); ?></h1>
	</div>
</div>
<?php endif; ?>

<main id="main-content">
<?php if ( $oec_is_app_page ) : ?>
	<?php the_content(); ?>
<?php else : ?>
	<section>
		<div class="container">
			<article <?php post_class( 'post-content' ); ?> id="post-<?php the_ID(); ?>">
				<?php the_content(); ?>
			</article>
		</div>
	</section>
<?php endif; ?>
</main>

<?php endwhile; ?>

<?php get_footer(); ?>
