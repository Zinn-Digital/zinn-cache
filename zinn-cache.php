<?php
/**
 * Plugin Name:       Zinn® Cache
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-cache
 * Description:       For sites hosted with Zinn Digital®. Controls the page cache your Zinn® server already provides — smart auto-purge on content change, remote purge from your Zinn® dashboard, a Redis object-cache toggle, safe WordPress/WooCommerce exclusions, and one-click admin login. This is a cache controller, not a cache engine. Hosting elsewhere? Install Zinn® Cache Engine instead.
 * Version:           1.1.0
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

const VERSION = '1.0.0';

define( 'ZINN_CACHE_VERSION', VERSION );
define( 'ZINN_CACHE_FILE', __FILE__ );
define( 'ZINN_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZINN_CACHE_URL', plugin_dir_url( __FILE__ ) );
define( 'ZINN_CACHE_MIN_PHP', '8.2' );
define( 'ZINN_CACHE_MIN_WP', '6.6' );

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __DIR__ . '/includes' );

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
