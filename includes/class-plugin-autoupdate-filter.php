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
	 * Initialize the plugin
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
		add_filter( 'auto_update_plugin', array( $this, 'filter_auto_update_specific_times' ), 10, 2 );
		add_filter( 'auto_update_core', array( $this, 'filter_auto_update_specific_times' ), 10, 2 );

		// enforce a delay on all plugin autoupdates, based on release date
		add_filter( 'auto_update_plugin', array( $this, 'filter_enforce_delay' ), 11, 2 );

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
		add_filter( 'auto_update_plugin', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX, 2 );
		add_filter( 'auto_update_core', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX, 2 );
		add_filter( 'auto_update_theme', array( $this, 'filter_maybe_disable_all_autoupdates' ), PHP_INT_MAX, 2 );
		add_action( 'admin_init', array( $this, 'output_auto_updates_disabled_admin_notice' ) );
	}

	/**
	 * Load settings from the centralized settings page
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
			'Status'          => 'Checking global updates status',
			'Update Controls' => array(
				'Global Updates Enabled' => ! isset( $this->settings->disable_all ),
			),
		);

		if ( isset( $this->settings->disable_all ) || null === $update ) {
			$update_info['Status'] = 'Updates globally disabled';
			$this->logger->log_update_attempt( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
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
			$update_info = array(
				'Status' => 'Update blocked - invalid update data',
				'Update Controls' => array(
					'Invalid Data' => array(
						'Missing Slug' => ! isset( $item->slug ),
						'Missing Version' => empty( $item->new_version ),
					),
					'Raw Item' => wp_json_encode( $item ),
				),
			);
			$this->logger->log_update_attempt( 
				$item->slug ?? 'unknown', 
				$item->new_version ?? 'unknown', 
				$update_info 
			);
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

		$update_info = array(
			'Status' => 'Checking business hours',
		);

		// Only add controls if update is blocked
		if ( $hour < $hours['start'] || $hour > $hours['end'] ||
				in_array( $day, $days_off, true ) ||
				( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			$update_info['Update Controls'] = array(
				'Current Time'          => $now,
				'Within Business Hours' => array(
					'Hour Check'  => $hour >= $hours['start'] && $hour <= $hours['end'],
					'Day Check'   => ! in_array( $day, $days_off, true ),
					'Friday Rule' => 'Fri' !== $day || $hour <= $hours['friday_end'],
				),
			);
		}

		// Check holidays
		foreach ( $holidays as $holiday_name => $holiday ) {
			$update_info['Update Controls']['Holiday Status'][ $holiday_name ] = array(
				'Period' => $holiday['start'] . ' to ' . $holiday['end'],
				'Active' => $holiday['start'] <= $now && $now <= $holiday['end'],
			);

			if ( $holiday['start'] <= $now && $now <= $holiday['end'] ) {
				$update_info['Status'] = 'Update blocked - holiday period';
				$this->logger->log_update_attempt( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
				return false;
			}
		}

		if ( $hour < $hours['start'] || $hour > $hours['end'] ||
				in_array( $day, $days_off, true ) ||
				( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			$update_info['Status'] = 'Update blocked - outside business hours';
			$this->logger->log_update_attempt( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
			return false;
		}

		$update_info['Status'] = 'Update allowed - within business hours';
		$this->logger->log_update_attempt( $item->slug ?? 'unknown', $item->new_version ?? 'unknown', $update_info );
		return true;
	}

	/**
	 * Check if updates are disabled globally
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
				'Status' => 'Update blocked - invalid update data',
				'Update Controls' => array(
					'Invalid Data' => array(
						'Missing Version' => empty( $item->new_version ),
					),
					'Raw Item' => wp_json_encode( $item ),
				),
			);
			$this->logger->log_update_attempt( 
				$item->slug ?? 'unknown', 
				$item->new_version ?? 'unknown', 
				$update_info 
			);
			return false;
		}

		$helpers = new Plugin_Autoupdate_Filter_Helpers( $this->logger );

		// Try to get plugin file from either plugin property or id property
		$plugin_file = $item->plugin ?? $item->id ?? '';
		
		// If plugin file is empty but we have a slug, try to construct the plugin file path
		if (empty($plugin_file) && !empty($item->slug)) {
			$plugin_file = $item->slug . '/' . $item->slug . '.php';
		}

		$plugin_slug = $item->slug ?? dirname($plugin_file);
		$plugin_new_version = $item->new_version;

		// Get current version
		$current_version = $helpers->get_installed_plugin_version( $plugin_file );

		// Initialize update info
		$update_info = array(
			'Status'          => 'Checking update requirements',
			'Version Info'    => array(
				'Current Version' => $current_version,
				'New Version'     => $plugin_new_version,
			),
			'Update Controls' => array(
				'Has Update Package'           => ! empty( $item->package ),
				'Is Canary Site'               => false,
				'Updates disabled by OpsOasis' => $this->are_updates_disabled(),
			),
		);

		// Check if updates are disabled globally
		if ( $this->are_updates_disabled() ) {
			$update_info['Status'] = 'Update blocked - disabled by OpsOasis';
			$this->logger->log_update_attempt( $plugin_slug, $plugin_new_version, $update_info );
			return false;
		}

		// If no package is available (no paid license, or not connected to WooCommerce.com)
		if ( empty( $item->package ) ) {
			$update_info['Status'] = 'Update unavailable - no update package';
			$this->logger->log_update_attempt( $plugin_slug, $plugin_new_version, $update_info );
			return false;
		}

		// Check for canary site status
		$site_url = wp_parse_url( home_url(), PHP_URL_HOST );
		$update_info['Update Controls']['Is Canary Site'] = isset( $this->settings->canary_sites ) &&
			in_array( $site_url, $this->settings->canary_sites, true );

		$update_info['Status'] = 'Auto-update scheduled';
		$this->logger->log_update_attempt( $plugin_slug, $plugin_new_version, $update_info );
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
}
