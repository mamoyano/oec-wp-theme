<?php
/**
 * Template: Links (/es/links) — la página del enlace de la bio de Instagram.
 * Se activa sola para la página con slug "links" (la crea
 * oec_create_links_page(), en inc/template-functions.php).
 *
 * Pensada para el navegador interno de Instagram en un celular: página
 * independiente, sin header/footer ni el CSS general del tema. Todo el CSS
 * va inline (~5 KB) y los íconos son el recorte del tema (~10 KB, la misma
 * fuente que ya usa el resto del sitio). Sin jQuery ni JS propio: el
 * buscador es un formulario GET a /formaciones?q=. GTM, Pixel y Clarity
 * salen diferidos como en todo el sitio, y el fbclid sigue hasta el checkout.
 *
 * "Cierran pronto" sale del catálogo sincronizado (listing.json): por eso
 * la página vive 1 día en Cloudflare y se borra tras cada sync
 * (inc/cloudflare.php). No va al sitemap y es noindex: es un atajo, no
 * contenido para buscadores.
 */

$opts      = function_exists( 'oec_get_options' ) ? oec_get_options() : [];
$logo      = (string) ( $opts['logo_url'] ?? '' );
$logo_box  = $logo && function_exists( 'oec_logo_box' ) ? oec_logo_box( $logo, 44 ) : null;
$whatsapp  = 'https://api.whatsapp.com/send?phone=5493512584960';
$campus    = (string) ( $opts['campus_virtual_url'] ?? '' );
$page_url  = static fn( string $slug ) => ( $p = get_page_by_path( $slug ) ) ? get_permalink( $p ) : '';
$abiertas  = class_exists( 'OEC_AI_Catalog' ) ? count( OEC_AI_Catalog::get_index() ) : 0;
$temas     = function_exists( 'oec_get_especiales_list' ) ? oec_get_especiales_list() : [];
$creditos  = $page_url( 'creditos-por-descuentos' );

