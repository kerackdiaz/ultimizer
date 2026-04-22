<?php
/**
 * Plugin Name: Ultimizer
 * Plugin URI:  https://github.com/kerackdiaz/ultimizer
 * Description: Optimización de imágenes para WordPress: compresión JPEG/PNG/GIF, conversión a AVIF y WebP, respaldos y optimización masiva.
 * Version:     1.0.0
 * Author:      Kerack Diaz
 * Author URI:  https://github.com/kerackdiaz
 * License:     GPL-2.0+
 * Text Domain: ultimizer
 * @package Ultimizer
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
require_once ULTIMIZER_PLUGIN_DIR . 'includes/class-ultimizer-admin.php';

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

	public function activate() {
		Ultimizer_Logger::create_table();
		Ultimizer_Backup::create_backup_dir();

		if ( ! get_option( 'ultimizer_settings' ) ) {
			update_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );
		}

		( new Ultimizer_Optimizer() )->inject_htaccess_rules();
	}

	public function deactivate() {
		( new Ultimizer_Optimizer() )->remove_htaccess_rules();
	}

	public function init() {
		$settings = get_option( 'ultimizer_settings', Ultimizer_Optimizer::get_defaults() );

		if ( ! empty( $settings['optimize_on_upload'] ) ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_upload' ], 99, 2 );
		}

		if ( is_admin() ) {
			new Ultimizer_Admin();
		} else {
			new Ultimizer_Frontend();
		}
	}

	public function on_upload( $metadata, $attachment_id ) {
		( new Ultimizer_Optimizer() )->optimize_attachment( (int) $attachment_id );
		return $metadata;
	}
}

Ultimizer::get_instance();
