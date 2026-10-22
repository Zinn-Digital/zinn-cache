<?php
/**
 * A change made in Zinn® SEO reaches visitors at once: the pages it changed leave the cache.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ WHY THIS EXISTS. Zinn® SEO keeps a page's title, description, robots setting and social
 * text as fields of their own, and saves them without saving the post: its bulk editor, its
 * list-table quick edit, its importers, its WP-CLI commands and an AI agent all write a field
 * and nothing else. No `save_post` fires, so the page cache kept serving the old `<title>` and
 * the old "noindex" until the page expired. The same holds for its site-wide settings (title
 * patterns, the knowledge graph, verification tags: every page's head) and for a redirect added
 * for an address that has a cached page, which the cache would keep answering in place of the
 * redirect.
 *
 * What is purged:
 *
 * - a field of a post: that post's own page, and nothing else (a listing shows the post's title
 *   and excerpt, never its SEO title, so the listings stay cached);
 * - a field of a category, tag or author: that archive's address;
 * - a redirect: the address it redirects from (a pattern can match any address: everything);
 * - a site-wide setting a page can show, a title pattern's translation, an import from another
 *   SEO plugin, the no-trace switch: everything. A setting that is only on a file Zinn® SEO
 *   serves itself (the sitemap, llms.txt) or in its own notes purges nothing.
 *
 * ⭐ A bulk change is bounded: past {@see self::BURST} separate purges in one request the whole
 * cache is purged once and the rest of the request purges nothing more, so an import of a
 * hundred thousand pages is one purge, not a hundred thousand.
 *
 * Zinn® SEO is heard through its documented actions only. On a site without it none of them
 * ever fires, and this class does nothing.
 */
final class Seo_Purge {

	/** Separate purges one request may make before everything is purged once instead. */
	public const BURST = 25;

	/**
	 * Zinn® SEO settings no visitor's page shows, by key or key prefix: the files it serves
	 * outside the page cache (sitemaps, llms.txt, robots.txt is not cached), its e-mails and
	 * reports, and the notes it keeps for itself. `redirects_store` and `redirects_regex` are
	 * rewritten with every redirect saved, which is purged on its own, exactly.
	 *
	 * ⛔ Only what can NEVER be on a page belongs here. A setting missing from this list purges
	 * everything (the safe side); one wrongly added would leave stale pages.
	 */
	public const NOT_ON_PAGES = array( 'redirects_store', 'redirects_regex', 'sitemap_', 'llms_', 'indexnow_', 'gsc_property', 'notices_', 'notfound_', 'broken_', 'keep_data', 'import_live', 'notrace_hash' );

	/** Fields only the editor reads: a change of one changes no visitor's page. */
	public const EDITOR_ONLY = array( 'focus', 'related', 'cornerstone', 'lock', 'brief', 'focus_markets', 'schema_import' );

	/**
	 * The purge controller.
	 *
	 * @var Purge_Controller
	 */
	private Purge_Controller $purger;

	/**
	 * What this request already purged: `p<id>` or an address => true.
	 *
	 * @var array<string, bool>
	 */
	private array $done = array();

	/**
	 * Everything was purged in this request.
	 *
	 * @var bool
	 */
	private bool $all = false;

	/**
	 * Constructor.
	 *
	 * @param Purge_Controller $purger The purge controller.
	 */
	public function __construct( Purge_Controller $purger ) {
		$this->purger = $purger;
	}

