<?php
/**
 * Plugin Name: WP Integrity Guard
 * Description: Monitors and protects your WordPress core files, themes, and plugins from unauthorized changes.
 * Version: 1.0.0
 * Author: Elyes Zouaghi
 * Author URI: https://github.com/elyess/wp-integrity-guard
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-integrity-guard
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-wpig-settings.php';
require_once __DIR__ . '/includes/class-wpig-utils.php';
require_once __DIR__ . '/includes/class-wpig-scan.php';

class WP_Integrity_Guard {

	const VERSION = '1.0.0';
	const TEXTDOMAIN = 'wp-integrity-guard';

	const CAPABILITY = 'manage_options';

	//const SLUG_PLUGIN = 'wp_integrity_guard';
	const SLUG_SCAN = 'wp_integrity_guard_scan';
	const SLUG_SETTINGS = 'wp_integrity_guard_settings';
	const SLUG_HISTORY = 'wp_integrity_guard_history';

	private string $plugin_file;

	private WPIG_Settings $settings;
	private WPIG_Scan $scan;

	public function __construct() {
		$this->plugin_file = __FILE__;
		$this->settings = new WPIG_Settings();
		$this->scan = new WPIG_Scan( $this->plugin_file, self::SLUG_SCAN, $this->settings, self::TEXTDOMAIN, self::VERSION );
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
add_action('wp_ajax_wpig_dismiss_first_scan_notice', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    check_ajax_referer('wpig_dismiss_first_scan_notice', 'nonce');

    // Flip the global flag off
    if (is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network(plugin_basename(__FILE__))) {
        update_site_option('wpig_show_first_scan_notice', 'no');
    } else {
        update_option('wpig_show_first_scan_notice', 'no');
    }

    wp_send_json_success();
});

// Load a tiny JS when we might show the notice (cheap guard)
add_action('admin_enqueue_scripts', function ($hook) {
    // Only enqueue if the flag is on to avoid noise
    $flag = is_multisite() && is_plugin_active_for_network(plugin_basename(__FILE__))
        ? get_site_option('wpig_show_first_scan_notice', 'no')
        : get_option('wpig_show_first_scan_notice', 'no');

    if ($flag !== 'yes') return;

    wp_add_inline_script(
        'jquery-core', // ensure jQuery loaded first; or register your own handle
        "(function($){
            $(document).on('click', '.wpig-first-scan-notice .notice-dismiss', function(){
                var \$n = $(this).closest('.wpig-first-scan-notice');
                var nonce = \$n.data('nonce');
                $.post(ajaxurl, {
                    action: 'wpig_dismiss_first_scan_notice',
                    nonce: nonce
                });
            });
        })(jQuery);"
    );
});		
	}

	public function register_admin_menu() {
		add_menu_page(
			__('WP Integrity Guard', self::TEXTDOMAIN),
			__('Integrity Guard', self::TEXTDOMAIN),
			self::CAPABILITY,
			self::SLUG_SCAN,
			[$this, 'render_scan_page'],
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			self::SLUG_SCAN,
			__('Scan', self::TEXTDOMAIN),
			__('Scan', self::TEXTDOMAIN),
			self::CAPABILITY,
			self::SLUG_SCAN,
			[$this, 'render_scan_page']
		);

		add_submenu_page(
			self::SLUG_SCAN,
			__('Settings', self::TEXTDOMAIN),
			__('Settings', self::TEXTDOMAIN),
			self::CAPABILITY,
			self::SLUG_SETTINGS,
			[$this, 'render_settings_page']
		);

		add_submenu_page(
			self::SLUG_SCAN,
			__('History', self::TEXTDOMAIN),
			__('History', self::TEXTDOMAIN),
			self::CAPABILITY,
			self::SLUG_HISTORY,
			[$this, 'render_history_page']
		);
	}

	public function plugin_action_links(array $links) {
		$settings_url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);
		array_unshift(
				$links,
				'<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', self::TEXTDOMAIN) . '</a>',
				'<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_SCAN)) . '">' . esc_html__('Scan Now', self::TEXTDOMAIN) . '</a>'
		);
		return $links;
	}

	public function render_scan_page(): void {
			if (!current_user_can(self::CAPABILITY)) wp_die(__('You do not have permission.', self::TEXTDOMAIN));
			$scan = $this->scan;
			$settings = $this->settings;
			include __DIR__ . '/includes/views/scan.php';
	}

	public function render_history_page(): void {
			if (!current_user_can(self::CAPABILITY)) wp_die(__('You do not have permission.', self::TEXTDOMAIN));
			include __DIR__ . '/includes/views/history.php';
	}

	public function render_settings_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission.', self::TEXTDOMAIN ) );
			}
			$settings = $this->settings;
			include __DIR__ . '/includes/views/settings.php';
	}

private function get_global_notice_flag() {
    if (is_multisite() && $this->is_network_active()) {
        return get_site_option('wpig_show_first_scan_notice', 'no');
    }
    return get_option('wpig_show_first_scan_notice', 'no');
}

private function set_global_notice_flag($value) {
    if (is_multisite() && $this->is_network_active()) {
        return update_site_option('wpig_show_first_scan_notice', $value);
    }
    return update_option('wpig_show_first_scan_notice', $value);
}

// Simple network-active check (works in most plugin setups)
private function is_network_active() {
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    // Adjust plugin basename if needed
    return is_multisite() && is_plugin_active_for_network(plugin_basename(__FILE__));
}

public function show_first_scan_notice() {
    if (!current_user_can('manage_options')) return;

    // Only show if still globally enabled
    if ($this->get_global_notice_flag() !== 'yes') return;

    // Skip on the scan page itself
    if (isset($_GET['page']) && $_GET['page'] === self::SLUG_SCAN) return;

    $scan_url     = add_query_arg(['page' => self::SLUG_SCAN, 'run_first_scan' => 1], admin_url('admin.php'));
    $settings_url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);

    // Security nonce for AJAX dismiss
    $nonce = wp_create_nonce('wpig_dismiss_first_scan_notice');

    echo '<div class="notice notice-info is-dismissible wpig-first-scan-notice" data-nonce="' . esc_attr($nonce) . '">';
    echo '<p><strong>' . esc_html__('WP Integrity Guard is active.', self::TEXTDOMAIN) . '</strong></p>';
    echo '<p>' . esc_html__('You can run your first scan now or review the scan settings.', self::TEXTDOMAIN) . '</p>';
    echo '<p>';
    echo '<a href="' . esc_url($scan_url) . '" class="button button-primary">' . esc_html__('Run first scan', self::TEXTDOMAIN) . '</a> ';
    echo '<a href="' . esc_url($settings_url) . '" class="button">' . esc_html__('Review settings', self::TEXTDOMAIN) . '</a>';
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

    $existing = get_transient('wpig_changed_plugins');
    if (!is_array($existing)) $existing = [];
	    $merged = array_values(array_unique(array_merge($existing, $changed)));
	    set_transient('wpig_changed_plugins', $merged, HOUR_IN_SECONDS);
	}

/** Remove checksum JSON when a plugin is deleted. */
public function handle_deleted_plugin($plugin_file, $deleted) {
    if (!$deleted) return;
    if (class_exists('WPIG_Utils')) {
        WPIG_Utils::delete_checksum_file($plugin_file);
    }
    $existing = get_transient('wpig_changed_plugins');
    if (is_array($existing)) {
        $existing = array_diff($existing, [$plugin_file]);
        set_transient('wpig_changed_plugins', $existing, HOUR_IN_SECONDS);
    }
}

