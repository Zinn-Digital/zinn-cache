<?php
/**
 * One-click WordPress-admin login from the Zinn dashboard.
 *
 * Author: Neil Lock — CEO, Zinn Digital® Ltd
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Exposes `GET /wp-json/zinn-sso/v1/login?token=…` — the site half of the platform's
 * one-click wp-admin login.
 *
 * The control plane mints a short-lived signed token (`engine/engine/access/wp_sso.py`)
 * and the customer's browser is sent here with it. There is no password anywhere in the
 * flow and nothing is stored: **the token IS the credential**, which is why every property
 * below is load-bearing.
 *
 * ## The contract, and why it is copied rather than invented
 *
 * `engine.access.wp_sso.verify_wp_sso_token` is the reference verifier and this class
 * mirrors it exactly:
 *
 *  - the token is `b64url(payload_json) . "." . b64url(HMAC-SHA256(site_key, payload_b64))`;
 *  - `site_key` is `HMAC-SHA256(platform_master_key, "wp-sso-v1:{site_id}")`, handed to
 *    this site as the hex constant `ZINN_SSO_KEY` at provision time — the master key never
 *    leaves the engine, and no two sites share a key, so a token minted for one site cannot
 *    be verified for another even before the `sid` check below;
 *  - base64 is **URL-safe with the padding stripped**, so decoding has to translate `-_`
 *    back to `+/` AND re-add `=`. Both are pinned by a byte-exact cross-language vector in
 *    `wp/tests/unit/SsoTest.php` and
 *    `engine/engine/access/tests/test_wp_sso_cross_language_vector.py`, because a suite that
 *    only ever mints and verifies with itself cannot see the two sides drift apart
 *    (`CLAUDE.md` §2.40) — and when they do, the only symptom is that every login says
 *    "invalid" for ever.
 *
 * ## Why single-use, when the token already expires
 *
 * The token rides in a URL, so it lands in the browser's history, in any `Referer` this
 * site emits, and in the access log of whatever sits in front of it. A ~2-minute lifetime
 * bounds that; single-use on `jti` bounds it to *one* wp-admin session rather than every
 * session anyone can start in those two minutes. The engine deliberately does NOT enforce
 * single use — it is stateless — so if this class does not, nothing does.
 *
 * ## Inert unless configured
 *
 * With no `ZINN_SSO_KEY`/`ZINN_SITE_ID` the route is not registered at all, exactly as the
 * updater and the event reporter `defined()`-guard themselves. That matters more here than
 * for them: an SSO route with no key is a route that would have to decide what an empty key
 * means, and every wrong answer to that question is "anyone can log in as the administrator".
 * Not registering it is the only answer with no edge case.
 */
final class Sso {

	/**
	 * REST namespace. Deliberately NOT `zinn-cache/v1`: this is a platform capability that
	 * happens to ship inside the cache plugin, and the engine addresses it by this path.
	 */
	private const REST_NAMESPACE = 'zinn-sso/v1';

	/**
	 * REST route.
	 */
	private const ROUTE = '/login';

	/**
	 * Token format version. Must equal `wp_sso.TOKEN_VERSION` on the engine — a bump on one
	 * side without the other rejects every token, which is the intended effect of a bump.
	 */
	private const TOKEN_VERSION = 1;

	/**
	 * Object-cache group for spent token identifiers.
	 */
	private const JTI_GROUP = 'zinn_sso_jti';

	/**
	 * Prefix for the spent-`jti` key (transient name / cache key).
	 */
	private const JTI_PREFIX = 'zinn_sso_jti_';

	/**
	 * How many administrators to consider when resolving "the site's primary admin".
	 * Bounded because this runs on a customer's site and an unbounded `get_users` on a
	 * multi-author network is a query nobody budgeted for.
	 */
	private const ADMIN_LOOKUP_LIMIT = 20;

	/**
	 * Claims a token identifier for single use: `( string $jti, int $ttl ): bool`, true when
	 * this caller won the claim.
	 *
	 * Injectable purely so the replay behaviour is testable — the unit suite runs with no
	 * WordPress, so it cannot reach a transient or an object cache. Production always uses
	 * the default.
	 *
	 * @var callable
	 */
	private $claim_jti;

