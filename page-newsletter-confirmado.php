<?php
/**
 * Template Name: Newsletter confirmado
 *
 * Destino del link del email de confirmación (?t=token). newsletter.js
 * postea el token a /oec/v1/newsletter/confirm: alta en Elastic Email +
 * créditos de suscripción. Ver inc/newsletter.php.
 */

get_header();
?>

<main id="main-content" class="oec-nl-landing-page">
	<div class="container">
		<?php oec_nl_render_landing( 'confirm' ); ?>
	</div>
</main>

<?php
get_footer();
