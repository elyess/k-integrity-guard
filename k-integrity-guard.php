<?php
/**
 * Plugin Name: K'Integrity Guard
 * Description: Monitors and protects your WordPress core files, themes, and plugins from unauthorized changes.
 * Version: 1.1.0
 * Author: Elyes Zouaghi
 * Author URI: https://github.com/elyess/k-integrity-guard
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: k-integrity-guard
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-kig-settings.php';
require_once __DIR__ . '/includes/class-kig-utils.php';
require_once __DIR__ . '/includes/class-kig-db.php';
require_once __DIR__ . '/includes/class-kig-scan.php';
require_once __DIR__ . '/includes/class-kig-history-table.php';

class K_Integrity_Guard {

	const VERSION = '1.1.0';


	const CAPABILITY = 'manage_options';

	const SLUG_SCAN = 'k_integrity_guard_scan';
	const SLUG_SETTINGS = 'k_integrity_guard_settings';
	const SLUG_HISTORY = 'k_integrity_guard_history';

	private string $plugin_file;

	private KIG_Settings $settings;
	private KIG_Scan $scan;
	private KIG_DB $db;

	public function __construct() {
		$this->plugin_file = __FILE__;
		$this->settings = new KIG_Settings();
		$this->db = new KIG_DB();
		$this->scan = new KIG_Scan( $this->plugin_file, self::SLUG_SCAN, $this->settings, self::VERSION, $this->db );
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), [$this, 'plugin_action_links']);
		add_action('admin_notices',        [$this, 'show_first_scan_notice']);
		add_action('network_admin_notices',[$this, 'show_first_scan_notice']);
			// Track plugin updates/installs and show regeneration notice for third-party plugins.
			add_action('upgrader_process_complete', [$this, 'capture_plugin_changes'], 10, 2);
			add_action('deleted_plugin', [$this, 'handle_deleted_plugin'], 10, 2);
			add_action('admin_notices', [$this, 'show_changed_plugins_notice']);
			// Track theme updates/installs and show regeneration notice for third-party themes.
			add_action('upgrader_process_complete', [$this, 'capture_theme_changes'], 10, 2);
			add_action('deleted_theme', [$this, 'handle_deleted_theme'], 10, 3);
			add_action('admin_notices', [$this, 'show_changed_themes_notice']);

		// Handle the AJAX dismissal (admins only)
		add_action('wp_ajax_kig_dismiss_first_scan_notice', [$this, 'dismiss_first_scan_notice']);

// Load a tiny JS when we might show the notice (cheap guard)
add_action('admin_enqueue_scripts', function ($hook) {
    // Only enqueue if the flag is on to avoid noise
    $flag = is_multisite() && is_plugin_active_for_network(plugin_basename(__FILE__))
        ? get_site_option('kig_show_first_scan_notice', 'no')
        : get_option('kig_show_first_scan_notice', 'no');

    if ($flag !== 'yes') return;

    wp_add_inline_script(
        'jquery-core', // ensure jQuery loaded first; or register your own handle
        "(function($){
            $(document).on('click', '.kig-first-scan-notice .notice-dismiss', function(){
                var \$n = $(this).closest('.kig-first-scan-notice');
                var nonce = \$n.data('nonce');
                $.post(ajaxurl, {
                    action: 'kig_dismiss_first_scan_notice',
                    nonce: nonce
                });
            });
        })(jQuery);"
    );
});		
	}

	public function register_admin_menu() {
		add_menu_page(
			__("K'Integrity Guard", 'k-integrity-guard'),
			__('Integrity Guard', 'k-integrity-guard'),
			self::CAPABILITY,
			self::SLUG_SCAN,
			[$this, 'render_scan_page'],
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			self::SLUG_SCAN,
			__('Scan', 'k-integrity-guard'),
			__('Scan', 'k-integrity-guard'),
			self::CAPABILITY,
			self::SLUG_SCAN,
			[$this, 'render_scan_page']
		);

		add_submenu_page(
			self::SLUG_SCAN,
			__('Settings', 'k-integrity-guard'),
			__('Settings', 'k-integrity-guard'),
			self::CAPABILITY,
			self::SLUG_SETTINGS,
			[$this, 'render_settings_page']
		);

		$history_hook = add_submenu_page(
			self::SLUG_SCAN,
			__('History', 'k-integrity-guard'),
			__('History', 'k-integrity-guard'),
			self::CAPABILITY,
			self::SLUG_HISTORY,
			[$this, 'render_history_page']
		);
		if ($history_hook) {
			add_action('load-' . $history_hook, [$this, 'handle_history_actions']);
		}
	}

	/**
	 * Handle History page delete actions before any output, so redirects work.
	 */
	public function handle_history_actions(): void {
		if (!current_user_can(self::CAPABILITY)) return;

		$redirect = admin_url('admin.php?page=' . self::SLUG_HISTORY);

		// Single delete.
		if (isset($_GET['action'], $_GET['scan_id']) && 'delete' === sanitize_key($_GET['action'])) {
			$scan_id = absint($_GET['scan_id']);
			check_admin_referer('delete_scan_' . $scan_id);
			KIG_DB::delete_scan($scan_id);
			wp_safe_redirect($redirect . '&deleted=1');
			exit;
		}

		// Bulk delete: WP_List_Table posts the top selector as 'action' and the bottom one as 'action2' (-1 = none).
		$bulk = '';
		foreach (['action', 'action2'] as $key) {
			if (isset($_POST[$key]) && '-1' !== $_POST[$key]) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$bulk = sanitize_key(wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				break;
			}
		}
		if ('delete' === $bulk && !empty($_POST['scan_ids'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer('bulk-scans');
			$scan_ids = array_map('absint', (array) wp_unslash($_POST['scan_ids']));
			KIG_DB::delete_scans($scan_ids);
			wp_safe_redirect($redirect . '&deleted=' . count($scan_ids));
			exit;
		}

		// Empty history.
		if (isset($_POST['kig_empty_history'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer('kig_empty_history');
			$deleted_count = KIG_DB::delete_all_scans();
			wp_safe_redirect($redirect . '&emptied=' . $deleted_count);
			exit;
		}
	}

	public function plugin_action_links(array $links) {
		$settings_url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);
		array_unshift(
				$links,
				'<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'k-integrity-guard') . '</a>',
				'<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_SCAN)) . '">' . esc_html__('Scan Now', 'k-integrity-guard') . '</a>'
		);
		return $links;
	}

	public function render_scan_page(): void {
			if (!current_user_can(self::CAPABILITY)) wp_die(esc_html__('You do not have permission.', 'k-integrity-guard'));
			$scan = $this->scan;
			$settings = $this->settings;
			include __DIR__ . '/includes/views/scan.php';
	}

	public function render_history_page(): void {
			if (!current_user_can(self::CAPABILITY)) wp_die(esc_html__('You do not have permission.', 'k-integrity-guard'));
			include __DIR__ . '/includes/views/history.php';
	}

	public function render_settings_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission.', 'k-integrity-guard' ) );
			}
			$settings = $this->settings;
			include __DIR__ . '/includes/views/settings.php';
	}

private function get_global_notice_flag() {
    if (is_multisite() && $this->is_network_active()) {
        return get_site_option('kig_show_first_scan_notice', 'no');
    }
    return get_option('kig_show_first_scan_notice', 'no');
}

private function set_global_notice_flag($value) {
    if (is_multisite() && $this->is_network_active()) {
        return update_site_option('kig_show_first_scan_notice', $value);
    }
    return update_option('kig_show_first_scan_notice', $value);
}

// Simple network-active check (works in most plugin setups)
private function is_network_active() {
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    // Adjust plugin basename if needed
    return is_multisite() && is_plugin_active_for_network(plugin_basename(__FILE__));
}

	public function dismiss_first_scan_notice() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer('kig_dismiss_first_scan_notice', 'nonce');

		// Flip the global flag off
		if ($this->is_network_active()) {
			update_site_option('kig_show_first_scan_notice', 'no');
		} else {
			update_option('kig_show_first_scan_notice', 'no');
		}

		wp_send_json_success();
	}

	public function show_first_scan_notice() {
    if (!current_user_can('manage_options')) return;

    // Only show if still globally enabled
    if ($this->get_global_notice_flag() !== 'yes') return;

    // Skip on the scan page itself
    if (isset($_GET['page']) && sanitize_key($_GET['page']) === self::SLUG_SCAN) return;

    $scan_url     = add_query_arg(['page' => self::SLUG_SCAN, 'run_first_scan' => 1], admin_url('admin.php'));
    $settings_url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);

    // Security nonce for AJAX dismiss
    $nonce = wp_create_nonce('kig_dismiss_first_scan_notice');

    echo '<div class="notice notice-info is-dismissible kig-first-scan-notice" data-nonce="' . esc_attr($nonce) . '">';
    echo '<p><strong>' . esc_html__("K'Integrity Guard is active.", 'k-integrity-guard') . '</strong></p>';
    echo '<p>' . esc_html__('You can run your first scan now or review the scan settings.', 'k-integrity-guard') . '</p>';
    echo '<p>';
    echo '<a href="' . esc_url($scan_url) . '" class="button button-primary">' . esc_html__('Run first scan', 'k-integrity-guard') . '</a> ';
    echo '<a href="' . esc_url($settings_url) . '" class="button">' . esc_html__('Review settings', 'k-integrity-guard') . '</a>';
    echo '</p>';
    echo '</div>';
	}

