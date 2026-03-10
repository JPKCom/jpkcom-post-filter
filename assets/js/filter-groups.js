/**
 * Filter Groups Admin – dynamic add / remove
 *
 * Clones a hidden template row when "Add Filter Group" is clicked and
 * appends it into the form list. Handles "Remove Group" via event delegation.
 *
 * @package JPKCom_Post_Filter
 * @since   1.0.0
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {

        var list     = document.getElementById( 'jpkcom-filter-groups-list' );
        var template = document.getElementById( 'jpkcom-filter-group-template' );
        var addBtn   = document.getElementById( 'jpkcom-add-filter-group' );
        var emptyMsg = document.getElementById( 'jpkcom-groups-empty-msg' );

        if ( ! list || ! template || ! addBtn ) {
            return;
        }

        // Start index after the last server-rendered group so indices never collide.
        var nextIndex = parseInt( list.getAttribute( 'data-next-index' ) || '0', 10 );

        /** Show/hide the "no groups" message based on current list content. */
        function updateEmptyMessage() {
            if ( ! emptyMsg ) {
                return;
            }
            var hasGroups = !! list.querySelector( '.jpkcom-filter-group' );
            emptyMsg.style.display = hasGroups ? 'none' : '';
        }

        /** Update the "URL-Filter-Segment N" badge on every group. */
        function updateSegmentNumbers() {
            var label = ( typeof jpkcomFilterGroupsI18n !== 'undefined' && jpkcomFilterGroupsI18n.segmentLabel )
                ? jpkcomFilterGroupsI18n.segmentLabel
                : 'URL-Filter-Segment';
            list.querySelectorAll( '.jpkcom-filter-group' ).forEach( function ( group, i ) {
                var badge = group.querySelector( '.jpkcom-segment-badge' );
                if ( badge ) {
                    badge.textContent = label + ' ' + ( i + 1 );
                }
            } );
        }

        /** Clone the template, stamp in the next index, append to the list. */
        addBtn.addEventListener( 'click', function () {
            var tplGroup = template.querySelector( '.jpkcom-filter-group' );
            if ( ! tplGroup ) {
                return;
            }

            var clone = tplGroup.cloneNode( true );

            // Replace the __INDEX__ placeholder in every [name] attribute.
            clone.querySelectorAll( '[name]' ).forEach( function ( el ) {
                el.setAttribute(
                    'name',
                    el.getAttribute( 'name' ).replace( /__INDEX__/g, String( nextIndex ) )
                );
            } );

            list.appendChild( clone );
            nextIndex++;
            updateEmptyMessage();
            updateSegmentNumbers();

            // Focus the slug field of the new group for convenience.
            var slugInput = clone.querySelector( 'input[type="text"]' );
            if ( slugInput ) {
                slugInput.focus();
            }
        } );

        /** Remove the clicked group row (event delegation on the list). */
        list.addEventListener( 'click', function ( e ) {
            var btn = /** @type {HTMLElement} */ ( e.target );
            if ( ! btn.classList.contains( 'jpkcom-remove-group' ) ) {
                return;
            }
            var group = btn.closest( '.jpkcom-filter-group' );
            if ( ! group ) {
                return;
            }
            var toggle   = /** @type {HTMLInputElement|null} */ ( group.querySelector( '.jpkcom-custom-toggle' ) );
            var isCustom = !! ( toggle && toggle.checked );
            var i18n     = ( typeof jpkcomFilterGroupsI18n !== 'undefined' ) ? jpkcomFilterGroupsI18n : {};
            var msg      = isCustom
                ? ( i18n.confirmRemoveCustom || 'Warning: This group uses a Custom Taxonomy.\n\nAll term assignments on posts will be permanently lost.\n\nAre you sure?' )
                : ( i18n.confirmRemove || 'Are you sure you want to remove this filter group?' );
            if ( ! window.confirm( msg ) ) {
                return;
            }
            group.remove();
            updateEmptyMessage();
            updateSegmentNumbers();
        } );

        // Initialise visibility and numbering on page load.
        updateEmptyMessage();
        updateSegmentNumbers();
    } );
}() );
