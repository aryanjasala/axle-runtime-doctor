<?php
/**
 * Constants and a small in-memory $wpdb for Axle Runtime Doctor's unit tests.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

// phpcs:ignoreFile

defined( 'AryanX\Axle\RuntimeDoctor\PLUGIN_FILE' ) || define( 'AryanX\Axle\RuntimeDoctor\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/axle-runtime-doctor.php' );
defined( 'AryanX\Axle\RuntimeDoctor\VERSION' ) || define( 'AryanX\Axle\RuntimeDoctor\VERSION', '1.0.0' );

foreach ( array(
	'ARRAY_A'         => 'ARRAY_A',
	'WP_CONTENT_DIR'  => '/var/www/html/wp-content',
	'WP_PLUGIN_DIR'   => '/var/www/html/wp-content/plugins',
	'WPMU_PLUGIN_DIR' => '/var/www/html/wp-content/mu-plugins',
) as $ard_name => $ard_value ) {
	defined( $ard_name ) || define( $ard_name, $ard_value );
}

/**
 * Enough of wpdb for the autoload code: queued answers per method.
 */
class Ard_Fake_Wpdb {
	/** @var string */
	public $options = 'wp_options';
	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $base_prefix = 'wp_';
	/** @var array<string, array> Answers queued per method. */
	public $answers = array();
	/** @var string[] Queries seen. */
	public $seen = array();

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	private function answer( $method, $query ) {
		$this->seen[] = $query;
		return isset( $this->answers[ $method ] ) && $this->answers[ $method ] ? array_shift( $this->answers[ $method ] ) : null;
	}

	public function get_results( $query, $output = null ) {
		return $this->answer( 'get_results', $query ) ?? array();
	}

	public function get_row( $query, $output = null ) {
		return $this->answer( 'get_row', $query );
	}

	public function get_col( $query ) {
		return $this->answer( 'get_col', $query ) ?? array();
	}

	public function get_var( $query ) {
		return $this->answer( 'get_var', $query );
	}
}
