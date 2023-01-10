<?php
/**
 * Plugin Autoupdate Filter Self Update class
 * sets up autoupdates for this GitHub-hosted plugin
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter_Self_Update {

	/**
	 * Plugin_Autoupdate_Filter_Self_Update constructor.
	 */
	public function __construct() {
		add_filter( 'update_plugins_github.com', array( $this, 'self_update' ), 10, 4 );
	}

	/**
	 * Check for updates to this plugin
	 *
	 * @param array  $update   Array of update data.
	 * @param array  $plugin_data Array of plugin data.
	 * @param string $plugin_file Path to plugin file.
	 * @param string $locales    Locale code.
	 *
	 * @return array|bool Array of update data or false if no update available.
	 */
	public function self_update( $update, array $plugin_data, string $plugin_file, $locales ) {
		// only check this plugin
		if ( 'plugin-autoupdate-filter/plugin-autoupdate-filter.php' !== $plugin_file ) {
			return $update;
		}

		// already completed update check elsewhere
		if ( ! empty( $update ) ) {
			return $update;
		}

		// let's go get the latest version number from GitHub
		$response = wp_remote_get(
			'https://api.github.com/repos/a8cteam51/plugin-autoupdate-filter/releases/latest',
			array(
				'user-agent' => 'wpspecialprojects',
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		} else {
			$output = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		$new_version_number  = $output['tag_name'];
		$is_update_available = version_compare( $plugin_data['Version'], $new_version_number, '<' );

		if ( ! $is_update_available ) {
			return false;
		}

		$new_url     = $output['html_url'];
		$new_package = $output['assets'][0]['browser_download_url'];

		return array(
			'slug'    => $plugin_data['TextDomain'],
			'version' => $new_version_number,
			'url'     => $new_url,
			'package' => $new_package,
		);
	}
}
new Plugin_Autoupdate_Filter_Self_Update();
