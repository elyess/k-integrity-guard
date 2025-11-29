<?php
/**
 * Third-party items tables.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class KIG_Third_Party_Plugins_Table extends WP_List_Table {

	/**
	 * Raw items to render.
	 *
	 * @var array
	 */
	protected $items_data = array();

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments, expects 'items'.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			array(
				'singular' => 'plugin',
				'plural'   => 'plugins',
				'ajax'     => false,
			)
		);

		$this->items_data = isset( $args['items'] ) ? (array) $args['items'] : array();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name'    => esc_html__( 'Plugin', 'k-integrity-guard' ),
			'version' => esc_html__( 'Version', 'k-integrity-guard' ),
			'status'  => esc_html__( 'Status', 'k-integrity-guard' ),
			'ignore'  => esc_html__( 'Ignore', 'k-integrity-guard' ),
			'action'  => esc_html__( 'Action', 'k-integrity-guard' ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'name';
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->items_data;
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	/**
	 * Message when no rows.
	 */
	public function no_items() {
		esc_html_e( 'No third-party plugins detected.', 'k-integrity-guard' );
	}
}

class KIG_Third_Party_Themes_Table extends WP_List_Table {

	/**
	 * Raw items to render.
	 *
	 * @var array
	 */
	protected $items_data = array();

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments, expects 'items'.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			array(
				'singular' => 'theme',
				'plural'   => 'themes',
				'ajax'     => false,
			)
		);

		$this->items_data = isset( $args['items'] ) ? (array) $args['items'] : array();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name'    => esc_html__( 'Theme', 'k-integrity-guard' ),
			'version' => esc_html__( 'Version', 'k-integrity-guard' ),
			'status'  => esc_html__( 'Status', 'k-integrity-guard' ),
			'ignore'  => esc_html__( 'Ignore', 'k-integrity-guard' ),
			'action'  => esc_html__( 'Action', 'k-integrity-guard' ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'name';
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->items_data;
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	/**
	 * Message when no rows.
	 */
	public function no_items() {
		esc_html_e( 'No third-party themes detected.', 'k-integrity-guard' );
	}
}

