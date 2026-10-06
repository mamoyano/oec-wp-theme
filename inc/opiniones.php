<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   OPINIONES — [oec-opiniones limit="6" tematica="" title=""]

   Testimonios de alumnos renderizados del lado del servidor desde el
   catálogo sincronizado (OEC_AI_Catalog), sin JS ni llamadas a la API en
   cada visita — reemplaza a [oec-reviews] del plugin, que por lo lento de
   la API se cargaba diferido con JS.

   Arriba, el agregado que da validez: promedio y total de opiniones de
   todas las formaciones (reviews_summary de la API). Abajo, una selección
   de opiniones reales (5★ preferentemente, texto de largo legible, con
   foto, recientes), una por formación y rotando a diario.
   ============================================================ */

/**
 * Opiniones + agregado de todo el catálogo (abiertas + cerradas).
 * Cacheado hasta el próximo sync.
 */
function oec_opiniones_catalog(): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return [ 'reviews' => [], 'summary' => [] ];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_opiniones_v2_' . md5( (string) ( $meta['finished_at'] ?? '' ) );
	$hit  = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}

	$reviews = [];
	$summary = []; // tematica ('' = todas) => [suma ponderada, cantidad]
	foreach ( OEC_AI_Catalog::get_history_index() as $h ) {
		$f = OEC_AI_Catalog::get_formation( (string) $h['id'] );
		if ( ! $f ) {
			continue;
		}
		$tags = $f['tags'] ?? [];
		if ( ! empty( $f['reviews_summary']['count'] ) ) {
			foreach ( array_merge( [ '' ], $tags ) as $t ) {
				$summary[ $t ][0] = ( $summary[ $t ][0] ?? 0 ) + $f['reviews_summary']['average'] * $f['reviews_summary']['count'];
				$summary[ $t ][1] = ( $summary[ $t ][1] ?? 0 ) + $f['reviews_summary']['count'];
			}
		}
		foreach ( $f['reviews'] ?? [] as $r ) {
			$reviews[] = [
				'author'    => oec_opinion_clean_name( (string) ( $r['author'] ?? '' ) ),
				'rating'    => (int) ( $r['rating'] ?? 0 ),
				'comment'   => oec_opinion_clean_comment( (string) ( $r['comment'] ?? '' ) ),
				// La silueta genérica de la plataforma cuenta como "sin foto" (→ iniciales).
				'image'     => false !== strpos( (string) ( $r['image'] ?? '' ), 'user-default' ) ? '' : (string) ( $r['image'] ?? '' ),
				'specialty' => (string) ( $r['specialty'] ?? '' ),
				'origin'    => (string) ( $r['origin'] ?? '' ),
				'date'      => (string) ( $r['date'] ?? '' ),
				'f_id'      => $f['id'],
				'f_title'   => $f['title'] ?? '',
				'f_slug'    => basename( (string) wp_parse_url( $f['url'] ?? '', PHP_URL_PATH ) ),
				'tags'      => $tags,
			];
		}
	}

	$data = [ 'reviews' => $reviews, 'summary' => $summary ];
	set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
	return $data;
}

/** "matias magnano" / "MATIAS MAGNANO" → "Matias Magnano". */
function oec_opinion_clean_name( string $name ): string {
	$name = trim( preg_replace( '/\s+/', ' ', $name ) );
	if ( $name === mb_strtolower( $name ) || $name === mb_strtoupper( $name ) ) {
		$name = mb_convert_case( mb_strtolower( $name ), MB_CASE_TITLE );
	}
	return $name;
}

/** Espacios prolijos; MAYÚSCULAS → oración normal; primera letra en mayúscula. */
function oec_opinion_clean_comment( string $text ): string {
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	if ( $text !== '' && $text === mb_strtoupper( $text ) ) {
		$text = mb_strtolower( $text );
	}
	return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
}

/** Iniciales para el avatar sin foto. */
function oec_opinion_initials( string $name ): string {
	$parts = preg_split( '/\s+/', $name );
	$ini   = mb_substr( $parts[0] ?? '', 0, 1 ) . mb_substr( count( $parts ) > 1 ? end( $parts ) : '', 0, 1 );
	return mb_strtoupper( $ini );
}

