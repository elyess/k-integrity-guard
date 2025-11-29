<?php
/**
 * Settings page template.
 *
 * @package K_Integrity_Guard
 * @var KIG_Settings $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="options.php">
		<?php
			settings_fields( KIG_Settings::PAGE );
			do_settings_sections( KIG_Settings::PAGE );
			submit_button( __( 'Save Settings', 'k-integrity-guard' ) );
		?>
	</form>

	<!-- Third-party Plugins Section -->
	<div class="wpig-third-party-section">
		<h2><?php esc_html_e( 'Third-party Plugins', 'k-integrity-guard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Plugins not detected as hosted on WordPress.org. Ignore them or generate checksums for integrity verification.', 'k-integrity-guard' ); ?>
		</p>

		<form method="post" action="options.php" id="wpig-third-party-plugins-form">
			<?php
				settings_fields( KIG_Settings::PAGE );
				$settings->field_third_party_list();
				submit_button( __( 'Save Plugin Settings', 'k-integrity-guard' ), 'primary', 'submit', true, array( 'id' => 'wpig-third-party-plugins-submit' ) );
			?>
		</form>
	</div>

	<!-- Third-party Themes Section -->
	<div class="wpig-third-party-section">
		<h2><?php esc_html_e( 'Third-party Themes', 'k-integrity-guard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Themes not detected as hosted on WordPress.org. Ignore them or generate checksums for integrity verification.', 'k-integrity-guard' ); ?>
		</p>

		<form method="post" action="options.php" id="wpig-third-party-themes-form">
			<?php
				settings_fields( KIG_Settings::PAGE );
				$settings->field_third_party_themes_list();
				submit_button( __( 'Save Theme Settings', 'k-integrity-guard' ), 'primary', 'submit', true, array( 'id' => 'wpig-third-party-themes-submit' ) );
			?>
		</form>
	</div>
</div>
