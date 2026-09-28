<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   ROBOTS.TXT, LLMS.TXT Y NOINDEX

   robots.txt: los buscadores solo leen el de la raíz del dominio
   (g-se.com/robots.txt, nunca /es/robots.txt), así que se arma igual
   desde cualquier sitio de la red y declara el sitemap de TODOS.

   llms.txt (llmstxt.org): resumen en Markdown para asistentes de IA
   (ChatGPT, Claude, Perplexity…) — qué es el sitio, sus secciones y
   el catálogo vigente con links. Se regenera con el sitemap.

   Reemplazan a los del plugin OEC (oec_allow_ai_crawlers y su
   llms.txt): el del plugin agregaba un grupo "User-agent: GPTBot /
   Allow: /" por bot, y un bot que tiene grupo propio ignora el de
   "User-agent: *" — o sea, GPTBot podía entrar a /wp-admin/.
   ============================================================ */

/**
 * Crawlers de IA y si pueden entrar ('allow') o no ('block').
 * - Búsqueda / respuesta en vivo (citan y linkean al sitio): conviene
 *   permitirlos siempre, son tráfico.
 * - Entrenamiento (el contenido termina dentro del modelo, sin link):
 *   decisión de negocio. Por defecto permitidos, igual que antes.
 */
function oec_robots_ai_agents(): array {
	return apply_filters( 'oec_robots_ai_agents', [
		// Búsqueda y agentes que responden en vivo.
		'OAI-SearchBot'      => 'allow',
		'ChatGPT-User'       => 'allow',
		'Claude-SearchBot'   => 'allow',
		'Claude-User'        => 'allow',
		'PerplexityBot'      => 'allow',
		'Perplexity-User'    => 'allow',
		'DuckAssistBot'      => 'allow',
		'MistralAI-User'     => 'allow',
		// Entrenamiento.
		'GPTBot'             => 'allow',
		'ClaudeBot'          => 'allow',
		'Google-Extended'    => 'allow',
		'Applebot-Extended'  => 'allow',
		'Meta-ExternalAgent' => 'allow',
		'CCBot'              => 'allow',
	] );
}

add_action( 'init', function () {
	remove_filter( 'robots_txt', 'oec_allow_ai_crawlers', 10 );
} );

add_filter( 'robots_txt', function ( $output, $public ) {
	if ( ! $public ) {
		return $output; // "Disuadir a los motores de búsqueda": manda WordPress.
	}

	$agents  = oec_robots_ai_agents();
	$allowed = array_keys( array_filter( $agents, fn( $v ) => 'allow' === $v ) );
	$blocked = array_keys( array_diff_key( $agents, array_flip( $allowed ) ) );

	$lines = [ '# ' . ( is_multisite() ? get_network()->site_name : get_bloginfo( 'name' ) ), '' ];

	// Un solo grupo para todos: los bots de IA permitidos se nombran para
	// que quede explícito, pero con las mismas reglas que el resto.
	foreach ( $allowed as $agent ) {
		$lines[] = "User-agent: {$agent}";
	}
	$lines[] = 'User-agent: *';
	$lines   = array_merge( $lines, [
		'Disallow: /wp-admin/',
		'Disallow: /*/wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Allow: /*/wp-admin/admin-ajax.php',
		'Disallow: /wp-login.php',
		'Disallow: /*/wp-login.php',
		// Búsquedas internas: infinitas combinaciones sin contenido propio.
		'Disallow: /*?s=',
		'Disallow: /*&s=',
		'Disallow: /*?q=',
		'Disallow: /*&q=',
		// Datos crudos del catálogo (los usa el tema, no son páginas).
		'Disallow: /*oec-ai-catalog/',
		'Disallow: /*oec-seo/',
		// Vista web del último newsletter.
		'Disallow: /*?oec_newsletter=',
	] );

	if ( $blocked ) {
		$lines[] = '';
		$lines[] = '# Sin permiso para usar el contenido en entrenamiento de modelos';
		foreach ( $blocked as $agent ) {
			$lines[] = "User-agent: {$agent}";
		}
		$lines[] = 'Disallow: /';
	}

	$lines[] = '';
	foreach ( oec_seo_sites() as $site ) {
		$lines[] = 'Sitemap: ' . $site['url'] . 'sitemap.xml';
	}
	$lines[] = '';
	$lines[] = '# Resumen para asistentes de IA: ' . network_home_url( '/llms.txt' );

	return implode( "\n", $lines ) . "\n";
}, 999, 2 );

