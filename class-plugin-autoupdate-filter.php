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
	 * The settings loaded from the JSON file.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Initialize WordPress hooks and load settings from JSON.
	 */
	public function init() {
		$this->load_settings_from_json();

		// "pause all" plugin and core autoupdates
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_pause_all' ), 10, 2 );
		add_filter( 'auto_update_core', array( $this, 'auto_update_pause_all' ), 10, 2 );

		// setup plugins and core to autoupdate _unless_ it's during specific day/time
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_specific_times' ), 10, 2 );
		add_filter( 'auto_update_core', array( $this, 'auto_update_specific_times' ), 10, 2 );

		// filter plugins based on the version control specified in the centralized_settings json
		add_filter( 'auto_update_plugin', array( $this, 'pause_updates_for_specific_plugins' ), 10, 2 );

		// Replace automatic update wording on plugin management page in admin
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'plugin_autoupdate_filter_custom_setting_html' ), 11, 3 );

		// Append text to upgrade text on plugins page for plugins explicitly set to not autoupdate
		add_action( 'admin_init', array( $this, 'plugin_autoupdate_filter_change_upgrade_message_for_specific_plugins' ) );

		// If set to "pause all" updates, show notice on plugins page
		add_action( 'admin_init', array( $this, 'plugin_autoupdate_filter_pause_all_notice' ) );

		// Always send auto-update emails to a specific email address
		add_filter( 'auto_plugin_theme_update_email', array( $this, 'plugin_autoupdate_filter_custom_update_emails' ), 10, 4 );
		add_filter( 'auto_core_update_email', array( $this, 'plugin_autoupdate_filter_custom_update_emails' ), 10, 4 );
		add_filter( 'automatic_updates_debug_email', array( $this, 'plugin_autoupdate_filter_custom_debug_email' ), 10, 3 );

		// re-enable core update emails which are disabled in an mu-plugin at the Atomic platform level
		add_filter( 'automatic_updates_send_debug_email', '__return_true', 11 );
		add_filter( 'auto_core_update_send_email', '__return_true', 11 );
		add_filter( 'auto_plugin_update_send_email', '__return_true', 11 );
		add_filter( 'auto_theme_update_send_email', '__return_true', 11 );
	}

	/**
	 * Load settings from the centralized JSON file.
	 */
	private function load_settings_from_json() {
		$json_path = __DIR__ . '/centralized_settings.json';
		if ( file_exists( $json_path ) ) {
			$json_content = file_get_contents( $json_path );
			$settings     = json_decode( $json_content, true );
			if ( is_array( $settings ) ) {
				$this->settings = $settings;
			}
		}
	}

	/**
	 * If we have hit the "pause all" switch, don't autoupdate.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function auto_update_pause_all( $update, $item ) {

		if ( isset( $this->settings['pause_all'] ) && true === $this->settings['pause_all'] ) {
			return false;
		}

		return $update;
	}

	/**
	 * The plugin update filter checks each plugin against the rules defined in the settings array.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function pause_updates_for_specific_plugins( $update, $item ) {
		if ( ! $update ) {
			return $update;
		}

		// Iterate through pause_plugins rules and apply them
		foreach ( $this->settings['pause_plugins'] as $rule ) {
			// If the plugin being updated matches a slug from our pause list
			if ( isset( $item->slug ) && $item->slug === $rule['slug'] ) {
				// Current plugin version to be updated to
				$version = isset( $item->new_version ) ? $item->new_version : null;

				if ( null === $version ) {
					continue; // If there's no new version to update to, just continue with the next rule.
				}

				// Check if the version to update is in the range we should pause
				if ( isset( $rule['skip_exact_version'] ) && $version === $rule['skip_exact_version'] ) {
					return false; // Don't update if the exact version should be skipped.
				}
				if ( isset( $rule['skip_version_higher_than_or_equal_to'] ) && version_compare( $version, $rule['skip_version_higher_than_or_equal_to'], '>=' ) ) {
					return false; // Don't update if the version is higher than or equal to the one in the rule.
				}
				if ( isset( $rule['skip_version_lower_than'] ) && version_compare( $version, $rule['skip_version_lower_than'], '<' ) ) {
					return false; // Don't update if the version is lower than the one in the rule.
				}
			}
		}

		return $update; // If none of the rules match, allow updating the plugin.
	}

	/**
	 * Enable or disable plugin auto-updates based on time and day of the week.
	 *
	 * @param bool   $update Whether to update the plugin or not.
	 * @param object $item   The plugin or core update object.
	 *
	 * @return bool True to update, false to not update.
	 */
	public function auto_update_specific_times( $update, $item ) {
		$now = gmdate( 'Y-m-d H:i:s' );

		// Apply pre-defined pause periods
		$pause_periods = array(
			'christmas' => array(
				'start' => gmdate( 'Y' ) . '-12-23 00:00:00',
				'end'   => gmdate( 'Y' ) . '-12-31 23:59:59',
			),
			'new_years' => array(
				'start' => gmdate( 'Y' ) . '-01-01 00:00:00',
				'end'   => gmdate( 'Y' ) . '-01-02 23:59:59',
			),
		);

		// Add pause periods from JSON settings
		if ( isset( $this->settings['pause_periods'] ) ) {
			foreach ( $this->settings['pause_periods'] as $extra_pause_period ) {
				if ( isset( $extra_pause_period['name'], $extra_pause_period['start'], $extra_pause_period['end'] ) ) {
					$pause_periods[ $extra_pause_period['name'] ] = array(
						'start' => $extra_pause_period['start'],
						'end'   => $extra_pause_period['end'],
					);
				}
			}
		}

		$pause_periods = apply_filters( 'plugin_autoupdate_filter_holidays', $pause_periods ); // keep for backward compatibility
		$pause_periods = apply_filters( 'plugin_autoupdate_filter_pause_periods', $pause_periods );

		foreach ( $pause_periods as $pause_period ) {
			$start = $pause_period['start'];
			$end   = $pause_period['end'];
			if ( $start <= $now && $now <= $end ) {
				return false;
			}
		}

		// Default hours, will be overridden by JSON settings if they exist
		$hours = array(
			'start'      => '10', // 6am Eastern
			'end'        => '23', // 7pm Eastern
			'friday_end' => '19', // 3pm Eastern on Fridays
		);
		$hours = array_merge( $hours, $this->settings['pause_times'] ?? array() );
		$hours = apply_filters( 'plugin_autoupdate_filter_hours', $hours );

		// Default days off, will be overridden by JSON settings if they exist
		$days_off = $this->settings['pause_days']['days_off'] ?? array( 'Sat', 'Sun' );
		$days_off = apply_filters( 'plugin_autoupdate_filter_days_off', $days_off );

		// Get current hour and day
		$hour = gmdate( 'H' );
		$day  = gmdate( 'D' );

		// If outside business hours or during days off, disable auto-updates
		if ( $hour < $hours['start'] || $hour > $hours['end'] || in_array( $day, $days_off, true ) || ( 'Fri' === $day && $hour > $hours['friday_end'] ) ) {
			return false;
		}

		return $update;
	}

	/**
	 * Customize auto-update email recipients.
	 *
	 * @param array  $email              Array of email data.
	 * @param string $type               Type of email to send.
	 * @param array  $successful_updates Array of successful updates.
	 * @param array  $failed_updates     Array of failed updates.
	 *
	 * @return array Modified array of email data.
	 */
	public function plugin_autoupdate_filter_custom_update_emails( $email, $type, $successful_updates, $failed_updates ) {
		$email['to'] = $this->settings['email'] ?? 'concierge@wordpress.com'; // Fallback email if not defined in settings
		return $email;
	}

	/**
	 * Filters the recipient email address for plugin update failure notifications.
	 *
	 * @param array $email         The email details, including 'to', 'subject', 'body', 'headers'.
	 * @param int   $failures      The number of failures encountered while upgrading.
	 * @param mixed $update_results The results of all attempted updates.
	 *
	 * @return array Modified email with the 'to' address.
	 */
	public function plugin_autoupdate_filter_custom_debug_email( $email, $failures, $update_results ) {
		$email['to'] = $this->settings['debug_email'] ?? 'concierge@wordpress.com'; // Fallback email if not defined in settings
		return $email;
	}

	/**
	 * Customize automatic update setting HTML for plugins page in wp-admin.
	 *
	 * @param string $html        HTML for automatic update settings.
	 * @param string $plugin_file Path to the plugin file.
	 * @param array  $plugin_data Array of plugin data.
	 *
	 * @return string Customized HTML for automatic update settings.
	 */
	public function plugin_autoupdate_filter_custom_setting_html( $html, $plugin_file, $plugin_data ) {
		$plugin_obj                    = new stdClass();
		$plugin_obj->slug              = dirname( $plugin_file );
		$plugin_allowed_to_update_bool = $this->pause_updates_for_specific_plugins( true, $plugin_obj );

		if ( false === $plugin_allowed_to_update_bool ) {
			return 'Autoupdates have been explicitly deactivated for this plugin.';
		}

		return 'Automatic updates managed by <strong>Plugin Autoupdate Filter</strong>';
	}

	/**
	 * Append text to upgrade text on plugins page for plugins explicitly set to not autoupdate
	 */
	public function plugin_autoupdate_filter_change_upgrade_message_for_specific_plugins() {
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugin_obj                    = new stdClass();
			$plugin_obj->slug              = dirname( $plugin_file );
			$plugin_allowed_to_update_bool = $this->pause_updates_for_specific_plugins( true, $plugin_obj );

			if ( false === $plugin_allowed_to_update_bool ) {
				add_filter(
					"in_plugin_update_message-{$plugin_file}",
					function () {
						echo ' <strong style="color:red;"> Caution:</strong> Autoupdates have been explicitly deactivated for this plugin. Please contact the WordPress Special Projects team before manually updating.';
					},
					10,
					2
				);

				global $pagenow;
				if ( 'plugins.php' === $pagenow ) {
					add_action(
						'admin_notices',
						function() use ( $plugin_obj ) {
							echo '<div class="error"><p><strong style="color:red;"> Caution:</strong> Autoupdates have been explicitly deactivated for ', esc_html( $plugin_obj->slug ), '. Please contact the WordPress Special Projects team before manually updating.</p></div>';
						}
					);
				}
			}
		}
	}

	/**
	 * Add notice on plugins page if set to "pause all"
	 */
	public function plugin_autoupdate_filter_pause_all_notice() {
		if ( isset( $this->settings['pause_all'] ) && true === $this->settings['pause_all'] ) {
			global $pagenow;
			if ( 'plugins.php' === $pagenow ) {
				add_action(
					'admin_notices',
					function() {
						echo '<div class="error"><p><strong style="color:red;"> Caution:</strong> Autoupdates have been explicitly deactivated for all plugins. Please contact the WordPress Special Projects team before manually updating.</p></div>';
					}
				);
			}
		}
	}
}

$plugin_autoupdate_filter = new Plugin_Autoupdate_Filter();
$plugin_autoupdate_filter->init();
