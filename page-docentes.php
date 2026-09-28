<?php
/**
 * Template: Listado de docentes.
 * Se activa automáticamente para la página con slug "docentes".
 *
 * Todos los docentes van en el HTML (así buscadores e IAs siguen el link a
 * cada landing); el filtrado, el orden y el "Mostrar más" los hace
 * main.js en el navegador. Acepta ?tematica=… y ?q=… para llegar ya
 * filtrado (p. ej. desde la tira de docentes de una landing temática).
 */

$oec_docentes = array_values( oec_docentes_catalog() );
usort( $oec_docentes, fn( $a, $b ) => $b['alumnos'] <=> $a['alumnos'] ?: strcmp( $a['slug'], $b['slug'] ) );

$oec_por_pagina = 48;
$oec_tem_counts = [];
foreach ( $oec_docentes as $d ) {
	foreach ( array_intersect( $d['tags'], array_keys( OEC_DOCENTE_TEMATICAS ) ) as $t ) {
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
			[ __( 'Docentes', 'oec-theme' ) ],
		], false );
		?>
		<h1><?php esc_html_e( 'Docentes', 'oec-theme' ); ?></h1>
		<p class="page-hero__lead">
			<?php printf(
				/* translators: %s: cantidad de docentes */
				esc_html__( '%s docentes, investigadores y preparadores enseñan en nuestras formaciones. Conocé su trayectoria, sus formaciones abiertas y lo que dicen sus alumnos.', 'oec-theme' ),
				esc_html( number_format_i18n( count( $oec_docentes ) ) )
			); ?>
		</p>
	</div>
</div>

<main id="main-content">
<div class="articulos-wrap docentes-wrap">
<div class="container">

	<?php if ( $oec_docentes ) : ?>

	<div class="docentes-filters" data-oec-dir data-grid="docentes-grid" data-per-page="<?php echo (int) $oec_por_pagina; ?>" data-noun="<?php esc_attr_e( 'docente|docentes', 'oec-theme' ); ?>">
		<div class="docentes-toolbar">
			<label class="docentes-search">
				<i class="bi bi-search" aria-hidden="true"></i>
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar docentes', 'oec-theme' ); ?></span>
				<input type="search" data-dir-q placeholder="<?php esc_attr_e( 'Buscar por nombre o especialidad', 'oec-theme' ); ?>" autocomplete="off" value="<?php echo esc_attr( wp_unslash( $_GET['q'] ?? '' ) ); ?>">
			</label>
			<label class="docentes-sort">
				<span><?php esc_html_e( 'Ordenar por', 'oec-theme' ); ?></span>
				<select data-dir-sort>
					<option value="alumnos"><?php esc_html_e( 'Más alumnos formados', 'oec-theme' ); ?></option>
					<option value="abiertas"><?php esc_html_e( 'Inscripción abierta primero', 'oec-theme' ); ?></option>
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

	<div class="docentes-grid" id="docentes-grid">
		<?php foreach ( $oec_docentes as $i => $d ) : ?>
			<?php
			echo oec_docente_card( $d, $i % $oec_por_pagina, [ // phpcs:ignore WordPress.Security.EscapeOutput
				'search'  => remove_accents( mb_strtolower( implode( ' ', array_merge(
					[ $d['name'], $d['background'], $d['org'] ],
					array_intersect_key( OEC_DOCENTE_TEMATICAS, array_flip( $d['tags'] ) )
				) ) ) ),
				'tags'    => implode( ' ', $d['tags'] ),
				'alumnos' => (string) $d['alumnos'],
				'open'    => $d['next'] ? '1' : '0',
				'sort'    => $d['slug'],
			], true );
			?>
		<?php endforeach; ?>
	</div>

	<div class="art-empty docentes-empty" data-dir-empty="docentes-grid" hidden>
		<p><?php esc_html_e( 'No encontramos docentes con esa búsqueda.', 'oec-theme' ); ?></p>
		<button type="button" class="btn btn-ghost" data-dir-reset><?php esc_html_e( 'Ver todos los docentes', 'oec-theme' ); ?></button>
	</div>

	<div class="docentes-more-wrap">
		<button type="button" class="btn btn-ghost" data-dir-more="docentes-grid" hidden><?php esc_html_e( 'Mostrar más docentes', 'oec-theme' ); ?></button>
	</div>

	<?php else : ?>

	<div class="art-empty">
		<p><?php esc_html_e( 'Todavía no hay docentes para mostrar.', 'oec-theme' ); ?></p>
		<a href="<?php echo esc_url( home_url( user_trailingslashit( '/formaciones' ) ) ); ?>" class="btn btn-ghost"><?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?></a>
	</div>

	<?php endif; ?>

</div><!-- .container -->
</div><!-- .articulos-wrap -->
</main>

<?php get_footer(); ?>
