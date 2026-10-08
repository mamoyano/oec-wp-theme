<?php
defined( 'ABSPATH' ) || exit;

class OEC_AI_Chat {

	const NAMESPACE    = 'oec/v1';
	const ROUTE        = '/chat';
	const MAX_RESULTS  = 8;
	const RATE_LIMIT   = 20; // requests per minute per IP

	// Stream detrás de proxies que bufferizan (Cloudways: Nginx → Apache → FPM):
	// cada envío se rellena con un comentario SSE de este tamaño para llenar el
	// buffer del proxy y obligarlo a soltar la respuesta, y el texto se agrupa en
	// tandas para no mandar el relleno por cada token (el tipeo lo hace el JS).
	// Si el servidor deja de bufferizar: 0 y 0 vuelve al stream normal.
	const SSE_PAD_BYTES = 8192;

	// Línea oculta al final de cada respuesta con los ID de las formaciones que
	// nombró la IA (ver system_prompt()): arma las tarjetas sin depender de
	// cómo escribió cada título. El servidor la corta antes de enviar el texto.
	const IDS_OPEN = '<<IDS:';
	const SSE_BATCH_SEC = 0.3;

	// Relleno de esta respuesta. ?nopad=1 lo desactiva solo en esa consulta,
	// para comprobar si el servidor ya transmite sin buffer.
	private static int $sse_pad = self::SSE_PAD_BYTES;

	// Para el registro: momento del primer texto enviado y si se amplió la consulta con IA.
	private static float $t_first   = 0;
	private static bool $did_expand = false;