/** Show a notice when third-party plugins changed version and need checksum regeneration. */
	public function show_changed_plugins_notice() {
	    if (!current_user_can('manage_options')) return;

    $changed = get_transient('wpig_changed_plugins');
    if (empty($changed) || !is_array($changed)) return;

    // Respect ignores and only third-party.
    $opts = get_option(WPIG_Settings::OPTION, []);
    $ignores = $opts['third_party']['ignore'] ?? [];

    if (!class_exists('WPIG_Utils')) {
        require_once __DIR__ . '/includes/class-wpig-utils.php';
    }
    $sources = WPIG_Utils::detect_plugin_sources();

    $todo = [];
    foreach ($changed as $file) {
        if (!empty($ignores[$file])) continue;
        if (empty($sources[$file]) || !empty($sources[$file]['is_wporg'])) continue;
        if (WPIG_Utils::checksum_is_stale($file)) {
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
    echo '<p><strong>' . esc_html__('Third-party plugin versions changed.', self::TEXTDOMAIN) . '</strong></p>';
    echo '<p>' . esc_html__('Please regenerate checksums for:', self::TEXTDOMAIN) . '</p><ul>';
    foreach ($todo as $file) {
        $name = isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $file;
        echo '<li>' . esc_html($name . ' (' . $file . ')') . '</li>';
    }
	    echo '</ul><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open settings to regenerate', self::TEXTDOMAIN) . '</a></p></div>';
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

	    $existing = get_transient('wpig_changed_themes');
	    if (!is_array($existing)) $existing = [];
	    $merged = array_values(array_unique(array_merge($existing, $changed)));
	    set_transient('wpig_changed_themes', $merged, HOUR_IN_SECONDS);
	}

	/** Remove checksum JSON when a theme is deleted. */
	public function handle_deleted_theme($stylesheet, $deleted, $theme) {
	    if (!$deleted) return;
	    if (class_exists('WPIG_Utils')) {
	        WPIG_Utils::delete_theme_checksum_file($stylesheet);
	    }
	    $existing = get_transient('wpig_changed_themes');
	    if (is_array($existing)) {
	        $existing = array_diff($existing, [$stylesheet]);
	        set_transient('wpig_changed_themes', $existing, HOUR_IN_SECONDS);
	    }
	}

	/** Show a notice when third-party themes changed version and need checksum regeneration. */
	public function show_changed_themes_notice() {
	    if (!current_user_can('manage_options')) return;

	    $changed = get_transient('wpig_changed_themes');
	    if (empty($changed) || !is_array($changed)) return;

	    $opts = get_option(WPIG_Settings::OPTION, []);
	    $ignores = $opts['third_party_themes']['ignore'] ?? [];

	    if (!class_exists('WPIG_Utils')) {
	        require_once __DIR__ . '/includes/class-wpig-utils.php';
	    }
	    $sources = WPIG_Utils::detect_theme_sources();

	    $todo = [];
	    foreach ($changed as $stylesheet) {
	        if (!empty($ignores[$stylesheet])) continue;
	        if (empty($sources[$stylesheet]) || !empty($sources[$stylesheet]['is_wporg'])) continue;
	        if (WPIG_Utils::theme_checksum_is_stale($stylesheet)) {
	            $todo[] = $stylesheet;
	        }
	    }

	    if (empty($todo)) return;

	    $url = admin_url('admin.php?page=' . self::SLUG_SETTINGS);

	    echo '<div class="notice notice-warning is-dismissible">';
	    echo '<p><strong>' . esc_html__('Third-party theme versions changed.', self::TEXTDOMAIN) . '</strong></p>';
	    echo '<p>' . esc_html__('Please regenerate checksums for:', self::TEXTDOMAIN) . '</p><ul>';
	    foreach ($todo as $stylesheet) {
	        $theme = wp_get_theme($stylesheet);
	        $name  = $theme->exists() ? $theme->get('Name') : $stylesheet;
	        echo '<li>' . esc_html($name . ' (' . $stylesheet . ')') . '</li>';
	    }
	    echo '</ul><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open settings to regenerate', self::TEXTDOMAIN) . '</a></p></div>';
	}




}

add_action(
	'plugins_loaded',
	static function () {
		new WP_Integrity_Guard();
	}
);

register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) {
        update_site_option('wpig_show_first_scan_notice', 'yes');
    } else {
        update_option('wpig_show_first_scan_notice', 'yes');
    }
});
