<?php
/**
* Plugin Name: WP Wallcreeper
* Plugin URI: alex.alouit.fr
* Description: High performance full page caching for Wordpress.
* Version: 1.6.1
* Author: Alex Alouit
* Author URI: alex.alouit.fr
* Author Email: alex@alouit.fr
* Text Domain: wp-wallcreeper
* Domain Path: /languages
* License: GPLv2
*/

if (! class_exists('wp_wallcreeper')) {
	include(__DIR__ . DIRECTORY_SEPARATOR . 'cache.php');
}

register_activation_hook(__FILE__, function () {
	do_action('wpwallcreeper_edit_file', array('push'));
	do_action('wpwallcreeper_edit_config', array('WP_CACHE', 'push'));
});

register_deactivation_hook(__FILE__, function () {
	wp_unschedule_event(
		wp_next_scheduled(
		'wpwallcreeper_cron'
	),
	'wpwallcreeper_cron'
	);

	do_action('wpwallcreeper_edit_config', array('WP_CACHE', 'pull'));
	do_action('wpwallcreeper_edit_file', array('pull'));

	delete_option('wpwallcreeper_uuid');
});

add_action('plugins_loaded', function () {
	load_plugin_textdomain('wp-wallcreeper', false, 'wp-wallcreeper/languages');
});

add_action('admin_enqueue_scripts', function () {
	if (function_exists('current_user_can') && ! current_user_can('manage_options')) return;

	wp_enqueue_script('wpwallcreeper-js', plugins_url('/scripts.js', __FILE__), array('jquery'), '1.0.0', true);
	wp_enqueue_style('wpwallcreeper-css', plugins_url('/style.css', __FILE__), array(), '1.0.0', 'all');
});

add_filter('plugin_action_links', function ($links, $file) {
	if ($file == plugin_basename(dirname(__FILE__) . '/wp-wallcreeper.php')) {
		$links[] = '<a href="' . add_query_arg(array('page' => 'wpwallcreeper'), admin_url('options-general.php')) . '">' . __('Settings', 'wp-wallcreeper') . '</a>';
	}

	return $links;
}, 10, 2);

add_action('admin_menu', function () {
	if (function_exists('current_user_can') && ! current_user_can('manage_options')) return;

	$hook = add_options_page('WP Wallcreeper', 'WP Wallcreeper', 'manage_options', 'wpwallcreeper', 'wpwallcreeper');

	add_action('load-'.$hook, function () {
		add_screen_option('per_page', array(
			'label' => __('Results per page', 'wp-wallcreeper'),
			'default' => 25,
			'option' => 'per_page'
		));
	});
});

