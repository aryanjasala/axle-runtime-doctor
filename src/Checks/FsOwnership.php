<?php
/**
 * Whether PHP runs as the user that owns the WordPress files.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether PHP runs as the user that owns the WordPress files.
 */
final class FsOwnership extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fs_ownership';
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
		return __( 'File ownership', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'When PHP runs as a different user from the one that owns the files, WordPress cannot write them directly and asks for FTP details to install or update plugins. FS_METHOD forces a method and hides the mismatch.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$fs     = $context->arr( 'filesystem' );
		$method = (string) ( $fs['fs_method'] ?? '' );
		$match  = $fs['owner_match'] ?? null;
		$fields = array(
			/* translators: 1: PHP user, 2: file owner, 3: filesystem method. */
			'value'    => sprintf( __( 'PHP runs as %1$s, files owned by %2$s, method %3$s', 'axle-runtime-doctor' ), (string) ( $fs['process_user'] ?? '-' ), (string) ( $fs['file_owner'] ?? '-' ), '' !== $method ? $method : '-' ),
			'expected' => __( 'The same user, method "direct"', 'axle-runtime-doctor' ),
			'data'     => array(
				'fs_method'    => $method,
				'process_user' => (string) ( $fs['process_user'] ?? '' ),
				'file_owner'   => (string) ( $fs['file_owner'] ?? '' ),
			),
		);

		if ( false === $match ) {
			return $this->result( Result::RECOMMENDED, __( 'PHP does not own the WordPress files', 'axle-runtime-doctor' ), $fields );
		}
		if ( '' !== $method && 'direct' !== $method ) {
			/* translators: %s: filesystem method. */
			return $this->result( Result::RECOMMENDED, sprintf( __( 'WordPress writes files through %s', 'axle-runtime-doctor' ), $method ), $fields );
		}
		if ( null === $match ) {
			return $this->result( Result::INFO, __( 'File ownership could not be compared', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'PHP owns the WordPress files', 'axle-runtime-doctor' ), $fields );
	}
}
