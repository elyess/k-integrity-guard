<?php
/**
 * Scan history detail view.
 *
 * @package K_Integrity_Guard
 * @var array $scan Scan record data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scan_date = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['scan_date'] );
$results   = is_array( $scan['results'] ) ? $scan['results'] : array();
$targets   = is_array( $scan['targets'] ) ? $scan['targets'] : array();
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Scan Details', 'k-integrity-guard' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . K_Integrity_Guard::SLUG_HISTORY ) ); ?>" class="button">
			&larr; <?php esc_html_e( 'Back to History', 'k-integrity-guard' ); ?>
		</a>
	</p>

	<div class="wpig-scan-detail">
		<table class="widefat fixed striped">
			<tbody>
				<tr>
					<th style="width: 200px;"><?php esc_html_e( 'Scan Date', 'k-integrity-guard' ); ?></th>
					<td><?php echo esc_html( $scan_date ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Context', 'k-integrity-guard' ); ?></th>
					<td>
						<?php
						if ( 'cron' === $scan['context'] ) {
							echo '<span class="wpig-context-badge wpig-context-cron">' . esc_html__( 'Scheduled', 'k-integrity-guard' ) . '</span>';
						} else {
							echo '<span class="wpig-context-badge wpig-context-manual">' . esc_html__( 'Manual', 'k-integrity-guard' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'k-integrity-guard' ); ?></th>
					<td>
						<?php
						switch ( $scan['status'] ) {
							case 'success':
								echo '<span class="wpig-status-badge wpig-status-success">' . esc_html__( 'Success', 'k-integrity-guard' ) . '</span>';
								break;
							case 'warning':
								echo '<span class="wpig-status-badge wpig-status-warning">' . esc_html__( 'Warning', 'k-integrity-guard' ) . '</span>';
								break;
							case 'error':
								echo '<span class="wpig-status-badge wpig-status-error">' . esc_html__( 'Error', 'k-integrity-guard' ) . '</span>';
								break;
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total Issues', 'k-integrity-guard' ); ?></th>
					<td><strong><?php echo esc_html( number_format_i18n( $scan['total_issues'] ) ); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Summary', 'k-integrity-guard' ); ?></th>
					<td><?php echo esc_html( $scan['summary'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Scan Results', 'k-integrity-guard' ); ?></h2>

		<?php foreach ( $targets as $target ) : ?>
			<?php if ( ! isset( $results[ $target ] ) ) {
				continue;
			} ?>

			<?php
			$result = $results[ $target ];
			$items  = isset( $result['items'] ) ? $result['items'] : array();
			?>

			<h3>
				<?php
				switch ( $target ) {
					case 'core':
						esc_html_e( 'WordPress Core', 'k-integrity-guard' );
						break;
					case 'plugins':
						esc_html_e( 'Plugins', 'k-integrity-guard' );
						break;
					case 'themes':
						esc_html_e( 'Themes', 'k-integrity-guard' );
						break;
					default:
						echo esc_html( ucfirst( $target ) );
				}
				?>
			</h3>

			<?php if ( isset( $result['summary'] ) ) : ?>
				<p><?php echo esc_html( $result['summary'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $items ) ) : ?>
				<?php foreach ( $items as $item ) : ?>
					<?php if ( 'ok' === ( $item['status'] ?? '' ) ) {
						continue;
					} ?>

					<div class="wpig-item-detail" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
						<h4 style="margin-top: 0;">
							<?php echo esc_html( $item['name'] ?? __( 'Unknown', 'k-integrity-guard' ) ); ?>
							<?php if ( isset( $item['version'] ) ) : ?>
								<small>(v<?php echo esc_html( $item['version'] ); ?>)</small>
							<?php endif; ?>
						</h4>

						<?php if ( isset( $item['message'] ) ) : ?>
							<p><strong><?php echo esc_html( $item['message'] ); ?></strong></p>
						<?php endif; ?>

						<?php if ( ! empty( $item['modified'] ) ) : ?>
							<details style="margin: 10px 0;">
								<summary style="cursor: pointer; font-weight: 600; color: #856404;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of files */
											_n( '%d Modified File', '%d Modified Files', count( $item['modified'] ), 'k-integrity-guard' ),
											count( $item['modified'] )
										)
									);
									?>
								</summary>
								<ul style="margin: 10px 0; padding-left: 20px;">
									<?php foreach ( $item['modified'] as $file ) : ?>
										<li><code><?php echo esc_html( is_array( $file ) ? ( $file['file'] ?? '' ) : $file ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>

						<?php if ( ! empty( $item['missing'] ) ) : ?>
							<details style="margin: 10px 0;">
								<summary style="cursor: pointer; font-weight: 600; color: #721c24;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of files */
											_n( '%d Missing File', '%d Missing Files', count( $item['missing'] ), 'k-integrity-guard' ),
											count( $item['missing'] )
										)
									);
									?>
								</summary>
								<ul style="margin: 10px 0; padding-left: 20px;">
									<?php foreach ( $item['missing'] as $file ) : ?>
										<li><code><?php echo esc_html( $file ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>

						<?php if ( ! empty( $item['added'] ) ) : ?>
							<details style="margin: 10px 0;">
								<summary style="cursor: pointer; font-weight: 600; color: #084298;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of files */
											_n( '%d Added File', '%d Added Files', count( $item['added'] ), 'k-integrity-guard' ),
											count( $item['added'] )
										)
									);
									?>
								</summary>
								<ul style="margin: 10px 0; padding-left: 20px;">
									<?php foreach ( $item['added'] as $file ) : ?>
										<li><code><?php echo esc_html( $file ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endforeach; ?>
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
</style>
