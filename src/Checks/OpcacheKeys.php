<?php
/**
 * Whether OPcache has a slot for every PHP file.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether OPcache has a slot for every PHP file.
 */
final class OpcacheKeys extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_keys';
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
		return __( 'OPcache file slots', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'opcache.max_accelerated_files caps how many files OPcache holds. When WordPress, its plugins and theme have more PHP files than that, the rest are compiled again on every request.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$opcache = $context->arr( 'opcache' );
		$stats   = $opcache['status']['opcache_statistics'] ?? null;
		if ( ! is_array( $stats ) || empty( $stats['max_cached_keys'] ) ) {
			return null;
		}

		$keys    = (int) ( $stats['num_cached_keys'] ?? 0 );
		$max     = (int) $stats['max_cached_keys'];
		$setting = (int) $context->ini( 'opcache.max_accelerated_files' );
		$files   = $context->get( 'php_files' );
		$pct     = self::percent( $keys, $max );
		$limit   = $context->threshold( 'opcache_keys_pct' );
		$details = array(
			/* translators: %d: number. */
			sprintf( __( 'opcache.max_accelerated_files: %d', 'axle-runtime-doctor' ), $setting ),
		);
		if ( is_int( $files ) ) {
			/* translators: %d: number of files. */
			$details[] = sprintf( __( 'PHP files under the WordPress folder: %d', 'axle-runtime-doctor' ), $files );
		}

		$fields = array(
			/* translators: 1: used slots, 2: all slots, 3: percentage. */
			'value'    => sprintf( __( '%1$d of %2$d slots used (%3$s%%)', 'axle-runtime-doctor' ), $keys, $max, $pct ),
			/* translators: %s: percentage. */
			'expected' => sprintf( __( 'Under %s%%, and more slots than PHP files', 'axle-runtime-doctor' ), $limit ),
			'details'  => $details,
			'data'     => array(
				'keys'      => $keys,
				'max'       => $max,
				'php_files' => $files,
				'suggested' => is_int( $files ) ? (int) max( 10000, ceil( $files * 1.5 / 1000 ) * 1000 ) : 0,
			),
		);

		if ( is_int( $files ) && $files > $max ) {
			return $this->result( Result::RECOMMENDED, __( 'OPcache has fewer slots than WordPress has PHP files', 'axle-runtime-doctor' ), $fields );
		}
		if ( $pct >= $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'OPcache file slots are almost full', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'OPcache has a slot for every file', 'axle-runtime-doctor' ), $fields );
	}
}
