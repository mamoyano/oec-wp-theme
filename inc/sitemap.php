<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SITEMAP XML — /sitemap.xml (índice) + un sitemap por sección:

     /sitemap-paginas.xml               home + páginas, en su jerarquía
     /sitemap-formaciones.xml           formaciones con inscripción abierta
     /sitemap-formaciones-cerradas.xml  historial (cerradas), de a 1000
     /sitemap-docentes.xml              landings de docentes
     /sitemap-organizaciones.xml        landings de organizaciones
     /sitemap-articulos.xml, -2, -3…    posts, de a 1000 (más viejos primero)
     /sitemap-categorias.xml            categorías, padre → hijas
     /sitemap-etiquetas.xml             etiquetas con OEC_SITEMAP_TAG_MIN posts
     /sitemap-autores.xml               autores con posts publicados

   Cómo se mantiene solo:
   - Cada sección se genera la primera vez que se pide y queda en
     uploads/oec-seo/ (por sitio de la red).
   - Publicar/editar/borrar un post o página, o tocar una categoría/
     etiqueta, borra las secciones de contenido; el sync diario del
     catálogo (OEC_AI_Catalog) borra las de formaciones/docentes/orgs.
     En ambos casos un cron las vuelve a generar a los 2 minutos, así
     ningún buscador espera a que se armen.
   - lastmod "honesto" en lo que viene del catálogo: se guarda un hash
     de cada formación/docente/organización y la fecha solo cambia si
     cambió el contenido (Google ignora los lastmod que cambian a diario).

   Formaciones: solo las que tienen canonical en ESTE sitio. La API
   manda la URL de la comunidad "dueña" (traumato.site, fisio.one…) y
   la ficha declara ese canonical: si la listáramos, Search Console la
   reportaría como "URL enviada no seleccionada como canónica".

   En un multisite con la raíz como redirector de idioma (mu-plugin
   oec-root-redirect), la raíz no tiene sitemap propio: /sitemap.xml
   de la raíz lista los sitemaps de /es, /en…
   ============================================================ */

const OEC_SITEMAP_PER_FILE    = 1000;
const OEC_SITEMAP_TAG_MIN     = 5;
const OEC_SEO_REWRITE_VERSION = '1';

/**
 * Secciones en el orden del índice. 'group' decide qué invalida cada
 * cambio: 'content' (posts, páginas, términos) o 'catalog' (sync diario).
 */
function oec_sitemap_sections(): array {
	return [
		'paginas'              => [ 'group' => 'content', 'cb' => 'oec_sitemap_pages' ],
		'formaciones'          => [ 'group' => 'catalog', 'cb' => 'oec_sitemap_formations_open' ],
		'formaciones-cerradas' => [ 'group' => 'catalog', 'cb' => 'oec_sitemap_formations_closed' ],
		'docentes'             => [ 'group' => 'catalog', 'cb' => 'oec_sitemap_docentes' ],
		'organizaciones'       => [ 'group' => 'catalog', 'cb' => 'oec_sitemap_organizations' ],
		'articulos'            => [ 'group' => 'content', 'cb' => 'oec_sitemap_posts' ],
		'categorias'           => [ 'group' => 'content', 'cb' => 'oec_sitemap_categories' ],
		'etiquetas'            => [ 'group' => 'content', 'cb' => 'oec_sitemap_tags' ],
		'autores'              => [ 'group' => 'content', 'cb' => 'oec_sitemap_authors' ],
	];
}

/**
 * Páginas que no van al sitemap: plantillas que solo tienen sentido con
 * un ?slug= o un /t-… (formacion, docente, organizacion) y páginas de
 * trámite (confirmaciones, canje). Sus hijas tampoco van.
 */
function oec_sitemap_excluded_pages(): array {
	return apply_filters( 'oec_sitemap_excluded_pages', [
		'formacion',
		'docente',
		'organizacion',
		'newsletter-confirmado',
		'otorgar-creditos',
		'confirmacion-de-canje-de-creditos',
	] );
}

/* ── Sitios de la red que publican sitemap/llms.txt ─────────── */

/**
 * Sitios públicos de la red con este tema. La raíz queda afuera cuando
 * es solo el redirector de idioma y hay otros sitios. En un WordPress
 * simple, devuelve solo el sitio actual.
 *
 * @return array<int, array{id:int, url:string, name:string, description:string, lang:string}>
 */
