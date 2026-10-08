<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   DOCENTES — tira [oec-docentes] + landing individual (page-docente.php)

   Todo sale del catálogo ya sincronizado (OEC_AI_Catalog), no de la API
   en vivo: se renderiza al instante y trae datos que la API de listados
   no da (alumnos formados, historial de formaciones, bio, opiniones).
   ============================================================ */

/** Temáticas del catálogo con nombre legible (chips de la landing). */
const OEC_DOCENTE_TEMATICAS = [
	'nutricion-deportiva' => 'Nutrición deportiva',
	'fuerza'              => 'Entrenamiento de la fuerza',
	'fisiologia'          => 'Fisiología del ejercicio',
	'salud-ejercicio'     => 'Ejercicio y salud',
	'deportes'            => 'Deportes',
	'futbol'              => 'Fútbol',
	'endurance'           => 'Resistencia',
];

/**
 * Todos los docentes del catálogo (abiertas + cerradas), agregados y
 * indexados por slug. Cacheado hasta el próximo sync (la clave incluye
 * la fecha de fin del último sync).
 *
 * Tokens secundarios: un docente existe en el sitio solo si dicta alguna
 * formación del token principal; en su página se suman también las de
 * otras comunidades (con su logo y link a su sitio).
 */
function oec_docentes_catalog(): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return [];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_docentes_v3_' . md5( (string) ( $meta['finished_at'] ?? '' ) . current_time( 'Y-m-d' ) );
	$hit  = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}

	$hoy      = current_time( 'Y-m-d' );
	$abiertas = [];
	foreach ( OEC_AI_Catalog::get_index( true ) as $f ) {
		if ( ( $f['enrollment_end'] ?? '' ) >= $hoy ) {
			$abiertas[ $f['id'] ] = true;
		}
	}

	$docentes = [];
	foreach ( OEC_AI_Catalog::get_history_index( true ) as $h ) {
		$f = OEC_AI_Catalog::get_formation( (string) $h['id'] );
		if ( ! $f ) {
			continue;
		}
		$abierta   = isset( $abiertas[ $f['id'] ] );
		$f_slug    = oec_formation_slug( $f );
		$primary   = oec_formation_is_primary( $f );
		$formacion = [
			'id'             => $f['id'],
			'title'          => $f['title'] ?? '',
			'type'           => $f['type'] ?? '',
			'slug'           => $f_slug,
			'image'          => $f['image'] ?? '',
			'enrollment_end' => $f['enrollment_end'] ?? '',
			'open'           => $abierta,
			'community'      => $f['community'] ?? '',
			'primary'        => $primary,
		];

		foreach ( $f['teachers'] ?? [] as $t ) {
			$nombre = oec_docente_clean_name( $t['name'] ?? '' );
			$slug   = oec_docente_slug( $nombre );
			if ( '' === $slug ) {
				continue;
			}
			$d = $docentes[ $slug ] ?? [
				'slug'        => $slug,
				'name'        => $nombre,
				'photo'       => '',
				'background'  => '',
				'bio'         => '',
				'org'         => '',
				'formaciones' => 0,
				'alumnos'     => 0,
				'tags'        => [],
				'items'       => [], // formaciones (abiertas + cerradas)
				'next'        => null, // [enrollment_end, slug] de la abierta (de este sitio) que cierra primero
				'primary'     => 0,    // cuántas son del token principal
			];
			$bio              = oec_docente_clean_bio( (string) ( $t['bio'] ?? '' ) );
			$d['photo']       = $d['photo'] ?: (string) ( $t['photo'] ?? '' );
			$d['background']  = $d['background'] ?: oec_docente_clean_text( (string) ( $t['background'] ?? '' ) );
			$d['bio']         = mb_strlen( $bio ) > mb_strlen( $d['bio'] ) ? $bio : $d['bio'];
			$d['org']         = $d['org'] ?: (string) ( $f['org'] ?? '' );
			$d['formaciones']++;
			$d['alumnos']    += (int) ( $f['total_students'] ?? 0 );
			$d['tags']        = array_values( array_unique( array_merge( $d['tags'], $f['tags'] ?? [] ) ) );
			$d['items'][]     = $formacion;
			$d['primary']    += (int) $primary;
			if ( $abierta && $f_slug && ! oec_formation_is_external( $formacion ) && ( ! $d['next'] || $formacion['enrollment_end'] < $d['next'][0] ) ) {
				$d['next'] = [ $formacion['enrollment_end'], $f_slug ];
			}
			$docentes[ $slug ] = $d;
		}
	}

	$docentes = array_filter( $docentes, fn( $d ) => $d['primary'] > 0 );
	set_transient( $key, $docentes, 12 * HOUR_IN_SECONDS );
	return $docentes;
}

