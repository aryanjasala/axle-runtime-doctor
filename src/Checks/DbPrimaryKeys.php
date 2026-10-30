<?php
/**
 * Tables without a primary key.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Tables without a primary key.
 */
final class DbPrimaryKeys extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_primary_keys';
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
		return __( 'Primary keys', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Without a primary key, updates and deletes scan the whole table, replication can stall, and some managed databases refuse the table outright.', 'axle-runtime-doctor' );
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
		if ( ! isset( $db['no_pk'] ) ) {
			return null;
		}

		$tables = array_map( 'strval', (array) $db['no_pk'] );
		$fields = array(
			/* translators: %d: number of tables. */
			'value'    => sprintf( _n( '%d table without one', '%d tables without one', count( $tables ), 'axle-runtime-doctor' ), count( $tables ) ),
			'expected' => __( 'Every table has a primary key', 'axle-runtime-doctor' ),
			'details'  => $tables,
			'data'     => array( 'tables' => $tables ),
		);

		if ( $tables ) {
			return $this->result( Result::RECOMMENDED, __( 'Some tables have no primary key', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'Every table has a primary key', 'axle-runtime-doctor' ), $fields );
	}
}
