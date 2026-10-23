<?php
/**
 * The nginx proxy_cache / fastcgi_cache purge driver.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an nginx page cache that pages changed, in one of three ways, because hosts set nginx up
 * in three ways:
 *
 *  - `http`  — a purge request per page: the `ngx_cache_purge` module's `/purge/<path>` location
 *              (GET) or a `PURGE` request to the page itself. Sent to an address you name (for
 *              example `http://127.0.0.1`) with the site's Host header, because a request to the
 *              public address would reach a CDN in front of nginx, not nginx.
 *  - `files` — WordPress can read the cache folder (`proxy_cache_path` / `fastcgi_cache_path`), so
 *              the entry file is worked out from the cache key and deleted, the way nginx itself
 *              names it: md5 of the key, split into the `levels` directories.
 *  - `spool` — nginx runs somewhere WordPress cannot reach (another container, another user). The
 *              changed addresses are written, one per line, to a file in a folder a purger on the
 *              host reads. This is how zinnhub.com's nginx cache is purged.
 */
final class Nginx extends Driver {

	/**
	 * The default cache key, the one most nginx guides (and the Nginx Helper plugin) use.
	 */
	public const DEFAULT_KEY = '$scheme$request_method$host$request_uri';

	/**
	 * The default purge address template, for the `ngx_cache_purge` module.
	 */
	public const DEFAULT_ENDPOINT = '{scheme}://{host}/purge{request_uri}';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'nginx';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'nginx page cache', 'zinn-cache' );
	}

	/**
	 * The purge method: `http`, `files` or `spool`.
	 *
	 * @return string
	 */
	public function method(): string {
		$method = (string) ( $this->settings['nginx_purge_method'] ?? 'http' );
		return in_array( $method, array( 'http', 'files', 'spool' ), true ) ? $method : 'http';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		switch ( $this->method() ) {
			case 'files':
				$dir = (string) ( $this->settings['nginx_cache_path'] ?? '' );
				return '' !== $dir && is_dir( $dir );
			case 'spool':
				return '' !== (string) ( $this->settings['nginx_spool_dir'] ?? '' );
			default:
				return true;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_local(): bool {
		return 'http' !== $this->method();
	}

	/**
	 * Purge these absolute URLs.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return array{ok:int,failed:int,error:string}
	 */
	public function purge_urls( array $urls ): array {
		if ( array() === $urls ) {
			return self::result( 0 );
		}
		switch ( $this->method() ) {
			case 'files':
				return $this->purge_files( $urls );
			case 'spool':
				return $this->write_spool( $urls );
			default:
				return $this->purge_http( $urls );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function purge_all(): array {
		if ( 'files' !== $this->method() ) {
			return self::result( 0, 1, __( 'Emptying the whole nginx cache needs the "delete cache files" method, or your host. Single pages are still purged.', 'zinn-cache' ) );
		}
		$dir = $this->cache_dir();
		if ( null === $dir ) {
			return self::result( 0, 1, __( 'The nginx cache folder cannot be read.', 'zinn-cache' ) );
		}
		if ( ! self::looks_like_nginx_cache( $dir ) ) {
			return self::result( 0, 1, __( 'That folder does not look like an nginx cache, so nothing in it was deleted.', 'zinn-cache' ) );
		}
		$deleted = 0;
		foreach ( self::entry_files( $dir ) as $file ) {
			wp_delete_file( $file );
			++$deleted;
		}
		return self::result( $deleted );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stats(): array {
		if ( 'spool' === $this->method() ) {
			$dir     = (string) ( $this->settings['nginx_spool_dir'] ?? '' );
			$pending = is_dir( $dir ) ? glob( trailingslashit( $dir ) . '*.txt' ) : false;
			return array(
				'visible' => false,
				'reason'  => __( 'nginx runs where WordPress cannot read its cache; purges are handed to the host through the spool folder.', 'zinn-cache' ),
				'queue'   => is_array( $pending ) ? count( $pending ) : null,
			);
		}
		if ( 'files' === $this->method() ) {
			$dir  = $this->cache_dir();
			$size = null === $dir ? null : self::tree_size( $dir );
			if ( null === $size ) {
				return array(
					'visible' => false,
					'reason'  => __( 'The nginx cache folder cannot be read.', 'zinn-cache' ),
				);
			}
			return array(
				'visible'  => true,
				'entries'  => $size['files'],
				'bytes'    => $size['bytes'],
				'complete' => $size['complete'],
			);
		}
		return parent::stats();
	}

	/**
	 * The nginx cache key for a URL, one per variant the key template produces.
	 *
	 * Supported variables: `$scheme`, `$host`, `$http_host`, `$request_uri`, `$uri`, `$args`,
	 * `$is_args`, and `$request_method` (GET and HEAD). Any other `$name` takes each value listed
	 * for it in the key-variants setting (`name=value1|value2`, an empty value allowed), so a key
	 * such as `$scheme|$host|$request_uri|$encoding` with `encoding=gzip|` is purged in both forms.
	 *
	 * @param string                 $template Key template.
	 * @param string                 $url      Absolute URL.
	 * @param array<string,string[]> $variants Values for variables this driver cannot know.
	 * @return string[]
	 */
	public static function keys_for( string $template, string $url, array $variants = array() ): array {
		$parts = self::parts( $url );
		if ( null === $parts ) {
			return array();
		}
		$host  = (string) preg_replace( '/:\d+$/', '', $parts['host'] );
		$known = array(
			'scheme'         => array( $parts['scheme'] ),
			'host'           => array( $host ),
			'http_host'      => array( $parts['host'] ),
			'request_uri'    => array( $parts['request_uri'] ),
			'uri'            => array( $parts['path'] ),
			'args'           => array( $parts['query'] ),
			'is_args'        => array( '' !== $parts['query'] ? '?' : '' ),
			'request_method' => array( 'GET', 'HEAD' ),
		);
		$keys  = array( $template );
		if ( false === preg_match_all( '/\$([a-z_][a-z0-9_]*)/i', $template, $found ) ) {
			return $keys;
		}
		// Longest names first, so `$request_uri` is never read as `$request` followed by `_uri`.
		$names = array_unique( $found[1] );
		usort( $names, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		foreach ( $names as $name ) {
			$values = $known[ strtolower( $name ) ] ?? ( $variants[ $name ] ?? array( '' ) );
			$next   = array();
			foreach ( $keys as $key ) {
				foreach ( $values as $value ) {
					$next[] = str_replace( '$' . $name, (string) $value, $key );
				}
			}
			$keys = $next;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Where nginx stores the entry for a key: md5 of the key, split into the `levels` directories
	 * taken from the END of the hash (levels=1:2 → `c/29/b7f…029c`).
	 *
	 * @param string $dir    Cache root.
	 * @param string $levels The `levels=` value, for example `1:2`.
	 * @param string $key    Cache key.
	 * @return string
	 */
	public static function entry_path( string $dir, string $levels, string $key ): string {
		$hash = md5( $key );
		$path = rtrim( $dir, '/' );
		$end  = strlen( $hash );
		foreach ( self::levels( $levels ) as $width ) {
			$end  -= $width;
			$path .= '/' . substr( $hash, $end, $width );
		}
		return $path . '/' . $hash;
	}

	/**
	 * Parse a `levels=` value into widths (each 1 or 2, at most three levels, as nginx allows).
	 *
	 * @param string $levels For example `1:2`.
	 * @return int[]
	 */
	public static function levels( string $levels ): array {
		$out = array();
		foreach ( explode( ':', trim( $levels ) ) as $width ) {
			$width = (int) $width;
			if ( 1 === $width || 2 === $width ) {
				$out[] = $width;
			}
		}
		return array_slice( $out, 0, 3 );
	}

	/**
	 * Parse the key-variants setting (`name=value1|value2` per line).
	 *
	 * @param mixed $lines Setting value.
	 * @return array<string,string[]>
	 */
	public static function parse_variants( $lines ): array {
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}
			list( $name, $values ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$name                  = ltrim( $name, '$' );
			if ( 1 === preg_match( '/^[a-z_][a-z0-9_]*$/i', $name ) ) {
				$out[ $name ] = explode( '|', $values );
			}
		}
		return $out;
	}

	/**
	 * Delete the entry files for these URLs.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array{ok:int,failed:int,error:string}
	 */
	private function purge_files( array $urls ): array {
		$dir = $this->cache_dir();
		if ( null === $dir ) {
			return self::result( 0, count( $urls ), __( 'The nginx cache folder cannot be read.', 'zinn-cache' ) );
		}
		$template = (string) ( $this->settings['nginx_cache_key'] ?? '' );
		$template = '' === $template ? self::DEFAULT_KEY : $template;
		$levels   = (string) ( $this->settings['nginx_cache_levels'] ?? '1:2' );
		$variants = self::parse_variants( $this->settings['nginx_key_variants'] ?? array() );
		$ok       = 0;
		foreach ( $urls as $url ) {
			foreach ( self::keys_for( $template, (string) $url, $variants ) as $key ) {
				$file = self::entry_path( $dir, $levels, $key );
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			++$ok;
		}
		return self::result( $ok );
	}

	/**
	 * Hand the URLs to the host's purger through the spool folder (one file per batch, written to
	 * a temporary name and renamed, so the purger never reads half a file).
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array{ok:int,failed:int,error:string}
	 */
	private function write_spool( array $urls ): array {
		$dir = untrailingslashit( (string) ( $this->settings['nginx_spool_dir'] ?? '' ) );
		if ( '' === $dir || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! wp_is_writable( $dir ) ) {
			return self::result( 0, count( $urls ), __( 'The spool folder cannot be written.', 'zinn-cache' ) );
		}
		$lines = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			// ⛔ One URL per line is the whole format: a line break inside a URL would let it
			// smuggle a second "URL" into the host purger.
			if ( '' !== $url && false === strpbrk( $url, "\r\n" ) && null !== self::parts( $url ) ) {
				$lines[] = $url;
			}
		}
		if ( array() === $lines ) {
			return self::result( 0 );
		}
		$name = $dir . '/' . gmdate( 'YmdHis' ) . '-' . getmypid() . '-' . wp_rand( 100000, 999999 );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- A temporary file and a rename, so the host purger never reads half a batch; WP_Filesystem has no rename and may be FTP-backed.
		if ( false === file_put_contents( $name . '.tmp', implode( "\n", $lines ) . "\n", LOCK_EX ) || ! rename( $name . '.tmp', $name . '.txt' ) ) {
			wp_delete_file( $name . '.tmp' );
			return self::result( 0, count( $lines ), __( 'The spool folder cannot be written.', 'zinn-cache' ) );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return self::result( count( $lines ) );
	}

	/**
	 * Send a purge request per URL.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array{ok:int,failed:int,error:string}
	 */
	private function purge_http( array $urls ): array {
		$template = trim( (string) ( $this->settings['nginx_purge_endpoint'] ?? '' ) );
		$template = '' === $template ? self::DEFAULT_ENDPOINT : $template;
		$verb     = 'PURGE' === strtoupper( (string) ( $this->settings['nginx_http_verb'] ?? 'GET' ) ) ? 'PURGE' : 'GET';
		$address  = untrailingslashit( trim( (string) ( $this->settings['nginx_purge_address'] ?? '' ) ) );
		$ok       = 0;
		$failed   = 0;
		$error    = '';
		foreach ( $urls as $url ) {
			$target = self::endpoint_for( $template, (string) $url, $address );
			if ( null === $target ) {
				++$failed;
				continue;
			}
			$why = self::request( $verb, $target['url'], $target['headers'] );
			if ( '' === $why ) {
				++$ok;
			} else {
				++$failed;
				$error = $why;
			}
		}
		return self::result( $ok, $failed, $error );
	}

	/**
	 * Build the purge request for a URL from the endpoint template.
	 *
	 * Tokens: `{scheme}`, `{host}`, `{path}`, `{request_uri}`, `{url}`. With an address, the request
	 * goes there and carries the page's host in the Host header.
	 *
	 * @param string $template Endpoint template.
	 * @param string $url      Absolute page URL.
	 * @param string $address  Optional `scheme://ip[:port]` to send the request to.
	 * @return array{url:string,headers:array<string,string>}|null
	 */
	public static function endpoint_for( string $template, string $url, string $address = '' ): ?array {
		$parts = self::parts( $url );
		if ( null === $parts ) {
			return null;
		}
		$target  = strtr(
			$template,
			array(
				'{scheme}'      => $parts['scheme'],
				'{host}'        => $parts['host'],
				'{path}'        => $parts['path'],
				'{request_uri}' => $parts['request_uri'],
				'{url}'         => $url,
			)
		);
		$headers = array();
		if ( '' !== $address ) {
			$target_parts = wp_parse_url( $target );
			if ( ! is_array( $target_parts ) ) {
				return null;
			}
			$target          = $address . ( $target_parts['path'] ?? '/' ) . ( isset( $target_parts['query'] ) ? '?' . $target_parts['query'] : '' );
			$headers['Host'] = $parts['host'];
		}
		return array(
			'url'     => $target,
			'headers' => $headers,
		);
	}

	/**
	 * The cache folder, when it can be read.
	 *
	 * @return string|null
	 */
	private function cache_dir(): ?string {
		$dir = untrailingslashit( (string) ( $this->settings['nginx_cache_path'] ?? '' ) );
		return ( '' !== $dir && is_dir( $dir ) && is_readable( $dir ) ) ? $dir : null;
	}

	/**
	 * Every entry file under a cache folder (32-hex-character names only).
	 *
	 * @param string $dir Cache root.
	 * @return \Generator<string>
	 */
	private static function entry_files( string $dir ): \Generator {
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() && 1 === preg_match( '/^[a-f0-9]{32}$/', $file->getFilename() ) ) {
					yield $file->getPathname();
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Whether a folder holds nginx cache entries (or nothing yet). An entry file carries a
	 * `KEY: ` line in its header; anything else means the folder is not what the setting claims,
	 * and emptying it would delete someone's files.
	 *
	 * @param string $dir Cache root.
	 * @return bool
	 */
	public static function looks_like_nginx_cache( string $dir ): bool {
		$seen = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $file->getFilename() ) ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A 1 KB header read of a local cache file.
				$head = (string) file_get_contents( $file->getPathname(), false, null, 0, 1024 );
				if ( ! str_contains( $head, "\nKEY: " ) ) {
					return false;
				}
				if ( ++$seen >= 3 ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}
}
