<?php
defined( 'ABSPATH' ) || exit;

/**
 * OEC Theme Updater
 *
 * Detecta nuevas versiones en GitHub Releases y las integra al sistema
 * nativo de actualizaciones de WordPress.
 *
 * Flujo:
 *  1. WordPress llama al hook "pre_set_site_transient_update_themes"
 *  2. Consultamos la API de GitHub: /repos/{owner}/{repo}/releases/latest
 *  3. Si el tag de la release es mayor que la versión actual → la ofrecemos
 *  4. WordPress descarga el ZIP y lo instala con fix_directory_name()
 */
class OEC_Theme_Updater {

	private string $repo;
	private string $slug;
	private string $version;
	private string $cache_key;
	private ?string $token;

	/**
	 * @param string      $repo  Formato: "owner/repo-name"
	 * @param string|null $token Personal Access Token (solo para repos privados)
	 */
	public function __construct( string $repo, ?string $token = null ) {
		$this->repo      = $repo;
		$this->slug      = get_template();
		$this->version   = wp_get_theme()->get( 'Version' );
		$this->cache_key = 'oec_updater_' . md5( $repo );
		$this->token     = $token;

		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'check_for_update' ] );
		add_filter( 'upgrader_source_selection',            [ $this, 'fix_directory_name' ], 10, 4 );
		add_filter( 'auto_update_theme',                    [ $this, 'enable_auto_update' ], 10, 2 );
	}

	/* ============================================================
	   1. CHECK FOR UPDATE
	   ============================================================ */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();

		if ( ! $release || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$latest = ltrim( $release['tag_name'], 'vV' );

		if ( version_compare( $latest, $this->version, '>' ) ) {
			$package = $this->get_zip_url( $release );

			$transient->response[ $this->slug ] = [
				'theme'       => $this->slug,
				'new_version' => $latest,
				'url'         => $release['html_url'] ?? '',
				'package'     => $package,
			];
		}

		return $transient;
	}

	/* ============================================================
	   2. FETCH RELEASE FROM GITHUB API
	   ============================================================ */
	private function fetch_latest_release(): ?array {
		// Cache de 6 horas para no saturar la API de GitHub
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$headers = [
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
		];

		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->repo}/releases/latest",
			[
				'headers' => $headers,
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Guardamos false para no reintentar inmediatamente en caso de error
			set_transient( $this->cache_key, false, 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		set_transient( $this->cache_key, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/* ============================================================
	   3. ELEGIR EL ZIP CORRECTO
	      Prioridad: asset adjunto al release > zipball de GitHub
	   ============================================================ */
	private function get_zip_url( array $release ): string {
		// Si subiste un ZIP como asset al release, se usa ese (nombre exacto del slug)
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( str_ends_with( $asset['name'], '.zip' ) ) {
					$url = $asset['browser_download_url'] ?? '';
					if ( $url && $this->token ) {
						// Assets de repos privados requieren autenticación
						add_filter( 'http_request_args', [ $this, 'add_auth_header' ], 10, 2 );
					}
					return $url;
				}
			}
		}

		// Fallback: ZIP automático de GitHub (tiene carpeta con hash adentro)
		return $release['zipball_url'] ?? '';
	}

	/* ============================================================
	   4. RENOMBRAR LA CARPETA DEL ZIP
	      GitHub genera ZIPs con carpeta "owner-repo-{hash}/"
	      WordPress necesita que sea exactamente el slug del tema.
	   ============================================================ */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ): string {
		if ( empty( $hook_extra['theme'] ) || $hook_extra['theme'] !== $this->slug ) {
			return $source;
		}

		$correct = trailingslashit( $remote_source ) . $this->slug . '/';

		if ( $source === $correct ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem->exists( $correct ) ) {
			$wp_filesystem->delete( $correct, true );
		}

		if ( $wp_filesystem->move( $source, $correct ) ) {
			return $correct;
		}

		return $source;
	}

	/* ============================================================
	   5. AUTORIZAR ACTUALIZACIONES AUTOMÁTICAS (opcional)
	      Si WP tiene activada la auto-actualización de temas,
	      este tema también participará.
	   ============================================================ */
	public function enable_auto_update( $update, $item ): bool {
		if ( isset( $item->theme ) && $item->theme === $this->slug ) {
			return true;
		}
		return (bool) $update;
	}

	/* ============================================================
	   6. HEADER AUTH PARA REPOS PRIVADOS (assets)
	   ============================================================ */
	public function add_auth_header( $args, $url ): array {
		if ( str_contains( $url, 'github.com' ) && $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}
		return $args;
	}

	/* ============================================================
	   UTILIDAD: limpiar cache manualmente (útil durante desarrollo)
	   ============================================================ */
	public function flush_cache(): void {
		delete_transient( $this->cache_key );
	}
}
