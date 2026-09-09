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
			'lscache_enabled'       => true,
			'lscache_ttl'           => 604800,
			'object_cache_enabled'  => false,
			'auto_purge_enabled'    => true,
			'purge_on_upgrade'      => true,
			'exclude_uris'          => array(),
			'exclude_query_keys'    => array(),
			'exclude_cookies'       => array(),
			'redis_host'            => '127.0.0.1',
			'redis_port'            => 6379,
			'redis_database'        => 0,
			'redis_password'        => '',
			'redis_key_prefix'      => '',
			// ⭐ W41-Q. Every one of these is read by the code that does the work — see the
			// call sites named in each field's description on the settings screen. A stored
			// switch with no consumer is the placeholder §2.41 forbids.
			'browser_ttl'           => 0,
			'cache_logged_out_only' => true,
			'purge_on_comment'      => true,
			'ttl_overrides'         => array(),
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
			'lscache_enabled'       => self::to_bool( $raw['lscache_enabled'] ?? $defaults['lscache_enabled'] ),
			'lscache_ttl'           => self::clamp_int( $raw['lscache_ttl'] ?? $defaults['lscache_ttl'], self::TTL_MIN, self::TTL_MAX, (int) $defaults['lscache_ttl'] ),
			'object_cache_enabled'  => self::to_bool( $raw['object_cache_enabled'] ?? $defaults['object_cache_enabled'] ),
			'auto_purge_enabled'    => self::to_bool( $raw['auto_purge_enabled'] ?? $defaults['auto_purge_enabled'] ),
			'purge_on_upgrade'      => self::to_bool( $raw['purge_on_upgrade'] ?? $defaults['purge_on_upgrade'] ),
			'exclude_uris'          => self::to_list( $raw['exclude_uris'] ?? array() ),
			'exclude_query_keys'    => self::to_list( $raw['exclude_query_keys'] ?? array() ),
			'exclude_cookies'       => self::to_list( $raw['exclude_cookies'] ?? array() ),
			'redis_host'            => self::to_host( $raw['redis_host'] ?? $defaults['redis_host'] ),
			'redis_port'            => self::clamp_int( $raw['redis_port'] ?? $defaults['redis_port'], 1, 65535, (int) $defaults['redis_port'] ),
			'redis_database'        => self::clamp_int( $raw['redis_database'] ?? $defaults['redis_database'], 0, 255, 0 ),
			'redis_password'        => is_scalar( $raw['redis_password'] ?? '' ) ? (string) ( $raw['redis_password'] ?? '' ) : '',
			'redis_key_prefix'      => self::to_key_prefix( $raw['redis_key_prefix'] ?? '' ),
			'browser_ttl'           => self::clamp_int( $raw['browser_ttl'] ?? 0, 0, 31536000, 0 ),
			'cache_logged_out_only' => self::to_bool( $raw['cache_logged_out_only'] ?? $defaults['cache_logged_out_only'] ),
			'purge_on_comment'      => self::to_bool( $raw['purge_on_comment'] ?? $defaults['purge_on_comment'] ),
			'ttl_overrides'         => self::to_ttl_overrides( $raw['ttl_overrides'] ?? array() ),
		);
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
