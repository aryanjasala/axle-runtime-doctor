<?php
/**
 * Tests for the page cache test: header analysis, blame, the check, the scanner and the tracer.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Checks\PageCache;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\PageCache\Analyzer;
use AryanX\Axle\RuntimeDoctor\PageCache\Blame;
use AryanX\Axle\RuntimeDoctor\PageCache\Scanner;
use AryanX\Axle\RuntimeDoctor\PageCache\Tracer;
use AryanX\Axle\RuntimeDoctor\Probe;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PageCacheTest extends DoctorTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'get_theme_root' )->justReturn( '/var/www/html/wp-content/themes' );
	}

	protected function tearDown(): void {
		foreach ( array( 'active' => false, 'signals' => array(), 'previous' => null, 'label' => 'load', 'out' => '' ) as $name => $value ) {
			$property = new \ReflectionProperty( Tracer::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		unset( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'], $GLOBALS['wp_filter'] );
		parent::tearDown();
	}

	private function headers( array $headers ): array {
		return Analyzer::normalise( $headers );
	}

	public function test_a_hit_is_read_from_the_headers_of_the_big_hosts_and_cdns(): void {
		$cases = array(
			'cloudflare' => array( array( 'CF-Cache-Status' => 'HIT' ), 'Cloudflare' ),
			'kinsta'     => array( array( 'x-kinsta-cache' => 'HIT' ), 'Kinsta cache' ),
			'fastly'     => array( array( 'x-cache' => 'MISS, HIT' ), 'A CDN or proxy cache (x-cache)' ),
			'cloudfront' => array( array( 'X-Cache' => 'Hit from cloudfront' ), 'A CDN or proxy cache (x-cache)' ),
			'litespeed'  => array( array( 'x-litespeed-cache' => 'hit' ), 'LiteSpeed cache' ),
			'varnish'    => array( array( 'x-varnish' => '32770 32771' ), 'Varnish' ),
			'wpengine'   => array( array( 'x-cache' => 'HIT: 3' ), 'A CDN or proxy cache (x-cache)' ),
		);
		foreach ( $cases as $name => $case ) {
			$answer = Analyzer::answer( $this->headers( $case[0] ) );
			$this->assertSame( Analyzer::HIT, $answer['state'], $name );
			$this->assertSame( array( $case[1] ), $answer['by'], $name );
		}

		$age = Analyzer::answer( $this->headers( array( 'Age' => '12' ) ) );
		$this->assertSame( Analyzer::HIT, $age['state'] );
	}

	public function test_a_miss_and_no_cache_are_told_apart(): void {
		$this->assertSame( Analyzer::MISS, Analyzer::answer( $this->headers( array( 'x-cache-status' => 'BYPASS' ) ) )['state'] );
		$this->assertSame( Analyzer::MISS, Analyzer::answer( $this->headers( array( 'x-varnish' => '32770' ) ) )['state'] );
		$this->assertSame( Analyzer::MISS, Analyzer::answer( $this->headers( array( 'x-cache' => 'HIT, MISS' ) ) )['state'] );
		$this->assertSame( Analyzer::MISS, Analyzer::answer( $this->headers( array( 'x-cache-group' => 'bypass' ) ) )['state'] );
		$this->assertSame( Analyzer::NONE, Analyzer::answer( $this->headers( array( 'server' => 'nginx', 'x-cache-group' => 'normal' ) ) )['state'] );
		$this->assertSame( Analyzer::NONE, Analyzer::answer( $this->headers( array() ) )['state'] );

		$dynamic = Analyzer::answer( $this->headers( array( 'cf-cache-status' => 'DYNAMIC' ) ) );
		$this->assertSame( Analyzer::MISS, $dynamic['state'] );
		$this->assertTrue( $dynamic['dynamic'] );
		$this->assertSame( array( 'cf-cache-status: DYNAMIC' ), $dynamic['signals'] );
	}

	public function test_the_cache_header_list_has_a_filter(): void {
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_headers' )->once()->andReturnUsing(
			static function ( $known ) {
				$known['X-My-Cache'] = 'My edge';
				return $known;
			}
		);
		$answer = Analyzer::answer( $this->headers( array( 'x-my-cache' => 'HIT' ) ) );

		$this->assertSame( array( 'My edge' ), $answer['by'] );
	}

	public function test_cookie_names_are_kept_and_their_values_are_not(): void {
		$headers = $this->headers( array( 'Set-Cookie' => array( 'wp_woocommerce_session_abc=1234%7Csecret; path=/; HttpOnly', 'PHPSESSID=deadbeef; path=/' ) ) );

		$this->assertSame( array( 'wp_woocommerce_session_abc', 'PHPSESSID' ), Analyzer::cookie_names( $headers ) );
	}

	public function test_what_stops_a_cache_is_found_in_the_headers(): void {
		$blockers = Analyzer::blockers(
			$this->headers(
				array(
					'Set-Cookie'    => array( 'PHPSESSID=x; path=/', 'tracker=1' ),
					'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
					'Vary'          => 'Accept-Encoding, Cookie',
					'Expires'       => 'Thu, 19 Nov 1981 08:52:00 GMT',
				)
			)
		);
		$types = array_column( $blockers, 'type' );

		$this->assertSame( array( 'session', 'cookie', 'cache-control', 'vary' ), $types );
		$this->assertSame( array( 'PHPSESSID' ), $blockers[0]['names'] );
		$this->assertSame( array( 'tracker' ), $blockers[1]['names'] );
		$this->assertSame( array( 'no-store', 'no-cache', 'max-age=0' ), $blockers[2]['names'] );
		$this->assertStringContainsString( 'Vary: Cookie', $blockers[3]['detail'] );
		foreach ( $blockers as $blocker ) {
			$this->assertSame( 'blocks', $blocker['severity'] );
		}
	}

	public function test_vary_star_private_and_session_headers_without_a_cookie(): void {
		$star = Analyzer::blockers( $this->headers( array( 'Vary' => '*' ) ) );
		$this->assertSame( array( '*' ), $star[0]['names'] );

		$private = Analyzer::blockers( $this->headers( array( 'Cache-Control' => 'private, max-age=60' ) ) );
		$this->assertSame( array( 'private' ), $private[0]['names'] );

		$session = Analyzer::blockers( $this->headers( array( 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT' ) ) );
		$this->assertSame( 'session', $session[0]['type'] );
		$this->assertSame( array( 'session_start()' ), $session[0]['names'] );
	}

	public function test_an_old_expires_alone_is_only_a_hint_and_clean_headers_have_no_blockers(): void {
		$hint = Analyzer::blockers( $this->headers( array( 'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT' ) ) );
		$this->assertSame( 'hint', $hint[0]['severity'] );

		$this->assertSame( array(), Analyzer::blockers( $this->headers( array( 'Cache-Control' => 'public, max-age=300', 'Vary' => 'Accept-Encoding' ) ) ) );
	}

	public function test_a_nocache_call_is_named_for_sure_and_a_window_is_not(): void {
		$sure = Blame::describe(
			array(
				'type'     => 'nocache_headers',
				'suspects' => array( 'plugin:bad-cache' ),
				'certain'  => true,
			)
		);
		$this->assertSame( 'nocache_headers() was called by plugin bad-cache. This is seen, not guessed.', $sure );

		$one = Blame::describe(
			array(
				'type'     => 'cookie',
				'name'     => 'my_cookie',
				'where'    => 'init',
				'suspects' => array( 'plugin:only-one' ),
			)
		);
		$this->assertStringContainsString( 'only plugin only-one has callbacks on it. It is probably that one', $one );
		$this->assertStringContainsString( 'did not see it', $one );

		$many = Blame::describe(
			array(
				'type'     => 'cookie',
				'name'     => 'my_cookie',
				'where'    => 'init',
				'suspects' => array( 'plugin:a', 'theme:b' ),
			)
		);
		$this->assertStringContainsString( 'one of these, and the trace cannot tell which: plugin a, theme b', $many );

		$none = Blame::describe(
			array(
				'type'  => 'donotcachepage',
				'name'  => 'DONOTCACHEPAGE',
				'where' => '',
			)
		);
		$this->assertStringContainsString( 'cannot say which plugin', $none );

		$load = Blame::describe(
			array(
				'type'  => 'session',
				'name'  => 'session_start()',
				'where' => 'load',
			)
		);
		$this->assertStringContainsString( 'while the plugin and theme files were loading', $load );
	}

	public function test_what_one_session_does_is_one_sentence_not_five(): void {
		$window = array(
			'where'    => 'template_redirect',
			'suspects' => array( 'plugin:sessions' ),
			'certain'  => false,
		);
		$lines  = Blame::describe_all(
			array(
				$window + array( 'type' => 'cookie', 'name' => 'PHPSESSID' ),
				$window + array( 'type' => 'header', 'name' => 'cache-control' ),
				$window + array( 'type' => 'session', 'name' => 'session_start()' ),
				array( 'type' => 'nocache_headers', 'name' => 'nocache_headers()', 'where' => 'init', 'suspects' => array( 'plugin:bad' ), 'certain' => true ),
				array( 'type' => 'cookie', 'name' => 'other', 'where' => 'init', 'suspects' => array( 'plugin:a', 'plugin:b' ) ),
			)
		);

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( 'nocache_headers() was called by plugin bad.', $lines[0] );
		$this->assertStringStartsWith( 'Seen: the cookie PHPSESSID, the cache-control header, a PHP session. It appeared during the template_redirect hook, and only plugin sessions', $lines[1] );
		$this->assertStringContainsString( 'The cookie PHPSESSID points at: PHP session_start().', $lines[1] );
		$this->assertStringStartsWith( 'Seen: the cookie other. It appeared during the init hook. It is one of these', $lines[2] );
	}

	public function test_cookie_names_that_give_a_plugin_away(): void {
		$this->assertSame( 'plugin woocommerce', Blame::hint( 'wp_woocommerce_session_abc123' ) );
		$this->assertSame( 'PHP session_start()', Blame::hint( 'PHPSESSID' ) );
		$this->assertSame( '', Blame::hint( 'something_unknown' ) );

		$text = Blame::describe(
			array(
				'type'     => 'cookie',
				'name'     => 'wp_woocommerce_session_abc',
				'where'    => 'init',
				'suspects' => array(),
			)
		);
		$this->assertStringContainsString( 'The name of the cookie points at: plugin woocommerce.', $text );
		$this->assertStringStartsWith( 'Seen: the cookie wp_woocommerce_session_abc.', $text );
	}

	public function test_the_cookie_hint_list_has_a_filter_and_drops_bad_patterns(): void {
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_cookie_hints' )->once()->andReturn(
			array(
				'/^mine_/' => 'plugin mine',
				'not a regex' => 'broken',
			)
		);

		$this->assertSame( array( '/^mine_/' => 'plugin mine' ), Blame::cookie_hints() );
	}

	private function page( array $page ): array {
		return $page + array(
			'url'      => 'https://example.com/',
			'status'   => 200,
			'via'      => 'public',
			'cold_ms'  => 300,
			'warm_ms'  => 40,
			'cold'     => Analyzer::MISS,
			'answered' => Analyzer::HIT,
			'by'       => array( 'Cloudflare' ),
			'signals'  => array( 'cf-cache-status: HIT' ),
			'dynamic'  => false,
			'blockers' => array(),
			'cookies'  => array(),
			'trace'    => null,
			'error'    => '',
		);
	}

	private function blocked( array $page = array() ): array {
		return $this->page(
			$page + array(
				'answered' => Analyzer::MISS,
				'signals'  => array( 'cf-cache-status: BYPASS' ),
				'by'       => array( 'Cloudflare' ),
				'cookies'  => array( 'wp_woocommerce_session_abc' ),
				'blockers' => array(
					array(
						'type'     => 'cookie',
						'severity' => 'blocks',
						'names'    => array( 'wp_woocommerce_session_abc' ),
						'detail'   => 'The page sets a cookie for a visitor who is not logged in: wp_woocommerce_session_abc.',
					),
				),
			)
		);
	}

	public function test_the_check_does_not_apply_when_the_test_is_off(): void {
		$this->assertNull( ( new PageCache() )->run( $this->context( array( 'page_cache' => null ) ) ) );
	}

	public function test_every_page_answered_from_a_cache_is_good(): void {
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $this->page( array() ), $this->page( array( 'url' => 'https://example.com/a/' ) ) ) ) ) ) );

		$this->assertSame( 'good', $result->status );
		$this->assertSame( '2 of 2 pages answered from a cache', $result->value );
	}

	public function test_a_cache_that_every_page_skips_is_critical_and_says_why(): void {
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $this->blocked(), $this->blocked( array( 'url' => 'https://example.com/a/' ) ) ) ) ) ) );

		$this->assertSame( 'critical', $result->status );
		$this->assertSame( '0 of 2 pages answered from a cache', $result->value );
		$this->assertStringContainsString( '/: The page sets a cookie', $result->details[0] );
		$this->assertStringContainsString( 'The cookie wp_woocommerce_session_abc points at: plugin woocommerce. This comes from its name, not from a trace.', implode( "\n", $result->details ) );
		$this->assertSame( 'cookie: wp_woocommerce_session_abc', $result->data['found'] );
		$this->assertSame( 2, $result->data['blocked'] );
		$this->assertFalse( $result->inferred );
	}

	public function test_a_blocker_with_no_cache_in_sight_is_recommended_and_inferred(): void {
		$page   = $this->blocked(
			array(
				'answered' => Analyzer::NONE,
				'signals'  => array(),
				'by'       => array(),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $page ) ) ) ) );

		$this->assertSame( 'recommended', $result->status );
		$this->assertTrue( $result->inferred );
		$this->assertSame( 'no', $result->data['cache_seen'] );
	}

	public function test_some_pages_cached_and_some_blocked_is_recommended(): void {
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $this->page( array() ), $this->blocked( array( 'url' => 'https://example.com/shop/' ) ) ) ) ) ) );

		$this->assertSame( 'recommended', $result->status );
		$this->assertSame( 'Some pages skip the page cache', $result->summary );
		$this->assertSame( '1 of 2 pages answered from a cache', $result->value );
	}

	public function test_a_hit_with_a_blocker_is_still_a_hit_and_says_so(): void {
		$page   = $this->page(
			array(
				'blockers' => array(
					array(
						'type'     => 'cookie',
						'severity' => 'blocks',
						'names'    => array( 'x' ),
						'detail'   => 'The page sets a cookie.',
					),
				),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $page ) ) ) ) );

		$this->assertSame( 'good', $result->status );
		$this->assertStringContainsString( 'A cache still answered, so it is not stopping it here.', $result->details[0] );
	}

	public function test_a_cache_that_misses_without_a_visible_cause_and_no_cache_at_all(): void {
		$miss   = $this->page(
			array(
				'answered' => Analyzer::MISS,
				'dynamic'  => true,
				'signals'  => array( 'cf-cache-status: DYNAMIC' ),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $miss ) ) ) ) );
		$this->assertSame( 'recommended', $result->status );
		$this->assertStringContainsString( 'Cloudflare answered DYNAMIC', $result->details[0] );

		$none   = $this->page(
			array(
				'answered' => Analyzer::NONE,
				'signals'  => array(),
				'by'       => array(),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $none ) ) ) ) );
		$this->assertSame( 'info', $result->status );
		$this->assertSame( 'No page cache was detected', $result->summary );
		$this->assertTrue( $result->inferred );
	}

	public function test_pages_that_could_not_be_loaded_are_listed_and_never_counted(): void {
		$failed = $this->page(
			array(
				'status' => 0,
				'error'  => 'cURL error 28: timed out',
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $failed ) ) ) ) );
		$this->assertSame( 'info', $result->status );
		$this->assertSame( array( 'Could not test /: cURL error 28: timed out' ), $result->details );

		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $this->page( array() ), $failed ) ) ) ) );
		$this->assertSame( 'good', $result->status );
		$this->assertSame( '1 of 1 pages answered from a cache', $result->value );
	}

	public function test_a_trace_names_the_plugin_only_when_it_saw_it_or_one_plugin_could_have(): void {
		$page   = $this->blocked(
			array(
				'trace' => array(
					'ran'     => true,
					'reached' => true,
					'signals' => array(
						array(
							'type'     => 'nocache_headers',
							'name'     => 'nocache_headers()',
							'where'    => 'template_redirect:start',
							'suspects' => array( 'plugin:bad-cache' ),
							'certain'  => true,
						),
						array(
							'type'     => 'cookie',
							'name'     => 'other',
							'where'    => 'init',
							'suspects' => array( 'plugin:a', 'plugin:b' ),
							'certain'  => false,
						),
					),
				),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $page ) ) ) ) );

		$this->assertSame( 'plugin:bad-cache', $result->data['culprits'] );
		$this->assertStringContainsString( 'nocache_headers() was called by plugin bad-cache', implode( "\n", $result->details ) );
		$this->assertStringContainsString( 'It is one of these', implode( "\n", $result->details ) );
	}

	public function test_a_trace_that_did_not_reach_php_says_so_instead_of_guessing(): void {
		$page   = $this->blocked(
			array(
				'trace' => array(
					'ran'     => true,
					'reached' => false,
					'signals' => array(),
				),
			)
		);
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $page ) ) ) ) );

		$this->assertStringContainsString( 'The tracing request did not reach PHP', implode( "\n", $result->details ) );
		$this->assertSame( '', $result->data['culprits'] );
	}

	public function test_the_result_for_a_stored_scan_needs_no_request(): void {
		$result = PageCache::from_scan( array( 'pages' => array( $this->page( array() ) ) ) );

		$this->assertSame( 'good', $result->status );
		$this->assertSame( 'page_cache', $result->id );
		$this->assertSame( 'page_cache', $result->group );
	}

	public function test_the_support_ticket_has_what_the_pages_send(): void {
		$result = ( new PageCache() )->run( $this->context( array( 'page_cache' => array( 'pages' => array( $this->blocked() ) ) ) ) );
		$fixes  = new Fixes();
		$ticket = $fixes->ticket( $result->to_array(), array( 'id' => 'kinsta', 'label' => 'Kinsta', 'server' => 'nginx' ) );

		$this->assertStringContainsString( 'What the pages send: cookie: wp_woocommerce_session_abc', $ticket );
		$this->assertStringContainsString( 'Could you tell me why the page cache is skipped', $ticket );

		$fix = $fixes->get( 'page_cache', array( 'id' => 'kinsta', 'server' => 'nginx' ) );
		$this->assertSame( 'kinsta', $fix['host'] );
		$this->assertSame( 'generic', $fixes->get( 'page_cache', array( 'id' => 'unknown', 'server' => '' ) )['host'] );
		$this->assertSame( 'nginx', $fixes->get( 'page_cache', array( 'id' => 'unknown', 'server' => 'nginx' ) )['host'] );
	}

	public function test_every_fix_for_the_page_cache_has_setting_where_and_steps(): void {
		$data = ( new Fixes() )->data()['page_cache'];

		$this->assertNotEmpty( $data['ask'] );
		foreach ( $data as $key => $fix ) {
			if ( is_array( $fix ) ) {
				$this->assertNotEmpty( $fix['setting'], $key );
				$this->assertNotEmpty( $fix['where'], $key );
				$this->assertNotEmpty( $fix['steps'], $key );
				$this->assertStringNotContainsString( '—', wp_json_encode( $fix ), $key );
			}
		}
	}

	public function test_the_pages_to_test_are_only_on_this_site_and_capped(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'esc_url_raw' )->returnArg();
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_urls' )->once()->andReturnUsing(
			static function ( $urls ) {
				return array_merge( $urls, array( 'https://evil.example/', 'http://169.254.169.254/latest', 'https://example.com/a/', 'https://example.com/a/', 'https://EXAMPLE.com/b/', 'https://example.com/c/', 'https://example.com/d/', 'https://example.com/e/', 'https://example.com/f/' ) );
			}
		);

		$urls = Scanner::urls();

		$this->assertCount( 6, $urls );
		$this->assertSame( array( 'https://example.com/', 'https://example.com/a/', 'https://EXAMPLE.com/b/', 'https://example.com/c/', 'https://example.com/d/', 'https://example.com/e/' ), $urls );
	}

	public function test_the_limits_have_filters_and_are_clamped(): void {
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_timeout' )->once()->andReturn( 500 );
		$this->assertSame( 30, Scanner::timeout() );

		Filters\expectApplied( 'axle_runtime_doctor_page_cache_trace_max' )->once()->andReturn( -3 );
		$this->assertSame( 0, Scanner::trace_limit() );
	}

	public function test_the_time_budget_has_a_filter_and_a_page_it_skipped_says_so(): void {
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_budget' )->once()->andReturn( -50 );
		$this->assertSame( 5, Scanner::budget() );
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_budget' )->once()->andReturn( 9000 );
		$this->assertSame( 120, Scanner::budget() );

		$method = new \ReflectionMethod( Scanner::class, 'skipped' );
		$method->setAccessible( true );
		$skipped = $method->invoke( null, 'https://example.com/a/' );

		$this->assertSame( 'Skipped: the test ran out of time before it got to this page.', $skipped['error'] );
		$result = PageCache::from_scan( array( 'pages' => array( $skipped ) ) );
		$this->assertSame( 'info', $result->status );
		$this->assertStringContainsString( 'Could not test /a/: Skipped', $result->details[0] );
	}

	public function test_the_test_can_be_switched_off_with_a_filter(): void {
		Filters\expectApplied( 'axle_runtime_doctor_page_cache_enabled' )->once()->andReturn( false );

		$this->assertNull( Scanner::scan() );
		$this->assertArrayNotHasKey( Scanner::OPTION, $this->options );
	}

	private function response( array $headers, int $code = 200 ): array {
		return array(
			'code'    => $code,
			'headers' => $headers,
		);
	}

	private function http( array $queue ): void {
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$queue ) {
				$next = array_shift( $queue );
				return is_callable( $next ) ? $next( $url, $args ) : $next;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( $r ) => $r['headers'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $name ) => $r['headers'][ $name ] ?? '' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
			}
		);
	}

	public function test_a_page_is_asked_twice_with_no_cookies_and_read_from_the_second_answer(): void {
		$seen = array();
		$spy  = function ( $headers ) use ( &$seen ) {
			return function ( $url, $args ) use ( $headers, &$seen ) {
				$seen[] = array( $url, $args );
				return $this->response( $headers );
			};
		};
		$this->http(
			array(
				$spy( array( 'cf-cache-status' => 'MISS' ) ),
				$spy( array( 'cf-cache-status' => 'HIT', 'age' => '3' ) ),
			)
		);

		$page = Scanner::test( 'https://example.com/post/' );

		$this->assertSame( 'https://example.com/post/', $seen[0][0] );
		$this->assertSame( array(), $seen[0][1]['cookies'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $seen[0][1]['headers'] );
		$this->assertSame( 0, $seen[0][1]['redirection'] );
		$this->assertSame( 'public', $page['via'] );
		$this->assertSame( Analyzer::MISS, $page['cold'] );
		$this->assertSame( Analyzer::HIT, $page['answered'] );
		$this->assertSame( array(), $page['blockers'] );
	}

	public function test_a_server_that_cannot_reach_its_public_address_falls_back_to_the_loopback_with_the_site_host(): void {
		Filters\expectApplied( 'axle_runtime_doctor_loopback_url' )->andReturn( 'http://wp' );
		$seen = array();
		$ok   = function ( $url, $args ) use ( &$seen ) {
			$seen[] = array( $url, $args );
			return $this->response( array( 'set-cookie' => 'a=1; path=/' ) );
		};
		$this->http( array( new \WP_Error( 'http', 'connection refused' ), $ok, $ok ) );

		$page = Scanner::test( 'https://example.com/' );

		$this->assertSame( 'loopback', $page['via'] );
		$this->assertSame( 'http://wp/', $seen[0][0] );
		$this->assertSame( 'example.com', $seen[0][1]['headers']['Host'] );
		$this->assertSame( array( 'a' ), $page['cookies'] );
		$this->assertSame( 'cookie', $page['blockers'][0]['type'] );
	}

	public function test_a_page_that_fails_or_redirects_is_recorded_not_analysed(): void {
		$this->http( array( new \WP_Error( 'http', 'refused' ) ) );
		$page = Scanner::test( 'https://example.com/', false );
		$this->assertSame( 'refused', $page['error'] );
		$this->assertSame( 'loopback', $page['via'] );

		$this->http( array( new \WP_Error( 'http', 'refused' ), new \WP_Error( 'http', 'refused again' ) ) );
		$page = Scanner::test( 'https://example.com/' );
		$this->assertSame( 'refused again', $page['error'] );
		$this->assertSame( array(), $page['blockers'] );

		$this->http( array( $this->response( array(), 200 ), $this->response( array( 'location' => 'https://example.com/en/' ), 301 ) ) );
		$page = Scanner::test( 'https://example.com/' );
		$this->assertSame( 'The page redirects to https://example.com/en/. Use the final address.', $page['error'] );
	}

	public function test_a_scan_traces_pages_that_have_a_blocker_and_keeps_the_result(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_generate_password' )->justReturn( 'tracetoken' );
		$this->http(
			array(
				$this->response( array( 'set-cookie' => 'x=1' ) ),
				$this->response( array( 'set-cookie' => 'x=1' ) ),
				function ( $url, $args ) {
					// The page ran with a token and saved what it saw.
					$token                                                    = $args['headers'][ Probe::HEADER ];
					$this->options[ Probe::OUT . Probe::hash( $token ) ] = array(
						'signals' => array(
							array(
								'type'     => 'cookie',
								'name'     => 'x',
								'where'    => 'init',
								'suspects' => array( 'plugin:p' ),
								'certain'  => false,
							),
						),
					);
					return $this->response( array() );
				},
			)
		);
		Actions\expectDone( 'axle_runtime_doctor_page_cache_started' )->once();
		Actions\expectDone( 'axle_runtime_doctor_page_cache_finished' )->once();

		$scan = Scanner::scan();

		$this->assertCount( 1, $scan['pages'] );
		$this->assertTrue( $scan['pages'][0]['trace']['reached'] );
		$this->assertSame( 'plugin:p', $scan['pages'][0]['trace']['signals'][0]['suspects'][0] );
		$this->assertSame( $scan, Scanner::latest() );
		$this->assertArrayNotHasKey( Probe::OUT . Probe::hash( 'tracetoken' ), $this->options, 'The trace output is deleted after it is read.' );
		$this->assertArrayNotHasKey( Probe::PREFIX . Probe::hash( 'tracetoken' ), $this->options, 'The token is deleted after use.' );
	}

	public function test_rows_are_flat_for_wp_cli_and_abilities(): void {
		$rows = Scanner::rows( array( 'pages' => array( $this->blocked() ) ) );

		$this->assertSame( 'https://example.com/', $rows[0]['url'] );
		$this->assertSame( 'miss', $rows[0]['answered'] );
		$this->assertSame( 'cookie (wp_woocommerce_session_abc)', $rows[0]['stops'] );
		$this->assertSame( 40, $rows[0]['second_ms'] );
	}

	public function test_tracing_only_starts_with_a_valid_token_of_its_own_mode(): void {
		$passwords = array( 'tok-queries', 'tok-trace' );
		Functions\when( 'wp_generate_password' )->alias(
			static function () use ( &$passwords ) {
				return array_shift( $passwords );
			}
		);
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		Tracer::maybe_start();
		$this->assertFalse( Tracer::active(), 'No header, no tracing.' );

		$_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] = Probe::issue( 'queries' );
		Tracer::maybe_start();
		$this->assertFalse( Tracer::active(), 'A token for another mode does not start it.' );

		$_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] = Probe::issue( 'cache-trace' );
		Tracer::maybe_start();
		$this->assertTrue( Tracer::active() );
		$this->assertNull( Probe::consume( 'tok-trace' ), 'The token is used up.' );
	}

	public function test_a_change_in_the_response_is_seen_between_two_snapshots(): void {
		$before = array(
			'cookies'    => array( 'a' ),
			'headers'    => array( 'cache-control' => 'max-age=300' ),
			'donotcache' => false,
			'session'    => false,
		);
		$after  = array(
			'cookies'    => array( 'a', 'b' ),
			'headers'    => array( 'cache-control' => 'no-store' ),
			'donotcache' => true,
			'session'    => true,
		);

		$this->assertSame(
			array(
				array( 'type' => 'cookie', 'name' => 'b' ),
				array( 'type' => 'header', 'name' => 'cache-control' ),
				array( 'type' => 'donotcachepage', 'name' => 'DONOTCACHEPAGE' ),
				array( 'type' => 'session', 'name' => 'session_start()' ),
			),
			Tracer::changes( $before, $after )
		);
		$this->assertSame( array(), Tracer::changes( $after, $after ) );
		$this->assertCount( 5, Tracer::changes( null, $after + array( 'cookies' => array( 'a', 'b', 'c' ) ) ), 'Against the start of the request everything counts as new.' );
	}

	public function test_the_components_with_callbacks_on_a_hook_exclude_core_and_this_plugin(): void {
		$GLOBALS['wp_filter'] = array(
			'init' => (object) array(
				'callbacks' => array(
					10 => array(
						array( 'function' => 'strlen' ),
						array( 'function' => static function () {} ),
					),
				),
			),
		);

		$this->assertSame( array(), Tracer::hook_components( 'init' ), 'Core functions and the test file have no plugin.' );
		$this->assertSame( array(), Tracer::hook_components( 'nothing_here' ) );
		$this->assertSame( '', Tracer::file_of( 'no_such_function_anywhere' ) );
		$this->assertSame( __FILE__, Tracer::file_of( array( $this, 'test_a_change_in_the_response_is_seen_between_two_snapshots' ) ) );
		$this->assertSame( __FILE__, Tracer::file_of( static function () {} ) );
	}
}
