<?php
/**
 * Reads a report from another site, as data only.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

use AryanX\Axle\RuntimeDoctor\Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a report document strictly and rebuilds it from the fields it knows.
 *
 * The text is size-capped before it is decoded, decoded with a depth limit,
 * never unserialized or evaluated, and nothing in it is trusted: every value
 * is cut to length and stripped of markup, and unknown fields are dropped.
 */
final class Importer {

	/**
	 * The most bytes of JSON a report may have.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the largest report, in bytes, that can be imported.
		 *
		 * @param int $bytes Default 262144 (256 KB). 10 KB to 2 MB.
		 */
		return max( 10240, min( 2097152, (int) apply_filters( 'axle_runtime_doctor_import_max_bytes', 262144 ) ) );
	}

	/**
	 * Turns JSON text into a clean report document.
	 *
	 * @param string $json The text.
	 * @return array|WP_Error The document, or why it was refused.
	 */
	public static function parse( string $json ) {
		$json = trim( $json );
		if ( '' === $json ) {
			return new WP_Error( 'axle_runtime_doctor_import_empty', __( 'There is nothing to import. Paste a report or choose a file.', 'axle-runtime-doctor' ) );
		}
		if ( strlen( $json ) > self::max_bytes() ) {
			/* translators: %s: size such as 256 KB. */
			return new WP_Error( 'axle_runtime_doctor_import_large', sprintf( __( 'That report is too large. The limit is %s.', 'axle-runtime-doctor' ), size_format( self::max_bytes() ) ) );
		}

		$data = json_decode( $json, true, 8 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'axle_runtime_doctor_import_json', __( 'That is not valid JSON. Export the report again on the other site and paste the whole file.', 'axle-runtime-doctor' ) );
		}
		if ( ( $data['format'] ?? '' ) !== Exporter::FORMAT ) {
			return new WP_Error( 'axle_runtime_doctor_import_format', __( 'That JSON is not a Runtime Doctor report. Use Export on the other site.', 'axle-runtime-doctor' ) );
		}
		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) || $data['version'] < 1 || $data['version'] > Exporter::VERSION ) {
			return new WP_Error( 'axle_runtime_doctor_import_version', __( 'That report comes from a newer version of Runtime Doctor. Update this site and try again.', 'axle-runtime-doctor' ) );
		}
		if ( ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new WP_Error( 'axle_runtime_doctor_import_results', __( 'That report has no check results.', 'axle-runtime-doctor' ) );
		}

		$results = array();
		foreach ( $data['results'] as $key => $result ) {
			if ( count( $results ) >= 200 ) {
				break;
			}
			$id = is_array( $result ) && isset( $result['id'] ) && is_string( $result['id'] ) ? $result['id'] : ( is_string( $key ) ? $key : '' );
			if ( ! is_array( $result ) || ! preg_match( '/^[a-z0-9_]{1,64}$/', $id ) ) {
				continue;
			}
			$status         = (string) ( $result['status'] ?? '' );
			$results[ $id ] = array(
				'id'       => $id,
				'group'    => self::slug( $result['group'] ?? '' ),
				'label'    => self::text( $result['label'] ?? $id, 100 ),
				'status'   => in_array( $status, Result::statuses(), true ) ? $status : Result::INFO,
				'summary'  => self::text( $result['summary'] ?? '', 400 ),
				'value'    => self::text( $result['value'] ?? '', 400 ),
				'expected' => self::text( $result['expected'] ?? '', 400 ),
			);
		}
		if ( ! $results ) {
			return new WP_Error( 'axle_runtime_doctor_import_results', __( 'That report has no check results.', 'axle-runtime-doctor' ) );
		}

		$host = isset( $data['host'] ) && is_array( $data['host'] ) ? $data['host'] : array();

		return array(
			'format'         => Exporter::FORMAT,
			'version'        => Exporter::VERSION,
			'plugin_version' => self::text( $data['plugin_version'] ?? '', 20 ),
			'exported'       => self::time( $data['exported'] ?? 0 ),
			'time'           => self::time( $data['time'] ?? 0 ),
			'sapi'           => 'cli' === ( $data['sapi'] ?? '' ) ? 'cli' : 'web',
			'host'           => array(
				'id'    => self::slug( $host['id'] ?? '' ),
				'label' => self::text( $host['label'] ?? '', 60 ),
			),
			'counts'         => \AryanX\Axle\RuntimeDoctor\Runner::counts( $results ),
			'results'        => $results,
			'settings'       => Environment::clean( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array() ),
		);
	}

	/**
	 * Plain text, cut to length.
	 *
	 * @param mixed $value  Anything.
	 * @param int   $length Most characters.
	 * @return string
	 */
	private static function text( $value, int $length ): string {
		$text = is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';

		return substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) ), 0, $length );
	}

	/**
	 * A lower-case identifier.
	 *
	 * @param mixed $value Anything.
	 * @return string
	 */
	private static function slug( $value ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( is_scalar( $value ) ? (string) $value : '' ) ), 0, 40 );
	}

	/**
	 * A Unix time that makes sense: zero, or between 2000 and a day from now.
	 *
	 * @param mixed $value Anything.
	 * @return int
	 */
	private static function time( $value ): int {
		$time = is_numeric( $value ) ? (int) $value : 0;

		return $time >= 946684800 && $time <= time() + DAY_IN_SECONDS ? $time : 0;
	}
}
