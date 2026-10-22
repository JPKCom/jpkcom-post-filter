<?php
/**
 * Crawl Guard – keep the filter URL space finite for bots and caches
 *
 * Everything a crawler sees of the filter, in one place:
 *
 *  - the request guard: 301 to the one canonical spelling, 404/410 for paths
 *    that can never be a valid selection - decided on the `request` filter,
 *    before the main query runs;
 *  - the internal query vars, which are only ever taken from the rewrite rule;
 *  - robots directives (meta tag for core, Rank Math and Yoast, plus an
 *    X-Robots-Tag header) and exactly one canonical;
 *  - robots.txt rules for the combination space;
 *  - the term links of the filter bar, which decide what a link-following
 *    crawler can discover in the first place.
 *
 * WHY THIS EXISTS
 * ---------------
 * See includes/url-policy.php. In short: every term link pointed at "current
 * selection + this term" in click order, nothing was ever rejected, and every
 * spelling rendered as a separate indexable page. A link-following crawler
 * found millions of URLs from a few dozen terms, and a page cache keyed on the
 * URL never hit. Bots are not locked out by any of this: content, the archive
 * and the single-term filter pages stay crawlable and indexable. What goes away
 * is the maze.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.5.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Internal query vars come from the rewrite rule only
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_restrict_internal_query_vars' ) ) {
    /**
     * Drop the plugin's query vars unless the matched rewrite rule set them
     *
     * The three vars are registered as public query vars so the rewrite rules
     * can pass them, and WordPress lets GET/POST override a rewrite match. That
     * turned `?jpkcom_filter_path=…` on any URL into a second, unlimited entry
     * to the filter that no path-based rule (nginx, robots.txt, the request
     * guard) ever sees, and `?jpkcom_filter_fragment=1` switched any page into
     * fragment mode.
     *
     * @since 1.5.0
     *
     * @param array<string, mixed> $query_vars Request query vars.
     * @return array<string, mixed>
     */
    function jpkcom_postfilter_restrict_internal_query_vars( array $query_vars ): array {
        $wp      = $GLOBALS['wp'] ?? null;
        $matched = ( is_object( $wp ) && isset( $wp->matched_query ) && is_string( $wp->matched_query ) ) ? $wp->matched_query : '';

        $from_rule = [];
        parse_str( $matched, $from_rule );

        foreach ( [ 'jpkcom_filter_path', 'jpkcom_filter_post_type', 'jpkcom_filter_fragment' ] as $key ) {
            if ( isset( $from_rule[ $key ] ) && is_string( $from_rule[ $key ] ) ) {
                $query_vars[ $key ] = $from_rule[ $key ];
            } else {
                unset( $query_vars[ $key ] );
            }
        }

        return $query_vars;
    }
}

add_filter( 'request', 'jpkcom_postfilter_restrict_internal_query_vars', 0 );


