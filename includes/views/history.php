<?php
/**
 * History page template.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delete, bulk delete and empty-history are handled in K_Integrity_Guard::handle_history_actions() before output.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display parameters (view ID and result counts); every deletion is nonce-checked there.
// Handle view details.
if ( isset( $_GET['action'], $_GET['scan_id'] ) && 'view' === sanitize_key( $_GET['action'] ) ) {
	$scan_id = absint( $_GET['scan_id'] );
	$scan    = KIG_DB::get_scan( $scan_id );

	if ( $scan ) {
		include __DIR__ . '/history-detail.php';
		return;
	}
}

$table = new KIG_History_Table();
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
						_n( '%d scan deleted successfully.', '%d scans deleted successfully.', $count, 'k-integrity-guard' ),
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
						__( 'History emptied. %d scans deleted.', 'k-integrity-guard' ),
						$count
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'View the complete history of all integrity scans. Click on a scan to see detailed results.', 'k-integrity-guard' ); ?>
	</p>

	<form method="post" id="kig-history-form">
		<?php $table->views(); ?>
		<?php $table->display(); ?>
	</form>

	<div class="kig-history-actions" style="margin-top: 20px;">
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'kig_empty_history' ); ?>
			<input type="hidden" name="kig_empty_history" value="1" />
			<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all scan history? This action cannot be undone.', 'k-integrity-guard' ) ); ?>');">
				<?php esc_html_e( 'Empty History', 'k-integrity-guard' ); ?>
			</button>
		</form>
	</div>
</div>

<style>
.kig-status-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.kig-status-success {
	background-color: #d4edda;
	color: #155724;
}
.kig-status-warning {
	background-color: #fff3cd;
	color: #856404;
}
.kig-status-error {
	background-color: #f8d7da;
	color: #721c24;
}
.kig-status-unknown {
	background-color: #e2e3e5;
	color: #383d41;
}

.kig-context-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
}
.kig-context-manual {
	background-color: #cfe2ff;
	color: #084298;
}
.kig-context-cron {
	background-color: #e7e9ee;
	color: #41464b;
}

.kig-issues-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.kig-issues-none {
	background-color: #d4edda;
	color: #155724;
}
.kig-issues-found {
	background-color: #fff3cd;
	color: #856404;
}
</style>
