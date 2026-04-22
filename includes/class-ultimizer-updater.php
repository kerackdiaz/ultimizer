<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Updater {

	private $file;
	private $basename;
	private $github_user = 'kerackdiaz';
	private $github_repo = 'ultimizer';
	private $release_info = null;

	const CACHE_KEY = 'ultimizer_github_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function __construct( $plugin_file ) {
		$this->file     = $plugin_file;
		$this->basename = plugin_basename( $plugin_file ); // set here — no timing issues.

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
		add_action( 'upgrader_process_complete',             [ $this, 'purge_cache' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// GitHub release info
	// -------------------------------------------------------------------------

	private function fetch_release_info() {
		if ( $this->release_info ) {
			return $this->release_info;
		}

		if ( empty( $this->github_user ) ) {
			return null;
		}

		$cached = get_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			$this->release_info = $cached;
			return $cached;
		}

		$url      = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'Ultimizer-Plugin/' . ULTIMIZER_VERSION,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$info = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $info ) || ! isset( $info->tag_name ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
		$this->release_info = $info;

		return $info;
	}

	// -------------------------------------------------------------------------
	// Hooks de WordPress Update API
	// -------------------------------------------------------------------------

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_release_info();
		if ( ! $release ) {
			return $transient;
		}

		$current = isset( $transient->checked[ $this->basename ] )
			? $transient->checked[ $this->basename ]
			: '0';

		$latest = ltrim( $release->tag_name, 'v' );

		if ( version_compare( $latest, $current, '>' ) ) {
			$package = $this->get_download_url( $release );

			$transient->response[ $this->basename ] = (object) [
				'slug'        => dirname( $this->basename ),
				'plugin'      => $this->basename,
				'new_version' => $latest,
				'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
				'package'     => $package,
			];
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== dirname( $this->basename ) ) {
			return $result;
		}

		$release     = $this->fetch_release_info();
		$plugin_data = get_plugin_data( $this->file );

		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => $plugin_data['Name'],
			'slug'          => dirname( $this->basename ),
			'version'       => ltrim( $release->tag_name, 'v' ),
			'author'        => $plugin_data['Author'],
			'download_link' => $this->get_download_url( $release ),
			'trunk'         => $this->get_download_url( $release ),
			'requires'      => '5.8',
			'tested'        => '6.7',
			'last_updated'  => isset( $release->published_at ) ? $release->published_at : '',
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'sections'      => [
				'description' => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : $plugin_data['Description'],
				'changelog'   => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : '',
			],
		];
	}

	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if (
			empty( $hook_extra['plugin'] ) ||
			$hook_extra['plugin'] !== $this->basename
		) {
			return $result;
		}

		// Check active state at install time, not at setup time.
		$was_active = is_plugin_active( $this->basename );

		$install_dir = plugin_dir_path( $this->file );
		$wp_filesystem->move( $result['destination'], $install_dir );
		$result['destination'] = $install_dir;

		if ( $was_active ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}

	public function purge_cache( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( self::CACHE_KEY );
			$this->release_info = null;
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_download_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->browser_download_url ) && '.zip' === substr( $asset->name, -4 ) ) {
					return $asset->browser_download_url;
				}
			}
		}
		return isset( $release->zipball_url ) ? $release->zipball_url : '';
	}
}
