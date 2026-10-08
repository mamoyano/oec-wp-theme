<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   ORGANIZACIONES — listado + landing individual.
   Los datos vienen de OEC_AI_Catalog (inc/ai-catalog.php), que ya
   los releva todos los días como parte de la sincronización del
   catálogo para el chat IA — acá solo se muestran.
   ============================================================ */

/**
 * URL de la landing de una organización (o del listado base, sin slug).
 * La página "organizacion" se auto-crea más abajo — mismo patrón que
 * oec_create_credits_page() para /creditos-por-descuentos/.
 */
function oec_organizacion_url( string $slug = '' ): string {
	$page = get_page_by_path( 'organizacion' );
	$base = $page ? get_permalink( $page ) : home_url( '/organizacion/' );
	return $slug ? add_query_arg( 'slug', $slug, $base ) : $base;
}

/** URL del listado de organizaciones (page-organizaciones.php). */
function oec_organizaciones_url( string $tematica = '' ): string {
	$page = get_page_by_path( 'organizaciones' );
	$base = $page ? get_permalink( $page ) : home_url( '/organizaciones/' );
	return $tematica ? add_query_arg( 'tematica', $tematica, $base ) : $base;
}

/**
 * [oec-organizations min-formaciones="2" title="Organizaciones" tematica="fuerza" orderby="random"]
 * Grilla de logos/nombres de organizaciones con formaciones abiertas,
 * cada una linkeando a su landing (page-organizacion.php).
 *
 * - tematica: slug de OEC_AI_Catalog::TEMATICA_SLUGS — si se pasa,
 *   "min-formaciones" se exige dentro de ESA temática puntual (p. ej.
 *   "organizaciones con 2+ formaciones en fuerza"), no sobre el total.
 * - orderby: "" (default, alfabético) o "random".
 */
