<?php
/**
 * Smart automatic purging on content change.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use WP_Comment;

/**
 * Wires WordPress content-change events to targeted cache purges.
 *
 * Post edits purge the post plus the listings it appears on; comment changes
 * purge the parent post; term edits purge the term archive; theme switches,
 * plugin (de)activations, core/plugin/theme upgrades, customizer saves, menu
 * saves and site-wide option changes purge everything. Every purge is dispatched
 * to the LiteSpeed layer and mirrored to the control-plane panel (so the
 * CDN/edge purges in step) via a signed webhook.
 */
final class Purge_Controller {

	/**
	 * Options whose value is rendered into EVERY page, so a change must purge everything.
	 *
	 * Measured on a live site (D19743): a customer changed the Site Title in Settings and
	 * every cached page kept the old title for the full public TTL — a week — because no
	 * purge hook fires on an option. Post edits were already covered; these were not, and
	 * neither the third-party LiteSpeed Cache plugin nor this plugin listened for them.
	 * `wp_update_nav_menu` is registered beside these for the same reason: a menu is on
	 * every page and is saved through neither a post nor an option this list can name.
	 *
	 * ⛔ Keep this a list of option NAMES and register from it in one loop — a second
	 * hand-typed `add_action` block is how a hook gets added to the docstring and not to
	 * the code (§2.24).
	 *
	 * @var string[]
	 */
	public const SITE_WIDE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'permalink_structure',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'sidebars_widgets',
	);

	/**
	 * LiteSpeed cache controller.
	 *
	 * @var Lscache
	 */
	private Lscache $lscache;

	/**
	 * Current, normalised plugin settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * Constructor.
	 *
	 * @param Lscache             $lscache  LiteSpeed cache controller.
	 * @param array<string,mixed> $settings Normalised settings from {@see Settings::get()}.
	 */
	public function __construct( Lscache $lscache, array $settings ) {
		$this->lscache  = $lscache;
		$this->settings = $settings;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( empty( $this->settings['auto_purge_enabled'] ) ) {
			return;
		}

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_save_post' ), 10, 1 );
		// `before_delete_post` fires while the row still exists, so the permalink
		// and post type can still be resolved (unlike `deleted_post`).
		add_action( 'before_delete_post', array( $this, 'on_save_post' ), 10, 1 );

		add_action( 'comment_post', array( $this, 'on_comment_change' ), 10, 1 );
		add_action( 'edit_comment', array( $this, 'on_comment_change' ), 10, 1 );
		add_action( 'trashed_comment', array( $this, 'on_comment_change' ), 10, 1 );
		// `delete_comment` fires before the row is removed, so the parent post is
		// still resolvable (unlike `deleted_comment`).
		add_action( 'delete_comment', array( $this, 'on_comment_change' ), 10, 1 );
		add_action( 'wp_set_comment_status', array( $this, 'on_comment_change' ), 10, 1 );

		add_action( 'created_term', array( $this, 'on_term_change' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 1 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 1 );

		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		add_action( 'activated_plugin', array( $this, 'purge_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );

		foreach ( self::site_wide_option_hooks() as $hook ) {
			add_action( $hook, array( $this, 'purge_all' ) );
		}

		if ( ! empty( $this->settings['purge_on_upgrade'] ) ) {
			add_action( 'upgrader_process_complete', array( $this, 'purge_all' ) );
		}
	}

	/**
	 * Purge a post and the listings it can appear on.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_save_post( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return;
		}

		$this->dispatch( $this->plan_for_post( $post ) );
	}

	/**
	 * Purge the post a changed comment belongs to.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function on_comment_change( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );
		if ( $post instanceof WP_Post ) {
			$this->dispatch( $this->plan_for_post( $post ) );
		}
	}

	/**
	 * Purge a taxonomy term archive (and the site listings).
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function on_term_change( int $term_id ): void {
		$urls = array();
		$link = get_term_link( $term_id );
		if ( is_string( $link ) ) {
			$urls[] = $link;
		}
		$urls[] = home_url( '/' );

		$tags = array(
			Cache_Tags::term( $term_id ),
			Cache_Tags::archive(),
			Cache_Tags::home(),
			Cache_Tags::front_page(),
		);

		$this->dispatch( Purge_Planner::targets( $urls, $tags ) );
	}

	/**
	 * The `update_option_<name>` hooks that must purge everything.
	 *
	 * Derived from {@see self::SITE_WIDE_OPTIONS} so the list and the registration cannot
	 * disagree; the unit test calls this rather than reading the source.
	 *
	 * @return string[]
	 */
	public static function site_wide_option_hooks(): array {
		$hooks = array();
		foreach ( self::SITE_WIDE_OPTIONS as $option ) {
			$hooks[] = 'update_option_' . $option;
		}
		return $hooks;
	}

	/**
	 * Purge the entire cache.
	 *
	 * @return void
	 */
	public function purge_all(): void {
		$this->dispatch( Purge_Planner::everything() );
	}

	/**
	 * Build the purge plan for a single post.
	 *
	 * @param WP_Post $post Post being changed.
	 * @return array{purge_all:bool,urls:string[],tags:string[]}
	 */
	private function plan_for_post( WP_Post $post ): array {
		$post_id = (int) $post->ID;

		$urls = array( home_url( '/' ) );

		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) ) {
			$urls[] = $permalink;
		}

		$archive = get_post_type_archive_link( (string) $post->post_type );
		if ( is_string( $archive ) ) {
			$urls[] = $archive;
		}

		$tags = Cache_Tags::for_post_change( $post_id, (string) $post->post_type );

		return Purge_Planner::targets( $urls, $tags );
	}

	/**
	 * Dispatch a purge plan to LiteSpeed and mirror it to the panel.
	 *
	 * @param array{purge_all:bool,urls:string[],tags:string[]} $plan Purge plan.
	 * @return void
	 */
	private function dispatch( array $plan ): void {
		if ( Purge_Planner::is_empty( $plan ) ) {
			return;
		}

		$this->lscache->dispatch( $plan );

		/**
		 * Fires after the plugin has dispatched a cache purge.
		 *
		 * @param array{purge_all:bool,urls:string[],tags:string[]} $plan The purge plan that was dispatched.
		 */
		do_action( 'zinn_cache_purged', $plan );

		$this->notify_panel( $plan );
	}

	/**
	 * Mirror a purge to the control-plane panel via a signed, non-blocking webhook.
	 *
	 * Configured through the `ZINN_CACHE_PANEL_URL` and `ZINN_CACHE_PANEL_SECRET`
	 * constants (set per-site in the deploy footprint). Absent config = no-op, so
	 * the plugin works standalone.
	 *
	 * @param array{purge_all:bool,urls:string[],tags:string[]} $plan Purge plan.
	 * @return void
	 */
	private function notify_panel( array $plan ): void {
		if ( ! defined( 'ZINN_CACHE_PANEL_URL' ) || ! defined( 'ZINN_CACHE_PANEL_SECRET' ) ) {
			return;
		}

		$url    = (string) constant( 'ZINN_CACHE_PANEL_URL' );
		$secret = (string) constant( 'ZINN_CACHE_PANEL_SECRET' );
		if ( '' === $url || '' === $secret ) {
			return;
		}

		$payload = (string) wp_json_encode(
			array(
				'site'      => home_url( '/' ),
				'purge_all' => $plan['purge_all'],
				'urls'      => $plan['urls'],
				'tags'      => $plan['tags'],
				'at'        => time(),
			)
		);

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . "\n" . $payload, $secret );

		wp_remote_post(
			$url,
			array(
				'timeout'  => 2,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'           => 'application/json',
					'X-Zinn-Cache-Timestamp' => $timestamp,
					'X-Zinn-Cache-Signature' => 'sha256=' . $signature,
				),
				'body'     => $payload,
			)
		);
	}
}