function oec_get_docente( string $slug ): ?array {
	return oec_docentes_catalog()[ $slug ] ?? null;
}

/** "Francis Holway , MSc" → "Francis Holway, MSc"; MAYÚSCULAS → Título. */
function oec_docente_clean_name( string $name ): string {
	$name = trim( preg_replace( [ '/\s+/', '/\s+,/' ], [ ' ', ',' ], $name ) );
	if ( $name !== '' && $name === mb_strtoupper( $name ) ) {
		$name = mb_convert_case( mb_strtolower( $name ), MB_CASE_TITLE );
	}
	return $name;
}

/**
 * Slug estable para la URL: sin tratamiento ("Dr.", "Lic."…) ni títulos
 * posteriores (", MSc", ", PhD"…), así "Dr. Fernando Naclerio, PhD,
 * CSCS" → "fernando-naclerio".
 */
function oec_docente_slug( string $name ): string {
	$base = preg_replace( '/^(?:(?:dra?|lic|licda?|prof|profa|mg|mgter|msc|phd|ing|mtro|mtra)\.?\s+)+/iu', '', $name );
	$base = preg_replace( '/,.*$/u', '', (string) $base );
	return sanitize_title( remove_accents( trim( (string) $base ) ) );
}

/** Perfil en MAYÚSCULAS → oración normal; espacios prolijos. */
function oec_docente_clean_text( string $text ): string {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	if ( $text !== '' && $text === mb_strtoupper( $text ) ) {
		$text = mb_strtolower( $text );
		$text = mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}
	return $text;
}

/** Bio: sin restos de botones ("Seguir en Instagram") y con espacio tras punto. */
function oec_docente_clean_bio( string $bio ): string {
	$bio = oec_docente_clean_text( $bio );
	$bio = preg_replace( '/\s*Seguir en [A-Za-z]+\.?$/u', '', $bio );
	$bio = preg_replace( '/([a-záéíóúñ)])\.(?=[A-ZÁÉÍÓÚÑ])/u', '$1. ', (string) $bio );
	return trim( (string) $bio );
}

/** Foto por el redimensionador de OEC (sale en AVIF/WebP según el navegador; q=75 pesa ~25 % menos que 85). */
function oec_docente_photo_url( string $photo, int $w = 480 ): string {
	return $photo
		? add_query_arg( 'format', 'webp', oec_cdn_resize( $photo, $w, 75 ) )
		: 'https://imgrsize.oe-img.center/img/user-default.jpg?w=' . $w . '&q=75&format=webp';
}

/** URL de la landing de un docente (page-docente.php, ?slug=…). */
function oec_docente_url( string $slug = '' ): string {
	$page = get_page_by_path( 'docente' );
	$base = $page ? get_permalink( $page ) : home_url( '/docente/' );
	return $slug ? add_query_arg( 'slug', $slug, $base ) : $base;
}

/** URL del listado de docentes (page-docentes.php), opcionalmente filtrado. */
function oec_docentes_url( string $tematica = '' ): string {
	$page = get_page_by_path( 'docentes' );
	$base = $page ? get_permalink( $page ) : home_url( '/docentes/' );
	return $tematica ? add_query_arg( 'tematica', $tematica, $base ) : $base;
}

/**
 * Card vertical de un docente (tira del home/landings y listado).
 * $data: atributos data-* extra (filtros del listado). $show_open: marca
 * (ícono + tooltip) de "tiene formaciones con inscripción abierta" — en la
 * tira sobra, porque ahí todos tienen alguna abierta.
 */
