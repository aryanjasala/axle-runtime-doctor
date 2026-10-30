const path = require( 'path' );

module.exports = {
	SLUG: 'axle-runtime-doctor',
	// Each plugin has its own port, so its site can run next to the others.
	// WP_PORT moves it (start the site with the same WP_PORT), WP_BASE_URL points the tests elsewhere.
	BASE_URL: process.env.WP_BASE_URL || `http://localhost:${ process.env.WP_PORT || 8918 }`,
	// Test-only credentials for the throwaway Docker site.
	USERNAME: process.env.WP_USERNAME || 'admin',
	PASSWORD: process.env.WP_PASSWORD || 'admin',
	STORAGE_STATE: path.join( __dirname, '..', '.auth', 'admin.json' ),
	// This plugin's own Docker site by default. A shared site can be used by pointing WP_COMPOSE_DIR at its compose folder.
	COMPOSE_DIR: process.env.WP_COMPOSE_DIR || path.join( __dirname, '..', 'env' ),
	// Options the plugin stores, cleared before each run.
	OPTIONS: ['axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_php_releases', 'axle_runtime_doctor_notice', 'axle_runtime_doctor_coverage'],
};