/** Capture plugin updates/installs and mark changed plugins. */
public function capture_plugin_changes($upgrader, $hook_extra) {
    if (empty($hook_extra['type']) || 'plugin' !== $hook_extra['type']) {
        return;
    }
    $changed = [];
    if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        $changed = $hook_extra['plugins'];
    }
    if (empty($changed)) return;

    $existing = get_transient('kig_changed_plugins');
    if (!is_array($existing)) $existing = [];
	    $merged = array_values(array_unique(array_merge($existing, $changed)));
	    set_transient('kig_changed_plugins', $merged, HOUR_IN_SECONDS);
	}

/** Remove checksum JSON when a plugin is deleted. */
public function handle_deleted_plugin($plugin_file, $deleted) {
    if (!$deleted) return;
    KIG_Utils::delete_checksum_file($plugin_file);
    $existing = get_transient('kig_changed_plugins');
    if (is_array($existing)) {
        $existing = array_diff($existing, [$plugin_file]);
        set_transient('kig_changed_plugins', $existing, HOUR_IN_SECONDS);
    }
}

/** Show a notice when third-party plugins changed version and need checksum regeneration. */
	public function show_changed_plugins_notice() {
	    if (!current_user_can('manage_options')) return;

    $changed = get_transient('kig_changed_plugins');
    if (empty($changed) || !is_array($changed)) return;

    // Respect ignores and only third-party.
    $opts = get_option(KIG_Settings::OPTION, []);
    $ignores = $opts['third_party']['ignore'] ?? [];

    $sources = KIG_Utils::detect_plugin_sources();

    $todo = [];
    foreach ($changed as $file) {
        if (!empty($ignores[$file])) continue;
        if (empty($sources[$file]) || !empty($sources[$file]['is_wporg'])) continue;
        if (KIG_Utils::checksum_is_stale($file)) {
            $todo[] = $file;
        }
    }

    if (empty($todo)) return;

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = get_plugins();
    $url     = admin_url('admin.php?page=' . self::SLUG_SETTINGS);

    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>' . esc_html__('Third-party plugin versions changed.', 'k-integrity-guard') . '</strong></p>';
    echo '<p>' . esc_html__('Please regenerate checksums for:', 'k-integrity-guard') . '</p><ul>';
    foreach ($todo as $file) {
        $name = isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $file;
        echo '<li>' . esc_html($name . ' (' . $file . ')') . '</li>';
    }
	    echo '</ul><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open settings to regenerate', 'k-integrity-guard') . '</a></p></div>';
	}

	/** Capture theme updates/installs and mark changed themes. */
	public function capture_theme_changes($upgrader, $hook_extra) {
	    if (empty($hook_extra['type']) || 'theme' !== $hook_extra['type']) {
	        return;
	    }
	    $changed = [];
	    if (!empty($hook_extra['themes']) && is_array($hook_extra['themes'])) {
	        $changed = $hook_extra['themes'];
	    }
	    if (empty($changed)) return;

	    $existing = get_transient('kig_changed_themes');
	    if (!is_array($existing)) $existing = [];
	    $merged = array_values(array_unique(array_merge($existing, $changed)));
	    set_transient('kig_changed_themes', $merged, HOUR_IN_SECONDS);
	}

	/** Remove checksum JSON when a theme is deleted. */
	public function handle_deleted_theme($stylesheet, $deleted, $theme) {
	    if (!$deleted) return;
	    KIG_Utils::delete_theme_checksum_file($stylesheet);
	    $existing = get_transient('kig_changed_themes');
	    if (is_array($existing)) {
	        $existing = array_diff($existing, [$stylesheet]);
	        set_transient('kig_changed_themes', $existing, HOUR_IN_SECONDS);
	    }
	}

	/** Show a notice when third-party themes changed version and need checksum regeneration. */
	public function show_changed_themes_notice() {
	    if (!current_user_can('manage_options')) return;

	    $changed = get_transient('kig_changed_themes');
	    if (empty($changed) || !is_array($changed)) return;

	    $opts = get_option(KIG_Settings::OPTION, []);
	    $ignores = $opts['third_party_themes']['ignore'] ?? [];

	    $sources = KIG_Utils::detect_theme_sources();

	    $todo = [];
	    foreach ($changed as $stylesheet) {
	        if (!empty($ignores[$stylesheet])) continue;
	        if (empty($sources[$stylesheet]) || !empty($sources[$stylesheet]['is_wporg'])) continue;
	        if (KIG_Utils::theme_checksum_is_stale($stylesheet)) {
	            $todo[] = $stylesheet;
	        }
	    }

	    if (empty($todo)) return;

	    $url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);

	    echo '<div class="notice notice-warning is-dismissible">';
	    echo '<p><strong>' . esc_html__('Third-party theme versions changed.', 'k-integrity-guard') . '</strong></p>';
	    echo '<p>' . esc_html__('Please regenerate checksums for:', 'k-integrity-guard') . '</p><ul>';
	    foreach ($todo as $stylesheet) {
	        $theme = wp_get_theme($stylesheet);
	        $name  = $theme->exists() ? $theme->get('Name') : $stylesheet;
	        echo '<li>' . esc_html($name . ' (' . $stylesheet . ')') . '</li>';
	    }
	    echo '</ul><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open settings to regenerate', 'k-integrity-guard') . '</a></p></div>';
	}




}

add_action(
	'plugins_loaded',
	static function () {
		new K_Integrity_Guard();
	}
);

register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) {
        update_site_option('kig_show_first_scan_notice', 'yes');
    } else {
        update_option('kig_show_first_scan_notice', 'yes');
    }
    
    // Create scan history table.
    KIG_DB::create_table();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('kig_daily_scan');
});
