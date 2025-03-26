<?php
/**
 * Plugin Autoupdate Filter Logger class
 * Handles all logging functionality
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter_Logger {

	/**
	 * @var string The base directory for log files
	 */
	private $log_directory;

	/**
	 * @var WP_Filesystem_Base|null WordPress Filesystem instance
	 */
	private $wp_filesystem;

	/**
	 * @var array Cache of logged entries to prevent duplicates
	 */
	private $logged_entries = array();

	/**
	 * @var array Track the final status for each plugin update attempt
	 */
	private $plugin_statuses = array();

	/**
	 * @var array Track update checks for each plugin
	 */
	private $update_checks = array();

	/**
	 * @var array Track which plugins we've already logged
	 */
	private $logged_plugins = array();

	/**
	 * @var Plugin_Autoupdate_Filter_Helpers Helpers instance
	 */
	private $helpers;

	/**
	 * Initialize the logger
	 */
	public function __construct() {
		$upload_dir          = wp_upload_dir();
		$this->log_directory = trailingslashit( $upload_dir['basedir'] ) . 'plugin-autoupdate-filter-logs';
		$this->helpers       = new Plugin_Autoupdate_Filter_Helpers();

		// Initialize WP_Filesystem
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Logging filesystem errors is acceptable in production
			trigger_error(
				esc_html( 'Plugin Autoupdate Filter: WP_Filesystem is not available' ),
				E_USER_WARNING
			);
			return;
		}

		$this->wp_filesystem = $wp_filesystem;
	}

	/**
	 * Initialize WordPress hooks
	 */
	public function init(): void {
		// Set up the cleanup cron job if logging is enabled
		if ( $this->is_logging_enabled() ) {
			add_action( 'plugin_autoupdate_filter_cleanup_logs', array( $this, 'cleanup_old_logs' ) );

			if ( ! wp_next_scheduled( 'plugin_autoupdate_filter_cleanup_logs' ) ) {
				wp_schedule_event( strtotime( 'midnight' ), 'daily', 'plugin_autoupdate_filter_cleanup_logs' );
			}
		}
	}

	/**
	 * Check if logging is enabled in plugin settings
	 *
	 * @return bool
	 */
	public function is_logging_enabled(): bool {
		return (bool) get_option( 'plugin_autoupdate_filter_enable_logging', false );
	}

	/**
	 * Ensure the log directory exists and is writable
	 *
	 * @return bool
	 */
	public function ensure_log_directory(): bool {
		if ( ! $this->wp_filesystem ) {
			return false;
		}

		// Create the directory if it doesn't exist
		if ( ! $this->wp_filesystem->is_dir( $this->log_directory ) ) {
			if ( ! wp_mkdir_p( $this->log_directory ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Logging filesystem errors is acceptable in production
				trigger_error(
					esc_html( 'Plugin Autoupdate Filter: Unable to create log directory' ),
					E_USER_WARNING
				);
				return false;
			}

			// Create index.php to prevent directory listing
			$index_content = "<?php\n// Silence is golden.";
			$this->wp_filesystem->put_contents(
				trailingslashit( $this->log_directory ) . 'index.php',
				$index_content,
				FS_CHMOD_FILE
			);
		}

		return true;
	}

	/**
	 * Get the transient key for a plugin
	 */
	private function get_transient_key( string $plugin_key ): string {
		return 'paf_logged_' . md5( $plugin_key );
	}

	/**
	 * Track update information without logging
	 *
	 * @param string $plugin_name    The name of the plugin
	 * @param string $new_version    The version being updated to
	 * @param array  $update_info    Array of update information
	 * @return bool
	 */
	public function track_update_info( string $plugin_name, string $new_version, array $update_info ): bool {
		$check_key = $plugin_name . '|' . $new_version;

		// Initialize or update the check entry
		if ( ! isset( $this->update_checks[ $check_key ] ) ) {
			$initial_status = ! empty( $update_info['Status'] )
				? $update_info['Status']
				: 'Update status unknown';

			$this->update_checks[ $check_key ] = array(
				'timestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'plugin_name'  => $plugin_name,
				'version_info' => array(
					'Current Version' => 'unknown',
					'New Version'     => $new_version,
				),
				'details'      => array(
					'Has Update Package'           => ! empty( $update_info['Details']['Has Update Package'] ),
					'Outside business hours'       => false,
					'Holiday period'               => false,
					'Delay passed'                 => true,
					'Updates disabled by OpsOasis' => false,
				),
				'status'       => $initial_status,
			);
		}

		// Update with any new information
		if ( ! empty( $update_info['Version Info'] ) ) {
			$this->update_checks[ $check_key ]['version_info'] = $update_info['Version Info'];
		}

		if ( ! empty( $update_info['Details'] ) ) {
			$this->update_checks[ $check_key ]['details'] = array_merge(
				$this->update_checks[ $check_key ]['details'],
				$update_info['Details']
			);
		}

		if ( ! empty( $update_info['Status'] ) ) {
			$this->update_checks[ $check_key ]['status'] = $update_info['Status'];
		}

		if ( ! empty( $update_info['Update Tracking'] ) ) {
			$this->update_checks[ $check_key ]['update_tracking'] = $update_info['Update Tracking'];
		}

		return true;
	}

	/**
	 * Write the final update decision to the log
	 *
	 * @param string $plugin_name    The name of the plugin
	 * @param string $new_version    The version being updated to
	 * @param array  $update_info    Array of update information
	 * @return bool
	 */
	public function write_to_log( string $plugin_name, string $new_version, array $update_info ): bool {
		if ( ! $this->is_logging_enabled() || ! $this->ensure_log_directory() ) {
			return false;
		}

		// Track the final information before logging
		$this->track_update_info( $plugin_name, $new_version, $update_info );
		$check_key = $plugin_name . '|' . $new_version;

		// Get today's log file path
		$today    = gmdate( 'Y-m-d' );
		$log_file = trailingslashit( $this->log_directory ) . $today . '-plugin-autoupdate-filter.log';

		// Get existing content
		$existing_content = $this->wp_filesystem->exists( $log_file )
			? $this->wp_filesystem->get_contents( $log_file )
			: '';

		// Format and write the log entry
		$log_entry = sprintf(
			"[%s] Plugin Update Attempt: %s %s\n%s\n",
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$plugin_name,
			$new_version,
			wp_json_encode( $this->format_log_info( $this->update_checks[ $check_key ] ), JSON_PRETTY_PRINT )
		);

		$full_content = $existing_content . $log_entry . "\n";
		return (bool) $this->wp_filesystem->put_contents( $log_file, $full_content );
	}

	/**
	 * Clean up old log files
	 *
	 * @return void
	 */
	public function cleanup_old_logs(): void {
		if ( ! $this->wp_filesystem || ! $this->wp_filesystem->is_dir( $this->log_directory ) ) {
			return;
		}

		$retention_days = absint( get_option( 'plugin_autoupdate_filter_log_retention', 15 ) );
		$retention_days = min( max( $retention_days, 1 ), 60 ); // Ensure between 1 and 60 days

		$files = $this->wp_filesystem->dirlist( $this->log_directory );
		if ( ! $files ) {
			return;
		}

		$cutoff_date = strtotime( "-{$retention_days} days" );

		foreach ( $files as $file ) {
			// Skip if not a log file
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}-plugin-autoupdate-filter\.log$/', $file['name'] ) ) {
				continue;
			}

			// Extract date from filename
			$file_date      = substr( $file['name'], 0, 10 );
			$file_timestamp = strtotime( $file_date );

			if ( $file_timestamp && $file_timestamp < $cutoff_date ) {
				$file_path = trailingslashit( $this->log_directory ) . $file['name'];
				if ( ! $this->wp_filesystem->delete( $file_path ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Logging filesystem errors is acceptable in production
					trigger_error(
						sprintf(
							'Plugin Autoupdate Filter: Failed to delete old log file: %s',
							esc_html( $file['name'] )
						),
						E_USER_WARNING
					);
				}
			}
		}
	}

	/**
	 * Get list of available log files
	 *
	 * @return array Array of log files with dates as keys and file paths as values
	 */
	public function get_log_files(): array {
		if ( ! $this->wp_filesystem || ! $this->wp_filesystem->is_dir( $this->log_directory ) ) {
			return array();
		}

		$files = $this->wp_filesystem->dirlist( $this->log_directory );
		if ( ! $files ) {
			return array();
		}

		$log_files = array();
		foreach ( $files as $file ) {
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})-plugin-autoupdate-filter\.log$/', $file['name'], $matches ) ) {
				$date               = $matches[1];
				$log_files[ $date ] = trailingslashit( $this->log_directory ) . $file['name'];
			}
		}

		// Sort by date descending
		krsort( $log_files );

		return $log_files;
	}

	/**
	 * Get the contents of a log file
	 *
	 * @param string $file_path Path to the log file
	 * @return string|false The file contents or false on failure
	 */
	public function get_log_content( string $file_path ) {
		if ( ! $this->wp_filesystem ) {
			return false;
		}

		return $this->wp_filesystem->get_contents( $file_path );
	}

	/**
	 * Format log information for output
	 *
	 * @param array $checks Array of update check information
	 * 
	 * @return array Formatted log information
	 */
	private function format_log_info( array $checks ): array {
		$formatted = array(
			'Status'          => $checks['status'] ?? '',
			'Version Info'    => $checks['version_info'] ?? array(),
			'Details'         => $checks['details'] ?? array(),
			'Update Tracking' => $checks['update_tracking'] ?? array(),
		);

		return $formatted;
	}

	/**
	 * Add update process information to the update checks
	 *
	 * @param string $plugin_name The name of the plugin
	 * @param string $version     The version of the plugin
	 * @param array  $process_info The update process information
	 */
	public function add_update_process_info( string $plugin_name, string $version, array $process_info ): void {
		$key = $plugin_name . '|' . $version;

		// If no update check exists, create one
		if ( ! isset( $this->update_checks[ $key ] ) ) {
			$this->update_checks[ $key ] = array(
				'timestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'plugin_name'  => $plugin_name,
				'version_info' => array(
					'Current Version' => 'unknown',
					'New Version'     => $version,
				),
				'details'      => array(
					'Has Update Package'           => true,
					'Outside business hours'       => false,
					'Holiday period'               => false,
					'Delay passed'                 => true,
					'Updates disabled by OpsOasis' => false,
				),
			);
		}

		// Add or merge the process info
		if ( isset( $this->update_checks[ $key ]['update_process'] ) ) {
			$this->update_checks[ $key ]['update_process'] = array_merge(
				$this->update_checks[ $key ]['update_process'],
				$process_info
			);
		} else {
			$this->update_checks[ $key ]['update_process'] = $process_info;
		}
	}

	/**
	 * Check package status for non-WordPress.org plugins
	 *
	 * @param string $plugin_slug  The plugin slug
	 * @param array  $update_info  Current update information
	 * 
	 * @return array Updated information with package status
	 */
	private function check_package_status( string $plugin_slug, array $update_info ): array {
		// Only check package status for non-.org plugins
		if ( 0 === strpos( $plugin_slug, 'woocommerce-com-' ) || ! $this->is_wp_org_plugin( $plugin_slug ) ) {
			$package_url = $this->get_update_package_url( $plugin_slug );
			if ( $package_url ) {
				$response      = wp_remote_head( $package_url );
				$response_code = wp_remote_retrieve_response_code( $response );
				$is_accessible = false;
				if ( 200 === $response_code ) {
					$is_accessible = true;
				}

				$update_info['Package Status'] = array(
					'Response Code' => $response_code,
					'Is Accessible' => $is_accessible,
				);
			}
		}

		return $update_info;
	}

	/**
	 * Get update information for a specific plugin
	 *
	 * @param string $plugin_name The name of the plugin
	 * @param string $version     The version being checked
	 * 
	 * @return array|null Update information or null if not found
	 */
	public function get_update_info( string $plugin_name, string $version ): ?array {
		$check_key = $plugin_name . '|' . $version;
		return $this->update_checks[ $check_key ] ?? null;
	}
}
