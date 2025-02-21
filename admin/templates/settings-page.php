<?php
/**
 * Admin settings page template
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Get available log files
$log_files       = $this->logger->get_log_files();
$logging_enabled = get_option( 'plugin_autoupdate_filter_enable_logging', false );
$retention_days  = get_option( 'plugin_autoupdate_filter_log_retention', 15 );
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="notice notice-info">
		<p>
			<?php
			printf(
				/* translators: %s: Log directory path */
				esc_html__( 'Log files are stored in: %s', 'plugin-autoupdate-filter' ),
				'<code>' . esc_html( wp_normalize_path( WP_CONTENT_DIR . '/uploads/plugin-autoupdate-filter-logs/' ) ) . '</code>'
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'Each log file contains detailed information about plugin update attempts, including timing checks, version comparisons, and update decisions.', 'plugin-autoupdate-filter' ); ?>
		</p>
	</div>

	<form action="options.php" method="post">
		<?php
		settings_fields( 'plugin_autoupdate_filter' );
		do_settings_sections( 'plugin_autoupdate_filter' );
		submit_button();
		?>
	</form>

	<?php if ( $logging_enabled ) : ?>
		<div class="plugin-autoupdate-filter-logs">
			<h2><?php esc_html_e( 'Available Log Files', 'plugin-autoupdate-filter' ); ?></h2>
			<?php if ( ! empty( $log_files ) ) : ?>
				<div class="plugin-autoupdate-filter-logs-table-wrap">
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'plugin-autoupdate-filter' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'plugin-autoupdate-filter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log_files as $date => $file_path ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $date ); ?>
									</td>
									<td>
										<a href="<?php echo esc_url( content_url( str_replace( WP_CONTENT_DIR, '', $file_path ) ) ); ?>" 
											class="button button-secondary" 
											target="_blank"
											download>
											<?php esc_html_e( 'Download Log', 'plugin-autoupdate-filter' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No log files available yet.', 'plugin-autoupdate-filter' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
