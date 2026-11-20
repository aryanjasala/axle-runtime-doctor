// Which update made the site slower: the update log, the timing run, the email,
// "Confirm it", and the guarantees around the must-use loader behind it.
const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';
const DAY = `${ PAGE }&tab=compare&view=day`;
const SLOW = 'ard-slow-plugin/ard-slow-plugin.php';
const MARKER = '<!-- ard-slow-plugin-loaded -->';
const PROBE = 'X-Axle-Runtime-Doctor-Probe';
const json = ( ...args ) => JSON.parse( wp( ...args ) );

/** A token the way the plugin issues it for a request that leaves the slow plugin out. */
function issueToken( without = SLOW ) {
	return wpEval( `
		$token = \\AryanX\\Axle\\RuntimeDoctor\\Probe::issue( 'timed' );
		\\AryanX\\Axle\\RuntimeDoctor\\Timing\\Loader::allow( $token, ${ JSON.stringify( without ) } );
		echo $token;
	` );
}

test.describe( 'Which update made the site slower', () => {
	test.describe.configure( { mode: 'serial', timeout: 240_000 } );

	test.beforeAll( () => {
		h.reset();
		h.installFixture();
		h.installSlowPlugin();
	} );

	test.afterAll( () => {
		h.cleanupSlowdown();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-e2e.php' );` );
	} );

	test( 'an update is logged, the next timing names it, the email names it, and Confirm it points at the plugin', async ( { page, browser } ) => {
		h.seedSlowdown();

		// The update went on the log with the versions before and after.
		const updates = json( 'option', 'get', 'axle_runtime_doctor_updates', '--format=json' );
		expect( updates ).toHaveLength( 1 );
		expect( updates[ 0 ] ).toMatchObject( { type: 'plugin', name: 'Slow Fixture', from: '1.0.0', to: '1.1.0', file: SLOW } );

		// Two timings: the baseline and the one after the update, which matched the slowdown to it.
		const runs = json( 'option', 'get', 'axle_runtime_doctor_timings', '--format=json' );
		expect( runs ).toHaveLength( 2 );
		expect( runs[ 1 ].source ).toBe( 'update' );
		expect( runs[ 1 ].updates ).toEqual( [ updates[ 0 ].id ] );
		expect( runs[ 1 ].regressions ).toHaveLength( 1 );
		expect( runs[ 1 ].regressions[ 0 ].delta ).toBeGreaterThan( 300 );
		expect( runs[ 1 ].regressions[ 0 ].summary ).toMatch( /^Home page server time went from \d+ ms to \d+ ms after Slow Fixture 1\.0\.0 to 1\.1\.0 on \w+day\.$/ );
		expect( runs[ 0 ].pages[ '/' ].samples ).toHaveLength( 5 );
		expect( runs[ 0 ].pages[ '/' ].cached ).toBe( false );

		// The email says which update.
		const mail = json( 'option', 'get', 'ard_e2e_mail', '--format=json' );
		expect( mail ).toHaveLength( 1 );
		expect( mail[ 0 ].to ).toEqual( [ 'ops@example.com' ] );
		expect( mail[ 0 ].subject ).toContain( 'A page got slower: Home page server time went from' );
		expect( mail[ 0 ].subject ).toContain( 'after Slow Fixture 1.0.0 to 1.1.0' );

		// The screen shows it, and the update is on the timeline.
		await page.goto( DAY );
		await expect( page.locator( '.ard-slowdown' ) ).toContainText( 'after Slow Fixture 1.0.0 to 1.1.0' );
		await expect( page.locator( '.ard-timings tr[data-page="/"]' ) ).toContainText( 'Home page' );
		await expect( page.locator( '.ard-tl--update' ) ).toContainText( 'Slow Fixture 1.0.0 to 1.1.0' );
		await expect( page.locator( '.ard-tl--run' ) ).toHaveCount( 2 );

		// Confirm it: the page is loaded once without the plugin, and the plugin is named.
		await page.locator( '.ard-slowdown' ).getByRole( 'button', { name: /Confirm it/ } ).click();
		await expect( page.locator( '.ard-notice' ) ).toContainText( 'so Slow Fixture adds about' );
		await expect( page.locator( '.ard-slowdown .ard-confirm-result--confirmed' ) ).toBeVisible();
		const kept = json( 'option', 'get', 'axle_runtime_doctor_confirmations', '--format=json' );
		const result = kept[ updates[ 0 ].id ];
		expect( result.verdict ).toBe( 'confirmed' );
		expect( result.with_ms - result.without_ms ).toBeGreaterThan( 300 );
		expect( result.status ).toBe( 200 );

		// Nothing was switched off: the plugin is still active and every visitor still gets it.
		expect( h.wpRun( 'plugin', 'is-active', 'ard-slow-plugin' ).status ).toBe( 0 );
		expect( json( 'option', 'get', 'active_plugins', '--format=json' ) ).toContain( SLOW );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		expect( await ( await visitor.request.get( '/' ) ).text() ).toContain( MARKER );
		await visitor.close();
		// The one-time rows are gone.
		expect( wpEval( "global $wpdb; echo implode( ',', $wpdb->get_col( \"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'axle_runtime_doctor_probe_%'\" ) );" ) ).toBe( '' );
	} );

	test( 'WP-CLI lists the timings and the updates, and confirms an update', () => {
		const timing = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'timing', 'list', '--format=json' ).stdout );
		expect( timing ).toHaveLength( 2 );
		expect( timing[ 0 ] ).toMatchObject( { page: '/', slower: 'yes', source: 'update' } );

		const updates = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'updates', '--format=json' ).stdout );
		expect( updates[ 0 ] ).toMatchObject( { name: 'Slow Fixture', from: '1.0.0', to: '1.1.0' } );

		const confirm = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'confirm', updates[ 0 ].id, '--format=json' ).stdout );
		expect( confirm[ 0 ] ).toMatchObject( { plugin: 'Slow Fixture', verdict: 'confirmed' } );

		const unknown = h.wpRun( 'axle-runtime-doctor', 'confirm', 'nosuchid' );
		expect( unknown.status ).toBe( 1 );
		expect( unknown.stderr ).toContain( 'not a plugin that is active now' );
	} );

	test( 'a plugin that is not the cause is cleared', () => {
		// The page is as fast with the plugin as without it, so leaving the plugin out saves nothing.
		wp( 'option', 'update', 'ard_e2e_slow_ms', '0' );
		const update = json( 'option', 'get', 'axle_runtime_doctor_updates', '--format=json' )[ 0 ].id;
		const confirm = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'confirm', update, '--format=json' ).stdout );
		expect( confirm[ 0 ].verdict ).toBe( 'not' );
		expect( json( 'option', 'get', 'axle_runtime_doctor_confirmations', '--format=json' )[ update ].message ).toContain( 'is not the cause' );
	} );

	test( 'a forged, malformed, expired or reused token never changes a front-end request', async ( { browser } ) => {
		wp( 'option', 'update', 'ard_e2e_slow_ms', '0' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const get = async ( headers = {}, method = 'get' ) => ( await visitor.request[ method ]( '/', { headers } ) ).text();

		// A visitor: every plugin loads.
		expect( await get() ).toContain( MARKER );
		// A made-up token, a token of the wrong shape, an empty one.
		expect( await get( { [ PROBE ]: 'F'.repeat( 40 ) } ) ).toContain( MARKER );
		expect( await get( { [ PROBE ]: 'short' } ) ).toContain( MARKER );
		expect( await get( { [ PROBE ]: ' ' } ) ).toContain( MARKER );

		// A real token works once: the first request leaves the plugin out, the second, with the same token, does not.
		const token = issueToken();
		expect( token ).toMatch( /^[A-Za-z0-9]{40}$/ );
		expect( await get( { [ PROBE ]: token } ) ).not.toContain( MARKER );
		expect( await get( { [ PROBE ]: token } ) ).toContain( MARKER );
		expect( await get() ).toContain( MARKER );

		// An expired token does nothing, and a token that is only valid for a POST request does nothing either.
		const old = issueToken();
		wpEval( `$n = \\AryanX\\Axle\\RuntimeDoctor\\Timing\\Loader::row_name( ${ JSON.stringify( old ) } ); $r = get_option( $n ); $r['expires'] = time() - 5; update_option( $n, $r );` );
		expect( await get( { [ PROBE ]: old } ) ).toContain( MARKER );
		const post = issueToken();
		expect( await get( { [ PROBE ]: post }, 'post' ) ).toContain( MARKER );

		// A token for a plugin that does not exist, and for this plugin: nothing is left out.
		expect( await get( { [ PROBE ]: issueToken( 'nothing/nothing.php' ) } ) ).toContain( MARKER );
		const self = issueToken( 'axle-runtime-doctor/axle-runtime-doctor.php' );
		expect( await get( { [ PROBE ]: self } ) ).toContain( MARKER );
		expect( h.wpRun( 'plugin', 'is-active', 'axle-runtime-doctor' ).status ).toBe( 0 );

		// A forged token on the REST probe is refused as before.
		const rest = await visitor.request.post( '/index.php?rest_route=/axle-runtime-doctor/v1/probe', { headers: { [ PROBE ]: 'F'.repeat( 40 ) }, data: {} } );
		expect( rest.status() ).toBe( 401 );
		await visitor.close();
	} );

	test( 'a page loaded without a plugin changes nothing for the next visitor, even right after', async ( { browser } ) => {
		wp( 'option', 'update', 'ard_e2e_slow_ms', '0' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const token = issueToken();
		const [ without, plain ] = await Promise.all( [
			visitor.request.get( '/', { headers: { [ PROBE ]: token } } ).then( ( r ) => r.text() ),
			visitor.request.get( '/' ).then( ( r ) => r.text() ),
		] );
		expect( without ).not.toContain( MARKER );
		expect( plain ).toContain( MARKER );
		await visitor.close();
	} );
} );

