<?php
/**
 * Template part: barra de compartir (WhatsApp / LinkedIn / X / Copiar enlace).
 * En mobile se suma un botón "Más" que abre el panel nativo de compartir del
 * sistema (navigator.share) — ahí es donde aparece Instagram, Telegram, etc.
 * si el usuario los tiene instalados; no existe un link web para "compartir a
 * Instagram" directamente, así que no se ofrece como ícono propio.
 */
$oec_tipo_term = function_exists( 'oec_get_tipo_term' ) ? oec_get_tipo_term() : null;
?>
<div class="share-bar"
     data-share-url="<?php echo esc_url( get_permalink() ); ?>"
     data-share-title="<?php echo esc_attr( get_the_title() ); ?>"
     data-content-type="<?php echo esc_attr( $oec_tipo_term->slug ?? '' ); ?>"
     data-item-id="<?php echo esc_attr( get_the_ID() ); ?>">
	<span class="share-bar__label"><?php esc_html_e( 'Compartir', 'oec-theme' ); ?></span>

	<a href="#" class="share-bar__btn" data-share="whatsapp" aria-label="<?php esc_attr_e( 'Compartir por WhatsApp', 'oec-theme' ); ?>">
		<i class="bi bi-whatsapp" aria-hidden="true"></i>
	</a>
	<a href="#" class="share-bar__btn" data-share="linkedin" aria-label="<?php esc_attr_e( 'Compartir en LinkedIn', 'oec-theme' ); ?>">
		<i class="bi bi-linkedin" aria-hidden="true"></i>
	</a>
	<a href="#" class="share-bar__btn" data-share="x" aria-label="<?php esc_attr_e( 'Compartir en X', 'oec-theme' ); ?>">
		<i class="bi bi-twitter-x" aria-hidden="true"></i>
	</a>
	<button type="button" class="share-bar__btn" data-share="copy" aria-label="<?php esc_attr_e( 'Copiar enlace', 'oec-theme' ); ?>">
		<i class="bi bi-link-45deg" aria-hidden="true"></i>
	</button>
	<button type="button" class="share-bar__btn share-bar__btn--more" data-share="more" aria-label="<?php esc_attr_e( 'Más opciones para compartir', 'oec-theme' ); ?>" hidden>
		<i class="bi bi-three-dots" aria-hidden="true"></i>
	</button>
</div>
