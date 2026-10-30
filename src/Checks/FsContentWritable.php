<?php
/**
 * Whether PHP can write to wp-content.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether PHP can write to wp-content.
 */
final class FsContentWritable extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fs_content_writable';
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
		return __( 'wp-content writable', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Updates, caches, logs and uploads all write under wp-content. Some hosts make it read-only on purpose; then updates have to come through deploys.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$fs = $context->arr( 'filesystem' );
		if ( ! isset( $fs['content_writable'] ) ) {
			return null;
		}

		$fields = array(
			'value'    => $fs['content_writable'] ? __( 'Writable', 'axle-runtime-doctor' ) : __( 'Not writable', 'axle-runtime-doctor' ),
			'expected' => __( 'Writable, unless the host deploys code another way', 'axle-runtime-doctor' ),
		);

		if ( ! $fs['content_writable'] ) {
			return $this->result( Result::RECOMMENDED, __( 'PHP cannot write to wp-content', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'PHP can write to wp-content', 'axle-runtime-doctor' ), $fields );
	}
}