function oec_seo_sites(): array {
	static $sites = null;
	if ( null !== $sites ) {
		return $sites;
	}
	$info = static function (): array {
		return [
			'id'          => get_current_blog_id(),
			'url'         => home_url( '/' ),
			'name'        => get_bloginfo( 'name' ),
			'description' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'lang'        => get_bloginfo( 'language' ),
		];
	};
	if ( ! is_multisite() ) {
		return $sites = [ $info() ];
	}

	$sites    = [];
	$template = get_template();
	foreach ( get_sites( [ 'public' => 1, 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'number' => 50 ] ) as $site ) {
		switch_to_blog( (int) $site->blog_id );
		if ( get_option( 'template' ) === $template ) {
			$sites[] = $info();
		}
		restore_current_blog();
	}
	$main = get_main_site_id();
	if ( count( $sites ) > 1 && function_exists( 'oec_root_preferred_lang' ) ) {
		$sites = array_values( array_filter( $sites, fn( $s ) => $s['id'] !== $main ) );
	}
	return $sites = apply_filters( 'oec_seo_sites', $sites );
}

/** ¿El sitio actual publica su propio sitemap/llms.txt? */
function oec_seo_is_indexed_site(): bool {
	return in_array( get_current_blog_id(), array_column( oec_seo_sites(), 'id' ), true );
}

/* ── Rutas ─────────────────────────────────────────────────── */

add_action( 'init', function () {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?oec_seo=sitemap', 'top' );
	add_rewrite_rule( '^sitemap-([a-z]+(?:-[a-z]+)*?)(?:-([0-9]+))?\.xml$', 'index.php?oec_seo=sitemap&oec_seo_arg=$matches[1]&oec_seo_page=$matches[2]', 'top' );
	add_rewrite_rule( '^sitemap\.xsl$', 'index.php?oec_seo=xsl', 'top' );
	add_rewrite_rule( '^llms\.txt$', 'index.php?oec_seo=llms', 'top' );
	add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?oec_seo=indexnow&oec_seo_arg=$matches[1]', 'top' );
	// Sitemaps anteriores (All in One SEO: post-sitemap2.xml, sitemap.rss…)
	// que Search Console ya tiene registrados: 301 al índice nuevo.
	add_rewrite_rule( '^(?:[a-z_-]+-sitemap[0-9]*\.xml|sitemap\.rss)$', 'index.php?oec_seo=legacy', 'top' );

	if ( get_option( 'oec_seo_rewrite' ) !== OEC_SEO_REWRITE_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'oec_seo_rewrite', OEC_SEO_REWRITE_VERSION );
	}
}, 20 );

add_filter( 'query_vars', function ( array $vars ): array {
	return array_merge( $vars, [ 'oec_seo', 'oec_seo_arg', 'oec_seo_page' ] );
} );

// El sitemap de WordPress (wp-sitemap.xml) queda apagado: este lo reemplaza.
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/*
 * Se atiende en parse_request (antes de la consulta principal y de
 * template_redirect): así no corre el redirect de idioma de la raíz ni
 * el redirect_canonical, y no se gasta una query de posts.
 */
add_action( 'parse_request', function ( WP $wp ) {
	$qv   = $wp->query_vars;
	$what = $qv['oec_seo'] ?? '';

	// wp-sitemap.xml y compañía (reglas del core, que siguen registradas).
	if ( '' === $what && ( ! empty( $qv['sitemap'] ) || ! empty( $qv['sitemap-stylesheet'] ) ) ) {
		$what = 'legacy';
	}

	switch ( $what ) {
		case 'sitemap':
			$arg = (string) ( $qv['oec_seo_arg'] ?? '' );
			$xml = '' === $arg
				? oec_sitemap_index_xml()
				: oec_sitemap_section_xml( $arg, max( 1, (int) ( $qv['oec_seo_page'] ?? 1 ) ) );
			oec_seo_send( $xml, 'application/xml' );
			break;
		case 'xsl':
			oec_seo_send( oec_sitemap_xsl(), 'text/xsl' );
			break;
		case 'llms':
			oec_seo_send( function_exists( 'oec_llms_txt' ) ? oec_llms_txt() : null, 'text/plain' );
			break;
		case 'indexnow':
			$key = function_exists( 'oec_indexnow_key' ) ? oec_indexnow_key( false ) : '';
			oec_seo_send( $key && hash_equals( $key, (string) ( $qv['oec_seo_arg'] ?? '' ) ) ? $key : null, 'text/plain' );
			break;
		case 'legacy':
			wp_redirect( home_url( '/sitemap.xml' ), 301, 'OEC' );
			exit;
	}
}, 0 );

