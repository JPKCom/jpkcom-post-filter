<?php
/**
 * Server Rules status check, and the notice after a loosening save
 *
 * Spec 8. Two things, both about server rules the admin pasted into a web
 * server configuration - the plugin cannot see or change those:
 *
 * - "Check now" (jpkcom_postfilter_server_check_run(), called by the Server
 *   Rules page; no background job): loopback requests to the site's own
 *   URLs. robots.txt must carry the plugin's lines; a synthetic over-cap URL
 *   shows by its X-Jpkpf-Reject / X-Jpkpf-Rule headers whether the server or
 *   PHP rejected it and from which settings the server rule was built; a URL
 *   with exactly N terms - one the plugin serves - must never be rejected by
 *   the server. The rate limit is not probed: that would use up the request
 *   budget all cookie-less visitors share. A loopback the site does not let
 *   through (HTTP authentication, firewall, maintenance mode, timeout) is
 *   "unreachable" with its reason - never "missing".
 * - The notice: after every save of the General or Filter Groups page that
 *   loosens the rule inputs (spec 7: N higher or 0, a group or a position
 *   without taxonomy added, strict URLs off, another endpoint or base) the
 *   saving admin is told to update deployed server rules - whether or not a
 *   check ever ran, red when the last check found them active or stale.
 *   Nothing is blocked. Not when the plugin manages the .htaccess block
 *   itself: includes/htaccess-sync.php keeps that one in step.
 *
 * The probes, classification and the "looser" comparison are pure functions
 * on top of includes/server-rules.php and includes/server-rules-render.php
 * (the probe URLs are the ones the printed curl commands use); the hooks for
 * the notice are at the end of this file.
 *
 * WHY THE "BEFORE" STATE IS CAPTURED ON sanitize_option_* (PRIORITY 4)
 * ---------------------------------------------------------------------
 * The plugin's settings sanitizer (priority 10) writes the settings cache
 * file, and the plugin reads that file before the database - from then on
 * the effective inputs are the new ones. So the inputs before the save can
 * only be read before priority 10. A first save goes update_option() ->
 * add_option() and sanitizes twice; the second run would read the new state,
 * so the first capture of a save is kept until the commit hook takes it.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Pure: probe URLs, classification, "looser"
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_probe_urls' ) ) {
    /**
     * The URLs the status check requests
     *
     * Origin of home_url() plus the paths of
     * jpkcom_postfilter_server_probe_paths() for the snippet inputs - the same
     * URLs the printed curl commands probe. `over`: N+1 probe slugs at the
     * first position with a taxonomy (`/blog/filter/jpkpf-probe-1+…+N+1/`);
     * with N = 0 (unlimited) there is no per-group rule, and the segments
     * probe (G+4 segments) takes its place. `at_cap`: a URL the plugin serves
     * that is closest to the rule - N probe slugs, or G segments for N = 0.
     * Both null when the settings produce no reject pattern. robots.txt is
     * always at the host root, where crawlers read it.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return array{robots: string, over: ?string, at_cap: ?string} `robots` is '' when home_url() has no host.
     */
    function jpkcom_postfilter_server_probe_urls( array $inputs ): array {
        $origin = jpkcom_postfilter_server_probe_origin();

        if ( $origin === null ) {
            return [ 'robots' => '', 'over' => null, 'at_cap' => null ];
        }

        $patterns = jpkcom_postfilter_server_render_patterns( inputs: $inputs );
        $paths    = jpkcom_postfilter_server_probe_paths( inputs: jpkcom_postfilter_server_render_inputs( inputs: $inputs ) );

        $over   = null;
        $at_cap = null;
        if ( $patterns['per_group'] !== null && $paths['per_group'] !== null ) {
            $over   = $paths['per_group'];
            $at_cap = $paths['within'][0];
        } elseif ( $patterns['segments'] !== null && $paths['segments'] !== null ) {
            $over   = $paths['segments'];
            $at_cap = $paths['within'][1];
        }

        $over_url   = $over !== null ? $origin . $over : null;
        $at_cap_url = $at_cap !== null ? $origin . $at_cap : null;

        return [
            'robots' => $origin . '/robots.txt',
            'over'   => $over_url,
            'at_cap' => $at_cap_url,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_response' ) ) {
    /**
     * One probe answer in the shape classify() reads
     *
     * Header names lower-cased; a header sent more than once keeps its first
     * value. Anything malformed becomes "no answer".
     *
     * @since 1.6.0
     *
     * @param mixed $response array{error: ?string, status: int, headers: array<string, string>, body: string}, or null.
     * @return array{error: ?string, status: int, headers: array<string, string>, body: string}
     */
    function jpkcom_postfilter_server_check_response( mixed $response ): array {
        $response = is_array( $response ) ? $response : [ 'error' => '' ];

        $headers = [];
        foreach ( (array) ( $response['headers'] ?? [] ) as $name => $value ) {
            $value = is_array( $value ) ? reset( $value ) : $value;
            $name  = strtolower( (string) $name );
            if ( is_scalar( $value ) && ! isset( $headers[ $name ] ) ) {
                $headers[ $name ] = trim( (string) $value );
            }
        }

        $error = $response['error'] ?? null;
        $error = is_scalar( $error ) ? (string) $error : null;

        return [
            'error'   => $error,
            'status'  => is_numeric( $response['status'] ?? null ) ? (int) $response['status'] : 0,
            'headers' => $headers,
            'body'    => is_string( $response['body'] ?? null ) ? $response['body'] : '',
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_blocked' ) ) {
    /**
     * Why a probe says nothing about the rule, or null when it does
     *
     * A transport error (timeout, refused connection, TLS), no status, HTTP
     * authentication, a firewall, a rate limit, maintenance mode or another
     * server error, and a redirect (`redirection` is 0, so a redirect to
     * https or another host is not followed). Plain text, untrusted parts
     * (error message, Location) included - the page escapes it.
     *
     * @since 1.6.0
     *
     * @param array{error: ?string, status: int, headers: array<string, string>, body: string} $response Normalised answer.
     * @return string|null
     */
    function jpkcom_postfilter_server_check_blocked( array $response ): ?string {
        $status = (int) $response['status'];

        if ( $response['error'] !== null ) {
            $error = $response['error'] !== '' ? $response['error'] : __( 'no answer', 'jpkcom-post-filter' );
            return sprintf(
                /* translators: %s: error message of the HTTP request, e.g. "cURL error 28: Operation timed out" */
                __( 'the request failed (%s)', 'jpkcom-post-filter' ),
                $error
            );
        }

        $reasons = [
            401 => __( 'the site asks for a password (HTTP authentication)', 'jpkcom-post-filter' ),
            403 => __( 'refused by a firewall or an access rule', 'jpkcom-post-filter' ),
            407 => __( 'a proxy asks for a password', 'jpkcom-post-filter' ),
            429 => __( 'too many requests (a rate limit)', 'jpkcom-post-filter' ),
            503 => __( 'maintenance mode or overload', 'jpkcom-post-filter' ),
        ];

        if ( $status <= 0 ) {
            return __( 'no answer', 'jpkcom-post-filter' );
        }

        if ( isset( $reasons[ $status ] ) || $status >= 500 ) {
            $why = $reasons[ $status ] ?? __( 'server error', 'jpkcom-post-filter' );
            return sprintf(
                /* translators: 1: HTTP status code, 2: what it usually means, e.g. "maintenance mode or overload" */
                __( 'HTTP %1$d: %2$s', 'jpkcom-post-filter' ),
                $status,
                $why
            );
        }

        if ( $status >= 300 && $status < 400 ) {
            $location = substr( $response['headers']['location'] ?? '', 0, 200 );
            return sprintf(
                /* translators: 1: HTTP status code, 2: the URL the answer redirects to */
                __( 'HTTP %1$d: redirected to %2$s', 'jpkcom-post-filter' ),
                $status,
                $location
            );
        }

        return null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_classify' ) ) {
    /**
     * Turn the probe answers into a status
     *
     * robots: `ok` when the body carries the plugin's fragment line
     * (`/*jpkpf-fragment`), `disabled` when the plugin writes no robots.txt
     * lines on this site, `not-wordpress` when something else answers
     * robots.txt (a physical file, the web server), `unreachable` when the
     * request did not get through.
     *
     * rule (the `over` probe): `active` for `X-Jpkpf-Reject: server-…` with
     * `X-Jpkpf-Rule` equal to the current hash, `stale` with another or no
     * hash, `missing` when PHP's own reject header (no `server-`) answered,
     * `unknown` for an answer without the header - OpenLiteSpeed cannot send
     * one with its reject, and a proxy may remove it - `unreachable` for a
     * transport error, 401, 403, 429, 5xx or a redirect, `none` when the
     * settings produce no reject pattern.
     *
     * stricter (the `at_cap` probe, a URL the plugin serves): true when the
     * server rejects it - `server-…`, or on LiteSpeed a 404/410 without
     * header. That is the alarm.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $responses       Keys `robots`, `over`, `at_cap`, each
     *                                              array{error: ?string, status: int, headers: array<string, string>, body: string}
     *                                              or null when not requested.
     * @param array<string, mixed> $inputs          Rule inputs the probes were built from.
     * @param string               $server_software SERVER_SOFTWARE of this site.
     * @param bool                 $robots_expected Does the plugin add lines to robots.txt on this site?
     * @return array{robots: string, rule: string, stricter: bool, detail: string}
     */
    function jpkcom_postfilter_server_check_classify( array $responses, array $inputs, string $server_software, bool $robots_expected = true ): array {
        $litespeed = stripos( $server_software, 'litespeed' ) !== false;
        $hash      = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $patterns  = jpkcom_postfilter_server_render_patterns( inputs: $inputs );
        $detail    = [];

        $fragment = is_string( $inputs['fragment'] ?? null ) && $inputs['fragment'] !== '' ? $inputs['fragment'] : 'jpkpf-fragment';

        // --- robots.txt ---
        $robots  = jpkcom_postfilter_server_check_response( response: $responses['robots'] ?? null );
        $blocked = jpkcom_postfilter_server_check_blocked( response: $robots );
        if ( $blocked !== null ) {
            $robots_status = 'unreachable';
            $detail[]      = sprintf(
                /* translators: %s: why the request did not get through, e.g. "HTTP 401: the site asks for a password (HTTP authentication)" */
                __( 'robots.txt could not be checked: %s.', 'jpkcom-post-filter' ),
                $blocked
            );
        } elseif ( $robots['status'] >= 200 && $robots['status'] < 300 && str_contains( $robots['body'], '/*' . $fragment ) ) {
            $robots_status = 'ok';
            $detail[]      = __( 'robots.txt reaches WordPress and carries the plugin\'s lines.', 'jpkcom-post-filter' );
        } elseif ( ! $robots_expected ) {
            $robots_status = 'disabled';
            $detail[]      = __( 'The plugin adds no lines to robots.txt on this site (robots.txt mode off, or the site asks search engines not to index it).', 'jpkcom-post-filter' );
        } else {
            $robots_status = 'not-wordpress';
            $detail[]      = __( 'robots.txt does not carry the plugin\'s lines: a physical robots.txt file or the web server answers it, not WordPress.', 'jpkcom-post-filter' );
        }

        // --- the rule ---
        $stricter = false;
        if ( $patterns['per_group'] === null && $patterns['segments'] === null ) {
            $rule     = 'none';
            $detail[] = __( 'These settings produce no server reject rule, so there is nothing to check. A rule deployed from earlier settings would not be noticed here: remove it from the server configuration.', 'jpkcom-post-filter' );
        } else {
            $over_given = is_array( $responses['over'] ?? null );
            $over       = jpkcom_postfilter_server_check_response( response: $responses['over'] ?? null );
            $reject     = strtolower( $over['headers']['x-jpkpf-reject'] ?? '' );
            $blocked    = jpkcom_postfilter_server_check_blocked( response: $over );

            if ( ! $over_given ) {
                $rule     = 'unreachable';
                $detail[] = __( 'The server rule could not be checked: the site address has no host name to send the probe to.', 'jpkcom-post-filter' );
            } elseif ( str_starts_with( $reject, 'server-' ) ) {
                $got = substr( (string) preg_replace( '/[^0-9A-Za-z]/', '', $over['headers']['x-jpkpf-rule'] ?? '' ), 0, 64 );
                if ( $got !== '' && hash_equals( $hash, strtolower( $got ) ) ) {
                    $rule     = 'active';
                    $detail[] = sprintf(
                        /* translators: %s: rule hash, 12 hex characters */
                        __( 'The server rule is active (rule %s).', 'jpkcom-post-filter' ),
                        $hash
                    );
                } elseif ( $got !== '' ) {
                    $rule     = 'stale';
                    $detail[] = sprintf(
                        /* translators: 1: rule hash the server sent, 2: rule hash of the current settings */
                        __( 'The server answers with rule %1$s, but the current settings need rule %2$s: replace the server rules with the ones on this page.', 'jpkcom-post-filter' ),
                        $got,
                        $hash
                    );
                } else {
                    $rule     = 'stale';
                    $detail[] = __( 'The server rejects the probe URL but does not say which rule it uses: replace the server rules with the ones on this page.', 'jpkcom-post-filter' );
                }
            } elseif ( $reject !== '' ) {
                $rule     = 'missing';
                $detail[] = __( 'No server rule answered: WordPress rejected the probe URL itself. Deploy the server rules from this page.', 'jpkcom-post-filter' );
            } elseif ( $blocked !== null ) {
                $rule     = 'unreachable';
                $detail[] = sprintf(
                    /* translators: %s: why the request did not get through, e.g. "HTTP 503: maintenance mode or overload" */
                    __( 'The server rule could not be checked: %s. This says nothing about whether it is deployed.', 'jpkcom-post-filter' ),
                    $blocked
                );
            } elseif ( $litespeed && in_array( $over['status'], [ 404, 410 ], true ) ) {
                $rule     = 'unknown';
                $detail[] = __( 'Status not detectable: LiteSpeed rejected the probe URL without a header. OpenLiteSpeed cannot send one with its reject, so this is most likely the server rule, but it cannot be told for certain.', 'jpkcom-post-filter' );
            } else {
                $rule     = 'unknown';
                $detail[] = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Status not detectable: the probe URL was answered with HTTP %d and without an X-Jpkpf-Reject header. A proxy or CDN in front of the site may remove it.', 'jpkcom-post-filter' ),
                    $over['status']
                );
            }

            // --- never stricter than the plugin ---
            if ( is_array( $responses['at_cap'] ?? null ) ) {
                $at_cap        = jpkcom_postfilter_server_check_response( response: $responses['at_cap'] );
                $at_cap_reject = strtolower( $at_cap['headers']['x-jpkpf-reject'] ?? '' );

                if ( str_starts_with( $at_cap_reject, 'server-' ) ) {
                    $stricter = true;
                    $detail[] = __( 'Alarm: the server rejects a filter URL within the plugin\'s limits, which the plugin serves. The deployed server rules are stricter than the plugin and break filter pages: replace or remove them now.', 'jpkcom-post-filter' );
                } elseif ( $litespeed && $at_cap_reject === '' && $at_cap['error'] === null && in_array( $at_cap['status'], [ 404, 410 ], true ) ) {
                    $stricter = true;
                    $detail[] = __( 'Alarm: LiteSpeed rejects a filter URL within the plugin\'s limits without a header. Most likely the deployed server rules are stricter than the plugin and break filter pages: replace or remove them now.', 'jpkcom-post-filter' );
                }
            }
        }

        $detail[] = __( 'The rate limit is not checked: the check would use up the request budget that all visitors without cookies share.', 'jpkcom-post-filter' );

        return [
            'robots'   => $robots_status,
            'rule'     => $rule,
            'stricter' => $stricter,
            'detail'   => implode( ' ', $detail ),
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_is_looser' ) ) {
    /**
     * Does going from $old to $new loosen the rule inputs?
     *
     * True when union( $old, $new ) differs from $old (spec 7: N higher or 0,
     * a group or a position without taxonomy added, strict URLs off, another
     * endpoint, fragment or base, a filtered verdict). Compared on the values
     * that shape the rule, as sets where they are sets; the hash is ignored.
     * A state without groups rejects nothing whatever `strict` says, so
     * `strict` counts only with groups. A different reject status counts as
     * looser, too (union() takes the new one): a deployed rule answers with
     * the old one, and when unclear, looser is the safe reading.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $old Inputs before the change.
     * @param array<string, mixed> $new Inputs after it.
     * @return bool
     */
    function jpkcom_postfilter_server_rules_is_looser( array $old, array $new ): bool {
        $shape = static function ( array $inputs ): array {
            $skip = array_values( array_unique( array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) ) ) );
            sort( $skip );

            $bases = array_values( array_unique( array_map( 'strval', (array) ( $inputs['bases'] ?? [] ) ) ) );
            sort( $bases, SORT_STRING );

            $filtered = array_values( array_unique( array_map( 'strval', (array) ( $inputs['policy_filtered'] ?? [] ) ) ) );
            sort( $filtered, SORT_STRING );

            $groups = (int) ( $inputs['groups'] ?? 0 );

            return [
                'strict'           => ! empty( $inputs['strict'] ) && $groups > 0,
                'max_per_group'    => (int) ( $inputs['max_per_group'] ?? 0 ),
                'groups'           => $groups,
                'skip_positions'   => $skip,
                'reject_status'    => (int) ( $inputs['reject_status'] ?? 0 ),
                'endpoint'         => (string) ( $inputs['endpoint'] ?? '' ),
                'fragment'         => (string) ( $inputs['fragment'] ?? '' ),
                'bases'            => $bases,
                'verdict_filtered' => ! empty( $inputs['verdict_filtered'] ),
                'policy_filtered'  => $filtered,
            ];
        };

        $union = jpkcom_postfilter_server_rule_union( old: $old, new: $new );

        return $shape( $union ) !== $shape( $old );
    }
}


// ---------------------------------------------------------------------------
// The check
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_check_last' ) ) {
    /**
     * The stored result of the last check
     *
     * @since 1.6.0
     *
     * @return array{time: int, result: array<string, mixed>}|null Null when no check ran (or the option is malformed).
     */
    function jpkcom_postfilter_server_check_last(): ?array {
        $stored = get_option( 'jpkcom_postfilter_server_rules_check', null );

        if ( ! is_array( $stored ) || ! is_array( $stored['result'] ?? null ) ) {
            return null;
        }

        return [
            'time'   => (int) ( $stored['time'] ?? 0 ),
            'result' => $stored['result'],
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_run' ) ) {
    /**
     * "Check now": probe the site, classify, store
     *
     * GET robots.txt, HEAD on the over-cap and the at-cap URL (both skipped
     * when there is no reject pattern). Only URLs built from home_url();
     * `timeout` 5, `redirection` 0, no cookies, TLS verification as WordPress
     * decides. The result goes to the option
     * `jpkcom_postfilter_server_rules_check` (not autoloaded) as
     * {time, result}. The caller checks capability and nonce.
     *
     * @since 1.6.0
     *
     * @return array{time: int, result: array{robots: string, rule: string, stricter: bool, detail: string}}
     */
    function jpkcom_postfilter_server_check_run(): array {
        jpkcom_postfilter_settings_memo_reset();
        $inputs = jpkcom_postfilter_server_rule_inputs();
        $urls   = jpkcom_postfilter_server_probe_urls( inputs: $inputs );

        $args = [
            'timeout'             => 5,
            'redirection'         => 0,
            'cookies'             => [],
            'limit_response_size' => 65536,
        ];

        $fetch = static function ( string $method, string $url ) use ( $args ): array {
            $response = $method === 'HEAD' ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                return [ 'error' => $response->get_error_message(), 'status' => 0, 'headers' => [], 'body' => '' ];
            }

            $headers = [];
            foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
                $headers[ strtolower( (string) $name ) ] = $value;
            }

            return [
                'error'   => null,
                'status'  => (int) wp_remote_retrieve_response_code( $response ),
                'headers' => $headers,
                'body'    => (string) wp_remote_retrieve_body( $response ),
            ];
        };

        $no_host = [ 'error' => __( 'the site address has no host name', 'jpkcom-post-filter' ), 'status' => 0, 'headers' => [], 'body' => '' ];

        $responses = [
            'robots' => $urls['robots'] !== '' ? $fetch( 'GET', $urls['robots'] ) : $no_host,
            'over'   => $urls['over'] !== null ? $fetch( 'HEAD', $urls['over'] ) : null,
            'at_cap' => $urls['at_cap'] !== null ? $fetch( 'HEAD', $urls['at_cap'] ) : null,
        ];

        $robots_expected = (bool) (int) get_option( 'blog_public', 1 );
        if ( $robots_expected && function_exists( function: 'jpkcom_postfilter_robots_txt_rules' ) ) {
            $robots_expected = jpkcom_postfilter_robots_txt_rules() !== [];
        }

        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

        $record = [
            'time'   => time(),
            'result' => jpkcom_postfilter_server_check_classify(
                responses: $responses,
                inputs: $inputs,
                server_software: $software,
                robots_expected: $robots_expected
            ),
        ];

        update_option( 'jpkcom_postfilter_server_rules_check', $record, false );

        return $record;
    }
}


// ---------------------------------------------------------------------------
// The notice after a loosening save
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_check_pending' ) ) {
    /**
     * The inputs captured before a save of an option, until the commit takes them
     *
     * @since 1.6.0
     *
     * @param string                    $option Option name.
     * @param array<string, mixed>|null $inputs Inputs to remember, or null to read.
     * @param bool                      $take   Forget them after reading.
     * @return array<string, mixed>|null
     */
    function jpkcom_postfilter_server_check_pending( string $option, ?array $inputs = null, bool $take = false ): ?array {
        static $pending = [];

        if ( $inputs !== null ) {
            $pending[ $option ] = $inputs;
            return $inputs;
        }

        $value = $pending[ $option ] ?? null;
        if ( $take ) {
            unset( $pending[ $option ] );
        }

        return $value;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_capture_before' ) ) {
    /**
     * sanitize_option_{option}, priority 4: remember the inputs before the save
     *
     * Before the settings sanitizer (10) rewrites the cache file. A capture
     * that is still pending (the second sanitize of add_option(), or a save
     * that changed nothing) is kept: the state it describes is still the one
     * before the next commit.
     *
     * @since 1.6.0
     *
     * @param mixed  $value  Submitted value, handed on unchanged.
     * @param string $option Option name.
     * @return mixed
     */
    function jpkcom_postfilter_server_check_capture_before( mixed $value, string $option = '' ): mixed {
        // Only for a logged-in user: nobody else ever sees the notice.
        if ( $option !== '' && get_current_user_id() > 0 && jpkcom_postfilter_server_check_pending( option: $option ) === null ) {
            jpkcom_postfilter_settings_memo_reset();
            jpkcom_postfilter_server_check_pending( option: $option, inputs: jpkcom_postfilter_server_rule_inputs() );
        }

        return $value;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_notice_suppressed' ) ) {
    /**
     * Does the plugin keep the server rules in step by itself?
     *
     * Only for the managed .htaccess block, and only while the plugin can
     * actually sync it (Apache, file writable, ...). A managed option on a
     * server that ignores .htaccess keeps nothing in step.
     *
     * @since 1.6.0
     *
     * @return bool
     */
    function jpkcom_postfilter_server_check_notice_suppressed(): bool {
        return function_exists( function: 'jpkcom_postfilter_htaccess_is_managed' )
            && jpkcom_postfilter_htaccess_is_managed()
            && jpkcom_postfilter_htaccess_available()['ok'];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_note_save' ) ) {
    /**
     * After a commit: set the notice for the saving user when the save loosened
     *
     * Compares the inputs captured before the save with the effective inputs
     * now. Without a capture the save counts as loosening. A user transient
     * for 60 seconds - long enough for the redirect after options.php.
     *
     * @since 1.6.0
     *
     * @param string $option Option name.
     * @return void
     */
    function jpkcom_postfilter_server_check_note_save( string $option ): void {
        $before = jpkcom_postfilter_server_check_pending( option: $option, take: true );

        // Nobody to show it to (cron, WP-CLI, a front-end request).
        $user = get_current_user_id();
        if ( $user <= 0 ) {
            return;
        }

        jpkcom_postfilter_settings_memo_reset();
        $after = jpkcom_postfilter_server_rule_inputs();

        if ( $before !== null && ! jpkcom_postfilter_server_rules_is_looser( old: $before, new: $after ) ) {
            return;
        }

        if ( jpkcom_postfilter_server_check_notice_suppressed() ) {
            return;
        }

        set_transient( 'jpkcom_postfilter_loosened_' . $user, 1, 60 );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_after_update' ) ) {
    /**
     * update_option_{option}
     *
     * @since 1.6.0
     *
     * @param mixed  $old_value Old value.
     * @param mixed  $value     New value.
     * @param string $option    Option name.
     * @return void
     */
    function jpkcom_postfilter_server_check_after_update( mixed $old_value, mixed $value, string $option = '' ): void {
        jpkcom_postfilter_server_check_note_save( option: $option );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_after_add' ) ) {
    /**
     * add_option_{option}
     *
     * @since 1.6.0
     *
     * @param string $option Option name.
     * @return void
     */
    function jpkcom_postfilter_server_check_after_add( string $option = '' ): void {
        jpkcom_postfilter_server_check_note_save( option: $option );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_check_notice' ) ) {
    /**
     * admin_notices: the notice after a loosening save, once
     *
     * Red when the last check found the server rules active or stale - they
     * are deployed, so after this save they may reject URLs the plugin now
     * serves. Nothing is blocked.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_server_check_notice(): void {
        $user = get_current_user_id();
        if ( $user <= 0 ) {
            return;
        }

        $key = 'jpkcom_postfilter_loosened_' . $user;
        if ( get_transient( $key ) === false || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        delete_transient( $key );

        if ( jpkcom_postfilter_server_check_notice_suppressed() ) {
            return;
        }

        $last = jpkcom_postfilter_server_check_last();
        $red  = in_array( $last['result']['rule'] ?? '', [ 'active', 'stale' ], true );

        $text = __( 'The filter URL settings were loosened: the plugin now serves filter URLs it rejected before. If JPKCom Post Filter server rules are deployed in the web server configuration, update them now, otherwise the server keeps rejecting those URLs.', 'jpkcom-post-filter' );
        if ( $red ) {
            $text = __( 'The last status check found JPKCom Post Filter server rules deployed on this site.', 'jpkcom-post-filter' ) . ' ' . $text;
        }

        $class = $red ? 'notice notice-error is-dismissible' : 'notice notice-warning is-dismissible';
        $url   = admin_url( 'admin.php?page=jpkcom-postfilter-server-rules' );

        printf(
            '<div class="%1$s"><p>%2$s</p><p><a href="%3$s">%4$s</a></p></div>',
            esc_attr( $class ),
            esc_html( $text ),
            esc_url( $url ),
            esc_html__( 'Update the server rules', 'jpkcom-post-filter' )
        );
    }
}


// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $jpkpf_option ) {
    add_filter( "sanitize_option_{$jpkpf_option}", 'jpkcom_postfilter_server_check_capture_before', 4, 2 );
    add_action( "update_option_{$jpkpf_option}", 'jpkcom_postfilter_server_check_after_update', 30, 3 );
    add_action( "add_option_{$jpkpf_option}", 'jpkcom_postfilter_server_check_after_add', 30, 1 );
}
unset( $jpkpf_option );

add_action( 'admin_notices', 'jpkcom_postfilter_server_check_notice', 10, 0 );
