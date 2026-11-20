<?php
/**
 * Plugin Name: Slow Fixture
 * Description: End-to-end fixture. Sleeps on init for ard_e2e_slow_ms milliseconds and marks the page footer, so a test can see whether it was loaded.
 * Version: 1.0.0
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

add_action(
	'init',
	static function () {
		$ms = (int) get_option( 'ard_e2e_slow_ms', 0 );
		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}
);

add_action(
	'wp_footer',
	static function () {
		echo '<!-- ard-slow-plugin-loaded -->';
	}
);
