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

	// -----------------------------------------------------------------
	// 1.5.0: one URL per selection, buttons without crawlable links, and
	// error answers that do not reload the page.
	// -----------------------------------------------------------------
	const terms = await page.evaluate( () => Array.from(
		document.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' )
	).map( ( b ) => ( { tax: b.getAttribute( 'data-filter-taxonomy' ), term: b.getAttribute( 'data-filter-term' ) } ) ) );

	const firstTax = terms.length ? terms[ 0 ].tax : '';
	const sameTax  = terms.filter( ( t ) => t.tax === firstTax ).map( ( t ) => t.term );

	if ( sameTax.length >= 2 ) {
		console.log( '\nClick order does not decide the URL (1.5.0)' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		requests.length = 0;

		const [ a, b ] = sameTax.slice( 0, 2 ).sort();
		const click    = ( t ) => page.evaluate( ( x ) => document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ x }"]` ).click(), t );

		await click( b );
		await wait( 1500 );
		await click( a );
		await wait( 2000 );

		const url = await page.evaluate( () => location.pathname );
		chk( url.endsWith( `/${ a }+${ b }/` ), `clicked ${ b } then ${ a }, URL is sorted: ${ url }` );
		chk( ! requests.some( ( r ) => r.type === 'document' ), 'still no reload' );

		console.log( '\nServer-rendered selection: buttons where a link would lead into the combinations' );
		const resp2 = await page.goto( SITE + 'filter/' + a + '+' + b + '/', { waitUntil: 'networkidle2' } );
		chk( resp2.status() === 200, `the sorted selection is served (${ resp2.status() })` );

		const markup = await page.evaluate( ( ab ) => {
			const el = ( t ) => document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ t }"]` );
			return {
				activeTag:    el( ab[ 0 ] ).tagName,
				activeHref:   el( ab[ 0 ] ).getAttribute( 'href' ),
				comboLinks:   Array.from( document.querySelectorAll( 'a.jpkpf-filter-btn[href*="+"]' ) ).length,
				robots:       document.querySelector( 'meta[name="robots"]' )?.content ?? '',
				canonicals:   document.querySelectorAll( 'link[rel="canonical"]' ).length,
			};
		}, [ a, b ] );

		chk( markup.comboLinks === 0, `no term links to a combination (${ markup.comboLinks })` );
		chk( markup.activeHref === `${ SITE }filter/${ b }/`, `deselecting ${ a } links to the single term ${ b } (${ markup.activeHref })` );
		chk( /noindex/.test( markup.robots ) && /nofollow/.test( markup.robots ), `two terms are "${ markup.robots }"` );
		chk( markup.canonicals === 0, `no canonical on a noindex page (${ markup.canonicals })` );

		if ( sameTax.length >= 3 ) {
			const c = sameTax.slice().sort()[ 2 ];
			await page.goto( SITE + 'filter/' + [ a, b, c ].sort().join( '+' ) + '/', { waitUntil: 'networkidle2' } );
			const tag = await page.evaluate( ( t ) => document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ t }"]` ).tagName, a );
			chk( tag === 'BUTTON', `with three selected, an active term is a <button> (${ tag })` );

			requests.length = 0;
			await page.focus( `.jpkpf-filter-btn[data-filter-term="${ a }"]` );
			await page.keyboard.press( 'Enter' );
			await wait( 2000 );
			const st = await page.evaluate( ( t ) => ( {
				pressed: document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ t }"]` ).getAttribute( 'aria-pressed' ),
				url: location.pathname,
			} ), a );
			chk( st.pressed === 'false', `Enter on the <button> deselects it (${ st.url })` );
			chk( requests.some( ( r ) => r.url.includes( '/jpkpf-fragment/' ) ) && ! requests.some( ( r ) => r.type === 'document' ), 'via a fragment request, no reload' );
		}

		console.log( '\nA combination the server rejects: no reload, last good state kept and explained' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		// Pretend the bar allows more than the server does: what a cached old
		// page or a changed setting produces in the field.
		await page.evaluate( () => {
			window.jpkcomPostFilter.maxFiltersPerGroup = 0;
			window.jpkcomPostFilter.maxTermsTotal      = 0;
		} );
		requests.length = 0;
		const responses = [];
		const onResp = ( r ) => { if ( r.url().includes( '/jpkpf-fragment/' ) ) { responses.push( r.status() ); } };
		page.on( 'response', onResp );
		for ( const t of sameTax ) {
			await page.evaluate( ( x ) => document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ x }"]` ).click(), t );
			await wait( 900 );
		}
		await wait( 1500 );
		page.off( 'response', onResp );
		const rejectedState = await page.evaluate( () => {
			const path    = location.pathname;
			const pressed = Array.from( document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ) )
				.map( ( b ) => b.getAttribute( 'data-filter-term' ) ).sort();
			return {
				notice: document.querySelector( '.jpkpf-fetch-error' )?.textContent ?? '',
				path,
				pressed,
				// Every pressed button must be in the address bar's segment.
				consistent: pressed.every( ( t ) => path.split( '/' ).some( ( seg ) => seg.split( '+' ).includes( t ) ) )
					&& pressed.length === path.split( '/' ).filter( ( seg ) => seg && seg !== 'filter' && seg !== '_' ).reduce( ( n, seg ) => n + seg.split( '+' ).length, 0 ),
			};
		} );
		if ( sameTax.length > 3 ) {
			chk( responses.includes( 410 ), `the server answered 410 (${ responses.join( ',' ) })` );
			chk( rejectedState.notice !== '', `a visible notice: "${ rejectedState.notice }"` );
			chk( rejectedState.consistent, `buttons match the address bar ${ rejectedState.path } (${ rejectedState.pressed.join( ',' ) })` );
		} else {
			console.log( '  SKIP  fewer than four terms in one group - cannot exceed the default cap' );
		}
		chk( ! requests.some( ( r ) => r.type === 'document' ), 'no reload onto the error page' );

		console.log( '\nAn overloaded server keeps the results and says so' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await page.setRequestInterception( true );
		const on503 = ( req ) => {
			if ( req.url().includes( '/jpkpf-fragment/' ) ) {
				req.respond( { status: 503, contentType: 'text/html', body: 'busy' } );
			} else {
				req.continue();
			}
		};
		page.on( 'request', on503 );
		requests.length = 0;
		const articlesBefore = await page.evaluate( () => document.querySelectorAll( '[data-jpkpf-results] article' ).length );
		await click( a );
		await wait( 1500 );
		const busy = await page.evaluate( () => ( {
			notice:   document.querySelector( '.jpkpf-fetch-error' )?.textContent ?? '',
			articles: document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		} ) );
		page.off( 'request', on503 );
		await page.setRequestInterception( false );
		chk( busy.notice !== '', `a visible notice: "${ busy.notice }"` );
		chk( busy.articles === articlesBefore, `the previous results stay (${ busy.articles })` );
		const busyPressed = await page.evaluate( () => document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).length );
		chk( busyPressed === 0, `the clicked button is released again, matching the unchanged address bar (${ busyPressed } pressed)` );
		chk( ! requests.some( ( r ) => r.type === 'document' ), 'no reload against the overloaded server' );

		console.log( '\nBack/forward whose fetch fails: the popped URL is loaded, nothing contradicts it' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await click( a );
		await wait( 2000 );
		await page.setRequestInterception( true );
		page.on( 'request', on503 );
		// goBack() resolves on the same-document history step; the full load
		// of the popped URL follows once the fragment request has failed.
		const reload = page.waitForRequest( ( r ) => r.resourceType() === 'document', { timeout: 15000 } ).catch( () => null );
		await page.goBack();
		const docReq = await reload;
		await page.waitForNetworkIdle( { idleTime: 800, timeout: 15000 } ).catch( () => null );
		chk( !! docReq, 'the failed back/forward fetch fell back to loading the popped URL' );
		page.off( 'request', on503 );
		await page.setRequestInterception( false );
		const popped = await page.evaluate( () => ( {
			url:      location.pathname,
			pressed:  document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).length,
			articles: document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		} ) );
		chk( popped.url === new URL( SITE ).pathname && popped.pressed === 0 && popped.articles === articlesBefore, `address bar, buttons and list agree after the failed back (${ popped.url }, ${ popped.pressed } pressed, ${ popped.articles } articles)` );

		console.log( '\nFast double back: the request of the step in between does not land' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await click( a );
		await wait( 1500 );
		await click( b );
		await wait( 1500 );
		await click( b );
		await wait( 1500 );
		// History: /, /a/, /a+b/, /a/ - the zone shows /a/. Two backs in quick
		// succession: the first starts a request for /a+b/, the second lands on
		// /a/ again, which the zone already shows.
		await page.evaluate( () => {
			history.back();
			setTimeout( () => history.back(), 60 );
		} );
		await wait( 3000 );
		const dbl = await page.evaluate( () => ( {
			url:     location.pathname,
			pressed: Array.from( document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ) ).map( ( x ) => x.getAttribute( 'data-filter-term' ) ).join( ',' ),
		} ) );
		const expected = await page.evaluate( async ( u ) => {
			const r   = await fetch( u, { headers: { 'X-Requested-With': 'XMLHttpRequest' } } );
			const doc = new DOMParser().parseFromString( await r.text(), 'text/html' );
			return Array.from( doc.querySelectorAll( '[data-jpkpf-results] article' ) ).map( ( x ) => x.id ).join( ',' );
		}, new URL( SITE ).origin + dbl.url.replace( /\/?$/, '/' ) + 'jpkpf-fragment/' ).catch( () => '' );
		const shown = await page.evaluate( () => Array.from( document.querySelectorAll( '[data-jpkpf-results] article' ) ).map( ( x ) => x.id ).join( ',' ) );
		chk( dbl.pressed === dbl.url.split( '/' ).filter( Boolean ).pop().split( '+' ).sort().join( ',' ), `buttons match ${ dbl.url } (${ dbl.pressed })` );
		chk( shown === expected && shown !== '', `the list is the one of ${ dbl.url }` );

		console.log( '\nAn in-page #anchor while a click is loading does not undo the click' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await page.setRequestInterception( true );
		const delay = ( req ) => {
			if ( req.url().includes( '/jpkpf-fragment/' ) ) {
				setTimeout( () => req.continue().catch( () => {} ), 1500 );
			} else {
				req.continue().catch( () => {} );
			}
		};
		page.on( 'request', delay );
		await click( a );
		await wait( 300 );
		await page.evaluate( () => { location.hash = 'jpkpf-anchor-test'; } );
		await wait( 3000 );
		page.off( 'request', delay );
		await page.setRequestInterception( false );
		const anchored = await page.evaluate( () => ( {
			url:     location.pathname,
			pressed: Array.from( document.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ) ).map( ( x ) => x.getAttribute( 'data-filter-term' ) ).join( ',' ),
			live:    document.querySelector( '[data-jpkpf-live-region]' )?.textContent ?? '',
		} ) );
		chk( anchored.url.endsWith( `/${ a }/` ) && anchored.pressed === a, `the click landed (${ anchored.url }, ${ anchored.pressed })` );

		console.log( '\nScreen reader announcement after a successful click' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await click( a );
		await wait( 2500 );
		const announced = await page.evaluate( () => ( {
			live:     document.querySelector( '[data-jpkpf-live-region]' )?.textContent ?? '',
			articles: document.querySelectorAll( '[data-jpkpf-results] article' ).length,
		} ) );
		chk(
			announced.articles > 0 && /^\d+ /.test( announced.live ),
			`${ announced.articles } results are announced with their count, not as "no posts" ("${ announced.live }")`
		);

		console.log( '\nTwo quick clicks: the second wins' );
		await page.goto( SITE, { waitUntil: 'networkidle2' } );
		await page.evaluate( ( x ) => {
			document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ x[ 0 ] }"]` ).click();
			document.querySelector( `.jpkpf-filter-btn[data-filter-term="${ x[ 1 ] }"]` ).click();
		}, [ a, b ] );
		await wait( 2500 );
		const quick = await page.evaluate( () => ( {
			url: location.pathname,
			loading: document.querySelector( '[data-jpkpf-results]' ).classList.contains( 'jpkpf-loading' ),
		} ) );
		chk( quick.url.endsWith( `/${ a }+${ b }/` ), `URL of the last state: ${ quick.url }` );
		chk( ! quick.loading, 'loading state cleared' );
	}

	console.log( '\nJavaScript errors' );
	chk( errors.length === 0, `none${ errors.length ? ': ' + errors.join( ' | ' ) : '' }` );

} finally {
	await browser.close();
}

console.log( failed === 0 ? '\n  all green\n' : `\n  ${ failed } check(s) failed\n` );
process.exit( failed > 0 ? 1 : 0 );
