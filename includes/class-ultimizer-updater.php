<?php
/**
 * Actualizador automático desde GitHub Releases.
 *
 * Configuración:
 *   - Ir a Ultimizer → Configuración y rellenar el campo "Usuario de GitHub".
 *   - El repositorio debe llamarse "ultimizer" y publicar releases con tags versionados (p.e. v1.0.1).
 *   - Los releases deben incluir el .zip del plugin como asset o usar el zipball_url de GitHub.
 *
 * Basado en el patrón de plugin-update-checker adaptado a dependencias cero.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Updater {

	/** @var string Ruta completa al archivo principal del plugin. */
	private $file;

	/** @var string Plugin basename: "ultimizer/ultimizer.php" */
	private $basename;

	/** @var bool Si el plugin está activo actualmente. */
	private $active;

	/** @var string Usuario de GitHub. */
	private $github_user;

	/** @var string Nombre del repositorio GitHub. */
	private $github_repo = 'ultimizer';

	/** @var string|null Caché de la respuesta de la API de GitHub. */
	private $release_info = null;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function __construct( $plugin_file ) {
		$this->file = $plugin_file;

		add_action( 'admin_init', [ $this, 'setup' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
	}

	public function setup() {
		$this->basename = plugin_basename( $this->file );
		$this->active   = is_plugin_active( $this->basename );

		$settings          = get_option( 'ultimizer_settings', [] );
		$this->github_user = isset( $settings['github_user'] ) ? sanitize_text_field( $settings['github_user'] ) : '';
	}

	// -------------------------------------------------------------------------
	// Obtener información del último release en GitHub
	// -------------------------------------------------------------------------

	private function fetch_release_info() {
		if ( $this->release_info ) {
			return $this->release_info;
		}

		if ( empty( $this->github_user ) ) {
			return null;
		}

		$cache_key = 'ultimizer_github_info';
		$cached    = get_transient( $cache_key );

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

		// Cachear 12 horas.
		set_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
		$this->release_info = $info;

		return $info;
	}

	// -------------------------------------------------------------------------
	// Hooks de WordPress Update API
	// -------------------------------------------------------------------------

	/**
	 * Inyecta información de actualización en el transient de WordPress.
	 *
	 * @param  object $transient
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) || empty( $this->basename ) ) {
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

	/**
	 * Proporciona detalles del plugin para el diálogo de "Ver detalles del plugin".
	 *
	 * @param  false|object|array $result
	 * @param  string             $action
	 * @param  object             $args
	 * @return false|object
	 */
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

	/**
	 * Mueve el directorio extraído al directorio correcto del plugin tras instalar.
	 *
	 * @param  bool  $response
	 * @param  array $hook_extra
	 * @param  array $result
	 * @return array
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if (
			empty( $hook_extra['plugin'] ) ||
			$hook_extra['plugin'] !== $this->basename
		) {
			return $result;
		}

		$install_dir = plugin_dir_path( $this->file );
		$wp_filesystem->move( $result['destination'], $install_dir );
		$result['destination'] = $install_dir;

		if ( $this->active ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Determina la URL de descarga del release.
	 * Prefiere el primer asset .zip; si no hay, usa el zipball de GitHub.
	 *
	 * @param  object $release
	 * @return string
	 */
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
