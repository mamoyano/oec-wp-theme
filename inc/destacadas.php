<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   FORMACIONES DESTACADAS DE UNA TEMÁTICA — las "bombas" de cada landing
   Las de relevancia 2 (la máxima de la API: 0, 1 y 2), abiertas y con la
   etiqueta de la temática, salen en tres lugares:
    - [oec-estrella]: en el hero, en lugar del widget de créditos. Rota
      entre todas, con más chances para las que tienen más alumnos y mejor
      calificación (la elige main.js al cargar: Cloudflare cachea la página
      y una elección hecha en PHP sería la misma para todos).
    - [oec-imperdible]: una sección fija para la número 1 de la temática.
    - "Antes de irte": aviso en una esquina para quien se va sin mirarla.
      Lo arma [oec-imperdible], solo en las temáticas con muchas formaciones.
   Todo sale del catálogo sincronizado (OEC_AI_Catalog), sin API en vivo.

   Solo usa imágenes que ya vienen en el catálogo: la portada de la
   formación (una panorámica 4:1) y la foto de cada docente, que puede ser
   de cualquier encuadre y con fondo — por eso van recortadas en círculos o
   tarjetas, todas del mismo tamaño: si son dos o tres docentes, ninguno
   aparece por encima de los otros.
   ============================================================ */

/**
 * Destacadas de la temática, de la más a la menos fuerte (por "peso").
 * Cacheado hasta el próximo sync; las que ya cerraron inscripción se sacan
 * al leer (el catálogo recién se entera en el sync siguiente). Como
 * Cloudflare guarda la página hasta un día, las piezas llevan además su
 * cierre en data-cierre y el navegador las esconde apenas pasa.
 */
