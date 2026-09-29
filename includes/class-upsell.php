<?php
/**
 * What Zinn® Cache Pro adds, shown inside the free plugin.
 *
 * @package Zinn\Cache
 */

declare( strict_types=1 );

namespace Zinn\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * The Pro upsell: a tab on the plugin's own settings screen and a "Go Pro" link on its row of the
 * Plugins screen (LOCKED PLAN §1, the zinn-chat three-piece pattern; the third piece is the readme's
 * `= Pro features =` section).
 *
 * ⭐ Inside the WordPress.org guidelines by construction: guideline 11 allows upgrade prompts that
 * are contextual or on the plugin's own settings page. Neither piece is a notice, neither needs
 * dismissing, and guideline 5 is untouched because nothing in the free plugin is locked behind Pro.
 *
 * ⛔ Hidden once Zinn® Cache Pro is installed and active: a customer who owns Pro is never asked to
 * buy it. Pro is its own plugin (it installs on top of this one), so this is a runtime test of the
 * constant Pro defines, not a file the build drops.
 *
 * ⛔ No remote call, no image loaded from us, no tracking: this renders inside somebody else's
 * wp-admin, possibly on a site we do not host. Admin only; nothing reaches the public site.
 */
final class Upsell {

	/**
	 * The paid edition's name, kept out of the translation surface (a registered mark plus an
	 * edition word must not be translated; see Zinn_Chat_Upsell::NAME for the measured reason).
	 */
	public const NAME = "\u{2068}Zinn® Cache Pro\u{2069}";

	/**
	 * The yearly prices, kept out of the translated sentence so a price change is one edit, not 57
	 * (§2.45). ⛔ They must equal `wp/freemius-plans.json` (product `zinn-cache-pro`, integer
	 * cents): `ZinnCacheUpsellTest` compares them.
	 */
	public const PRICES = array(
		'personal' => '$49',
		'business' => '$99',
		'agency'   => '$199',
	);

	/**
	 * Where somebody goes to buy it or read more.
	 */
	public const PRODUCT_URL = 'https://zinndigital.com/wordpress-plugins/zinn-cache-pro';

	/**
	 * Register the Plugins-screen link.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::pro_active() ) {
			return;
		}
		add_filter( 'plugin_action_links_' . plugin_basename( ZINN_CACHE_FILE ), array( self::class, 'action_link' ), 20 );
	}

	/**
	 * Whether Zinn® Cache Pro is installed and active.
	 *
	 * @return bool
	 */
	public static function pro_active(): bool {
		return defined( 'ZINN_CACHE_PRO_VERSION' );
	}

	/**
	 * A "Go Pro" link beside Settings on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public static function action_link( array $links ): array {
		$links['zinn_cache_go_pro'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener" style="font-weight:600">%2$s</a>',
			esc_url( self::PRODUCT_URL ),
			esc_html__( 'Go Pro', 'zinn-cache' )
		);
		return $links;
	}

	/**
	 * The settings-screen tab, or null when Pro is active.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function tab(): ?array {
		if ( self::pro_active() ) {
			return null;
		}
		return array(
			'title'  => self::NAME,
			'fields' => array( self::class, 'fields' ),
		);
	}

	/**
	 * What Pro adds, in the order a site owner cares about it.
	 *
	 * @return string[]
	 */
	public static function features(): array {
		return array(
			__( 'A cache analytics screen: hit ratio, memory, keys and the slowest cache commands.', 'zinn-cache' ),
			__( 'A slow-query and uncached-call finder that names the plugin or theme responsible.', 'zinn-cache' ),
			__( 'An in-memory APCu tier in front of Redis for the hottest keys, plus prefetching, compression and the igbinary serializer.', 'zinn-cache' ),
			__( 'Tag-based purging for posts, terms and WooCommerce products, so only what changed is cleared, with the page and object caches purged together.', 'zinn-cache' ),
			__( 'Automatic cache warm-up after a purge, WebP and AVIF images, unused-CSS removal and delayed JavaScript.', 'zinn-cache' ),
			__( 'Scheduled database clean-up with a preview, and per-page cache rules.', 'zinn-cache' ),
			__( 'Cache health alerts in your Zinn Digital® dashboard when the hit ratio drops or memory fills.', 'zinn-cache' ),
		);
	}

	/**
	 * The tab's content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		$items = '';
		foreach ( self::features() as $feature ) {
			$items .= '<li>' . esc_html( $feature ) . '</li>';
		}
		$html  = '<p>' . esc_html__( 'Everything on the other tabs is free and stays free. Pro installs on top of this plugin and adds:', 'zinn-cache' ) . '</p>';
		$html .= '<ul style="list-style:disc;padding-inline-start:1.25rem">' . $items . '</ul>';
		$html .= '<p><a class="button button-primary" href="' . esc_url( self::PRODUCT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'See what Pro costs', 'zinn-cache' ) . '</a></p>';
		$html .= '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: yearly price for one site, 2: for five sites, 3: for unlimited sites (each already formatted, e.g. $49). */
				__( 'Every plan starts with a 14-day free trial, no card needed: %1$s a year for one site, %2$s for five sites, %3$s for unlimited sites.', 'zinn-cache' ),
				self::PRICES['personal'],
				self::PRICES['business'],
				self::PRICES['agency']
			)
		) . '</p>';
		return array(
			array(
				'type'  => 'heading',
				'label' => self::NAME,
			),
			array(
				'type' => 'html',
				'html' => $html,
			),
		);
	}
}
