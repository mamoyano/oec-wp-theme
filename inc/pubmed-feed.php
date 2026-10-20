<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   RSS DE PUBMED "LIMPIO" PARA WP-AUTOMATIC
   GET /wp-json/oec/v1/pubmed-rss?feed={id del RSS}[&limit=50][&fulltext=1]

   PubMed agrega a cada <link> parámetros que cambian en cada lectura
   (ff = hora del pedido, utm_source según quién lo lee). wp-automatic
   reconoce lo ya importado por ese enlace, así que re-importaba el mismo
   artículo (y volvía a pagar la IA). Este endpoint devuelve el mismo feed
   con los enlaces de PubMed/PMC sin parámetros: estables y limpios (también
   el botón "Publicación Original", que sale de [source_link]).

   - Solo lee feeds de PubMed (el id se valida): no es un proxy abierto.
   - Caché de 30 min; si PubMed falla, sirve la última copia buena.
   - Con &fulltext=1, si el artículo está libre (PMC o la web de la revista),
     su texto completo se agrega al final de <content:encoded>, así la IA de
     VigIA escribe a partir del paper y no solo del resumen (ver
     oec_pubmed_feed_fulltext()). Es opcional porque si la plantilla de la
     campaña de wp-automatic copiara ese contenido al post, publicaría el paper.
   El id es la parte de la URL de "Create RSS" en PubMed:
   https://pubmed.ncbi.nlm.nih.gov/rss/search/{id}/?limit=50…
   ============================================================ */

const OEC_PUBMED_FEED_TTL = 30 * MINUTE_IN_SECONDS;

add_action( 'rest_api_init', function (): void {
	register_rest_route( 'oec/v1', '/pubmed-rss', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => [
			'feed'  => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $v ),
			],
			'limit' => [
				'default'           => 50,
				'sanitize_callback' => fn( $v ) => max( 1, min( 100, (int) $v ) ),
			],
			'fulltext' => [
				'default'           => 0,
				'sanitize_callback' => fn( $v ) => (int) (bool) $v,
			],
		],
		'callback'            => 'oec_pubmed_feed_serve',
	] );
} );

/** Saca los parámetros de las URLs de PubMed y PMC (dejan de variar entre lecturas). */
function oec_pubmed_feed_clean( string $xml ): string {
	return (string) preg_replace(
		'#(https://(?:pubmed\.ncbi\.nlm\.nih\.gov|www\.ncbi\.nlm\.nih\.gov)/[^?"\'<>\s]*)\?[^"\'<>\s]*#',
		'$1',
		$xml
	);
}

function oec_pubmed_feed_serve( WP_REST_Request $req ): void {
	$feed  = $req['feed'];
	$limit = (int) $req['limit'];
	$full  = (bool) $req['fulltext'];
	$key   = 'oec_pubmed_' . md5( $feed . '|' . $limit . ( $full ? '|ft' : '' ) );

	$xml = get_transient( $key );
	if ( ! is_string( $xml ) ) {
		$res = wp_remote_get( "https://pubmed.ncbi.nlm.nih.gov/rss/search/{$feed}/?limit={$limit}", [
			'timeout'    => 25,
			'user-agent' => 'Mozilla/5.0 (compatible; G-SE feed; +' . home_url( '/' ) . ')',
		] );
		$body = wp_remote_retrieve_body( $res );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) && str_contains( $body, '<rss' ) ) {
			$xml = oec_pubmed_feed_clean( $body );
			if ( $full ) {
				$xml = oec_pubmed_feed_fulltext( $xml );
			}
			set_transient( $key, $xml, OEC_PUBMED_FEED_TTL );
			update_option( $key . '_ultimo', $xml, false ); // respaldo si PubMed falla
		} else {
			$xml = get_option( $key . '_ultimo' );
		}
	}

	if ( ! is_string( $xml ) || '' === $xml ) {
		status_header( 502 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'No se pudo leer el feed de PubMed.';
		exit;
	}

	header( 'Content-Type: application/rss+xml; charset=UTF-8' );
	header( 'Cache-Control: no-store' );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML del feed, solo reescrito.
	exit;
}

