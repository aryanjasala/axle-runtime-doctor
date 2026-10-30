// Helpers only this plugin's tests need: the must-use fixture, PHP settings in
// the web container, and WP-CLI runs that keep stderr and the exit code.
const { spawnSync } = require( 'child_process' );
const { COMPOSE_DIR } = require( './utils/config' );
const { wp, wpEval } = require( './utils/wp-cli' );

const OPTIONS = [ 'axle_runtime_doctor_settings', 'axle_runtime_doctor_snapshots', 'axle_runtime_doctor_report', 'axle_runtime_doctor_samples', 'axle_runtime_doctor_notice', 'ard_e2e_mail', 'ard_e2e_loopback' ];
const FIXTURES = '/var/www/html/wp-content/plugins/axle-runtime-doctor/tests/e2e/fixtures';

/**
 * Runs WP-CLI and returns stdout, stderr and the exit code, without throwing.
 *
 * @param {...string} args WP-CLI arguments.
 * @return {{stdout: string, stderr: string, status: number}} Result.
 */
function wpRun( ...args ) {
	const run = spawnSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'wp', ...args ], { cwd: COMPOSE_DIR, encoding: 'utf8' } );
	return { stdout: ( run.stdout || '' ).trim(), stderr: ( run.stderr || '' ).trim(), status: run.status };
}

/**
 * Runs a shell command as root in the web server container.
 *
 * @param {string} command Shell command.
 * @return {string} Output.
 */
function webRoot( command ) {
	const run = spawnSync( 'docker', [ 'compose', 'exec', '-T', '-u', 'root', 'wp', 'sh', '-c', command ], { cwd: COMPOSE_DIR, encoding: 'utf8' } );
	if ( 0 !== run.status ) {
		throw new Error( `${ command } failed: ${ run.stderr }` );
	}
	return ( run.stdout || '' ).trim();
}

/**
 * Restarts the web server container, so PHP reads its ini files again and OPcache
 * starts empty. A graceful Apache restart can crash mod_php when OPcache settings change.
 */
function reloadPhp() {
	const run = spawnSync( 'docker', [ 'compose', 'restart', 'wp' ], { cwd: COMPOSE_DIR, encoding: 'utf8' } );
	if ( 0 !== run.status ) {
		throw new Error( `Restarting the web server failed: ${ run.stderr }` );
	}
	for ( let i = 0; i < 60; i++ ) {
		const up = spawnSync( 'docker', [ 'compose', 'exec', '-T', 'wp', 'php', '-r', 'echo @file_get_contents( "http://localhost/wp-login.php" ) ? "up" : "";' ], { cwd: COMPOSE_DIR, encoding: 'utf8' } );
		if ( 'up' === ( up.stdout || '' ).trim() ) {
			return;
		}
		Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 500 );
	}
	throw new Error( 'The web server did not come back.' );
}

/** Makes OPcache skip wp-content, through a blacklist file, like a host policy would. */
function blacklistWpContent() {
	webRoot( `cp ${ FIXTURES }/opcache-blacklist.ini /usr/local/etc/php/conf.d/zz-ard-blacklist.ini && cp ${ FIXTURES }/ard-blacklist.txt /usr/local/etc/php/ard-blacklist.txt` );
	reloadPhp();
}

/** Removes the OPcache blacklist. */
function unblacklist() {
	webRoot( 'rm -f /usr/local/etc/php/conf.d/zz-ard-blacklist.ini /usr/local/etc/php/ard-blacklist.txt' );
	reloadPhp();
}

/** Installs the must-use fixture: loopback through the Docker network, captured mail. */
function installFixture() {
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-runtime-doctor/tests/e2e/fixtures/ard-e2e.php', WPMU_PLUGIN_DIR . '/ard-e2e.php' ); touch( WPMU_PLUGIN_DIR . '/ard-e2e.php', time() - 60 );` );
	return '';
}

/** Deletes the plugin's options and the fixture's. */
function reset() {
	for ( const option of OPTIONS ) {
		wpRun( 'option', 'delete', option );
	}
}

/**
 * The stored report.
 *
 * @return {Object|null} Report.
 */
function report() {
	const out = wpRun( 'option', 'get', 'axle_runtime_doctor_report', '--format=json' );
	return 0 === out.status ? JSON.parse( out.stdout ) : null;
}

/**
 * Runs every check from WP-CLI (through the probe) and returns the report.
 *
 * @return {Object} Report.
 */
function runChecks() {
	wpRun( 'axle-runtime-doctor', 'check' );
	return report();
}

/**
 * Whether WordPress is older than a version.
 *
 * @param {string} version Version.
 * @return {boolean} Older.
 */
function wpOlderThan( version ) {
	return '1' === wpEval( `echo version_compare( get_bloginfo( 'version' ), '${ version }', '<' ) ? 1 : 0;` );
}

/**
 * Creates a menu with many items straight in the database (the API takes a minute for 1,200).
 *
 * @param {string} name  Menu name.
 * @param {number} count Items.
 * @return {string} Menu term ID.
 */
function bigMenu( name, count ) {
	return wpEval( `
		global $wpdb;
		$menu = wp_create_nav_menu( ${ JSON.stringify( name ) } );
		$tt   = (int) get_term( $menu, 'nav_menu' )->term_taxonomy_id;
		$rows = array();
		for ( $i = 0; $i < ${ count }; $i++ ) {
			$rows[] = $wpdb->prepare( '( 1, NOW(), NOW(), %s, %s, %s, %s, %s, %d )', '', 'Link ' . $i, 'publish', 'nav_menu_item', '', $i );
		}
		$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_type, post_excerpt, menu_order ) VALUES " . implode( ',', $rows ) );
		$first = (int) $wpdb->insert_id;
		$links = array();
		for ( $i = 0; $i < ${ count }; $i++ ) {
			$links[] = '(' . ( $first + $i ) . ',' . $tt . ')';
		}
		$wpdb->query( "INSERT INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id ) VALUES " . implode( ',', $links ) );
		wp_update_term_count_now( array( $tt ), 'nav_menu' );
		clean_term_cache( $menu, 'nav_menu' );
		echo $menu;
	` );
}

/**
 * Deletes a menu made by bigMenu().
 *
 * @param {string} menu Menu term ID.
 */
function deleteBigMenu( menu ) {
	wpEval( `
		global $wpdb;
		$tt = (int) get_term( ${ Number( menu ) }, 'nav_menu' )->term_taxonomy_id;
		$wpdb->query( $wpdb->prepare( "DELETE p, r FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID WHERE r.term_taxonomy_id = %d", $tt ) );
		wp_delete_term( ${ Number( menu ) }, 'nav_menu' );
	` );
}

module.exports = { wpRun, webRoot, reloadPhp, blacklistWpContent, unblacklist, installFixture, reset, report, runChecks, wpOlderThan, bigMenu, deleteBigMenu, OPTIONS };
