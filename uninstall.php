<?php
/**
 * Removes Axle Runtime Doctor's settings, snapshots, kept reports, the page cache test, samples, schedule and probe leftovers.
 *
 * @package RuntimeDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data from the current site.
 */
function axle_runtime_doctor_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_report', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_php_releases', 'axle_runtime_doctor_notice', 'axle_runtime_doctor_imports', 'axle_runtime_doctor_page_cache', 'axle_runtime_doctor_updates', 'axle_runtime_doctor_timings', 'axle_runtime_doctor_confirmations', 'axle_runtime_doctor_timing_lock' ) as $axle_runtime_doctor_option ) {
		delete_option( $axle_runtime_doctor_option );
	}
	delete_transient( 'axle_runtime_doctor_php_files' );
	delete_site_transient( 'axle_runtime_doctor_import_trash' );
	wp_clear_scheduled_hook( 'axle_runtime_doctor_daily' );
	wp_clear_scheduled_hook( 'axle_runtime_doctor_timing_run' );
	wp_cache_delete( 'samples', 'axle_runtime_doctor' );

	// Probe tokens and probe output.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'axle_runtime_doctor_probe_' ) . '%' ) );
}

// The must-use loader of "Confirm it", when it is ours.
$axle_runtime_doctor_loader = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/axle-runtime-doctor-confirm.php';
if ( is_readable( $axle_runtime_doctor_loader ) ) {
	$axle_runtime_doctor_head = file_get_contents( $axle_runtime_doctor_loader, false, null, 0, 2048 ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the first lines of our own file.
	if ( is_string( $axle_runtime_doctor_head ) && false !== strpos( $axle_runtime_doctor_head, 'AXLE_RUNTIME_DOCTOR_CONFIRM_LOADER 1' ) ) {
		wp_delete_file( $axle_runtime_doctor_loader );
	}
}

// What each user saw last: a notice kept for one page view, and the Get started card.
delete_metadata( 'user', 0, 'axle_runtime_doctor_flash', '', true );
delete_metadata( 'user', 0, 'axle_runtime_doctor_compare_state', '', true );
delete_metadata( 'user', 0, 'axle_runtime_doctor_cache_flash', '', true );
delete_metadata( 'user', 0, 'axle_runtime_doctor_cache_state', '', true );
delete_metadata( 'user', 0, 'axle_runtime_doctor_timeline_flash', '', true );

if ( is_multisite() ) {
	foreach ( array( 'axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_report', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_php_releases', 'axle_runtime_doctor_notice', 'axle_runtime_doctor_imports', 'axle_runtime_doctor_page_cache', 'axle_runtime_doctor_updates', 'axle_runtime_doctor_timings', 'axle_runtime_doctor_confirmations', 'axle_runtime_doctor_timing_lock' ) as $axle_runtime_doctor_option ) {
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
