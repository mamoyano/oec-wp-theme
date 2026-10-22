<?php
/**
 * Template Name: Campaña (Cyber, Black Friday…)
 *
 * Landing de una campaña de descuentos: /especiales/{slug} (ver
 * inc/campaign.php, que la crea al guardar Tema OEC → Campañas). Tres
 * estados: "se viene" (cuenta regresiva al inicio + newsletter), "en
 * curso" (formaciones con su %, cuenta regresiva al fin) y "terminó". Una
 * landing de una campaña anterior queda mostrando "terminó".
 */

$oec_cmp_actual = oec_cmp_is_current_page();
$oec_cmp_o      = oec_cmp_opts();
$oec_cmp_phase  = $oec_cmp_actual ? ( oec_cmp_view_phase() ?: 'off' ) : 'ended';
if ( 'off' === $oec_cmp_phase ) {
	$oec_cmp_phase = 'teaser'; // desactivada o antes del "se viene": se ve como "se viene" (para revisarla)
}
$oec_cmp_name  = $oec_cmp_actual ? (string) $oec_cmp_o['name'] : get_the_title();
$oec_cmp_t     = oec_cmp_times( $oec_cmp_o );
$oec_cmp_rows  = $oec_cmp_actual && 'ended' !== $oec_cmp_phase ? oec_cmp_formaciones( $oec_cmp_phase ) : [];
$oec_cmp_max   = oec_cmp_max_pct( $oec_cmp_rows );
$oec_cmp_n     = count( $oec_cmp_rows );
$oec_cmp_dias  = oec_cmp_dias_texto( $oec_cmp_o );
$oec_cmp_dur   = oec_cmp_duracion_texto( $oec_cmp_o );
$oec_cmp_fin   = oec_cmp_fin_texto( $oec_cmp_o );
$oec_cmp_live  = 'live' === $oec_cmp_phase;

// Filtros: temáticas de las landings (en su orden) y los % que hay.
$oec_cmp_temas = [];
foreach ( function_exists( 'oec_especiales_registradas' ) ? oec_especiales_registradas() : [] as $e ) {
	$n = count( array_filter( $oec_cmp_rows, fn( $r ) => in_array( $e['tematica'], $r['tags'], true ) ) );
	if ( $n ) {
		$oec_cmp_temas[ $e['tematica'] ] = [ $e['title'], $n ];
	}
}
$oec_cmp_pcts = $oec_cmp_live ? array_values( array_unique( array_filter( array_column( $oec_cmp_rows, 'cmp_pct' ) ) ) ) : [];
rsort( $oec_cmp_pcts );

$oec_cmp_chat = $oec_cmp_live
	? __( 'Hola, ¿qué formación del Cyber me recomiendas?', 'oec-theme' )
	: __( 'Hola, ¿qué formaciones van a estar en el Cyber?', 'oec-theme' );

$oec_cmp_faq = [
	[
		sprintf( '¿Qué es el %s?', $oec_cmp_name ),
		sprintf( 'Son %s de descuentos fuertes en formaciones seleccionadas junto a nuestros socios educativos: %s. Es una promo corta: cuando termina, los precios vuelven a la normalidad.', $oec_cmp_dur, $oec_cmp_dias ),
	],
	[
		'¿Necesito un código de descuento?',
		'No. El descuento se aplica solo al inscribirte en cualquiera de las formaciones de esta página durante la promo: no hay que cargar ningún código.',
	],
	[
		'¿Hasta cuándo puedo inscribirme con descuento?',
		sprintf( 'Hasta %s (hora de Argentina). Después de ese momento, cada formación vuelve a su precio habitual.', $oec_cmp_fin ),
	],
	[
		'¿Y si la formación empieza más adelante?',
		'Te inscribís ahora con el descuento y la cursás cuando empieza. Las fechas de inicio y la modalidad de cada una están en su ficha.',
	],
	[
		'¿Qué formaciones participan?',
		'Solo las que aparecen en esta página, cada una con su porcentaje. El resto del catálogo mantiene sus precios.',
	],
	[
		'No sé cuál elegir, ¿me ayudan?',
		'Sí: abrí el asistente del sitio y contale a qué te dedicás o qué querés lograr. Te recomienda formaciones de la promo con sus precios con descuento.',
	],
];

