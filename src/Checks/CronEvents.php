<?php
/**
 * Duplicate events and events nothing handles.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Duplicate events and events nothing handles.
 */
final class CronEvents extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'cron_events';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'cron';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Scheduled events', 'axle-runtime-doctor' );
	}

	/**
	 * Reads stored state, which is the same from WP-CLI.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Scheduled events belong to each site.
	 *
	 * @return bool
	 */
	public function per_site(): bool {
		return true;
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A plugin that schedules its event on every request without checking first piles up duplicates, which all run. Events whose plugin is gone still fire and do nothing, and keep the cron option large.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$cron       = $context->arr( 'cron' );
		$duplicates = array_map( 'strval', (array) ( $cron['duplicates'] ?? array() ) );
		$orphans    = array_map( 'strval', (array) ( $cron['orphans'] ?? array() ) );
		$details    = array();
		if ( $duplicates ) {
			/* translators: %s: list of hooks. */
			$details[] = sprintf( __( 'Scheduled more than once with the same arguments: %s', 'axle-runtime-doctor' ), implode( ', ', $duplicates ) );
		}
		if ( $orphans ) {
			/* translators: %s: list of hooks. */
			$details[] = sprintf( __( 'No code handles these events in this request (Axle Leftovers can trace and remove them): %s', 'axle-runtime-doctor' ), implode( ', ', $orphans ) );
		}

		$fields = array(
			/* translators: 1: number of events, 2: number of duplicates. */
			'value'    => sprintf( __( '%1$d events, %2$d duplicated', 'axle-runtime-doctor' ), (int) ( $cron['events'] ?? 0 ), count( $duplicates ) ),
			'expected' => __( 'No duplicates', 'axle-runtime-doctor' ),
			'details'  => $details,
			'data'     => array(
				'duplicates' => $duplicates,
				'orphans'    => $orphans,
			),
		);

		if ( $duplicates ) {
			return $this->result( Result::RECOMMENDED, __( 'Some events are scheduled more than once', 'axle-runtime-doctor' ), $fields );
		}
		if ( $orphans ) {
			/* translators: %d: number of events. */
			return $this->result( Result::INFO, sprintf( _n( '%d event has no code to run it', '%d events have no code to run them', count( $orphans ), 'axle-runtime-doctor' ), count( $orphans ) ), $fields );
		}

		return $this->result( Result::GOOD, __( 'No duplicate events', 'axle-runtime-doctor' ), $fields );
	}
}
