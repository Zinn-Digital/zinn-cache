<?php
/**
 * Plugin settings: storage, defaults, and normalisation.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes, and normalises the plugin's single options record.
 *
 * The pure helpers ({@see Settings::defaults()}, {@see Settings::normalize()},
 * {@see Settings::lines_to_list()}) hold the correctness-critical shaping and
 * clamping logic and are unit-tested; {@see Settings::get()} / {@see Settings::save()}
 * are the thin WordPress options-API wrappers used at runtime.
 */
final class Settings {

	/**
	 * Options key under which every setting is stored (one row).
	 */
	public const OPTION = 'zinn_cache_settings';

	/**
	 * Minimum acceptable full-page cache TTL, in seconds.
	 */
	public const TTL_MIN = 60;

	/**
	 * Maximum acceptable full-page cache TTL, in seconds (one year).
	 */
	public const TTL_MAX = 31536000;

	/**
	 * Default settings, used on fresh installs and to backfill missing keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'lscache_enabled'             => true,
			'lscache_ttl'                 => 604800,
			'object_cache_enabled'        => false,
			'auto_purge_enabled'          => true,
			'purge_on_upgrade'            => true,
			'exclude_uris'                => array(),
			'exclude_query_keys'          => array(),
			'exclude_cookies'             => array(),
			'redis_host'                  => '127.0.0.1',
			'redis_port'                  => 6379,
			'redis_database'              => 0,
			'redis_username'              => '',
			'redis_password'              => '',
			'redis_key_prefix'            => '',
			// ⭐ W41-Q. Every one of these is read by the code that does the work — see the
			// call sites named in each field's description on the settings screen. A stored
			// switch with no consumer is the placeholder §2.41 forbids.
			'browser_ttl'                 => 0,
			'cache_logged_out_only'       => true,
			'purge_on_comment'            => true,
			'ttl_overrides'               => array(),
			// AI agents (MCP): on by default for administrators (owner Q2, 2026-09-30).
			'mcp'                         => true,
			// Server caches in front of WordPress (1.11.0, s1014 ZCACHE). Consumers: Server\Server_Cache
			// and its drivers; Page_Cache::sync() keeps the disk cache out when one is there.
			'server_cache'                => 'auto',
			'server_cache_headers'        => true,
			'nginx_purge_method'          => 'http',
			'nginx_purge_endpoint'        => '',
			'nginx_http_verb'             => 'GET',
			'nginx_purge_address'         => '',
			'nginx_cache_path'            => '',
			'nginx_cache_levels'          => '1:2',
			'nginx_cache_key'             => '',
			'nginx_key_variants'          => array(),
			'nginx_spool_dir'             => '',
			'varnish_host'                => '127.0.0.1',
			'varnish_port'                => 6081,
			'varnish_method'              => 'PURGE',
			'apache_cache_root'           => '',
			'apache_dir_levels'           => 2,
			'apache_dir_length'           => 1,
			'cloudflare_enabled'          => false,
			'cloudflare_zone_id'          => '',
			'cloudflare_api_token'        => '',
			'cloudflare_purge_everything' => false,
			// The paced refresh (Refresh): pages per minute, and the load average that pauses it (0 = 1.5 × cores).
			'refresh_batch'               => 100,
			'refresh_load_limit'          => 0,
			// Object cache serializer for new writes (the drop-in reads both formats).
			'redis_serializer'            => 'auto',
			// Lighter pages (Frontend_Tweaks).
			'heartbeat_mode'              => 'default',
			'font_display_swap'           => false,
			'preconnect_origins'          => array(),
			'cdn_url'                     => '',
			'cdn_exclude'                 => array(),
		);
	}

	/**
	 * Normalise an arbitrary (already-unslashed) input array into clean settings.
	 *
	 * Every value is coerced to its expected type, clamped to a safe range, and
	 * missing keys are filled from {@see Settings::defaults()}. Pure: no I/O.
	 *
	 * @param array<string,mixed> $raw Raw input (e.g. a decoded form submission).
	 * @return array<string,mixed>
	 */
	public static function normalize( array $raw ): array {
		$defaults = self::defaults();

		return array(
			'lscache_enabled'             => self::to_bool( $raw['lscache_enabled'] ?? $defaults['lscache_enabled'] ),
			'lscache_ttl'                 => self::clamp_int( $raw['lscache_ttl'] ?? $defaults['lscache_ttl'], self::TTL_MIN, self::TTL_MAX, (int) $defaults['lscache_ttl'] ),
			'object_cache_enabled'        => self::to_bool( $raw['object_cache_enabled'] ?? $defaults['object_cache_enabled'] ),
			'auto_purge_enabled'          => self::to_bool( $raw['auto_purge_enabled'] ?? $defaults['auto_purge_enabled'] ),
			'purge_on_upgrade'            => self::to_bool( $raw['purge_on_upgrade'] ?? $defaults['purge_on_upgrade'] ),
			'exclude_uris'                => self::to_list( $raw['exclude_uris'] ?? array() ),
			'exclude_query_keys'          => self::to_list( $raw['exclude_query_keys'] ?? array() ),
			'exclude_cookies'             => self::to_list( $raw['exclude_cookies'] ?? array() ),
			'redis_host'                  => self::to_host( $raw['redis_host'] ?? $defaults['redis_host'] ),
			'redis_port'                  => self::clamp_int( $raw['redis_port'] ?? $defaults['redis_port'], 1, 65535, (int) $defaults['redis_port'] ),
			'redis_database'              => self::clamp_int( $raw['redis_database'] ?? $defaults['redis_database'], 0, 255, 0 ),
			'redis_username'              => is_scalar( $raw['redis_username'] ?? '' ) ? sanitize_text_field( (string) ( $raw['redis_username'] ?? '' ) ) : '',
			'redis_password'              => is_scalar( $raw['redis_password'] ?? '' ) ? (string) ( $raw['redis_password'] ?? '' ) : '',
			'redis_key_prefix'            => self::to_key_prefix( $raw['redis_key_prefix'] ?? '' ),
			'browser_ttl'                 => self::clamp_int( $raw['browser_ttl'] ?? 0, 0, 31536000, 0 ),
			'cache_logged_out_only'       => self::to_bool( $raw['cache_logged_out_only'] ?? $defaults['cache_logged_out_only'] ),
			'purge_on_comment'            => self::to_bool( $raw['purge_on_comment'] ?? $defaults['purge_on_comment'] ),
			'ttl_overrides'               => self::to_ttl_overrides( $raw['ttl_overrides'] ?? array() ),
			'mcp'                         => self::to_bool( $raw['mcp'] ?? $defaults['mcp'] ),
			'server_cache'                => self::to_choice( $raw['server_cache'] ?? 'auto', array( 'auto', 'none', 'nginx', 'varnish', 'apache' ), 'auto' ),
			'server_cache_headers'        => self::to_bool( $raw['server_cache_headers'] ?? $defaults['server_cache_headers'] ),
			'nginx_purge_method'          => self::to_choice( $raw['nginx_purge_method'] ?? 'http', array( 'http', 'files', 'spool' ), 'http' ),
			'nginx_purge_endpoint'        => self::to_endpoint( $raw['nginx_purge_endpoint'] ?? '' ),
			'nginx_http_verb'             => self::to_choice( strtoupper( self::scalar( $raw, 'nginx_http_verb' ) ), array( 'GET', 'PURGE' ), 'GET' ),
			'nginx_purge_address'         => self::to_address( $raw['nginx_purge_address'] ?? '' ),
			'nginx_cache_path'            => self::to_path( $raw['nginx_cache_path'] ?? '' ),
			'nginx_cache_levels'          => 1 === preg_match( '/^[12](:[12]){0,2}$/', self::scalar( $raw, 'nginx_cache_levels' ) ) ? self::scalar( $raw, 'nginx_cache_levels' ) : '1:2',
			'nginx_cache_key'             => is_scalar( $raw['nginx_cache_key'] ?? '' ) ? (string) preg_replace( '/[\r\n]/', '', trim( (string) ( $raw['nginx_cache_key'] ?? '' ) ) ) : '',
			'nginx_key_variants'          => self::to_list( $raw['nginx_key_variants'] ?? array() ),
			'nginx_spool_dir'             => self::to_path( $raw['nginx_spool_dir'] ?? '' ),
			'varnish_host'                => self::to_hostname( $raw['varnish_host'] ?? $defaults['varnish_host'] ),
			'varnish_port'                => self::clamp_int( $raw['varnish_port'] ?? $defaults['varnish_port'], 1, 65535, (int) $defaults['varnish_port'] ),
			'varnish_method'              => self::to_choice( strtoupper( self::scalar( $raw, 'varnish_method' ) ), array( 'PURGE', 'BAN' ), 'PURGE' ),
			'apache_cache_root'           => self::to_path( $raw['apache_cache_root'] ?? '' ),
			'apache_dir_levels'           => self::clamp_int( $raw['apache_dir_levels'] ?? 2, 0, 20, 2 ),
			'apache_dir_length'           => self::clamp_int( $raw['apache_dir_length'] ?? 1, 1, 20, 1 ),
			'cloudflare_enabled'          => self::to_bool( $raw['cloudflare_enabled'] ?? false ),
			'cloudflare_zone_id'          => 1 === preg_match( '/^[a-f0-9]{32}$/', strtolower( trim( self::scalar( $raw, 'cloudflare_zone_id' ) ) ) ) ? strtolower( trim( self::scalar( $raw, 'cloudflare_zone_id' ) ) ) : '',
			'cloudflare_api_token'        => is_scalar( $raw['cloudflare_api_token'] ?? '' ) ? (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $raw['cloudflare_api_token'] ?? '' ) ) : '',
			'cloudflare_purge_everything' => self::to_bool( $raw['cloudflare_purge_everything'] ?? false ),
			'refresh_batch'               => self::clamp_int( $raw['refresh_batch'] ?? 100, 10, 1000, 100 ),
			'refresh_load_limit'          => self::clamp_int( $raw['refresh_load_limit'] ?? 0, 0, 1000, 0 ),
			'redis_serializer'            => self::to_choice( $raw['redis_serializer'] ?? 'auto', array( 'auto', 'php', 'igbinary' ), 'auto' ),
			'heartbeat_mode'              => self::to_choice( $raw['heartbeat_mode'] ?? 'default', array( 'default', 'reduce', 'off_front' ), 'default' ),
			'font_display_swap'           => self::to_bool( $raw['font_display_swap'] ?? false ),
			'preconnect_origins'          => self::to_origins( $raw['preconnect_origins'] ?? array() ),
			'cdn_url'                     => self::to_cdn_url( $raw['cdn_url'] ?? '' ),
			'cdn_exclude'                 => self::to_list( $raw['cdn_exclude'] ?? array() ),
		);
	}

	/**
	 * A raw value as a string, or '' when it is missing or not a scalar.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 * @param string              $key Key.
	 * @return string
	 */
	private static function scalar( array $raw, string $key ): string {
		return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
	}

	/**
	 * One of a fixed set of values, or the fallback.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback.
	 * @return string
	 */
	private static function to_choice( $value, array $allowed, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * An absolute server path (a cache or spool folder), or empty. Relative paths, `..` segments
	 * and stream wrappers are refused: these settings name folders files are DELETED from.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_path( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value || '/' !== $value[0] || str_contains( $value, '..' ) || str_contains( $value, "\0" ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			return '';
		}
		$value = untrailingslashit( (string) preg_replace( '#/+#', '/', $value ) );
		// ⛔ Never a system root a careless entry could point at.
		return in_array( $value, array( '', '/', '/etc', '/usr', '/var', '/home', '/root', '/bin', '/boot', '/dev', '/proc', '/sys' ), true ) ? '' : $value;
	}

	/**
	 * A purge endpoint template: http(s) with the supported tokens, or empty for the default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_endpoint( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return 1 === preg_match( '#^(https?://|\{scheme\}://)[^\s]+$#i', $value ) ? $value : '';
	}

	/**
	 * Where to send purge requests: `http(s)://host[:port]`, or empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_address( $value ): string {
		$value = is_scalar( $value ) ? untrailingslashit( trim( (string) $value ) ) : '';
		return 1 === preg_match( '#^https?://[A-Za-z0-9.\-\[\]:]+$#', $value ) ? $value : '';
	}

	/**
	 * A hostname or IP for Varnish.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_hostname( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9.\-:\[\]]{1,253}$/', $value ) ? $value : '';
	}

	/**
	 * Origins to preconnect to: `https://host` only.
	 *
	 * @param mixed $value Raw list.
	 * @return string[]
	 */
	private static function to_origins( $value ): array {
		$out = array();
		foreach ( self::to_list( $value ) as $origin ) {
			$parts = wp_parse_url( $origin );
			if ( is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) ) {
				$out[] = 'https://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A CDN base URL (`https://cdn.example.com`), or empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_cdn_url( $value ): string {
		$value = is_scalar( $value ) ? untrailingslashit( trim( (string) $value ) ) : '';
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	/**
	 * Split a textarea/CSV/array input into a clean list of trimmed, unique lines.
	 *
	 * Accepts either an array or a newline-delimited string. Pure.
	 *
	 * @param mixed $value Raw list input.
	 * @return string[]
	 */
	public static function lines_to_list( $value ): array {
		return self::to_list( $value );
	}

	/**
	 * Normalise the per-post-type cache-lifetime rules.
	 *
	 * ⛔ A row whose post type is not registered on THIS site is dropped, not kept "just in
	 * case". A rule against a post type that no longer exists is a rule that silently
	 * matches nothing while reading on the screen as though it works — and a customer who
	 * deactivates a plugin should not be left with an invisible cache rule from it.
	 *
	 * @param mixed $raw Submitted rows.
	 * @return array<int, array{post_type:string,ttl:int}>
	 */
	private static function to_ttl_overrides( $raw ): array {
		$known = get_post_types( array(), 'names' );
		$out   = array();
		foreach ( (array) ( is_array( $raw ) ? $raw : array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
			if ( '' === $type || ! in_array( $type, (array) $known, true ) ) {
				continue;
			}
			$out[] = array(
				'post_type' => $type,
				'ttl'       => self::clamp_int( $row['ttl'] ?? self::TTL_MIN, self::TTL_MIN, self::TTL_MAX, self::TTL_MIN ),
			);
		}
		return $out;
	}

	/**
	 * Retrieve the full, defaults-backfilled settings from the database.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Retrieve a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed Value, or null when the key is unknown.
	 */
	public static function get_value( string $key ) {
		$all = self::get();

		return $all[ $key ] ?? null;
	}

	/**
	 * Persist a normalised settings array.
	 *
	 * @param array<string,mixed> $settings Settings to store (will be normalised).
	 * @return bool Whether the option was updated.
	 */
	public static function save( array $settings ): bool {
		return update_option( self::OPTION, self::normalize( $settings ) );
	}

	/**
	 * Coerce a value to a strict boolean, understanding common truthy tokens.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Cast to int and clamp into [min, max], falling back on non-numeric input.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound (inclusive).
	 * @param int   $max      Upper bound (inclusive).
	 * @param int   $fallback Value used when $value is not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Normalise a list input (array or newline string) to trimmed, unique values.
	 *
	 * @param mixed $value Raw list input.
	 * @return string[]
	 */
	private static function to_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
			if ( false === $value ) {
				$value = array();
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitise a Redis host (hostname, IP, or unix socket path).
	 *
	 * @param mixed $value Raw host.
	 * @return string
	 */
	private static function to_host( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '127.0.0.1';
		}
		$value = trim( (string) $value );

		return '' === $value ? '127.0.0.1' : $value;
	}

	/**
	 * Sanitise a Redis key prefix to a safe token.
	 *
	 * @param mixed $value Raw prefix.
	 * @return string
	 */
	private static function to_key_prefix( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return (string) preg_replace( '/[^A-Za-z0-9_\-:]/', '', (string) $value );
	}
}
