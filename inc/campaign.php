<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   CAMPAÑAS — eventos especiales de descuentos (Cyber, Black Friday,
   Back to School…), unas 6 veces por año. Tema OEC → Campañas.

   Una campaña tiene nombre ("Cyber G-SE"), fechas de inicio y fin (hora
   del sitio), unos días previos de "se viene", un grupo de la API
   (wpgroup, p. ej. CYBER2026) y una paleta. Con eso:

   - Landing /especiales/{slug} (page-campana.php): se crea sola al
     guardar. Tres estados: "se viene" (cuenta regresiva al inicio +
     newsletter), "en curso" (formaciones con su %, cuenta regresiva al
     fin) y "terminó".
   - Todo el sitio (menos la landing y las fichas): barra arriba, tono de
     la campaña, aviso chico una vez por visita y marca con el % en cada
     card que participa (assets/js/campaign.js). En la ficha de una que
     participa, solo una marca junto al título: no se saca a nadie de la
     ficha cuando está por comprar.
   - Home: franja con las formaciones de la campaña ([oec-campana]).
   - Chat IA (inc/ai-chat.php) y newsletter semanal (inc/newsletter.php)
     la conocen mientras dura (y el newsletter, desde 7 días antes).

   Formaciones: las abiertas del catálogo (token principal y secundarios)
   que tienen el grupo de la campaña. El descuento es el de pago
   anticipado (early payment) de la API de precios, que el equipo carga
   para la campaña y se aplica solo al inscribirse: no hay códigos. Se
   consulta después de cada sync del catálogo (desde 2 días antes del
   "se viene" hasta el fin), al empezar el "se viene" y la campaña, y con
   el botón "Actualizar descuentos" del admin. Vale como descuento de la
   campaña si vence el día de inicio o después.

   Caché: el HTML está en Cloudflare (inc/cloudflare.php). Por eso los
   cambios de estado los decide el navegador con las fechas exactas
   (window.OEC_CMP, en el <head> de cada página), y al empezar el "se
   viene", la campaña y al terminar se borra todo el sitio de Cloudflare.
   ============================================================ */

const OEC_CMP_OPTION = 'oec_campaign';
const OEC_CMP_DATA   = 'oec_campaign_data';
const OEC_CMP_PRICES = 'https://api.onlineeducation.center/api-oas/v1/trainings/';

/** Días antes del inicio en que el newsletter ya la anuncia ("se viene"). */
const OEC_CMP_NL_DAYS = 7;

function oec_cmp_defaults(): array {
	return [
		'enabled'     => false,
		'name'        => 'Cyber G-SE',
		'slug'        => 'cyber-2026',
		'tag'         => 'CYBER2026',
		'start'       => '2026-11-02 00:00', // hora del sitio
		'end'         => '2026-11-04 23:59',
		'teaser_days' => 4,
		// Azul oscuro con verde muy claro de acento (para Black Friday, negros).
		'dark'        => '#0a1633',
		'dark_2'      => '#15397a',
		'accent'      => '#a8f5c9',
	];
}

function oec_cmp_opts(): array {
	return wp_parse_args( (array) get_option( OEC_CMP_OPTION, [] ), oec_cmp_defaults() );
}

/** Instantes (UTC) de la campaña: inicio del "se viene", inicio y fin (al terminar ese minuto). */
function oec_cmp_times( ?array $o = null ): array {
	$o  = $o ?? oec_cmp_opts();
	$tz = wp_timezone();
	$ts = function ( string $v ) use ( $tz ): int {
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $v, $tz );
		return $d ? $d->getTimestamp() : 0;
	};
	$start = $ts( (string) $o['start'] );
	$end   = $ts( (string) $o['end'] );
	return [
		'teaser' => $start - max( 0, (int) $o['teaser_days'] ) * DAY_IN_SECONDS,
		'start'  => $start,
		'end'    => $end ? $end + 59 : 0,
	];
}

/** off (sin campaña o falta) · teaser ("se viene") · live (en curso) · ended (terminó). */
function oec_cmp_phase( ?int $now = null ): string {
	$o = oec_cmp_opts();
	$t = oec_cmp_times( $o );
	if ( empty( $o['enabled'] ) || ! $t['start'] || $t['end'] <= $t['start'] ) {
		return 'off';
	}
	$now = $now ?? time();
	if ( $now < $t['teaser'] ) {
		return 'off';
	}
	if ( $now < $t['start'] ) {
		return 'teaser';
	}
	return $now <= $t['end'] ? 'live' : 'ended';
}

/**
 * Vista previa para administradores: ?oec_cmp_preview=teaser|live|ended en
 * cualquier página muestra ese estado (sin caché: con sesión iniciada
 * Cloudflare no guarda nada, ver oec_send_cache_headers()).
 */
