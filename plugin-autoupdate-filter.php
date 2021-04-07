<?php
/*
Plugin Name: Plugin Autoupdate Filter
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-filter
Description: Plugin which sets plugin autoupdates to always on, but only happen during specific times.
Version: 1.0
Author: WordPress.com Special Projects
Author URI:
License: GPLv3
*/

function auto_update_specific_times ( $update, $item ) {

  // Set these to run in U.S. Eastern time zone, which is most of our business hours
  date_default_timezone_set('America/New_York');

  $start = "09"; // 9am
  $end = "14"; // 2pm

  $hour = date('H'); // Current hour
  $day = date('D');  // Current day of the week

  // If outside business hours, disable auto-updates
  if ( $hour < $start || $hour > $end || 'Sat' == $day  || 'Sun' == $day )
  {
    return false;

		$site_url = site_url();
		$slug = $item->slug;

		log_to_slack(
				sprintf(
						'PLUGIN UPDATED: %s updated on %s',
						$slug,
						$site_url
				)
		);
  }

  // Otherwise, plugins will autoupdate regardless of settings in wp-admin
  return true;
}
add_filter( 'auto_update_plugin', 'auto_update_specific_times', 10, 2 );

// Replace automatic update wording on plugin management page in admin
add_filter( 'plugin_auto_update_setting_html', function( $html, $plugin_file, $plugin_data ) { return 'Auto-updates managed by WP Special Projects team <br/>(enabled during business hours)'; } , 11, 3 );


// ping slack helper function
function log_to_slack( $message ) {

		define( SLACK_WEBHOOK_URL, 'https://webhooks.wpspecialprojects.com/hooks/log-to-slack' );

		$message = json_encode( array( 'message' => $message ) );

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => 'POST',
				'content' => $message,
			),
		);

		$context = stream_context_create( $options );
		$result  = @file_get_contents( SLACK_WEBHOOK_URL, false, $context );

		return json_decode( $result );
	}


// ping Slack when any plugin updates
add_action( 'upgrader_process_complete', 'ping_on_update',10, 2);

function ping_on_update( $upgrader_object, $options ) {

  if( $options['action'] == 'update' && $options['type'] == 'plugin' && isset( $options['plugins'] ) ) {

    $site_url = site_url();

    foreach( $options['plugins'] as $plugin ) {

       log_to_slack(
           sprintf(
               'PLUGIN UPDATED: %s updated on %s',
               $plugin,
               $site_url
           )
       );

       }
    }
}
