<?php
/**
 * Direct mode: one site fetches the report of another that runs the plugin.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Probe;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Site A makes a one-time code. Site B takes the code, calls A over HTTPS and
 * keeps the report it gets back.
 *
 * The code holds A's address and a random token. A stores only a hash of the
 * token, accepts it once, and stops accepting it after a few minutes. The
 * checksum at the end of the code only catches typos and cut-off pastes: the
 * token is what proves the caller may read the report. B makes the call with
 * the safe HTTP functions, so addresses on private networks are refused.
 */
final class Direct implements Service {

	public const MODE   = 'share';
	private const LEAD  = 'ard1';
	private const TOKEN = '/^[A-Za-z0-9]{40}$/';

	/**
	 * Runs the checks, when there is no report yet.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Snapshots.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up direct mode.
	 *
	 * @param Runner  $runner  Runs the checks.
	 * @param History $history Snapshots.
	 */
	public function __construct( Runner $runner, History $history ) {
		$this->runner  = $runner;
		$this->history = $history;
	}

	/**
	 * Adds the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers GET /axle-runtime-doctor/v1/share.
	 */
	public function routes(): void {
		register_rest_route(
			Probe::NS,
			'/share',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Whether direct mode is on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether direct mode is on: making codes on this site, taking
		 * them, and answering them. Return false to keep every report manual.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'axle_runtime_doctor_direct_enabled', true );
	}

