<?php
/**
 * Tests for running checks, snapshots, regressions and alerts.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Checks\Check;
use AryanX\Axle\RuntimeDoctor\Checks\Context;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\PhpReleases;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Sampler;
use AryanX\Axle\RuntimeDoctor\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RunnerHistoryTest extends DoctorTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ) {
				if ( is_string( $args[0] ) ) {
					return $args[2] . '?' . $args[0] . '=' . $args[1];
				}
				return $args[1] . '?' . http_build_query( $args[0] );
			}
		);
		$this->options['admin_email'] = 'admin@example.com';
	}

	/**
	 * A check that returns a fixed status.
	 */
	private function stub( string $id, string $status, bool $needs_web = false, ?\Throwable $error = null ): Check {
		return new class( $id, $status, $needs_web, $error ) implements Check {
			private $id;
			private $status;
			private $web;
			private $error;

			public function __construct( $id, $status, $web, $error ) {
				$this->id     = $id;
				$this->status = $status;
				$this->web    = $web;
				$this->error  = $error;
			}

			public function id(): string {
				return $this->id;
			}

			public function group(): string {
				return 'php';
			}

			public function label(): string {
				return 'Stub ' . $this->id;
			}

			public function needs_web(): bool {
				return $this->web;
			}

			public function per_site(): bool {
				return false;
			}

			public function run( Context $context ): ?Result {
				if ( $this->error ) {
					throw $this->error;
				}
				if ( 'none' === $this->status ) {
					return null;
				}
				return Result::from_array(
					array(
						'id'      => $this->id,
						'group'   => 'php',
						'label'   => $this->label(),
						'status'  => $this->status,
						'summary' => 'Stub says ' . $this->status,
						'value'   => '42',
					)
				);
			}
		};
	}

	private function runner( array $checks ): Runner {
		Filters\expectApplied( 'axle_runtime_doctor_checks' )->andReturn( $checks );
		$settings = new Settings();

		return new Runner( $settings, new Registry(), new Hosts( $settings, array( 'software' => 'nginx' ) ), new Sampler( $settings ), new Autoload(), new PhpReleases(), new History( new Alerts( $settings ) ) );
	}

	public function test_a_full_run_fires_its_actions_and_stores_a_snapshot(): void {
		Actions\expectDone( 'axle_runtime_doctor_checks_started' )->once()->with( array( 'a', 'b', 'c' ), 'screen' );
		Actions\expectDone( 'axle_runtime_doctor_check_finished' )->twice();
		Actions\expectDone( 'axle_runtime_doctor_checks_finished' )->once();

		$report = $this->runner( array( $this->stub( 'a', 'good' ), $this->stub( 'b', 'critical' ), $this->stub( 'c', 'none' ) ) )->run_local( array( 'sapi' => 'web' ) );

		$this->assertSame( array( 'a', 'b' ), array_keys( $report['results'] ) );
		$this->assertSame( 1, $report['counts']['critical'] );
		$this->assertSame( 'nginx', $report['host']['id'] );
		$this->assertCount( 1, $this->options[ History::SNAPSHOTS ] );
		$this->assertSame( 'critical', $this->options[ History::SNAPSHOTS ][0]['results']['b']['status'] );
		$this->assertSame( $report['results'], $this->options[ History::REPORT ]['results'] );
	}

	public function test_a_partial_run_is_not_stored(): void {
		$this->runner( array( $this->stub( 'a', 'good' ), $this->stub( 'b', 'good' ) ) )->run_local(
			array(
				'sapi'   => 'web',
				'checks' => array( 'a' ),
			)
		);

		$this->assertArrayNotHasKey( History::SNAPSHOTS, $this->options );
	}

	public function test_check_result_filter_changes_a_result_but_not_its_id(): void {
		Filters\expectApplied( 'axle_runtime_doctor_check_result' )->once()->andReturnUsing(
			static function ( $result, $id, $context ) {
				$result['status'] = 'recommended';
				$result['id']     = 'renamed';
				return $result;
			}
		);

		$report = $this->runner( array( $this->stub( 'a', 'good' ) ) )->run_local( array( 'sapi' => 'web' ) );

		$this->assertSame( 'recommended', $report['results']['a']['status'] );
		$this->assertSame( 'a', $report['results']['a']['id'] );
	}

	public function test_from_wp_cli_without_the_probe_web_only_checks_are_marked(): void {
		$report = $this->runner( array( $this->stub( 'web', 'good', true ), $this->stub( 'db', 'good' ) ) )->run_local( array( 'sapi' => 'cli' ) );

		$this->assertSame( 'info', $report['results']['web']['status'] );
		$this->assertStringContainsString( 'Only the web server can check this', $report['results']['web']['summary'] );
		$this->assertSame( 'good', $report['results']['db']['status'] );
		$this->assertSame( 'cli', $report['sapi'] );
	}

	public function test_a_failing_check_does_not_stop_the_run(): void {
		$report = $this->runner( array( $this->stub( 'bad', 'good', false, new \RuntimeException( 'boom' ) ), $this->stub( 'ok', 'good' ) ) )->run_local( array( 'sapi' => 'web' ) );

		$this->assertSame( 'The check failed: boom', $report['results']['bad']['summary'] );
		$this->assertSame( 'good', $report['results']['ok']['status'] );
	}

	public function test_run_from_wp_cli_falls_back_when_the_probe_fails(): void {
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'token' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http', 'Connection refused' ) );
		Actions\expectDone( 'axle_runtime_doctor_probe_failed' )->once();

		$report = $this->runner( array( $this->stub( 'web', 'good', true ) ) )->run();

		$this->assertSame( 'Connection refused', $report['probe_error'] );
		$this->assertSame( 'cli', $report['sapi'] );
	}

	public function test_run_from_wp_cli_uses_the_probe_report(): void {
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'token' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"sapi":"web","results":{"x":{"status":"good"}}}' );

		$report = $this->runner( array() )->run();

		$this->assertSame( 'probe', $report['via'] );
		$this->assertSame( 'web', $report['sapi'] );
	}

	private function previous( string $status ): void {
		$this->options[ History::SNAPSHOTS ] = array(
			array(
				'time'    => 1,
				'host'    => 'docker',
				'sapi'    => 'web',
				'results' => array( 'b' => array( 'status' => $status ) ),
			),
		);
	}

	public function test_a_check_that_got_worse_is_a_regression_that_is_emailed(): void {
		$this->previous( 'good' );
		Functions\expect( 'wp_mail' )->once()->with( array( 'admin@example.com' ), \Mockery::pattern( '/Runtime check got worse: Stub says critical/' ), \Mockery::type( 'string' ) )->andReturn( true );
		Actions\expectDone( 'axle_runtime_doctor_regression_detected' )->once()->with( 'b', 'good', 'critical', \Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_runtime_doctor_alert_sent' )->once()->with( array( 'admin@example.com' ), \Mockery::type( 'array' ), true );

		$report = $this->runner( array( $this->stub( 'b', 'critical' ) ) )->run_local( array( 'sapi' => 'web' ) );

		$this->assertSame( 'b', $report['regressions'][0]['id'] );
		$this->assertSame( array( 'b' ), $this->options[ History::SNAPSHOTS ][1]['regressions'] );
		$this->assertSame( 'critical', $this->options[ Alerts::NOTICE ]['regressions'][0]['after'] );
	}

	public function test_alert_message_filter_and_no_recipients(): void {
		$this->previous( 'recommended' );
		Filters\expectApplied( 'axle_runtime_doctor_alert_message' )->once()->andReturn(
			array(
				'subject' => 'Custom subject',
				'message' => 'Custom body',
			)
		);
		Functions\expect( 'wp_mail' )->once()->with( array( 'admin@example.com' ), 'Custom subject', 'Custom body' )->andReturn( false );

		$this->runner( array( $this->stub( 'b', 'critical' ) ) )->run_local( array( 'sapi' => 'web' ) );

		$this->previous( 'good' );
		Filters\expectApplied( 'axle_runtime_doctor_alert_recipients' )->once()->andReturn( array() );
		Actions\expectDone( 'axle_runtime_doctor_alert_sent' )->never();
		$this->runner( array( $this->stub( 'b', 'critical' ) ) )->run_local( array( 'sapi' => 'web' ) );
		$this->assertNotEmpty( $this->options[ Alerts::NOTICE ] );
	}

	public function test_getting_better_is_not_a_regression(): void {
		$this->previous( 'critical' );
		Actions\expectDone( 'axle_runtime_doctor_regression_detected' )->never();
		Functions\expect( 'wp_mail' )->never();

		$report = $this->runner( array( $this->stub( 'b', 'info' ) ) )->run_local( array( 'sapi' => 'web' ) );
		$this->assertSame( array(), $report['regressions'] );
	}

	public function test_snapshots_are_trimmed_to_the_filtered_count(): void {
		Filters\expectApplied( 'axle_runtime_doctor_snapshots_to_keep' )->andReturn( 2 );
		$runner = $this->runner( array( $this->stub( 'a', 'good' ) ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$runner->run_local( array( 'sapi' => 'web' ) );
		}

		$this->assertCount( 2, $this->options[ History::SNAPSHOTS ] );
	}

	public function test_compact_keeps_status_and_a_short_value(): void {
		$snapshot = History::compact(
			array(
				'time'    => 5,
				'host'    => array( 'id' => 'kinsta' ),
				'results' => array(
					'a' => array(
						'status' => 'good',
						'value'  => str_repeat( 'x', 500 ),
						'why'    => 'long text',
					),
				),
			)
		);

		$this->assertSame( 'kinsta', $snapshot['host'] );
		$this->assertSame( 200, strlen( $snapshot['results']['a']['value'] ) );
		$this->assertArrayNotHasKey( 'why', $snapshot['results']['a'] );
	}

	public function test_notice_dismiss(): void {
		$alerts                         = new Alerts( new Settings() );
		$this->options[ Alerts::NOTICE ] = array( 'regressions' => array( array( 'id' => 'a' ) ) );
		$this->assertNotNull( $alerts->notice() );
		$alerts->dismiss();
		$this->assertNull( $alerts->notice() );
	}

	public function test_registry_filter_can_remove_and_replace_checks(): void {
		$replacement = $this->stub( 'opcache_coverage', 'good' );
		Filters\expectApplied( 'axle_runtime_doctor_checks' )->once()->andReturnUsing(
			static function ( $checks ) use ( $replacement ) {
				unset( $checks['opcache_jit'] );
				$checks['opcache_coverage'] = $replacement;
				$checks['junk']             = 'not a check';
				return $checks;
			}
		);
		$registry = new Registry();

		$this->assertNull( $registry->get( 'opcache_jit' ) );
		$this->assertSame( $replacement, $registry->get( 'opcache_coverage' ) );
		$this->assertArrayNotHasKey( 'junk', $registry->all() );
		$this->assertSame( array( 'db_autoload', 'cron_mode', 'cron_events', 'fs_uploads_php' ), array_keys( $registry->pick( array(), '', true ) ) );
	}
}
