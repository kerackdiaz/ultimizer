<?php
/**
 * Clase de administración: menú, AJAX, guardado de ajustes.
 *
 * Pestañas del panel:
 *   panel        – Estadísticas generales y capacidades del servidor.
 *   settings     – Configuración del plugin.
 *   bulk         – Optimizador masivo con barra de progreso.
 *   log          – Registro de optimizaciones.
 *   backups      – Gestión de respaldos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Admin {

	const MENU_SLUG   = 'ultimizer';
	const NONCE_KEY   = 'ultimizer_nonce';
	const CAPABILITY  = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_icon_style' ] );
		add_action( 'admin_post_ultimizer_save_settings', [ $this, 'save_settings' ] );

		// Endpoints AJAX.
		add_action( 'wp_ajax_ultimizer_get_stats',          [ $this, 'ajax_get_stats' ] );
		add_action( 'wp_ajax_ultimizer_scan_batch',         [ $this, 'ajax_scan_batch' ] );
		add_action( 'wp_ajax_ultimizer_bulk_process_batch', [ $this, 'ajax_bulk_process_batch' ] );
		add_action( 'wp_ajax_ultimizer_restore_backup',     [ $this, 'ajax_restore_backup' ] );
		add_action( 'wp_ajax_ultimizer_delete_backup',      [ $this, 'ajax_delete_backup' ] );
		add_action( 'wp_ajax_ultimizer_delete_all_backups', [ $this, 'ajax_delete_all_backups' ] );
		add_action( 'wp_ajax_ultimizer_clear_log',          [ $this, 'ajax_clear_log' ] );
		add_action( 'wp_ajax_ultimizer_toggle_exclude',     [ $this, 'ajax_toggle_exclude' ] );
	}

	// -------------------------------------------------------------------------
	// Menú y assets
	// -------------------------------------------------------------------------

	public function register_menu() {
		add_menu_page(
			'Ultimizer',
			'Ultimizer',
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			ULTIMIZER_PLUGIN_URL . 'assets/ultimizer.png',
			81
		);
	}

	public function enqueue_menu_icon_style() {
		$css = '#adminmenu #toplevel_page_ultimizer .wp-menu-image img {'
			. 'width:28px!important;height:28px!important;padding:0!important;margin-top:6px!important;}';
		wp_add_inline_style( 'wp-admin', $css );
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ultimizer-admin',
			ULTIMIZER_PLUGIN_URL . 'assets/css/admin-new.css',
			[],
			ULTIMIZER_VERSION
		);

		wp_enqueue_script(
			'ultimizer-bulk',
			ULTIMIZER_PLUGIN_URL . 'assets/js/bulk-optimizer-new.js',
			[ 'jquery' ],
			ULTIMIZER_VERSION,
			true
		);

		wp_localize_script( 'ultimizer-bulk', 'ultimizerData', [
			'nonce'         => wp_create_nonce( self::NONCE_KEY ),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'batchSize'     => 5,
			'strings'       => [
				'running'    => __( 'Optimizando...', 'ultimizer' ),
				'paused'     => __( 'En pausa', 'ultimizer' ),
				'done'       => __( 'Optimización completada', 'ultimizer' ),
				'error'      => __( 'Error en el lote', 'ultimizer' ),
				'start'      => __( 'Iniciar optimización', 'ultimizer' ),
				'pause'      => __( 'Pausar', 'ultimizer' ),
				'resume'     => __( 'Reanudar', 'ultimizer' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Renderizado del panel
	// -------------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ultimizer' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'panel'; // phpcs:ignore WordPress.Security.NonceVerification
		require_once ULTIMIZER_PLUGIN_DIR . 'admin/views/settings-page-new.php';
	}

	// -------------------------------------------------------------------------
	// Guardar configuración
	// -------------------------------------------------------------------------

	public function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'ultimizer' ) );
		}

		check_admin_referer( 'ultimizer_save_settings' );

		$post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification

		$settings = [
			'jpeg_quality'    => min( 100, max( 1, (int) ( $post['jpeg_quality']    ?? 82 ) ) ),
			'png_compression' => min( 9,   max( 0, (int) ( $post['png_compression'] ?? 6  ) ) ),
			'webp_quality'    => min( 100, max( 1, (int) ( $post['webp_quality']    ?? 80 ) ) ),
			'avif_quality'    => min( 100, max( 1, (int) ( $post['avif_quality']    ?? 65 ) ) ),
		];

		update_option( 'ultimizer_settings', $settings );

		wp_safe_redirect( add_query_arg( [
			'page'    => self::MENU_SLUG,
			'tab'     => 'settings',
			'updated' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: estadísticas
	// -------------------------------------------------------------------------

	public function ajax_get_stats() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		$optimizer   = new Ultimizer_Optimizer();
		$logger      = new Ultimizer_Logger();
		$stats       = $logger->get_global_stats();

		wp_send_json_success( [
			'optimized'        => $optimizer->count_optimized(),
			'unoptimized'      => $optimizer->count_unoptimized(),
			'total_savings_hr' => size_format( $stats['total_savings_bytes'] ),
			'avg_savings_pct'  => $stats['avg_savings_percent'],
			'avif_count'       => $stats['avif_count'],
			'webp_count'       => $stats['webp_count'],
			'supports_avif'    => $optimizer->supports_avif(),
			'supports_webp'    => $optimizer->supports_webp(),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: escaneo de biblioteca
	// -------------------------------------------------------------------------

	public function ajax_scan_batch() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		$limit  = min( 20, max( 1, (int) ( $_POST['limit']  ?? 20 ) ) );
		$offset = max( 0,           (int) ( $_POST['offset'] ?? 0  ) );

		$optimizer = new Ultimizer_Optimizer();
		$ids       = $optimizer->get_all_ids( $limit, $offset );
		$total     = $optimizer->count_all();

		$items = [];
		foreach ( $ids as $id ) {
			$data = $optimizer->scan_attachment( (int) $id );
			if ( null !== $data ) {
				$items[] = $data;
			}
		}

		wp_send_json_success( [
			'items'  => $items,
			'total'  => $total,
			'offset' => $offset + count( $ids ),
			'done'   => ( $offset + count( $ids ) ) >= $total,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: lote de optimización masiva
	// -------------------------------------------------------------------------

	public function ajax_bulk_process_batch() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		$batch_size = max( 1, min( 20, (int) ( $_POST['batch_size'] ?? 5 ) ) );
		$offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$optimizer = new Ultimizer_Optimizer();
		$ids       = $optimizer->get_unoptimized_ids( $batch_size, 0 ); // offset 0: siempre obtiene los primeros no optimizados.

		$results        = [];
		$total_saved    = 0;
		$processed      = 0;
		$errors         = 0;

		foreach ( $ids as $attachment_id ) {
			$result = $optimizer->optimize_attachment( (int) $attachment_id );

			if ( is_wp_error( $result ) ) {
				$errors++;
				$results[] = [
					'id'    => $attachment_id,
					'error' => $result->get_error_message(),
				];
			} elseif ( ! empty( $result['skipped'] ) ) {
				$results[] = [ 'id' => $attachment_id, 'skipped' => true ];
			} else {
				$processed++;
				$total_saved += $result['savings_bytes'];
				$results[]    = [
					'id'              => $attachment_id,
					'savings_bytes'   => $result['savings_bytes'],
					'savings_percent' => $result['savings_percent'],
					'avif'            => $result['avif_generated'],
					'webp'            => $result['webp_generated'],
				];
			}
		}

		$remaining = $optimizer->count_unoptimized();

		wp_send_json_success( [
			'processed'    => $processed,
			'errors'       => $errors,
			'total_saved'  => size_format( $total_saved ),
			'remaining'    => $remaining,
			'done'         => ( $remaining === 0 ),
			'results'      => $results,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: restaurar respaldo
	// -------------------------------------------------------------------------

	public function ajax_restore_backup() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'ID de adjunto inválido.' );
		}

		$backup = new Ultimizer_Backup();
		$result = $backup->restore( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [ 'message' => 'Imagen restaurada correctamente.' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: eliminar respaldo individual
	// -------------------------------------------------------------------------

	public function ajax_delete_backup() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_send_json_error( 'Permiso denegado.' ); }

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) { wp_send_json_error( 'ID inválido.' ); }

		$backup = new Ultimizer_Backup();
		$backup->delete_backup( $attachment_id );

		$remaining_size = $backup->get_total_backup_size();
		wp_send_json_success( [
			'remaining_count' => $backup->count_backups(),
			'remaining_size'  => size_format( $remaining_size ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: eliminar todos los respaldos
	// -------------------------------------------------------------------------

	public function ajax_delete_all_backups() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_send_json_error( 'Permiso denegado.' ); }

		$backup = new Ultimizer_Backup();
		$count  = $backup->delete_all_backups();

		wp_send_json_success( [ 'deleted' => $count ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: limpiar log
	// -------------------------------------------------------------------------

	public function ajax_clear_log() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		( new Ultimizer_Logger() )->clear();

		wp_send_json_success( [ 'message' => 'Registro eliminado.' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: activar/desactivar exclusión de una imagen
	// -------------------------------------------------------------------------

	public function ajax_toggle_exclude() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'Permiso denegado.' );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			wp_send_json_error( 'ID de adjunto inválido.' );
		}

		$exclude = ! empty( $_POST['exclude'] ) && '1' === (string) $_POST['exclude'];

		if ( $exclude ) {
			update_post_meta( $attachment_id, '_ultimizer_excluded', '1' );
		} else {
			delete_post_meta( $attachment_id, '_ultimizer_excluded' );
		}

		$optimizer = new Ultimizer_Optimizer();

		wp_send_json_success( [
			'excluded'   => $exclude,
			'pending'    => $optimizer->count_unoptimized(),
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers de vista
	// -------------------------------------------------------------------------

	/**
	 * Devuelve el HTML de las pestañas de navegación.
	 *
	 * @param  string $current  Pestaña activa.
	 * @return string
	 */
	public static function render_tabs( $current ) {
		$tabs = [
			'panel'    => 'Panel',
			'settings' => 'Configuración',
			'log'      => 'Registro',
			'backups'  => 'Respaldos',
		];

		$output = '<nav class="ult-tabs"><ul>';
		foreach ( $tabs as $slug => $label ) {
			$active   = $slug === $current ? ' class="active"' : '';
			$url      = add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
			$output  .= '<li' . $active . '><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		$output .= '</ul></nav>';

		return $output;
	}
}
