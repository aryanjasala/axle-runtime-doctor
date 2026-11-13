=== Axle Runtime Doctor ===
Contributors: aryanjasala
Tags: opcache, object cache, performance, site health, server
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks the server WordPress runs on: OPcache, object cache, PHP, cron, the page cache and more, with the fix for your host.

== Description ==

A site is slow and the host says everything is fine. Often the cause is runtime config nobody checks. OPcache is on for WordPress but skips wp-content. OPcache memory is full. A Redis drop-in quietly falls back to memory because Redis is down. The PHP branch gets no security fixes, max_input_vars cuts off a big menu, or WP-Cron is off with no real cron behind it.

Site Health says "Opcode cache is enabled" and stops there. Axle Runtime Doctor checks what Site Health does not, from inside a real web request, and tells you what to change on your host.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

= What it checks =

* **OPcache**: whether it caches wp-content as well as core (and why not: a blacklist file, file_cache_only, restrict_api, a .user.ini, or the host), memory, the interned strings buffer, file slots against the PHP files you have, restarts, a full cache, hit rate, validate_timestamps and the JIT. Before WordPress 7.0 also whether it is on at all.
* **Object cache**: which drop-in, which backend, whether a value written in one request is still there in the next, the hit rate from sampled front-end requests, and the Redis eviction policy.
* **PHP**: the end of security support for your branch, the patch level (optional), memory_limit against WP_MEMORY_LIMIT and real peak use, max_execution_time, max_input_vars against your biggest menu, post_max_size against upload_max_filesize, display_errors in production, and the realpath cache.
* **Database**: the 20 biggest autoloaded options with their owner, autoloaded transients, options of inactive plugins, slow queries on your key pages, MyISAM tables, the largest tables, tables without a primary key, and the end of life of your MySQL or MariaDB release.
* **Cron and loopback**: whether a real cron runs events when WP-Cron is off, duplicate events, events nothing handles, loopback speed and whether it goes out through a CDN or firewall.
* **Page cache**: whether a cache answers a visitor, and what stops it (see below).
* **Files**: wp-config.php readable by everyone, PHP files in uploads, PHP running as a different user from the file owner, wp-content not writable, and free disk space.

= Real values, not WP-CLI's =

WP-CLI runs a different PHP from the one your visitors get: OPcache is usually off there and the limits differ. So every check runs in a web request. The screen runs them over REST, and WP-CLI, the daily run and the abilities ask the site to run them through a loopback request with a single-use token. When the site cannot reach itself, WP-CLI says so.

= A fix for your host =

The plugin guesses your host from constants, environment variables, paths and the web server, shows the evidence, and lets you correct it. Every problem has the setting to change, where to change it on that host (WP Engine, Pressable, Kinsta, WordPress.com, WordPress VIP, Pantheon, GoDaddy, Flywheel, SiteGround, Cloudways, Hostinger, cPanel, Plesk, nginx, Apache), and a support ticket with your measured values, ready to paste.

= Compare two sites =

"It is only slow on production." Put production and staging side by side and see what differs. Export a report from one site, with paths, hostnames, email addresses and IP addresses replaced, and import it on the other: paste it or upload the file. Or, if both sites run the plugin, make a one-time code on one and paste it on the other, which fetches the report over https.

The comparison covers every check and the PHP, OPcache and WordPress settings. Differences come first, each with a sentence on what it means. For example: "OPcache holds 2,000 files on Staging and 10,000 on Production: Staging runs out of slots first and recompiles PHP it could have kept." Imported reports are kept, named and dated, so "before the host migration" can be compared with "after". An import is read as data only: it is checked field by field and never run.

= What keeps the page cache from working =

The host's cache is on and almost nothing is served from it. Nobody knows why. The Page cache tab asks your home page, latest post and a page twice, as a visitor who is not logged in, and reads the headers. It tells you whether a cache answered (it knows the headers of Cloudflare, Kinsta, SiteGround, LiteSpeed, Varnish, WP Engine, Fastly, CloudFront and more) and what stops one:

