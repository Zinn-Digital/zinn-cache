<?php
/**
 * REST endpoint for control-plane-triggered purges.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes `POST /wp-json/zinn-cache/v1/purge` so the Zinn control plane (or a
 * signed-in administrator) can purge this site's cache remotely.
 *
 * Authentication is either a logged-in administrator (`manage_options`, via the
 * standard REST cookie nonce) or an HMAC-SHA256 signature over the raw request
 * body using the per-site `ZINN_CACHE_PANEL_SECRET`. Panel-triggered purges are
 * intentionally NOT mirrored back to the panel, to avoid a feedback loop.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'zinn-cache/v1';

	/**
	 * REST route.
	 */
	private const ROUTE = '/purge';

	/**
	 * Maximum accepted clock skew for a signed request, in seconds (replay bound).
	 */
	private const MAX_CLOCK_SKEW = 300;

	/**
	 * LiteSpeed cache controller.
	 *
	 * @var Lscache
	 */
	private Lscache $lscache;

	/**
	 * Constructor.
	 *
	 * @param Lscache $lscache LiteSpeed cache controller.
	 */
	public function __construct( Lscache $lscache ) {
		$this->lscache = $lscache;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the purge route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_purge' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'all'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'urls' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'tags' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Authorise the request (admin session, or valid HMAC signature).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public function check_permission( WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! defined( 'ZINN_CACHE_PANEL_SECRET' ) ) {
			return false;
		}

		$secret = (string) constant( 'ZINN_CACHE_PANEL_SECRET' );
		if ( '' === $secret ) {
			return false;
		}

		$timestamp = (string) $request->get_header( 'X-Zinn-Cache-Timestamp' );
		$provided  = (string) $request->get_header( 'X-Zinn-Cache-Signature' );
		if ( '' === $timestamp || '' === $provided ) {
			return false;
		}

		// Bound replay: reject stale or future timestamps.
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return false;
		}

		// Sign the timestamp together with the exact body the handler reads, so a
		// captured request cannot be replayed later, nor have query-string params
		// appended to change what is purged (the handler reads only the body).
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . "\n" . (string) $request->get_body(), $secret );

		return hash_equals( $expected, $provided );
	}

	/**
	 * Execute the requested purge.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_purge( WP_REST_Request $request ): WP_REST_Response {
		// Read ONLY from the JSON body — the same bytes the HMAC signs. Never fall
		// through to query-string params (which the signature does not cover).
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( ! empty( $body['all'] ) ) {
			$plan = Purge_Planner::everything();
		} else {
			$raw_urls = is_array( $body['urls'] ?? null ) ? $body['urls'] : array();
			$raw_tags = is_array( $body['tags'] ?? null ) ? $body['tags'] : array();
			$urls     = array_map( 'esc_url_raw', array_map( 'strval', $raw_urls ) );
			$tags     = array_map( 'sanitize_text_field', array_map( 'strval', $raw_tags ) );
			$plan     = Purge_Planner::targets( $urls, $tags );
		}

		$this->lscache->dispatch( $plan );

		/** This action is documented in includes/class-purge-controller.php */
		do_action( 'zinn_cache_purged', $plan );

		return new WP_REST_Response(
			array(
				'purged' => true,
				'plan'   => $plan,
			),
			200
		);
	}
}
