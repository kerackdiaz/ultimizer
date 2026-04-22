<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Optimizer {

	/** @var array Configuración activa. */
	private $settings;

	/** Tipos MIME procesables. */
	const SUPPORTED_MIMES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	];

	public function __construct() {
		$stored         = get_option( 'ultimizer_settings', [] );
		$this->settings = array_merge( self::get_defaults(), $stored );

		// Fixed behaviour: always enabled.
		$this->settings['convert_to_avif']    = true;
		$this->settings['convert_to_webp']    = true;
		$this->settings['strip_metadata']     = true;
		$this->settings['skip_optimized']     = true;
		$this->settings['optimize_on_upload'] = true;
	}

	// -------------------------------------------------------------------------
	// Configuración
	// -------------------------------------------------------------------------

	public static function get_defaults() {
		return [
			'jpeg_quality'    => 82,
			'png_compression' => 6,
			'webp_quality'    => 80,
			'avif_quality'    => 65,
		];
	}

	// -------------------------------------------------------------------------
	// Punto de entrada principal
	// -------------------------------------------------------------------------

	public function optimize_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( get_post_meta( $attachment_id, '_ultimizer_excluded', true ) ) {
			return [ 'skipped' => true, 'reason' => 'excluded' ];
		}

		if (
			! empty( $this->settings['skip_optimized'] ) &&
			get_post_meta( $attachment_id, '_ultimizer_optimized', true )
		) {
			return [ 'skipped' => true, 'reason' => 'already_optimized' ];
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'Archivo no encontrado: ' . $file_path );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED_MIMES, true ) ) {
			return [ 'skipped' => true, 'reason' => 'unsupported_mime' ];
		}

		$backup = new Ultimizer_Backup();
		$backup->create( $attachment_id, $file_path );

		foreach ( [ '.avif', '.webp' ] as $ext ) {
			$old = $file_path . $ext;
			if ( file_exists( $old ) ) {
				@unlink( $old );
			}
		}

		$original_size = (int) filesize( $file_path );
		$result = $this->optimize_file( $file_path, $mime_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$upload_dir = dirname( $file_path );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_path = $upload_dir . DIRECTORY_SEPARATOR . basename( $size_data['file'] );
				if ( file_exists( $size_path ) ) {
					$this->optimize_file( $size_path, $mime_type );
					$this->maybe_generate_modern_formats( $size_path );
				}
			}
		}

		$avif_generated = false;
		$webp_generated = false;
		$this->maybe_generate_modern_formats( $file_path, $avif_generated, $webp_generated );

		self::inject_htaccess_rules();

		clearstatcache( true, $file_path );
		$optimized_size  = (int) filesize( $file_path );
		$savings_bytes   = max( 0, $original_size - $optimized_size );
		$savings_percent = $original_size > 0
			? round( ( $savings_bytes / $original_size ) * 100, 2 )
			: 0.0;

		update_post_meta( $attachment_id, '_ultimizer_optimized',      current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_ultimizer_original_size',  $original_size );
		update_post_meta( $attachment_id, '_ultimizer_optimized_size', $optimized_size );
		update_post_meta( $attachment_id, '_ultimizer_savings_bytes',  $savings_bytes );
		update_post_meta( $attachment_id, '_ultimizer_savings_pct',    $savings_percent );

		// Registrar en log.
		$logger = new Ultimizer_Logger();
		$logger->log( [
			'attachment_id'    => $attachment_id,
			'file_path'        => $file_path,
			'original_size'    => $original_size,
			'optimized_size'   => $optimized_size,
			'savings_bytes'    => $savings_bytes,
			'savings_percent'  => $savings_percent,
			'format_original'  => $mime_type,
			'avif_generated'   => $avif_generated ? 1 : 0,
			'webp_generated'   => $webp_generated ? 1 : 0,
		] );

		return [
			'success'         => true,
			'original_size'   => $original_size,
			'optimized_size'  => $optimized_size,
			'savings_bytes'   => $savings_bytes,
			'savings_percent' => $savings_percent,
			'avif_generated'  => $avif_generated,
			'webp_generated'  => $webp_generated,
		];
	}

	// -------------------------------------------------------------------------
	// Optimización de archivos individuales
	// -------------------------------------------------------------------------

	private function optimize_file( $file_path, $mime_type ) {
		if ( extension_loaded( 'imagick' ) ) {
			return $this->optimize_imagick( $file_path, $mime_type );
		}
		return $this->optimize_gd( $file_path, $mime_type );
	}

	private function optimize_imagick( $file_path, $mime_type ) {
		try {
			$imagick = new Imagick();
			$imagick->readImage( $file_path );

			// Handle GIF animations (multiple frames).
			$is_animated = ( $imagick->getNumberImages() > 1 );

			if ( ! empty( $this->settings['strip_metadata'] ) ) {
				$imagick->stripImage();
			}

			switch ( $mime_type ) {
				case 'image/jpeg':
					$imagick->setImageFormat( 'JPEG' );
					$imagick->setImageCompressionQuality( (int) $this->settings['jpeg_quality'] );
					$imagick->setInterlaceScheme( Imagick::INTERLACE_PLANE ); // Progressive JPEG.
					$imagick->setSamplingFactors( [ '2x2', '1x1', '1x1' ] ); // 4:2:0 chroma.
					break;

				case 'image/png':
					$imagick->setImageFormat( 'PNG' );
					$imagick->setOption( 'png:compression-level', (string) (int) $this->settings['png_compression'] );
					$imagick->setOption( 'png:compression-filter', '5' );
					$imagick->setOption( 'png:compression-strategy', '1' );
					break;

				case 'image/gif':
					if ( $is_animated ) {
						$imagick = $imagick->coalesceImages();
						$imagick->optimizeImageLayers();
					}
					break;

				case 'image/webp':
					$imagick->setImageFormat( 'WEBP' );
					$imagick->setImageCompressionQuality( (int) $this->settings['webp_quality'] );
					break;
			}

			$tmp_path = $file_path . '.ult_tmp';
			$imagick->writeImage( $tmp_path );
			$imagick->destroy();

			// Only replace if result is smaller.
			if ( file_exists( $tmp_path ) ) {
				if ( filesize( $tmp_path ) < filesize( $file_path ) ) {
					rename( $tmp_path, $file_path );
				} else {
					@unlink( $tmp_path );
				}
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_error', $e->getMessage() );
		}
	}

	private function optimize_gd( $file_path, $mime_type ) {
		$image = null;

		switch ( $mime_type ) {
			case 'image/jpeg':
				$image = @imagecreatefromjpeg( $file_path );
				if ( $image ) {
					ob_start();
					imageinterlace( $image, 1 );
					imagejpeg( $image, null, (int) $this->settings['jpeg_quality'] );
					$data = ob_get_clean();
					if ( strlen( $data ) < filesize( $file_path ) ) {
						file_put_contents( $file_path, $data );
					}
				}
				break;

			case 'image/png':
				$image = @imagecreatefrompng( $file_path );
				if ( $image ) {
					ob_start();
					imagepng( $image, null, (int) $this->settings['png_compression'] );
					$data = ob_get_clean();
					if ( strlen( $data ) < filesize( $file_path ) ) {
						file_put_contents( $file_path, $data );
					}
				}
				break;

			case 'image/gif':
				$image = @imagecreatefromgif( $file_path );
				if ( $image ) {
					ob_start();
					imagegif( $image, null );
					$data = ob_get_clean();
					if ( strlen( $data ) < filesize( $file_path ) ) {
						file_put_contents( $file_path, $data );
					}
				}
				break;

			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = @imagecreatefromwebp( $file_path );
					if ( $image ) {
						ob_start();
						imagewebp( $image, null, (int) $this->settings['webp_quality'] );
						$data = ob_get_clean();
						if ( strlen( $data ) < filesize( $file_path ) ) {
							file_put_contents( $file_path, $data );
						}
					}
				}
				break;
		}

		if ( isset( $image ) && $image ) {
			imagedestroy( $image );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Modern format generation (AVIF / WebP)
	// -------------------------------------------------------------------------

	private function maybe_generate_modern_formats( $source_path, &$avif_out = false, &$webp_out = false ) {
		if ( ! empty( $this->settings['convert_to_avif'] ) && $this->supports_avif() ) {
			$avif = $this->generate_avif( $source_path );
			if ( $avif ) {
				$avif_out = true;
			}
		}
		if ( ! empty( $this->settings['convert_to_webp'] ) && $this->supports_webp() ) {
			$webp = $this->generate_webp( $source_path );
			if ( $webp ) {
				$webp_out = true;
			}
		}
	}

	private function generate_avif( $source_path ) {
		// Strip original extension and add .avif (image.avif, not image.jpg.avif).
		$dest = preg_replace( '/\.[^.\/\\\\]+$/', '', $source_path ) . '.avif';
		try {
			$imagick = new Imagick( $source_path );
			$imagick->stripImage();
			$imagick->setImageFormat( 'AVIF' );
			$imagick->setImageCompressionQuality( (int) $this->settings['avif_quality'] );
			$imagick->writeImage( $dest );
			$imagick->destroy();
			return $dest;
		} catch ( Exception $e ) {
			return null;
		}
	}

	private function generate_webp( $source_path ) {
		// Strip original extension and add .webp (image.webp, not image.jpg.webp).
		$dest = preg_replace( '/\.[^.\/\\\\]+$/', '', $source_path ) . '.webp';

		// Try with Imagick.
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$imagick = new Imagick( $source_path );
				$imagick->stripImage();
				$imagick->setImageFormat( 'WEBP' );
				$imagick->setImageCompressionQuality( (int) $this->settings['webp_quality'] );
				$imagick->writeImage( $dest );
				$imagick->destroy();
				return $dest;
			} catch ( Exception $e ) {
				// Fall through to GD.
			}
		}

		// Try with GD.
		if ( function_exists( 'imagewebp' ) ) {
			$mime  = mime_content_type( $source_path );
			$image = null;
			switch ( $mime ) {
				case 'image/jpeg': $image = @imagecreatefromjpeg( $source_path ); break;
				case 'image/png':  $image = @imagecreatefrompng( $source_path );  break;
			}
			if ( $image ) {
				imagewebp( $image, $dest, (int) $this->settings['webp_quality'] );
				imagedestroy( $image );
				return $dest;
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Server capability detection
	// -------------------------------------------------------------------------

	public function supports_avif() {
		if ( ! extension_loaded( 'imagick' ) ) {
			return false;
		}
		try {
			return in_array( 'AVIF', Imagick::queryFormats(), true );
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function supports_webp() {
		if ( extension_loaded( 'imagick' ) ) {
			try {
				return in_array( 'WEBP', Imagick::queryFormats(), true );
			} catch ( Exception $e ) {}
		}
		return function_exists( 'imagewebp' );
	}

	// -------------------------------------------------------------------------
	// .htaccess rules to serve AVIF/WebP transparently
	// -------------------------------------------------------------------------

	public static function inject_htaccess_rules() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$upload_dir = wp_upload_dir();
		$htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

		$rules = [
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine On',
			'  # Serve AVIF when the browser supports it and the sidecar exists.',
			'  RewriteCond %{HTTP_ACCEPT} image/avif',
			'  RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png|gif|webp)$',
			'  RewriteCond %1.avif -f',
			'  RewriteRule (.+)\.(jpe?g|png|gif|webp)$ $1.avif [T=image/avif,E=ult_modern:1,L]',
			'  # Serve WebP when the browser supports it and the sidecar exists.',
			'  RewriteCond %{HTTP_ACCEPT} image/webp',
			'  RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png|gif)$',
			'  RewriteCond %1.webp -f',
			'  RewriteRule (.+)\.(jpe?g|png|gif)$ $1.webp [T=image/webp,E=ult_modern:1,L]',
			'  <IfModule mod_headers.c>',
			'    Header append Vary Accept env=ult_modern',
			'  </IfModule>',
			'</IfModule>',
		];

		insert_with_markers( $htaccess, 'Ultimizer', $rules );
	}

	public static function remove_htaccess_rules() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$upload_dir = wp_upload_dir();
		$htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

		if ( file_exists( $htaccess ) ) {
			insert_with_markers( $htaccess, 'Ultimizer', [] );
		}
	}

	// -------------------------------------------------------------------------
	// Status queries
	// -------------------------------------------------------------------------

	public function get_unoptimized_ids( $limit = 10, $offset = 0 ) {
		global $wpdb;

		$mimes = implode( "','", array_map( 'esc_sql', self::SUPPORTED_MIMES ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				        ON p.ID = pm.post_id
				       AND pm.meta_key = '_ultimizer_optimized'
				 WHERE p.post_type      = 'attachment'
				   AND p.post_mime_type IN ('{$mimes}')
				   AND pm.meta_value    IS NULL
				   AND p.ID NOT IN (
				       SELECT post_id FROM {$wpdb->postmeta}
				       WHERE meta_key = '_ultimizer_excluded' AND meta_value = '1'
				   )
				 ORDER BY p.ID DESC
				 LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			)
		);
	}

	public function count_unoptimized() {
		global $wpdb;

		$mimes = implode( "','", array_map( 'esc_sql', self::SUPPORTED_MIMES ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			        ON p.ID = pm.post_id
			       AND pm.meta_key = '_ultimizer_optimized'
			 WHERE p.post_type      = 'attachment'
			   AND p.post_mime_type IN ('{$mimes}')
			   AND pm.meta_value    IS NULL
			   AND p.ID NOT IN (
			       SELECT post_id FROM {$wpdb->postmeta}
			       WHERE meta_key = '_ultimizer_excluded' AND meta_value = '1'
			   )"
		);
	}

	public function count_optimized() {
		global $wpdb;

		$mimes = implode( "','", array_map( 'esc_sql', self::SUPPORTED_MIMES ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			         ON p.ID = pm.post_id
			        AND pm.meta_key = '_ultimizer_optimized'
			 WHERE p.post_type      = 'attachment'
			   AND p.post_mime_type IN ('{$mimes}')"
		);
	}

	public function get_all_ids( $limit = 20, $offset = 0 ) {
		global $wpdb;

		$mimes = implode( "','", array_map( 'esc_sql', self::SUPPORTED_MIMES ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				 FROM {$wpdb->posts}
				 WHERE post_type      = 'attachment'
				   AND post_mime_type IN ('{$mimes}')
				 ORDER BY ID DESC
				 LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			)
		);
	}

	public function count_all() {
		global $wpdb;

		$mimes = implode( "','", array_map( 'esc_sql', self::SUPPORTED_MIMES ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(ID)
			 FROM {$wpdb->posts}
			 WHERE post_type      = 'attachment'
			   AND post_mime_type IN ('{$mimes}')"
		);
	}

	public function scan_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file_path     = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED_MIMES, true ) ) {
			return null;
		}

		$current_size  = (int) filesize( $file_path );
		$is_optimized  = (bool) get_post_meta( $attachment_id, '_ultimizer_optimized', true );
		$is_excluded   = (bool) get_post_meta( $attachment_id, '_ultimizer_excluded', true );
		$savings_pct   = (float) get_post_meta( $attachment_id, '_ultimizer_savings_pct', true );
		$savings_bytes = (int) get_post_meta( $attachment_id, '_ultimizer_savings_bytes', true );

		$est_pct       = $this->estimate_savings_pct( $mime_type );
		$est_savings_b = max( 0, (int) ( $current_size * ( $est_pct / 100 ) ) );

		$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

		return [
			'id'                    => $attachment_id,
			'filename'              => basename( $file_path ),
			'mime_type'             => $mime_type,
			'current_size'          => $current_size,
			'current_size_hr'       => size_format( $current_size ),
			'is_optimized'          => $is_optimized,
			'savings_pct_actual'    => $is_optimized ? round( $savings_pct, 1 ) : null,
			'savings_bytes_actual'  => $is_optimized ? $savings_bytes : null,
			'estimated_savings_pct' => $est_pct,
			'estimated_savings_hr'  => size_format( $est_savings_b ),
			'thumb_url'             => $thumb ? $thumb[0] : '',
			'title'                 => get_the_title( $attachment_id ),
			'is_excluded'           => $is_excluded,
		];
	}

	/**
	 * Estima el porcentaje de reducción esperado según el formato.
	 *
	 * @param  string $mime_type
	 * @return int
	 */
	private function estimate_savings_pct( $mime_type ) {
		$estimates = [
			'image/jpeg' => 28,
			'image/png'  => 35,
			'image/gif'  => 10,
			'image/webp' => 8,
		];
		return $estimates[ $mime_type ] ?? 20;
	}
}
