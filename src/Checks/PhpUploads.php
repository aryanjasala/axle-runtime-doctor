<?php
/**
 * The post_max_size against upload_max_filesize.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The post_max_size against upload_max_filesize.
 */
final class PhpUploads extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_uploads';
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
		return __( 'Upload size limits', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'An upload has to fit in both limits. When post_max_size is smaller than upload_max_filesize, WordPress says large files are allowed, but PHP throws the whole request away and the upload fails with no clear error.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$post   = $context->ini( 'post_max_size' );
		$upload = $context->ini( 'upload_max_filesize' );
		if ( '' === $post || '' === $upload ) {
			return null;
		}

		$fields = array(
			/* translators: 1: post_max_size, 2: upload_max_filesize. */
			'value'    => sprintf( __( 'post_max_size %1$s, upload_max_filesize %2$s', 'axle-runtime-doctor' ), $post, $upload ),
			'expected' => __( 'post_max_size at least as big as upload_max_filesize', 'axle-runtime-doctor' ),
			'data'     => array(
				'post_max_size'       => $post,
				'upload_max_filesize' => $upload,
			),
		);

		$post_bytes = self::ini_bytes( $post );
		if ( $post_bytes > 0 && $post_bytes < self::ini_bytes( $upload ) ) {
			return $this->result( Result::RECOMMENDED, __( 'post_max_size is smaller than upload_max_filesize', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The upload limits match', 'axle-runtime-doctor' ), $fields );
	}
}
