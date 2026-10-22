<?php
/**
 * Starts Zinn® Cache: the shared settings framework, the Zinn® panel and every hook of the plugin.
 *
 * Read by zinn-cache.php through Cli_Start::start(): at once, or — in a WP-CLI command — on WP-CLI's
 * `after_wp_load`, with every already-fired action replayed (class-cli-start.php; stage PERF3's
 * pattern, CONSENT1, Q226). Classes of `Zinn\Cache` come from the autoloader on first use.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

// ⛔ `require_once` rather than the autoloader: the shared settings framework is deliberately
// GLOBAL — shipped identically into seven plugins with different namespacing conventions —
// and this plugin bootstraps inside `Zinn\Cache`, where an autoloader keyed on the namespace
// would never look for `Zinn_Cache_Admin_UI`. Loaded unconditionally, because `::get()` is
// read on front-end requests as well as in wp-admin (§2.38).
require_once __DIR__ . '/class-zinn-cache-admin-fields.php';
require_once __DIR__ . '/class-zinn-cache-admin-ui.php';
require_once __DIR__ . '/class-zinn-cache-connection.php';
require_once __DIR__ . '/class-zinn-cache-diagnostics.php';
require_once __DIR__ . '/class-zinn-cache-style-presets.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );

// ── The Zinn® panel ──────────────────────────────────────────────────────────────────────
//
// ⚖️ Owner, 2026-09-01: *"each plugin should promote our hosting and marketplace as well as
// Zinn Hub global marketplace inside people's site in the admin dashboard"*, and *"user
// guides for them … linked to in the plugins dashboard"*.
//
// ⛔ `require_once` rather than the autoloader, and a STRING callable rather than
// `array( Zinn_Cache_Promo::class, … )`. The class is deliberately global — it is shipped
// identically into seven plugins with different namespacing conventions, and three of them
// bootstrap inside a namespace where `Zinn_Cache_Promo::class` would resolve to a class that does
// not exist. A string callable is resolved in the global namespace at call time, which is
// correct from every one of the seven. `php -l` cannot see that mistake; only running it can.
require_once __DIR__ . '/class-zinn-cache-promo.php';
add_action( 'plugins_loaded', array( 'Zinn_Cache_Promo', 'register' ) );

// HOSTDISC (docs/894): the hosting-customer Pro discount card, on this plugin's own screen, only
// on a site Zinn hosts, and never once Zinn® Cache Pro is active. The class is generated
// (`wp/bin/build-promo.php`) and global, so it is required and named by STRING, like the promo.
require_once __DIR__ . '/class-zinn-cache-pro-discount.php';
add_action(
	'plugins_loaded',
	static function (): void {
		\Zinn_Cache_Pro_Discount::register( 'zinn-admin-ui-zinn-cache', 'Zinn\\Cache\\Upsell::pro_active' );
	}
);
