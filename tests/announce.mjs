/**
 * Run resultsAnnouncement() from assets/js/post-filter.js on minimal fake zones
 * and print what it returns as JSON.
 *
 * Driven by tests/test-a11y-markup.php. The function is lifted out of the IIFE
 * by source slicing, like tests/term-state.mjs - a copy would be the drift.
 * It reads `cfg`, which the IIFE closes over; the slice gets a stand-in.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const src  = readFileSync( join( here, '..', 'assets', 'js', 'post-filter.js' ), 'utf8' );

function slice( name ) {
	const start = src.indexOf( 'function ' + name + '(' );
	if ( start === -1 ) {
		console.error( name + '() not found in assets/js/post-filter.js' );
		process.exit( 2 );
	}
	const open = src.indexOf( '{', start );
	let depth  = 0;
	for ( let i = open; i < src.length; i++ ) {
		if ( src[ i ] === '{' ) {
			depth++;
		} else if ( src[ i ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return src.slice( start, i + 1 );
			}
		}
	}
	console.error( 'could not find the end of ' + name + '()' );
	process.exit( 2 );
}

function load( i18n ) {
	const moduleSource = [
		'const cfg = ' + JSON.stringify( { i18n } ) + ';',
		slice( 'resultsAnnouncement' ),
		'export { resultsAnnouncement };',
	].join( '\n' );
	return import( 'data:text/javascript;base64,' + Buffer.from( moduleSource, 'utf8' ).toString( 'base64' ) );
}

function zone( status, noResults ) {
	return {
		getAttribute: ( n ) => ( n === 'data-jpkpf-status' ? status : null ),
		querySelector: ( sel ) => ( sel === '.jpkpf-no-results' && noResults ? {} : null ),
	};
}

const plain = ( await load( {} ) ).resultsAnnouncement;
const de    = ( await load( { noResults: 'Keine Treffer', filterActive: 'Filter aktiv' } ) ).resultsAnnouncement;

const out = {};
out[ 'with-status' ]       = plain( zone( '12 posts found', false ), zone( null, false ) );
// An empty attribute counts as missing; the old zone is what contains the message.
out[ 'empty-status-zero' ] = plain( zone( '', false ), zone( null, true ) );
out[ 'no-status-empty' ]   = plain( zone( null, true ), zone( null, true ) );
out[ 'no-status-posts' ]   = plain( zone( null, false ), zone( null, false ) );
out[ 'i18n-no-results' ]   = de( zone( null, true ), zone( null, true ) );
out[ 'i18n-filter' ]       = de( zone( null, false ), zone( null, false ) );

console.log( JSON.stringify( out ) );