/* ============================================================
   TEXTO COMPLETO PARA VigIA
   PubMed solo trae el resumen. Para cada artículo del feed se busca el
   texto completo libre, en este orden:
     1. PubMed Central (E-utilities, XML JATS): casi todos los que tienen PMCID.
        Si la revista no permite bajarlo en XML, viene sin <body> → paso 2.
     2. La página del DOI en la revista (HTML), solo si de verdad trae el
        artículo: OEC_PUBMED_FT_MIN_CHARS y bastante más largo que el resumen
        (las páginas con muro de pago muestran solo el resumen).
   El texto se agrega al final de <content:encoded> bajo "FULL TEXT (source:
   …)"; si no se encuentra, el ítem queda como siempre (solo resumen).
   Cada artículo se busca una vez (OEC_PUBMED_FT_TTL; si no hubo texto,
   se reintenta a los OEC_PUBMED_FT_MISS_TTL) y por armado del feed se
   buscan como mucho OEC_PUBMED_FT_PER_BUILD (los más nuevos primero),
   para que wp-automatic no espere demasiado.
   ============================================================ */

const OEC_PUBMED_FT_PER_BUILD = 15;
const OEC_PUBMED_FT_MAX_CHARS = 45000; // ~11.000 tokens: alcanza para el cuerpo del paper sin la bibliografía
const OEC_PUBMED_FT_MIN_CHARS = 8000;
const OEC_PUBMED_FT_TTL       = 14 * DAY_IN_SECONDS;
const OEC_PUBMED_FT_MISS_TTL  = 2 * DAY_IN_SECONDS;

function oec_pubmed_ft_key( string $pmid ): string {
	return 'oec_pmft_' . $pmid;
}

