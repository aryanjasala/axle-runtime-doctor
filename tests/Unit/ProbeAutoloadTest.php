<?php
/**
 * Tests for the probe, query digests, autoloaded options, facts helpers and sampling.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Facts;
use AryanX\Axle\RuntimeDoctor\PhpReleases;
use AryanX\Axle\RuntimeDoctor\Probe;
use AryanX\Axle\RuntimeDoctor\Sampler;
use AryanX\Axle\RuntimeDoctor\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ProbeAutoloadTest extends DoctorTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	public function test_a_token_works_once_and_only_for_its_mode(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'secret-token' );
		$token = Probe::issue( 'read', array( 'key' => 'k' ) );
		$name  = Probe::PREFIX . Probe::hash( $token );

		$this->assertArrayHasKey( $name, $this->options );
		$this->assertStringNotContainsString( 'secret-token', $name );
		$this->assertNull( Probe::consume( $token, 'queries' ) );
		$this->assertSame( array( 'key' => 'k' ), Probe::consume( $token )['args'] );
		$this->assertNull( Probe::consume( $token ) );
	}

	public function test_an_expired_token_is_refused(): void {
		$this->options[ Probe::PREFIX . Probe::hash( 'old' ) ] = array(
			'mode'    => 'run',
			'args'    => array(),
			'expires' => time() - 1,
		);

		$this->assertNull( Probe::consume( 'old' ) );
		$this->assertArrayNotHasKey( Probe::PREFIX . Probe::hash( 'old' ), $this->options );
	}

	public function test_loopback_url_filter_rewrites_site_urls(): void {
		$this->assertSame( 'https://example.com', Probe::base_url() );

		Filters\expectApplied( 'axle_runtime_doctor_loopback_url' )->atLeast()->once()->with( 'https://example.com' )->andReturn( 'http://127.0.0.1:8080/' );
		$this->assertSame( 'http://127.0.0.1:8080/?rest_route=/x', Probe::loopback( 'https://example.com/?rest_route=/x' ) );
		$this->assertSame( 'https://other.example/', Probe::loopback( 'https://other.example/' ) );
	}

	public function test_an_invalid_loopback_url_falls_back_to_home(): void {
		Filters\expectApplied( 'axle_runtime_doctor_loopback_url' )->once()->andReturn( 'javascript:alert(1)' );
		$this->assertSame( 'https://example.com', Probe::base_url() );
	}

	public function test_a_failed_probe_fires_an_action(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 't' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/?rest_route=/axle-runtime-doctor/v1/probe' );
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Actions\expectDone( 'axle_runtime_doctor_probe_failed' )->once()->with( \Mockery::type( \WP_Error::class ), 'read', 'https://example.com/?rest_route=/axle-runtime-doctor/v1/probe' );

		$result = Probe::call( 'read' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'The site answered the probe with HTTP 403.', $result->get_error_message() );
	}

	public function test_digest_strips_every_literal(): void {
		$this->assertSame(
			'SELECT * FROM wp_users WHERE user_email = ? AND ID IN (?, ...) AND score > ? LIMIT ?',
			Probe::digest( "SELECT  *\n FROM wp_users WHERE user_email = 'sam@example.com' AND ID IN ( 1, 2, 3 ) AND score > -4.5 LIMIT 10" )
		);
		$this->assertSame( 'SELECT option_value FROM wp_2_options WHERE option_name = ?', Probe::digest( 'SELECT option_value FROM wp_2_options WHERE option_name = "it\'s \"x\""' ) );
	}

	public function test_group_sums_queries_by_shape(): void {
		$groups = Probe::group(
			array(
				array( "SELECT * FROM t WHERE id = 1", 0.010, 'caller', 0, array( 'axle_runtime_doctor_component' => 'plugin:shop' ) ),
				array( "SELECT * FROM t WHERE id = 2", 0.030, 'caller', 0, array( 'axle_runtime_doctor_component' => 'plugin:shop' ) ),
				array( 'SELECT 1', 0.001, 'caller', 0, array() ),
				'not a query',
			)
		);

		$this->assertCount( 2, $groups );
		$this->assertSame(
			array(
				'sql'       => 'SELECT * FROM t WHERE id = ?',
				'calls'     => 2,
				'total_ms'  => 40.0,
				'max_ms'    => 30.0,
				'component' => 'plugin:shop',
			),
			$groups[0]
		);
		$this->assertSame( 'core', $groups[1]['component'] );
	}

	public function test_component_names_plugins_themes_and_mu_plugins(): void {
		Functions\when( 'get_theme_root' )->justReturn( '/var/www/html/wp-content/themes' );

		$this->assertSame( 'plugin:woocommerce', Probe::component( '/var/www/html/wp-content/plugins/woocommerce/includes/x.php' ) );
		$this->assertSame( 'theme:twentytwentyfive', Probe::component( '/var/www/html/wp-content/themes/twentytwentyfive/functions.php' ) );
		$this->assertSame( 'mu-plugin:loader.php', Probe::component( '/var/www/html/wp-content/mu-plugins/loader.php' ) );
		$this->assertSame( '', Probe::component( '/var/www/html/wp-includes/class-wpdb.php' ) );
	}

	public function test_blacklist_entries_match_like_opcache(): void {
		$this->assertTrue( Facts::blacklist_matches( '/var/www/html/wp-content/*', '/var/www/html/wp-content/plugins/x.php' ) );
		$this->assertTrue( Facts::blacklist_matches( '/var/www/html/wp-content', '/var/www/html/wp-content/themes/x.php' ) );
		$this->assertTrue( Facts::blacklist_matches( '/srv/*/wp-content/plugins/', '/srv/site1/wp-content/plugins/x.php' ) );
		$this->assertFalse( Facts::blacklist_matches( '/var/www/html/wp-admin/*', '/var/www/html/wp-content/plugins/x.php' ) );
	}

	public function test_cdn_headers(): void {
		$this->assertSame( array( 'Cloudflare' ), Facts::cdn( array( 'CF-Ray' => 'abc', 'Server' => 'cloudflare' ) ) );
		$this->assertSame( array( 'Sucuri', 'CloudFront' ), Facts::cdn( array( 'x-sucuri-id' => '1', 'x-amz-cf-id' => '2' ) ) );
		$this->assertSame( array(), Facts::cdn( array( 'server' => 'Apache' ) ) );
	}

	public function test_coverage_files_filter(): void {
		Functions\when( 'get_template_directory' )->justReturn( '/nowhere' );
		Functions\when( 'wp_get_mu_plugins' )->justReturn( array() );
		Filters\expectApplied( 'axle_runtime_doctor_coverage_files' )->once()->andReturnUsing(
			static function ( $files ) {
				$files['content'][] = '/var/www/html/wp-content/plugins/big/big.php';
				return $files;
			}
		);

		$files = Facts::coverage_files();
		$this->assertSame( array( ABSPATH . 'wp-includes/plugin.php' ), $files['core'] );
		$this->assertContains( '/var/www/html/wp-content/plugins/big/big.php', $files['content'] );
	}

	public function test_probe_urls_filter_keeps_ten_unique(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/?s=a' );
		Filters\expectApplied( 'axle_runtime_doctor_probe_urls' )->once()->andReturnUsing(
			static function ( $urls ) {
				return array_merge( $urls, array( 'https://example.com/' ), array_map( static function ( $n ) { return 'https://example.com/p' . $n; }, range( 1, 20 ) ) );
			}
		);

		$urls = Facts::probe_urls();
		$this->assertCount( 10, $urls );
		$this->assertSame( array( 'https://example.com/', 'https://example.com/?s=a' ), array_slice( $urls, 0, 2 ) );
	}

	public function test_scan_limit_filter(): void {
		$this->assertSame( 20000, Facts::scan_limit() );
		Filters\expectApplied( 'axle_runtime_doctor_scan_limit' )->once()->andReturn( 5 );
		$this->assertSame( 100, Facts::scan_limit() );
	}

	public function test_count_files_finds_php_in_a_folder(): void {
		$dir = sys_get_temp_dir() . '/ard-' . uniqid();
		mkdir( $dir . '/2026/10', 0777, true );
		touch( $dir . '/2026/10/photo.jpg' );
		touch( $dir . '/2026/10/shell.php' );
		$found = array();

		$this->assertSame( 1, Facts::count_files( $dir, '/\.php$/', 100, $found ) );
		$this->assertSame( array( '2026/10/shell.php' ), $found );
		$this->assertNull( Facts::count_files( $dir, '/\.php$/', 1 ) );

		unlink( $dir . '/2026/10/photo.jpg' );
		unlink( $dir . '/2026/10/shell.php' );
		rmdir( $dir . '/2026/10' );
		rmdir( $dir . '/2026' );
		rmdir( $dir );
	}

	public function test_php_versions_and_db_versions_filters(): void {
		Filters\expectApplied( 'axle_runtime_doctor_php_versions' )->once()->andReturn( array( 'branches' => array( '9.0' => array( 'security' => '2031-01-01' ) ) ) );
		Filters\expectApplied( 'axle_runtime_doctor_db_versions' )->once()->andReturn( array( 'mysql' => array( '9.9' => '2040-01-01' ) ) );
		$settings = new Settings();
		$facts    = new Facts( $settings, new \AryanX\Axle\RuntimeDoctor\Hosts( $settings, array() ), new Sampler( $settings ), new Autoload(), new PhpReleases(), 'web' );

		$this->assertArrayHasKey( '9.0', $facts->get( 'php_versions' )['branches'] );
		$this->assertSame( '2040-01-01', $facts->get( 'db_versions' )['mysql']['9.9'] );
		$this->assertNull( $facts->get( 'nothing_like_this' ) );
		$this->assertNull( $facts->get( 'php_releases' ) );
	}

	public function test_owner_of_options(): void {
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ),
				'old-gallery/old-gallery.php' => array( 'Name' => 'Old Gallery' ),
			)
		);
		Functions\when( 'is_plugin_active' )->alias(
			static function ( $file ) {
				return 'woocommerce/woocommerce.php' === $file;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		$autoload = new Autoload();

		$this->assertSame( 'core', $autoload->owner( 'siteurl' )['status'] );
		$this->assertSame( 'core', $autoload->owner( 'wp_user_roles' )['status'] );
		$this->assertSame( array( 'label' => 'twentytwentyfive', 'status' => 'theme' ), $autoload->owner( 'theme_mods_twentytwentyfive' ) );
		$this->assertSame( array( 'label' => 'WooCommerce', 'status' => 'active' ), $autoload->owner( 'woocommerce_settings' ) );
		$this->assertSame( array( 'label' => 'Old Gallery', 'status' => 'inactive' ), $autoload->owner( 'old_gallery_cache' ) );
		$this->assertSame( 'unknown', $autoload->owner( 'mystery_option' )['status'] );
	}

	public function test_option_owner_filter(): void {
		Filters\expectApplied( 'axle_runtime_doctor_option_owner' )->once()->with( array( 'label' => 'WordPress', 'status' => 'core' ), 'siteurl' )->andReturn( array( 'label' => 'Platform', 'status' => 'active' ) );
		$this->assertSame( array( 'label' => 'Platform', 'status' => 'active' ), ( new Autoload() )->owner( 'siteurl' ) );
	}

	public function test_autoload_top_filter(): void {
		$this->assertSame( 20, Autoload::top_count() );
		Filters\expectApplied( 'axle_runtime_doctor_autoload_top' )->once()->andReturn( 1000 );
		$this->assertSame( 100, Autoload::top_count() );
	}

	public function test_disable_turns_autoload_off_and_fires_an_action(): void {
		Functions\when( 'wp_autoload_values_to_autoload' )->justReturn( array( 'yes', 'on', 'auto-on', 'auto' ) );
		Functions\expect( 'wp_set_option_autoload_values' )->once()->with( array( 'big' => false ) );
		$this->wpdb->answers['get_results'] = array(
			array(
				array( 'option_name' => 'big', 'autoload' => 'on' ),
				array( 'option_name' => 'small', 'autoload' => 'off' ),
			),
			array( array( 'option_name' => 'big', 'autoload' => 'off' ) ),
		);
		Actions\expectDone( 'axle_runtime_doctor_autoload_changed' )->once()->with( array( 'big' ), array( 'big' => 'on' ), array( 'big' => 'off' ) );

		$done = ( new Autoload() )->disable( array( 'big', 'small', 'gone', 'big' ) );

		$this->assertSame( array( 'big' ), $done['changed'] );
		$this->assertSame( array( 'small', 'gone' ), array_keys( $done['skipped'] ) );
	}

	public function test_disable_with_nothing_to_change_fires_nothing(): void {
		Functions\when( 'wp_autoload_values_to_autoload' )->justReturn( array( 'on' ) );
		Actions\expectDone( 'axle_runtime_doctor_autoload_changed' )->never();
		$this->wpdb->answers['get_results'] = array( array() );

		$this->assertSame( array(), ( new Autoload() )->disable( array( 'gone' ) )['changed'] );
	}

	public function test_sampler_keeps_a_rolling_buffer(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Filters\expectApplied( 'axle_runtime_doctor_samples_max' )->andReturn( 3 );
		$sampler = new Sampler( new Settings() );
		for ( $i = 1; $i <= 12; $i++ ) {
			$sampler->add( array( 't' => $i ) );
		}

		// The filter is clamped to at least 10.
		$this->assertSame( range( 3, 12 ), array_column( $sampler->all(), 't' ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$sampler->clear();
		$this->assertSame( array(), $sampler->all() );
	}

	public function test_sampler_skips_admin_and_rate_zero(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		$this->options[ Settings::OPTION ] = array( 'sampling' => false );

		( new Sampler( new Settings() ) )->maybe_sample();
		$this->assertArrayNotHasKey( Sampler::OPTION, $this->options );
	}

	public function test_php_releases_are_grouped_by_branch(): void {
		$parsed = PhpReleases::parse(
			array(
				'8.3.12' => array( 'tags' => array( 'security' ) ),
				'8.3.14' => array( 'tags' => array() ),
				'8.3.13' => array( 'tags' => array( 'security' ) ),
				'8.2.26' => array( 'tags' => array( 'security' ) ),
				'junk'   => array(),
			)
		);

		$this->assertSame( '8.3.14', $parsed['branches']['8.3']['latest'] );
		$this->assertSame( array( '8.3.12', '8.3.13' ), $parsed['branches']['8.3']['security'] );
		$this->assertSame( '8.2.26', $parsed['branches']['8.2']['latest'] );
	}

	public function test_php_releases_are_cached_and_errors_retried_sooner(): void {
		Functions\when( 'add_query_arg' )->justReturn( 'https://www.php.net/releases/index.php?json=1' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http', 'timeout' ) );
		$releases = new PhpReleases();

		$this->assertSame( array( 'error' => 'timeout' ), $releases->get() );
		$this->assertSame( array( 'error' => 'timeout' ), $releases->get() );
		$this->assertLessThanOrEqual( time() + 3600, $this->options[ PhpReleases::OPTION ]['expires'] );
	}
}
