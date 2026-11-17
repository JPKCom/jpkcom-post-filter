/**
 * Run isTermActive() / setTermActive() from assets/js/post-filter.js on a
 * minimal fake element and print the resulting attributes as JSON.
 *
 * Driven by tests/test-a11y-markup.php. The helpers are lifted out of the IIFE
 * by source slicing, like tests/filter-url.mjs - a copy would be the drift.
 * They must mirror jpkcom_postfilter_term_link_attributes() (aria-pressed on
 * buttons only, aria-current="true" on an active link).
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

const moduleSource = [
	slice( 'isTermActive' ),
	slice( 'setTermActive' ),
	'export { isTermActive, setTermActive };',
].join( '\n' );

const { isTermActive, setTermActive } = await import(
	'data:text/javascript;base64,' + Buffer.from( moduleSource, 'utf8' ).toString( 'base64' )
);

function fake( tagName, classes, attrs ) {
	const cl = new Set( classes );
	const at = new Map( Object.entries( attrs ) );
	return {
		tagName,
		classList: {
			contains: ( c ) => cl.has( c ),
			add:      ( c ) => { cl.add( c ); },
			remove:   ( c ) => { cl.delete( c ); },
			toggle:   ( c, force ) => {
				const on = force === undefined ? ! cl.has( c ) : !! force;
				if ( on ) { cl.add( c ); } else { cl.delete( c ); }
				return on;
			},
		},
		getAttribute:    ( n ) => ( at.has( n ) ? at.get( n ) : null ),
		setAttribute:    ( n, v ) => { at.set( n, String( v ) ); },
		removeAttribute: ( n ) => { at.delete( n ); },
		hasAttribute:    ( n ) => at.has( n ),
	};
}

function snap( el ) {
	return {
		active:  el.classList.contains( 'is-active' ),
		pressed: el.getAttribute( 'aria-pressed' ),
		current: el.getAttribute( 'aria-current' ),
	};
}

const out = {};

let el = fake( 'BUTTON', [], {} );
setTermActive( el, true );
out[ 'button-on' ] = snap( el );

el = fake( 'BUTTON', [ 'is-active' ], { 'aria-pressed': 'true', 'aria-current': 'true' } );
setTermActive( el, false );
out[ 'button-off' ] = snap( el );

el = fake( 'A', [], {} );
setTermActive( el, true );
out[ 'link-on' ] = snap( el );

el = fake( 'A', [ 'is-active' ], { 'aria-current': 'true' } );
setTermActive( el, false );
out[ 'link-off' ] = snap( el );

// Theme override printing the 1.7.1 markup: a link with aria-pressed + is-active.
el = fake( 'A', [ 'is-active' ], { 'aria-pressed': 'true' } );
setTermActive( el, isTermActive( el ) );
out[ 'legacy-link' ] = snap( el );

// A legacy link that is not active: aria-pressed="false" must go too.
el = fake( 'A', [], { 'aria-pressed': 'false' } );
setTermActive( el, isTermActive( el ) );
out[ 'legacy-link-off' ] = snap( el );

console.log( JSON.stringify( out ) );
