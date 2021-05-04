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
				return 'Auto-updates managed by WP Special Projects team';
			},
			11,
			3
		);

	}

	// setup plugins to autoupdate _unless_ it's during specific day/time
	public function auto_update_specific_times( $update, $item ) {

		$start 			= '10'; // 6am Eastern
		$end   			= '23'; // 7pm Eastern
		$friday_end = '19'; // 3pm Eastern on Fridays

		$hour = gmdate( 'H' ); // Current hour
		$day  = gmdate( 'D' );  // Current day of the week

		// If outside business hours, disable auto-updates
		if ( $hour < $start || $hour > $end || 'Sat' === $day || 'Sun' === $day || ( 'Fri' === $day && $hour > $friday_end ) ) {
			return false;
		}

			// Otherwise, plugins will autoupdate regardless of settings in wp-admin
			return true;
	}

}
new Plugin_Autoupdate_Filter();
