<?php
/*
 * WP Wallcreeper
 * High performance full page caching for Wordpress
 *
 * @author: Alex Alouit <alex@alouit.fr>
 */

if (! defined('ABSPATH'))
	return;

if (! include(__DIR__ . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'wp-wallcreeper' . DIRECTORY_SEPARATOR . 'cache.php')) {
	error_log('Unable to find WP Wallcreeper file');
	return;
}

if (! class_exists('wp_wallcreeper')) {
	error_log('Unable to find WP Wallcreeper class');
	return;
}

new wp_wallcreeper();

if (! function_exists('wp_cache_clear_cache')) {
	function wp_cache_clear_cache() {
		(new wp_wallcreeper())->flush();
	}
}
?>