add_shortcode( 'oec-opiniones', 'oec_render_opiniones_shortcode' );
function oec_render_opiniones_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'limit'    => 6,
		'tematica' => '',
		'title'    => __( 'Lo que dicen nuestros alumnos', 'oec-theme' ),
	], $atts, 'oec-opiniones' );

	$limit = max( 1, (int) $atts['limit'] );
	$data  = oec_opiniones_catalog();
	$tem   = $atts['tematica'];

	$pool = array_filter( $data['reviews'], function ( $r ) use ( $tem ) {
		$len = mb_strlen( $r['comment'] );
		return $r['rating'] >= 4 && $len >= 70 && $len <= 480
			&& ( '' === $tem || in_array( $tem, $r['tags'], true ) );
	} );
	if ( ! $pool ) {
		return '';
	}

	// Mejores primero: 5★, con foto, más recientes, con especialidad.
	$score = fn( $r ) => ( 5 === $r['rating'] ? 4 : 0 ) + ( $r['image'] ? 2 : 0 ) + ( $r['specialty'] ? 1 : 0 )
		+ ( $r['date'] >= gmdate( 'Y-m-d', strtotime( '-2 years' ) ) ? 2 : 0 );
	usort( $pool, fn( $a, $b ) => $score( $b ) <=> $score( $a ) ?: strcmp( $b['date'], $a['date'] ) );

	// Una por formación, así se ve la variedad de la oferta.
	$vistas = [];
	$pool   = array_values( array_filter( $pool, function ( $r ) use ( &$vistas ) {
		if ( isset( $vistas[ $r['f_id'] ] ) ) {
			return false;
		}
		return $vistas[ $r['f_id'] ] = true;
	} ) );
	$pool = array_slice( $pool, 0, $limit * 3 );

	// Rotación diaria estable.
	mt_srand( crc32( 'opiniones' . current_time( 'Y-m-d' ) . $tem ) );
	shuffle( $pool );
	mt_srand();
	$opiniones = array_slice( $pool, 0, $limit );

	// 3 columnas armadas acá (no con columnas CSS: cada navegador las
	// balancea distinto y en Safari las columnas no arrancaban a la misma
	// altura). Cada opinión va a la columna más corta hasta ese momento,
	// estimando el alto por el largo del comentario. Se conserva el índice
	// original ($i) para ocultar las que sobran en mobile.
	$columnas = [ [], [], [] ];
	$altos    = [ 0, 0, 0 ];
	foreach ( $opiniones as $i => $r ) {
		$c                  = array_search( min( $altos ), $altos, true );
		$columnas[ $c ][ $i ] = $r;
		$altos[ $c ]       += 180 + mb_strlen( $r['comment'] );
	}
	$columnas = array_filter( $columnas );

	[ $suma, $cant ] = $data['summary'][ $tem ] ?? [ 0, 0 ];
	$promedio = $cant ? $suma / $cant : 0;

	ob_start();
	?>
	<section class="oec-opiniones" aria-labelledby="oec-opiniones-title">
		<div class="oec-opiniones__head">
			<div>
				<span class="oec-opiniones__eyebrow"><?php esc_html_e( 'Opiniones de alumnos', 'oec-theme' ); ?></span>
				<h2 class="oec-opiniones__title" id="oec-opiniones-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="oec-opiniones__sub"><?php esc_html_e( 'Reseñas reales de alumnos de nuestras formaciones: qué les pareció el contenido, los docentes y la experiencia.', 'oec-theme' ); ?></p>
			</div>
			<?php if ( $cant ) : ?>
			<div class="oec-opiniones__score">
				<strong class="oec-opiniones__avg"><?php echo esc_html( number_format_i18n( $promedio, 1 ) ); ?></strong>
				<div>
					<span class="oec-opiniones__stars" style="--rating: <?php echo esc_attr( round( $promedio / 5 * 100, 1 ) ); ?>%;" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%s de 5 estrellas', 'oec-theme' ), number_format_i18n( $promedio, 1 ) ) ); ?>"></span>
					<span class="oec-opiniones__count">
						<?php echo esc_html( sprintf( __( '%s opiniones de alumnos', 'oec-theme' ), number_format_i18n( $cant ) ) ); ?>
					</span>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<div class="oec-opiniones__grid">
			<?php foreach ( $columnas as $col ) : ?>
			<div class="oec-opiniones__col">
				<?php foreach ( $col as $i => $r ) : ?>
					<figure class="oec-opinion<?php echo $i >= 4 ? ' is-extra' : ''; ?>">
						<div class="oec-opinion__top">
							<span class="oec-opinion__stars" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d de 5 estrellas', 'oec-theme' ), $r['rating'] ) ); ?>">
								<?php echo str_repeat( '<i class="bi bi-star-fill" aria-hidden="true"></i>', $r['rating'] ); // phpcs:ignore ?>
							</span>
							<?php if ( $r['date'] ) : ?>
							<time class="oec-opinion__date" datetime="<?php echo esc_attr( $r['date'] ); ?>"><?php echo esc_html( date_i18n( 'F Y', strtotime( $r['date'] ) ) ); ?></time>
							<?php endif; ?>
						</div>
						<blockquote class="oec-opinion__text"><?php echo esc_html( $r['comment'] ); ?></blockquote>
						<figcaption class="oec-opinion__author">
							<?php if ( $r['image'] ) : ?>
							<img class="oec-opinion__avatar" src="<?php echo esc_url( 'https://imgrsize.oe-img.center' . $r['image'] . '?w=96&q=85&format=webp' ); ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
							<?php else : ?>
							<span class="oec-opinion__avatar oec-opinion__avatar--initials" aria-hidden="true"><?php echo esc_html( oec_opinion_initials( $r['author'] ) ); ?></span>
							<?php endif; ?>
							<span class="oec-opinion__who">
								<strong><?php echo esc_html( $r['author'] ); ?></strong>
								<?php if ( $r['specialty'] || $r['origin'] ) : ?>
								<span><?php echo esc_html( implode( ' · ', array_filter( [ $r['specialty'], $r['origin'] ] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $r['f_slug'] ) : ?>
								<a class="oec-opinion__course" href="<?php echo esc_url( home_url( user_trailingslashit( '/formacion/' . $r['f_slug'] ) ) ); ?>"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo esc_html( wp_trim_words( $r['f_title'], 9, '…' ) ); ?></a>
								<?php endif; ?>
							</span>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
