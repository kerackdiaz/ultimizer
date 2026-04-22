/**
 * Ultimizer – Lógica del panel: escaneo, optimización masiva y acciones.
 */

/* global ultimizerData */
(function ($) {
	'use strict';

	if ( typeof ultimizerData === 'undefined' ) { return; }

	var cfg = ultimizerData;

	// Clave de localStorage (scoped al sitio).
	var LS_KEY = 'ultimizer_scan_v1_' + ( cfg.ajaxUrl || '' ).replace( /\W/g, '' ).slice( -12 );

	// Estado del escaneo
	var scanItems   = [];
	var totalImages = 0;
	var optTotal    = 0;

	// Acumuladores de stats en tiempo real (optimización en curso).
	var sessionSavedBytes = 0;
	var sessionSavedPcts  = [];

	// Estado de la optimización
	var optimizing    = false;
	var paused        = false;
	var optDone       = 0;
	var startTime     = null;
	var timerInterval = null;

	// Refs DOM
	var $scanBtn     = $( '#ult-scan-btn' );
	var $optimizeBtn = $( '#ult-optimize-btn' );
	var $pauseBtn    = $( '#ult-pause-btn' );
	var $scanProg    = $( '#ult-scan-progress' );
	var $scanBar     = $( '#ult-scan-bar' );
	var $scanStatus  = $( '#ult-scan-status' );
	var $optProg     = $( '#ult-opt-progress' );
	var $optBar      = $( '#ult-opt-bar' );
	var $optStatus   = $( '#ult-opt-status' );
	var $optPct      = $( '#ult-opt-pct' );
	var $results     = $( '#ult-scan-results' );
	var $tbody       = $( '#ult-image-tbody' );
	var $summary     = $( '#ult-results-summary' );
	var $pending     = $( '#ult-pending-count' );
	var $statOpt     = $( '#ult-stat-optimized' );
	var $statSavings = $( '#ult-stat-savings' );
	var $statAvg     = $( '#ult-stat-avg' );

	// =========================================================================
	// RESTAURAR ESCANEO ANTERIOR AL CARGAR
	// =========================================================================

	( function restoreScanFromStorage() {
		try {
			var raw = localStorage.getItem( LS_KEY );
			if ( ! raw ) { return; }
			var saved = JSON.parse( raw );
			if ( ! saved || ! saved.items || ! saved.items.length ) { return; }

			scanItems   = saved.items;
			totalImages = saved.total;

			saved.items.forEach( function ( item ) { appendScanRow( item ); } );
			finishScan( saved.total, saved.time );
		} catch ( e ) {}
	}() );

	// =========================================================================
	// ESCANEO
	// =========================================================================

	$scanBtn.on( 'click', function () {
		// Reset + borrar caché del escaneo anterior.
		try { localStorage.removeItem( LS_KEY ); } catch ( e ) {}
		scanItems   = [];
		totalImages = 0;
		optTotal    = 0;
		$tbody.empty();
		$results.hide();
		$optimizeBtn.hide();
		$optProg.hide();
		$scanProg.show();
		$scanBar.css( 'width', '0%' );
		$scanStatus.text( 'Preparando escaneo...' );
		$scanBtn.prop( 'disabled', true ).text( 'Escaneando...' );

		// Obtener totales primero
		$.post( cfg.ajaxUrl, { action: 'ultimizer_get_stats', nonce: cfg.nonce }, function ( r ) {
			if ( r.success ) {
				totalImages = parseInt( r.data.optimized, 10 ) + parseInt( r.data.unoptimized, 10 );
				optTotal    = parseInt( r.data.unoptimized, 10 );
			}
			runScanBatch( 0, totalImages );
		} );
	} );

	function runScanBatch( offset, total ) {
		$.post( cfg.ajaxUrl, {
			action: 'ultimizer_scan_batch',
			nonce:  cfg.nonce,
			limit:  20,
			offset: offset
		}, function ( r ) {
			if ( ! r.success ) {
				$scanStatus.text( 'Error al escanear la biblioteca.' );
				resetScanBtn();
				return;
			}

			var data = r.data;

			// Actualizar total real desde el servidor
			if ( ! total ) { total = data.total; }

			data.items.forEach( function ( item ) {
				scanItems.push( item );
				appendScanRow( item );
			} );

			var scanned = data.offset;
			var pct     = total > 0 ? Math.min( 100, Math.round( scanned / total * 100 ) ) : 0;
			$scanBar.css( 'width', pct + '%' );
			$scanStatus.text( 'Escaneando… ' + scanned + ' / ' + data.total );

			if ( data.done ) {
				finishScan( data.total );
			} else {
				setTimeout( function () { runScanBatch( data.offset, data.total ); }, 80 );
			}
		} ).fail( function () {
			$scanStatus.text( 'Error de conexión.' );
			resetScanBtn();
		} );
	}

	function appendScanRow( item ) {
		var isOpt     = item.is_optimized;
		var isExcl    = item.is_excluded;
		var statusHtml;
		if ( isExcl ) {
			statusHtml = '<span class="ult-pill gray sm">Excluida</span>';
		} else if ( isOpt ) {
			statusHtml = '<span class="ult-pill green sm">Optimizada</span>';
		} else {
			statusHtml = '<span class="ult-pill amber sm">Pendiente</span>';
		}

		var savings  = isOpt
			? ( '<strong>' + item.savings_pct_actual + '%</strong>' )
			: ( isExcl ? '—' : '~' + item.estimated_savings_pct + '% <small>est. (' + esc( item.estimated_savings_hr ) + ')</small>' );
		var thumb    = item.thumb_url
			? '<img src="' + esc( item.thumb_url ) + '" alt="" class="ult-thumb">'
			: '<span class="ult-no-thumb dashicons dashicons-format-image"></span>';

		var rowClass = isExcl ? ' class="ult-row-excluded"' : ( isOpt ? ' class="ult-row-done"' : '' );

		$tbody.append(
			'<tr id="ult-row-' + item.id + '"' + rowClass + '>' +
			'<td class="col-exclude"><input type="checkbox" class="ult-excl-cb" data-id="' + item.id + '"' + ( isExcl ? ' checked' : '' ) + ' title="Excluir de la optimizaci\u00f3n"></td>' +
			'<td class="col-thumb">' + thumb + '</td>' +
			'<td><span class="ult-filename">' + esc( item.filename ) + '</span></td>' +
			'<td><span class="ult-pill gray sm">' + fmtMime( item.mime_type ) + '</span></td>' +
			'<td>' + esc( item.current_size_hr ) + '</td>' +
			'<td>' + savings + '</td>' +
			'<td class="ult-col-status">' + statusHtml + '</td>' +
			'</tr>'
		);
	}

	function finishScan( total ) {
		$scanProg.hide();
		$results.show();
		resetScanBtn( 'Volver a escanear' );

		var pending = scanItems.filter( function ( i ) { return ! i.is_optimized && ! i.is_excluded; } );
		optTotal = pending.length; // sincronizar con los que realmente se van a procesar.

		var estBytes = 0;
		pending.forEach( function ( i ) {
			estBytes += Math.round( i.current_size * ( i.estimated_savings_pct / 100 ) );
		} );

		$summary.html(
			'<div class="ult-results-summary-inner">' +
			'<span><strong>' + total + '</strong> imágenes en la biblioteca</span>' +
			'<span><strong>' + pending.length + '</strong> pendientes de optimizar</span>' +
			'<span>Ahorro estimado: <strong>' + fmtBytes( estBytes ) + '</strong></span>' +
			'</div>'
		);

		if ( pending.length > 0 ) {
			$optimizeBtn.show();
		}
	}

	function resetScanBtn( label ) {
		$scanBtn.prop( 'disabled', false ).html(
			'<span class="dashicons dashicons-search"></span>&nbsp;' + ( label || 'Escanear biblioteca' )
		);
	}

	// =========================================================================
	// OPTIMIZACIÓN
	// =========================================================================

	$optimizeBtn.on( 'click', function () {
		if ( optimizing ) { return; }
		optimizing        = true;
		paused            = false;
		optDone           = 0;
		startTime         = Date.now();
		sessionSavedBytes = 0;
		sessionSavedPcts  = [];

		// Advertir si el usuario intenta salir mientras optimiza.
		window.onbeforeunload = function () {
			return 'La optimizaci\u00f3n est\u00e1 en curso. Si sales, el lote actual terminar\u00e1 en el servidor pero no se procesar\u00e1n m\u00e1s im\u00e1genes hasta que vuelvas a iniciarla.';
		};

		// Iniciar temporizador.
		timerInterval = setInterval( function () {
			if ( ! paused && optimizing ) {
				var elapsed = Math.floor( ( Date.now() - startTime ) / 1000 );
				var mm = String( Math.floor( elapsed / 60 ) ).padStart( 2, '0' );
				var ss = String( elapsed % 60 ).padStart( 2, '0' );
				$( '#ult-opt-timer' ).text( mm + ':' + ss );
			}
		}, 1000 );

		$optimizeBtn.hide();
		$pauseBtn.show().text( 'Pausar' );
		$optProg.show();
		updateOptUI();
		runOptBatch();
	} );

	$pauseBtn.on( 'click', function () {
		paused = ! paused;
		if ( paused ) {
			$pauseBtn.text( 'Reanudar' );
			$optStatus.text( 'En pausa' );
		} else {
			$pauseBtn.text( 'Pausar' );
			$optStatus.text( 'Optimizando...' );
			runOptBatch();
		}
	} );

	function runOptBatch() {
		if ( ! optimizing || paused ) { return; }

		$.post( cfg.ajaxUrl, {
			action:     'ultimizer_bulk_process_batch',
			nonce:      cfg.nonce,
			batch_size: cfg.batchSize
		}, function ( r ) {
			if ( ! r.success ) {
				$optStatus.text( 'Error procesando el lote.' );
				finishOpt();
				return;
			}

			var data = r.data;

			// Actualizar filas en la tabla de escaneo.
			data.results.forEach( function ( item ) {
				if ( item.error || item.skipped ) { return; }
				var $row = $( '#ult-row-' + item.id );
				if ( $row.length ) {
					$row.find( '.ult-col-status' ).html( '<span class="ult-pill green sm">Optimizada</span>' );
					$row.find( 'td:eq(5)' ).html( '<strong>' + item.savings_percent + '%</strong> <small>' + fmtBytes( item.savings_bytes ) + '</small>' );
					$row.removeClass( 'ult-row-excluded' ).addClass( 'ult-row-done' );
				}
				// Acumular para tarjetas.
				sessionSavedBytes += ( item.savings_bytes || 0 );
				if ( item.savings_percent ) { sessionSavedPcts.push( parseFloat( item.savings_percent ) ); }
			} );

			// Progreso basado en restantes (preciso con omitidas/errores).
			optDone = Math.max( optDone, optTotal - data.remaining );
			$pending.text( data.remaining );
			updateOptUI();
			updateStatCards( data.remaining );

			if ( data.done || data.remaining === 0 ) {
				finishOpt();
				return;
			}

			setTimeout( function () {
				if ( ! paused ) { runOptBatch(); }
			}, 300 );

		} ).fail( function () {
			$optStatus.text( 'Error de conexión.' );
			finishOpt();
		} );
	}

	function updateOptUI() {
		var pct = optTotal > 0 ? Math.min( 100, Math.round( optDone / optTotal * 100 ) ) : 0;
		$optBar.css( 'width', pct + '%' );
		$optPct.text( pct + '%' );
		$optStatus.text( 'Procesando ' + optDone + ' de ' + optTotal );
	}

	/**
	 * Actualiza las tarjetas de estadísticas en tiempo real mientras se optimiza.
	 * @param {number} remaining  Pendientes según el servidor.
	 */
	function updateStatCards( remaining ) {
		// Optimizadas = total - remaining (sin excluidas).
		var total   = parseInt( $( '#ult-stat-total' ).text().replace( /\D/g, '' ), 10 ) || 0;
		var newOpt  = total - remaining;
		$statOpt.text( newOpt );
		$pending.text( remaining );

		// Ahorro acumulado en esta sesión más el que ya estaba al cargar la página.
		if ( sessionSavedBytes > 0 ) {
			$statSavings.text( fmtBytes( sessionSavedBytes ) + '+' );
		}

		// Reducción media de la sesión.
		if ( sessionSavedPcts.length > 0 ) {
			var sum = sessionSavedPcts.reduce( function ( a, b ) { return a + b; }, 0 );
			$statAvg.text( ( sum / sessionSavedPcts.length ).toFixed( 1 ) + '%' );
		}
	}

	function finishOpt() {
		optimizing = false;
		window.onbeforeunload = null; // quitar advertencia al salir.
		clearInterval( timerInterval );

		var elapsed = startTime ? Math.floor( ( Date.now() - startTime ) / 1000 ) : 0;
		var mm = String( Math.floor( elapsed / 60 ) ).padStart( 2, '0' );
		var ss = String( elapsed % 60 ).padStart( 2, '0' );

		$pauseBtn.hide();
		$optBar.css( 'width', '100%' );
		$optPct.text( '100%' );
		$optStatus.html( 'Optimización completada ✓ &nbsp;<small>Tiempo total: ' + mm + ':' + ss + '</small>' );
		$optimizeBtn.hide();
	}

	// =========================================================================
	// EXCLUIR / INCLUIR imagen
	// =========================================================================

	$( document ).on( 'change', '.ult-excl-cb', function () {
		var $cb  = $( this );
		var id   = parseInt( $cb.data( 'id' ), 10 );
		var excl = $cb.is( ':checked' ) ? '1' : '0';

		$cb.prop( 'disabled', true );

		$.post( cfg.ajaxUrl, {
			action:        'ultimizer_toggle_exclude',
			nonce:         cfg.nonce,
			attachment_id: id,
			exclude:       excl
		}, function ( r ) {
			$cb.prop( 'disabled', false );
			if ( ! r.success ) { $cb.prop( 'checked', ! $cb.is( ':checked' ) ); return; }

			var $row = $( '#ult-row-' + id );
			if ( excl === '1' ) {
				$row.addClass( 'ult-row-excluded' ).removeClass( 'ult-row-done' );
				$row.find( '.ult-col-status' ).html( '<span class="ult-pill gray sm">Excluida</span>' );
				$row.find( 'td:eq(5)' ).text( '—' );
			} else {
				$row.removeClass( 'ult-row-excluded' );
				$row.find( '.ult-col-status' ).html( '<span class="ult-pill amber sm">Pendiente</span>' );
			}
			// Actualizar contador de pendientes en stats strip.
			$pending.text( r.data.pending );

			// Mostrar u ocultar botón de optimización.
			var hasPending = $tbody.find( '.ult-pill.amber' ).length > 0;
			if ( hasPending ) { $optimizeBtn.show(); } else { $optimizeBtn.hide(); }
		} ).fail( function () {
			$cb.prop( 'disabled', false ).prop( 'checked', ! $cb.is( ':checked' ) );
		} );
	} );

	// =========================================================================
	// RESTAURAR RESPALDO
	// =========================================================================

	$( document ).on( 'click', '.ult-restore-btn', function () {
		var $btn = $( this );
		var id   = parseInt( $btn.data( 'id' ), 10 );
		if ( ! id ) { return; }
		if ( ! confirm( '¿Restaurar la imagen original? La versión optimizada será reemplazada.' ) ) { return; }

		$btn.prop( 'disabled', true ).text( 'Restaurando...' );

		$.post( cfg.ajaxUrl, {
			action:        'ultimizer_restore_backup',
			nonce:         cfg.nonce,
			attachment_id: id
		}, function ( r ) {
			if ( r.success ) {
				$btn.closest( 'tr' ).css( 'opacity', '.5' );
				$btn.text( 'Restaurado ✓' );
			} else {
				alert( 'Error: ' + ( r.data || 'No se pudo restaurar.' ) );
				$btn.prop( 'disabled', false ).text( 'Restaurar original' );
			}
		} ).fail( function () {
			alert( 'Error de conexión.' );
			$btn.prop( 'disabled', false ).text( 'Restaurar original' );
		} );
	} );

	// =========================================================================
	// ELIMINAR RESPALDO INDIVIDUAL
	// =========================================================================

	$( document ).on( 'click', '.ult-delete-backup-btn', function () {
		var $btn = $( this );
		var id   = parseInt( $btn.data( 'id' ), 10 );
		if ( ! id ) { return; }
		if ( ! confirm( '¿Eliminar el respaldo de esta imagen? No podrás restaurar la versión original.' ) ) { return; }

		$btn.prop( 'disabled', true ).text( 'Eliminando...' );

		$.post( cfg.ajaxUrl, {
			action:        'ultimizer_delete_backup',
			nonce:         cfg.nonce,
			attachment_id: id
		}, function ( r ) {
			if ( r.success ) {
				$( '#ult-backup-row-' + id ).fadeOut( 300, function () { $( this ).remove(); } );
				// Actualizar contador y tamaño en header.
				var $pill = $( '.ult-count-pill' ).first();
				var remaining = r.data.remaining_count;
				$pill.text( remaining + ( remaining === 1 ? ' archivo' : ' archivos' ) );
				$( '#ult-backup-total-size' ).text( r.data.remaining_size + ' en disco' );
				if ( remaining === 0 ) {
					$( '#ult-delete-all-backups' ).hide();
					$( '.ult-table-wrap' ).replaceWith(
						'<div class="ult-empty"><span class="dashicons dashicons-backup"></span><p>No hay respaldos aún.</p></div>'
					);
				}
			} else {
				alert( 'Error: ' + ( r.data || 'No se pudo eliminar.' ) );
				$btn.prop( 'disabled', false ).text( 'Eliminar respaldo' );
			}
		} ).fail( function () {
			alert( 'Error de conexión.' );
			$btn.prop( 'disabled', false ).text( 'Eliminar respaldo' );
		} );
	} );

	// =========================================================================
	// ELIMINAR TODOS LOS RESPALDOS
	// =========================================================================

	$( document ).on( 'click', '#ult-delete-all-backups', function () {
		if ( ! confirm( '¿Eliminar TODOS los respaldos? Esta acción no se puede deshacer. Sin respaldos no podrás restaurar ninguna imagen a su estado original.' ) ) { return; }

		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Eliminando...' );

		$.post( cfg.ajaxUrl, {
			action: 'ultimizer_delete_all_backups',
			nonce:  cfg.nonce
		}, function ( r ) {
			if ( r.success ) { location.reload(); }
			else { alert( 'Error.' ); $btn.prop( 'disabled', false ).text( 'Eliminar todos los respaldos' ); }
		} ).fail( function () {
			alert( 'Error de conexión.' );
			$btn.prop( 'disabled', false ).text( 'Eliminar todos los respaldos' );
		} );
	} );

	// =========================================================================
	// VACIAR REGISTRO
	// =========================================================================

	$( document ).on( 'click', '#ult-clear-log', function () {
		if ( ! confirm( '¿Vaciar todo el registro? Esta acción no se puede deshacer.' ) ) { return; }

		var $btn = $( this );
		$btn.prop( 'disabled', true );

		$.post( cfg.ajaxUrl, {
			action: 'ultimizer_clear_log',
			nonce:  cfg.nonce
		}, function ( r ) {
			if ( r.success ) { location.reload(); }
			else { alert( 'Error.' ); $btn.prop( 'disabled', false ); }
		} );
	} );

	$( document ).on( 'click', '#ult-clear-log', function () {
		if ( ! confirm( '¿Vaciar todo el registro? Esta acción no se puede deshacer.' ) ) { return; }

		var $btn = $( this );
		$btn.prop( 'disabled', true );

		$.post( cfg.ajaxUrl, {
			action: 'ultimizer_clear_log',
			nonce:  cfg.nonce
		}, function ( r ) {
			if ( r.success ) { location.reload(); }
			else { alert( 'Error.' ); $btn.prop( 'disabled', false ); }
		} );
	} );

	// =========================================================================
	// HELPERS
	// =========================================================================

	function fmtMime( mime ) {
		var map = { 'image/jpeg': 'JPEG', 'image/png': 'PNG', 'image/gif': 'GIF', 'image/webp': 'WebP' };
		return map[ mime ] || mime;
	}

	function fmtBytes( b ) {
		if ( b >= 1048576 ) { return ( b / 1048576 ).toFixed( 1 ) + ' MB'; }
		if ( b >= 1024 )    { return Math.round( b / 1024 ) + ' KB'; }
		return b + ' B';
	}

	function esc( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;'  )
			.replace( />/g, '&gt;'  )
			.replace( /"/g, '&quot;' );
	}

}( jQuery ) );
