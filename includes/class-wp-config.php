<?php
/**
 * The one `wp-config.php` line the disk page cache needs.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Adds and removes `define( 'WP_CACHE', true );`, and nothing else.
 *
 * WordPress loads `advanced-cache.php` only when `WP_CACHE` is true, and the constant can only be
 * set in `wp-config.php`. Every page-cache plugin edits that file; this one does it so that a
 * failure can never damage the site:
 *
 * - ⛔⛔ **It never writes a shorter file than it read, and never an empty one.** `docs/72`
 *   D11762 records a write chain that emptied `wp-config.php` on a live site. The new contents are
 *   built in memory, checked (they still hold every byte of the original plus our line), written
 *   to a temporary file beside the original, and renamed over it.
 * - It adds its line only when `WP_CACHE` is not defined in the file at all. A site that defines it
 *   itself (true or false) is the owner's decision, and is reported, not overridden.
 * - It removes only its own line, recognised by {@see Page_Cache::CONFIG_MARKER}.
 */
final class Wp_Config {

	/**
	 * Where `wp-config.php` is: ABSPATH, or one level up (WordPress allows both).
	 *
	 * @return string Empty when neither exists.
	 */
	public static function path(): string {
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return is_file( $up ) && ! is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ? $up : '';
	}

	/**
	 * Our line.
	 *
	 * @return string
	 */
	public static function line(): string {
		return "define( 'WP_CACHE', true ); " . Page_Cache::CONFIG_MARKER;
	}

	/**
	 * Add our line when the file does not define `WP_CACHE` at all.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_wp_cache() {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}
		$path = self::path();
		if ( '' === $path ) {
			return new WP_Error( 'zinn_cache_no_wp_config', __( 'wp-config.php was not found, so WP_CACHE could not be turned on. Add define( \'WP_CACHE\', true ); to it.', 'zinn-cache' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local configuration file.
		$original = (string) file_get_contents( $path );
		$updated  = self::with_line( $original );
		if ( null === $updated ) {
			return new WP_Error( 'zinn_cache_wp_cache_defined', __( 'wp-config.php already defines WP_CACHE as false, so the page cache cannot start. Change it to true.', 'zinn-cache' ) );
		}
		if ( $updated === $original ) {
			return true;
		}
		return self::replace( $path, $original, $updated )
			? true
			: new WP_Error( 'zinn_cache_wp_config_write', __( 'wp-config.php is not writable, so WP_CACHE could not be turned on. Add define( \'WP_CACHE\', true ); to it.', 'zinn-cache' ) );
	}

	/**
	 * Remove our line, if it is there.
	 *
	 * @return bool Whether the file is now free of our line.
	 */
	public static function remove_wp_cache(): bool {
		$path = self::path();
		if ( '' === $path ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local configuration file.
		$original = (string) file_get_contents( $path );
		$updated  = self::without_line( $original );
		return $updated === $original || self::replace( $path, $original, $updated );
	}

	/**
	 * The file with our line added, the file unchanged when it already carries our line or
	 * defines WP_CACHE true, or null when it defines WP_CACHE false.
	 *
	 * @param string $contents The file.
	 * @return string|null
	 */
	public static function with_line( string $contents ): ?string {
		if ( str_contains( $contents, Page_Cache::CONFIG_MARKER ) ) {
			return $contents;
		}
		if ( 1 === preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*([^)]*)\)/i', $contents, $m ) ) {
			return 1 === preg_match( '/^\s*(true|1|\'1\'|"1")\s*$/i', $m[1] ) ? $contents : null;
		}
		if ( 1 !== preg_match( '/^<\?php[^\n]*\n/', $contents, $open ) ) {
			return null;
		}
		return $open[0] . self::line() . "\n" . substr( $contents, strlen( $open[0] ) );
	}

	/**
	 * The file with our line removed.
	 *
	 * @param string $contents The file.
	 * @return string
	 */
	public static function without_line( string $contents ): string {
		return str_replace( self::line() . "\n", '', $contents );
	}

	/**
	 * Replace the file, refusing any result that lost bytes it should have kept.
	 *
	 * @param string $path     The file.
	 * @param string $original What was read.
	 * @param string $updated  What should be written.
	 * @return bool
	 */
	private static function replace( string $path, string $original, string $updated ): bool {
		$line = self::line() . "\n";
		// ⛔ The only difference allowed is our one line, added or removed. Anything else means the
		// transform misread the file, and writing it would change a customer's configuration.
		$same = strlen( $updated ) === strlen( $original ) + strlen( $line ) || strlen( $updated ) + strlen( $line ) === strlen( $original );
		if ( ! $same || '' === trim( $updated ) || ! str_starts_with( ltrim( $updated ), '<?php' ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- An atomic replace of wp-config.php needs file_put_contents()+rename() in the same directory; WP_Filesystem may be FTP-backed and cannot rename.
		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) {
			return false;
		}
		$temp = $path . '.zinn-cache-' . wp_generate_password( 8, false, false );
		if ( strlen( $updated ) !== file_put_contents( $temp, $updated, LOCK_EX ) ) {
			wp_delete_file( $temp );
			return false;
		}
		$perms = fileperms( $path );
		if ( false !== $perms ) {
			chmod( $temp, $perms & 0777 );
		}
		if ( ! @rename( $temp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported through the return value.
			wp_delete_file( $temp );
			return false;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		return true;
	}
}
