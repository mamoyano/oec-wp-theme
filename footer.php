	<footer class="site-footer" role="contentinfo">
		<div class="container">
			<div class="footer-grid">

				<!-- Columna marca -->
				<div class="footer-brand">
					<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php
						$oec_logo_url = function_exists( 'oec_get_options' ) ? oec_get_options()['logo_url'] : '';
						if ( $oec_logo_url ) :
						?>
							<img src="<?php echo esc_url( $oec_logo_url ); ?>"
							     alt="<?php bloginfo( 'name' ); ?>"
							     class="oec-logo-img"
							     loading="lazy"
							     height="36">
						<?php elseif ( has_custom_logo() ) : ?>
							<?php the_custom_logo(); ?>
						<?php else : ?>
							<span class="site-logo-text text-white">OEC<span>.</span></span>
						<?php endif; ?>
					</a>
					<p><?php bloginfo( 'description' ); ?></p>

					<!-- Redes sociales -->
					<div class="footer-social">
						<?php
						$socials = [
							'oec_social_linkedin'  => [ 'label' => 'LinkedIn',  'icon' => 'in' ],
							'oec_social_instagram' => [ 'label' => 'Instagram', 'icon' => '&#x2665;' ],
							'oec_social_facebook'  => [ 'label' => 'Facebook',  'icon' => 'f' ],
							'oec_social_youtube'   => [ 'label' => 'YouTube',   'icon' => '&#9654;' ],
						];
						foreach ( $socials as $key => $data ) :
							$url = get_theme_mod( $key, '' );
							if ( $url ) :
						?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $data['label'] ); ?>">
							<?php echo $data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
						<?php endif; endforeach; ?>
					</div>
				</div>

				<!-- Columnas dinámicas de footer -->
				<?php for ( $i = 2; $i <= 4; $i++ ) : ?>
					<div class="footer-col">
						<?php if ( is_active_sidebar( "footer-col-{$i}" ) ) : ?>
							<?php dynamic_sidebar( "footer-col-{$i}" ); ?>
						<?php else : ?>
							<?php oec_footer_default_col( $i ); ?>
						<?php endif; ?>
					</div>
				<?php endfor; ?>

			</div>

			<!-- Pie -->
			<div class="footer-bottom">
				<span>
					&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>.
					<?php esc_html_e( 'Todos los derechos reservados.', 'oec-theme' ); ?>
				</span>
				<span>
					<?php
					wp_nav_menu( [
						'theme_location' => 'footer',
						'container'      => false,
						'items_wrap'     => '%3$s',
						'depth'          => 1,
						'fallback_cb'    => false,
						'link_before'    => '',
						'link_after'     => '',
					] );
					?>
				</span>
			</div>

		</div>
	</footer>

	<?php wp_footer(); ?>
</body>
</html>

<?php
function oec_footer_default_col( int $col ): void {
	$cols = [
		2 => [
			'title' => __( 'Navegación', 'oec-theme' ),
			'links' => [
				[ 'label' => __( 'Inicio', 'oec-theme' ),    'href' => home_url( '/' ) ],
				[ 'label' => __( 'Nosotros', 'oec-theme' ),  'href' => home_url( '/#nosotros' ) ],
				[ 'label' => __( 'Servicios', 'oec-theme' ), 'href' => home_url( '/#servicios' ) ],
				[ 'label' => __( 'Blog', 'oec-theme' ),      'href' => home_url( '/blog' ) ],
				[ 'label' => __( 'Contacto', 'oec-theme' ),  'href' => home_url( '/#contacto' ) ],
			],
		],
		3 => [
			'title' => __( 'Cursos', 'oec-theme' ),
			'links' => [
				[ 'label' => __( 'Catálogo completo', 'oec-theme' ), 'href' => '#' ],
				[ 'label' => __( 'Certificaciones', 'oec-theme' ),   'href' => '#' ],
				[ 'label' => __( 'Empresas', 'oec-theme' ),          'href' => '#' ],
				[ 'label' => __( 'Becas', 'oec-theme' ),             'href' => '#' ],
			],
		],
		4 => [
			'title' => __( 'Contacto', 'oec-theme' ),
			'links' => array_filter( [
				get_theme_mod( 'oec_contact_email' )
					? [ 'label' => esc_html( get_theme_mod( 'oec_contact_email' ) ), 'href' => 'mailto:' . antispambot( get_theme_mod( 'oec_contact_email' ) ) ]
					: null,
				get_theme_mod( 'oec_contact_phone' )
					? [ 'label' => esc_html( get_theme_mod( 'oec_contact_phone' ) ), 'href' => 'tel:' . preg_replace( '/\s+/', '', get_theme_mod( 'oec_contact_phone' ) ) ]
					: null,
				get_theme_mod( 'oec_contact_address' )
					? [ 'label' => esc_html( get_theme_mod( 'oec_contact_address' ) ), 'href' => '#' ]
					: null,
			] ),
		],
	];

	if ( ! isset( $cols[ $col ] ) ) return;

	$data = $cols[ $col ];
	echo '<h4>' . esc_html( $data['title'] ) . '</h4>';
	echo '<ul>';
	foreach ( $data['links'] as $link ) {
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( $link['href'] ),
			esc_html( $link['label'] )
		);
	}
	echo '</ul>';
}
