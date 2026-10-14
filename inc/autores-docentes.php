<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   AUTORES ↔ DOCENTES

   Muchos autores de artículos (usuarios de WordPress) son también
   docentes del catálogo, con un perfil mucho más completo en
   /docente?slug=… Sin vínculo, Google veía dos personas distintas: el
   artículo apuntaba a /author/<hash> y el perfil no mostraba sus artículos.

   - Vínculo AUTOMÁTICO por nombre: oec_docente_slug() del nombre del autor
     (sin "Prof.", "Dr.", ", PhD"…, sin tildes) contra los slugs del
     catálogo. Se calcula al mostrar cada página, así que un autor nuevo
     que ya es docente (o que pasa a serlo con el próximo sync) se vincula
     solo. Solo para autores que son personas (oec_seo_author_node()).
   - Corrección MANUAL en el perfil del usuario (wp-admin → Usuarios):
     meta OEC_AUTHOR_DOCENTE_META = '' automático · '-' no vincular ·
     '<slug>' ese docente (nombre escrito distinto, homónimos).
   - Con vínculo: la firma del artículo y Article.author.url van al perfil
     de docente (mismo @id de Person en los dos lados), el perfil lista sus
     artículos (ProfilePage) y el archivo de autor enlaza al perfil.
   ============================================================ */

const OEC_AUTHOR_DOCENTE_META = 'oec_docente_slug';

/**
 * Cómo está vinculado un autor: ['mode' => auto|manual|off|none, 'slug' => '', 'docente' => array|null].
 * 'none' = automático sin coincidencia (o el autor no es una persona).
 */
function oec_author_docente_link( int $user_id ): array {
	static $memo = [];
	if ( isset( $memo[ $user_id ] ) ) {
		return $memo[ $user_id ];
	}
	$out = [ 'mode' => 'none', 'slug' => '', 'docente' => null ];
	if ( $user_id <= 0 || ! function_exists( 'oec_get_docente' ) ) {
		return $memo[ $user_id ] = $out;
	}
	$meta = trim( (string) get_user_meta( $user_id, OEC_AUTHOR_DOCENTE_META, true ) );
	if ( '-' === $meta ) {
		return $memo[ $user_id ] = [ 'mode' => 'off', 'slug' => '', 'docente' => null ];
	}
	if ( '' !== $meta ) {
		$d = oec_get_docente( $meta );
		return $memo[ $user_id ] = [ 'mode' => 'manual', 'slug' => $meta, 'docente' => $d ];
	}
	// Automático: solo personas (no revistas, bases de datos ni "G-SE").
	$node = function_exists( 'oec_seo_author_node' ) ? oec_seo_author_node( $user_id, false ) : [ '@type' => 'Person' ];
	if ( ( $node['@type'] ?? '' ) !== 'Person' ) {
		return $memo[ $user_id ] = $out;
	}
	$slug = oec_docente_slug( (string) get_the_author_meta( 'display_name', $user_id ) );
	$d    = '' !== $slug ? oec_get_docente( $slug ) : null;
	return $memo[ $user_id ] = $d ? [ 'mode' => 'auto', 'slug' => $slug, 'docente' => $d ] : $out;
}

/** El docente vinculado a un autor, o null. */
function oec_author_docente( int $user_id ): ?array {
	return oec_author_docente_link( $user_id )['docente'];
}

/** @id compartido de la persona: el mismo en el artículo y en su perfil de docente. */
function oec_docente_person_id( string $slug ): string {
	return oec_docente_url( $slug ) . '#person';
}

/**
 * Autores (IDs de usuario con artículos publicados) vinculados a un docente.
 * Mapa completo cacheado; se rehace con el sync del catálogo, al cambiar un
 * usuario y cada 6 h (un autor nuevo aparece con su primer artículo).
 */
function oec_docente_author_ids( string $slug ): array {
	$meta = class_exists( 'OEC_AI_Catalog' ) ? OEC_AI_Catalog::get_meta() : [];
	$key  = 'oec_doc_authors_' . md5( (string) ( $meta['finished_at'] ?? '' ) . '|' . (int) get_option( 'oec_doc_authors_ver', 0 ) );
	$map  = get_transient( $key );
	if ( ! is_array( $map ) ) {
		$map = [];
		foreach ( get_users( [ 'has_published_posts' => [ 'post' ], 'fields' => 'ID', 'number' => 2000 ] ) as $uid ) {
			$link = oec_author_docente_link( (int) $uid );
			if ( $link['docente'] ) {
				$map[ $link['docente']['slug'] ][] = (int) $uid;
			}
		}
		set_transient( $key, $map, 6 * HOUR_IN_SECONDS );
	}
	return $map[ $slug ] ?? [];
}

function oec_doc_authors_bump(): void {
	update_option( 'oec_doc_authors_ver', (int) get_option( 'oec_doc_authors_ver', 0 ) + 1, false );
}
add_action( 'profile_update', 'oec_doc_authors_bump' );
add_action( 'user_register', 'oec_doc_authors_bump' );
add_action( 'deleted_user', 'oec_doc_authors_bump' );

/** URL del autor para mostrar: su perfil de docente si está vinculado; si no, null. */
function oec_author_profile_url( int $user_id ): ?string {
	$d = oec_author_docente( $user_id );
	return $d ? oec_docente_url( $d['slug'] ) : null;
}

/* ── Perfil de docente: sus artículos ─────────────────────────── */

/**
 * Sección "Artículos de …" para page-docente.php. Devuelve [query, url de
 * "ver todos"] o null si el docente no escribió artículos.
 */