function oec_cmp_preview(): string {
	$p = sanitize_key( $_GET['oec_cmp_preview'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $p, [ 'teaser', 'live', 'ended' ], true ) && current_user_can( 'manage_options' ) ? $p : '';
}

/** Estado a mostrar en esta página: la vista previa o el real. */
function oec_cmp_view_phase(): string {
	return oec_cmp_preview() ?: oec_cmp_phase();
}

/** ¿Hay que consultar precios? Desde 2 días antes del "se viene" hasta el fin. */
function oec_cmp_window(): bool {
	$o = oec_cmp_opts();
	$t = oec_cmp_times( $o );
	return ! empty( $o['enabled'] ) && $t['start'] && time() >= $t['teaser'] - 2 * DAY_IN_SECONDS && time() <= $t['end'];
}

function oec_cmp_url( ?array $o = null ): string {
	$o = $o ?? oec_cmp_opts();
	return home_url( user_trailingslashit( '/especiales/' . $o['slug'] ) );
}

/** ¿Es una landing de campaña (la actual o una anterior)? */
function oec_is_campaign_page(): bool {
	return is_page() && 'page-campana.php' === get_page_template_slug( get_queried_object_id() );
}

/** ¿Es la landing de la campaña configurada? */
function oec_cmp_is_current_page(): bool {
	if ( ! is_page() ) {
		return false;
	}
	$page = get_page_by_path( 'especiales/' . oec_cmp_opts()['slug'] );
	return $page && (int) $page->ID === get_queried_object_id();
}

/* ── Formaciones y descuentos ─────────────────────────────── */

function oec_cmp_data(): array {
	return wp_parse_args( (array) get_option( OEC_CMP_DATA, [] ), [ 'tag' => '', 'updated' => 0, 'items' => [], 'errors' => 0 ] );
}

/** % de la campaña de una formación: su pago anticipado, si vence el día de inicio o después. */
function oec_cmp_pct_of( ?array $item, ?array $o = null ): int {
	$o     = $o ?? oec_cmp_opts();
	$start = substr( (string) $o['start'], 0, 10 );
	return $item && $item['pct'] > 0 && $item['exp'] >= $start ? (int) $item['pct'] : 0;
}

/**
 * Las formaciones de la campaña: abiertas, del token principal o de los
 * secundarios, con el grupo de la campaña y la inscripción abierta al
 * empezar. Cada fila de oec_tira_catalogo() + cmp_pct (0 = sin descuento
 * cargado todavía). En curso, si ya se consultaron los precios, solo las
 * que tienen descuento. Ordenadas por % (mayor primero) y relevancia.
 */
function oec_cmp_formaciones( string $phase = '' ): array {
	$o   = oec_cmp_opts();
	$tag = strtolower( trim( (string) $o['tag'] ) );
	if ( '' === $tag || ! function_exists( 'oec_tira_catalogo' ) ) {
		return [];
	}
	$desde = max( current_time( 'Y-m-d' ), substr( (string) $o['start'], 0, 10 ) );
	$items = oec_cmp_data()['items'];
	$rows  = [];
	foreach ( oec_tira_catalogo( true ) as $r ) {
		if ( ! in_array( $tag, array_map( 'strtolower', $r['tags'] ), true ) || ( $r['enrollment_end'] && $r['enrollment_end'] < $desde ) ) {
			continue;
		}
		$r['cmp_pct'] = oec_cmp_pct_of( $items[ $r['id'] ] ?? null, $o );
		$rows[]       = $r;
	}
	if ( 'live' === $phase && array_filter( array_column( $rows, 'cmp_pct' ) ) ) {
		$rows = array_values( array_filter( $rows, fn( $r ) => $r['cmp_pct'] > 0 ) );
	}
	usort( $rows, fn( $a, $b ) => [ $b['cmp_pct'], $b['relevance'], $a['title'] ] <=> [ $a['cmp_pct'], $a['relevance'], $b['title'] ] );
	return $rows;
}

/** Mayor descuento de la campaña (0 si todavía no hay). */
function oec_cmp_max_pct( array $rows ): int {
	return $rows ? (int) max( array_column( $rows, 'cmp_pct' ) ) : 0;
}

/**
 * Consulta el precio de cada formación de la campaña (API de precios, la
 * misma que usa la ficha): de a 5 en paralelo y 1 s entre tandas, para no
 * cargar la API (ver inc/ai-catalog.php). Si una falla, queda su dato
 * anterior. Si algo cambió, borra el sitio de Cloudflare (las marcas de
 * todas las páginas salen de acá).
 */
function oec_cmp_refresh(): array {
	$o     = oec_cmp_opts();
	$ids   = array_column( oec_cmp_formaciones(), 'id' );
	$old   = oec_cmp_data();
	$items = [];
	$errs  = 0;
	$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
	foreach ( array_chunk( $ids, 5 ) as $i => $lote ) {
		if ( $i ) {
			sleep( 1 );
		}
		$reqs = [];
		foreach ( $lote as $id ) {
			$reqs[ $id ] = [ 'url' => OEC_CMP_PRICES . rawurlencode( $id ) . '/prices', 'type' => 'GET', 'headers' => [ 'Accept' => 'application/json' ] ];
		}
		try {
			$res = $class::request_multiple( $reqs, [ 'timeout' => 15 ] );
		} catch ( \Throwable $e ) {
			$res = [];
		}
		foreach ( $lote as $id ) {
			$r = $res[ $id ] ?? null;
			$d = ( is_object( $r ) && isset( $r->status_code ) && 200 === (int) $r->status_code ) ? json_decode( (string) $r->body, true ) : null;
			if ( ! is_array( $d ) || ! isset( $d['total'] ) ) {
				$errs++;
				if ( isset( $old['items'][ $id ] ) ) {
					$items[ $id ] = $old['items'][ $id ];
				}
				continue;
			}
			$items[ $id ] = [
				'pct'   => (int) ( $d['discountPercent'] ?? 0 ),
				'exp'   => substr( (string) ( $d['expiration'] ?? '' ), 0, 10 ),
				'total' => round( (float) $d['total'], 2 ),
				'final' => round( (float) ( $d['toPriceDiscount'] ?? $d['total'] ), 2 ),
				'cur'   => (string) ( $d['currency'] ?? '' ),
			];
		}
	}
	$data = [ 'tag' => (string) $o['tag'], 'updated' => time(), 'items' => $items, 'errors' => $errs ];
	update_option( OEC_CMP_DATA, $data, false );
	$pcts = fn( array $its ) => array_map( fn( $it ) => oec_cmp_pct_of( $it, $o ), $its );
	if ( $pcts( $items ) != $pcts( (array) $old['items'] ) && function_exists( 'oec_cf_queue' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons -- sin importar el orden
		oec_cf_queue( [], true );
	}
	return $data;
}

/* ── Programación ─────────────────────────────────────────── */

// Después de cada sync del catálogo (la sincronización diaria trae los
// grupos): en un minuto, para no alargar la tanda del sync.
add_action( 'oec_ai_catalog_synced', function (): void {
	if ( oec_cmp_window() && ! wp_next_scheduled( 'oec_cmp_refresh' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'oec_cmp_refresh' );
	}
} );
add_action( 'oec_cmp_refresh', 'oec_cmp_refresh' );

/** Un evento por cambio de estado (se viene, empieza, termina), un minuto después. */
function oec_cmp_schedule(): void {
	wp_unschedule_hook( 'oec_cmp_moment' );
	$o = oec_cmp_opts();
	if ( empty( $o['enabled'] ) ) {
		return;
	}
	foreach ( oec_cmp_times( $o ) as $k => $t ) {
		if ( $t && $t + MINUTE_IN_SECONDS > time() ) {
			wp_schedule_single_event( $t + MINUTE_IN_SECONDS, 'oec_cmp_moment', [ $k ] );
		}
	}
}

// Al cambiar de estado: precios al día (al empezar) y todo el sitio de
// nuevo desde el servidor, con el estado nuevo (y precalentado).
add_action( 'oec_cmp_moment', function ( string $k = '' ): void {
	if ( 'end' !== $k ) {
		oec_cmp_refresh();
	}
	if ( function_exists( 'oec_cf_queue' ) ) {
		oec_cf_queue( [], true );
	}
} );

/* ── Landing: se crea sola (hija de /especiales) ─────────── */

function oec_cmp_ensure_page( array $o ): void {
	$parent = get_page_by_path( 'especiales' );
	if ( ! $parent || '' === $o['slug'] ) {
		return;
	}
	$page = get_page_by_path( 'especiales/' . $o['slug'] );
	if ( $page ) {
		if ( 'page-campana.php' !== get_page_template_slug( $page ) ) {
			update_post_meta( $page->ID, '_wp_page_template', 'page-campana.php' );
		}
		return;
	}
	$id = wp_insert_post( [
		'post_title'   => $o['name'],
		'post_name'    => $o['slug'],
		'post_parent'  => $parent->ID,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_author'  => get_current_user_id() ?: 1,
	] );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_wp_page_template', 'page-campana.php' );
	}
}

/* ── Textos ───────────────────────────────────────────────── */

/** "2, 3 y 4 de noviembre" (hasta 4 días) o "del 2 al 9 de noviembre". */
function oec_cmp_dias_texto( ?array $o = null ): string {
	$o  = $o ?? oec_cmp_opts();
	$t  = oec_cmp_times( $o );
	$tz = wp_timezone();
	$a  = ( new DateTimeImmutable( '@' . $t['start'] ) )->setTimezone( $tz )->setTime( 0, 0 );
	$b  = ( new DateTimeImmutable( '@' . $t['end'] ) )->setTimezone( $tz )->setTime( 0, 0 );
	$n  = (int) $a->diff( $b )->days + 1;
	if ( $n <= 4 && $a->format( 'm' ) === $b->format( 'm' ) ) {
		$dias = [];
		for ( $d = $a; $d <= $b; $d = $d->modify( '+1 day' ) ) {
			$dias[] = $d->format( 'j' );
		}
		$last = array_pop( $dias );
		return ( $dias ? implode( ', ', $dias ) . ' y ' : '' ) . $last . ' de ' . wp_date( 'F', $t['end'] );
	}
	return $a->format( 'm' ) === $b->format( 'm' )
		? sprintf( 'del %s al %s', $a->format( 'j' ), wp_date( 'j \d\e F', $t['end'] ) )
		: sprintf( 'del %s al %s', wp_date( 'j \d\e F', $t['start'] ), wp_date( 'j \d\e F', $t['end'] ) );
}

/** "el miércoles 4 de noviembre a las 23:59" */
function oec_cmp_fin_texto( ?array $o = null ): string {
	$t = oec_cmp_times( $o );
	return wp_date( 'l j \d\e F \a \l\a\s H:i', $t['end'] );
}

/** Duración: "72 horas" (hasta 4 días) o "7 días". */
function oec_cmp_duracion_texto( ?array $o = null ): string {
	$t = oec_cmp_times( $o );
	$h = (int) round( ( $t['end'] - $t['start'] ) / HOUR_IN_SECONDS );
	return $h <= 96 ? sprintf( '%d horas', $h ) : sprintf( '%d días', (int) round( $h / 24 ) );
}

/** El mismo tono que $hex, saturado y con luminosidad $l (0–1): un acento que se lee sobre blanco. */
function oec_cmp_strong( string $hex, float $l = .38 ): string {
	[ $r, $g, $b ] = array_map( fn( $c ) => hexdec( $c ) / 255, str_split( ltrim( $hex, '#' ), 2 ) );
	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );
	$d   = $max - $min;
	$h   = 0;
	if ( $d > 0 ) {
		$h = $max === $r ? fmod( ( $g - $b ) / $d, 6 ) : ( $max === $g ? ( $b - $r ) / $d + 2 : ( $r - $g ) / $d + 4 );
	}
	$h = fmod( $h * 60 + 360, 360 );
	$s = .65;
	$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
	$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
	$m = $l - $c / 2;
	$rgb = [ [ $c, $x, 0 ], [ $x, $c, 0 ], [ 0, $c, $x ], [ 0, $x, $c ], [ $x, 0, $c ], [ $c, 0, $x ] ][ (int) floor( $h / 60 ) % 6 ];
	return vsprintf( '#%02x%02x%02x', array_map( fn( $v ) => (int) round( ( $v + $m ) * 255 ), $rgb ) );
}

