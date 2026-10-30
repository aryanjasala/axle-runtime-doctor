<?php
/**
 * Tests for settings, host detection and fixes, with their filters.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class SettingsHostsFixesTest extends DoctorTestCase {

	public function test_capability_filter_falls_back_on_empty(): void {
		$this->assertSame( 'manage_options', Settings::capability() );

		Filters\expectApplied( 'axle_runtime_doctor_capability' )->once()->with( 'manage_options' )->andReturn( '' );
		$this->assertSame( 'manage_options', Settings::capability() );
	}

	public function test_capability_filter_changes_it(): void {
		Filters\expectApplied( 'axle_runtime_doctor_capability' )->once()->andReturn( 'manage_network_options' );
		$this->assertSame( 'manage_network_options', Settings::capability() );
	}

	public function test_save_cleans_input_and_fires_an_action(): void {
		Actions\expectDone( 'axle_runtime_doctor_settings_saved' )->once();
		$settings = new Settings();
		$saved    = $settings->save(
			array(
				'host'        => 'Pressable!',
				'sampling'    => '0',
				'sample_rate' => '999999',
				'patch_check' => '1',
				'recipients'  => 'one@example.com, nope, two@example.com one@example.com',
				'thresholds'  => array(
					'loopback_ms'        => '250',
					'opcache_memory_pct' => '90',
					'disk_free_mb'       => '-5',
					'unknown'            => '1',
				),
			)
		);

		$this->assertSame( 'pressable', $saved['host'] );
		$this->assertFalse( $saved['sampling'] );
		$this->assertSame( 10000, $saved['sample_rate'] );
		$this->assertTrue( $saved['patch_check'] );
		$this->assertSame( 'one@example.com, two@example.com', $saved['recipients'] );
		$this->assertSame( array( 'loopback_ms' => 250.0 ), $saved['thresholds'] );
		$this->assertSame( 250.0, $settings->thresholds()['loopback_ms'] );
	}

	public function test_thresholds_filter_is_clamped_and_falls_back(): void {
		Filters\expectApplied( 'axle_runtime_doctor_thresholds' )->once()->andReturn(
			array(
				'opcache_memory_pct' => 150,
				'loopback_ms'        => 'fast',
				'disk_free_mb'       => 0,
			)
		);
		$thresholds = ( new Settings() )->thresholds();

		$this->assertSame( 100.0, $thresholds['opcache_memory_pct'] );
		$this->assertSame( 1000.0, $thresholds['loopback_ms'] );
		$this->assertSame( 1024.0, $thresholds['disk_free_mb'] );
	}

	public function test_sample_rate_and_patch_check_filters(): void {
		$settings = new Settings();
		$this->assertSame( 50, $settings->sample_rate() );
		$this->assertFalse( $settings->patch_check() );

		Filters\expectApplied( 'axle_runtime_doctor_sample_rate' )->once()->with( 50 )->andReturn( -3 );
		$this->assertSame( 0, $settings->sample_rate() );

		Filters\expectApplied( 'axle_runtime_doctor_php_patch_check' )->once()->with( false )->andReturn( true );
		$this->assertTrue( $settings->patch_check() );
	}

	public function test_sampling_off_means_rate_zero(): void {
		$this->options[ Settings::OPTION ] = array( 'sampling' => false );
		$this->assertSame( 0, ( new Settings() )->sample_rate() );
	}

	public function test_recipients_default_to_the_admin_and_are_filtered(): void {
		$this->options['admin_email'] = 'admin@example.com';
		$this->assertSame( array( 'admin@example.com' ), ( new Settings() )->recipients() );

		Filters\expectApplied( 'axle_runtime_doctor_alert_recipients' )->once()->andReturn( array( 'ops@example.com', 'bad', array() ) );
		$this->assertSame( array( 'ops@example.com' ), ( new Settings() )->recipients() );
	}

	public function test_snapshot_and_slow_query_filters(): void {
		$this->assertSame( 30, Settings::snapshots_to_keep() );
		Filters\expectApplied( 'axle_runtime_doctor_snapshots_to_keep' )->once()->andReturn( 9999 );
		$this->assertSame( 365, Settings::snapshots_to_keep() );

		$this->assertSame( 50.0, Settings::slow_query_ms() );
		Filters\expectApplied( 'axle_runtime_doctor_slow_query_threshold_ms' )->once()->andReturn( -1 );
		$this->assertSame( 50.0, Settings::slow_query_ms() );
	}

	private function hosts( array $env, array $settings = array() ): Hosts {
		$this->options[ Settings::OPTION ] = $settings;

		return new Hosts( new Settings(), $env );
	}

	public function test_detects_managed_hosts_with_evidence(): void {
		$host = $this->hosts(
			array(
				'constants' => array( 'IS_PRESSABLE' ),
				'software'  => 'nginx',
				'sapi'      => 'fpm-fcgi',
			)
		)->current();

		$this->assertSame( 'pressable', $host['id'] );
		$this->assertSame( 'nginx', $host['server'] );
		$this->assertSame( array( 'The constant IS_PRESSABLE is defined' ), $host['evidence'] );
		$this->assertFalse( $host['overridden'] );
	}

	public function test_detects_hosts_by_path_env_and_server(): void {
		$this->assertSame( 'siteground', $this->hosts( array( 'abspath' => '/home/customer/www/example.com/public_html/' ) )->current()['id'] );
		$this->assertSame( 'pantheon', $this->hosts( array( 'env' => array( 'PANTHEON_ENVIRONMENT' ) ) )->current()['id'] );
		$this->assertSame( 'cpanel', $this->hosts( array( 'abspath' => '/home/jane/public_html/' ) )->current()['id'] );
		$this->assertSame( 'apache', $this->hosts( array( 'software' => 'Apache/2.4' ) )->current()['id'] );
		$this->assertSame( 'generic', $this->hosts( array() )->current()['id'] );
	}

	public function test_override_keeps_the_detected_evidence(): void {
		$host = $this->hosts( array( 'files' => array( '/.dockerenv' ), 'software' => 'Apache' ), array( 'host' => 'kinsta' ) )->current();

		$this->assertSame( 'kinsta', $host['id'] );
		$this->assertTrue( $host['overridden'] );
		$this->assertSame( 'docker', $host['detected'] );
		$this->assertStringContainsString( 'Detected: Docker', $host['evidence'][0] );
		$this->assertSame( 'apache', $host['server'] );
	}

	public function test_hosts_filter_adds_a_detector(): void {
		Filters\expectApplied( 'axle_runtime_doctor_hosts' )->atLeast()->once()->andReturnUsing(
			static function ( $detectors ) {
				return array(
					'acme' => array(
						'label'     => 'Acme Hosting',
						'type'      => 'managed',
						'constants' => array( 'ACME_SITE' ),
					),
				) + $detectors;
			}
		);

		$this->assertSame( 'acme', $this->hosts( array( 'constants' => array( 'ACME_SITE' ) ) )->current()['id'] );
	}

	public function test_host_filter_changes_the_result(): void {
		Filters\expectApplied( 'axle_runtime_doctor_host' )->once()->andReturnUsing(
			static function ( $host ) {
				$host['label'] = 'Our own cluster';
				return $host;
			}
		);

		$this->assertSame( 'Our own cluster', $this->hosts( array( 'software' => 'nginx' ) )->current()['label'] );
	}

	public function test_fix_falls_back_from_host_to_server_to_generic(): void {
		$fixes = new Fixes();

		$this->assertSame( 'pressable', $fixes->get( 'opcache_coverage', array( 'id' => 'pressable', 'server' => 'nginx' ) )['host'] );
		$this->assertSame( 'nginx', $fixes->get( 'opcache_coverage', array( 'id' => 'docker', 'server' => 'nginx' ) )['host'] );
		$this->assertSame( 'generic', $fixes->get( 'opcache_coverage', array( 'id' => 'docker', 'server' => '' ) )['host'] );
		$this->assertNull( $fixes->get( 'no_such_check', array( 'id' => 'docker' ) ) );
	}

	public function test_every_fix_has_a_generic_entry_and_an_ask(): void {
		foreach ( ( new Fixes() )->data() as $check => $fix ) {
			if ( '_ticket' === $check ) {
				continue;
			}
			$this->assertArrayHasKey( 'generic', $fix, $check );
			$this->assertNotEmpty( $fix['ask'], $check );
		}
	}

	public function test_ticket_fills_in_the_measured_values(): void {
		$ticket = ( new Fixes() )->ticket(
			array(
				'id'       => 'php_input_vars',
				'label'    => 'max_input_vars',
				'summary'  => 'Saving the menu "Big footer" (1200 items) loses items',
				'value'    => '1000',
				'expected' => 'At least 14420',
				'details'  => array( 'Largest menu: Big footer' ),
				'data'     => array(
					'suggested' => 17500,
					'menu'      => 'Big footer',
					'items'     => 1200,
				),
			),
			array( 'label' => 'SiteGround' )
		);

		$this->assertStringContainsString( 'On https://example.com/ the runtime check says: Saving the menu', $ticket );
		$this->assertStringContainsString( 'raise max_input_vars for this site to 17500, as the menu "Big footer" has 1200 items', $ticket );
		$this->assertStringContainsString( 'Measured: 1000', $ticket );
	}

	public function test_fix_and_ticket_filters(): void {
		Filters\expectApplied( 'axle_runtime_doctor_fixes' )->once()->andReturn(
			array(
				'opcache_memory' => array(
					'ask'     => 'add memory',
					'generic' => array(
						'setting' => 'ours',
						'where'   => 'our panel',
						'steps'   => array( 'Ask us.' ),
					),
				),
			)
		);
		Filters\expectApplied( 'axle_runtime_doctor_support_ticket_text' )->once()->andReturn( 'Custom ticket' );

		$fixes = new Fixes();
		$this->assertSame( 'our panel', $fixes->get( 'opcache_memory', array( 'id' => 'x' ) )['where'] );
		$this->assertSame( 'Custom ticket', $fixes->ticket( array( 'id' => 'opcache_memory' ), array() ) );
	}
}
