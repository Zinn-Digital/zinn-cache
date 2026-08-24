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

		$settings = Settings::get();

		$lscache = new Lscache( $settings );
		$lscache->register();

		( new Purge_Controller( $lscache, $settings ) )->register();
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
		// One-click wp-admin login from the Zinn dashboard. ⛔ Registers NOTHING unless the
		// deploy footprint wrote `ZINN_SSO_KEY` and `ZINN_SITE_ID` into wp-config.php — an
		// SSO route with no key would have to decide what an empty key means, and every
		// wrong answer to that is "anyone can log in as the administrator".
		( new Sso() )->register();
		( new Updater( ZINN_CACHE_FILE, ZINN_CACHE_VERSION ) )->register();

		if ( is_admin() ) {
			( new Admin( new Object_Cache( $settings ), $lscache ) )->register();
		}
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
		// ⛔ A cron event survives deactivation unless it is removed. Leaving it would
		// keep firing a hook whose handler no longer exists — a warning in the customer's
		// log every hour, for ever, from a plugin they turned off.
		( new Link_Scanner() )->unschedule();
	}
}
