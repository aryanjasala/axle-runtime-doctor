<?php
/**
 * Tables that still use MyISAM.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Tables that still use MyISAM.
 */
final class DbMyisam extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_myisam';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'database';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Table engines', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'MyISAM locks a whole table for every write and can lose data in a crash. InnoDB locks rows, recovers after a crash and is what WordPress creates today.', 'axle-runtime-doctor' );
	}

	/**
	 * Reads the database, which is the same from WP-CLI.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$db = $context->arr( 'database' );
		if ( ! isset( $db['myisam'] ) ) {
			return null;
		}

		$tables = array_map( 'strval', (array) $db['myisam'] );
		$fields = array(
			/* translators: %d: number of tables. */
			'value'    => sprintf( _n( '%d MyISAM table', '%d MyISAM tables', count( $tables ), 'axle-runtime-doctor' ), count( $tables ) ),
			'expected' => __( 'InnoDB everywhere', 'axle-runtime-doctor' ),
			'details'  => $tables,
			'data'     => array( 'tables' => $tables ),
		);

		if ( $tables ) {
			return $this->result( Result::RECOMMENDED, __( 'Some tables still use MyISAM', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'Every table uses InnoDB', 'axle-runtime-doctor' ), $fields );
	}
}
