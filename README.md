| :exclamation:  This is a public repository |
|--------------------------------------------|

# Plugin Autoupdate Filter
Filters whether autoupdates are on based on day/time and other settings.

## What's this?
This is a plugin that the WordPress Special Projects team uses on many of their partner sites in order to help manage autoupdates in a responsible way. For example:
1. It defaults autoupdates to be on. Keeping plugins up-to-date is one of the the first lines of defense against malicious attacks and technical debt.
2. It provides various mechanisms by which we can turn off autoupdates, such as during specific days/times, for specific plugins, or centralized settings which can turn off all autoupdates.

## Usage

1. Download the .zip file from https://github.com/a8cteam51/plugin-autoupdate-filter/releases
2. Via the wp-admin plugins page on your WordPress site, upload the zip file and activate the plugin

### Notes on functionality

This plugin filters the core `auto_update_plugin` functionality to always run autoupdates during specific hours. It doesn't respect any toggle settings prior to activating this plugin, and is also respected by Jetpack autoupdate settings (the Jetpack autoupdate toggles may still reflect something different, but are not meaningful if this plugin is activated).

It's a good idea to load this as a normal plugin (rather than an mu-plugin), so that it can be deactivated easily by a site admin, in case autoupdates needs to be paused during troubleshooting, etc.

By default, the plugin always returns `true` for autoupdates Mon-Thu 6am-7pm Eastern, and Fri 6am-3pm Eastern. The 13 hour days are because the cron event which checks for autoupdates only runs every 12 hours, and so if the window isn't more than 12 hours at least once during the week, we run the risk of missing updates completely.

### Centralized settings

By default, this plugin checks an endpoint set up by the WordPress Special Projects team to get centralized settings, so our settings also apply to any site using it. If you use this plugin on a site that is not managed by the WordPress Special Projects team, we recommend adding a filter to skip the request (add it outside this plugin's own files, since the plugin updates itself from this repository's releases):

```
function custom_skip_autoupdate_central_settings() {
    return (object) array(); // no centralized settings
}
add_filter( 'pre_transient_wpcpmsp_auto_update_settings', 'custom_skip_autoupdate_central_settings' );
```

The payload supports:

- `disable_all` to disable all automatic updates.
- `canary_sites` to bypass release-delay behavior for selected sites.
- `disabled_plugins` to disable automatic updates for specific plugins across connected sites.

Note: If the plugin can't get valid settings from the endpoint, it disables all automatic updates until it can.


## Support

**This plugin is unsupported; use at your own discretion**

If you have a problem or suggestion, please make an issue in the repo here: https://github.com/a8cteam51/plugin-autoupdate-filter/issues

Feel free to fork and/or create a PR!

## Filters
### Set your own hours/days
If you'd like to customize the times and days, you can filter them. e.g.:
```
function custom_autoupdate_hours( $hours ) {
  return array(
    start      => '10', // 6am Eastern
    end        => '23', // 7pm Eastern
    friday_end => '20', // 4pm Eastern on Fridays
  );
}
add_filter( 'plugin_autoupdate_filter_hours', 'custom_autoupdate_hours' );
```
```
function custom_autoupdate_days_off( $days_off ) {
  // if you don't want updates to run on Fri, Sat, or Sun at all
  return array(
    Fri,
    Sat,
    Sun,
  );
}
add_filter( 'plugin_autoupdate_filter_days_off', 'custom_autoupdate_days_off' );
```
### Set holidays
If you'd like to set windows of time for no updates, you can filter them. e.g.:
```
$holidays = array(
  'christmas' => array(
    'start' => '2021-12-23 00:00:00',
    'end'   => '2021-12-26 00:00:00'
  ),
);
add_filter( 'plugin_autoupdate_filter_holidays', 'custom_autoupdate_holidays' );
```

### Disable autoupdate completely for specific plugins
If you still need to turn off autoupdates for a specific plugin, you can filter `auto_update_plugin` at a priority greater than 10, and prevent specific plugins from updating.

**NOTE: If you do this, please name your function `disable_autoupdate_specific_plugins`**, so that we can add appropriate notices in wp-admin, e.g.

```
function disable_autoupdate_specific_plugins ( $update, $item ) {
    // Array of plugin slugs to never auto-update
    $plugins = array (
        'akismet',
        'buddypress',
    );
    if ( in_array( $item->slug, $plugins ) ) {
         // Never update plugins in this array
        return false;
    } else {
        // Else, do whatever it was going to do before
        return $update;
    }
}
add_filter( 'auto_update_plugin', 'disable_autoupdate_specific_plugins', 11, 2 );
```

### Update notification emails

By default, this plugin sends **all** automatic update emails to the WordPress Special Projects team's email, instead of the site's admin email. This includes:

- Plugin and theme auto-update emails (`auto_plugin_theme_update_email`)
- Core auto-update emails (`auto_core_update_email`)
- Debug emails (`automatic_updates_debug_email`), which are sent after every automatic update run, not just on failures

It also forces these emails on, even if they were turned off elsewhere (for example, by a platform-level mu-plugin).

**If you use this plugin for a site that is not managed by the WordPress Special Projects team, change this before activating the plugin on your sites.** Otherwise, your sites' update reports, including site URLs and plugin lists, will go to our team and **not** to your site's admin.

We recommend sending the emails to your own address with a filter at a priority higher than 10. (Add it in a separate plugin, not in this plugin's files: the plugin updates itself from this repository's releases, so any edits to its code will be overwritten).

```
function custom_autoupdate_email_recipient( $email ) {
    $email['to'] = get_site_option( 'admin_email' ); // or your own address
    return $email;
}
add_filter( 'auto_plugin_theme_update_email', 'custom_autoupdate_email_recipient', 20 );
add_filter( 'auto_core_update_email', 'custom_autoupdate_email_recipient', 20 );
add_filter( 'automatic_updates_debug_email', 'custom_autoupdate_email_recipient', 20 );
```