// ---------------------------------------------------------------------------
// Request guard
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_request_verdict' ) ) {
    /**
     * Decide what to answer for a filter request
     *
     * Pure apart from the settings and term lookups, so it can be tested
     * without a request. The comparison that triggers a redirect is between
     * the requested path and the path of the URL built from the normalised
     * selection: one comparison covers order, duplicates, case, empty
     * segments, a trailing `_`, `page/1` and the trailing slash.
     *
     * @since 1.5.0
     *
     * @param array<string, mixed> $query_vars   Request query vars (after the rewrite match).
     * @param string               $request_uri  Raw REQUEST_URI.
     * @param string               $method       HTTP method.
     * @return array{action: string, status: int, location: string, reason: string, base: string, filters: array<string, string[]>, path: string}
     */
    function jpkcom_postfilter_request_verdict( array $query_vars, string $request_uri, string $method = 'GET' ): array {
        $path      = (string) ( $query_vars['jpkcom_filter_path'] ?? '' );
        $post_type = sanitize_key( (string) ( $query_vars['jpkcom_filter_post_type'] ?? 'post' ) );
        $post_type = $post_type !== '' ? $post_type : 'post';
        $fragment  = (string) ( $query_vars['jpkcom_filter_fragment'] ?? '' ) === '1';
        $paged     = max( 0, (int) ( $query_vars['paged'] ?? 0 ) );
        $base      = jpkcom_postfilter_get_archive_base_url( $post_type );

        $verdict = [
            'action'   => 'ok',
            'status'   => 200,
            'location' => '',
            'reason'   => '',
            'base'     => $base,
            'filters'  => [],
            'path'     => $path,
        ];

        if ( $path === '' || ! jpkcom_postfilter_policy( 'strict_urls' ) ) {
            return $verdict;
        }

        $taxonomies = jpkcom_postfilter_filter_taxonomies();
        $result     = jpkcom_postfilter_validate_filter_path(
            $path,
            $taxonomies,
            'jpkcom_postfilter_known_term_slugs',
            (string) jpkcom_postfilter_policy( 'unknown_terms_action' )
        );

        if ( $result['action'] === 'reject' ) {
            $verdict['action'] = 'reject';
            $verdict['status'] = (int) jpkcom_postfilter_policy( 'reject_status' );
            $verdict['reason'] = $result['reason'];
            return $verdict;
        }

        $verdict['filters'] = $result['filters'];
        $verdict['path']    = jpkcom_postfilter_filter_path_from_filters( $result['filters'], $taxonomies );

        // A fragment is fetched by the script, which builds its own URL and
        // cannot follow a redirect to a different page into the same zone.
        // It is answered for the cleaned selection instead (see the caller).
        if ( $fragment || ! in_array( $method, [ 'GET', 'HEAD' ], true ) || $base === '' ) {
            return $verdict;
        }

        $canonical = jpkcom_postfilter_get_filter_url( $base, $result['filters'], $paged );

        $requested = (string) parse_url( $request_uri, PHP_URL_PATH );
        $target    = (string) parse_url( $canonical, PHP_URL_PATH );
        $query     = (string) parse_url( $request_uri, PHP_URL_QUERY );

        $same_path = jpkcom_postfilter_normalize_url_path( $requested ) === jpkcom_postfilter_normalize_url_path( $target );

        // Differing only in the trailing slash is left alone: a permalink
        // structure without one, or a server rule stripping it, would
        // otherwise bounce between the two forever. The canonical tag still
        // names the one form.
        if ( ! $same_path && untrailingslashit( jpkcom_postfilter_normalize_url_path( $requested ) ) === untrailingslashit( jpkcom_postfilter_normalize_url_path( $target ) ) ) {
            $same_path = true;
        }

        $kept_query = $query;
        if ( $query !== '' && jpkcom_postfilter_policy( 'strip_query' ) ) {
            $kept_query = jpkcom_postfilter_filter_query_string( $query );
        }

        if ( $same_path && $kept_query === $query ) {
            return $verdict;
        }

        $verdict['action']   = 'redirect';
        $verdict['status']   = 301;
        $verdict['location'] = $canonical . ( $kept_query !== '' ? '?' . $kept_query : '' );
        $verdict['reason']   = $same_path ? 'query' : 'canonical';

        return $verdict;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_filter_query_string' ) ) {
    /**
     * Keep only allow-listed parameters of a query string
     *
     * Used when `strip_query` is on: `?x=1` on a filter URL is the same page
     * under a new cache key, and page caches usually skip URLs with a query
     * string entirely, so every variant was an uncached full render.
     *
     * @since 1.5.0
     *
     * @param string $query Raw query string.
     * @return string Remaining query string.
     */
    function jpkcom_postfilter_filter_query_string( string $query ): string {
        /**
         * Filter the query parameters kept on a filter URL when strip_query is on.
         *
         * Entries ending in `*` match a prefix, e.g. `utm_*`.
         *
         * @since 1.5.0
         *
         * @param string[] $allow Parameter names or prefixes.
         */
        $allow = (array) apply_filters( 'jpkcom_postfilter_strip_query_allowlist', [] );

        $kept = [];
        foreach ( explode( '&', $query ) as $pair ) {
            if ( $pair === '' ) {
                continue;
            }
            $name = urldecode( explode( '=', $pair, 2 )[0] );
            foreach ( $allow as $rule ) {
                $rule = (string) $rule;
                if ( $rule === $name || ( str_ends_with( $rule, '*' ) && str_starts_with( $name, substr( $rule, 0, -1 ) ) ) ) {
                    $kept[] = $pair;
                    break;
                }
            }
        }

        return implode( '&', $kept );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_send_reject' ) ) {
    /**
     * Answer a filter request with a cheap error page and stop
     *
     * No theme: rendering one costs the main query and the full template, which
     * is the expense this exists to avoid. The body links back to the archive
     * for the odd human who lands here.
     *
     * @since 1.5.0
     *
     * @param int    $status   404 or 410.
     * @param string $base_url Archive URL to link back to.
     * @param string $reason   Why (see jpkcom_postfilter_validate_filter_path()).
     * @return never
     */
    function jpkcom_postfilter_send_reject( int $status, string $base_url, string $reason = '' ): never {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        status_header( $status );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        // Tells the script this is a deliberate "no such selection", not a
        // broken fragment route (which it answers with a full reload).
        header( 'X-Jpkpf-Reject: ' . ( $reason !== '' ? sanitize_key( $reason ) : 'rejected' ) );

        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'HEAD' ) {
            printf(
                '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>%1$d</title></head><body><p>%2$s</p><p><a href="%3$s">%4$s</a></p></body></html>',
                (int) $status,
                esc_html__( 'This filter combination is not available.', 'jpkcom-post-filter' ),
                esc_url( $base_url !== '' ? $base_url : home_url( '/' ) ),
                esc_html__( 'Show all posts', 'jpkcom-post-filter' )
            );
        }

        exit;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_guard_request' ) ) {
    /**
     * Request guard on the `request` filter
     *
     * Runs inside WP::parse_request(), after the rewrite match and before the
     * main query: taxonomies and the cached term lists are available, nothing
     * has been queried or rendered yet. A redirect or rejection here costs the
     * bootstrap and nothing else.
     *
     * @since 1.5.0
     *
     * @param array<string, mixed> $query_vars Request query vars.
     * @return array<string, mixed>
     */
    function jpkcom_postfilter_guard_request( array $query_vars ): array {
        if ( ! isset( $query_vars['jpkcom_filter_path'] ) || ! is_string( $query_vars['jpkcom_filter_path'] ) || $query_vars['jpkcom_filter_path'] === '' ) {
            return $query_vars;
        }

        $verdict = jpkcom_postfilter_request_verdict(
            $query_vars,
            (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
            strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) )
        );

        /**
         * Filter the request guard's decision.
         *
         * @since 1.5.0
         *
         * @param array<string, mixed> $verdict    action ok|redirect|reject, status, location, reason, base, filters, path.
         * @param array<string, mixed> $query_vars Request query vars.
         */
        $verdict = (array) apply_filters( 'jpkcom_postfilter_request_verdict', $verdict, $query_vars );

        $action = (string) ( $verdict['action'] ?? 'ok' );

        if ( $action === 'reject' ) {
            jpkcom_postfilter_debug_log( 'request guard: rejected', [ 'path' => $query_vars['jpkcom_filter_path'], 'reason' => $verdict['reason'] ?? '' ] );
            jpkcom_postfilter_send_reject( (int) ( $verdict['status'] ?? 410 ), (string) ( $verdict['base'] ?? '' ), (string) ( $verdict['reason'] ?? '' ) );
        }

        if ( $action === 'redirect' ) {
            $location = wp_validate_redirect( (string) ( $verdict['location'] ?? '' ), '' );

            if ( $location !== '' ) {
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
                wp_safe_redirect( $location, (int) ( $verdict['status'] ?? 301 ), 'jpkcom-post-filter' );
                exit;
            }
        }

        // The main query parses the path again; hand it the cleaned one so a
        // fragment (never redirected) drops unknown slugs exactly like the page.
        if ( isset( $verdict['path'] ) && is_string( $verdict['path'] ) && $verdict['path'] !== $query_vars['jpkcom_filter_path'] && $action === 'ok' && jpkcom_postfilter_policy( 'strict_urls' ) ) {
            if ( $verdict['path'] === '' ) {
                unset( $query_vars['jpkcom_filter_path'] );
            } else {
                $query_vars['jpkcom_filter_path'] = $verdict['path'];
            }
        }

        return $query_vars;
    }
}

