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
 * Controls the full-page cache.
 *
 * Three integration modes, chosen automatically:
 *
 *  - **A cache-engine plugin present** — the third-party LiteSpeed Cache plugin. We defer
 *    page caching to it and route purges through its public actions (`litespeed_purge_*`);
 *    see {@see self::CACHE_ENGINES}.
 *  - **LiteSpeed server** (our hosting: LiteSpeed Enterprise / OpenLiteSpeed with the cache
 *    module) — we stamp cacheability and cache tags on responses via
 *    `X-LiteSpeed-Cache-Control` / `X-LiteSpeed-Tag`, and purge with `X-LiteSpeed-Purge`.
 *  - **Any other server** — the disk cache ({@see Page_Cache}): pages are stored as files and
 *    served by `advanced-cache.php` before WordPress loads, and purged by tag and URL.
 *
 * ⛔ Zinn® Cache Engine (the LiteSpeed Cache fork that answered to `ZINN_CACHE_PRO_V`) was
 * retired on 2026-09-29 with no users; the slug `zinn-cache-pro` now belongs to the Pro add-on,
 * which installs on top of this plugin and never owns page caching itself.
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
	 * The file (under the cache root) holding server purge directives a WP-CLI run could not send.
	 */
	public const PENDING_FILE = 'server-purge.pending';

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
		add_action( 'init', array( $this, 'send_deferred_purge' ), 0 );
	}

	/**
	 * Whether this run has no HTTP response to carry a purge header (WP-CLI).
	 *
	 * @return bool
	 */
	public static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * The path of the deferred server-purge file.
	 *
	 * @return string
	 */
	public static function pending_file(): string {
		return Page_Cache::root() . '/' . self::PENDING_FILE;
	}

	/**
	 * Keep server purge directives for the next web request, from a run that has no response.
	 *
	 * ⛔ A LiteSpeed server purges only on a response header, and a WP-CLI run sends none, so a
	 * purge from the command line used to be dropped without a word (W16, 2026-10-05). The disk
	 * cache never needed this: the drop-in is installed only where LiteSpeed is NOT the server,
	 * so a site with a ready disk cache has nothing to defer. A file, not an option: the check
	 * runs on every uncached request, and a stat() costs less than a query.
	 *
	 * @param string[] $directives `*` or `tag=<tag>` entries.
	 * @return bool Whether anything was deferred.
	 */
	public function defer_server_purge( array $directives ): bool {
		if ( array() === $directives || ! self::is_cli() || null !== $this->cache_engine_hook_prefix()
			|| empty( $this->settings['lscache_enabled'] ) || Page_Cache::is_ready() ) {
			return false;
		}
		$file    = self::pending_file();
		$pending = is_file( $file ) ? array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local flag file.
		$pending = in_array( '*', $directives, true ) || in_array( '*', $pending, true )
			? array( '*' )
			: array_values( array_unique( array_merge( $pending, $directives ) ) );
		if ( ! wp_mkdir_p( Page_Cache::root() ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A local flag file; WP_Filesystem may be FTP-backed.
		return false !== file_put_contents( $file, implode( "\n", $pending ) . "\n", LOCK_EX );
	}

	/**
	 * Send a purge a WP-CLI run deferred, on the first web request after it (hooked to `init`).
	 *
	 * @return void
	 */
	public function send_deferred_purge(): void {
		if ( self::is_cli() ) {
			return;
		}
		$file = self::pending_file();
		if ( ! is_file( $file ) ) {
			return;
		}
		$pending = array_filter( explode( "\n", (string) file_get_contents( $file ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local flag file.
		wp_delete_file( $file );
		if ( ! $this->is_litespeed_server() ) {
			return;
		}
		foreach ( $pending as $directive ) {
			if ( '*' === $directive ) {
				$this->purge_all_queued = true;
			} elseif ( str_starts_with( $directive, 'tag=' ) ) {
				$this->purge_queue[ $directive ] = true;
			}
		}
		$this->emit_purge_now();
	}

	/**
	 * Full-page-cache engines we can hand off to, in preference order, mapped from the
	 * constant that proves the engine is loaded to the prefix its public actions use.
	 *
	 * Only the third-party LiteSpeed Cache plugin today.
	 *
	 * @var array<string,string>
	 */
	private const CACHE_ENGINES = array(
		'LSCWP_V' => 'litespeed_',
	);

	/**
	 * Whether a full-page-cache engine plugin (the third-party
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
	 * The output-buffer nesting level our own buffer occupies, or null when we hold none.
	 *
	 * ⭐ A LEVEL, not a boolean. "Did I open one?" is not enough to close one safely: the
	 * only question that matters at shutdown is *which frame is mine*, and a boolean would
	 * have us unwind the whole stack including buffers we never opened.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

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
	 * ⛔⛔ **EVERY `ob_start()` HERE IS PAIRED WITH AN EXPLICIT CLOSE, AND THAT IS A
	 * WORDPRESS.ORG REVIEW FINDING** (`docs/730`, reviewing `zinn-cache` 1.2.0). Their
	 * objection is not that the buffer is wrong — deferring the cacheability decision to
	 * flush is the whole point of it — but that it was left OPEN, to be unwound by
	 * `wp_ob_end_flush_all()` at shutdown along with everybody else's. WordPress is a shared
	 * output stack: a component that opens a frame and does not close it has made the
	 * stack's depth depend on the order plugins happened to load, and the component that
	 * finds itself misaligned is never the one that caused it.
	 *
	 * ⭐ So the buffer's own level is recorded and `close_buffer()` unwinds to exactly that
	 * frame on `shutdown` at priority 0 — before core's flush-all, and touching nothing
	 * below ours.
	 *
	 * @return void
	 */
	public function maybe_start_buffer(): void {
		if ( ! $this->should_control_cache() ) {
			return;
		}

		if ( ! ob_start( array( $this, 'finalize' ) ) ) {
			return;
		}

		$this->buffer_level = ob_get_level();

		// ⛔ Priority 0 on `shutdown`: core runs `wp_ob_end_flush_all()` AFTER the whole
		// `shutdown` action, so closing here is the last chance to do it ourselves rather
		// than have it done to us. A `template_redirect` request that never reaches
		// `shutdown` at all — `exit` inside another plugin — still flushes at the end of
		// the PHP request, exactly as it did before.
		add_action( 'shutdown', array( $this, 'close_buffer' ), 0 );
	}

	/**
	 * Close the buffer this request opened, and only that one.
	 *
	 * ⛔⛔ **IT UNWINDS TO OUR OWN FRAME AND STOPS.** Anything opened above ours is nested
	 * inside it and cannot outlive it, so flushing those is not optional; anything below
	 * ours belongs to somebody else and is never touched. ⛔ A buffer PHP will not let us
	 * remove — `zlib.output_compression`, or one started with `PHP_OUTPUT_HANDLER_REMOVABLE`
	 * off — ends the unwind rather than producing a warning on a customer's live site.
	 *
	 * ⛔⛤ **REMOVABILITY IS READ FROM `flags`, NEVER FROM A `del` KEY.** `ob_get_status()`
	 * returned `del` in PHP 5; it does not in PHP 8, and this function was written against
	 * the old shape. `empty( $status['del'] )` was therefore always true, so the loop
	 * returned on its first pass and closed nothing — a repair for an unclosed buffer that
	 * left the buffer open, with no error anywhere (§2.44: the ambiguous reading resolved to
	 * the reassuring one). `LsCacheBufferTest` is what found it, by running the real `ob_*`
	 * stack instead of agreeing with the author.
	 *
	 * ⭐ Idempotent: the level is cleared first, so a second call (a plugin firing
	 * `shutdown` by hand, a `wp_die()` path that has already flushed) does nothing.
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
			if ( 0 === ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				return;
			}
			if ( ! ob_end_flush() ) {
				return;
			}
		}
	}

	/**
	 * Output-buffer callback: stamp cacheability + tags, then return the body.
	 *
	 * @param string $buffer Buffered response body.
	 * @return string The unmodified body.
	 */
	public function finalize( string $buffer ): string {
		if ( $this->is_disk_mode() ) {
			return $this->finalize_disk( $buffer );
		}

		if ( ! headers_sent() ) {
			if ( $this->request_is_cacheable() ) {
				$ttl = $this->ttl_for_request();
				header( self::HEADER_CONTROL . ': ' . $this->cache_control_value( $ttl ) );

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
	 * Store the page for the disk cache when it may be cached, and say whether it was a miss.
	 *
	 * ⛔ Only a complete `200` HTML document is stored: a redirect, an error page, a feed or a
	 * JSON response served from the page cache is a defect the visitor sees and we do not.
	 *
	 * @param string $buffer Response body.
	 * @return string The unmodified body.
	 */
	private function finalize_disk( string $buffer ): string {
		$uri   = $this->request_uri();
		$parts = explode( '?', $uri, 2 );
		$keys  = $this->query_keys();
		if ( ! $this->request_is_cacheable() || 200 !== http_response_code() || ! Page_Cache::query_is_ignorable( $keys ) || is_feed() || ! $this->is_html_response() || false === stripos( $buffer, '</html>' ) ) {
			if ( ! headers_sent() ) {
				header( 'X-Zinn-Cache: BYPASS' );
			}
			return $buffer;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$type = 'text/html; charset=' . get_option( 'blog_charset', 'UTF-8' );
		Page_Cache::store( $host, '' === $parts[0] ? '/' : $parts[0], $buffer, $this->ttl_for_request(), $this->current_page_tags(), $type, '' );
		if ( ! headers_sent() ) {
			header( 'X-Zinn-Cache: MISS' );
		}
		return $buffer;
	}

	/**
	 * Whether the response being sent is HTML (no Content-Type header sent yet means WordPress's default, HTML).
	 *
	 * @return bool
	 */
	private function is_html_response(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}
		return true;
	}

	/**
	 * Whether this plugin should be controlling the LiteSpeed cache on this request.
	 *
	 * @return bool
	 */
	private function should_control_cache(): bool {
		return ! empty( $this->settings['lscache_enabled'] )
			&& ! $this->is_lscache_plugin_active()
			&& ( $this->is_litespeed_server() || $this->is_disk_mode() );
	}

	/**
	 * Whether pages are cached on disk by {@see Page_Cache} on this request: the page cache is on,
	 * no cache-engine plugin owns it, the server is not LiteSpeed, and the drop-in is in place.
	 *
	 * @return bool
	 */
	public function is_disk_mode(): bool {
		return ! empty( $this->settings['lscache_enabled'] )
			&& ! $this->is_lscache_plugin_active()
			&& ! $this->is_litespeed_server()
			&& Page_Cache::is_ready();
	}

	/**
	 * Which page-cache engine serves this site right now, for the status screen and the CLI.
	 *
	 * @return string `litespeed`, `disk`, `plugin`, `off` or `not-ready` (on, but the disk cache is not installed).
	 */
	public function engine(): string {
		if ( empty( $this->settings['lscache_enabled'] ) ) {
			return 'off';
		}
		if ( $this->is_lscache_plugin_active() ) {
			return 'plugin';
		}
		if ( $this->is_litespeed_server() ) {
			return 'litespeed';
		}
		return Page_Cache::is_ready() ? 'disk' : 'not-ready';
	}

	/**
	 * Purge the entire full-page cache.
	 *
	 * @param string $reason Why: 'manual', 'deploy', 'update' or 'api' (passed to `zinn_cache_purged_all`).
	 * @return void
	 */
	public function purge_all( string $reason = 'manual' ): void {
		$engine = $this->cache_engine_hook_prefix();
		if ( null !== $engine ) {
			do_action( $engine . 'purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Invoking the active cache-engine plugin's own action, not defining ours; the prefix comes from the fixed CACHE_ENGINES map.
		} elseif ( $this->is_litespeed_server() ) {
			$this->purge_all_queued = true;
			$this->emit_purge_now();
		} else {
			$this->defer_server_purge( array( '*' ) );
		}
		// The disk cache is emptied whatever engine serves now: pages stored before a switch to
		// LiteSpeed (or before the cache was turned off) must not come back if it is switched back.
		Page_Cache::purge_all();

		/**
		 * Fires after a full purge of the page cache was requested.
		 *
		 * Add-ons listen here to warm the cache again or clear caches of their own.
		 *
		 * @param string $reason Why: 'manual', 'deploy', 'update' or 'api'.
		 */
		do_action( 'zinn_cache_purged_all', $reason );
	}

	/**
	 * Dispatch a purge plan produced by {@see Purge_Planner}.
	 *
	 * @param array{purge_all?:bool,urls?:string[],tags?:string[]} $plan Purge plan.
	 * @return void
	 */
	public function dispatch( array $plan ): void {
		if ( ! empty( $plan['purge_all'] ) ) {
			$this->purge_all( 'update' );
			return;
		}

		Page_Cache::purge_tags( (array) ( $plan['tags'] ?? array() ) );
		Page_Cache::purge_urls( (array) ( $plan['urls'] ?? array() ) );

		$engine = $this->cache_engine_hook_prefix();
		if ( null !== $engine ) {
			foreach ( ( $plan['urls'] ?? array() ) as $url ) {
				do_action( $engine . 'purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Invoking the active cache-engine plugin's own action, not defining ours; the prefix comes from the fixed CACHE_ENGINES map.
			}
			return;
		}

		if ( ! $this->is_litespeed_server() ) {
			$this->defer_server_purge(
				array_map(
					static fn( $tag ): string => 'tag=' . (string) $tag,
					(array) ( $plan['tags'] ?? array() )
				)
			);
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
	 * How long THIS page should be cached for.
	 *
	 * ⭐ A shop's product pages at an hour while the rest of the site stays a week is the
	 * commonest real request, and before this the answer was "one number for everything".
	 *
	 * @return int Seconds.
	 */
	private function ttl_for_request(): int {
		$ttl = (int) ( $this->settings['lscache_ttl'] ?? 0 );

		$overrides = $this->settings['ttl_overrides'] ?? array();
		if ( is_array( $overrides ) && array() !== $overrides && is_singular() ) {
			$type = (string) get_post_type();
			foreach ( $overrides as $rule ) {
				if ( is_array( $rule ) && (string) ( $rule['post_type'] ?? '' ) === $type ) {
					$ttl = (int) $rule['ttl'];
					break;
				}
			}
		}

		/**
		 * Filters how long the current page is cached for, in seconds.
		 *
		 * Runs on every cacheable request, after the per-post-type overrides.
		 *
		 * @param int $ttl Seconds.
		 */
		$filtered = apply_filters( 'zinn_cache_ttl', $ttl );

		return is_numeric( $filtered ) ? max( 0, (int) $filtered ) : $ttl;
	}

	/**
	 * The `X-LiteSpeed-Cache-Control` value for a cacheable page.
	 *
	 * ⛔ The filtered value is accepted only in the two shapes that are safe to send —
	 * `public,max-age=N` and `private,max-age=N` — so an add-on can make a page private but can
	 * never smuggle another directive (an ESI toggle, a vary) into the header.
	 *
	 * @param int $ttl Seconds.
	 * @return string
	 */
	private function cache_control_value( int $ttl ): string {
		$default = 'public,max-age=' . $ttl;

		/**
		 * Filters the cache-control value sent to LiteSpeed for a cacheable page.
		 *
		 * @param string $value `public,max-age=N` by default; `private,max-age=N` is also accepted.
		 * @param int    $ttl   Seconds.
		 */
		$value = apply_filters( 'zinn_cache_control_header', $default, $ttl );

		return is_string( $value ) && 1 === preg_match( '/^(public|private),max-age=\d{1,9}$/', $value ) ? $value : $default;
	}

	/**
	 * Whether the current request may be served from the full-page cache.
	 *
	 * @return bool
	 */
	private function request_is_cacheable(): bool {
		if ( is_admin() ) {
			return false;
		}
		// ⛔⛔ DEFAULTS TO REFUSING A SIGNED-IN REQUEST, and the setting can only ever RELAX
		// that. Serving one visitor's personalised page to another is the single worst thing
		// a page cache can do, so the failure direction of a missing or corrupt setting must
		// be "do not cache" — which is what `?? true` gives.
		if ( is_user_logged_in() && ( $this->settings['cache_logged_out_only'] ?? true ) ) {
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

		$cacheable = ! Exclusions::is_excluded(
			$this->request_uri(),
			$this->cookie_names(),
			$this->query_keys(),
			$rules
		);

		/**
		 * Filters whether the current front-end request may be served from the page cache.
		 *
		 * ⛔ Reached only AFTER the hard refusals above (wp-admin, a signed-in visitor, a non-GET,
		 * DONOTCACHEPAGE, a preview, a 404, a search), so no filter can make those cacheable.
		 *
		 * @param bool $cacheable Whether the exclusion rules allow caching this request.
		 */
		return (bool) apply_filters( 'zinn_cache_request_cacheable', $cacheable );
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
