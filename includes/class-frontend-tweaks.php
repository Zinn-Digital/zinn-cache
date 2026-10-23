<?php
/**
 * Lighter pages: the WordPress Heartbeat, web fonts, early connections and a CDN address.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Four small switches every speed plugin has, each off by default and each doing one thing:
 *
 *  - **Heartbeat**: the browser asks WordPress every 15 to 60 seconds whether anything changed
 *    (autosave, post locks, notifications). `reduce` asks once a minute; `off_front` stops it on
 *    the public site, where nothing uses it, and leaves the dashboard alone.
 *  - **Web fonts**: Google Fonts stylesheets get `display=swap`, so text shows at once in a
 *    fallback font instead of staying invisible while the font downloads, and the browser
 *    connects to the font server early.
 *  - **Early connections**: `<link rel="preconnect">` for the origins named (a CDN, an analytics
 *    or payment script's server), so the connection is ready when the page asks for it.
 *  - **CDN address**: images, CSS, JavaScript and fonts under `/wp-content/` and `/wp-includes/` are
 *    served from the CDN address named instead of the site's own (a pull CDN such as BunnyCDN or
 *    KeyCDN). Pages themselves never move.
 */
final class Frontend_Tweaks {

	/**
	 * File types the CDN rewrite moves.
	 */
	private const STATIC_TYPES = 'avif|webp|png|jpe?g|gif|svg|ico|css|js|mjs|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf';

	/**
	 * Normalised settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * The output-buffer level of the CDN rewrite, or null.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register only the hooks a switched-on option needs.
	 *
	 * @return void
	 */
	public function register(): void {
		$heartbeat = (string) ( $this->settings['heartbeat_mode'] ?? 'default' );
		if ( 'reduce' === $heartbeat ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'slow_heartbeat' ) );
		} elseif ( 'off_front' === $heartbeat ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stop_front_heartbeat' ), 100 );
		}
		if ( ! empty( $this->settings['font_display_swap'] ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'font_swap' ), 10, 1 );
		}
		if ( ! empty( $this->settings['font_display_swap'] ) || array() !== (array) ( $this->settings['preconnect_origins'] ?? array() ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		}
		if ( '' !== (string) ( $this->settings['cdn_url'] ?? '' ) ) {
			add_action( 'template_redirect', array( $this, 'start_cdn_buffer' ), 1 );
		}
	}

	/**
	 * Heartbeat once a minute.
	 *
	 * @param array<string,mixed> $settings Heartbeat settings.
	 * @return array<string,mixed>
	 */
	public static function slow_heartbeat( array $settings ): array {
		$settings['interval'] = 60;
		return $settings;
	}

	/**
	 * No Heartbeat on the public site.
	 *
	 * @return void
	 */
	public static function stop_front_heartbeat(): void {
		if ( ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * Add `display=swap` to a Google Fonts stylesheet address.
	 *
	 * @param mixed $src Stylesheet URL.
	 * @return mixed
	 */
	public static function font_swap( $src ) {
		if ( ! is_string( $src ) || ! str_contains( $src, 'fonts.googleapis.com' ) || str_contains( $src, 'display=' ) ) {
			return $src;
		}
		return add_query_arg( 'display', 'swap', $src );
	}

	/**
	 * Preconnect hints.
	 *
	 * @param array<int,mixed> $urls          Hint URLs.
	 * @param string           $relation_type Relation.
	 * @return array<int,mixed>
	 */
	public function resource_hints( array $urls, string $relation_type ): array {
		if ( 'preconnect' !== $relation_type ) {
			return $urls;
		}
		foreach ( (array) ( $this->settings['preconnect_origins'] ?? array() ) as $origin ) {
			$urls[] = (string) $origin;
		}
		if ( ! empty( $this->settings['font_display_swap'] ) ) {
			$urls[] = array(
				'href'        => 'https://fonts.gstatic.com',
				'crossorigin' => 'anonymous',
			);
		}
		$cdn = (string) ( $this->settings['cdn_url'] ?? '' );
		if ( '' !== $cdn ) {
			$urls[] = $cdn;
		}
		return $urls;
	}

	/**
	 * Begin the CDN rewrite buffer on the public site.
	 *
	 * @return void
	 */
	public function start_cdn_buffer(): void {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}
		if ( ! ob_start( array( $this, 'rewrite' ) ) ) {
			return;
		}
		$this->buffer_level = ob_get_level();
		add_action( 'shutdown', array( $this, 'close_buffer' ), 0 );
	}

	/**
	 * Close the buffer this class opened, and only that one (the same rule as {@see Lscache::close_buffer()}).
	 *
	 * @return void
	 */
	public function close_buffer(): void {
		if ( null === $this->buffer_level ) {
			return;
		}
		$level              = $this->buffer_level;
		$this->buffer_level = null;
		while ( ob_get_level() >= $level && ob_get_level() > 0 ) {
			$status = ob_get_status();
			$flags  = is_array( $status ) ? (int) ( $status['flags'] ?? 0 ) : 0;
			if ( 0 === ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_flush() ) {
				return;
			}
		}
	}

	/**
	 * Rewrite static-file addresses to the CDN.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function rewrite( string $html ): string {
		return self::rewrite_html(
			$html,
			home_url( '/' ),
			(string) ( $this->settings['cdn_url'] ?? '' ),
			array_map( 'strval', (array) ( $this->settings['cdn_exclude'] ?? array() ) )
		);
	}

	/**
	 * Move every `/wp-content/` and `/wp-includes/` static file on this site's host to the CDN. Pure.
	 *
	 * @param string   $html    HTML.
	 * @param string   $home    The site's home URL.
	 * @param string   $cdn     CDN base URL (`https://cdn.example.com`).
	 * @param string[] $exclude Substrings: an address containing one stays where it is.
	 * @return string
	 */
	public static function rewrite_html( string $html, string $home, string $cdn, array $exclude = array() ): string {
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );
		$cdn  = untrailingslashit( $cdn );
		if ( '' === $host || '' === $cdn || false === stripos( $html, '/wp-' ) ) {
			return $html;
		}
		$exclude = array_values( array_filter( array_map( 'trim', $exclude ) ) );
		$pattern = '#(?:https?:)?//' . preg_quote( $host, '#' ) . '(/(?:wp-content|wp-includes)/[^"\'\s<>()]+?\.(?:' . self::STATIC_TYPES . ')(?:\?[^"\'\s<>()]*)?)(?=["\'\s<>(),])#i';
		$out     = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $cdn, $exclude ): string {
				foreach ( $exclude as $needle ) {
					if ( str_contains( $m[0], $needle ) ) {
						return $m[0];
					}
				}
				return $cdn . $m[1];
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}
}
