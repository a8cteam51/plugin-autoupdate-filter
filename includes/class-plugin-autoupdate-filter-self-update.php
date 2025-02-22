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
		add_filter( 'update_plugins_github.com', array( $this, 'self_update' ), 10, 4 );
	}

	/**
	 * Check for updates to this plugin
	 *
	 * @param array  $update      Array of update data.
	 * @param array  $plugin_data Array of plugin data.
	 * @param string $plugin_file Path to plugin file.
	 * @param string $locales     Locale code.
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

		$update_info = array(
			'Status'          => 'Checking for self updates',
			'Version Info'    => array(
				'Current Version' => $plugin_data['Version'],
			),
			'Update Controls' => array(
				'Plugin File' => $plugin_file,
			),
		);

		// Get latest version from GitHub
		$response = wp_remote_get(
			'https://api.github.com/repos/a8cteam51/plugin-autoupdate-filter/releases/latest',
			array(
				'user-agent' => 'wpspecialprojects',
			)
		);

		if ( is_wp_error( $response ) ) {
			$update_info['Status'] = 'Error checking for updates: ' . $response->get_error_message();
			$this->logger->log_update_attempt( 'plugin-autoupdate-filter', $plugin_data['Version'], $update_info );
			return false;
		}

		$output = json_decode( wp_remote_retrieve_body( $response ), true );

		// Validate GitHub API response
		if ( empty( $output ) || ! is_array( $output ) || ! isset( $output['tag_name'] ) ) {
			$update_info['Status']   = 'Error: Invalid GitHub API response';
			$update_info['Response'] = $output;
			$this->logger->log_update_attempt( 'plugin-autoupdate-filter', $plugin_data['Version'], $update_info );
			return false;
		}

		$new_version_number                         = $output['tag_name'];
		$update_info['Version Info']['New Version'] = $new_version_number;

		// Skip if no actual update available
		if ( $plugin_data['Version'] === $new_version_number ) {
			$update_info['Status'] = 'No update available';
			$this->logger->log_update_attempt( 'plugin-autoupdate-filter', $new_version_number, $update_info );
			return false;
		}

		// Validate required update data exists
		if ( ! isset( $output['html_url'], $output['assets'][0]['browser_download_url'] ) ) {
			$update_info['Status']   = 'Error: Missing required update data';
			$update_info['Response'] = $output;
			$this->logger->log_update_attempt( 'plugin-autoupdate-filter', $new_version_number, $update_info );
			return false;
		}

		$update_info['Status']                         = 'Update available';
		$update_info['Update Controls']['Update URL']  = $output['html_url'];
		$update_info['Update Controls']['Package URL'] = $output['assets'][0]['browser_download_url'];

		$this->logger->log_update_attempt( 'plugin-autoupdate-filter', $new_version_number, $update_info );

		return array(
			'slug'    => $plugin_data['TextDomain'],
			'version' => $new_version_number,
			'url'     => $output['html_url'],
			'package' => $output['assets'][0]['browser_download_url'],
		);
	}
}

// Initialize the class with the logger
add_action(
	'init',
	function() {
		$logger      = new Plugin_Autoupdate_Filter_Logger();
		$self_update = new Plugin_Autoupdate_Filter_Self_Update( $logger );
		$self_update->init();
	}
);
