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
 * An empty `<template>` and not an HTML comment, since 1.4.4. Comments are
 * precisely what an HTML minifier is built to delete: Autoptimize ships with
 * "Optimize HTML Code" on and "Keep HTML comments" off, and its buffer is the
 * *inner* one (`template_redirect` priority 2 against this file's 0), so it
 * minified the page before `jpkcom_postfilter_extract_zones()` ever saw it.
 * Both markers gone, no zone found, a 0-byte 200 on every filter click.
 * Measured on the verification install: 18 300 B with Autoptimize off, 0 B with
 * its stock settings, 18 300 B again with `autoptimize_html` off and 8 976 B
 * with `autoptimize_html_keepcomments` on — which isolates it to the comment
 * stripping and nothing else about Autoptimize.
 *
 * No minifier removes elements, so a marker that *is* an element survives all
 * of them; `<template>` because it renders nothing and is valid almost
 * anywhere, including inside a table. It never reaches a browser either way —
 * extraction returns what lies *between* the markers.
 *
 * @since 1.2.0
 * @since 1.4.4 An element instead of an HTML comment.
 * @var string
 */
const JPKCOM_POSTFILTER_ZONE_START = '<template data-jpkpf-zone="start"></template>';

/**
 * Marker written immediately after a swappable zone
 *
 * @since 1.2.0
 * @since 1.4.4 An element instead of an HTML comment.
 * @var string
 */
const JPKCOM_POSTFILTER_ZONE_END = '<template data-jpkpf-zone="end"></template>';

/**
 * Answer for a fragment request whose markers were emitted but not found again
 *
 * Something between this plugin and the socket removed them. The response says
 * so instead of being empty, because an empty 200 is indistinguishable from
 * "no posts matched" and the script used to render it as exactly that.
 *
 * @since 1.4.4
 * @var string
 */
const JPKCOM_POSTFILTER_FRAGMENT_ERROR_STRIPPED = '<div data-jpkpf-fragment-error="markers-stripped"></div>';

/**
 * Answer for a fragment request that never marked a zone in the first place
 *
 * A URL that is not a filterable archive, or a page whose list was removed.
 * Not an error, but equally not something the script can swap in.
 *
 * @since 1.4.4
 * @var string
 */
const JPKCOM_POSTFILTER_FRAGMENT_ERROR_NO_ZONE = '<div data-jpkpf-fragment-error="no-zone"></div>';


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


if ( ! function_exists( function: 'jpkcom_postfilter_zone_count' ) ) {
    /**
     * How many zones this request has marked
     *
     * The difference between "this page has nothing to swap in" and "the markers
     * were destroyed on the way out" is not decidable from the buffer — both
     * look like a page without markers. Counting what was written makes it
     * decidable, and the two cases get different answers.
     *
     * @since 1.4.4
     *
     * @param bool $increment Count one more zone.
     * @return int Zones marked so far.
     */
    function jpkcom_postfilter_zone_count( bool $increment = false ): int {

        static $count = 0;

        if ( $increment ) {
            $count++;
        }

        return $count;

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
            jpkcom_postfilter_zone_count( increment: true );
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

        jpkcom_postfilter_zone_count( increment: true );

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
// Standing down HTML optimisers
// ---------------------------------------------------------------------------


if ( ! function_exists( function: 'jpkcom_postfilter_request_looks_like_fragment' ) ) {
    /**
     * Whether this request is a fragment request, answerable before the query runs
     *
     * `jpkcom_postfilter_is_fragment_request()` reads a query var, so it only
     * answers from `parse_request` onwards — and optimisation plugins decide
     * whether to touch a page well before that. Autoptimize freezes the decision
     * in a static the first time anything asks, and two of its own modules ask
     * on `wp`; with `AUTOPTIMIZE_INIT_EARLIER` defined it asks on `init`. Hence
     * the path check: it is available from the first line of PHP that runs.
     *
     * The query var is still preferred where it exists, because it is the
     * authority — the path check only has to be right about requests the
     * rewrite rules will route here anyway.
     *
     * @since 1.4.4
     *
     * @return bool True when this request is (or will be) answered with a fragment.
     */
    function jpkcom_postfilter_request_looks_like_fragment(): bool {

        // $wp_query does not exist yet while plugins are being loaded, and
        // get_query_var() would call a method on null.
        if ( isset( $GLOBALS['wp_query'] ) && jpkcom_postfilter_is_fragment_request() ) {
            return true;
        }

        $path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

        if ( $path === '' ) {
            return false;
        }

        return str_contains( rtrim( $path, '/' ) . '/', '/' . jpkcom_postfilter_fragment_segment() . '/' );

    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_fragment_force_noptimize' ) ) {
    /**
     * Filter callback: leave this response alone
     *
     * Shaped for the boolean "do not optimise this page" filters the
     * optimisation plugins expose. A `true` that is already there is never
     * turned back into a `false` — something else asked for the same thing and
     * this is not the place to overrule it.
     *
     * @since 1.4.4
     *
     * @param mixed $noptimize Current decision.
     * @return mixed True on a fragment request, the incoming value otherwise.
     */
    function jpkcom_postfilter_fragment_force_noptimize( mixed $noptimize = false ): mixed {

        if ( $noptimize ) {
            return $noptimize;
        }

        return jpkcom_postfilter_request_looks_like_fragment() ? true : $noptimize;

    }
}


/**
 * Ask the known HTML optimisers to skip fragment requests
 *
 * Registered while the plugin file is being loaded, and that is not a detail:
 * Autoptimize decides once per request and caches the answer in a static, and
 * `autoptimizeExtra::run_on_frontend()` and `autoptimizeImages::run_on_frontend()`
 * both ask on `wp`. A filter added in `template_redirect` — where the rest of
 * this file's work happens — arrives after the decision has already been made.
 *
 * Belt and braces next to the element markers: a minifier can no longer destroy
 * a marker, but it can still rewrite the markup inside a zone in ways that only
 * make sense with the head and footer that a fragment does not carry (an
 * aggregated inline `<style>` moved into `<head>`, for one). Nothing an
 * optimiser does to a fragment is wanted, so none of it should run.
 *
 * The list is filterable for the vendors this plugin does not know. It runs at
 * load time, so the only place that can usefully hook it is an mu-plugin —
 * those are loaded before regular plugins.
 *
 * @since 1.4.4
 *
 * @param string[] $filters Names of boolean "skip this page" filters.
 */
foreach ( (array) apply_filters( 'jpkcom_postfilter_fragment_noptimize_filters', [ 'autoptimize_filter_noptimize' ] ) as $jpkcom_postfilter_noptimize_filter ) {

    if ( is_string( $jpkcom_postfilter_noptimize_filter ) && $jpkcom_postfilter_noptimize_filter !== '' ) {
        add_filter( $jpkcom_postfilter_noptimize_filter, 'jpkcom_postfilter_fragment_force_noptimize', PHP_INT_MAX );
    }

}

unset( $jpkcom_postfilter_noptimize_filter );

// The cross-vendor convention for the same request, honoured by Autoptimize
// among others and cheap enough to set unconditionally. A constant has to be
// defined before anyone reads it, which is why this is not on a hook either.
if ( ! defined( 'DONOTMINIFY' ) && jpkcom_postfilter_request_looks_like_fragment() ) {
    define( 'DONOTMINIFY', true );
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

    // The zero-results fallback must survive. When auto-injection applies but
    // the main loop never runs — a filter combination with zero results — it is
    // the only thing that emits a results zone. Dropping it would make a
    // zero-result filter click return a fragment with no swappable zone at all,
    // leaving the previous results on screen.
    //
    // Only wp_footer was cleared, so re-attaching that one is enough — the
    // theme hook and get_footer are untouched. Its internal guard keeps the
    // render to one regardless of how many of them fire.
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

        if ( $zones !== '' ) {
            return $zones;
        }

        // Zero results is *not* one of the cases that land here: every list
        // template renders its `[data-jpkpf-results]` wrapper before it looks at
        // the query and puts the empty-state message inside it, and in
        // auto-inject mode `render_zero_results_fallback()` exists to guarantee
        // the same. So a zone was either never marked — a URL that is not a
        // filterable archive — or it was marked and something removed the
        // markers afterwards. The counter is what tells the two apart.
        $marked = jpkcom_postfilter_zone_count();

        jpkcom_postfilter_debug_log( 'fragment request produced no marked zone', [
            'bytes'        => strlen( $buffer ),
            'zones_marked' => $marked,
        ] );

        // Answering with an empty body was the mistake that let this go
        // unnoticed on a live site: the script cannot tell an empty 200 from a
        // filter that matched nothing, so it rendered "no posts found" and the
        // filter looked like it worked while showing a wrong, empty result set
        // on every click. A body the script recognises makes it fall back to a
        // full page load instead — slower, but the answer the server would have
        // given anyway.
        return $marked > 0
            ? JPKCOM_POSTFILTER_FRAGMENT_ERROR_STRIPPED
            : JPKCOM_POSTFILTER_FRAGMENT_ERROR_NO_ZONE;

    }
}
