<?php
/**
 * Plugin Autoupdate Filter Helpers class
 *
 * @package Plugin_Autoupdate_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Plugin_Autoupdate_Filter_Helpers {

	/**
	 * @var array Cache of installed plugins
	 */
	private $plugins;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// No logger required
	}

	/**
	 * Determines whether a plugin should be updated based on release version and delay rules.
	 *
	 * @param   string $plugin_slug         Slug of the plugin.
	 * @param   string $plugin_new_version  The version that the plugin would be updated to.
	 * @param   string $plugin_file         The relative path to the plugin file.
	 *
	 * @return  bool|null True if the plugin should be updated, false otherwise.
	 */
	public function has_delay_passed( string $plugin_slug, string $plugin_new_version, string $plugin_file ): ?bool {
		$longer_delay_plugins = array(
			'woocommerce/woocommerce.php',
			'woocommerce-payments/woocommerce-payments.php',
		);

		$delay_days        = in_array( $plugin_file, $longer_delay_plugins, true ) ? 7 : 2;
		$installed_version = $this->get_installed_plugin_version( $plugin_file );

		if ( $plugin_new_version === $installed_version ) {
			return null;
		}

		$update_info = array(
			'Status'          => 'Checking version delay requirements',
			'Version Info'    => array(
				'Current Version'   => $installed_version,
				'New Version'       => $plugin_new_version,
				'Plugin File'       => $plugin_file,
				'Is Extended Delay' => in_array( $plugin_file, $longer_delay_plugins, true ),
				'Delay Days'        => $delay_days,
			),
			'Update Controls' => array(
				'Required Delay Days'   => $delay_days,
				'Extended Delay Plugin' => in_array( $plugin_file, $longer_delay_plugins, true ),
			),
		);

		if ( '0.0.0' === $installed_version || '0.0.0' === $plugin_new_version ) {
			$update_info['Status'] = 'Invalid version detected';
			return false;
		}

		$installed_version_parts = explode( '.', $installed_version );
		$update_version_parts    = explode( '.', $plugin_new_version );

		$update_info['Version Info']['Version Parts'] = array(
			'Current' => $installed_version_parts,
			'New'     => $update_version_parts,
		);

		$is_major_change = $installed_version_parts[0] !== $update_version_parts[0] ||
			$installed_version_parts[1] !== $update_version_parts[1];

		if ( $is_major_change ) {
			$update_allowed_after                                   = $this->get_delay_date( $plugin_slug, $plugin_new_version, $delay_days, $plugin_file );
			$update_info['Update Controls']['Update Allowed After'] = gmdate( 'Y-m-d\TH:i:s\Z', $update_allowed_after );
			$update_info['Update Controls']['Current Time']         = gmdate( 'Y-m-d\TH:i:s\Z', time() );

			if ( time() >= $update_allowed_after ) {
				$update_info['Status'] = 'Update allowed - delay period passed';
				return true;
			}

			$update_info['Status'] = 'Update blocked - still within delay period';
			return false;
		}

		$update_info['Status'] = 'Update allowed - point release';
		return true;
	}

	/**
	 * Get the installed version of a plugin.
	 *
	 * @param string $plugin_file The plugin file path.
	 * @return string The plugin version or 'unknown' if not found.
	 */
	public function get_installed_plugin_version( $plugin_file ) {
		if ( empty( $plugin_file ) ) {
			return 'unknown';
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
		return ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : 'unknown';
	}

	/**
	 * Retrieve the date after which a plugin update is allowed or calculate it if not set.
	 *
	 * @param   string  $plugin_slug    Slug of the plugin.
	 * @param   string  $update_version The version to update to.
	 * @param   int     $delay_days     Number of days to delay the update.
	 * @param   string  $plugin_file    The relative path to the plugin file.
	 *
	 * @return  int The Unix timestamp indicating when the plugin can be updated.
	 */
	public function get_delay_date( string $plugin_slug, string $update_version, int $delay_days, string $plugin_file ): int {
		$option_key = 'plugin_update_delays';
		$delays     = get_option( $option_key, array() );

		if ( ! isset( $delays[ $plugin_file ][ $update_version ] ) ) {
			$release_date = $this->get_plugin_release_date( $plugin_slug );
			if ( ! $release_date ) {
				$release_date = time();
			}

			$release_plus_delay = strtotime( "+$delay_days days", $release_date );

			$delays[ $plugin_file ][ $update_version ] = $release_plus_delay;
			update_option( $option_key, $delays );
		}

		return $delays[ $plugin_file ][ $update_version ];
	}

	/**
	 * Retrieve the release date of a plugin based on its slug.
	 *
	 * @param string $plugin_slug Slug of the plugin.
	 * @return int The Unix timestamp of the release date or the current time if not available.
	 */
	public function get_plugin_release_date( string $plugin_slug ): int {
		$response = wp_safe_remote_get( "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug={$plugin_slug}" );
		if ( is_wp_error( $response ) ) {
			return time();
		}

		$plugin_info = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $plugin_info['last_updated'] ) ) {
			$timestamp = strtotime( $plugin_info['last_updated'] );
			return false === $timestamp ? time() : $timestamp;
		}

		return time();
	}

	/**
	 * Clear out the entry for the plugin in the serialized array.
	 *
	 * @param   string $plugin_file The relative path to the plugin file.
	 *
	 * @return  void
	 */
	public function clear_plugin_delay( string $plugin_file ): void {
		$option_key = 'plugin_update_delays';
		$delays     = get_option( $option_key, array() );

		if ( isset( $delays[ $plugin_file ] ) ) {
			unset( $delays[ $plugin_file ] );
			update_option( $option_key, $delays );
		}
	}

	/**
	 * Get the plugin file path from the plugin slug
	 *
	 * @param string $plugin_slug The plugin slug
	 * @return string|null The plugin file path or null if not found
	 */
	private function get_plugin_file( string $plugin_slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		// Simple direct match first
		foreach ( $plugins as $file => $data ) {
			if ( strpos( $file, $plugin_slug ) !== false ) {
				return $file;
			}
		}

		// For non-direct matches, try without prefixes
		$clean_slug = str_replace( array( 'woocommerce-com-', 'woocommerce-' ), '', $plugin_slug );
		foreach ( $plugins as $file => $data ) {
			if ( strpos( $file, $clean_slug ) !== false ) {
				return $file;
			}
		}

		return null;
	}
}
