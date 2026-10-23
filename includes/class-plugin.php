<?php
/**
 * Plugin bootstrap / orchestrator.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components together and owns the activation lifecycle.
 */
final class Plugin {

	/**
	 * The daily server-cache detection hook.
	 */
	public const PROBE_HOOK = 'zinn_cache_probe';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot the plugin once, on `plugins_loaded`.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * Register hooks and components.
	 *
	 * @return void
	 */
	private function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		// The licensing SDK's opt-in screen and notices, in the site's language (57 locales).
		Freemius_I18n::register();

		$settings = Settings::get();

		$lscache = new Lscache( $settings );
		$lscache->register();

		// A plugin update ships a new drop-in, but WordPress runs the COPY in wp-content. Refresh
		// an older copy of our own drop-in after an update and on admin page loads (two 1 KB header
		// reads; nothing is written unless the bundled revision is newer).
		$refresh = static function () use ( $settings ): void {
			( new Object_Cache( $settings ) )->refresh_if_stale();
		};
		add_action( 'admin_init', $refresh );
		add_action( 'upgrader_process_complete', $refresh );

		// The standard page cache for servers that are not LiteSpeed (LOCKED PLAN §1). Checked on
		// admin page loads (a few stat() calls once it is installed) and rewritten on every save.
		$page_cache = static function () use ( $lscache ): void {
			// ⛔ Not on admin-ajax: a loopback (the warm-up runner's) can be in flight while this
			// plugin is being deactivated, and would put back what deactivation removed (D28306).
			if ( wp_doing_ajax() ) {
				return;
			}
			self::detect_before_install( $lscache );
			Page_Cache::sync( Settings::get(), $lscache->page_cache_is_elsewhere() );
		};
		add_action( 'admin_init', $page_cache );
		// Move a no-cache block written below WordPress's catch-all (before 1.7.4) above it.
		if ( ! empty( $settings['lscache_enabled'] ) ) {
			add_action( 'admin_init', array( Htaccess::class, 'repair_position' ) );
		}
		add_action(
			'update_option_' . Settings::OPTION,
			static function () use ( $lscache ): void {
				$settings = Settings::get();
				if ( Page_Cache::is_ready() ) {
					Page_Cache::write_config( $settings );
				}
				Page_Cache::sync( $settings, ( new Lscache( $settings ) )->page_cache_is_elsewhere() );
			}
		);

		// Which caches answer in front of the site: checked once a day (and from the status screen),
		// so a server cache added later takes over from the disk cache by itself.
		add_action( self::PROBE_HOOK, array( Server\Probe::class, 'run' ) );
		add_action( self::PROBE_HOOK . '_once', array( Server\Probe::class, 'run' ) );
		// Not on LiteSpeed: its own cache is the page cache there, and the answer never changes.
		if ( ! wp_installing() && ! $lscache->is_litespeed_server() && ! Lscache::is_cli() && ! wp_next_scheduled( self::PROBE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PROBE_HOOK );
		}
		// The paced refresh that replaces "purge everything" (updates, activations, site-wide changes).
		( new Refresh( $lscache, $settings ) )->register();
		// Lighter pages: Heartbeat, web fonts, preconnect, CDN address (each off until switched on).
		( new Frontend_Tweaks( $settings ) )->register();

