<?php
/**
 * Redis object-cache drop-in management.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Installs, configures, and removes the bundled Redis object-cache drop-in.
 *
 * The drop-in itself lives at `dropins/object-cache.php` and is copied into
 * `wp-content/object-cache.php` when enabled. Connection settings are written to
 * `wp-content/zinn-cache-redis.php`; the drop-in also honours the standard
 * `WP_REDIS_*` constants when a footprint prefers to define them in `wp-config.php`.
 *
 * The manager never overwrites a *foreign* object-cache drop-in (another caching
 * plugin's), and gracefully reports when the phpredis extension is unavailable —
 * the object cache is a per-blueprint capability that may be absent.
 */
final class Object_Cache {

	/**
	 * Marker string present in our drop-in, used to recognise our own file.
	 */
	public const DROPIN_MARKER = 'Zinn Cache object-cache drop-in';

	/**
	 * Current, normalised plugin settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Normalised settings from {@see Settings::get()}.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the phpredis PHP extension is loaded.
	 *
	 * @return bool
	 */
	public function is_redis_extension_available(): bool {
		return class_exists( 'Redis' );
	}

	/**
	 * Absolute path to the active drop-in location.
	 *
	 * @return string
	 */
	public function dropin_target(): string {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/**
	 * Absolute path to the bundled drop-in source shipped with the plugin.
	 *
	 * @return string
	 */
	public function dropin_source(): string {
		return ZINN_CACHE_DIR . 'dropins/object-cache.php';
	}

	/**
	 * Absolute path to the generated Redis connection config file.
	 *
	 * @return string
	 */
	public function config_target(): string {
		return WP_CONTENT_DIR . '/zinn-cache-redis.php';
	}

	/**
	 * Whether our drop-in is currently installed and active.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$target = $this->dropin_target();

		return file_exists( $target ) && $this->is_our_dropin( $target );
	}

	/**
	 * Whether a drop-in from other software currently occupies the slot.
	 *
	 * @return bool
	 */
	public function is_foreign_dropin(): bool {
		$target = $this->dropin_target();

		return file_exists( $target ) && ! $this->is_our_dropin( $target );
	}

	/**
	 * Install (or refresh) the drop-in and write its connection config.
	 *
	 * @return true|WP_Error True on success, WP_Error describing the refusal/fault.
	 */
	public function enable() {
		if ( ! $this->is_redis_extension_available() ) {
			return new WP_Error(
				'zinn_cache_no_redis',
				__( 'The Redis PHP extension (phpredis) is not available on this server, so the object cache cannot be enabled.', 'zinn-cache' )
			);
		}

		if ( $this->is_foreign_dropin() ) {
			return new WP_Error(
				'zinn_cache_foreign_dropin',
				__( 'Another object-cache drop-in is already installed. Remove it before enabling the Zinn® object cache.', 'zinn-cache' )
			);
		}

		$fs = $this->filesystem();
		if ( $fs instanceof WP_Error ) {
			return $fs;
		}

		if ( false === $fs->put_contents( $this->config_target(), $this->config_file_contents(), FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'zinn_cache_config_write_failed',
				__( 'Could not write the Redis connection configuration file.', 'zinn-cache' )
			);
		}

		$source_contents = $fs->get_contents( $this->dropin_source() );
		if ( false === $source_contents || '' === $source_contents ) {
			return new WP_Error(
				'zinn_cache_dropin_missing',
				__( 'The bundled object-cache drop-in could not be read.', 'zinn-cache' )
			);
		}

		if ( false === $fs->put_contents( $this->dropin_target(), $source_contents, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'zinn_cache_dropin_write_failed',
				__( 'Could not install the object-cache drop-in into wp-content.', 'zinn-cache' )
			);
		}

		return true;
	}

	/**
	 * Remove our drop-in and its config file (never touches a foreign drop-in).
	 *
	 * @return true|WP_Error
	 */
	public function disable() {
		$fs = $this->filesystem();
		if ( $fs instanceof WP_Error ) {
			return $fs;
		}

		if ( $this->is_enabled() && ! $fs->delete( $this->dropin_target() ) ) {
			return new WP_Error(
				'zinn_cache_dropin_delete_failed',
				__( 'Could not remove the object-cache drop-in.', 'zinn-cache' )
			);
		}

		if ( file_exists( $this->config_target() ) ) {
			$fs->delete( $this->config_target() );
		}

		return true;
	}

	/**
	 * Reconcile the on-disk drop-in with the desired settings state.
	 *
	 * @return true|WP_Error
	 */
	public function sync(): mixed {
		$want = ! empty( $this->settings['object_cache_enabled'] );

		if ( $want && ! $this->is_enabled() ) {
			return $this->enable();
		}

		if ( $want && $this->is_enabled() ) {
			// Keep the connection config current with the latest settings.
			$fs = $this->filesystem();
			if ( $fs instanceof WP_Error ) {
				return $fs;
			}
			$fs->put_contents( $this->config_target(), $this->config_file_contents(), FS_CHMOD_FILE );
			return true;
		}

		if ( ! $want && $this->is_enabled() ) {
			return $this->disable();
		}

		return true;
	}

	/**
	 * Whether the file at the given path is our drop-in (by marker).
	 *
	 * @param string $path File to inspect.
	 * @return bool
	 */
	private function is_our_dropin( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only marker check on a local, plugin-owned file; a WP_Filesystem round-trip is unnecessary and may be unavailable for a pure read.
		$head = file_get_contents( $path, false, null, 0, 512 );

		return is_string( $head ) && str_contains( $head, self::DROPIN_MARKER );
	}

	/**
	 * Build the PHP source of the Redis connection config file.
	 *
	 * @return string
	 */
	private function config_file_contents(): string {
		$config = array(
			'host'     => (string) ( $this->settings['redis_host'] ?? '127.0.0.1' ),
			'port'     => (int) ( $this->settings['redis_port'] ?? 6379 ),
			'database' => (int) ( $this->settings['redis_database'] ?? 0 ),
			'password' => (string) ( $this->settings['redis_password'] ?? '' ),
			'prefix'   => (string) ( $this->settings['redis_key_prefix'] ?? '' ),
			'timeout'  => 1.0,
		);

		return "<?php\n"
			. '// ' . self::DROPIN_MARKER . " — connection config (auto-generated; do not edit).\n"
			. "// Managed by the Zinn® Cache plugin. Values here are overridden by any WP_REDIS_* constants.\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Serialising a config array to a PHP file, not debug output.
	}

	/**
	 * Initialise and return the WordPress filesystem abstraction.
	 *
	 * @return \WP_Filesystem_Base|WP_Error
	 */
	private function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'zinn_cache_fs_unavailable',
				__( 'The WordPress filesystem is not writable, so the object-cache drop-in could not be managed.', 'zinn-cache' )
			);
		}

		return $wp_filesystem;
	}
}
