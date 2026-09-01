<?php
/**
 * LiteSpeed full-page cache (LSCache) control.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Controls the LiteSpeed server-level full-page cache.
 *
 * Two integration modes, chosen automatically and degrading gracefully:
 *
 *  - **A cache-engine plugin present** — Zinn Cache Engine (our GPL-3.0 fork, which ships
 *    in the same deploy footprint) or the third-party LiteSpeed Cache plugin. We defer
 *    page caching to it and route purges through its public actions
 *    (`zinn_cache_pro_purge_*` / `litespeed_purge_*`); see {@see self::CACHE_ENGINES}.
 *  - **LiteSpeed server only** (our deploy-footprint case: LiteSpeed Enterprise /
 *    OpenLiteSpeed with the cache module, no third-party plugin) — we stamp
 *    cacheability and cache tags on responses via `X-LiteSpeed-Cache-Control` /
 *    `X-LiteSpeed-Tag`, and purge with `X-LiteSpeed-Purge` tag directives.
 *
 * When neither is present (e.g. Apache/nginx, or WP-CLI), every method is a safe
 * no-op — the cache layer is a per-blueprint capability that may simply be absent.
 */
final class Lscache {

	/**
	 * Response header used to control cacheability of the current page.
	 */
	private const HEADER_CONTROL = 'X-LiteSpeed-Cache-Control';

	/**
	 * Response header used to tag the current page for later targeted purging.
	 */
	private const HEADER_TAG = 'X-LiteSpeed-Tag';

	/**
	 * Response header used to instruct the server to purge cache entries.
	 */
	private const HEADER_PURGE = 'X-LiteSpeed-Purge';

	/**
	 * Current, normalised plugin settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * Directives queued for a purge header emitted late in the request.
	 *
	 * @var array<string,bool>
	 */
	private array $purge_queue = array();

