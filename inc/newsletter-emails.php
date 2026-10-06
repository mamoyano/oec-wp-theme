<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   NEWSLETTER — plantillas de email (lo carga inc/newsletter.php)

   Mismo lenguaje visual que el sitio (colores de Ajustes OEC, tipografía
   del sistema, tarjetas de formación como las de la web), escrito para
   clientes de correo:
   - Maquetado con tablas y estilos inline; el <style> del <head> solo
     agrega el responsive (Gmail, Apple Mail, Outlook.com lo respetan; si
     un cliente lo ignora, se ve la versión de escritorio a 600px).
   - Tabla "fantasma" [if mso] a 600px para Outlook de escritorio.
   - Sin flex/grid/position/background-image/rgba(): Outlook y Gmail no
     los soportan de forma pareja.
   - Botones "bulletproof" (td con bgcolor + a con padding).
   - Imágenes con width/height/alt y en PNG/JPG (el logo del sitio es WebP,
     que Outlook no muestra: se usa OEC_NL_LOGO, un PNG del tema).
   - HTML compacto: Gmail recorta los mensajes de más de ~102 KB.
   ============================================================ */

const OEC_NL_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

/** Paleta de los emails: colores del tema (Ajustes OEC del sitio de configuración). */
function oec_nl_colors(): array {
	static $c = null;
	if ( null !== $c ) {
		return $c;
	}
	$o   = function_exists( 'oec_config_theme_options' ) ? oec_config_theme_options() : [];
	$p = oec_palette( $o );
	return $c = [
		'dark'    => $p['dark'],
		'primary' => $p['primary'],
		'accent'  => $p['accent'],
		'text'    => $p['text'],
		'muted'   => $p['muted'],
		'border'  => $p['border'],
		'light'   => $p['light'],
		'page'    => '#eef2f6',
		'soft'    => '#b8c7d6', // texto secundario sobre el fondo oscuro (sin rgba)
	];
}

/**
 * Botón compatible con Outlook. $href ya debe venir escapado (esc_url, o
 * esc_attr si lleva merge tags de Elastic Email que esc_url rompería).
 */
function oec_nl_btn( string $href, string $label, string $bg, string $color, bool $full = false ): string {
	return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"' . ( $full ? ' width="100%" class="nl-btn"' : ' class="nl-btn"' ) . '><tr>'
		. '<td align="center" bgcolor="' . esc_attr( $bg ) . '" style="border-radius:8px;background:' . esc_attr( $bg ) . ';">'
		. '<a href="' . $href . '" target="_blank" style="display:block;padding:12px 22px;font-family:' . OEC_NL_FONT . ';font-size:14px;font-weight:700;line-height:18px;color:' . esc_attr( $color ) . ';text-decoration:none;border-radius:8px;">' . esc_html( $label ) . '</a>'
		. '</td></tr></table>';
}

/** Pastilla chica (badges de formación, etiquetas). */
function oec_nl_pill( string $label, string $bg, string $color ): string {
	return '<span style="display:inline-block;margin:0 4px 6px 0;padding:3px 8px;border-radius:4px;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . ';font-family:' . OEC_NL_FONT . ';font-size:10px;font-weight:700;line-height:14px;letter-spacing:.4px;text-transform:uppercase;">' . esc_html( $label ) . '</span>';
}

/**
 * Documento completo: cabecera oscura (logo + etiqueta + título), cuerpo
 * blanco y pie. $body son filas <tr> de la tabla de 600px.
 */
function oec_nl_email_shell( array $a ): string {
	$c = oec_nl_colors();
	$a = wp_parse_args( $a, [
		'title'      => OEC_NL_BRAND,
		'preheader'  => '',
		'label'      => '',
		'sublabel'   => '',
		'heading'    => '',
		'intro'      => '',
		'body'       => '',
		'subscribed' => true,
		'header_bg'  => '', // fondo de la cabecera (p. ej. el tinte de una temática)
		'label_color'=> '', // color de la etiqueta (p. ej. el acento de una temática)
		'banner'     => '', // URL de una franja de imagen debajo de la cabecera (600 px)
		'banner_alt' => '',
	] );
	$f      = OEC_NL_FONT;
	$head   = sanitize_hex_color( $a['header_bg'] ) ?: $c['dark'];
	$lcolor = sanitize_hex_color( $a['label_color'] ) ?: $c['accent'];

	ob_start();
	?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $a['title'] ); ?></title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>table,td,a,p,h1,h2,h3,span{font-family:Arial,sans-serif!important}</style><![endif]-->