	/**
	 * Constructor.
	 *
	 * @param callable|null $claim_jti Optional claim strategy (see {@see self::$claim_jti}).
	 */
	public function __construct( ?callable $claim_jti = null ) {
		$this->claim_jti = $claim_jti ?? array( self::class, 'claim_jti_with_wordpress' );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! self::is_configured() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Whether this site has been given an SSO key and its own identity.
	 *
	 * Both, never one: the key alone would verify a signature without checking which site
	 * the token was minted for, and the site id alone has nothing to check a signature with.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return defined( 'ZINN_SSO_KEY' )
			&& defined( 'ZINN_SITE_ID' )
			&& '' !== (string) constant( 'ZINN_SSO_KEY' )
			&& '' !== (string) constant( 'ZINN_SITE_ID' );
	}

	/**
	 * Register the login route.
	 *
	 * `permission_callback` is deliberately `__return_true`: the token in the query string
	 * IS the credential, and it is checked in the handler. WordPress requires an explicit
	 * callback here precisely so that "public" is written down rather than defaulted into.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Decode one URL-safe, unpadded base64 segment.
	 *
	 * ⛔ The single most likely place for the PHP and Python sides to disagree, so it is
	 * strict rather than forgiving: anything outside the URL-safe alphabet is rejected
	 * instead of being coerced, and `base64_decode` runs in strict mode. A lenient decoder
	 * would accept several spellings of the same signature, which is not a property you want
	 * anywhere near a `hash_equals`.
	 *
	 * @param string $segment One dot-delimited token segment.
	 * @return string|null Raw bytes, or null when the segment is not well formed.
	 */
	public static function decode_segment( string $segment ): ?string {
		if ( '' === $segment || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $segment ) ) {
			return null;
		}

