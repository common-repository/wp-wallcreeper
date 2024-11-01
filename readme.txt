=== WP Wallcreeper ===
Contributors: alexalouit
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=4GJGDY4J4PRXS
Tags: cache, caching, speed, performance
Requires at least: 3.0.1
Tested up to: 6.3.2
Requires PHP: 5.2.4
Stable tag: 6.3.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

High performance full page caching for Wordpress

== Description ==

Storage supported:
 - Filesystem
 - APC/APCu
 - Memcached
 - Xcache

Supported:
 - PHP8
 - Wordpress MU
 - direct gzip serving
 - WooCommerce
 - full header caching
 - 404 in cache
 - http redirection (30x status code)
 - advance 304 status code redirection
 - Memcached multiple server

== Installation ==

The installation is like any other plugin:

Automatic installation:
Install it from Wordpress plugins repository, activate it. Configure it.

Manual installation:
Unzip files under /wp-content/plugins directory, activate it. Configure it.

Automatic uninstallation:
Use Wordpress built-in extension manager.

Manual uninstallation:
 - edit /wp-config.php file, remove 'WP_CACHE' line
 - remove /wp-content/advanced-config.php
 - remove plugin directory /wp-content/plugins/wp-wallcreeper

== Frequently Asked Questions ==

- Why you do one request to yours servers at the installation?
 In order to generate a recurring cron without additional installation from ourself,
 we store the URI of your domain (no other information is transmitted),
 then, a robot visit the wp-cron page regularly in order to execute them.

- How I can see cached page?
 List of the cached page is available under Content tab of the setting plugin.
 You can check yourself using another browser/unlogged broswer by visiting your site.
 A http header field will be add in the response (formally 'x-cache-engine').

- I want to remove one page from the cache
 Find the entries list and delete it from the 'Content' tab page under plugin settings.

- I want to remove the whole cache
 Just click on te 'Purge Cache' top page button.
 Alternately, you can select all entries and delete it from the 'Content' tab page under plugin settings.

== Screenshots ==

1. General configure (enable switcher and storage engine)
2. Current cache content
3. Policy setting
4. Expert configuration

== Changelog ==

= 1.6 =
* Support PHP8

= 1.5.2 =
* Fix configuration bug in MU (use new configuration file format)
* Fix bug in gui (timeout field)

= 1.5.1 =
* Fix timeout precision

= 1.5 =
* Support asynchronous flush
* Lighter advanced cache file
* Fix useless log warning
* Fix flush post by id
* Better timeout precision

= 1.4.1 =
* Fix directory generation

= 1.4 =
* Active new configuration

= 1.3 =
* Add serve gzip switch state
* Fix timezone (use built-in Wordpress function)
* Fix configuration file (on old MU Wordpress)

= 1.2 =
* Prevent whitescreen

= 1.1 =
* Fix configuration updater

= 1.0 =
* Initial version

== Upgrade Notice ==

= 1.0 =
* Initial versi