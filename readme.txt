=== K'Integrity Guard ===
Contributors: elyesz
Donate link: https://github.com/sponsors/elyess
Tags: security, integrity, malware, checksum, file monitoring
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect tampered WordPress core, plugin and theme files by comparing them with official WordPress.org checksums or your own snapshots.

== Description ==

Attackers who get into a WordPress site usually leave something behind: a changed core file, a backdoor in a plugin, a new PHP file in a theme. K'Integrity Guard finds these changes. It compares the files on your server with known-good fingerprints (checksums) and lists every file that was modified, removed or added.

K'Integrity Guard only reads files. It never changes, quarantines or deletes anything, so it is safe to run on a live site.

= What it checks =

* **WordPress core** – every file in `wp-admin`, `wp-includes` and the site root is compared with the official checksums for your WordPress version and language. Unexpected files in `wp-admin` and `wp-includes`, and unexpected PHP files in the site root, are reported too.
* **Default themes that ship with WordPress** – checked together with core.
* **Plugins from WordPress.org** – compared with the official checksums for the installed version.
* **Themes and third-party (premium or custom) plugins** – WordPress.org does not publish checksums for these, so you take a snapshot of the files while you know they are clean. Later scans report every change since that snapshot.

= Features =

* Scan on demand from the admin, with live progress.
* Optional daily scan through WP-Cron.
* Scan history with a detail view for every scan, filters, and bulk delete. Entries older than 90 days are removed automatically.
* Snapshots for themes and third-party plugins, with a reminder when a plugin or theme update makes a snapshot outdated.
* Accept your own edits: if you changed a default theme directly, take a snapshot of it and only later changes are reported.
* A version number that changed since the snapshot is always reported, so editing a version header cannot hide changes.
* Clean uninstall: settings, scan history and snapshots are removed when you delete the plugin.

= What it does not do =

* It does not remove malware or repair files. Use it to find what changed, then restore the files from a clean copy.
* It does not send email alerts. Check the History page, or the Scan page after a scheduled scan.

== External services ==

To know what a clean file looks like, K'Integrity Guard downloads official checksums from WordPress.org. It sends no personal data and nothing about your site's content.

* **WordPress core checksums** – `https://api.wordpress.org/core/checksums/1.0/`. Used when a scan includes WordPress core. The request contains your WordPress version and site language. The result is cached for one day.
* **WordPress.org plugin checksums** – `https://downloads.wordpress.org/plugin-checksums/`. Used when a scan includes plugins, once for each plugin that is hosted on WordPress.org. The request contains the plugin's slug and version.

Both services are run by WordPress.org: [privacy policy](https://wordpress.org/about/privacy/). Snapshots of themes and third-party plugins are created and compared locally and are never sent anywhere.

== Installation ==

1. In your admin, go to **Plugins → Add New**, search for "K'Integrity Guard", then install and activate it. Or upload the `k-integrity-guard` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.
2. Open **Integrity Guard → Scan** and start your first scan.
3. Optional: in **Integrity Guard → Settings**, enable the daily scan and choose what it checks.
4. Optional: in the same screen, create snapshots for your themes and third-party plugins while you know they are clean.

== Frequently Asked Questions ==

= Does the plugin change my files? =

No. It only reads files and compares their checksums. It never modifies, moves or deletes anything on your site.

= A scan found differences. What should I do? =

Look at the list of files. If you did not change them yourself, treat the site as compromised: restore the files from a clean copy (for example by reinstalling WordPress, the plugin or the theme), change your passwords, and look for the way in. If the changes are your own, see the next questions.

= Why are themes not checked against WordPress.org? =

WordPress.org publishes checksums for WordPress core and for plugins, but not for themes. Create a snapshot of each theme in **Settings → Theme Snapshots** while you know it is clean. The default themes that ship with your WordPress version are checked with core and need no snapshot.

= I edited a default theme (Twenty Twenty-Five, etc.) directly. Will it always show up? =

Yes, until you tell the plugin the changes are yours. Create a snapshot of that theme in Settings. Core scans then accept your edits and report only changes made after the snapshot, together with a short note that counts the accepted files. Better still, move your changes to a child theme: edits to a default theme are lost the next time WordPress updates it.

= How do I check premium or custom plugins? =

Enable **Third-Party Plugins (snapshot)** in Settings and create a snapshot for each plugin under **Third-party Plugins**. After you update such a plugin, create a new snapshot; the plugin reminds you.

= Why is a file reported as "unexpected"? =

It is a PHP file that is not part of WordPress (or of the plugin or theme), for example a leftover file or a backdoor. Files such as `robots.txt` or `.htaccess` in the site root are not reported, because every site has its own.

= Where are snapshots stored? =

In `wp-content/uploads/k-integrity-guard/checksums/`. The plugin adds an `index.php` and an `.htaccess` file there to block direct web access on Apache servers. On Nginx, block access to that folder in your server configuration if you do not want snapshots to be publicly readable.

= Does it work on multisite? =

Each site in a network has its own settings and scan history, and the plugin can be activated per site. A network-wide dashboard is not available, and multisite networks have not been fully tested yet.

= What happens when I delete the plugin? =

Its settings, scan history table, cached data and snapshots are removed.

== Screenshots ==

1. A scan in progress and the results: modified, missing and unexpected files, grouped by WordPress core, plugins and themes.
2. Settings: daily scan, scan targets, and snapshots for third-party plugins and themes.
3. Scan history with status, number of issues and filters.
4. The details of one scan.

== Changelog ==

= 1.2.0 =
* New: snapshot checks for themes. WordPress.org publishes no theme checksums, so themes are now verified against your own snapshot.
* New: accept your own edits to a default theme with a snapshot; a note counts the accepted files and shows the snapshot date.
* New: a note explains edited default themes and recommends a child theme.
* New: third-party plugins and themes can be included in the daily scan.
* New: scan history older than 90 days is removed automatically after the daily scan.
* New: uninstall removes all plugin data; deactivation removes the scheduled scan.
* Fix: the History page did not list any scans, and bulk delete did nothing.
* Fix: saving one section of the settings page erased the other sections and could switch off the daily scan.
* Fix: WordPress.org plugin checksums were read incorrectly, so every file of every plugin was reported as modified.
* Fix: files of non-default themes were reported as unexpected core files, and deleted default themes as missing files.
* Fix: changes in WordPress core were not counted in the scan history status.
* Fix: Hello Dolly and other single-file plugins were reported as modified on every site.
* Fix: single-file third-party plugins were compared with the whole plugins folder.
* Fix: a changed version number no longer hides a modified third-party plugin.
* Fix: deleting scans from the History page now redirects correctly and reports the right count.
* Improvement: unexpected files in the site root are limited to PHP files, so `robots.txt` and similar files are no longer reported.
* Improvement: snapshots are protected from direct web access on Apache.
* Improvement: core checksums are cached for a day, which makes scans faster.
* Tested with WordPress 7.1.

= 1.1.0 =
* Added scan history with a custom database table.
* View past scans with filtering and sorting.
* Detailed view of every scan.
* Bulk delete and history management.

= 1.0.0 =
* Initial release with scan dashboard, daily scheduling, and third-party checksum tools.

== Upgrade Notice ==

= 1.2.0 =
Fixes the empty History page, false alarms for plugins and themes, and settings being lost. Themes are now checked against snapshots: create them in Settings.
