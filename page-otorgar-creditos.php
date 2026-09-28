<?php
/**
 * Template Name: Créditos semanales
 *
 * Destino del botón "Obtener mis créditos" del newsletter semanal
 * (?t=fecha&s=firma&email=…). newsletter.js postea a
 * /oec/v1/newsletter/claim. Ver inc/newsletter.php.
 */

get_header();
?>

<main id="main-content" class="oec-nl-landing-page">
	<div class="container">
		<?php oec_nl_render_landing( 'claim' ); ?>
	</div>
</main>

<?php
get_footer();
