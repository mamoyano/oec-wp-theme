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
	private static string $stop_reason = ''; // 'refusal' si el modelo rechazó la consulta

	/**
	 * Modelos que se pueden elegir en Ajustes → Chat IA (opción oec_ai_model).
	 * Haiku 5.5 razona antes de responder por defecto y eso demora el primer
	 * texto varios segundos: para el chat va con el razonamiento apagado (medido
	 * en local: primer texto como 4.5, respuesta completa en la mitad de tiempo
	 * y ~9 veces más barato). El registro guarda qué modelo respondió cada consulta.
	 */
	const MODELS = [
		'haiku-4-5' => [
			'label'  => 'Claude Haiku 4.5',
			'id'     => 'claude-haiku-4-5-20251001',
			'params' => [],
		],
		'haiku-5-5' => [
			'label'  => 'Claude Haiku 5.5',
			'id'     => 'claude-haiku-5-5',
			'params' => [ 'thinking' => [ 'type' => 'disabled' ], 'output_config' => [ 'effort' => 'low' ] ],
		],
	];
	const DEFAULT_MODEL = 'haiku-4-5';

	public static function model_key(): string {
		$key = (string) ( oec_get_options()['oec_ai_model'] ?? '' );
		return isset( self::MODELS[ $key ] ) ? $key : self::DEFAULT_MODEL;
	}

	/** Cuerpo del pedido a la API: modelo elegido + sus parámetros + lo propio de la llamada. */
	public static function request_body( array $body ): array {
		$m = self::MODELS[ self::model_key() ];
		return array_merge( [ 'model' => $m['id'] ], $m['params'], $body );
	}

	/** Texto de una respuesta no-streaming: los bloques de tipo "text" (el primero puede ser de razonamiento). */
	private static function response_text( $data ): string {
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'] ?? '';
			}
		}
		return $text;
	}

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
			// Página desde donde se chatea (ficha / landing de temática): ver page_context()
			'context'      => [ 'required' => false, 'type' => 'object', 'default' => [] ],
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
		// Sin coincidencias (un saludo, un tema que no está en el catálogo): no
		// se manda ninguna ficha. Antes iban de relleno las 10 con cierre más
		// cercano (~18.000 tokens por un "Hola"); ahora build_context() pone en
		// su lugar un resumen de las áreas del catálogo (catalog_overview()).
		return self::load_formations( self::rank( $index, $query, $extra, self::MAX_RESULTS ) );
	}

	/** Áreas del catálogo abierto con su cantidad de formaciones, en una línea. */
	private static function catalog_overview(): string {
		$labels = [
			'nutricion-deportiva' => 'Nutrición Deportiva',
			'fuerza'              => 'Entrenamiento de la Fuerza',
			'fisiologia'          => 'Fisiología del Ejercicio',
			'salud-ejercicio'     => 'Salud y Ejercicio',
			'deportes'            => 'Deportes',
			'futbol'              => 'Fútbol',
			'endurance'           => 'Endurance',
		];
		$counts = array_fill_keys( array_keys( $labels ), 0 );
		foreach ( OEC_AI_Catalog::get_index() ?: OEC_AI_Catalog::get_index( true ) as $e ) {
			foreach ( array_intersect( $e['tags'] ?? [], array_keys( $labels ) ) as $slug ) {
				$counts[ $slug ]++;
			}
		}
		$areas = [];
		foreach ( array_filter( $counts ) as $slug => $n ) {
			$areas[] = "{$labels[ $slug ]} ({$n})";
		}
		return "NINGUNA FORMACIÓN SELECCIONADA para esta consulta (es un saludo o no hubo coincidencias en el catálogo).\n"
			. ( $areas ? 'Áreas con formaciones abiertas: ' . implode( ', ', $areas ) . ".\n" : '' )
			. 'No nombres formaciones puntuales: preguntá qué busca la persona (tema, nivel, objetivo) o sugerí estas áreas. '
			. 'Si pidió un tema que no está entre ellas, decilo con honestidad y ofrecé la más cercana.';
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
		// Saludos y pedidos genéricos ("Buenas tardes, quisiera información"): no son un tema
		'buen', 'buenas', 'buenos', 'dia', 'dias', 'tarde', 'tardes', 'noche', 'noches', 'holaa', 'saludos',
		'quisiera', 'gustaria', 'podrian', 'podria', 'pueden', 'puede', 'favor', 'porfavor', 'porfa',
		'info', 'informacion', 'information', 'consulta', 'consultar', 'ayuda', 'interesa', 'interesado',
		'interesada', 'datos', 'detalles', 'todo', 'todos', 'aca', 'ahi', 'estoy', 'soy', 'ustedes',
		'brindar', 'brinden', 'enviar', 'envien', 'mandar', 'manden', 'pasar', 'pasen', 'dar', 'den',
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
		if ( ! $formations ) {
			$parts[] = self::catalog_overview();
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
		// La cantidad de módulos va escrita: si hay que contarla del temario, la IA
		// se equivoca (dijo 3 en un curso de 4 y después se "corrigió" sola).
		$n_mod   = count( $f['modules'] ?? [] );
		$lines[] = "Horas: {$f['lecture_hours']}h" . ( $n_mod ? " | Cantidad de módulos: {$n_mod}" : '' ) . " | Alumnos históricos: {$f['total_students']}";
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
			$lines[]  = "\nPrecio: {$cur} {$total}" . self::approx( (float) ( $price_data['price']['total'] ?? 0 ), $cur );

			if ( ! empty( $price_data['discount'] ) ) {
				$disc       = $price_data['discount'];
				$disc_total = number_format( $disc['total'] ?? 0, 0, ',', '.' );
				$disc_type  = $disc['type'] ?? '';
				$disc_label = $disc_type === 'early_payment' ? 'Descuento por pago anticipado' : 'Descuento por pago completo';

				if ( $disc_type === 'early_payment' && ! empty( $disc['expiration'] ) ) {
					$until    = self::format_date_human( substr( $disc['expiration'], 0, 10 ) );
					$lines[]  = "{$disc_label} {$disc['percentage']}% → {$cur} {$disc_total}" . self::approx( (float) ( $disc['total'] ?? 0 ), $cur ) . " (válido hasta {$until})";
				} else {
					$lines[]  = "{$disc_label} {$disc['percentage']}% → {$cur} {$disc_total}" . self::approx( (float) ( $disc['total'] ?? 0 ), $cur );
				}
			}

			if ( ! empty( $price_data['payment_methods'] ) ) {
				$methods = array_column( $price_data['payment_methods'], 'name' );
				$lines[] = "Formas de pago: " . implode( ', ', $methods );

				// Pago completo con descuento + cuotas de la tarjeta: sale mejor que ir
				// módulo por módulo sin descuento. Solo si hay un medio con cuotas.
				$cards = array_values( array_filter( $methods, static fn( $m ) => (bool) preg_match( '/pagotic|mercado\s*pago/i', (string) $m ) ) );
				$disc  = $price_data['discount'] ?? [];
				if ( $cards && ! empty( $disc['percentage'] ) && 'early_payment' !== ( $disc['type'] ?? '' ) && ! empty( $disc['total'] ) ) {
					$saving  = number_format( (float) ( $price_data['price']['total'] ?? 0 ) - (float) $disc['total'], 0, ',', '.' );
					$lines[] = "CONSEJO DE PAGO: lo más conveniente es pagar el curso completo con el {$disc['percentage']}% de descuento ({$cur} "
						. number_format( (float) $disc['total'], 0, ',', '.' ) . ", ahorra {$cur} {$saving}) y elegir cuotas con tarjeta en "
						. implode( ' o ', $cards ) . ": así paga en cuotas igual, pero sobre el precio con descuento. El costo exacto de las cuotas lo muestra el medio de pago antes de confirmar.";
				}
			}

			// Module 1 option for multi-module formations
			if ( count( $f['modules'] ?? [] ) > 1 ) {
				$m1_total = $price_data['first_module_price']['price']['total'] ?? null;
				if ( $m1_total ) {
					$m1_price = number_format( (float) $m1_total, 0, ',', '.' );
					$lines[]  = "Opción de inicio: también puede inscribirse SOLO al Módulo 1 por {$cur} {$m1_price}" . self::approx( (float) $m1_total, $cur );
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

	/**
	 * Mensajes para la IA. Cada turno del historial trae la página donde se
	 * escribió ("ctx": formacion:<id> · tematica:<slug> · catalogo) y solo van
	 * los de la página actual: los de otra ficha hablan de OTRA formación (otro
	 * precio, otras fechas) y la IA los tomaba como de esta — llegó a dar el
	 * precio de una formación en la ficha de otra y a "corregirse" sola. Las
	 * fichas anteriores no se pierden: viajan como alternativas con sus datos
	 * reales (visited_fichas()). Sin $ctx_key no se filtra nada.
	 */
	private static function build_messages( array $history, string $message, string $ctx_key = '' ): array {
		if ( '' !== $ctx_key ) {
			$history = array_values( array_filter(
				$history,
				static fn( $turn ) => (string) ( $turn['ctx'] ?? 'catalogo' ) === $ctx_key
			) );
		}
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

	/**
	 * Imagen de ayuda que acompaña la respuesta. La decide el servidor (no la
	 * IA) para que salga siempre y nunca una equivocada. Por ahora una sola:
	 * dónde se carga el código de descuento en el checkout (en el celular hay
	 * que desplegar el resumen, por eso hay dos versiones).
	 */
	private static function help_image( string $message, string $reply ): ?array {
		// Se decide por la respuesta: si explica dónde va el código (nombra el campo
		// y el "Resumen de compra" o el botón "Aplicar"), va la imagen. Mirar la
		// pregunta la dejaba afuera con "¿dónde lo pongo?" o "tengo un cupon".
		$asked = (bool) preg_match( '/c[oó]digo|cup[oó]n|promocional/iu', $message );
		$shown = preg_match( '/c[oó]digo de descuento/iu', $reply ) && preg_match( '/resumen de compra|aplicar/iu', $reply );
		if ( ! $shown && ! ( $asked && preg_match( '/checkout|c[oó]digo/iu', $reply ) ) ) {
			return null;
		}
		$base = OEC_THEME_URI . '/assets/img/chat/codigo-descuento-';
		$ver  = '?v=' . OEC_THEME_VERSION;
		return [
			'desktop' => $base . 'desktop.svg' . $ver,
			'mobile'  => $base . 'mobile.svg' . $ver,
			'alt'     => 'Dónde se escribe el código de descuento en el checkout',
		];
	}

	/** Monedas que cotiza la API de precios (las mismas del selector de país y moneda). */
	private const PRICE_CURRENCIES = [ 'EUR', 'USD', 'ARS', 'MXN', 'COP', 'CLP' ];

	/**
	 * Para qué país y en qué moneda están los precios del contexto, y qué hacer si
	 * la persona los quiere en otra: no se convierte a mano, se cambia la selección
	 * del sitio (el enlace REGION abre el selector; solo si el navegador lo tiene).
	 */
	private static function region_note( string $country, string $currency, bool $selector ): string {
		if ( ! $country ) {
			return '';
		}
		$name = self::country_label( $country );
		$how  = $selector
			? 'decile que puede cambiarlo y poné exactamente este enlace: [Cambiar país o moneda](REGION) (abre el selector; también está al pie de cada página, en "Ver precios: …"). Apenas lo cambie, le das el precio exacto.'
			: 'decile que puede cambiarlo con el selector "Ver precios: …" de la ficha de la formación.';
		$all  = implode( ', ', self::PRICE_CURRENCIES );
		$note = "\n\nPAÍS Y MONEDA: los precios del contexto son para {$name}, en {$currency}. Los precios y los medios de pago dependen del país.";
		if ( self::$local ) {
			// La moneda de su país no se cotiza: el servidor ya dejó el equivalente
			// aproximado al lado de cada precio (la IA no hace la cuenta).
			$l     = self::$local;
			$note .= " La moneda de {$name} ({$l['code']}, {$l['name']}) no está entre las que muestra el sitio, por eso el precio sale en {$currency}."
				. " Si pide el precio en {$l['name']} / {$l['code']} o \"en mi moneda\": dale el monto en {$currency} y, al lado, el equivalente que figura en el contexto como \"aprox. {$l['code']} …\""
				. " aclarando que es una referencia (tipo de cambio del {$l['date']}, puede variar)."
				. " Y contale que, aunque el checkout muestra el precio en {$currency}, según la forma de pago que elija puede pagar en su moneda local."
				. ' Usá solo esos equivalentes ya calculados: no hagas vos otras conversiones.';
		}
		// Países donde no se da equivalente (cambio oficial y paralelo muy distintos)
		$own = [ 'BO' => 'bolivianos', 'VE' => 'bolívares', 'CU' => 'pesos cubanos' ][ $country ] ?? '';
		if ( ! self::$local && $own ) {
			$note .= " CASO ESPECIAL — si pide el precio en {$own} o \"en mi moneda\": respondé SOLO esto, con tus palabras: el sitio muestra el precio en {$currency}; no tenés un tipo de cambio confiable para decirle un equivalente en {$own}; y según la forma de pago que elija puede pagar en su moneda local. En ese caso NO pongas el enlace de cambiar país o moneda (cambiarlo no le va a mostrar {$own}).";
		}
		return $note
			. " Si pide el precio en otra moneda" . ( $own ? " (que no sea {$own})" : ( self::$local ? ' que no sea esa' : '' ) ) . ", dice que está en otro país o que el país detectado no es el suyo: NO conviertas ni estimes montos (no tenés el tipo de cambio ni los precios de otro país); {$how}"
			. " Monedas que muestra el sitio: {$all}. No ofrezcas cambiar país o moneda si no lo pide.";
	}

	/** "Perú (PE)" — nombre del país en español si el servidor lo sabe; si no, el código. */
	private static function country_label( string $country ): string {
		$name = class_exists( '\\Locale' ) ? (string) \Locale::getDisplayRegion( '-' . $country, 'es' ) : '';
		return $name && $name !== $country ? "{$name} ({$country})" : $country;
	}

	/** ¿Se viene hablando de precio o moneda? (el mensaje lo nombra, o hace poco se ofreció cambiarla) */
	private static function talks_about_currency( string $message, array $history ): bool {
		if ( preg_match( '/precio|cu[aá]nto|sale|cuesta|vale|d[oó]lar|usd|euro|eur\b|peso|moneda|divisa|pa[ií]s|cambi|ya lo hice|listo|ya est[aá]/iu', $message ) ) {
			return true;
		}
		foreach ( array_slice( $history, -6 ) as $turn ) {
			if ( preg_match( '/oec-region|\(REGION\)|Cambiar pa[ií]s o moneda/iu', (string) ( $turn['content'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Moneda local del usuario cuando el sitio no la cotiza: [ code, name, rate, date, base ] o null. */
	private static ?array $local = null;

	/** Monedas locales que se nombran por su nombre; el resto sale del sistema (intl) solo con su código. */
	private const LOCAL_CURRENCIES = [
		'PE' => [ 'PEN', 'soles' ],            'CR' => [ 'CRC', 'colones' ],        'UY' => [ 'UYU', 'pesos uruguayos' ],
		'PY' => [ 'PYG', 'guaraníes' ],        'BR' => [ 'BRL', 'reales' ],
		'GT' => [ 'GTQ', 'quetzales' ],        'DO' => [ 'DOP', 'pesos dominicanos' ], 'HN' => [ 'HNL', 'lempiras' ],
		'NI' => [ 'NIO', 'córdobas' ],
	];

	/**
	 * Tipo de cambio de referencia para decir un precio en la moneda del país
	 * cuando el sitio no la cotiza (soles, colones…). Tabla pública de
	 * open.er-api.com, una vez cada 12 h para todo el sitio; si falla, no se
	 * ofrece equivalente. Sin Venezuela, Cuba ni Bolivia: tienen un cambio oficial y
	 * otro paralelo muy distintos, y el número daría una idea equivocada.
	 */
	private static function local_currency( string $country, string $currency ): ?array {
		if ( in_array( $country, [ 'VE', 'CU', 'BO' ], true ) ) {
			return null;
		}
		[ $code, $name ] = self::LOCAL_CURRENCIES[ $country ] ?? [ '', '' ];
		if ( ! $code && class_exists( '\\NumberFormatter' ) ) {
			$code = (string) ( new \NumberFormatter( 'es_' . $country, \NumberFormatter::CURRENCY ) )->getTextAttribute( \NumberFormatter::CURRENCY_CODE );
			$name = $code;
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || $code === $currency || in_array( $code, self::PRICE_CURRENCIES, true ) || 'XXX' === $code ) {
			return null;
		}
		$fx = get_transient( 'oec_chat_fx' );
		if ( ! is_array( $fx ) ) {
			$res  = wp_remote_get( 'https://open.er-api.com/v6/latest/USD', [ 'timeout' => 3 ] );
			$data = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
			if ( ! is_array( $data ) || 'success' !== ( $data['result'] ?? '' ) || empty( $data['rates'] ) ) {
				set_transient( 'oec_chat_fx', [ 'rates' => [] ], 30 * MINUTE_IN_SECONDS );
				return null;
			}
			$fx = [ 'rates' => $data['rates'], 'time' => (int) ( $data['time_last_update_unix'] ?? time() ) ];
			set_transient( 'oec_chat_fx', $fx, 12 * HOUR_IN_SECONDS );
		}
		$to   = (float) ( $fx['rates'][ $code ] ?? 0 );
		$from = (float) ( $fx['rates'][ $currency ] ?? 0 );
		if ( $to <= 0 || $from <= 0 ) {
			return null;
		}
		return [ 'code' => $code, 'name' => $name, 'rate' => $to / $from, 'base' => $currency, 'date' => wp_date( 'j \\d\\e F', $fx['time'] ?? time() ) ];
	}

	/** " (aprox. PEN 1.370)" al lado de un precio, si hay moneda local de referencia. */
	private static function approx( float $amount, string $cur ): string {
		if ( ! self::$local || $amount <= 0 || $cur !== self::$local['base'] ) {
			return '';
		}
		$v = $amount * self::$local['rate'];
		// Redondeo "de referencia": 2 cifras menos que el número, para que no parezca exacto
		$step = $v >= 100000 ? 1000 : ( $v >= 10000 ? 100 : ( $v >= 1000 ? 10 : 1 ) );
		return ' (aprox. ' . self::$local['code'] . ' ' . number_format( round( $v / $step ) * $step, 0, ',', '.' ) . ')';
	}

	/** ¿El mensaje habla de una ficha que vio antes? ("las que vi", "la anterior", "compará") */
	private static function refers_to_seen( string $message ): bool {
		return (bool) preg_match( '/(?<![\p{L}])(vi|ví|viste|vimos|mir[eé]|miraba|anterior(es)?|las? otras?|el otro|fichas?|esas|esa otra|compar\p{L}+)(?![\p{L}])/iu', $message );
	}

	/** ¿Va la lista de derivaciones a una persona en esta consulta? (ver wants_human()) */
	private static bool $derive = true;

	/** ¿El mensaje plantea un problema o pide contacto humano? (habilita ofrecer WhatsApp) */
	private static function wants_human( string $message ): bool {
		return (bool) preg_match(
			'/error|no puedo|no pude|no (me |lo |la |me lo |me la |se )*(toma|acepta|reconoce|sirve|funcion|anda|carga|deja|aplica|valida|pasa)|no me deja|no me dej|no funciona|no anda|no carga|falla|fall[oó]|rechaz|inv[aá]lid|no es v[aá]lid|no (se )?aplica|problema|reclamo|queja|devoluci|reembols|darme de baja|whats|persona|humano|alguien|asesor|vendedor|hablar|llamar|tel[eé]fono|contact|ya pagu|no recib|no me lleg|empresa|instituci|convenio|factura/iu',
			$message
		);
	}

	private static function ctx_key( array $ctx ): string {
		if ( 'formacion' === $ctx['mode'] ) {
			return 'formacion:' . $ctx['id'];
		}
		return 'tematica' === $ctx['mode'] ? 'tematica:' . $ctx['tematica'] : 'catalogo';
	}

	/** Fichas que la persona miró antes en esta conversación (las 3 más recientes, sin la actual). */
	private static function visited_fichas( array $history, string $current_id = '' ): array {
		$ids = [];
		foreach ( array_reverse( $history ) as $turn ) {
			if ( preg_match( '/^formacion:(t-[A-Za-z0-9]{14})$/', (string) ( $turn['ctx'] ?? '' ), $m )
				&& $m[1] !== $current_id && ! in_array( $m[1], $ids, true ) ) {
				$ids[] = $m[1];
				if ( count( $ids ) >= 3 ) {
					break;
				}
			}
		}
		return $ids;
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
		// País y moneda vienen del selector del sitio (o de la detección por IP). La
		// API de precios solo cotiza PRICE_CURRENCIES: con otra moneda, USD.
		$country  = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
		$currency = $country ? ( in_array( $currency, self::PRICE_CURRENCIES, true ) ? $currency : 'USD' ) : '';
		self::$local = $country ? self::local_currency( $country, $currency ) : null;

		// Rol según la página: ficha de formación, landing de temática o catálogo
		$ctx   = self::resolve_context( $request->get_param( 'context' ) );
		$focus = 'formacion' === $ctx['mode'] ? self::focus_formation( $ctx['id'] ) : null;
		if ( $focus ) {
			$focus['user_country'] = (string) $country;
		}
		$open  = true;
		$closed = [];
		$others = [];
		$visited = self::visited_fichas( (array) $history, $focus['id'] ?? '' );
		// Fuera de una ficha, las ya vistas solo entran si el mensaje habla de ellas
		// ("las que vi", "la anterior", "compará"): si no, la IA las recomendaba de
		// más. Y cuando habla de ellas van solas, sin relleno del buscador.
		$recall = $visited && ! $focus && self::refers_to_seen( (string) $message );
		if ( ! $focus && ! $recall ) {
			$visited = [];
		}

		if ( $focus ) {
			// Ficha: va siempre esa formación completa + hasta 3 alternativas. Sin
			// ampliación con IA: no hay que buscar, la formación ya está elegida.
			self::sse_status( 'Revisando esta formación...' );
			$index  = OEC_AI_Catalog::get_index( true );
			$open   = in_array( $focus['id'], array_column( $index, 'id' ), true ) && self::enrollment_open( $focus );
			// Alternativas: lo que el usuario nombra (si la palabra está en el título o
			// los docentes: "inscripción" o "abrir" coinciden por azar con cualquier
			// ficha) y, después, las más parecidas a esta formación.
			$asked = array_filter(
				self::rank( $index, $message, '', self::MAX_RESULTS ),
				static fn( $e ) => self::names_entry( $message, $e )
			);
			$similar = self::rank( $index, $focus['title'] . ' ' . implode( ' ', $focus['tematicas'] ?? [] ), '', self::MAX_RESULTS );
			$picked  = [];
			foreach ( $focus['parents'] as $p ) {
				$picked[ $p['id'] ] = [ 'id' => $p['id'] ]; // la incluyen: van con sus datos y precio reales
			}
			foreach ( $visited as $vid ) {
				$picked[ $vid ] = [ 'id' => $vid ]; // fichas que miró antes: para comparar con datos reales
			}
			foreach ( array_merge( array_slice( array_values( $asked ), 0, 2 ), $similar ) as $e ) {
				if ( $e['id'] !== $focus['id'] && count( $picked ) < max( 3, count( $visited ) + count( $focus['parents'] ) + 1 ) ) {
					$picked[ $e['id'] ] ??= $e;
				}
			}
			$others     = self::load_formations( array_values( $picked ) );
			$formations = array_merge( [ $focus ], $others );
		} else {
			self::sse_status( 'Analizando tu consulta...' );
			$expanded = self::expand_query( $api_key, $message );

			self::sse_status( 'Revisando el catálogo de formaciones...' );
			$extra      = trim( substr( $expanded, strlen( $message ) ) ); // solo la ampliación de la IA
			$formations = 'tematica' === $ctx['mode']
				? self::prefilter_tematica( $message, $extra, $ctx['tematica'] )
				: self::prefilter( $message, $extra );
			$formations = $recall
				? self::merge_mentioned( [], $visited )
				: self::merge_mentioned( $formations, (array) $mentioned_ids );
			$closed     = self::prefilter_closed( $message, $formations, $extra );
		}

		$prices = [];
		if ( $country && $currency && ! empty( $formations ) ) {
			self::sse_status( 'Consultando precios actualizados...' );
			$prices = self::fetch_prices( array_column( $formations, 'id' ), $country, $currency );
		}

		self::sse_status( 'Preparando la respuesta...' );
		$total            = count( OEC_AI_Catalog::get_index( true ) );
		$detailed_modules = self::asks_about_content( $message );
		if ( $focus ) {
			$context = "FORMACIÓN DE ESTA PÁGINA:\n" . self::format_focus( $focus, $prices[ $focus['id'] ] ?? null, $open );
			if ( $others ) {
				$context .= "\n\n---\n\nOTRAS FORMACIONES (alternativas: ofrecelas solo si la de esta página no es lo que busca o está cerrada):\n\n"
					. self::build_context( $others, $prices, false, [] );
			}
		} else {
			// Las fichas que ya miró van aparte y primero: mezcladas con el resto,
			// "las que vi" terminaba siendo cualquier formación del contexto.
			$seen_f = array_values( array_filter( $formations, static fn( $f ) => in_array( $f['id'], $visited, true ) ) );
			$rest_f = array_values( array_filter( $formations, static fn( $f ) => ! in_array( $f['id'], $visited, true ) ) );
			$context = $seen_f
				? "FICHAS QUE LA PERSONA YA MIRÓ EN ESTA CONVERSACIÓN:\n\n" . self::build_context( $seen_f, $prices, false, [] )
					. ( $rest_f ? "\n\n---\n\nRESTO DEL CATÁLOGO PARA ESTA CONSULTA:\n\n" . self::build_context( $rest_f, $prices, $detailed_modules, $closed ) : '' )
				: self::build_context( $formations, $prices, $detailed_modules, $closed );
		}
		$messages = self::build_messages( (array) $history, $message, self::ctx_key( $ctx ) );

		$by_id_all = array_column( $formations, 'title', 'id' );
		$seen      = array_values( array_filter( array_map( static fn( $id ) => $by_id_all[ $id ] ?? '', $visited ) ) );
		$mode = self::mode_prompt( $ctx, $open, $seen, $focus['parents'] ?? [] );
		// El contacto por WhatsApp no se ofrece "por las dudas". Con la regla sola la
		// IA lo agregaba igual ("si te da error, escribinos…"), así que la lista de
		// derivaciones solo viaja cuando la persona plantea un problema o pide contacto.
		self::$derive = self::wants_human( (string) $message );
		$mode .= self::region_note( $country, $currency, (bool) $request->get_param( 'region' ) );
		// Tras cambiar la moneda, la IA seguía diciendo "no tengo el precio en dólares"
		// (lo había dicho ella misma dos mensajes antes, con la selección anterior). Si
		// se venía hablando de moneda o precio, la selección vigente va pegada a la pregunta.
		if ( $country && self::talks_about_currency( (string) $message, (array) $history ) ) {
			$last = count( $messages ) - 1;
			$messages[ $last ]['content'] .= "\n\n[Dato del sitio, no lo escribió la persona: la selección vigente AHORA es " . self::country_label( $country ) . " · {$currency}."
				. " Los precios del contexto ya están en {$currency} (los mensajes anteriores pueden ser de otra selección: no valen)."
				. " Si pregunta el precio, dice que ya cambió la moneda o había pedido verlo en {$currency}: dale ahora el precio en {$currency} con los números del contexto. No digas que no lo tenés ni lo mandes a mirarlo en la ficha.]";
		}
		// "¿Es más barata que la anterior?": a veces respondía que no sabía qué ficha
		// había visto, porque en los mensajes solo ve los de esta página. Si habla de
		// otra ficha, el dato va pegado a su propia pregunta (en las reglas no alcanzaba).
		if ( $seen && self::refers_to_seen( (string) $message ) ) {
			$note = "[Dato del sitio, no lo escribió la persona: antes de esta página miró la ficha de «{$seen[0]}» (esa es \"la anterior\")"
				. ( isset( $seen[1] ) ? ', y antes ' . implode( ' y ', array_map( static fn( $t ) => "«{$t}»", array_slice( $seen, 1 ) ) ) : '' )
				. '. Sus precios y datos están en el contexto: compará con esos números y respondé directo.]';
			$last = count( $messages ) - 1;
			$messages[ $last ]['content'] .= "\n\n" . $note;
		}
		$full_reply = self::stream_anthropic( $api_key, $context, $messages, $total, $mode );
		[ $reply, $ids ] = self::split_ids( $full_reply );

		// Sin texto (falla de la API, o el modelo rechazó la consulta): el usuario
		// recibe un mensaje en vez de quedarse mirando una pantalla vacía.
		$error = '';
		if ( '' === trim( $reply ) ) {
			$error = 'refusal' === self::$stop_reason ? 'Consulta rechazada por el modelo' : 'Sin respuesta de la IA';
			self::sse_data( [ 't' => 'No pude responder esa consulta. ¿Podés escribirla de otra forma?' ] );
		}

		// Tarjetas: las formaciones que la IA declaró en <<IDS:…>> (en ese
		// orden) y, como respaldo por si omitió la línea, las que nombra por título.
		$by_id  = array_column( $formations, null, 'id' );
		$source = [];
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$source[ $id ] = $by_id[ $id ];
			}
		}
		// En una ficha no hay respaldo por título: al describir la formación de la
		// página, palabras como "trail running" coincidían con el título de una
		// alternativa y aparecía la tarjeta de un curso que la IA nunca nombró.
		foreach ( $focus ? [] : $formations as $f ) {
			if ( ! isset( $source[ $f['id'] ] ) && self::title_in_text( $f['title'], $reply ) ) {
				$source[ $f['id'] ] = $f;
			}
		}
		if ( $focus ) {
			unset( $source[ $focus['id'] ] ); // ya está en esa página
		}
		$source = array_values( $source );

		$cards = array_map( [ __CLASS__, 'card' ], $source );

		$done = [ 'done' => true, 'formations' => $cards ];
		if ( $help = self::help_image( (string) $message, $reply ) ) {
			$done['help'] = $help;
		}
		self::sse_data( $done );

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
				'error'      => $error,
				'model'      => self::model_key(),
				'ctx'        => 'formacion' === $ctx['mode'] ? 'formacion:' . $ctx['id'] : ( 'tematica' === $ctx['mode'] ? 'tematica:' . $ctx['tematica'] : '' ),
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

		// Solo saludos y cortesías ("Buenas tardes, quisiera información"): sin
		// ninguna palabra de búsqueda no hay tema que ampliar.
		$topic = array_diff(
			array_filter( explode( ' ', self::title_norm( $query ) ), static fn( $w ) => strlen( $w ) >= 3 ),
			self::SEARCH_STOPWORDS
		);
		if ( ! $topic ) {
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
		$body = wp_json_encode( self::request_body( [
			'max_tokens' => 80,
			'system'     => 'Eres un asistente de búsqueda especializado en educación deportiva y ciencias de la salud. Dado un mensaje de búsqueda en español, devolvé ÚNICAMENTE una lista de palabras clave y sinónimos relevantes separados por espacios, sin explicaciones ni puntuación. Incluí variantes ortográficas (voley/voleibol/volleyball), términos técnicos relacionados y palabras del dominio del deporte, salud y ejercicio físico.',
			'messages'   => [ [ 'role' => 'user', 'content' => $query ] ],
		] ) );

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
		$expanded = trim( self::response_text( $data ) );
		set_transient( $cache_key, $expanded, 30 * DAY_IN_SECONDS );
		return $expanded ? $query . ' ' . $expanded : $query;
	}

	/**
	 * Bloques de "system": instrucciones + conocimiento de ventas (iguales en
	 * todas las consultas → se cachean) y, aparte, el rol de esta página y las
	 * formaciones elegidas para esta consulta.
	 */
	private static function system_blocks( string $context, int $total, string $mode = '' ): array {
		$system = [
			[
				'type'          => 'text',
				'text'          => self::system_prompt( $total ) . "\n\n" . self::sales_knowledge(),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		];
		$dynamic = trim( $mode . "\n\n" . ( $context ? "FORMACIONES DISPONIBLES ACTUALMENTE (selección para esta consulta):\n" . $context : '' ) );
		if ( '' !== $dynamic ) {
			$system[] = [ 'type' => 'text', 'text' => $dynamic ];
		}
		return $system;
	}

	private static function stream_anthropic( string $key, string $context, array $messages, int $total = 0, string $mode = '' ): string {
		$system = self::system_blocks( $context, $total, $mode );

		$body = wp_json_encode( self::request_body( [
			'max_tokens' => 1024,
			'stream'     => true,
			'system'     => $system,
			'messages'   => $messages,
		] ) );

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
					if ( 'message_delta' === ( $evt['type'] ?? '' ) && ! empty( $evt['delta']['stop_reason'] ) ) {
						self::$stop_reason = (string) $evt['delta']['stop_reason'];
					}
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
		$system = self::system_blocks( $context, $total );

		$body = wp_json_encode( self::request_body( [
			'max_tokens' => 2048,
			'system'     => $system,
			'messages'   => $messages,
		] ) );

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

		$text = self::response_text( $data );
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'anthropic_empty', 'Respuesta vacía de la IA.' );
		}

		return self::split_ids( $text )[0];
	}

	/* ══════════════════════════════════════════════════════════
	   MODO VENDEDOR
	   El mismo chat cambia de rol según la página donde se abre:
	     · ficha de formación  → asesor de ESA formación
	     · landing de temática → asesor de las formaciones de esa área
	     · resto del sitio     → recomendador del catálogo (como siempre)
	   La página se detecta en PHP (page_context) y viaja al navegador en
	   la config del script; cada mensaje la devuelve en "context" y el
	   servidor la valida (request_context) antes de usarla.
	   ══════════════════════════════════════════════════════════ */

	/** Contexto de la página que se está generando (front). */
	public static function page_context(): array {
		static $ctx = null;
		if ( null !== $ctx ) {
			return $ctx;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore

		// Ficha de formación: /formacion/<slug>-t-XXXXXXXXXXXXXX (la arma el plugin)
		if ( preg_match( '#/formacion/[^/?]*?(t-[A-Za-z0-9]{14})/?$#', $path, $m ) ) {
			$ctx = self::resolve_context( [ 'mode' => 'formacion', 'id' => $m[1] ] );
			if ( 'formacion' === $ctx['mode'] ) {
				return $ctx;
			}
		}
		// Landing de temática
		if ( function_exists( 'oec_especiales_registradas' ) ) {
			foreach ( oec_especiales_registradas() as $e ) {
				$landing = (string) wp_parse_url( (string) ( $e['url'] ?? '' ), PHP_URL_PATH );
				if ( $landing && untrailingslashit( $landing ) === untrailingslashit( $path ) ) {
					return $ctx = self::resolve_context( [ 'mode' => 'tematica', 'tematica' => $e['tematica'] ?? '' ] );
				}
			}
		}
		return $ctx = [ 'mode' => 'catalogo' ];
	}

	/** Valida un contexto (de la página o enviado por el navegador) y le pone el título real. */
	private static function resolve_context( $raw ): array {
		$mode = is_array( $raw ) ? (string) ( $raw['mode'] ?? '' ) : '';
		if ( 'formacion' === $mode ) {
			$id = (string) ( $raw['id'] ?? '' );
			$f  = preg_match( '/^t-[A-Za-z0-9]{14}$/', $id ) ? OEC_AI_Catalog::get_formation( $id ) : null;
			if ( $f && ! empty( $f['title'] ) ) {
				return [ 'mode' => 'formacion', 'id' => $id, 'title' => (string) $f['title'] ];
			}
		}
		if ( 'tematica' === $mode && function_exists( 'oec_especiales_registradas' ) ) {
			foreach ( oec_especiales_registradas() as $e ) {
				if ( ( $e['tematica'] ?? '' ) === ( $raw['tematica'] ?? null ) ) {
					return [ 'mode' => 'tematica', 'tematica' => (string) $e['tematica'], 'title' => (string) $e['title'] ];
				}
			}
		}
		return [ 'mode' => 'catalogo' ];
	}

	/** Placeholder del campo del header según la página. */
	public static function placeholder(): string {
		$ctx = self::page_context();
		if ( 'catalogo' === $ctx['mode'] ) {
			return __( '¿Qué quieres aprender?', 'oec-theme' );
		}
		$title = $ctx['title'];
		if ( mb_strlen( $title ) > 60 ) {
			$title = rtrim( mb_substr( $title, 0, 58 ) ) . '…';
		}
		/* translators: %s: nombre de la formación o de la temática */
		return sprintf( __( '¿Qué te respondo sobre %s?', 'oec-theme' ), $title );
	}

	/** Config para ai-chat.js: contexto de la página y enlaces que el chat escribe como [texto](CLAVE). */
	public static function script_config(): array {
		$ctx   = self::page_context();
		$links = array_filter( [ 'WHATSAPP' => self::whatsapp_url() ] );
		if ( 'formacion' === $ctx['mode'] ) {
			$focus = self::focus_base( $ctx['id'] ); // sin clases del auditorio: eso se pide recién al chatear
			if ( ! empty( $focus['register_url'] ) ) {
				$links['INSCRIPCION'] = esc_url_raw( $focus['register_url'] );
			}
		}
		// El título va aparte: es para el rótulo "Hablando sobre…" del historial;
		// "context" vuelve al servidor tal cual y ahí se valida de nuevo.
		$title = (string) ( $ctx['title'] ?? '' );
		unset( $ctx['title'] );
		return [ 'context' => $ctx, 'contextTitle' => $title, 'links' => (object) $links ];
	}

	private static function whatsapp_url(): string {
		$url = trim( (string) ( oec_get_options()['oec_chat_whatsapp'] ?? '' ) );
		return $url ?: ( defined( 'OEC_NL_WHATSAPP' ) ? OEC_NL_WHATSAPP : '' );
	}

	/**
	 * Ficha en foco, con los campos del vendedor (cómo se cursa, exámenes,
	 * requisitos, link de inscripción, detalle de certificados). El catálogo
	 * los guarda desde v1.0.129; a las fichas bajadas antes se les completan
	 * con la copia que el plugin de formaciones deja en caché al mostrar la
	 * ficha (opción oec_cache_<md5("trainings/ID")>), sin consultar la API.
	 */
	private static function focus_formation( string $id ): ?array {
		$f = self::focus_base( $id );
		if ( ! $f ) {
			return null;
		}
		$f['parents']  = self::focus_parents( $id );
		$f['sessions'] = self::focus_sessions( (string) ( $f['edition_uid'] ?? '' ) );
		return $f;
	}

	/**
	 * Formaciones más grandes que incluyen a esta ("Esta formación está incluida
	 * en…"). El plugin ya lo pide al mostrar la ficha y lo guarda 24 h: se lee
	 * de ahí, aunque esté vencido (cambia muy poco), sin sumarle pedidos a oas-api.
	 */
	private static function focus_parents( string $id ): array {
		$cached = get_option( 'oec_cache_' . md5( "trainings/{$id}/parents" ) );
		$out    = [];
		foreach ( is_array( $cached ) && is_array( $cached['data'] ?? null ) ? $cached['data'] : [] as $p ) {
			if ( ! empty( $p['title'] ) && preg_match( '/^t-[A-Za-z0-9]{14}$/', (string) ( $p['uid'] ?? '' ) ) ) {
				$out[] = [ 'id' => $p['uid'], 'title' => (string) $p['title'] ];
			}
		}
		return $out;
	}

	/**
	 * Próximas clases en vivo ya programadas en el auditorio virtual (lo mismo
	 * que anuncia la ficha). Es la API del campus, no oas-api; un pedido por
	 * edición por hora como mucho, y si falla no se reintenta por 10 minutos.
	 */
	private static function focus_sessions( string $edition_uid ): array {
		if ( ! preg_match( '/^te-[A-Za-z0-9]{14}$/', $edition_uid ) ) {
			return [];
		}
		$key    = 'oec_chat_ses_' . $edition_uid;
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			$base = apply_filters( 'oec_chat_campus_api', 'https://g-se.onlineeducation.center/campus/api' );
			$res  = wp_remote_get( "{$base}/editions/{$edition_uid}/auditorium-sessions", [ 'timeout' => 3, 'headers' => [ 'Accept' => 'application/json' ] ] );
			$data = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
			if ( ! is_array( $data ) ) {
				set_transient( $key, [], 10 * MINUTE_IN_SECONDS );
				return [];
			}
			$cached = [];
			foreach ( $data['sessions'] ?? [] as $s ) {
				$start = strtotime( (string) ( $s['acSesion']['inicio'] ?? '' ) );
				if ( $start && ! empty( $s['nombre'] ) ) {
					$cached[] = [
						'start'   => $start,
						'name'    => sanitize_text_field( $s['nombre'] ),
						'minutes' => (int) ( $s['acSesion']['minutosSesion'] ?? 0 ),
						'module'  => (int) ( $s['asignatura']['modulo']['numero'] ?? 0 ),
					];
				}
			}
			usort( $cached, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
			set_transient( $key, $cached, HOUR_IN_SECONDS );
		}
		return array_slice( array_values( array_filter( $cached, static fn( $s ) => $s['start'] > time() ) ), 0, 8 );
	}

	/** Huso del país del usuario, solo donde hay uno claro: [ DateTimeZone, nombre del país ]. */
	private static function country_timezone( string $country ): ?array {
		$map = [
			'MX' => [ 'America/Mexico_City', 'México (hora del centro)' ], 'ES' => [ 'Europe/Madrid', 'España (península)' ],
			'CL' => [ 'America/Santiago', 'Chile' ],        'CO' => [ 'America/Bogota', 'Colombia' ],
			'PE' => [ 'America/Lima', 'Perú' ],             'EC' => [ 'America/Guayaquil', 'Ecuador' ],
			'UY' => [ 'America/Montevideo', 'Uruguay' ],    'PY' => [ 'America/Asuncion', 'Paraguay' ],
			'BO' => [ 'America/La_Paz', 'Bolivia' ],        'VE' => [ 'America/Caracas', 'Venezuela' ],
			'CR' => [ 'America/Costa_Rica', 'Costa Rica' ], 'GT' => [ 'America/Guatemala', 'Guatemala' ],
			'PA' => [ 'America/Panama', 'Panamá' ],         'DO' => [ 'America/Santo_Domingo', 'República Dominicana' ],
			'SV' => [ 'America/El_Salvador', 'El Salvador' ], 'HN' => [ 'America/Tegucigalpa', 'Honduras' ],
			'NI' => [ 'America/Managua', 'Nicaragua' ],     'PR' => [ 'America/Puerto_Rico', 'Puerto Rico' ],
			'CU' => [ 'America/Havana', 'Cuba' ],           'PT' => [ 'Europe/Lisbon', 'Portugal' ],
		];
		if ( ! isset( $map[ $country ] ) ) {
			return null; // Argentina (ya es la hora base) o país con varios husos / sin datos
		}
		try {
			return [ new \DateTimeZone( $map[ $country ][0] ), $map[ $country ][1] ];
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function focus_base( string $id ): ?array {
		$f = OEC_AI_Catalog::get_formation( $id );
		if ( ! $f || array_key_exists( 'methodology', $f ) ) {
			return $f;
		}
		$cached = get_option( 'oec_cache_' . md5( "trainings/{$id}" ) );
		$d      = is_array( $cached ) ? ( $cached['data'] ?? null ) : null;
		if ( ! is_array( $d ) ) {
			return $f;
		}
		$f['edition_uid']       = (string) ( $d['edition_uid'] ?? '' );
		$prose = static fn( $html ) => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '/<(br|\/p|\/li|\/div|\/h\d)\b[^>]*>/i', ' ', (string) $html ) ) ) );
		$f['methodology']       = $prose( $d['methodology'] ?? '' );
		$f['exams_description'] = $prose( $d['exams_description'] ?? '' );
		$f['requirements']      = $prose( $d['requirements'] ?? '' );
		$f['register_url']      = (string) ( $d['register_url'] ?? '' );
		$certs = [];
		foreach ( $d['certificates']['data'] ?? [] as $c ) {
			$certs[] = [
				'name'        => (string) ( $c['name'] ?? '' ),
				'type'        => (string) ( $c['type'] ?? '' ),
				'description' => $prose( $c['description'] ?? '' ),
				'cecs'        => (float) ( $c['cecs'] ?? 0 ),
			];
		}
		if ( $certs ) {
			$f['certificates'] = $certs;
		}
		return $f;
	}

	/** Bloque de contexto de la ficha en foco: la ficha completa + lo que solo usa el vendedor. */
	private static function format_focus( array $f, ?array $price, bool $open ): string {
		$lines   = [ self::format_formation( $f, $price, true ) ];
		$lines[] = '';
		$lines[] = $open
			? 'ESTADO: inscripción ABIERTA.'
			: 'ESTADO: INSCRIPCIÓN CERRADA. No se puede inscribir ahora y no hay fecha de una próxima edición (la decide el organizador).';
		if ( ! empty( $f['methodology'] ) ) {
			$lines[] = 'Cómo se cursa (días, horarios, clases): ' . mb_substr( $f['methodology'], 0, 900 );
		}
		if ( ! empty( $f['exams_description'] ) ) {
			$lines[] = 'Exámenes: ' . mb_substr( $f['exams_description'], 0, 600 );
		}
		if ( ! empty( $f['requirements'] ) ) {
			$lines[] = 'Requisitos: ' . mb_substr( $f['requirements'], 0, 400 );
		}
		$certs = array_filter( $f['certificates'] ?? [], static fn( $c ) => ! empty( $c['description'] ) || ! empty( $c['cecs'] ) );
		if ( $certs ) {
			$lines[] = 'Detalle de certificados:';
			foreach ( $f['certificates'] as $c ) {
				$meta = array_filter( [
					! empty( $c['type'] ) ? 'formato ' . $c['type'] : '',
					! empty( $c['cecs'] ) ? rtrim( rtrim( number_format( (float) $c['cecs'], 2, ',', '' ), '0' ), ',' ) . ' créditos de educación continua' : '',
				] );
				$lines[] = '- ' . $c['name'] . ( $meta ? ' (' . implode( ', ', $meta ) . ')' : '' )
					. ( ! empty( $c['description'] ) ? ': ' . mb_substr( $c['description'], 0, 280 ) : '' );
			}
		}
		$year = substr( (string) ( $f['publish_start'] ?? '' ), 0, 4 );
		if ( $year ) {
			$lines[] = "Esta edición se publicó en {$year}.";
		}
		if ( ! empty( $f['sessions'] ) ) {
			// La hora del país del usuario la calcula el servidor: la IA se equivoca
			// al convertir husos (dio 12:00 para México cuando eran las 11:00).
			$local   = self::country_timezone( (string) ( $f['user_country'] ?? '' ) );
			$lines[] = '';
			$lines[] = 'PRÓXIMAS CLASES EN VIVO ya programadas en el auditorio virtual (hora de Argentina, GMT-3'
				. ( $local ? '; entre paréntesis, la hora en ' . $local[1] : '' ) . '; quedan grabadas):';
			foreach ( $f['sessions'] as $s ) {
				$lines[] = '- ' . wp_date( 'l j \\d\\e F, H:i', $s['start'] ) . ' hs de Argentina'
					. ( $local ? ' (' . wp_date( 'H:i', $s['start'], $local[0] ) . ' hs en ' . $local[1] . ')' : '' )
					. ': "' . $s['name'] . '"'
					. ( $s['minutes'] ? " ({$s['minutes']} min" . ( $s['module'] ? ", módulo {$s['module']}" : '' ) . ')' : '' );
			}
		}
		if ( count( $f['modules'] ?? [] ) <= 1 ) {
			$lines[] = 'Tiene un solo módulo: NO existe la opción de pagar módulo por módulo.';
		}
		if ( ! empty( $f['parents'] ) ) {
			$lines[] = '';
			$lines[] = 'ESTA FORMACIÓN ESTÁ INCLUIDA EN (formaciones más completas que la contienen; quien se inscribe a una de ellas ya cursa esta): '
				. implode( ' · ', array_map( static fn( $p ) => "«{$p['title']}»", $f['parents'] ) );
		}
		if ( $open && ( $args = self::selling_points( $f, $price ) ) ) {
			$lines[] = '';
			$lines[] = 'ARGUMENTOS DE VENTA de esta formación (datos reales; usá uno distinto en cada respuesta):';
			foreach ( $args as $a ) {
				$lines[] = '- ' . $a;
			}
		}
		if ( $open ) {
			$lines[] = ! empty( $f['register_url'] )
				? 'Para inscribirse: escribí exactamente [Inscribirme](INSCRIPCION) (el sistema lo convierte en el enlace al checkout).'
				: 'Para inscribirse: con el botón de inscripción de esta misma página.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * ¿Sigue abierta hoy? El índice de abiertas se rehace una vez por día: entre
	 * el cierre de inscripción y el próximo sync, la fecha manda. La inscripción
	 * cierra al terminar ese día; las asincrónicas no tienen fecha de cierre.
	 */
	private static function enrollment_open( array $f ): bool {
		if ( str_starts_with( (string) ( $f['synchronicity'] ?? '' ), 'ASYNC' ) ) {
			return true;
		}
		$end = substr( (string) ( $f['enrollment_end'] ?? '' ), 0, 10 );
		return ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $end >= current_time( 'Y-m-d' );
	}

	/** ¿Alguna palabra de búsqueda del mensaje está en el título o en los docentes de la formación? */
	/**
	 * Palabras con las que se habla de OTRA ficha o de precios, no del nombre de
	 * una formación: "¿es más barata que la anterior?" traía de alternativa el
	 * taller "Ligamento Cruzado Anterior".
	 */
	private const REFERENCE_WORDS = [
		'anterior', 'anteriores', 'otra', 'otras', 'otro', 'otros', 'primera', 'primero', 'segunda', 'segundo', 'ultima', 'ultimo',
		'barata', 'barato', 'baratas', 'caro', 'cara', 'caras', 'precio', 'precios', 'mejor', 'peor', 'completa', 'completo',
		'compara', 'comparar', 'comparada', 'comparado', 'diferencia', 'diferencias', 'conviene', 'vale', 'sale', 'cuesta',
	];

	private static function names_entry( string $message, array $entry ): bool {
		$hay = ' ' . self::title_norm( ( $entry['title'] ?? '' ) . ' ' . implode( ' ', $entry['teachers'] ?? [] ) ) . ' ';
		foreach ( explode( ' ', self::title_norm( $message ) ) as $w ) {
			if ( strlen( $w ) >= 4 && ! in_array( $w, self::SEARCH_STOPWORDS, true ) && ! in_array( $w, self::REFERENCE_WORDS, true )
				&& str_contains( $hay, ' ' . $w . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Lo más vendible de una formación, armado con sus datos (nada inventado). */
	private static function selling_points( array $f, ?array $price ): array {
		$out = [];
		$async = str_starts_with( (string) ( $f['synchronicity'] ?? '' ), 'ASYNC' );
		$end   = substr( (string) ( $f['enrollment_end'] ?? '' ), 0, 10 );
		if ( ! $async && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			$days = (int) floor( ( strtotime( $end ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
			if ( $days >= 0 && $days <= 15 ) {
				$out[] = 0 === $days ? 'La inscripción cierra HOY.' : ( 1 === $days ? 'La inscripción cierra mañana.' : "Quedan {$days} días para el cierre de inscripción." );
			}
		}
		if ( $async ) {
			$out[] = 'Se puede empezar hoy mismo y cursar a su ritmo, sin horarios fijos.';
		}
		if ( ! empty( $f['sessions'] ) ) {
			$next  = $f['sessions'][0];
			$out[] = 'Próxima clase en vivo: ' . wp_date( 'l j \\d\\e F, H:i', $next['start'] ) . ' hs (hora de Argentina), "' . $next['name'] . '". Ya hay ' . count( $f['sessions'] ) . ' clase(s) en vivo programada(s).';
		}
		$disc = $price['discount'] ?? null;
		if ( ! empty( $disc['percentage'] ) ) {
			$until = ( 'early_payment' === ( $disc['type'] ?? '' ) && ! empty( $disc['expiration'] ) )
				? ' (vence el ' . self::format_date_human( substr( $disc['expiration'], 0, 10 ) ) . ')'
				: '';
			$out[] = "Descuento vigente del {$disc['percentage']}%{$until}.";
		}
		if ( count( $f['modules'] ?? [] ) > 1 ) {
			$out[] = 'Se puede empezar pagando solo el Módulo 1 y seguir módulo por módulo.';
		}
		if ( ! empty( $f['teachers'] ) ) {
			$t     = $f['teachers'][0];
			$out[] = ( ! empty( $f['great_lecturers'] ) ? 'Docentes destacados: ' : 'Lo dicta ' ) . $t['name']
				. ( ! empty( $t['background'] ) ? ' (' . mb_substr( $t['background'], 0, 110 ) . ')' : '' )
				. ( count( $f['teachers'] ) > 1 ? ' y ' . ( count( $f['teachers'] ) - 1 ) . ' docente(s) más' : '' ) . '.';
		}
		if ( ! empty( $f['supports'] ) ) {
			$out[] = 'Aval de ' . implode( ', ', array_slice( $f['supports'], 0, 3 ) ) . ': figura en el certificado.';
		}
		$students = (int) ( $f['total_students'] ?? 0 );
		if ( $students >= 50 ) {
			$out[] = number_format( $students, 0, ',', '.' ) . ' alumnos ya la cursaron.';
		}
		$rs = $f['reviews_summary'] ?? null;
		if ( ! empty( $rs['count'] ) && (float) ( $rs['average'] ?? 0 ) >= 4.5 ) {
			$out[] = 'Opiniones de alumnos: ' . number_format( (float) $rs['average'], 1, ',', '' ) . '/5 sobre ' . (int) $rs['count'] . ' opiniones.';
		}
		if ( ! empty( $f['great_topic'] ) ) {
			$out[] = 'Temática destacada: muy pedida y actual en el campo profesional.';
		}
		$out[] = 'Todo queda grabado y con acceso por un año: no se pierde nada si un día no se puede asistir.';
		return $out;
	}

	/** Formaciones de una temática para esta consulta; sin tema en el mensaje, las destacadas del área. */
	private static function prefilter_tematica( string $query, string $extra, string $slug ): array {
		$all = OEC_AI_Catalog::get_index( true );
		$in  = array_values( array_filter( $all, static fn( $e ) => in_array( $slug, $e['tags'] ?? [], true ) ) );
		$top = self::rank( $in, $query, $extra, self::MAX_RESULTS );
		if ( count( $top ) < 3 ) {
			// Pregunta por otra área: se responde igual, con lo que haya en todo el catálogo
			$seen = array_flip( array_column( $top, 'id' ) );
			foreach ( self::rank( $all, $query, $extra, self::MAX_RESULTS ) as $e ) {
				if ( ! isset( $seen[ $e['id'] ] ) && count( $top ) < self::MAX_RESULTS ) {
					$top[] = $e;
				}
			}
		}
		if ( ! $top ) {
			$top = array_slice( $in, 0, 6 ); // "Hola" / "Info": las primeras en el orden sugerido por la API
		}
		return self::load_formations( $top );
	}

	/** Instrucciones del rol según la página (van junto al contexto, fuera de la parte cacheada). */
	private static function mode_prompt( array $ctx, bool $open = true, array $seen = [], array $parents = [] ): string {
		$incl = $parents
			? "\n- IMPORTANTE: esta formación también se cursa como parte de " . implode( ' y de ', array_map( static fn( $p ) => "«{$p['title']}»", $parents ) )
				. ', formaciones más completas que la incluyen (sus datos y precios están en "OTRAS FORMACIONES"). Decilo SIEMPRE en tu primera respuesta de la conversación y cada vez que pregunte por precio, inscripción o certificados: puede hacer solo esta o ir por la más completa, que ya la trae adentro. Nombralas y ponelas en la línea <<IDS:…>>.'
			: '';
		$before = $seen
			? "\nPÁGINAS ANTERIORES: en esta misma conversación la persona ya miró la ficha de " . implode( ' y la de ', array_map( static fn( $t ) => "«{$t}»", $seen ) )
				. '. Sus datos reales están en el contexto. Cuando diga "la anterior", "la otra", "las que vi" o pida comparar, se refiere SOLO a esas (y a la de esta página, si es una ficha), no al resto del contexto. Si la pregunta no es sobre ellas, no las nombres, y nunca digas que "ya vio" una formación que no sea una de esas. Respondé directo con el dato correcto: sin dudar en voz alta ni rectificarte dentro del mismo mensaje. Cada formación tiene su propio precio: usá siempre el del contexto y nunca te disculpes ni "corrijas" respuestas anteriores por eso (eran sobre otra formación).'
			: '';
		if ( 'formacion' === $ctx['mode'] ) {
			$closed = $open ? '' : "\n- Esta formación tiene la INSCRIPCIÓN CERRADA: decilo con claridad apenas sea relevante, no invites a inscribirse ni prometas fechas, y ofrecé las formaciones similares de \"OTRAS FORMACIONES\".";
			return <<<TXT
MODO DE ESTA CONVERSACIÓN: ASESOR DE UNA FORMACIÓN
La persona está mirando la ficha de «{$ctx['title']}». Todo lo que pregunte se refiere a ESA formación, salvo que nombre otra. Es la primera que aparece abajo ("FORMACIÓN DE ESTA PÁGINA").
- Tu objetivo es resolver sus dudas y que se inscriba. Sos un vendedor que conoce el producto: respondé primero lo que preguntó, con los datos de la ficha y del conocimiento de ventas.
- ESTRUCTURA DE CADA RESPUESTA en esta página, en tres mensajes separados por |||:
  1) La respuesta a lo que preguntó.
  2) Un punto fuerte de la formación que NO te preguntó, en una o dos frases, tomado de "ARGUMENTOS DE VENTA" (o de sus docentes, opiniones y temario). Tiene que ser de OTRO tema que la pregunta: si preguntó por precio o pagos, hablá de docentes, avales, alumnos, opiniones, temario o modalidad; si preguntó por contenidos, hablá del docente, el aval o el descuento. Mirá la conversación y no repitas un punto que ya dijiste. Si quedan pocos días para el cierre o el descuento vence pronto, ese es el punto.
  3) Una pregunta o una invitación concreta a inscribirse. Si ya resolviste dos o tres dudas, invitá directamente a inscribirse.
- El mensaje 2 va SIEMPRE, aunque la pregunta haya sido corta. Excepciones: si la persona ya es alumna, pide hablar con alguien o la inscripción está cerrada.
- No presiones ni exageres: solo datos reales de la ficha.
- Si solo saluda o pide "info", no le preguntes qué área le interesa: presentale esta formación en 2 o 3 mensajes (qué es y para quién; cómo y cuándo se cursa; precio con el descuento vigente) y preguntá qué más quiere saber.{$incl}
- Precio: usá los valores del contexto (ya están en su moneda). Nombrá el descuento vigente y, si existe, la opción de empezar solo con el Módulo 1.
- Si el contexto trae "CONSEJO DE PAGO" y pregunta por precio, cuotas o cómo pagar, recomendá ESO primero y con convicción (pago completo con descuento + cuotas con tarjeta, con el monto y el ahorro); el pago módulo por módulo va después, como alternativa. No inventes tasas, cantidad de cuotas ni digas "sin interés".
- No repitas el título completo en cada mensaje: decí "esta formación" o "el curso".
- Si pregunta cómo pagar o pide datos para pagar (transferencia, CBU, link de pago): la respuesta es inscribirse por el checkout, y en ese mismo mensaje va SIEMPRE la línea "Para inscribirse" del contexto (el enlace [Inscribirme](INSCRIPCION) si figura).
- "Empezar solo con el Módulo 1" se ofrece únicamente si el contexto trae "Opción de inicio"; si la formación tiene un solo módulo, no lo nombres.
- Datos (cantidad de módulos, horas, fechas, precios): tomalos tal cual del contexto y decilos una sola vez, con seguridad. Nunca dudes en voz alta, te contradigas ni te rectifiques dentro de la respuesta, y no digas que vas a confirmar un dato que ya está en el contexto.
- Si esta formación no es lo que busca, ofrecele una de las "OTRAS FORMACIONES".
- Si el contexto trae "PRÓXIMAS CLASES EN VIVO", usalas cuando pregunte por fechas, horarios, cronograma o cómo se cursa, y como punto fuerte ("la próxima clase en vivo es el…"). Aclará siempre que el horario es de Argentina y que quedan grabadas. Si la lista trae entre paréntesis la hora de su país, dá esa también. NUNCA conviertas vos el horario a otro país o ciudad (te equivocás con los husos): si pide otra zona, dale la hora de Argentina (GMT-3) para que la convierta, y no te ofrezcas a convertirla. No inventes clases que no estén en la lista.{$closed}
- En la línea <<IDS:…>> poné SOLO las otras formaciones que nombres; nunca la de esta ficha (la persona ya está en su página).{$before}
TXT;
		}
		if ( 'tematica' === $ctx['mode'] ) {
			return <<<TXT
MODO DE ESTA CONVERSACIÓN: ASESOR DE LA TEMÁTICA «{$ctx['title']}»
La persona está en la página de {$ctx['title']}. Las formaciones de abajo son de esa área (salvo que haya preguntado por otra cosa).
- Tu objetivo es ayudarla a elegir una formación de esta área e inscribirse.
- Si solo saluda o pide "info", no le preguntes qué área le interesa: presentale 2 o 3 formaciones destacadas de la lista, con una frase sobre para quién es cada una, y preguntá por su perfil u objetivo para afinar.
- Si pregunta por otra área, respondé igual con lo que haya en el contexto.{$before}
TXT;
		}
		return ltrim( $before );
	}

	/**
	 * Conocimiento de ventas: las respuestas oficiales del equipo comercial a
	 * lo que la ficha no dice (cuotas, grabaciones, validez del certificado…)
	 * + cuándo y a dónde derivar. Editable en Tema OEC → Chat IA → Configuración.
	 */
	private static function sales_knowledge(): string {
		$opts = oec_get_options();
		$kb   = trim( (string) ( $opts['oec_chat_kb'] ?? '' ) ) ?: self::default_kb();
		$kb   = str_replace( '{CREDITOS_URL}', home_url( '/creditos-por-descuentos' ), $kb );
		$site = get_bloginfo( 'name' );

		$redes = array_filter( [
			'Instagram' => $opts['social_instagram'] ?? '',
			'Facebook'  => $opts['social_facebook'] ?? '',
			'YouTube'   => $opts['social_youtube'] ?? '',
			'LinkedIn'  => $opts['social_linkedin'] ?? '',
		] );
		$redes_txt = $redes
			? '- Redes oficiales de ' . $site . ': ' . implode( '; ', array_map( static fn( $k, $v ) => "$k $v", array_keys( $redes ), $redes ) ) . '.'
			: '';

		$wa     = self::whatsapp_url();
		$humano = $wa
			? 'escribí exactamente este enlace: [Escribinos por WhatsApp](WHATSAPP) (el sistema lo convierte en el link real; nunca escribas vos una dirección de WhatsApp como wa.me ni un número de teléfono)'
			: 'indicá que escriba a ventas@onlineeducation.center';

		$derivar = self::$derive
			? <<<TXT
CUÁNDO DERIVAR A UNA PERSONA — para derivar, {$humano}.
El contacto con una persona NO se ofrece por las dudas ("si tenés algún problema, escribinos…", "cualquier cosa, por WhatsApp…"): solo se da en los casos de esta lista, cuando la persona ya lo planteó. En el resto de las respuestas no lo nombres.
- Si pide hablar con una persona.
- Si DICE que algo le da error o que no puede: el código de descuento no se aplica, el checkout o el pago fallan, no logra inscribirse.
- Si pregunta algo de ventas que no está en la ficha ni en este conocimiento: decí que no tenés ese dato (no lo inventes) y derivá.
- Pedidos especiales (beneficios, un medio de pago que no figura en "Formas de pago", cambio de formación, devoluciones), consultas de empresas o instituciones, y propuestas comerciales o de colaboración.
TXT
			: <<<TXT
CONTACTO CON UNA PERSONA — en esta respuesta no corresponde: la persona no planteó un problema ni pidió hablar con alguien. No nombres WhatsApp ni el contacto con el equipo, ni expliques qué hacer "si algo falla", "si da error" o "si no se aplica", ni te ofrezcas a revisarlo vos ("decime cuál es", "avisame y lo vemos"). Única excepción: si pregunta un dato de ventas que no está en la ficha ni en este conocimiento, decí que no lo tenés (no lo inventes) y {$humano}.
TXT;

		return <<<TXT
CONOCIMIENTO DE VENTAS — respuestas oficiales a lo que la ficha no dice. Usalas tal cual; no agregues condiciones, plazos ni beneficios que no figuren acá.
{$kb}
{$redes_txt}

CÓMO PAGAR — regla fija: todo pago empieza en el checkout. Si pide los datos para transferir o depositar, el CBU/alias, un link de pago o "cómo pago", NO lo derives a una persona ni digas que alguien le va a pasar los datos: decile que inicie la inscripción (en una ficha, con el enlace [Inscribirme](INSCRIPCION) si el contexto lo indica; si no, con el botón de inscripción de la ficha de la formación), que elija ahí su medio de pago y que el sistema le muestra y le envía los datos para completarlo. Empezá la respuesta por ese paso ("Los datos te los da el sistema al inscribirte: …"), sin frases como "no puedo" o "no te puedo pasar".

{$derivar}

SI LA PERSONA YA ES ALUMNA (dice que pagó, que no puede entrar, o pregunta por su examen, su diploma o su clase): no le vendas nada; decile a dónde ir:
- Pagó y no recibió el acceso, o no puede entrar al campus; o consultó a la Mesa de Ayuda y no le respondieron → para derivarla a una persona, {$humano}.
- No encuentra la clase o el link; necesita más tiempo para rendir o terminar; no le aparecen los exámenes; quiere darse de baja; su diploma tiene un error → Mesa de Ayuda del campus virtual.
- Perdió o quiere cambiar el email de su cuenta → ventas@onlineeducation.center.
TXT;
	}

	/** Texto por defecto del conocimiento de ventas (respuestas del equipo comercial, octubre 2026). */
	public static function default_kb(): string {
		$site = get_bloginfo( 'name' );
		return <<<TXT
PAGOS
- Cómo se paga, sea el medio que sea (tarjeta, transferencia, depósito, efectivo, PayPal…): SIEMPRE inscribiéndose por el checkout de la formación. Ahí se elige el medio de pago y el mismo sistema muestra y envía por mail los datos para completar el pago (por ejemplo, los datos bancarios para la transferencia). Nadie pasa datos de pago por chat, WhatsApp ni mail.
- Cuotas: las formaciones con más de un módulo se pueden pagar módulo por módulo (cada módulo dura en general un mes), lo que funciona como cuotas. Además, si entre las formas de pago de la formación figuran PagoTic o Mercado Pago, esos medios ofrecen cuotas con interés. Si figura Klarna, permite comprar ahora y pagar después.
- Pagar el Módulo 1 y después los siguientes: sí. El precio no cambia: se paga el que figura hoy. Los módulos se pueden pagar hasta un año después de terminada la formación; quien paga tarde solo pierde la interacción en vivo con los docentes, porque todo queda grabado.
- No se puede comprar un módulo suelto que no sea el primero: los módulos son consecutivos.
- Pagando por módulos con tarjeta, el cobro se hace cada mes.
- Medios de pago: solo los que figuran en "Formas de pago" de cada formación (dependen del país). No ofrezcas otros.
- Se puede pagar desde la cuenta de otra persona.
- Monedas: el checkout muestra precios en EUR, USD, ARS, COP, CLP o MXN. En los demás países el precio se muestra en USD, pero los medios de pago locales cobran en la moneda local.
- Factura: sí, a empresas, universidades o personas (incluida factura "A"). Se pide por la Mesa de Ayuda una vez dentro de la formación.
- No se puede reservar un lugar para pagar después del cierre de inscripción.
- Confirmación del pago: con medios directos (tarjeta, PayPal) el mail de bienvenida llega al instante (revisar spam y promociones). Con medios indirectos (transferencia, depósito) hay que informar el pago en el sistema y una persona lo verifica: hasta 2 días hábiles. Pasado ese plazo, escribir a ventas@onlineeducation.center.

CURSADA
- Las clases en vivo, las conferencias y las sesiones de consulta quedan grabadas (con subtítulos). Se pueden ver mientras dure el acceso al campus: en formaciones sincrónicas, hasta 1 año después de que terminan; en asincrónicas, hasta 1 año después de que el alumno termina.
- Quien se inscribe con el curso empezado puede ver todo lo anterior, incluso las preguntas de sus compañeros. No pierde contenido: solo la interacción en vivo de las clases ya dadas.
- Días y horarios: si la ficha los indica ("Cómo se cursa"), usá eso. Si no los indica: en las formaciones sincrónicas los docentes proponen los horarios en vivo y quien no puede asistir no pierde nada porque queda grabado; en las asincrónicas todas las clases ya están grabadas.
- Dentro del campus los horarios se muestran en el huso horario de cada alumno.
- Las clases en vivo son por Zoom; se entra con un botón desde el campus virtual.
- Cursos a tu ritmo: al menos un año de acceso desde el pago.
- Acceso al material: hasta un año después de terminada la formación.
- Dudas durante la cursada: Mesa de Ayuda (privada, con los coordinadores académicos) y foros (con docentes y compañeros).
- Exámenes: si la ficha tiene "Exámenes", usá eso; si no, no afirmes nada.
- Presencial u online, en vivo o grabado: según la "Modalidad" y la "Sincronicidad" de cada formación.

CERTIFICADOS
- Reconocimiento: no se puede asegurar que se reconozca en todos los países; tiene el reconocimiento de las organizaciones que lo avalan, que figuran en el certificado. El diploma va acompañado de un certificado analítico con las asignaturas, los docentes y los contenidos.
- No son habilitantes para ejercer (salvo que la descripción de un certificado lo diga): es formación complementaria.
- Puntaje docente: no hay un trámite especial, pero en muchos institutos y profesorados de educación física de Argentina, Colombia y México, y en grados de CAFYD de España, se valoraron en la historia curricular de quienes aspiran a cargos docentes.
- Se entrega al final de la formación, no por módulo. Una formación puede tener más de un certificado.
- Formato: la mayoría son digitales (PDF para descargar); el tipo de cada uno figura en la ficha.
- Suele haber un certificado de asistencia para quien no rinde y otro de aprobación para quien rinde y aprueba: fijate cuáles tiene la formación.
- No avalamos cursos de otras academias o instituciones.

DESCUENTOS
- Además del descuento por pago anticipado o por pago completo, hay descuentos por créditos: con créditos acumulados se compran descuentos. Benefician a ex alumnos y a quienes hacen más formaciones. Cada formación tiene su propio cambio de créditos por porcentaje de descuento. Más información: {CREDITOS_URL}
- Código de descuento: se carga en el checkout, en "Resumen de compra", campo "Código de descuento", botón "Aplicar". En el celular el "Resumen de compra" está plegado: primero hay que tocar la flecha que está junto al "Total", abajo de la pantalla. (El chat muestra solo una imagen con el lugar exacto: no la describas ni pongas imágenes vos.) Vos no podés ver, validar ni revisar códigos: nunca pidas que te lo pasen ni ofrezcas revisarlo u "orientar" si falla. Explicá dónde se carga y cerrá ahí (con la invitación a inscribirse, si corresponde).
- Descuentos para grupos o socios de asociaciones: depende de la formación; escribir a ventas@onlineeducation.center.
- No hay becas ni beneficios por discapacidad por el momento.
- Vencimiento de una promoción: si el descuento tiene fecha de vencimiento, figura junto al precio de la formación.

INSCRIPCIÓN
- Próxima edición de una formación cerrada: no hay información, la decide el organizador. Ofrecé formaciones similares que estén abiertas.
- Hasta cuándo se puede entrar: hasta la fecha de cierre de inscripción de la formación.
- Después de pagar llega el mail de bienvenida (buscar "bienvenida" en el correo), que explica cómo empezar.
- Edad: no hay requisito de edad salvo que la formación lo indique en "Requisitos".
- Posgrados y másteres: requieren título de grado. Se aceptan estudiantes, pero el título no se expide hasta que se gradúen.
- Cambiar de formación después de pagar: solo excepcionalmente (derivar a una persona).
- Bajas y devoluciones: se devuelve el dinero si de nuestra parte algo no se cumplió (derivar a una persona para el caso puntual).

CONFIANZA
- {$site} es una comunidad de Online Education Center (OEC), la empresa que opera la plataforma (https://onlineeducation.center/es). Por eso en el checkout puede aparecer el nombre "Online Education Center". Comunidades de OEC: G-SE, Traumato Site, Fisio One, Swimming Science, Voley, ISF.
- Para mostrar que es un servicio real podés citar datos de la ficha (docentes, organizaciones que avalan, cantidad de alumnos, opiniones) y las redes oficiales.
- No hay atención por teléfono ni por videollamada.
- Direcciones: 1901 60th Place, Suite L2389, Bradenton, FL 34203, USA; y Juan Antonio de Bárcena 121, Torre 1 – 3D, Villasol, Córdoba (5003), Argentina. Presencia administrativa en España, México, Chile y Colombia.
TXT;
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
- Una lista (viñetas, fechas, cronograma de clases) va ENTERA dentro de un mismo mensaje: nunca pongas ||| entre los ítems de una lista
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
- Si la formación trae "CONSEJO DE PAGO" y pregunta por cuotas o cómo pagar, recomendá eso primero (pago completo con descuento + cuotas con tarjeta) y dejá el pago módulo por módulo como alternativa. No inventes tasas ni cantidad de cuotas
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
