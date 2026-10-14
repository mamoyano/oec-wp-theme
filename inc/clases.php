<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   CLASES ABIERTAS — reproductor + lista de reproducción

   [oec-clases title="…"]
     [oec-clase url="https://vimeo.com/…" title="…" description="…" more-url="/formacion/…"]
     [oec-clase …]
   [/oec-clases]

   Clases reales grabadas en vivo (Vimeo públicos). Un reproductor grande
   que se reproduce en la página, una lista a la derecha y, al terminar
   cada clase, "Ver curso completo" + autoplay de la siguiente con cuenta
   regresiva (main.js, vía la API postMessage del player de Vimeo, sin
   librerías). Miniatura, duración y fecha salen del oEmbed público de
   Vimeo, cacheado 7 días; además se publica JSON-LD VideoObject.

   Con el token de Vimeo (inc/vimeo.php) las clases salen solas de la
   cuenta — categoría "Educativo" —: en el home, todas; en una landing,
   las del tag de su temática (la detecta sola, o tematica="…"). Salen
   las primeras "max" (7) del orden al azar que se sortea cada vez que el
   tema se conecta a Vimeo (inc/vimeo.php): mismas clases y mismo orden
   para todos hasta la próxima conexión. El HTML trae solo esas, así el
   JSON-LD VideoObject describe exactamente los videos que se ven. Sin
   token, o si no hay videos para esa temática, se usa la lista cargada a
   mano adentro del shortcode.

   [oec-clases title="…" max="7"]  (tematica="nutricion-deportiva" opcional)
   ============================================================ */

/**
 * Datos oEmbed de varios videos de Vimeo, en paralelo para los que no
 * están en caché (7 días; si Vimeo falla, se reintenta en 1 hora).
 */
