<?php
/**
 * Whether the MySQL or MariaDB release still gets fixes.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the MySQL or MariaDB release still gets fixes.
 */
final class DbVersion extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_version';
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
		return __( 'Database server support', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Site Health checks the database against what WordPress needs. This check looks at the vendor\'s own end-of-life dates, after which security holes in the database server stay open.', 'axle-runtime-doctor' );
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
		$db      = $context->arr( 'database' );
		$type    = (string) ( $db['type'] ?? '' );
		$version = (string) ( $db['version'] ?? '' );
		if ( '' === $type || '' === $version ) {
			return null;
		}

		$table  = $context->arr( 'db_versions' );
		$name   = 'mariadb' === $type ? 'MariaDB' : 'MySQL';
		$branch = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		$eol    = isset( $table[ $type ][ $branch ] ) ? (string) $table[ $type ][ $branch ] : '';
		$now    = (int) ( $context->get( 'now' ) ?? time() );

		if ( '' === $eol ) {
			return $this->result(
				Result::INFO,
				/* translators: 1: MySQL or MariaDB, 2: branch. */
				sprintf( __( 'No end-of-life date known for %1$s %2$s', 'axle-runtime-doctor' ), $name, $branch ),
				array( 'value' => $name . ' ' . $version )
			);
		}

		$days   = (int) floor( ( strtotime( $eol . ' 23:59:59 UTC' ) - $now ) / DAY_IN_SECONDS );
		$fields = array(
			/* translators: 1: server and version, 2: date. */
			'value'    => sprintf( __( '%1$s, supported until %2$s', 'axle-runtime-doctor' ), $name . ' ' . $version, $eol ),
			'expected' => __( 'A supported release', 'axle-runtime-doctor' ),
			'data'     => array(
				'type'    => $type,
				'version' => $version,
				'eol'     => $eol,
			),
		);

		if ( $days < 0 ) {
			/* translators: 1: MySQL or MariaDB, 2: branch, 3: date. */
			return $this->result( Result::CRITICAL, sprintf( __( '%1$s %2$s reached end of life on %3$s', 'axle-runtime-doctor' ), $name, $branch, $eol ), $fields );
		}
		if ( $days <= (int) $context->threshold( 'eol_warning_days' ) ) {
			/* translators: 1: MySQL or MariaDB, 2: branch, 3: date. */
			return $this->result( Result::RECOMMENDED, sprintf( __( '%1$s %2$s reaches end of life on %3$s', 'axle-runtime-doctor' ), $name, $branch, $eol ), $fields );
		}

		/* translators: 1: MySQL or MariaDB, 2: branch. */
		return $this->result( Result::GOOD, sprintf( __( '%1$s %2$s is supported', 'axle-runtime-doctor' ), $name, $branch ), $fields );
	}
}
