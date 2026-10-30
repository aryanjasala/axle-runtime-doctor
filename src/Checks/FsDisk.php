<?php
/**
 * Free disk space where WordPress lives.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Free disk space where WordPress lives.
 */
final class FsDisk extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fs_disk';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'filesystem';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Disk space', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A full disk breaks uploads, updates, sessions, caches and often the database, all at once. Site Health only checks space for updates.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$fs   = $context->arr( 'filesystem' );
		$free = $fs['disk_free'] ?? null;
		if ( ! is_numeric( $free ) ) {
			return null;
		}

		$free   = (float) $free;
		$total  = (float) ( $fs['disk_total'] ?? 0 );
		$limit  = $context->threshold( 'disk_free_mb' ) * MB_IN_BYTES;
		$fields = array(
			/* translators: 1: free size, 2: total size. */
			'value'    => $total > 0 ? sprintf( __( '%1$s free of %2$s', 'axle-runtime-doctor' ), self::size( $free ), self::size( $total ) ) : self::size( $free ),
			/* translators: %s: size. */
			'expected' => sprintf( __( 'At least %s free', 'axle-runtime-doctor' ), self::size( $limit ) ),
			'data'     => array( 'free' => $free ),
		);

		if ( $free < $limit / 10 ) {
			return $this->result( Result::CRITICAL, __( 'The disk is almost full', 'axle-runtime-doctor' ), $fields );
		}
		if ( $free < $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'Disk space is running low', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'There is enough free disk space', 'axle-runtime-doctor' ), $fields );
	}
}
