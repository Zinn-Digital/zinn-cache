<?php
/**
 * Admin settings screen.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Zinn Cache" settings screen and handles its actions.
 *
 * Exposes controls for LSCache (enable + TTL), the Redis object cache (toggle +
 * connection), smart auto-purge, and cache exclusions, plus a "Purge everything
 * now" button. Every write is capability-checked and nonce-protected; every
 * output is escaped; every string is translation-ready.
 */
final class Admin {

	/**
	 * Settings group used by the WordPress Settings API.
	 */
	private const OPTION_GROUP = 'zinn_cache_settings_group';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'zinn-cache';

	/**
	 * Object-cache drop-in manager.
	 *
	 * @var Object_Cache
	 */
	private Object_Cache $object_cache;

	/**
	 * LiteSpeed cache controller.
	 *
	 * @var Lscache
	 */
	private Lscache $lscache;

	/**
	 * Constructor.
	 *
	 * @param Object_Cache $object_cache Object-cache manager.
	 * @param Lscache      $lscache      LiteSpeed cache controller.
	 */
	public function __construct( Object_Cache $object_cache, Lscache $lscache ) {
		$this->object_cache = $object_cache;
		$this->lscache      = $lscache;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// ⛔⛔ ON `init`, NOT `plugins_loaded` — WordPress 6.7 raises *"Translation loading …
		// triggered too early"* for any `__()` before `init`, and a settings page is
		// translated labels by construction. Measured on WordPress 7.1.
		add_action( 'init', array( $this, 'declare_page' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zinn_cache_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_status_script' ) );
	}

	/**
	 * The status screen's script, on this plugin's screen only (§2.22: no global asset).
	 *
	 * @param string $hook The admin page hook suffix.
	 * @return void
	 */
	public function enqueue_status_script( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen is open.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( \Zinn_Cache_Admin_UI::page_slug() !== $page || '' === $hook ) {
			return;
		}
		wp_enqueue_script( 'zinn-cache-status', plugins_url( 'assets/status.js', ZINN_CACHE_FILE ), array(), ZINN_CACHE_VERSION, true );
		wp_add_inline_script(
			'zinn-cache-status',
			'window.zinnCacheStatus = ' . wp_json_encode( self::status_strings() ) . ';',
			'before'
		);
	}

	/**
	 * The status script's words, translated here so they reach every locale with the rest.
	 *
	 * @return array<string,string>
	 */
	public static function status_strings(): array {
		return array(
			'purgeTitle'     => __( 'Purge addresses', 'zinn-cache' ),
			'purgeHelp'      => __( 'One address per line: a path such as /about/ or a full address on this site. Each one is removed from every cache layer at once.', 'zinn-cache' ),
			'purgeLabel'     => __( 'Addresses to purge', 'zinn-cache' ),
			'purgeButton'    => __( 'Purge these addresses', 'zinn-cache' ),
			'checkTitle'     => __( 'Check what is caching', 'zinn-cache' ),
			'checkHelp'      => __( 'Requests the home page and the newest pages twice, as a visitor would, and reports which cache answered and how often it was a hit.', 'zinn-cache' ),
			'checkButton'    => __( 'Check now', 'zinn-cache' ),
			/* translators: 1: pages checked, 2: pages that did not answer. */
			'sampled'        => __( 'Pages checked: %1$d. Pages that did not answer: %2$d.', 'zinn-cache' ),
			'hits'           => __( 'served from the cache', 'zinn-cache' ),
			'noLayers'       => __( 'No cache layer named itself in the responses.', 'zinn-cache' ),
			'reload'         => __( 'Reload this screen to see the detection above updated.', 'zinn-cache' ),
			'refreshTitle'   => __( 'Paced refresh', 'zinn-cache' ),
			'refreshHelp'    => __( 'Refreshes every page a batch per minute instead of emptying the cache at once. It starts by itself after an update or a site-wide change.', 'zinn-cache' ),
			'refreshStart'   => __( 'Start a refresh now', 'zinn-cache' ),
			'refreshStop'    => __( 'Stop the refresh', 'zinn-cache' ),
			'refreshRunning' => __( 'The refresh is running.', 'zinn-cache' ),
			'refreshStopped' => __( 'No refresh is running.', 'zinn-cache' ),
			'working'        => __( 'Working…', 'zinn-cache' ),
			'ok'             => __( 'purged', 'zinn-cache' ),
			'queued'         => __( 'queued (sent within a minute)', 'zinn-cache' ),
			'failedCount'    => __( 'failed', 'zinn-cache' ),
			'failed'         => __( 'The request failed', 'zinn-cache' ),
			'nothing'        => __( 'No cache layer needed purging.', 'zinn-cache' ),
		);
	}

	/*
	 * ⛔⛔ THE OLD `Settings →` SCREEN IS DELETED, NOT LEFT IN PLACE. ⚖️ The owner ruled on
	 * 2026-09-08 that every plugin lives under ONE `Zinn Digital®` menu, so `add_menu()` and
	 * the screen it pointed at were no longer hooked by anything — and a settings screen that
	 * still compiles, still reads the same option and can never be reached is the worst kind
	 * of dead code: the next person to change a field changes it in the copy nobody sees.
	 * The live declaration is `declare_page()`.
	 *
	 * ⛔ `register_settings()` / `register_setting()` SURVIVES and must. It is what makes
	 * `sanitize_option_{$option}` fire on the framework's own `update_option`, so the plugin's
	 * own normaliser still guards every write. Deleting it alongside the screen would have
	 * removed a control while removing something that looked like the same thing.
	 */


	/**
	 * Declare the screen through the shared Zinn settings framework.
	 *
	 * ⚖️ **Owner ruling, 2026-09-08: ONE top-level `Zinn` menu**, and *"customisable options
	 * … so they can properly contorl it and setit up"*. The three exclusion lists were
	 * already here; what was missing was everything a site owner needs to make the cache fit
	 * their site — a lifetime that differs by post type, a browser-cache lifetime, and the
	 * ability to keep a page out of the cache without editing a text area of URLs.
	 *
	 * @return void
	 */
	public function declare_page(): void {
		\Zinn_Cache_Admin_UI::register(
			array(
				'title'      => __( 'Cache', 'zinn-cache' ),
				'option'     => Settings::OPTION,
				'position'   => 50,
				'connection' => array( __CLASS__, 'status' ),
				'tabs'       => array(
					'status'     => array(
						'title'  => __( 'Status', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'status_fields' ),
					),
					'page'       => array(
						'title'  => __( 'Page cache', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'page_fields' ),
					),
					'object'     => array(
						'title'  => __( 'Object cache', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'object_fields' ),
					),
					'purging'    => array(
						'title'  => __( 'Purging', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'purge_fields' ),
					),
					'exclusions' => array(
						'title'  => __( 'Exclusions', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'exclusion_fields' ),
					),
					'server'     => array(
						'title'  => __( 'Server cache', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'server_fields' ),
					),
					'lighter'    => array(
						'title'  => __( 'Lighter pages', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'lighter_fields' ),
					),
					'agents'     => array(
						'title'  => __( 'AI agents (MCP)', 'zinn-cache' ),
						'fields' => array( __CLASS__, 'agent_fields' ),
					),
				) + array_filter( array( 'pro' => Upsell::tab() ) ),
				'actions'    => array(
					array(
						'id'       => 'zinn_cache_purge_all',
						'label'    => __( 'Empty all now', 'zinn-cache' ),
						'confirm'  => __( 'Every cached page is discarded at once, on every cache layer this plugin purges, and every visitor will reach WordPress until pages are rebuilt. Updates do not need this: they refresh pages gradually by themselves. Continue?', 'zinn-cache' ),
						'callback' => array( __CLASS__, 'purge_all_now' ),
					),
				),
			)
		);
	}

	/**
	 * The Status tab: what is caching the site, and the tools to act on it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function status_fields(): array {
		return array(
			array(
				'type' => 'html',
				'html' => Status::panel_html( new Lscache( Settings::get() ) ),
			),
		);
	}

	/**
	 * The Server cache tab: nginx, Varnish, Apache and Cloudflare.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function server_fields(): array {
		$probe    = Server\Probe::last();
		$detected = null === $probe ? __( 'not checked yet', 'zinn-cache' ) : ( ! empty( $probe['blind'] ) ? __( 'the check could not reach the site', 'zinn-cache' ) : Status::layer_label( (string) $probe['page'] ) );
		$is_layer = static fn( string $layer ): array => array( 'server_cache' => $layer );
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'A page cache in front of WordPress', 'zinn-cache' ),
				'description' => __( 'Many hosts put nginx, Varnish or Apache’s own cache in front of WordPress. Those answer before PHP runs, so they are faster than any plugin; Zinn® Cache then does not add a second page cache behind them. It tells them which pages changed instead. On a LiteSpeed server nothing here is needed.', 'zinn-cache' ),
			),
			array(
				'key'         => 'server_cache',
				'type'        => 'select',
				'label'       => __( 'Server page cache', 'zinn-cache' ),
				/* translators: %s: what the last check saw. */
				'description' => sprintf( __( 'Last check: %s. “Detect automatically” keeps Zinn® Cache’s own page cache out when a server cache is seen, but purges only reach a server cache you choose here, with how to reach it.', 'zinn-cache' ), $detected ),
				'choices'     => array(
					'auto'    => __( 'Detect automatically', 'zinn-cache' ),
					'none'    => __( 'None — use Zinn® Cache’s page cache', 'zinn-cache' ),
					'nginx'   => __( 'nginx (proxy_cache or fastcgi_cache)', 'zinn-cache' ),
					'varnish' => 'Varnish',
					'apache'  => __( 'Apache mod_cache (disk)', 'zinn-cache' ),
				),
				'default'     => 'auto',
			),
			array(
				'key'            => 'server_cache_headers',
				'type'           => 'toggle',
				'label'          => __( 'Cache headers', 'zinn-cache' ),
				'checkbox_label' => __( 'Tell the server cache how long a page may be kept (s-maxage), and mark personal pages private', 'zinn-cache' ),
				'description'    => __( 'Signed-in visitors, excluded addresses and form posts always get Cache-Control: private, no-store. This switch adds the shared-cache lifetime to pages that may be cached.', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'     => 'nginx_purge_method',
				'type'    => 'radio',
				'label'   => __( 'How to purge nginx', 'zinn-cache' ),
				'choices' => array(
					'http'  => __( 'A purge request per page (the ngx_cache_purge module)', 'zinn-cache' ),
					'files' => __( 'Delete the cache files (WordPress can read the cache folder)', 'zinn-cache' ),
					'spool' => __( 'Write the addresses to a folder for the host’s purger', 'zinn-cache' ),
				),
				'default' => 'http',
				'show_if' => $is_layer( 'nginx' ),
			),
			array(
				'key'         => 'nginx_purge_endpoint',
				'type'        => 'text',
				'label'       => __( 'Purge address', 'zinn-cache' ),
				'description' => __( 'Leave blank for {scheme}://{host}/purge{request_uri}. Tokens: {scheme}, {host}, {path}, {request_uri}, {url}.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'http',
				),
			),
			array(
				'key'     => 'nginx_http_verb',
				'type'    => 'select',
				'label'   => __( 'Request method', 'zinn-cache' ),
				'choices' => array(
					'GET'   => 'GET',
					'PURGE' => 'PURGE',
				),
				'default' => 'GET',
				'show_if' => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'http',
				),
			),
			array(
				'key'         => 'nginx_purge_address',
				'type'        => 'text',
				'label'       => __( 'Send purges to', 'zinn-cache' ),
				'description' => __( 'For example http://127.0.0.1. Needed when a CDN is in front of nginx: a purge sent to the public address would reach the CDN, not nginx. The site’s host name goes in the Host header.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'http',
				),
			),
			array(
				'key'         => 'nginx_cache_path',
				'type'        => 'text',
				'label'       => __( 'Cache folder', 'zinn-cache' ),
				'description' => __( 'The folder in proxy_cache_path or fastcgi_cache_path, for example /var/cache/nginx/site. Only nginx cache files in it are ever deleted.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'files',
				),
			),
			array(
				'key'         => 'nginx_cache_levels',
				'type'        => 'text',
				'label'       => __( 'levels=', 'zinn-cache' ),
				'description' => __( 'The levels= value of the same line, usually 1:2.', 'zinn-cache' ),
				'default'     => '1:2',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'files',
				),
			),
			array(
				'key'         => 'nginx_cache_key',
				'type'        => 'text',
				'label'       => __( 'Cache key', 'zinn-cache' ),
				'description' => __( 'Your proxy_cache_key or fastcgi_cache_key. Leave blank for $scheme$request_method$host$request_uri.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'files',
				),
			),
			array(
				'key'         => 'nginx_key_variants',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Other key variables', 'zinn-cache' ),
				'description' => __( 'For a variable of your own in the key, one per line as name=value1|value2 (an empty value allowed), for example encoding=gzip|. Every combination is purged.', 'zinn-cache' ),
				'rows'        => 3,
				'default'     => array(),
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'files',
				),
			),
			array(
				'key'         => 'nginx_spool_dir',
				'type'        => 'text',
				'label'       => __( 'Spool folder', 'zinn-cache' ),
				'description' => __( 'A folder outside the web root that your host’s purger reads. Each purge is one file with one address per line.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array(
					'server_cache'       => 'nginx',
					'nginx_purge_method' => 'spool',
				),
			),
			array(
				'key'     => 'varnish_host',
				'type'    => 'text',
				'label'   => __( 'Varnish address', 'zinn-cache' ),
				'default' => '127.0.0.1',
				'show_if' => $is_layer( 'varnish' ),
			),
			array(
				'key'     => 'varnish_port',
				'type'    => 'number',
				'label'   => __( 'Varnish port', 'zinn-cache' ),
				'min'     => 1,
				'max'     => 65535,
				'default' => 6081,
				'show_if' => $is_layer( 'varnish' ),
			),
			array(
				'key'         => 'varnish_method',
				'type'        => 'select',
				'label'       => __( 'Purge with', 'zinn-cache' ),
				'description' => __( 'Your VCL must accept it from this server (an ACL in vcl_recv).', 'zinn-cache' ),
				'choices'     => array(
					'PURGE' => 'PURGE',
					'BAN'   => 'BAN',
				),
				'default'     => 'PURGE',
				'show_if'     => $is_layer( 'varnish' ),
			),
			array(
				'key'         => 'apache_cache_root',
				'type'        => 'text',
				'label'       => __( 'CacheRoot', 'zinn-cache' ),
				'description' => __( 'The CacheRoot folder of mod_cache_disk.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => $is_layer( 'apache' ),
			),
			array(
				'key'     => 'apache_dir_levels',
				'type'    => 'number',
				'label'   => __( 'CacheDirLevels', 'zinn-cache' ),
				'min'     => 0,
				'max'     => 20,
				'default' => 2,
				'show_if' => $is_layer( 'apache' ),
			),
			array(
				'key'     => 'apache_dir_length',
				'type'    => 'number',
				'label'   => __( 'CacheDirLength', 'zinn-cache' ),
				'min'     => 1,
				'max'     => 20,
				'default' => 1,
				'show_if' => $is_layer( 'apache' ),
			),
			array(
				'type'        => 'heading',
				'label'       => __( 'Cloudflare', 'zinn-cache' ),
				'description' => __( 'When Cloudflare caches your pages (a Cache Everything rule or APO), changed pages are purged from its edge by address.', 'zinn-cache' ),
			),
			array(
				'key'            => 'cloudflare_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Cloudflare purge', 'zinn-cache' ),
				'checkbox_label' => __( 'Purge changed pages from Cloudflare', 'zinn-cache' ),
				'default'        => false,
			),
			array(
				'key'         => 'cloudflare_zone_id',
				'type'        => 'text',
				'label'       => __( 'Zone ID', 'zinn-cache' ),
				'description' => __( 'On the Overview page of your domain in Cloudflare.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'cloudflare_enabled' => true ),
			),
			array(
				'key'         => 'cloudflare_api_token',
				'type'        => 'password',
				'secret'      => true,
				'sanitize'    => 'raw_secret',
				'label'       => __( 'API token', 'zinn-cache' ),
				'description' => Server\Cloudflare::token_is_constant() ? __( 'Set in wp-config.php (ZINN_CACHE_CLOUDFLARE_TOKEN); that one is used.', 'zinn-cache' ) : __( 'Create a token with only Zone → Cache Purge → Purge, for this zone. It is never shown again or exported.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'cloudflare_enabled' => true ),
			),
			array(
				'key'            => 'cloudflare_purge_everything',
				'type'           => 'toggle',
				'label'          => __( 'Empty all', 'zinn-cache' ),
				'checkbox_label' => __( 'Let “Empty all now” empty the whole Cloudflare zone too', 'zinn-cache' ),
				'description'    => __( 'Off by default: it empties every host name on the zone and sends every visitor to your server at once.', 'zinn-cache' ),
				'default'        => false,
				'show_if'        => array( 'cloudflare_enabled' => true ),
			),
		);
	}

	/**
	 * The Lighter pages tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function lighter_fields(): array {
		return array(
			array(
				'key'         => 'heartbeat_mode',
				'type'        => 'select',
				'label'       => __( 'WordPress Heartbeat', 'zinn-cache' ),
				'description' => __( 'The Heartbeat is the browser asking WordPress every 15–60 seconds whether anything changed (autosave, post locks). Each ask is a PHP request the page cache cannot serve.', 'zinn-cache' ),
				'choices'     => array(
					'default'   => __( 'Leave it as WordPress sets it', 'zinn-cache' ),
					'reduce'    => __( 'Once a minute everywhere', 'zinn-cache' ),
					'off_front' => __( 'Off on the public site, normal in the dashboard', 'zinn-cache' ),
				),
				'default'     => 'default',
			),
			array(
				'key'            => 'font_display_swap',
				'type'           => 'toggle',
				'label'          => __( 'Web fonts', 'zinn-cache' ),
				'checkbox_label' => __( 'Show text at once while Google Fonts load (display=swap), and connect to the font server early', 'zinn-cache' ),
				'default'        => false,
			),
			array(
				'key'         => 'preconnect_origins',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Connect early to', 'zinn-cache' ),
				'description' => __( 'One https:// address per line: servers your pages load from (a CDN, a payment or analytics script).', 'zinn-cache' ),
				'rows'        => 3,
				'default'     => array(),
			),
			array(
				'key'         => 'cdn_url',
				'type'        => 'url',
				'label'       => __( 'CDN address', 'zinn-cache' ),
				'description' => __( 'For a pull CDN such as BunnyCDN or KeyCDN, for example https://cdn.example.com. Images, CSS, JavaScript and fonts under /wp-content/ and /wp-includes/ are served from it. Pages are not. Leave blank when Cloudflare proxies your whole site: it is already the CDN.', 'zinn-cache' ),
				'default'     => '',
			),
			array(
				'key'         => 'cdn_exclude',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Keep on this site', 'zinn-cache' ),
				'description' => __( 'Addresses containing any of these (one per line) are not moved to the CDN.', 'zinn-cache' ),
				'rows'        => 3,
				'default'     => array(),
			),
		);
	}

	/**
	 * The AI agents (MCP) switch, then the connection details (docs/901).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agent_fields(): array {
		return array(
			array(
				'key'            => 'mcp',
				'type'           => 'toggle',
				'label'          => __( 'AI agents (MCP)', 'zinn-cache' ),
				'checkbox_label' => __( 'Allow AI agents (MCP)', 'zinn-cache' ),
				'description'    => __( 'Off: the tools below and their REST routes are not registered at all.', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'type' => 'html',
				'html' => Mcp\Abilities::panel_html(),
			),
		);
	}

	/**
	 * The full-page cache controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function page_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Full-page cache', 'zinn-cache' ),
				'description' => __( 'On a LiteSpeed server, pages are served by the web server before WordPress runs. On any other server, this plugin stores each page as a file and serves it before WordPress loads. This is where nearly all the speed comes from.', 'zinn-cache' ),
			),
			array(
				'key'            => 'lscache_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Page cache', 'zinn-cache' ),
				'checkbox_label' => __( 'Cache pages for visitors who are not signed in', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'         => 'lscache_ttl',
				'type'        => 'number',
				'label'       => __( 'How long a page stays cached', 'zinn-cache' ),
				'description' => __( 'Seconds. A week suits most sites; a news site publishing hourly wants far less.', 'zinn-cache' ),
				'min'         => Settings::TTL_MIN,
				'max'         => Settings::TTL_MAX,
				'default'     => 604800,
				'show_if'     => array( 'lscache_enabled' => true ),
			),
			array(
				'key'         => 'browser_ttl',
				'type'        => 'number',
				'label'       => __( 'How long a visitor’s browser keeps images and scripts', 'zinn-cache' ),
				'description' => __( 'Seconds. Applies to images, CSS and JavaScript only — never to pages. Set to 0 to leave this to your host.', 'zinn-cache' ),
				'min'         => 0,
				'max'         => 31536000,
				'default'     => 0,
			),
			array(
				'key'            => 'cache_logged_out_only',
				'type'           => 'toggle',
				'label'          => __( 'Signed-in visitors', 'zinn-cache' ),
				'checkbox_label' => __( 'Never serve a cached page to a signed-in visitor', 'zinn-cache' ),
				'description'    => __( 'On is the safe answer and the default. Turn it off only if you know every cached page is identical for every signed-in user — a membership site is the usual reason not to.', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'         => 'ttl_overrides',
				'type'        => 'repeater',
				'label'       => __( 'Different lifetimes for different content', 'zinn-cache' ),
				'description' => __( 'For example: a shop’s product pages for an hour, while everything else stays a week.', 'zinn-cache' ),
				'add_label'   => __( 'Add a rule', 'zinn-cache' ),
				'default'     => array(),
				'show_if'     => array( 'lscache_enabled' => true ),
				'fields'      => array(
					array(
						'key'     => 'post_type',
						'type'    => 'select',
						'label'   => __( 'Content type', 'zinn-cache' ),
						'choices' => array( __CLASS__, 'post_type_choices' ),
					),
					array(
						'key'   => 'ttl',
						'type'  => 'number',
						'label' => __( 'Seconds', 'zinn-cache' ),
						'min'   => Settings::TTL_MIN,
						'max'   => Settings::TTL_MAX,
					),
				),
			),
		);
	}

	/**
	 * The object-cache controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function object_fields(): array {
		if ( defined( 'ZINN_CACHE_MANAGED_OBJECT_CACHE' ) && (bool) constant( 'ZINN_CACHE_MANAGED_OBJECT_CACHE' ) ) {
			// The host installed and configured the object cache for this site, with credentials of
			// its own; offering a switch and a password box here would only let a customer break it.
			return array(
				array(
					'type'        => 'heading',
					'label'       => __( 'Object cache (Redis)', 'zinn-cache' ),
					'description' => __( 'Your host runs a private Redis object cache for this site and keeps it configured. There is nothing to set up here; the status at the top of this screen shows whether it is working.', 'zinn-cache' ),
				),
			);
		}

		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Object cache (Redis)', 'zinn-cache' ),
				'description' => __( 'Caches the results of database queries. It helps most on a busy shop or a site with a lot of signed-in traffic, where the page cache cannot.', 'zinn-cache' ),
			),
			array(
				'key'            => 'object_cache_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Object cache', 'zinn-cache' ),
				'checkbox_label' => __( 'Install the Redis object-cache drop-in', 'zinn-cache' ),
				'description'    => __( 'Needs the phpredis extension. If it is missing, the box above this screen says so.', 'zinn-cache' ),
				'default'        => false,
			),
			array(
				'key'     => 'redis_host',
				'type'    => 'text',
				'label'   => __( 'Redis host', 'zinn-cache' ),
				'default' => '127.0.0.1',
				'show_if' => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'     => 'redis_port',
				'type'    => 'number',
				'label'   => __( 'Redis port', 'zinn-cache' ),
				'min'     => 1,
				'max'     => 65535,
				'default' => 6379,
				'show_if' => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_database',
				'type'        => 'number',
				'label'       => __( 'Redis database', 'zinn-cache' ),
				'description' => __( 'Give each site on a shared Redis its own number, or they will clear each other’s cache.', 'zinn-cache' ),
				'min'         => 0,
				'max'         => 255,
				'default'     => 0,
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_username',
				'type'        => 'text',
				'label'       => __( 'Redis username', 'zinn-cache' ),
				'description' => __( 'Only for a Redis server with ACL users (Redis 6 or later). Leave blank to sign in with the password alone.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_password',
				'type'        => 'password',
				'secret'      => true,
				'label'       => __( 'Redis password', 'zinn-cache' ),
				'description' => __( 'Leave blank if your Redis does not need one.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_serializer',
				'type'        => 'select',
				'label'       => __( 'Serializer', 'zinn-cache' ),
				'description' => __( 'igbinary stores the same data in less memory and reads it faster, when the PHP igbinary extension is installed. Changing it is safe at any time: each stored value is read in the format it was written in.', 'zinn-cache' ),
				'choices'     => array(
					'auto'     => __( 'igbinary when available, otherwise PHP', 'zinn-cache' ),
					'igbinary' => 'igbinary',
					'php'      => __( 'PHP (standard)', 'zinn-cache' ),
				),
				'default'     => 'auto',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
			array(
				'key'         => 'redis_key_prefix',
				'type'        => 'text',
				'label'       => __( 'Key prefix', 'zinn-cache' ),
				'description' => __( 'An alternative to a separate database number when you cannot have one.', 'zinn-cache' ),
				'default'     => '',
				'show_if'     => array( 'object_cache_enabled' => true ),
			),
		);
	}

	/**
	 * When the cache clears itself.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function purge_fields(): array {
		return array(
			array(
				'key'            => 'auto_purge_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Smart auto-purge', 'zinn-cache' ),
				'checkbox_label' => __( 'Clear affected pages automatically when content changes', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'            => 'purge_on_upgrade',
				'type'           => 'toggle',
				'label'          => __( 'After updates', 'zinn-cache' ),
				'checkbox_label' => __( 'Refresh every page, a batch a minute, after a core, plugin or theme update', 'zinn-cache' ),
				'description'    => __( 'Updates, plugin activations, theme and menu changes never empty the cache at once any more: emptying it sends every visitor to WordPress in the same minute. The paced refresh rebuilds the site gradually instead.', 'zinn-cache' ),
				'default'        => true,
			),
			array(
				'key'         => 'refresh_batch',
				'type'        => 'number',
				'label'       => __( 'Pages refreshed per minute', 'zinn-cache' ),
				'description' => __( 'The refresh always reaches the end of the site; this only sets its pace.', 'zinn-cache' ),
				'min'         => 10,
				'max'         => 1000,
				'default'     => 100,
			),
			array(
				'key'         => 'refresh_load_limit',
				'type'        => 'number',
				'label'       => __( 'Pause the refresh above this server load', 'zinn-cache' ),
				'description' => __( 'The one-minute load average. 0 means 1.5 × the number of CPU cores.', 'zinn-cache' ),
				'min'         => 0,
				'max'         => 1000,
				'default'     => 0,
			),
			array(
				'key'            => 'purge_on_comment',
				'type'           => 'toggle',
				'label'          => __( 'After a comment', 'zinn-cache' ),
				'checkbox_label' => __( 'Clear a post’s page when a comment on it is approved', 'zinn-cache' ),
				'description'    => __( 'Turn this off on a very busy comment section, where it can clear the same page hundreds of times an hour.', 'zinn-cache' ),
				'default'        => true,
			),
		);
	}

	/**
	 * What is never cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function exclusion_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Never cache these', 'zinn-cache' ),
				'description' => __( 'Sensible WordPress and WooCommerce defaults are always applied — cart, checkout, my-account and any signed-in session are never cached, whatever is below.', 'zinn-cache' ),
			),
			array(
				'key'         => 'exclude_uris',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Addresses', 'zinn-cache' ),
				'description' => __( 'One path per line, for example <code>/basket</code>. A trailing <code>*</code> matches everything under it.', 'zinn-cache' ),
				'rows'        => 5,
				'default'     => array(),
			),
			array(
				'key'         => 'exclude_query_keys',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Query parameters', 'zinn-cache' ),
				'description' => __( 'A page carrying one of these is never cached — for example a one-time preview token.', 'zinn-cache' ),
				'rows'        => 4,
				'default'     => array(),
			),
			array(
				'key'         => 'exclude_cookies',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Cookies', 'zinn-cache' ),
				'description' => __( 'A visitor carrying one of these is always served a fresh page.', 'zinn-cache' ),
				'rows'        => 4,
				'default'     => array(),
			),
		);
	}

	/**
	 * The site's public post types, as a choice list.
	 *
	 * @return array<string, string>
	 */
	public static function post_type_choices(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$out[ (string) $type->name ] = (string) ( $type->labels->name ?? $type->name );
		}
		return $out;
	}

	/**
	 * Clear everything, from the Tools row.
	 *
	 * @return array<string, string>
	 */
	public static function purge_all_now(): array {
		do_action( 'zinn_cache_purge_all' );
		return array(
			'kind'    => 'success',
			'message' => __( 'Every cached page has been discarded. They will be rebuilt as visitors arrive.', 'zinn-cache' ),
		);
	}

	/**
	 * Whether the caching this screen configures is actually happening.
	 *
	 * ⛔⛔ **THIS IS THE QUESTION THE OLD SCREEN COULD NOT ANSWER.** A tick against
	 * "Enable Redis object cache" says what was asked for; it says nothing about whether the
	 * extension exists, the drop-in is installed, or the server is LiteSpeed at all. A
	 * customer whose host does not run LiteSpeed had a screen that looked entirely correct
	 * and a cache that did nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings  = Settings::get();
		$litespeed = self::server_is_litespeed();
		$redis_ok  = extension_loaded( 'redis' );

		// ⭐ First, because it is the failure nothing else shows: a drop-in that cannot sign in
		// serves every page from memory, so the site looks fine and nothing is cached.
		$object = self::object_cache_health();
		if ( null !== $object && ! $object['ok'] ) {
			return array(
				'state'   => 'degraded',
				'summary' => __( 'The object cache is installed, but it is not storing anything.', 'zinn-cache' ),
				'reason'  => self::health_reason( $object['reason'] ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		// On a server that is not LiteSpeed the page cache is the disk cache (Page_Cache). It needs
		// its drop-in and WP_CACHE; when either is missing, say which, because "on" is otherwise a
		// tick that caches nothing.
		$elsewhere = ( new Lscache( $settings ) )->page_cache_is_elsewhere();
		if ( $settings['lscache_enabled'] && ! $elsewhere && ! Page_Cache::is_ready() ) {
			$result = Page_Cache::sync( $settings, false );
			if ( is_wp_error( $result ) || ! Page_Cache::is_ready() ) {
				return array(
					'state'   => 'degraded',
					'summary' => __( 'The page cache is on, but pages are not being stored yet.', 'zinn-cache' ),
					'reason'  => is_wp_error( $result ) ? $result->get_error_message() : __( 'WordPress has not loaded the page-cache drop-in yet. It starts on the next page load; if it does not, check that WP_CACHE is true in wp-config.php.', 'zinn-cache' ),
					'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
				);
			}
		}

		if ( $settings['object_cache_enabled'] && ! $redis_ok ) {
			return array(
				'state'   => 'degraded',
				'summary' => __( 'The object cache is on, but this server has no Redis support.', 'zinn-cache' ),
				'reason'  => __( 'The phpredis extension is not installed, so the drop-in cannot be used and WordPress is falling back to its own in-memory cache. Ask your host to enable phpredis.', 'zinn-cache' ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		if ( ! $settings['lscache_enabled'] && ! $settings['object_cache_enabled'] ) {
			return array(
				'state'   => 'standalone',
				'summary' => __( 'Nothing is being cached.', 'zinn-cache' ),
				'reason'  => __( 'Both caches are switched off, so this plugin is not making the site any faster.', 'zinn-cache' ),
				'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
			);
		}

		return array(
			'state'   => 'connected',
			'summary' => __( 'Caching is active on this site.', 'zinn-cache' ),
			'details' => self::detail_rows( $settings, $litespeed, $redis_ok ),
		);
	}

	/**
	 * The live write/read check of this plugin's object cache, or null when it is not in use.
	 *
	 * ⛔ Not "is the drop-in there": a drop-in that cannot sign in to Redis serves every page from
	 * memory, so the site looks fine and nothing is cached. Only a round trip through Redis says
	 * the cache works.
	 *
	 * @return array{ok:bool,reason:string,ms:float}|null
	 */
	public static function object_cache_health(): ?array {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'health' ) || ! defined( get_class( $wp_object_cache ) . '::MARKER' ) ) {
			return null;
		}

		$health = $wp_object_cache->health();

		return is_array( $health ) && isset( $health['ok'], $health['reason'] ) ? $health : null;
	}

	/**
	 * A customer-facing explanation for an object-cache failure reason code.
	 *
	 * @param string $reason Reason code from the drop-in.
	 * @return string
	 */
	public static function health_reason( string $reason ): string {
		switch ( $reason ) {
			case 'auth':
				return __( 'Redis refused the username or password. Check the Redis credentials in wp-config.php or on this screen.', 'zinn-cache' );
			case 'noperm':
				return __( 'Redis accepted the login but does not allow this site to store its keys. The Redis user needs permission for this site\'s key prefix.', 'zinn-cache' );
			case 'select':
				return __( 'Redis refused the database number. Check the Redis database setting.', 'zinn-cache' );
			case 'oom':
				return __( 'Redis is out of memory and is refusing new data.', 'zinn-cache' );
			case 'no-extension':
				return __( 'The phpredis extension is not installed, so WordPress is using its own in-memory cache. Ask your host to enable phpredis.', 'zinn-cache' );
			case 'disabled':
				return __( 'WP_REDIS_DISABLED is set in wp-config.php, so the object cache is switched off.', 'zinn-cache' );
			case 'connect':
				return __( 'The Redis server could not be reached. Check the Redis host and port, and that Redis is running.', 'zinn-cache' );
			default:
				return __( 'Redis did not return the value that was just written to it. Check the Redis server\'s logs.', 'zinn-cache' );
		}
	}

	/**
	 * The rows under the status headline.
	 *
	 * @param array<string, mixed> $settings  Current settings.
	 * @param bool                 $litespeed Whether the server is LiteSpeed.
	 * @param bool                 $redis_ok  Whether phpredis is loaded.
	 * @return array<int, array<string, string>>
	 */
	private static function detail_rows( array $settings, bool $litespeed, bool $redis_ok ): array {
		return array(
			array(
				'label' => __( 'Pages are served by', 'zinn-cache' ),
				'value' => $litespeed
					? __( 'LiteSpeed — pages are cached by the web server', 'zinn-cache' )
					: Status::engine_label( ( new Lscache( $settings ) )->engine() ),
			),
			array(
				'label' => __( 'Pages stored on disk', 'zinn-cache' ),
				'value' => number_format_i18n( Page_Cache::stats()['pages'] ),
			),
			array(
				'label' => __( 'Redis support', 'zinn-cache' ),
				'value' => $redis_ok
					? __( 'phpredis is installed', 'zinn-cache' )
					: __( 'phpredis is not installed', 'zinn-cache' ),
			),
			array(
				'label' => __( 'Page lifetime', 'zinn-cache' ),
				'value' => (string) (int) $settings['lscache_ttl'] . 's',
			),
		);
	}

	/**
	 * Is this server LiteSpeed?
	 *
	 * ⛔ Read from the server signature rather than from a constant, and it returns FALSE
	 * when it cannot tell. A caching plugin reporting "all good" because it could not see is
	 * the reassuring-zero failure §2.44 is about.
	 *
	 * @return bool
	 */
	private static function server_is_litespeed(): bool {
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$software = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) );
			if ( false !== strpos( $software, 'litespeed' ) || false !== strpos( $software, 'openlitespeed' ) ) {
				return true;
			}
		}
		return isset( $_SERVER['X-LSCACHE'] ) || defined( 'LSCWP_V' );
	}

	/**
	 * Register the single settings option with its normalising sanitiser.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitise submitted settings through the pure normaliser.
	 *
	 * @param mixed $input Raw (unslashed) submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		return Settings::normalize( is_array( $input ) ? $input : array() );
	}

	/**
	 * Re-sync the object-cache drop-in whenever settings are saved.
	 *
	 * @return void
	 */
	public function on_settings_updated(): void {
		$settings = Settings::get();
		delete_transient( 'zinn_cache_layer_stats' );

		$result = ( new Object_Cache( $settings ) )->sync();
		if ( $result instanceof \WP_Error ) {
			set_transient( 'zinn_cache_notice', $result->get_error_message(), 30 );
		}

		Htaccess::sync( ! empty( $settings['lscache_enabled'] ) );
	}

	/**
	 * Handle the "Purge everything now" action.
	 *
	 * @return void
	 */
	public function handle_purge_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to purge the cache.', 'zinn-cache' ) );
		}

		check_admin_referer( 'zinn_cache_purge_all' );

		$this->lscache->purge_all();
		set_transient( 'zinn_cache_notice', __( 'Cache purge requested.', 'zinn-cache' ), 30 );

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Show any queued admin notice, plus environment warnings.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( 'zinn_cache_notice' );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( 'zinn_cache_notice' );
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( $notice )
			);
		}

		if ( $this->object_cache->is_foreign_dropin() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Zinn® Cache: another object-cache drop-in is installed, so the Redis object cache is not managed by this plugin.', 'zinn-cache' )
			);
		}
	}


	/**
	 * Render the environment/status panel at the top of the screen.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return void
	 */
	private function render_status( array $settings ): void {
		$lscache_state = $this->lscache->is_available()
			? __( 'detected', 'zinn-cache' )
			: __( 'not detected', 'zinn-cache' );

		$health = self::object_cache_health();
		if ( ! $this->object_cache->is_redis_extension_available() ) {
			$object_state = __( 'phpredis extension missing', 'zinn-cache' );
		} elseif ( null !== $health && ! $health['ok'] ) {
			$object_state = __( 'installed, not storing anything', 'zinn-cache' );
		} elseif ( $this->object_cache->is_enabled() && $this->object_cache->is_managed() ) {
			$object_state = __( 'active, managed by your host', 'zinn-cache' );
		} elseif ( $this->object_cache->is_enabled() ) {
			$object_state = __( 'active', 'zinn-cache' );
		} else {
			$object_state = __( 'inactive', 'zinn-cache' );
		}

		printf(
			'<p>%1$s <strong>%2$s</strong> &nbsp;|&nbsp; %3$s <strong>%4$s</strong></p>',
			esc_html__( 'LiteSpeed cache:', 'zinn-cache' ),
			esc_html( $lscache_state ),
			esc_html__( 'Redis object cache:', 'zinn-cache' ),
			esc_html( $object_state )
		);

		unset( $settings );
	}

	/**
	 * Render a boolean setting as a hidden "0" + checkbox "1" pair.
	 *
	 * The hidden field guarantees the key is always submitted, so unchecking a
	 * box reliably stores `false` (avoiding the classic missing-checkbox pitfall).
	 *
	 * @param string $key     Settings key.
	 * @param bool   $checked Whether the box is currently checked.
	 * @param string $label   Label shown beside the checkbox.
	 * @return void
	 */
	private function checkbox( string $key, bool $checked, string $label ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		printf(
			'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