	/**
	 * How long a code works, in seconds.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		/**
		 * Filters how long a one-time code works.
		 *
		 * @param int $seconds Default 600. 60 to 3600.
		 */
		return max( 60, min( 3600, (int) apply_filters( 'axle_runtime_doctor_direct_code_ttl', 600 ) ) );
	}

	/**
	 * Makes a one-time code for another site.
	 *
	 * @return array{code: string, expires: int}|WP_Error
	 */
	public static function create_code() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'axle_runtime_doctor_direct_off', __( 'Direct mode is turned off on this site.', 'axle-runtime-doctor' ) );
		}

		$ttl     = self::ttl();
		$token   = Probe::issue( self::MODE, array(), $ttl );
		$payload = (string) wp_json_encode(
			array(
				'u' => untrailingslashit( home_url() ),
				't' => $token,
			)
		);
		$body    = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Packs a short code for pasting, not hiding anything.
		$code    = self::LEAD . '.' . $body . '.' . substr( hash( 'crc32b', $body ), 0, 6 );

		/**
		 * Fires after a one-time code was made.
		 *
		 * @param int $expires Unix time the code stops working.
		 */
		do_action( 'axle_runtime_doctor_direct_code_created', time() + $ttl );

		return array(
			'code'    => $code,
			'expires' => time() + $ttl,
		);
	}

	/**
	 * Reads a code.
	 *
	 * @param string $code What the person pasted.
	 * @return array{origin: string, token: string}|WP_Error
	 */
	public static function decode( string $code ) {
		$error = new WP_Error( 'axle_runtime_doctor_direct_code', __( 'That is not a valid code. Copy the whole code from the other site again.', 'axle-runtime-doctor' ) );
		$parts = explode( '.', trim( $code ) );
		if ( 3 !== count( $parts ) || self::LEAD !== $parts[0] || strlen( $parts[1] ) > 1024 || ! hash_equals( substr( hash( 'crc32b', $parts[1] ), 0, 6 ), $parts[2] ) ) {
			return $error;
		}

		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unpacks the code made above, and the result is validated as JSON.
		$data = is_string( $json ) ? json_decode( $json, true, 2 ) : null;
		if ( ! is_array( $data ) || ! isset( $data['u'], $data['t'] ) || ! is_string( $data['u'] ) || ! is_string( $data['t'] ) || ! preg_match( self::TOKEN, $data['t'] ) ) {
			return $error;
		}

		return array(
			'origin' => untrailingslashit( $data['u'] ),
			'token'  => $data['t'],
		);
	}

	/**
	 * Fetches the report of the site that made a code.
	 *
	 * @param string $code The code.
	 * @return array|WP_Error A clean report document, or why it failed.
	 */
	public static function fetch( string $code ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'axle_runtime_doctor_direct_off', __( 'Direct mode is turned off on this site.', 'axle-runtime-doctor' ) );
		}
		$decoded = self::decode( $code );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$origin = $decoded['origin'];
		$scheme = wp_parse_url( $origin, PHP_URL_SCHEME );
		/**
		 * Filters whether a code may point at a plain http address. Leave it off
		 * on live sites: the token would cross the network in the clear.
		 *
		 * @param bool   $allow  Default false.
		 * @param string $origin The address in the code.
		 */
		$http = (bool) apply_filters( 'axle_runtime_doctor_direct_allow_http', false, $origin );
		if ( 'https' !== $scheme && ! ( $http && 'http' === $scheme ) ) {
			return self::failed( new WP_Error( 'axle_runtime_doctor_direct_scheme', __( 'The code points at an address that is not https, so it was not used. The code travels with the request and must be encrypted.', 'axle-runtime-doctor' ) ), $origin );
		}

		$url = add_query_arg( 'rest_route', '/' . Probe::NS . '/share', trailingslashit( $origin ) );
		/**
		 * Filters the address direct mode calls, for sites that reach each other
		 * through another name, such as a private network or a test setup.
		 *
		 * @param string $url    The full request address.
		 * @param string $origin The address in the code.
		 */
		$url = (string) apply_filters( 'axle_runtime_doctor_direct_request_url', $url, $origin );
		if ( ! wp_http_validate_url( $url ) ) {
			return self::failed( new WP_Error( 'axle_runtime_doctor_direct_unsafe', __( 'That address cannot be called: it is not a public web address. Use the manual export and import instead.', 'axle-runtime-doctor' ) ), $origin );
		}

		/**
		 * Filters how many seconds direct mode waits for the other site.
		 *
		 * @param int $seconds Default 30. 5 to 120.
		 */
		$timeout  = max( 5, min( 120, (int) apply_filters( 'axle_runtime_doctor_direct_timeout', 30 ) ) );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => Importer::max_bytes() + 1,
				'headers'             => array(
					Probe::HEADER => $decoded['token'],
					'Accept'      => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			return self::failed( new WP_Error( 'axle_runtime_doctor_direct_request', sprintf( __( 'The other site did not answer: %s', 'axle-runtime-doctor' ), $response->get_error_message() ) ), $origin );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::failed( new WP_Error( 'axle_runtime_doctor_direct_refused', __( 'The other site refused the code. A code works once and for a few minutes. Make a new one there.', 'axle-runtime-doctor' ) ), $origin );
		}
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status. */
			return self::failed( new WP_Error( 'axle_runtime_doctor_direct_status', sprintf( __( 'The other site answered with HTTP %d. It may not run Runtime Doctor, or direct mode is off there.', 'axle-runtime-doctor' ), $status ) ), $origin );
		}

		$document = Importer::parse( (string) wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $document ) ) {
			return self::failed( $document, $origin );
		}

		/**
		 * Fires after a report was fetched from another site.
		 *
		 * @param string $origin   Address of the other site.
		 * @param array  $document The clean report.
		 */
		do_action( 'axle_runtime_doctor_direct_fetched', $origin, $document );

		return $document;
	}

	/**
	 * A readable name for the site a code points at.
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function site_name( string $code ): string {
		$decoded = self::decode( $code );
		$host    = is_wp_error( $decoded ) ? '' : (string) wp_parse_url( $decoded['origin'], PHP_URL_HOST );

		return '' !== $host ? $host : __( 'Other site', 'axle-runtime-doctor' );
	}

	/**
	 * Fires the failure action and passes the error on.
	 *
	 * @param WP_Error $error  The error.
	 * @param string   $origin Address in the code.
	 * @return WP_Error
	 */
	private static function failed( WP_Error $error, string $origin ): WP_Error {
		/**
		 * Fires when direct mode could not fetch a report.
		 *
		 * @param WP_Error $error  Why.
		 * @param string   $origin Address of the other site.
		 */
		do_action( 'axle_runtime_doctor_direct_fetch_failed', $error, $origin );

		return $error;
	}

	/**
	 * Lets in a request that carries a valid code, once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authorize( $request ) {
		$token = (string) $request->get_header( 'x_axle_runtime_doctor_probe' );
		if ( ! self::enabled() || '' === $token || ! Probe::consume( $token, self::MODE ) ) {
			return new WP_Error( 'rest_forbidden', __( 'This needs a valid, unused code.', 'axle-runtime-doctor' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Answers with the report.
	 *
	 * @return WP_REST_Response
	 */
	public function serve(): WP_REST_Response {
		$report = $this->history->latest();
		if ( ! $report || empty( $report['environment'] ) ) {
			$report = $this->runner->run_local(
				array(
					'save'   => false,
					'source' => 'share',
				)
			);
		}
		$document = Exporter::publish( $report, 'direct' );

		$response = new WP_REST_Response( $document );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
