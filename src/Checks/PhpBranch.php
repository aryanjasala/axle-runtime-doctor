<?php
/**
 * Whether the PHP branch still gets security fixes.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the PHP branch still gets security fixes.
 */
final class PhpBranch extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_branch';
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
		return __( 'PHP support', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Once a PHP branch reaches its end of life, security holes in PHP itself are no longer fixed. Site Health checks the version against what WordPress recommends; this check looks at the PHP project\'s own support dates.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$version  = (string) $context->get( 'php_version' );
		$branch   = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		$table    = $context->arr( 'php_versions' );
		$branches = isset( $table['branches'] ) && is_array( $table['branches'] ) ? $table['branches'] : array();
		$dates    = isset( $branches[ $branch ] ) && is_array( $branches[ $branch ] ) ? $branches[ $branch ] : null;
		$now      = (int) ( $context->get( 'now' ) ?? time() );

		if ( null === $dates || empty( $dates['security'] ) ) {
			return $this->result(
				Result::INFO,
				/* translators: %s: PHP branch. */
				sprintf( __( 'No support dates known for PHP %s', 'axle-runtime-doctor' ), $branch ),
				array( 'value' => $version )
			);
		}

		$security = (int) strtotime( $dates['security'] . ' 23:59:59 UTC' );
		$active   = ! empty( $dates['active'] ) ? (int) strtotime( $dates['active'] . ' 23:59:59 UTC' ) : 0;
		$days     = (int) floor( ( $security - $now ) / DAY_IN_SECONDS );
		$warn     = (int) $context->threshold( 'eol_warning_days' );
		$newest   = self::newest_supported( $branches, $now );
		$fields   = array(
			/* translators: 1: PHP version, 2: date. */
			'value'    => sprintf( __( 'PHP %1$s, security fixes until %2$s', 'axle-runtime-doctor' ), $version, $dates['security'] ),
			'expected' => __( 'A branch with security support', 'axle-runtime-doctor' ),
			'details'  => array_filter(
				array(
					/* translators: %s: date. */
					$active ? sprintf( __( 'Active support (bug fixes) until %s', 'axle-runtime-doctor' ), $dates['active'] ) : '',
					/* translators: %s: PHP branch. */
					'' !== $newest ? sprintf( __( 'Newest supported branch: PHP %s', 'axle-runtime-doctor' ), $newest ) : '',
				)
			),
			'data'     => array(
				'branch'   => $branch,
				'security' => $dates['security'],
				'days'     => $days,
				'newest'   => $newest,
			),
		);

		if ( $days < 0 ) {
			/* translators: 1: PHP branch, 2: date. */
			return $this->result( Result::CRITICAL, sprintf( __( 'PHP %1$s gets no security fixes since %2$s', 'axle-runtime-doctor' ), $branch, $dates['security'] ), $fields );
		}
		if ( $days <= $warn ) {
			/* translators: 1: PHP branch, 2: date. */
			return $this->result( Result::RECOMMENDED, sprintf( __( 'Security support for PHP %1$s ends on %2$s', 'axle-runtime-doctor' ), $branch, $dates['security'] ), $fields );
		}

		/* translators: %s: PHP branch. */
		return $this->result( Result::GOOD, sprintf( __( 'PHP %s gets security fixes', 'axle-runtime-doctor' ), $branch ), $fields );
	}

	/**
	 * The newest branch that is out and supported.
	 *
	 * @param array $branches Branch table.
	 * @param int   $now      Timestamp.
	 * @return string
	 */
	private static function newest_supported( array $branches, int $now ): string {
		$newest = '';
		foreach ( $branches as $branch => $dates ) {
			$released = ! empty( $dates['released'] ) ? (int) strtotime( (string) $dates['released'] ) : 0;
			if ( $released <= $now && ! empty( $dates['security'] ) && strtotime( $dates['security'] ) > $now && version_compare( (string) $branch, $newest, '>' ) ) {
				$newest = (string) $branch;
			}
		}

		return $newest;
	}
}
