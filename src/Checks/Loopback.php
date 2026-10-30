<?php
/**
 * How fast the site reaches itself, and through what.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How fast the site reaches itself, and through what.
 */
final class Loopback extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'loopback';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'cron';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Loopback', 'axle-runtime-doctor' );
	}

	/**
	 * Reads stored state, which is the same from WP-CLI.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'WP-Cron, Site Health and the plugin and theme editors call the site from the server itself. Site Health tests that this works. When the call goes out through a CDN or firewall, it can be slow or blocked by a bot challenge now and then, and cron misses runs.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$loopback = $context->arr( 'loopback' );
		if ( ! $loopback ) {
			return null;
		}

		$ms      = (int) round( (float) ( $loopback['ms'] ?? 0 ) );
		$cdn     = array_map( 'strval', (array) ( $loopback['cdn'] ?? array() ) );
		$limit   = (int) $context->threshold( 'loopback_ms' );
		$details = array();
		if ( ! empty( $loopback['blocked_external'] ) ) {
			$details[] = __( 'WP_HTTP_BLOCK_EXTERNAL is on. Requests to the site\'s own address are still allowed.', 'axle-runtime-doctor' );
		}
		if ( $cdn ) {
			/* translators: %s: list of services. */
			$details[] = sprintf( __( 'The response came through: %s', 'axle-runtime-doctor' ), implode( ', ', $cdn ) );
		}

		if ( empty( $loopback['ok'] ) ) {
			$details[] = (string) ( $loopback['error'] ?? '' );

			return $this->result(
				! empty( $loopback['challenge'] ) ? Result::CRITICAL : Result::INFO,
				! empty( $loopback['challenge'] ) ? __( 'A firewall blocks the site from calling itself', 'axle-runtime-doctor' ) : __( 'The site could not call itself', 'axle-runtime-doctor' ),
				array(
					/* translators: %s: HTTP status. */
					'value'    => ! empty( $loopback['status'] ) ? sprintf( __( 'HTTP %s', 'axle-runtime-doctor' ), (string) $loopback['status'] ) : __( 'No response', 'axle-runtime-doctor' ),
					'expected' => __( 'HTTP 200', 'axle-runtime-doctor' ),
					'details'  => array_filter( $details ),
					'data'     => array( 'cdn' => $cdn ),
				)
			);
		}

		$fields = array(
			/* translators: %d: milliseconds. */
			'value'    => sprintf( __( '%d ms', 'axle-runtime-doctor' ), $ms ),
			/* translators: %d: milliseconds. */
			'expected' => sprintf( __( 'Under %d ms, straight to the server', 'axle-runtime-doctor' ), $limit ),
			'details'  => $details,
			'data'     => array(
				'ms'  => $ms,
				'cdn' => $cdn,
			),
		);

		if ( $cdn ) {
			return $this->result( Result::RECOMMENDED, __( 'The site calls itself through a CDN or firewall', 'axle-runtime-doctor' ), $fields );
		}
		if ( $ms > $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'The site is slow to call itself', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, __( 'The site calls itself quickly', 'axle-runtime-doctor' ), $fields );
	}
}