<style>
:root{color-scheme:light;supported-color-schemes:light}
body{margin:0!important;padding:0!important;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
table,td{mso-table-lspace:0;mso-table-rspace:0;border-collapse:collapse}
img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none}
a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}
u+#body a{color:inherit;text-decoration:none}
@media only screen and (max-width:620px){
.nl-wrap{width:100%!important}
.nl-px{padding-left:20px!important;padding-right:20px!important}
.nl-stack{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box!important}
.nl-thumb{padding:0 0 14px 0!important}
.nl-thumb img{width:100%!important;height:auto!important}
.nl-h1{font-size:25px!important;line-height:31px!important}
.nl-gap{padding-top:14px!important}
.nl-btn{width:100%!important}
.nl-center{text-align:left!important}
}
</style>
</head>
<body id="body" style="margin:0;padding:0;background:<?php echo esc_attr( $c['page'] ); ?>;" bgcolor="<?php echo esc_attr( $c['page'] ); ?>">
<?php if ( $a['preheader'] ) : ?>
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;"><?php echo esc_html( $a['preheader'] ); ?><?php echo str_repeat( '&#847;&zwnj;&nbsp;', 60 ); // phpcs:ignore ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php echo esc_attr( $c['page'] ); ?>" style="background:<?php echo esc_attr( $c['page'] ); ?>;">
<tr><td align="center" style="padding:24px 10px 0;">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" class="nl-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;margin:0 auto;">

	<tr><td class="nl-px" bgcolor="<?php echo esc_attr( $head ); ?>" style="background:<?php echo esc_attr( $head ); ?>;border-radius:16px 16px 0 0;padding:26px 32px 30px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
			<td valign="middle"><a href="<?php echo esc_url( oec_config_url( '/?utm_source=mailing&utm_medium=newsletter' ) ); ?>" target="_blank"><img src="<?php echo esc_url( OEC_NL_LOGO ); ?>" width="91" height="40" alt="<?php echo esc_attr( OEC_NL_BRAND ); ?>" style="display:block;width:91px;height:40px;"></a></td>
			<?php if ( $a['label'] ) : ?>
			<td valign="middle" align="right" style="font-family:<?php echo esc_attr( $f ); ?>;">
				<span style="display:block;font-size:11px;line-height:15px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:<?php echo esc_attr( $lcolor ); ?>;"><?php echo esc_html( $a['label'] ); ?></span>
				<?php if ( $a['sublabel'] ) : ?><span style="display:block;font-size:13px;line-height:18px;color:<?php echo esc_attr( $c['soft'] ); ?>;"><?php echo esc_html( $a['sublabel'] ); ?></span><?php endif; ?>
			</td>
			<?php endif; ?>
		</tr></table>
		<?php if ( $a['heading'] ) : ?>
		<h1 class="nl-h1" style="margin:28px 0 0;font-family:<?php echo esc_attr( $f ); ?>;font-size:30px;line-height:36px;font-weight:800;color:#ffffff;"><?php echo $a['heading']; // phpcs:ignore -- escapado por quien llama ?></h1>
		<?php endif; ?>
		<?php if ( $a['intro'] ) : ?>
		<p style="margin:12px 0 0;font-family:<?php echo esc_attr( $f ); ?>;font-size:16px;line-height:24px;color:<?php echo esc_attr( $c['soft'] ); ?>;"><?php echo $a['intro']; // phpcs:ignore -- escapado por quien llama ?></p>
		<?php endif; ?>
	</td></tr>

	<?php if ( $a['banner'] ) : ?>
	<tr><td bgcolor="<?php echo esc_attr( $head ); ?>" style="padding:0;font-size:0;line-height:0;background:<?php echo esc_attr( $head ); ?>;"><img src="<?php echo esc_url( $a['banner'] ); ?>" width="600" alt="<?php echo esc_attr( $a['banner_alt'] ); ?>" style="display:block;width:100%;max-width:600px;height:auto;"></td></tr>
	<?php endif; ?>

	<?php echo $a['body']; // phpcs:ignore ?>

	<tr><td bgcolor="#ffffff" style="background:#ffffff;border-radius:0 0 16px 16px;font-size:0;line-height:0;height:16px;">&nbsp;</td></tr>

	<?php echo oec_nl_email_footer( (bool) $a['subscribed'] ); // phpcs:ignore ?>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/**
 * Pie: redes, motivo del envío, baja y legales. $subscribed = true para el
 * newsletter (link de baja y dirección de la cuenta vía merge tags de
 * Elastic Email); el email de confirmación usa la dirección fija.
 */
