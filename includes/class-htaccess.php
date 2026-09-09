<?php
/**
 * LiteSpeed cache-vary management via .htaccess.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Writes (and removes) a managed .htaccess block that tells the LiteSpeed web
 * server to bypass the full-page cache for any request carrying a WordPress
 * login, password-protected, comment-author, or WooCommerce/EDD session cookie.
 *
 * This is essential for correctness in the standalone (no LiteSpeed Cache plugin)
 * mode: the plugin's per-request `no-cache` header only stops a logged-in
 * response from being *stored*; it cannot stop the server from *serving* an
 * already-cached anonymous page to a logged-in request, because a cache HIT never
 * reaches PHP. The server-level rule closes that private-data-leak gap. The block
 * is wrapped in `<IfModule LiteSpeed>`, so it is inert on non-LiteSpeed servers.
 */
final class Htaccess {

	/**
	 * Marker label for the managed block (`# BEGIN/END Zinn Cache`).
	 */
	private const MARKER = 'Zinn Cache';

	/**
	 * Reconcile the .htaccess block with the desired LSCache-enabled state.
	 *
	 * @param bool $enabled Whether LSCache control is enabled.
	 * @return void
	 */
	public static function sync( bool $enabled ): void {
		if ( $enabled ) {
			self::write();
		} else {
			self::remove();
		}
	}

	/**
	 * Write (or refresh) the managed block.
	 *
	 * @return void
	 */
	public static function write(): void {
		$path = self::path();
		if ( '' === $path ) {
			return;
		}

		self::load_markers_api();
		insert_with_markers( $path, self::MARKER, self::rules() );
	}

	/**
	 * Remove the managed block, leaving the rest of .htaccess intact.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$path = self::path();
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		self::load_markers_api();
		insert_with_markers( $path, self::MARKER, array() );
	}

	/**
	 * The LiteSpeed rewrite rules that bypass cache on any session cookie.
	 *
	 * @return string[]
	 */
	private static function rules(): array {
		$cookies = implode( '|', Exclusions::default_cookie_prefixes() );

		$rules = array(
			'<IfModule LiteSpeed>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_COOKIE} (' . $cookies . ') [NC]',
			'RewriteRule .* - [E=Cache-Control:no-cache]',
			'</IfModule>',
		);

		// ⛔⛔ **STATIC FILES ONLY, NEVER `text/html`.** A browser-cache lifetime on a page
		// is not a performance win, it is a visitor who cannot see a correction until their
		// cache expires — with no way for us or for them to clear it. The `mod_expires`
		// block below names image, font, CSS and JavaScript types explicitly for exactly
		// that reason; there is no "everything else" line and there must not be one.
		$browser_ttl = (int) ( Settings::get()['browser_ttl'] ?? 0 );
		if ( $browser_ttl > 0 ) {
			$rules[] = '<IfModule mod_expires.c>';
			$rules[] = 'ExpiresActive On';
			foreach ( array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'font/woff2', 'font/woff', 'text/css', 'application/javascript' ) as $mime ) {
				$rules[] = 'ExpiresByType ' . $mime . ' "access plus ' . $browser_ttl . ' seconds"';
			}
			$rules[] = '</IfModule>';
		}

		return $rules;
	}

	/**
	 * Resolve the site's .htaccess path, or '' when it cannot be determined.
	 *
	 * @return string
	 */
	private static function path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = get_home_path();
		if ( '' === $home || '/' === $home ) {
			$home = ABSPATH;
		}

		return rtrim( $home, '/\\' ) . '/.htaccess';
	}

	/**
	 * Ensure the `insert_with_markers()` helper is loaded.
	 *
	 * @return void
	 */
	private static function load_markers_api(): void {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}
}
