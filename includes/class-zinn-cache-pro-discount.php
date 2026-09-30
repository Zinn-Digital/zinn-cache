<?php
/**
 * The hosting-customer Pro discount, offered inside wp-admin on a site Zinn hosts.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** The source of truth is
 * `wp/promo/class-zinn-pro-discount.php.tpl`; `wp/bin/build-promo.php` renders it into every
 * plugin whose Pro edition the platform discounts (`engine/engine/prodiscount/pro_catalogue.json`),
 * substituting the class name, text domain, the Pro edition's name and the percentage — all
 * read from the plugin registry, never typed — and `--check` fails the build on a stale copy.
 *
 * @package ZinnCache
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * "Hosting customer? Get 50% off Zinn® Cache Pro" — HOSTDISC, owner ruling 2026-09-29.
 *
 * ⚖️ The owner asked for the discount to be offered *"in the sites etc and panels where
 * applicable"*. A customer of Zinn hosting gets ONE personal code per Pro plugin, for their
 * first payment only; the code is the same one their dashboard shows.
 *
 * ⛔ **No remote call when a page loads.** The card is static HTML, and the engine is asked
 * only when an administrator presses the button — a form POST to `admin-post.php`, checked
 * by nonce and capability, which the site then sends to the engine signed with its own secret
 * (`ZINN_UPDATE_SECRET`, the site-panel scheme) and whose answer it verifies before using.
 * ⛔ **Only on a site Zinn hosts.** Without the platform's constants there is no endpoint and
 * no secret, and the card does not render at all: a discount for hosting customers shown on
 * somebody else's server is an advert for a refusal.
 */
final class Zinn_Cache_Pro_Discount {

	/**
	 * The admin-post action (and nonce action) this class answers.
	 */
	private const ACTION = 'zinn_pro_discount_zinn_cache';

	/**
	 * The engine path the site asks; the site id follows it.
	 */
	private const DISCOUNT_PATH = '/v1/wp/pro-discount/';

	/**
	 * Where the site id lives in the one constant every hosted site carries.
	 */
	private const UPDATE_PATH = '/v1/wp/plugin-update/';

	/**
	 * The only host a checkout link may point at.
	 */
	private const CHECKOUT_HOST = 'checkout.freemius.com';

	/**
	 * Seconds to wait for the engine: this is a click, so a person is waiting.
	 */
	private const TIMEOUT = 15;

	/**
	 * The screen this card belongs on (its id contains this), set by `register()`.
	 *
	 * @var string
	 */
	private static $screen = '';

	/**
	 * Returns true when the Pro edition is already in use here (nothing to sell).
	 *
	 * @var callable|null
	 */
	private static $has_pro = null;

	/**
	 * Hook the card onto the plugin's own screen and the button's handler.
	 *
	 * @param string        $screen  A fragment of the plugin's admin screen id.
	 * @param callable|null $has_pro Returns true when the Pro edition is already active.
	 * @return void
	 */
	public static function register( string $screen, ?callable $has_pro = null ): void {
		self::$screen  = $screen;
		self::$has_pro = $has_pro;
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Whether this site can ask at all: hosted by Zinn, and the viewer can buy for it.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( '' === self::endpoint() || '' === self::secret() ) {
			return false;
		}
		return ! ( is_callable( self::$has_pro ) && call_user_func( self::$has_pro ) );
	}