add_filter( 'request', 'jpkcom_postfilter_guard_request', 1 );


// ---------------------------------------------------------------------------
// Robots directives
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_main_query_is_empty' ) ) {
    /**
     * Whether the (already executed) main query found nothing on page 1
     *
     * @since 1.5.0
     *
     * @return bool False as long as the main query has not run.
     */
    function jpkcom_postfilter_main_query_is_empty(): bool {
        $q = $GLOBALS['wp_query'] ?? null;

        return $q instanceof \WP_Query
            && is_array( $q->posts )
            && ! $q->is_paged()
            && count( $q->posts ) === 0;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_robots_directives' ) ) {
    /**
     * Robots directives for the current filter page
     *
     * One decision for the meta tag (core, Rank Math, Yoast) and the HTTP
     * header. A filter page is `noindex, nofollow` when it combines more terms
     * than `seo_index_max_terms` (default 1), names an unknown slug, or has no
     * results on page 1. Single-term pages stay `index, follow`.
     *
     * `nofollow` is deliberate: everything such a page links to is reachable
     * from the archive, the single-term pages and the sitemap. Following its
     * links only leads further into the combinations.
     *
     * @since 1.5.0
     *
     * @return array{index: bool, follow: bool}|null Null when not a filter request.
     */
    function jpkcom_postfilter_robots_directives(): ?array {
        if ( ! jpkcom_postfilter_is_filter_request() ) {
            return null;
        }

        $filters = jpkcom_postfilter_get_active_filters();
        $terms   = jpkcom_postfilter_count_filter_terms( $filters );
        $max     = (int) jpkcom_postfilter_policy( 'seo_index_max_terms' );

        $directives = [ 'index' => true, 'follow' => true ];

        if ( $terms > $max || jpkcom_postfilter_has_unknown_terms() || jpkcom_postfilter_main_query_is_empty() ) {
            $directives = [ 'index' => false, 'follow' => false ];
        }

        /**
         * Filter the robots directives of a filter page.
         *
         * @since 1.5.0
         *
         * @param array{index: bool, follow: bool} $directives Directives.
         * @param array<string, string[]>          $filters    Active filters.
         */
        $directives = (array) apply_filters( 'jpkcom_postfilter_robots_directives', $directives, $filters );

        return [
            'index'  => (bool) ( $directives['index'] ?? true ),
            'follow' => (bool) ( $directives['follow'] ?? true ),
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_should_noindex' ) ) {
    /**
     * Whether the current filter page must not be indexed
     *
     * Kept for backwards compatibility; the decision is
     * jpkcom_postfilter_robots_directives().
     *
     * @since 1.2.0
     *
     * @return bool
     */
    function jpkcom_postfilter_should_noindex(): bool {
        $directives = jpkcom_postfilter_robots_directives();
        return $directives !== null && ! $directives['index'];
    }
}


/**
 * Core robots output
 *
 * On most of the sites this plugin runs on it never fires: Rank Math calls
 * `remove_all_filters( 'wp_robots' )` before emitting its own tag. Hence the
 * Rank Math and Yoast callbacks below, all sharing one decision.
 *
 * @since 1.1.7
 */
add_filter( 'wp_robots', static function ( array $robots ): array {

    $directives = jpkcom_postfilter_robots_directives();

    if ( $directives === null || ( $directives['index'] && $directives['follow'] ) ) {
        return $robots;
    }

    unset( $robots['index'], $robots['follow'] );

    if ( ! $directives['index'] ) {
        $robots['noindex'] = true;
    }
    if ( ! $directives['follow'] ) {
        $robots['nofollow'] = true;
    }

    return $robots;
}, 99 );

/**
 * Rank Math robots
 *
 * Rank Math's native keys are `index` and `follow` with the values
 * `index|noindex` and `follow|nofollow`. Until 1.5.0 this callback wrote a
 * `noindex` key instead: the tag came out right, but Rank Math decides whether
 * to drop its own canonical and prev/next links on `$robots['index']` alone, so
 * noindex pages kept a canonical pointing somewhere else.
 *
 * @since 1.2.0
 */
add_filter( 'rank_math/frontend/robots', static function ( $robots ) {

    $directives = jpkcom_postfilter_robots_directives();

    if ( $directives === null || ( $directives['index'] && $directives['follow'] ) ) {
        return $robots;
    }

    $robots = is_array( $robots ) ? $robots : [];

    unset( $robots['noindex'], $robots['nofollow'] );

    $robots['index']  = $directives['index'] ? 'index' : 'noindex';
    $robots['follow'] = $directives['follow'] ? 'follow' : 'nofollow';

    return $robots;
}, 99 );

/**
 * Yoast SEO robots
 *
 * @since 1.2.0
 */
add_filter( 'wpseo_robots_array', static function ( $robots ) {

    $directives = jpkcom_postfilter_robots_directives();

    if ( $directives === null || ( $directives['index'] && $directives['follow'] ) ) {
        return $robots;
    }

    $robots = is_array( $robots ) ? $robots : [];

    $robots['index']  = $directives['index'] ? 'index' : 'noindex';
    $robots['follow'] = $directives['follow'] ? 'follow' : 'nofollow';

    return $robots;
}, 99 );


/**
 * Send the robots decision as an HTTP header as well
 *
 * Independent of the SEO plugin and of whether the head is printed at all. A
 * fragment response sets its own header in fragment-response.php.
 *
 * @since 1.5.0
 */
add_action( 'template_redirect', static function (): void {

    if ( headers_sent() || jpkcom_postfilter_is_fragment_request() ) {
        return;
    }

    $directives = jpkcom_postfilter_robots_directives();

    if ( $directives === null ) {
        return;
    }

    if ( ! ( $directives['index'] && $directives['follow'] ) && ( ! defined( 'JPKCOM_POSTFILTER_ROBOTS_HEADER' ) || JPKCOM_POSTFILTER_ROBOTS_HEADER ) ) {
        header(
            'X-Robots-Tag: ' . ( $directives['index'] ? 'index' : 'noindex' ) . ', ' . ( $directives['follow'] ? 'follow' : 'nofollow' ),
            false
        );
    }

    // Opt-in only. A finite, canonical URL space is what makes a page cache
    // work at all; keeping deep combinations out of it means every request
    // for one - including each alias that the guard redirects onto it - is a
    // full render again.
    $cache_max = defined( 'JPKCOM_POSTFILTER_PAGECACHE_MAX_TERMS' ) ? (int) JPKCOM_POSTFILTER_PAGECACHE_MAX_TERMS : 0;

    /**
     * Filter whether this filter page is kept out of page caches.
     *
     * @since 1.5.0
     *
     * @param bool                    $donotcache Default from JPKCOM_POSTFILTER_PAGECACHE_MAX_TERMS.
     * @param array<string, string[]> $filters    Active filters.
     */
    $donotcache = (bool) apply_filters(
        'jpkcom_postfilter_donotcache',
        $cache_max > 0 && jpkcom_postfilter_count_filter_terms( jpkcom_postfilter_get_active_filters() ) > $cache_max,
        jpkcom_postfilter_get_active_filters()
    );

    if ( $donotcache ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        header( 'Cache-Control: private, no-store, max-age=0' );
    }

}, 1 );


// ---------------------------------------------------------------------------
// Canonical
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_canonical_url' ) ) {
    /**
     * Canonical URL of the current filter page
     *
     * @since 1.5.0
     *
     * @return string|null Null when this is not a filter page, '' for "no
     *                     canonical" (noindex pages), the URL otherwise.
     */
    function jpkcom_postfilter_canonical_url(): ?string {
        if ( ! jpkcom_postfilter_is_filter_request() ) {
            return null;
        }

        $directives = jpkcom_postfilter_robots_directives();
        if ( $directives !== null && ! $directives['index'] ) {
            return '';
        }

        $post_type = (string) get_query_var( 'jpkcom_filter_post_type', 'post' );
        $post_type = $post_type !== '' ? $post_type : 'post';
        $page      = max( 1, (int) get_query_var( 'paged' ) );

        $url = jpkcom_postfilter_get_filter_url(
            jpkcom_postfilter_get_archive_base_url( $post_type ),
            jpkcom_postfilter_get_active_filters(),
            $page > 1 ? $page : 0
        );

        /**
         * Filter the canonical URL of an indexable filter page.
         *
         * @since 1.5.0
         *
         * @param string $url Canonical URL.
         */
        return (string) apply_filters( 'jpkcom_postfilter_canonical', $url );
    }
}


/**
 * Hand the canonical to the SEO plugin instead of printing a second one
 *
 * Filter URLs route to the posts page, so Rank Math and Yoast print that
 * page's canonical (`/blog/`) on every filter page. Until 1.5.0 this plugin
 * printed its own tag next to it: two contradicting canonicals, which search
 * engines resolve by ignoring both.
 *
 * @since 1.5.0
 */
add_filter( 'rank_math/frontend/canonical', static function ( $canonical ) {
    $url = jpkcom_postfilter_canonical_url();
    return $url === null ? $canonical : $url;
}, 99 );

add_filter( 'wpseo_canonical', static function ( $canonical ) {
    $url = jpkcom_postfilter_canonical_url();
    if ( $url === null ) {
        return $canonical;
    }
    return $url !== '' ? $url : false;
}, 99 );

// Rank Math's prev/next on a filter page point into the unfiltered archive.
add_filter( 'rank_math/frontend/disable_adjacent_rel_links', static function ( $disable ) {
    return jpkcom_postfilter_is_filter_request() ? true : $disable;
}, 99 );

add_action( 'wp', static function (): void {

    if ( ! jpkcom_postfilter_is_filter_request() ) {
        return;
    }

    remove_action( 'wp_head', 'rel_canonical' );

    if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
        return;
    }

    add_action( 'wp_head', static function (): void {
        $url = jpkcom_postfilter_canonical_url();
        if ( $url !== null && $url !== '' ) {
            printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
        }
    }, 1 );

}, 10 );


