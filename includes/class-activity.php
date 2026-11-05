<?php
/**
 * The activity log: who changed which setting, and who emptied the cache, and when.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last {@see self::ROWS} things done to the cache, newest first.
 *
 * ⛔ It stores setting KEYS, never values: a setting can be a secret (an API token), and an
 * activity row is shown to every administrator. The label is resolved when the row is
 * shown, so a renamed setting reads its new name.
 *
 * ⛔ The option is not autoloaded and is capped, so a busy site never grows it and never
 * loads it on a page view.
 */
final class Activity {

	/**
	 * Option holding the rows.
	 */
	public const OPTION = 'zinn_cache_activity';

	/**
	 * Rows kept.
	 */
	public const ROWS = 100;

	/**
	 * Hook the events the log records.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'zinn_admin_ui_saved_zinn_cache', array( __CLASS__, 'on_saved' ), 10, 3 );
		add_action( 'zinn_cache_purged_all', array( __CLASS__, 'on_purged_all' ), 10, 1 );
		add_action( 'zinn_cache_refresh_armed', array( __CLASS__, 'on_refresh_armed' ), 10, 1 );
	}

	/**
	 * A settings save: record the keys that changed (none changed: nothing recorded).
	 *
	 * @param mixed $stored   The stored settings after the save.
	 * @param mixed $tab      The tab saved.
	 * @param mixed $previous The stored settings before the save.
	 * @return void
	 */
	public static function on_saved( $stored, $tab = '', $previous = array() ): void {
		if ( ! is_array( $stored ) || ! is_array( $previous ) || ! class_exists( '\Zinn_Cache_Admin_UI' ) ) {
			return;
		}
		$keys = \Zinn_Cache_Admin_UI::changed_keys( $previous, $stored );
		if ( array() === $keys ) {
			return;
		}
		self::add( 'settings', array_map( 'strval', $keys ), (string) $tab );
	}

	/**
	 * The whole cache was emptied.
	 *
	 * @param mixed $reason Why: manual, deploy, update or api.
	 * @return void
	 */
	public static function on_purged_all( $reason = '' ): void {
		self::add( 'purge_all', array(), sanitize_key( (string) $reason ) );
	}

	/**
	 * A paced refresh started.
	 *
	 * @param mixed $reason Why.
	 * @return void
	 */
	public static function on_refresh_armed( $reason = '' ): void {
		self::add( 'refresh', array(), sanitize_key( (string) $reason ) );
	}

	/**
	 * Append one row, newest first, capped.
	 *
	 * @param string             $type   settings, purge_all or refresh.
	 * @param array<int, string> $keys   Changed setting keys (settings rows only).
	 * @param string             $detail The tab, or the reason.
	 * @return void
	 */
	public static function add( string $type, array $keys, string $detail ): void {
		// A cron run or WP-CLI has no signed-in user: the row says "System".
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$row  = array(
			'at'     => time(),
			'type'   => $type,
			'keys'   => array_values( $keys ),
			'detail' => $detail,
			'user'   => ( $user instanceof \WP_User && $user->exists() ) ? (string) $user->user_login : '',
		);
		$rows = self::rows();
		array_unshift( $rows, $row );
		update_option( self::OPTION, array_slice( $rows, 0, self::ROWS ), false );
	}

	/**
	 * The stored rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * One row's "what happened" in words.
	 *
	 * @param array<string, mixed> $row A stored row.
	 * @return string
	 */
	public static function describe( array $row ): string {
		$detail = (string) ( $row['detail'] ?? '' );
		switch ( (string) ( $row['type'] ?? '' ) ) {
			case 'settings':
				$labels = array();
				foreach ( (array) ( $row['keys'] ?? array() ) as $key ) {
					$labels[] = class_exists( '\Zinn_Cache_Admin_UI' ) ? \Zinn_Cache_Admin_UI::field_label( (string) $key ) : (string) $key;
				}
				/* translators: %s: comma-separated setting names. */
				return sprintf( __( 'Changed: %s', 'zinn-cache' ), implode( ', ', $labels ) );
			case 'purge_all':
				/* translators: %s: why the cache was emptied (manual, deploy, update or api). */
				return sprintf( __( 'Emptied the whole cache (%s)', 'zinn-cache' ), $detail );
			case 'refresh':
				/* translators: %s: why the refresh started. */
				return sprintf( __( 'Started a paced refresh (%s)', 'zinn-cache' ), $detail );
		}
		return $detail;
	}

	/**
	 * The "Activity" table for the Status screen (empty string when nothing has happened).
	 *
	 * @param int $limit Rows shown.
	 * @return string
	 */
	public static function panel_html( int $limit = 20 ): string {
		$rows = array_slice( self::rows(), 0, max( 1, $limit ) );
		if ( array() === $rows ) {
			return '';
		}
		$html = '<h2>' . esc_html__( 'Activity', 'zinn-cache' ) . '</h2><table class="widefat striped zinn-cache-activity"><thead><tr><th>'
			. esc_html__( 'When', 'zinn-cache' ) . '</th><th>' . esc_html__( 'Who', 'zinn-cache' ) . '</th><th>'
			. esc_html__( 'What', 'zinn-cache' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$who   = (string) ( $row['user'] ?? '' );
			$html .= '<tr><td>' . esc_html( sprintf( /* translators: %s: how long ago. */ __( '%s ago', 'zinn-cache' ), human_time_diff( (int) ( $row['at'] ?? 0 ) ) ) ) . '</td><td>'
				. esc_html( '' === $who ? __( 'System', 'zinn-cache' ) : $who ) . '</td><td>'
				. esc_html( self::describe( $row ) ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}
