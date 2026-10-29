<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   VISOR DE PDF — [oec-pdf]

   [oec-pdf url="https://g-se.com/wp-content/uploads/…/paper.pdf" title="…"]
   [oec-pdf id="12345"]  (un PDF de la biblioteca de medios)

   La entrada solo trae una tarjeta: ícono, título, páginas y peso, y los
   botones "Ver PDF" y "Descargar". Nada del visor carga con la página:
   recién al tocar "Ver PDF" se bajan PDF.js (assets/vendor/pdfjs, la
   librería de Firefox, ~520 KB comprimida, queda en caché) y las partes
   del PDF que se van mostrando (pedidos por rango, no el archivo entero).
   Así funciona igual en celulares, donde el navegador no muestra PDFs
   dentro de la página. El script de la tarjeta (assets/js/pdf-viewer.js,
   unos KB con defer) solo se encola en las entradas con el shortcode.

   El PDF tiene que estar en este mismo sitio: PDF.js lo lee con fetch y
   otro dominio lo bloquea (CORS). Si es de afuera, o falla, "Ver PDF"
   lo abre en una pestaña nueva.
   ============================================================ */

add_shortcode( 'oec-pdf', 'oec_render_pdf_shortcode' );
function oec_render_pdf_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'url'   => '',
		'id'    => 0,
		'title' => '',
	], $atts, 'oec-pdf' );

	$id  = (int) $atts['id'];
	$url = $id ? (string) wp_get_attachment_url( $id ) : trim( (string) $atts['url'] );
	if ( ! $url ) {
		return '';
	}
	if ( ! $id ) {
		$id = (int) attachment_url_to_postid( $url );
	}
	$title = trim( (string) $atts['title'] );
	if ( '' === $title ) {
		$title = $id ? get_the_title( $id ) : '';
		$title = '' !== $title ? $title : rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	}

	$info = oec_pdf_info( $url, $id );
	$meta = [ 'PDF' ];
	if ( $info['pages'] ) {
		/* translators: %d: cantidad de páginas */
		$meta[] = sprintf( _n( '%d página', '%d páginas', $info['pages'], 'oec-theme' ), $info['pages'] );
	}
	if ( $info['size'] ) {
		$meta[] = size_format( $info['size'], $info['size'] >= MB_IN_BYTES ? 1 : 0 );
	}

	$vendor = oec_unprefix_asset_src( OEC_THEME_URI . '/assets/vendor/pdfjs' ); // la misma URL en /es y /en: una sola copia en caché
	$ver    = rawurlencode( OEC_THEME_VERSION );
	$same   = $info['local'] || oec_pdf_same_host( $url );

	wp_enqueue_script(
		'oec-pdf-viewer',
		OEC_THEME_URI . '/assets/js/pdf-viewer.js',
		[],
		oec_asset_version( 'assets/js/pdf-viewer.js' ),
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

	ob_start();
	?>
	<figure class="oec-pdf"
		data-src="<?php echo esc_url( $url ); ?>"
		<?php if ( $same ) : ?>
		data-lib="<?php echo esc_url( "{$vendor}/pdf.min.js?v={$ver}" ); ?>"
		data-worker="<?php echo esc_url( "{$vendor}/pdf.worker.min.js?v={$ver}" ); ?>"
		data-fonts="<?php echo esc_url( "{$vendor}/standard_fonts/" ); ?>"
		<?php endif; ?>
		<?php if ( $info['pages'] ) : ?>data-pages="<?php echo (int) $info['pages']; ?>"<?php endif; ?>>
		<div class="oec-pdf__card">
			<span class="oec-pdf__icon" aria-hidden="true"><i class="bi bi-file-earmark-pdf"></i></span>
			<figcaption class="oec-pdf__info">
				<strong class="oec-pdf__title"><?php echo esc_html( $title ); ?></strong>
				<span class="oec-pdf__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
			</figcaption>
			<div class="oec-pdf__actions">
				<a class="btn btn-sm btn--primary oec-pdf__open" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
					<i class="bi bi-eye" aria-hidden="true"></i><?php esc_html_e( 'Ver PDF', 'oec-theme' ); ?>
				</a>
				<a class="btn btn-sm btn--ghost oec-pdf__download" href="<?php echo esc_url( $url ); ?>" download>
					<i class="bi bi-download" aria-hidden="true"></i><?php esc_html_e( 'Descargar', 'oec-theme' ); ?>
				</a>
			</div>
		</div>
	</figure>
	<?php
	return trim( (string) ob_get_clean() );
}

function oec_pdf_same_host( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return ! $host || strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * Páginas y peso de un PDF de este sitio: se lee del disco una vez y se
 * guarda (en el adjunto, o en un transitorio si no está en la biblioteca).
 * Si el archivo es de otro sitio, no se sabe: la tarjeta dice solo "PDF".
 *
 * @return array{local: bool, pages: int, size: int}
 */
function oec_pdf_info( string $url, int $id ): array {
	$path = $id ? (string) get_attached_file( $id ) : '';
	if ( ! $path ) {
		$uploads = wp_get_upload_dir();
		$rel     = str_starts_with( $url, $uploads['baseurl'] . '/' ) ? substr( $url, strlen( $uploads['baseurl'] ) ) : '';
		$path    = $rel && ! str_contains( $rel, '..' ) ? $uploads['basedir'] . rawurldecode( strtok( $rel, '?#' ) ) : '';
	}
	if ( ! $path || ! is_readable( $path ) ) {
		return [ 'local' => false, 'pages' => 0, 'size' => 0 ];
	}

	$size  = (int) filesize( $path );
	$key   = $size . '-' . (int) filemtime( $path );
	$saved = $id ? get_post_meta( $id, '_oec_pdf_info', true ) : get_transient( 'oec_pdf_' . md5( $path ) );
	if ( is_array( $saved ) && ( $saved['key'] ?? '' ) === $key ) {
		return [ 'local' => true, 'pages' => (int) $saved['pages'], 'size' => $size ];
	}

	$pages = $size <= 60 * MB_IN_BYTES ? oec_pdf_count_pages( (string) file_get_contents( $path ) ) : 0;
	$data  = [ 'key' => $key, 'pages' => $pages ];
	if ( $id ) {
		update_post_meta( $id, '_oec_pdf_info', $data );
	} else {
		set_transient( 'oec_pdf_' . md5( $path ), $data, MONTH_IN_SECONDS );
	}
	return [ 'local' => true, 'pages' => $pages, 'size' => $size ];
}

/**
 * Cantidad de páginas sin librerías: el /Count más alto de los nodos
 * /Pages (el árbol raíz). En los PDF que guardan esos objetos comprimidos
 * no aparece: se cuentan los /Type /Page sueltos, y si tampoco, 0 (la
 * tarjeta no muestra páginas; el visor las sabe igual).
 */
function oec_pdf_count_pages( string $pdf ): int {
	$max = 0;
	if ( preg_match_all( '#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#', $pdf, $m ) ) {
		$max = max( array_map( 'intval', array_merge( $m[1], $m[2] ) ) );
	}
	return $max ?: (int) preg_match_all( '#/Type\s*/Page\b(?!s)#', $pdf );
}
