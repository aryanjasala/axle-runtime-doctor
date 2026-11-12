<?php
/**
 * A runtime report that is safe to send to another site.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

use const AryanX\Axle\RuntimeDoctor\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored report into the portable JSON document.
 *
 * It keeps each check's status, summary, value and expected value, and the
 * runtime settings. It drops details and raw data, and it replaces paths,
 * hostnames, email addresses and IP addresses, so the file holds nothing
 * that identifies the server.
 */
final class Exporter {

	public const FORMAT  = 'axle-runtime-doctor-report';
	public const VERSION = 1;

	/**
	 * Builds the document.
	 *
	 * @param array $report A stored report: time, host, sapi, counts, results, environment.
	 * @return array
	 */
	public static function build( array $report ): array {
		$replace = self::replacements();
		$results = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $id => $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$id             = (string) ( $result['id'] ?? $id );
			$results[ $id ] = array(
				'id'       => $id,
				'group'    => (string) ( $result['group'] ?? '' ),
				'label'    => self::redact( (string) ( $result['label'] ?? $id ), $replace ),
				'status'   => (string) ( $result['status'] ?? 'info' ),
				'summary'  => self::redact( (string) ( $result['summary'] ?? '' ), $replace ),
				'value'    => self::redact( (string) ( $result['value'] ?? '' ), $replace ),
				'expected' => self::redact( (string) ( $result['expected'] ?? '' ), $replace ),
			);
		}

		$settings = array();
		foreach ( Environment::clean( (array) ( $report['environment'] ?? array() ) ) as $key => $setting ) {
			$setting['value'] = self::redact( $setting['value'], $replace );
			$settings[ $key ] = $setting;
		}

		$document = array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'plugin_version' => VERSION,
			'exported'       => time(),
			'time'           => (int) ( $report['time'] ?? time() ),
			'sapi'           => 'cli' === ( $report['sapi'] ?? '' ) ? 'cli' : 'web',
			'host'           => array(
				'id'    => (string) ( $report['host']['id'] ?? '' ),
				'label' => self::redact( (string) ( $report['host']['label'] ?? '' ), $replace ),
			),
			'counts'         => (array) ( $report['counts'] ?? array() ),
			'results'        => $results,
			'settings'       => $settings,
		);

		/**
		 * Filters the report document before it is exported.
		 *
		 * Keep it free of secrets, paths and hostnames: the file leaves the site.
		 *
		 * @param array $document The portable report.
		 * @param array $report   The stored report it was built from.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_export_report', $document, $report );

		return ( $filtered['format'] ?? '' ) === self::FORMAT ? $filtered : $document;
	}

	/**
	 * Builds the document for a real export and tells everyone who listens.
	 *
	 * @param array  $report A stored report.
	 * @param string $via    download, cli, ability or direct.
	 * @return array
	 */
	public static function publish( array $report, string $via ): array {
		$document = self::build( $report );

		/**
		 * Fires after a report was exported from this site.
		 *
		 * @param array  $document The portable report.
		 * @param string $via      download, cli, ability or direct.
		 */
		do_action( 'axle_runtime_doctor_report_exported', $document, $via );

		return $document;
	}

	/**
	 * The document as JSON text.
	 *
	 * @param array $document The portable report.
	 * @return string
	 */
	public static function to_json( array $document ): string {
		return (string) wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * A file name for the download.
	 *
	 * @return string
	 */
	public static function filename(): string {
		return 'runtime-report-' . gmdate( 'Y-m-d' ) . '.json';
	}

	/**
	 * Literal text to replace, longest first.
	 *
	 * @return array<string, string>
	 */
	private static function replacements(): array {
		$pairs = array();
		$roots = array(
			'{content}' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'{plugins}' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'{abspath}' => defined( 'ABSPATH' ) ? ABSPATH : '',
		);
		foreach ( $roots as $token => $path ) {
			$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( strlen( $path ) > 3 ) {
				$pairs[ $path ] = $token;
			}
		}
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ), function_exists( 'gethostname' ) ? gethostname() : '' );
		foreach ( array( 'HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$hosts[] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		foreach ( $hosts as $host ) {
			if ( is_string( $host ) && strlen( $host ) > 2 ) {
				$pairs[ $host ] = '{site}';
			}
		}

		/**
		 * Filters literal text that is replaced in an exported report, such as a
		 * private hostname the plugin cannot know about.
		 *
		 * @param array<string, string> $pairs Text to find => what replaces it.
		 */
		$pairs = array_filter( (array) apply_filters( 'axle_runtime_doctor_export_replacements', $pairs ), 'is_string' );
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		return $pairs;
	}

	/**
	 * Takes the identifying parts out of one text.
	 *
	 * @param string                $text    Text.
	 * @param array<string, string> $replace Literal replacements.
	 * @return string
	 */
	public static function redact( string $text, array $replace = array() ): string {
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '{email}', $text );
		$text = (string) preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '{ip}', $text );
		foreach ( $replace as $find => $with ) {
			$text = str_ireplace( (string) $find, $with, $text );
		}
		// Any other absolute path keeps its last part only.
		$text = (string) preg_replace_callback(
			'#(?<![\w:/.{}])(?:[A-Za-z]:[\\\\/]|/)(?:[\w.\-@+]+[\\\\/]){2,}([\w.\-@+]*)#',
			static function ( $found ) {
				return '{path}/' . $found[1];
			},
			$text
		);

		return substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) ), 0, 400 );
	}
}
