<?php
/**
 * The max_execution_time in the web server.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The max_execution_time in the web server.
 */
final class PhpExecutionTime extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_execution_time';
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
		return __( 'Execution time', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Imports, backups and updates can run past a short time limit and stop halfway. WP-CLI has no limit, so only a web request shows the real one.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$raw = $context->ini( 'max_execution_time' );
		if ( '' === $raw ) {
			return null;
		}

		$seconds = (int) $raw;
		$fields  = array(
			/* translators: %d: seconds. */
			'value'    => 0 === $seconds ? __( 'No limit', 'axle-runtime-doctor' ) : sprintf( __( '%d seconds', 'axle-runtime-doctor' ), $seconds ),
			'expected' => __( '30 seconds or more', 'axle-runtime-doctor' ),
			'data'     => array( 'seconds' => $seconds ),
		);

		if ( $seconds > 0 && $seconds < 30 ) {
			return $this->result( Result::RECOMMENDED, __( 'The PHP time limit is short', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, 0 === $seconds ? __( 'PHP has no time limit', 'axle-runtime-doctor' ) : __( 'The PHP time limit is fine', 'axle-runtime-doctor' ), $fields );
	}
}
