<?php
/*
 * WP Wallcreeper
 * High performance full page caching for Wordpress
 *
 * @author: Alex Alouit <alex@alouit.fr>
 */

if (! defined('ABSPATH'))
	return;

class wp_wallcreeper {

	// configuration
	private $config;

	// temporary data
	private $volatile;

	// cache instance
	private $_cache;

	/*
	 * constructor
	 *
	 * @params: void
	 * @return: void
	 */
	public function __construct() {
		// volatile data
		$this->volatile = array(
			'birth' => ((@is_int($_SERVER['REQUEST_TIME'])) ? $_SERVER['REQUEST_TIME'] : time()),
			'isConnected' => false,
			'request' => null,
			'response' => array(
				'meta' => array(
					'status' => null,
					'headers' => array(),
				),
				'content' => null,
				'gzcontent' => null
			),
			'flushed' => array()
		);

		$this->config = $this->defaultConfig();

		if (file_exists($this->configUri())) {
			if ($temp = file_get_contents($this->configUri())) {
				if ($temp = json_decode($temp, true)) {
					$this->config = array_replace_recursive($this->defaultConfig(), $temp);
				} else {
					unlink($this->configUri);
				}
			}

			unset($temp);
		}

		if (! $this->config['enable'])
			return;

		if (function_exists('apache_request_headers')) {
			$this->volatile['request'] = apache_request_headers();
		} else {
			$this->volatile['request'] = headers_list();
		}

		if (
			$this->config['engine'] === 'fs' && 
			(
				(! @is_dir($this->config['path']) && ! @mkdir($this->config['path'], 0777, true)) || 
				! @is_writable($this->config['path'])
			)
		) {
				$this->debug('for engine backend cache fs path must be present and writable');
				return;
		} elseif ($this->config['engine'] == 'memcached' && ! extension_loaded('memcached')) {
				$this->debug('for engine backend cache memcached required');
				return;
		} elseif ($this->config['engine'] == 'apc' && ! extension_loaded('apc')) {
				$this->debug('for engine backend cache apc required');
				return;
		} elseif ($this->config['engine'] == 'apcu' && ! extension_loaded('apcu')) {
				$this->debug('engine backend cache apcu required');
				return;
		} elseif ($this->config['engine'] == 'xcache' && ! extension_loaded('xcache')) {
				$this->debug('engine backend cache xcache required');
				return;
		} elseif (is_null($this->config['engine'])) {
			// automatic selection engine
			// by default, use by order apcu, apc, or memcached

			if (
				(is_dir($this->config['path']) && is_writable($this->config['path'])) ||
				@mkdir($this->config['path'], 0777, true)
			) {
				$this->config['engine'] = 'fs';
				$this->debug('engine backend cache automatic selection: fs');
			} elseif (extension_loaded('apcu')) {
				$this->config['engine'] = 'apcu';
				$this->debug('engine backend cache automatic selection: apcu');
			} elseif (extension_loaded('apc')) {
				$this->config['engine'] = 'apc';
				$this->debug('engine backend cache automatic selection: apc');
			} elseif (extension_loaded('memcached')) {
				$this->config['engine'] = 'memcached';
				$this->debug('engine backend cache automatic selection: memcached');
			} elseif (extension_loaded('xcache')) {
				$this->config['engine'] = 'xcache';
				$this->debug('engine backend cache automatic selection: xcache');
			} else {
				$this->debug('engine backend cache automatic selection: invalid');
				return;
			}
		}

		return $this->run();
	}

	/*
	 * destructor
	 *
	 * @params: void
	 * @return: void
	 */
	public function __destruct() {
		$this->exit();
	}