add_action('admin_notices', function () {
//	if (! class_exists('wp_wallcreeper')) return;

	if (! defined('WP_CACHE') || constant('WP_CACHE') !== true) {
		do_action('wpwallcreeper_edit_config', array('WP_CACHE', 'push'));
	}

	if (array_key_exists('wp_purge_cache', $_REQUEST)) {
		(new wp_wallcreeper())->flush();
?>
		<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('WP Wallcreeper: Cache purged.', 'wp-wallcreeper'); ?></strong></p></div>
<?php
	}

	$config = (new wp_wallcreeper())->config();

	if (! $config['enable'] && ! (new wp_wallcreeper())->haveConfig()) {
?>
		<div class="notice notice-success is-dismissible"><p><strong>
			<?php echo sprintf(__('WP Wallcreeper: Cache is disable, go to <a href="%s">Settings</a> to activate it.', 'wp-wallcreeper'), add_query_arg(array('page' => 'wpwallcreeper'), admin_url('options-general.php'))); ?>
		</strong></p></div>
<?php
	}

	if (
		! file_exists(constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php') ||
		hash_file('sha256', __DIR__ . DIRECTORY_SEPARATOR . 'advanced-cache.php') !== hash_file('sha256', constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')
	) {
		do_action('wpwallcreeper_edit_file', array('push'));
	}

	if (is_null(get_option('wpwallcreeper_uuid', null))) {
		if (isset($_REQUEST['wpwallcreeper_register'])) {
			if ($_REQUEST['wpwallcreeper_register'] == 'no') {
				add_option('wpwallcreeper_uuid', 0);
			}

			if ($_REQUEST['wpwallcreeper_register'] == 'yes') {
				$response = wp_remote_post(
					'https://cron.wpwallcreeper.api.alouit.fr/',
					array(
						'body'=> array(
							'host' => $_SERVER['HTTP_HOST']
						)
					)
				);

				if (
					false !== $response &&
					false !== preg_match(
						'/^([a-z0-9]{8})-([a-z0-9]{4})-([a-z0-9]{4})-([a-z0-9]{4})-([a-z0-9]{12})$/',
						(string) $response['body']
					)
				) {
					add_option('wpwallcreeper_uuid', (string) $response['body']);
				} else {
?>
<div class="notice notice-error"><p><strong>
<?php esc_html_e('WP Wallcreeper: Unable to register plugin to cron server, please disable and enable plugin again. If this persists, contact plugin administrator.', 'wp-wallcreeper'); ?>
</strong></p></div>
<?php
				}
			}
		} else {
?>
<div class="notice notice-warning">
	<p>
		<i>
			wp-wallcreeper:
		</i>
<?php esc_html_e('WP Wallcreeper: Register to free cron service?', 'wp-wallcreeper'); ?>
<br />
<?php
	echo sprintf(
		__(
			'<a href="%s">No</a> / <a href="%s">Yes</a>',
			'wp-wallcreeper'
		),
		add_query_arg(
			array(
				'wpwallcreeper_register' => 'no'
			)
		),
		add_query_arg(
			array(
				'wpwallcreeper_register' => 'yes'
			)
		)
	); ?>
	</p>
</div>
<?php
		}
	}

	if(isset($_POST['wpwallcreeper'])) {
		array_walk_recursive(
			$_POST['wpwallcreeper'],
			function (&$item) {
				($item == '1') ? $item = true : null;
				($item == '0') ? $item = false : null;
			}
		);

		$config = array();
		$current = (new wp_wallcreeper())->config();

		// enable cache
		if (
			isset($_POST['wpwallcreeper']['enable']) &&
			is_bool($_POST['wpwallcreeper']['enable']) &&
			$_POST['wpwallcreeper']['enable'] != $current['enable']
		) {
				$config['enable'] = (bool) $_POST['wpwallcreeper']['enable'];
		}

		// engine
		if (isset($_POST['wpwallcreeper']['engine'])) {
			sanitize_text_field($_POST['wpwallcreeper']['engine']);

			if ($_POST['wpwallcreeper']['engine'] != $current['engine']) {
				$config['engine'] = (string) $_POST['wpwallcreeper']['engine'];
			}
		}

		// memcached servers
		$errors = array();

		if (
			$_POST['wpwallcreeper']['engine'] == 'memcached' &&
			extension_loaded('memcached')
		) {

			foreach($_POST['wpwallcreeper']['servers'] as $server) {
				if (! isset($server['host']) || ! isset($server['port'])) {
					continue;
				}

				sanitize_text_field($server['host']);
				sanitize_text_field($server['port']);

				$cache = new \Memcached();
				$cache->setOption(\Memcached::OPT_REMOVE_FAILED_SERVERS, true);
				$cache->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);

				if (
					@$_POST['wpwallcreeper']['u'] &&
					@$_POST['wpwallcreeper']['p']
				) {
					sanitize_text_field($_POST['wpwallcreeper']['u']);
					sanitize_text_field($_POST['wpwallcreeper']['p']);
					$cache->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
					$cache->setSaslAuthData(
						(string) $_POST['wpwallcreeper']['u'],
						(string) $_POST['wpwallcreeper']['p']
					);
				}

				if (! $cache->addServer((string) $server['host'], (int) $server['port'])) {
					$errors[] = array('host' => (string) $server['host'], 'port' => (int) $server['port']);
				}

				$key = sprintf('%s:%s', (string) $server['host'], (int) $server['port']);
				$list = (array) $cache->getVersion();

				if (array_key_exists($key, $list) && $list[$key]) {
					$config['servers'][] = array(
						'host' => (string) $server['host'],
						'port' => (int) $server['port']
					);

					if (
						@$_POST['wpwallcreeper']['u'] &&
						@$_POST['wpwallcreeper']['p']
					) {
						$config['username'] = $_POST['wpwallcreeper']['u'];
						$config['password'] = $_POST['wpwallcreeper']['p'];
					}
				} else {
					$errors[] = array('host' => (string) $server['host'], 'port' => (int) $server['port']);
				}

				$cache->quit();
			}

			if (
				$config['engine'] == 'memcached' &&
				extension_loaded('memcached') &&
				empty($config['servers'])
			) {
				$config['enable'] = false;
			}

			foreach ($errors as $error) {
?>
<div class="error"><p><strong>
<?php echo sprintf(esc_html('WP Wallcreeper: Unable to connect to memcached server %s:%s', 'wp-wallcreeper'), $error['host'], $error['port']); ?>
</strong></p></div>
<?php
			}
		}

		// store home page
		if (
			isset($_POST['wpwallcreeper']['rules']['home']) &&
			is_bool($_POST['wpwallcreeper']['rules']['home']) &&
			$_POST['wpwallcreeper']['rules']['home'] != $current['rules']['home']
		) {
				$config['rules']['home'] = (bool) $_POST['wpwallcreeper']['rules']['home'];
		}

		// store archive
		if (
			isset($_POST['wpwallcreeper']['rules']['archive']) &&
			is_bool($_POST['wpwallcreeper']['rules']['archive']) &&
			$_POST['wpwallcreeper']['rules']['archive'] != $current['rules']['archive']
		) {
				$config['rules']['archive'] = (bool) $_POST['wpwallcreeper']['rules']['archive'];
		}

		// store category
		if (
			isset($_POST['wpwallcreeper']['rules']['category']) &&
			is_bool($_POST['wpwallcreeper']['rules']['category']) &&
			$_POST['wpwallcreeper']['rules']['category'] != $current['rules']['category']
		) {
				$config['rules']['category'] = (bool) $_POST['wpwallcreeper']['rules']['category'];
		}

		// store post
		if (
			isset($_POST['wpwallcreeper']['rules']['post']) &&
			is_bool($_POST['wpwallcreeper']['rules']['post']) &&
			$_POST['wpwallcreeper']['rules']['post'] != $current['rules']['post']
		) {
				$config['rules']['post'] = (bool) $_POST['wpwallcreeper']['rules']['post'];
		}

		// store page
		if (
			isset($_POST['wpwallcreeper']['rules']['page']) &&
			is_bool($_POST['wpwallcreeper']['rules']['page']) &&
			$_POST['wpwallcreeper']['rules']['page'] != $current['rules']['page']
		) {
				$config['rules']['page'] = (bool) $_POST['wpwallcreeper']['rules']['page'];
		}

		// store feed
		if (
			isset($_POST['wpwallcreeper']['rules']['feed']) &&
			is_bool($_POST['wpwallcreeper']['rules']['feed']) &&
			$_POST['wpwallcreeper']['rules']['feed'] != $current['rules']['feed']
		) {
				$config['rules']['feed'] = (bool) $_POST['wpwallcreeper']['rules']['feed'];
		}

		// store tag
		if (
			isset($_POST['wpwallcreeper']['rules']['tag']) &&
			is_bool($_POST['wpwallcreeper']['rules']['tag']) &&
			$_POST['wpwallcreeper']['rules']['tag'] != $current['rules']['tag']
		) {
				$config['rules']['tag'] = (bool) $_POST['wpwallcreeper']['rules']['tag'];
		}

		// store tax
		if (
			isset($_POST['wpwallcreeper']['rules']['tax']) &&
			is_bool($_POST['wpwallcreeper']['rules']['tax']) &&
			$_POST['wpwallcreeper']['rules']['tax'] != $current['rules']['tax']
		) {
				$config['rules']['tax'] = (bool) $_POST['wpwallcreeper']['rules']['tax'];
		}

		// store single
		if (
			isset($_POST['wpwallcreeper']['rules']['single']) &&
			is_bool($_POST['wpwallcreeper']['rules']['single']) &&
			$_POST['wpwallcreeper']['rules']['single'] != $current['rules']['single']
		) {
				$config['rules']['single'] = (bool) $_POST['wpwallcreeper']['rules']['single'];
		}

		// store search
		if (
			isset($_POST['wpwallcreeper']['rules']['search']) &&
			is_bool($_POST['wpwallcreeper']['rules']['search']) &&
			$_POST['wpwallcreeper']['rules']['search'] != $current['rules']['search']
		) {
				$config['rules']['search'] = (bool) $_POST['wpwallcreeper']['rules']['search'];
		}

		// store comment
		if (
			isset($_POST['wpwallcreeper']['rules']['comment']) &&
			is_bool($_POST['wpwallcreeper']['rules']['comment']) &&
			$_POST['wpwallcreeper']['rules']['comment'] != $current['rules']['comment']
		) {
				$config['rules']['comment'] = (bool) $_POST['wpwallcreeper']['rules']['comment'];
		}

		// woocommerce
		if (class_exists('WooCommerce')) {
			// store woocommerce product
			if (
				isset($_POST['wpwallcreeper']['rules']['woocommerce']['product']) &&
				is_bool($_POST['wpwallcreeper']['rules']['woocommerce']['product']) &&
				$_POST['wpwallcreeper']['rules']['woocommerce']['product'] != $current['rules']['woocommerce']['product']
			) {
					$config['rules']['woocommerce']['product'] = (bool) $_POST['wpwallcreeper']['rules']['woocommerce']['product'];
			}

			// store woocommerce category
			if (
				isset($_POST['wpwallcreeper']['rules']['woocommerce']['category']) &&
				is_bool($_POST['wpwallcreeper']['rules']['woocommerce']['category']) &&
				$_POST['wpwallcreeper']['rules']['woocommerce']['category'] != $current['rules']['woocommerce']['category']
			) {
					$config['rules']['woocommerce']['category'] = (bool) $_POST['wpwallcreeper']['rules']['woocommerce']['category'];
			}
		}

		// debug
		if (
			isset($_POST['wpwallcreeper']['debug']) &&
			is_bool($_POST['wpwallcreeper']['debug']) &&
			$_POST['wpwallcreeper']['debug'] != $current['debug']
		) {
				$config['debug'] = (bool) $_POST['wpwallcreeper']['debug'];
		}

		// ttl
		if (isset($_POST['wpwallcreeper']['ttl'])) {
			sanitize_text_field($_POST['wpwallcreeper']['ttl']);

			if ($_POST['wpwallcreeper']['ttl'] != $current['ttl']) {
				$config['ttl'] = (int) $_POST['wpwallcreeper']['ttl'];
			}
		}

		// store header
		if (
			isset($_POST['wpwallcreeper']['rules']['header']) &&
			is_bool($_POST['wpwallcreeper']['rules']['header']) &&
			$_POST['wpwallcreeper']['rules']['header'] != $current['rules']['header']
		) {
				$config['rules']['header'] = (bool) $_POST['wpwallcreeper']['rules']['header'];
		}

		// serve gzip
		if (
			isset($_POST['wpwallcreeper']['rules']['gz']) &&
			is_bool($_POST['wpwallcreeper']['rules']['gz']) &&
			$_POST['wpwallcreeper']['rules']['gz'] != $current['rules']['gz']
		) {
				$config['rules']['gz'] = (bool) $_POST['wpwallcreeper']['rules']['gz'];
		}

		// store redirect
		if (
			isset($_POST['wpwallcreeper']['rules']['redirect']) &&
			is_bool($_POST['wpwallcreeper']['rules']['redirect']) &&
			$_POST['wpwallcreeper']['rules']['redirect'] != $current['rules']['redirect']
		) {
				$config['rules']['redirect'] = (bool) $_POST['wpwallcreeper']['rules']['redirect'];
		}

		// store 304
		if (
			isset($_POST['wpwallcreeper']['rules']['304']) &&
			is_bool($_POST['wpwallcreeper']['rules']['304']) &&
			$_POST['wpwallcreeper']['rules']['304'] != $current['rules']['304']
		) {
				$config['rules']['304'] = (bool) $_POST['wpwallcreeper']['rules']['304'];
		}

		// store 404
		if (
			isset($_POST['wpwallcreeper']['rules']['404']) &&
			is_bool($_POST['wpwallcreeper']['rules']['404']) &&
			$_POST['wpwallcreeper']['rules']['404'] != $current['rules']['404']
		) {
				$config['rules']['404'] = (bool) $_POST['wpwallcreeper']['rules']['404'];
		}

		// precache maximum request
		if (isset($_POST['wpwallcreeper']['precache']['maximum_request'])) {
			sanitize_text_field($_POST['wpwallcreeper']['precache']['maximum_request']);

			if ($_POST['wpwallcreeper']['precache']['maximum_request'] != $current['precache']['maximum_request']) {
				$config['precache']['maximum_request'] = (int) $_POST['wpwallcreeper']['precache']['maximum_request'];
			}
		}

		// precache timeout
		if (isset($_POST['wpwallcreeper']['precache']['timeout'])) {
			sanitize_text_field($_POST['wpwallcreeper']['precache']['timeout']);

			if ($_POST['wpwallcreeper']['precache']['timeout'] != $current['precache']['timeout']) {
				$config['precache']['timeout'] = (int) $_POST['wpwallcreeper']['precache']['timeout'];
			}
		}

		// precache home
		if (
			isset($_POST['wpwallcreeper']['precache']['home']) &&
			is_bool($_POST['wpwallcreeper']['precache']['home']) &&
			$_POST['wpwallcreeper']['precache']['home'] != $current['precache']['home']
		) {
				$config['precache']['home'] = (bool) $_POST['wpwallcreeper']['precache']['home'];
		}

		// precache category
		if (
			isset($_POST['wpwallcreeper']['precache']['category']) &&
			is_bool($_POST['wpwallcreeper']['precache']['category']) &&
			$_POST['wpwallcreeper']['precache']['category'] != $current['precache']['category']
		) {
				$config['precache']['category'] = (bool) $_POST['wpwallcreeper']['precache']['category'];
		}

		// precache page
		if (
			isset($_POST['wpwallcreeper']['precache']['page']) &&
			is_bool($_POST['wpwallcreeper']['precache']['page']) &&
			$_POST['wpwallcreeper']['precache']['page'] != $current['precache']['page']
		) {
				$config['precache']['page'] = (bool) $_POST['wpwallcreeper']['precache']['page'];
		}

		// precache post
		if (
			isset($_POST['wpwallcreeper']['precache']['post']) &&
			is_bool($_POST['wpwallcreeper']['precache']['post']) &&
			$_POST['wpwallcreeper']['precache']['post'] != $current['precache']['post']
		) {
				$config['precache']['post'] = (bool) $_POST['wpwallcreeper']['precache']['post'];
		}

		// precache feed
		if (
			isset($_POST['wpwallcreeper']['precache']['feed']) &&
			is_bool($_POST['wpwallcreeper']['precache']['feed']) &&
			$_POST['wpwallcreeper']['precache']['feed'] != $current['precache']['feed']
		) {
				$config['precache']['feed'] = (bool) $_POST['wpwallcreeper']['precache']['feed'];
		}

		// precache tag
		if (
			isset($_POST['wpwallcreeper']['precache']['tag']) &&
			is_bool($_POST['wpwallcreeper']['precache']['tag']) &&
			$_POST['wpwallcreeper']['precache']['tag'] != $current['precache']['tag']
		) {
				$config['precache']['tag'] = (bool) $_POST['wpwallcreeper']['precache']['tag'];
		}

		// precache woocommerce
		if (class_exists('WooCommerce')) {
			// precache woocommerce product
			if (
				isset($_POST['wpwallcreeper']['precache']['woocommerce']['product']) &&
				is_bool($_POST['wpwallcreeper']['precache']['woocommerce']['product']) &&
				$_POST['wpwallcreeper']['precache']['woocommerce']['product'] != $current['precache']['woocommerce']['product']
			) {
					$config['precache']['woocommerce']['product'] = (bool) $_POST['wpwallcreeper']['precache']['woocommerce']['product'];
			}

			// precache woocommerce category
			if (
				isset($_POST['wpwallcreeper']['precache']['woocommerce']['category']) &&
				is_bool($_POST['wpwallcreeper']['precache']['woocommerce']['category']) &&
				$_POST['wpwallcreeper']['precache']['woocommerce']['category'] != $current['precache']['woocommerce']['category']
			) {
					$config['precache']['woocommerce']['category'] = (bool) $_POST['wpwallcreeper']['precache']['woocommerce']['category'];
			}

			// precache woocommerce tag
			if (
				isset($_POST['wpwallcreeper']['precache']['woocommerce']['tag']) &&
				is_bool($_POST['wpwallcreeper']['precache']['woocommerce']['tag']) &&
				$_POST['wpwallcreeper']['precache']['woocommerce']['tag'] != $current['precache']['woocommerce']['tag']
			) {
					$config['precache']['woocommerce']['tag'] = (bool) $_POST['wpwallcreeper']['precache']['woocommerce']['tag'];
			}
		}

		(new wp_wallcreeper())->setConfig((array) $config);

?>
<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('WP Wallcreeper: Options saved.', 'wp-wallcreeper'); ?></strong></p></div>
<?php
	}
});

add_action('admin_bar_menu', function ($wp_admin_bar) {
	if (function_exists('current_user_can') && ! current_user_can('manage_options')) return;

	$wp_admin_bar->add_node(
		array(
			'id' => 'wpwallcreeper-purge',
			'title' => '<span class="ab-icon"></span><span class="ab-item">' . __('Purge cache', 'wp-wallcreeper') . '</span>',
			'href' => add_query_arg(array('page' => 'wpwallcreeper', 'wp_purge_cache' => 'yes'), admin_url('options-general.php'))
		)
	);
}, 90);

add_action('wpwallcreeper_edit_file', function ($args) {
	if (function_exists('current_user_can') && ! current_user_can('manage_options')) return;

	if (! $args[0]) return;

	if ($args[0] == 'push') {
		if (
			(file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'advanced-cache.php') && file_exists(constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')) &&
			hash_file('sha256', __DIR__ . DIRECTORY_SEPARATOR . 'advanced-cache.php') === hash_file('sha256', constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')
		) return;

		if (! copy(__DIR__ . DIRECTORY_SEPARATOR . 'advanced-cache.php', constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')) {
?>
			<div class="notice notice-error"><p><strong><?php esc_html_e('Unable to copy "advanced-cache" file.', 'wp-wallcreeper'); ?></strong></p></div>
<?php
		}
	} elseif ($args[0] == 'pull') {
		if (! file_exists(constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')) return;

		if (! unlink(constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'advanced-cache.php')) {
?>
			<div class="notice notice-error"><p><strong><?php esc_html_e('Unable to remove "advanced-cache" file.', 'wp-wallcreeper'); ?></strong></p></div>
<?php
		}
	} else {
		return;
	}
});

add_action('wpwallcreeper_edit_config', function ($args) {
	if (function_exists('current_user_can') && ! current_user_can('manage_options')) return;

	if (! $args[0]) return;

	$new = array();
	$offset = 0;

	if (! is_file(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php')) {
?>
			<div class="notice notice-error">
				<p>
					<strong>
						<?php esc_html_e('Unable to find "config.php" file.', 'wp-wallcreeper'); ?>
					</strong>
					<br />

<?php
	if ($args[1] == 'push') {
		echo sprintf(__("Please insert line define('%s', true) to 'config.php' file." , 'wp-wallcreeper'), $args[0]);
	} else {
		echo sprintf(__("Please remove or comment line define('%s', true) from 'config.php' file." , 'wp-wallcreeper'), $args[0]);
	}
?>
				</p>
			</div>

<?php
		return;
	} else if (! is_writeable(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php')) {
?>
			<div class="notice notice-error">
				<p>
					<strong>
						<?php esc_html_e('Unable to edit "config.php" file.', 'wp-wallcreeper'); ?>
					</strong>
					<br />

<?php
	if ($args[1] == 'push') {
		echo sprintf(__("Please insert line define('%s', true) to 'config.php' file." , 'wp-wallcreeper'), $args[0]);
	} else {
		echo sprintf(__("Please remove or comment line define('%s', true) from 'config.php' file." , 'wp-wallcreeper'), $args[0]);
	}
?>
				</p>
			</div>

<?php
		return;
	}

	foreach (explode(
		PHP_EOL,
		file_get_contents(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php')
	) as $key => $line) {
		if (strpos($line, '<?php')) {
			$offset = $key;
		}

		if (! strpos($line, $args[0])) {
			$new[] = $line;
		}
	}

	if ($args[1] == 'push') {
		array_splice($new, $offset + 1, 0, sprintf("define('%s', true);", $args[0]));
	} elseif ($args[1] == 'pull') {
	} else {
		return;
	}

	copy(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php', constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php.bk');
	if (! file_put_contents(
		constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php',
		implode(
			PHP_EOL,
			$new
		)
	)) {
		rename(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php.bk', constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php');
	} else {
		unlink(constant('ABSPATH') . DIRECTORY_SEPARATOR . 'wp-config.php.bk');
	}
});

add_action('wpwallcreeper_cron', function () { (new wp_wallcreeper())->cron(); });

add_filter('cron_schedules', function($schedules) {
	$schedules['every_minute'] = array(
		'interval' => 60,
		'display' => esc_html__('Every Minute')
	);

	return $schedules;
});

if (! wp_next_scheduled('wpwallcreeper_cron')) {
	wp_schedule_event(time(), 'every_minute', 'wpwallcreeper_cron');
}

function wpwallcreeper() {
	$config = (new wp_wallcreeper())->config();
?>

<div class="wrap">
		<h1 class='wp-heading-inline'>WP Wallcreeper</h1>

		<h3 class="nav-tab-wrapper">
			<a class="nav-tab<?php if (! @$_REQUEST['tab']): ?> nav-tab-active<?php endif; ?>" href="<?php echo add_query_arg(array('page' => 'wpwallcreeper'), admin_url('options-general.php')); ?>"><?php esc_html_e('General', 'wpwallcreeper'); ?></a>
			<a class="nav-tab<?php if (@$_REQUEST['tab'] == 'content'): ?> nav-tab-active<?php endif; ?>" href="<?php echo add_query_arg(array('page' => 'wpwallcreeper', 'tab' => 'content'), admin_url('options-general.php')); ?>"><?php esc_html_e('Content', 'wpwallcreeper'); ?></a>
			<a class="nav-tab<?php if (@$_REQUEST['tab'] == 'expert'): ?> nav-tab-active<?php endif; ?>" href="<?php echo add_query_arg(array('page' => 'wpwallcreeper', 'tab' => 'expert'), admin_url('options-general.php')); ?>"><?php esc_html_e('Expert', 'wpwallcreeper'); ?></a>

			<a class="nav-tab" style="float: right;" href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=4GJGDY4J4PRXS" target="_blank"><?php esc_html_e('Donate', 'wpwallcreeper'); ?></a>
			<a class="nav-tab" style="float: right;" href="https://wordpress.org/support/plugin/wp-wallcreeper" target="_blank"><?php esc_html_e('Support', 'wpwallcreeper'); ?></a>
			<a class="nav-tab" style="float: right;" href="https://wordpress.org/plugins/wp-wallcreeper/#faq" target="_blank"><?php esc_html_e('FAQ', 'wpwallcreeper'); ?></a>
		</h3>

		<div id="poststuff">
			<div id="post-body-content">

<?php if (! @$_REQUEST['tab']): ?>

				<form name="wpwallcreeper" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
					<table class="form-table">

						<tr>
							<th scope="row"><?php esc_html_e('Enable cache', 'wpwallcreeper'); ?></th>
							<td>
								<fieldset>
										<br />
									<label>
										<input type="radio" id="enable" name="wpwallcreeper[enable]" value="0"<?php if (! $config['enable']): ?> checked<?php endif; ?>> <?php esc_html_e('Off', 'wp-wallcreeper'); ?>
									</label>

										<br />
									<label>
										<input type="radio" id="enable" name="wpwallcreeper[enable]" value="1"<?php if ($config['enable']): ?> checked<?php endif; ?>> <?php esc_html_e('On', 'wp-wallcreeper'); ?>
									</label>

								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e('Engine', 'wpwallcreeper'); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" id="engine" name="wpwallcreeper[engine]" value="fs"<?php if ($config['engine'] == 'fs'): ?> checked<?php endif; ?>> <?php esc_html_e('Filesystem', 'wp-wallcreeper'); ?>
									</label>

										<br />
									<label>
										<input type="radio" id="engine" name="wpwallcreeper[engine]" value="apc"<?php if ($config['engine'] == 'apc'): ?> checked<?php endif; ?><?php if (! extension_loaded('apc')): ?> disabled<?php endif; ?>> <?php esc_html_e('APC', 'wp-wallcreeper'); ?>
									</label>

										<br />
									<label>
										<input type="radio" id="engine" name="wpwallcreeper[engine]" value="apcu"<?php if ($config['engine'] == 'apcu'): ?> checked<?php endif; ?><?php if (! extension_loaded('apcu')): ?> disabled<?php endif; ?>> <?php esc_html_e('APCu', 'wp-wallcreeper'); ?>
									</label>

										<br />
									<label>
										<input type="radio" id="engine" name="wpwallcreeper[engine]" value="memcached"<?php if ($config['engine'] == 'memcached'): ?> checked<?php endif; ?><?php if (! extension_loaded('memcached')): ?> disabled<?php endif; ?>> <?php esc_html_e('Memcached', 'wp-wallcreeper'); ?>
									</label>

										<br />
									<label>
										<input type="radio" id="engine" name="wpwallcreeper[engine]" value="xcache"<?php if ($config['engine'] == 'xcache'): ?> checked<?php endif; ?><?php if (! extension_loaded('xcache')): ?> disabled<?php endif; ?>> <?php esc_html_e('XCache', 'wp-wallcreeper'); ?>
									</label>

								</fieldset>
							</td>
						</tr>

						<tr class="memcached-servers">
							<th scope="row"><?php esc_html_e('Servers', 'wpwallcreeper'); ?></th>
							<td>
								<fieldset>
									<label>

<?php
if (empty($config['servers'])) {
	$config['servers'] = array('host' => '127.0.0.1', 'port' => 11211); }
?>

<?php foreach ($config['servers'] as $key => $value): ?>

										<div>
											<input name="wpwallcreeper[servers][<?php echo $key; ?>][host]" id="host" type="text" value="<?php echo $value['host']; ?>" placeholder="hostname/ip" class="regular-text code"> 
											<input name="wpwallcreeper[servers][<?php echo $key; ?>][port]" id="port" type="number" step="1" min="0" value="<?php echo $value['port']; ?>" placeholder="port" class="small-text">
											<span class="dashicons dashicons-no" id="remove-memcached-server" title="<?php esc_html_e('remove server', 'wpwallcreeper'); ?>"></span>
										</div>

<?php endforeach; ?>

									</label>

								</fieldset>

								<span class="dashicons dashicons-plus" id="add-memcached-server" title="<?php esc_html_e('add server', 'wpwallcreeper'); ?>"></span>
							</td>
						</tr>

						<tr class="memcached-auth">
							<th scope="row"><?php esc_html_e('SASL Authentification', 'wpwallcreeper'); ?></th>
							<td>
								<input name="wpwallcreeper[u]" id="u" type="text" value="<?php echo $config['username']; ?>" autocomplete="off" placeholder="username" class="text"> <?php esc_html_e('Username', 'wpwallcreeper'); ?>

							<br />

								<input name="wpwallcreeper[p]" id="p" type="password" value="<?php echo $config['password']; ?>" autocomplete="off" placeholder="password" class="text"> <?php esc_html_e('Password', 'wpwallcreeper'); ?>
							<td>
						</tr>

					</table>
					<?php submit_button(); ?>
				</form>

<?php endif; ?>

<?php if (@$_REQUEST['tab'] == 'content'): ?>

<?php if ($config['engine'] == 'xcache'): ?>
<p><strong><?php esc_html_e("XCache don't support list mode." , 'wp-wallcreeper'); ?></strong></p>
<?php endif; ?>

<?php

if (! class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class wpwallcreeper_list extends WP_List_Table {
	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="bulk-action[]" value="%s" />', $item['url']
		);
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();

		$this->process_bulk_action();

		$config = (new wp_wallcreeper())->config();

		if (! $config['enable']) {
			$data = array();
		} else {
			$data = (new wp_wallcreeper())->getAll();
		}

		array_walk($data, function (&$value) {
			$value['url'] = str_replace(
				array(
					'GET::',
					'http://',
					'https://',
					'.gz',
					'.meta',
					$_SERVER['HTTP_HOST']
				),
				'',
				$value['url']
			);
		});

		$temp = array();

		$data = array_filter($data, function ($value) use (&$temp) {
			if (in_array($value['url'], $temp))
				return false;

			if (false !== strpos($value['url'], '.generatelist'))
				return false;

			if (false !== strpos($value['url'], 'HEAD::'))
				return false;

			$temp[] = $value['url'];
			return true;
		});

		unset($temp);

		if (isset($_REQUEST['s']) && ! empty($_REQUEST['s'])) {
			$filters = $this->searchable();
			$data = array_filter($data, function ($value) use ($filters) {
				foreach ($filters as $field) {
					if (isset($value[$field]) && false !== strpos($value[$field], $_REQUEST['s']))
						return true;
				}

				return false;
			});
		}

		usort($data, function ($a, $b) {
			$orderby = (! empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : $this->defaultOrderBy();
			$order = (! empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc';
			$result = @strcmp($a[$orderby], $b[$orderby]);
			return ($order==='asc') ? $result : -$result;
		});

		$current_page = $this->get_pagenum();

		$total_items = count($data);

		$user = get_current_user_id();
		$per_page = (int) get_user_meta($user, 'per_page', true);

		if (! is_int($per_page) || $per_page < 1) {
			$screen = get_current_screen();
			$per_page = $screen->get_option('per_page', 'default');
		}

		if (! is_int($per_page) || $per_page < 1) {
			$per_page = 25;
		}

		$data = array_slice($data, (($current_page - 1) * $per_page), $per_page);
		$this->set_pagination_args(array(
			'total_items' => $total_items,
			'per_page' => $per_page,
			'total_pages' => ceil($total_items / $per_page)
		));

		$this->_column_headers = array($columns, $hidden, $sortable);

		$this->items = $data;
	}

	// we can dedicate a column by function
	// name must correspond with column name
	// (like 'column_active' for active)
	public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'ttl':
				return ($item[$column_name] === 0) ? __('Unavailable', 'wp-wallcreeper') : date_i18n('Y-m-d H:i', $item[$column_name]);
		}

		return $item[$column_name];
	}

	public function no_items() {
		esc_html_e('No entry found.', 'wp-wallcreeper');
	}

	public function get_columns() {
		return array(
			'cb' => '<input type="checkbox" />',
			'url' => __('URL', 'wp-wallcreeper'),
			'ttl' => __('TTL', 'wp-wallcreeper'),
		);
	}

	public function get_sortable_columns() {
		return array(
			'url' => array('url', false),
			'ttl' => array('ttl', true),
		);
	}

	public function searchable() {
		return array('url');
	}

	public function defaultOrderBy() {
		return 'url';
	}

	public function get_bulk_actions() {
		return array(
			'bulk-delete' => __('Delete', 'wp-wallcreeper')
		);
	}

	public function process_bulk_action() {
		if (
			(array_key_exists('action', $_REQUEST) && $_REQUEST['action'] == 'bulk-delete') ||
			(array_key_exists('action2', $_REQUEST) && $_REQUEST['action2'] == 'bulk-delete')
		) {
			foreach (esc_sql($_REQUEST['bulk-action']) as $id) {
				(new wp_wallcreeper())->flush($_SERVER['HTTP_HOST'] . $id);
			}
		}
	}
}
?>

				<div class="meta-box-sortables ui-sortable">
					<form method="get">
						<input type="hidden" name="page" value="<?php echo @$_REQUEST['page']; ?>" />
						<input type="hidden" name="tab" value="<?php echo @$_REQUEST['tab']; ?>" />
						<?php
						$list = new wpwallcreeper_list();
						$list->prepare_items();
						$list->search_box(__('Search', 'wp-wallcreeper'), 'search_id');
						$list->display();
						?>
					</form>
				</div>

<?php endif; ?>

<?php if (@$_REQUEST['tab'] == 'expert'): ?>

				<form name="wpwallcreeper" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
					<table class="form-table">
<!--
						<tr>
							<th scope="row"><?php esc_html_e('Object Cache', 'wp-wallcreeper'); ?></th>
							<td>
								<fieldset>

									<label>
										<input name="wpwallcreeper[object]" type="hidden" id="object" value="0">
										<input name="wpwallcreeper[object]" type="checkbox" id="object" value="1"<?php if ($config['object']): ?> checked<?php endif; ?>> <?php esc_html_e('Enable', 'wp-wallcreeper'); ?>
									</label>

								</fieldset>
							</td>
						</tr>
-->
						<tr>
							<th scope="row"><?php esc_html_e('Extras', 'wp-wallcreeper'); ?></th>
							<td>
								<fieldset>

									<label>
										<input name="wpwallcreeper[debug]" type="hidden" id="debug" value="0">
										<input name="wpwallcreeper[debug]" type="checkbox" id="debug" value="1"<?php if ($config['debug']): ?> checked<?php endif; ?>> <?php esc_html_e('Debug mode', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<?php esc_html_e('Cache generate will be keep for', 'wp-wallcreeper'); ?> <input name="wpwallcreeper[ttl]" type="number" step="1" min="1" id="ttl" value="<?php echo $config['ttl']; ?>" class="small-text"> <?php esc_html_e('seconds', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][header]" type="hidden" id="header" value="0">
										<input name="wpwallcreeper[rules][header]" type="checkbox" id="header" value="1"<?php if ($config['rules']['header']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Serve full HTTP headers', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][gz]" type="hidden" id="gz" value="0">
										<input name="wpwallcreeper[rules][gz]" type="checkbox" id="gz" value="1"<?php if ($config['rules']['gz']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Serve direct gzip', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][redirect]" type="hidden" id="redirect" value="0">
										<input name="wpwallcreeper[rules][redirect]" type="checkbox" id="redirect" value="1"<?php if ($config['rules']['redirect']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Serve HTTP redirects (30x)', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][304]" type="hidden" id="304" value="0">
										<input name="wpwallcreeper[rules][304]" type="checkbox" id="304" value="1"<?php if ($config['rules']['304']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Handle advance HTTP redirects (304)', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][404]" type="hidden" id="404" value="0">
										<input name="wpwallcreeper[rules][404]" type="checkbox" id="404" value="1"<?php if ($config['rules']['404']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Store not found pages (404)', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[rules][asyncflush]" type="hidden" id="asyncflush" value="0">
										<input name="wpwallcreeper[rules][asyncflush]" type="checkbox" id="asyncflush" value="1"<?php if ($config['rules']['asyncflush']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Async flush', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[external]" type="checkbox" id="external_cron" value="1"<?php if ($config['external'] || (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON'))): ?> checked<?php endif; ?>>
										<?php esc_html_e('Disable client-side cron execution', 'wp-wallcreeper'); ?>
										<br />
<?php if (! $config['external']): ?>
										<small>
											<code>DISABLE_WP_CRON</code> 
											<?php esc_html_e('will be added to your configuration file', 'wp-wallcreeper'); ?>
										</small>
<?php endif; ?>
									</label>

								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row">Precache</th>
							<td>
								<fieldset>

									<label>
										<?php esc_html_e('Each instance will generate', 'wp-wallcreeper'); ?> <input name="wpwallcreeper[precache][maximum_request]" type="number" step="1" min="1" id="maximum_request" value="<?php echo $config['precache']['maximum_request']; ?>" class="small-text"> <?php esc_html_e('pages', 'wp-wallcreeper'); ?> 
										<?php esc_html_e('and have a', 'wp-wallcreeper'); ?> <input name="wpwallcreeper[precache][timeout]" type="number" step="0.5" min="1" id="timeout" value="<?php echo $config['precache']['timeout']; ?>" class="small-text"> <?php esc_html_e('seconds timeout', 'wp-wallcreeper'); ?>
									</label>

									<br />
									<br />

									<label>
										<input name="wpwallcreeper[precache][home]" type="hidden" id="home" value="0">
										<input name="wpwallcreeper[precache][home]" type="checkbox" id="home" value="1"<?php if ($config['precache']['home']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate home page', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][category]" type="hidden" id="category" value="0">
										<input name="wpwallcreeper[precache][category]" type="checkbox" id="category" value="1"<?php if ($config['precache']['category']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate categories', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][page]" type="hidden" id="page" value="0">
										<input name="wpwallcreeper[precache][page]" type="checkbox" id="page" value="1"<?php if ($config['precache']['page']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate pages', 'wp-wallcreeper'); ?>
									</label>


									<br />

									<label>
										<input name="wpwallcreeper[precache][post]" type="hidden" id="post" value="0">
										<input name="wpwallcreeper[precache][post]" type="checkbox" id="post" value="1"<?php if ($config['precache']['post']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate posts', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][feed]" type="hidden" id="feed" value="0">
										<input name="wpwallcreeper[precache][feed]" type="checkbox" id="feed" value="1"<?php if ($config['precache']['feed']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate feeds', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][tag]" type="hidden" id="tag" value="0">
										<input name="wpwallcreeper[precache][tag]" type="checkbox" id="tag" value="1"<?php if ($config['precache']['tag']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Generate tags', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][woocommerce][product]" type="hidden" id="woocommerce-product" value="0">
										<input name="wpwallcreeper[precache][woocommerce][product]" type="checkbox" id="woocommerce-product" value="1"<?php if ($config['precache']['woocommerce']['product']): ?> checked<?php endif; ?><?php if (! class_exists('WooCommerce')): ?> disabled<?php endif; ?>>
										<?php esc_html_e('Generate WooCommerce products', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][woocommerce][category]" type="hidden" id="woocommerce-category" value="0">
										<input name="wpwallcreeper[precache][woocommerce][category]" type="checkbox" id="woocommerce-category" value="1"<?php if ($config['precache']['woocommerce']['category']): ?> checked<?php endif; ?><?php if (! class_exists('WooCommerce')): ?> disabled<?php endif; ?>>
										<?php esc_html_e('Generate WooCommerce categories', 'wp-wallcreeper'); ?>
									</label>

									<br />

									<label>
										<input name="wpwallcreeper[precache][woocommerce][tag]" type="hidden" id="woocommerce-tag" value="0">
										<input name="wpwallcreeper[precache][woocommerce][tag]" type="checkbox" id="woocommerce-tag" value="1"<?php if ($config['precache']['woocommerce']['tag']): ?> checked<?php endif; ?><?php if (! class_exists('WooCommerce')): ?> disabled<?php endif; ?>>
										<?php esc_html_e('Generate WooCommerce tags', 'wp-wallcreeper'); ?>
									</label>

								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e('Keep in cache', 'wp-wallcreeper'); ?></th>
							<td>
								<fieldset>

									<label>
										<input name="wpwallcreeper[rules][home]" type="hidden" id="home" value="0">
										<input name="wpwallcreeper[rules][home]" type="checkbox" id="home" value="1"<?php if ($config['rules']['home']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Home page', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][archive]" type="hidden" id="archive" value="0">
										<input name="wpwallcreeper[rules][archive]" type="checkbox" id="archive" value="1"<?php if ($config['rules']['archive']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Archives', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][category]" type="hidden" id="category" value="0">
										<input name="wpwallcreeper[rules][category]" type="checkbox" id="category" value="1"<?php if ($config['rules']['category']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Categories', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][post]" type="hidden" id="post" value="0">
										<input name="wpwallcreeper[rules][post]" type="checkbox" id="post" value="1"<?php if ($config['rules']['post']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Posts', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][page]" type="hidden" id="page" value="0">
										<input name="wpwallcreeper[rules][page]" type="checkbox" id="page" value="1"<?php if ($config['rules']['page']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Pages', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][feed]" type="hidden" id="feed" value="0">
										<input name="wpwallcreeper[rules][feed]" type="checkbox" id="feed" value="1"<?php if ($config['rules']['feed']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Feeds', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][tag]" type="hidden" id="tag" value="0">
										<input name="wpwallcreeper[rules][tag]" type="checkbox" id="tag" value="1"<?php if ($config['rules']['tag']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Tags', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][tax]" type="hidden" id="tax" value="0">
										<input name="wpwallcreeper[rules][tax]" type="checkbox" id="tax" value="1"<?php if ($config['rules']['tax']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Taxonomies', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][single]" type="hidden" id="single" value="0">
										<input name="wpwallcreeper[rules][single]" type="checkbox" id="single" value="1"<?php if ($config['rules']['single']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Single pages', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][search]" type="hidden" id="search" value="0">
										<input name="wpwallcreeper[rules][search]" type="checkbox" id="search" value="1"<?php if ($config['rules']['search']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Search pages', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][comment]" type="hidden" id="comment" value="0">
										<input name="wpwallcreeper[rules][comment]" type="checkbox" id="comment" value="1"<?php if ($config['rules']['comment']): ?> checked<?php endif; ?>>
										<?php esc_html_e('Comments pages', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][woocommerce][product]" type="hidden" id="woocommerce-product" value="0">
										<input name="wpwallcreeper[rules][woocommerce][product]" type="checkbox" id="woocommerce-product" value="1"<?php if ($config['rules']['woocommerce']['product']): ?> checked<?php endif; ?><?php if (! class_exists('WooCommerce')): ?> disabled<?php endif; ?>>
										<?php esc_html_e('WooCommerce products', 'wp-wallcreeper'); ?>
									</label>

									</br />

									<label>
										<input name="wpwallcreeper[rules][woocommerce][category]" type="hidden" id="woocommerce-category" value="0">
										<input name="wpwallcreeper[rules][woocommerce][category]" type="checkbox" id="woocommerce-category" value="1"<?php if ($config['rules']['woocommerce']['category']): ?> checked<?php endif; ?><?php if (! class_exists('WooCommerce')): ?> disabled<?php endif; ?>>
										<?php esc_html_e('WooCommerce categories', 'wp-wallcreeper'); ?>
									</label>

								</fieldset>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>

<?php endif; ?>

			</div>
		</div>
	</div>
<?php
}