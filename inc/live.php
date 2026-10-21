<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   LIVE — /especiales/live (page-live.php)

   "Made for humans, by humans": las formaciones con docentes en vivo
   (synchronicity SYNC o MIXED) y relevancia 2 — lo mejor de lo mejor —,
   agrupadas por temática. Todo sale del catálogo sincronizado: a medida
   que se marcan más formaciones con relevancia 2, aparecen solas.

   No está en oec_especiales_registradas() a propósito: esa lista arma
   también las listas del newsletter, las clases de Vimeo, el chat IA y
   la franja de créditos. LIVE se suma aparte, destacada, en /especiales
   y en [oec-tematica-nav].

   El hero muestra "globos" con nuestros docentes dando clase (videos
   cuadrados de 8 s, en el tema: assets/img/live/docentes/{n}.mp4 y su
   primer cuadro en .webp), que aparecen y se desvanecen por la mitad
   derecha (main.js). La página no depende de nada subido a mano.
   ============================================================ */

/** Paleta: rojo neón ("en vivo") sobre negro. */
const OEC_LIVE_COLORS = [
	'accent' => '#ff3b3b',
	'dark'   => '#0b0203',
	'dark-2' => '#4a0610',
];

function oec_is_live_page(): bool {
	return is_page( 'live' ) && 'especiales' === get_post_field( 'post_name', (int) wp_get_post_parent_id( get_queried_object_id() ) );
}

/** ¿Está publicada /especiales/live? (para los accesos desde /especiales y el home) */
function oec_live_disponible(): bool {
	$p = get_page_by_path( 'especiales/live' );
	return $p && 'publish' === $p->post_status;
}

/**
 * Banner de LIVE para /especiales: negro, logo, claim y cuántas hay.
 */