	/**
	 * Render the card on the plugin's own screen only.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( '' === self::$screen || null === $screen || false === strpos( (string) $screen->id, self::$screen ) ) {
			return;
		}
		self::render_card();
	}

	/**
	 * The card: a heading, one sentence, and a button. Static; no remote call.
	 *
	 * @return void
	 */
	public static function render_card(): void {
		if ( ! self::is_available() ) {
			return;
		}
		$state = self::take_state();
		echo '<div class="notice notice-info zinn-pro-discount"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: 1: a percentage, e.g. 50. 2: a Pro plugin's name. */
				__( 'Hosting customer? Get %1$d%% off %2$s', 'zinn-cache' ),
				50,
				'Zinn® Cache Pro'
			)
		);
		echo '</strong><br>';
		echo esc_html(
			sprintf(
				/* translators: %d: a percentage, e.g. 50. */
				__( 'As a Zinn Digital® hosting customer you get a personal code for %d%% off your first payment. Renewals are at the normal price.', 'zinn-cache' ),
				50
			)
		);
		echo '</p>';
		if ( '' !== $state ) {
			echo '<p>' . esc_html( self::state_sentence( $state ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<button type="submit" class="button button-primary">';
		echo esc_html(
			sprintf(
				/* translators: %d: a percentage, e.g. 50. */
				__( 'Get my %d%% code and go to checkout', 'zinn-cache' ),
				50
			)
		);
		echo '</button></p></form></div>';
	}

	/**
	 * The button: ask the engine, then go to the checkout with the code applied.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'zinn-cache' ), 403 );
		}
		$answer = self::ask();
		$url    = self::destination( $answer );
		if ( null !== $url ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ): array {
					$hosts[] = self::CHECKOUT_HOST;
					return $hosts;
				}
			);
			wp_safe_redirect( $url );
			exit;
		}

		$state = is_array( $answer ) ? (string) ( $answer['state'] ?? '' ) : '';
		set_transient( self::state_key(), '' === $state ? 'unavailable' : $state, 5 * MINUTE_IN_SECONDS );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Where the browser goes: the checkout with the code applied, or null.
	 *
	 * ⛔ Only an `https://checkout.freemius.com` URL on an `active` answer — this answer decides
	 * where an administrator's browser is sent, so anything else stays on the site.
	 *
	 * @param array<string, mixed>|null $answer The engine's verified answer.
	 * @return string|null
	 */
	public static function destination( ?array $answer ): ?string {
		if ( ! is_array( $answer ) || 'active' !== ( $answer['state'] ?? '' ) ) {
			return null;
		}
		$url = is_array( $answer['code'] ?? null ) ? (string) ( $answer['code']['checkout_url'] ?? '' ) : '';
		if ( 0 !== strpos( $url, 'https://' ) || self::CHECKOUT_HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * The sentence for a state the engine answered that was not a code.
	 *
	 * @param string $state The engine's state.
	 * @return string
	 */
	private static function state_sentence( string $state ): string {
		if ( 'not_eligible' === $state ) {
			return __( 'This discount is for customers with a paid hosting plan in good standing. Free trials do not qualify.', 'zinn-cache' );
		}
		if ( 'redeemed' === $state ) {
			return __( 'You have already used your hosting-customer discount for this plugin.', 'zinn-cache' );
		}
		// ⛔ Our problem, said as ours — never a vendor's name (§2.57).
		return __( 'We could not get your code just now. Please try again in a few minutes.', 'zinn-cache' );
	}

	/**
	 * The per-user key the last non-code answer is kept under, for one page view.
	 *
	 * @return string
	 */
	private static function state_key(): string {
		return self::ACTION . '_' . get_current_user_id();
	}

	/**
	 * Read and forget the last answer for this user.
	 *
	 * @return string
	 */
	private static function take_state(): string {
		$state = get_transient( self::state_key() );
		if ( false === $state ) {
			return '';
		}
		delete_transient( self::state_key() );
		return (string) $state;
	}

	/**
	 * The engine's discount endpoint for this site, derived from `ZINN_UPDATE_URL`, or ''.
	 *
	 * @return string
	 */
	private static function endpoint(): string {
		if ( ! defined( 'ZINN_UPDATE_URL' ) || ! is_string( ZINN_UPDATE_URL ) ) {
			return '';
		}
		$update = trim( ZINN_UPDATE_URL );
		$at     = strpos( $update, self::UPDATE_PATH );
		if ( false === $at || 0 !== strpos( $update, 'https://' ) ) {
			return '';
		}
		$id = substr( $update, $at + strlen( self::UPDATE_PATH ) );
		if ( '' === $id ) {
			return '';
		}
		return substr( $update, 0, $at ) . self::DISCOUNT_PATH . $id;
	}

	/**
	 * The site's shared secret, under either of the names the platform writes it.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( defined( 'ZINN_UPDATE_SECRET' ) && is_string( ZINN_UPDATE_SECRET ) ) {
			return trim( ZINN_UPDATE_SECRET );
		}
		if ( defined( 'ZINN_CACHE_PANEL_SECRET' ) && is_string( ZINN_CACHE_PANEL_SECRET ) ) {
			return trim( ZINN_CACHE_PANEL_SECRET );
		}
		return '';
	}

	/**
	 * Ask the engine for this site's owner's code. `null` on any failure, including an
	 * answer whose signature does not verify.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function ask(): ?array {
		$endpoint = self::endpoint();
		$secret   = self::secret();
		if ( '' === $endpoint || '' === $secret ) {
			return null;
		}
		$body      = (string) wp_json_encode(
			array(
				'plugin' => 'zinn-cache',
				'action' => 'issue',
				'locale' => get_user_locale(),
			)
		);
		$timestamp = (string) time();
		$response  = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => self::TIMEOUT,
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'           => 'application/json',
					'X-Zinn-Cache-Timestamp' => $timestamp,
					'X-Zinn-Cache-Signature' => self::sign( $secret, $timestamp, $body ),
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		// ⛔ Verified before it is parsed: this answer decides where the browser is sent.
		$signature = (string) wp_remote_retrieve_header( $response, 'x-zinn-signature' );
		if ( ! hash_equals( self::sign( $secret, $timestamp, $raw ), $signature ) ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * `sha256=<hex>` over `"<timestamp>\n<body>"` — the engine's scheme, exactly.
	 *
	 * @param string $secret    The site's shared secret.
	 * @param string $timestamp The Unix time the request was signed at.
	 * @param string $body      The exact bytes being signed.
	 * @return string
	 */
	private static function sign( string $secret, string $timestamp, string $body ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );
	}
}
