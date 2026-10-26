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
     * Option `jpkcom_postfilter_htaccess_managed`: '1' or missing, never
     * autoloaded.
     *
     * @since 1.6.0
     *
     * @return bool
     */
    function jpkcom_postfilter_htaccess_is_managed(): bool {
        return get_option( 'jpkcom_postfilter_htaccess_managed', '' ) === '1';
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
     * Does the web server read .htaccess at all?
     *
     * Apache and LiteSpeed (OpenLiteSpeed too, after a restart), detected from
     * SERVER_SOFTWARE as jpkcom_postfilter_htaccess_available() does. On nginx,
     * Caddy, Ferron or an unknown server a leftover managed block is inert
     * (ruling R17).
     *
     * @since 1.6.0
     *
     * @return bool
     */
    function jpkcom_postfilter_htaccess_server_reads(): bool {
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

        return stripos( $software, 'apache' ) !== false || stripos( $software, 'litespeed' ) !== false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_loosen' ) ) {
    /**
     * Make sure the .htaccess is no stricter than the given inputs
     *
     * True when the block was brought to the inputs, already was there, the
     * file holds no managed block that could be stricter, or the server does
     * not read .htaccess (nothing is written then; the block stays for the
     * Remove action and deactivation). False when a managed block (or markers
     * that no longer pair up) stays as it is on Apache or LiteSpeed.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs Rule inputs (usually a union).
     * @return array{ok: bool, reason: string}
     */
    function jpkcom_postfilter_htaccess_loosen( array $inputs ): array {
        $file      = jpkcom_postfilter_htaccess_file();
        $available = jpkcom_postfilter_htaccess_available();

        if ( $available['ok'] && jpkcom_postfilter_htaccess_apply( file: $file, inputs: $inputs ) ) {
            return [ 'ok' => true, 'reason' => '' ];
        }

        // Ruling R17: a block the server never reads cannot be stricter than the plugin.
        if ( ! jpkcom_postfilter_htaccess_server_reads() ) {
            return [ 'ok' => true, 'reason' => '' ];
        }

        $state = jpkcom_postfilter_htaccess_state( file: $file );

        if ( $state['damaged'] ) {
            return [ 'ok' => false, 'reason' => 'damaged' ];
        }
        if ( ! $state['managed'] ) {
            return [ 'ok' => true, 'reason' => '' ];
        }
        // Already the block for these inputs (a tightening save on a read-only file).
        if ( $state['hash'] === ( $inputs['hash'] ?? null ) && ! $state['misplaced'] ) {
            return [ 'ok' => true, 'reason' => '' ];
        }

        $reason = $available['ok'] ? 'write-failed' : $available['reason'];

        return [ 'ok' => false, 'reason' => $reason ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_refusal_message' ) ) {
    /**
     * The error shown when a save is refused
     *
     * @since 1.6.0
     *
     * @param string $reason A reason from jpkcom_postfilter_htaccess_available(), `damaged` or `write-failed`.
     * @return string
     */
    function jpkcom_postfilter_htaccess_refusal_message( string $reason ): string {
        $reasons = [
            'not-writable'         => __( 'the file is not writable', 'jpkcom-post-filter' ),
            'no-file'              => __( 'the file does not exist', 'jpkcom-post-filter' ),
            'manual-block'         => __( 'it also contains a manually inserted block', 'jpkcom-post-filter' ),
            'file-mods-disallowed' => __( 'DISALLOW_FILE_MODS is set', 'jpkcom-post-filter' ),
            'multisite'            => __( 'this is a multisite network', 'jpkcom-post-filter' ),
            'litespeed'            => __( 'the server is LiteSpeed', 'jpkcom-post-filter' ),
            'not-apache'           => __( 'the server does not report itself as Apache', 'jpkcom-post-filter' ),
            'damaged'              => __( 'its JPKCom Post Filter markers do not pair up', 'jpkcom-post-filter' ),
        ];
        $why = $reasons[ $reason ] ?? __( 'writing the file failed', 'jpkcom-post-filter' );

        return sprintf(
            /* translators: %s: why the .htaccess could not be changed, e.g. "the file is not writable" */
            __( 'Settings not saved: they allow more filter URLs than the rules in .htaccess, and the .htaccess could not be updated first (%s). The server would otherwise reject pages the plugin serves. Make the .htaccess writable, or remove the managed block on the Server Rules page, then save again.', 'jpkcom-post-filter' ),
            $why
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_prepare_save' ) ) {
    /**
     * Loosen the block for a submitted option value, before it is committed
     *
     * Writes union( current, prospective ) when the block is managed. On
     * failure adds a settings error for `$setting` (default: the messages of
     * the option's admin page) and returns false - the caller must then not
     * save. Inside jpkcom_postfilter_htaccess_without_refusal() it never
     * returns false.
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
        if ( ! jpkcom_postfilter_htaccess_is_managed() ) {
            return true;
        }

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
        $result  = jpkcom_postfilter_htaccess_loosen( inputs: $union );

        if ( $result['ok'] || jpkcom_postfilter_htaccess_without_refusal() ) {
            return true;
        }

        if ( function_exists( function: 'add_settings_error' ) ) {
            $slug = $setting !== '' ? $setting : $option . '_messages';
            add_settings_error( $slug, 'jpkcom_postfilter_htaccess', jpkcom_postfilter_htaccess_refusal_message( reason: $result['reason'] ), 'error' );
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
     * nothing is written and no update_option_* / add_option_* fires, and puts
     * the settings cache file back on the stored value.
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

        return get_option( $option );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_after_settings_saved' ) ) {
    /**
     * jpkcom_postfilter_settings_saved: undo settings_save()'s own cache write
     *
     * jpkcom_postfilter_settings_save() (the import) writes the cache file
     * itself after update_option(); after a refused save that file would hold
     * the values that were just refused.
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
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_after_save' ) ) {
    /**
     * After an option commit: bring the block to the new state
     *
     * Tightens where the save tightened; for the plugin's own options the
     * block is already loose enough. Hooked with 0 arguments.
     *
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_after_save(): void {
        jpkcom_postfilter_settings_memo_reset();
        jpkcom_postfilter_htaccess_sync();
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_maybe_resync' ) ) {
    /**
     * admin_init: sync when the file's hash is not the current one
     *
     * Constants and filters change effective values without an option event.
     * A block below WordPress's (it never takes effect) is moved, too. Only on
     * admin page loads - not on admin-ajax (heartbeat), REST or cron requests
     * (ruling R18). Known limit: a constant that loosens between two requests
     * leaves the block stricter until the next admin page load.
     *
     * @since 1.6.0
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

        if ( ! jpkcom_postfilter_htaccess_is_managed() || ! jpkcom_postfilter_htaccess_available()['ok'] ) {
            return;
        }

        $inputs = jpkcom_postfilter_server_rule_inputs();
        $state  = jpkcom_postfilter_htaccess_state( file: jpkcom_postfilter_htaccess_file() );

        $stale = jpkcom_postfilter_htaccess_block( inputs: $inputs ) === ''
            ? $state['managed']
            : ( $state['hash'] !== $inputs['hash'] || $state['misplaced'] );

        if ( $stale ) {
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
     * @since 1.6.0
     *
     * @return void
     */
    function jpkcom_postfilter_htaccess_lifecycle(): void {
        if ( jpkcom_postfilter_htaccess_is_managed() ) {
            jpkcom_postfilter_htaccess_sync();
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
     * jpkcom_postfilter_htaccess_available() says.
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

        delete_option( 'jpkcom_postfilter_htaccess_managed' );
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
