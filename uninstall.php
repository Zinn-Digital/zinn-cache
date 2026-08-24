<?php
/**
 * Uninstall handler: remove the plugin's stored data and object-cache drop-in.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

Zinn\Cache\Autoloader::register( __DIR__ . '/includes' );

// Remove our object-cache drop-in and its connection config (never a foreign one).
( new Zinn\Cache\Object_Cache( Zinn\Cache\Settings::get() ) )->disable();

// Remove the managed .htaccess cache-vary block.
Zinn\Cache\Htaccess::remove();

// Remove the stored settings, on every site of a network.
if ( is_multisite() ) {
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
} else {
	delete_option( Zinn\Cache\Settings::OPTION );
}
