/**
 * Ultimizer – Optimizador masivo (bulk).
 * Gestiona el procesamiento en lotes mediante AJAX, barra de progreso y log en tiempo real.
 */

/* global ultimizerData */
(function ($) {
	'use strict';

	if (typeof ultimizerData === 'undefined') {
		return;
	}

	var cfg        = ultimizerData;
	var running    = false;
	var paused     = false;
	var totalStart = 0;
	var processed  = 0;
	var savedBytes = 0;

	var $startBtn    = $('#ult-start-bulk');
	var $pauseBtn    = $('#ult-pause-bulk');
	var $progressWrap = $('#ult-bulk-progress-wrap');
	var $progressFill = $('#ult-progress-fill');
	var $progressPct  = $('#ult-progress-pct');
	var $progressLbl  = $('#ult-progress-label');
	var $log          = $('#ult-bulk-log');
	var $remaining    = $('#ult-unoptimized-count');

	// -------------------------------------------------------------------------
	// Inicio
	// -------------------------------------------------------------------------

	$startBtn.on('click', function () {
		if (running) return;

		running    = true;
		paused     = false;
		processed  = 0;
		savedBytes = 0;

		// Obtener el total actual antes de empezar.
		getStats(function (stats) {
			totalStart = parseInt(stats.optimized, 10) + parseInt(stats.unoptimized, 10);

			$startBtn.hide();
			$pauseBtn.show();
			$progressWrap.show();
			$progressLbl.text(cfg.strings.running);

			processBatch();
		});
	});

	// -------------------------------------------------------------------------
	// Pausa / Reanudar
	// -------------------------------------------------------------------------

	$pauseBtn.on('click', function () {
		if (!running) return;

		paused = !paused;

		if (paused) {
			$pauseBtn.text(cfg.strings.resume);
			$progressLbl.text(cfg.strings.paused);
		} else {
			$pauseBtn.text(cfg.strings.pause);
			$progressLbl.text(cfg.strings.running);
			processBatch();
		}
	});

	// -------------------------------------------------------------------------
	// Restaurar respaldos
	// -------------------------------------------------------------------------

	$(document).on('click', '.ult-restore-btn', function () {
		var $btn = $(this);
		var id   = $btn.data('id');

		if (!id) return;
		if (!confirm('¿Restaurar la imagen original? Esto eliminará la versión optimizada.')) return;

		$btn.prop('disabled', true).text('Restaurando...');

		$.post(cfg.ajaxUrl, {
			action:        'ultimizer_restore_backup',
			nonce:         cfg.nonce,
			attachment_id: id
		}, function (resp) {
			if (resp.success) {
				$btn.closest('tr').css('opacity', '0.5');
				$btn.text('Restaurado ✓');
			} else {
				alert('Error: ' + (resp.data || 'No se pudo restaurar.'));
				$btn.prop('disabled', false).text('Restaurar original');
			}
		}).fail(function () {
			alert('Error de conexión.');
			$btn.prop('disabled', false).text('Restaurar original');
		});
	});

	// -------------------------------------------------------------------------
	// Vaciar log
	// -------------------------------------------------------------------------

	$(document).on('click', '#ult-clear-log', function () {
		if (!confirm('¿Vaciar todo el registro? Esta acción no se puede deshacer.')) return;

		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(cfg.ajaxUrl, {
			action: 'ultimizer_clear_log',
			nonce:  cfg.nonce
		}, function (resp) {
			if (resp.success) {
				location.reload();
			} else {
				alert('Error: ' + (resp.data || 'No se pudo vaciar el registro.'));
				$btn.prop('disabled', false);
			}
		}).fail(function () {
			alert('Error de conexión.');
			$btn.prop('disabled', false);
		});
	});

	// -------------------------------------------------------------------------
	// Funciones internas
	// -------------------------------------------------------------------------

	function getStats(callback) {
		$.post(cfg.ajaxUrl, {
			action: 'ultimizer_get_stats',
			nonce:  cfg.nonce
		}, function (resp) {
			if (resp.success && typeof callback === 'function') {
				callback(resp.data);
			}
		});
	}

	function processBatch() {
		if (!running || paused) return;

		$.post(cfg.ajaxUrl, {
			action:     'ultimizer_bulk_process_batch',
			nonce:      cfg.nonce,
			batch_size: cfg.batchSize
		}, function (resp) {
			if (!resp.success) {
				appendLog(cfg.strings.error + ': ' + (resp.data || ''), 'error');
				finishRun();
				return;
			}

			var data = resp.data;

			processed  += parseInt(data.processed, 10);
			savedBytes += parseSavedBytes(data.total_saved);

			// Actualizar contador visible de pendientes.
			$remaining.text(numberFormat(data.remaining));

			// Actualizar barra de progreso.
			if (totalStart > 0) {
				var done    = totalStart - data.remaining;
				var percent = Math.min(100, Math.round((done / totalStart) * 100));
				$progressFill.css('width', percent + '%');
				$progressPct.text(percent + '%');
			}

			// Log de los resultados del lote.
			if (data.results && data.results.length) {
				data.results.forEach(function (item) {
					if (item.error) {
						appendLog('#' + item.id + ' → Error: ' + item.error, 'error');
					} else if (item.skipped) {
						appendLog('#' + item.id + ' → Omitida (ya optimizada)', 'skip');
					} else {
						var extras = [];
						if (item.avif) extras.push('AVIF');
						if (item.webp) extras.push('WebP');
						appendLog(
							'#' + item.id + ' → ↓' + item.savings_percent + '%  ' +
							(extras.length ? '(' + extras.join(', ') + ' generados)' : ''),
							'success'
						);
					}
				});
			}

			// Actualizar etiqueta de progreso.
			$progressLbl.text(
				cfg.strings.running + ' — ' + numberFormat(data.remaining) + ' pendientes'
			);

			if (data.done) {
				finishRun();
				return;
			}

			// Siguiente lote con pequeña pausa para no saturar el servidor.
			setTimeout(function () {
				if (!paused) {
					processBatch();
				}
			}, 300);

		}).fail(function () {
			appendLog('Error de conexión con el servidor.', 'error');
			finishRun();
		});
	}

	function finishRun() {
		running = false;
		$pauseBtn.hide();
		$startBtn.show().text('Optimización completada ✓').prop('disabled', true);
		$progressFill.css('width', '100%');
		$progressPct.text('100%');
		$progressLbl.text(cfg.strings.done);
	}

	function appendLog(text, type) {
		var cls = type || 'info';
		$log.append('<div class="log-line ' + cls + '">' + escHtml(text) + '</div>');
		$log.scrollTop($log[0].scrollHeight);
	}

	function numberFormat(n) {
		return parseInt(n, 10).toLocaleString();
	}

	function parseSavedBytes(str) {
		// La cadena viene formateada (e.g. "1.5 MB"), se ignora para cálculo.
		return 0;
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

}(jQuery));
