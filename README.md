# plugin-autoupdate-filter
Plugin which filters plugin auto-updates to run on specific days and during specific times only.

## Usage
This plugin filters the core `auto_update_plugin` functionality to always run autoupdates during specific hours. It doesn't respect any toggle settings prior to activating this plugin, and is also respected by Jetpack autoupdate settings (the Jetpack autoupdate toggles may still reflect something different, but are not meaningful if this plugin is activated).

It's a good idea to load this as a normal plugin (rather than an mu-plugin), so that it can be deactivated easily by a site admin, in case autoupdates needs to be paused during troubleshooting, etc.

By default, the plugin always returns `true` for autoupdates Mon-Thu 6am-7pm Eastern, and Fri 6am-3pm Eastern. The 13 hour days are because the cron event which checks for autoupdates only runs every 12 hours, and so if the window isn't more than 12 hours at least once during the week, we run the risk of missing updates completely.

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

### Disable autoupdate completely for specific plugins
If you still need to turn off autoupdates for a specific plugin, you can filter `auto_update_plugin` at a priority greater than 10, and prevent specific plugins from updating. e.g.:
```
function auto_update_specific_plugins ( $update, $item ) {
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
add_filter( 'auto_update_plugin', 'auto_update_specific_plugins', 11, 2 );
```
## Problems?
Make an issue in the repo here: https://github.com/a8cteam51/plugin-autoupdate-filter
