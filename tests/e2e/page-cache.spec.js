const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';
const TAB = `${ PAGE }&tab=page-cache`;
const SCAN = 'axle_runtime_doctor_page_cache';

/**
 * The stored page cache test.
 *
 * @return {Object|null} Scan.
 */
function scan() {
	const out = h.wpRun( 'option', 'get', SCAN, '--format=json' );
	return 0 === out.status ? JSON.parse( out.stdout ) : null;
}

/**
 * Picks what the front end of the test site does, and forgets the fake cache.
 *
 * @param {string} mode cookie, nocache, session, donotcache, hit or an empty string.
 */
function frontEnd( mode ) {
	h.wpRun( 'transient', 'delete', '--all' );
	if ( mode ) {
		wp( 'option', 'update', 'ard_e2e_cache', mode );
	} else {
		h.wpRun( 'option', 'delete', 'ard_e2e_cache' );
	}
}

/**
 * Runs the test from the screen and waits for the answer.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function runTest( page ) {
	await page.goto( TAB );
	await page.locator( '.ard-cache-form button[type="submit"]' ).click();
	await expect( page.locator( '.ard-notice' ) ).toBeVisible( { timeout: 90_000 } );
}

test.describe( 'Page cache', () => {
	test.beforeAll( () => {
		h.installFixture();
	} );

	test.beforeEach( () => {
		h.reset();
		frontEnd( '' );
	} );

	test.afterAll( () => {
		h.reset();
		frontEnd( '' );
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-e2e.php' );` );
	} );

	test( 'a first visit explains what the test does, and the card goes away when hidden', async ( { page } ) => {
		await page.goto( TAB );
		await expect( page.locator( '.ard-started' ) ).toContainText( 'Get started' );
		await expect( page.locator( '#ard-cache-run .axle-empty' ) ).toContainText( 'No test yet' );
		await expect( page.locator( '#ard-cache-result' ) ).toHaveCount( 0 );
		expect( scan() ).toBeNull();

		// The JavaScript action fires when the test starts, and the form still waits for the server.
		await page.evaluate( () => {
			window.ardStarted = null;
			window.wp.hooks.addAction( 'axleRuntimeDoctor.pageCacheStarted', 'ard-e2e', ( text ) => {
				window.ardStarted = text;
			} );
			document.querySelector( '.ard-cache-form' ).addEventListener( 'submit', ( event ) => event.preventDefault(), true );
		} );
		await page.locator( '.ard-cache-form button[type="submit"]' ).click();
		await expect( page.locator( '.ard-cache-form' ) ).toHaveAttribute( 'aria-busy', 'true' );
		expect( await page.evaluate( () => window.ardStarted ) ).toBe( 'Testing the page cache. This takes a few seconds.' );
		await expect( page.locator( '.ard-cache-form .spinner' ) ).toHaveClass( /is-active/ );

		await page.getByRole( 'link', { name: 'Hide this card' } ).click();
		await expect( page.locator( '.ard-started' ) ).toHaveCount( 0 );
		expect( wp( 'user', 'meta', 'get', '1', 'axle_runtime_doctor_cache_state', '--format=json' ) ).toContain( '"dismissed":true' );
	} );

	test( 'a cookie on the page is found, the plugin behind it is named, and the host fix is shown', async ( { page } ) => {
		frontEnd( 'cookie' );
		await runTest( page );

		await expect( page.locator( '.ard-notice' ) ).toContainText( 'Pages send cookies or headers that stop page caching' );
		await expect( page.locator( '#ard-cache-result .axle-badge' ).first() ).toHaveText( 'Recommended' );
		await expect( page.locator( '.ard-cache-pages' ) ).toContainText( 'ard_visitor' );
		await expect( page.locator( '.ard-cache-pages' ).first() ).toContainText( 'No cache seen' );
		// The cookie is set on template_redirect, and the fixture is the only plugin with code on that hook.
		await expect( page.locator( '.ard-cache-details' ) ).toContainText( 'only must-use plugin ard-e2e.php has callbacks on it. It is probably that one' );
		await expect( page.locator( '.ard-fix' ) ).toContainText( 'How to fix on Docker' );

		const stored = scan();
		expect( stored.pages.length ).toBeGreaterThan( 0 );
		expect( stored.pages[ 0 ].cookies ).toEqual( [ 'ard_visitor' ] );
		expect( stored.pages[ 0 ].via ).toBe( 'loopback' );
		expect( JSON.stringify( stored ) ).not.toContain( 'Set-Cookie' );
		expect( stored.pages[ 0 ].trace.reached ).toBe( true );
		expect( stored.pages[ 0 ].trace.signals[ 0 ].suspects ).toEqual( [ 'mu-plugin:ard-e2e.php' ] );
		expect( stored.pages[ 0 ].trace.signals[ 0 ].certain ).toBe( false );
	} );

	test( 'a nocache_headers() call is seen and the plugin is named for sure', async ( { page } ) => {
		frontEnd( 'nocache' );
		await runTest( page );

		await expect( page.locator( '.ard-cache-details' ) ).toContainText( 'nocache_headers() was called by must-use plugin ard-e2e.php. This is seen, not guessed.' );
		await expect( page.locator( '.ard-cache-pages' ) ).toContainText( 'Cache-Control says' );

		const check = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'check', 'page_cache', '--format=json' ).stdout )[ 0 ];
		expect( check.id ).toBe( 'page_cache' );
		expect( check.status ).toBe( 'recommended' );
		const stored = scan();
		expect( stored.pages[ 0 ].trace.signals.some( ( signal ) => 'nocache_headers' === signal.type && true === signal.certain ) ).toBe( true );
	} );

	test( 'a PHP session is found from its cookie and headers', async ( { page } ) => {
		frontEnd( 'session' );
		await runTest( page );

		await expect( page.locator( '.ard-cache-pages' ) ).toContainText( 'A PHP session was started (cookie PHPSESSID)' );
		await expect( page.locator( '.ard-cache-details' ) ).toContainText( 'Seen: the cookie PHPSESSID' );
		await expect( page.locator( '.ard-cache-details' ) ).toContainText( 'The cookie PHPSESSID points at: PHP session_start()' );
		expect( ( await page.locator( '.ard-cache-details li' ).allTextContents() ).filter( ( text ) => text.includes( 'appeared during the template_redirect hook' ) ) ).toHaveLength( 1 );
		expect( scan().pages[ 0 ].cookies ).toContain( 'PHPSESSID' );
	} );

	test( 'DONOTCACHEPAGE is found by the trace, since no header shows it', async ( { page } ) => {
		frontEnd( 'donotcache' );
		await runTest( page );

		await expect( page.locator( '.ard-cache-pages' ) ).toContainText( 'The constant DONOTCACHEPAGE is defined on the page' );
		await expect( page.locator( '.ard-cache-details' ) ).toContainText( 'The constant DONOTCACHEPAGE' );
		const stored = scan();
		expect( stored.pages[ 0 ].blockers.some( ( blocker ) => 'nocache' === blocker.type ) ).toBe( true );
		expect( stored.pages[ 0 ].cookies ).toEqual( [] );
	} );

	test( 'a page a cache answers is good, and Site Health, WP-CLI and the daily history agree', async ( { page } ) => {
		frontEnd( 'hit' );
		await runTest( page );

		await expect( page.locator( '.ard-notice' ) ).toContainText( 'A cache answered every page we tested' );
		await expect( page.locator( '.ard-cache-pages .axle-badge--good' ).first() ).toHaveText( 'Answered from a cache' );
		await expect( page.locator( '.ard-cache-pages' ).first() ).toContainText( 'Kinsta cache' );
		await expect( page.locator( '.ard-fix' ) ).toHaveCount( 0 );
		expect( scan().pages[ 0 ].answered ).toBe( 'hit' );
		expect( scan().pages[ 0 ].trace ).toBeNull();

		const rows = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'page-cache', '--stored', '--format=json' ).stdout );
		expect( rows[ 0 ].answered ).toBe( 'hit' );
		expect( rows[ 0 ].by ).toBe( 'Kinsta cache' );

		// A full run is part of the history: the result is stored in the snapshot.
		frontEnd( 'hit' );
		const report = h.runChecks();
		expect( report.results.page_cache.status ).toBe( 'good' );
		const snapshots = JSON.parse( wp( 'option', 'get', 'axle_runtime_doctor_snapshots', '--format=json' ) );
		expect( snapshots[ snapshots.length - 1 ].results.page_cache.status ).toBe( 'good' );

		await page.goto( '/wp-admin/site-health.php' );
		// A passed test sits in the collapsed "Passed tests" list.
		await expect( page.locator( '#health-check-issues-good' ) ).toContainText( 'A cache answered every page we tested', { timeout: 30_000 } );
	} );

	test( 'the checks tab lists the result and links to the page cache tab', async ( { page } ) => {
		frontEnd( 'cookie' );
		h.runChecks();
		await page.goto( PAGE );
		const card = page.locator( '#ard-check-page_cache' );
		await expect( card ).toBeVisible( { timeout: 30_000 } );
		await card.getByRole( 'link', { name: 'See each page and what stops its cache' } ).click();
		await expect( page.locator( '.ard-cache-pages' ) ).toBeVisible();
	} );

	test( 'an editor cannot run the test, and the ability reads the last test without changing anything', async ( { browser, rest } ) => {
		try {
			wp( 'user', 'create', 'ard-cache-editor', 'ard-cache-editor@example.com', '--role=editor', '--user_pass=ard-cache-pass' );
		} catch ( error ) {
			// Already there.
		}
		const { context, page } = await logInAs( browser, 'ard-cache-editor', 'ard-cache-pass' );
		try {
			const denied = await page.goto( TAB );
			expect( denied.status() ).toBe( 403 );
			const post = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'axle_runtime_doctor_cache_test' } } );
			expect( post.status() ).toBe( 403 );
			expect( scan() ).toBeNull();
		} finally {
			await context.close();
		}

		if ( ! h.wpOlderThan( '6.9' ) ) {
			const base = '/wp-abilities/v1/abilities/axle-runtime-doctor/get-page-cache';
			const none = await rest( 'GET', `${ base }/run` );
			expect( none.status ).toBe( 200 );
			expect( none.body.tested ).toBe( false );

			frontEnd( 'cookie' );
			const fresh = await rest( 'GET', `${ base }/run?input[refresh]=1` );
			expect( fresh.body.tested ).toBe( true );
			expect( fresh.body.pages[ 0 ].stops ).toContain( 'cookie (ard_visitor)' );
			expect( ( await rest( 'POST', `${ base }/run`, { input: {} } ) ).status ).toBe( 405 );

			const client = await appPasswordClient( 'ard-cache-editor', 'ard-cache-e2e' );
			try {
				expect( ( await client.call( 'GET', `${ base }/run` ) ).status ).toBe( 403 );
			} finally {
				await client.dispose();
			}
		}
		h.wpRun( 'user', 'delete', 'ard-cache-editor', '--yes' );
	} );
} );
