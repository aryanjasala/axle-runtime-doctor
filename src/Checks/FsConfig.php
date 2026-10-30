<?php
/**
 * Whether other users on the server can read wp-config.php.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether other users on the server can read wp-config.php.
 */
final class FsConfig extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fs_config';
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
		return __( 'wp-config.php permissions', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'wp-config.php holds the database password and the secret keys. On a shared server, a file readable by everyone can be read by every other account.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$fs   = $context->arr( 'filesystem' );
		$mode = $fs['config_mode'] ?? null;
		if ( ! is_int( $mode ) ) {
			return null;
		}

		$fields = array(
			'value'    => sprintf( '%04o', $mode & 0777 ),
			'expected' => '0640 / 0600 / 0440 / 0400',
			'data'     => array(
				'mode' => sprintf( '%04o', $mode & 0777 ),
				'path' => (string) ( $fs['config_path'] ?? '' ),
			),
		);

		if ( $mode & 0004 ) {
			return $this->result( Result::RECOMMENDED, __( 'Everyone on the server can read wp-config.php', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'wp-config.php is not readable by everyone', 'axle-runtime-doctor' ), $fields );
	}
}
