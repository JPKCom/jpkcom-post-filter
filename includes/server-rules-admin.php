<?php
/**
 * Server Rules – the admin page
 *
 * Spec 6. A sub-page of "Post Filter" that brings the generator
 * (includes/server-rules.php, includes/server-rules-render.php), the
 * .htaccess management (includes/htaccess-manager.php,
 * includes/htaccess-sync.php) and the status check
 * (includes/server-rules-check.php) together. It decides nothing about the
 * rules itself: every snippet, limit, probe and status comes from those
 * files, so what the page shows is what the tests there prove.
 *
 * - Head: the detected web server, warnings, the effective inputs, the form
 *   for the rate-limit inputs (own option `jpkcom_postfilter_server_rules`)
 *   and the status box with "Check now".
 * - One tab per server (nginx, Apache server configuration, Apache
 *   .htaccess, Caddy, Ferron 3, Ferron 2.8, OpenLiteSpeed), the detected one
 *   preselected, all of them available. Each with its snippets and copy
 *   buttons, the order rule, curl checks and the page-cache hint; the
 *   .htaccess tab also with the box that sets the managed block up or
 *   removes it.
 *
 * The decisions are pure helpers (tests/test-server-rules-admin.php); the
 * render functions only print what they return. Every action checks
 * `manage_options` and a nonce and redirects back with a message KEY - the
 * text comes from a fixed list, never from the URL.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_detect_server' ) ) {
    /**
     * The server family a SERVER_SOFTWARE value names
     *
     * LiteSpeed is checked first: WordPress treats it as Apache, but it does
     * not behave like one here (no headers on its reject, .htaccess changes
     * need a restart). FrankenPHP is Caddy with PHP built in; Ferron is taken
     * as Ferron 3, the version the page targets first. The value describes
     * the server that runs PHP, not a proxy or CDN in front of it.
     *
     * @since 1.6.0
     *
     * @param string $software $_SERVER['SERVER_SOFTWARE'].
     * @return string nginx|apache|ols|caddy|ferron3, '' when unknown.
     */
    function jpkcom_postfilter_detect_server( string $software ): string {
        $software = strtolower( $software );

        $map = [
            'litespeed'  => 'ols',
            'nginx'      => 'nginx',
            'apache'     => 'apache',
            'frankenphp' => 'caddy',
            'caddy'      => 'caddy',
            'ferron'     => 'ferron3',
        ];

        foreach ( $map as $needle => $server ) {
            if ( str_contains( $software, $needle ) ) {
                return $server;
            }
        }

        return '';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_tabs' ) ) {
    /**
     * The tabs of the page, in display order
     *
     * The keys are the server ids of jpkcom_postfilter_server_snippets().
     *
     * @since 1.6.0
     *
     * @return array<string, string> Tab id => label.
     */
    function jpkcom_postfilter_server_rules_tabs(): array {
        return [
            'nginx'    => __( 'nginx', 'jpkcom-post-filter' ),
            'apache'   => __( 'Apache (server configuration)', 'jpkcom-post-filter' ),
            'htaccess' => __( 'Apache (.htaccess)', 'jpkcom-post-filter' ),
            'caddy'    => __( 'Caddy / FrankenPHP', 'jpkcom-post-filter' ),
            'ferron3'  => __( 'Ferron 3', 'jpkcom-post-filter' ),
            'ferron2'  => __( 'Ferron 2.8', 'jpkcom-post-filter' ),
            'ols'      => __( 'OpenLiteSpeed', 'jpkcom-post-filter' ),
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_initial_tab' ) ) {
    /**
     * The tab preselected for a detected server
     *
     * Apache opens the .htaccess tab: it is the one most Apache sites can use
     * (no root access needed) and the one the plugin can manage itself; the
     * server-configuration tab sits right next to it. Unknown: the first tab.
     *
     * @since 1.6.0
     *
     * @param string $detected As returned by jpkcom_postfilter_detect_server().
     * @return string Tab id.
     */
    function jpkcom_postfilter_server_rules_initial_tab( string $detected ): string {
        if ( $detected === 'apache' ) {
            return 'htaccess';
        }

        $tabs = jpkcom_postfilter_server_rules_tabs();

        return isset( $tabs[ $detected ] ) ? $detected : (string) array_key_first( $tabs );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_sanitize_options' ) ) {
    /**
     * Sanitizer of the option `jpkcom_postfilter_server_rules`
     *
     * Clamps like jpkcom_postfilter_rate_limits(): workers 1-256, render time
     * 0.05-10 s; a non-numeric value falls back to the default (8, 0.4). A
     * decimal comma is accepted. nginx variant '' (plain server block) or
     * 'ispconfig'. Writes nothing - unlike the plugin's settings sanitizers
     * this option has no cache file.
     *
     * @since 1.6.0
     *
     * @param mixed $value Submitted or stored value.
     * @return array{workers: int, render_seconds: float, nginx_variant: string}
     */
    function jpkcom_postfilter_server_rules_sanitize_options( mixed $value ): array {
        $value = is_array( $value ) ? $value : [];
        $out   = [ 'workers' => 8, 'render_seconds' => 0.4, 'nginx_variant' => '' ];

        $workers = $value['workers'] ?? null;
        if ( is_numeric( $workers ) ) {
            $out['workers'] = max( 1, min( 256, (int) $workers ) );
        }

        $seconds = $value['render_seconds'] ?? null;
        $seconds = is_string( $seconds ) ? str_replace( ',', '.', trim( $seconds ) ) : $seconds;
        if ( is_numeric( $seconds ) && is_finite( (float) $seconds ) ) {
            $out['render_seconds'] = round( max( 0.05, min( 10.0, (float) $seconds ) ), 2 );
        }

        if ( ( $value['nginx_variant'] ?? '' ) === 'ispconfig' ) {
            $out['nginx_variant'] = 'ispconfig';
        }

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_options' ) ) {
    /**
     * The stored rate-limit inputs, sanitized
     *
     * @since 1.6.0
     *
     * @return array{workers: int, render_seconds: float, nginx_variant: string}
     */
    function jpkcom_postfilter_server_rules_options(): array {
        return jpkcom_postfilter_server_rules_sanitize_options( value: get_option( 'jpkcom_postfilter_server_rules', [] ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_warnings' ) ) {
    /**
     * What the admin must know before deploying the rules (spec 6)
     *
     * Each entry: `level` (error, warning, info), `text`, and an `id` for
     * styling and tests. Sorted errors first. Reads WordPress state besides
     * the inputs: a physical robots.txt (ABSPATH and the site root), the home
     * path, blog_public, multisite and the policy constants. The filter
     * group taxonomies come in as an argument (the inputs only carry their
     * count); without them there is no duplicate check.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs     As returned by jpkcom_postfilter_server_rule_inputs().
     * @param string[]             $taxonomies Taxonomy per filter group position, as
     *                                         jpkcom_postfilter_filter_taxonomies() returns them.
     * @return array<int, array{level: string, text: string, id: string}>
     */
    function jpkcom_postfilter_server_rules_warnings( array $inputs, array $taxonomies = [] ): array {
        $out = [];
        $add = static function ( string $id, string $level, string $text ) use ( &$out ): void {
            $out[] = [ 'level' => $level, 'text' => $text, 'id' => $id ];
        };

        $strict = ! empty( $inputs['strict'] );
        $max    = (int) ( $inputs['max_per_group'] ?? 0 );
        $groups = (int) ( $inputs['groups'] ?? 0 );
        $bases  = (array) ( $inputs['bases'] ?? [] );

        if ( ! empty( $inputs['verdict_filtered'] ) ) {
            $add( 'verdict-filtered', 'error', __( 'Code on this site filters jpkcom_postfilter_request_verdict, so the plugin may decide differently on every request. A server cannot follow that: no reject rule is generated, and a managed .htaccess block loses its reject part with the next update. robots.txt and the rate limit are not affected.', 'jpkcom-post-filter' ) );
        }

        if ( ! $strict ) {
            $add( 'strict-off', 'warning', __( 'Strict URLs are off: the plugin does not reject filter URLs over its limits, so there is no server reject rule either. The rate limit and robots.txt still apply.', 'jpkcom-post-filter' ) );
        } elseif ( $max <= 0 ) {
            $add( 'unlimited', 'info', __( 'Terms per group is 0 (unlimited): there is no per-group rule. The server only rejects filter URLs with more segments than there are filter groups.', 'jpkcom-post-filter' ) );
        }

        if ( $groups <= 0 ) {
            $add( 'no-groups', 'warning', __( 'No filter group is enabled, so there is no reject rule.', 'jpkcom-post-filter' ) );
        }

        if ( $bases === [] ) {
            $add( 'no-bases', 'warning', __( 'No archive with filter URLs was found for the enabled post types, so there is neither a reject rule nor a rate limit.', 'jpkcom-post-filter' ) );
        }

        $unsafe = jpkcom_postfilter_server_unsafe_bases( inputs: $inputs );
        if ( $unsafe !== [] ) {
            $add( 'unsafe-bases', 'warning', sprintf(
                /* translators: %s: comma-separated archive paths, e.g. "/my news/" */
                __( 'These archive paths contain characters that cannot go into a server configuration safely and are left out of every snippet: %s. Filter URLs below them are checked by the plugin only, and they have no rate limit.', 'jpkcom-post-filter' ),
                implode( ', ', $unsafe )
            ) );
        }

        $policy_filtered = array_values( array_filter( array_map( 'strval', (array) ( $inputs['policy_filtered'] ?? [] ) ) ) );
        if ( $policy_filtered !== [] ) {
            $hooks = array_map( static fn( string $key ): string => 'jpkcom_postfilter_' . $key, $policy_filtered );
            $add( 'policy-filtered', 'warning', sprintf(
                /* translators: %s: comma-separated filter hook names */
                __( 'Code on this site filters %s. The rules below use the values of this admin request. If the filter answers differently on other requests (another language, user or URL), the server rule may not match the plugin there. Check that code before you deploy the rules.', 'jpkcom-post-filter' ),
                implode( ', ', $hooks )
            ) );
        }

        $skip = array_map( 'intval', (array) ( $inputs['skip_positions'] ?? [] ) );
        if ( $skip !== [] ) {
            $positions = array_map( static fn( int $p ): string => (string) ( $p + 1 ), $skip );
            $add( 'skip-positions', 'info', sprintf(
                /* translators: %s: comma-separated positions of filter groups, counted from 1 */
                __( 'Filter groups at position %s have no taxonomy. The server rule skips those positions, so it catches fewer URLs there; the plugin still checks them.', 'jpkcom-post-filter' ),
                implode( ', ', $positions )
            ) );
        }

        // Two groups on one taxonomy: the plugin adds their terms up, the
        // server counts each segment on its own - looser, never stricter.
        $taxonomies = array_values( array_filter( array_map( 'strval', $taxonomies ), static fn( string $t ): bool => $t !== '' ) );
        $duplicates = array_keys( array_filter( array_count_values( $taxonomies ), static fn( int $n ): bool => $n > 1 ) );
        if ( $duplicates !== [] ) {
            $add( 'duplicate-taxonomies', 'info', sprintf(
                /* translators: %s: comma-separated taxonomy names */
                __( 'More than one filter group uses the taxonomy %s. The plugin counts the terms of all those groups together, the server counts each segment on its own, so the server rule catches fewer URLs there. It is never stricter.', 'jpkcom-post-filter' ),
                implode( ', ', $duplicates )
            ) );
        }

        $constants = [];
        if ( function_exists( function: 'jpkcom_postfilter_policy_locked' ) && function_exists( function: 'jpkcom_postfilter_policy_definitions' ) ) {
            $definitions = jpkcom_postfilter_policy_definitions();
            foreach ( [ 'strict_urls', 'max_filters_per_group', 'reject_status' ] as $key ) {
                if ( jpkcom_postfilter_policy_locked( $key ) ) {
                    $constants[] = (string) ( $definitions[ $key ]['constant'] ?? '' );
                }
            }
        }
        if ( $constants !== [] ) {
            $add( 'constants', 'info', sprintf(
                /* translators: %s: comma-separated constant names */
                __( 'Set in wp-config.php: %s. The rules below use these values. After changing a constant, update the server rules (a managed .htaccess block follows on the next admin page load).', 'jpkcom-post-filter' ),
                implode( ', ', $constants )
            ) );
        }

        // The WordPress directory, and the site root when WordPress lives in
        // a subdirectory of it (get_home_path()).
        $wp_robots   = ABSPATH . 'robots.txt';
        $root_robots = dirname( jpkcom_postfilter_htaccess_file() ) . '/robots.txt';
        if ( is_file( $wp_robots ) ) {
            $add( 'robots-file', 'warning', sprintf(
                /* translators: %s: file path */
                __( 'A robots.txt file exists in the WordPress directory (%s). The web server delivers it directly, so the plugin\'s robots.txt lines never reach crawlers. Copy the lines from the General page into that file, or delete it.', 'jpkcom-post-filter' ),
                $wp_robots
            ) );
        } elseif ( is_file( $root_robots ) ) {
            $add( 'robots-file', 'warning', sprintf(
                /* translators: %s: file path */
                __( 'A robots.txt file exists in the site root (%s). The web server delivers it directly, so the plugin\'s robots.txt lines never reach crawlers. Copy the lines from the General page into that file, or delete it.', 'jpkcom-post-filter' ),
                $root_robots
            ) );
        }

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path = is_string( $home_path ) ? '/' . trim( $home_path, '/' ) . '/' : '/';
        $home_path = $home_path === '//' ? '/' : $home_path;
        if ( $home_path !== '/' ) {
            $add( 'subdirectory', 'warning', sprintf(
                /* translators: %s: path of the WordPress site, e.g. "/wp/" */
                __( 'WordPress runs in the subdirectory %s. Crawlers only read the robots.txt at the root of the domain, which this WordPress does not answer. Copy the plugin\'s robots.txt lines from the General page into that file.', 'jpkcom-post-filter' ),
                $home_path
            ) );
        }

        if ( (string) get_option( 'blog_public', '1' ) === '0' ) {
            $add( 'blog-public', 'info', __( 'Search engines are asked not to index this site (Settings > Reading). WordPress then blocks everything in robots.txt, and the plugin adds no lines of its own.', 'jpkcom-post-filter' ) );
        }

        if ( is_multisite() ) {
            $add( 'multisite', 'warning', __( 'This is a multisite network. The rules on this page cover this site only. Generate them on the Server Rules page of every site whose filter URLs the server should check. Where several sites share one server block, combine their rules by hand (nginx, for example, allows each location only once), and never apply one site\'s rule to another site\'s addresses.', 'jpkcom-post-filter' ) );
        }

        $add( 'proxy', 'info', __( 'Behind a reverse proxy or CDN the web server sees the proxy\'s address: per-IP limits then count all visitors as one, unless the server takes the client address from the forwarded header (nginx realip, Apache mod_remoteip, Caddy trusted_proxies). A CDN may also cache responses or drop the X-Jpkpf headers.', 'jpkcom-post-filter' ) );
        $add( 'top-rewrite', 'info', __( 'The server decides by the URL alone. If another plugin adds its own rewrite rule that serves such a filter URL before this plugin does, the server does not know that and rejects it anyway. Check after installing plugins that add their own URLs below the archive.', 'jpkcom-post-filter' ) );

        $rank = [ 'error' => 0, 'warning' => 1, 'info' => 2 ];
        usort( $out, static fn( array $a, array $b ): int => $rank[ $a['level'] ] <=> $rank[ $b['level'] ] );

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_message' ) ) {
    /**
     * The notice for a `jpkpf_msg` key after an admin-post redirect
     *
     * @since 1.6.0
     *
     * @param string $key Message key from the URL.
     * @return array{type: string, text: string}|null Null for an unknown key.
     */
    function jpkcom_postfilter_server_rules_message( string $key ): ?array {
        $messages = [
            'checked'                  => [ 'success', __( 'Status checked. The result is shown below.', 'jpkcom-post-filter' ) ],
            'htaccess-installed'       => [ 'success', __( 'The .htaccess block is set up. From now on the plugin keeps it in step with the settings.', 'jpkcom-post-filter' ) ],
            'htaccess-installed-empty' => [ 'info', __( 'The .htaccess block is set up, but the current settings produce no reject rule, so nothing was written yet. The plugin writes the block as soon as the settings produce one.', 'jpkcom-post-filter' ) ],
            'htaccess-unavailable'     => [ 'error', __( 'The .htaccess block was not set up. The reason is shown in the .htaccess box below.', 'jpkcom-post-filter' ) ],
            'htaccess-failed'          => [ 'error', __( 'Writing the .htaccess failed, nothing was set up. Check that the file is writable and that its JPKCom Post Filter markers pair up.', 'jpkcom-post-filter' ) ],
            'htaccess-removed'         => [ 'success', __( 'The .htaccess block is removed. The plugin no longer manages the file.', 'jpkcom-post-filter' ) ],
            'htaccess-remove-failed'   => [ 'error', __( 'The .htaccess block could not be removed: the file is not writable, or its JPKCom Post Filter markers do not pair up. The plugin keeps guarding the block, so saves that would leave it stricter than the plugin are refused. Fix the file, then remove the block again.', 'jpkcom-post-filter' ) ],
        ];

        if ( ! isset( $messages[ $key ] ) ) {
            return null;
        }

        return [ 'type' => $messages[ $key ][0], 'text' => $messages[ $key ][1] ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_htaccess_reason' ) ) {
    /**
     * Why the plugin may not manage the .htaccess, in words
     *
     * @since 1.6.0
     *
     * @param string $reason A reason from jpkcom_postfilter_htaccess_available().
     * @return string
     */
    function jpkcom_postfilter_server_rules_htaccess_reason( string $reason ): string {
        return match ( $reason ) {
            'not-apache'           => __( 'The web server does not report itself as Apache, so it does not read .htaccess rules. Use the tab of your server instead.', 'jpkcom-post-filter' ),
            'litespeed'            => __( 'The server is LiteSpeed. OpenLiteSpeed only reads .htaccess changes after a restart, so the plugin does not manage the file there. Copy the block by hand, or use the OpenLiteSpeed tab.', 'jpkcom-post-filter' ),
            'multisite'            => __( 'This is a multisite network: one .htaccess serves every site, so the plugin does not manage it. Copy the block by hand.', 'jpkcom-post-filter' ),
            'file-mods-disallowed' => __( 'DISALLOW_FILE_MODS is set in wp-config.php, so the plugin does not change files. Copy the block by hand.', 'jpkcom-post-filter' ),
            'no-file'              => __( 'There is no .htaccess in the WordPress directory. Save the permalink settings once so that WordPress creates it, then come back.', 'jpkcom-post-filter' ),
            'not-writable'         => __( 'WordPress cannot write the .htaccess. Make it writable for the web server user, or copy the block by hand.', 'jpkcom-post-filter' ),
            'manual-block'         => __( 'The .htaccess already contains a block inserted by hand (# BEGIN JPKCom Post Filter (manual)). Delete that block first: two blocks would apply the same rules twice.', 'jpkcom-post-filter' ),
            default                => __( 'The plugin cannot manage the .htaccess on this site. Copy the block by hand.', 'jpkcom-post-filter' ),
        };
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_htaccess_action' ) ) {
    /**
     * "Set up" or "Remove" the managed .htaccess block
     *
     * install: only when jpkcom_postfilter_htaccess_available() allows it;
     * writes the block for the current inputs, then sets the option
     * `jpkcom_postfilter_htaccess_managed` (not autoloaded). With no reject
     * part the option is set anyway and the block follows once there is one.
     *
     * remove: whenever the file can be written, whatever available() says -
     * removing only loosens, and after a server move it is the only way to
     * get rid of a leftover block. The option goes only when no managed block
     * (and no damaged marker) remains, so a block that could not be removed
     * stays guarded.
     *
     * The caller checks capability and nonce.
     *
     * @since 1.6.0
     *
     * @param string                    $do     install|remove.
     * @param array<string, mixed>|null $inputs Rule inputs, null for the current ones.
     * @return string Message key for jpkcom_postfilter_server_rules_message(), '' for an unknown action.
     */
    function jpkcom_postfilter_server_rules_htaccess_action( string $do, ?array $inputs = null ): string {
        $file = jpkcom_postfilter_htaccess_file();

        if ( $do === 'install' ) {
            if ( ! jpkcom_postfilter_htaccess_available()['ok'] ) {
                return 'htaccess-unavailable';
            }

            if ( $inputs === null ) {
                jpkcom_postfilter_settings_memo_reset();
                $inputs = jpkcom_postfilter_server_rule_inputs();
            }

            if ( ! jpkcom_postfilter_htaccess_apply( file: $file, inputs: $inputs ) ) {
                return 'htaccess-failed';
            }

            update_option( 'jpkcom_postfilter_htaccess_managed', '1', false );

            return jpkcom_postfilter_htaccess_block( inputs: $inputs ) === '' ? 'htaccess-installed-empty' : 'htaccess-installed';
        }

        if ( $do === 'remove' ) {
            if ( jpkcom_postfilter_htaccess_state( file: $file )['managed'] ) {
                jpkcom_postfilter_htaccess_remove( file: $file );
            }

            $state = jpkcom_postfilter_htaccess_state( file: $file );
            if ( $state['managed'] || $state['damaged'] ) {
                return 'htaccess-remove-failed';
            }

            delete_option( 'jpkcom_postfilter_htaccess_managed' );

            return 'htaccess-removed';
        }

        return '';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_check_view' ) ) {
    /**
     * Labels and levels for a stored status check result
     *
     * `stricter`: red (error) on proof, amber (warning) when only suspected
     * (LiteSpeed, ruling R19), amber "not checked" when the at-cap probe did
     * not get through while there is a rule to check, green when it got
     * through, null when there is no rule.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $result The `result` part of jpkcom_postfilter_server_check_last().
     * @return array{robots: string, robots_level: string, rule: string, rule_level: string, stricter: array{level: string, text: string}|null}
     */
    function jpkcom_postfilter_server_rules_check_view( array $result ): array {
        $robots = [
            'ok'            => [ 'success', __( 'robots.txt reaches WordPress and carries the plugin\'s lines.', 'jpkcom-post-filter' ) ],
            'not-wordpress' => [ 'warning', __( 'robots.txt is answered by a file or the web server, not by WordPress.', 'jpkcom-post-filter' ) ],
            'disabled'      => [ 'info', __( 'The plugin adds no robots.txt lines on this site.', 'jpkcom-post-filter' ) ],
            'unreachable'   => [ 'warning', __( 'robots.txt could not be checked.', 'jpkcom-post-filter' ) ],
        ];
        $rules = [
            'active'      => [ 'success', __( 'Active: the server rejects filter URLs over the limits with the current rule.', 'jpkcom-post-filter' ) ],
            'stale'       => [ 'warning', __( 'Outdated: the server uses a rule built from other settings.', 'jpkcom-post-filter' ) ],
            'missing'     => [ 'info', __( 'Not deployed: WordPress rejected the probe URL itself.', 'jpkcom-post-filter' ) ],
            'unknown'     => [ 'warning', __( 'Not detectable from the response.', 'jpkcom-post-filter' ) ],
            'none'        => [ 'info', __( 'No server rule for these settings.', 'jpkcom-post-filter' ) ],
            'unreachable' => [ 'warning', __( 'Could not be checked.', 'jpkcom-post-filter' ) ],
        ];
        $unknown = [ 'warning', __( 'Unknown result.', 'jpkcom-post-filter' ) ];

        $robots_view = $robots[ (string) ( $result['robots'] ?? '' ) ] ?? $unknown;
        $rule_key    = (string) ( $result['rule'] ?? '' );
        $rule_view   = $rules[ $rule_key ] ?? $unknown;

        $stricter = null;
        if ( ! empty( $result['stricter'] ) ) {
            $stricter = [ 'level' => 'error', 'text' => __( 'Alarm: the server rejects a filter URL that the plugin serves. Replace or remove the server rules now.', 'jpkcom-post-filter' ) ];
        } elseif ( ! empty( $result['stricter_suspected'] ) ) {
            $stricter = [ 'level' => 'warning', 'text' => __( 'Possibly stricter than the plugin: LiteSpeed rejected a filter URL within the limits without a header. See the details.', 'jpkcom-post-filter' ) ];
        } elseif ( empty( $result['stricter_checked'] ) && $rule_key !== 'none' ) {
            $stricter = [ 'level' => 'warning', 'text' => __( 'Not checked whether the server is stricter than the plugin. See the details.', 'jpkcom-post-filter' ) ];
        } elseif ( ! empty( $result['stricter_checked'] ) ) {
            $stricter = [ 'level' => 'success', 'text' => __( 'Not stricter than the plugin: a filter URL within the limits got through.', 'jpkcom-post-filter' ) ];
        }

        return [
            'robots'       => $robots_view[1],
            'robots_level' => $robots_view[0],
            'rule'         => $rule_view[1],
            'rule_level'   => $rule_view[0],
            'stricter'     => $stricter,
        ];
    }
}


// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_code' ) ) {
    /**
     * A code block with its copy button
     *
     * The code is printed with esc_html() inside <pre><code>; the script
     * copies the element's textContent, which is the unescaped text again.
     *
     * @since 1.6.0
     *
     * @param string $id   Unique id (snippet id).
     * @param string $code Config text.
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_code( string $id, string $code ): void {
        $element = 'jpkpf-sr-code-' . $id;
        ?>
        <div class="jpkpf-sr-code">
            <div class="jpkpf-sr-code-bar">
                <button type="button" class="button button-small jpkpf-sr-copy" data-copy-target="<?php echo esc_attr( $element ); ?>"><?php esc_html_e( 'Copy', 'jpkcom-post-filter' ); ?></button>
                <span class="jpkpf-sr-copy-feedback" aria-live="polite"></span>
            </div>
            <pre><code id="<?php echo esc_attr( $element ); ?>"><?php echo esc_html( $code ); ?></code></pre>
        </div>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_notice' ) ) {
    /**
     * An inline notice
     *
     * @since 1.6.0
     *
     * @param string $level success|info|warning|error.
     * @param string $text  Plain text.
     * @param string $class Extra class.
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_notice( string $level, string $text, string $class = '' ): void {
        $level = in_array( $level, [ 'success', 'info', 'warning', 'error' ], true ) ? $level : 'info';
        printf(
            '<div class="notice notice-%1$s inline %2$s"><p>%3$s</p></div>',
            esc_attr( $level ),
            esc_attr( $class ),
            esc_html( $text )
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_intro' ) ) {
    /**
     * What the page is for, and how far its promise reaches (spec 8)
     *
     * The snippets are never stricter than the plugin for the settings they
     * were generated from. A server the plugin does not manage keeps a
     * deployed rule after the settings are loosened; from then on the order
     * rule is the admin's job.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_intro(): void {
        ?>
        <p><?php esc_html_e( 'Server rules let the web server turn away filter URLs that the plugin rejects anyway - before WordPress even starts - and slow down floods of filter requests from visitors without cookies. Copy the snippets for your server into its configuration.', 'jpkcom-post-filter' ); ?></p>
        <p><?php esc_html_e( 'The snippets never reject a URL the plugin serves with the current settings. After you loosen the settings, rules already deployed in the server configuration stay as they are until you replace them - follow the order rule in each tab. Only the managed .htaccess block on Apache follows the settings by itself; apart from that block the plugin never changes your server configuration.', 'jpkcom-post-filter' ); ?></p>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_head' ) ) {
    /**
     * Head of the page: detected server, warnings, inputs, rate-limit form
     *
     * @since 1.6.0
     *
     * @param array<string, mixed>                                              $inputs   Rule inputs.
     * @param array{workers: int, render_seconds: float, nginx_variant: string} $opts     Rate-limit inputs.
     * @param string                                                            $software   SERVER_SOFTWARE.
     * @param string[]                                                          $taxonomies Taxonomy per filter group position (duplicate check).
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_head( array $inputs, array $opts, string $software, array $taxonomies = [] ): void {
        $tabs           = jpkcom_postfilter_server_rules_tabs();
        $detected       = jpkcom_postfilter_detect_server( software: $software );
        $limits         = jpkcom_postfilter_rate_limits( workers: (int) $opts['workers'], render_seconds: (float) $opts['render_seconds'] );
        $hash           = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $locked         = static fn( string $key ): bool => function_exists( function: 'jpkcom_postfilter_policy_locked' ) && jpkcom_postfilter_policy_locked( $key );
        $filtered       = (array) ( $inputs['policy_filtered'] ?? [] );
        $strict_label   = ! empty( $inputs['strict'] ) ? __( 'on', 'jpkcom-post-filter' ) : __( 'off', 'jpkcom-post-filter' );
        $software_shown = $software !== '' ? $software : '-';
        $detected_label = $detected === 'apache' ? 'Apache' : ( $tabs[ $detected ] ?? $detected );
        $source         = static function ( string $key ) use ( $locked, $filtered ): string {
            $notes = [];
            if ( $locked( $key ) ) {
                $notes[] = __( 'constant', 'jpkcom-post-filter' );
            }
            if ( in_array( $key, $filtered, true ) ) {
                $notes[] = __( 'filtered', 'jpkcom-post-filter' );
            }
            return $notes === [] ? '' : ' (' . implode( ', ', $notes ) . ')';
        };
        ?>
        <h2><?php esc_html_e( 'Web server', 'jpkcom-post-filter' ); ?></h2>
        <p class="jpkpf-sr-server">
            <?php
            if ( $detected !== '' ) {
                printf(
                    /* translators: 1: name of a server tab, e.g. "nginx", 2: the SERVER_SOFTWARE value */
                    esc_html__( 'Detected: %1$s (SERVER_SOFTWARE: %2$s). Its tab is preselected below; all other tabs stay available.', 'jpkcom-post-filter' ),
                    '<strong>' . esc_html( $detected_label ) . '</strong>',
                    '<code>' . esc_html( $software ) . '</code>'
                );
            } else {
                printf(
                    /* translators: %s: the SERVER_SOFTWARE value */
                    esc_html__( 'The web server could not be identified from SERVER_SOFTWARE (%s). Choose the tab of your server below.', 'jpkcom-post-filter' ),
                    '<code>' . esc_html( $software_shown ) . '</code>'
                );
            }
            ?>
            <br><span class="description"><?php esc_html_e( 'This is the server that runs PHP. A proxy or CDN in front of it is not visible here.', 'jpkcom-post-filter' ); ?></span>
        </p>

        <div class="jpkpf-sr-warnings">
            <?php
            foreach ( jpkcom_postfilter_server_rules_warnings( inputs: $inputs, taxonomies: $taxonomies ) as $warning ) {
                jpkcom_postfilter_server_rules_render_notice(
                    level: $warning['level'],
                    text: $warning['text'],
                    class: 'jpkpf-sr-warning jpkpf-sr-warning-' . sanitize_key( $warning['id'] )
                );
            }
            ?>
        </div>

        <h2><?php esc_html_e( 'What the rules are built from', 'jpkcom-post-filter' ); ?></h2>
        <p class="description"><?php esc_html_e( 'The effective values of this site: wp-config.php constants, saved settings and code filters already applied. The rule hash changes whenever one of them changes; the status check uses it to recognise outdated rules.', 'jpkcom-post-filter' ); ?></p>
        <table class="widefat striped jpkpf-sr-inputs">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Strict URLs', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo esc_html( $strict_label . $source( 'strict_urls' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Terms per filter group', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo esc_html( (string) (int) ( $inputs['max_per_group'] ?? 0 ) . $source( 'max_filters_per_group' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Filter groups', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo esc_html( (string) (int) ( $inputs['groups'] ?? 0 ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Reject status', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo esc_html( (string) jpkcom_postfilter_server_reject_status( inputs: $inputs ) . $source( 'reject_status' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'URL endpoint / fragment segment', 'jpkcom-post-filter' ); ?></th>
                    <td><code><?php echo esc_html( (string) ( $inputs['endpoint'] ?? '' ) ); ?></code> / <code><?php echo esc_html( (string) ( $inputs['fragment'] ?? '' ) ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Archive paths', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <?php
                        $bases = array_map( 'strval', (array) ( $inputs['bases'] ?? [] ) );
                        if ( $bases === [] ) {
                            echo '&ndash;';
                        }
                        echo implode( ' ', array_map( static fn( string $base ): string => '<code>' . esc_html( $base ) . '</code>', $bases ) );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Rule hash', 'jpkcom-post-filter' ); ?></th>
                    <td><code><?php echo esc_html( $hash ); ?></code></td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Rate limit', 'jpkcom-post-filter' ); ?></h2>
        <p><?php esc_html_e( 'Two numbers decide every rate limit on this page. Enter what your hosting gives you; when in doubt, keep the defaults.', 'jpkcom-post-filter' ); ?></p>
        <form method="post" action="options.php" class="jpkpf-sr-form">
            <?php settings_fields( 'jpkcom_postfilter_server_rules' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="jpkpf-sr-workers"><?php esc_html_e( 'PHP workers', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <input type="number" id="jpkpf-sr-workers" name="jpkcom_postfilter_server_rules[workers]" value="<?php echo esc_attr( (string) $opts['workers'] ); ?>" min="1" max="256" step="1" class="small-text">
                        <p class="description"><?php esc_html_e( 'How many PHP requests the site can run at the same time (PHP-FPM pm.max_children, or what your host states). Default 8.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jpkpf-sr-seconds"><?php esc_html_e( 'Render time of a filter page (seconds)', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <input type="number" id="jpkpf-sr-seconds" name="jpkcom_postfilter_server_rules[render_seconds]" value="<?php echo esc_attr( (string) $opts['render_seconds'] ); ?>" min="0.05" max="10" step="0.01" class="small-text">
                        <p class="description"><?php esc_html_e( 'How long WordPress needs for one filter page without page cache (browser developer tools, "waiting for server"). Default 0.4.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jpkpf-sr-variant"><?php esc_html_e( 'nginx variant', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <select id="jpkpf-sr-variant" name="jpkcom_postfilter_server_rules[nginx_variant]">
                            <option value="" <?php selected( $opts['nginx_variant'], '' ); ?>><?php esc_html_e( 'Standard (server block)', 'jpkcom-post-filter' ); ?></option>
                            <option value="ispconfig" <?php selected( $opts['nginx_variant'], 'ispconfig' ); ?>><?php esc_html_e( 'ISPConfig (nginx Directives field)', 'jpkcom-post-filter' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save and update the snippets', 'jpkcom-post-filter' ), 'secondary' ); ?>
        </form>

        <p><strong><?php esc_html_e( 'Resulting limits for filter URLs, requests without any cookie:', 'jpkcom-post-filter' ); ?></strong></p>
        <ul class="jpkpf-sr-limits">
            <li>
                <?php
                printf(
                    /* translators: %d: number of requests */
                    esc_html__( 'At most %d filter pages rendering at the same time (3/8 of the workers, at least 1). The other workers stay free for the rest of the site.', 'jpkcom-post-filter' ),
                    (int) $limits['conn']
                );
                ?>
            </li>
            <li>
                <?php
                printf(
                    /* translators: 1: requests per second, 2: burst size */
                    esc_html__( 'Site-wide %1$d requests per second, burst %2$d (0.4 x the concurrent pages / render time: those workers are busy 40%% of the time on average).', 'jpkcom-post-filter' ),
                    (int) $limits['rate'],
                    (int) $limits['burst']
                );
                ?>
            </li>
            <li>
                <?php
                printf(
                    /* translators: 1: requests per minute, 2: burst size */
                    esc_html__( 'Per visitor IP %1$d requests per minute, burst %2$d.', 'jpkcom-post-filter' ),
                    (int) $limits['ip_per_minute'],
                    (int) $limits['ip_burst']
                );
                ?>
            </li>
            <li>
                <?php
                printf(
                    /* translators: 1: requests per second, 2: burst size */
                    esc_html__( 'Search engines in their own budget: %1$d requests per second, burst %2$d (two thirds of the site-wide rate).', 'jpkcom-post-filter' ),
                    (int) $limits['bot_rate'],
                    (int) $limits['bot_burst']
                );
                ?>
            </li>
            <li>
                <?php
                printf(
                    /* translators: %d: seconds */
                    esc_html__( 'Over the limit the server answers 429 with Retry-After: %d.', 'jpkcom-post-filter' ),
                    (int) $limits['retry_after']
                );
                ?>
            </li>
        </ul>
        <p class="description"><?php esc_html_e( 'Any Cookie header exempts a request from the rate limit, on every server: logged-in users and visitors who already browse the site are never slowed down. A bot can send a cookie too, and a first-time visitor has none - the limit is a brake for anonymous floods, not access control.', 'jpkcom-post-filter' ); ?></p>
        <p class="description"><?php esc_html_e( 'Googlebot, Bingbot, Applebot, DuckDuckBot and YandexBot get their own budget where the server can tell them apart (nginx, Caddy, Apache with ModSecurity): a 429 for Google would slow down its crawling of the whole site. Elsewhere they share the site-wide limit, which the tab then says.', 'jpkcom-post-filter' ); ?></p>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_status' ) ) {
    /**
     * The status box: last check result and the "Check now" button
     *
     * @since 1.6.0
     *
     * @param array{time: int, result: array<string, mixed>}|null $last As returned by jpkcom_postfilter_server_check_last().
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_status( ?array $last ): void {
        ?>
        <div class="jpkpf-sr-status">
            <h2><?php esc_html_e( 'Status', 'jpkcom-post-filter' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Sends a few requests to this site, without cookies: robots.txt, one filter URL over the limits and one within them. It shows whether the server rule is deployed, current, and never stricter than the plugin. The rate limit is not tested, because that would use up the budget all visitors without cookies share.', 'jpkcom-post-filter' ); ?></p>
            <?php
            if ( $last === null ) {
                echo '<p>' . esc_html__( 'The check has not run yet.', 'jpkcom-post-filter' ) . '</p>';
            } else {
                $result = $last['result'];
                $view   = jpkcom_postfilter_server_rules_check_view( result: $result );
                $format = (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' );
                ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: date and time */
                        esc_html__( 'Last check: %s', 'jpkcom-post-filter' ),
                        esc_html( (string) wp_date( $format, (int) $last['time'] ) )
                    );
                    ?>
                </p>
                <table class="widefat jpkpf-sr-status-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'robots.txt', 'jpkcom-post-filter' ); ?></th>
                            <td class="jpkpf-sr-level-<?php echo esc_attr( $view['robots_level'] ); ?>"><?php echo esc_html( $view['robots'] ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Server rule', 'jpkcom-post-filter' ); ?></th>
                            <td class="jpkpf-sr-level-<?php echo esc_attr( $view['rule_level'] ); ?>"><?php echo esc_html( $view['rule'] ); ?></td>
                        </tr>
                        <?php if ( $view['stricter'] !== null ) : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Stricter than the plugin?', 'jpkcom-post-filter' ); ?></th>
                                <td class="jpkpf-sr-level-<?php echo esc_attr( $view['stricter']['level'] ); ?>"><?php echo esc_html( $view['stricter']['text'] ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ( ( $result['detail'] ?? '' ) !== '' ) : ?>
                    <p class="jpkpf-sr-detail"><?php echo esc_html( (string) $result['detail'] ); ?></p>
                <?php endif; ?>
                <?php
            }
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jpkcom_postfilter_server_check">
                <?php wp_nonce_field( 'jpkcom_postfilter_server_check' ); ?>
                <?php submit_button( __( 'Check now', 'jpkcom-post-filter' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_htaccess_box' ) ) {
    /**
     * The .htaccess management box of the `htaccess` tab (spec 7)
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs.
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_htaccess_box( array $inputs ): void {
        $file      = jpkcom_postfilter_htaccess_file();
        $state     = jpkcom_postfilter_htaccess_state( file: $file );
        $available = jpkcom_postfilter_htaccess_available();
        $managed   = jpkcom_postfilter_htaccess_is_managed();
        $hash      = jpkcom_postfilter_server_hash_of( inputs: $inputs );
        $has_block = $state['managed'] || $managed;

        $button = static function ( string $do, string $label, string $class ): void {
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jpkpf-sr-inline-form">
                <input type="hidden" name="action" value="jpkcom_postfilter_htaccess">
                <input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
                <?php wp_nonce_field( 'jpkcom_postfilter_htaccess' ); ?>
                <?php submit_button( $label, $class, 'submit', false ); ?>
            </form>
            <?php
        };
        ?>
        <div class="jpkpf-sr-htaccess">
            <h3><?php esc_html_e( 'Let the plugin manage the block', 'jpkcom-post-filter' ); ?></h3>
            <p><?php esc_html_e( 'Set up writes the reject block between # BEGIN JPKCom Post Filter and # END JPKCom Post Filter directly before WordPress\'s own block, and from then on the plugin keeps it in step with the settings. A save that would loosen the settings is refused while the block cannot be updated, so the block is never stricter than the plugin. Remove, or deactivating the plugin, deletes the block again.', 'jpkcom-post-filter' ); ?></p>
            <ul class="jpkpf-sr-htaccess-state">
                <li>
                    <?php
                    if ( ! $state['exists'] ) {
                        $file_state = __( 'does not exist', 'jpkcom-post-filter' );
                    } elseif ( $state['writable'] ) {
                        $file_state = __( 'writable', 'jpkcom-post-filter' );
                    } else {
                        $file_state = __( 'not writable', 'jpkcom-post-filter' );
                    }
                    printf(
                        /* translators: 1: file path, 2: file state, e.g. "writable" */
                        esc_html__( 'File: %1$s (%2$s)', 'jpkcom-post-filter' ),
                        '<code>' . esc_html( $file ) . '</code>',
                        esc_html( $file_state )
                    );
                    ?>
                </li>
                <li>
                    <?php
                    if ( $state['managed'] && $state['hash'] !== null && $state['hash'] === $hash ) {
                        /* translators: %s: rule hash */
                        $block_state = sprintf( __( 'Managed block present, current (rule %s).', 'jpkcom-post-filter' ), $state['hash'] );
                    } elseif ( $state['managed'] && $state['hash'] !== null ) {
                        /* translators: 1: rule hash in the file, 2: rule hash of the current settings */
                        $block_state = sprintf( __( 'Managed block present, built from other settings (rule %1$s, current %2$s). It is updated on the next admin page load.', 'jpkcom-post-filter' ), $state['hash'], $hash );
                    } elseif ( $state['managed'] ) {
                        $block_state = __( 'Managed block present.', 'jpkcom-post-filter' );
                    } elseif ( $managed ) {
                        $block_state = __( 'Set up, but there is no block in the file: the current settings produce no reject rule, or the block was deleted by hand.', 'jpkcom-post-filter' );
                    } else {
                        $block_state = __( 'Not set up.', 'jpkcom-post-filter' );
                    }
                    echo esc_html( $block_state );
                    ?>
                </li>
            </ul>
            <?php
            if ( $state['damaged'] ) {
                jpkcom_postfilter_server_rules_render_notice(
                    level: 'error',
                    text: __( 'The JPKCom Post Filter markers in the .htaccess do not pair up (a BEGIN without END, or the other way round). The plugin neither writes nor removes anything until the file is fixed by hand.', 'jpkcom-post-filter' ),
                    class: 'jpkpf-sr-htaccess-damaged'
                );
            }
            if ( $state['misplaced'] ) {
                jpkcom_postfilter_server_rules_render_notice(
                    level: 'warning',
                    text: __( 'The managed block sits below WordPress\'s block, where it never takes effect. The plugin moves it with the next update; Set up moves it right away.', 'jpkcom-post-filter' ),
                    class: 'jpkpf-sr-htaccess-misplaced'
                );
            }
            if ( $state['manual'] ) {
                jpkcom_postfilter_server_rules_render_notice(
                    level: 'info',
                    text: __( 'The file contains a block inserted by hand (manual markers). The plugin never changes it.', 'jpkcom-post-filter' ),
                    class: 'jpkpf-sr-htaccess-manual'
                );
            }

            if ( ! $available['ok'] && ! $state['damaged'] ) {
                echo '<p class="jpkpf-sr-htaccess-reason">' . esc_html( jpkcom_postfilter_server_rules_htaccess_reason( reason: $available['reason'] ) ) . '</p>';
            }

            if ( ! $state['damaged'] ) {
                echo '<div class="jpkpf-sr-htaccess-actions">';
                if ( $available['ok'] ) {
                    $label = $has_block ? __( 'Set up again', 'jpkcom-post-filter' ) : __( 'Set up', 'jpkcom-post-filter' );
                    $class = $has_block ? 'secondary' : 'primary';
                    $button( 'install', $label, $class );
                }
                if ( $has_block && ( $state['writable'] || ! $state['exists'] ) ) {
                    $button( 'remove', __( 'Remove', 'jpkcom-post-filter' ), 'secondary' );
                }
                echo '</div>';
            }
            ?>
        </div>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_render_tab' ) ) {
    /**
     * One server tab
     *
     * @since 1.6.0
     *
     * @param string                                                            $tab      Tab id.
     * @param array<string, mixed>                                              $inputs   Rule inputs.
     * @param array{workers: int, render_seconds: float, nginx_variant: string} $opts     Rate-limit inputs.
     * @param string                                                            $detected As returned by jpkcom_postfilter_detect_server().
     * @return void
     */
    function jpkcom_postfilter_server_rules_render_tab( string $tab, array $inputs, array $opts, string $detected ): void {
        $tabs     = jpkcom_postfilter_server_rules_tabs();
        $limits   = jpkcom_postfilter_rate_limits( workers: (int) $opts['workers'], render_seconds: (float) $opts['render_seconds'] );
        $variant  = ( $tab === 'nginx' && $opts['nginx_variant'] === 'ispconfig' ) ? 'ispconfig' : '';
        $snippets = jpkcom_postfilter_server_snippets( server: $tab, inputs: $inputs, limits: $limits, variant: $variant );
        $is_here  = $detected !== '' && ( $detected === $tab || jpkcom_postfilter_server_rules_initial_tab( detected: $detected ) === $tab );
        ?>
        <div id="tab-<?php echo esc_attr( $tab ); ?>" class="jpkpf-tab-panel jpkpf-sr-panel">
            <h2><?php echo esc_html( $tabs[ $tab ] ?? $tab ); ?></h2>
            <?php if ( $is_here ) : ?>
                <p class="jpkpf-sr-detected"><?php esc_html_e( 'This server was detected on this site.', 'jpkcom-post-filter' ); ?></p>
            <?php endif; ?>
            <?php if ( $tab === 'nginx' ) : ?>
                <p class="description">
                    <?php
                    $variant_label = $variant === 'ispconfig' ? __( 'ISPConfig', 'jpkcom-post-filter' ) : __( 'Standard', 'jpkcom-post-filter' );
                    printf(
                        /* translators: %s: variant name, "Standard" or "ISPConfig" */
                        esc_html__( 'Variant: %s. Change it in the rate limit form above.', 'jpkcom-post-filter' ),
                        esc_html( $variant_label )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <div class="jpkpf-sr-order">
                <p><strong><?php esc_html_e( 'Order of changes', 'jpkcom-post-filter' ); ?></strong></p>
                <p><?php esc_html_e( 'When tightening (fewer terms per group, fewer filter groups, strict URLs on): change the plugin settings first and purge the page cache, then update the server rules.', 'jpkcom-post-filter' ); ?></p>
                <p><?php esc_html_e( 'When loosening (more terms per group, more filter groups, strict URLs off, another endpoint or archive): update the server rules first, then the plugin settings. The other way round the server rejects filter pages the plugin already serves.', 'jpkcom-post-filter' ); ?></p>
            </div>

            <?php
            if ( $tab === 'htaccess' ) {
                jpkcom_postfilter_server_rules_render_htaccess_box( inputs: $inputs );
            }

            foreach ( $snippets as $snippet ) {
                ?>
                <div class="jpkpf-sr-snippet">
                    <h3><?php echo esc_html( $snippet['title'] ); ?></h3>
                    <?php if ( $snippet['context'] !== '' ) : ?>
                        <p><?php echo esc_html( $snippet['context'] ); ?></p>
                    <?php endif; ?>
                    <?php
                    if ( $snippet['code'] !== '' ) {
                        jpkcom_postfilter_server_rules_render_code( id: $snippet['id'], code: $snippet['code'] );
                    }
                    ?>
                </div>
                <?php
            }
            ?>

            <div class="jpkpf-sr-snippet">
                <h3><?php esc_html_e( 'Check after installing', 'jpkcom-post-filter' ); ?></h3>
                <p><?php esc_html_e( 'Run these commands on any computer with curl. Judge by the value of X-Jpkpf-Reject, not by the status code: WordPress answers the same URLs with its own 410, so only "server-..." proves the server rule. The "Check now" button above runs the same probes without the rate limit.', 'jpkcom-post-filter' ); ?></p>
                <?php jpkcom_postfilter_server_rules_render_code( id: $tab . '-check', code: jpkcom_postfilter_server_check_commands( server: $tab, inputs: $inputs ) ); ?>
            </div>

            <div class="jpkpf-sr-snippet">
                <h3><?php esc_html_e( 'Page cache', 'jpkcom-post-filter' ); ?></h3>
                <p><?php esc_html_e( 'A page cache (caching plugin, Varnish, CDN) may still hold filter pages from before a change. Purge it after every change of the plugin settings or the server rules. The server\'s reject is sent with Cache-Control: no-store and is not cached itself. Rate-limited filter URLs that a cache answers never reach the server limit, which is intended.', 'jpkcom-post-filter' ); ?></p>
            </div>
        </div>
        <?php
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_page_server_rules' ) ) {
    /**
     * Render: Server Rules page
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_page_server_rules(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_settings_error( 'jpkcom_postfilter_server_rules_messages', 'saved', __( 'Rate limit values saved. The snippets below use them.', 'jpkcom-post-filter' ), 'success' );
        }
        settings_errors( 'jpkcom_postfilter_server_rules_messages' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message key, looked up in a fixed list
        $key     = isset( $_GET['jpkpf_msg'] ) && is_string( $_GET['jpkpf_msg'] ) ? sanitize_key( wp_unslash( $_GET['jpkpf_msg'] ) ) : '';
        $message = $key !== '' ? jpkcom_postfilter_server_rules_message( key: $key ) : null;

        jpkcom_postfilter_settings_memo_reset();
        $inputs   = jpkcom_postfilter_server_rule_inputs();
        $opts     = jpkcom_postfilter_server_rules_options();
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        $detected = jpkcom_postfilter_detect_server( software: $software );
        $initial  = jpkcom_postfilter_server_rules_initial_tab( detected: $detected );
        ?>
        <div class="wrap jpkpf-server-rules">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php
            if ( $message !== null ) {
                printf(
                    '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                    esc_attr( $message['type'] ),
                    esc_html( $message['text'] )
                );
            }
            ?>

            <?php jpkcom_postfilter_server_rules_render_intro(); ?>

            <?php
            jpkcom_postfilter_server_rules_render_head( inputs: $inputs, opts: $opts, software: $software, taxonomies: jpkcom_postfilter_filter_taxonomies() );
            jpkcom_postfilter_server_rules_render_status( last: jpkcom_postfilter_server_check_last() );
            ?>

            <h2><?php esc_html_e( 'Snippets', 'jpkcom-post-filter' ); ?></h2>
            <nav class="jpkpf-tab-nav jpkpf-sr-tabs" data-jpkpf-initial="<?php echo esc_attr( $initial ); ?>">
                <?php foreach ( jpkcom_postfilter_server_rules_tabs() as $id => $label ) : ?>
                    <button type="button" class="jpkpf-tab-btn" data-tab="<?php echo esc_attr( $id ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <?php
            foreach ( array_keys( jpkcom_postfilter_server_rules_tabs() ) as $tab ) {
                jpkcom_postfilter_server_rules_render_tab( tab: $tab, inputs: $inputs, opts: $opts, detected: $detected );
            }
            ?>
        </div>
        <?php
    }
}


// ---------------------------------------------------------------------------
// admin-post handlers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_redirect' ) ) {
    /**
     * Back to the page with a message key, then stop
     *
     * @since 1.6.0
     *
     * @param string $message Message key.
     * @param string $tab     Tab to open, '' for none.
     * @return never
     */
    function jpkcom_postfilter_server_rules_redirect( string $message, string $tab = '' ): never {
        $args = [ 'page' => 'jpkcom-postfilter-server-rules' ];
        if ( $message !== '' ) {
            $args['jpkpf_msg'] = $message;
        }
        $url = add_query_arg( $args, admin_url( 'admin.php' ) );
        if ( $tab !== '' ) {
            $url .= '#' . $tab;
        }

        wp_safe_redirect( $url );
        exit;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_handle_htaccess' ) ) {
    /**
     * admin_post_jpkcom_postfilter_htaccess: Set up / Remove
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_server_rules_handle_htaccess(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'jpkcom-post-filter' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'jpkcom_postfilter_htaccess' );

        $do = isset( $_POST['do'] ) && is_string( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

        jpkcom_postfilter_server_rules_redirect( message: jpkcom_postfilter_server_rules_htaccess_action( do: $do ), tab: 'htaccess' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_handle_check' ) ) {
    /**
     * admin_post_jpkcom_postfilter_server_check: "Check now"
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_server_rules_handle_check(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'jpkcom-post-filter' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'jpkcom_postfilter_server_check' );

        jpkcom_postfilter_server_check_run();

        jpkcom_postfilter_server_rules_redirect( message: 'checked' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_removable_args' ) ) {
    /**
     * removable_query_args: drop `jpkpf_msg` from the address bar
     *
     * WordPress removes these from the displayed URL after load, so the
     * notice does not come back on a reload or with the next form's referer.
     *
     * @since 1.6.0
     *
     * @param string[] $args Query args WordPress removes.
     * @return string[]
     */
    function jpkcom_postfilter_server_rules_removable_args( array $args ): array {
        $args[] = 'jpkpf_msg';
        return $args;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rules_autoload' ) ) {
    /**
     * wp_default_autoload_value: never autoload the rate-limit option
     *
     * options.php adds a new option without an autoload argument; this keeps
     * it out of the autoloaded set (it is read on the Server Rules page only).
     *
     * @since 1.6.0
     *
     * @param bool|null $autoload Autoload decision so far.
     * @param string    $option   Option name.
     * @return bool|null
     */
    function jpkcom_postfilter_server_rules_autoload( mixed $autoload, string $option = '' ): mixed {
        return $option === 'jpkcom_postfilter_server_rules' ? false : $autoload;
    }
}


// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

// After the other sub-pages (admin-pages.php registers them on priority 20).
add_action( 'admin_menu', static function (): void {
    $GLOBALS['jpkcom_postfilter_server_rules_hook'] = add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Server Rules', 'jpkcom-post-filter' ),
        menu_title: __( 'Server Rules', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-server-rules',
        callback: 'jpkcom_postfilter_page_server_rules'
    );
}, 21 );

add_action( 'admin_init', static function (): void {
    register_setting(
        option_group: 'jpkcom_postfilter_server_rules',
        option_name: 'jpkcom_postfilter_server_rules',
        args: [
            'type'              => 'array',
            'sanitize_callback' => 'jpkcom_postfilter_server_rules_sanitize_options',
            'default'           => [ 'workers' => 8, 'render_seconds' => 0.4, 'nginx_variant' => '' ],
            'show_in_rest'      => false,
        ]
    );
} );

add_filter( 'wp_default_autoload_value', 'jpkcom_postfilter_server_rules_autoload', 10, 2 );
add_filter( 'removable_query_args', 'jpkcom_postfilter_server_rules_removable_args', 10, 1 );

add_action( 'admin_post_jpkcom_postfilter_htaccess', 'jpkcom_postfilter_server_rules_handle_htaccess', 10, 0 );
add_action( 'admin_post_jpkcom_postfilter_server_check', 'jpkcom_postfilter_server_rules_handle_check', 10, 0 );
