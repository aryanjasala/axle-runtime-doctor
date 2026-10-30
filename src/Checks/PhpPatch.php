<?php
/**
 * Whether PHP is on the latest patch release of its branch.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether PHP is on the latest patch release of its branch.
 */
final class PhpPatch extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_patch';
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
		return __( 'PHP patch level', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Security fixes ship as patch releases (8.3.1, 8.3.2 and so on). A supported branch only protects you when the host keeps up with them.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check. Only when the php.net check is on in the settings.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$releases = $context->get( 'php_releases' );
		if ( null === $releases ) {
			return null;
		}

		$version = (string) $context->get( 'php_version' );
		$branch  = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		if ( ! is_array( $releases ) || empty( $releases['branches'][ $branch ]['latest'] ) ) {
			return $this->result(
				Result::INFO,
				__( 'The latest PHP releases could not be loaded from php.net', 'axle-runtime-doctor' ),
				array(
					'value'   => $version,
					'details' => is_array( $releases ) && ! empty( $releases['error'] ) ? array( (string) $releases['error'] ) : array(),
				)
			);
		}

		$latest   = (string) $releases['branches'][ $branch ]['latest'];
		$security = array();
		foreach ( (array) ( $releases['branches'][ $branch ]['security'] ?? array() ) as $release ) {
			if ( version_compare( (string) $release, $version, '>' ) ) {
				$security[] = (string) $release;
			}
		}
		$fields = array(
			'value'    => $version,
			'expected' => $latest,
			'details'  => $security
				/* translators: %s: list of versions. */
				? array( sprintf( __( 'Security releases since this one: %s', 'axle-runtime-doctor' ), implode( ', ', $security ) ) )
				: array(),
			'data'     => array(
				'latest'   => $latest,
				'security' => $security,
			),
		);

		if ( version_compare( $version, $latest, '<' ) ) {
			return $this->result(
				$security ? Result::CRITICAL : Result::RECOMMENDED,
				/* translators: 1: running version, 2: latest version. */
				sprintf( __( 'PHP %1$s is behind %2$s', 'axle-runtime-doctor' ), $version, $latest ),
				$fields
			);
		}

		return $this->result( Result::GOOD, __( 'PHP is on the latest patch release', 'axle-runtime-doctor' ), $fields );
	}
}
