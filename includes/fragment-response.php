<?php
/**
 * Fragment responses for AJAX filter requests
 *
 * Before this existed, every filter click rendered a complete page — theme
 * header, nav menus, sidebar widgets, footer, the whole asset pipeline — and
 * `assets/js/post-filter.js` threw all of it away except `[data-jpkpf-results]`.
 * The parameter the JS sent (`jpkpf_ajax=1`) appeared in no PHP file at all.
 *
 * A fragment request is a normal filter request routed through an extra URL
 * segment, so it walks the *same* rewrite rules, query vars and query pipeline
 * as the page it mirrors. Only the output differs: the chrome around the loop is
 * switched off and the response is cut down to the zones the JS actually swaps.
 *
 * The theme's loop still runs. It has to: in auto-inject mode the result markup
 * between `[data-jpkpf-results]` is produced by the theme, not by this plugin
 * (see `filter-injection.php`, `loop_start`/`loop_end`). What this saves is the
 * nav-menu and widget queries, the entire enqueue/print pipeline, and the bulk
 * of the transferred bytes — not the query and not the loop.
 *
 * Why a URL segment and not `?jpkpf_ajax=1`: a full-page cache keys on the URL,
 * and several common configurations strip query parameters they do not know.
 * A stripped parameter collapses the fragment URL onto the real page URL, and
 * the cache then happily serves a bare fragment — no theme, no header — to an
 * ordinary visitor. A distinct path cannot collapse that way.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.2.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Marker written immediately before a swappable zone
 *
 * @since 1.2.0
 * @var string
 */
const JPKCOM_POSTFILTER_ZONE_START = '<!--jpkpf:zone:start-->';

/**
 * Marker written immediately after a swappable zone
 *
 * @since 1.2.0
 * @var string
 */
const JPKCOM_POSTFILTER_ZONE_END = '<!--jpkpf:zone:end-->';


