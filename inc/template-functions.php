<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SITIO DE CONFIGURACIÓN (multisitio)
   Newsletter y créditos se configuran en un solo sitio de la red
   (por ahora /es/) y el resto lee de ahí: API keys, listas, log, etc.
   Fuera de multisitio todo es el sitio actual.
   ============================================================ */
if ( ! defined( 'OEC_CONFIG_SITE_PATH' ) ) {
	define( 'OEC_CONFIG_SITE_PATH', '/es/' );
}

function oec_config_blog_id(): int {
	static $id = null;
	if ( null !== $id ) {
		return $id;
	}
	if ( ! is_multisite() ) {
		return $id = get_current_blog_id();
	}
	$ids = get_sites( [ 'path' => OEC_CONFIG_SITE_PATH, 'number' => 1, 'fields' => 'ids' ] );
	return $id = $ids ? (int) $ids[0] : (int) get_main_site_id();
}

function oec_is_config_site(): bool {
	return get_current_blog_id() === oec_config_blog_id();
}

function oec_config_get_option( string $name, $default = false ) {
	return oec_is_config_site() ? get_option( $name, $default ) : get_blog_option( oec_config_blog_id(), $name, $default );
}

function oec_config_update_option( string $name, $value, bool $autoload = true ): void {
	if ( oec_is_config_site() ) {
		update_option( $name, $value, $autoload );
		return;
	}
	switch_to_blog( oec_config_blog_id() );
	update_option( $name, $value, $autoload );
	restore_current_blog();
}

/** Opciones del tema (Ajustes OEC) del sitio de configuración. */
function oec_config_theme_options(): array {
	if ( oec_is_config_site() ) {
		return oec_get_options();
	}
	return wp_parse_args( (array) get_blog_option( oec_config_blog_id(), OEC_OPTION, [] ), oec_get_defaults() );
}

/** URL del sitio de configuración (páginas del newsletter, links de los emails). */
function oec_config_url( string $path = '/' ): string {
	return get_home_url( oec_config_blog_id(), $path );
}

/* ============================================================
   IMÁGENES DE LA API OEC — proxy de redimensionado
   ============================================================ */

/**
 * Reescribe una URL de static1.onlineeducation.center/uploads/... para
 * pasar por el proxy de redimensionado imgrsize.oe-img.center, pidiendo
 * un ancho y calidad concretos (evita bajar la imagen original a tamaño
 * completo cuando solo se va a mostrar un logo chico o una miniatura).
 * Si la URL no matchea ese dominio (ya viene de otro lado, está vacía,
 * etc.) se devuelve tal cual, sin tocar.
 */
function oec_cdn_resize( string $url, int $width, int $quality = 90 ): string {
	if ( ! $url ) {
		return $url;
	}
	$resized = preg_replace(
		'#^https://static1\.onlineeducation\.center/uploads/#',
		'https://imgrsize.oe-img.center/',
		$url
	);
	if ( $resized === $url ) {
		return $url;
	}
	return add_query_arg( [ 'w' => $width, 'q' => $quality ], $resized );
}

/* ============================================================
   ARTÍCULOS — helpers de URL y metadata
   ============================================================ */

/**
 * Genera la URL de /articulos con los parámetros de filtro dados,
 * mezclando los filtros activos actuales con los cambios recibidos.
 * Pasar un valor vacío ('') limpia ese filtro.
 */
function oec_articulos_url( array $params ): string {
	$page = get_page_by_path( 'articulos' );
	$base = $page ? get_permalink( $page ) : home_url( '/articulos/' );

	$active = [];
	foreach ( [ 'tipo', 'tematica', 'anio', 'autor' ] as $k ) {
		$val = sanitize_key( wp_unslash( $_GET[ $k ] ?? '' ) );
		if ( $val !== '' ) {
			$active[ $k ] = $val;
		}
	}
	// Búsqueda: texto libre, no un slug (sanitize_key le sacaría los espacios).
	$q = oec_articulos_q();
	if ( $q !== '' ) {
		$active['q'] = $q;
	}

	$merged = array_merge( $active, $params );
	$merged = array_filter( $merged, fn( $v ) => $v !== '' );

	return $merged ? add_query_arg( $merged, $base ) : $base;
}

/**
 * Breadcrumb visible + su BreadcrumbList (JSON-LD), para que el recorrido
 * que ve el usuario y el que lee Google no se desincronicen.
 *
 * $items: [ [ etiqueta, url ], …, [ etiqueta ] ] — el último (página actual)
 * va sin link. "Inicio" se agrega solo.
 * $schema = false cuando la página ya imprime su BreadcrumbList en el <head>
 * (docentes, especiales; formaciones lo hace el plugin).
 */
