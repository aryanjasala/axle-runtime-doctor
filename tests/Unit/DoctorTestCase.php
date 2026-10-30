<?php
/**
 * Base class for Axle Runtime Doctor unit tests.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Checks\Context;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/runtime-doctor.php';

/**
 * Network options go to the same in-memory store as options, and the
 * common formatting functions behave like WordPress.
 */
abstract class DoctorTestCase extends TestCase {

	/** @var \Ard_Fake_Wpdb */
	protected $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new \Ard_Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'get_site_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_site_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com' . $path;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $text ) {
				return rtrim( $text, '/\\' );
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
	}

	/**
	 * A context with facts and the default thresholds.
	 *
	 * @param array $facts Facts.
	 * @return Context
	 */
	protected function context( array $facts ): Context {
		return new Context( $facts + array( 'sapi' => 'web' ) );
	}
}
