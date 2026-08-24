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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zinn_cache_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Zinn® Cache', 'zinn-cache' ),
			__( 'Zinn® Cache', 'zinn-cache' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
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
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::get();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Zinn® Cache', 'zinn-cache' ); ?></h1>

			<?php $this->render_status( $settings ); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php echo esc_html__( 'Full-page cache (LiteSpeed)', 'zinn-cache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable LSCache control', 'zinn-cache' ); ?></th>
						<td><?php $this->checkbox( 'lscache_enabled', (bool) $settings['lscache_enabled'], __( 'Send LiteSpeed cache-control and tag headers on cacheable pages.', 'zinn-cache' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zinn_cache_ttl"><?php echo esc_html__( 'Public cache lifetime (seconds)', 'zinn-cache' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( Settings::OPTION ); ?>[lscache_ttl]" id="zinn_cache_ttl" type="number" min="<?php echo esc_attr( (string) Settings::TTL_MIN ); ?>" max="<?php echo esc_attr( (string) Settings::TTL_MAX ); ?>" value="<?php echo esc_attr( (string) $settings['lscache_ttl'] ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Object cache (Redis)', 'zinn-cache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable Redis object cache', 'zinn-cache' ); ?></th>
						<td><?php $this->checkbox( 'object_cache_enabled', (bool) $settings['object_cache_enabled'], __( 'Install the Redis object-cache drop-in (requires the phpredis extension).', 'zinn-cache' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_redis_host"><?php echo esc_html__( 'Redis host', 'zinn-cache' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[redis_host]" id="zinn_cache_redis_host" type="text" value="<?php echo esc_attr( (string) $settings['redis_host'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_redis_port"><?php echo esc_html__( 'Redis port', 'zinn-cache' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[redis_port]" id="zinn_cache_redis_port" type="number" min="1" max="65535" value="<?php echo esc_attr( (string) $settings['redis_port'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_redis_database"><?php echo esc_html__( 'Redis database', 'zinn-cache' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[redis_database]" id="zinn_cache_redis_database" type="number" min="0" max="255" value="<?php echo esc_attr( (string) $settings['redis_database'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_redis_prefix"><?php echo esc_html__( 'Redis key prefix', 'zinn-cache' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[redis_key_prefix]" id="zinn_cache_redis_prefix" type="text" value="<?php echo esc_attr( (string) $settings['redis_key_prefix'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Automatic purging', 'zinn-cache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Smart auto-purge', 'zinn-cache' ); ?></th>
						<td><?php $this->checkbox( 'auto_purge_enabled', (bool) $settings['auto_purge_enabled'], __( 'Purge affected pages automatically when content changes.', 'zinn-cache' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Purge on updates', 'zinn-cache' ); ?></th>
						<td><?php $this->checkbox( 'purge_on_upgrade', (bool) $settings['purge_on_upgrade'], __( 'Purge everything after core, plugin, or theme updates.', 'zinn-cache' ) ); ?></td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Cache exclusions', 'zinn-cache' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Sensible WordPress and WooCommerce defaults are always applied (cart, checkout, my-account, logged-in and session pages are never cached). Add extra rules below, one per line.', 'zinn-cache' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zinn_cache_exclude_uris"><?php echo esc_html__( 'Exclude URL paths (prefix match)', 'zinn-cache' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[exclude_uris]" id="zinn_cache_exclude_uris" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['exclude_uris'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_exclude_query"><?php echo esc_html__( 'Exclude query keys', 'zinn-cache' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[exclude_query_keys]" id="zinn_cache_exclude_query" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['exclude_query_keys'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_cache_exclude_cookies"><?php echo esc_html__( 'Exclude cookie name prefixes', 'zinn-cache' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[exclude_cookies]" id="zinn_cache_exclude_cookies" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['exclude_cookies'] ) ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Maintenance', 'zinn-cache' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="zinn_cache_purge_all" />
				<?php wp_nonce_field( 'zinn_cache_purge_all' ); ?>
				<?php submit_button( __( 'Purge everything now', 'zinn-cache' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
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

		if ( ! $this->object_cache->is_redis_extension_available() ) {
			$object_state = __( 'phpredis extension missing', 'zinn-cache' );
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
