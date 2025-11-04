<?php
/**
 * Scan handling for WP Integrity Guard.
 *
 * @package WP_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPIG_Scan {

	const JOB_PREFIX = 'wpig_scan_job_';
	const OPTION_LAST_RESULT = 'wpig_last_scan_result';

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
	 * Text domain for translations.
	 *
	 * @var string
	 */
	private string $textdomain;

	/**
	 * Plugin version string.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Settings handler.
	 *
	 * @var WPIG_Settings
	 */
	private WPIG_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param string        $plugin_file Main plugin file path.
	 * @param string        $menu_slug   Scan menu slug.
	 * @param WPIG_Settings $settings    Settings instance.
	 * @param string        $textdomain  Text domain.
	 * @param string        $version     Plugin version.
	 */
	public function __construct( string $plugin_file, string $menu_slug, WPIG_Settings $settings, string $textdomain, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->menu_slug   = $menu_slug;
		$this->settings    = $settings;
		$this->textdomain  = $textdomain;
		$this->version     = $version;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpig_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'wp_ajax_wpig_scan_status', array( $this, 'handle_scan_status' ) );
		add_action( 'wpig_daily_scan', array( $this, 'run_scheduled_scan' ) );
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
					'start'  => wp_create_nonce( 'wpig_start_scan' ),
					'status' => wp_create_nonce( 'wpig_scan_status' ),
				),
				'defaults' => array(
					'targets' => $options['targets'],
				),
				'lastScan' => $this->get_last_scan_summary(),
				'text'     => array(
					'progressIdle'       => esc_html__( 'Waiting to start…', $this->textdomain ),
					'progressPreparing'  => esc_html__( 'Preparing scan…', $this->textdomain ),
					'progressFinalizing' => esc_html__( 'Wrapping up scan…', $this->textdomain ),
					'progressCompleted'  => esc_html__( 'Scan completed.', $this->textdomain ),
					'noIssues'           => esc_html__( 'No integrity issues detected.', $this->textdomain ),
					'issuesFound'        => esc_html__( 'Integrity issues detected.', $this->textdomain ),
					'errorMessage'       => esc_html__( 'Unable to continue the scan. Please try again.', $this->textdomain ),
					'lookAtSummary'      => esc_html__( 'Review the scan summary below for details.', $this->textdomain ),
					'viewLastSummary'    => esc_html__( 'Last scan summary:', $this->textdomain ),
					'lastRunLabel'       => esc_html__( 'Completed on %s', $this->textdomain ),
					'buttonStart'        => esc_html__( 'Start Scan', $this->textdomain ),
					'buttonWorking'      => esc_html__( 'Starting…', $this->textdomain ),
					'modifiedTitle'      => esc_html__( 'Modified files (%d)', $this->textdomain ),
					'missingTitle'       => esc_html__( 'Missing files (%d)', $this->textdomain ),
					'addedTitle'         => esc_html__( 'Unexpected files (%d)', $this->textdomain ),
					'coreHeading'        => esc_html__( 'WordPress Core', $this->textdomain ),
					'pluginsHeading'     => esc_html__( 'Plugins', $this->textdomain ),
					'themesHeading'      => esc_html__( 'Themes', $this->textdomain ),
					'skippedLabel'       => esc_html__( 'Skipped (%d)', $this->textdomain ),
					'errorsLabel'        => esc_html__( 'Errors (%d)', $this->textdomain ),
					'issuesDetected'     => esc_html__( 'Differences detected', $this->textdomain ),
					'noDifferences'      => esc_html__( 'No differences detected.', $this->textdomain ),
					'statusError'        => esc_html__( 'Verification failed.', $this->textdomain ),
					'versionLabel'       => esc_html__( 'Version %s', $this->textdomain ),
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
			'core'    => esc_html__( 'WordPress Core', $this->textdomain ),
			'plugins' => esc_html__( 'Plugins', $this->textdomain ),
			'themes'  => esc_html__( 'Themes', $this->textdomain ),
		);
	}

	/**
	 * Handle the AJAX request for starting a scan.
	 */
	public function handle_start_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', $this->textdomain ) ), 403 );
		}

		check_ajax_referer( 'wpig_start_scan', 'nonce' );

		$raw_targets = isset( $_POST['targets'] ) ? (array) wp_unslash( $_POST['targets'] ) : array();
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
			wp_send_json_error( array( 'message' => __( 'Access denied.', $this->textdomain ) ), 403 );
		}

		check_ajax_referer( 'wpig_scan_status', 'nonce' );

		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';

		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid job identifier.', $this->textdomain ) ), 400 );
		}

		$job = $this->load_job_state( $job_id );

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Scan session expired. Please start again.', $this->textdomain ) ), 410 );
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
			$message = __( 'Processing…', $this->textdomain );
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
	}

	/**
	 * Ensure the daily cron event aligns with the saved preference.
	 */
	public function maybe_schedule_cron(): void {
		$options   = $this->settings->get_options();
		$enabled   = ! empty( $options['daily_scan_enabled'] );
		$timestamp = wp_next_scheduled( 'wpig_daily_scan' );

		if ( $enabled && false === $timestamp ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpig_daily_scan' );
			return;
		}

		if ( ! $enabled && false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpig_daily_scan' );
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

			switch ( $target ) {
				case 'core':
					$result   = $this->advance_core_target( $state );
					$message  = $result['message'];
					$performed++;

					if ( ! empty( $result['error'] ) ) {
						$job['errors'][] = array(
							'target'  => $target,
							'message' => $result['message'],
						);
					}

					if ( ! empty( $result['complete'] ) ) {
						$job['results']['core'] = $this->summarize_core_result( $state );
						$job['current_target']++;
					}
					break;

				case 'plugins':
					$result   = $this->advance_plugins_target( $state );
					$message  = $result['message'];
					$performed++;

					if ( ! empty( $result['error'] ) ) {
						$job['errors'][] = array(
							'target'  => $target,
							'message' => $result['message'],
						);
					}

					if ( ! empty( $result['complete'] ) ) {
						$job['results']['plugins'] = $this->summarize_plugins_result( $state );
						$job['current_target']++;
					}
					break;

				case 'themes':
					$result   = $this->advance_themes_target( $state );
					$message  = $result['message'];
					$performed++;

					if ( ! empty( $result['error'] ) ) {
						$job['errors'][] = array(
							'target'  => $target,
							'message' => $result['message'],
						);
					}

					if ( ! empty( $result['complete'] ) ) {
						$job['results']['themes'] = $this->summarize_themes_result( $state );
						$job['current_target']++;
					}
					break;

				default:
					if ( 'pending' === ( $state['status'] ?? 'pending' ) ) {
						$state['status']  = 'skipped';
						$state['message'] = __( 'Scanning for this target is not yet available.', $this->textdomain );
						$job['results'][ $target ] = array(
							'status'  => 'skipped',
							'message' => $state['message'],
						);
						$message = $state['message'];
					}

					$job['current_target']++;
					$performed++;
					break;
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
			$state['message'] = __( 'Fetching WordPress core checksums…', $this->textdomain );

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
			$state['message'] = __( 'Scanning WordPress core files…', $this->textdomain );

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
				$state['message'] = __( 'Wrapping up WordPress core scan…', $this->textdomain );

				return array(
					'complete' => false,
					'message'  => $state['message'],
				);
			}

			$state['message'] = $chunk['message'] ?? __( 'Scanning WordPress core files…', $this->textdomain );

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
				'message'  => $state['message'] ?? __( 'WordPress core scan completed.', $this->textdomain ),
			);
		}

		if ( 'error' === $status ) {
			return array(
				'complete' => true,
				'error'    => true,
				'message'  => $state['error_message'] ?? __( 'Unable to scan WordPress core.', $this->textdomain ),
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

		$state['checksums'] = $filtered;
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
	 * Fetch WordPress core checksums from WordPress.org with locale fallback.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Preferred locale.
	 * @return array{checksums:array<string,string>,locale:string}|WP_Error
	 */
	private function fetch_core_checksums( string $version, string $locale ) {
		$candidates = array_unique( array( $locale, 'en_US' ) );
		$last_error = null;

		foreach ( $candidates as $candidate ) {
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
					'wpig_http_error',
					sprintf(
						/* translators: %s: HTTP status code. */
						__( 'Unexpected response while fetching core checksums (%s).', $this->textdomain ),
						$code
					)
				);
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				$last_error = new WP_Error( 'wpig_invalid_response', __( 'Unable to parse checksum response.', $this->textdomain ) );
				continue;
			}

			if ( empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
				if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$last_error = new WP_Error( 'wpig_remote_error', $data['error'] );
					continue;
				}

				$last_error = new WP_Error( 'wpig_missing_checksums', __( 'Checksum data was not provided by WordPress.org.', $this->textdomain ) );
				continue;
			}

			return array(
				'checksums' => $data['checksums'],
				'locale'    => isset( $data['locale'] ) ? (string) $data['locale'] : $candidate,
			);
		}

		return $last_error ? $last_error : new WP_Error( 'wpig_checksum_unavailable', __( 'Unable to download WordPress core checksums.', $this->textdomain ) );
	}

	/**
	 * Filter checksum map to exclude user controlled files.
	 *
	 * @param array<string,string> $checksums         Raw checksum map.
	 * @param bool                 $include_themes    Whether to retain default theme checksums.
	 * @param array<int,string>    $allowed_theme_dirs Populated with allowed theme directory prefixes when themes are included. Passed by reference.
	 * @return array<string,string>
	 */
	private function filter_core_checksums( array $checksums, bool $include_themes = false, array &$allowed_theme_dirs = array() ): array {
		$filtered          = array();
		$allowed_theme_map = array();

		foreach ( $checksums as $path => $hash ) {
			$normalized = ltrim( wp_normalize_path( (string) $path ), '/' );

			if ( $include_themes && 0 === strpos( $normalized, 'wp-content/themes' ) ) {
				$theme_dir = $this->resolve_core_theme_directory( $normalized );

				if ( null !== $theme_dir ) {
					$allowed_theme_map[ $theme_dir ] = true;
				}

				$filtered[ $normalized ] = (string) $hash;
				continue;
			}

			if ( $this->is_core_path_excluded( $normalized ) ) {
				continue;
			}

			$filtered[ $normalized ] = (string) $hash;
		}

		if ( $include_themes ) {
			if ( ! empty( $allowed_theme_map ) ) {
				$allowed_theme_map['wp-content/themes'] = true;
			}
			$allowed_theme_dirs = array_values( array_keys( $allowed_theme_map ) );
		} else {
			$allowed_theme_dirs = array();
		}

		return $filtered;
	}

	/**
	 * Resolve the theme directory prefix for a checksum path.
	 *
	 * @param string $path Normalized checksum path.
	 * @return string|null
	 */
	private function resolve_core_theme_directory( string $path ): ?string {
		$normalized = ltrim( wp_normalize_path( $path ), '/' );

		if ( 0 !== strpos( $normalized, 'wp-content/themes' ) ) {
			return null;
		}

		$parts = explode( '/', $normalized );

		if ( count( $parts ) < 3 ) {
			return 'wp-content/themes';
		}

		if ( 'themes' !== $parts[1] ) {
			return null;
		}

		if ( count( $parts ) >= 4 ) {
			$slug = $parts[2];
			if ( '' !== $slug ) {
				return 'wp-content/themes/' . $slug;
			}
		}

		return 'wp-content/themes';
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
		$checks  = $state['checksums'] ?? array();

		if ( empty( $files ) || 0 === $total ) {
			return array(
				'finished' => true,
				'message'  => __( 'No core files were available for scanning.', $this->textdomain ),
			);
		}

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
					'message' => __( 'File could not be read.', $this->textdomain ),
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
			__( 'Scanning WordPress core files (%1$d of %2$d)…', $this->textdomain ),
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

		unset( $state['checksums'], $state['files'], $state['actual_files'], $state['pointer'], $state['allowed_theme_dirs'] );
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
				return __( 'WordPress core and bundled themes scan completed without integrity issues.', $this->textdomain );
			}

			return __( 'WordPress core scan completed without integrity issues.', $this->textdomain );
		}

		if ( $includes_themes ) {
			return sprintf(
				/* translators: 1: modified count, 2: missing count, 3: added count. */
				__( 'WordPress core and bundled themes issues detected — %1$d modified, %2$d missing, %3$d unexpected files.', $this->textdomain ),
				$modified,
				$missing,
				$added
			);
		}

		return sprintf(
			/* translators: 1: modified count, 2: missing count, 3: added count. */
			__( 'WordPress core issues detected — %1$d modified, %2$d missing, %3$d unexpected files.', $this->textdomain ),
			$modified,
			$missing,
			$added
		);
	}

	/**
	 * Build summarized core result payload.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return array<string,mixed>
	 */
	private function summarize_core_result( array $state ): array {
		return array(
			'status'          => $state['status'] ?? 'unknown',
			'version'         => $state['version'] ?? '',
			'locale'          => $state['locale'] ?? '',
			'themes_included' => ! empty( $state['include_core_themes'] ),
			'modified'        => $state['modified'] ?? array(),
			'missing'         => $state['missing'] ?? array(),
			'added'           => $state['added'] ?? array(),
			'errors'          => $state['errors'] ?? array(),
			'summary'         => $this->build_core_summary_message( $state ),
		);
	}

	/**
	 * Advance the plugin scan state by one step.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function advance_plugins_target( array &$state ): array {
		$status = $state['status'] ?? 'pending';

		if ( 'pending' === $status ) {
			$result = $this->prepare_plugins_target( $state );

			if ( is_wp_error( $result ) ) {
				$state['status']  = 'error';
				$state['message'] = $result->get_error_message();

				return array(
					'complete' => true,
					'error'    => true,
					'message'  => $state['message'],
				);
			}

			if ( empty( $state['total'] ) ) {
				$state['status']  = 'completed';
				$state['message'] = __( 'No WordPress.org plugins were available for verification.', $this->textdomain );

				return array(
					'complete' => true,
					'error'    => false,
					'message'  => $state['message'],
				);
			}

			$state['status']  = 'processing';
			$state['message'] = __( 'Preparing plugin verification…', $this->textdomain );

			return array(
				'complete' => false,
				'error'    => false,
				'message'  => $state['message'],
			);
		}

		if ( 'processing' === $status ) {
			$result = $this->process_next_plugin( $state );

			if ( ! empty( $result['finished'] ) ) {
				$this->finalize_plugins_target( $state );
				$state['status']  = 'completed';
				$state['message'] = __( 'Plugin verification completed.', $this->textdomain );

				return array(
					'complete' => true,
					'error'    => ! empty( $state['errors'] ),
					'message'  => $state['message'],
				);
			}

			return array(
				'complete' => false,
				'error'    => ! empty( $result['error'] ),
				'message'  => $result['message'],
			);
		}

		return array(
			'complete' => true,
			'error'    => 'error' === $status,
			'message'  => $state['message'] ?? '',
		);
	}

	/**
	 * Build plugin scan metadata.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return true|WP_Error
	 */
	private function prepare_plugins_target( array &$state ) {
		if ( ! class_exists( 'WPIG_Utils' ) ) {
			return new WP_Error( 'wpig_missing_utils', __( 'Utility class is unavailable.', $this->textdomain ) );
		}

		$sources = WPIG_Utils::detect_plugin_sources();
		$plugins = array();
		$order   = array();
		$skipped = array();

		foreach ( $sources as $file => $info ) {
			$name     = isset( $info['name'] ) ? (string) $info['name'] : $file;
			$name     = $this->sanitize_extension_label( $name );
			$version  = isset( $info['version'] ) ? (string) $info['version'] : '';
			$slug     = isset( $info['slug'] ) ? (string) $info['slug'] : '';
			$is_wporg = ! empty( $info['is_wporg'] );

			if ( ! $is_wporg ) {
				$skipped[] = array(
					'plugin' => $file,
					'name'   => $name,
					'reason' => __( 'Not available on WordPress.org.', $this->textdomain ),
				);
				continue;
			}

			if ( '' === $slug ) {
				$skipped[] = array(
					'plugin' => $file,
					'name'   => $name,
					'reason' => __( 'Plugin slug could not be determined.', $this->textdomain ),
				);
				continue;
			}

			if ( '' === $version ) {
				$skipped[] = array(
					'plugin' => $file,
					'name'   => $name,
					'reason' => __( 'Plugin version could not be determined.', $this->textdomain ),
				);
				continue;
			}

			$plugins[ $file ] = array(
				'plugin'   => $file,
				'name'     => $name,
				'slug'     => $slug,
				'version'  => $version,
				'status'   => 'pending',
				'modified' => array(),
				'missing'  => array(),
				'added'    => array(),
				'errors'   => array(),
			);

			$order[] = $file;
		}

		$state['plugins']   = $plugins;
		$state['order']     = $order;
		$state['skipped']   = $skipped;
		$state['errors']    = array();
		$state['processed'] = 0;
		$state['total']     = count( $order );
		$state['issue_total'] = 0;

		return true;
	}

	/**
	 * Process the next plugin pending verification.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function process_next_plugin( array &$state ): array {
		$order = $state['order'] ?? array();
		$total = max( 1, (int) ( $state['total'] ?? count( $order ) ) );

		foreach ( $order as $index => $key ) {
			if ( empty( $state['plugins'][ $key ] ) ) {
				continue;
			}

			$plugin = &$state['plugins'][ $key ];
			if ( 'pending' !== ( $plugin['status'] ?? 'pending' ) ) {
				unset( $plugin );
				continue;
			}

			$result = $this->scan_single_plugin( $plugin );
			$state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;

			if ( is_wp_error( $result ) ) {
				$error_message    = $result->get_error_message();
				$plugin['status']  = 'error';
				$plugin['message'] = $error_message;
				$state['errors'][] = array(
					'plugin'  => $key,
					'name'    => $plugin['name'] ?? $key,
					'message' => $error_message,
				);

				$message = sprintf(
					/* translators: %s: Plugin name. */
					__( 'Verification failed for plugin %s.', $this->textdomain ),
					$plugin['name'] ?? $key
				);

				unset( $plugin );

				return array(
					'finished' => false,
					'error'    => true,
					'message'  => $message,
				);
			}

			$plugin['modified'] = $result['modified'];
			$plugin['missing']  = $result['missing'];
			$plugin['added']    = $result['added'];
			$plugin['errors']   = $result['errors'];
			$plugin['status']   = $result['status'];
			$plugin['message']  = $result['message'];

			if ( 'issues' === $result['status'] ) {
				$state['issue_total'] = (int) ( $state['issue_total'] ?? 0 ) + 1;
			}

			$message = sprintf(
				/* translators: 1: plugin name, 2: current position, 3: total. */
				__( 'Checked plugin %1$s (%2$d of %3$d).', $this->textdomain ),
				$plugin['name'] ?? $key,
				$index + 1,
				$total
			);

			if ( ! empty( $result['message'] ) ) {
				$message = $result['message'];
			}

			unset( $plugin );

			return array(
				'finished' => false,
				'error'    => false,
				'message'  => $message,
			);
		}

		return array(
			'finished' => true,
			'error'    => false,
			'message'  => __( 'Plugin verification completed.', $this->textdomain ),
		);
	}

	/**
	 * Verify a single plugin against WordPress.org checksums.
	 *
	 * @param array<string,mixed> $plugin Plugin payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function scan_single_plugin( array $plugin ) {
		$plugin_file = $plugin['plugin'] ?? '';
		$slug        = $plugin['slug'] ?? '';
		$version     = $plugin['version'] ?? '';
		$name        = $plugin['name'] ?? $plugin_file;

		if ( '' === $plugin_file || '' === $slug || '' === $version ) {
			return new WP_Error( 'wpig_plugin_data', __( 'Insufficient data to verify plugin.', $this->textdomain ) );
		}

		$checksums = $this->fetch_plugin_checksums( $slug, $version );
		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$prefixes = array();
		if ( '' !== $slug ) {
			$prefixes[] = $slug;
		}
		$directory = dirname( $plugin_file );
		if ( '.' !== $directory && '' !== $directory ) {
			$prefixes[] = $directory;
		}

		$map = $this->normalize_checksum_map( $checksums['checksums'], $prefixes );
		if ( empty( $map ) ) {
			return new WP_Error( 'wpig_missing_plugin_checksums', __( 'Plugin checksums were not provided.', $this->textdomain ) );
		}

		$root_info    = $this->determine_plugin_root( $plugin_file, $slug );
		$comparison   = $this->compare_extension_files( $root_info['root'], $map, $checksums['checksum_type'], $root_info['detect_added'] );
		$has_issues   = ! empty( $comparison['modified'] ) || ! empty( $comparison['missing'] ) || ! empty( $comparison['added'] );
		$status       = $has_issues ? 'issues' : 'ok';
		$message      = $has_issues
			? sprintf( __( 'Differences detected in plugin %s.', $this->textdomain ), $name )
			: sprintf( __( 'Plugin %s matches the official checksums.', $this->textdomain ), $name );

		return array(
			'status'   => $status,
			'message'  => $message,
			'modified' => $comparison['modified'],
			'missing'  => $comparison['missing'],
			'added'    => $comparison['added'],
			'errors'   => $comparison['errors'],
		);
	}

	/**
	 * Finalise plugin verification state.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return void
	 */
	private function finalize_plugins_target( array &$state ): void {
		$issues = 0;
		foreach ( $state['plugins'] ?? array() as $plugin ) {
			if ( 'issues' === ( $plugin['status'] ?? '' ) ) {
				$issues++;
			}
		}

		$state['issue_total'] = $issues;
		$state['processed']   = isset( $state['total'] ) ? (int) $state['total'] : (int) count( $state['plugins'] ?? array() );
	}

	/**
	 * Summarize plugin verification results.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return array<string,mixed>
	 */
	private function summarize_plugins_result( array $state ): array {
		$order   = isset( $state['order'] ) && is_array( $state['order'] ) ? $state['order'] : array_keys( $state['plugins'] ?? array() );
		$items   = array();
		$plugins = $state['plugins'] ?? array();

		foreach ( $order as $key ) {
			if ( empty( $plugins[ $key ] ) ) {
				continue;
			}

			$data     = $plugins[ $key ];
			$items[] = array(
				'key'      => $key,
				'name'     => $data['name'] ?? $key,
				'version'  => $data['version'] ?? '',
				'status'   => $data['status'] ?? 'unknown',
				'message'  => $data['message'] ?? '',
				'modified' => $data['modified'] ?? array(),
				'missing'  => $data['missing'] ?? array(),
				'added'    => $data['added'] ?? array(),
				'errors'   => $data['errors'] ?? array(),
			);
		}

		$skipped = array();
		foreach ( $state['skipped'] ?? array() as $entry ) {
			$skipped[] = array(
				'key'    => $entry['plugin'] ?? '',
				'name'   => $entry['name'] ?? '',
				'reason' => $entry['reason'] ?? '',
			);
		}

		$errors = array();
		foreach ( $state['errors'] ?? array() as $entry ) {
			$errors[] = array(
				'key'     => $entry['plugin'] ?? '',
				'name'    => $entry['name'] ?? '',
				'message' => $entry['message'] ?? '',
			);
		}

		$total   = (int) ( $state['total'] ?? count( $items ) );
		$issues  = (int) ( $state['issue_total'] ?? 0 );
		$skipped_count = count( $skipped );
		$error_count   = count( $errors );

		return array(
			'status'  => $state['status'] ?? 'unknown',
			'summary' => $this->build_plugins_summary_message( $state ),
			'items'   => $items,
			'skipped' => $skipped,
			'errors'  => $errors,
			'totals'  => array(
				'verified' => $total,
				'issues'   => $issues,
				'skipped'  => $skipped_count,
				'errors'   => $error_count,
			),
		);
	}

	/**
	 * Build summary message for plugin verification results.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return string
	 */
	private function build_plugins_summary_message( array $state ): string {
		$total        = (int) ( $state['total'] ?? 0 );
		$issues       = (int) ( $state['issue_total'] ?? 0 );
		$skipped      = is_array( $state['skipped'] ?? null ) ? count( $state['skipped'] ) : 0;
		$error_count  = is_array( $state['errors'] ?? null ) ? count( $state['errors'] ) : 0;

		if ( 0 === $total ) {
			if ( $skipped > 0 ) {
				return __( 'No WordPress.org plugins were available for verification.', $this->textdomain );
			}

			return __( 'No plugins required verification.', $this->textdomain );
		}

		if ( 0 === $issues && 0 === $error_count ) {
			if ( $skipped > 0 ) {
				return sprintf(
					/* translators: %d: number of plugins. */
					__( 'All WordPress.org plugins verified successfully. %d plugin(s) were skipped.', $this->textdomain ),
					$skipped
				);
			}

			return __( 'All WordPress.org plugins match the official checksums.', $this->textdomain );
		}

		$message = sprintf(
			/* translators: 1: number of plugins with issues, 2: total verified. */
			__( 'Integrity issues detected in %1$d plugin(s) out of %2$d verified.', $this->textdomain ),
			$issues,
			$total
		);

		if ( $error_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of plugins. */
				__( '%d plugin(s) could not be verified.', $this->textdomain ),
				$error_count
			);
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of plugins. */
				__( '%d plugin(s) were skipped.', $this->textdomain ),
				$skipped
			);
		}

		return $message;
	}

	/**
	 * Advance the theme scan state by one step.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function advance_themes_target( array &$state ): array {
		$status = $state['status'] ?? 'pending';

		if ( 'pending' === $status ) {
			$result = $this->prepare_themes_target( $state );

			if ( is_wp_error( $result ) ) {
				$state['status']  = 'error';
				$state['message'] = $result->get_error_message();

				return array(
					'complete' => true,
					'error'    => true,
					'message'  => $state['message'],
				);
			}

			if ( empty( $state['total'] ) ) {
				$state['status']  = 'completed';
				$state['message'] = __( 'No WordPress.org themes were available for verification.', $this->textdomain );

				return array(
					'complete' => true,
					'error'    => false,
					'message'  => $state['message'],
				);
			}

			$state['status']  = 'processing';
			$state['message'] = __( 'Preparing theme verification…', $this->textdomain );

			return array(
				'complete' => false,
				'error'    => false,
				'message'  => $state['message'],
			);
		}

		if ( 'processing' === $status ) {
			$result = $this->process_next_theme( $state );

			if ( ! empty( $result['finished'] ) ) {
				$this->finalize_themes_target( $state );
				$state['status']  = 'completed';
				$state['message'] = __( 'Theme verification completed.', $this->textdomain );

				return array(
					'complete' => true,
					'error'    => ! empty( $state['errors'] ),
					'message'  => $state['message'],
				);
			}

			return array(
				'complete' => false,
				'error'    => ! empty( $result['error'] ),
				'message'  => $result['message'],
			);
		}

		return array(
			'complete' => true,
			'error'    => 'error' === $status,
			'message'  => $state['message'] ?? '',
		);
	}

	/**
	 * Build theme scan metadata.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return true|WP_Error
	 */
	private function prepare_themes_target( array &$state ) {
		if ( ! class_exists( 'WPIG_Utils' ) ) {
			return new WP_Error( 'wpig_missing_utils', __( 'Utility class is unavailable.', $this->textdomain ) );
		}

		$sources = WPIG_Utils::detect_theme_sources();
		$themes  = array();
		$order   = array();
		$skipped = array();

		foreach ( $sources as $stylesheet => $info ) {
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				continue;
			}

			$name     = $this->sanitize_extension_label( $theme->get( 'Name' ) );
			$version  = (string) $theme->get( 'Version' );
			$slug     = $stylesheet;
			$is_wporg = ! empty( $info['is_wporg'] );

			if ( ! $is_wporg ) {
				$skipped[] = array(
					'theme'  => $stylesheet,
					'name'   => $name,
					'reason' => __( 'Not available on WordPress.org.', $this->textdomain ),
				);
				continue;
			}

			if ( '' === $version ) {
				$skipped[] = array(
					'theme'  => $stylesheet,
					'name'   => $name,
					'reason' => __( 'Theme version could not be determined.', $this->textdomain ),
				);
				continue;
			}

			$themes[ $stylesheet ] = array(
				'stylesheet' => $stylesheet,
				'name'       => $name,
				'slug'       => $slug,
				'version'    => $version,
				'status'     => 'pending',
				'modified'   => array(),
				'missing'    => array(),
				'added'      => array(),
				'errors'     => array(),
			);

			$order[] = $stylesheet;
		}

		$state['themes']     = $themes;
		$state['order']      = $order;
		$state['skipped']    = $skipped;
		$state['errors']     = array();
		$state['processed']  = 0;
		$state['total']      = count( $order );
		$state['issue_total'] = 0;

		return true;
	}

	/**
	 * Process the next theme pending verification.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return array<string,mixed>
	 */
	private function process_next_theme( array &$state ): array {
		$order = $state['order'] ?? array();
		$total = max( 1, (int) ( $state['total'] ?? count( $order ) ) );

		foreach ( $order as $index => $key ) {
			if ( empty( $state['themes'][ $key ] ) ) {
				continue;
			}

			$theme = &$state['themes'][ $key ];
			if ( 'pending' !== ( $theme['status'] ?? 'pending' ) ) {
				unset( $theme );
				continue;
			}

			$result = $this->scan_single_theme( $theme );
			$state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;

			if ( is_wp_error( $result ) ) {
				$error_message   = $result->get_error_message();
				$theme['status']  = 'error';
				$theme['message'] = $error_message;
				$state['errors'][] = array(
					'theme'   => $key,
					'name'    => $theme['name'] ?? $key,
					'message' => $error_message,
				);

				$message = sprintf(
					/* translators: %s: Theme name. */
					__( 'Verification failed for theme %s.', $this->textdomain ),
					$theme['name'] ?? $key
				);

				unset( $theme );

				return array(
					'finished' => false,
					'error'    => true,
					'message'  => $message,
				);
			}

			$theme['modified'] = $result['modified'];
			$theme['missing']  = $result['missing'];
			$theme['added']    = $result['added'];
			$theme['errors']   = $result['errors'];
			$theme['status']   = $result['status'];
			$theme['message']  = $result['message'];

			if ( 'issues' === $result['status'] ) {
				$state['issue_total'] = (int) ( $state['issue_total'] ?? 0 ) + 1;
			}

			$message = sprintf(
				/* translators: 1: theme name, 2: current position, 3: total. */
				__( 'Checked theme %1$s (%2$d of %3$d).', $this->textdomain ),
				$theme['name'] ?? $key,
				$index + 1,
				$total
			);

			if ( ! empty( $result['message'] ) ) {
				$message = $result['message'];
			}

			unset( $theme );

			return array(
				'finished' => false,
				'error'    => false,
				'message'  => $message,
			);
		}

		return array(
			'finished' => true,
			'error'    => false,
			'message'  => __( 'Theme verification completed.', $this->textdomain ),
		);
	}

	/**
	 * Verify a single theme against WordPress.org checksums.
	 *
	 * @param array<string,mixed> $theme Theme payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function scan_single_theme( array $theme ) {
		$stylesheet = $theme['stylesheet'] ?? '';
		$slug       = $theme['slug'] ?? '';
		$version    = $theme['version'] ?? '';
		$name       = $theme['name'] ?? $stylesheet;

		if ( '' === $stylesheet || '' === $slug || '' === $version ) {
			return new WP_Error( 'wpig_theme_data', __( 'Insufficient data to verify theme.', $this->textdomain ) );
		}

		$theme_obj = wp_get_theme( $stylesheet );
		if ( ! $theme_obj->exists() ) {
			return new WP_Error( 'wpig_theme_missing', __( 'Theme is not installed.', $this->textdomain ) );
		}

		$root = $theme_obj->get_stylesheet_directory();
		if ( ! $root || ! is_dir( $root ) ) {
			return new WP_Error( 'wpig_theme_path', __( 'Theme directory could not be located.', $this->textdomain ) );
		}

		$checksums = $this->fetch_theme_checksums( $slug, $version );
		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$map = $this->normalize_checksum_map( $checksums['checksums'], array( $slug ) );
		if ( empty( $map ) ) {
			return new WP_Error( 'wpig_missing_theme_checksums', __( 'Theme checksums were not provided.', $this->textdomain ) );
		}

		$comparison = $this->compare_extension_files( $root, $map, $checksums['checksum_type'], true );
		$has_issues = ! empty( $comparison['modified'] ) || ! empty( $comparison['missing'] ) || ! empty( $comparison['added'] );
		$status     = $has_issues ? 'issues' : 'ok';
		$message    = $has_issues
			? sprintf( __( 'Differences detected in theme %s.', $this->textdomain ), $name )
			: sprintf( __( 'Theme %s matches the official checksums.', $this->textdomain ), $name );

		return array(
			'status'   => $status,
			'message'  => $message,
			'modified' => $comparison['modified'],
			'missing'  => $comparison['missing'],
			'added'    => $comparison['added'],
			'errors'   => $comparison['errors'],
		);
	}

	/**
	 * Finalise theme verification state.
	 *
	 * @param array<string,mixed> $state Target state (passed by reference).
	 * @return void
	 */
	private function finalize_themes_target( array &$state ): void {
		$issues = 0;
		foreach ( $state['themes'] ?? array() as $theme ) {
			if ( 'issues' === ( $theme['status'] ?? '' ) ) {
				$issues++;
			}
		}

		$state['issue_total'] = $issues;
		$state['processed']   = isset( $state['total'] ) ? (int) $state['total'] : (int) count( $state['themes'] ?? array() );
	}

	/**
	 * Summarize theme verification results.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return array<string,mixed>
	 */
	private function summarize_themes_result( array $state ): array {
		$order  = isset( $state['order'] ) && is_array( $state['order'] ) ? $state['order'] : array_keys( $state['themes'] ?? array() );
		$themes = $state['themes'] ?? array();
		$items  = array();

		foreach ( $order as $key ) {
			if ( empty( $themes[ $key ] ) ) {
				continue;
			}

			$data     = $themes[ $key ];
			$items[] = array(
				'key'      => $key,
				'name'     => $data['name'] ?? $key,
				'version'  => $data['version'] ?? '',
				'status'   => $data['status'] ?? 'unknown',
				'message'  => $data['message'] ?? '',
				'modified' => $data['modified'] ?? array(),
				'missing'  => $data['missing'] ?? array(),
				'added'    => $data['added'] ?? array(),
				'errors'   => $data['errors'] ?? array(),
			);
		}

		$skipped = array();
		foreach ( $state['skipped'] ?? array() as $entry ) {
			$skipped[] = array(
				'key'    => $entry['theme'] ?? '',
				'name'   => $entry['name'] ?? '',
				'reason' => $entry['reason'] ?? '',
			);
		}

		$errors = array();
		foreach ( $state['errors'] ?? array() as $entry ) {
			$errors[] = array(
				'key'     => $entry['theme'] ?? '',
				'name'    => $entry['name'] ?? '',
				'message' => $entry['message'] ?? '',
			);
		}

		$total   = (int) ( $state['total'] ?? count( $items ) );
		$issues  = (int) ( $state['issue_total'] ?? 0 );
		$skipped_count = count( $skipped );
		$error_count   = count( $errors );

		return array(
			'status'  => $state['status'] ?? 'unknown',
			'summary' => $this->build_themes_summary_message( $state ),
			'items'   => $items,
			'skipped' => $skipped,
			'errors'  => $errors,
			'totals'  => array(
				'verified' => $total,
				'issues'   => $issues,
				'skipped'  => $skipped_count,
				'errors'   => $error_count,
			),
		);
	}

	/**
	 * Build summary message for theme verification results.
	 *
	 * @param array<string,mixed> $state Target state.
	 * @return string
	 */
	private function build_themes_summary_message( array $state ): string {
		$total        = (int) ( $state['total'] ?? 0 );
		$issues       = (int) ( $state['issue_total'] ?? 0 );
		$skipped      = is_array( $state['skipped'] ?? null ) ? count( $state['skipped'] ) : 0;
		$error_count  = is_array( $state['errors'] ?? null ) ? count( $state['errors'] ) : 0;

		if ( 0 === $total ) {
			if ( $skipped > 0 ) {
				return __( 'No WordPress.org themes were available for verification.', $this->textdomain );
			}

			return __( 'No themes required verification.', $this->textdomain );
		}

		if ( 0 === $issues && 0 === $error_count ) {
			if ( $skipped > 0 ) {
				return sprintf(
					/* translators: %d: number of themes. */
					__( 'All WordPress.org themes verified successfully. %d theme(s) were skipped.', $this->textdomain ),
					$skipped
				);
			}

			return __( 'All WordPress.org themes match the official checksums.', $this->textdomain );
		}

		$message = sprintf(
			/* translators: 1: number of themes with issues, 2: total verified. */
			__( 'Integrity issues detected in %1$d theme(s) out of %2$d verified.', $this->textdomain ),
			$issues,
			$total
		);

		if ( $error_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of themes. */
				__( '%d theme(s) could not be verified.', $this->textdomain ),
				$error_count
			);
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of themes. */
				__( '%d theme(s) were skipped.', $this->textdomain ),
				$skipped
			);
		}

		return $message;
	}

	/**
	 * Retrieve plugin checksums from WordPress.org.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Plugin version.
	 * @return array<string,mixed>|WP_Error
	 */
	private function fetch_plugin_checksums( string $slug, string $version ) {
		if ( '' === $slug || '' === $version ) {
			return new WP_Error( 'wpig_plugin_checksum_args', __( 'Invalid plugin checksum request.', $this->textdomain ) );
		}

		return $this->fetch_plugin_checksums_from_downloads( $slug, $version );
	}

	/**
	 * Retrieve plugin checksums via downloads.wordpress.org endpoint.
	 *
	 * @param string $slug Plugin slug.
	 * @param string $version Plugin version.
	 * @return array<string,mixed>|WP_Error
	 */
	private function fetch_plugin_checksums_from_downloads( string $slug, string $version ) {
		$url     = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%1$s/%2$s.json', rawurlencode( $slug ), rawurlencode( $version ) );
		$request = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'WP Integrity Guard/' . $this->version,
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			if ( 404 === $code ) {
				return new WP_Error( 'wpig_plugin_checksum_missing', __( 'Plugin checksums were not provided by WordPress.org.', $this->textdomain ) );
			}

			return new WP_Error( 'wpig_plugin_checksum_http', __( 'Unable to download plugin checksums.', $this->textdomain ) );
		}

		$body = wp_remote_retrieve_body( $request );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wpig_plugin_checksum_json', __( 'Unexpected plugin checksum response.', $this->textdomain ) );
		}

		if ( empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return new WP_Error( 'wpig_plugin_checksum_missing', __( 'Plugin checksums were not provided by WordPress.org.', $this->textdomain ) );
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
			return new WP_Error( 'wpig_theme_checksum_args', __( 'Invalid theme checksum request.', $this->textdomain ) );
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
				'user-agent' => 'WP Integrity Guard/' . $this->version,
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			return new WP_Error( 'wpig_theme_checksum_http', __( 'Unable to download theme checksums.', $this->textdomain ) );
		}

		$body = wp_remote_retrieve_body( $request );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wpig_theme_checksum_json', __( 'Unexpected theme checksum response.', $this->textdomain ) );
		}

		if ( empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return new WP_Error( 'wpig_theme_checksum_error', $data['error'] );
			}

			return new WP_Error( 'wpig_theme_checksum_missing', __( 'Theme checksums were not provided by WordPress.org.', $this->textdomain ) );
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
					'message' => __( 'File could not be read.', $this->textdomain ),
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
					'message' => __( 'File could not be hashed.', $this->textdomain ),
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
			return __( 'Unknown', $this->textdomain );
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
			return __( 'Scan completed.', $this->textdomain );
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
	 * Build a list of core files excluding user directories.
	 *
	 * @return array<int,string>
	 */
	private function list_core_files( array $allowed_theme_dirs = array() ): array {
		$files = array();
		$root  = wp_normalize_path( trailingslashit( ABSPATH ) );
		$base  = strlen( $root );

		$directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
		$filter    = new RecursiveCallbackFilterIterator(
			$directory,
			function ( SplFileInfo $current ) use ( $root, $base, $allowed_theme_dirs ) {
				$path = wp_normalize_path( $current->getPathname() );
				$rel  = ltrim( substr( $path, $base ), '/' );

				if ( '' === $rel ) {
					return true;
				}

				if ( $current->isDir() ) {
					return ! $this->is_core_path_excluded( $rel, $allowed_theme_dirs );
				}

				return ! $this->is_core_path_excluded( $rel, $allowed_theme_dirs );
			}
		);

		$iterator = new RecursiveIteratorIterator( $filter );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			$rel  = ltrim( substr( $path, $base ), '/' );

			if ( '' === $rel || $this->is_core_path_excluded( $rel, $allowed_theme_dirs ) ) {
				continue;
			}

			$files[] = $rel;
		}

		return $files;
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
