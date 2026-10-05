=== Zinn® Cache ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-cache
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: cache, page cache, object cache, redis, performance
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.9.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A page cache and a Redis object cache for any WordPress site — LiteSpeed-aware, with smart auto-purge and safe exclusions.

== Description ==

Zinn® Cache makes a WordPress site faster wherever it is hosted. On a LiteSpeed server it drives the server's own page cache; on any other server it stores each page as a file and serves it before WordPress loads. It also installs a Redis object cache that proves it is working, and purges only the pages a change affects.

It is installed for you on sites hosted with Zinn Digital®, and it works the same way on any other host. There is one Zinn® cache plugin; Zinn® Cache Pro is an optional add-on that installs on top of it (see Pro features below).

**What it does**

* **Page cache on any server.** On a LiteSpeed server it stamps `X-LiteSpeed-Cache-Control` and `X-LiteSpeed-Tag` headers so the web server serves full pages without hitting PHP or MySQL. On any other server it stores each complete page as a file under `wp-content/cache/zinn-cache/` and serves it from `advanced-cache.php` before WordPress loads (it adds `define( 'WP_CACHE', true );` to `wp-config.php` for that, and removes it again when the page cache is turned off). Only visitors who are not signed in are served stored pages, and only addresses with no query string (tracking parameters such as `utm_source` are ignored). If the third-party LiteSpeed Cache plugin is present, page caching is deferred to it instead.
* **Smart auto-purge.** When content changes — a post or page is saved, trashed or deleted, a comment is added or moderated, a taxonomy term is edited, the theme is switched, a plugin is (de)activated, or WordPress/plugins/themes are updated — only the affected pages (and the listings they appear on) are purged, via targeted LiteSpeed cache tags. The plugin can also mirror each purge to a control panel over a signed webhook, but only when `ZINN_CACHE_PANEL_URL` is defined — and the Zinn Digital® platform does not set it today, so on a Zinn-hosted site purges happen on the server and are not mirrored anywhere.
* **Redis object cache.** A one-click toggle installs a self-contained Redis object-cache drop-in (requires the phpredis extension), offloading repeated database reads. It signs in with a plain password or a Redis 6+ ACL username and password, honours the usual `WP_REDIS_*` constants (including `WP_REDIS_PASSWORD` given as `array( 'user', 'password' )`, `WP_REDIS_PATH` and `WP_REDIS_MAXTTL`), and proves itself with a write-and-read round trip: if Redis refuses the login or the data, the settings screen says why instead of showing a cache that stores nothing. The plugin never overwrites another caching plugin's drop-in, refreshes its own drop-in after an update, and the drop-in falls back to an in-memory cache if Redis is unreachable, so the site keeps working.
* **Host-managed object cache.** A host that installs this drop-in for its customers (as Zinn Digital® hosting does) defines `ZINN_CACHE_MANAGED_OBJECT_CACHE`; the plugin then never removes that drop-in and shows the cache as managed. A host whose Redis users may not run `SCAN` can define `ZINN_CACHE_FLUSH_SOCKET`, the path of a local helper that deletes only the calling site's keys, so "Flush cache" keeps working.
* **Safe cache exclusions.** Ships with sensible WordPress and WooCommerce/Easy Digital Downloads defaults — cart, checkout, my-account, REST/AJAX, preview, search, and any logged-in or session-cookie request are never cached. Extra path, query-key, and cookie-prefix rules can be added per site.

**Remote purging**

A REST endpoint, `POST /wp-json/zinn-cache/v1/purge`, purges everything, specific URLs, or specific tags. It accepts a logged-in administrator, or any caller holding the site's `ZINN_CACHE_PANEL_SECRET` who signs the request body with it (HMAC-SHA256) — so a deploy script or your own tooling can clear the cache. Your Zinn® dashboard does not call it today; there is no dashboard purge button for this plugin yet.

= Pro features =

