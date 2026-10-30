<?php
/**
 * How full the OPcache interned strings buffer is.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How full the OPcache interned strings buffer is.
 */
final class OpcacheStrings extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_strings';
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
		return __( 'Interned strings buffer', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'OPcache keeps repeated strings (class, function and array key names) in one buffer. A full buffer makes every process keep its own copies, which uses more memory and slows things down. WordPress with many plugins often needs 16 to 32 MB.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		$strings = $opcache['status']['interned_strings_usage'] ?? null;
		if ( ! is_array( $strings ) || empty( $strings['buffer_size'] ) ) {
			return null;
		}

		$size   = (float) $strings['buffer_size'];
		$used   = (float) ( $strings['used_memory'] ?? 0 );
		$pct    = self::percent( $used, $size );
		$limit  = $context->threshold( 'opcache_strings_pct' );
		$fields = array(
			/* translators: 1: used size, 2: buffer size, 3: percentage. */
			'value'    => sprintf( __( '%1$s of %2$s used (%3$s%%)', 'axle-runtime-doctor' ), self::size( $used ), self::size( $size ), $pct ),
			/* translators: %s: percentage. */
			'expected' => sprintf( __( 'Under %s%%', 'axle-runtime-doctor' ), $limit ),
			'data'     => array(
				'used_pct' => $pct,
				'size'     => $size,
			),
		);

		if ( $pct >= $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'The interned strings buffer is full', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The interned strings buffer has room', 'axle-runtime-doctor' ), $fields );
	}
}
