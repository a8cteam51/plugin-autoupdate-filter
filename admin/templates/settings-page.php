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

	<form action="options.php" method="post">
		<?php
		settings_fields( 'plugin_autoupdate_filter' );
		do_settings_sections( 'plugin_autoupdate_filter' );
		submit_button();
		?>
	</form>

	<?php if ( $logging_enabled ) : ?>
		<div class="plugin-autoupdate-filter-logs">
			<h2><?php esc_html_e( 'Log Files', 'plugin-autoupdate-filter' ); ?></h2>
			<?php if ( ! empty( $log_files ) ) : ?>
				<select id="log-file-select">
					<option value=""><?php esc_html_e( 'Select a date to view logs...', 'plugin-autoupdate-filter' ); ?></option>
					<?php foreach ( $log_files as $date => $file_path ) : ?>
						<option value="<?php echo esc_attr( $date ); ?>">
							<?php echo esc_html( $date ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="spinner" id="log-loading-spinner"></span>
				<div id="log-content" class="log-content" style="display: none;">
					<pre></pre>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No log files available yet.', 'plugin-autoupdate-filter' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
