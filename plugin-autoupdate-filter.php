<?php
/*
Plugin Name: Plugin Autoupdate Filter
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-filter
Description: Filters whether autoupdates are on based on day/time and other settings.
Version: 1.5.2
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
