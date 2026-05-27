<?php get_header(); ?>

<main id="main-content">

	<!-- ============================================================
	     HERO
	     ============================================================ -->
	<section class="hero" aria-label="<?php esc_attr_e( 'Inicio', 'oec-theme' ); ?>">
		<div class="container">
			<div class="hero-inner">

				<div class="hero-content">
					<?php $eyebrow = get_theme_mod( 'oec_hero_eyebrow', __( 'Educación Online', 'oec-theme' ) ); ?>
					<?php if ( $eyebrow ) : ?>
					<span class="hero-eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
					<?php endif; ?>

					<h1 class="hero-title">
						<?php echo wp_kses_post( get_theme_mod( 'oec_hero_title', __( 'Aprendé sin límites, <em>crecé sin fronteras</em>', 'oec-theme' ) ) ); ?>
					</h1>

					<p class="hero-description">
						<?php echo esc_html( get_theme_mod( 'oec_hero_subtitle', __( 'Descubrí nuestra oferta de cursos online, certificaciones y programas de formación profesional diseñados para impulsar tu carrera.', 'oec-theme' ) ) ); ?>
					</p>

					<div class="hero-actions">
						<a href="<?php echo esc_url( get_theme_mod( 'oec_hero_btn_url', '#servicios' ) ); ?>" class="btn btn-primary btn-lg">
							<?php echo esc_html( get_theme_mod( 'oec_hero_btn_text', __( 'Ver cursos', 'oec-theme' ) ) ); ?>
						</a>
						<a href="#nosotros" class="btn btn-outline btn-lg">
							<?php esc_html_e( 'Conocé OEC', 'oec-theme' ); ?>
						</a>
					</div>
				</div>

				<div class="hero-media">
					<div class="hero-image-wrapper">
						<?php
						$hero_img = get_theme_mod( 'oec_hero_image', '' );
						if ( $hero_img ) :
						?>
							<img src="<?php echo esc_url( $hero_img ); ?>" alt="<?php esc_attr_e( 'Estudiantes OEC', 'oec-theme' ); ?>" loading="eager">
						<?php else : ?>
							<div class="hero-image-placeholder" aria-hidden="true">🎓</div>
						<?php endif; ?>
					</div>
				</div>

			</div>
		</div>
	</section>

	<!-- ============================================================
	     STATS BAR
	     ============================================================ -->
	<div class="stats-bar" aria-label="<?php esc_attr_e( 'Estadísticas', 'oec-theme' ); ?>">
		<div class="container">
			<div class="stats-inner">
				<?php
				$stats = [
					[ 'number' => '+10.000', 'label' => __( 'Estudiantes activos', 'oec-theme' ) ],
					[ 'number' => '+200',    'label' => __( 'Cursos disponibles', 'oec-theme' ) ],
					[ 'number' => '98%',     'label' => __( 'Satisfacción de alumnos', 'oec-theme' ) ],
					[ 'number' => '+15',     'label' => __( 'Años de experiencia', 'oec-theme' ) ],
				];
				foreach ( $stats as $stat ) :
				?>
				<div class="stat-item">
					<strong class="stat-number"><?php echo esc_html( $stat['number'] ); ?></strong>
					<span class="stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     SERVICES
	     ============================================================ -->
	<section class="services" id="servicios">
		<div class="container">

			<div class="section-header">
				<span class="section-eyebrow"><?php esc_html_e( 'Lo que ofrecemos', 'oec-theme' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Formación pensada para vos', 'oec-theme' ); ?></h2>
				<p class="section-description">
					<?php esc_html_e( 'Nuestros programas combinan teoría, práctica y acompañamiento docente para garantizar un aprendizaje real y efectivo.', 'oec-theme' ); ?>
				</p>
			</div>

			<div class="grid-3">
				<?php
				$services = [
					[
						'icon'  => '📚',
						'title' => __( 'Cursos online', 'oec-theme' ),
						'desc'  => __( 'Accedé a contenido grabado de alta calidad, disponible las 24 hs. Avanzá a tu ritmo con soporte docente.', 'oec-theme' ),
					],
					[
						'icon'  => '🎓',
						'title' => __( 'Certificaciones', 'oec-theme' ),
						'desc'  => __( 'Obtené certificados reconocidos por el mercado laboral y sumá valor a tu perfil profesional.', 'oec-theme' ),
					],
					[
						'icon'  => '🏢',
						'title' => __( 'Capacitación empresarial', 'oec-theme' ),
						'desc'  => __( 'Programas a medida para equipos de trabajo. Mejorá las competencias de tu organización.', 'oec-theme' ),
					],
					[
						'icon'  => '🔴',
						'title' => __( 'Clases en vivo', 'oec-theme' ),
						'desc'  => __( 'Encuentros sincrónicos con docentes especializados. Hacé preguntas y aprendé en comunidad.', 'oec-theme' ),
					],
					[
						'icon'  => '💼',
						'title' => __( 'Mentorías', 'oec-theme' ),
						'desc'  => __( 'Sesiones one-on-one con expertos de la industria para potenciar tu carrera y tus proyectos.', 'oec-theme' ),
					],
					[
						'icon'  => '📊',
						'title' => __( 'Panel de progreso', 'oec-theme' ),
						'desc'  => __( 'Seguí tu avance en tiempo real. Estadísticas, logros y certificados en un solo lugar.', 'oec-theme' ),
					],
				];
				foreach ( $services as $service ) :
				?>
				<div class="service-card">
					<div class="service-icon" aria-hidden="true"><?php echo $service['icon']; // phpcs:ignore ?></div>
					<h3><?php echo esc_html( $service['title'] ); ?></h3>
					<p><?php echo esc_html( $service['desc'] ); ?></p>
				</div>
				<?php endforeach; ?>
			</div>

		</div>
	</section>

	<!-- ============================================================
	     ABOUT
	     ============================================================ -->
	<section id="nosotros">
		<div class="container">
			<div class="about-inner">

				<div class="about-media">
					<div class="about-image">
						<img
							src="<?php echo esc_url( OEC_THEME_URI . '/assets/img/about-placeholder.jpg' ); ?>"
							alt="<?php esc_attr_e( 'Equipo OEC', 'oec-theme' ); ?>"
							loading="lazy"
							width="600"
							height="460"
						>
					</div>
					<div class="about-badge">
						<strong>+15</strong>
						<span><?php esc_html_e( 'Años formando profesionales', 'oec-theme' ); ?></span>
					</div>
				</div>

				<div class="about-content">
					<span class="section-eyebrow"><?php esc_html_e( 'Quiénes somos', 'oec-theme' ); ?></span>
					<h2 class="section-title"><?php esc_html_e( 'Educación de calidad, al alcance de todos', 'oec-theme' ); ?></h2>
					<p class="lead">
						<?php esc_html_e( 'En OEC creemos que el conocimiento transforma vidas. Llevamos más de 15 años desarrollando propuestas formativas innovadoras para estudiantes y profesionales de toda Latinoamérica.', 'oec-theme' ); ?>
					</p>
					<ul class="about-list">
						<li><?php esc_html_e( 'Docentes con experiencia real en la industria', 'oec-theme' ); ?></li>
						<li><?php esc_html_e( 'Contenido actualizado permanentemente', 'oec-theme' ); ?></li>
						<li><?php esc_html_e( 'Plataforma accesible desde cualquier dispositivo', 'oec-theme' ); ?></li>
						<li><?php esc_html_e( 'Comunidad activa de más de 10.000 estudiantes', 'oec-theme' ); ?></li>
						<li><?php esc_html_e( 'Certificados reconocidos por el mercado', 'oec-theme' ); ?></li>
					</ul>
					<div class="about-actions">
						<a href="#contacto" class="btn btn-primary">
							<?php esc_html_e( 'Hablar con un asesor', 'oec-theme' ); ?>
						</a>
					</div>
				</div>

			</div>
		</div>
	</section>

	<!-- ============================================================
	     TESTIMONIALS
	     ============================================================ -->
	<section class="testimonials">
		<div class="container">

			<div class="section-header">
				<span class="section-eyebrow"><?php esc_html_e( 'Testimonios', 'oec-theme' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Lo que dicen nuestros alumnos', 'oec-theme' ); ?></h2>
				<p class="section-description">
					<?php esc_html_e( 'Miles de profesionales ya transformaron su carrera con OEC. Conocé sus experiencias.', 'oec-theme' ); ?>
				</p>
			</div>

			<div class="grid-3">
				<?php
				$testimonials = [
					[
						'text'   => '"OEC cambió mi carrera por completo. Los cursos son prácticos, los docentes son excelentes y el material siempre está actualizado."',
						'name'   => 'Lucía Fernández',
						'role'   => __( 'Diseñadora UX/UI', 'oec-theme' ),
						'avatar' => 'LF',
					],
					[
						'text'   => '"Hice el programa de Marketing Digital y conseguí trabajo en menos de dos meses. La metodología es realmente efectiva."',
						'name'   => 'Mateo González',
						'role'   => __( 'Marketing Manager', 'oec-theme' ),
						'avatar' => 'MG',
					],
					[
						'text'   => '"Las mentorías son un diferencial enorme. Poder hablar con un profesional del sector hace toda la diferencia."',
						'name'   => 'Valeria Torres',
						'role'   => __( 'Desarrolladora Full Stack', 'oec-theme' ),
						'avatar' => 'VT',
					],
				];
				foreach ( $testimonials as $t ) :
				?>
				<div class="testimonial-card">
					<div class="testimonial-stars" aria-label="<?php esc_attr_e( '5 estrellas', 'oec-theme' ); ?>">★★★★★</div>
					<p class="testimonial-text"><?php echo esc_html( $t['text'] ); ?></p>
					<div class="testimonial-author">
						<div class="testimonial-avatar" aria-hidden="true"><?php echo esc_html( $t['avatar'] ); ?></div>
						<div>
							<div class="testimonial-name"><?php echo esc_html( $t['name'] ); ?></div>
							<div class="testimonial-role"><?php echo esc_html( $t['role'] ); ?></div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

		</div>
	</section>

	<!-- ============================================================
	     CTA BAND
	     ============================================================ -->
	<section class="cta-band">
		<div class="container" style="position:relative;">
			<h2><?php esc_html_e( '¿Listo para dar el siguiente paso?', 'oec-theme' ); ?></h2>
			<p><?php esc_html_e( 'Sumate a miles de profesionales que ya están aprendiendo con OEC. Empezá hoy, sin costo de inscripción.', 'oec-theme' ); ?></p>
			<div class="cta-actions">
				<a href="#servicios" class="btn btn-primary btn-lg"><?php esc_html_e( 'Explorar cursos', 'oec-theme' ); ?></a>
				<a href="#contacto" class="btn btn-outline btn-lg"><?php esc_html_e( 'Hablar con un asesor', 'oec-theme' ); ?></a>
			</div>
		</div>
	</section>

	<!-- ============================================================
	     CONTACT
	     ============================================================ -->
	<section id="contacto">
		<div class="container">
			<div class="contact-inner">

				<div class="contact-info">
					<h2 class="heading-2"><?php esc_html_e( 'Contactanos', 'oec-theme' ); ?></h2>
					<p><?php esc_html_e( 'Nuestro equipo de asesores está disponible para ayudarte a elegir el programa ideal para tus objetivos.', 'oec-theme' ); ?></p>

					<div class="contact-details">
						<?php
						$email   = get_theme_mod( 'oec_contact_email', 'info@oec.edu' );
						$phone   = get_theme_mod( 'oec_contact_phone', '' );
						$address = get_theme_mod( 'oec_contact_address', '' );

						if ( $email ) :
						?>
						<div class="contact-item">
							<div class="contact-item-icon" aria-hidden="true">✉</div>
							<div class="contact-item-text">
								<strong><?php esc_html_e( 'Email', 'oec-theme' ); ?></strong>
								<span><?php echo esc_html( $email ); ?></span>
							</div>
						</div>
						<?php endif; ?>

						<?php if ( $phone ) : ?>
						<div class="contact-item">
							<div class="contact-item-icon" aria-hidden="true">☏</div>
							<div class="contact-item-text">
								<strong><?php esc_html_e( 'Teléfono', 'oec-theme' ); ?></strong>
								<span><?php echo esc_html( $phone ); ?></span>
							</div>
						</div>
						<?php endif; ?>

						<?php if ( $address ) : ?>
						<div class="contact-item">
							<div class="contact-item-icon" aria-hidden="true">◎</div>
							<div class="contact-item-text">
								<strong><?php esc_html_e( 'Dirección', 'oec-theme' ); ?></strong>
								<span><?php echo esc_html( $address ); ?></span>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="contact-form">
					<?php if ( function_exists( 'wpcf7_contact_form' ) ) : ?>
						<!-- Contact Form 7: reemplazá [contact-form-7 id="xxx"] con tu shortcode -->
						<p class="text-muted text-sm"><?php esc_html_e( 'Insertá tu shortcode de Contact Form 7 aquí.', 'oec-theme' ); ?></p>
					<?php else : ?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" novalidate>
						<input type="hidden" name="action" value="oec_contact_form">
						<?php wp_nonce_field( 'oec_contact_nonce', 'oec_nonce' ); ?>

						<div class="form-grid">
							<div class="form-group">
								<label for="cf-name"><?php esc_html_e( 'Nombre', 'oec-theme' ); ?></label>
								<input type="text" id="cf-name" name="cf_name" placeholder="<?php esc_attr_e( 'Tu nombre', 'oec-theme' ); ?>" required>
							</div>
							<div class="form-group">
								<label for="cf-email"><?php esc_html_e( 'Email', 'oec-theme' ); ?></label>
								<input type="email" id="cf-email" name="cf_email" placeholder="<?php esc_attr_e( 'tu@email.com', 'oec-theme' ); ?>" required>
							</div>
							<div class="form-group">
								<label for="cf-phone"><?php esc_html_e( 'Teléfono', 'oec-theme' ); ?></label>
								<input type="tel" id="cf-phone" name="cf_phone" placeholder="+54 11 1234-5678">
							</div>
							<div class="form-group">
								<label for="cf-interest"><?php esc_html_e( 'Área de interés', 'oec-theme' ); ?></label>
								<select id="cf-interest" name="cf_interest">
									<option value=""><?php esc_html_e( 'Seleccioná una opción', 'oec-theme' ); ?></option>
									<option value="cursos"><?php esc_html_e( 'Cursos online', 'oec-theme' ); ?></option>
									<option value="certificaciones"><?php esc_html_e( 'Certificaciones', 'oec-theme' ); ?></option>
									<option value="empresas"><?php esc_html_e( 'Capacitación empresarial', 'oec-theme' ); ?></option>
									<option value="otro"><?php esc_html_e( 'Otro', 'oec-theme' ); ?></option>
								</select>
							</div>
							<div class="form-group full">
								<label for="cf-message"><?php esc_html_e( 'Mensaje', 'oec-theme' ); ?></label>
								<textarea id="cf-message" name="cf_message" placeholder="<?php esc_attr_e( '¿En qué podemos ayudarte?', 'oec-theme' ); ?>"></textarea>
							</div>
						</div>

						<div class="form-submit mt-2">
							<button type="submit" class="btn btn-primary btn-lg">
								<?php esc_html_e( 'Enviar consulta', 'oec-theme' ); ?>
							</button>
						</div>
					</form>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</section>

</main>

<?php get_footer(); ?>
