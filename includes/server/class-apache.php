<?php
/**
 * Apache mod_cache (mod_cache_disk) purge driver.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a page's entry from Apache's disk cache (`CacheEnable disk`, `CacheRoot`).
 *
 * Apache's mod_cache_disk names an entry after the md5 of the cache key, written in its own 64-character
 * alphabet (22 characters) and split into `CacheDirLevels` directories of `CacheDirLength`
 * characters. The entry is two files, `<name>.header` and `<name>.data`, plus a
 * `<name>.header.vary/` folder when the response varies on a header; all three are removed.
 *
 * The key is `scheme://host:port/path?query` — the port is always present and the `?` is always
 * there, even with no query string (mod_cache's default key, `cache_generate_key_default`).
 */
final class Apache extends Driver {

	/**
	 * The encoding alphabet of mod_cache_disk (cache_util.c), verified against real Apache 2.4 files.
	 */
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_@';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'apache';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Apache disk cache (mod_cache)', 'zinn-cache' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return null !== $this->root();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_local(): bool {
		return true;
	}

	/**
	 * Purge these absolute URLs.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return array{ok:int,failed:int,error:string}
	 */
	public function purge_urls( array $urls ): array {
		$root = $this->root();
		if ( null === $root ) {
			return self::result( 0, count( $urls ), __( 'The Apache CacheRoot folder cannot be read.', 'zinn-cache' ) );
		}
		$levels = (int) ( $this->settings['apache_dir_levels'] ?? 2 );
		$length = (int) ( $this->settings['apache_dir_length'] ?? 1 );
		$ok     = 0;
		foreach ( $urls as $url ) {
			$key = self::key_for( (string) $url );
			if ( '' === $key ) {
				continue;
			}
			$base = $root . '/' . self::cache_name( $key, $levels, $length );
			foreach ( array( '.header', '.data' ) as $suffix ) {
				if ( is_file( $base . $suffix ) ) {
					wp_delete_file( $base . $suffix );
				}
			}
			self::delete_vary_dir( $base . '.header.vary' );
			++$ok;
		}
		return self::result( $ok );
	}

	/**
	 * {@inheritDoc}
	 */
	public function purge_all(): array {
		$root = $this->root();
		if ( null === $root ) {
			return self::result( 0, 1, __( 'The Apache CacheRoot folder cannot be read.', 'zinn-cache' ) );
		}
		$deleted = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				// ⛔ Only mod_cache_disk's own files: an entry is `.header` / `.data`.
				if ( $file instanceof \SplFileInfo && $file->isFile() && 1 === preg_match( '/\.(header|data)$/', $file->getFilename() ) ) {
					wp_delete_file( $file->getPathname() );
					++$deleted;
				}
			}
		} catch ( \Throwable $e ) {
			return self::result( $deleted, 1, __( 'The Apache CacheRoot folder could not be read completely.', 'zinn-cache' ) );
		}
		return self::result( $deleted );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stats(): array {
		$root = $this->root();
		$size = null === $root ? null : self::tree_size( $root );
		if ( null === $size ) {
			return array(
				'visible' => false,
				'reason'  => __( 'The Apache CacheRoot folder cannot be read.', 'zinn-cache' ),
			);
		}
		$out = array(
			'visible'  => true,
			'entries'  => intdiv( $size['files'], 2 ),
			'bytes'    => $size['bytes'],
			'complete' => $size['complete'],
		);
		if ( null !== $root && self::caches_by_index_php( $root, home_url( '/' ), (int) ( $this->settings['apache_dir_levels'] ?? 2 ), (int) ( $this->settings['apache_dir_length'] ?? 1 ) ) ) {
			$out['warning'] = __( 'Apache stores every page under the address /index.php here, because its cache module runs in the same server as the WordPress rewrite rules. Every address can then be served the same cached page. Put the cache in a reverse-proxy virtual host in front of WordPress (ProxyPass, CacheEnable) instead.', 'zinn-cache' );
		}
		return $out;
	}

	/**
	 * Whether mod_cache has an entry keyed on `/index.php` for this site: the sign of mod_cache
	 * running behind WordPress's own rewrite, where the save filter keys every page on the
	 * rewritten `/index.php` and one page is then served for every address (measured on Apache
	 * 2.4 with mod_php, 2026-10-23: `/purge-me/` was answered with the Bystander page).
	 *
	 * @param string $root   CacheRoot.
	 * @param string $home   Home URL.
	 * @param int    $levels CacheDirLevels.
	 * @param int    $length CacheDirLength.
	 * @return bool
	 */
	public static function caches_by_index_php( string $root, string $home, int $levels, int $length ): bool {
		$base = rtrim( $home, '/' ) . '/index.php';
		return is_file( rtrim( $root, '/' ) . '/' . self::cache_name( self::key_for( $base ), $levels, $length ) . '.header' );
	}

	/**
	 * The default mod_cache key for a URL.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function key_for( string $url ): string {
		$parts = self::parts( $url );
		if ( null === $parts ) {
			return '';
		}
		$host = $parts['host'];
		if ( ! str_contains( $host, ':' ) ) {
			$host .= 'https' === $parts['scheme'] ? ':443' : ':80';
		}
		return $parts['scheme'] . '://' . $host . $parts['path'] . '?' . $parts['query'];
	}

	/**
	 * The mod_cache_disk file name for a key (`cache_hash()` in Apache's cache_util.c).
	 *
	 * @param string $key    Cache key.
	 * @param int    $levels CacheDirLevels.
	 * @param int    $length CacheDirLength.
	 * @return string
	 */
	public static function cache_name( string $key, int $levels = 2, int $length = 1 ): string {
		$digest = md5( $key, true );
		$out    = '';
		for ( $i = 0; $i < 15; $i += 3 ) {
			$x    = ( ord( $digest[ $i ] ) << 16 ) | ( ord( $digest[ $i + 1 ] ) << 8 ) | ord( $digest[ $i + 2 ] );
			$out .= self::ALPHABET[ $x >> 18 ] . self::ALPHABET[ ( $x >> 12 ) & 0x3f ] . self::ALPHABET[ ( $x >> 6 ) & 0x3f ] . self::ALPHABET[ $x & 0x3f ];
		}
		$x    = ord( $digest[15] );
		$out .= self::ALPHABET[ $x >> 2 ] . self::ALPHABET[ ( $x << 4 ) & 0x3f ];

		$levels = max( 0, min( 20, $levels ) );
		$length = max( 1, min( 20, $length ) );
		if ( $levels * $length > 20 ) {
			$levels = intdiv( 20, $length );
		}
		$path = '';
		for ( $d = 0, $k = 0; $d < $levels; ++$d, $k += $length ) {
			$path .= substr( $out, $k, $length ) . '/';
		}
		return $path . substr( $out, $levels * $length );
	}

	/**
	 * The CacheRoot, when it can be read.
	 *
	 * @return string|null
	 */
	private function root(): ?string {
		$dir = untrailingslashit( (string) ( $this->settings['apache_cache_root'] ?? '' ) );
		return ( '' !== $dir && is_dir( $dir ) && is_readable( $dir ) ) ? $dir : null;
	}

	/**
	 * Remove a `.header.vary` folder and the variant files in it.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	private static function delete_vary_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo ) {
					continue;
				}
				if ( $file->isDir() ) {
					@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A cache folder that may already be gone.
				} elseif ( 1 === preg_match( '/\.(header|data)$/', $file->getFilename() ) ) {
					wp_delete_file( $file->getPathname() );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A cache folder that may already be gone.
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
