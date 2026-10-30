<?php
/**
 * How OPcache notices changed files.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How OPcache notices changed files.
 */
final class OpcacheRevalidate extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_revalidate';
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
		return __( 'OPcache file checks', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'With opcache.validate_timestamps off, OPcache never looks at files again, so updates and deploys keep running the old code until someone flushes OPcache. Checking on every request (revalidate_freq 0) is safe but costs a file check per include.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		if ( empty( $opcache['loaded'] ) || '' === $context->ini( 'opcache.validate_timestamps' ) ) {
			return null;
		}

		$validate = self::on( $context->ini( 'opcache.validate_timestamps' ) );
		$freq     = (int) $context->ini( 'opcache.revalidate_freq' );
		$fields   = array(
			'value'    => $validate
				/* translators: %d: seconds. */
				? sprintf( __( 'validate_timestamps on, revalidate_freq %d s', 'axle-runtime-doctor' ), $freq )
				: __( 'validate_timestamps off', 'axle-runtime-doctor' ),
			'expected' => __( 'validate_timestamps on, or off with a flush on every deploy', 'axle-runtime-doctor' ),
			'data'     => array(
				'validate_timestamps' => $validate,
				'revalidate_freq'     => $freq,
			),
		);

		if ( ! $validate ) {
			$fields['details'] = array(
				__( 'Plugin and theme updates from wp-admin only show once OPcache is flushed. Make sure every deploy and update flushes it (PHP-FPM reload, or opcache_reset() from the web server).', 'axle-runtime-doctor' ),
				__( 'Axle Smoke Test can spot a deploy and check the site right after it.', 'axle-runtime-doctor' ),
			);

			return $this->result( Result::RECOMMENDED, __( 'OPcache never checks for changed files', 'axle-runtime-doctor' ), $fields );
		}

		if ( 0 === $freq ) {
			return $this->result( Result::INFO, __( 'OPcache checks files on every request', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'OPcache picks up changed files', 'axle-runtime-doctor' ), $fields );
	}
}
