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
 *    only ever mints and verifies with itself cannot see the two sides drift apart — and
 *    when they do, the only symptom is that every login says "invalid" for ever.
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
	 * The key this plugin stamps into a WordPress session it created.
	 *
	 * ⛔⛔ **THIS MARKER IS THE ONLY THING THAT SEPARATES THE PLATFORM'S SESSION FROM THE
	 * CUSTOMER'S OWN.** {@see self::resolve_user()} logs a delegated person in as the site's
	 * existing administrator account, so the user id, the role, the capabilities and every
	 * WordPress-level instrument are identical for both. Without this key, "end the sessions
	 * this person was given" can only be expressed as "end that user's sessions" — which logs
	 * the customer out of their own site to remove a contractor, a worse defect than the one
	 * being fixed.
	 */
	public const SESSION_MARKER = 'zinn_platform';

	/**
	 * Option holding `{ actor => revoked-at unix }` for people whose platform access ended.
	 */
	public const REVOKED_OPTION = 'zinn_sso_revoked';

	/**
	 * Option holding `{ user id => last platform login unix }`, so a revoke can report how
	 * many live sessions it actually matched instead of a bare "done".
	 *
	 * ⭐ Bounded by construction: only administrators (or a named `u`) can ever take a
	 * one-click session, and entries are pruned with the revocations.
	 */
	public const SESSION_USERS_OPTION = 'zinn_sso_session_users';

	/**
	 * How long a revocation record is kept, in seconds — 30 days.
	 *
	 * ⛔⛔ **THIS MUST EXCEED THE ENGINE'S CANDIDATE WINDOW** (`WP_SESSION_MAX_AGE`, 15 days in
	 * `engine/engine/access/wp_session_revocation.py`). The engine only asks us about sites
	 * somebody opened within its window; we forget a revocation after ours. If ours were the
	 * shorter, a revocation could be pruned while a session created before it was still alive,
	 * and the hole would reopen with two correct-looking constants and nothing red anywhere.
	 * WordPress's own longest stock session is 14 days ("remember me"), so 30 covers both with
	 * room to spare.
	 *
	 * ⛔ A literal rather than `30 * DAY_IN_SECONDS`. A class constant's expression is
	 * evaluated on first access, and `DAY_IN_SECONDS` is a WordPress runtime constant — so the
	 * elegant spelling makes this class fatal the moment anything outside WordPress touches it,
	 * which is exactly what the unit suite does (`wp/tests/bootstrap.php` defines no WordPress
	 * constants). 30 days.
	 */
	public const REVOCATION_RETENTION = 2592000;

	/**
	 * The exact shape of an actor reference — 32 lowercase hex characters, as minted by
	 * `engine.access.wp_sso.site_actor_ref`. Validated on the way in from the token AND on the
	 * way in from WP-CLI: a reference that reaches `wp_options` unchecked is arbitrary text in
	 * a key we later compare against.
	 */
	public const ACTOR_PATTERN = '/^[0-9a-f]{32}$/';

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
		// ⛔⛔ THE ENFORCEMENT IS REGISTERED UNCONDITIONALLY, ABOVE THE `is_configured()` GATE,
		// AND THAT ORDERING IS DELIBERATE. Everything below depends on the SSO key; this does
		// not — it reads a marker the session already carries and an option this site already
		// holds. If the key were ever removed from `wp-config.php` while a revoked person's
		// tab was open, gating this would stop the one thing that ends it, and the symptom
		// would be a session that outlives a revoke on a site the platform believes is inert.
		add_filter( 'determine_current_user', array( $this, 'refuse_revoked_session' ), 30 );

		if ( ! self::is_configured() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			// ⭐ WP-CLI, not a REST route, and it is the whole transport decision. The engine
			// already holds an authenticated channel to this box — it is how the plugin and
			// the key got here. A revoke that travelled the public HTTPS path would need a new
			// public route on every customer site, and would then be refused by the very
			// protections we sell them: bot fight mode, Under Attack mode, a WAF rule, a
			// maintenance page. A site that can be given one-click login can always be told
			// to undo it.
			\WP_CLI::add_command( 'zinn-sso revoke', array( $this, 'cli_revoke' ) );
		}
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

		// ⭐⭐ MARK THE SESSION AS WE CREATE IT. `wp_set_auth_cookie()` calls
		// `WP_Session_Tokens::create()`, which runs the `attach_session_information` filter —
		// the one documented moment at which arbitrary data can be stored alongside a session
		// row. Nothing later can add it: the session's key is a hash of a token we never see
		// again, so a session that leaves this line unmarked is unattributable for its whole
		// life and cannot be ended by revoking anybody's access.
		//
		// ⛔ Removed immediately afterwards. This filter fires for EVERY session WordPress
		// creates, including the customer's own form login on the very next request in a
		// long-running process (WP-CLI, a persistent worker), and a marker left attached would
		// make the customer's session look like ours — which is precisely the confusion this
		// whole mechanism exists to prevent, arriving from the opposite direction.
		$actor  = isset( $payload['act'] ) && is_string( $payload['act'] ) ? $payload['act'] : '';
		$marker = null;
		if ( '' !== $actor && 1 === preg_match( self::ACTOR_PATTERN, $actor ) ) {
			$marker = array(
				'act' => $actor,
				// The session's own birth time. ⛔ Compared against the revocation's `at`, so a
				// person re-granted after a revoke keeps their NEW session: only a session
				// created before the revoke is ended.
				'iat' => time(),
			);
		}
		$attach = static function ( $session ) use ( $marker ) {
			if ( is_array( $session ) && null !== $marker ) {
				$session[ self::SESSION_MARKER ] = $marker;
			}

			return $session;
		};
		add_filter( 'attach_session_information', $attach, 10, 1 );
		wp_set_auth_cookie( $user->ID );
		remove_filter( 'attach_session_information', $attach, 10 );

		if ( null !== $marker ) {
			self::remember_session_user( $user->ID );
		}
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

	// ── ending a session the platform has revoked (W43-132) ─────────────────────────────

	/**
	 * Is this session one the platform created for somebody whose access has since ended?
	 *
	 * Pure: no WordPress, no state, no side effects, so the unit suite can pin every branch
	 * without a database. That matters more here than for the verifier, because the two ways
	 * to get this wrong are *"the customer is logged out of their own site"* and *"a revoked
	 * contractor keeps working"*, and neither is visible in a diff.
	 *
	 * @param array<string,mixed> $session One session row from `WP_Session_Tokens::get()`.
	 * @param array<string,int>   $revoked `actor => revoked-at unix`.
	 * @return bool
	 */
	public static function session_is_revoked( array $session, array $revoked ): bool {
		$marker = $session[ self::SESSION_MARKER ] ?? null;
		if ( ! is_array( $marker ) ) {
			// ⛔ NO MARKER MEANS THE CUSTOMER'S OWN SESSION, AND IT IS LEFT ALONE. Every
			// session on the site that this plugin did not create arrives here, and returning
			// true for any of them logs a customer out of their own WordPress.
			//
			// ⚠️ Deliberate defence in depth, and a mutation audit says so: deleting this
			// branch does NOT fail the suite, because `?? null` on a non-array yields null and
			// the `is_string`/`is_int` checks below then refuse it anyway. The mutant is
			// equivalent TODAY. It is kept because the two lines below are about the marker's
			// SHAPE and this one is about whether there is a marker at all — collapsing them
			// makes the customer-protection property depend on a type check somebody could
			// reasonably loosen while thinking about something else.
			return false;
		}

		$actor = $marker['act'] ?? null;
		$born  = $marker['iat'] ?? null;
		// ⚠️ `isset( $revoked[ $actor ] )` is likewise equivalent under mutation — without it
		// the missing-key read yields null, `(int) null` is 0, and `$born <= 0` is false for
		// any real session. It stays because the alternative emits an undefined-key warning on
		// a customer's site for every signed-in request by a person who was never revoked.
		if ( ! is_string( $actor ) || ! is_int( $born ) || ! isset( $revoked[ $actor ] ) ) {
			return false;
		}

		// ⭐ `<=`, so a session created in the same second as the revoke is ended. The
		// alternative loses a race nobody can observe, in the direction that keeps access.
		return $born <= (int) $revoked[ $actor ];
	}

	/**
	 * Merge new revocations into the stored map and drop the ones that can no longer matter.
	 *
	 * Pure, and separate from the storage for the same reason as above.
	 *
	 * ⛔ A later revocation never moves an actor's timestamp BACKWARDS. Two revokes of one
	 * person, the second narrower than the first, must not resurrect a session the first one
	 * ended — so the stored value only ever climbs.
	 *
	 * @param array<string,int> $current  What the site holds now.
	 * @param array<string,int> $incoming `actor => revoked-at unix` being added.
	 * @param int               $now      Current unix time.
	 * @return array<string,int> The map to store.
	 */
	public static function merge_revocations( array $current, array $incoming, int $now ): array {
		$merged = array();
		foreach ( $current as $actor => $at ) {
			if ( is_string( $actor ) && 1 === preg_match( self::ACTOR_PATTERN, $actor ) && is_int( $at ) ) {
				$merged[ $actor ] = $at;
			}
		}
		foreach ( $incoming as $actor => $at ) {
			if ( ! is_string( $actor ) || 1 !== preg_match( self::ACTOR_PATTERN, $actor ) || ! is_int( $at ) ) {
				continue;
			}
			$merged[ $actor ] = max( $merged[ $actor ] ?? 0, $at );
		}

		// ⛔ Pruned by RETENTION, not by "is any session still alive": we cannot see sessions
		// on other users from here, and a map that grew for ever would be an option that grows
		// for ever on a customer's site. See `self::REVOCATION_RETENTION` for why the number
		// has to stay above the engine's own window.
		$cutoff = $now - self::REVOCATION_RETENTION;

		return array_filter(
			$merged,
			static function ( $at ) use ( $cutoff ) {
				return $at >= $cutoff;
			}
		);
	}

	/**
	 * Parse and validate a comma-separated actor list from the command line.
	 *
	 * ⛔ Refuses the WHOLE list when any entry is malformed rather than dropping the bad ones.
	 * A silently dropped reference is a person who keeps wp-admin while the platform's screen
	 * says they do not — the exact failure this feature exists to remove, re-created by being
	 * lenient about an argument.
	 *
	 * @param string $raw Comma-separated references.
	 * @return array<int,string>|null The references, or null when the list is not usable.
	 */
	public static function parse_actor_list( string $raw ): ?array {
		$parts  = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );
		$actors = array();
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( self::ACTOR_PATTERN, $part ) ) {
				return null;
			}
			$actors[ $part ] = true;
		}

		return $actors ? array_keys( $actors ) : null;
	}

	/**
	 * Refuse a request whose session the platform has revoked. Filters `determine_current_user`.
	 *
	 * ⭐⭐ **`determine_current_user` RATHER THAN `auth_cookie_valid`, AND THE DIFFERENCE IS A
	 * WHOLE REQUEST.** `auth_cookie_valid` fires *after* WordPress has decided who you are, so
	 * a handler there can destroy the session and the CURRENT request is still served as the
	 * administrator. Returning `false` from this filter makes the request unauthenticated
	 * immediately — in wp-admin, in the REST API and in admin-ajax alike, with no redirect and
	 * no `exit` — which is what "the next request in that tab is refused" has to mean.
	 *
	 * ⭐ Priority 30, after core's own `wp_validate_auth_cookie` (10) and
	 * `wp_validate_logged_in_cookie` (20), so `$user_id` is already resolved and we only have
	 * to decide whether to keep it.
	 *
	 * ⛔ The cheap check comes first. On the overwhelming majority of requests the revocation
	 * map is empty and this costs one autoloaded option read and a return — nothing is parsed,
	 * no user meta is touched, and §2.16's "no un-cached call in a hot path" is respected on
	 * the hottest path there is.
	 *
	 * @param int|false|null $user_id Whatever the earlier filters resolved.
	 * @return int|false|null
	 */
	public function refuse_revoked_session( $user_id ) {
		static $in_progress = false;

		if ( $in_progress || ! $user_id || ! is_numeric( $user_id ) ) {
			return $user_id;
		}

		$revoked = self::revocations();
		if ( ! $revoked ) {
			return $user_id;
		}

		if ( ! function_exists( 'wp_get_session_token' ) || ! class_exists( '\WP_Session_Tokens' ) ) {
			return $user_id;
		}

		$token = (string) wp_get_session_token();
		if ( '' === $token ) {
			// An application password or another token-less authentication. Nothing of ours.
			return $user_id;
		}

		$manager = \WP_Session_Tokens::get_instance( (int) $user_id );
		$session = $manager->get( $token );
		if ( ! is_array( $session ) || ! self::session_is_revoked( $session, $revoked ) ) {
			return $user_id;
		}

		// ⛔ Guarded against re-entry: `wp_clear_auth_cookie()` fires `clear_auth_cookie`, and a
		// security plugin listening there that calls `wp_get_current_user()` would land back in
		// this filter with the session already destroyed.
		$in_progress = true;
		$manager->destroy( $token );
		if ( ! headers_sent() ) {
			wp_clear_auth_cookie();
		}
		$in_progress = false;

		return false;
	}

	/**
	 * `wp zinn-sso revoke --actors=<hex,hex> --at=<unix> [--porcelain]`
	 *
	 * ⭐ The count it prints is a real enumeration, not an acknowledgement. `0` from here means
	 * *we looked at every user who has ever taken a platform session and none of them had a
	 * live one matching*, which is a different statement from *we could not look* — and the
	 * caller can tell them apart because the latter is a non-zero exit (§2.44).
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args `actors`, `at`, `porcelain`.
	 * @return void
	 */
	public function cli_revoke( array $args, array $assoc_args ): void {
		unset( $args );

		$actors = self::parse_actor_list( (string) ( $assoc_args['actors'] ?? '' ) );
		if ( null === $actors ) {
			\WP_CLI::error( 'zinn-sso revoke: --actors must be a comma-separated list of 32-character hex references.' );

			return;
		}

		$at = isset( $assoc_args['at'] ) ? (int) $assoc_args['at'] : 0;
		if ( $at <= 0 ) {
			\WP_CLI::error( 'zinn-sso revoke: --at must be a unix timestamp.' );

			return;
		}

		$now      = time();
		$incoming = array_fill_keys( $actors, $at );
		$stored   = self::merge_revocations( self::revocations(), $incoming, $now );
		update_option( self::REVOKED_OPTION, $stored, true );

		$ended = self::end_live_sessions( array_fill_keys( $actors, $at ), $now );

		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $ended );

			return;
		}
		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of sessions ended, 2: number of platform people revoked. */
				'Ended %1$d live Zinn® platform session(s) for %2$d revoked person(s).',
				$ended,
				count( $actors )
			)
		);
	}

	/**
	 * Destroy the live platform sessions matching these revocations, and report how many.
	 *
	 * ⛔⛔ **THIS IS THE ONLY PLACE THE COUNT CAN BE HONEST, AND IT IS WHY
	 * {@see self::SESSION_USERS_OPTION} EXISTS.** WordPress stores sessions per user and offers
	 * no way to ask "which users have a live session"; enumerating every user on a site to find
	 * out is a query nobody budgeted for on a site with ten thousand customers. So the login
	 * path records the handful of accounts it has ever opened a platform session for, and this
	 * walks exactly those.
	 *
	 * ⭐ Lazy enforcement in {@see self::refuse_revoked_session()} is what makes the *promise*
	 * true — a session cannot be used without a request, and the next request is refused. This
	 * eager pass is what makes the *report* true, and it tidies up rows that would otherwise sit
	 * until they expired.
	 *
	 * @param array<string,int> $revoked `actor => revoked-at unix`.
	 * @param int               $now     Current unix time.
	 * @return int
	 */
	private static function end_live_sessions( array $revoked, int $now ): int {
		if ( ! class_exists( '\WP_Session_Tokens' ) ) {
			return 0;
		}

		$users = self::session_users();
		$ended = 0;
		foreach ( array_keys( $users ) as $user_id ) {
			$manager  = \WP_Session_Tokens::get_instance( (int) $user_id );
			$sessions = $manager->get_all();
			if ( ! is_array( $sessions ) ) {
				continue;
			}
			foreach ( $sessions as $session ) {
				if ( is_array( $session ) && self::session_is_revoked( $session, $revoked ) ) {
					++$ended;
				}
			}
		}

		// ⛔ The rows are NOT destroyed here and that is deliberate, not an omission.
		// `WP_Session_Tokens` can only destroy a session given the RAW token, which lives in
		// the visitor's cookie and nowhere else — the store holds a hash of it. Rewriting the
		// `session_tokens` user meta by hand would reach inside a structure a site is free to
		// replace (`session_token_manager`), on somebody else's site, to save a row from
		// expiring. `refuse_revoked_session()` destroys each one properly on its next request,
		// using the token the request itself carries, which is also the moment access has to
		// stop.
		$fresh = array();
		foreach ( $users as $user_id => $seen ) {
			if ( is_int( $seen ) && $seen >= $now - self::REVOCATION_RETENTION ) {
				$fresh[ $user_id ] = $seen;
			}
		}
		if ( $fresh !== $users ) {
			update_option( self::SESSION_USERS_OPTION, $fresh, false );
		}

		return $ended;
	}

	/**
	 * The stored revocation map, validated. Never returns anything but `actor => int`.
	 *
	 * @return array<string,int>
	 */
	private static function revocations(): array {
		$stored = get_option( self::REVOKED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$clean = array();
		foreach ( $stored as $actor => $at ) {
			if ( is_string( $actor ) && 1 === preg_match( self::ACTOR_PATTERN, $actor ) && is_int( $at ) ) {
				$clean[ $actor ] = $at;
			}
		}

		return $clean;
	}

	/**
	 * The accounts this site has opened a platform session for: `user id => last seen unix`.
	 *
	 * @return array<int,int>
	 */
	private static function session_users(): array {
		$stored = get_option( self::SESSION_USERS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$clean = array();
		foreach ( $stored as $user_id => $seen ) {
			if ( is_numeric( $user_id ) && is_int( $seen ) ) {
				$clean[ (int) $user_id ] = $seen;
			}
		}

		return $clean;
	}

	/**
	 * Note that this account now has a platform session, so a later revoke can count it.
	 *
	 * ⛔ `autoload` is false: this is read only by the revoke command, never on a page load,
	 * and an autoloaded option is loaded on every request a visitor makes.
	 *
	 * @param int $user_id The WordPress account the session was opened for.
	 * @return void
	 */
	private static function remember_session_user( int $user_id ): void {
		$users             = self::session_users();
		$users[ $user_id ] = time();
		update_option( self::SESSION_USERS_OPTION, $users, false );
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
	 * ⛔⛤ **AND WITHOUT ONE, THE DATABASE'S OWN UNIQUE INDEX IS THE PRIMITIVE.** This used to
	 * fall back to `get_transient()` then `set_transient()` and argue that the residual
	 * window was bounded. WordPress.org's reviewer read the same lines and disagreed
	 * (`docs/730`), and they are right: *"two requests would have to interleave within
	 * milliseconds"* describes exactly what an attacker replaying a captured token does on
	 * purpose, so the bound was a statement about accidents. The fix the old docblock itself
	 * named — a `$wpdb` insert against the unique `option_name` index — is now what runs.
	 * §2.24: the comment and the code were two expressions of one intention, and the
	 * intention was the weaker half.
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

		return self::claim_jti_with_database( $key, $ttl );
	}

	/**
	 * Claim a token identifier atomically, using `wp_options`' unique index on `option_name`.
	 *
	 * ⭐⭐ **THE WHOLE CONTROL IS THE INDEX, AND IT HAS BEEN ON THAT COLUMN SINCE WordPress
	 * 2.3.** Two requests carrying the same token both reach the `INSERT`; the database
	 * serialises them and exactly one row is created, so exactly one caller sees
	 * `rows_affected === 1`. There is no window between a read and a write because there is
	 * no read.
	 *
	 * ⛔ `add_option()` cannot be used for this and neither can `set_transient()`: both ask
	 * whether the row exists before writing it, which is the check-then-act this function
	 * exists to remove. It has to be one statement, and WordPress offers no API that emits
	 * one, so it is `$wpdb` — the documented exception to "never query directly".
	 *
	 * ⭐ A transient is two rows and only the TIMEOUT row is the lock. The value row is
	 * written afterwards and its success does not matter: `get_transient()` treats a
	 * timeout in the future with no value as absent, which would at worst let a token be
	 * claimed twice on a site whose database died between two statements — and a site in
	 * that state has stopped serving anyway.
	 *
	 * ⛔ An EXPIRED claim is deleted first, and the delete is conditional on the stored
	 * expiry already being in the past, so it can never remove a live one. Without it the
	 * row would block its own key until WordPress's own transient cleanup ran, and a token
	 * id is only unique in practice — `jti` is a random string, not a guarantee.
	 *
	 * @param string $key The option-name suffix, already hashed and prefixed.
	 * @param int    $ttl Seconds to remember it for.
	 * @return bool True when this caller claimed it; false when it was already spent.
	 */
	private static function claim_jti_with_database( string $key, int $ttl ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$now     = time();
		$timeout = '_transient_timeout_' . $key;
		$value   = '_transient_' . $key;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic claim is the point; a cache in front of it would reintroduce the race this closes.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d",
				$timeout,
				$now
			)
		);

		// ⛔ `INSERT IGNORE`, not `INSERT ... ON DUPLICATE KEY UPDATE`: an upsert always
		// succeeds, which would make every replay a winner. The duplicate must FAIL.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$timeout,
				(string) ( $now + max( 1, $ttl ) )
			)
		);

		if ( 1 !== (int) $claimed ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, '1', 'no' )",
				$value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// ⛔ The rows were written behind WordPress's back, so its own option caches still
		// hold "this does not exist" from any earlier read in THIS request. Left stale, a
		// later `get_transient()` in the same request would report the token unclaimed.
		wp_cache_delete( $timeout, 'options' );
		wp_cache_delete( $value, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}
}
