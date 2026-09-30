<?php
/**
 * Zinn Cache advanced-cache drop-in
 *
 * Serves a page stored by Zinn® Cache before WordPress loads. Installed into wp-content by the
 * plugin when the page cache is on and the server is not LiteSpeed; removed when it is turned off.
 *
 * ⛔ Every failure here must FALL THROUGH to WordPress, never stop the request: a missing config,
 * a missing plugin, an unreadable file or an expired page all mean "let WordPress render it".
 *
 * @package Zinn\Cache
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file runs before WordPress loads: none of its sanitising or filesystem APIs exist yet, and every value read here is only compared or hashed, never output.

/**
 * Serve the stored page for this request, if there is one. Returns when there is not.
 *
 * @return void
 */
function zinn_cache_advanced_cache_serve() {
	if ( PHP_SAPI === 'cli' || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}
	$root   = WP_CONTENT_DIR . '/cache/zinn-cache';
	$config = is_file( $root . '/config.php' ) ? include $root . '/config.php' : null;
	if ( ! is_array( $config ) || empty( $config['enabled'] ) || empty( $config['exclusions_file'] ) || ! is_file( $config['exclusions_file'] ) ) {
		return;
	}
	require_once $config['exclusions_file'];
	if ( ! class_exists( '\\Zinn\\Cache\\Exclusions', false ) ) {
		return;
	}

	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$parts = explode( '?', $uri, 2 );
	$path  = '' === $parts[0] ? '/' : $parts[0];
	$keys  = array();
	if ( isset( $parts[1] ) && '' !== $parts[1] ) {
		parse_str( $parts[1], $query );
		$keys = array_map( 'strval', array_keys( $query ) );
		foreach ( $keys as $key ) {
			if ( ! in_array( $key, (array) $config['ignored'], true ) ) {
				return;
			}
		}
	}
	$cookies = array_map( 'strval', array_keys( $_COOKIE ) );
	foreach ( $cookies as $cookie ) {
		// ⛔ Never serve a stored page to a signed-in visitor, whatever the rules say.
		if ( 0 === strpos( $cookie, 'wordpress_logged_in_' ) || 0 === strpos( $cookie, 'wp-postpass_' ) ) {
			return;
		}
	}
	if ( \Zinn\Cache\Exclusions::is_excluded( $uri, $cookies, $keys, (array) $config['rules'] ) ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	$host = (string) preg_replace( '/[^a-z0-9.-]/', '', (string) preg_replace( '/:\d+$/', '', $host ) );
	// ⛔ The host names ONE directory under pages/, so it must be a name and nothing else. The
	// filter above already drops every separator; what it leaves possible is a Host header of
	// only dots (`.` or `..`), which is a directory REFERENCE — `pages/../<key>` — not a name.
	// No stored page lives there, so it served nothing, but a path built from a request header
	// is refused on its shape rather than on what happens to be on disk today. `basename()`
	// states the same "one segment" guarantee in the form static analysis can follow.
	$host = basename( $host );
	if ( '' === trim( $host, '.' ) ) {
		return;
	}
	// The request path reaches the filename only as `md5( $path )`. The three `nosemgrep` marks
	// below rest on one fact: md5() returns exactly 32 characters of [0-9a-f], so no request
	// can put a separator or a dot into the key (the taint rule cannot know a hash's alphabet).
	// Re-check if the key ever becomes anything but a hash of the path — the raw path, a slug,
	// or a query-string part would make the finding true.
	$base = $root . '/pages/' . $host . '/' . md5( $path );
	if ( ! is_file( $base . '.json' ) || ! is_file( $base . '.html' ) ) { // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- md5() key, see above.
		return;
	}
	$meta = json_decode( (string) file_get_contents( $base . '.json' ), true ); // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- md5() key, see above.
	if ( ! is_array( $meta ) || (int) ( $meta['expires'] ?? 0 ) <= time() || (string) ( $meta['url'] ?? '' ) !== $path ) {
		return;
	}
	if ( headers_sent() ) {
		return;
	}
	header( 'Content-Type: ' . ( isset( $meta['type'] ) && '' !== $meta['type'] ? $meta['type'] : 'text/html; charset=UTF-8' ) );
	if ( isset( $meta['control'] ) && '' !== $meta['control'] ) {
		header( 'Cache-Control: ' . $meta['control'] );
	}
	header( 'X-Zinn-Cache: HIT' );
	header( 'Age: ' . max( 0, time() - (int) ( $meta['created'] ?? time() ) ) );
	if ( 'HEAD' !== $method ) {
		readfile( $base . '.html' ); // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- md5() key, see above.
	}
	exit;
}

zinn_cache_advanced_cache_serve();
