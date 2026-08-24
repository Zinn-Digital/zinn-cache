<?php
/**
 * The scheduled outbound-link scan.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the site's posts in bounded batches on WP-Cron and reports its outbound links.
 *
 * ## ⛔ Never on a page load. Not once.
 *
 * This runs on a **customer's** site. Reading every post and regexing its content is
 * expensive, and a plugin that makes a visitor wait for it — or that makes the admin
 * screen crawl — is a plugin we cannot ship. V1 feature-flagged this and queued it at
 * lowest priority with a 24-hour expiry, and that posture is kept:
 *
 * * it is **off** unless the deploy footprint has configured a reporting URL and secret;
 * * it runs on **WP-Cron only**, one batch per tick, {@see Link_Scanner::BATCH_POSTS}
 *   posts at a time;
 * * a full pass starts at most once every {@see Link_Scanner::PASS_INTERVAL} seconds;
 * * the POST is **non-blocking** with a two-second timeout and no retry, exactly like
 *   {@see Event_Reporter} — the panel being down must never affect the site.
 *
 * ## The cursor, and why it is a post ID and not an offset
 *
 * A pass walks `ID > cursor ORDER BY ID ASC`. An `offset`-paged walk over a table that is
 * being written to **skips rows**, and a skipped post is a post whose links are absent from
 * the snapshot — which the panel then reconciles by deleting them. An operator would watch
 * links disappear from the report at random. The ID cursor cannot skip.
 *
 * ## The pass identifier is the contract with the panel
 *
 * One pass carries one `scan_id` across every batch, and the final batch sets
 * `complete: true`. That is what lets the panel treat a scan as a **snapshot** — anything
 * it does not see this time round is gone from the site and goes from the report. ⛔ If a
 * pass is abandoned half way (the site goes down, cron stops), no completing batch is ever
 * sent and the panel deletes nothing: the report goes stale rather than wrong.
 */
final class Link_Scanner {

	/**
	 * Cron hook name. One recurring event; each tick does at most one batch.
	 */
	public const HOOK = 'zinn_cache_link_scan';

	/**
	 * Option holding the pass cursor.
	 */
	public const STATE_OPTION = 'zinn_cache_link_scan_state';

	/**
	 * Posts read per tick. Small deliberately: this is somebody's live site.
	 */
	public const BATCH_POSTS = 50;

	/**
	 * Shortest gap between the start of one full pass and the next, in seconds.
	 *
	 * ⛔ A literal, not `DAY_IN_SECONDS`. A class constant defined in terms of a
	 * WordPress constant only resolves once WordPress has loaded, which makes this class
	 * unloadable in the unit runner and in any context that requires the file early —
	 * a load-order dependency bought for nothing.
	 */
	public const PASS_INTERVAL = 86400;

	/**
	 * Register the schedule and the worker.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_configured() ) {
			// ⛔ Also UNSCHEDULE. A site whose footprint config is removed must stop
			// scanning; leaving the event registered would keep a customer's site doing
			// expensive work for a panel it can no longer reach.
			$this->unschedule();

			return;
		}

		add_action( self::HOOK, array( $this, 'run_batch' ) );

		// ⭐ LIVE UPDATES. A link added right now appears in the panel now, not on the next
		// pass. One post, one tiny POST — the full pass stays as the correctness sweep that
		// catches deletions and anything a hook missed, rather than being the only way the
		// report ever changes.
		//
		// ⛔ `save_post` fires for autosaves, revisions and bulk-edit churn too, so
		// {@see Link_Scanner::on_post_saved()} filters those out. Reporting an autosave
		// would send a POST on every keystroke-triggered save in the editor.
		add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
		// A deleted or unpublished post must leave the report as promptly as a new link
		// enters it, and neither `save_post` nor the pass fires on a trashing.
		add_action( 'deleted_post', array( $this, 'on_post_removed' ), 10, 1 );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// `hourly`, a core schedule, so no custom interval has to be registered (one
			// more thing that silently stops working when another plugin filters
			// `cron_schedules`). A tick with nothing to do costs one option read.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled event. Called on deactivation and when config disappears.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( (int) $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Whether the deploy footprint has told this site where to report.
	 *
	 * @return bool
	 */
	private function is_configured(): bool {
		return defined( 'ZINN_SITE_LINKS_URL' )
			&& defined( 'ZINN_CACHE_PANEL_SECRET' )
			&& '' !== (string) constant( 'ZINN_SITE_LINKS_URL' )
			&& '' !== (string) constant( 'ZINN_CACHE_PANEL_SECRET' );
	}

