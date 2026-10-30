<?php
/**
 * Tests for Site Health, abilities, privacy, the schedule and results.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Abilities;
use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\Health\SiteHealth;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\PhpReleases;
use AryanX\Axle\RuntimeDoctor\Privacy;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Sampler;
use AryanX\Axle\RuntimeDoctor\Scheduler;
use AryanX\Axle\RuntimeDoctor\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class IntegrationsTest extends DoctorTestCase {

	private Settings $settings;
	private History $history;
	private Registry $registry;
	private Runner $runner;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
		$this->history  = new History( new Alerts( $this->settings ) );
		$this->registry = new Registry();
		$this->runner   = new Runner( $this->settings, $this->registry, new Hosts( $this->settings, array( 'software' => 'nginx' ) ), new Sampler( $this->settings ), new Autoload(), new PhpReleases(), $this->history );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
	}

	private function report(): array {
		return array(
			'time'    => 100,
			'host'    => array( 'id' => 'docker', 'label' => 'Docker' ),
			'sapi'    => 'web',
			'counts'  => array( 'critical' => 1 ),
			'results' => array(
				'opcache_coverage' => array(
					'id'       => 'opcache_coverage',
					'group'    => 'opcache',
					'label'    => 'OPcache coverage',
					'status'   => 'critical',
					'summary'  => 'OPcache is skipping wp-content',
					'value'    => 'Core cached, wp-content not cached',
					'expected' => 'Core and wp-content cached',
					'why'      => 'Because.',
					'details'  => array( 'line' ),
					'data'     => array(),
					'inferred' => false,
				),
			),
			'slow_queries' => array(
				'threshold_ms' => 50,
				'probed'       => array( 'https://example.com/' ),
				'digests'      => array(
					array( 'sql' => 'SELECT ?', 'component' => 'core', 'calls' => 3, 'total_ms' => 1.5, 'max_ms' => 0.9 ),
					array( 'sql' => 'SELECT * FROM t', 'component' => 'plugin:x', 'calls' => 1, 'total_ms' => 0.5, 'max_ms' => 0.5 ),
				),
			),
		);
	}

	public function test_site_health_tests_are_filtered_and_prefixed(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.6.2' );
		$this->assertContains( 'opcache_enabled', SiteHealth::tests() );

		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		$this->assertNotContains( 'opcache_enabled', SiteHealth::tests() );

		Filters\expectApplied( 'axle_runtime_doctor_site_health_tests' )->once()->andReturn( array( 'php_branch', 'no_such_check' ) );
		$tests = ( new SiteHealth( $this->runner, $this->history, $this->registry ) )->add_tests( array( 'direct' => array( 'core' => array() ) ) );

		$this->assertSame( array( 'core', 'axle_runtime_doctor_php_branch' ), array_keys( $tests['direct'] ) );
		$this->assertSame( 'PHP support', $tests['direct']['axle_runtime_doctor_php_branch']['label'] );
	}

	public function test_site_health_maps_a_result(): void {
		Filters\expectApplied( 'axle_runtime_doctor_checks' )->andReturnUsing(
			static function ( $checks ) {
				return array( 'fs_uploads_php' => $checks['fs_uploads_php'] );
			}
		);
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/nowhere' ) );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'wp_is_writable' )->justReturn( true );
		$health = new SiteHealth( new Runner( $this->settings, new Registry(), new Hosts( $this->settings, array() ), new Sampler( $this->settings ), new Autoload(), new PhpReleases(), $this->history ), $this->history, new Registry() );

		$result = $health->run( 'fs_uploads_php' );

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'axle_runtime_doctor_fs_uploads_php', $result['test'] );
		$this->assertSame( 'Security', $result['badge']['label'] );
		$this->assertStringContainsString( '#ard-check-fs_uploads_php', $result['actions'] );
	}

	public function test_debug_information_lists_the_latest_report(): void {
		$health = new SiteHealth( $this->runner, $this->history, $this->registry );
		$empty  = $health->debug_information( array() );
		$this->assertArrayHasKey( 'none', $empty['axle-runtime-doctor']['fields'] );

		$this->options[ History::REPORT ] = $this->report();
		$info                             = $health->debug_information( array() );
		$this->assertSame( 'Docker', $info['axle-runtime-doctor']['fields']['host']['value'] );
		$this->assertSame( 'Critical: Core cached, wp-content not cached', $info['axle-runtime-doctor']['fields']['opcache_coverage']['value'] );
	}

	private function abilities(): Abilities {
		return new Abilities( $this->runner, $this->history, new Fixes(), new Hosts( $this->settings, array( 'files' => array( '/.dockerenv' ), 'software' => 'Apache' ) ), new Autoload(), $this->registry );
	}

	public function test_abilities_register_only_with_the_api(): void {
		$abilities = $this->abilities();
		Actions\expectAdded( 'wp_abilities_api_init' )->never();
		$abilities->register();

		Functions\when( 'wp_register_ability' )->justReturn( true );
		Actions\expectAdded( 'wp_abilities_api_init' )->once();
		Actions\expectAdded( 'wp_abilities_api_categories_init' )->once();
		$abilities->register();
	}

	public function test_abilities_have_the_right_annotations(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);
		$this->abilities()->register_abilities();

		$this->assertSame( array( 'axle-runtime-doctor/run-checks', 'axle-runtime-doctor/get-report', 'axle-runtime-doctor/list-slow-queries', 'axle-runtime-doctor/get-fix', 'axle-runtime-doctor/disable-autoload' ), array_keys( $registered ) );
		$this->assertTrue( $registered['axle-runtime-doctor/run-checks']['meta']['annotations']['readonly'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$registered['axle-runtime-doctor/disable-autoload']['meta']['annotations']
		);
		$this->assertTrue( $registered['axle-runtime-doctor/get-fix']['meta']['mcp']['public'] );
	}

	public function test_ability_permission_uses_the_filtered_capability(): void {
		Filters\expectApplied( 'axle_runtime_doctor_capability' )->once()->andReturn( 'manage_network' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network' )->andReturn( false );

		$this->assertFalse( Abilities::can() );
	}

	public function test_get_report_list_slow_queries_and_get_fix(): void {
		$abilities = $this->abilities();
		$empty     = $abilities->get_report();
		$this->assertSame( 0, $empty['time'] );

		$this->options[ History::REPORT ] = $this->report();
		$report                           = $abilities->get_report();
		$this->assertSame( 'Docker', $report['host'] );
		$this->assertSame( 'opcache_coverage', $report['results'][0]['id'] );

		$slow = $abilities->list_slow_queries( array( 'limit' => 1 ) );
		$this->assertCount( 1, $slow['queries'] );
		$this->assertSame( array( 'https://example.com/' ), $slow['pages'] );

		$fix = $abilities->get_fix( array( 'check' => 'opcache_coverage', 'host' => 'pressable' ) );
		$this->assertSame( 'Pressable', $fix['host'] );
		$this->assertStringContainsString( 'OPcache is skipping wp-content', $fix['ticket'] );

		$apache = $abilities->get_fix( array( 'check' => 'opcache_coverage' ) );
		$this->assertSame( 'Docker', $apache['host'] );
		$this->assertStringContainsString( 'apachectl', implode( ' ', $apache['steps'] ) );

		$this->assertInstanceOf( \WP_Error::class, $abilities->get_fix( array( 'check' => 'nope' ) ) );
	}

	public function test_privacy_exports_and_erases_a_recipient(): void {
		Functions\when( 'wp_add_privacy_policy_content' )->justReturn( true );
		$this->options[ Settings::OPTION ] = array( 'recipients' => 'ops@example.com, sam@example.com' );
		$privacy                           = new Privacy( $this->settings );

		$export = $privacy->export( 'Sam@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertCount( 0, $privacy->export( 'nobody@example.com' )['data'] );

		$erase = $privacy->erase( 'sam@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( 'ops@example.com', $this->options[ Settings::OPTION ]['recipients'] );
		$this->assertFalse( $privacy->erase( 'sam@example.com' )['items_removed'] );
	}

	public function test_scheduler_schedules_on_the_main_site_only(): void {
		Functions\when( 'is_main_site' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->never();
		( new Scheduler( $this->runner ) )->schedule();
	}

	public function test_scheduler_adds_the_daily_event(): void {
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Scheduler::HOOK );
		( new Scheduler( $this->runner ) )->schedule();
	}

	public function test_result_from_array_is_strict(): void {
		$result = Result::from_array(
			array(
				'id'      => 'x',
				'status'  => 'broken',
				'details' => array( 'a', array( 'b' ), 3 ),
				'value'   => array( 'not a string' ),
			)
		);

		$this->assertSame( 'info', $result->status );
		$this->assertSame( array( 'a', '3' ), $result->details );
		$this->assertSame( '', $result->value );
		$this->assertSame( 2, Result::rank( 'critical' ) );
		$this->assertSame( 0, Result::rank( 'info' ) );
		$this->assertSame( 'Recommended', Result::status_label( 'recommended' ) );
	}
}
