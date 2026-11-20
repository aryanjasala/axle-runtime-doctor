<?php
/**
 * The daily run.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every check once a day and stores the snapshot. On multisite only the
 * main site schedules it, as the runtime is shared.
 */
final class Scheduler implements Service {

	public const HOOK = 'axle_runtime_doctor_daily';

	/**
	 * Runs the checks.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Times the key pages.
	 *
	 * @var Timing\Timings|null
	 */
	private ?Timing\Timings $timings;

	/**
	 * Sets up the schedule.
	 *
	 * @param Runner              $runner  Runs the checks.
	 * @param Timing\Timings|null $timings Times the key pages.
	 */
	public function __construct( Runner $runner, ?Timing\Timings $timings = null ) {
		$this->runner  = $runner;
		$this->timings = $timings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	/**
	 * Schedules the daily run when it is missing.
	 */
	public function schedule(): void {
		if ( is_main_site() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * The daily run.
	 */
	public function run(): void {
		$this->runner->run( array( 'source' => 'cron' ) );
		if ( $this->timings && $this->timings->enabled() ) {
			$this->timings->run( 'cron' );
		}
		Probe::purge();
	}

	/**
	 * Removes the schedule, on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( Timing\Timings::HOOK );
	}
}
