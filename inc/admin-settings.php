<?php
defined( 'ABSPATH' ) || exit;

define( 'OEC_OPTION', 'oec_theme_options' );

/* ============================================================
   DEFAULTS & HELPERS
   ============================================================ */
function oec_get_defaults(): array {
	return [
		'logo_id'          => 0,
		'logo_url'         => '',
		'color_primary'    => '#194872',
		'color_dark'       => '#071b2d',
		'color_accent'     => '#e8952a',
		'color_light'      => '#f4f8fc',
		'color_text'       => '#1e2d3d',
		'gtm_id'           => '',
		'meta_pixel_id'    => '',
		'ms_clarity_id'    => '',
	];
}

function oec_get_options(): array {
	return wp_parse_args( (array) get_option( OEC_OPTION, [] ), oec_get_defaults() );
}

function oec_darken_hex( string $hex, int $percent = 12 ): string {
	$hex    = ltrim( $hex, '#' );
	$factor = 1 - $percent / 100;
	$r      = max( 0, (int) round( hexdec( substr( $hex, 0, 2 ) ) * $factor ) );
	$g      = max( 0, (int) round( hexdec( substr( $hex, 2, 2 ) ) * $factor ) );
	$b      = max( 0, (int) round( hexdec( substr( $hex, 4, 2 ) ) * $factor ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/* ============================================================
   REGISTER SETTINGS
   ============================================================ */
function oec_settings_init(): void {
	register_setting( 'oec_settings_group', OEC_OPTION, [
		'sanitize_callback' => 'oec_sanitize_options',
	] );
}
add_action( 'admin_init', 'oec_settings_init' );

function oec_sanitize_options( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return oec_get_defaults();
	}

	$defaults = oec_get_defaults();
	$clean    = [];

	// Logo
	$clean['logo_id']  = absint( $raw['logo_id']  ?? 0 );
	$clean['logo_url'] = esc_url_raw( $raw['logo_url'] ?? '' );

	// Colors
	foreach ( [ 'color_primary', 'color_dark', 'color_accent', 'color_light', 'color_text' ] as $key ) {
		$val          = sanitize_hex_color( $raw[ $key ] ?? '' );
		$clean[ $key ] = $val ?: $defaults[ $key ];
	}

	// Trackers
	$clean['gtm_id']        = strtoupper( sanitize_text_field( $raw['gtm_id'] ?? '' ) );
	$clean['meta_pixel_id'] = preg_replace( '/\D/', '', $raw['meta_pixel_id'] ?? '' );
	$clean['ms_clarity_id'] = preg_replace( '/[^a-z0-9]/i', '', $raw['ms_clarity_id'] ?? '' );

	return $clean;
}

/* ============================================================
   ADMIN MENU
   ============================================================ */
function oec_add_admin_menu(): void {
	add_theme_page(
		__( 'OEC — Configuración del tema', 'oec-theme' ),
		__( 'Configuración OEC', 'oec-theme' ),
		'manage_options',
		'oec-settings',
		'oec_render_settings_page'
	);
}
add_action( 'admin_menu', 'oec_add_admin_menu' );

/* ============================================================
   ENQUEUE ASSETS (solo en la página del tema)
   ============================================================ */
function oec_admin_enqueue( string $hook ): void {
	if ( 'appearance_page_oec-settings' !== $hook ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_enqueue_script(
		'oec-admin-settings',
		OEC_THEME_URI . '/assets/js/admin-settings.js',
		[ 'jquery', 'wp-color-picker', 'wp-util' ],
		OEC_THEME_VERSION,
		true
	);
	wp_localize_script( 'oec-admin-settings', 'oecAdmin', [
		'mediaTitle'  => __( 'Seleccionar logo', 'oec-theme' ),
		'mediaButton' => __( 'Usar como logo', 'oec-theme' ),
		'noLogo'      => __( 'Sin logo cargado', 'oec-theme' ),
		'uploadLabel' => __( 'Subir logo', 'oec-theme' ),
		'changeLabel' => __( 'Cambiar logo', 'oec-theme' ),
	] );
}
add_action( 'admin_enqueue_scripts', 'oec_admin_enqueue' );

/* ============================================================
   SETTINGS PAGE
   ============================================================ */
function oec_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$opts = oec_get_options();
	$tab  = sanitize_key( $_GET['tab'] ?? 'logo' );
	$tabs = [
		'logo'    => [ 'label' => __( 'Logo', 'oec-theme' ),              'icon' => '🖼' ],
		'colores' => [ 'label' => __( 'Paleta de colores', 'oec-theme' ), 'icon' => '🎨' ],
		'rastreo' => [ 'label' => __( 'Rastreo', 'oec-theme' ),           'icon' => '📊' ],
	];

	if ( ! array_key_exists( $tab, $tabs ) ) {
		$tab = 'logo';
	}

	if ( isset( $_GET['settings-updated'] ) ) {
		add_settings_error( 'oec_messages', 'oec_saved', __( 'Configuración guardada correctamente.', 'oec-theme' ), 'updated' );
	}

	// Keys that belong to each tab (for hidden-input preservation)
	$tab_keys = [
		'logo'    => [ 'logo_id', 'logo_url' ],
		'colores' => [ 'color_primary', 'color_dark', 'color_accent', 'color_light', 'color_text' ],
		'rastreo' => [ 'gtm_id', 'meta_pixel_id', 'ms_clarity_id' ],
	];

	$all_keys    = array_merge( ...array_values( $tab_keys ) );
	$current_tab_keys = $tab_keys[ $tab ];
	$other_keys  = array_diff( $all_keys, $current_tab_keys );

	oec_page_inline_styles();
	?>
	<div class="wrap oec-settings-wrap">

		<!-- Header -->
		<div class="oec-page-header">
			<div class="oec-page-header__left">
				<span class="oec-page-header__logo">OEC<span>.</span></span>
				<div>
					<h1><?php esc_html_e( 'Configuración del tema', 'oec-theme' ); ?></h1>
					<p><?php esc_html_e( 'Personalizá la apariencia y el rastreo de tu sitio.', 'oec-theme' ); ?></p>
				</div>
			</div>
		</div>

		<?php settings_errors( 'oec_messages' ); ?>

		<!-- Tabs -->
		<nav class="oec-tabs" aria-label="<?php esc_attr_e( 'Secciones', 'oec-theme' ); ?>">
			<?php foreach ( $tabs as $key => $data ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'oec-settings', 'tab' => $key ], admin_url( 'themes.php' ) ) ); ?>"
			   class="oec-tab <?php echo $tab === $key ? 'oec-tab--active' : ''; ?>">
				<span class="oec-tab__icon" aria-hidden="true"><?php echo $data['icon']; // phpcs:ignore ?></span>
				<?php echo esc_html( $data['label'] ); ?>
			</a>
			<?php endforeach; ?>
		</nav>

		<!-- Form -->
		<form method="post" action="options.php" class="oec-settings-form">
			<?php settings_fields( 'oec_settings_group' ); ?>

			<!-- Preserve other tabs' values as hidden inputs -->
			<?php foreach ( $other_keys as $key ) : ?>
			<input type="hidden" name="<?php echo esc_attr( OEC_OPTION . '[' . $key . ']' ); ?>"
			       value="<?php echo esc_attr( (string) ( $opts[ $key ] ?? '' ) ); ?>">
			<?php endforeach; ?>

			<div class="oec-settings-body">

				<?php if ( $tab === 'logo' ) : ?>
				<!-- ================================================
				     TAB: LOGO
				     ================================================ -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Logo del sitio', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Se mostrará en el header y el footer sobre fondo oscuro.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<div class="oec-logo-row">

							<!-- Preview -->
							<div class="oec-logo-preview-wrap">
								<span class="oec-logo-preview-label"><?php esc_html_e( 'Vista previa', 'oec-theme' ); ?></span>
								<div class="oec-logo-canvas" id="oec-logo-preview">
									<?php if ( $opts['logo_url'] ) : ?>
										<img src="<?php echo esc_url( $opts['logo_url'] ); ?>"
										     alt="<?php esc_attr_e( 'Logo', 'oec-theme' ); ?>"
										     class="oec-logo-img">
									<?php else : ?>
										<span class="oec-logo-placeholder"><?php esc_html_e( 'Sin logo', 'oec-theme' ); ?></span>
									<?php endif; ?>
								</div>
							</div>

							<!-- Actions -->
							<div class="oec-logo-actions">
								<input type="hidden" id="oec-logo-id"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[logo_id]"
								       value="<?php echo esc_attr( (string) $opts['logo_id'] ); ?>">
								<input type="hidden" id="oec-logo-url"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[logo_url]"
								       value="<?php echo esc_attr( $opts['logo_url'] ); ?>">

								<button type="button" id="oec-upload-logo" class="button button-primary button-hero">
									<?php echo $opts['logo_url']
										? esc_html__( 'Cambiar logo', 'oec-theme' )
										: esc_html__( 'Subir logo', 'oec-theme' ); ?>
								</button>

								<button type="button" id="oec-remove-logo"
								        class="button button-hero"
								        style="<?php echo $opts['logo_url'] ? '' : 'display:none;'; ?>">
									<?php esc_html_e( 'Quitar logo', 'oec-theme' ); ?>
								</button>

								<ul class="oec-hint-list">
									<li><?php esc_html_e( 'Formato recomendado: PNG con fondo transparente.', 'oec-theme' ); ?></li>
									<li><?php esc_html_e( 'Tamaño mínimo: 300 × 80 px.', 'oec-theme' ); ?></li>
									<li><?php esc_html_e( 'El logo se mostrará siempre sobre fondo oscuro.', 'oec-theme' ); ?></li>
								</ul>
							</div>

						</div>

					</div>
				</div>

				<?php elseif ( $tab === 'colores' ) : ?>
				<!-- ================================================
				     TAB: COLORES
				     ================================================ -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Paleta de colores', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Los cambios se aplican globalmente a todo el sitio mediante variables CSS.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<!-- Swatches preview -->
						<div class="oec-swatches" id="oec-swatches-preview" aria-hidden="true">
							<?php
							$swatch_defs = [
								'color_primary' => __( 'Primario', 'oec-theme' ),
								'color_dark'    => __( 'Oscuro', 'oec-theme' ),
								'color_accent'  => __( 'Acento', 'oec-theme' ),
								'color_light'   => __( 'Claro', 'oec-theme' ),
								'color_text'    => __( 'Texto', 'oec-theme' ),
							];
							foreach ( $swatch_defs as $key => $label ) :
							?>
							<div class="oec-swatch">
								<div class="oec-swatch__color" id="swatch-<?php echo esc_attr( $key ); ?>"
								     style="background:<?php echo esc_attr( $opts[ $key ] ); ?>;"></div>
								<span class="oec-swatch__label"><?php echo esc_html( $label ); ?></span>
								<span class="oec-swatch__hex" id="swatch-hex-<?php echo esc_attr( $key ); ?>">
									<?php echo esc_html( $opts[ $key ] ); ?>
								</span>
							</div>
							<?php endforeach; ?>
						</div>

						<!-- Color fields -->
						<div class="oec-color-fields">
							<?php
							$color_fields = [
								'color_primary' => [
									'label' => __( 'Color primario', 'oec-theme' ),
									'desc'  => __( 'Header de navegación, íconos de servicio, bordes en hover.', 'oec-theme' ),
									'used'  => '--color-primary',
								],
								'color_dark' => [
									'label' => __( 'Color oscuro', 'oec-theme' ),
									'desc'  => __( 'Fondo del header, footer, sección de testimonios y gradientes.', 'oec-theme' ),
									'used'  => '--color-dark',
								],
								'color_accent' => [
									'label' => __( 'Color de acento', 'oec-theme' ),
									'desc'  => __( 'Botones primarios, eyebrow chips, estrellas de testimonios.', 'oec-theme' ),
									'used'  => '--color-accent',
								],
								'color_light' => [
									'label' => __( 'Color claro / fondo alterno', 'oec-theme' ),
									'desc'  => __( 'Fondo de secciones alternadas, campos de formulario.', 'oec-theme' ),
									'used'  => '--color-light',
								],
								'color_text' => [
									'label' => __( 'Color de texto', 'oec-theme' ),
									'desc'  => __( 'Color base de todo el cuerpo de texto.', 'oec-theme' ),
									'used'  => '--color-text',
								],
							];
							foreach ( $color_fields as $key => $info ) :
							?>
							<div class="oec-color-row">
								<div class="oec-color-row__meta">
									<label class="oec-color-row__label" for="oec-<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $info['label'] ); ?>
									</label>
									<p class="oec-color-row__desc"><?php echo esc_html( $info['desc'] ); ?></p>
									<code class="oec-color-row__var"><?php echo esc_html( $info['used'] ); ?></code>
								</div>
								<div class="oec-color-row__picker">
									<input type="text"
									       id="oec-<?php echo esc_attr( $key ); ?>"
									       name="<?php echo esc_attr( OEC_OPTION . '[' . $key . ']' ); ?>"
									       value="<?php echo esc_attr( $opts[ $key ] ); ?>"
									       class="oec-color-picker"
									       data-key="<?php echo esc_attr( $key ); ?>"
									       data-default-color="<?php echo esc_attr( oec_get_defaults()[ $key ] ); ?>">
								</div>
							</div>
							<?php endforeach; ?>
						</div>

						<div class="oec-reset-row">
							<button type="button" id="oec-reset-colors" class="button">
								<?php esc_html_e( 'Restablecer colores por defecto', 'oec-theme' ); ?>
							</button>
						</div>

					</div>
				</div>

				<?php elseif ( $tab === 'rastreo' ) : ?>
				<!-- ================================================
				     TAB: RASTREO
				     ================================================ -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Rastreo y analítica', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Los scripts se inyectan automáticamente en el frontend. Dejá en blanco los que no uses.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<!-- GTM -->
						<div class="oec-tracker-row">
							<div class="oec-tracker-row__head">
								<div class="oec-tracker-logo oec-tracker-logo--gtm">GTM</div>
								<div>
									<strong><?php esc_html_e( 'Google Tag Manager', 'oec-theme' ); ?></strong>
									<p><?php esc_html_e( 'Gestiona todos tus tags de Google (GA4, Ads, etc.) desde un solo lugar.', 'oec-theme' ); ?></p>
								</div>
								<div class="oec-tracker-status" id="status-gtm">
									<?php oec_tracker_badge( $opts['gtm_id'] ); ?>
								</div>
							</div>
							<div class="oec-tracker-row__field">
								<label for="oec-gtm-id"><?php esc_html_e( 'Container ID', 'oec-theme' ); ?></label>
								<input type="text" id="oec-gtm-id"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[gtm_id]"
								       value="<?php echo esc_attr( $opts['gtm_id'] ); ?>"
								       class="regular-text oec-tracker-input"
								       placeholder="GTM-XXXXXXX"
								       data-tracker="gtm"
								       spellcheck="false">
								<p class="description">
									<?php esc_html_e( 'Google Tag Manager → Administrador → tu contenedor. Formato: GTM-XXXXXXX.', 'oec-theme' ); ?>
								</p>
							</div>
						</div>

						<hr class="oec-divider">

						<!-- Meta Pixel -->
						<div class="oec-tracker-row">
							<div class="oec-tracker-row__head">
								<div class="oec-tracker-logo oec-tracker-logo--meta">f</div>
								<div>
									<strong><?php esc_html_e( 'Meta Pixel', 'oec-theme' ); ?></strong>
									<p><?php esc_html_e( 'Medición de conversiones y audiencias para campañas de Facebook e Instagram.', 'oec-theme' ); ?></p>
								</div>
								<div class="oec-tracker-status" id="status-meta_pixel_id">
									<?php oec_tracker_badge( $opts['meta_pixel_id'] ); ?>
								</div>
							</div>
							<div class="oec-tracker-row__field">
								<label for="oec-meta-pixel-id"><?php esc_html_e( 'Pixel ID', 'oec-theme' ); ?></label>
								<input type="text" id="oec-meta-pixel-id"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[meta_pixel_id]"
								       value="<?php echo esc_attr( $opts['meta_pixel_id'] ); ?>"
								       class="regular-text oec-tracker-input"
								       placeholder="1234567890123456"
								       data-tracker="meta_pixel_id"
								       spellcheck="false"
								       inputmode="numeric">
								<p class="description">
									<?php esc_html_e( 'Meta Business Suite → Administrador de eventos → Píxeles. Formato: 15–16 dígitos.', 'oec-theme' ); ?>
								</p>
							</div>
						</div>

						<hr class="oec-divider">

						<!-- MS Clarity -->
						<div class="oec-tracker-row">
							<div class="oec-tracker-row__head">
								<div class="oec-tracker-logo oec-tracker-logo--clarity">C</div>
								<div>
									<strong><?php esc_html_e( 'Microsoft Clarity', 'oec-theme' ); ?></strong>
									<p><?php esc_html_e( 'Mapas de calor, grabaciones de sesión y analítica de comportamiento. Gratis.', 'oec-theme' ); ?></p>
								</div>
								<div class="oec-tracker-status" id="status-ms_clarity_id">
									<?php oec_tracker_badge( $opts['ms_clarity_id'] ); ?>
								</div>
							</div>
							<div class="oec-tracker-row__field">
								<label for="oec-ms-clarity-id"><?php esc_html_e( 'Project ID', 'oec-theme' ); ?></label>
								<input type="text" id="oec-ms-clarity-id"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[ms_clarity_id]"
								       value="<?php echo esc_attr( $opts['ms_clarity_id'] ); ?>"
								       class="regular-text oec-tracker-input"
								       placeholder="xxxxxxxxxx"
								       data-tracker="ms_clarity_id"
								       spellcheck="false">
								<p class="description">
									<?php esc_html_e( 'Clarity → tu proyecto → Configuración → Instalar manualmente. Formato: ~10 caracteres alfanuméricos.', 'oec-theme' ); ?>
								</p>
							</div>
						</div>

					</div>
				</div>

				<?php endif; ?>

			</div><!-- .oec-settings-body -->

			<div class="oec-settings-footer">
				<?php submit_button( __( 'Guardar cambios', 'oec-theme' ), 'primary large', 'submit', false ); ?>
			</div>

		</form>
	</div><!-- .wrap -->
	<?php
}

