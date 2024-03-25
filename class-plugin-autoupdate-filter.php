<?php
/**
 * Plugin Autoupdate Filter class
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter {

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

			error_log( "Plugin Autoupdate Filter: Unable to retrieve the autoupdate settings ({$error_message})" ); // phpcs:disable WordPress.PHP.DevelopmentFunctions
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
				throw new RuntimeException( $response->get_error_message(), $response->get_error_code() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Check that the response code is a 2xx code.
			if ( ! \str_starts_with( (string) $response_code, '2' ) ) {
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
	 * @param bool|null   $update Whether to update the plugin or not. This can be bool or null as per the docs
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function filter_maybe_disable_all_autoupdates( $update, $item ): bool {

		if ( isset( $this->settings->disable_all ) || null === $update ) {
			return false;
		}

		return $update;
	}

	/**
	 * Enable or disable plugin auto-updates based on time and day of the week.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function filter_auto_update_specific_times( $update, $item ): bool {

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

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $holidays as $holiday ) {
			$start = $holiday['start'];
			$end   = $holiday['end'];
			if ( $start <= $now && $now <= $end ) {
				return false;
			}
		}

		$hours = array(
			'start'      => '10', // 6am Eastern
			'end'        => '23', // 7pm Eastern
			'friday_end' => '19', // 3pm Eastern on Fridays
		);
		$hours = apply_filters( 'plugin_autoupdate_filter_hours', $hours );

		$days_off = array(
			'Sat',
			'Sun',
		);
		$days_off = apply_filters( 'plugin_autoupdate_filter_days_off', $days_off );

		$hour = gmdate( 'H' ); // Current hour
		$day  = gmdate( 'D' );  // Current day of the week

		// If outside business hours, disable auto-updates
		if ( $hour < $hours['start'] || $hour > $hours['end'] || in_array( $day, $days_off, true ) || ( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			return false;
		}

		// Otherwise, plugins will autoupdate regardless of settings in wp-admin
		return true;
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
	 * @param array $email The email details, including 'to', 'subject', 'body', 'headers'.
	 * @param int $failures The number of failures encountered while upgrading.
	 * @param mixed $update_results The results of all attempted updates.
	 *
	 * @return array $email The email details with the 'to' address modified.
	 */
	public function filter_custom_debug_email( $email, $failures, $update_results ): array {
		$email['to'] = 'concierge@wordpress.com';
		return $email;
	}

	/**
	 * Customize automatic update setting HTML for plugins page in wp-admin.
	 *
	 * @param string $html       HTML for automatic update settings.
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
	 *
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
	 *
	 */
	public function output_auto_updates_disabled_admin_notice(): void {
		// add notice to the top of the screen
		global $pagenow;
		if ( 'plugins.php' === $pagenow && isset( $this->settings->disable_all ) ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="notice notice-error"><p><strong style="color:red;"> Caution:</strong> All automatic updates are deactivated. Please contact the WordPress Special Projects team before manually updating plugins.</p></div>';
				}
			);

		}
	}

}
$plugin_autoupdate_filter = new Plugin_Autoupdate_Filter();
$plugin_autoupdate_filter->init();