	/**
	 * Whether a full ("purge everything") directive has been requested.
	 *
	 * @var bool
	 */
	private bool $purge_all_queued = false;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Normalised settings from {@see Settings::get()}.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 0 );
		add_action( 'shutdown', array( $this, 'flush_purge_queue' ), 0 );
	}

	/**
	 * Full-page-cache engines we can hand off to, in preference order, mapped from the
	 * constant that proves the engine is loaded to the prefix its public actions use.
	 *
	 * Zinn Cache Engine is our own GPL-3.0 fork of LiteSpeed Cache and ships in the same
	 * deploy footprint as this plugin, so it is checked FIRST — if both were somehow
	 * active we hand off to ours. The fork renames every global it inherits (that is what
	 * makes it a distinct plugin rather than a colliding copy), so it answers to
	 * `ZINN_CACHE_PRO_V` / `zinn_cache_pro_purge_*` and is invisible to a bare `LSCWP_V`
	 * check — which is exactly how this plugin used to look for an engine.
	 *
	 * @var array<string,string>
	 */
	private const CACHE_ENGINES = array(
		'ZINN_CACHE_PRO_V' => 'zinn_cache_pro_',
		'LSCWP_V'          => 'litespeed_',
	);

	/**
	 * Whether a full-page-cache engine plugin (Zinn Cache Engine, or the third-party
	 * LiteSpeed Cache plugin) is active and should own page caching on this request.
	 *
	 * @return bool
	 */
	public function is_lscache_plugin_active(): bool {
		return null !== $this->cache_engine_hook_prefix();
	}

	/**
	 * The action-name prefix of the active cache-engine plugin, or null if none is active.
	 *
	 * @return string|null
	 */
	private function cache_engine_hook_prefix(): ?string {
		foreach ( self::CACHE_ENGINES as $constant => $hook_prefix ) {
			if ( defined( $constant ) ) {
				return $hook_prefix;
			}
		}

		return null;
	}

	/**
	 * Whether the request is being served by a LiteSpeed web server.
	 *
	 * @return bool
	 */
	public function is_litespeed_server(): bool {
		if ( isset( $_SERVER['HTTP_X_LSCACHE'] ) && '' !== $_SERVER['HTTP_X_LSCACHE'] ) {
			return true;
		}

		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );

			return false !== stripos( $software, 'litespeed' );
		}

		return false;
	}

	/**
	 * Whether any LiteSpeed cache integration is available at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->is_lscache_plugin_active() || $this->is_litespeed_server();
	}

	/**
	 * On the front end, begin buffering output so the cacheability decision can
	 * be made at the *end* of the request.
	 *
	 * Deferring to output flush means late bypass signals — a `DONOTCACHEPAGE`
	 * constant defined during rendering, a shortcode/widget that goes dynamic, a
	 * 404 resolved mid-render — are all honoured before the cache-control header
	 * is committed. No-op when LSCache control is disabled, when the LiteSpeed
	 * plugin is active (it owns caching then), or when not on a LiteSpeed server.
	 *
	 * @return void
	 */
	public function maybe_start_buffer(): void {
		if ( ! $this->should_control_cache() ) {
			return;
		}

		ob_start( array( $this, 'finalize' ) );
	}

	/**
	 * Output-buffer callback: stamp cacheability + tags, then return the body.
	 *
	 * @param string $buffer Buffered response body.
	 * @return string The unmodified body.
	 */
	public function finalize( string $buffer ): string {
		if ( ! headers_sent() ) {
			if ( $this->request_is_cacheable() ) {
				$ttl = (int) ( $this->settings['lscache_ttl'] ?? 0 );
				header( self::HEADER_CONTROL . ': public,max-age=' . $ttl );

				$tags = $this->current_page_tags();
				if ( array() !== $tags ) {
					header( self::HEADER_TAG . ': ' . implode( ',', $tags ) );
				}
			} else {
				header( self::HEADER_CONTROL . ': no-cache,esi=off' );
			}
		}

		return $buffer;
	}

	/**
	 * Whether this plugin should be controlling the LiteSpeed cache on this request.
	 *
	 * @return bool
	 */
	private function should_control_cache(): bool {
		return ! empty( $this->settings['lscache_enabled'] )
			&& ! $this->is_lscache_plugin_active()
			&& $this->is_litespeed_server();
	}

	/**
	 * Purge the entire full-page cache.
	 *
	 * @return void
	 */
	public function purge_all(): void {
		$engine = $this->cache_engine_hook_prefix();
		if ( null !== $engine ) {
			do_action( $engine . 'purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Invoking the active cache-engine plugin's own action, not defining ours; the prefix comes from the fixed CACHE_ENGINES map.
			return;
		}

		if ( $this->is_litespeed_server() ) {
			$this->purge_all_queued = true;
			$this->emit_purge_now();
		}
	}

	/**
	 * Dispatch a purge plan produced by {@see Purge_Planner}.
	 *
	 * @param array{purge_all?:bool,urls?:string[],tags?:string[]} $plan Purge plan.
	 * @return void
	 */
	public function dispatch( array $plan ): void {
		if ( ! empty( $plan['purge_all'] ) ) {
			$this->purge_all();
			return;
		}

		$engine = $this->cache_engine_hook_prefix();
		if ( null !== $engine ) {
			foreach ( ( $plan['urls'] ?? array() ) as $url ) {
				do_action( $engine . 'purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Invoking the active cache-engine plugin's own action, not defining ours; the prefix comes from the fixed CACHE_ENGINES map.
			}
			return;
		}

		if ( ! $this->is_litespeed_server() ) {
			return;
		}

		foreach ( ( $plan['tags'] ?? array() ) as $tag ) {
			$this->purge_queue[ 'tag=' . $tag ] = true;
		}
		$this->emit_purge_now();
	}

	/**
	 * Emit queued purge directives as a header, if output has not begun.
	 *
	 * Called eagerly after each purge request (works for REST/Gutenberg saves and
	 * classic-editor saves, where headers are not yet sent) and again on shutdown
	 * as a fallback. Safe to call repeatedly; the queue is cleared once emitted.
	 *
	 * @return void
	 */
	public function flush_purge_queue(): void {
		$this->emit_purge_now();
	}

	/**
	 * Compute the LiteSpeed cache tags describing the current page.
	 *
	 * @return string[]
	 */
	private function current_page_tags(): array {
		$tags = array();

		if ( is_front_page() ) {
			$tags[] = Cache_Tags::front_page();
		}
		if ( is_home() ) {
			$tags[] = Cache_Tags::home();
		}
		if ( is_feed() ) {
			$tags[] = Cache_Tags::feed();
		}

		if ( is_singular() ) {
			$object_id = get_queried_object_id();
			if ( $object_id > 0 ) {
				$tags[] = Cache_Tags::post( $object_id );
			}
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			if ( is_string( $post_type ) && '' !== $post_type ) {
				$tags[] = Cache_Tags::post_type( $post_type );
			}
		}

		if ( is_archive() ) {
			$tags[]    = Cache_Tags::archive();
			$object_id = get_queried_object_id();
			if ( $object_id > 0 ) {
				$tags[] = is_author() ? Cache_Tags::author( $object_id ) : Cache_Tags::term( $object_id );
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Whether the current request may be served from the full-page cache.
	 *
	 * @return bool
	 */
	private function request_is_cacheable(): bool {
		if ( is_admin() || is_user_logged_in() ) {
			return false;
		}
		if ( ! $this->request_is_get() ) {
			return false;
		}
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || is_preview() || is_404() || is_search() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		$rules = Exclusions::build_rules(
			$this->as_list( $this->settings['exclude_uris'] ?? array() ),
			$this->as_list( $this->settings['exclude_query_keys'] ?? array() ),
			$this->as_list( $this->settings['exclude_cookies'] ?? array() )
		);

		return ! Exclusions::is_excluded(
			$this->request_uri(),
			$this->cookie_names(),
			$this->query_keys(),
			$rules
		);
	}

	/**
	 * Emit any queued purge directives if headers can still be sent.
	 *
	 * @return void
	 */
	private function emit_purge_now(): void {
		if ( ! $this->purge_all_queued && array() === $this->purge_queue ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}

		if ( $this->purge_all_queued ) {
			header( self::HEADER_PURGE . ': *' );
		} else {
			header( self::HEADER_PURGE . ': ' . implode( ', ', array_keys( $this->purge_queue ) ) );
		}

		$this->purge_queue      = array();
		$this->purge_all_queued = false;
	}

	/**
	 * The raw request URI, unslashed.
	 *
	 * @return string
	 */
	private function request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		return esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Names of cookies present on the request.
	 *
	 * @return string[]
	 */
	private function cookie_names(): array {
		if ( array() === $_COOKIE ) {
			return array();
		}

		return array_map( 'sanitize_text_field', array_keys( wp_unslash( $_COOKIE ) ) );
	}

	/**
	 * Query-string keys present on the request.
	 *
	 * Read-only inspection of request shape for a caching decision — no state is
	 * changed, so nonce verification does not apply.
	 *
	 * @return string[]
	 */
	private function query_keys(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cacheability check, no state change.
		if ( array() === $_GET ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cacheability check, no state change.
		return array_map( 'sanitize_text_field', array_keys( wp_unslash( $_GET ) ) );
	}

	/**
	 * Whether the current request is a GET (or HEAD) request.
	 *
	 * @return bool
	 */
	private function request_is_get(): bool {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return true;
		}

		$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );

		return 'GET' === $method || 'HEAD' === $method;
	}

	/**
	 * Coerce a settings value to a list of strings.
	 *
	 * @param mixed $value Settings value.
	 * @return string[]
	 */
	private function as_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $value ), static fn ( string $item ): bool => '' !== $item ) );
	}
}
