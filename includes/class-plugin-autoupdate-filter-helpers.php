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
	 * Determines whether a plugin should be updated based on release version and delay rules.
	 *
	 * @param string $plugin_slug Slug of the plugin.
	 * @param string $update_version The version that the plugin would be updated to.
	 *
	 * @return bool True if the plugin should be updated, false otherwise.
	 */
	public function has_delay_passed( string $plugin_slug, string $update_version ): bool {
		// delay most plugins 2 days. delay some plugins 7 days.
		$longer_delay_plugins = array(
			'woocommerce',
			'woocommerce-payments',
		);

		$delay_days        = in_array( $plugin_slug, $longer_delay_plugins, true ) ? 7 : 2;
		$installed_version = $this->get_installed_plugin_version( $plugin_slug );

		if ( empty( $installed_version ) || $update_version === $installed_version || '0.0.0' === $update_version ) {
			return false;
		}

		$installed_version_parts = explode( '.', $installed_version );
		$update_version_parts    = explode( '.', $update_version );

		// only apply delays to major and minor releases. let point releases (patches) go through.
		if ( $installed_version_parts[0] !== $update_version_parts[0] || $installed_version_parts[1] !== $update_version_parts[1] ) {
			$update_allowed_after = $this->get_delay_date( $plugin_slug, $update_version, $delay_days );

			if ( time() >= $update_allowed_after ) {
				$this->clear_plugin_delay( $plugin_slug );
				return true;
			}

			return false;
		}

		return true;
	}

	/**
	 * Retrieve the current version of an installed plugin.
	 *
	 * @param string $plugin_slug Slug of the plugin.
	 *
	 * @return string Current version of the plugin or an empty string if not found.
	 */
	public function get_installed_plugin_version( string $plugin_slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		foreach ( $plugins as $plugin_path => $plugin_info ) {
			if ( dirname( $plugin_path ) === $plugin_slug ) {
				return $plugin_info['Version'];
			}
		}

		return '';
	}

	/**
	 * Retrieve the date after which a plugin update is allowed or calculate it if not set.
	 *
	 * @param string $plugin_slug Slug of the plugin.
	 * @param string $update_version The version to update to.
	 * @param int $delay_days Number of days to delay the update.
	 *
	 * @return int The Unix timestamp indicating when the plugin can be updated.
	 */
	public function get_delay_date( string $plugin_slug, string $update_version, int $delay_days ): int {
		$option_key = 'plugin_update_delays';
		$delays     = get_option( $option_key, array() );

		if ( ! isset( $delays[ $plugin_slug ][ $update_version ] ) ) {
			$release_date = $this->get_plugin_release_date( $plugin_slug );

			if ( ! $release_date ) {
				$release_date = time();
			}

			$delays[ $plugin_slug ][ $update_version ] = strtotime( "+{$delay_days} days", $release_date );
			update_option( $option_key, $delays );
		}

		return $delays[ $plugin_slug ][ $update_version ];
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
			return strtotime( $plugin_info['last_updated'] ) ?? time();
		}

		return time();
	}

	/**
	 * Clear out the entry for the plugin in the serialized array.
	 *
	 * @param string $plugin_slug Slug of the plugin.
	 * @return void
	 */
	public function clear_plugin_delay( string $plugin_slug ): void {
		$option_key = 'plugin_update_delays';
		$delays     = get_option( $option_key, array() );

		if ( isset( $delays[ $plugin_slug ] ) ) {
			unset( $delays[ $plugin_slug ] );
			update_option( $option_key, $delays );
		}
	}
}
