=== Zinn® Cache ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: cache, page cache, object cache, redis, performance
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side cache control for the LiteSpeed (LSCache) full-page cache, with smart auto-purge, a Redis object-cache toggle, and safe cache exclusions.

== Description ==

Zinn® Cache integrates a WordPress site with the server-side cache layer of the Zinn Digital® hosting platform. It is designed to run on LiteSpeed Enterprise / OpenLiteSpeed hosts with the LSCache module, and degrades gracefully wherever a cache layer is absent — caching is a per-blueprint capability.

**What it does**

* **LSCache control.** Stamps `X-LiteSpeed-Cache-Control` and `X-LiteSpeed-Tag` headers on cacheable front-end responses so the LiteSpeed web server can serve full pages without hitting PHP or MySQL. A configurable public cache lifetime is applied. If the third-party LiteSpeed Cache plugin is present, page caching is deferred to it instead.
* **Smart auto-purge.** When content changes — a post or page is saved, trashed or deleted, a comment is added or moderated, a taxonomy term is edited, the theme is switched, a plugin is (de)activated, or WordPress/plugins/themes are updated — only the affected pages (and the listings they appear on) are purged, via targeted LiteSpeed cache tags. Purges are mirrored to the control-plane panel over a signed webhook so the CDN/edge purges in step.
* **Redis object cache.** A one-click toggle installs a self-contained Redis object-cache drop-in (requires the phpredis extension), offloading repeated database reads. The plugin never overwrites another caching plugin's drop-in, and the drop-in falls back to an in-memory cache if Redis is unreachable, so the site keeps working.
* **Safe cache exclusions.** Ships with sensible WordPress and WooCommerce/Easy Digital Downloads defaults — cart, checkout, my-account, REST/AJAX, preview, search, and any logged-in or session-cookie request are never cached. Extra path, query-key, and cookie-prefix rules can be added per site.

**Remote purging**

A REST endpoint, `POST /wp-json/zinn-cache/v1/purge`, lets the control plane purge everything, specific URLs, or specific tags. It is authenticated either by a logged-in administrator or by an HMAC-SHA256 signature over the request body using the site's `ZINN_CACHE_PANEL_SECRET`.

**One-click admin login**

On Zinn-hosted sites, `GET /wp-json/zinn-sso/v1/login` accepts a short-lived signed token issued by the Zinn® dashboard and opens a wp-admin session for the site's administrator — no password, and no second set of credentials to manage. Tokens are signed with the site's own key (`ZINN_SSO_KEY`), are bound to this site (`ZINN_SITE_ID`), expire after about two minutes, and can only be used once. The route is **not registered at all** unless both constants are defined, so the endpoint does not exist on a site that has not been given a key.

== Installation ==

1. Upload the `zinn-cache` folder to `/wp-content/plugins/` (this is done automatically as part of the Zinn® deploy footprint).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Zinn® Cache** to configure the full-page cache, object cache, auto-purge, and exclusions.

Optionally define these constants in `wp-config.php` (set automatically on Zinn-hosted sites):

* `ZINN_CACHE_PANEL_URL` and `ZINN_CACHE_PANEL_SECRET` — enable signed purge mirroring to the control plane.
* `ZINN_SSO_KEY` and `ZINN_SITE_ID` — enable one-click admin login from the Zinn® dashboard. Both are required; with either missing the login route is not registered.
* `WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_REDIS_DATABASE`, `WP_REDIS_PASSWORD`, `WP_REDIS_PREFIX` — override the object-cache connection.

== Frequently Asked Questions ==

= Does this require LiteSpeed? =

Full-page caching requires a LiteSpeed web server (or the third-party LiteSpeed Cache plugin). On other servers the plugin simply does not emit cache headers — everything else (object cache, exclusions API, remote purge) still works.

= Does it conflict with the LiteSpeed Cache plugin? =

No. If the LiteSpeed Cache plugin is active, Zinn® Cache defers page caching to it and routes purges through its public actions.

= What happens if Redis is unavailable? =

The object cache is optional. If the phpredis extension is missing the toggle is disabled with a notice; if Redis becomes unreachable at runtime, the drop-in serves from a per-request in-memory cache so the site never breaks.

== Changelog ==

= 1.0.0 =
* Initial release: LSCache control, smart tag-based auto-purge, Redis object-cache toggle, safe cache exclusions, and a signed remote-purge REST endpoint.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
