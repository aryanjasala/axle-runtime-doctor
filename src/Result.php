<?php
/**
 * The outcome of one check.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * What a check found: a status, the measured value, what it should be, why it
 * matters and the details behind it.
 */
final class Result {

	public const GOOD        = 'good';
	public const RECOMMENDED = 'recommended';
	public const CRITICAL    = 'critical';
	public const INFO        = 'info';

	/**
	 * Check ID.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * Group ID.
	 *
	 * @var string
	 */
	public string $group = '';

	/**
	 * Check name, such as "OPcache coverage".
	 *
	 * @var string
	 */
	public string $label = '';

	/**
	 * One of good, recommended, critical or info.
	 *
	 * @var string
	 */
	public string $status = self::INFO;

	/**
	 * The verdict in one sentence, such as "OPcache is skipping wp-content".
	 *
	 * @var string
	 */
	public string $summary = '';

	/**
	 * What was measured.
	 *
	 * @var string
	 */
	public string $value = '';

	/**
	 * What it should be.
	 *
	 * @var string
	 */
	public string $expected = '';

	/**
	 * Why it matters.
	 *
	 * @var string
	 */
	public string $why = '';

	/**
	 * Lines of detail: reasons, top items, evidence.
	 *
	 * @var string[]
	 */
	public array $details = array();

	/**
	 * Raw values for support tickets and other code.
	 *
	 * @var array
	 */
	public array $data = array();

	/**
	 * Whether the result is inferred rather than read directly.
	 *
	 * @var bool
	 */
	public bool $inferred = false;

	/**
	 * Severity, for comparing two results: info and good 0, recommended 1, critical 2.
	 *
	 * @param string $status A status.
	 * @return int
	 */
	public static function rank( string $status ): int {
		$ranks = array(
			self::CRITICAL    => 2,
			self::RECOMMENDED => 1,
		);

		return $ranks[ $status ] ?? 0;
	}

	/**
	 * Every status.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::CRITICAL, self::RECOMMENDED, self::GOOD, self::INFO );
	}

	/**
	 * Label for a status.
	 *
	 * @param string $status A status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::CRITICAL:
				return __( 'Critical', 'axle-runtime-doctor' );
			case self::RECOMMENDED:
				return __( 'Recommended', 'axle-runtime-doctor' );
			case self::GOOD:
				return __( 'Good', 'axle-runtime-doctor' );
		}

		return __( 'Info', 'axle-runtime-doctor' );
	}

	/**
	 * As an array, for storage and JSON.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'group'    => $this->group,
			'label'    => $this->label,
			'status'   => $this->status,
			'summary'  => $this->summary,
			'value'    => $this->value,
			'expected' => $this->expected,
			'why'      => $this->why,
			'details'  => array_values( array_map( 'strval', $this->details ) ),
			'data'     => $this->data,
			'inferred' => $this->inferred,
		);
	}

	/**
	 * Builds a result from an array, for results that come back from a filter or storage.
	 *
	 * @param array $data Fields.
	 * @return Result
	 */
	public static function from_array( array $data ): Result {
		$result = new self();
		foreach ( array( 'id', 'group', 'label', 'summary', 'value', 'expected', 'why' ) as $field ) {
			$result->$field = isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '';
		}
		$status           = isset( $data['status'] ) ? (string) $data['status'] : '';
		$result->status   = in_array( $status, self::statuses(), true ) ? $status : self::INFO;
		$result->details  = isset( $data['details'] ) && is_array( $data['details'] ) ? array_values( array_map( 'strval', array_filter( $data['details'], 'is_scalar' ) ) ) : array();
		$result->data     = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$result->inferred = ! empty( $data['inferred'] );

		return $result;
	}
}
