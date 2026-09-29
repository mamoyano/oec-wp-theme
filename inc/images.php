<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   IMÁGENES — WebP liviano y sin el original pesado

   1. Al subir (wp-admin, REST API o media_sideload_image) un JPG
      o PNG se achica a MAX_SIDE px de lado mayor, se guarda como
      WebP y se borra el archivo original. WordPress genera los
      tamaños intermedios a partir del WebP, así que salen WebP.
   2. Medios → Optimizar imágenes convierte los adjuntos que ya
      estaban subidos: reemplaza los archivos, regenera los
      tamaños y actualiza las URLs en el contenido de los posts.
   ============================================================ */

class OEC_Images {

	/** Lado mayor máximo del archivo que se guarda (el hero usa 1600). */
	const MAX_SIDE = 1920;

	/** Calidad WebP (0–100). */
	const QUALITY = 80;

	/** Los archivos más chicos que esto y dentro de MAX_SIDE se dejan tal cual. */
	const MIN_BYTES = 150 * KB_IN_BYTES;

	const CONVERT_MIMES = [ 'image/jpeg', 'image/png' ];

	/** Meta de los adjuntos que el conversor ya revisó y decidió no tocar. */
	const META_SKIP = '_oec_img_skip';

	/** Segundos de trabajo por pedido AJAX del conversor masivo. */
	const SLICE_SECONDS = 15;

