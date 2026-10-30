// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );

	test.beforeAll( () => {
		prepareSite( 'Northwind Books' );
		h.installFixture();
		h.reset();
		for ( const title of [ 'Autumn reading list', 'Meet the author: Ana Ruiz', 'Our new store hours' ] ) {
			wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', '--porcelain' );
		}
		wp( 'post', 'create', '--post_type=page', '--post_title=About us', '--post_status=publish', '--porcelain' );
		wp( 'axle-runtime-doctor', 'settings', '--host=pressable' );
		h.blacklistWpContent();
		h.runChecks();
		// Last week, before the host moved the site: wp-content was cached.
		wpEval( `$s = get_option( 'axle_runtime_doctor_snapshots' ); $now = end( $s ); $runs = array(); for ( $i = 6; $i >= 1; $i-- ) { $old = $now; $old['time'] = time() - $i * DAY_IN_SECONDS; $old['regressions'] = array(); $old['results']['opcache_coverage']['status'] = 'good'; $runs[] = $old; } $now['regressions'] = array( 'opcache_coverage' ); $runs[] = $now; update_option( 'axle_runtime_doctor_snapshots', $runs, false ); delete_option( 'axle_runtime_doctor_notice' );` );
	} );

	test.afterAll( () => {
		h.unblacklist();
		h.reset();
		wp( 'site', 'empty', '--yes' );
	} );

	test( '1: the checks, with OPcache skipping wp-content', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '#ard-check-opcache_coverage .ard-badge' ) ).toHaveText( 'Critical' );
		await page.locator( '#ard-check-opcache_coverage .ard-why summary' ).click();
		await shot( page, 1 );
	} );

	test( '2: how to fix it on the host, with the support ticket', async ( { page } ) => {
		await page.goto( PAGE );
		const row = page.locator( '#ard-check-opcache_coverage' );
		await row.locator( '.ard-fix summary' ).click();
		await row.scrollIntoViewIfNeeded();
		await page.evaluate( () => document.querySelector( '#ard-check-opcache_coverage' ).scrollIntoView( { block: 'start' } ) );
		await page.evaluate( () => window.scrollBy( 0, -60 ) );
		await shot( page, 2 );
	} );

	test( '3: history, with the day OPcache stopped caching wp-content', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=history` );
		await expect( page.locator( '.ard-history-checks tr.ard-regressed' ) ).toHaveCount( 1 );
		await shot( page, 3 );
	} );

	test( '4: slow queries on the key pages', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=slow-queries` );
		await expect( page.locator( '.ard-slow tbody tr' ).first() ).toBeVisible();
		await shot( page, 4 );
	} );
} );
