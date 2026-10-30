<?php
/**
 * Runs the checks.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use AryanX\Axle\RuntimeDoctor\Checks\Context;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Runs checks in the web server's PHP and builds the report.
 *
 * In a web request (the screen, REST, abilities over HTTP, WP-Cron) the
 * checks run right here. From WP-CLI they run through the loopback probe, so
 * the values are the web server's. When the probe fails, WP-CLI says so and
 * runs what it can, with the checks that need the web server marked as such.
 */
final class Runner {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The checks.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Host detection.
	 *
	 * @var Hosts
	 */
	private Hosts $hosts;

	/**
	 * Front-end samples.
	 *
	 * @var Sampler
	 */
	private Sampler $sampler;

	/**
	 * Autoloaded options.
	 *
	 * @var Autoload
	 */
	private Autoload $autoload;

	/**
	 * PHP release data.
	 *
	 * @var PhpReleases
	 */
	private PhpReleases $releases;

	/**
	 * Snapshots.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the runner.
	 *
	 * @param Settings    $settings Settings.
	 * @param Registry    $registry The checks.
	 * @param Hosts       $hosts    Host detection.
	 * @param Sampler     $sampler  Front-end samples.
	 * @param Autoload    $autoload Autoloaded options.
	 * @param PhpReleases $releases PHP release data.
	 * @param History     $history  Snapshots.
	 */
	public function __construct( Settings $settings, Registry $registry, Hosts $hosts, Sampler $sampler, Autoload $autoload, PhpReleases $releases, History $history ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->hosts    = $hosts;
		$this->sampler  = $sampler;
		$this->autoload = $autoload;
		$this->releases = $releases;
		$this->history  = $history;
	}

	/**
	 * Runs checks where their values are real: here in a web request, through
	 * the probe from WP-CLI.
	 *
	 * @param array $args checks (IDs), group, per_site, save (default: true for a full run), source.
	 * @return array The report.
	 */
	public function run( array $args = array() ): array {
		if ( 'cli' !== self::sapi() ) {
			return $this->run_local( $args );
		}

		$remote = Probe::call( 'run', $args, 180 );
		if ( is_array( $remote ) && isset( $remote['results'] ) ) {
			$remote['via'] = 'probe';

			return $remote;
		}

		$report                = $this->run_local( array_merge( $args, array( 'sapi' => 'cli' ) ) );
		$report['probe_error'] = is_wp_error( $remote ) ? $remote->get_error_message() : __( 'The probe sent an answer that is not a report.', 'axle-runtime-doctor' );

		return $report;
	}

	/**
	 * Runs checks in this request.
	 *
	 * @param array $args checks, group, per_site, save, source, sapi.
	 * @return array The report.
	 */
	public function run_local( array $args = array() ): array {
		$ids      = array_values( array_filter( array_map( 'strval', (array) ( $args['checks'] ?? array() ) ) ) );
		$group    = (string) ( $args['group'] ?? '' );
		$per_site = ! empty( $args['per_site'] );
		$full     = ! $ids && '' === $group && ! $per_site;
		$save     = isset( $args['save'] ) ? (bool) $args['save'] && $full : $full;
		$source   = (string) ( $args['source'] ?? 'screen' );
		$sapi     = 'cli' === ( $args['sapi'] ?? self::sapi() ) ? 'cli' : 'web';
		$facts    = new Facts( $this->settings, $this->hosts, $this->sampler, $this->autoload, $this->releases, $sapi );
		$context  = new Context( array(), $facts, $this->settings->thresholds() );
		$checks   = $this->registry->pick( $ids, $group, $per_site );

		/**
		 * Fires before the checks run.
		 *
		 * @param string[] $checks Check IDs.
		 * @param string   $source screen, cli, cron, ability or site-health.
		 */
		do_action( 'axle_runtime_doctor_checks_started', array_keys( $checks ), $source );

		$results = array();
		foreach ( $checks as $id => $check ) {
			if ( 'cli' === $sapi && $check->needs_web() ) {
				$result = Result::from_array(
					array(
						'id'      => $id,
						'group'   => $check->group(),
						'label'   => $check->label(),
						'status'  => Result::INFO,
						'summary' => __( 'Only the web server can check this, and the probe could not reach it', 'axle-runtime-doctor' ),
						'value'   => __( 'Not checked from WP-CLI', 'axle-runtime-doctor' ),
					)
				);
			} else {
				try {
					$result = $check->run( $context );
				} catch ( \Throwable $e ) {
					$result = Result::from_array(
						array(
							'id'      => $id,
							'group'   => $check->group(),
							'label'   => $check->label(),
							'status'  => Result::INFO,
							/* translators: %s: error message. */
							'summary' => sprintf( __( 'The check failed: %s', 'axle-runtime-doctor' ), $e->getMessage() ),
						)
					);
				}
			}
			if ( null === $result ) {
				continue;
			}

			/**
			 * Filters one check's result before it is stored and shown.
			 *
			 * @param array   $result  id, group, label, status, summary, value, expected, why, details, data, inferred.
			 * @param string  $id      Check ID.
			 * @param Context $context What the run knows.
			 */
			$filtered = (array) apply_filters( 'axle_runtime_doctor_check_result', $result->to_array(), $id, $context );
			$result   = $filtered ? Result::from_array( array_merge( $filtered, array( 'id' => $id ) ) ) : $result;
			$row      = $result->to_array();

			/**
			 * Fires after a check ran.
			 *
			 * @param array $result The result.
			 */
			do_action( 'axle_runtime_doctor_check_finished', $row );

			$results[ $id ] = $row;
		}

		$report = array(
			'time'    => time(),
			'host'    => $this->hosts->current(),
			'sapi'    => $sapi,
			'via'     => 'local',
			'source'  => $source,
			'php'     => PHP_VERSION,
			'counts'  => self::counts( $results ),
			'results' => $results,
		);
		$facts  = $context->all();
		if ( isset( $facts['slow_queries'] ) && is_array( $facts['slow_queries'] ) ) {
			$report['slow_queries'] = $facts['slow_queries'];
		}

		if ( $save ) {
			$report['regressions'] = $this->history->save( $report );
		}

		/**
		 * Fires after the checks ran.
		 *
		 * @param array $report time, host, sapi, via, source, counts, results, slow_queries.
		 */
		do_action( 'axle_runtime_doctor_checks_finished', $report );

		return $report;
	}

	/**
	 * Results per status.
	 *
	 * @param array $results Results.
	 * @return array<string, int>
	 */
	public static function counts( array $results ): array {
		$counts = array_fill_keys( Result::statuses(), 0 );
		foreach ( $results as $result ) {
			$status = (string) ( $result['status'] ?? Result::INFO );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Either web or cli.
	 *
	 * @return string
	 */
	public static function sapi(): string {
		return 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) ? 'cli' : 'web';
	}
}
