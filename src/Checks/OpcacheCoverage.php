<?php
/**
 * Whether OPcache caches wp-content as well as core.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Asks OPcache whether files this request certainly included are cached: a
 * core file, this plugin's main file, the theme's functions.php and a
 * must-use plugin. Core cached and wp-content not, in two requests in a row,
 * means OPcache is skipping wp-content, and the result says why.
 */
final class OpcacheCoverage extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_coverage';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'opcache';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'OPcache coverage', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'OPcache can be on for WordPress core and still skip wp-content, through a blacklist file or a host policy. Then every plugin and theme file is compiled again on each request, which often costs a full second per page while every dashboard says OPcache is on.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		if ( empty( $opcache['loaded'] ) || ( ! empty( $opcache['status'] ) && empty( $opcache['status']['opcache_enabled'] ) ) ) {
			return null;
		}

		$coverage = $context->arr( 'coverage' );
		$reasons  = isset( $coverage['reasons'] ) ? array_map( 'strval', (array) $coverage['reasons'] ) : array();

		if ( empty( $coverage['available'] ) ) {
			return $this->inferred( $context, $coverage, $reasons );
		}

		$observations = isset( $coverage['observations'] ) ? array_values( (array) $coverage['observations'] ) : array();
		if ( ! $observations ) {
			return $this->result( Result::INFO, __( 'OPcache coverage could not be read', 'axle-runtime-doctor' ) );
		}

		$details = array();
		foreach ( (array) ( $observations[0]['files'] ?? array() ) as $file => $cached ) {
			/* translators: 1: file path, 2: cached or not cached. */
			$details[] = sprintf( __( '%1$s: %2$s', 'axle-runtime-doctor' ), $file, $cached ? __( 'cached', 'axle-runtime-doctor' ) : __( 'not cached', 'axle-runtime-doctor' ) );
		}

		$skipping = 0;
		$core_off = 0;
		foreach ( $observations as $observation ) {
			if ( ! empty( $observation['core'] ) && isset( $observation['content'] ) && false === $observation['content'] ) {
				++$skipping;
			}
			if ( isset( $observation['core'] ) && false === $observation['core'] ) {
				++$core_off;
			}
		}

		$fields = array(
			'details' => array_merge( $details, $reasons ),
			'data'    => array(
				'files'   => $observations[0]['files'] ?? array(),
				'reasons' => $reasons,
			),
		);

		if ( $skipping >= 2 ) {
			return $this->result(
				Result::CRITICAL,
				__( 'OPcache is skipping wp-content', 'axle-runtime-doctor' ),
				array_merge(
					$fields,
					array(
						'value'    => __( 'Core cached, wp-content not cached (two requests in a row)', 'axle-runtime-doctor' ),
						'expected' => __( 'Core and wp-content cached', 'axle-runtime-doctor' ),
					)
				)
			);
		}

		if ( 1 === $skipping ) {
			return $this->result(
				Result::RECOMMENDED,
				__( 'OPcache skipped wp-content in one request', 'axle-runtime-doctor' ),
				array_merge(
					$fields,
					array(
						'value'    => __( 'Core cached, wp-content not cached once', 'axle-runtime-doctor' ),
						'expected' => __( 'Core and wp-content cached', 'axle-runtime-doctor' ),
						'details'  => array_merge( $fields['details'], array( __( 'The second request could not be made, so this is not confirmed. Run the checks again.', 'axle-runtime-doctor' ) ) ),
					)
				)
			);
		}

		if ( count( $observations ) === $core_off ) {
			return $this->result(
				Result::RECOMMENDED,
				__( 'OPcache did not cache this request\'s files', 'axle-runtime-doctor' ),
				array_merge(
					$fields,
					array(
						'value'    => __( 'Core and wp-content not cached', 'axle-runtime-doctor' ),
						'expected' => __( 'Core and wp-content cached', 'axle-runtime-doctor' ),
					)
				)
			);
		}

		return $this->result(
			Result::GOOD,
			__( 'OPcache caches core and wp-content', 'axle-runtime-doctor' ),
			array_merge(
				$fields,
				array(
					'value'    => __( 'Core and wp-content cached', 'axle-runtime-doctor' ),
					'expected' => __( 'Core and wp-content cached', 'axle-runtime-doctor' ),
				)
			)
		);
	}

	/**
	 * The result when opcache.restrict_api hides OPcache from this site: a
	 * timing probe instead, labelled inferred.
	 *
	 * @param Context  $context  Facts.
	 * @param array    $coverage Coverage facts.
	 * @param string[] $reasons  What could explain it.
	 * @return Result
	 */
	private function inferred( Context $context, array $coverage, array $reasons ): Result {
		$timing = isset( $coverage['timing'] ) && is_array( $coverage['timing'] ) ? $coverage['timing'] : array();
		$ms     = isset( $timing['second_ms'] ) ? (float) $timing['second_ms'] : -1.0;
		$limit  = $context->threshold( 'bootstrap_ms' );
		$value  = $ms >= 0
			/* translators: %d: milliseconds. */
			? sprintf( __( 'WordPress loaded its plugins in %d ms on a repeat request', 'axle-runtime-doctor' ), (int) round( $ms ) )
			: __( 'Unknown', 'axle-runtime-doctor' );
		$fields = array(
			'value'    => $value,
			/* translators: %d: milliseconds. */
			'expected' => sprintf( __( 'Under %d ms', 'axle-runtime-doctor' ), (int) $limit ),
			'details'  => array_merge( array( __( 'opcache.restrict_api keeps this site from asking OPcache which files it holds, so this result is inferred from timing.', 'axle-runtime-doctor' ) ), $reasons ),
			'inferred' => true,
			'data'     => array( 'timing' => $timing ),
		);

		if ( $ms > $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'PHP seems to compile files on every request (inferred)', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::INFO, __( 'OPcache coverage could not be read directly (inferred from timing)', 'axle-runtime-doctor' ), $fields );
	}
}