// ---------------------------------------------------------------------------
// robots.txt
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_archive_base_urls_all_languages' ) ) {
    /**
     * Archive base URL of a post type in every language
     *
     * Crawlers read robots.txt only at the host root, but WPML localises
     * home_url() and permalinks to the language of the current request: the
     * root robots.txt named `/filter/` and never `/en/filter/`. With WPML
     * active the base is added once per active language.
     *
     * @since 1.5.0
     *
     * @param string $post_type Post type.
     * @return string[] Base URLs, empty entries dropped.
     */
    function jpkcom_postfilter_archive_base_urls_all_languages( string $post_type ): array {
        $base = jpkcom_postfilter_get_archive_base_url( $post_type );
        $urls = [ $base ];

        $languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );

        // wpml_permalink resolves a page (a posts page, including its
        // translated slug) but hands the bare home URL and archive URLs back
        // unchanged - measured on WPML 4.8 in directory mode: `/en/` never
        // appeared for the home URL, and its absolute-link resolver has no
        // branch for post type archives. For those the language's home URL
        // replaces the home prefix. SitePress::language_url() gives that URL
        // without switching the language: the switch action always sets the
        // language cookie, which overwrote a logged-in visitor's language on
        // a robots.txt request.
        global $sitepress;

        if ( $base !== '' && is_array( $languages ) ) {
            $home = home_url( '/' );

            foreach ( array_keys( $languages ) as $code ) {
                $url = (string) apply_filters( 'wpml_permalink', $base, (string) $code, true );

                if (
                    untrailingslashit( $url ) === untrailingslashit( $base )
                    && str_starts_with( $base, $home )
                    && is_object( $sitepress )
                    && method_exists( $sitepress, 'language_url' )
                ) {
                    $url = trailingslashit( (string) $sitepress->language_url( (string) $code ) ) . substr( $base, strlen( $home ) );
                }

                $urls[] = $url;
            }
        }

        /**
         * Filter the archive base URLs the robots.txt rules are written for.
         *
         * For multilingual plugins other than WPML.
         *
         * @since 1.5.0
         *
         * @param string[] $urls      Base URLs.
         * @param string   $post_type Post type.
         */
        $urls = (array) apply_filters( 'jpkcom_postfilter_robots_txt_base_urls', $urls, $post_type );

        return array_values( array_unique( array_filter( array_map( 'strval', $urls ), static fn( string $u ): bool => $u !== '' ) ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_robots_txt_rules' ) ) {
    /**
     * robots.txt rules for the filter URLs
     *
     * Applies to every user agent alike - no crawler is locked out of content
     * or of the single-term pages, only out of the combinations.
     *
     *  - deep (default), with seo_index_max_terms = 1:
     *      `{base}/{endpoint}/*+`   any segment with more than one term
     *    Not `*\/*\/` (proposed in the original analysis): it would also block
     *    `_/term/` - the single-term pages of the second group, which the
     *    default link mode links to and which are indexable - and the
     *    pagination of every single-term page. Blocked but linked pages end
     *    up "indexed, though blocked by robots.txt". Two-segment combinations
     *    are not linked in single mode and are noindex anyway.
     *  - all: the whole endpoint
     *  - off: nothing
     *
     * With seo_index_max_terms = 0 "deep" becomes "all"; with 2 or more the
     * indexable pages cannot be told apart by a wildcard, so only the
     * fragment and query-var lines are emitted.
     *
     * @since 1.5.0
     *
     * @return string[] Lines without the `User-agent` line.
     */
    function jpkcom_postfilter_robots_txt_rules(): array {
        $mode = (string) jpkcom_postfilter_policy( 'robots_txt_mode' );

        if ( $mode === 'off' ) {
            return [];
        }

        $index_max = (int) jpkcom_postfilter_policy( 'seo_index_max_terms' );
        if ( $mode === 'deep' && $index_max === 0 ) {
            $mode = 'all';
        }

        $endpoint = sanitize_key( jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) );
        $lines    = [];

        foreach ( jpkcom_postfilter_routable_post_types() as $post_type ) {
            foreach ( jpkcom_postfilter_archive_base_urls_all_languages( $post_type ) as $base ) {
                $prefix = trailingslashit( (string) wp_parse_url( $base, PHP_URL_PATH ) ) . $endpoint . '/';

                if ( $mode === 'all' ) {
                    $lines[] = 'Disallow: ' . $prefix;
                } elseif ( $index_max === 1 ) {
                    $lines[] = 'Disallow: ' . $prefix . '*+';
                }
            }
        }

        // No leading `?`: robots.txt matches by prefix, and `/*?jpkcom_filter_`
        // would miss the parameter whenever it is not the first one.
        $lines[] = 'Disallow: /*' . jpkcom_postfilter_fragment_segment();
        $lines[] = 'Disallow: /*jpkcom_filter_';

        /**
         * Filter the robots.txt lines for the filter URLs.
         *
         * @since 1.5.0
         *
         * @param string[] $lines Disallow lines (the `User-agent: *` line is added around them).
         */
        return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'jpkcom_postfilter_robots_txt_rules', $lines ) ) ) );
    }
}