/* ── Front: datos para campaign.js, en el <head> de cada página ── */

/**
 * Datos de la campaña para el navegador, o null si no hay nada que
 * mostrar (sin campaña, antes del "se viene" o ya terminada). Solo lo
 * público: nombre, link, fechas y el % de cada formación (id => %; en el
 * "se viene", 0: el % se conoce al empezar).
 */
function oec_cmp_front_data(): ?array {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$preview = oec_cmp_preview();
	$phase   = $preview ?: oec_cmp_phase();
	// Terminada, la landing lo dice sola; el resto del sitio vuelve a la normalidad.
	if ( is_admin() || is_feed() || ! in_array( $phase, [ 'teaser', 'live' ], true ) ) {
		return $cache = null;
	}
	$o    = oec_cmp_opts();
	$t    = oec_cmp_times( $o );
	$rows = oec_cmp_formaciones( $phase );
	$ids  = [];
	foreach ( $rows as $r ) {
		$ids[ $r['id'] ] = 'teaser' === $phase ? 0 : $r['cmp_pct'];
	}
	return $cache = [
		'name'    => (string) $o['name'],
		'url'     => oec_cmp_url( $o ),
		'teaser'  => $t['teaser'] * 1000,
		'start'   => $t['start'] * 1000,
		'end'     => $t['end'] * 1000,
		'days'    => oec_cmp_dias_texto( $o ),
		'max'     => 'live' === $phase ? oec_cmp_max_pct( $rows ) : 0, // antes de empezar, el % no se anticipa
		'n'       => count( $rows ),
		'ids'     => (object) $ids,
		'page'    => oec_is_campaign_page() ? 'landing' : ( is_page( 'formacion' ) ? 'ficha' : ( is_front_page() ? 'home' : '' ) ),
		'preview' => $preview,
		'key'     => sanitize_key( $o['slug'] ),
	];
}

