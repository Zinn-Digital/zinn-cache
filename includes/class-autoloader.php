<?php
/**
 * PSR-4-style autoloader for the Zinn Cache plugin.
 *
 * The plugin ships to customer sites as part of the deploy footprint, so it must
 * not depend on Composer's runtime autoloader being present. This tiny, dependency
 * -free loader maps the `Zinn\Cache\` namespace onto the WordPress file-naming
 * convention (`class-{hyphenated-name}.php`) used throughout `includes/`.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `Zinn\Cache\*` class names to files under a base directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix this loader is responsible for.
	 */
	private const PREFIX = 'Zinn\\Cache\\';

	/**
	 * Absolute path to the directory that holds the plugin's class files.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Directory containing the `class-*.php` files.
	 */
	private function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' );
	}

	/**
	 * Register an autoloader instance for the given base directory.
	 *
	 * @param string $base_dir Directory containing the `class-*.php` files.
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		$loader = new self( $base_dir );
		spl_autoload_register( array( $loader, 'load' ) );
	}

	/**
	 * Resolve and require the file backing a fully-qualified class name.
	 *
	 * @param string $class_name Fully-qualified class name being loaded.
	 * @return void
	 */
	public function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = $this->path_for( $relative );

		if ( is_readable( $file ) ) {
			require $file;
		}
	}

	/**
	 * Translate the un-prefixed class name into its file path.
	 *
	 * `Rest\Purge_Endpoint` becomes `<base>/rest/class-purge-endpoint.php`.
	 *
	 * @param string $relative Class name with the `Zinn\Cache\` prefix stripped.
	 * @return string Absolute file path (not guaranteed to exist).
	 */
	private function path_for( string $relative ): string {
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$file_name  = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		$sub_path = '';
		if ( array() !== $parts ) {
			$sub_path = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';
		}

		return $this->base_dir . '/' . $sub_path . $file_name;
	}
}
