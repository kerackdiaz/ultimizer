<?php
/**
 * Vista principal del panel de administración de Ultimizer.
 * Incluida desde Ultimizer_Admin::render_page().
 *
 * Variables disponibles:
 *   $tab  – Pestaña activa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$optimizer = new Ultimizer_Optimizer();
$logger    = new Ultimizer_Logger();
$backup    = new Ultimizer_Backup();
$settings  = get_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );
$stats     = $logger->get_global_stats();
?>
<div class="wrap ultimizer-wrap">

	<div class="ultimizer-header">
		<div class="ultimizer-logo">
			<span class="dashicons dashicons-format-image"></span>
			<h1>Ultimizer</h1>
		</div>
		<span class="ultimizer-version">v<?php echo esc_html( ULTIMIZER_VERSION ); ?></span>
	</div>

	<?php echo Ultimizer_Admin::render_tabs( $tab ); ?>

	<?php if ( ! empty( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración guardada correctamente.', 'ultimizer' ); ?></p></div>
	<?php endif; ?>

	<div class="ultimizer-content">

	<?php
	// =========================================================================
	// PESTAÑA: PANEL
	// =========================================================================
	if ( 'panel' === $tab ) :
		$total_images    = $optimizer->count_optimized() + $optimizer->count_unoptimized();
		$optimized_count = $optimizer->count_optimized();
		$percent_done    = $total_images > 0 ? round( ( $optimized_count / $total_images ) * 100 ) : 0;
	?>
		<div class="ultimizer-stats-grid">

			<div class="ultimizer-stat-card">
				<div class="stat-icon dashicons dashicons-images-alt2"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $total_images ) ); ?></div>
				<div class="stat-label">Imágenes totales</div>
			</div>

			<div class="ultimizer-stat-card accent-green">
				<div class="stat-icon dashicons dashicons-yes-alt"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $optimized_count ) ); ?></div>
				<div class="stat-label">Optimizadas</div>
			</div>

			<div class="ultimizer-stat-card accent-orange">
				<div class="stat-icon dashicons dashicons-clock"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $optimizer->count_unoptimized() ) ); ?></div>
				<div class="stat-label">Pendientes</div>
			</div>

			<div class="ultimizer-stat-card accent-blue">
				<div class="stat-icon dashicons dashicons-chart-line"></div>
				<div class="stat-value"><?php echo esc_html( size_format( $stats['total_savings_bytes'] ) ); ?></div>
				<div class="stat-label">Espacio recuperado</div>
			</div>

			<div class="ultimizer-stat-card">
				<div class="stat-icon dashicons dashicons-performance"></div>
				<div class="stat-value"><?php echo esc_html( $stats['avg_savings_percent'] ); ?>%</div>
				<div class="stat-label">Reducción media</div>
			</div>

			<div class="ultimizer-stat-card">
				<div class="stat-icon dashicons dashicons-images-alt"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['avif_count'] ) ); ?></div>
				<div class="stat-label">Archivos AVIF generados</div>
			</div>

			<div class="ultimizer-stat-card">
				<div class="stat-icon dashicons dashicons-image-filter"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['webp_count'] ) ); ?></div>
				<div class="stat-label">Archivos WebP generados</div>
			</div>

			<div class="ultimizer-stat-card accent-purple">
				<div class="stat-icon dashicons dashicons-backup"></div>
				<div class="stat-value"><?php echo esc_html( number_format_i18n( $backup->count_backups() ) ); ?></div>
				<div class="stat-label">Respaldos almacenados</div>
			</div>

		</div>

		<?php if ( $total_images > 0 ) : ?>
		<div class="ultimizer-progress-section">
			<div class="ultimizer-progress-label">
				<span>Progreso general de optimización</span>
				<span><strong><?php echo esc_html( $percent_done ); ?>%</strong></span>
			</div>
			<div class="ultimizer-progress-bar">
				<div class="ultimizer-progress-fill" style="width: <?php echo esc_attr( $percent_done ); ?>%"></div>
			</div>
		</div>
		<?php endif; ?>

		<div class="ultimizer-server-info">
			<h3>Capacidades del servidor</h3>
			<table class="ultimizer-info-table widefat striped">
				<tbody>
					<tr>
						<td>Motor de imagen disponible</td>
						<td><?php echo extension_loaded( 'imagick' ) ? '<span class="ult-badge green">Imagick</span>' : '<span class="ult-badge orange">GD (modo básico)</span>'; ?></td>
					</tr>
					<tr>
						<td>Soporte AVIF</td>
						<td><?php echo $optimizer->supports_avif() ? '<span class="ult-badge green">Disponible</span>' : '<span class="ult-badge red">No disponible</span>'; ?></td>
					</tr>
					<tr>
						<td>Soporte WebP</td>
						<td><?php echo $optimizer->supports_webp() ? '<span class="ult-badge green">Disponible</span>' : '<span class="ult-badge red">No disponible</span>'; ?></td>
					</tr>
					<tr>
						<td>Versión PHP</td>
						<td><?php echo esc_html( phpversion() ); ?></td>
					</tr>
					<tr>
						<td>Directorio de respaldos</td>
						<td><?php
							$dir = Ultimizer_Backup::get_backup_dir();
							echo is_writable( $dir ) ? '<span class="ult-badge green">Escribible</span>' : '<span class="ult-badge red">Sin permisos de escritura</span>';
							echo ' <code>' . esc_html( $dir ) . '</code>';
						?></td>
					</tr>
				</tbody>
			</table>
		</div>

	<?php
	// =========================================================================
	// PESTAÑA: CONFIGURACIÓN
	// =========================================================================
	elseif ( 'settings' === $tab ) :
	?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ultimizer-settings-form">
			<input type="hidden" name="action" value="ultimizer_save_settings">
			<?php wp_nonce_field( 'ultimizer_save_settings' ); ?>

			<div class="ultimizer-section">
				<h2>Calidad y compresión</h2>
				<table class="form-table">
					<tr>
						<th><label for="jpeg_quality">Calidad JPEG <span class="ult-hint">(1–100)</span></label></th>
						<td>
							<input type="number" id="jpeg_quality" name="jpeg_quality" min="1" max="100"
								value="<?php echo esc_attr( $settings['jpeg_quality'] ); ?>" class="small-text">
							<p class="description">Recomendado: 80–85. Menor valor = mayor compresión.</p>
						</td>
					</tr>
					<tr>
						<th><label for="png_compression">Compresión PNG <span class="ult-hint">(0–9)</span></label></th>
						<td>
							<input type="number" id="png_compression" name="png_compression" min="0" max="9"
								value="<?php echo esc_attr( $settings['png_compression'] ); ?>" class="small-text">
							<p class="description">Nivel de compresión zlib. 6 es el equilibrio estándar.</p>
						</td>
					</tr>
					<tr>
						<th><label for="webp_quality">Calidad WebP <span class="ult-hint">(1–100)</span></label></th>
						<td>
							<input type="number" id="webp_quality" name="webp_quality" min="1" max="100"
								value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" class="small-text">
						</td>
					</tr>
					<tr>
						<th><label for="avif_quality">Calidad AVIF <span class="ult-hint">(1–100)</span></label></th>
						<td>
							<input type="number" id="avif_quality" name="avif_quality" min="1" max="100"
								value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" class="small-text">
							<p class="description">AVIF logra alta calidad visual con valores menores (60–70).</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="ultimizer-section">
				<h2>Formatos modernos</h2>
				<table class="form-table">
					<tr>
						<th>Generar AVIF</th>
						<td>
							<label>
								<input type="checkbox" name="convert_to_avif" value="1"
									<?php checked( ! empty( $settings['convert_to_avif'] ) ); ?>>
								Crear versión <strong>.avif</strong> junto a cada imagen
								<?php if ( ! $optimizer->supports_avif() ) : ?>
									<span class="ult-badge red">No disponible en este servidor</span>
								<?php endif; ?>
							</label>
						</td>
					</tr>
					<tr>
						<th>Generar WebP</th>
						<td>
							<label>
								<input type="checkbox" name="convert_to_webp" value="1"
									<?php checked( ! empty( $settings['convert_to_webp'] ) ); ?>>
								Crear versión <strong>.webp</strong> junto a cada imagen
								<?php if ( ! $optimizer->supports_webp() ) : ?>
									<span class="ult-badge red">No disponible en este servidor</span>
								<?php endif; ?>
							</label>
							<p class="description">Los navegadores modernos recibirán AVIF o WebP automáticamente vía .htaccess sin cambiar ninguna URL.</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="ultimizer-section">
				<h2>Comportamiento</h2>
				<table class="form-table">
					<tr>
						<th>Eliminar metadatos</th>
						<td>
							<label>
								<input type="checkbox" name="strip_metadata" value="1"
									<?php checked( ! empty( $settings['strip_metadata'] ) ); ?>>
								Eliminar metadatos EXIF, IPTC y XMP de todas las imágenes
							</label>
						</td>
					</tr>
					<tr>
						<th>Omitir ya optimizadas</th>
						<td>
							<label>
								<input type="checkbox" name="skip_optimized" value="1"
									<?php checked( ! empty( $settings['skip_optimized'] ) ); ?>>
								No volver a procesar imágenes ya optimizadas por Ultimizer
							</label>
						</td>
					</tr>
					<tr>
						<th>Optimizar al subir</th>
						<td>
							<label>
								<input type="checkbox" name="optimize_on_upload" value="1"
									<?php checked( ! empty( $settings['optimize_on_upload'] ) ); ?>>
								Optimizar automáticamente cada imagen al subirla
							</label>
						</td>
					</tr>
				</table>
			</div>

			<div class="ultimizer-section">
				<h2>Actualizaciones desde GitHub</h2>
				<table class="form-table">
					<tr>
						<th><label for="github_user">Usuario de GitHub</label></th>
						<td>
							<input type="text" id="github_user" name="github_user" class="regular-text"
								value="<?php echo esc_attr( $settings['github_user'] ); ?>"
								placeholder="tu-usuario-github">
							<p class="description">
								El repositorio debe llamarse <code>ultimizer</code> y publicar releases con tags de versión (p.e. <code>v1.0.1</code>).
							</p>
						</td>
					</tr>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">Guardar configuración</button>
			</p>
		</form>

	<?php
	// =========================================================================
	// PESTAÑA: OPTIMIZACIÓN MASIVA
	// =========================================================================
	elseif ( 'bulk' === $tab ) :
		$unoptimized = $optimizer->count_unoptimized();
		$optimized   = $optimizer->count_optimized();
		$total       = $unoptimized + $optimized;
	?>
		<div class="ultimizer-bulk-panel">

			<div class="ultimizer-bulk-stats">
				<div class="bulk-stat">
					<span class="bulk-stat-number"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<span class="bulk-stat-label">Total</span>
				</div>
				<div class="bulk-stat accent-green">
					<span class="bulk-stat-number"><?php echo esc_html( number_format_i18n( $optimized ) ); ?></span>
					<span class="bulk-stat-label">Optimizadas</span>
				</div>
				<div class="bulk-stat accent-orange">
					<span class="bulk-stat-number" id="ult-unoptimized-count"><?php echo esc_html( number_format_i18n( $unoptimized ) ); ?></span>
					<span class="bulk-stat-label">Pendientes</span>
				</div>
			</div>

			<?php if ( $unoptimized > 0 ) : ?>
			<div class="ultimizer-bulk-controls">
				<button id="ult-start-bulk" class="button button-primary button-hero">
					Iniciar optimización
				</button>
				<button id="ult-pause-bulk" class="button button-secondary button-hero" style="display:none;">
					Pausar
				</button>
			</div>

			<div id="ult-bulk-progress-wrap" style="display:none;">
				<div class="ultimizer-progress-label">
					<span id="ult-progress-label">Preparando...</span>
					<span><strong id="ult-progress-pct">0%</strong></span>
				</div>
				<div class="ultimizer-progress-bar large">
					<div class="ultimizer-progress-fill" id="ult-progress-fill" style="width:0%"></div>
				</div>
				<div class="ultimizer-bulk-log" id="ult-bulk-log"></div>
			</div>
			<?php else : ?>
			<div class="notice notice-success inline"><p>✓ Todas las imágenes del sitio están optimizadas.</p></div>
			<?php endif; ?>

		</div>

	<?php
	// =========================================================================
	// PESTAÑA: REGISTRO
	// =========================================================================
	elseif ( 'log' === $tab ) :
		$entries     = $logger->get_entries( 100, 0 );
		$total_count = $logger->count_entries();
	?>
		<div class="ultimizer-log-controls">
			<h2>Registro de optimizaciones <span class="ult-count-badge"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span></h2>
			<?php if ( $total_count > 0 ) : ?>
			<button id="ult-clear-log" class="button button-secondary">Vaciar registro</button>
			<?php endif; ?>
		</div>

		<?php if ( empty( $entries ) ) : ?>
		<p>No hay entradas en el registro aún.</p>
		<?php else : ?>
		<table class="ultimizer-log-table widefat striped">
			<thead>
				<tr>
					<th>Adjunto</th>
					<th>Archivo</th>
					<th>Original</th>
					<th>Optimizado</th>
					<th>Ahorro</th>
					<th>AVIF</th>
					<th>WebP</th>
					<th>Fecha</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $entry['attachment_id'] ); ?></td>
					<td><span title="<?php echo esc_attr( $entry['file_path'] ); ?>"><?php echo esc_html( basename( $entry['file_path'] ) ); ?></span></td>
					<td><?php echo esc_html( size_format( (int) $entry['original_size'] ) ); ?></td>
					<td><?php echo esc_html( size_format( (int) $entry['optimized_size'] ) ); ?></td>
					<td><strong><?php echo esc_html( $entry['savings_percent'] ); ?>%</strong> <small>(<?php echo esc_html( size_format( (int) $entry['savings_bytes'] ) ); ?>)</small></td>
					<td><?php echo $entry['avif_generated'] ? '<span class="ult-badge green">Sí</span>' : '—'; ?></td>
					<td><?php echo $entry['webp_generated'] ? '<span class="ult-badge blue">Sí</span>' : '—'; ?></td>
					<td><?php echo esc_html( $entry['optimized_at'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

	<?php
	// =========================================================================
	// PESTAÑA: RESPALDOS
	// =========================================================================
	elseif ( 'backups' === $tab ) :
		$backups     = $backup->get_backups( 100, 0 );
		$total_back  = $backup->count_backups();
	?>
		<h2>Respaldos almacenados <span class="ult-count-badge"><?php echo esc_html( number_format_i18n( $total_back ) ); ?></span></h2>
		<p class="description">Los respaldos contienen el archivo original antes de ser optimizado. Puedes restaurarlos en cualquier momento.</p>

		<?php if ( empty( $backups ) ) : ?>
		<p>No hay respaldos aún. Se crean automáticamente al optimizar cada imagen.</p>
		<?php else : ?>
		<table class="ultimizer-log-table widefat striped">
			<thead>
				<tr>
					<th>Título</th>
					<th>Archivo de respaldo</th>
					<th>Tamaño</th>
					<th>Optimizado el</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $backups as $b ) : ?>
				<tr>
					<td><?php echo esc_html( $b['title'] ?: '(sin título)' ); ?></td>
					<td><?php echo esc_html( $b['filename'] ); ?>
						<?php if ( ! $b['exists'] ) : ?>
							<span class="ult-badge red">Archivo no encontrado</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $b['size'] ); ?></td>
					<td><?php echo esc_html( $b['optimized_at'] ); ?></td>
					<td>
						<?php if ( $b['exists'] ) : ?>
						<button class="button button-small ult-restore-btn"
							data-id="<?php echo esc_attr( $b['attachment_id'] ); ?>">
							Restaurar original
						</button>
						<?php else : ?>
						—
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

	<?php endif; ?>

	</div><!-- .ultimizer-content -->
</div><!-- .ultimizer-wrap -->
