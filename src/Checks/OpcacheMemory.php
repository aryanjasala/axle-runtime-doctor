<?php
/**
 * How full the OPcache shared memory is.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How full the OPcache shared memory is.
 */
final class OpcacheMemory extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_memory';
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
		return __( 'OPcache memory', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'When OPcache memory fills up, new and changed files are no longer cached, and OPcache may restart and throw away the whole cache. Pages get slow in bursts.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		$memory  = $opcache['status']['memory_usage'] ?? null;
		if ( ! is_array( $memory ) ) {
			return null;
		}

		$used  = (float) ( $memory['used_memory'] ?? 0 ) + (float) ( $memory['wasted_memory'] ?? 0 );
		$total = $used + (float) ( $memory['free_memory'] ?? 0 );
		$pct   = self::percent( $used, $total );
		$limit = $context->threshold( 'opcache_memory_pct' );
		$value = sprintf(
			/* translators: 1: used size, 2: total size, 3: percentage. */
			__( '%1$s of %2$s used (%3$s%%)', 'axle-runtime-doctor' ),
			self::size( $used ),
			self::size( $total ),
			$pct
		);
		$fields = array(
			'value'    => $value,
			/* translators: %s: percentage. */
			'expected' => sprintf( __( 'Under %s%%', 'axle-runtime-doctor' ), $limit ),
			'details'  => array(
				/* translators: %s: size. */
				sprintf( __( 'Wasted by changed files: %s', 'axle-runtime-doctor' ), self::size( (float) ( $memory['wasted_memory'] ?? 0 ) ) ),
				/* translators: %s: size. */
				sprintf( __( 'Free: %s', 'axle-runtime-doctor' ), self::size( (float) ( $memory['free_memory'] ?? 0 ) ) ),
			),
			'data'     => array(
				'used_pct' => $pct,
				'total'    => $total,
			),
		);

		if ( $pct >= $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'OPcache memory is almost full', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'OPcache has free memory', 'axle-runtime-doctor' ), $fields );
	}
}