function oec_nl_email_footer( bool $subscribed ): string {
	$c     = oec_nl_colors();
	$f     = OEC_NL_FONT;
	$small = 'margin:0 0 10px;font-family:' . $f . ';font-size:11px;line-height:17px;color:' . $c['muted'] . ';';
	$link  = 'color:' . $c['primary'] . ';text-decoration:underline;';
	$icon  = 'https://d2u6lzrmbvw8bs.cloudfront.net/assets/social-icons/%1$s/%1$s-round-solid-color.png';

	$socials = '';
	foreach ( [ 'facebook' => OEC_NL_FACEBOOK, 'instagram' => OEC_NL_INSTAGRAM, 'whatsapp' => OEC_NL_WHATSAPP ] as $net => $url ) {
		$socials .= '<td style="padding:0 8px;"><a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( sprintf( $icon, $net ) ) . '" width="32" height="32" alt="' . esc_attr( ucfirst( $net ) ) . '" style="display:block;width:32px;height:32px;"></a></td>';
	}

	$reason = $subscribed
		? 'Recibís este email porque estás suscripto al newsletter de ' . esc_html( OEC_NL_BRAND ) . '.<br><a href="{view}" style="' . $link . '">Ver en el navegador</a> &nbsp;·&nbsp; <a href="{unsubscribe}" style="' . $link . '">Darme de baja</a>'
		: 'Recibís este email porque pediste suscribirte al newsletter de ' . esc_html( OEC_NL_BRAND ) . '. Si no fuiste vos, ignoralo: no te vamos a escribir.';

	$address = $subscribed
		? '{accountaddress}'
		: esc_html( OEC_NL_ADDRESS ) . ' · <a href="' . esc_url( oec_config_url( '/' ) ) . '" style="' . $link . '">' . esc_html( wp_parse_url( oec_config_url( '/' ), PHP_URL_HOST ) ) . '</a>';

	return '<tr><td align="center" class="nl-px" style="padding:28px 32px 40px;">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>' . $socials . '</tr></table>'
		. '<p style="' . $small . 'margin-top:20px;font-size:12px;">' . $reason . '</p>'
		. '<p style="' . $small . 'font-size:10px;line-height:15px;">Este mensaje puede contener información confidencial y está destinado únicamente a su destinatario. OEC no se hace responsable de errores u omisiones ni de daños derivados del uso del correo electrónico; las opiniones expresadas son responsabilidad de su autor.</p>'
		. '<p style="' . $small . 'font-size:10px;line-height:15px;margin:0;">' . $address . '</p>'
		. '</td></tr>';
}

/* ============================================================
   EMAIL DE CONFIRMACIÓN (bienvenida al newsletter elegido, en 3 pasos)
   ============================================================ */

/**
 * Temática de la suscripción: los datos de su landing (título, acento,
 * tinte, portada) si se eligió UNA sola lista y es de una temática; null
 * para el newsletter general o una combinación.
 */
function oec_nl_confirm_topic( array $lists ): ?array {
	$lists = array_values( array_unique( $lists ) );
	if ( 1 !== count( $lists ) || OEC_NL_GENERAL_LIST === $lists[0] || ! function_exists( 'oec_nl_especiales_lists' ) || ! function_exists( 'oec_get_especiales_list' ) ) {
		return null;
	}
	$tematica = oec_nl_especiales_lists()[ $lists[0] ]['tematica'] ?? '';
	foreach ( oec_get_especiales_list() as $e ) {
		if ( $tematica && $e['tematica'] === $tematica ) {
			return $e;
		}
	}
	return null;
}

/**
 * Las listas en una frase: "el newsletter semanal y el newsletter de
 * Endurance". Con $al = true, para después de "suscripción": "al
 * newsletter semanal y al newsletter de Endurance".
 */
function oec_nl_lists_phrase( array $lists, bool $al = false ): string {
	$especiales = function_exists( 'oec_nl_especiales_lists' ) ? oec_nl_especiales_lists() : [];
	$opts       = function_exists( 'oec_nl_opts' ) ? oec_nl_opts()['lists'] : [];
	$names      = [];
	foreach ( array_unique( $lists ) ?: [ OEC_NL_GENERAL_LIST ] as $list ) {
		$names[] = ( $al ? 'al ' : 'el ' ) . ( OEC_NL_GENERAL_LIST === $list
			? 'newsletter semanal'
			: 'newsletter de ' . ( $especiales[ $list ]['label'] ?? ( $opts[ $list ]['label'] ?? $list ) ) );
	}
	$last = array_pop( $names );
	return $names ? implode( ', ', $names ) . ' y ' . $last : $last;
}