function oec_breadcrumb( array $items, bool $schema = true ): void {
	array_unshift( $items, [ __( 'Inicio', 'oec-theme' ), home_url( '/' ) ] );
	$last = count( $items ) - 1;

	echo '<nav class="breadcrumb" aria-label="' . esc_attr__( 'Ruta de navegación', 'oec-theme' ) . '">';
	foreach ( $items as $i => $crumb ) {
		if ( $i ) {
			echo '<span class="sep" aria-hidden="true">/</span>';
		}
		$label = esc_html( $crumb[0] );
		echo ( $i < $last && ! empty( $crumb[1] ) )
			? '<a href="' . esc_url( $crumb[1] ) . '">' . $label . '</a>'
			: '<span aria-current="page">' . $label . '</span>';
	}
	echo "</nav>\n";

	if ( ! $schema ) {
		return;
	}
	$list = [];
	foreach ( $items as $i => $crumb ) {
		$el = [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags( $crumb[0] ) ];
		if ( ! empty( $crumb[1] ) ) {
			$el['item'] = $crumb[1];
		}
		$list[] = $el;
	}
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

/**
 * URL de la página N de /articulos con los filtros activos, en el formato que
 * WordPress considera canónico (/articulos/page/N): con ?paged=N WP responde
 * un 301 a esta misma URL.
 */
function oec_articulos_page_url( int $page, array $params = [] ): string {
	$url   = oec_articulos_url( $params );
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	$base  = strtok( $url, '?' );
	if ( $page > 1 ) {
		$base = user_trailingslashit( trailingslashit( $base ) . 'page/' . $page, 'paged' );
	}
	return '' !== $query ? $base . '?' . $query : $base;
}

/** Texto buscado en /articulos (?q=), limpio y acotado. */
function oec_articulos_q(): string {
	return mb_substr( trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) ), 0, 80 );
}

/**
 * Devuelve el tiempo estimado de lectura en minutos (200 palabras/min).
 */
function oec_reading_time( int $post_id = 0 ): int {
	$content = get_post_field( 'post_content', $post_id ?: get_the_ID() );
	$words   = str_word_count( wp_strip_all_tags( $content ) );
	return max( 1, (int) ceil( $words / 200 ) );
}

/**
 * Devuelve el WP_Term de TIPO (articulos/blogs/vigia) del post actual.
 */
function oec_get_tipo_term(): ?WP_Term {
	$tipo_slugs = [ 'articulos', 'blogs', 'vigia' ];
	foreach ( get_the_category() as $cat ) {
		if ( in_array( $cat->slug, $tipo_slugs, true ) ) {
			return $cat;
		}
	}
	return null;
}

/**
 * Etiquetas legibles para las categorías TIPO (usado en breadcrumbs, badges, etc.).
 */
function oec_tipo_labels(): array {
	return [ 'articulos' => 'Artículo', 'blogs' => 'Blog', 'vigia' => 'VigIA' ];
}

/**
 * Etiquetas en plural para las categorías TIPO (usado en el título del listado).
 */
function oec_tipo_labels_plural(): array {
	return [ 'articulos' => 'Artículos', 'blogs' => 'Blogs', 'vigia' => 'VigIA' ];
}

/**
 * Lee un atributo tipo "valor:Etiqueta,valor:Etiqueta" del shortcode
 * [oec-list] directamente del post_content, sin depender de que el
 * plugin exponga esa estructura ya parseada. Se usa para construir
 * títulos dinámicos (p. ej. en page-formaciones.php) a partir de la
 * MISMA configuración que ya arma el sidebar de filtros — así un
 * cambio de MKT en el listado de temáticas/tipos no desactualiza el
 * título en un segundo lugar.
 */
function oec_parse_oeclist_shortcode_attr( string $content, string $attr_name ): array {
	if ( ! preg_match( '/\[oec-list\b([^\]]*)\]/s', $content, $m ) ) {
		return [];
	}
	$atts = shortcode_parse_atts( $m[1] );
	$raw  = is_array( $atts ) ? (string) ( $atts[ $attr_name ] ?? '' ) : '';
	if ( '' === $raw ) {
		return [];
	}
	$out = [];
	foreach ( explode( ',', $raw ) as $pair ) {
		$pair = trim( $pair );
		if ( '' === $pair ) {
			continue;
		}
		$parts = explode( ':', $pair, 2 );
		$value = trim( $parts[0] );
		$label = isset( $parts[1] ) ? trim( $parts[1] ) : $value;
		if ( '' === $value ) {
			continue;
		}
		$out[ $value ] = $label;
	}
	return $out;
}

