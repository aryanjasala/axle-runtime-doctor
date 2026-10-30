<?php
/**
 * The biggest autoloaded options and the ones nobody needs.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The biggest autoloaded options and the ones nobody needs.
 */
final class DbAutoload extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'db_autoload';
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
		return __( 'Autoloaded options', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'WordPress loads every autoloaded option on every request. Site Health warns when the total is large; this check names the biggest ones, who owns them, and the ones that should never autoload: large single values, transients and leftovers from inactive plugins.', 'axle-runtime-doctor' );
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
	 * Autoloaded options belong to each site.
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
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$autoload = $context->arr( 'autoload' );
		$top      = isset( $autoload['top'] ) && is_array( $autoload['top'] ) ? $autoload['top'] : array();
		$limit    = (int) ( $context->threshold( 'autoload_single_kb' ) * KB_IN_BYTES );
		$large    = array();
		$inactive = array();
		$details  = array();

		foreach ( $top as $option ) {
			$size  = (int) ( $option['size'] ?? 0 );
			$owner = (string) ( $option['owner'] ?? '' );
			$state = (string) ( $option['owner_status'] ?? '' );
			if ( $size >= $limit ) {
				$large[] = (string) $option['name'];
			}
			if ( 'inactive' === $state ) {
				$inactive[] = (string) $option['name'];
			}
			/* translators: 1: option name, 2: size, 3: owner. */
			$details[] = sprintf( __( '%1$s: %2$s, %3$s', 'axle-runtime-doctor' ), (string) $option['name'], self::size( $size ), '' !== $owner ? $owner : __( 'unknown owner', 'axle-runtime-doctor' ) );
		}
		$transients = isset( $autoload['transients'] ) && is_array( $autoload['transients'] ) ? array_map( 'strval', $autoload['transients'] ) : array();
		foreach ( isset( $autoload['inactive'] ) && is_array( $autoload['inactive'] ) ? $autoload['inactive'] : array() as $name ) {
			$inactive[] = (string) $name;
		}
		$inactive = array_values( array_unique( $inactive ) );

		$fields = array(
			/* translators: 1: number of options, 2: total size. */
			'value'    => sprintf( __( '%1$d options, %2$s', 'axle-runtime-doctor' ), (int) ( $autoload['count'] ?? 0 ), self::size( (int) ( $autoload['total'] ?? 0 ) ) ),
			/* translators: %s: size. */
			'expected' => sprintf( __( 'No single option over %s, no transients, nothing from inactive plugins', 'axle-runtime-doctor' ), self::size( $limit ) ),
			'details'  => $details,
			'data'     => array(
				'top'        => $top,
				'large'      => $large,
				'transients' => $transients,
				'inactive'   => $inactive,
			),
		);

		$problems = array();
		if ( $large ) {
			/* translators: %d: number of options. */
			$problems[] = sprintf( _n( '%d autoloaded option is over the size limit', '%d autoloaded options are over the size limit', count( $large ), 'axle-runtime-doctor' ), count( $large ) );
		}
		if ( $transients ) {
			/* translators: %d: number of transients. */
			$problems[] = sprintf( _n( '%d transient is autoloaded', '%d transients are autoloaded', count( $transients ), 'axle-runtime-doctor' ), count( $transients ) );
		}
		if ( $inactive ) {
			/* translators: %d: number of options. */
			$problems[] = sprintf( _n( '%d autoloaded option belongs to an inactive plugin', '%d autoloaded options belong to inactive plugins', count( $inactive ), 'axle-runtime-doctor' ), count( $inactive ) );
		}

		if ( $problems ) {
			return $this->result( Result::RECOMMENDED, implode( '. ', $problems ), $fields );
		}

		return $this->result( Result::GOOD, __( 'No oversized or unneeded autoloaded options', 'axle-runtime-doctor' ), $fields );
	}
}
