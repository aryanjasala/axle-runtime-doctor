# Reference

## How the checks run

Every check runs inside a web request, because WP-CLI runs a different PHP from the one visitors get (OPcache is usually off on the command line, and the ini values differ).

- **The screen** calls `POST /axle-runtime-doctor/v1/run`, which runs the checks in that request.
- **WP-CLI, the daily run from a system cron, and abilities called from WP-CLI** issue a single-use token (stored hashed, valid 60 seconds) and call `POST /axle-runtime-doctor/v1/probe` on the site itself. The probe runs the checks and returns the report. When the site cannot reach itself, WP-CLI says so, runs what it can in its own PHP, and marks the checks that need the web server.
- **The object cache roundtrip** writes a random key in one request and reads it in a second probe request.
- **OPcache coverage** asks `opcache_is_script_cached()` about files every request includes: `wp-includes/plugin.php`, this plugin's main file, the theme's `functions.php` and the first must-use plugin. Core cached and wp-content not cached in two requests in a row is critical. Files changed in the last few seconds are left out, as OPcache does not cache those yet. When `opcache.restrict_api` hides OPcache from the site, the result is inferred from timing and labelled so.
- **Slow queries** load the key pages (home, latest post, latest page, biggest category, a search) with `SAVEQUERIES` on for that request only, and group the queries by shape with every literal taken out.

Loopback requests go to the home URL. Servers that cannot reach their own public address can point them elsewhere with `axle_runtime_doctor_loopback_url`.

## Comparing two sites

The Compare tab puts two runtime reports side by side. Use it when staging is fast, production is slow and the host says both are the same plan.

1. Run the checks on both sites.
2. On the other site, open Compare and choose **Download the report** (or run `wp axle-runtime-doctor export`).
3. Here, under **Add another site's report**, choose the file or paste its text, name it and import it. Or, if the other site runs this plugin too, make a **one-time code** there and paste it under **Fetch a report with a code**.
4. Pick two reports and choose **Compare reports**.

The comparison lists differences first: a check with another verdict on each site, or a setting that differs, with a sentence on what it means. Example: "OPcache holds 2,000 files on Staging and 10,000 on Production: Staging runs out of slots first and recompiles PHP it could have kept." Checks with the same verdict but other numbers, and everything identical, are folded away below.

What an export holds: each check's id, label, status, summary, value and expected value, and these settings read in the web request: PHP version and how PHP runs, `memory_limit`, `max_execution_time`, `max_input_vars`, upload and post size, `display_errors`, the realpath cache, the OPcache settings (`enable`, `memory_consumption`, `interned_strings_buffer`, `max_accelerated_files`, `validate_timestamps`, `revalidate_freq`, `save_comments`, `jit`, `jit_buffer_size`, `file_cache_only`), the web server name, the WordPress version, multisite, whether a persistent object cache is in use, `WP_DEBUG`, `DISABLE_WP_CRON`, the memory constants, the number of active plugins and the database server version. Check details and raw data are left out. Paths become `{abspath}`, `{content}`, `{plugins}` or `{path}/<file name>`, the site's hostnames become `{site}`, and email addresses and IP addresses become `{email}` and `{ip}`.

An import is read as data only. The text is cut off above 256 KB before it is decoded, decoded with a depth limit, never unserialized or run, and rebuilt from the fields this plugin knows: ids, statuses and groups are checked against lists, every string is stripped of markup and cut to length, and unknown fields are dropped. Up to 10 reports are kept (filterable), named and dated, and each can be removed. A removed report can be put back for 15 minutes.

**One-time codes.** Site A makes a code, site B pastes it. The code holds A's address and a random token, plus a checksum that catches typos and cut-off pastes. A keeps only a hash of the token, accepts it once, and stops accepting it after 10 minutes (filterable). B calls `GET /wp-json/axle-runtime-doctor/v1/share` over https with the token in the `X-Axle-Runtime-Doctor-Probe` header, through the safe HTTP functions, so private and local addresses are refused. Turn it off with the `axle_runtime_doctor_direct_enabled` filter.

## Checks

