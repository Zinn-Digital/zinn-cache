<?php
/**
 * The licensing SDK's own screens and notices, in the site's language.
 *
 * @package Zinn\Cache
 */

declare( strict_types = 1 );

namespace Zinn\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes EVERY string the licensing SDK can show through this plugin's own catalogues.
 *
 * The SDK translates itself with its own `freemius` text domain, which ships a catalogue for
 * only 13 of the 57 languages this plugin is sold in. So on an Arabic admin the sticky opt-in
 * notice ("We made a few tweaks to the plugin, Opt in to make … better!"), its "Dismiss" link,
 * the licence and pricing screens all rendered in English on every admin page.
 *
 * The strings come from `freemius-strings.php`, which is GENERATED from the bundled SDK by
 * `wp/bin/freemius-i18n.php` (never typed), and a unit test fails if an SDK string is missing
 * from it. Two supported mechanisms, and nothing under vendor/ is edited:
 *
 * 1. `fs_override_i18n( $strings, 'zinn-cache' )` for every key the SDK looks up for THIS plugin's
 *    module. It wins over the SDK's own catalogue, and only ever affects this plugin.
 * 2. Strings the SDK looks up with no module slug (the notice's "Dismiss", strings whose key
 *    differs between screens) reach WordPress as the `freemius` text domain. Those are answered
 *    from our catalogue ONLY when the SDK's own catalogue had no translation (it returned the
 *    English unchanged), so a language the SDK already translates is never overridden, and
 *    another plugin's copy of the SDK only ever gains a translation of the same sentence.
 */
final class Freemius_I18n {

	/**
	 * The generated table, loaded once per request.
	 *
	 * @var array{keyed:list<array{0:string,1:string}>,unkeyed:list<array{0:string,1:string,2:string}>,label:list<string>,mtype:list<list<string|int>>}|null
	 */
	private static ?array $table = null;

	/**
	 * "context\x04English" => our translation, for the gettext gap-fill.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $unkeyed = null;

	/**
	 * Register the hooks. Admin-only: the SDK renders nothing a visitor sees.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		// `init`, not earlier: `__()` before `init` loads the catalogue too early (WP 6.7+ notice).
		add_action( 'init', array( self::class, 'override' ), 1 );
		// Before the SDK's own `admin_init` (priority 10), which is what re-adds the notice.
		add_action( 'admin_init', array( self::class, 'refresh_stored_notice' ), 5 );
		add_filter( 'gettext_freemius', array( self::class, 'unkeyed' ), 10, 2 );
		add_filter( 'gettext_with_context_freemius', array( self::class, 'unkeyed_with_context' ), 10, 3 );
	}

	/**
	 * Hand the SDK our translation of every key it looks up for this module.
	 *
	 * @return void
	 */
	public static function override(): void {
		if ( ! function_exists( 'fs_override_i18n' ) ) {
			return;
		}
		fs_override_i18n( self::keyed(), 'zinn-cache' );
	}

	/**
	 * Re-render the SDK's STORED opt-in notice once, in the admin's current language.
	 *
	 * The SDK renders the "We made a few tweaks…" notice ONCE, when it first adds it, and keeps
	 * the finished HTML in its own storage. So a site that met it before this plugin translated
	 * the SDK keeps showing the English copy after the update, on every admin page, until someone
	 * opts in or skips. When the language differs from the one the stored copy was made in, drop
	 * it and clear the SDK's "already added" flag; the SDK's own `admin_init` then adds it again,
	 * translated. The language is remembered in the SDK's storage for this module (no option of
	 * our own), so this costs nothing on every other request.
	 *
	 * @return void
	 */
	public static function refresh_stored_notice(): void {
		if ( ! class_exists( 'FS_Admin_Notices' ) || ! class_exists( 'FS_Storage' ) || ! function_exists( 'determine_locale' ) ) {
			return;
		}
		$storage = \FS_Storage::instance( 'plugin', 'zinn-cache' );
		$locale  = determine_locale();
		if ( $storage->get( 'zinn_notice_locale' ) === $locale ) {
			return;
		}
		$notices = \FS_Admin_Notices::instance( 'zinn-cache' );
		if ( $notices->has_sticky( 'connect_account' ) ) {
			$notices->remove_sticky( 'connect_account' );
			$storage->remove( 'sticky_optin_added' );
		}
		$storage->store( 'zinn_notice_locale', $locale );
	}

