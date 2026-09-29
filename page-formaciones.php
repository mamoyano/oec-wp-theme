<?php
/**
 * Template: Listado de formaciones (abiertas y cerradas).
 * Se activa automáticamente para la página con slug "formaciones".
 *
 * Todo sale de listing.json (ver inc/formaciones.php): filtros, orden,
 * búsqueda y paginación se resuelven en memoria, sin llamar a la API.
 * El contenido de la página (el viejo [oec-list] del plugin) ya no se usa.
 */

$estado     = oec_formaciones_estado();
$grupos     = oec_formaciones_grupos();
$ordenes    = oec_formaciones_ordenes();
$res        = oec_formaciones_query( $estado );
$paged      = $res['page'];

// Página fuera de rango (el catálogo se achica y quedan links viejos a
// /formaciones/page/N): a la última que existe, con los mismos filtros.
if ( $estado['pg'] > $res['pages'] ) {
	wp_safe_redirect( oec_formaciones_url( [ 'pg' => $res['pages'] ] ), 302 );
	exit;
}
$hero_title = oec_formaciones_titulo( $estado );

// Bajadas por tipo de formación (criterio editorial). Sin tipo, o un tipo
// sin bajada propia, va el texto genérico.
$hero_leads_by_type = [
	'Webinar'  => __( 'Los webinars son conferencias virtuales únicas, pensadas para actualizarte en un tema puntual en poco tiempo.', 'oec-theme' ),
	'Taller'   => __( 'Los talleres son formaciones rápidas, de aproximadamente una semana, con aprendizaje teórico y mucha aplicación práctica.', 'oec-theme' ),
	'Curso'    => __( 'Los cursos son formaciones robustas, con uno o varios docentes, asignaturas, exámenes y trabajos prácticos.', 'oec-theme' ),
	'Posgrado' => __( 'Los posgrados son certificaciones oficiales o avaladas internacionalmente, de nivel elevado.', 'oec-theme' ),
	'Master'   => __( 'Los másteres son posgrados con certificación universitaria, de nivel elevado.', 'oec-theme' ),
	'Simposio' => __( 'Los simposios reúnen muchas conferencias y expositores sobre una misma temática, sin un hilo pedagógico. Nivel elevado.', 'oec-theme' ),
];
$hero_lead = $hero_leads_by_type[ $estado['tipo'] ] ?? __( 'Explorá toda la oferta académica de Online Education Center: cursos, talleres, webinars, posgrados y más.', 'oec-theme' );

// Tags de filtros activos (cada uno con el link que lo quita).
$tags = [];
foreach ( $grupos as $key => $g ) {
	$v = $estado[ $key ];
	if ( ! empty( $g['multi'] ) ) {
		foreach ( $v as $opt ) {
			$tags[] = [ $g['options'][ $opt ], oec_formaciones_url( [ $key => array_values( array_diff( $v, [ $opt ] ) ) ] ) ];
		}
	} elseif ( 'inscripcion' === $key ) {
		if ( 'all' !== $v ) {
			$tags[] = [ 'opened' === $v ? __( 'Inscripción abierta', 'oec-theme' ) : __( 'Inscripción cerrada', 'oec-theme' ), oec_formaciones_url( [ $key => 'all' ] ) ];
		}
	} elseif ( '' !== $v ) {
		$tags[] = [ $g['options'][ $v ], oec_formaciones_url( [ $key => '' ] ) ];
	}
}
if ( '' !== $estado['q'] ) {
	/* translators: %s: texto buscado */
	$tags[] = [ sprintf( __( '“%s”', 'oec-theme' ), $estado['q'] ), oec_formaciones_url( [ 'q' => '' ] ) ];
}
$clear_url = oec_formaciones_url( [ 'tematica' => [], 'tipo' => '', 'inscripcion' => '', 'mes' => '', 'sync' => '', 'modalidad' => '', 'q' => '' ] );

// og:image / twitter:image con el logo del tema (después de los meta del plugin).
add_action( 'wp_head', 'oec_output_og_image_meta', 20 );

// ItemList de la página actual (lo que antes armaba el Twig del plugin).
add_action( 'wp_head', function () use ( $res, $paged, $hero_title ): void {
	if ( ! $res['items'] ) {
		return;
	}
	$offset = ( $paged - 1 ) * OEC_FORMACIONES_POR_PAGINA;
	$items  = [];
	foreach ( $res['items'] as $i => $r ) {
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $offset + $i + 1,
			'url'      => trailingslashit( home_url( '/formacion' ) ) . $r['slug'],
			'name'     => $r['title'],
		];
	}
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context'   => 'https://schema.org',
		'@type'      => 'CollectionPage',
		'name'       => $hero_title,
		'url'        => oec_formaciones_url( [ 'pg' => $paged ] ),
		'mainEntity' => [
			'@type'           => 'ItemList',
			'numberOfItems'   => $res['total'],
			'itemListElement' => $items,
		],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}, 20 );

