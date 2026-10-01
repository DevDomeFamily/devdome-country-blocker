=== DevDome Country Blocker: Geo Blocking, GeoIP Block ===
Contributors: devdome
Tags: country blocker, geo blocking, geo blocker, geoip block, whitelist country
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use geo blocking to block or allow countries, with Cloudflare or a free local GeoIP database and Test Mode to check your rules.

== Description ==

DevDome Country Blocker is a geo blocker for WordPress with country block and allow lists. Block visitors by country or allow only listed countries, subject to your safety exemptions. Blocked visitors get a 403 page with your text or a redirect to another website.

Geo blocking uses verified Cloudflare country headers or the free local DB-IP country database for GeoIP detection. The local database supports IPv4 and IPv6, with automatic monthly refresh. Country detection runs on your server without external lookup requests during visits.

Before you geoblock visitors, use Test Mode to let everyone through and log requests that would be blocked. You can restrict by country while keeping individual IPv4 and IPv6 addresses or CIDR ranges exempt through Always Allowed Addresses. wp-admin is never blocked, and visitors whose country is unknown are always allowed.

= How It Knows the Country =

* **Cloudflare:** reads the country header only after checking that the connecting address belongs to a Cloudflare edge range.
* **Local database:** download the free DB-IP country database with one click in Settings. It supports IPv4 and IPv6. Lookups run on your server, with automatic monthly refresh through a daily scheduled check.
* **CloudFront or Vercel:** reads their country header only after you turn on Proxy Headers. Use it only when the site really runs behind one of them.
* **Your own proxy or load balancer:** enter its addresses under Trusted Proxies. The plugin reads X-Forwarded-For only when the connection comes from a listed proxy.

= Try Before You Block =

Turn on Test Mode to let everyone through and log requests that would be blocked. Test hits are marked "Test" and do not increase blocked totals.

Your Connection on the Overview shows your country, detection source, installed database month and address.

= Keep Access to Your Site =

* wp-admin is never blocked.
* Login blocking is optional. A settings save is refused if it would block the saving visitor's detected country from the login page without an allowed address.
* Logged-in users are exempt from front-end blocking by default.
* Always Allowed Addresses accepts individual addresses and CIDR ranges, for IPv4 and IPv6.
* Unknown countries are always allowed.
* Add `define('DEVDCOUN_DISABLE', true);` to wp-config.php to switch all blocking off.

= Optional Login and XML-RPC Blocking =

Apply your country rules to wp-login.php and xmlrpc.php with separate settings. Both are off by default. Always Allowed Addresses still applies. Logged-in and crawler exemptions do not apply to these endpoints.

= Search Engines and Health Checks =

One checkbox exempts Google, Bing, DuckDuckGo and Apple crawlers, plus the DevDome health monitor, from front-end blocking. It matches their user-agent strings.

= Review Blocked Visits =

Overview tiles show blocked requests today, over 7 and 30 days, and the number of listed countries. Top Blocked Countries shows counts over 30 days.

The log keeps about the last 200 hits, including Test Mode hits. It shows time, country, result, anonymized address, path without its query string, and user agent.

Use Refresh Now to recalculate the totals. Clear Statistics removes every counter and logged hit after you type CLEAR.

= Limits =

Blocking applies only to requests that reach WordPress. A full-page cache or CDN can serve a cached page before the plugin runs.

admin-ajax.php and REST API requests are not blocked. The user-agent exemption can be faked. On multisite, each site has separate settings, country lists and statistics, so set up each site separately.

= AI Agents and MCP =

On WordPress 6.9+, the plugin registers 8 abilities: get-status, get-settings, update-settings, get-statistics, lookup-country, update-database, refresh-summary and clear-statistics. Compatible agents and MCP clients can use them through the WordPress MCP Adapter. Every ability requires the plugin capability, manage_options by default. Older WordPress versions register none.

Agents must pass `confirm: true` after the owner agrees to:

* Download or update the database, or clear statistics.
* Enable login or XML-RPC blocking.
* Change who is blocked when either the current or proposed mode is not Off and its country list is nonempty. This includes mode, active country list, Test Mode, login/XML-RPC settings, logged-in or crawler exemptions, proxy settings and allowed addresses. Changes that reduce blocking also need confirmation.

This settings rule applies even in Test Mode. Changing only the message, redirect or response action does not require confirmation. Agent output never carries full visitor addresses, email addresses or server paths.

== External services ==