add_shortcode( 'oec-organizations', 'oec_render_organizations_shortcode' );
function oec_render_organizations_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'min-formaciones' => 2,
		'title'           => '',
		'tematica'        => '',
		'orderby'         => '',
	], $atts, 'oec-organizations' );

	$orgs = class_exists( 'OEC_AI_Catalog' )
		? OEC_AI_Catalog::get_organizations( (int) $atts['min-formaciones'], $atts['tematica'] )
		: [];

	if ( ! $orgs ) {
		return '';
	}

	if ( 'random' === $atts['orderby'] ) {
		shuffle( $orgs );
	}

	ob_start();
	?>
	<div class="oec-organizations">
		<?php if ( $atts['title'] ) : ?>
		<h2 class="oec-organizations__title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>
		<div class="oec-organizations__grid">
			<?php foreach ( $orgs as $org ) : ?>
			<a class="oec-organizations__item" href="<?php echo esc_url( oec_organizacion_url( $org['slug'] ) ); ?>" title="<?php echo esc_attr( $org['name'] ); ?>">
				<span class="oec-organizations__logo">
					<?php if ( ! empty( $org['logo'] ) ) : ?>
					<img src="<?php echo esc_url( oec_cdn_resize( $org['logo'], 400, 90 ) ); ?>" alt="<?php echo esc_attr( $org['name'] ); ?>" loading="lazy">
					<?php else : ?>
					<span class="oec-organizations__fallback"><?php echo esc_html( $org['short_name'] ?: $org['name'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="oec-organizations__name"><?php echo esc_html( $org['name'] ); ?></span>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Página "organizacion" — se auto-crea al activar el tema, igual que
 * oec_create_credits_page() en inc/credits.php. WordPress selecciona
 * page-organizacion.php automáticamente por el slug, sin rewrite rule:
 * la organización a mostrar viaja como ?slug=... (mismo patrón que ya
 * usa el plugin para /formacion/?id=...).
 */
function oec_create_organizacion_page(): void {
	if ( get_page_by_path( 'organizacion' ) ) {
		return;
	}
	wp_insert_post( [
		'post_title'   => 'Organización',
		'post_name'    => 'organizacion',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_author'  => 1,
	] );
}
add_action( 'after_switch_theme', 'oec_create_organizacion_page' );

/**
 * Página "organizaciones" (listado, page-organizaciones.php) — se crea sola
 * una vez; en admin_init también, para instalaciones donde el tema ya
 * estaba activo (after_switch_theme no vuelve a dispararse).
 */
function oec_create_organizaciones_page(): void {
	if ( get_option( 'oec_organizaciones_page_v1' ) ) {
		return;
	}
	oec_create_organizacion_page();
	if ( ! get_page_by_path( 'organizaciones' ) ) {
		wp_insert_post( [
			'post_title'   => 'Organizaciones',
			'post_name'    => 'organizaciones',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'post_author'  => 1,
		] );
	}
	update_option( 'oec_organizaciones_page_v1', 1 );
}
add_action( 'after_switch_theme', 'oec_create_organizaciones_page' );
add_action( 'admin_init', 'oec_create_organizaciones_page' );

/** Organización de la landing actual (?slug=), o null. */
function oec_current_organizacion(): ?array {
	static $org = false;
	if ( false === $org ) {
		$slug = sanitize_title( wp_unslash( $_GET['slug'] ?? '' ) );
		$org  = ( is_page( 'organizacion' ) && $slug && class_exists( 'OEC_AI_Catalog' ) )
			? ( OEC_AI_Catalog::get_organization( $slug ) ?: null )
			: null;
	}
	return $org;
}

// Landing: <title> y meta description con el nombre (si no, todas se llaman "Organización").
add_filter( 'document_title_parts', function ( array $parts ): array {
	$org = oec_current_organizacion();
	if ( ! empty( $org['name'] ) ) {
		$parts['title'] = $org['name'];
	}
	return $parts;
} );

// Metadatos de la landing y del listado (description, Open Graph): inc/seo.php.
add_filter( 'oec_seo', function ( $c ) {
	if ( ! is_array( $c ) ) {
		return $c;
	}
	$org = oec_current_organizacion();
	if ( ! empty( $org['name'] ) ) {
		$desc = ( $org['short_description'] ?? '' ) ?: sprintf(
			/* translators: %s: nombre de la organización */
			__( 'Formaciones abiertas de %s: cursos, diplomados y posgrados online.', 'oec-theme' ),
			$org['name']
		);
		$c = array_merge( $c, array_filter( [
			'title'       => $org['name'],
			'description' => oec_seo_trim( $desc ),
			'image'       => ! empty( $org['logo'] ) ? oec_cdn_resize( $org['logo'], 1200, 90 ) : '',
		] ) );
		// La organización como entidad (los docentes ya tienen su Person).
		$url             = function_exists( 'oec_organizacion_url' ) ? oec_organizacion_url( $org['slug'] ) : '';
		$c['schema'][]   = array_filter( [
			'@type'       => 'EducationalOrganization',
			'@id'         => $url ? $url . '#organization' : null,
			'name'        => $org['name'],
			'alternateName' => ( $org['short_name'] ?? '' ) && $org['short_name'] !== $org['name'] ? $org['short_name'] : null,
			'url'         => $url ?: null,
			'logo'        => ! empty( $org['logo'] ) ? oec_cdn_resize( $org['logo'], 400, 90 ) : null,
			'description' => oec_seo_trim( (string) ( $org['short_description'] ?? '' ), 500 ) ?: null,
		] );
		return $c;
	}
	if ( is_page( 'organizaciones' ) && class_exists( 'OEC_AI_Catalog' ) ) {
		$c['description'] = sprintf(
			/* translators: %s: cantidad de organizaciones */
			__( '%s universidades, instituciones y centros de formación publican sus cursos, diplomados y posgrados con nosotros. Conocé sus formaciones abiertas.', 'oec-theme' ),
			number_format_i18n( count( OEC_AI_Catalog::get_organizations( 1 ) ) )
		);
	}
	return $c;
} );

/* ============================================================
   VITRINA DE ORGANIZACIONES — [oec-org-spotlight limit="8" tematica="" title=""]

   Lista de organizaciones destacadas (logo chico + nombre + cantidad) y
   un panel con la activa: logo, descripción, números (formaciones
   abiertas, alumnos formados, temáticas) y sus próximas formaciones.
   Rota sola (main.js). Pensada para que luzca el contenido y no dependa
   de la calidad de los logos. Todo sale del catálogo sincronizado.
   ============================================================ */

/** Alumnos formados por organización (abiertas + cerradas), cacheado hasta el próximo sync. */
function oec_org_alumnos(): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return [];
	}
	$meta = OEC_AI_Catalog::get_meta();
	$key  = 'oec_org_alumnos_' . md5( (string) ( $meta['finished_at'] ?? '' ) );
	$hit  = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$alumnos = [];
	foreach ( OEC_AI_Catalog::get_history_index() as $h ) {
		$f    = OEC_AI_Catalog::get_formation( (string) $h['id'] );
		$slug = $f['organization']['slug'] ?? '';
		if ( $slug ) {
			$alumnos[ $slug ] = ( $alumnos[ $slug ] ?? 0 ) + (int) ( $f['total_students'] ?? 0 );
		}
	}
	set_transient( $key, $alumnos, 12 * HOUR_IN_SECONDS );
	return $alumnos;
}

