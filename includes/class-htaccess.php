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
		self::stage_above_wordpress( $path );
		insert_with_markers( $path, self::MARKER, self::rules() );
	}

	/**
	 * On an admin page load, move a block that sits below WordPress's catch-all above it.
	 *
	 * Sites written before 1.7.4 have the block at the END of .htaccess (see
	 * {@see self::place_above_wordpress()}); this repairs them once, on the first admin
	 * page load after the update. A site whose block is already in place pays one read.
	 *
	 * @return void
	 */
	public static function repair_position(): void {
		$path = self::path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the site's own .htaccess, as insert_with_markers() does.
		$current = file_get_contents( $path );
		if ( ! is_string( $current ) || ! self::is_below_wordpress( $current ) ) {
			return;
		}
		self::write();
	}

	/**
	 * Is our block present BELOW `# BEGIN WordPress`?
	 *
	 * @param string $content The file's content.
	 * @return bool
	 */
	public static function is_below_wordpress( string $content ): bool {
		$wp   = self::marker_offset( $content, 'WordPress' );
		$ours = self::marker_offset( $content, self::MARKER );

		return null !== $wp && null !== $ours && $ours > $wp;
	}

	/**
	 * Put an (empty) marker pair for our block just above `# BEGIN WordPress`, so that
	 * `insert_with_markers()` fills it in THERE.
	 *
	 * ⛔⛔ `insert_with_markers()` APPENDS a block it does not find, which puts it after
	 * WordPress's catch-all `RewriteRule . /index.php [L]`. That rule ends rewriting for
	 * every pretty URL, so the no-cache rule below it never ran and a visitor carrying a
	 * login or cart cookie was served the ANONYMOUS cached page (`x-litespeed-cache: hit`),
	 * measured on 13 live sites, 2026-09-29 (lane CACHEORDER). A block already above
	 * WordPress, or a file with no WordPress block, is returned unchanged; every other line
	 * keeps its place.
	 *
	 * ⭐ Public and label-generic so `zinn-cache-pro`'s own root `.htaccess` block (and any
	 * later one) is placed by the same code.
	 *
	 * @param string $content The file's content.
	 * @param string $label   The block's marker label.
	 * @return string
	 */
	public static function place_above_wordpress( string $content, string $label = self::MARKER ): string {
		$wp = self::marker_offset( $content, 'WordPress' );
		if ( null === $wp ) {
			return $content;
		}
		$ours = self::marker_offset( $content, $label );
		if ( null !== $ours && $ours < $wp ) {
			return $content;
		}

		$begin    = preg_quote( '# BEGIN ' . $label, '/' );
		$end      = preg_quote( '# END ' . $label, '/' );
		$stripped = (string) preg_replace( '/^' . $begin . '[ \t]*\R.*?^' . $end . '[ \t]*(?:\R|\z)/ms', '', $content );
		$wp       = (int) self::marker_offset( $stripped, 'WordPress' );

		return substr( $stripped, 0, $wp ) . '# BEGIN ' . $label . "\n# END " . $label . "\n\n" . substr( $stripped, $wp );
	}

	/**
	 * Byte offset of the `# BEGIN <label>` line, or null when there is none.
	 *
	 * @param string $content The file's content.
	 * @param string $label   The marker label.
	 * @return int|null
	 */
	private static function marker_offset( string $content, string $label ): ?int {
		if ( ! preg_match( '/^# BEGIN ' . preg_quote( $label, '/' ) . '[ \t]*\r?$/m', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		return (int) $m[0][1];
	}

	/**
	 * Rewrite the file with a marker pair above WordPress's block, when it is not there.
	 * Call it just before `insert_with_markers()` for the same label.
	 *
	 * @param string $path  The .htaccess path.
	 * @param string $label The block's marker label.
	 * @return void
	 */
	public static function stage_above_wordpress( string $path, string $label = self::MARKER ): void {
		if ( ! is_readable( $path ) || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- .htaccess is written directly, as insert_with_markers() does.
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the site's own .htaccess, as insert_with_markers() does.
		$current = file_get_contents( $path );
		if ( ! is_string( $current ) ) {
			return;
		}
		$staged = self::place_above_wordpress( $current, $label );
		if ( $staged !== $current ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same direct, locked write insert_with_markers() performs on .htaccess; WP_Filesystem may be FTP-backed.
			file_put_contents( $path, $staged, LOCK_EX );
		}
	}

	/**
	 * Remove the managed block, leaving the rest of .htaccess intact.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$path = self::path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return;
		}

		// ⛔ Not `insert_with_markers( …, array() )`: that leaves an EMPTY "# BEGIN Zinn Cache"
		// block behind, so a site that never turned the page cache on still had its .htaccess
		// written by us (reported by the V1 rollout, 2026-09-28). With the page cache off this
		// plugin must leave .htaccess exactly as it found it: nothing is written when there is
		// no block, and an existing block is removed whole, markers included.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the site's own .htaccess, as insert_with_markers() does.
		$current = file_get_contents( $path );
		if ( ! is_string( $current ) ) {
			return;
		}

		$stripped = self::strip_block( $current );
		if ( $stripped === $current ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same direct, locked write insert_with_markers() performs on .htaccess; WP_Filesystem may be FTP-backed.
		file_put_contents( $path, $stripped, LOCK_EX );
	}

	/**
	 * Remove this plugin's marked block (markers included) from .htaccess content.
	 *
	 * @param string $content The file's content.
	 * @return string The content without the block; unchanged when there is none.
	 */
	public static function strip_block( string $content ): string {
		$pattern = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '\R?/ms';

		return (string) preg_replace( $pattern, '', $content );
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
