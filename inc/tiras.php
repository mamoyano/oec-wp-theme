<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   TIRAS DE FORMACIONES DESDE EL CATÁLOGO — [oec-tira]

   Reemplaza a [oec-list layout="scroll"] del plugin en el home y las
   landings: mismas cards (mismo markup y clases, así usan el CSS/JS del
   plugin tal cual: flechas, cuentas regresivas), pero armadas en el
   servidor desde el catálogo que se sincroniza cada noche (OEC_AI_Catalog),
   sin ninguna llamada a la API de OEC al cargar la página.

   [oec-tira orden="cierre" limit="10" more-url="/formaciones?oec_order=enrollment_end"]
   [oec-tira orden="inicio" desde="next-month" hasta="month-after-next" limit="50" almanaque="si"]
   [oec-tira orden="publicacion" limit="40"]
   [oec-tira tematica="nutricion-deportiva,deportes" title="Nutrición aplicada a tu deporte" limit="10"]

   - orden: cierre (enrollment_end ↑) | inicio (start ↑) | publicacion
     (publish_start ↓) | relevancia (por defecto: el orden sugerido por la
     API, que es el orden en que el sync guarda el catálogo).
   - La cuenta regresiva de cierre solo va en la tira orden="cierre".
   - tematica: uno o varios slugs separados por coma (se exigen TODOS).
   - desde / hasta: sobre la fecha de inicio; "this-month", "next-month",
     "month-after-next" o una fecha AAAA-MM-DD ("hasta" = último día).
   - almanaque="si": hoja de almanaque con la fecha de inicio en la foto
     (la agenda [oec-agenda] la usa).
   - seo-description / seo-image: metas de la página (ver oec_tira_seo_meta).

   Contrapartida: los datos son del último sync (una vez por día).
   ============================================================ */

/**
 * Todas las formaciones abiertas del catálogo, con solo los campos que
 * usan las cards. Cacheado hasta el próximo sync.
 */
function oec_tira_catalogo(): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return [];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_tira_cat_' . md5( (string) ( $meta['finished_at'] ?? '' ) );
	$hit  = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$rows = [];
	foreach ( OEC_AI_Catalog::get_index() as $e ) {
		$f = OEC_AI_Catalog::get_formation( (string) $e['id'] );
		if ( ! $f ) {
			continue;
		}
		$rows[] = [
			'id'             => $f['id'],
			'slug'           => $f['slug'] ?? basename( (string) wp_parse_url( $f['url'] ?? '', PHP_URL_PATH ) ),
			'title'          => $f['title'] ?? '',
			'type'           => $f['type'] ?? '',
			'image'          => $f['image'] ?? '',
			'org'            => $f['org'] ?? '',
			'description'    => $f['description'] ?? '',
			'modality'       => $f['modality'] ?? '',
			'synchronicity'  => $f['synchronicity'] ?? '',
			'edition_number' => (int) ( $f['edition_number'] ?? 0 ),
			'start'          => $f['start'] ?? '',
			'enrollment_end' => $f['enrollment_end'] ?? '',
			'publish_start'  => $f['published_at'] ?? ( $f['publish_start'] ?? '' ),
			'relevance'      => (int) ( $f['relevance'] ?? 0 ),
			'reviews'        => $f['reviews_summary'] ?? null,
			'tags'           => $f['tags'] ?? [],
			'community'      => $f['community'] ?? '',
			'badges'         => array_keys( array_filter( [
				'Docentes Destacados'   => ! empty( $f['great_lecturers'] ),
				'Temática Destacada'    => ! empty( $f['great_topic'] ),
				'Organizador Líder'     => ! empty( $f['great_organizer'] ),
				'Certificación Oficial' => ! empty( $f['great_certification'] ),
				'Mejor Precio'          => ! empty( $f['great_price'] ),
			] ) ),
		];
	}
	set_transient( $key, $rows, 12 * HOUR_IN_SECONDS );
	return $rows;
}

/**
 * Mes de la agenda: hasta el día 19 inclusive, el mes en curso; desde el 20,
 * el siguiente (ya casi no queda nada por arrancar en el mes que termina).
 * Lo usan el encabezado [oec-agenda] y su tira (oec-tira almanaque="si"),
 * así siempre muestran el mismo mes. Devuelve el día 1 de ese mes.
 */
