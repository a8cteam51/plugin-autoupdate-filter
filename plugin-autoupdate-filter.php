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

add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter( 'allow_minor_auto_core_updates', '__return_false' );
add_filter( 'allow_major_auto_core_updates', '__return_false' );
add_filter( 'allow_dev_auto_core_updates', '__return_false' );
add_filter( 'auto_update_core', '__return_false' );
add_filter( 'wp_auto_update_core', '__return_false' );
add_filter( 'auto_core_update_send_email', '__return_false' );
add_filter( 'send_core_update_notification_email', '__return_false' );
add_filter( 'auto_update_plugin', '__return_false' );
add_filter( 'auto_update_theme', '__return_false' );
add_filter( 'automatic_updates_send_debug_email', '__return_false' );
add_filter( 'automatic_updates_send_debug_email ', '__return_false', 1 );
if( !defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) define( 'AUTOMATIC_UPDATER_DISABLED', true );
if( !defined( 'WP_AUTO_UPDATE_CORE') ) define( 'WP_AUTO_UPDATE_CORE', false );

add_filter( 'pre_http_request', 'block_request', 10, 3 );

function block_request($pre, $args, $url) {
	/* Empty url */
	if( empty( $url ) ) {
		return $pre;
	}

	/* Invalid host */
	if( !$host = parse_url($url, PHP_URL_HOST) ) {
		return $pre;
	}

	$url_data = parse_url( $url );

	/* block request */
	if( false !== stripos( $host, 'api.wordpress.org' ) && (false !== stripos( $url_data['path'], 'update-check' ) || false !== stripos( $url_data['path'], 'version-check' ) || false !== stripos( $url_data['path'], 'browse-happy' ) || false !== stripos( $url_data['path'], 'serve-happy' )) ) {
		return true;
	}

	return $pre;
}

// Replace automatic update wording on Plugin management page in admin
// add_filter( 'plugin_auto_update_setting_html', function( $html, $plugin_file, $plugin_data ) { return 'Auto-updates managed by WP Special Projects team <br/>(enabled during business hours)'; } , 100, 3 );

// ping Slack when any plugin updates

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

<<<<<<< Updated upstream
=======
			 // error_log( 'In ' . __FUNCTION__ . '(), backtrace = ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true));

>>>>>>> Stashed changes
       }
    }
}
