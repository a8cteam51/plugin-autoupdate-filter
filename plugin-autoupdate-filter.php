<?php
/*
Plugin Name: Plugin Autoupdate Filter
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-filter
Description: Sets plugin automatic updates to always on, but only happen during specific days and times.
Version: 1.4.1
Author: WordPress.com Special Projects
Author URI: https://wpspecialprojects.wordpress.com/
Update URI: https://github.com/a8cteam51/plugin-autoupdate-filter/
License: GPLv3
Network: true
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// main plugin functionality
require_once dirname( __FILE__ ) . '/class-plugin-autoupdate-filter.php';

// handles updating of the plugin itself
require_once dirname( __FILE__ ) . '/class-plugin-autoupdate-filter-self-update.php';
