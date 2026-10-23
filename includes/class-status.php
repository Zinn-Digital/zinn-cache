<?php
/**
 * One report of what is caching this site, for the status screen, the REST API, WP-CLI and AI agents.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the status report every surface shows, so the screen, `wp zinn-cache status`, the REST
 * route and the `get-status` ability can never disagree about the same site.
 */
final class Status {

	/**
	 * Pages the cache-hit sample requests (home plus the newest posts and pages).
	 */
	public const SAMPLE_SIZE = 10;

	/**
	 * The report.
	 *
	 * @param Lscache $lscache Page-cache controller.
	 * @return array<string,mixed>
	 */
	public static function report( Lscache $lscache ): array {
		$server  = $lscache->server();
		$drivers = array();
		// Counting a cache folder walks it: kept for five minutes so the screen stays quick.
		$cached = get_transient( 'zinn_cache_layer_stats' );
		$cached = is_array( $cached ) ? $cached : array();
		$fresh  = false;
		foreach ( $server->drivers() as $driver ) {
			$key = $driver->id() . ( $driver instanceof Server\Nginx ? ':' . $driver->method() : '' );
			if ( ! isset( $cached[ $key ] ) ) {
				$cached[ $key ] = $driver->stats();
				$fresh          = true;
			}
			$drivers[] = array(
				'id'         => $driver->id(),
				'label'      => $driver->label(),
				'configured' => $driver->is_configured(),
				'local'      => $driver->is_local(),
				'method'     => $driver instanceof Server\Nginx ? $driver->method() : '',
				'stats'      => $cached[ $key ],
			);
		}
		if ( $fresh ) {
			set_transient( 'zinn_cache_layer_stats', $cached, 5 * MINUTE_IN_SECONDS );
		}
		$object = null;
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'serializer' ) ) {
			$object = array( 'serializer' => (string) $wp_object_cache->serializer() );
		}
		return array(
			'engine'       => $lscache->engine(),
			'server_cache' => array(
				'chosen'   => $server->chosen_layer(),
				'resolved' => $server->page_layer(),
				'probe'    => Server\Probe::last(),
			),
			'drivers'      => $drivers,
			'disk'         => Page_Cache::is_ready() ? Page_Cache::stats() : null,
			'queue'        => Server\Server_Cache::queue_depth(),
			'refresh'      => Refresh::state(),
			'log'          => array_slice( Server\Server_Cache::log_rows(), 0, 10 ),
			'object_cache' => $object,
		);
	}

	/**
	 * The pages the hit sample requests.
	 *
	 * @return string[]
	 */
	public static function sample_urls(): array {
		$urls  = array( home_url( '/' ) );
		$posts = get_posts(
			array(
				'post_type'        => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
				'post_status'      => 'publish',
				'numberposts'      => self::SAMPLE_SIZE - 1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $id ) {
			$link = get_permalink( (int) $id );
			if ( is_string( $link ) ) {
				$urls[] = $link;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Turn what a person typed into absolute URLs on this site: a path (`/about/`) is joined to the
	 * home address; anything else must already be a full address.
	 *
	 * @param string[] $lines Typed lines.
	 * @return string[]
	 */
	public static function to_urls( array $lines ): array {
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( str_starts_with( $line, '/' ) && ! str_starts_with( $line, '//' ) ) {
				$line = home_url( $line );
			}
			$out[] = esc_url_raw( $line );
		}
		return array_values( array_filter( array_unique( $out ) ) );
	}

	/**
	 * The status screen's HTML (the settings framework passes it through wp_kses_post, so it is
	 * plain markup; the interactive parts are added by `assets/status.js` on this screen only).
	 *
	 * @param Lscache $lscache Page-cache controller.
	 * @return string
	 */
	public static function panel_html( Lscache $lscache ): string {
		$r    = self::report( $lscache );
		$rows = array();

		$rows[] = array( __( 'Pages are served by', 'zinn-cache' ), self::engine_label( (string) $r['engine'] ) );

		$probe = $r['server_cache']['probe'];
		if ( null === $probe ) {
			$seen = __( 'Not checked yet.', 'zinn-cache' );
		} elseif ( ! empty( $probe['blind'] ) ) {
			/* translators: %s: the error. */
			$seen = sprintf( __( 'The check could not reach the site (%s), so it is not known whether a server cache is in front.', 'zinn-cache' ), (string) $probe['error'] );
		} else {
			$seen = self::layer_label( (string) $probe['page'] );
			if ( 'cloudflare' === ( $probe['cdn'] ?? '' ) ) {
				$seen .= ' + ' . __( 'Cloudflare', 'zinn-cache' );
			}
			/* translators: %s: how long ago. */
			$seen .= ' — ' . sprintf( __( 'checked %s ago', 'zinn-cache' ), human_time_diff( (int) $probe['at'] ) );
		}
		$rows[] = array( __( 'Seen in front of WordPress', 'zinn-cache' ), $seen );
		$rows[] = array( __( 'Server cache setting', 'zinn-cache' ), 'auto' === $r['server_cache']['chosen'] ? __( 'Detect automatically', 'zinn-cache' ) : self::layer_label( (string) $r['server_cache']['chosen'] ) );

		foreach ( $r['drivers'] as $driver ) {
			$stats = (array) $driver['stats'];
			if ( ! $driver['configured'] ) {
				$value = __( 'Not set up yet: open the Server cache tab.', 'zinn-cache' );
			} elseif ( ! empty( $stats['visible'] ) ) {
				/* translators: 1: number of cached entries, 2: size. */
				$value = sprintf( __( '%1$s cached entries, %2$s', 'zinn-cache' ), number_format_i18n( (int) $stats['entries'] ), size_format( (int) $stats['bytes'] ) ) . ( empty( $stats['complete'] ) ? ' ' . __( '(at least — counting stopped early)', 'zinn-cache' ) : '' );
			} else {
				$value = (string) ( $stats['reason'] ?? '' );
				if ( isset( $stats['queue'] ) && null !== $stats['queue'] ) {
					/* translators: %s: number of batches. */
					$value .= ' ' . sprintf( __( 'Batches waiting for the host: %s.', 'zinn-cache' ), number_format_i18n( (int) $stats['queue'] ) );
				}
			}
			if ( ! empty( $stats['warning'] ) ) {
				$value .= ' ⚠ ' . (string) $stats['warning'];
			}
			$rows[] = array( (string) $driver['label'], $value );
		}
		if ( null !== $r['disk'] ) {
			/* translators: 1: number of pages, 2: size. */
			$rows[] = array( __( 'Disk page cache', 'zinn-cache' ), sprintf( __( '%1$s pages, %2$s', 'zinn-cache' ), number_format_i18n( (int) $r['disk']['pages'] ), size_format( (int) $r['disk']['bytes'] ) ) );
		}
		if ( $r['queue'] > 0 ) {
			$rows[] = array( __( 'Purges waiting to be sent', 'zinn-cache' ), number_format_i18n( (int) $r['queue'] ) );
		}
		if ( null !== $r['object_cache'] ) {
			$rows[] = array( __( 'Object cache serializer', 'zinn-cache' ), 'igbinary' === $r['object_cache']['serializer'] ? 'igbinary' : __( 'PHP (standard)', 'zinn-cache' ) );
		}

		$refresh = $r['refresh'];
		if ( null === $refresh ) {
			$rows[] = array( __( 'Paced refresh', 'zinn-cache' ), __( 'Not running.', 'zinn-cache' ) );
		} else {
			/* translators: 1: pages refreshed so far, 2: how long ago it started. */
			$value = sprintf( __( 'Running: %1$s pages refreshed, started %2$s ago.', 'zinn-cache' ), number_format_i18n( (int) $refresh['done'] ), human_time_diff( (int) $refresh['armed_at'] ) );
			if ( '' !== (string) $refresh['paused'] ) {
				/* translators: %s: the reason. */
				$value .= ' ' . sprintf( __( 'Paused this minute: %s', 'zinn-cache' ), (string) $refresh['paused'] );
			}
			$rows[] = array( __( 'Paced refresh', 'zinn-cache' ), $value );
		}

		/**
		 * Filters the rows of the status screen's "What is caching this site" table.
		 *
		 * @param array<int,array{0:string,1:string}> $rows   Label and value pairs.
		 * @param array<string,mixed>                 $report The status report.
		 */
		$rows = (array) apply_filters( 'zinn_cache_status_rows', $rows, $r );

		$html  = '<h2>' . esc_html__( 'What is caching this site', 'zinn-cache' ) . '</h2>';
		$html .= '<table class="widefat striped zinn-cache-status"><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		// The interactive tools: filled in by assets/status.js (route-scoped to this screen).
		$html .= '<div class="zinn-cache-tools" data-zinn-cache-tools="1"'
			. ' data-rest="' . esc_url( rest_url( 'zinn-cache/v1/' ) ) . '"'
			. ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"></div>';

		if ( array() !== $r['log'] ) {
			$html .= '<h2>' . esc_html__( 'Recent purges', 'zinn-cache' ) . '</h2><table class="widefat striped"><thead><tr><th>'
				. esc_html__( 'When', 'zinn-cache' ) . '</th><th>' . esc_html__( 'Why', 'zinn-cache' ) . '</th><th>'
				. esc_html__( 'Addresses', 'zinn-cache' ) . '</th><th>' . esc_html__( 'Result', 'zinn-cache' ) . '</th></tr></thead><tbody>';
			foreach ( $r['log'] as $entry ) {
				$parts = array();
				foreach ( (array) $entry['results'] as $layer => $res ) {
					$text = $layer . ': ' . (int) ( $res['ok'] ?? 0 ) . ' ✓';
					if ( ! empty( $res['queued'] ) ) {
						/* translators: %d: number queued. */
						$text .= ' ' . sprintf( __( '%d queued', 'zinn-cache' ), (int) $res['queued'] );
					}
					if ( ! empty( $res['failed'] ) ) {
						$text .= ' ' . (int) $res['failed'] . ' ✗ ' . (string) ( $res['error'] ?? '' );
					}
					$parts[] = $text;
				}
				$html .= '<tr><td>' . esc_html( sprintf( /* translators: %s: how long ago. */ __( '%s ago', 'zinn-cache' ), human_time_diff( (int) $entry['at'] ) ) ) . '</td><td>'
					. esc_html( (string) $entry['source'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $entry['urls'] ) ) . '</td><td>'
					. esc_html( implode( ' · ', $parts ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		return $html;
	}

	/**
	 * A layer id in words.
	 *
	 * @param string $layer Layer id.
	 * @return string
	 */
	public static function layer_label( string $layer ): string {
		$labels = array(
			'nginx'     => __( 'nginx page cache', 'zinn-cache' ),
			'varnish'   => 'Varnish',
			'apache'    => __( 'Apache disk cache (mod_cache)', 'zinn-cache' ),
			'litespeed' => __( 'LiteSpeed server cache', 'zinn-cache' ),
			'none'      => __( 'No server page cache', 'zinn-cache' ),
			'unknown'   => __( 'Not known', 'zinn-cache' ),
		);
		return $labels[ $layer ] ?? $layer;
	}

	/**
	 * An engine id in words.
	 *
	 * @param string $engine Engine id from {@see Lscache::engine()}.
	 * @return string
	 */
	public static function engine_label( string $engine ): string {
		if ( str_starts_with( $engine, 'server:' ) ) {
			/* translators: %s: the server cache, e.g. "nginx page cache". */
			return sprintf( __( '%s (Zinn® Cache purges it when content changes)', 'zinn-cache' ), self::layer_label( substr( $engine, 7 ) ) );
		}
		$labels = array(
			'litespeed' => __( 'The LiteSpeed server cache', 'zinn-cache' ),
			'disk'      => __( 'Zinn® Cache’s disk page cache', 'zinn-cache' ),
			'plugin'    => __( 'The LiteSpeed Cache plugin', 'zinn-cache' ),
			'off'       => __( 'Nothing: the page cache is switched off', 'zinn-cache' ),
			'not-ready' => __( 'Nothing yet: the disk page cache is not installed', 'zinn-cache' ),
		);
		return $labels[ $engine ] ?? $engine;
	}
}
