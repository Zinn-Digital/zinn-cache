<?php
/**
 * Uninstall cleanup, run by the licensing SDK's `after_uninstall` action.
 *
 * ⛔ There is no uninstall.php: Freemius refuses an upload that contains one (HTTP 400
 * `uninstall_script`), because WordPress runs that file INSTEAD of the SDK's own uninstall hook.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Remove the plugin's drop-ins (only ours), its .htaccess block, the disk cache and the settings.
 *
 * @return void
 */
function zinn_cache_uninstall(): void {
	( new Zinn\Cache\Object_Cache( Zinn\Cache\Settings::get() ) )->disable();
	Zinn\Cache\Htaccess::remove();
	Zinn\Cache\Page_Cache::uninstall();

	if ( ! is_multisite() ) {
		delete_option( Zinn\Cache\Settings::OPTION );
		return;
	}
	$zinn_cache_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $zinn_cache_site_ids as $zinn_cache_site_id ) {
		switch_to_blog( (int) $zinn_cache_site_id );
		delete_option( Zinn\Cache\Settings::OPTION );
		restore_current_blog();
	}
}
