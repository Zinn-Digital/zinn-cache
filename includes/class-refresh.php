<?php
/**
 * The paced refresh: what replaces "purge everything" after an update or a site-wide change.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Refreshes every cached page a batch at a time instead of emptying the cache at once.
 *
 * ⛔⛔ WHY. Emptying the whole page cache sends every visitor and every crawler to PHP in the same
 * minute. On zinnhub.com (2026-10-22) a cache that emptied itself on each plugin update put the
 * origin under exactly that load, and an uncached page there takes seconds to build. An update,
 * an activation, a menu save, a theme switch or a site-wide setting therefore no longer empties
 * anything: it ARMS this refresh, which walks the site's pages (home, then every published post of
 * every public type, then every term archive) newest first and purges a batch per minute, so
 * each page is rebuilt by the next visitor without the whole site being rebuilt at once.
 *
 * It is bounded per minute, never in total: it goes to the end of the site, whatever its size, and
 * resumes from its cursor after any interruption (§2.10). It pauses for a minute while the server
 * is busy (load average over the limit, or a host's own guard through `zinn_cache_refresh_guard`)
 * and says so on the status screen. Pages stored by the plugin's own disk cache before the refresh
 * began are also removed oldest first, so pages no list reaches (page 2 of an archive) refresh too.
 *
 * "Empty all now" on the settings screen still empties everything at once, for the rare case a
 * person wants exactly that.
 */
final class Refresh {

	/**
	 * Option holding the refresh state.
	 */
	public const OPTION = 'zinn_cache_refresh';

	/**
	 * The per-minute cron hook.
	 */
	public const CRON_HOOK = 'zinn_cache_refresh_tick';

	/**
	 * The one-minute cron schedule.
	 */
	public const SCHEDULE = 'zinn_cache_minute';

	/**
	 * The page-cache controller.
	 *
	 * @var Lscache
	 */
	private Lscache $lscache;

	/**
	 * Normalised settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * Constructor.
	 *
	 * @param Lscache             $lscache  Page-cache controller.
	 * @param array<string,mixed> $settings Normalised settings.
	 */
	public function __construct( Lscache $lscache, array $settings ) {
		$this->lscache  = $lscache;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One minute is the pace of the refresh; it runs only while a refresh is armed and unschedules itself when done.
		add_action( self::CRON_HOOK, array( $this, 'tick' ) );
	}

	/**
	 * Add the one-minute schedule.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Zinn® Cache refresh)', 'zinn-cache' ),
		);
		return $schedules;
	}

	/**
	 * Start (or restart) a refresh. Arming again while one runs starts over from the newest pages,
	 * since the newer change may affect pages the running one already passed.
	 *
	 * @param string $reason Why: `update`, `activate`, `deactivate`, `site-change`, `manual`.
	 * @return void
	 */
	public static function arm( string $reason ): void {
		$state = array(
			'armed_at' => time(),
			'reason'   => sanitize_key( $reason ),
			'stage'    => 'home',
			'cursor'   => 0,
			'type'     => '',
			'done'     => 0,
			'paused'   => '',
			'last'     => 0,
		);
		update_option( self::OPTION, $state, false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
		}
		/**
		 * Fires when a paced refresh starts.
		 *
		 * @param string $reason Why.
		 */
		do_action( 'zinn_cache_refresh_armed', $state['reason'] );
	}

