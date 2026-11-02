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
 * buscador es un formulario GET a /formaciones?q=. WhatsApp va solo como
 * ícono al pie, junto a las redes (cada conversación desde Meta tiene costo). GTM, Pixel y Clarity
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
	// Al final y sin botón propio: cada conversación iniciada desde Meta
	// tiene costo, así que no se destaca (pedido de Mario, 2026-10).
	'whatsapp'  => $whatsapp,
] );

// Campaña de descuentos por empezar o en curso (inc/campaign.php): va arriba
// de todo, con cuenta regresiva y sus 4 primeras formaciones. La página se
// borra de Cloudflare al cambiar de estado; por las dudas, el script de
// abajo la esconde si ya terminó.
$cmp_phase = function_exists( 'oec_cmp_view_phase' ) ? oec_cmp_view_phase() : 'off';
$cmp       = null;
if ( in_array( $cmp_phase, [ 'teaser', 'live' ], true ) ) {
	$cmp_rows = oec_cmp_formaciones( $cmp_phase );
	if ( $cmp_rows ) {
		$cmp_o   = oec_cmp_opts();
		$cmp_t   = oec_cmp_times( $cmp_o );
		$cmp_max = 'live' === $cmp_phase ? oec_cmp_max_pct( $cmp_rows ) : 0;
		$cmp_n   = sprintf( _n( '%d formación', '%d formaciones', count( $cmp_rows ), 'oec-theme' ), count( $cmp_rows ) );
		$cmp     = [
			'name'  => (string) $cmp_o['name'],
			'url'   => oec_cmp_url( $cmp_o ),
			'live'  => 'live' === $cmp_phase,
			'to'    => ( 'live' === $cmp_phase ? $cmp_t['end'] : $cmp_t['start'] ) * 1000,
			'end'   => $cmp_t['end'] * 1000,
			'head'  => 'live' === $cmp_phase
				? ( $cmp_max ? sprintf( 'Hasta %d%% OFF en %s', $cmp_max, $cmp_n ) : sprintf( '%s con descuento', $cmp_n ) )
				: sprintf( 'Descuentos fuertes en %s', $cmp_n ),
			'sub'   => 'live' === $cmp_phase
				? sprintf( 'Promo flash: termina el %s a las %s. Sin códigos.', wp_date( 'l j', $cmp_t['end'] ), wp_date( 'H:i', $cmp_t['end'] ) )
				: sprintf( 'Se viene: %s.', oec_cmp_dias_texto( $cmp_o ) ),
			'rows'  => array_slice( $cmp_rows, 0, 4 ),
			'dark'  => (string) $cmp_o['dark'],
			'dark2' => (string) $cmp_o['dark_2'],
			'acc'   => (string) $cmp_o['accent'],
		];
	}
}

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
<?php
// Para compartir: la tarjeta de marca (1200×630, JPEG); el logo es chico y .webp (WhatsApp no lo muestra).
$og_img = function_exists( 'oec_seo_share_card' ) ? oec_seo_share_card( 'generica' ) : null;
$og_img = $og_img ?: ( $logo ? [ $logo, 0, 0 ] : null );
?>
<?php if ( $og_img ) : ?><meta property="og:image" content="<?php echo esc_url( $og_img[0] ); ?>">
<?php if ( $og_img[1] ) : ?><meta property="og:image:width" content="<?php echo (int) $og_img[1]; ?>">
<meta property="og:image:height" content="<?php echo (int) $og_img[2]; ?>">
<?php endif; ?>
<?php endif; ?>
<?php if ( get_site_icon_url() ) : ?><link rel="icon" href="<?php echo esc_url( get_site_icon_url( 32 ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( get_site_icon_url( 180 ) ); ?>">
<?php endif; ?>
<link rel="preconnect" href="https://imgrsize.oe-img.center">
<link rel="preload" href="<?php echo esc_url( $icons_url . '/bootstrap-icons-subset.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<style>
<?php echo is_readable( $icons ) ? str_replace( '{URL}', esc_url_raw( $icons_url ), trim( (string) file_get_contents( $icons ) ) ) : ''; // phpcs:ignore ?>

