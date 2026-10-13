<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SUBTEMÁTICA DE UNA LANDING — [oec-subtematica]

   Un espacio propio, dentro de la landing de una temática, para un grupo de
   formaciones que comparten además otra etiqueta: Antropometría dentro de
   Nutrición Deportiva, por ejemplo.

   [oec-subtematica tematica="nutricion-deportiva,antropometria"
       title="Antropometría y composición corporal"
       texto="Medir bien es el primer paso para…"
       lista="Más formaciones de antropometría"]

   - tematica: las etiquetas que tiene que tener la formación (TODAS). La
     primera es la de la landing (sale en el rótulo "Dentro de …").
   - La principal es la de mayor relevancia (y, a igual relevancia, la de
     más peso: alumnos y calificación, ver oec_destacada_datos()). Va
     grande, con portada, docentes y datos. El resto, en una lista al lado,
     por fecha de inicio. Cada una se presenta como una formación
     independiente: aunque algunas sean módulos o tramos de la principal,
     acá no se dice (a pedido de Mario: en el área conviven formaciones que
     no tienen nada que ver con ella) — rótulo = su tipo, y el título sin
     el sufijo "- MOD N".
   - Sin video ni imagen de fondo a propósito: solo imágenes del catálogo
     (portada y fotos de docentes), nada que subir ni mantener a mano. El
     fondo es el oscuro de la temática con una regla graduada en CSS.
   - Sin formaciones abiertas con esas etiquetas, no sale nada. Las que
     cierran inscripción con la página en caché las saca main.js
     (data-cierre), igual que en las destacadas.

   Todo sale del catálogo sincronizado (OEC_AI_Catalog), sin API en vivo.
   ============================================================ */

/** Desempate del orden de la lista (a igual fecha de inicio): lo más abarcador primero. */
const OEC_SUB_NIVELES = [ 'master' => 0, 'posgrado' => 1, 'diplomado' => 2, 'certificacion' => 3, 'curso' => 4, 'taller' => 5, 'webinar' => 6 ];

