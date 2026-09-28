<?php
/**
 * Template: Listado de artículos, blogs y VigIA.
 * Auto-seleccionado por WordPress para la página con slug "articulos".
 */

$tipo_slugs  = [ 'articulos', 'blogs', 'vigia' ];
$tipo_labels = oec_tipo_labels();

// ── Filtros activos ───────────────────────────────────────────────────────
$f_tipo     = sanitize_key( wp_unslash( $_GET['tipo']     ?? '' ) );
$f_tematica = sanitize_key( wp_unslash( $_GET['tematica'] ?? '' ) );
$f_anio_raw = sanitize_key( wp_unslash( $_GET['anio']     ?? '' ) );
$f_anio     = ( $f_anio_raw === 'antes' ) ? 'antes' : absint( $f_anio_raw );
$f_autor    = absint( $_GET['autor'] ?? 0 );
$f_q        = oec_articulos_q();

// Término de la temática activa y autor activo (se reutilizan en el hero y en los tags activos).
$tematica_term = $f_tematica ? get_term_by( 'slug', $f_tematica, 'category' ) : null;
$autor_user    = $f_autor ? get_userdata( $f_autor ) : null;

// ── Título dinámico del hero: "Artículos" / "Blogs" / "VigIA" + " de {Temática}" + " por {Autor}" ──
$tipo_labels_plural = oec_tipo_labels_plural();
$hero_title         = $tipo_labels_plural[ $f_tipo ] ?? __( 'Artículos', 'oec-theme' );
if ( $tematica_term instanceof WP_Term ) {
	$hero_title = sprintf(
		/* translators: 1: tipo de contenido (Artículos/Blogs/VigIA), 2: nombre de la temática */
		__( '%1$s de %2$s', 'oec-theme' ),
		$hero_title,
		$tematica_term->name
	);
}
if ( $autor_user ) {
	$hero_title = sprintf(
		/* translators: 1: título acumulado (p. ej. "Blogs de Ciclismo"), 2: nombre del autor */
		__( '%1$s por %2$s', 'oec-theme' ),
		$hero_title,
		$autor_user->display_name
	);
}

// ── Bajada dinámica del hero, según el tipo de contenido activo ──
$hero_leads = [
	''          => __( 'Artículos científicos, blogs editoriales y resúmenes semanales de VigIA. Todo el conocimiento del deporte en un solo lugar.', 'oec-theme' ),
	'articulos' => __( 'Artículos científicos que resumen y traducen a un lenguaje claro la evidencia más reciente en ciencias del ejercicio, la nutrición deportiva y la salud.', 'oec-theme' ),
	'blogs'     => __( 'Blogs editoriales con contenido práctico sobre entrenamiento, nutrición deportiva y salud, pensados para llevar la ciencia al día a día.', 'oec-theme' ),
	'vigia'     => __( 'VigIA es el sistema de OEC que usa inteligencia artificial para revisar a diario bases científicas y detectar nuevas publicaciones sobre las ciencias del ejercicio.', 'oec-theme' ),
];
$hero_lead = $hero_leads[ $f_tipo ] ?? $hero_leads[''];
// WordPress canonicaliza ?paged=N a la URL "bonita" /articulos/page/N/ —
// ahí el número de página viaja en la query var 'paged' de WP, no en $_GET.
$paged      = max( 1, absint( get_query_var( 'paged' ) ?: ( $_GET['paged'] ?? 1 ) ) );

// ── WP_Query ──────────────────────────────────────────────────────────────
$tax_query = [ 'relation' => 'AND' ];
if ( $f_tipo && in_array( $f_tipo, $tipo_slugs, true ) ) {
	$tax_query[] = [ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => $f_tipo ];
}
if ( $f_tematica ) {
	$tax_query[] = [ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => $f_tematica ];
}

$date_query = [];
if ( $f_anio === 'antes' ) {
	$date_query = [ [ 'before' => '2024-01-01', 'inclusive' => false ] ];
} elseif ( $f_anio > 0 ) {
	$date_query = [ [ 'year' => $f_anio ] ];
}

