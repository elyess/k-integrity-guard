<?php
/**
 * Database handler for K'Integrity Guard scan history.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the custom database table for scan history.
 */
class KIG_DB {

	/**
	 * Database table version for upgrades.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Option name for storing database version.
	 */
	/**
	 * Option name for storing database version.
	 */
	const DB_VERSION_OPTION = 'kig_db_version';

	/**
	 * Cache group for scan counts.
	 */
	/**
	 * Cache group for scan counts.
	 */
	const CACHE_GROUP = 'kig_scan_counts';

	/**
	 * Cache group for scan objects.
	 */
	const CACHE_GROUP_SCANS = 'kig_scans';

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @return string Table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'kig_scan_history';
	}

	/**
	 * Create the scan history table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_date datetime NOT NULL,
			context varchar(20) NOT NULL DEFAULT 'manual',
			targets text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			summary text NOT NULL,
			results longtext NOT NULL,
			total_issues int(11) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY scan_date (scan_date),
			KEY status (status),
			KEY context (context)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert a new scan record.
	 *
	 * @param array $data {
	 *     Scan data to insert.
	 *
	 *     @type string $scan_date     Scan completion datetime (MySQL format).
	 *     @type string $context       Scan context (manual|cron).
	 *     @type array  $targets       List of scanned targets.
	 *     @type string $status        Overall scan status (success|warning|error).
	 *     @type string $summary       Human-readable summary.
	 *     @type array  $results       Full scan results array.
	 *     @type int    $total_issues  Total number of issues found.
	 *     @type int    $created_by    User ID who initiated the scan.
	 * }
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function insert_scan( array $data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Ensure table exists, create if not (handles case where plugin wasn't reactivated after update).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			self::create_table();
		}

		$defaults = array(
			'scan_date'    => current_time( 'mysql' ),
			'context'      => 'manual',
			'targets'      => array(),
			'status'       => 'success',
			'summary'      => '',
			'results'      => array(),
			'total_issues' => 0,
			'created_by'   => get_current_user_id(),
		);

		$data = wp_parse_args( $data, $defaults );

		$insert_data = array(
			'scan_date'    => $data['scan_date'],
			'context'      => sanitize_key( $data['context'] ),
			'targets'      => wp_json_encode( $data['targets'] ),
			'status'       => sanitize_key( $data['status'] ),
			'summary'      => sanitize_text_field( $data['summary'] ),
			'results'      => wp_json_encode( $data['results'] ),
			'total_issues' => absint( $data['total_issues'] ),
			'created_by'   => absint( $data['created_by'] ),
		);

		$result = $wpdb->insert(
			self::get_table_name(),
			$insert_data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		wp_cache_delete( 'scan_counts', self::CACHE_GROUP );
		self::increment_last_changed();

		return $wpdb->insert_id;
	}

	/**
	 * Get scan history with pagination and filtering.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type int    $per_page  Number of results per page. Default 20.
	 *     @type int    $page      Current page number. Default 1.
	 *     @type string $orderby   Column to order by. Default 'scan_date'.
	 *     @type string $order     Order direction (ASC|DESC). Default 'DESC'.
	 *     @type string $status    Filter by status. Default empty (all).
	 *     @type string $context   Filter by context. Default empty (all).
	 * }
	 * @return array {
	 *     Query results.
	 *
	 *     @type array $items Array of scan records.
	 *     @type int   $total Total number of records.
	 * }
	 */
	public static function get_scans( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'scan_date',
			'order'    => 'DESC',
			'status'   => '',
			'context'  => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$last_changed = self::get_last_changed();
		$key          = md5( serialize( $args ) . $last_changed );
		$cached       = wp_cache_get( $key, self::CACHE_GROUP_SCANS );

		if ( false !== $cached ) {
			return $cached;
		}

		$table_name = self::get_table_name();
		$per_page   = absint( $args['per_page'] );
		$page       = absint( $args['page'] );
		$offset     = ( $page - 1 ) * $per_page;
		$orderby    = sanitize_key( $args['orderby'] );
		$order      = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Validate orderby column.
		$allowed_orderby = array( 'id', 'scan_date', 'status', 'total_issues', 'context' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'scan_date';
		}

		// Escape for query usage
		$table_name_esc = esc_sql( $table_name );
		$orderby_esc    = esc_sql( $orderby );

		$where = array( '1=1' );
		$query_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$query_args[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['context'] ) ) {
			$where[] = 'context = %s';
			$query_args[] = sanitize_key( $args['context'] );
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count.
		if ( ! empty( $query_args ) ) {
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name_esc} WHERE {$where_clause}", $query_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_esc} WHERE {$where_clause}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Get paginated results.
		$query_args[] = $per_page;
		$query_args[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name_esc} WHERE {$where_clause} ORDER BY {$orderby_esc} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$query_args
			),
			ARRAY_A
		);

		// Decode JSON fields.
		if ( ! empty( $items ) ) {
			foreach ( $items as &$item ) {
				$item['targets'] = json_decode( $item['targets'], true );
				$item['results'] = json_decode( $item['results'], true );
			}
		}

		$result = array(
			'items' => $items,
			'total' => (int) $total,
		);

		wp_cache_set( $key, $result, self::CACHE_GROUP_SCANS );

		return $result;
	}

	/**
	 * Get a single scan record by ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|null Scan record or null if not found.
	 */
	public static function get_scan( $scan_id ) {
		global $wpdb;

		$scan_id = absint( $scan_id );
		if ( 0 === $scan_id ) {
			return null;
		}

		$cached = wp_cache_get( 'scan_' . $scan_id, self::CACHE_GROUP_SCANS );
		if ( false !== $cached ) {
			return $cached;
		}

		$table_name = self::get_table_name();
		$scan       = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . esc_sql( $table_name ) . " WHERE id = %d", $scan_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $scan ) {
			return null;
		}

		// Decode JSON fields.
		$scan['targets'] = json_decode( $scan['targets'], true );
		$scan['results'] = json_decode( $scan['results'], true );

		wp_cache_set( 'scan_' . $scan_id, $scan, self::CACHE_GROUP_SCANS );

		return $scan;
	}

	/**
	 * Delete a scan record.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_scan( $scan_id ) {
		global $wpdb;

		$scan_id = absint( $scan_id );
		if ( 0 === $scan_id ) {
			return false;
		}

		$result = $wpdb->delete(
			self::get_table_name(),
			array( 'id' => $scan_id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'scan_counts', self::CACHE_GROUP );
			wp_cache_delete( 'scan_' . $scan_id, self::CACHE_GROUP_SCANS );
			self::increment_last_changed();
			return true;
		}

		return false;
	}

	/**
	 * Delete multiple scan records.
	 *
	 * @param array $scan_ids Array of scan IDs to delete.
	 * @return int Number of records deleted.
	 */
	public static function delete_scans( array $scan_ids ) {
		global $wpdb;

		if ( empty( $scan_ids ) ) {
			return 0;
		}

		$scan_ids     = array_map( 'absint', $scan_ids );
		$scan_ids     = array_filter( $scan_ids );

		if ( empty( $scan_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $scan_ids ), '%d' ) );
		$table_name   = self::get_table_name();

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . esc_sql( $table_name ) . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$scan_ids
			)
		);

		if ( false !== $result ) {
			wp_cache_delete( 'scan_counts', self::CACHE_GROUP );
			foreach ( $scan_ids as $id ) {
				wp_cache_delete( 'scan_' . $id, self::CACHE_GROUP_SCANS );
			}
			self::increment_last_changed();
		}

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * Delete all scan records.
	 *
	 * @return int Number of records deleted.
	 */
	public static function delete_all_scans() {
		global $wpdb;

		$table_name = self::get_table_name();
		$result     = $wpdb->query( "TRUNCATE TABLE " . esc_sql( $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false !== $result ) {
			wp_cache_delete( 'scan_counts', self::CACHE_GROUP );
			self::increment_last_changed();
		}

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * Delete old scan records beyond a retention period.
	 *
	 * @param int $days Number of days to retain. Default 90.
	 * @return int Number of records deleted.
	 */
	public static function delete_old_scans( $days = 90 ) {
		global $wpdb;

		$days       = absint( $days );
		$table_name = self::get_table_name();
		$date       = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . esc_sql( $table_name ) . " WHERE scan_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);

		if ( false !== $result ) {
			wp_cache_delete( 'scan_counts', self::CACHE_GROUP );
			self::increment_last_changed();
		}

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * Get scan counts by status.
	 *
	 * @return array<string,int> Counts keyed by status (all, success, warning, error).
	 */
	public static function get_scan_counts() {
		$counts = wp_cache_get( 'scan_counts', self::CACHE_GROUP );

		if ( false !== $counts ) {
			return $counts;
		}

		global $wpdb;
		$table_name = self::get_table_name();

		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM " . esc_sql( $table_name ) . " GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array(
			'all'     => 0,
			'success' => 0,
			'warning' => 0,
			'error'   => 0,
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$status = $row['status'];
				$count  = (int) $row['count'];

				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = $count;
				}

				$counts['all'] += $count;
			}
		}

		wp_cache_set( 'scan_counts', $counts, self::CACHE_GROUP );

		return $counts;
	}

	/**
	 * Get the last changed timestamp for the scans group.
	 *
	 * @return string Last changed timestamp.
	 */
	private static function get_last_changed() {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP_SCANS );

		if ( ! $last_changed ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP_SCANS );
		}

		return $last_changed;
	}

	/**
	 * Increment the last changed timestamp to invalidate list caches.
	 */
	private static function increment_last_changed() {
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP_SCANS );
	}
}
