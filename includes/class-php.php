<?php
/**
 * Is a PHP function safe to call on THIS host?
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔⛔ Shared hosts list functions in php.ini `disable_functions` (getmypid, sys_getloadavg ...).
 * On PHP 8 a disabled function is UNDEFINED: calling it throws a fatal `Error` and `@` does NOT
 * stop it; on PHP 7 it "exists" and returns null with a warning. Every call to a function a host
 * may disable is asked here first and has a fallback (V1 ticket 13692, docs/72 D35246). Enforced
 * by scripts/php-disabled-functions-guard.py. A class, so it loads only when asked (the start-up
 * path stays as small as PerfCliStartTest measures).
 */
final class Php {

	/**
	 * True only if the function exists AND is not listed in `disable_functions`.
	 *
	 * @param string $name A PHP function name.
	 * @return bool
	 */
	public static function can_call( string $name ): bool {
		static $disabled = null;
		if ( ! function_exists( $name ) ) {
			return false;
		}
		if ( null === $disabled ) {
			$list     = function_exists( 'ini_get' ) ? (string) ini_get( 'disable_functions' ) : '';
			$disabled = array_flip( array_filter( array_map( 'trim', explode( ',', strtolower( $list ) ) ) ) );
		}
		return ! isset( $disabled[ strtolower( $name ) ] );
	}
}