	/**
	 * Stop a running refresh.
	 *
	 * @return void
	 */
	public static function stop(): void {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The current state, or null when no refresh is running.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION, null );
		return is_array( $state ) && ! empty( $state['armed_at'] ) ? $state : null;
	}

	/**
	 * Pages per minute.
	 *
	 * @return int
	 */
	public function batch_size(): int {
		return max( 10, min( 1000, (int) ( $this->settings['refresh_batch'] ?? 100 ) ) );
	}

	/**
	 * Whether the server is too busy for this minute's batch, and why.
	 *
	 * @return string Empty when it may run.
	 */
	public function busy_reason(): string {
		$limit = (float) ( $this->settings['refresh_load_limit'] ?? 0 );
		if ( $limit <= 0 ) {
			$limit = 1.5 * self::cpu_count();
		}
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			if ( is_array( $load ) && isset( $load[0] ) && (float) $load[0] > $limit ) {
				/* translators: 1: one-minute load average, 2: the limit. */
				return sprintf( __( 'Server load %1$.1f is over the limit of %2$.1f.', 'zinn-cache' ), (float) $load[0], $limit );
			}
		}
		/**
		 * Filters whether the refresh should pause this minute (a host's own load guard).
		 *
		 * @param string $reason Empty to run; any text pauses the batch and is shown as the reason.
		 */
		return (string) apply_filters( 'zinn_cache_refresh_guard', '' );
	}

	/**
	 * Run one minute's batch (the cron hook).
	 *
	 * @return void
	 */
	public function tick(): void {
		$state = self::state();
		if ( null === $state ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		$busy = $this->busy_reason();
		if ( '' !== $busy ) {
			$state['paused'] = $busy;
			update_option( self::OPTION, $state, false );
			return;
		}
		$state['paused']      = '';
		$size                 = $this->batch_size();
		list( $urls, $state ) = self::next_batch( $state, $size );

		if ( array() !== $urls ) {
			/**
			 * Filters the addresses a refresh batch purges — add each page's other language
			 * addresses here, for example.
			 *
			 * @param string[] $urls Absolute URLs.
			 */
			$urls = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'zinn_cache_refresh_urls', $urls ) ) ) );
			$this->lscache->dispatch( Purge_Planner::targets( $urls ), 'refresh' );
			/**
			 * Fires after a refresh batch was purged (Zinn® Cache Pro warms these pages again).
			 *
			 * @param string[] $urls Absolute URLs.
			 */
			do_action( 'zinn_cache_refreshed_urls', $urls );
		}
		$disk = Page_Cache::purge_older_than( (int) $state['armed_at'], $size );

		$state['done'] += count( $urls ) + $disk['deleted'];
		$state['last']  = time();
		if ( 'done' === $state['stage'] && 0 === $disk['remaining'] ) {
			self::stop();
			/**
			 * Fires when a paced refresh has reached the end of the site.
			 *
			 * @param int $pages Addresses refreshed.
			 */
			do_action( 'zinn_cache_refresh_finished', (int) $state['done'] );
			return;
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The next batch of addresses from a state, and the state after it. Walks home → posts (every
	 * public type, newest id first) → term archives (highest id first) → done.
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Batch size.
	 * @return array{0:string[],1:array<string,mixed>}
	 */
	public static function next_batch( array $state, int $size ): array {
		$urls = array();
		$have = 0;
		while ( $have < $size && 'done' !== $state['stage'] ) {
			$want = $size - $have;
			switch ( $state['stage'] ) {
				case 'home':
					$urls[]     = home_url( '/' );
					$posts_page = (int) get_option( 'page_for_posts' );
					if ( $posts_page > 0 ) {
						$link = get_permalink( $posts_page );
						if ( is_string( $link ) ) {
							$urls[] = $link;
						}
					}
					$state['stage']  = 'posts';
					$state['cursor'] = 0;
					break;
				case 'posts':
					$ids = self::post_ids_below( (int) $state['cursor'], $want );
					if ( array() === $ids ) {
						$state['stage']  = 'terms';
						$state['cursor'] = 0;
						break;
					}
					foreach ( $ids as $id ) {
						$link = get_permalink( $id );
						if ( is_string( $link ) ) {
							$urls[] = $link;
						}
					}
					$state['cursor'] = (int) end( $ids );
					break;
				case 'terms':
					$terms = self::term_ids_below( (int) $state['cursor'], $want );
					if ( array() === $terms ) {
						$state['stage'] = 'done';
						break;
					}
					foreach ( $terms as $term_id ) {
						$link = get_term_link( (int) $term_id );
						if ( is_string( $link ) ) {
							$urls[] = $link;
						}
					}
					$state['cursor'] = (int) end( $terms );
					break;
				default:
					$state['stage'] = 'done';
			}
			$have = count( $urls );
		}
		return array( array_values( array_unique( $urls ) ), $state );
	}

	/**
	 * Published, public post ids below a cursor (0 = from the top), newest id first.
	 *
	 * @param int $below Cursor.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private static function post_ids_below( int $below, int $limit ): array {
		global $wpdb;
		$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		if ( array() === $types ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$below = $below > 0 ? $below : PHP_INT_MAX;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A keyset page over the primary key (a WP_Query would load every post object); the IN list is placeholders only, filled from the one array.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ID < %d ORDER BY ID DESC LIMIT %d", array_merge( $types, array( $below, $limit ) ) ) );
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Term ids of public taxonomies with posts, below a cursor, highest first.
	 *
	 * @param int $below Cursor.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private static function term_ids_below( int $below, int $limit ): array {
		global $wpdb;
		$taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
		if ( array() === $taxonomies ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$below = $below > 0 ? $below : PHP_INT_MAX;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A keyset page over term ids; the IN list is placeholders only, filled from the one array.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($in) AND count > 0 AND term_id < %d ORDER BY term_id DESC LIMIT %d", array_merge( $taxonomies, array( $below, $limit ) ) ) );
		// phpcs:enable
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * CPU cores (for the default load limit).
	 *
	 * @return int
	 */
	private static function cpu_count(): int {
		static $count = null;
		if ( null !== $count ) {
			return $count;
		}
		$count = 1;
		if ( is_readable( '/proc/cpuinfo' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local kernel file.
			$found = preg_match_all( '/^processor\s*:/m', (string) file_get_contents( '/proc/cpuinfo' ) );
			$count = max( 1, (int) $found );
		}
		return $count;
	}
}
