<?php
/**
 * Samples front-end requests for the object cache hit rate and peak memory.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * On 1 in 50 front-end requests (by default), records the object cache hits
 * and misses and the peak memory at shutdown, in a rolling buffer of 500.
 * With a persistent object cache the buffer lives there; otherwise in an
 * option.
 */
final class Sampler implements Service {

	public const OPTION = 'axle_runtime_doctor_samples';
	public const GROUP  = 'axle_runtime_doctor';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up sampling.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks into shutdown on front-end requests.
	 */
	public function register(): void {
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( array( self::GROUP ) );
		}
		add_action( 'shutdown', array( $this, 'maybe_sample' ), 1 );
	}

	/**
	 * The most samples kept.
	 *
	 * @return int
	 */
	public static function max(): int {
		/**
		 * Filters how many samples the rolling buffer keeps.
		 *
		 * @param int $max Default 500. 10 to 5000.
		 */
		return max( 10, min( 5000, (int) apply_filters( 'axle_runtime_doctor_samples_max', 500 ) ) );
	}

	/**
	 * Takes a sample on 1 in N front-end requests.
	 */
	public function maybe_sample(): void {
		$rate = $this->settings->sample_rate();
		if ( $rate < 1 || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || Probe::capturing() ) {
			return;
		}
		if ( 1 !== $rate && 1 !== wp_rand( 1, $rate ) ) {
			return;
		}

		$this->add( self::sample() );
	}

	/**
	 * This request's numbers.
	 *
	 * @return array t, hits, misses, peak, persistent.
	 */
	public static function sample(): array {
		global $wp_object_cache;
		$hits   = 0;
		$misses = 0;
		if ( is_object( $wp_object_cache ) ) {
			if ( isset( $wp_object_cache->cache_hits ) ) {
				$hits   = (int) $wp_object_cache->cache_hits;
				$misses = (int) ( $wp_object_cache->cache_misses ?? 0 );
			} elseif ( method_exists( $wp_object_cache, 'info' ) ) {
				$info   = (object) $wp_object_cache->info();
				$hits   = (int) ( $info->hits ?? 0 );
				$misses = (int) ( $info->misses ?? 0 );
			}
		}

		return array(
			't'          => time(),
			'hits'       => $hits,
			'misses'     => $misses,
			'peak'       => memory_get_peak_usage(),
			'persistent' => (bool) wp_using_ext_object_cache(),
		);
	}

	/**
	 * Adds a sample to the buffer.
	 *
	 * @param array $sample A sample.
	 */
	public function add( array $sample ): void {
		$samples   = $this->all();
		$samples[] = $sample;
		$samples   = array_slice( $samples, -self::max() );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( 'samples', $samples, self::GROUP );
		} else {
			Store::set( self::OPTION, $samples );
		}
	}

	/**
	 * Every sample, oldest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$samples = wp_using_ext_object_cache() ? wp_cache_get( 'samples', self::GROUP ) : Store::get( self::OPTION, array() );

		return is_array( $samples ) ? array_values( array_filter( $samples, 'is_array' ) ) : array();
	}

	/**
	 * Empties the buffer.
	 */
	public function clear(): void {
		wp_cache_delete( 'samples', self::GROUP );
		Store::delete( self::OPTION );
	}
}