function oec_vimeo_oembed_many( array $urls ): array {
	$out     = [];
	$missing = [];
	foreach ( $urls as $url ) {
		$hit = get_transient( 'oec_vimeo_' . md5( $url ) );
		if ( is_array( $hit ) ) {
			$out[ $url ] = $hit;
		} else {
			$missing[ $url ] = [
				'url'     => 'https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode( $url ),
				'type'    => 'GET',
				'options' => [ 'timeout' => 8 ],
			];
		}
	}
	if ( $missing ) {
		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
		try {
			$responses = $class::request_multiple( $missing );
		} catch ( \Throwable $e ) {
			$responses = [];
		}
		foreach ( array_keys( $missing ) as $url ) {
			$r    = $responses[ $url ] ?? null;
			$data = ( $r && ! ( $r instanceof \Throwable ) && ! empty( $r->success ) ) ? json_decode( $r->body, true ) : null;
			$info = is_array( $data ) && ! empty( $data['video_id'] ) ? [
				'id'       => (int) $data['video_id'],
				'thumb'    => (string) ( $data['thumbnail_url'] ?? '' ),
				'duration' => (int) ( $data['duration'] ?? 0 ),
				'date'     => substr( (string) ( $data['upload_date'] ?? '' ), 0, 10 ),
			] : [];
			set_transient( 'oec_vimeo_' . md5( $url ), $info, $info ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
			$out[ $url ] = $info;
		}
	}
	return $out;
}

/** 4755 → "1 h 19 min"; 2520 → "42 min". */
function oec_duracion( int $segundos ): string {
	$min = (int) round( $segundos / 60 );
	return $min >= 60 ? sprintf( '%d h %02d min', intdiv( $min, 60 ), $min % 60 ) : sprintf( '%d min', max( 1, $min ) );
}

// [oec-clase] solo tiene sentido dentro de [oec-clases]; suelto no imprime nada.
add_shortcode( 'oec-clase', '__return_empty_string' );

add_shortcode( 'oec-clases', 'oec_render_clases_shortcode' );
function oec_render_clases_shortcode( $atts, $content = '' ): string {
	$atts = shortcode_atts( [
		'title'    => __( 'Mirá clases reales de nuestras formaciones', 'oec-theme' ),
		'tematica' => null, // null = la de la landing que se está viendo ('' en el home)
		'max'      => 7,
	], $atts, 'oec-clases' );
	$max = max( 1, (int) $atts['max'] );

	// Desde Vimeo (inc/vimeo.php), si hay token y videos para esta temática.
	$tem    = null === $atts['tematica'] ? ( function_exists( 'oec_vimeo_tematica_actual' ) ? oec_vimeo_tematica_actual() : '' ) : sanitize_title( $atts['tematica'] );
	$clases = function_exists( 'oec_vimeo_videos' ) ? array_slice( oec_vimeo_videos( $tem ), 0, $max ) : [];
	if ( $clases ) {
		// El link al curso va en la descripción (el nombre del curso, como en
		// Vimeo), no en un botón "Ver el curso completo".
		$clases = array_map( fn( $c ) => [ 'desc_html' => oec_vimeo_desc_html( $c ), 'more' => '' ] + $c, $clases );
		return oec_render_clases( $clases, $atts['title'] );
	}

	// Si no, las [oec-clase] de adentro: se leen sus atributos, no se ejecutan.
	if ( preg_match_all( '/' . get_shortcode_regex( [ 'oec-clase' ] ) . '/s', (string) $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $sc ) {
			$a = shortcode_atts( [ 'url' => '', 'title' => '', 'description' => '', 'more-url' => '' ], shortcode_parse_atts( $sc[3] ) ?: [] );
			if ( $a['url'] ) {
				$clases[] = $a;
			}
		}
	}
	if ( ! $clases ) {
		return '';
	}

	$oembed = oec_vimeo_oembed_many( array_column( $clases, 'url' ) );
	foreach ( $clases as $i => $c ) {
		$clases[ $i ] += $oembed[ $c['url'] ] ?? [];
		$clases[ $i ]['more'] = $c['more-url'] ? home_url( $c['more-url'] ) : '';
	}
	$clases = array_values( array_filter( $clases, fn( $c ) => ! empty( $c['id'] ) ) );
	if ( ! $clases ) {
		return '';
	}
	// La lista a mano se muestra entera y en su orden.
	return oec_render_clases( $clases, $atts['title'] );
}

/**
 * Reproductor + lista. $clases: id, h (hash de los videos ocultos), title,
 * description, more (URL absoluta), thumb, duration, date, url — en el
 * orden en que se muestran.
 */
function oec_render_clases( array $clases, string $title ): string {
	$total = array_sum( array_column( $clases, 'duration' ) );
	$first = $clases[0];
	// Botón "Ver el curso completo" solo si alguna clase tiene link aparte
	// (lista a mano). Las de Vimeo lo llevan en la descripción.
	$con_more = (bool) array_filter( array_column( $clases, 'more' ) );
	$atts  = [ 'title' => $title ];

	ob_start();
	?>
	<section class="oec-clases" data-oec-clases aria-labelledby="oec-clases-title">
		<div class="oec-clases__head">
			<span class="oec-agenda__eyebrow"><i class="bi bi-play-btn-fill" aria-hidden="true"></i> <?php esc_html_e( 'Clases abiertas', 'oec-theme' ); ?></span>
			<h2 class="oec-agenda__title" id="oec-clases-title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<p class="oec-agenda__sub">
				<?php
				$n = count( $clases );
				printf(
					/* translators: 1: cantidad de clases, 2: duración total */
					esc_html( _n( '%1$s clase completa, grabada en vivo · %2$s para mirar gratis.', '%1$s clases completas, grabadas en vivo · %2$s para mirar gratis.', $n, 'oec-theme' ) ),
					esc_html( number_format_i18n( $n ) ),
					esc_html( oec_duracion( $total ) )
				);
				?>
			</p>
		</div>

		<div class="oec-clases__layout">
			<div class="oec-clases__main">
				<div class="oec-clases__player" data-clases-player>
					<button type="button" class="oec-clases__poster" data-clases-poster style="background-image: url('<?php echo esc_url( $first['thumb'] ); ?>');">
						<span class="oec-clases__play" aria-hidden="true"><i class="bi bi-play-fill"></i></span>
						<span class="oec-clases__play-label"><?php esc_html_e( 'Ver la clase', 'oec-theme' ); ?> · <span data-clases-dur><?php echo esc_html( oec_duracion( $first['duration'] ) ); ?></span></span>
					</button>
					<div class="oec-clases__end" data-clases-end hidden>
						<p class="oec-clases__end-title"><?php esc_html_e( '¿Te gustó la clase?', 'oec-theme' ); ?></p>
						<?php if ( $con_more ) : ?>
						<a class="btn oec-clases__end-cta" data-clases-more href="<?php echo esc_url( $first['more'] ); ?>"><?php esc_html_e( 'Ver el curso completo', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
						<?php endif; ?>
						<p class="oec-clases__end-next" data-clases-next></p>
						<button type="button" class="oec-clases__end-cancel" data-clases-cancel><?php esc_html_e( 'Cancelar', 'oec-theme' ); ?></button>
					</div>
				</div>
				<div class="oec-clases__info">
					<h3 class="oec-clases__title" data-clases-title><?php echo esc_html( $first['title'] ); ?></h3>
					<p class="oec-clases__desc" data-clases-desc><?php echo $first['desc_html'] ?? esc_html( $first['description'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- armado escapado en oec_vimeo_desc_html() ?></p>
<?php if ( $con_more ) : ?>
					<a class="oec-cierres__more" data-clases-more href="<?php echo esc_url( $first['more'] ); ?>"<?php echo $first['more'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Ver el curso completo', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
					<?php endif; ?>
				</div>
			</div>

			<ol class="oec-clases__list" aria-label="<?php esc_attr_e( 'Lista de clases', 'oec-theme' ); ?>">
				<?php foreach ( $clases as $i => $c ) : ?>
				<li>
					<button type="button" class="oec-clase" data-clase
					        data-id="<?php echo (int) $c['id']; ?>"
					        data-h="<?php echo esc_attr( $c['h'] ?? '' ); ?>"
					        data-title="<?php echo esc_attr( $c['title'] ); ?>"
					        data-desc="<?php echo esc_attr( $c['desc_html'] ?? esc_html( $c['description'] ) ); ?>"
					        data-more="<?php echo esc_url( $c['more'] ); ?>"
					        data-thumb="<?php echo esc_url( $c['thumb'] ); ?>"
					        data-dur="<?php echo esc_attr( oec_duracion( $c['duration'] ) ); ?>"
					        <?php echo 0 === $i ? 'aria-current="true"' : ''; ?>>
						<span class="oec-clase__thumb">
							<img src="<?php echo esc_url( preg_replace( '/-d_\d+/', '-d_480', $c['thumb'] ) ); ?>" alt="" loading="lazy" decoding="async" width="480" height="270">
							<span class="oec-clase__dur"><?php echo esc_html( oec_duracion( $c['duration'] ) ); ?></span>
							<span class="oec-clase__now"><span class="oec-clase__bars" aria-hidden="true"><i></i><i></i><i></i></span><?php esc_html_e( 'Reproduciendo', 'oec-theme' ); ?></span>
						</span>
						<span class="oec-clase__text">
							<strong><?php echo esc_html( $c['title'] ); ?></strong>
							<span><?php echo esc_html( $c['description'] ); ?></span>
						</span>
					</button>
				</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<script type="application/ld+json"><?php
		echo wp_json_encode( [
			'@context' => 'https://schema.org',
			'@graph'   => array_map( fn( $c ) => array_filter( [
				'@type'        => 'VideoObject',
				'name'         => $c['title'],
				'description'  => $c['description'] ?: $c['title'],
				'thumbnailUrl' => $c['thumb'],
				// Google exige fecha+hora con zona (ISO 8601): las de la API de
				// Vimeo ya la traen; las del oEmbed (lista a mano), solo el día.
				'uploadDate'   => $c['date'] ? ( new DateTime( $c['date'], wp_timezone() ) )->format( 'c' ) : null,
				'duration'     => $c['duration'] ? 'PT' . $c['duration'] . 'S' : null,
				'embedUrl'     => 'https://player.vimeo.com/video/' . $c['id'] . ( ! empty( $c['h'] ) ? '?h=' . $c['h'] : '' ),
				'url'          => $c['url'],
			] ), $clases ),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		?></script>
	</section>
	<?php
	return ob_get_clean();
}
