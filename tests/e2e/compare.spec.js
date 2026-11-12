const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const h = require( './helpers' );

const PAGE = '/wp-admin/tools.php?page=axle-runtime-doctor';
const COMPARE = `${ PAGE }&tab=compare`;
const IMPORTS = 'axle_runtime_doctor_imports';

/**
 * The kept reports.
 *
 * @return {Object} ID to item.
 */
function imports() {
	const out = h.wpRun( 'option', 'get', IMPORTS, '--format=json' );
	return 0 === out.status ? JSON.parse( out.stdout ) : {};
}

/**
 * This site's exported report, changed to look like another site.
 *
 * @return {Object} Report document.
 */
function otherSite() {
	h.runChecks();
	const other = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'export' ).stdout );
	other.results.opcache_coverage.status = 'critical';
	other.results.opcache_coverage.summary = 'OPcache is skipping wp-content';
	other.settings[ 'opcache.max_accelerated_files' ].value = '2000';
	return other;
}

test.describe( 'Compare two sites', () => {
	test.beforeAll( () => {
		h.installFixture();
	} );

	test.beforeEach( () => {
		h.reset();
	} );

	test.afterAll( () => {
		h.reset();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-e2e.php' );` );
	} );

	test( 'a first visit explains what to do, and the export holds no paths or hostnames', async ( { page } ) => {
		await page.goto( COMPARE );
		await expect( page.locator( '.ard-started' ) ).toContainText( 'Get started' );
		await expect( page.locator( '#ard-compare .axle-empty' ) ).toContainText( 'Run the checks on this site first' );

		h.runChecks();
		await page.goto( COMPARE );
		await expect( page.locator( '.ard-started .axle-badge--good' ) ).toHaveCount( 1 );
		await expect( page.locator( '#ard-compare .axle-empty' ) ).toContainText( 'Nothing to compare with yet' );
		await expect( page.locator( '#ard-saved .axle-empty' ) ).toContainText( 'No reports saved yet' );

		const exported = h.wpRun( 'axle-runtime-doctor', 'export' ).stdout;
		const document = JSON.parse( exported );
		expect( document.format ).toBe( 'axle-runtime-doctor-report' );
		expect( Object.keys( document.results ).length ).toBeGreaterThan( 20 );
		expect( document.settings[ 'opcache.memory_consumption' ].value ).toBe( '128' );
		expect( exported ).not.toContain( '/var/www/html' );
		expect( exported ).not.toContain( 'localhost' );
		expect( exported ).not.toContain( '"details"' );

		const download = page.waitForEvent( 'download' );
		await page.locator( '.ard-export' ).click();
		const file = await download;
		expect( file.suggestedFilename() ).toMatch( /^runtime-report-\d{4}-\d{2}-\d{2}\.json$/ );

		await page.getByRole( 'button', { name: 'Hide this card' } ).or( page.getByRole( 'link', { name: 'Hide this card' } ) ).click();
		await expect( page.locator( '.ard-started' ) ).toHaveCount( 0 );
	} );

	test( 'a pasted report is compared, differences first, and can be removed and put back', async ( { page } ) => {
		const other = otherSite();
		await page.goto( COMPARE );
		await page.fill( '#ard-import-name', 'Production' );
		await page.fill( '#ard-import-text', JSON.stringify( other ) );
		await page.getByRole( 'button', { name: 'Import the report' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Saved the report "Production"' );
		const kept = Object.values( imports() );
		expect( kept ).toHaveLength( 1 );
		expect( kept[ 0 ] ).toMatchObject( { name: 'Production', source: 'paste' } );
		expect( kept[ 0 ].report.settings[ 'opcache.max_accelerated_files' ].value ).toBe( '2000' );

		await expect( page.locator( '.ard-compare-stats' ) ).toContainText( 'differences' );
		const first = page.locator( '.ard-compare tbody tr' ).first();
		await expect( first ).toHaveAttribute( 'data-key', 'opcache_coverage' );
		await expect( first ).toContainText( 'Production is critical: OPcache is skipping wp-content' );
		const files = page.locator( '.ard-compare tr[data-key="opcache.max_accelerated_files"]' );
		await expect( files ).toContainText( '4,000 files on This site (latest run) and 2,000 on Production' );
		await expect( files ).toContainText( 'Production runs out of slots first and recompiles PHP' );
		await expect( page.locator( '.ard-compare-more' ).first() ).toBeVisible();

		// Remove, with the in-place confirm and Undo.
		const row = page.locator( '.ard-saved tr[data-report]' ).first();
		await row.getByRole( 'button', { name: /Remove/ } ).click();
		const confirm = row.locator( '.axle-confirm' );
		await expect( confirm ).toBeVisible();
		await expect( confirm ).toContainText( 'You can undo it for 15 minutes' );
		await expect( confirm.getByRole( 'button', { name: 'Remove the report' } ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( confirm ).toBeHidden();
		await expect( row.getByRole( 'button', { name: /Remove/ } ) ).toBeFocused();
		expect( Object.keys( imports() ) ).toHaveLength( 1 );

		await row.getByRole( 'button', { name: /Remove/ } ).click();
		await confirm.getByRole( 'button', { name: 'Remove the report' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Removed the report "Production"' );
		expect( Object.keys( imports() ) ).toHaveLength( 0 );
		await page.locator( '.notice-success' ).getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Put back the report "Production"' );
		expect( Object.keys( imports() ) ).toHaveLength( 1 );
	} );

	test( 'a report that is not a report is refused, and nothing is kept', async ( { page } ) => {
		await page.goto( COMPARE );
		await page.fill( '#ard-import-text', 'O:8:"stdClass":0:{}' );
		await page.getByRole( 'button', { name: 'Import the report' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'That is not valid JSON' );

		await page.fill( '#ard-import-text', '{"format":"other","version":1,"results":{}}' );
		await page.getByRole( 'button', { name: 'Import the report' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'not a Runtime Doctor report' );

		await page.setInputFiles( '#ard-import-file', { name: 'big.json', mimeType: 'application/json', buffer: Buffer.alloc( 300 * 1024, 'x' ) } );
		await page.getByRole( 'button', { name: 'Import the report' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'too large' );
		expect( imports() ).toEqual( {} );
	} );

	test( 'an uploaded file is read as data and markup in it is stripped', async ( { page } ) => {
		const other = otherSite();
		other.results.opcache_coverage.summary = '<img src=x onerror=alert(1)>Skipping wp-content';
		other.results.opcache_coverage.evil = { nested: true };
		await page.goto( COMPARE );
		await page.setInputFiles( '#ard-import-file', { name: 'staging.json', mimeType: 'application/json', buffer: Buffer.from( JSON.stringify( other ) ) } );
		await page.getByRole( 'button', { name: 'Import the report' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Saved the report "staging"' );
		const kept = Object.values( imports() )[ 0 ];
		expect( kept.source ).toBe( 'file' );
		expect( kept.report.results.opcache_coverage.summary ).toBe( 'Skipping wp-content' );
		expect( kept.report.results.opcache_coverage ).not.toHaveProperty( 'evil' );
		await expect( page.locator( '.ard-compare img' ) ).toHaveCount( 0 );
	} );

	test( 'WP-CLI exports, compares, keeps, lists and removes reports', async () => {
		const other = otherSite();
		const file = '/tmp/ard-e2e-other.json';
		wpEval( `file_put_contents( '${ file }', ${ JSON.stringify( JSON.stringify( other ) ) } );` );

		const out = h.wpRun( 'axle-runtime-doctor', 'export', '--file=/tmp/ard-e2e-own.json' );
		expect( out.stdout ).toContain( 'Wrote /tmp/ard-e2e-own.json' );

		const same = h.wpRun( 'axle-runtime-doctor', 'compare', '/tmp/ard-e2e-own.json', '--format=json' );
		expect( same.status ).toBe( 0 );
		expect( JSON.parse( same.stdout ) ).toEqual( [] );

		const compare = h.wpRun( 'axle-runtime-doctor', 'compare', file, '--format=json' );
		expect( compare.status ).toBe( 1 );
		const rows = JSON.parse( compare.stdout );
		expect( rows[ 0 ].key ).toBe( 'opcache_coverage' );
		expect( rows.find( ( row ) => 'opcache.max_accelerated_files' === row.key ).meaning ).toContain( 'and 2,000 on' );

		const kept = h.wpRun( 'axle-runtime-doctor', 'import', file, '--name=Before the move' );
		expect( kept.stdout ).toContain( 'Kept "Before the move"' );
		const list = JSON.parse( h.wpRun( 'axle-runtime-doctor', 'imports', '--format=json' ).stdout );
		expect( list ).toHaveLength( 1 );
		expect( list[ 0 ].name ).toBe( 'Before the move' );
		expect( h.wpRun( 'axle-runtime-doctor', 'compare', list[ 0 ].id, '--format=json' ).status ).toBe( 1 );
		expect( h.wpRun( 'axle-runtime-doctor', 'remove-import', list[ 0 ].id ).stdout ).toContain( 'Removed' );
		expect( imports() ).toEqual( {} );

		expect( h.wpRun( 'axle-runtime-doctor', 'import', '/tmp/does-not-exist.json' ).stderr ).toContain( 'Cannot read' );
		wpEval( `@unlink( '${ file }' ); @unlink( '/tmp/ard-e2e-own.json' );` );
	} );

	test( 'a one-time code works once, and direct mode fetches the report over the network', async ( { request, page } ) => {
		h.runChecks();
		const code = h.wpRun( 'axle-runtime-doctor', 'direct-code' ).stdout.split( '\n' )[ 0 ].trim();
		expect( code ).toMatch( /^ard1\.[\w-]+\.[0-9a-f]{6}$/ );
		const token = JSON.parse( Buffer.from( code.split( '.' )[ 1 ].replace( /-/g, '+' ).replace( /_/g, '/' ), 'base64' ).toString() ).t;

		const route = '/index.php?rest_route=/axle-runtime-doctor/v1/share';
		expect( ( await request.get( route, { headers: { 'X-Axle-Runtime-Doctor-Probe': 'nope' } } ) ).status() ).toBe( 401 );
		const first = await request.get( route, { headers: { 'X-Axle-Runtime-Doctor-Probe': token } } );
		expect( first.status() ).toBe( 200 );
		expect( first.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		expect( ( await first.json() ).format ).toBe( 'axle-runtime-doctor-report' );
		expect( ( await request.get( route, { headers: { 'X-Axle-Runtime-Doctor-Probe': token } } ) ).status() ).toBe( 401 );

		// The share token does not open the probe route.
		const probeCode = h.wpRun( 'axle-runtime-doctor', 'direct-code' ).stdout.split( '\n' )[ 0 ].trim();
		const probeToken = JSON.parse( Buffer.from( probeCode.split( '.' )[ 1 ].replace( /-/g, '+' ).replace( /_/g, '/' ), 'base64' ).toString() ).t;
		const probe = await request.post( '/index.php?rest_route=/axle-runtime-doctor/v1/probe', { headers: { 'X-Axle-Runtime-Doctor-Probe': probeToken }, data: '{}' } );
		expect( [ 401, 403 ] ).toContain( probe.status() );

		// An http address is refused: the code would travel in the clear.
		const refused = h.wpRun( 'axle-runtime-doctor', 'fetch', probeCode );
		expect( refused.stderr ).toContain( 'not https' );
		expect( imports() ).toEqual( {} );

		// With the fixture's network settings the whole path works, from the screen too.
		wp( 'option', 'update', 'ard_e2e_direct', 'on' );
		const fresh = h.wpRun( 'axle-runtime-doctor', 'direct-code' ).stdout.split( '\n' )[ 0 ].trim();
		await page.goto( COMPARE );
		await page.fill( '#ard-fetch-code', fresh );
		await page.getByRole( 'button', { name: 'Fetch the report' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Saved the report' );
		const kept = Object.values( imports() );
		expect( kept ).toHaveLength( 1 );
		expect( kept[ 0 ].source ).toBe( 'direct' );

		// The same code a second time is refused by the other site.
		await page.fill( '#ard-fetch-code', fresh );
		await page.getByRole( 'button', { name: 'Fetch the report' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'refused the code' );
		expect( Object.keys( imports() ) ).toHaveLength( 1 );
	} );

	test( 'the screen makes a one-time code and shows it once', async ( { page } ) => {
		h.runChecks();
		await page.goto( COMPARE );
		await page.getByRole( 'button', { name: 'Make a one-time code' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The code is ready' );
		await expect( page.locator( '#ard-code-made' ) ).toHaveValue( /^ard1\./ );
		await page.reload();
		await expect( page.locator( '#ard-code-made' ) ).toHaveCount( 0 );
	} );

	test( 'direct mode can be filtered off', async ( { request } ) => {
		h.runChecks();
		wpEval( `file_put_contents( WPMU_PLUGIN_DIR . '/ard-direct-off.php', "<?php add_filter( 'axle_runtime_doctor_direct_enabled', '__return_false' );" );` );
		try {
			expect( h.wpRun( 'axle-runtime-doctor', 'direct-code' ).stderr ).toContain( 'Direct mode is turned off' );
			expect( ( await request.get( '/index.php?rest_route=/axle-runtime-doctor/v1/share' ) ).status() ).toBe( 401 );
		} finally {
			wpEval( `@unlink( WPMU_PLUGIN_DIR . '/ard-direct-off.php' );` );
		}
	} );

	test( 'an editor cannot export, import or open the tab, and abilities compare read-only', async ( { browser, rest } ) => {
		try {
			wp( 'user', 'create', 'ard-editor', 'ard-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		} catch ( error ) {
			// Already there.
		}
		const { context, page } = await logInAs( browser, 'ard-editor', 'editor-pass-123' );
		expect( ( await page.goto( COMPARE ) ).status() ).toBe( 403 );
		expect( ( await page.goto( '/wp-admin/admin-post.php?action=axle_runtime_doctor_export' ) ).status() ).toBe( 403 );
		await context.close();

		test.skip( h.wpOlderThan( '6.9' ), 'The Abilities API arrived in WordPress 6.9.' );
		const other = otherSite();
		const base = '/wp-abilities/v1/abilities/axle-runtime-doctor';
		const exported = await rest( 'GET', `${ base }/export-report/run` );
		expect( exported.status ).toBe( 200 );
		expect( exported.body.format ).toBe( 'axle-runtime-doctor-report' );

		h.wpRun( 'axle-runtime-doctor', 'import', '/dev/null' );
		wpEval( `file_put_contents( '/tmp/ard-e2e-ab.json', ${ JSON.stringify( JSON.stringify( other ) ) } );` );
		h.wpRun( 'axle-runtime-doctor', 'import', '/tmp/ard-e2e-ab.json', '--name=Other' );
		const list = await rest( 'GET', `${ base }/list-imported-reports/run` );
		expect( list.body.reports ).toHaveLength( 1 );
		const compared = await rest( 'GET', `${ base }/compare-reports/run?input[import_id]=${ list.body.reports[ 0 ].id }` );
		expect( compared.status ).toBe( 200 );
		expect( compared.body.rows[ 0 ].key ).toBe( 'opcache_coverage' );
		expect( compared.body.rows.every( ( row ) => 'same' !== row.state ) ).toBe( true );
		const missing = await rest( 'GET', `${ base }/compare-reports/run?input[import_id]=nope` );
		expect( missing.status ).toBe( 404 );
		wpEval( `@unlink( '/tmp/ard-e2e-ab.json' );` );
	} );
} );
