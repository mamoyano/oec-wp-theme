<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   CONTACT FORM HANDLER
   ============================================================ */
function oec_handle_contact_form(): void {
	if ( ! isset( $_POST['oec_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oec_nonce'] ) ), 'oec_contact_nonce' ) ) {
		wp_die( esc_html__( 'Acción no permitida.', 'oec-theme' ) );
	}

	$name     = sanitize_text_field( wp_unslash( $_POST['cf_name']     ?? '' ) );
	$email    = sanitize_email( wp_unslash( $_POST['cf_email']    ?? '' ) );
	$phone    = sanitize_text_field( wp_unslash( $_POST['cf_phone']    ?? '' ) );
	$interest = sanitize_text_field( wp_unslash( $_POST['cf_interest'] ?? '' ) );
	$message  = sanitize_textarea_field( wp_unslash( $_POST['cf_message']  ?? '' ) );

	if ( ! $name || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'contact', 'error', wp_get_referer() ) );
		exit;
	}

	$to      = get_theme_mod( 'oec_contact_email', get_option( 'admin_email' ) );
	$subject = sprintf( '[%s] Nueva consulta de %s', get_bloginfo( 'name' ), $name );
	$body    = sprintf(
		"Nombre: %s\nEmail: %s\nTeléfono: %s\nInterés: %s\n\nMensaje:\n%s",
		$name, $email, $phone, $interest, $message
	);
	$headers = [ 'Content-Type: text/plain; charset=UTF-8', "Reply-To: {$name} <{$email}>" ];

	wp_mail( $to, $subject, $body, $headers );

	wp_safe_redirect( add_query_arg( 'contact', 'success', wp_get_referer() ) );
	exit;
}
add_action( 'admin_post_oec_contact_form',        'oec_handle_contact_form' );
add_action( 'admin_post_nopriv_oec_contact_form', 'oec_handle_contact_form' );
