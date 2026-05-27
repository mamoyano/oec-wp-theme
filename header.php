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

<header class="site-header" role="banner">
	<div class="container">
		<div class="header-inner">

			<!-- Logo -->
			<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<?php
				$oec_logo_url = function_exists( 'oec_get_options' ) ? oec_get_options()['logo_url'] : '';
				if ( $oec_logo_url ) :
				?>
					<img src="<?php echo esc_url( $oec_logo_url ); ?>"
					     alt="<?php bloginfo( 'name' ); ?>"
					     class="oec-logo-img"
					     loading="eager"
					     height="40">
				<?php elseif ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span class="site-logo-text">OEC<span>.</span></span>
				<?php endif; ?>
			</a>

			<!-- Navigation -->
			<nav class="main-nav" id="main-nav" aria-label="<?php esc_attr_e( 'Navegación principal', 'oec-theme' ); ?>">
				<?php
				wp_nav_menu( [
					'theme_location' => 'primary',
					'menu_id'        => 'primary-menu',
					'container'      => false,
					'items_wrap'     => '%3$s',
					'walker'         => new OEC_Walker_Nav(),
					'fallback_cb'    => 'oec_nav_fallback',
				] );
				?>
				<a href="<?php echo esc_url( home_url( '/#contacto' ) ); ?>" class="btn btn-primary btn-sm nav-cta">
					<?php esc_html_e( 'Contactanos', 'oec-theme' ); ?>
				</a>
			</nav>

			<!-- Mobile toggle -->
			<button class="menu-toggle" id="menu-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Abrir menú', 'oec-theme' ); ?>">
				<span></span>
				<span></span>
				<span></span>
			</button>

		</div>
	</div>
</header>

<?php
function oec_nav_fallback(): void {
	echo '<ul>';
	echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Inicio', 'oec-theme' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/#servicios' ) ) . '">' . esc_html__( 'Servicios', 'oec-theme' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/#nosotros' ) ) . '">' . esc_html__( 'Nosotros', 'oec-theme' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/blog' ) ) . '">' . esc_html__( 'Blog', 'oec-theme' ) . '</a></li>';
	echo '</ul>';
}
