<?php
/**
 * Server Rules – rendering: rate limits, reject body and per-server snippets
 *
 * Pure: everything here works on the inputs and patterns of
 * includes/server-rules.php and returns text, so tests/test-server-rules.php
 * can check every snippet without a server. No hooks are registered.
 *
 * Every snippet follows the same scheme (spec 5.3): a reject answers with the
 * configured status, `X-Robots-Tag: noindex, nofollow`,
 * `X-Jpkpf-Reject: server-<reason>`, `X-Jpkpf-Rule: <hash>` and
 * `Cache-Control: no-store`; a rate limit only applies to filter URLs of
 * requests without any Cookie header, and its 429 carries no X-Robots-Tag.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
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


if ( ! function_exists( function: 'jpkcom_postfilter_server_unsafe_bases' ) ) {
    /**
     * The bases left out of every snippet
     *
     * Those jpkcom_postfilter_server_safe_bases() refuses. Neither the reject
     * rule nor the rate limit covers them, so the admin page names them.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return string[]
     */
    function jpkcom_postfilter_server_unsafe_bases( array $inputs ): array {
        $bases = [];
        foreach ( (array) ( $inputs['bases'] ?? [] ) as $base ) {
            $bases[] = is_scalar( $base ) ? (string) $base : '';
        }
        $safe = jpkcom_postfilter_server_safe_bases( bases: $bases );

        return array_values( array_unique( array_filter(
            $bases,
            static fn( string $base ): bool => ! in_array( $base, $safe, true )
        ) ) );
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
     * @param array<string, mixed> $inputs  Rule inputs.
     * @param string[]             $lines   Code lines.
     * @param string               $comment Comment marker of the config syntax
     *                                      (`//` for Ferron 2's KDL).
     * @return string
     */
    function jpkcom_postfilter_server_wrap( array $inputs, array $lines, string $comment = '#' ): string {
        $version = defined( 'JPKCOM_POSTFILTER_VERSION' ) ? (string) JPKCOM_POSTFILTER_VERSION : '';
        $version = (string) preg_replace( '/[^0-9A-Za-z.+-]/', '', $version );
        $open    = $comment . ' >>> jpkcom-post-filter ' . $version . ' rule ' . jpkcom_postfilter_server_hash_of( inputs: $inputs );

        return implode( "\n", array_merge( [ $open ], $lines, [ $comment . ' <<< jpkcom-post-filter' ] ) );
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
     * Every non-ASCII character becomes a numeric entity as well: Apache sends
     * a string ErrorDocument as `text/html; charset=iso-8859-1` (measured on
     * 2.4.69, VirtualHost and .htaccess), and that header wins over the meta
     * tag - "verfügbar" rendered as "verfÃ¼gbar". Pure ASCII reads the same
     * whatever charset a server announces.
     *
     * @since 1.6.0
     *
     * @param string $text Plain text.
     * @return string
     */
    function jpkcom_postfilter_server_html_text( string $text ): string {
        $text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
        $text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        // Valid UTF-8 after ENT_SUBSTITUTE, so /u cannot fail here.
        $text = (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static function ( array $m ): string {
                $bytes = array_values( (array) unpack( 'C*', $m[0] ) );
                $count = count( $bytes );
                $code  = $bytes[0] & ( 0xFF >> ( $count + 1 ) ); // lead byte of a 2-4 byte sequence
                for ( $i = 1; $i < $count; $i++ ) {
                    $code = ( $code << 6 ) | ( $bytes[ $i ] & 0x3F );
                }
                return '&#' . $code . ';';
            },
            $text
        );

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
     * `^/+`, not `^/`: some servers match the scope on a path they do not
     * merge (Caddy's `{http.request.orig_uri.path}`, possibly Ferron), and
     * WordPress trims leading slashes before it matches its rewrite rules -
     * `//blog/filter/x/` is a full filter render. Where the server merges
     * slashes first (Apache's Request_URI and LocationMatch) the `+` changes
     * nothing. nginx uses prefix locations and never reads this.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string|null `^/+(?:blog/|news/)filter/`, or null without bases or endpoint.
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

        return '^/+(?:' . implode( '|', $alternatives ) . ')' . $endpoint . '/';
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


if ( ! function_exists( function: 'jpkcom_postfilter_server_probe_origin' ) ) {
    /**
     * Scheme, host and port of home_url(), the origin every probe goes to
     *
     * The probe paths already include the home path (they are built from the
     * archive bases). Shared by the printed curl commands and the status
     * check (includes/server-rules-check.php), so both probe the same URLs.
     *
     * @since 1.6.0
     *
     * @return string|null `https://example.com[:port]`, or null when home_url() has no host.
     */
    function jpkcom_postfilter_server_probe_origin(): ?string {
        $home = wp_parse_url( home_url( '/' ) );
        $home = is_array( $home ) ? $home : [];

        if ( ! isset( $home['host'] ) || ! is_string( $home['host'] ) || $home['host'] === '' ) {
            return null;
        }

        $port = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';

        return ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . $port;
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


if ( ! function_exists( function: 'jpkcom_postfilter_server_htaccess_marked' ) ) {
    /**
     * The .htaccess reject rules between `# BEGIN <label>` / `# END <label>`
     *
     * One assembly for both blocks: the copy snippet passes the manual label,
     * jpkcom_postfilter_htaccess_block() the managed one. The `# rule <hash>`
     * line is what jpkcom_postfilter_htaccess_state() reads back.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param string               $label  Marker label, e.g. `JPKCom Post Filter`.
     * @return string '' when there is no reject part. Lines joined with LF, no trailing newline.
     */
    function jpkcom_postfilter_server_htaccess_marked( array $inputs, string $label ): string {
        $rules = jpkcom_postfilter_server_htaccess_rules( inputs: $inputs );
        if ( $rules === '' ) {
            return '';
        }

        return implode( "\n", [
            '# BEGIN ' . $label,
            '# rule ' . jpkcom_postfilter_server_hash_of( inputs: $inputs ),
            $rules,
            '# END ' . $label,
        ] );
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
     * mod_qos has no bucket of its own for search engines: exempting their
     * User-Agent would let anyone who fakes one past the only concurrency cap,
     * so they share it (spec 6).
     *
     * ModSecurity (§3b): everything inside a `<LocationMatch>` over the filter
     * prefixes (decoded path - fine for a rate limit), including `SecRuleEngine
     * On`, so OWASP CRS is not switched to blocking for the rest of the site.
     * Phase 2, because Apache maps a request to its Location only after phase 1
     * rules ran (post_read_request). Fixed-window counters. The window starts with the
     * first request (`expirevar` only when the counter is created); research
     * set expirevar on every hit, which re-arms it - steady traffic then never
     * resets a site-wide counter, and a visitor clicking every few seconds is
     * blocked for good. Every deny rule is chained to its own class (cookie-
     * less visitor or search engine), so a counter another rule set keeps
     * cannot trigger it.
     *
     * The counters live in RESOURCE (key `jpkpf_ip_<addr>`) and USER (key
     * `jpkpf_site_<server name>`, via setuid), not in IP and GLOBAL: OWASP CRS
     * 3.3 opens `ip` as `<addr>_<UA hash>` and `global` as `global` in phase 1,
     * and ModSecurity 2 ignores a later initcol of an open collection. Measured
     * on Debian 13 (CRS 3.3.7, modsecurity.conf active): a new User-Agent per
     * request walked past the per-IP limit, and one VirtualHost's site-wide
     * counter answered 429 on another. Custom collection names are not
     * addressable in ModSecurity 2 ("Unknown variable").
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
                    __( 'Global server configuration, outside every <VirtualHost> (root). Needs mod_qos (Debian: libapache2-mod-qos; check apachectl -M | grep qos). At most %d cookie-less filter requests at once, one cap for all sites on this server; any cookie exempts a request, mod_qos answers 429. Search engines share this cap: a User-Agent exemption would let anyone who fakes one bypass it. QS_ClientEventLimitCount is deliberately not used: it bans the whole IP for every URL.', 'jpkcom-post-filter' ),
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
                // R9: never a vhost-wide SecRuleEngine - it would make OWASP
                // CRS enforce on every URL, wp-admin included.
                '<LocationMatch "' . $scope . '">',
                '    SecRuleEngine On',
                '    # Cookie-less filter request; any Cookie header or a search engine takes it out.',
                '    SecAction "id:4710001,phase:2,pass,nolog,setvar:tx.jpkpf_anon=1"',
                '    SecRule &REQUEST_HEADERS:Cookie "@gt 0" "id:4710002,phase:2,pass,nolog,setvar:tx.jpkpf_anon=0"',
                '    ' . $anon . '4710003,phase:2,pass,nolog,chain"',
                '        SecRule REQUEST_HEADERS:User-Agent "@rx (?i)(?:' . jpkcom_postfilter_server_searchbots() . ')" "setvar:tx.jpkpf_anon=0,setvar:tx.jpkpf_bot=1"',
                '    # Fixed windows: the counter expires ' . $window . ' s (per IP) / 10 s (site) after its first request.',
                '    # RESOURCE and USER, not IP and GLOBAL: OWASP CRS opens those with its own keys first.',
                '    ' . $anon . '4710004,phase:2,pass,nolog,initcol:resource=jpkpf_ip_%{REMOTE_ADDR},setuid:jpkpf_site_%{SERVER_NAME}"',
                '    ' . $anon . '4710005,phase:2,pass,nolog,chain"',
                '        SecRule &RESOURCE:JPKPF_HITS "@eq 0" "setvar:resource.jpkpf_hits=0,expirevar:resource.jpkpf_hits=' . $window . '"',
                '    ' . $anon . '4710006,phase:2,pass,nolog,chain"',
                '        SecRule &USER:JPKPF_HITS "@eq 0" "setvar:user.jpkpf_hits=0,expirevar:user.jpkpf_hits=10"',
                '    ' . $anon . '4710007,phase:2,pass,nolog,setvar:resource.jpkpf_hits=+1,setvar:user.jpkpf_hits=+1"',
                '    ' . $anon . "4710008,phase:2,deny,status:429,log,msg:'jpkpf: per-IP filter limit',chain\"",
                '        SecRule RESOURCE:JPKPF_HITS "@gt ' . $limits['ip_burst'] . '"',
                '    ' . $anon . "4710009,phase:2,deny,status:429,log,msg:'jpkpf: site-wide filter limit',chain\"",
                '        SecRule USER:JPKPF_HITS "@gt ' . $site . '"',
                '    # Search engines: their own site-wide counter.',
                '    ' . $bot . '4710010,phase:2,pass,nolog,setuid:jpkpf_site_%{SERVER_NAME}"',
                '    ' . $bot . '4710011,phase:2,pass,nolog,chain"',
                '        SecRule &USER:JPKPF_BOT_HITS "@eq 0" "setvar:user.jpkpf_bot_hits=0,expirevar:user.jpkpf_bot_hits=10"',
                '    ' . $bot . '4710012,phase:2,pass,nolog,setvar:user.jpkpf_bot_hits=+1"',
                '    ' . $bot . "4710013,phase:2,deny,status:429,log,msg:'jpkpf: search engine filter limit',chain\"",
                '        SecRule USER:JPKPF_BOT_HITS "@gt ' . $bots . '"',
                '</LocationMatch>',
                '</IfModule>',
                '<IfModule mod_headers.c>',
                '    ' . $retry_after,
                '</IfModule>',
            ];

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'apache-modsec',
                title: __( 'Rate limits per IP and site-wide (ModSecurity, VirtualHost)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: 1: requests, 2: seconds, 3: requests, 4: requests */
                    __( 'Inside the site\'s <VirtualHost>. Needs ModSecurity 2 (security2_module) with a SecDataDir for its counters, and mod_headers for Retry-After. Cookie-less filter requests get 429 beyond %1$d per IP in %2$d seconds or %3$d site-wide in 10 seconds; search engines have their own limit of %4$d in 10 seconds. Everything sits in a LocationMatch for the filter URLs: SecRuleEngine On applies there only. If OWASP CRS is loaded without a global SecRuleEngine (Debian\'s default without modsecurity.conf), CRS runs uninitialised inside this LocationMatch: it blocks nothing there and logs rule 911100 for every filter request. Enable modsecurity.conf globally (for example SecRuleEngine DetectionOnly): CRS then initialises normally, only logs elsewhere and also blocks attacks on the filter URLs - try it on a staging copy first. The site-wide counter is keyed on SERVER_NAME, which follows the request\'s Host header: in the default VirtualHost or one with a wildcard ServerAlias set UseCanonicalName On, otherwise every Host value gets a fresh site-wide counter. mod_evasive is not recommended: it bans whole IPs and knows no path or cookie.', 'jpkcom-post-filter' ),
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
        $out  = [];
        $code = jpkcom_postfilter_server_htaccess_marked( inputs: $inputs, label: 'JPKCom Post Filter (manual)' );

        if ( $code !== '' ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'htaccess-reject',
                title: __( 'Reject before PHP (.htaccess)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Paste it into the .htaccess of the WordPress directory, before the line # BEGIN WordPress - after WordPress\'s block it never takes effect. Needs AllowOverride FileInfo. The ErrorDocument line sets the body of every %d that Apache itself sends in this directory tree; WordPress answers its own 404 pages through index.php. The plugin never edits a block with these (manual) markers.', 'jpkcom-post-filter' ),
                    jpkcom_postfilter_server_reject_status( inputs: $inputs )
                ),
                code: $code
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


// ---------------------------------------------------------------------------
// Snippets: Caddy (RE2), Ferron 3 and Ferron 2.8 (Rust regex)
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_caddy' ) ) {
    /**
     * Caddy snippet: one `route` with the rejects first, then `rate_limit`
     *
     * Research report 2 §2. `path_regexp` sees the decoded path, so the reject
     * is a CEL `expression` on the raw path and query. Both the reject and the
     * rate-limit scope read the ORIGINAL request (`{http.request.orig_uri}`,
     * `{http.request.orig_uri.path}`): the Caddyfile sorts `rewrite` and
     * `try_files` before `route`, so a site-level try_files would otherwise
     * have rewritten `{http.request.uri}` and `path` to /index.php first and
     * silently disabled both. The original path is not cleaned, which is why
     * the scope allows leading slashes (scope_regex()).
     *
     * The pattern sits in a double-quoted CEL string inside a Caddyfile
     * backtick token: the backticks pass every byte through, CEL then reads
     * `\\` as one backslash - so each `\` of the pattern is doubled (`\\+`).
     * A root base renders as an empty alternative `(?:|news/)`, which RE2
     * accepts.
     *
     * `route` keeps the written order. Outside it caddy-ratelimit sorts itself
     * before `respond`, and every rejected request then used up the budget of
     * the cookie-less visitors (verified in research). Inside it the segments
     * block comes first: its `respond` ends the route, so a URL over both caps
     * gets the guard's reason. The reject carries `no-store` - a cached reject
     * would outlive a later loosening (spec 5.3).
     *
     * Rate limit (caddy-ratelimit, sliding window of `events` per `window`):
     * per IP `ip_burst` in the time `ip_per_minute` needs for them, site-wide
     * (`key static`) and search engines' own zone over 10 s. Any Cookie header
     * exempts. The 429 page carries `no-store` but no X-Robots-Tag: a busy
     * answer for a valid URL must not tell search engines to drop it.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param array<string, int>   $limits Normalised rate limits.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_caddy( array $inputs, array $limits ): array {
        $reasons = jpkcom_postfilter_server_reasons( patterns: jpkcom_postfilter_server_render_patterns( inputs: $inputs ) );
        $status  = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash    = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $body    = str_replace( '"', '\\"', jpkcom_postfilter_server_reject_body( inputs: $inputs ) );
        $scope   = jpkcom_postfilter_server_scope_regex( inputs: $inputs );
        $route   = [];
        $out     = [];

        foreach ( $reasons as $reason => $pattern ) {
            $m       = '@jpkpf_' . str_replace( '-', '_', $reason );
            $route[] = "\t" . $m . ' expression `{http.request.orig_uri}.matches("' . str_replace( '\\', '\\\\', $pattern ) . '")`';
            $route[] = "\theader " . $m . ' X-Robots-Tag "noindex, nofollow"';
            $route[] = "\theader " . $m . ' X-Jpkpf-Reject "server-' . $reason . '"';
            $route[] = "\theader " . $m . ' X-Jpkpf-Rule "' . $hash . '"';
            $route[] = "\theader " . $m . ' Cache-Control "no-store"';
            $route[] = "\theader " . $m . ' Content-Type "text/html; charset=utf-8"';
            $route[] = "\trespond " . $m . ' "' . $body . '" ' . $status;
            $route[] = '';
        }

        $errors = [];
        if ( $scope !== null ) {
            $ua   = 'header_regexp User-Agent (?i)(?:' . jpkcom_postfilter_server_searchbots() . ')';
            $in   = 'expression `{http.request.orig_uri.path}.matches("' . str_replace( '\\', '\\\\', $scope ) . '")`';
            $zone = static function ( string $name, string $ua_line, string $key, int $events, int $window ) use ( $in ): array {
                return [
                    "\t\tzone " . $name . ' {',
                    "\t\t\tmatch {",
                    "\t\t\t\t" . $in,
                    "\t\t\t\theader !Cookie",
                    "\t\t\t\t" . $ua_line,
                    "\t\t\t}",
                    "\t\t\tkey " . $key,
                    "\t\t\tevents " . $events,
                    "\t\t\twindow " . $window . 's',
                    "\t\t}",
                ];
            };

            $window  = (int) ceil( $limits['ip_burst'] * 60 / $limits['ip_per_minute'] );
            $route[] = "\t# Cookie-less filter requests; search engines have their own zone.";
            $route   = array_merge(
                $route,
                [ "\trate_limit {" ],
                $zone( 'jpkpf_ip', 'not ' . $ua, '{remote_host}', $limits['ip_burst'], $window ),
                $zone( 'jpkpf_all', 'not ' . $ua, 'static', 10 * $limits['rate'], 10 ),
                $zone( 'jpkpf_bots', $ua, 'static', 10 * $limits['bot_rate'], 10 ),
                [ "\t}" ]
            );

            $errors = [
                'handle_errors 429 {',
                "\theader Cache-Control \"no-store\"",
                "\trespond \"" . jpkcom_postfilter_server_plain_text( text: __( 'Too many filter requests, please retry in a moment.', 'jpkcom-post-filter' ) ) . '" 429',
                '}',
            ];
        }

        if ( $route !== [] ) {
            if ( end( $route ) === '' ) {
                array_pop( $route );
            }

            $lines = array_merge( [ 'route {' ], $route, [ '}' ], $errors );

            $context = __( 'Inside the site block of the Caddyfile (FrankenPHP: the same block), anywhere before php_fastcgi or php_server, then caddy validate and caddy reload. Rejects and rate limit sit in one route on purpose: a rejected request is answered before rate_limit and never uses up the budget of real visitors. Both parts read the original request URI ({http.request.orig_uri}), so a try_files or rewrite of the site block does not change what they see. If the site wraps php_fastcgi in a handle block, put the route block inside that handle, before php_fastcgi, and keep handle_errors outside it at the site level (Caddy refuses handle_errors inside handle): handle sorts before route and ends the request, so a route outside it never runs.', 'jpkcom-post-filter' );
            if ( $scope !== null ) {
                $context .= ' ' . sprintf(
                    /* translators: 1: requests, 2: seconds, 3: requests, 4: requests, 5: shell command */
                    __( 'The rate_limit block needs Caddy built with the caddy-ratelimit module (xcaddy build --with github.com/mholt/caddy-ratelimit; FrankenPHP needs its own build with it). Check with %5$s - without the module Caddy refuses the whole file: then delete the rate_limit and handle_errors blocks, the reject still works. Cookie-less filter requests get 429 with Retry-After beyond %1$d per IP in %2$d seconds or %3$d site-wide in 10 seconds; search engines (by User-Agent) have their own limit of %4$d in 10 seconds. Behind a proxy or CDN use key {client_ip} instead of {remote_host} and the global option servers { trusted_proxies static <ranges> }. handle_errors 429 needs Caddy 2.8 or later and answers every 429 of this site.', 'jpkcom-post-filter' ),
                    $limits['ip_burst'],
                    $window,
                    10 * $limits['rate'],
                    10 * $limits['bot_rate'],
                    'caddy list-modules | grep http.handlers.rate_limit'
                );
            }

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'caddy-site',
                title: __( 'Reject before PHP and rate limit (Caddyfile site block)', 'jpkcom-post-filter' ),
                context: $context,
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $lines )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'caddy-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'php_fastcgi (and FrankenPHP\'s php_server) hands /robots.txt to WordPress - nothing to do, unless the site block has its own respond or file_server rule for /robots.txt, or a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
            code: ''
        );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_ferron3' ) ) {
    /**
     * Ferron 3 snippets: top-level `match` blocks and host-level `if` blocks
     *
     * Research report 2 §3.1 (3.0.0-rc.10, `.conf` format). `match` goes at
     * the top level, where every host can use it (rc.10 also takes it inside
     * a host), `if` only inside a host, hence two blocks. The
     * reject matches `request.uri` (raw; `request.uri.path` is decoded) in a
     * raw string `r"…"`, so the pattern goes in unchanged; a root base's empty
     * alternative `(?:|news/)` is valid in Rust's regex since 1.8. The `if`
     * is decided once on the original URI, before any rewrite stage. The
     * per-group matcher excludes the segments pattern, so a URL over both
     * caps gets one status and the guard's reason.
     *
     * Rate limit: `rate_limit` is a token bucket with an integer rate per
     * second (1 is the smallest), per IP. Ferron has no constant key, so
     * there is neither a site-wide rate nor a bucket of their own for search
     * engines. Instead a FastCGI bulkhead: a second `fcgi_php` with its own
     * connection pool, keyed by the URL string - it must differ from the main
     * URL - and `limit` connections; further requests wait. The plugin does
     * not know the backend URL: `<php-backend-url>` is a placeholder. A Unix
     * socket cannot take the `/jpkpf` suffix (Ferron connects to the URL's
     * whole path, so every request failed with 500 in the lab); a second
     * spelling of the same path (`/./`) is the separate pool there.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param array<string, int>   $limits Normalised rate limits.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_ferron3( array $inputs, array $limits ): array {
        $reasons = jpkcom_postfilter_server_reasons( patterns: jpkcom_postfilter_server_render_patterns( inputs: $inputs ) );
        $status  = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash    = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $body    = str_replace( '"', '\\"', jpkcom_postfilter_server_reject_body( inputs: $inputs ) );
        $scope   = jpkcom_postfilter_server_scope_regex( inputs: $inputs );
        $match   = [];
        $host    = [];
        $out     = [];

        foreach ( $reasons as $reason => $pattern ) {
            $name    = 'jpkpf_' . str_replace( '-', '_', $reason );
            $match[] = 'match ' . $name . ' {';
            $match[] = '    request.uri ~ r"' . $pattern . '"';
            if ( $reason === 'per-group' && isset( $reasons['segments'] ) ) {
                $match[] = '    request.uri !~ r"' . $reasons['segments'] . '"';
            }
            $match[] = '}';

            $host[] = 'if ' . $name . ' {';
            $host[] = '    status ' . $status . ' {';
            $host[] = '        body "' . $body . '"';
            $host[] = '    }';
            $host[] = '    header X-Robots-Tag "noindex, nofollow"';
            $host[] = '    header X-Jpkpf-Reject "server-' . $reason . '"';
            $host[] = '    header X-Jpkpf-Rule "' . $hash . '"';
            $host[] = '    header Cache-Control "no-store"';
            $host[] = '    header Content-Type "text/html; charset=utf-8"';
            $host[] = '}';
        }

        if ( $scope !== null ) {
            $match[] = '# Filter URLs from clients without any Cookie header.';
            $match[] = 'match jpkpf_filter_anon {';
            $match[] = '    request.uri.path ~ r"' . $scope . '"';
            $match[] = '    request.header.cookie !~ r"."';
            $match[] = '}';

            $host[] = 'if jpkpf_filter_anon {';
            $host[] = '    rate_limit {';
            $host[] = '        rate 1';
            $host[] = '        burst ' . $limits['ip_burst'];
            $host[] = '        key remote_address';
            $host[] = '        deny_status 429';
            $host[] = '    }';
            $host[] = '    # Bulkhead: replace <php-backend-url> with the URL of the main fcgi_php, keep /jpkpf.';
            $host[] = '    # Unix socket: no /jpkpf suffix, spell the same path differently (unix:///run/php/./php-fpm.sock).';
            $host[] = '    fcgi_php <php-backend-url>/jpkpf {';
            $host[] = '        limit ' . $limits['conn'];
            $host[] = '    }';
            $host[] = '}';
        }

        $rc = __( 'Ferron 3 is a release candidate: tested with 3.0.0-rc.10. Check it again against 3.0.0 final - the release candidates changed configuration semantics.', 'jpkcom-post-filter' );

        if ( $match !== [] ) {
            $out[] = jpkcom_postfilter_server_snippet(
                id: 'ferron3-match',
                title: __( 'Matchers (ferron.conf, top level)', 'jpkcom-post-filter' ),
                context: $rc . ' ' . __( 'At the top level of ferron.conf, outside every host block, so every host can use the matchers (3.0.0-rc.10 also accepts them inside the host block). request.uri is the raw request URI, so the reject leaves every percent-encoded path to PHP.', 'jpkcom-post-filter' ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $match )
            );

            $context = __( 'Inside the site\'s host block, before the rewrite to /index.php, then ferron validate -c /etc/ferron/ferron.conf and a reload. Needs the matchers block. Rejects answer before PHP with all four headers.', 'jpkcom-post-filter' );
            if ( $scope !== null ) {
                $context .= ' ' . sprintf(
                    /* translators: 1: burst, 2: requests per minute, 3: concurrent requests */
                    __( 'Rate limit for cookie-less filter requests: per IP a burst of %1$d, then 1 per second (Ferron\'s smallest rate; the plugin\'s %2$d per minute cannot be expressed), beyond that 429 with Retry-After. Ferron has no constant rate-limit key, so there is no site-wide rate and search engines cannot get a bucket of their own: they share the per-IP limit. Instead a FastCGI bulkhead lets at most %3$d cookie-less filter requests reach PHP at once; further requests wait for a free connection, they get no 429. Replace <php-backend-url> with the URL of the main fcgi_php (for example tcp://127.0.0.1:9000) and keep the /jpkpf suffix: the bulkhead URL must differ from the main fcgi_php URL, otherwise the limit applies to every PHP request. With a Unix socket the suffix becomes part of the socket path and every cookie-less filter request fails with 500: leave out /jpkpf and spell the same path differently instead, for example unix:///run/php/./php-fpm.sock for unix:///run/php/php-fpm.sock. Both forms rely on Ferron keying the connection pool and its limit by the literal URL string - read in the 3.0.0-rc.10 source, not documented, so check it again against 3.0.0 final; a second PHP-FPM listen socket or port (its own pool) is the robust alternative. Ferron answers the 429 with its default error page and no Cache-Control header; a 429 is not cached by default (RFC 9111), so nothing needs adding. Behind a proxy or CDN set client_ip_from_header with trusted_proxy, otherwise the per-IP limit counts the proxy.', 'jpkcom-post-filter' ),
                    $limits['ip_burst'],
                    $limits['ip_per_minute'],
                    $limits['conn']
                );
            }

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'ferron3-host',
                title: __( 'Reject before PHP and rate limit (host block)', 'jpkcom-post-filter' ),
                context: $context,
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $host )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'ferron3-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'The front-controller rewrite to /index.php hands /robots.txt to WordPress - nothing to do, unless a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
            code: ''
        );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_snippets_ferron2' ) ) {
    /**
     * Ferron 2.8 snippets: rate limit only (KDL)
     *
     * Research report 2 §3.2. No reject part in 1.6.0 (untested). Ferron 2's
     * URL sanitizer decodes safe escapes and merges slashes before any module
     * and rewrites the URI PHP receives, so `{path}` equals WordPress's view;
     * a reject on it would not be stricter, but none was built or
     * container-tested. A missing header is ""
     * in conditions (`"-"` never matches). `limit` is per IP only; its bucket
     * holds `burst` requests. KDL strings know escapes, so each `\` of the
     * scope regex is doubled, and comments are `//`.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @param array<string, int>   $limits Normalised rate limits.
     * @return array<int, array{id: string, title: string, context: string, code: string}>
     */
    function jpkcom_postfilter_server_snippets_ferron2( array $inputs, array $limits ): array {
        $scope = jpkcom_postfilter_server_scope_regex( inputs: $inputs );
        $out   = [];

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'ferron2-reject',
            title: __( 'Reject before PHP', 'jpkcom-post-filter' ),
            context: sprintf(
                /* translators: 1, 2, 3: literal URL escapes (%2B, %2F, %70age), not placeholders to translate */
                __( 'No reject rule for Ferron 2.8 in this version: none has been tested. Ferron 2 decodes percent escapes such as %1$s, %2$s or %3$s and merges slashes before any rule runs, and PHP receives that same decoded URI, so WordPress already counts what a server rule would see and keeps rejecting over-cap URLs itself. Ferron 3 can reject before PHP on the raw request.uri.', 'jpkcom-post-filter' ),
                '%2B',
                '%2F',
                '%70age'
            ),
            code: ''
        );

        if ( $scope !== null ) {
            $lines = [
                'condition "JPKPF_FILTER_ANON" {',
                '    is_regex "{path}" "' . str_replace( '\\', '\\\\', $scope ) . '"',
                '    is_equal "{header:Cookie}" ""',
                '}',
                'if "JPKPF_FILTER_ANON" {',
                '    limit rate=1 burst=' . $limits['ip_burst'],
                '}',
            ];

            $out[] = jpkcom_postfilter_server_snippet(
                id: 'ferron2-rate',
                title: __( 'Rate limit for filter URLs (ferron.kdl host block)', 'jpkcom-post-filter' ),
                context: sprintf(
                    /* translators: 1: burst, 2: requests per minute */
                    __( 'Inside the site\'s host block in ferron.kdl, before the rewrite and fcgi_php, then restart Ferron. Cookie-less filter requests: per IP a burst of %1$d, then 1 per second (the plugin\'s %2$d per minute cannot be expressed), beyond that 429. A missing header is "" in Ferron 2 conditions, so is_equal "{header:Cookie}" "" matches exactly the requests without any cookie. Ferron 2 has no site-wide cap and no bucket of their own for search engines: they share the per-IP limit. The 429 is Ferron\'s default error page without Retry-After or Cache-Control; a 429 is not cached by default (RFC 9111). Behind a proxy or CDN the per-IP limit counts the proxy.', 'jpkcom-post-filter' ),
                    $limits['ip_burst'],
                    $limits['ip_per_minute']
                ),
                code: jpkcom_postfilter_server_wrap( inputs: $inputs, lines: $lines, comment: '//' )
            );
        }

        $out[] = jpkcom_postfilter_server_snippet(
            id: 'ferron2-robots',
            title: __( 'robots.txt', 'jpkcom-post-filter' ),
            context: __( 'The front-controller rewrite to /index.php hands /robots.txt to WordPress - nothing to do, unless a physical robots.txt file exists in the web root.', 'jpkcom-post-filter' ),
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
            'caddy'    => jpkcom_postfilter_server_snippets_caddy( inputs: $inputs, limits: $limits ),
            'ferron3'  => jpkcom_postfilter_server_snippets_ferron3( inputs: $inputs, limits: $limits ),
            'ferron2'  => jpkcom_postfilter_server_snippets_ferron2( inputs: $inputs, limits: $limits ),
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
     * a reject status WITHOUT the header is the server's. Ferron 2.8 gets no
     * reject rule at all (jpkcom_postfilter_server_snippets_ferron2()), so it
     * gets no reject probes either. Not translated: the comments belong to
     * the code block.
     *
     * @since 1.6.0
     *
     * @param string               $server nginx|apache|htaccess|ols|caddy|ferron3|ferron2
     * @param array<string, mixed> $inputs Rule inputs.
     * @return string
     */
    function jpkcom_postfilter_server_check_commands( string $server, array $inputs ): string {
        $render   = jpkcom_postfilter_server_render_inputs( inputs: $inputs );
        $patterns = jpkcom_postfilter_server_render_patterns( inputs: $inputs );
        if ( $server === 'ferron2' ) {
            $patterns = [ 'per_group' => null, 'segments' => null ];
        }
        $probes   = jpkcom_postfilter_server_probe_paths( inputs: $render );
        $status   = jpkcom_postfilter_server_reject_status( inputs: $inputs );
        $hash     = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $headers  = $server !== 'ols';
        $rate     = in_array( $server, [ 'nginx', 'apache', 'caddy', 'ferron3', 'ferron2' ], true ) && jpkcom_postfilter_server_scope_regex( inputs: $inputs ) !== null;

        $origin = jpkcom_postfilter_server_probe_origin() ?? 'https://example.com';
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
            if ( $server === 'ferron2' ) {
                $out[] = '# Ferron 2.8 gets no reject rule in this version: nothing to probe.';
            } else {
                $out[] = '# No reject rule is generated for these settings: nothing to probe.';
            }
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
            $base   = $probes['within'][1];
            $target = $url( $base . '?rl=' );
            $seq    = 'for i in $(seq 1 25); do curl -s -o /dev/null -w \'%{http_code} \' ';
            $par    = 'seq 1 8 | xargs -P 8 -I{} curl -s -o /dev/null -w \'%{http_code} \' ';
            $out[]  = '';
            $out[]  = '# ' . ( ++$n ) . ') Rate limit. This uses up the shared budget of cookie-less visitors: run it off-peak.';
            if ( $server === 'nginx' ) {
                $out[] = '#    Sequential requests (limit_req): expect 429 after the burst, then Retry-After on the next one.';
                $out[] = $seq . $target . '"$i"; done; echo';
                $out[] = 'curl -s -o /dev/null -D - ' . $url( $base ) . " | grep -iE '^(HTTP|retry-after)'";
                $out[] = '#    Any cookie exempts a request: expect no 429.';
                $out[] = $seq . '-H \'Cookie: jpkpf-check=1\' ' . $target . '"$i"; done; echo';
            } elseif ( $server !== 'apache' ) {
                if ( $server === 'ferron3' ) {
                    $out[] = '#    a) The FastCGI bulkhead makes CONCURRENT requests wait instead of answering 429:';
                    $out[] = '#       send 8 at once. Expect no 429, and times rising in steps of one PHP request.';
                    $out[] = str_replace( "'%{http_code} '", "'%{http_code}:%{time_total} '", $par ) . $url( $base . '?rl=p{}' ) . '; echo';
                    $out[] = '#    b) The per-IP limit, 15 s after a): sequential requests.';
                    $out[] = 'sleep 15';
                }
                $out[] = '#    Sequential requests: expect 429 after the per-IP burst, with Retry-After where the server sends one.';
                $out[] = $seq . $target . '"$i"; done; echo';
                $out[] = 'curl -s -o /dev/null -D - ' . $url( $base ) . " | grep -iE '^(HTTP|retry-after)'";
                $out[] = '#    Any cookie exempts a request: expect no 429.';
                $out[] = $seq . '-H \'Cookie: jpkpf-check=1\' ' . $target . '"$i"; done; echo';
            } else {
                $out[] = '#    a) mod_qos caps CONCURRENT requests: send 8 at once. Expect some 429 when more';
                $out[] = '#       than the cap overlap (they only overlap while PHP is busy rendering).';
                $out[] = $par . $url( $base . '?rl=p{}' ) . '; echo';
                $out[] = '#    b) ModSecurity counts per IP over time: wait 30 s after a), then sequential requests.';
                $out[] = '#       Expect 429 after the per-IP limit, then Retry-After on the next one.';
                $out[] = 'sleep 30';
                $out[] = $seq . $target . '"$i"; done; echo';
                $out[] = 'curl -s -o /dev/null -D - ' . $url( $base ) . " | grep -iE '^(HTTP|retry-after)'";
                $out[] = '#    Any cookie exempts a request from both: expect no 429 (wait 30 s first).';
                $out[] = 'sleep 30';
                $out[] = $par . '-H \'Cookie: jpkpf-check=1\' ' . $url( $base . '?rl=p{}' ) . '; echo';
                $out[] = $seq . '-H \'Cookie: jpkpf-check=1\' ' . $target . '"$i"; done; echo';
            }
        }

        return implode( "\n", $out );
    }
}
