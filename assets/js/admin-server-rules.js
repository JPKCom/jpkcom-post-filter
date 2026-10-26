/**
 * JPKCom Post Filter – Server Rules admin page
 *
 * Handles:
 *  - Tab switching (URL hash first, else the tab of the detected server,
 *    given by data-jpkpf-initial on the tab bar)
 *  - Copy buttons: copy the textContent of the <code> element named in
 *    data-copy-target (the page prints it escaped; textContent is the raw
 *    config text again). navigator.clipboard with an execCommand fallback,
 *    as in shortcode-generator.js.
 *
 * Progressive enhancement: without JS all tab panels are visible and the code
 * can be selected by hand.
 *
 * @package JPKCom_Post_Filter
 * @since   1.6.0
 */

( function () {
    'use strict';

    const i18n = window.jpkpfServerRules || {
        copied: 'Copied.',
        copyFailed: 'Copying failed - select the text and copy it by hand.',
    };

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------

    /**
     * @param {HTMLButtonElement[]} buttons
     * @param {HTMLElement[]}       panels
     * @param {string}              targetId
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
        const nav = document.querySelector( '.jpkpf-sr-tabs' );
        if ( ! nav ) {
            return;
        }

        const buttons = Array.from( nav.querySelectorAll( '.jpkpf-tab-btn' ) );
        const panels  = Array.from( document.querySelectorAll( '.jpkpf-sr-panel' ) );

        if ( ! buttons.length || ! panels.length ) {
            return;
        }

        nav.setAttribute( 'role', 'tablist' );
        buttons.forEach( function ( btn ) {
            btn.setAttribute( 'role', 'tab' );
            btn.setAttribute( 'aria-controls', 'tab-' + btn.dataset.tab );
            btn.setAttribute( 'tabindex', '-1' );
        } );
        panels.forEach( function ( panel ) {
            panel.setAttribute( 'role', 'tabpanel' );
        } );

        const known   = function ( id ) {
            return !! id && buttons.some( function ( btn ) {
                return btn.dataset.tab === id;
            } );
        };
        const hash    = window.location.hash.replace( '#', '' );
        const initial = nav.dataset.jpkpfInitial || '';
        let   start   = buttons[ 0 ].dataset.tab;

        if ( known( hash ) ) {
            start = hash;
        } else if ( known( initial ) ) {
            start = initial;
        }

        activateTab( buttons, panels, start );

        buttons.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                activateTab( buttons, panels, btn.dataset.tab );
                history.replaceState( null, '', '#' + btn.dataset.tab );
            } );
        } );

        nav.addEventListener( 'keydown', function ( e ) {
            const idx = buttons.indexOf( document.activeElement );
            if ( idx === -1 ) {
                return;
            }

            let next = -1;
            if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
                next = ( idx + 1 ) % buttons.length;
            } else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
                next = ( idx - 1 + buttons.length ) % buttons.length;
            } else if ( e.key === 'Home' ) {
                next = 0;
            } else if ( e.key === 'End' ) {
                next = buttons.length - 1;
            }

            if ( next !== -1 ) {
                e.preventDefault();
                buttons[ next ].focus();
                buttons[ next ].click();
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Copy
    // -------------------------------------------------------------------------

    /**
     * @param {HTMLElement|null} el
     * @param {string}           text
     */
    function showFeedback( el, text ) {
        if ( ! el ) {
            return;
        }
        el.textContent = text;
        window.setTimeout( function () {
            el.textContent = '';
        }, 2500 );
    }

    /**
     * Fallback copy using execCommand (older browsers, no secure context).
     *
     * @param {string} text
     * @returns {boolean}
     */
    function legacyCopy( text ) {
        const area = document.createElement( 'textarea' );
        area.value = text;
        area.setAttribute( 'readonly', '' );
        area.style.position = 'fixed';
        area.style.top      = '-1000px';
        document.body.appendChild( area );
        area.select();

        let ok = false;
        try {
            ok = document.execCommand( 'copy' );
        } catch ( e ) {
            ok = false;
        }
        document.body.removeChild( area );
        return ok;
    }

    /**
     * Select the code so it can be copied by hand.
     *
     * @param {HTMLElement} code
     */
    function selectCode( code ) {
        const range = document.createRange();
        range.selectNodeContents( code );
        const sel = window.getSelection();
        if ( sel ) {
            sel.removeAllRanges();
            sel.addRange( range );
        }
    }

    function initCopy() {
        document.querySelectorAll( '.jpkpf-sr-copy' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                const code     = document.getElementById( btn.dataset.copyTarget || '' );
                const feedback = btn.parentElement ? btn.parentElement.querySelector( '.jpkpf-sr-copy-feedback' ) : null;

                if ( ! code ) {
                    return;
                }

                const text = code.textContent || '';
                const done = function ( ok ) {
                    if ( ! ok ) {
                        selectCode( code );
                    }
                    showFeedback( feedback, ok ? i18n.copied : i18n.copyFailed );
                };

                if ( navigator.clipboard && window.isSecureContext ) {
                    navigator.clipboard.writeText( text ).then( function () {
                        done( true );
                    } ).catch( function () {
                        done( legacyCopy( text ) );
                    } );
                } else {
                    done( legacyCopy( text ) );
                }
            } );
        } );
    }

    function init() {
        initTabs();
        initCopy();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} () );
