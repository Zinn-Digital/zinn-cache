<?php
/**
 * The standard page cache, for servers that are not LiteSpeed.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Stores whole pages as static files and lets `advanced-cache.php` serve them before WordPress loads.
 *
 * ⚖️ LOCKED PLAN §1 (owner, 2026-09-28): Zinn® Cache is THE cache plugin for every site anywhere,
 * so on a server with no LiteSpeed cache it must still cache pages. On a LiteSpeed server the
 * server's own cache is better (it is in front of PHP entirely), so this engine is used ONLY when
 * the request is not served by LiteSpeed and no cache-engine plugin owns page caching.
 *
 * Layout under `wp-content/cache/zinn-cache/`:
 *
 *     pages/<host>/<md5(path)>.html   the page body
 *     pages/<host>/<md5(path)>.json   {url, created, expires, tags, type, control}
 *     tags/<host>/<md5(tag)>.lst      one page key per line: which pages carry this tag
 *     config.php                      the rules the drop-in needs (exclusions, TTL, enabled)
 *
 * ⛔ Only requests with no query string (after tracking keys are dropped) are stored. A cache
 * keyed on arbitrary query strings is a disk that any visitor can fill.
 *
 * ⛔ Every write is a temporary file and a rename in the same directory, so the drop-in reads
 * either the old page or the new one, never half of one.
 */
final class Page_Cache {

	/**
	 * The first line of our `advanced-cache.php`, used to recognise our own drop-in.
	 */
	public const DROPIN_MARKER = 'Zinn Cache advanced-cache drop-in';

	/**
	 * The comment ending the `wp-config.php` line we add, so we remove only our own line.
	 */
	public const CONFIG_MARKER = '// Zinn Cache: page cache (removed when the page cache is turned off).';

	/**
	 * Tracking query keys that never change a page, so a URL carrying only these is still cacheable.
	 */
	public const IGNORED_QUERY_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', '_ga', 'mc_cid', 'mc_eid' );

	/**
	 * The cache root.
	 *
	 * @return string
	 */
	public static function root(): string {
		return WP_CONTENT_DIR . '/cache/zinn-cache';
	}

	/**
	 * The host part of the cache path, reduced to characters a directory name can safely carry.
	 *
	 * @param string $host A Host header or a URL host.
	 * @return string Empty when nothing usable is left.
	 */
	public static function host_dir( string $host ): string {
		$host = strtolower( $host );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		return (string) preg_replace( '/[^a-z0-9.-]/', '', $host );
	}

	/**
	 * The storage key of a request path.
	 *
	 * @param string $path The URL path, beginning with `/`.
	 * @return string
	 */
	public static function page_key( string $path ): string {
		return md5( '' === $path ? '/' : $path );
	}