	/**
	 * Key => our translation.
	 *
	 * @return array<string,string>
	 */
	public static function keyed(): array {
		$table = self::table();
		$out   = array();
		foreach ( $table['keyed'] as $row ) {
			$out[ $row[0] ] = $row[1];
		}
		// The SDK formats these with its module label BEFORE the lookup, so the override must be
		// the finished sentence, formatted with our translation of that label.
		foreach ( $table['label'] as $key ) {
			if ( isset( $out[ $key ], $out['plugin'] ) ) {
				$out[ $key ] = sprintf( $out[ $key ], $out['plugin'] );
			}
		}
		// And these the SDK fills with its RAW module type, the untranslated word "plugin". Our
		// sentence carries our own word in that slot instead; sprintf() ignores the unused arg.
		$word = isset( $out['plugin'] ) ? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $out['plugin'] ) : strtolower( $out['plugin'] ) ) : '';
		foreach ( $table['mtype'] as $row ) {
			$key = array_shift( $row );
			if ( '' === $word || ! isset( $out[ $key ] ) ) {
				continue;
			}
			foreach ( $row as $slot ) {
				$out[ $key ] = str_replace( '%' . $slot . '$s', $word, $out[ $key ] );
				if ( 1 === $slot ) {
					// A sentence with a single placeholder keeps the SDK's bare `%s`.
					$out[ $key ] = preg_replace( '/%s/', $word, $out[ $key ], 1 ) ?? $out[ $key ];
				}
			}
		}
		return $out;
	}

	/**
	 * Our translation of an SDK string looked up in the `freemius` domain, where the SDK had none.
	 *
	 * @param string $translation What the SDK's own catalogue returned.
	 * @param string $text        The English source.
	 * @return string
	 */
	public static function unkeyed( $translation, $text ) {
		return self::fill( (string) $translation, (string) $text, '' );
	}

	/**
	 * As {@see unkeyed()}, for a string with a gettext context.
	 *
	 * @param string $translation What the SDK's own catalogue returned.
	 * @param string $text        The English source.
	 * @param string $context     The gettext context.
	 * @return string
	 */
	public static function unkeyed_with_context( $translation, $text, $context ) {
		return self::fill( (string) $translation, (string) $text, (string) $context );
	}

	/**
	 * Answer from our catalogue only when the SDK's own returned the English unchanged.
	 *
	 * @param string $translation SDK result.
	 * @param string $text        English.
	 * @param string $context     Context ('' for none).
	 * @return string
	 */
	private static function fill( string $translation, string $text, string $context ): string {
		// Before `init` our catalogue may not be loaded yet, and loading it would raise WP 6.7's
		// "translation loading was triggered too early" notice.
		if ( $translation !== $text || ! did_action( 'init' ) ) {
			return $translation;
		}
		if ( null === self::$unkeyed ) {
			self::$unkeyed = array();
			foreach ( self::table()['unkeyed'] as $row ) {
				self::$unkeyed[ $row[0] . "\x04" . $row[1] ] = $row[2];
			}
		}
		return self::$unkeyed[ $context . "\x04" . $text ] ?? $translation;
	}

	/**
	 * The generated table (translated with the current locale on first use).
	 *
	 * @return array{keyed:list<array{0:string,1:string}>,unkeyed:list<array{0:string,1:string,2:string}>,label:list<string>,mtype:list<list<string|int>>}
	 */
	private static function table(): array {
		if ( null === self::$table ) {
			self::$table = require __DIR__ . '/freemius-strings.php';
		}
		return self::$table;
	}
}
