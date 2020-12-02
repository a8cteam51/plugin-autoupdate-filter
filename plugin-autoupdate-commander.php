<?php
/*
Plugin Name: Plugin Autoupdate Commander
Plugin URI: https://github.com/a8cteam51/plugin-autoupdate-commander
Description: Plugin which sets autoupdates to run during specific times.
Version: 1.0
Author: Team 51 (WordPress.com Special Projects)
Author URI:
License: GPLv3
*/


function auto_update_specific_times ( $update, $item ) {

  $start = "09"; // 9am
  $end = "14"; // 2pm
  $hour = date('H');
  $day = date('D');

  if ( $now < $start || $now > $end || 'Sat' == $day  || 'Sun' == $day )
  {
    $update = false;
  }

  return $update;
}
add_filter( 'auto_update_plugin', 'auto_update_specific_times', 10, 2 );