// Lo antes posible en el <head>: el estado y la clase del <html> (tono de
// la campaña) tienen que estar antes de que se pinte la página.
add_action( 'wp_head', function (): void {
	$d = oec_cmp_front_data();
	if ( ! $d ) {
		return;
	}
	$js = 'window.OEC_CMP=' . wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
		. '(function(c){var n=Date.now(),p=c.preview||(n<c.teaser?"":n<c.start?"teaser":n<=c.end?"live":"");c.phase=p;'
		. 'if(p==="teaser"||p==="live"){document.documentElement.classList.add("oec-cmp","oec-cmp--"+p);}})(window.OEC_CMP);';
	echo "<script id=\"oec-cmp-data\">{$js}</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON
}, 2 );

// Colores de la campaña. Después de los de Ajustes OEC (prioridad 100):
// en curso, el sitio entero toma el azul de la campaña en los fondos
// oscuros (hero, pie) y su verde como acento.
add_action( 'wp_head', function (): void {
	if ( ! oec_cmp_front_data() && ! oec_is_campaign_page() ) {
		return;
	}
	$o   = oec_cmp_opts();
	$css = sprintf(
		':root{--cmp-dark:%1$s;--cmp-dark-2:%2$s;--cmp-accent:%3$s;--cmp-accent-rgb:%4$s;--cmp-dark-rgb:%5$s}'
		. 'html.oec-cmp--live:root{--color-dark:%1$s;--color-dark-2:%2$s;--color-dark-rgb:%5$s;--color-accent:%6$s;--color-accent-hover:%7$s;--color-accent-rgb:%8$s}',
		esc_attr( $o['dark'] ),
		esc_attr( $o['dark_2'] ),
		esc_attr( $o['accent'] ),
		esc_attr( oec_hex_to_rgb_list( $o['accent'] ) ),
		esc_attr( oec_hex_to_rgb_list( $o['dark'] ) ),
		// Sobre fondo blanco el verde muy claro no se lee: como acento del
		// sitio va el mismo tono, más intenso y oscuro.
		esc_attr( oec_cmp_strong( $o['accent'] ) ),
		esc_attr( oec_cmp_strong( $o['accent'], .32 ) ),
		esc_attr( oec_hex_to_rgb_list( oec_cmp_strong( $o['accent'] ) ) )
	);
	echo "<style id=\"oec-cmp-colors\">{$css}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- colores saneados
}, 101 );

add_action( 'wp_enqueue_scripts', function (): void {
	if ( ! oec_cmp_front_data() && ! oec_is_campaign_page() ) {
		return;
	}
	wp_enqueue_script( 'oec-campaign', OEC_THEME_URI . '/assets/js/campaign.js', [], oec_asset_version( 'assets/js/campaign.js' ), [ 'strategy' => 'defer', 'in_footer' => true ] );
} );

// Landing: la plantilla también por si alguien le cambia el meta; <title>
// con el gancho (el de la página es solo el nombre).
add_filter( 'document_title_parts', function ( array $parts ): array {
	if ( oec_cmp_is_current_page() ) {
		$o              = oec_cmp_opts();
		$max            = 'live' === oec_cmp_view_phase() ? oec_cmp_max_pct( oec_cmp_formaciones( 'live' ) ) : 0;
		$parts['title'] = 'ended' === oec_cmp_view_phase()
			? sprintf( '%s: la promo terminó', $o['name'] )
			: ( $max
				? sprintf( '%s: hasta %d%% OFF en formaciones, %s', $o['name'], $max, oec_cmp_dias_texto( $o ) )
				: sprintf( '%s: descuentos en formaciones, %s', $o['name'], oec_cmp_dias_texto( $o ) ) );
	}
	return $parts;
} );

/* ── [oec-campana]: franja del home ───────────────────────── */