:root{--dark:#071b2d;--primary:#194872;--accent:#e8952a;--bg:#f2f5f9;--card:#fff;--text:#1c2330;--muted:#5d6878;--line:#e2e8f0}
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
h2{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.pronto{display:grid;gap:10px;margin:0 0 26px}
.f{display:flex;gap:12px;align-items:center;padding:10px;background:var(--card);border:1px solid var(--line);border-radius:14px}
.f img{flex:0 0 64px;width:64px;height:64px;border-radius:10px;object-fit:cover;background:var(--line)}
.f b{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:15px;line-height:1.3}
.f small{display:inline-flex;align-items:center;gap:5px;margin-top:4px;font-size:12px;font-weight:800;color:#b45309}
.redes{display:flex;justify-content:center;gap:10px;margin:0 0 18px}
.redes a{width:46px;height:46px;display:grid;place-items:center;border-radius:50%;background:var(--card);border:1px solid var(--line);color:var(--primary);font-size:20px}
.pie{text-align:center;font-size:12px;color:var(--muted)}
/* Campaña (Cyber, Black Friday…): arriba de todo, con sus colores */
.cmp{position:relative;margin:-40px 0 14px;padding:20px 18px 18px;border-radius:20px;color:#fff;background:radial-gradient(circle at 100% 0%,rgba(255,255,255,.12) 0%,rgba(255,255,255,0) 45%),linear-gradient(150deg,var(--cd) 0%,var(--cd2) 100%);box-shadow:0 14px 34px rgba(7,27,45,.3),0 0 0 1px var(--ca)}
.cmp__eyebrow{display:inline-flex;align-items:center;gap:6px;margin:0 0 6px;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--ca)}
.cmp__name{margin:0;color:#fff;font-size:38px;font-weight:900;line-height:.95;letter-spacing:-.02em;text-transform:uppercase}
.cmp__name span{color:var(--ca)}
.cmp__head{margin:8px 0 2px;font-size:19px;font-weight:800;color:var(--ca)}
.cmp__sub{margin:0 0 14px;font-size:14px;color:rgba(255,255,255,.8)}
.cmp__cd{display:flex;gap:6px;margin:0 0 14px}
.cmp__cd span{flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 0 6px;border-radius:10px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.14)}
.cmp__cd b{font-size:24px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.cmp__cd small{margin-top:4px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ca)}
.cmp__btn{display:flex;align-items:center;justify-content:center;gap:8px;min-height:52px;border-radius:14px;background:var(--ca);color:var(--cd);font-size:16px;font-weight:900}
.cmp__list{display:grid;gap:8px;margin:14px 0 0}
.cmp__f{display:flex;gap:10px;align-items:center;padding:8px;border-radius:12px;background:rgba(255,255,255,.08)}
.cmp__f img{flex:0 0 52px;width:52px;height:52px;border-radius:8px;object-fit:cover;background:rgba(255,255,255,.1)}
.cmp__f span{flex:1;min-width:0;font-size:14px;font-weight:700;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cmp__f em{flex:0 0 auto;padding:4px 8px;border-radius:8px;background:var(--ca);color:var(--cd);font-style:normal;font-size:14px;font-weight:900}
.cmp+.search{margin-top:0}
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

	<?php if ( $cmp ) :
		$cmp_words = preg_split( '/\s+/', trim( $cmp['name'] ), 2 );
		?>
	<section class="cmp" data-end="<?php echo esc_attr( (string) $cmp['end'] ); ?>" style="--cd:<?php echo esc_attr( $cmp['dark'] ); ?>;--cd2:<?php echo esc_attr( $cmp['dark2'] ); ?>;--ca:<?php echo esc_attr( $cmp['acc'] ); ?>">
		<p class="cmp__eyebrow"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i><?php echo esc_html( $cmp['live'] ? 'Promo flash · Ya empezó' : 'Se viene' ); ?></p>
		<h2 class="cmp__name"><?php echo esc_html( $cmp_words[0] ); ?><?php echo ! empty( $cmp_words[1] ) ? ' <span>' . esc_html( $cmp_words[1] ) . '</span>' : ''; ?></h2>
		<p class="cmp__head"><?php echo esc_html( $cmp['head'] ); ?></p>
		<p class="cmp__sub"><?php echo esc_html( $cmp['sub'] ); ?></p>
		<div class="cmp__cd" data-to="<?php echo esc_attr( (string) $cmp['to'] ); ?>" role="timer" aria-label="<?php echo esc_attr( $cmp['live'] ? 'Termina en' : 'Empieza en' ); ?>">
			<span><b data-u="d">00</b><small>días</small></span><span><b data-u="h">00</b><small>hs</small></span><span><b data-u="m">00</b><small>min</small></span><span><b data-u="s">00</b><small>seg</small></span>
		</div>
		<a class="cmp__btn" href="<?php echo esc_url( $cmp['url'] ); ?>"><?php echo esc_html( $cmp['live'] ? 'Ver las ofertas' : 'Ver cuáles participan' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
		<div class="cmp__list">
			<?php foreach ( $cmp['rows'] as $r ) :
				$href = oec_formation_url( $r );
				?>
			<a class="cmp__f" href="<?php echo esc_url( $href ); ?>"<?php echo str_starts_with( $href, home_url() ) ? '' : ' target="_blank" rel="noopener"'; ?>>
				<?php if ( ! empty( $r['image'] ) ) : ?>
				<img src="<?php echo esc_url( oec_cdn_resize( $r['image'], 104, 75 ) ); ?>" alt="" width="52" height="52" loading="lazy" decoding="async">
				<?php endif; ?>
				<span><?php echo esc_html( $r['title'] ); ?></span>
				<?php if ( $cmp['live'] && $r['cmp_pct'] ) : ?><em>−<?php echo (int) $r['cmp_pct']; ?>%</em><?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

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
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( ucfirst( str_replace( [ 'twitter-x', 'whatsapp' ], [ 'X', 'WhatsApp' ], $icon ) ) ); ?>"><i class="bi bi-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<p class="pie"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a> · Online Education Center</p>

</main>

<?php if ( $cmp ) : ?>
<script>
/* Cuenta regresiva de la campaña; si ya terminó (copia vieja en caché), el bloque se va. */
(function(){var s=document.querySelector('.cmp');if(!s)return;var c=s.querySelector('.cmp__cd'),to=+c.dataset.to,end=+s.dataset.end;
function p(n){return(n<10?'0':'')+n}
function t(){var n=Date.now();if(n>end){s.remove();return}var x=Math.max(0,Math.floor((to-n)/1e3)),v={d:Math.floor(x/86400),h:Math.floor(x%86400/3600),m:Math.floor(x%3600/60),s:x%60};
c.querySelectorAll('[data-u]').forEach(function(b){b.textContent=p(v[b.dataset.u])})}
t();setInterval(t,1e3)})();
</script>
<?php endif; ?>
<?php
if ( function_exists( 'oec_output_fbclid_links' ) ) {
	oec_output_fbclid_links();
}
?>
</body>
</html>
