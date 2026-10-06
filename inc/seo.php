<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SEO / AEO — metadatos de TODAS las páginas desde un solo lugar

   Por cada request se arma un "contexto" (oec_seo_context()) y de ahí
   salen, sin duplicados:
     - <link rel="canonical">
     - meta description
     - Open Graph (og:*) y Twitter Card — lo que usan WhatsApp, LinkedIn,
       Facebook, X, Slack… para la vista previa al compartir
     - article:* y JSON-LD (Article/BlogPosting en los posts;
       EducationalOrganization + WebSite en el home)

   Valores por defecto según el tipo de página (home, post, página,
   categoría, etiqueta, autor). Las secciones con datos propios (landings,
   especiales, docentes, organizaciones, listados) ajustan el contexto con
   el filtro 'oec_seo' en vez de imprimir sus propias etiquetas:

     add_filter( 'oec_seo', fn( $c ) => array_merge( $c, [ 'description' => '…' ] ) );

   Claves: title, description, url (og:url), canonical (false = no emitir),
   image (ID de adjunto o URL), type (website|article|profile),
   article (published, modified, section, tags), schema (nodos JSON-LD).
   Devolver null en el filtro = la página se ocupa sola (p. ej. la ficha
   de formación, que la resuelve el plugin).

   Imagen para compartir: una versión JPEG de 1200×630 de la imagen
   destacada (oec_seo_share_image()). WhatsApp no muestra vista previa si
   la imagen pesa más de ~300 KB, y las destacadas suelen ser PNG de 1 MB+.
   ============================================================ */

// WordPress arma "Título – Descripción corta" en el home; la descripción
// corta ya va como meta description y es demasiado larga para un title.
add_filter( 'document_title_parts', function ( array $parts ): array {
	if ( is_front_page() ) {
		unset( $parts['tagline'] );
	}
	return $parts;
} );

// Extracto también en las páginas: es su meta description si se completa.
add_action( 'init', function () {
	add_post_type_support( 'page', 'excerpt' );
} );

/** Texto plano de hasta ~$max caracteres, cortado en una palabra. */
function oec_seo_trim( string $text, int $max = 158 ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$sp  = mb_strrpos( $cut, ' ' );
	return rtrim( $sp ? mb_substr( $cut, 0, $sp ) : $cut, " ,.;:–-" ) . '…';
}

/**
 * Descripción de un post/página: el extracto si lo tiene; si no, los
 * primeros párrafos del contenido (no los títulos ni el aviso legal).
 * Las páginas "aplicación" (HTML/CSS crudo) no tienen texto utilizable.
 */
function oec_seo_post_description( WP_Post $post ): string {
	if ( has_excerpt( $post ) ) {
		return oec_seo_trim( $post->post_excerpt );
	}
	$html = $post->post_content;
	if ( '' === trim( $html ) || false !== stripos( $html, '<style' ) ) {
		return '';
	}
	$html = preg_replace( '#<div[^>]*id="post_(disclaimer|buttons)".*?</div>#is', '', strip_shortcodes( $html ) );
	$text = preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $m ) ? implode( ' ', $m[1] ) : $html;
	return oec_seo_trim( $text );
}

/**
 * Imagen para compartir: JPEG 1200×630 (recorte centrado) generado una vez
 * a partir del adjunto y guardado junto al original. Devuelve
 * [url, ancho, alto, alt] o null. Si no se puede generar, el tamaño "large".
 */