function oec_docente_card( array $d, int $i = 0, array $data = [], bool $show_open = false ): string {
	$attrs = '';
	foreach ( $data as $k => $v ) {
		$attrs .= sprintf( ' data-%s="%s"', esc_attr( $k ), esc_attr( $v ) );
	}
	ob_start();
	?>
	<a class="oec-docente" style="--i: <?php echo (int) $i; ?>;" href="<?php echo esc_url( oec_docente_url( $d['slug'] ) ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- escapado arriba ?>>
		<img class="oec-docente__photo" src="<?php echo esc_url( oec_docente_photo_url( $d['photo'] ) ); ?>" alt="<?php echo esc_attr( $d['name'] ); ?>" loading="lazy" decoding="async" width="480" height="600">
		<span class="oec-docente__chip">
			<?php echo esc_html( sprintf( _n( '%s formación', '%s formaciones', $d['formaciones'], 'oec-theme' ), number_format_i18n( $d['formaciones'] ) ) ); ?>
		</span>
		<?php if ( $show_open && $d['next'] ) : ?>
		<span class="oec-docente__open" role="img" aria-label="<?php esc_attr_e( 'Tiene formaciones con inscripción abierta', 'oec-theme' ); ?>" data-tip="<?php esc_attr_e( 'Tiene formaciones con inscripción abierta', 'oec-theme' ); ?>"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
		<?php endif; ?>
		<span class="oec-docente__body">
			<span class="oec-docente__name"><?php echo esc_html( $d['name'] ); ?></span>
			<?php if ( $d['background'] || $d['org'] ) : ?>
			<span class="oec-docente__bg"><?php echo esc_html( $d['background'] ?: $d['org'] ); ?></span>
			<?php endif; ?>
			<?php if ( $d['alumnos'] > 0 ) : ?>
			<span class="oec-docente__stat"><i class="bi bi-mortarboard" aria-hidden="true"></i>
				<?php echo esc_html( sprintf( __( '%s alumnos formados', 'oec-theme' ), number_format_i18n( $d['alumnos'] ) ) ); ?>
			</span>
			<?php endif; ?>
			<span class="oec-docente__cta"><?php esc_html_e( 'Ver perfil', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
		</span>
	</a>
	<?php
	return ob_get_clean();
}

/* ── Tira [oec-docentes] ──────────────────────────────────────

   [oec-docentes limit="12" tematica="" title="" more-url=""]

   Docentes con al menos una formación abierta hoy, ordenados por impacto
   (alumnos formados + cantidad de formaciones), uno por formación. Se
   toma el doble de "limit" y se rota a diario dentro de ese grupo, para
   que no se vean siempre las mismas caras. Cada card lleva a la landing
   del docente. */
add_shortcode( 'oec-docentes', 'oec_render_docentes_shortcode' );
function oec_render_docentes_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'limit'    => 12,
		'tematica' => '',
		'title'    => __( 'Referentes que marcan la agenda', 'oec-theme' ),
		'more-url' => '',
	], $atts, 'oec-docentes' );

	$limit = max( 1, (int) $atts['limit'] );
	$todos = array_values( oec_docentes_catalog() );
	if ( '' !== $atts['tematica'] ) {
		$todos = array_values( array_filter( $todos, fn( $d ) => in_array( $atts['tematica'], $d['tags'], true ) ) );
	}
	$total    = count( $todos );
	$more_url = $atts['more-url'] ? home_url( $atts['more-url'] ) : oec_docentes_url( $atts['tematica'] );

	$pool = array_filter( $todos, fn( $d ) => $d['next'] && $d['photo'] );
	usort( $pool, fn( $a, $b ) => ( $b['alumnos'] + 300 * $b['formaciones'] ) <=> ( $a['alumnos'] + 300 * $a['formaciones'] ) );

	// Un docente por formación: sin esto, los 4-5 disertantes de un mismo
	// simposio masivo ocupan media tira con el mismo número de alumnos.
	$vistas = [];
	$pool   = array_filter( $pool, function ( $d ) use ( &$vistas ) {
		if ( isset( $vistas[ $d['next'][1] ] ) ) {
			return false;
		}
		return $vistas[ $d['next'][1] ] = true;
	} );
	$pool = array_slice( array_values( $pool ), 0, $limit * 2 );
	if ( ! $pool ) {
		return '';
	}

	// Rotación diaria estable (misma selección todo el día → cacheable).
	mt_srand( crc32( current_time( 'Y-m-d' ) . $atts['tematica'] ) );
	shuffle( $pool );
	mt_srand();
	$docentes = array_slice( $pool, 0, $limit );
	usort( $docentes, fn( $a, $b ) => $b['alumnos'] <=> $a['alumnos'] );

	$resto = $total - count( $docentes );
	$sub   = $atts['tematica']
		/* translators: %s: cantidad de docentes */
		? __( '%s docentes, investigadores y preparadores enseñan en estas formaciones. Estos son algunos de los que más alumnos formaron.', 'oec-theme' )
		/* translators: %s: cantidad de docentes */
		: __( '%s docentes, investigadores y preparadores enseñan en nuestras formaciones. Estos son algunos de los que más alumnos formaron.', 'oec-theme' );

	ob_start();
	?>
	<section class="oec-docentes" aria-labelledby="oec-docentes-title">
		<div class="oec-docentes__head">
			<div>
				<span class="oec-docentes__eyebrow"><?php esc_html_e( 'Docentes', 'oec-theme' ); ?></span>
				<h2 class="oec-docentes__title" id="oec-docentes-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="oec-docentes__sub"><?php echo esc_html( sprintf( $sub, number_format_i18n( $total ) ) ); ?></p>
			</div>
			<div class="oec-docentes__nav">
				<button type="button" class="oec-docentes__arrow" data-dir="-1" aria-label="<?php esc_attr_e( 'Anteriores', 'oec-theme' ); ?>" disabled><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
				<button type="button" class="oec-docentes__arrow" data-dir="1" aria-label="<?php esc_attr_e( 'Siguientes', 'oec-theme' ); ?>"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
			</div>
		</div>

		<div class="oec-docentes__track">
			<?php foreach ( $docentes as $i => $d ) : ?>
			<?php echo oec_docente_card( $d, $i ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endforeach; ?>

			<?php if ( $resto > 0 ) : ?>
			<a class="oec-docente oec-docente--more" style="--i: <?php echo count( $docentes ); ?>;" href="<?php echo esc_url( $more_url ); ?>">
				<span class="oec-docente__more-num">+<?php echo esc_html( number_format_i18n( $resto ) ); ?></span>
				<span class="oec-docente__more-txt"><?php echo esc_html( $atts['tematica'] ? __( 'docentes más en esta temática', 'oec-theme' ) : __( 'docentes más en todas nuestras formaciones', 'oec-theme' ) ); ?></span>
				<span class="oec-docente__cta"><?php esc_html_e( 'Ver todos los docentes', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
			</a>
			<?php endif; ?>
		</div>

		<div class="oec-docentes__foot">
			<a class="btn btn-ghost oec-docentes__all" href="<?php echo esc_url( $more_url ); ?>">
				<?php
				echo esc_html( $atts['tematica']
					/* translators: %s: cantidad de docentes */
					? sprintf( __( 'Conocé a los %s docentes de esta temática', 'oec-theme' ), number_format_i18n( $total ) )
					/* translators: %s: cantidad de docentes */
					: sprintf( __( 'Conocé a los %s docentes', 'oec-theme' ), number_format_i18n( $total ) ) );
				?>
				<i class="bi bi-arrow-right" aria-hidden="true"></i>
			</a>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/* ── Landing individual: SEO ──────────────────────────────────
   Title, meta description, canonical con el ?slug= (sin esto, todas las
   landings de docentes comparten el canonical /docente/) y JSON-LD
   Person, para que buscadores e IAs entiendan quién es. */

function oec_current_docente(): ?array {
	static $docente = false;
	if ( false === $docente ) {
		$slug    = sanitize_title( wp_unslash( $_GET['slug'] ?? '' ) );
		$docente = ( $slug && is_page( 'docente' ) ) ? oec_get_docente( $slug ) : null;
	}
	return $docente;
}

// Docente inexistente (o /docente/ sin slug): 404 real + noindex, para
// que los buscadores no indexen una página vacía.
add_action( 'template_redirect', function () {
	if ( is_page( 'docente' ) && ! oec_current_docente() ) {
		status_header( 404 );
		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}
} );

add_filter( 'document_title_parts', function ( array $parts ): array {
	$d = oec_current_docente();
	if ( $d ) {
		$parts['title'] = $d['name'] . ( $d['background'] ? ' — ' . $d['background'] : '' );
	}
	return $parts;
} );

add_filter( 'get_canonical_url', function ( $url, $post ) {
	if ( 'docente' === $post->post_name && ( $d = oec_current_docente() ) ) {
		return oec_docente_url( $d['slug'] );
	}
	if ( 'organizacion' === $post->post_name && ! empty( $_GET['slug'] ) && function_exists( 'oec_organizacion_url' ) ) {
		return oec_organizacion_url( sanitize_title( wp_unslash( $_GET['slug'] ) ) );
	}
	return $url;
}, 10, 2 );

// Metadatos de la landing (description, Open Graph, Twitter): inc/seo.php.
add_filter( 'oec_seo', function ( $c ) {
	$d = is_array( $c ) ? oec_current_docente() : null;
	if ( ! $d ) {
		return $c;
	}
	return array_merge( $c, [
		'type'        => 'profile',
		'title'       => $d['name'],
		'description' => oec_seo_trim( $d['bio'] ?: ( $d['background'] ?: '' ) ),
		'url'         => oec_docente_url( $d['slug'] ),
		// 500 px: el proxy sirve PNG a quien no acepta WebP (bots de
		// WhatsApp y otros) y a 800 px pasa los ~300 KB que toleran.
		'image'       => oec_docente_photo_url( $d['photo'], 500 ),
	] );
} );

// Person + BreadcrumbList.
add_action( 'wp_head', function () {
	$d = oec_current_docente();
	if ( ! $d ) {
		return;
	}
	$img = oec_docente_photo_url( $d['photo'], 800 );
	$url = oec_docente_url( $d['slug'] );

	$person = array_filter( [
		'@context'    => 'https://schema.org',
		'@type'       => 'Person',
		'name'        => $d['name'],
		'url'         => $url,
		'image'       => $img,
		'jobTitle'    => $d['background'] ?: null,
		'description' => $d['bio'] ?: null,
		'knowsAbout'  => array_values( array_intersect_key( OEC_DOCENTE_TEMATICAS, array_flip( $d['tags'] ) ) ) ?: null,
		'worksFor'    => [ '@id' => home_url( '/' ) . '#organization' ],
	] );
	echo '<script type="application/ld+json">' . wp_json_encode( $person, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
	oec_docentes_breadcrumb_jsonld( [ $d['name'], $url ] );
}, 5 );

/**
 * BreadcrumbList: Inicio / Formaciones / Docentes [/ Docente]. Mismo
 * recorrido que el breadcrumb visible de page-docentes.php y page-docente.php.
 */
function oec_docentes_breadcrumb_jsonld( array $last = [] ): void {
	$items = [
		[ __( 'Inicio', 'oec-theme' ), home_url( '/' ) ],
		[ __( 'Formaciones', 'oec-theme' ), home_url( user_trailingslashit( '/formaciones' ) ) ],
		[ __( 'Docentes', 'oec-theme' ), oec_docentes_url() ],
	];
	if ( $last ) {
		$items[] = $last;
	}
	$list = [];
	foreach ( $items as $n => [ $name, $item ] ) {
		$list[] = [ '@type' => 'ListItem', 'position' => $n + 1, 'name' => $name, 'item' => $item ];
	}
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

// Listado: descripción (inc/seo.php) + breadcrumb estructurado.
add_filter( 'oec_seo', function ( $c ) {
	if ( ! is_array( $c ) || ! is_page( 'docentes' ) ) {
		return $c;
	}
	$c['description'] = sprintf(
		/* translators: %s: cantidad de docentes */
		__( 'Conocé a los %s docentes, investigadores y preparadores que enseñan en nuestras formaciones: su trayectoria, sus formaciones abiertas y lo que dicen sus alumnos.', 'oec-theme' ),
		number_format_i18n( count( oec_docentes_catalog() ) )
	);
	return $c;
} );
add_action( 'wp_head', function () {
	if ( is_page( 'docentes' ) ) {
		oec_docentes_breadcrumb_jsonld();
	}
}, 5 );

/**
 * Páginas "docentes" (listado) y "docente" (landing) — se crean solas,
 * una vez, si no existen, igual que la de organizaciones. WordPress elige
 * page-docentes.php / page-docente.php por el slug.
 */
function oec_create_docente_pages(): void {
	if ( get_option( 'oec_docente_pages_v2' ) ) {
		return;
	}
	foreach ( [ 'docentes' => 'Docentes', 'docente' => 'Docente' ] as $slug => $title ) {
		if ( ! get_page_by_path( $slug ) ) {
			wp_insert_post( [
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
				'post_author'  => 1,
			] );
		}
	}
	update_option( 'oec_docente_pages_v2', 1 );
}
add_action( 'after_switch_theme', 'oec_create_docente_pages' );
add_action( 'admin_init', 'oec_create_docente_pages' );
