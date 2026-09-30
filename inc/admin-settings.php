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
		'logo_height'      => 40,
		// Solo 5 colores configurables; el resto de la paleta (fondos
		// suaves, bordes, texto secundario, badges) se deriva de estos en
		// oec_palette(). Blanco, sombra y avisos son fijos en style.css.
		'color_primary'    => '#194872',
		'color_dark'       => '#012b1b',
		'color_dark_2'     => '#14510b',
		'color_accent'     => '#ffde59',
		'color_text'       => '#1e2d3d',
		'gtm_id'           => '',
		'meta_pixel_id'    => '',
		'ms_clarity_id'    => '',
		'oec_api_token'      => '',
		'oec_anthropic_key'  => '',
		'credits_api_key'    => '',
		// Topbar & footer content
		'campus_virtual_url' => '',
		'footer_desc'        => 'La comunidad educativa en ciencias del ejercicio físico más grande de hispanoamérica.',
		'footer_landings'    => '',
		'footer_legal'       => '',
		'footer_cta_url'     => '',
		'social_linkedin'    => '',
		'social_instagram'   => '',
		'social_facebook'    => '',
		'social_youtube'     => '',
		'social_x'           => '',
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

/* Mezcla un hex hacia blanco (usado para el fondo pastel de los badges). */
function oec_lighten_hex( string $hex, int $percent = 85 ): string {
	$hex    = ltrim( $hex, '#' );
	$factor = $percent / 100;
	$mix    = fn( int $c ) => (int) round( $c + ( 255 - $c ) * $factor );
	$r      = $mix( hexdec( substr( $hex, 0, 2 ) ) );
	$g      = $mix( hexdec( substr( $hex, 2, 2 ) ) );
	$b      = $mix( hexdec( substr( $hex, 4, 2 ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/* Claves de los colores configurables (orden de la pantalla de ajustes). */
function oec_color_keys(): array {
	return [ 'color_primary', 'color_dark', 'color_dark_2', 'color_accent', 'color_text' ];
}

/* Paleta completa a partir de los 5 colores base. Los tonos derivados
 * usan las mismas mezclas que los valores por defecto de style.css, así
 * que con la paleta por defecto el resultado coincide con el archivo. */
function oec_palette( ?array $opts = null ): array {
	$opts     = $opts ?? oec_get_options();
	$defaults = oec_get_defaults();
	$c        = [];
	foreach ( oec_color_keys() as $key ) {
		$c[ substr( $key, 6 ) ] = sanitize_hex_color( $opts[ $key ] ?? '' ) ?: $defaults[ $key ];
	}
	$c['accent_hover'] = oec_darken_hex( $c['accent'] );
	$c['light']        = oec_lighten_hex( $c['primary'], 97 );
	$c['border']       = oec_lighten_hex( $c['primary'], 85 );
	$c['muted']        = oec_lighten_hex( $c['text'], 30 );
	$c['badge_articulo']    = $c['primary'];
	$c['badge_articulo_bg'] = oec_lighten_hex( $c['primary'] );
	$c['badge_blog']        = $c['dark_2'];
	$c['badge_blog_bg']     = oec_lighten_hex( $c['dark_2'] );
	return $c;
}

/* "25,72,114" a partir de "#194872" — para usar el color con alpha:
 * rgba(var(--color-x-rgb), .5) */
function oec_hex_to_rgb_list( string $hex ): string {
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	return implode( ',', [
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	] );
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
	$clean['logo_id']     = absint( $raw['logo_id']  ?? 0 );
	$clean['logo_url']    = esc_url_raw( $raw['logo_url'] ?? '' );
	$clean['logo_height'] = min( 120, max( 20, absint( $raw['logo_height'] ?? 40 ) ) );

	// Favicon: se guarda en el ícono del sitio de WordPress (site_icon), que
	// el core imprime en wp_head. Solo viene en el POST de la pestaña
	// Identidad (no se preserva como hidden), por eso el isset.
	if ( isset( $raw['site_icon'] ) ) {
		update_option( 'site_icon', absint( $raw['site_icon'] ) );
	}

	// Colors
	foreach ( oec_color_keys() as $key ) {
		$val          = sanitize_hex_color( $raw[ $key ] ?? '' );
		$clean[ $key ] = $val ?: $defaults[ $key ];
	}

	// Trackers
	$clean['gtm_id']        = strtoupper( sanitize_text_field( $raw['gtm_id'] ?? '' ) );
	$clean['meta_pixel_id'] = preg_replace( '/\D/', '', $raw['meta_pixel_id'] ?? '' );
	$clean['ms_clarity_id'] = preg_replace( '/[^a-z0-9]/i', '', $raw['ms_clarity_id'] ?? '' );

	// OEC API
	$clean['oec_api_token']   = sanitize_text_field( $raw['oec_api_token']   ?? '' );
	$clean['credits_api_key'] = sanitize_text_field( $raw['credits_api_key'] ?? '' );

	// Anthropic
	$clean['oec_anthropic_key'] = sanitize_text_field( $raw['oec_anthropic_key'] ?? '' );

	// Topbar & footer
	$clean['campus_virtual_url'] = esc_url_raw( $raw['campus_virtual_url'] ?? '' );
	$clean['footer_desc']        = sanitize_text_field( $raw['footer_desc'] ?? '' );
	$clean['footer_landings']    = sanitize_textarea_field( $raw['footer_landings'] ?? '' );
	$clean['footer_legal']       = sanitize_textarea_field( $raw['footer_legal'] ?? '' );
	$clean['footer_cta_url']     = esc_url_raw( $raw['footer_cta_url'] ?? '' );
	foreach ( [ 'social_linkedin', 'social_instagram', 'social_facebook', 'social_youtube', 'social_x' ] as $skey ) {
		$clean[ $skey ] = esc_url_raw( $raw[ $skey ] ?? '' );
	}

	return $clean;
}

/* ============================================================
   ADMIN MENU
   ============================================================ */
function oec_add_admin_menu(): void {
	add_theme_page(
		__( 'Configuración', 'oec-theme' ),
		__( 'Online Education Center', 'oec-theme' ),
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
		oec_asset_version( 'assets/js/admin-settings.js' ),
		true
	);
	wp_localize_script( 'oec-admin-settings', 'oecAdmin', [
		'mediaTitle'  => __( 'Seleccionar logo', 'oec-theme' ),
		'mediaButton' => __( 'Usar como logo', 'oec-theme' ),
		'noLogo'      => __( 'Sin logo cargado', 'oec-theme' ),
		'faviconTitle'  => __( 'Seleccionar favicon', 'oec-theme' ),
		'faviconButton' => __( 'Usar como favicon', 'oec-theme' ),
		'noFavicon'     => __( 'Sin favicon', 'oec-theme' ),
		'uploadFavicon' => __( 'Subir favicon', 'oec-theme' ),
		'changeFavicon' => __( 'Cambiar favicon', 'oec-theme' ),
		'uploadLabel' => __( 'Subir logo', 'oec-theme' ),
		'changeLabel' => __( 'Cambiar logo', 'oec-theme' ),
	] );
}
add_action( 'admin_enqueue_scripts', 'oec_admin_enqueue' );

/* ============================================================
   FORCE UPDATE CHECK — acción GET
   ============================================================ */
function oec_maybe_handle_force_check(): void {
	if ( ! isset( $_GET['oec_action'] ) || $_GET['oec_action'] !== 'force_update_check' ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_nonce'] ), 'oec_force_update_check' ) ) return;

	// Leer repo desde el header del style.css
	$theme_data = get_file_data( get_template_directory() . '/style.css', [ 'github_uri' => 'GitHub Theme URI' ] );
	$repo       = $theme_data['github_uri'] ?? '';

	// 1. Borrar nuestro caché del updater
	if ( $repo ) {
		delete_transient( 'oec_updater_' . md5( $repo ) );
	}

	// 2. Borrar el caché de actualizaciones de WordPress
	delete_site_transient( 'update_themes' );

	// 3. Forzar re-verificación inmediata
	wp_update_themes();

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'oec-settings', 'tab' => 'actualizaciones', 'checked' => '1' ],
		admin_url( 'themes.php' )
	) );
	exit;
}
add_action( 'admin_init', 'oec_maybe_handle_force_check' );

/* ============================================================
   SETTINGS PAGE
   ============================================================ */
function oec_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$opts = oec_get_options();
	$tab  = sanitize_key( $_GET['tab'] ?? 'identidad' );
	// Pestañas viejas (antes separadas) → la nueva que las reúne.
	if ( in_array( $tab, [ 'logo', 'colores' ], true ) ) {
		$tab = 'identidad';
	} elseif ( 'rastreo' === $tab ) {
		$tab = 'integraciones';
	}
	$tabs = [
		'identidad'       => [ 'label' => __( 'Identidad gráfica', 'oec-theme' ), 'icon' => '🎨' ],
		'integraciones'   => [ 'label' => __( 'Integraciones', 'oec-theme' ),     'icon' => '🔌' ],
		'actualizaciones' => [ 'label' => __( 'Actualizaciones', 'oec-theme' ),   'icon' => '🔄' ],
		'asistente'       => [ 'label' => __( 'Asistente IA', 'oec-theme' ),       'icon' => '🤖' ],
		'contenido'       => [ 'label' => __( 'Contenido', 'oec-theme' ),           'icon' => '🧭' ],
	];

	if ( ! array_key_exists( $tab, $tabs ) ) {
		$tab = 'identidad';
	}

	if ( isset( $_GET['settings-updated'] ) ) {
		add_settings_error( 'oec_messages', 'oec_saved', __( 'Configuración guardada correctamente.', 'oec-theme' ), 'updated' );
	}

	// Keys that belong to each tab (for hidden-input preservation)
	$tab_keys = [
		'identidad'       => array_merge( [ 'logo_id', 'logo_url', 'logo_height' ], oec_color_keys() ),
		'integraciones'   => [ 'oec_api_token', 'credits_api_key', 'gtm_id', 'meta_pixel_id', 'ms_clarity_id' ],
		'actualizaciones' => [],
		'asistente'       => [ 'oec_anthropic_key' ],
		'contenido'       => [ 'campus_virtual_url', 'footer_desc', 'footer_landings', 'footer_legal', 'footer_cta_url', 'social_linkedin', 'social_instagram', 'social_facebook', 'social_youtube', 'social_x' ],
	];

	$all_keys    = array_merge( ...array_values( $tab_keys ) );
	$current_tab_keys = $tab_keys[ $tab ];
	$other_keys  = array_diff( $all_keys, $current_tab_keys );

	oec_page_inline_styles();
	?>
	<div class="wrap oec-settings-wrap">

		<h1><?php esc_html_e( 'Configuración', 'oec-theme' ); ?></h1>

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

				<?php if ( $tab === 'identidad' ) : ?>
				<!-- ================================================
				     TAB: IDENTIDAD GRÁFICA (logo, favicon, colores)
				     ================================================ -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Logo', 'oec-theme' ); ?></h2>
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

								<div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #f0f0f1;">
									<label for="oec-logo-height" style="font-weight:600;font-size:.875rem;display:block;margin-bottom:.5rem;">
										<?php esc_html_e( 'Alto en el header (px)', 'oec-theme' ); ?>
									</label>
									<input type="number" id="oec-logo-height"
									       name="<?php echo esc_attr( OEC_OPTION ); ?>[logo_height]"
									       value="<?php echo esc_attr( (string) $opts['logo_height'] ); ?>"
									       min="20" max="120" step="1"
									       style="width:80px;">
									<p class="description" style="margin-top:.375rem;">
										<?php esc_html_e( 'Altura de visualización del logo en el encabezado (entre 20 y 120 px).', 'oec-theme' ); ?>
									</p>
								</div>
							</div>

						</div>

					</div>
				</div>

				<?php
				$site_icon_id  = (int) get_option( 'site_icon' );
				$site_icon_url = $site_icon_id ? wp_get_attachment_image_url( $site_icon_id, 'thumbnail' ) : '';
				?>
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Favicon', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Ícono de la pestaña del navegador, favoritos y accesos directos en el celular.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">
						<div class="oec-favicon-row">
							<div class="oec-favicon-preview" id="oec-favicon-preview">
								<?php if ( $site_icon_url ) : ?>
									<img src="<?php echo esc_url( $site_icon_url ); ?>" alt="">
								<?php else : ?>
									<span class="oec-favicon-placeholder"><?php esc_html_e( 'Sin favicon', 'oec-theme' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="oec-logo-actions">
								<input type="hidden" id="oec-favicon-id"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[site_icon]"
								       value="<?php echo esc_attr( (string) $site_icon_id ); ?>">
								<div class="oec-btn-row">
									<button type="button" id="oec-upload-favicon" class="button button-primary">
										<?php echo $site_icon_url
											? esc_html__( 'Cambiar favicon', 'oec-theme' )
											: esc_html__( 'Subir favicon', 'oec-theme' ); ?>
									</button>
									<button type="button" id="oec-remove-favicon" class="button"
									        style="<?php echo $site_icon_url ? '' : 'display:none;'; ?>">
										<?php esc_html_e( 'Quitar favicon', 'oec-theme' ); ?>
									</button>
								</div>
								<ul class="oec-hint-list">
									<li><?php esc_html_e( 'Imagen cuadrada, PNG, de al menos 512 × 512 px.', 'oec-theme' ); ?></li>
								</ul>
							</div>
						</div>
					</div>
				</div>

				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Colores', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Cinco colores base. Los tonos secundarios (fondos suaves, bordes, texto secundario, etiquetas) se calculan solos a partir de estos.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<?php
						$color_fields = [
							'color_primary' => [
								'label' => __( 'Primario', 'oec-theme' ),
								'desc'  => __( 'Links, íconos, bordes en hover. También define el fondo suave de secciones y el color de los bordes.', 'oec-theme' ),
							],
							'color_dark' => [
								'label' => __( 'Oscuro', 'oec-theme' ),
								'desc'  => __( 'Fondo del header, footer y secciones oscuras.', 'oec-theme' ),
							],
							'color_dark_2' => [
								'label' => __( 'Oscuro secundario', 'oec-theme' ),
								'desc'  => __( 'Segundo tono de los degradés del hero y menús desplegables.', 'oec-theme' ),
							],
							'color_accent' => [
								'label' => __( 'Acento', 'oec-theme' ),
								'desc'  => __( 'Botones principales, chips y estrellas. El tono hover se calcula solo.', 'oec-theme' ),
							],
							'color_text' => [
								'label' => __( 'Texto', 'oec-theme' ),
								'desc'  => __( 'Texto del cuerpo. El texto secundario (fechas, metadatos) se aclara a partir de este.', 'oec-theme' ),
							],
						];
						$palette = oec_palette( $opts );
						// Derivados que se muestran como referencia: [etiqueta, color base, % hacia blanco].
						$derived = [
							'light'  => [ __( 'Fondo suave', 'oec-theme' ),      'color_primary', 97 ],
							'border' => [ __( 'Bordes', 'oec-theme' ),           'color_primary', 85 ],
							'muted'  => [ __( 'Texto secundario', 'oec-theme' ), 'color_text',    30 ],
						];
						?>

						<div class="oec-color-fields">
							<?php foreach ( $color_fields as $key => $info ) : ?>
							<div class="oec-color-row">
								<div class="oec-color-row__meta">
									<label class="oec-color-row__label" for="oec-<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $info['label'] ); ?>
									</label>
									<p class="oec-color-row__desc"><?php echo esc_html( $info['desc'] ); ?></p>
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

						<div class="oec-derived">
							<span class="oec-derived__title"><?php esc_html_e( 'Calculados automáticamente', 'oec-theme' ); ?></span>
							<?php foreach ( $derived as $dkey => [ $dlabel, $from, $mix ] ) : ?>
							<span class="oec-derived__item">
								<span class="oec-derived__dot" data-from="<?php echo esc_attr( $from ); ?>" data-mix="<?php echo esc_attr( (string) $mix ); ?>"
								      style="background:<?php echo esc_attr( $palette[ $dkey ] ); ?>;"></span>
								<?php echo esc_html( $dlabel ); ?>
							</span>
							<?php endforeach; ?>
						</div>

						<div class="oec-reset-row">
							<button type="button" id="oec-reset-colors" class="button">
								<?php esc_html_e( 'Restablecer colores por defecto', 'oec-theme' ); ?>
							</button>
						</div>

					</div>
				</div>

				<?php elseif ( $tab === 'integraciones' ) : ?>
				<!-- ================================================
				     TAB: INTEGRACIONES (APIs + rastreo)
				     ================================================ -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'API de formaciones (OAS)', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Se usa server-side; nunca se expone al navegador.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<div class="oec-tracker-row">
							<div class="oec-tracker-row__head">
								<div class="oec-tracker-logo" style="background:#194872;font-size:.6rem;font-weight:900;">OEC</div>
								<div>
									<strong><?php esc_html_e( 'Token de la API OAS', 'oec-theme' ); ?></strong>
									<p><?php esc_html_e( 'Sincroniza el catálogo de formaciones (listado de /formaciones y asistente IA) y trae las próximas formaciones del newsletter.', 'oec-theme' ); ?></p>
								</div>
								<div class="oec-tracker-status" id="status-oec_api_token">
									<?php oec_tracker_badge( $opts['oec_api_token'] ); ?>
								</div>
							</div>

							<div class="oec-tracker-row__field">
								<label for="oec-api-token"><?php esc_html_e( 'API Token', 'oec-theme' ); ?></label>
								<input type="password"
								       id="oec-api-token"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[oec_api_token]"
								       value="<?php echo esc_attr( $opts['oec_api_token'] ); ?>"
								       class="regular-text oec-tracker-input"
								       placeholder="PjTzQpp..."
								       data-tracker="oec_api_token"
								       autocomplete="new-password"
								       spellcheck="false">
								<p class="description">
									<?php esc_html_e( 'Token de autenticación para la API de OEC. Lo encontrás en tu panel de administración de Online Education Center.', 'oec-theme' ); ?>
									<?php if ( $opts['oec_api_token'] ) : ?>
									<br><span style="color:#1e7e34;font-weight:600;">✓ <?php esc_html_e( 'Token configurado.', 'oec-theme' ); ?></span>
									<?php endif; ?>
								</p>
							</div>

						</div>

					</div>
				</div>

					<!-- Credits API -->
					<div class="oec-card">
						<div class="oec-card__header">
							<h2><?php esc_html_e( 'API de Créditos', 'oec-theme' ); ?></h2>
							<p><?php esc_html_e( 'Clave para el sistema de créditos. Se usa server-side para verificar y otorgar créditos.', 'oec-theme' ); ?></p>
						</div>
						<div class="oec-card__body">
							<div class="oec-tracker-row">
								<div class="oec-tracker-row__head">
									<div class="oec-tracker-logo" style="background:#e8952a;font-size:.6rem;font-weight:900;">OEC</div>
									<div>
										<strong><?php esc_html_e( 'Credits API Key', 'oec-theme' ); ?></strong>
										<p><?php esc_html_e( 'Habilita la página de créditos por descuentos y el otorgamiento de créditos de bienvenida.', 'oec-theme' ); ?></p>
									</div>
									<div class="oec-tracker-status">
										<?php oec_tracker_badge( $opts['credits_api_key'] ); ?>
									</div>
								</div>
								<div class="oec-tracker-row__field">
									<label for="oec-credits-api-key"><?php esc_html_e( 'API Key', 'oec-theme' ); ?></label>
									<input type="password"
									       id="oec-credits-api-key"
									       name="<?php echo esc_attr( OEC_OPTION ); ?>[credits_api_key]"
									       value="<?php echo esc_attr( $opts['credits_api_key'] ); ?>"
									       class="regular-text oec-tracker-input"
									       placeholder="c869e9c4..."
									       data-tracker="credits_api_key"
									       autocomplete="new-password"
									       spellcheck="false">
									<p class="description">
										<?php esc_html_e( 'Clave de la API de api.onlineeducation.center/contable. Nunca se expone al navegador.', 'oec-theme' ); ?>
										<?php if ( $opts['credits_api_key'] ) : ?>
										<br><span style="color:#1e7e34;font-weight:600;">✓ <?php esc_html_e( 'Clave configurada — sistema de créditos activo.', 'oec-theme' ); ?></span>
										<?php endif; ?>
									</p>
								</div>
							</div>
						</div>
					</div>

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

				<?php elseif ( $tab === 'actualizaciones' ) : ?>
				<!-- ================================================
				     TAB: ACTUALIZACIONES — diagnóstico y control
				     ================================================ -->
				<?php
				// Leer datos para el diagnóstico
				$theme_data   = get_file_data( get_template_directory() . '/style.css', [ 'github_uri' => 'GitHub Theme URI' ] );
				$repo         = $theme_data['github_uri'] ?? '';
				$cache_key    = $repo ? ( 'oec_updater_' . md5( $repo ) ) : '';
				$cached       = $cache_key ? get_transient( $cache_key ) : false;
				$installed_v  = wp_get_theme()->get( 'Version' );
				$theme_slug   = get_template();

				// Llamada en vivo a la API de GitHub (sin caché)
				$gh_latest   = null;
				$gh_error    = '';
				if ( $repo ) {
					$gh_response = wp_remote_get(
						"https://api.github.com/repos/{$repo}/releases/latest",
						[ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ], 'timeout' => 8 ]
					);
					$gh_code = wp_remote_retrieve_response_code( $gh_response );
					if ( is_wp_error( $gh_response ) ) {
						$gh_error = $gh_response->get_error_message();
					} elseif ( $gh_code === 200 ) {
						$gh_latest = json_decode( wp_remote_retrieve_body( $gh_response ), true );
					} elseif ( $gh_code === 404 ) {
						$gh_error = __( 'Repositorio no encontrado o sin releases publicados. ¿El repo es público?', 'oec-theme' );
					} else {
						$gh_error = sprintf( __( 'GitHub respondió con código HTTP %d.', 'oec-theme' ), $gh_code );
					}
				}

				$latest_tag = $gh_latest ? ltrim( $gh_latest['tag_name'] ?? '', 'vV' ) : null;
				$has_update = $latest_tag && version_compare( $latest_tag, $installed_v, '>' );

				if ( isset( $_GET['checked'] ) ) :
				?>
				<div class="notice notice-success is-dismissible" style="margin-bottom:1.5rem;">
					<p><?php esc_html_e( '✅ Caché borrado. WordPress verificó actualizaciones ahora mismo.', 'oec-theme' ); ?></p>
				</div>
				<?php endif; ?>

				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Estado de la actualización', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Diagnóstico en tiempo real — esta consulta a GitHub se hace ahora mismo, sin caché.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<!-- Tabla de diagnóstico -->
						<table style="width:100%;border-collapse:collapse;font-size:.9rem;">
							<?php
							$rows = [
								[
									'label'  => __( 'Carpeta del tema (slug)', 'oec-theme' ),
									'value'  => '<code>' . esc_html( $theme_slug ) . '</code>',
									'note'   => __( 'Debe coincidir con el nombre del ZIP del release.', 'oec-theme' ),
								],
								[
									'label'  => __( 'Versión instalada', 'oec-theme' ),
									'value'  => '<strong>' . esc_html( $installed_v ) . '</strong>',
									'note'   => __( 'Leída desde style.css del servidor.', 'oec-theme' ),
								],
								[
									'label'  => __( 'Repositorio GitHub', 'oec-theme' ),
									'value'  => $repo
										? '<a href="https://github.com/' . esc_attr( $repo ) . '" target="_blank">' . esc_html( $repo ) . '</a>'
										: '<span style="color:#c00;">⚠ No configurado en style.css</span>',
									'note'   => __( 'Header "GitHub Theme URI" en style.css.', 'oec-theme' ),
								],
								[
									'label'  => __( 'Último release en GitHub', 'oec-theme' ),
									'value'  => $gh_error
										? '<span style="color:#c00;">✗ ' . esc_html( $gh_error ) . '</span>'
										: ( $gh_latest
											? '<strong>' . esc_html( $gh_latest['tag_name'] ) . '</strong> — <a href="' . esc_url( $gh_latest['html_url'] ) . '" target="_blank">' . esc_html__( 'ver release', 'oec-theme' ) . '</a>'
											: '<span style="color:#888;">' . esc_html__( 'Sin datos', 'oec-theme' ) . '</span>'
										),
									'note'   => $gh_latest ? esc_html( $gh_latest['published_at'] ?? '' ) : '',
								],
								[
									'label'  => __( 'Caché del updater', 'oec-theme' ),
									'value'  => $cached !== false
										? '<span style="color:#888;">' . esc_html__( 'Activo (expira en ', 'oec-theme' ) . ( $cache_key ? human_time_diff( time() + (int) get_option( '_transient_timeout_' . $cache_key, 0 ), time() ) : '?' ) . ')</span>'
										: '<span style="color:#1e7e34;">' . esc_html__( 'Sin caché — se consultará en la próxima verificación', 'oec-theme' ) . '</span>',
									'note'   => '',
								],
								[
									'label'  => __( '¿Hay actualización disponible?', 'oec-theme' ),
									'value'  => $has_update
										? '<span style="color:#1e7e34;font-weight:700;">✅ Sí — ' . esc_html( $latest_tag ) . ' > ' . esc_html( $installed_v ) . '</span>'
										: ( $latest_tag
											? '<span style="color:#888;">✓ ' . esc_html__( 'Ya estás en la última versión.', 'oec-theme' ) . '</span>'
											: '<span style="color:#888;">—</span>'
										),
									'note'   => '',
								],
							];
							foreach ( $rows as $row ) :
							?>
							<tr style="border-bottom:1px solid #f0f0f1;">
								<td style="padding:.875rem 1rem .875rem 0;font-weight:600;color:#1e2d3d;width:35%;vertical-align:top;">
									<?php echo esc_html( $row['label'] ); ?>
								</td>
								<td style="padding:.875rem 0;vertical-align:top;">
									<?php echo $row['value']; // phpcs:ignore ?>
									<?php if ( $row['note'] ) : ?>
									<br><span style="font-size:.75rem;color:#888;"><?php echo esc_html( $row['note'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</table>

						<!-- Botón forzar verificación -->
						<div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid #f0f0f1;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
							<a href="<?php echo esc_url( add_query_arg( [
								'page'       => 'oec-settings',
								'tab'        => 'actualizaciones',
								'oec_action' => 'force_update_check',
								'_nonce'     => wp_create_nonce( 'oec_force_update_check' ),
							], admin_url( 'themes.php' ) ) ); ?>"
							   class="button button-primary button-large">
								🔄 <?php esc_html_e( 'Borrar caché y forzar verificación', 'oec-theme' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-large">
								<?php esc_html_e( 'Ir a WordPress → Actualizaciones', 'oec-theme' ); ?>
							</a>
							<p style="font-size:.8125rem;color:#646970;margin:0;">
								<?php esc_html_e( 'Después de forzar, revisá Apariencia → Temas.', 'oec-theme' ); ?>
							</p>
						</div>

					</div>
				</div>

				<?php if ( $has_update ) : ?>
				<!-- Aviso de actualización disponible -->
				<div class="oec-card" style="margin-top:1rem;border-color:#1e7e34;">
					<div class="oec-card__body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.25rem 2rem;">
						<div>
							<strong style="color:#1e7e34;font-size:1rem;">
								✅ <?php printf( esc_html__( 'Versión %s disponible', 'oec-theme' ), esc_html( $latest_tag ) ); ?>
							</strong>
							<p style="font-size:.875rem;color:#646970;margin:.25rem 0 0;">
								<?php esc_html_e( 'WordPress debería mostrártela en Apariencia → Temas. Si no aparece, usá el botón de arriba.', 'oec-theme' ); ?>
							</p>
						</div>
						<a href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Ir a Temas', 'oec-theme' ); ?>
						</a>
					</div>
				</div>
				<?php endif; ?>

				<?php elseif ( $tab === 'asistente' ) : ?>
				<!-- ================================================
				     TAB: ASISTENTE IA
				     ================================================ -->
				<?php
				$ai_meta = class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_meta() : [];
				$ai_status = $ai_meta['status'] ?? 'never';
				$ai_count  = (int) ( $ai_meta['count'] ?? 0 );
				$ai_date   = $ai_meta['finished_at'] ?? '';
				$ai_errors = $ai_meta['errors'] ?? [];
				?>

				<!-- API Key -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'API Key de Anthropic', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Necesaria para que el asistente pueda responder. Nunca se expone al navegador.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">
						<div class="oec-tracker-row">
							<div class="oec-tracker-row__head">
								<div class="oec-tracker-logo" style="background:#d97757;font-size:.6rem;font-weight:900;">ANT</div>
								<div>
									<strong><?php esc_html_e( 'Anthropic Claude', 'oec-theme' ); ?></strong>
									<p><?php esc_html_e( 'El asistente usa Claude Haiku para generar recomendaciones personalizadas.', 'oec-theme' ); ?></p>
								</div>
								<div class="oec-tracker-status">
									<?php oec_tracker_badge( $opts['oec_anthropic_key'] ); ?>
								</div>
							</div>
							<div class="oec-tracker-row__field">
								<label for="oec-anthropic-key"><?php esc_html_e( 'API Key', 'oec-theme' ); ?></label>
								<input type="password"
								       id="oec-anthropic-key"
								       name="<?php echo esc_attr( OEC_OPTION ); ?>[oec_anthropic_key]"
								       value="<?php echo esc_attr( $opts['oec_anthropic_key'] ); ?>"
								       class="regular-text oec-tracker-input"
								       placeholder="sk-ant-..."
								       autocomplete="new-password"
								       spellcheck="false">
								<p class="description">
									<?php esc_html_e( 'Obtenela en console.anthropic.com → API Keys.', 'oec-theme' ); ?>
									<?php if ( $opts['oec_anthropic_key'] ) : ?>
									<br><span style="color:#1e7e34;font-weight:600;">✓ <?php esc_html_e( 'Configurada — el asistente está activo.', 'oec-theme' ); ?></span>
									<?php endif; ?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<!-- Catalog status -->
				<div class="oec-card" style="margin-top:1rem;">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Catálogo de formaciones', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'El asistente lee un catálogo local que se actualiza diariamente a las 3:00 AM.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:1.5rem;">
							<tr style="border-bottom:1px solid #f0f0f1;">
								<td style="padding:.75rem 1rem .75rem 0;font-weight:600;width:35%;"><?php esc_html_e( 'Estado', 'oec-theme' ); ?></td>
								<td style="padding:.75rem 0;">
									<?php
									$status_map = [
										'ok'      => '<span style="color:#1e7e34;font-weight:600;">✅ ' . esc_html__( 'Sincronizado', 'oec-theme' ) . '</span>',
										'running' => '<span style="color:#856404;font-weight:600;">⏳ ' . esc_html__( 'Sincronizando…', 'oec-theme' ) . '</span>',
										'error'   => '<span style="color:#c00;font-weight:600;">✗ ' . esc_html__( 'Error en última sincronización', 'oec-theme' ) . '</span>',
										'never'   => '<span style="color:#888;">' . esc_html__( 'Nunca sincronizado', 'oec-theme' ) . '</span>',
									];
									echo $status_map[ $ai_status ] ?? esc_html( $ai_status ); // phpcs:ignore
									?>
								</td>
							</tr>
							<tr style="border-bottom:1px solid #f0f0f1;">
								<td style="padding:.75rem 1rem .75rem 0;font-weight:600;"><?php esc_html_e( 'Formaciones almacenadas', 'oec-theme' ); ?></td>
								<td style="padding:.75rem 0;"><?php echo $ai_count ? '<strong>' . esc_html( number_format( $ai_count ) ) . '</strong> ' . esc_html__( 'abiertas', 'oec-theme' ) : '<span style="color:#888;">—</span>'; // phpcs:ignore ?>
									<?php if ( isset( $ai_meta['closed_count'] ) ) : ?>
									· <strong><?php echo esc_html( number_format( (int) $ai_meta['closed_count'] ) ); ?></strong> <?php esc_html_e( 'cerradas', 'oec-theme' ); ?>
									<?php endif; ?>
								</td>
							</tr>
							<tr style="border-bottom:1px solid #f0f0f1;">
								<td style="padding:.75rem 1rem .75rem 0;font-weight:600;"><?php esc_html_e( 'Última sincronización', 'oec-theme' ); ?></td>
								<td style="padding:.75rem 0;"><?php echo $ai_date ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $ai_date ) ) ) : '<span style="color:#888;">—</span>'; // phpcs:ignore ?></td>
							</tr>
							<tr>
								<td style="padding:.75rem 1rem .75rem 0;font-weight:600;"><?php esc_html_e( 'Próxima sincronización', 'oec-theme' ); ?></td>
								<td style="padding:.75rem 0;">
									<?php
									$next = wp_next_scheduled( 'oec_ai_catalog_sync' );
									echo $next
										? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
										: '<span style="color:#888;">' . esc_html__( 'No programada', 'oec-theme' ) . '</span>';
									// phpcs:ignore
									?>
								</td>
							</tr>
						</table>

						<?php if ( ! empty( $ai_errors ) ) : ?>
						<div style="background:#fff8f0;border:1px solid #ffd285;border-radius:6px;padding:1rem;margin-bottom:1.5rem;">
							<strong style="color:#856404;"><?php printf( esc_html__( 'Errores en última sincronización (%d):', 'oec-theme' ), count( $ai_errors ) ); ?></strong>
							<ul style="margin:.5rem 0 0;padding-left:1.25rem;font-size:.8125rem;color:#856404;">
								<?php foreach ( array_slice( $ai_errors, 0, 5 ) as $err ) : ?>
								<li><?php echo esc_html( $err ); ?></li>
								<?php endforeach; ?>
								<?php if ( count( $ai_errors ) > 5 ) : ?>
								<li><?php printf( esc_html__( '… y %d más', 'oec-theme' ), count( $ai_errors ) - 5 ); ?></li>
								<?php endif; ?>
							</ul>
						</div>
						<?php endif; ?>

						<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
							<button type="button" id="oec-ai-sync-now" class="button button-primary button-large"
							        data-nonce="<?php echo esc_attr( wp_create_nonce( 'oec_ai_sync' ) ); ?>">
								🔄 <?php esc_html_e( 'Sincronizar ahora', 'oec-theme' ); ?>
							</button>
							<span id="oec-ai-sync-msg" style="font-size:.875rem;color:#646970;"></span>
						</div>

					</div>
				</div>

				<?php elseif ( $tab === 'contenido' ) : ?>
				<!-- ================================================
				     TAB: CONTENIDO — topbar, redes, footer
				     ================================================ -->

				<!-- Encabezado superior -->
				<div class="oec-card">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Barra superior del encabezado', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Aparece encima del header en todas las páginas. El enlace a Online Education Center es fijo; el Campus Virtual es configurable.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">
						<div class="oec-tracker-row__field">
							<label for="oec-campus-url"><strong><?php esc_html_e( 'URL del Campus Virtual', 'oec-theme' ); ?></strong></label>
							<input type="url" id="oec-campus-url"
							       name="<?php echo esc_attr( OEC_OPTION ); ?>[campus_virtual_url]"
							       value="<?php echo esc_attr( $opts['campus_virtual_url'] ); ?>"
							       class="regular-text"
							       placeholder="https://campus.example.com">
							<p class="description"><?php esc_html_e( 'Si está vacío, el botón "Campus Virtual" no se muestra en la barra.', 'oec-theme' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Redes sociales -->
				<div class="oec-card" style="margin-top:1rem;">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Redes sociales', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Se muestran como íconos en el pie de página. Dejá en blanco las que no uses.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">
						<?php
						$social_fields = [
							'social_linkedin'  => [ 'label' => 'LinkedIn',    'icon' => '🔗', 'placeholder' => 'https://linkedin.com/company/...' ],
							'social_instagram' => [ 'label' => 'Instagram',   'icon' => '📷', 'placeholder' => 'https://instagram.com/...' ],
							'social_facebook'  => [ 'label' => 'Facebook',    'icon' => '👥', 'placeholder' => 'https://facebook.com/...' ],
							'social_youtube'   => [ 'label' => 'YouTube',     'icon' => '▶', 'placeholder' => 'https://youtube.com/@...' ],
							'social_x'         => [ 'label' => 'X (Twitter)', 'icon' => '𝕏', 'placeholder' => 'https://x.com/...' ],
						];
						foreach ( $social_fields as $skey => $sdata ) : ?>
						<div class="oec-tracker-row__field" style="margin-bottom:1rem;">
							<label for="oec-<?php echo esc_attr( $skey ); ?>">
								<strong><?php echo esc_html( $sdata['icon'] . ' ' . $sdata['label'] ); ?></strong>
							</label>
							<input type="url" id="oec-<?php echo esc_attr( $skey ); ?>"
							       name="<?php echo esc_attr( OEC_OPTION . '[' . $skey . ']' ); ?>"
							       value="<?php echo esc_attr( $opts[ $skey ] ?? '' ); ?>"
							       class="regular-text"
							       placeholder="<?php echo esc_attr( $sdata['placeholder'] ); ?>">
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Footer -->
				<div class="oec-card" style="margin-top:1rem;">
					<div class="oec-card__header">
						<h2><?php esc_html_e( 'Pie de página', 'oec-theme' ); ?></h2>
						<p><?php esc_html_e( 'Texto bajo el logo y columnas de enlaces.', 'oec-theme' ); ?></p>
					</div>
					<div class="oec-card__body">

						<!-- Description -->
						<div class="oec-tracker-row__field" style="margin-bottom:1.5rem;">
							<label for="oec-footer-desc"><strong><?php esc_html_e( 'Descripción bajo el logo', 'oec-theme' ); ?></strong></label>
							<input type="text" id="oec-footer-desc"
							       name="<?php echo esc_attr( OEC_OPTION ); ?>[footer_desc]"
							       value="<?php echo esc_attr( $opts['footer_desc'] ); ?>"
							       class="large-text">
						</div>

						<hr class="oec-divider" style="margin-bottom:1.5rem;">

						<!-- CTA organizaciones -->
						<div class="oec-tracker-row__field" style="margin-bottom:1.5rem;">
							<label for="oec-footer-cta-url"><strong><?php esc_html_e( 'Invitación a organizaciones educativas (URL)', 'oec-theme' ); ?></strong></label>
							<input type="url" id="oec-footer-cta-url"
							       name="<?php echo esc_attr( OEC_OPTION ); ?>[footer_cta_url]"
							       value="<?php echo esc_attr( $opts['footer_cta_url'] ); ?>"
							       class="regular-text"
							       placeholder="https://onlineeducation.center/es/organizaciones">
							<p class="description"><?php esc_html_e( 'URL del botón "Más información" en el banner de organizaciones. Si está vacío, el banner no se muestra.', 'oec-theme' ); ?></p>
						</div>

						<hr class="oec-divider" style="margin-bottom:1.5rem;">

						<!-- Landings -->
						<div class="oec-tracker-row__field" style="margin-bottom:1.5rem;">
							<label for="oec-footer-landings"><strong><?php esc_html_e( 'Temáticas', 'oec-theme' ); ?></strong></label>
							<textarea id="oec-footer-landings"
							          name="<?php echo esc_attr( OEC_OPTION ); ?>[footer_landings]"
							          rows="6" class="large-text"
							          placeholder="Nutrición Deportiva|/nutricion-deportiva|bi-heart-pulse&#10;Entrenamiento de la Fuerza|/entrenamiento-fuerza|bi-activity&#10;Fisiología del Ejercicio|/fisiologia|bi-lungs"><?php echo esc_textarea( $opts['footer_landings'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Un enlace por línea: Nombre|URL o Nombre|URL|bi-icono (ícono Bootstrap opcional). Ejemplo: Nutrición|/nutricion|bi-heart-pulse', 'oec-theme' ); ?></p>
						</div>

						<hr class="oec-divider" style="margin-bottom:1.5rem;">

						<!-- Legal -->
						<div class="oec-tracker-row__field">
							<label for="oec-footer-legal"><strong><?php esc_html_e( 'Información', 'oec-theme' ); ?></strong></label>
							<textarea id="oec-footer-legal"
							          name="<?php echo esc_attr( OEC_OPTION ); ?>[footer_legal]"
							          rows="5" class="large-text"
							          placeholder="Quiénes somos|/quienes-somos&#10;Privacidad|/privacidad&#10;Términos de uso|/terminos"><?php echo esc_textarea( $opts['footer_legal'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Un enlace por línea, en formato: Nombre del enlace|URL. Si está vacío, no se muestra la columna.', 'oec-theme' ); ?></p>
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
	.oec-card + .oec-card { margin-top: 1.5rem; }

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

	/* ---- Favicon ---- */
	.oec-favicon-row { display: flex; gap: 2rem; align-items: flex-start; }
	.oec-favicon-preview {
		width: 96px; height: 96px; flex-shrink: 0;
		border: 1px solid #dcdcde; border-radius: 12px; background: #f6f7f7;
		display: flex; align-items: center; justify-content: center; overflow: hidden;
	}
	.oec-favicon-preview img { width: 64px; height: 64px; object-fit: contain; }
	.oec-favicon-placeholder { font-size: .75rem; color: #8c8f94; text-align: center; }
	.oec-btn-row { display: flex; gap: .5rem; flex-wrap: wrap; }

	/* ---- Colores ---- */
	.oec-color-fields { display: flex; flex-direction: column; gap: 0; margin-bottom: .5rem; }
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
	.oec-color-row__picker .wp-picker-container { display: flex; align-items: center; gap: .5rem; }
	.oec-derived { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.25rem; margin-top: 1rem; padding: 1rem 1.25rem; background: #f6f7f7; border-radius: 8px; font-size: .8125rem; color: #50575e; }
	.oec-derived__title { font-weight: 600; margin-right: .25rem; }
	.oec-derived__item { display: inline-flex; align-items: center; gap: .375rem; }
	.oec-derived__dot { width: 18px; height: 18px; border-radius: 50%; border: 1px solid rgba(0,0,0,.12); }
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
		.oec-favicon-row { flex-direction: column; }
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

	$changed = false;
	foreach ( oec_color_keys() as $k ) {
		if ( strtolower( $opts[ $k ] ) !== $defaults[ $k ] ) {
			$changed = true;
			break;
		}
	}
	if ( ! $changed ) {
		return;
	}

	$p    = oec_palette( $opts );
	$vars = [
		'--color-primary'      => $p['primary'],
		'--color-dark'         => $p['dark'],
		'--color-dark-2'       => $p['dark_2'],
		'--color-accent'       => $p['accent'],
		'--color-accent-hover' => $p['accent_hover'],
		'--color-light'        => $p['light'],
		'--color-border'       => $p['border'],
		'--color-text'         => $p['text'],
		'--color-muted'        => $p['muted'],
		'--color-badge-articulo'    => $p['badge_articulo'],
		'--color-badge-articulo-bg' => $p['badge_articulo_bg'],
		'--color-badge-blog'        => $p['badge_blog'],
		'--color-badge-blog-bg'     => $p['badge_blog_bg'],
		// Canales R,G,B de los colores que se usan con alpha (rgba(var(--x-rgb),N)).
		'--color-primary-rgb' => oec_hex_to_rgb_list( $p['primary'] ),
		'--color-dark-rgb'    => oec_hex_to_rgb_list( $p['dark'] ),
		'--color-accent-rgb'  => oec_hex_to_rgb_list( $p['accent'] ),
		'--color-text-rgb'    => oec_hex_to_rgb_list( $p['text'] ),
	];

	// Selector más específico que ":root" a secas (que usa style.css) para
	// que este override gane el cascade SIN depender de en qué orden se
	// impriman los <style>/<link> — antes salía a prioridad 5 (antes que
	// el <link> de style.css) y el ":root" del archivo, al imprimirse
	// después, terminaba pisando estos valores silenciosamente.
	$css = 'html:root{';
	foreach ( $vars as $prop => $val ) {
		$css .= esc_attr( $prop ) . ':' . esc_attr( $val ) . ';';
	}
	$css .= '}';

	echo "\n<style id=\"oec-dynamic-colors\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_head', 'oec_output_dynamic_css', 100 );

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

/* ============================================================
   HELPER: parse "Label|URL|icon" textarea → array
   Defined here (not footer.php) so it's available globally
   before footer.php is ever included.
   Formato: Etiqueta|URL   o   Etiqueta|URL|bi-nombre-icono
   ============================================================ */
if ( ! function_exists( 'oec_parse_link_list' ) ) {
	function oec_parse_link_list( string $raw ): array {
		$links = [];
		if ( ! $raw ) {
			return $links;
		}
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( ! $line ) {
				continue;
			}
			$parts = explode( '|', $line, 3 );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$label = trim( $parts[0] );
			// "/https://…" (barra de más al cargarlo) también cuenta como externo.
			$href  = preg_replace( '#^/+(?=https?://)#i', '', trim( $parts[1] ) );
			$icon  = isset( $parts[2] ) ? trim( $parts[2] ) : '';
			if ( $label && $href ) {
				$links[] = [
					'label'    => $label,
					'href'     => $href,
					'external' => str_starts_with( $href, 'http' ),
					'icon'     => $icon,
				];
			}
		}
		return $links;
	}
}
