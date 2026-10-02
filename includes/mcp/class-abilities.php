<?php
/**
 * Zinn Cache abilities: cache status, purging and the settings, for AI agents (MCP) and REST.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Mcp;

use Zinn\Cache\McpKit\Ability;
use Zinn\Cache\McpKit\Rest_Bridge;
use Zinn\Cache\McpKit\Server;
use Zinn\Cache\Object_Cache;
use Zinn\Cache\Page_Cache;
use Zinn\Cache\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The free abilities (owner, 2026-09-30: purge and status free; warm-up and the slow-query finder
 * in Pro, which Zinn Cache Pro adds on the `zinn_cache_mcp_abilities` action).
 *
 * Every action a site owner has on the Cache screen is here: the status, purging (the REST purge
 * route, bridged), reading and changing every setting, putting them back to their defaults, and
 * the diagnostics report. The Redis password is write-only: it is never returned.
 */
final class Abilities {

	/** The ability category. */
	public const CATEGORY = 'zinn-cache';

	/** Who may use them: the same capability as the Cache screen and the purge route. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Boot the MCP kit for this plugin.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Server::boot(
			array(
				'id'             => 'zinn-cache',
				'rest_namespace' => 'zinn-cache/v1',
				'name'           => 'Zinn® Cache',
				'description'    => static fn(): string => __( 'Check and purge this site\'s cache and change its cache settings with Zinn® Cache.', 'zinn-cache' ),
				'version'        => ZINN_CACHE_VERSION,
				'capability'     => self::CAPABILITY,
				'category'       => array(
					'slug'        => self::CATEGORY,
					'label'       => static fn(): string => __( 'Zinn® Cache', 'zinn-cache' ),
					'description' => static fn(): string => __( 'Page and object cache status, purging and settings.', 'zinn-cache' ),
				),
				'enabled'        => static fn(): bool => (bool) ( Settings::get()['mcp'] ?? true ),
				'abilities'      => array( self::class, 'register' ),
				'vendor_dir'     => ZINN_CACHE_DIR . 'vendor/wordpress',
				'docs'           => array(
					'guide'      => 'https://zinndigital.com/wordpress-plugins/zinn-cache/mcp',
					'developers' => 'https://zinndigital.com/wordpress-plugins/zinn-cache/mcp-api',
				),
			)
		);
	}

	/**
	 * Register the abilities (on `wp_abilities_api_init`).
	 *
	 * @return void
	 */
	public static function register(): void {
		$can = static fn(): bool => current_user_can( self::CAPABILITY );

		Ability::register(
			'zinn-cache/get-status',
			array(
				'edition'             => 'free',
				'capability'          => self::CAPABILITY,
				'label'               => __( 'Read the cache status', 'zinn-cache' ),
				'description'         => __( 'Returns whether the page cache and the object cache are on and working, how many pages are cached, and the object cache\'s connection and hit numbers.', 'zinn-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'status' ),
				'permission_callback' => $can,
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		Ability::register(
			'zinn-cache/get-settings',
			array(
				'edition'             => 'free',
				'capability'          => self::CAPABILITY,
				'label'               => __( 'Read the cache settings', 'zinn-cache' ),
				'description'         => __( 'Returns every cache setting. The Redis password is never returned, only whether one is saved.', 'zinn-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'settings' ),
				'permission_callback' => $can,
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		Ability::register(
			'zinn-cache/update-settings',
			array(
				'edition'             => 'free',
				'capability'          => self::CAPABILITY,
				'label'               => __( 'Change the cache settings', 'zinn-cache' ),
				'description'         => __( 'Changes the settings you send and keeps the rest (page cache, how long pages stay cached, the object cache and its Redis connection, purging rules and exclusions). Values are checked and corrected exactly as on the Cache screen.', 'zinn-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::settings_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'update_settings' ),
				'permission_callback' => $can,
				'annotations'         => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			)
		);

		Ability::register(
			'zinn-cache/reset-settings',
			array(
				'edition'             => 'free',
				'capability'          => self::CAPABILITY,
				'label'               => __( 'Put the cache settings back to their defaults', 'zinn-cache' ),
				'description'         => __( 'Puts every cache setting back to its default, as the Reset button does. The saved Redis password is removed too.', 'zinn-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'reset_settings' ),
				'permission_callback' => $can,
				'annotations'         => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			)
		);

		Ability::register(
			'zinn-cache/get-diagnostics',
			array(
				'edition'             => 'free',
				'capability'          => self::CAPABILITY,
				'label'               => __( 'Read the diagnostics report', 'zinn-cache' ),
				'description'         => __( 'Returns the diagnostics report support asks for: versions, server limits, the cache state and recent errors. It holds no passwords or keys.', 'zinn-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn(): array => \Zinn_Cache_Diagnostics::collect(),
				'permission_callback' => $can,
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		// The purge route (and any other REST route of the plugin), as abilities of their own.
		require_once __DIR__ . '/class-rest-map.php';
		Rest_Bridge::register( Rest_Map::entries(), self::CATEGORY );

		/**
		 * Fires after Zinn Cache registers its abilities: Zinn Cache Pro adds its own here.
		 */
		do_action( 'zinn_cache_mcp_abilities' );
	}

	/**
	 * An input schema with no fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function no_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * The settings an agent may change, typed from the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_schema(): array {
		$properties = array();
		foreach ( Settings::defaults() as $key => $default ) {
			if ( 'mcp' === $key ) {
				continue; // The switch that lets agents in is the site owner's, on the screen.
			}
			if ( is_bool( $default ) ) {
				$properties[ $key ] = array( 'type' => 'boolean' );
			} elseif ( is_int( $default ) ) {
				$properties[ $key ] = array( 'type' => 'integer' );
			} elseif ( is_array( $default ) ) {
				$properties[ $key ] = array( 'type' => array( 'array', 'object', 'string' ) );
			} else {
				$properties[ $key ] = array( 'type' => 'string' );
			}
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Zinn-cache/get-status (the same report as `wp zinn-cache status`).
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings                   = Settings::get();
		$report                     = ( new Object_Cache( $settings ) )->status_report();
		$pages                      = Page_Cache::stats();
		$report['page_cache']       = empty( $settings['lscache_enabled'] ) ? 'off' : ( Page_Cache::is_ready() ? 'disk' : 'server' );
		$report['page_cache_pages'] = $pages['pages'];

		return $report;
	}

	/**
	 * Zinn-cache/get-settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$settings                       = Settings::get();
		$settings['redis_password_set'] = '' !== (string) ( $settings['redis_password'] ?? '' );
		unset( $settings['redis_password'], $settings['mcp'] );

		return $settings;
	}

	/**
	 * Zinn-cache/update-settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function update_settings( array $input ): array {
		unset( $input['mcp'] );
		Settings::save( array_merge( Settings::get(), $input ) );

		return self::settings();
	}

	/**
	 * Zinn-cache/reset-settings (keeps the site owner's MCP switch, or this call would lock agents out).
	 *
	 * @return array<string, mixed>
	 */
	public static function reset_settings(): array {
		$mcp = (bool) ( Settings::get()['mcp'] ?? true );
		Settings::save( array_merge( Settings::defaults(), array( 'mcp' => $mcp ) ) );

		return self::settings();
	}

	/**
	 * The Cache screen's MCP details (the switch is the tab's own field).
	 *
	 * @return string
	 */
	public static function panel_html(): string {
		return Server::panel_html();
	}
}
