/**
 * Run the browser-side buildFilterUrlFromMap() over the shared case list.
 *
 * Driven by tests/test-url-policy.php, which compares this output against
 * jpkcom_postfilter_get_filter_url(). Since 1.5.0 the server redirects every
 * spelling of a selection but one; if the script built a different one, every
 * reload of a filtered page would cost a redirect, and a fragment request
 * would be answered for a URL the address bar does not show.
 *
 * Lifted out of the IIFE in assets/js/post-filter.js by source slicing, like
 * tests/fragment-url.mjs - a copy would be the drift.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const src  = readFileSync( join( here, '..', 'assets', 'js', 'post-filter.js' ), 'utf8' );

const start = src.indexOf( 'function buildFilterUrlFromMap(' );

if ( start === -1 ) {
	console.error( 'buildFilterUrlFromMap() not found in assets/js/post-filter.js' );
	process.exit( 2 );
}

const open = src.indexOf( '{', start );
let depth  = 0;
let end    = -1;

for ( let i = open; i < src.length; i++ ) {
	if ( src[ i ] === '{' ) {
		depth++;
	} else if ( src[ i ] === '}' ) {
		depth--;
		if ( depth === 0 ) {
			end = i + 1;
			break;
		}
	}
}

if ( end === -1 ) {
	console.error( 'could not find the end of buildFilterUrlFromMap()' );
	process.exit( 2 );
}

const moduleSource = [ src.slice( start, end ), 'export default buildFilterUrlFromMap;' ].join( '\n' );

const { default: buildFilterUrlFromMap } = await import(
	'data:text/javascript;base64,' + Buffer.from( moduleSource, 'utf8' ).toString( 'base64' )
);

const config = {
	endpoint:         'filter',
	maxFilterCombos:  3,
	filterGroupOrder: [ 'category', 'post_tag' ],
};

const cases = JSON.parse( readFileSync( join( here, 'filter-url-cases.json' ), 'utf8' ) );

console.log( JSON.stringify( cases.map( ( c ) => buildFilterUrlFromMap( c.active, config, 'https://example.test/blog/' ) ) ) );
