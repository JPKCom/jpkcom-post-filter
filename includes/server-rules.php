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


if ( ! function_exists( function: 'jpkcom_postfilter_server_renderer_version' ) ) {
    /**
     * Version of the code that turns rule inputs into server rules
     *
     * Folded into the rule hash when it is not empty. The hash otherwise
     * covers the inputs only, so a release that changes what a renderer
     * writes for the same inputs - a snippet, the managed .htaccess block,
     * a pattern - without bumping this would leave every deployed rule
     * reading as current: the status check says "active", and the managed
     * block is never resynced (admin_init and the version-change sync both
     * compare hashes).
     *
     * **The next renderer change must set this** (the first one to '2', then
     * '3', ...). Empty means "the renderers of 1.6.0 to 1.7.1", whose reject
     * rules and managed block for a layout with nothing dropped never
     * changed, so the hashes of those rules stay as they were. Setting it
     * marks every deployed hand-pasted rule "stale" - which is the point when
     * the rule did change.
     *
     * Rate-limit parts are outside the hash, like their limits: the status
     * check never probes them, and a change there would mark every deployed
     * reject "stale" for nothing. 1.7.1's `ctl:auditEngine=Off` on the
     * ModSecurity 429 (T6-a) therefore left this empty.
     *
     * @since 1.7.1
     *
     * @return string '' until a renderer changes.
     */
    function jpkcom_postfilter_server_renderer_version(): string {
        return '';
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
     * Two values are appended only when they are not empty, so the hash of
     * every other rule stays byte-identical to 1.6.0:
     *
     * - `dropped` (R161-4): the patterns the size and compile guard leaves
     *   out (jpkcom_postfilter_server_patterns_dropped(), key => reason).
     *   1.6.0 had no guard and wrote those patterns, so the same inputs
     *   stood for another rule; without this a deployed 1.6.0 rule read as
     *   "active" and a managed 1.6.0 block was never rewritten.
     * - `renderer` (T9-c): jpkcom_postfilter_server_renderer_version().
     *
     * @since 1.6.0
     * @since 1.7.1 Folds in the dropped patterns and the renderer version, each only when not empty.
     *
     * @param array<string, mixed>       $inputs   As returned by jpkcom_postfilter_server_rule_inputs().
     * @param array<string, string>|null $dropped  Dropped patterns; null to decide them from `$inputs`.
     * @param string|null                $renderer Renderer version; null for the current one.
     * @return string First 12 hex characters of sha256( json_encode ).
     */
    function jpkcom_postfilter_server_rule_hash( array $inputs, ?array $dropped = null, ?string $renderer = null ): string {
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

        $dropped ??= jpkcom_postfilter_server_patterns_dropped( inputs: $inputs );
        if ( $dropped !== [] ) {
            $dropped = array_map( 'strval', $dropped );
            ksort( $dropped, SORT_STRING );
            $data['dropped'] = $dropped;
        }

        $renderer ??= jpkcom_postfilter_server_renderer_version();
        if ( $renderer !== '' ) {
            $data['renderer'] = $renderer;
        }

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


if ( ! function_exists( function: 'jpkcom_postfilter_server_reject_patterns_unchecked' ) ) {
    /**
     * The two patterns a server may reject a filter URL on, before the size
     * and compile guard
     *
     * Every caller that hands a pattern to a server goes through
     * jpkcom_postfilter_server_reject_patterns(), which drops what a server
     * could not load. This builder is public for tests and diagnostics only.
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
     * @since 1.6.0 as jpkcom_postfilter_server_reject_patterns(); 1.6.1 unchecked builder
     *
     * @param array<string, mixed> $inputs  As returned by jpkcom_postfilter_server_rule_inputs().
     * @param string               $subject `uri` (raw request URI, default) or
     *                                      `request_line` (THE_REQUEST).
     * @return array{per_group: ?string, segments: ?string}
     */
    function jpkcom_postfilter_server_reject_patterns_unchecked( array $inputs, string $subject = 'uri' ): array {
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


// ---------------------------------------------------------------------------
// Size and compile guard (1.6.1)
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_pattern_limits' ) ) {
    /**
     * What a reject pattern must stay within to load on every server
     *
     * The bounded per_group form grows with G and N. A 1.6.0 review found
     * layouts (many filter groups, a high cap, positions without taxonomy)
     * whose pattern a server cannot load - and a server that cannot load a
     * rule fails loudly (nginx -t, a 500 for the whole tree from .htaccess)
     * or silently (Ferron 3 never matches). Measured for 1.6.1:
     *
     *  - `length` 4000 characters, for the longer of the two forms (the
     *    THE_REQUEST form; the URI form is always shorter). nginx reads a
     *    quoted parameter of at most 4094 characters (NGX_CONF_BUFFER 4096;
     *    nginx 1.30: 4094 accepted, 4095 "too long parameter") - the nginx
     *    and ISPConfig snippets put the URI form into one such parameter.
     *    Apache 2.4 answers 500 for the whole directory tree once an
     *    .htaccess line reaches 8192 bytes (8191 accepted); the request-line
     *    form sits in `RewriteCond %{THE_REQUEST} "…"`, so 4000 leaves half
     *    of that unused. Caddy doubles every backslash inside its CEL
     *    string; the Caddyfile has no token length limit.
     *  - `repeat` 1000: Go's regexp (Caddy) refuses a repetition count over
     *    1000 and nested counted repetitions whose product exceeds 1000.
     *  - `depth` 200: Rust's regex (Ferron 3) refuses nesting deeper than 250
     *    (Ferron 3.0.0-rc.10: 124 nested `(?:…)?` matched, 125 never did -
     *    without any error). 50 of margin for constructs not calibrated one
     *    by one; Go's own nesting limit (1000) is further away.
     *
     * @since 1.6.1
     *
     * @return array{length: int, repeat: int, depth: int}
     */
    function jpkcom_postfilter_server_pattern_limits(): array {
        return [ 'length' => 4000, 'repeat' => 1000, 'depth' => 200 ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_regex_shape' ) ) {
    /**
     * Nesting depth and repetition validity of a pattern in the server grammar
     *
     * Parses the subset jpkcom_postfilter_server_reject_patterns_unchecked()
     * emits (literals, escapes, bracket classes, groups, alternation,
     * `* + ?` and `{n}`, `{n,}`, `{n,m}`) into a tree and reports:
     *
     *  - `depth`: the nesting depth as Rust's regex-syntax counts it against
     *    its nest limit - every group, repetition, bracket class, class with
     *    more than one item, alternation and concatenation of two or more
     *    items is one level; literals, escapes and assertions are none.
     *  - `repeat_ok`: Go's regexp rule (regexp/syntax repeatIsValid): no
     *    count above 1000, and along every path the counted repetitions
     *    (`*`, `+`, `?` do not count) multiply to at most 1000.
     *
     * @since 1.6.1
     *
     * @param string $re Pattern without delimiters.
     * @return array{depth: int, repeat_ok: bool}
     */
    function jpkcom_postfilter_server_regex_shape( string $re ): array {
        $i   = 0;
        $len = strlen( $re );
        $max = jpkcom_postfilter_server_pattern_limits()['repeat'];

        $alternation = null;
        $alternation = static function () use ( &$alternation, &$i, $len, $re ): array {
            $branches = [];
            $items    = [];
            while ( $i < $len && $re[ $i ] !== ')' ) {
                $ch = $re[ $i ];
                if ( $ch === '|' ) {
                    $branches[] = $items;
                    $items      = [];
                    $i++;
                    continue;
                }
                if ( $ch === '(' ) {
                    $i += substr( $re, $i + 1, 2 ) === '?:' ? 3 : 1;
                    $atom = [ 'k' => 'group', 'items' => [ $alternation() ] ];
                    $i++; // the closing parenthesis
                } elseif ( $ch === '[' ) {
                    $i++;
                    $count = 0;
                    if ( $i < $len && $re[ $i ] === '^' ) {
                        $i++;
                    }
                    if ( $i < $len && $re[ $i ] === ']' ) {
                        $count++;
                        $i++;
                    }
                    while ( $i < $len && $re[ $i ] !== ']' ) {
                        $i += $re[ $i ] === '\\' ? 2 : 1;
                        $count++;
                    }
                    $i++;
                    $atom = [ 'k' => 'class', 'n' => $count ];
                } else {
                    $i   += $ch === '\\' ? 2 : 1;
                    $atom = [ 'k' => 'leaf' ];
                }
                while ( $i < $len ) {
                    if ( in_array( $re[ $i ], [ '*', '+', '?' ], true ) ) {
                        $i++;
                        $atom = [ 'k' => 'rep', 'counted' => false, 'items' => [ $atom ] ];
                        continue;
                    }
                    if ( $re[ $i ] === '{' && preg_match( '/\G\{([0-9]+)(,([0-9]*))?\}/', $re, $m, 0, $i ) === 1 ) {
                        $i   += strlen( $m[0] );
                        $min  = (int) $m[1];
                        $high = ! isset( $m[2] ) || $m[2] === '' ? $min : ( ( $m[3] ?? '' ) === '' ? null : (int) $m[3] );
                        $atom = [ 'k' => 'rep', 'counted' => true, 'min' => $min, 'max' => $high, 'items' => [ $atom ] ];
                        continue;
                    }
                    break;
                }
                $items[] = $atom;
            }
            $branches[] = $items;

            $nodes = array_map(
                static fn( array $list ): array => count( $list ) === 1 ? $list[0] : [ 'k' => $list === [] ? 'leaf' : 'concat', 'items' => $list ],
                $branches
            );

            return count( $nodes ) === 1 ? $nodes[0] : [ 'k' => 'alt', 'items' => $nodes ];
        };

        $tree = $alternation();

        $depth = null;
        $depth = static function ( array $node ) use ( &$depth ): int {
            return match ( $node['k'] ) {
                'leaf'  => 0,
                'class' => $node['n'] > 1 ? 2 : 1,
                default => 1 + max( array_map( $depth, $node['items'] ) ),
            };
        };

        $valid = null;
        $valid = static function ( array $node, int $budget ) use ( &$valid, $max ): bool {
            if ( $node['k'] === 'rep' && $node['counted'] ) {
                if ( $node['min'] > $max || ( $node['max'] !== null && $node['max'] > $max ) ) {
                    return false;
                }
                $m = $node['max'] ?? -1;
                if ( $m === 0 ) {
                    return true;
                }
                if ( $m < 0 ) {
                    $m = $node['min'];
                }
                if ( $m > $budget ) {
                    return false;
                }
                if ( $m > 0 ) {
                    $budget = intdiv( $budget, $m );
                }
            }
            foreach ( $node['items'] ?? [] as $child ) {
                if ( ! $valid( $child, $budget ) ) {
                    return false;
                }
            }
            return true;
        };

        return [ 'depth' => $depth( $tree ), 'repeat_ok' => $valid( $tree, $max ) ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_pattern_problem' ) ) {
    /**
     * Why a server could not load this pattern, if it could not
     *
     * Checked in this order: `length` (either form over the length limit),
     * `pcre` (PHP's PCRE2 cannot compile one of the forms - nginx, Apache
     * and OpenLiteSpeed use the same library, normally with the same link
     * size), `repeat` and `nesting` (the URI form, the only one Go's and
     * Rust's engines get, against jpkcom_postfilter_server_pattern_limits()).
     *
     * @since 1.6.1
     *
     * @param string $uri  The pattern in the URI form.
     * @param string $line The same pattern in the THE_REQUEST form.
     * @return string|null `length`, `pcre`, `repeat`, `nesting`, or null when it loads everywhere.
     */
    function jpkcom_postfilter_server_pattern_problem( string $uri, string $line ): ?string {
        $limits = jpkcom_postfilter_server_pattern_limits();

        if ( max( strlen( $uri ), strlen( $line ) ) > $limits['length'] ) {
            return 'length';
        }

        foreach ( [ $uri, $line ] as $re ) {
            // \x01 as delimiter: no base or endpoint in a pattern contains it
            // (and if one did, the compile error would drop the pattern -
            // looser, never stricter).
            if ( @preg_match( "\x01" . $re . "\x01", '' ) === false ) {
                return 'pcre';
            }
        }

        $shape = jpkcom_postfilter_server_regex_shape( re: $uri );
        if ( ! $shape['repeat_ok'] ) {
            return 'repeat';
        }
        if ( $shape['depth'] > $limits['depth'] ) {
            return 'nesting';
        }

        return null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_patterns_dropped' ) ) {
    /**
     * The reject patterns left out because a server could not load them
     *
     * Decided over both forms at once, so every server tab, the curl
     * commands, the status check and the managed .htaccess block agree on
     * which rule exists. A pattern that is dropped is looser, never
     * stricter: the plugin still rejects those URLs in PHP. per_group is
     * also dropped when segments is, so a URL over both caps never gets
     * another reason from the server than from PHP. Its reason is then its
     * own problem, or `with-segments` when its own pattern would load.
     *
     * @since 1.6.1
     * @since 1.7.1 Reason `with-segments`.
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return array<string, string> Pattern key (`per_group`, `segments`) => reason
     *                               (see jpkcom_postfilter_server_pattern_problem(), plus
     *                               `with-segments`); empty when nothing is dropped.
     */
    function jpkcom_postfilter_server_patterns_dropped( array $inputs ): array {
        $uri  = jpkcom_postfilter_server_reject_patterns_unchecked( inputs: $inputs );
        $line = jpkcom_postfilter_server_reject_patterns_unchecked( inputs: $inputs, subject: 'request_line' );
        $out  = [];

        foreach ( [ 'segments', 'per_group' ] as $key ) {
            if ( $uri[ $key ] === null || $line[ $key ] === null ) {
                continue;
            }
            $problem = jpkcom_postfilter_server_pattern_problem( uri: $uri[ $key ], line: $line[ $key ] );
            // The guard checks segments before per_group, so per_group goes
            // with it. Its own pattern is loadable: say so instead of
            // borrowing the segments reason (1.7.1).
            if ( $problem === null && $key === 'per_group' && isset( $out['segments'] ) ) {
                $problem = 'with-segments';
            }
            if ( $problem !== null ) {
                $out[ $key ] = $problem;
            }
        }

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_reject_patterns' ) ) {
    /**
     * The two patterns a server may reject a filter URL on
     *
     * jpkcom_postfilter_server_reject_patterns_unchecked() (where the
     * grammar and why it is never stricter than the plugin are documented),
     * minus every pattern jpkcom_postfilter_server_patterns_dropped() names:
     * one that a server could not load is null, as if the settings produced
     * none. Every snippet, curl command, status check and the managed
     * .htaccess block take their patterns from here.
     *
     * @since 1.6.0
     * @since 1.6.1 Drops patterns no server could load (size, PCRE compile, RE2 repetition, nesting).
     *
     * @param array<string, mixed> $inputs  As returned by jpkcom_postfilter_server_rule_inputs().
     * @param string               $subject `uri` (raw request URI, default) or
     *                                      `request_line` (THE_REQUEST).
     * @return array{per_group: ?string, segments: ?string}
     */
    function jpkcom_postfilter_server_reject_patterns( array $inputs, string $subject = 'uri' ): array {
        $patterns = jpkcom_postfilter_server_reject_patterns_unchecked( inputs: $inputs, subject: $subject );

        if ( $patterns['per_group'] === null && $patterns['segments'] === null ) {
            return $patterns;
        }

        foreach ( array_keys( jpkcom_postfilter_server_patterns_dropped( inputs: $inputs ) ) as $key ) {
            $patterns[ $key ] = null;
        }

        return $patterns;
    }
}
