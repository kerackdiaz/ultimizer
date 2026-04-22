<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$optimizer = new Ultimizer_Optimizer();
$logger    = new Ultimizer_Logger();
$backup    = new Ultimizer_Backup();
$settings  = get_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );
$stats     = $logger->get_global_stats();
?>
<div class="wrap ult-wrap">

	<div class="ult-header">
		<div class="ult-brand">
			<img src="<?php echo esc_url( ULTIMIZER_PLUGIN_URL . 'assets/ultimizer.png' ); ?>" alt="Ultimizer" class="ult-brand-logo">
			<h1>Ultimizer</h1>
		</div>
		<span class="ult-ver">v<?php echo esc_html( ULTIMIZER_VERSION ); ?></span>
	</div>

	<?php echo Ultimizer_Admin::render_tabs( $tab ); ?>

	<?php if ( ! empty( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Configuración guardada correctamente.</p></div>
	<?php endif; ?>

	<div class="ult-content">

	<?php
	// =========================================================================
	// PANEL
	// =========================================================================
	if ( 'panel' === $tab ) :
		$total       = $optimizer->count_all();
		$optimized   = $optimizer->count_optimized();
		$unoptimized = $optimizer->count_unoptimized();
		$pct         = $total > 0 ? round( ( $optimized / $total ) * 100 ) : 0;
	?>

		<div class="ult-stats-strip">
			<div class="ult-stat">
				<span class="ult-stat-n" id="ult-stat-total"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				<span class="ult-stat-l">Imágenes totales</span>
			</div>
			<div class="ult-stat green">
				<span class="ult-stat-n" id="ult-stat-optimized"><?php echo esc_html( number_format_i18n( $optimized ) ); ?></span>
				<span class="ult-stat-l">Optimizadas</span>
			</div>
			<div class="ult-stat amber" id="ult-stat-pending-wrap">
				<span class="ult-stat-n" id="ult-pending-count"><?php echo esc_html( number_format_i18n( $unoptimized ) ); ?></span>
				<span class="ult-stat-l">Pendientes</span>
			</div>
			<div class="ult-stat blue">
				<span class="ult-stat-n" id="ult-stat-savings"><?php echo esc_html( size_format( $stats['total_savings_bytes'] ) ); ?></span>
				<span class="ult-stat-l">Espacio recuperado</span>
			</div>
			<div class="ult-stat">
				<span class="ult-stat-n" id="ult-stat-avg"><?php echo esc_html( $stats['avg_savings_percent'] ); ?>%</span>
				<span class="ult-stat-l">Reducción media</span>
			</div>
		</div>

		<div class="ult-scan-panel">
			<div class="ult-scan-top">
				<div>
					<h2>Biblioteca de imágenes</h2>
					<p>Escanea para ver el estado, peso y ahorro estimado de cada imagen antes de optimizar.</p>
				</div>
				<div class="ult-scan-actions">
					<button id="ult-scan-btn" class="button button-primary ult-btn-lg">
						<span class="dashicons dashicons-search"></span>&nbsp;Escanear biblioteca
					</button>
					<button id="ult-optimize-btn" class="button ult-btn-purple ult-btn-lg" style="display:none">
						<span class="dashicons dashicons-performance"></span>&nbsp;Iniciar optimización
					</button>
					<button id="ult-pause-btn" class="button button-secondary ult-btn-lg" style="display:none">Pausar</button>
				</div>
			</div>

			<div id="ult-scan-progress" style="display:none" class="ult-progress-wrap">
				<div class="ult-track"><div class="ult-bar" id="ult-scan-bar" style="width:0%"></div></div>
				<p class="ult-progress-txt" id="ult-scan-status">Preparando escaneo...</p>
			</div>

			<div id="ult-opt-progress" style="display:none" class="ult-progress-wrap">
				<div class="ult-opt-labels">
					<span id="ult-opt-status">Procesando en servidor&hellip;</span>
					<div class="ult-opt-right">
						<span class="ult-opt-timer" id="ult-opt-timer">00:00</span>
						<strong id="ult-opt-pct">0%</strong>
					</div>
				</div>
				<div class="ult-track lg"><div class="ult-bar" id="ult-opt-bar" style="width:0%"></div></div>
			</div>

			<div id="ult-scan-results" style="display:none">
				<div class="ult-results-summary" id="ult-results-summary"></div>
				<div class="ult-table-wrap">
					<table class="ult-image-table widefat">
						<thead>
							<tr>
								<th class="col-thumb"></th>
								<th>Nombre del archivo</th>
								<th>Formato</th>
								<th>Peso actual</th>
								<th>Ahorro estimado</th>
								<th>Estado</th>
							</tr>
						</thead>
						<tbody id="ult-image-tbody"></tbody>
					</table>
				</div>
			</div>
		</div><!-- .ult-scan-panel -->

		<div class="ult-server-card">
			<h3>Motor de procesamiento</h3>
			<div class="ult-server-grid">
				<div class="ult-server-item">
					<span class="ult-label">Motor</span>
					<?php echo extension_loaded( 'imagick' ) ? '<span class="ult-pill green">Imagick</span>' : '<span class="ult-pill amber">GD (básico)</span>'; ?>
				</div>
				<div class="ult-server-item">
					<span class="ult-label">AVIF</span>
					<?php echo $optimizer->supports_avif() ? '<span class="ult-pill green">Disponible</span>' : '<span class="ult-pill red">No disponible</span>'; ?>
				</div>
				<div class="ult-server-item">
					<span class="ult-label">WebP</span>
					<?php echo $optimizer->supports_webp() ? '<span class="ult-pill green">Disponible</span>' : '<span class="ult-pill red">No disponible</span>'; ?>
				</div>
				<div class="ult-server-item">
					<span class="ult-label">PHP</span>
					<span><?php echo esc_html( phpversion() ); ?></span>
				</div>
				<div class="ult-server-item">
					<span class="ult-label">Directorio de respaldos</span>
					<?php
					$backup_dir = Ultimizer_Backup::get_backup_dir();
					echo is_writable( $backup_dir )
						? '<span class="ult-pill green">Escribible</span>'
						: '<span class="ult-pill red">Sin permisos</span>';
					?>
				</div>
			</div>
		</div>

	<?php
	// =========================================================================
	// SETTINGS
	// =========================================================================
	elseif ( 'settings' === $tab ) :
	?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ult-settings-form">
			<input type="hidden" name="action" value="ultimizer_save_settings">
			<?php wp_nonce_field( 'ultimizer_save_settings' ); ?>

			<div class="ult-card">
				<div class="ult-card-header">
					<span class="dashicons dashicons-images-alt2"></span>
					<div>
						<h3>Calidad de compresión</h3>
						<p>Controla el equilibrio entre calidad visual y reducción de peso para cada formato de imagen.</p>
					</div>
				</div>
				<div class="ult-quality-grid">
					<?php
					$quality_fields = [
						[ 'id' => 'jpeg_quality',    'label' => 'JPEG',  'min' => 1,  'max' => 100, 'hint' => 'Recomendado: 80–85',   'desc' => 'Mayor compresión ← → Mayor calidad' ],
						[ 'id' => 'png_compression', 'label' => 'PNG',   'min' => 0,  'max' => 9,   'hint' => 'Recomendado: 6',        'desc' => 'Sin comprimir ← → Máx. compresión' ],
						[ 'id' => 'webp_quality',    'label' => 'WebP',  'min' => 1,  'max' => 100, 'hint' => 'Recomendado: 78–82',   'desc' => 'Mayor compresión ← → Mayor calidad' ],
						[ 'id' => 'avif_quality',    'label' => 'AVIF',  'min' => 1,  'max' => 100, 'hint' => 'AVIF logra excelente calidad con 60–70', 'desc' => 'Mayor compresión ← → Mayor calidad' ],
					];
					foreach ( $quality_fields as $f ) :
						$val = isset( $settings[ $f['id'] ] ) ? (int) $settings[ $f['id'] ] : 0;
					?>
					<div class="ult-quality-item">
						<div class="ult-quality-top">
							<label for="<?php echo esc_attr( $f['id'] ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
							<span class="ult-quality-val" id="<?php echo esc_attr( $f['id'] ); ?>_val"><?php echo esc_html( $val ); ?></span>
						</div>
						<input type="range"
							id="<?php echo esc_attr( $f['id'] ); ?>"
							name="<?php echo esc_attr( $f['id'] ); ?>"
							min="<?php echo esc_attr( $f['min'] ); ?>"
							max="<?php echo esc_attr( $f['max'] ); ?>"
							value="<?php echo esc_attr( $val ); ?>"
							class="ult-range"
						>
						<p class="ult-range-desc"><?php echo esc_html( $f['desc'] ); ?></p>
						<p class="ult-range-hint"><?php echo esc_html( $f['hint'] ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">Guardar configuración</button>
			</p>
		</form>

		<script>
		document.querySelectorAll('.ult-range').forEach(function(r){
			var v = document.getElementById(r.id + '_val');
			if(v){ r.addEventListener('input', function(){ v.textContent = this.value; }); }
		});
		</script>

	<?php
	// =========================================================================
	// LOG
	// =========================================================================
	elseif ( 'log' === $tab ) :
		$entries     = $logger->get_entries( 200, 0 );
		$total_count = $logger->count_entries();
	?>

		<div class="ult-section-header">
			<div class="ult-section-meta">
				<h2>Registro de optimizaciones</h2>
				<span class="ult-count-pill"><?php echo esc_html( number_format_i18n( $total_count ) ); ?> entradas</span>
			</div>
			<?php if ( $total_count > 0 ) : ?>
			<button id="ult-clear-log" class="button button-secondary">Vaciar registro</button>
			<?php endif; ?>
		</div>

		<?php if ( $stats['total_entries'] > 0 ) : ?>
		<div class="ult-log-summary">
			<div class="ult-log-stat">
				<span class="ult-log-stat-n"><?php echo esc_html( size_format( $stats['total_savings_bytes'] ) ); ?></span>
				<span class="ult-log-stat-l">Total recuperado</span>
			</div>
			<div class="ult-log-stat">
				<span class="ult-log-stat-n"><?php echo esc_html( $stats['avg_savings_percent'] ); ?>%</span>
				<span class="ult-log-stat-l">Reducción media</span>
			</div>
			<div class="ult-log-stat">
				<span class="ult-log-stat-n"><?php echo esc_html( number_format_i18n( $stats['total_entries'] ) ); ?></span>
				<span class="ult-log-stat-l">Imágenes procesadas</span>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( empty( $entries ) ) : ?>
		<div class="ult-empty">
			<span class="dashicons dashicons-chart-line"></span>
			<p>No hay registros aún. Los datos aparecerán aquí después de optimizar imágenes.</p>
		</div>
		<?php else : ?>
		<div class="ult-table-wrap">
			<table class="ult-log-table widefat">
				<thead>
					<tr>
						<th>Archivo</th>
						<th>Antes</th>
						<th>Después</th>
						<th>Ahorro</th>
						<th>Fecha</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) :
						$pct     = (float) $e['savings_percent'];
						$savings = (int) $e['savings_bytes'];
						$pct_class = $pct > 0 ? 'ult-savings-pos' : ( $pct < 0 ? 'ult-savings-neg' : 'ult-savings-zero' );
					?>
					<tr>
						<td>
							<span class="ult-filename"><?php echo esc_html( basename( $e['file_path'] ) ); ?></span>
							<small class="ult-aid">#<?php echo esc_html( $e['attachment_id'] ); ?></small>
						</td>
						<td><?php echo esc_html( size_format( (int) $e['original_size'] ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) $e['optimized_size'] ) ); ?></td>
						<td>
							<span class="ult-savings-pct <?php echo esc_attr( $pct_class ); ?>">
								<?php echo $pct > 0 ? '-' : ( $pct < 0 ? '+' : '' ); echo esc_html( abs( $pct ) ); ?>%
							</span>
							<?php if ( $savings > 0 ) : ?>
							<small class="ult-savings-bytes"><?php echo esc_html( size_format( $savings ) ); ?></small>
							<?php elseif ( $pct < 0 ) : ?>
							<small class="ult-savings-note">imagen ya optimizada</small>
							<?php endif; ?>
						</td>
						<td><span class="ult-date"><?php echo esc_html( $e['optimized_at'] ); ?></span></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

	<?php
	// =========================================================================
	// BACKUPS
	// =========================================================================
	elseif ( 'backups' === $tab ) :
		$backups       = $backup->get_backups( 200, 0 );
		$total_back    = $backup->count_backups();
		$total_back_sz = $backup->get_total_backup_size();
	?>

		<div class="ult-section-header">
			<div class="ult-section-meta">
				<h2>Respaldos almacenados</h2>
				<span class="ult-count-pill"><?php echo esc_html( number_format_i18n( $total_back ) ); ?> archivos</span>
				<?php if ( $total_back_sz > 0 ) : ?>
				<span class="ult-count-pill gray" id="ult-backup-total-size"><?php echo esc_html( size_format( $total_back_sz ) ); ?> en disco</span>
				<?php endif; ?>
			</div>
			<?php if ( $total_back > 0 ) : ?>
			<button id="ult-delete-all-backups" class="button button-secondary">
				<span class="dashicons dashicons-trash" style="vertical-align:middle;margin-top:-2px"></span>
				Eliminar todos los respaldos
			</button>
			<?php endif; ?>
		</div>
		<p class="description" style="margin-bottom:16px">
			Cada respaldo es el archivo original antes de optimizar. Puedes restaurar en cualquier momento o eliminar los respaldos para liberar espacio en disco. <strong>Sin respaldo no se puede restaurar la imagen.</strong>
		</p>

		<?php if ( empty( $backups ) ) : ?>
		<div class="ult-empty">
			<span class="dashicons dashicons-backup"></span>
			<p>No hay respaldos aún. Se crean automáticamente al optimizar cada imagen.</p>
		</div>
		<?php else : ?>
		<div class="ult-table-wrap">
			<table class="ult-log-table widefat">
				<thead>
					<tr>
						<th>Imagen</th>
						<th>Archivo original</th>
						<th>Tamaño</th>
						<th>Formatos modernos</th>
						<th>Optimizado el</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $b ) : ?>
					<tr id="ult-backup-row-<?php echo esc_attr( $b['attachment_id'] ); ?>" class="<?php echo ! $b['exists'] ? 'ult-row-missing' : ''; ?>">
						<td><?php echo esc_html( $b['title'] ?: '(sin título)' ); ?></td>
						<td>
							<span class="ult-filename"><?php echo esc_html( $b['filename'] ); ?></span>
							<?php if ( ! $b['exists'] ) : ?>
								<span class="ult-pill red sm">No encontrado</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $b['size'] ); ?></td>
						<td>
							<?php if ( $b['has_avif'] ) : ?>
								<span class="ult-pill green sm">AVIF</span>
							<?php endif; ?>
							<?php if ( $b['has_webp'] ) : ?>
								<span class="ult-pill blue sm">WebP</span>
							<?php endif; ?>
							<?php if ( ! $b['has_avif'] && ! $b['has_webp'] ) : ?>
								<span class="ult-muted">—</span>
							<?php endif; ?>
						</td>
						<td><span class="ult-date"><?php echo esc_html( $b['optimized_at'] ); ?></span></td>
						<td class="ult-backup-actions">
							<?php if ( $b['exists'] ) : ?>
							<button class="button button-small ult-restore-btn"
								data-id="<?php echo esc_attr( $b['attachment_id'] ); ?>"
								title="Restaurar la imagen original">
								Restaurar
							</button>
							<?php endif; ?>
							<button class="button button-small ult-delete-backup-btn"
								data-id="<?php echo esc_attr( $b['attachment_id'] ); ?>"
								title="Eliminar este respaldo para liberar espacio"
								<?php echo ! $b['exists'] ? 'style="margin-left:0"' : ''; ?>>
								Eliminar respaldo
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

	<?php endif; ?>

	</div><!-- .ult-content -->

	<div class="ult-donate-bar">
		<div class="ult-donate-inner">
			<span class="ult-donate-text">
				<span class="dashicons dashicons-heart"></span>
				¿Te ha sido útil Ultimizer? Puedes invitarme un café.
			</span>
			<div class="ult-donate-buttons">
				<a href="https://paypal.me/kerackdiaz/1" target="_blank" rel="noopener" class="button ult-donate-btn">$1</a>
				<a href="https://paypal.me/kerackdiaz/5" target="_blank" rel="noopener" class="button ult-donate-btn">$5</a>
				<a href="https://paypal.me/kerackdiaz/10" target="_blank" rel="noopener" class="button ult-donate-btn">$10</a>
			</div>
		</div>
	</div>

</div><!-- .ult-wrap -->
