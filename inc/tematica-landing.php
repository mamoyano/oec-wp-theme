<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   LANDINGS DE TEMÁTICA — piezas reutilizables (hero, stats, etc.)
   Pensado para /especiales/{tematica}/ y las que se arman con el
   mismo patrón. Los números "de la industria" salen del catálogo que
   ya sincroniza OEC_AI_Catalog — nada de esto pega a la API en vivo.
   ============================================================ */

/**
 * [oec-tematica-stats tematica="nutricion-deportiva" desde="1999" variante="hero"]
 *
 * Barra de estadísticas para una landing de temática, o para el home
 * (dejando "tematica" vacío: ahí cuenta sobre TODO el catálogo, sin
 * filtrar). Todo sale del catálogo ya sincronizado — se actualiza solo en
 * cada sync diario, sin tocar la página:
 *  - "Formaciones abiertas": index.json (solo las abiertas hoy).
 *  - "Docentes", "Horas de contenido" y "Alumnos egresados": el histórico
 *    (history_index.json, abiertas + cerradas), para que el número no baje
 *    cuando una formación cierra inscripción. total_students ya es el
 *    total de egresados de todas las ediciones de cada formación.
 * "desde" es un dato de la empresa que no sale del catálogo, va a mano.
 * variante="hero": para ir dentro de .oec-tematica-hero__grid, como fila
 * de ancho completo al pie del hero (texto claro sobre fondo oscuro).
 */
add_shortcode( 'oec-tematica-stats', 'oec_render_tematica_stats_shortcode' );
function oec_render_tematica_stats_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'tematica' => '',
		'desde'    => '',
		'variante' => '',
	], $atts, 'oec-tematica-stats' );

	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return '';
	}

	$by_tematica = fn( array $list ) => '' === $atts['tematica']
		? $list
		: array_filter( $list, fn( $f ) => in_array( $atts['tematica'], $f['tags'] ?? [], true ) );

	$abiertas  = $by_tematica( OEC_AI_Catalog::get_index() );
	$historico = $by_tematica( OEC_AI_Catalog::get_history_index() ) ?: $abiertas;

	$docentes = [];
	$alumnos  = 0;
	$horas    = 0;
	foreach ( $historico as $f ) {
		foreach ( $f['teachers'] ?? [] as $nombre ) {
			$docentes[ $nombre ] = true;
		}
		$alumnos += (int) ( $f['total_students'] ?? 0 );
		$horas   += (int) ( $f['lecture_hours'] ?? 0 );
	}

	$stats = [];
	if ( $atts['desde'] ) {
		$stats[] = [ (int) current_time( 'Y' ) - (int) $atts['desde'], __( 'Años en la industria', 'oec-theme' ) ];
	}
	$stats[] = [ count( $abiertas ), __( 'Formaciones abiertas', 'oec-theme' ) ];
	// Mismo recuento que /docentes: una persona por slug normalizado (sin
	// "Dr.", ", MSc", acentos…). Contar nombres crudos duplicaba a quien
	// figura escrito distinto en dos formaciones (1931 vs 1880).
	if ( function_exists( 'oec_docentes_catalog' ) && ( $catalogo = oec_docentes_catalog() ) ) {
		$docentes = '' === $atts['tematica']
			? $catalogo
			: array_filter( $catalogo, fn( $d ) => in_array( $atts['tematica'], $d['tags'] ?? [], true ) );
	}
	$stats[] = [ count( $docentes ), __( 'Docentes especializados', 'oec-theme' ) ];
	$stats[] = [ $horas,             __( 'Horas de contenido', 'oec-theme' ) ];
	$stats[] = [ $alumnos,           __( 'Alumnos egresados', 'oec-theme' ) ];
	// Sin datos todavía (p. ej. histórico sin sincronizar): mejor no mostrar un 0.
	$stats = array_filter( $stats, fn( $s ) => $s[0] > 0 );

	ob_start();
	?>
	<div class="oec-stats-bar<?php echo 'hero' === $atts['variante'] ? ' oec-stats-bar--hero' : ''; ?>">
		<?php foreach ( $stats as [ $value, $label ] ) : ?>
		<div class="oec-stats-bar__item">
			<span class="oec-stats-bar__value" data-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
			<span class="oec-stats-bar__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [oec-cierres dias="7" tematica="" more-url="/formaciones?oec_order=enrollment_end"]
 *
 * Encabezado para la tira de formaciones que cierran inscripción pronto
 * (el [oec-list order="enrollment_end"] que va debajo, con title=""):
 * cuenta regresiva en vivo al próximo cierre + cuántas cierran en los
 * próximos N días, del catálogo sincronizado. Urgencia real, sin inventar
 * cupos. La inscripción cierra al TERMINAR el día de enrollment_end, en
 * la zona horaria del sitio — mismo criterio que el plugin
 * (normalize_dates()), así el encabezado y las cards marcan lo mismo.
 */
