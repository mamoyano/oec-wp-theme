<?php
/**
 * Template: Listado de organizaciones.
 * Se activa automáticamente para la página con slug "organizaciones".
 *
 * Mismo esquema que page-docentes.php: todas las organizaciones van en el
 * HTML y main.js filtra por búsqueda/temática y ordena ([data-oec-dir]).
 * Acepta ?tematica=… y ?q=…
 */

$oec_orgs = class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_organizations( 1 ) : [];
usort( $oec_orgs, fn( $a, $b ) => ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 ) ?: strcasecmp( $a['name'], $b['name'] ) );

$oec_tem_counts = [];
foreach ( $oec_orgs as $o ) {
	foreach ( array_keys( $o['tematicas'] ?? [] ) as $t ) {
		$oec_tem_counts[ $t ] = ( $oec_tem_counts[ $t ] ?? 0 ) + 1;
	}
}
$oec_tem_activa = sanitize_title( wp_unslash( $_GET['tematica'] ?? '' ) );
if ( ! isset( OEC_DOCENTE_TEMATICAS[ $oec_tem_activa ] ) ) {
	$oec_tem_activa = '';
}

get_header();
?>

<div class="page-hero page-hero--articulos">
	<div class="container">
		<?php
		oec_breadcrumb( [
			[ __( 'Formaciones', 'oec-theme' ), home_url( user_trailingslashit( '/formaciones' ) ) ],
			[ __( 'Organizaciones', 'oec-theme' ) ],
		] );
		?>
		<h1><?php esc_html_e( 'Organizaciones', 'oec-theme' ); ?></h1>
		<p class="page-hero__lead">
			<?php printf(
				/* translators: %s: cantidad de organizaciones */
				esc_html__( '%s universidades, instituciones y centros de formación publican sus cursos, diplomados y posgrados con nosotros. Conocé sus formaciones abiertas.', 'oec-theme' ),
				esc_html( number_format_i18n( count( $oec_orgs ) ) )
			); ?>
		</p>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap docentes-wrap">
<div class="container">

	<?php if ( $oec_orgs ) : ?>

	<div class="docentes-filters" data-oec-dir data-grid="orgs-grid" data-noun="<?php esc_attr_e( 'organización|organizaciones', 'oec-theme' ); ?>">
		<div class="docentes-toolbar">
			<label class="docentes-search">
				<i class="bi bi-search" aria-hidden="true"></i>
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar organizaciones', 'oec-theme' ); ?></span>
				<input type="search" data-dir-q placeholder="<?php esc_attr_e( 'Buscar por nombre', 'oec-theme' ); ?>" autocomplete="off" value="<?php echo esc_attr( wp_unslash( $_GET['q'] ?? '' ) ); ?>">
			</label>
			<label class="docentes-sort">
				<span><?php esc_html_e( 'Ordenar por', 'oec-theme' ); ?></span>
				<select data-dir-sort>
					<option value="formaciones"><?php esc_html_e( 'Más formaciones abiertas', 'oec-theme' ); ?></option>
					<option value="nombre"><?php esc_html_e( 'Nombre (A–Z)', 'oec-theme' ); ?></option>
				</select>
			</label>
		</div>

		<div class="docentes-chips" role="group" aria-label="<?php esc_attr_e( 'Filtrar por temática', 'oec-theme' ); ?>">
			<button type="button" class="docentes-chip" data-dir-chip data-tematica="" aria-pressed="<?php echo $oec_tem_activa ? 'false' : 'true'; ?>"><?php esc_html_e( 'Todas', 'oec-theme' ); ?></button>
			<?php foreach ( OEC_DOCENTE_TEMATICAS as $slug => $label ) : ?>
				<?php if ( empty( $oec_tem_counts[ $slug ] ) ) { continue; } ?>
			<button type="button" class="docentes-chip" data-dir-chip data-tematica="<?php echo esc_attr( $slug ); ?>" aria-pressed="<?php echo $slug === $oec_tem_activa ? 'true' : 'false'; ?>">
				<?php echo esc_html( $label ); ?> <span><?php echo esc_html( number_format_i18n( $oec_tem_counts[ $slug ] ) ); ?></span>
			</button>
			<?php endforeach; ?>
		</div>

		<p class="docentes-count" data-dir-count aria-live="polite"></p>
	</div>

	<div class="oec-organizations__grid orgs-dir-grid" id="orgs-grid">
		<?php foreach ( $oec_orgs as $o ) :
			$oec_labels = array_intersect_key( OEC_DOCENTE_TEMATICAS, $o['tematicas'] ?? [] );
			?>
		<a class="oec-organizations__item orgs-dir-item"
		   href="<?php echo esc_url( oec_organizacion_url( $o['slug'] ) ); ?>"
		   data-search="<?php echo esc_attr( remove_accents( mb_strtolower( $o['name'] . ' ' . $o['short_name'] . ' ' . implode( ' ', $oec_labels ) ) ) ); ?>"
		   data-tags="<?php echo esc_attr( implode( ' ', array_keys( $o['tematicas'] ?? [] ) ) ); ?>"
		   data-formaciones="<?php echo (int) ( $o['count'] ?? 0 ); ?>"
		   data-sort="<?php echo esc_attr( sanitize_title( remove_accents( $o['name'] ) ) ); ?>">
			<span class="oec-organizations__logo">
				<?php if ( ! empty( $o['logo'] ) ) : ?>
				<img src="<?php echo esc_url( oec_cdn_resize( $o['logo'], 400, 90 ) ); ?>" alt="<?php echo esc_attr( $o['name'] ); ?>" loading="lazy">
				<?php else : ?>
				<span class="oec-organizations__fallback"><?php echo esc_html( $o['short_name'] ?: $o['name'] ); ?></span>
				<?php endif; ?>
			</span>
			<span class="oec-organizations__name"><?php echo esc_html( $o['name'] ); ?></span>
			<span class="orgs-dir-item__count">
				<?php echo esc_html( sprintf( _n( '%s formación abierta', '%s formaciones abiertas', (int) $o['count'], 'oec-theme' ), number_format_i18n( (int) $o['count'] ) ) ); ?>
			</span>
		</a>
		<?php endforeach; ?>
	</div>

	<div class="art-empty docentes-empty" data-dir-empty="orgs-grid" hidden>
		<p><?php esc_html_e( 'No encontramos organizaciones con esa búsqueda.', 'oec-theme' ); ?></p>
		<button type="button" class="btn btn-ghost" data-dir-reset><?php esc_html_e( 'Ver todas las organizaciones', 'oec-theme' ); ?></button>
	</div>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'Todavía no hay organizaciones para mostrar.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?></a>
	</div>

	<?php endif; ?>

	<?php
	// Contenido editable de la página (desde WordPress): la vitrina de webs
	// de socios [oec-sitios variante="grande"], que además alimenta el botón
	// "Visitar su sitio web" de cada organización/docente. do_shortcode
	// directo, sin wpautop, para que no meta <p>/<br> entre los [oec-sitio].
	$oec_extra = trim( (string) get_post_field( 'post_content', get_queried_object_id() ) );
	if ( $oec_extra ) :
		?>
	<div class="oec-orgs-dir-sitios">
		<?php echo do_shortcode( $oec_extra ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>
	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
