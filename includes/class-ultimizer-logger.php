<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultimizer_Logger {

	const TABLE_NAME = 'ultimizer_log';

	// -------------------------------------------------------------------------
	// Estructura de la tabla
	// -------------------------------------------------------------------------

	public static function create_table() {
		global $wpdb;

		$table      = $wpdb->prefix . self::TABLE_NAME;
		$charset_db = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id             BIGINT(20)    NOT NULL AUTO_INCREMENT,
			attachment_id  BIGINT(20)    NOT NULL,
			file_path      VARCHAR(500)  NOT NULL,
			original_size  BIGINT(20)    NOT NULL DEFAULT 0,
			optimized_size BIGINT(20)    NOT NULL DEFAULT 0,
			savings_bytes  BIGINT(20)    NOT NULL DEFAULT 0,
			savings_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			format_original VARCHAR(30)  NOT NULL DEFAULT '',
			avif_generated  TINYINT(1)   NOT NULL DEFAULT 0,
			webp_generated  TINYINT(1)   NOT NULL DEFAULT 0,
			optimized_at   DATETIME      NOT NULL,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY optimized_at (optimized_at)
		) {$charset_db};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Insertar entrada
	// -------------------------------------------------------------------------

	public function log( array $data ) {
		global $wpdb;

		$defaults = [
			'attachment_id'   => 0,
			'file_path'       => '',
			'original_size'   => 0,
			'optimized_size'  => 0,
			'savings_bytes'   => 0,
			'savings_percent' => 0.0,
			'format_original' => '',
			'avif_generated'  => 0,
			'webp_generated'  => 0,
			'optimized_at'    => current_time( 'mysql' ),
		];

		$row = array_merge( $defaults, $data );

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			[
				'attachment_id'   => (int) $row['attachment_id'],
				'file_path'       => sanitize_text_field( $row['file_path'] ),
				'original_size'   => (int) $row['original_size'],
				'optimized_size'  => (int) $row['optimized_size'],
				'savings_bytes'   => (int) $row['savings_bytes'],
				'savings_percent' => (float) $row['savings_percent'],
				'format_original' => sanitize_text_field( $row['format_original'] ),
				'avif_generated'  => (int) $row['avif_generated'],
				'webp_generated'  => (int) $row['webp_generated'],
				'optimized_at'    => $row['optimized_at'],
			],
			[ '%d', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%d', '%s' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	// -------------------------------------------------------------------------
	// Consultar el log
	// -------------------------------------------------------------------------

	/**
	 * Devuelve entradas del log con paginación.
	 *
	 * @param  int $limit
	 * @param  int $offset
	 * @return array
	 */
	public function get_entries( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY optimized_at DESC LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
	}

	public function count_entries() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(id) FROM ' . $wpdb->prefix . self::TABLE_NAME );
	}

	// -------------------------------------------------------------------------
	// Global statistics
	// -------------------------------------------------------------------------

	public function get_global_stats() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		$row = $wpdb->get_row(
			"SELECT
				SUM(savings_bytes)    AS total_savings_bytes,
				AVG(savings_percent)  AS avg_savings_percent,
				COUNT(id)             AS total_entries
			 FROM {$table}",
			ARRAY_A
		);

		return [
			'total_savings_bytes'  => isset( $row['total_savings_bytes'] ) ? (int) $row['total_savings_bytes'] : 0,
			'avg_savings_percent'  => isset( $row['avg_savings_percent'] ) ? round( (float) $row['avg_savings_percent'], 2 ) : 0.0,
			'total_entries'        => isset( $row['total_entries'] ) ? (int) $row['total_entries'] : 0,
			'avif_count'           => $this->count_by_format( 'avif_generated' ),
			'webp_count'           => $this->count_by_format( 'webp_generated' ),
		];
	}

	private function count_by_format( $column ) {
		global $wpdb;
		$column = sanitize_key( $column );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE {$column} = 1" );
	}

	// -------------------------------------------------------------------------
	// Clear log
	// -------------------------------------------------------------------------

	public function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::TABLE_NAME );
	}
}
