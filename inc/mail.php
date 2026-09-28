<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   ENVÍO DE EMAILS POR SMTP (Elastic Email)
   Todo lo que WordPress manda con wp_mail() — formularios, avisos,
   recuperación de contraseña — sale por el SMTP de Elastic Email en vez
   del servidor (que termina en spam). El newsletter no pasa por acá:
   usa la API de Elastic Email (inc/newsletter.php).

   Las credenciales van en wp-config.php, nunca en el repo (es público):
     define( 'OEC_SMTP_USER', '…' );
     define( 'OEC_SMTP_PASS', '…' );
   Opcionales:
     OEC_SMTP_HOST (smtp.elasticemail.com), OEC_SMTP_PORT (2525),
     OEC_MAIL_FROM, OEC_MAIL_FROM_NAME (remitente por defecto).
   Sin OEC_SMTP_USER/PASS no cambia nada: WordPress envía como siempre.
   ============================================================ */

add_action( 'phpmailer_init', function ( $mailer ): void {
	if ( ! defined( 'OEC_SMTP_USER' ) || ! defined( 'OEC_SMTP_PASS' ) ) {
		return;
	}
	$mailer->isSMTP();
	$mailer->Host       = defined( 'OEC_SMTP_HOST' ) ? OEC_SMTP_HOST : 'smtp.elasticemail.com';
	$mailer->Port       = defined( 'OEC_SMTP_PORT' ) ? (int) OEC_SMTP_PORT : 2525;
	$mailer->SMTPAuth   = true;
	$mailer->SMTPSecure = 'tls'; // STARTTLS
	$mailer->Username   = OEC_SMTP_USER;
	$mailer->Password   = OEC_SMTP_PASS;
} );

// Remitente: solo reemplaza el genérico de WordPress (wordpress@dominio);
// si un formulario define su propio remitente, se respeta.
add_filter( 'wp_mail_from', function ( string $from ): string {
	return ( defined( 'OEC_MAIL_FROM' ) && str_starts_with( $from, 'wordpress@' ) ) ? OEC_MAIL_FROM : $from;
} );
add_filter( 'wp_mail_from_name', function ( string $name ): string {
	return ( defined( 'OEC_MAIL_FROM_NAME' ) && 'WordPress' === $name ) ? OEC_MAIL_FROM_NAME : $name;
} );

// Un envío fallido queda en el log de PHP, con el motivo que da el SMTP.
add_action( 'wp_mail_failed', function ( WP_Error $error ): void {
	error_log( '[OEC mail] ' . $error->get_error_message() );
} );
