<?php
/**
 * Scan screen template.
 *
 * @var KIG_Scan     $scan     Scan helper.
 * @var KIG_Settings $settings Settings handler.
 *
 * @package K_Integrity_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$targets  = $scan->get_targets();
$options  = $settings->get_options();
$defaults = $options['targets'] ?? [];
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form id="wpig-scan-form">
		<?php wp_nonce_field( 'kig_start_scan', 'kig_start_scan_nonce' ); ?>
		<?php wp_nonce_field( 'kig_scan_status', 'kig_scan_status_nonce' ); ?>

		<h2 class="title"><?php esc_html_e( 'Scan Targets', 'k-integrity-guard' ); ?></h2>
		<p><?php esc_html_e( 'Override the saved targets for this scan. Leave everything unchecked to use your saved defaults.', 'k-integrity-guard' ); ?></p>

		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Scan targets', 'k-integrity-guard' ); ?></legend>
			<?php foreach ( $targets as $key => $label ) : ?>
				<label class="selectit">
					<input
						type="checkbox"
						name="targets[<?php echo esc_attr( $key ); ?>]"
						<?php checked( ! empty( $defaults[ $key ] ) ); ?>
					/>
					<?php echo esc_html( $label ); ?>
				</label>
				<br />
			<?php endforeach; ?>
		</fieldset>

		<p>
			<button type="submit" class="button button-primary" id="wpig-start-scan">
				<?php esc_html_e( 'Start Scan', 'k-integrity-guard' ); ?>
			</button>
		</p>
	</form>

	<div class="wpig-scan-progress wp-core-ui" aria-live="polite">
		<progress id="wpig-scan-progress" max="100" value="0"></progress>
		<p id="wpig-scan-status-text" class="description">
			<?php esc_html_e( 'Waiting to start…', 'k-integrity-guard' ); ?>
		</p>
	</div>

	<div id="wpig-scan-message" class="wpig-scan-message" aria-live="assertive"></div>

	<div class="wpig-scan-summary-wrapper">
		<div id="wpig-scan-summary" class="wpig-scan-summary" aria-live="assertive"></div>
		<div id="wpig-last-scan" class="wpig-last-summary" aria-live="polite"></div>
	</div>
</div>