	/* ── Bootstrap ─────────────────────────────────────────── */

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	public static function register_route(): void {
		$args = [
			'message'      => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'history'      => [ 'required' => false, 'type' => 'array',  'default' => [] ],
			'country'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'currency'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'mentioned_ids'=> [ 'required' => false, 'type' => 'array',  'default' => [] ],
			// Para el registro (inc/ai-chat-log.php): conversación anónima, nº de consulta y página.
			'cid'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'turn'         => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
			'page'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		];

		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle' ],
			'permission_callback' => '__return_true',
			'args'                => $args,
		] );

		register_rest_route( self::NAMESPACE, '/chat-stream', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_stream' ],
			'permission_callback' => '__return_true',
			'args'                => $args,
		] );
	}

	/* ── Main handler ───────────────────────────────────────── */

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$opts    = oec_get_options();
		$api_key = $opts['oec_anthropic_key'] ?? '';

		if ( ! $api_key ) {
			return new WP_REST_Response( [ 'error' => 'Asistente no configurado.' ], 503 );
		}

		// Rate limit: 20 req/min per IP
		$ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0' );
		$r_key = 'oec_chat_rate_' . md5( $ip );
		$count = (int) get_transient( $r_key );
		if ( $count >= self::RATE_LIMIT ) {
			return new WP_REST_Response( [ 'error' => 'Demasiadas consultas. Esperá un momento.' ], 429 );
		}
		set_transient( $r_key, $count + 1, MINUTE_IN_SECONDS );

		$message       = $request->get_param( 'message' );
		$history       = $request->get_param( 'history' );
		$country       = strtoupper( $request->get_param( 'country' ) );
		$currency      = strtoupper( $request->get_param( 'currency' ) );
		$mentioned_ids = $request->get_param( 'mentioned_ids' );

		// Prefilter on current message only; continuity handled by merge_mentioned
		$formations = self::prefilter( $message );
		$formations = self::merge_mentioned( $formations, $mentioned_ids );
		$closed     = self::prefilter_closed( $message, $formations );

		// Fetch real prices if we have location data
		$prices = [];
		if ( $country && $currency && ! empty( $formations ) ) {
			$prices = self::fetch_prices( array_column( $formations, 'id' ), $country, $currency );
		}

		// Build Claude messages
		$total            = count( OEC_AI_Catalog::get_index( true ) );
		$detailed_modules = self::asks_about_content( $message );
		$context          = self::build_context( $formations, $prices, $detailed_modules, $closed );
		$messages         = self::build_messages( $history, $message );

		// Call Anthropic
		$reply = self::call_anthropic( $api_key, $context, $messages, $total );
		if ( is_wp_error( $reply ) ) {
			return new WP_REST_Response( [ 'error' => 'Error del servicio de IA. Intenta de nuevo.' ], 500 );
		}

		// Return reply + formation cards for the UI
		$cards = array_map( [ __CLASS__, 'card' ], $formations );

		return new WP_REST_Response( [
			'reply'      => $reply,
			'formations' => $cards,
		] );
	}

	/* ── Relevance pre-filter ───────────────────────────────── */

	/* Token principal + secundarios: el chat también recomienda las de otras
	 * comunidades (swimming.science, fisio.one…), con link a su sitio. */
	private static function prefilter( string $query, string $extra = '' ): array {
		$index = OEC_AI_Catalog::get_index( true );
		if ( empty( $index ) ) {
			return [];
		}
		$top = self::rank( $index, $query, $extra, self::MAX_RESULTS );
		if ( ! $top ) {
			// Sin coincidencias: las 10 de este sitio con cierre de inscripción más cercano
			$index = OEC_AI_Catalog::get_index() ?: $index;
			usort( $index, static fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ) );
			return self::load_formations( array_slice( $index, 0, 10 ) );
		}
		return self::load_formations( $top );
	}

	/* ── Closed-formation lookup against historical index ───── */

	private static function prefilter_closed( string $query, array $open_formations, string $extra = '' ): array {
		$open_ids = array_flip( array_column( $open_formations, 'id' ) );
		$history  = array_values( array_filter(
			OEC_AI_Catalog::get_history_index( true ),
			static fn( $e ) => ! isset( $open_ids[ $e['id'] ] )
		) );
		return $history ? self::rank( $history, $query, $extra, 4 ) : [];
	}

	/** Palabras que no ayudan a encontrar una formación. */
	const SEARCH_STOPWORDS = [
		'que', 'hay', 'uno', 'una', 'unos', 'unas', 'los', 'las', 'del', 'con', 'sin', 'para', 'por', 'sobre',
		'algo', 'alguno', 'alguna', 'algun', 'como', 'cual', 'cuales', 'donde', 'cuando', 'tiene', 'tienen',
		'tenes', 'tengo', 'quiero', 'queria', 'busco', 'buscando', 'necesito', 'llama', 'llamado', 'mas',
		'muy', 'esta', 'este', 'ese', 'esa', 'eso', 'hola', 'gracias', 'curso', 'cursos', 'formacion',
		'formaciones', 'otro', 'otra', 'les', 'mis', 'tus', 'sus', 'pero', 'porque', 'saber', 'conocer',
	];

	/**
	 * Formaciones más relevantes para la consulta (las $limit primeras).
	 *
	 * - Cada palabra pesa según qué tan rara es en el catálogo: un apellido
	 *   ("anselmi", 3 formaciones) vale mucho más que "fuerza" (decenas).
	 * - Lo que escribió el usuario pesa más que la ampliación de la IA
	 *   ($extra, que suma sinónimos genéricos y antes tapaba lo específico).
	 * - Si la consulta contiene el nombre exacto de una formación, va primera.
	 * - Sin tildes ni mayúsculas en ambos lados.
	 */
	private static function rank( array $entries, string $query, string $extra, int $limit ): array {
		$tokens = static fn( string $t ) => array_values( array_unique( array_filter(
			explode( ' ', self::title_norm( $t ) ),
			static fn( $w ) => strlen( $w ) >= 3 && ! in_array( $w, self::SEARCH_STOPWORDS, true )
		) ) );
		$main  = $tokens( $query );
		$added = array_values( array_diff( $tokens( $extra ), $main ) );
		$q     = ' ' . self::title_norm( $query ) . ' ';
		if ( ! $main && ! $added ) {
			return [];
		}

		$docs = [];
		$df   = [];
		foreach ( $entries as $i => $e ) {
			$docs[ $i ] = [
				'kw'       => ' ' . self::title_norm( ( $e['keywords'] ?? '' ) . ' ' . ( $e['title'] ?? '' ) ) . ' ',
				'title'    => ' ' . self::title_norm( $e['title'] ?? '' ) . ' ',
				'teachers' => ' ' . self::title_norm( implode( ' ', $e['teachers'] ?? [] ) ) . ' ',
			];
			foreach ( array_merge( $main, $added ) as $w ) {
				if ( str_contains( $docs[ $i ]['kw'], $w ) ) {
					$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
				}
			}
		}
		$n = count( $entries );

		$scored = [];
		foreach ( $entries as $i => $e ) {
			$d     = $docs[ $i ];
			$score = 0.0;
			// Nombre exacto de la formación dentro de la consulta (2+ palabras)
			$t = trim( $d['title'] );
			if ( substr_count( $t, ' ' ) >= 1 && strlen( $t ) >= 8 && str_contains( $q, ' ' . $t . ' ' ) ) {
				$score += 100;
			}
			foreach ( [ [ $main, 1.0 ], [ $added, 0.35 ] ] as [ $words, $weight ] ) {
				foreach ( $words as $w ) {
					if ( ! str_contains( $d['kw'], $w ) ) {
						continue;
					}
					$field  = str_contains( $d['title'], $w ) ? 3 : ( str_contains( $d['teachers'], $w ) ? 3 : 1 );
					$idf    = log( ( $n + 1 ) / ( ( $df[ $w ] ?? 0 ) + 1 ) ) + 1;
					$score += $field * $idf * $weight;
				}
			}
			if ( $score > 0 ) {
				$scored[] = [ $score, $e ];
			}
		}
		usort( $scored, static fn( $a, $b ) => $b[0] <=> $a[0] );
		return array_column( array_slice( $scored, 0, $limit ), 1 );
	}

	private static function load_formations( array $entries ): array {
		return array_values( array_filter( array_map(
			static fn( $e ) => OEC_AI_Catalog::get_formation( $e['id'] ),
			$entries
		) ) );
	}

	private static function merge_mentioned( array $formations, array $mentioned_ids ): array {
		if ( empty( $mentioned_ids ) ) {
			return $formations;
		}
		$existing_ids = array_flip( array_column( $formations, 'id' ) );
		foreach ( $mentioned_ids as $id ) {
			$id = sanitize_text_field( (string) $id );
			if ( $id && ! isset( $existing_ids[ $id ] ) ) {
				$f = OEC_AI_Catalog::get_formation( $id );
				if ( $f ) {
					$formations[]          = $f;
					$existing_ids[ $id ]   = true;
				}
			}
		}
		return $formations;
	}

	/* ── Date formatter: YYYY-MM-DD → "12 de noviembre de 2026" ── */

	private static function format_date_human( string $iso ): string {
		$date = \DateTime::createFromFormat( 'Y-m-d', substr( $iso, 0, 10 ) );
		if ( ! $date ) {
			return $iso;
		}
		$months = [
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
			5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
			9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		];
		$day   = (int) $date->format( 'j' );
		$month = $months[ (int) $date->format( 'n' ) ];
		$year  = $date->format( 'Y' );
		return "{$day} de {$month} de {$year}";
	}

	/* ── Real-time price fetch ──────────────────────────────── */

	/** Precio de una formación por país/moneda: se guarda 1 hora (PRICE_TTL). */
	const PRICE_TTL = HOUR_IN_SECONDS;

	private static function fetch_prices( array $ids, string $country, string $currency ): array {
		$prices   = [];
		$requests = [];
		foreach ( $ids as $id ) {
			$cached = get_transient( self::price_key( $id, $country, $currency ) );
			if ( is_array( $cached ) ) {
				$prices[ $id ] = $cached;
				continue;
			}
			$requests[ $id ] = [
				'url'  => sprintf(
					'https://api.g-se.com/v2/content/trainings/%s/price/%s/%s',
					$id, $country, $currency
				),
				'type' => 'GET',
				'options' => [ 'timeout' => 10 ],
			];
		}

		if ( ! $requests ) {
			return $prices;
		}
		try {
			$class     = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
			$exc_class = class_exists( '\WpOrg\Requests\Exception' ) ? '\WpOrg\Requests\Exception' : 'Requests_Exception';
			$responses = $class::request_multiple( $requests, [ 'timeout' => 10 ] );
			foreach ( $responses as $id => $resp ) {
				if ( $resp instanceof $exc_class ) {
					continue;
				}
				$data = json_decode( $resp->body, true );
				if ( ! empty( $data['price'] ) ) {
					$prices[ $id ] = $data;
					set_transient( self::price_key( $id, $country, $currency ), $data, self::PRICE_TTL );
				}
			}
		} catch ( \Throwable $e ) {
			// Prices are optional; proceed without them
		}

		return $prices;
	}

	private static function price_key( string $id, string $country, string $currency ): string {
		return 'oec_chat_price_' . md5( $id . '|' . $country . '|' . $currency );
	}

	/* ── Context builder ────────────────────────────────────── */

	private static function build_context( array $formations, array $prices, bool $detailed_modules = false, array $closed = [] ): string {
		$parts = [];

		foreach ( $formations as $f ) {
			$parts[] = self::format_formation( $f, $prices[ $f['id'] ] ?? null, $detailed_modules );
		}

		if ( ! empty( $closed ) ) {
			$closed_lines = [ "FORMACIONES CON INSCRIPCIÓN CERRADA (solo referencia — NO recomendar inscripción ni fechas):" ];
			foreach ( $closed as $c ) {
				$teachers_str = implode( ', ', $c['teachers'] ?? [] );
				$line         = "• {$c['title']}";
				if ( $teachers_str ) {
					$line .= " | Docente(s): {$teachers_str}";
				}
				if ( ! empty( $c['org'] ) ) {
					$line .= " | Org: {$c['org']}";
				}
				if ( oec_formation_is_external( $c ) ) {
					$line .= ' | Comunidad: ' . oec_community_host( $c['community'] );
				}
				if ( ! empty( $c['url'] ) ) {
					$line .= " | URL: {$c['url']}";
				}
				$closed_lines[] = $line;
			}
			$parts[] = implode( "\n", $closed_lines );
		}

		return implode( "\n\n---\n\n", $parts );
	}

	private static function format_formation( array $f, ?array $price_data, bool $detailed_modules = false ): string {
		$lines = [];

		$rel_label = match ( $f['relevance'] ?? 1 ) {
			2       => 'muy destacada',
			0       => 'relleno',
			default => 'promedio',
		};
		$lines[] = "## {$f['title']} ({$f['type']})";
		$lines[] = "ID: {$f['id']}";
		$lines[] = "Relevance: {$f['relevance']} ({$rel_label})";
		$modality_labels = [
			'ONLINE'          => '100% online',
			'BLEND-LINE-COMP' => 'Blended — casi todo online con instancias presenciales obligatorias',
			'BLEND-LINE-OPT'  => 'Blended — casi todo online con instancias presenciales optativas',
			'BLEND-SITE-COMP' => 'Blended — casi todo presencial con soporte online obligatorio',
			'BLEND-SITE-OPT'  => 'Blended — casi todo presencial con soporte online optativo',
			'ONSITE'          => '100% presencial',
		];
		$sync_labels = [
			'SYNC'    => 'Sincrónico — clases, consultas y foros en vivo',
			'MIXED'   => 'Mixto — algunas clases grabadas, otras en vivo; consultas y foros sincrónicos',
			'ASYNC-F' => 'Asincrónico con foros — clases grabadas, sin consultas, foros sincrónicos; sin fecha de inicio fija',
			'ASYNC'   => 'Asincrónico — clases grabadas, sin consultas ni foros; sin fecha de inicio fija',
		];
		$modality_str = $modality_labels[ $f['modality'] ] ?? $f['modality'];
		$sync_str     = $sync_labels[ $f['synchronicity'] ] ?? $f['synchronicity'];
		$lines[] = "Organización: {$f['org']} | Modalidad: {$modality_str} | Sincronicidad: {$sync_str}";
		$lines[] = "Horas: {$f['lecture_hours']}h | Alumnos históricos: {$f['total_students']}";
		$start  = self::format_date_human( $f['start'] ?? '' );
		$end    = self::format_date_human( $f['end'] ?? '' );
		$enroll = self::format_date_human( $f['enrollment_end'] ?? '' );
		$lines[] = "Período: {$start} → {$end} | Inscripción hasta: {$enroll}";
		$lines[] = 'URL: ' . self::link( $f );
		if ( oec_formation_is_external( $f ) ) {
			$host    = oec_community_host( $f['community'] );
			$lines[] = "Comunidad: {$host} (formación de otra comunidad de OEC: se cursa e inscribe en {$host})";
		}

		$flags = array_filter( [
			$f['great_lecturers']     ? 'docentes_destacados'     : '',
			$f['great_topic']         ? 'tema_destacado'          : '',
			$f['great_certification'] ? 'certificacion_destacada' : '',
		] );
		if ( $flags ) {
			$lines[] = "Flags: " . implode( ', ', $flags );
		}

		if ( $f['description'] ) {
			$lines[] = "\nDescripción:\n" . substr( $f['description'], 0, 500 );
		}
		if ( $f['objectives'] ) {
			$lines[] = "\nObjetivos:\n" . substr( $f['objectives'], 0, 250 );
		}
		if ( $f['target_audience'] ) {
			$lines[] = "\nDirigido a:\n" . substr( $f['target_audience'], 0, 200 );
		}
		if ( ! empty( $f['graduate_profile'] ) ) {
			$lines[] = "\nPerfil del egresado:\n" . substr( $f['graduate_profile'], 0, 200 );
		}

		if ( ! empty( $f['teachers'] ) ) {
			$lines[] = "\nDocentes:";
			foreach ( $f['teachers'] as $t ) {
				$photo = $t['photo'] ? self::cdn_image( $t['photo'], 300 ) : '';
				$photo_line = $photo ? " | Foto: {$photo}" : '';
				$lines[] = "- {$t['name']} ({$t['background']}): {$t['bio']}{$photo_line}";
			}
		}

		if ( ! empty( $f['certificates'] ) ) {
			$names   = array_column( $f['certificates'], 'name' );
			$lines[] = "\nCertificados: " . implode( ', ', $names );
		}

		if ( ! empty( $f['supports'] ) ) {
			$lines[] = "Aval académico: " . implode( ', ', $f['supports'] );
		}

		if ( ! empty( $f['modules'] ) ) {
			$lines[] = "\nContenidos:";
			foreach ( $f['modules'] as $mod ) {
				$lines[] = "Módulo {$mod['number']}:";
				foreach ( $mod['subjects'] as $sub ) {
					// Include full subject content only when user explicitly asks about the program
					$lines[] = $detailed_modules
						? "  · {$sub['name']}: {$sub['content']}"
						: "  · {$sub['name']}";
				}
			}
		}

		if ( $price_data ) {
			$total    = number_format( $price_data['price']['total'] ?? 0, 0, ',', '.' );
			$cur      = $price_data['price']['currency'] ?? '';
			$lines[]  = "\nPrecio: {$cur} {$total}";

			if ( ! empty( $price_data['discount'] ) ) {
				$disc       = $price_data['discount'];
				$disc_total = number_format( $disc['total'] ?? 0, 0, ',', '.' );
				$disc_type  = $disc['type'] ?? '';
				$disc_label = $disc_type === 'early_payment' ? 'Descuento por pago anticipado' : 'Descuento por pago completo';

				if ( $disc_type === 'early_payment' && ! empty( $disc['expiration'] ) ) {
					$until    = self::format_date_human( substr( $disc['expiration'], 0, 10 ) );
					$lines[]  = "{$disc_label} {$disc['percentage']}% → {$cur} {$disc_total} (válido hasta {$until})";
				} else {
					$lines[]  = "{$disc_label} {$disc['percentage']}% → {$cur} {$disc_total}";
				}
			}

			if ( ! empty( $price_data['payment_methods'] ) ) {
				$methods = array_column( $price_data['payment_methods'], 'name' );
				$lines[] = "Formas de pago: " . implode( ', ', $methods );
			}

			// Module 1 option for multi-module formations
			if ( count( $f['modules'] ?? [] ) > 1 ) {
				$m1_total = $price_data['first_module_price']['price']['total'] ?? null;
				if ( $m1_total ) {
					$m1_price = number_format( (float) $m1_total, 0, ',', '.' );
					$lines[]  = "Opción de inicio: también puede inscribirse SOLO al Módulo 1 por {$cur} {$m1_price}";
				} else {
					$lines[]  = "Opción de inicio: también puede inscribirse SOLO al Módulo 1 (precio disponible al consultar)";
				}
			}
		}

		if ( ! empty( $f['reviews'] ) ) {
			$avg     = round( array_sum( array_column( $f['reviews'], 'rating' ) ) / count( $f['reviews'] ), 1 );
			$lines[] = "\nOpiniones de alumnos (promedio {$avg}/5):";
			foreach ( array_slice( $f['reviews'], 0, 4 ) as $r ) {
				$lines[] = "- \"{$r['comment']}\" — {$r['author']} (⭐ {$r['rating']}/5)";
			}
		}

		return implode( "\n", $lines );
	}

	/* ── Message builder ────────────────────────────────────── */

	private static function build_messages( array $history, string $message ): array {
		$messages = [];
		// Keep only the last 6 history items (3 back-and-forth turns)
		foreach ( array_slice( $history, -6 ) as $turn ) {
			$role    = $turn['role'] ?? '';
			$content = $turn['content'] ?? '';
			if ( in_array( $role, [ 'user', 'assistant' ], true ) && $content ) {
				$messages[] = [ 'role' => $role, 'content' => sanitize_text_field( $content ) ];
			}
		}
		$messages[] = [ 'role' => 'user', 'content' => $message ];
		return $messages;
	}

	/* ── Streaming handler (SSE) ────────────────────────────── */

	public static function handle_stream( WP_REST_Request $request ): void {
		$t0 = microtime( true ); // tiempos para el registro

		while ( ob_get_level() ) {
			ob_end_flush();
		}
		@ini_set( 'output_buffering', 'off' );    // phpcs:ignore
		@ini_set( 'zlib.output_compression', false ); // phpcs:ignore
		@ini_set( 'implicit_flush', '1' );            // phpcs:ignore
		@set_time_limit( 0 );                          // phpcs:ignore
		// Apache/LiteSpeed: sin gzip, que retiene la respuesta hasta el final
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );          // phpcs:ignore
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );

		// Relleno inicial: fuerza el envío de las cabeceras
		if ( ! empty( $_GET['nopad'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::$sse_pad = 0;
		}
		self::sse_flush( '' );

		$opts    = oec_get_options();
		$api_key = $opts['oec_anthropic_key'] ?? '';

		if ( ! $api_key ) {
			self::sse_data( [ 'message' => 'Asistente no configurado.' ] );
			exit;
		}

		$ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0' );
		$r_key = 'oec_chat_rate_' . md5( $ip );
		$count = (int) get_transient( $r_key );
		if ( $count >= self::RATE_LIMIT ) {
			self::sse_data( [ 'message' => 'Demasiadas consultas. Esperá un momento.' ] );
			exit;
		}
		set_transient( $r_key, $count + 1, MINUTE_IN_SECONDS );

		$message       = $request->get_param( 'message' );
		$history       = $request->get_param( 'history' );
		$country       = strtoupper( $request->get_param( 'country' ) );
		$currency      = strtoupper( $request->get_param( 'currency' ) );
		$mentioned_ids = $request->get_param( 'mentioned_ids' );

		self::sse_status( 'Analizando tu consulta...' );
		$expanded = self::expand_query( $api_key, $message );

		self::sse_status( 'Revisando el catálogo de formaciones...' );
		$extra      = trim( substr( $expanded, strlen( $message ) ) ); // solo la ampliación de la IA
		$formations = self::prefilter( $message, $extra );
		$formations = self::merge_mentioned( $formations, $mentioned_ids );
		$closed     = self::prefilter_closed( $message, $formations, $extra );

		$prices = [];
		if ( $country && $currency && ! empty( $formations ) ) {
			self::sse_status( 'Consultando precios actualizados...' );
			$prices = self::fetch_prices( array_column( $formations, 'id' ), $country, $currency );
		}

		self::sse_status( 'Preparando la respuesta...' );
		$total            = count( OEC_AI_Catalog::get_index( true ) );
		$detailed_modules = self::asks_about_content( $message );
		$context          = self::build_context( $formations, $prices, $detailed_modules, $closed );
		$messages         = self::build_messages( $history, $message );

		$full_reply = self::stream_anthropic( $api_key, $context, $messages, $total );
		[ $reply, $ids ] = self::split_ids( $full_reply );

		// Tarjetas: las formaciones que la IA declaró en <<IDS:…>> (en ese
		// orden) y, como respaldo por si omitió la línea, las que nombra por título.
		$by_id  = array_column( $formations, null, 'id' );
		$source = [];
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$source[ $id ] = $by_id[ $id ];
			}
		}
		foreach ( $formations as $f ) {
			if ( ! isset( $source[ $f['id'] ] ) && self::title_in_text( $f['title'], $reply ) ) {
				$source[ $f['id'] ] = $f;
			}
		}
		$source = array_values( $source );

		$cards = array_map( [ __CLASS__, 'card' ], $source );

		self::sse_data( [ 'done' => true, 'formations' => $cards ] );

		// Se cierra la respuesta al usuario y recién después se guarda el
		// registro: la escritura en la base no le suma espera a nadie.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		if ( class_exists( 'OEC_AI_Chat_Log' ) ) {
			OEC_AI_Chat_Log::insert( [
				'cid'        => $request->get_param( 'cid' ),
				'turn'       => $request->get_param( 'turn' ),
				'page'       => $request->get_param( 'page' ),
				'country'    => $country,
				'message'    => $message,
				'reply'      => $reply,
				'formations' => array_column( $source, 'id' ),
				'expanded'   => self::$did_expand,
				'ms_first'   => self::$t_first ? round( ( self::$t_first - $t0 ) * 1000 ) : 0,
				'ms_total'   => round( ( microtime( true ) - $t0 ) * 1000 ),
				'error'      => '' === trim( $reply ) ? 'Sin respuesta de la IA' : '',
			] );
		}
		exit;
	}

	private static function sse_data( array $data ): void {
		self::sse_flush( 'data: ' . wp_json_encode( $data ) . "\n\n" );
	}

	/* Envía un bloque SSE y lo empuja fuera del buffer del proxy con relleno
	 * (comentario SSE: línea que empieza con ":", el cliente la ignora). */
	private static function sse_flush( string $payload ): void {
		if ( self::$sse_pad > 0 ) {
			$payload .= ':' . str_repeat( ' ', self::$sse_pad ) . "\n\n";
		}
		echo $payload; // phpcs:ignore
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	private static function sse_status( string $msg ): void {
		self::sse_data( [ 'status' => $msg ] );
	}

	/** ¿Hay alguna formación abierta con 2+ palabras de la consulta en su título? */
	private static function matches_titles( string $query ): bool {
		static $generic = [ 'curso', 'cursos', 'formacion', 'formaciones', 'quiero', 'busco', 'buscando',
			'aprender', 'sobre', 'algo', 'interesa', 'tienen', 'tenes', 'alguna', 'algun', 'para', 'como', 'online' ];
		$words = array_diff(
			array_unique( array_filter( explode( ' ', self::title_norm( $query ) ), static fn( $w ) => strlen( $w ) >= 4 ) ),
			$generic
		);
		if ( count( $words ) < 2 ) {
			return false;
		}
		foreach ( OEC_AI_Catalog::get_index( true ) as $entry ) {
			$title = ' ' . self::title_norm( $entry['title'] ?? '' ) . ' ';
			$hits  = 0;
			foreach ( $words as $w ) {
				if ( str_contains( $title, ' ' . $w . ' ' ) && ++$hits >= 2 ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function expand_query( string $key, string $query ): string {
		// Saludos y mensajes sin palabras de búsqueda: no hay nada que expandir
		$norm = trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $query ) ) );
		if ( preg_match( '/^(hola|buen[oa]s?( d[ií]as| tardes| noches)?|hey|gracias|muchas gracias|ok|dale|genial|perfecto|chau|adi[oó]s)[\s!.,¡¿?]*$/u', $norm )
			|| ! preg_match( '/\p{L}{4,}/u', $norm ) ) {
			return $query;
		}

		// Si la consulta ya nombra el tema de alguna formación (2+ palabras en
		// su título), la búsqueda directa alcanza: se ahorra la llamada (~1,2 s).
		if ( self::matches_titles( $norm ) ) {
			return $query;
		}

		// Misma consulta → misma expansión: se guarda para no repetir la llamada
		$cache_key = 'oec_chat_exp_' . md5( $norm );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $query . ' ' . $cached : $query;
		}

		self::$did_expand = true;
		$body = wp_json_encode( [
			'model'      => 'claude-haiku-4-5-20251001',
			'max_tokens' => 80,
			'system'     => 'Eres un asistente de búsqueda especializado en educación deportiva y ciencias de la salud. Dado un mensaje de búsqueda en español, devolvé ÚNICAMENTE una lista de palabras clave y sinónimos relevantes separados por espacios, sin explicaciones ni puntuación. Incluí variantes ortográficas (voley/voleibol/volleyball), términos técnicos relacionados y palabras del dominio del deporte, salud y ejercicio físico.',
			'messages'   => [ [ 'role' => 'user', 'content' => $query ] ],
		] );

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 3,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body' => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return $query;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $query;
		}

		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$expanded = trim( $data['content'][0]['text'] ?? '' );
		set_transient( $cache_key, $expanded, 30 * DAY_IN_SECONDS );
		return $expanded ? $query . ' ' . $expanded : $query;
	}

	private static function stream_anthropic( string $key, string $context, array $messages, int $total = 0 ): string {
		// System as array: static instructions (cacheable) + dynamic formations context
		$system = [
			[
				'type'          => 'text',
				'text'          => self::system_prompt( $total ),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		];
		if ( $context ) {
			$system[] = [
				'type' => 'text',
				'text' => "FORMACIONES DISPONIBLES ACTUALMENTE (selección para esta consulta):\n" . $context,
			];
		}

		$body = wp_json_encode( [
			'model'      => 'claude-haiku-4-5-20251001',
			'max_tokens' => 1024,
			'stream'     => true,
			'system'     => $system,
			'messages'   => $messages,
		] );

		$sse_buf   = '';
		$full_text = '';
		$batch     = '';       // texto recibido aún no enviado al navegador
		$last_send = microtime( true );
		$hidden    = false;    // ya empezó la línea oculta <<IDS:…>>: no se reenvía

		$ch = curl_init( 'https://api.anthropic.com/v1/messages' );
		curl_setopt_array( $ch, [
			CURLOPT_POST          => true,
			CURLOPT_POSTFIELDS    => $body,
			CURLOPT_HTTPHEADER    => [
				'x-api-key: ' . $key,
				'anthropic-version: 2023-06-01',
				'anthropic-beta: prompt-caching-2024-07-31',
				'content-type: application/json',
			],
			CURLOPT_TIMEOUT       => 60,
			CURLOPT_WRITEFUNCTION => static function ( $ch, $raw ) use ( &$sse_buf, &$full_text, &$batch, &$last_send, &$hidden ): int {
				$sse_buf .= $raw;
				while ( ( $pos = strpos( $sse_buf, "\n\n" ) ) !== false ) {
					$block   = substr( $sse_buf, 0, $pos );
					$sse_buf = substr( $sse_buf, $pos + 2 );

					$payload = '';
					foreach ( explode( "\n", $block ) as $line ) {
						if ( str_starts_with( $line, 'data: ' ) ) {
							$payload = substr( $line, 6 );
						}
					}

					if ( ! $payload || $payload === '[DONE]' ) {
						continue;
					}

					$evt = json_decode( $payload, true );
					if (
						isset( $evt['type'], $evt['delta']['type'], $evt['delta']['text'] ) &&
						'content_block_delta' === $evt['type'] &&
						'text_delta' === $evt['delta']['type']
					) {
						$chunk      = $evt['delta']['text'];
						$full_text .= $chunk;
						if ( ! $hidden ) {
							$batch .= $chunk;
							$cut    = strpos( $batch, self::IDS_OPEN );
							if ( false !== $cut ) {
								$batch  = rtrim( substr( $batch, 0, $cut ), " \t\n\r|" );
								$hidden = true;
							}
						}
					}
				}

				// Si el final del texto puede ser el comienzo de la línea oculta
				// ("<", "<<", "<<ID"…), se retiene hasta el próximo fragmento.
				$keep = $hidden ? '' : self::partial_open( $batch );
				$send = substr( $batch, 0, strlen( $batch ) - strlen( $keep ) );
				if ( '' !== $send && microtime( true ) - $last_send >= self::SSE_BATCH_SEC ) {
					self::$t_first = self::$t_first ?: microtime( true );
					self::sse_flush( 'data: ' . wp_json_encode( [ 't' => $send ] ) . "\n\n" );
					$batch     = $keep;
					$last_send = microtime( true );
				}
				return strlen( $raw );
			},
		] );

		curl_exec( $ch );
		curl_close( $ch );

		if ( '' !== $batch ) {
			self::$t_first = self::$t_first ?: microtime( true );
			self::sse_data( [ 't' => $batch ] );
		}

		return $full_text;
	}

	/* CDN image URL with sizing params */
	private static function cdn_image( string $url, int $w = 640 ): string {
		if ( ! $url ) {
			return '';
		}
		$cdn = str_replace(
			'https://static1.onlineeducation.center/uploads/',
			'https://imgrsize.oe-img.center/',
			$url
		);
		return $cdn !== $url ? $cdn . '?w=' . $w . '&q=89' : $url;
	}

	/* Link de la formación que ve la IA: el de su ficha o, si es de otra
	 * comunidad, el de su sitio (oec_formation_url()). */
	private static function link( array $f ): string {
		return oec_formation_is_external( $f ) ? oec_formation_url( $f ) : (string) ( $f['url'] ?? '' );
	}

	/* Tarjeta de formación para el chat (ai-chat.js). url = el link que ve la
	 * IA (para reconocerlo en la respuesta); path = adónde lleva la tarjeta. */
	public static function card( array $f ): array {
		$external = oec_formation_is_external( $f );
		$host     = $external ? oec_community_host( $f['community'] ) : '';
		return [
			'id'        => $f['id'],
			'title'     => $f['title'],
			'url'       => self::link( $f ),
			'path'      => $external ? oec_formation_url( $f ) : self::formation_path( $f['url'] ),
			'image'     => self::cdn_image( $f['image'] ?? '' ),
			'type'      => $f['type'],
			'org'       => $f['org'],
			'external'  => $external,
			'community' => $host,
			'logo'      => $host ? oec_community_logo( $host ) : '',
		];
	}

	/* URL local absoluta para el link de formación: /formacion/<slug>,
	 * usando home_url() para que en multisite lleve el prefijo del sitio
	 * actual (/es/, /en/) en vez de una ruta relativa contra la raíz. */
	private static function formation_path( string $url ): string {
		if ( ! $url ) {
			return '';
		}
		$slug = basename( parse_url( $url, PHP_URL_PATH ) ?? '' );
		return $slug ? home_url( '/formacion/' . $slug ) : '';
	}

	/* Detect whether the user is asking about curriculum / module content */
	private static function asks_about_content( string $message ): bool {
		$lower    = mb_strtolower( $message );
		$keywords = [
			'contenido', 'temario', 'programa', 'módulo', 'modulo',
			'asignatura', 'qué incluye', 'que incluye', 'qué se ve', 'que se ve',
			'qué aprendo', 'que aprendo', 'qué enseña', 'que enseña',
			'qué cubre', 'que cubre', 'detalle', 'detalles', 'syllabus',
		];
		foreach ( $keywords as $kw ) {
			if ( str_contains( $lower, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sufijo de $text que es un comienzo incompleto de IDS_OPEN ('' si no hay). */
	private static function partial_open( string $text ): string {
		for ( $n = min( strlen( self::IDS_OPEN ) - 1, strlen( $text ) ); $n > 0; $n-- ) {
			$tail = substr( $text, -$n );
			if ( str_starts_with( self::IDS_OPEN, $tail ) ) {
				return $tail;
			}
		}
		return '';
	}

	/** Separa la línea oculta <<IDS:a,b>> del texto → [texto sin la línea, [ids]]. */
	private static function split_ids( string $text ): array {
		$cut = strpos( $text, self::IDS_OPEN );
		if ( false === $cut ) {
			return [ $text, [] ];
		}
		preg_match_all( '/t-[A-Za-z0-9]{14}/', substr( $text, $cut ), $m );
		return [ rtrim( substr( $text, 0, $cut ), " \t\n\r|" ), array_values( array_unique( $m[0] ) ) ];
	}

	/* Texto para comparar títulos: minúsculas, sin tildes, sin comillas ni signos. */
	private static function title_norm( string $text ): string {
		$text = remove_accents( mb_strtolower( $text ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	/*
	 * ¿La respuesta de la IA nombra esta formación? Tolera comillas rectas vs.
	 * tipográficas, tildes y mayúsculas, y que omita el subtítulo después de
	 * ":" (ej. 'Fisiología … "De la Teoría a la Práctica"' sin ": Del resultado
	 * de un test…"). El título principal solo vale si tiene 4+ palabras, para
	 * que un genérico como "Nutrición Deportiva: …" no dispare la tarjeta.
	 */
	private static function title_in_text( string $title, string $text ): bool {
		$t = self::title_norm( $title );
		$x = ' ' . self::title_norm( $text ) . ' ';
		if ( '' === $t ) {
			return false;
		}
		if ( str_contains( $x, ' ' . $t . ' ' ) ) {
			return true;
		}
		$main = self::title_norm( preg_split( '/\s*[:|]\s*|\s+[—–-]\s+/u', $title )[0] ?? '' );
		if ( $main !== $t && substr_count( $main, ' ' ) >= 3 && str_contains( $x, ' ' . $main . ' ' ) ) {
			return true;
		}
		// Coincidencia parcial: la mayoría de las palabras largas del título completo
		$significant = array_values( array_filter( explode( ' ', $t ), static fn( $w ) => strlen( $w ) > 4 ) );
		if ( count( $significant ) < 2 ) {
			return false;
		}
		// La última palabra larga suele ser el tema que diferencia ("voley", "natacion"):
		// se exige para que "Preparación Física en Voley" no coincida con "en Natación".
		if ( ! str_contains( $x, ' ' . end( $significant ) . ' ' ) ) {
			return false;
		}
		$found = array_filter( $significant, static fn( $w ) => str_contains( $x, ' ' . $w . ' ' ) );
		return count( $found ) / count( $significant ) >= 0.80;
	}

	/* ── Anthropic call ─────────────────────────────────────── */

	private static function call_anthropic( string $key, string $context, array $messages, int $total = 0 ): string|\WP_Error {
		// System as array: static instructions (cacheable) + dynamic formations context
		$system = [
			[
				'type'          => 'text',
				'text'          => self::system_prompt( $total ),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		];
		if ( $context ) {
			$system[] = [
				'type' => 'text',
				'text' => "FORMACIONES DISPONIBLES ACTUALMENTE (selección para esta consulta):\n" . $context,
			];
		}

		$body = wp_json_encode( [
			'model'      => 'claude-haiku-4-5-20251001',
			'max_tokens' => 2048,
			'system'     => $system,
			'messages'   => $messages,
		] );

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 30,
			'headers' => [
				'x-api-key'           => $key,
				'anthropic-version'   => '2023-06-01',
				'anthropic-beta'      => 'prompt-caching-2024-07-31',
				'content-type'        => 'application/json',
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['content'][0]['text'] ) ) {
			return new \WP_Error( 'anthropic_empty', 'Respuesta vacía de la IA.' );
		}

		return self::split_ids( $data['content'][0]['text'] )[0];
	}

	/* ── System prompt ──────────────────────────────────────── */

	private static function system_prompt( int $total_formations = 0 ): string {
		$site  = get_bloginfo( 'name' );
		$today = current_time( 'Y-m-d' );

		return <<<PROMPT
Eres un asesor académico de {$site}, especializado en formaciones de ciencias del deporte, salud y ejercicio físico. Tu rol es ayudar a los usuarios a encontrar la formación ideal para sus intereses, objetivos y perfil profesional.

Fecha de hoy: {$today}

ESTILO DE COMUNICACIÓN:
- Usá voseo rioplatense: "podés", "tenés", "necesitás" — nunca "usted" ni tuteo español
- Sé cálido y cercano, pero profesional y directo
- Respuestas concretas con información exacta del catálogo; nunca inventes datos

FORMATO DE RESPUESTA — MUY IMPORTANTE:
- Dividí tu respuesta en 2 o 3 mensajes cortos separados EXACTAMENTE por |||
- Cada mensaje debe ser breve y directo (no más de 4-5 líneas)
- NO uses headers markdown (##, ###) — el tono es conversacional, como un chat
- Usá negrita (**texto**) solo para nombres de cursos y datos clave
- Listas con guión (- ítem) cuando hay 3 o más opciones
- Ejemplo de formato correcto:
  "Hola! Encontré algunas opciones que se ajustan a lo que buscás.|||La más completa es el **Diplomado en Nutrición Deportiva** — abarca desde fundamentos hasta aplicación clínica en atletas de alto rendimiento.|||¿Querés que te cuente más sobre los docentes o el precio?"

COMPORTAMIENTO:
- Recomendá formaciones específicas justificando brevemente por qué se ajustan al perfil del usuario
- Cuando corresponda, sugerí una ruta: "primero X, luego Y"
- Usá opiniones de alumnos para reforzar recomendaciones cuando preguntan sobre calidad o docentes
- Si pregunta por contenidos específicos, respondé con los datos exactos del módulo
- Si pregunta por precios, mostralos con descuentos y formas de pago disponibles
- Si no hay formación que coincida exactamente, decilo con honestidad y ofrecé la alternativa más cercana
- Algunas formaciones son de otras comunidades de OEC (campo "Comunidad:", p. ej. swimming.science): recomendalas igual que las demás cuando encajen, aclarando en pocas palabras que se cursan en ese sitio, y usá su URL tal cual

REGLAS SEGÚN DATOS DEL CATÁLOGO — seguí estas reglas según los flags que figuran en cada formación:
- Campo "Relevance": indica la importancia de la formación para OEC. Valor 2 = formación MUY DESTACADA (priorizala en recomendaciones, resaltá sus puntos fuertes). Valor 1 = promedio (recomendalas normalmente). Valor 0 = relleno (mencionala SOLO si es la única opción disponible o el usuario pregunta específicamente; nunca la pongas primero).
- Sincronicidad ASYNC: NUNCA menciones fechas de inicio, fin ni de inscripción. Destacá que se puede "cursarlo a su ritmo", "empezarlo cuando quiera", "sin horarios fijos". Omití completamente el período y el enrollment_end.
- Flag docentes_destacados: hacé énfasis en el equipo docente — nombralos, mencioná sus cargos y trayectoria. Si el docente tiene URL de foto en sus datos (campo "Foto:"), mostrala usando markdown de imagen: ![Nombre del docente](url_foto). Las imágenes se renderizan correctamente en el chat.
- Flag tema_destacado: destacá la relevancia, actualidad e impacto del temario en el campo profesional.
- Flag certificacion_destacada: destacá el valor del certificado o aval para la carrera del alumno.
- Promedio de opiniones ≥ 4.5/5: citá una o dos reseñas reales de alumnos para respaldar la recomendación.

SOBRE EL CATÁLOGO:
- El catálogo completo tiene {$total_formations} formaciones en total.
- A continuación se muestran SOLO las más relevantes para esta consulta. No son todas — el sistema selecciona las más adecuadas por contexto.
- Si alguien pregunta cuántas formaciones hay, respondé con el número real: {$total_formations}.
- Nunca cuentes ni supongas el total a partir de las formaciones que ves aquí.

LÍNEA FINAL OBLIGATORIA (el usuario no la ve):
- Terminá SIEMPRE tu respuesta con una última línea, aparte, con los ID (campo "ID:") de las formaciones del contexto que nombraste o recomendaste, en el orden en que aparecen: <<IDS:t-xxxxxxxxxxxxxx,t-yyyyyyyyyyyyyy>>
- Si no nombraste ninguna, igual escribí la línea vacía: <<IDS:>>
- No pongas nada después de esa línea ni la menciones en el texto.

FORMACIONES CON INSCRIPCIÓN CERRADA — cuando aparezcan en el contexto bajo esa sección:
- Podés mencionar su existencia, docentes y temática si el usuario pregunta específicamente por esa formación o ese docente.
- NUNCA sugieras inscribirse, no menciones fechas, no la incluyas en recomendaciones activas.
- Si el usuario pregunta si puede anotarse, respondé que actualmente no tiene inscripción abierta y ofrecé las alternativas disponibles.
PROMPT;
	}
}

OEC_AI_Chat::init();