/**
 * Franja JPG de 1200×440 de la portada de una temática para el email (las
 * portadas son PNG/WebP de varios MB; Outlook no muestra WebP). Se genera
 * una vez en uploads/oec-theme/ y se regenera si cambia la portada.
 */
function oec_nl_topic_banner( array $topic ): string {
	$src = (string) ( $topic['image'] ?? '' );
	$pos = strpos( $src, '/wp-content/' );
	if ( false === $pos ) {
		return '';
	}
	$file = WP_CONTENT_DIR . '/' . substr( $src, $pos + strlen( '/wp-content/' ) );
	if ( ! is_file( $file ) ) {
		return '';
	}
	$up   = wp_upload_dir();
	$name = 'email-' . sanitize_title( $topic['tematica'] ) . '-' . substr( md5( $file . filemtime( $file ) ), 0, 8 ) . '.jpg';
	$dir  = trailingslashit( $up['basedir'] ) . 'oec-theme';
	$url  = trailingslashit( $up['baseurl'] ) . 'oec-theme/' . $name;
	if ( is_file( "$dir/$name" ) ) {
		return $url;
	}
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( 1200, 440, true ) ) ) {
		return '';
	}
	wp_mkdir_p( $dir );
	$editor->set_quality( 80 );
	$saved = $editor->save( "$dir/$name", 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return '';
	}
	// Las versiones viejas de esa temática ya no se usan.
	foreach ( glob( "$dir/email-" . sanitize_title( $topic['tematica'] ) . '-*.jpg' ) ?: [] as $old ) {
		if ( basename( $old ) !== $name ) {
			wp_delete_file( $old );
		}
	}
	return $url;
}

/** Asunto del email de confirmación. */
function oec_nl_confirm_subject( array $lists ): string {
	$topic = oec_nl_confirm_topic( $lists );
	return $topic
		? sprintf( 'Confirmá tu suscripción a %s y sumá %d créditos', $topic['title'], OEC_NL_SUBSCRIBE_CREDITS )
		: sprintf( 'Confirmá tu suscripción y sumá %d créditos', OEC_NL_SUBSCRIBE_CREDITS );
}

