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
 */
final class Cli {

	/**
	 * Register the command when running under WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'zinn-cache status', array( self::class, 'status' ) );
		}
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
