<?php
/**
 * Whether the PHP 8 JIT is on.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the PHP 8 JIT is on.
 */
final class OpcacheJit extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_jit';
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
		return __( 'JIT', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'The JIT compiles hot PHP code to machine code. It helps maths-heavy code but does little for WordPress pages, and some extensions do not work with it. Shown for reference.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		if ( empty( $opcache['loaded'] ) || version_compare( (string) $context->get( 'php_version' ), '8.0', '<' ) ) {
			return null;
		}

		$jit    = $opcache['status']['jit'] ?? array();
		$on     = is_array( $jit ) && ! empty( $jit['on'] );
		$buffer = $context->ini( 'opcache.jit_buffer_size' );
		$mode   = $context->ini( 'opcache.jit' );

		return $this->result(
			Result::INFO,
			$on ? __( 'The JIT is on', 'axle-runtime-doctor' ) : __( 'The JIT is off', 'axle-runtime-doctor' ),
			array(
				/* translators: 1: JIT mode, 2: buffer size. */
				'value' => sprintf( __( 'opcache.jit %1$s, buffer %2$s', 'axle-runtime-doctor' ), '' !== $mode ? $mode : '-', '' !== $buffer ? $buffer : '0' ),
				'data'  => array( 'on' => $on ),
			)
		);
	}
}