function oec_docente_articles( array $d, int $limit = 6 ): ?array {
	$ids = oec_docente_author_ids( $d['slug'] );
	if ( ! $ids ) {
		return null;
	}
	$q = new WP_Query( [
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'author__in'          => $ids,
		'posts_per_page'      => $limit,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => false,
	] );
	if ( ! $q->have_posts() ) {
		return null;
	}
	// "Ver todos": el archivo del usuario con más artículos (casi siempre hay uno solo).
	usort( $ids, fn( $a, $b ) => count_user_posts( $b, 'post', true ) <=> count_user_posts( $a, 'post', true ) );
	return [ $q, get_author_posts_url( $ids[0] ) ];
}

/* ── wp-admin: perfil del usuario y columna en la lista ────────── */

function oec_author_docente_field( WP_User $user ): void {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}
	$meta = trim( (string) get_user_meta( $user->ID, OEC_AUTHOR_DOCENTE_META, true ) );
	$mode = '-' === $meta ? 'off' : ( '' === $meta ? 'auto' : 'manual' );
	$auto = oec_docente_slug( $user->display_name );
	$auto = '' !== $auto && function_exists( 'oec_get_docente' ) && oec_get_docente( $auto ) ? $auto : '';
	$link = oec_author_docente_link( $user->ID );
	?>
	<h2><?php esc_html_e( 'Perfil de docente', 'oec-theme' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Vínculo con el catálogo', 'oec-theme' ); ?></th>
			<td>
				<fieldset>
					<label><input type="radio" name="oec_docente_mode" value="auto" <?php checked( $mode, 'auto' ); ?>>
						<?php esc_html_e( 'Automático (por nombre)', 'oec-theme' ); ?>
						— <?php echo $auto ? '<a href="' . esc_url( oec_docente_url( $auto ) ) . '" target="_blank">' . esc_html( $auto ) . '</a>' : esc_html__( 'sin coincidencia', 'oec-theme' ); ?>
					</label><br>
					<label><input type="radio" name="oec_docente_mode" value="manual" <?php checked( $mode, 'manual' ); ?>>
						<?php esc_html_e( 'Este docente:', 'oec-theme' ); ?>
					</label>
					<input type="text" name="oec_docente_slug" list="oec-docentes-slugs" class="regular-text" placeholder="slug-del-docente"
					       value="<?php echo esc_attr( 'manual' === $mode ? $meta : '' ); ?>">
					<datalist id="oec-docentes-slugs">
						<?php foreach ( function_exists( 'oec_docentes_catalog' ) ? oec_docentes_catalog() : [] as $slug => $d ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $d['name'] ); ?></option>
						<?php endforeach; ?>
					</datalist><br>
					<label><input type="radio" name="oec_docente_mode" value="off" <?php checked( $mode, 'off' ); ?>>
						<?php esc_html_e( 'No vincular', 'oec-theme' ); ?>
					</label>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'Con vínculo, sus artículos apuntan a su perfil de docente y el perfil muestra sus artículos. Usar "Este docente" si el nombre está escrito distinto en el catálogo; "No vincular" si el automático eligió a otra persona.', 'oec-theme' ); ?>
					<?php if ( $link['docente'] ) : ?>
					<br><strong><?php esc_html_e( 'Ahora:', 'oec-theme' ); ?></strong>
					<a href="<?php echo esc_url( oec_docente_url( $link['docente']['slug'] ) ); ?>" target="_blank"><?php echo esc_html( $link['docente']['name'] ); ?></a>
					<?php elseif ( 'manual' === $link['mode'] ) : ?>
					<br><strong style="color:#b32d2e"><?php esc_html_e( 'Ese slug no está en el catálogo.', 'oec-theme' ); ?></strong>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'oec_author_docente_field' );
add_action( 'edit_user_profile', 'oec_author_docente_field' );

function oec_author_docente_save( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['oec_docente_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- lo verifica el formulario de perfil de WP
		return;
	}
	$mode = sanitize_key( wp_unslash( $_POST['oec_docente_mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$slug = sanitize_title( wp_unslash( $_POST['oec_docente_slug'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'off' === $mode ) {
		update_user_meta( $user_id, OEC_AUTHOR_DOCENTE_META, '-' );
	} elseif ( 'manual' === $mode && '' !== $slug ) {
		update_user_meta( $user_id, OEC_AUTHOR_DOCENTE_META, $slug );
	} else {
		delete_user_meta( $user_id, OEC_AUTHOR_DOCENTE_META );
	}
	oec_doc_authors_bump();
}
add_action( 'personal_options_update', 'oec_author_docente_save' );
add_action( 'edit_user_profile_update', 'oec_author_docente_save' );

// Columna "Perfil de docente" en Usuarios: para revisar de un vistazo quién quedó sin vínculo.
add_filter( 'manage_users_columns', function ( array $cols ): array {
	$cols['oec_docente'] = __( 'Perfil de docente', 'oec-theme' );
	return $cols;
} );
add_filter( 'manage_users_custom_column', function ( $out, string $col, int $user_id ) {
	if ( 'oec_docente' !== $col ) {
		return $out;
	}
	$link = oec_author_docente_link( $user_id );
	if ( $link['docente'] ) {
		$tag = 'manual' === $link['mode'] ? ' <em>(' . esc_html__( 'manual', 'oec-theme' ) . ')</em>' : '';
		return '<a href="' . esc_url( oec_docente_url( $link['docente']['slug'] ) ) . '" target="_blank">' . esc_html( $link['docente']['name'] ) . '</a>' . $tag;
	}
	if ( 'off' === $link['mode'] ) {
		return '<em>' . esc_html__( 'No vincular', 'oec-theme' ) . '</em>';
	}
	if ( 'manual' === $link['mode'] ) {
		return '<span style="color:#b32d2e">' . esc_html__( 'Slug inexistente', 'oec-theme' ) . '</span>';
	}
	return '—';
}, 10, 3 );
