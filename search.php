<?php
/**
 * search.php — Resultados de búsqueda de formaciones OEC
 * Se activa cuando el usuario presiona Enter en el buscador del header (?s=query)
 */
get_header();

$query   = get_search_query(); // sanitizado por WP
$results = $query ? oec_fetch_trainings( $query ) : [];

$fmt_date = fn( $d ) => $d
	? date_i18n( 'd M Y', strtotime( $d ) )
	: '—';
?>

<div class="page-hero">
	<div class="container">
		<h1>
			<?php if ( $query ) : ?>
				<?php printf(
					/* translators: %s: término buscado */
					esc_html__( 'Resultados para "%s"', 'oec-theme' ),
					esc_html( $query )
				); ?>
			<?php else : ?>
				<?php esc_html_e( 'Buscar formaciones', 'oec-theme' ); ?>
			<?php endif; ?>
		</h1>
		<?php if ( $results ) : ?>
		<p style="color:rgba(255,255,255,.55);margin-top:.5rem;font-size:.9375rem;">
			<?php printf(
				esc_html( _n( '%d formación encontrada', '%d formaciones encontradas', count( $results ), 'oec-theme' ) ),
				count( $results )
			); ?>
		</p>
		<?php endif; ?>
	</div>
</div>

<main id="main-content">
<section>
<div class="container">

<?php if ( ! $query ) : ?>
	<div class="text-center mt-4">
		<p><?php esc_html_e( 'Ingresá un término en el buscador para ver resultados.', 'oec-theme' ); ?></p>
	</div>

<?php elseif ( empty( $results ) ) : ?>
	<div class="text-center mt-4">
		<p style="font-size:3rem;margin-bottom:1rem;" aria-hidden="true">😕</p>
		<p><?php printf(
			esc_html__( 'No encontramos formaciones para "%s".', 'oec-theme' ),
			esc_html( $query )
		); ?></p>
		<p class="text-muted mt-2"><?php esc_html_e( 'Probá con un término más general o diferente.', 'oec-theme' ); ?></p>
	</div>

<?php else : ?>
	<div class="search-results-grid">
		<?php foreach ( $results as $item ) :
			$title  = esc_html( $item['title']             ?? '' );
			$image  = esc_url(  $item['image']             ?? '' );
			$desc   = esc_html( $item['short_description'] ?? '' );
			$type   = esc_html( $item['type']              ?? '' );
			$mod    = esc_html( $item['modality']          ?? '' );
			$org    = esc_html( $item['organization']['shortName'] ?? '' );
			$start  = $fmt_date( $item['start'] ?? null );
			$end    = $fmt_date( $item['end']   ?? null );
			$url    = esc_url( $item['url'] ?? '#' );
		?>
		<a href="<?php echo $url; ?>" class="training-card" target="_blank" rel="noopener noreferrer">
			<?php if ( $image ) : ?>
			<div class="training-card__thumb">
				<img src="<?php echo $image; ?>" alt="<?php echo $title; ?>" loading="lazy">
			</div>
			<?php endif; ?>

			<div class="training-card__body">
				<div class="training-card__meta">
					<?php if ( $type ) : ?>
					<span class="training-badge"><?php echo $type; ?></span>
					<?php endif; ?>
					<?php if ( $mod ) : ?>
					<span class="training-badge"><?php echo $mod; ?></span>
					<?php endif; ?>
				</div>

				<div class="training-card__title"><?php echo $title; ?></div>
				<?php if ( $desc ) : ?>
				<div class="training-card__desc"><?php echo $desc; ?></div>
				<?php endif; ?>

				<div class="training-card__footer">
					<div>
						<strong><?php echo $start; ?></strong>
						<?php if ( $end && $end !== $start ) : ?> → <?php echo $end; ?><?php endif; ?>
					</div>
					<?php if ( $org ) : ?>
					<span><?php echo $org; ?></span>
					<?php endif; ?>
				</div>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

</div>
</section>
</main>

<?php get_footer(); ?>
