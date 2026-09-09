<?php
/**
 * Admin settings screen.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Zinn Cache" settings screen and handles its actions.
 *
 * Exposes controls for LSCache (enable + TTL), the Redis object cache (toggle +
 * connection), smart auto-purge, and cache exclusions, plus a "Purge everything
 * now" button. Every write is capability-checked and nonce-protected; every
 * output is escaped; every string is translation-ready.
 */
final class Admin {

	/**
	 * Settings group used by the WordPress Settings API.
	 */
	private const OPTION_GROUP = 'zinn_cache_settings_group';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'zinn-cache';

	/**
	 * Object-cache drop-in manager.
	 *
	 * @var Object_Cache
	 */
	private Object_Cache $object_cache;

	/**
	 * LiteSpeed cache controller.
	 *
	 * @var Lscache
	 */
	private Lscache $lscache;

	/**
	 * Constructor.
	 *
	 * @param Object_Cache $object_cache Object-cache manager.
	 * @param Lscache      $lscache      LiteSpeed cache controller.
	 */
	public function __construct( Object_Cache $object_cache, Lscache $lscache ) {
		$this->object_cache = $object_cache;
		$this->lscache      = $lscache;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// ⛔⛔ ON `init`, NOT `plugins_loaded` — WordPress 6.7 raises *"Translation loading …
		// triggered too early"* for any `__()` before `init`, and a settings page is
		// translated labels by construction. Measured on WordPress 7.1.
		add_action( 'init', array( $this, 'declare_page' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zinn_cache_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/*
	 * ⛔⛔ THE OLD `Settings →` SCREEN IS DELETED, NOT LEFT IN PLACE. ⚖️ The owner ruled on
	 * 2026-09-08 that every plugin lives under ONE `Zinn Digital®` menu, so `add_menu()` and
	 * the screen it pointed at were no longer hooked by anything — and a settings screen that
	 * still compiles, still reads the same option and can never be reached is the worst kind
	 * of dead code: the next person to change a field changes it in the copy nobody sees.
	 * The live declaration is `declare_page()`.
	 *
	 * ⛔ `register_settings()` / `register_setting()` SURVIVES and must. It is what makes
	 * `sanitize_option_{$option}` fire on the framework's own `update_option`, so the plugin's
	 * own normaliser still guards every write. Deleting it alongside the screen would have
	 * removed a control while removing something that looked like the same thing.
	 */


	/**
	 * Declare the screen through the shared Zinn settings framework.
	 *
	 * ⚖️ **Owner ruling, 2026-09-08: ONE top-level `Zinn` menu**, and *"customisable options
	 * … so they can properly contorl it and setit up"*. The three exclusion lists were
	 * already here; what was missing was everything a site owner needs to make the cache fit
	 * their site — a lifetime that differs by post type, a browser-cache lifetime, and the
	 * ability to keep a page out of the cache without editing a text area of URLs.
	 *
	 * @return void
	 */
	public function declare_page(): void {
		\Zinn_Cache_Admin_UI::register(
			array(
				'title'      => __( 'Cache', 'zinn-cache' ),
				'option'     => Settings::OPTION,
				'position'   => 50,
				'connection' => array( __CLASS__, 'status' ),
				'tabs'       => array(
					'page'       => array(
						'title'  => __( 'Page cache', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'page_fields' ),
					),
					'object'     => array(
						'title'  => __( 'Object cache', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'object_fields' ),
					),
					'purging'    => array(
						'title'  => __( 'Purging', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'purge_fields' ),
					),
					'exclusions' => array(
						'title'  => __( 'Exclusions', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'exclusion_fields' ),
					),
				),
				'actions'    => array(
					array(
						'id'       => 'zinn_cache_purge_all',
						'label'    => __( 'Purge everything now', 'zinn-cache' ),
						'confirm'  => __( 'Every cached page is discarded and will be rebuilt as visitors arrive. Continue?', 'zinn-cache' ),
						'callback' => array( __CLASS__, 'purge_all_now' ),
					),
				),
			)
		);
	}

	/**
	 * The full-page cache controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function page_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Full-page cache', 'zinn-cache' ),
				'description' => __( 'Served by LiteSpeed at the web server, before WordPress runs. This is where nearly all the speed comes from.', 'zinn-cache' ),
			),
			array(
				'key'            => 'lscache_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Page cache', 'zinn-cache' ),
				'checkbox_label' => __( 'Send LiteSpeed cache-control and tag headers on cacheable pages', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'         => 'lscache_ttl',
				'type'        => 'number',
				'label'       => __( 'How long a page stays cached', 'zinn-cache' ),
				'description' => __( 'Seconds. A week suits most sites; a news site publishing hourly wants far less.', 'zinn-cache' ),
				'min'         => Settings::TTL_MIN,
				'max'         => Settings::TTL_MAX,
				'default'     => 604800,
				'show_if'     => array( 'lscache_enabled' => true ),
			),
			array(
				'key'         => 'browser_ttl',
				'type'        => 'number',
				'label'       => __( 'How long a visitor’s browser keeps images and scripts', 'zinn-cache' ),
				'description' => __( 'Seconds. Applies to images, CSS and JavaScript only — never to pages. Set to 0 to leave this to your host.', 'zinn-cache' ),
				'min'         => 0,
				'max'         => 31536000,
				'default'     => 0,
			),
			array(
				'key'            => 'cache_logged_out_only',
				'type'           => 'toggle',
				'label'          => __( 'Signed-in visitors', 'zinn-cache' ),
				'checkbox_label' => __( 'Never serve a cached page to a signed-in visitor', 'zinn-cache' ),
				'description'    => __( 'On is the safe answer and the default. Turn it off only if you know every cached page is identical for every signed-in user — a membership site is the usual reason not to.', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'         => 'ttl_overrides',
				'type'        => 'repeater',
				'label'       => __( 'Different lifetimes for different content', 'zinn-cache' ),
				'description' => __( 'For example: a shop’s product pages for an hour, while everything else stays a week.', 'zinn-cache' ),
				'add_label'   => __( 'Add a rule', 'zinn-cache' ),
				'default'     => array(),
				'show_if'     => array( 'lscache_enabled' => true ),
				'fields'      => array(
					array(
						'key'     => 'post_type',
						'type'    => 'select',
						'label'   => __( 'Content type', 'zinn-cache' ),
						'choices' => array( __CLASS__, 'post_type_choices' ),
					),
					array(
						'key'   => 'ttl',
						'type'  => 'number',
						'label' => __( 'Seconds', 'zinn-cache' ),
						'min'   => Settings::TTL_MIN,
						'max'   => Settings::TTL_MAX,
					),
				),
			),
		);
	}

	/**
	 * The object-cache controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function object_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Object cache (Redis)', 'zinn-cache' ),
				'description' => __( 'Caches the results of database queries. It helps most on a busy shop or a site with a lot of signed-in traffic, where the page cache cannot.', 'zinn-cache' ),
			),
			array(
				'key'            => 'object_cache_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Object cache', 'zinn-cache' ),
				'checkbox_label' => __( 'Install the Redis object-cache drop-in', 'zinn-cache' ),
				'description'    => __( 'Needs the phpredis extension. If it is missing, the box above this screen says so.', 'zinn-cache' ),
				'default'        => false,
			),
			array(
				'key'     => 'redis_host',
				'type'    => 'text',
				'label'   => __( 'Redis host', 'zinn-cache' ),
				'default' => '127.0.0.1',
				'show_if' => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'     => 'redis_port',
				'type'    => 'number',
				'label'   => __( 'Redis port', 'zinn-cache' ),
				'min'     => 1,
				'max'     => 65535,
				'default' => 6379,
				'show_if' => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_database',
				'type'        => 'number',
				'label'       => __( 'Redis database', 'zinn-cache' ),
				'description' => __( 'Give each site on a shared Redis its own number, or they will clear each other’s cache.', 'zinn-cache' ),
				'min'         => 0,
				'max'         => 255,
				'default'     => 0,
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_password',
				'type'        => 'password',
				'secret'      => true,
				'label'       => __( 'Redis password', 'zinn-cache' ),
				'description' => __( 'Leave blank if your Redis does not need one.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_key_prefix',
				'type'        => 'text',
				'label'       => __( 'Key prefix', 'zinn-cache' ),
				'description' => __( 'An alternative to a separate database number when you cannot have one.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
		);
	}

	/**
	 * When the cache clears itself.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function purge_fields(): array {
		return array(
			array(
				'key'            => 'auto_purge_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Smart auto-purge', 'zinn-cache' ),
				'checkbox_label' => __( 'Clear affected pages automatically when content changes', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'            => 'purge_on_upgrade',
				'type'           => 'toggle',
				'label'          => __( 'After updates', 'zinn-cache' ),
				'checkbox_label' => __( 'Clear everything after a core, plugin or theme update', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'            => 'purge_on_comment',
				'type'           => 'toggle',
				'label'          => __( 'After a comment', 'zinn-cache' ),
				'checkbox_label' => __( 'Clear a post’s page when a comment on it is approved', 'zinn-cache' ),
				'description'    => __( 'Turn this off on a very busy comment section, where it can clear the same page hundreds of times an hour.', 'zinn-cache' ),
				'default'        => true,
			),
		);
	}

	/**
	 * What is never cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function exclusion_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Never cache these', 'zinn-cache' ),
				'description' => __( 'Sensible WordPress and WooCommerce defaults are always applied — cart, checkout, my-account and any signed-in session are never cached, whatever is below.', 'zinn-cache' ),
			),
			array(
				'key'         => 'exclude_uris',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Addresses', 'zinn-cache' ),
				'description' => __( 'One path per line, for example <code>/basket</code>. A trailing <code>*</code> matches everything under it.', 'zinn-cache' ),
				'rows'        => 5,
				'default'     => array(),
			),
			array(
				'key'         => 'exclude_query_keys',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Query parameters', 'zinn-cache' ),
				'description' => __( 'A page carrying one of these is never cached — for example a one-time preview token.', 'zinn-cache' ),
				'rows'        => 4,
				'default'     => array(),
			),
			array(
				'key'         => 'exclude_cookies',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Cookies', 'zinn-cache' ),
				'description' => __( 'A visitor carrying one of these is always served a fresh page.', 'zinn-cache' ),
				'rows'        => 4,
				'default'     => array(),
			),
		);
	}

	/**
	 * The site's public post types, as a choice list.
	 *
	 * @return array<string, string>
	 */
	public static function post_type_choices(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$out[ (string) $type->name ] = (string) ( $type->labels->name ?? $type->name );
		}
		return $out;
	}

	/**
	 * Clear everything, from the Tools row.
	 *
	 * @return array<string, string>
	 */
	public static function purge_all_now(): array {
		do_action( 'zinn_cache_purge_all' );
		return array(
			'kind'    => 'success',
			'message' => __( 'Every cached page has been discarded. They will be rebuilt as visitors arrive.', 'zinn-cache' ),
		);
	}

	/**
	 * Whether the caching this screen configures is actually happening.
	 *
	 * ⛔⛔ **THIS IS THE QUESTION THE OLD SCREEN COULD NOT ANSWER.** A tick against
	 * "Enable Redis object cache" says what was asked for; it says nothing about whether the
	 * extension exists, the drop-in is installed, or the server is LiteSpeed at all. A
	 * customer whose host does not run LiteSpeed had a screen that looked entirely correct
	 * and a cache that did nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings  = Settings::get();
		$litespeed = self::server_is_litespeed();
		$redis_ok  = extension_loaded( 'redis' );

		if ( $settings['lscache_enabled'] && ! $litespeed ) {
			return array(
				'state'   => 'degraded',
				'summary' => __( 'The page cache is on, but this server is not LiteSpeed.', 'zinn-cache' ),
				'reason'  => __( 'The cache headers are being sent and nothing is reading them, so pages are not actually being cached. Ask your host whether LiteSpeed is available, or move to Zinn® hosting where it is standard.', 'zinn-cache' ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		if ( $settings['object_cache_enabled'] && ! $redis_ok ) {
			return array(
				'state'   => 'degraded',
				'summary' => __( 'The object cache is on, but this server has no Redis support.', 'zinn-cache' ),
				'reason'  => __( 'The phpredis extension is not installed, so the drop-in cannot be used and WordPress is falling back to its own in-memory cache. Ask your host to enable phpredis.', 'zinn-cache' ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		if ( ! $settings['lscache_enabled'] && ! $settings['object_cache_enabled'] ) {
			return array(
				'state'   => 'standalone',
				'summary' => __( 'Nothing is being cached.', 'zinn-cache' ),
				'reason'  => __( 'Both caches are switched off, so this plugin is not making the site any faster.', 'zinn-cache' ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		return array(
			'state'   => 'connected',
			'summary' => __( 'Caching is active on this site.', 'zinn-cache' ),
			'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
		);
	}

	/**
	 * The rows under the status headline.
	 *
	 * @param array<string, mixed> $settings  Current settings.
	 * @param bool                 $litespeed Whether the server is LiteSpeed.
	 * @param bool                 $redis_ok  Whether phpredis is loaded.
	 * @return array<int, array<string, string>>
	 */
	private static function detail_rows( array $settings, bool $litespeed, bool $redis_ok ): array {
		return array(
			array(
				'label' => __( 'Web server', 'zinn-cache' ),
				'value' => $litespeed
					? __( 'LiteSpeed — page caching available', 'zinn-cache' )
					: __( 'not LiteSpeed — page caching unavailable', 'zinn-cache' ),
			),
			array(
				'label' => __( 'Redis support', 'zinn-cache' ),
				'value' => $redis_ok
					? __( 'phpredis is installed', 'zinn-cache' )
					: __( 'phpredis is not installed', 'zinn-cache' ),
			),
			array(
				'label' => __( 'Page lifetime', 'zinn-cache' ),
				'value' => (string) (int) $settings['lscache_ttl'] . 's',
			),
		);
	}

	/**
	 * Is this server LiteSpeed?
	 *
	 * ⛔ Read from the server signature rather than from a constant, and it returns FALSE
	 * when it cannot tell. A caching plugin reporting "all good" because it could not see is
	 * the reassuring-zero failure §2.44 is about.
	 *
	 * @return bool
	 */
	private static function server_is_litespeed(): bool {
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$software = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) );
			if ( false !== strpos( $software, 'litespeed' ) || false !== strpos( $software, 'openlitespeed' ) ) {
				return true;
			}
		}
		return isset( $_SERVER['X-LSCACHE'] ) || defined( 'LSCWP_V' );
	}

	/**
	 * Register the single settings option with its normalising sanitiser.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitise submitted settings through the pure normaliser.
	 *
	 * @param mixed $input Raw (unslashed) submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		return Settings::normalize( is_array( $input ) ? $input : array() );
	}

	/**
	 * Re-sync the object-cache drop-in whenever settings are saved.
	 *
	 * @return void
	 */
	public function on_settings_updated(): void {
		$settings = Settings::get();

		$result = ( new Object_Cache( $settings ) )->sync();
		if ( $result instanceof \WP_Error ) {
			set_transient( 'zinn_cache_notice', $result->get_error_message(), 30 );
		}

		Htaccess::sync( ! empty( $settings['lscache_enabled'] ) );
	}

	/**
	 * Handle the "Purge everything now" action.
	 *
	 * @return void
	 */
	public function handle_purge_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to purge the cache.', 'zinn-cache' ) );
		}

		check_admin_referer( 'zinn_cache_purge_all' );

		$this->lscache->purge_all();
		set_transient( 'zinn_cache_notice', __( 'Cache purge requested.', 'zinn-cache' ), 30 );

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Show any queued admin notice, plus environment warnings.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( 'zinn_cache_notice' );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( 'zinn_cache_notice' );
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( $notice )
			);
		}

		if ( $this->object_cache->is_foreign_dropin() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Zinn® Cache: another object-cache drop-in is installed, so the Redis object cache is not managed by this plugin.', 'zinn-cache' )
			);
		}
	}


	/**
	 * Render the environment/status panel at the top of the screen.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return void
	 */
	private function render_status( array $settings ): void {
		$lscache_state = $this->lscache->is_available()
			? __( 'detected', 'zinn-cache' )
			: __( 'not detected', 'zinn-cache' );

		if ( ! $this->object_cache->is_redis_extension_available() ) {
			$object_state = __( 'phpredis extension missing', 'zinn-cache' );
		} elseif ( $this->object_cache->is_enabled() ) {
			$object_state = __( 'active', 'zinn-cache' );
		} else {
			$object_state = __( 'inactive', 'zinn-cache' );
		}

		printf(
			'<p>%1$s <strong>%2$s</strong> &nbsp;|&nbsp; %3$s <strong>%4$s</strong></p>',
			esc_html__( 'LiteSpeed cache:', 'zinn-cache' ),
			esc_html( $lscache_state ),
			esc_html__( 'Redis object cache:', 'zinn-cache' ),
			esc_html( $object_state )
		);

		unset( $settings );
	}

	/**
	 * Render a boolean setting as a hidden "0" + checkbox "1" pair.
	 *
	 * The hidden field guarantees the key is always submitted, so unchecking a
	 * box reliably stores `false` (avoiding the classic missing-checkbox pitfall).
	 *
	 * @param string $key     Settings key.
	 * @param bool   $checked Whether the box is currently checked.
	 * @param string $label   Label shown beside the checkbox.
	 * @return void
	 */
	private function checkbox( string $key, bool $checked, string $label ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		printf(
			'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
