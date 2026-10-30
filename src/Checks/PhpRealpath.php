<?php
/**
 * How full the realpath cache is.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How full the realpath cache is.
 */
final class PhpRealpath extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_realpath';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'php';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Realpath cache', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'PHP remembers where each included file lives. When the realpath cache is too small for WordPress and its plugins, every include asks the file system again, which is slow on network storage.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$raw  = $context->ini( 'realpath_cache_size' );
		$used = $context->get( 'realpath_used' );
		if ( '' === $raw || ! is_int( $used ) ) {
			return null;
		}

		$size   = self::ini_bytes( $raw );
		$pct    = self::percent( $used, $size );
		$fields = array(
			/* translators: 1: used size, 2: cache size, 3: percentage. */
			'value'    => sprintf( __( '%1$s of %2$s used (%3$s%%)', 'axle-runtime-doctor' ), self::size( $used ), $raw, $pct ),
			'expected' => __( 'Under 90%', 'axle-runtime-doctor' ),
			'data'     => array( 'used_pct' => $pct ),
		);

		if ( $size > 0 && $pct >= 90 ) {
			return $this->result( Result::RECOMMENDED, __( 'The realpath cache is full', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The realpath cache has room', 'axle-runtime-doctor' ), $fields );
	}
}
