<?php
/**
 * Gestión de respaldos: crea una copia del archivo original antes de optimizarlo
 * y permite restaurarlo si es necesario.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Backup {

	/** Carpeta de respaldos dentro de uploads. */
	const BACKUP_FOLDER = 'ultimizer-backups';

	// -------------------------------------------------------------------------
	// Inicialización
	// -------------------------------------------------------------------------

	/**
	 * Crea la carpeta de respaldos y protege con .htaccess si no existe.
	 */
	public static function create_backup_dir() {
		$dir = self::get_backup_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Evitar acceso web directo a los respaldos.
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// index.php de seguridad.
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	// -------------------------------------------------------------------------
	// Crear respaldo
	// -------------------------------------------------------------------------

	/**
	 * Copia el archivo original al directorio de respaldos.
	 *
	 * @param  int    $attachment_id
	 * @param  string $file_path  Ruta absoluta al archivo de adjunto.
	 * @return string|false  Ruta al respaldo o false si falla.
	 */
	public function create( $attachment_id, $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		self::create_backup_dir();

		$backup_path = $this->get_backup_path( $attachment_id, $file_path );

		// Solo crear respaldo si todavía no existe (no sobreescribir el original).
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
	// Restaurar respaldo
	// -------------------------------------------------------------------------

	/**
	 * Restaura el archivo original desde el respaldo y limpia los meta de optimización.
	 *
	 * @param  int $attachment_id
	 * @return true|WP_Error
	 */
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

		// Limpiar todos los meta de optimización.
		delete_post_meta( $attachment_id, '_ultimizer_optimized' );
		delete_post_meta( $attachment_id, '_ultimizer_original_size' );
		delete_post_meta( $attachment_id, '_ultimizer_optimized_size' );
		delete_post_meta( $attachment_id, '_ultimizer_savings_bytes' );
		delete_post_meta( $attachment_id, '_ultimizer_savings_pct' );

		// Eliminar sidecars AVIF/WebP (formato correcto e incorrecto por compatibilidad).
		$base_path = preg_replace( '/\.[^.\/\\\\]+$/', '', $original_path );
		foreach ( [ '.avif', '.webp' ] as $ext ) {
			// Formato correcto: Livit-1.avif
			if ( file_exists( $base_path . $ext ) ) {
				@unlink( $base_path . $ext );
			}
			// Formato antiguo malformado: Livit-1.jpg.avif
			if ( file_exists( $original_path . $ext ) ) {
				@unlink( $original_path . $ext );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Eliminar respaldo
	// -------------------------------------------------------------------------

	/**
	 * Borra el archivo de respaldo de un adjunto.
	 *
	 * @param  int $attachment_id
	 * @return bool
	 */
	public function delete( $attachment_id ) {
		$backup_path = get_post_meta( $attachment_id, '_ultimizer_backup_path', true );
		if ( $backup_path && file_exists( $backup_path ) ) {
			@unlink( $backup_path );
		}
		delete_post_meta( $attachment_id, '_ultimizer_backup_path' );
		return true;
	}

	// -------------------------------------------------------------------------
	// Listar respaldos
	// -------------------------------------------------------------------------

	/**
	 * Devuelve información sobre todos los adjuntos con respaldo.
	 *
	 * @param  int $limit
	 * @param  int $offset
	 * @return array
	 */
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
			// Detectar sidecars generados (AVIF/WebP) para el archivo original.
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

	/**
	 * Calcula el tamaño total en bytes de todos los archivos de respaldo existentes.
	 *
	 * @return int
	 */
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

	/**
	 * Elimina el respaldo de un adjunto (archivo + meta).
	 * Alias público de delete() con nombre más explícito.
	 *
	 * @param  int $attachment_id
	 * @return bool
	 */
	public function delete_backup( $attachment_id ) {
		return $this->delete( $attachment_id );
	}

	/**
	 * Elimina TODOS los respaldos de la base de datos y del disco.
	 *
	 * @return int  Número de respaldos eliminados.
	 */
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
				// Eliminar el directorio del adjunto si quedó vacío.
				$att_dir = dirname( $path );
				if ( is_dir( $att_dir ) && count( glob( $att_dir . '/*' ) ) === 0 ) {
					@rmdir( $att_dir );
				}
			}
			delete_post_meta( (int) $row['post_id'], '_ultimizer_backup_path' );
			$count++;
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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

	/**
	 * Intenta encontrar el archivo de respaldo de un adjunto.
	 * Prueba la ruta guardada en meta y, como fallback, la recalcula desde get_attached_file().
	 *
	 * @param  int $attachment_id
	 * @return string|false  Ruta absoluta al archivo si existe, false si no.
	 */
	private function find_backup_file( $attachment_id ) {
		// 1. Intentar ruta guardada en meta.
		$stored = get_post_meta( (int) $attachment_id, '_ultimizer_backup_path', true );
		if ( $stored && file_exists( $stored ) ) {
			return $stored;
		}

		// 2. Recalcular desde el archivo adjunto actual.
		$file_path = get_attached_file( (int) $attachment_id );
		if ( $file_path ) {
			$fresh = $this->get_backup_path( $attachment_id, $file_path );
			if ( file_exists( $fresh ) ) {
				update_post_meta( (int) $attachment_id, '_ultimizer_backup_path', $fresh );
				return $fresh;
			}
		}

		// 3. Escanear el subdirectorio del adjunto en la carpeta de respaldos.
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
