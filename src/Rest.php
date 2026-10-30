<?php
/**
 * REST routes for the screen.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /axle-runtime-doctor/v1/run runs every check in this web request and
 * stores the snapshot. GET /axle-runtime-doctor/v1/report returns the latest
 * report. Both need the plugin's capability.
 */
final class Rest implements Service {

	/**
	 * Runs the checks.
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
	 * Sets up the routes.
	 *
	 * @param Runner  $runner  Runs the checks.
	 * @param History $history Snapshots.
	 */
	public function __construct( Runner $runner, History $history ) {
		$this->runner  = $runner;
		$this->history = $history;
	}

	/**
	 * Adds the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$allowed = static function (): bool {
			return current_user_can( Settings::capability() );
		};

		register_rest_route(
			Probe::NS,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $allowed,
			)
		);
		register_rest_route(
			Probe::NS,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'report' ),
				'permission_callback' => $allowed,
			)
		);
	}

	/**
	 * Runs every check.
	 *
	 * @return WP_REST_Response
	 */
	public function run(): WP_REST_Response {
		return new WP_REST_Response( $this->runner->run( array( 'source' => 'screen' ) ) );
	}

	/**
	 * The latest report.
	 *
	 * @return WP_REST_Response
	 */
	public function report(): WP_REST_Response {
		return new WP_REST_Response( $this->history->latest() );
	}
}