/* ── noindex coherente con el sitemap ──────────────────────── */

add_filter( 'wp_robots', function ( array $robots ): array {
	// Páginas de trámite (no van al sitemap).
	if ( is_page( [ 'otorgar-creditos', 'confirmacion-de-canje-de-creditos', 'newsletter-confirmado' ] ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	// Etiquetas con pocos posts: páginas finas, fuera del sitemap y del índice.
	if ( is_tag() && ( $term = get_queried_object() ) && $term->count < OEC_SITEMAP_TAG_MIN ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );

// /organizacion/ sin ?slug= válido: 404 real, como /docente/ (docentes.php).
add_action( 'template_redirect', function () {
	if ( ! is_page( 'organizacion' ) || ! class_exists( 'OEC_AI_Catalog' ) ) {
		return;
	}
	$slug = sanitize_title( wp_unslash( $_GET['slug'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $slug || ! OEC_AI_Catalog::get_organization( $slug ) ) {
		status_header( 404 );
		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}
} );

/* ── llms.txt ──────────────────────────────────────────────── */

// El plugin atiende el mismo /llms.txt en template_redirect; este
// responde antes (parse_request, en sitemap.php) y el suyo no llega a correr.

/**
 * Contenido de /llms.txt. En un sitio con sitemap propio, su resumen
 * completo (cacheado en uploads/oec-seo/llms.txt hasta el próximo
 * cambio); en la raíz redirectora, un índice de los sitios de la red.
 */
function oec_llms_txt(): ?string {
	if ( ! oec_seo_is_indexed_site() ) {
		return ( is_multisite() && is_main_site() ) ? oec_llms_network() : null;
	}
	$file = oec_seo_cache_dir() . '/llms.txt';
	if ( file_exists( $file ) ) {
		return (string) file_get_contents( $file );
	}
	$txt = oec_llms_site();
	file_put_contents( $file, $txt, LOCK_EX );
	return $txt;
}

function oec_llms_network(): ?string {
	$sites = oec_seo_sites();
	if ( ! $sites ) {
		return null;
	}
	$out = [ '# ' . get_network()->site_name, '' ];
	if ( $sites[0]['description'] ) {
		$out[] = '> ' . $sites[0]['description'];
		$out[] = '';
	}
	$out[] = 'El sitio está dividido por idioma. Cada versión tiene su propio llms.txt con el catálogo completo:';
	$out[] = '';
	foreach ( $sites as $s ) {
		$out[] = sprintf( '- [%s (%s)](%sllms.txt): %s', $s['name'], $s['lang'], $s['url'], $s['description'] ?: $s['url'] );
	}
	return implode( "\n", $out ) . "\n";
}

/** Línea de lista Markdown: "- [título](url): detalle". */
function oec_llms_link( string $title, string $url, string $detail = '' ): string {
	$title = str_replace( [ '[', ']' ], [ '(', ')' ], wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) );
	$detail = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $detail, ENT_QUOTES, 'UTF-8' ) ) ) );
	return "- [{$title}]({$url})" . ( '' !== $detail ? ": {$detail}" : '' );
}

function oec_llms_date( string $ymd ): string {
	return $ymd ? wp_date( 'j/n/Y', strtotime( $ymd . ' 12:00:00' ) ) : '';
}

function oec_llms_site(): string {
	$name  = get_bloginfo( 'name' );
	$desc  = wp_strip_all_tags( get_bloginfo( 'description' ) );
	$page  = fn( string $slug ) => ( $p = get_page_by_path( $slug ) ) && 'publish' === $p->post_status ? get_permalink( $p ) : '';
	$has   = class_exists( 'OEC_AI_Catalog' );
	$rows  = $has ? OEC_AI_Catalog::get_listing() : [];
	$open  = array_values( array_filter( $rows, fn( $r ) => $r['open'] ) );
	$orgs  = $has ? OEC_AI_Catalog::get_organizations( 1 ) : [];
	$docs  = function_exists( 'oec_docentes_catalog' ) ? oec_docentes_catalog() : [];
	$posts = (int) wp_count_posts( 'post' )->publish;

	$out   = [ "# {$name}", '' ];
	if ( $desc ) {
		$out[] = "> {$desc}";
		$out[] = '';
	}
	$out[] = sprintf(
		'%1$s es una plataforma de formación online. Hoy tiene %2$s formaciones con inscripción abierta (cursos, certificaciones, diplomados, simposios) de %3$s organizaciones, un histórico de %4$s formaciones dictadas, %5$s docentes y %6$s artículos científicos y de divulgación. Todas las formaciones son online; cada ficha detalla programa, docentes, fechas, carga horaria, certificación, precios y opiniones de alumnos.',
		$name,
		number_format_i18n( count( $open ) ),
		number_format_i18n( count( array_unique( array_column( $open, 'org' ) ) ) ),
		number_format_i18n( count( $rows ) ),
		number_format_i18n( count( $docs ) ),
		number_format_i18n( $posts )
	);
	$out[] = '';
	$out[] = 'Actualizado: ' . wp_date( 'j/n/Y' ) . '. Idioma: ' . get_bloginfo( 'language' ) . '.';

	// Secciones principales.
	$sections = array_filter( [
		[ __( 'Formaciones', 'oec-theme' ), $page( 'formaciones' ), 'buscador de todas las formaciones (abiertas y cerradas), filtrable por temática, tipo y texto.' ],
		[ __( 'Especiales por temática', 'oec-theme' ), $page( 'especiales' ), 'landings curadas por área, con formaciones, docentes y opiniones.' ],
		[ __( 'Docentes', 'oec-theme' ), $page( 'docentes' ), 'perfil, trayectoria y formaciones de cada docente.' ],
		[ __( 'Organizaciones', 'oec-theme' ), $page( 'organizaciones' ), 'instituciones y equipos que dictan las formaciones.' ],
		[ __( 'Artículos', 'oec-theme' ), $page( 'articulos' ), 'artículos científicos y de divulgación, filtrables por categoría, autor y año.' ],
		[ __( 'Créditos por descuentos', 'oec-theme' ), $page( 'creditos-por-descuentos' ), 'cómo se ganan y se canjean créditos por descuentos en formaciones.' ],
	], fn( $s ) => '' !== $s[1] );
	if ( $sections ) {
		$out[] = '';
		$out[] = '## Secciones';
		$out[] = '';
		foreach ( $sections as [ $t, $u, $d ] ) {
			$out[] = oec_llms_link( $t, $u, $d );
		}
	}

	// Especiales: hijas de /especiales/.
	$esp = get_page_by_path( 'especiales' );
	$landings = $esp ? get_pages( [ 'parent' => $esp->ID, 'sort_column' => 'post_title' ] ) : [];
	if ( $landings ) {
		$out[] = '';
		$out[] = '## Especiales por temática';
		$out[] = '';
		foreach ( $landings as $l ) {
			$out[] = oec_llms_link( get_the_title( $l ), get_permalink( $l ), has_excerpt( $l ) ? get_the_excerpt( $l ) : '' );
		}
	}

	// Formaciones abiertas, las que cierran antes primero.
	if ( $open ) {
		usort( $open, fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] ) );
		$clean = function_exists( 'oec_docente_clean_name' ) ? 'oec_docente_clean_name' : 'trim';
		$out[] = '';
		$out[] = '## Formaciones con inscripción abierta';
		$out[] = '';
		foreach ( $open as $r ) {
			$bits = array_filter( [
				$r['type'],
				$r['org'] ? 'por ' . $r['org'] : '',
				$r['teachers'] ? 'docentes: ' . implode( ', ', array_map( $clean, array_slice( $r['teachers'], 0, 4 ) ) ) : '',
				$r['start'] ? 'inicia ' . oec_llms_date( $r['start'] ) : ( 'ASYNC' === $r['synchronicity'] ? 'a tu ritmo' : '' ),
				$r['enrollment_end'] ? 'inscripción hasta ' . oec_llms_date( $r['enrollment_end'] ) : '',
				! empty( $r['reviews']['count'] ) ? sprintf( _n( '%1$s/5 (%2$d opinión)', '%1$s/5 (%2$d opiniones)', $r['reviews']['count'], 'oec-theme' ), number_format_i18n( $r['reviews']['average'], 1 ), $r['reviews']['count'] ) : '',
			] );
			$out[] = oec_llms_link( $r['title'], home_url( user_trailingslashit( '/formacion/' . $r['slug'] ) ), implode( ' · ', $bits ) );
		}
	}

	// Organizaciones con formaciones abiertas.
	if ( $orgs && function_exists( 'oec_organizacion_url' ) ) {
		usort( $orgs, fn( $a, $b ) => $b['count'] <=> $a['count'] );
		$out[] = '';
		$out[] = '## Organizaciones';
		$out[] = '';
		foreach ( $orgs as $o ) {
			$out[] = oec_llms_link( $o['name'], oec_organizacion_url( $o['slug'] ), sprintf( _n( '%d formación abierta', '%d formaciones abiertas', $o['count'], 'oec-theme' ), $o['count'] ) );
		}
	}

	// Los 100 docentes con formaciones abiertas y más alumnos (el resto, en /docentes/).
	$activos = array_filter( $docs, fn( $d ) => ! empty( $d['next'] ) );
	if ( $activos && function_exists( 'oec_docente_url' ) ) {
		uasort( $activos, fn( $a, $b ) => $b['alumnos'] <=> $a['alumnos'] );
		$activos = array_slice( $activos, 0, 100 );
		$out[]   = '';
		$out[]   = '## Docentes destacados';
		$out[]   = '';
		$out[]   = sprintf( 'Los que más alumnos formaron entre los que tienen formaciones abiertas. Perfil completo de los %s docentes en %s', number_format_i18n( count( $docs ) ), oec_docentes_url() );
		$out[]   = '';
		foreach ( $activos as $d ) {
			$bits  = array_filter( [ $d['background'], $d['alumnos'] ? number_format_i18n( $d['alumnos'] ) . ' alumnos formados' : '' ] );
			$out[] = oec_llms_link( $d['name'], oec_docente_url( $d['slug'] ), implode( ' · ', $bits ) );
		}
	}

	// Artículos: categorías con posts, padre → hijas.
	$cats = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ] );
	if ( $cats && ! is_wp_error( $cats ) ) {
		$out[] = '';
		$out[] = '## Artículos por categoría';
		$out[] = '';
		foreach ( $cats as $c ) {
			$out[] = oec_llms_link( $c->name, get_term_link( $c ), sprintf( _n( '%d artículo', '%d artículos', $c->count, 'oec-theme' ), $c->count ) . ( $c->description ? '. ' . $c->description : '' ) );
		}
	}

	// Opcional (llmstxt.org): lo que un asistente puede saltear si tiene poco contexto.
	$out[] = '';
	$out[] = '## Optional';
	$out[] = '';
	foreach ( get_posts( [ 'numberposts' => 30, 'post_status' => 'publish', 'no_found_rows' => true ] ) as $p ) {
		$out[] = oec_llms_link( get_the_title( $p ), get_permalink( $p ), wp_trim_words( get_the_excerpt( $p ), 25, '…' ) );
	}
	$out[] = oec_llms_link( 'Sitemap XML', home_url( '/sitemap.xml' ), 'todas las URLs indexables del sitio, incluido el historial de formaciones cerradas.' );

	return implode( "\n", $out ) . "\n";
}
