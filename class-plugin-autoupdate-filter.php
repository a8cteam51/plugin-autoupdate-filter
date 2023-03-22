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
	public function init() {

		// setup plugins to autoupdate _unless_ it's during specific day/time
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_specific_times' ), 10, 2 );

		// Replace automatic update wording on plugin management page in admin
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'plugin_autoupdate_filter_custom_setting_html' ), 11, 3 );

		// Always send auto-update emails to T51 concierge email address
		add_filter( 'auto_plugin_theme_update_email', array( $this, 'plugin_autoupdate_filter_custom_update_emails' ), 10, 4 );
		add_filter( 'automatic_updates_debug_email', array( $this, 'plugin_autoupdate_filter_custom_debug_email' ), 10, 3 );

		// re-enable core update emails which are disabled in an mu-plugin at the Atomic platform level
		add_filter( 'automatic_updates_send_debug_email', '__return_true', 11 );
		add_filter( 'auto_core_update_send_email', '__return_true', 11 );
		add_filter( 'auto_plugin_update_send_email', '__return_true', 11 );
		add_filter( 'auto_theme_update_send_email', '__return_true', 11 );

	}

	/**
	 * Enable or disable plugin auto-updates based on time and day of the week.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function auto_update_specific_times( $update, $item ) {

		$holidays = array(
			'christmas' => array(
				'start' => '2021-12-23 00:00:00',
				'end'   => '2021-12-26 00:00:00',
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
	public function plugin_autoupdate_filter_custom_update_emails( $email, $type, $successful_updates, $failed_updates ) {
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
	public function plugin_autoupdate_filter_custom_debug_email( $email, $failures, $update_results ) {
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
	public function plugin_autoupdate_filter_custom_setting_html( $html, $plugin_file, $plugin_data ) {
		return 'Automatic updates managed by <strong>Plugin Autoupdate Filter</strong>';
	}

}
$plugin_autoupdate_filter = new Plugin_Autoupdate_Filter();
$plugin_autoupdate_filter->init();
