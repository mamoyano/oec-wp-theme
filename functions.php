<?php
defined( 'ABSPATH' ) || exit;

define( 'OEC_THEME_VERSION', '1.0.0' );
define( 'OEC_THEME_DIR',     get_template_directory() );
define( 'OEC_THEME_URI',     get_template_directory_uri() );

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
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );

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
		'oec-style',
		get_stylesheet_uri(),
		[],
		OEC_THEME_VERSION
	);

	wp_enqueue_script(
		'oec-main',
		OEC_THEME_URI . '/assets/js/main.js',
		[],
		OEC_THEME_VERSION,
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

}
add_action( 'wp_enqueue_scripts', 'oec_enqueue_assets' );

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
   INCLUDE FILES
   ============================================================ */
require OEC_THEME_DIR . '/inc/performance.php';
require OEC_THEME_DIR . '/inc/template-functions.php';
require OEC_THEME_DIR . '/inc/admin-settings.php';
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