$args = [
	'post_type'      => 'post',
	'posts_per_page' => 12,
	'paged'          => $paged,
];
if ( count( $tax_query ) > 1 ) {
	$args['tax_query'] = $tax_query;
}
if ( $date_query ) {
	$args['date_query'] = $date_query;
}
if ( $f_autor ) {
	$args['author'] = $f_autor;
}
if ( $f_q !== '' ) {
	$args['s'] = $f_q; // WP ordena por relevancia (título primero)
}

$art_query = new WP_Query( $args );

// ── Categorías TEMÁTICA para la sidebar ──────────────────────────────────
$excluir_slugs = array_merge( $tipo_slugs, [ 'general' ] );
$excluir_ids   = array_filter( array_map(
	fn( $s ) => ( $t = get_term_by( 'slug', $s, 'category' ) ) ? $t->term_id : 0,
	$excluir_slugs
) );
$tematicas = get_categories( [
	'exclude'    => array_values( $excluir_ids ),
	'hide_empty' => true,
	'orderby'    => 'name',
] );

// ── Años disponibles ──────────────────────────────────────────────────────
$current_year = (int) date( 'Y' );
$years_range  = range( $current_year, 2024 );

// ── Top 10 autores con más publicaciones ─────────────────────────────────
$autores_con_posts = get_users( [
	'has_published_posts' => [ 'post' ],
	'fields'               => [ 'ID', 'display_name' ],
] );
foreach ( $autores_con_posts as $autor ) {
	$autor->post_count = count_user_posts( $autor->ID, 'post', true );
}
usort( $autores_con_posts, fn( $a, $b ) => $b->post_count <=> $a->post_count );
$autores_top = array_slice( $autores_con_posts, 0, 10 );

// ── SEO: canonical / robots según combinación de filtros ──────────────────
// WordPress emite su propio canonical por defecto (rel_canonical) apuntando
// siempre a /articulos/ sin importar los filtros — lo sacamos para no duplicar
// la etiqueta y controlar nosotros el canonical real según el filtro activo.
remove_action( 'wp_head', 'rel_canonical' );

// Sin filtros -> canonical a sí misma. Un solo filtro tipo/temática -> canonical
// a la página de categoría nativa (/category/slug/), que es la URL indexable.
// Cualquier combinación de 2+ filtros, o año/autor solos -> noindex (no aportan
// contenido único para el buscador, evitan contenido duplicado).
add_action( 'wp_head', function () use ( $f_tipo, $f_tematica, $f_anio, $f_autor, $f_q ): void {
	$active_filters = array_filter( [
		'tipo'     => $f_tipo,
		'tematica' => $f_tematica,
		'anio'     => $f_anio,
		'autor'    => $f_autor,
		'q'        => $f_q,
	] );
	$active_count = count( $active_filters );

	if ( 0 === $active_count ) {
		$current_url = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/articulos/' ) );
		echo '<link rel="canonical" href="' . esc_url( $current_url ) . '">' . "\n";
		return;
	}

	if ( 1 === $active_count && isset( $active_filters['tipo'] ) ) {
		$term = get_term_by( 'slug', $f_tipo, 'category' );
		if ( $term instanceof WP_Term ) {
			echo '<link rel="canonical" href="' . esc_url( get_term_link( $term ) ) . '">' . "\n";
			return;
		}
	}

	if ( 1 === $active_count && isset( $active_filters['tematica'] ) ) {
		$term = get_term_by( 'slug', $f_tematica, 'category' );
		if ( $term instanceof WP_Term ) {
			echo '<link rel="canonical" href="' . esc_url( get_term_link( $term ) ) . '">' . "\n";
			return;
		}
	}

	echo '<meta name="robots" content="noindex,follow">' . "\n";
}, 5 );

// ── OG image con logo del tema ────────────────────────────────────────────
add_action( 'wp_head', 'oec_output_og_image_meta', 20 );

get_header();
?>