**IP database download (`db-ip.com`), only when you press "Download Database" in Settings (or "Update Database Now", shown there after a failed monthly update), when an agent runs update-database, and afterwards by a daily check that downloads again only when a newer monthly dataset is due (or the last attempt failed).** The plugin downloads the free "IP to Country Lite" file from `https://download.db-ip.com/free/` (about 10 MB, server-to-server). It is a plain file download: no visitor data, site data or personal data is sent. If you never install the database (for example because your site is behind Cloudflare), nothing is ever downloaded. Service provider: DB-IP. Licence: Creative Commons Attribution 4.0. Terms: https://db-ip.com/db/lite.php Privacy policy: https://db-ip.com/privacy.php

**Error reports (`devdome.com`), only when you press "Report this error" on an error message.** The plugin sends the error text, the plugin, bundled library, WordPress and PHP versions, whether the site is a multisite, the site locale, the screen you were on, its own state (mode, counts and flags, never a visitor address), your site address, the generated site ID, your DevDome account ID if the site is connected, and your admin e-mail (so support can reply) to `https://devdome.com/api/plugin/error-report`. Nothing is sent unless you press the button. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours (one hour after a failed fetch), so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

Country Blocker's own features (country detection, blocking, statistics) run entirely on your own server and make no network requests during a visit.

The plugin bundles the shared DevDome suite library that powers the **DevDome Tools** dashboard. What that library may contact depends on the edition you are running; on the WordPress.org edition nothing is contacted before you act; the self-hosted edition also checks for updates as described below.

= WordPress.org edition =

The WordPress.org package carries a build marker that keeps the library's bot-detection feed download permanently off and contains no self-hosted updater; updates come only from WordPress.org. Besides the requests above (the database download you start, error reports you send, the plugin catalog), the only other request the shared library can make is the optional account connection below.

= Connecting a DevDome account (optional, both editions) =

