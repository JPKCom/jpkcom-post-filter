<?php
/**
 * Server Rules – what a web server may reject before PHP runs
 *
 * No hooks are registered here: the inputs are read from WordPress on demand,
 * everything else is pure, so tests/test-server-rules.php can fuzz the
 * patterns against the real request guard without a server. The snippets
 * built from these patterns live in includes/server-rules-render.php.
 *
 * WHY THIS EXISTS
 * ---------------
 * The 1.5.0 request guard answers over-cap filter URLs with 410, but only
 * after the WordPress bootstrap - on the production site that prompted 1.5.0
 * the bootstrap alone was enough to exhaust PHP-FPM. A web server can turn the
 * same URLs away for almost nothing, provided its rule is never stricter than
 * the plugin: a server rule must never match a URL the plugin answers with 200
 * or 301. Looser is fine; PHP still has the last word.
 *
 * The patterns read the RAW request URI (nginx `$request_uri`, Apache
 * `THE_REQUEST`, Caddy `{http.request.uri}`, Ferron `request.uri`) and only
 * ever match a path without `%`. WordPress tries every rewrite rule on the raw
 * path and then on its urldecode()d form (WP::parse_request()), so an encoded
 * `%70age`, `jpkpf%2Dfragment` or `%2F` splits the filter path differently
 * from its raw bytes; leaving every `%` path to PHP rules that out entirely.
 *
 * They use only what PCRE (nginx, Apache, OpenLiteSpeed), RE2 (Caddy) and
 * Rust's `regex` (Ferron) share: literals, negated classes, non-capturing
 * groups, `* + ? {n} {n,} {n,m}`, alternation and anchors. No lookaround, no
 * backreferences - one grammar, one fuzz test for every server.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_rule_base_path' ) ) {
    /**
     * Reduce an archive base URL to the path a server rule can match
     *
     * @since 1.6.0
     *
     * @param string $url Absolute base URL (home path included).
     * @return string|null Path with leading and trailing slash, or null when it
     *                     contains `%` - browsers send percent escapes in either
     *                     case and WordPress also matches the decoded form, so
     *                     such a base is left to PHP - or cannot be parsed.
     */
    function jpkcom_postfilter_server_rule_base_path( string $url ): ?string {
        $path = wp_parse_url( $url, PHP_URL_PATH );

        if ( $path === false ) {
            return null; // malformed URL: no base rather than the site root
        }

        $path = is_string( $path ) ? trim( $path, '/' ) : '';
        $path = $path === '' ? '/' : '/' . $path . '/';

        return str_contains( $path, '%' ) ? null : $path;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rule_bases' ) ) {
    /**
     * The base paths a server rule covers for the given post types
     *
     * Every post type that gets rewrite rules, in every language
     * jpkcom_postfilter_archive_base_urls_all_languages() knows, as paths
     * including the home path, sorted and without duplicates. Separate from
     * jpkcom_postfilter_server_rule_inputs() so the .htaccess sync can work
     * out the bases of a post type list that is not saved yet.
     *
     * @since 1.6.0
     *
     * @param string[] $post_types Post type slugs, as jpkcom_postfilter_routable_post_types() returns them.
     * @return string[]
     */
    function jpkcom_postfilter_server_rule_bases( array $post_types ): array {
        $page_for_posts = (int) get_option( 'page_for_posts' );
        $bases          = [];

        foreach ( $post_types as $post_type ) {
            $post_type = (string) $post_type;

            // Same gate as the rewrite rules in url-routing.php.
            if ( jpkcom_postfilter_archive_base_regex( $post_type, $page_for_posts ) === null ) {
                continue;
            }

            foreach ( jpkcom_postfilter_archive_base_urls_all_languages( $post_type ) as $url ) {
                $path = jpkcom_postfilter_server_rule_base_path( $url );
                if ( $path !== null ) {
                    $bases[] = $path;
                }
            }
        }

        $bases = array_values( array_unique( $bases ) );
        sort( $bases, SORT_STRING );

        return $bases;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rule_inputs' ) ) {
    /**
     * The effective values a server rule is generated from
     *
     * Effective means after constants, stored settings, defaults and the
     * `jpkcom_postfilter_{key}` filters - what this request's guard would use.
     * A filter that answers differently per request cannot be seen from here,
     * which is why `policy_filtered` and `verdict_filtered` are reported.
     *
     * Bases: every routable post type that gets rewrite rules, in every
     * language jpkcom_postfilter_archive_base_urls_all_languages() knows,
     * as paths including the home path. A missing base only makes the rule
     * looser. For a base the plugin derives itself (post type archive, posts
     * page, WPML language directory) a path it does not route is one
     * WordPress would 404 anyway. That is NOT proven for bases a callback on
     * `jpkcom_postfilter_robots_txt_base_urls` adds (multilingual plugins
     * other than WPML): the filter feeds the server rejects too, so if other
     * code serves `{base}{endpoint}/a+b+c/` below such a base, the server
     * would reject it. A callback must return only bases the plugin routes.
     *
     * @since 1.6.0
     *
     * @return array{strict: bool, max_per_group: int, groups: int, skip_positions: int[], reject_status: int, endpoint: string, fragment: string, bases: string[], verdict_filtered: bool, policy_filtered: string[], hash: string}
     */
    function jpkcom_postfilter_server_rule_inputs(): array {
        $taxonomies = jpkcom_postfilter_filter_taxonomies();

        $skip = [];
        foreach ( $taxonomies as $position => $taxonomy ) {
            if ( $taxonomy === '' ) {
                $skip[] = (int) $position;
            }
        }

        $bases = jpkcom_postfilter_server_rule_bases( post_types: jpkcom_postfilter_routable_post_types() );

        // The policy keys a server rule is built from.
        $policy_filtered = [];
        foreach ( [ 'strict_urls', 'max_filters_per_group', 'reject_status' ] as $key ) {
            if ( has_filter( 'jpkcom_postfilter_' . $key ) !== false ) {
                $policy_filtered[] = $key;
            }
        }

        $inputs = [
            'strict'           => (bool) jpkcom_postfilter_policy( 'strict_urls' ),
            'max_per_group'    => (int) jpkcom_postfilter_policy( 'max_filters_per_group' ),
            'groups'           => count( $taxonomies ),
            'skip_positions'   => $skip,
            'reject_status'    => (int) jpkcom_postfilter_policy( 'reject_status' ),
            'endpoint'         => sanitize_key( (string) jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) ),
            'fragment'         => jpkcom_postfilter_fragment_segment(),
            'bases'            => $bases,
            'verdict_filtered' => has_filter( 'jpkcom_postfilter_request_verdict' ) !== false,
            'policy_filtered'  => $policy_filtered,
            'hash'             => '',
        ];

        $inputs['hash'] = jpkcom_postfilter_server_rule_hash( inputs: $inputs );

        return $inputs;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rule_hash' ) ) {
    /**
     * Short hash over every input that shapes the rule
     *
     * Sent as `X-Jpkpf-Rule` by the server and compared by the status check,
     * so a deployed rule built from other settings is recognised as stale.
     * Neither the key order of the argument nor the order of `skip_positions`
     * and `bases` matters (both are sets); `policy_filtered` and `hash` do not
     * shape the rule and are left out.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return string First 12 hex characters of sha256( json_encode ).
     */
    function jpkcom_postfilter_server_rule_hash( array $inputs ): string {
        $skip = array_values( array_unique( array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) ) ) );
        sort( $skip );

        $bases = array_values( array_unique( array_map( 'strval', (array) ( $inputs['bases'] ?? [] ) ) ) );
        sort( $bases, SORT_STRING );

        $data = [
            'strict'           => (bool) ( $inputs['strict'] ?? false ),
            'max_per_group'    => (int) ( $inputs['max_per_group'] ?? 0 ),
            'groups'           => (int) ( $inputs['groups'] ?? 0 ),
            'skip_positions'   => $skip,
            'reject_status'    => (int) ( $inputs['reject_status'] ?? 0 ),
            'endpoint'         => (string) ( $inputs['endpoint'] ?? '' ),
            'fragment'         => (string) ( $inputs['fragment'] ?? '' ),
            'bases'            => $bases,
            'verdict_filtered' => (bool) ( $inputs['verdict_filtered'] ?? false ),
        ];

        return substr( hash( algo: 'sha256', data: (string) json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE ) ), 0, 12 );
    }
}


