=== Zinn® Cache ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-cache
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: cache, page cache, object cache, redis, performance
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cache control for sites hosted with Zinn Digital® — auto-purge, remote purge from your dashboard, a Redis object cache and one-click admin login.

== Description ==

Zinn® Cache connects a WordPress site to the server-side cache layer of the Zinn Digital® hosting platform. It is installed for you when a Zinn® site is provisioned. It runs on LiteSpeed Enterprise / OpenLiteSpeed hosts with the LSCache module, and degrades gracefully wherever a cache layer is absent — caching is a per-blueprint capability.

**Which Zinn® cache plugin do I need?** Exactly one of them:

* **Hosted with Zinn Digital®** — this plugin. Your Zinn® server already runs the page cache; Zinn® Cache controls it and connects it to your dashboard.
* **Hosted anywhere else** — install **Zinn® Cache Engine**, which brings its own caching engine.

They are different plugins doing different jobs, not a free and a paid tier of one plugin. You do not need both.

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

== External services ==

This plugin connects your site to Zinn Digital® (the hosting platform it is built for) so that
cache purges can be driven from your Zinn® dashboard and so an administrator can open wp-admin
from it without a second password.

**What is sent, and when**

* **Cache purges (only if `ZINN_CACHE_PANEL_URL` and `ZINN_CACHE_PANEL_SECRET` are defined).**
  When content changes, the plugin posts the affected URLs and cache tags — no post content, no
  visitor data — to your Zinn® control plane so the CDN purges in step. The request is signed with
  an HMAC-SHA256 of the body using your site's own secret.
* **Update checks (only if `ZINN_UPDATE_URL` is defined).** The plugin asks whether a newer release
  exists, sending the plugin slug and installed version. Nothing about your site or its visitors is
  included.
* **One-click admin login (only if `ZINN_SSO_KEY` and `ZINN_SITE_ID` are defined).** This is
  inbound only — Zinn Digital® presents a short-lived signed token and the plugin verifies it. No
  request leaves your site, and the route is not registered at all unless both constants are set.

* **Site events (only if `ZINN_SITE_EVENTS_URL` and `ZINN_CACHE_PANEL_SECRET` are defined).** When
  a plugin or theme is activated, deactivated, switched or upgraded, the plugin reports that fact so
  your Zinn® dashboard can show what changed on the site. It sends the site's own address, its PHP
  version, and the name and version of the plugin or theme involved. No post content and no visitor
  data are included.
* **Outbound link index (only if `ZINN_SITE_LINKS_URL` and `ZINN_CACHE_PANEL_SECRET` are defined).**
  When a post is saved or removed, and on a daily pass, the plugin sends that post's **title**, its
  **permalink**, and the **links found in its content** so link placements can be tracked from your
  Zinn® dashboard. This is post-derived data: titles and the URLs a post links to. The post body
  itself is never sent, and no visitor data is included.

**When nothing is sent.** All of the constants above are set by the Zinn® platform when it provisions
a site. On a site that is not hosted with Zinn Digital® none of them exist, and the plugin makes **no
outbound requests whatsoever** — caching, exclusions and the object cache all work locally.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

* **Support diagnostics (only when you press send).** If you ask us for help, the plugin can send
  a support report to `https://api.zinndigital.com/v1/connector/diagnostics`. **You are shown the
  exact payload first, already redacted, and nothing leaves your site until you press send.**
  Credentials are excluded by declaration rather than by matching key names, and render as
  `[not sent — credential]`. The plugin never sends this on its own initiative.

== Translations ==

**Every string this plugin adds to your admin is translated into 57 languages** — labels, notices,
errors and settings, not a subset. The catalogues are bundled in the plugin, so they work as soon
as you set your site language; there is no separate language pack to install.

All 56 user-visible strings are complete in every one of the 53 languages WordPress can serve
today:

Amharic (am), Arabic (ar), Azerbaijani (az), Bulgarian (bg_BG), Bengali (Bangladesh)
(bn_BD), Czech (cs_CZ), German (de_DE), Greek (el), Spanish (Spain) (es_ES), Persian
(fa_IR), French (France) (fr_FR), Gujarati (gu), Hebrew (he_IL), Hindi (hi_IN), Croatian
(hr), Hungarian (hu_HU), Armenian (hy), Indonesian (id_ID), Italian (it_IT), Japanese
(ja), Georgian (ka_GE), Kazakh (kk), Khmer (km), Kannada (kn), Korean (ko_KR), Lao (lo),
Malayalam (ml_IN), Mongolian (mn), Marathi (mr), Malay (ms_MY), Myanmar (Burmese)
(my_MM), Nepali (ne_NP), Dutch (nl_NL), Panjabi (India) (pa_IN), Polish (pl_PL), Pashto
(ps), Portuguese (Brazil) (pt_BR), Romanian (ro_RO), Russian (ru_RU), Sinhala (si_LK),
Albanian (sq), Serbian (sr_RS), Swahili (sw), Tamil (ta_IN), Telugu (te), Thai (th),
Tagalog (tl), Turkish (tr_TR), Ukrainian (uk), Urdu (ur), Uzbek (uz_UZ), Vietnamese
(vi), Chinese (China) (zh_CN)

A further 4 ship complete in the plugin — Hausa (ha), Somali (so_SO), Tajik (tg), Yoruba (yo) — but
WordPress core does not currently provide a locale for them, so WordPress cannot load them.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

== Frequently Asked Questions ==

= Does this require LiteSpeed? =

Full-page caching requires a LiteSpeed web server (or the third-party LiteSpeed Cache plugin). On other servers the plugin simply does not emit cache headers — everything else (object cache, exclusions API, remote purge) still works.

= Does it conflict with the LiteSpeed Cache plugin? =

No. If the LiteSpeed Cache plugin is active, Zinn® Cache defers page caching to it and routes purges through its public actions.

= What happens if Redis is unavailable? =

The object cache is optional. If the phpredis extension is missing the toggle is disabled with a notice; if Redis becomes unreachable at runtime, the drop-in serves from a per-request in-memory cache so the site never breaks.

== Changelog ==

= 1.2.0 =
Settings moved under the one Zinn Digital® menu, with per-post-type cache lifetimes, a browser-cache lifetime, a signed-in-visitor rule, a purge-on-comment switch, a Redis password field, and a status panel that tells you when the server is not LiteSpeed and the cache is therefore doing nothing.

= 1.1.2 =
* Fixed: the plugin told the update service it was version 1.0.0, so a site already on the latest version was offered the same version again on every check and re-installed it.

= 1.1.1 =
* Fixed: changing the Site Title, tagline, front-page, permalink, posts-per-page, widget or theme settings, or saving a menu, now purges the whole page cache. Previously every cached page kept the old value until it expired.

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.

= 1.0.0 =
* Initial release: LSCache control, smart tag-based auto-purge, Redis object-cache toggle, safe cache exclusions, and a signed remote-purge REST endpoint.

== Upgrade Notice ==

= 1.1.2 =
Stops the plugin re-downloading and re-installing itself on every update check. No settings change.

= 1.1.1 =
Site-wide settings changes now purge the page cache. No settings change.

= 1.1.0 =
Adds the Zinn® panel to the WordPress admin. No settings change.

= 1.0.0 =
Initial release.