if ( ! function_exists( function: 'jpkcom_postfilter_fragment_segment' ) ) {
    /**
     * URL segment that marks a request as a fragment request
     *
     * Deliberately not the obvious `fragment`: the segment sits in the same
     * path position as taxonomy term slugs, and a site with a term called
     * `fragment` would produce genuinely ambiguous URLs. A prefixed slug cannot
     * collide with a term slug anyone would plausibly create.
     *
     * @since 1.2.0
     *
     * @return string Sanitised URL segment.
     */
    function jpkcom_postfilter_fragment_segment(): string {

        /**
         * Filter the fragment URL segment.
         *
         * Changing this requires a rewrite flush.
         *
         * @since 1.2.0
         *
         * @param string $segment Default 'jpkpf-fragment'.
         */
        $segment = (string) apply_filters( 'jpkcom_postfilter_fragment_segment', 'jpkpf-fragment' );

        $segment = sanitize_title( $segment );

        return $segment !== '' ? $segment : 'jpkpf-fragment';

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_is_fragment_request' ) ) {
    /**
     * Whether the current request should be answered with a fragment
     *
     * Reads the query var the rewrite rule sets. Checked against the main query
     * only — a secondary WP_Query must never flip the whole response into
     * fragment mode.
     *
     * @since 1.2.0
     *
     * @return bool True on a fragment request.
     */
    function jpkcom_postfilter_is_fragment_request(): bool {

        return (string) get_query_var( 'jpkcom_filter_fragment' ) === '1';

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_zone_open' ) ) {
    /**
     * Emit the opening zone marker, but only on a fragment request
     *
     * Markers are HTML comments placed *outside* the element they wrap, so the
     * element itself is part of the captured zone. They are written only when a
     * fragment is being produced, so normal page output is byte-identical to
     * before.
     *
     * @since 1.2.0
     *
     * @return void
     */
    function jpkcom_postfilter_zone_open(): void {

        if ( jpkcom_postfilter_is_fragment_request() ) {
            echo JPKCOM_POSTFILTER_ZONE_START; // phpcs:ignore WordPress.Security.EscapeOutput -- static literal
        }

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_zone_close' ) ) {
    /**
     * Emit the closing zone marker, but only on a fragment request
     *
     * @since 1.2.0
     *
     * @return void
     */
    function jpkcom_postfilter_zone_close(): void {

        if ( jpkcom_postfilter_is_fragment_request() ) {
            echo JPKCOM_POSTFILTER_ZONE_END; // phpcs:ignore WordPress.Security.EscapeOutput -- static literal
        }

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_wrap_zone' ) ) {
    /**
     * Wrap an already-rendered HTML string in zone markers
     *
     * For the render functions that return a string instead of printing
     * (shortcodes, blocks, page-builder elements).
     *
     * @since 1.2.0
     *
     * @param string $html Rendered HTML.
     * @return string Same HTML, marked when this is a fragment request.
     */
    function jpkcom_postfilter_wrap_zone( string $html ): string {

        if ( $html === '' || ! jpkcom_postfilter_is_fragment_request() ) {
            return $html;
        }

        return JPKCOM_POSTFILTER_ZONE_START . $html . JPKCOM_POSTFILTER_ZONE_END;

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_extract_zones' ) ) {
    /**
     * Cut the marked zones out of a rendered page
     *
     * Substring work against markers this plugin wrote itself, deliberately not
     * a regex or DOMDocument search for `[data-jpkpf-results]`: the results zone
     * contains arbitrary theme markup with nested elements, and matching its
     * closing tag by pattern is guesswork. Matching a marker is not.
     *
     * An unterminated start marker yields nothing rather than everything — the
     * failure mode of "return the rest of the document" is exactly the full-page
     * dump this whole change exists to avoid.
     *
     * @since 1.2.0
     *
     * @param string $html Complete rendered output.
     * @return string Concatenated zones in document order; empty string if none.
     */
    function jpkcom_postfilter_extract_zones( string $html ): string {

        $out    = '';
        $offset = 0;

        while ( true ) {

            $start = strpos( $html, JPKCOM_POSTFILTER_ZONE_START, $offset );

            if ( $start === false ) {
                break;
            }

            $content_at = $start + strlen( JPKCOM_POSTFILTER_ZONE_START );
            $end        = strpos( $html, JPKCOM_POSTFILTER_ZONE_END, $content_at );

            if ( $end === false ) {
                // Unterminated zone: drop it instead of running to end of document.
                break;
            }

            $out   .= substr( $html, $content_at, $end - $content_at );
            $offset = $end + strlen( JPKCOM_POSTFILTER_ZONE_END );

        }

        return $out;

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_fragment_url' ) ) {
    /**
     * Append the fragment segment to a filter URL
     *
     * Kept in PHP as well as JS so the two cannot drift apart silently; the
     * templates use it for the no-JS-visible data attribute the script reads.
     *
     * @since 1.2.0
     *
     * @param string $url Absolute or root-relative filter URL.
     * @return string URL with the fragment segment appended before the query string.
     */
    function jpkcom_postfilter_fragment_url( string $url ): string {

        $segment = jpkcom_postfilter_fragment_segment();

        $query = '';
        $hash  = '';

        $hash_at = strpos( $url, '#' );

        if ( $hash_at !== false ) {
            $hash = substr( $url, $hash_at );
            $url  = substr( $url, 0, $hash_at );
        }

        $query_at = strpos( $url, '?' );

        if ( $query_at !== false ) {
            $query = substr( $url, $query_at );
            $url   = substr( $url, 0, $query_at );
        }

        $url = rtrim( $url, '/' ) . '/' . $segment . '/';

        return $url . $query . $hash;

    }
}


// ---------------------------------------------------------------------------
// Response handling
// ---------------------------------------------------------------------------

/**
 * Switch off everything around the loop and start capturing
 *
 * Runs on `template_redirect`, i.e. after the query is resolved and before the
 * template is loaded, so the loop and its filters are untouched.
 *
 * @since 1.2.0
 */
add_action( 'template_redirect', static function (): void {

    if ( ! jpkcom_postfilter_is_fragment_request() ) {
        return;
    }

    // Headers first: once the buffer is handed back to PHP, output may already
    // have been committed.
    if ( ! headers_sent() ) {
        // A fragment is per-request and must never sit in a shared cache. The
        // distinct URL already prevents collapsing onto the page URL; this is
        // the second lock.
        header( 'Cache-Control: private, no-store, max-age=0' );
        // wp_head is emptied below, so the usual wp_robots route is gone.
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'X-Content-Type-Options: nosniff' );
    }

    // WordPress's canonical redirect does not recognise the fragment segment as
    // part of the URL and "repairs" a paginated fragment by appending the page
    // it thinks is missing: /page/2/jpkpf-fragment/ was answered with a 301 to
    // /page/2/jpkpf-fragment/page/2/. The script follows the redirect, gets a
    // 404, and falls back to a full page reload — so paginating a filtered list
    // silently stopped using AJAX at all. Found on a live install; no amount of
    // source reading would have shown it.
    add_filter( 'redirect_canonical', '__return_false', PHP_INT_MAX );

    // wp_enqueue_scripts is dispatched from wp_head, so emptying wp_head also
    // removes the entire enqueue and print pipeline — no separate dequeue pass.
    remove_all_actions( 'wp_head' );
    remove_all_actions( 'wp_footer' );

    // One wp_footer callback must survive. When auto-injection applies but the
    // main loop never runs — a filter combination with zero results — the
    // results zone is emitted from wp_footer and nowhere else. Dropping it here
    // would make a zero-result filter click return a fragment containing no
    // swappable zone at all, and the previous results would stay on screen.
    add_action( 'wp_footer', 'jpkcom_postfilter_render_zero_results_fallback', 1 );

    add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );

    // Short-circuit nav menus: each rendered menu is its own set of queries and
    // none of it can appear in a fragment.
    add_filter( 'pre_wp_nav_menu', static fn(): string => '', PHP_INT_MAX );

    // Same for widgets — sidebars cannot be part of a swappable zone.
    add_filter( 'sidebars_widgets', static fn(): array => [], PHP_INT_MAX );

    ob_start( 'jpkcom_postfilter_fragment_ob_callback' );

}, 0 );


if ( ! function_exists( function: 'jpkcom_postfilter_fragment_ob_callback' ) ) {
    /**
     * Reduce the captured page to its marked zones
     *
     * @since 1.2.0
     *
     * @param string $buffer Complete rendered output.
     * @return string Zones only.
     */
    function jpkcom_postfilter_fragment_ob_callback( string $buffer ): string {

        $zones = jpkcom_postfilter_extract_zones( $buffer );

        if ( $zones === '' ) {

            jpkcom_postfilter_debug_log( 'fragment request produced no marked zone', [
                'bytes' => strlen( $buffer ),
            ] );

            // No zone found means 0 results, or a URL that is not a filterable
            // archive at all. Either way the answer is "nothing to swap in" —
            // never the page that was just rendered.
            return '';

        }

        return $zones;

    }
}