	/**
	 * One cron tick: at most one batch.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$state = $this->state();
		if ( '' === $state['scan_id'] ) {
			if ( time() - $state['finished_at'] < self::PASS_INTERVAL ) {
				return;
			}
			$new_id = wp_generate_uuid4();
			$state  = array(
				'scan_id'      => $new_id,
				'cursor'       => 0,
				'finished_at'  => $state['finished_at'],
				'posts_sent'   => 0,
				'last_scan_id' => $new_id,
			);
		}

		$ids = $this->next_post_ids( (int) $state['cursor'] );
		// ⛔ A short batch does NOT mean the pass is over — it means this page was short.
		// The pass ends when a query returns NOTHING, and that distinction is the whole
		// safety of the sweep: calling a short page "complete" would tell the panel to
		// delete every post the scan had not reached.
		$complete = array() === $ids;

		// ⛔⛔ The running total for the WHOLE pass, carried into the completing batch.
		// This POST is non-blocking, so this site never learns that a batch was lost to a
		// restart, a 502 or a rejected clock skew — it advances its cursor and eventually
		// says `complete`, and the panel would then delete the missing batch's posts as if
		// the customer had removed those links. The panel compares this against what it
		// actually received and refuses the sweep on a shortfall.
		$posts_sent = (int) $state['posts_sent'] + count( $ids );

		$this->send(
			(string) $state['scan_id'],
			$complete,
			array_map( array( $this, 'describe_post' ), $ids ),
			$posts_sent
		);

		if ( $complete ) {
			$this->save_state(
				array(
					'scan_id'      => '',
					'cursor'       => 0,
					'finished_at'  => time(),
					'posts_sent'   => 0,
					// ⛔ KEPT after the pass ends — this is the id a live delta stamps with.
					'last_scan_id' => (string) $state['scan_id'],
				)
			);

			return;
		}

		$this->save_state(
			array(
				'scan_id'      => (string) $state['scan_id'],
				'cursor'       => (int) max( $ids ),
				'finished_at'  => (int) $state['finished_at'],
				'posts_sent'   => $posts_sent,
				'last_scan_id' => (string) $state['scan_id'],
			)
		);
	}

	/**
	 * One post changed — report just that post, immediately.
	 *
	 * ⛔ This is a **delta**, not a pass: it carries no `scan_id` of an in-flight scan and
	 * never sets `complete`, so it can never trigger the snapshot sweep. It updates one
	 * post's links and leaves every other row alone. A delta that could reconcile would let
	 * a single edit delete the rest of the site's report.
	 *
	 * @param int      $post_id The post that changed.
	 * @param \WP_Post $post    The post object.
	 * @return void
	 */
	public function on_post_saved( int $post_id, $post ): void {
		if ( ! $this->is_configured() || ! $post instanceof \WP_Post ) {
			return;
		}
		// ⛔ Autosaves and revisions are not content changes a reader ever sees, and the
		// editor fires them constantly. Reporting one would put an HTTP call on the
		// customer's keystrokes.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			// Not published: it is not serving links. If it WAS published, the next pass
			// removes it — the sweep is what owns disappearance, not this hook.
			return;
		}
		if ( ! in_array( $post->post_type, $this->scanned_post_types(), true ) ) {
			return;
		}