* a cookie on a page for logged-out visitors (it keeps the cookie's name, never its value),
* a PHP session, from its cookie or the headers `session_start()` sends,
* `Cache-Control: no-store`, `private` or `no-cache`,
* `Vary: Cookie` or `Vary: *`,
* a call to `nocache_headers()`,
* the `DONOTCACHEPAGE` constant, which cache plugins read and which sends no header.

A page that no cache answered is loaded once more and watches itself, to find who did it. A call to `nocache_headers()` is seen directly, so the plugin or theme is named for sure. PHP has no way to see who sets a cookie, so for that the page shows which hook it happened in and which plugins have code on that hook. It says "one of these" when it cannot tell, and it says so when it cannot trace at all. It does not guess a name.

You get the fix for your host and a support ticket with what the pages send. The result is part of the daily history and Site Health.

= History and alerts =

The checks run once a day and keep the last 30 snapshots. When a check gets worse, for example OPcache stops caching wp-content after the host moved the site, you get an email and a notice in wp-admin.

= Also =

* Site Health shows the checks that matter most, and its Info tab lists every value.
* "Turn autoload off" on any listed option, from the screen, WP-CLI or an ability, with an Undo link. Options WordPress itself needs are never switched off. An action fires with the values before and after, so an activity log can undo it too.
* On multisite the full screen is in Network Admin, and each site sees the checks about itself.
* Abilities for AI agents on WordPress 6.9 and later: run-checks, get-report, list-slow-queries, get-page-cache, get-fix, export-report, list-imported-reports, compare-reports, disable-autoload and enable-autoload.

= WP-CLI =

`wp axle-runtime-doctor check [<check-id>...] [--group=<group>] [--status=critical,recommended] [--format=json]`
`wp axle-runtime-doctor fix opcache_coverage --host=pressable`
`wp axle-runtime-doctor report`
`wp axle-runtime-doctor history`
`wp axle-runtime-doctor slow-queries`
`wp axle-runtime-doctor page-cache [--stored]`
`wp axle-runtime-doctor autoload <off|on> <option>...`
`wp axle-runtime-doctor export --file=production.json`
`wp axle-runtime-doctor compare production.json`
`wp axle-runtime-doctor import <file> [--name=<name>]`
`wp axle-runtime-doctor imports`
`wp axle-runtime-doctor remove-import <id>`
`wp axle-runtime-doctor direct-code`
`wp axle-runtime-doctor fetch <code>`
`wp axle-runtime-doctor hosts`
`wp axle-runtime-doctor settings`

`check` exits with 1 when a check is critical, so it fits a deploy script.

== External services ==

The PHP patch-level check can compare your PHP version with the release list on php.net. It is **off by default**. When you turn it on in the settings, the plugin requests https://www.php.net/releases/index.php?json&version=<major> at most once a week, from the server, while the checks run. Nothing about your site is sent: the request only names the PHP major version, such as 8. php.net's terms: https://www.php.net/license/ and privacy policy: https://www.php.net/privacy.php.

Direct mode, for comparing two sites, makes one request when an administrator pastes a one-time code under Compare and chooses "Fetch the report". The request goes to the site address inside the code, over https, and carries only the code's token. The other site answers with its runtime report, which has paths, hostnames, email addresses and IP addresses replaced. Nothing is sent without that action, and you can turn direct mode off with the axle_runtime_doctor_direct_enabled filter.

No other external requests are made. The loopback requests and the page cache test go to your own site.

== Frequently Asked Questions ==

= Does it change my server settings? =

No. It reads settings one by one and shows what to change and where. It never writes php.ini or .user.ini and never calls phpinfo(), whose output carries environment variables and keys.

= Does it slow down my site? =

No. Nothing runs on normal page views except a tiny sample on 1 in 50 front-end requests (object cache hits and misses, and peak memory), which you can turn off. The checks themselves run when you open the screen, once a day, or from WP-CLI.

= The checks say the site could not call itself. =

The loopback request goes to your home URL from the server. If the server cannot reach its own public address, point the probe somewhere it can with the axle_runtime_doctor_loopback_url filter, for example http://127.0.0.1.

= Is an exported report safe to send? =

It holds each check's verdict and a list of PHP, OPcache and WordPress settings. Paths, hostnames, email addresses and IP addresses are replaced, and check details and raw data are left out. Read the file before you send it: you know your own site best. The axle_runtime_doctor_export_replacements filter replaces other text, such as a private hostname.

= How does the page cache test know which plugin sets a cookie? =

Often it does not, and it says so. A call to nocache_headers() from a plugin or theme is seen for sure. A cookie, a session or DONOTCACHEPAGE is only placed in the WordPress hook where it appeared. If one plugin has code on that hook, the result says "probably" that plugin. If several do, it lists them as "one of these". A cookie name that matches a known plugin, such as WooCommerce, adds a note that the name points at it.

= Does the page cache test change my cache? =

No. It cannot purge or change a cache. It sends ordinary requests to your own pages with no cookies, which may fill a cache the way any visitor's would, and one more request with a new query string to trace a page. Up to 14 requests per run.

= Can it flush or warm OPcache? =

No. Flush Opcache and OPcache Manager do that well.

= What does it store? =

Its settings, the last 30 snapshots (status and value per check), the latest full report, the last page cache test (cookie names, never values), up to 10 reports you import from other sites, and up to 500 samples. Query digests have every value taken out, so no emails or other data end up in them. Uninstalling removes all of it.

== Screenshots ==

1. The checks, with OPcache skipping wp-content and the blacklist that causes it.
2. How to fix it on Pressable, with the support ticket filled in.
3. History: the day OPcache stopped caching wp-content.
4. Slow queries on the key pages, grouped by shape, with the component that sent them.
5. The page cache test: the cookie that makes every page skip the cache, and the plugin behind it.

== Changelog ==

= 1.0.0 =
* First release.
