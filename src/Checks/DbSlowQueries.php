<?php
/**
 * The slowest queries on the key pages of the site.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The slowest queries on the key pages of the site.
 */
final class DbSlowQueries extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_slow_queries';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'database';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Slow queries', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'One slow query on the home page or a busy archive costs every visitor that time. The checks load the key pages with query logging on and group the queries by shape, so you can see which component sends the slow ones.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$slow = $context->get( 'slow_queries' );
		if ( ! is_array( $slow ) ) {
			return null;
		}

		$probed  = isset( $slow['probed'] ) ? count( (array) $slow['probed'] ) : 0;
		$digests = isset( $slow['digests'] ) && is_array( $slow['digests'] ) ? $slow['digests'] : array();
		$limit   = isset( $slow['threshold_ms'] ) ? (float) $slow['threshold_ms'] : 50.0;
		$details = array();
		foreach ( (array) ( $slow['failed'] ?? array() ) as $url ) {
			/* translators: %s: URL. */
			$details[] = sprintf( __( 'Could not load %s', 'axle-runtime-doctor' ), (string) $url );
		}
		foreach ( (array) ( $slow['server'] ?? array() ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$details[] = $key . ': ' . $value;
			}
		}

		if ( 0 === $probed ) {
			return $this->result( Result::INFO, __( 'No page could be loaded to measure queries', 'axle-runtime-doctor' ), array( 'details' => $details ) );
		}

		$slow_ones = array_values(
			array_filter(
				$digests,
				static function ( $digest ) use ( $limit ) {
					return is_array( $digest ) && (float) ( $digest['max_ms'] ?? 0 ) >= $limit;
				}
			)
		);
		foreach ( array_slice( $slow_ones, 0, 5 ) as $digest ) {
			/* translators: 1: milliseconds, 2: component, 3: query. */
			$details[] = sprintf( __( '%1$s ms, %2$s: %3$s', 'axle-runtime-doctor' ), round( (float) $digest['max_ms'], 1 ), (string) ( $digest['component'] ?? '' ), (string) ( $digest['sql'] ?? '' ) );
		}

		$fields = array(
			/* translators: 1: number of slow query shapes, 2: number of pages. */
			'value'    => sprintf( __( '%1$d slow query shapes on %2$d pages', 'axle-runtime-doctor' ), count( $slow_ones ), $probed ),
			/* translators: %s: milliseconds. */
			'expected' => sprintf( __( 'No query over %s ms', 'axle-runtime-doctor' ), $limit ),
			'details'  => $details,
			'data'     => array( 'slow' => count( $slow_ones ) ),
		);

		if ( $slow_ones ) {
			return $this->result( Result::RECOMMENDED, __( 'Some queries on key pages are slow', 'axle-runtime-doctor' ), $fields );
		}

		/* translators: %d: number of pages. */
		return $this->result( Result::GOOD, sprintf( _n( 'No slow queries on %d key page', 'No slow queries on %d key pages', $probed, 'axle-runtime-doctor' ), $probed ), $fields );
	}
}