/**
 * Pluraliza una palabra en español de forma aproximada: agrega "s" si
 * termina en vocal, "es" si termina en consonante — con excepciones
 * conocidas para los "Tipos de formación" ya configurados en el sitio
 * ("Webinar" se usa en plural con "s" simple en el uso habitual, no con
 * la regla estricta "-es"; "Master" pluraliza como "Másteres", la forma
 * castellanizada con tilde).
 */
function oec_pluralize_es( string $singular ): string {
	$exceptions = [
		'Webinar' => 'Webinars',
		'Master'  => 'Másteres',
	];
	if ( isset( $exceptions[ $singular ] ) ) {
		return $exceptions[ $singular ];
	}
	$last = mb_strtolower( mb_substr( $singular, -1 ) );
	return in_array( $last, [ 'a', 'e', 'i', 'o', 'u' ], true ) ? $singular . 's' : $singular . 'es';
}

/**
 * Emite las meta tags og:image / twitter:image con el logo del tema.
 * Enganchar a wp_head en las plantillas de listado (articulos, category, etc.).
 */
function oec_output_og_image_meta(): void {
	$opts = function_exists( 'oec_get_options' ) ? oec_get_options() : [];
	$img  = $opts['logo_url'] ?? '';
	if ( $img ) {
		echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n";
	}
}

/* ============================================================
   CONTACT FORM HANDLER
   ============================================================ */
function oec_handle_contact_form(): void {
	if ( ! isset( $_POST['oec_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oec_nonce'] ) ), 'oec_contact_nonce' ) ) {
		wp_die( esc_html__( 'Acción no permitida.', 'oec-theme' ) );
	}

	// Trampa para bots (campo invisible en el formulario): se descarta en silencio.
	if ( ! empty( $_POST['cf_website'] ) ) {
		wp_safe_redirect( add_query_arg( 'contact', 'success', wp_get_referer() ) . '#contacto' );
		exit;
	}

	$name     = sanitize_text_field( wp_unslash( $_POST['cf_name']     ?? '' ) );
	$email    = sanitize_email( wp_unslash( $_POST['cf_email']    ?? '' ) );
	$phone    = sanitize_text_field( wp_unslash( $_POST['cf_phone']    ?? '' ) );
	$interest = sanitize_text_field( wp_unslash( $_POST['cf_interest'] ?? '' ) );
	$message  = sanitize_textarea_field( wp_unslash( $_POST['cf_message']  ?? '' ) );

	if ( ! $name || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'contact', 'error', wp_get_referer() ) . '#contacto' );
		exit;
	}

	$to      = get_theme_mod( 'oec_contact_email', get_option( 'admin_email' ) );
	$subject = sprintf( '[%s] Nueva consulta de %s', get_bloginfo( 'name' ), $name );
	$body    = sprintf(
		"Nombre: %s\nEmail: %s\nTeléfono: %s\nInterés: %s\n\nMensaje:\n%s",
		$name, $email, $phone, $interest, $message
	);
	$headers = [ 'Content-Type: text/plain; charset=UTF-8', "Reply-To: {$name} <{$email}>" ];

	wp_mail( $to, $subject, $body, $headers );

	wp_safe_redirect( add_query_arg( 'contact', 'success', wp_get_referer() ) . '#contacto' );
	exit;
}
add_action( 'admin_post_oec_contact_form',        'oec_handle_contact_form' );
add_action( 'admin_post_nopriv_oec_contact_form', 'oec_handle_contact_form' );

/**
 * Página "Quiénes somos" (page-quienes-somos.php): se crea sola en el sitio
 * de contenido, vacía — el contenido vive en la plantilla.
 */
function oec_create_quienes_somos_page(): void {
	if ( get_option( 'oec_quienes_somos_page_v1' ) || ! oec_is_config_site() ) {
		return;
	}
	if ( ! get_page_by_path( 'quienes-somos' ) ) {
		wp_insert_post( [
			'post_title'   => 'Quiénes somos',
			'post_name'    => 'quienes-somos',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'post_author'  => get_current_user_id() ?: 1,
		] );
	}
	update_option( 'oec_quienes_somos_page_v1', 1 );
}
add_action( 'after_switch_theme', 'oec_create_quienes_somos_page' );
add_action( 'admin_init', 'oec_create_quienes_somos_page' );