| ID | Group | Critical when | Recommended when |
| --- | --- | --- | --- |
| `opcache_enabled` | OPcache | | OPcache is off (WordPress before 7.0 only; 7.0 has its own test) |
| `opcache_coverage` | OPcache | core cached, wp-content not, twice | once, or nothing cached; inferred slow plugin load |
| `opcache_memory` | OPcache | | used memory over 90% |
| `opcache_strings` | OPcache | | interned strings buffer over 95% |
| `opcache_keys` | OPcache | | fewer slots than PHP files, or slots over 90% |
| `opcache_restarts` | OPcache | cache full | out-of-memory or hash restarts |
| `opcache_hit_rate` | OPcache | | under 95% after 1,000 requests |
| `opcache_revalidate` | OPcache | | `validate_timestamps` off |
| `opcache_jit` | OPcache | | (info) |
| `object_cache_dropin` | Object cache | | drop-in present but unused, or Redis `noeviction` |
| `object_cache_roundtrip` | Object cache | a value written in one request is gone in the next | |
| `object_cache_hit_rate` | Object cache | | under 80% over 10+ samples |
| `php_branch` | PHP | no security support | security support ends within 6 months |
| `php_patch` | PHP | a newer security release exists | a newer patch exists (only when the php.net check is on) |
| `php_memory` | PHP | | peak over 80% of `memory_limit`, limit under 128M, or `WP_MAX_MEMORY_LIMIT` under `WP_MEMORY_LIMIT` |
| `php_execution_time` | PHP | | under 30 seconds |
| `php_input_vars` | PHP | | the biggest menu (12 fields per item) or settings form does not fit |
| `php_uploads` | PHP | | `post_max_size` under `upload_max_filesize` |
| `php_display_errors` | PHP | | on in production |
| `php_realpath` | PHP | | over 90% full |
| `db_autoload` | Database | | a single option over 100 KB, autoloaded transients, options of inactive plugins |
| `db_slow_queries` | Database | | a query over 50 ms on a key page |
| `db_myisam` | Database | | MyISAM tables |
| `db_tables` | Database | | (info: the 10 largest tables) |
| `db_primary_keys` | Database | | tables without a primary key |
| `db_version` | Database | end of life passed | end of life within 6 months |
| `cron_mode` | Cron and loopback | `DISABLE_WP_CRON` and the oldest due event over 15 minutes late | events late with WP-Cron |
| `cron_events` | Cron and loopback | | duplicate events (info: events nothing handles) |
| `loopback` | Cron and loopback | a firewall challenge | through a CDN or firewall, or over 1,000 ms |
| `fs_config` | Filesystem | | `wp-config.php` readable by everyone |
| `fs_uploads_php` | Filesystem | PHP files in uploads | |
| `fs_ownership` | Filesystem | | PHP is not the file owner, or `FS_METHOD` is not `direct` |
| `fs_content_writable` | Filesystem | | wp-content not writable |
| `fs_disk` | Filesystem | under 10% of the threshold | under 1 GB free |

Every threshold can be changed on the Settings tab or with `axle_runtime_doctor_thresholds`. The PHP and database support dates ship in `data/php-versions.json` and `data/db-versions.json`, refreshed each release. The fixes per host live in `data/fixes.json`.

## Hooks

### Filters

