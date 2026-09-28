<?php
defined( 'ABSPATH' ) || exit;

define( 'OEC_THEME_VERSION', '1.0.55' );
define( 'OEC_THEME_DIR',     get_template_directory() );
define( 'OEC_THEME_URI',     get_template_directory_uri() );

/**
 * Versión para el ?ver= de un CSS/JS del tema: versión del tema + fecha
 * de modificación del archivo. Cada vez que el archivo cambia, cambia la
 * URL y el navegador (o Cloudflare) deja de usar la copia vieja, sin tener
 * que acordarse de subir OEC_THEME_VERSION en cada entrega.
 * $path: relativo a la carpeta del tema (ej. 'assets/js/main.js').
 */
function oec_asset_version( string $path ): string {
	$file = OEC_THEME_DIR . '/' . ltrim( $path, '/' );
	return OEC_THEME_VERSION . ( file_exists( $file ) ? '.' . filemtime( $file ) : '' );
}

/* ============================================================
   SETUP
   ============================================================ */
function oec_setup(): void {
	load_theme_textdomain( 'oec-theme', OEC_THEME_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', [ 'search-form', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support( 'custom-logo', [
		'height'      => 80,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	] );
	add_theme_support( 'customize-selective-refresh-widgets' );

	add_image_size( 'oec-hero',    1600, 900,  true );
	add_image_size( 'oec-card',     800, 500,  true );
	add_image_size( 'oec-thumb',    400, 250,  true );

	register_nav_menus( [
		'primary' => __( 'Menú principal', 'oec-theme' ),
		'footer'  => __( 'Menú pie de página', 'oec-theme' ),
	] );
}
add_action( 'after_setup_theme', 'oec_setup' );

/* ============================================================
   ENQUEUE ASSETS
   ============================================================ */