/** Responde y termina. $body null → 404. */
function oec_seo_send( ?string $body, string $type ): void {
	if ( null === $body ) {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=60' );
		echo 'Not found';
		exit;
	}
	status_header( 200 );
	header( "Content-Type: {$type}; charset=UTF-8" );
	header( 'X-Robots-Tag: noindex, follow' );
	header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML/texto armado y escapado acá
	exit;
}

/* ── Caché en disco ────────────────────────────────────────── */

function oec_seo_cache_dir(): string {
	$dir = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'oec-seo';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}
	return $dir;
}

/** Qué se generó: [sección => ['pages' => n, 'lastmod' => [n => fecha]]]. */
function oec_sitemap_state( ?array $save = null ): array {
	$path = oec_seo_cache_dir() . '/sections.json';
	if ( null !== $save ) {
		file_put_contents( $path, wp_json_encode( $save ), LOCK_EX );
		return $save;
	}
	$data = file_exists( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $data ) ? $data : [];
}

/**
 * Borra lo generado de un grupo ('content' | 'catalog' | 'all') y agenda
 * la regeneración. El llms.txt se arma con ambos, así que cae siempre.
 */
function oec_seo_purge( string $group = 'all' ): void {
	$dir   = oec_seo_cache_dir();
	$state = oec_sitemap_state();
	foreach ( oec_sitemap_sections() as $name => $section ) {
		if ( 'all' !== $group && $section['group'] !== $group ) {
			continue;
		}
		foreach ( glob( "{$dir}/{$name}-*.xml" ) ?: [] as $file ) {
			wp_delete_file( $file );
		}
		// Se marca en vez de borrarse: el índice de la raíz (que no regenera
		// secciones de otro sitio) la sigue listando mientras tanto. Antes,
		// entre la purga y la regeneración, /sitemap.xml salía sin artículos
		// ni páginas, y Cloudflare cacheaba esa versión una hora.
		if ( isset( $state[ $name ] ) ) {
			$state[ $name ]['stale'] = true;
		}
	}
	oec_sitemap_state( $state );
	wp_delete_file( $dir . '/llms.txt' );

	if ( ! wp_next_scheduled( 'oec_seo_warm' ) ) {
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'oec_seo_warm' );
	}
}

/** Regenera todo lo que falte (cron): los bots siempre reciben caché. */
add_action( 'oec_seo_warm', function () {
	if ( ! oec_seo_is_indexed_site() ) {
		return;
	}
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$state = oec_sitemap_state();
	foreach ( array_keys( oec_sitemap_sections() ) as $name ) {
		if ( ! isset( $state[ $name ] ) || ! empty( $state[ $name ]['stale'] ) ) {
			$state = oec_sitemap_build( $name );
		}
	}
	if ( function_exists( 'oec_llms_txt' ) ) {
		oec_llms_txt();
	}
} );

/* ── Invalidación ──────────────────────────────────────────── */

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( in_array( $post->post_type, [ 'post', 'page' ], true ) && ( 'publish' === $new || 'publish' === $old ) ) {
		oec_seo_purge( 'content' );
	}
}, 10, 3 );

foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $oec_hook ) {
	add_action( $oec_hook, function ( $term_id, $tt_id, $taxonomy ) {
		if ( in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			oec_seo_purge( 'content' );
		}
	}, 10, 3 );
}
unset( $oec_hook );

add_action( 'profile_update', fn() => oec_seo_purge( 'content' ) );
add_action( 'oec_ai_catalog_synced', fn() => oec_seo_purge( 'catalog' ) );
add_action( 'after_switch_theme', fn() => oec_seo_purge( 'all' ) );

/* ── Armado ────────────────────────────────────────────────── */

/**
 * Genera una sección: la parte en archivos de OEC_SITEMAP_PER_FILE URLs
 * y guarda cuántos son y el lastmod de cada uno (para el índice).
 */
