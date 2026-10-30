<?php
/**
 * Shared parts of the checks.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Fills the ID, group, name and "why it matters" into each result.
 */
abstract class BaseCheck implements Check {

	/**
	 * Why the check matters. Shown under "Why it matters".
	 *
	 * @return string
	 */
	abstract protected function why(): string;

	/**
	 * Inside a web request only. Most checks are.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return true;
	}

	/**
	 * About the shared runtime, not one site.
	 *
	 * @return bool
	 */
	public function per_site(): bool {
		return false;
	}

	/**
	 * Builds a result.
	 *
	 * @param string $status  good, recommended, critical or info.
	 * @param string $summary The verdict.
	 * @param array  $fields  value, expected, details, data, inferred.
	 * @return Result
	 */
	protected function result( string $status, string $summary, array $fields = array() ): Result {
		$fields['id']      = $this->id();
		$fields['group']   = $this->group();
		$fields['label']   = $this->label();
		$fields['status']  = $status;
		$fields['summary'] = $summary;
		$fields['why']     = $this->why();

		return Result::from_array( $fields );
	}

	/**
	 * Bytes as a short size.
	 *
	 * @param float|int $bytes Bytes.
	 * @return string
	 */
	protected static function size( $bytes ): string {
		$bytes = (float) $bytes;
		if ( $bytes >= 1073741824 ) {
			return round( $bytes / 1073741824, 1 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}

		return (int) $bytes . ' B';
	}

	/**
	 * A php.ini size such as 256M in bytes. -1 stays -1.
	 *
	 * @param string $value Ini value.
	 * @return int
	 */
	public static function ini_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$number = (int) $value;
		$unit   = strtolower( substr( $value, -1 ) );
		$powers = array(
			'k' => 1,
			'm' => 2,
			'g' => 3,
		);

		return isset( $powers[ $unit ] ) ? (int) ( $number * pow( 1024, $powers[ $unit ] ) ) : $number;
	}

	/**
	 * Whether an ini flag is on.
	 *
	 * @param mixed $value Ini value.
	 * @return bool
	 */
	protected static function on( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * A percentage with one decimal.
	 *
	 * @param float $part  Part.
	 * @param float $whole Whole.
	 * @return float
	 */
	protected static function percent( float $part, float $whole ): float {
		return $whole > 0 ? round( 100 * $part / $whole, 1 ) : 0.0;
	}
}