<div class="page-hero page-hero--articulos<?php echo $paged > 1 ? ' page-hero--paged' : ''; ?>">
	<div class="container">
		<?php
		oec_breadcrumb( $paged > 1
			? [ [ $hero_title, oec_articulos_url( [] ) ], [ sprintf( __( 'Página %d', 'oec-theme' ), $paged ) ] ]
			: [ [ $hero_title ] ] );
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
<div class="articulos-wrap">
<div class="container">

<div class="art-layout">

	<!-- ── SIDEBAR ─────────────────────────────────────────────── -->
	<aside class="art-sidebar" aria-label="<?php esc_attr_e( 'Filtros de artículos', 'oec-theme' ); ?>">

		<?php $f_active_n = count( array_filter( [ $f_tipo, $f_tematica, $f_anio, $f_autor, '' !== $f_q ] ) ); ?>
		<button class="art-filter-toggle" aria-expanded="false" aria-controls="art-sidebar-body" id="art-filter-toggle" data-live-region="toggle">
			<svg class="art-filter-toggle-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
			<span class="art-filter-toggle-label"><?php esc_html_e( 'Filtros', 'oec-theme' ); ?></span>
			<?php if ( $f_active_n ) : ?>
			<span class="art-filter-toggle-count" aria-label="<?php /* translators: %d: cantidad de filtros activos */ echo esc_attr( sprintf( _n( '%d filtro activo', '%d filtros activos', $f_active_n, 'oec-theme' ), $f_active_n ) ); ?>"><?php echo (int) $f_active_n; ?></span>
			<?php endif; ?>
			<svg class="art-filter-toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
		</button>

		<div class="art-sidebar-body" id="art-sidebar-body" data-live-region="sidebar">

		<div class="art-sidebar-header">
			<h2 class="art-sidebar-title"><?php esc_html_e( 'Filtrar por', 'oec-theme' ); ?></h2>
		</div>

		<?php if ( $f_tipo || $f_tematica || $f_anio || $f_autor || $f_q !== '' ) : ?>
		<div class="art-active-tags">
			<?php if ( $f_tipo ) : ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'tipo' => '' ] ) ); ?>" class="art-active-tag">
				<?php echo esc_html( $tipo_labels[ $f_tipo ] ?? $f_tipo ); ?> ×
			</a>
			<?php endif; ?>
			<?php if ( $f_tematica ) : ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'tematica' => '' ] ) ); ?>" class="art-active-tag">
				<?php echo esc_html( $tematica_term ? $tematica_term->name : $f_tematica ); ?> ×
			</a>
			<?php endif; ?>
			<?php if ( $f_anio ) : ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'anio' => '' ] ) ); ?>" class="art-active-tag">
				<?php echo $f_anio === 'antes' ? esc_html__( 'Antes de 2024', 'oec-theme' ) : esc_html( $f_anio ); ?> ×
			</a>
			<?php endif; ?>
			<?php if ( $f_autor ) : ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'autor' => '' ] ) ); ?>" class="art-active-tag">
				<?php echo esc_html( $autor_user ? $autor_user->display_name : $f_autor ); ?> ×
			</a>
			<?php endif; ?>
			<?php if ( $f_q !== '' ) : ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'q' => '' ] ) ); ?>" class="art-active-tag">
				<?php /* translators: %s: texto buscado */ echo esc_html( sprintf( __( '“%s”', 'oec-theme' ), $f_q ) ); ?> ×
			</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'tipo' => '', 'tematica' => '', 'anio' => '', 'autor' => '', 'q' => '' ] ) ); ?>" class="art-clear-all">
				<?php esc_html_e( 'Quitar todos los filtros', 'oec-theme' ); ?>
			</a>
		</div>
		<?php endif; ?>

		<!-- TIPO -->
		<div class="art-filter-group">
			<h3 class="art-filter-title"><?php esc_html_e( 'TIPO', 'oec-theme' ); ?></h3>
			<ul class="art-filter-list">
				<?php foreach ( $tipo_labels as $slug => $label ) :
					$is_active = $f_tipo === $slug;
					$url = $is_active
						? oec_articulos_url( [ 'tipo' => '' ] )
						: oec_articulos_url( [ 'tipo' => $slug ] );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="art-filter-link<?php echo $is_active ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<!-- TEMÁTICA -->
		<?php if ( $tematicas ) : ?>
		<div class="art-filter-group">
			<h3 class="art-filter-title"><?php esc_html_e( 'TEMÁTICA', 'oec-theme' ); ?></h3>
			<ul class="art-filter-list">
				<?php foreach ( $tematicas as $cat ) :
					$is_active = $f_tematica === $cat->slug;
					$url = $is_active
						? oec_articulos_url( [ 'tematica' => '' ] )
						: oec_articulos_url( [ 'tematica' => $cat->slug ] );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="art-filter-link<?php echo $is_active ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $cat->name ); ?>
						<span class="art-filter-count"><?php echo (int) $cat->count; ?></span>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- AUTOR -->
		<?php if ( $autores_top ) : ?>
		<div class="art-filter-group">
			<h3 class="art-filter-title"><?php esc_html_e( 'AUTOR', 'oec-theme' ); ?></h3>
			<ul class="art-filter-list">
				<?php foreach ( $autores_top as $autor ) :
					$is_active = $f_autor === (int) $autor->ID;
					$url = $is_active
						? oec_articulos_url( [ 'autor' => '' ] )
						: oec_articulos_url( [ 'autor' => (string) $autor->ID ] );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="art-filter-link<?php echo $is_active ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $autor->display_name ); ?>
						<span class="art-filter-count"><?php echo (int) $autor->post_count; ?></span>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- AÑO -->
		<div class="art-filter-group">
			<h3 class="art-filter-title"><?php esc_html_e( 'AÑO', 'oec-theme' ); ?></h3>
			<ul class="art-filter-list">
				<?php foreach ( $years_range as $y ) :
					$is_active = (string) $f_anio === (string) $y;
					$url = $is_active
						? oec_articulos_url( [ 'anio' => '' ] )
						: oec_articulos_url( [ 'anio' => (string) $y ] );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="art-filter-link<?php echo $is_active ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $y ); ?>
					</a>
				</li>
				<?php endforeach; ?>
				<?php
				$is_antes = $f_anio === 'antes';
				$url_antes = $is_antes
					? oec_articulos_url( [ 'anio' => '' ] )
					: oec_articulos_url( [ 'anio' => 'antes' ] );
				?>
				<li>
					<a href="<?php echo esc_url( $url_antes ); ?>"
					   class="art-filter-link<?php echo $is_antes ? ' is-active' : ''; ?>"
					   aria-current="<?php echo $is_antes ? 'true' : 'false'; ?>">
						<?php esc_html_e( 'Antes de 2024', 'oec-theme' ); ?>
					</a>
				</li>
			</ul>
		</div>

		</div><!-- .art-sidebar-body -->

	</aside><!-- .art-sidebar -->

	<!-- ── CONTENIDO ────────────────────────────────────────────── -->
	<div class="art-contenido">

		<form class="art-toolbar" action="<?php echo esc_url( oec_articulos_url( [ 'q' => '' ] ) ); ?>" method="get" role="search" data-oec-live-search>
			<?php
			// Los demás filtros viajan en campos ocultos: buscar no los pierde.
			foreach ( array_filter( [ 'tipo' => $f_tipo, 'tematica' => $f_tematica, 'anio' => (string) $f_anio, 'autor' => $f_autor ? (string) $f_autor : '' ] ) as $name => $value ) :
				?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label class="docentes-search">
				<i class="bi bi-search" aria-hidden="true"></i>
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar artículos', 'oec-theme' ); ?></span>
				<input type="search" name="q" placeholder="<?php esc_attr_e( 'Buscar por título o tema', 'oec-theme' ); ?>" autocomplete="off" value="<?php echo esc_attr( $f_q ); ?>">
			</label>
			<noscript><button type="submit" class="btn btn-ghost"><?php esc_html_e( 'Buscar', 'oec-theme' ); ?></button></noscript>
		</form>

		<div data-live-region="results">

		<div class="art-results-header">
			<?php if ( $art_query->found_posts > 0 ) :
				$from = ( $paged - 1 ) * 12 + 1;
				$to   = min( $paged * 12, $art_query->found_posts );
			?>
			<p class="art-results-count">
				<?php printf(
					/* translators: 1: from, 2: to, 3: total */
					esc_html__( 'Mostrando %1$d a %2$d de %3$d entradas', 'oec-theme' ),
					$from, $to, $art_query->found_posts
				); ?>
			</p>
			<?php else : ?>
			<p class="art-results-count"><?php esc_html_e( 'No se encontraron entradas.', 'oec-theme' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $art_query->have_posts() ) : ?>

		<div class="art-grid">
			<?php while ( $art_query->have_posts() ) : $art_query->the_post(); ?>
				<?php get_template_part( 'template-parts/content', 'card' ); ?>
			<?php endwhile; ?>
		</div>

		<?php
		// Paginación preservando filtros activos — mismo componente visual
		// (clases .oec-pager*) y misma ventana (primera, última, actual±1,
		// resto con "…") que el paginador de /formaciones, a pedido de Mario.
		$oec_art_tp = (int) $art_query->max_num_pages;
		if ( $oec_art_tp > 1 ) :
			$oec_art_cp   = $paged;
			$pagination_base = oec_articulos_url( [] );
			$oec_art_page_url = fn( $p ) => esc_url( $p > 1 ? add_query_arg( 'paged', $p, $pagination_base ) : $pagination_base );
			?>
		<nav class="oec-pager" aria-label="<?php esc_attr_e( 'Paginación de artículos', 'oec-theme' ); ?>">
			<?php if ( $oec_art_cp > 1 ) : ?>
				<a href="<?php echo $oec_art_page_url( $oec_art_cp - 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página anterior', 'oec-theme' ); ?>" rel="prev"><i class="bi bi-chevron-left"></i></a>
			<?php else : ?>
				<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
			<?php endif; ?>

			<?php for ( $p = 1; $p <= $oec_art_tp; $p++ ) :
				if ( 1 === $p || $oec_art_tp === $p || ( $p >= $oec_art_cp - 1 && $p <= $oec_art_cp + 1 ) ) :
					if ( $p === $oec_art_cp ) : ?>
						<span class="oec-pager-num is-active" aria-current="page"><?php echo (int) $p; ?></span>
					<?php else : ?>
						<a href="<?php echo $oec_art_page_url( $p ); ?>" class="oec-pager-num"><?php echo (int) $p; ?></a>
					<?php endif;
				elseif ( $p === $oec_art_cp - 2 || $p === $oec_art_cp + 2 ) : ?>
					<span class="oec-pager-dots">&hellip;</span>
				<?php endif;
			endfor; ?>

			<?php if ( $oec_art_cp < $oec_art_tp ) : ?>
				<a href="<?php echo $oec_art_page_url( $oec_art_cp + 1 ); ?>" class="oec-pager-arrow" aria-label="<?php esc_attr_e( 'Página siguiente', 'oec-theme' ); ?>" rel="next"><i class="bi bi-chevron-right"></i></a>
			<?php else : ?>
				<span class="oec-pager-arrow is-disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
			<?php endif; ?>
		</nav>
			<?php
		endif;
		?>

		<?php else : ?>

		<div class="art-empty">
			<p><?php esc_html_e( 'No hay entradas que coincidan con los filtros seleccionados.', 'oec-theme' ); ?></p>
			<a href="<?php echo esc_url( oec_articulos_url( [ 'tipo' => '', 'tematica' => '', 'anio' => '', 'autor' => '', 'q' => '' ] ) ); ?>"
			   class="btn btn-ghost">
				<?php esc_html_e( 'Ver todas las entradas', 'oec-theme' ); ?>
			</a>
		</div>

		<?php endif; wp_reset_postdata(); ?>

		</div><!-- [data-live-region=results] -->

	</div><!-- .art-contenido -->

</div><!-- .art-layout -->
</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
