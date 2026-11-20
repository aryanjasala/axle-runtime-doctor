<?php
/**
 * Runs the must-use loader's code in its own process with a fake WordPress
 * and prints what it did, as JSON. Used by LoaderSourceTest.
 *
 * Usage: php loader-harness.php <loader file> <scenario file>
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );

$scenario = json_decode( (string) file_get_contents( $argv[2] ), true );

define( 'WP_PLUGIN_DIR', $scenario['plugin_dir'] );
foreach ( $scenario['server'] as $key => $value ) {
	$_SERVER[ $key ] = $value;
}
if ( ! empty( $scenario['wp_admin'] ) ) {
	define( 'WP_ADMIN', true );
}

$GLOBALS['options'] = $scenario['options'];
$GLOBALS['filters'] = array();

function get_option( $name ) {
	return $GLOBALS['options'][ $name ] ?? false;
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['options'] ) ) {
		return false;
	}
	unset( $GLOBALS['options'][ $name ] );

	return true;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['filters'][ $hook ] = $callback;
}

require $argv[1];

$active = array( 'a/a.php', 'elementor/elementor.php', 'axle-runtime-doctor/axle-runtime-doctor.php' );
$net    = array( 'elementor/elementor.php' => 1, 'b/b.php' => 1 );
$out    = array(
	'skipped'     => defined( 'AXLE_RUNTIME_DOCTOR_PROBE_SKIPPED' ) ? AXLE_RUNTIME_DOCTOR_PROBE_SKIPPED : '',
	'active'      => isset( $GLOBALS['filters']['option_active_plugins'] ) ? $GLOBALS['filters']['option_active_plugins']( $active ) : $active,
	'network'     => isset( $GLOBALS['filters']['site_option_active_sitewide_plugins'] ) ? array_keys( $GLOBALS['filters']['site_option_active_sitewide_plugins']( $net ) ) : array_keys( $net ),
	'row_left'    => array_keys( $GLOBALS['options'] ),
);

echo json_encode( $out );