get_header();
?>

<div class="page-hero page-hero--formaciones<?php echo $paged > 1 ? ' page-hero--paged' : ''; ?>">
	<div class="container">
		<?php
		oec_breadcrumb( $paged > 1
			? [ [ $hero_title, oec_formaciones_url() ], [ sprintf( __( 'Página %d', 'oec-theme' ), $paged ) ] ]
			: [ [ $hero_title ] ], false );
		?>
		<?php if ( 1 === $paged ) : ?>
		<h1><?php echo esc_html( $hero_title ); ?></h1>
		<p class="page-hero__lead">
			<?php echo esc_html( $hero_lead ); ?>
		</p>
		<?php else : ?>
		<h1 class="sr-only"><?php echo esc_html( sprintf( __( '%1$s – Página %2$d', 'oec-theme' ), $hero_title, $paged ) ); ?></h1>
		<?php endif; ?>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap formaciones-wrap">
<div class="container">

<div class="art-layout">

	<!-- ── SIDEBAR ─────────────────────────────────────────────── -->
	<aside class="art-sidebar" aria-label="<?php esc_attr_e( 'Filtros de formaciones', 'oec-theme' ); ?>">

		<button class="art-filter-toggle" aria-expanded="false" aria-controls="art-sidebar-body" id="art-filter-toggle" data-live-region="toggle">
			<svg class="art-filter-toggle-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
			<span class="art-filter-toggle-label"><?php esc_html_e( 'Filtros', 'oec-theme' ); ?></span>
			<?php if ( count( $tags ) ) : ?>
			<span class="art-filter-toggle-count" aria-label="<?php /* translators: %d: cantidad de filtros activos */ echo esc_attr( sprintf( _n( '%d filtro activo', '%d filtros activos', count( $tags ), 'oec-theme' ), count( $tags ) ) ); ?>"><?php echo (int) count( $tags ); ?></span>
			<?php endif; ?>
			<svg class="art-filter-toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
		</button>

		<div class="art-sidebar-body" id="art-sidebar-body" data-live-region="sidebar">

		<div class="art-sidebar-header">
			<h2 class="art-sidebar-title"><?php esc_html_e( 'Filtrar por', 'oec-theme' ); ?></h2>
		</div>

		<?php if ( $tags ) : ?>
		<div class="art-active-tags">
			<?php foreach ( $tags as [ $label, $url ] ) : ?>
			<a href="<?php echo esc_url( $url ); ?>" class="art-active-tag"><?php echo esc_html( $label ); ?> ×</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="art-clear-all"><?php esc_html_e( 'Quitar todos los filtros', 'oec-theme' ); ?></a>
		</div>
		<?php endif; ?>

		<?php foreach ( $grupos as $key => $g ) :
			$activo  = $estado[ $key ];
			$visible = array_filter( $g['options'], function ( $opt ) use ( $key, $res, $activo ) {
				return ! empty( $res['counts'][ $key ][ $opt ] ) || in_array( $opt, (array) $activo, true ) || 'all' === $opt;
			}, ARRAY_FILTER_USE_KEY );
			if ( ! $visible ) {
				continue;
			}
			?>
		<div class="art-filter-group">
			<h3 class="art-filter-title"><?php echo esc_html( $g['label'] ); ?></h3>
			<ul class="art-filter-list">
				<?php foreach ( $visible as $opt => $label ) :
					if ( ! empty( $g['multi'] ) ) {
						$is_active = in_array( $opt, $activo, true );
						$nuevo     = $is_active ? array_diff( $activo, [ $opt ] ) : array_slice( array_merge( $activo, [ $opt ] ), -$g['multi'] );
						$url       = oec_formaciones_url( [ $key => array_values( $nuevo ) ] );
					} else {
						$is_active = $activo === $opt;
						// Inscripción siempre tiene un valor: el click elige, no apaga.
						$url = oec_formaciones_url( [ $key => $is_active && 'inscripcion' !== $key ? '' : $opt ] );
					}
					$n = (int) ( $res['counts'][ $key ][ $opt ] ?? 0 );
					?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="art-filter-link<?php echo $is_active ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
						<span class="art-filter-count"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endforeach; ?>

		</div><!-- .art-sidebar-body -->

	</aside><!-- .art-sidebar -->

	<!-- ── CONTENIDO ────────────────────────────────────────────── -->
	<div class="art-contenido">

		<form class="art-toolbar" action="<?php echo esc_url( oec_formaciones_url( [ 'q' => '', 'orden' => '' ] ) ); ?>" method="get" role="search" data-oec-live-search>
			<?php
			// Los demás filtros viajan en campos ocultos: buscar u ordenar no los pierde.
			wp_parse_str( (string) wp_parse_url( oec_formaciones_url( [ 'q' => '', 'orden' => '' ] ), PHP_URL_QUERY ), $ocultos );
			foreach ( $ocultos as $name => $value ) :
				?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label class="docentes-search">
				<i class="bi bi-search" aria-hidden="true"></i>
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar formaciones', 'oec-theme' ); ?></span>
				<input type="search" name="q" placeholder="<?php esc_attr_e( 'Buscar por título, docente u organización', 'oec-theme' ); ?>" autocomplete="off" value="<?php echo esc_attr( $estado['q'] ); ?>">
			</label>
			<label class="docentes-sort">
				<span><?php esc_html_e( 'Ordenar por', 'oec-theme' ); ?></span>
				<select name="oec_order">
					<?php foreach ( $ordenes as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $estado['orden'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit" class="btn btn-ghost"><?php esc_html_e( 'Buscar', 'oec-theme' ); ?></button></noscript>
		</form>

		<div data-live-region="results">

		<div class="art-results-header">
			<p class="art-results-count" aria-live="polite">
			<?php if ( $res['total'] > 0 ) :
				printf(
					/* translators: 1: desde, 2: hasta, 3: total */
					esc_html__( 'Mostrando %1$s a %2$s de %3$s formaciones', 'oec-theme' ),
					esc_html( number_format_i18n( ( $paged - 1 ) * OEC_FORMACIONES_POR_PAGINA + 1 ) ),
					esc_html( number_format_i18n( min( $paged * OEC_FORMACIONES_POR_PAGINA, $res['total'] ) ) ),
					esc_html( number_format_i18n( $res['total'] ) )
				);
			else :
				esc_html_e( 'No se encontraron formaciones.', 'oec-theme' );
			endif; ?>
			</p>
		</div>

		<?php if ( $res['items'] ) : ?>

		<h2 class="sr-only"><?php esc_html_e( 'Catálogo de formaciones', 'oec-theme' ); ?></h2>
		<div class="art-grid art-grid--formaciones">
			<?php foreach ( $res['items'] as $r ) : ?>
				<?php echo oec_formacion_card( $r, [ 'org' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<?php endforeach; ?>
		</div>

		<?php
		// Paginador: mismo componente y ventana que /articulos (primera,
		// última, actual±1, el resto con "…"), con los filtros preservados.
		$tp = $res['pages'];
		if ( $tp > 1 ) :
			$page_url = fn( $p ) => esc_url( oec_formaciones_url( [ 'pg' => $p ] ) );
			?>
		<nav class="oec-pager" aria-label="<?php esc_attr_e( 'Paginación de formaciones', 'oec-theme' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?php echo $page_url( $paged - 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página anterior', 'oec-theme' ); ?>" rel="prev"><i class="bi bi-chevron-left"></i></a>
			<?php else : ?>
				<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
			<?php endif; ?>

			<?php for ( $p = 1; $p <= $tp; $p++ ) :
				if ( 1 === $p || $tp === $p || ( $p >= $paged - 1 && $p <= $paged + 1 ) ) :
					if ( $p === $paged ) : ?>
						<span class="oec-pager-num is-active" aria-current="page"><?php echo (int) $p; ?></span>
					<?php else : ?>
						<a href="<?php echo $page_url( $p ); ?>" class="oec-pager-num"><?php echo (int) $p; ?></a>
					<?php endif;
				elseif ( $p === $paged - 2 || $p === $paged + 2 ) : ?>
					<span class="oec-pager-dots">&hellip;</span>
				<?php endif;
			endfor; ?>

			<?php if ( $paged < $tp ) : ?>
				<a href="<?php echo $page_url( $paged + 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página siguiente', 'oec-theme' ); ?>" rel="next"><i class="bi bi-chevron-right"></i></a>
			<?php else : ?>
				<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
			<?php endif; ?>
		</nav>
		<?php endif; ?>

		<?php else : ?>

		<div class="art-empty">
			<p><?php esc_html_e( 'No hay formaciones que coincidan con la búsqueda o los filtros seleccionados.', 'oec-theme' ); ?></p>
			<?php if ( 'opened' === $estado['inscripcion'] ) : ?>
			<a href="<?php echo esc_url( oec_formaciones_url( [ 'inscripcion' => 'all' ] ) ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Buscar también en las cerradas', 'oec-theme' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?></a>
		</div>

		<?php endif; ?>

		</div><!-- [data-live-region=results] -->

	</div><!-- .art-contenido -->

</div><!-- .art-layout -->
</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
