<?php
/**
 * Whether this site is hosted by the Zinn Digital® platform.
 *
 * @package Zinn\Cache
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zinn_cache_is_platform_hosted' ) ) {
	/**
	 * True on a site the Zinn Digital® platform hosts: it writes one of these constants.
	 *
	 * ⛔ A FUNCTION, not an expression in `includes/freemius.php`, on purpose (D28560): Freemius
	 * re-prints that file on upload and removes the parentheses the house coding standard
	 * requires around mixed `&&`/`||`, so the served zip never matched the house zip. A call has
	 * no operators to re-print.
	 *
	 * @return bool
	 */
	function zinn_cache_is_platform_hosted(): bool {
		$managed = defined( 'ZINN_CACHE_MANAGED_OBJECT_CACHE' ) && ZINN_CACHE_MANAGED_OBJECT_CACHE;
		return $managed || defined( 'ZINN_SITE_EVENTS_URL' );
	}
}
