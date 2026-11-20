<?php
/**
 * Times the key pages from the server and matches a slowdown to updates.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Timing;

use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Facts;
use AryanX\Axle\RuntimeDoctor\Probe;
use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Each run loads the key pages the way a visitor does, from the server, with
 * every page cache told to step aside, once to warm up and then five times.
 * It keeps the median of the five. A page that got slower since the last run
 * is matched to the updates logged in between.
 */
final class Timings implements Service {

	public const OPTION = 'axle_runtime_doctor_timings';
	public const LOCK   = 'axle_runtime_doctor_timing_lock';
	public const HOOK   = 'axle_runtime_doctor_timing_run';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the timing.
	 *
	 * @param Settings $settings Settings.
	 * @param Alerts   $alerts   Alerts.
	 */
	public function __construct( Settings $settings, Alerts $alerts ) {
		$this->settings = $settings;
		$this->alerts   = $alerts;
	}

	/**
	 * Adds the hook that runs a queued timing.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_queued' ) );
	}

	/**
	 * Whether the pages are timed on their own: daily and after updates.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		/**
		 * Filters whether pages are timed daily and after each update.
		 *
		 * @param bool $enabled The setting, on by default.
		 */
		return (bool) apply_filters( 'axle_runtime_doctor_timing_enabled', (bool) $this->settings->get( 'timing' ) );
	}

	/**
	 * Asks for a timing run soon, from WP-Cron.
	 *
	 * @param string $source update, deploy or smoke-test.
	 * @return bool Whether a run was queued.
	 */
	public function queue( string $source ): bool {
		if ( ! $this->enabled() ) {
			return false;
		}

		/**
		 * Filters the shortest time between two timing runs started by Smoke Test.
		 *
		 * @param int $seconds Default 300. 0 to 86400.
		 */
		$gap  = max( 0, min( DAY_IN_SECONDS, (int) apply_filters( 'axle_runtime_doctor_timing_min_gap', 300 ) ) );
		$last = self::latest();
		if ( 'smoke-test' === $source && $last && ( time() - (int) $last['time'] ) < $gap ) {
			return false;
		}

		/**
		 * Filters how long after an update the timing run starts, so the new
		 * files are in place and caches have settled.
		 *
		 * @param int    $seconds Default 45. 0 to 3600.
		 * @param string $source  What asked for it.
		 */
		$delay  = max( 0, min( HOUR_IN_SECONDS, (int) apply_filters( 'axle_runtime_doctor_timing_delay', 45, $source ) ) );
		$switch = is_multisite() && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		$queued = false !== wp_schedule_single_event( time() + $delay, self::HOOK, array( $source ) );
		if ( $switch ) {
			restore_current_blog();
		}

		if ( $queued ) {
			/**
			 * Fires when a timing run was queued.
			 *
			 * @param string $source What asked for it.
			 * @param int    $delay  Seconds until it starts.
			 */
			do_action( 'axle_runtime_doctor_timing_queued', $source, $delay );
		}

		return $queued;
	}

	/**
	 * Runs a queued timing.
	 *
	 * @param mixed $source What asked for it.
	 */
	public function run_queued( $source = 'update' ): void {
		if ( ! $this->enabled() || ( is_multisite() && ! is_main_site() ) ) {
			return;
		}

		$this->run( is_string( $source ) && '' !== $source ? $source : 'update' );
	}

	/**
	 * The pages that are timed.
	 *
	 * @return array[] url, path, label.
	 */
	public static function pages(): array {
		$urls = array();
		foreach ( Facts::probe_urls() as $url ) {
			if ( false === strpos( $url, '?s=' ) ) {
				$urls[] = $url;
			}
		}

		/**
		 * Filters the pages that are timed.
		 *
		 * @param string[] $urls Home, latest post, latest page and biggest category, without the search.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_timing_urls', $urls );
		$home     = untrailingslashit( home_url() );

		/**
		 * Filters how many pages are timed.
		 *
		 * @param int $count Default 3. 1 to 6.
		 */
		$count = max( 1, min( 6, (int) apply_filters( 'axle_runtime_doctor_timing_page_count', 3 ) ) );
		$pages = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'strval', array_filter( $filtered, 'is_scalar' ) ) ) ) ) as $url ) {
			// Only this site's own pages.
			if ( 0 !== strpos( $url, $home ) ) {
				continue;
			}
			$pages[] = array(
				'url'   => $url,
				'path'  => self::path( $url ),
				'label' => self::label( $url ),
			);
			if ( count( $pages ) >= $count ) {
				break;
			}
		}

		return $pages;
	}

	/**
	 * A page's path.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $path ? $path : '/';
	}

	/**
	 * A page's name for people.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function label( string $url ): string {
		$path = self::path( $url );

		return '/' === $path ? __( 'Home page', 'axle-runtime-doctor' ) : $path;
	}

	/**
	 * How many requests count towards a page's median.
	 *
	 * @return int
	 */
	public static function samples(): int {
		/**
		 * Filters how many timed requests make a page's median, after one warm-up request.
		 *
		 * @param int $samples Default 5. 1 to 11.
		 */
		return max( 1, min( 11, (int) apply_filters( 'axle_runtime_doctor_timing_samples', 5 ) ) );
	}

	/**
	 * How many runs are kept.
	 *
	 * @return int
	 */
	public static function keep(): int {
		/**
		 * Filters how many timing runs are kept.
		 *
		 * @param int $keep Default 60. 10 to 365.
		 */
		return max( 10, min( 365, (int) apply_filters( 'axle_runtime_doctor_timings_to_keep', 60 ) ) );
	}

	/**
	 * Times the key pages and stores the run.
	 *
	 * @param string $source cron, update, deploy, smoke-test, screen, cli or ability.
	 * @return array|WP_Error The run.
	 */
	public function run( string $source = 'screen' ) {
		if ( ! $this->lock() ) {
			return new WP_Error( 'axle_runtime_doctor_timing_busy', __( 'A timing run is already going. Try again in a few minutes.', 'axle-runtime-doctor' ) );
		}

		/**
		 * Fires before the pages are timed.
		 *
		 * @param string $source What started it.
		 */
		do_action( 'axle_runtime_doctor_timing_started', $source );

		$run = array(
			'id'      => substr( md5( $source . microtime( true ) ), 0, 12 ),
			'time'    => time(),
			'source'  => $source,
			'pages'   => array(),
			'updates' => array(),
		);
		foreach ( self::pages() as $page ) {
			$run['pages'][ $page['path'] ] = array( 'label' => $page['label'] ) + self::measure( $page['url'] );
		}
		$this->unlock();

		$previous = self::latest();
		$window   = $previous ? Updates::between( (int) $previous['time'], $run['time'] ) : array();

		$run['updates']     = array_column( $window, 'id' );
		$run['regressions'] = $previous ? self::regressions( (array) $previous['pages'], $run['pages'], $window, $this->settings->thresholds() ) : array();

		$all   = self::all();
		$all[] = $run;
		Store::set( self::OPTION, array_slice( $all, -self::keep() ) );

		/**
		 * Fires after the pages were timed and the run stored.
		 *
		 * @param array $run id, time, source, pages, updates, regressions.
		 */
		do_action( 'axle_runtime_doctor_timing_finished', $run );

		foreach ( $run['regressions'] as $row ) {
			/**
			 * Fires for each page that got slower since the last run.
			 *
			 * @param array $row     key, label, before, after, delta, percent, updates, summary.
			 * @param array $run     The run.
			 */
			do_action( 'axle_runtime_doctor_slowdown_detected', $row, $run );
		}
		if ( $run['regressions'] ) {
			$this->alerts->send_slowdown( $run['regressions'], $run );
		}

		return $run;
	}

	/**
	 * Times one page: a warm-up request, then the samples. Server time, not
	 * the time the loopback took.
	 *
	 * @param string $url Page URL.
	 * @return array median (ms), samples (ms), status, cached, error.
	 */
	public static function measure( string $url ): array {
		$page = Probe::time_page( $url );
		if ( is_wp_error( $page ) ) {
			return array(
				'median'  => 0.0,
				'samples' => array(),
				'status'  => 0,
				'cached'  => false,
				'error'   => $page->get_error_message(),
			);
		}

		$samples = array();
		$status  = (int) $page['status'];
		$cached  = false;
		for ( $i = 0, $n = self::samples(); $i < $n; $i++ ) {
			$one = Probe::time_page( $url );
			if ( is_wp_error( $one ) ) {
				continue;
			}
			$samples[] = (float) $one['ms'];
			$status    = (int) $one['status'];
			$cached    = $cached || ! empty( $one['cached'] );
		}

		return array(
			'median'  => self::median( $samples ),
			'samples' => $samples,
			'status'  => $status,
			'cached'  => $cached,
			'error'   => $samples ? '' : __( 'No request could be timed', 'axle-runtime-doctor' ),
		);
	}

	/**
	 * The median of some numbers.
	 *
	 * @param float[] $values Numbers.
	 * @return float
	 */
	public static function median( array $values ): float {
		$values = array_values( array_map( 'floatval', $values ) );
		if ( ! $values ) {
			return 0.0;
		}
		sort( $values );
		$middle = intdiv( count( $values ), 2 );

		return round( 1 === count( $values ) % 2 ? $values[ $middle ] : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2, 1 );
	}

	/**
	 * Pages that got slower between two runs, with the updates in between.
	 *
	 * @param array   $before     Pages of the last run: path => label, median, cached, error.
	 * @param array   $after      Pages of this run.
	 * @param array[] $window     Updates logged between the two runs.
	 * @param array   $thresholds timing_ms (least extra time) and timing_pct (least extra percent).
	 * @return array[] key, label, before, after, delta, percent, updates, summary.
	 */
	public static function regressions( array $before, array $after, array $window, array $thresholds ): array {
		$least_ms  = (float) ( $thresholds['timing_ms'] ?? 100 );
		$least_pct = (float) ( $thresholds['timing_pct'] ?? 30 );
		$found     = array();
		foreach ( $after as $path => $page ) {
			$old = $before[ $path ] ?? null;
			if ( ! is_array( $old ) || ! is_array( $page ) || ! empty( $old['error'] ) || ! empty( $page['error'] ) || (float) $old['median'] <= 0 ) {
				continue;
			}
			// A cache that answered measures the cache, not the site.
			if ( ! empty( $old['cached'] ) || ! empty( $page['cached'] ) ) {
				continue;
			}
			$was     = (float) $old['median'];
			$now     = (float) $page['median'];
			$delta   = $now - $was;
			$percent = $delta / $was * 100;
			if ( $delta < $least_ms || $percent < $least_pct ) {
				continue;
			}
			$label   = (string) ( $page['label'] ?? $path );
			$found[] = array(
				'key'     => (string) $path,
				'label'   => $label,
				'before'  => $was,
				'after'   => $now,
				'delta'   => round( $delta, 1 ),
				'percent' => round( $percent ),
				'updates' => array_column( $window, 'id' ),
				'summary' => self::summary( $label, $was, $now, $window ),
			);
		}

		return $found;
	}

	/**
	 * "Home page server time went from 180 ms to 520 ms after Elementor 3.25 to 3.26 on Tuesday."
	 *
	 * @param string  $label  Page name.
	 * @param float   $before Milliseconds before.
	 * @param float   $after  Milliseconds now.
	 * @param array[] $window Updates in between.
	 * @return string
	 */
	public static function summary( string $label, float $before, float $after, array $window ): string {
		$ms = static function ( float $value ): string {
			return number_format_i18n( round( $value ) );
		};

		if ( ! $window ) {
			/* translators: 1: page name, 2: milliseconds before, 3: milliseconds now. */
			return sprintf( __( '%1$s server time went from %2$s ms to %3$s ms. No update was logged since the last timing.', 'axle-runtime-doctor' ), $label, $ms( $before ), $ms( $after ) );
		}
		if ( count( $window ) <= 3 ) {
			$names = array_map( array( Updates::class, 'describe' ), $window );
			$last  = (int) end( $window )['time'];
			$list  = implode( ', ', $names );
			/* translators: 1: page name, 2: milliseconds before, 3: milliseconds now, 4: updates, 5: weekday. */
			return sprintf( __( '%1$s server time went from %2$s ms to %3$s ms after %4$s on %5$s.', 'axle-runtime-doctor' ), $label, $ms( $before ), $ms( $after ), $list, wp_date( 'l', $last ) );
		}

		/* translators: 1: page name, 2: milliseconds before, 3: milliseconds now, 4: number of updates. */
		return sprintf( __( '%1$s server time went from %2$s ms to %3$s ms after %4$d updates.', 'axle-runtime-doctor' ), $label, $ms( $before ), $ms( $after ), count( $window ) );
	}

	/**
	 * Every run, oldest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$all = Store::get( self::OPTION, array() );

		return is_array( $all ) ? array_values( array_filter( $all, 'is_array' ) ) : array();
	}

	/**
	 * The latest run.
	 *
	 * @return array|null
	 */
	public static function latest(): ?array {
		$all = self::all();

		return $all ? end( $all ) : null;
	}

	/**
	 * Runs and updates in one list, newest first.
	 *
	 * @param int $limit Most entries.
	 * @return array[] kind (run or update), time, and the run or update itself.
	 */
	public static function timeline( int $limit = 40 ): array {
		$rows = array();
		foreach ( self::all() as $run ) {
			$rows[] = array(
				'kind' => 'run',
				'time' => (int) $run['time'],
				'run'  => $run,
			);
		}
		foreach ( Updates::all() as $event ) {
			$rows[] = array(
				'kind'   => 'update',
				'time'   => (int) $event['time'],
				'update' => $event,
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	/**
	 * The slowdowns found by the latest run.
	 *
	 * @return array[]
	 */
	public static function latest_slowdowns(): array {
		$run = self::latest();

		return $run ? (array) ( $run['regressions'] ?? array() ) : array();
	}

	/**
	 * Takes the lock, so two runs do not overlap. A lock older than ten
	 * minutes is stale (a run that died) and is taken over.
	 *
	 * @return bool
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK, time() + 10 * MINUTE_IN_SECONDS, '', false ) ) {
			return true;
		}
		if ( (int) get_option( self::LOCK ) < time() ) {
			update_option( self::LOCK, time() + 10 * MINUTE_IN_SECONDS, false );

			return true;
		}

		return false;
	}

	/**
	 * Releases the lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK );
	}
}
