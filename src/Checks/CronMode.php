<?php
/**
 * How scheduled events run, and whether they run at all.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * How scheduled events run, and whether they run at all.
 */
final class CronMode extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'cron_mode';
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
		return __( 'Cron', 'axle-runtime-doctor' );
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
	 * Scheduled events belong to each site.
	 *
	 * @return bool
	 */
	public function per_site(): bool {
		return true;
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'With DISABLE_WP_CRON, WordPress expects a real cron job on the server to call wp-cron.php. When nobody set one up, or it broke in a migration, scheduled posts, backups, emails and cleanups silently stop. Site Health reports late events; this check says whether anything runs them at all.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$cron = $context->arr( 'cron' );
		$now  = (int) ( $context->get( 'now' ) ?? time() );
		if ( ! empty( $cron['disabled'] ) ) {
			$mode = __( 'DISABLE_WP_CRON, a server cron job runs events', 'axle-runtime-doctor' );
		} elseif ( ! empty( $cron['alternate'] ) ) {
			$mode = __( 'ALTERNATE_WP_CRON, events run through a redirect on page visits', 'axle-runtime-doctor' );
		} else {
			$mode = __( 'WP-Cron, events run on page visits', 'axle-runtime-doctor' );
		}

		$oldest  = isset( $cron['oldest_due'] ) ? (int) $cron['oldest_due'] : 0;
		$late    = $oldest > 0 ? max( 0, $now - $oldest ) : 0;
		$limit   = (int) ( $context->threshold( 'cron_late_minutes' ) * MINUTE_IN_SECONDS );
		$details = array();
		if ( ! empty( $cron['action_scheduler'] ) ) {
			$details[] = __( 'Action Scheduler is present and runs its queue through WP-Cron as well.', 'axle-runtime-doctor' );
		}
		if ( $late > 0 ) {
			/* translators: %d: minutes. */
			$details[] = sprintf( __( 'The oldest due event is %d minutes late.', 'axle-runtime-doctor' ), (int) floor( $late / MINUTE_IN_SECONDS ) );
		}
		$details[] = __( 'Site Health lists missed and late events.', 'axle-runtime-doctor' );

		$fields = array(
			'value'    => $mode,
			'expected' => __( 'Due events run within a few minutes', 'axle-runtime-doctor' ),
			'details'  => $details,
			'data'     => array(
				'disabled'     => ! empty( $cron['disabled'] ),
				'late_minutes' => (int) floor( $late / MINUTE_IN_SECONDS ),
			),
		);

		if ( ! empty( $cron['disabled'] ) && $late > $limit ) {
			return $this->result( Result::CRITICAL, __( 'No real cron is running', 'axle-runtime-doctor' ), $fields );
		}
		if ( $late > $limit ) {
			return $this->result( Result::RECOMMENDED, __( 'Scheduled events run late', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::GOOD, ! empty( $cron['disabled'] ) ? __( 'A server cron job runs scheduled events', 'axle-runtime-doctor' ) : __( 'WP-Cron runs scheduled events', 'axle-runtime-doctor' ), $fields );
	}
}
