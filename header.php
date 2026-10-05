<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
/* iPhone/iPad: Safari hace zoom al enfocar un campo y la página queda
 * agrandada y cortada. maximum-scale=1 lo evita; en iOS el usuario igual
 * puede ampliar con dos dedos. Solo en iOS: en Android bloquearía ese
 * gesto. Va por JS porque Cloudflare cachea el mismo HTML para todos. */
?>
<script>(function(){var n=navigator;if(/iPad|iPhone|iPod/.test(n.userAgent)||(n.platform==='MacIntel'&&n.maxTouchPoints>1)){var m=document.querySelector('meta[name="viewport"]');if(m)m.setAttribute('content','width=device-width, initial-scale=1, maximum-scale=1');}})();</script>
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

			<!-- ── ASISTENTE IA (desktop) ───────────────────── -->
			<div class="header-search" role="search" id="oec-ai-zone">
				<div class="header-search__box" id="oec-ai-box">
					<svg class="header-search__icon" width="16" height="16" viewBox="0 0 24 24"
					     fill="none" stroke="currentColor" stroke-width="2"
					     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="11" cy="11" r="8"/>
						<path d="m21 21-4.35-4.35"/>
					</svg>
					<input type="text"
					       id="oec-ai-header-input"
					       class="header-search__input"
					       placeholder="<?php esc_attr_e( '¿Qué quieres aprender?', 'oec-theme' ); ?>"
					       autocomplete="off"
					       spellcheck="false"
					       aria-label="<?php esc_attr_e( 'Pregunta al asistente OEC', 'oec-theme' ); ?>">
					<button type="button" id="oec-ai-header-send"
					        class="header-search__send-btn"
					        aria-label="<?php esc_attr_e( 'Preguntar', 'oec-theme' ); ?>"
					        disabled>
						<i class="bi bi-arrow-down" aria-hidden="true"></i>
					</button>
				</div>
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

	<!-- Asistente IA mobile (se muestra al tocar el ícono) -->
	<div class="header-search-mobile" id="header-search-mobile" hidden>
		<div class="container">
			<div class="header-search__box">
				<svg class="header-search__icon" width="16" height="16" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor" stroke-width="2"
				     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
				</svg>
				<input type="text" id="oec-ai-mobile-input" class="header-search__input"
				       placeholder="<?php esc_attr_e( '¿Qué quieres aprender?', 'oec-theme' ); ?>"
				       autocomplete="off" autofocus>
				<button type="button" id="oec-ai-mobile-send"
				        class="header-search__send-btn"
				        aria-label="<?php esc_attr_e( 'Preguntar', 'oec-theme' ); ?>"
				        disabled>
					<i class="bi bi-arrow-down" aria-hidden="true"></i>
				</button>
			</div>
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

	/** URL del ítem de primer nivel que se está armando (para start_lvl). */
	private string $oec_parent_url = '';

	/**
	 * Accesos rápidos arriba del mega-menú de "Formaciones" (el ítem cuya URL
	 * es /formaciones): se agregan solos, sin cargarlos en Apariencia > Menús,
	 * así el tema funciona igual en cada plataforma. Van más chicos que las
	 * temáticas para que se note la jerarquía. Filtrable con
	 * 'oec_mega_quick_links' ([ [label, url, icono bi-*], … ], $parent_url).
	 */
	private function quick_links_html( string $parent_url ): string {
		$path   = untrailingslashit( (string) wp_parse_url( $parent_url, PHP_URL_PATH ) );
		$target = untrailingslashit( (string) wp_parse_url( home_url( '/formaciones' ), PHP_URL_PATH ) );
		$links  = ( '' !== $path && $path === $target ) ? [
			[ __( 'Todas las formaciones', 'oec-theme' ), $parent_url, 'bi-grid-3x3-gap' ],
			[ __( 'Todos los docentes', 'oec-theme' ), function_exists( 'oec_docentes_url' ) ? oec_docentes_url() : home_url( '/docentes/' ), 'bi-person-video3' ],
		] : [];
		$links = apply_filters( 'oec_mega_quick_links', $links, $parent_url );
		if ( ! $links ) {
			return '';
		}

		$html = '<div class="mega-menu__quick">';
		foreach ( $links as [ $label, $url, $icon ] ) {
			$html .= '<a class="mega-menu__quick-link" href="' . esc_url( $url ) . '">'
			       . '<i class="bi ' . esc_attr( $icon ) . '" aria-hidden="true"></i>' . esc_html( $label ) . '</a>';
		}
		return $html . '</div><span class="mega-menu__label">' . esc_html__( 'Por temática', 'oec-theme' ) . '</span>';
	}

	public function start_lvl( &$output, $depth = 0, $args = null ): void {
		if ( 0 === $depth ) {
			$output .= '<div class="mega-menu" role="region">'
			         . $this->quick_links_html( $this->oec_parent_url )
			         . '<div class="mega-menu__grid">';
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
			$this->oec_parent_url = (string) $item->url;
			$is_muted = in_array( 'nav-item--muted', (array) $item->classes, true );

			$classes = 'nav-item';
			if ( $has_children ) $classes .= ' nav-item--has-sub';
			if ( $is_current )   $classes .= ' nav-item--current';
			if ( $is_muted )     $classes .= ' nav-item--muted';

			$output .= '<li class="' . esc_attr( $classes ) . '">';

			if ( $has_children ) {
				// Único <a>: navega en desktop (hover abre el mega-menu),
				// en mobile el primer tap abre el submenu y el segundo navega.
				$href = ( ! empty( $item->url ) && '#' !== $item->url ) ? esc_url( $item->url ) : '#';
				$output .= '<a href="' . $href . '" class="nav-trigger" '
				         . 'aria-expanded="false" aria-haspopup="true">'
				         . esc_html( $item->title )
				         . '<svg class="nav-chevron" width="11" height="11" viewBox="0 0 24 24" '
				         . 'fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
				         . '<polyline points="6 9 12 15 18 9"/></svg>'
				         . '</a>';
			} else {
				$is_external = '_blank' === ( $item->target ?? '' );
				$output .= '<a href="' . esc_url( $item->url ) . '"'
				         . ( $is_external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>'
				         . esc_html( $item->title )
				         . ( $is_external ? ' <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>' : '' )
				         . '</a>';
			}

		} else {
			// Items dentro del mega-menú
			$is_external = '_blank' === ( $item->target ?? '' );

			$output .= '<a href="' . esc_url( $item->url ) . '" class="mega-menu__item"'
			         . ( $is_external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>'
			         . esc_html( $item->title )
			         . ( $is_external ? ' <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>' : '' )
			         . '</a>';
		}
	}

	public function end_el( &$output, $data_object, $depth = 0, $args = null ): void {
		if ( 0 === $depth ) {
			$output .= '</li>';
		}
	}
}

/* ================================================================
   CREDITS BADGE: lee localStorage y actualiza el nav en tiempo real.
   Se ejecuta después del DOMContentLoaded para no bloquear render.
   ================================================================ */
?>
<script>
(function () {
	function oecApplyCredits( balance ) {
		document.querySelectorAll( '.nav-list a, .mega-menu__item' ).forEach( function ( a ) {
			const text = a.textContent.trim().toLowerCase();
			if ( text === 'créditos' || text === 'creditos' ) {
				a.classList.add( 'nav-credits-link' );
				a.innerHTML =
					'<span class="nav-credits-badge">' +
					parseInt( balance, 10 ).toLocaleString( 'es-AR' ) +
					'</span> Créditos';
			}
		} );
	}
	function oecClearCredits() {
		document.querySelectorAll( '.nav-credits-link' ).forEach( function ( a ) {
			a.classList.remove( 'nav-credits-link' );
			a.textContent = 'Créditos';
		} );
	}
	function oecWhenReady( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}
	try {
		// Mismo criterio que el bloque de créditos ([oec-credits-widget]): el
		// usuario se identifica por el EMAIL (localStorage "userEmail"). Sin
		// email no hay saldo que mostrar: si quedó uno guardado (p. ej. el
		// "Cambiar email" de la ficha del plugin borra el email y el saldo de
		// sesión pero no el respaldo del tema), se limpia. Así el header y el
		// bloque nunca se contradicen.
		// Saldo: sessionStorage del plugin (igual que oec-formacion.js) o, si no,
		// el respaldo en localStorage del tema.
		var email = localStorage.getItem( 'userEmail' );
		var b     = sessionStorage.getItem( 'userCredits' ) || localStorage.getItem( 'oec_credits_balance' );
		if ( ! email ) {
			sessionStorage.removeItem( 'userCredits' );
			localStorage.removeItem( 'oec_credits_balance' );
		} else if ( b !== null && b !== '' ) {
			oecWhenReady( function () { oecApplyCredits( b ); } );
		}
	} catch ( _e ) {}
	// Actualización en tiempo real (bloque de créditos, newsletter, etc.)
	window.addEventListener( 'oec:credits-updated', function ( e ) {
		oecApplyCredits( e.detail.balance );
	} );
	window.addEventListener( 'oec:credits-reset', oecClearCredits );
}());
</script>
<?php

function oec_header_fallback_nav(): void {
	echo '<ul class="nav-list">';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/formaciones' ) ) . '">'
	   . esc_html__( 'Formaciones', 'oec-theme' ) . '</a></li>';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/articulos' ) ) . '">'
	   . esc_html__( 'Artículos y Blogs', 'oec-theme' ) . '</a></li>';
	echo '<li class="nav-item"><a href="' . esc_url( home_url( '/creditos-por-descuentos' ) ) . '">'
	   . esc_html__( 'Créditos', 'oec-theme' ) . '</a></li>';
	echo '</ul>';
}
