<?php
/**
 * Settings, thresholds and the capability.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * The saved settings, with every default behind a filter.
 */
final class Settings {

	public const OPTION = 'axle_runtime_doctor_settings';

	/**
	 * Saved settings merged with the defaults, cached for the request.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * Defaults for every setting.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'host'        => 'auto',
			'sampling'    => true,
			'sample_rate' => 50,
			'patch_check' => false,
			'recipients'  => '',
			'thresholds'  => array(),
		);
	}

	/**
	 * Default thresholds. Each one can be changed in the settings and with
	 * the axle_runtime_doctor_thresholds filter.
	 *
	 * @return array<string, float>
	 */
	public static function default_thresholds(): array {
		return array(
			'opcache_memory_pct'  => 90.0,
			'opcache_strings_pct' => 95.0,
			'opcache_keys_pct'    => 90.0,
			'opcache_hit_rate'    => 95.0,
			'autoload_single_kb'  => 100.0,
			'cron_late_minutes'   => 15.0,
			'loopback_ms'         => 1000.0,
			'disk_free_mb'        => 1024.0,
			'eol_warning_days'    => 183.0,
			'bootstrap_ms'        => 300.0,
			'object_hit_rate'     => 80.0,
		);
	}

	/**
	 * The capability that opens the screen and runs everything.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed for the screen, the REST routes and the abilities.
		 *
		 * @param string $capability Default manage_options.
		 */
		$cap = (string) apply_filters( 'axle_runtime_doctor_capability', 'manage_options' );

		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Every setting.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$saved       = Store::get( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Cleans and saves settings. Keys that are missing keep their value.
	 *
	 * @param array $input Raw input.
	 * @return array The saved settings.
	 */
	public function save( array $input ): array {
		$settings = $this->all();

		if ( isset( $input['host'] ) ) {
			$host             = sanitize_key( (string) $input['host'] );
			$settings['host'] = '' !== $host ? $host : 'auto';
		}
		if ( isset( $input['sampling'] ) ) {
			$settings['sampling'] = (bool) $input['sampling'];
		}
		if ( isset( $input['sample_rate'] ) ) {
			$settings['sample_rate'] = max( 1, min( 10000, (int) $input['sample_rate'] ) );
		}
		if ( isset( $input['patch_check'] ) ) {
			$settings['patch_check'] = (bool) $input['patch_check'];
		}
		if ( isset( $input['recipients'] ) ) {
			$settings['recipients'] = implode( ', ', self::emails( (string) $input['recipients'] ) );
		}
		if ( isset( $input['thresholds'] ) && is_array( $input['thresholds'] ) ) {
			$thresholds = array();
			foreach ( self::default_thresholds() as $key => $default ) {
				if ( isset( $input['thresholds'][ $key ] ) && '' !== trim( (string) $input['thresholds'][ $key ] ) && is_numeric( $input['thresholds'][ $key ] ) ) {
					$value = (float) $input['thresholds'][ $key ];
					if ( $value > 0 && abs( $value - $default ) > 0.0001 ) {
						$thresholds[ $key ] = $value;
					}
				}
			}
			$settings['thresholds'] = $thresholds;
		}

		Store::set( self::OPTION, $settings );
		$this->cache = null;

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array $settings The saved settings.
		 */
		do_action( 'axle_runtime_doctor_settings_saved', $settings );

		return $this->all();
	}

	/**
	 * Thresholds, with saved overrides and the filter.
	 *
	 * @return array<string, float>
	 */
	public function thresholds(): array {
		$defaults = self::default_thresholds();
		$saved    = $this->get( 'thresholds' );
		$merged   = array_merge( $defaults, is_array( $saved ) ? $saved : array() );

		/**
		 * Filters the thresholds the checks judge against.
		 *
		 * @param array $thresholds opcache_memory_pct, opcache_strings_pct, opcache_keys_pct, opcache_hit_rate,
		 *                          autoload_single_kb, cron_late_minutes, loopback_ms, disk_free_mb,
		 *                          eol_warning_days, bootstrap_ms, object_hit_rate.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_thresholds', $merged );

		$clean = array();
		foreach ( $defaults as $key => $default ) {
			$value         = isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ? (float) $filtered[ $key ] : $default;
			$clean[ $key ] = $value > 0 ? $value : $default;
		}
		foreach ( array( 'opcache_memory_pct', 'opcache_strings_pct', 'opcache_keys_pct', 'opcache_hit_rate', 'object_hit_rate' ) as $percent ) {
			$clean[ $percent ] = min( 100.0, $clean[ $percent ] );
		}

		return $clean;
	}

	/**
	 * How often front-end requests are sampled: 1 in N, 0 when sampling is off.
	 *
	 * @return int
	 */
	public function sample_rate(): int {
		$rate = $this->get( 'sampling' ) ? (int) $this->get( 'sample_rate' ) : 0;

		/**
		 * Filters the front-end sampling rate: 1 in N requests. 0 turns sampling off.
		 *
		 * @param int $rate Default 50, or what the settings say.
		 */
		$rate = (int) apply_filters( 'axle_runtime_doctor_sample_rate', $rate );

		return max( 0, min( 100000, $rate ) );
	}

	/**
	 * Whether to compare the PHP version with php.net's release list.
	 *
	 * @return bool
	 */
	public function patch_check(): bool {
		/**
		 * Filters whether the PHP patch-level check asks php.net for the latest releases.
		 *
		 * @param bool $on Default false, or what the settings say.
		 */
		return (bool) apply_filters( 'axle_runtime_doctor_php_patch_check', (bool) $this->get( 'patch_check' ) );
	}

	/**
	 * Who gets an email when a check gets worse.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		$saved = trim( (string) $this->get( 'recipients' ) );
		$list  = '' !== $saved ? self::emails( $saved ) : array( (string) get_site_option( 'admin_email', get_option( 'admin_email' ) ) );

		/**
		 * Filters the regression alert recipients. Return an empty array for no email.
		 *
		 * @param string[] $recipients Default: the saved list, or the admin email.
		 */
		$list = (array) apply_filters( 'axle_runtime_doctor_alert_recipients', $list );

		return self::emails( implode( ',', array_map( 'strval', array_filter( $list, 'is_scalar' ) ) ) );
	}

	/**
	 * How many snapshots History keeps.
	 *
	 * @return int
	 */
	public static function snapshots_to_keep(): int {
		/**
		 * Filters how many daily snapshots to keep.
		 *
		 * @param int $keep Default 30. 1 to 365.
		 */
		return max( 1, min( 365, (int) apply_filters( 'axle_runtime_doctor_snapshots_to_keep', 30 ) ) );
	}

	/**
	 * A query slower than this is slow.
	 *
	 * @return float Milliseconds.
	 */
	public static function slow_query_ms(): float {
		/**
		 * Filters when a single query counts as slow.
		 *
		 * @param float $ms Default 50 milliseconds.
		 */
		$ms = (float) apply_filters( 'axle_runtime_doctor_slow_query_threshold_ms', 50.0 );

		return $ms > 0 ? $ms : 50.0;
	}

	/**
	 * Valid email addresses in a comma or space separated list.
	 *
	 * @param string $text Addresses.
	 * @return string[]
	 */
	public static function emails( string $text ): array {
		$found = array();
		$parts = preg_split( '/[\s,;]+/', $text );
		foreach ( is_array( $parts ) ? $parts : array() as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$found[] = $email;
			}
		}

		return array_values( array_unique( $found ) );
	}
}