function oec_seo_share_image( int $att_id ): ?array {
	if ( ! $att_id || ! wp_attachment_is_image( $att_id ) ) {
		return null;
	}
	$alt  = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
	$meta = wp_get_attachment_metadata( $att_id );
	$file = get_attached_file( $att_id );
	$og   = $meta['oec_og'] ?? null;

	if ( ! $og || ! file_exists( path_join( dirname( $file ), $og['file'] ) ) ) {
		$og = null;
		if ( $file && file_exists( $file ) ) {
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
				$size = $editor->get_size();
				// Si es más chica que 1200×630, se recorta a 1,91:1 sin agrandar.
				$w = min( 1200, $size['width'], (int) round( $size['height'] * 1.905 ) );
				$h = (int) round( $w / 1.905 );
				$editor->resize( $w, $h, true );
				$editor->set_quality( 82 );
				$dest  = $editor->generate_filename( 'og', null, 'jpg' );
				$saved = $editor->save( $dest, 'image/jpeg' );
				if ( ! is_wp_error( $saved ) ) {
					$og = [ 'file' => wp_basename( $saved['path'] ), 'width' => $saved['width'], 'height' => $saved['height'] ];
					$meta['oec_og'] = $og;
					wp_update_attachment_metadata( $att_id, $meta );
				}
			}
		}
	}
	if ( $og ) {
		$url = path_join( dirname( wp_get_attachment_url( $att_id ) ), $og['file'] );
		return [ $url, (int) $og['width'], (int) $og['height'], $alt ];
	}
	$large = wp_get_attachment_image_src( $att_id, 'large' );
	return $large ? [ $large[0], (int) $large[1], (int) $large[2], $alt ] : null;
}

// El JPEG para compartir se borra junto con el adjunto.
add_action( 'delete_attachment', function ( $att_id ) {
	$meta = wp_get_attachment_metadata( $att_id );
	$file = get_attached_file( $att_id );
	if ( ! empty( $meta['oec_og']['file'] ) && $file ) {
		wp_delete_file( path_join( dirname( $file ), $meta['oec_og']['file'] ) );
	}
} );

// Al publicar/actualizar un post con destacada, se genera ya (no en la
// primera visita, que suele ser justamente el bot de WhatsApp).
add_action( 'save_post', function ( $post_id, $post ) {
	if ( 'publish' === $post->post_status && ! wp_is_post_revision( $post_id ) && has_post_thumbnail( $post ) ) {
		oec_seo_share_image( (int) get_post_thumbnail_id( $post ) );
	}
}, 20, 2 );

/** Imagen por defecto: la destacada del home, si no el logo o el ícono del sitio. */
function oec_seo_default_image() {
	$front = (int) get_option( 'page_on_front' );
	if ( $front && has_post_thumbnail( $front ) ) {
		return (int) get_post_thumbnail_id( $front );
	}
	return (int) get_theme_mod( 'custom_logo' ) ?: get_site_icon_url( 512 );
}

/** URL de la página N de un archivo (categoría, etiqueta, autor). */
function oec_seo_paged_url( string $base, int $paged ): string {
	return $paged > 1 ? user_trailingslashit( trailingslashit( $base ) . 'page/' . $paged, 'paged' ) : $base;
}

/** Nodo Organization del sitio (publisher de los artículos). */
function oec_seo_organization_node(): array {
	$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' )
		?: ( function_exists( 'oec_get_options' ) ? ( oec_get_options()['logo_url'] ?? '' ) : '' )
		?: get_site_icon_url( 512 );
	// Perfiles sociales (Ajustes del tema): con sameAs, Google y los asistentes
	// de IA atan el sitio a la misma entidad que esas cuentas.
	$opts   = function_exists( 'oec_get_options' ) ? oec_get_options() : [];
	$same   = array_values( array_filter( array_map(
		fn( $k ) => esc_url_raw( (string) ( $opts[ $k ] ?? '' ) ),
		[ 'social_linkedin', 'social_instagram', 'social_facebook', 'social_youtube', 'social_x' ]
	) ) );
	return array_filter( [
		'@type'  => 'EducationalOrganization',
		'@id'    => home_url( '/' ) . '#organization',
		'name'   => get_bloginfo( 'name' ),
		'url'    => home_url( '/' ),
		'logo'   => $logo ? [ '@type' => 'ImageObject', 'url' => $logo ] : null,
		'sameAs' => $same ?: null,
	] );
}

