<?php
/**
 * PHP memory_limit against what WordPress asks for and uses.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * PHP memory_limit against what WordPress asks for and uses.
 */
final class PhpMemory extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_memory';
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
		return __( 'Memory limit', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'WordPress raises memory_limit to WP_MEMORY_LIMIT on the front end and WP_MAX_MEMORY_LIMIT in wp-admin, but only up to what the host allows. Requests that run out die with a white screen or a half-finished import.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$limit_raw = $context->ini( 'memory_limit' );
		$limit     = self::ini_bytes( $limit_raw );
		$memory    = $context->arr( 'memory' );
		$wp        = (string) ( $memory['wp_memory_limit'] ?? '' );
		$wp_max    = (string) ( $memory['wp_max_memory_limit'] ?? '' );
		$peak      = (int) ( $memory['peak'] ?? 0 );
		foreach ( $context->arr( 'samples' ) as $sample ) {
			$peak = max( $peak, (int) ( is_array( $sample ) ? ( $sample['peak'] ?? 0 ) : 0 ) );
		}

		$fields = array(
			/* translators: 1: memory_limit, 2: WP_MEMORY_LIMIT, 3: WP_MAX_MEMORY_LIMIT, 4: peak use. */
			'value'    => sprintf( __( 'memory_limit %1$s, WP_MEMORY_LIMIT %2$s, WP_MAX_MEMORY_LIMIT %3$s, peak seen %4$s', 'axle-runtime-doctor' ), '' !== $limit_raw ? $limit_raw : '-', '' !== $wp ? $wp : '-', '' !== $wp_max ? $wp_max : '-', self::size( $peak ) ),
			'expected' => __( '128M or more, with room above the peak', 'axle-runtime-doctor' ),
			'data'     => array(
				'memory_limit' => $limit_raw,
				'peak'         => $peak,
			),
		);

		if ( -1 === $limit ) {
			return $this->result( Result::GOOD, __( 'PHP has no memory limit', 'axle-runtime-doctor' ), $fields );
		}
		if ( $limit > 0 && $peak > 0.8 * $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'Requests come close to the memory limit', 'axle-runtime-doctor' ), $fields );
		}
		if ( $limit > 0 && $limit < 128 * MB_IN_BYTES ) {
			return $this->result( Result::RECOMMENDED, __( 'The memory limit is low', 'axle-runtime-doctor' ), $fields );
		}
		if ( '' !== $wp_max && '' !== $wp && self::ini_bytes( $wp_max ) > 0 && self::ini_bytes( $wp_max ) < self::ini_bytes( $wp ) ) {
			return $this->result( Result::RECOMMENDED, __( 'wp-admin gets less memory than the front end', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The memory limit has room', 'axle-runtime-doctor' ), $fields );
	}
}
