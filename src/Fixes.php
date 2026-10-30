<?php
/**
 * How to fix each check, per host.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use const AryanX\Axle\RuntimeDoctor\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Reads data/fixes.json: per check, a fix for each host (the setting to
 * change, where, and the steps), and the text of a support ticket.
 *
 * A host without its own fix gets its web server's (apache, nginx), then the
 * generic one.
 */
final class Fixes {

	/**
	 * Loaded fix data.
	 *
	 * @var array|null
	 */
	private ?array $data = null;

	/**
	 * All fix data, after the filter.
	 *
	 * @return array
	 */
	public function data(): array {
		if ( null === $this->data ) {
			$data = self::read_json( 'fixes.json' );

			/**
			 * Filters the fix data: check ID => array( 'ask' => ..., 'ticket' => ..., host ID => fix ).
			 * Each fix has setting, where and steps. "generic" is the fallback.
			 *
			 * @param array $data Fix data from data/fixes.json.
			 */
			$this->data = (array) apply_filters( 'axle_runtime_doctor_fixes', $data );
		}

		return $this->data;
	}

	/**
	 * The fix for a check on a host.
	 *
	 * @param string $check Check ID.
	 * @param array  $host  Host: id, label, server.
	 * @return array|null host (the fix's host ID), setting, where, steps. Null when there is no fix.
	 */
	public function get( string $check, array $host ): ?array {
		$data  = $this->data();
		$fixes = isset( $data[ $check ] ) && is_array( $data[ $check ] ) ? $data[ $check ] : array();

		foreach ( array( (string) ( $host['id'] ?? '' ), (string) ( $host['server'] ?? '' ), 'generic' ) as $key ) {
			if ( '' !== $key && isset( $fixes[ $key ] ) && is_array( $fixes[ $key ] ) ) {
				$fix = $fixes[ $key ];

				return array(
					'host'    => $key,
					'setting' => (string) ( $fix['setting'] ?? '' ),
					'where'   => (string) ( $fix['where'] ?? '' ),
					'steps'   => array_values( array_map( 'strval', (array) ( $fix['steps'] ?? array() ) ) ),
				);
			}
		}

		return null;
	}

	/**
	 * A ready-to-paste support ticket for a result.
	 *
	 * @param array $result A result, as an array.
	 * @param array $host   Host.
	 * @return string Plain text. Empty when the check has nothing to ask.
	 */
	public function ticket( array $result, array $host ): string {
		$data  = $this->data();
		$check = (string) ( $result['id'] ?? '' );
		$fixes = isset( $data[ $check ] ) && is_array( $data[ $check ] ) ? $data[ $check ] : array();
		if ( empty( $fixes['ask'] ) ) {
			return '';
		}

		$template = (string) ( $fixes['ticket'] ?? $data['_ticket'] ?? '' );
		$details  = array_slice( array_map( 'strval', (array) ( $result['details'] ?? array() ) ), 0, 8 );
		$values   = array(
			'{ask}'      => (string) $fixes['ask'],
			'{site}'     => home_url( '/' ),
			'{host}'     => (string) ( $host['label'] ?? '' ),
			'{check}'    => (string) ( $result['label'] ?? '' ),
			'{summary}'  => (string) ( $result['summary'] ?? '' ),
			'{value}'    => (string) ( $result['value'] ?? '' ),
			'{expected}' => (string) ( $result['expected'] ?? '' ),
			'{details}'  => $details ? "\n" . implode( "\n", $details ) . "\n" : '',
			'{php}'      => PHP_VERSION,
		);
		foreach ( (array) ( $result['data'] ?? array() ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$values[ '{data.' . $key . '}' ] = (string) $value;
			}
		}

		$text = strtr( strtr( $template, array( '{ask}' => $values['{ask}'] ) ), $values );

		/**
		 * Filters the support ticket text for a check.
		 *
		 * @param string $text   The ticket.
		 * @param array  $result The result.
		 * @param array  $host   The host.
		 */
		return (string) apply_filters( 'axle_runtime_doctor_support_ticket_text', $text, $result, $host );
	}

	/**
	 * Reads a JSON file from data/.
	 *
	 * @param string $file File name.
	 * @return array
	 */
	public static function read_json( string $file ): array {
		$path = dirname( PLUGIN_FILE ) . '/data/' . $file;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file that ships with the plugin.
		$data = json_decode( (string) file_get_contents( $path ), true );

		return is_array( $data ) ? $data : array();
	}
}
