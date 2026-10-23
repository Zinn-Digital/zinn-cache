<?php
/**
 * Finds out which caches answer in front of this site, from the response headers.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Requests pages of this site the way a visitor does and reads which cache answered.
 *
 * ⛔⛔ THREE ANSWERS, NEVER TWO. "A server cache is there", "no server cache is there" and "the
 * request did not come back, so we do not know" are different facts. The third is stored as
 * `blind`, and nothing that would remove or install a cache acts on it as if it were the second.
 */
final class Probe {

	/**
	 * Option holding the last detection.
	 */
	public const OPTION = 'zinn_cache_server_probe';

	/**
	 * Response headers that name a page cache, and the layer each one means.
	 *
	 * @var array<string,string>
	 */
	public const PAGE_SIGNATURES = array(
		'x-cache-status'         => 'nginx',
		'x-proxy-cache'          => 'nginx',
		'x-fastcgi-cache'        => 'nginx',
		'x-nginx-cache'          => 'nginx',
		'x-srcache-fetch-status' => 'nginx',
		'x-varnish'              => 'varnish',
		'x-cache-detail'         => 'apache',
		'x-litespeed-cache'      => 'litespeed',
	);

	/**
	 * The headers kept in the stored result (what the status screen shows).
	 *
	 * @var string[]
	 */
	private const KEPT = array( 'server', 'via', 'age', 'cf-cache-status', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-srcache-fetch-status', 'x-varnish', 'x-cache-detail', 'x-litespeed-cache', 'x-zinn-cache', 'cache-control' );

	/**
	 * Work out the layers from one response's headers. Pure.
	 *
	 * @param array<string,string> $headers Lower-case header names => values.
	 * @return array{page:string,cdn:string,status:array<string,string>}
	 */
	public static function classify( array $headers ): array {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$page    = 'none';
		foreach ( self::PAGE_SIGNATURES as $name => $layer ) {
			if ( isset( $headers[ $name ] ) && '' !== trim( (string) $headers[ $name ] ) ) {
				$page = $layer;
				break;
			}
		}
		if ( 'none' === $page && isset( $headers['via'] ) && false !== stripos( (string) $headers['via'], 'varnish' ) ) {
			$page = 'varnish';
		}
		$cdn = isset( $headers['cf-cache-status'] ) ? 'cloudflare' : 'none';

		$status = array();
		$map    = array(
			'nginx'      => array( 'x-cache-status', 'x-proxy-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-srcache-fetch-status' ),
			'varnish'    => array( 'x-cache' ),
			'apache'     => array( 'x-cache' ),
			'litespeed'  => array( 'x-litespeed-cache' ),
			'cloudflare' => array( 'cf-cache-status' ),
			'zinn-cache' => array( 'x-zinn-cache' ),
		);
		foreach ( $map as $layer => $names ) {
			foreach ( $names as $name ) {
				if ( isset( $headers[ $name ] ) ) {
					$status[ $layer ] = self::outcome( (string) $headers[ $name ] );
					break;
				}
			}
		}
		if ( 'varnish' === $page && ! isset( $status['varnish'] ) ) {
			// Varnish has no standard status header: a cached object carries two transaction ids.
			$status['varnish'] = 2 <= count( preg_split( '/\s+/', trim( (string) ( $headers['x-varnish'] ?? '' ) ) ) ) ? 'HIT' : 'MISS';
		}

		return array(
			'page'   => $page,
			'cdn'    => $cdn,
			'status' => $status,
		);
	}

	/**
	 * Reduce a cache-status header value to HIT, MISS, BYPASS, STALE, EXPIRED or OTHER.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	public static function outcome( string $value ): string {
		$value = strtoupper( trim( $value ) );
		foreach ( array( 'HIT', 'MISS', 'BYPASS', 'STALE', 'EXPIRED', 'UPDATING', 'REVALIDATED', 'DYNAMIC' ) as $word ) {
			if ( str_contains( $value, $word ) ) {
				return 'UPDATING' === $word || 'REVALIDATED' === $word ? 'HIT' : $word;
			}
		}
		return 'OTHER';
	}

	/**
	 * Request the home page twice (the second can only be a hit if something cached the first)
	 * and store what answered.
	 *
	 * @return array<string,mixed> The stored result.
	 */
	public static function run(): array {
		$url    = home_url( '/' );
		$result = array(
			'at'      => time(),
			'page'    => 'none',
			'cdn'     => 'none',
			'blind'   => false,
			'error'   => '',
			'headers' => array(),
			'status'  => array(),
		);
		$last   = null;
		for ( $i = 0; $i < 2; $i++ ) {
			$response = self::fetch( $url );
			if ( is_wp_error( $response ) ) {
				$result['blind'] = true;
				$result['error'] = $response->get_error_message();
				update_option( self::OPTION, $result, false );
				return $result;
			}
			$last = $response;
		}
		$headers = self::header_array( $last );
		$found   = self::classify( $headers );

		$result['page']    = $found['page'];
		$result['cdn']     = $found['cdn'];
		$result['status']  = $found['status'];
		$result['headers'] = array_intersect_key( $headers, array_flip( self::KEPT ) );
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * The last stored detection, or null when there has been none.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last(): ?array {
		$stored = get_option( self::OPTION, null );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Request a sample of pages and count what each layer answered — the status screen's
	 * "of N pages, how many were served from the cache". Two requests per page, so the second
	 * shows whether the first was kept.
	 *
	 * @param string[] $urls Pages to sample.
	 * @return array{sampled:int,blind:int,layers:array<string,array<string,int>>}
	 */
	public static function sample( array $urls ): array {
		$out = array(
			'sampled' => 0,
			'blind'   => 0,
			'layers'  => array(),
		);
		foreach ( $urls as $url ) {
			self::fetch( (string) $url );
			$response = self::fetch( (string) $url );
			if ( is_wp_error( $response ) ) {
				++$out['blind'];
				continue;
			}
			++$out['sampled'];
			foreach ( self::classify( self::header_array( $response ) )['status'] as $layer => $outcome ) {
				$out['layers'][ $layer ][ $outcome ] = ( $out['layers'][ $layer ][ $outcome ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/**
	 * One anonymous GET, as a visitor would make it.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function fetch( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'cookies'     => array(),
				'headers'     => array( 'Accept-Encoding' => 'gzip' ),
				'user-agent'  => 'Mozilla/5.0 (compatible; ZinnCacheProbe/1.0; +https://zinndigital.com)',
				// The site's own address: a staging copy or a local certificate must not make
				// the probe blind. Nothing secret is sent.
				'sslverify'   => false,
			)
		);
	}

	/**
	 * Response headers as a lower-case name => value array.
	 *
	 * @param mixed $response A wp_remote_* response.
	 * @return array<string,string>
	 */
	private static function header_array( $response ): array {
		$out     = array();
		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $out;
	}
}