/**
 * Autor de un artículo para el JSON-LD. Muchos "autores" de WordPress son en
 * realidad revistas, bases de datos o canales de los que se importa
 * (PubMed, PLOS ONE, Nutrients, Kronos, BPA Podcast…): declararlos como
 * Person es un dato falso para Google. Esos van como Organization; "G-SE" y
 * "Autores Varios" apuntan a la organización del sitio; el resto, Person.
 * Filtro 'oec_seo_author_type' (user_id, nombre) → 'person' | 'organization' | 'site'.
 */
function oec_seo_author_node( int $user_id ): array {
	$name = (string) get_the_author_meta( 'display_name', $user_id );
	$url  = get_author_posts_url( $user_id );
	$type = 'person';
	if ( preg_match( '/^(g-se\b|grupo sobre entrenamiento|autores varios)/i', $name ) ) {
		$type = 'site';
	} elseif ( preg_match( '/\b(revista|journal|plos ?one|pubmed|publice|kronos|gymnasium|nutrients|sports? medicine|springer|human kinetics|podcast|team|blogs?|my sport science|sport tips|bmc)\b/i', $name ) ) {
		$type = 'organization';
	}
	$type = apply_filters( 'oec_seo_author_type', $type, $user_id, $name );

	if ( 'site' === $type ) {
		return [ '@id' => home_url( '/' ) . '#organization' ];
	}
	return [
		'@type' => 'organization' === $type ? 'Organization' : 'Person',
		'name'  => $name,
		'url'   => $url,
	];
}

/**
 * Contexto SEO de la página actual (cacheado). null = no emitir nada.
 */
function oec_seo_context(): ?array {
	static $ctx = false;
	if ( false !== $ctx ) {
		return $ctx;
	}
	$ctx = null;
	if ( is_admin() || is_feed() || is_404() || is_search() ) {
		return $ctx;
	}

	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$c     = [ 'type' => 'website', 'image' => oec_seo_default_image(), 'schema' => [] ];

	if ( is_front_page() ) {
		// El extracto de la página de inicio si se completó; si no, la
		// descripción corta del sitio. (El contenido del home es HTML de
		// aplicación, no sirve como resumen.)
		$front = get_queried_object();
		$desc  = $front instanceof WP_Post && has_excerpt( $front )
			? oec_seo_trim( $front->post_excerpt, 300 )
			: oec_seo_trim( get_bloginfo( 'description' ), 300 );
		// Meta description: Google muestra ~155 caracteres. El texto largo
		// queda para el JSON-LD de la organización y del sitio.
		$meta_desc = oec_seo_trim( $desc, 158 );
		$c    = array_merge( $c, [
			'title'       => get_bloginfo( 'name' ),
			'description' => $meta_desc,
			'url'         => home_url( '/' ),
		] );
		$c['schema'][] = array_merge( oec_seo_organization_node(), array_filter( [ 'description' => $desc ] ) );
		$c['schema'][] = array_filter( [
			'@type'       => 'WebSite',
			'@id'         => home_url( '/' ) . '#website',
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'description' => $desc,
			'inLanguage'  => get_bloginfo( 'language' ),
			'publisher'   => [ '@id' => home_url( '/' ) . '#organization' ],
		] );
	} elseif ( is_singular() ) {
		$post = get_queried_object();
		// La ficha de formación (/formacion/…) la resuelve entera el plugin.
		if ( ! $post instanceof WP_Post || is_page( 'formacion' ) ) {
			return $ctx;
		}
		$c = array_merge( $c, [
			'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'description' => oec_seo_post_description( $post ),
			'url'         => wp_get_canonical_url( $post ) ?: get_permalink( $post ),
		] );
		if ( has_post_thumbnail( $post ) ) {
			$c['image'] = (int) get_post_thumbnail_id( $post );
		}
		if ( 'post' === $post->post_type ) {
			$c['type']    = 'article';
			$cats         = get_the_category( $post->ID );
			$tematicas    = array_values( array_filter(
				$cats,
				fn( $t ) => ! in_array( $t->slug, [ 'articulos', 'blogs', 'vigia', 'general', 'sin-categoria', 'uncategorized' ], true )
			) );
			$c['article'] = [
				'published' => get_post_time( 'c', true, $post ),
				'modified'  => get_post_modified_time( 'c', true, $post ),
				'section'   => $tematicas ? $tematicas[0]->name : '',
				'tags'      => wp_list_pluck( get_the_tags( $post->ID ) ?: [], 'name' ),
			];
			$c['post'] = $post;
			$c['blog'] = in_array( 'blogs', wp_list_pluck( $cats, 'slug' ), true );
		}
	} elseif ( is_category() || is_tag() ) {
		$term  = get_queried_object();
		$label = is_category() && function_exists( 'oec_tipo_labels_plural' )
			? ( oec_tipo_labels_plural()[ $term->slug ] ?? $term->name )
			: $term->name;
		$desc  = oec_seo_trim( term_description( $term ) ) ?: sprintf(
			/* translators: %s: categoría o etiqueta */
			__( 'Artículos científicos, blogs y resúmenes sobre %s para profesionales de las ciencias del ejercicio.', 'oec-theme' ),
			$label
		);
		$c = array_merge( $c, [
			'title'       => is_tag() ? '#' . $label : $label,
			'description' => $desc,
			'url'         => oec_seo_paged_url( get_term_link( $term ), $paged ),
		] );
	} elseif ( is_author() ) {
		$user = get_queried_object();
		$name = $user->display_name;
		$c    = array_merge( $c, [
			'type'        => 'profile',
			'title'       => $name,
			/* translators: %s: nombre del autor */
			'description' => oec_seo_trim( get_the_author_meta( 'description', $user->ID ) ) ?: sprintf( __( 'Artículos y blogs publicados por %s.', 'oec-theme' ), $name ),
			'url'         => oec_seo_paged_url( get_author_posts_url( $user->ID ), $paged ),
		] );
	} else {
		return $ctx;
	}

	$c   = apply_filters( 'oec_seo', $c );
	$ctx = is_array( $c ) ? $c : null;
	return $ctx;
}

