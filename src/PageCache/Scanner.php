<?php
/**
 * Asks the key pages twice, as a visitor, and records what answered.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\PageCache;

use AryanX\Axle\RuntimeDoctor\Probe;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * For each key page: one request, then a second right after it, with no
 * cookies and no cache-busting header, so a cache sees an ordinary visitor.
 * The second answer says whether a cache holds the page. If something on the
 * page would stop a cache, one more request with a token and a cache-busting
 * query lets the page trace who did it (see Tracer).
 *
 * Requests go to the public address first, so a CDN in front of the server is
 * part of the test. A server that cannot reach its own public address falls
 * back to the loopback address, and the result says the CDN was not tested.
 */
final class Scanner {

	public const OPTION = 'axle_runtime_doctor_page_cache';
	public const BUSTER = 'axle-rd-trace';

	/**
	 * The pages to test: home, the latest post and a page.
	 *
	 * @return string[]
	 */
	public static function urls(): array {
		$urls   = array( home_url( '/' ) );
		$latest = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'post',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		if ( $latest ) {
			$urls[] = (string) get_permalink( (int) $latest[0] );
		}
		$front = (int) get_option( 'page_on_front' );
		$page  = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'page',
				'fields'           => 'ids',
				'exclude'          => $front ? array( $front ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- One post, to skip the front page.
				'suppress_filters' => false,
			)
		);
		if ( $page ) {
			$urls[] = (string) get_permalink( (int) $page[0] );
		}