function oec_enqueue_assets(): void {
	wp_enqueue_style(
		'bootstrap-icons',
		'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
		[],
		null
	);
	wp_enqueue_style(
		'oec-style',
		get_stylesheet_uri(),
		[ 'bootstrap-icons' ],
		oec_asset_version( 'style.css' )
	);

	wp_enqueue_script(
		'oec-main',
		OEC_THEME_URI . '/assets/js/main.js',
		[],
		oec_asset_version( 'assets/js/main.js' ),
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

	// Asistente IA — TODO: re-condicionar a oec_anthropic_key tras pruebas de UI
	wp_enqueue_script(
		'oec-ai-chat',
		OEC_THEME_URI . '/assets/js/ai-chat.js',
		[],
		oec_asset_version( 'assets/js/ai-chat.js' ),
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);
	wp_localize_script( 'oec-ai-chat', 'oecAiChat', [
		'endpoint'       => esc_url( rest_url( 'oec/v1/chat' ) ),
		'streamEndpoint' => esc_url( rest_url( 'oec/v1/chat-stream' ) ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
	] );

	// Barra de compartir — solo en artículos/blogs individuales.
	if ( is_singular( 'post' ) ) {
		wp_enqueue_script(
			'oec-share',
			OEC_THEME_URI . '/assets/js/share.js',
			[],
			oec_asset_version( 'assets/js/share.js' ),
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}
}
add_action( 'wp_enqueue_scripts', 'oec_enqueue_assets' );

function oec_logo_dynamic_css(): void {
	$h = (int) ( oec_get_options()['logo_height'] ?? 40 );
	wp_add_inline_style( 'oec-style', ".site-logo img, .oec-overlay-site-logo { max-height: {$h}px; }" );
}
add_action( 'wp_enqueue_scripts', 'oec_logo_dynamic_css', 20 );

/* ============================================================
   CONTENT WIDTH
   ============================================================ */
function oec_content_width(): void {
	$GLOBALS['content_width'] = 1160;
}
add_action( 'after_setup_theme', 'oec_content_width', 0 );

/* ============================================================
   WIDGETS / SIDEBARS
   ============================================================ */
function oec_register_sidebars(): void {
	$defaults = [
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	];

	register_sidebar( array_merge( $defaults, [
		'name' => __( 'Sidebar blog', 'oec-theme' ),
		'id'   => 'sidebar-blog',
	] ) );

	register_sidebar( array_merge( $defaults, [
		'name' => __( 'Footer columna 2', 'oec-theme' ),
		'id'   => 'footer-col-2',
	] ) );

	register_sidebar( array_merge( $defaults, [
		'name' => __( 'Footer columna 3', 'oec-theme' ),
		'id'   => 'footer-col-3',
	] ) );

	register_sidebar( array_merge( $defaults, [
		'name' => __( 'Footer columna 4', 'oec-theme' ),
		'id'   => 'footer-col-4',
	] ) );
}
add_action( 'widgets_init', 'oec_register_sidebars' );

/* ============================================================
   CUSTOM EXCERPT
   ============================================================ */
function oec_excerpt_length(): int {
	return 25;
}
add_filter( 'excerpt_length', 'oec_excerpt_length' );

function oec_excerpt_more( string $more ): string {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'oec_excerpt_more' );

/* ============================================================
   BODY CLASSES
   ============================================================ */
function oec_body_classes( array $classes ): array {
	if ( is_singular() ) {
		$classes[] = 'singular';
	}
	if ( ! is_active_sidebar( 'sidebar-blog' ) ) {
		$classes[] = 'no-sidebar';
	}
	return $classes;
}
add_filter( 'body_class', 'oec_body_classes' );

/* ============================================================
   TEMPLATE HELPERS
   ============================================================ */
function oec_posted_on(): void {
	$time = sprintf(
		'<time class="entry-date published" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date( 'd M Y' ) )
	);
	echo $time; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function oec_posted_by(): void {
	printf(
		'<span class="author">%s</span>',
		esc_html( get_the_author() )
	);
}

function oec_post_thumbnail( string $size = 'oec-card' ): void {
	if ( post_password_required() || is_attachment() || ! has_post_thumbnail() ) {
		return;
	}
	echo '<div class="post-card-thumb">';
	the_post_thumbnail( $size, [ 'loading' => 'lazy' ] );
	echo '</div>';
}

/* ============================================================
   CUSTOM WALKER: NAV MENU
   ============================================================ */
class OEC_Walker_Nav extends Walker_Nav_Menu {

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $id = 0 ): void {
		$item   = $data_object;
		$indent = str_repeat( "\t", $depth );

		$classes   = empty( $item->classes ) ? [] : (array) $item->classes;
		$class_str = implode( ' ', array_filter( $classes ) );

		$atts = [
			'href'   => ! empty( $item->url ) ? $item->url : '#',
			'target' => ! empty( $item->target ) ? $item->target : '',
			'rel'    => ! empty( $item->xfn ) ? $item->xfn : '',
		];

		$atts_str = '';
		foreach ( $atts as $key => $val ) {
			if ( $val ) {
				$atts_str .= ' ' . $key . '="' . esc_attr( $val ) . '"';
			}
		}

		$title = apply_filters( 'nav_menu_item_title', $item->title, $item, $args, $depth );

		$item_output = $indent . "<li class=\"{$class_str}\">";
		$item_output .= "<a{$atts_str}>";
		$item_output .= esc_html( $title );
		$item_output .= "</a>\n";

		$output .= $item_output;
	}
}

/* ============================================================
   THEME CUSTOMIZER
   ============================================================ */
function oec_customize_register( WP_Customize_Manager $wp_customize ): void {

	// Hero section
	$wp_customize->add_section( 'oec_hero', [
		'title'    => __( 'Hero (Inicio)', 'oec-theme' ),
		'priority' => 30,
	] );

	$fields = [
		'oec_hero_eyebrow' => [
			'label'   => __( 'Eyebrow (texto pequeño)', 'oec-theme' ),
			'default' => __( 'Educación Online', 'oec-theme' ),
			'type'    => 'text',
		],
		'oec_hero_title' => [
			'label'   => __( 'Título principal', 'oec-theme' ),
			'default' => __( 'Aprendé sin límites, crecé sin fronteras', 'oec-theme' ),
			'type'    => 'text',
		],
		'oec_hero_subtitle' => [
			'label'   => __( 'Subtítulo', 'oec-theme' ),
			'default' => __( 'Descubrí nuestra oferta de cursos online, certificaciones y programas de formación profesional.', 'oec-theme' ),
			'type'    => 'textarea',
		],
		'oec_hero_btn_text' => [
			'label'   => __( 'Texto botón primario', 'oec-theme' ),
			'default' => __( 'Ver cursos', 'oec-theme' ),
			'type'    => 'text',
		],
		'oec_hero_btn_url' => [
			'label'   => __( 'URL botón primario', 'oec-theme' ),
			'default' => '#cursos',
			'type'    => 'url',
		],
	];

	foreach ( $fields as $id => $args ) {
		$wp_customize->add_setting( $id, [
			'default'           => $args['default'],
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'refresh',
		] );
		$wp_customize->add_control( $id, [
			'label'   => $args['label'],
			'section' => 'oec_hero',
			'type'    => $args['type'],
		] );
	}

	// Contact info
	$wp_customize->add_section( 'oec_contact_info', [
		'title'    => __( 'Datos de contacto', 'oec-theme' ),
		'priority' => 40,
	] );

	$contact_fields = [
		'oec_contact_email'    => [ 'label' => __( 'Email', 'oec-theme' ), 'default' => 'info@oec.edu' ],
		'oec_contact_phone'    => [ 'label' => __( 'Teléfono', 'oec-theme' ), 'default' => '' ],
		'oec_contact_address'  => [ 'label' => __( 'Dirección', 'oec-theme' ), 'default' => '' ],
		'oec_social_linkedin'  => [ 'label' => __( 'LinkedIn URL', 'oec-theme' ), 'default' => '' ],
		'oec_social_instagram' => [ 'label' => __( 'Instagram URL', 'oec-theme' ), 'default' => '' ],
		'oec_social_facebook'  => [ 'label' => __( 'Facebook URL', 'oec-theme' ), 'default' => '' ],
		'oec_social_youtube'   => [ 'label' => __( 'YouTube URL', 'oec-theme' ), 'default' => '' ],
	];

	foreach ( $contact_fields as $id => $args ) {
		$wp_customize->add_setting( $id, [
			'default'           => $args['default'],
			'sanitize_callback' => 'sanitize_text_field',
		] );
		$wp_customize->add_control( $id, [
			'label'   => $args['label'],
			'section' => 'oec_contact_info',
			'type'    => 'text',
		] );
	}
}
add_action( 'customize_register', 'oec_customize_register' );

/* ============================================================
   NAV — SUBMENÚ TEMÁTICAS
   Inyecta los footer_landings del admin como hijos del item
   "Formaciones" en el menú primario.
   ============================================================ */
add_filter( 'wp_nav_menu_objects', function ( array $items, $args ): array {
	if ( ( $args->theme_location ?? '' ) !== 'primary' ) {
		return $items;
	}

	$opts       = function_exists( 'oec_get_options' )     ? oec_get_options()                                        : [];
	$raw        = trim( $opts['footer_landings'] ?? '' );
	$landings   = function_exists( 'oec_parse_link_list' ) ? oec_parse_link_list( $raw ) : [];
	$campus_url = trim( $opts['campus_virtual_url'] ?? '' );

	// --- Submenú de temáticas dentro de "Formaciones" ---
	if ( ! empty( $landings ) ) {
		$parent_id = null;
		foreach ( $items as &$item ) {
			$by_title = mb_strtolower( $item->title ?? '' ) === 'formaciones';
			$by_url   = str_contains( $item->url ?? '', '/formaciones' );
			if ( $by_title || $by_url ) {
				$parent_id = (int) $item->ID;
				// Necesario para que el walker mega-menú muestre el trigger
				if ( ! in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
					$item->classes[] = 'menu-item-has-children';
				}
				break;
			}
		}
		unset( $item );

		if ( $parent_id ) {
			$fake_id   = 98000;
			$new_items = [];
			foreach ( $landings as $landing ) {
				$obj                        = new stdClass();
				$obj->ID                    = ++$fake_id;
				$obj->db_id                 = $fake_id;
				$obj->menu_item_parent      = $parent_id;
				$obj->object_id             = $fake_id;
				$obj->object                = 'custom';
				$obj->type                  = 'custom';
				$obj->type_label            = '';
				$obj->title                 = $landing['label'];
				$obj->url                   = $landing['href'];
				$obj->target                = $landing['external'] ? '_blank' : '';
				$obj->attr_title            = '';
				$obj->description           = '';
				$obj->icon                  = $landing['icon'] ?? '';
				$obj->classes               = [ 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' ];
				$obj->xfn                   = $landing['external'] ? 'noopener noreferrer' : '';
				$obj->menu_order            = $fake_id;
				$obj->post_parent           = 0;
				$obj->current               = false;
				$obj->current_item_ancestor = false;
				$obj->current_item_parent   = false;
				$new_items[]                = $obj;
			}

			// Inserta los nuevos items justo después del parent
			$merged = [];
			foreach ( $items as $item ) {
				$merged[] = $item;
				if ( (int) $item->ID === $parent_id ) {
					foreach ( $new_items as $ni ) {
						$merged[] = $ni;
					}
				}
			}
			$items = $merged;
		}
	}

	// --- Enlace "Campus Virtual": último ítem del menú principal, tono apagado ---
	if ( $campus_url ) {
		$campus                        = new stdClass();
		$campus->ID                    = 98999;
		$campus->db_id                 = 98999;
		$campus->menu_item_parent      = 0;
		$campus->object_id             = 98999;
		$campus->object                = 'custom';
		$campus->type                  = 'custom';
		$campus->type_label            = '';
		$campus->title                 = __( 'Campus', 'oec-theme' );
		$campus->url                   = $campus_url;
		$campus->target                = '_blank';
		$campus->attr_title            = '';
		$campus->description           = '';
		$campus->icon                  = '';
		$campus->classes               = [ 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'nav-item--muted' ];
		$campus->xfn                   = 'noopener noreferrer';
		$campus->menu_order            = 98999;
		$campus->post_parent           = 0;
		$campus->current               = false;
		$campus->current_item_ancestor = false;
		$campus->current_item_parent   = false;
		$items[]                       = $campus;
	}

	return $items;
}, 10, 2 );

/* ============================================================
   INCLUDE FILES
   ============================================================ */
require OEC_THEME_DIR . '/inc/performance.php';
require OEC_THEME_DIR . '/inc/root-redirect.php';
require OEC_THEME_DIR . '/inc/template-functions.php';
require OEC_THEME_DIR . '/inc/urls.php';
require OEC_THEME_DIR . '/inc/seo.php';
require OEC_THEME_DIR . '/inc/sitemap.php';
require OEC_THEME_DIR . '/inc/crawlers.php';
require OEC_THEME_DIR . '/inc/indexnow.php';
require OEC_THEME_DIR . '/inc/admin-settings.php';
require OEC_THEME_DIR . '/inc/credits.php';
require OEC_THEME_DIR . '/inc/ai-catalog.php';
require OEC_THEME_DIR . '/inc/organizations.php';
require OEC_THEME_DIR . '/inc/tematica-landing.php';
require OEC_THEME_DIR . '/inc/docentes.php';
require OEC_THEME_DIR . '/inc/opiniones.php';
require OEC_THEME_DIR . '/inc/clases.php';
require OEC_THEME_DIR . '/inc/blog-home.php';
require OEC_THEME_DIR . '/inc/sitios.php';
require OEC_THEME_DIR . '/inc/tiras.php';
require OEC_THEME_DIR . '/inc/formaciones.php';
require OEC_THEME_DIR . '/inc/ai-chat.php';
require OEC_THEME_DIR . '/inc/newsletter.php';
require OEC_THEME_DIR . '/inc/seed.php';
require OEC_THEME_DIR . '/inc/redirects.php';
require OEC_THEME_DIR . '/inc/mail.php';
require OEC_THEME_DIR . '/inc/theme-updater.php';

/* ============================================================
   THEME UPDATER
   Para repos privados pasá el Personal Access Token como 2do arg.
   ============================================================ */
function oec_register_updater(): void {
	new OEC_Theme_Updater(
		'mamoyano/oec-wp-theme',
		defined( 'OEC_GITHUB_TOKEN' ) ? OEC_GITHUB_TOKEN : null
	);
}
add_action( 'init', 'oec_register_updater' );

/* ============================================================
   SECURITY: Remove WP version
   ============================================================ */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