The DevDome Tools dashboard offers connecting a free DevDome account (used by other DevDome plugins for optional email alerts; Country Blocker's own features stay local; the shared library performs only the checks described here). Nothing is sent until you press the Connect button. If you do connect: the shared library sends your site address, a generated site ID and a generated secret site token to `analytics.devdome.com/api/plugin/connect/start` and `/api/plugin/connect/claim` to link this site to your account; afterwards it confirms the connection with `api.devdome.com/plugin/account` (site address plus the site token in a request header) normally at most once every fifteen minutes while a DevDome screen is open (sooner right after connecting, and after ten minutes when a check failed), and tells `api.devdome.com/plugin/disconnect` when you disconnect. When you connect from the DevDome Tools dashboard, whose Connect card states this before you press the button, those account checks also carry the slug and version of each active DevDome plugin on the site plus the bundled DevDome library, WordPress and PHP versions, so your DevDome account can show your sites and their DevDome plugins for support and update notices. Nothing about other plugins, users, email addresses, content or visitors is included. Sites connected before this was introduced, and sites connected from a button that does not show that text, do not send the list. Disconnecting stops the checks and the plugin list. Terms: https://devdome.com/terms-of-service. Privacy: https://devdome.com/privacy-policy

= Self-hosted edition only =

* `https://api.devdome.com/plugin-updates/devdome-country-blocker.json`: the self-hosted update manifest, checked from wp-admin and cron (never on front-end requests), cached six hours, ten-second timeout. The request sends no site data; it is a plain download. The updater validates the manifest and, when it publishes a checksum, verifies the downloaded package before installation; on any failure the installed version keeps working.
* `https://api.devdome.com/bot-protection/*`: the shared once-daily bot-detection reference lists used by other DevDome plugins. A one-way download; no data about your site or visitors is sent.

== Privacy ==

* **Your data stays local.** Settings, counters, the hit log and the downloaded database are stored only on your own site and are removed on uninstall.
* **Addresses are anonymized before storage.** The address field of the log keeps the first three numbers of an IPv4 address and the first 48 bits of an IPv6 address, never the full address. The requested path (without its query string) and the user agent of a blocked request are stored as sent.
* **Retention is bounded.** About 200 logged hits are kept (trimmed as new hits arrive and exactly once a day); daily counters older than 13 months are removed automatically.
* **You can delete everything.** "Clear Statistics" removes every counter and every logged hit on demand.
* **No cookies** are set by this plugin and no visitor data leaves your site.

== Installation ==

1. Upload and activate the plugin.
2. Open **DevDome > Country Blocker**.
3. If using local country detection, click **Download Database** in Settings.
4. Check **Your Connection**. Add your own reverse proxy under **Trusted Proxies** if needed.
5. Choose a mode, add countries and enable **Test Mode**. Click **Save Settings**.
6. Review the test hits. Turn Test Mode off and save when ready to block.

== Frequently Asked Questions ==

= Is anything paid? =

The plugin's features and local DB-IP Lite database are free. No account or licence key is required for country blocking.

= Can I lock myself out? =

wp-admin is never blocked. The save check refuses login rules that would block your current detected country unless your address is allowed. A later change of address or country can still affect login access. Add your address to Always Allowed Addresses, or use `define('DEVDCOUN_DISABLE', true);` in wp-config.php to recover.

= Does it slow my site down? =

Country detection checks headers or searches local database files. It makes no external lookup request during a visit. Blocked hits write a counter and log entry, with occasional log cleanup. Test Mode writes log entries without blocked counters. These operations use server resources.

= Why does the Overview say blocking is inactive? =

The "No country source" warning means no usable country source is available. Install the local database or check your Cloudflare setup. Without a country, visitors are allowed. Off mode, an empty active list and Test Mode also block nobody.

= My site is behind Nginx, Varnish or a load balancer, does it work? =

Yes. List your proxy's addresses or ranges under Trusted Proxies and have it supply X-Forwarded-For. Install the local database for address lookups. Your Connection shows the address the plugin sees. Cloudflare needs no Trusted Proxies entry.

= Does it work with a caching plugin or a CDN? =

Only requests reaching WordPress are checked. Cached pages served before WordPress runs bypass the plugin. Configure country rules at the cache, CDN or firewall when those responses also need blocking.

= Does it block the login page and XML-RPC? =

Only when you enable their separate settings. Both are off by default. Allowed addresses still get through, but logged-in and crawler exemptions do not apply there.

= Does it block WooCommerce checkout or REST API calls? =

A checkout page can be blocked like other front-end pages. REST API and admin-ajax.php requests are exempt. The plugin does not provide a separate WooCommerce checkout rule.

= Does it work on multisite? =

Yes. Each site keeps its own settings, country lists, database and statistics. Configure each site separately.

= What happens to a visitor whose country is unknown? =

They are always allowed, including in allow-only mode.

= How do I test my list before it blocks anyone? =

Choose your mode and countries, enable Test Mode and save. Review Recent Blocked Hits for rows marked "Test". Exempt visitors do not produce test hits. Turn Test Mode off and save to enforce the list.

= Does it block at the CDN edge? =

No. It runs inside WordPress. Edge blocking needs a rule at your CDN or firewall.

= What is removed on uninstall? =

Deleting the plugin removes its statistics tables, settings, cached summary, database metadata, build lock, scheduled tasks and downloaded database files. Cleanup runs for every site on multisite. Unrelated files in the database folder are left alone. Shared DevDome library data is kept while another installed DevDome plugin needs it. Deactivation keeps the plugin's data and removes its schedules.

== Screenshots ==

1. Overview: blocked totals, Your Connection, top blocked countries and recent hits.
2. Settings: blocking modes, the searchable country picker and the blocked visitor response.
3. Safety settings: logged-in and crawler exemptions, proxy settings and Always Allowed Addresses.
4. The 403 page: a plain page displaying your message to blocked visitors.

== Changelog ==

= 1.0.4 =

* Listing text updated: title, short description, tags and introduction.
* The admin page is printed without an output buffer and the Report this error button goes through wp_kses.
* Shared DevDome library 1.7.10: the one-time Report a bug hint is recorded through a nonce-checked request instead of on a page view, and the DevDome dashboard prints its icons through wp_kses.

= 1.0.3 =
* Removed the shared library update helper. The plugin no longer hooks the WordPress updater; updates come only from WordPress.org.

= 1.0.2 =

Database download: the gzip trailer (checksum and length) is verified, so a download cut off mid-stream can never replace the working database. Build lock: a stale lock is taken over and released only by its owner. Abilities: get-status reports active only when visitors are actually blocked and adds test_mode and disabled_by_constant; update-settings refuses a redirect to this site instead of silently clearing the stored one; get-settings returns the allow list, trusted proxies and redirect URL exactly as stored; clear-statistics reports when the cached totals could not be recomputed. Proxy country headers: XX and T1 count as unknown, as with Cloudflare.

= 1.0.1 =

Plugin name and plugin links corrected.

= 1.0.0 =

First release. Includes country block and allow lists, verified Cloudflare headers, a local IPv4 and IPv6 database with monthly refresh, trusted proxies, a custom 403 message or redirect, Test Mode, access safeguards, optional login and XML-RPC blocking, statistics, an anonymized hit log, 8 WordPress Abilities and the shared DevDome core 1.7.6 (DevDome Tools dashboard, optional account connection, Report this error).