		/**
		 * Filters the pages the page cache test loads. At most 6 are used, and
		 * only addresses on this site: anything else is dropped.
		 *
		 * @param string[] $urls Home, the latest post, a page.
		 */
		$filtered = array_filter( (array) apply_filters( 'axle_runtime_doctor_page_cache_urls', $urls ), 'is_scalar' );
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$clean    = array();
		foreach ( $filtered as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' !== $url && 0 === strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) {
				$clean[] = $url;
			}
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 6 );
	}

	/**
	 * Seconds each request may take.
	 *
	 * @return int
	 */
	public static function timeout(): int {
		/**
		 * Filters how long each request of the page cache test may take.
		 *
		 * @param int $seconds Default 10. 3 to 30.
		 */
		return max( 3, min( 30, (int) apply_filters( 'axle_runtime_doctor_page_cache_timeout', 10 ) ) );
	}

	/**
	 * Seconds the whole test may take. It stops starting new pages after that.
	 *
	 * @return int
	 */
	public static function budget(): int {
		/**
		 * Filters how long the whole page cache test may run before it skips the
		 * pages it has not started. A page can still take its own timeout on top.
		 * The checks run inside one web request, which PHP stops after
		 * max_execution_time.
		 *
		 * @param int $seconds Default 20. 5 to 120.
		 */
		return max( 5, min( 120, (int) apply_filters( 'axle_runtime_doctor_page_cache_budget', 20 ) ) );
	}

	/**
	 * How many pages get the extra tracing request.
	 *
	 * @return int
	 */
	public static function trace_limit(): int {
		/**
		 * Filters how many pages get a tracing request when something on them
		 * would stop a cache. Tracing names the plugin or theme when it can.
		 *
		 * @param int $max Default 2. 0 to 5. 0 turns tracing off.
		 */
		return max( 0, min( 5, (int) apply_filters( 'axle_runtime_doctor_page_cache_trace_max', 2 ) ) );
	}

	/**
	 * Whether the test is on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether the page cache test runs, in the daily run and on the screen.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'axle_runtime_doctor_page_cache_enabled', true );
	}

	/**
	 * The latest scan.
	 *
	 * @return array|null
	 */
	public static function latest(): ?array {
		$scan = get_option( self::OPTION );

		return is_array( $scan ) && isset( $scan['pages'] ) ? $scan : null;
	}

	/**
	 * Runs the test and keeps the result.
	 *
	 * @return array|null time, pages, traced. Null when the test is off.
	 */
	public static function scan(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}

		$urls = self::urls();

		/**
		 * Fires before the page cache test loads the pages.
		 *
		 * @param string[] $urls The pages.
		 */
		do_action( 'axle_runtime_doctor_page_cache_started', $urls );

		$use_public = true;
		$traces     = self::trace_limit();
		$pages      = array();
		$started    = microtime( true );
		$budget     = self::budget();
		foreach ( $urls as $url ) {
			if ( microtime( true ) - $started > $budget ) {
				$pages[] = self::skipped( $url );
				continue;
			}
			$page = self::test( $url, $use_public );
			if ( 'loopback' === $page['via'] ) {
				$use_public = false;
			}
			if ( $traces > 0 && microtime( true ) - $started <= $budget && self::worth_tracing( $page ) ) {
				$page['trace']    = self::trace( $url, 'loopback' === $page['via'] );
				$page['blockers'] = array_merge( (array) $page['blockers'], self::trace_blockers( $page['trace'] ) );
				--$traces;
			}
			$pages[] = $page;
		}

		$scan = array(
			'time'  => time(),
			'pages' => $pages,
		);
		update_option( self::OPTION, $scan, false );

		/**
		 * Fires after the page cache test, with what it found.
		 *
		 * @param array $scan time and pages: url, status, via, cold, answered, by, signals, blockers, cookies, trace, error.
		 */
		do_action( 'axle_runtime_doctor_page_cache_finished', $scan );

		return $scan;
	}

	/**
	 * A scan as flat rows, for WP-CLI and abilities.
	 *
	 * @param array $scan A scan.
	 * @return array[] url, status, answered, by, first_ms, second_ms, stops, via, error.
	 */
	public static function rows( array $scan ): array {
		$rows = array();
		foreach ( (array) ( $scan['pages'] ?? array() ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$stops = array();
			foreach ( (array) ( $page['blockers'] ?? array() ) as $blocker ) {
				$stops[] = (string) ( $blocker['type'] ?? '' ) . ( ! empty( $blocker['names'] ) ? ' (' . implode( ', ', array_map( 'strval', (array) $blocker['names'] ) ) . ')' : '' );
			}
			$rows[] = array(
				'url'       => (string) ( $page['url'] ?? '' ),
				'status'    => (int) ( $page['status'] ?? 0 ),
				'answered'  => (string) ( $page['answered'] ?? Analyzer::NONE ),
				'by'        => implode( ', ', array_map( 'strval', (array) ( $page['by'] ?? array() ) ) ),
				'first_ms'  => (int) ( $page['cold_ms'] ?? 0 ),
				'second_ms' => (int) ( $page['warm_ms'] ?? 0 ),
				'stops'     => implode( '; ', $stops ),
				'via'       => (string) ( $page['via'] ?? '' ),
				'error'     => (string) ( $page['error'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Whether a trace is worth a request: the page was not answered from a cache.
	 *
	 * @param array $page One tested page.
	 * @return bool
	 */
	private static function worth_tracing( array $page ): bool {
		return '' === (string) $page['error'] && Analyzer::HIT !== $page['answered'];
	}

	/**
	 * What a trace saw that the headers do not show: a nocache_headers() call
	 * from a plugin or theme, and the DONOTCACHEPAGE constant. Cache plugins read
	 * the constant and skip the page without sending any header about it.
	 *
	 * @param array $trace The trace of a page.
	 * @return array[] Blockers.
	 */
	private static function trace_blockers( array $trace ): array {
		$found = array();
		foreach ( (array) ( $trace['signals'] ?? array() ) as $signal ) {
			if ( ! is_array( $signal ) ) {
				continue;
			}
			if ( 'donotcachepage' === ( $signal['type'] ?? '' ) ) {
				$found['donotcachepage'] = array(
					'type'     => 'nocache',
					'severity' => 'blocks',
					'names'    => array( 'DONOTCACHEPAGE' ),
					'detail'   => __( 'The constant DONOTCACHEPAGE is defined on the page. Cache plugins such as WP Super Cache, W3 Total Cache and WP Rocket skip a page that defines it, and send no header about it.', 'axle-runtime-doctor' ),
				);
			} elseif ( 'nocache_headers' === ( $signal['type'] ?? '' ) && ! empty( $signal['certain'] ) ) {
				$found['nocache_headers'] = array(
					'type'     => 'nocache',
					'severity' => 'blocks',
					'names'    => array( 'nocache_headers()' ),
					'detail'   => __( 'A plugin or theme calls nocache_headers() on the page, which tells every cache not to keep it.', 'axle-runtime-doctor' ),
				);
			}
		}

		return array_values( $found );
	}

	/**
	 * A page the test did not get to.
	 *
	 * @param string $url The page.
	 * @return array
	 */
	private static function skipped( string $url ): array {
		return array(
			'url'      => $url,
			'status'   => 0,
			'via'      => 'public',
			'cold_ms'  => 0,
			'warm_ms'  => 0,
			'cold'     => Analyzer::NONE,
			'answered' => Analyzer::NONE,
			'by'       => array(),
			'signals'  => array(),
			'dynamic'  => false,
			'blockers' => array(),
			'cookies'  => array(),
			'trace'    => null,
			'error'    => __( 'Skipped: the test ran out of time before it got to this page.', 'axle-runtime-doctor' ),
		);
	}

	/**
	 * Tests one page: a first and a second request.
	 *
	 * @param string $url    The page.
	 * @param bool   $use_public Whether to try the public address first.
	 * @return array
	 */
	public static function test( string $url, bool $use_public = true ): array {
		$page          = self::skipped( $url );
		$page['error'] = '';

		$loopback = ! $use_public || ! self::use_public();
		$first    = self::fetch( $url, $loopback );
		if ( is_wp_error( $first ) && ! $loopback ) {
			$loopback = true;
			$first    = self::fetch( $url, true );
		}
		$page['via'] = $loopback ? 'loopback' : 'public';
		if ( is_wp_error( $first ) ) {
			$page['error'] = $first->get_error_message();

			return $page;
		}

		$second = self::fetch( $url, $loopback );
		if ( is_wp_error( $second ) ) {
			$page['error'] = $second->get_error_message();

			return $page;
		}

		$cold = Analyzer::answer( $first['headers'] );
		$warm = Analyzer::answer( $second['headers'] );

		$page['status']   = (int) $second['status'];
		$page['cold_ms']  = (int) round( $first['ms'] );
		$page['warm_ms']  = (int) round( $second['ms'] );
		$page['cold']     = $cold['state'];
		$page['answered'] = $warm['state'];
		$page['by']       = $warm['by'] ? $warm['by'] : $cold['by'];
		$page['signals']  = $warm['signals'];
		$page['dynamic']  = $warm['dynamic'];
		$page['cookies']  = Analyzer::cookie_names( $second['headers'] );

		if ( $second['status'] >= 300 && $second['status'] < 400 ) {
			/* translators: %s: where the page redirects. */
			$page['error'] = sprintf( __( 'The page redirects to %s. Use the final address.', 'axle-runtime-doctor' ), $second['location'] );

			return $page;
		}
		$page['blockers'] = Analyzer::blockers( $second['headers'] );

		return $page;
	}

	/**
	 * Whether to try the public address.
	 *
	 * @return bool
	 */
	private static function use_public(): bool {
		/**
		 * Filters whether the page cache test calls the public address first,
		 * which is the only way to test a CDN in front of the server. When off,
		 * or when the server cannot reach its own public address, it uses the
		 * loopback address (see axle_runtime_doctor_loopback_url).
		 *
		 * @param bool $use_public Default true.
		 */
		return (bool) apply_filters( 'axle_runtime_doctor_page_cache_public', true );
	}

	/**
	 * One request, the way a visitor makes it.
	 *
	 * @param string $url      The page.
	 * @param bool   $loopback Go through the loopback address.
	 * @param array  $headers  Extra request headers.
	 * @return array|WP_Error status, headers, ms, location.
	 */
	private static function fetch( string $url, bool $loopback, array $headers = array() ) {
		$home = untrailingslashit( home_url() );
		$args = array(
			'timeout'             => self::timeout(),
			'redirection'         => 0,
			'cookies'             => array(),
			'user-agent'          => 'Mozilla/5.0 (compatible; Axle Runtime Doctor page cache test)',
			'limit_response_size' => 262144,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
			'sslverify'           => $loopback ? apply_filters( 'https_local_ssl_verify', false ) : true,
			'headers'             => $headers + array( 'Accept' => 'text/html,application/xhtml+xml' ),
		);
		if ( $loopback && Probe::base_url() !== $home ) {
			// The server answers for the site's own host name, so redirects and virtual hosts behave as they do for a visitor.
			$host = (string) wp_parse_url( $home, PHP_URL_HOST );
			$port = wp_parse_url( $home, PHP_URL_PORT );

			$args['headers']['Host'] = $host . ( $port ? ':' . $port : '' );
		}

		$start = microtime( true );
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- The test itself: it asks this site's own pages the way a visitor does.
		$response = wp_remote_get( $loopback ? Probe::loopback( $url ) : $url, $args );
		// phpcs:enable
		$ms = ( microtime( true ) - $start ) * 1000;
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_headers( $response );
		$all = is_array( $raw ) ? $raw : $raw->getAll();

		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'headers'  => Analyzer::normalise( $all ),
			'ms'       => $ms,
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		);
	}

	/**
	 * Loads a page once more with a token, so the page traces itself.
	 *
	 * @param string $url      The page.
	 * @param bool   $loopback Go through the loopback address.
	 * @return array ran (the request got an answer), reached (the trace came back), signals.
	 */
	private static function trace( string $url, bool $loopback ): array {
		$token = Probe::issue( 'cache-trace' );
		$out   = Probe::OUT . Probe::hash( $token );
		// A new query string, so a cache that keys on the whole address sends the request on to PHP.
		$response = self::fetch( add_query_arg( self::BUSTER, wp_generate_password( 8, false, false ), $url ), $loopback, array( Probe::HEADER => $token ) );
		$found    = get_option( $out );
		delete_option( $out );
		delete_option( Probe::PREFIX . Probe::hash( $token ) );

		return array(
			'ran'     => ! is_wp_error( $response ),
			'reached' => is_array( $found ),
			'signals' => is_array( $found ) ? array_slice( array_values( array_filter( (array) ( $found['signals'] ?? array() ), 'is_array' ) ), 0, 40 ) : array(),
		);
	}
}
