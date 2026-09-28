<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SITIOS DE SOCIOS — webs de docentes e instituciones, sincronizadas
   con la plataforma. Sección discreta, pensada para el cierre de la página.

   [oec-sitios tematica="" variante="" cta-url="https://onlineeducation.center/es"]
     [oec-sitio url="https://francisholway.site" name="Francis Holway" tematicas="nutricion-deportiva" rol="Nutrición deportiva" org="francis-holway" docente="francis-holway" image="https://…/sitio-francisholway.jpg"]
     [oec-sitio …]
   [/oec-sitios]

   - variante="grande": grilla de portadas grandes + banner de invitación al
     servicio (la usa /organizaciones/, donde el público son instituciones).
     Sin variante: tira discreta de miniaturas.
   - org / docente: slug de la organización / del docente en el catálogo.
     La lista vive en el contenido de la página "organizaciones"
     (page-organizaciones.php la muestra al final) y de ahí sale el botón
     "Visitar su sitio web" de las landings de organización y de docente
     (oec_sitio_de()).

   - tematica (opcional): muestra solo los sitios con ese slug en su
     "tematicas" (lista separada por comas) — para las landings.
   - Tira horizontal de miniaturas chicas (marco de navegador con el
     dominio), en orden que rota a diario. "image" es una captura subida a
     la biblioteca de medios; si falta, se usa la captura automática de
     WordPress.com (mShots).
   - Al lado del título, un link discreto al servicio para organizaciones.
   ============================================================ */

// [oec-sitio] solo tiene sentido dentro de [oec-sitios]; suelto no imprime nada.
add_shortcode( 'oec-sitio', '__return_empty_string' );

