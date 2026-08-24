<?php
/**
 * Finding the outbound links in a post's stored content.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls outbound `<a>` tags out of post content. Pure: no WordPress, no I/O.
 *
 * Split out from {@see Link_Scanner} on purpose — this half holds every decision that
 * can be wrong about a customer's data, and it is unit-tested without a WordPress
 * install. The other half is scheduling and HTTP.
 *
 * ## Three decisions, each with a consequence
 *
 * **1. The WHOLE `<a>` tag is captured, not the href and the text.** The panel stores it
 * verbatim so the bulk editor can rewrite the href *inside the existing tag* — `rel`,
 * `target` and the theme's classes survive an edit instead of being flattened. A tag
 * rebuilt from parts is a future edit that silently drops the `rel="sponsored"` a
 * customer needed for compliance.
 *
 * **2. It reads the STORED content, not the rendered page.** That is what the editor will
 * write back through `wp_update_post`, so it is the only thing an edit can act on. Links a
 * theme or plugin injects at render time are deliberately out of scope: reporting a link
 * nobody can edit would be a row with a disabled button.
 *
 * **3. Links to the site's OWN host are dropped.** An internal link is navigation, and a
 * WordPress theme puts dozens in every post — including them would bury the handful of
 * outbound links the report exists to show. ⭐ Links to *another* of the customer's sites
 * are kept, and are exactly what the panel's `Site` column is for.
 *
 * ## Why a regex and not `DOMDocument`
 *
 * `DOMDocument` parses attributes correctly and then hands back a **re-serialised** tag,
 * not the original bytes — which defeats decision 1 above, because the string stored would
 * no longer match the string in the post and an in-place rewrite could not find it. It
 * also mangles HTML5 and shortcodes. ⚠️ The known cost is that an attribute containing a
 * literal `>` (`title="a > b"`) truncates that one match; that is rare, it fails by
 * reporting a shorter tag rather than a wrong one, and it is a better trade than an editor
 * that cannot find what it is meant to change.
 */
final class Link_Extractor {

	/**
	 * Longest `<a>` tag reported. Matches the panel's own ceiling; a longer one is
	 * skipped rather than truncated, because a half tag is not an editable link.
	 */
	public const MAX_HTML_LENGTH = 4000;

	/**
	 * Most distinct links reported from one post. A page with more outbound links than
	 * this is a link farm and the tail is not information.
	 */
	public const MAX_LINKS_PER_POST = 500;

	/**
	 * Every outbound link in `$content`, with how many times each appears.
	 *
	 * @param string $content   The post's stored content.
	 * @param string $home_host The site's own host, lower-cased, no `www.`.
	 * @param string $home_url  The site's `home_url()`, used to resolve relative links.
	 * @return array<int,array{href:string,text:string,html:string,count:int}>
	 */
	public static function extract( string $content, string $home_host, string $home_url ): array {
		if ( '' === trim( $content ) ) {
			return array();
		}

		$matches = array();
		// `<a` followed by a word boundary so `<article>` is not a link. `.*?` with `s`
		// so a tag wrapping an image or several lines is one match.
		if ( ! preg_match_all( '#<a\b[^>]*>.*?</a>#is', $content, $matches ) ) {
			return array();
		}

		$found = array();
		foreach ( $matches[0] as $html ) {
			if ( strlen( $html ) > self::MAX_HTML_LENGTH ) {
				continue;
			}
			$href = self::href_of( $html );
			if ( '' === $href ) {
				continue;
			}
			$absolute = self::absolutise( $href, $home_url );
			if ( ! self::is_outbound( $absolute, $home_host ) ) {
				continue;
			}
			// Keyed on the whole tag: two links with the same href but different `rel`
			// are two different things to edit, and collapsing them would make the
			// editor rewrite one and silently leave the other.
			if ( isset( $found[ $html ] ) ) {
				++$found[ $html ]['count'];
				continue;
			}
			if ( count( $found ) >= self::MAX_LINKS_PER_POST ) {
				break;
			}
			$found[ $html ] = array(
				'href'  => $absolute,
				'text'  => self::text_of( $html ),
				'html'  => $html,
				'count' => 1,
			);
		}

		return array_values( $found );
	}

	/**
	 * The `href` attribute of an `<a>` tag, or an empty string.
	 *
	 * Handles double quotes, single quotes and the unquoted form, because all three
	 * appear in real post content and a scanner that only understood one would silently
	 * under-report a customer's links.
	 *
	 * @param string $html The whole `<a>` tag.
	 * @return string
	 */
	public static function href_of( string $html ): string {
		$match = array();
		if ( ! preg_match( '#\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $html, $match ) ) {
			return '';
		}
		foreach ( array( 2, 3, 4 ) as $group ) {
			if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
				return trim( html_entity_decode( $match[ $group ], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}

		return '';
	}

	/**
	 * The visible text of an `<a>` tag, tags stripped and whitespace collapsed.
	 *
	 * May legitimately be empty — an image link is a real link and is reported.
	 *
	 * @param string $html The whole `<a>` tag.
	 * @return string
	 */
	public static function text_of( string $html ): string {
		$inner = preg_replace( '#^<a\b[^>]*>|</a>$#is', '', $html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This class is deliberately WordPress-free so the unit suite can exercise it with no WordPress install (see the class docstring), which is where every decision that can be wrong about a customer's data is proved.
		$text = strip_tags( (string) $inner );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Resolve a link against the site's own URL when it is relative.
	 *
	 * ⚠️ Only the two cases that matter are resolved — root-relative (`/x`) and
	 * protocol-relative (`//host/x`). A path-relative link (`../x`) is left as it is and
	 * will be dropped by {@see Link_Extractor::is_outbound()} for having no host, which
	 * is correct: it is an internal link.
	 *
	 * @param string $href     The raw href.
	 * @param string $home_url The site's `home_url()`.
	 * @return string
	 */
	public static function absolutise( string $href, string $home_url ): string {
		$href = trim( $href );
		if ( '' === $href ) {
			return '';
		}
		if ( str_starts_with( $href, '//' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, no WordPress at test time. `wp_parse_url` only adds a protocol-relative fix, and the branch this sits in has already handled that case itself.
			$parsed = parse_url( $home_url, PHP_URL_SCHEME );
			$scheme = is_string( $parsed ) && '' !== $parsed ? $parsed : 'https';

			return $scheme . ':' . $href;
		}
		if ( str_starts_with( $href, '/' ) ) {
			return rtrim( $home_url, '/' ) . $href;
		}

		return $href;
	}

	/**
	 * Whether a resolved link points somewhere other than this site.
	 *
	 * `mailto:`, `tel:`, `javascript:` and bare fragments are all false — they are not
	 * outbound links to a domain, and counting one would put a number on the customer's
	 * screen that they cannot act on.
	 *
	 * @param string $url       The resolved link.
	 * @param string $home_host The site's own host, lower-cased, no `www.`.
	 * @return bool
	 */
	public static function is_outbound( string $url, string $home_host ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, no WordPress at test time (see the class docstring).
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, no WordPress at test time (see the class docstring).
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		return self::bare_host( $host ) !== self::bare_host( $home_host );
	}

	/**
	 * A host with its `www.` prefix and trailing dot removed, lower-cased.
	 *
	 * ⛔ The same fold the panel applies on write. If the two ends disagreed, a site
	 * would report `www.itself.com` as an outbound link to itself — one row per site,
	 * forever, in a report about who you link to.
	 *
	 * @param string $host A hostname.
	 * @return string
	 */
	public static function bare_host( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