function oec_sitemap_build( string $name ): array {
	$sections = oec_sitemap_sections();
	$state    = oec_sitemap_state();
	if ( ! isset( $sections[ $name ] ) ) {
		return $state;
	}

	$entries = array_values( array_filter( (array) call_user_func( $sections[ $name ]['cb'] ), fn( $e ) => ! empty( $e['loc'] ) ) );
	$chunks  = array_chunk( $entries, OEC_SITEMAP_PER_FILE );
	$dir     = oec_seo_cache_dir();

	foreach ( glob( "{$dir}/{$name}-*.xml" ) ?: [] as $file ) {
		wp_delete_file( $file );
	}
	$lastmods = [];
	foreach ( $chunks as $i => $chunk ) {
		file_put_contents( "{$dir}/{$name}-" . ( $i + 1 ) . '.xml', oec_sitemap_urlset_xml( $chunk ), LOCK_EX );
		$dates             = array_filter( array_column( $chunk, 'lastmod' ) );
		$lastmods[ $i + 1 ] = $dates ? max( $dates ) : '';
	}

	// Se relee por si otra sección se generó en paralelo.
	$state          = oec_sitemap_state();
	$state[ $name ] = [ 'pages' => count( $chunks ), 'count' => count( $entries ), 'lastmod' => $lastmods ];
	return oec_sitemap_state( $state );
}

function oec_sitemap_section_xml( string $name, int $page ): ?string {
	if ( ! oec_seo_is_indexed_site() || ! isset( oec_sitemap_sections()[ $name ] ) ) {
		return null;
	}
	$file = oec_seo_cache_dir() . "/{$name}-{$page}.xml";
	if ( ! file_exists( $file ) ) {
		$state = oec_sitemap_state();
		if ( isset( $state[ $name ] ) && empty( $state[ $name ]['stale'] ) && $page > $state[ $name ]['pages'] ) {
			return null;
		}
		oec_sitemap_build( $name );
	}
	return file_exists( $file ) ? (string) file_get_contents( $file ) : null;
}

/** URL pública de una parte: sitemap-articulos.xml, sitemap-articulos-2.xml… */
function oec_sitemap_url( string $name, int $page = 1 ): string {
	return home_url( '/sitemap-' . $name . ( $page > 1 ? '-' . $page : '' ) . '.xml' );
}

/** Partes del sitemap del sitio actual: [[loc, lastmod], …]. */
function oec_sitemap_parts(): array {
	$state = oec_sitemap_state();
	$parts = [];
	foreach ( array_keys( oec_sitemap_sections() ) as $name ) {
		if ( ! isset( $state[ $name ] ) || ! empty( $state[ $name ]['stale'] ) ) {
			$state = oec_sitemap_build( $name );
		}
		for ( $p = 1; $p <= (int) ( $state[ $name ]['pages'] ?? 0 ); $p++ ) {
			$parts[] = [ oec_sitemap_url( $name, $p ), $state[ $name ]['lastmod'][ $p ] ?? '' ];
		}
	}
	return $parts;
}

/**
 * Índice. En un sitio con sitemap propio, sus partes. En la raíz
 * redirectora, las partes de cada sitio de la red (un índice no puede
 * contener otros índices, así que se listan las partes directamente).
 */
function oec_sitemap_index_xml(): ?string {
	$parts = [];
	if ( oec_seo_is_indexed_site() ) {
		$parts = oec_sitemap_parts();
	} elseif ( is_multisite() && is_main_site() ) {
		foreach ( oec_seo_sites() as $site ) {
			switch_to_blog( $site['id'] );
			// Solo lo ya generado por ese sitio (su cron lo mantiene al día).
			foreach ( oec_sitemap_state() as $name => $s ) {
				for ( $p = 1; $p <= (int) ( $s['pages'] ?? 0 ); $p++ ) {
					$parts[] = [ oec_sitemap_url( $name, $p ), $s['lastmod'][ $p ] ?? '' ];
				}
			}
			restore_current_blog();
		}
	}
	if ( ! $parts ) {
		return null;
	}

	$xml = oec_sitemap_xml_head() . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $parts as [ $loc, $lastmod ] ) {
		$xml .= "\t<sitemap>\n\t\t<loc>" . oec_xml( $loc ) . "</loc>\n";
		$xml .= $lastmod ? "\t\t<lastmod>" . oec_xml( $lastmod ) . "</lastmod>\n" : '';
		$xml .= "\t</sitemap>\n";
	}
	return $xml . "</sitemapindex>\n";
}

