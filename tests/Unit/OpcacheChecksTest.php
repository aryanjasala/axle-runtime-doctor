<?php
/**
 * Tests for the OPcache checks.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Checks\OpcacheCoverage;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheEnabled;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheHitRate;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheJit;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheKeys;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheMemory;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheRestarts;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheRevalidate;
use AryanX\Axle\RuntimeDoctor\Checks\OpcacheStrings;

final class OpcacheChecksTest extends DoctorTestCase {

	private function opcache( array $status = array() ): array {
		return array(
			'loaded'     => true,
			'restricted' => false,
			'status'     => $status + array( 'opcache_enabled' => true ),
		);
	}

	private function observation( bool $core, ?bool $content ): array {
		return array(
			'core'    => $core,
			'content' => $content,
			'files'   => array(
				'wp-includes/plugin.php'                   => $core,
				'wp-content/plugins/x/x.php'               => (bool) $content,
			),
		);
	}

	public function test_coverage_is_critical_when_two_requests_skip_wp_content(): void {
		$result = ( new OpcacheCoverage() )->run(
			$this->context(
				array(
					'opcache'  => $this->opcache(),
					'coverage' => array(
						'available'    => true,
						'observations' => array( $this->observation( true, false ), $this->observation( true, false ) ),
						'reasons'      => array( 'opcache.blacklist_filename: "/var/www/html/wp-content/*" matches wp-content' ),
					),
				)
			)
		);

		$this->assertSame( 'critical', $result->status );
		$this->assertSame( 'OPcache is skipping wp-content', $result->summary );
		$this->assertContains( 'opcache.blacklist_filename: "/var/www/html/wp-content/*" matches wp-content', $result->details );
		$this->assertContains( 'wp-content/plugins/x/x.php: not cached', $result->details );
	}

	public function test_coverage_is_only_recommended_when_the_second_request_could_not_confirm(): void {
		$result = ( new OpcacheCoverage() )->run(
			$this->context(
				array(
					'opcache'  => $this->opcache(),
					'coverage' => array(
						'available'    => true,
						'observations' => array( $this->observation( true, false ) ),
						'reasons'      => array(),
					),
				)
			)
		);

		$this->assertSame( 'recommended', $result->status );
		$this->assertStringContainsString( 'Run the checks again', implode( ' ', $result->details ) );
	}

	public function test_coverage_is_good_when_everything_is_cached(): void {
		$result = ( new OpcacheCoverage() )->run(
			$this->context(
				array(
					'opcache'  => $this->opcache(),
					'coverage' => array(
						'available'    => true,
						'observations' => array( $this->observation( true, true ) ),
					),
				)
			)
		);

		$this->assertSame( 'good', $result->status );
	}

	public function test_coverage_flags_files_not_cached_at_all(): void {
		$result = ( new OpcacheCoverage() )->run(
			$this->context(
				array(
					'opcache'  => $this->opcache(),
					'coverage' => array(
						'available'    => true,
						'observations' => array( $this->observation( false, false ) ),
					),
				)
			)
		);

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( "OPcache did not cache this request's files", $result->summary );
	}

	public function test_coverage_is_inferred_from_timing_when_restrict_api_hides_opcache(): void {
		$facts = array(
			'opcache'  => array(
				'loaded'     => true,
				'restricted' => true,
				'status'     => null,
			),
			'coverage' => array(
				'available' => false,
				'reasons'   => array( 'opcache.restrict_api is /srv' ),
				'timing'    => array(
					'first_ms'  => 900,
					'second_ms' => 800,
				),
			),
		);

		$slow = ( new OpcacheCoverage() )->run( $this->context( $facts ) );
		$this->assertSame( 'recommended', $slow->status );
		$this->assertTrue( $slow->inferred );

		$facts['coverage']['timing']['second_ms'] = 40;
		$fast                                     = ( new OpcacheCoverage() )->run( $this->context( $facts ) );
		$this->assertSame( 'info', $fast->status );
		$this->assertTrue( $fast->inferred );
	}

	public function test_coverage_does_not_apply_when_opcache_is_off(): void {
		$this->assertNull( ( new OpcacheCoverage() )->run( $this->context( array( 'opcache' => array( 'loaded' => false ) ) ) ) );
		$this->assertNull( ( new OpcacheCoverage() )->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_enabled' => false ) ) ) ) ) );
	}

	public function test_enabled_only_runs_before_wordpress_7(): void {
		$check = new OpcacheEnabled();
		$this->assertNull( $check->run( $this->context( array( 'wp_version' => '7.0' ) ) ) );
		$this->assertNull( $check->run( $this->context( array( 'wp_version' => '7.1-beta1' ) ) ) );

		$on = $check->run(
			$this->context(
				array(
					'wp_version' => '6.6.2',
					'opcache'    => $this->opcache(),
				)
			)
		);
		$this->assertSame( 'good', $on->status );

		$off = $check->run(
			$this->context(
				array(
					'wp_version' => '6.9.1',
					'opcache'    => array( 'loaded' => false ),
				)
			)
		);
		$this->assertSame( 'recommended', $off->status );
		$this->assertSame( 'OPcache is not installed', $off->summary );
	}

	public function test_memory_over_the_threshold_is_recommended(): void {
		$status = array(
			'memory_usage' => array(
				'used_memory'   => 115 * 1048576,
				'free_memory'   => 3 * 1048576,
				'wasted_memory' => 10 * 1048576,
			),
		);
		$result = ( new OpcacheMemory() )->run( $this->context( array( 'opcache' => $this->opcache( $status ) ) ) );

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( 97.7, $result->data['used_pct'] );
	}

	public function test_memory_threshold_comes_from_the_filtered_thresholds(): void {
		$status  = array(
			'memory_usage' => array(
				'used_memory' => 60,
				'free_memory' => 40,
			),
		);
		$context = new \AryanX\Axle\RuntimeDoctor\Checks\Context( array( 'opcache' => $this->opcache( $status ) ), null, array( 'opcache_memory_pct' => 50.0 ) );

		$this->assertSame( 'recommended', ( new OpcacheMemory() )->run( $context )->status );
	}

	public function test_full_interned_strings_buffer_is_recommended(): void {
		$status = array(
			'interned_strings_usage' => array(
				'buffer_size' => 8388608,
				'used_memory' => 8388000,
			),
		);

		$this->assertSame( 'recommended', ( new OpcacheStrings() )->run( $this->context( array( 'opcache' => $this->opcache( $status ) ) ) )->status );
	}

	public function test_fewer_slots_than_php_files_is_recommended_with_a_suggestion(): void {
		$status = array(
			'opcache_statistics' => array(
				'num_cached_keys' => 1500,
				'max_cached_keys' => 7963,
			),
		);
		$result = ( new OpcacheKeys() )->run(
			$this->context(
				array(
					'opcache'   => $this->opcache( $status ),
					'php_files' => 12000,
					'ini'       => array( 'opcache.max_accelerated_files' => '4000' ),
				)
			)
		);

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( 18000, $result->data['suggested'] );
	}

	public function test_restarts_and_a_full_cache(): void {
		$check = new OpcacheRestarts();
		$full  = $check->run(
			$this->context(
				array(
					'opcache' => $this->opcache(
						array(
							'cache_full'         => true,
							'opcache_statistics' => array(),
						)
					),
				)
			)
		);
		$this->assertSame( 'critical', $full->status );

		$oom = $check->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_statistics' => array( 'oom_restarts' => 3 ) ) ) ) ) );
		$this->assertSame( 'recommended', $oom->status );

		$fine = $check->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_statistics' => array() ) ) ) ) );
		$this->assertSame( 'good', $fine->status );
	}

	public function test_hit_rate_needs_enough_requests(): void {
		$check = new OpcacheHitRate();
		$few   = $check->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_statistics' => array( 'hits' => 10, 'misses' => 500 ) ) ) ) ) );
		$this->assertSame( 'info', $few->status );

		$low = $check->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_statistics' => array( 'hits' => 8000, 'misses' => 2000 ) ) ) ) ) );
		$this->assertSame( 'recommended', $low->status );

		$high = $check->run( $this->context( array( 'opcache' => $this->opcache( array( 'opcache_statistics' => array( 'hits' => 99000, 'misses' => 100 ) ) ) ) ) );
		$this->assertSame( 'good', $high->status );
	}

	public function test_validate_timestamps_off_warns_about_deploys(): void {
		$check = new OpcacheRevalidate();
		$off   = $check->run(
			$this->context(
				array(
					'opcache' => $this->opcache(),
					'ini'     => array(
						'opcache.validate_timestamps' => '0',
						'opcache.revalidate_freq'     => '2',
					),
				)
			)
		);
		$this->assertSame( 'recommended', $off->status );
		$this->assertStringContainsString( 'Axle Smoke Test', implode( ' ', $off->details ) );

		$every = $check->run(
			$this->context(
				array(
					'opcache' => $this->opcache(),
					'ini'     => array(
						'opcache.validate_timestamps' => '1',
						'opcache.revalidate_freq'     => '0',
					),
				)
			)
		);
		$this->assertSame( 'info', $every->status );
	}

	public function test_jit_is_info_and_skipped_before_php_8(): void {
		$check = new OpcacheJit();
		$this->assertNull( $check->run( $this->context( array( 'php_version' => '7.4.33', 'opcache' => $this->opcache() ) ) ) );

		$on = $check->run(
			$this->context(
				array(
					'php_version' => '8.3.1',
					'opcache'     => $this->opcache( array( 'jit' => array( 'on' => true ) ) ),
					'ini'         => array(
						'opcache.jit'             => 'tracing',
						'opcache.jit_buffer_size' => '64M',
					),
				)
			)
		);
		$this->assertSame( 'info', $on->status );
		$this->assertSame( 'The JIT is on', $on->summary );
	}
}
