<?php
/**
 * PHP files inside the uploads folder.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * PHP files inside the uploads folder.
 */
final class FsUploadsPhp extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fs_uploads_php';
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
		return __( 'PHP files in uploads', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'WordPress never puts PHP files in uploads. A PHP file there is usually a backdoor left by an attack through a vulnerable upload form.', 'axle-runtime-doctor' );
	}

	/**
	 * Reads files, which are the same from WP-CLI.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Uploads belong to each site.
	 *
	 * @return bool
	 */
	public function per_site(): bool {
		return true;
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$fs = $context->arr( 'filesystem' );
		if ( ! isset( $fs['uploads_php'] ) ) {
			return null;
		}

		$files  = array_map( 'strval', (array) $fs['uploads_php'] );
		$fields = array(
			/* translators: 1: number of PHP files, 2: number of files looked at. */
			'value'    => sprintf( __( '%1$d PHP files in %2$d files', 'axle-runtime-doctor' ), count( $files ), (int) ( $fs['uploads_scanned'] ?? 0 ) ),
			'expected' => __( 'None', 'axle-runtime-doctor' ),
			'details'  => $files,
			'data'     => array( 'files' => $files ),
		);

		if ( $files ) {
			return $this->result( Result::CRITICAL, __( 'There are PHP files in the uploads folder', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'No PHP files in the uploads folder', 'axle-runtime-doctor' ), $fields );
	}
}
