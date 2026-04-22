<?php
/**
 * Plugin Name: Ultimizer
 * Plugin URI:  https://github.com/YOUR_GITHUB_USER/ultimizer
 * Description: Optimización avanzada de imágenes: AVIF/WebP, eliminación de metadatos, respaldos automáticos, procesamiento masivo y actualizaciones desde GitHub.
 * Version:     1.0.0
 * Author:      Ricardo Diaz
 * License:     GPL-2.0+
 * Text Domain: ultimizer
 *
 * GitHub Plugin URI: YOUR_GITHUB_USER/ultimizer
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ULTIMIZER_VERSION',     '1.0.0' );
define( 'ULTIMIZER_PLUGIN_FILE', __FILE__ );
define( 'ULTIMIZER_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ULTIMIZER_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-logger.php';
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-backup.php';
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-optimizer.php';
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-frontend.php';
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-updater.php';
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-admin.php';

/**
 * Plugin principal – singleton.
 */
final class Ultimizer {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( ULTIMIZER_PLUGIN_FILE,   [ $this, 'activate' ] );
		register_deactivation_hook( ULTIMIZER_PLUGIN_FILE, [ $this, 'deactivate' ] );
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	/** Activación: crear tabla de log, carpeta de respaldos y reglas .htaccess. */
	public function activate() {
		Ultimizer_Logger::create_table();
		Ultimizer_Backup::create_backup_dir();

		if ( ! get_option( 'ultimizer_settings' ) ) {
			update_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );
		}

		( new Ultimizer_Optimizer() )->inject_htaccess_rules();
	}

	/** Desactivación: eliminar reglas .htaccess y limpiar caché de actualización. */
	public function deactivate() {
		( new Ultimizer_Optimizer() )->remove_htaccess_rules();
		delete_transient( 'ultimizer_github_info' );
	}

	public function init() {
		$settings = get_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );

		// Optimizar automáticamente al subir una imagen.
		if ( ! empty( $settings['optimize_on_upload'] ) ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_upload' ], 99, 2 );
		}

		if ( is_admin() ) {
			new Ultimizer_Admin();
			new Ultimizer_Updater( ULTIMIZER_PLUGIN_FILE );
		} else {
			// Frontend: entregar AVIF/WebP via <picture> (funciona en cualquier servidor).
			new Ultimizer_Frontend();
		}
	}

	/**
	 * Hook en la subida de archivos adjuntos.
	 *
	 * @param array $metadata       Metadatos del adjunto.
	 * @param int   $attachment_id  ID del adjunto.
	 * @return array
	 */
	public function on_upload( $metadata, $attachment_id ) {
		( new Ultimizer_Optimizer() )->optimize_attachment( (int) $attachment_id );
		return $metadata;
	}
}

Ultimizer::get_instance();
