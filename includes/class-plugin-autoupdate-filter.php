<?php
/**
 * Plugin Autoupdate Filter class
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once 'class-plugin-autoupdate-filter-helpers.php';

class Plugin_Autoupdate_Filter {

	/**
	 * @var stdClass Holds the settings
	 */
	private $settings;

	/**
	 * @var Plugin_Autoupdate_Filter_Logger Logger instance
	 */
	private $logger;

	/**
	 * @var Plugin_Autoupdate_Filter_Helpers Helper instance
	 */
	private $helpers;

	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		$this->logger  = new Plugin_Autoupdate_Filter_Logger();
		$this->helpers = new Plugin_Autoupdate_Filter_Helpers();
	}

	/**
	 * Initialize WordPress hooks
	 */
	public function init(): void {
		// get the centralized settings from opsoasis
		try {
			$this->settings = $this->get_auto_update_settings();
		} catch ( Exception $exception ) {
			$error_message  = $exception->getMessage();
			$this->settings = (object) array( 'disable_all' => true );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Logging API errors is acceptable in production
			trigger_error(
				sprintf(
					'Plugin Autoupdate Filter: Unable to retrieve the autoupdate settings (%s)',
					esc_html( $error_message )
				),
				E_USER_WARNING
			);
			add_action(
				'admin_notices',
				function() use ( $error_message ) {
					echo '<div class="notice notice-error"><p><strong> Plugin Autoupdate Filter:</strong> Unable to get autoupdate settings (' . esc_html( $error_message ) . ').</p></div>';
				}
			);
		}

		// setup plugins and core to autoupdate _unless_ it's during specific day/time
		add_filter( 'auto_update_plugin', array( $this, 'filter_auto_update_specific_times' ), 11, 2 );
		add_filter( 'auto_update_core', array( $this, 'filter_auto_update_specific_times' ), 11, 2 );

		// enforce a delay on all plugin autoupdates, based on release date
		add_filter( 'auto_update_plugin', array( $this, 'filter_enforce_delay' ), 12, 2 );

		// Replace automatic update wording on plugin management page in admin
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'filter_custom_setting_html' ), 11, 3 );

		//Append text to upgrade text on plugins page for plugins explicitly set to not autoupdate
		add_action( 'admin_init', array( $this, 'output_upgrade_message_for_specific_plugins' ) );

		// Always send auto-update emails to T51 concierge email address
		add_filter( 'auto_plugin_theme_update_email', array( $this, 'filter_custom_update_emails' ), 10, 4 );
		add_filter( 'auto_core_update_email', array( $this, 'filter_custom_update_emails' ), 10, 4 );
		add_filter( 'automatic_updates_debug_email', array( $this, 'filter_custom_debug_email' ), 10, 3 );

		// re-enable core update emails which are disabled in an mu-plugin at the Atomic platform level
		add_filter( 'automatic_updates_send_debug_email', '__return_true', 11 );
		add_filter( 'auto_core_update_send_email', '__return_true', 11 );
		add_filter( 'auto_plugin_update_send_email', '__return_true', 11 );
		add_filter( 'auto_theme_update_send_email', '__return_true', 11 );

		// "Disable all autoupdates" toggle
		add_filter( 'auto_update_plugin', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX - 1, 2 );
		add_filter( 'auto_update_core', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX - 1, 2 );
		add_filter( 'auto_update_theme', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX - 1, 2 );
		add_action( 'admin_init', array( $this, 'output_auto_updates_disabled_admin_notice' ) );

		// Add final filter check after all our other filters
		add_filter( 'auto_update_plugin', array( $this, 'track_final_update_decision' ), PHP_INT_MAX, 2 );

		// Add update result tracking
		add_action( 'upgrader_process_complete', array( $this, 'track_update_result' ), PHP_INT_MAX, 2 );

		// Add filter to fix malformed update objects
		add_filter( 'site_transient_update_plugins', array( $this, 'fix_malformed_update_objects' ), 9 );
	}

	/**
	 * Load settings from the centralized settings page
	 *
	 * @return stdClass Settings object
	 * @throws RuntimeException|Exception If settings cannot be retrieved
	 */
	private function get_auto_update_settings(): stdClass {
		// Try getting the settings from the transient first
		$transient_key = 'wpcpmsp_auto_update_settings';
		$settings      = get_transient( $transient_key );

		if ( empty( $settings ) ) {
			$response = wp_safe_remote_get(
				'https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/autoupdate-plugin/v1/settings/',
				array( 'headers' => array( 'Accept' => 'application/json' ) )
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Check that the response code is a 2xx code.
			if ( ! str_starts_with( (string) $response_code, '2' ) ) {
				$response_message = wp_remote_retrieve_response_message( $response );
				throw new Exception( $response_message, $response_code );
			}

			$decoded_body = json_decode( $response_body, false, 512, JSON_THROW_ON_ERROR );

			// if the settings are empty, we still need to return an object
			if ( ! is_object( $decoded_body ) ) {
				$object              = new stdClass();
				$object->placeholder = $decoded_body;
				$decoded_body        = $object;
			}

			// Save the settings in a transient for 5 minutes
			set_transient( $transient_key, $decoded_body, 5 * MINUTE_IN_SECONDS );

			$settings = $decoded_body;
		}

		return $settings;
	}

	/**
	 * If we have hit the "Disable all autoupdates" toggle switch, or if we can't get the centralized settings, don't autoupdate anything.
	 *
	 * @param bool|null $update Whether to update the plugin or not. This can be bool or null as per the docs
	 * @param object    $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function filter_maybe_disable_all_autoupdates( $update, $item ): bool {
		$update_info = array(
			'Filter Details' => array(
				'Updates disabled by OpsOasis' => isset( $this->settings->disable_all ),
			),
		);

		if ( isset( $this->settings->disable_all ) || null === $update ) {
			$this->logger->track_update_info( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
			return false;
		}

		return (bool) $update;
	}

	/**
	 * Disable auto-updates based on time and day of the week.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function filter_auto_update_specific_times( $update, $item ): bool {
		if ( ! is_object( $item ) || ! isset( $item->slug ) || empty( $item->new_version ) ) {
			return false;
		}
		$holidays = array(
			'christmas' => array(
				'start' => gmdate( 'Y' ) . '-12-23 00:00:00',
				'end'   => gmdate( 'Y' ) . '-12-31 23:59:59',
			),
			'new_years' => array(
				'start' => gmdate( 'Y' ) . '-01-01 00:00:00',
				'end'   => gmdate( 'Y' ) . '-01-02 23:59:59',
			),
		);
		$holidays = apply_filters( 'plugin_autoupdate_filter_holidays', $holidays );

		$now  = gmdate( 'Y-m-d H:i:s' );
		$hour = gmdate( 'H' );
		$day  = gmdate( 'D' );

		$hours = array(
			'start'      => '10', // 6am Eastern
			'end'        => '23', // 7pm Eastern
			'friday_end' => '19', // 3pm Eastern on Fridays
		);
		$hours = apply_filters( 'plugin_autoupdate_filter_hours', $hours );

		$days_off = array( 'Sat', 'Sun' );
		$days_off = apply_filters( 'plugin_autoupdate_filter_days_off', $days_off );

		// Only add controls if update is blocked
		if ( $hour < $hours['start'] || $hour > $hours['end'] ||
				in_array( $day, $days_off, true ) ||
				( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			$update_info['Filter Details'] = array(
				'Outside business hours' => true,
			);
		}

		// Check holidays
		foreach ( $holidays as $holiday_name => $holiday ) {
			if ( $holiday['start'] <= $now && $now <= $holiday['end'] ) {
				$update_info['Filter Details']['Holiday period'] = true;
				$this->logger->track_update_info( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
				return false;
			}
		}

		if ( $hour < $hours['start'] || $hour > $hours['end'] ||
				in_array( $day, $days_off, true ) ||
				( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			$this->logger->track_update_info( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
			return false;
		}

		return true;
	}

	/**
	 * Check if updates are disabled globally
	 *
	 * @return bool True if updates are disabled, false otherwise
	 */
	private function are_updates_disabled(): bool {
		return isset( $this->settings->disable_all ) && '1' === $this->settings->disable_all;
	}

	/**
	 * Disable plugin auto-updates based on if a delay has passed since plugin was released.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function filter_enforce_delay( $update, $item ): bool {
		// protect against non-bool being returned from this function
		if ( null === $update ) {
			$update = false;
		}

		// Check for required properties
		if ( ! is_object( $item ) || empty( $item->new_version ) ) {
			$update_info = array(
				'Status'          => 'Update invalid - missing update data',
				'Update Controls' => array(
					'Invalid Data' => array(
						'Missing Version' => empty( $item->new_version ),
					),
					'Raw Item'     => wp_json_encode( $item ),
				),
			);
			return $update;
		}

		// Try to get plugin file from either plugin property or id property
		$plugin_file = $item->plugin ?? $item->id ?? '';

		// If plugin file is empty but we have a slug, try to construct the plugin file path
		if ( empty( $plugin_file ) && ! empty( $item->slug ) ) {
			$plugin_file = $item->slug . '/' . $item->slug . '.php';
		}

		$plugin_slug        = $item->slug ?? dirname( $plugin_file );
		$plugin_new_version = $item->new_version;

		// Get current version
		$current_version = $this->helpers->get_installed_plugin_version( $plugin_file );

		// Initialize update info
		$update_info = array(
			'Version Info'   => array(
				'Current Version' => $current_version,
				'New Version'     => $plugin_new_version,
			),
			'Filter Details' => array(
				'Updates disabled by OpsOasis' => $this->are_updates_disabled(),
				'Is Canary Site'               => false,
				'Delay passed'                 => true,
			),
		);

		// Check if updates are disabled globally
		if ( $this->are_updates_disabled() ) {
			$this->logger->track_update_info( $plugin_slug, $plugin_new_version, $update_info );
			return false;
		}

		// Check for canary site status
		$site_url                                        = wp_parse_url( home_url(), PHP_URL_HOST );
		$update_info['Filter Details']['Is Canary Site'] = isset( $this->settings->canary_sites ) &&
			in_array( $site_url, $this->settings->canary_sites, true );

		if ( $update_info['Filter Details']['Is Canary Site'] ) {
			$this->logger->track_update_info( $plugin_slug, $plugin_new_version, $update_info );
			return $update;
		}

		// Apply delay logic
		$has_delay_passed                              = $this->helpers->has_delay_passed( $plugin_slug, $plugin_new_version, $plugin_file );
		$update_info['Filter Details']['Delay passed'] = $has_delay_passed ?? true;

		// Get the scheduled update date if there's a delay
		$formatted_date = '';
		if ( isset( $update_info['Filter Details']['Delay passed'] ) && false === $update_info['Filter Details']['Delay passed'] ) {
			$option_key = 'plugin_update_delays';
			$delays     = get_option( $option_key, array() );

			if ( isset( $delays[ $plugin_file ][ $plugin_new_version ] ) &&
				is_numeric( $delays[ $plugin_file ][ $plugin_new_version ] ) &&
				( ! empty( $plugin_file ) && is_plugin_active( $plugin_file ) )
			) {
				$delay_date      = $delays[ $plugin_file ][ $plugin_new_version ];
				$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
				$formatted_date  = date_i18n( $datetime_format, $delay_date, true );

				$update_info['Filter Details']['Scheduled Update Date'] = $formatted_date;

				add_filter(
					"in_plugin_update_message-{$plugin_file}",
					function( $plugin_data, $response ) use ( $plugin_new_version, $formatted_date ) {
						if ( ! empty( $response->package ) ) {
							echo ' For stability, autoupdates operate on a slight delay. Autoupdate to version ' .
								esc_html( $plugin_new_version ) .
								' is currently estimated to run after ' .
								esc_html( $formatted_date ) . ' UTC.';
						}
					},
					10,
					2
				);
			}
			$this->logger->track_update_info( $plugin_slug, $plugin_new_version, $update_info );
			return false;
		} elseif ( true === $has_delay_passed ) {
			$this->helpers->clear_plugin_delay( $plugin_file );
		}

		$this->logger->track_update_info( $plugin_slug, $plugin_new_version, $update_info );
		return $update;
	}

	/**
	 * Customize automatic update setting HTML for plugins page in wp-admin.
	 *
	 * @param string $html        HTML for automatic update settings.
	 * @param string $plugin_file Path to plugin file.
	 * @param array  $plugin_data Array of plugin data.
	 *
	 * @return string Customized HTML for automatic update settings.
	 */
	public function filter_custom_setting_html( $html, $plugin_file, $plugin_data ): string {
		// check if updates are explicitly blocked for this plugin
		if ( function_exists( 'disable_autoupdate_specific_plugins' ) ) {
			// create a fake object to feed to disable_autoupdate_specific_plugins
			$plugin_obj                    = new stdClass();
			$plugin_obj->slug              = dirname( $plugin_file );
			$plugin_allowed_to_update_bool = disable_autoupdate_specific_plugins( true, $plugin_obj );

			if ( false === $plugin_allowed_to_update_bool ) {
				return 'Autoupdates have been explicitly deactivated for this plugin.';
			}
		}

		return 'Automatic updates managed by <strong>Plugin Autoupdate Filter</strong>';
	}

	/**
	 * Append text to upgrade text on plugins page for plugins explicitly set to not autoupdate
	 */
	public function output_upgrade_message_for_specific_plugins(): void {
		// check if updates are explicitly blocked for this plugin
		// don't show if we are already disabling all updates
		if ( ! function_exists( 'disable_autoupdate_specific_plugins' ) || isset( $this->settings->disable_all ) ) {
			return;
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			// create a fake object to feed to disable_autoupdate_specific_plugins
			$plugin_obj                    = new stdClass();
			$slug                          = dirname( $plugin_file );
			$plugin_obj->slug              = $slug;
			$plugin_allowed_to_update_bool = disable_autoupdate_specific_plugins( true, $plugin_obj );
			if ( false === $plugin_allowed_to_update_bool ) {
				// add notice next to the "update now" link
				add_filter(
					"in_plugin_update_message-{$plugin_file}",
					function () {
						echo ' <strong style="color:red;"> Caution:</strong> Autoupdates have been explicitly deactivated for this plugin. Please contact the WordPress Special Projects team before manually updating.';
					},
					10,
					2
				);
				// add notice to the top of the screen
				global $pagenow;
				if ( 'plugins.php' === $pagenow ) {
					add_action(
						'admin_notices',
						function() use ( $slug ) {
							echo '<div class="notice notice-error"><p><strong style="color:red;"> Caution:</strong> Autoupdates have been explicitly deactivated for ', esc_html( $slug ), '. Please contact the WordPress Special Projects team before manually updating.</p></div>';
						}
					);
				}
			}
		}
	}

	/**
	 * Autoupdates disabled admin notice
	 */
	public function output_auto_updates_disabled_admin_notice(): void {
		static $notice_added = false;

		// add notice to the top of the screen
		global $pagenow;
		if ( 'plugins.php' === $pagenow && isset( $this->settings->disable_all ) && ! $notice_added ) {
			$notice_added = true;
			add_action(
				'admin_notices',
				function() {
					echo '<div class="notice notice-error"><p><strong style="color:red;"> Caution:</strong> All automatic updates are deactivated. Please contact the WordPress Special Projects team before manually updating plugins.</p></div>';
				}
			);
		}
	}

	/**
	 * Customize auto-update email recipients.
	 *
	 * @param array  $email              Array of email data.
	 * @param string $type               Type of email to send.
	 * @param array  $successful_updates Array of successful updates.
	 * @param array  $failed_updates     Array of failed updates.
	 *
	 * @return array Array of email data with modified recipient email.
	 */
	public function filter_custom_update_emails( $email, $type, $successful_updates, $failed_updates ): array {
		$email['to'] = 'concierge@wordpress.com';
		return $email;
	}

	/**
	 * Filters the recipient email address for plugin update failure notifications.
	 *
	 * @param array $email          The email details, including 'to', 'subject', 'body', 'headers'.
	 * @param int   $failures       The number of failures encountered while upgrading.
	 * @param mixed $update_results The results of all attempted updates.
	 *
	 * @return array $email The email details with the 'to' address modified.
	 */
	public function filter_custom_debug_email( $email, $failures, $update_results ): array {
		$email['to'] = 'concierge@wordpress.com';
		return $email;
	}

	/**
	 * Add settings link to plugin listing
	 *
	 * @param array $links Array of plugin action links
	 * @return array Modified array of plugin action links
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=plugin-autoupdate-filter' ) ),
			esc_html__( 'Settings', 'plugin-autoupdate-filter' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Track the final decision after all filters have run
	 *
	 * @param bool|null $update Whether to update the plugin
	 * @param object    $item   The plugin update object
	 *
	 * @return bool|null The final update decision
	 */
	public function track_final_update_decision( $update, $item ): ?bool {
		if ( ! is_object( $item ) || empty( $item->slug ) ) {
			return $update;
		}

		// Get current version using the plugin file from $item
		$current_version = ! empty( $item->plugin ) ?
			$this->helpers->get_installed_plugin_version( $item->plugin ) :
			'unknown';

		// Skip logging if new version not higher
		if ( $current_version >= $item->new_version ) {
			return $update;
		}

		// Get all our previously collected data
		$update_info    = $this->logger->get_update_info( $item->slug, $item->new_version );
		$filter_details = $update_info['Filter Details'] ?? array();

		// Check package status
		$has_package   = ! empty( $item->package );
		$package_url   = $has_package ? $item->package : '';
		$response_code = null;
		if ( $has_package ) {
			$response = wp_safe_remote_head( $package_url );
			if ( ! is_wp_error( $response ) ) {
				$response_code = wp_remote_retrieve_response_code( $response );
			}
		}

		// Use our stored filter details to determine status in priority order
		$status = 'Auto-update allowed';
		if ( ! empty( $filter_details['Updates disabled by OpsOasis'] ) ) {
			$status = 'Auto-update blocked - disabled by OpsOasis';
		} elseif ( false === $has_package || ( $response_code >= 400 && $response_code < 500 ) ) {
			$status = 'Auto-update unavailable - no valid update package';
		} elseif ( isset( $filter_details['Delay passed'] ) && false === $filter_details['Delay passed'] ) {
			$status = 'Auto-update blocked - delay period';
		} elseif ( ! empty( $filter_details['Holiday period'] ) ) {
			$status = 'Auto-update blocked - holiday period';
		} elseif ( ! empty( $filter_details['Outside business hours'] ) ) {
			$status = 'Auto-update blocked - outside business hours';
		}

		// Match the expected array structure from track_update_info
		$final_update_info = array(
			'timestamp'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_name'     => $item->slug,
			'Status'          => $status,
			'Version Info'    => array(
				'Current Version' => $current_version,
				'New Version'     => $item->new_version,
			),
			'Update Package'  => array(
				'Has Update Package'    => $has_package,
				'Package URL'           => $package_url,
				'Package Response Code' => $response_code,
			),
			'Filter Details'  => $filter_details,
			'Update Tracking' => array(
				'Attempt Status' => 'Pending',
				'Was Attempted'  => false,
			),
		);

		$this->logger->write_to_log( $item->slug, $item->new_version, $final_update_info );

		return $update;
	}

	/**
	 * Fix malformed update objects that have incorrect slug/plugin properties
	 *
	 * @param object|bool $transient The update_plugins transient object
	 * @return object|bool The modified transient object
	 */
	public function fix_malformed_update_objects( $transient ) {
		if ( empty( $transient->response ) ) {
			return $transient;
		}

		foreach ( $transient->response as $plugin_file => $update_data ) {
			if ( ! isset( $update_data->plugin ) ) {
				if ( isset( $update_data->slug ) && strpos( $update_data->slug, '/' ) !== false ) {
					$update_data->plugin = $update_data->slug;
					$update_data->slug   = dirname( $update_data->slug );
				} else {
					$update_data->plugin = $plugin_file;
				}
			}
		}

		return $transient;
	}
	/**
	 * Track the result of the update process
	 *
	 * @param WP_Upgrader $upgrader   WP_Upgrader instance
	 * @param array       $hook_extra Array of bulk item update data
	 */
	public function track_update_result( $upgrader, $hook_extra ): void {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$plugin_file = $hook_extra['plugin'] ?? '';
		if ( empty( $plugin_file ) ) {
			return;
		}

		// Get plugin slug from file
		$plugin_slug = dirname( $plugin_file );

		// Get the plugin data
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
		$version     = $plugin_data['Version'] ?? '';

		$result_info = array(
			'Update Result' => array(
				'Status'    => $upgrader->plugin_info() ? 'Success' : 'Failed',
				'Attempted' => true,
			),
		);

		if ( is_wp_error( $upgrader->skin->result ) ) {
			$result_info['Update Result']['Error'] = $upgrader->skin->result->get_error_message();
		}

		$this->logger->add_update_process_info( $plugin_slug, $version, $result_info );
	}
}