function oec_agenda_mes(): DateTimeImmutable {
	$hoy = new DateTimeImmutable( 'now', wp_timezone() );
	$ini = $hoy->modify( 'first day of this month' )->setTime( 0, 0 );
	return (int) $hoy->format( 'j' ) >= 20 ? $ini->modify( '+1 month' ) : $ini;
}

/**
 * "Ver todo" de la agenda: el filtro de /formaciones por mes espera la clave
 * del mes ("october-2026", ver oec_formaciones_meses()), no un número.
 */
function oec_agenda_more_url( string $url ): string {
	if ( '' === $url || false === strpos( $url, 'oec_month=' ) ) {
		return $url;
	}
	return (string) preg_replace( '/oec_month=[^&]*/', 'oec_month=' . strtolower( oec_agenda_mes()->format( 'F-Y' ) ), $url );
}

/** "next-month" / "this-month" / "month-after-next" / AAAA-MM-DD → AAAA-MM-DD. */
function oec_tira_fecha( string $valor, bool $fin ): string {
	$meses = [ 'this-month' => 0, 'next-month' => 1, 'month-after-next' => 2 ];
	if ( isset( $meses[ $valor ] ) ) {
		$d = new DateTimeImmutable( 'first day of this month', wp_timezone() );
		$d = $d->modify( '+' . $meses[ $valor ] . ' month' );
		return $d->format( $fin ? 'Y-m-t' : 'Y-m-01' );
	}
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) ? $valor : '';
}