// Cierran pronto: las 4 abiertas cuya inscripción vence antes.
$hoy    = current_time( 'Y-m-d' );
$pronto = class_exists( 'OEC_AI_Catalog' ) ? array_filter(
	OEC_AI_Catalog::get_listing(),
	fn( $f ) => ! empty( $f['open'] ) && ! empty( $f['slug'] ) && ( $f['enrollment_end'] ?? '' ) >= $hoy
) : [];
usort( $pronto, fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ) );
$pronto  = array_slice( $pronto, 0, 4 );
$cierra  = function ( string $fecha ) use ( $hoy ): string {
	$dias = (int) round( ( strtotime( $fecha ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
	if ( 0 === $dias ) {
		return __( 'Cierra hoy', 'oec-theme' );
	}
	if ( 1 === $dias ) {
		return __( 'Cierra mañana', 'oec-theme' );
	}
	return sprintf( __( 'Cierra el %s', 'oec-theme' ), wp_date( 'j/n', strtotime( $fecha . ' 12:00' ) ) );
};

$botones = array_filter( [
	[ oec_formaciones_url(), 'mortarboard', __( 'Formaciones abiertas', 'oec-theme' ), $abiertas ? sprintf( __( '%s cursos, talleres y posgrados', 'oec-theme' ), number_format_i18n( $abiertas ) ) : __( 'Cursos, talleres y posgrados', 'oec-theme' ), 'main' ],
	[ $whatsapp, 'whatsapp', __( 'Escribinos por WhatsApp', 'oec-theme' ), __( 'Te ayudamos a elegir', 'oec-theme' ), 'wa' ],
	[ oec_articulos_url( [] ), 'journal-text', __( 'Artículos y blogs', 'oec-theme' ), __( 'La ciencia del ejercicio, explicada', 'oec-theme' ), '' ],
	[ $creditos, 'gift', __( 'Créditos por descuentos', 'oec-theme' ), __( 'Sumá créditos y pagá menos', 'oec-theme' ), '' ],
	[ $creditos ? $creditos . '#newsletter' : '', 'stars', __( 'Newsletter semanal', 'oec-theme' ), __( 'Novedades y créditos cada semana', 'oec-theme' ), '' ],
	[ $page_url( 'docentes' ), 'person-video3', __( 'Docentes', 'oec-theme' ), __( 'Referentes de todo el mundo', 'oec-theme' ), '' ],
	[ $campus, 'laptop', __( 'Campus virtual', 'oec-theme' ), __( 'Ingresá a tus cursos', 'oec-theme' ), '' ],
	[ $page_url( 'quienes-somos' ), 'people', __( 'Quiénes somos', 'oec-theme' ), '', '' ],
], fn( $b ) => '' !== $b[0] );

$redes = array_filter( [
	'instagram' => $opts['social_instagram'] ?? '',
	'youtube'   => $opts['social_youtube'] ?? '',
	'facebook'  => $opts['social_facebook'] ?? '',
	'linkedin'  => $opts['social_linkedin'] ?? '',
	'twitter-x' => $opts['social_x'] ?? '',
] );

$titulo = get_bloginfo( 'name' );
$desc   = __( 'Formaciones, artículos y novedades en ciencias del ejercicio: todo a un toque.', 'oec-theme' );
$icons  = OEC_THEME_DIR . '/assets/fonts/bootstrap-icons/bootstrap-icons-subset.css';
$icons_url = function_exists( 'oec_unprefix_asset_src' ) ? oec_unprefix_asset_src( OEC_THEME_URI . '/assets/fonts/bootstrap-icons' ) : OEC_THEME_URI . '/assets/fonts/bootstrap-icons';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo esc_html( $titulo . ' · Links' ); ?></title>
<meta name="description" content="<?php echo esc_attr( $desc ); ?>">
<meta name="robots" content="noindex, follow">
<meta name="theme-color" content="#071b2d">
<link rel="canonical" href="<?php echo esc_url( get_permalink() ); ?>">
<meta property="og:title" content="<?php echo esc_attr( $titulo ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
<meta property="og:url" content="<?php echo esc_url( get_permalink() ); ?>">
<?php if ( $logo ) : ?><meta property="og:image" content="<?php echo esc_url( $logo ); ?>">
<?php endif; ?>
<?php if ( get_site_icon_url() ) : ?><link rel="icon" href="<?php echo esc_url( get_site_icon_url( 32 ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( get_site_icon_url( 180 ) ); ?>">
<?php endif; ?>
<link rel="preconnect" href="https://imgrsize.oe-img.center">
<link rel="preload" href="<?php echo esc_url( $icons_url . '/bootstrap-icons-subset.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<style>
<?php echo is_readable( $icons ) ? str_replace( '{URL}', esc_url_raw( $icons_url ), trim( (string) file_get_contents( $icons ) ) ) : ''; // phpcs:ignore ?>

:root{--dark:#071b2d;--primary:#194872;--accent:#e8952a;--bg:#f2f5f9;--card:#fff;--text:#1c2330;--muted:#5d6878;--line:#e2e8f0;--wa:#1faa59}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:480px;margin:0 auto;padding:0 18px calc(28px + env(safe-area-inset-bottom))}
.hero{background:linear-gradient(160deg,var(--dark) 0%,var(--primary) 100%);color:#fff;padding:28px 0 64px;text-align:center}
.hero img{display:block;margin:0 auto 14px;height:44px;width:auto}
.hero h1{margin:0 0 6px;font-size:20px;font-weight:800;letter-spacing:-.01em}
.hero p{margin:0;font-size:14px;color:rgba(255,255,255,.75)}
.search{margin:-40px 0 14px;display:flex;align-items:center;gap:8px;background:var(--card);border-radius:16px;padding:6px 6px 6px 16px;box-shadow:0 10px 30px rgba(7,27,45,.18)}
.search i{color:var(--muted);font-size:18px}
.search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:16px;padding:12px 0;color:var(--text)}
.search button{border:0;border-radius:12px;background:var(--accent);color:var(--dark);font:inherit;font-weight:800;padding:12px 16px;cursor:pointer}
/* Temáticas: una sola fila que se desliza de costado, para que los botones queden a la vista. */
.temas{display:flex;gap:8px;margin:0 -18px 16px;padding:0 18px 4px;overflow-x:auto;scroll-snap-type:x proximity;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.temas::-webkit-scrollbar{display:none}
.temas a{flex:0 0 auto;scroll-snap-align:start;display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:999px;background:var(--card);border:1px solid var(--line);font-size:14px;font-weight:700;white-space:nowrap}
.temas a::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--c,var(--accent))}
.btns{display:grid;gap:10px;margin:0 0 26px}
.btn{display:flex;align-items:center;gap:14px;min-height:64px;padding:12px 16px;background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 2px rgba(7,27,45,.05);transition:transform .15s}
.btn:active{transform:scale(.98)}
.btn>i:first-child{flex:0 0 40px;height:40px;display:grid;place-items:center;border-radius:12px;background:rgba(25,72,114,.08);color:var(--primary);font-size:20px}
.btn span{flex:1;min-width:0}
.btn b{display:block;font-size:16px}
.btn small{display:block;font-size:13px;color:var(--muted);margin-top:2px}
.btn>i:last-child{color:#a0aab8}
.btn--main{background:var(--dark);border-color:var(--dark);color:#fff}
.btn--main>i:first-child{background:var(--accent);color:var(--dark)}
.btn--main small,.btn--main>i:last-child{color:rgba(255,255,255,.7)}
.btn--wa>i:first-child{background:var(--wa);color:#fff}
h2{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.pronto{display:grid;gap:10px;margin:0 0 26px}
.f{display:flex;gap:12px;align-items:center;padding:10px;background:var(--card);border:1px solid var(--line);border-radius:14px}
.f img{flex:0 0 64px;width:64px;height:64px;border-radius:10px;object-fit:cover;background:var(--line)}
.f b{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:15px;line-height:1.3}
.f small{display:inline-flex;align-items:center;gap:5px;margin-top:4px;font-size:12px;font-weight:800;color:#b45309}
.redes{display:flex;justify-content:center;gap:10px;margin:0 0 18px}
.redes a{width:46px;height:46px;display:grid;place-items:center;border-radius:50%;background:var(--card);border:1px solid var(--line);color:var(--primary);font-size:20px}
.pie{text-align:center;font-size:12px;color:var(--muted)}
.pie a{text-decoration:underline}
</style>
<?php
if ( function_exists( 'oec_output_trackers_head' ) ) {
	oec_output_trackers_head();
}
?>
</head>
<body>

<header class="hero">
	<div class="wrap">
		<?php if ( $logo ) : ?>
		<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $titulo ); ?>"<?php echo $logo_box ? sprintf( ' width="%d" height="%d"', $logo_box[0], $logo_box[1] ) : ''; ?> fetchpriority="high">
		<?php endif; ?>
		<h1><?php echo esc_html( $titulo ); ?></h1>
		<p><?php esc_html_e( 'Formación en ciencias del ejercicio', 'oec-theme' ); ?></p>
	</div>
</header>

<main class="wrap">

	<form class="search" role="search" method="get" action="<?php echo esc_url( oec_formaciones_url() ); ?>">
		<i class="bi bi-search" aria-hidden="true"></i>
		<input type="search" name="q" aria-label="<?php esc_attr_e( 'Buscar formaciones', 'oec-theme' ); ?>" placeholder="<?php esc_attr_e( '¿Qué querés aprender?', 'oec-theme' ); ?>" enterkeyhint="search" autocomplete="off">
		<button type="submit"><?php esc_html_e( 'Buscar', 'oec-theme' ); ?></button>
	</form>

	<?php if ( $temas ) : ?>
	<nav class="temas" aria-label="<?php esc_attr_e( 'Temáticas', 'oec-theme' ); ?>">
		<?php foreach ( $temas as $t ) : ?>
		<a href="<?php echo esc_url( $t['url'] ); ?>" style="--c:<?php echo esc_attr( $t['accent'] ?? '' ); ?>"><?php echo esc_html( $t['title'] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<nav class="btns" aria-label="<?php esc_attr_e( 'Accesos', 'oec-theme' ); ?>">
		<?php foreach ( $botones as [ $url, $icon, $label, $sub, $mod ] ) : ?>
		<a class="btn<?php echo $mod ? ' btn--' . esc_attr( $mod ) : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo str_starts_with( $url, home_url() ) ? '' : ' target="_blank" rel="noopener"'; ?>>
			<i class="bi bi-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
			<span><b><?php echo esc_html( $label ); ?></b><?php echo $sub ? '<small>' . esc_html( $sub ) . '</small>' : ''; ?></span>
			<i class="bi bi-chevron-right" aria-hidden="true"></i>
		</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( $pronto ) : ?>
	<h2><?php esc_html_e( 'Cierran pronto', 'oec-theme' ); ?></h2>
	<div class="pronto">
		<?php foreach ( $pronto as $f ) : ?>
		<a class="f" href="<?php echo esc_url( home_url( user_trailingslashit( '/formacion/' . $f['slug'] ) ) ); ?>">
			<?php if ( ! empty( $f['image'] ) ) : ?>
			<img src="<?php echo esc_url( oec_cdn_resize( $f['image'], 128, 75 ) ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
			<?php endif; ?>
			<span><b><?php echo esc_html( $f['title'] ); ?></b><small><i class="bi bi-clock" aria-hidden="true"></i><?php echo esc_html( $cierra( $f['enrollment_end'] ) ); ?></small></span>
		</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ( $redes ) : ?>
	<nav class="redes" aria-label="<?php esc_attr_e( 'Redes sociales', 'oec-theme' ); ?>">
		<?php foreach ( $redes as $icon => $url ) : ?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( ucfirst( str_replace( 'twitter-x', 'X', $icon ) ) ); ?>"><i class="bi bi-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<p class="pie"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a> · Online Education Center</p>

</main>

<?php
if ( function_exists( 'oec_output_fbclid_links' ) ) {
	oec_output_fbclid_links();
}
?>
</body>
</html>