test.describe( 'The Confirm it loader is opt-in and goes away', () => {
	test.describe.configure( { mode: 'serial', timeout: 240_000 } );

	test.beforeAll( () => {
		h.reset();
		h.installFixture();
		h.installSlowPlugin();
	} );

	test.afterAll( () => {
		h.cleanupSlowdown();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-e2e.php' );` );
	} );

	test( 'it is not written until the setting is on, and it is removed when the setting goes off', () => {
		expect( h.loaderInstalled() ).toBe( false );
		wp( 'axle-runtime-doctor', 'settings', '--confirm=on' );
		expect( h.loaderInstalled() ).toBe( true );
		expect( wpEval( "echo file_get_contents( WPMU_PLUGIN_DIR . '/axle-runtime-doctor-confirm.php' );" ) ).toContain( 'AXLE_RUNTIME_DOCTOR_CONFIRM_LOADER' );
		wp( 'axle-runtime-doctor', 'settings', '--confirm=off' );
		expect( h.loaderInstalled() ).toBe( false );
	} );

	test( 'with it off, a token row does nothing and Confirm it refuses', () => {
		const token = issueToken();
		expect( wpEval( `echo get_option( \\AryanX\\Axle\\RuntimeDoctor\\Timing\\Loader::row_name( ${ JSON.stringify( token ) } ) ) ? 'row' : 'none';` ) ).toBe( 'none' );
		const refused = h.wpRun( 'axle-runtime-doctor', 'confirm', 'abc' );
		expect( refused.status ).toBe( 1 );
		expect( refused.stderr ).toContain( 'Turn on "Confirm it"' );
	} );

	test( 'it is removed, and stays off, when the plugin is deactivated', () => {
		wp( 'axle-runtime-doctor', 'settings', '--confirm=on' );
		expect( h.loaderInstalled() ).toBe( true );
		wp( 'plugin', 'deactivate', 'axle-runtime-doctor' );
		expect( h.loaderInstalled() ).toBe( false );
		wp( 'plugin', 'activate', 'axle-runtime-doctor' );
		expect( h.loaderInstalled() ).toBe( false );
		expect( json( 'option', 'get', 'axle_runtime_doctor_settings', '--format=json' ).confirm ).toBe( false );
	} );

	test( 'it is removed on uninstall, with the data the feature kept', () => {
		h.seedSlowdown();
		expect( h.loaderInstalled() ).toBe( true );
		wpEval( "define( 'WP_UNINSTALL_PLUGIN', 'axle-runtime-doctor/axle-runtime-doctor.php' ); include WP_PLUGIN_DIR . '/axle-runtime-doctor/uninstall.php';" );
		expect( h.loaderInstalled() ).toBe( false );
		for ( const option of [ 'axle_runtime_doctor_updates', 'axle_runtime_doctor_timings', 'axle_runtime_doctor_confirmations', 'axle_runtime_doctor_settings' ] ) {
			expect( h.wpRun( 'option', 'get', option ).status ).toBe( 1 );
		}
		expect( h.wpRun( 'cron', 'event', 'list', '--format=csv' ).stdout ).not.toContain( 'axle_runtime_doctor_timing_run' );
	} );

	test( 'someone without the capability cannot time the pages or confirm an update', async ( { browser } ) => {
		h.seedSlowdown();
		const update = json( 'option', 'get', 'axle_runtime_doctor_updates', '--format=json' )[ 0 ].id;
		h.wpRun( 'user', 'delete', 'ard-editor', '--yes' );
		wp( 'user', 'create', 'ard-editor', 'ard-editor@example.com', '--role=editor', '--user_pass=ard-editor-pass' );
		try {
			const { context, page } = await logInAs( browser, 'ard-editor', 'ard-editor-pass' );
			for ( const action of [ 'axle_runtime_doctor_confirm', 'axle_runtime_doctor_measure' ] ) {
				const response = await page.request.post( '/wp-admin/admin-post.php', { form: { action, event: update } } );
				expect( response.status() ).toBe( 403 );
			}
			const screen = await page.request.get( DAY );
			expect( screen.status() ).toBe( 403 );
			await context.close();
		} finally {
			wp( 'user', 'delete', 'ard-editor', '--yes' );
		}
		// The admin without a valid nonce is refused too.
		const admin = await browser.newContext( { storageState: require( 'path' ).join( __dirname, '.auth', 'admin.json' ) } );
		const response = await admin.request.post( '/wp-admin/admin-post.php', { form: { action: 'axle_runtime_doctor_confirm', event: update } } );
		expect( response.status() ).toBe( 403 );
		await admin.close();
		expect( h.wpRun( 'option', 'get', 'axle_runtime_doctor_confirmations' ).status ).toBe( 1 );
	} );

	test( 'only the last Smoke Test result of a run starts a timing', () => {
		h.reset();
		wpEval( "do_action( 'axle_smoke_test_run_finished', array( 'id' => 'r1', 'verdict' => 'green', 'source' => 'manual', 'finished' => time(), 'final' => false ), array() );" );
		expect( h.wpRun( 'cron', 'event', 'list', '--format=csv' ).stdout ).not.toContain( 'axle_runtime_doctor_timing_run' );
		wpEval( "do_action( 'axle_smoke_test_run_finished', array( 'id' => 'r1', 'verdict' => 'green', 'source' => 'manual', 'finished' => time(), 'final' => true ), array() );" );
		expect( h.wpRun( 'cron', 'event', 'list', '--format=csv' ).stdout ).toContain( 'axle_runtime_doctor_timing_run' );
	} );

	test( 'the Compare tab has two views, and the old History and Slow queries addresses still work', async ( { page } ) => {
		h.seedSlowdown();
		h.runChecks();
		await page.goto( PAGE );
		await expect( page.locator( '.nav-tab' ) ).toHaveText( [ 'Checks', 'Page cache', 'Compare', 'Settings' ] );

		await page.goto( `${ PAGE }&tab=compare` );
		await expect( page.locator( '.ard-views a.current' ) ).toHaveText( 'With another day' );
		await expect( page.locator( '#ard-timeline' ) ).toBeVisible();
		await page.locator( '.ard-views' ).getByRole( 'link', { name: 'With another site' } ).click();
		await expect( page.locator( '#ard-compare' ) ).toBeVisible();

		await page.goto( `${ PAGE }&tab=history` );
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Compare' );
		await expect( page.locator( '#ard-timeline' ) ).toBeVisible();
		await page.goto( `${ PAGE }&tab=slow-queries` );
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Checks' );

		// A row of "Each check over time" opens its check.
		await page.goto( DAY );
		await page.locator( '.ard-history-checks tr[data-check="php_branch"] th a' ).click();
		await expect( page ).toHaveURL( /tab=checks.*#ard-check-php_branch|#ard-check-php_branch/ );
		await expect( page.locator( '#ard-check-php_branch' ) ).toBeVisible();
	} );

	test( 'the tables of History, Slow queries and the autoload list scroll inside their card at 360 px', async ( { page } ) => {
		h.runChecks();
		await page.setViewportSize( { width: 360, height: 800 } );
		await page.goto( DAY );
		for ( const table of [ '.ard-history-checks', '.ard-snapshots' ] ) {
			await expect( page.locator( `.axle-scroll ${ table }` ) ).toHaveCount( 1 );
		}
		await page.goto( PAGE );
		await page.locator( '#ard-check-db_slow_queries .ard-slow-details summary' ).click();
		await expect( page.locator( '.axle-scroll .ard-slow' ) ).toHaveCount( 1 );
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( overflow ).toBeLessThanOrEqual( 1 );
	} );
} );
