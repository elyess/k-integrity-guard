<?php
/**
 * History page template.
 *
 * @package WP_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle bulk and single actions.
if ( isset( $_GET['action'] ) ) {
	$action = sanitize_key( $_GET['action'] );

	// Handle single delete.
	if ( 'delete' === $action && isset( $_GET['scan_id'] ) ) {
		$scan_id = absint( $_GET['scan_id'] );
		if ( check_admin_referer( 'delete_scan_' . $scan_id ) ) {
			WPIG_DB::delete_scan( $scan_id );
			wp_safe_redirect( admin_url( 'admin.php?page=' . WP_Integrity_Guard::SLUG_HISTORY . '&deleted=1' ) );
			exit;
		}
	}

	// Handle view details.
	if ( 'view' === $action && isset( $_GET['scan_id'] ) ) {
		$scan_id = absint( $_GET['scan_id'] );
		$scan    = WPIG_DB::get_scan( $scan_id );

		if ( $scan ) {
			include __DIR__ . '/history-detail.php';
			return;
		}
	}
}

// Handle bulk actions.
if ( isset( $_POST['action'] ) && 'delete' === $_POST['action'] && ! empty( $_POST['scan_ids'] ) ) {
	check_admin_referer( 'bulk-scans' );
	$scan_ids = array_map( 'absint', $_POST['scan_ids'] );
	WPIG_DB::delete_scans( $scan_ids );
	wp_safe_redirect( admin_url( 'admin.php?page=' . WP_Integrity_Guard::SLUG_HISTORY . '&deleted=' . count( $scan_ids ) ) );
	exit;
}

// Handle empty history action.
if ( isset( $_POST['wpig_empty_history'] ) ) {
	check_admin_referer( 'wpig_empty_history' );
	$deleted_count = WPIG_DB::delete_all_scans();
	wp_safe_redirect( admin_url( 'admin.php?page=' . WP_Integrity_Guard::SLUG_HISTORY . '&emptied=' . $deleted_count ) );
	exit;
}

$table = new WPIG_History_Table();
$table->prepare_items();
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				$count = absint( $_GET['deleted'] );
				echo esc_html(
					sprintf(
						/* translators: %d: number of scans deleted */
						_n( '%d scan deleted successfully.', '%d scans deleted successfully.', $count, 'wp-integrity-guard' ),
						$count
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['emptied'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				$count = absint( $_GET['emptied'] );
				echo esc_html(
					sprintf(
						/* translators: %d: number of scans deleted */
						__( 'History emptied. %d scans deleted.', 'wp-integrity-guard' ),
						$count
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'View the complete history of all integrity scans. Click on a scan to see detailed results.', 'wp-integrity-guard' ); ?>
	</p>

	<form method="post" id="wpig-history-form">
		<?php $table->views(); ?>
		<?php $table->display(); ?>
	</form>

	<div class="wpig-history-actions" style="margin-top: 20px;">
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wpig_empty_history' ); ?>
			<input type="hidden" name="wpig_empty_history" value="1" />
			<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all scan history? This action cannot be undone.', 'wp-integrity-guard' ) ); ?>');">
				<?php esc_html_e( 'Empty History', 'wp-integrity-guard' ); ?>
			</button>
		</form>
	</div>
</div>

<style>
.wpig-status-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.wpig-status-success {
	background-color: #d4edda;
	color: #155724;
}
.wpig-status-warning {
	background-color: #fff3cd;
	color: #856404;
}
.wpig-status-error {
	background-color: #f8d7da;
	color: #721c24;
}
.wpig-status-unknown {
	background-color: #e2e3e5;
	color: #383d41;
}

.wpig-context-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
}
.wpig-context-manual {
	background-color: #cfe2ff;
	color: #084298;
}
.wpig-context-cron {
	background-color: #e7e9ee;
	color: #41464b;
}

.wpig-issues-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.wpig-issues-none {
	background-color: #d4edda;
	color: #155724;
}
.wpig-issues-found {
	background-color: #fff3cd;
	color: #856404;
}
</style>
