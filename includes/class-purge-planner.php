<?php
/**
 * Purge planning.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a normalised, de-duplicated purge plan from a content change.
 *
 * Like {@see Exclusions}, this is WordPress-free: the integration layer resolves
 * permalinks/archive URLs (which needs WordPress) and hands them here, where they
 * are normalised, de-duplicated, and reduced to a single decision — purge
 * everything, or purge a specific set of URLs and/or LiteSpeed cache tags.
 */
final class Purge_Planner {

	/**
	 * An empty (no-op) plan.
	 *
	 * @return array{purge_all:bool,urls:string[],tags:string[]}
	 */
	public static function nothing(): array {
		return array(
			'purge_all' => false,
			'urls'      => array(),
			'tags'      => array(),
		);
	}

	/**
	 * A plan that flushes the entire full-page cache.
	 *
	 * @return array{purge_all:bool,urls:string[],tags:string[]}
	 */
	public static function everything(): array {
		return array(
			'purge_all' => true,
			'urls'      => array(),
			'tags'      => array(),
		);
	}

	/**
	 * Build a targeted plan from a set of URLs and/or tags.
	 *
	 * @param string[] $urls URLs to purge.
	 * @param string[] $tags LiteSpeed cache tags to purge.
	 * @return array{purge_all:bool,urls:string[],tags:string[]}
	 */
	public static function targets( array $urls, array $tags = array() ): array {
		return array(
			'purge_all' => false,
			'urls'      => self::normalize_urls( $urls ),
			'tags'      => self::normalize_tags( $tags ),
		);
	}

	/**
	 * Merge several plans into one.
	 *
	 * If any plan purges everything, the merged plan purges everything (and drops
	 * the now-redundant targeted lists). Otherwise URL and tag lists are unioned.
	 *
	 * @param array<int,array{purge_all?:bool,urls?:string[],tags?:string[]}> $plans Plans to merge.
	 * @return array{purge_all:bool,urls:string[],tags:string[]}
	 */
	public static function merge( array $plans ): array {
		$urls = array();
		$tags = array();

		foreach ( $plans as $plan ) {
			if ( ! empty( $plan['purge_all'] ) ) {
				return self::everything();
			}
			$urls = array_merge( $urls, $plan['urls'] ?? array() );
			$tags = array_merge( $tags, $plan['tags'] ?? array() );
		}

		return self::targets( $urls, $tags );
	}

	/**
	 * Whether a plan would purge nothing at all.
	 *
	 * @param array{purge_all?:bool,urls?:string[],tags?:string[]} $plan Plan to test.
	 * @return bool
	 */
	public static function is_empty( array $plan ): bool {
		return empty( $plan['purge_all'] )
			&& array() === ( $plan['urls'] ?? array() )
			&& array() === ( $plan['tags'] ?? array() );
	}

	/**
	 * Trim, drop fragments/empties, and de-duplicate a URL list.
	 *
	 * @param string[] $urls Raw URL list.
	 * @return string[]
	 */
	public static function normalize_urls( array $urls ): array {
		$clean = array();

		foreach ( $urls as $url ) {
			$url = trim( $url );

			$fragment_pos = strpos( $url, '#' );
			if ( false !== $fragment_pos ) {
				$url = substr( $url, 0, $fragment_pos );
			}

			if ( '' !== $url ) {
				$clean[] = $url;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Trim, drop empties, and de-duplicate a tag list.
	 *
	 * @param string[] $tags Raw tag list.
	 * @return string[]
	 */
	public static function normalize_tags( array $tags ): array {
		$clean = array();

		foreach ( $tags as $tag ) {
			$tag = trim( $tag );
			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
