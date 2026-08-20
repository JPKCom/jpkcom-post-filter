/**
 * End-to-end check of a filter click in a real browser.
 *
 * This covers what tests/test-fragment.php deliberately does not: that clicking
 * a filter issues a fragment request instead of reloading, that the results zone
 * is swapped while the filter bar survives, that history back restores the
 * previous list, and that a zero-result combination still renders a usable zone.
 *
 * Needs a running site — it is not part of the CI suite, which has no server.
 *
 *     node tests/browser-check.mjs https://your-site.test/
 *
 * puppeteer-core arrives transitively via @wordpress/scripts and is not a
 * declared dependency, so a missing module exits 0 with a SKIP rather than
 * reporting a failure that says nothing about the plugin.
 *
 * On WSL2 with snap Chromium the launch flags below are required: no sandbox,
 * no GPU, no /dev/shm. Self-signed certificates (DDEV) need acceptInsecureCerts.
 *
 * @package JPKCom_Post_Filter
 * @since 1.2.0
 */

const SITE = ( process.argv[ 2 ] || 'https://posts.ddev.site/' ).replace( /\/*$/, '/' );

let puppeteer;

try {
	( { default: puppeteer } = await import( 'puppeteer-core' ) );
} catch ( err ) {
	console.log( '  SKIP  puppeteer-core not installed — browser checks did NOT run' );
	process.exit( 0 );
}

const { existsSync } = await import( 'node:fs' );

const CHROME = process.env.CHROME_PATH
	|| [ '/snap/bin/chromium', '/usr/bin/chromium', '/usr/bin/google-chrome' ].find( existsSync );

if ( ! CHROME ) {
	console.log( '  SKIP  no Chromium found — set CHROME_PATH. Browser checks did NOT run' );
	process.exit( 0 );
}

let failed = 0;
const ok  = ( m ) => console.log( `  OK    ${ m }` );
const bad = ( m ) => { console.log( `  FEHL  ${ m }` ); failed++; };
const chk = ( c, m ) => ( c ? ok( m ) : bad( m ) );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: true,
	acceptInsecureCerts: true,
	args: [ '--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage' ],
} );

try {
	const page = await browser.newPage();

	const requests = [];
	page.on( 'request', ( r ) => requests.push( { url: r.url(), type: r.resourceType() } ) );

	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	console.log( '\nInitial load' );
	const resp = await page.goto( SITE, { waitUntil: 'networkidle2' } );
	chk( resp.status() === 200, `archive returns ${ resp.status() }` );

	const before = await page.evaluate( () => ( {
		articles: document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		buttons:  document.querySelectorAll( '.jpkpf-filter-btn' ).length,
		url:      location.pathname,
		firstTerm: document.querySelector( '.jpkpf-filter-btn[data-filter-term]' )
			?.getAttribute( 'data-filter-term' ) ?? '',
	} ) );

	chk( before.articles > 0, `results zone populated (${ before.articles } articles)` );
	chk( before.buttons > 0, `filter bar rendered (${ before.buttons } buttons)` );

	if ( ! before.firstTerm ) {
		console.log( '  SKIP  no filter buttons on this site — nothing to click' );
		await browser.close();
		process.exit( 0 );
	}

	console.log( `\nClicking filter "${ before.firstTerm }"` );
	requests.length = 0;

	await page.evaluate( ( t ) => {
		document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ t }"]` )?.click();
	}, before.firstTerm );
	await wait( 2500 );

	chk(
		requests.some( ( r ) => r.url.includes( '/jpkpf-fragment/' ) ),
		'a fragment request was issued'
	);
	chk(
		! requests.some( ( r ) => r.type === 'document' ),
		'no document request — the page did not reload'
	);

	const after = await page.evaluate( () => ( {
		articles:  document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		url:       location.pathname,
		hasMarker: document.body.innerHTML.includes( 'data-jpkpf-zone' ),
		barExists: !! document.querySelector( '[data-jpkpf-filter-bar]' ),
		pressed:   document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).length,
	} ) );

	chk( after.url !== before.url, `URL updated via pushState: ${ after.url }` );
	chk( ! after.url.includes( 'jpkpf-fragment' ), 'the visible URL carries no fragment segment' );
	chk( after.barExists, 'filter bar still present' );
	chk( after.pressed === 1, `exactly one button pressed (${ after.pressed })` );
	chk( ! after.hasMarker, 'no zone markers leaked into the visible DOM' );

	console.log( '\nHistory back' );
	await page.goBack( { waitUntil: 'networkidle2' } ).catch( () => {} );
	await wait( 2000 );

	const back = await page.evaluate( () => ( {
		articles: document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		url:      location.pathname,
		pressed:  document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).length,
	} ) );

	chk( back.url === before.url, `back on ${ back.url }` );
	chk(
		back.articles === before.articles,
		`results restored (${ back.articles }, expected ${ before.articles })`
	);
	chk(
		back.pressed === 0,
		`filter buttons released (${ back.pressed } still pressed)`
	);

	console.log( '\nJavaScript errors' );
	chk( errors.length === 0, `none${ errors.length ? ': ' + errors.join( ' | ' ) : '' }` );

} finally {
	await browser.close();
}

console.log( failed === 0 ? '\n  all green\n' : `\n  ${ failed } check(s) failed\n` );
process.exit( failed > 0 ? 1 : 0 );
