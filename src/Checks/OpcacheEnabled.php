<?php
/**
 * OPcache on or off, for WordPress before 7.0.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress 7.0 added its own "opcode cache" Site Health test. Before 7.0
 * nothing tells you OPcache is off, so this check fills in.
 */
final class OpcacheEnabled extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'opcache_enabled';
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
		return __( 'OPcache enabled', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Without OPcache, PHP reads and compiles every WordPress file on every request. That alone can add half a second or more to each page.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		if ( version_compare( (string) $context->get( 'wp_version' ), '7.0-alpha', '>=' ) ) {
			return null;
		}

		$opcache = $context->arr( 'opcache' );
		$on      = ! empty( $opcache['loaded'] ) && ( ! empty( $opcache['status']['opcache_enabled'] ) || ( ! empty( $opcache['restricted'] ) && self::on( $context->ini( 'opcache.enable' ) ) ) );

		if ( $on ) {
			return $this->result(
				Result::GOOD,
				__( 'OPcache is on', 'axle-runtime-doctor' ),
				array(
					'value'    => __( 'On', 'axle-runtime-doctor' ),
					'expected' => __( 'On', 'axle-runtime-doctor' ),
				) 
			);
		}

		return $this->result(
			Result::RECOMMENDED,
			empty( $opcache['loaded'] ) ? __( 'OPcache is not installed', 'axle-runtime-doctor' ) : __( 'OPcache is off', 'axle-runtime-doctor' ),
			array(
				'value'    => empty( $opcache['loaded'] ) ? __( 'Not installed', 'axle-runtime-doctor' ) : __( 'Off', 'axle-runtime-doctor' ),
				'expected' => __( 'On', 'axle-runtime-doctor' ),
			)
		);
	}
}
