<?php
/**
 * The loopback probe: runs checks inside a real web request.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI and cron run in a different PHP than visitors get: OPcache is
 * usually off on the command line and the ini values differ. So they ask the
 * web server to run the checks, through a REST route that only answers a
 * single-use token, valid for 60 seconds and stored hashed.
 *
 * The same probe reads a cache key back (the object cache roundtrip),
 * reports OPcache coverage in a second request, and, on front-end pages,
 * logs the page's queries for the slow query check.
 */
final class Probe implements Service {

	public const HEADER   = 'X-Axle-Runtime-Doctor-Probe';
	public const NS       = 'axle-runtime-doctor/v1';
	public const PREFIX   = 'axle_runtime_doctor_probe_';
	public const OUT      = 'axle_runtime_doctor_probe_out_';
	private const MODES   = array( 'run', 'read', 'timing', 'ping' );
	private const SECONDS = 60;

	/**
	 * When the plugin booted in this request, for the timing probe.
	 *
	 * @var float
	 */
	public static float $booted = 0.0;

	/**
	 * What the token of this request allows.
	 *
	 * @var array|null
	 */
	private ?array $grant = null;

	/**
	 * Whether this request is a front-end query probe.
	 *
	 * @var bool
	 */
	private static bool $capturing = false;

	/**
	 * Adds the REST route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers /axle-runtime-doctor/v1/probe.
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			'/probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Lets the request in only with a valid token, and uses it up.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authorize( $request ) {
		$token = (string) $request->get_header( 'x_axle_runtime_doctor_probe' );
		$grant = '' !== $token ? self::consume( $token ) : null;
		if ( ! $grant || ! in_array( $grant['mode'], self::MODES, true ) ) {
			return new WP_Error( 'rest_forbidden', __( 'This probe needs a valid token.', 'axle-runtime-doctor' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$this->grant = $grant;

		return true;
	}

	/**
	 * Answers the probe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( $request ) {
		unset( $request );
		$grant = $this->grant ?? array(
			'mode' => '',
			'args' => array(),
		);
		$args  = (array) $grant['args'];

		switch ( $grant['mode'] ) {
			case 'run':
				$plugin = Plugin::instance();
				if ( ! $plugin ) {
					return new WP_Error( 'axle_runtime_doctor_not_ready', 'Not ready.', array( 'status' => 500 ) );
				}

				return new WP_REST_Response( $plugin->runner()->run_local( $args ) );
			case 'read':
				$key = (string) ( $args['key'] ?? '' );

				return new WP_REST_Response(
					array(
						'found'    => '' !== $key && 'ok' === wp_cache_get( $key, 'axle_runtime_doctor' ),
						'coverage' => Facts::observe_coverage(),
					)
				);
			case 'timing':
				$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : self::$booted;

				return new WP_REST_Response( array( 'ms' => round( max( 0, self::$booted - $start ) * 1000, 1 ) ) );
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Creates a single-use token.
	 *
	 * @param string $mode run, read, timing, ping or queries.
	 * @param array  $args What the probe should do.
	 * @return string The token, to send in the X-Axle-Runtime-Doctor-Probe header.
	 */
	public static function issue( string $mode, array $args = array() ): string {
		$token = wp_generate_password( 40, false, false );
		// An option, not a transient: an object cache that forgets between requests would lose it.
		add_option(
			self::PREFIX . self::hash( $token ),
			array(
				'mode'    => $mode,
				'args'    => $args,
				'expires' => time() + self::SECONDS,
			),
			'',
			false
		);

		return $token;
	}

	/**
	 * Uses up a token.
	 *
	 * @param string      $token Token.
	 * @param string|null $mode  Only when the token is for this mode.
	 * @return array|null mode, args. Null when the token is unknown, used or expired.
	 */
	public static function consume( string $token, ?string $mode = null ): ?array {
		$name  = self::PREFIX . self::hash( $token );
		$grant = get_option( $name );
		if ( ! is_array( $grant ) || ( null !== $mode && ( $grant['mode'] ?? '' ) !== $mode ) ) {
			return null;
		}
		delete_option( $name );
		if ( (int) ( $grant['expires'] ?? 0 ) < time() ) {
			return null;
		}

		return array(
			'mode' => (string) ( $grant['mode'] ?? '' ),
			'args' => (array) ( $grant['args'] ?? array() ),
		);
	}

