<?php
/**
 * WP-CLI commands for Zinn® Cache.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * `wp zinn-cache status`: what the object cache is really doing.
 *
 * Hosts that roll the object cache out across many sites need one command that says whether a
 * site is caching or has silently fallen back to memory. That is `fallback`, measured by a
 * write/read round trip through Redis rather than by the presence of a file.
 *
 * `wp zinn-cache purge all|url|post`: empty the page cache from the command line (1.9.10). Before
 * it, staff deleted stale cached pages by hand, because `do_action( 'zinn_cache_purge_all' )` had
 * no listener (W16, 2026-10-05).
 */
final class Cli {

	/**
	 * The page-cache controller the purge commands drive (set by {@see Plugin}).
	 *
	 * @var Lscache|null
	 */
	private static ?Lscache $lscache = null;

	/**
	 * The purge controller the purge commands drive (set by {@see Plugin}).
	 *
	 * @var Purge_Controller|null
	 */
	private static ?Purge_Controller $purger = null;

	/**
	 * Register the commands when running under WP-CLI.
	 *
	 * @param Lscache|null          $lscache The page-cache controller.
	 * @param Purge_Controller|null $purger  The purge controller.
	 * @return void
	 */
	public static function register( ?Lscache $lscache = null, ?Purge_Controller $purger = null ): void {
		self::$lscache = $lscache;
		self::$purger  = $purger;
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'zinn-cache status', array( self::class, 'status' ) );
			\WP_CLI::add_command( 'zinn-cache purge all', array( self::class, 'purge_all' ) );
			\WP_CLI::add_command( 'zinn-cache purge url', array( self::class, 'purge_url' ) );
			\WP_CLI::add_command( 'zinn-cache purge post', array( self::class, 'purge_post' ) );
		}
	}

	/**
	 * Purge every cached page.
	 *
	 * Empties the disk page cache now. On a LiteSpeed server the purge is sent with the next web
	 * request (a WP-CLI run has no response to carry it); the command makes that request itself.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache purge all
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments (unused).
	 * @return void
	 */
	public static function purge_all( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$before = Page_Cache::stats()['pages'];
		self::controller()->purge_all( 'manual' );
		self::report( $before, 'every page' );
	}

	/**
	 * Purge the cached pages at these URLs.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : Absolute URLs, or paths on this site (`/pricing/`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache purge url https://example.com/ /pricing/
	 *
	 * @param array<int,string>    $args       The URLs.
	 * @param array<string,string> $assoc_args Named arguments (unused).
	 * @return void
	 */
	public static function purge_url( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$urls = array();
		foreach ( $args as $arg ) {
			$url = self::absolute_url( (string) $arg );
			if ( null === $url ) {
				\WP_CLI::error( sprintf( 'Not a URL or a path on this site: %s', $arg ) );
			}
			$urls[] = $url;
		}
		if ( null === self::$purger ) {
			\WP_CLI::error( 'Zinn® Cache is not loaded on this site.' );
		}
		$before = Page_Cache::stats()['pages'];
		self::$purger->purge_plan( array( 'urls' => $urls ) );
		self::report( $before, implode( ', ', $urls ) );
	}

	/**
	 * Purge a post's pages and the listings it appears on (home page, archives, feeds).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Post IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache purge post 42
	 *
	 * @param array<int,string>    $args       The post IDs.
	 * @param array<string,string> $assoc_args Named arguments (unused).
	 * @return void
	 */
	public static function purge_post( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		if ( null === self::$purger ) {
			\WP_CLI::error( 'Zinn® Cache is not loaded on this site.' );
		}
		$before = Page_Cache::stats()['pages'];
		foreach ( $args as $arg ) {
			$id = ctype_digit( (string) $arg ) ? (int) $arg : 0;
			if ( $id <= 0 || ! self::$purger->purge_post( $id ) ) {
				\WP_CLI::error( sprintf( 'No post with ID %s.', $arg ) );
			}
		}
		self::report( $before, 'post ' . implode( ', ', $args ) );
	}

	/**
	 * An absolute URL for a CLI argument: kept when it is http(s), joined to the home URL when it
	 * is a path, null otherwise.
	 *
	 * @param string $arg The argument.
	 * @return string|null
	 */
	public static function absolute_url( string $arg ): ?string {
		$arg = trim( $arg );
		if ( '' === $arg ) {
			return null;
		}
		if ( str_starts_with( $arg, '/' ) && ! str_starts_with( $arg, '//' ) ) {
			return home_url( $arg );
		}
		$parts = wp_parse_url( $arg );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		return $arg;
	}

	/**
	 * The page-cache controller, built from the settings when the plugin did not hand one over.
	 *
	 * @return Lscache
	 */
	private static function controller(): Lscache {
		return self::$lscache ?? new Lscache( Settings::get() );
	}

	/**
	 * Say what the purge did: disk pages removed, and a deferred LiteSpeed purge sent or pending.
	 *
	 * @param int    $before Disk pages before the purge.
	 * @param string $what   What was purged, for the message.
	 * @return void
	 */
	private static function report( int $before, string $what ): void {
		$after = Page_Cache::stats()['pages'];
		\WP_CLI::log( sprintf( 'Disk page cache: %d page(s) removed, %d left.', max( 0, $before - $after ), $after ) );
		if ( is_file( Lscache::pending_file() ) ) {
			// A request of our own carries the deferred purge header to the LiteSpeed server now.
			wp_remote_get( admin_url( 'admin-ajax.php' ), array( 'timeout' => 10 ) );
			\WP_CLI::log(
				is_file( Lscache::pending_file() )
					? 'LiteSpeed server cache: purge queued; it is sent with the next uncached request.'
					: 'LiteSpeed server cache: purge sent.'
			);
		}
		\WP_CLI::success( sprintf( 'Purged %s.', $what ) );
	}

	/**
	 * Report the object cache's state.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function status( array $args, array $assoc_args ): void {
		unset( $args );
		$report = ( new Object_Cache( Settings::get() ) )->status_report();
		// Appended, never interleaved: hosts alert on the object-cache fields above by name (V1).
		// `disk` is decidable from the CLI; `litespeed` is not (the server is known only to a web
		// request), so an enabled cache without the disk drop-in reports `server`.
		$pages                      = Page_Cache::stats();
		$report['page_cache']       = empty( Settings::get()['lscache_enabled'] ) ? 'off' : ( Page_Cache::is_ready() ? 'disk' : 'server' );
		$report['page_cache_pages'] = $pages['pages'];
		// false when the build lost vendor/freemius and the plugin is running without it (D28308).
		$report['freemius'] = ! defined( 'ZINN_CACHE_FREEMIUS_MISSING' );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report ) );
			return;
		}

		$rows = array();
		foreach ( $report as $field => $value ) {
			$rows[] = array(
				'field' => $field,
				'value' => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( null === $value ? '' : (string) $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
