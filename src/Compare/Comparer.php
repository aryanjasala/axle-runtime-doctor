<?php
/**
 * Puts two reports side by side.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Compares every check and every runtime setting of two report documents,
 * puts the differences first and says what each one means.
 */
final class Comparer {

	public const DIFFERENT = 'different';
	public const ONLY      = 'only';
	public const MINOR     = 'minor';
	public const SAME      = 'same';

	/**
	 * Compares two reports.
	 *
	 * @param array  $a      Report document of the first site.
	 * @param array  $b      Report document of the second site.
	 * @param string $name_a What to call the first site.
	 * @param string $name_b What to call the second site.
	 * @return array{rows: array[], counts: array<string, int>}
	 */
	public static function compare( array $a, array $b, string $name_a, string $name_b ): array {
		$rows = array();

		$ra = (array) ( $a['results'] ?? array() );
		$rb = (array) ( $b['results'] ?? array() );
		foreach ( array_unique( array_merge( array_keys( $ra ), array_keys( $rb ) ) ) as $id ) {
			$rows[] = self::check_row( (string) $id, $ra[ $id ] ?? null, $rb[ $id ] ?? null, $name_a, $name_b );
		}

		$sa = (array) ( $a['settings'] ?? array() );
		$sb = (array) ( $b['settings'] ?? array() );
		foreach ( array_unique( array_merge( array_keys( $sa ), array_keys( $sb ) ) ) as $key ) {
			$row = self::setting_row( (string) $key, $sa[ $key ] ?? null, $sb[ $key ] ?? null, $name_a, $name_b );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		$order = array(
			self::DIFFERENT => 0,
			self::ONLY      => 1,
			self::MINOR     => 2,
			self::SAME      => 3,
		);
		usort(
			$rows,
			static function ( $x, $y ) use ( $order ) {
				return array( $order[ $x['state'] ], -$x['gap'], 'check' === $x['type'] ? 0 : 1, $x['label'] )
					<=> array( $order[ $y['state'] ], -$y['gap'], 'check' === $y['type'] ? 0 : 1, $y['label'] );
			}
		);

		$counts = array_fill_keys( array_keys( $order ), 0 );
		foreach ( $rows as $row ) {
			++$counts[ $row['state'] ];
		}

		return array(
			'rows'   => $rows,
			'counts' => $counts,
		);
	}

	/**
	 * One check.
	 *
	 * @param string     $id     Check ID.
	 * @param array|null $a      Result on the first site.
	 * @param array|null $b      Result on the second site.
	 * @param string     $name_a First site.
	 * @param string     $name_b Second site.
	 * @return array
	 */
	private static function check_row( string $id, ?array $a, ?array $b, string $name_a, string $name_b ): array {
		$any = $a ?? $b ?? array();
		$row = array(
			'type'    => 'check',
			'key'     => $id,
			'group'   => (string) ( $any['group'] ?? '' ),
			'label'   => (string) ( $any['label'] ?? $id ),
			'a'       => $a,
			'b'       => $b,
			'state'   => self::SAME,
			'gap'     => 0,
			'meaning' => '',
		);

		if ( null === $a || null === $b ) {
			$row['state']   = self::ONLY;
			$row['meaning'] = self::meaning( self::only_text( null === $a ? $name_b : $name_a, null === $a ? $name_a : $name_b ), $row );

			return $row;
		}

		$status_a = (string) ( $a['status'] ?? Result::INFO );
		$status_b = (string) ( $b['status'] ?? Result::INFO );
		if ( $status_a !== $status_b ) {
			$row['state'] = self::DIFFERENT;
			$row['gap']   = abs( Result::rank( $status_a ) - Result::rank( $status_b ) ) + ( Result::INFO === $status_a || Result::INFO === $status_b ? 0 : 1 );
			$worse_a      = Result::rank( $status_a ) > Result::rank( $status_b );
			$worse_b      = Result::rank( $status_b ) > Result::rank( $status_a );
			$worse        = $worse_a ? $a : $b;
			$text         = sprintf(
				/* translators: 1: site name that is worse, 2: its status, 3: what its check says. */
				__( '%1$s is %2$s: %3$s.', 'axle-runtime-doctor' ),
				$worse_a ? $name_a : ( $worse_b ? $name_b : $name_a ),
				strtolower( Result::status_label( (string) ( $worse['status'] ?? '' ) ) ),
				rtrim( (string) ( $worse['summary'] ?? '' ), '.' )
			);
			if ( ! $worse_a && ! $worse_b ) {
				$text = sprintf(
					/* translators: 1: first site, 2: its summary, 3: second site, 4: its summary. */
					__( '%1$s says: %2$s. %3$s says: %4$s.', 'axle-runtime-doctor' ),
					$name_a,
					rtrim( (string) ( $a['summary'] ?? '' ), '.' ),
					$name_b,
					rtrim( (string) ( $b['summary'] ?? '' ), '.' )
				);
			}
			$row['meaning'] = self::meaning( $text, $row );
		} elseif ( self::norm( (string) ( $a['value'] ?? '' ) ) !== self::norm( (string) ( $b['value'] ?? '' ) ) ) {
			$row['state']   = self::MINOR;
			$row['meaning'] = self::meaning( __( 'Same verdict on both sites, with different numbers.', 'axle-runtime-doctor' ), $row );
		}

		return $row;
	}

	/**
	 * One runtime setting.
	 *
	 * @param string     $key    Setting key.
	 * @param array|null $a      Setting on the first site.
	 * @param array|null $b      Setting on the second site.
	 * @param string     $name_a First site.
	 * @param string     $name_b Second site.
	 * @return array|null Null for a setting that is ignored.
	 */
	private static function setting_row( string $key, ?array $a, ?array $b, string $name_a, string $name_b ): ?array {
		/**
		 * Filters the settings left out of a comparison, such as ones that always differ.
		 *
		 * @param string[] $keys Setting keys. Default none.
		 */
		$ignored = (array) apply_filters( 'axle_runtime_doctor_compare_ignored_settings', array() );
		if ( in_array( $key, $ignored, true ) ) {
			return null;
		}

		$any = $a ?? $b ?? array();
		$row = array(
			'type'    => 'setting',
			'key'     => $key,
			'group'   => (string) ( $any['group'] ?? '' ),
			'label'   => (string) ( $any['label'] ?? $key ),
			'a'       => $a,
			'b'       => $b,
			'state'   => self::SAME,
			'gap'     => 0,
			'meaning' => '',
		);

		if ( null === $a || null === $b ) {
			$row['state']   = self::ONLY;
			$row['meaning'] = self::meaning( self::only_text( null === $a ? $name_b : $name_a, null === $a ? $name_a : $name_b ), $row );

			return $row;
		}
		if ( self::norm( (string) $a['value'] ) !== self::norm( (string) $b['value'] ) ) {
			$row['state']   = self::DIFFERENT;
			$row['meaning'] = self::meaning( self::setting_meaning( $key, (string) $a['value'], (string) $b['value'], $name_a, $name_b ), $row );
		}

		return $row;
	}

	/**
	 * "Only X has this."
	 *
	 * @param string $has  Name of the site that has it.
	 * @param string $lack Name of the site that does not.
	 * @return string
	 */
	private static function only_text( string $has, string $lack ): string {
		/* translators: 1: site that reports this, 2: site that does not. */
		return sprintf( __( 'Only %1$s reports this. %2$s may run another version of Runtime Doctor or another PHP.', 'axle-runtime-doctor' ), $has, $lack );
	}

	/**
	 * Passes the meaning through the filter.
	 *
	 * @param string $text Meaning.
	 * @param array  $row  The row.
	 * @return string
	 */
	private static function meaning( string $text, array $row ): string {
		/**
		 * Filters the sentence that says what a difference between two sites means.
		 *
		 * @param string $text Meaning.
		 * @param array  $row  type (check or setting), key, group, label, a, b, state.
		 */
		return (string) apply_filters( 'axle_runtime_doctor_compare_meaning', $text, $row );
	}

	/**
	 * A value in a form that can be compared: sizes in bytes, on and off as words, case ignored.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function norm( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( '1', 'on', 'true', 'yes' ), true ) ) {
			return 'on';
		}
		if ( in_array( $value, array( '0', 'off', 'false', 'no', '' ), true ) ) {
			return 'off';
		}
		if ( preg_match( '/^(\d+)\s*([kmg])?b?$/', $value, $m ) ) {
			$powers = array(
				''  => 0,
				'k' => 1,
				'm' => 2,
				'g' => 3,
			);

			return (string) ( (int) $m[1] * ( 1024 ** $powers[ $m[2] ?? '' ] ) );
		}

		return $value;
	}

	/**
	 * The number in a value, for working out which side has more.
	 *
	 * @param string $value Value.
	 * @return float
	 */
	private static function num( string $value ): float {
		$norm = self::norm( $value );

		return is_numeric( $norm ) ? (float) $norm : (float) preg_replace( '/[^0-9.]/', '', $value );
	}

	/**
	 * The sentence for a setting that differs.
	 *
	 * @param string $key    Setting key.
	 * @param string $va     Value on the first site.
	 * @param string $vb     Value on the second site.
	 * @param string $name_a First site.
	 * @param string $name_b Second site.
	 * @return string
	 */
	private static function setting_meaning( string $key, string $va, string $vb, string $name_a, string $name_b ): string {
		$a_is_low = self::num( $va ) < self::num( $vb );
		$low      = $a_is_low ? $name_a : $name_b;
		$high     = $a_is_low ? $name_b : $name_a;
		$a_on     = 'on' === self::norm( $va );
		$on       = $a_on ? $name_a : $name_b;
		$off      = $a_on ? $name_b : $name_a;
		$fmt      = static function ( string $value ): string {
			return is_numeric( $value ) ? number_format_i18n( (float) $value ) : $value;
		};

		switch ( $key ) {
			case 'opcache.max_accelerated_files':
				return sprintf(
					/* translators: 1: number of files, 2: site, 3: number of files, 4: site, 5: the site with fewer slots. */
					__( 'OPcache holds %1$s files on %2$s and %3$s on %4$s: %5$s runs out of slots first and recompiles PHP it could have kept.', 'axle-runtime-doctor' ),
					$fmt( $va ),
					$name_a,
					$fmt( $vb ),
					$name_b,
					$low
				);
			case 'opcache.memory_consumption':
			case 'opcache.interned_strings_buffer':
				return sprintf(
					/* translators: 1: site with the smaller value, 2: the smaller value in MB, 3: site with the bigger value, 4: the bigger value in MB. */
					__( '%1$s has %2$s MB for this and %3$s has %4$s MB: the smaller one fills sooner, and then OPcache throws code away and compiles it again.', 'axle-runtime-doctor' ),
					$low,
					$fmt( $a_is_low ? $va : $vb ),
					$high,
					$fmt( $a_is_low ? $vb : $va )
				);
			case 'opcache.enable':
				return sprintf(
					/* translators: %s: site where OPcache is off. */
					__( 'OPcache is off on %s, so every request compiles all the PHP again.', 'axle-runtime-doctor' ),
					$off
				);
			case 'opcache.validate_timestamps':
				return sprintf(
					/* translators: 1: site that checks files, 2: site that does not. */
					__( '%1$s checks every PHP file for changes while it runs, which costs disk reads. %2$s trusts the cache until it is reset, which is faster but needs a reset after each deploy.', 'axle-runtime-doctor' ),
					$on,
					$off
				);
			case 'opcache.revalidate_freq':
				return sprintf(
					/* translators: 1: site, 2: seconds, 3: site, 4: seconds. */
					__( '%1$s looks for changed files every %2$s seconds and %3$s every %4$s seconds. The shorter time means more disk reads.', 'axle-runtime-doctor' ),
					$name_a,
					$va,
					$name_b,
					$vb
				);
			case 'opcache.jit':
			case 'opcache.jit_buffer_size':
				return __( 'The JIT settings differ. For normal WordPress pages JIT changes little: match the cache settings first.', 'axle-runtime-doctor' );
			case 'opcache.file_cache_only':
				return sprintf(
					/* translators: %s: site that uses only the file cache. */
					__( '%s uses OPcache from files on disk only, not shared memory. That is slower on every request.', 'axle-runtime-doctor' ),
					$on
				);
			case 'php_version':
				return sprintf(
					/* translators: 1: site with the older PHP, 2: its version, 3: other site, 4: its version. */
					__( '%1$s runs PHP %2$s and %3$s runs PHP %4$s. The older one is usually slower and may no longer get security fixes.', 'axle-runtime-doctor' ),
					version_compare( $va, $vb, '<' ) ? $name_a : $name_b,
					version_compare( $va, $vb, '<' ) ? $va : $vb,
					version_compare( $va, $vb, '<' ) ? $name_b : $name_a,
					version_compare( $va, $vb, '<' ) ? $vb : $va
				);
			case 'memory_limit':
			case 'wp_memory_limit':
			case 'wp_max_memory_limit':
				return sprintf(
					/* translators: %s: site with the lower limit. */
					__( '%s has the lower memory limit. Big imports, page builders and image work stop with "out of memory" there first.', 'axle-runtime-doctor' ),
					$low
				);
			case 'max_execution_time':
				return sprintf(
					/* translators: %s: site with the lower limit. */
					__( '%s has the shorter time limit. Long imports and backups are cut off there first.', 'axle-runtime-doctor' ),
					$low
				);
			case 'max_input_vars':
				return sprintf(
					/* translators: %s: site with the lower limit. */
					__( '%s accepts fewer form fields. Saving a large menu or a big settings form loses items there first.', 'axle-runtime-doctor' ),
					$low
				);
			case 'upload_max_filesize':
			case 'post_max_size':
				return sprintf(
					/* translators: %s: site with the lower limit. */
					__( '%s accepts smaller uploads.', 'axle-runtime-doctor' ),
					$low
				);
			case 'realpath_cache_size':
			case 'realpath_cache_ttl':
				return sprintf(
					/* translators: %s: site with the lower value. */
					__( '%s has the smaller realpath cache, so PHP looks up file locations on disk more often.', 'axle-runtime-doctor' ),
					$low
				);
			case 'object_cache':
				return sprintf(
					/* translators: 1: site with a persistent object cache, 2: site without. */
					__( '%1$s has a persistent object cache and %2$s does not. %2$s asks the database again for options, posts and users on every request.', 'axle-runtime-doctor' ),
					'none' === $va ? $name_b : $name_a,
					'none' === $va ? $name_a : $name_b
				);
			case 'wp_debug':
				return sprintf(
					/* translators: %s: site where WP_DEBUG is on. */
					__( 'WP_DEBUG is on for %s. It records notices on every request, which is slower and fills the log.', 'axle-runtime-doctor' ),
					$on
				);
			case 'disable_wp_cron':
				return sprintf(
					/* translators: %s: site where WP-Cron is off. */
					__( 'WP-Cron is off on %s. A real cron job has to call wp-cron.php there, or scheduled tasks stop running.', 'axle-runtime-doctor' ),
					$on
				);
			case 'active_plugins':
				return sprintf(
					/* translators: 1: site with more plugins, 2: number, 3: other site, 4: number. */
					__( '%1$s loads more plugins (%2$s against %3$s on %4$s). Each one adds code to every request.', 'axle-runtime-doctor' ),
					$high,
					$fmt( $a_is_low ? $vb : $va ),
					$fmt( $a_is_low ? $va : $vb ),
					$low
				);
			case 'wp_version':
				return __( 'The WordPress versions differ, so core code and its performance fixes differ too.', 'axle-runtime-doctor' );
			case 'db_server':
				return __( 'The database servers or versions differ. Query plans and defaults can differ with them.', 'axle-runtime-doctor' );
			case 'php_sapi':
			case 'server_software':
				return __( 'PHP runs another way on each site, for example FPM against Apache. Speed and limits depend on it.', 'axle-runtime-doctor' );
		}

		return sprintf(
			/* translators: 1: first site, 2: its value, 3: second site, 4: its value. */
			__( '%1$s has %2$s and %3$s has %4$s.', 'axle-runtime-doctor' ),
			$name_a,
			$va,
			$name_b,
			$vb
		);
	}
}
