<?php
/**
 * Uninstall cleanup for K'Integrity Guard.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin data for the current site.
 *
 * @return void
 */
function kig_uninstall_site() {
	global $wpdb;

	foreach ( array( 'kig_options', 'kig_last_scan_result', 'kig_db_version', 'kig_show_first_scan_notice' ) as $option ) {
		delete_option( $option );
	}

	// Covers kig_changed_plugins/themes, scan job and core checksum cache transients.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_kig_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_kig_' ) . '%'
		)
	);

	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'kig_scan_history' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared

	wp_clear_scheduled_hook( 'kig_daily_scan' );

	$uploads = wp_upload_dir( null, false );
	kig_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'k-integrity-guard' );
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function kig_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		/** @var SplFileInfo $f */
		if ( $f->isDir() && ! $f->isLink() ) {
			rmdir( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			wp_delete_file( $f->getPathname() );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

if ( is_multisite() ) {
	foreach ( array( 'kig_options', 'kig_last_scan_result', 'kig_db_version', 'kig_show_first_scan_notice' ) as $option ) {
		delete_site_option( $option );
	}
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		kig_uninstall_site();
		restore_current_blog();
	}
} else {
	kig_uninstall_site();
}