function oec_live_banner(): string {
	if ( ! oec_live_disponible() ) {
		return '';
	}
	$n = count( oec_live_formaciones() );
	ob_start();
	?>
	<a class="oec-live-banner" href="<?php echo esc_url( oec_live_url() ); ?>" style="--live-accent: <?php echo esc_attr( OEC_LIVE_COLORS['accent'] ); ?>;">
		<span class="oec-live-banner__logo"><?php echo oec_live_logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fijo ?></span>
		<span class="oec-live-banner__text">
			<strong>Made for humans, by humans.</strong>
			<span><?php echo esc_html( $n ? sprintf( _n( '%d formación en vivo con docentes de élite: experiencia que todavía no está escrita, ni en papers ni en la IA.', '%d formaciones en vivo con docentes de élite: experiencia que todavía no está escrita, ni en papers ni en la IA.', $n, 'oec-theme' ), $n ) : __( 'Formaciones en vivo con docentes de élite: experiencia que todavía no está escrita, ni en papers ni en la IA.', 'oec-theme' ) ); ?></span>
		</span>
		<span class="oec-live-banner__cta"><?php esc_html_e( 'Entrar a LIVE', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
	</a>
	<?php
	return (string) ob_get_clean();
}

function oec_live_url(): string {
	return home_url( user_trailingslashit( '/especiales/live' ) );
}

/**
 * Las formaciones LIVE abiertas: SYNC o MIXED, relevancia 2, ordenadas
 * por fecha de inicio (las que arrancan antes, primero).
 */
function oec_live_formaciones(): array {
	if ( ! function_exists( 'oec_tira_catalogo' ) ) {
		return [];
	}
	$hoy  = current_time( 'Y-m-d' );
	$rows = array_values( array_filter( oec_tira_catalogo(), fn( $r ) =>
		$r['relevance'] >= 2
		&& in_array( $r['synchronicity'], [ 'SYNC', 'MIXED' ], true )
		&& ! ( $r['enrollment_end'] && $r['enrollment_end'] < $hoy )
	) );
	usort( $rows, fn( $a, $b ) => strcmp( $a['start'] ?: '9999', $b['start'] ?: '9999' ) );
	return $rows;
}

/**
 * Agrupadas por temática, en el orden de las landings. Una formación con
 * dos temáticas sale en las dos. Las que no tienen una temática con
 * landing van en "Otras temáticas", al final.
 */
function oec_live_grupos( array $rows ): array {
	$grupos = [];
	$usadas = [];
	foreach ( oec_especiales_registradas() as $e ) {
		$en = array_values( array_filter( $rows, fn( $r ) => in_array( $e['tematica'], $r['tags'], true ) ) );
		if ( ! $en ) {
			continue;
		}
		foreach ( $en as $r ) {
			$usadas[ $r['id'] ] = true;
		}
		$grupos[] = [
			'tematica' => $e['tematica'],
			'title'    => $e['title'],
			'accent'   => $e['accent'],
			'url'      => $e['url'],
			'rows'     => $en,
		];
	}
	$otras = array_values( array_filter( $rows, fn( $r ) => empty( $usadas[ $r['id'] ] ) ) );
	if ( $otras ) {
		$grupos[] = [
			'tematica' => '',
			'title'    => __( 'Otras temáticas', 'oec-theme' ),
			'accent'   => OEC_LIVE_COLORS['accent'],
			'url'      => '',
			'rows'     => $otras,
		];
	}
	return $grupos;
}

/**
 * Docentes de las formaciones LIVE (uno por persona), con foto primero.
 * Si tiene ficha en /docente, con link.
 */
function oec_live_docentes( array $rows ): array {
	$catalogo = function_exists( 'oec_docentes_catalog' ) ? oec_docentes_catalog() : [];
	$out      = [];
	foreach ( $rows as $r ) {
		$f = OEC_AI_Catalog::get_formation( (string) $r['id'] );
		foreach ( $f['teachers'] ?? [] as $t ) {
			$slug = oec_docente_slug( (string) ( $t['name'] ?? '' ) );
			if ( '' === $slug || isset( $out[ $slug ] ) ) {
				continue;
			}
			$out[ $slug ] = [
				'name'  => function_exists( 'oec_destacada_nombre' ) ? oec_destacada_nombre( (string) $t['name'] ) : (string) $t['name'],
				'area'  => trim( (string) ( $t['background'] ?? '' ) ),
				'photo' => (string) ( $t['photo'] ?? '' ),
				'url'   => isset( $catalogo[ $slug ] ) ? oec_docente_url( $slug ) : '',
			];
		}
	}
	uasort( $out, fn( $a, $b ) => ( '' === $a['photo'] ) <=> ( '' === $b['photo'] ) );
	return array_values( $out );
}

/**
 * Logo LIVE: punto que late (como el "● EN VIVO" de una transmisión) +
 * la palabra + una línea de pulso cardíaco que se dibuja. SVG en línea:
 * nítido en cualquier tamaño y sin pedir ningún archivo. El texto toma la
 * tipografía del sitio y el color del contexto (currentColor).
 */
function oec_live_logo( string $class = '' ): string {
	return '<svg class="oec-live-logo ' . esc_attr( $class ) . '" viewBox="0 0 300 72" role="img" aria-label="LIVE" xmlns="http://www.w3.org/2000/svg">'
		. '<circle class="oec-live-logo__halo" cx="24" cy="36" r="13"/>'
		. '<circle class="oec-live-logo__dot" cx="24" cy="36" r="11"/>'
		. '<text x="48" y="54" class="oec-live-logo__text">LIVE</text>'
		. '<path class="oec-live-logo__pulse" d="M196 36 h22 l7 -16 l9 32 l8 -26 l6 10 h48" pathLength="100"/>'
		. '</svg>';
}

/* ── Crear /especiales/live una vez (como /creditos-por-descuentos). Solo
   en el sitio que tiene /especiales; WordPress elige page-live.php por el
   slug, el meta de plantilla queda por las dudas. ── */
function oec_create_live_page(): void {
	$parent = get_page_by_path( 'especiales' );
	if ( ! $parent || get_page_by_path( 'especiales/live' ) ) {
		return;
	}
	$id = wp_insert_post( [
		'post_title'   => 'LIVE',
		'post_name'    => 'live',
		'post_parent'  => $parent->ID,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_author'  => get_current_user_id() ?: 1,
	] );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_wp_page_template', 'page-live.php' );
	}
}
add_action( 'after_switch_theme', 'oec_create_live_page' );
add_action( 'admin_init', function (): void {
	$key = 'oec_live_page_checked_' . get_current_blog_id();
	if ( get_transient( $key ) ) {
		return;
	}
	oec_create_live_page();
	set_transient( $key, 1, DAY_IN_SECONDS );
} );

/**
 * Docentes de los globos del hero: el número es el de su video en
 * assets/img/live/docentes/. Para sumar uno: tools/live-globo.sh <video>
 * <número> (lo deja en 8 s, 240 px, sin audio, más su primer cuadro en
 * .webp) y una línea acá con su nombre.
 */
const OEC_LIVE_GLOBOS = [
	1  => 'Horacio Anselmi',
	2  => 'Francis Holway',
	3  => 'Juan José González Badillo',
	4  => 'Juan Ramón Heredia',
	5  => 'Stuart McGill',
	6  => 'Ariel Couceiro',
	7  => 'Bret Contreras',
	8  => 'Javier Fernández Alba',
	9  => 'Brad Schoenfeld',
	10 => 'Mario Di Santo',
	11 => 'Luis García',
	12 => 'Felipe Isidro',
	13 => 'Richard Kreider',
	14 => 'Mireia Porta',
	15 => 'Iñigo Mujika',
	16 => 'Louise Burke',
	17 => 'Michael Boyle',
	18 => 'Esther Morenco Martínez',
	19 => 'Mauricio Moyano',
];

