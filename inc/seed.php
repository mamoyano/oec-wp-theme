<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SEMBRADOR DE PÁGINAS — primera instalación del tema

   El home y las landings de temática llevan su HTML/CSS/JSON-LD en el
   post_content (no en plantillas), así que no viajan con el tema. Acá
   quedan como archivos (seed/pages/*.html) y se crean solas, UNA vez,
   la primera vez que un administrador entra a wp-admin del sitio.

   - Si ya existe una página con ese slug NO se toca (nunca pisa lo que
     se edite en producción). Única excepción: si existe pero está vacía
     (p. ej. "organizaciones", que la crea vacía inc/organizations.php),
     se le completa el contenido.
   - Home y landings se crean en BORRADOR: se revisan con "Vista previa",
     se publican a mano y el home se elige como portada en Ajustes →
     Lectura. Las páginas cuyo contenido real es la plantilla del tema
     (especiales, artículos, organizaciones) se publican directo, igual
     que las que ya crean docentes.php / organizations.php.
   - Las imágenes (seed/img/) se suben a la biblioteca de medios y en el
     contenido {{img:archivo.png}} se reemplaza por su URL.
   - Los archivos son una foto del contenido de Local al momento de
     exportarlos: los cambios posteriores en producción no vuelven acá.
   - Solo en el sitio de la red OEC_SEED_SITE_PATH (el contenido es en
     español). Para volver a correrlo: borrar la opción oec_seed_v1.
   ============================================================ */

if ( ! defined( 'OEC_SEED_SITE_PATH' ) ) {
	define( 'OEC_SEED_SITE_PATH', '/es/' );
}

/** Páginas a sembrar, en orden (los padres antes que los hijos). */
function oec_seed_pages(): array {
	return [
		'home'                                  => [ 'title' => 'Formación en Ciencias del Ejercicio', 'status' => 'draft', 'thumbnail' => 'Portada-Entrenamiento-de-la-Fuerza-1.png' ],
		'especiales'                            => [ 'title' => 'Especiales', 'status' => 'publish' ],
		'especiales/nutricion-deportiva'        => [ 'title' => 'Formaciones en Nutrición Deportiva', 'status' => 'draft' ],
		'especiales/entrenamiento-de-la-fuerza' => [ 'title' => 'Formaciones en Entrenamiento de la Fuerza', 'status' => 'draft' ],
		'articulos'                             => [ 'title' => 'Artículos', 'status' => 'publish' ],
		'organizaciones'                        => [ 'title' => 'Organizaciones', 'status' => 'publish' ],
	];
}

function oec_seed_run(): void {
	if ( get_option( 'oec_seed_v1' ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
		return;
	}
	if ( is_multisite() && get_site()->path !== OEC_SEED_SITE_PATH ) {
		return;
	}
	// Candado: dos pestañas de wp-admin a la vez no deben sembrar dos veces.
	if ( get_transient( 'oec_seed_lock' ) ) {
		return;
	}
	set_transient( 'oec_seed_lock', 1, 10 * MINUTE_IN_SECONDS );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // subir ~30 imágenes y generar sus tamaños lleva un rato
	}

	$dir    = get_template_directory() . '/seed/pages/';
	$ids    = [];
	$report = [];

	// Sin esto, en multisitio (solo el superadmin tiene unfiltered_html)
	// kses borra el <style>, los <script> del JSON-LD y el chat.
	kses_remove_filters();

	foreach ( oec_seed_pages() as $path => $page ) {
		$slug    = basename( $path );
		$parent  = dirname( $path );
		$content = (string) @file_get_contents( $dir . $slug . '.html' ); // phpcs:ignore
		$content = preg_replace_callback( '/\{\{img:([^}]+)\}\}/', fn( $m ) => oec_seed_image( $m[1] ), $content );

		$existing = get_page_by_path( $path );
		if ( $existing ) {
			$ids[ $path ] = $existing->ID;
			if ( '' === trim( $existing->post_content ) && '' !== trim( $content ) ) {
				wp_update_post( wp_slash( [ 'ID' => $existing->ID, 'post_content' => $content ] ) );
				$report[] = [ $existing->ID, $page['title'], 'filled' ];
			} else {
				$report[] = [ $existing->ID, $page['title'], 'skipped' ];
			}
			continue;
		}

		$id = wp_insert_post( wp_slash( [
			'post_type'      => 'page',
			'post_status'    => $page['status'],
			'post_title'     => $page['title'],
			'post_name'      => $slug,
			'post_parent'    => '.' !== $parent ? ( $ids[ $parent ] ?? 0 ) : 0,
			'post_content'   => $content,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] ), true );
		if ( is_wp_error( $id ) ) {
			$report[] = [ 0, $page['title'], 'error: ' . $id->get_error_message() ];
			continue;
		}
		$ids[ $path ] = $id;
		if ( ! empty( $page['thumbnail'] ) && ( $att = oec_seed_image( $page['thumbnail'], true ) ) ) {
			set_post_thumbnail( $id, $att );
		}
		$report[] = [ $id, $page['title'], $page['status'] ];
	}

	kses_init();
	update_option( 'oec_seed_v1', time(), false );
	update_option( 'oec_seed_report', $report, false );
	delete_transient( 'oec_seed_lock' );
}
// Prioridad 20: después de oec_create_organizaciones_page (10), que crea
// "organizaciones" vacía; acá se le completa el contenido.
add_action( 'admin_init', 'oec_seed_run', 20 );

/**
 * Sube seed/img/$file a la biblioteca de medios (una sola vez, aunque se
 * pida varias) y devuelve su URL — o su ID con $return_id.
 */
function oec_seed_image( string $file, bool $return_id = false ) {
	static $cache = [];
	if ( ! isset( $cache[ $file ] ) ) {
		$found = get_posts( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_oec_seed_file',
			'meta_value'  => $file,
			'fields'      => 'ids',
			'numberposts' => 1,
		] );
		$id = $found[0] ?? 0;
		$src = get_template_directory() . '/seed/img/' . basename( $file );
		if ( ! $id && is_readable( $src ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			// media_handle_sideload mueve el archivo: se le pasa una copia.
			$tmp = wp_tempnam( $file );
			copy( $src, $tmp );
			$id = media_handle_sideload( [ 'name' => basename( $file ), 'tmp_name' => $tmp ], 0 );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore
				$id = 0;
			} else {
				update_post_meta( $id, '_oec_seed_file', $file );
			}
		}
		$cache[ $file ] = (int) $id;
	}
	if ( $return_id ) {
		return $cache[ $file ];
	}
	return $cache[ $file ] ? (string) wp_get_attachment_url( $cache[ $file ] ) : '';
}

/** Aviso único en wp-admin con lo que hizo el sembrador. */
add_action( 'admin_notices', function () {
	$report = get_option( 'oec_seed_report' );
	if ( ! $report || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_option( 'oec_seed_report' );
	$labels = [
		'draft'   => __( 'creada en borrador — revisala y publicala', 'oec-theme' ),
		'publish' => __( 'creada y publicada', 'oec-theme' ),
		'filled'  => __( 'ya existía vacía: se completó su contenido', 'oec-theme' ),
		'skipped' => __( 'ya existía: no se tocó', 'oec-theme' ),
	];
	echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Tema OEC — páginas iniciales', 'oec-theme' ) . '</strong></p><ul style="list-style:disc;padding-left:1.5em">';
	foreach ( $report as [ $id, $title, $status ] ) {
		$label = $labels[ $status ] ?? $status;
		$name  = $id ? '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		echo '<li>' . $name . ': ' . esc_html( $label ) . '</li>'; // phpcs:ignore
	}
	echo '</ul><p>' . esc_html__( 'El home, una vez publicado, se elige como portada en Ajustes → Lectura.', 'oec-theme' ) . '</p></div>';
} );
