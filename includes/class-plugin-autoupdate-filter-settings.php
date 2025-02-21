<?php
/**
 * Plugin Autoupdate Filter Settings class
 * Handles settings page and options management
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter_Settings {

	/**
	 * @var Plugin_Autoupdate_Filter_Logger Logger instance
	 */
	private $logger;

	/**
	 * Initialize the settings
	 *
	 * @param Plugin_Autoupdate_Filter_Logger $logger Logger instance
	 */
	public function __construct( Plugin_Autoupdate_Filter_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Initialize WordPress hooks
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add settings page to WordPress admin
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'options-general.php',
			'Plugin Autoupdate Filter Settings',
			'Plugin Autoupdate Filter',
			'manage_options',
			'plugin-autoupdate-filter',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings(): void {
		register_setting(
			'plugin_autoupdate_filter',
			'plugin_autoupdate_filter_enable_logging',
			array(
				'type'              => 'boolean',
				'description'       => 'Enable debug logging',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'plugin_autoupdate_filter',
			'plugin_autoupdate_filter_log_retention',
			array(
				'type'              => 'integer',
				'description'       => 'Number of days to retain logs',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => 15,
			)
		);

		add_settings_section(
			'plugin_autoupdate_filter_main',
			'Logging Settings',
			array( $this, 'render_settings_section' ),
			'plugin_autoupdate_filter'
		);

		add_settings_field(
			'plugin_autoupdate_filter_enable_logging',
			'Enable Logging',
			array( $this, 'render_enable_logging_field' ),
			'plugin_autoupdate_filter',
			'plugin_autoupdate_filter_main'
		);

		add_settings_field(
			'plugin_autoupdate_filter_log_retention',
			'Log Retention (days)',
			array( $this, 'render_log_retention_field' ),
			'plugin_autoupdate_filter',
			'plugin_autoupdate_filter_main'
		);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook The current admin page
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_plugin-autoupdate-filter' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'plugin-autoupdate-filter-admin',
			plugins_url( 'admin/css/settings.css', PLUGIN_AUTOUPDATE_FILTER_FILE ),
			array(),
			PLUGIN_AUTOUPDATE_FILTER_VERSION
		);

		wp_enqueue_script(
			'plugin-autoupdate-filter-admin',
			plugins_url( 'admin/js/settings.js', PLUGIN_AUTOUPDATE_FILTER_FILE ),
			array( 'jquery' ),
			PLUGIN_AUTOUPDATE_FILTER_VERSION,
			true
		);
	}

	/**
	 * Sanitize retention days setting
	 *
	 * @param mixed $value The value to sanitize
	 * @return int
	 */
	public function sanitize_retention_days( $value ): int {
		$days = absint( $value );
		return min( max( $days, 1 ), 60 );
	}

	/**
	 * Render the settings section description
	 */
	public function render_settings_section(): void {
		?>
		<p>Configure logging settings for Plugin Autoupdate Filter. Logs are stored in <code>wp-content/uploads/plugin-autoupdate-filter-logs/</code>.</p>
		<?php
	}

	/**
	 * Render the enable logging checkbox
	 */
	public function render_enable_logging_field(): void {
		$enabled = get_option( 'plugin_autoupdate_filter_enable_logging', false );
		?>
		<label>
			<input type="checkbox" name="plugin_autoupdate_filter_enable_logging" value="1" <?php checked( $enabled ); ?>>
			Enable logging of plugin update attempts
		</label>
		<?php
	}

	/**
	 * Render the log retention field
	 */
	public function render_log_retention_field(): void {
		$days = get_option( 'plugin_autoupdate_filter_log_retention', 15 );
		?>
		<input type="number" name="plugin_autoupdate_filter_log_retention" value="<?php echo esc_attr( $days ); ?>" min="1" max="60" step="1">
		<p class="description">Number of days to keep log files (1-60 days)</p>
		<?php
	}

	/**
	 * Render the settings page
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get available log files
		$log_files = $this->logger->get_log_files();

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

			<?php if ( ! empty( $log_files ) ) : ?>
				<div class="plugin-autoupdate-filter-logs">
					<h2>Available Log Files</h2>
					<div class="plugin-autoupdate-filter-logs-table-wrap">
						<table class="widefat">
							<thead>
								<tr>
									<th>Date</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $log_files as $date => $file_path ) : ?>
									<tr>
										<td><?php echo esc_html( $date ); ?></td>
										<td>
											<a href="<?php echo esc_url( content_url( str_replace( WP_CONTENT_DIR, '', $file_path ) ) ); ?>" class="button button-secondary" target="_blank">
												View Log
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
