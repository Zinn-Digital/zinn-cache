<?php
/**
 * A cache that sits in front of WordPress and must be told when a page changes.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * One purge target: an nginx proxy cache, Varnish, Apache's mod_cache, or Cloudflare.
 *
 * ⭐ These caches are faster than anything PHP can do, because they answer before PHP starts. So
 * Zinn® Cache does not compete with them: it tells them which addresses changed. A site that has
 * one never gets the plugin's own disk cache as well ({@see \Zinn\Cache\Page_Cache::sync()}).
 *
 * Each driver answers in the same shape, so the status screen, the purge log, WP-CLI and the REST
 * API can say exactly which layer did and did not accept a purge.
 */
abstract class Driver {

	/**
	 * Current, normalised plugin settings.
	 *
	 * @var array<string,mixed>
	 */
	protected array $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Stable id: `nginx`, `varnish`, `apache` or `cloudflare`.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * A human name for the status screen.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether the driver has what it needs to purge (a path, a host, a token).
	 *
	 * @return bool
	 */
	abstract public function is_configured(): bool;

	/**
	 * Whether a purge here is a quick local operation (a file deleted or written) rather than a
	 * network call. Local purges run in the request that caused them; network purges are queued.
	 *
	 * @return bool
	 */
	public function is_local(): bool {
		return false;
	}

	/**
	 * Purge these absolute URLs.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return array{ok:int,failed:int,error:string}
	 */
	abstract public function purge_urls( array $urls ): array;

	/**
	 * Empty the whole cache. Only ever called on an explicit request from a person (the "Empty all
	 * now" button, `wp zinn-cache purge all`), never by an update or a plugin activation.
	 *
	 * @return array{ok:int,failed:int,error:string}
	 */
	abstract public function purge_all(): array;

	/**
	 * What this layer can say about itself: entries, bytes, a queue depth. A value WordPress cannot
	 * see is reported as null with a reason, never as 0 (a 0 would read as "empty").
	 *
	 * @return array<string,mixed>
	 */
	public function stats(): array {
		return array(
			'visible' => false,
			'reason'  => __( 'This cache runs on the server, where WordPress cannot read its size.', 'zinn-cache' ),
		);
	}

	/**
	 * A result row.
	 *
	 * @param int    $ok     Accepted.
	 * @param int    $failed Refused or unreachable.
	 * @param string $error  Why, when something failed.
	 * @return array{ok:int,failed:int,error:string}
	 */
	protected static function result( int $ok, int $failed = 0, string $error = '' ): array {
		return array(
			'ok'     => $ok,
			'failed' => $failed,
			'error'  => $error,
		);
	}

	/**
	 * Split an absolute URL into scheme, host, path and the request URI (path plus query).
	 *
	 * @param string $url Absolute URL.
	 * @return array{scheme:string,host:string,path:string,request_uri:string,query:string}|null
	 */
	public static function parts( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$path  = (string) ( $parts['path'] ?? '/' );
		$path  = '' === $path ? '/' : $path;
		$query = (string) ( $parts['query'] ?? '' );
		return array(
			'scheme'      => strtolower( (string) ( $parts['scheme'] ?? 'https' ) ),
			'host'        => strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ),
			'path'        => $path,
			'query'       => $query,
			'request_uri' => $path . ( '' !== $query ? '?' . $query : '' ),
		);
	}

	/**
	 * Send one HTTP request to a local cache and say whether it was accepted.
	 *
	 * A 404 from a purge endpoint means "that page was not cached", which is success: the cache no
	 * longer holds it. Anything else outside 2xx is a failure with its status in the error.
	 *
	 * @param string               $method  HTTP method (PURGE, BAN, GET).
	 * @param string               $url     Where to send it.
	 * @param array<string,string> $headers Extra headers.
	 * @return string Empty on success, otherwise why it failed.
	 */
	protected static function request( string $method, string $url, array $headers = array() ): string {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 3,
				'redirection' => 0,
				'headers'     => $headers,
				// ⛔ A purge goes to the cache on this server, often by IP with a Host header, so the
				// certificate cannot match. The request carries no secret and changes nothing but
				// a cached copy of a public page.
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// ⛔ A 404 is "not cached" only when the CACHE says it. A purge address nginx does not
			// know falls through to WordPress, whose 404 page would otherwise read as success
			// for every purge, for ever.
			$body = (string) wp_remote_retrieve_body( $response );
			if ( str_contains( $body, 'wp-content' ) || str_contains( $body, 'wp-includes' ) ) {
				return __( 'The purge address reached WordPress, not the cache. Check the purge address setting and the server configuration.', 'zinn-cache' );
			}
			return '';
		}
		if ( $code >= 200 && $code < 300 ) {
			return '';
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'The cache answered HTTP %d.', 'zinn-cache' ), $code );
	}

	/**
	 * Size and entry count of a directory tree, bounded so a huge cache cannot stall the screen.
	 *
	 * @param string $dir   Directory.
	 * @param int    $limit Stop after this many files.
	 * @return array{files:int,bytes:int,complete:bool}|null Null when the directory cannot be read
	 *                                                       (⛔ never 0: that would read as "empty").
	 */
	protected static function tree_size( string $dir, int $limit = 200000 ): ?array {
		if ( '' === $dir || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return null;
		}
		$files = 0;
		$bytes = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				++$files;
				$bytes += (int) $file->getSize();
				if ( $files >= $limit ) {
					return array(
						'files'    => $files,
						'bytes'    => $bytes,
						'complete' => false,
					);
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
		return array(
			'files'    => $files,
			'bytes'    => $bytes,
			'complete' => true,
		);
	}
}