add_shortcode( 'oec-campana', 'oec_cmp_render_home' );
function oec_cmp_render_home(): string {
	$phase = oec_cmp_view_phase();
	if ( ! in_array( $phase, [ 'teaser', 'live' ], true ) ) {
		return '';
	}
	$rows = oec_cmp_formaciones( $phase );
	if ( ! $rows ) {
		return '';
	}
	$o   = oec_cmp_opts();
	$t   = oec_cmp_times( $o );
	$max = oec_cmp_max_pct( $rows );
	$url = oec_cmp_url( $o );
	ob_start();
	?>
	<section class="oec-cmp-home" data-oec-cmp-phase="<?php echo esc_attr( $phase ); ?>">
		<div class="oec-cmp-home__wrap">
			<div class="oec-cmp-home__head">
				<div>
					<p class="oec-cmp-eyebrow"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> <?php echo esc_html( 'live' === $phase ? sprintf( 'Solo %s · %s', oec_cmp_duracion_texto( $o ), oec_cmp_dias_texto( $o ) ) : sprintf( 'Se viene · %s', oec_cmp_dias_texto( $o ) ) ); ?></p>
					<h2 class="oec-cmp-home__title"><?php echo oec_cmp_wordmark( $o['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></h2>
					<p class="oec-cmp-home__lead">
						<?php
						if ( 'live' === $phase ) {
							echo esc_html( $max ? sprintf( 'Hasta %d%% OFF en %d formaciones. El descuento se aplica solo al inscribirte.', $max, count( $rows ) ) : sprintf( '%d formaciones con descuento. Se aplica solo al inscribirte.', count( $rows ) ) );
						} else {
							echo esc_html( sprintf( '%d formaciones con descuentos fuertes, solo por %s. Elegí la tuya desde ahora.', count( $rows ), oec_cmp_duracion_texto( $o ) ) );
						}
						?>
					</p>
				</div>
				<div class="oec-cmp-home__side">
					<?php echo oec_cmp_countdown( 'live' === $phase ? $t['end'] : $t['start'], 'live' === $phase ? 'Termina en' : 'Empieza en', 'oec-cmp-cd--sm' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					<a class="oec-cmp-btn" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( 'live' === $phase ? 'Ver todas las ofertas' : 'Ver qué formaciones participan' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				</div>
			</div>
			<?php echo oec_tira_html( array_slice( $rows, 0, 12 ), [ 'more_url' => $url, 'more_label' => sprintf( 'Ver todas las formaciones del %s', $o['name'] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

// En el home va sola, como primer bloque después del hero (antes de los
// cierres de inscripción, #oec-list-top), salvo que el contenido ya traiga
// [oec-campana]. Si no encuentra ese bloque, después de la primera sección.
add_filter( 'the_content', function ( string $content ): string {
	if ( ! is_front_page() || has_shortcode( $content, 'oec-campana' ) || ! in_array( oec_cmp_view_phase(), [ 'teaser', 'live' ], true ) ) {
		return $content;
	}
	$i = strpos( $content, 'id="oec-list-top"' );
	$j = false === $i ? false : strrpos( substr( $content, 0, $i ), '<section' );
	if ( false === $j ) {
		$k = strpos( $content, '</section>' );
		$j = false === $k ? false : $k + strlen( '</section>' );
	}
	return false === $j ? "[oec-campana]\n" . $content : substr_replace( $content, "\n[oec-campana]\n", $j, 0 );
}, 9 );

/* ── Piezas compartidas (landing y franja) ────────────────── */

/**
 * Nombre de la campaña como logotipo: la primera palabra grande, con
 * efecto glitch (CSS, data-text), y el resto ("G-SE") en el acento.
 */
function oec_cmp_wordmark( string $name ): string {
	$parts = preg_split( '/\s+/', trim( $name ), 2 );
	$first = mb_strtoupper( $parts[0] ?? '' );
	return '<span class="oec-cmp-word"><span class="oec-cmp-word__main" data-text="' . esc_attr( $first ) . '">' . esc_html( $first ) . '</span>'
		. ( ! empty( $parts[1] ) ? ' <span class="oec-cmp-word__rest">' . esc_html( $parts[1] ) . '</span>' : '' ) . '</span>';
}

/** Cuenta regresiva (la mueve campaign.js). $to: instante UTC. */
function oec_cmp_countdown( int $to, string $label, string $class = '' ): string {
	$u = static fn( string $k, string $l ) => '<span class="oec-cmp-cd__u"><span class="oec-cmp-cd__n" data-u="' . $k . '">00</span><span class="oec-cmp-cd__l">' . $l . '</span></span>';
	return '<div class="oec-cmp-cd ' . esc_attr( $class ) . '" data-oec-cmp-cd="' . esc_attr( (string) ( $to * 1000 ) ) . '" role="timer" aria-label="' . esc_attr( $label ) . '">'
		. '<span class="oec-cmp-cd__label">' . esc_html( $label ) . '</span>'
		. '<span class="oec-cmp-cd__units">' . $u( 'd', 'días' ) . $u( 'h', 'hs' ) . $u( 'm', 'min' ) . $u( 's', 'seg' ) . '</span>'
		. '</div>';
}

/* ── Newsletter: bloque de la campaña (inc/newsletter.php) ─── */

/**
 * Estado de la campaña para un newsletter enviado en $at: 'teaser' desde
 * OEC_CMP_NL_DAYS días antes del inicio, 'live' mientras dura, '' si no
 * corresponde.
 */
function oec_cmp_nl_phase( int $at ): string {
	$o = oec_cmp_opts();
	$t = oec_cmp_times( $o );
	if ( empty( $o['enabled'] ) || ! $t['start'] || $at > $t['end'] ) {
		return '';
	}
	if ( $at >= $t['start'] ) {
		return 'live';
	}
	return $at >= $t['start'] - OEC_CMP_NL_DAYS * DAY_IN_SECONDS ? 'teaser' : '';
}

/** Prefijo del asunto: "Cyber G-SE: hasta 50% OFF hasta el miércoles · " / "Se viene el Cyber G-SE (2, 3 y 4 de noviembre) · ". */
function oec_cmp_nl_subject_prefix( int $at ): string {
	$phase = oec_cmp_nl_phase( $at );
	if ( ! $phase ) {
		return '';
	}
	$o = oec_cmp_opts();
	if ( 'teaser' === $phase ) {
		return sprintf( 'Se viene el %s (%s) · ', $o['name'], oec_cmp_dias_texto( $o ) );
	}
	$max = oec_cmp_max_pct( oec_cmp_formaciones( 'live' ) );
	$fin = wp_date( 'l', oec_cmp_times( $o )['end'] );
	return $max
		? sprintf( '%s: hasta %d%% OFF hasta el %s · ', $o['name'], $max, $fin )
		: sprintf( '%s: descuentos hasta el %s · ', $o['name'], $fin );
}

/**
 * Bloque para el newsletter semanal (filas <tr> de la tabla de 600 px, ver
 * inc/newsletter-emails.php): fondo de la campaña, nombre, fechas, el
 * mayor descuento y, en curso, las 4 formaciones con más descuento.
 * $link: función que arma el link con el redirector y las UTM.
 */
function oec_cmp_nl_block( int $at, callable $link ): string {
	$phase = oec_cmp_nl_phase( $at );
	if ( ! $phase ) {
		return '';
	}
	$o    = oec_cmp_opts();
	$rows = oec_cmp_formaciones( $phase );
	if ( ! $rows ) {
		return '';
	}
	$f   = defined( 'OEC_NL_FONT' ) ? OEC_NL_FONT : 'Arial,sans-serif';
	$max = oec_cmp_max_pct( $rows );
	$url = esc_attr( $link( oec_cmp_url( $o ) ) );
	$dk  = esc_attr( $o['dark'] );
	$ac  = esc_attr( $o['accent'] );

	$eyebrow = 'live' === $phase ? '¡Ya empezó! Solo ' . oec_cmp_duracion_texto( $o ) : 'Se viene · ' . oec_cmp_dias_texto( $o );
	$title   = 'live' === $phase
		? ( $max ? sprintf( 'Hasta %d%% OFF en %d formaciones', $max, count( $rows ) ) : sprintf( '%d formaciones con descuento', count( $rows ) ) )
		: sprintf( 'Descuentos fuertes en %d formaciones, solo %s', count( $rows ), oec_cmp_dias_texto( $o ) );
	$text = 'live' === $phase
		? sprintf( 'El descuento se aplica solo al inscribirte, sin códigos. Termina %s (hora de Argentina).', oec_cmp_fin_texto( $o ) )
		: sprintf( 'Durante %s vas a poder inscribirte con descuento en formaciones seleccionadas. Mirá desde ahora cuáles participan y elegí la tuya.', oec_cmp_duracion_texto( $o ) );

	$lista = '';
	if ( 'live' === $phase ) {
		foreach ( array_slice( $rows, 0, 4 ) as $r ) {
			$href   = esc_attr( $link( oec_formation_url( $r ) ) );
			$lista .= '<tr><td style="padding:8px 0;border-top:1px solid #2a4a86;font-family:' . $f . ';">'
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
				. ( $r['cmp_pct'] ? '<td width="64" valign="middle" style="width:64px;font-size:20px;line-height:24px;font-weight:800;color:' . $ac . ';">−' . (int) $r['cmp_pct'] . '%</td>' : '' )
				. '<td valign="middle" style="font-size:14px;line-height:20px;"><a href="' . $href . '" target="_blank" style="color:#ffffff;text-decoration:none;font-weight:700;">' . esc_html( $r['title'] ) . '</a></td>'
				. '</tr></table></td></tr>';
		}
		$lista = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;">' . $lista . '</table>';
	}

	return '<tr><td class="nl-px" bgcolor="#ffffff" style="background:#ffffff;padding:28px 32px 8px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $dk . '" style="background:' . $dk . ';border-radius:14px;border-collapse:separate;"><tr><td style="padding:24px 24px 22px;font-family:' . $f . ';">'
		. '<p style="margin:0 0 6px;font-size:11px;line-height:15px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:' . $ac . ';">⚡ ' . esc_html( $eyebrow ) . '</p>'
		. '<p style="margin:0 0 8px;font-size:30px;line-height:34px;font-weight:900;letter-spacing:-.5px;color:#ffffff;">' . esc_html( mb_strtoupper( $o['name'] ) ) . '</p>'
		. '<p style="margin:0 0 8px;font-size:18px;line-height:24px;font-weight:800;color:' . $ac . ';">' . esc_html( $title ) . '</p>'
		. '<p style="margin:0 0 16px;font-size:14px;line-height:21px;color:#c9d6ee;">' . esc_html( $text ) . '</p>'
		. $lista
		. oec_nl_btn( $url, 'live' === $phase ? 'Ver todas las ofertas' : 'Ver qué formaciones participan', $o['accent'], $o['dark'] )
		. '</td></tr></table>'
		. '</td></tr>';
}

/* ── Admin: Tema OEC → Campañas ───────────────────────────── */

add_action( 'admin_post_oec_cmp_save', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sin permiso.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_cmp_save' );
	$raw = wp_unslash( $_POST['oec_cmp'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$d   = oec_cmp_defaults();
	$dt  = static function ( $v ) {
		$v = str_replace( 'T', ' ', (string) $v );
		return DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $v, wp_timezone() ) ? $v : '';
	};
	$o = [
		'enabled'     => ! empty( $raw['enabled'] ),
		'name'        => sanitize_text_field( $raw['name'] ?? '' ) ?: $d['name'],
		'slug'        => sanitize_title( $raw['slug'] ?? '' ) ?: $d['slug'],
		'tag'         => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $raw['tag'] ?? '' ) ),
		'start'       => $dt( $raw['start'] ?? '' ) ?: $d['start'],
		'end'         => $dt( $raw['end'] ?? '' ) ?: $d['end'],
		'teaser_days' => min( 14, max( 0, (int) ( $raw['teaser_days'] ?? $d['teaser_days'] ) ) ),
		'dark'        => sanitize_hex_color( $raw['dark'] ?? '' ) ?: $d['dark'],
		'dark_2'      => sanitize_hex_color( $raw['dark_2'] ?? '' ) ?: $d['dark_2'],
		'accent'      => sanitize_hex_color( $raw['accent'] ?? '' ) ?: $d['accent'],
	];
	$prev = oec_cmp_opts();
	update_option( OEC_CMP_OPTION, $o, false );
	if ( $prev['tag'] !== $o['tag'] ) {
		delete_option( OEC_CMP_DATA ); // otro grupo: los descuentos guardados ya no sirven
	}
	if ( $o['enabled'] ) {
		oec_cmp_ensure_page( $o );
	}
	oec_cmp_schedule();
	if ( oec_cmp_window() && ! wp_next_scheduled( 'oec_cmp_refresh' ) ) {
		wp_schedule_single_event( time() + 5, 'oec_cmp_refresh' );
	}
	if ( function_exists( 'oec_cf_queue' ) ) {
		oec_cf_queue( [], true ); // la barra, el tono y las marcas están en todas las páginas
	}
	wp_safe_redirect( add_query_arg( [ 'page' => 'oec-campanas', 'oec_cmp_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
	exit;
} );

add_action( 'admin_post_oec_cmp_refresh', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sin permiso.', 'oec-theme' ) );
	}
	check_admin_referer( 'oec_cmp_refresh' );
	oec_cmp_refresh();
	wp_safe_redirect( add_query_arg( [ 'page' => 'oec-campanas', 'oec_cmp_msg' => 'refreshed' ], admin_url( 'admin.php' ) ) );
	exit;
} );

function oec_cmp_render_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$o     = oec_cmp_opts();
	$t     = oec_cmp_times( $o );
	$phase = oec_cmp_phase();
	$data  = oec_cmp_data();
	$rows  = oec_cmp_formaciones();
	$url   = oec_cmp_url( $o );
	$msg   = sanitize_key( $_GET['oec_cmp_msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$fmt   = static fn( int $ts ) => $ts ? wp_date( 'D j/n/Y H:i', $ts ) : '—';
	$phases = [
		'off'    => [ 'Apagada', '#6b7280' ],
		'teaser' => [ 'Se viene', '#b45309' ],
		'live'   => [ 'En curso', '#047857' ],
		'ended'  => [ 'Terminó', '#6b7280' ],
	];
	$input_dt = static fn( string $v ) => str_replace( ' ', 'T', $v );
	oec_page_inline_styles();
	?>
	<div class="wrap oec-settings-wrap">
		<h1><?php esc_html_e( 'Campañas', 'oec-theme' ); ?></h1>
		<?php if ( 'saved' === $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaña guardada. Se borró la caché de todo el sitio.', 'oec-theme' ); ?></p></div><?php endif; ?>
		<?php if ( 'refreshed' === $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( 'Descuentos actualizados: %d formaciones consultadas%s.', count( $data['items'] ), $data['errors'] ? sprintf( ', %d con error (quedó su dato anterior)', $data['errors'] ) : '' ) ); ?></p></div><?php endif; ?>

		<div class="oec-card">
			<p style="margin-top:0">
				<?php esc_html_e( 'Eventos de descuentos (Cyber, Black Friday, Back to School…). Mientras dura, todo el sitio toma el color de la campaña, muestra una barra arriba, un aviso una vez por visita y una marca con el % en cada formación que participa; el home suma una franja y el chat IA y el newsletter la conocen. Las fichas de formación solo llevan una marca, para no sacar a nadie de la página cuando está por comprar.', 'oec-theme' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Estado:', 'oec-theme' ); ?></strong>
				<span style="display:inline-block;padding:2px 10px;border-radius:999px;color:#fff;background:<?php echo esc_attr( $phases[ $phase ][1] ); ?>"><?php echo esc_html( $phases[ $phase ][0] ); ?></span>
				&nbsp; <?php echo esc_html( sprintf( 'Se viene desde %s · empieza %s · termina %s', $fmt( $t['teaser'] ), $fmt( $t['start'] ), $fmt( $t['end'] ) ) ); ?>
			</p>
			<?php if ( get_page_by_path( 'especiales/' . $o['slug'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Landing:', 'oec-theme' ); ?></strong> <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a>
				&nbsp;·&nbsp; <?php esc_html_e( 'Vista previa:', 'oec-theme' ); ?>
				<?php foreach ( [ 'teaser' => 'se viene', 'live' => 'en curso', 'ended' => 'terminó' ] as $k => $l ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'oec_cmp_preview', $k, $url ) ); ?>" target="_blank"><?php echo esc_html( $l ); ?></a> ·
				<?php endforeach; ?>
				<a href="<?php echo esc_url( add_query_arg( 'oec_cmp_preview', 'live', home_url( '/' ) ) ); ?>" target="_blank"><?php esc_html_e( 'home en curso', 'oec-theme' ); ?></a>
			</p>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oec-card">
			<input type="hidden" name="action" value="oec_cmp_save">
			<?php wp_nonce_field( 'oec_cmp_save' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Activa', 'oec-theme' ); ?></th>
					<td><label><input type="checkbox" name="oec_cmp[enabled]" value="1" <?php checked( $o['enabled'] ); ?>> <?php esc_html_e( 'Mostrar la campaña en las fechas de abajo', 'oec-theme' ); ?></label></td></tr>
				<tr><th scope="row"><label for="oec-cmp-name"><?php esc_html_e( 'Nombre', 'oec-theme' ); ?></label></th>
					<td><input type="text" id="oec-cmp-name" name="oec_cmp[name]" value="<?php echo esc_attr( $o['name'] ); ?>" class="regular-text"><p class="description"><?php esc_html_e( 'Como se ve en el sitio: "Cyber G-SE". La primera palabra va grande, como logo.', 'oec-theme' ); ?></p></td></tr>
				<tr><th scope="row"><label for="oec-cmp-slug"><?php esc_html_e( 'Dirección', 'oec-theme' ); ?></label></th>
					<td><code><?php echo esc_html( home_url( '/especiales/' ) ); ?></code><input type="text" id="oec-cmp-slug" name="oec_cmp[slug]" value="<?php echo esc_attr( $o['slug'] ); ?>"><p class="description"><?php esc_html_e( 'La página se crea sola al guardar (hija de Especiales). Cada campaña nueva, una dirección nueva: la anterior queda mostrando "terminó".', 'oec-theme' ); ?></p></td></tr>
				<tr><th scope="row"><label for="oec-cmp-tag"><?php esc_html_e( 'Grupo de la API', 'oec-theme' ); ?></label></th>
					<td><input type="text" id="oec-cmp-tag" name="oec_cmp[tag]" value="<?php echo esc_attr( $o['tag'] ); ?>"><p class="description"><?php esc_html_e( 'El wpgroup que tienen las formaciones que participan (mayúsculas o minúsculas da igual). El descuento es el de pago anticipado de cada una.', 'oec-theme' ); ?></p></td></tr>
				<tr><th scope="row"><label for="oec-cmp-start"><?php esc_html_e( 'Empieza', 'oec-theme' ); ?></label></th>
					<td><input type="datetime-local" id="oec-cmp-start" name="oec_cmp[start]" value="<?php echo esc_attr( $input_dt( $o['start'] ) ); ?>"> <?php esc_html_e( 'termina', 'oec-theme' ); ?> <input type="datetime-local" name="oec_cmp[end]" value="<?php echo esc_attr( $input_dt( $o['end'] ) ); ?>" aria-label="<?php esc_attr_e( 'Termina', 'oec-theme' ); ?>">
						<p class="description"><?php echo esc_html( sprintf( 'Hora del sitio (%s). El fin incluye ese minuto: 23:59 = hasta terminar el día.', wp_timezone_string() ) ); ?></p></td></tr>
				<tr><th scope="row"><label for="oec-cmp-teaser"><?php esc_html_e( '"Se viene"', 'oec-theme' ); ?></label></th>
					<td><input type="number" id="oec-cmp-teaser" name="oec_cmp[teaser_days]" value="<?php echo (int) $o['teaser_days']; ?>" min="0" max="14" style="width:5em"> <?php esc_html_e( 'días antes', 'oec-theme' ); ?>
						<p class="description"><?php echo esc_html( sprintf( 'Barra y landing con cuenta regresiva al inicio y suscripción al newsletter. El newsletter semanal la anuncia desde %d días antes.', OEC_CMP_NL_DAYS ) ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Colores', 'oec-theme' ); ?></th>
					<td>
						<label><input type="color" name="oec_cmp[dark]" value="<?php echo esc_attr( $o['dark'] ); ?>"> <?php esc_html_e( 'Fondo', 'oec-theme' ); ?></label> &nbsp;
						<label><input type="color" name="oec_cmp[dark_2]" value="<?php echo esc_attr( $o['dark_2'] ); ?>"> <?php esc_html_e( 'Fondo 2 (degradé)', 'oec-theme' ); ?></label> &nbsp;
						<label><input type="color" name="oec_cmp[accent]" value="<?php echo esc_attr( $o['accent'] ); ?>"> <?php esc_html_e( 'Acento', 'oec-theme' ); ?></label>
						<p class="description"><?php esc_html_e( 'El acento va sobre el fondo oscuro (debe ser claro). En el resto del sitio se usa una versión más intensa, que se lee sobre blanco.', 'oec-theme' ); ?></p>
					</td></tr>
			</table>
			<?php submit_button( __( 'Guardar campaña', 'oec-theme' ) ); ?>
		</form>

		<div class="oec-card">
			<h2 style="margin-top:0"><?php echo esc_html( sprintf( 'Formaciones que participan (%d)', count( $rows ) ) ); ?></h2>
			<p>
				<?php echo esc_html( $data['updated'] ? sprintf( 'Descuentos consultados: %s.', wp_date( 'D j/n H:i', $data['updated'] ) ) : 'Todavía no se consultaron los descuentos.' ); ?>
				<?php esc_html_e( 'Se consultan solos después de cada sincronización del catálogo, desde 2 días antes del "se viene", y al empezar.', 'oec-theme' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1rem">
				<input type="hidden" name="action" value="oec_cmp_refresh">
				<?php wp_nonce_field( 'oec_cmp_refresh' ); ?>
				<?php submit_button( __( 'Actualizar descuentos ahora', 'oec-theme' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $rows ) : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Formación', 'oec-theme' ); ?></th><th><?php esc_html_e( 'Pago anticipado', 'oec-theme' ); ?></th><th><?php esc_html_e( 'Vence', 'oec-theme' ); ?></th><th><?php esc_html_e( 'Cierre inscripción', 'oec-theme' ); ?></th><th><?php esc_html_e( 'En la campaña', 'oec-theme' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$it = $data['items'][ $r['id'] ] ?? null;
					?>
					<tr>
						<td><a href="<?php echo esc_url( oec_formation_url( $r ) ); ?>" target="_blank"><?php echo esc_html( $r['title'] ); ?></a><?php echo oec_formation_is_external( $r ) ? ' <small>(' . esc_html( (string) wp_parse_url( $r['community'], PHP_URL_HOST ) ) . ')</small>' : ''; ?></td>
						<td><?php echo $it ? esc_html( $it['pct'] . '%' ) : '—'; ?></td>
						<td><?php echo $it && $it['exp'] ? esc_html( $it['exp'] ) : '—'; ?></td>
						<td><?php echo esc_html( $r['enrollment_end'] ?: '—' ); ?></td>
						<td><?php echo $r['cmp_pct'] ? '<span style="color:#047857;font-weight:700">−' . (int) $r['cmp_pct'] . '%</span>' : '<span style="color:#b45309">' . esc_html__( 'sin descuento vigente para la campaña', 'oec-theme' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Mientras la campaña está en curso, la landing y las marcas muestran solo las que tienen descuento (pago anticipado que vence el día de inicio o después).', 'oec-theme' ); ?></p>
			<?php else : ?>
			<p><?php esc_html_e( 'No hay formaciones abiertas con ese grupo en el catálogo (se actualiza con la sincronización diaria).', 'oec-theme' ); ?></p>
			<?php endif; ?>
		</div>

		<?php
		if ( function_exists( 'oec_nl_btn' ) && $o['enabled'] && $rows ) :
			$nl_link = static fn( string $u ) => add_query_arg( [ 'utm_source' => 'mailing', 'utm_medium' => 'elastic', 'utm_campaign' => sanitize_title( $o['name'] ) ], $u );
			?>
		<div class="oec-card">
			<h2 style="margin-top:0"><?php esc_html_e( 'En el newsletter semanal', 'oec-theme' ); ?></h2>
			<p><?php echo esc_html( sprintf( 'Desde %d días antes del inicio y hasta el fin, cada newsletter (el general y los de cada temática) lleva este bloque arriba de todo y el asunto empieza con la campaña. Esa semana sale aunque no haya artículos nuevos.', OEC_CMP_NL_DAYS ) ); ?></p>
			<?php foreach ( [ 'teaser' => $t['start'] - DAY_IN_SECONDS, 'live' => $t['start'] + HOUR_IN_SECONDS ] as $k => $at ) : ?>
			<p><strong><?php echo esc_html( 'teaser' === $k ? 'Antes de empezar' : 'En curso' ); ?></strong> — <?php esc_html_e( 'asunto:', 'oec-theme' ); ?> <em><?php echo esc_html( oec_cmp_nl_subject_prefix( $at ) . 'Novedades de esta semana: …' ); ?></em></p>
			<table role="presentation" width="600" style="max-width:100%;background:#fff;border:1px solid #dcdcde"><?php echo oec_cmp_nl_block( $at, $nl_link ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></table>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
}
