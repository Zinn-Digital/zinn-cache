<?php
/**
 * The caches in front of WordPress: which ones there are, and telling them what changed.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes every purge Zinn® Cache makes to the server cache (nginx, Varnish or Apache) and to
 * Cloudflare, and keeps a short log of what each one answered.
 *
 * A purge that is a local file operation (nginx/Apache cache files, the nginx spool folder) runs
 * at once. A purge that is a network call (an nginx purge URL, Varnish, Cloudflare) is queued and
 * sent from WP-Cron in batches, so a bulk edit never makes an editor wait on a hundred requests.
 * The queue is drained to the end on every run that has time; nothing is ever dropped for being
 * past a count.
 */
final class Server_Cache {

	/**
	 * Option: queued URLs for network drivers (not autoloaded).
	 */
	public const QUEUE_OPTION = 'zinn_cache_server_queue';

	/**
	 * Option: the last purges and what each layer answered (not autoloaded).
	 */
	public const LOG_OPTION = 'zinn_cache_purge_log';

	/**
	 * The cron hook that sends queued purges.
	 */
	public const CRON_HOOK = 'zinn_cache_server_purge';

	/**
	 * How many log rows are kept.
	 */
	private const LOG_ROWS = 50;

	/**
	 * Seconds one queue run may spend before it hands the rest to the next run.
	 */
	private const BUDGET = 20;

	/**
	 * Page-cache layers a site can name.
	 *
	 * @var string[]
	 */
	public const PAGE_LAYERS = array( 'auto', 'none', 'nginx', 'varnish', 'apache' );