	/*
	 * exit
	 *
	 * @params: void
	 * @return: void
	 */
	public function exit() {
		if (
			is_null($this->volatile['response']['meta']['status']) &&
			empty($this->volatile['response']['meta']['headers']) &&
			is_null($this->volatile['response']['content']) &&
			is_null($this->volatile['response']['gzcontent'])
		) {
			return;
		}

		if ($this->config['rules']['header']) {
			if (is_int($this->volatile['response']['meta']['status'])) {
				http_response_code($this->volatile['response']['meta']['status']);
			}

			if (
				is_array($this->volatile['response']['meta']['headers']) && ! empty($this->volatile['response']['meta']['headers'])) {
				foreach ($this->volatile['response']['meta']['headers'] as $key => $line) {
					@header($line);
				}
			}
		}

		@header('x-cache-engine: wallcreeper cache (low level)');

		// gz available, valid and client support gz?
		if (
			$this->volatile['response']['meta']['length'] > 0 && 
			(
				false !== @gzdecode($this->volatile['response']['gzcontent']) && 
				false !== strpos(@$_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') && 
				$this->config['rules']['gz']
			)
		) {
			@header('Content-Encoding: gzip');
			@header('x-cache-engine: wallcreeper cache (low level + gz)');
				print $this->volatile['response']['gzcontent'];
		} elseif (
			$this->volatile['response']['meta']['length'] > 0 && 
			is_string($this->volatile['response']['content'])
		) {
			// we don't have gz, but we have ascii content
			print $this->volatile['response']['content'];
		} else {
			return;
		}

		// object is already destroyed
		if ($this->config['engine'] == 'memcached') {
			$this->_cache->quit();
		}

		exit();
	}

	/*
	 * do magic things
	 *
	 * @params: void
	 * @return: void
	 */
	private function run() {
		// connect to backend cache
		if (! $this->connect())
			return;

		// declare invalidate
		$this->invalidate();

		if (false === $this->preprocess())
			return;

		ob_start(array(&$this, 'postprocess'));
	}

	/*
	 * choose if we have to serve request
	 *
	 * @params: void
	 * @return
	 */
	private function preprocess() {
		// is a ajax request
		if (defined('DOING_AJAX'))
			return false;

		// is a post request
		if ($_SERVER["REQUEST_METHOD"] == 'POST')
			return false;

		// is args
		if (! $this->config['rules']['args'] && $_GET) {
			return false;
		}

		// is a redirection and disabled
		if (! $this->config['rules']['redirect'] && (int) http_response_code() >= 300 && (int) http_response_code() <= 399)
			return false;

		// url pattern verification
		if (
			preg_match(sprintf('#%s#', $this->config['rules']['url']), $_SERVER['REQUEST_URI']) &&
			! defined('DOING_CRON') // cron passthrough
		)
			return false;

		$cookies = $this->config['rules']['cookies'];
		if (defined('LOGGED_IN_COOKIE')) {
			$cookies .= sprintf('|^%s', constant('LOGGED_IN_COOKIE'));
		}

		// cookies pattern verification
		foreach ($_COOKIE as $key => $value) {
			if (preg_match(sprintf('#%s#', $cookies), $key))
				return false;
		}

		// fetch meta (header, status)
		if (! $meta = $this->get('.meta'))
			return;

		$this->volatile['response']['meta'] = $meta;

		$this->_304();

		// fetch ascii content
		if ($this->volatile['response']['meta']['length'] > 0 && ! $content = $this->get())
			return;

		// compare data
		if ($this->volatile['response']['meta']['length'] != strlen(@$content))
			return;

		$this->volatile['response']['content'] = @$content;

		// fetch gz and check it
		if ($gz = $this->get('.gz')) {
			// compare gz data
			if ($this->volatile['response']['meta']['length'] != strlen(gzdecode(@$gz)))
				return;

			$this->volatile['response']['gzcontent'] = @$gz;
		}

		$this->exit();
	}

	/*
	 * postprocess (callback buffer)
	 *
	 * @params: buffer
	 * @return: buffer
	 */
	private function postprocess($buffer) {
		if (defined('DOING_CRON'))
			return $buffer;

		// is an admin
		if (is_admin())
			return $buffer;

		// is home and disabled
		if (! $this->config['rules']['home'] && (function_exists('is_home') && is_home() || function_exists('is_front_page') && is_front_page()))
			return $buffer;

		if (function_exists('is_preview') && is_preview())
			return $buffer;

		// is feed and disabled
		if (! $this->config['rules']['feed'] && function_exists('is_feed') && is_feed())
			return $buffer;

		// is archive and disabled
		if (! $this->config['rules']['archive'] && function_exists('is_archive') && is_archive())
			return $buffer;

		// is category and disabled
		if (! $this->config['rules']['category'] && function_exists('is_category') && is_category())
			return $buffer;

		// is single and disabled
		if (! $this->config['rules']['single'] && function_exists('is_single') && is_single())
			return $buffer;

		// is page and disabled
		if (! $this->config['rules']['page'] && function_exists('is_page') && is_page())
			return $buffer;

		// is post and disabled
		if (! $this->config['rules']['post'] && function_exists('is_post') && is_post())
			return $buffer;

		// is tag and disabled
		if (! $this->config['rules']['tag'] && function_exists('is_tag') && is_tag())
			return $buffer;

		// is tax and disabled
		if (! $this->config['rules']['tax'] && function_exists('is_tax') && is_tax())
			return $buffer;

		// is 404 and disabled
		if (! $this->config['rules']['404'] && function_exists('is_404') && is_404())
			return $buffer;

		// is search and disabled
		if (! $this->config['rules']['search'] && function_exists('is_search') && is_search())
			return $buffer;

		if ((int) http_response_code() >= 200 && (int) http_response_code() <= 299) {
			// is succeed
		} elseif ((int) http_response_code() >= 300 && (int) http_response_code() <= 399) {
			// is redirection
		} elseif ((int) http_response_code() >= 400 && (int) http_response_code() <= 499) {
			// is a error
		} else {
			return $buffer;
		}

		$extras_headers = array();

		if (
			$this->config['rules']['304'] &&
			isset($GLOBALS['post']->post_date_gmt) &&
			isset($GLOBALS['post']->post_modified)
		) {
			$lastmodified = $GLOBALS['post']->post_date_gmt;

			if (isset($GLOBALS['post']->post_modified)) {
				$lastmodified = $GLOBALS['post']->post_modified;
			}

			if (! $lastmodified = strtotime($lastmodified)) {
				$lastmodified = time();
			}

			$extras_headers[] = sprintf(
				'Last-Modified: %s GMT',
				date(
					'D, d M Y H:i:s',
					$lastmodified
				)
			);
		}

		// store header
		if (! $this->set(
			'.meta',
			array(
			'status' => (int) http_response_code(),
			'length' => (int) strlen($buffer),
			'headers' =>
				array_merge(
					(array) $extras_headers,
					(array) headers_list()
				)
			)
		)) {
		}

		if (strlen($buffer) !== 0) {
			// store content
			$this->set(null, $buffer);

			// store gz content
			$this->set('.gz', gzencode($buffer, 9, FORCE_GZIP));
		}

		return $buffer;
}

	/*
	 * connect to cache backend
	 *
	 * @params: void
	 * @return: (bool)
	 */
	private function connect() {
		if ($this->volatile['isConnected'])
			return;

		if ($this->config['engine'] == 'memcached') {
			// connect to cache backend
			$this->_cache = new \Memcached();
			$this->_cache->setOption(\Memcached::OPT_REMOVE_FAILED_SERVERS, true);
			$this->_cache->setOption(\Memcached::OPT_RETRY_TIMEOUT, true);

			if (
				$this->config['username'] &&
				$this->config['password']
			) {
				$this->debug('enable sasl authentification');
				$this->_cache->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
				$this->_cache->setSaslAuthData($this->config['username'], $this->config['password']);
			}

				foreach ($this->config['servers'] as $server) {
					if (! $this->_cache->addServer($server['host'], $server['port'])) {
						$this->debug(sprintf('unable to connect backend cache "%s:%s"', $server['host'], $server['port']));
						return false;
					}

					$key = sprintf('%s:%s', (string) $server['host'], (int) $server['port']);
					$list = (array) $this->_cache->getVersion();

					if (array_key_exists($key, $list) && $list[$key]) {
						$this->debug(sprintf('unable to fetch backend cache version "%s:%s"', $server['host'], $server['port']));
//						return false;
					}
				}
		} elseif ($this->config['engine'] == 'apc') {
		} elseif ($this->config['engine'] == 'apcu') {
		} elseif ($this->config['engine'] == 'fs') {
		} elseif ($this->config['engine'] == 'xcache') {
		}

		$this->volatile['isConnected'] = true;
		$this->debug('backend cache connected');
		return true;
	}

	/*
	 * get cache entry (formatted)
	 *
	 * @params: (string) suffix key
	 * @return: (mixed) result
	 * @return: (bool) false
	 */
	public function get($suffix = null) {
		$key = sprintf(
			'%s::%s://%s%s%s',
			$_SERVER['REQUEST_METHOD'],
			isset($_SERVER['HTTPS']) ? 'https' : 'http',
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$suffix
		);

		return $this->_get($key);
	}

	/*
	 * get cache entry (non-formatted)
	 *
	 * @params: (string) suffix key
	 * @params: (mixed) return value if not
	 * @return: (mixed) result
	 * @return: (bool) false
	 */
	public function _get($key = null, $return = false) {
		if (! $key)
			return false;

		$this->connect();

		if ($this->config['engine'] == 'memcached') {
			if (! $result = $this->_cache->get($key)) {
				$this->debug(sprintf('unable to get backend cache key %s', $key));
				return $return;
			}
		} elseif ($this->config['engine'] == 'apc') {
			if (! $result = apc_fetch($key)) {
				$this->debug(sprintf('unable to get backend cache key %s', $key));
				return $return;
			}
		} elseif ($this->config['engine'] == 'apcu') {
			if (! $result = apcu_fetch($key)) {
				$this->debug(sprintf('unable to get backend cache key %s', $key));
				return $return;
			}
		} elseif ($this->config['engine'] == 'xcache') {
			if (! $result = xcache_get($key)) {
				$this->debug(sprintf('unable to get backend cache key %s', $key));
				return $return;
			}
		} elseif ($this->config['engine'] == 'fs') {
			if ($result = @json_decode(@file_get_contents($this->config['path'] . rawurlencode($key)), true)) {
			} elseif (! $result = @file_get_contents($this->config['path'] . rawurlencode($key))) {
				$this->debug(sprintf('unable to get backend cache key %s', $key));
				return $return;
			}

			if (
				$this->config['ttl'] && 
				@filectime($this->config['path'] . rawurlencode($key)) < (time() - $this->config['ttl'])
			) {
					$this->_delete($key);
					return $return;
			}
		}

		return $result;
	}

	/*
	 * check entry exists
	 *
	 * @params: (string) suffix key
	 * @return: (bool)
	 */
	public function _exists($key = null) {
		if (! $key)
			return false;

		$this->connect();

		if ($this->config['engine'] == 'memcached') {
			if (! $this->_cache->get($key))
				return false;
		} elseif ($this->config['engine'] == 'apc') {
			return apc_exists($key);
		} elseif ($this->config['engine'] == 'apcu') {
			return apcu_exists($key);
		} elseif ($this->config['engine'] == 'xcache') {
			return xcache_isset($key);
		} elseif ($this->config['engine'] == 'fs') {
			if (! @file_exists($this->config['path'] . rawurlencode($key)))
				return false;

			if (
					$this->config['ttl'] && 
					@filectime($this->config['path'] . rawurlencode($key)) < (time() - $this->config['ttl'])
			) {
				$this->_delete($key);
				return false;
			}
		}

		return true;
	}

	/*
	 * set cache entry (formatted)
	 *
	 * @params: (string) suffix key
	 * @params: (mixed) data
	 * @return: (bool) state
	 */
	public function set($suffix = null, $data) {
		$key = sprintf(
			'%s::%s://%s%s%s',
			$_SERVER['REQUEST_METHOD'],
			isset($_SERVER['HTTPS']) ? 'https' : 'http',
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$suffix
		);

		return $this->_set($key, $data);
	}

	/*
	 * set cache entry (non-formatted)
	 *
	 * @params: (string) key
	 * @params: (mixed) data
	 * @params: (int) ttl
	 * @return: (bool) state
	 */
	public function _set($key = null, $data, $ttl = null) {
		if (! $key)
			return false;

		$this->connect();

		if (! $ttl) {
			$ttl = $this->config['ttl'];
		}

		if (
			$this->config['engine'] == 'memcached' &&
			(
				($ttl && ! $this->_cache->set($key, $data, $ttl)) ||
				(! $ttl && ! $this->_cache->set($key, $data))
			)
		) {
			$this->debug(sprintf('unable to set backend cache key %s', $key));
			return false;
		} elseif (
			$this->config['engine'] == 'apc' &&
			(
				($ttl && ! apc_store($key, $data, $ttl)) ||
				(! $ttl && ! apc_store($key, $data))
			)
		) {
			$this->debug(sprintf('unable to set backend cache key %s', $key));
			return false;
		} elseif (
			$this->config['engine'] == 'apcu' &&
			(
				($ttl && ! apcu_store($key, $data, $ttl)) ||
				(! $ttl && ! apcu_store($key, $data))
			)
		) {
			$this->debug(sprintf('unable to set backend cache key %s', $key));
			return false;
		} elseif (
			$this->config['engine'] == 'xcache' &&
			(
				($ttl && ! xcache_set($key, $data, $ttl)) ||
				(! $ttl && ! xcache_set($key, $data))
			)
		) {
			$this->debug(sprintf('unable to set backend cache key %s', $key));
			return false;
		} elseif (
			$this->config['engine'] === 'fs' && 
			(
				(is_array($data) && ! @file_put_contents($this->config['path'] . rawurlencode($key), json_encode($data))) ||
				(! is_array($data) && ! @file_put_contents($this->config['path'] . rawurlencode($key), $data))
			)
		) {
			$this->debug(sprintf('unable to set backend cache key %s', $key));
			return false;
		}

		return true;
	}

	/*
	 * delete cache (formatted)
	 *
	 * @params: (string) suffix key 
	 * @return: (bool) state
	 */
	public function delete($suffix = null) {
		$key = sprintf(
			'%s::%s://%s%s%s',
			$_SERVER['REQUEST_METHOD'],
			isset($_SERVER['HTTPS']) ? 'https' : 'http',
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$suffix
		);

		return $this->_delete($key);
	}

	/*
	 * delete cache entry entry (non-formatted)
	 *
	 * @params: (string) key
	 * @return: (bool) state
	 */
	public function _delete($key = null) {
		if (! $key)
			return false;

		$this->connect();

		if ($this->config['engine'] === 'memcached') {
			if (! $this->_cache->delete($key)) {
				$this->debug(sprintf('unable to delete backend cache key %s', $key));
				return false;
			}
		} elseif ($this->config['engine'] === 'apc') {
			if (! apc_delete($key)) {
				$this->debug(sprintf('unable to delete backend cache key %s', $key));
				return false;
			}
		} elseif ($this->config['engine'] === 'apcu') {
			if (! apcu_delete($key)) {
				$this->debug(sprintf('unable to delete backend cache key %s', $key));
				return false;
			}
		} elseif ($this->config['engine'] === 'xcache') {
			if (! xcache_unset($key)) {
				$this->debug(sprintf('unable to delete backend cache key %s', $key));
				return false;
			}
		} elseif ($this->config['engine'] === 'fs') {
			if (! @unlink($this->config['path'] . rawurlencode($key))) {
				$this->debug(sprintf('unable to delete backend cache key %s', $key));
				return false;
			}
		}

		$this->debug(sprintf('backend cache key %s deleted', $key));

		return true;
	}

	/*
	 * flush cache
	 *
	 * @params: (string) pattern
	 * @return: (bool)
	 */
	public function flush($pattern = false, $_from = false) {
		$this->connect();

		if (! $pattern) {
			$pattern = $_SERVER['HTTP_HOST'];
		}

		$from = time();
		if ($_from) {
			$from = $_from;
		}

		$this->debug(sprintf('flush backend cache for %s pattern', $pattern));

		if ($this->config['engine'] === 'memcached') {
			// sasl require binary mode
			// and libmemcached don't support get all keys in binary mode
			// @see: https://github.com/trondn/libmemcached/blob/ca739a890349ac36dc79447e37da7caa9ae819f5/libmemcached/dump.c#L93
			if (
				$this->config['username'] &&
				$this->config['password']
			) {
				$this->_cache->flush();
			}
		} elseif ($this->config['engine'] === 'xcache') {
			xcache_unset_by_prefix($pattern);
		} else {
			foreach ($this->getAll() as $item) {
				if (
					$this->config['rules']['asyncflush'] &&
					$this->volatile['birth'] + $this->config['precache']['timeout'] < time()
				) {
					$this->debug('async flush hit timeout');

					if (! $_from) {
						$this->_set(
							sprintf(
								'%s.flush',
								$_SERVER['HTTP_HOST']
							),
							array_merge(
								array(
									array(
										'pattern' => $pattern,
										'from' => $from
									)
								),
								(array) $this->_get(
									sprintf(
										'%s.flush',
										$_SERVER['HTTP_HOST']
									),
									array()
								)
							),
							$this->config['ttl']
						);
					}

					return false;
				}

				if (false !== strpos($item['url'], '.generatelist')) {
					continue;
				}

				if (false !== strpos($item['url'], '.flush')) {
					continue;
				}

				if (
					$item['ttl'] < $from ||
					false !== strpos($item['url'], $pattern) // we don't use regexp, special char causing troubles
				) {
					$this->_delete($item['url']);
				}
			}
		}

		$this->debug('backend cache flushed');
		return true;
	}

	/*
	 * list cache
	 *
	 * @params: null
	 * @return: (bool)
	 */
	public function getAll() {
		$this->connect();

		$pattern = $_SERVER['HTTP_HOST'];

		$this->debug(sprintf('get all keys for %s pattern', $pattern));

		$data = array();

		if ($this->config['engine'] === 'memcached') {
			foreach ((array) $this->_cache->getAllKeys() as $item) {
				// sasl require binary mode
				// and libmemcached don't support get all keys in binary mode
				// @see: https://github.com/trondn/libmemcached/blob/ca739a890349ac36dc79447e37da7caa9ae819f5/libmemcached/dump.c#L93
				if (
					$this->config['username'] &&
					$this->config['password']
				) {
					return array();
				} else {
					if (false !== strpos($item, $pattern)) { // we don't use regexp, special char causing troubles
						$data[] = array('url' => $item, 'ttl' => 0);
					}
				}
			}
		} elseif ($this->config['engine'] === 'apc') {
			foreach ((array) new APCIterator(null, APC_ITER_KEY + APC_ITER_CTIME) as $item) {
				if (false !== strpos($item['key'], $pattern)) { // we don't use regexp, special char causing troubles
					$data[] = array('url' => $item['key'], 'ttl' => ($item['creation_time'] + $this->config['ttl']));
				}
			}
		} elseif ($this->config['engine'] === 'apcu') {
			foreach ((array) new APCUIterator(null, APC_ITER_KEY + APC_ITER_CTIME) as $item) {
				if (false !== strpos($item['key'], $pattern)) { // we don't use regexp, special char causing troubles
					$data[] = array('url' => $item['key'], 'ttl' => ($item['creation_time'] + $this->config['ttl']));
				}
			}
		} elseif ($this->config['engine'] === 'fs') {
			foreach ((array) @scandir($this->config['path']) as $item) {
				if (! is_file($this->config['path'] . $item)) {
					continue;
				}

				if (
					$this->config['ttl'] && 
					@filectime($this->config['path'] . $item) < (time() - $this->config['ttl'])
				) {
					$this->_delete(rawurldecode($item));
				} elseif (false !== strpos($item, $pattern)) { // we don't use regexp, special char causing troubles
					$data[] = array('url' => rawurldecode($item), 'ttl' => (@filectime($this->config['path'] . $item) + $this->config['ttl']));
				}
			}
		} elseif ($this->config['engine'] === 'xcache') {
			// xcache don't support list by default (administration access required)
			return array();
		}

		return $data;
	}

	/*
	 *
	 * @params: (void)
	 * @return: (bool)
	 */
	public function flush_by_id($id) {
		if (! is_int($id)) {
			// we don't have specific int cast
			return false;
		}

		if (in_array($id, $this->volatile['flushed'])) {
			// already flushed
			return;
		}

		$this->debug('"flush by id" backend cache triggered');

		// find the post to remove
		if ($url = get_permalink($id)) {
			$this->volatile['flushed'][] = $id;
			return $this->flush($url);
		} else {
			return false;
		}
	}

	/*
	 * decalare invalidate actions
	 *
	 * @params: void
	 * @return: void
	 */
	private function invalidate() {
		// invalidate
		add_action('edit_post', array(&$this, 'flush_by_id'), 0);
		add_action('delete_post', array(&$this, 'flush_by_id'), 0);
		add_action('transition_post_status', array(&$this, 'flush'), 20);
		add_action('clean_post_cache', array(&$this, 'flush_by_id'));

		add_action('comment_post', array(&$this, 'flush_by_id'), 0);
		add_action('edit_comment', array(&$this, 'flush_by_id'), 0);
		add_action('trashed_comment', array(&$this, 'flush_by_id'), 0);
		add_action('pingback_post', array(&$this, 'flush_by_id'), 0);
		add_action('trackback_post', array(&$this, 'flush_by_id'), 0);
		add_action('wp_insert_comment', array(&$this, 'flush_by_id'), 0);

		add_action('switch_theme', array(&$this, 'flush'), 0);
	}

	/*
	 * check if 304 required
	 *
	 * @params: comparator
	 * @return: null
	 */
	private function _304() {
		if (! $this->config['rules']['304'])
			return;

		// we have If-Modified-Since header?
		if (! isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]))
			return;

