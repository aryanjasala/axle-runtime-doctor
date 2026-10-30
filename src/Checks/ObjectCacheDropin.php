<?php
/**
 * Which persistent object cache the site uses.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Which persistent object cache the site uses.
 */
final class ObjectCacheDropin extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'object_cache_dropin';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'object_cache';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Object cache drop-in', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A persistent object cache (Redis, Memcached) keeps query results between requests. The drop-in in wp-content/object-cache.php decides which one, and its backend settings decide what happens when it fills up.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$cache = $context->arr( 'object_cache' );

		if ( empty( $cache['dropin'] ) ) {
			return $this->result(
				Result::INFO,
				__( 'No persistent object cache', 'axle-runtime-doctor' ),
				array(
					'value'   => __( 'None', 'axle-runtime-doctor' ),
					'details' => array( __( 'Site Health already suggests one when the site is big enough to need it.', 'axle-runtime-doctor' ) ),
				)
			);
		}

		$name    = '' !== (string) ( $cache['name'] ?? '' ) ? (string) $cache['name'] : __( 'Unknown drop-in', 'axle-runtime-doctor' );
		$backend = (string) ( $cache['backend'] ?? '' );
		$info    = isset( $cache['info'] ) && is_array( $cache['info'] ) ? $cache['info'] : array();
		$details = array();
		if ( ! empty( $cache['version'] ) ) {
			/* translators: %s: version. */
			$details[] = sprintf( __( 'Version: %s', 'axle-runtime-doctor' ), $cache['version'] );
		}
		foreach ( $info as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$details[] = $key . ': ' . $value;
			}
		}

		$fields = array(
			'value'   => '' !== $backend ? $name . ' (' . $backend . ')' : $name,
			'details' => $details,
			'data'    => array(
				'name'    => $name,
				'backend' => $backend,
			),
		);

		if ( isset( $info['maxmemory_policy'] ) && 'noeviction' === $info['maxmemory_policy'] ) {
			$fields['expected'] = __( 'An eviction policy such as allkeys-lru', 'axle-runtime-doctor' );

			return $this->result( Result::RECOMMENDED, __( 'The cache refuses writes when it is full', 'axle-runtime-doctor' ), $fields );
		}

		if ( empty( $cache['persistent'] ) ) {
			return $this->result( Result::RECOMMENDED, __( 'An object cache drop-in is installed but WordPress does not use it', 'axle-runtime-doctor' ), $fields );
		}

		/* translators: %s: drop-in name. */
		return $this->result( Result::GOOD, sprintf( __( 'Object cache: %s', 'axle-runtime-doctor' ), $name ), $fields );
	}
}
