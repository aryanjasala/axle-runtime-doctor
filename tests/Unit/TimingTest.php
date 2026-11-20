<?php
/**
 * Tests for page timing, the update log, Confirm it and the slowdown email.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Admin\AxleUi;
use AryanX\Axle\RuntimeDoctor\Admin\PageCachePanel;
use AryanX\Axle\RuntimeDoctor\PageCache\Scanner;
use AryanX\Axle\RuntimeDoctor\Probe;
use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Health\SiteHealth;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\PhpReleases;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Sampler;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Timing\Confirm;
use AryanX\Axle\RuntimeDoctor\Timing\Loader;
use AryanX\Axle\RuntimeDoctor\Timing\Timings;
use AryanX\Axle\RuntimeDoctor\Timing\Updates;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TimingTest extends DoctorTestCase {

	private Settings $settings;
	private Timings $timings;
	private Updates $updates;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
		$this->timings  = new Timings( $this->settings, new Alerts( $this->settings ) );
		$this->updates  = new Updates( $this->timings );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $time ) {
				return gmdate( $format, $time );
			}
		);
	}

	public function test_median_of_five_ignores_the_outlier(): void {
		$this->assertSame( 200.0, Timings::median( array( 190, 2000, 200, 210, 195 ) ) );
		$this->assertSame( 150.0, Timings::median( array( 100, 200 ) ) );
		$this->assertSame( 0.0, Timings::median( array() ) );
	}

	public function test_a_slowdown_is_matched_to_the_updates_since_the_last_run(): void {
		$before = array( '/' => array( 'label' => 'Home page', 'median' => 180.0, 'cached' => false, 'error' => '' ) );
		$after  = array( '/' => array( 'label' => 'Home page', 'median' => 520.0, 'cached' => false, 'error' => '' ) );
		$window = array( array( 'id' => 'abc', 'time' => 1700000000, 'type' => 'plugin', 'name' => 'Elementor', 'from' => '3.25', 'to' => '3.26' ) );

		$found = Timings::regressions( $before, $after, $window, array( 'timing_ms' => 100, 'timing_pct' => 30 ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 340.0, $found[0]['delta'] );
		$this->assertSame( array( 'abc' ), $found[0]['updates'] );
		$this->assertStringContainsString( 'Home page server time went from 180 ms to 520 ms after Elementor 3.25 to 3.26 on Tuesday.', $found[0]['summary'] );
	}

	public function test_small_cached_and_failed_readings_are_not_slowdowns(): void {
		$page   = static function ( $ms, $extra = array() ) {
			return array( '/' => $extra + array( 'label' => 'Home page', 'median' => $ms, 'cached' => false, 'error' => '' ) );
		};
		$limits = array( 'timing_ms' => 100, 'timing_pct' => 30 );

		$this->assertSame( array(), Timings::regressions( $page( 180.0 ), $page( 250.0 ), array(), $limits ), 'under 100 ms' );
		$this->assertSame( array(), Timings::regressions( $page( 2000.0 ), $page( 2200.0 ), array(), $limits ), 'under 30 percent' );
		$this->assertSame( array(), Timings::regressions( $page( 180.0 ), $page( 900.0, array( 'cached' => true ) ), array(), $limits ), 'a cache answered' );
		$this->assertSame( array(), Timings::regressions( $page( 180.0 ), $page( 0.0, array( 'error' => 'HTTP 500' ) ), array(), $limits ), 'not timed' );
		$this->assertSame( array(), Timings::regressions( array(), $page( 900.0 ), array(), $limits ), 'no earlier reading' );
	}

	public function test_summary_without_and_with_many_updates(): void {
		$this->assertStringContainsString( 'No update was logged', Timings::summary( 'Home page', 180, 520, array() ) );

		$many = array_fill( 0, 4, array( 'time' => 1700000000, 'type' => 'plugin', 'name' => 'X', 'from' => '1', 'to' => '2' ) );
		$this->assertStringContainsString( 'after 4 updates', Timings::summary( 'Home page', 180, 520, $many ) );
	}

	public function test_updates_are_logged_filtered_and_trimmed(): void {
		Actions\expectDone( 'axle_runtime_doctor_update_recorded' )->once();
		$this->assertTrue( $this->updates->record( array( 'type' => 'plugin', 'slug' => 'elementor', 'name' => 'Elementor', 'from' => '3.25', 'to' => '3.26' ) ) );

		$all = Updates::all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Elementor 3.25 to 3.26', Updates::describe( $all[0] ) );
		$this->assertSame( $all[0], Updates::find( $all[0]['id'] ) );
		$this->assertNull( Updates::find( 'nope' ) );
		$this->assertCount( 1, Updates::between( 0, time() + 5 ) );
		$this->assertCount( 0, Updates::between( time() + 5, time() + 10 ) );
	}

	public function test_an_update_can_be_left_out_of_the_log_by_a_filter(): void {
		Filters\expectApplied( 'axle_runtime_doctor_update_event' )->once()->andReturn( array() );
		$this->assertFalse( $this->updates->record( array( 'type' => 'plugin', 'name' => 'X' ) ) );
		$this->assertSame( array(), Updates::all() );
	}

	public function test_log_keeps_only_the_newest_updates(): void {
		Filters\expectApplied( 'axle_runtime_doctor_updates_to_keep' )->andReturn( 10 );
		for ( $i = 0; $i < 13; $i++ ) {
			$this->updates->record( array( 'type' => 'plugin', 'slug' => 'p' . $i, 'name' => 'P' . $i, 'to' => (string) $i ) );
		}
		$all = Updates::all();
		$this->assertCount( 10, $all );
		$this->assertSame( 'p12', end( $all )['slug'] );
	}

	public function test_a_non_final_smoke_test_run_does_not_start_a_timing(): void {
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->updates->on_smoke_test( array( 'id' => 'r1', 'verdict' => 'green', 'final' => false ), array() );
		$this->assertTrue( true );
	}

	public function test_the_final_smoke_test_run_starts_a_timing_and_a_missing_flag_counts_as_final(): void {
		Functions\expect( 'wp_schedule_single_event' )->twice()->andReturn( true );
		Actions\expectDone( 'axle_runtime_doctor_timing_queued' )->twice();
		$this->updates->on_smoke_test( array( 'id' => 'r1', 'final' => true ), array() );
		$this->updates->on_smoke_test( array( 'id' => 'r2' ), array() );
		$this->assertTrue( true );
	}

	public function test_a_recent_run_holds_back_a_smoke_test_timing_but_not_an_update_timing(): void {
		$this->options[ Timings::OPTION ] = array( array( 'id' => 'a', 'time' => time() - 60, 'pages' => array() ) );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );

		$this->assertFalse( $this->timings->queue( 'smoke-test' ) );
		$this->assertTrue( $this->timings->queue( 'update' ) );
	}

	public function test_timing_off_queues_nothing(): void {
		$this->options[ Settings::OPTION ] = array( 'timing' => false );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertFalse( ( new Timings( new Settings(), new Alerts( new Settings() ) ) )->queue( 'update' ) );
	}

	public function test_confirm_verdicts(): void {
		// The page got 340 ms slower; leaving the plugin out saves 300 ms.
		$this->assertSame( 'confirmed', Confirm::verdict( 520.0, 220.0, 200, 200, true, 340.0 )['verdict'] );
		// Saves about a third of the slowdown: part of it.
		$this->assertSame( 'partly', Confirm::verdict( 520.0, 400.0, 200, 200, true, 340.0 )['verdict'] );
		// Saves almost nothing.
		$this->assertSame( 'not', Confirm::verdict( 520.0, 505.0, 200, 200, true, 340.0 )['verdict'] );
		// The plugin was not left out, or the page fails without it: nothing is concluded.
		$this->assertSame( 'inconclusive', Confirm::verdict( 520.0, 220.0, 200, 200, false, 340.0 )['verdict'] );
		$this->assertSame( 'inconclusive', Confirm::verdict( 520.0, 90.0, 200, 500, true, 340.0 )['verdict'] );
		$this->assertSame( 'inconclusive', Confirm::verdict( 520.0, 90.0, 200, 301, true, 340.0 )['verdict'] );
	}

	public function test_confirm_messages_name_the_plugin_and_the_numbers(): void {
		$message = Confirm::message( 'confirmed', 'Elementor', 'Home page', 520.0, 220.0, 200 );
		$this->assertSame( 'Home page took 520 ms. Without Elementor it took 220 ms, so Elementor adds about 300 ms.', $message );
		$this->assertStringContainsString( 'is not the cause', Confirm::message( 'not', 'Elementor', 'Home page', 520.0, 505.0, 200 ) );
		$this->assertStringContainsString( 'Nothing was changed on the site', Confirm::message( 'inconclusive', 'Elementor', 'Home page', 520.0, 0.0, 0 ) );
	}

	public function test_confirm_refuses_while_the_loader_is_off(): void {
		$result = ( new Confirm( new Settings() ) )->run( 'abc' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_runtime_doctor_confirm_off', $result->get_error_code() );
	}

	public function test_only_a_well_formed_plugin_that_is_not_this_one_can_be_left_out(): void {
		$this->assertTrue( Loader::skippable( 'elementor/elementor.php' ) );
		$this->assertTrue( Loader::skippable( 'hello.php' ) );
		$this->assertFalse( Loader::skippable( 'axle-runtime-doctor/axle-runtime-doctor.php' ) );
		$this->assertFalse( Loader::skippable( '../../evil.php' ) );
		$this->assertFalse( Loader::skippable( 'a/b/c.php' ) );
		$this->assertFalse( Loader::skippable( "x/y.php\nz" ) );
		$this->assertFalse( Loader::skippable( 'elementor/elementor.txt' ) );
		$this->assertFalse( Loader::skippable( '' ) );
	}

	public function test_no_row_is_written_while_the_loader_is_not_installed(): void {
		$this->assertFalse( Loader::installed() );
		$this->assertFalse( Loader::allow( str_repeat( 'a', 40 ), 'elementor/elementor.php' ) );
		$this->assertSame( array(), $this->options );
	}

	public function test_the_slowdown_email_names_the_update(): void {
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_site_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		$this->options['blogname']             = 'Example';
		$this->options['admin_email']          = 'admin@example.com';
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
		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message ) use ( &$sent ) {
				$sent = array( $to, $subject, $message );
				return true;
			}
		);
		$row = array(
			'key'     => '/',
			'label'   => 'Home page',
			'before'  => 180.0,
			'after'   => 520.0,
			'delta'   => 340.0,
			'percent' => 189,
			'updates' => array( 'abc' ),
			'summary' => 'Home page server time went from 180 ms to 520 ms after Elementor 3.25 to 3.26 on Tuesday.',
		);
		Actions\expectDone( 'axle_runtime_doctor_slowdown_alert_sent' )->once();

		( new Alerts( $this->settings ) )->send_slowdown( array( $row ), array( 'time' => 1700000000 ) );

		$this->assertSame( array( 'admin@example.com' ), $sent[0] );
		$this->assertSame( '[Example] A page got slower: ' . $row['summary'], $sent[1] );
		$this->assertStringContainsString( 'after Elementor 3.25 to 3.26', $sent[2] );
		$notice = $this->options[ Alerts::NOTICE ];
		$this->assertSame( 'timing:/', $notice['regressions'][0]['id'] );
		$this->assertSame( 'critical', $notice['regressions'][0]['after'], 'more than twice as slow' );
	}

	public function test_a_slowdown_notice_joins_an_open_check_notice(): void {
		Functions\when( 'get_site_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		$this->options[ Alerts::NOTICE ] = array( 'time' => 1, 'regressions' => array( array( 'id' => 'opcache_memory', 'label' => 'OPcache memory', 'before' => 'good', 'after' => 'critical', 'summary' => 'x' ) ) );
		$this->options['admin_email']    = '';
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/tools.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/tools.php?page=x' );
		$this->options[ Settings::OPTION ] = array( 'recipients' => 'a@example.com' );
		$settings                          = new Settings();

		( new Alerts( $settings ) )->send_slowdown( array( array( 'key' => '/', 'label' => 'Home page', 'percent' => 40, 'summary' => 's' ) ), array( 'time' => 2 ) );

		$ids = array_column( $this->options[ Alerts::NOTICE ]['regressions'], 'id' );
		$this->assertSame( array( 'opcache_memory', 'timing:/' ), $ids );
		$this->assertSame( 'recommended', $this->options[ Alerts::NOTICE ]['regressions'][1]['after'] );
	}

	public function test_site_health_reports_the_latest_slowdown(): void {
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/tools.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/tools.php?page=x' );
		$history = new History( new Alerts( $this->settings ) );
		$health  = new SiteHealth( new Runner( $this->settings, new Registry(), new Hosts( $this->settings, array( 'software' => 'nginx' ) ), new Sampler( $this->settings ), new Autoload(), new PhpReleases(), $history ), $history, new Registry() );

		$this->assertSame( 'good', $health->timing_test()['status'], 'not timed yet' );

		$this->options[ Timings::OPTION ] = array( array( 'id' => 'a', 'time' => 1, 'pages' => array(), 'regressions' => array() ) );
		$this->assertSame( 'No page got slower in the latest timing', $health->timing_test()['label'] );

		$this->options[ Timings::OPTION ] = array( array( 'id' => 'a', 'time' => 1, 'pages' => array(), 'regressions' => array( array( 'summary' => 'Home page got slower after X.' ) ) ) );
		$result                           = $health->timing_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Home page got slower after X.', $result['label'] );
	}

	public function test_page_cache_blockers_are_not_listed_twice(): void {
		$pages   = array(
			array(
				'url'      => 'https://example.com/',
				'blockers' => array( array( 'detail' => 'The response sets the cookie wordpress_test_cookie.' ) ),
			),
		);
		$details = array(
			'/: The response sets the cookie wordpress_test_cookie.',
			'/: The tracing request saw no cookie or header being set.',
			'The cookie wp-settings-1 points at: WordPress.',
		);

		$this->assertSame(
			array( '/: The tracing request saw no cookie or header being set.', 'The cookie wp-settings-1 points at: WordPress.' ),
			PageCachePanel::without_blockers( $details, $pages )
		);
	}

	public function test_the_probe_and_the_page_cache_test_send_the_loopback_headers(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 't', 40 ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url = '' ) {
				return is_array( $key ) ? $value . '?' . http_build_query( $key ) : $url . '?' . $key . '=' . $value;
			}
		);
		$seen = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$seen ) {
				$seen['probe'] = $args['headers'];
				return array();
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$seen ) {
				$seen['get'][] = $args['headers'];
				return array( 'code' => 200, 'headers' => array() );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] ?? 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( $r ) => $r['headers'] ?? array() );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'esc_url_raw' )->returnArg();

		Probe::call( 'ping' );
		Scanner::test( 'https://example.com/' );

		$this->assertMatchesRegularExpression( '/^\d{9,11}\.[a-f0-9]{64}$/', $seen['probe']['X-Axle-Loopback'] );
		$this->assertMatchesRegularExpression( '/^\d{9,11}\.[a-f0-9]{64}$/', $seen['get'][0]['X-Axle-Loopback'] );
		$this->assertSame( array(), AxleUi::loopback_headers( 'https://elsewhere.test/' ), 'never to another host' );
	}
}