function oec_sitemap_urlset_xml( array $entries ): string {
	$xml  = oec_sitemap_xml_head();
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
	foreach ( $entries as $e ) {
		$xml .= "\t<url>\n\t\t<loc>" . oec_xml( $e['loc'] ) . "</loc>\n";
		if ( ! empty( $e['lastmod'] ) ) {
			$xml .= "\t\t<lastmod>" . oec_xml( $e['lastmod'] ) . "</lastmod>\n";
		}
		foreach ( array_filter( (array) ( $e['images'] ?? [] ) ) as $img ) {
			$xml .= "\t\t<image:image><image:loc>" . oec_xml( $img ) . "</image:loc></image:image>\n";
		}
		$xml .= "\t</url>\n";
	}
	return $xml . "</urlset>\n";
}

function oec_sitemap_xml_head(): string {
	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<?xml-stylesheet type="text/xsl" href="' . oec_xml( home_url( '/sitemap.xsl' ) ) . '"?>' . "\n";
}

function oec_xml( string $s ): string {
	return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/** Fecha MySQL en GMT → W3C (2026-09-25T14:46:44+00:00). */
function oec_sitemap_date( ?string $gmt ): string {
	return ( $gmt && '0000-00-00 00:00:00' !== $gmt ) ? gmdate( 'c', strtotime( $gmt . ' UTC' ) ) : '';
}

/* ── lastmod estable para lo que viene del catálogo ────────── */

/**
 * Devuelve la fecha en que cambió por última vez el contenido de $key
 * (según $hash). Si es nuevo o cambió, la fecha es "ahora" y la URL se
 * anota para avisar por IndexNow. Se persiste con oec_sitemap_lastmods_save().
 */
function oec_sitemap_lastmod( string $key, string $hash, string $loc ): string {
	global $oec_sitemap_lastmods, $oec_sitemap_changed;
	if ( null === $oec_sitemap_lastmods ) {
		$path                 = oec_seo_cache_dir() . '/lastmods.json';
		$oec_sitemap_lastmods = file_exists( $path ) ? (array) json_decode( (string) file_get_contents( $path ), true ) : [];
	}
	$prev = $oec_sitemap_lastmods[ $key ] ?? null;
	if ( $prev && $prev[0] === $hash ) {
		return $prev[1];
	}
	$now                          = gmdate( 'c' );
	$oec_sitemap_lastmods[ $key ] = [ $hash, $now ];
	$oec_sitemap_changed[]        = $loc;
	return $now;
}

/**
 * Hash del contenido, estable entre PHP web y CLI: los decimales (p. ej.
 * el promedio de opiniones) se pasan a texto antes, porque json_encode
 * los escribe según serialize_precision, que cambia de un php.ini a otro.
 */
function oec_sitemap_hash( $data ): string {
	if ( is_array( $data ) ) {
		array_walk_recursive( $data, function ( &$v ) {
			if ( is_float( $v ) ) {
				$v = number_format( $v, 4, '.', '' );
			}
		} );
	}
	return md5( (string) wp_json_encode( $data ) );
}

function oec_sitemap_lastmods_save(): void {
	global $oec_sitemap_lastmods, $oec_sitemap_changed;
	if ( null !== $oec_sitemap_lastmods ) {
		file_put_contents( oec_seo_cache_dir() . '/lastmods.json', wp_json_encode( $oec_sitemap_lastmods ), LOCK_EX );
	}
	if ( $oec_sitemap_changed ) {
		do_action( 'oec_seo_urls_changed', array_values( array_unique( $oec_sitemap_changed ) ) );
		$oec_sitemap_changed = [];
	}
}

/* ── Secciones ─────────────────────────────────────────────── */

/** Home + páginas publicadas, recorridas en profundidad (padre → hijas). */
function oec_sitemap_pages(): array {
	$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	$front    = $front_id ? get_post( $front_id ) : null;
	$out      = [ [
		'loc'     => home_url( '/' ),
		'lastmod' => oec_sitemap_date( $front ? $front->post_modified_gmt : get_lastpostmodified( 'GMT' ) ),
		'images'  => $front_id ? [ get_the_post_thumbnail_url( $front_id, 'full' ) ] : [],
	] ];

	$skip     = oec_sitemap_excluded_pages();
	$excluded = [];
	foreach ( get_pages( [ 'hierarchical' => true, 'sort_column' => 'menu_order, post_title' ] ) as $p ) {
		if ( $p->ID === $front_id ) {
			continue; // ya va como home
		}
		if ( isset( $excluded[ $p->post_parent ] ) || in_array( $p->post_name, $skip, true ) || post_password_required( $p ) ) {
			$excluded[ $p->ID ] = true;
			continue;
		}
		$out[] = [
			'loc'     => get_permalink( $p ),
			'lastmod' => oec_sitemap_date( $p->post_modified_gmt ),
			'images'  => [ get_the_post_thumbnail_url( $p, 'full' ) ],
		];
	}
	return $out;
}

/** Posts publicados, del más viejo al más nuevo: las partes viejas no cambian. */
function oec_sitemap_posts(): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_author, post_date, post_date_gmt, post_modified_gmt, post_name, post_parent, post_type, post_status
		 FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status = 'publish' AND post_password = ''
		 ORDER BY post_date ASC, ID ASC"
	);
	$thumbs = oec_sitemap_thumbnails( array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) );
	$out    = [];
	foreach ( $rows as $r ) {
		$out[] = [
			'loc'     => get_permalink( new WP_Post( $r ) ),
			'lastmod' => oec_sitemap_date( $r->post_modified_gmt ),
			'images'  => [ $thumbs[ (int) $r->ID ] ?? '' ],
		];
	}
	return $out;
}

