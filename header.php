<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="sr-only" href="#main-content"><?php esc_html_e( 'Saltar al contenido', 'oec-theme' ); ?></a>

<header class="site-header" role="banner" id="site-header">
	<div class="container">
		<div class="header-inner">

			<!-- ── LOGO ──────────────────────────────────── -->
			<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"
			   aria-label="<?php bloginfo( 'name' ); ?>">
				<?php
				$oec_logo = function_exists( 'oec_get_options' ) ? oec_get_options()['logo_url'] : '';
				if ( $oec_logo ) : ?>
					<img src="<?php echo esc_url( $oec_logo ); ?>"
					     alt="<?php bloginfo( 'name' ); ?>"
					     height="40" loading="eager">
				<?php elseif ( has_custom_logo() ) :
					the_custom_logo();
				else : ?>
					<span class="site-logo-text">OEC<span>.</span></span>
				<?php endif; ?>
			</a>

			<!-- ── BUSCADOR (desktop) ─────────────────────── -->
			<div class="header-search" role="search">
				<form class="header-search__form" id="oec-search-form"
				      action="<?php echo esc_url( home_url( '/' ) ); ?>"
				      method="get">
					<div class="header-search__box">
						<svg class="header-search__icon" width="16" height="16" viewBox="0 0 24 24"
						     fill="none" stroke="currentColor" stroke-width="2"
						     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<circle cx="11" cy="11" r="8"/>
							<path d="m21 21-4.35-4.35"/>
						</svg>

						<input type="search"
						       id="oec-search-input"
						       name="s"
						       class="header-search__input"
						       placeholder="<?php esc_attr_e( 'Buscar formaciones...', 'oec-theme' ); ?>"
						       autocomplete="off"
						       spellcheck="false"
						       aria-label="<?php esc_attr_e( 'Buscar formaciones', 'oec-theme' ); ?>"
						       aria-expanded="false"
						       aria-controls="oec-search-dropdown"
						       value="<?php echo esc_attr( get_search_query() ); ?>">

						<div class="header-search__dropdown"
						     id="oec-search-dropdown"
						     role="listbox"
						     aria-label="<?php esc_attr_e( 'Sugerencias de búsqueda', 'oec-theme' ); ?>">
						</div>
					</div>
				</form>
			</div>

			<!-- ── NAVEGACIÓN ─────────────────────────────── -->
			<nav class="header-nav" id="main-nav"
			     aria-label="<?php esc_attr_e( 'Navegación principal', 'oec-theme' ); ?>">
				<?php
				wp_nav_menu( [
					'theme_location' => 'primary',
					'container'      => false,
					'items_wrap'     => '<ul class="nav-list" id="nav-list-%1$s">%3$s</ul>',
					'walker'         => new OEC_Walker_Mega_Menu(),
					'fallback_cb'    => 'oec_header_fallback_nav',
				] );
				?>
			</nav>

			<!-- ── ACCIONES MOBILE ────────────────────────── -->
			<div class="header-mobile-btns">
				<button class="header-icon-btn" id="search-toggle"
				        aria-label="<?php esc_attr_e( 'Abrir buscador', 'oec-theme' ); ?>"
				        aria-expanded="false" aria-controls="header-search-mobile">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
					     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
					</svg>
				</button>

				<button class="menu-toggle" id="menu-toggle"
				        aria-controls="main-nav" aria-expanded="false"
				        aria-label="<?php esc_attr_e( 'Abrir menú', 'oec-theme' ); ?>">
					<span></span><span></span><span></span>
				</button>
			</div>

		</div><!-- .header-inner -->
	</div><!-- .container -->

	<!-- Buscador mobile (se muestra al tocar el ícono) -->
	<div class="header-search-mobile" id="header-search-mobile" hidden>
		<div class="container">
			<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
				<div class="header-search__box">
					<svg class="header-search__icon" width="16" height="16" viewBox="0 0 24 24"
					     fill="none" stroke="currentColor" stroke-width="2"
					     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
					</svg>
					<input type="search" name="s" class="header-search__input"
					       placeholder="<?php esc_attr_e( 'Buscar formaciones...', 'oec-theme' ); ?>"
					       autocomplete="off" autofocus>
				</div>
			</form>
		</div>
	</div>
</header>

<?php
/* ================================================================
   WALKER: MEGA MENÚ
   Cualquier ítem de primer nivel con hijos genera un mega-menú.
   Los hijos se renderizan como links en una grilla.
   ================================================================ */
class OEC_Walker_Mega_Menu extends Walker_Nav_Menu {

	public function start_lvl( &$output, $depth = 0, $args = null ): void {
		if ( 0 === $depth ) {
			$output .= '<div class="mega-menu" role="region"><div class="mega-menu__grid">';
		}
	}

	public function end_lvl( &$output, $depth = 0, $args = null ): void {
		if ( 0 === $depth ) {
			$output .= '</div></div>';
		}
	}

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $id = 0 ): void {
		$item         = $data_object;
		$has_children = in_array( 'menu-item-has-children', (array) $item->classes, true );
		$is_current   = in_array( 'current-menu-item', (array) $item->classes, true )
		             || in_array( 'current-menu-ancestor', (array) $item->classes, true );

		if ( 0 === $depth ) {
			$classes = 'nav-item';
			if ( $has_children ) $classes .= ' nav-item--has-sub';
			if ( $is_current )   $classes .= ' nav-item--current';

			$output .= '<li class="' . esc_attr( $classes ) . '">';

			if ( $has_children ) {
				$output .= '<button type="button" class="nav-trigger" '
				         . 'aria-expanded="false" aria-haspopup="true">'
				         . esc_html( $item->title )
				         . '<svg class="nav-chevron" width="11" height="11" viewBox="0 0 24 24" '
				         . 'fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
				         . '<polyline points="6 9 12 15 18 9"/></svg>'
				         . '</button>';
			} else {
				$output .= '<a href="' . esc_url( $item->url ) . '">'
				         . esc_html( $item->title ) . '</a>';
			}

		} else {
			// Items dentro del mega-menú
			$output .= '<a href="' . esc_url( $item->url ) . '" class="mega-menu__item">'
			         . esc_html( $item->title ) . '</a>';
		}
	}

	public function end_el( &$output, $data_object, $depth = 0, $args = null ): void {
		if ( 0 === $depth ) {
			$output .= '</li>';
		}
	}
}

function oec_header_fallback_nav(): void {
	echo '<ul class="nav-list">';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/formaciones' ) ) . '">'
	   . esc_html__( 'Formaciones', 'oec-theme' ) . '</a></li>';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/articulos' ) ) . '">'
	   . esc_html__( 'Artículos', 'oec-theme' ) . '</a></li>';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/creditos' ) ) . '">'
	   . esc_html__( 'Créditos', 'oec-theme' ) . '</a></li>';
	echo '</ul>';
}
