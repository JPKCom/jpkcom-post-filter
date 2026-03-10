/**
 * JPKCom Post Filter – Layout Admin Page JS
 *
 * Handles:
 *  - Tab switching with URL hash (no page reload)
 *  - Bidirectional colorpicker sync (color swatch ↔ text input)
 *  - Color reset button
 *
 * Progressive enhancement: without JS all tab panels are visible.
 *
 * @package JPKCom_Post_Filter
 * @since   1.0.0
 */

( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Tab navigation
    // -------------------------------------------------------------------------

    /**
     * @param {NodeListOf<HTMLButtonElement>} buttons
     * @param {NodeListOf<HTMLElement>} panels
     * @param {string} targetId
     */
    function activateTab( buttons, panels, targetId ) {
        buttons.forEach( function ( btn ) {
            const isActive = btn.dataset.tab === targetId;
            btn.classList.toggle( 'is-active', isActive );
            btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
            btn.setAttribute( 'tabindex', isActive ? '0' : '-1' );
        } );

        panels.forEach( function ( panel ) {
            panel.classList.toggle( 'is-hidden', panel.id !== 'tab-' + targetId );
        } );
    }

    function initTabs() {
        const nav     = document.querySelector( '.jpkpf-tab-nav' );
        const buttons = document.querySelectorAll( '.jpkpf-tab-btn' );
        const panels  = document.querySelectorAll( '.jpkpf-tab-panel' );

        if ( ! nav || ! buttons.length || ! panels.length ) {
            return;
        }

        // Progressive enhancement: hide all panels initially, JS takes over
        panels.forEach( function ( panel ) {
            panel.classList.add( 'is-hidden' );
        } );

        // Set ARIA roles
        nav.setAttribute( 'role', 'tablist' );
        buttons.forEach( function ( btn ) {
            btn.setAttribute( 'role', 'tab' );
            btn.setAttribute( 'aria-controls', 'tab-' + btn.dataset.tab );
            btn.setAttribute( 'tabindex', '-1' );
        } );
        panels.forEach( function ( panel ) {
            panel.setAttribute( 'role', 'tabpanel' );
        } );

        // Determine initial active tab from URL hash
        const hash       = window.location.hash.replace( '#', '' );
        const tabFromHash = hash && document.getElementById( 'tab-' + hash ) ? hash : null;
        const firstTab   = buttons[ 0 ] ? buttons[ 0 ].dataset.tab : null;
        const initial    = tabFromHash || firstTab;

        if ( initial ) {
            activateTab( buttons, panels, initial );
        }

        // Button click handler
        buttons.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                const id = btn.dataset.tab;
                activateTab( buttons, panels, id );
                history.replaceState( null, '', '#' + id );
            } );
        } );

        // Keyboard navigation (arrow keys within tablist)
        nav.addEventListener( 'keydown', function ( e ) {
            const btns  = Array.from( buttons );
            const idx   = btns.indexOf( document.activeElement );
            if ( idx === -1 ) return;

            let next = -1;
            if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
                next = ( idx + 1 ) % btns.length;
            } else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
                next = ( idx - 1 + btns.length ) % btns.length;
            } else if ( e.key === 'Home' ) {
                next = 0;
            } else if ( e.key === 'End' ) {
                next = btns.length - 1;
            }

            if ( next !== -1 ) {
                e.preventDefault();
                btns[ next ].focus();
                btns[ next ].click();
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Colorpicker sync
    // -------------------------------------------------------------------------

    /** @param {string} val @returns {boolean} */
    function isValidHex( val ) {
        return /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test( val.trim() );
    }

    /** @param {HTMLElement} field */
    function initColorField( field ) {
        const swatch = field.querySelector( '.jpkpf-color-swatch' );
        const text   = field.querySelector( '.jpkpf-color-text' );
        const reset  = field.querySelector( '.jpkpf-color-reset' );

        if ( ! swatch || ! text ) return;

        const defaultVal = text.getAttribute( 'placeholder' ) || '';

        // Sync text → swatch on text input
        text.addEventListener( 'input', function () {
            const val = text.value.trim();
            if ( isValidHex( val ) ) {
                swatch.value    = val;
                swatch.disabled = false;
            } else {
                swatch.disabled = true;
            }
        } );

        // Sync swatch → text on color pick
        swatch.addEventListener( 'input', function () {
            text.value = swatch.value;
        } );

        // Initialize swatch from text value
        const initVal = text.value.trim();
        if ( isValidHex( initVal ) ) {
            swatch.value    = initVal;
            swatch.disabled = false;
        } else {
            swatch.disabled = true;
            // Show placeholder default in swatch if it's a valid hex
            if ( isValidHex( defaultVal ) ) {
                swatch.value = defaultVal;
            }
        }

        // Reset button
        if ( reset ) {
            reset.addEventListener( 'click', function () {
                text.value = '';
                if ( isValidHex( defaultVal ) ) {
                    swatch.value    = defaultVal;
                    swatch.disabled = false;
                } else {
                    swatch.disabled = true;
                }
                text.dispatchEvent( new Event( 'input' ) );
            } );
        }
    }

    function initColorFields() {
        document.querySelectorAll( '.jpkpf-color-field' ).forEach( initColorField );
    }

    // -------------------------------------------------------------------------
    // Tab reset buttons
    // -------------------------------------------------------------------------

    function initTabResetButtons() {
        document.querySelectorAll( '.jpkpf-tab-reset-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var msg = btn.dataset.confirm || '';
                if ( ! window.confirm( msg ) ) {
                    return;
                }

                var tabId = btn.dataset.tab;
                var panel = document.getElementById( 'tab-' + tabId );
                if ( ! panel ) { return; }

                // 1. Click each individual color-reset button → clears text + syncs swatch
                panel.querySelectorAll( '.jpkpf-color-field .jpkpf-color-reset' ).forEach( function ( resetBtn ) {
                    resetBtn.click();
                } );

                // 2. Reset remaining css_vars inputs (text, number, select) outside color fields
                panel.querySelectorAll( '[name*="css_vars"]' ).forEach( function ( el ) {
                    if ( el.closest( '.jpkpf-color-field' ) ) { return; }
                    if ( el.tagName === 'SELECT' ) {
                        var def = el.dataset.default;
                        if ( def !== undefined && def !== '' ) {
                            el.value = def;
                        }
                    } else {
                        el.value = '';
                    }
                } );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', function () {
        initTabs();
        initColorFields();
        initTabResetButtons();
    } );

}() );
