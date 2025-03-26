<?php
/**
 * The Plugin Autoupdate Filter bootstrap file.
 *
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:     Plugin Autoupdate Filter
 * Plugin URI:      https://github.com/a8cteam51/plugin-autoupdate-filter
 * Update URI:      https://github.com/a8cteam51/plugin-autoupdate-filter
 * Description:     Filters whether autoupdates are on based on day/time and other settings.
 * Version:         1.7.0
 * Requires PHP:    7.4
 * Author:          WordPress.com Special Projects
 * Author URI:      https://wpspecialprojects.wordpress.com
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     plugin-autoupdate-filter
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( defined( 'PLUGIN_AUTOUPDATE_FILTER_PATH' ) ) {
	exit; // Exit if another copy of plugin is active
}

// Define plugin constants
define( 'PLUGIN_AUTOUPDATE_FILTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_AUTOUPDATE_FILTER_FILE', __FILE__ );

// Load required files
require_once PLUGIN_AUTOUPDATE_FILTER_PATH . 'includes/class-plugin-autoupdate-filter-logger.php';
require_once PLUGIN_AUTOUPDATE_FILTER_PATH . 'includes/class-plugin-autoupdate-filter-settings.php';
require_once PLUGIN_AUTOUPDATE_FILTER_PATH . 'includes/class-plugin-autoupdate-filter.php';
require_once PLUGIN_AUTOUPDATE_FILTER_PATH . 'includes/class-plugin-autoupdate-filter-self-update.php';

/**
 * Initialize plugin components
 */
function plugin_autoupdate_filter_init() {
	// Initialize logger first as other components depend on it
	$logger = new Plugin_Autoupdate_Filter_Logger();
	$logger->init();

	// Initialize settings
	$settings = new Plugin_Autoupdate_Filter_Settings( $logger );
	$settings->init();

	// Initialize main plugin functionality
	$plugin = new Plugin_Autoupdate_Filter( $logger );

	// Add settings link (needs to be added before init)
	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		array( $plugin, 'add_settings_link' )
	);

	// Initialize the rest of the plugin
	add_action( 'init', array( $plugin, 'init' ) );

	// Initialize self-update functionality
	$self_update = new Plugin_Autoupdate_Filter_Self_Update( $logger );
	$self_update->init();
}

// Run initialization on plugins_loaded instead of init
add_action( 'plugins_loaded', 'plugin_autoupdate_filter_init' );

/**
 * Plugin activation hook
 */
function plugin_autoupdate_filter_activate() {
	// Add default options
	add_option( 'plugin_autoupdate_filter_enable_logging', false );
	add_option( 'plugin_autoupdate_filter_log_retention', 15 );

	// Schedule the cleanup event if logging is enabled
	if ( get_option( 'plugin_autoupdate_filter_enable_logging', false ) ) {
		if ( ! wp_next_scheduled( 'plugin_autoupdate_filter_cleanup_logs' ) ) {
			wp_schedule_event( strtotime( 'midnight' ), 'daily', 'plugin_autoupdate_filter_cleanup_logs' );
		}
	}

	// Ensure log directory exists if logging is enabled
	if ( get_option( 'plugin_autoupdate_filter_enable_logging', false ) ) {
		$logger = new Plugin_Autoupdate_Filter_Logger();
		$logger->ensure_log_directory();
	}
}
register_activation_hook( __FILE__, 'plugin_autoupdate_filter_activate' );

/**
 * Plugin deactivation hook
 */
function plugin_autoupdate_filter_deactivate() {
	// Clear the scheduled cleanup event
	wp_clear_scheduled_hook( 'plugin_autoupdate_filter_cleanup_logs' );
}
register_deactivation_hook( __FILE__, 'plugin_autoupdate_filter_deactivate' );

/**
 * Load plugin textdomain
 */
function plugin_autoupdate_filter_load_textdomain() {
	load_plugin_textdomain(
		'plugin-autoupdate-filter',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'plugin_autoupdate_filter_load_textdomain' );
