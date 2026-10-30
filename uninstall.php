<?php
/**
 * Removes Axle Runtime Doctor's settings, snapshots, samples, schedule and probe leftovers.
 *
 * @package RuntimeDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data from the current site.
 */
function axle_runtime_doctor_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_report', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_php_releases', 'axle_runtime_doctor_notice' ) as $axle_runtime_doctor_option ) {
		delete_option( $axle_runtime_doctor_option );
	}
	delete_transient( 'axle_runtime_doctor_php_files' );
	wp_clear_scheduled_hook( 'axle_runtime_doctor_daily' );
	wp_cache_delete( 'samples', 'axle_runtime_doctor' );

	// Probe tokens and probe output.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'axle_runtime_doctor_probe_' ) . '%' ) );
}

if ( is_multisite() ) {
	foreach ( array( 'axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_report', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_php_releases', 'axle_runtime_doctor_notice' ) as $axle_runtime_doctor_option ) {
		delete_site_option( $axle_runtime_doctor_option );
	}

	$axle_runtime_doctor_offset = 0;
	do {
		$axle_runtime_doctor_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_runtime_doctor_offset,
			)
		);

		foreach ( $axle_runtime_doctor_sites as $axle_runtime_doctor_site ) {
			switch_to_blog( $axle_runtime_doctor_site );
			axle_runtime_doctor_uninstall_site();
			restore_current_blog();
		}

		$axle_runtime_doctor_offset += 100;
	} while ( $axle_runtime_doctor_sites );
} else {
	axle_runtime_doctor_uninstall_site();
}