/* ============================================================
   HELPER: Tracker active/inactive badge
   ============================================================ */
function oec_tracker_badge( string $value ): void {
	if ( $value ) {
		echo '<span class="oec-badge oec-badge--on">' . esc_html__( 'Activo', 'oec-theme' ) . '</span>';
	} else {
		echo '<span class="oec-badge oec-badge--off">' . esc_html__( 'Inactivo', 'oec-theme' ) . '</span>';
	}
}

/* ============================================================
   INLINE ADMIN CSS
   ============================================================ */
function oec_page_inline_styles(): void {
	?>
	<style>
	/* ---- Layout ---- */
	.oec-settings-wrap { max-width: 860px; }

	/* ---- Page header ---- */
	.oec-page-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 1.5rem 2rem;
		background: #071b2d;
		border-radius: 10px;
		margin-bottom: 1.5rem;
		color: #fff;
	}
	.oec-page-header__left { display: flex; align-items: center; gap: 1.25rem; }
	.oec-page-header__logo {
		font-size: 2rem;
		font-weight: 900;
		color: #fff;
		letter-spacing: -.03em;
		line-height: 1;
	}
	.oec-page-header__logo span { color: #e8952a; }
	.oec-page-header h1 {
		font-size: 1.25rem;
		color: #fff;
		margin: 0 0 .25rem;
		padding: 0;
		border: none;
	}
	.oec-page-header p { color: rgba(255,255,255,.55); margin: 0; font-size: .875rem; }

	/* ---- Tabs ---- */
	.oec-tabs {
		display: flex;
		gap: .25rem;
		margin-bottom: 1.5rem;
		border-bottom: 2px solid #dcdcde;
		padding-bottom: 0;
	}
	.oec-tab {
		display: inline-flex;
		align-items: center;
		gap: .5rem;
		padding: .625rem 1.25rem;
		font-size: .9375rem;
		font-weight: 500;
		color: #50575e;
		text-decoration: none;
		border-radius: 6px 6px 0 0;
		border: 2px solid transparent;
		border-bottom: none;
		margin-bottom: -2px;
		transition: color .15s, background .15s;
	}
	.oec-tab:hover { color: #194872; background: #f0f4f8; }
	.oec-tab--active {
		color: #194872;
		background: #fff;
		border-color: #dcdcde;
		border-bottom-color: #fff;
		font-weight: 600;
	}
	.oec-tab__icon { font-size: 1rem; }

	/* ---- Card ---- */
	.oec-card {
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 10px;
		overflow: hidden;
		box-shadow: 0 1px 3px rgba(0,0,0,.06);
	}
	.oec-card__header {
		padding: 1.5rem 2rem;
		border-bottom: 1px solid #f0f0f1;
		background: #fafafa;
	}
	.oec-card__header h2 { font-size: 1.0625rem; margin: 0 0 .375rem; padding: 0; }
	.oec-card__header p  { margin: 0; color: #646970; font-size: .875rem; }
	.oec-card__body { padding: 2rem; }

	/* ---- Logo tab ---- */
	.oec-logo-row { display: grid; grid-template-columns: auto 1fr; gap: 2rem; align-items: start; }
	.oec-logo-preview-label { display: block; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #646970; margin-bottom: .5rem; font-weight: 600; }
	.oec-logo-canvas {
		width: 280px;
		height: 90px;
		background: #071b2d;
		border-radius: 8px;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 1rem 1.5rem;
	}
	.oec-logo-img   { max-height: 58px; max-width: 220px; width: auto; display: block; }
	.oec-logo-placeholder { color: rgba(255,255,255,.3); font-size: .875rem; }
	.oec-logo-actions { display: flex; flex-direction: column; gap: .75rem; align-items: flex-start; }
	.oec-hint-list { margin: .5rem 0 0; padding: 0; list-style: none; }
	.oec-hint-list li { font-size: .8125rem; color: #646970; padding-left: 1rem; position: relative; margin-bottom: .25rem; }
	.oec-hint-list li::before { content: '✓'; position: absolute; left: 0; color: #194872; }

	/* ---- Colors tab ---- */
	.oec-swatches {
		display: flex;
		gap: 1rem;
		margin-bottom: 2rem;
		padding: 1.5rem;
		background: #f6f7f7;
		border-radius: 8px;
		border: 1px solid #e5e7eb;
	}
	.oec-swatch         { text-align: center; flex: 1; }
	.oec-swatch__color  { height: 56px; border-radius: 8px; margin-bottom: .5rem; border: 1px solid rgba(0,0,0,.08); box-shadow: 0 1px 3px rgba(0,0,0,.1); transition: transform .2s; }
	.oec-swatch__color:hover { transform: scale(1.06); }
	.oec-swatch__label  { display: block; font-size: .6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #646970; margin-bottom: .125rem; }
	.oec-swatch__hex    { display: block; font-size: .75rem; color: #333; font-family: monospace; }

	.oec-color-fields { display: flex; flex-direction: column; gap: 0; }
	.oec-color-row {
		display: grid;
		grid-template-columns: 1fr auto;
		align-items: center;
		gap: 1.5rem;
		padding: 1.25rem 0;
		border-bottom: 1px solid #f0f0f1;
	}
	.oec-color-row:last-child { border-bottom: none; }
	.oec-color-row__label { font-weight: 600; font-size: .9375rem; display: block; margin-bottom: .25rem; }
	.oec-color-row__desc  { font-size: .8125rem; color: #646970; margin: 0 0 .375rem; }
	.oec-color-row__var   { font-size: .75rem; background: #f0f4f8; color: #194872; padding: .125rem .5rem; border-radius: 4px; font-family: monospace; }
	.oec-color-row__picker .wp-picker-container { display: flex; align-items: center; gap: .5rem; }
	.oec-reset-row { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #f0f0f1; }

	/* ---- Tracker tab ---- */
	.oec-tracker-row      { padding: 1.5rem 0; }
	.oec-tracker-row__head {
		display: flex;
		align-items: flex-start;
		gap: 1rem;
		margin-bottom: 1.25rem;
	}
	.oec-tracker-row__head > div:nth-child(2) { flex: 1; }
	.oec-tracker-row__head strong { display: block; font-size: .9375rem; margin-bottom: .25rem; }
	.oec-tracker-row__head p { margin: 0; font-size: .8125rem; color: #646970; }

	.oec-tracker-logo {
		width: 40px;
		height: 40px;
		border-radius: 8px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-weight: 700;
		font-size: .875rem;
		color: #fff;
		flex-shrink: 0;
		letter-spacing: -.02em;
	}
	.oec-tracker-logo--gtm     { background: #4285F4; font-size: .625rem; }
	.oec-tracker-logo--meta    { background: #1877F2; font-size: 1.125rem; }
	.oec-tracker-logo--clarity { background: #0078D4; }

	.oec-tracker-row__field { display: flex; flex-direction: column; gap: .5rem; }
	.oec-tracker-row__field label { font-weight: 600; font-size: .875rem; }
	.oec-tracker-input { font-family: monospace !important; font-size: .9rem !important; }

	.oec-badge {
		display: inline-flex;
		align-items: center;
		gap: .25rem;
		padding: .25rem .625rem;
		border-radius: 100px;
		font-size: .75rem;
		font-weight: 600;
		white-space: nowrap;
	}
	.oec-badge::before { content: '●'; font-size: .5rem; }
	.oec-badge--on  { background: #e6f4ea; color: #1e7e34; }
	.oec-badge--off { background: #f0f0f1; color: #646970; }

	.oec-tracker-status { display: flex; align-items: flex-start; padding-top: .125rem; }

	.oec-divider { border: none; border-top: 1px solid #f0f0f1; margin: 0; }

	/* ---- Footer ---- */
	.oec-settings-footer {
		margin-top: 1.5rem;
		padding: 1.25rem 2rem;
		background: #fafafa;
		border: 1px solid #dcdcde;
		border-radius: 10px;
		display: flex;
		align-items: center;
		gap: 1rem;
	}

	@media (max-width: 600px) {
		.oec-logo-row  { grid-template-columns: 1fr; }
		.oec-logo-canvas { width: 100%; }
		.oec-swatches  { flex-wrap: wrap; }
		.oec-color-row { grid-template-columns: 1fr; }
	}
	</style>
	<?php
}

/* ============================================================
   FRONTEND: Dynamic CSS variables
   ============================================================ */
function oec_output_dynamic_css(): void {
	$opts     = oec_get_options();
	$defaults = oec_get_defaults();

	$vars = [
		'--color-primary'      => $opts['color_primary'],
		'--color-dark'         => $opts['color_dark'],
		'--color-accent'       => $opts['color_accent'],
		'--color-accent-hover' => oec_darken_hex( $opts['color_accent'] ),
		'--color-light'        => $opts['color_light'],
		'--color-text'         => $opts['color_text'],
	];

	$changed = false;
	foreach ( [ 'color_primary', 'color_dark', 'color_accent', 'color_light', 'color_text' ] as $k ) {
		if ( $opts[ $k ] !== $defaults[ $k ] ) {
			$changed = true;
			break;
		}
	}

	if ( ! $changed ) {
		return;
	}

	$css = ':root{';
	foreach ( $vars as $prop => $val ) {
		$css .= esc_attr( $prop ) . ':' . esc_attr( $val ) . ';';
	}
	$css .= '}';

	echo "\n<style id=\"oec-dynamic-colors\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_head', 'oec_output_dynamic_css', 5 );

/* ============================================================
   FRONTEND: Tracker scripts
   ============================================================ */
function oec_output_trackers_head(): void {
	$opts = oec_get_options();

	// Google Tag Manager — <head>
	if ( ! empty( $opts['gtm_id'] ) ) {
		$id = esc_js( $opts['gtm_id'] );
		echo "\n<!-- Google Tag Manager -->\n";
		echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . $id . "');</script>\n";
		echo "<!-- End Google Tag Manager -->\n";
	}

	// Meta Pixel — <head>
	if ( ! empty( $opts['meta_pixel_id'] ) ) {
		$id = esc_js( $opts['meta_pixel_id'] );
		echo "\n<!-- Meta Pixel Code -->\n";
		echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $id . "');fbq('track','PageView');</script>\n";
		echo "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id=" . urlencode( $opts['meta_pixel_id'] ) . "&ev=PageView&noscript=1\"/></noscript>\n";
		echo "<!-- End Meta Pixel Code -->\n";
	}

	// Microsoft Clarity — <head>
	if ( ! empty( $opts['ms_clarity_id'] ) ) {
		$id = esc_js( $opts['ms_clarity_id'] );
		echo "\n<!-- Microsoft Clarity -->\n";
		echo "<script type=\"text/javascript\">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\" + i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','" . $id . "');</script>\n";
		echo "<!-- End Microsoft Clarity -->\n";
	}
}
add_action( 'wp_head', 'oec_output_trackers_head', 1 );

// Google Tag Manager — <body> noscript
function oec_output_gtm_body(): void {
	$opts = oec_get_options();
	if ( empty( $opts['gtm_id'] ) ) {
		return;
	}
	$id = urlencode( $opts['gtm_id'] );
	echo "\n<!-- Google Tag Manager (noscript) -->\n";
	echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=" . $id . "\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
	echo "<!-- End Google Tag Manager (noscript) -->\n";
}
add_action( 'wp_body_open', 'oec_output_gtm_body', 1 );