		$this->send_delta( array( $this->describe_post( $post_id ) ) );
	}

	/**
	 * A post was deleted outright.
	 *
	 * Reported as a post with **no links**, which is exactly what it now is. ⛔ The row
	 * itself is removed by the next pass's sweep; this makes its links disappear at once,
	 * which is the half a customer notices.
	 *
	 * @param int $post_id The post that was deleted.
	 * @return void
	 */
	public function on_post_removed( int $post_id ): void {
		if ( ! $this->is_configured() ) {
			return;
		}
		$this->send_delta(
			array(
				array(
					'wp_post_id' => $post_id,
					'title'      => '',
					'url'        => '',
					'links'      => array(),
				),
			)
		);
	}

	/**
	 * Post a one-post delta. Never completes a pass, never sweeps.
	 *
	 * @param array<int,array<string,mixed>> $posts The single post, described.
	 * @return void
	 */
	private function send_delta( array $posts ): void {
		$state = $this->state();
		// ⛔ A delta carries the IN-FLIGHT pass's id when one exists, so its rows are
		// stamped with the scan that will complete — otherwise the very next `complete`
		// would sweep away the post this hook just updated. With no pass in flight it uses
		// the last finished one for the same reason.
		$scan_id = '' !== $state['scan_id'] ? (string) $state['scan_id'] : (string) $state['last_scan_id'];
		if ( '' === $scan_id ) {
			// Nothing has ever scanned this site, so there is no snapshot to update and a
			// delta would be an orphan the first pass deletes. The pass will pick it up.
			return;
		}
		$this->send( $scan_id, false, $posts, 0 );
	}

	/**
	 * Post types the scan covers.
	 *
	 * @return string[]
	 */
	private function scanned_post_types(): array {
		$types = (array) apply_filters( 'zinn_cache_link_scan_post_types', array( 'post', 'page' ) );

		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * The next page of post IDs after `$cursor`.
	 *
	 * @param int $cursor Highest post ID already reported in this pass.
	 * @return int[]
	 */
	private function next_post_ids( int $cursor ): array {
		global $wpdb;

		/**
		 * Post types the scan covers.
		 *
		 * Posts and pages by default. A site with a custom type holding editorial
		 * content can widen it; a site with ten thousand WooCommerce products has no
		 * reason to pay for scanning them.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = (array) apply_filters( 'zinn_cache_link_scan_post_types', array( 'post', 'page' ) );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );
		if ( array() === $types ) {
			// A filter that returns nothing means "scan nothing", and an empty `IN ()`
			// is a SQL syntax error. Answering "no more posts" is the honest reading and
			// it simply completes the pass.
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ( {$placeholders} ) AND ID > %d
			 ORDER BY ID ASC LIMIT %d";

		// A keyset walk over the post table. `WP_Query` cannot express `ID > cursor`, and its
		// offset paging SKIPS rows on a table being written to — a skipped post is one the
		// panel then reconciles away, so an operator would watch links vanish at random.
		// Caching a maintenance cursor query would be actively wrong.
		//
		// Every VALUE is bound. The only interpolations are `$wpdb->posts` (a core-owned
		// table name) and `$placeholders` (built from a count, never from input), which is
		// why the two PreparedSQL sniffs cannot see that this is safe.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = $wpdb->get_col(
			$wpdb->prepare( $sql, array_merge( $types, array( $cursor, self::BATCH_POSTS ) ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * One post, as the panel wants it.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function describe_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			// Deleted between the ID query and here. Reported with no links, which is
			// honest — and the panel's sweep will drop it on the completing batch.
			return array(
				'wp_post_id' => $post_id,
				'title'      => '',
				'url'        => '',
				'links'      => array(),
			);
		}

		$home_url = home_url( '/' );

		return array(
			'wp_post_id' => $post_id,
			'title'      => (string) $post->post_title,
			'url'        => (string) get_permalink( $post ),
			'links'      => Link_Extractor::extract(
				(string) $post->post_content,
				$this->home_host(),
				$home_url
			),
		);
	}

	/**
	 * This site's own host, folded the same way the panel folds a target.
	 *
	 * @return string
	 */
	private function home_host(): string {
		return Link_Extractor::bare_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * POST one batch to the panel: signed, non-blocking, fire and forget.
	 *
	 * ⛔ Byte-for-byte the scheme {@see Event_Reporter} already uses — HMAC-SHA256 over
	 * `"<timestamp>\n<body>"` with the per-site secret, timestamp in the signed material
	 * so a signature cannot be replayed for ever with a fresh header. One scheme, one
	 * secret, one place for the two ends to agree.
	 *
	 * @param string                         $scan_id    The pass identifier.
	 * @param bool                           $complete   Whether this batch ends the pass.
	 * @param array<int,array<string,mixed>> $posts      The posts in this batch.
	 * @param int                            $posts_sent Posts sent so far in this pass.
	 * @return void
	 */
	private function send( string $scan_id, bool $complete, array $posts, int $posts_sent ): void {
		$url    = (string) constant( 'ZINN_SITE_LINKS_URL' );
		$secret = (string) constant( 'ZINN_CACHE_PANEL_SECRET' );

		$payload = (string) wp_json_encode(
			array(
				'site'       => home_url( '/' ),
				'scan_id'    => $scan_id,
				'complete'   => $complete,
				'posts_sent' => $posts_sent,
				'posts'      => array_values( $posts ),
			)
		);

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . "\n" . $payload, $secret );

		wp_remote_post(
			$url,
			array(
				'timeout'  => 2,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'           => 'application/json',
					'X-Zinn-Cache-Timestamp' => $timestamp,
					'X-Zinn-Cache-Signature' => 'sha256=' . $signature,
				),
				'body'     => $payload,
			)
		);
	}

	/**
	 * The stored cursor, shaped and defaulted.
	 *
	 * @return array{scan_id:string,cursor:int,finished_at:int,posts_sent:int,last_scan_id:string}
	 */
	private function state(): array {
		$raw = get_option( self::STATE_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'scan_id'      => isset( $raw['scan_id'] ) ? (string) $raw['scan_id'] : '',
			'cursor'       => isset( $raw['cursor'] ) ? max( 0, (int) $raw['cursor'] ) : 0,
			'finished_at'  => isset( $raw['finished_at'] ) ? (int) $raw['finished_at'] : 0,
			'posts_sent'   => isset( $raw['posts_sent'] ) ? max( 0, (int) $raw['posts_sent'] ) : 0,
			// ⛔ Survives the end of a pass, unlike `scan_id`. A live delta between passes
			// must stamp its row with the scan the panel last reconciled, or the next
			// `complete` sweeps away the post the hook just updated.
			'last_scan_id' => isset( $raw['last_scan_id'] ) ? (string) $raw['last_scan_id'] : '',
		);
	}

	/**
	 * Persist the cursor.
	 *
	 * `autoload` off: this row is read by cron and by nothing else, so loading it into
	 * every request on the site would be a cost the scan imposes on every page view —
	 * which is the one thing this class is not allowed to do.
	 *
	 * @param array{scan_id:string,cursor:int,finished_at:int,posts_sent:int,last_scan_id:string} $state The cursor.
	 * @return void
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}
}
