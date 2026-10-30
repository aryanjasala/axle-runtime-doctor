<?php
/**
 * The ten largest tables.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The ten largest tables.
 */
final class DbTables extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_tables';
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
		return __( 'Largest tables', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A table that keeps growing (logs, sessions, revisions, analytics) slows backups and queries. Shown for reference.', 'axle-runtime-doctor' );
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
		if ( ! isset( $db['largest'] ) ) {
			return null;
		}

		$details = array();
		$total   = 0;
		foreach ( (array) $db['largest'] as $table ) {
			$total += (int) ( $table['size'] ?? 0 );
			/* translators: 1: table name, 2: size, 3: number of rows. */
			$details[] = sprintf( __( '%1$s: %2$s, about %3$d rows', 'axle-runtime-doctor' ), (string) ( $table['name'] ?? '' ), self::size( (int) ( $table['size'] ?? 0 ) ), (int) ( $table['rows'] ?? 0 ) );
		}

		return $this->result(
			Result::INFO,
			$details ? (string) strtok( $details[0], ':' ) . ' ' . __( 'is the largest table', 'axle-runtime-doctor' ) : __( 'No tables found', 'axle-runtime-doctor' ),
			array(
				/* translators: %s: size. */
				'value'   => sprintf( __( 'Top ten: %s', 'axle-runtime-doctor' ), self::size( $total ) ),
				'details' => $details,
				'data'    => array( 'tables' => (array) $db['largest'] ),
			)
		);
	}
}
