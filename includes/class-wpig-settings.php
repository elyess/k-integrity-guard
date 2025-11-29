<?php
if (!defined('ABSPATH')) exit;

class WPIG_Settings {
    const OPTION = 'wpig_options';       // single array option
    const PAGE   = 'wp_integrity_guard_settings';      // settings page slug used by Settings API
    const TEXTDOMAIN     = 'wp-integrity-guard'; // text domain

    public function __construct()
    {
        add_action('admin_init', [$this, 'register']);
        // Optional: react to option changes (e.g., schedule/unschedule cron)
        add_action('update_option_' . self::OPTION, [$this, 'handle_cron'], 10, 3);
        // Settings UI assets and AJAX.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wpig_generate_checksum', [$this, 'ajax_generate_checksum']);
        add_action('wp_ajax_wpig_generate_theme_checksum', [$this, 'ajax_generate_theme_checksum']);
    }

    /** Register Settings, Sections, Fields */
    public function register() {
        register_setting(
            self::PAGE,
            self::OPTION,
            [
                'type'              => 'array',
                'default'           => $this->defaults(),
                'sanitize_callback' => [$this, 'sanitize'],
                'show_in_rest'      => false,
            ]
        );

        // Section: General
        add_settings_section(
            'wpig_general',
            __('General', self::TEXTDOMAIN),
            function () {
                echo '<p>' . esc_html__('Basic configuration for WP Integrity Guard.', self::TEXTDOMAIN) . '</p>';
            },
            self::PAGE
        );

        add_settings_field(
            'daily_scan_enabled',
            __('Enable daily scan', self::TEXTDOMAIN),
            [$this, 'field_daily_scan_enabled'],
            self::PAGE,
            'wpig_general'
        );

        // Section: Integrity Targets
        add_settings_section(
            'wpig_targets',
            __('Check integrity for', self::TEXTDOMAIN),
            function () {
                echo '<p>' . esc_html__('Choose what to include in integrity checks.', self::TEXTDOMAIN) . '</p>';
            },
            self::PAGE
        );

        add_settings_field(
            'targets',
            __('Targets', self::TEXTDOMAIN),
            [$this, 'field_targets'],
            self::PAGE,
            'wpig_targets'
        );

        // Note: Third-party plugins and themes sections are rendered separately in the view template
        // to avoid nested table structure issues with WP_List_Table.
    }

    /** Defaults for the option array */
    private function defaults() {
        return [
            'daily_scan_enabled' => false,
            'targets' => [
                'core'    => true,
                'plugins' => true,
                'themes'  => true,
                'third_party_plugins' => false,
            ],
            'third_party' => [
                'ignore' => [],
            ],
            'third_party_themes' => [
                'ignore' => [],
            ],
        ];
    }

    /** Sanitize and normalize input */
    public function sanitize($input) {
        $defaults = $this->defaults();
        $out = $defaults;

        if (is_array($input)) {
            // Checkbox: present => true; absent => false
            $out['daily_scan_enabled'] = !empty($input['daily_scan_enabled']);

            $out['targets']['core']    = !empty($input['targets']['core']);
            $out['targets']['plugins'] = !empty($input['targets']['plugins']);
            $out['targets']['themes']  = !empty($input['targets']['themes']);

            // Third-party ignore map
            $out['third_party']['ignore'] = [];
            if (!empty($input['third_party']['ignore']) && is_array($input['third_party']['ignore'])) {
                foreach ($input['third_party']['ignore'] as $file => $val) {
                    $file = sanitize_text_field($file);
                    $out['third_party']['ignore'][$file] = !empty($val);
                }
            }

            // Third-party themes ignore map
            $out['third_party_themes']['ignore'] = [];
            if (!empty($input['third_party_themes']['ignore']) && is_array($input['third_party_themes']['ignore'])) {
                foreach ($input['third_party_themes']['ignore'] as $stylesheet => $val) {
                    $stylesheet = sanitize_text_field($stylesheet);
                    $out['third_party_themes']['ignore'][$stylesheet] = !empty($val);
                }
            }
        }

        return $out;
    }

    /** Render: Enable daily scan */
    public function field_daily_scan_enabled() {
        $opts = $this->get_options();
        printf(
            '<label><input type="checkbox" name="%1$s[daily_scan_enabled]" %2$s /> %3$s</label>',
            esc_attr(self::OPTION),
            checked(!empty($opts['daily_scan_enabled']), true, false),
            esc_html__('Run the integrity scan once per day (via WP-Cron).', self::TEXTDOMAIN)
        );
    }

