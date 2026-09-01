<?php
/**
 * Zinn Cache object-cache drop-in — a persistent WordPress object cache backed by Redis (phpredis).
 *
 * This file is copied to `wp-content/object-cache.php` by the Zinn Cache plugin. WordPress loads it
 * very early (before most of core), so it must be self-contained and must never fatal: when the
 * phpredis extension is missing, disabled, or unreachable it degrades to a purely in-request,
 * non-persistent cache so the site keeps serving. Persistent groups round-trip through Redis; a
 * runtime (in-request) layer sits in front of Redis for speed.
 *
 * The first comment line above contains the exact marker `Zinn Cache object-cache drop-in`; the
 * plugin recognises its own drop-in by reading the first 512 bytes for that string.
 *
 * License: GPL-2.0-or-later (https://www.gnu.org/licenses/gpl-2.0.html).
 *
 * @package Zinn\Cache
 * @author  Neil Lock — CEO, Zinn Digital® Ltd
 * @link    https://zinndigital.com
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Redis-backed implementation of the WordPress object cache.
 *
 * Implements the full `WP_Object_Cache` contract expected by WordPress core, plus multiple-key
 * helpers and feature discovery. All Redis access is wrapped in try/catch: on any failure the
 * connection is marked dead and reads/writes fall back to the in-request runtime cache, so a Redis
 * outage never takes the site down.
 */
class WP_Object_Cache {

	/**
	 * Marker string that identifies this file as the Zinn drop-in.
	 *
	 * @var string
	 */
	public const MARKER = 'Zinn Cache object-cache drop-in';

	/**
	 * Cumulative number of cache hits served this request.
	 *
	 * @var int
	 */
	public int $cache_hits = 0;

	/**
	 * Cumulative number of cache misses this request.
	 *
	 * @var int
	 */
	public int $cache_misses = 0;

	/**
	 * Groups that are shared across every site in a multisite network.
	 *
	 * Keyed by group name with a boolean `true` value for O(1) look-ups.
	 *
	 * @var array<string,bool>
	 */
	public array $global_groups = array();

	/**
	 * Per-blog key segment used for non-global groups on multisite (empty on single site).
	 *
	 * @var string
	 */
	public string $blog_prefix = '';

	/**
	 * Whether the install is a multisite network.
	 *
	 * @var bool
	 */
	public bool $multisite = false;

	/**
	 * In-request runtime cache, keyed by group then key.
	 *
	 * @var array<string,array<int|string,mixed>>
	 */
	private array $cache = array();

	/**
	 * Active phpredis connection, or null when running in-memory only.
	 *
	 * @var \Redis|null
	 */
	private ?\Redis $redis = null;

	/**
	 * Whether a live, authenticated Redis connection is currently available.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Groups that are never persisted to Redis (in-request memory only).
	 *
	 * Keyed by group name with a boolean `true` value.
	 *
	 * @var array<string,bool>
	 */
	private array $non_persistent_groups = array();

	/**
	 * Global key prefix from configuration (may be empty).
	 *
	 * @var string
	 */
	private string $global_prefix = '';

	/**
	 * Extra salt prefix from the `WP_CACHE_KEY_SALT` constant (may be empty).
	 *
	 * @var string
	 */
	private string $key_salt = '';

	/**
	 * Whether the igbinary extension is available for (de)serialisation.
	 *
	 * @var bool
	 */
	private bool $use_igbinary = false;

	/**
	 * Normalised connection configuration (host, port, database, password, prefix, timeout).
	 *
	 * @var array{host:string,port:int,database:int,password:string,prefix:string,timeout:float}
	 */
	private array $config = array();