function oec_nl_render_confirm_email( string $first, string $url, array $lists = [ OEC_NL_GENERAL_LIST ] ): string {
	$c      = oec_nl_colors();
	$f      = OEC_NL_FONT;
	$topic  = oec_nl_confirm_topic( $lists );
	$accent = $topic ? ( sanitize_hex_color( $topic['accent'] ?? '' ) ?: $c['accent'] ) : $c['accent'];
	$dark   = $topic ? ( sanitize_hex_color( $topic['tinte'] ?? '' ) ?: $c['dark'] ) : $c['dark'];
	$what   = oec_nl_lists_phrase( $lists );        // "el newsletter de Nutrición Deportiva"
	$to     = oec_nl_lists_phrase( $lists, true );  // "al newsletter de Nutrición Deportiva"
	$email  = preg_replace( '/^.*<([^>]+)>$/', '$1', OEC_NL_FROM );
	$h      = 'margin:0 0 6px;font-family:' . $f . ';font-size:18px;line-height:24px;font-weight:800;color:' . $c['text'] . ';';
	$p      = 'margin:0 0 12px;font-family:' . $f . ';font-size:15px;line-height:23px;color:' . $c['muted'] . ';';
	$icon   = 'https://d2u6lzrmbvw8bs.cloudfront.net/assets/social-icons/%1$s/%1$s-round-solid-color.png';

	$step = function ( int $n, string $inner, bool $last = false ) use ( $c, $f, $accent, $dark ) {
		return '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:' . ( 1 === $n ? '32' : '0' ) . 'px 32px ' . ( $last ? '8' : '28' ) . 'px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td width="56" valign="top" style="width:56px;padding-top:2px;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="40" height="40" align="center" valign="middle" bgcolor="' . esc_attr( $dark ) . '" style="width:40px;height:40px;border-radius:20px;background:' . esc_attr( $dark ) . ';font-family:' . $f . ';font-size:17px;font-weight:800;line-height:40px;color:' . esc_attr( $accent ) . ';">' . $n . '</td></tr></table>'
			. '</td><td valign="top">' . $inner . '</td></tr></table>'
			. ( $last ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding-top:28px;border-bottom:1px solid ' . esc_attr( $c['border'] ) . ';font-size:0;line-height:0;">&nbsp;</td></tr></table>' )
			. '</td></tr>';
	};

	$socials = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td style="padding:4px 12px 0 0;"><a href="' . esc_url( OEC_NL_FACEBOOK ) . '" target="_blank"><img src="' . esc_url( sprintf( $icon, 'facebook' ) ) . '" width="40" height="40" alt="Facebook" style="display:block;width:40px;height:40px;"></a></td>'
		. '<td style="padding:4px 0 0;"><a href="' . esc_url( OEC_NL_INSTAGRAM ) . '" target="_blank"><img src="' . esc_url( sprintf( $icon, 'instagram' ) ) . '" width="40" height="40" alt="Instagram" style="display:block;width:40px;height:40px;"></a></td>'
		. '</tr></table>';

	$weekly = $topic
		? 'Así vas a recibir todos los ' . esc_html( oec_nl_weekday_plural() ) . ' lo nuevo en <strong style="color:' . esc_attr( $c['text'] ) . ';">' . esc_html( $topic['title'] ) . '</strong>: artículos, blogs y formaciones seleccionadas de la temática.'
		: 'Así vas a recibir ' . esc_html( $what ) . ' todos los ' . esc_html( oec_nl_weekday_plural() ) . ', con artículos, blogs y ofertas exclusivas en formaciones.';

	$body = $step( 1,
		'<p style="' . $h . '">Seguinos en redes</p>'
		. '<p style="' . $p . '">Contenido académico, novedades en formaciones, vivos y descuentos especiales.</p>' . $socials
	)
	. $step( 2,
		'<p style="' . $h . '">Asegurá nuestros emails</p>'
		. '<p style="' . $p . '">Si este email llegó a spam o promociones, marcalo como <strong style="color:' . esc_attr( $c['text'] ) . ';">no es spam</strong> y agregá <a href="mailto:' . esc_attr( $email ) . '" style="color:' . esc_attr( $c['primary'] ) . ';font-weight:700;text-decoration:none;">' . esc_html( $email ) . '</a> a tus contactos.</p>'
		. '<p style="' . $p . 'margin:0;">' . $weekly . '</p>'
	)
	. $step( 3,
		'<p style="' . $h . '">Confirmá y sumá ' . (int) OEC_NL_SUBSCRIBE_CREDITS . ' créditos</p>'
		. '<p style="' . $p . '">Tocá el botón para confirmar tu suscripción. Los créditos se acreditan al instante y los podés canjear por descuentos en las mejores formaciones de ciencias del ejercicio en habla hispana.</p>'
		. oec_nl_btn( esc_url( $url ), 'Confirmar y obtener mis créditos', $accent, $c['dark'] )
		. '<p style="' . $p . 'margin:14px 0 0;font-size:13px;line-height:19px;">¿Cómo se usan los créditos? <a href="' . esc_url( oec_config_url( '/creditos-por-descuentos' ) ) . '" style="color:' . esc_attr( $c['primary'] ) . ';font-weight:700;text-decoration:none;">Te lo explicamos acá</a>. El link vence en ' . (int) OEC_NL_CONFIRM_HOURS . ' horas.</p>',
		true
	);

	return oec_nl_email_shell( [
		'title'       => $topic ? 'Newsletter de ' . $topic['title'] : 'Newsletter semanal de G-SE',
		'preheader'   => sprintf( 'Confirmá tu suscripción %s para sumar %d créditos.', $to, OEC_NL_SUBSCRIBE_CREDITS ),
		'label'       => $topic ? 'Newsletter · ' . $topic['title'] : 'Newsletter semanal',
		'label_color' => $accent,
		'header_bg'   => $dark,
		'banner'      => $topic ? oec_nl_topic_banner( $topic ) : '',
		'banner_alt'  => $topic ? $topic['title'] : '',
		'heading'     => $first ? '¡Excelente, ' . esc_html( $first ) . '!' : '¡Excelente!',
		'intro'       => esc_html( sprintf( 'Confirmá tu suscripción %s para sumar %d créditos. Son solo tres pasos:', $to, OEC_NL_SUBSCRIBE_CREDITS ) ),
		'body'        => $body,
		'subscribed'  => false,
	] );
}

/* ============================================================
   NEWSLETTER SEMANAL
   ============================================================ */

/**
 * Miniatura del artículo apta para email. Outlook de escritorio no muestra
 * WebP: en ese caso se genera una sola vez una copia JPG de 360×226
 * (2× del tamaño que se muestra) y se guarda en el meta del adjunto.
 */
function oec_nl_post_thumb_url( WP_Post $post ): string {
	$id  = (int) get_post_thumbnail_id( $post );
	$url = $id ? (string) get_the_post_thumbnail_url( $post, 'oec-thumb' ) : '';
	if ( ! $url || 'image/webp' !== get_post_mime_type( $id ) ) {
		return $url;
	}

	$cached = get_post_meta( $id, '_oec_nl_email_jpg', true );
	if ( $cached ) {
		return (string) $cached;
	}

	$file   = get_attached_file( $id );
	$editor = $file ? wp_get_image_editor( $file ) : null;
	if ( ! $editor || is_wp_error( $editor ) ) {
		return $url; // sin editor de imágenes: mejor WebP que nada
	}
	$editor->resize( 360, 226, true ); // si es más chica que eso, falla y se convierte tal cual
	$dest  = preg_replace( '/\.webp$/i', '', $file ) . '-email-360x226.jpg';
	$saved = $editor->save( $dest, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return $url;
	}
	$jpg = trailingslashit( dirname( wp_get_attachment_url( $id ) ) ) . wp_basename( $saved['path'] );
	update_post_meta( $id, '_oec_nl_email_jpg', $jpg );
	return $jpg;
}

/** "Artículo · Nutrición Deportiva" a partir de las categorías del post. */
function oec_nl_post_eyebrow( WP_Post $post ): string {
	$tipos  = function_exists( 'oec_tipo_labels' ) ? oec_tipo_labels() : [];
	$tipo   = '';
	$tema   = '';
	foreach ( get_the_category( $post->ID ) as $cat ) {
		if ( isset( $tipos[ $cat->slug ] ) ) {
			$tipo = $tipo ?: $tipos[ $cat->slug ];
		} elseif ( 'general' !== $cat->slug && ! $tema ) {
			$tema = $cat->name;
		}
	}
	return implode( ' · ', array_filter( [ $tipo ?: 'Artículo', $tema ] ) );
}

function oec_nl_post_html( WP_Post $post ): string {
	$c     = oec_nl_colors();
	$f     = OEC_NL_FONT;
	$link  = esc_url( oec_nl_post_link( $post ) );
	$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$img   = oec_nl_post_thumb_url( $post );
	$mins  = function_exists( 'oec_reading_time' ) ? oec_reading_time( $post->ID ) : 0;
	$meta  = wp_date( 'j \d\e F', get_post_timestamp( $post ) ) . ( $mins ? ' · ' . $mins . ' min de lectura' : '' );

	$text = '<p style="margin:0 0 6px;font-family:' . $f . ';font-size:11px;line-height:15px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:' . $c['accent'] . ';">' . esc_html( oec_nl_post_eyebrow( $post ) ) . '</p>'
		. '<h2 style="margin:0 0 6px;font-family:' . $f . ';font-size:17px;line-height:23px;font-weight:800;"><a href="' . $link . '" target="_blank" style="color:' . $c['text'] . ';text-decoration:none;">' . esc_html( $title ) . '</a></h2>'
		. '<p style="margin:0 0 8px;font-family:' . $f . ';font-size:12px;line-height:16px;color:' . $c['muted'] . ';">' . esc_html( $meta ) . '</p>'
		. '<p style="margin:0 0 10px;font-family:' . $f . ';font-size:14px;line-height:21px;color:' . $c['muted'] . ';">' . esc_html( oec_nl_truncate( get_the_excerpt( $post ), 150 ) ) . '</p>'
		. '<a href="' . $link . '" target="_blank" style="font-family:' . $f . ';font-size:14px;line-height:18px;font-weight:700;color:' . $c['primary'] . ';text-decoration:none;">Leer artículo &rarr;</a>';

	$row = $img
		? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td class="nl-stack nl-thumb" width="180" valign="top" style="width:180px;padding:0 20px 0 0;"><a href="' . $link . '" target="_blank"><img src="' . esc_url( $img ) . '" width="180" height="113" alt="' . esc_attr( $title ) . '" style="display:block;width:180px;height:113px;border-radius:10px;"></a></td>'
			. '<td class="nl-stack" valign="top">' . $text . '</td>'
			. '</tr></table>'
		: $text;

	return '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:24px 32px;">' . $row . '</td></tr>'
		. '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-bottom:1px solid ' . $c['border'] . ';font-size:0;line-height:0;height:1px;">&nbsp;</td></tr></table></td></tr>';
}

/** Etiquetas de la tarjeta: "Curso • 3ª ed • Online • Sincrónica". */
function oec_nl_training_tags( array $t ): string {
	$sync = [ 'SYNC' => 'Sincrónica', 'ASYNC' => 'Asincrónica', 'MIXED' => 'Mixta' ];
	$tags = [
		$t['type'] ?? '',
		! empty( $t['edition_number'] ) ? (int) $t['edition_number'] . 'ª ed' : '',
		! empty( $t['modality'] ) ? ucfirst( mb_strtolower( (string) $t['modality'] ) ) : '',
		$sync[ $t['synchronicity'] ?? '' ] ?? ( ! empty( $t['synchronicity'] ) ? ucfirst( mb_strtolower( (string) $t['synchronicity'] ) ) : '' ),
	];
	return implode( ' • ', array_filter( $tags ) );
}

/** Tarjeta de formación, con la misma estructura que las del sitio. */
function oec_nl_training_html( array $t ): string {
	$c     = oec_nl_colors();
	$f     = OEC_NL_FONT;
	$link  = esc_attr( oec_nl_training_link( $t ) ); // esc_attr: esc_url rompería los merge tags {{ … }}
	$title = (string) ( $t['title'] ?? '' );
	$img   = (string) ( $t['image'] ?? '' );
	if ( $img && function_exists( 'oec_cdn_resize' ) ) {
		$img = oec_cdn_resize( $img, 1072, 85 ); // 2x para pantallas retina
	}

	$badges = '';
	$labels = [
		'great_lecturers'     => 'Docentes destacados',
		'great_topic'         => 'Temática destacada',
		'great_organizer'     => 'Organizador destacado',
		'great_certification' => 'Certificación destacada',
		'great_price'         => 'Precio destacado',
	];
	foreach ( $labels as $key => $label ) {
		if ( ! empty( $t[ $key ] ) && substr_count( $badges, '<span' ) < 2 ) {
			$badges .= oec_nl_pill( $label, '#fff4e5', '#9a5b0f' );
		}
	}

	// Cierre de inscripciones (o inicio) si todavía no pasó.
	$date_label = '';
	$date_value = '';
	foreach ( [ 'enrollment_end' => 'Cierre de inscripciones', 'start' => 'Inicio' ] as $key => $label ) {
		$ts = ! empty( $t[ $key ] ) ? strtotime( (string) $t[ $key ] ) : 0;
		if ( $ts && $ts > time() ) {
			$date_label = $label;
			$date_value = wp_date( 'j \d\e F', $ts );
			break;
		}
	}

	$org = (string) ( $t['organization']['name'] ?? '' );
	$cta = 'Ver ' . ( ( $t['type'] ?? '' ) ?: 'formación' );

	$html = '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:24px 32px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $c['border'] . ';border-radius:12px;border-collapse:separate;">';

	if ( $img ) {
		$html .= '<tr><td style="padding:0;border-radius:12px 12px 0 0;"><a href="' . $link . '" target="_blank"><img src="' . esc_url( $img ) . '" width="534" alt="' . esc_attr( $title ) . '" style="display:block;width:100%;max-width:534px;height:auto;border-radius:12px 12px 0 0;"></a></td></tr>';
	}

	$html .= '<tr><td style="padding:20px 22px 18px;">'
		. ( $badges ? '<div style="margin:0 0 4px;">' . $badges . '</div>' : '' )
		. '<p style="margin:0 0 8px;font-family:' . $f . ';font-size:11px;line-height:15px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:' . $c['muted'] . ';">' . esc_html( oec_nl_training_tags( $t ) ) . '</p>'
		. '<h2 style="margin:0 0 6px;font-family:' . $f . ';font-size:19px;line-height:25px;font-weight:800;"><a href="' . $link . '" target="_blank" style="color:' . $c['text'] . ';text-decoration:none;">' . esc_html( $title ) . '</a></h2>'
		. ( $org ? '<p style="margin:0 0 10px;font-family:' . $f . ';font-size:13px;line-height:18px;color:' . $c['muted'] . ';">por <strong style="color:' . $c['text'] . ';">' . esc_html( $org ) . '</strong></p>' : '' )
		. '<p style="margin:0;font-family:' . $f . ';font-size:14px;line-height:21px;color:' . $c['muted'] . ';">' . esc_html( oec_nl_truncate( (string) ( $t['short_description'] ?? '' ), 170 ) ) . '</p>'
		. '</td></tr>';

	$html .= '<tr><td bgcolor="' . $c['light'] . '" style="background:' . $c['light'] . ';border-top:1px solid ' . $c['border'] . ';border-radius:0 0 12px 12px;padding:14px 22px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td class="nl-stack" valign="middle" style="font-family:' . $f . ';">'
		. ( $date_value
			? '<span style="display:block;font-size:10px;line-height:14px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:' . $c['accent'] . ';">' . esc_html( $date_label ) . '</span>'
				. '<span style="display:block;font-size:15px;line-height:20px;font-weight:700;color:' . $c['text'] . ';">' . esc_html( $date_value ) . '</span>'
			: '&nbsp;' )
		. '</td>'
		. '<td class="nl-stack nl-gap" valign="middle" align="right" width="170" style="width:170px;">' . oec_nl_btn( $link, $cta, $c['primary'], '#ffffff', true ) . '</td>'
		. '</tr></table>'
		. '</td></tr>';

	return $html . '</table></td></tr>';
}

/** Banner de los créditos semanales (va arriba: Gmail recorta el final de los emails largos). */
function oec_nl_claim_html( string $claim ): string {
	$c = oec_nl_colors();
	$f = OEC_NL_FONT;
	return '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:28px 32px 8px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fff7ec" style="background:#fff7ec;border:1px solid #f6d9b1;border-radius:12px;border-collapse:separate;"><tr>'
		. '<td class="nl-stack" width="110" valign="middle" align="center" style="width:110px;padding:18px 0 18px 18px;font-family:' . $f . ';">'
		. '<span style="display:block;font-size:34px;line-height:36px;font-weight:800;color:' . $c['accent'] . ';">+' . (int) OEC_NL_WEEKLY_CREDITS . '</span>'
		. '<span style="display:block;font-size:11px;line-height:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#9a5b0f;">créditos</span>'
		. '</td>'
		. '<td class="nl-stack" valign="middle" style="padding:18px 20px;font-family:' . $f . ';">'
		. '<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:' . $c['text'] . ';"><strong>Tus créditos de esta semana te esperan.</strong> Reclamalos y canjealos por descuentos exclusivos en nuestras formaciones.</p>'
		. oec_nl_btn( esc_attr( $claim ), 'Obtener mis créditos', $c['accent'], $c['dark'] )
		. '</td>'
		. '</tr></table>'
		. '</td></tr>';
}

/**
 * Intercala artículos y formaciones como el script original: 3 artículos,
 * formación, 1 artículo, formación, 2 artículos, formación, formación,
 * 2 artículos, … y los artículos que sobren al final.
 */
function oec_nl_mix_html( array $posts, array $trainings ): string {
	$before = [ 3, 1, 2, 0, 2, 0, 0 ];
	$html   = '';
	foreach ( array_values( $trainings ) as $i => $training ) {
		for ( $n = 0; $n < ( $before[ $i ] ?? 0 ) && $posts; $n++ ) {
			$html .= oec_nl_post_html( array_shift( $posts ) );
		}
		$html .= oec_nl_training_html( $training );
	}
	foreach ( $posts as $post ) {
		$html .= oec_nl_post_html( $post );
	}
	return $html;
}

/** Newsletter semanal completo. */
function oec_nl_render_email( array $d ): string {
	$c = oec_nl_colors();

	$cta = '<tr><td class="nl-px" bgcolor="#ffffff" align="center" style="background:#ffffff;padding:32px 32px 16px;">'
		. '<p style="margin:0 0 14px;font-family:' . OEC_NL_FONT . ';font-size:15px;line-height:22px;color:' . $c['muted'] . ';">¿Buscás algo en particular? Tenemos cientos de formaciones abiertas.</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td>' . oec_nl_btn( esc_url( oec_config_url( '/formaciones/?utm_source=mailing&utm_medium=elastic&utm_campaign=newsletter+semanal' ) ), 'Ver todas las formaciones', $c['dark'], '#ffffff' ) . '</td></tr></table>'
		. '</td></tr>';

	return oec_nl_email_shell( [
		'title'      => OEC_NL_BRAND . ' · Newsletter',
		'preheader'  => 'Artículos, blogs y formaciones seleccionadas para {firstname}.',
		'label'      => 'Newsletter semanal',
		'sublabel'   => $d['date'],
		'heading'    => $d['topic'] ? 'Lo nuevo en ' . esc_html( $d['topic'] ) : 'Lo nuevo de esta semana',
		'intro'      => 'Hola {firstname}, estos son los artículos y formaciones que seleccionamos para vos.',
		'body'       => oec_nl_claim_html( $d['claim'] ) . $d['content'] . $cta,
		'subscribed' => true,
	] );
}