	/**
	 * Whether a query string is empty once tracking keys are ignored.
	 *
	 * @param array<int,string> $keys The query keys present.
	 * @return bool
	 */
	public static function query_is_ignorable( array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! in_array( (string) $key, self::IGNORED_QUERY_KEYS, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Store a page.
	 *
	 * @param string   $host    Request host.
	 * @param string   $path    Request path.
	 * @param string   $body    The HTML.
	 * @param int      $ttl     Seconds the page may be served for.
	 * @param string[] $tags    The page's cache tags.
	 * @param string   $type    The Content-Type header value.
	 * @param string   $control The Cache-Control header the browser should get.
	 * @return bool
	 */
	public static function store( string $host, string $path, string $body, int $ttl, array $tags, string $type, string $control ): bool {
		$host_dir = self::host_dir( $host );
		if ( '' === $host_dir || $ttl <= 0 || '' === $body ) {
			return false;
		}
		$dir = self::root() . '/pages/' . $host_dir;
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$key  = self::page_key( $path );
		$now  = time();
		$meta = array(
			'url'     => $path,
			'created' => $now,
			'expires' => $now + $ttl,
			'tags'    => array_values( $tags ),
			'type'    => $type,
			'control' => $control,
		);
		if ( ! self::write_atomic( $dir . '/' . $key . '.html', $body ) || ! self::write_atomic( $dir . '/' . $key . '.json', (string) wp_json_encode( $meta ) ) ) {
			return false;
		}
		$tag_dir = self::root() . '/tags/' . $host_dir;
		if ( array() !== $tags && wp_mkdir_p( $tag_dir ) ) {
			foreach ( $tags as $tag ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- An append of one line to an index; WP_Filesystem has no append and may be FTP-backed.
				file_put_contents( $tag_dir . '/' . md5( (string) $tag ) . '.lst', $key . "\n", FILE_APPEND | LOCK_EX );
			}
		}
		return true;
	}

	/**
	 * Delete every stored page on every host.
	 *
	 * @return int Pages deleted.
	 */
	public static function purge_all(): int {
		$count = 0;
		foreach ( array( 'pages', 'tags' ) as $sub ) {
			$count += self::delete_tree( self::root() . '/' . $sub, 'pages' === $sub );
		}
		return $count;
	}

	/**
	 * Delete the pages at these URLs.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return int Pages deleted.
	 */
	public static function purge_urls( array $urls ): int {
		$count = 0;
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( (string) $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$count += self::delete_page( self::host_dir( (string) $parts['host'] ), self::page_key( (string) ( $parts['path'] ?? '/' ) ) );
		}
		return $count;
	}

	/**
	 * Delete every page carrying any of these tags, on every host.
	 *
	 * @param string[] $tags Cache tags.
	 * @return int Pages deleted.
	 */
	public static function purge_tags( array $tags ): int {
		$count = 0;
		$hosts = glob( self::root() . '/tags/*', GLOB_ONLYDIR );
		foreach ( is_array( $hosts ) ? $hosts : array() as $tag_dir ) {
			$host_dir = basename( $tag_dir );
			foreach ( $tags as $tag ) {
				$index = $tag_dir . '/' . md5( (string) $tag ) . '.lst';
				if ( ! is_file( $index ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local index file.
				$keys = array_unique( array_filter( explode( "\n", (string) file_get_contents( $index ) ) ) );
				wp_delete_file( $index );
				foreach ( $keys as $key ) {
					if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
						$count += self::delete_page( $host_dir, $key );
					}
				}
			}
		}
		return $count;
	}

	/**
	 * How many pages are stored, and their size in bytes.
	 *
	 * @return array{pages:int,bytes:int}
	 */
	public static function stats(): array {
		$pages = 0;
		$bytes = 0;
		$files = glob( self::root() . '/pages/*/*.html' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			++$pages;
			$bytes += (int) filesize( $file );
		}
		return array(
			'pages' => $pages,
			'bytes' => $bytes,
		);
	}

	/**
	 * Write the rules the drop-in reads, from the plugin settings.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 * @return bool
	 */
	public static function write_config( array $settings ): bool {
		if ( ! wp_mkdir_p( self::root() ) ) {
			return false;
		}
		$rules  = Exclusions::build_rules(
			array_map( 'strval', (array) ( $settings['exclude_uris'] ?? array() ) ),
			array_map( 'strval', (array) ( $settings['exclude_query_keys'] ?? array() ) ),
			array_map( 'strval', (array) ( $settings['exclude_cookies'] ?? array() ) )
		);
		$config = array(
			'enabled'         => ! empty( $settings['lscache_enabled'] ),
			'rules'           => $rules,
			'ignored'         => self::IGNORED_QUERY_KEYS,
			'exclusions_file' => ZINN_CACHE_DIR . 'includes/class-exclusions.php',
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export() writes a PHP array literal the drop-in can include without a JSON parse on every request.
		$php = "<?php\n// Written by Zinn® Cache. Edit the plugin settings, never this file.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $config, true ) . ";\n";
		return self::write_atomic( self::root() . '/config.php', $php );
	}

	/**
	 * The drop-in's path in wp-content.
	 *
	 * @return string
	 */
	public static function dropin_target(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * The bundled drop-in.
	 *
	 * @return string
	 */
	public static function dropin_source(): string {
		return ZINN_CACHE_DIR . 'dropins/advanced-cache.php';
	}

	/**
	 * Whether a file is our drop-in (read from its first 512 bytes).
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_our_dropin( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A 512-byte header read of a local file.
		$head = (string) file_get_contents( $path, false, null, 0, 512 );
		return str_contains( $head, self::DROPIN_MARKER );
	}

	/**
	 * Whether a plugin is active right now, read from the database rather than from the
	 * `active_plugins` this request loaded when it started. Zinn® Cache Pro asks the same
	 * question before it writes its extension spec.
	 *
	 * @param string $file The plugin's main file; this plugin's when empty.
	 * @return bool
	 */
	public static function still_active( string $file = '' ): bool {
		$file = '' !== $file ? $file : ( defined( 'ZINN_CACHE_FILE' ) ? (string) ZINN_CACHE_FILE : '' );
		if ( '' === $file || ! function_exists( 'plugin_basename' ) ) {
			return true;
		}
		global $wpdb;
		$basename = plugin_basename( $file );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cached copy is exactly what may be stale here.
		$site = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) ) );
		if ( is_array( $site ) && in_array( $basename, $site, true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins' );
			return is_array( $network ) && isset( $network[ $basename ] );
		}
		return false;
	}

	/**
	 * Make the disk cache ready: config, drop-in and `WP_CACHE`.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 * @return true|WP_Error
	 */
	public static function install( array $settings ) {
		// ⛔ A request that started while this plugin was active can reach here after it was
		// deactivated (a loopback begun during the deactivation) and would put back the
		// WP_CACHE line and the drop-in that deactivation had just removed (D28306). So the
		// plugin's state is read from the database, not from this request's snapshot of it.
		if ( ! self::still_active() ) {
			return true;
		}
		$target = self::dropin_target();
		if ( is_file( $target ) && ! self::is_our_dropin( $target ) ) {
			return new WP_Error( 'zinn_cache_foreign_advanced_cache', __( 'Another page-cache plugin has installed wp-content/advanced-cache.php. Remove that plugin first, or keep using its page cache.', 'zinn-cache' ) );
		}
		if ( ! self::write_config( $settings ) ) {
			return new WP_Error( 'zinn_cache_cache_dir', __( 'The folder wp-content/cache/zinn-cache could not be written, so pages cannot be cached.', 'zinn-cache' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A bundled local file.
		$source = (string) file_get_contents( self::dropin_source() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local drop-in.
		$current = is_file( $target ) ? (string) file_get_contents( $target ) : null;
		if ( '' === $source || ( $current !== $source && ! self::write_atomic( $target, $source ) ) ) {
			return new WP_Error( 'zinn_cache_advanced_cache_write', __( 'wp-content/advanced-cache.php could not be written, so pages cannot be cached.', 'zinn-cache' ) );
		}
		$config = Wp_Config::ensure_wp_cache();
		return is_wp_error( $config ) ? $config : true;
	}

	/**
	 * Undo {@see install()}: the drop-in (only ours), our `WP_CACHE` line and every stored page.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		if ( self::is_our_dropin( self::dropin_target() ) ) {
			wp_delete_file( self::dropin_target() );
		}
		Wp_Config::remove_wp_cache();
		self::purge_all();
		wp_delete_file( self::root() . '/config.php' );
	}

	/**
	 * Bring the disk cache in line with the settings and the server: installed when it is the
	 * engine that should serve pages, removed (with every stored page) when it is not.
	 *
	 * ⛔ Never from WP-CLI or cron. Whether the server is LiteSpeed is read from the web request's
	 * `SERVER_SOFTWARE`, which a CLI process does not have, so a `wp plugin activate` on a
	 * LiteSpeed server would otherwise install a second page cache in front of the real one.
	 *
	 * @param array<string,mixed> $settings  Normalised settings.
	 * @param bool                $server_or_plugin Whether LiteSpeed (the server) or a cache-engine plugin owns page caching.
	 * @return true|WP_Error
	 */
	public static function sync( array $settings, bool $server_or_plugin ) {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		if ( ! empty( $settings['lscache_enabled'] ) && ! $server_or_plugin ) {
			return self::is_ready() && self::config_matches( $settings ) ? true : self::install( $settings );
		}
		if ( self::is_our_dropin( self::dropin_target() ) ) {
			self::uninstall();
		}
		return true;
	}

	/**
	 * Whether the stored config already says what these settings say.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 * @return bool
	 */
	private static function config_matches( array $settings ): bool {
		$config = include self::root() . '/config.php';
		return is_array( $config ) && ! empty( $config['enabled'] ) === ! empty( $settings['lscache_enabled'] )
			&& ( $config['exclusions_file'] ?? '' ) === ZINN_CACHE_DIR . 'includes/class-exclusions.php';
	}

	/**
	 * Whether a stored page may be served for this request right now (the drop-in's test, exposed for status).
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE && self::is_our_dropin( self::dropin_target() ) && is_file( self::root() . '/config.php' );
	}

	/**
	 * Write a file through a temporary file and a rename.
	 *
	 * @param string $path     Destination.
	 * @param string $contents Contents.
	 * @return bool
	 */
	private static function write_atomic( string $path, string $contents ): bool {
		$temp = $path . '.' . wp_generate_password( 8, false, false ) . '.tmp';
		// phpcs:disable WordPress.WP.AlternativeFunctions -- An atomic replace needs file_put_contents()+rename(); WP_Filesystem has no rename and may be FTP-backed, where a live request could read a half-written file.
		if ( false === file_put_contents( $temp, $contents, LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $temp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported through the return value.
			wp_delete_file( $temp );
			return false;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( function_exists( 'opcache_invalidate' ) && str_ends_with( $path, '.php' ) ) {
			opcache_invalidate( $path, true );
		}
		return true;
	}

	/**
	 * Delete one stored page.
	 *
	 * @param string $host_dir Host directory name.
	 * @param string $key      Page key.
	 * @return int 1 when a page was deleted.
	 */
	private static function delete_page( string $host_dir, string $key ): int {
		if ( '' === $host_dir ) {
			return 0;
		}
		$base    = self::root() . '/pages/' . $host_dir . '/' . $key;
		$existed = is_file( $base . '.html' ) ? 1 : 0;
		wp_delete_file( $base . '.html' );
		wp_delete_file( $base . '.json' );
		return $existed;
	}

	/**
	 * Delete a two-level cache tree (`<sub>/<host>/<file>`), counting `.html` files when asked.
	 *
	 * @param string $dir         The `pages` or `tags` directory.
	 * @param bool   $count_pages Whether to count `.html` files.
	 * @return int
	 */
	private static function delete_tree( string $dir, bool $count_pages ): int {
		$count = 0;
		$files = glob( $dir . '/*/*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( $count_pages && str_ends_with( $file, '.html' ) ) {
				++$count;
			}
			wp_delete_file( $file );
		}
		return $count;
	}
}
