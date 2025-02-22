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
	 * Initialize the logger
	 */
	public function __construct() {
		$upload_dir          = wp_upload_dir();
		$this->log_directory = trailingslashit( $upload_dir['basedir'] ) . 'plugin-autoupdate-filter-logs';

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
	 * Log a plugin update attempt
	 *
	 * @param string $plugin_name    The name of the plugin
	 * @param string $new_version    The version being updated to
	 * @param array  $update_info    Array of update information
	 * @return bool
	 */
	public function log_update_attempt( string $plugin_name, string $new_version, array $update_info ): bool {
		if ( ! $this->is_logging_enabled() || ! $this->wp_filesystem ) {
			return false;
		}

		// Get the current version
		$current_version = $update_info['Version Info']['Current Version'] ?? 'unknown';

		// Skip if versions are the same
		if ( 'unknown' !== $current_version && $current_version === $new_version ) {
			return true;
		}

		// Get today's log file
		$log_date = gmdate( 'Y-m-d' );
		$log_file = $this->log_directory . "/{$log_date}-plugin-autoupdate-filter.log";

		// Check if we've already logged this plugin update attempt today
		if ( $this->wp_filesystem->exists( $log_file ) ) {
			$existing_content = $this->wp_filesystem->get_contents( $log_file );
			$timestamp        = gmdate( 'Y-m-d\TH:i:s\Z' );
			$search_string    = "[{$timestamp}] Plugin Update Attempt: {$plugin_name} {$new_version}";
			if ( false !== strpos( $existing_content, $search_string ) ) {
				return true;
			}
		}

		// Initialize or update the checks for this plugin
		if ( ! isset( $this->update_checks[ $plugin_name . '|' . $new_version ] ) ) {
			$this->update_checks[ $plugin_name . '|' . $new_version ] = array(
				'timestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'plugin_name'  => $plugin_name,
				'version_info' => array(
					'Current Version' => $current_version,
					'New Version'     => $new_version,
				),
				'details'      => array(
					'Has Update Package'           => true,
					'Outside business hours'       => false,
					'Holiday period'               => false,
					'Delay passed'                 => true,
					'Updates disabled by OpsOasis' => false,
				),
			);
		} elseif ( 'unknown' !== $current_version ) {
			// Update current version if we now have it
			$this->update_checks[ $plugin_name . '|' . $new_version ]['version_info']['Current Version'] = $current_version;
		}

		// Update the checks based on the status
		$checks = &$this->update_checks[ $plugin_name . '|' . $new_version ];

		// Check for OpsOasis block first
		if ( isset( $update_info['Update Controls']['Updates disabled by OpsOasis'] ) &&
		true === $update_info['Update Controls']['Updates disabled by OpsOasis'] ) {
			$checks['details']['Updates disabled by OpsOasis'] = true;
		} elseif ( false !== strpos( $update_info['Status'], 'disabled by OpsOasis' ) ) {
			$checks['details']['Updates disabled by OpsOasis'] = true;
		}

		if ( false !== strpos( $update_info['Status'], 'outside business hours' ) ) {
			$checks['details']['Outside business hours'] = true;
		}

		if ( isset( $update_info['Update Controls']['Has Update Package'] ) ) {
			$checks['details']['Has Update Package'] = (bool) $update_info['Update Controls']['Has Update Package'];
		}

		if ( false !== strpos( $update_info['Status'], 'delayed' ) ) {
			$checks['details']['Delay passed'] = false;
		}

		// If this is a final status, write the log
		if ( $this->is_final_status( $update_info['Status'] ) ) {
			$log_info = array(
				'Status'       => $this->determine_final_status( $checks['details'] ),
				'Version Info' => $checks['version_info'],
				'Details'      => $checks['details'],
			);

			// Format the log entry
			$timestamp  = $checks['timestamp'];
			$log_entry  = "[{$timestamp}] Plugin Update Attempt: {$plugin_name} {$new_version}\n";
			$log_entry .= wp_json_encode( $log_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n\n";

			// Write to file
			if ( ! $this->ensure_log_directory() ) {
				return false;
			}

			// Append to log file
			$existing_content = '';
			if ( $this->wp_filesystem->exists( $log_file ) ) {
				$existing_content = $this->wp_filesystem->get_contents( $log_file );
			}
			$full_content = $existing_content . $log_entry;
			$result       = $this->wp_filesystem->put_contents( $log_file, $full_content, FS_CHMOD_FILE );

			// Mark this plugin as logged using a transient that expires in 1 minute
			if ( $result ) {
				set_transient( $this->get_transient_key( $plugin_name . '|' . $new_version ), true, MINUTE_IN_SECONDS );
				unset( $this->update_checks[ $plugin_name . '|' . $new_version ] );
			}

			return (bool) $result;
		}

		return true;
	}

	/**
	 * Check if this is a final status that should trigger log writing
	 */
	private function is_final_status( string $status ): bool {
		$final_statuses = array(
			'Auto-update skipped - WooCommerce.com connection required',
			'Auto-update scheduled',
			'Update complete',
			'Update blocked - disabled by OpsOasis',
		);

		foreach ( $final_statuses as $final_status ) {
			if ( strpos( $status, $final_status ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine the final status based on all checks
	 */
	private function determine_final_status( array $details ): string {
		if ( $details['Updates disabled by OpsOasis'] ||
		! $details['Has Update Package'] ||
		$details['Outside business hours'] ||
		! $details['Delay passed'] ) {
			return 'Autoupdate blocked';
		}
		return 'Autoupdate allowed';
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
}