	/**
	 * Build the cache, load configuration, and attempt the Redis connection.
	 */
	public function __construct() {
		$this->use_igbinary = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' );
		$this->multisite    = ( function_exists( 'is_multisite' ) && is_multisite() ) || ( defined( 'MULTISITE' ) && MULTISITE );

		if ( $this->multisite ) {
			$blog_id           = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : (int) ( $GLOBALS['blog_id'] ?? 1 );
			$this->blog_prefix = $blog_id . ':';
		}

		$this->global_groups = array_fill_keys(
			array(
				'users',
				'userlogins',
				'useremail',
				'user_meta',
				'site-transient',
				'site-options',
				'blog-lookup',
				'blog-details',
				'site-details',
				'rss',
				'global-posts',
				'blog_meta',
				'networks',
				'sites',
			),
			true
		);

		$this->non_persistent_groups = array_fill_keys(
			array(
				'counts',
				'plugins',
				'theme_json',
				'comment',
				'wc_session_id',
			),
			true
		);

		$this->config   = $this->load_config();
		$this->key_salt = defined( 'WP_CACHE_KEY_SALT' ) ? (string) WP_CACHE_KEY_SALT : '';

		// Keys MUST always be namespaced: if neither a configured prefix nor a
		// key salt is present, derive a stable per-install namespace. Without this,
		// flush() would SCAN the pattern "*" and UNLINK every key in a (possibly
		// shared) Redis database — including other apps'/sites' data.
		$configured_prefix = $this->config['prefix'];
		if ( '' === $configured_prefix && '' === $this->key_salt ) {
			$configured_prefix = $this->derive_prefix();
		}
		$this->global_prefix = $configured_prefix;

		if ( ! ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) ) {
			$this->connect();
		}
	}

	/**
	 * Add data to the cache only if the key does not already exist.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default 'default'.
	 * @param int        $expire Optional. Expiry in seconds; 0 means no expiry. Default 0.
	 * @return bool True on success, false if the key already exists or additions are suspended.
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ): bool {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}

		$group = $this->normalize_group( $group );

		if ( $this->exists( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	/**
	 * Add multiple values to the cache in one call, skipping keys that already exist.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value to add.
	 * @param string                  $group  Optional. Cache group. Default 'default'.
	 * @param int                     $expire Optional. Expiry in seconds. Default 0.
	 * @return array<int|string,bool> Map of key => success flag.
	 */
	public function add_multiple( array $data, $group = 'default', $expire = 0 ): array {
		$results = array();

		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}

		return $results;
	}

	/**
	 * Replace data in the cache only if the key already exists.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default 'default'.
	 * @param int        $expire Optional. Expiry in seconds. Default 0.
	 * @return bool True on success, false if the key does not exist.
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ): bool {
		$group = $this->normalize_group( $group );

		if ( ! $this->exists( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	/**
	 * Store data in the cache, overwriting any existing value.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default 'default'.
	 * @param int        $expire Optional. Expiry in seconds; 0 means no expiry. Default 0.
	 * @return bool Always true (the value is at least cached in the runtime layer).
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ): bool {
		$group  = $this->normalize_group( $group );
		$expire = max( 0, (int) $expire );
		$store  = is_object( $data ) ? clone $data : $data;

		$this->cache[ $group ][ $key ] = $store;

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			return true;
		}

		try {
			$this->write_to_redis( $key, $store, $group, $expire );
		} catch ( \Exception $e ) {
			$this->handle_exception();
		}

		return true;
	}

	/**
	 * Store multiple values in the cache in one call.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value to store.
	 * @param string                  $group  Optional. Cache group. Default 'default'.
	 * @param int                     $expire Optional. Expiry in seconds. Default 0.
	 * @return array<int|string,bool> Map of key => success flag.
	 */
	public function set_multiple( array $data, $group = 'default', $expire = 0 ): array {
		$results = array();

		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->set( $key, $value, $group, $expire );
		}

		return $results;
	}

	/**
	 * Retrieve a value from the cache.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Optional. Cache group. Default 'default'.
	 * @param bool       $force Optional. Re-read from Redis, bypassing the runtime layer. Default false.
	 * @param bool       $found Optional. Set by reference to whether the key was found. Default null.
	 * @return mixed The cached value, or false when not found.
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$group = $this->normalize_group( $group );

		if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			$found = true;
			++$this->cache_hits;
			return $this->copy_value( $this->cache[ $group ][ $key ] );
		}

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}

		try {
			$raw = $this->redis->get( $this->build_key( $key, $group ) );
		} catch ( \Exception $e ) {
			$this->handle_exception();
			$found = false;
			++$this->cache_misses;
			return false;
		}

		if ( false === $raw ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}

		$value                         = $this->unserialize_value( $raw );
		$this->cache[ $group ][ $key ] = $value;
		$found                         = true;
		++$this->cache_hits;

		return $this->copy_value( $value );
	}

	/**
	 * Retrieve multiple values from the cache in one call.
	 *
	 * @param array<int,int|string> $keys  Keys to fetch.
	 * @param string                $group Optional. Cache group. Default 'default'.
	 * @param bool                  $force Optional. Bypass the runtime layer. Default false.
	 * @return array<int|string,mixed> Map of key => value (false for keys that were not found).
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ): array {
		$group  = $this->normalize_group( $group );
		$values = array();
		$needed = array();

		foreach ( $keys as $key ) {
			if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
				++$this->cache_hits;
				$values[ $key ] = $this->copy_value( $this->cache[ $group ][ $key ] );
				continue;
			}

			if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
				++$this->cache_misses;
				$values[ $key ] = false;
				continue;
			}

			$needed[ $key ] = $this->build_key( $key, $group );
		}

		if ( array() !== $needed ) {
			$raws = false;

			try {
				$raws = $this->redis->mget( array_values( $needed ) );
			} catch ( \Exception $e ) {
				$this->handle_exception();
			}

			$index = 0;
			foreach ( $needed as $key => $unused ) {
				$raw = is_array( $raws ) && array_key_exists( $index, $raws ) ? $raws[ $index ] : false;
				++$index;

				if ( false === $raw ) {
					++$this->cache_misses;
					$values[ $key ] = false;
					continue;
				}

				$value                         = $this->unserialize_value( $raw );
				$this->cache[ $group ][ $key ] = $value;
				++$this->cache_hits;
				$values[ $key ] = $this->copy_value( $value );
			}
		}

		$ordered = array();
		foreach ( $keys as $key ) {
			$ordered[ $key ] = array_key_exists( $key, $values ) ? $values[ $key ] : false;
		}

		return $ordered;
	}

	/**
	 * Delete a value from the cache.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Optional. Cache group. Default 'default'.
	 * @return bool True if something was removed, false otherwise.
	 */
	public function delete( $key, $group = 'default' ): bool {
		$group   = $this->normalize_group( $group );
		$existed = isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] );

		unset( $this->cache[ $group ][ $key ] );

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			return $existed;
		}

		try {
			$count = (int) $this->redis->del( $this->build_key( $key, $group ) );
		} catch ( \Exception $e ) {
			$this->handle_exception();
			return $existed;
		}

		return $existed || $count > 0;
	}

	/**
	 * Delete multiple values from the cache in one call.
	 *
	 * @param array<int,int|string> $keys  Keys to delete.
	 * @param string                $group Optional. Cache group. Default 'default'.
	 * @return array<int|string,bool> Map of key => whether it was removed.
	 */
	public function delete_multiple( $keys, $group = 'default' ): array {
		$results = array();

		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}

		return $results;
	}

	/**
	 * Atomically increment a numeric cache value.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Optional. Amount to increment by. Default 1.
	 * @param string     $group  Optional. Cache group. Default 'default'.
	 * @return int|false The new value, or false if the key does not exist.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		$group  = $this->normalize_group( $group );
		$offset = (int) $offset;

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			return $this->modify_runtime( $key, $offset, $group );
		}

		$full = $this->build_key( $key, $group );

		try {
			if ( ! $this->redis->exists( $full ) ) {
				return false;
			}

			$current = $this->redis->get( $full );
			if ( ! is_string( $current ) || (string) (int) $current !== $current ) {
				$this->redis->set( $full, 0 );
			}

			$value = (int) $this->redis->incrBy( $full, $offset );
			if ( 0 > $value ) {
				$this->redis->set( $full, 0 );
				$value = 0;
			}

			$this->cache[ $group ][ $key ] = $value;
			return $value;
		} catch ( \Exception $e ) {
			$this->handle_exception();
			return $this->modify_runtime( $key, $offset, $group );
		}
	}

	/**
	 * Atomically decrement a numeric cache value, clamping the result at zero.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Optional. Amount to decrement by. Default 1.
	 * @param string     $group  Optional. Cache group. Default 'default'.
	 * @return int|false The new value, or false if the key does not exist.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		$group  = $this->normalize_group( $group );
		$offset = (int) $offset;

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			return $this->modify_runtime( $key, - $offset, $group );
		}

		$full = $this->build_key( $key, $group );

		try {
			if ( ! $this->redis->exists( $full ) ) {
				return false;
			}

			$current = $this->redis->get( $full );
			if ( ! is_string( $current ) || (string) (int) $current !== $current ) {
				$this->redis->set( $full, 0 );
			}

			$value = (int) $this->redis->decrBy( $full, $offset );
			if ( 0 > $value ) {
				$this->redis->set( $full, 0 );
				$value = 0;
			}

			$this->cache[ $group ][ $key ] = $value;
			return $value;
		} catch ( \Exception $e ) {
			$this->handle_exception();
			return $this->modify_runtime( $key, - $offset, $group );
		}
	}

	/**
	 * Flush the entire cache: clear the runtime layer and delete every key under our prefix.
	 *
	 * Uses a non-blocking SCAN loop and never issues FLUSHDB/FLUSHALL, because the Redis instance
	 * may be shared with other applications.
	 *
	 * @return bool True on success.
	 */
	public function flush(): bool {
		$this->cache = array();

		if ( ! $this->is_connected() ) {
			return true;
		}

		return $this->scan_delete( $this->global_prefix . $this->key_salt . '*' );
	}

	/**
	 * Clear only the in-request runtime cache, leaving Redis untouched.
	 *
	 * @return bool Always true.
	 */
	public function flush_runtime(): bool {
		$this->cache = array();

		return true;
	}

	/**
	 * Remove every key belonging to a single cache group.
	 *
	 * @param string $group Cache group to flush.
	 * @return bool True on success.
	 */
	public function flush_group( $group ): bool {
		$group = $this->normalize_group( $group );

		unset( $this->cache[ $group ] );

		if ( ! $this->is_connected() || $this->is_non_persistent_group( $group ) ) {
			return true;
		}

		$site    = $this->is_global_group( $group ) ? '' : $this->blog_prefix;
		$pattern = $this->global_prefix . $this->key_salt . $site . $group . ':*';

		return $this->scan_delete( $pattern );
	}

	/**
	 * Close the Redis connection.
	 *
	 * @return bool Always true.
	 */
	public function close(): bool {
		if ( $this->is_connected() ) {
			try {
				$this->redis->close();
			} catch ( \Exception $e ) {
				$this->handle_exception();
			}
		}

		$this->connected = false;

		return true;
	}

	/**
	 * Register groups that are shared across all sites in a network.
	 *
	 * @param array<int,string>|string $groups Group name(s) to mark global.
	 * @return void
	 */
	public function add_global_groups( $groups ): void {
		foreach ( (array) $groups as $group ) {
			$this->global_groups[ $group ] = true;
		}
	}

	/**
	 * Register groups that must never be persisted to Redis.
	 *
	 * @param array<int,string>|string $groups Group name(s) to mark non-persistent.
	 * @return void
	 */
	public function add_non_persistent_groups( $groups ): void {
		foreach ( (array) $groups as $group ) {
			$this->non_persistent_groups[ $group ] = true;
		}
	}

	/**
	 * Switch the per-blog key prefix when moving between sites on multisite.
	 *
	 * @param int $blog_id Blog ID to switch to.
	 * @return bool Always true.
	 */
	public function switch_to_blog( $blog_id ): bool {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

		return true;
	}

	/**
	 * Report whether the cache supports a given feature.
	 *
	 * @param string $feature Feature name.
	 * @return bool True if the feature is supported.
	 */
	public function supports( $feature ): bool {
		switch ( $feature ) {
			case 'add_multiple':
			case 'set_multiple':
			case 'get_multiple':
			case 'delete_multiple':
			case 'flush_runtime':
			case 'flush_group':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Return runtime statistics for this request.
	 *
	 * Unlike WordPress core's method, this returns the data instead of echoing HTML.
	 *
	 * @return array{hits:int,misses:int,ratio:float,connected:bool,groups:int}
	 */
	public function stats(): array {
		$total = $this->cache_hits + $this->cache_misses;

		return array(
			'hits'      => $this->cache_hits,
			'misses'    => $this->cache_misses,
			'ratio'     => $total > 0 ? round( ( $this->cache_hits / $total ) * 100, 1 ) : 0.0,
			'connected' => $this->is_connected(),
			'groups'    => count( $this->cache ),
		);
	}

	/**
	 * Load and normalise the connection configuration.
	 *
	 * Precedence per key: `WP_REDIS_*` constants, then the `zinn-cache-redis.php` config file, then
	 * built-in defaults.
	 *
	 * @return array{host:string,port:int,database:int,password:string,prefix:string,timeout:float}
	 */
	private function load_config(): array {
		$config = array(
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'database' => 0,
			'password' => '',
			'prefix'   => '',
			'timeout'  => 1.0,
		);

		$path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/zinn-cache-redis.php' : '';
		if ( '' !== $path && is_readable( $path ) ) {
			$loaded = include $path;
			if ( is_array( $loaded ) ) {
				$config = array_merge( $config, $loaded );
			}
		}

		if ( defined( 'WP_REDIS_HOST' ) ) {
			$config['host'] = WP_REDIS_HOST;
		}
		if ( defined( 'WP_REDIS_PORT' ) ) {
			$config['port'] = WP_REDIS_PORT;
		}
		if ( defined( 'WP_REDIS_DATABASE' ) ) {
			$config['database'] = WP_REDIS_DATABASE;
		}
		if ( defined( 'WP_REDIS_PASSWORD' ) ) {
			$config['password'] = WP_REDIS_PASSWORD;
		}
		if ( defined( 'WP_REDIS_PREFIX' ) ) {
			$config['prefix'] = WP_REDIS_PREFIX;
		}
		if ( defined( 'WP_REDIS_TIMEOUT' ) ) {
			$config['timeout'] = WP_REDIS_TIMEOUT;
		}

		return array(
			'host'     => (string) $config['host'],
			'port'     => (int) $config['port'],
			'database' => (int) $config['database'],
			'password' => (string) $config['password'],
			'prefix'   => (string) $config['prefix'],
			'timeout'  => (float) $config['timeout'],
		);
	}

	/**
	 * Derive a stable, per-install key namespace when none is configured.
	 *
	 * Combines the database name, table prefix, and install path so two WordPress
	 * installs sharing one Redis database never collide — and so flush() only ever
	 * clears this install's keys instead of the whole database.
	 *
	 * @return string
	 */
	private function derive_prefix(): string {
		global $table_prefix;

		$seed = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' )
			. '|' . ( isset( $table_prefix ) ? (string) $table_prefix : '' )
			. '|' . ( defined( 'ABSPATH' ) ? (string) ABSPATH : '' );

		return 'zc:' . substr( md5( $seed ), 0, 12 ) . ':';
	}

	/**
	 * Attempt to establish the Redis connection; on any failure remain in in-memory mode.
	 *
	 * @return void
	 */
	private function connect(): void {
		if ( ! class_exists( 'Redis' ) ) {
			return;
		}

		try {
			$redis     = new \Redis();
			$host      = $this->config['host'];
			$is_socket = str_starts_with( $host, '/' );
			$port      = $is_socket ? 0 : $this->config['port'];
			$connected = $redis->connect( $host, $port, $this->config['timeout'] );

			if ( true !== $connected ) {
				return;
			}

			if ( '' !== $this->config['password'] && true !== $redis->auth( $this->config['password'] ) ) {
				return;
			}

			if ( 0 !== $this->config['database'] ) {
				$redis->select( $this->config['database'] );
			}

			$this->redis     = $redis;
			$this->connected = true;
		} catch ( \Exception $e ) {
			$this->redis     = null;
			$this->connected = false;
		}
	}

	/**
	 * Whether a usable Redis connection is available.
	 *
	 * @return bool
	 */
	private function is_connected(): bool {
		return $this->connected && $this->redis instanceof \Redis;
	}

	/**
	 * Mark the connection dead so subsequent operations fall back to runtime memory.
	 *
	 * @return void
	 */
	private function handle_exception(): void {
		$this->connected = false;
		$this->redis     = null;
	}

	/**
	 * Write a value to Redis with the given expiry.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire Expiry in seconds; 0 means no expiry.
	 * @return void
	 */
	private function write_to_redis( $key, $data, string $group, int $expire ): void {
		$full       = $this->build_key( $key, $group );
		$serialized = $this->serialize_value( $data );

		if ( $expire > 0 ) {
			$this->redis->setex( $full, $expire, $serialized );
		} else {
			$this->redis->set( $full, $serialized );
		}
	}

	/**
	 * Delete every key matching a pattern using a non-blocking SCAN loop.
	 *
	 * @param string $pattern Redis key glob pattern.
	 * @return bool True on success, false if the connection failed mid-flush.
	 */
	private function scan_delete( string $pattern ): bool {
		if ( ! $this->is_connected() ) {
			return false;
		}

		// Safety net: never wipe an entire (possibly shared) Redis database. A
		// bare "*" or empty pattern would match every key, so refuse it — flush
		// scopes are always namespaced by the effective prefix (see constructor).
		$trimmed = trim( $pattern );
		if ( '' === $trimmed || '*' === $trimmed ) {
			return false;
		}

		try {
			$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
			$iterator = null;

			do {
				$keys = $this->redis->scan( $iterator, $pattern, 500 );
				if ( is_array( $keys ) && array() !== $keys ) {
					$this->redis->unlink( $keys );
				}
			} while ( false !== $keys && $iterator > 0 );

			return true;
		} catch ( \Exception $e ) {
			$this->handle_exception();
			return false;
		}
	}

	/**
	 * Determine whether a key exists in the runtime layer or in Redis.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group (already normalised).
	 * @return bool
	 */
	private function exists( $key, string $group ): bool {
		if ( isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			return true;
		}

		if ( $this->is_non_persistent_group( $group ) || ! $this->is_connected() ) {
			return false;
		}

		try {
			return (bool) $this->redis->exists( $this->build_key( $key, $group ) );
		} catch ( \Exception $e ) {
			$this->handle_exception();
			return false;
		}
	}

	/**
	 * Apply a signed delta to a numeric runtime value, clamping the result at zero.
	 *
	 * @param int|string $key   Cache key.
	 * @param int        $delta Signed amount to apply.
	 * @param string     $group Cache group (already normalised).
	 * @return int|false The new value, or false if the key does not exist.
	 */
	private function modify_runtime( $key, int $delta, string $group ) {
		if ( ! isset( $this->cache[ $group ] ) || ! array_key_exists( $key, $this->cache[ $group ] ) ) {
			return false;
		}

		$value = $this->cache[ $group ][ $key ];
		if ( ! is_numeric( $value ) ) {
			$value = 0;
		}

		$value = (int) $value + $delta;
		if ( 0 > $value ) {
			$value = 0;
		}

		$this->cache[ $group ][ $key ] = $value;

		return $value;
	}

	/**
	 * Build the fully-qualified, Redis-safe key for a group/key pair.
	 *
	 * Format: `{globalprefix}{salt}{siteprefix}{group}:{key}` with whitespace replaced by
	 * underscores. The site prefix is empty for global groups and on single-site installs.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return string
	 */
	private function build_key( $key, string $group ): string {
		$group = $this->normalize_group( $group );
		$site  = $this->is_global_group( $group ) ? '' : $this->blog_prefix;
		$full  = $this->global_prefix . $this->key_salt . $site . $group . ':' . (string) $key;

		return str_replace( array( ' ', "\t", "\r", "\n" ), '_', $full );
	}

	/**
	 * Coerce a group value to a non-empty group name.
	 *
	 * @param mixed $group Raw group value.
	 * @return string
	 */
	private function normalize_group( $group ): string {
		$group = (string) $group;

		return '' !== $group ? $group : 'default';
	}

	/**
	 * Whether a group is shared across all network sites.
	 *
	 * @param string $group Cache group.
	 * @return bool
	 */
	private function is_global_group( string $group ): bool {
		return isset( $this->global_groups[ $group ] );
	}

	/**
	 * Whether a group is kept in runtime memory only (never persisted).
	 *
	 * @param string $group Cache group.
	 * @return bool
	 */
	private function is_non_persistent_group( string $group ): bool {
		return isset( $this->non_persistent_groups[ $group ] );
	}

	/**
	 * Return a safe copy of a value so callers cannot mutate the cached instance by reference.
	 *
	 * @param mixed $value Cached value.
	 * @return mixed
	 */
	private function copy_value( $value ) {
		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Serialise a value for storage in Redis.
	 *
	 * Plain integers are stored as their raw decimal string so Redis INCRBY/DECRBY can operate on
	 * them atomically; everything else is serialised (igbinary when available, otherwise native).
	 *
	 * @param mixed $value Value to serialise.
	 * @return string
	 */
	private function serialize_value( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( $this->use_igbinary ) {
			return (string) igbinary_serialize( $value );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Serialising our own trusted cache payload for Redis; not user-facing output.
		return (string) serialize( $value );
	}

	/**
	 * Reverse {@see WP_Object_Cache::serialize_value()} for a value read from Redis.
	 *
	 * @param mixed $value Raw value from Redis.
	 * @return mixed
	 */
	private function unserialize_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( (string) (int) $value === $value ) {
			return (int) $value;
		}

		if ( $this->use_igbinary ) {
			return igbinary_unserialize( $value );
		}

		// The payload was serialized by this drop-in and read back from our own
		// namespaced Redis key — it is trusted data, never user input. Unrestricted
		// unserialize is the required contract of a WordPress object-cache drop-in
		// (WP legitimately caches serialized objects).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Trusted, drop-in-authored cache payload; not user input.
		return unserialize( $value ); // nosemgrep: php.lang.security.unserialize-use.unserialize-use -- Trusted, drop-in-authored cache payload; not user input.
	}
}

/*
 * A WordPress object-cache drop-in must, by contract, declare both the `WP_Object_Cache` class and
 * the global `wp_cache_*` functions in this single file. The sniffs that would object to that
 * (SeparateFunctionsFromOO / PrefixAllGlobals / FileName / GlobalVariablesOverride) are scoped out
 * for this path in phpcs.xml.dist.
 *
 * ⛔⛔ AND THAT SCOPING IS NOT ENOUGH, BECAUSE IT ONLY BINDS OUR OWN PHPCS RUN. The
 * wordpress.org directory's own tool — the Plugin Check plugin — carries its own ruleset and
 * never reads our `phpcs.xml.dist`, so it reported all twelve of these as
 * `PrefixAllGlobals.NonPrefixedFunctionFound`. The names are not ours to prefix: `wp_cache_get`
 * IS the WordPress cache API, and a drop-in that prefixed them would define nothing WordPress
 * calls and silently cache nothing at all. So the suppression is inline, where any reviewer
 * running that tool will read it, rather than only in a config file they will never open.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- These ARE the WordPress object-cache API function names; a drop-in that renamed them would be dead code. See the note above.

if ( ! function_exists( 'wp_cache_init' ) ) {
	/**
	 * Set up the global object cache instance.
	 *
	 * @return void
	 */
	function wp_cache_init() {
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * Add data to the cache if the key does not already exist.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default ''.
	 * @param int        $expire Optional. Expiry in seconds. Default 0.
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->add( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_add_multiple' ) ) {
	/**
	 * Add multiple values to the cache in one call.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param string                  $group  Optional. Cache group. Default ''.
	 * @param int                     $expire Optional. Expiry in seconds. Default 0.
	 * @return array<int|string,bool> Map of key => success flag.
	 */
	function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->add_multiple( $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_replace' ) ) {
	/**
	 * Replace data in the cache if the key already exists.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default ''.
	 * @param int        $expire Optional. Expiry in seconds. Default 0.
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * Store data in the cache.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Optional. Cache group. Default ''.
	 * @param int        $expire Optional. Expiry in seconds. Default 0.
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->set( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_set_multiple' ) ) {
	/**
	 * Store multiple values in the cache in one call.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param string                  $group  Optional. Cache group. Default ''.
	 * @param int                     $expire Optional. Expiry in seconds. Default 0.
	 * @return array<int|string,bool> Map of key => success flag.
	 */
	function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->set_multiple( $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * Retrieve a value from the cache.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Optional. Cache group. Default ''.
	 * @param bool       $force Optional. Bypass the runtime layer. Default false.
	 * @param bool       $found Optional. Set by reference to whether the key was found. Default null.
	 * @return mixed The cached value, or false when not found.
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_object_cache;

		return $wp_object_cache->get( $key, $group, $force, $found );
	}
}

if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
	/**
	 * Retrieve multiple values from the cache in one call.
	 *
	 * @param array<int,int|string> $keys  Keys to fetch.
	 * @param string                $group Optional. Cache group. Default ''.
	 * @param bool                  $force Optional. Bypass the runtime layer. Default false.
	 * @return array<int|string,mixed> Map of key => value (false when not found).
	 */
	function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
		global $wp_object_cache;

		return $wp_object_cache->get_multiple( $keys, $group, $force );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Delete a value from the cache.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Optional. Cache group. Default ''.
	 * @return bool True if removed, false otherwise.
	 */
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->delete( $key, $group );
	}
}

if ( ! function_exists( 'wp_cache_delete_multiple' ) ) {
	/**
	 * Delete multiple values from the cache in one call.
	 *
	 * @param array<int,int|string> $keys  Keys to delete.
	 * @param string                $group Optional. Cache group. Default ''.
	 * @return array<int|string,bool> Map of key => whether it was removed.
	 */
	function wp_cache_delete_multiple( $keys, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->delete_multiple( $keys, $group );
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	/**
	 * Increment a numeric cache value.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Optional. Amount to increment by. Default 1.
	 * @param string     $group  Optional. Cache group. Default ''.
	 * @return int|false The new value, or false on failure.
	 */
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->incr( $key, (int) $offset, $group );
	}
}

if ( ! function_exists( 'wp_cache_decr' ) ) {
	/**
	 * Decrement a numeric cache value.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Optional. Amount to decrement by. Default 1.
	 * @param string     $group  Optional. Cache group. Default ''.
	 * @return int|false The new value, or false on failure.
	 */
	function wp_cache_decr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->decr( $key, (int) $offset, $group );
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	/**
	 * Flush the entire cache.
	 *
	 * @return bool True on success.
	 */
	function wp_cache_flush() {
		global $wp_object_cache;

		return $wp_object_cache->flush();
	}
}

if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
	/**
	 * Clear only the in-request runtime cache.
	 *
	 * @return bool True on success.
	 */
	function wp_cache_flush_runtime() {
		global $wp_object_cache;

		return $wp_object_cache->flush_runtime();
	}
}

if ( ! function_exists( 'wp_cache_flush_group' ) ) {
	/**
	 * Remove all cached data for a single group.
	 *
	 * @param string $group Cache group to flush.
	 * @return bool True on success.
	 */
	function wp_cache_flush_group( $group ) {
		global $wp_object_cache;

		return $wp_object_cache->flush_group( $group );
	}
}

if ( ! function_exists( 'wp_cache_close' ) ) {
	/**
	 * Close the cache back end.
	 *
	 * @return bool True on success.
	 */
	function wp_cache_close() {
		global $wp_object_cache;

		return $wp_object_cache->close();
	}
}

if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
	/**
	 * Register groups shared across all network sites.
	 *
	 * @param array<int,string>|string $groups Group name(s).
	 * @return void
	 */
	function wp_cache_add_global_groups( $groups ) {
		global $wp_object_cache;

		$wp_object_cache->add_global_groups( $groups );
	}
}