function oec_destacadas( string $tematica ): array {
	if ( '' === $tematica || ! function_exists( 'oec_tira_catalogo' ) ) {
		return [];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_destacadas_' . md5( $tematica . '|' . ( $meta['finished_at'] ?? '' ) . '|' . OEC_THEME_VERSION );
	$out  = get_transient( $key );
	if ( ! is_array( $out ) ) {
		$out = [];
		foreach ( oec_tira_catalogo() as $r ) {
			if ( $r['relevance'] < 2 || ! in_array( $tematica, $r['tags'], true ) ) {
				continue;
			}
			$f = OEC_AI_Catalog::get_formation( (string) $r['id'] );
			if ( $f ) {
				$out[] = oec_destacada_datos( $r, $f );
			}
		}
		usort( $out, fn( $a, $b ) => $b['peso'] <=> $a['peso'] );
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	}

	$hoy = current_time( 'Y-m-d' );
	return array_values( array_filter( $out, fn( $d ) => ! $d['enrollment_end'] || $d['enrollment_end'] >= $hoy ) );
}

/** Lo que muestran las tres piezas, armado desde la fila de la tira y la ficha. */
function oec_destacada_datos( array $r, array $f ): array {
	$alumnos = (int) ( $f['total_students'] ?? 0 );
	$prom    = (float) ( $r['reviews']['average'] ?? 0 );
	$n_op    = (int) ( $r['reviews']['count'] ?? 0 );

	// Peso de la rotación: los alumnos pesan, pero con raíz (^0.6) para que
	// 7.000 alumnos no tapen del todo a las de 100; la calificación ajusta.
	$peso = round( max( $alumnos, 10 ) ** 0.6 * ( $prom ?: 4.5 ) / 5, 2 );

	// Docentes, en el orden de la ficha.
	$docentes = [];
	foreach ( $f['teachers'] ?? [] as $t ) {
		$slug = oec_docente_slug( (string) ( $t['name'] ?? '' ) );
		if ( '' === $slug ) {
			continue;
		}
		$docentes[] = [
			'slug'    => $slug,
			'name'    => oec_destacada_nombre( (string) $t['name'] ),
			'bio'     => oec_destacada_recortar( oec_destacada_bio( (string) ( $t['bio'] ?? '' ) ), 280 ),
			'photo'   => (string) ( $t['photo'] ?? '' ),
			'area'    => trim( (string) ( $t['background'] ?? '' ) ),
		];
	}

	// Opiniones: 5 estrellas, con algo para decir pero sin ser un testamento.
	// La foto viene como ruta del campus (mismo criterio que inc/opiniones.php):
	// la genérica "user-default" cuenta como sin foto.
	$opiniones = array_map( function ( $o ) {
		$img        = (string) ( $o['image'] ?? '' );
		$o['image'] = '' === $img || false !== strpos( $img, 'user-default' ) ? '' : 'https://imgrsize.oe-img.center' . $img . '?w=96&q=85&format=webp';
		return $o;
	}, $f['reviews'] ?? [] );
	$opiniones = array_values( array_filter( $opiniones, fn( $o ) => (int) $o['rating'] >= 5 && mb_strlen( trim( (string) $o['comment'] ) ) >= 40 ) );
	usort( $opiniones, fn( $a, $b ) => [ '' === $a['image'], mb_strlen( $b['comment'] ) ] <=> [ '' === $b['image'], mb_strlen( $a['comment'] ) ] );
	$opiniones = array_map( fn( $o ) => [
		'author'  => oec_destacada_nombre( (string) $o['author'] ),
		'image'   => (string) $o['image'],
		'comment' => oec_destacada_recortar( (string) $o['comment'], 230 ),
	], array_slice( $opiniones, 0, 3 ) );

	return [
		'id'             => $r['id'],
		'slug'           => $r['slug'],
		'url'            => oec_formation_url( $r ),
		'attrs'          => oec_formation_link_attrs( $r ),
		'title'          => $r['title'],
		'type'           => $r['type'],
		'image'          => $r['image'],
		'edition_number' => $r['edition_number'],
		'synchronicity'  => $r['synchronicity'],
		'start'          => $r['start'],
		'enrollment_end' => $r['enrollment_end'],
		// Fin del día del cierre en la hora del sitio (mismo criterio que las cards); '' si es asincrónica.
		'cierre'         => $r['enrollment_end'] && ! in_array( $r['synchronicity'], [ 'ASYNC', 'ASYNC-F' ], true )
			? ( new DateTime( $r['enrollment_end'] . ' 23:59:59', wp_timezone() ) )->format( 'c' ) : '',
		'alumnos'        => $alumnos,
		'horas'          => (int) ( $f['lecture_hours'] ?? 0 ),
		'promedio'       => $prom,
		'opiniones_n'    => $n_op,
		'docentes'       => $docentes,
		'opiniones'      => $opiniones,
		'objetivos'      => oec_destacada_objetivos( (string) ( $f['objectives'] ?? '' ) ),
		// Para las fichas sin objetivos: de qué se trata y para quién es.
		'resumen'        => oec_destacada_recortar( oec_destacada_espacios( (string) ( $r['description'] ?? '' ) ), 340 ),
		'para_quien'     => oec_destacada_recortar( oec_destacada_espacios( (string) ( $f['target_audience'] ?? '' ) ), 220 ),
		'peso'           => $peso,
	];
}

/** "Prof. Diego A. Bonilla Ocampo , MSc" → "Diego A. Bonilla Ocampo"; MAYÚSCULAS → Nombre Propio. */
function oec_destacada_nombre( string $name ): string {
	$name = preg_replace( '/^(?:(?:dra?|lic|licda?|prof|profa|mg|mgter|msc|phd|ing|mtro|mtra)\.?\s+)+/iu', '', trim( $name ) );
	$name = trim( preg_replace( [ '/\s*,.*$/u', '/\s+/u' ], [ '', ' ' ], (string) $name ) );
	return $name === mb_strtoupper( $name ) || $name === mb_strtolower( $name ) ? mb_convert_case( $name, MB_CASE_TITLE ) : $name;
}

/** Bio del docente sin los textos de los links a redes ("Seguir en Instagram"). */
function oec_destacada_bio( string $bio ): string {
	$bio = oec_docente_clean_text( $bio );
	$bio = preg_replace( '/\s*(?:seguir|seguime|seguilo|seguila|follow)\s+(?:en|on)\s+(?:instagram|twitter|x|linkedin|facebook|youtube|tiktok)\.?/iu', '', $bio );
	// Los renglones de la ficha llegan pegados ("Buenos AiresMagíster"): un
	// separador donde termina una palabra en minúscula y arranca otra con
	// mayúscula (dos minúsculas antes, para no partir "McGregor"; o una
	// sigla: "ISAKProfesional").
	$bio = preg_replace( '/(?:(?<=\p{Ll}{2})|(?<=\p{Lu}{3}))(?=\p{Lu}\p{Ll})/u', ' · ', (string) $bio );
	return trim( oec_destacada_espacios( (string) $bio ) );
}

/** "deportista.Después" → "deportista. Después" (oraciones pegadas en los textos de la ficha). */
function oec_destacada_espacios( string $text ): string {
	return (string) preg_replace( '/(\p{Ll}[.!?:])(?=\p{Lu}\p{Ll})/u', '$1 ', $text );
}

/** Corta en el último espacio antes de $max y agrega "…". */
function oec_destacada_recortar( string $text, int $max ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max );
	return rtrim( mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) ?: $max ), ' ,.;:' ) . '…';
}

