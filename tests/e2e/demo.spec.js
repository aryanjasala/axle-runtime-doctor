// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';

test( 'demo: find OPcache skipping wp-content and send the host a ticket', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	prepareSite( 'Northwind Books' );
	h.installFixture();
	h.reset();
	h.runChecks();
	h.blacklistWpContent();

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( PAGE );
			await demo.start();
			await demo.pause( 1200 );
			await demo.click( page.getByRole( 'button', { name: 'Run checks again' } ), 300 );
			await expect( page.locator( '#ard-check-opcache_coverage .ard-badge' ) ).toHaveText( 'Critical', { timeout: 30_000 } );
			const row = page.locator( '#ard-check-opcache_coverage' );
			await demo.moveTo( row.locator( '.ard-check-summary' ) );
			await demo.pause( 1500 );
			await demo.click( row.locator( '.ard-why summary' ), 1800 );

			// Tell the plugin the host, and the fix and ticket follow.
			await page.goto( `${ PAGE }&tab=settings` );
			await demo.choose( page.locator( '#ard-host' ), 'pressable' );
			await demo.click( page.getByRole( 'button', { name: 'Save settings' } ), 600 );
			await page.goto( PAGE );
			await demo.click( row.locator( '.ard-fix summary' ), 900 );
			await demo.moveTo( row.locator( '.ard-ticket' ) );
			await demo.pause( 2200 );
			await demo.click( row.getByRole( 'button', { name: 'Copy support ticket' } ), 1200 );
		} );
	} finally {
		h.unblacklist();
		h.reset();
		wp( 'option', 'update', 'blogname', 'E2E' );
	}
} );
