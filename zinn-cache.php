<?php
/**
 * Plugin Name:       Zinn® Cache
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-cache
 * Description:       A page cache and a Redis object cache for any WordPress site. Uses the LiteSpeed server cache where there is one and its own disk cache everywhere else, purges only what a change affects, and proves its Redis connection works.
 * Version:           1.11.0
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

const VERSION = '1.11.0';

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
require_once __DIR__ . '/includes/class-cli-start.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

// The start — the settings framework, the Zinn® panel and every hook of the plugin — is in
// includes/start.php. Cli_Start runs it at once, or in a WP-CLI command right after WordPress has
// loaded: WP-CLI loads WordPress under PHP's own 128 MB limit and lifts it only afterwards (stage
// PERF3's pattern, applied here by CONSENT1, Q226). `define( 'ZINN_CLI_START_AT_ONCE', true );`
// starts it at once under WP-CLI too.
//
// ⭐ Anything that must run WHILE WordPress loads even when the start is deferred goes above
// this line; the rest is in start.php.
\Zinn\Cache\Cli_Start::start(
	static function (): void {
		require_once __DIR__ . '/includes/start.php';
	}
);