		// is If-Modified-Since exploitable?
		if (! strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]))
			return;

		// we have Last-Modified header?
		if (! $lastmodified = @preg_grep('/^Last-Modified: (.*)/i', $this->volatile['response']['meta']['headers'])[0])
			return;

		// is Last-Modified exploitable?
		if (! strtotime(explode(': ', $lastmodified)[1]))
			return;

		// is valid?
		if (strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) > strtotime(explode(': ', $lastmodified)[1])) {
			$this->volatile['response']['meta']['status'] = 304;
			$this->exit();
		}
	}

	/*
	 * do cron procedure
	 *
	 * @params: (int) status code (non-required, current by default)
	 * @return: (string) complete status code
	 */
	public function cron() {
		if (! defined('DOING_CRON'))
			return;

		$this->debug('cron start');

		$this->connect();

		if (
			(defined('WP_ALLOW_MULTISITE') && constant('WP_ALLOW_MULTISITE')) ||
			(function_exists('is_multisite') && is_multisite()) &&
			(function_exists('get_current_blog_id') && function_exists('get_blog_status'))
		) {
			// we are in MU

			// fetch id
			$id = get_current_blog_id();

			// is active?
			if (get_blog_status($id, 'archived'))
				return true;

			if (get_blog_status($id, 'deleted'))
				return true;
		}

		if ($this->config['rules']['asyncflush']) {
			$flush = $this->_get(
				sprintf(
					'%s.flush',
					$_SERVER['HTTP_HOST']
				)
			);

			if ($flush) {
				$this->debug('found async flush');
				foreach ($flush as $key => $item) {
					if ($this->flush($item['pattern'], $item['from'])) {
						unset($flush[$key]);
					}
				}

				if (empty($flush)) {
					$this->debug('async flush done');
					$this->_delete(
						sprintf(
							'%s.flush',
							$_SERVER['HTTP_HOST']
						)
					);
				} else {
					$this->debug('async flush update');
					$this->_set(
						sprintf(
						'%s.flush',
							$_SERVER['HTTP_HOST']
						),
						(array) $flush,
						$this->config['ttl']
					);
				}
			}
		}

		$list = $this->_get(
			sprintf(
				'%s.generatelist',
				$_SERVER['HTTP_HOST']
			)
		);

		// have we list to do?
		if (! $list) {
			// generate cache list files
			$this->debug('generate list');

			global $wpdb;

			if ($this->config['precache']['home']) {
				$list[] = @home_url();
			}

			// do own query to fetch id only

			if ($this->config['precache']['post']) {
				foreach ($wpdb->get_col("SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_type` = 'post' AND `post_status` = 'publish' ORDER BY `post_date_gmt` DESC, `post_modified_gmt` DESC") as $id) {
					$list[] = get_post_permalink((int) $id);
				}
			}

			if ($this->config['precache']['page']) {
				foreach ($wpdb->get_col("SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_type` = 'page' AND `post_status` = 'publish' ORDER BY `post_date_gmt` DESC, `post_modified_gmt` DESC") as $id) {
					$list[] = get_page_link((int) $id);
				}
			}

			if ($this->config['precache']['tag']) {
				foreach ($wpdb->get_col("SELECT `te`.`term_id` FROM `{$wpdb->terms}` as te,`{$wpdb->term_taxonomy}` as ta WHERE `te`.`term_id` = `ta`.`term_id` AND `ta`.`taxonomy` = 'post_tag'") as $id) {
					$list[] = get_term_link((int) $id, 'post_tag');
				}
			}

			if ($this->config['precache']['category']) {
				foreach ($wpdb->get_col("SELECT `te`.`term_id` FROM `{$wpdb->terms}` as te,`{$wpdb->term_taxonomy}` as ta WHERE `te`.`term_id` = `ta`.`term_id` AND `ta`.`taxonomy` = 'category'") as $id) {
					$list[] = get_term_link($id, 'category');
				}
			}

			if ($this->config['precache']['feed']) {
				$list[] = get_feed_link();
			}

			if (class_exists('WooCommerce')) {
				if ($this->config['precache']['woocommerce']['product']) {
					foreach ($wpdb->get_col("SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_type` = 'product' AND `post_status` = 'publish' ORDER BY `post_date_gmt` DESC, `post_modified_gmt` DESC") as $id) {
						$list[] = get_post_permalink((int) $id);
					}
				}

				if ($this->config['precache']['woocommerce']['category']) {
					foreach ($wpdb->get_col("SELECT `te`.`term_id` FROM `{$wpdb->terms}` as te,`{$wpdb->term_taxonomy}` as ta WHERE `te`.`term_id` = `ta`.`term_id` AND `ta`.`taxonomy` = 'product_cat'") as $id) {
						$list[] = get_term_link((int) $id, 'product_cat');
					}
				}

				if ($this->config['precache']['woocommerce']['tag']) {
					foreach ($wpdb->get_col("SELECT `te`.`term_id` FROM `{$wpdb->terms}` as te,`{$wpdb->term_taxonomy}` as ta WHERE `te`.`term_id` = `ta`.`term_id` AND `ta`.`taxonomy` = 'product_tag'") as $id) {
						$list[] = get_term_link((int) $id, 'product_tag');
					}
				}
			}
		} else {
			$this->debug('found old list');
		}

		if (empty($list))
			return true;

		// be sure all links are string
		$list = array_filter($list, 'is_string');

		// search for duplicate
		$list = array_unique($list);

		// is a valid url?
//		$list = array_filter($list, function ($v) { if (false === strpos($v, $_SERVER['HTTP_HOST'])) { return false; } else { return true; } } );

		// save list
		$this->_set(
			sprintf(
				'%s.generatelist',
				$_SERVER['HTTP_HOST']
			),
			(array) $list,
			$this->config['ttl']
		);

		$done = 0;
		foreach ($list as $key => &$link) {

			// set limit by request
			if ($done >= $this->config['precache']['maximum_request']) {
				$this->debug('reach limit');
				break;
			}

			// set limit by timeout
			if ($this->volatile['birth'] + $this->config['precache']['timeout'] < time()) {
				$this->debug('reach timeout');
				break;
			}

			// remove entry from list
			unset($list[$key]);

			// update list every 10 times
			if (is_int($key / 10)) {
				$this->debug('update list');

				$this->_set(
					sprintf(
						'%s.generatelist',
						$_SERVER['HTTP_HOST']
					),
					(array) array_values((array) $list), // re-order array keys
					$this->config['ttl']
				);
			}

			// is already in cache?
			if ($this->_exists(sprintf('GET::%s.meta', $link))) {
				continue;
			}

			$done++;

			$this->debug(sprintf('generate %s', $link));

			// generate cache
			$context = stream_context_create(
				array(
					'http' => array(
						'user_agent' => 'wallcreeper'
					)
				)
			);

			$page = @file_get_contents($link, false, $context);

		}

		if (empty($list)) {
			$this->debug('list empty, update list');

			// if we have do all the list, remove list
			$this->_delete(
				sprintf(
					'%s.generatelist',
					$_SERVER['HTTP_HOST']
				)
			);
		}

		return true;
	}

	private function debug($msg) {
		if (! $this->config['debug'])
			return;

		error_log($msg);
	}

	public function config() {
		return $this->config;
	}

	private function configUri() {
		$path = constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'wpwallcreeper.' . htmlentities($_SERVER['HTTP_HOST']) . '.conf';

		// backward compatibility
		global $blog_id;
		$old_path = constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'wpwallcreeper.' . $blog_id . '.conf';
		if (is_file($old_path)) {
			rename($old_path, $path);
		}

		return $path;
}

	public function setConfig($data = array()) {
		file_put_contents(
			$this->configUri(),
			json_encode(
				array_replace_recursive(
					(new wp_wallcreeper())->config(),
					(array) $data
				)
			)
		);
	}

	public function defaultConfig() {
		return array(
			'enable' => false,

			'object' => false,

			'engine' => 'fs',

			'path' => constant('WP_CONTENT_DIR') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'wpwallcreeper' . DIRECTORY_SEPARATOR,

			'servers' => array(
				array(
					'host' => '127.0.0.1',
					'port' => 11211
				)
			),

			'username' => null,
			'password' => null,

			'ttl' => 10800,

			'rules' => array(
				'cookies' => '^wordpress_logged_in_|^wp-postpass_|^wordpressuser_|^comment_author_|^wp_woocommerce_|^woocommerce_',
				'url' => '^/wp-|^/wc-api|^/\?wc-api=',

				'home' => true,
				'archive' => true,
				'category' => true,
				'post' => true,
				'page' => true,
				'feed' => true,
				'tag' => true,
				'tax' => true,
				'single' => true,
				'search' => true,
				'comment' => true,

				'header' => true,
				'redirect' => true,
				'304' => true,
				'404' => false,

				'gz' => true,

				'args' => false,

				'asyncflush' => true,

				'woocommerce' => array(
					'product' => true,
					'category' => true
				)
			),

			'precache' => array(
				'maximum_request' => 64,
				'timeout' => ((@ini_get('max_execution_time')) ? (ini_get('max_execution_time') * 0.75) : 25),
				'home' => true,
				'category' => true,
				'page' => true,
				'post' => true,
				'feed' => true,
				'tag' => true,
				'woocommerce' => array(
					'product' => true,
					'category' => true,
					'tag' => true
				)
			),

			'debug' => false,
			'external' => false
		);
	}

	public function haveConfig() {
		return file_exists($this->configUri());
	}
}

?>
