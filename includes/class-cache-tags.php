<?php
/**
 * LiteSpeed cache-tag naming.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `X-LiteSpeed-Tag` values this plugin stamps on cacheable responses
 * and the matching `X-LiteSpeed-Purge` tags it invalidates on content change.
 *
 * Tagging our own pages lets us do *targeted* purges through the LiteSpeed web
 * server directly — without requiring the third-party LiteSpeed Cache WP plugin
 * to be installed. All names are namespaced with a short prefix so they never
 * clash with tags emitted by other software on the same host.
 *
 * Pure and WordPress-free, so the tag scheme is fully unit-testable.
 */
final class Cache_Tags {

	/**
	 * Prefix applied to every tag this plugin emits.
	 */
	public const PREFIX = 'zc.';

	/**
	 * Tag for a single piece of content (post, page, or custom post type).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post( int $post_id ): string {
		return self::PREFIX . 'post.' . $post_id;
	}

	/**
	 * Tag for every archive of a given post type.
	 *
	 * @param string $post_type Post-type slug.
	 * @return string
	 */
	public static function post_type( string $post_type ): string {
		return self::PREFIX . 'pt.' . self::slug( $post_type );
	}

	/**
	 * Tag for a taxonomy term archive.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public static function term( int $term_id ): string {
		return self::PREFIX . 'term.' . $term_id;
	}

	/**
	 * Tag for an author archive.
	 *
	 * @param int $author_id Author (user) ID.
	 * @return string
	 */
	public static function author( int $author_id ): string {
		return self::PREFIX . 'author.' . $author_id;
	}

	/**
	 * Tag for the blog posts index / home page.
	 *
	 * @return string
	 */
	public static function home(): string {
		return self::PREFIX . 'home';
	}

	/**
	 * Tag for the site front page.
	 *
	 * @return string
	 */
	public static function front_page(): string {
		return self::PREFIX . 'frontpage';
	}

	/**
	 * Tag for any archive listing (date/category/tag/CPT index).
	 *
	 * @return string
	 */
	public static function archive(): string {
		return self::PREFIX . 'archive';
	}

	/**
	 * Tag for feeds.
	 *
	 * @return string
	 */
	public static function feed(): string {
		return self::PREFIX . 'feed';
	}

	/**
	 * The set of tags to purge when a single post changes: the post itself plus
	 * the listings it can appear on (its type's archives, the blog index, the
	 * front page, generic archives, and feeds).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post-type slug.
	 * @return string[]
	 */
	public static function for_post_change( int $post_id, string $post_type ): array {
		return array(
			self::post( $post_id ),
			self::post_type( $post_type ),
			self::home(),
			self::front_page(),
			self::archive(),
			self::feed(),
		);
	}

	/**
	 * Lower-case and reduce a slug to a tag-safe token.
	 *
	 * @param string $value Raw slug.
	 * @return string
	 */
	private static function slug( string $value ): string {
		$value = strtolower( trim( $value ) );

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}
}