| Hook | Use |
| --- | --- |
| `axle_runtime_doctor_capability` | `( string $capability )`. Who may open the screen, call the REST routes and run the abilities. Default `manage_options` |
| `axle_runtime_doctor_checks` | `( array $checks )`. Check ID => object implementing `AryanX\Axle\RuntimeDoctor\Checks\Check`. Add, remove or replace checks |
| `axle_runtime_doctor_check_result` | `( array $result, string $id, Context $context )`. One result before it is stored and shown |
| `axle_runtime_doctor_host` | `( array $host )`. The host after detection and the override: `id`, `label`, `type`, `server`, `evidence`, `overridden`, `detected` |
| `axle_runtime_doctor_hosts` | `( array $detectors )`. Host detectors, tried in order: `label`, `type`, and any of `constants`, `env`, `paths`, `software`, `files` |
| `axle_runtime_doctor_fixes` | `( array $fixes )`. Check ID => `ask`, and host ID => `setting`, `where`, `steps`. `generic` is the fallback |
| `axle_runtime_doctor_support_ticket_text` | `( string $text, array $result, array $host )`. The support ticket |
| `axle_runtime_doctor_php_versions` | `( array $table )`. PHP branch support dates |
| `axle_runtime_doctor_db_versions` | `( array $table )`. MySQL and MariaDB end-of-life dates |
| `axle_runtime_doctor_php_patch_check` | `( bool $on )`. Whether to ask php.net for the latest releases. Default off |
| `axle_runtime_doctor_thresholds` | `( array $thresholds )`. `opcache_memory_pct`, `opcache_strings_pct`, `opcache_keys_pct`, `opcache_hit_rate`, `object_hit_rate`, `autoload_single_kb`, `cron_late_minutes`, `loopback_ms`, `disk_free_mb`, `eol_warning_days`, `bootstrap_ms` |
| `axle_runtime_doctor_sample_rate` | `( int $rate )`. Sample 1 in N front-end requests. 0 turns sampling off. Default 50 |
| `axle_runtime_doctor_samples_max` | `( int $max )`. Samples kept. Default 500 |
| `axle_runtime_doctor_probe_urls` | `( string[] $urls )`. Pages the slow query check loads. At most 10 |
| `axle_runtime_doctor_slow_query_threshold_ms` | `( float $ms )`. A query this slow counts as slow. Default 50 |
| `axle_runtime_doctor_snapshots_to_keep` | `( int $keep )`. Default 30 |
| `axle_runtime_doctor_alert_recipients` | `( string[] $emails )`. Default: the saved list, or the admin email. Empty for no email |
| `axle_runtime_doctor_alert_message` | `( array $email, array $regressions, array $report )`. `subject` and `message` of the regression email |
| `axle_runtime_doctor_site_health_tests` | `( string[] $ids )`. Checks added to Site Health as `axle_runtime_doctor_<id>` |
| `axle_runtime_doctor_coverage_files` | `( array $files )`. `core` and `content` files the OPcache coverage check asks about. Each must be included on every request |
| `axle_runtime_doctor_loopback_url` | `( string $url )`. Where loopback requests go. Default: the home URL |
| `axle_runtime_doctor_option_owner` | `( array $owner, string $name )`. The owner of an autoloaded option: `label`, `status` (`core`, `active`, `inactive`, `theme`, `unknown`) |
| `axle_runtime_doctor_autoload_top` | `( int $count )`. How many of the biggest autoloaded options are listed. Default 20 |
| `axle_runtime_doctor_scan_limit` | `( int $limit )`. Files the PHP-in-uploads scan looks at. Default 20,000 |
| `axle_runtime_doctor_environment` | `( array $settings )`. The settings a report carries for comparing: key => `label`, `group`, `value`. Keep them free of secrets, paths and hostnames |
| `axle_runtime_doctor_export_report` | `( array $document, array $report )`. The portable report before it is exported |
| `axle_runtime_doctor_export_replacements` | `( array $pairs )`. Text to find => what replaces it in an export, such as a private hostname |
| `axle_runtime_doctor_import_max_bytes` | `( int $bytes )`. Largest report that can be imported. Default 262,144. 10 KB to 2 MB |
| `axle_runtime_doctor_import_limit` | `( int $limit )`. Imported reports kept. Default 10. 1 to 50 |
| `axle_runtime_doctor_compare_meaning` | `( string $text, array $row )`. The sentence that says what a difference means. `$row` has `type`, `key`, `group`, `label`, `a`, `b`, `state` |
| `axle_runtime_doctor_compare_ignored_settings` | `( string[] $keys )`. Settings left out of a comparison. Default none |
| `axle_runtime_doctor_direct_enabled` | `( bool $enabled )`. Make, take and answer one-time codes. Default true |
| `axle_runtime_doctor_direct_code_ttl` | `( int $seconds )`. How long a code works. Default 600. 60 to 3,600 |
| `axle_runtime_doctor_direct_allow_http` | `( bool $allow, string $origin )`. Let a code point at plain http. Default false |
| `axle_runtime_doctor_direct_request_url` | `( string $url, string $origin )`. The address direct mode calls, for sites that reach each other another way |
| `axle_runtime_doctor_direct_timeout` | `( int $seconds )`. How long to wait for the other site. Default 30. 5 to 120 |
| `axle_runtime_doctor_autoload_protected` | `( bool $protected, string $name )`. Options Runtime Doctor will not turn autoload off for. Default true for the options WordPress creates |

### Actions