	/**
	 * Listen to Zinn® SEO (only while automatic purging is on: the caller checks).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'zinn_seo_field_saved', array( $this, 'on_field' ), 20, 5 );
		add_action( 'zinn_seo_settings_saved', array( $this, 'on_settings' ), 20, 2 );
		add_action( 'zinn_seo_redirect_saved', array( $this, 'on_redirect' ), 20, 2 );
		add_action( 'zinn_seo_import_finished', array( $this, 'on_import' ), 20, 2 );
		add_action( 'zinn_seo_import_switched', array( $this, 'on_site_change' ), 20, 0 );
		add_action( 'zinn_seo_notrace_changed', array( $this, 'on_site_change' ), 20, 0 );
		add_action( 'zinn_seo_site_texts_changed', array( $this, 'on_site_change' ), 20, 0 );
	}

	/**
	 * What one saved field asks for. Pure.
	 *
	 * @param string $kind   `post`, `term` or `user`.
	 * @param int    $id     Object ID.
	 * @param string $name   Field name.
	 * @param mixed  $before Old value.
	 * @param mixed  $after  New value.
	 * @return string '' (nothing), `post`, `term` or `user`.
	 */
	public static function target( string $kind, int $id, string $name, $before, $after ): string {
		if ( $id <= 0 || in_array( $name, self::EDITOR_ONLY, true ) || ! in_array( $kind, array( 'post', 'term', 'user' ), true ) ) {
			return '';
		}
		// Loose on purpose: an unset field reads as '' and is cleared to its type's empty value.
		if ( $before == $after || ( self::is_empty( $before ) && self::is_empty( $after ) ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- "1" and 1, '' and array() are the same stored state.
			return '';
		}

		return $kind;
	}

	/**
	 * Is a field value "nothing stored"?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		return null === $value || '' === $value || false === $value || 0 === $value || array() === $value;
	}

	/**
	 * The address a redirect rule answers for, or '' when it can match any address. Pure.
	 *
	 * @param mixed $rule A rule as Zinn® SEO hands it.
	 * @return string|null A path or a whole address, '' for a pattern, null when there is no rule.
	 */
	public static function redirect_source( $rule ): ?string {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$source = trim( (string) ( $rule['source'] ?? '' ) );
		if ( '' === $source ) {
			return null;
		}
		if ( ! empty( $rule['is_regex'] ) || ! empty( $rule['regex'] ) || false !== strpos( $source, '*' ) ) {
			return '';
		}

		return 1 === preg_match( '#^https?://#i', $source ) ? $source : '/' . ltrim( $source, '/' );
	}

	/**
	 * `zinn_seo_field_saved`.
	 *
	 * @param mixed $kind   `post`, `term` or `user`.
	 * @param mixed $id     Object ID.
	 * @param mixed $name   Field name.
	 * @param mixed $before Old value.
	 * @param mixed $after  New value.
	 * @return void
	 */
	public function on_field( $kind, $id, $name, $before = null, $after = null ): void {
		if ( ! self::wanted() ) {
			return;
		}
		$id = (int) $id;
		switch ( self::target( (string) $kind, $id, (string) $name, $before, $after ) ) {
			case 'post':
				$link = get_permalink( $id );
				if ( is_string( $link ) && '' !== $link && $this->take( 'p' . $id ) ) {
					$this->purger->purge_plan(
						array(
							'urls' => array( $link ),
							'tags' => array( Cache_Tags::post( $id ) ),
						)
					);
				}
				break;
			case 'term':
				$link = get_term_link( $id );
				if ( is_string( $link ) ) {
					$this->url( $link );
				}
				break;
			case 'user':
				$this->url( (string) get_author_posts_url( $id ) );
				break;
		}
	}

	/**
	 * `zinn_seo_settings_saved`: every page's head may have changed.
	 *
	 * @param mixed $after  Settings now.
	 * @param mixed $before Settings before.
	 * @return void
	 */
	public function on_settings( $after, $before = null ): void {
		if ( self::settings_matter( $after, $before ) && self::wanted() ) {
			$this->everything();
		}
	}

	/**
	 * Did a save change a setting a visitor's page can show? Pure.
	 *
	 * @param mixed $after  Settings now.
	 * @param mixed $before Settings before.
	 * @return bool
	 */
	public static function settings_matter( $after, $before ): bool {
		if ( ! is_array( $after ) || ! is_array( $before ) ) {
			return $after !== $before; // Not the shape promised: any difference counts.
		}
		foreach ( array_unique( array_merge( array_keys( $after ), array_keys( $before ) ) ) as $key ) {
			if ( ( $after[ $key ] ?? null ) === ( $before[ $key ] ?? null ) ) {
				continue;
			}
			$shown = true;
			foreach ( self::NOT_ON_PAGES as $prefix ) {
				if ( 0 === strpos( (string) $key, $prefix ) ) {
					$shown = false;
					break;
				}
			}
			if ( $shown ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `zinn_seo_redirect_saved`: the cache must stop answering for an address that now redirects
	 * (and for the one a changed rule used to redirect from).
	 *
	 * @param mixed $after  The rule now.
	 * @param mixed $before The rule before.
	 * @return void
	 */
	public function on_redirect( $after, $before = null ): void {
		if ( ! self::wanted() ) {
			return;
		}
		foreach ( array( $after, $before ) as $rule ) {
			$source = self::redirect_source( $rule );
			if ( '' === $source ) {
				$this->everything();
				return;
			}
			if ( null !== $source ) {
				$this->url( '/' === $source[0] ? home_url( $source ) : $source );
			}
		}
	}

	/**
	 * `zinn_seo_import_finished`: titles and descriptions were copied in from another plugin.
	 *
	 * @param mixed $source Source id.
	 * @param mixed $dry    It was only a count.
	 * @return void
	 */
	public function on_import( $source, $dry = false ): void {
		unset( $source );
		if ( ! $dry && self::wanted() ) {
			$this->everything();
		}
	}

	/**
	 * `zinn_seo_import_switched`, `zinn_seo_notrace_changed`, `zinn_seo_site_texts_changed` (a
	 * title pattern's translation was saved): every page's head changed.
	 *
	 * @return void
	 */
	public function on_site_change(): void {
		if ( self::wanted() ) {
			$this->everything();
		}
	}

	/**
	 * May a change made in Zinn® SEO purge the cache? Not when the site switched Zinn® SEO's work
	 * with this plugin off (its Integrations screen keeps the list in `integrations_off`).
	 *
	 * @return bool
	 */
	private static function wanted(): bool {
		$on = true;
		if ( function_exists( 'zinn_seo' ) ) {
			$api = call_user_func( 'zinn_seo' );
			$off = is_object( $api ) && is_callable( array( $api, 'setting' ) ) ? (array) $api->setting( 'integrations_off' ) : array();
			$on  = ! in_array( 'zinn-cache', array_map( 'strval', $off ), true );
		}

		/**
		 * Filters whether a change made in Zinn® SEO purges the pages it changed.
		 *
		 * @param bool $on On.
		 */
		return (bool) apply_filters( 'zinn_cache_purge_on_seo_change', $on );
	}

	/**
	 * Purge the whole cache, once per request.
	 *
	 * @return void
	 */
	private function everything(): void {
		if ( $this->all ) {
			return;
		}
		$this->all = true;
		$this->purger->purge_all();
	}

	/**
	 * Purge one address.
	 *
	 * @param string $url Address.
	 * @return void
	 */
	private function url( string $url ): void {
		if ( '' !== $url && $this->take( $url ) ) {
			$this->purger->purge_plan( array( 'urls' => array( $url ) ) );
		}
	}

	/**
	 * May this target be purged now? False when it already was, or when everything was; past the
	 * burst limit everything is purged instead.
	 *
	 * @param string $key Target.
	 * @return bool
	 */
	private function take( string $key ): bool {
		switch ( self::decide( array_keys( $this->done ), $this->all, $key, self::BURST ) ) {
			case 'purge':
				$this->done[ $key ] = true;
				return true;
			case 'everything':
				$this->everything();
				return false;
		}

		return false;
	}

	/**
	 * What to do with one more target in this request. Pure.
	 *
	 * @param array<int, string> $done  Targets already purged.
	 * @param bool               $all   Everything was purged.
	 * @param string             $key   The target.
	 * @param int                $burst How many separate purges a request may make.
	 * @return string `purge` (this target), `everything` (the limit is reached: purge all, once)
	 *                or `skip` (already purged, alone or with everything).
	 */
	public static function decide( array $done, bool $all, string $key, int $burst ): string {
		if ( $all || in_array( $key, $done, true ) ) {
			return 'skip';
		}

		return count( $done ) >= $burst ? 'everything' : 'purge';
	}
}