if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	/**
	 * Register groups that must not be persisted.
	 *
	 * @param array<int,string>|string $groups Group name(s).
	 * @return void
	 */
	function wp_cache_add_non_persistent_groups( $groups ) {
		global $wp_object_cache;

		$wp_object_cache->add_non_persistent_groups( $groups );
	}
}

if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
	/**
	 * Switch the cache context to another site on multisite.
	 *
	 * @param int $blog_id Blog ID to switch to.
	 * @return bool True on success.
	 */
	function wp_cache_switch_to_blog( $blog_id ) {
		global $wp_object_cache;

		return $wp_object_cache->switch_to_blog( (int) $blog_id );
	}
}

if ( ! function_exists( 'wp_cache_reset' ) ) {
	/**
	 * Reset the cache (deprecated no-op kept for backwards compatibility).
	 *
	 * @deprecated Use wp_cache_flush() instead.
	 * @return bool Always false.
	 */
	function wp_cache_reset() {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_flush()' );
		}

		return false;
	}
}

if ( ! function_exists( 'wp_cache_supports' ) ) {
	/**
	 * Report whether the object cache supports a given feature.
	 *
	 * @param string $feature Feature name.
	 * @return bool True if supported.
	 */
	function wp_cache_supports( $feature ) {
		global $wp_object_cache;

		return $wp_object_cache->supports( $feature );
	}
}

$GLOBALS['wp_object_cache'] = new WP_Object_Cache();

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
