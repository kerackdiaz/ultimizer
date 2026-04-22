<?php
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

		// Post and page content.
		add_filter( 'the_content',             [ $this, 'process_html' ], 20 );
		// Featured images (thumbnails).
		add_filter( 'post_thumbnail_html',     [ $this, 'process_html' ], 20 );
		// Text widgets.
		add_filter( 'widget_text_content',     [ $this, 'process_html' ], 20 );
		// Attachment images via wp_get_attachment_image().
		add_filter( 'wp_get_attachment_image', [ $this, 'process_html' ], 20 );
		// Gallery blocks and similar.
		add_filter( 'render_block',            [ $this, 'process_html' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Entry point
	// -------------------------------------------------------------------------

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
	// Process each <img>
	// -------------------------------------------------------------------------

	private function process_img_tag( $matches ) {
		$attrs = $matches[1];
		$img   = $matches[0];

		// Extract src (ignore query string for file lookup).
		if ( ! preg_match( '/[\s]src=["\']([^"\']+)["\']/', $attrs, $src_m ) ) {
			return $img;
		}
		$src       = $src_m[1];
		$src_clean = strtok( $src, '?' );

		// Only process images from the uploads directory.
		if ( strpos( $src_clean, $this->upload_url ) === false ) {
			return $img;
		}

		// Check sidecars for the main src.
		$file_path = $this->url_to_path( $src_clean );
		$sidecars  = $this->check_sidecars( $file_path );

		// Build sources for srcset (responsive images) if present.
		$sources = '';
		if ( preg_match( '/[\s]srcset=["\']([^"\']+)["\']/', $attrs, $ss_m ) ) {
			$sizes_attr = '';
			if ( preg_match( '/[\s]sizes=["\']([^"\']+)["\']/', $attrs, $sz_m ) ) {
				$sizes_attr = ' sizes="' . esc_attr( $sz_m[1] ) . '"';
			}

			// For srcset, convert each URL individually.
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
			// No srcset: simple source.
			$base_src = $this->strip_ext_url( $src_clean );
			if ( $sidecars['avif'] ) {
				$sources .= '<source type="image/avif" srcset="' . esc_url( $base_src . '.avif' ) . '">';
			}
			if ( $sidecars['webp'] ) {
				$sources .= '<source type="image/webp" srcset="' . esc_url( $base_src . '.webp' ) . '">';
			}
		}

		// No sidecars available, return the original image.
		if ( empty( $sources ) ) {
			return $img;
		}

		// Avoid double-wrapping if already inside <picture>.
		// (preg_replace_callback cannot see the outer context, so we use
		// a marker attribute on the <img> to detect it.)
		if ( strpos( $attrs, 'data-ult-picture' ) !== false ) {
			return $img;
		}

		// Add marker attribute to <img> to prevent reprocessing.
		$img_marked = str_replace( '<img', '<img data-ult-picture="1"', $img );

		return '<picture>' . $sources . $img_marked . '</picture>';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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

	private function url_to_path( $url ) {
		$path = str_replace( $this->upload_url, $this->upload_dir, $url );
		// Normalize directory separators on Windows.
		return str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	private function strip_ext_url( $url ) {
		return preg_replace( '/\.[^.\/?]+(\?.*)?$/', '', $url );
	}

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