add_shortcode( 'oec-org-spotlight', 'oec_render_org_spotlight_shortcode' );
function oec_render_org_spotlight_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'limit'    => 8,
		'tematica' => '',
		'title'    => __( 'Instituciones que enseñan con nosotros', 'oec-theme' ),
	], $atts, 'oec-org-spotlight' );

	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return '';
	}
	$tem     = $atts['tematica'];
	$todas   = OEC_AI_Catalog::get_organizations( 1, $tem );
	$alumnos = oec_org_alumnos();
	$limit   = max( 1, (int) $atts['limit'] );

	$cantidad = fn( $o ) => '' !== $tem ? (int) ( $o['tematicas'][ $tem ] ?? 0 ) : (int) ( $o['count'] ?? 0 );
	$minimo   = '' !== $tem ? 1 : 2; // por temática hay menos: alcanza con 1
	$pool     = array_filter( $todas, fn( $o ) => ! empty( $o['logo'] ) && $cantidad( $o ) >= $minimo );
	usort( $pool, fn( $a, $b ) => ( 200 * $cantidad( $b ) + ( $alumnos[ $b['slug'] ] ?? 0 ) ) <=> ( 200 * $cantidad( $a ) + ( $alumnos[ $a['slug'] ] ?? 0 ) ) );
	$pool = array_slice( array_values( $pool ), 0, $limit * 2 );
	if ( ! $pool ) {
		return '';
	}
	// Rotación diaria estable dentro de las más relevantes.
	mt_srand( crc32( 'orgs' . current_time( 'Y-m-d' ) . $tem ) );
	shuffle( $pool );
	mt_srand();
	$orgs = array_slice( $pool, 0, $limit );
	usort( $orgs, fn( $a, $b ) => $cantidad( $b ) <=> $cantidad( $a ) );

	$hoy = current_time( 'Y-m-d' );

	ob_start();
	?>
	<section class="oec-orgs" data-oec-orgs aria-labelledby="oec-orgs-title">
		<div class="oec-agenda__head">
			<div>
				<span class="oec-agenda__eyebrow"><i class="bi bi-building" aria-hidden="true"></i> <?php esc_html_e( 'Organizaciones asociadas', 'oec-theme' ); ?></span>
				<h2 class="oec-agenda__title" id="oec-orgs-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="oec-agenda__sub">
					<?php
					printf(
						/* translators: %s: cantidad de organizaciones */
						esc_html__( '%s universidades, academias y referentes publican sus formaciones en nuestra plataforma.', 'oec-theme' ),
						esc_html( number_format_i18n( count( $todas ) ) )
					);
					?>
				</p>
			</div>
			<a class="oec-cierres__more" href="<?php echo esc_url( oec_organizaciones_url( $tem ) ); ?>">
				<?php echo esc_html( sprintf( __( 'Ver las %s organizaciones', 'oec-theme' ), number_format_i18n( count( $todas ) ) ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
			</a>
		</div>

		<div class="oec-orgs__layout">
			<div class="oec-orgs__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Organizaciones destacadas', 'oec-theme' ); ?>">
				<?php foreach ( $orgs as $i => $o ) : ?>
				<button type="button" class="oec-orgs__tab" role="tab" id="oec-org-tab-<?php echo (int) $i; ?>" aria-controls="oec-org-panel-<?php echo (int) $i; ?>" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>">
					<span class="oec-orgs__tab-logo"><img src="<?php echo esc_url( oec_cdn_resize( $o['logo'], 160, 90 ) ); ?>" alt="" loading="lazy" decoding="async"></span>
					<span class="oec-orgs__tab-text">
						<strong><?php echo esc_html( $o['name'] ); ?></strong>
						<span><?php echo esc_html( sprintf( _n( '%s formación abierta', '%s formaciones abiertas', $cantidad( $o ), 'oec-theme' ), number_format_i18n( $cantidad( $o ) ) ) ); ?></span>
					</span>
					<span class="oec-orgs__tab-progress" aria-hidden="true"></span>
				</button>
				<?php endforeach; ?>
			</div>

			<div class="oec-orgs__panels">
				<?php foreach ( $orgs as $i => $o ) :
					$det   = OEC_AI_Catalog::get_organization( $o['slug'] ) ?: [];
					// Vitrina del home / landings: solo las del token principal.
					$prox  = array_filter( $det['formations'] ?? [], fn( $f ) => ( $f['enrollment_end'] ?? '' ) >= $hoy
						&& oec_formation_is_primary( $f )
						&& ( '' === $tem || in_array( $tem, $f['tematicas'] ?? [], true ) ) );
					usort( $prox, fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ) );
					$prox  = array_slice( $prox, 0, 3 );
					$tems  = array_intersect_key( OEC_DOCENTE_TEMATICAS, $o['tematicas'] ?? [] );
					$al    = (int) ( $alumnos[ $o['slug'] ] ?? 0 );
					$desc  = trim( (string) ( $det['short_description'] ?? '' ) );
					?>
				<article class="oec-orgs__panel" role="tabpanel" id="oec-org-panel-<?php echo (int) $i; ?>" aria-labelledby="oec-org-tab-<?php echo (int) $i; ?>"<?php echo $i ? ' hidden' : ''; ?>>
					<div class="oec-orgs__top">
						<a class="oec-orgs__logo" href="<?php echo esc_url( oec_organizacion_url( $o['slug'] ) ); ?>">
							<img src="<?php echo esc_url( oec_cdn_resize( $o['logo'], 400, 90 ) ); ?>" alt="<?php echo esc_attr( $o['name'] ); ?>" loading="lazy" decoding="async">
						</a>
						<div class="oec-orgs__about">
							<h3 class="oec-orgs__name"><a href="<?php echo esc_url( oec_organizacion_url( $o['slug'] ) ); ?>"><?php echo esc_html( $o['name'] ); ?></a></h3>
							<?php if ( $desc ) : ?>
							<p class="oec-orgs__desc"><?php echo esc_html( wp_trim_words( $desc, 36, '…' ) ); ?></p>
							<?php endif; ?>
							<ul class="oec-orgs__stats">
								<li><strong><?php echo esc_html( number_format_i18n( $cantidad( $o ) ) ); ?></strong> <?php echo esc_html( _n( 'formación abierta', 'formaciones abiertas', $cantidad( $o ), 'oec-theme' ) ); ?></li>
								<?php if ( $al > 0 ) : ?>
								<li><strong><?php echo esc_html( number_format_i18n( $al ) ); ?></strong> <?php esc_html_e( 'alumnos formados', 'oec-theme' ); ?></li>
								<?php endif; ?>
							</ul>
							<?php if ( $tems ) : ?>
							<div class="oec-orgs__tems">
								<?php foreach ( $tems as $label ) : ?><span><?php echo esc_html( $label ); ?></span><?php endforeach; ?>
							</div>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( $prox ) : ?>
					<div class="oec-orgs__formations">
						<?php foreach ( $prox as $f ) :
							?>
						<a class="oec-orgs__formation" href="<?php echo esc_url( oec_formation_url( $f ) ); ?>"<?php echo oec_formation_link_attrs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<?php if ( ! empty( $f['image'] ) ) : ?>
							<span class="oec-orgs__formation-img"><img src="<?php echo esc_url( oec_cdn_resize( $f['image'], 480, 85 ) ); ?>" alt="" loading="lazy" decoding="async"></span>
							<?php endif; ?>
							<span class="oec-orgs__formation-body">
								<?php if ( ! empty( $f['type'] ) ) : ?><span class="oec-orgs__formation-type"><?php echo esc_html( $f['type'] ); ?></span><?php endif; ?>
								<strong><?php echo esc_html( $f['title'] ); ?></strong>
								<span class="oec-orgs__formation-date"><i class="bi bi-calendar3" aria-hidden="true"></i> <?php echo esc_html( sprintf( __( 'Inscripción hasta el %s', 'oec-theme' ), date_i18n( 'j \d\e F', strtotime( $f['enrollment_end'] ) ) ) ); ?></span>
							</span>
						</a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<a class="btn oec-orgs__cta" href="<?php echo esc_url( oec_organizacion_url( $o['slug'] ) ); ?>">
						<?php echo esc_html( sprintf( _n( 'Ver su formación', 'Ver sus %s formaciones', $cantidad( $o ), 'oec-theme' ), number_format_i18n( $cantidad( $o ) ) ) ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
					</a>
				</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
