<?php
/*
Plugin Name: Plugin Autoupdate Filter
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-filter
Description: Sets plugin autoupdates to always on, but only happen during specific times.
Version: 1.0
Author: WordPress.com Special Projects / Nick Green
Author URI: https://wpspecialprojects.wordpress.com/
License: GPLv3
*/


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once dirname( __FILE__ ) . '/class-plugin-autoupdate-filter.php';
