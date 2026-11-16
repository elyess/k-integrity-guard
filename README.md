# K'Integrity Guard

A WordPress plugin that detects unexpected changes to WordPress core, plugin and theme files. It compares the files on the server with the official WordPress.org checksums, or with snapshots taken while the files were known to be clean.

The user-facing description, FAQ and changelog are in [`readme.txt`](readme.txt), which is what WordPress.org displays.

- Requires WordPress 6.0 or later and PHP 7.4 or later. Tested up to WordPress 7.1.
- License: GPLv2 or later.

## How it works

| Target | Compared with |
| --- | --- |
| WordPress core and the default themes shipped with it | `api.wordpress.org/core/checksums/1.0/` (cached for a day) |
| Plugins hosted on WordPress.org | `downloads.wordpress.org/plugin-checksums/{slug}/{version}.json` |
| Themes and third-party plugins | Local snapshots in `wp-content/uploads/k-integrity-guard/checksums/` |

A scan runs as a job in small steps, so large sites do not hit PHP time limits. The admin page advances the job through AJAX. The daily WP-Cron event runs it in one go. Results go to the custom table `{prefix}kig_scan_history`. Scans older than 90 days are removed after each daily scan.

## Code layout

| Path | Purpose |
| --- | --- |
| `k-integrity-guard.php` | Bootstrap, admin menu, notices, history actions, activation and deactivation. |
| `includes/class-kig-scan.php` | Scan jobs, core checks, the shared plugin and theme pipeline, AJAX endpoints and cron. |
| `includes/class-kig-utils.php` | Plugin and theme source detection, snapshot files. |
| `includes/class-kig-settings.php` | Settings API, snapshot tables and their AJAX actions. |
| `includes/class-kig-db.php` | Scan history table. |
| `includes/views/` | Admin page templates. |
| `uninstall.php` | Removes options, the table, transients and snapshots. |
| `.wordpress-org/` | Banners, icons and screenshots for the WordPress.org listing. They are not part of the plugin zip. |

## Tests

The tests run against a real WordPress test install. You only need Docker:

```sh
bin/run-tests.sh              # WordPress 7.1.4, PHP 8.3
bin/run-tests.sh 6.0 7.4      # oldest supported versions
```

The script starts a MariaDB container. It downloads WordPress, the matching test library from `wordpress-develop`, PHPUnit 9.6 and the PHPUnit Polyfills, then runs `tests/`.

## Release

1. Update the version in three places: `Version:` in `k-integrity-guard.php`, `K_Integrity_Guard::VERSION`, and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. Commit, then build the zip:

   ```sh
   bin/build-zip.sh   # writes dist/k-integrity-guard-<version>.zip
   ```

   The zip contains only the committed plugin files. `.gitattributes` and `.distignore` keep out tests, build scripts and `.wordpress-org/`.
3. Check the zip with [Plugin Check](https://wordpress.org/plugins/plugin-check/) on a test site: install the zip, then run `wp plugin check k-integrity-guard`.

### First submission to WordPress.org

1. Log in to WordPress.org with the account named under `Contributors:` in `readme.txt`.
2. Upload the zip at <https://wordpress.org/plugins/developers/add/>.
3. Answer the review team's email. Approval gives you an SVN repository at `https://plugins.svn.wordpress.org/k-integrity-guard/`.

### Publishing a version via SVN

```sh
svn co https://plugins.svn.wordpress.org/k-integrity-guard svn
unzip -o dist/k-integrity-guard-1.2.0.zip -d /tmp && rsync -a --delete /tmp/k-integrity-guard/ svn/trunk/
cp .wordpress-org/* svn/assets/
cd svn && svn add --force trunk assets && svn cp trunk tags/1.2.0
svn ci -m "Release 1.2.0"
```

`svn add --force` does not register deleted files. Run `svn status` before you commit, and `svn rm` any file marked `!`.
