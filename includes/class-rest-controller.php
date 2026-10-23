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

		$admin = array( $this, 'is_admin_user' );
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => fn() => new WP_REST_Response( Status::report( $this->lscache ), 200 ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/purge-urls',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_purge_urls' ),
				'permission_callback' => $admin,
				'args'                => array(
					'urls' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
						'maxItems' => 500,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_probe' ),
				'permission_callback' => $admin,
				'args'                => array(
					'sample' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_refresh' ),
				'permission_callback' => $admin,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'start', 'stop' ),
					),
				),
			)
		);
	}

	/**
	 * Only a signed-in administrator (no HMAC: these routes are the status screen's own).
	 *
	 * @return bool
	 */
	public function is_admin_user(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Purge typed addresses (paths or full URLs on this site) on every layer, now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_purge_urls( WP_REST_Request $request ): WP_REST_Response {
		$urls = Status::to_urls( array_map( 'strval', (array) $request->get_param( 'urls' ) ) );
		$urls = $this->lscache->server()->own_urls( $urls );
		if ( array() === $urls ) {
			return new WP_REST_Response(
				array(
					'code'    => 'zinn_cache_no_urls',
					'message' => __( 'None of those is an address on this site.', 'zinn-cache' ),
				),
				400
			);
		}
		$plan    = Purge_Planner::targets( $urls );
		$results = $this->lscache->dispatch( $plan, 'manual' );
		/** This action is documented in includes/class-purge-controller.php */
		do_action( 'zinn_cache_purged', $plan );
		return new WP_REST_Response(
			array(
				'urls'    => $urls,
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Detect the caches in front of the site, and optionally sample how often they hit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_probe( WP_REST_Request $request ): WP_REST_Response {
		delete_transient( 'zinn_cache_layer_stats' );
		$out = array( 'probe' => Server\Probe::run() );
		if ( $request->get_param( 'sample' ) ) {
			$out['sample'] = Server\Probe::sample( Status::sample_urls() );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Start or stop the paced refresh.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_refresh( WP_REST_Request $request ): WP_REST_Response {
		if ( 'start' === $request->get_param( 'action' ) ) {
			Refresh::arm( 'manual' );
		} else {
			Refresh::stop();
		}
		return new WP_REST_Response(
			array(
				'running' => null !== Refresh::state(),
				'state'   => Refresh::state(),
			),
			200
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
			// An explicit "everything" from tooling the site owner runs: empty at once.
			$this->lscache->purge_all( 'api' );
			return new WP_REST_Response(
				array(
					'purged' => true,
					'plan'   => Purge_Planner::everything(),
				),
				200
			);
		} else {
			$raw_urls = is_array( $body['urls'] ?? null ) ? $body['urls'] : array();
			$raw_tags = is_array( $body['tags'] ?? null ) ? $body['tags'] : array();
			$urls     = array_map( 'esc_url_raw', array_map( 'strval', $raw_urls ) );
			$tags     = array_map( 'sanitize_text_field', array_map( 'strval', $raw_tags ) );
			$plan     = Purge_Planner::targets( $urls, $tags );
		}

		$this->lscache->dispatch( $plan, 'api' );

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