	/**
	 * The stored name of a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function hash( string $token ): string {
		return substr( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Where loopback requests go: the home URL, unless filtered.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		$home = untrailingslashit( home_url() );

		/**
		 * Filters the address the probe uses to call the site from the server,
		 * for servers that cannot reach their own public address, such as
		 * http://127.0.0.1 or a Docker service name.
		 *
		 * @param string $url Default: the home URL.
		 */
		$url = (string) apply_filters( 'axle_runtime_doctor_loopback_url', $home );

		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? untrailingslashit( $url ) : $home;
	}

	/**
	 * A site URL, rewritten to go through the loopback address.
	 *
	 * @param string $url A URL on the site.
	 * @return string
	 */
	public static function loopback( string $url ): string {
		$home = untrailingslashit( home_url() );

		return 0 === strpos( $url, $home ) ? self::base_url() . substr( $url, strlen( $home ) ) : $url;
	}

	/**
	 * Arguments for a loopback request.
	 *
	 * @param string $token   Probe token.
	 * @param int    $timeout Seconds.
	 * @return array
	 */
	private static function request_args( string $token, int $timeout ): array {
		return array(
			'timeout'     => $timeout,
			'redirection' => 0,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array(
				self::HEADER    => $token,
				'Cache-Control' => 'no-cache',
				'Content-Type'  => 'application/json',
			),
		);
	}

	/**
	 * Asks the web server to do something and returns its answer.
	 *
	 * @param string $mode    run, read, timing or ping.
	 * @param array  $args    What to do.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error
	 */
	public static function call( string $mode, array $args = array(), int $timeout = 20 ) {
		$token    = self::issue( $mode, $args );
		$url      = self::loopback( add_query_arg( 'rest_route', '/' . self::NS . '/probe', home_url( '/' ) ) );
		$response = wp_remote_post( $url, self::request_args( $token, $timeout ) + array( 'body' => '{}' ) );

		if ( is_wp_error( $response ) ) {
			delete_option( self::PREFIX . self::hash( $token ) );
			$error = new WP_Error( 'axle_runtime_doctor_probe_failed', $response->get_error_message() );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && is_array( $body ) ) {
				return $body;
			}
			/* translators: %d: HTTP status. */
			$error = new WP_Error( 'axle_runtime_doctor_probe_failed', sprintf( __( 'The site answered the probe with HTTP %d.', 'axle-runtime-doctor' ), $code ) );
		}

		/**
		 * Fires when a loopback probe fails.
		 *
		 * @param WP_Error $error The error.
		 * @param string   $mode  What the probe was for.
		 * @param string   $url   Where it went.
		 */
		do_action( 'axle_runtime_doctor_probe_failed', $error, $mode, $url );

		return $error;
	}

	/**
	 * Loads a front-end page with query logging on and returns its queries.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error status, digests.
	 */
	public static function page_queries( string $url ) {
		$token = self::issue( 'queries' );
		$out   = self::OUT . self::hash( $token );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A loopback to this site, which must wait for the whole page.
		$response = wp_remote_get( self::loopback( $url ), self::request_args( $token, 20 ) );
		$found    = get_option( $out );
		delete_option( $out );
		delete_option( self::PREFIX . self::hash( $token ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $found ) ) {
			/* translators: %d: HTTP status. */
			return new WP_Error( 'axle_runtime_doctor_no_queries', sprintf( __( 'HTTP %d, no queries recorded', 'axle-runtime-doctor' ), (int) wp_remote_retrieve_response_code( $response ) ) );
		}

		return $found + array( 'status' => (int) wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Turns on query logging when this request is a front-end query probe.
	 * Runs on plugins_loaded, before the page's own queries.
	 */
	public static function maybe_capture(): void {
		$token = isset( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] ) ) : '';
		if ( '' === $token || ! self::consume( $token, 'queries' ) ) {
			return;
		}

		self::$capturing = true;
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true ); // phpcs:ignore WordPress.WP.DiscouragedConstants.SAVEQUERIESDeclarationFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Only for this single, token-authenticated probe request.
		}
		// The probe calls the loopback address, which may not be the canonical one.
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'log_query_custom_data', array( self::class, 'tag_query' ), 10, 1 );
		$out = self::OUT . self::hash( $token );
		add_action(
			'shutdown',
			static function () use ( $out ) {
				self::save_queries( $out );
			},
			0
		);
	}

	/**
	 * Whether this request is a query probe.
	 *
	 * @return bool
	 */
	public static function capturing(): bool {
		return self::$capturing;
	}

	/**
	 * Adds the component that sent each query: a plugin, theme, must-use plugin or core.
	 *
	 * @param array $data Custom query data.
	 * @return array
	 */
	public static function tag_query( $data ): array {
		$data = (array) $data;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only on the probe request, to name the component that sent each query.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			$component = self::component( (string) ( $frame['file'] ?? '' ) );
			if ( '' !== $component ) {
				$data['axle_runtime_doctor_component'] = $component;
				return $data;
			}
		}
		$data['axle_runtime_doctor_component'] = 'core';

		return $data;
	}

	/**
	 * Names the component a file belongs to.
	 *
	 * @param string $file File path.
	 * @return string plugin:slug, theme:slug, mu-plugin:file, or empty for core and this plugin.
	 */
	public static function component( string $file ): string {
		$file = wp_normalize_path( $file );
		$self = wp_normalize_path( dirname( PLUGIN_FILE ) ) . '/';
		if ( '' === $file || 0 === strpos( $file, $self ) ) {
			return '';
		}
		$roots = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		foreach ( $roots as $type => $root ) {
			if ( 0 === strpos( $file, $root ) ) {
				$parts = explode( '/', substr( $file, strlen( $root ) ) );

				return $type . ':' . $parts[0];
			}
		}

		return '';
	}

	/**
	 * Strips literals from a query, so queries with the same shape group
	 * together and no values are stored.
	 *
	 * @param string $sql Query.
	 * @return string
	 */
	public static function digest( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$sql = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '?', $sql );
		$sql = (string) preg_replace( '/(?<![\w.])-?\d+(?:\.\d+)?\b/', '?', $sql );
		$sql = (string) preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?, ...)', $sql );
		$sql = (string) preg_replace( '/\s+/', ' ', trim( $sql ) );

		return substr( $sql, 0, 400 );
	}

	/**
	 * Groups logged queries by digest.
	 *
	 * @param array $queries wpdb::$queries rows: sql, seconds, callers, start, custom data.
	 * @return array[] sql, calls, total_ms, max_ms, component; slowest total first.
	 */
	public static function group( array $queries ): array {
		$groups = array();
		foreach ( $queries as $query ) {
			if ( ! is_array( $query ) || ! isset( $query[0], $query[1] ) ) {
				continue;
			}
			$sql = self::digest( (string) $query[0] );
			$ms  = (float) $query[1] * 1000;
			if ( ! isset( $groups[ $sql ] ) ) {
				$groups[ $sql ] = array(
					'sql'       => $sql,
					'calls'     => 0,
					'total_ms'  => 0.0,
					'max_ms'    => 0.0,
					'component' => (string) ( $query[4]['axle_runtime_doctor_component'] ?? 'core' ),
				);
			}
			++$groups[ $sql ]['calls'];
			$groups[ $sql ]['total_ms'] += $ms;
			$groups[ $sql ]['max_ms']    = max( $groups[ $sql ]['max_ms'], $ms );
		}

		usort(
			$groups,
			static function ( $a, $b ) {
				return $b['total_ms'] <=> $a['total_ms'];
			}
		);

		return array_map(
			static function ( $group ) {
				$group['total_ms'] = round( $group['total_ms'], 2 );
				$group['max_ms']   = round( $group['max_ms'], 2 );
				return $group;
			},
			$groups
		);
	}

	/**
	 * Saves this page's grouped queries for the caller.
	 *
	 * @param string $out Option name.
	 */
	private static function save_queries( string $out ): void {
		global $wpdb;
		$queries = isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? $wpdb->queries : array();
		$total   = 0.0;
		foreach ( $queries as $query ) {
			$total += (float) ( $query[1] ?? 0 );
		}
		add_option(
			$out,
			array(
				'count'    => count( $queries ),
				'total_ms' => round( $total * 1000, 2 ),
				'digests'  => array_slice( self::group( $queries ), 0, 50 ),
			),
			'',
			false
		);
	}

	/**
	 * Deletes expired tokens and leftover probe output.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge(): int {
		global $wpdb;
		$deleted = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Housekeeping of probe tokens, once a day.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( 'axle_runtime_doctor_probe_' ) . '%' ) );
		foreach ( (array) $names as $name ) {
			$value = get_option( (string) $name );
			if ( ! is_array( $value ) || ! isset( $value['expires'] ) || (int) $value['expires'] < time() ) {
				$deleted += delete_option( (string) $name ) ? 1 : 0;
			}
		}

		return $deleted;
	}
}