		$padding = 0;
		$decoded = base64_decode( strtr( $segment, '-_', '+/' ) . str_repeat( '=', $padding ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own signed token's base64url segments, not obfuscated code; the strict flag and the alphabet check above are what make it safe.

		return false === $decoded ? null : $decoded;
	}

	/**
	 * Verify a token's signature, version, site binding and expiry.
	 *
	 * Pure: no WordPress, no state, no side effects — which is what lets the unit suite pin
	 * it against the engine's byte-exact vector. Single use is NOT checked here (it cannot
	 * be, statelessly); {@see self::authorize()} adds it.
	 *
	 * The order is deliberate. The signature is checked **before** anything in the payload
	 * is trusted, so a forged payload never reaches `json_decode`, and every failure returns
	 * the same `null` so the caller cannot leak which check failed.
	 *
	 * @param string $token        The presented token.
	 * @param string $site_key_hex Per-site signing key, hex encoded (`ZINN_SSO_KEY`).
	 * @param string $site_id      This site's id (`ZINN_SITE_ID`).
	 * @param int    $now          Current unix time.
	 * @return array<string,mixed>|null The decoded payload, or null if the token is not valid.
	 */
	public static function verify( string $token, string $site_key_hex, string $site_id, int $now ): ?array {
		$key = self::key_bytes( $site_key_hex );
		if ( null === $key || '' === $site_id ) {
			return null;
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		$payload_b64   = $parts[0];
		$signature_b64 = $parts[1];

		$provided = self::decode_segment( $signature_b64 );
		if ( null === $provided ) {
			return null;
		}

		// Signed over the base64 TEXT of the payload, not over its decoded bytes — same as
		// the engine. Signing the decoded form would leave the encoding unauthenticated.
		$expected = hash_hmac( 'sha256', $payload_b64, $key, true );
		if ( ! hash_equals( $expected, $provided ) ) {
			return null;
		}

		$json = self::decode_segment( $payload_b64 );
		if ( null === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( ! isset( $payload['v'] ) || self::TOKEN_VERSION !== $payload['v'] ) {
			return null;
		}

		// ⛔ The site binding, checked even though the key is already site-derived. Two
		// independent barriers: if a future change ever made key derivation site-agnostic
		// (or an operator copied one site's wp-config to another, which is exactly how a
		// staging clone is made), this is what still refuses the token.
		if ( ! isset( $payload['sid'] ) || ! is_string( $payload['sid'] ) || $payload['sid'] !== $site_id ) {
			return null;
		}

		// `>=` on the engine side means a token is dead AT its expiry second, not after it.
		if ( ! isset( $payload['exp'] ) || ! is_int( $payload['exp'] ) || $payload['exp'] <= $now ) {
			return null;
		}

		if ( ! isset( $payload['jti'] ) || ! is_string( $payload['jti'] ) || '' === $payload['jti'] ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Verify a token AND spend it — the full acceptance decision.
	 *
	 * ⭐ The claim happens **after** the signature verifies, never before. Claiming first
	 * would let anyone burn a `jti` of their choosing with an unsigned request, so a
	 * bystander who saw a token in a log could invalidate the customer's real login without
	 * ever being able to use it — a denial of service handed out for free.
	 *
	 * @param string $token        The presented token.
	 * @param string $site_key_hex Per-site signing key, hex encoded (`ZINN_SSO_KEY`).
	 * @param string $site_id      This site's id (`ZINN_SITE_ID`).
	 * @param int    $now          Current unix time.
	 * @return array<string,mixed>|null The decoded payload, or null if the token is not usable.
	 */
	public function authorize( string $token, string $site_key_hex, string $site_id, int $now ): ?array {
		$payload = self::verify( $token, $site_key_hex, $site_id, $now );
		if ( null === $payload ) {
			return null;
		}

		// The claim expires WITH the token: keeping a spent `jti` any longer stores rows
		// nothing can ever consult again, and keeping it any shorter reopens the replay
		// window this exists to close. At least one second, because a TTL of 0 means
		// "no expiry" to `set_transient` and "forever" is its own kind of leak.
		$ttl = max( 1, (int) $payload['exp'] - $now );

		return ( $this->claim_jti )( (string) $payload['jti'], $ttl ) ? $payload : null;
	}

	/**
	 * Handle `GET /wp-json/zinn-sso/v1/login`.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error A redirect into wp-admin, or a non-leaking refusal.
	 */
	public function handle_login( WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );

		$payload = $this->authorize(
			is_string( $token ) ? $token : '',
			(string) constant( 'ZINN_SSO_KEY' ),
			(string) constant( 'ZINN_SITE_ID' ),
			time()
		);
		if ( null === $payload ) {
			return self::refuse( 'token rejected (bad signature, wrong site, expired, or already used)' );
		}

		$username = isset( $payload['u'] ) && is_string( $payload['u'] ) ? $payload['u'] : '';
		$user     = self::resolve_user( $username );
		if ( ! $user instanceof WP_User ) {
			return self::refuse( 'no target user for the token' );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		// Core's own `wp_login`, fired so security and audit plugins see this session exactly
		// as they see a form login. Without it a one-click login is invisible to every
		// login-notification, 2FA-audit and last-seen plugin on the site — which, on a
		// customer's own site, reads as an unexplained administrator session appearing from
		// nowhere.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately firing WordPress CORE's login hook, not defining a Zinn one; prefixing it would make it a hook nothing listens to, which is the entire failure this line exists to avoid.
		do_action( 'wp_login', $user->user_login, $user );

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', admin_url() );
		// The URL that reached here carries a credential; nothing about this response may
		// be stored by a proxy or the browser's back button.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );

		return $response;
	}

	/**
	 * Resolve the WordPress user a token should log in as.
	 *
	 * An empty `u` claim means "the site's primary administrator", which is what the
	 * dashboard sends when the customer just wants in. A NON-empty `u` that does not resolve
	 * is refused rather than falling back to the administrator: silently promoting a typo to
	 * a full admin session is a surprise nobody asked for, and the customer would never know
	 * which account they were actually using.
	 *
	 * @param string $username The token's `u` claim.
	 * @return WP_User|null
	 */
	public static function resolve_user( string $username ): ?WP_User {
		if ( '' !== $username ) {
			$user = get_user_by( 'login', $username );

			return $user instanceof WP_User ? $user : null;
		}

		$candidates = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => self::ADMIN_LOOKUP_LIMIT,
				'fields'  => array( 'ID' ),
			)
		);

		foreach ( $candidates as $candidate ) {
			$user_id = (int) $candidate->ID;
			// The capability is re-checked rather than inferred from the role: a site can
			// have taken `manage_options` off the administrator role (some membership and
			// client-portal plugins do), and an account that cannot open Settings is not
			// what "log me into wp-admin" means.
			if ( user_can( $user_id, 'manage_options' ) ) {
				$user = get_user_by( 'id', $user_id );

				if ( $user instanceof WP_User ) {
					return $user;
				}
			}
		}

		return null;
	}

	/**
	 * The single, deliberately uninformative refusal.
	 *
	 * One message for every failure, so the response cannot be used to work out whether a
	 * token was expired, replayed, for another site, or simply forged. The customer-facing
	 * half still says what to do about it — they reached here from a button, and "invalid"
	 * with no remedy is how a support ticket gets written.
	 *
	 * @param string $reason Operator-facing detail. Never sent to the client.
	 * @return WP_Error
	 */
	private static function refuse( string $reason ): WP_Error {
		if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The ONLY place the refusal reason exists; an operator debugging a customer's failed login has no other signal, and it is gated behind WP_DEBUG so a production site logs nothing.
			error_log( 'Zinn® SSO: ' . $reason );
		}

		return new WP_Error(
			'zinn_sso_invalid_token',
			__( 'This one-click login link is not valid any more. Links expire after a couple of minutes and can only be used once — open your site from the Zinn® dashboard again to get a fresh one.', 'zinn-cache' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Convert the hex `ZINN_SSO_KEY` constant into raw key bytes.
	 *
	 * Validated rather than trusted: `hex2bin` on an odd-length or non-hex string emits a
	 * PHP warning, and a warning from a login endpoint on a customer's live site is both a
	 * broken login and a line in their error log they will ask about.
	 *
	 * @param string $hex Hex-encoded key.
	 * @return string|null Raw bytes, or null when the constant is not usable.
	 */
	private static function key_bytes( string $hex ): ?string {
		if ( '' === $hex || 0 !== strlen( $hex ) % 2 || 1 !== preg_match( '/^[0-9a-fA-F]+$/', $hex ) ) {
			return null;
		}

		$raw = hex2bin( $hex );

		return false === $raw ? null : $raw;
	}

	/**
	 * Claim a token identifier for single use, using whatever storage this site has.
	 *
	 * ⭐ `wp_cache_add` is the atomic primitive when a PERSISTENT object cache is present
	 * (Redis `SETNX` / memcached `ADD` — it fails if the key exists), which is the live path
	 * on the Zinn fleet, since this plugin ships the Redis drop-in. Two simultaneous
	 * presentations of one token then have a genuine winner and loser.
	 *
	 * ⛔ Without a persistent object cache there is no atomic primitive in WordPress at all:
	 * `get_transient` then `set_transient` is a check-then-act with a real window. It is kept
	 * because the alternative is no replay defence whatsoever, and the residual exposure is
	 * bounded — two requests would have to interleave within milliseconds, both carrying a
	 * token that is already single-use and already dead in ~2 minutes. If that ever needs to
	 * be closed, the fix is a `$wpdb` insert against the unique `option_name` index, not a
	 * longer TTL.
	 *
	 * @param string $jti Token identifier.
	 * @param int    $ttl Seconds to remember it for (the token's remaining life).
	 * @return bool True when this caller claimed it; false when it was already spent.
	 */
	private static function claim_jti_with_wordpress( string $jti, int $ttl ): bool {
		// Hashed, not concatenated: `jti` is attacker-supplied text and this becomes an
		// option name. The hash also keeps the key a fixed, safe length.
		$key = self::JTI_PREFIX . hash( 'sha256', $jti );

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $key, 1, self::JTI_GROUP, $ttl );
		}

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, $ttl );

		return true;
	}
}