/**
 * Imagen destacada de muchos posts con dos consultas (en vez de dos por
 * post): _thumbnail_id y _wp_attached_file. [post_id => url].
 */
function oec_sitemap_thumbnails( array $post_ids ): array {
	global $wpdb;
	$thumb_of = [];
	foreach ( array_chunk( $post_ids, 1000 ) as $ids ) {
		$in = implode( ',', $ids );
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN ($in)" ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- enteros
			$thumb_of[ (int) $m->post_id ] = (int) $m->meta_value;
		}
	}
	$files = [];
	foreach ( array_chunk( array_unique( array_filter( $thumb_of ) ), 1000 ) as $ids ) {
		$in = implode( ',', $ids );
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ($in)" ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- enteros
			$files[ (int) $m->post_id ] = $m->meta_value;
		}
	}
	$base = wp_upload_dir( null, false )['baseurl'];
	$out  = [];
	foreach ( $thumb_of as $post_id => $att ) {
		if ( isset( $files[ $att ] ) ) {
			$url              = preg_match( '#^https?://#', $files[ $att ] ) ? $files[ $att ] : $base . '/' . ltrim( $files[ $att ], '/' );
			$out[ $post_id ] = apply_filters( 'wp_get_attachment_url', $url, $att );
		}
	}
	return $out;
}

/** Último post modificado por término: [term_id => fecha GMT]. */
function oec_sitemap_term_lastmods( string $taxonomy ): array {
	global $wpdb;
	return wp_list_pluck( $wpdb->get_results( $wpdb->prepare(
		"SELECT tt.term_id, MAX(p.post_modified_gmt) AS lastmod
		 FROM {$wpdb->term_taxonomy} tt
		 JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
		 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'post' AND p.post_status = 'publish'
		 WHERE tt.taxonomy = %s
		 GROUP BY tt.term_id",
		$taxonomy
	), OBJECT_K ), 'lastmod' );
}

/** Categorías con posts, padre → hijas (mismo orden que un árbol). */
function oec_sitemap_categories(): array {
	$terms    = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true, 'orderby' => 'name' ] );
	$lastmods = oec_sitemap_term_lastmods( 'category' );
	if ( is_wp_error( $terms ) ) {
		return [];
	}
	$children = [];
	$ids      = wp_list_pluck( $terms, 'term_id' );
	foreach ( $terms as $t ) {
		// Una hija cuyo padre está vacío cuelga de la raíz.
		$parent                = in_array( $t->parent, $ids, true ) ? $t->parent : 0;
		$children[ $parent ][] = $t;
	}
	$out  = [];
	$walk = function ( int $parent ) use ( &$walk, &$out, $children, $lastmods ) {
		foreach ( $children[ $parent ] ?? [] as $t ) {
			$out[] = [ 'loc' => get_term_link( $t ), 'lastmod' => oec_sitemap_date( $lastmods[ $t->term_id ] ?? '' ) ];
			$walk( $t->term_id );
		}
	};
	$walk( 0 );
	return $out;
}

