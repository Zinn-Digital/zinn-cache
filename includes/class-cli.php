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
			\WP_CLI::add_command( 'zinn-cache server detect', array( self::class, 'server_detect' ) );
			\WP_CLI::add_command( 'zinn-cache server drain', array( self::class, 'server_drain' ) );
			\WP_CLI::add_command( 'zinn-cache refresh', array( self::class, 'refresh' ) );
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
		$before  = Page_Cache::stats()['pages'];
		$results = self::$purger->purge_urls_now( $urls, 'cli' );
		foreach ( $results as $layer => $result ) {
			if ( 'disk' === $layer ) {
				continue;
			}
			\WP_CLI::log( sprintf( '%s: %d purged, %d failed%s', $layer, (int) $result['ok'], (int) $result['failed'], '' !== (string) $result['error'] ? ' (' . $result['error'] . ')' : '' ) );
		}
		self::report( $before, implode( ', ', $urls ) );
	}

	/**
	 * Check which caches answer in front of the site (nginx, Varnish, Apache, LiteSpeed, Cloudflare).
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache server detect
	 *
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args Unused.
	 * @return void
	 */
	public static function server_detect( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$probe = Server\Probe::run();
		if ( ! empty( $probe['blind'] ) ) {
			\WP_CLI::error( sprintf( 'The site did not answer (%s), so it is not known what is in front of it.', (string) $probe['error'] ), false );
			return;
		}
		\WP_CLI::log( sprintf( 'Page cache in front: %s. CDN: %s.', (string) $probe['page'], (string) $probe['cdn'] ) );
		foreach ( (array) $probe['status'] as $layer => $outcome ) {
			\WP_CLI::log( sprintf( '  %s: %s', $layer, $outcome ) );
		}
		\WP_CLI::success( 'Detection stored.' );
	}

	/**
	 * Send the queued server-cache purges now instead of waiting for WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache server drain
	 *
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args Unused.
	 * @return void
	 */
	public static function server_drain( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$left = self::controller()->server()->drain();
		\WP_CLI::success( sprintf( 'Queue sent; %d address(es) still waiting.', $left ) );
	}

	/**
	 * Start, stop or show the paced refresh (what replaces "purge everything" after an update).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : start, stop, status or tick (run one batch now).
	 * ---
	 * options:
	 *   - start
	 *   - stop
	 *   - status
	 *   - tick
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-cache refresh status
	 *
	 * @param array<int,string>    $args       The action.
	 * @param array<string,string> $assoc_args Unused.
	 * @return void
	 */
	public static function refresh( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$action = (string) ( $args[0] ?? 'status' );
		if ( 'start' === $action ) {
			Refresh::arm( 'manual' );
		} elseif ( 'stop' === $action ) {
			Refresh::stop();
		} elseif ( 'tick' === $action ) {
			( new Refresh( self::controller(), Settings::get() ) )->tick();
		}
		$state = Refresh::state();
		if ( null === $state ) {
			\WP_CLI::success( 'No refresh is running.' );
			return;
		}
		\WP_CLI::success( sprintf( 'Refresh running since %s: stage %s, %d addresses refreshed%s.', gmdate( 'c', (int) $state['armed_at'] ), (string) $state['stage'], (int) $state['done'], '' !== (string) $state['paused'] ? ', paused: ' . $state['paused'] : '' ) );
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
		$pages                = Page_Cache::stats();
		$report['page_cache'] = empty( Settings::get()['lscache_enabled'] ) ? 'off' : ( Page_Cache::is_ready() ? 'disk' : 'server' );
		// `server` above means "not the disk cache" (V1 alerts read it by name); the next fields
		// say which server cache.
		$report['page_cache_pages']  = $pages['pages'];
		$server                      = self::controller()->server();
		$report['server_cache']      = $server->chosen_layer();
		$report['server_cache_seen'] = $server->page_layer();
		$report['purge_queue']       = Server\Server_Cache::queue_depth();
		$report['refresh']           = null === Refresh::state() ? 'idle' : 'running';
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
