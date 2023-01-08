<?php
/*
Plugin Name: Plugin Autoupdate Filter
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-filter
Description: Sets plugin automatic updates to always on, but only happen during specific days and times.
Version: 1.2.0
Author: WordPress.com Special Projects / Nick Green
Author URI: https://wpspecialprojects.wordpress.com/
Update URI: https://github.com/a8cteam51/plugin-autoupdate-filter/
License: GPLv3
Network: true
*/


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once dirname( __FILE__ ) . '/class-plugin-autoupdate-filter.php';

// sets up autoupdates for this plugin, even though it's hosted on GitHub
add_filter( 'update_plugins_github.com', function( $update, array $plugin_data, string $plugin_file, $locales ) {
    // only check this plugin
    if ( $plugin_file !== 'plugin-autoupdate-filter/plugin-autoupdate-filter.php' ) {
        return $update;
    }

    // already done update check elsewhere
    if ( ! empty( $update ) ) {
        return $update;
    }

	// let's go get the latest version number from GitHub 
	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, "https://api.github.com/repos/a8cteam51/plugin-autoupdate-filter/releases/latest" );  
	curl_setopt( $curl, CURLOPT_USERAGENT, 'wpspecialprojects' );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
	$output = json_decode( curl_exec($curl), true );  
	curl_close( $curl );

	$new_version_number  = $output['tag_name'];	
	$is_update_available = version_compare( $plugin_data['Version'], $new_version_number, '<' );

    if ( ! $is_update_available ) {
        return false;
    }

    return [
        'slug'    => 'plugin-autoupdate-filter',
        'version' => $new_version_number,
        'url'     => 'https://github.com/a8cteam51/plugin-autoupdate-filter/',
        'package' => 'https://github.com/a8cteam51/plugin-autoupdate-filter/releases/latest/download/plugin-autoupdate-filter.zip',
    ];
}, 10, 4 );