<?php
/**
 * The "Zinn® Cache" menu in the WordPress admin bar: purge this page, purge everything.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a purge menu to the admin bar for users who may manage the cache.
 *
 * ⛔ PHONES: below 782 px core hides every top-level admin-bar node it does not list by id
 * (`#wp-toolbar > ul > li { display: none }` in wp-includes/css/admin-bar.css). This node is
 * shown again there with its own icon and no text label, the way core's own nodes are, so the
 * menu works on a phone too. The few lines of CSS ride on core's `admin-bar` stylesheet and are
 * printed only where the admin bar shows for a user who sees the menu.
 *
 * Both actions are local admin-post requests: nonce-checked and capability-checked, and they
 * only purge this site's own caches through the same code the settings screen and WP-CLI use.
 */
final class Admin_Bar {

	/** Admin-bar node id. */
	public const NODE = 'zinn-cache';

	/** The admin-post action and the nonce action. */
	public const ACTION = 'zinn_cache_bar_purge';

	/** Query argument that tells the page which purge just ran. */
	public const DONE_ARG = 'zinn_cache_purged';

	/** Capability needed to see and use the menu (the settings screen's). */
	public const CAPABILITY = 'manage_options';

	/**
	 * Purges everything.
	 *
	 * @var callable():void
	 */
	private $purge_all;

	/**
	 * Purges the given absolute URLs.
	 *
	 * @var callable(string[]):mixed
	 */
	private $purge_urls;

