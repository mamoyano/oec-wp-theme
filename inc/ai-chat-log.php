<?php
defined( 'ABSPATH' ) || exit;

/*
 * Registro de conversaciones del chat IA (para la empresa).
 *
 * Una sola tabla para toda la red ({base_prefix}oec_chat_log): una fila por
 * consulta, con la pregunta, la respuesta, las formaciones que se mostraron
 * como tarjeta, las que el usuario cliqueó y los tiempos. Se guarda DESPUÉS
 * de cerrar la respuesta al usuario (ver OEC_AI_Chat::handle_stream()), así
 * que no suma espera. No se guardan IP ni datos personales: la conversación
 * se identifica con un ID anónimo que genera el navegador.
 *
 * Retención: RETENTION_MONTHS; un cron diario borra lo más viejo.
 * Pantalla: "Chat IA" en el admin del sitio de configuración (/es/), con
 * resumen, filtros y exportación a CSV.
 */
class OEC_AI_Chat_Log {

	const DB_VERSION       = 1;
	const DB_OPTION        = 'oec_chat_log_db';
	const CRON_HOOK        = 'oec_chat_log_purge';
	const RETENTION_MONTHS = 12;
	const PER_PAGE         = 50;

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'maybe_create_table' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'purge' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_oec_chat_log_csv', [ __CLASS__, 'export_csv' ] );

