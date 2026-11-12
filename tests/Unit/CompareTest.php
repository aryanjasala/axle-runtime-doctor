<?php
/**
 * Tests for comparing two sites: export, import, compare, kept reports and direct mode.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Compare\Comparer;
use AryanX\Axle\RuntimeDoctor\Compare\Direct;
use AryanX\Axle\RuntimeDoctor\Compare\Exporter;
use AryanX\Axle\RuntimeDoctor\Compare\Importer;
use AryanX\Axle\RuntimeDoctor\Compare\Library;
use AryanX\Axle\RuntimeDoctor\Probe;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CompareTest extends DoctorTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_generate_password' )->alias(
			static function ( $length = 12 ) {
				static $n = 0;
				++$n;
				return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGH', 3 ) . $n, $n, (int) $length );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $text ) {
				return rtrim( $text, '/\\' ) . '/';
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			function ( $name, $value ) {
				$this->options[ '_t_' . $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_site_transient' )->alias(
			function ( $name ) {
				return $this->options[ '_t_' . $name ] ?? false;
			}
		);
		Functions\when( 'delete_site_transient' )->alias(
			function ( $name ) {
				unset( $this->options[ '_t_' . $name ] );
				return true;
			}
		);
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	/**
	 * A stored report of this site.
	 */
	private function stored(): array {
		return array(
			'time'        => 1700000000,
			'host'        => array(
				'id'       => 'docker',
				'label'    => 'Docker',
				'evidence' => array( 'secret evidence' ),
			),
			'sapi'        => 'web',
			'counts'      => array( 'critical' => 1 ),
			'results'     => array(
				'opcache_coverage' => array(
					'id'       => 'opcache_coverage',
					'group'    => 'opcache',
					'label'    => 'OPcache coverage',
					'status'   => 'critical',
					'summary'  => 'OPcache skips /srv/www/site/wp-content/plugins/x.php on example.com for admin@example.com from 10.1.2.3',
					'value'    => 'Core cached',
					'expected' => 'Both cached',
					'details'  => array( 'blacklist in /usr/local/etc/php/ard-blacklist.txt' ),
					'data'     => array( 'token' => 'hunter2' ),
				),
			),
			'environment' => array(
				'opcache.max_accelerated_files' => array(
					'label' => 'PHP setting opcache.max_accelerated_files',
					'group' => 'opcache',
					'value' => '2000',
				),
			),
		);
	}

	public function test_export_keeps_verdicts_and_takes_identifying_text_out(): void {
		$document = Exporter::build( $this->stored() );
		$json     = Exporter::to_json( $document );

		$this->assertSame( Exporter::FORMAT, $document['format'] );
		$this->assertSame( 'critical', $document['results']['opcache_coverage']['status'] );
		$summary = $document['results']['opcache_coverage']['summary'];
		$this->assertStringContainsString( '{path}/x.php', $summary );
		$this->assertStringContainsString( '{site}', $summary );
		$this->assertStringContainsString( '{email}', $summary );
		$this->assertStringContainsString( '{ip}', $summary );
		foreach ( array( '/srv/www', 'admin@example.com', '10.1.2.3', 'hunter2', 'ard-blacklist', 'secret evidence' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $json, $leak );
		}
		$this->assertSame( '2000', $document['settings']['opcache.max_accelerated_files']['value'] );
	}

	public function test_export_replaces_the_site_roots_and_runs_the_filters(): void {
		Filters\expectApplied( 'axle_runtime_doctor_export_replacements' )->once();
		Filters\expectApplied( 'axle_runtime_doctor_export_report' )->once();
		$report                                      = $this->stored();
		$report['results']['opcache_coverage']['value'] = WP_CONTENT_DIR . '/plugins/a/b.php';

		$this->assertSame( '{plugins}/a/b.php', Exporter::build( $report )['results']['opcache_coverage']['value'] );
	}

	public function test_publish_fires_the_exported_action(): void {
		Actions\expectDone( 'axle_runtime_doctor_report_exported' )->once()->with( \Mockery::type( 'array' ), 'cli' );
		Exporter::publish( $this->stored(), 'cli' );
	}

	public function test_import_accepts_what_export_made_and_nothing_else(): void {
		$parsed = Importer::parse( Exporter::to_json( Exporter::build( $this->stored() ) ) );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'critical', $parsed['results']['opcache_coverage']['status'] );
		$this->assertArrayNotHasKey( 'details', $parsed['results']['opcache_coverage'] );
	}

	public function test_import_refuses_bad_input(): void {
		$cases = array(
			'empty'        => '',
			'not json'     => 'O:8:"stdClass":0:{}',
			'a list'       => '[1,2,3]',
			'other format' => '{"format":"something-else","version":1,"results":{}}',
			'newer'        => '{"format":"axle-runtime-doctor-report","version":99,"results":{"a":{"id":"a"}}}',
			'no results'   => '{"format":"axle-runtime-doctor-report","version":1}',
			'bad ids only' => '{"format":"axle-runtime-doctor-report","version":1,"results":{"BAD ID!":{"id":"BAD ID!"}}}',
			'too deep'     => '{"format":"axle-runtime-doctor-report","version":1,"results":{"a":{"id":"a","status":{"x":{"y":{"z":{"w":{"v":{"u":1}}}}}}}}}',
		);
		foreach ( $cases as $name => $json ) {
			$this->assertInstanceOf( \WP_Error::class, Importer::parse( $json ), $name );
		}

		Filters\expectApplied( 'axle_runtime_doctor_import_max_bytes' )->atLeast()->once()->andReturn( 10240 );
		$this->assertSame( 'axle_runtime_doctor_import_large', Importer::parse( str_repeat( ' x', 6000 ) )->get_error_code() );
	}

	public function test_import_cleans_every_field(): void {
		$json   = (string) json_encode(
			array(
				'format'  => Exporter::FORMAT,
				'version' => 1,
				'time'    => 5,
				'host'    => array(
					'id'    => 'Docker<script>',
					'label' => '<b>Dock</b>er',
				),
				'results' => array(
					'a_check' => array(
						'id'      => 'a_check',
						'group'   => 'OPcache!',
						'status'  => 'nonsense',
						'summary' => '<script>alert(1)</script>Fine',
						'extra'   => 'dropped',
					),
				),
				'settings' => array(
					'php_version' => array(
						'label' => 'PHP',
						'group' => 'php',
						'value' => '8.3',
					),
					'Bad Key'     => array( 'value' => 'x' ),
					'nested'      => array( 'value' => array( 'x' ) ),
				),
			)
		);
		$parsed = Importer::parse( $json );

		$this->assertSame( 'info', $parsed['results']['a_check']['status'] );
		$this->assertSame( 'opcache', $parsed['results']['a_check']['group'] );
		$this->assertStringNotContainsString( '<', $parsed['results']['a_check']['summary'] );
		$this->assertArrayNotHasKey( 'extra', $parsed['results']['a_check'] );
		$this->assertSame( 0, $parsed['time'] );
		$this->assertSame( 'dockerscript', $parsed['host']['id'] );
		$this->assertSame( array( 'php_version' ), array_keys( $parsed['settings'] ) );
	}

	public function test_compare_puts_differences_first_and_says_what_they_mean(): void {
		$here  = array(
			'results'  => array(
				'opcache_coverage' => array(
					'label'   => 'OPcache coverage',
					'group'   => 'opcache',
					'status'  => 'critical',
					'summary' => 'OPcache is skipping wp-content',
					'value'   => 'Core only',
				),
				'php_branch'       => array(
					'label'   => 'PHP branch',
					'status'  => 'good',
					'summary' => 'Supported',
					'value'   => '8.3',
				),
				'cron_mode'        => array(
					'label'   => 'Cron',
					'status'  => 'good',
					'summary' => 'WP-Cron',
					'value'   => '3 events',
				),
			),
			'settings' => array(
				'opcache.max_accelerated_files' => array(
					'label' => 'Files',
					'group' => 'opcache',
					'value' => '2000',
				),
				'memory_limit'                  => array(
					'label' => 'Memory',
					'group' => 'php',
					'value' => '256M',
				),
			),
		);
		$there = array(
			'results'  => array(
				'opcache_coverage' => array(
					'label'   => 'OPcache coverage',
					'group'   => 'opcache',
					'status'  => 'good',
					'summary' => 'Caches core and wp-content',
					'value'   => 'Both',
				),
				'php_branch'       => array(
					'label'   => 'PHP branch',
					'status'  => 'good',
					'summary' => 'Supported',
					'value'   => '8.3',
				),
				'cron_mode'        => array(
					'label'   => 'Cron',
					'status'  => 'good',
					'summary' => 'WP-Cron',
					'value'   => '9 events',
				),
				'only_there'       => array(
					'label'   => 'New check',
					'status'  => 'good',
					'summary' => 'x',
					'value'   => 'y',
				),
			),
			'settings' => array(
				'opcache.max_accelerated_files' => array(
					'label' => 'Files',
					'group' => 'opcache',
					'value' => '10000',
				),
				'memory_limit'                  => array(
					'label' => 'Memory',
					'group' => 'php',
					'value' => '268435456',
				),
			),
		);

		$result = Comparer::compare( $here, $there, 'Staging', 'Production' );
		$keys   = array_column( $result['rows'], 'key' );
		$rows   = array_column( $result['rows'], null, 'key' );

		$this->assertSame( 'opcache_coverage', $keys[0], 'The worst check difference comes first.' );
		$this->assertSame( Comparer::DIFFERENT, $rows['opcache_coverage']['state'] );
		$this->assertStringContainsString( 'Staging is critical: OPcache is skipping wp-content', $rows['opcache_coverage']['meaning'] );
		$this->assertSame( Comparer::DIFFERENT, $rows['opcache.max_accelerated_files']['state'] );
		$this->assertStringContainsString( '2,000 files on Staging and 10,000 on Production', $rows['opcache.max_accelerated_files']['meaning'] );
		$this->assertStringContainsString( 'Staging runs out of slots first', $rows['opcache.max_accelerated_files']['meaning'] );
		$this->assertSame( Comparer::SAME, $rows['memory_limit']['state'], '256M and 268435456 are the same size.' );
		$this->assertSame( Comparer::SAME, $rows['php_branch']['state'] );
		$this->assertSame( Comparer::MINOR, $rows['cron_mode']['state'] );
		$this->assertSame( Comparer::ONLY, $rows['only_there']['state'] );
		$this->assertSame( 2, $result['counts'][ Comparer::DIFFERENT ] );
		$this->assertLessThan( array_search( 'cron_mode', $keys, true ), array_search( 'only_there', $keys, true ) );
		$this->assertGreaterThan( array_search( 'cron_mode', $keys, true ), array_search( 'memory_limit', $keys, true ) );
	}

	public function test_compare_meaning_filter_and_ignored_settings(): void {
		Filters\expectApplied( 'axle_runtime_doctor_compare_ignored_settings' )->atLeast()->once()->andReturn( array( 'memory_limit' ) );
		Filters\expectApplied( 'axle_runtime_doctor_compare_meaning' )->atLeast()->once()->andReturn( 'Custom words.' );
		$setting = static function ( $value ) {
			return array(
				'settings' => array(
					'memory_limit' => array(
						'label' => 'Memory',
						'group' => 'php',
						'value' => $value,
					),
					'wp_debug'     => array(
						'label' => 'WP_DEBUG',
						'group' => 'wordpress',
						'value' => 'on' === $value || '1' === $value ? 'on' : 'off',
					),
				),
			);
		};

		$result = Comparer::compare( $setting( '256M' ), $setting( '1' ), 'A', 'B' );
		$this->assertSame( array( 'wp_debug' ), array_column( $result['rows'], 'key' ) );
		$this->assertSame( 'Custom words.', $result['rows'][0]['meaning'] );
	}

	public function test_library_keeps_names_removes_and_restores(): void {
		$library = new Library();
		$report  = Importer::parse( Exporter::to_json( Exporter::build( $this->stored() ) ) );

		Actions\expectDone( 'axle_runtime_doctor_report_imported' )->twice();
		$item = $library->add( $report, '  <b>Before</b> the move ', 'file' );
		$this->assertSame( 'Before the move', $item['name'] );
		$this->assertSame( 'file', $item['source'] );
		$this->assertCount( 1, $library->all() );
		$this->assertSame( 'Imported report', $library->add( $report, '', 'nonsense' )['name'] );

		Actions\expectDone( 'axle_runtime_doctor_report_removed' )->once();
		$this->assertTrue( $library->remove( $item['id'] ) );
		$this->assertFalse( $library->remove( $item['id'] ) );
		$this->assertCount( 1, $library->all() );

		Actions\expectDone( 'axle_runtime_doctor_report_restored' )->once();
		$this->assertSame( $item['id'], $library->restore()['id'] );
		$this->assertCount( 2, $library->all() );
		$this->assertNull( $library->restore(), 'Only the last removal can come back, once.' );
	}

	public function test_library_has_a_limit(): void {
		Filters\expectApplied( 'axle_runtime_doctor_import_limit' )->atLeast()->once()->andReturn( 1 );
		$library = new Library();
		$report  = Importer::parse( Exporter::to_json( Exporter::build( $this->stored() ) ) );

		$this->assertIsArray( $library->add( $report, 'One' ) );
		$this->assertSame( 'axle_runtime_doctor_import_full', $library->add( $report, 'Two' )->get_error_code() );
	}

	public function test_a_code_round_trips_and_refuses_tampering(): void {
		$made    = Direct::create_code();
		$decoded = Direct::decode( $made['code'] );

		$this->assertSame( 'https://example.com', $decoded['origin'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{40}$/', $decoded['token'] );
		$this->assertCount( 1, array_filter( array_keys( $this->options ), static fn( $name ) => 0 === strpos( $name, Probe::PREFIX ) ) );

		$parts    = explode( '.', $made['code'] );
		$tampered = $parts[0] . '.' . $parts[1] . 'A.' . $parts[2];
		foreach ( array( $tampered, 'ard1.abc', '', 'ard2.' . $parts[1] . '.' . $parts[2], $parts[0] . '.' . $parts[1] . '.000000' ) as $bad ) {
			$this->assertInstanceOf( \WP_Error::class, Direct::decode( $bad ), $bad );
		}
	}

	public function test_a_code_expires_and_works_once_on_the_sharing_site(): void {
		$made    = Direct::create_code();
		$token   = Direct::decode( $made['code'] )['token'];
		$request = new class() extends \WP_REST_Request {
			public function get_header( $key ) {
				return $GLOBALS['ard_test_token'];
			}
		};

		$GLOBALS['ard_test_token'] = $token;
		$direct                    = ( new \ReflectionClass( Direct::class ) )->newInstanceWithoutConstructor();
		$this->assertTrue( $direct->authorize( $request ) );
		$this->assertInstanceOf( \WP_Error::class, $direct->authorize( $request ), 'The second use is refused.' );

		$GLOBALS['ard_test_token'] = Direct::decode( Direct::create_code()['code'] )['token'];
		foreach ( array_keys( $this->options ) as $name ) {
			if ( 0 === strpos( $name, Probe::PREFIX ) ) {
				$this->options[ $name ]['expires'] = time() - 1;
			}
		}
		$this->assertInstanceOf( \WP_Error::class, $direct->authorize( $request ), 'An expired code is refused.' );
		unset( $GLOBALS['ard_test_token'] );
	}

	public function test_a_share_code_does_not_open_the_probe_route(): void {
		$token = Probe::issue( Direct::MODE, array(), 600 );
		$this->assertNull( Probe::consume( $token, array( 'run', 'read', 'timing', 'ping' ) ) );
		$this->assertSame( Direct::MODE, Probe::consume( $token, Direct::MODE )['mode'] );
	}

	public function test_a_token_is_only_good_for_the_request_whose_delete_wins(): void {
		$token = Probe::issue( 'run' );
		Functions\when( 'delete_option' )->justReturn( false );

		$this->assertNull( Probe::consume( $token ), 'A second request that lost the race gets nothing.' );
	}

	public function test_a_token_row_with_the_wrong_hash_is_refused(): void {
		$name                   = Probe::PREFIX . Probe::hash( 'abc' );
		$this->options[ $name ] = array(
			'mode'    => 'run',
			'args'    => array(),
			'hash'    => 'something-else',
			'expires' => time() + 60,
		);

		$this->assertNull( Probe::consume( 'abc' ) );
	}

	public function test_fetch_calls_only_https_public_addresses_with_the_token_in_a_header(): void {
		Functions\when( 'wp_http_validate_url' )->justReturn( true );
		$seen = array();
		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $url, $args ) use ( &$seen ) {
				$seen = array( $url, $args );
				return array( 'body' => (string) json_encode( Exporter::build( array( 'results' => array( 'a_check' => array( 'id' => 'a_check', 'status' => 'good' ) ) ) ) ), 'code' => 200 );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Actions\expectDone( 'axle_runtime_doctor_direct_fetched' )->once();

		$made     = Direct::create_code();
		$document = Direct::fetch( $made['code'] );

		$this->assertIsArray( $document );
		$this->assertSame( 'https://example.com/?rest_route=/axle-runtime-doctor/v1/share', $seen[0] );
		$this->assertSame( Direct::decode( $made['code'] )['token'], $seen[1]['headers'][ Probe::HEADER ] );
		$this->assertSame( 0, $seen[1]['redirection'] );
		$this->assertGreaterThan( 0, $seen[1]['limit_response_size'] );
	}

	public function test_fetch_refuses_http_unsafe_addresses_and_a_refusal(): void {
		Actions\expectDone( 'axle_runtime_doctor_direct_fetch_failed' )->times( 3 );
		$code = static function ( $origin ) {
			$body = rtrim( strtr( base64_encode( (string) json_encode( array( 'u' => $origin, 't' => str_repeat( 'a', 40 ) ) ) ), '+/', '-_' ), '=' );
			return 'ard1.' . $body . '.' . substr( hash( 'crc32b', $body ), 0, 6 );
		};

		$this->assertSame( 'axle_runtime_doctor_direct_scheme', Direct::fetch( $code( 'http://example.org' ) )->get_error_code() );

		Functions\when( 'wp_http_validate_url' )->justReturn( false );
		$this->assertSame( 'axle_runtime_doctor_direct_unsafe', Direct::fetch( $code( 'https://192.168.1.5' ) )->get_error_code() );

		Functions\when( 'wp_http_validate_url' )->justReturn( true );
		Functions\when( 'wp_safe_remote_get' )->justReturn( array( 'code' => 403 ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] );
		$this->assertSame( 'axle_runtime_doctor_direct_refused', Direct::fetch( $code( 'https://example.org' ) )->get_error_code() );
	}

	public function test_direct_mode_can_be_turned_off(): void {
		Filters\expectApplied( 'axle_runtime_doctor_direct_enabled' )->atLeast()->once()->andReturn( false );

		$this->assertSame( 'axle_runtime_doctor_direct_off', Direct::create_code()->get_error_code() );
		$this->assertSame( 'axle_runtime_doctor_direct_off', Direct::fetch( 'ard1.x.y' )->get_error_code() );
	}

	public function test_core_options_are_never_switched_off_and_autoload_can_be_undone(): void {
		$this->assertTrue( Autoload::is_protected( 'siteurl' ) );
		$this->assertTrue( Autoload::is_protected( 'widget_text' ) );
		$this->assertFalse( Autoload::is_protected( 'my_plugin_cache' ) );
		$this->assertFalse( Autoload::is_protected( 'theme_mods_twentytwentyfive' ) );

		$this->wpdb->answers['get_results'] = array( array( array( 'option_name' => 'siteurl', 'autoload' => 'yes' ) ) );
		$done = ( new Autoload() )->disable( array( 'siteurl' ) );
		$this->assertSame( array(), $done['changed'] );
		$this->assertArrayHasKey( 'siteurl', $done['skipped'] );

		$this->wpdb->answers['get_results'] = array(
			array( array( 'option_name' => 'my_cache', 'autoload' => 'off' ) ),
			array( array( 'option_name' => 'my_cache', 'autoload' => 'on' ) ),
		);
		Functions\expect( 'wp_set_option_autoload_values' )->once()->with( array( 'my_cache' => true ) );
		Actions\expectDone( 'axle_runtime_doctor_autoload_restored' )->once();
		$this->assertSame( array( 'my_cache' ), ( new Autoload() )->enable( array( 'my_cache' ) )['changed'] );
	}
}