	/**
	 * Wire the purge functions in.
	 *
	 * @param callable():void          $purge_all  Purge everything ({@see Purge_Controller::purge_everything()}).
	 * @param callable(string[]):mixed $purge_urls Purge these URLs ({@see Purge_Controller::purge_urls_now()}).
	 */
	public function __construct( callable $purge_all, callable $purge_urls ) {
		$this->purge_all  = $purge_all;
		$this->purge_urls = $purge_urls;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'menu' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Whether the current user may see and use the menu.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * The menu's nodes (pure, so it can be tested without a WordPress admin bar).
	 *
	 * @param bool   $allowed     Whether the user may manage the cache.
	 * @param string $page_url    The front-end page being viewed, or '' in wp-admin.
	 * @param string $return_url  Where to come back to after a purge.
	 * @param string $purged      Which purge just ran ('page', 'all' or '').
	 * @return array<int,array<string,mixed>> Arguments for `WP_Admin_Bar::add_node()`, parent first.
	 */
	public static function nodes( bool $allowed, string $page_url, string $return_url, string $purged ): array {
		if ( ! $allowed ) {
			return array();
		}
		$label = match ( $purged ) {
			'page'  => __( 'Page purged', 'zinn-cache' ),
			'all'   => __( 'Cache purged', 'zinn-cache' ),
			default => __( 'Zinn® Cache', 'zinn-cache' ),
		};
		$nodes = array(
			array(
				'id'    => self::NODE,
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html( $label ) . '</span>',
				// The settings screen lives in the shared Zinn Digital® menu (admin.php), never under
				// Settings: options-general.php?page=zinn-cache answers 403 (found on a live site).
				'href'  => class_exists( \Zinn_Cache_Admin_UI::class ) ? \Zinn_Cache_Admin_UI::page_url() : admin_url( 'admin.php?page=zinn-admin-ui-zinn-cache' ),
				'meta'  => array(
					'class' => 'zinn-cache-bar',
					'title' => __( 'Zinn® Cache', 'zinn-cache' ),
				),
			),
		);
		if ( '' !== $page_url ) {
			$nodes[] = array(
				'parent' => self::NODE,
				'id'     => self::NODE . '-page',
				'title'  => esc_html__( 'Purge this page', 'zinn-cache' ),
				'href'   => self::action_url( 'page', $page_url, $return_url ),
			);
		}
		$nodes[] = array(
			'parent' => self::NODE,
			'id'     => self::NODE . '-all',
			'title'  => esc_html__( 'Purge everything', 'zinn-cache' ),
			'href'   => self::action_url( 'all', '', $return_url ),
		);
		return $nodes;
	}

	/**
	 * A nonce-protected admin-post link.
	 *
	 * @param string $scope      'page' or 'all'.
	 * @param string $page_url   The page to purge ('page' only).
	 * @param string $return_url Where to come back to.
	 * @return string
	 */
	private static function action_url( string $scope, string $page_url, string $return_url ): string {
		$args = array(
			'action'   => self::ACTION,
			'scope'    => $scope,
			'back'     => $return_url,
			'_wpnonce' => wp_create_nonce( self::ACTION ),
		);
		if ( '' !== $page_url ) {
			$args['url'] = $page_url;
		}
		return admin_url( 'admin-post.php' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Add the menu to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar.
	 * @return void
	 */
	public function menu( $bar ): void {
		$current = self::current_url();
		foreach ( self::nodes( self::allowed(), is_admin() ? '' : $current, $current, self::purged_flag() ) as $node ) {
			$bar->add_node( $node );
		}
	}

	/**
	 * The admin bar's own look for the node, on phones too (see the class comment).
	 *
	 * @return void
	 */
	public function styles(): void {
		if ( ! is_admin_bar_showing() || ! self::allowed() ) {
			return;
		}
		$id  = '#wpadminbar #wp-admin-bar-' . self::NODE;
		$css = $id . ' .ab-icon:before{content:"\f311";top:2px}'
			. '@media screen and (max-width:782px){'
			. '#wpadminbar li#wp-admin-bar-' . self::NODE . '{display:block}'
			. $id . ' > .ab-item{inline-size:52px;padding:0;text-align:center}'
			. $id . ' .ab-label{display:none}'
			. $id . ' .ab-icon:before{top:0}'
			. '}';
		wp_add_inline_style( 'admin-bar', wp_strip_all_tags( $css ) );
	}

	/**
	 * The admin-post handler: check, purge, return.
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified by self::authorized() before anything is read for use.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! self::authorized( $nonce ) ) {
			wp_die( esc_html__( 'You are not allowed to purge the cache, or the link has expired. Reload the page and try again.', 'zinn-cache' ), '', array( 'response' => 403 ) );
			return;
		}
		$scope = isset( $_GET['scope'] ) && 'page' === $_GET['scope'] ? 'page' : 'all';
		$url   = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw is the sanitiser.
		$back  = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( (string) $_GET['back'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw is the sanitiser.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$done = $this->purge( $scope, $url );
		$back = wp_validate_redirect( $back, admin_url() );
		wp_safe_redirect( add_query_arg( self::DONE_ARG, $done, $back ) );
		exit;
	}

	/**
	 * A request may purge only with a valid nonce AND the capability.
	 *
	 * @param string $nonce The request's nonce.
	 * @return bool
	 */
	public static function authorized( string $nonce ): bool {
		return self::allowed() && false !== wp_verify_nonce( $nonce, self::ACTION );
	}

	/**
	 * Run the purge. A page purge is refused for an address that is not on this site and
	 * falls back to nothing (never to "everything").
	 *
	 * @param string $scope 'page' or 'all'.
	 * @param string $url   The page ('page' only).
	 * @return string What ran: 'page', 'all' or 'none'.
	 */
	public function purge( string $scope, string $url ): string {
		if ( 'page' === $scope ) {
			if ( '' === $url || ! self::on_this_site( $url ) ) {
				return 'none';
			}
			( $this->purge_urls )( array( $url ) );
			return 'page';
		}
		( $this->purge_all )();
		return 'all';
	}

	/**
	 * Whether an address belongs to this site.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function on_this_site( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && is_string( $home ) && strtolower( $host ) === strtolower( $home );
	}

	/**
	 * In wp-admin, a notice confirming the purge that just ran.
	 *
	 * @return void
	 */
	public function notice(): void {
		if ( ! self::allowed() ) {
			return;
		}
		$message = self::notice_text( self::purged_flag() );
		if ( '' !== $message ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * The confirmation for a finished purge.
	 *
	 * @param string $purged 'page', 'all', 'none' or ''.
	 * @return string '' when there is nothing to confirm.
	 */
	public static function notice_text( string $purged ): string {
		return match ( $purged ) {
			'page'  => __( 'Zinn® Cache: this page was purged. Its next visitor gets a fresh copy.', 'zinn-cache' ),
			'all'   => __( 'Zinn® Cache: the whole cache was purged. Pages are cached again as visitors open them.', 'zinn-cache' ),
			'none'  => __( 'Zinn® Cache: nothing was purged, because that address is not on this site.', 'zinn-cache' ),
			default => '',
		};
	}

	/**
	 * Which purge the page was reached from (display only; it changes nothing).
	 *
	 * @return string
	 */
	private static function purged_flag(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag after a nonce-checked purge.
		$flag = isset( $_GET[ self::DONE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE_ARG ] ) ) : '';
		return in_array( $flag, array( 'page', 'all', 'none' ), true ) ? $flag : '';
	}

	/**
	 * The address being viewed, without our confirmation flag.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw is the sanitiser.
		if ( '' === $host ) {
			return home_url( '/' );
		}
		return remove_query_arg( self::DONE_ARG, set_url_scheme( 'http://' . $host . $uri ) );
	}
}
