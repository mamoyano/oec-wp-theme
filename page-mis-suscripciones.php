<?php
/**
 * Template Name: Mis suscripciones
 *
 * Gestión de suscripciones al newsletter: pide el email, manda un link
 * firmado y con ese link la persona elige sus listas. Todo lo hace
 * assets/js/newsletter.js contra la REST del tema (inc/newsletter-manage.php).
 */

get_header();
?>

<main id="main-content" class="oec-nl-landing-page">
	<div class="container">
		<?php oec_nl_render_manage_page(); ?>
	</div>
</main>

<?php
get_footer();
