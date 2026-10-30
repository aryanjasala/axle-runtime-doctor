=== Axle Runtime Doctor ===
Contributors: aryanjasala
Tags: opcache, object cache, performance, site health, server
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks the server WordPress runs on: OPcache coverage, the object cache, PHP support and limits, cron and more, with the fix for your host.

== Description ==

A site is slow and the host says everything is fine. Often the cause is runtime config nobody checks. OPcache is on for WordPress but skips wp-content. OPcache memory is full. A Redis drop-in quietly falls back to memory because Redis is down. The PHP branch gets no security fixes, max_input_vars cuts off a big menu, or WP-Cron is off with no real cron behind it.

Site Health says "Opcode cache is enabled" and stops there. Axle Runtime Doctor checks what Site Health does not, from inside a real web request, and tells you what to change on your host.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

= What it checks =

* **OPcache**: whether it caches wp-content as well as core (and why not: a blacklist file, file_cache_only, restrict_api, a .user.ini, or the host), memory, the interned strings buffer, file slots against the PHP files you have, restarts, a full cache, hit rate, validate_timestamps and the JIT. Before WordPress 7.0 also whether it is on at all.
* **Object cache**: which drop-in, which backend, whether a value written in one request is still there in the next, the hit rate from sampled front-end requests, and the Redis eviction policy.
* **PHP**: the end of security support for your branch, the patch level (optional), memory_limit against WP_MEMORY_LIMIT and real peak use, max_execution_time, max_input_vars against your biggest menu, post_max_size against upload_max_filesize, display_errors in production, and the realpath cache.
* **Database**: the 20 biggest autoloaded options with their owner, autoloaded transients, options of inactive plugins, slow queries on your key pages, MyISAM tables, the largest tables, tables without a primary key, and the end of life of your MySQL or MariaDB release.
* **Cron and loopback**: whether a real cron runs events when WP-Cron is off, duplicate events, events nothing handles, loopback speed and whether it goes out through a CDN or firewall.
* **Files**: wp-config.php readable by everyone, PHP files in uploads, PHP running as a different user from the file owner, wp-content not writable, and free disk space.

= Real values, not WP-CLI's =

WP-CLI runs a different PHP from the one your visitors get: OPcache is usually off there and the limits differ. So every check runs in a web request. The screen runs them over REST, and WP-CLI, the daily run and the abilities ask the site to run them through a loopback request with a single-use token. When the site cannot reach itself, WP-CLI says so.

= A fix for your host =

The plugin guesses your host from constants, environment variables, paths and the web server, shows the evidence, and lets you correct it. Every problem has the setting to change, where to change it on that host (WP Engine, Pressable, Kinsta, WordPress.com, WordPress VIP, Pantheon, GoDaddy, Flywheel, SiteGround, Cloudways, Hostinger, cPanel, Plesk, nginx, Apache), and a support ticket with your measured values, ready to paste.

= History and alerts =

The checks run once a day and keep the last 30 snapshots. When a check gets worse, for example OPcache stops caching wp-content after the host moved the site, you get an email and a notice in wp-admin.

= Also =

* Site Health shows the checks that matter most, and its Info tab lists every value.
* "Turn autoload off" on any listed option, from the screen, WP-CLI or an ability. An action fires with the values before and after, so an activity log can undo it.
* On multisite the full screen is in Network Admin, and each site sees the checks about itself.
* Abilities for AI agents on WordPress 6.9 and later: run-checks, get-report, list-slow-queries, get-fix and disable-autoload.

= WP-CLI =

`wp axle-runtime-doctor check [<check-id>...] [--group=<group>] [--status=critical,recommended] [--format=json]`
`wp axle-runtime-doctor fix opcache_coverage --host=pressable`
`wp axle-runtime-doctor report`
`wp axle-runtime-doctor history`
`wp axle-runtime-doctor slow-queries`
`wp axle-runtime-doctor autoload off <option>...`
`wp axle-runtime-doctor hosts`
`wp axle-runtime-doctor settings`

`check` exits with 1 when a check is critical, so it fits a deploy script.

Part of [Axle](https://axlewp.com/), a family of free plugins for running WordPress sites safely.

== External services ==

The PHP patch-level check can compare your PHP version with the release list on php.net. It is **off by default**. When you turn it on in the settings, the plugin requests https://www.php.net/releases/index.php?json&version=<major> at most once a week, from the server, while the checks run. Nothing about your site is sent: the request only names the PHP major version, such as 8. php.net's terms: https://www.php.net/license/ and privacy policy: https://www.php.net/privacy.php.

No other external requests are made. The loopback requests go to your own site.

== Frequently Asked Questions ==

= Does it change my server settings? =

No. It reads settings one by one and shows what to change and where. It never writes php.ini or .user.ini and never calls phpinfo(), whose output carries environment variables and keys.

= Does it slow down my site? =

No. Nothing runs on normal page views except a tiny sample on 1 in 50 front-end requests (object cache hits and misses, and peak memory), which you can turn off. The checks themselves run when you open the screen, once a day, or from WP-CLI.

= The checks say the site could not call itself. =

The loopback request goes to your home URL from the server. If the server cannot reach its own public address, point the probe somewhere it can with the axle_runtime_doctor_loopback_url filter, for example http://127.0.0.1.

= Can it flush or warm OPcache? =

No. Flush Opcache and OPcache Manager do that well.

= What does it store? =

Its settings, the last 30 snapshots (status and value per check), the latest full report, and up to 500 samples. Query digests have every value taken out, so no emails or other data end up in them. Uninstalling removes all of it.

== Screenshots ==

1. The checks, with OPcache skipping wp-content and the blacklist that causes it.
2. How to fix it on Pressable, with the support ticket filled in.
3. History: the day OPcache stopped caching wp-content.
4. Slow queries on the key pages, grouped by shape, with the component that sent them.

== Changelog ==

= 1.0.0 =
* First release.
