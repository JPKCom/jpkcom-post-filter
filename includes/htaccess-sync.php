<?php
/**
 * .htaccess sync – keeps the managed block in step with the settings
 *
 * The managed block (includes/htaccess-manager.php) may never be stricter
 * than the plugin, not even for one request: a server that rejects a URL the
 * plugin would answer with 200 or 301 breaks the site. So the block follows
 * every change of the inputs it is built from (spec 7):
 *
 * - A save of the General or Filter Groups page first brings the block to the
 *   UNION of the current and the submitted state - looser than either - and
 *   only then lets WordPress commit the option. After the commit the block is
 *   brought to the new state, which tightens it where the save tightened.
 * - When the block cannot be loosened (file not writable, markers damaged,
 *   .htaccess management no longer available) the save is refused: an error
 *   for the admin, and option, settings cache file and .htaccess all stay as
 *   they were.
 * - One save at a time (1.7.1, T9-d): a lock is held from the loosen step to
 *   the tighten step. A second save of General, Filter Groups or an import
 *   that starts meanwhile is refused - loosening from values the first save
 *   has not committed yet could leave the block stricter than what that save
 *   commits.
 * - Options outside the plugin that move the archive bases (page_for_posts,
 *   permalink_structure) sync after the commit; constants and filters change
 *   the effective values without any option event, so admin_init compares
 *   the hash in the file with the current one.
 *
 * WHY sanitize_option_* ON PRIORITY 5
 * -----------------------------------
 * update_option() runs sanitize_option() first, and the plugin's settings
 * sanitizer (priority 10) writes the settings cache file as a side effect -
 * the plugin reads that file before the database, so from that moment on it
 * runs with the new values. `pre_update_option_*` comes too late. Priority 5
 * loosens the block before the cache file is written.
 *
 * Refusing works through the same filter: it hands on the stored value
 * instead of the submitted one, and a last filter on PHP_INT_MAX hands on
 * exactly the value update_option() compares against (get_option()) and puts
 * the cache file back on the stored value - the sanitizer at 10 has just
 * rewritten it from that value, which is not necessarily byte-identical to
 * what is stored (a partially stored array gains keys). update_option() then
 * sees no change and returns false without a hook (wp-includes/option.php
 * 7.1.3:923-925). A first save goes update_option() -> add_option(), which
 * sanitizes a second time (:1113); a refused first save never gets there.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_is_managed' ) ) {
    /**
     * Has the admin set up the managed .htaccess block?
     *
     * Option `jpkcom_postfilter_htaccess_managed`: the absolute path of the
     * .htaccess resolved at Set up (jpkcom_postfilter_htaccess_file() uses it
     * from then on), '1' as written before that path was stored, or missing.
     * Never autoloaded.
     *
     * @since 1.6.0
     *
     * @return bool
     */
    function jpkcom_postfilter_htaccess_is_managed(): bool {
        $value = get_option( 'jpkcom_postfilter_htaccess_managed', '' );

        return is_string( $value ) && ( $value === '1' || str_ends_with( $value, '/.htaccess' ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_rule_union' ) ) {
    /**
     * Rule inputs that reject only what both of two states reject
     *
     * Strict only when both are; N = 0 (unlimited) when either is 0, else the
     * larger N; the larger group count; every position either state leaves
     * without a taxonomy. A different endpoint, fragment segment or set of
     * bases, a side without groups or a filtered verdict on either side leaves
     * no reject part at all - the patterns of one state say nothing about the
     * paths of the other.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $old As returned by jpkcom_postfilter_server_rule_inputs().
     * @param array<string, mixed> $new Same shape.
     * @return array<string, mixed> Same shape, with its own hash.
     */
    function jpkcom_postfilter_server_rule_union( array $old, array $new ): array {
        $bases_of = static function ( array $inputs ): array {
            $bases = array_values( array_unique( array_map( 'strval', (array) ( $inputs['bases'] ?? [] ) ) ) );
            sort( $bases, SORT_STRING );
            return $bases;
        };

        $same_paths = (string) ( $old['endpoint'] ?? '' ) === (string) ( $new['endpoint'] ?? '' )
            && (string) ( $old['fragment'] ?? '' ) === (string) ( $new['fragment'] ?? '' )
            && $bases_of( $old ) === $bases_of( $new );

        $old_groups = (int) ( $old['groups'] ?? 0 );
        $new_groups = (int) ( $new['groups'] ?? 0 );
        $old_max    = (int) ( $old['max_per_group'] ?? 0 );
        $new_max    = (int) ( $new['max_per_group'] ?? 0 );
        $max        = ( $old_max <= 0 || $new_max <= 0 ) ? 0 : max( $old_max, $new_max );

        $skip = array_values( array_unique( array_map(
            'intval',
            array_merge( (array) ( $old['skip_positions'] ?? [] ), (array) ( $new['skip_positions'] ?? [] ) )
        ) ) );
        sort( $skip );

        $policy_filtered = array_values( array_unique( array_map(
            'strval',
            array_merge( (array) ( $old['policy_filtered'] ?? [] ), (array) ( $new['policy_filtered'] ?? [] ) )
        ) ) );

        $union = [
            'strict'           => ! empty( $old['strict'] ) && ! empty( $new['strict'] ) && $same_paths && $old_groups > 0 && $new_groups > 0,
            'max_per_group'    => $max,
            'groups'           => max( $old_groups, $new_groups ),
            'skip_positions'   => $skip,
            'reject_status'    => (int) ( $new['reject_status'] ?? $old['reject_status'] ?? 0 ),
            'endpoint'         => (string) ( $new['endpoint'] ?? '' ),
            'fragment'         => (string) ( $new['fragment'] ?? '' ),
            'bases'            => $bases_of( $new ),
            'verdict_filtered' => ! empty( $old['verdict_filtered'] ) || ! empty( $new['verdict_filtered'] ),
            'policy_filtered'  => $policy_filtered,
            'hash'             => '',
        ];

        $union['hash'] = jpkcom_postfilter_server_rule_hash( inputs: $union );

        return $union;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_policy_from_raw' ) ) {
    /**
     * The effective value a policy key will have once a raw form value is saved
     *
     * A constant wins (the sanitizer keeps the stored value, the getter reads
     * the constant). Otherwise the raw value, or the default when the form did
     * not send it, through jpkcom_postfilter_policy_sanitize() and the
     * `jpkcom_postfilter_{key}` filter, as jpkcom_postfilter_policy() resolves
     * it. For `max_filters_per_group` anything but a plain non-negative
     * integer counts as 0: the General sanitizer reads it with absint()
     * ("3abc" is 3, "abc" is 0) where policy_sanitize() falls back to the
     * default, and when unclear, the looser reading is the safe one.
     *
     * @since 1.6.0
     *
     * @param string               $key Policy key.
     * @param array<string, mixed> $raw Submitted General form values.
     * @return mixed
     */
    function jpkcom_postfilter_server_policy_from_raw( string $key, array $raw ): mixed {
        if ( jpkcom_postfilter_policy_locked( $key ) ) {
            return jpkcom_postfilter_policy( $key );
        }

        $value = array_key_exists( $key, $raw )
            ? $raw[ $key ]
            : ( jpkcom_postfilter_policy_definitions()[ $key ]['default'] ?? null );

        if ( $key === 'max_filters_per_group' ) {
            $plain = is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) );
            $value = $plain ? (int) $value : 0;
        }

        $value = jpkcom_postfilter_policy_sanitize( $key, $value );

        /** This filter is documented in includes/url-policy.php */
        $value = apply_filters( 'jpkcom_postfilter_' . $key, $value );

        return jpkcom_postfilter_policy_sanitize( $key, $value );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_server_inputs_from_raw' ) ) {
    /**
     * The rule inputs a submitted, not yet sanitized option value will lead to
     *
     * Starts from the current inputs and replaces what the option decides.
     * Never calls the settings sanitizers (they write the settings cache
     * file); it reads the raw value the way they and the getters will:
     *
     * - General: strict URLs, N and reject status as
     *   jpkcom_postfilter_server_policy_from_raw() resolves them, the endpoint
     *   through sanitize_title() like the sanitizer, the archive bases of the
     *   submitted post types.
     * - Filter Groups: the enabled groups in `order`, their taxonomies (an empty
     *   one is dropped where the plugin's sanitizer will run and becomes a
     *   position without taxonomy where it will not) and their post types.
     *
     * With `$sanitized` false the value is read the way the getters read a
     * stored value (a missing post type list is the default `post`, a group
     * without taxonomy keeps its position) - that is how the stored value of
     * the option is turned into inputs, too.
     *
     * @since 1.6.0
     *
     * @param string    $option    `jpkcom_postfilter_general` or `jpkcom_postfilter_filter_groups`.
     * @param mixed     $raw       Value as handed to update_option().
     * @param bool|null $sanitized Will the plugin's settings sanitizer process `$raw`? Null: whether it is hooked.
     * @return array<string, mixed> Same shape as jpkcom_postfilter_server_rule_inputs().
     */
    function jpkcom_postfilter_server_inputs_from_raw( string $option, mixed $raw, ?bool $sanitized = null ): array {
        $inputs = jpkcom_postfilter_server_rule_inputs();
        $raw    = is_array( $raw ) ? $raw : [];

        if ( $sanitized === null ) {
            $sanitizer = 'jpkcom_postfilter_sanitize_' . substr( $option, strlen( 'jpkcom_postfilter_' ) ) . '_settings';
            $sanitized = has_filter( 'sanitize_option_' . $option, $sanitizer ) !== false;
        }

        // Post type slugs out of whatever was submitted: scalars only.
        $slugs = static fn( mixed $list ): array => array_map(
            static fn( mixed $pt ): string => sanitize_key( (string) $pt ),
            array_filter( (array) $list, 'is_scalar' )
        );

        $enabled_post_types = $slugs( jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] ) );
        $groups             = jpkcom_postfilter_get_filter_groups_enabled();

        if ( $option === 'jpkcom_postfilter_general' ) {
            $inputs['strict']        = (bool) jpkcom_postfilter_server_policy_from_raw( key: 'strict_urls', raw: $raw );
            $inputs['max_per_group'] = (int) jpkcom_postfilter_server_policy_from_raw( key: 'max_filters_per_group', raw: $raw );
            $inputs['reject_status'] = (int) jpkcom_postfilter_server_policy_from_raw( key: 'reject_status', raw: $raw );

            $endpoint = is_scalar( $raw['url_endpoint'] ?? null ) ? (string) $raw['url_endpoint'] : JPKCOM_POSTFILTER_URL_ENDPOINT;
            $endpoint = sanitize_key( sanitize_title( $endpoint ) );
            $inputs['endpoint'] = $endpoint !== '' ? $endpoint : sanitize_key( JPKCOM_POSTFILTER_URL_ENDPOINT );

            if ( isset( $raw['enabled_post_types'] ) && is_array( $raw['enabled_post_types'] ) ) {
                $enabled_post_types = $slugs( $raw['enabled_post_types'] );
                if ( $sanitized ) {
                    $public             = get_post_types( [ 'public' => true ] );
                    $enabled_post_types = array_filter( $enabled_post_types, static fn( string $pt ): bool => isset( $public[ $pt ] ) );
                }
            } elseif ( $sanitized ) {
                $enabled_post_types = [];   // the sanitizer reads a missing checkbox list as "none"
            } else {
                $enabled_post_types = [ 'post' ];   // stored without the key: the getter's default
            }
        } elseif ( $option === 'jpkcom_postfilter_filter_groups' ) {
            $groups = [];
            foreach ( (array) ( $raw['groups'] ?? [] ) as $group ) {
                if ( ! is_array( $group ) || ! (bool) ( $group['enabled'] ?? true ) ) {
                    continue;
                }
                $taxonomy = is_scalar( $group['taxonomy'] ?? null ) ? (string) $group['taxonomy'] : '';
                $taxonomy = sanitize_key( $taxonomy );
                if ( $taxonomy === '' && $sanitized ) {
                    continue;
                }
                $group['taxonomy'] = $taxonomy;
                $groups[]          = $group;
            }
            usort( $groups, static fn( array $a, array $b ): int => (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) );

            $inputs['groups']         = count( $groups );
            $inputs['skip_positions'] = [];
            foreach ( array_values( $groups ) as $position => $group ) {
                if ( $group['taxonomy'] === '' ) {
                    $inputs['skip_positions'][] = $position;
                }
            }
        }

        // Same list as jpkcom_postfilter_routable_post_types(), from the prospective values.
        $post_types = $enabled_post_types;
        foreach ( $groups as $group ) {
            $post_types = array_merge( $post_types, $slugs( $group['post_types'] ?? [] ) );
        }
        $inputs['bases'] = jpkcom_postfilter_server_rule_bases( post_types: array_values( array_unique( array_filter( $post_types ) ) ) );

        $inputs['hash'] = jpkcom_postfilter_server_rule_hash( inputs: $inputs );

        return $inputs;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_apply' ) ) {
    /**
     * Bring the managed block in the file to the given inputs
     *
     * Writes the block, or removes it when the inputs have no reject part
     * (strict URLs off, a filtered verdict, ...): an empty managed block has
     * nothing to say. The option `jpkcom_postfilter_htaccess_managed` stays
     * either way, so the block comes back when there is a reject part again.
     *
     * @since 1.6.0
     *
     * @param string               $file   Path of the .htaccess.
     * @param array<string, mixed> $inputs Rule inputs.
     * @return bool True when the file holds exactly that afterwards.
     */
    function jpkcom_postfilter_htaccess_apply( string $file, array $inputs ): bool {
        $block = jpkcom_postfilter_htaccess_block( inputs: $inputs );

        if ( $block === '' ) {
            return jpkcom_postfilter_htaccess_remove( file: $file );
        }

        return jpkcom_postfilter_htaccess_write( file: $file, block: $block );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_sync' ) ) {
    /**
     * Write the managed block for the given (default: current) inputs
     *
     * Only when the admin set the block up and the plugin may manage the
     * .htaccess on this site. Reads the current inputs after
     * jpkcom_postfilter_settings_memo_reset(), so a value saved earlier in the
     * same request is seen.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed>|null $inputs Rule inputs, null for the current ones.
     * @return bool True when the block was brought to the inputs.
     */
    function jpkcom_postfilter_htaccess_sync( ?array $inputs = null ): bool {
        if ( ! jpkcom_postfilter_htaccess_is_managed() || ! jpkcom_postfilter_htaccess_available()['ok'] ) {
            return false;
        }

        if ( $inputs === null ) {
            jpkcom_postfilter_settings_memo_reset();
            $inputs = jpkcom_postfilter_server_rule_inputs();
        }

        return jpkcom_postfilter_htaccess_apply( file: jpkcom_postfilter_htaccess_file(), inputs: $inputs );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_without_refusal' ) ) {
    /**
     * Run a callback during which a loosening save is never refused
     *
     * For the 1.6.0 migration (jpkcom_postfilter_migrate_pin_per_group_cap()):
     * it must not be lost because the .htaccess is read-only at that moment -
     * the version marker is updated right after it, so it would never run
     * again. The block is still loosened when it can be, and the version-change
     * sync and the admin_init hash comparison try again afterwards.
     *
     * Without an argument: whether such a callback is running.
     *
     * @since 1.6.0
     *
     * @param callable|null $callback Callback to run.
     * @return mixed The callback's return value, or the flag.
     */
    function jpkcom_postfilter_htaccess_without_refusal( ?callable $callback = null ): mixed {
        static $depth = 0;

        if ( $callback === null ) {
            return $depth > 0;
        }

        $depth++;
        try {
            return $callback();
        } finally {
            $depth--;
        }
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_refused' ) ) {
    /**
     * Was the last save of an option refused in this request?
     *
     * @since 1.6.0
     *
     * @param string    $option Option name.
     * @param bool|null $set    New state, or null to read.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_refused( string $option, ?bool $set = null ): bool {
        static $refused = [];

        if ( $set !== null ) {
            $refused[ $option ] = $set;
        }

        return $refused[ $option ] ?? false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_server_reads' ) ) {
    /**
     * May the web server read .htaccess?
     *
     * False only when SERVER_SOFTWARE positively names a server that never
     * reads it - nginx, Caddy, FrankenPHP, Ferron: a leftover managed block is
     * inert there. Everything else counts as reading it (ruling R22, which
     * replaces R17's "unknown server"): an empty SERVER_SOFTWARE is what
     * php-cli under WP-CLI or a system cron has on an Apache site, and when
     * in doubt a block has to count as active.
     *
     * @since 1.6.0
     *
     * @return bool
     */
    function jpkcom_postfilter_htaccess_server_reads(): bool {
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : '';

        foreach ( [ 'nginx', 'caddy', 'frankenphp', 'ferron' ] as $ignores ) {
            if ( str_contains( $software, $ignores ) ) {
                return false;
            }
        }

        return true;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_as_strict_as' ) ) {
    /**
     * Is the block in the file exactly as strict as the given inputs?
     *
     * The `# rule` hash of the block against the hash of the inputs, with the
     * reject status left out of the comparison (ruling R20): a block that
     * answers 410 where the settings now say 404 rejects the same URLs, so it
     * cannot be stricter. A block below WordPress's never counts.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $state  As returned by jpkcom_postfilter_htaccess_state().
     * @param array<string, mixed> $inputs Rule inputs.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_as_strict_as( array $state, array $inputs ): bool {
        if ( ! $state['managed'] || $state['hash'] === null || $state['misplaced'] ) {
            return false;
        }

        return jpkcom_postfilter_htaccess_hash_rejects_as( hash: $state['hash'], inputs: $inputs );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_hash_rejects_as' ) ) {
    /**
     * Does a rule hash stand for the same rejects as the given inputs?
     *
     * The hash of the inputs, with the reject status left out of the
     * comparison (ruling R20): 404 and 410 reject the same URLs.
     *
     * @since 1.7.1
     *
     * @param string               $hash   A rule hash (12 hex characters).
     * @param array<string, mixed> $inputs Rule inputs.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_hash_rejects_as( string $hash, array $inputs ): bool {
        $statuses = array_unique( [ (int) ( $inputs['reject_status'] ?? 0 ), 404, 410 ] );
        // The status shapes no pattern: decide the dropped ones once.
        $dropped = jpkcom_postfilter_server_patterns_dropped( inputs: $inputs );
        foreach ( $statuses as $status ) {
            $inputs['reject_status'] = $status;
            if ( jpkcom_postfilter_server_rule_hash( inputs: $inputs, dropped: $dropped ) === $hash ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_loosen' ) ) {
    /**
     * Make sure the .htaccess is no stricter than the given inputs
     *
     * Writing a looser block, like removing one, is always safe, so it is
     * attempted whenever the file holds a managed block and is writable -
     * whatever jpkcom_postfilter_htaccess_available() says and whatever
     * SERVER_SOFTWARE names (empty under WP-CLI). Where the plugin may manage
     * the file, a missing block is written, too.
     *
     * True when the block was brought to the inputs, already is as strict as
     * them, the file holds no managed block that could be stricter, or the
     * server positively does not read .htaccess (nothing more is done; the
     * block stays for the Remove action and deactivation). False, with a
     * reason, when a managed block (or markers that no longer pair up) stays
     * stricter on a server that may read it (ruling R22).
     *
     * An orphaned block (managed markers in the file, option gone - e.g. a
     * deactivation that could not remove it) is not brought to the inputs but
     * removed: nothing would keep it in step afterwards.
     *
     * A write that failed half way and could not be undone
     * (jpkcom_postfilter_htaccess_last_error() `rollback-failed`) is reported
     * as `write-unrestored` ahead of every other reason: the file may be
     * incomplete, and the admin has to check it, not just retry (T8-a).
     *
     * A file that exists but cannot be read (T9-e) holds a block nobody can
     * see, possibly stricter than anything: `not-readable`, like damaged
     * markers - unless `$current` is given and the inputs reject exactly what
     * it rejects (the save loosens nothing, so whatever the block is, it is
     * not made stricter than the plugin by this save).
     *
     * @since 1.6.0
     *
     * @param array<string, mixed>      $inputs  Rule inputs (usually a union).
     * @param bool                      $orphan  The block is in the file without the managed option.
     * @param array<string, mixed>|null $current Inputs the plugin runs with before the save (1.7.1).
     * @return array{ok: bool, reason: string} Reason: `write-unrestored`, `damaged`, `not-readable`, `manual-block`, `not-writable` or `write-failed`.
     */
    function jpkcom_postfilter_htaccess_loosen( array $inputs, bool $orphan = false, ?array $current = null ): array {
        $ok    = [ 'ok' => true, 'reason' => '' ];
        $file  = jpkcom_postfilter_htaccess_file();
        $state = jpkcom_postfilter_htaccess_state( file: $file );

        // apply() can fail without reaching modify(); no earlier failure may count.
        jpkcom_postfilter_htaccess_last_error( set: '' );

        if ( $orphan ) {
            if ( jpkcom_postfilter_htaccess_remove( file: $file ) ) {
                return $ok;
            }
        } elseif ( ( $state['managed'] && $state['writable'] ) || jpkcom_postfilter_htaccess_available()['ok'] ) {
            if ( jpkcom_postfilter_htaccess_apply( file: $file, inputs: $inputs ) ) {
                return $ok;
            }
        }
        $unrestored = jpkcom_postfilter_htaccess_last_error() === 'rollback-failed';

        // A block on a server that never reads it cannot be stricter than the plugin.
        if ( ! jpkcom_postfilter_htaccess_server_reads() ) {
            return $ok;
        }

        if ( $unrestored ) {
            return [ 'ok' => false, 'reason' => 'write-unrestored' ];
        }

        $state = jpkcom_postfilter_htaccess_state( file: $file );

        if ( $state['damaged'] ) {
            return [ 'ok' => false, 'reason' => 'damaged' ];
        }
        if ( $state['exists'] && ! $state['readable'] ) {
            $loosens = $current === null || ! jpkcom_postfilter_htaccess_hash_rejects_as( hash: (string) ( $current['hash'] ?? '' ), inputs: $inputs );
            return $loosens ? [ 'ok' => false, 'reason' => 'not-readable' ] : $ok;
        }
        if ( ! $state['managed'] ) {
            return $ok;
        }
        // Already as strict as these inputs (a tightening save on a read-only file).
        if ( jpkcom_postfilter_htaccess_as_strict_as( state: $state, inputs: $inputs ) ) {
            return $ok;
        }

        if ( $state['manual'] ) {
            $reason = 'manual-block';
        } elseif ( ! $state['writable'] ) {
            $reason = 'not-writable';
        } else {
            $reason = 'write-failed';
        }

        return [ 'ok' => false, 'reason' => $reason ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_refusal_message' ) ) {
    /**
     * The error shown when a save is refused
     *
     * @since 1.6.0
     *
     * The sentence that says what to do depends on the reason: damaged
     * markers are fixed by hand, a manual block next to the managed one is
     * deleted, a read-only file is made writable, an unreadable one readable.
     *
     * @param string $reason `busy`, `write-unrestored`, `damaged`, `not-readable`, `manual-block`, `not-writable`, `write-failed`, or a reason from jpkcom_postfilter_htaccess_available().
     * @return string
     */
    function jpkcom_postfilter_htaccess_refusal_message( string $reason ): string {
        if ( $reason === 'busy' ) {
            return __( 'Settings not saved: another save of the JPKCom Post Filter settings is updating the .htaccess rules at this moment, and two saves at once could leave the rules stricter than the plugin. Wait a few seconds, then save again.', 'jpkcom-post-filter' );
        }

        $reasons = [
            'write-unrestored'     => __( 'writing the file failed, and its previous content could not be put back', 'jpkcom-post-filter' ),
            'not-writable'         => __( 'the file is not writable', 'jpkcom-post-filter' ),
            'not-readable'         => __( 'the file is not readable', 'jpkcom-post-filter' ),
            'no-file'              => __( 'the file does not exist', 'jpkcom-post-filter' ),
            'manual-block'         => __( 'it also contains a manually inserted block', 'jpkcom-post-filter' ),
            'file-mods-disallowed' => __( 'DISALLOW_FILE_MODS is set', 'jpkcom-post-filter' ),
            'multisite'            => __( 'this is a multisite network', 'jpkcom-post-filter' ),
            'litespeed'            => __( 'the server is LiteSpeed', 'jpkcom-post-filter' ),
            'not-apache'           => __( 'the server does not report itself as Apache', 'jpkcom-post-filter' ),
            'damaged'              => __( 'its JPKCom Post Filter markers do not pair up', 'jpkcom-post-filter' ),
        ];
        $why = $reasons[ $reason ] ?? __( 'writing the file failed', 'jpkcom-post-filter' );

        // What fixes it depends on the reason: chmod does not repair markers (D3).
        $remedy = match ( $reason ) {
            'write-unrestored' => __( 'The .htaccess may be incomplete: check it now and repair it by hand, or restore it from a backup - while Apache cannot parse it, it answers every request with an error. Then save again.', 'jpkcom-post-filter' ),
            'damaged'          => __( 'Fix the file by hand: every # BEGIN JPKCom Post Filter line needs its own # END JPKCom Post Filter line, and WordPress\'s block (# BEGIN WordPress to # END WordPress) must stay outside it. Or delete the JPKCom Post Filter lines. Then save again.', 'jpkcom-post-filter' ),
            'manual-block'     => __( 'Delete one of the two blocks - the one inserted by hand (# BEGIN JPKCom Post Filter (manual)) or the managed one - then save again.', 'jpkcom-post-filter' ),
            'not-readable'     => __( 'Make the .htaccess readable and writable for the web server user, or remove the JPKCom Post Filter block from it, then save again.', 'jpkcom-post-filter' ),
            'not-writable', 'no-file' => __( 'Make the .htaccess writable, or remove the JPKCom Post Filter block from it, then save again.', 'jpkcom-post-filter' ),
            default            => __( 'Check the .htaccess (permissions, free disk space), or remove the JPKCom Post Filter block from it, then save again.', 'jpkcom-post-filter' ),
        };

        $message = sprintf(
            /* translators: %s: why the .htaccess could not be changed, e.g. "the file is not writable" */
            __( 'Settings not saved: the JPKCom Post Filter rules in .htaccess could not be updated to match the new settings (%s), and with the old rules the server could reject filter pages the plugin serves.', 'jpkcom-post-filter' ),
            $why
        );

        return $message . ' ' . $remedy;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_request_id' ) ) {
    /**
     * A token for this request, to tell its save lock from another request's
     *
     * @since 1.7.1
     *
     * @return string 16 hex characters, the same for the whole request.
     */
    function jpkcom_postfilter_htaccess_request_id(): string {
        static $id = '';

        if ( $id === '' ) {
            $id = bin2hex( random_bytes( 8 ) );
        }

        return $id;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_save_lock_row' ) ) {
    /**
     * The save lock as it is in the database: [ row value, since, owner ]
     *
     * Read past the options cache - another request writes the row, and a
     * cached "no such option" from earlier in this request would hide it.
     *
     * @since 1.7.1
     *
     * @return array{0: string, 1: int, 2: string}|null Null when nobody holds it (or there is no database).
     */
    function jpkcom_postfilter_htaccess_save_lock_row(): ?array {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name only
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", 'jpkcom_postfilter_htaccess_save_lock' ) );
        if ( ! is_string( $value ) ) {
            return null;
        }

        $parts = explode( '|', $value, 2 );

        return [ $value, (int) $parts[0], $parts[1] ?? '' ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_save_lock_taken' ) ) {
    /**
     * Has this request taken the save lock (and not released it yet)?
     *
     * Keeps jpkcom_postfilter_htaccess_save_unlock() from sending a query on
     * every option event of a request that never took it.
     *
     * @since 1.7.1
     *
     * @param bool|null $set New state, or null to read.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_save_lock_taken( ?bool $set = null ): bool {
        static $taken = false;

        if ( $set !== null ) {
            $taken = $set;
        }

        return $taken;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_save_lock' ) ) {
    /**
     * Take the save lock, held from the loosen step to the tighten step (T9-d)
     *
     * Two saves at once break "never stricter": B loosens from the values A
     * has not committed yet and can write a block stricter than what A then
     * commits. So one save at a time - for a managed block only; a site
     * without one sends no query.
     *
     * The option `jpkcom_postfilter_htaccess_save_lock` holds
     * "<time>|<request id>", inserted with INSERT IGNORE like core's
     * WP_Upgrader::create_lock(): the row either goes in or it is there
     * already, nothing in between. The request holding it may take it again
     * (add_option() sanitizes a second time, an import saves two options).
     * Released after the tighten (jpkcom_postfilter_htaccess_after_save()),
     * on a refusal, after an import, and at the latest by a shutdown function
     * - which PHP runs after a fatal error, too. A lock older than a minute
     * belongs to a request that died without even that (killed); it is
     * taken over, atomically, so two takers cannot both win.
     *
     * Without $wpdb (never the case in WordPress) there is nothing to lock
     * with, and the save goes on.
     *
     * @since 1.7.1
     *
     * @return bool False when another request holds it.
     */
    function jpkcom_postfilter_htaccess_save_lock(): bool {
        global $wpdb;
        static $shutdown = false;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return true;
        }

        $name  = 'jpkcom_postfilter_htaccess_save_lock';
        $mine  = jpkcom_postfilter_htaccess_request_id();
        $now   = time();
        $value = $now . '|' . $mine;
        $taken = false;

        // Twice: the holder may release it between the INSERT and the SELECT.
        for ( $try = 0; $try < 2 && ! $taken; $try++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name only
            if ( (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'off' )", $name, $value ) ) === 1 ) {
                $taken = true;
                break;
            }

            $row = jpkcom_postfilter_htaccess_save_lock_row();
            if ( $row === null ) {
                continue;
            }
            [ $held, $since, $owner ] = $row;

            if ( $owner === $mine ) {
                jpkcom_postfilter_htaccess_save_lock_taken( set: true );
                return true;
            }
            if ( $since > $now - 60 ) {
                return false;
            }

            // Stale: replace exactly that value, so of two takers only one wins.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name only
            $taken = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", $value, $name, $held ) ) === 1;
            break;
        }

        if ( $taken ) {
            jpkcom_postfilter_htaccess_save_lock_taken( set: true );
            if ( ! $shutdown ) {
                $shutdown = true;
                register_shutdown_function( 'jpkcom_postfilter_htaccess_save_unlock' );
            }
        }

        return $taken;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_save_unlock' ) ) {
    /**
     * Release the save lock, if this request holds it
     *
     * Deletes exactly the value this request wrote: a lock another request
     * has taken over meanwhile stays.
     *
     * @since 1.7.1
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_save_unlock(): void {
        global $wpdb;

        if ( ! jpkcom_postfilter_htaccess_save_lock_taken() ) {
            return;
        }

        $row = jpkcom_postfilter_htaccess_save_lock_row();
        if ( $row === null ) {
            jpkcom_postfilter_htaccess_save_lock_taken( set: false );
            return;
        }
        if ( $row[2] !== jpkcom_postfilter_htaccess_request_id() ) {
            return;
        }
        jpkcom_postfilter_htaccess_save_lock_taken( set: false );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name only
        $wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s", 'jpkcom_postfilter_htaccess_save_lock', $row[0] ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_save_locked_elsewhere' ) ) {
    /**
     * Is another request between its loosen and its tighten right now?
     *
     * @since 1.7.1
     *
     * @return bool
     */
    function jpkcom_postfilter_htaccess_save_locked_elsewhere(): bool {
        $row = jpkcom_postfilter_htaccess_save_lock_row();

        return $row !== null && $row[2] !== jpkcom_postfilter_htaccess_request_id() && $row[1] > time() - 60;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_prepare_save' ) ) {
    /**
     * Loosen the block for a submitted option value, before it is committed
     *
     * Writes union( current, prospective ) when the block is managed, and
     * removes an orphaned managed block (markers in the file without the
     * option). On failure adds a settings error for `$setting` (default: the
     * messages of the option's admin page), a warning under WP-CLI - or,
     * otherwise outside wp-admin, a debug log line - and returns false: the
     * caller must then not save. Inside
     * jpkcom_postfilter_htaccess_without_refusal() it never returns false.
     *
     * First it takes the save lock (jpkcom_postfilter_htaccess_save_lock());
     * while another request holds it, the save is refused with reason `busy`
     * and nothing is written (T9-d).
     *
     * @since 1.6.0
     *
     * @param string $option  Option name.
     * @param mixed  $raw     Submitted value.
     * @param string $setting Settings error slug, '' for "{$option}_messages".
     * @return bool True when the value may be saved.
     */
    function jpkcom_postfilter_htaccess_prepare_save( string $option, mixed $raw, string $setting = '' ): bool {
        // Layout and cache settings do not shape the rule.
        if ( ! in_array( $option, [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ], true ) ) {
            return true;
        }
        // Managed: the option, or managed markers in the file without it (a
        // removal that failed must not leave a block nothing guards).
        $managed = jpkcom_postfilter_htaccess_is_managed();
        if ( ! $managed ) {
            $state = jpkcom_postfilter_htaccess_state( file: jpkcom_postfilter_htaccess_file() );
            if ( ! $state['managed'] && ! $state['damaged'] ) {
                return true;
            }
        }

        if ( ! jpkcom_postfilter_htaccess_save_lock() && ! jpkcom_postfilter_htaccess_without_refusal() ) {
            $result = [ 'ok' => false, 'reason' => 'busy' ];
        } else {
            // "Current" three times over: what the plugin runs with (the settings
            // cache file first) and what is stored. They differ during a save - the
            // sanitizer has already rewritten the cache file when add_option()
            // sanitizes a second time (first save, or a stored value equal to the
            // registered default) - and the block must stay loose for both.
            jpkcom_postfilter_settings_memo_reset();
            $current = jpkcom_postfilter_server_rule_union(
                old: jpkcom_postfilter_server_rule_inputs(),
                new: jpkcom_postfilter_server_inputs_from_raw( option: $option, raw: get_option( $option ), sanitized: false )
            );
            $union   = jpkcom_postfilter_server_rule_union(
                old: $current,
                new: jpkcom_postfilter_server_inputs_from_raw( option: $option, raw: $raw )
            );
            $result  = jpkcom_postfilter_htaccess_loosen( inputs: $union, orphan: ! $managed, current: $current );
        }

        if ( $result['ok'] || jpkcom_postfilter_htaccess_without_refusal() ) {
            return true;
        }

        $message = jpkcom_postfilter_htaccess_refusal_message( reason: $result['reason'] );
        if ( function_exists( function: 'add_settings_error' ) ) {
            $slug = $setting !== '' ? $setting : $option . '_messages';
            add_settings_error( $slug, 'jpkcom_postfilter_htaccess', $message, 'error' );
        }
        if ( defined( constant_name: 'WP_CLI' ) && WP_CLI && class_exists( class: 'WP_CLI' ) ) {
            // `wp option update` only says "Could not update option" otherwise.
            WP_CLI::warning( $message );
        } elseif ( ! function_exists( function: 'add_settings_error' ) && function_exists( function: 'jpkcom_postfilter_debug_log' ) ) {
            jpkcom_postfilter_debug_log( $message, [ 'option' => $option, 'reason' => $result['reason'] ] );
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_restore_cache' ) ) {
    /**
     * Put an option's settings cache file back on the stored value
     *
     * What jpkcom_postfilter_settings_get_group() would build from the
     * database: the stored array, or no file when nothing (or an empty array)
     * is stored.
     *
     * @since 1.6.0
     *
     * @param string $option `jpkcom_postfilter_{group}`.
     * @return void
     */
    function jpkcom_postfilter_htaccess_restore_cache( string $option ): void {
        $group  = substr( $option, strlen( 'jpkcom_postfilter_' ) );
        $stored = get_option( $option, null );

        if ( is_array( $stored ) && $stored !== [] ) {
            jpkcom_postfilter_settings_write_file( $group, $stored );
        } else {
            jpkcom_postfilter_settings_delete_file( $group );
        }

        jpkcom_postfilter_settings_memo_reset();
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_before_save' ) ) {
    /**
     * sanitize_option_{option}, priority 5: loosen first, or refuse
     *
     * On refusal the stored value is handed on, so the sanitizer at 10 works
     * on the old state; jpkcom_postfilter_htaccess_settle_refusal() on
     * PHP_INT_MAX finishes the refusal.
     *
     * @since 1.6.0
     *
     * @param mixed  $value  Submitted value.
     * @param string $option Option name.
     * @return mixed
     */
    function jpkcom_postfilter_htaccess_before_save( mixed $value, string $option = '' ): mixed {
        jpkcom_postfilter_htaccess_refused( $option, false );

        if ( jpkcom_postfilter_htaccess_prepare_save( option: $option, raw: $value ) ) {
            return $value;
        }

        jpkcom_postfilter_htaccess_refused( $option, true );

        return get_option( $option );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_settle_refusal' ) ) {
    /**
     * sanitize_option_{option}, priority PHP_INT_MAX: make a refusal a no-op
     *
     * Returns exactly what update_option() compares with (get_option() without
     * a default, i.e. the registered default for an option not stored yet), so
     * nothing is written and no update_option_* / add_option_* fires, puts
     * the settings cache file back on the stored value, and releases the save
     * lock (only if this request holds it - a `busy` refusal never took it).
     *
     * @since 1.6.0
     *
     * @param mixed  $value  Sanitized value.
     * @param string $option Option name.
     * @return mixed
     */
    function jpkcom_postfilter_htaccess_settle_refusal( mixed $value, string $option = '' ): mixed {
        if ( ! jpkcom_postfilter_htaccess_refused( $option ) ) {
            return $value;
        }

        jpkcom_postfilter_htaccess_restore_cache( option: $option );
        jpkcom_postfilter_htaccess_save_unlock();

        return get_option( $option );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_after_settings_saved' ) ) {
    /**
     * jpkcom_postfilter_settings_saved: undo settings_save()'s own cache write
     *
     * jpkcom_postfilter_settings_save() (the import) writes the cache file
     * itself after update_option(); after a refused save that file would hold
     * the values that were just refused. The import of this option is done
     * then, so the save lock goes, too - also when nothing changed and no
     * update_option_* hook released it.
     *
     * @since 1.6.0
     *
     * @param string $group Settings group.
     * @return void
     */
    function jpkcom_postfilter_htaccess_after_settings_saved( string $group ): void {
        $option = 'jpkcom_postfilter_' . $group;

        if ( jpkcom_postfilter_htaccess_refused( $option ) ) {
            jpkcom_postfilter_htaccess_restore_cache( option: $option );
        }

        jpkcom_postfilter_htaccess_save_unlock();
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_after_save' ) ) {
    /**
     * After an option commit: bring the block to the new state
     *
     * Tightens where the save tightened; for the plugin's own options the
     * block is already loose enough. Then the save lock goes (if this request
     * holds it). Hooked with 0 arguments.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_after_save(): void {
        jpkcom_postfilter_settings_memo_reset();
        jpkcom_postfilter_htaccess_sync();
        jpkcom_postfilter_htaccess_save_unlock();
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_maybe_resync' ) ) {
    /**
     * admin_init: sync when the file's hash is not the current one
     *
     * Constants and filters change effective values without an option event.
     * A block below WordPress's (it never takes effect) is moved, too. Only on
     * admin page loads - not on admin-ajax (heartbeat), REST or cron requests
     * (ruling R18), and only for a user who can manage_options (not for an
     * anonymous request to admin-post.php). Known limit: a constant that
     * loosens between two requests leaves the block stricter until the next
     * admin page load. No write while another request is between its loosen
     * and its tighten (T9-d): this request may still read the values before
     * that save, and the save's own tighten writes the current state anyway.
     *
     * Cheapest first, since this runs on every admin page (1.7.1): the site
     * checks before the managed flag (not autoloaded, so a query - T9-b),
     * the file read once, and on an equal hash nothing is rendered (T9-a).
     * The hash still runs the size guard once
     * (jpkcom_postfilter_server_patterns_dropped(), R161-4); rendering the
     * block would run it twice more. Returning on an equal hash is safe
     * because a `# rule` line is only written with a non-empty block, and
     * the same hash means the same rule - as long as a renderer change bumps
     * jpkcom_postfilter_server_renderer_version().
     *
     * @since 1.6.0
     * @since 1.7.1 Site checks before the option read; returns on an equal hash before rendering.
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_maybe_resync(): void {
        $background = ( defined( constant_name: 'DOING_AJAX' ) && DOING_AJAX )
            || ( defined( constant_name: 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( constant_name: 'DOING_CRON' ) && DOING_CRON );

        if ( $background ) {
            return;
        }

        // admin_init also runs on admin-post.php for anonymous requests (core
        // fires it before its login check): only for someone who could change
        // the settings anyway.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( jpkcom_postfilter_htaccess_site_reason() !== '' || ! jpkcom_postfilter_htaccess_is_managed() ) {
            return;
        }

        $state = jpkcom_postfilter_htaccess_state( file: jpkcom_postfilter_htaccess_file() );
        if ( jpkcom_postfilter_htaccess_file_reason( state: $state ) !== '' ) {
            return;
        }

        $inputs = jpkcom_postfilter_server_rule_inputs();
        if ( $state['managed'] && ! $state['misplaced'] && $state['hash'] === $inputs['hash'] ) {
            return;
        }

        // Not equal: stale whenever there is a block to write, and when
        // there is none, only if a managed block is still in the file.
        $stale = jpkcom_postfilter_htaccess_block( inputs: $inputs ) === '' ? $state['managed'] : true;

        if ( $stale && ! jpkcom_postfilter_htaccess_save_locked_elsewhere() ) {
            jpkcom_postfilter_htaccess_sync( inputs: $inputs );
        }
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_lifecycle' ) ) {
    /**
     * Activation and version change
     *
     * Managed: sync (a new version may build other rules from the same
     * settings). Not managed but a managed block is in the file - the plugin
     * was deleted without being deactivated and installed again, say: remove
     * it, it must never stay with outdated limits. Removing only ever loosens,
     * so it does not wait for jpkcom_postfilter_htaccess_available(); a manual
     * block is never touched. Nothing is set up.
     *
     * Managed, but SERVER_SOFTWARE is empty (WP-CLI, a system cron) or names
     * an unknown server that may read .htaccess (R161-3): the block is
     * rewritten only when the new one is provably looser
     * (jpkcom_postfilter_htaccess_provably_looser(): it drops whole reject
     * rules and adds none). A 1.6.0 block with a rule the size guard now
     * drops makes Apache answer 500 for the whole site, so PHP only ever runs
     * under WP-CLI there - and on a server the plugin cannot see, it never
     * tightens. Everything else stays as it was until the next admin page
     * load on Apache. Other reasons of jpkcom_postfilter_htaccess_available()
     * (multisite, DISALLOW_FILE_MODS, LiteSpeed, a manual block) keep their
     * behaviour, and so does a server that positively ignores .htaccess.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_lifecycle(): void {
        if ( jpkcom_postfilter_htaccess_is_managed() ) {
            $available = jpkcom_postfilter_htaccess_available();
            if ( $available['ok'] ) {
                jpkcom_postfilter_htaccess_sync();
            } elseif ( $available['reason'] === 'not-apache' && jpkcom_postfilter_htaccess_server_reads() ) {
                jpkcom_postfilter_settings_memo_reset();
                $block = jpkcom_postfilter_htaccess_block( inputs: jpkcom_postfilter_server_rule_inputs() );
                jpkcom_postfilter_htaccess_loosen_to( file: jpkcom_postfilter_htaccess_file(), block: $block );
            }
            return;
        }

        $file = jpkcom_postfilter_htaccess_file();
        if ( jpkcom_postfilter_htaccess_state( file: $file )['managed'] ) {
            jpkcom_postfilter_htaccess_remove( file: $file );
        }
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_deactivate' ) ) {
    /**
     * Deactivation: remove the managed block and forget it was set up
     *
     * Reactivating sets nothing up again (spec 12, decision 7). Removing only
     * loosens, so it runs whenever the file is writable, whatever
     * jpkcom_postfilter_htaccess_available() says. When the block cannot be
     * removed (read-only file, damaged markers) - or the file cannot even be
     * read, so nobody knows whether a block is there (T9-e) - the option
     * stays, so the block is still guarded after a reactivation.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_deactivate(): void {
        $file = jpkcom_postfilter_htaccess_file();
        if ( jpkcom_postfilter_htaccess_state( file: $file )['managed'] ) {
            jpkcom_postfilter_htaccess_remove( file: $file );
        }

        // Forget it only when it is gone: a block that could not be removed
        // stays managed, so a later loosening save is still refused for it.
        $state = jpkcom_postfilter_htaccess_state( file: $file );
        if ( ! $state['managed'] && ! $state['damaged'] && ( $state['readable'] || ! $state['exists'] ) ) {
            delete_option( 'jpkcom_postfilter_htaccess_managed' );
        }
    }
}


// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $jpkpf_option ) {
    add_filter( "sanitize_option_{$jpkpf_option}", 'jpkcom_postfilter_htaccess_before_save', 5, 2 );
    add_filter( "sanitize_option_{$jpkpf_option}", 'jpkcom_postfilter_htaccess_settle_refusal', PHP_INT_MAX, 2 );
    add_action( "update_option_{$jpkpf_option}", 'jpkcom_postfilter_htaccess_after_save', 20, 0 );
    add_action( "add_option_{$jpkpf_option}", 'jpkcom_postfilter_htaccess_after_save', 20, 0 );
}
unset( $jpkpf_option );

add_action( 'jpkcom_postfilter_settings_saved', 'jpkcom_postfilter_htaccess_after_settings_saved', 10, 1 );

// Archive bases follow the posts page and the permalink structure.
// permalink_structure_changed fires after WP_Rewrite::init() - update_option_*
// comes before it, while $wp_rewrite->front is still the old one.
add_action( 'update_option_page_for_posts', 'jpkcom_postfilter_htaccess_after_save', 20, 0 );
add_action( 'update_option_permalink_structure', 'jpkcom_postfilter_htaccess_after_save', 20, 0 );
add_action( 'permalink_structure_changed', 'jpkcom_postfilter_htaccess_after_save', 20, 0 );

add_action( 'admin_init', 'jpkcom_postfilter_htaccess_maybe_resync', 20, 0 );