/**
 * Los globos del hero: 8 lugares fijos (posición y tamaño en % de la
 * mitad derecha, elegidos para que no se pisen) que main.js va llenando
 * con docentes al azar; cada tanto uno se desvanece y aparece otro. Los
 * videos se piden recién al aparecer su globo. Decorativo para lectores
 * de pantalla: los nombres van en el texto de la página.
 */
function oec_live_globos_html(): string {
	$base = OEC_THEME_URI . '/assets/img/live/docentes/';
	$data = [];
	foreach ( OEC_LIVE_GLOBOS as $n => $nombre ) {
		$data[] = [ 'v' => $base . $n . '.mp4?v=' . OEC_THEME_VERSION, 'p' => $base . $n . '.webp?v=' . OEC_THEME_VERSION, 'n' => $nombre ];
	}
	// left %, top %, ancho % (del contenedor), segundos del vaivén; y los
	// mismos tres para el celular (solo los seis primeros: ver style.css).
	$slots = [
		[ 6, 8, 24, 7, 2, 4, 26 ],
		[ 44, 4, 18, 9, 38, 0, 22 ],
		[ 70, 14, 22, 8, 70, 6, 26 ],
		[ 22, 42, 28, 10, 18, 50, 24 ],
		[ 60, 44, 20, 7.5, 50, 52, 22 ],
		[ 4, 66, 17, 8.5, 80, 58, 18 ],
		[ 52, 70, 21, 9.5, 0, 0, 0 ],
		[ 76, 68, 16, 7, 0, 0, 0 ],
	];
	$html  = '<div class="oec-live-globos" data-oec-live-globos="' . esc_attr( wp_json_encode( $data ) ) . '" aria-hidden="true">';
	foreach ( $slots as [ $x, $y, $s, $d, $mx, $my, $ms ] ) {
		$html .= sprintf(
			'<div class="oec-live-globo" style="--x:%1$s%%;--y:%2$s%%;--s:%3$s%%;--d:%4$ss;--mx:%5$s%%;--my:%6$s%%;--ms:%7$s%%"><div class="oec-live-globo__flota"><div class="oec-live-globo__circle"><video muted playsinline loop preload="none"></video></div><span class="oec-live-globo__name"></span></div></div>',
			$x, $y, $s, $d, $mx, $my, $ms
		);
	}
	return $html . '</div>';
}

// <title> descriptivo (el de la página es solo "LIVE"): es lo que muestra Google.
add_filter( 'document_title_parts', function ( array $parts ): array {
	if ( oec_is_live_page() ) {
		$parts['title'] = __( 'LIVE: formaciones en vivo con docentes de élite', 'oec-theme' );
	}
	return $parts;
} );

/**
 * [oec-live] — franja de LIVE para el home: el logo animado como título,
 * una frase y la tira con las formaciones LIVE; "Ver todas" lleva a
 * /especiales/live (mismo formato que el encabezado de las otras tiras).
 * Sin la página publicada, no sale nada.
 */
add_shortcode( 'oec-live', 'oec_render_live_home' );
function oec_render_live_home(): string {
	if ( ! oec_live_disponible() ) {
		return '';
	}
	$rows = oec_live_formaciones();
	if ( ! $rows ) {
		return '';
	}
	ob_start();
	?>
	<section class="oec-live-home" style="--live-accent: <?php echo esc_attr( OEC_LIVE_COLORS['accent'] ); ?>;">
		<div class="oec-live-wrap">
			<div class="oec-live-home__head">
				<div>
					<h2 class="oec-live-home__title"><?php echo oec_live_logo( 'oec-live-home__logo' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fijo, con aria-label "LIVE" ?></h2>
					<p class="oec-live-home__lead"><?php esc_html_e( 'Nuestras mejores formaciones en vivo.', 'oec-theme' ); ?></p>
				</div>
				<a class="oec-live-home__more" href="<?php echo esc_url( oec_live_url() ); ?>"><?php esc_html_e( 'Ver todas las formaciones LIVE', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			</div>
			<?php echo oec_tira_html( array_slice( $rows, 0, 12 ), [ 'more_url' => oec_live_url(), 'more_label' => __( 'Ver todas las formaciones LIVE', 'oec-theme' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

// En el home va sola, entre "Lo que dicen nuestros alumnos" ([oec-opiniones])
// y "Recién llegadas", salvo que el contenido ya traiga [oec-live] en otro
// lugar: así no hay que editar el home a mano en cada sitio. Antes de
// do_shortcode (11), sobre el contenido crudo, donde esa sección solo tiene
// shortcodes y su </section> es el primero después de [oec-opiniones.
add_filter( 'the_content', function ( string $content ): string {
	if ( ! is_front_page() || has_shortcode( $content, 'oec-live' ) ) {
		return $content;
	}
	$i = strpos( $content, '[oec-opiniones' );
	$j = false === $i ? false : strpos( $content, '</section>', $i );
	return false === $j ? $content : substr_replace( $content, "\n[oec-live]\n", $j + strlen( '</section>' ), 0 );
}, 9 );
