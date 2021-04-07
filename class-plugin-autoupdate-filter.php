<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter {

	public function __construct() {

    define( SLACK_WEBHOOK_URL, 'https://webhooks.wpspecialprojects.com/hooks/log-to-slack' );

		// setup plugins to autoupdate _unless_ it's during specific day/time
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update_specific_times' ), 10, 2 );

		// Replace automatic update wording on plugin management page in admin
		add_filter(
			'plugin_auto_update_setting_html',
			function( $html, $plugin_file, $plugin_data ) {
				return 'Auto-updates managed by WP Special Projects team';
			},
			11,
			3
		);

		// ping Slack when any plugin updates
		add_action( 'upgrader_process_complete', array( __CLASS__, 'ping_on_update' ), 10, 2 );
	}

	// setup plugins to autoupdate _unless_ it's during specific day/time
	public function auto_update_specific_times( $update, $item ) {

		$start = '10'; // 6am Eastern
		$end   = '23'; // 7pm Eastern

		$hour = gmdate( 'H' ); // Current hour
		$day  = gmdate( 'D' );  // Current day of the week

		// If outside business hours, disable auto-updates
		if ( $hour < $start || $hour > $end || 'Sat' === $day || 'Sun' === $day ) {
			$site_url = site_url();
			$slug     = $item->slug;

			$this->log_to_slack(
				sprintf(
					'PLUGIN AUTOUPDATE FILTER: %s prevented from updating on %s',
					$slug,
					$site_url
				)
			);
			return false;
		}

			// Otherwise, plugins will autoupdate regardless of settings in wp-admin
			return true;
	}

	public function ping_on_update( $upgrader_object, $options ) {

		if ( 'update' === $options['action'] && 'plugin' === $options['type'] && isset( $options['plugins'] ) ) {

			$site_url = site_url();

			foreach ( $options['plugins'] as $plugin ) {

				$this->log_to_slack(
					sprintf(
						'PLUGIN AUTOUPDATE FILTER: %s updated on %s',
						$plugin,
						$site_url
					)
				);

			}
		}
	}

  // ping slack helper function
  public function log_to_slack( $message ) {

    $message = wp_json_encode( array( 'message' => $message ) );

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

    return wp_json_decode( $result );
  }

}
new Plugin_Autoupdate_Filter();
