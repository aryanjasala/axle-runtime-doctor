<?php
/**
 * Snapshots and regressions.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last 30 snapshots (the status and value of each check) and the
 * latest full report. A check that got worse since the last snapshot is a
 * regression: it fires an action and goes to Alerts.
 */
final class History {

	public const SNAPSHOTS = 'axle_runtime_doctor_snapshots';
	public const REPORT    = 'axle_runtime_doctor_report';

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up history.
	 *
	 * @param Alerts $alerts Alerts.
	 */
	public function __construct( Alerts $alerts ) {
		$this->alerts = $alerts;
	}

	/**
	 * Every snapshot, oldest first.
	 *
	 * @return array[] time, host, sapi, results (ID => status, value).
	 */
	public function all(): array {
		$snapshots = Store::get( self::SNAPSHOTS, array() );

		return is_array( $snapshots ) ? array_values( array_filter( $snapshots, 'is_array' ) ) : array();
	}

	/**
	 * The latest full report.
	 *
	 * @return array|null
	 */
	public function latest(): ?array {
		$report = Store::get( self::REPORT );

		return is_array( $report ) && isset( $report['results'] ) ? $report : null;
	}

	/**
	 * Stores a report as the latest and as a snapshot, and finds regressions.
	 *
	 * @param array $report The report.
	 * @return array[] Regressions: id, label, before, after, summary.
	 */
	public function save( array $report ): array {
		$snapshots = $this->all();
		$previous  = $snapshots ? end( $snapshots ) : null;
		$snapshot  = self::compact( $report );

		$regressions = array();
		if ( is_array( $previous ) ) {
			$regressions = self::regressions( (array) $previous['results'], $report );
		}
		$snapshot['regressions'] = array_column( $regressions, 'id' );

		$snapshots[] = $snapshot;
		Store::set( self::SNAPSHOTS, array_slice( $snapshots, -Settings::snapshots_to_keep() ) );
		unset( $report['regressions'] );
		Store::set( self::REPORT, $report );

		foreach ( $regressions as $regression ) {
			/**
			 * Fires when a check got worse since the last snapshot.
			 *
			 * @param string $check  Check ID.
			 * @param string $before Status before.
			 * @param string $after  Status now.
			 * @param array  $result The new result.
			 */
			do_action( 'axle_runtime_doctor_regression_detected', $regression['id'], $regression['before'], $regression['after'], $report['results'][ $regression['id'] ] );
		}
		if ( $regressions ) {
			$this->alerts->send( $regressions, $report );
		}

		return $regressions;
	}

	/**
	 * A report reduced to what a snapshot keeps.
	 *
	 * @param array $report The report.
	 * @return array
	 */
	public static function compact( array $report ): array {
		$results = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $id => $result ) {
			$results[ $id ] = array(
				'status' => (string) ( $result['status'] ?? Result::INFO ),
				'value'  => substr( (string) ( $result['value'] ?? '' ), 0, 200 ),
			);
		}

		return array(
			'time'    => (int) ( $report['time'] ?? time() ),
			'host'    => (string) ( $report['host']['id'] ?? '' ),
			'sapi'    => (string) ( $report['sapi'] ?? 'web' ),
			'results' => $results,
		);
	}

	/**
	 * Checks that got worse.
	 *
	 * @param array $before Snapshot results: ID => status, value.
	 * @param array $report The new report.
	 * @return array[]
	 */
	public static function regressions( array $before, array $report ): array {
		$found = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $id => $result ) {
			if ( ! isset( $before[ $id ]['status'] ) ) {
				continue;
			}
			$old = (string) $before[ $id ]['status'];
			$new = (string) ( $result['status'] ?? Result::INFO );
			if ( Result::rank( $new ) > Result::rank( $old ) ) {
				$found[] = array(
					'id'      => (string) $id,
					'label'   => (string) ( $result['label'] ?? $id ),
					'before'  => $old,
					'after'   => $new,
					'summary' => (string) ( $result['summary'] ?? '' ),
				);
			}
		}

		return $found;
	}

	/**
	 * Removes every snapshot and the latest report.
	 */
	public function clear(): void {
		Store::delete( self::SNAPSHOTS );
		Store::delete( self::REPORT );
	}
}