Everything above is free and stays free. [Zinn® Cache Pro](https://zinndigital.com/wordpress-plugins/zinn-cache-pro) is an add-on that installs on top of this plugin and adds:

* A cache analytics screen: hit ratio, memory, keys and the slowest cache commands.
* A slow-query and uncached-call finder that names the plugin or theme responsible.
* An in-memory APCu tier in front of Redis for the hottest keys, plus prefetching, compression and the igbinary serializer.
* Tag-based purging for posts, terms and WooCommerce products, so only what changed is cleared, with the page and object caches purged together.
* Automatic cache warm-up after a purge, WebP and AVIF images, unused-CSS removal and delayed JavaScript.
* Scheduled database clean-up with a preview, and per-page cache rules.
* Cache health alerts in your Zinn Digital® dashboard when the hit ratio drops or memory fills.

Pro costs $49 a year for one site, $99 for five sites and $199 for unlimited sites, and every plan starts with a 14-day free trial, no card needed. Start it from the Pro tab of the Zinn® Cache screen.

== Installation ==

1. Upload the `zinn-cache` folder to `/wp-content/plugins/` (this is done automatically as part of the Zinn® deploy footprint).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Zinn Digital® → Cache** to configure the full-page cache, object cache, auto-purge, and exclusions.

Optionally define these constants in `wp-config.php` (set automatically on Zinn-hosted sites):

* `ZINN_CACHE_PANEL_URL` and `ZINN_CACHE_PANEL_SECRET` — enable signed purge mirroring to the control plane.
* `WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_REDIS_DATABASE`, `WP_REDIS_PASSWORD`, `WP_REDIS_PREFIX` — override the object-cache connection.

== External services ==

This plugin can connect your site to Zinn Digital® (the hosting platform it is built for) so that
cache purges can be mirrored to it and the Zinn® dashboard can show what changed on the site.

**What is sent, and when**

* **Cache purges (only if `ZINN_CACHE_PANEL_URL` and `ZINN_CACHE_PANEL_SECRET` are defined).**
  When content changes, the plugin posts the affected URLs and cache tags — no post content, no
  visitor data — to the address in `ZINN_CACHE_PANEL_URL`. The request is signed with an HMAC-SHA256
  of the body using your site's own secret. Note: the Zinn Digital® platform does not define
  `ZINN_CACHE_PANEL_URL` on the sites it hosts today, so on those sites nothing is sent.
* **Update checks (only if `ZINN_UPDATE_URL` is defined).** The plugin asks whether a newer release
  exists, sending the plugin slug and installed version. Nothing about your site or its visitors is
  included.
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

* **Hosting-customer Pro discount (only on sites Zinn Digital® hosts).** The settings screen shows
  administrators a card offering hosting customers a personal discount code for Zinn® Cache Pro.
  Nothing is sent when the page loads. Only when an administrator presses the card's button does the
  site send one request to Zinn Digital® at `https://api.zinndigital.com/v1/wp/pro-discount/<site id>`,
  containing the plugin's slug, the word `issue` and the administrator's WordPress language, signed
  with the site's own key. The site's address and key come from constants the platform
  writes into wp-config.php on the sites it hosts; the card reuses only the host and site id of the
  address, which ends in `/v1/wp/plugin-update/<site id>`, and never sends anything to that address
  itself. On any other site they do not exist and the card is never shown. The answer is the
  customer's code and a Freemius checkout link, to which the browser is then sent. The card is not
  shown once Zinn® Cache Pro is active.

* **Freemius (only if you opt in).** The plugin bundles the Freemius SDK, which handles the opt-in,
  the upgrade path to Zinn® Cache Pro and licences. Nothing is sent until you opt in on the screen
  shown after activation. When you do, the SDK sends your site's URL, WordPress, PHP and plugin
  versions, language, and the administrator's name and email address to Freemius, and checks it
  periodically. Before connecting it checks that the service is reachable by requesting
  `https://api.freemius.com/v1/ping.json`, which sends nothing about your site. You can opt out at
  any time. On a site hosted with Zinn Digital® (where the platform defines
  `ZINN_CACHE_MANAGED_OBJECT_CACHE` or `ZINN_SITE_EVENTS_URL`), the SDK runs anonymously and asks nothing. Service: https://freemius.com · Terms:
  https://freemius.com/terms/ · Privacy: https://freemius.com/privacy/

**When nothing is sent.** The constants above are set by the Zinn® platform when it provisions
a site — except `ZINN_CACHE_PANEL_URL`, which it does not set today. On a site that is not hosted with Zinn Digital® none of them exist, and the plugin makes **no
outbound requests whatsoever** unless you opt in to Freemius — caching, exclusions and the object cache all work locally.

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

Every user-visible string is complete in every one of the 53 languages WordPress can serve
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

The catalogues are bundled rather than left to translate.wordpress.org because that site can only
offer what volunteers have contributed, and a site administrator working in Amharic or Khmer would
otherwise read English indefinitely. They do not compete with community translations: where a
WordPress language pack exists for this plugin, WordPress loads it ahead of the bundled catalogue,
so a community translation always wins.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

== Screenshots ==

1. The Page cache tab: the full-page cache switch, how long a page stays cached, and whether signed-in visitors are ever served a cached page. The panel above the tabs says plainly when the server cannot cache — here, a server that is not LiteSpeed.
2. The Purging tab: clear only the affected pages when content changes, clear everything after an update, and the tools to purge by hand or export your settings.
3. The Exclusions tab: addresses, query parameters and cookies that are never cached, on top of the WordPress and WooCommerce defaults that always apply.
4. Every screen is translated — here the Page cache tab in Arabic, right to left.

== Frequently Asked Questions ==

= Does this require LiteSpeed? =

Full-page caching requires a LiteSpeed web server (or the third-party LiteSpeed Cache plugin). On any other server nothing reads the cache headers, so pages are not cached — and the settings screen says so plainly rather than looking as if it works. Everything else (object cache, exclusions, and the signed purge endpoint) still works.

= Does it conflict with the LiteSpeed Cache plugin? =

No. If the LiteSpeed Cache plugin is active, Zinn® Cache defers page caching to it and routes purges through its public actions.

= What happens if Redis is unavailable? =

The object cache is optional. If the phpredis extension is missing the toggle is disabled with a notice; if Redis becomes unreachable at runtime, the drop-in serves from a per-request in-memory cache so the site never breaks.

= How do I clear the cache from the command line? =

With WP-CLI: `wp zinn-cache purge all` empties every cached page, `wp zinn-cache purge url https://example.com/pricing/` (or just `/pricing/`) empties those pages, and `wp zinn-cache purge post 42` empties a post's page and the lists it appears on. Each prints how many stored pages it removed. On a LiteSpeed server the purge is sent to the server with the next request, which the command makes itself.

== Changelog ==

= 1.9.10 =
* New: `wp zinn-cache purge all|url|post`, so a stale page can be cleared from the command line. Fixed: "Purge everything now" and the zinn_cache_purge_all action did nothing; a purge from WP-CLI on a LiteSpeed server is now sent with the next request instead of being dropped.

= 1.9.9 =
* Saving a draft, pending or scheduled post no longer empties the cache of your home page and archives; unpublishing a post still clears its pages.

= 1.9.8 =
* AI apps (Claude, ChatGPT) can now sign in with OAuth on a site where this is the only Zinn® plugin with an AI-agent server; the sign-in did not start there.

= 1.9.7 =
* MCP tools for AI connector directories: tool descriptions state only what each tool does (no references to other tools); every tool that takes input declares it; list results reach MCP clients as objects.

= 1.9.6 =
* Serbian: quotation marks are now „…“ throughout, as the Serbian WordPress translation team writes them.

= 1.9.5 =
* Translations follow each language's WordPress.org translation team style guide: its quotation marks, spacing before punctuation, apostrophes and ellipsis, and the forms of address it uses.

= 1.9.4 =
* Ukrainian: a site's visitors are now «відвідувачі» everywhere; some screens called them «користувачі» (users).

= 1.9.3 =
* Japanese follows the WordPress.org Japanese team's style guide: a half-width space around Latin text, half-width colons and question marks.

= 1.9.2 =
* New: two wp-config.php switches for the Zinn Digital® panel. define( 'ZINN_CACHE_PROMO', false ); removes the panel (dashboard widget, settings block and footer), and define( 'ZINN_CACHE_PROMO_HOSTING_URL', 'https://…' ); points its hosting offer at another https address.

= 1.9.1 =
* Security: the AI-app sign-in (MCP OAuth) checks a client's metadata address more strictly before fetching it (a public web address only, a limit per address, and a refused address remembered).

= 1.9.0 =
* AI apps such as Claude and ChatGPT can connect by signing in (OAuth 2.1) — no application password needed; every MCP tool declares whether it is read-only or destructive.

= 1.8.4 =
* Every bundled translation is redone with the current Google model: each locale in its own script (Serbian in Cyrillic), in the register its WordPress translation team uses, with the original spacing, placeholders and entities preserved.

= 1.8.3 =
* New: Zinn® Cache has its own icon on WordPress.org, on the licensing screens and on zinndigital.com, in place of the generic Zinn® letter.

= 1.8.2 =
* On sites hosted by Zinn Digital®, this plugin is now installed from WordPress.org, so it updates from WordPress.org like any directory plugin. No change to what it does.

= 1.8.1 =
* Fix: with WooCommerce active, every WP-CLI command (for example `wp option get`) used about 6 MB more memory than before the AI agents (MCP) release, which could push a site over a 128M limit. The site's MCP server now starts only for `wp mcp-adapter` and on web requests; define `ZINN_MCP_DISABLED` in wp-config.php to turn every Zinn® MCP server off.

= 1.8.0 =
* AI agents (MCP) and REST: read the cache status, purge, change every setting and read the diagnostics from AI apps, with your WordPress permissions (administrators). On by default; switch under Cache → AI agents (MCP).

= 1.7.7 =
* Hardened: the page cache refuses a request whose Host header is only dots, so a stored page is only ever read from, or written to, that host's own folder. No stored page could be reached this way before; the request is now refused on its shape.

= 1.7.6 =
* Readme: the WordPress.org edition keeps its disclosure of the hosting-customer discount request, and says plainly that the card never contacts the platform's update address.

= 1.7.5 =
* New: on a site hosted by Zinn Digital®, the settings screen offers hosting customers a personal discount code for their first payment of Zinn® Cache Pro. It is not shown once Pro is active, or on any site Zinn Digital® does not host.

= 1.7.4 =
* Fixed: this plugin's .htaccess rules are written above WordPress's own rewrite rules, where they take effect on every page (before, a block could land below WordPress's catch-all and only apply to the home page); a block written below them earlier is moved on the next admin page load.

= 1.7.3 =
* Freemius's own packages now match ours byte for byte: the upload that failed on 2026-09-29 is fixed, and nothing else changes.

= 1.7.2 =
* Never stops the site if the bundled Freemius SDK is missing: the cache keeps working without it, and `wp zinn-cache status` reports freemius=false.

= 1.7.1 =
* The Freemius SDK the plugin loads is now part of every build; 1.7.0 / 2.0.0 were never released because a build without it had no update channel.

= 1.7.0 =
* New: a page cache for servers that are not LiteSpeed. Each page is stored as a file and served before WordPress loads; purges clear exactly the pages a change affects.
* New: Freemius opt-in and the upgrade path to Zinn® Cache Pro, an add-on with cache analytics, a slow-query finder, an APCu tier, warm-up and image optimisation. A "Pro" tab on the settings screen and a "Go Pro" link on the Plugins screen show what it adds; neither is a notice, and both disappear once Pro is installed.
* The object-cache drop-in (revision 3) lets Zinn® Cache Pro add its features without replacing it.

= 1.6.0 =
* Smaller download: the editable translation sources (.po) are no longer shipped; WordPress only ever loads the compiled .mo and .l10n.php files, which are unchanged.

= 1.5.0 =
* Updates install whenever you click Update, even months later: the download link is fetched fresh at install time instead of expiring in WordPress's saved update data.

= 1.4.0 =
* The Redis object cache signs in with a Redis 6+ ACL username and password (WP_REDIS_USERNAME, or WP_REDIS_PASSWORD as array( user, password )), honours WP_REDIS_PATH and WP_REDIS_MAXTTL, and can flush through a host helper (ZINN_CACHE_FLUSH_SOCKET) where SCAN is not allowed. It now proves itself with a write-and-read round trip and the settings screen says why when Redis refuses the login or the data, instead of a cache that silently stores nothing. Hosts can mark the object cache as managed (ZINN_CACHE_MANAGED_OBJECT_CACHE) so the plugin never removes their drop-in, and an older copy of the plugin's own drop-in is refreshed after an update. New filters for add-ons: zinn_cache_request_cacheable, zinn_cache_ttl, zinn_cache_control_header, and the zinn_cache_purged_all action.
* New `wp zinn-cache status --format=json` reports whether the object cache is really storing data (`connected`), or has silently fallen back to memory (`fallback`) and why (`last_error`), with the Redis server's hit ratio; the last result is kept in the `zinn_cache_object_cache_status` transient.
* With the page cache switched off, the plugin no longer writes an empty block into .htaccess, and removes one left by an earlier version.
* The object-cache drop-in is loaded by WordPress on whatever PHP the site runs, so it no longer uses any PHP 8-only function: it runs on PHP 7.4 and later. The plugin itself still requires PHP 8.2.

= 1.3.7 =
* Security hardening: the design-token stylesheet validates every component id and strips anything that could close the inline style.

= 1.3.6 =
* The Translations section no longer says the plugin has 56 user-visible strings. The plugin has grown to nearly three times that many, and every one of them is translated; the count was typed by hand and stopped being true as strings were added, so it is gone rather than corrected.

= 1.3.5 =
* The plugin's description in your Plugins list no longer offers remote purge from your Zinn® dashboard or one-click admin login. Neither is part of this plugin: one-click login moved out in 1.3.2, and the dashboard does not send purges to it. It now names what the plugin does — smart auto-purge, a signed purge endpoint for your own tools, a Redis object-cache toggle and safe exclusions — in every language it ships.

= 1.3.4 =
* The readme no longer describes one-click admin login, which moved out of this plugin in 1.3.2, and the settings are found under Zinn Digital® → Cache. Screenshots added for the WordPress.org listing. Tested up to: 7.1 — the major version, as WordPress.org requires.

= 1.3.3 =
* Tested up to WordPress 7.1.1.

= 1.3.1 =
* In a right-to-left admin language, the Zinn Digital® menu entry showed its trademark symbol on the wrong side of the name. The name is now isolated so it reads correctly in Arabic, Hebrew, Persian, Pashto and Urdu.
* Number fields on the settings screen are now wide enough to show the whole value. A long value — a page-cache lifetime of 604800 seconds, say — was cut off to its first few digits, so the setting looked wrong even when it was right.
* This readme no longer says cache purges are driven from your Zinn® dashboard. The purge endpoint in the plugin is real and works for an administrator or a caller holding the site's secret, but the platform does not yet send purges to it or receive purge mirrors from it, so the description said more than the product does.

= 1.3.0 =
* When somebody's access to a site is revoked in your Zinn® dashboard, the WordPress admin session they already had open now ends on their very next click. Before this release a one-click login that was already open kept working until WordPress ended it on its own — up to 48 hours — so removing a developer, an agency or a former member of staff did not take effect straight away for whoever still had a tab open.
* Only sessions that were opened from your Zinn® dashboard are ended. Your own WordPress logins, and anyone else's, are untouched.

= 1.2.3 =
* The admin screens' styles and scripts are now enqueued through WordPress rather than printed into the page, so they can be dequeued, deferred or optimised by your site like any other asset — and they still work on a site whose security policy forbids inline code.
* The page-cache decision no longer leaves an output buffer open for WordPress to unwind at the end of the request; the plugin closes its own and never touches anybody else's.
* One-click login from your Zinn® dashboard now claims its single-use token atomically, so the same token can never be accepted twice even on a site with no object cache.

= 1.2.2 =
* Translations: every string this plugin's admin shows is now translated in every bundled language. A few strings the machine translator refused were shipping in English; they are now translated by hand.

= 1.2.1 =
* Hardening: a settings rule can no longer be mistaken for a PHP function with the same name. The same shared settings code is what stopped Zinn® Translate saving its settings. Nothing about how this plugin behaves changes.

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