		$purger = new Purge_Controller( $lscache, $settings );
		$purger->register();
		// ⛔ `class_exists` (autoloads): a site that receives this file before `class-seo-purge.php`
		// (a partial copy, a mixed-version install) must keep serving pages, not die at bootstrap.
		// A fatal here broke WordPress installs on the fleet (Zinn Cache 1.10.0, 2026-10-11).
		if ( ! empty( $settings['auto_purge_enabled'] ) && class_exists( Seo_Purge::class ) ) {
			// A change made in Zinn® SEO (a title, a redirect, a site-wide setting) saves no post.
			( new Seo_Purge( $purger ) )->register();
		}
		// Reports plugin/theme/core changes to the panel so the speed timeline can
		// explain a score change (docs/85 §6.2). It hooks the SAME WordPress events
		// `Purge_Controller` already listens to — those were purging the cache and
		// discarding the signal. No-op unless the deploy footprint configured it.
		( new Event_Reporter() )->register();
		// The outbound-link scan (#1799). ⛔ Registers a CRON hook and nothing else — it
		// never runs on a page load, because reading every post and regexing its content
		// is expensive and this is a customer's live site. No-op, and actively
		// unscheduled, unless the deploy footprint configured it.
		( new Link_Scanner() )->register();
		( new Rest_Controller( $lscache ) )->register();
		// AI agents (MCP, docs/901): the cache's actions as WordPress abilities, through the shared
		// MCP kit and the bundled WordPress MCP adapter. Signed-in users only, each checked per call.
		require_once ZINN_CACHE_DIR . 'includes/mcp-kit/load.php';
		require_once ZINN_CACHE_DIR . 'includes/mcp/class-abilities.php';
		Mcp\Abilities::boot();
		// ⛔ No one-click wp-admin login is registered here. It lived here until 2026-09-18
		// and has moved to `zinn-footprint`, which is app-only and never submitted.
		//
		// WordPress.org refused this plugin for it, correctly (review R/RMT zinn-cache 18Sep26):
		// `Sso::handle_login` set an authentication cookie and logged into an administrator
		// account on a signed request from our dashboard. That is REMOTE ADMINISTRATION by their
		// definition however carefully it is implemented, and a plugin that offers it cannot be
		// listed without an independent security audit of the infrastructure behind it.
		//
		// The audit is not the reason it moved. A CACHING plugin has no business logging anyone
		// in: the capability belongs to the platform, not to the thing that serves pages fast, and
		// shipping it here put it in front of every WordPress.org user who only wanted a cache.
		//
		// `zinn-footprint` is app-only and never submitted (owner ruling 2026-08-24), so the
		// capability keeps working for our own customers and leaves the public artefact entirely.
		// DO NOT re-add it here.
		( new Updater( ZINN_CACHE_FILE, ZINN_CACHE_VERSION ) )->register();
		Cli::register( $lscache, $purger );

		if ( is_admin() ) {
			( new Admin( new Object_Cache( $settings ), $lscache ) )->register();
			Upsell::register();
		}
	}

	/**
	 * Find out whether a server page cache is in front of the site BEFORE the disk cache would be
	 * installed behind it, and schedule a check for a site that already has the disk cache.
	 *
	 * ⛔ Only a POSITIVE detection keeps the disk cache out (or removes it). A detection that could
	 * not see (`blind`) leaves the site exactly as it was: a customer on Apache or nginx with no
	 * server cache keeps the disk page cache, on install and on upgrade alike.
	 *
	 * @param Lscache $lscache Page-cache controller.
	 * @return void
	 */
	private static function detect_before_install( Lscache $lscache ): void {
		$settings = Settings::get();
		if ( empty( $settings['lscache_enabled'] ) || 'auto' !== (string) ( $settings['server_cache'] ?? 'auto' ) || defined( 'ZINN_CACHE_SERVER_CACHE' )
			|| null !== Server\Probe::last() || $lscache->is_litespeed_server() || $lscache->is_lscache_plugin_active() ) {
			return;
		}
		if ( Page_Cache::is_ready() ) {
			// Already serving pages: never block an admin screen on a loopback for it.
			if ( ! wp_next_scheduled( self::PROBE_HOOK . '_once' ) ) {
				wp_schedule_single_event( time(), self::PROBE_HOOK . '_once' );
			}
			return;
		}
		Server\Probe::run();
	}

	/**
	 * Load the plugin's translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'zinn-cache',
			false,
			dirname( plugin_basename( ZINN_CACHE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation: seed default settings.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		Htaccess::sync( (bool) Settings::get_value( 'lscache_enabled' ) );
	}

	/**
	 * Deactivation: remove our object-cache drop-in and the managed .htaccess
	 * block so nothing is left dangling.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		( new Object_Cache( Settings::get() ) )->disable();
		Htaccess::remove();
		Page_Cache::uninstall();
		Refresh::stop();
		wp_clear_scheduled_hook( self::PROBE_HOOK );
		wp_clear_scheduled_hook( self::PROBE_HOOK . '_once' );
		wp_clear_scheduled_hook( Server\Server_Cache::CRON_HOOK );
		// ⛔ A cron event survives deactivation unless it is removed. Leaving it would
		// keep firing a hook whose handler no longer exists — a warning in the customer's
		// log every hour, for ever, from a plugin they turned off.
		( new Link_Scanner() )->unschedule();
	}
}
