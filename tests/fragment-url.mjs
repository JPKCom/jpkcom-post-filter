/**
 * Run the browser-side fragmentUrl() over the shared case list.
 *
 * Driven by tests/test-fragment.php, which compares this output against the PHP
 * implementation. The two build the same URL in two languages, and nothing in a
 * normal test run would notice them drifting apart — a mismatch means every
 * AJAX request 404s in production while every PHP test still passes.
 *
 * The function is lifted out of the IIFE in assets/js/post-filter.js by source
 * slicing rather than copied here on purpose: a copy would be the drift.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const src  = readFileSync( join( here, '..', 'assets', 'js', 'post-filter.js' ), 'utf8' );

const start = src.indexOf( 'function fragmentUrl(' );

if ( start === -1 ) {
	console.error( 'fragmentUrl() not found in assets/js/post-filter.js' );
	process.exit( 2 );
}

// Walk braces from the function's opening brace to its matching close.
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
	console.error( 'could not find the end of fragmentUrl()' );
	process.exit( 2 );
}

// Load the slice as a real ES module rather than through new Function(): the
// same evaluation, without introducing an eval-shaped primitive into a file
// someone might later copy somewhere it *does* see untrusted input.
// `cfg` is the localised config object the real script closes over.
const moduleSource = [
	'const cfg = { fragmentSegment: "jpkpf-fragment" };',
	src.slice( start, end ),
	'export default fragmentUrl;',
].join( '\n' );

const { default: fragmentUrl } = await import(
	'data:text/javascript;base64,' + Buffer.from( moduleSource, 'utf8' ).toString( 'base64' )
);

const cases = JSON.parse( readFileSync( join( here, 'fragment-url-cases.json' ), 'utf8' ) );

console.log( JSON.stringify( cases.map( ( u ) => fragmentUrl( u ) ) ) );