// ---------------------------------------------------------------------------
// Patterns
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_regex_quote' ) ) {
    /**
     * Escape a literal for the server patterns
     *
     * Only `\ . + * ? ( ) [ ] { } | ^ $`. Unlike preg_quote(), `-`, `/`, `#`
     * and the rest stay as they are: an escape that PCRE tolerates may be an
     * error or mean something else in RE2 or Rust's `regex`.
     *
     * @since 1.6.0
     *
     * @param string $s Literal.
     * @return string
     */
    function jpkcom_postfilter_server_regex_quote( string $s ): string {
        return (string) preg_replace( '/[\\\\.+*?()\[\]{}|^$]/', '\\\\$0', $s );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_reject_patterns' ) ) {
    /**
     * The two patterns a server may reject a filter URL on
     *
     * Regexes without delimiters, for the raw request URI (path plus optional
     * query). With B = the bases without their leading `/`, E = endpoint,
     * N = terms per group, G = filter groups:
     *
     *   per_group: a segment below {B}{E}/ with N+1 or more non-empty pieces
     *              separated by literal `+`
     *   segments:  segment G+1 contains a `+`, or there are G+4 segments
     *
     * Why that is never stricter than the plugin, for a path without `%`:
     *  - urldecode() changes nothing but `+` there, and no rewrite rule
     *    literal contains `+`, so WordPress captures from the raw path.
     *  - The capture is the path after {E}/ minus an optional
     *    `page/N` / fragment suffix - at most three segments, none with a
     *    `+`. A segment with a `+` is therefore always part of the capture,
     *    and of G+4 segments at least G+1 are.
     *  - The guard rejects more than G non-empty segments (`segments`), and
     *    since 1.6.0 more than N non-empty raw pieces per taxonomy, counted
     *    before sanitize_title() and de-duplication (`per-group`). A literal
     *    `+` always separates pieces; `%2b` joining does not apply without
     *    `%`. Pieces of one taxonomy add up across segments, so one
     *    segment over the cap is enough.
     *  - A position whose group has no taxonomy is not counted by the guard,
     *    so per_group then only looks at the other positions - or at a
     *    position past G, which is a `segments` reject anyway.
     *  - per_group looks for the over-cap segment up to position G+3 only
     *    (bounded repetition, no JIT stack per segment); past that, and
     *    without `%`, `segments` matches anyway - with `%`, nothing does.
     *
     * Both null when strict URLs are off, the guard's verdict is filtered
     * (code may decide per request), there are no groups, no bases or no
     * endpoint, or one base lies below another base's endpoint (a URL could
     * then be captured by either base's rules, positions shifted). per_group
     * is also null when N is 0 (unlimited).
     *
     * `request_line` builds the same grammar for Apache's and OpenLiteSpeed's
     * `%{THE_REQUEST}`, "METHOD URI PROTOCOL": anchored `^[A-Z]+ ` ...
     * `(?:\s|$)`, every negated class also excludes whitespace and the query
     * is `(?:\?\S*)?`. The whitespace matters - "HTTP/1.1" contains a `/`, so
     * a class that may cross the space counts "jpkpf-fragment HTTP/" as one
     * more segment, and /blog/filter/a/b/page/2/jpkpf-fragment would be
     * rejected although the plugin serves it. A URI cannot contain whitespace
     * in a request line, so on every real request both forms decide the same.
     * This form is PCRE-only (`\s`, `\S`); only the URI form is for RE2/Rust.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs  As returned by jpkcom_postfilter_server_rule_inputs().
     * @param string               $subject `uri` (raw request URI, default) or
     *                                      `request_line` (THE_REQUEST).
     * @return array{per_group: ?string, segments: ?string}
     */
    function jpkcom_postfilter_server_reject_patterns( array $inputs, string $subject = 'uri' ): array {
        $none = [ 'per_group' => null, 'segments' => null ];

        $groups   = (int) ( $inputs['groups'] ?? 0 );
        $max      = (int) ( $inputs['max_per_group'] ?? 0 );
        $endpoint = (string) ( $inputs['endpoint'] ?? '' );
        $bases    = array_values( array_map( 'strval', (array) ( $inputs['bases'] ?? [] ) ) );

        if ( empty( $inputs['strict'] ) || ! empty( $inputs['verdict_filtered'] ) || $groups <= 0 || $bases === [] || $endpoint === '' ) {
            return $none;
        }

        foreach ( $bases as $outer ) {
            foreach ( $bases as $inner ) {
                if ( $inner !== $outer && str_starts_with( $inner, $outer . $endpoint . '/' ) ) {
                    return $none;
                }
            }
        }

        $alternatives = array_map(
            static fn( string $base ): string => jpkcom_postfilter_server_regex_quote( s: ltrim( $base, '/' ) ),
            $bases
        );

        $line  = $subject === 'request_line';
        $ws    = $line ? '\s' : '';               // added to every negated class
        $start = $line ? '^[A-Z]+ ' : '^';
        $query = $line ? '(?:\?\S*)?' : '(?:\?.*)?';
        $end   = $line ? '(?:\s|$)' : '$';

        $seg    = '[^/?%' . $ws . ']+/+';          // one non-empty segment and its slashes
        $piece  = '[^/?%+' . $ws . ']+';           // one non-empty piece
        $prefix = $start . '/+(?:' . implode( '|', $alternatives ) . ')' . jpkcom_postfilter_server_regex_quote( s: $endpoint ) . '/+';
        $tail   = '[^?%' . $ws . ']*' . $query . $end; // rest of a %-free path, any query

        // Linear time on purpose: every branch is anchored on something that
        // can only match in one place (the first `+` of segment G, one
        // character of segment G+4), so a `%` near the end of a long path
        // fails in one pass instead of once per earlier split point.
        $segments = $prefix
            . '(?:(?:' . $seg . '){' . $groups . '}[^/?%+' . $ws . ']*\+'
            . '|(?:' . $seg . '){' . ( $groups + 3 ) . '}[^/?%' . $ws . '])'
            . $tail;

        if ( $max <= 0 ) {
            return [ 'per_group' => null, 'segments' => $segments ];
        }

        // A segment with at most N non-empty pieces, and its slashes. Skipping
        // exactly these leaves one candidate - the first segment over the cap.
        // `(?:any segment)*` instead would retry the tail scan from every
        // segment: quadratic, 12 ms for 2000 characters without a JIT.
        $within = '';
        for ( $i = 1; $i < $max; $i++ ) {
            $within = '(?:\\++' . $piece . $within . ')?';
        }
        $w = '(?:\\++|\\+*' . $piece . $within . '\\+*)/+';

        // Every repetition of a segment is bounded (attack A4): with nginx's
        // `pcre_jit on` an unbounded `(?:...)*` used JIT stack per segment and
        // overflowed PCRE2's default 32 KiB at ~1.65 KB of '+/' - a 500 instead
        // of the reject. The over-cap segment is looked for up to position
        // G+3; a URL with more segments is a `segments` reject anyway, and
        // where a `%` prevents that, the bounded pattern simply does not match
        // (looser).
        $skip = array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) );

        if ( $skip === [] ) {
            $positions = '(?:' . $w . '){0,' . ( $groups + 3 ) . '}';
        } else {
            // Position-exact, built from the end, one run of positions with a
            // taxonomy at a time. In a run of k such positions the over-cap
            // segment may come at any of them - after 0 to k-1 segments within
            // the cap - or all k are within the cap and the next position
            // follows. A position without taxonomy is passed whatever it
            // holds. Each segment has exactly one way through, so a `%` near
            // the end costs one tail scan however many groups there are; one
            // alternative per position scanned the tail G times (attack A5).
            // Past G up to three more segments within the cap: here when the
            // last position has no taxonomy, else as part of the last run.
            $positions = in_array( $groups - 1, $skip, true ) ? '(?:' . $w . '){0,3}' : '';
            $position  = $groups;
            while ( $position > 0 ) {
                if ( in_array( $position - 1, $skip, true ) ) {
                    $positions = $seg . $positions;
                    $position--;
                    continue;
                }
                $k = 0;
                while ( $position - $k > 0 && ! in_array( $position - $k - 1, $skip, true ) ) {
                    $k++;
                }
                if ( $position === $groups ) {
                    $positions = '(?:' . $w . '){0,' . ( $k + 3 ) . '}';
                } elseif ( $k === 1 ) {
                    $positions = '(?:' . $w . $positions . ')?';
                } else {
                    $positions = '(?:(?:' . $w . '){' . $k . '}' . $positions . '|(?:' . $w . '){0,' . ( $k - 1 ) . '})';
                }
                $position -= $k;
            }
        }

        // N+1 pieces; the last one runs to a `+`, `/`, `?` or the end, so the
        // tail cannot re-split it.
        $per_group = $prefix . $positions
            . '\+*' . $piece . '(?:\++' . $piece . '){' . $max . '}'
            . '(?:(?:\+|/)[^?%' . $ws . ']*)?' . $query . $end;

        return [ 'per_group' => $per_group, 'segments' => $segments ];
    }
}
