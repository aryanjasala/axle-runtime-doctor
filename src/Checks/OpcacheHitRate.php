<?php
/**
 * How often a request finds its files already compiled.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How often a request finds its files already compiled.
 */
final class OpcacheHitRate extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_hit_rate';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'opcache';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'OPcache hit rate', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A healthy site serves almost every file from OPcache. A low hit rate means files keep being compiled, usually because the cache is too small, restarts often or checks files too often.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		$stats   = $opcache['status']['opcache_statistics'] ?? null;
		if ( ! is_array( $stats ) ) {
			return null;
		}

		$hits   = (int) ( $stats['hits'] ?? 0 );
		$misses = (int) ( $stats['misses'] ?? 0 );
		$rate   = isset( $stats['opcache_hit_rate'] ) ? round( (float) $stats['opcache_hit_rate'], 1 ) : self::percent( $hits, $hits + $misses );
		$limit  = $context->threshold( 'opcache_hit_rate' );
		$fields = array(
			/* translators: 1: percentage, 2: hits, 3: misses. */
			'value'    => sprintf( __( '%1$s%% (%2$d hits, %3$d misses)', 'axle-runtime-doctor' ), $rate, $hits, $misses ),
			/* translators: %s: percentage. */
			'expected' => sprintf( __( '%s%% or more', 'axle-runtime-doctor' ), $limit ),
			'data'     => array( 'rate' => $rate ),
		);

		if ( $hits + $misses < 1000 ) {
			return $this->result( Result::INFO, __( 'Too few requests since OPcache started to judge the hit rate', 'axle-runtime-doctor' ), $fields );
		}
		if ( $rate < $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'OPcache hit rate is low', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'OPcache hit rate is high', 'axle-runtime-doctor' ), $fields );
	}
}
