<?php

/**
 * Freemius for the free Zinn® Cache (LOCKED PLAN §1: opt-in, the upgrade path, licences and updates, as PBS and Tranzly).
 *
 * ⛔ Written in the canonical form the Freemius deploy processor re-prints (four-space indent, no blank lines between statements, `!$x`): it re-prints the file that calls fs_dynamic_init(), so writing it that way keeps the zip Freemius serves byte-equal to ours (docs/adr/0031, docs/adr/0032). Keep this file to the SDK init; everything else follows WPCS in its own file.
 *
 * ⛔ No secret key here, ever: the SDK needs only the PUBLIC key. The product secret lives in Vault (secret/vendors/freemius/zinn-cache).
 *
 * ⭐ Anonymous on a site hosted with Zinn Digital® (ZINN_CACHE_MANAGED_OBJECT_CACHE or ZINN_SITE_EVENTS_URL, both written by the platform): the host already knows the site, and an opt-in screen on a hosted customer's site is a question with no one to answer it.
 *
 * ⛔⛔ FAIL SAFE: without the SDK on disk the whole block is skipped and the cache keeps working. A build that lost vendor/freemius (D28307) otherwise fatalled on activation and, through the platform's footprint, at core install of every new site (D28308).
 *
 * @package Zinn\Cache
 */
defined( 'ABSPATH' ) || exit;
if ( !file_exists( dirname( __DIR__ ) . '/vendor/freemius/start.php' ) ) {
    define( 'ZINN_CACHE_FREEMIUS_MISSING', true );
} elseif ( !function_exists( 'zinn_cache_fs' ) ) {
    /**
     * The licensing SDK instance for this plugin (Freemius product 40420).
     *
     * @return Freemius
     */
    function zinn_cache_fs() {
        global $zinn_cache_fs;
        if ( !isset( $zinn_cache_fs ) ) {
            require_once dirname( __DIR__ ) . '/vendor/freemius/start.php';
            $zinn_cache_fs = fs_dynamic_init( array(
                'id'               => '40420',
                'slug'             => 'zinn-cache',
                'type'             => 'plugin',
                'public_key'       => 'pk_2719e9065bebbc19a177d1704a582',
                'is_premium'       => false,
                'has_addons'       => false,
                'has_paid_plans'   => false,
                'is_org_compliant' => true,
                'anonymous_mode'   => ( defined( 'ZINN_CACHE_MANAGED_OBJECT_CACHE' ) && ZINN_CACHE_MANAGED_OBJECT_CACHE ) || defined( 'ZINN_SITE_EVENTS_URL' ),
                'menu'             => array(
                    'slug'    => 'zinn-admin-ui-zinn-cache',
                    'account' => false,
                    'contact' => false,
                    'support' => false,
                    'pricing' => false,
                    'parent'  => array(
                        'slug' => 'zinn-admin-ui',
                    ),
                ),
                'is_live'          => true,
            ) );
        }
        return $zinn_cache_fs;
    }
    zinn_cache_fs();
    zinn_cache_fs()->add_action( 'after_uninstall', 'zinn_cache_uninstall' );
    do_action( 'zinn_cache_fs_loaded' );
}