/** Formaciones abiertas que tienen todas las etiquetas, con los datos de las destacadas. */
function oec_subtematica_formaciones( array $tags ): array {
	if ( ! $tags || ! function_exists( 'oec_tira_catalogo' ) || ! function_exists( 'oec_destacada_datos' ) ) {
		return [];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_sub_' . md5( implode( ',', $tags ) . '|' . ( $meta['finished_at'] ?? '' ) . '|' . OEC_THEME_VERSION );
	$out  = get_transient( $key );
	if ( ! is_array( $out ) ) {
		$out = [];
		foreach ( oec_tira_catalogo() as $r ) {
			if ( array_diff( $tags, $r['tags'] ) ) {
				continue;
			}
			$f = OEC_AI_Catalog::get_formation( (string) $r['id'] );
			if ( $f ) {
				$out[] = oec_destacada_datos( $r, $f ) + [ 'relevance' => (int) $r['relevance'], 'org' => (string) ( $r['org'] ?? '' ) ];
			}
		}
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	}
	$hoy = current_time( 'Y-m-d' );
	return array_values( array_filter( $out, fn( $d ) => ! $d['enrollment_end'] || $d['enrollment_end'] >= $hoy ) );
}

/** "2027-03-13" → "marzo de 2027"; asincrónica → "Empezá cuando quieras". */
function oec_subtematica_inicio( array $d ): string {
	if ( in_array( $d['synchronicity'], [ 'ASYNC', 'ASYNC-F' ], true ) ) {
		return __( 'Empezá cuando quieras', 'oec-theme' );
	}
	if ( ! preg_match( '/^(\d{4})-(\d{2})/', (string) $d['start'], $m ) ) {
		return '';
	}
	$meses = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
	/* translators: 1: mes, 2: año */
	return sprintf( __( '%1$s de %2$s', 'oec-theme' ), $meses[ (int) $m[2] - 1 ], $m[1] );
}

/**
 * Rótulo y título para la lista: [ tipo, título ]. Al título se le saca el
 * sufijo "- MOD 1" con que vienen los módulos de un programa: en la lista
 * cada formación va como independiente, sin referencia a otra.
 */
function oec_subtematica_item_titulo( array $d ): array {
	$title = trim( (string) preg_replace( '/\s*[-–—]\s*M[OÓ]D(?:ULO)?\.?\s*\d+\s*$/iu', '', trim( (string) $d['title'] ) ) );
	return [ oec_subtematica_tipo( $d ), $title ];
}

/**
 * Resumen sin los titulares en MAYÚSCULAS con que arrancan algunas fichas
 * ("EL PRIMER MÁSTER… BEHAP ACADEMYLa composición corporal es…": al sacar
 * el HTML quedan pegados al párrafo que sigue). Se corta donde una palabra
 * en mayúsculas choca con una que empieza en mayúscula y sigue en
 * minúsculas, y se descartan los tramos gritados. Si no queda nada
 * legible, el texto original.
 */
function oec_subtematica_resumen( string $text, int $max ): string {
	$partes = preg_split( '/(?<=[A-ZÁÉÍÓÚÑ\)\.])(?=[A-ZÁÉÍÓÚÑ][a-záéíóúñ])/u', $text ) ?: [ $text ];
	$partes = array_filter( $partes, function ( $p ) {
		$letras = preg_replace( '/[^\p{L}]/u', '', $p );
		return '' !== $letras && mb_strtoupper( $letras ) !== $letras;
	} );
	$limpio = trim( implode( ' ', array_map( 'trim', $partes ) ) );
	return oec_destacada_recortar( mb_strlen( $limpio ) >= 60 ? $limpio : $text, $max );
}

/** Tipo para mostrar: "Master" (como viene de la API) → "Máster". */
function oec_subtematica_tipo( array $d ): string {
	$tipo = oec_tira_capitalize( (string) $d['type'] );
	return 'Master' === $tipo ? 'Máster' : $tipo;
}

add_shortcode( 'oec-subtematica', 'oec_render_subtematica_shortcode' );
function oec_render_subtematica_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'tematica' => '',
		'title'    => '',
		'texto'    => '',
		'lista'    => __( 'Más formaciones de esta área', 'oec-theme' ),
		'limit'    => 10,
	], $atts, 'oec-subtematica' );

	$tags = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $atts['tematica'] ) ) ) );
	$all  = oec_subtematica_formaciones( $tags );
	if ( ! $all ) {
		return '';
	}

	// La principal: mayor relevancia; a igual relevancia, más peso.
	usort( $all, fn( $a, $b ) => [ $b['relevance'], $b['peso'] ] <=> [ $a['relevance'], $a['peso'] ] );
	$main  = array_shift( $all );
	// El resto, por fecha de inicio (a igual fecha, lo más abarcador primero).
	$nivel = fn( $d ) => OEC_SUB_NIVELES[ sanitize_title( (string) $d['type'] ) ] ?? 9;
	usort( $all, fn( $a, $b ) => [ $a['start'], $nivel( $a ) ] <=> [ $b['start'], $nivel( $b ) ] );
	$resto = array_slice( $all, 0, max( 0, (int) $atts['limit'] ) );

	$padre  = function_exists( 'oec_destacada_tematica_nombre' ) ? oec_destacada_tematica_nombre( $tags[0] ) : '';
	$inicio = oec_subtematica_inicio( $main );
	$tipo   = oec_subtematica_tipo( $main );
	$n_doc  = count( $main['docentes'] );

	ob_start();
	?>
	<section class="oec-sub" aria-labelledby="oec-sub-title-<?php echo esc_attr( end( $tags ) ); ?>">
		<header class="oec-sub__head">
			<?php if ( $padre ) : ?>
			<span class="oec-sub__eyebrow"><?php echo esc_html( sprintf( /* translators: %s: temática de la landing */ __( 'Dentro de %s', 'oec-theme' ), $padre ) ); ?></span>
			<?php endif; ?>
			<h2 class="oec-sub__title" id="oec-sub-title-<?php echo esc_attr( end( $tags ) ); ?>"><?php echo esc_html( $atts['title'] ?: oec_destacada_tematica_nombre( end( $tags ) ) ); ?></h2>
			<?php if ( $atts['texto'] ) : ?>
			<p class="oec-sub__text"><?php echo esc_html( $atts['texto'] ); ?></p>
			<?php endif; ?>
		</header>

		<div class="oec-sub__layout<?php echo $resto ? '' : ' oec-sub__layout--solo'; ?>">
			<a class="oec-sub__main" href="<?php echo esc_url( $main['url'] ); ?>"<?php echo $main['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput -- armado por el tema ?> data-oec-sub-item data-cierre="<?php echo esc_attr( $main['cierre'] ); ?>">
				<span class="oec-sub__cover"><?php echo oec_destacada_portada( $main, 1200 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="oec-sub__main-body">
					<span class="oec-sub__chips">
						<span class="oec-sub__chip oec-sub__chip--acc"><?php echo esc_html( $tipo ); ?></span>
						<?php if ( $main['org'] ) : ?><span class="oec-sub__chip"><?php echo esc_html( $main['org'] ); ?></span><?php endif; ?>
					</span>
					<strong class="oec-sub__main-title"><?php echo esc_html( $main['title'] ); ?></strong>
					<?php if ( $main['resumen'] ) : ?>
					<span class="oec-sub__main-desc"><?php echo esc_html( oec_subtematica_resumen( $main['resumen'], 210 ) ); ?></span>
					<?php endif; ?>
					<span class="oec-sub__facts">
						<?php if ( $main['horas'] > 0 ) : ?><span><i class="bi bi-clock" aria-hidden="true"></i> <?php echo esc_html( sprintf( /* translators: %s: horas */ __( '%s horas', 'oec-theme' ), number_format_i18n( $main['horas'] ) ) ); ?></span><?php endif; ?>
						<?php if ( $inicio ) : ?><span><i class="bi bi-calendar3" aria-hidden="true"></i> <?php echo esc_html( in_array( $main['synchronicity'], [ 'ASYNC', 'ASYNC-F' ], true ) ? $inicio : sprintf( /* translators: %s: mes y año */ __( 'Empieza en %s', 'oec-theme' ), $inicio ) ); ?></span><?php endif; ?>
						<?php if ( $n_doc > 1 ) : ?><span><i class="bi bi-people" aria-hidden="true"></i> <?php echo esc_html( sprintf( /* translators: %d: docentes */ __( '%d docentes', 'oec-theme' ), $n_doc ) ); ?></span><?php endif; ?>
					</span>
					<span class="oec-sub__foot">
						<?php echo oec_destacada_caras( $main, 'oec-sub__caras', 96, 5 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="oec-sub__cta"><?php echo esc_html( 'Máster' === $tipo ? __( 'Ver el máster', 'oec-theme' ) : __( 'Ver la formación', 'oec-theme' ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
					</span>
				</span>
			</a>

			<?php if ( $resto ) : ?>
			<div class="oec-sub__aside">
				<h3 class="oec-sub__aside-title"><?php echo esc_html( $atts['lista'] ); ?></h3>
				<ul class="oec-sub__list">
					<?php foreach ( $resto as $d ) :
						[ $rotulo, $titulo ] = oec_subtematica_item_titulo( $d );
						$cuando              = oec_subtematica_inicio( $d );
						?>
					<li data-oec-sub-item data-cierre="<?php echo esc_attr( $d['cierre'] ); ?>">
						<a class="oec-sub__item" href="<?php echo esc_url( $d['url'] ); ?>"<?php echo $d['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<span class="oec-sub__item-label"><?php echo esc_html( $rotulo ); ?></span>
							<span class="oec-sub__item-title"><?php echo esc_html( $titulo ); ?></span>
							<span class="oec-sub__item-meta">
								<?php echo esc_html( implode( ' · ', array_filter( [ $cuando, $d['horas'] > 0 ? sprintf( /* translators: %s: horas */ __( '%s h', 'oec-theme' ), number_format_i18n( $d['horas'] ) ) : '' ] ) ) ); ?>
							</span>
							<i class="bi bi-arrow-right oec-sub__item-arrow" aria-hidden="true"></i>
						</a>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}