| Hook | Use |
| --- | --- |
| `axle_runtime_doctor_checks_started` | `( string[] $ids, string $source )`. Before the checks run. `$source` is `screen`, `cli`, `cron`, `ability` or `site-health` |
| `axle_runtime_doctor_check_finished` | `( array $result )`. After each check |
| `axle_runtime_doctor_checks_finished` | `( array $report )`. After the run |
| `axle_runtime_doctor_regression_detected` | `( string $id, string $before, string $after, array $result )`. A check got worse since the last snapshot |
| `axle_runtime_doctor_alert_sent` | `( string[] $recipients, array $regressions, bool $sent )`. After the regression email |
| `axle_runtime_doctor_autoload_changed` | `( string[] $options, array $before, array $after )`. Autoload was turned off; before and after are option => autoload value, for undo |
| `axle_runtime_doctor_autoload_restored` | `( string[] $options, array $before, array $after )`. Autoload was turned back on (Undo) |
| `axle_runtime_doctor_report_exported` | `( array $document, string $via )`. A report was exported. `$via` is `download`, `cli`, `ability` or `direct` |
| `axle_runtime_doctor_report_imported` | `( string $id, array $item )`. A report from another site was kept. `$item` has `id`, `name`, `added`, `source`, `report` |
| `axle_runtime_doctor_report_removed` | `( string $id, array $item )`. A kept report was removed |
| `axle_runtime_doctor_report_restored` | `( string $id, array $item )`. A removed report was put back |
| `axle_runtime_doctor_direct_code_created` | `( int $expires )`. A one-time code was made |
| `axle_runtime_doctor_direct_fetched` | `( string $origin, array $document )`. A report was fetched from another site |
| `axle_runtime_doctor_direct_fetch_failed` | `( WP_Error $error, string $origin )`. Direct mode could not fetch a report |
| `axle_runtime_doctor_probe_failed` | `( WP_Error $error, string $mode, string $url )`. A loopback probe failed |
| `axle_runtime_doctor_settings_saved` | `( array $settings )`. After the settings are saved |

### JavaScript hooks

The screen's script reads these through `wp.hooks` on each use, so scripts loaded later can add them.

| Hook | Type | Use |
| --- | --- | --- |
| `axleRuntimeDoctor.ticketText` | filter | `( text, checkId, hostId )`. The support ticket "Copy support ticket" puts on the clipboard. It does not run for the report text or code on the Compare tab |
| `axleRuntimeDoctor.checksFinished` | action | `( report )`. The checks ran from the screen, before the page reloads |

### Examples

```php
// Our servers cannot reach their public address: send loopback requests to localhost.
add_filter( 'axle_runtime_doctor_loopback_url', function () {
	return 'http://127.0.0.1';
} );

// Post regressions to a Slack channel as well as email.
add_action( 'axle_runtime_doctor_regression_detected', function ( $id, $before, $after, $result ) {
	wp_remote_post( MY_SLACK_WEBHOOK, array( 'body' => wp_json_encode( array( 'text' => sprintf( '%s: %s', home_url(), $result['summary'] ) ) ) ) );
}, 10, 4 );

// Our own host: detect it and give our own fix for OPcache coverage.
add_filter( 'axle_runtime_doctor_hosts', function ( $detectors ) {
	return array( 'acme' => array( 'label' => 'Acme Cloud', 'type' => 'managed', 'constants' => array( 'ACME_SITE_ID' ) ) ) + $detectors;
} );
add_filter( 'axle_runtime_doctor_fixes', function ( $fixes ) {
	$fixes['opcache_coverage']['acme'] = array( 'setting' => 'OPcache scope', 'where' => 'Acme panel > PHP', 'steps' => array( 'Set OPcache scope to "whole site".' ) );
	return $fixes;
} );
```

## Abilities

On WordPress 6.9 and later, Runtime Doctor registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability, and needs the plugin's capability.

