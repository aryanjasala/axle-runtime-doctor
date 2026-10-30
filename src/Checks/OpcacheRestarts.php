<?php
/**
 * Whether OPcache filled up or restarted.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether OPcache filled up or restarted.
 */
final class OpcacheRestarts extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_restarts';
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
		return __( 'OPcache restarts', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Each restart throws away every cached file, and all PHP workers compile WordPress again at the same time. Out-of-memory and hash restarts mean OPcache is too small for the site.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		$status  = $opcache['status'] ?? null;
		if ( ! is_array( $status ) || ! isset( $status['opcache_statistics'] ) ) {
			return null;
		}

		$stats  = (array) $status['opcache_statistics'];
		$oom    = (int) ( $stats['oom_restarts'] ?? 0 );
		$hash   = (int) ( $stats['hash_restarts'] ?? 0 );
		$manual = (int) ( $stats['manual_restarts'] ?? 0 );
		$full   = ! empty( $status['cache_full'] );
		$fields = array(
			/* translators: 1: out-of-memory restarts, 2: hash restarts, 3: manual restarts. */
			'value'    => sprintf( __( '%1$d out of memory, %2$d hash, %3$d manual', 'axle-runtime-doctor' ), $oom, $hash, $manual ),
			'expected' => __( 'No out of memory or hash restarts, cache not full', 'axle-runtime-doctor' ),
			'details'  => array( $full ? __( 'The cache is full: new files are not cached.', 'axle-runtime-doctor' ) : __( 'The cache is not full.', 'axle-runtime-doctor' ) ),
			'data'     => array(
				'oom'        => $oom,
				'hash'       => $hash,
				'cache_full' => $full,
			),
		);

		if ( $full ) {
			return $this->result( Result::CRITICAL, __( 'OPcache is full and caches no new files', 'axle-runtime-doctor' ), $fields );
		}
		if ( $oom > 0 || $hash > 0 ) {
			return $this->result( Result::RECOMMENDED, __( 'OPcache restarted because it ran out of room', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'OPcache has not run out of room', 'axle-runtime-doctor' ), $fields );
	}
}