/** "2026-09-24" → "24 de Septiembre de 2026" (mismo formato que format_date del plugin). */
function oec_tira_format_date( string $ymd ): string {
	$meses = [ 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' ];
	$t     = strtotime( $ymd );
	return $t ? sprintf( '%s de %s de %s', gmdate( 'd', $t ), $meses[ (int) gmdate( 'n', $t ) - 1 ], gmdate( 'Y', $t ) ) : '';
}

/** Capitalize de Twig: primera letra mayúscula, el resto minúsculas. */
function oec_tira_capitalize( string $s ): string {
	$s = mb_strtolower( $s );
	return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
}

/**
 * Card de una formación — mismo markup y clases que las del plugin, así
 * usan su CSS/JS tal cual. La usan las tiras y el listado de /formaciones.
 * $r: fila de oec_tira_catalogo() u OEC_AI_Catalog::get_listing(). Si es
 * de otra comunidad, lleva su logo y abre su sitio (inc/communities.php).
 * $o: almanaque (hoja con la fecha de inicio en la foto), countdown
 * (cuenta regresiva del cierre), org (organización bajo el título),
 * eager (foto sin lazy y con prioridad: las primeras de /formaciones,
 * que son el LCP).
 */
function oec_formacion_card( array $r, array $o = [] ): string {
	static $hoy = null;
	$hoy      = $hoy ?? current_time( 'Y-m-d' );
	$meses_ab = [ 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC' ];

	$is_async = in_array( $r['synchronicity'], [ 'ASYNC', 'ASYNC-F' ], true );
	$closed   = ( $r['enrollment_end'] && $r['enrollment_end'] < $hoy ) || ( isset( $r['open'] ) && ! $r['open'] );
	if ( $is_async && ! $closed ) {
		[ $fecha_clase, $fecha_icono, $fecha_tipo ] = [ 'fecha-async', 'bi-infinity', 'async' ];
	} elseif ( $closed ) {
		[ $fecha_clase, $fecha_icono, $fecha_tipo ] = [ 'fecha-cerrada', 'bi-lock', 'cerrada' ];
	} elseif ( $r['start'] > $hoy ) {
		[ $fecha_clase, $fecha_icono, $fecha_tipo ] = [ 'fecha-inicio', 'bi-calendar3', 'inicio' ];
	} else {
		[ $fecha_clase, $fecha_icono, $fecha_tipo ] = [ 'fecha-cierre', 'bi-calendar-check', 'cierre' ];
	}
	$modality = false !== strpos( $r['modality'], 'BLEND' ) ? 'Mixta' : oec_tira_capitalize( $r['modality'] );
	$sync     = [ 'SYNC' => 'Sincrónica', 'MIXED' => 'Mixta', 'ASYNC-F' => 'Asincrónica c/foros' ][ $r['synchronicity'] ] ?? 'Asincrónica';
	$rev_n    = (int) ( $r['reviews']['count'] ?? 0 );
	// imgrsize elige AVIF/WebP según el Accept del navegador; q=80 pesa ~40 % menos que 89 sin diferencia visible a este tamaño.
	$img      = $r['image'] ? 'https://imgrsize.oe-img.center/campus/capacitacion/imagen/' . basename( (string) wp_parse_url( $r['image'], PHP_URL_PATH ) ) . '?w=640&q=80' : '';
	$title    = mb_strlen( $r['title'] ) > 100 ? mb_substr( $r['title'], 0, 97 ) . '...' : $r['title'];
	// La card muestra ~8 renglones y el resto lo tapa el degradé: la descripción
	// completa (a veces 3000+ caracteres) solo engordaba el HTML (~250 KB en el home).
	$desc     = mb_strlen( $r['description'] ) > 420 ? rtrim( mb_substr( $r['description'], 0, 420 ) ) . '…' : $r['description'];
	$org      = ! empty( $o['org'] ) ? (string) ( $r['org'] ?? '' ) : '';
	// Cierre al terminar el día, en la hora del sitio (mismo criterio que el plugin).
	$cierre_iso = $r['enrollment_end'] ? ( new DateTime( $r['enrollment_end'] . ' 23:59:59', wp_timezone() ) )->format( 'c' ) : '';
	// De otra comunidad (inc/communities.php): logo en la foto y link a su sitio en otra pestaña.
	$external   = oec_formation_is_external( $r );

	ob_start();
	?>
			<a href="<?php echo esc_url( oec_formation_url( $r ) ); ?>"<?php echo oec_formation_link_attrs( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?> class="oec-card<?php echo $closed ? ' enrollment-closed' : ''; ?><?php echo $external ? ' oec-card--external' : ''; ?>" data-id="<?php echo esc_attr( $r['id'] ); ?>" data-start="<?php echo esc_attr( $is_async ? '' : $r['start'] ); ?>">
				<div class="oec-image-wrapper">
					<?php echo oec_community_badge( $r, 'oec-community--card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					<?php if ( ! empty( $o['almanaque'] ) && ! $is_async && $r['start'] ) : ?>
					<div class="oec-date-stamp" aria-hidden="true"><span class="oec-date-stamp__mon"><?php echo esc_html( $meses_ab[ (int) substr( $r['start'], 5, 2 ) - 1 ] ); ?></span><span class="oec-date-stamp__day"><?php echo (int) substr( $r['start'], 8, 2 ); ?></span></div>
					<?php endif; ?>
					<div class="oec-badges-container">
						<?php foreach ( $r['badges'] as $b ) : ?><div class="oec-badge-item"><?php echo esc_html( $b ); ?></div><?php endforeach; ?>
					</div>
					<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" class="oec-image" alt="<?php echo esc_attr( $r['title'] ); ?>" width="640" height="350" <?php echo empty( $o['eager'] ) ? 'loading="lazy" decoding="async"' : 'fetchpriority="high"'; ?>><?php endif; ?>
				</div>
				<div class="oec-body">
					<div class="oec-title"><?php echo esc_html( $title ); ?></div>
					<?php if ( $org ) : ?>
					<div class="oec-organization"><i class="bi bi-building"></i> <?php echo esc_html( mb_strlen( $org ) > 35 ? mb_substr( $org, 0, 32 ) . '...' : $org ); ?></div>
					<?php endif; ?>
					<div class="oec-description-container"><div class="oec-description"><?php echo esc_html( $desc ); ?></div></div>
					<div class="oec-grid-reviews">
						<?php if ( $rev_n > 0 ) : ?>
						<div class="oec-stars-container"><div class="oec-stars" style="width: <?php echo esc_attr( round( (float) $r['reviews']['average'] * 100 / 5, 2 ) ); ?>%;"></div></div>
						<span class="oec-stars-average"><?php echo esc_html( number_format( (float) $r['reviews']['average'], 1, ',', '.' ) ); ?></span>
						<span class="oec-cant-reviewers<?php echo $rev_n > 30 ? ' destacado-review' : ''; ?>">(<?php echo esc_html( $rev_n ); ?> opiniones)</span>
						<?php else : ?>
						<div style="color:#064f1c;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Nueva Formación', 'oec-theme' ); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<div class="oec-footer">
					<div class="oec-footer-top">
						<div class="oec-tags-container">
							<?php echo esc_html( oec_tira_capitalize( $r['type'] ) ); ?>
							<?php if ( $r['edition_number'] ) : ?><span class="separador">•</span><span class="<?php echo $r['edition_number'] > 5 ? 'destacado-edicion' : ''; ?>"><?php echo (int) $r['edition_number']; ?>ª ed</span><?php endif; ?>
							<span class="separador">•</span> <?php echo esc_html( $modality ); ?>
							<span class="separador">•</span> <?php echo esc_html( $sync ); ?>
						</div>
					</div>
					<div class="oec-footer-bottom">
						<div class="oec-start-date <?php echo esc_attr( $fecha_clase ); ?>">
							<i class="bi <?php echo esc_attr( $fecha_icono ); ?>"></i>
							<?php if ( 'async' === $fecha_tipo ) : ?>
							<span class="fecha-texto"><?php esc_html_e( 'Comience cuando quiera', 'oec-theme' ); ?></span>
							<?php elseif ( 'cerrada' === $fecha_tipo ) : ?>
							<span class="fecha-texto"><?php esc_html_e( 'Inscripciones cerradas', 'oec-theme' ); ?></span>
							<?php elseif ( 'inicio' === $fecha_tipo ) : ?>
							<div class="fecha-contenedor"><span class="fecha-valor"><?php echo esc_html( oec_tira_format_date( $r['start'] ) ); ?></span><span class="fecha-etiqueta"><?php esc_html_e( 'Fecha de inicio', 'oec-theme' ); ?></span></div>
							<?php else : ?>
							<div class="fecha-contenedor"><span class="fecha-valor"><?php echo esc_html( oec_tira_format_date( $r['enrollment_end'] ) ); ?></span><span class="fecha-etiqueta"><?php esc_html_e( 'Cierre de inscripciones', 'oec-theme' ); ?></span></div>
							<?php endif; ?>
						</div>
						<div class="oec-btn-fake"><?php echo esc_html( sprintf( __( 'Ver %s', 'oec-theme' ), $r['type'] ) ); ?><?php echo $external ? ' <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>' : ''; ?></div>
					</div>
					<?php if ( ! empty( $o['countdown'] ) && ! $is_async && ! $closed && $cierre_iso ) : ?>
					<div class="oec-countdown" date="<?php echo esc_attr( $cierre_iso ); ?>">
						<div class="oec-cd-unit oec-cd-days"><span class="oec-cd-num" data-unit="d">00</span><span class="oec-cd-label">Días</span></div>
						<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="h">00</span><span class="oec-cd-label">Hs</span></div>
						<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="m">00</span><span class="oec-cd-label">Min</span></div>
						<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="s">00</span><span class="oec-cd-label">Seg</span></div>
					</div>
					<?php endif; ?>
				</div>
			</a>
	<?php
	return ob_get_clean();
}

/**
 * ¿Es una página "aplicación"? — trae un shortcode del plugin
 * (oec_post_uses_shortcodes) o [oec-tira]. El home y las landings dejaron de
 * tener [oec-list], así que el plugin ya no las reconoce: sin esto perdían
 * todo lo que el plugin les daba (page.php sin hero genérico y a ancho
 * completo, sin wpautop, sin editor visual).
 */
function oec_is_app_content( string $content ): bool {
	return ( function_exists( 'oec_post_uses_shortcodes' ) && oec_post_uses_shortcodes( $content ) )
		|| has_shortcode( $content, 'oec-tira' );
}

/**
 * Sin wpautop en esas páginas: llevan cientos de líneas de CSS/HTML crudo y
 * wpautop mete <p> adentro del <style> (rompe el :root con las variables de
 * la temática). Prioridad 8, antes de wpautop (10) — igual que el plugin.
 */
add_filter( 'the_content', function ( $content ) {
	if ( has_shortcode( $content, 'oec-tira' ) ) {
		remove_filter( 'the_content', 'wpautop' );
	}
	return $content;
}, 8 );

/**
 * Ni editor de bloques ni pestaña "Visual" para esas páginas: los dos
 * reescriben el HTML crudo al guardar. Solo la caja de texto plano.
 */
add_filter( 'use_block_editor_for_post', function ( $use, $post ) {
	return ( $post && has_shortcode( $post->post_content, 'oec-tira' ) ) ? false : $use;
}, 10, 2 );
add_filter( 'user_can_richedit', function ( $default ) {
	global $post;
	return ( is_admin() && $post && has_shortcode( $post->post_content, 'oec-tira' ) ) ? false : $default;
} );

add_shortcode( 'oec-tira', 'oec_render_tira_shortcode' );
function oec_render_tira_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'orden'           => 'relevancia',
		'tematica'        => '',
		'desde'           => '',
		'hasta'           => '',
		'limit'           => 10,
		'title'           => '',
		'more-url'        => '',
		'almanaque'       => '',
		'seo-description' => '',
		'seo-image'       => '',
	], $atts, 'oec-tira' );

	$hoy   = current_time( 'Y-m-d' );
	$temas = array_filter( array_map( 'sanitize_title', explode( ',', $atts['tematica'] ) ) );
	$desde = $atts['desde'] ? oec_tira_fecha( $atts['desde'], false ) : '';
	$hasta = $atts['hasta'] ? oec_tira_fecha( $atts['hasta'], true ) : ( $desde && isset( [ 'this-month' => 1, 'next-month' => 1, 'month-after-next' => 1 ][ $atts['desde'] ] ) ? oec_tira_fecha( $atts['desde'], true ) : '' );

	// Tira de la agenda (almanaque): el mes lo decide oec_agenda_mes() — el
	// mismo que muestra el encabezado [oec-agenda] —, más el siguiente para
	// cuando ese mes tiene pocas (main.js oculta el segundo si sobran).
	// Pisa desde/hasta de la página, que todavía dicen "next-month".
	if ( 'si' === $atts['almanaque'] ) {
		$mes                = oec_agenda_mes();
		$desde              = $mes->format( 'Y-m-01' );
		$hasta              = $mes->modify( '+1 month' )->format( 'Y-m-t' );
		$atts['more-url']   = oec_agenda_more_url( $atts['more-url'] );
	}
	$cierre = 'cierre' === $atts['orden'];

	$rows = array_filter( oec_tira_catalogo(), function ( $r ) use ( $hoy, $temas, $desde, $hasta, $cierre ) {
		if ( $r['enrollment_end'] && $r['enrollment_end'] < $hoy ) {
			return false; // inscripción ya cerrada desde el último sync
		}
		if ( $cierre && $r['relevance'] < 1 ) {
			return false; // "Cierran esta semana": solo relevancia 1 y 2
		}
		if ( $temas && array_diff( $temas, $r['tags'] ) ) {
			return false;
		}
		if ( $desde && $r['start'] < $desde ) {
			return false;
		}
		if ( $hasta && $r['start'] > $hasta ) {
			return false;
		}
		return true;
	} );

	$cmp = [
		'cierre'      => fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ),
		'inicio'      => fn( $a, $b ) => strcmp( $a['start'], $b['start'] ),
		'publicacion' => fn( $a, $b ) => strcmp( $b['publish_start'], $a['publish_start'] ),
	][ $atts['orden'] ] ?? null;
	if ( $cmp ) {
		usort( $rows, $cmp );
	}
	$rows = array_slice( array_values( $rows ), 0, max( 1, (int) $atts['limit'] ) );

	$more_url = $atts['more-url'] ? home_url( $atts['more-url'] ) : '';
	$brand    = get_option( 'oec_brand_color', '#a435f0' );

	ob_start();
	?>
<style>:root { --oec-brand: <?php echo esc_html( $brand ); ?>; }</style>
<section class="oec-scroll-section">
	<?php if ( $atts['title'] ) : ?><h2 class="oec-scroll-title"><?php echo esc_html( $atts['title'] ); ?></h2><?php endif; ?>
	<div class="oec-scroll-wrapper">
		<button type="button" class="oec-scroll-arrow oec-scroll-arrow-left" aria-label="<?php esc_attr_e( 'Anterior', 'oec-theme' ); ?>"><i class="bi bi-chevron-left"></i></button>
		<div class="oec-scroll-track">
			<?php if ( ! $rows ) : ?>
			<p class="oec-empty-title"><?php esc_html_e( 'No hay formaciones disponibles en este momento.', 'oec-theme' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<?php echo oec_formacion_card( $r, [ 'almanaque' => 'si' === $atts['almanaque'], 'countdown' => 'cierre' === $atts['orden'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<?php endforeach; ?>
			<?php if ( $more_url ) : ?><a class="oec-scroll-more" href="<?php echo esc_url( $more_url ); ?>" aria-label="<?php esc_attr_e( 'Ver todas', 'oec-theme' ); ?>"><i class="bi bi-arrow-right"></i></a><?php endif; ?>
		</div>
		<button type="button" class="oec-scroll-arrow oec-scroll-arrow-right" aria-label="<?php esc_attr_e( 'Siguiente', 'oec-theme' ); ?>"><i class="bi bi-chevron-right"></i></button>
	</div>
</section>
	<?php
	return ob_get_clean();
}

/* ── CSS/JS de las cards: son los del plugin (oec-formaciones.css/.js).
   El plugin los carga solo si la página usa sus shortcodes; con [oec-tira]
   los carga el tema, con la misma versión por fecha de modificación. ── */
add_action( 'wp_enqueue_scripts', function () {
	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'oec-tira' ) ) {
		return;
	}
	$dir = WP_PLUGIN_DIR . '/oec-wordpress-plugin';
	$url = plugins_url( 'oec-wordpress-plugin' );
	if ( ! is_dir( $dir ) ) {
		return;
	}
	if ( ! wp_style_is( 'oec-formaciones', 'enqueued' ) && file_exists( $dir . '/css/oec-formaciones.css' ) ) {
		wp_enqueue_style( 'oec-formaciones', $url . '/css/oec-formaciones.css', [], (string) filemtime( $dir . '/css/oec-formaciones.css' ) );
	}
	if ( ! wp_script_is( 'oec-formaciones', 'enqueued' ) && file_exists( $dir . '/js/oec-formaciones.js' ) ) {
		wp_enqueue_script( 'oec-formaciones', $url . '/js/oec-formaciones.js', [], (string) filemtime( $dir . '/js/oec-formaciones.js' ), [ 'strategy' => 'defer', 'in_footer' => true ] );
	}
}, 20 );

/* ── SEO de las landings: la primera [oec-tira] con seo-description le da
   a la página su meta description e imagen para compartir (inc/seo.php,
   filtro oec_seo) y acá se emite su BreadcrumbList — lo que antes hacía
   el plugin con [oec-list seo-description]. ── */

/** Atributos de la primera [oec-tira] con seo-description de la página actual, o null. */
function oec_tira_seo_atts(): ?array {
	static $atts = false;
	if ( false !== $atts ) {
		return $atts;
	}
	$atts = null;
	$post = get_queried_object();
	if ( is_admin() || is_front_page() || ! is_singular() || ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'oec-tira' ) ) {
		return $atts;
	}
	if ( preg_match_all( '/' . get_shortcode_regex( [ 'oec-tira' ] ) . '/s', $post->post_content, $m ) ) {
		foreach ( $m[3] as $raw ) {
			$a = shortcode_parse_atts( $raw );
			if ( is_array( $a ) && ! empty( $a['seo-description'] ) ) {
				$atts = $a;
				break;
			}
		}
	}
	return $atts;
}

add_filter( 'oec_seo', function ( $c ) {
	$atts = oec_tira_seo_atts();
	if ( ! $atts || ! is_array( $c ) ) {
		return $c;
	}
	$c['description'] = oec_seo_trim( $atts['seo-description'], 300 );
	if ( ! empty( $atts['seo-image'] ) ) {
		$c['image'] = $atts['seo-image'];
	}
	return $c;
} );

add_action( 'wp_head', 'oec_tira_seo_meta', 5 );
function oec_tira_seo_meta(): void {
	if ( ! oec_tira_seo_atts() ) {
		return;
	}
	$post  = get_queried_object();
	$title = get_the_title( $post );
	$url   = get_permalink( $post );

	// Breadcrumb con la jerarquía real de páginas (Inicio → Especiales → …).
	$items = [ [ '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Inicio', 'oec-theme' ), 'item' => home_url( '/' ) ] ];
	$pos   = 2;
	foreach ( array_reverse( get_post_ancestors( $post ) ) as $anc ) {
		$items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title( $anc ), 'item' => get_permalink( $anc ) ];
	}
	$items[] = [ '@type' => 'ListItem', 'position' => $pos, 'name' => $title, 'item' => $url ];
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}
