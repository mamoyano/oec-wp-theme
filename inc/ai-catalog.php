<?php
defined( 'ABSPATH' ) || exit;

class OEC_AI_Catalog {

	const DIR_NAME   = 'oec-ai-catalog';
	const OPTION_META = 'oec_ai_catalog_meta';
	const OPTION_JOB = 'oec_ai_catalog_job';
	const CRON_HOOK  = 'oec_ai_catalog_sync';
	const SLICE_HOOK = 'oec_ai_catalog_slice';
	/** Fichas por lote (cada una = ficha en oas-api + opiniones en api.g-se.com). */
	const BATCH_SIZE = 5;

	/** Pausa entre lotes de fichas, para no saturar oas-api. */
	const BATCH_PAUSE_SECONDS = 1;

	/** Páginas de listado pedidas en paralelo. */
	const LIST_PARALLEL = 2;

	/**
	 * Abiertas: la ficha completa se vuelve a pedir solo si es nueva, si
	 * cambió de edición o si tiene más de OPEN_REFRESH_EVERY. Las vencidas
	 * se reparten: hasta OPEN_REFRESH_MAX por día, las más viejas primero.
	 * Al resto se le actualizan desde el listado los datos que cambian
	 * (fechas, relevancia, destacados, opiniones), sin pedir la ficha.
	 */
	const OPEN_REFRESH_EVERY = WEEK_IN_SECONDS;
	const OPEN_REFRESH_MAX   = 60;

	const API_LIST = 'https://oas-api.onlineeducation.center/api-oas/v1/trainings';

	/** Filas por página al leer los listados: páginas chicas responden en ~5 s. */
	const LIST_PAGE_SIZE = 25;

	/** Duración máxima de cada tanda del sync (ver start()). */
	const SLICE_SECONDS = 25;

	/** Cada cuánto se relee entero el listado de cerradas de la API. */
	const CLOSED_FULL_EVERY = WEEK_IN_SECONDS;

	/**
	 * Temáticas "reales" — mismos slugs que "filter-subjects" en el
	 * shortcode [oec-list] de la página Formaciones. El campo "wpgroup"
	 * de la API trae esto MEZCLADO con tags de campaña/marketing (ej.
	 * "christmassale fisiologia fisiologia-vaa hot-gse2026") — acá se
	 * filtra contra esta lista para quedarse solo con temáticas reales.
	 * Si se agrega una temática nueva al sitio, hay que sumarla acá
	 * también (mismo valor que en el shortcode de Formaciones).
	 */
	const TEMATICA_SLUGS = [
		'nutricion-deportiva',
		'fuerza',
		'fisiologia',
		'salud-ejercicio',
		'deportes',
		'futbol',
		'endurance',
	];

	private static string $dir = '';

	/** reviews_summary (promedio + cantidad) de cada formación, tomado del
	 *  listado durante el sync — el detalle no lo trae. id => [avg, count]. */
	private static array $summaries = [];

	/* ── Bootstrap ─────────────────────────────────────────── */

	/*
	 * Multisitio: el catálogo es uno solo para toda la red. Lo sincroniza
	 * únicamente el sitio de configuración (/es/, ver oec_config_blog_id())
	 * y los demás leen sus archivos y su meta. Antes cada sitio corría su
	 * propio sync completo contra la API, a la misma hora.
	 *
	 * En entornos locales (WP_ENVIRONMENT_TYPE = local) no hay sync
	 * automático, para no golpear la API de producción cada vez que se abre
	 * el sitio de pruebas; el botón "Sincronizar ahora" sigue funcionando.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'start' ] );
		add_action( self::SLICE_HOOK, [ __CLASS__, 'run_slice' ] );
		add_action( 'wp_ajax_oec_ai_sync_catalog', [ __CLASS__, 'ajax_sync' ] );

		if ( ! oec_is_config_site() || 'local' === wp_get_environment_type() ) {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			if ( ! oec_is_config_site() && wp_next_scheduled( self::SLICE_HOOK ) ) {
				wp_clear_scheduled_hook( self::SLICE_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	/* ── Directory helpers ──────────────────────────────────── */

	public static function get_dir(): string {
		if ( ! self::$dir ) {
			// Siempre la carpeta del sitio de configuración (ver init()).
			$switch = is_multisite() && ! oec_is_config_site();
			if ( $switch ) {
				switch_to_blog( oec_config_blog_id() );
			}
			self::$dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIR_NAME;
			if ( $switch ) {
				restore_current_blog();
			}
		}
		return self::$dir;
	}

	private static function ensure_dir(): bool {
		$dir    = self::get_dir();
		$is_new = ! is_dir( $dir );
		wp_mkdir_p( $dir . '/formations' );
		wp_mkdir_p( $dir . '/organizations' );
		if ( $is_new ) {
			file_put_contents( $dir . '/.htaccess', "deny from all\n" );
		}
		return is_writable( $dir );
	}

	/* ── Public readers ─────────────────────────────────────── */

	/*
	 * Fuentes: el token principal (el del sitio) y los secundarios (los de
	 * otras comunidades: swimming.science, fisio.one…). Cada fila y cada
	 * ficha guarda de qué fuente vino ("source") y su "primary". Los
	 * lectores devuelven por defecto SOLO lo del token principal (home,
	 * landings, sitemap, llms.txt, opiniones…); con $all = true, también lo
	 * de los secundarios (chat IA, /formaciones, docentes, organización).
	 * Una entrada sin "primary" (guardada antes de los secundarios) cuenta
	 * como principal.
	 */
	public static function get_index( bool $all = false ): array {
		$path = self::get_dir() . '/index.json';
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$data = json_decode( file_get_contents( $path ), true );
		return self::scope( $data['formations'] ?? [], $all );
	}

	/** Solo las entradas del token principal, salvo $all. */
	private static function scope( array $rows, bool $all ): array {
		return $all ? $rows : array_values( array_filter( $rows, 'oec_formation_is_primary' ) );
	}

	/**
	 * Tokens configurados: clave de fuente => token. 'primary' es el token
	 * principal; cada secundario se identifica con un hash corto de su
	 * token (así sacar uno de la lista saca sus formaciones del catálogo).
	 */
	public static function sources(): array {
		$opts    = oec_get_options();
		$primary = trim( (string) ( $opts['oec_api_token'] ?? '' ) );
		$out     = $primary ? [ 'primary' => $primary ] : [];
		foreach ( (array) ( $opts['oec_api_tokens'] ?? [] ) as $token ) {
			$token = trim( (string) $token );
			if ( $token && $token !== $primary ) {
				$out[ self::source_key( $token ) ] = $token;
			}
		}
		return $out;
	}

	public static function source_key( string $token ): string {
		return 's' . substr( md5( $token ), 0, 10 );
	}

