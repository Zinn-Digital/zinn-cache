<?php
/**
 * Cache-exclusion rules and matching.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a request must bypass the full-page cache.
 *
 * This class is deliberately free of any WordPress dependency: it takes plain
 * request data (path, cookie names, query keys) and a rule set, and returns a
 * boolean. That keeps the correctness-critical "never cache a logged-in / cart /
 * checkout / session page" logic fully unit-testable and side-effect free. The
 * WordPress integration layer gathers the request data and applies filters.
 */
final class Exclusions {

	/**
	 * URL path prefixes that must never be full-page cached.
	 *
	 * Covers admin, auth, REST/AJAX, WooCommerce & Easy Digital Downloads
	 * stateful endpoints, and password-protected/preview flows.
	 *
	 * @return string[]
	 */
	public static function default_uri_prefixes(): array {
		return array(
			'/wp-admin/',
			'/wp-login.php',
			'/wp-cron.php',
			'/xmlrpc.php',
			'/wp-json/',
			'/wc-api/',
			'/cart/',
			'/checkout/',
			'/my-account/',
			'/edd-api/',
		);
	}

	/**
	 * Substrings that, anywhere in the path, force a bypass.
	 *
	 * @return string[]
	 */
	public static function default_uri_contains(): array {
		return array(
			'/wp-admin',
		);
	}

	/**
	 * Query-string keys whose presence forces a bypass.
	 *
	 * Dynamic, per-request or preview/nonce-bearing requests are never cached.
	 *
	 * @return string[]
	 */
	public static function default_query_keys(): array {
		return array(
			'add-to-cart',
			'remove_item',
			'wc-ajax',
			'preview',
			'preview_id',
			'preview_nonce',
			's',
			'nocache',
			'customize_changeset_uuid',
			'unapproved',
		);
	}

	/**
	 * Cookie name prefixes that indicate a logged-in or stateful session.
	 *
	 * A request carrying any of these must never be served (or stored) as a
	 * shared, anonymous full-page cache entry.
	 *
	 * @return string[]
	 */
	public static function default_cookie_prefixes(): array {
		return array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'wp_woocommerce_session_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'edd_items_in_cart',
			'edd_cart_messages',
			'wordpress_sec_',
		);
	}

	/**
	 * The full default rule set, seeded with WP + WooCommerce + EDD safe defaults.
	 *
	 * @return array{uri_prefixes:string[],uri_contains:string[],query_keys:string[],cookie_prefixes:string[]}
	 */
	public static function default_rules(): array {
		return array(
			'uri_prefixes'    => self::default_uri_prefixes(),
			'uri_contains'    => self::default_uri_contains(),
			'query_keys'      => self::default_query_keys(),
			'cookie_prefixes' => self::default_cookie_prefixes(),
		);
	}

	/**
	 * Merge the seeded defaults with site-specific custom entries.
	 *
	 * @param string[] $custom_uris     Extra path prefixes to exclude.
	 * @param string[] $custom_query    Extra query keys to exclude.
	 * @param string[] $custom_cookies  Extra cookie-name prefixes to exclude.
	 * @return array{uri_prefixes:string[],uri_contains:string[],query_keys:string[],cookie_prefixes:string[]}
	 */
	public static function build_rules( array $custom_uris = array(), array $custom_query = array(), array $custom_cookies = array() ): array {
		$rules = self::default_rules();

		$rules['uri_prefixes']    = array_values( array_unique( array_merge( $rules['uri_prefixes'], $custom_uris ) ) );
		$rules['query_keys']      = array_values( array_unique( array_merge( $rules['query_keys'], $custom_query ) ) );
		$rules['cookie_prefixes'] = array_values( array_unique( array_merge( $rules['cookie_prefixes'], $custom_cookies ) ) );

		return $rules;
	}

	/**
	 * Determine whether a request must bypass the cache.
	 *
	 * @param string                                                                                              $request_uri  Raw request URI, e.g. `/checkout/?step=2`.
	 * @param string[]                                                                                            $cookie_names Names of cookies present on the request.
	 * @param string[]                                                                                            $query_keys   Query-string keys present on the request.
	 * @param array{uri_prefixes?:string[],uri_contains?:string[],query_keys?:string[],cookie_prefixes?:string[]} $rules Rule set.
	 * @return bool True when the request must NOT be cached.
	 */
	public static function is_excluded( string $request_uri, array $cookie_names, array $query_keys, array $rules ): bool {
		$path = self::path_from_uri( $request_uri );

		foreach ( ( $rules['uri_prefixes'] ?? array() ) as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		foreach ( ( $rules['uri_contains'] ?? array() ) as $needle ) {
			if ( '' !== $needle && str_contains( $path, $needle ) ) {
				return true;
			}
		}

		$excluded_query = $rules['query_keys'] ?? array();
		foreach ( $query_keys as $key ) {
			if ( in_array( $key, $excluded_query, true ) ) {
				return true;
			}
		}

		return self::has_stateful_cookie( $cookie_names, $rules['cookie_prefixes'] ?? array() );
	}

	/**
	 * Whether any present cookie matches a stateful-session prefix.
	 *
	 * @param string[] $cookie_names    Names of cookies present on the request.
	 * @param string[] $cookie_prefixes Prefixes that indicate a session.
	 * @return bool
	 */
	public static function has_stateful_cookie( array $cookie_names, array $cookie_prefixes ): bool {
		foreach ( $cookie_names as $name ) {
			foreach ( $cookie_prefixes as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract the path portion (before any query string) from a request URI.
	 *
	 * @param string $request_uri Raw request URI.
	 * @return string Path portion, always beginning with `/`.
	 */
	private static function path_from_uri( string $request_uri ): string {
		$path = $request_uri;

		$query_pos = strpos( $path, '?' );
		if ( false !== $query_pos ) {
			$path = substr( $path, 0, $query_pos );
		}

		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return $path;
	}
}
