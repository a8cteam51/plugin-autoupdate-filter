<?php
/**
 * The Plugin Autoupdate Filter bootstrap file.
 *
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:     Plugin Autoupdate Filter
 * Plugin URI:      https://github.com/a8cteam51/plugin-autoupdate-filter
 * Update URI:      https://github.com/a8cteam51/plugin-autoupdate-filter
 * Description:     Filters whether autoupdates are on based on day/time and other settings.
 * Version:         1.6.3
 * Requires PHP:    7.4
 * Author:          WordPress.com Special Projects
 * Author URI:      https://wpspecialprojects.wordpress.com
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Network:         true
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( defined( 'PLUGIN_AUTOUPDATE_FILTER_PATH' ) ) {
	exit; // Exit if another copy of plugin is active
}

define( 'PLUGIN_AUTOUPDATE_FILTER_PATH', plugin_dir_path( __FILE__ ) );

// main plugin functionality
require_once __DIR__ . '/class-plugin-autoupdate-filter.php';

// handles updating of the plugin itself
require_once __DIR__ . '/class-plugin-autoupdate-filter-self-update.php';
