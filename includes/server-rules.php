<?php
/**
 * Server Rules – what a web server may reject before PHP runs
 *
 * No hooks are registered here: the inputs are read from WordPress on demand,
 * everything else is pure, so tests/test-server-rules.php can fuzz the
 * patterns against the real request guard without a server.
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
 * groups, `* + ? {n} {n,}`, alternation and anchors. No lookaround, no
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
     * as paths including the home path. A base the plugin does not actually
     * route only makes the rule cover a path WordPress would 404 anyway; a
     * missing one only makes the rule looser.
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

        $page_for_posts = (int) get_option( 'page_for_posts' );
        $bases          = [];

        foreach ( jpkcom_postfilter_routable_post_types() as $post_type ) {
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
            $within = '(?:\++' . $piece . $within . ')?';
        }
        $skip_within = '(?:(?:\++|\+*' . $piece . $within . '\+*)/+)*';

        $skip = array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) );

        if ( $skip === [] ) {
            $positions = $skip_within;
        } else {
            $alts = [];
            for ( $position = 0; $position < $groups; $position++ ) {
                if ( ! in_array( $position, $skip, true ) ) {
                    $alts[] = '(?:' . $seg . '){' . $position . '}';
                }
            }
            // Past G every segment is a `segments` reject anyway.
            $alts[]    = '(?:' . $seg . '){' . $groups . '}' . $skip_within;
            $positions = '(?:' . implode( '|', $alts ) . ')';
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
// Rendering helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_safe_bases' ) ) {
    /**
     * The bases a snippet may contain
     *
     * A base ends up in nginx strings and location prefixes, Apache quoted
     * arguments, shell commands and HTML. WordPress paths are normally plain
     * slugs, but nothing forbids a quote, space, brace, `$`, `;` or newline in
     * a custom rewrite slug or home path - and browsers percent-encode most of
     * those, so a raw-path rule would not match them anyway. Such a base is
     * left out of the snippets (looser, never stricter). Allowed: RFC 3986
     * unreserved characters plus `! * + , = : @`.
     *
     * @since 1.6.0
     *
     * @param array<int, mixed> $bases Base paths.
     * @return string[]
     */
    function jpkcom_postfilter_server_safe_bases( array $bases ): array {
        $out = [];
        foreach ( $bases as $base ) {
            $base = is_scalar( $base ) ? (string) $base : '';
            if ( preg_match( '#^/(?:[A-Za-z0-9._~!*+,=:@-]+/)*$#D', $base ) === 1 ) {
                $out[] = $base;
            }
        }
        return array_values( array_unique( $out ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_render_inputs' ) ) {
    /**
     * Inputs with only the bases a snippet may contain
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return array<string, mixed>
     */
    function jpkcom_postfilter_server_render_inputs( array $inputs ): array {
        $inputs['bases'] = jpkcom_postfilter_server_safe_bases( bases: (array) ( $inputs['bases'] ?? [] ) );
        return $inputs;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_render_patterns' ) ) {
    /**
     * The reject patterns a snippet contains
     *
     * Built from the safe bases only, but null wherever the patterns over ALL
     * bases are null: leaving out a base must not lift the nested-base check
     * of jpkcom_postfilter_server_reject_patterns() - a dropped base that lies
     * below another base's endpoint still shifts that base's positions.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs  As returned by jpkcom_postfilter_server_rule_inputs().
     * @param string               $subject `uri` or `request_line`.
     * @return array{per_group: ?string, segments: ?string}
     */
    function jpkcom_postfilter_server_render_patterns( array $inputs, string $subject = 'uri' ): array {
        $all  = jpkcom_postfilter_server_reject_patterns( inputs: $inputs );
        $safe = jpkcom_postfilter_server_reject_patterns( inputs: jpkcom_postfilter_server_render_inputs( inputs: $inputs ), subject: $subject );

        foreach ( $safe as $key => $pattern ) {
            if ( $all[ $key ] === null ) {
                $safe[ $key ] = null;
            }
        }

        return $safe;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_reasons' ) ) {
    /**
     * Reject reasons in the guard's order, with their pattern
     *
     * The guard checks segments before per-group (url-policy.php), so a URL
     * over both caps gets the same reason from the server as from PHP.
     *
     * @since 1.6.0
     *
     * @param array{per_group: ?string, segments: ?string} $patterns Reject patterns.
     * @return array<string, string> reason (`segments`, `per-group`) => pattern
     */
    function jpkcom_postfilter_server_reasons( array $patterns ): array {
        $out = [];
        if ( is_string( $patterns['segments'] ?? null ) ) {
            $out['segments'] = $patterns['segments'];
        }
        if ( is_string( $patterns['per_group'] ?? null ) ) {
            $out['per-group'] = $patterns['per_group'];
        }
        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_reject_status' ) ) {
    /**
     * The reject status a snippet uses: 404, anything else is 410
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return int
     */
    function jpkcom_postfilter_server_reject_status( array $inputs ): int {
        return (int) ( $inputs['reject_status'] ?? 410 ) === 404 ? 404 : 410;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_hash_of' ) ) {
    /**
     * The rule hash of the inputs, recomputed when missing or malformed
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string 12 hex characters.
     */
    function jpkcom_postfilter_server_hash_of( array $inputs ): string {
        $hash = is_string( $inputs['hash'] ?? null ) ? $inputs['hash'] : '';
        if ( preg_match( '/^[0-9a-f]{12}$/D', $hash ) !== 1 ) {
            $hash = jpkcom_postfilter_server_rule_hash( inputs: $inputs );
        }
        return $hash;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_wrap' ) ) {
    /**
     * Code lines between the `# >>> … rule <hash>` / `# <<<` comment lines
     *
     * The opening line names plugin version and rule hash, so an admin can
     * see which settings a pasted block was generated from.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param string[]             $lines  Code lines.
     * @return string
     */
    function jpkcom_postfilter_server_wrap( array $inputs, array $lines ): string {
        $version = defined( 'JPKCOM_POSTFILTER_VERSION' ) ? (string) JPKCOM_POSTFILTER_VERSION : '';
        $version = (string) preg_replace( '/[^0-9A-Za-z.+-]/', '', $version );
        $open    = '# >>> jpkcom-post-filter ' . $version . ' rule ' . jpkcom_postfilter_server_hash_of( inputs: $inputs );

        return implode( "\n", array_merge( [ $open ], $lines, [ '# <<< jpkcom-post-filter' ] ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_html_text' ) ) {
    /**
     * Text for the reject body: HTML-escaped and inert in every config syntax
     *
     * The body is pasted into nginx `return '…'` (variables `$x`, escapes
     * `\`), ISPConfig's brace counter (`{ }`), Apache's quoted ErrorDocument
     * (expressions `%{…}`) and the Caddy/Ferron strings. A translation must not
     * be able to reach any of them, so these characters become entities, and
     * control characters become a space (the body is one line).
     *
     * @since 1.6.0
     *
     * @param string $text Plain text.
     * @return string
     */
    function jpkcom_postfilter_server_html_text( string $text ): string {
        $text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
        $text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        return strtr( $text, [ '{' => '&#123;', '}' => '&#125;', '$' => '&#36;', '\\' => '&#92;', '%' => '&#37;' ] );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_plain_text' ) ) {
    /**
     * A short plain-text body (429): letters, digits, space and `. , : ! ? ( ) -`
     *
     * @since 1.6.0
     *
     * @param string $text Plain text.
     * @return string
     */
    function jpkcom_postfilter_server_plain_text( string $text ): string {
        return trim( (string) preg_replace( '/[^\p{L}\p{N} .,:!?()-]+/u', '', $text ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_searchbots' ) ) {
    /**
     * User-Agent alternation for the search engines' own rate-limit bucket
     *
     * A bot that fakes one of these only reaches that small bucket.
     *
     * @since 1.6.0
     *
     * @return string Lower-case, `|`-separated, to be matched case-insensitively.
     */
    function jpkcom_postfilter_server_searchbots(): string {
        return 'googlebot|bingbot|applebot|duckduckbot|yandexbot';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_scope_regex' ) ) {
    /**
     * Rate-limit scope: a request path below any safe base's endpoint
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string|null `^/(?:blog/|news/)filter/`, or null without bases or endpoint.
     */
    function jpkcom_postfilter_server_scope_regex( array $inputs ): ?string {
        $bases    = jpkcom_postfilter_server_safe_bases( bases: (array) ( $inputs['bases'] ?? [] ) );
        $endpoint = (string) ( $inputs['endpoint'] ?? '' );

        if ( $bases === [] || preg_match( '/^[a-z0-9_-]+$/D', $endpoint ) !== 1 ) {
            return null;
        }

        $alternatives = array_map(
            static fn( string $base ): string => jpkcom_postfilter_server_regex_quote( s: ltrim( $base, '/' ) ),
            $bases
        );

        return '^/(?:' . implode( '|', $alternatives ) . ')' . $endpoint . '/';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_limits' ) ) {
    /**
     * Rate limits as integers >= 1, defaults (8 workers, 0.4 s) for missing keys
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $limits As returned by jpkcom_postfilter_rate_limits().
     * @return array<string, int>
     */
    function jpkcom_postfilter_server_limits( array $limits ): array {
        $out = jpkcom_postfilter_rate_limits( workers: 8, render_seconds: 0.4 );
        foreach ( $out as $key => $default ) {
            if ( isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ) {
                $out[ $key ] = max( 1, (int) $limits[ $key ] );
            }
        }
        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippet' ) ) {
    /**
     * One snippet entry
     *
     * @since 1.6.0
     *
     * @param string $id      Stable id, prefixed by the server.
     * @param string $title   Translated title.
     * @param string $context Translated installation text.
     * @param string $code    Config text, '' for a hint without code.
     * @return array{id: string, title: string, context: string, code: string}
     */
    function jpkcom_postfilter_server_snippet( string $id, string $title, string $context, string $code ): array {
        return [ 'id' => $id, 'title' => $title, 'context' => $context, 'code' => $code ];
    }
}


// ---------------------------------------------------------------------------
// Rate limits and the reject response
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_rate_limits' ) ) {
    /**
     * The rate-limit numbers of every server snippet, from one formula
     *
     * Inputs are what an admin knows: W = PHP workers (1-256), t = render time
     * of a filter page in seconds (0.05-10).
     *
     *   conn          = max(1, floor(3W/8))     at most 3/8 of the workers render
     *                                           cookie-less filter pages at once,
     *                                           the rest stay free for the site
     *   rate          = max(1, round(0.4 conn/t)) requests/s site-wide: keeps those
     *                                           workers 40 % busy on average
     *   burst         = 2 rate
     *   ip_per_minute = 30, ip_burst = 10       a visitor without cookie gets 11
     *                                           quick clicks, then one every 2 s
     *   bot_rate      = max(1, round(2 rate/3)), bot_burst = 20
     *                                           search engines' own bucket
     *   retry_after   = 10 s
     *
     * Anchor (spec 6, research report 3 §3, production: 8 workers, 0.4 s):
     * conn 3, 3 r/s burst 6, 30 r/min burst 10 per IP, search engines 2 r/s
     * burst 20.
     *
     * @since 1.6.0
     *
     * @param int   $workers        PHP workers (FPM pm.max_children or equivalent).
     * @param float $render_seconds Render time of one filter page.
     * @return array{conn: int, rate: int, burst: int, ip_per_minute: int, ip_burst: int, bot_rate: int, bot_burst: int, retry_after: int}
     */
    function jpkcom_postfilter_rate_limits( int $workers, float $render_seconds ): array {
        $workers = max( 1, min( 256, $workers ) );
        $seconds = is_nan( $render_seconds ) ? 0.4 : max( 0.05, min( 10.0, $render_seconds ) );

        $conn = max( 1, intdiv( 3 * $workers, 8 ) );
        $rate = max( 1, (int) round( 0.4 * $conn / $seconds ) );

        return [
            'conn'          => $conn,
            'rate'          => $rate,
            'burst'         => 2 * $rate,
            'ip_per_minute' => 30,
            'ip_burst'      => 10,
            'bot_rate'      => max( 1, (int) round( 2 * $rate / 3 ) ),
            'bot_burst'     => 20,
            'retry_after'   => 10,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_reject_body' ) ) {
    /**
     * The HTML body a server sends with its reject
     *
     * In the site language (`get_bloginfo( 'language' )`, the plugin's own
     * strings), noindex, a link to the first base. One line, and free of
     * `{ } ' $ \ %` (jpkcom_postfilter_server_html_text()), so it can be
     * pasted into every server's config syntax as it is.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string
     */
    function jpkcom_postfilter_server_reject_body( array $inputs ): string {
        $status = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $lang   = (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) get_bloginfo( 'language' ) );
        $lang   = $lang !== '' ? $lang : 'en';
        $bases  = jpkcom_postfilter_server_safe_bases( bases: (array) ( $inputs['bases'] ?? [] ) );
        $href   = $bases[0] ?? '/';
        $text   = jpkcom_postfilter_server_html_text( text: __( 'This filter combination is not available.', 'jpkcom-post-filter' ) );
        $link   = jpkcom_postfilter_server_html_text( text: __( 'Show all posts', 'jpkcom-post-filter' ) );

        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . $status . '</title></head>'
            . '<body><p>' . $text . '</p><p><a href="' . jpkcom_postfilter_server_html_text( text: $href ) . '">' . $link . '</a></p></body></html>';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_probe_paths' ) ) {
    /**
     * Synthetic request paths that show whether a server rule is active
     *
     * Slugs `jpkpf-probe-1…` under the first base. `per_group`: N+1 slugs at
     * the first position that has a taxonomy - matched by the per_group
     * pattern only. `segments`: G+4 single slugs - matched by the segments
     * pattern only. `within`: paths the plugin serves (N slugs; G segments;
     * G segments plus page and fragment suffix), which no rule may match.
     * The plugin answers the over-cap paths with its own 410 (`per-group`,
     * `segments`; caps are checked before unknown slugs) and the others with
     * a 301 to the archive, so only the X-Jpkpf-Reject value tells server
     * and PHP apart.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return array{per_group: ?string, segments: ?string, within: string[]}
     */
    function jpkcom_postfilter_server_probe_paths( array $inputs ): array {
        $patterns = jpkcom_postfilter_server_reject_patterns( inputs: $inputs );
        $bases    = array_values( (array) ( $inputs['bases'] ?? [] ) );
        $base     = is_string( $bases[0] ?? null ) ? $bases[0] : '/';
        $lead     = $base . (string) ( $inputs['endpoint'] ?? '' ) . '/';
        $groups   = max( 1, (int) ( $inputs['groups'] ?? 1 ) );
        $max      = max( 0, (int) ( $inputs['max_per_group'] ?? 0 ) );
        $skip     = array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) );
        $fragment = (string) ( $inputs['fragment'] ?? '' );
        $fragment = $fragment !== '' ? $fragment : 'jpkpf-fragment';

        $slugs = static fn( int $count, string $glue ): string => implode(
            $glue,
            array_map( static fn( int $i ): string => 'jpkpf-probe-' . $i, range( 1, max( 1, $count ) ) )
        );

        $position = null;
        for ( $p = 0; $p < $groups; $p++ ) {
            if ( ! in_array( $p, $skip, true ) ) {
                $position = $p;
                break;
            }
        }
        $pad = str_repeat( '_/', (int) $position );

        $per_group = null;
        if ( $patterns['per_group'] !== null && $position !== null ) {
            $per_group = $lead . $pad . $slugs( $max + 1, '+' ) . '/';
        }

        $segments = null;
        if ( $patterns['segments'] !== null ) {
            $segments = $lead . $slugs( $groups + 4, '/' ) . '/';
        }

        $per_segment = $max > 0 ? $max : 3;

        return [
            'per_group' => $per_group,
            'segments'  => $segments,
            'within'    => [
                $lead . $pad . $slugs( $per_segment, '+' ) . '/',
                $lead . $slugs( $groups, '/' ) . '/',
                $lead . $slugs( $groups, '/' ) . '/page/2/' . $fragment . '/',
            ],
        ];
    }
}


// ---------------------------------------------------------------------------
// Snippets: nginx, Apache, .htaccess, OpenLiteSpeed (PCRE)
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_nginx' ) ) {
    /**
     * nginx snippets, plain server block or ISPConfig's "nginx Directives"
     *
     * Reject: a server-level `if ($request_uri ~ …)` (raw URI, server rewrite
     * phase, before any location) rewrites to an internal exact location,
     * because `add_header` is not allowed in a server-level `if`. `return
     * <status> '<body>'` sends the body itself, so no `error_page` (and none of
     * ISPConfig's error documents) runs. Rejected requests therefore never
     * reach the rate-limit location either.
     *
     * Rate limit: maps and zones only work in the http context (root); the
     * per-base location carries the limits and hands valid requests on with
     * the usual WordPress try_files. 429, not 503: ISPConfig's "Own
     * Error-Documents" replace 503 together with its headers.
     *
     * ISPConfig (research report 3 §1-§3): no `location /` (its merge replaces
     * a location it already has), no line starting with `#` (it strips them),
     * no brace inside a directive line (it counts braces per line), and the
     * template's own `location = /robots.txt` is extended with `##merge##`.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs    Rule inputs.
     * @param array<string, int>   $limits    Normalised rate limits.
     * @param bool                 $ispconfig ISPConfig variant.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_nginx( array $inputs, array $limits, bool $ispconfig ): array {
        $reasons = jpkcom_postfilter_server_reasons( patterns: jpkcom_postfilter_server_render_patterns( inputs: $inputs ) );
        $status  = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash    = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $body    = jpkcom_postfilter_server_reject_body( inputs: $inputs );
        $out     = [];

        // --- reject ---------------------------------------------------------
        $reject = [];
        foreach ( $reasons as $reason => $pattern ) {
            $reject[] = 'if ($request_uri ~ "' . $pattern . '") {';
            $reject[] = '    rewrite ^ /jpkpf-edge-' . $reason . '? last;';
            $reject[] = '}';
        }
        foreach ( $reasons as $reason => $pattern ) {
            $reject[] = '';
            $reject[] = 'location = /jpkpf-edge-' . $reason . ' {';
            $reject[] = '    internal;';
            $reject[] = '    default_type text/html;';
            $reject[] = '    charset utf-8;';
            $reject[] = '    add_header X-Robots-Tag "noindex, nofollow" always;';
            $reject[] = '    add_header X-Jpkpf-Reject "server-' . $reason . '" always;';
            $reject[] = '    add_header X-Jpkpf-Rule "' . $hash . '" always;';
            $reject[] = '    add_header Cache-Control "no-store" always;';
            $reject[] = '    return ' . $status . " '" . $body . "';";
            $reject[] = '}';
        }

        if ( $ispconfig ) {
            $robots = [ 'location = /robots.txt { ##merge##', '    try_files $uri /index.php?$args;', '}' ];
            if ( $reject !== [] ) {
                $robots[] = '';
            }
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'nginx-reject',
                title: __( 'ISPConfig nginx Directives: robots.txt and reject before PHP', 'jpkcom-post-filter' ),
                context: __( 'ISPConfig: Sites, Website, Options, nginx Directives. Paste it once: a second ##merge## block makes nginx -t fail with "try_files directive is duplicate". ISPConfig removes lines starting with # and replaces a location it already has, so this text has no comments and no "location /". The first block adds try_files to ISPConfig\'s own location = /robots.txt, so robots.txt reaches WordPress. The rule hash is in the X-Jpkpf-Rule line.', 'jpkcom-post-filter' ),
                code: implode( "\n", array_merge( $robots, $reject ) )
            );
        } elseif ( $reject !== [] ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'nginx-reject',
                title: __( 'Reject before PHP (server block)', 'jpkcom-post-filter' ),
                context: __( 'Inside the site\'s server block, at server level (not inside a location), then nginx -t and a reload. No root needed if you may edit the site\'s server block. Rejected requests go to an internal location: they never reach PHP or the rate limit.', 'jpkcom-post-filter' ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $reject )
            );
        }

        // --- rate limit -----------------------------------------------------
        $bases    = jpkcom_postfilter_server_safe_bases( bases: (array) ( $inputs['bases'] ?? [] ) );
        $endpoint = (string) ( $inputs['endpoint'] ?? '' );

        if ( $bases !== [] && preg_match( '/^[a-z0-9_-]+$/D', $endpoint ) === 1 ) {
            $http = [
                '# http context. Requests with any cookie are not counted (empty key);',
                '# search engines get their own zone.',
                'map $http_user_agent $jpkpf_searchbot {',
                '    default 0;',
                '    "~*(?:' . jpkcom_postfilter_server_searchbots() . ')" 1;',
                '}',
                'map "$jpkpf_searchbot:$http_cookie" $jpkpf_ip_key   { "0:" $binary_remote_addr; default ""; }',
                'map "$jpkpf_searchbot:$http_cookie" $jpkpf_site_key { "0:" $server_name;        default ""; }',
                'map "$jpkpf_searchbot:$http_cookie" $jpkpf_bot_key  { "1:" $server_name;        default ""; }',
                '',
                'limit_req_zone  $jpkpf_ip_key   zone=jpkpf_ip:10m   rate=' . $limits['ip_per_minute'] . 'r/m;',
                'limit_req_zone  $jpkpf_site_key zone=jpkpf_site:1m  rate=' . $limits['rate'] . 'r/s;',
                'limit_req_zone  $jpkpf_bot_key  zone=jpkpf_bots:1m  rate=' . $limits['bot_rate'] . 'r/s;',
                'limit_conn_zone $jpkpf_site_key zone=jpkpf_conn:1m;',
            ];

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'nginx-http',
                title: __( 'Rate-limit zones (http context, root)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: 1: file path, 2: shell command */
                    __( 'Root only: save as %1$s (http context: map and limit_*_zone are not allowed in a server block), then nginx -t and a reload. Once per server, shared by every site that uses this plugin. The nginx-light package has no limit_req module: check with %2$s (no output means it is available).', 'jpkcom-post-filter' ),
                    '/etc/nginx/conf.d/jpkpf-limits.conf',
                    'nginx -V 2>&1 | grep -o without-http_limit_req'
                ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $http )
            );

            // ISPConfig strips every line that starts with #: the dry-run
            // switches become trailing comments there.
            $dry_req  = $ispconfig ? ' # limit_req_dry_run on;' : '';
            $dry_conn = $ispconfig ? ' # limit_conn_dry_run on;' : '';

            $rate = [];
            foreach ( $bases as $base ) {
                $rate[] = 'location ' . $base . $endpoint . '/ {';
                $rate[] = '    limit_req  zone=jpkpf_site burst=' . $limits['burst'] . ' nodelay;';
                $rate[] = '    limit_req  zone=jpkpf_ip burst=' . $limits['ip_burst'] . ' nodelay;';
                $rate[] = '    limit_req  zone=jpkpf_bots burst=' . $limits['bot_burst'] . ' nodelay;' . $dry_req;
                if ( ! $ispconfig ) {
                    $rate[] = '    # limit_req_dry_run on;';
                }
                $rate[] = '    limit_conn jpkpf_conn ' . $limits['conn'] . ';' . $dry_conn;
                if ( ! $ispconfig ) {
                    $rate[] = '    # limit_conn_dry_run on;';
                }
                $rate[] = '    limit_req_status 429;';
                $rate[] = '    limit_conn_status 429;';
                $rate[] = '    error_page 429 = @jpkpf_busy;';
                $rate[] = '    try_files $uri $uri/ /index.php?$args;';
                $rate[] = '}';
                $rate[] = '';
            }
            $rate[] = 'location @jpkpf_busy {';
            $rate[] = '    default_type text/plain;';
            $rate[] = '    charset utf-8;';
            $rate[] = '    add_header Retry-After ' . $limits['retry_after'] . ' always;';
            $rate[] = '    add_header X-Robots-Tag "noindex, nofollow" always;';
            $rate[] = '    add_header Cache-Control "no-store" always;';
            $rate[] = "    return 429 '" . jpkcom_postfilter_server_plain_text( text: __( 'Too many filter requests, please retry in a moment.', 'jpkcom-post-filter' ) ) . "';";
            $rate[] = '}';

            $context = sprintf(
                /* translators: 1: concurrent requests, 2: requests per second, 3: burst, 4: requests per minute, 5: burst per IP */
                __( 'Server block, only after the http context file is installed (without it nginx -t fails with an unknown zone). Cookie-less filter requests: at most %1$d at once and %2$d per second with a burst of %3$d site-wide, %4$d per minute with a burst of %5$d per IP; beyond that nginx answers 429 with Retry-After. This location takes precedence over page-cache rules in location / for filter URLs.', 'jpkcom-post-filter' ),
                $limits['conn'],
                $limits['rate'],
                $limits['burst'],
                $limits['ip_per_minute'],
                $limits['ip_burst']
            );
            if ( $ispconfig ) {
                $context .= ' ' . sprintf(
                    /* translators: %s: log file path */
                    __( 'ISPConfig: paste it into nginx Directives below the first block. 429 rather than 503, because ISPConfig\'s Own Error-Documents replace 503 together with its headers. For a calibration phase move limit_req_dry_run on; and limit_conn_dry_run on; out of the trailing comments: nginx then only logs "limiting requests, dry run" to %s.', 'jpkcom-post-filter' ),
                    '/var/log/ispconfig/httpd/{DOMAIN}/error.log'
                );
            } else {
                $context .= ' ' . __( 'For a calibration phase remove the # in front of limit_req_dry_run and limit_conn_dry_run: nginx then only logs "limiting requests, dry run" to the site\'s error log.', 'jpkcom-post-filter' );
            }

            $code  = $ispconfig ? implode( "\n", $rate ) : jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $rate );
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'nginx-rate',
                title: __( 'Rate limit for filter URLs', 'jpkcom-post-filter' ),
                context: $context,
                code: $code
            );
        }

        if ( ! $ispconfig ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'nginx-robots',
                title: __( 'robots.txt', 'jpkcom-post-filter' ),
                context: __( 'robots.txt reaches WordPress through location / - nothing to do. Only if the server block has its own location = /robots.txt (a second one makes nginx -t fail), add try_files $uri /index.php?$args; inside it. A physical robots.txt file in the web root always wins.', 'jpkcom-post-filter' ),
                code: ''
            );
        }

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_apache_rules' ) ) {
    /**
     * Apache reject rules on `%{THE_REQUEST}` (raw request line)
     *
     * Research report 4 §1-§2: RewriteRule patterns, REQUEST_URI and
     * RedirectMatch see the decoded path, THE_REQUEST does not. The
     * REDIRECT_STATUS guard keeps the rules out of an error document's
     * internal redirect (otherwise a recursion: THE_REQUEST does not change)
     * and out of WordPress's second pass through index.php. The `env=`
     * header lines exist twice, because an internal redirect (a host-level
     * `ErrorDocument … /index.php`) renames the variable to REDIRECT_…. The
     * string ErrorDocument keeps such a host-level document from handing the
     * request back to WordPress.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs        Rule inputs.
     * @param bool                 $per_directory .htaccess form: `RewriteRule ^`
     *                                            (the per-directory prefix is
     *                                            stripped) and `<IfModule>`.
     * @return string '' when there is no reject part.
     */
    function jpkcom_postfilter_server_apache_rules( array $inputs, bool $per_directory ): string {
        $reasons = jpkcom_postfilter_server_reasons( patterns: jpkcom_postfilter_server_render_patterns( inputs: $inputs, subject: 'request_line' ) );

        if ( $reasons === [] ) {
            return '';
        }

        $status = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash   = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $target = $per_directory ? '^' : '^/';

        $rewrite = [ 'RewriteEngine On' ];
        foreach ( $reasons as $reason => $pattern ) {
            $rewrite[] = 'RewriteCond %{ENV:REDIRECT_STATUS} ^$';
            $rewrite[] = 'RewriteCond %{THE_REQUEST} "' . $pattern . '"';
            $rewrite[] = 'RewriteRule ' . $target . ' - [E=JPKPF_REJECT:server-' . $reason . ',R=' . $status . ',L]';
        }

        $headers = [];
        foreach ( [ 'JPKPF_REJECT', 'REDIRECT_JPKPF_REJECT' ] as $env ) {
            $headers[] = 'Header always set X-Robots-Tag "noindex, nofollow" env=' . $env;
            $headers[] = 'Header always set X-Jpkpf-Reject "%{' . $env . '}e" env=' . $env;
            $headers[] = 'Header always set X-Jpkpf-Rule "' . $hash . '" env=' . $env;
            $headers[] = 'Header always set Cache-Control "no-store" env=' . $env;
        }

        if ( $per_directory ) {
            $rewrite = array_merge( [ '<IfModule mod_rewrite.c>' ], $rewrite, [ '</IfModule>' ] );
            $headers = array_merge( [ '<IfModule mod_headers.c>' ], $headers, [ '</IfModule>' ] );
        }

        $body  = str_replace( '"', '\\"', jpkcom_postfilter_server_reject_body( inputs: $inputs ) );
        $lines = array_merge( $rewrite, $headers, [ 'ErrorDocument ' . $status . ' "' . $body . '"' ] );

        return implode( "\n", $lines );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_htaccess_rules' ) ) {
    /**
     * The .htaccess reject rules, without any marker
     *
     * Shared by the copy snippet (manual markers) and the managed block
     * (jpkcom_postfilter_htaccess_block(), managed markers), so both always
     * carry the same rules.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string '' when there is no reject part.
     */
    function jpkcom_postfilter_server_htaccess_rules( array $inputs ): string {
        return jpkcom_postfilter_server_apache_rules( inputs: $inputs, per_directory: true );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_apache' ) ) {
    /**
     * Apache snippets: VirtualHost reject, mod_qos cap, ModSecurity limits
     *
     * mod_qos (research report 4 §3a): `QS_EventRequestLimit` is a real
     * concurrency cap on cookie-less filter requests. Never
     * `QS_ClientEventLimitCount`: measured, it bans the client IP for every
     * URL, cookie users included.
     *
     * ModSecurity (§3b): fixed-window counters. The window starts with the
     * first request (`expirevar` only when the counter is created); research
     * set expirevar on every hit, which re-arms it - steady traffic then never
     * resets a site-wide counter, and a visitor clicking every few seconds is
     * blocked for good. Every deny rule is chained to its own class (cookie-
     * less visitor or search engine), so a collection another rule set
     * initialised (OWASP CRS uses `ip` and `global`) cannot trigger it.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param array<string, int>   $limits Normalised rate limits.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_apache( array $inputs, array $limits ): array {
        $out    = [];
        $status = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $rules  = jpkcom_postfilter_server_apache_rules( inputs: $inputs, per_directory: false );

        if ( $rules !== '' ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'apache-reject',
                title: __( 'Reject before PHP (VirtualHost)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Inside the site\'s <VirtualHost> (HTTPS and HTTP), then apachectl configtest and a graceful reload. Needs mod_rewrite and mod_headers: apachectl -M | grep -E "rewrite|headers". Runs before any .htaccess. The ErrorDocument line sets the body of every %d that Apache itself sends for this host; WordPress answers its own 404 pages through index.php.', 'jpkcom-post-filter' ),
                    $status
                ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: explode( "\n", $rules ) )
            );
        }

        $scope       = jpkcom_postfilter_server_scope_regex( inputs: $inputs );
        $retry_after = 'Header always set Retry-After "' . $limits['retry_after'] . '" "expr=%{REQUEST_STATUS} == 429"';

        if ( $scope !== null ) {
            $qos = [
                '<IfModule qos_module>',
                '    SetEnvIf Request_URI "' . $scope . '" JPKPF_FILTER=1',
                '    SetEnvIf Cookie "." JPKPF_EXEMPT=1',
                '    SetEnvIfNoCase User-Agent "' . jpkcom_postfilter_server_searchbots() . '" JPKPF_EXEMPT=1',
                '    QS_SetEnvIf JPKPF_FILTER !JPKPF_EXEMPT JPKPF_ANON=1',
                '    QS_EventRequestLimit JPKPF_ANON ' . $limits['conn'],
                '    QS_ErrorResponseCode 429',
                '</IfModule>',
                '<IfModule mod_headers.c>',
                '    ' . $retry_after,
                '</IfModule>',
            ];

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'apache-qos',
                title: __( 'Concurrency cap (mod_qos, server configuration)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: %d: concurrent requests */
                    __( 'Global server configuration, outside every <VirtualHost> (root). Needs mod_qos (Debian: libapache2-mod-qos; check apachectl -M | grep qos). At most %d cookie-less filter requests at once, one cap for all sites on this server; any cookie and the big search engines are exempt, mod_qos answers 429. QS_ClientEventLimitCount is deliberately not used: it bans the whole IP for every URL.', 'jpkcom-post-filter' ),
                    $limits['conn']
                ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $qos )
            );

            $window = (int) ceil( $limits['ip_burst'] * 60 / $limits['ip_per_minute'] );
            $site   = 10 * $limits['rate'];
            $bots   = 10 * $limits['bot_rate'];
            $anon   = 'SecRule TX:JPKPF_ANON "@eq 1" "id:';
            $bot    = 'SecRule TX:JPKPF_BOT "@eq 1" "id:';

            $modsec = [
                '<IfModule security2_module>',
                '    SecRuleEngine On',
                '    # Cookie-less filter request; any Cookie header or a search engine takes it out.',
                '    SecRule REQUEST_URI "@rx ' . $scope . '" "id:4710001,phase:1,pass,nolog,setvar:tx.jpkpf_anon=1"',
                '    SecRule &REQUEST_HEADERS:Cookie "@gt 0" "id:4710002,phase:1,pass,nolog,setvar:tx.jpkpf_anon=0"',
                '    ' . $anon . '4710003,phase:1,pass,nolog,chain"',
                '        SecRule REQUEST_HEADERS:User-Agent "@rx (?i)(?:' . jpkcom_postfilter_server_searchbots() . ')" "setvar:tx.jpkpf_anon=0,setvar:tx.jpkpf_bot=1"',
                '    # Fixed windows: the counter expires ' . $window . ' s (per IP) / 10 s (site) after its first request.',
                '    ' . $anon . '4710004,phase:1,pass,nolog,initcol:ip=%{REMOTE_ADDR},initcol:global=jpkpf_%{SERVER_NAME}"',
                '    ' . $anon . '4710005,phase:1,pass,nolog,chain"',
                '        SecRule &IP:JPKPF_HITS "@eq 0" "setvar:ip.jpkpf_hits=0,expirevar:ip.jpkpf_hits=' . $window . '"',
                '    ' . $anon . '4710006,phase:1,pass,nolog,chain"',
                '        SecRule &GLOBAL:JPKPF_HITS "@eq 0" "setvar:global.jpkpf_hits=0,expirevar:global.jpkpf_hits=10"',
                '    ' . $anon . '4710007,phase:1,pass,nolog,setvar:ip.jpkpf_hits=+1,setvar:global.jpkpf_hits=+1"',
                '    ' . $anon . "4710008,phase:1,deny,status:429,log,msg:'jpkpf: per-IP filter limit',chain\"",
                '        SecRule IP:JPKPF_HITS "@gt ' . $limits['ip_burst'] . '"',
                '    ' . $anon . "4710009,phase:1,deny,status:429,log,msg:'jpkpf: site-wide filter limit',chain\"",
                '        SecRule GLOBAL:JPKPF_HITS "@gt ' . $site . '"',
                '    # Search engines: their own site-wide counter.',
                '    ' . $bot . '4710010,phase:1,pass,nolog,initcol:global=jpkpf_%{SERVER_NAME}"',
                '    ' . $bot . '4710011,phase:1,pass,nolog,chain"',
                '        SecRule &GLOBAL:JPKPF_BOT_HITS "@eq 0" "setvar:global.jpkpf_bot_hits=0,expirevar:global.jpkpf_bot_hits=10"',
                '    ' . $bot . '4710012,phase:1,pass,nolog,setvar:global.jpkpf_bot_hits=+1"',
                '    ' . $bot . "4710013,phase:1,deny,status:429,log,msg:'jpkpf: search engine filter limit',chain\"",
                '        SecRule GLOBAL:JPKPF_BOT_HITS "@gt ' . $bots . '"',
                '</IfModule>',
                $retry_after,
            ];

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'apache-modsec',
                title: __( 'Rate limits per IP and site-wide (ModSecurity, VirtualHost)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: 1: requests, 2: seconds, 3: requests, 4: requests */
                    __( 'Inside the site\'s <VirtualHost>. Needs ModSecurity 2 (security2_module) with a SecDataDir for its counters. Cookie-less filter requests get 429 with Retry-After beyond %1$d per IP in %2$d seconds or %3$d site-wide in 10 seconds; search engines have their own limit of %4$d in 10 seconds. SecRuleEngine On switches ModSecurity on for this host - if OWASP CRS is loaded, CRS then blocks too; drop that line if the engine is already on. mod_evasive is not recommended: it bans whole IPs and knows no path or cookie.', 'jpkcom-post-filter' ),
                    $limits['ip_burst'],
                    $window,
                    $site,
                    $bots
                ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $modsec )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'apache-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'robots.txt reaches WordPress through WordPress\'s own .htaccess rules - nothing to do, unless a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
            code: ''
        );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_htaccess' ) ) {
    /**
     * .htaccess snippet to copy: the rules between the manual markers
     *
     * The manual markers differ from the managed ones on purpose: the plugin
     * only ever edits its managed block and never a pasted one (spec 7).
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_htaccess( array $inputs ): array {
        $out   = [];
        $rules = jpkcom_postfilter_server_htaccess_rules( inputs: $inputs );

        if ( $rules !== '' ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'htaccess-reject',
                title: __( 'Reject before PHP (.htaccess)', 'jpkcom-post-filter' ),
                context: __( 'Paste it into the .htaccess of the WordPress directory, before the line # BEGIN WordPress - after WordPress\'s block it never takes effect. Needs AllowOverride FileInfo. The plugin never edits a block with these (manual) markers.', 'jpkcom-post-filter' ),
                code: implode( "\n", [
                    '# BEGIN JPKCom Post Filter (manual)',
                    '# rule ' . jpkcom_postfilter_server_hash_of( inputs: $inputs ),
                    $rules,
                    '# END JPKCom Post Filter (manual)',
                ] )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'htaccess-rate',
            title: __( 'Rate limit', 'jpkcom-post-filter' ),
            context: __( 'A rate limit cannot be set in .htaccess: mod_qos and ModSecurity are configured at server level. See the tab for the Apache server configuration.', 'jpkcom-post-filter' ),
            code: ''
        );
        $out[] = jpkcom_postfilter_server_snippet(
            id: 'htaccess-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'robots.txt reaches WordPress through WordPress\'s own .htaccess rules - nothing to do, unless a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
            code: ''
        );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_ols' ) ) {
    /**
     * OpenLiteSpeed snippet: Virtual Host rewrite rules, status only
     *
     * Research report 4 §4: THE_REQUEST, `%1` and `[E=…,R=…,L]` work as in
     * Apache, but neither Rewrite nor .htaccess can set a header, and the
     * Virtual Host's `extraHeaders` ignores its `env=` condition - it would
     * put noindex on every page. So the reject carries no header at all.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_ols( array $inputs ): array {
        $out     = [];
        $reasons = jpkcom_postfilter_server_reasons( patterns: jpkcom_postfilter_server_render_patterns( inputs: $inputs, subject: 'request_line' ) );
        $status  = jpkcom_postfilter_server_reject_status( inputs: $inputs );

        if ( $reasons !== [] ) {
            $rules = [];
            foreach ( $reasons as $reason => $pattern ) {
                $rules[] = 'RewriteCond %{THE_REQUEST} "' . $pattern . '"';
                $rules[] = 'RewriteRule ^/ - [E=JPKPF_REJECT:server-' . $reason . ',R=' . $status . ',L]';
            }

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'ols-reject',
                title: __( 'Reject before PHP (Virtual Host rewrite)', 'jpkcom-post-filter' ),
                context: __( 'WebAdmin: Virtual Hosts, your host, Rewrite. Set Enable Rewrite to Yes, paste this into Rewrite Rules, save, then Graceful Restart. OpenLiteSpeed cannot set response headers here, so its reject carries no X-Jpkpf-Reject header: the status check cannot recognise it, and the filter script reloads the page on a rejected fragment request. Do not use extraHeaders instead - OpenLiteSpeed ignores its env= condition and would mark every page noindex. .htaccess changes also need a restart on OpenLiteSpeed, which is why the plugin does not manage them there.', 'jpkcom-post-filter' ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $rules )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'ols-rate',
            title: __( 'Rate limit', 'jpkcom-post-filter' ),
            context: __( 'OpenLiteSpeed has no path-based rate limit in its released versions, only the server-wide Per Client Throttling, which applies to every PHP request.', 'jpkcom-post-filter' ),
            code: ''
        );
        $out[] = jpkcom_postfilter_server_snippet(
            id: 'ols-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'robots.txt reaches WordPress through WordPress\'s own .htaccess rules - nothing to do, unless a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
            code: ''
        );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets' ) ) {
    /**
     * The snippets of one server tab
     *
     * Every entry has a stable `id` (prefixed by the server), a translated
     * `title` and `context` (where it goes, who can install it, how to
     * reload) and the `code` to copy - '' for a hint without code. A reject
     * snippet only exists when there is a reject part (jpkcom_postfilter_
     * server_reject_patterns(): strict URLs on, verdict not filtered, …);
     * rate-limit snippets only with at least one base. Bases that could break
     * a config syntax are left out (jpkcom_postfilter_server_safe_bases()).
     *
     * @since 1.6.0
     *
     * @param string               $server  nginx|apache|htaccess|ols|caddy|ferron3|ferron2.
     * @param array<string, mixed> $inputs  As returned by jpkcom_postfilter_server_rule_inputs().
     * @param array<string, mixed> $limits  As returned by jpkcom_postfilter_rate_limits().
     * @param string               $variant `ispconfig` for nginx, '' otherwise.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets( string $server, array $inputs, array $limits, string $variant = '' ): array {
        $limits = jpkcom_postfilter_server_limits( limits: $limits );

        return match ( $server ) {
            'nginx'    => jpkcom_postfilter_server_snippets_nginx( inputs: $inputs, limits: $limits, ispconfig: $variant === 'ispconfig' ),
            'apache'   => jpkcom_postfilter_server_snippets_apache( inputs: $inputs, limits: $limits ),
            'htaccess' => jpkcom_postfilter_server_snippets_htaccess( inputs: $inputs ),
            'ols'      => jpkcom_postfilter_server_snippets_ols( inputs: $inputs ),
            default    => [],
        };
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_shell_quote' ) ) {
    /**
     * Single-quote a string for sh
     *
     * @since 1.6.0
     *
     * @param string $s Any string.
     * @return string
     */
    function jpkcom_postfilter_server_shell_quote( string $s ): string {
        return "'" . str_replace( "'", "'\\''", $s ) . "'";
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_commands' ) ) {
    /**
     * curl commands that show whether the server rule is active
     *
     * Probe paths from jpkcom_postfilter_server_probe_paths(). The expectation
     * is the VALUE of X-Jpkpf-Reject (spec 6): PHP answers the same over-cap
     * URL with its own 410 (`per-group`, `segments`), and with
     * `unknown_terms_action = reject` even a within-cap probe, so the status
     * alone says nothing. OpenLiteSpeed sends no header with its reject; there
     * a reject status WITHOUT the header is the server's. Not translated: the
     * comments belong to the code block.
     *
     * @since 1.6.0
     *
     * @param string               $server nginx|apache|htaccess|ols|…
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string
     */
    function jpkcom_postfilter_server_check_commands( string $server, array $inputs ): string {
        $render   = jpkcom_postfilter_server_render_inputs( inputs: $inputs );
        $patterns = jpkcom_postfilter_server_render_patterns( inputs: $inputs );
        $probes   = jpkcom_postfilter_server_probe_paths( inputs: $render );
        $status   = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash     = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $headers  = $server !== 'ols';
        $rate     = in_array( $server, [ 'nginx', 'apache' ], true ) && jpkcom_postfilter_server_scope_regex( inputs: $inputs ) !== null;

        $home   = wp_parse_url( home_url( '/' ) );
        $home   = is_array( $home ) ? $home : [];
        $port   = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
        $origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? 'example.com' ) . $port;
        $url    = static fn( string $path ): string => jpkcom_postfilter_server_shell_quote( s: $origin . $path );
        $show   = " | grep -iE '^(HTTP|x-jpkpf-|x-robots-tag|cache-control)'";

        $out   = [];
        $out[] = '# jpkcom-post-filter rule ' . $hash . ': checks for ' . $server;
        if ( $headers ) {
            $out[] = '# Read the VALUE of X-Jpkpf-Reject: "server-..." = the web server answered,';
            $out[] = '# a value without "server-" (per-group, segments, unknown) = PHP answered.';
            $out[] = '# PHP may answer ' . $status . ' as well, so the status alone says nothing.';
        } else {
            $out[] = '# OpenLiteSpeed sends no header with its reject: a ' . $status . ' WITHOUT an';
            $out[] = '# X-Jpkpf-Reject line is the server; PHP\'s own reject always carries one.';
        }

        $over = [
            'per_group' => [ 'N+1 terms in one filter group', 'server-per-group' ],
            'segments'  => [ 'more segments than filter groups', 'server-segments' ],
        ];
        $n = 0;
        foreach ( $over as $key => [ $what, $reason ] ) {
            if ( $patterns[ $key ] === null || $probes[ $key ] === null ) {
                continue;
            }
            $n++;
            $out[] = '';
            if ( $headers ) {
                $out[] = '# ' . $n . ') ' . $what . ': expect "X-Jpkpf-Reject: ' . $reason . '" and "X-Jpkpf-Rule: ' . $hash . '"';
            } else {
                $out[] = '# ' . $n . ') ' . $what . ': expect "HTTP/... ' . $status . '" and no X-Jpkpf-Reject line';
            }
            $out[] = 'curl -s -o /dev/null -D - ' . $url( $probes[ $key ] ) . $show;
        }
        if ( $n === 0 ) {
            $out[] = '';
            $out[] = '# No reject rule is generated for these settings: nothing to probe.';
        }

        $within = implode( ' ', array_map( $url, $probes['within'] ) );
        $out[]  = '';
        if ( $headers ) {
            $out[] = '# ' . ( ++$n ) . ') Never stricter than the plugin: every line must end in 0';
            $out[] = 'for u in ' . $within . '; do printf \'%s \' "$u"; curl -s -o /dev/null -D - "$u" | grep -ciE \'^x-jpkpf-reject: *server-\'; done';
        } else {
            $out[] = '# ' . ( ++$n ) . ') Never stricter than the plugin: no line may show ' . $status . ' without X-Jpkpf-Reject';
            $out[] = 'for u in ' . $within . '; do printf \'%s \' "$u"; curl -s -o /dev/null -D - "$u" | grep -iE \'^(HTTP|x-jpkpf-reject)\' | tr -d \'\\r\' | tr \'\\n\' \' \'; echo; done';
        }

        $out[] = '';
        $out[] = '# ' . ( ++$n ) . ') robots.txt reaches WordPress: expect the "# jpkcom-post-filter" block';
        $out[] = 'curl -s ' . $url( '/robots.txt' ) . " | grep -A8 '^# jpkcom-post-filter'";

        if ( $rate ) {
            $target = $url( $probes['within'][1] . '?rl=' );
            $out[]  = '';
            $out[]  = '# ' . ( ++$n ) . ') Rate limit. This uses up the shared budget of cookie-less visitors: run it off-peak.';
            $out[]  = '#    Expect 429 after the burst, with Retry-After:';
            $out[]  = 'for i in $(seq 1 25); do curl -s -o /dev/null -w \'%{http_code} \' ' . $target . '"$i"; done; echo';
            $out[]  = 'curl -s -o /dev/null -D - ' . $url( $probes['within'][1] ) . " | grep -iE '^(HTTP|retry-after)'";
            $out[]  = '#    Any cookie exempts a request: expect no 429.';
            $out[]  = 'for i in $(seq 1 25); do curl -s -o /dev/null -w \'%{http_code} \' -H \'Cookie: jpkpf-check=1\' ' . $target . '"$i"; done; echo';
        }

        return implode( "\n", $out );
    }
}
