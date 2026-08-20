/**
 * JPKCom Post Filter – Frontend JavaScript
 *
 * Handles:
 * - AJAX filter requests with DOM-swap
 * - URL updates via history.pushState (no full page reload)
 * - No-JS fallback: filter links work as normal anchor navigation
 * - Accessibility: aria-live region, aria-pressed, focus management
 * - prefers-reduced-motion: disables animations
 *
 * Two integration modes:
 *   Auto-inject:  [data-jpkpf-wrapper] wraps both filter bar and results.
 *   Shortcode:    [data-jpkpf-filter-bar][data-jpkpf-post-type] and
 *                 [data-jpkpf-results][data-jpkpf-post-type] are paired by JS.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

/* global jpkcomPostFilter */

( function () {
	'use strict';

	// Guard: only run when data is available (i.e., plugin is active on page)
	if ( typeof window.jpkcomPostFilter === 'undefined' ) {
		return;
	}

	const cfg = window.jpkcomPostFilter;

	/**
	 * Initialize all filter UIs on the page.
	 *
	 * Auto-inject mode: find [data-jpkpf-wrapper] elements.
	 * Shortcode mode:   pair [data-jpkpf-filter-bar][data-jpkpf-post-type]
	 *                   with [data-jpkpf-results][data-jpkpf-post-type].
	 */
	function init() {
		// Auto-inject: wrapper contains both filter bar and results
		document.querySelectorAll( '[data-jpkpf-wrapper]' ).forEach( initWrapper );

		// Shortcode / Block: standalone filter bar paired with results by post type
		document.querySelectorAll( '[data-jpkpf-filter-bar][data-jpkpf-post-type]' ).forEach( function ( filterBar ) {
			// Skip filter bars already inside a wrapper (handled above)
			if ( filterBar.closest( '[data-jpkpf-wrapper]' ) ) {
				return;
			}

			const postType = filterBar.getAttribute( 'data-jpkpf-post-type' );
			const baseUrl  = filterBar.getAttribute( 'data-jpkpf-base-url' ) || '';
			const results  = document.querySelector(
				'[data-jpkpf-results][data-jpkpf-post-type="' + CSS.escape( postType ) + '"]'
			);

			if ( results ) {
				initShortcodeFilter( filterBar, results, baseUrl );
			}
		} );
	}

	/**
	 * Initialize a single auto-inject filter wrapper.
	 *
	 * @param {HTMLElement} wrapper
	 */
	function initWrapper( wrapper ) {
		const filterBar  = wrapper.querySelector( '[data-jpkpf-filter-bar]' );
		const resultZone = wrapper.querySelector( '[data-jpkpf-results]' );
		const liveRegion = wrapper.querySelector( '[data-jpkpf-live-region]' );
		const baseUrl    = wrapper.getAttribute( 'data-jpkpf-base-url' ) || '';

		if ( ! filterBar || ! resultZone ) {
			return;
		}

		initFilterUI( filterBar, resultZone, liveRegion, baseUrl );
	}

	/**
	 * Initialize a shortcode-mode filter bar/results pair.
	 *
	 * @param {HTMLElement} filterBar
	 * @param {HTMLElement} resultZone
	 * @param {string}      baseUrl
	 */
	function initShortcodeFilter( filterBar, resultZone, baseUrl ) {
		const liveRegion = filterBar.querySelector( '[data-jpkpf-live-region]' );
		initFilterUI( filterBar, resultZone, liveRegion, baseUrl );
	}

	/**
	 * Wire up filter buttons, reset, dropdowns, and popstate for a
	 * filter bar / results zone pair.
	 *
	 * @param {HTMLElement}      filterBar
	 * @param {HTMLElement}      resultZone
	 * @param {HTMLElement|null} liveRegion
	 * @param {string}           baseUrl
	 */
	function initFilterUI( filterBar, resultZone, liveRegion, baseUrl ) {
		// Enhance filter buttons with aria-pressed and click handlers
		const filterBtns = filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' );
		filterBtns.forEach( function ( btn ) {
			// Sync aria-pressed with visual active state
			const isActive = btn.classList.contains( 'is-active' );
			btn.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( cfg.plusMinusMode ) {
					handlePlusMinusClick( e, btn, filterBar, resultZone, liveRegion, baseUrl );
				} else {
					toggleFilter( btn, filterBar, resultZone, liveRegion, baseUrl );
				}
			} );
		} );

		// Reset button
		const resetBtn = filterBar.querySelector( '.jpkpf-filter-reset' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				resetFilters( filterBar, resultZone, liveRegion, baseUrl );
			} );
		}

		// Dropdown: toggle open/close
		const dropdownTriggers = filterBar.querySelectorAll( '.jpkpf-filter-dropdown-trigger' );
		dropdownTriggers.forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function () {
				const expanded = trigger.getAttribute( 'aria-expanded' ) === 'true';
				trigger.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				const panel = document.getElementById( trigger.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.classList.toggle( 'is-open', ! expanded );
				}
			} );
		} );

		// Close dropdowns on outside click
		document.addEventListener( 'click', function ( e ) {
			dropdownTriggers.forEach( function ( trigger ) {
				const panel = document.getElementById( trigger.getAttribute( 'aria-controls' ) );
				if ( panel && ! trigger.contains( e.target ) && ! panel.contains( e.target ) ) {
					trigger.setAttribute( 'aria-expanded', 'false' );
					panel.classList.remove( 'is-open' );
				}
			} );
		} );

		// Handle browser back/forward navigation.
		//
		// The history entry created by the *initial* page load carries no state
		// at all, so a plain `if ( e.state && e.state.jpkpf )` did nothing when
		// the user went back to it: the address bar returned to the unfiltered
		// archive while the results zone kept showing the filtered list, and the
		// filter buttons stayed pressed. Falling back to location.href re-fetches
		// whatever URL the entry actually points at, which is correct for both
		// the initial entry and any entry pushed by another script.
		window.addEventListener( 'popstate', function ( e ) {
			const url = ( e.state && e.state.jpkpf ) ? e.state.url : location.href;

			syncButtonsToUrl( filterBar, baseUrl );
			fetchResults( url, resultZone, liveRegion, false );
		} );

		// Feature: +/- mode — inject icons after all listeners are set
		if ( cfg.plusMinusMode ) {
			initPlusMinus( filterBar );
		}

		// Feature: show-more threshold
		if ( cfg.showMore && cfg.showMore.enabled ) {
			initShowMore( filterBar );
		}

		// Apply initial group limits (e.g. when filters are pre-selected server-side)
		enforceLimits( filterBar );
	}

	/**
	 * Toggle a filter button's active state and fetch new results.
	 *
	 * @param {HTMLElement}      btn
	 * @param {HTMLElement}      filterBar
	 * @param {HTMLElement}      resultZone
	 * @param {HTMLElement|null} liveRegion
	 * @param {string}           baseUrl
	 */
	function toggleFilter( btn, filterBar, resultZone, liveRegion, baseUrl ) {
		if ( btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

		const isActive = btn.getAttribute( 'aria-pressed' ) === 'true';

		btn.setAttribute( 'aria-pressed', isActive ? 'false' : 'true' );
		btn.classList.toggle( 'is-active', ! isActive );

		enforceLimits( filterBar );
		updateResetButton( filterBar );
		updateDropdownTriggers( filterBar );

		const url = buildFilterUrl( filterBar, baseUrl );
		fetchResults( url, resultZone, liveRegion, true );
	}

	/**
	 * Set the filter buttons to match the current address-bar URL.
	 *
	 * Used on back/forward: the results zone is re-fetched from the URL, and the
	 * buttons have to follow, or the bar claims a selection the list no longer
	 * shows. Reads the positional filter path the same way the PHP side does —
	 * segments in filter-group order, '_' meaning "no selection in this group".
	 *
	 * @param {HTMLElement} filterBar
	 * @param {string}      baseUrl
	 */
	function syncButtonsToUrl( filterBar, baseUrl ) {
		const endpoint = cfg.endpoint || 'filter';
		const groups   = ( cfg.filterGroupOrder && cfg.filterGroupOrder.length ) ? cfg.filterGroupOrder : [];

		let path = location.pathname;
		try {
			path = location.pathname.replace( new URL( baseUrl, location.origin ).pathname, '/' );
		} catch ( err ) {
			// baseUrl unparseable — fall back to the raw pathname.
		}

		const parts = path.split( '/' ).filter( Boolean );
		const active = {};

		if ( parts[ 0 ] === endpoint ) {
			parts.slice( 1 ).forEach( function ( segment, index ) {
				if ( segment === '_' || ! groups[ index ] ) { return; }
				active[ groups[ index ] ] = segment.split( '+' );
			} );
		}

		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			const tax  = btn.getAttribute( 'data-filter-taxonomy' );
			const term = btn.getAttribute( 'data-filter-term' );
			const on   = !! ( active[ tax ] && active[ tax ].indexOf( term ) !== -1 );

			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			btn.classList.toggle( 'is-active', on );
		} );

		enforceLimits( filterBar );
		updateResetButton( filterBar );
		updateDropdownTriggers( filterBar );
	}

	/**
	 * Reset all active filters and fetch unfiltered results.
	 *
	 * @param {HTMLElement}      filterBar
	 * @param {HTMLElement}      resultZone
	 * @param {HTMLElement|null} liveRegion
	 * @param {string}           baseUrl
	 */
	function resetFilters( filterBar, resultZone, liveRegion, baseUrl ) {
		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', 'false' );
			btn.classList.remove( 'is-active' );
		} );

		enforceLimits( filterBar );
		updateResetButton( filterBar );
		updateDropdownTriggers( filterBar );

		fetchResults( baseUrl, resultZone, liveRegion, true );
	}

	/**
	 * Sync dropdown trigger active state and count badge with current selection.
	 *
	 * Called after every filter toggle and reset so the trigger button reflects
	 * how many items are selected inside its panel without a page reload.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function updateDropdownTriggers( filterBar ) {
		filterBar.querySelectorAll( '.jpkpf-filter-dropdown-group' ).forEach( function ( group ) {
			var trigger = group.querySelector( '.jpkpf-filter-dropdown-trigger' );
			if ( ! trigger ) {
				return;
			}

			var activeCount = group.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).length;
			trigger.classList.toggle( 'is-active', activeCount > 0 );

			var badge = trigger.querySelector( '.jpkpf-filter-count' );

			if ( activeCount > 0 ) {
				if ( ! badge ) {
					badge = document.createElement( 'span' );
					badge.className = 'jpkpf-filter-count';
					trigger.appendChild( badge );
				}
				badge.textContent = String( activeCount );
			} else if ( badge ) {
				badge.remove();
			}
		} );
	}

	/**
	 * Show or hide the reset button based on whether any filter is active.
	 *
	 * The button is always present in the DOM (rendered with the `hidden`
	 * attribute when no filters are active on initial load). This function
	 * keeps its visibility in sync after JS-driven filter toggles.
	 * When data-jpkpf-reset-mode="always" the button is never hidden by JS.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function updateResetButton( filterBar ) {
		const resetBtn = filterBar.querySelector( '.jpkpf-filter-reset' );
		if ( ! resetBtn ) {
			return;
		}
		if ( resetBtn.getAttribute( 'data-jpkpf-reset-mode' ) === 'always' ) {
			return;
		}
		const hasActive = !! filterBar.querySelector( '.jpkpf-filter-btn[aria-pressed="true"]' );
		resetBtn.hidden = ! hasActive;
	}

	/**
	 * Build the filter URL from currently active filter buttons in a filter bar.
	 *
	 * @param {HTMLElement} filterBar
	 * @param {string}      baseUrl
	 * @returns {string}
	 */
	function buildFilterUrl( filterBar, baseUrl ) {
		const endpoint = cfg.endpoint || 'filter';

		// Collect active filters grouped by taxonomy
		const active = {};
		filterBar.querySelectorAll( '.jpkpf-filter-btn[aria-pressed="true"]' ).forEach( function ( btn ) {
			const taxonomy = btn.getAttribute( 'data-filter-taxonomy' );
			const term     = btn.getAttribute( 'data-filter-term' );
			if ( taxonomy && term ) {
				if ( ! active[ taxonomy ] ) {
					active[ taxonomy ] = [];
				}
				active[ taxonomy ].push( term );
			}
		} );

		if ( Object.keys( active ).length === 0 ) {
			return ensureTrailingSlash( baseUrl );
		}

		// Enforce max_filter_combos: limit number of active taxonomy groups
		const maxCombos = parseInt( String( cfg.maxFilterCombos || 0 ), 10 );
		if ( maxCombos > 0 && Object.keys( active ).length > maxCombos ) {
			const order = ( cfg.filterGroupOrder && cfg.filterGroupOrder.length )
				? cfg.filterGroupOrder
				: Object.keys( active );
			let kept = 0;
			order.forEach( function ( taxonomy ) {
				if ( active[ taxonomy ] && active[ taxonomy ].length > 0 ) {
					if ( kept < maxCombos ) {
						kept++;
					} else {
						delete active[ taxonomy ];
					}
				}
			} );
		}

		// Build segments positionally, matching the configured group order.
		// Empty positions get a '_' placeholder (same logic as PHP's get_filter_url).
		const groupOrder = ( cfg.filterGroupOrder && cfg.filterGroupOrder.length )
			? cfg.filterGroupOrder
			: Object.keys( active );

		const segments = [];
		let lastNonEmpty = -1;

		groupOrder.forEach( function ( taxonomy, index ) {
			if ( active[ taxonomy ] && active[ taxonomy ].length > 0 ) {
				segments.push( active[ taxonomy ].join( '+' ) );
				lastNonEmpty = index;
			} else {
				segments.push( '_' );
			}
		} );

		// Trim trailing '_' placeholders (same as PHP)
		const trimmed = segments.slice( 0, lastNonEmpty + 1 );

		if ( trimmed.length === 0 ) {
			return ensureTrailingSlash( baseUrl );
		}

		return ensureTrailingSlash( baseUrl ) + endpoint + '/' + trimmed.join( '/' ) + '/';
	}

	/**
	 * Fetch filtered results via AJAX and update the DOM.
	 *
	 * @param {string}           url
	 * @param {HTMLElement}      resultZone
	 * @param {HTMLElement|null} liveRegion
	 * @param {boolean}          pushState Whether to update browser history.
	 */
	function fetchResults( url, resultZone, liveRegion, pushState ) {
		// Show loading state
		resultZone.classList.add( 'jpkpf-loading' );
		resultZone.setAttribute( 'aria-busy', 'true' );

		if ( liveRegion ) {
			liveRegion.textContent = cfg.i18n.loading || 'Loading…';
		}

		const requestUrl = fragmentUrl( url );

		fetch( requestUrl, {
			method: 'GET',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'X-WP-Nonce':       cfg.nonce || '',
			},
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok: ' + response.status );
				}
				return response.text();
			} )
			.then( function ( html ) {
				// Parse the returned HTML and extract the results zone
				const parser  = new DOMParser();
				const doc     = parser.parseFromString( html, 'text/html' );
				const newZone = doc.querySelector( '[data-jpkpf-results]' );

				if ( ! newZone ) {
					// A response without a results zone never means "no posts".
					// Every list template renders the zone first and puts its
					// empty-state message inside it, and in auto-inject mode
					// render_zero_results_fallback() guarantees one — so a zero
					// result arrives here as a zone containing that message.
					//
					// This branch means the fragment did not survive the way
					// out. An HTML minifier that strips comments used to be
					// enough (Autoptimize, stock settings: 18 300 B became 0 B);
					// the server names what it found in
					// [data-jpkpf-fragment-error]. Until 1.4.4 this wrote the
					// no-results message into the zone, which turned a broken
					// response into a plausible wrong answer: the filter looked
					// like it worked and reported no matches on every click.
					//
					// Reloading is slower than a swap and always correct — it is
					// the server-rendered answer for the very same URL.
					if ( cfg.debug ) {
						const reason = doc.querySelector( '[data-jpkpf-fragment-error]' );
						// eslint-disable-next-line no-console
						console.warn(
							'[jpkcom-post-filter] No results zone in fragment response' +
							( reason ? ' (' + reason.getAttribute( 'data-jpkpf-fragment-error' ) + ')' : '' ) +
							' — falling back to a full page load:',
							requestUrl
						);
					}

					window.location.href = url;
					return;
				}

				// Swap content
				resultZone.innerHTML = newZone.innerHTML;

				// Swap standalone pagination blocks (outside [data-jpkpf-results])
				swapPagination( doc, resultZone );

				// Update history
				if ( pushState ) {
					history.pushState( { jpkpf: true, url }, document.title, url );
				}

				// Announce to screen readers
				if ( liveRegion ) {
					const count = resultZone.querySelectorAll( '[data-jpkpf-post]' ).length;
					liveRegion.textContent = count > 0
						? count + ' ' + ( cfg.i18n.filterActive || 'results' )
						: ( cfg.i18n.noResults || 'No posts found.' );
				}

				// Move focus to results for screen reader users
				resultZone.setAttribute( 'tabindex', '-1' );
				resultZone.focus( { preventScroll: true } );
			} )
			.catch( function ( error ) {
				if ( cfg.debug ) {
					// eslint-disable-next-line no-console
					console.error( '[jpkcom-post-filter] Fetch error:', error );
				}

				// Graceful degradation: reload page at filter URL
				window.location.href = url;
			} )
			.finally( function () {
				resultZone.classList.remove( 'jpkpf-loading' );
				resultZone.setAttribute( 'aria-busy', 'false' );
			} );
	}

	// -------------------------------------------------------------------------
	// Feature: Plus/Minus Mode
	// -------------------------------------------------------------------------

	/**
	 * Inject +/– icon spans into every filter button and mark the nav.
	 * Called once per filter bar when plusMinusMode is active.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function initPlusMinus( filterBar ) {
		filterBar.classList.add( 'jpkpf-pm-mode' );
		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			const icon = document.createElement( 'span' );
			icon.className = 'jpkpf-pm-icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.textContent = btn.getAttribute( 'aria-pressed' ) === 'true' ? '\u2212' : '+';
			btn.appendChild( icon );
		} );
	}

	/**
	 * Refresh +/– icons to match current aria-pressed states.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function updatePlusMinusIcons( filterBar ) {
		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			const icon = btn.querySelector( '.jpkpf-pm-icon' );
			if ( icon ) {
				icon.textContent = btn.getAttribute( 'aria-pressed' ) === 'true' ? '\u2212' : '+';
			}
		} );
	}

	/**
	 * Click handler used in Plus/Minus Mode.
	 *
	 * Rules:
	 *   – Active button (label OR icon)  → remove this filter.
	 *   – Inactive button's + icon       → additive: keep others, add this.
	 *   – Inactive button's label, no active filters → activate (same as additive).
	 *   – Inactive button's label, other filters active → exclusive: reset all, activate only this.
	 *
	 * @param {MouseEvent}       e
	 * @param {HTMLElement}      btn
	 * @param {HTMLElement}      filterBar
	 * @param {HTMLElement}      resultZone
	 * @param {HTMLElement|null} liveRegion
	 * @param {string}           baseUrl
	 */
	function handlePlusMinusClick( e, btn, filterBar, resultZone, liveRegion, baseUrl ) {
		const isActive = btn.getAttribute( 'aria-pressed' ) === 'true';
		const onIcon   = !! e.target.closest( '.jpkpf-pm-icon' );

		// Group limit guard: disabled buttons may not be activated
		if ( btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

		if ( isActive ) {
			// Deactivate (label click or − icon click)
			btn.setAttribute( 'aria-pressed', 'false' );
			btn.classList.remove( 'is-active' );
		} else if ( onIcon ) {
			// + icon → additive
			btn.setAttribute( 'aria-pressed', 'true' );
			btn.classList.add( 'is-active' );
		} else {
			// Label click on inactive button
			const hasActive = !! filterBar.querySelector( '.jpkpf-filter-btn[aria-pressed="true"]' );
			if ( hasActive ) {
				// Exclusive: clear all others first
				filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
					b.classList.remove( 'is-active' );
				} );
			}
			btn.setAttribute( 'aria-pressed', 'true' );
			btn.classList.add( 'is-active' );
		}

		enforceLimits( filterBar );
		updatePlusMinusIcons( filterBar );
		updateResetButton( filterBar );
		updateDropdownTriggers( filterBar );

		const url = buildFilterUrl( filterBar, baseUrl );
		fetchResults( url, resultZone, liveRegion, true );
	}


	// -------------------------------------------------------------------------
	// Feature: Max. Filters per Group + Max. Filter Combinations
	// -------------------------------------------------------------------------

	/**
	 * Run both limit checks in the correct order.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function enforceLimits( filterBar ) {
		enforceGroupLimits( filterBar );
		enforceComboLimit( filterBar );
	}

	/**
	 * Disable non-selected buttons in any taxonomy group that has reached the
	 * configured selection limit. Re-enables them when selections drop below
	 * the limit. Has no effect when cfg.maxFiltersPerGroup is 0 (unlimited).
	 *
	 * Uses aria-disabled so the state is communicated to assistive technologies.
	 * pointer-events:none in CSS prevents mouse clicks; the JS guards in
	 * toggleFilter / handlePlusMinusClick prevent keyboard activation.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function enforceGroupLimits( filterBar ) {
		var max = parseInt( String( cfg.maxFiltersPerGroup || 0 ), 10 );
		if ( ! max || max <= 0 ) { return; }

		// Collect buttons by taxonomy
		var byTaxonomy = {};
		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			var tax = btn.getAttribute( 'data-filter-taxonomy' );
			if ( ! tax ) { return; }
			if ( ! byTaxonomy[ tax ] ) { byTaxonomy[ tax ] = []; }
			byTaxonomy[ tax ].push( btn );
		} );

		Object.keys( byTaxonomy ).forEach( function ( tax ) {
			var btns         = byTaxonomy[ tax ];
			var activeCount  = btns.filter( function ( b ) {
				return b.getAttribute( 'aria-pressed' ) === 'true';
			} ).length;
			var limitReached = activeCount >= max;

			btns.forEach( function ( btn ) {
				var isActive      = btn.getAttribute( 'aria-pressed' ) === 'true';
				var shouldDisable = limitReached && ! isActive;
				if ( shouldDisable ) {
					btn.setAttribute( 'aria-disabled', 'true' );
				} else {
					btn.removeAttribute( 'aria-disabled' );
				}
			} );
		} );
	}


	/**
	 * Disable all buttons in taxonomy groups that have no active selection once
	 * the configured max-combos limit is reached. Re-enables them when the
	 * number of active groups drops below the limit.
	 *
	 * Runs after enforceGroupLimits so per-group limits are never overridden.
	 * Only acts on groups with zero active selections (groups with at least one
	 * active button are left untouched — their per-item limits are already
	 * handled by enforceGroupLimits).
	 *
	 * Has no effect when cfg.maxFilterCombos is 0 (unlimited).
	 *
	 * @param {HTMLElement} filterBar
	 */
	function enforceComboLimit( filterBar ) {
		var maxCombos = parseInt( String( cfg.maxFilterCombos || 0 ), 10 );
		if ( ! maxCombos || maxCombos <= 0 ) { return; }

		// Collect buttons by taxonomy
		var byTaxonomy = {};
		filterBar.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ).forEach( function ( btn ) {
			var tax = btn.getAttribute( 'data-filter-taxonomy' );
			if ( ! tax ) { return; }
			if ( ! byTaxonomy[ tax ] ) { byTaxonomy[ tax ] = []; }
			byTaxonomy[ tax ].push( btn );
		} );

		// Count taxonomy groups that already have at least one active selection
		var activeGroupCount = Object.keys( byTaxonomy ).filter( function ( tax ) {
			return byTaxonomy[ tax ].some( function ( btn ) {
				return btn.getAttribute( 'aria-pressed' ) === 'true';
			} );
		} ).length;

		var limitReached = activeGroupCount >= maxCombos;

		Object.keys( byTaxonomy ).forEach( function ( tax ) {
			var btns      = byTaxonomy[ tax ];
			var hasActive = btns.some( function ( btn ) {
				return btn.getAttribute( 'aria-pressed' ) === 'true';
			} );

			// Only act on groups with no active selection
			if ( ! hasActive ) {
				btns.forEach( function ( btn ) {
					if ( limitReached ) {
						btn.setAttribute( 'aria-disabled', 'true' );
					} else {
						btn.removeAttribute( 'aria-disabled' );
					}
				} );
			}
		} );
	}


	// -------------------------------------------------------------------------
	// Feature: Show More (…) Button
	// -------------------------------------------------------------------------

	/**
	 * Apply show-more threshold to each filter group.
	 * Hides buttons beyond the threshold and inserts a toggle "…" button.
	 * Not applied to Dropdown layout.
	 *
	 * @param {HTMLElement} filterBar
	 */
	function initShowMore( filterBar ) {
		// Dropdown layout: skip entirely
		if ( filterBar.classList.contains( 'jpkpf-filter-dropdown' ) ) {
			return;
		}

		const threshold  = parseInt( String( cfg.showMore.threshold ), 10 ) || 10;
		const labelMore  = ( cfg.i18n && cfg.i18n.showMore ) ? cfg.i18n.showMore : '\u2026';
		const labelLess  = ( cfg.i18n && cfg.i18n.showLess ) ? cfg.i18n.showLess : '\u00ab';

		filterBar.querySelectorAll( '.jpkpf-filter-group, .jpkpf-filter-columns-group' ).forEach( function ( group ) {
			const btns = Array.from( group.querySelectorAll( '.jpkpf-filter-btn[data-filter-term]' ) );
			if ( btns.length <= threshold ) {
				return;
			}

			// Hide overflow buttons
			btns.slice( threshold ).forEach( function ( btn ) {
				btn.classList.add( 'jpkpf-overflow-item' );
				btn.hidden = true;
			} );

			// Create toggle button and insert after the last visible button
			const moreBtn = document.createElement( 'button' );
			moreBtn.type = 'button';
			moreBtn.className = 'jpkpf-filter-btn jpkpf-show-more-btn';
			moreBtn.setAttribute( 'aria-expanded', 'false' );
			moreBtn.textContent = labelMore;
			btns[ threshold - 1 ].insertAdjacentElement( 'afterend', moreBtn );

			moreBtn.addEventListener( 'click', function () {
				const expanded     = moreBtn.getAttribute( 'aria-expanded' ) === 'true';
				const overflowBtns = group.querySelectorAll( '.jpkpf-overflow-item' );
				overflowBtns.forEach( function ( item ) {
					item.hidden = expanded;
				} );
				moreBtn.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				moreBtn.textContent = expanded ? labelMore : labelLess;
			} );
		} );
	}


	/**
	 * Swap standalone pagination elements (Block / Shortcode mode).
	 *
	 * In auto-inject mode pagination lives inside [data-jpkpf-results] and is
	 * swapped together with it. In block/shortcode mode however, the pagination
	 * is a separate element outside the results zone. This function finds all
	 * [data-jpkpf-pagination] elements in both the current DOM and the AJAX
	 * response and replaces them, so pagination links stay filter-aware.
	 *
	 * @param {Document}    doc        Parsed AJAX response document.
	 * @param {HTMLElement} resultZone Current results zone (used to skip
	 *                                 pagination already inside results).
	 */
	function swapPagination( doc, resultZone ) {
		// Determine post type from the results zone to match the right pagination
		var postType = resultZone.getAttribute( 'data-jpkpf-post-type' ) || '';

		// Build selector: match by post type when available, otherwise any pagination
		var selector = postType
			? '[data-jpkpf-pagination][data-jpkpf-post-type="' + CSS.escape( postType ) + '"]'
			: '[data-jpkpf-pagination]';

		// Find pagination elements in the current page that are NOT inside the results zone
		var currentPaginations = document.querySelectorAll( selector );
		var newPaginations     = doc.querySelectorAll( selector );

		currentPaginations.forEach( function ( currentNav ) {
			// Skip pagination inside the results zone (already swapped)
			if ( resultZone.contains( currentNav ) ) {
				return;
			}

			if ( newPaginations.length > 0 ) {
				// Replace with the first matching pagination from the response
				currentNav.outerHTML = newPaginations[ 0 ].outerHTML;
			} else {
				// No pagination in response (≤ 1 page) — hide but keep as
				// placeholder so it can be restored when filters change back
				currentNav.innerHTML = '';
				currentNav.hidden    = true;
			}
		} );

		// If no current pagination exists outside the results zone and we are NOT
		// in auto-inject mode (wrapper), insert a new pagination after the results.
		// In auto-inject mode pagination lives inside [data-jpkpf-results] and is
		// already swapped with the results content — no extra insertion needed.
		var isWrapperMode = !! resultZone.closest( '[data-jpkpf-wrapper]' );

		if ( ! isWrapperMode ) {
			if ( currentPaginations.length === 0 || allInsideResults( currentPaginations, resultZone ) ) {
				if ( newPaginations.length > 0 ) {
					resultZone.insertAdjacentHTML( 'afterend', newPaginations[ 0 ].outerHTML );
				}
			}
		}
	}

	/**
	 * Check whether all elements in a NodeList are inside a container.
	 *
	 * @param {NodeList}    nodes
	 * @param {HTMLElement} container
	 * @returns {boolean}
	 */
	function allInsideResults( nodes, container ) {
		for ( var i = 0; i < nodes.length; i++ ) {
			if ( ! container.contains( nodes[ i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensure a URL ends with a trailing slash.
	 *
	 * @param {string} url
	 * @returns {string}
	 */
	function ensureTrailingSlash( url ) {
		return url.endsWith( '/' ) ? url : url + '/';
	}

	/**
	 * Add a query parameter to a URL.
	 *
	 * @param {string} url
	 * @param {string} key
	 * @param {string} value
	 * @returns {string}
	 */
	function addQueryParam( url, key, value ) {
		const separator = url.includes( '?' ) ? '&' : '?';
		return url + separator + encodeURIComponent( key ) + '=' + encodeURIComponent( value );
	}

	/**
	 * Turn a filter URL into its fragment URL.
	 *
	 * The segment goes into the path, not the query string: a full-page cache
	 * keys on the URL and several common setups drop query parameters they do
	 * not recognise. A dropped parameter would collapse this onto the real page
	 * URL and let the cache hand a bare, theme-less fragment to a normal
	 * visitor. A path segment cannot collapse that way.
	 *
	 * Mirrors jpkcom_postfilter_fragment_url() in includes/fragment-response.php.
	 *
	 * @param {string} url Filter URL, possibly with query string and/or hash.
	 * @return {string} URL with the fragment segment appended to the path.
	 */
	function fragmentUrl( url ) {
		const segment = cfg.fragmentSegment || 'jpkpf-fragment';

		let hash = '';
		const hashAt = url.indexOf( '#' );

		if ( hashAt !== -1 ) {
			hash = url.slice( hashAt );
			url  = url.slice( 0, hashAt );
		}

		let query = '';
		const queryAt = url.indexOf( '?' );

		if ( queryAt !== -1 ) {
			query = url.slice( queryAt );
			url   = url.slice( 0, queryAt );
		}

		return url.replace( /\/+$/, '' ) + '/' + segment + '/' + query + hash;
	}

	// Initialize when DOM is ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} () );