		// El borrado corre solo en el sitio de configuración (la tabla es una para toda la red).
		add_action( 'init', static function () {
			if ( ! oec_is_config_site() ) {
				if ( wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
				}
				return;
			}
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( strtotime( 'tomorrow 04:30:00' ), 'daily', self::CRON_HOOK );
			}
		} );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'oec_chat_log';
	}

	/* ── Tabla ──────────────────────────────────────────────── */

	public static function maybe_create_table(): void {
		if ( (int) get_network_option( null, self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			cid varchar(40) NOT NULL DEFAULT '',
			turn smallint(5) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page varchar(255) NOT NULL DEFAULT '',
			country varchar(4) NOT NULL DEFAULT '',
			message text NOT NULL,
			reply mediumtext NOT NULL,
			formations text NOT NULL,
			clicks text NOT NULL,
			expanded tinyint(1) NOT NULL DEFAULT 0,
			ms_first int(10) unsigned NOT NULL DEFAULT 0,
			ms_total int(10) unsigned NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY cid (cid)
		) $charset;" );
		update_network_option( null, self::DB_OPTION, self::DB_VERSION );
	}

	/* ── Escritura ──────────────────────────────────────────── */

	/** Guarda una consulta. $row: cid, turn, page, country, message, reply, formations[], expanded, ms_first, ms_total, error. */
	public static function insert( array $row ): void {
		global $wpdb;
		$wpdb->insert( self::table(), [
			'created_at' => current_time( 'mysql', true ),
			'cid'        => substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $row['cid'] ?? '' ) ), 0, 40 ),
			'turn'       => min( 65535, max( 0, (int) ( $row['turn'] ?? 0 ) ) ),
			'blog_id'    => get_current_blog_id(),
			'page'       => mb_substr( (string) ( $row['page'] ?? '' ), 0, 255 ),
			'country'    => substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $row['country'] ?? '' ) ) ), 0, 4 ),
			'message'    => (string) ( $row['message'] ?? '' ),
			'reply'      => (string) ( $row['reply'] ?? '' ),
			'formations' => wp_json_encode( array_values( $row['formations'] ?? [] ) ),
			'clicks'     => '[]',
			'expanded'   => empty( $row['expanded'] ) ? 0 : 1,
			'ms_first'   => max( 0, (int) ( $row['ms_first'] ?? 0 ) ),
			'ms_total'   => max( 0, (int) ( $row['ms_total'] ?? 0 ) ),
			'error'      => mb_substr( (string) ( $row['error'] ?? '' ), 0, 255 ),
		] );
	}

	/** Borra lo que tenga más de RETENTION_MONTHS (cron diario). */
	public static function purge(): void {
		global $wpdb;
		$limit = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_MONTHS . ' months' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $limit ) ); // phpcs:ignore
	}

	/* ── Clics en tarjetas (navigator.sendBeacon desde ai-chat.js) ── */

	public static function register_routes(): void {
		register_rest_route( 'oec/v1', '/chat-click', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_click' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle_click( WP_REST_Request $request ): WP_REST_Response {
		$data = json_decode( (string) $request->get_body(), true );
		$cid  = preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $data['cid'] ?? '' ) );
		$id   = (string) ( $data['id'] ?? '' );
		if ( ! $cid || ! preg_match( '/^t-[A-Za-z0-9]{14}$/', $id ) ) {
			return new WP_REST_Response( null, 204 );
		}
		global $wpdb;
		// El clic se anota en la última consulta de esa conversación que mostró esa formación.
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, clicks FROM ' . self::table() . ' WHERE cid = %s AND formations LIKE %s ORDER BY id DESC LIMIT 1', // phpcs:ignore
			$cid,
			'%' . $wpdb->esc_like( '"' . $id . '"' ) . '%'
		) );
		if ( $row ) {
			$clicks = (array) json_decode( $row->clicks, true );
			if ( ! in_array( $id, $clicks, true ) ) {
				$clicks[] = $id;
				$wpdb->update( self::table(), [ 'clicks' => wp_json_encode( $clicks ) ], [ 'id' => $row->id ] );
			}
		}
		return new WP_REST_Response( null, 204 );
	}

	/* ── Admin ──────────────────────────────────────────────── */

	public static function admin_menu(): void {
		if ( ! oec_is_config_site() ) {
			return;
		}
		add_menu_page( 'Conversaciones del chat IA', 'Chat IA', 'manage_options', 'oec-chat-log',
			[ __CLASS__, 'render_page' ], 'dashicons-format-chat', 59 );
	}

	/** Filtros de la pantalla / CSV → [WHERE sql, args]. */
	private static function filters(): array {
		global $wpdb;
		$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification
		$q    = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );    // phpcs:ignore WordPress.Security.NonceVerification
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : gmdate( 'Y-m-d' );
		$where = 'created_at >= %s AND created_at < %s';
		$args  = [ $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' ];
		if ( '' !== $q ) {
			$where .= ' AND (message LIKE %s OR reply LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			array_push( $args, $like, $like );
		}
		return [ $where, $args, compact( 'from', 'to', 'q' ) ];
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		[ $where, $args, $f ] = self::filters();

		$stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS consultas, COUNT(DISTINCT cid) AS conversaciones,
			        SUM(formations <> '[]') AS con_tarjetas, SUM(clicks <> '[]') AS con_clic,
			        ROUND(AVG(NULLIF(ms_first,0))) AS ms_first, ROUND(AVG(NULLIF(ms_total,0))) AS ms_total,
			        SUM(error <> '') AS errores
			 FROM $t WHERE $where", // phpcs:ignore
			...$args
		) );
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$total  = (int) $stats->consultas;
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
			...array_merge( $args, [ self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ] )
		) );
		$titles = self::titles_by_id();
		$pct    = static fn( $n ) => $total ? round( 100 * (int) $n / $total ) . '%' : '—';
		$sec    = static fn( $ms ) => $ms ? number_format_i18n( $ms / 1000, 1 ) . ' s' : '—';
		$csv    = wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'oec_chat_log_csv' ], $f ), admin_url( 'admin-post.php' ) ), 'oec_chat_log_csv' );
		?>
		<div class="wrap">
			<h1>Conversaciones del chat IA</h1>
			<p>Cada fila es una consulta. Se guardan <?php echo (int) self::RETENTION_MONTHS; ?> meses y después se borran solas. No se guardan IP ni datos personales.</p>

			<form method="get" style="margin:1em 0;display:flex;gap:.5em;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="page" value="oec-chat-log">
				<label>Desde <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label>Hasta <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Buscar en preguntas y respuestas">
				<button class="button">Filtrar</button>
				<a class="button button-primary" href="<?php echo esc_url( $csv ); ?>">Exportar CSV</a>
			</form>

			<table class="widefat striped" style="max-width:900px;margin-bottom:1.5em">
				<tbody><tr>
					<td><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong><br>consultas</td>
					<td><strong><?php echo esc_html( number_format_i18n( (int) $stats->conversaciones ) ); ?></strong><br>conversaciones</td>
					<td><strong><?php echo esc_html( $pct( $stats->con_tarjetas ) ); ?></strong><br>con tarjetas</td>
					<td><strong><?php echo esc_html( $pct( $stats->con_clic ) ); ?></strong><br>con clic en tarjeta</td>
					<td><strong><?php echo esc_html( $sec( (int) $stats->ms_first ) ); ?></strong><br>primer texto (prom.)</td>
					<td><strong><?php echo esc_html( $sec( (int) $stats->ms_total ) ); ?></strong><br>respuesta completa (prom.)</td>
					<td><strong><?php echo esc_html( number_format_i18n( (int) $stats->errores ) ); ?></strong><br>errores</td>
				</tr></tbody>
			</table>

			<table class="widefat striped">
				<thead><tr>
					<th style="width:9em">Fecha</th><th>Pregunta y respuesta</th><th style="width:22%">Tarjetas (✓ = clic)</th>
					<th style="width:10em">Página</th><th style="width:4em">País</th><th style="width:7em">Tiempos</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6">No hay consultas en este período.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) :
					$forms  = (array) json_decode( $r->formations, true );
					$clicks = (array) json_decode( $r->clicks, true );
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $r->created_at, 'd/m/Y H:i' ) ); ?><br>
							<small title="Conversación <?php echo esc_attr( $r->cid ); ?>">conv. <?php echo esc_html( substr( $r->cid, 0, 8 ) ); ?> · #<?php echo (int) $r->turn; ?></small></td>
						<td><strong><?php echo esc_html( $r->message ); ?></strong>
							<details><summary>Respuesta</summary><div style="white-space:pre-wrap"><?php echo esc_html( str_replace( '|||', "\n\n", $r->reply ) ); ?></div></details>
							<?php if ( $r->error ) : ?><span style="color:#b32d2e">Error: <?php echo esc_html( $r->error ); ?></span><?php endif; ?></td>
						<td><?php foreach ( $forms as $id ) : ?>
							<div><?php echo in_array( $id, $clicks, true ) ? '✓ ' : ''; ?><?php echo esc_html( $titles[ $id ] ?? $id ); ?></div>
						<?php endforeach; ?></td>
						<td><small><?php echo esc_html( $r->page ); ?></small></td>
						<td><?php echo esc_html( $r->country ); ?></td>
						<td><small><?php echo esc_html( $sec( (int) $r->ms_first ) ); ?> / <?php echo esc_html( $sec( (int) $r->ms_total ) ); ?><?php echo $r->expanded ? '<br>con ampliación' : ''; ?></small></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				] );
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/** id de formación → título, desde el catálogo (abiertas + cerradas). */
	private static function titles_by_id(): array {
		$titles = [];
		foreach ( OEC_AI_Catalog::get_listing() as $r ) {
			$titles[ $r['id'] ] = $r['title'];
		}
		return $titles;
	}

	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'oec_chat_log_csv' ) ) {
			wp_die( 'Sin permisos' );
		}
		global $wpdb;
		[ $where, $args, $f ] = self::filters();
		$titles = self::titles_by_id();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="chat-ia-' . $f['from'] . '_' . $f['to'] . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre bien las tildes
		fputcsv( $out, [ 'fecha', 'conversacion', 'turno', 'pagina', 'pais', 'pregunta', 'respuesta', 'tarjetas', 'clics', 'ampliacion', 'primer_texto_s', 'total_s', 'error' ] );

		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY id ASC LIMIT 1000 OFFSET %d", // phpcs:ignore
				...array_merge( $args, [ $offset ] )
			) );
			foreach ( $rows as $r ) {
				$name = static fn( $ids ) => implode( ' | ', array_map( static fn( $id ) => $titles[ $id ] ?? $id, (array) json_decode( $ids, true ) ) );
				fputcsv( $out, [
					get_date_from_gmt( $r->created_at, 'Y-m-d H:i:s' ), $r->cid, $r->turn, $r->page, $r->country,
					$r->message, str_replace( '|||', "\n\n", $r->reply ), $name( $r->formations ), $name( $r->clicks ),
					$r->expanded ? 'sí' : 'no', round( $r->ms_first / 1000, 2 ), round( $r->ms_total / 1000, 2 ), $r->error,
				] );
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
		fclose( $out );
		exit;
	}
}

OEC_AI_Chat_Log::init();
