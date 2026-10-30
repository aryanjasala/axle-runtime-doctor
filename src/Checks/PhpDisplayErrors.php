<?php
/**
 * Whether PHP prints errors into pages in production.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether PHP prints errors into pages in production.
 */
final class PhpDisplayErrors extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_display_errors';
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
		return __( 'display_errors', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'With display_errors on, PHP prints warnings into pages, with server paths. Visitors see them, JSON responses break, and attackers learn the layout of the server. Site Health checks WP_DEBUG_DISPLAY; this is the PHP setting under it.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$raw         = $context->ini( 'display_errors' );
		$environment = (string) $context->get( 'environment' );
		$on          = self::on( $raw ) || 'stderr' === strtolower( $raw ) || 'stdout' === strtolower( $raw );
		$fields      = array(
			'value'    => $on ? __( 'On', 'axle-runtime-doctor' ) : __( 'Off', 'axle-runtime-doctor' ),
			'expected' => __( 'Off in production', 'axle-runtime-doctor' ),
			/* translators: %s: environment type. */
			'details'  => array( sprintf( __( 'Environment type: %s', 'axle-runtime-doctor' ), $environment ) ),
		);

		if ( $on && 'production' === $environment ) {
			return $this->result( Result::RECOMMENDED, __( 'PHP shows errors to visitors', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, $on ? __( 'PHP shows errors, outside production', 'axle-runtime-doctor' ) : __( 'PHP does not show errors to visitors', 'axle-runtime-doctor' ), $fields );
	}
}