/**
 * Append the filter rules to WordPress's virtual robots.txt
 *
 * Only reaches clients when the web server hands /robots.txt to WordPress -
 * a physical robots.txt file wins over this. The admin General page shows the
 * same lines for copying into one.
 *
 * Priority 99: Rank Math replaces the whole output at 10 when its editor is
 * filled, which would discard rules added earlier.
 *
 * @since 1.5.0
 */
add_filter( 'robots_txt', static function ( $output, $public ) {

    if ( ! (bool) (int) $public ) {
        return $output;
    }

    $lines = jpkcom_postfilter_robots_txt_rules();

    if ( empty( $lines ) ) {
        return $output;
    }

    return rtrim( (string) $output ) . "\n\n# jpkcom-post-filter\nUser-agent: *\n" . implode( "\n", $lines ) . "\n";
}, 99, 2 );


// ---------------------------------------------------------------------------
// Term links
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_term_href' ) ) {
    /**
     * Where a term button links to - or null for no link at all
     *
     * The script never reads these hrefs: it intercepts every click and builds
     * the URL from the button states. The hrefs serve clients without
     * JavaScript, which in practice means crawlers. They are therefore the
     * generator of the URL space a crawler can discover, and the link mode
     * decides its size:
     *
     *  - single (default): an inactive term links to its own single-term page,
     *    an active term to the selection without it while at most one term is
     *    left. Crawlable: the archive plus one page per term.
     *  - combo: "current selection ± this term", as before 1.5.0, but never
     *    beyond the caps, never deeper than JPKCOM_POSTFILTER_LINK_MAX_TERMS,
     *    and on an empty result page not into another group (that can only
     *    narrow nothing further).
     *  - none: no hrefs, buttons only.
     *
     * @since 1.5.0
     *
     * @param string                  $base_url       Archive base URL.
     * @param array<string, string[]> $active_filters Active filters.
     * @param string                  $taxonomy       Term taxonomy.
     * @param string                  $slug           Term slug.
     * @param bool                    $is_active      Whether the term is selected.
     * @return string|null
     */
    function jpkcom_postfilter_term_href( string $base_url, array $active_filters, string $taxonomy, string $slug, bool $is_active ): ?string {
        $active_filters = jpkcom_postfilter_normalize_filters( $active_filters );
        $current        = $active_filters[ $taxonomy ] ?? [];

        $next              = $active_filters;
        $next[ $taxonomy ] = $is_active
            ? array_values( array_diff( $current, [ $slug ] ) )
            : array_merge( $current, [ $slug ] );
        $next              = jpkcom_postfilter_normalize_filters( $next );
        $depth             = jpkcom_postfilter_count_filter_terms( $next );

        $href = null;

        switch ( (string) jpkcom_postfilter_policy( 'filter_link_mode' ) ) {
            case 'single':
                if ( ! $is_active ) {
                    $href = jpkcom_postfilter_get_filter_url( $base_url, [ $taxonomy => [ $slug ] ] );
                } elseif ( $depth <= 1 ) {
                    $href = jpkcom_postfilter_get_filter_url( $base_url, $next );
                }
                break;

            case 'combo':
                $max_depth = defined( 'JPKCOM_POSTFILTER_LINK_MAX_TERMS' ) ? max( 0, (int) JPKCOM_POSTFILTER_LINK_MAX_TERMS ) : 0;

                if ( $is_active ) {
                    $href = jpkcom_postfilter_get_filter_url( $base_url, $next );
                } elseif (
                    jpkcom_postfilter_filters_within_caps( $next )
                    && ( $max_depth === 0 || $depth <= $max_depth )
                    && ! ( empty( $current ) && jpkcom_postfilter_main_query_is_empty() )
                ) {
                    $href = jpkcom_postfilter_get_filter_url( $base_url, $next );
                }
                break;
        }

        /**
         * Filter the href of a term button. Null renders a button without a link.
         *
         * @since 1.5.0
         *
         * @param string|null             $href           Link target.
         * @param string                  $base_url       Archive base URL.
         * @param array<string, string[]> $active_filters Active filters.
         * @param string                  $taxonomy       Term taxonomy.
         * @param string                  $slug           Term slug.
         * @param bool                    $is_active      Whether the term is selected.
         */
        $href = apply_filters( 'jpkcom_postfilter_term_href', $href, $base_url, $active_filters, $taxonomy, $slug, $is_active );

        return is_string( $href ) && $href !== '' ? $href : null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_url_term_count' ) ) {
    /**
     * How many terms a filter URL selects, read from its path
     *
     * @since 1.5.0
     *
     * @param string $url      Filter URL.
     * @param string $base_url Archive base URL.
     * @return int
     */
    function jpkcom_postfilter_url_term_count( string $url, string $base_url ): int {
        $endpoint = sanitize_key( jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) );
        $prefix   = trailingslashit( $base_url ) . $endpoint . '/';

        if ( ! str_starts_with( $url, $prefix ) ) {
            return 0;
        }

        $path  = (string) preg_replace( '#/page/[0-9]+/?$#', '', substr( $url, strlen( $prefix ) ) );
        $count = 0;
        foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
            if ( $segment !== '' && $segment !== '_' ) {
                $count += count( explode( '+', $segment ) );
            }
        }

        return $count;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_term_link_attributes' ) ) {
    /**
     * Element and attributes for a term button
     *
     * A term with a link target is an `<a href>`; one without is a
     * `<button type="button">`, which keyboards and screen readers handle
     * natively and which the script drives exactly like the link (it reads
     * only the data attributes and aria-pressed).
     *
     * A link to a page that is not indexable carries rel="nofollow".
     *
     * @since 1.5.0
     *
     * @param string                  $base_url       Archive base URL.
     * @param array<string, string[]> $active_filters Active filters.
     * @param string                  $taxonomy       Term taxonomy.
     * @param string                  $slug           Term slug.
     * @param bool                    $is_active      Whether the term is selected.
     * @return array{tag: string, attrs: string} `attrs` is already escaped.
     */
    function jpkcom_postfilter_term_link_attributes( string $base_url, array $active_filters, string $taxonomy, string $slug, bool $is_active ): array {
        $href = jpkcom_postfilter_term_href( $base_url, $active_filters, $taxonomy, $slug, $is_active );

        if ( $href === null ) {
            return [ 'tag' => 'button', 'attrs' => 'type="button"' ];
        }

        $rel = jpkcom_postfilter_url_term_count( $href, $base_url ) > (int) jpkcom_postfilter_policy( 'seo_index_max_terms' )
            ? 'nofollow'
            : '';

        /**
         * Filter the rel attribute of a term link.
         *
         * @since 1.5.0
         *
         * @param string $rel  Default 'nofollow' for links to non-indexable pages, else ''.
         * @param string $href Link target.
         */
        $rel = (string) apply_filters( 'jpkcom_postfilter_term_link_rel', $rel, $href );

        return [
            'tag'   => 'a',
            'attrs' => 'href="' . esc_url( $href ) . '"' . ( $rel !== '' ? ' rel="' . esc_attr( $rel ) . '"' : '' ),
        ];
    }
}
