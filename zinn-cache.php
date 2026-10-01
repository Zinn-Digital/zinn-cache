<?php
/**
 * Plugin Name:       Zinn® Cache
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-cache
 * Description:       A page cache and a Redis object cache for any WordPress site. Uses the LiteSpeed server cache where there is one and its own disk cache everywhere else, purges only what a change affects, and proves its Redis connection works.
 * Version:           1.8.2
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-cache
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package Zinn\Cache
 *
 * Zinn Cache
 * Copyright (C) 2026 Zinn Digital® Ltd (Neil Lock, CEO).
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 2, as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.8.2';

define( 'ZINN_CACHE_VERSION', VERSION );
define( 'ZINN_CACHE_FILE', __FILE__ );
define( 'ZINN_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZINN_CACHE_URL', plugin_dir_url( __FILE__ ) );
define( 'ZINN_CACHE_MIN_PHP', '8.2' );
define( 'ZINN_CACHE_MIN_WP', '6.6' );

require_once __DIR__ . '/includes/class-autoloader.php';
require_once __DIR__ . '/includes/uninstall-cleanup.php';
require_once __DIR__ . '/includes/platform-hosted.php';
require_once __DIR__ . '/includes/freemius.php';

Autoloader::register( __DIR__ . '/includes' );

// ⛔ `require_once` rather than the autoloader: the shared settings framework is deliberately
// GLOBAL — shipped identically into seven plugins with different namespacing conventions —
// and this plugin bootstraps inside `Zinn\Cache`, where an autoloader keyed on the namespace
// would never look for `Zinn_Cache_Admin_UI`. Loaded unconditionally, because `::get()` is
// read on front-end requests as well as in wp-admin (§2.38).
require_once __DIR__ . '/includes/class-zinn-cache-admin-fields.php';
require_once __DIR__ . '/includes/class-zinn-cache-admin-ui.php';
require_once __DIR__ . '/includes/class-zinn-cache-connection.php';
require_once __DIR__ . '/includes/class-zinn-cache-diagnostics.php';
require_once __DIR__ . '/includes/class-zinn-cache-style-presets.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

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
require_once __DIR__ . '/includes/class-zinn-cache-promo.php';
add_action( 'plugins_loaded', array( 'Zinn_Cache_Promo', 'register' ) );

// HOSTDISC (docs/894): the hosting-customer Pro discount card, on this plugin's own screen, only
// on a site Zinn hosts, and never once Zinn® Cache Pro is active. The class is generated
// (`wp/bin/build-promo.php`) and global, so it is required and named by STRING, like the promo.
require_once __DIR__ . '/includes/class-zinn-cache-pro-discount.php';
add_action(
	'plugins_loaded',
	static function (): void {
		\Zinn_Cache_Pro_Discount::register( 'zinn-admin-ui-zinn-cache', 'Zinn\\Cache\\Upsell::pro_active' );
	}
);