/** Hasta 3 objetivos cortos, de las oraciones del texto de objetivos de la ficha. */
function oec_destacada_objetivos( string $text ): array {
	$text = trim( preg_replace( [ '/^\s*objetivos?\s*(generales?)?\s*:?\s*/iu', '/\s+/u' ], [ '', ' ' ], $text ) );
	$out  = [];
	foreach ( preg_split( '/(?<=[.;])\s+|\s*•\s*/u', $text ) ?: [] as $s ) {
		$s = rtrim( trim( $s ), '.; ' );
		if ( mb_strlen( $s ) >= 25 && mb_strlen( $s ) <= 170 ) {
			$out[] = $s;
		}
		if ( 3 === count( $out ) ) {
			break;
		}
	}
	return $out;
}

/** Nombre de la temática para los textos ("Nutrición Deportiva"). */
function oec_destacada_tematica_nombre( string $tematica ): string {
	foreach ( oec_especiales_registradas() as $e ) {
		if ( $e['tematica'] === $tematica ) {
			return $e['title'];
		}
	}
	return ucwords( str_replace( '-', ' ', $tematica ) );
}

/** "Francis Holway" / "Romina Garavaglia y Ana Pérez" / "A, B y C". */
function oec_destacada_docentes_txt( array $d ): string {
	$names = array_column( array_slice( $d['docentes'], 0, 3 ), 'name' );
	$last  = array_pop( $names );
	return $names ? implode( ', ', $names ) . ' ' . __( 'y', 'oec-theme' ) . ' ' . $last : (string) $last;
}

/** Línea de fecha: hasta cuándo se puede anotar (o que arranca cuando quiera). */
function oec_destacada_fecha_txt( array $d ): string {
	if ( in_array( $d['synchronicity'], [ 'ASYNC', 'ASYNC-F' ], true ) ) {
		return __( 'Empezá cuando quieras', 'oec-theme' );
	}
	if ( ! $d['enrollment_end'] ) {
		return '';
	}
	$meses = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
	$fecha = (int) substr( $d['enrollment_end'], 8, 2 ) . ' de ' . $meses[ (int) substr( $d['enrollment_end'], 5, 2 ) - 1 ];
	return sprintf( __( 'Inscripción abierta hasta el %s', 'oec-theme' ), $fecha );
}

/**
 * Portada de la formación (la misma imagen que las cards, por imgrsize, que
 * elige AVIF/WebP). $lazy: con data-src en vez de src — la pone el JS al
 * mostrarla (las destacadas del hero que no salen, el aviso de salida).
 */
function oec_destacada_portada( array $d, int $w, string $loading = 'lazy' ): string {
	if ( ! $d['image'] ) {
		return '';
	}
	$src = 'https://imgrsize.oe-img.center/campus/capacitacion/imagen/' . basename( (string) wp_parse_url( $d['image'], PHP_URL_PATH ) ) . '?w=' . $w . '&q=80';
	return sprintf(
		'<img %1$s="%2$s" alt="" width="1200" height="300" decoding="async"%3$s>',
		'data' === $loading ? 'data-src' : 'src',
		esc_url( $src ),
		'lazy' === $loading ? ' loading="lazy"' : ''
	);
}

/**
 * Fotos de los docentes (hasta $max, todas iguales) + "+N" si hay más.
 * Sin foto, un círculo con las iniciales.
 */