add_shortcode( 'oec-sitios', 'oec_render_sitios_shortcode' );
function oec_render_sitios_shortcode( $atts, $content = '' ): string {
	$atts = shortcode_atts( [
		'title'    => __( 'Webs de nuestros socios', 'oec-theme' ),
		'tematica' => '',
		'variante' => '',
		'cta-url'  => '',
	], $atts, 'oec-sitios' );
	$grande = 'grande' === $atts['variante'];

	$sitios = [];
	if ( preg_match_all( '/' . get_shortcode_regex( [ 'oec-sitio' ] ) . '/s', (string) $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $sc ) {
			$a = shortcode_atts( [ 'url' => '', 'name' => '', 'rol' => '', 'image' => '', 'tematicas' => '', 'org' => '', 'docente' => '' ], shortcode_parse_atts( $sc[3] ) ?: [] );
			if ( ! $a['url'] || ! $a['name'] ) {
				continue;
			}
			$tems = array_filter( array_map( 'sanitize_title', explode( ',', $a['tematicas'] ) ) );
			if ( $atts['tematica'] && ! in_array( sanitize_title( $atts['tematica'] ), $tems, true ) ) {
				continue;
			}
			$a['domain'] = preg_replace( '/^www\./', '', (string) wp_parse_url( $a['url'], PHP_URL_HOST ) );
			$a['image']  = $a['image'] ?: 'https://s.wordpress.com/mshots/v1/' . rawurlencode( $a['url'] ) . '?w=1200&h=750';
			$sitios[]    = $a;
		}
	}
	if ( ! $sitios ) {
		return '';
	}

	// Orden que rota a diario, para que no sean siempre los mismos al principio.
	mt_srand( crc32( 'sitios' . current_time( 'Y-m-d' ) . $atts['tematica'] ) );
	shuffle( $sitios );
	mt_srand();

	ob_start();
	?>
	<section class="oec-sitios<?php echo $grande ? ' oec-sitios--grande' : ''; ?>" aria-labelledby="oec-sitios-title">
		<div class="oec-sitios__head">
			<div>
				<h2 class="oec-sitios__title" id="oec-sitios-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="oec-sitios__sub">
					<?php
					printf(
						/* translators: %s: cantidad de sitios */
						esc_html( _n( '%s web de docentes e instituciones que enseñan con nosotros, sincronizada con nuestra plataforma.', '%s webs de docentes e instituciones que enseñan con nosotros, sincronizadas con nuestra plataforma.', count( $sitios ), 'oec-theme' ) ),
						esc_html( number_format_i18n( count( $sitios ) ) )
					);
					?>
				</p>
			</div>
			<?php if ( $atts['cta-url'] && ! $grande ) : ?>
			<a class="oec-sitios__cta" href="<?php echo esc_url( $atts['cta-url'] ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( '¿Querés una web así para tu institución?', 'oec-theme' ); ?> <strong><?php esc_html_e( 'Conocé el servicio', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></strong>
			</a>
			<?php endif; ?>
		</div>

		<div class="<?php echo $grande ? 'oec-sitios__grid' : 'oec-sitios__track'; ?>">
			<?php foreach ( $sitios as $s ) : ?>
			<a class="oec-sitio" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $s['name'] . ( $s['rol'] ? ' — ' . $s['rol'] : '' ) ); ?>">
				<span class="oec-sitio__browser">
					<span class="oec-sitio__bar" aria-hidden="true">
						<span class="oec-sitio__dots"><i></i><i></i><i></i></span>
						<span class="oec-sitio__url"><?php echo esc_html( $s['domain'] ); ?></span>
					</span>
					<span class="oec-sitio__shot">
						<?php
						// Si la captura está en la biblioteca de medios, que WordPress arme el
						// srcset (400/800 px): la miniatura se ve a ~210 px de ancho.
						$alt    = sprintf( __( 'Portada del sitio de %s', 'oec-theme' ), $s['name'] );
						$att_id = attachment_url_to_postid( $s['image'] );
						if ( $att_id ) {
							echo wp_get_attachment_image( $att_id, 'medium_large', false, [ 'alt' => $alt, 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => $grande ? '(max-width: 640px) 82vw, (max-width: 1000px) 45vw, 400px' : '(max-width: 640px) 170px, 210px' ] ); // phpcs:ignore
						} else {
							printf( '<img src="%s" alt="%s" loading="lazy" decoding="async" width="600" height="375">', esc_url( $s['image'] ), esc_attr( $alt ) );
						}
						?>
					</span>
				</span>
				<span class="oec-sitio__name"><?php echo esc_html( $s['name'] ); ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span>
				<?php if ( $s['rol'] ) : ?><span class="oec-sitio__rol"><?php echo esc_html( $s['rol'] ); ?></span><?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>

		<?php if ( $atts['cta-url'] && $grande ) : ?>
		<div class="oec-sitios__banner">
			<span class="oec-sitios__banner-icon" aria-hidden="true"><i class="bi bi-magic"></i></span>
			<div class="oec-sitios__banner-text">
				<strong><?php esc_html_e( '¿Sos docente o institución y querés una web así?', 'oec-theme' ); ?></strong>
				<span><?php esc_html_e( 'Te diseñamos una web profesional conectada a nuestra plataforma, con tus formaciones siempre al día.', 'oec-theme' ); ?></span>
			</div>
			<a class="btn oec-sitios__banner-btn" href="<?php echo esc_url( $atts['cta-url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Conocé el servicio', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
		</div>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Sitio web de una organización o de un docente, según la lista de
 * [oec-sitio] cargada en la página "organizaciones". Devuelve
 * ['url' => …, 'domain' => …] o null. $tipo: 'org' | 'docente'.
 */
function oec_sitio_de( string $tipo, string $slug ): ?array {
	static $mapa = null;
	if ( null === $mapa ) {
		$mapa = [ 'org' => [], 'docente' => [] ];
		$page = get_page_by_path( 'organizaciones' );
		if ( $page && preg_match_all( '/' . get_shortcode_regex( [ 'oec-sitio' ] ) . '/s', (string) $page->post_content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $sc ) {
				$a = shortcode_parse_atts( $sc[3] ) ?: [];
				if ( empty( $a['url'] ) ) {
					continue;
				}
				$sitio = [ 'url' => $a['url'], 'domain' => preg_replace( '/^www\./', '', (string) wp_parse_url( $a['url'], PHP_URL_HOST ) ) ];
				foreach ( [ 'org', 'docente' ] as $t ) {
					if ( ! empty( $a[ $t ] ) ) {
						$mapa[ $t ][ sanitize_title( $a[ $t ] ) ] = $sitio;
					}
				}
			}
		}
	}
	return $mapa[ $tipo ][ $slug ] ?? null;
}