| Ability | Does |
| --- | --- |
| `axle-runtime-doctor/run-checks` | Read-only. Runs the checks (`checks`, `group` to narrow them) and returns each result. A full run is stored as a snapshot |
| `axle-runtime-doctor/get-report` | Read-only. The latest stored report |
| `axle-runtime-doctor/list-slow-queries` | Read-only. Query shapes from the latest run, slowest total first. `limit` |
| `axle-runtime-doctor/get-fix` | Read-only. `check`, `host`: the setting, where, steps and the support ticket |
| `axle-runtime-doctor/export-report` | Read-only. The latest report as the portable document for comparing, with paths, hostnames and addresses taken out |
| `axle-runtime-doctor/list-imported-reports` | Read-only. The reports kept from other sites: `id`, `name`, `added`, `source`, `host` |
| `axle-runtime-doctor/compare-reports` | Read-only. `import_id` or `report` (as data), and `all`: what differs between this site and that report, worst first, with what each difference means. Nothing is stored |
| `axle-runtime-doctor/disable-autoload` | Writes, not destructive, idempotent. `options`: turns autoload off for them. Options WordPress needs are skipped. Axle Read-Only Mode refuses it during a freeze |
| `axle-runtime-doctor/enable-autoload` | Writes, not destructive, idempotent. `options`: turns autoload back on, to undo the one above. Axle Read-Only Mode refuses it during a freeze |

Run them over REST with `GET .../run-checks/run?input[group]=opcache` and `POST .../disable-autoload/run` with `{ "input": { "options": [ "my_big_option" ] } }`.

## WP-CLI

```sh
wp axle-runtime-doctor check [<check-id>...] [--group=<group>] [--status=<statuses>] [--fields=<fields>] [--format=<format>]
wp axle-runtime-doctor report [--status=<statuses>] [--format=<format>]
wp axle-runtime-doctor history [--format=<format>]
wp axle-runtime-doctor slow-queries [--limit=<n>] [--format=<format>]
wp axle-runtime-doctor fix <check-id> [--host=<host>] [--format=text|json]
wp axle-runtime-doctor autoload <off|on> <option>...
wp axle-runtime-doctor export [--file=<file>] [--fresh]
wp axle-runtime-doctor compare <file-or-id> [--all] [--fresh] [--fields=<fields>] [--format=<format>]
wp axle-runtime-doctor import <file> [--name=<name>]
wp axle-runtime-doctor imports [--format=<format>]
wp axle-runtime-doctor remove-import <id>
wp axle-runtime-doctor direct-code
wp axle-runtime-doctor fetch <code> [--name=<name>]
wp axle-runtime-doctor hosts [--all] [--format=<format>]
wp axle-runtime-doctor settings [--host=<host>] [--sampling=on|off] [--sample-rate=<n>] [--patch-check=on|off] [--recipients=<emails>]
```

`check` runs through the web server and exits with 1 when a check is critical. A full run (no IDs, no group) is stored as a snapshot.

`export` prints or writes the portable report of the latest run, and runs the checks first when there is none or with `--fresh`. `compare` takes a file from another site or the id of a kept report, lists what differs, and exits with 1 when something does, so a script can notice.

## Data

| Where | What |
| --- | --- |
| `axle_runtime_doctor_settings` | Host override, sampling, php.net check, alert recipients, thresholds |
| `axle_runtime_doctor_snapshots` | The last 30 runs: time, host, and the status and value of each check |
| `axle_runtime_doctor_report` | The latest full report, for the screen |
| `axle_runtime_doctor_samples` | Up to 500 front-end samples: hits, misses, peak memory. In the object cache when it is persistent |
| `axle_runtime_doctor_php_releases` | php.net's release list, 7 days, only when that check is on |
| `axle_runtime_doctor_notice` | The open regression notice |
| `axle_runtime_doctor_imports` | Reports kept from other sites: name, date, how they came, and the clean report. Up to 10 |
| `axle_runtime_doctor_import_trash` | The report removed last, for 15 minutes, so Undo works |
| user meta `axle_runtime_doctor_flash`, `axle_runtime_doctor_compare_state` | The notice an action leaves for the next page view, and whether the user hid or finished the Get started card |
| `axle_runtime_doctor_probe_*` | Probe tokens and one-time codes (hashed, single use, 60 seconds for probes, 10 minutes for codes) and probe output, removed after use and once a day |
| `axle_runtime_doctor_daily` | The daily cron event |

All are options with autoload off, network options on multisite. Probe tokens are options rather than transients so an object cache that forgets cannot lose them. Uninstalling removes everything.

## Privacy

Nothing about visitors is stored. Samples hold counts and memory use only, and query shapes have every value taken out. The only personal data is an alert recipient's email address; the privacy exporter lists it and the eraser removes it. Exported reports have paths, hostnames, email addresses and IP addresses replaced. Direct mode makes one request to the address in a code an administrator pasted, and nothing else leaves the site.
