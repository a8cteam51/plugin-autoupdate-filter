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
		add_action( 'wp_ajax_get_log_content', array( $this, 'get_log_content' ) );
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

		wp_localize_script(
			'plugin-autoupdate-filter-admin',
			'pluginAutoupdateFilter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'get_log_content' ),
			)
		);
	}

	/**
	 * Ajax handler to get log content
	 */
	public function get_log_content(): void {
		check_ajax_referer( 'get_log_content', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$date = sanitize_text_field( $_GET['date'] ?? '' );
		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( 'Invalid date format: ' . $date );
		}

		$log_files = $this->logger->get_log_files();
		if ( ! isset( $log_files[ $date ] ) ) {
			wp_send_json_error( 'Log file not found for date: ' . $date );
		}

		$file_path = $log_files[ $date ];
		if ( ! file_exists( $file_path ) ) {
			wp_send_json_error( 'File does not exist: ' . $file_path );
		}

		if ( ! is_readable( $file_path ) ) {
			wp_send_json_error( 'File is not readable: ' . $file_path . ' (Permissions: ' . decoct( fileperms( $file_path ) ) . ')' );
		}

		$content = $this->logger->get_log_content( $file_path );
		if ( false === $content ) {
			wp_send_json_error( 'Could not read file: ' . $file_path );
		}

		wp_send_json_success( $content );
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
		require_once PLUGIN_AUTOUPDATE_FILTER_PATH . 'admin/templates/settings-page.php';
	}
}