function oec_destacada_caras( array $d, string $class, int $w, int $max = 3, string $loading = 'lazy' ): string {
	$docs = $d['docentes'];
	if ( ! $docs ) {
		return '';
	}
	$out = '';
	foreach ( array_slice( $docs, 0, $max ) as $doc ) {
		if ( $doc['photo'] ) {
			$out .= sprintf(
				'<span class="%1$s__cara"><img %2$s="%3$s" alt="%4$s" title="%4$s" width="%5$d" height="%5$d" decoding="async"%6$s></span>',
				esc_attr( $class ),
				'data' === $loading ? 'data-src' : 'src',
				esc_url( oec_docente_photo_url( $doc['photo'], $w ) ),
				esc_attr( $doc['name'] ),
				$w,
				'lazy' === $loading ? ' loading="lazy"' : ''
			);
		} else {
			$ini  = implode( '', array_map( fn( $p ) => mb_substr( $p, 0, 1 ), array_slice( preg_split( '/\s+/u', $doc['name'] ) ?: [], 0, 2 ) ) );
			$out .= '<span class="' . esc_attr( $class ) . '__cara ' . esc_attr( $class ) . '__cara--ini" title="' . esc_attr( $doc['name'] ) . '">' . esc_html( mb_strtoupper( $ini ) ) . '</span>';
		}
	}
	if ( count( $docs ) > $max ) {
		$out .= '<span class="' . esc_attr( $class ) . '__cara ' . esc_attr( $class ) . '__cara--mas">+' . ( count( $docs ) - $max ) . '</span>';
	}
	return '<div class="' . esc_attr( $class ) . '" data-n="' . min( count( $docs ), $max ) . '">' . $out . '</div>';
}

/** "★ 4,9 · 2.169 opiniones" — "7.155 alumnos" — "22ª edición". */
function oec_destacada_stats( array $d, string $class ): string {
	$items = [];
	if ( $d['opiniones_n'] > 0 ) {
		$items[] = '<li><i class="bi bi-star-fill" aria-hidden="true"></i> <strong>' . esc_html( number_format( $d['promedio'], 1, ',', '.' ) ) . '</strong> <span>(' . esc_html( sprintf( _n( '%s opinión', '%s opiniones', $d['opiniones_n'], 'oec-theme' ), number_format_i18n( $d['opiniones_n'] ) ) ) . ')</span></li>';
	}
	if ( $d['alumnos'] > 0 ) {
		$items[] = '<li><i class="bi bi-people-fill" aria-hidden="true"></i> <strong>' . esc_html( number_format_i18n( $d['alumnos'] ) ) . '</strong> <span>' . esc_html__( 'alumnos', 'oec-theme' ) . '</span></li>';
	}
	if ( $d['edition_number'] > 1 ) {
		$items[] = '<li><i class="bi bi-arrow-repeat" aria-hidden="true"></i> <strong>' . (int) $d['edition_number'] . 'ª</strong> <span>' . esc_html__( 'edición', 'oec-theme' ) . '</span></li>';
	}
	return $items ? '<ul class="' . esc_attr( $class ) . '">' . implode( '', $items ) . '</ul>' : '';
}

/**
 * [oec-estrella tematica="nutricion-deportiva" max="6"]
 *
 * Tarjeta del hero de la landing: portada de una formación destacada de la
 * temática, con sus docentes "presentándola". Salen hasta "max" (las de más peso); main.js
 * elige una al azar según data-peso y deja las flechas para pasar a las
 * otras. Todas ocupan la misma celda de la grilla, así cambiar de una a
 * otra no mueve la página. Sin destacadas, no sale nada (y el hero queda
 * con el texto solo).
 */