	public static function init(): void {
		add_filter( 'wp_handle_upload', [ __CLASS__, 'on_upload' ], 10, 2 );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'wp_ajax_oec_images_batch', [ __CLASS__, 'ajax_batch' ] );
	}

	private static function webp_supported(): bool {
		return wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	}

	/* ── Conversión de un archivo ──────────────────────────── */

	/**
	 * Convierte $path a WebP en la misma carpeta. Devuelve la ruta del
	 * WebP nuevo, o '' si no hacía falta o no convenía (el original queda
	 * intacto en ese caso). No borra el original: eso lo decide quien llama.
	 */
	private static function convert_file( string $path, string $mime ): string {
		if ( ! in_array( $mime, self::CONVERT_MIMES, true ) || ! is_file( $path ) || ! self::webp_supported() ) {
			return '';
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$size    = $editor->get_size();
		$too_big = max( $size['width'], $size['height'] ) > self::MAX_SIDE;
		$bytes   = (int) filesize( $path );
		if ( ! $too_big && $bytes < self::MIN_BYTES ) {
			return '';
		}

		if ( 'image/jpeg' === $mime ) {
			$editor->maybe_exif_rotate(); // si no, las fotos de celular quedan giradas
		}
		if ( $too_big ) {
			$editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
		}
		$editor->set_quality( self::QUALITY );

		$dir  = dirname( $path );
		$name = preg_replace( '/-scaled$/', '', pathinfo( $path, PATHINFO_FILENAME ) ) . '.webp';
		$dest = trailingslashit( $dir ) . wp_unique_filename( $dir, $name );

		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return '';
		}

		// Sin achicar, un WebP más pesado que el original no sirve de nada.
		if ( ! $too_big && filesize( $saved['path'] ) >= $bytes ) {
			wp_delete_file( $saved['path'] );
			return '';
		}
		return $saved['path'];
	}

	/* ── 1. Al subir ────────────────────────────────────────── */

	/**
	 * Filtro 'wp_handle_upload': corre antes de crear el adjunto, así que
	 * alcanza con devolver el archivo, la URL y el tipo nuevos.
	 */
	public static function on_upload( array $upload, string $context = 'upload' ): array {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		$webp = self::convert_file( $upload['file'], $upload['type'] );
		if ( ! $webp ) {
			return $upload;
		}

		wp_delete_file( $upload['file'] );
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . wp_basename( $webp );
		$upload['file'] = $webp;
		$upload['type'] = 'image/webp';
		return $upload;
	}

	/* ── 2. Adjuntos ya subidos ─────────────────────────────── */

	/** IDs de adjuntos JPG/PNG que el conversor todavía no revisó. */
	private static function pending_ids( int $limit = -1 ): array {
		return get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::CONVERT_MIMES,
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'DESC', // primero lo más nuevo: los artículos recientes
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => self::META_SKIP, 'compare' => 'NOT EXISTS' ] ],
		] );
	}

	/**
	 * Parte de la ruta de uploads que aparece en cualquier URL del sitio,
	 * sea absoluta o relativa: "uploads/sites/2/" + "2026/09/foto.jpg".
	 */
	private static function url_needle( string $url ): string {
		$pos = strpos( $url, '/wp-content/' );
		return false === $pos ? $url : substr( $url, $pos + strlen( '/wp-content/' ) );
	}

	/** URL de cada archivo del adjunto, por nombre de tamaño ('full' = principal). */
	private static function attachment_urls( int $id, array $meta ): array {
		$full = (string) wp_get_attachment_url( $id );
		if ( ! $full ) {
			return [];
		}
		$base = trailingslashit( dirname( $full ) );
		$urls = [ 'full' => $full ];
		if ( ! empty( $meta['original_image'] ) ) {
			$urls['original'] = $base . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size => $data ) {
			if ( ! empty( $data['file'] ) ) {
				$urls[ $size ] = $base . $data['file'];
			}
		}
		return $urls;
	}

	/**
	 * Convierte un adjunto existente. Devuelve los bytes ahorrados, o
	 * null si se dejó como estaba.
	 */
	public static function convert_attachment( int $id ): ?int {
		$mime = (string) get_post_mime_type( $id );
		$file = (string) get_attached_file( $id );
		$src  = (string) ( wp_get_original_image_path( $id ) ?: $file ); // el original sin "-scaled" da mejor calidad

		$webp = $src && is_file( $src ) ? self::convert_file( $src, $mime ) : '';
		if ( ! $webp ) {
			update_post_meta( $id, self::META_SKIP, 1 );
			return null;
		}

		$old_meta  = (array) wp_get_attachment_metadata( $id );
		$old_urls  = self::attachment_urls( $id, $old_meta );
		$old_bytes = 0;
		foreach ( array_unique( array_filter( [ $src, $file ] ) ) as $f ) {
			$old_bytes += is_file( $f ) ? (int) filesize( $f ) : 0;
		}

		// Borra el original, el "-scaled" y todos los tamaños intermedios viejos.
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $id, $old_meta, is_array( $backup ) ? $backup : [], $file );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );

		update_attached_file( $id, $webp );
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/webp' ] );
		$new_meta = wp_generate_attachment_metadata( $id, $webp );
		wp_update_attachment_metadata( $id, $new_meta );
		delete_post_meta( $id, self::META_SKIP );

		// Cada URL vieja apunta al mismo tamaño en WebP; si ese tamaño ya no
		// existe (imagen achicada), al archivo principal.
		$new_urls = self::attachment_urls( $id, (array) $new_meta );
		$map      = [];
		foreach ( $old_urls as $size => $url ) {
			$map[ self::url_needle( $url ) ] = self::url_needle( $new_urls[ $size ] ?? $new_urls['full'] );
		}
		self::replace_in_content( $map );

		return max( 0, $old_bytes - (int) filesize( $webp ) );
	}

	/**
	 * Reemplaza las URLs viejas en post_content. Se escribe directo en la
	 * tabla (sin wp_update_post) para no pasar el HTML por kses/wpautop
	 * ni generar revisiones.
	 */
	private static function replace_in_content( array $map ): void {
		global $wpdb;

		$map = array_filter( $map, fn( $to, $from ) => $from !== $to, ARRAY_FILTER_USE_BOTH );
		if ( ! $map ) {
			return;
		}
		// Los nombres más largos primero: "foto-800x500.jpg" antes que "foto.jpg".
		uksort( $map, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$likes = implode( ' OR ', array_fill( 0, count( $map ), 'post_content LIKE %s' ) );
		$args  = array_map( fn( $from ) => '%' . $wpdb->esc_like( $from ) . '%', array_keys( $map ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND ( $likes )",
			$args
		) );

		foreach ( $rows as $row ) {
			$content = strtr( $row->post_content, $map );
			if ( $content !== $row->post_content ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $row->ID ] );
				clean_post_cache( (int) $row->ID );
			}
		}
	}

	/* ── Pantalla Medios → Optimizar imágenes ───────────────── */

	public static function admin_menu(): void {
		add_media_page(
			__( 'Optimizar imágenes', 'oec-theme' ),
			__( 'Optimizar imágenes', 'oec-theme' ),
			'manage_options',
			'oec-images',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		$pending = count( self::pending_ids() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Optimizar imágenes', 'oec-theme' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: lado máximo en px, 2: KB mínimos */
					esc_html__( 'Las imágenes nuevas ya se guardan en WebP de hasta %1$d px al subirlas. Esto convierte las JPG/PNG que ya estaban en la biblioteca y pesan más de %2$d KB o miden más de %1$d px: reemplaza el archivo, borra el original pesado y actualiza las URLs en los posts.', 'oec-theme' ),
					(int) self::MAX_SIDE,
					(int) ( self::MIN_BYTES / KB_IN_BYTES )
				);
				?>
			</p>
			<?php if ( ! self::webp_supported() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'El servidor no puede generar WebP (falta soporte en GD/Imagick).', 'oec-theme' ); ?></p></div>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Por revisar:', 'oec-theme' ); ?></strong> <span id="oec-img-pending"><?php echo (int) $pending; ?></span></p>
				<p><button type="button" class="button button-primary" id="oec-img-run" <?php disabled( 0, $pending ); ?>><?php esc_html_e( 'Convertir a WebP', 'oec-theme' ); ?></button></p>
				<p id="oec-img-status"></p>
				<script>
				( function () {
					const btn = document.getElementById( 'oec-img-run' );
					const out = document.getElementById( 'oec-img-status' );
					const left = document.getElementById( 'oec-img-pending' );
					let done = 0, saved = 0;
					async function step() {
						const body = new URLSearchParams( { action: 'oec_images_batch', nonce: <?php echo wp_json_encode( wp_create_nonce( 'oec_images' ) ); ?> } );
						const res = await fetch( ajaxurl, { method: 'POST', body } ).then( r => r.json() ).catch( () => null );
						if ( ! res || ! res.success ) {
							out.textContent = 'Error: ' + ( res && res.data ? res.data : 'sin respuesta del servidor' ) + '. Podés volver a intentar.';
							btn.disabled = false;
							return;
						}
						done += res.data.converted;
						saved += res.data.saved;
						left.textContent = res.data.pending;
						out.textContent = done + ' convertidas · ' + ( saved / 1048576 ).toFixed( 1 ) + ' MB ahorrados';
						if ( res.data.pending > 0 && res.data.processed > 0 ) {
							step();
						} else {
							out.textContent += ' · listo';
						}
					}
					btn.addEventListener( 'click', () => {
						btn.disabled = true;
						out.textContent = 'Convirtiendo…';
						step();
					} );
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function ajax_batch(): void {
		check_ajax_referer( 'oec_images', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Sin permisos', 403 );
		}
		if ( ! self::webp_supported() ) {
			wp_send_json_error( 'El servidor no soporta WebP' );
		}
		wp_raise_memory_limit( 'image' );

		$start     = microtime( true );
		$processed = 0;
		$converted = 0;
		$saved     = 0;
		foreach ( self::pending_ids( 50 ) as $id ) {
			$bytes = self::convert_attachment( (int) $id );
			$processed++;
			if ( null !== $bytes ) {
				$converted++;
				$saved += $bytes;
			}
			if ( microtime( true ) - $start > self::SLICE_SECONDS ) {
				break;
			}
		}

		wp_send_json_success( [
			'processed' => $processed,
			'converted' => $converted,
			'saved'     => $saved,
			'pending'   => count( self::pending_ids() ),
		] );
	}
}

OEC_Images::init();
