<?php
/**
 * WP_List_Table implementation for scan history.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Scan history list table.
 */
class KIG_History_Table extends WP_List_Table {



	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'scan',
				'plural'   => 'scans',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get table columns.
	 *
	 * @return array Column headers.
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'scan_date'    => esc_html__( 'Scan Date', 'k-integrity-guard' ),
			'context'      => esc_html__( 'Context', 'k-integrity-guard' ),
			'targets'      => esc_html__( 'Targets', 'k-integrity-guard' ),
			'total_issues' => esc_html__( 'Issues Found', 'k-integrity-guard' ),
			'status'       => esc_html__( 'Status', 'k-integrity-guard' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array Sortable column definitions.
	 */
	protected function get_sortable_columns() {
		return array(
			'scan_date'    => array( 'scan_date', true ),
			'status'       => array( 'status', false ),
			'total_issues' => array( 'total_issues', false ),
			'context'      => array( 'context', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array Bulk actions.
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => esc_html__( 'Delete', 'k-integrity-guard' ),
		);
	}

	/**
	 * Get views for filtering.
	 *
	 * @return array Views array.
	 */
	protected function get_views() {
		global $wpdb;

		$current    = isset( $_GET['scan_status'] ) ? sanitize_key( $_GET['scan_status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get counts.
		$counts = KIG_DB::get_scan_counts();

		$views = array(
			'all' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( remove_query_arg( 'scan_status' ) ),
				'all' === $current ? 'current' : '',
				esc_html__( 'All', 'k-integrity-guard' ),
				$counts['all']
			),
		);

		if ( $counts['success'] > 0 ) {
			$views['success'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'scan_status', 'success' ) ),
				'success' === $current ? 'current' : '',
				esc_html__( 'Success', 'k-integrity-guard' ),
				$counts['success']
			);
		}

		if ( $counts['warning'] > 0 ) {
			$views['warning'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'scan_status', 'warning' ) ),
				'warning' === $current ? 'current' : '',
				esc_html__( 'Warnings', 'k-integrity-guard' ),
				$counts['warning']
			);
		}

		if ( $counts['error'] > 0 ) {
			$views['error'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'scan_status', 'error' ) ),
				'error' === $current ? 'current' : '',
				esc_html__( 'Errors', 'k-integrity-guard' ),
				$counts['error']
			);
		}

		return $views;
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page = 20;
		$current  = $this->get_pagenum();

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'scan_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['scan_status'] ) ? sanitize_key( $_GET['scan_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => $per_page,
			'page'     => $current,
			'orderby'  => $orderby,
			'order'    => $order,
			'status'   => $status,
		);

		$data = KIG_DB::get_scans( $args );

		$this->items = $data['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => $per_page,
				'total_pages' => ceil( $data['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row data.
	 * @return string Checkbox HTML.
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="scan_ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Scan date column.
	 *
	 * @param array $item Row data.
	 * @return string Column content.
	 */
	protected function column_scan_date( $item ) {
		$scan_id   = absint( $item['id'] );
		$date      = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['scan_date'] );
		$view_url  = add_query_arg(
			array(
				'action'  => 'view',
				'scan_id' => $scan_id,
			)
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'delete',
					'scan_id' => $scan_id,
				)
			),
			'delete_scan_' . $scan_id
		);

		$actions = array(
			'view'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $view_url ),
				esc_html__( 'View Details', 'k-integrity-guard' )
			),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to delete this scan?', 'k-integrity-guard' ) ),
				esc_html__( 'Delete', 'k-integrity-guard' )
			),
		);

		return sprintf( '<strong>%s</strong>%s', esc_html( $date ), $this->row_actions( $actions ) );
	}

	/**
	 * Context column.
	 *
	 * @param array $item Row data.
	 * @return string Column content.
	 */
	protected function column_context( $item ) {
		$context = sanitize_key( $item['context'] );

		if ( 'cron' === $context ) {
			return '<span class="wpig-context-badge wpig-context-cron">' . esc_html__( 'Scheduled', 'k-integrity-guard' ) . '</span>';
		}

		return '<span class="wpig-context-badge wpig-context-manual">' . esc_html__( 'Manual', 'k-integrity-guard' ) . '</span>';
	}

	/**
	 * Targets column.
	 *
	 * @param array $item Row data.
	 * @return string Column content.
	 */
	protected function column_targets( $item ) {
		$targets = is_array( $item['targets'] ) ? $item['targets'] : array();

		if ( empty( $targets ) ) {
			return '—';
		}

		$labels = array();
		foreach ( $targets as $target ) {
			switch ( $target ) {
				case 'core':
					$labels[] = esc_html__( 'Core', 'k-integrity-guard' );
					break;
				case 'plugins':
					$labels[] = esc_html__( 'Plugins', 'k-integrity-guard' );
					break;
				case 'themes':
					$labels[] = esc_html__( 'Themes', 'k-integrity-guard' );
					break;
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * Total issues column.
	 *
	 * @param array $item Row data.
	 * @return string Column content.
	 */
	protected function column_total_issues( $item ) {
		$total = absint( $item['total_issues'] );

		if ( 0 === $total ) {
			return '<span class="wpig-issues-badge wpig-issues-none">0</span>';
		}

		return '<span class="wpig-issues-badge wpig-issues-found">' . esc_html( number_format_i18n( $total ) ) . '</span>';
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row data.
	 * @return string Column content.
	 */
	protected function column_status( $item ) {
		$status = sanitize_key( $item['status'] );

		switch ( $status ) {
			case 'success':
				return '<span class="wpig-status-badge wpig-status-success">' . esc_html__( 'Success', 'k-integrity-guard' ) . '</span>';
			case 'warning':
				return '<span class="wpig-status-badge wpig-status-warning">' . esc_html__( 'Warning', 'k-integrity-guard' ) . '</span>';
			case 'error':
				return '<span class="wpig-status-badge wpig-status-error">' . esc_html__( 'Error', 'k-integrity-guard' ) . '</span>';
			default:
				return '<span class="wpig-status-badge wpig-status-unknown">' . esc_html__( 'Unknown', 'k-integrity-guard' ) . '</span>';
		}
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 * @return string Column content.
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
	}

	/**
	 * Message when no items are found.
	 */
	public function no_items() {
		esc_html_e( 'No scan history found. Run a scan to start tracking results.', 'k-integrity-guard' );
	}
}