// El plugin OEC imprime otro Organization + WebSite (más pobre) en todas las
// páginas; el nuestro ya los trae, con @id para que el resto los referencie.
add_action( 'init', function () {
	remove_action( 'wp_head', 'oec_site_jsonld', 5 );
} );

// Ficha de formación: el plugin arma todo, pero su canonical dependía del
// rel_canonical del core, que en producción no sale. Se imprime acá, con la
// misma URL (data.canonical de la API → la comunidad dueña de la formación).
add_action( 'wp_head', function () {
	if ( is_page( 'formacion' ) && function_exists( 'oec_get_current_training_canonical' )
		&& function_exists( 'oec_get_current_training_data' ) && oec_get_current_training_data() ) {
		remove_action( 'wp_head', 'rel_canonical' );
		echo '<link rel="canonical" href="' . esc_url( oec_get_current_training_canonical() ) . '">' . "\n";
	}
}, 2 );

// Antes que el rel_canonical de WordPress (10): si emitimos el nuestro, se saca el suyo.
add_action( 'wp_head', 'oec_seo_head', 2 );
function oec_seo_head(): void {
	$c = oec_seo_context();
	if ( ! $c ) {
		return;
	}
	$e     = fn( $v ) => esc_attr( $v );
	$title = $c['title'] ?? '';
	$desc  = $c['description'] ?? '';
	$url   = $c['url'] ?? '';
	$canon = array_key_exists( 'canonical', $c ) ? $c['canonical'] : $url;

	$img = null;
	if ( is_int( $c['image'] ?? null ) ) {
		$img = oec_seo_share_image( $c['image'] );
	} elseif ( ! empty( $c['image'] ) ) {
		$att = attachment_url_to_postid( $c['image'] );
		$img = $att ? oec_seo_share_image( $att ) : [ $c['image'], 0, 0, '' ];
	}

	echo "\n";
	if ( $canon ) {
		remove_action( 'wp_head', 'rel_canonical' );
		echo '<link rel="canonical" href="' . esc_url( $canon ) . '">' . "\n";
	}
	if ( $desc ) {
		echo '<meta name="description" content="' . $e( $desc ) . '">' . "\n";
	}
	echo '<meta property="og:type" content="' . $e( $c['type'] ) . '">' . "\n";
	echo '<meta property="og:locale" content="' . $e( get_locale() ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . $e( get_bloginfo( 'name' ) ) . '">' . "\n";
	if ( $title ) {
		echo '<meta property="og:title" content="' . $e( $title ) . '">' . "\n";
	}
	if ( $desc ) {
		echo '<meta property="og:description" content="' . $e( $desc ) . '">' . "\n";
	}
	if ( $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	}
	if ( $img ) {
		echo '<meta property="og:image" content="' . esc_url( $img[0] ) . '">' . "\n";
		if ( $img[1] && $img[2] ) {
			echo '<meta property="og:image:width" content="' . (int) $img[1] . '">' . "\n";
			echo '<meta property="og:image:height" content="' . (int) $img[2] . '">' . "\n";
		}
		echo '<meta property="og:image:alt" content="' . $e( $img[3] ?: $title ) . '">' . "\n";
	}
	if ( ! empty( $c['article'] ) ) {
		$a = $c['article'];
		echo '<meta property="article:published_time" content="' . $e( $a['published'] ) . '">' . "\n";
		echo '<meta property="article:modified_time" content="' . $e( $a['modified'] ) . '">' . "\n";
		if ( ! empty( $a['section'] ) ) {
			echo '<meta property="article:section" content="' . $e( $a['section'] ) . '">' . "\n";
		}
		foreach ( $a['tags'] ?? [] as $tag ) {
			echo '<meta property="article:tag" content="' . $e( $tag ) . '">' . "\n";
		}
	}
	echo '<meta name="twitter:card" content="' . ( $img ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	if ( $title ) {
		echo '<meta name="twitter:title" content="' . $e( $title ) . '">' . "\n";
	}
	if ( $desc ) {
		echo '<meta name="twitter:description" content="' . $e( $desc ) . '">' . "\n";
	}
	if ( $img ) {
		echo '<meta name="twitter:image" content="' . esc_url( $img[0] ) . '">' . "\n";
	}

	// JSON-LD del artículo.
	if ( ! empty( $c['post'] ) ) {
		$post    = $c['post'];
		$images  = [];
		if ( $img ) {
			$images[] = $img[0];
		}
		if ( has_post_thumbnail( $post ) && ( $full = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'full' ) ) ) {
			$images[] = $full;
		}
		$c['schema'][] = array_filter( [
			'@type'            => ! empty( $c['blog'] ) ? 'BlogPosting' : 'Article',
			'@id'              => $url . '#article',
			'headline'         => mb_substr( $title, 0, 110 ),
			'description'      => $desc ?: null,
			'image'            => array_values( array_unique( $images ) ) ?: null,
			'datePublished'    => $c['article']['published'],
			'dateModified'     => $c['article']['modified'],
			'inLanguage'       => get_bloginfo( 'language' ),
			'mainEntityOfPage' => $url,
			'articleSection'   => $c['article']['section'] ?: null,
			'keywords'         => $c['article']['tags'] ? implode( ', ', $c['article']['tags'] ) : null,
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'author'           => oec_seo_author_node( (int) $post->post_author ),
			'publisher'        => oec_seo_organization_node(),
		] );
	}
	if ( $c['schema'] ) {
		$graph = count( $c['schema'] ) > 1
			? [ '@context' => 'https://schema.org', '@graph' => $c['schema'] ]
			: array_merge( [ '@context' => 'https://schema.org' ], $c['schema'][0] );
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
	}
}