    /** Render: Targets (core, plugins, themes) */
    public function field_targets() {
        $opts = $this->get_options();
        $targets = $opts['targets'] ?? [];

        $rows = [
            'core'    => __('WordPress Core', self::TEXTDOMAIN),
            'plugins' => __('Plugins', self::TEXTDOMAIN),
            'themes'  => __('Themes', self::TEXTDOMAIN),
        ];

        echo '<fieldset>';
        foreach ($rows as $key => $label) {
            printf(
                '<label style="display:block;margin:4px 0;">
                    <input type="checkbox" name="%1$s[targets][%2$s]" %3$s />
                    %4$s
                 </label>',
                esc_attr(self::OPTION),
                esc_attr($key),
                checked(!empty($targets[$key]), true, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';
    }

    /** Helper: get merged options with defaults */
    public function get_options() {
        $stored = get_option(self::OPTION, []);
        return wp_parse_args(is_array($stored) ? $stored : [], $this->defaults());
    }

    /** Optional: schedule/unschedule daily event when the option changes */
    public function handle_cron($old_value, $value, $option) {
        if ($option !== self::OPTION) return;

        $was = !empty($old_value['daily_scan_enabled']);
        $now = !empty($value['daily_scan_enabled']);

        if ($now && !$was && !wp_next_scheduled('wpig_daily_scan')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpig_daily_scan');
        } elseif (!$now && $was) {
            $timestamp = wp_next_scheduled('wpig_daily_scan');
            if ($timestamp) wp_unschedule_event($timestamp, 'wpig_daily_scan');
        }
    }

    /**
     * Enqueue settings assets only on our settings page.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        $is_settings = isset( $_GET['page'] ) && $_GET['page'] === self::PAGE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $is_settings ) {
            return;
        }

        $plugin_file = dirname( __DIR__ ) . '/wp-integrity-guard.php';
        $css_url     = plugins_url( 'assets/css/settings.css', $plugin_file );
        $script_url  = plugins_url( 'assets/js/settings.js', $plugin_file );

        // Enqueue CSS.
        wp_enqueue_style(
            'wpig-settings',
            $css_url,
            array(),
            '1.0.0'
        );

        // Enqueue JavaScript.
        wp_enqueue_script(
            'wpig-settings',
            $script_url,
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script(
            'wpig-settings',
            'wpigSettings',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpig_generate_checksum' ),
                'l10n'    => array(
                    'upToDate' => __( 'Up to date', self::TEXTDOMAIN ),
                    'working'  => __( 'Working…', self::TEXTDOMAIN ),
                    'error'    => __( 'Failed to generate checksum.', self::TEXTDOMAIN ),
                ),
            )
        );
    }

    /**
     * Render the third-party plugins table.
     *
     * Uses WP_List_Table for proper WordPress-compliant table display.
     *
     * @return void
     */
    public function field_third_party_list() {
        if ( ! class_exists( 'WPIG_Third_Party_Plugins_Table' ) ) {
            require_once dirname( __FILE__ ) . '/class-wpig-third-party-table.php';
        }
        if ( ! class_exists( 'WPIG_Utils' ) ) {
            require_once dirname( __FILE__ ) . '/class-wpig-utils.php';
        }

        $opts    = $this->get_options();
        $ignores = isset( $opts['third_party']['ignore'] ) ? $opts['third_party']['ignore'] : array();
        $sources = WPIG_Utils::detect_plugin_sources();

        $items = array();

        foreach ( $sources as $file => $plugin ) {
            if ( ! empty( $plugin['is_wporg'] ) ) {
                continue;
            }

            $name    = '' !== $plugin['name'] ? $plugin['name'] : $file;
            $version = '' !== $plugin['version'] ? $plugin['version'] : __( 'Unknown', self::TEXTDOMAIN );

            $status_text = WPIG_Utils::checksum_is_stale( $file )
                ? __( 'Needs update', self::TEXTDOMAIN )
                : __( 'Up to date', self::TEXTDOMAIN );

            $items[] = array(
                'name'    => esc_html( $name ),
                'version' => esc_html( $version ),
                'status'  => sprintf(
                    '<span class="wpig-status" data-plugin="%1$s">%2$s</span>',
                    esc_attr( $file ),
                    esc_html( $status_text )
                ),
                'ignore'  => sprintf(
                    '<label><input type="checkbox" name="%1$s[third_party][ignore][%2$s]" %3$s /> <span class="screen-reader-text">%4$s</span></label>',
                    esc_attr( self::OPTION ),
                    esc_attr( $file ),
                    checked( ! empty( $ignores[ $file ] ), true, false ),
                    esc_html__( 'Ignore', self::TEXTDOMAIN )
                ),
                'action'  => sprintf(
                    '<button type="button" class="button wpig-generate" data-plugin="%1$s">%2$s</button> <span class="spinner" style="float:none;"></span>',
                    esc_attr( $file ),
                    esc_html__( 'Update Checksum', self::TEXTDOMAIN )
                ),
            );
        }

        $table = new WPIG_Third_Party_Plugins_Table(
            array(
                'items' => $items,
            )
        );
        $table->prepare_items();
        $table->display();
    }

    /**
     * Render the third-party themes table.
     *
     * Uses WP_List_Table for proper WordPress-compliant table display.
     *
     * @return void
     */
    public function field_third_party_themes_list() {
        if ( ! class_exists( 'WPIG_Third_Party_Themes_Table' ) ) {
            require_once dirname( __FILE__ ) . '/class-wpig-third-party-table.php';
        }
        if ( ! class_exists( 'WPIG_Utils' ) ) {
            require_once dirname( __FILE__ ) . '/class-wpig-utils.php';
        }

        $opts    = $this->get_options();
        $ignores = isset( $opts['third_party_themes']['ignore'] ) ? $opts['third_party_themes']['ignore'] : array();
        $sources = WPIG_Utils::detect_theme_sources();

        $items = array();

        foreach ( $sources as $stylesheet => $theme ) {
            if ( ! empty( $theme['is_wporg'] ) ) {
                continue;
            }

            $name    = '' !== $theme['name'] ? $theme['name'] : $stylesheet;
            $version = '' !== $theme['version'] ? $theme['version'] : __( 'Unknown', self::TEXTDOMAIN );

            $status_text = WPIG_Utils::theme_checksum_is_stale( $stylesheet )
                ? __( 'Needs update', self::TEXTDOMAIN )
                : __( 'Up to date', self::TEXTDOMAIN );

            $items[] = array(
                'name'    => esc_html( $name ),
                'version' => esc_html( $version ),
                'status'  => sprintf(
                    '<span class="wpig-theme-status" data-theme="%1$s">%2$s</span>',
                    esc_attr( $stylesheet ),
                    esc_html( $status_text )
                ),
                'ignore'  => sprintf(
                    '<label><input type="checkbox" name="%1$s[third_party_themes][ignore][%2$s]" %3$s /> <span class="screen-reader-text">%4$s</span></label>',
                    esc_attr( self::OPTION ),
                    esc_attr( $stylesheet ),
                    checked( ! empty( $ignores[ $stylesheet ] ), true, false ),
                    esc_html__( 'Ignore', self::TEXTDOMAIN )
                ),
                'action'  => sprintf(
                    '<button type="button" class="button wpig-theme-generate" data-theme="%1$s">%2$s</button> <span class="spinner" style="float:none;"></span>',
                    esc_attr( $stylesheet ),
                    esc_html__( 'Update Checksum', self::TEXTDOMAIN )
                ),
            );
        }

        $table = new WPIG_Third_Party_Themes_Table(
            array(
                'items' => $items,
            )
        );
        $table->prepare_items();
        $table->display();
    }

    /** AJAX handler: Generate checksum JSON for a plugin */
    public function ajax_generate_checksum() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Access denied.', self::TEXTDOMAIN)], 403);
        }
        check_ajax_referer('wpig_generate_checksum', 'nonce');

        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field(wp_unslash($_POST['plugin_file'])) : '';
        if ('' === $plugin_file) {
            wp_send_json_error(['message' => __('Invalid plugin.', self::TEXTDOMAIN)], 400);
        }

        if (!class_exists('WPIG_Utils')) {
            require_once dirname(__FILE__) . '/class-wpig-utils.php';
        }

        $result = WPIG_Utils::generate_plugin_checksum_json($plugin_file);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'status' => __('Up to date', self::TEXTDOMAIN),
        ]);
    }

    /**
     * AJAX handler: Generate checksum JSON for a theme.
     *
     * @return void
     */
    public function ajax_generate_theme_checksum() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Access denied.', self::TEXTDOMAIN)], 403);
        }
        check_ajax_referer('wpig_generate_checksum', 'nonce');

        $stylesheet = isset($_POST['stylesheet']) ? sanitize_text_field(wp_unslash($_POST['stylesheet'])) : '';
        if ('' === $stylesheet) {
            wp_send_json_error(['message' => __('Invalid theme.', self::TEXTDOMAIN)], 400);
        }

        if (!class_exists('WPIG_Utils')) {
            require_once dirname(__FILE__) . '/class-wpig-utils.php';
        }

        $result = WPIG_Utils::generate_theme_checksum_json($stylesheet);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'status' => __('Up to date', self::TEXTDOMAIN),
        ]);
    }
}