add_filter( 'oec_seo', function ( $c ) use ( $oec_cmp_name, $oec_cmp_max, $oec_cmp_n, $oec_cmp_dias, $oec_cmp_phase ) {
	return array_merge( $c, [
		'title'       => $oec_cmp_name,
		'description' => 'ended' === $oec_cmp_phase
			? sprintf( 'El %s terminó. Suscribite al newsletter para enterarte primero de las próximas promos.', $oec_cmp_name )
			: ( $oec_cmp_max
				? sprintf( '%s: hasta %d%% OFF en %d formaciones, %s. El descuento se aplica solo al inscribirte.', $oec_cmp_name, $oec_cmp_max, $oec_cmp_n, $oec_cmp_dias )
				: sprintf( '%s: descuentos fuertes en formaciones seleccionadas, %s. El descuento se aplica solo al inscribirte.', $oec_cmp_name, $oec_cmp_dias ) ),
	] );
} );

get_header();
?>
<main id="main-content" class="oec-cmpL oec-cmpL--<?php echo esc_attr( $oec_cmp_phase ); ?>" data-oec-cmp-landing="<?php echo esc_attr( $oec_cmp_phase ); ?>">

	<?php /* ── HERO ── */ ?>
	<section class="oec-cmpL-hero">
		<div class="oec-cmpL-hero__grid" aria-hidden="true"></div>
		<div class="oec-cmpL-hero__glow" aria-hidden="true"></div>
		<div class="oec-cmpL-hero__pcts" aria-hidden="true"><span>%</span><span>%</span><span>%</span><span>%</span><span>%</span><span>%</span></div>
		<div class="oec-cmpL-wrap oec-cmpL-hero__inner">
			<?php oec_breadcrumb( [ [ __( 'Especiales', 'oec-theme' ), home_url( user_trailingslashit( '/especiales' ) ) ], [ $oec_cmp_name ] ] ); ?>
			<p class="oec-cmp-eyebrow">
				<i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
				<?php
				if ( $oec_cmp_live ) {
					echo esc_html( sprintf( 'Promo flash · solo %s', $oec_cmp_dur ) );
				} elseif ( 'teaser' === $oec_cmp_phase ) {
					echo esc_html( sprintf( 'Se viene · %s', $oec_cmp_dias ) );
				} else {
					esc_html_e( 'Promo terminada', 'oec-theme' );
				}
				?>
			</p>
			<h1 class="oec-cmpL-hero__title"><?php echo oec_cmp_wordmark( $oec_cmp_name ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></h1>

			<?php if ( 'ended' === $oec_cmp_phase ) : ?>
			<p class="oec-cmpL-hero__big"><?php esc_html_e( 'Terminó. ¡Gracias por sumarte!', 'oec-theme' ); ?></p>
			<p class="oec-cmpL-hero__lead"><?php esc_html_e( 'Los descuentos de esta promo ya no están vigentes. Hacemos varias por año: suscribite al newsletter y te avisamos primero de la próxima.', 'oec-theme' ); ?></p>
			<div class="oec-cmpL-hero__actions">
				<a class="oec-cmp-btn" href="<?php echo esc_url( home_url( '/formaciones' ) ); ?>"><?php esc_html_e( 'Ver todas las formaciones', 'oec-theme' ); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
			</div>
			<?php else : ?>
			<p class="oec-cmpL-hero__big">
				<?php
				if ( $oec_cmp_live ) {
					echo $oec_cmp_max // phpcs:ignore WordPress.Security.EscapeOutput
						? sprintf( 'Hasta <strong>%d%% OFF</strong> en %d formaciones', (int) $oec_cmp_max, (int) $oec_cmp_n )
						: esc_html( sprintf( '%d formaciones con descuento', $oec_cmp_n ) );
				} else {
					echo esc_html( sprintf( 'Descuentos fuertes en %s', $oec_cmp_n ? sprintf( '%d formaciones', $oec_cmp_n ) : 'formaciones seleccionadas' ) );
				}
				?>
			</p>
			<p class="oec-cmpL-hero__lead">
				<?php
				echo esc_html( $oec_cmp_live
					? sprintf( 'Solo %s: termina %s (hora de Argentina). El descuento se aplica solo al inscribirte, sin códigos.', $oec_cmp_dias, $oec_cmp_fin )
					: sprintf( 'Durante %s (%s) vas a poder inscribirte con descuento en formaciones elegidas junto a nuestros socios educativos. Mirá desde ahora cuáles participan y elegí la tuya.', $oec_cmp_dur, $oec_cmp_dias ) );
				?>
			</p>
			<?php echo oec_cmp_countdown( $oec_cmp_live ? $oec_cmp_t['end'] : $oec_cmp_t['start'], $oec_cmp_live ? 'Termina en' : 'Empieza en', 'oec-cmp-cd--lg' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<div class="oec-cmpL-hero__actions">
				<?php if ( $oec_cmp_rows ) : ?>
				<a class="oec-cmp-btn" href="#cmp-formaciones"><?php echo esc_html( $oec_cmp_live ? 'Ver las ofertas' : 'Ver qué formaciones participan' ); ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
				<?php endif; ?>
				<?php if ( 'teaser' === $oec_cmp_phase ) : ?>
				<a class="oec-cmp-btn oec-cmp-btn--ghost" href="#cmp-aviso"><i class="bi bi-bell" aria-hidden="true"></i> <?php esc_html_e( 'Avisame cuando empiece', 'oec-theme' ); ?></a>
				<?php else : ?>
				<button type="button" class="oec-cmp-btn oec-cmp-btn--ghost oec-chat-ia-trigger" data-msg="<?php echo esc_attr( $oec_cmp_chat ); ?>"><i class="bi bi-stars" aria-hidden="true"></i> <?php esc_html_e( 'Ayudame a elegir', 'oec-theme' ); ?></button>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( 'ended' !== $oec_cmp_phase ) : ?>
	<?php /* ── CINTA: lo esencial, en movimiento ── */ ?>
	<div class="oec-cmpL-tape" aria-hidden="true">
		<div class="oec-cmpL-tape__track">
			<?php for ( $i = 0; $i < 2; $i++ ) : ?>
			<span><?php echo esc_html( mb_strtoupper( $oec_cmp_name ) ); ?></span><i class="bi bi-lightning-charge-fill"></i>
			<span><?php echo esc_html( $oec_cmp_max ? sprintf( 'Hasta %d%% OFF', $oec_cmp_max ) : 'Descuentos fuertes' ); ?></span><i class="bi bi-lightning-charge-fill"></i>
			<span><?php echo esc_html( mb_strtoupper( $oec_cmp_dias ) ); ?></span><i class="bi bi-lightning-charge-fill"></i>
			<span><?php esc_html_e( 'Sin códigos', 'oec-theme' ); ?></span><i class="bi bi-lightning-charge-fill"></i>
			<span><?php echo esc_html( sprintf( 'Solo %s', $oec_cmp_dur ) ); ?></span><i class="bi bi-lightning-charge-fill"></i>
			<?php endfor; ?>
		</div>
	</div>

	<?php /* ── CÓMO FUNCIONA ── */ ?>
	<section class="oec-cmpL-block">
		<div class="oec-cmpL-wrap">
			<ol class="oec-cmpL-steps">
				<li>
					<span class="oec-cmpL-steps__n">1</span>
					<h2><?php esc_html_e( 'Elegí tu formación', 'oec-theme' ); ?></h2>
					<p><?php esc_html_e( 'Cursos, diplomados y certificaciones de los mejores socios educativos, cada una con su porcentaje de descuento.', 'oec-theme' ); ?></p>
				</li>
				<li>
					<span class="oec-cmpL-steps__n">2</span>
					<h2><?php echo esc_html( sprintf( 'Inscribite hasta el %s', wp_date( 'l j', $oec_cmp_t['end'] ) ) ); ?></h2>
					<p><?php echo esc_html( sprintf( 'La promo dura %s y termina %s (hora de Argentina). Cuando termina, no se extiende.', $oec_cmp_dur, $oec_cmp_fin ) ); ?></p>
				</li>
				<li>
					<span class="oec-cmpL-steps__n">3</span>
					<h2><?php esc_html_e( 'El descuento se aplica solo', 'oec-theme' ); ?></h2>
					<p><?php esc_html_e( 'No hay códigos que cargar: al inscribirte durante la promo ya ves el precio con descuento.', 'oec-theme' ); ?></p>
				</li>
			</ol>
		</div>
	</section>

	<?php if ( 'teaser' === $oec_cmp_phase ) : ?>
	<?php /* ── AVISO: newsletter ── */ ?>
	<section class="oec-cmpL-block oec-cmpL-block--dark" id="cmp-aviso">
		<div class="oec-cmpL-wrap oec-cmpL-aviso">
			<div>
				<p class="oec-cmp-eyebrow"><i class="bi bi-bell-fill" aria-hidden="true"></i> <?php esc_html_e( 'Que no te lo cuenten', 'oec-theme' ); ?></p>
				<h2 class="oec-cmpL-h2"><?php esc_html_e( 'Te avisamos cuando empiece', 'oec-theme' ); ?></h2>
				<?php
				// Primer newsletter semanal después del inicio (lleva el bloque de la campaña): si sale
				// mientras dura, se dice qué día llega; si no, no se promete una fecha.
				$oec_cmp_nl = function_exists( 'oec_nl_compute_next_run' ) && oec_nl_opts()['digest_enabled'] ? oec_nl_compute_next_run( $oec_cmp_t['start'] - 1 ) : 0;
				?>
				<p><?php echo esc_html( $oec_cmp_nl && $oec_cmp_nl <= $oec_cmp_t['end']
					? sprintf( 'Suscribite al newsletter y el %s te llega a tu correo el %s con todas las formaciones y sus descuentos.', wp_date( 'l j \d\e F', $oec_cmp_nl ), $oec_cmp_name )
					: sprintf( 'Suscribite al newsletter y enterate de promos como el %s antes que nadie.', $oec_cmp_name ) ); ?></p>
			</div>
			<div class="oec-cmpL-aviso__form">
				<?php
				echo function_exists( 'oec_nl_render_aware' ) // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro
					? oec_nl_render_aware( OEC_NL_GENERAL_LIST, [
						'title'  => '',
						'text'   => sprintf( 'Un correo por semana, con artículos, formaciones y las promos como el %s. Al confirmar sumás %d créditos.', $oec_cmp_name, OEC_NL_SUBSCRIBE_CREDITS ),
						'button' => __( 'Avisame', 'oec-theme' ),
						'topics' => 'no',
					] )
					: '';
				?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $oec_cmp_rows ) : ?>
	<?php /* ── FORMACIONES ── */ ?>
	<section class="oec-cmpL-block oec-cmpL-block--list" id="cmp-formaciones">
		<div class="oec-cmpL-wrap">
			<p class="oec-cmp-eyebrow oec-cmp-eyebrow--dark"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> <?php echo esc_html( $oec_cmp_live ? 'Ofertas vigentes' : 'Participan' ); ?></p>
			<h2 class="oec-cmpL-h2 oec-cmpL-h2--dark"><?php echo esc_html( $oec_cmp_live ? sprintf( '%d formaciones con descuento', $oec_cmp_n ) : sprintf( '%d formaciones van a tener descuento', $oec_cmp_n ) ); ?></h2>
			<?php if ( ! $oec_cmp_live ) : ?>
			<p class="oec-cmpL-sub"><?php echo esc_html( sprintf( 'Los porcentajes se revelan cuando empieza la promo, %s. Guardá esta página.', wp_date( 'l j \d\e F', $oec_cmp_t['start'] ) ) ); ?></p>
			<?php endif; ?>

			<?php if ( $oec_cmp_pcts || count( $oec_cmp_temas ) > 1 ) : ?>
			<div class="oec-cmpL-filters" data-oec-cmp-filters>
				<?php if ( count( $oec_cmp_pcts ) > 1 ) : ?>
				<div class="oec-cmpL-filters__row" role="group" aria-label="<?php esc_attr_e( 'Filtrar por descuento', 'oec-theme' ); ?>">
					<button type="button" class="oec-cmpL-chip" data-filter-pct="" aria-pressed="true"><?php esc_html_e( 'Todos los descuentos', 'oec-theme' ); ?></button>
					<?php foreach ( $oec_cmp_pcts as $p ) : ?>
					<button type="button" class="oec-cmpL-chip" data-filter-pct="<?php echo (int) $p; ?>" aria-pressed="false">−<?php echo (int) $p; ?>%</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php if ( count( $oec_cmp_temas ) > 1 ) : ?>
				<div class="oec-cmpL-filters__row" role="group" aria-label="<?php esc_attr_e( 'Filtrar por temática', 'oec-theme' ); ?>">
					<button type="button" class="oec-cmpL-chip" data-filter-tema="" aria-pressed="true"><?php esc_html_e( 'Todas las temáticas', 'oec-theme' ); ?></button>
					<?php foreach ( $oec_cmp_temas as $slug => [ $title, $n ] ) : ?>
					<button type="button" class="oec-cmpL-chip" data-filter-tema="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $title ); ?> <span><?php echo (int) $n; ?></span></button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<p class="oec-cmpL-filters__count" role="status" aria-live="polite"></p>
			</div>
			<?php endif; ?>

			<div class="art-grid art-grid--formaciones oec-cmpL-grid">
				<?php foreach ( $oec_cmp_rows as $i => $r ) : ?>
				<div class="oec-cmpL-item" data-pct="<?php echo (int) $r['cmp_pct']; ?>" data-temas="<?php echo esc_attr( implode( ' ', $r['tags'] ) ); ?>">
					<?php echo oec_formacion_card( $r, [ 'org' => true, 'eager' => $i < 3 ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php /* ── CHAT IA ── */ ?>
	<section class="oec-cmpL-block oec-cmpL-block--dark">
		<div class="oec-cmpL-wrap oec-cmpL-chat">
			<span class="oec-cmpL-chat__icon" aria-hidden="true"><i class="bi bi-stars"></i></span>
			<div>
				<h2 class="oec-cmpL-h2"><?php esc_html_e( '¿No sabés cuál elegir?', 'oec-theme' ); ?></h2>
				<p><?php echo esc_html( sprintf( 'Contale a nuestro asistente a qué te dedicás o qué querés lograr: conoce todas las formaciones del %s y te dice cuál te conviene, con su precio con descuento.', $oec_cmp_name ) ); ?></p>
			</div>
			<button type="button" class="oec-cmp-btn oec-chat-ia-trigger" data-msg="<?php echo esc_attr( $oec_cmp_chat ); ?>"><?php esc_html_e( 'Preguntale al asistente', 'oec-theme' ); ?> <i class="bi bi-chat-dots" aria-hidden="true"></i></button>
		</div>
	</section>

	<?php /* ── PREGUNTAS FRECUENTES (+ FAQPage con el mismo texto visible) ── */ ?>
	<section class="oec-cmpL-block">
		<div class="oec-cmpL-wrap oec-cmpL-faq">
			<h2 class="oec-cmpL-h2 oec-cmpL-h2--dark"><?php esc_html_e( 'Preguntas frecuentes', 'oec-theme' ); ?></h2>
			<?php foreach ( $oec_cmp_faq as $i => [ $q, $a ] ) : ?>
			<details class="oec-faq-item"<?php echo 0 === $i ? ' open' : ''; ?>>
				<summary><?php echo esc_html( $q ); ?></summary>
				<p><?php echo esc_html( $a ); ?></p>
			</details>
			<?php endforeach; ?>
		</div>
		<script type="application/ld+json"><?php echo wp_json_encode( [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map( fn( $qa ) => [
				'@type'          => 'Question',
				'name'           => $qa[0],
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $qa[1] ],
			], $oec_cmp_faq ),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
	</section>

	<?php /* ── CIERRE ── */ ?>
	<section class="oec-cmpL-block oec-cmpL-block--dark oec-cmpL-cierre">
		<div class="oec-cmpL-wrap">
			<p class="oec-cmpL-cierre__title"><?php echo oec_cmp_wordmark( $oec_cmp_name ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?></p>
			<p class="oec-cmpL-hero__lead"><?php echo esc_html( $oec_cmp_live ? 'Cuando el contador llega a cero, los precios vuelven a la normalidad.' : sprintf( 'Empieza %s a las %s.', wp_date( 'l j \d\e F', $oec_cmp_t['start'] ), wp_date( 'H:i', $oec_cmp_t['start'] ) ) ); ?></p>
			<?php echo oec_cmp_countdown( $oec_cmp_live ? $oec_cmp_t['end'] : $oec_cmp_t['start'], $oec_cmp_live ? 'Termina en' : 'Empieza en', 'oec-cmp-cd--md' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro ?>
			<?php if ( $oec_cmp_rows ) : ?>
			<div class="oec-cmpL-hero__actions">
				<a class="oec-cmp-btn" href="#cmp-formaciones"><?php echo esc_html( $oec_cmp_live ? 'Elegir mi formación' : 'Ver qué formaciones participan' ); ?> <i class="bi bi-arrow-up" aria-hidden="true"></i></a>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php else : ?>
	<?php /* ── TERMINÓ: newsletter ── */ ?>
	<section class="oec-cmpL-block">
		<div class="oec-cmpL-wrap oec-cmpL-aviso oec-cmpL-aviso--light">
			<div>
				<h2 class="oec-cmpL-h2 oec-cmpL-h2--dark"><?php esc_html_e( 'Enterate primero de la próxima promo', 'oec-theme' ); ?></h2>
				<p><?php esc_html_e( 'Un correo por semana con artículos, formaciones seleccionadas y nuestras promos especiales.', 'oec-theme' ); ?></p>
			</div>
			<div class="oec-cmpL-aviso__form">
				<?php
				echo function_exists( 'oec_nl_render_aware' ) // phpcs:ignore WordPress.Security.EscapeOutput -- escapado adentro
					? oec_nl_render_aware( OEC_NL_GENERAL_LIST, [ 'title' => '', 'button' => __( 'Suscribirme', 'oec-theme' ), 'topics' => 'no' ] )
					: '';
				?>
			</div>
		</div>
	</section>
	<?php endif; ?>
</main>
<?php
get_footer();