	/**
	 * Normalised settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * URLs queued in this request for network drivers.
	 *
	 * @var array<string,bool>
	 */
	private array $pending = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Normalised settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'drain' ) );
		add_action( 'shutdown', array( $this, 'flush_pending' ), 5 );
	}

	/**
	 * The layer the site owner chose (`auto` when they left it to detection).
	 *
	 * A host can name it in wp-config.php with `ZINN_CACHE_SERVER_CACHE` (`nginx`, `varnish`,
	 * `apache` or `none`), which wins over the setting.
	 *
	 * @return string
	 */
	public function chosen_layer(): string {
		$chosen = defined( 'ZINN_CACHE_SERVER_CACHE' ) ? (string) constant( 'ZINN_CACHE_SERVER_CACHE' ) : (string) ( $this->settings['server_cache'] ?? 'auto' );
		return in_array( $chosen, self::PAGE_LAYERS, true ) ? $chosen : 'auto';
	}

	/**
	 * The page-cache layer in front of WordPress: the chosen one, or on `auto` the one the last
	 * detection saw. `none` when there is none; `unknown` on `auto` when the detection has not run
	 * or could not see (⛔ never read as `none` by anything that removes a cache).
	 *
	 * @return string
	 */
	public function page_layer(): string {
		$chosen = $this->chosen_layer();
		if ( 'auto' !== $chosen ) {
			return $chosen;
		}
		$probe = Probe::last();
		if ( null === $probe || ! empty( $probe['blind'] ) ) {
			return 'unknown';
		}
		$page = (string) ( $probe['page'] ?? 'none' );
		return in_array( $page, array( 'nginx', 'varnish', 'apache', 'litespeed' ), true ) ? $page : 'none';
	}

	/**
	 * Whether a server page cache (nginx, Varnish or Apache) sits in front of WordPress, so the
	 * plugin's own disk cache must not be installed as a second page cache behind it.
	 *
	 * @return bool
	 */
	public function has_server_page_cache(): bool {
		return in_array( $this->page_layer(), array( 'nginx', 'varnish', 'apache' ), true );
	}

	/**
	 * The drivers to purge, configured or not (the status screen lists unconfigured ones too).
	 *
	 * @return Driver[]
	 */
	public function drivers(): array {
		$out = array();
		// ⛔ Purges go only to a layer the site owner (or host) CHOSE. A detected layer keeps the
		// disk cache out and is shown on the status screen, but how to purge it (an address, a
		// folder) cannot be detected, and guessing would report purges that never happened.
		switch ( $this->chosen_layer() ) {
			case 'nginx':
				$out[] = new Nginx( $this->settings );
				break;
			case 'varnish':
				$out[] = new Varnish( $this->settings );
				break;
			case 'apache':
				$out[] = new Apache( $this->settings );
				break;
		}
		if ( ! empty( $this->settings['cloudflare_enabled'] ) || defined( 'ZINN_CACHE_CLOUDFLARE_TOKEN' ) ) {
			$out[] = new Cloudflare( $this->settings );
		}
		return $out;
	}

	/**
	 * Purge these URLs everywhere they may be cached in front of WordPress.
	 *
	 * Only this site's own addresses are passed on: a purge request is never a way to make the
	 * site send requests about somebody else's pages.
	 *
	 * @param string[] $urls   Absolute URLs.
	 * @param string   $source Who asked: `auto`, `manual`, `refresh`, `api`, `cli`.
	 * @param bool     $now    Send network purges in this request too (an interactive purge).
	 * @return array<string,array{ok:int,failed:int,error:string,queued?:int}> Per driver id.
	 */
	public function purge_urls( array $urls, string $source = 'auto', bool $now = false ): array {
		$urls = $this->own_urls( $urls );
		if ( array() === $urls ) {
			return array();
		}
		$results = array();
		foreach ( $this->drivers() as $driver ) {
			if ( ! $driver->is_configured() ) {
				continue;
			}
			if ( $driver->is_local() || $now ) {
				$results[ $driver->id() ] = $driver->purge_urls( $urls );
				continue;
			}
			foreach ( $urls as $url ) {
				$this->pending[ $url ] = true;
			}
			$results[ $driver->id() ] = array(
				'ok'     => 0,
				'failed' => 0,
				'error'  => '',
				'queued' => count( $urls ),
			);
		}
		if ( array() !== $results ) {
			$this->log( $source, count( $urls ), $results );
		}
		return $results;
	}

	/**
	 * Empty every cache in front of WordPress. ⛔ Explicit requests only: see {@see Driver::purge_all()}.
	 *
	 * @param string $source Who asked.
	 * @return array<string,array{ok:int,failed:int,error:string}>
	 */
	public function purge_all( string $source = 'manual' ): array {
		$results = array();
		foreach ( $this->drivers() as $driver ) {
			if ( $driver->is_configured() ) {
				$results[ $driver->id() ] = $driver->purge_all();
			}
		}
		if ( array() !== $results ) {
			$this->log( $source . ':all', 0, $results );
		}
		return $results;
	}

	/**
	 * Write this request's queued URLs to the queue and wake the cron runner (on `shutdown`).
	 *
	 * @return void
	 */
	public function flush_pending(): void {
		if ( array() === $this->pending ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( array_keys( $this->pending ) as $url ) {
			$queue[ $url ] = true;
		}
		$this->pending = array();
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Send queued purges to the network drivers, until the queue is empty or the time budget is
	 * spent; whatever is left is rescheduled, never dropped.
	 *
	 * @return int URLs still queued.
	 */
	public function drain(): int {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? array_keys( $queue ) : array();
		if ( array() === $queue ) {
			return 0;
		}
		$start   = microtime( true );
		$network = array_values(
			array_filter(
				$this->drivers(),
				static fn( Driver $d ): bool => $d->is_configured() && ! $d->is_local()
			)
		);
		$left    = $queue;
		$totals  = array();
		while ( array() !== $left && microtime( true ) - $start < self::BUDGET ) {
			$batch = array_slice( $left, 0, Cloudflare::PER_CALL );
			$retry = false;
			foreach ( $network as $driver ) {
				$result                  = $driver->purge_urls( $batch );
				$row                     = $totals[ $driver->id() ] ?? array(
					'ok'     => 0,
					'failed' => 0,
					'error'  => '',
				);
				$row['ok']              += $result['ok'];
				$row['failed']          += $result['failed'];
				$row['error']            = '' !== $result['error'] ? $result['error'] : $row['error'];
				$totals[ $driver->id() ] = $row;
				// A rate limit is the one failure worth waiting for: the batch is retried later.
				if ( $result['failed'] > 0 && str_contains( $result['error'], '429' ) ) {
					$retry = true;
				}
			}
			if ( $retry ) {
				break;
			}
			$left = array_slice( $left, count( $batch ) );
		}
		$remaining = array();
		foreach ( $left as $url ) {
			$remaining[ $url ] = true;
		}
		// Merge with anything queued while this run worked.
		$fresh = get_option( self::QUEUE_OPTION, array() );
		foreach ( ( is_array( $fresh ) ? array_keys( $fresh ) : array() ) as $url ) {
			if ( ! in_array( $url, $queue, true ) ) {
				$remaining[ $url ] = true;
			}
		}
		update_option( self::QUEUE_OPTION, $remaining, false );
		if ( array() !== $totals ) {
			$this->log( 'queue', count( $queue ) - count( $left ), $totals );
		}
		if ( array() !== $remaining ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
		return count( $remaining );
	}

	/**
	 * How many URLs wait for a network purge.
	 *
	 * @return int
	 */
	public static function queue_depth(): int {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}

	/**
	 * The recent purge log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function log_rows(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Keep only this site's own addresses (home host and site host, any scheme).
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	public function own_urls( array $urls ): array {
		$hosts = array_filter(
			array(
				strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ) ),
			)
		);
		/**
		 * Filters the hostnames purges may name (for a site served on more than one domain).
		 *
		 * @param string[] $hosts Lower-case hostnames.
		 */
		$hosts = array_map( 'strtolower', (array) apply_filters( 'zinn_cache_purge_hosts', array_values( array_unique( $hosts ) ) ) );
		$out   = array();
		foreach ( $urls as $url ) {
			$url   = esc_url_raw( trim( (string) $url ) );
			$parts = Driver::parts( $url );
			if ( null === $parts ) {
				continue;
			}
			$host = (string) preg_replace( '/:\d+$/', '', $parts['host'] );
			if ( in_array( $host, $hosts, true ) && in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
				$out[ $url ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Add a log row.
	 *
	 * @param string                                              $source  Who asked.
	 * @param int                                                 $count   URLs.
	 * @param array<string,array{ok:int,failed:int,error:string}> $results Per driver.
	 * @return void
	 */
	private function log( string $source, int $count, array $results ): void {
		$log = self::log_rows();
		array_unshift(
			$log,
			array(
				'at'      => time(),
				'source'  => $source,
				'urls'    => $count,
				'results' => $results,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_ROWS ), false );
	}
}
