<?php
$_fopts        = function_exists( 'oec_get_options' ) ? oec_get_options() : [];
$_footer_desc  = $_fopts['footer_desc'] ?? '';
$_landings_raw = trim( $_fopts['footer_landings'] ?? '' );
$_legal_raw    = trim( $_fopts['footer_legal'] ?? '' );
$_cta_url      = $_fopts['footer_cta_url'] ?? '';

$_landings = oec_parse_link_list( $_landings_raw );
$_legal    = oec_parse_link_list( $_legal_raw );

// Navegación general: fija, no depende de los ajustes del tema.
$_nav = [
	[ __( 'Formaciones', 'oec-theme' ),    home_url( '/formaciones' ) ],
	[ __( 'Docentes', 'oec-theme' ),       function_exists( 'oec_docentes_url' ) ? oec_docentes_url() : home_url( '/docentes/' ) ],
	[ __( 'Organizaciones', 'oec-theme' ), function_exists( 'oec_organizaciones_url' ) ? oec_organizaciones_url() : home_url( '/organizaciones/' ) ],
	[ __( 'Artículos', 'oec-theme' ),      function_exists( 'oec_articulos_url' ) ? oec_articulos_url( [] ) : home_url( '/articulos/' ) ],
];

$_col_count = 2 + ( ! empty( $_landings ) ? 1 : 0 ) + ( ! empty( $_legal ) ? 1 : 0 );

$_socials = [
	'social_linkedin'  => [ 'icon' => 'bi-linkedin',  'label' => 'LinkedIn'    ],
	'social_instagram' => [ 'icon' => 'bi-instagram', 'label' => 'Instagram'   ],
	'social_facebook'  => [ 'icon' => 'bi-facebook',  'label' => 'Facebook'    ],
	'social_youtube'   => [ 'icon' => 'bi-youtube',   'label' => 'YouTube'     ],
	'social_x'         => [ 'icon' => 'bi-twitter-x', 'label' => 'X (Twitter)' ],
];
?>

	<footer class="site-footer" role="contentinfo">
		<div class="container">

			<?php if ( $_cta_url ) : ?>
			<!-- Invitación a organizaciones educativas -->
			<div class="footer-cta">
				<div class="footer-cta__body">
					<strong><?php esc_html_e( 'Organizaciones educativas', 'oec-theme' ); ?></strong>
					<p><?php esc_html_e( 'Publicá tus formaciones en Online Education Center y llegá a miles de profesionales en hispanoamérica. Sin costos fijos.', 'oec-theme' ); ?></p>
				</div>
				<a href="<?php echo esc_url( $_cta_url ); ?>"
				   class="footer-cta__btn"
				   target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Más información', 'oec-theme' ); ?>
					<i class="bi bi-arrow-right" aria-hidden="true"></i>
				</a>
			</div>
			<?php endif; ?>

			<div class="footer-grid footer-grid--cols-<?php echo esc_attr( $_col_count ); ?>">

				<!-- Columna marca -->
				<div class="footer-brand">
					<a class="footer-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>">
						<?php
						$_logo_url = $_fopts['logo_url'] ?? '';
						$_logo_h   = (int) ( $_fopts['logo_height'] ?? 40 );
						if ( $_logo_url ) : ?>
							<?php $_logo_box = oec_logo_box( $_logo_url, $_logo_h ); ?>
							<img src="<?php echo esc_url( $_logo_url ); ?>"
							     alt="<?php bloginfo( 'name' ); ?>"
							     <?php if ( $_logo_box ) : ?>width="<?php echo (int) $_logo_box[0]; ?>" height="<?php echo (int) $_logo_box[1]; ?>"
							     style="width:<?php echo (int) $_logo_box[0]; ?>px;height:<?php echo (int) $_logo_box[1]; ?>px;"
							     <?php else : ?>style="max-height:<?php echo esc_attr( $_logo_h ); ?>px;height:auto;width:auto;"
							     <?php endif; ?>loading="lazy">
						<?php elseif ( has_custom_logo() ) :
							the_custom_logo();
						else : ?>
							<span class="site-logo-text">OEC<span>.</span></span>
						<?php endif; ?>
					</a>

					<?php if ( $_footer_desc ) : ?>
					<p class="footer-brand__desc"><?php echo esc_html( $_footer_desc ); ?></p>
					<?php endif; ?>

					<!-- Redes sociales -->
					<div class="footer-social">
						<?php foreach ( $_socials as $skey => $sdata ) :
							$url = $_fopts[ $skey ] ?? '';
							if ( ! $url ) continue; ?>
						<a href="<?php echo esc_url( $url ); ?>"
						   target="_blank" rel="noopener noreferrer"
						   aria-label="<?php echo esc_attr( $sdata['label'] ); ?>">
							<i class="bi <?php echo esc_attr( $sdata['icon'] ); ?>" aria-hidden="true"></i>
						</a>
						<?php endforeach; ?>
					</div>

					<a class="footer-community-link" href="https://onlineeducation.center/es" target="_blank" rel="noopener noreferrer"
					   aria-label="<?php esc_attr_e( 'Comunidad de Online Education Center — abre en nueva pestaña', 'oec-theme' ); ?>">
						<?php esc_html_e( 'Comunidad de Online Education Center', 'oec-theme' ); ?>
						<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
					</a>
				</div>

				<!-- Columna Explorar -->
				<nav class="footer-col" aria-label="<?php esc_attr_e( 'Navegación del pie', 'oec-theme' ); ?>">
					<h4><?php esc_html_e( 'Explorar', 'oec-theme' ); ?></h4>
					<ul>
						<?php foreach ( $_nav as [ $label, $url ] ) : ?>
						<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<!-- Columna Temáticas -->
				<?php if ( ! empty( $_landings ) ) : ?>
				<div class="footer-col">
					<h4><?php esc_html_e( 'Temáticas', 'oec-theme' ); ?></h4>
					<ul>
						<?php foreach ( $_landings as $link ) :
							$ext = $link['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
						<li><a href="<?php echo esc_url( $link['href'] ); ?>"<?php echo $ext; // phpcs:ignore ?>><?php echo esc_html( $link['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

				<!-- Columna Información -->
				<?php if ( ! empty( $_legal ) ) : ?>
				<div class="footer-col">
					<h4><?php esc_html_e( 'Información', 'oec-theme' ); ?></h4>
					<ul>
						<?php foreach ( $_legal as $link ) :
							$ext = $link['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
						<li><a href="<?php echo esc_url( $link['href'] ); ?>"<?php echo $ext; // phpcs:ignore ?>><?php echo esc_html( $link['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

			</div><!-- .footer-grid -->

		</div><!-- .container -->
	</footer>

	<?php wp_footer(); ?>
</body>
</html>