/**
 * Etiquetas con al menos OEC_SITEMAP_TAG_MIN posts. Las más chicas son
 * páginas finas: van con noindex (ver crawlers.php) y no se listan.
 */
function oec_sitemap_tags(): array {
	$terms    = get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => true, 'orderby' => 'name' ] );
	$lastmods = oec_sitemap_term_lastmods( 'post_tag' );
	if ( is_wp_error( $terms ) ) {
		return [];
	}
	$out = [];
	foreach ( $terms as $t ) {
		if ( $t->count >= OEC_SITEMAP_TAG_MIN ) {
			$out[] = [ 'loc' => get_term_link( $t ), 'lastmod' => oec_sitemap_date( $lastmods[ $t->term_id ] ?? '' ) ];
		}
	}
	return $out;
}

function oec_sitemap_authors(): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT post_author, MAX(post_modified_gmt) AS lastmod FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status = 'publish' AND post_password = ''
		 GROUP BY post_author"
	);
	$out = [];
	foreach ( $rows as $r ) {
		if ( get_userdata( (int) $r->post_author ) ) {
			$out[] = [ 'loc' => get_author_posts_url( (int) $r->post_author ), 'lastmod' => oec_sitemap_date( $r->lastmod ) ];
		}
	}
	return $out;
}

/* ── Catálogo (formaciones, docentes, organizaciones) ──────── */

/**
 * URL de la ficha en este sitio, a partir del canonical que manda la API
 * ('' si la formación es de otra comunidad). Hosts propios: el del sitio
 * + los del filtro oec_seo_own_hosts (en local se agrega el de producción,
 * y la URL se reescribe al host local para poder probar).
 */
function oec_sitemap_formation_loc( string $canonical ): string {
	$host = (string) wp_parse_url( $canonical, PHP_URL_HOST );
	$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $host || ! str_contains( (string) wp_parse_url( $canonical, PHP_URL_PATH ), '/formacion/' ) ) {
		return '';
	}
	if ( $host === $home ) {
		return $canonical;
	}
	if ( in_array( $host, (array) apply_filters( 'oec_seo_own_hosts', [] ), true ) ) {
		$origin = preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url() );
		return $origin . (string) wp_parse_url( $canonical, PHP_URL_PATH );
	}
	return '';
}

function oec_sitemap_formations( bool $open ): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) ) {
		return [];
	}
	// Solo las del token principal y de este sitio: las de otra comunidad están en el sitemap de su dominio.
	$rows = array_filter( OEC_AI_Catalog::get_listing(), fn( $r ) => (bool) $r['open'] === $open && ! oec_formation_is_external( $r ) );
	// Abiertas: las que cierran antes, primero. Cerradas: más recientes primero.
	usort( $rows, $open
		? fn( $a, $b ) => strcmp( $a['enrollment_end'], $b['enrollment_end'] )
		: fn( $a, $b ) => strcmp( $b['start'], $a['start'] ) );

	$out = [];
	foreach ( $rows as $r ) {
		$f   = OEC_AI_Catalog::get_formation( (string) $r['id'] );
		$loc = $f ? oec_sitemap_formation_loc( (string) ( $f['url'] ?? '' ) ) : '';
		if ( ! $loc ) {
			continue;
		}
		unset( $f['relevance'] ); // ranking de la API: cambia sin que cambie la ficha
		$out[] = [
			'loc'     => $loc,
			'lastmod' => oec_sitemap_lastmod( 'f:' . $r['id'], oec_sitemap_hash( [ $f, $open ] ), $loc ),
			'images'  => [ $r['image'] ?? '' ],
		];
	}
	oec_sitemap_lastmods_save();
	return $out;
}

function oec_sitemap_formations_open(): array {
	return oec_sitemap_formations( true );
}

function oec_sitemap_formations_closed(): array {
	return oec_sitemap_formations( false );
}