add_shortcode( 'oec-estrella', 'oec_render_estrella_shortcode' );
function oec_render_estrella_shortcode( $atts ): string {
	$atts  = shortcode_atts( [ 'tematica' => '', 'max' => 6 ], $atts, 'oec-estrella' );
	$items = array_slice( oec_destacadas( sanitize_title( $atts['tematica'] ) ), 0, max( 1, (int) $atts['max'] ) );
	if ( ! $items ) {
		// Sin destacadas abiertas: el hero vuelve a tener los créditos (y
		// [oec-suscribite], más abajo, no sale, para no repetirlos).
		return '<div class="oec-tematica-hero__credits">' . do_shortcode( '[oec-credits-widget tematica="' . esc_attr( sanitize_title( $atts['tematica'] ) ) . '"]' ) . '</div>';
	}

	ob_start();
	?>
	<div class="oec-estrella" data-oec-estrella>
		<div class="oec-estrella__stack">
		<?php foreach ( $items as $i => $d ) :
			$carga   = 0 === $i ? 'eager' : 'data';
			$quien   = oec_destacada_docentes_txt( $d );
			$quote   = $d['opiniones'][0] ?? null;
			$fecha   = oec_destacada_fecha_txt( $d );
			?>
			<article class="oec-estrella__item<?php echo 0 === $i ? ' is-active' : ''; ?>" data-peso="<?php echo esc_attr( $d['peso'] ); ?>" data-cierre="<?php echo esc_attr( $d['cierre'] ); ?>"<?php echo 0 === $i ? '' : ' aria-hidden="true"'; ?>>
				<div class="oec-estrella__portada">
					<?php echo oec_destacada_portada( $d, 800, $carga ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					<span class="oec-estrella__kicker"><i class="bi bi-stars" aria-hidden="true"></i> <?php esc_html_e( 'Imperdible', 'oec-theme' ); ?></span>
				</div>
				<div class="oec-estrella__body">
					<?php echo oec_destacada_caras( $d, 'oec-estrella__caras', 144, 3, $carga ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					<?php if ( $quien ) : ?>
					<p class="oec-estrella__presenta"><?php echo esc_html( sprintf( count( $d['docentes'] ) > 1 ? __( '%s te presentan', 'oec-theme' ) : __( '%s te presenta', 'oec-theme' ), $quien ) ); ?></p>
					<?php endif; ?>
					<h2 class="oec-estrella__title"><a href="<?php echo esc_url( $d['url'] ); ?>"<?php echo $d['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput ?> tabindex="<?php echo 0 === $i ? '0' : '-1'; ?>"><?php echo esc_html( $d['title'] ); ?></a></h2>
					<?php echo oec_destacada_stats( $d, 'oec-estrella__stats' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
					<?php if ( $quote ) : ?>
					<blockquote class="oec-estrella__quote">“<?php echo esc_html( oec_destacada_recortar( $quote['comment'], 120 ) ); ?>” <cite>— <?php echo esc_html( $quote['author'] ); ?></cite></blockquote>
					<?php endif; ?>
					<div class="oec-estrella__foot">
						<a class="oec-estrella__cta" href="<?php echo esc_url( $d['url'] ); ?>"<?php echo $d['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput ?> tabindex="<?php echo 0 === $i ? '0' : '-1'; ?>"><?php echo esc_html( sprintf( __( 'Ver %s', 'oec-theme' ), mb_strtolower( $d['type'] ) ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
						<?php if ( $fecha ) : ?><span class="oec-estrella__fecha"><i class="bi bi-calendar-check" aria-hidden="true"></i> <?php echo esc_html( $fecha ); ?></span><?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<?php if ( count( $items ) > 1 ) : ?>
		<div class="oec-estrella__nav">
			<button type="button" class="oec-estrella__arrow" data-dir="-1" aria-label="<?php esc_attr_e( 'Destacada anterior', 'oec-theme' ); ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
			<span class="oec-estrella__count"><span data-oec-estrella-n>1</span> / <span data-oec-estrella-total><?php echo count( $items ); ?></span> <?php esc_html_e( 'imperdibles', 'oec-theme' ); ?></span>
			<button type="button" class="oec-estrella__arrow" data-dir="1" aria-label="<?php esc_attr_e( 'Destacada siguiente', 'oec-theme' ); ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
		</div>
		<?php endif; ?>
	</div>
	<?php
	// Inline (no en main.js, que va diferido): elige antes del primer
	// pintado, así no se ve una destacada y enseguida otra. Antes saca las
	// que cerraron inscripción desde que se guardó la página.
	?>
	<script>
	(function (box) {
		var ahora = Date.now(), cur = 0, n = box.querySelector('[data-oec-estrella-n]'), nav = box.querySelector('.oec-estrella__nav'), items = [];
		box.querySelectorAll('.oec-estrella__item').forEach(function (it) {
			Date.parse(it.dataset.cierre || '') < ahora ? it.remove() : items.push(it);
		});
		if (!items.length) { box.remove(); return; }
		if (nav && items.length < 2) nav.remove();
		else if (nav) nav.querySelector('[data-oec-estrella-total]').textContent = items.length;
		function show(i) {
			cur = (i + items.length) % items.length;
			items.forEach(function (it, j) {
				var on = j === cur;
				it.classList.toggle('is-active', on);
				on ? it.removeAttribute('aria-hidden') : it.setAttribute('aria-hidden', 'true');
				it.querySelectorAll('a').forEach(function (a) { a.tabIndex = on ? 0 : -1; });
				if (on) it.querySelectorAll('img[data-src]').forEach(function (img) { img.src = img.dataset.src; img.removeAttribute('data-src'); });
			});
			if (n) n.textContent = cur + 1;
		}
		var total = 0, r, i;
		items.forEach(function (it) { total += +it.dataset.peso || 0; });
		r = Math.random() * total;
		for (i = 0; i < items.length - 1 && (r -= +items[i].dataset.peso || 0) > 0; i++);
		show(i);
		box.querySelectorAll('[data-dir]').forEach(function (b) {
			b.addEventListener('click', function () { show(cur + +b.dataset.dir); });
		});
	})(document.currentScript.previousElementSibling);
	</script>
	<?php
	return ob_get_clean();
}

/**
 * [oec-imperdible tematica="nutricion-deportiva" formacion="" antes-de-irte="30"]
 *
 * Sección fija para la destacada número 1 de la temática (o la del slug de
 * "formacion", para elegirla a mano): portada, docentes (todos iguales),
 * por qué hacerla, qué dicen los alumnos. Si la temática tiene al menos "antes-de-irte"
 * formaciones abiertas, agrega el aviso de salida (0 = nunca).
 */
add_shortcode( 'oec-imperdible', 'oec_render_imperdible_shortcode' );
function oec_render_imperdible_shortcode( $atts ): string {
	$atts     = shortcode_atts( [ 'tematica' => '', 'formacion' => '', 'antes-de-irte' => 30 ], $atts, 'oec-imperdible' );
	$tematica = sanitize_title( $atts['tematica'] );
	$items    = oec_destacadas( $tematica );
	if ( $atts['formacion'] ) {
		$items = array_values( array_filter( $items, fn( $d ) => $d['slug'] === $atts['formacion'] ) ) ?: $items;
	}
	$d = $items[0] ?? null;
	if ( ! $d ) {
		return oec_destacada_vacia();
	}

	$nombre  = oec_destacada_tematica_nombre( $tematica );
	$n_docs  = count( $d['docentes'] );
	$bio_max = [ 1 => 300, 2 => 150 ][ $n_docs ] ?? 0; // con tres o más, solo nombre y especialidad
	$fecha   = oec_destacada_fecha_txt( $d );

	// ¿Es la más elegida de la temática? (entre todas las abiertas, no solo las destacadas)
	$abiertas  = array_filter( OEC_AI_Catalog::get_index(), fn( $f ) => in_array( $tematica, $f['tags'] ?? [], true ) );
	$max_alum  = max( array_map( fn( $f ) => (int) ( $f['total_students'] ?? 0 ), $abiertas ) ?: [ 0 ] );
	$eyebrow   = $d['alumnos'] > 0 && $d['alumnos'] >= $max_alum
		? sprintf( __( 'La formación más elegida en %s', 'oec-theme' ), $nombre )
		: sprintf( __( 'Imperdible en %s', 'oec-theme' ), $nombre );

	ob_start();
	?>
	<div class="oec-imperdible" data-oec-imperdible data-cierre="<?php echo esc_attr( $d['cierre'] ); ?>">
		<div class="oec-imperdible__portada">
			<?php echo oec_destacada_portada( $d, 1280 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
		</div>
		<div class="oec-imperdible__head">
			<span class="oec-imperdible__eyebrow"><i class="bi bi-trophy-fill" aria-hidden="true"></i> <?php echo esc_html( $eyebrow ); ?></span>
			<h2 class="oec-imperdible__title"><?php echo esc_html( $d['title'] ); ?></h2>
		</div>
		<?php if ( $d['docentes'] ) : ?>
		<div class="oec-imperdible__docentes" data-n="<?php echo (int) min( $n_docs, 3 ); ?>">
			<?php foreach ( array_slice( $d['docentes'], 0, 3 ) as $doc ) : ?>
			<figure class="oec-imperdible__docente">
				<?php if ( $doc['photo'] ) : ?>
				<div class="oec-imperdible__docente-foto"><img src="<?php echo esc_url( oec_docente_photo_url( $doc['photo'], 1 === $n_docs ? 520 : 360 ) ); ?>" alt="<?php echo esc_attr( $doc['name'] ); ?>" width="480" height="600" loading="lazy" decoding="async"></div>
				<?php endif; ?>
				<figcaption>
					<strong><?php echo esc_html( $doc['name'] ); ?></strong>
					<?php if ( $doc['area'] ) : ?><span class="oec-imperdible__docente-area"><?php echo esc_html( $doc['area'] ); ?></span><?php endif; ?>
					<?php if ( $bio_max && $doc['bio'] ) : ?><span class="oec-imperdible__docente-bio"><?php echo esc_html( oec_destacada_recortar( $doc['bio'], $bio_max ) ); ?></span><?php endif; ?>
				</figcaption>
			</figure>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="oec-imperdible__body">
			<?php echo oec_destacada_stats( $d, 'oec-imperdible__stats' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<?php if ( $d['objetivos'] ) : ?>
			<p class="oec-imperdible__sub"><?php esc_html_e( 'Qué te llevás', 'oec-theme' ); ?></p>
			<ul class="oec-imperdible__puntos">
				<?php foreach ( $d['objetivos'] as $o ) : ?><li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?php echo esc_html( $o ); ?></li><?php endforeach; ?>
			</ul>
			<?php else : ?>
				<?php if ( $d['resumen'] ) : ?>
			<p class="oec-imperdible__sub"><?php esc_html_e( 'De qué se trata', 'oec-theme' ); ?></p>
			<p class="oec-imperdible__texto"><?php echo esc_html( $d['resumen'] ); ?></p>
				<?php endif; ?>
				<?php if ( $d['para_quien'] ) : ?>
			<p class="oec-imperdible__sub"><?php esc_html_e( 'Para quién es', 'oec-theme' ); ?></p>
			<p class="oec-imperdible__texto"><?php echo esc_html( $d['para_quien'] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<div class="oec-imperdible__actions">
				<a class="oec-imperdible__cta" href="<?php echo esc_url( $d['url'] ); ?>"<?php echo $d['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e( 'Conocé la formación', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
				<?php if ( $fecha ) : ?><span class="oec-imperdible__fecha"><i class="bi bi-calendar-check" aria-hidden="true"></i> <?php echo esc_html( $fecha ); ?></span><?php endif; ?>
			</div>
		</div>
		<?php if ( $d['opiniones'] ) : ?>
		<div class="oec-imperdible__opiniones">
			<?php foreach ( $d['opiniones'] as $o ) : ?>
			<figure class="oec-imperdible__opinion">
				<div class="oec-imperdible__stars" aria-label="<?php esc_attr_e( '5 estrellas', 'oec-theme' ); ?>"><?php echo str_repeat( '<i class="bi bi-star-fill" aria-hidden="true"></i>', 5 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<blockquote>“<?php echo esc_html( $o['comment'] ); ?>”</blockquote>
				<figcaption>
					<?php if ( $o['image'] ) : ?><img src="<?php echo esc_url( $o['image'] ); ?>" alt="" width="40" height="40" loading="lazy" decoding="async"><?php endif; ?>
					<?php echo esc_html( $o['author'] ); ?>
				</figcaption>
			</figure>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
	$min = (int) $atts['antes-de-irte'];
	if ( $min > 0 && count( $abiertas ) >= $min ) {
		echo oec_render_antes_de_irte( $d ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro
	}
	return ob_get_clean();
}

/**
 * Aviso "antes de irte": tarjeta en la esquina inferior izquierda (a la
 * derecha está el botón del chat). main.js lo muestra una vez por sesión
 * cuando el mouse sale por arriba de la ventana (desktop) o al llegar al
 * final de la página (celular), y nunca si ya se entró a esa formación.
 */
function oec_render_antes_de_irte( array $d ): string {
	ob_start();
	?>
	<aside class="oec-antes-irte" data-oec-antes-irte data-slug="<?php echo esc_attr( $d['slug'] ); ?>" role="dialog" aria-labelledby="oec-antes-irte-t" hidden>
		<button type="button" class="oec-antes-irte__close" data-oec-antes-irte-close aria-label="<?php esc_attr_e( 'Cerrar', 'oec-theme' ); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
		<div class="oec-antes-irte__portada"><?php echo oec_destacada_portada( $d, 480, 'data' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></div>
		<div class="oec-antes-irte__body">
			<?php echo oec_destacada_caras( $d, 'oec-antes-irte__caras', 96, 3, 'data' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<p class="oec-antes-irte__kicker"><?php esc_html_e( '¡Esperá! No te vayas sin mirar…', 'oec-theme' ); ?></p>
			<p class="oec-antes-irte__t" id="oec-antes-irte-t"><?php echo esc_html( $d['title'] ); ?></p>
			<?php echo oec_destacada_stats( $d, 'oec-antes-irte__stats' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<a class="oec-antes-irte__cta" href="<?php echo esc_url( $d['url'] ); ?>"<?php echo $d['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e( 'Echale un vistazo', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
		</div>
	</aside>
	<?php
	return ob_get_clean();
}

/**
 * Marca de "no hay nada que mostrar": el CSS esconde la sección de la
 * landing (.oec-landing-block) que la contiene, en vez de dejar una franja
 * vacía con su padding.
 */
function oec_destacada_vacia(): string {
	return '<div class="oec-destacada-vacia" hidden></div>';
}

/**
 * [oec-suscribite tematica="nutricion-deportiva"]
 *
 * Créditos de bienvenida + newsletter de la temática, como sección propia
 * (antes estaba en el hero, que ahora es de [oec-estrella]). Va dentro de
 * un .oec-landing-block--dark: el widget lleva .oec-tematica-hero__credits
 * para tomar los mismos colores "sobre oscuro" que tenía en el hero.
 */
add_shortcode( 'oec-suscribite', 'oec_render_suscribite_shortcode' );
function oec_render_suscribite_shortcode( $atts ): string {
	$atts     = shortcode_atts( [ 'tematica' => '' ], $atts, 'oec-suscribite' );
	$tematica = sanitize_title( $atts['tematica'] );
	if ( $tematica && ! oec_destacadas( $tematica ) ) {
		return oec_destacada_vacia(); // los créditos quedaron en el hero ([oec-estrella])
	}
	$nombre = $tematica ? oec_destacada_tematica_nombre( $tematica ) : '';
	ob_start();
	?>
	<div class="oec-suscribite">
		<div class="oec-suscribite__text">
			<span class="oec-suscribite__eyebrow"><i class="bi bi-envelope-paper-heart" aria-hidden="true"></i> <?php echo esc_html( $nombre ? sprintf( __( 'Newsletter de %s', 'oec-theme' ), $nombre ) : __( 'Newsletter', 'oec-theme' ) ); ?></span>
			<h2><?php echo esc_html( $nombre ? sprintf( __( 'Lo mejor de %s, en tu correo', 'oec-theme' ), mb_strtolower( $nombre ) ) : __( 'Lo mejor de cada semana, en tu correo', 'oec-theme' ) ); ?></h2>
			<p><?php esc_html_e( 'Dejá tu email y te regalamos 50 créditos para usar como descuento en tu próxima formación.', 'oec-theme' ); ?></p>
			<ul>
				<li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?php esc_html_e( 'Formaciones nuevas y cierres de inscripción', 'oec-theme' ); ?></li>
				<li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?php esc_html_e( 'Artículos y novedades de la temática', 'oec-theme' ); ?></li>
				<li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?php esc_html_e( 'Te das de baja cuando quieras', 'oec-theme' ); ?></li>
			</ul>
		</div>
		<div class="oec-suscribite__widget oec-tematica-hero__credits">
			<?php echo do_shortcode( '[oec-credits-widget tematica="' . esc_attr( $tematica ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
