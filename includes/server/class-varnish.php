<?php
/**
 * Varnish purge driver.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Sends `PURGE` (or `BAN`) requests to Varnish for each changed page.
 *
 * ⛔ Varnish must be configured to accept them from this server: a `vcl_recv` that handles
 * `PURGE` (return (purge)) or `BAN` (ban("req.url ~ ...")) behind an ACL. A stock Varnish answers
 * 405 and the status screen says so; nothing is assumed to have worked.
 */
final class Varnish extends Driver {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'varnish';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Varnish'; // A product name, the same in every language.
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->address();
	}

	/**
	 * Where Varnish listens, as `http://host:port`.
	 *
	 * @return string
	 */
	public function address(): string {
		$host = trim( (string) ( $this->settings['varnish_host'] ?? '' ) );
		if ( '' === $host ) {
			return '';
		}
		$port = (int) ( $this->settings['varnish_port'] ?? 80 );
		return 'http://' . $host . ( $port > 0 && 80 !== $port ? ':' . $port : '' );
	}

	/**
	 * `PURGE` or `BAN`.
	 *
	 * @return string
	 */
	public function verb(): string {
		return 'BAN' === strtoupper( (string) ( $this->settings['varnish_method'] ?? 'PURGE' ) ) ? 'BAN' : 'PURGE';
	}

	/**
	 * Purge these absolute URLs.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return array{ok:int,failed:int,error:string}
	 */
	public function purge_urls( array $urls ): array {
		$address = $this->address();
		if ( '' === $address ) {
			return self::result( 0, count( $urls ), __( 'No Varnish address is set.', 'zinn-cache' ) );
		}
		$ok     = 0;
		$failed = 0;
		$error  = '';
		foreach ( $urls as $url ) {
			$parts = self::parts( (string) $url );
			if ( null === $parts ) {
				++$failed;
				continue;
			}
			$why = self::request( $this->verb(), $address . $parts['request_uri'], array( 'Host' => $parts['host'] ) );
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
	 * {@inheritDoc}
	 */
	public function purge_all(): array {
		$address = $this->address();
		if ( '' === $address ) {
			return self::result( 0, 1, __( 'No Varnish address is set.', 'zinn-cache' ) );
		}
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		// A BAN of `.*` empties the site; PURGE of one URL cannot, so a PURGE setup is asked to
		// ban too (the common VCLs that accept PURGE also accept BAN from the same ACL).
		$why = self::request(
			'BAN',
			$address . '/',
			array(
				'Host'          => $host,
				'X-Ban-Url'     => '.*',
				'X-Purge-Regex' => '.*',
			)
		);
		return '' === $why ? self::result( 1 ) : self::result( 0, 1, $why );
	}
}
