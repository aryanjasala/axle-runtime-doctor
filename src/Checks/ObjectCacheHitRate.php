<?php
/**
 * How often front-end requests find what they need in the object cache.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How often front-end requests find what they need in the object cache.
 */
final class ObjectCacheHitRate extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'object_cache_hit_rate';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'object_cache';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Object cache hit rate', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A persistent object cache only helps when requests find their data in it. A low hit rate means keys expire or get evicted too soon, or something flushes the cache often.', 'axle-runtime-doctor' );
	}

	/**
	 * Not tied to the web request: it reads stored samples.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$cache = $context->arr( 'object_cache' );
		if ( empty( $cache['persistent'] ) ) {
			return null;
		}

		$samples = array_filter(
			$context->arr( 'samples' ),
			static function ( $sample ) {
				return is_array( $sample ) && ! empty( $sample['persistent'] );
			}
		);
		$hits    = 0;
		$misses  = 0;
		foreach ( $samples as $sample ) {
			$hits   += (int) ( $sample['hits'] ?? 0 );
			$misses += (int) ( $sample['misses'] ?? 0 );
		}

		if ( count( $samples ) < 10 || 0 === $hits + $misses ) {
			return $this->result(
				Result::INFO,
				__( 'Not enough front-end samples yet', 'axle-runtime-doctor' ),
				array(
					/* translators: %d: number of samples. */
					'value'   => sprintf( _n( '%d sample', '%d samples', count( $samples ), 'axle-runtime-doctor' ), count( $samples ) ),
					'details' => array( __( 'Samples are taken from 1 in 50 front-end requests, unless sampling is off in the settings.', 'axle-runtime-doctor' ) ),
				)
			);
		}

		$rate   = self::percent( $hits, $hits + $misses );
		$limit  = $context->threshold( 'object_hit_rate' );
		$fields = array(
			/* translators: 1: percentage, 2: number of samples. */
			'value'    => sprintf( __( '%1$s%% over %2$d requests', 'axle-runtime-doctor' ), $rate, count( $samples ) ),
			/* translators: %s: percentage. */
			'expected' => sprintf( __( '%s%% or more', 'axle-runtime-doctor' ), $limit ),
			'data'     => array( 'rate' => $rate ),
		);

		if ( $rate < $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'The object cache hit rate is low', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The object cache hit rate is high', 'axle-runtime-doctor' ), $fields );
	}
}
