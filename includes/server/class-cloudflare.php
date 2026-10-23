<?php
/**
 * Cloudflare purge-by-URL driver.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Purges changed pages from Cloudflare's edge by URL, the way an APO setup expects.
 *
 * Needs a zone id and an API token limited to *Zone → Cache Purge → Purge* on that zone (the
 * `ZINN_CACHE_CLOUDFLARE_ZONE` and `ZINN_CACHE_CLOUDFLARE_TOKEN` constants win over the settings,
 * so a host can set them without the token ever reaching the database).
 *
 * ⛔ "Purge everything" empties the whole zone, every hostname on it, and sends every visitor to
 * the origin at once. It runs only from the explicit "Empty all now" action AND only when the site
 * owner has allowed it; updates and activations never reach it.
 */
final class Cloudflare extends Driver {

	/**
	 * URLs per purge call (Cloudflare's per-request limit on the Free plan).
	 */
	public const PER_CALL = 30;

	/**
	 * The API base.
	 */
	private const API = 'https://api.cloudflare.com/client/v4/zones/';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'cloudflare';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Cloudflare', 'zinn-cache' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->zone() && '' !== $this->token();
	}

	/**
	 * The zone id.
	 *
	 * @return string
	 */
	public function zone(): string {
		$zone = defined( 'ZINN_CACHE_CLOUDFLARE_ZONE' ) ? (string) constant( 'ZINN_CACHE_CLOUDFLARE_ZONE' ) : (string) ( $this->settings['cloudflare_zone_id'] ?? '' );
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $zone ) ? $zone : '';
	}

	/**
	 * The API token. Never shown, logged or exported.
	 *
	 * @return string
	 */
	private function token(): string {
		return defined( 'ZINN_CACHE_CLOUDFLARE_TOKEN' ) ? (string) constant( 'ZINN_CACHE_CLOUDFLARE_TOKEN' ) : (string) ( $this->settings['cloudflare_api_token'] ?? '' );
	}

	/**
	 * Whether the token comes from wp-config.php (so the screen does not offer to change it).
	 *
	 * @return bool
	 */
	public static function token_is_constant(): bool {
		return defined( 'ZINN_CACHE_CLOUDFLARE_TOKEN' ) && '' !== (string) constant( 'ZINN_CACHE_CLOUDFLARE_TOKEN' );
	}

	/**
	 * Purge these absolute URLs.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return array{ok:int,failed:int,error:string}
	 */
	public function purge_urls( array $urls ): array {
		if ( ! $this->is_configured() ) {
			return self::result( 0, count( $urls ), __( 'Cloudflare needs a zone id and an API token.', 'zinn-cache' ) );
		}
		$ok     = 0;
		$failed = 0;
		$error  = '';
		foreach ( array_chunk( array_values( $urls ), self::PER_CALL ) as $batch ) {
			$why = $this->call( array( 'files' => $batch ) );
			if ( '' === $why ) {
				$ok += count( $batch );
				continue;
			}
			$failed += count( $batch );
			$error   = $why;
		}
		return self::result( $ok, $failed, $error );
	}

	/**
	 * {@inheritDoc}
	 */
	public function purge_all(): array {
		if ( empty( $this->settings['cloudflare_purge_everything'] ) ) {
			return self::result( 0, 1, __( 'Emptying the whole Cloudflare zone is switched off in the settings. Single pages are still purged.', 'zinn-cache' ) );
		}
		if ( ! $this->is_configured() ) {
			return self::result( 0, 1, __( 'Cloudflare needs a zone id and an API token.', 'zinn-cache' ) );
		}
		$why = $this->call( array( 'purge_everything' => true ) );
		return '' === $why ? self::result( 1 ) : self::result( 0, 1, $why );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stats(): array {
		return array(
			'visible' => false,
			'reason'  => __( 'Cloudflare’s own hit ratio is in your Cloudflare dashboard, under Caching.', 'zinn-cache' ),
		);
	}

	/**
	 * One purge call.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return string Empty on success, otherwise why it failed (never the token).
	 */
	private function call( array $body ): string {
		$response = wp_remote_post(
			self::API . $this->zone() . '/purge_cache',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $json ) && ! empty( $json['success'] ) ) {
			return '';
		}
		if ( 429 === $code ) {
			return __( 'Cloudflare is rate-limiting purges; the rest are retried shortly.', 'zinn-cache' );
		}
		$message = is_array( $json ) && isset( $json['errors'][0]['message'] ) ? sanitize_text_field( (string) $json['errors'][0]['message'] ) : '';
		/* translators: 1: HTTP status code, 2: Cloudflare's error message. */
		return sprintf( __( 'Cloudflare answered HTTP %1$d. %2$s', 'zinn-cache' ), $code, $message );
	}
}
