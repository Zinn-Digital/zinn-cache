<?php
/**
 * Reporting site changes to the Zinn control plane.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Theme;

/**
 * Reports what changed on this site so the panel can show it on a speed timeline.
 *
 * The panel plots a site's Core Web Vitals over time. On its own that answers "is it
 * slower?" and never "why?" — which is the question a customer actually has. This class
 * supplies the "why": plugin activations, plugin/theme/core updates and theme switches,
 * each stamped with the time it happened on the site, so a score change lines up with the
 * change that caused it.
 *
 * Every one of these hooks was already registered by {@see Purge_Controller}, and every
 * one of them called `purge_all` and nothing else — the signal was being observed and
 * thrown away.
 *
 * Configured through `ZINN_SITE_EVENTS_URL` and `ZINN_CACHE_PANEL_SECRET` (per-site
 * constants written by the deploy footprint). Absent config = no-op, so the plugin works
 * standalone exactly as it does today.
 *
 * The request is non-blocking with a short timeout, and there is no retry: a customer's
 * page load must never wait on our panel, and a plugin that breaks a site when the panel
 * is down is a plugin we cannot ship. Duplicate and lost deliveries are both expected —
 * the panel de-duplicates on its side.
 */
final class Event_Reporter {

	/**
	 * Plugin versions captured before an upgrade, keyed by plugin file.
	 *
	 * `upgrader_process_complete` fires *after* the new files are in place, so the old
	 * version is only obtainable from a hook that ran before it. Without this the
	 * timeline would say "updated to 5.9" and never "from 5.8", which is most of the
	 * value when you are trying to work out what broke.
	 *
	 * @var array<string,string>
	 */
	private array $versions_before = array();

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( $this, 'on_theme_switched' ), 10, 3 );

		// Captures versions BEFORE the upgrade overwrites them. Priority 10 on a filter
		// that must return its input untouched — this is an observer, not a filter.
		add_filter( 'upgrader_pre_install', array( $this, 'capture_versions' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );
	}

	/**
	 * Whether the site has been given somewhere to report to.
	 *
	 * @return bool
	 */
	private function is_configured(): bool {
		return defined( 'ZINN_SITE_EVENTS_URL' )
			&& defined( 'ZINN_CACHE_PANEL_SECRET' )
			&& '' !== (string) constant( 'ZINN_SITE_EVENTS_URL' )
			&& '' !== (string) constant( 'ZINN_CACHE_PANEL_SECRET' );
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_activated( string $plugin ): void {
		$this->report( array( $this->plugin_event( 'plugin_activated', $plugin ) ) );
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_deactivated( string $plugin ): void {
		$this->report( array( $this->plugin_event( 'plugin_deactivated', $plugin ) ) );
	}

	/**
	 * The active theme changed.
	 *
	 * @param string        $new_name  Name of the new theme.
	 * @param WP_Theme|null $new_theme The new theme.
	 * @param WP_Theme|null $old_theme The theme being replaced.
	 * @return void
	 */
	public function on_theme_switched( string $new_name, $new_theme = null, $old_theme = null ): void {
		$this->report(
			array(
				array(
					'kind'         => 'theme_switched',
					'object_name'  => $new_name,
					'object_slug'  => $new_theme instanceof WP_Theme ? (string) $new_theme->get_stylesheet() : '',
					// The theme being replaced is the "from", which is what makes a
					// switch readable on a timeline at all.
					'from_version' => $old_theme instanceof WP_Theme ? (string) $old_theme->get( 'Name' ) : '',
					'to_version'   => $new_theme instanceof WP_Theme ? (string) $new_theme->get( 'Version' ) : '',
					'at'           => time(),
				),
			)
		);
	}

	/**
	 * Record installed versions before an upgrade overwrites them.
	 *
	 * A filter used purely as an observer: it returns `$response` unchanged. Returning
	 * anything else here would ABORT the upgrade, so the early return and the untouched
	 * return value are both load-bearing.
	 *
	 * @param bool|\WP_Error $response Installation response so far.
	 * @param array<mixed>   $hook_extra Extra arguments describing what is being installed.
	 * @return bool|\WP_Error The response, unchanged.
	 */
	public function capture_versions( $response, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return $response;
		}

		$files = array();
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$files[] = $hook_extra['plugin'];
		}
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $file ) {
				if ( is_string( $file ) ) {
					$files[] = $file;
				}
			}
		}

		foreach ( $files as $file ) {
			$data = $this->plugin_data( $file );
			if ( '' !== $data['version'] ) {
				$this->versions_before[ $file ] = $data['version'];
			}
		}

		return $response;
	}

	/**
	 * Core, a plugin or a theme finished updating.
	 *
	 * @param mixed        $upgrader The upgrader instance (unused).
	 * @param array<mixed> $options  What was updated.
	 * @return void
	 */
	public function on_upgrade_complete( $upgrader, $options ): void {
		unset( $upgrader );
		if ( ! is_array( $options ) ) {
			return;
		}

		$type   = isset( $options['type'] ) ? (string) $options['type'] : '';
		$action = isset( $options['action'] ) ? (string) $options['action'] : '';
		if ( 'update' !== $action && 'install' !== $action ) {
			return;
		}

		$events = array();

		if ( 'core' === $type ) {
			$events[] = array(
				'kind'        => 'wp_core_update',
				'object_name' => 'WordPress',
				'object_slug' => 'wordpress',
				'to_version'  => (string) get_bloginfo( 'version' ),
				'at'          => time(),
			);
		} elseif ( 'plugin' === $type ) {
			foreach ( $this->targets( $options, 'plugin', 'plugins' ) as $file ) {
				$events[] = $this->plugin_event( 'plugin_updated', $file );
			}
		} elseif ( 'theme' === $type ) {
			foreach ( $this->targets( $options, 'theme', 'themes' ) as $stylesheet ) {
				$theme    = wp_get_theme( $stylesheet );
				$events[] = array(
					'kind'        => 'theme_updated',
					'object_name' => (string) $theme->get( 'Name' ),
					'object_slug' => $stylesheet,
					'to_version'  => (string) $theme->get( 'Version' ),
					'at'          => time(),
				);
			}
		}

		$this->report( $events );
	}

	/**
	 * The plugins/themes an upgrade touched.
	 *
	 * WordPress uses a singular key for a single item and a plural one for a bulk run,
	 * and a bulk run of forty plugins fires ONE `upgrader_process_complete` describing
	 * all of them — which is why the panel accepts a batch.
	 *
	 * @param array<mixed> $options  Upgrade options.
	 * @param string       $singular Singular key.
	 * @param string       $plural   Plural key.
	 * @return string[]
	 */
	private function targets( array $options, string $singular, string $plural ): array {
		$out = array();
		if ( isset( $options[ $singular ] ) && is_string( $options[ $singular ] ) ) {
			$out[] = $options[ $singular ];
		}
		if ( isset( $options[ $plural ] ) && is_array( $options[ $plural ] ) ) {
			foreach ( $options[ $plural ] as $item ) {
				if ( is_string( $item ) ) {
					$out[] = $item;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * One plugin event, with the name a human recognises and the slug that is stable.
	 *
	 * @param string $kind   Event kind.
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return array<string,mixed>
	 */
	private function plugin_event( string $kind, string $plugin ): array {
		$data = $this->plugin_data( $plugin );
		$from = isset( $this->versions_before[ $plugin ] ) ? $this->versions_before[ $plugin ] : '';

		return array(
			'kind'         => $kind,
			'object_name'  => $data['name'],
			// `contact-form-7/wp-contact-form-7.php` → `contact-form-7`. The directory is
			// the identity WordPress.org uses; the entry file is not stable across
			// releases of some plugins.
			'object_slug'  => $this->plugin_slug( $plugin ),
			'from_version' => 'plugin_updated' === $kind ? $from : '',
			'to_version'   => $data['version'],
			'at'           => time(),
		);
	}

	/**
	 * A plugin's display name and version, tolerating a file that no longer exists.
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return array{name:string,version:string}
	 */
	private function plugin_data( string $plugin ): array {
		$path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $plugin : '';
		if ( '' === $path || ! function_exists( 'get_plugin_data' ) || ! file_exists( $path ) ) {
			// A deactivate-then-delete leaves nothing to read. The slug is still the
			// truth, so report that rather than dropping the event entirely — "something
			// was deactivated here" is exactly what the timeline is for.
			return array(
				'name'    => $this->plugin_slug( $plugin ),
				'version' => '',
			);
		}

		$data = get_plugin_data( $path, false, false );

		return array(
			'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : $this->plugin_slug( $plugin ),
			'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
		);
	}

	/**
	 * `contact-form-7/wp-contact-form-7.php` → `contact-form-7`.
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return string
	 */
	private function plugin_slug( string $plugin ): string {
		$parts = explode( '/', $plugin );

		return (string) $parts[0];
	}

	/**
	 * Send a batch to the panel: signed, non-blocking, fire and forget.
	 *
	 * The PHP version rides along on every report. The panel raises a
	 * `php_version_changed` event when it differs from the last value it saw, which
	 * detects a change by comparison rather than by instrumenting whatever changed it —
	 * and therefore also catches a change made outside the platform.
	 *
	 * @param array<int,array<string,mixed>> $events Events to report.
	 * @return void
	 */
	private function report( array $events ): void {
		// An empty batch is a no-op rather than a PHP-version-only heartbeat. A heartbeat
		// would need a hook on a request path, and an outbound HTTP call — even a
		// non-blocking one — on every admin page load is not a cost this plugin gets to
		// impose on a customer's site. The version therefore rides on real events, which
		// on a live WordPress means core and plugin updates: frequent enough to notice a
		// PHP change, and free.
		if ( array() === $events || ! $this->is_configured() ) {
			return;
		}

		$url    = (string) constant( 'ZINN_SITE_EVENTS_URL' );
		$secret = (string) constant( 'ZINN_CACHE_PANEL_SECRET' );

		$payload = (string) wp_json_encode(
			array(
				'site'        => home_url( '/' ),
				'php_version' => PHP_VERSION,
				'events'      => array_values( $events ),
			)
		);

		$timestamp = (string) time();
		// The timestamp is INSIDE the signed material, which is what bounds a replay:
		// a signature over the body alone could be reused for ever with a fresh header.
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
