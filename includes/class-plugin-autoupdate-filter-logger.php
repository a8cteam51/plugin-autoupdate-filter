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
			if ( ! $this->wp_filesystem->mkdir( $this->log_directory ) ) {
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
				$index_content
			);
		}

		return true;
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

		if ( ! $this->ensure_log_directory() ) {
			return false;
		}

		$timestamp = gmdate( 'Y-m-d\TH:i:s\Z' );
		$log_date  = gmdate( 'Y-m-d' );
		$log_file  = $this->log_directory . "/{$log_date}-plugin-autoupdate-filter.log";

		$log_entry  = "[{$timestamp}] Plugin Update Attempt: {$plugin_name} {$new_version}\n";
		$log_entry .= wp_json_encode( $update_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n\n";

		return (bool) $this->wp_filesystem->put_contents(
			$log_file,
			$log_entry,
			FILE_APPEND
		);
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
