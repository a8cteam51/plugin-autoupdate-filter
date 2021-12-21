<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter {

	public function __construct() {

		// setup plugins to autoupdate _unless_ it's during specific day/time
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_specific_times' ), 10, 2 );

		// Replace automatic update wording on plugin management page in admin
		add_filter(
			'plugin_auto_update_setting_html',
			function( $html, $plugin_file, $plugin_data ) {
				return 'Automatic updates managed by <strong>Plugin Autoupdate Filter</strong>';
			},
			11,
			3
		);

	}

	// setup plugins to autoupdate _unless_ it's during specific day/time
	public function auto_update_specific_times( $update, $item ) {

		$holidays = array(
			'christmas' => array(
				'start' => '2021-12-23 00:00:00',
				'end'   => '2021-12-26 00:00:00',
			),
		);
		$holidays = apply_filters( 'plugin_autoupdate_filter_holidays', $holidays );

		$now = gmdate("Y-m-d H:i:s");

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

}
new Plugin_Autoupdate_Filter();
