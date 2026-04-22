<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Backup {

	/** Backup folder inside uploads. */
	const BACKUP_FOLDER = 'ultimizer-backups';

	// -------------------------------------------------------------------------
	// Initialization
	// -------------------------------------------------------------------------

	public static function create_backup_dir() {
		$dir = self::get_backup_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Prevent direct web access to backups.
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Security index.php.
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	// -------------------------------------------------------------------------
	// Create backup
	// -------------------------------------------------------------------------

	public function create( $attachment_id, $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		self::create_backup_dir();

		$backup_path = $this->get_backup_path( $attachment_id, $file_path );

		// Only create backup if it doesn't already exist (do not overwrite the original).
		if ( file_exists( $backup_path ) ) {
			return $backup_path;
		}

		$backup_dir = dirname( $backup_path );
		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		if ( copy( $file_path, $backup_path ) ) {
			update_post_meta( $attachment_id, '_ultimizer_backup_path', $backup_path );
			return $backup_path;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Restore backup
	// -------------------------------------------------------------------------

	public function restore( $attachment_id ) {
		$backup_path = $this->find_backup_file( $attachment_id );

		if ( ! $backup_path ) {
			return new WP_Error( 'no_backup', 'No se encontró un respaldo para este adjunto.' );
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! $original_path ) {
			return new WP_Error( 'no_file', 'No se pudo determinar la ruta del adjunto.' );
		}

		if ( ! copy( $backup_path, $original_path ) ) {
			return new WP_Error( 'restore_failed', 'No se pudo copiar el respaldo al destino.' );
		}

		// Clear all optimization meta.
		delete_post_meta( $attachment_id, '_ultimizer_optimized' );
		delete_post_meta( $attachment_id, '_ultimizer_original_size' );
		delete_post_meta( $attachment_id, '_ultimizer_optimized_size' );
		delete_post_meta( $attachment_id, '_ultimizer_savings_bytes' );
		delete_post_meta( $attachment_id, '_ultimizer_savings_pct' );

		// Delete AVIF/WebP sidecars (both correct and legacy formats for compatibility).
		$base_path = preg_replace( '/\.[^.\/\\\\]+$/', '', $original_path );
		foreach ( [ '.avif', '.webp' ] as $ext ) {
			// Correct format: image.avif
			if ( file_exists( $base_path . $ext ) ) {
				@unlink( $base_path . $ext );
			}
			// Legacy malformed format: image.jpg.avif
			if ( file_exists( $original_path . $ext ) ) {
				@unlink( $original_path . $ext );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Delete backup
	// -------------------------------------------------------------------------

	public function delete( $attachment_id ) {
		$backup_path = get_post_meta( $attachment_id, '_ultimizer_backup_path', true );
		if ( $backup_path && file_exists( $backup_path ) ) {
			@unlink( $backup_path );
			$this->cleanup_empty_dirs( dirname( $backup_path ) );
		}
		delete_post_meta( $attachment_id, '_ultimizer_backup_path' );
		return true;
	}

	// -------------------------------------------------------------------------
	// List backups
	// -------------------------------------------------------------------------

	public function get_backups( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value AS backup_path
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_ultimizer_backup_path'
				 ORDER BY post_id DESC
				 LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);

		$backups = [];
		foreach ( $rows as $row ) {
			$att_id      = (int) $row['post_id'];
			$path        = $this->find_backup_file( $att_id );
			$exists      = (bool) $path;
			if ( ! $path ) { $path = $row['backup_path']; }
			$size_bytes  = $exists ? (int) filesize( $path ) : 0;
			// Detect generated sidecars (AVIF/WebP) for the original file.
			$orig_path   = get_attached_file( $att_id );
			$base        = $orig_path ? preg_replace( '/\.[^.\/\\\\]+$/', '', $orig_path ) : '';
			$backups[] = [
				'attachment_id' => $att_id,
				'backup_path'   => $path,
				'exists'        => $exists,
				'size'          => $exists ? size_format( $size_bytes ) : '—',
				'size_bytes'    => $size_bytes,
				'filename'      => basename( $path ),
				'title'         => get_the_title( $att_id ),
				'optimized_at'  => get_post_meta( $att_id, '_ultimizer_optimized', true ),
				'has_avif'      => $base && file_exists( $base . '.avif' ),
				'has_webp'      => $base && file_exists( $base . '.webp' ),
			];
		}

		return $backups;
	}

	public function count_backups() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(post_id)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_ultimizer_backup_path'"
		);
	}

	public function get_total_backup_size() {
		$total = 0;
		$dir   = self::get_backup_dir();
		if ( ! is_dir( $dir ) ) { return 0; }
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( $file->isFile() && ! in_array( $file->getFilename(), [ '.htaccess', 'index.php' ], true ) ) {
				$total += $file->getSize();
			}
		}
		return $total;
	}

	public function delete_backup( $attachment_id ) {
		return $this->delete( $attachment_id );
	}

	public function delete_all_backups() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ultimizer_backup_path'",
			ARRAY_A
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$path = $this->find_backup_file( (int) $row['post_id'] );
			if ( $path && file_exists( $path ) ) {
				@unlink( $path );
				$this->cleanup_empty_dirs( dirname( $path ) );
			}
			delete_post_meta( (int) $row['post_id'], '_ultimizer_backup_path' );
			$count++;
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Walk up the directory tree removing empty directories until reaching the backup root.
	 */
	private function cleanup_empty_dirs( $dir ) {
		$root = rtrim( self::get_backup_dir(), '/' );
		$dir  = rtrim( str_replace( '\\', '/', $dir ), '/' );

		while ( $dir && $dir !== $root && is_dir( $dir ) ) {
			$entries = array_diff( scandir( $dir ), [ '.', '..' ] );
			if ( count( $entries ) > 0 ) {
				break;
			}
			@rmdir( $dir );
			$dir = dirname( $dir );
		}
	}

	public static function get_backup_dir() {
		$upload_dir = wp_upload_dir();
		$base = str_replace( '\\', '/', trailingslashit( $upload_dir['basedir'] ) );
		return $base . self::BACKUP_FOLDER . '/';
	}

	private function get_backup_path( $attachment_id, $file_path ) {
		$basedir   = str_replace( '\\', '/', wp_upload_dir()['basedir'] );
		$file_path = str_replace( '\\', '/', $file_path );
		$relative  = ltrim( str_replace( $basedir, '', $file_path ), '/\\' );
		return self::get_backup_dir() . (int) $attachment_id . '/' . $relative;
	}

	private function find_backup_file( $attachment_id ) {
		// 1. Try stored path from meta.
		$stored = get_post_meta( (int) $attachment_id, '_ultimizer_backup_path', true );
		if ( $stored && file_exists( $stored ) ) {
			return $stored;
		}

		// 2. Recalculate from the current attached file.
		$file_path = get_attached_file( (int) $attachment_id );
		if ( $file_path ) {
			$fresh = $this->get_backup_path( $attachment_id, $file_path );
			if ( file_exists( $fresh ) ) {
				update_post_meta( (int) $attachment_id, '_ultimizer_backup_path', $fresh );
				return $fresh;
			}
		}

		// 3. Scan the attachment subdirectory in the backup folder.
		$att_dir = self::get_backup_dir() . (int) $attachment_id . '/';
		if ( is_dir( $att_dir ) ) {
			$found = glob( $att_dir . '*/*' );
			if ( empty( $found ) ) {
				$found = glob( $att_dir . '*' );
			}
			foreach ( (array) $found as $f ) {
				if ( is_file( $f ) ) {
					update_post_meta( (int) $attachment_id, '_ultimizer_backup_path', $f );
					return $f;
				}
			}
		}

		return false;
	}
}
