<?php
/**
 * Plugin Name:       Zinn® Cache
 * Plugin URI:        https://zinndigital.com
 * Description:       Server-side cache control for the LiteSpeed (LSCache) full-page cache — with smart auto-purge on content change, a Redis object-cache toggle, and safe WordPress/WooCommerce cache exclusions. Part of the Zinn Digital® hosting-platform deploy footprint.
 * Version:           1.0.0
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
