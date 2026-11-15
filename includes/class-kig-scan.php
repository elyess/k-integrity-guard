<?php
/**
 * Scan handling for K'Integrity Guard.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KIG_Scan {

	const JOB_PREFIX = 'kig_scan_job_';
	const OPTION_LAST_RESULT = 'kig_last_scan_result';

	private const JOB_TTL = 900;
	private const CORE_CHUNK_SIZE = 75;
	private const CORE_EXCLUDED_FILES = array(
		'wp-config.php',
	);
	private const CORE_EXCLUDED_PREFIXES = array(
		'wp-content/uploads',
		'wp-content/cache',
		'wp-content/plugins',
		'wp-content/themes',
		'wp-content/mu-plugins',
		'wp-content/blogs.dir',
		'wp-content/upgrade',
	);
	private const MAX_SYNC_STEPS = 5000;

	/**
	 * WordPress default bundled themes.
	 *
	 * These themes are verified as part of WordPress core scan.
	 */
	private const WP_DEFAULT_THEMES = array(
		'twentytwentyfive',
		'twentytwentyfour',
		'twentytwentythree',
		'twentytwentytwo',
		'twentytwentyone',
		'twentytwenty',
		'twentynineteen',
		'twentyseventeen',
		'twentysixteen',
		'twentyfifteen',
		'twentyfourteen',
		'twentythirteen',
		'twentytwelve',
		'twentyeleven',
		'twentyten',
	);

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Admin menu slug for the scan page.
	 *
	 * @var string
	 */
	private string $menu_slug;



	/**
	 * Plugin version string.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Settings handler.
	 *
	 * @var KIG_Settings
	 */
	private KIG_Settings $settings;

	/**
	 * Database handler.
	 *
	 * @var KIG_DB
	 */
	private KIG_DB $db;

	/**
	 * Constructor.
	 *
	 * @param string        $plugin_file Main plugin file path.
	 * @param string        $menu_slug   Scan menu slug.
	 * @param KIG_Settings $settings    Settings instance.
	 * @param string        $textdomain  Text domain.
	 * @param string        $version     Plugin version.
	 * @param KIG_DB       $db          Database handler instance.
	 */
	public function __construct( string $plugin_file, string $menu_slug, KIG_Settings $settings, string $version, KIG_DB $db ) {
		$this->plugin_file = $plugin_file;
		$this->menu_slug   = $menu_slug;
		$this->settings    = $settings;
		$this->version     = $version;
		$this->db          = $db;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_kig_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'wp_ajax_kig_scan_status', array( $this, 'handle_scan_status' ) );
		add_action( 'kig_daily_scan', array( $this, 'run_scheduled_scan' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
	}

	/**
	 * Enqueue assets for the scan screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_scan_screen( $hook ) ) {
			return;
		}

		$handle = 'wpig-scan';
		$src    = plugin_dir_url( $this->plugin_file ) . 'assets/js/scan.js';

		wp_enqueue_script( $handle, $src, array( 'jquery' ), $this->version, true );

		$options = $this->settings->get_options();

		wp_localize_script(
			$handle,
			'wpigScan',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'start'  => wp_create_nonce( 'kig_start_scan' ),
					'status' => wp_create_nonce( 'kig_scan_status' ),
				),
				'defaults' => array(
					'targets' => $options['targets'],
				),
				'lastScan' => $this->get_last_scan_summary(),
				'text'     => array(
					'progressIdle'       => esc_html__( 'Waiting to start…', 'k-integrity-guard' ),
					'progressPreparing'  => esc_html__( 'Preparing scan…', 'k-integrity-guard' ),
					'progressFinalizing' => esc_html__( 'Wrapping up scan…', 'k-integrity-guard' ),
					'progressCompleted'  => esc_html__( 'Scan completed.', 'k-integrity-guard' ),
					'noIssues'           => esc_html__( 'No integrity issues detected.', 'k-integrity-guard' ),
					'issuesFound'        => esc_html__( 'Integrity issues detected.', 'k-integrity-guard' ),
					'errorMessage'       => esc_html__( 'Unable to continue the scan. Please try again.', 'k-integrity-guard' ),
					'lookAtSummary'      => esc_html__( 'Review the scan summary below for details.', 'k-integrity-guard' ),
					'viewLastSummary'    => esc_html__( 'Last scan summary:', 'k-integrity-guard' ),
					/* translators: %s: Date and time of the last scan. */
					'lastRunLabel'       => esc_html__( 'Completed on %s', 'k-integrity-guard' ),
					'buttonStart'        => esc_html__( 'Start Scan', 'k-integrity-guard' ),
					'buttonWorking'      => esc_html__( 'Starting…', 'k-integrity-guard' ),
					/* translators: %d: Number of modified files. */
					'modifiedTitle'      => esc_html__( 'Modified files (%d)', 'k-integrity-guard' ),
					/* translators: %d: Number of missing files. */
					'missingTitle'       => esc_html__( 'Missing files (%d)', 'k-integrity-guard' ),
					/* translators: %d: Number of unexpected files. */
					'addedTitle'         => esc_html__( 'Unexpected files (%d)', 'k-integrity-guard' ),
					'coreHeading'        => esc_html__( 'WordPress Core', 'k-integrity-guard' ),
					'pluginsHeading'     => esc_html__( 'Plugins', 'k-integrity-guard' ),
					'themesHeading'      => esc_html__( 'Themes', 'k-integrity-guard' ),
					'thirdPartyPluginsHeading' => esc_html__( 'Third-Party Plugins', 'k-integrity-guard' ),
					'thirdPartyThemesHeading'  => esc_html__( 'Third-Party Themes', 'k-integrity-guard' ),
					/* translators: %d: Number of skipped items. */
					'skippedLabel'       => esc_html__( 'Skipped (%d)', 'k-integrity-guard' ),
					/* translators: %d: Number of errors. */
					'errorsLabel'        => esc_html__( 'Errors (%d)', 'k-integrity-guard' ),
					'issuesDetected'     => esc_html__( 'Differences detected', 'k-integrity-guard' ),
					'noDifferences'      => esc_html__( 'No differences detected.', 'k-integrity-guard' ),
					'statusError'        => esc_html__( 'Verification failed.', 'k-integrity-guard' ),
					/* translators: %s: Version number. */
					'versionLabel'       => esc_html__( 'Version %s', 'k-integrity-guard' ),
				),
			)
		);
	}

	/**
	 * Should assets be enqueued for the current page.
	 *
	 * @param string $hook Admin page hook.
	 * @return bool
	 */
	private function is_scan_screen( string $hook ): bool {
		$hooks = array(
			'toplevel_page_' . $this->menu_slug,
			$this->menu_slug . '_page_' . $this->menu_slug,
		);

		return in_array( $hook, $hooks, true );
	}

	/**
	 * Retrieve available scan targets.
	 *
	 * @return array<string,string>
	 */
	public function get_targets(): array {
		return array(
			'core'                => esc_html__( 'WordPress Core', 'k-integrity-guard' ),
			'plugins'             => esc_html__( 'Plugins', 'k-integrity-guard' ),
			'themes'              => esc_html__( 'Themes', 'k-integrity-guard' ),
			'third_party_plugins' => esc_html__( 'Third-Party Plugins', 'k-integrity-guard' ),
			'third_party_themes'  => esc_html__( 'Third-Party Themes', 'k-integrity-guard' ),
		);
	}

	/**
	 * Handle the AJAX request for starting a scan.
	 */
	public function handle_start_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'k-integrity-guard' ) ), 403 );
		}

		check_ajax_referer( 'kig_start_scan', 'nonce' );

		$raw_targets = isset( $_POST['targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['targets'] ) ) : array();
		$selected    = $this->resolve_targets( $raw_targets );

		$job_id = wp_generate_uuid4();
		$job    = $this->create_job_state( $selected, 'manual' );

		$this->save_job_state( $job_id, $job );

		wp_send_json_success(
			array(
				'job' => $job_id,
			)
		);
	}

	/**
	 * Handle the AJAX request for polling scan progress.
	 */
	public function handle_scan_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'k-integrity-guard' ) ), 403 );
		}

		check_ajax_referer( 'kig_scan_status', 'nonce' );

		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';

		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid job identifier.', 'k-integrity-guard' ) ), 400 );
		}

		$job = $this->load_job_state( $job_id );

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Scan session expired. Please start again.', 'k-integrity-guard' ) ), 410 );
		}

		$result = $this->advance_job_state( $job );
		$job    = $result['job'];

		$completed_at = 0;

		if ( $result['completed'] ) {
			$this->delete_job_state( $job_id );
			$completed_at = $this->persist_scan_result( $job );
		} else {
			$this->save_job_state( $job_id, $job );
		}

		$message = $result['message'];
		if ( '' === $message ) {
			$message = __( 'Processing…', 'k-integrity-guard' );
		}

		$response = array(
			'progress'     => $result['progress'],
			'current_item' => $message,
			'completed'    => $result['completed'],
		);

		if ( $result['completed'] ) {
			$response['summary']     = $result['summary'];
			$response['results']     = $job['results'];
			$response['completedAt'] = $completed_at;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Run the scheduled scan via WP-Cron using saved targets.
	 */
	public function run_scheduled_scan(): void {
		$options = $this->settings->get_options();
		$targets = array();

		foreach ( array_keys( $this->get_targets() ) as $target ) {
			if ( ! empty( $options['targets'][ $target ] ) ) {
				$targets[] = $target;
			}
		}

		if ( empty( $targets ) ) {
			$targets[] = 'core';
		}

		$job = $this->create_job_state( $targets, 'cron' );

		$iterations = 0;
		do {
			$result = $this->advance_job_state( $job );
			$job    = $result['job'];
			$iterations++;

			if ( $iterations > self::MAX_SYNC_STEPS ) {
				break;
			}
		} while ( ! $result['completed'] );

		$this->persist_scan_result( $job );
		$this->db->delete_old_scans( 90 );
	}

	/**
	 * Ensure the daily cron event aligns with the saved preference.
	 */
	public function maybe_schedule_cron(): void {
		$options   = $this->settings->get_options();
		$enabled   = ! empty( $options['daily_scan_enabled'] );
		$timestamp = wp_next_scheduled( 'kig_daily_scan' );

		if ( $enabled && false === $timestamp ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kig_daily_scan' );
			return;
		}

		if ( ! $enabled && false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'kig_daily_scan' );
		}
	}

	/**
	 * Resolve user-selected targets or fall back to saved defaults.
	 *
	 * @param array<string,mixed> $raw_targets Raw targets from the request.
	 * @return array<int,string>
	 */
	private function resolve_targets( array $raw_targets ): array {
		$available = array_keys( $this->get_targets() );
		$selected  = array();

		foreach ( $available as $target ) {
			if ( ! empty( $raw_targets[ $target ] ) ) {
				$selected[] = $target;
			}
		}

		if ( ! empty( $selected ) ) {
			return $selected;
		}

		$options = $this->settings->get_options();

		foreach ( $available as $target ) {
			if ( ! empty( $options['targets'][ $target ] ) ) {
				$selected[] = $target;
			}
		}

		if ( empty( $selected ) ) {
			$selected[] = 'core';
		}

		return $selected;
	}

	/**
	 * Create initial job state for a scan.
	 *
	 * @param array<int,string> $targets Selected targets.
	 * @param string            $context Scan context (manual|cron).
	 * @return array<string,mixed>
	 */
	private function create_job_state( array $targets, string $context ): array {
		$target_states = array();

		foreach ( $targets as $target ) {
			$target_states[ $target ] = array(
				'status'    => 'pending',
				'processed' => 0,
				'total'     => 0,
				'message'   => '',
			);
		}

		$include_core_themes = in_array( 'themes', $targets, true );

		if ( $include_core_themes && isset( $target_states['core'] ) ) {
			$target_states['core']['include_core_themes'] = true;
		}

		return array(
			'context'        => $context,
			'targets'        => array_values( $targets ),
			'current_target' => 0,
			'target_states'  => $target_states,
			'created'        => time(),
			'completed'      => false,
			'errors'         => array(),
			'results'        => array(),
			'summary'        => '',
		);
	}

	/**
	 * Save job state into a transient.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string,mixed>  $state  Job state.
	 */
	private function save_job_state( string $job_id, array $state ): void {
		set_transient( self::JOB_PREFIX . $job_id, $state, self::JOB_TTL );
	}

	/**
	 * Load job state from a transient.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string,mixed>|null
	 */
	private function load_job_state( string $job_id ): ?array {
		$data = get_transient( self::JOB_PREFIX . $job_id );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Remove job state transient.
	 *
	 * @param string $job_id Job identifier.
	 */
	private function delete_job_state( string $job_id ): void {
		delete_transient( self::JOB_PREFIX . $job_id );
	}

	/**
	 * Advance the scan state by executing the next step.
	 *
	 * @param array<string,mixed> $job        Current job state.
	 * @param int                 $operations Maximum operations to perform.
	 * @return array<string,mixed>
	 */
	private function advance_job_state( array $job, int $operations = 1 ): array {
		$message    = '';
		$performed  = 0;
		$operations = max( 1, $operations );
		$extensions = $this->get_extension_targets();

		while ( $performed < $operations && empty( $job['completed'] ) ) {
			$total_targets = count( $job['targets'] );

			if ( 0 === $total_targets ) {
				$job['completed'] = true;
				break;
			}

			$current_index = (int) $job['current_target'];

			if ( $current_index >= $total_targets ) {
				$job['completed'] = true;
				break;
			}

			$target = $job['targets'][ $current_index ];
			$state  = &$job['target_states'][ $target ];
			$performed++;

			if ( 'core' === $target ) {
				$result = $this->advance_core_target( $state );
			} elseif ( isset( $extensions[ $target ] ) ) {
				$result = $this->advance_extension_target( $state, $extensions[ $target ] );
			} else {
				$state['status']  = 'skipped';
				$state['message'] = __( 'Scanning for this target is not yet available.', 'k-integrity-guard' );
				$result           = array(
					'complete' => true,
					'message'  => $state['message'],
				);
			}

			$message = $result['message'];

			if ( ! empty( $result['error'] ) ) {
				$job['errors'][] = array(
					'target'  => $target,
					'message' => $result['message'],
				);
			}

			if ( ! empty( $result['complete'] ) ) {
				if ( 'core' === $target ) {
					$job['results'][ $target ] = $this->summarize_core_result( $state );
				} elseif ( isset( $extensions[ $target ] ) ) {
					$job['results'][ $target ] = $this->summarize_extension_result( $state, $extensions[ $target ] );
				} else {
					$job['results'][ $target ] = array(
						'status'  => 'skipped',
						'message' => $state['message'],
					);
				}

				$job['current_target']++;
			}

			unset( $state );
		}

		if ( $job['current_target'] >= count( $job['targets'] ) ) {
			$job['completed'] = true;
		}

		if ( ! empty( $job['completed'] ) ) {
			$job['summary'] = $this->build_summary( $job );
		}

		$progress = $this->calculate_progress( $job );

		return array(
			'job'       => $job,
			'message'   => $message,
			'progress'  => $progress,
			'completed' => ! empty( $job['completed'] ),
			'summary'   => $job['summary'] ?? '',
		);
	}

	/**
	 * Advance the WordPress core scan state by one step.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function advance_core_target( array &$state ): array {
		$status = $state['status'] ?? 'pending';

		if ( 'pending' === $status ) {
			$state['status']  = 'preparing';
			$state['message'] = __( 'Fetching WordPress core checksums…', 'k-integrity-guard' );

			$result = $this->prepare_core_target( $state );

			if ( is_wp_error( $result ) ) {
				$state['status']        = 'error';
				$state['error_message'] = $result->get_error_message();
				$state['message']       = $state['error_message'];

				return array(
					'complete' => true,
					'error'    => true,
					'message'  => $state['message'],
				);
			}

			$state['status']  = 'processing';
			$state['message'] = __( 'Scanning WordPress core files…', 'k-integrity-guard' );

			return array(
				'complete' => false,
				'message'  => $state['message'],
			);
		}

		if ( 'processing' === $status ) {
			$chunk = $this->process_core_chunk( $state );

			if ( ! empty( $chunk['error'] ) ) {
				$state['status']        = 'error';
				$state['error_message'] = $chunk['error'];
				$state['message']       = $chunk['error'];

				return array(
					'complete' => true,
					'error'    => true,
					'message'  => $state['message'],
				);
			}

			if ( ! empty( $chunk['finished'] ) ) {
				$state['status']  = 'finalizing';
				$state['message'] = __( 'Wrapping up WordPress core scan…', 'k-integrity-guard' );

				return array(
					'complete' => false,
					'message'  => $state['message'],
				);
			}

			$state['message'] = $chunk['message'] ?? __( 'Scanning WordPress core files…', 'k-integrity-guard' );

			return array(
				'complete' => false,
				'message'  => $state['message'],
			);
		}

		if ( 'finalizing' === $status ) {
			$this->finalize_core_target( $state );
			$state['status']  = 'completed';
			$state['message'] = $this->build_core_summary_message( $state );

			return array(
				'complete' => true,
				'message'  => $state['message'],
			);
		}

		if ( 'completed' === $status ) {
			return array(
				'complete' => true,
				'message'  => $state['message'] ?? __( 'WordPress core scan completed.', 'k-integrity-guard' ),
			);
		}

		if ( 'error' === $status ) {
			return array(
				'complete' => true,
				'error'    => true,
				'message'  => $state['error_message'] ?? __( 'Unable to scan WordPress core.', 'k-integrity-guard' ),
			);
		}

		return array(
			'complete' => true,
			'message'  => '',
		);
	}

	/**
	 * Prepare the target state for scanning WordPress core.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return true|WP_Error
	 */
	private function prepare_core_target( array &$state ) {
		$version = get_bloginfo( 'version' );
		$locale  = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		$state['version'] = $version;
		$state['locale']  = $locale;

		$include_themes = ! empty( $state['include_core_themes'] );

		$checksums = $this->fetch_core_checksums( $version, $locale );

		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$state['locale'] = $checksums['locale'];

		$allowed_theme_dirs = array();
		$filtered           = $this->filter_core_checksums( $checksums['checksums'], $include_themes, $allowed_theme_dirs );
		$files              = array_keys( $filtered );

		$state['files']     = $files;
		$state['total']     = count( $files );
		$state['processed'] = 0;
		$state['pointer']   = 0;
		$state['modified']  = array();
		$state['missing']   = array();
		$state['added']     = array();
		$state['errors']    = array();

		if ( $include_themes ) {
			$state['allowed_theme_dirs'] = $allowed_theme_dirs;
		} else {
			unset( $state['allowed_theme_dirs'] );
		}

		$state['actual_files'] = $this->list_core_files( $allowed_theme_dirs );

		return true;
	}

	/**
	 * Fetch WordPress core checksums from WordPress.org with locale fallback, cached for a day per version and locale.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Preferred locale.
	 * @return array{checksums:array<string,string>,locale:string}|WP_Error
	 */
	private function fetch_core_checksums( string $version, string $locale ) {
		$candidates = array_unique( array( $locale, 'en_US' ) );
		$last_error = null;

		foreach ( $candidates as $candidate ) {
			$cache_key = 'kig_core_checksums_' . md5( $version . '|' . $candidate );
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$url      = add_query_arg(
				array(
					'version' => rawurlencode( $version ),
					'locale'  => rawurlencode( $candidate ),
				),
				'https://api.wordpress.org/core/checksums/1.0/'
			);
			$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				$last_error = new WP_Error(
					'kig_http_error',
					sprintf(
						/* translators: %s: HTTP status code. */
						__( 'Unexpected response while fetching core checksums (%s).', 'k-integrity-guard' ),
						$code
					)
				);
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				$last_error = new WP_Error( 'kig_invalid_response', __( 'Unable to parse checksum response.', 'k-integrity-guard' ) );
				continue;
			}

			if ( empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
				if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$last_error = new WP_Error( 'kig_remote_error', $data['error'] );
					continue;
				}

				$last_error = new WP_Error( 'kig_missing_checksums', __( 'Checksum data was not provided by WordPress.org.', 'k-integrity-guard' ) );
				continue;
			}

			$result = array(
				'checksums' => $data['checksums'],
				'locale'    => isset( $data['locale'] ) ? (string) $data['locale'] : $candidate,
			);

			set_transient( $cache_key, $result, DAY_IN_SECONDS );

			return $result;
		}

		return $last_error ? $last_error : new WP_Error( 'kig_checksum_unavailable', __( 'Unable to download WordPress core checksums.', 'k-integrity-guard' ) );
	}

	/**
	 * Filter checksum map to exclude user controlled files.
	 *
	 * Only the themes folder index file and the bundled theme folders that exist on disk are kept,
	 * never the whole themes folder, so other themes are not reported as unexpected files.
	 *
	 * @param array<string,string> $checksums          Raw checksum map.
	 * @param bool                 $include_themes     Whether to retain default theme checksums.
	 * @param array<int,string>    $allowed_theme_dirs Populated with allowed theme paths when themes are included. Passed by reference.
	 * @return array<string,string>
	 */
	private function filter_core_checksums( array $checksums, bool $include_themes = false, array &$allowed_theme_dirs = array() ): array {
		$filtered   = array();
		$theme_dirs = array();
		$root       = trailingslashit( ABSPATH );

		foreach ( $checksums as $path => $hash ) {
			$normalized = ltrim( wp_normalize_path( (string) $path ), '/' );

			if ( 0 === strpos( $normalized, 'wp-content/themes/' ) ) {
				if ( ! $include_themes ) {
					continue;
				}

				if ( 'wp-content/themes/index.php' === $normalized ) {
					$theme_dirs[ $normalized ] = true;
				} else {
					$theme_dir = $this->resolve_core_theme_directory( $normalized );

					if ( null === $theme_dir ) {
						continue;
					}

					// A bundled theme the admin deleted must not be reported as missing.
					if ( ! isset( $theme_dirs[ $theme_dir ] ) ) {
						$theme_dirs[ $theme_dir ] = is_dir( $root . $theme_dir );
					}

					if ( ! $theme_dirs[ $theme_dir ] ) {
						continue;
					}
				}

				$filtered[ $normalized ] = (string) $hash;
				continue;
			}

			if ( $this->is_core_path_excluded( $normalized ) ) {
				continue;
			}

			$filtered[ $normalized ] = (string) $hash;
		}

		$allowed_theme_dirs = array_keys( array_filter( $theme_dirs ) );

		return $filtered;
	}

	/**
	 * Resolve the bundled theme directory (wp-content/themes/<slug>) for a checksum path.
	 *
	 * @param string $path Normalized checksum path.
	 * @return string|null Null when the path is not inside a theme folder.
	 */
	private function resolve_core_theme_directory( string $path ): ?string {
		$parts = explode( '/', ltrim( wp_normalize_path( $path ), '/' ) );

		if ( count( $parts ) < 4 || 'wp-content' !== $parts[0] || 'themes' !== $parts[1] || '' === $parts[2] ) {
			return null;
		}

		return 'wp-content/themes/' . $parts[2];
	}

	/**
	 * Determine whether a path belongs to an allowed default theme directory.
	 *
	 * @param string        $path               Relative path from ABSPATH.
	 * @param array<int,string> $allowed_theme_dirs Allowed theme directory prefixes.
	 * @return bool
	 */
	private function is_allowed_theme_path( string $path, array $allowed_theme_dirs ): bool {
		if ( empty( $allowed_theme_dirs ) ) {
			return false;
		}

		$normalized = ltrim( wp_normalize_path( $path ), '/' );

		foreach ( $allowed_theme_dirs as $allowed ) {
			$allowed = ltrim( wp_normalize_path( $allowed ), '/' );

			if ( $normalized === $allowed ) {
				return true;
			}

			$allowed_with_slash   = $allowed . '/';
			$normalized_with_slash = $normalized . '/';

			if ( 0 === strpos( $normalized, $allowed_with_slash ) ) {
				return true;
			}

			if ( 0 === strpos( $allowed_with_slash, $normalized_with_slash ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process a chunk of core files.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function process_core_chunk( array &$state ): array {
		$total   = (int) ( $state['total'] ?? 0 );
		$pointer = (int) ( $state['pointer'] ?? 0 );
		$files   = $state['files'] ?? array();

		if ( empty( $files ) || 0 === $total ) {
			return array(
				'finished' => true,
				'message'  => __( 'No core files were available for scanning.', 'k-integrity-guard' ),
			);
		}

		// Checksums live in a cache transient, not in job state, to keep the job state small.
		$checksums = $this->fetch_core_checksums( (string) ( $state['version'] ?? '' ), (string) ( $state['locale'] ?? '' ) );

		if ( is_wp_error( $checksums ) ) {
			return array( 'error' => $checksums->get_error_message() );
		}

		$checks = $checksums['checksums'];

		$chunk_size = self::CORE_CHUNK_SIZE;
		$root       = trailingslashit( ABSPATH );
		$processed  = 0;

		while ( $processed < $chunk_size && $pointer < $total ) {
			$file     = $files[ $pointer ];
			$expected = $checks[ $file ] ?? '';
			$full     = $root . $file;

			if ( ! file_exists( $full ) ) {
				$state['missing'][] = $file;
			} elseif ( ! is_readable( $full ) ) {
				$state['errors'][]  = array(
					'file'    => $file,
					'message' => __( 'File could not be read.', 'k-integrity-guard' ),
				);
				$state['modified'][] = array(
					'file'     => $file,
					'expected' => $expected,
					'actual'   => '',
				);
			} else {
				$actual = md5_file( $full );

				if ( ! $actual || strtolower( $actual ) !== strtolower( $expected ) ) {
					$state['modified'][] = array(
						'file'     => $file,
						'expected' => $expected,
						'actual'   => $actual ? (string) $actual : '',
					);
				}
			}

			$pointer++;
			$state['processed']++;
			$processed++;
		}

		$state['pointer'] = $pointer;

		$finished = $pointer >= $total;

		$message = sprintf(
			/* translators: 1: processed count, 2: total count. */
			__( 'Scanning WordPress core files (%1$d of %2$d)…', 'k-integrity-guard' ),
			(int) $state['processed'],
			$total
		);

		return array(
			'finished' => $finished,
			'message'  => $message,
		);
	}

	/**
	 * Finish processing WordPress core scan state.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 */
	private function finalize_core_target( array &$state ): void {
		$expected = $state['files'] ?? array();
		$actual   = $state['actual_files'] ?? array();

		if ( ! empty( $actual ) ) {
			$expected_map = array_fill_keys( $expected, true );

			foreach ( $actual as $path ) {
				if ( isset( $expected_map[ $path ] ) ) {
					continue;
				}

				$state['added'][] = $path;
			}
		}

		if ( ! empty( $state['missing'] ) ) {
			$state['missing'] = array_values( array_unique( $state['missing'] ) );
		}

		if ( ! empty( $state['added'] ) ) {
			$state['added'] = array_values( array_unique( $state['added'] ) );
		}

		$state['modified'] = array_values( $state['modified'] );

		unset( $state['files'], $state['actual_files'], $state['pointer'], $state['allowed_theme_dirs'] );
	}

	/**
	 * Generate a human-readable summary for the core scan.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return string
	 */
	private function build_core_summary_message( array $state ): string {
		$modified = count( $state['modified'] ?? array() );
		$missing  = count( $state['missing'] ?? array() );
		$added    = count( $state['added'] ?? array() );
		$includes_themes = ! empty( $state['include_core_themes'] );

		if ( 0 === $modified && 0 === $missing && 0 === $added ) {
			if ( $includes_themes ) {
				return __( 'WordPress core and bundled themes scan completed without integrity issues.', 'k-integrity-guard' );
			}

			return __( 'WordPress core scan completed without integrity issues.', 'k-integrity-guard' );
		}

		if ( $includes_themes ) {
			return sprintf(
				/* translators: 1: modified count, 2: missing count, 3: added count. */
				__( 'WordPress core and bundled themes issues detected — %1$d modified, %2$d missing, %3$d unexpected files.', 'k-integrity-guard' ),
				$modified,
				$missing,
				$added
			);
		}

		return sprintf(
			/* translators: 1: modified count, 2: missing count, 3: added count. */
			__( 'WordPress core issues detected — %1$d modified, %2$d missing, %3$d unexpected files.', 'k-integrity-guard' ),
			$modified,
			$missing,
			$added
		);
	}

	/**
	 * Build summarized core result payload.
	 *
	 * Keeps the flat fields the live scan screen reads and adds the items/totals shape used by history.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return array<string,mixed>
	 */
	private function summarize_core_result( array $state ): array {
		$modified = $state['modified'] ?? array();
		$missing  = $state['missing'] ?? array();
		$added    = $state['added'] ?? array();
		$errors   = $state['errors'] ?? array();
		$failed   = 'error' === ( $state['status'] ?? '' );
		$issues   = ! empty( $modified ) || ! empty( $missing ) || ! empty( $added );
		$summary  = $failed
			? (string) ( $state['error_message'] ?? __( 'Unable to scan WordPress core.', 'k-integrity-guard' ) )
			: $this->build_core_summary_message( $state );

		$item_status = 'ok';
		if ( $failed ) {
			$item_status = 'error';
		} elseif ( $issues ) {
			$item_status = 'issues';
		}

		return array(
			'status'          => $state['status'] ?? 'unknown',
			'version'         => $state['version'] ?? '',
			'locale'          => $state['locale'] ?? '',
			'themes_included' => ! empty( $state['include_core_themes'] ),
			'modified'        => $modified,
			'missing'         => $missing,
			'added'           => $added,
			'errors'          => $errors,
			'summary'         => $summary,
			'items'           => array(
				array(
					'key'      => 'core',
					'name'     => __( 'WordPress Core', 'k-integrity-guard' ),
					'version'  => $state['version'] ?? '',
					'status'   => $item_status,
					'message'  => $summary,
					'modified' => $modified,
					'missing'  => $missing,
					'added'    => $added,
					'errors'   => $errors,
				),
			),
			'skipped'         => array(),
			'totals'          => array(
				'verified' => $failed ? 0 : 1,
				'issues'   => $issues ? 1 : 0,
				'skipped'  => 0,
				'errors'   => $failed ? 1 : 0,
			),
		);
	}

	/**
	 * Configuration of the extension targets (plugins and themes).
	 *
	 * Each target names the method that builds its item list, the method that verifies one item,
	 * its label and the message shown when nothing could be verified.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function get_extension_targets(): array {
		$labels = $this->get_targets();

		return array(
			'plugins'             => array(
				'label'   => $labels['plugins'],
				'prepare' => 'prepare_wporg_plugins',
				'verify'  => 'verify_wporg_plugin',
				'empty'   => __( 'No WordPress.org plugins were available for verification.', 'k-integrity-guard' ),
			),
			'themes'              => array(
				'label'   => $labels['themes'],
				'prepare' => 'prepare_wporg_themes',
				'verify'  => 'verify_wporg_theme',
				'empty'   => __( 'No WordPress.org themes were available for verification.', 'k-integrity-guard' ),
			),
			'third_party_plugins' => array(
				'label'   => $labels['third_party_plugins'],
				'prepare' => 'prepare_third_party_plugins',
				'verify'  => 'verify_third_party_plugin',
				'empty'   => __( 'No third-party plugins with snapshots were available for verification.', 'k-integrity-guard' ),
			),
			'third_party_themes'  => array(
				'label'   => $labels['third_party_themes'],
				'prepare' => 'prepare_third_party_themes',
				'verify'  => 'verify_third_party_theme',
				'empty'   => __( 'No third-party themes with snapshots were available for verification.', 'k-integrity-guard' ),
			),
		);
	}

	/**
	 * Advance an extension target by one step: prepare the item list, then verify one item per step.
	 *
	 * @param array<string,mixed>  $state  Target state (passed by reference).
	 * @param array<string,string> $config Target configuration from get_extension_targets().
	 * @return array<string,mixed>
	 */
	private function advance_extension_target( array &$state, array $config ): array {
		$status = $state['status'] ?? 'pending';

		if ( 'pending' === $status ) {
			$prepared = $this->{$config['prepare']}();
			$items    = array();

			foreach ( $prepared['items'] as $key => $item ) {
				$items[ $key ] = array_merge(
					$item,
					array(
						'key'      => (string) $key,
						'status'   => 'pending',
						'message'  => '',
						'modified' => array(),
						'missing'  => array(),
						'added'    => array(),
						'errors'   => array(),
					)
				);
			}

			$state['items']       = $items;
			$state['order']       = array_keys( $items );
			$state['skipped']     = $prepared['skipped'];
			$state['errors']      = array();
			$state['processed']   = 0;
			$state['total']       = count( $items );
			$state['issue_total'] = 0;

			if ( 0 === $state['total'] ) {
				$state['status']  = 'completed';
				$state['message'] = $config['empty'];

				return array(
					'complete' => true,
					'error'    => false,
					'message'  => $state['message'],
				);
			}

			$state['status']  = 'processing';
			$state['message'] = sprintf(
				/* translators: %s: Scan target label, e.g. "Plugins". */
				__( 'Preparing verification: %s…', 'k-integrity-guard' ),
				$config['label']
			);

			return array(
				'complete' => false,
				'error'    => false,
				'message'  => $state['message'],
			);
		}

		if ( 'processing' !== $status ) {
			return array(
				'complete' => true,
				'error'    => 'error' === $status,
				'message'  => $state['message'] ?? '',
			);
		}

		$index = (int) $state['processed'];

		if ( $index >= (int) $state['total'] ) {
			$state['status']  = 'completed';
			$state['message'] = $this->build_extension_summary_message( $state, $config );

			return array(
				'complete' => true,
				'error'    => ! empty( $state['errors'] ),
				'message'  => $state['message'],
			);
		}

		$key  = $state['order'][ $index ];
		$item = &$state['items'][ $key ];
		$state['processed']++;

		$result = $this->{$config['verify']}( $item );

		if ( is_wp_error( $result ) ) {
			$item['status']    = 'error';
			$item['message']   = $result->get_error_message();
			$state['errors'][] = array(
				'key'     => (string) $key,
				'name'    => $item['name'],
				'message' => $item['message'],
			);

			$message = sprintf(
				/* translators: %s: Plugin or theme name. */
				__( 'Verification failed for %s.', 'k-integrity-guard' ),
				$item['name']
			);

			unset( $item );

			return array(
				'complete' => false,
				'error'    => true,
				'message'  => $message,
			);
		}

		$note       = $result['note'] ?? '';
		$has_issues = '' !== $note || ! empty( $result['modified'] ) || ! empty( $result['missing'] ) || ! empty( $result['added'] );

		$item['modified'] = $result['modified'];
		$item['missing']  = $result['missing'];
		$item['added']    = $result['added'];
		$item['errors']   = $result['errors'];
		$item['status']   = $has_issues ? 'issues' : 'ok';
		$item['message']  = $has_issues
			/* translators: %s: Plugin or theme name. */
			? sprintf( __( 'Differences detected in %s.', 'k-integrity-guard' ), $item['name'] )
			/* translators: %s: Plugin or theme name. */
			: sprintf( __( 'No differences detected in %s.', 'k-integrity-guard' ), $item['name'] );

		if ( '' !== $note ) {
			$item['message'] .= ' ' . $note;
		}

		if ( $has_issues ) {
			$state['issue_total']++;
		}

		$message = $item['message'];
		unset( $item );

		return array(
			'complete' => false,
			'error'    => false,
			'message'  => $message,
		);
	}

	/**
	 * Summarize an extension target for the scan screen and history.
	 *
	 * @param array<string,mixed>  $state  Target state.
	 * @param array<string,string> $config Target configuration.
	 * @return array<string,mixed>
	 */
	private function summarize_extension_result( array $state, array $config ): array {
		$items = array();

		foreach ( $state['items'] ?? array() as $key => $data ) {
			$items[] = array(
				'key'      => (string) $key,
				'name'     => $data['name'] ?? (string) $key,
				'version'  => $data['version'] ?? '',
				'status'   => $data['status'] ?? 'unknown',
				'message'  => $data['message'] ?? '',
				'modified' => $data['modified'] ?? array(),
				'missing'  => $data['missing'] ?? array(),
				'added'    => $data['added'] ?? array(),
				'errors'   => $data['errors'] ?? array(),
			);
		}

		$skipped = $state['skipped'] ?? array();
		$errors  = $state['errors'] ?? array();

		return array(
			'status'  => $state['status'] ?? 'unknown',
			'summary' => $this->build_extension_summary_message( $state, $config ),
			'items'   => $items,
			'skipped' => $skipped,
			'errors'  => $errors,
			'totals'  => array(
				'verified' => (int) ( $state['total'] ?? count( $items ) ),
				'issues'   => (int) ( $state['issue_total'] ?? 0 ),
				'skipped'  => count( $skipped ),
				'errors'   => count( $errors ),
			),
		);
	}

	/**
	 * Build the summary message for an extension target.
	 *
	 * @param array<string,mixed>  $state  Target state.
	 * @param array<string,string> $config Target configuration.
	 * @return string
	 */
	private function build_extension_summary_message( array $state, array $config ): string {
		$total   = (int) ( $state['total'] ?? 0 );
		$issues  = (int) ( $state['issue_total'] ?? 0 );
		$skipped = count( $state['skipped'] ?? array() );
		$errors  = count( $state['errors'] ?? array() );

		if ( 0 === $total ) {
			return $config['empty'];
		}

		if ( $issues > 0 ) {
			$message = sprintf(
				/* translators: 1: Scan target label, 2: items with differences, 3: total items. */
				__( '%1$s: differences detected in %2$d of %3$d item(s).', 'k-integrity-guard' ),
				$config['label'],
				$issues,
				$total
			);
		} else {
			$message = sprintf(
				/* translators: 1: Scan target label, 2: number of verified items. */
				__( '%1$s: no differences detected in %2$d verified item(s).', 'k-integrity-guard' ),
				$config['label'],
				$total - $errors
			);
		}

		if ( $errors > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of items. */
				__( '%d item(s) could not be verified.', 'k-integrity-guard' ),
				$errors
			);
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of items. */
				__( '%d item(s) were skipped.', 'k-integrity-guard' ),
				$skipped
			);
		}

		return $message;
	}

	/**
	 * Build a skipped-item entry.
	 *
	 * @param string $key    Plugin file or theme stylesheet.
	 * @param string $name   Display name.
	 * @param string $reason Why it was skipped.
	 * @return array<string,string>
	 */
	private function skipped_entry( string $key, string $name, string $reason ): array {
		return array(
			'key'    => $key,
			'name'   => $name,
			'reason' => $reason,
		);
	}

	/**
	 * List WordPress.org plugins to verify against the official checksums.
	 *
	 * @return array{items:array<string,array<string,string>>,skipped:array<int,array<string,string>>}
	 */
	private function prepare_wporg_plugins(): array {
		$items   = array();
		$skipped = array();

		foreach ( KIG_Utils::detect_plugin_sources() as $file => $info ) {
			$name    = $this->sanitize_extension_label( (string) ( $info['name'] ?? $file ) );
			$version = (string) ( $info['version'] ?? '' );
			$slug    = (string) ( $info['slug'] ?? '' );

			if ( empty( $info['is_wporg'] ) ) {
				$skipped[] = $this->skipped_entry( $file, $name, __( 'Not available on WordPress.org.', 'k-integrity-guard' ) );
			} elseif ( '' === $slug ) {
				$skipped[] = $this->skipped_entry( $file, $name, __( 'Plugin slug could not be determined.', 'k-integrity-guard' ) );
			} elseif ( '' === $version ) {
				$skipped[] = $this->skipped_entry( $file, $name, __( 'Version could not be determined.', 'k-integrity-guard' ) );
			} else {
				$items[ $file ] = array(
					'name'    => $name,
					'version' => $version,
					'slug'    => $slug,
				);
			}
		}

		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * List WordPress.org themes to verify against the official checksums.
	 *
	 * @return array{items:array<string,array<string,string>>,skipped:array<int,array<string,string>>}
	 */
	private function prepare_wporg_themes(): array {
		$items   = array();
		$skipped = array();

		foreach ( KIG_Utils::detect_theme_sources() as $stylesheet => $info ) {
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				continue;
			}

			$name    = $this->sanitize_extension_label( (string) $theme->get( 'Name' ) );
			$version = (string) $theme->get( 'Version' );

			if ( $theme->get( 'Template' ) ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'Child theme - inherits from parent.', 'k-integrity-guard' ) );
			} elseif ( in_array( $stylesheet, self::WP_DEFAULT_THEMES, true ) ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'Default WordPress theme - verified with core scan.', 'k-integrity-guard' ) );
			} elseif ( empty( $info['is_wporg'] ) ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'Not available on WordPress.org.', 'k-integrity-guard' ) );
			} elseif ( '' === $version ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'Version could not be determined.', 'k-integrity-guard' ) );
			} else {
				$items[ $stylesheet ] = array(
					'name'    => $name,
					'version' => $version,
				);
			}
		}

		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * List third-party plugins to verify against their local snapshots.
	 *
	 * @return array{items:array<string,array<string,string>>,skipped:array<int,array<string,string>>}
	 */
	private function prepare_third_party_plugins(): array {
		$options = $this->settings->get_options();
		$ignores = $options['third_party']['ignore'] ?? array();
		$items   = array();
		$skipped = array();

		foreach ( KIG_Utils::detect_plugin_sources() as $file => $info ) {
			if ( ! empty( $info['is_wporg'] ) ) {
				continue;
			}

			$name = $this->sanitize_extension_label( (string) ( $info['name'] ?? $file ) );

			if ( ! empty( $ignores[ $file ] ) ) {
				$skipped[] = $this->skipped_entry( $file, $name, __( 'Ignored by user.', 'k-integrity-guard' ) );
			} elseif ( null === KIG_Utils::read_plugin_checksum_json( $file ) ) {
				$skipped[] = $this->skipped_entry( $file, $name, __( 'No snapshot available. Generate one in Settings.', 'k-integrity-guard' ) );
			} else {
				$items[ $file ] = array(
					'name'    => $name,
					'version' => (string) ( $info['version'] ?? '' ),
				);
			}
		}

		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * List third-party themes to verify against their local snapshots.
	 *
	 * @return array{items:array<string,array<string,string>>,skipped:array<int,array<string,string>>}
	 */
	private function prepare_third_party_themes(): array {
		$options = $this->settings->get_options();
		$ignores = $options['third_party_themes']['ignore'] ?? array();
		$items   = array();
		$skipped = array();

		foreach ( KIG_Utils::detect_theme_sources() as $stylesheet => $info ) {
			if ( ! empty( $info['is_wporg'] ) ) {
				continue;
			}

			$name = $this->sanitize_extension_label( (string) ( $info['name'] ?? $stylesheet ) );

			if ( ! empty( $ignores[ $stylesheet ] ) ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'Ignored by user.', 'k-integrity-guard' ) );
			} elseif ( null === KIG_Utils::read_theme_checksum_json( $stylesheet ) ) {
				$skipped[] = $this->skipped_entry( $stylesheet, $name, __( 'No snapshot available. Generate one in Settings.', 'k-integrity-guard' ) );
			} else {
				$items[ $stylesheet ] = array(
					'name'    => $name,
					'version' => (string) ( $info['version'] ?? '' ),
				);
			}
		}

		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Verify a WordPress.org plugin against the official checksums.
	 *
	 * @param array<string,mixed> $item Item with key (plugin file), slug and version.
	 * @return array<string,mixed>|WP_Error Comparison result.
	 */
	private function verify_wporg_plugin( array $item ) {
		$checksums = $this->fetch_plugin_checksums( $item['slug'], $item['version'] );
		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$prefixes  = array( $item['slug'] );
		$directory = dirname( $item['key'] );
		if ( '.' !== $directory && '' !== $directory ) {
			$prefixes[] = $directory;
		}

		$map = $this->normalize_checksum_map( $checksums['checksums'], $prefixes );
		if ( empty( $map ) ) {
			return new WP_Error( 'kig_missing_plugin_checksums', __( 'Plugin checksums were not provided.', 'k-integrity-guard' ) );
		}

		$root_info = $this->determine_plugin_root( $item['key'], $item['slug'] );

		return $this->compare_extension_files( $root_info['root'], $map, $checksums['checksum_type'], $root_info['detect_added'] );
	}

	/**
	 * Verify a WordPress.org theme against the official checksums.
	 *
	 * @param array<string,mixed> $item Item with key (stylesheet) and version.
	 * @return array<string,mixed>|WP_Error Comparison result.
	 */
	private function verify_wporg_theme( array $item ) {
		$root = wp_get_theme( $item['key'] )->get_stylesheet_directory();
		if ( ! $root || ! is_dir( $root ) ) {
			return new WP_Error( 'kig_theme_path', __( 'Theme directory could not be located.', 'k-integrity-guard' ) );
		}

		$checksums = $this->fetch_theme_checksums( $item['key'], $item['version'] );
		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$map = $this->normalize_checksum_map( $checksums['checksums'], array( $item['key'] ) );
		if ( empty( $map ) ) {
			return new WP_Error( 'kig_missing_theme_checksums', __( 'Theme checksums were not provided.', 'k-integrity-guard' ) );
		}

		return $this->compare_extension_files( $root, $map, $checksums['checksum_type'], true );
	}

	/**
	 * Verify a third-party plugin against its local snapshot.
	 *
	 * @param array<string,mixed> $item Item with key (plugin file) and version.
	 * @return array<string,mixed>|WP_Error Comparison result.
	 */
	private function verify_third_party_plugin( array $item ) {
		$base     = dirname( $item['key'] );
		$snapshot = KIG_Utils::read_plugin_checksum_json( $item['key'] );

		if ( '.' === $base || '' === $base ) {
			// Single-file plugin: check only its own file. Older snapshots hashed the whole plugins folder, so filter them too.
			if ( is_array( $snapshot ) && is_array( $snapshot['files'] ?? null ) ) {
				$snapshot['files'] = array_intersect_key( $snapshot['files'], array( $item['key'] => true ) );
			}

			return $this->compare_with_snapshot( $snapshot, WP_PLUGIN_DIR, $item['version'], false );
		}

		$root = trailingslashit( WP_PLUGIN_DIR ) . $base;
		if ( ! is_dir( $root ) ) {
			return new WP_Error( 'kig_plugin_path', __( 'Plugin directory could not be located.', 'k-integrity-guard' ) );
		}

		return $this->compare_with_snapshot( $snapshot, $root, $item['version'] );
	}

	/**
	 * Verify a third-party theme against its local snapshot.
	 *
	 * @param array<string,mixed> $item Item with key (stylesheet) and version.
	 * @return array<string,mixed>|WP_Error Comparison result.
	 */
	private function verify_third_party_theme( array $item ) {
		$root = wp_get_theme( $item['key'] )->get_stylesheet_directory();
		if ( ! $root || ! is_dir( $root ) ) {
			return new WP_Error( 'kig_theme_path', __( 'Theme directory could not be located.', 'k-integrity-guard' ) );
		}

		return $this->compare_with_snapshot( KIG_Utils::read_theme_checksum_json( $item['key'] ), $root, $item['version'] );
	}

	/**
	 * Compare files against a local snapshot.
	 *
	 * A version change since the snapshot is always reported, so bumping the Version header cannot hide changes.
	 *
	 * @param array<string,mixed>|null $snapshot Snapshot data (version, algorithm, files).
	 * @param string                   $root     Directory to compare.
	 * @param string                   $version  Installed version.
	 * @param bool                     $detect_added Whether to report files not in the snapshot.
	 * @return array<string,mixed>|WP_Error Comparison result, with a 'note' when the version changed.
	 */
	private function compare_with_snapshot( $snapshot, string $root, string $version, bool $detect_added = true ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['files'] ) || ! is_array( $snapshot['files'] ) ) {
			return new WP_Error( 'kig_no_checksum', __( 'No checksum snapshot available.', 'k-integrity-guard' ) );
		}

		$result           = $this->compare_extension_files( $root, $snapshot['files'], (string) ( $snapshot['algorithm'] ?? 'md5' ), $detect_added );
		$snapshot_version = (string) ( $snapshot['version'] ?? '' );

		if ( $snapshot_version !== $version ) {
			$result['note'] = sprintf(
				/* translators: 1: snapshot version, 2: installed version. */
				__( 'Version changed since snapshot (%1$s to %2$s).', 'k-integrity-guard' ),
				$snapshot_version,
				$version
			);
		}

		return $result;
	}

	/**
	 * Retrieve plugin checksums from downloads.wordpress.org.
	 *
	 * @param string $slug Plugin slug.
	 * @param string $version Plugin version.
	 * @return array<string,mixed>|WP_Error
	 */
	private function fetch_plugin_checksums( string $slug, string $version ) {
		$url     = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%1$s/%2$s.json', rawurlencode( $slug ), rawurlencode( $version ) );
		$request = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'K\'Integrity Guard/' . $this->version,
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			if ( 404 === $code ) {
				return new WP_Error( 'kig_plugin_checksum_missing', __( 'Plugin checksums were not provided by WordPress.org.', 'k-integrity-guard' ) );
			}

			return new WP_Error( 'kig_plugin_checksum_http', __( 'Unable to download plugin checksums.', 'k-integrity-guard' ) );
		}

		$body = wp_remote_retrieve_body( $request );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'kig_plugin_checksum_json', __( 'Unexpected plugin checksum response.', 'k-integrity-guard' ) );
		}

		if ( empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return new WP_Error( 'kig_plugin_checksum_missing', __( 'Plugin checksums were not provided by WordPress.org.', 'k-integrity-guard' ) );
		}

		return array(
			'checksums'     => $data['files'],
			'checksum_type' => 'md5',
		);
	}

	/**
	 * Retrieve theme checksums from WordPress.org.
	 *
	 * @param string $slug    Theme slug (stylesheet).
	 * @param string $version Theme version.
	 * @return array<string,mixed>|WP_Error
	 */
	private function fetch_theme_checksums( string $slug, string $version ) {
		if ( '' === $slug || '' === $version ) {
			return new WP_Error( 'kig_theme_checksum_args', __( 'Invalid theme checksum request.', 'k-integrity-guard' ) );
		}

		$url = sprintf( 'https://api.wordpress.org/themes/checksums/1.0/%1$s/%2$s', rawurlencode( $slug ), rawurlencode( $version ) );
		$url = add_query_arg(
			array(
				'locale' => $this->determine_request_locale(),
			),
			$url
		);

		$request = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'user-agent' => 'K\'Integrity Guard/' . $this->version,
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			return new WP_Error( 'kig_theme_checksum_http', __( 'Unable to download theme checksums.', 'k-integrity-guard' ) );
		}

		$body = wp_remote_retrieve_body( $request );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'kig_theme_checksum_json', __( 'Unexpected theme checksum response.', 'k-integrity-guard' ) );
		}

		if ( empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return new WP_Error( 'kig_theme_checksum_error', $data['error'] );
			}

			return new WP_Error( 'kig_theme_checksum_missing', __( 'Theme checksums were not provided by WordPress.org.', 'k-integrity-guard' ) );
		}

		$type = isset( $data['checksum_type'] ) ? strtolower( (string) $data['checksum_type'] ) : 'md5';
		if ( ! in_array( $type, array( 'md5', 'sha256' ), true ) ) {
			$type = 'md5';
		}

		return array(
			'checksums'      => $data['checksums'],
			'checksum_type'  => $type,
		);
	}

	/**
	 * Normalize checksum map keys to relative paths.
	 *
	 * @param array<mixed> $checksums Raw checksum map.
	 * @param array<int,string> $prefixes Prefixes to strip from paths.
	 * @return array<string,string>
	 */
	private function normalize_checksum_map( array $checksums, array $prefixes = array() ): array {
		$normalized = array();

		foreach ( $checksums as $path => $hash ) {
			$relative = ltrim( wp_normalize_path( (string) $path ), '/' );

			foreach ( $prefixes as $prefix ) {
				$prefix = trim( wp_normalize_path( (string) $prefix ), '/' );
				if ( '' === $prefix ) {
					continue;
				}

				if ( $relative === $prefix ) {
					$relative = basename( $relative );
					break;
				}

				if ( 0 === strpos( $relative, $prefix . '/' ) ) {
					$relative = substr( $relative, strlen( $prefix ) + 1 );
					break;
				}
			}

			$normalized[ $relative ] = (string) $hash;
		}

		return $normalized;
	}

	/**
	 * Compare installed files with expected checksums.
	 *
	 * @param string $root          Root directory path.
	 * @param array<string,string> $checksums Normalized checksum map.
	 * @param string $algorithm    Checksum algorithm (md5|sha256).
	 * @param bool   $detect_added Whether to detect unexpected files.
	 * @return array<string,mixed>
	 */
	private function compare_extension_files( string $root, array $checksums, string $algorithm = 'md5', bool $detect_added = true ): array {
		$root        = wp_normalize_path( untrailingslashit( $root ) );
		$modified    = array();
		$missing     = array();
		$errors      = array();
		$added       = array();
		$algorithm   = in_array( strtolower( $algorithm ), array( 'md5', 'sha256' ), true ) ? strtolower( $algorithm ) : 'md5';

		foreach ( $checksums as $relative => $expected ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			$expected = (string) $expected;
			$full     = $root . '/' . $relative;

			if ( ! file_exists( $full ) ) {
				$missing[] = $relative;
				continue;
			}

			if ( ! is_readable( $full ) ) {
				$errors[] = array(
					'file'    => $relative,
					'message' => __( 'File could not be read.', 'k-integrity-guard' ),
				);
				$modified[] = array(
					'file'     => $relative,
					'expected' => $expected,
					'actual'   => '',
				);
				continue;
			}

			$actual = 'sha256' === $algorithm ? hash_file( 'sha256', $full ) : md5_file( $full );
			if ( ! $actual ) {
				$errors[] = array(
					'file'    => $relative,
					'message' => __( 'File could not be hashed.', 'k-integrity-guard' ),
				);
				$modified[] = array(
					'file'     => $relative,
					'expected' => $expected,
					'actual'   => '',
				);
				continue;
			}

			if ( strtolower( $actual ) !== strtolower( $expected ) ) {
				$modified[] = array(
					'file'     => $relative,
					'expected' => $expected,
					'actual'   => (string) $actual,
				);
			}
		}

		if ( $detect_added && is_dir( $root ) ) {
			$files = $this->list_directory_files( $root );
			if ( ! empty( $files ) ) {
				$expected_map = array_fill_keys( array_keys( $checksums ), true );
				foreach ( $files as $path ) {
					if ( isset( $expected_map[ $path ] ) ) {
						continue;
					}

					$added[] = $path;
				}
			}
		}

		return array(
			'modified' => array_values( $modified ),
			'missing'  => array_values( array_unique( $missing ) ),
			'added'    => array_values( array_unique( $added ) ),
			'errors'   => array_values( $errors ),
		);
	}

	/**
	 * List files relative to a root directory.
	 *
	 * @param string $root Directory path.
	 * @return array<int,string>
	 */
	private function list_directory_files( string $root ): array {
		$files = array();

		if ( ! is_dir( $root ) ) {
			return $files;
		}

		$root_path = wp_normalize_path( untrailingslashit( $root ) );
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root_path, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			if ( 0 !== strpos( $path, $root_path ) ) {
				continue;
			}

			$relative = ltrim( substr( $path, strlen( $root_path ) ), '/' );
			if ( '' !== $relative ) {
				$files[] = $relative;
			}
		}

		return $files;
	}

	/**
	 * Determine the local plugin root directory and diff strategy.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $slug        Plugin slug.
	 * @return array{root:string,detect_added:bool}
	 */
	private function determine_plugin_root( string $plugin_file, string $slug ): array {
		$base         = dirname( $plugin_file );
		$detect_added = true;
		$root         = WP_PLUGIN_DIR;

		if ( '.' !== $base && '' !== $base ) {
			$candidate = trailingslashit( WP_PLUGIN_DIR ) . $base;
			if ( is_dir( $candidate ) ) {
				$root = $candidate;
			} else {
				$root         = WP_PLUGIN_DIR;
				$detect_added = false;
			}
		} elseif ( '' !== $slug ) {
			$candidate = trailingslashit( WP_PLUGIN_DIR ) . $slug;
			if ( is_dir( $candidate ) ) {
				$root = $candidate;
			} else {
				$detect_added = false;
			}
		} else {
			$detect_added = false;
		}

		return array(
			'root'         => wp_normalize_path( untrailingslashit( $root ) ),
			'detect_added' => $detect_added,
		);
	}

	/**
	 * Sanitize extension labels for display.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	private function sanitize_extension_label( string $label ): string {
		$clean = trim( wp_strip_all_tags( $label ) );

		if ( '' === $clean ) {
			return __( 'Unknown', 'k-integrity-guard' );
		}

		return $clean;
	}

	/**
	 * Determine the locale to request from the WordPress.org APIs.
	 *
	 * @return string
	 */
	private function determine_request_locale(): string {
		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}

		return (string) get_locale();
	}

	/**
	 * Determine overall job progress percentage.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return int
	 */
	private function calculate_progress( array $job ): int {
		$total_targets = count( $job['targets'] );

		if ( 0 === $total_targets ) {
			return 100;
		}

		$progress = 0.0;

		foreach ( $job['targets'] as $index => $target ) {
			$state  = $job['target_states'][ $target ] ?? array();
			$status = $state['status'] ?? 'pending';

			if ( in_array( $status, array( 'completed', 'skipped', 'error' ), true ) ) {
				$progress += 1.0;
				continue;
			}

			if ( $index === (int) ( $job['current_target'] ?? 0 ) ) {
				$total     = max( 1, (int) ( $state['total'] ?? 0 ) );
				$processed = min( $total, (int) ( $state['processed'] ?? 0 ) );

				$progress += $total > 0 ? ( $processed / $total ) : 0.5;
			}
		}

		$percentage = (int) floor( ( $progress / $total_targets ) * 100 );

		return max( 0, min( 100, $percentage ) );
	}

	/**
	 * Persist scan results for later display.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return int Timestamp of completion.
	 */
	private function persist_scan_result( array $job ): int {
		$completed = current_time( 'timestamp' );
		$payload   = array(
			'completed_at' => $completed,
			'context'      => $job['context'] ?? 'manual',
			'targets'      => $job['targets'] ?? array(),
			'results'      => $job['results'] ?? array(),
			'errors'       => $job['errors'] ?? array(),
			'summary'      => $job['summary'] ?? '',
		);

		update_option( self::OPTION_LAST_RESULT, $payload, false );

		// Total issues is the number of affected files (modified + missing + added) across all items.
		$total_issues = 0;
		$has_errors   = false;
		$has_warnings = false;

		foreach ( $job['results'] ?? array() as $result ) {
			if ( 'error' === ( $result['status'] ?? '' ) || ! empty( $result['errors'] ) ) {
				$has_errors = true;
			}

			foreach ( $result['items'] ?? array() as $item ) {
				$total_issues += count( $item['modified'] ?? array() ) + count( $item['missing'] ?? array() ) + count( $item['added'] ?? array() );

				if ( 'error' === ( $item['status'] ?? '' ) ) {
					$has_errors = true;
				} elseif ( 'issues' === ( $item['status'] ?? '' ) ) {
					$has_warnings = true;
				}
			}
		}

		$status = 'success';
		if ( $has_errors ) {
			$status = 'error';
		} elseif ( $has_warnings || $total_issues > 0 ) {
			$status = 'warning';
		}

		// Save to database history.
		$this->db->insert_scan(
			array(
				'scan_date'    => current_time( 'mysql' ),
				'context'      => $job['context'] ?? 'manual',
				'targets'      => $job['targets'] ?? array(),
				'status'       => $status,
				'summary'      => $job['summary'] ?? '',
				'results'      => $job['results'] ?? array(),
				'total_issues' => $total_issues,
				'created_by'   => get_current_user_id(),
			)
		);

		return $completed;
	}

	/**
	 * Generate a single-string summary for the job.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return string
	 */
	private function build_summary( array $job ): string {
		$parts = array();

		foreach ( $job['results'] as $target => $result ) {
			if ( isset( $result['summary'] ) && is_string( $result['summary'] ) ) {
				$parts[] = $result['summary'];
			} elseif ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
				$parts[] = $result['message'];
			}
		}

		if ( empty( $parts ) ) {
			return __( 'Scan completed.', 'k-integrity-guard' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Return the last saved scan summary for UI display.
	 *
	 * @return array<string,mixed>
	 */
	private function get_last_scan_summary(): array {
		$data = get_option( self::OPTION_LAST_RESULT );

		if ( ! is_array( $data ) ) {
			return array();
		}

		return array(
			'completedAt' => isset( $data['completed_at'] ) ? (int) $data['completed_at'] : 0,
			'summary'     => isset( $data['summary'] ) ? (string) $data['summary'] : '',
		);
	}

	/**
	 * List installed core files for unexpected-file detection.
	 *
	 * Walks only root-level files of ABSPATH, wp-admin/, wp-includes/ and the allowed bundled theme folders.
	 * In the root only PHP-type files are listed: sites keep their own .htaccess, robots.txt, favicon etc. there,
	 * and only executable files are a tampering risk.
	 *
	 * @param array<int,string> $allowed_theme_dirs Allowed bundled theme paths relative to ABSPATH.
	 * @return array<int,string>
	 */
	private function list_core_files( array $allowed_theme_dirs = array() ): array {
		$files = array();
		$root  = wp_normalize_path( trailingslashit( ABSPATH ) );

		foreach ( new DirectoryIterator( $root ) as $entry ) {
			if ( $entry->isFile() && self::is_executable_name( $entry->getFilename() ) && ! $this->is_core_path_excluded( $entry->getFilename() ) ) {
				$files[] = $entry->getFilename();
			}
		}

		foreach ( array_merge( array( 'wp-admin', 'wp-includes' ), $allowed_theme_dirs ) as $dir ) {
			// Skips file entries such as wp-content/themes/index.php.
			if ( ! is_dir( $root . $dir ) ) {
				continue;
			}

			foreach ( $this->list_directory_files( $root . $dir ) as $relative ) {
				$files[] = $dir . '/' . $relative;
			}
		}

		return $files;
	}

	/**
	 * Whether a file name is one PHP may execute (.php, .php7, .phtml, .phar ...).
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_executable_name( string $name ): bool {
		return 1 === preg_match( '/\.(php\d*|phtml|phar|phps)$/i', $name );
	}

	/**
	 * Determine if a path should be excluded from core verification.
	 *
	 * @param string $path Relative path from ABSPATH.
	 * @return bool
	 */
	private function is_core_path_excluded( string $path, array $allowed_theme_dirs = array() ): bool {
		$normalized = ltrim( wp_normalize_path( $path ), '/' );
		$normalized = untrailingslashit( $normalized );

		foreach ( self::CORE_EXCLUDED_FILES as $file ) {
			if ( $normalized === $file ) {
				if ( ! empty( $allowed_theme_dirs ) && $this->is_allowed_theme_path( $normalized, $allowed_theme_dirs ) ) {
					continue;
				}

				return true;
			}
		}

		foreach ( self::CORE_EXCLUDED_PREFIXES as $prefix ) {
			$prefix = trim( wp_normalize_path( $prefix ), '/' );
			if ( '' === $prefix ) {
				continue;
			}

			$prefix = untrailingslashit( $prefix );

			if ( 0 === strpos( $normalized, $prefix ) ) {
				if ( 'wp-content/themes' === $prefix && ! empty( $allowed_theme_dirs ) ) {
					if ( $this->is_allowed_theme_path( $normalized, $allowed_theme_dirs ) ) {
						continue;
					}
				}

				$remainder = substr( $normalized, strlen( $prefix ) );
				if ( '' === $remainder || '/' === $remainder[0] ) {
					return true;
				}
			}
		}

		return false;
	}

}