function oec_pubmed_feed_fulltext( string $xml ): string {
	if ( ! preg_match_all( '#<item>.*?</item>#s', $xml, $m ) ) {
		return $xml;
	}

	$items   = [];
	$pending = [];
	foreach ( $m[0] as $raw ) {
		preg_match_all( '#<dc:identifier>(pmid|pmc|doi):([^<]+)</dc:identifier>#', $raw, $ids, PREG_SET_ORDER );
		$id = [];
		foreach ( $ids as $one ) {
			$id[ $one[1] ] = html_entity_decode( trim( $one[2] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}
		if ( empty( $id['pmid'] ) || ! ctype_digit( $id['pmid'] ) || ! str_contains( $raw, '<content:encoded><![CDATA[' ) ) {
			continue;
		}
		preg_match( '#<content:encoded><!\[CDATA\[(.*?)\]\]></content:encoded>#s', $raw, $ce );
		$id['abstract'] = isset( $ce[1] ) ? mb_strlen( wp_strip_all_tags( $ce[1] ) ) : 0;
		$items[ $raw ]  = $id;
		if ( false === get_transient( oec_pubmed_ft_key( $id['pmid'] ) ) && count( $pending ) < OEC_PUBMED_FT_PER_BUILD ) {
			$pending[ $id['pmid'] ] = $id;
		}
	}

	if ( $pending ) {
		$found = oec_pubmed_ft_from_pmc( $pending );
		$rest  = array_diff_key( $pending, $found );
		$found += oec_pubmed_ft_from_publisher( $rest );
		foreach ( $pending as $pmid => $id ) {
			$hit = $found[ $pmid ] ?? null;
			set_transient( oec_pubmed_ft_key( $pmid ), $hit ?: [ 'miss' => 1 ], $hit ? OEC_PUBMED_FT_TTL : OEC_PUBMED_FT_MISS_TTL );
		}
	}

	foreach ( $items as $raw => $id ) {
		$ft = get_transient( oec_pubmed_ft_key( $id['pmid'] ) );
		if ( empty( $ft['text'] ) ) {
			continue;
		}
		$html = '<hr><p><b>FULL TEXT</b> (source: ' . esc_html( $ft['src'] ) . ')</p>';
		foreach ( explode( "\n", $ft['text'] ) as $line ) {
			$html .= str_starts_with( $line, '## ' ) ? '<h3>' . esc_html( substr( $line, 3 ) ) . '</h3>' : '<p>' . esc_html( $line ) . '</p>';
		}
		$new = preg_replace_callback(
			'#\]\]></content:encoded>#',
			static fn() => $html . ']]></content:encoded>',
			$raw,
			1
		);
		$xml = str_replace( $raw, $new, $xml );
	}
	return $xml;
}

/** Pedidos GET en paralelo (url por clave) → [clave => ['code', 'body', 'url']]. */
function oec_pubmed_ft_get_multiple( array $urls, int $timeout ): array {
	if ( ! $urls ) {
		return [];
	}
	$requests = [];
	foreach ( $urls as $k => $url ) {
		$requests[ $k ] = [
			'url'     => $url,
			'type'    => 'GET',
			'headers' => [ 'User-Agent' => 'Mozilla/5.0 (compatible; G-SE feed; +' . home_url( '/' ) . ')' ],
		];
	}
	$out = [];
	try {
		$class     = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
		$exc_class = class_exists( '\WpOrg\Requests\Exception' ) ? '\WpOrg\Requests\Exception' : 'Requests_Exception';
		foreach ( $class::request_multiple( $requests, [ 'timeout' => $timeout, 'connect_timeout' => 5, 'redirects' => 6 ] ) as $k => $resp ) {
			if ( ! $resp instanceof $exc_class ) {
				$out[ $k ] = [ 'code' => (int) $resp->status_code, 'body' => (string) $resp->body, 'url' => (string) $resp->url ];
			}
		}
	} catch ( \Throwable $e ) {
		// Sin texto completo el ítem sale como siempre.
	}
	return $out;
}

/** Limpia y saca repetidos. */
function oec_pubmed_ft_lines( array $lines ): array {
	$seen = [];
	$out  = [];
	foreach ( $lines as $line ) {
		$line = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
		if ( '' === $line || '##' === $line || isset( $seen[ md5( $line ) ] ) ) {
			continue;
		}
		$seen[ md5( $line ) ] = true;
		$out[]                = $line;
	}
	return $out;
}

/**
 * Texto final, con tope OEC_PUBMED_FT_MAX_CHARS. Primero el texto corrido y
 * después las tablas: si las tablas van en su lugar, en un paper con muchas
 * el tope corta antes de la discusión y las conclusiones. Si el texto solo
 * ya se pasa, se conserva el principio y el final (conclusiones).
 */
function oec_pubmed_ft_join( array $prose, array $tables ): string {
	$prose  = oec_pubmed_ft_lines( $prose );
	$tables = oec_pubmed_ft_lines( $tables );
	$len    = static fn( array $lines ) => array_sum( array_map( 'mb_strlen', $lines ) ) + count( $lines );

	if ( $len( $prose ) > OEC_PUBMED_FT_MAX_CHARS ) {
		$head = [];
		while ( $prose && $len( $head ) + mb_strlen( $prose[0] ) < OEC_PUBMED_FT_MAX_CHARS * 0.6 ) {
			$head[] = array_shift( $prose );
		}
		$tail = [];
		while ( $prose && $len( $tail ) + mb_strlen( end( $prose ) ) < OEC_PUBMED_FT_MAX_CHARS * 0.4 ) {
			array_unshift( $tail, array_pop( $prose ) );
		}
		$prose  = array_merge( $head, [ '[…]' ], $tail );
		$tables = [];
	}

	$out = $prose;
	if ( $tables ) {
		$out[] = '## Tables';
		foreach ( $tables as $line ) {
			if ( $len( $out ) + mb_strlen( $line ) > OEC_PUBMED_FT_MAX_CHARS ) {
				$out[] = '[…]';
				break;
			}
			$out[] = $line;
		}
	}
	return implode( "\n", $out );
}

/* ── 1. PubMed Central ──────────────────────────────────────── */

function oec_pubmed_ft_from_pmc( array $items ): array {
	$by_pmc = [];
	foreach ( $items as $pmid => $id ) {
		if ( ! empty( $id['pmc'] ) && preg_match( '/^PMC(\d+)$/', $id['pmc'], $n ) ) {
			$by_pmc[ $id['pmc'] ] = (string) $pmid;
		}
	}
	$urls = [];
	foreach ( array_chunk( array_keys( $by_pmc ), 5 ) as $i => $chunk ) {
		$urls[ $i ] = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pmc&tool=g-se&id=' . implode( ',', array_map( fn( $p ) => substr( $p, 3 ), $chunk ) );
	}

	$found = [];
	foreach ( oec_pubmed_ft_get_multiple( $urls, 20 ) as $resp ) {
		if ( 200 !== $resp['code'] || ! str_contains( $resp['body'], '<article' ) ) {
			continue;
		}
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( $resp['body'], LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			continue;
		}
		$xp = new DOMXPath( $dom );
		foreach ( $xp->query( '//article' ) as $article ) {
			$pmcid = trim( (string) $xp->evaluate( 'string(.//article-meta/article-id[@pub-id-type="pmcid"])', $article ) );
			$body  = $xp->query( './body', $article )->item( 0 );
			if ( ! isset( $by_pmc[ $pmcid ] ) || ! $body ) {
				continue; // la revista no deja bajar el texto en XML
			}
			// Las citas numéricas ("[12]") no aportan y ocupan lugar.
			foreach ( iterator_to_array( $xp->query( './/xref[@ref-type="bibr"]', $body ) ) as $x ) {
				$x->parentNode->removeChild( $x );
			}
			$prose  = [];
			$tables = [];
			oec_pubmed_ft_walk_jats( $body, $prose, $tables );
			$text = oec_pubmed_ft_join( $prose, $tables );
			if ( mb_strlen( $text ) >= 2000 ) {
				$found[ $by_pmc[ $pmcid ] ] = [ 'text' => $text, 'src' => 'PubMed Central ' . $pmcid ];
			}
		}
	}
	return $found;
}

function oec_pubmed_ft_walk_jats( DOMNode $node, array &$lines, array &$tables ): void {
	foreach ( $node->childNodes as $c ) {
		if ( ! $c instanceof DOMElement ) {
			continue;
		}
		switch ( $c->localName ) {
			case 'title':
				$lines[] = '## ' . $c->textContent;
				break;
			case 'p':
				$lines[] = $c->textContent;
				break;
			case 'table-wrap':
			case 'fig':
				$label   = $c->getElementsByTagName( 'label' )->item( 0 );
				$caption = $c->getElementsByTagName( 'caption' )->item( 0 );
				$title   = trim( ( $label ? $label->textContent . ': ' : '' ) . ( $caption ? $caption->textContent : '' ) );
				if ( 'fig' === $c->localName ) {
					$lines[] = $title;
					break;
				}
				$tables[] = $title;
				foreach ( $c->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = [];
					foreach ( $tr->childNodes as $td ) {
						if ( $td instanceof DOMElement ) {
							$cells[] = trim( $td->textContent );
						}
					}
					$tables[] = implode( ' | ', $cells );
				}
				break;
			case 'ref-list':
			case 'supplementary-material':
			case 'graphic':
			case 'media':
				break;
			default:
				oec_pubmed_ft_walk_jats( $c, $lines, $tables );
		}
	}
}

/* ── 2. Página de la revista ────────────────────────────────── */

function oec_pubmed_ft_from_publisher( array $items ): array {
	$urls = [];
	foreach ( $items as $pmid => $id ) {
		if ( ! empty( $id['doi'] ) && preg_match( '#^10\.\d{4,9}/\S+$#', $id['doi'] ) ) {
			$urls[ $pmid ] = 'https://doi.org/' . str_replace( '%2F', '/', rawurlencode( $id['doi'] ) );
		}
	}
	$found = [];
	foreach ( oec_pubmed_ft_get_multiple( $urls, 15 ) as $pmid => $resp ) {
		if ( 200 !== $resp['code'] || strlen( $resp['body'] ) < 20000 ) {
			continue;
		}
		$text = oec_pubmed_ft_html_text( $resp['body'] );
		$len  = mb_strlen( $text );
		// Con muro de pago la página trae poco más que el resumen.
		if ( $len >= OEC_PUBMED_FT_MIN_CHARS && $len >= 4 * max( 1, (int) $items[ $pmid ]['abstract'] ) ) {
			$found[ (string) $pmid ] = [ 'text' => $text, 'src' => wp_parse_url( $resp['url'], PHP_URL_HOST ) ?: 'publisher' ];
		}
	}
	return $found;
}

/** Texto del artículo en una página HTML de revista: títulos, párrafos y tablas, hasta la bibliografía. */
function oec_pubmed_ft_html_text( string $html ): string {
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$ok   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	if ( ! $ok ) {
		return '';
	}
	$xp = new DOMXPath( $dom );
	foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//svg|//button' ) ) as $n ) {
		$n->parentNode->removeChild( $n );
	}

	$skip  = '/\b(ref-list|references?|bibliograph\w*|citation-list|footer|nav\w*|menu|sidebar|cookie\w*|related|recommend\w*|share|social|banner|metrics?)\b/i';
	$lines  = [];
	$tables = [];
	foreach ( $xp->query( '//h2|//h3|//h4|//p|//tr|//caption' ) as $el ) {
		for ( $a = $el->parentNode; $a instanceof DOMElement; $a = $a->parentNode ) {
			if ( preg_match( $skip, $a->getAttribute( 'class' ) . ' ' . $a->getAttribute( 'id' ) ) ) {
				continue 2;
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $el->textContent ) );
		if ( 'tr' === $el->localName ) {
			$cells = [];
			foreach ( $el->childNodes as $td ) {
				if ( $td instanceof DOMElement ) {
					$cells[] = trim( (string) preg_replace( '/\s+/u', ' ', $td->textContent ) );
				}
			}
			$tables[] = implode( ' | ', $cells );
			continue;
		}
		if ( 'caption' === $el->localName ) {
			$tables[] = $text;
			continue;
		}
		if ( 'p' !== $el->localName ) {
			if ( preg_match( '/^(\d+\.?\s*)?(references|bibliograf\w*|literature cited|referencias)$/i', $text ) ) {
				break;
			}
			$lines[] = '## ' . $text;
			continue;
		}
		if ( mb_strlen( $text ) >= 40 ) {
			$lines[] = $text;
		}
	}
	return oec_pubmed_ft_join( $lines, $tables );
}
