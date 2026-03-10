/**
 * JPKCom Post Filter – Shortcode Generator
 *
 * Generates shortcode strings from the admin form inputs.
 * Live preview: output updates automatically as form fields change.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

( function () {
	'use strict';

	// Form field IDs and their default values (used to suppress defaults in output)
	const DEFAULTS = {
		sg_post_type:    'post',
		sg_filter_layout: 'bar',
		sg_list_layout:  'cards',
		sg_limit:        '-1',
		sg_orderby:      'date',
		sg_order:        'DESC',
		sg_groups:       '',
		sg_reset:        true,   // checkbox: true = checked = default
	};

	/**
	 * Read the current value of a form element by ID.
	 *
	 * @param {string} id Element ID.
	 * @returns {string|boolean|null} Value or null if element not found.
	 */
	function getValue( id ) {
		const el = document.getElementById( id );
		if ( ! el ) {
			return null;
		}
		if ( el.type === 'checkbox' ) {
			return el.checked;
		}
		return el.value.trim();
	}

	/**
	 * Build a shortcode attribute string, omitting null/default values.
	 *
	 * @param {string} tag   Shortcode tag.
	 * @param {Object} attrs Key → value map; null values are omitted.
	 * @returns {string}
	 */
	function buildShortcode( tag, attrs ) {
		const parts = [ '[' + tag ];

		Object.entries( attrs ).forEach( function ( [ key, value ] ) {
			if ( value !== null && value !== '' ) {
				parts.push( ' ' + key + '="' + value + '"' );
			}
		} );

		parts.push( ']' );
		return parts.join( '' );
	}

	/**
	 * Generate the three shortcode strings from the current form state.
	 *
	 * @returns {string} All three shortcodes joined by newlines.
	 */
	function generateShortcodes() {
		const postType     = getValue( 'sg_post_type' );
		const filterLayout = getValue( 'sg_filter_layout' );
		const groups       = getValue( 'sg_groups' );
		const reset        = getValue( 'sg_reset' );        // boolean (checkbox)
		const listLayout   = getValue( 'sg_list_layout' );
		const limit        = getValue( 'sg_limit' );
		const orderby      = getValue( 'sg_orderby' );
		const order        = getValue( 'sg_order' );

		const filterSC = buildShortcode( 'jpkcom_postfilter_filter', {
			post_type: postType !== DEFAULTS.sg_post_type ? postType : null,
			layout:    filterLayout !== DEFAULTS.sg_filter_layout ? filterLayout : null,
			groups:    groups       || null,
			reset:     reset === false ? 'false' : null,   // only add when explicitly false
		} );

		const listSC = buildShortcode( 'jpkcom_postfilter_list', {
			post_type: postType !== DEFAULTS.sg_post_type ? postType : null,
			layout:    listLayout !== DEFAULTS.sg_list_layout ? listLayout : null,
			limit:     limit     !== DEFAULTS.sg_limit        ? limit     : null,
			orderby:   orderby   !== DEFAULTS.sg_orderby      ? orderby   : null,
			order:     order     !== DEFAULTS.sg_order        ? order     : null,
		} );

		const paginationSC = buildShortcode( 'jpkcom_postfilter_pagination', {
			post_type: postType !== DEFAULTS.sg_post_type ? postType : null,
		} );

		return [ filterSC, listSC, paginationSC ].join( '\n' );
	}

	/**
	 * Update the output textarea with the current shortcodes.
	 *
	 * @param {HTMLTextAreaElement} outputArea
	 * @param {HTMLElement}         outputRow
	 */
	function updateOutput( outputArea, outputRow ) {
		outputArea.value = generateShortcodes();
		outputRow.style.display = '';
	}

	/**
	 * Show copy success feedback momentarily.
	 *
	 * @param {HTMLElement|null} el
	 */
	function showCopyFeedback( el ) {
		if ( ! el ) {
			return;
		}
		el.style.display = 'inline';
		setTimeout( function () {
			el.style.display = 'none';
		}, 2000 );
	}

	/**
	 * Fallback copy using execCommand (older browsers).
	 *
	 * @param {HTMLTextAreaElement} textarea
	 * @param {HTMLElement|null}    feedbackEl
	 */
	function legacyCopy( textarea, feedbackEl ) {
		textarea.select();
		try {
			document.execCommand( 'copy' );
			showCopyFeedback( feedbackEl );
		} catch ( e ) {
			// Silent failure
		}
	}

	/**
	 * Initialize the shortcode generator UI.
	 */
	function init() {
		const generateBtn  = document.getElementById( 'sg_generate' );
		const outputRow    = document.getElementById( 'sg_output_row' );
		const outputArea   = document.getElementById( 'sg_output' );
		const copyBtn      = document.getElementById( 'sg_copy' );
		const copyFeedback = document.getElementById( 'sg_copy_feedback' );

		if ( ! generateBtn || ! outputRow || ! outputArea ) {
			return;
		}

		// Generate on button click
		generateBtn.addEventListener( 'click', function () {
			updateOutput( outputArea, outputRow );
			outputArea.focus();
		} );

		// Live preview: update on any form field change
		const liveFields = [
			'sg_post_type', 'sg_filter_layout', 'sg_list_layout',
			'sg_limit', 'sg_orderby', 'sg_order', 'sg_groups', 'sg_reset',
		];

		liveFields.forEach( function ( id ) {
			const el = document.getElementById( id );
			if ( el ) {
				const event = ( el.type === 'checkbox' || el.tagName === 'SELECT' ) ? 'change' : 'input';
				el.addEventListener( event, function () {
					// Only auto-update if output is already visible
					if ( outputRow.style.display !== 'none' ) {
						updateOutput( outputArea, outputRow );
					}
				} );
			}
		} );

		// Copy to clipboard
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				outputArea.select();

				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( outputArea.value ).then( function () {
						showCopyFeedback( copyFeedback );
					} ).catch( function () {
						legacyCopy( outputArea, copyFeedback );
					} );
				} else {
					legacyCopy( outputArea, copyFeedback );
				}
			} );
		}
	}

	// Init on DOM ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} () );