function oec_sitemap_docentes(): array {
	if ( ! function_exists( 'oec_docentes_catalog' ) ) {
		return [];
	}
	$base = oec_docente_url();
	$out  = [];
	foreach ( oec_docentes_catalog() as $d ) {
		$loc   = add_query_arg( 'slug', $d['slug'], $base );
		$hash  = oec_sitemap_hash( [ $d['name'], $d['bio'], $d['background'], $d['photo'], array_column( $d['items'], 'open', 'id' ) ] );
		$out[] = [
			'loc'     => $loc,
			'lastmod' => oec_sitemap_lastmod( 'd:' . $d['slug'], $hash, $loc ),
			'images'  => [ $d['photo'] ? oec_docente_photo_url( $d['photo'], 800 ) : '' ],
		];
	}
	oec_sitemap_lastmods_save();
	return $out;
}

function oec_sitemap_organizations(): array {
	if ( ! class_exists( 'OEC_AI_Catalog' ) || ! function_exists( 'oec_organizacion_url' ) ) {
		return [];
	}
	$base = oec_organizacion_url();
	$out  = [];
	foreach ( OEC_AI_Catalog::get_organizations( 0 ) as $o ) {
		$detail = OEC_AI_Catalog::get_organization( (string) $o['slug'] );
		if ( ! $detail ) {
			continue;
		}
		$loc   = add_query_arg( 'slug', $o['slug'], $base );
		$out[] = [
			'loc'     => $loc,
			'lastmod' => oec_sitemap_lastmod( 'o:' . $o['slug'], oec_sitemap_hash( $detail ), $loc ),
			'images'  => [ $o['logo'] ?? '' ],
		];
	}
	oec_sitemap_lastmods_save();
	return $out;
}

/* ── Hoja de estilo: el XML se ve como tabla en el navegador ── */

function oec_sitemap_xsl(): string {
	$title = oec_xml( get_bloginfo( 'name' ) );
	return <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html lang="es"><head><meta charset="UTF-8"/><meta name="robots" content="noindex"/>
<title>Sitemap — {$title}</title>
<style>
body{font:15px/1.5 system-ui,sans-serif;margin:0;color:#071b2d;background:#f6f8fb}
header{background:#194872;color:#fff;padding:24px 16px}header h1{margin:0;font-size:22px}header p{margin:4px 0 0;opacity:.8}
main{max-width:1100px;margin:0 auto;padding:16px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden}
th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #e6ebf1;word-break:break-all}
th{background:#eef2f7;font-size:13px}td.n{white-space:nowrap;word-break:normal;color:#5b6b7c;font-size:13px}
a{color:#194872;text-decoration:none}a:hover{text-decoration:underline}
</style></head><body>
<header><h1>Sitemap — {$title}</h1>
<xsl:choose>
<xsl:when test="s:sitemapindex"><p><xsl:value-of select="count(s:sitemapindex/s:sitemap)"/> sitemaps</p></xsl:when>
<xsl:otherwise><p><xsl:value-of select="count(s:urlset/s:url)"/> URLs</p></xsl:otherwise>
</xsl:choose>
</header>
<main><table>
<xsl:choose>
<xsl:when test="s:sitemapindex">
<tr><th>Sitemap</th><th>Última modificación</th></tr>
<xsl:for-each select="s:sitemapindex/s:sitemap">
<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td class="n"><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr>
</xsl:for-each>
</xsl:when>
<xsl:otherwise>
<tr><th>URL</th><th>Imágenes</th><th>Última modificación</th></tr>
<xsl:for-each select="s:urlset/s:url">
<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td class="n"><xsl:value-of select="count(image:image)"/></td><td class="n"><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr>
</xsl:for-each>
</xsl:otherwise>
</xsl:choose>
</table></main></body></html>
</xsl:template>
</xsl:stylesheet>
XSL;
}

/* ── Aviso si un plugin SEO también genera sitemap/robots/llms ── */

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$plugin = defined( 'AIOSEO_VERSION' ) ? 'All in One SEO'
		: ( defined( 'WPSEO_VERSION' ) ? 'Yoast SEO'
		: ( class_exists( 'RankMath' ) ? 'Rank Math' : '' ) );
	if ( ! $plugin ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html( sprintf(
			/* translators: %s: nombre del plugin SEO */
			__( 'El tema OEC ya genera sitemap.xml, robots.txt y llms.txt (se actualizan solos). Desactivá esos módulos en %s para que no compitan por las mismas URLs.', 'oec-theme' ),
			$plugin
		) )
	);
} );
