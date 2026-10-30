<?php
/**
 * Tests for the object cache, PHP, database, cron and filesystem checks.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Checks;
use AryanX\Axle\RuntimeDoctor\Checks\BaseCheck;
use AryanX\Axle\RuntimeDoctor\Fixes;

final class OtherChecksTest extends DoctorTestCase {

	public function test_drop_in_present_but_unused_and_noeviction(): void {
		$check = new Checks\ObjectCacheDropin();
		$none  = $check->run( $this->context( array( 'object_cache' => array( 'dropin' => false ) ) ) );
		$this->assertSame( 'info', $none->status );

		$unused = $check->run( $this->context( array( 'object_cache' => array( 'dropin' => true, 'persistent' => false, 'name' => 'Redis Object Cache' ) ) ) );
		$this->assertSame( 'recommended', $unused->status );

		$full = $check->run(
			$this->context(
				array(
					'object_cache' => array(
						'dropin'     => true,
						'persistent' => true,
						'name'       => 'Redis Object Cache',
						'backend'    => 'Redis',
						'info'       => array( 'maxmemory_policy' => 'noeviction' ),
					),
				)
			)
		);
		$this->assertSame( 'recommended', $full->status );
		$this->assertSame( 'Redis Object Cache (Redis)', $full->value );
	}

	public function test_roundtrip_statuses(): void {
		$check = new Checks\ObjectCacheRoundtrip();
		$this->assertSame( 'info', $check->run( $this->context( array( 'object_cache' => array( 'persistent' => false ) ) ) )->status );

		$untested = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'roundtrip' => array( 'tested' => false, 'error' => 'cURL error 7' ) ) ) );
		$this->assertSame( 'info', $untested->status );
		$this->assertSame( array( 'cURL error 7' ), $untested->details );

		$lies = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'roundtrip' => array( 'tested' => true, 'found' => false ) ) ) );
		$this->assertSame( 'critical', $lies->status );

		$works = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'roundtrip' => array( 'tested' => true, 'found' => true ) ) ) );
		$this->assertSame( 'good', $works->status );
	}

	public function test_object_cache_hit_rate_from_samples(): void {
		$check   = new Checks\ObjectCacheHitRate();
		$sample  = array( 'persistent' => true, 'hits' => 50, 'misses' => 50 );
		$this->assertNull( $check->run( $this->context( array( 'object_cache' => array( 'persistent' => false ) ) ) ) );

		$few = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'samples' => array( $sample ) ) ) );
		$this->assertSame( 'info', $few->status );

		$low = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'samples' => array_fill( 0, 12, $sample ) ) ) );
		$this->assertSame( 'recommended', $low->status );
		$this->assertSame( 50.0, $low->data['rate'] );

		$high = $check->run( $this->context( array( 'object_cache' => array( 'persistent' => true ), 'samples' => array_fill( 0, 12, array( 'persistent' => true, 'hits' => 95, 'misses' => 5 ) ) ) ) );
		$this->assertSame( 'good', $high->status );
	}

	private function branches(): array {
		return array(
			'branches' => array(
				'7.4' => array( 'released' => '2019-11-28', 'active' => '2021-11-28', 'security' => '2022-11-28' ),
				'8.2' => array( 'released' => '2022-12-08', 'active' => '2024-12-31', 'security' => '2026-12-31' ),
				'8.4' => array( 'released' => '2024-11-21', 'active' => '2026-12-31', 'security' => '2028-12-31' ),
			),
		);
	}

	public function test_php_branch_support(): void {
		$check = new Checks\PhpBranch();
		$now   = strtotime( '2026-10-30 12:00:00 UTC' );

		$old = $check->run( $this->context( array( 'php_version' => '7.4.33', 'php_versions' => $this->branches(), 'now' => $now ) ) );
		$this->assertSame( 'critical', $old->status );
		$this->assertSame( 'PHP 7.4 gets no security fixes since 2022-11-28', $old->summary );
		$this->assertSame( '8.4', $old->data['newest'] );

		$ending = $check->run( $this->context( array( 'php_version' => '8.2.20', 'php_versions' => $this->branches(), 'now' => $now ) ) );
		$this->assertSame( 'recommended', $ending->status );

		$fine = $check->run( $this->context( array( 'php_version' => '8.4.5', 'php_versions' => $this->branches(), 'now' => $now ) ) );
		$this->assertSame( 'good', $fine->status );

		$unknown = $check->run( $this->context( array( 'php_version' => '9.0.0', 'php_versions' => $this->branches(), 'now' => $now ) ) );
		$this->assertSame( 'info', $unknown->status );
	}

	public function test_bundled_php_and_database_tables_are_valid(): void {
		$php = Fixes::read_json( 'php-versions.json' );
		$this->assertArrayHasKey( '8.3', $php['branches'] );
		foreach ( $php['branches'] as $dates ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $dates['security'] );
		}
		$db = Fixes::read_json( 'db-versions.json' );
		$this->assertArrayHasKey( '11.4', $db['mariadb'] );
		$this->assertArrayHasKey( '8.4', $db['mysql'] );
	}

	public function test_php_patch_level(): void {
		$check = new Checks\PhpPatch();
		$this->assertNull( $check->run( $this->context( array( 'php_releases' => null ) ) ) );

		$releases = array(
			'branches' => array(
				'8.3' => array(
					'latest'   => '8.3.14',
					'security' => array( '8.3.12', '8.3.14' ),
				),
			),
		);
		$behind   = $check->run( $this->context( array( 'php_version' => '8.3.11', 'php_releases' => $releases ) ) );
		$this->assertSame( 'critical', $behind->status );
		$this->assertSame( array( '8.3.12', '8.3.14' ), $behind->data['security'] );

		$latest = $check->run( $this->context( array( 'php_version' => '8.3.14', 'php_releases' => $releases ) ) );
		$this->assertSame( 'good', $latest->status );

		$failed = $check->run( $this->context( array( 'php_version' => '8.3.14', 'php_releases' => array( 'error' => 'timeout' ) ) ) );
		$this->assertSame( 'info', $failed->status );
	}

	public function test_php_memory(): void {
		$check = new Checks\PhpMemory();
		$close = $check->run( $this->context( array( 'ini' => array( 'memory_limit' => '128M' ), 'memory' => array( 'peak' => 120 * 1048576 ) ) ) );
		$this->assertSame( 'Requests come close to the memory limit', $close->summary );

		$sampled = $check->run( $this->context( array( 'ini' => array( 'memory_limit' => '256M' ), 'memory' => array( 'peak' => 1 ), 'samples' => array( array( 'peak' => 250 * 1048576 ) ) ) ) );
		$this->assertSame( 'recommended', $sampled->status );

		$low = $check->run( $this->context( array( 'ini' => array( 'memory_limit' => '64M' ), 'memory' => array( 'peak' => 1 ) ) ) );
		$this->assertSame( 'The memory limit is low', $low->summary );

		$admin = $check->run( $this->context( array( 'ini' => array( 'memory_limit' => '512M' ), 'memory' => array( 'peak' => 1, 'wp_memory_limit' => '256M', 'wp_max_memory_limit' => '128M' ) ) ) );
		$this->assertSame( 'wp-admin gets less memory than the front end', $admin->summary );

		$none = $check->run( $this->context( array( 'ini' => array( 'memory_limit' => '-1' ) ) ) );
		$this->assertSame( 'good', $none->status );
	}

	public function test_ini_bytes(): void {
		$this->assertSame( 268435456, BaseCheck::ini_bytes( '256M' ) );
		$this->assertSame( 2048, BaseCheck::ini_bytes( '2k' ) );
		$this->assertSame( 1073741824, BaseCheck::ini_bytes( '1G' ) );
		$this->assertSame( -1, BaseCheck::ini_bytes( '-1' ) );
		$this->assertSame( 0, BaseCheck::ini_bytes( '' ) );
	}

	public function test_execution_time_uploads_display_errors_and_realpath(): void {
		$this->assertSame( 'recommended', ( new Checks\PhpExecutionTime() )->run( $this->context( array( 'ini' => array( 'max_execution_time' => '10' ) ) ) )->status );
		$this->assertSame( 'good', ( new Checks\PhpExecutionTime() )->run( $this->context( array( 'ini' => array( 'max_execution_time' => '0' ) ) ) )->status );

		$this->assertSame( 'recommended', ( new Checks\PhpUploads() )->run( $this->context( array( 'ini' => array( 'post_max_size' => '8M', 'upload_max_filesize' => '64M' ) ) ) )->status );
		$this->assertSame( 'good', ( new Checks\PhpUploads() )->run( $this->context( array( 'ini' => array( 'post_max_size' => '0', 'upload_max_filesize' => '64M' ) ) ) )->status );

		$this->assertSame( 'recommended', ( new Checks\PhpDisplayErrors() )->run( $this->context( array( 'ini' => array( 'display_errors' => 'On' ), 'environment' => 'production' ) ) )->status );
		$this->assertSame( 'good', ( new Checks\PhpDisplayErrors() )->run( $this->context( array( 'ini' => array( 'display_errors' => 'stderr' ), 'environment' => 'development' ) ) )->status );

		$this->assertSame( 'recommended', ( new Checks\PhpRealpath() )->run( $this->context( array( 'ini' => array( 'realpath_cache_size' => '16K' ), 'realpath_used' => 16000 ) ) )->status );
		$this->assertNull( ( new Checks\PhpRealpath() )->run( $this->context( array( 'ini' => array( 'realpath_cache_size' => '16K' ), 'realpath_used' => null ) ) ) );
	}

	public function test_input_vars_names_the_menu(): void {
		$check  = new Checks\PhpInputVars();
		$result = $check->run(
			$this->context(
				array(
					'ini'           => array( 'max_input_vars' => '1000' ),
					'menus'         => array( 'name' => 'Big footer', 'count' => 1200 ),
					'settings_form' => array( 'group' => 'general', 'count' => 20 ),
				)
			)
		);

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( 'Saving the menu "Big footer" (1200 items) loses items', $result->summary );
		$this->assertSame( 17500, $result->data['suggested'] );

		$form = $check->run( $this->context( array( 'ini' => array( 'max_input_vars' => '1000' ), 'menus' => array(), 'settings_form' => array( 'group' => 'big_plugin', 'count' => 1500 ) ) ) );
		$this->assertSame( 'Saving the settings form "big_plugin" loses fields', $form->summary );

		$fine = $check->run( $this->context( array( 'ini' => array( 'max_input_vars' => '1000' ), 'menus' => array( 'name' => 'Main', 'count' => 30 ) ) ) );
		$this->assertSame( 'good', $fine->status );
	}

	public function test_autoload_flags_large_options_transients_and_inactive_plugins(): void {
		$check  = new Checks\DbAutoload();
		$result = $check->run(
			$this->context(
				array(
					'autoload' => array(
						'total'      => 600000,
						'count'      => 300,
						'top'        => array(
							array( 'name' => 'big_cache', 'size' => 300 * 1024, 'owner' => 'Big Plugin', 'owner_status' => 'active' ),
							array( 'name' => 'old_plugin_settings', 'size' => 5000, 'owner' => 'Old Plugin', 'owner_status' => 'inactive' ),
							array( 'name' => 'siteurl', 'size' => 30, 'owner' => 'WordPress', 'owner_status' => 'core' ),
						),
						'transients' => array( '_transient_feed_1' ),
						'inactive'   => array( 'old_plugin_log' ),
					),
				)
			)
		);

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( array( 'big_cache' ), $result->data['large'] );
		$this->assertSame( array( 'old_plugin_settings', 'old_plugin_log' ), $result->data['inactive'] );
		$this->assertStringContainsString( '1 transient is autoloaded', $result->summary );
		$this->assertFalse( $check->needs_web() );
		$this->assertTrue( $check->per_site() );

		$clean = $check->run( $this->context( array( 'autoload' => array( 'top' => array( array( 'name' => 'siteurl', 'size' => 30, 'owner_status' => 'core' ) ) ) ) ) );
		$this->assertSame( 'good', $clean->status );
	}

	public function test_slow_queries(): void {
		$check = new Checks\DbSlowQueries();
		$this->assertNull( $check->run( $this->context( array( 'slow_queries' => null ) ) ) );
		$this->assertSame( 'info', $check->run( $this->context( array( 'slow_queries' => array( 'probed' => array(), 'failed' => array( 'https://example.com/' ) ) ) ) )->status );

		$slow = $check->run(
			$this->context(
				array(
					'slow_queries' => array(
						'probed'       => array( 'https://example.com/' ),
						'threshold_ms' => 50,
						'digests'      => array(
							array( 'sql' => 'SELECT * FROM wp_big WHERE x = ?', 'max_ms' => 320.5, 'component' => 'plugin:big' ),
							array( 'sql' => 'SELECT ?', 'max_ms' => 0.2, 'component' => 'core' ),
						),
					),
				)
			)
		);
		$this->assertSame( 'recommended', $slow->status );
		$this->assertSame( 1, $slow->data['slow'] );
		$this->assertStringContainsString( 'plugin:big', implode( ' ', $slow->details ) );
	}

	public function test_database_engine_tables_keys_and_version(): void {
		$db = array(
			'type'    => 'mysql',
			'version' => '5.7.44',
			'myisam'  => array( 'wp_old_log' ),
			'no_pk'   => array( 'wp_old_log' ),
			'largest' => array( array( 'name' => 'wp_postmeta', 'size' => 5000000, 'rows' => 90000 ) ),
		);
		$facts = array(
			'database'    => $db,
			'db_versions' => Fixes::read_json( 'db-versions.json' ),
			'now'         => strtotime( '2026-10-30' ),
		);

		$this->assertSame( 'recommended', ( new Checks\DbMyisam() )->run( $this->context( $facts ) )->status );
		$this->assertSame( 'recommended', ( new Checks\DbPrimaryKeys() )->run( $this->context( $facts ) )->status );
		$this->assertSame( 'wp_postmeta is the largest table', ( new Checks\DbTables() )->run( $this->context( $facts ) )->summary );
		$this->assertSame( 'critical', ( new Checks\DbVersion() )->run( $this->context( $facts ) )->status );

		$facts['database']['type']    = 'mariadb';
		$facts['database']['version'] = '11.4.13';
		$this->assertSame( 'good', ( new Checks\DbVersion() )->run( $this->context( $facts ) )->status );
		$facts['database']['version'] = '10.11.9';
		$facts['now']                 = strtotime( '2027-10-01' );
		$this->assertSame( 'recommended', ( new Checks\DbVersion() )->run( $this->context( $facts ) )->status );
	}

	public function test_cron_mode_without_a_real_cron(): void {
		$check = new Checks\CronMode();
		$now   = 1000000;

		$dead = $check->run( $this->context( array( 'cron' => array( 'disabled' => true, 'oldest_due' => $now - 3600 ), 'now' => $now ) ) );
		$this->assertSame( 'critical', $dead->status );
		$this->assertSame( 'No real cron is running', $dead->summary );

		$fine = $check->run( $this->context( array( 'cron' => array( 'disabled' => true, 'oldest_due' => $now - 60 ), 'now' => $now ) ) );
		$this->assertSame( 'good', $fine->status );

		$late = $check->run( $this->context( array( 'cron' => array( 'disabled' => false, 'oldest_due' => $now - 7200, 'action_scheduler' => true ), 'now' => $now ) ) );
		$this->assertSame( 'recommended', $late->status );
		$this->assertStringContainsString( 'Action Scheduler', implode( ' ', $late->details ) );
	}

	public function test_cron_events_duplicates_and_orphans(): void {
		$check = new Checks\CronEvents();
		$this->assertSame( 'recommended', $check->run( $this->context( array( 'cron' => array( 'events' => 9, 'duplicates' => array( 'my_sync' ) ) ) ) )->status );
		$this->assertSame( 'info', $check->run( $this->context( array( 'cron' => array( 'events' => 9, 'orphans' => array( 'gone_plugin_hook' ) ) ) ) )->status );
		$this->assertSame( 'good', $check->run( $this->context( array( 'cron' => array( 'events' => 9 ) ) ) )->status );
	}

	public function test_loopback(): void {
		$check = new Checks\Loopback();
		$this->assertSame( 'good', $check->run( $this->context( array( 'loopback' => array( 'ok' => true, 'ms' => 40 ) ) ) )->status );
		$this->assertSame( 'recommended', $check->run( $this->context( array( 'loopback' => array( 'ok' => true, 'ms' => 2500 ) ) ) )->status );

		$cdn = $check->run( $this->context( array( 'loopback' => array( 'ok' => true, 'ms' => 90, 'cdn' => array( 'Cloudflare' ) ) ) ) );
		$this->assertSame( 'The site calls itself through a CDN or firewall', $cdn->summary );

		$blocked = $check->run( $this->context( array( 'loopback' => array( 'ok' => false, 'status' => 403, 'challenge' => true, 'error' => 'HTTP 403' ) ) ) );
		$this->assertSame( 'critical', $blocked->status );

		$down = $check->run( $this->context( array( 'loopback' => array( 'ok' => false, 'error' => 'timeout' ) ) ) );
		$this->assertSame( 'info', $down->status );
	}

	public function test_filesystem_checks(): void {
		$this->assertSame( 'recommended', ( new Checks\FsConfig() )->run( $this->context( array( 'filesystem' => array( 'config_mode' => 0100644 ) ) ) )->status );
		$this->assertSame( 'good', ( new Checks\FsConfig() )->run( $this->context( array( 'filesystem' => array( 'config_mode' => 0100640 ) ) ) )->status );

		$this->assertSame( 'critical', ( new Checks\FsUploadsPhp() )->run( $this->context( array( 'filesystem' => array( 'uploads_php' => array( '2026/10/shell.php' ) ) ) ) )->status );
		$this->assertSame( 'good', ( new Checks\FsUploadsPhp() )->run( $this->context( array( 'filesystem' => array( 'uploads_php' => array() ) ) ) )->status );

		$this->assertSame( 'recommended', ( new Checks\FsOwnership() )->run( $this->context( array( 'filesystem' => array( 'owner_match' => false ) ) ) )->status );
		$this->assertSame( 'WordPress writes files through ftpext', ( new Checks\FsOwnership() )->run( $this->context( array( 'filesystem' => array( 'owner_match' => true, 'fs_method' => 'ftpext' ) ) ) )->summary );
		$this->assertSame( 'info', ( new Checks\FsOwnership() )->run( $this->context( array( 'filesystem' => array( 'owner_match' => null, 'fs_method' => 'direct' ) ) ) )->status );

		$this->assertSame( 'recommended', ( new Checks\FsContentWritable() )->run( $this->context( array( 'filesystem' => array( 'content_writable' => false ) ) ) )->status );

		$this->assertSame( 'critical', ( new Checks\FsDisk() )->run( $this->context( array( 'filesystem' => array( 'disk_free' => 50 * 1048576 ) ) ) )->status );
		$this->assertSame( 'recommended', ( new Checks\FsDisk() )->run( $this->context( array( 'filesystem' => array( 'disk_free' => 500 * 1048576 ) ) ) )->status );
		$this->assertNull( ( new Checks\FsDisk() )->run( $this->context( array( 'filesystem' => array( 'disk_free' => null ) ) ) ) );
	}
}