	/**
	 * Listado liviano de TODAS las formaciones (abiertas + cerradas) para
	 * page-formaciones.php, en el orden sugerido por la API: abiertas
	 * primero, después cerradas. Lo arma el sync (save_listing()).
	 */
	public static function get_listing( bool $all = false ): array {
		static $rows = null;
		if ( null === $rows ) {
			$path = self::get_dir() . '/listing.json';
			$data = file_exists( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			$rows = $data['formations'] ?? [];
		}
		return $all ? $rows : self::scope( $rows, false );
	}

	public static function get_formation( string $id ): ?array {
		$path = self::get_dir() . '/formations/' . sanitize_file_name( $id ) . '.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		return json_decode( file_get_contents( $path ), true );
	}

	/**
	 * Organizaciones con al menos $min_formations formaciones abiertas —
	 * pensado para el shortcode [oec-organizations]. El filtro se aplica
	 * acá, no al guardar, así se puede ajustar sin esperar a la próxima
	 * sincronización.
	 *
	 * Si se pasa $tematica (slug, ver TEMATICA_SLUGS), el umbral
	 * $min_formations se exige DENTRO de esa temática puntual (p. ej.
	 * "organizaciones con 2+ formaciones en fuerza"), no sobre el total
	 * de formaciones de la organización.
	 */
	public static function get_organizations( int $min_formations = 2, string $tematica = '' ): array {
		$path = self::get_dir() . '/organizations.json';
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$data = json_decode( file_get_contents( $path ), true );
		$orgs = $data['organizations'] ?? [];
		return array_values( array_filter( $orgs, function ( $o ) use ( $min_formations, $tematica ) {
			$count = '' !== $tematica
				? ( $o['tematicas'][ $tematica ] ?? 0 )
				: ( $o['count'] ?? 0 );
			return $count >= $min_formations;
		} ) );
	}

	public static function get_organization( string $slug ): ?array {
		$path = self::get_dir() . '/organizations/' . sanitize_file_name( $slug ) . '.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		return json_decode( file_get_contents( $path ), true );
	}

	public static function get_meta(): array {
		return oec_config_get_option( self::OPTION_META, [
			'status'      => 'never',
			'count'       => 0,
			'finished_at' => '',
			'errors'      => [],
		] );
	}

	/* ── Sync por tandas ────────────────────────────────────── */

	/*
	 * El sync es un trabajo en pasos que se guarda en la opción OPTION_JOB
	 * y avanza en tandas cortas (SLICE_SECONDS): cada tanda hace lo que
	 * entra en ese tiempo, guarda por dónde iba y agenda la siguiente con
	 * WP-Cron. Así ningún pedido HTTP a wp-cron.php dura más de ~1 minuto,
	 * aunque el hosting corte los procesos largos; si una tanda muere a la
	 * mitad, la siguiente retoma desde el último paso guardado.
	 *
	 *   open_list      listado completo de abiertas (páginas de LIST_PAGE_SIZE)
	 *   open_details   ficha + opiniones de cada abierta (lotes de BATCH_SIZE)
	 *   closed_list    cerradas: las del sync anterior + las que dejaron de
	 *                  estar abiertas. El listado de cerradas de la API (lento)
	 *                  se relee entero solo la primera vez y una vez por semana
	 *                  (CLOSED_FULL_EVERY), por si aparece alguna que nunca
	 *                  vimos abierta.
	 *   closed_details ficha + opiniones SOLO de las cerradas que no están en
	 *                  disco: una cerrada no cambia, no se vuelve a pedir.
	 *   finish         index.json, history_index.json, organizations,
	 *                  listing.json y meta.
	 *
	 * Día normal: ~15 páginas de listado + ~370 fichas ≈ 1 minuto, en 2-3
	 * tandas. Primera vez (backfill de ~1850 cerradas): ~5 minutos, en ~15.
	 *
	 * Tokens secundarios (ver sources()): los listados se leen fuente por
	 * fuente, uno detrás del otro (el principal primero; si una formación
	 * viene en dos, gana la primera), y cada ficha se pide con el token de
	 * su fuente, con los mismos lotes y pausas. Mismo criterio incremental
	 * por fuente: cerradas releídas una vez por semana, fichas de cerradas
	 * nunca repetidas. Si falla el principal se aborta como siempre; si
	 * falla un secundario, sus formaciones quedan como en el sync anterior.
	 */

	/** Arranca un sync (cron diario / botón del admin). No pisa uno en curso. */
	public static function start( bool $force = false ): void {
		$job = get_option( self::OPTION_JOB );
		if ( ! $force && is_array( $job ) && time() - (int) ( $job['touched'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return; // hay uno andando: que siga ese
		}
		$sources = self::sources();
		if ( ! self::ensure_dir() || empty( $sources['primary'] ) ) {
			return;
		}
		$meta = self::get_meta();
		self::delete_job_files();
		// Fuentes cuyo listado de cerradas toca releer entero: la primera vez y una vez por semana.
		$has_listing = file_exists( self::get_dir() . '/listing.json' );
		$full_closed = array_values( array_filter( array_keys( $sources ), function ( $key ) use ( $meta, $has_listing ) {
			$at = (int) ( $meta['closed_full'][ $key ] ?? ( 'primary' === $key ? ( $meta['closed_full_at'] ?? 0 ) : 0 ) );
			return ! $has_listing || time() - $at > self::CLOSED_FULL_EVERY;
		} ) );
		update_option( self::OPTION_JOB, [
			'phase'            => 'open_list',
			'started_at'       => current_time( 'c' ),
			'touched'          => time(),
			'sources'          => array_keys( $sources ), // el principal primero
			'src'              => 0,                      // fuente cuyo listado de abiertas se está leyendo
			'full_closed'      => $full_closed,
			'closed_full_done' => [],
			'failed'           => [], // secundarios sin listado de abiertas: se conserva lo del sync anterior
			'lists'            => [],
			'queue'            => [],
			'fetched'          => [], // cerradas bajadas en esta corrida (van al histórico)
			'patched'          => [], // cerradas a las que se les corrigió fuente/comunidad
			'errors'           => [],
		], false );
		self::save_meta( array_merge( $meta, [ 'status' => 'running', 'started_at' => current_time( 'c' ), 'progress' => '' ] ) );
		self::run_slice();
	}

	/**
	 * Una tanda: avanza el trabajo hasta agotar $seconds y agenda la
	 * siguiente. Con $seconds = 0 corre hasta terminar (WP-CLI / scripts).
	 */
	public static function run_slice( int $seconds = self::SLICE_SECONDS ): void {
		$job = get_option( self::OPTION_JOB );
		if ( ! is_array( $job ) ) {
			return;
		}
		if ( ! isset( $job['sources'] ) ) { // trabajo de antes de los tokens secundarios: se descarta
			delete_option( self::OPTION_JOB );
			self::delete_job_files();
			return;
		}
		if ( get_transient( 'oec_ai_catalog_lock' ) ) {
			return; // otra tanda está corriendo
		}
		set_transient( 'oec_ai_catalog_lock', 1, 5 * MINUTE_IN_SECONDS );
		// Red de seguridad: si el hosting mata esta tanda a la mitad, esta
		// otra la retoma cuando venza el lock.
		wp_clear_scheduled_hook( self::SLICE_HOOK );
		wp_schedule_single_event( time() + 6 * MINUTE_IN_SECONDS, self::SLICE_HOOK );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- donde el hosting lo permita

		$tokens = self::sources();
		$until  = microtime( true ) + ( $seconds ?: PHP_INT_MAX );
		do {
			$job = self::step( $job, $tokens );
			$job['touched'] = time();
			update_option( self::OPTION_JOB, $job, false ); // guardado paso a paso
		} while ( 'done' !== $job['phase'] && microtime( true ) < $until );

		delete_transient( 'oec_ai_catalog_lock' );
		wp_clear_scheduled_hook( self::SLICE_HOOK );
		if ( 'done' === $job['phase'] ) {
			delete_option( self::OPTION_JOB );
			self::delete_job_files();
			return;
		}
		self::save_meta( array_merge( self::get_meta(), [ 'progress' => self::progress_label( $job ) ] ) );
		wp_schedule_single_event( time() + 5, self::SLICE_HOOK );
	}

	/** Sync completo de una sola vez (WP-CLI, scripts). No usar desde una página web. */
	public static function sync(): void {
		self::start( true );
		while ( is_array( get_option( self::OPTION_JOB ) ) ) {
			self::run_slice( 0 );
		}
	}

	/** Un paso del trabajo; devuelve el estado actualizado. $tokens: ver sources(). */
	private static function step( array $job, array $tokens ): array {
		switch ( $job['phase'] ) {
			case 'open_list':
				$key  = $job['sources'][ $job['src'] ];
				$rows = isset( $tokens[ $key ] ) ? self::step_listing( $job, $tokens[ $key ], 'opened', $key ) : null;
				if ( false === $rows ) {
					return $job; // faltan páginas: sigue en la próxima vuelta
				}
				if ( 'primary' === $key && ! $rows ) {
					return self::abort( $job, 'No se pudo obtener el listado de formaciones.' );
				}
				if ( null === $rows ) {
					$job['errors'][] = sprintf( 'Token secundario %d: no se pudo leer el listado de abiertas; se conservan sus formaciones del sync anterior.', $job['src'] );
					$job['failed'][] = $key;
				} else {
					self::append_rows( 'open', $rows, $key );
				}
				if ( ++$job['src'] < count( $job['sources'] ) ) {
					return $job; // el listado de la fuente siguiente
				}
				$job['queue'] = self::queue_open( self::read_job_file( 'open' ) );
				$job['total'] = count( $job['queue'] );
				$job['phase'] = 'open_details';
				return $job;

			case 'open_details':
			case 'closed_details':
				$closed = 'closed_details' === $job['phase'];
				if ( ! $job['queue'] ) {
					return $closed ? array_merge( $job, [ 'phase' => 'finish' ] ) : self::prepare_closed( $job );
				}
				self::load_summaries( $closed ? 'closed' : 'open' );
				$origins = self::row_origins( $closed ? 'closed' : 'open' );
				$batch   = array_splice( $job['queue'], 0, self::BATCH_SIZE );
				// Cada ficha se pide con el token de la fuente de donde vino.
				$groups = [];
				foreach ( $batch as $id ) {
					$groups[ $origins[ $id ]['source'] ?? 'primary' ][] = $id;
				}
				foreach ( $groups as $key => $ids ) {
					if ( empty( $tokens[ $key ] ) ) {
						continue; // token quitado a mitad del sync
					}
					foreach ( self::fetch_batch( $ids, $tokens[ $key ] ) as $id => $data ) {
						if ( isset( $data['error'] ) ) {
							$job['errors'][] = $id . ': ' . $data['error'];
							continue;
						}
						self::save_formation( $id, array_merge(
							self::process( $data['detail'], $data['reviews'] ),
							$origins[ $id ] ?? self::origin( 'primary', '' ),
							[ '_fetched' => time() ]
						) );
						if ( $closed ) {
							$job['fetched'][] = (string) $id;
						} else {
							$job['open_fetched'] = ( $job['open_fetched'] ?? 0 ) + 1;
						}
					}
				}
				if ( $job['queue'] ) {
					sleep( self::BATCH_PAUSE_SECONDS );
				}
				return $job;

			case 'closed_list':
				$key  = $job['full_closed'][ $job['csrc'] ];
				$rows = isset( $tokens[ $key ] ) ? self::step_listing( $job, $tokens[ $key ], 'closed', $key ) : null;
				if ( false === $rows ) {
					return $job;
				}
				if ( null === $rows || ( 'primary' === $key && ! $rows ) ) {
					$job['errors'][] = 'primary' === $key
						? 'Listado de cerradas incompleto: se conservan las del sync anterior.'
						: sprintf( 'Token secundario %d: listado de cerradas incompleto; se conservan las del sync anterior.', array_search( $key, $job['sources'], true ) );
				} else {
					self::append_rows( 'closed_full', $rows, $key );
					$job['closed_full_done'][] = $key;
				}
				if ( ++$job['csrc'] < count( $job['full_closed'] ) ) {
					return $job;
				}
				return self::build_closed( $job );

			case 'finish':
				self::finish( $job );
				$job['phase'] = 'done';
				return $job;
		}
		return self::abort( $job, 'Estado de sincronización desconocido.' );
	}

	/** Suma al archivo del trabajo $name las filas de la fuente $key que no estén ya (gana la primera fuente). */
	private static function append_rows( string $name, array $rows, string $key ): void {
		$all  = self::read_job_file( $name );
		$seen = array_flip( array_map( 'strval', array_column( $all, 'id' ) ) );
		foreach ( $rows as $r ) {
			if ( ! isset( $seen[ (string) $r['id'] ] ) ) {
				$seen[ (string) $r['id'] ] = true;
				$all[]                     = array_merge( $r, [ '_source' => $key ] );
			}
		}
		self::write_job_file( $name, $all );
	}

	/** Fuente y comunidad de una formación, tal como se guardan en su ficha y en las filas. */
	private static function origin( string $source, string $community ): array {
		return [
			'source'    => $source,
			'primary'   => 'primary' === $source,
			'community' => oec_community_origin( $community ),
		];
	}

	/** id => origin() de las filas del archivo del trabajo ('open' | 'closed'). */
	private static function row_origins( string $which ): array {
		static $cache = [];
		if ( ! isset( $cache[ $which ] ) ) {
			$cache[ $which ] = [];
			foreach ( self::read_job_file( $which ) as $r ) {
				$cache[ $which ][ (string) $r['id'] ] = self::origin(
					(string) ( $r['_source'] ?? 'primary' ),
					(string) ( $r['community'] ?? $r['_listing']['community'] ?? '' )
				);
			}
		}
		return $cache[ $which ];
	}

	/**
	 * Terminadas las abiertas: si alguna fuente tiene que releer su listado
	 * de cerradas (semanal / primera vez), fase closed_list; si no, las
	 * cerradas salen del sync anterior (build_closed()).
	 */
	private static function prepare_closed( array $job ): array {
		if ( $job['full_closed'] && ! isset( $job['csrc'] ) ) {
			$job['csrc']  = 0;
			$job['phase'] = 'closed_list';
			return $job;
		}
		return self::build_closed( $job );
	}

	/**
	 * Cerradas de esta corrida, fuente por fuente (el principal primero):
	 * las del listado de la API si se releyó entero; si no, las del sync
	 * anterior + las que estaban abiertas y hoy ya no, sin pedirle nada a
	 * la API. Las de un token que se sacó de la configuración no siguen.
	 * Las de un secundario cuyo listado de abiertas falló hoy se conservan
	 * tal cual estaban (archivo 'carry').
	 */
	private static function build_closed( array $job ): array {
		$open_ids  = array_flip( array_map( 'strval', array_column( self::read_job_file( 'open' ), 'id' ) ) );
		$full_done = array_flip( $job['closed_full_done'] ?? [] );
		$failed    = array_flip( $job['failed'] ?? [] );
		$buckets   = array_fill_keys( $job['sources'], [ 'full' => [], 'fresh' => [], 'old' => [] ] );
		$carry     = [];

		foreach ( self::read_job_file( 'closed_full' ) as $r ) {
			if ( ! isset( $open_ids[ (string) $r['id'] ] ) && isset( $buckets[ $r['_source'] ] ) ) {
				$buckets[ $r['_source'] ]['full'][] = $r;
			}
		}
		foreach ( self::get_listing( true ) as $r ) {
			$src = (string) ( $r['source'] ?? 'primary' );
			if ( ! isset( $buckets[ $src ] ) ) {
				continue; // token quitado
			}
			if ( isset( $failed[ $src ] ) ) {
				$carry[] = $r;
				continue;
			}
			if ( isset( $full_done[ $src ] ) || isset( $open_ids[ (string) $r['id'] ] ) ) {
				continue;
			}
			// recién cerrada (estaba abierta en el sync anterior): adelante
			$buckets[ $src ][ ! empty( $r['open'] ) ? 'fresh' : 'old' ][] = [ '_listing' => $r, 'id' => $r['id'], '_source' => $src ];
		}
		self::write_job_file( 'carry', $carry );

		$rows = [];
		foreach ( $buckets as $b ) {
			$rows = array_merge( $rows, $b['full'], $b['fresh'], $b['old'] );
		}
		return self::queue_closed( $job, $rows );
	}

	/**
	 * Abiertas que necesitan la ficha completa: las que no están en disco,
	 * las que cambiaron de edición y, de las que tienen más de
	 * OPEN_REFRESH_EVERY, las OPEN_REFRESH_MAX más viejas. Al resto se le
	 * actualizan los datos del listado sin pedirle nada a la API.
	 */
	private static function queue_open( array $rows ): array {
		$queue = [];
		$stale = [];
		foreach ( $rows as $r ) {
			$id = (string) $r['id'];
			$f  = self::get_formation( $id );
			if ( ! $f || (int) ( $f['edition_number'] ?? 0 ) !== (int) ( $r['edition_number'] ?? 0 ) ) {
				$queue[] = $id;
				continue;
			}
			$path    = self::get_dir() . '/formations/' . sanitize_file_name( $id ) . '.json';
			$fetched = (int) ( $f['_fetched'] ?? filemtime( $path ) );
			if ( time() - $fetched > self::OPEN_REFRESH_EVERY ) {
				$stale[ $id ] = $fetched;
			}
			self::save_formation( $id, array_merge( self::patch_from_row( $f, $r ), [ '_fetched' => $fetched ] ) );
		}
		asort( $stale ); // las más viejas primero
		return array_merge( $queue, array_slice( array_map( 'strval', array_keys( $stale ) ), 0, self::OPEN_REFRESH_MAX ) );
	}

	/** Datos de una fila del listado que cambian entre ediciones/días → ficha guardada. */
	private static function patch_from_row( array $f, array $r ): array {
		foreach ( [ 'title', 'slug', 'type', 'image', 'modality', 'synchronicity' ] as $k ) {
			if ( isset( $r[ $k ] ) && '' !== $r[ $k ] ) {
				$f[ $k ] = (string) $r[ $k ];
			}
		}
		foreach ( [ 'start', 'end', 'enrollment_end' ] as $k ) {
			if ( ! empty( $r[ $k ] ) ) {
				$f[ $k ] = substr( (string) $r[ $k ], 0, 10 );
			}
		}
		if ( isset( $r['relevance'] ) ) {
			$f['relevance'] = (int) $r['relevance'];
		}
		foreach ( [ 'great_lecturers', 'great_topic', 'great_certification', 'great_organizer', 'great_price' ] as $k ) {
			if ( array_key_exists( $k, $r ) ) {
				$f[ $k ] = (bool) $r[ $k ];
			}
		}
		if ( array_key_exists( 'reviews_summary', $r ) ) {
			$f['reviews_summary'] = oec_ai_catalog_summary( $r['reviews_summary'] );
		}
		if ( isset( $r['wpgroup'] ) ) {
			$f['tags']      = array_values( array_filter( explode( ' ', trim( (string) $r['wpgroup'] ) ) ) );
			$f['tematicas'] = array_values( array_intersect( $f['tags'], self::TEMATICA_SLUGS ) );
		}
		if ( ! empty( $r['short_description'] ) ) {
			$f['description'] = trim( wp_strip_all_tags( (string) $r['short_description'] ) );
		}
		return array_merge( $f, self::origin( (string) ( $r['_source'] ?? 'primary' ), (string) ( $r['community'] ?? '' ) ) );
	}

	/**
	 * Guarda las cerradas y encola las que no tienen ficha en disco. A las
	 * que ya la tienen y vienen de un listado releído entero solo se les
	 * corrige fuente y comunidad (la ficha de una cerrada no se vuelve a pedir).
	 */
	private static function queue_closed( array $job, array $rows ): array {
		self::write_job_file( 'closed', $rows );
		$dir          = self::get_dir() . '/formations/';
		$job['queue'] = [];
		foreach ( $rows as $r ) {
			$id = (string) $r['id'];
			if ( ! file_exists( $dir . sanitize_file_name( $id ) . '.json' ) ) {
				$job['queue'][] = $id;
				continue;
			}
			if ( isset( $r['_listing'] ) ) {
				continue; // fila del sync anterior: la ficha ya quedó bien
			}
			$f = self::get_formation( $id );
			$o = self::origin( (string) $r['_source'], (string) ( $r['community'] ?? '' ) );
			if ( $f && array_intersect_key( $f, $o ) != $o ) { // phpcs:ignore Universal.Operators.StrictComparisons -- sin importar el orden de las claves
				self::save_formation( $id, array_merge( $f, $o ) );
				$job['patched'][] = $id;
			}
		}
		$job['total'] = count( $job['queue'] );
		$job['phase'] = 'closed_details';
		return $job;
	}

	/**
	 * Avanza la lectura de un listado ('opened' | 'closed') de la fuente
	 * $key: pide la página 1 (para saber cuántas hay) y después hasta
	 * LIST_PARALLEL páginas en paralelo por vuelta, guardando cada una
	 * apenas llega. Devuelve false si faltan páginas, null si se agotaron
	 * los reintentos, o todas las filas en el orden sugerido por la API.
	 */
	private static function step_listing( array &$job, string $token, string $enrollment, string $key ) {
		$list  = $enrollment . '-' . $key;
		$st    = $job['lists'][ $list ] ?? [ 'total' => 0, 'tries' => 0 ];
		$pages = self::read_job_file( 'pages-' . $list );

		$pending = [];
		for ( $pg = 1; $pg <= max( 1, $st['total'] ); $pg++ ) {
			if ( ! isset( $pages[ $pg ] ) ) {
				$pending[ $pg ] = self::API_LIST . '?' . http_build_query( [
					'enrollment'  => $enrollment,
					'relevance'   => 0,
					'inc-reviews' => 1, // reviews_summary (promedio + cantidad) en cada fila
					'pagination'  => self::LIST_PAGE_SIZE,
					'pg'          => $pg,
				] );
			}
		}
		$chunk = array_slice( $pending, 0, $st['total'] ? self::LIST_PARALLEL : 1, true );
		$got   = 0;
		foreach ( self::fetch_json_multiple( $chunk, $token ) as $pg => $body ) {
			if ( isset( $body['data'] ) ) {
				$pages[ $pg ] = $body['data'];
				$st['total']  = max( 1, (int) ( $body['meta']['pagination']['total_pages'] ?? 1 ) );
				$got++;
			}
		}
		$st['tries'] = $got ? 0 : $st['tries'] + 1; // reintentos seguidos sin avanzar
		self::write_job_file( 'pages-' . $list, $pages );
		$job['lists'][ $list ] = $st;

		if ( $st['tries'] >= 4 ) {
			return null;
		}
		if ( ! $st['total'] || count( $pages ) < $st['total'] ) {
			return false;
		}

		ksort( $pages );
		$rows = [];
		$seen = [];
		foreach ( $pages as $data ) {
			foreach ( $data as $item ) {
				if ( empty( $item['id'] ) || isset( $seen[ $item['id'] ] ) ) {
					continue; // una formación que cambió de posición entre páginas
				}
				$seen[ $item['id'] ] = true;
				$rows[]              = $item;
			}
		}
		return $rows;
	}

	/** reviews_summary de las filas del listado (la ficha no lo trae) → self::$summaries. */
	private static function load_summaries( string $which ): void {
		static $loaded = [];
		if ( isset( $loaded[ $which ] ) ) {
			return;
		}
		$loaded[ $which ] = true;
		foreach ( self::read_job_file( $which ) as $item ) {
			if ( ! empty( $item['reviews_summary']['count'] ) ) {
				self::$summaries[ $item['id'] ] = $item['reviews_summary'];
			}
		}
	}

	/** Último paso: índices, organizaciones, listing.json y meta, leyendo las fichas de disco. */
	private static function finish( array $job ): void {
		$open_rows   = self::read_job_file( 'open' );
		$closed_rows = self::read_job_file( 'closed' );
		$carry       = self::read_job_file( 'carry' ); // filas (de listing.json) de secundarios que fallaron hoy

		$open_ids = array_map( 'strval', array_merge(
			array_column( $open_rows, 'id' ),
			array_column( array_filter( $carry, fn( $r ) => ! empty( $r['open'] ) ), 'id' )
		) );
		$index         = [];
		$organizations = [];
		foreach ( array_unique( $open_ids ) as $id ) {
			$f = self::get_formation( $id );
			if ( $f ) {
				$index[] = self::index_entry( $f );
				self::accumulate_organization( $organizations, $f );
			}
		}
		$closed_index = [];
		foreach ( array_unique( array_merge( $job['fetched'], $job['patched'] ?? [] ) ) as $id ) {
			$f = self::get_formation( (string) $id );
			if ( $f ) {
				$closed_index[] = self::index_entry( $f );
			}
		}

		self::save_index( $index );
		self::merge_history( array_merge( $index, $closed_index ) );
		self::save_organizations( $organizations );
		self::save_listing( $open_rows, $closed_rows, $carry );

		// Por fuente: abiertas, cerradas y comunidades (para el admin).
		$sources = array_fill_keys( $job['sources'], [ 'open' => 0, 'closed' => 0, 'communities' => [] ] );
		foreach ( [ [ $open_rows, 'open' ], [ $closed_rows, 'closed' ] ] as [ $list, $k ] ) {
			foreach ( $list as $r ) {
				$src = (string) ( $r['_source'] ?? 'primary' );
				if ( isset( $sources[ $src ] ) ) {
					$sources[ $src ][ $k ]++;
					$host = oec_community_host( (string) ( $r['community'] ?? $r['_listing']['community'] ?? '' ) );
					if ( '' !== $host ) {
						$sources[ $src ]['communities'][ $host ] = ( $sources[ $src ]['communities'][ $host ] ?? 0 ) + 1;
					}
				}
			}
		}
		foreach ( $sources as $k => $st ) {
			arsort( $sources[ $k ]['communities'] );
		}

		$meta        = self::get_meta();
		$closed_full = (array) ( $meta['closed_full'] ?? [ 'primary' => (int) ( $meta['closed_full_at'] ?? 0 ) ] );
		foreach ( $job['closed_full_done'] ?? [] as $key ) {
			$closed_full[ $key ] = time();
		}
		self::save_meta( [
			'status'         => 'ok',
			'started_at'     => $job['started_at'],
			'finished_at'    => current_time( 'c' ),
			'count'          => count( $index ),
			'primary_count'  => count( array_filter( $index, 'oec_formation_is_primary' ) ),
			'closed_count'   => count( $closed_rows ),
			'open_fetched'   => (int) ( $job['open_fetched'] ?? 0 ),
			'closed_fetched' => count( $job['fetched'] ),
			'closed_full'    => array_intersect_key( $closed_full, $sources ),
			'sources'        => $sources,
			// Comunidad de este sitio: la que más aparece en el token principal.
			'site_community' => (string) ( array_key_first( $sources['primary']['communities'] ?? [] ) ?? ( $meta['site_community'] ?? '' ) ),
			'progress'       => '',
			'errors'         => $job['errors'],
		] );

		do_action( 'oec_ai_catalog_synced' ); // sitemap y llms.txt se regeneran (inc/sitemap.php)
	}

	private static function abort( array $job, string $error ): array {
		self::save_meta( array_merge( self::get_meta(), [
			'status'   => 'error',
			'progress' => '',
			'errors'   => array_merge( $job['errors'], [ $error ] ),
		] ) );
		$job['phase'] = 'done';
		return $job;
	}

	private static function progress_label( array $job ): string {
		$done = ( $job['total'] ?? 0 ) - count( $job['queue'] );
		switch ( $job['phase'] ) {
			case 'open_list':
				return $job['src'] ? sprintf( 'Leyendo el listado de abiertas (token secundario %d)…', $job['src'] ) : 'Leyendo el listado de abiertas…';
			case 'closed_list':
				$i = (int) array_search( $job['full_closed'][ $job['csrc'] ] ?? 'primary', $job['sources'], true );
				return $i ? sprintf( 'Leyendo el listado de cerradas (token secundario %d)…', $i ) : 'Leyendo el listado de cerradas…';
			case 'open_details':
				return sprintf( 'Fichas de abiertas: %d de %d', $done, $job['total'] );
			case 'closed_details':
				return sprintf( 'Fichas nuevas de cerradas: %d de %d', $done, $job['total'] );
		}
		return 'Armando índices…';
	}

	/* ── Archivos temporales del trabajo (_job-*.json) ──────── */

	private static function job_file( string $name ): string {
		return self::get_dir() . '/_job-' . $name . '.json';
	}

	private static function write_job_file( string $name, array $data ): void {
		file_put_contents( self::job_file( $name ), wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
	}

	private static function read_job_file( string $name ): array {
		$path = self::job_file( $name );
		return file_exists( $path ) ? (array) json_decode( (string) file_get_contents( $path ), true ) : [];
	}

	private static function delete_job_files(): void {
		foreach ( glob( self::get_dir() . '/_job-*.json' ) ?: [] as $f ) {
			unlink( $f );
		}
	}

	/** GET en paralelo de varias URLs de la API → [clave => JSON decodificado | null]. */
	private static function fetch_json_multiple( array $urls, string $token ): array {
		$requests = [];
		foreach ( $urls as $key => $url ) {
			$requests[ $key ] = [
				'url'     => $url,
				'headers' => [ 'X-API-TOKEN' => $token ],
				'type'    => 'GET',
			];
		}
		$class     = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
		$responses = [];
		try {
			$responses = $class::request_multiple( $requests, [ 'timeout' => 45, 'connect_timeout' => 15 ] );
		} catch ( \Throwable $e ) {
			$responses = [];
		}
		$out = [];
		foreach ( $urls as $key => $url ) {
			$r           = $responses[ $key ] ?? null;
			$out[ $key ] = ( is_object( $r ) && isset( $r->body ) && 200 === (int) ( $r->status_code ?? 0 ) )
				? json_decode( $r->body, true )
				: null;
		}
		return $out;
	}

	/* ── Parallel batch: detail + reviews ───────────────────── */

	private static function fetch_batch( array $ids, string $token ): array {
		$requests = [];
		foreach ( $ids as $id ) {
			$requests[ $id . '_d' ] = [
				'url'     => 'https://oas-api.onlineeducation.center/api-oas/v1/trainings/' . $id,
				'headers' => [ 'X-API-TOKEN' => $token ],
				'type'    => 'GET',
				'options' => [ 'timeout' => 30 ],
			];
			$requests[ $id . '_r' ] = [
				'url'     => 'https://api.g-se.com/v2/content/trainings/' . $id . '/reviews?page=1',
				'type'    => 'GET',
				'options' => [ 'timeout' => 20 ],
			];
		}

		try {
			$class = class_exists( '\WpOrg\Requests\Requests' )
				? '\WpOrg\Requests\Requests'
				: 'Requests';

			$responses = $class::request_multiple( $requests, [
				'timeout'   => 30,
				'useragent' => 'WordPress/' . get_bloginfo( 'version' ),
			] );
		} catch ( \Throwable $e ) {
			// Fallback: serial requests
			return self::fetch_batch_serial( $ids, $token );
		}

		$results = [];
		$exception_class = class_exists( '\WpOrg\Requests\Exception' )
			? '\WpOrg\Requests\Exception'
			: 'Requests_Exception';

		foreach ( $ids as $id ) {
			$detail_resp  = $responses[ $id . '_d' ] ?? null;
			$reviews_resp = $responses[ $id . '_r' ] ?? null;

			if ( ! $detail_resp || $detail_resp instanceof $exception_class ) {
				$results[ $id ] = [ 'error' => 'detail fetch failed' ];
				continue;
			}

			$detail_body = json_decode( $detail_resp->body, true );
			if ( empty( $detail_body['data'] ) ) {
				$results[ $id ] = [ 'error' => 'empty detail' ];
				continue;
			}

			$reviews = [];
			if ( $reviews_resp && ! ( $reviews_resp instanceof $exception_class ) ) {
				$rb      = json_decode( $reviews_resp->body, true );
				$reviews = $rb['reviews'] ?? [];
			}

			$results[ $id ] = [
				'detail'  => $detail_body['data'],
				'reviews' => $reviews,
			];
		}

		return $results;
	}

	private static function fetch_batch_serial( array $ids, string $token ): array {
		$results = [];
		foreach ( $ids as $id ) {
			$dr = wp_remote_get( 'https://oas-api.onlineeducation.center/api-oas/v1/trainings/' . $id, [
				'timeout' => 30,
				'headers' => [ 'X-API-TOKEN' => $token ],
			] );

			if ( is_wp_error( $dr ) ) {
				$results[ $id ] = [ 'error' => 'detail fetch failed' ];
				continue;
			}

			$detail_body = json_decode( wp_remote_retrieve_body( $dr ), true );
			if ( empty( $detail_body['data'] ) ) {
				$results[ $id ] = [ 'error' => 'empty detail' ];
				continue;
			}

			$rr      = wp_remote_get( 'https://api.g-se.com/v2/content/trainings/' . $id . '/reviews?page=1', [ 'timeout' => 20 ] );
			$reviews = [];
			if ( ! is_wp_error( $rr ) ) {
				$rb      = json_decode( wp_remote_retrieve_body( $rr ), true );
				$reviews = $rb['reviews'] ?? [];
			}

			$results[ $id ] = [
				'detail'  => $detail_body['data'],
				'reviews' => $reviews,
			];
		}
		return $results;
	}

	/* ── Data transformation ────────────────────────────────── */

	private static function process( array $d, array $raw_reviews ): array {
		$strip = static fn( $html ) => trim( wp_strip_all_tags( $html ?? '' ) );

		// Modules + subjects
		$modules = [];
		foreach ( $d['modules']['data'] ?? [] as $mod ) {
			$subjects = [];
			foreach ( $mod['subjects']['data'] ?? [] as $sub ) {
				$content = $strip( $sub['content'] );
				if ( $content ) {
					$subjects[] = [ 'name' => $sub['name'], 'content' => $content ];
				}
			}
			if ( $subjects ) {
				$modules[] = [ 'number' => $mod['number'], 'subjects' => $subjects ];
			}
		}

		// Teachers (top-level, deduplicated)
		$teachers = [];
		foreach ( $d['teachers']['data'] ?? [] as $t ) {
			$name = trim( ( $t['prefix'] ?? '' ) . ' ' . $t['full_name'] . ' ' . ( $t['suffix'] ?? '' ) );
			$teachers[] = [
				'name'       => trim( $name ),
				'background' => $t['background'] ?? '',
				'bio'        => $strip( $t['biography'] ?? '' ),
				'photo'      => $t['photo'] ?? $t['image'] ?? $t['avatar'] ?? '',
			];
		}

		// Certificates
		$certs = [];
		foreach ( $d['certificates']['data'] ?? [] as $c ) {
			$certs[] = [ 'name' => $c['name'], 'type' => $c['type'] ];
		}

		// Supporting organizations
		$supports = array_column( $d['supports']['data'] ?? [], 'name' );

		// Organización que dicta la formación (distinta de "supports" arriba,
		// que son avales/instituciones que respaldan, no quien la dicta).
		$org_data    = $d['organization']['data'] ?? [];
		$org_slug    = $org_data['slug'] ?? '';
		$organization = $org_slug ? [
			'id'                => $org_data['id'] ?? '',
			'slug'              => $org_slug,
			'name'              => $org_data['name'] ?? '',
			'short_name'        => $org_data['shortName'] ?? ( $org_data['name'] ?? '' ),
			'logo'              => $org_data['logo'] ?? '',
			'logo_gse'          => $org_data['logo_gse'] ?? '',
			'short_description' => $strip( $org_data['short_description'] ?? '' ),
			'domain'            => $org_data['domain'] ?? '',
		] : null;

		// Reviews: rating >= 4, comment >= 60 chars, max 8. Foto, fecha,
		// especialidad y país del alumno: los usa [oec-opiniones] (tema).
		$reviews = [];
		foreach ( $raw_reviews as $r ) {
			if ( ( $r['rating'] ?? 0 ) < 4 || strlen( $r['comment'] ?? '' ) < 60 ) {
				continue;
			}
			$reviews[] = [
				'author'    => trim( ( $r['author']['first_name'] ?? '' ) . ' ' . ( $r['author']['last_name'] ?? '' ) ),
				'rating'    => $r['rating'],
				'comment'   => $r['comment'],
				'image'     => (string) ( $r['author']['image'] ?? '' ),
				'specialty' => (string) ( $r['author']['specialty'] ?? '' ),
				'origin'    => (string) ( $r['author']['origin'] ?? '' ),
				'date'      => substr( (string) ( $r['date'] ?? '' ), 0, 10 ),
			];
			if ( count( $reviews ) >= 8 ) {
				break;
			}
		}

		// Tags from wpgroup (todo, sin filtrar — sirve como keywords para el chat IA)
		$tags = $d['wpgroup']
			? array_values( array_filter( explode( ' ', trim( $d['wpgroup'] ) ) ) )
			: [];

		// Temáticas reales dentro de wpgroup (filtradas contra la lista
		// conocida — descarta tags de campaña como "christmassale").
		$tematicas = array_values( array_intersect( $tags, self::TEMATICA_SLUGS ) );

		return [
			'id'              => $d['id'],
			'slug'            => (string) ( $d['slug'] ?? '' ),
			'edition_number'  => (int) ( $d['edition_number'] ?? 0 ),
			'title'           => $d['title'],
			'type'            => $d['type'],
			'org'             => $org_data['name'] ?? '',
			'organization'    => $organization,
			'tematicas'       => $tematicas,
			'modality'        => $d['modality'],
			'synchronicity'   => $d['synchronicity'],
			'lecture_hours'   => (int) ( $d['lecture_hours'] ?? 0 ),
			'total_students'  => (int) ( $d['total_students'] ?? 0 ),
			'start'           => substr( $d['start'] ?? '', 0, 10 ),
			'end'             => substr( $d['end'] ?? '', 0, 10 ),
			'enrollment_end'  => substr( $d['enrollment_end'] ?? '', 0, 10 ),
			'url'             => $d['oec_content_url'] ?? $d['canonical'] ?? '',
			'image'           => $d['image'] ?? '',
			'description'      => $strip( $d['short_description'] ),
			'objectives'       => $strip( $d['objetives'] ),
			'target_audience'  => $strip( $d['target_audience'] ),
			'graduate_profile' => $strip( $d['graduate_profile'] ?? $d['graduate_profile_text'] ?? '' ),
			'teachers'        => $teachers,
			'certificates'    => $certs,
			'supports'        => $supports,
			'modules'         => $modules,
			'reviews'         => $reviews,
			'reviews_summary' => oec_ai_catalog_summary( $d['reviews_summary'] ?? self::$summaries[ $d['id'] ] ?? null ),
			// Descuento por pago anticipado (solo % y vencimiento: los montos
			// dependen del país/moneda del visitante) y fecha de publicación.
			'early_payment'   => ! empty( $d['prices']['discounts']['early_payment']['percentage'] ) ? [
				'percentage' => (int) $d['prices']['discounts']['early_payment']['percentage'],
				'expiration' => substr( (string) ( $d['prices']['discounts']['early_payment']['expiration'] ?? '' ), 0, 10 ),
			] : null,
			'publish_start'   => substr( (string) ( $d['publish_start'] ?? '' ), 0, 10 ),
			'published_at'    => (string) ( $d['publish_start'] ?? '' ), // con hora: desempata el orden por publicación
			'tags'            => $tags,
			'great_lecturers'     => (bool) $d['great_lecturers'],
			'great_topic'         => (bool) $d['great_topic'],
			'great_certification' => (bool) $d['great_certification'],
			'great_organizer'     => (bool) ( $d['great_organizer'] ?? false ),
			'great_price'         => (bool) ( $d['great_price'] ?? false ),
			'relevance'           => (int) ( $d['relevance'] ?? 1 ),
		];
	}

	private static function index_entry( array $f ): array {
		$mod_subjects = [];
		foreach ( $f['modules'] as $mod ) {
			foreach ( $mod['subjects'] as $sub ) {
				$mod_subjects[] = $sub['name'];
			}
		}

		$keywords = strtolower( implode( ' ', array_filter( [
			$f['title'],
			$f['type'],
			$f['org'],
			$f['modality'],
			$f['synchronicity'],
			implode( ' ', array_column( $f['teachers'], 'name' ) ),
			implode( ' ', $f['tags'] ),
			implode( ' ', array_column( $f['certificates'], 'name' ) ),
			implode( ' ', $f['supports'] ),
			substr( $f['description'], 0, 300 ),
			substr( $f['objectives'], 0, 150 ),
			substr( $f['target_audience'], 0, 150 ),
			substr( $f['graduate_profile'], 0, 150 ),
			substr( implode( ' ', $mod_subjects ), 0, 400 ),
		] ) ) );

		return [
			'id'             => $f['id'],
			'title'          => $f['title'],
			'type'           => $f['type'],
			'tags'           => $f['tags'],
			'teachers'       => array_column( $f['teachers'], 'name' ),
			'org'            => $f['org'],
			'modality'       => $f['modality'],
			'synchronicity'  => $f['synchronicity'],
			'enrollment_end' => $f['enrollment_end'],
			'early_payment'  => $f['early_payment'] ?? null,
			'publish_start'  => $f['publish_start'] ?? '',
			'total_students' => $f['total_students'] ?? 0,
			'lecture_hours'  => $f['lecture_hours'] ?? 0,
			'keywords'       => $keywords,
			'community'      => $f['community'] ?? '',
			'primary'        => oec_formation_is_primary( $f ),
		];
	}

	/* ── Writers ────────────────────────────────────────────── */

	private static function save_formation( string $id, array $data ): void {
		$path = self::get_dir() . '/formations/' . sanitize_file_name( $id ) . '.json';
		file_put_contents( $path, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	private static function save_index( array $formations ): void {
		$path = self::get_dir() . '/index.json';
		file_put_contents( $path, wp_json_encode( [
			'updated'    => current_time( 'c' ),
			'count'      => count( $formations ),
			'formations' => $formations,
		], JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * listing.json: una fila liviana por formación (abiertas + cerradas),
	 * con lo que usan la card, los filtros y la búsqueda de /formaciones.
	 * Docentes y fecha de publicación salen de la ficha (la fila del
	 * listado no los trae); si la ficha de una cerrada todavía no se bajó,
	 * la fila va igual, sin esos dos datos.
	 */
	private static function save_listing( array $open_rows, array $closed_rows, array $carry = [] ): void {
		$rows = [];
		$seen = [];
		foreach ( [ [ $open_rows, true ], [ $closed_rows, false ], [ $carry, null ] ] as [ $list, $open ] ) {
			foreach ( $list as $raw ) {
				$id = (string) $raw['id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				if ( null === $open ) { // secundario que falló hoy: la fila tal cual estaba
					$rows[] = $raw;
					continue;
				}
				if ( isset( $raw['_listing'] ) ) { // fila conservada del sync anterior
					$rows[] = array_merge( $raw['_listing'], [ 'open' => $open ] );
					continue;
				}
				$rows[] = self::listing_row( $raw, self::get_formation( $id ), $open );
			}
		}

		file_put_contents( self::get_dir() . '/listing.json', wp_json_encode( [
			'updated'    => current_time( 'c' ),
			'count'      => count( $rows ),
			'formations' => $rows,
		], JSON_UNESCAPED_UNICODE ) );
	}

	private static function listing_row( array $r, ?array $f, bool $open ): array {
		$tags      = array_values( array_filter( explode( ' ', trim( (string) ( $r['wpgroup'] ?? '' ) ) ) ) );
		$teachers  = array_column( $f['teachers'] ?? [], 'name' );
		$org       = (string) ( $r['organization']['name'] ?? ( $f['org'] ?? '' ) );
		$desc      = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) ( $r['short_description'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) );
		$summary   = oec_ai_catalog_summary( $r['reviews_summary'] ?? null );

		return array_merge( [
			'id'             => (string) $r['id'],
			'slug'           => (string) ( $r['slug'] ?? '' ),
			'title'          => (string) ( $r['title'] ?? '' ),
			'type'           => (string) ( $r['type'] ?? '' ),
			'image'          => (string) ( $r['image'] ?? '' ),
			'org'            => $org,
			'description'    => mb_substr( $desc, 0, 320 ),
			'modality'       => (string) ( $r['modality'] ?? '' ),
			'synchronicity'  => (string) ( $r['synchronicity'] ?? '' ),
			'edition_number' => (int) ( $r['edition_number'] ?? 0 ),
			'start'          => substr( (string) ( $r['start'] ?? '' ), 0, 10 ),
			'enrollment_end' => substr( (string) ( $r['enrollment_end'] ?? '' ), 0, 10 ),
			'publish_start'  => (string) ( $f['published_at'] ?? '' ),
			'reviews'        => $summary,
			'badges'         => array_keys( array_filter( [
				'Docentes Destacados'   => ! empty( $r['great_lecturers'] ),
				'Temática Destacada'    => ! empty( $r['great_topic'] ),
				'Organizador Líder'     => ! empty( $r['great_organizer'] ),
				'Certificación Oficial' => ! empty( $r['great_certification'] ),
				'Mejor Precio'          => ! empty( $r['great_price'] ),
			] ) ),
			'tematicas'      => array_values( array_intersect( $tags, self::TEMATICA_SLUGS ) ),
			'teachers'       => $teachers,
			'open'           => $open,
			// Texto de búsqueda ya normalizado (minúsculas, sin tildes).
			'search'         => oec_search_normalize( implode( ' ', array_merge(
				[ $r['title'] ?? '', $org, $r['type'] ?? '' ],
				$teachers,
				$tags,
				[ mb_substr( $desc, 0, 320 ) ]
			) ) ),
		], self::origin( (string) ( $r['_source'] ?? 'primary' ), (string) ( $r['community'] ?? '' ) ) );
	}

	/**
	 * Va sumando, formación por formación, sus datos a la organización
	 * que la dicta ($organizations, pasado por referencia) — así al
	 * terminar el sync ya queda armado {slug => datos + formaciones[]}
	 * sin una segunda pasada.
	 */
	private static function accumulate_organization( array &$organizations, array $f ): void {
		$org = $f['organization'] ?? null;
		if ( ! $org || empty( $org['slug'] ) ) {
			return;
		}
		$slug = $org['slug'];
		if ( ! isset( $organizations[ $slug ] ) ) {
			$organizations[ $slug ] = [
				'slug'              => $slug,
				'name'              => $org['name'],
				'short_name'        => $org['short_name'],
				'logo'              => $org['logo'],
				'logo_gse'          => $org['logo_gse'],
				'short_description' => $org['short_description'],
				'domain'            => $org['domain'],
				'tematicas'         => [],
				'formations'        => [],
			];
		}
		$primary = oec_formation_is_primary( $f );
		$organizations[ $slug ]['formations'][] = [
			'id'             => $f['id'],
			'title'          => $f['title'],
			'type'           => $f['type'],
			'url'            => $f['url'],
			'slug'           => $f['slug'] ?? '',
			'image'          => $f['image'],
			'enrollment_end' => $f['enrollment_end'],
			'tematicas'      => $f['tematicas'],
			'community'      => $f['community'] ?? '',
			'primary'        => $primary,
		];
		if ( ! $primary ) {
			return; // las temáticas (y la cantidad, ver save_organizations()) cuentan solo las del token principal
		}
		foreach ( $f['tematicas'] as $tematica ) {
			$organizations[ $slug ]['tematicas'][ $tematica ] =
				( $organizations[ $slug ]['tematicas'][ $tematica ] ?? 0 ) + 1;
		}
	}

	/**
	 * Persiste organizations.json (índice liviano, para el shortcode de
	 * listado) + organizations/{slug}.json (detalle completo, para la
	 * landing de cada una). Se reescribe entero en cada sync a partir de
	 * las formaciones con inscripción abierta de ESE sync — no arrastra
	 * organizaciones de sincronizaciones anteriores que ya no tengan
	 * ninguna formación abierta hoy.
	 *
	 * Una organización existe en el sitio solo si tiene alguna formación
	 * del token principal; su landing muestra también las de los tokens
	 * secundarios, pero "count" (listado, vitrina, sitemap) cuenta solo
	 * las del principal.
	 */
	private static function save_organizations( array $organizations ): void {
		$dir   = self::get_dir();
		$index = [];

		foreach ( $organizations as $slug => $org ) {
			$count = count( array_filter( $org['formations'], 'oec_formation_is_primary' ) );
			if ( ! $count ) {
				continue;
			}
			file_put_contents(
				$dir . '/organizations/' . sanitize_file_name( $slug ) . '.json',
				wp_json_encode( $org, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			);
			$index[] = [
				'slug'       => $slug,
				'name'       => $org['name'],
				'short_name' => $org['short_name'],
				'logo'       => $org['logo'],
				'count'      => $count,
				'tematicas'  => $org['tematicas'],
			];
		}

		usort( $index, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		file_put_contents( $dir . '/organizations.json', wp_json_encode( [
			'updated'       => current_time( 'c' ),
			'count'         => count( $index ),
			'organizations' => $index,
		], JSON_UNESCAPED_UNICODE ) );
	}

	/* ── History index: accumulates all formations ever seen ── */

	public static function get_history_index( bool $all = false ): array {
		$path = self::get_dir() . '/history_index.json';
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$data = json_decode( file_get_contents( $path ), true );
		return self::scope( $data['formations'] ?? [], $all );
	}

	private static function merge_history( array $open_index ): void {
		$path    = self::get_dir() . '/history_index.json';
		$history = [];

		if ( file_exists( $path ) ) {
			$data    = json_decode( file_get_contents( $path ), true );
			$history = $data['formations'] ?? [];
		}

		$open_ids = array_flip( array_column( $open_index, 'id' ) );

		// Index existing history by id for fast lookup
		$history_map = [];
		foreach ( $history as $entry ) {
			$history_map[ $entry['id'] ] = $entry;
		}

		// Merge open formations into history (update if already present)
		foreach ( $open_index as $entry ) {
			$history_map[ $entry['id'] ] = [
				'id'             => $entry['id'],
				'title'          => $entry['title'],
				'teachers'       => $entry['teachers'],
				'keywords'       => $entry['keywords'],
				'url'            => $entry['url'] ?? '',
				'org'            => $entry['org'] ?? '',
				'tags'           => $entry['tags'] ?? [],
				'total_students' => $entry['total_students'] ?? 0,
				'lecture_hours'  => $entry['lecture_hours'] ?? 0,
				'community'      => $entry['community'] ?? '',
				'primary'        => $entry['primary'] ?? true,
			];
		}

		// Entradas cerradas guardadas antes de que el histórico tuviera
		// egresados/horas/temáticas: se completan desde su ficha, que queda
		// en formations/ aunque la formación ya no esté abierta.
		foreach ( $history_map as $id => $entry ) {
			if ( isset( $entry['total_students'] ) ) {
				continue;
			}
			$f = self::get_formation( (string) $id );
			$history_map[ $id ]['tags']           = $f['tags'] ?? [];
			$history_map[ $id ]['total_students'] = (int) ( $f['total_students'] ?? 0 );
			$history_map[ $id ]['lecture_hours']  = (int) ( $f['lecture_hours'] ?? 0 );
		}

		file_put_contents( $path, wp_json_encode( [
			'updated'    => current_time( 'c' ),
			'count'      => count( $history_map ),
			'formations' => array_values( $history_map ),
		], JSON_UNESCAPED_UNICODE ) );
	}

	private static function save_meta( array $meta ): void {
		update_option( self::OPTION_META, $meta, false );
	}

	/* ── Admin AJAX: manual sync ────────────────────────────── */

	/**
	 * Botón "Sincronizar ahora": el primer pedido arranca el trabajo
	 * (start=1) y el JS sigue llamando mientras status sea 'running'; cada
	 * llamada corre una tanda, así avanza aunque no haya visitas que
	 * disparen WP-Cron.
	 */
	public static function ajax_sync(): void {
		check_ajax_referer( 'oec_ai_sync', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Sin permisos', 403 );
		}
		// El trabajo vive en el sitio de configuración (ver init()).
		$switch = is_multisite() && ! oec_is_config_site();
		if ( $switch ) {
			switch_to_blog( oec_config_blog_id() );
		}
		if ( ! empty( $_POST['start'] ) ) {
			self::start();
		} else {
			self::run_slice();
		}
		$meta = self::get_meta();
		if ( $switch ) {
			restore_current_blog();
		}
		wp_send_json_success( $meta );
	}
}

OEC_AI_Catalog::init();

/** reviews_summary de la API → ['average' => float, 'count' => int] (o null). */
function oec_ai_catalog_summary( $summary ): ?array {
	$count = (int) ( $summary['count'] ?? 0 );
	return $count > 0 ? [ 'average' => round( (float) ( $summary['average'] ?? 0 ), 2 ), 'count' => $count ] : null;
}

/** Texto para búsquedas: minúsculas, sin tildes y con espacios simples. */
function oec_search_normalize( string $text ): string {
	return trim( preg_replace( '/\s+/u', ' ', remove_accents( mb_strtolower( wp_strip_all_tags( $text ) ) ) ) );
}