add_shortcode( 'oec-cierres', 'oec_render_cierres_shortcode' );
function oec_render_cierres_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'dias'     => 7,
		'tematica' => '',
		'more-url' => '',
	], $atts, 'oec-cierres' );

	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return '';
	}

	$dias  = max( 1, (int) $atts['dias'] );
	$hoy   = current_time( 'Y-m-d' );
	$hasta = gmdate( 'Y-m-d', strtotime( $hoy . ' +' . ( $dias - 1 ) . ' days' ) );

	$cierres = [];
	foreach ( OEC_AI_Catalog::get_index() as $f ) {
		$fin = $f['enrollment_end'] ?? '';
		if ( ! $fin || $fin < $hoy ) {
			continue;
		}
		if ( '' !== $atts['tematica'] && ! in_array( $atts['tematica'], $f['tags'] ?? [], true ) ) {
			continue;
		}
		$cierres[] = $fin;
	}
	if ( ! $cierres ) {
		return '';
	}
	sort( $cierres );
	$proximo  = ( new DateTime( $cierres[0] . ' 23:59:59', wp_timezone() ) )->format( 'c' );
	$en_rango = count( array_filter( $cierres, fn( $fin ) => $fin <= $hasta ) );

	if ( $en_rango > 1 ) {
		$titulo = 7 === $dias ? __( 'Cierran esta semana', 'oec-theme' ) : sprintf( __( 'Cierran en los próximos %d días', 'oec-theme' ), $dias );
		$bajada = sprintf( __( '%1$d formaciones cierran su inscripción en los próximos %2$d días. Después, hasta la próxima edición.', 'oec-theme' ), $en_rango, $dias );
	} elseif ( 1 === $en_rango ) {
		$titulo = 7 === $dias ? __( 'Cierra esta semana', 'oec-theme' ) : sprintf( __( 'Cierra en los próximos %d días', 'oec-theme' ), $dias );
		$bajada = __( 'Una formación cierra su inscripción en los próximos días. Después, hasta la próxima edición.', 'oec-theme' );
	} else {
		$titulo = __( 'Próximos cierres de inscripción', 'oec-theme' );
		$bajada = __( 'Formaciones ordenadas por fecha de cierre: la primera es la que menos tiempo tiene.', 'oec-theme' );
	}

	ob_start();
	?>
	<div class="oec-cierres">
		<div class="oec-cierres__text">
			<span class="oec-cierres__live" data-oec-next-close="<?php echo esc_attr( $proximo ); ?>" hidden>
				<span class="oec-cierres__dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Próximo cierre en', 'oec-theme' ); ?> <strong class="oec-cierres__clock"></strong>
			</span>
			<h2 class="oec-cierres__title"><?php echo esc_html( $titulo ); ?></h2>
			<p class="oec-cierres__sub"><?php echo esc_html( $bajada ); ?></p>
		</div>
		<?php if ( $atts['more-url'] ) : ?>
		<a class="oec-cierres__more" href="<?php echo esc_url( home_url( $atts['more-url'] ) ); ?>"><?php esc_html_e( 'Ver todos los cierres', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [oec-agenda more-url="/formaciones?oec_month=1" min="3"]
 *
 * Encabezado "agenda" para la tira de formaciones que empiezan el mes que
 * viene (el [oec-list] que va justo debajo, con title="" y
 * from-date="next-month" to-date="month-after-next", o sea dos meses):
 * nombre del mes + una fila con sus días. main.js cuenta las cards de la
 * tira por día (data-start en cada card del Twig), marca los días en que
 * arranca algo y, al tocar uno, filtra la tira a ese día.
 *
 * Si el mes que viene tiene menos de "min" formaciones, la agenda suma el
 * mes siguiente (días y cards); si no, las cards de ese segundo mes quedan
 * ocultas. Las cantidades salen de las cards reales, no del catálogo, para
 * que el número y lo que se ve siempre coincidan.
 */
add_shortcode( 'oec-agenda', 'oec_render_agenda_shortcode' );
function oec_render_agenda_shortcode( $atts ): string {
	$atts = shortcode_atts( [ 'more-url' => '', 'min' => 3 ], $atts, 'oec-agenda' );

	$meses = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
	$dias  = [ 1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo' ];

	$ini   = new DateTimeImmutable( 'first day of next month', wp_timezone() );
	$ini2  = $ini->modify( '+1 month' );
	$mes   = $meses[ (int) $ini->format( 'n' ) - 1 ];
	$mes2  = $meses[ (int) $ini2->format( 'n' ) - 1 ];

	$render_dias = function ( DateTimeImmutable $desde, string $nombre, bool $extra ) use ( $dias ): void {
		$cant = (int) $desde->format( 't' );
		for ( $d = 0; $d < $cant; $d++ ) {
			$fecha = $desde->modify( "+{$d} days" );
			$dow   = $dias[ (int) $fecha->format( 'N' ) ];
			printf(
				'<button type="button" class="oec-agenda__day%1$s%2$s" data-day="%3$s" data-label="%4$s" aria-pressed="false" disabled%5$s>'
				. '<span class="oec-agenda__dow">%6$s</span><span class="oec-agenda__num">%7$s</span><span class="oec-agenda__cnt" hidden></span></button>',
				(int) $fecha->format( 'N' ) >= 6 ? ' is-weekend' : '',
				$extra ? ' is-extra' : '',
				esc_attr( $fecha->format( 'Y-m-d' ) ),
				esc_attr( $dow . ' ' . $fecha->format( 'j' ) . ' de ' . $nombre ),
				$extra ? ' hidden' : '',
				esc_html( mb_substr( $dow, 0, 3 ) ),
				esc_html( $fecha->format( 'j' ) )
			);
		}
	};

	ob_start();
	?>
	<div class="oec-agenda" data-oec-agenda data-mes="<?php echo esc_attr( $mes ); ?>" data-mes2="<?php echo esc_attr( $mes2 ); ?>" data-min="<?php echo (int) $atts['min']; ?>">
		<div class="oec-agenda__head">
			<div>
				<span class="oec-agenda__eyebrow"><i class="bi bi-calendar3" aria-hidden="true"></i> <?php esc_html_e( 'Agenda', 'oec-theme' ); ?></span>
				<h2 class="oec-agenda__title" data-agenda-title><?php echo esc_html( sprintf( __( 'Empiezan en %s', 'oec-theme' ), $mes ) ); ?></h2>
				<p class="oec-agenda__sub" data-agenda-sub><?php echo esc_html( sprintf( __( 'Las formaciones que arrancan en %s. Elegí un día para ver cuáles empiezan ese día.', 'oec-theme' ), $mes ) ); ?></p>
			</div>
			<?php if ( $atts['more-url'] ) : ?>
			<a class="oec-cierres__more" href="<?php echo esc_url( home_url( $atts['more-url'] ) ); ?>"><?php echo esc_html( sprintf( __( 'Ver todo %s', 'oec-theme' ), $mes ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			<?php endif; ?>
		</div>

		<div class="oec-agenda__days" role="group" aria-label="<?php echo esc_attr( sprintf( __( 'Días de %s', 'oec-theme' ), $mes ) ); ?>">
			<button type="button" class="oec-agenda__day oec-agenda__day--all" data-day="" aria-pressed="true">
				<span class="oec-agenda__dow"><?php esc_html_e( 'Todas', 'oec-theme' ); ?></span>
				<span class="oec-agenda__num"><?php esc_html_e( 'las fechas', 'oec-theme' ); ?></span>
			</button>
			<?php $render_dias( $ini, $mes, false ); ?>
			<span class="oec-agenda__sep" data-agenda-sep hidden><?php echo esc_html( mb_substr( $mes2, 0, 3 ) ); ?></span>
			<?php $render_dias( $ini2, $mes2, true ); ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [oec-novedades dias="30" tematica="" more-url="/formaciones?oec_order=published_date"]
 *
 * Encabezado de la tira "Recién llegadas" (el [oec-list order=
 * "published_date"] que va justo debajo, con title=""). Del catálogo:
 * cuántas formaciones se publicaron en los últimos N días y cuántas tienen
 * descuento por pago anticipado vigente. Además imprime un mapa
 * id → {descuento, vencimiento, publicación} que main.js usa para marcar
 * cada card de la tira (data-id en el Twig): etiqueta "−30 % pago
 * anticipado" o "Nueva · hace N días", y un filtro "Con descuento".
 * Solo porcentaje y fecha: los montos dependen del país del visitante.
 */
add_shortcode( 'oec-novedades', 'oec_render_novedades_shortcode' );
function oec_render_novedades_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'dias'     => 30,
		'tematica' => '',
		'more-url' => '',
	], $atts, 'oec-novedades' );

	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return '';
	}

	$hoy   = current_time( 'Y-m-d' );
	$desde = gmdate( 'Y-m-d', strtotime( $hoy . ' -' . max( 1, (int) $atts['dias'] ) . ' days' ) );
	$mapa  = [];
	$nuevas = 0;
	$nuevas_desc = 0;
	foreach ( OEC_AI_Catalog::get_index() as $f ) {
		if ( '' !== $atts['tematica'] && ! in_array( $atts['tematica'], $f['tags'] ?? [], true ) ) {
			continue;
		}
		$pub  = (string) ( $f['publish_start'] ?? '' );
		$ep   = $f['early_payment'] ?? null;
		$desc = $ep && ( $ep['expiration'] ?? '' ) >= $hoy;
		$nueva = $pub >= $desde;
		if ( $nueva ) {
			$nuevas++;
			$nuevas_desc += $desc ? 1 : 0;
		}
		if ( $desc || $nueva ) {
			$mapa[ $f['id'] ] = array_filter( [
				'pct' => $desc ? (int) $ep['percentage'] : null,
				'exp' => $desc ? $ep['expiration'] : null,
				'pub' => $pub ?: null,
			] );
		}
	}

	// Frase en dos partes: las nuevas del período (catálogo) y cuántas de la
	// tira tienen descuento (main.js la reescribe contando las cards reales,
	// para que coincida con el filtro "Con descuento").
	$base = $nuevas > 1
		/* translators: 1: cantidad, 2: días */
		? sprintf( __( '%1$s formaciones se sumaron en los últimos %2$s días.', 'oec-theme' ), number_format_i18n( $nuevas ), (int) $atts['dias'] )
		: __( 'Las últimas formaciones que se sumaron a la plataforma.', 'oec-theme' );
	$desc = $nuevas_desc
		/* translators: %s: cantidad con descuento */
		? sprintf( _n( 'Hay %s con descuento por pago anticipado: cuanto antes te inscribas, menos pagás.', 'Hay %s con descuento por pago anticipado: cuanto antes te inscribas, menos pagás.', $nuevas_desc, 'oec-theme' ), number_format_i18n( $nuevas_desc ) )
		: '';

	ob_start();
	?>
	<div class="oec-novedades" data-oec-novedades>
		<div class="oec-agenda__head">
			<div>
				<span class="oec-agenda__eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> <?php esc_html_e( 'Novedades', 'oec-theme' ); ?></span>
				<h2 class="oec-agenda__title"><?php esc_html_e( 'Recién llegadas', 'oec-theme' ); ?></h2>
				<p class="oec-agenda__sub"><?php echo esc_html( $base ); ?> <span data-novedades-desc><?php echo esc_html( $desc ); ?></span></p>
			</div>
			<?php if ( $atts['more-url'] ) : ?>
			<a class="oec-cierres__more" href="<?php echo esc_url( home_url( $atts['more-url'] ) ); ?>"><?php esc_html_e( 'Ver todas las novedades', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			<?php endif; ?>
		</div>
		<div class="oec-novedades__filters" role="group" aria-label="<?php esc_attr_e( 'Filtrar novedades', 'oec-theme' ); ?>" hidden>
			<button type="button" class="docentes-chip" data-filtro="" aria-pressed="true"><?php esc_html_e( 'Todas', 'oec-theme' ); ?></button>
			<button type="button" class="docentes-chip" data-filtro="descuento" aria-pressed="false"><i class="bi bi-tag-fill" aria-hidden="true"></i> <?php esc_html_e( 'Con descuento', 'oec-theme' ); ?> <span data-novedades-count></span></button>
		</div>
		<script type="application/json" data-novedades-map><?php echo wp_json_encode( $mapa ); ?></script>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [oec-trust-logos title="Trabajamos con"]
 *
 * Cinta infinita de logos institucionales (avales). Lista curada a mano:
 * solo logos que se leen a 40px de alto — los que son mayormente texto
 * chico (AAHC, CSV, Europe Active, ABAMD, IDV, ISSN, AAOT) quedaron
 * afuera. Distinta de [oec-organizations], que es dinámica y lista a
 * quienes publican formaciones.
 */
function oec_get_trust_logos(): array {
	return [
		'fivb'      => 'FIVB',
		'uem'       => 'Universidad Europea Madrid',
		'greenwich' => 'University of Greenwich',
		'nsca'      => 'NSCA',
		'acsm'      => 'ACSM',
		'una'       => 'UNA',
		'iusca'     => 'IUSCA',
		'afaa'      => 'AFAA',
		'nasm'      => 'NASM',
		'asep'      => 'ASEP',
		'uemc'      => 'UEMC',
		'onlat'     => 'ONLAT',
		'favaloro'  => 'Fundación Favaloro',
		'ucam'      => 'UCAM',
		'dbss'      => 'DBSS',
		'iicefs'    => 'IICEFS',
		'isabel'    => 'Universidad Isabel I',
		'sermef'    => 'SERMEF',
		'asu'       => 'ASU',
		'umsa'      => 'UMSA',
		'oms'       => 'OMS',
	];
}

add_shortcode( 'oec-trust-logos', 'oec_render_trust_logos_shortcode' );
function oec_render_trust_logos_shortcode( $atts ): string {
	$atts  = shortcode_atts( [ 'title' => __( 'Trabajamos con', 'oec-theme' ) ], $atts, 'oec-trust-logos' );
	$logos = oec_get_trust_logos();

	ob_start();
	?>
	<div class="oec-trust">
		<?php if ( $atts['title'] ) : ?>
		<h2 class="oec-trust__title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>
		<div class="oec-trust__marquee">
			<div class="oec-trust__track">
				<?php // Dos vueltas: main.js corre la cinta el largo de una y empalma sin salto. Sin lazy: si un logo carga tarde cambia el largo. ?>
				<?php for ( $i = 0; $i < 2; $i++ ) : ?>
					<?php foreach ( $logos as $file => $name ) : ?>
					<img src="<?php echo esc_url( 'https://onlineeducation.center/wp-content/uploads/2024/12/' . $file . '.jpg' ); ?>"
					     alt="<?php echo $i ? '' : esc_attr( $name ); ?>"<?php echo $i ? ' aria-hidden="true"' : ''; ?>
					     decoding="async" height="40">
					<?php endforeach; ?>
				<?php endfor; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Landings de temática ya armadas — fuente única para el hub (/especiales/,
 * page-especiales.php), para [oec-tematica-nav] en el home y para el video
 * de fondo de los heroes ([oec-hero-video]: cada landing usa el suyo y el
 * home rota entre todos) y para los newsletters por temática: cada landing
 * tiene su lista de Elastic Email ("lista", por defecto "G-SE - {title}")
 * que se crea sola si no existe (oec_nl_ensure_especiales_lists()), y
 * "categoria" es la categoría del blog cuyos artículos lleva ese newsletter
 * (por defecto, la de slug = tematica). Para sumar una nueva (Fútbol, etc.)
 * alcanza con agregar una entrada acá: mientras su página no esté publicada
 * la entrada se ignora (sin links rotos en el home ni en /especiales/, y la
 * lista de Elastic Email recién se crea cuando la landing existe). "image"
 * y "video" son opcionales y se pueden cargar desde la página, sin tocar el
 * tema: la imagen destacada es la portada y el campo personalizado
 * "hero_video" (URL del .mp4) el video. Sin video, el hero queda con el
 * degradé de la temática y el home no la suma a la rotación.
 *
 * Videos del hero: en la biblioteca del sitio principal de la red,
 * comprimidos para fondo (960 px, 24 fps, sin audio, faststart — van detrás
 * de un overlay casi opaco). network_home_url() arma la URL con el dominio
 * vigente (hoy nuevo.g-se.com, mañana g-se.com).
 *
 * "tinte" (opcional): color oscuro de la temática para teñir la portada en
 * la tarjeta de /especiales/ cuando la imagen no viene ya teñida (las de
 * Nutrición y Fuerza traen la capa de color incorporada en el PNG).
 */
function oec_get_especiales_list(): array {
	static $cache = [];
	$blog = get_current_blog_id();
	if ( isset( $cache[ $blog ] ) ) {
		return $cache[ $blog ];
	}
	$base = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	$out = [];
	foreach ( oec_especiales_registradas() as $e ) {
		$path = trim( substr( (string) wp_parse_url( $e['url'], PHP_URL_PATH ), strlen( $base ) ), '/' );
		$page = get_page_by_path( $path );
		if ( ! $page || 'publish' !== $page->post_status ) {
			continue;
		}
		$e['image'] = $e['image'] ?: (string) get_the_post_thumbnail_url( $page, 'full' );
		$e['video'] = $e['video'] ?: esc_url_raw( trim( (string) get_post_meta( $page->ID, 'hero_video', true ) ) );
		$out[]      = $e;
	}
	return $cache[ $blog ] = $out;
}

/** Todas las landings declaradas (publicadas o no): ver oec_get_especiales_list(). */
function oec_especiales_registradas(): array {
	return [
		[
			'tematica' => 'nutricion-deportiva',
			'title'    => 'Nutrición Deportiva',
			'desc'     => 'Cursos, talleres y posgrados sobre alimentación, hidratación y suplementación aplicadas al rendimiento físico y la recuperación.',
			'url'      => home_url( user_trailingslashit( '/especiales/nutricion-deportiva' ) ),
			'image'    => home_url( '/wp-content/uploads/sites/2/2026/09/Portada-Nutricion-Deportiva-1.png' ),
			'video'    => network_home_url( '/wp-content/uploads/2026/09/hero-nutricion-deportiva.mp4' ),
			'accent'   => '#c1ff72',
			'lista'    => 'G-SE - Nutrición Deportiva',
		],
		[
			'tematica'  => 'fuerza',
			'title'     => 'Entrenamiento de la Fuerza',
			'desc'      => 'Cursos, talleres y posgrados sobre desarrollo de la fuerza muscular, sobrecarga progresiva y planificación del entrenamiento.',
			'url'       => home_url( user_trailingslashit( '/especiales/entrenamiento-de-la-fuerza' ) ),
			'image'     => home_url( '/wp-content/uploads/sites/2/2026/09/Portada-Entrenamiento-de-la-Fuerza-1.png' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-entrenamiento-de-la-fuerza.mp4' ),
			'accent'    => '#ff5a3c',
			'lista'     => 'G-SE - Entrenamiento de la Fuerza',
			'categoria' => 'entrenamiento-de-la-fuerza',
		],
		[
			'tematica'  => 'fisiologia',
			'title'     => 'Fisiología del Ejercicio',
			'desc'      => 'Cursos y especializaciones sobre las respuestas y adaptaciones del organismo al ejercicio: metabolismo energético, ergometría, análisis de gases y control motor.',
			'url'       => home_url( user_trailingslashit( '/especiales/fisiologia-del-ejercicio' ) ),
			'image'     => network_home_url( '/wp-content/uploads/2026/09/Portada-de-Fisiologia-del-Ejercicio-1.png' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-fisiologia-del-ejercicio.mp4' ),
			'accent'    => '#b794ff',
			'tinte'     => '#3d1f7a', // la portada es la foto sin teñir: la tarjeta de /especiales/ le pone la capa
			'lista'     => 'G-SE - Fisiología del Ejercicio',
			'categoria' => 'fisiologia-del-ejercicio',
		],
		[
			'tematica'  => 'salud-ejercicio',
			'title'     => 'Salud y Ejercicio',
			'desc'      => 'Cursos, talleres y posgrados sobre ejercicio físico para la salud: prevención y tratamiento de patologías, adultos mayores y poblaciones especiales.',
			'url'       => home_url( user_trailingslashit( '/especiales/salud-y-ejercicio' ) ),
			'image'     => network_home_url( '/wp-content/uploads/2026/09/portada-salud-y-ejercicio.jpg' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-salud-y-ejercicio.mp4' ),
			'accent'    => '#2ee6c5',
			'tinte'     => '#0b5a50',
			'lista'     => 'G-SE - Salud y Ejercicio',
			'categoria' => 'salud-fitness',
		],
		[
			'tematica'  => 'deportes',
			'title'     => 'Deportes',
			'desc'      => 'Cursos, talleres y posgrados de preparación física, planificación y ciencia aplicada a cada deporte: rugby, básquet, vóley, natación y más.',
			'url'       => home_url( user_trailingslashit( '/especiales/deportes' ) ),
			'image'     => network_home_url( '/wp-content/uploads/2026/09/portada-deportes.jpg' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-deportes.mp4' ),
			'accent'    => '#ff5fa2',
			'tinte'     => '#7a1446',
			'lista'     => 'G-SE - Deportes',
			'categoria' => 'deportes',
		],
		[
			'tematica'  => 'endurance',
			'title'     => 'Endurance',
			'desc'      => 'Cursos, talleres y posgrados sobre entrenamiento de la resistencia: running, ciclismo, triatlón y deportes de larga distancia.',
			'url'       => home_url( user_trailingslashit( '/especiales/endurance' ) ),
			'image'     => network_home_url( '/wp-content/uploads/2026/09/portada-endurance.jpg' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-endurance.mp4' ),
			'accent'    => '#ffc53d',
			'tinte'     => '#6b4a06',
			'lista'     => 'G-SE - Endurance',
			'categoria' => 'endurance',
		],
		[
			'tematica'  => 'futbol',
			'title'     => 'Fútbol',
			'desc'      => 'Cursos, talleres y posgrados de preparación física, nutrición y ciencia aplicada al fútbol profesional, formativo y amateur.',
			'url'       => home_url( user_trailingslashit( '/especiales/futbol' ) ),
			'image'     => network_home_url( '/wp-content/uploads/2026/09/portada-futbol.jpg' ),
			'video'     => network_home_url( '/wp-content/uploads/2026/09/hero-futbol.mp4' ),
			'accent'    => '#5ec8ff',
			'tinte'     => '#0b4a80',
			'lista'     => 'G-SE - Fútbol',
			'categoria' => 'futbol',
		],
	];
}

/**
 * [oec-hero-video tematica=""]
 *
 * Video de fondo del hero, desde oec_get_especiales_list():
 * - con tematica: el video de esa landing, en loop.
 * - sin tematica (home): rota entre los videos de todas las landings. Sin
 *   "loop": al terminar uno, main.js pasa al siguiente ([data-oec-videos]).
 *   El primero cambia cada día, para que no arranque siempre igual.
 */
add_shortcode( 'oec-hero-video', 'oec_render_hero_video_shortcode' );
function oec_render_hero_video_shortcode( $atts ): string {
	$atts   = shortcode_atts( [ 'tematica' => '' ], $atts, 'oec-hero-video' );
	$items  = array_values( array_filter( oec_get_especiales_list(), fn( $it ) => ! empty( $it['video'] ) ) );
	if ( '' !== $atts['tematica'] ) {
		$items = array_values( array_filter( $items, fn( $it ) => $it['tematica'] === $atts['tematica'] ) );
	}
	if ( ! $items ) {
		return '';
	}

	$rota = '' === $atts['tematica'] && count( $items ) > 1;
	if ( $rota ) {
		$offset = (int) current_time( 'z' ) % count( $items ); // día del año
		$items  = array_merge( array_slice( $items, $offset ), array_slice( $items, 0, $offset ) );
	}
	$first = $items[0];

	return sprintf(
		'<video class="oec-tematica-hero__video" autoplay muted playsinline%1$s preload="metadata" poster="%2$s"%3$s><source src="%4$s" type="video/mp4"></video>',
		$rota ? '' : ' loop',
		esc_url( $first['image'] ?? '' ),
		$rota ? " data-oec-videos='" . esc_attr( wp_json_encode( array_column( $items, 'video' ) ) ) . "'" : '',
		esc_url( $first['video'] )
	);
}

/**
 * [oec-tematica-nav]
 *
 * Fila de accesos directos a cada landing de temática ya armada — pensado
 * para el home, para que el visitante se auto-segmente por interés apenas
 * llega. Mismo acento de color que cada landing (--card-accent inline).
 */
add_shortcode( 'oec-tematica-nav', 'oec_render_tematica_nav_shortcode' );
function oec_render_tematica_nav_shortcode(): string {
	$items = oec_get_especiales_list();
	if ( ! $items ) {
		return '';
	}
	ob_start();
	?>
	<div class="oec-tematica-nav">
		<?php foreach ( $items as $it ) : ?>
		<a class="oec-tematica-nav__item" href="<?php echo esc_url( $it['url'] ); ?>" style="--card-accent: <?php echo esc_attr( $it['accent'] ); ?>;">
			<span class="oec-tematica-nav__dot" aria-hidden="true"></span>
			<?php echo esc_html( $it['title'] ); ?>
			<i class="bi bi-arrow-right" aria-hidden="true"></i>
		</a>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}
