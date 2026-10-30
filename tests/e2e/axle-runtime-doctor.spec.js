const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';
const check = ( page, id ) => page.locator( `#ard-check-${ id }` );

test.describe( 'Axle Runtime Doctor', () => {
	test.beforeAll( () => {
		h.installFixture();
	} );

	test.beforeEach( () => {
		h.reset();
	} );

	test.afterAll( () => {
		h.reset();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-e2e.php' ); @unlink( WP_CONTENT_DIR . '/object-cache.php' );` );
	} );

	test( 'the first visit runs the checks in the web server and stores a snapshot', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '.ard-summary' ) ).toBeVisible( { timeout: 30_000 } );
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The checks ran.' );

		await expect( check( page, 'opcache_coverage' ).locator( '.ard-badge' ) ).toHaveText( 'Good' );
		await expect( check( page, 'opcache_coverage' ) ).toContainText( 'OPcache caches core and wp-content' );
		await expect( check( page, 'object_cache_roundtrip' ) ).toContainText( 'Not persistent' );
		await expect( check( page, 'cron_mode' ) ).toContainText( 'WP-Cron, events run on page visits' );
		await expect( page.locator( '.ard-host-name' ) ).toHaveText( 'Docker' );

		// Core 7.0 tests "OPcache enabled" itself; before it, this plugin does.
		const old = h.wpOlderThan( '7.0-alpha' );
		await expect( check( page, 'opcache_enabled' ) ).toHaveCount( old ? 1 : 0 );

		const report = h.report();
		expect( report.sapi ).toBe( 'web' );
		expect( report.results.opcache_coverage.status ).toBe( 'good' );
		expect( Boolean( report.results.opcache_enabled ) ).toBe( old );
		expect( JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_snapshots', '--format=json' ) ) ).toHaveLength( 1 );
	} );

	test( 'WP-CLI runs the checks through the web server, and says so when the loopback is blocked', async ( { page } ) => {
		const run = h.wpRun( 'axle-runtime-doctor', 'check', '--format=json' );
		// Exit code 1 means a critical result, such as PHP 7.4 on the oldest leg, which gets no security fixes.
		const cli = Object.fromEntries( JSON.parse( run.stdout ).map( ( row ) => [ row.id, row ] ) );
		expect( run.status ).toBe( 'critical' === cli.php_branch.status ? 1 : 0 );
		// OPcache is off in WP-CLI, so these values can only come from the web server.
		expect( cli.opcache_memory.value ).toMatch( /of 128 MB used/ );
		expect( cli.opcache_coverage.status ).toBe( 'good' );

		await page.goto( PAGE );
		await expect( check( page, 'opcache_memory' ).locator( '.ard-badge' ) ).toHaveText( 'Good' );
		await expect( check( page, 'php_execution_time' ).locator( '.ard-value' ) ).toHaveText( cli.php_execution_time.value );
		expect( h.report().sapi ).toBe( 'web' );

		wp( 'option', 'update', 'ard_e2e_loopback', 'off' );
		const blocked = h.wpRun( 'axle-runtime-doctor', 'check', 'opcache_coverage', 'db_autoload', '--format=json' );
		expect( blocked.stderr ).toContain( 'Could not reach the site through the loopback probe' );
		const rows = Object.fromEntries( JSON.parse( blocked.stdout ).map( ( row ) => [ row.id, row ] ) );
		expect( rows.opcache_coverage.summary ).toContain( 'Only the web server can check this' );
		expect( rows.db_autoload.status ).not.toBe( 'info' );
	} );

	test( 'OPcache skipping wp-content is critical, names the blacklist, and has the Pressable fix', async ( { page } ) => {
		h.blacklistWpContent();
		try {
			await page.goto( PAGE );
			await expect( page.locator( '.ard-summary' ) ).toBeVisible( { timeout: 30_000 } );
			const row = check( page, 'opcache_coverage' );
			await expect( row.locator( '.ard-badge' ) ).toHaveText( 'Critical' );
			await expect( row.locator( '.ard-check-summary' ) ).toHaveText( 'OPcache is skipping wp-content' );
			await row.locator( '.ard-why summary' ).click();
			await expect( row.locator( '.ard-why' ) ).toContainText( '/usr/local/etc/php/ard-blacklist.txt (line 1) matches wp-content' );
			await row.locator( '.ard-fix summary' ).click();
			await expect( row.locator( '.ard-fix summary' ) ).toHaveText( 'How to fix on Docker' );
			await expect( row.locator( '.ard-fix' ) ).toContainText( 'opcache.blacklist_filename' );
			expect( h.report().results.opcache_coverage.status ).toBe( 'critical' );

			const fix = h.wpRun( 'axle-runtime-doctor', 'fix', 'opcache_coverage', '--host=pressable' );
			expect( fix.stdout ).toContain( 'How to fix opcache_coverage on Pressable' );
			expect( fix.stdout ).toContain( 'Pressable support' );
			expect( fix.stdout ).toContain( 'the runtime check says: OPcache is skipping wp-content.' );

			// Picking the host in the settings changes the fix and the ticket on the screen.
			await page.goto( `${ PAGE }&tab=settings` );
			await page.locator( '#ard-host' ).selectOption( 'pressable' );
			await page.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
			expect( JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_settings', '--format=json' ) ).host ).toBe( 'pressable' );

			await page.goto( PAGE );
			await row.locator( '.ard-fix summary' ).click();
			await expect( row.locator( '.ard-fix summary' ) ).toHaveText( 'How to fix on Pressable' );
			await expect( page.locator( '.ard-host-name' ) ).toHaveText( 'Pressable' );
			await page.evaluate( () => window.wp.hooks.addFilter( 'axleRuntimeDoctor.ticketText', 'e2e', ( text, id, host ) => `${ text }\nRef: ${ id } on ${ host }` ) );
			await row.getByRole( 'button', { name: 'Copy support ticket' } ).click();
			await expect( row.locator( '.ard-copy' ) ).toHaveAttribute( 'data-copied', /OPcache is skipping wp-content[\s\S]*Ref: opcache_coverage on pressable$/ );
		} finally {
			h.unblacklist();
		}
	} );

	test( 'an object cache drop-in that forgets between requests is critical', async ( { page, rest } ) => {
		wpEval( `copy( WP_PLUGIN_DIR . '/axle-runtime-doctor/tests/e2e/fixtures/object-cache.php', WP_CONTENT_DIR . '/object-cache.php' );` );
		try {
			const run = await rest( 'POST', '/axle-runtime-doctor/v1/run' );
			expect( run.status ).toBe( 200 );
			expect( run.body.results.object_cache_roundtrip.status ).toBe( 'critical' );
			expect( h.report().results.object_cache_roundtrip.summary ).toBe( 'The object cache forgets everything between requests' );

			await page.goto( PAGE );
			await expect( check( page, 'object_cache_roundtrip' ).locator( '.ard-badge' ) ).toHaveText( 'Critical' );
			await expect( check( page, 'object_cache_dropin' ) ).toContainText( 'Lying Object Cache (e2e fixture)' );
		} finally {
			wpEval( `@unlink( WP_CONTENT_DIR . '/object-cache.php' );` );
		}
	} );

	test( 'DISABLE_WP_CRON with an overdue event means no real cron is running', async ( { page } ) => {
		wp( 'config', 'set', 'DISABLE_WP_CRON', 'true', '--raw' );
		wpEval( `wp_schedule_single_event( time() - HOUR_IN_SECONDS, 'ard_e2e_overdue' );` );
		try {
			const run = h.wpRun( 'axle-runtime-doctor', 'check', 'cron_mode', '--format=json' );
			expect( run.status ).toBe( 1 );
			expect( JSON.parse( run.stdout )[ 0 ] ).toMatchObject( { status: 'critical', summary: 'No real cron is running' } );

			h.runChecks();
			await page.goto( PAGE );
			const row = check( page, 'cron_mode' );
			await expect( row.locator( '.ard-badge' ) ).toHaveText( 'Critical' );
			await row.locator( '.ard-fix summary' ).click();
			await expect( row.locator( '.ard-fix' ) ).toContainText( 'wp-cron.php?doing_wp_cron' );
			expect( h.report().results.cron_mode.status ).toBe( 'critical' );
		} finally {
			wp( 'config', 'delete', 'DISABLE_WP_CRON' );
			wpEval( `wp_clear_scheduled_hook( 'ard_e2e_overdue' );` );
		}
	} );

	test( 'a 300 KB autoloaded option is listed, and its autoload can be turned off', async ( { page } ) => {
		wpEval( `add_option( 'ard_e2e_big', str_repeat( 'x', 300 * 1024 ), '', true );` );
		try {
			h.runChecks();
			await page.goto( PAGE );
			const row = page.locator( '.ard-autoload tr[data-option="ard_e2e_big"]' );
			await expect( row ).toContainText( 'over the size limit' );
			await expect( check( page, 'db_autoload' ).locator( '.ard-badge' ) ).toHaveText( 'Recommended' );
			await row.getByRole( 'button', { name: 'Turn autoload off' } ).click();
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Autoload is off for ard_e2e_big.' );
			expect( wp( 'option', 'get-autoload', 'ard_e2e_big' ) ).toBe( 'off' );
			await expect( page.locator( '.ard-autoload tr[data-option="ard_e2e_big"]' ) ).toHaveCount( 0 );

			const again = h.wpRun( 'axle-runtime-doctor', 'autoload', 'off', 'ard_e2e_big' );
			expect( again.stderr ).toContain( 'Autoload is already off' );
		} finally {
			wp( 'option', 'delete', 'ard_e2e_big' );
		}
	} );

	test( 'a 1,200 item menu with max_input_vars 1000 is named', async ( { page } ) => {
		const menu = h.bigMenu( 'Big footer', 1200 );
		try {
			const run = h.wpRun( 'axle-runtime-doctor', 'check', 'php_input_vars', '--format=json' );
			const row = JSON.parse( run.stdout )[ 0 ];
			expect( row ).toMatchObject( { status: 'recommended', value: '1000' } );
			expect( row.summary ).toBe( 'Saving the menu "Big footer" (1200 items) loses items' );

			h.runChecks();
			await page.goto( PAGE );
			await expect( check( page, 'php_input_vars' ) ).toContainText( 'Saving the menu "Big footer" (1200 items) loses items' );
			expect( h.report().results.php_input_vars.status ).toBe( 'recommended' );
		} finally {
			h.deleteBigMenu( menu );
		}
	} );

	test( 'a check that got worse in the daily run is emailed and shown until dismissed', async ( { page } ) => {
		wp( 'axle-runtime-doctor', 'settings', '--recipients=ops@example.com' );
		// Yesterday's snapshot: wp-config.php was not readable by everyone then.
		wpEval( `update_option( 'axle_runtime_doctor_snapshots', array( array( 'time' => time() - DAY_IN_SECONDS, 'host' => 'docker', 'sapi' => 'web', 'results' => array( 'fs_config' => array( 'status' => 'good', 'value' => '0640' ) ) ) ), false );` );
		wp( 'cron', 'event', 'run', 'axle_runtime_doctor_daily' );

		const snapshots = JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_snapshots', '--format=json' ) );
		expect( snapshots ).toHaveLength( 2 );
		expect( snapshots[ 1 ].regressions ).toEqual( [ 'fs_config' ] );
		const mail = JSON.parse( wp( 'option', 'get', 'ard_e2e_mail', '--format=json' ) );
		expect( mail ).toHaveLength( 1 );
		expect( mail[ 0 ].to ).toEqual( [ 'ops@example.com' ] );
		expect( mail[ 0 ].subject ).toContain( 'Runtime check got worse: Everyone on the server can read wp-config.php' );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.axle-runtime-doctor-notice' );
		await expect( notice ).toContainText( 'wp-config.php permissions: Good to Recommended.' );

		await page.goto( `${ PAGE }&tab=history` );
		await expect( page.locator( '.ard-history-checks tr.ard-regressed[data-check="fs_config"]' ) ).toContainText( 'regression' );
		await expect( page.locator( '.ard-snapshots tbody tr' ) ).toHaveCount( 2 );

		await page.goto( '/wp-admin/index.php' );
		await notice.getByRole( 'link', { name: 'Dismiss' } ).click();
		await expect( page.locator( '.axle-runtime-doctor-notice' ) ).toHaveCount( 0 );
		expect( h.wpRun( 'option', 'get', 'axle_runtime_doctor_notice' ).status ).toBe( 1 );
	} );

	test( 'front-end requests are sampled, and the screen runs and fires its JS action', async ( { page, browser } ) => {
		wp( 'axle-runtime-doctor', 'settings', '--sample-rate=1' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		await ( await visitor.newPage() ).goto( '/' );
		await visitor.close();
		const samples = JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_samples', '--format=json' ) );
		expect( samples.length ).toBeGreaterThan( 0 );
		expect( samples[ 0 ] ).toEqual( expect.objectContaining( { persistent: false } ) );
		expect( samples[ 0 ].peak ).toBeGreaterThan( 1000000 );

		h.runChecks();
		await page.goto( PAGE );
		await page.evaluate( () => window.wp.hooks.addAction( 'axleRuntimeDoctor.checksFinished', 'e2e', ( report ) => window.sessionStorage.setItem( 'ard-e2e', String( Object.keys( report.results ).length ) ) ) );
		await page.getByRole( 'button', { name: 'Run checks again' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The checks ran.' );
		expect( Number( await page.evaluate( () => window.sessionStorage.getItem( 'ard-e2e' ) ) ) ).toBeGreaterThan( 20 );
		expect( JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_snapshots', '--format=json' ) ) ).toHaveLength( 2 );
	} );

	test( 'the slow queries, history and settings tabs show and save what they should', async ( { page } ) => {
		h.runChecks();
		await page.goto( `${ PAGE }&tab=slow-queries` );
		await expect( page.locator( '.ard-pages li' ).first() ).toBeVisible();
		await expect( page.locator( '.ard-slow tbody tr' ).first() ).toBeVisible();
		await expect( page.locator( '.ard-slow' ) ).toContainText( 'SELECT' );
		const digests = h.report().slow_queries.digests;
		expect( digests.length ).toBeGreaterThan( 0 );
		// Literals are stripped before anything is stored.
		expect( JSON.stringify( digests ) ).not.toContain( "'admin'" );

		await page.goto( `${ PAGE }&tab=history` );
		await expect( page.locator( '.ard-history-checks tr[data-check="php_branch"] .ard-spark-dot' ) ).toHaveCount( 1 );

		await page.goto( `${ PAGE }&tab=settings` );
		await page.locator( '#ard-recipients' ).fill( 'one@example.com, not-an-email, two@example.com' );
		await page.locator( '#ard-t-loopback_ms' ).fill( '250' );
		await page.getByLabel( 'Sample front-end requests for the object cache hit rate and peak memory' ).uncheck();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		const settings = JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_settings', '--format=json' ) );
		expect( settings ).toMatchObject( { recipients: 'one@example.com, two@example.com', sampling: false, thresholds: { loopback_ms: 250 } } );
		await expect( page.locator( '#ard-t-loopback_ms' ) ).toHaveValue( '250' );
	} );

	test( 'Site Health gets the important checks and an Info section', async ( { page } ) => {
		h.runChecks();
		const tests = JSON.parse( wpEval( `require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php'; echo wp_json_encode( array_keys( WP_Site_Health::get_tests()['direct'] ) );` ) );
		expect( tests ).toContain( 'axle_runtime_doctor_opcache_coverage' );
		expect( tests.includes( 'axle_runtime_doctor_opcache_enabled' ) ).toBe( h.wpOlderThan( '7.0-alpha' ) );

		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.locator( '#health-check-site-status-good' ) ).toContainText( 'OPcache caches core and wp-content', { timeout: 30_000 } );
		await page.goto( '/wp-admin/site-health.php?tab=debug' );
		await expect( page.locator( '#health-check-accordion-block-axle-runtime-doctor' ) ).toContainText( 'OPcache coverage' );
	} );

	test( 'an editor is refused on the screen, the REST routes, the probe and the abilities', async ( { browser } ) => {
		try {
			wp( 'user', 'create', 'ard-editor', 'ard-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		} catch ( error ) {
			// Already there.
		}
		const { context, page } = await logInAs( browser, 'ard-editor', 'editor-pass-123' );
		const screen = await page.goto( PAGE );
		expect( screen.status() ).toBe( 403 );
		await context.close();

		const client = await appPasswordClient( 'ard-editor', 'ard-e2e' );
		try {
			expect( ( await client.call( 'POST', '/axle-runtime-doctor/v1/run' ) ).status ).toBe( 403 );
			expect( ( await client.call( 'GET', '/axle-runtime-doctor/v1/report' ) ).status ).toBe( 403 );
			expect( ( await client.call( 'POST', '/axle-runtime-doctor/v1/probe' ) ).status ).toBe( 403 );
			if ( ! h.wpOlderThan( '6.9' ) ) {
				expect( ( await client.call( 'GET', '/wp-abilities/v1/abilities/axle-runtime-doctor/get-report/run' ) ).status ).toBe( 403 );
				expect( ( await client.call( 'POST', '/wp-abilities/v1/abilities/axle-runtime-doctor/disable-autoload/run', { input: { options: [ 'blogname' ] } } ) ).status ).toBe( 403 );
			}
		} finally {
			await client.dispose();
		}
		expect( h.wpRun( 'option', 'get', 'axle_runtime_doctor_report' ).status ).toBe( 1 );
	} );

	test( 'abilities run the checks, read the report and the fix, and turn autoload off', async ( { rest } ) => {
		test.skip( h.wpOlderThan( '6.9' ), 'The Abilities API arrived in WordPress 6.9.' );
		wpEval( `add_option( 'ard_e2e_big2', str_repeat( 'y', 150 * 1024 ), '', true );` );
		try {
			const base = '/wp-abilities/v1/abilities/axle-runtime-doctor';
			const run = await rest( 'GET', `${ base }/run-checks/run?input[group]=php` );
			expect( run.status ).toBe( 200 );
			expect( run.body.sapi ).toBe( 'web' );
			expect( run.body.results.map( ( row ) => row.id ) ).toContain( 'php_branch' );
			expect( run.body.results.every( ( row ) => 'php' === row.group ) ).toBe( true );
			// A partial run is not a snapshot.
			expect( h.wpRun( 'option', 'get', 'axle_runtime_doctor_snapshots' ).status ).toBe( 1 );

			h.runChecks();
			const report = await rest( 'GET', `${ base }/get-report/run` );
			expect( report.body.results.find( ( row ) => 'opcache_coverage' === row.id ).status ).toBe( 'good' );

			const fix = await rest( 'GET', `${ base }/get-fix/run?input[check]=cron_mode&input[host]=cpanel` );
			expect( fix.body ).toMatchObject( { check: 'cron_mode', host: 'cPanel', where: 'cPanel > Cron Jobs' } );

			const slow = await rest( 'GET', `${ base }/list-slow-queries/run?input[limit]=3` );
			expect( slow.body.queries.length ).toBeLessThanOrEqual( 3 );
			expect( slow.body.pages.length ).toBeGreaterThan( 0 );

			const off = await rest( 'POST', `${ base }/disable-autoload/run`, { input: { options: [ 'ard_e2e_big2', 'ard_e2e_missing' ] } } );
			expect( off.status ).toBe( 200 );
			expect( off.body.changed ).toEqual( [ 'ard_e2e_big2' ] );
			expect( off.body.skipped ).toHaveProperty( 'ard_e2e_missing' );
			expect( wp( 'option', 'get-autoload', 'ard_e2e_big2' ) ).toBe( 'off' );
		} finally {
			wp( 'option', 'delete', 'ard_e2e_big2' );
		}
	} );
} );
