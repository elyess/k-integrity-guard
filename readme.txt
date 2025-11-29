=== K'Integrity Guard ===
Contributors: elyesz
Donate link: https://github.com/sponsors/elyess
Tags: security, integrity, malware, checksum, maintenance
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==
K'Integrity Guard monitors your WordPress core, plugins, and themes for unexpected file changes. Run scans on demand or let the plugin schedule a daily check so you can catch tampering early.

= Features =
* On demand integrity scans for WordPress core, plugins, and themes
* Optional daily scan driven by WP-Cron
* Tracks third-party plugins and themes that are not in the WordPress.org catalog
* Generates and refreshes checksum files after plugin or theme updates
* Highlights modified, missing, and newly added files in scan results

== Installation ==
1. Upload the plugin files to the /wp-content/plugins/k-integrity-guard directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Open "K'Integrity Guard" in the admin menu to run your first scan and configure settings.
4. (Optional) Enable the daily scan toggle on the Settings tab if you want automated checks.

== Frequently Asked Questions ==

= Do I need to run scans manually? =
You can start a scan whenever you want from the Scan tab. If you enable the daily scan option, the plugin also runs once per day using WP-Cron.

= Does the plugin modify my files? =
No. The plugin only reads files to compare their contents against known checksums.

= Does it support multisite? =
Yes. Network administrators can run scans and manage settings across the network.

== Screenshots ==
1. Scan page showing progress and results.
2. Settings page with daily scan and third-party checksum tools.

== Changelog ==
= 1.1.0 =
* Added comprehensive scan history feature with custom database table
* View all past scans with filtering and sorting options
* Detailed scan results view showing all file changes
* Bulk delete and history management actions

= 1.0.0 =
* Initial release with scan dashboard, daily scheduling, and third-party checksum tools.

== Upgrade Notice ==
= 1.1.0 =
New scan history feature! Track all your integrity scans over time with detailed results.

= 1.0.0 =
Initial release. Install to start monitoring WordPress core, plugins, and themes.
