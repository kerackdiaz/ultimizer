<?php
/**
 * Ultimizer_Frontend – Entrega de imágenes modernas en el frontend.
 *
 * Convierte <img> en <picture> con <source type="image/avif"> y <source type="image/webp">
 * cuando existen los sidecars correspondientes.
 *
 * Funciona en cualquier servidor (Apache, Nginx, LiteSpeed, IIS…) porque la
 * negociación del formato la realiza el navegador, no el servidor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Frontend {

	/** Caché en memoria: ruta_base => [ 'avif' => bool, 'webp' => bool ] */
	private static $cache = [];

	/** URL base del directorio de uploads. */
	private $upload_url;

	/** Ruta absoluta del directorio de uploads. */
	private $upload_dir;

	public function __construct() {
		$uploads          = wp_upload_dir();
		$this->upload_url = untrailingslashit( $uploads['baseurl'] );
		$this->upload_dir = untrailingslashit( $uploads['basedir'] );

		// Contenido de entradas y páginas.
		add_filter( 'the_content',             [ $this, 'process_html' ], 20 );
		// Imágenes destacadas (thumbnails).
		add_filter( 'post_thumbnail_html',     [ $this, 'process_html' ], 20 );
		// Widgets de texto.
		add_filter( 'widget_text_content',     [ $this, 'process_html' ], 20 );
		// Imágenes de adjuntos generadas con wp_get_attachment_image().
		add_filter( 'wp_get_attachment_image', [ $this, 'process_html' ], 20 );
		// Bloques de galería y similares.
		add_filter( 'render_block',            [ $this, 'process_html' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Punto de entrada
	// -------------------------------------------------------------------------

	/**
	 * Reemplaza las etiquetas <img> que tienen sidecars AVIF/WebP por <picture>.
	 *
	 * @param  string $html
	 * @return string
	 */
	public function process_html( $html ) {
		if ( empty( $html ) || strpos( $html, '<img' ) === false ) {
			return $html;
		}

		return preg_replace_callback(
			'/<img(\s[^>]*)>/i',
			[ $this, 'process_img_tag' ],
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Procesamiento de cada <img>
	// -------------------------------------------------------------------------

	private function process_img_tag( $matches ) {
		$attrs = $matches[1];
		$img   = $matches[0];

		// Extraer src (ignorar query string para buscar el archivo).
		if ( ! preg_match( '/[\s]src=["\']([^"\']+)["\']/', $attrs, $src_m ) ) {
			return $img;
		}
		$src       = $src_m[1];
		$src_clean = strtok( $src, '?' );

		// Solo procesar imágenes del directorio de uploads.
		if ( strpos( $src_clean, $this->upload_url ) === false ) {
			return $img;
		}

		// Verificar sidecars para el src principal.
		$file_path = $this->url_to_path( $src_clean );
		$sidecars  = $this->check_sidecars( $file_path );

		// Construir sources para srcset (imágenes responsivas) si existe.
		$sources = '';
		if ( preg_match( '/[\s]srcset=["\']([^"\']+)["\']/', $attrs, $ss_m ) ) {
			$sizes_attr = '';
			if ( preg_match( '/[\s]sizes=["\']([^"\']+)["\']/', $attrs, $sz_m ) ) {
				$sizes_attr = ' sizes="' . esc_attr( $sz_m[1] ) . '"';
			}

			// Para srcset convertimos cada URL individualmente.
			if ( $sidecars['avif'] ) {
				$avif_srcset = $this->convert_srcset( $ss_m[1], 'avif' );
				if ( $avif_srcset ) {
					$sources .= '<source type="image/avif" srcset="' . esc_attr( $avif_srcset ) . '"' . $sizes_attr . '>';
				}
			}
			if ( $sidecars['webp'] ) {
				$webp_srcset = $this->convert_srcset( $ss_m[1], 'webp' );
				if ( $webp_srcset ) {
					$sources .= '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"' . $sizes_attr . '>';
				}
			}
		} else {
			// Sin srcset: source simple.
			$base_src = $this->strip_ext_url( $src_clean );
			if ( $sidecars['avif'] ) {
				$sources .= '<source type="image/avif" srcset="' . esc_url( $base_src . '.avif' ) . '">';
			}
			if ( $sidecars['webp'] ) {
				$sources .= '<source type="image/webp" srcset="' . esc_url( $base_src . '.webp' ) . '">';
			}
		}

		// Si no hay ningún sidecar disponible, devolver la imagen original.
		if ( empty( $sources ) ) {
			return $img;
		}

		// Evitar doble envoltorio si ya está dentro de <picture>.
		// (preg_replace_callback no puede ver el contexto exterior, así que usamos
		// un atributo marcador en el <img> para detectarlo.)
		if ( strpos( $attrs, 'data-ult-picture' ) !== false ) {
			return $img;
		}

		// Añadir atributo marcador al <img> para evitar re-proceso.
		$img_marked = str_replace( '<img', '<img data-ult-picture="1"', $img );

		return '<picture>' . $sources . $img_marked . '</picture>';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convierte las URLs de un atributo srcset al formato moderno indicado.
	 * Devuelve el srcset convertido, o vacío si ninguna URL tiene sidecar.
	 *
	 * @param  string $srcset  Valor del atributo srcset.
	 * @param  string $ext     'avif' o 'webp'.
	 * @return string
	 */
	private function convert_srcset( $srcset, $ext ) {
		$entries   = array_map( 'trim', explode( ',', $srcset ) );
		$converted = [];
		$any_found = false;

		foreach ( $entries as $entry ) {
			$parts = preg_split( '/\s+/', trim( $entry ), 2 );
			$url   = $parts[0];
			$desc  = isset( $parts[1] ) ? ' ' . $parts[1] : '';

			$url_clean = strtok( $url, '?' );

			if ( strpos( $url_clean, $this->upload_url ) !== false ) {
				$file_path = $this->url_to_path( $url_clean );
				$s         = $this->check_sidecars( $file_path );
				if ( $s[ $ext ] ) {
					$url       = $this->strip_ext_url( $url_clean ) . '.' . $ext;
					$any_found = true;
				}
			}

			$converted[] = $url . $desc;
		}

		return $any_found ? implode( ', ', $converted ) : '';
	}

	/**
	 * Convierte una URL de upload a ruta absoluta en disco.
	 *
	 * @param  string $url
	 * @return string
	 */
	private function url_to_path( $url ) {
		$path = str_replace( $this->upload_url, $this->upload_dir, $url );
		// Normalizar separadores en Windows.
		return str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	/**
	 * Quita la extensión de una URL (Livit-1.jpg → Livit-1).
	 *
	 * @param  string $url
	 * @return string
	 */
	private function strip_ext_url( $url ) {
		return preg_replace( '/\.[^.\/?]+(\?.*)?$/', '', $url );
	}

	/**
	 * Verifica si existen los sidecars AVIF y WebP para una ruta dada.
	 * Usa caché en memoria para no repetir file_exists() en la misma petición.
	 *
	 * @param  string $file_path  Ruta absoluta al archivo original.
	 * @return array{avif: bool, webp: bool}
	 */
	private function check_sidecars( $file_path ) {
		$base = preg_replace( '/\.[^.\/\\\\]+$/', '', $file_path );

		if ( ! isset( self::$cache[ $base ] ) ) {
			self::$cache[ $base ] = [
				'avif' => file_exists( $base . '.avif' ),
				'webp' => file_exists( $base . '.webp' ),
			];
		}

		return self::$cache[ $base ];
	}
}
