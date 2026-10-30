<?php
/**
 * Whether the object cache keeps a value from one request to the next.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the object cache keeps a value from one request to the next.
 */
final class ObjectCacheRoundtrip extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'object_cache_roundtrip';
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
		return __( 'Object cache roundtrip', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A drop-in can be installed while Redis or Memcached is down. Many drop-ins then quietly fall back to memory that lasts one request, so the site runs as if it had no object cache, and slower than before, with nothing in the logs.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$cache = $context->arr( 'object_cache' );
		if ( empty( $cache['persistent'] ) ) {
			return $this->result(
				Result::INFO,
				__( 'Not persistent: each request starts with an empty object cache', 'axle-runtime-doctor' ),
				array(
					'value'    => __( 'Not persistent', 'axle-runtime-doctor' ),
					'expected' => __( 'A value written in one request is there in the next', 'axle-runtime-doctor' ),
				)
			);
		}

		$roundtrip = $context->arr( 'roundtrip' );
		if ( empty( $roundtrip['tested'] ) ) {
			return $this->result(
				Result::INFO,
				__( 'The roundtrip could not be tested', 'axle-runtime-doctor' ),
				array(
					'value'   => __( 'Not tested', 'axle-runtime-doctor' ),
					'details' => array_filter( array( (string) ( $roundtrip['error'] ?? '' ) ) ),
				)
			);
		}

		$fields = array(
			'expected' => __( 'A value written in one request is there in the next', 'axle-runtime-doctor' ),
		);

		if ( empty( $roundtrip['found'] ) ) {
			$fields['value'] = __( 'Written in one request, missing in the next', 'axle-runtime-doctor' );

			return $this->result( Result::CRITICAL, __( 'The object cache forgets everything between requests', 'axle-runtime-doctor' ), $fields );
		}

		$fields['value'] = __( 'Written in one request, read back in the next', 'axle-runtime-doctor' );

		return $this->result( Result::GOOD, __( 'The object cache keeps values between requests', 'axle-runtime-doctor' ), $fields );
	}
}
