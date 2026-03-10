<?php
/**
 * Settings API + File-Based Settings Cache
 *
 * Manages plugin settings using the WordPress Settings API with a fast
 * file-based cache layer (.ht.jpkcom-post-filter-settings/).
 *
 * Cache read priority:
 *   1. File cache: .ht.jpkcom-post-filter-settings/{group}.php (PHP array, require_once)
 *   2. Fallback: wp_options (DB), then writes the cache file
 *
 * On backend save: wp_options + cache file are updated simultaneously.
 *
 * Security:
 *   - Directory name starts with .ht. (Apache auto-denies direct access)
 *   - .htaccess generated in settings dir (Deny from all)
 *   - Path validated to prevent directory traversal
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Settings directory & .htaccess setup
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_ensure_settings_dir' ) ) {
    /**
     * Create the settings cache directory and write a protective .htaccess
     *
     * @since 1.0.0
     * @return bool True if directory exists and is writable, false otherwise.
     */
    function jpkcom_postfilter_ensure_settings_dir(): bool {
        $dir = JPKCOM_POSTFILTER_SETTINGS_DIR;

        // Security: validate path – must be inside WP_CONTENT_DIR
        if ( ! str_starts_with( realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR, realpath( WP_CONTENT_DIR ) ) ) {
            jpkcom_postfilter_debug_log( 'Settings dir path traversal detected', [ 'dir' => $dir ] );
            return false;
        }

        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            if ( ! mkdir( directory: $dir, permissions: 0750, recursive: true ) ) {
                jpkcom_postfilter_debug_log( 'Failed to create settings cache directory', [ 'dir' => $dir ] );
                return false;
            }
        }

        if ( ! is_writable( $dir ) ) {
            jpkcom_postfilter_debug_log( 'Settings cache directory is not writable', [ 'dir' => $dir ] );
            return false;
        }

        // Write .htaccess to block direct HTTP access
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $htaccess_content = "# JPKCom Post Filter – Settings Cache Directory\n"
                . "# This file is auto-generated. Do not edit.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n";

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( filename: $htaccess, data: $htaccess_content );
        }

        // Write index.php to prevent directory listing
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( filename: $index, data: "<?php // Silence is golden.\n" );
        }

        return true;
    }
}


// ---------------------------------------------------------------------------
// File cache read / write
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_settings_cache_path' ) ) {
    /**
     * Build the file path for a settings cache file
     *
     * @since 1.0.0
     *
     * @param string $group Settings group slug (alphanumeric + dashes only).
     * @return string|null Absolute path to cache file, or null if group name is invalid.
     */
    function jpkcom_postfilter_settings_cache_path( string $group ): ?string {
        // Sanitize group to prevent path traversal
        $safe_group = preg_replace( '/[^a-z0-9\-_]/', '', strtolower( $group ) );
        if ( $safe_group === '' || $safe_group !== $group ) {
            return null;
        }
        return JPKCOM_POSTFILTER_SETTINGS_DIR . '/' . $safe_group . '.php';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_read_file' ) ) {
    /**
     * Read settings from the file cache
     *
     * @since 1.0.0
     *
     * @param string $group Settings group slug.
     * @return array<string, mixed>|null Settings array, or null on cache miss.
     */
    function jpkcom_postfilter_settings_read_file( string $group ): ?array {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            return null;
        }

        // Check settings_cache_enabled directly via get_option() to avoid circular dependency
        // (jpkcom_postfilter_settings_get() would call this function again).
        $cache_opts = get_option( 'jpkcom_postfilter_cache', [] );
        if ( ! (bool) ( is_array( $cache_opts ) ? ( $cache_opts['settings_cache_enabled'] ?? true ) : true ) ) {
            return null;
        }

        $path = jpkcom_postfilter_settings_cache_path( $group );
        if ( $path === null || ! file_exists( $path ) ) {
            return null;
        }

        // Security: ensure file is inside the settings dir
        $real_path = realpath( $path );
        $real_dir  = realpath( JPKCOM_POSTFILTER_SETTINGS_DIR );
        if ( $real_path === false || $real_dir === false || ! str_starts_with( $real_path, $real_dir ) ) {
            jpkcom_postfilter_debug_log( 'Settings file path traversal blocked', [ 'path' => $path ] );
            return null;
        }

        $data = null;
        try {
            // The file returns a PHP array via include
            // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
            $data = include $real_path;
        } catch ( \Throwable $e ) {
            jpkcom_postfilter_debug_log( 'Settings file include error', [ 'group' => $group, 'error' => $e->getMessage() ] );
            return null;
        }

        return is_array( $data ) ? $data : null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_write_file' ) ) {
    /**
     * Write settings to the file cache
     *
     * @since 1.0.0
     *
     * @param string               $group    Settings group slug.
     * @param array<string, mixed> $settings Settings data to cache.
     * @return bool True on success, false on failure.
     */
    function jpkcom_postfilter_settings_write_file( string $group, array $settings ): bool {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            return false;
        }

        if ( ! jpkcom_postfilter_ensure_settings_dir() ) {
            return false;
        }

        $path = jpkcom_postfilter_settings_cache_path( $group );
        if ( $path === null ) {
            return false;
        }

        $export  = var_export( $settings, true );
        $content = "<?php\n"
            . "/**\n"
            . " * JPKCom Post Filter – Settings Cache: {$group}\n"
            . " * Auto-generated on " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
            . " * Do not edit manually.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
            . "return " . $export . ";\n";

        // Write atomically via temp file + rename
        $tmp = $path . '.tmp.' . uniqid( '', true );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents( filename: $tmp, data: $content );

        if ( $written === false ) {
            jpkcom_postfilter_debug_log( 'Failed to write settings temp file', [ 'group' => $group, 'path' => $tmp ] );
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if ( ! rename( from: $tmp, to: $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $tmp );
            jpkcom_postfilter_debug_log( 'Failed to rename settings temp file', [ 'group' => $group ] );
            return false;
        }

        // Invalidate opcache if available
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( filename: $path, force: true );
        }

        jpkcom_postfilter_debug_log( 'Settings cache written', [ 'group' => $group ] );
        return true;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_delete_file' ) ) {
    /**
     * Delete a settings cache file
     *
     * @since 1.0.0
     *
     * @param string $group Settings group slug.
     * @return bool True on success.
     */
    function jpkcom_postfilter_settings_delete_file( string $group ): bool {
        $path = jpkcom_postfilter_settings_cache_path( $group );
        if ( $path === null || ! file_exists( $path ) ) {
            return true;
        }

        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( filename: $path, force: true );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return unlink( $path );
    }
}


// ---------------------------------------------------------------------------
// Unified settings read/write API
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_settings_get' ) ) {
    /**
     * Get a settings value (file cache → wp_options fallback)
     *
     * @since 1.0.0
     *
     * @param string $group   Settings group slug (e.g., 'general', 'layout').
     * @param string $key     Key within the group.
     * @param mixed  $default Default value if not found.
     * @return mixed Setting value or default.
     */
    function jpkcom_postfilter_settings_get( string $group, string $key, mixed $default = null ): mixed {
        $settings = jpkcom_postfilter_settings_get_group( $group );
        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_get_group' ) ) {
    /**
     * Get all settings for a group (file cache → wp_options fallback)
     *
     * @since 1.0.0
     *
     * @param string $group Settings group slug.
     * @return array<string, mixed> All settings for the group.
     */
    function jpkcom_postfilter_settings_get_group( string $group ): array {
        // Try file cache first
        $cached = jpkcom_postfilter_settings_read_file( $group );
        if ( $cached !== null ) {
            return $cached;
        }

        // Fallback to wp_options
        $db_value = get_option( 'jpkcom_postfilter_' . $group, [] );
        $settings = is_array( $db_value ) ? $db_value : [];

        // Warm up file cache from DB value
        if ( ! empty( $settings ) ) {
            jpkcom_postfilter_settings_write_file( $group, $settings );
        }

        return $settings;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_save' ) ) {
    /**
     * Save settings to wp_options AND update the file cache
     *
     * @since 1.0.0
     *
     * @param string               $group    Settings group slug.
     * @param array<string, mixed> $settings Settings array to save.
     * @return bool True on success.
     */
    function jpkcom_postfilter_settings_save( string $group, array $settings ): bool {
        // Persist to DB
        $db_result = update_option( 'jpkcom_postfilter_' . $group, $settings, false );

        // Update file cache
        jpkcom_postfilter_settings_write_file( $group, $settings );

        // Also flush object cache
        jpkcom_postfilter_cache_flush_group();

        /**
         * Fires after plugin settings are saved.
         *
         * @since 1.0.0
         *
         * @param string               $group    Settings group.
         * @param array<string, mixed> $settings Saved settings.
         */
        do_action( 'jpkcom_postfilter_settings_saved', $group, $settings );

        return $db_result;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_settings_delete_cache' ) ) {
    /**
     * Delete the file cache for a settings group (forces DB re-read next time)
     *
     * @since 1.0.0
     *
     * @param string $group Settings group slug, or '*' to clear all groups.
     * @return void
     */
    function jpkcom_postfilter_settings_delete_cache( string $group = '*' ): void {
        if ( $group === '*' ) {
            $dir = JPKCOM_POSTFILTER_SETTINGS_DIR;
            if ( is_dir( $dir ) ) {
                foreach ( glob( $dir . '/*.php' ) ?: [] as $file ) {
                    if ( basename( $file ) !== 'index.php' ) {
                        if ( function_exists( 'opcache_invalidate' ) ) {
                            opcache_invalidate( $file, true );
                        }
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        @unlink( $file );
                    }
                }
            }
        } else {
            jpkcom_postfilter_settings_delete_file( $group );
        }
    }
}


// ---------------------------------------------------------------------------
// Default settings definitions
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_default_settings' ) ) {
    /**
     * Get the default settings for all groups
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> Nested array of group => key => default.
     */
    function jpkcom_postfilter_default_settings(): array {
        return [
            'general' => [
                'enabled_post_types'      => [ 'post' ],
                'auto_inject'             => [ 'post' ],
                'max_filter_combos'       => 3,
                'max_filters_per_group'   => 3,
                'url_endpoint'            => JPKCOM_POSTFILTER_URL_ENDPOINT,
                'endpoint_empty_action'   => '404',
                'endpoint_empty_redirect' => '',
                'settings_cache_path'     => JPKCOM_POSTFILTER_SETTINGS_DIR,
                'debug_mode'              => JPKCOM_POSTFILTER_DEBUG,
            ],
            'filter_groups' => [
                'groups' => [],
            ],
            'layout' => [
                'filter_layout'       => 'bar',
                'list_layout'         => 'cards',
                'reset_button_mode'   => 'on_selection',
                'plus_minus_mode'     => false,
                'show_more_enabled'   => false,
                'show_more_threshold' => 10,
                'pagination_position' => 'below',
                'color_scheme'        => 'default',
                'stylesheet_mode'     => 'full',
                'custom_css'          => '',
                'css_vars'            => [],
            ],
            'cache' => [
                'object_cache_enabled'   => true,
                'transient_cache_enabled' => true,
                'settings_cache_enabled' => true,
                'cache_ttl'              => HOUR_IN_SECONDS,
            ],
        ];
    }
}


// ---------------------------------------------------------------------------
// WordPress Settings API registration (admin_init)
// ---------------------------------------------------------------------------

add_action( 'admin_init', static function (): void {

    // --- General Settings ---
    register_setting(
        option_group: 'jpkcom_postfilter_general',
        option_name: 'jpkcom_postfilter_general',
        args: [
            'type'              => 'array',
            'sanitize_callback' => 'jpkcom_postfilter_sanitize_general_settings',
            'default'           => jpkcom_postfilter_default_settings()['general'],
        ]
    );

    // --- Layout Settings ---
    register_setting(
        option_group: 'jpkcom_postfilter_layout',
        option_name: 'jpkcom_postfilter_layout',
        args: [
            'type'              => 'array',
            'sanitize_callback' => 'jpkcom_postfilter_sanitize_layout_settings',
            'default'           => jpkcom_postfilter_default_settings()['layout'],
        ]
    );

    // --- Cache Settings ---
    register_setting(
        option_group: 'jpkcom_postfilter_cache',
        option_name: 'jpkcom_postfilter_cache',
        args: [
            'type'              => 'array',
            'sanitize_callback' => 'jpkcom_postfilter_sanitize_cache_settings',
            'default'           => jpkcom_postfilter_default_settings()['cache'],
        ]
    );

    // --- Filter Groups Settings ---
    register_setting(
        option_group: 'jpkcom_postfilter_filter_groups',
        option_name: 'jpkcom_postfilter_filter_groups',
        args: [
            'type'              => 'array',
            'sanitize_callback' => 'jpkcom_postfilter_sanitize_filter_groups_settings',
            'default'           => jpkcom_postfilter_default_settings()['filter_groups'],
        ]
    );

} );


// ---------------------------------------------------------------------------
// Sanitization callbacks
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_general_settings' ) ) {
    /**
     * Sanitize general settings before saving
     *
     * @since 1.0.0
     *
     * @param mixed $input Raw input from the settings form.
     * @return array<string, mixed> Sanitized settings array.
     */
    function jpkcom_postfilter_sanitize_general_settings( mixed $input ): array {
        $defaults = jpkcom_postfilter_default_settings()['general'];
        $input    = is_array( $input ) ? $input : [];

        $output = [];

        // enabled_post_types: array of valid post type slugs
        // Note: unchecked checkboxes are not submitted by the browser, so absence of the key means "none selected".
        $raw_types = isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] )
            ? $input['enabled_post_types']
            : [];

        $valid_post_types = get_post_types( [ 'public' => true ] );
        $output['enabled_post_types'] = array_values( array_filter(
            array_map( 'sanitize_key', $raw_types ),
            static fn( string $pt ): bool => isset( $valid_post_types[ $pt ] )
        ) );

        // auto_inject: array of post type slugs
        // Note: unchecked checkboxes are not submitted by the browser, so absence of the key means "none selected".
        $raw_inject = isset( $input['auto_inject'] ) && is_array( $input['auto_inject'] )
            ? $input['auto_inject']
            : [];

        $output['auto_inject'] = array_values( array_filter(
            array_map( 'sanitize_key', $raw_inject ),
            static fn( string $pt ): bool => isset( $valid_post_types[ $pt ] )
        ) );

        // max_filter_combos: integer 1–10
        $output['max_filter_combos'] = min( 10, max( 1, absint( $input['max_filter_combos'] ?? $defaults['max_filter_combos'] ) ) );

        // max_filters_per_group: integer 1–20 (0 = unlimited)
        $raw_per_group = absint( $input['max_filters_per_group'] ?? $defaults['max_filters_per_group'] );
        $output['max_filters_per_group'] = $raw_per_group === 0 ? 0 : min( 20, max( 1, $raw_per_group ) );

        // url_endpoint: alphanumeric + dashes
        $raw_endpoint = sanitize_title( $input['url_endpoint'] ?? $defaults['url_endpoint'] );
        $output['url_endpoint'] = $raw_endpoint !== '' ? $raw_endpoint : JPKCOM_POSTFILTER_URL_ENDPOINT;

        // settings_cache_path: validated path inside WP_CONTENT_DIR
        $raw_path = sanitize_text_field( $input['settings_cache_path'] ?? $defaults['settings_cache_path'] );
        if ( str_starts_with( $raw_path, WP_CONTENT_DIR ) ) {
            $output['settings_cache_path'] = $raw_path;
        } else {
            $output['settings_cache_path'] = $defaults['settings_cache_path'];
        }

        // endpoint_empty_action: one of '404', 'home', 'custom'
        $valid_actions = [ '404', 'home', 'custom' ];
        $raw_action    = $input['endpoint_empty_action'] ?? '404';
        $output['endpoint_empty_action'] = in_array( $raw_action, $valid_actions, true ) ? $raw_action : '404';

        // endpoint_empty_redirect: URL for custom redirect target
        $output['endpoint_empty_redirect'] = esc_url_raw( sanitize_text_field( $input['endpoint_empty_redirect'] ?? '' ) );

        // debug_mode: boolean
        $output['debug_mode'] = (bool) ( $input['debug_mode'] ?? $defaults['debug_mode'] );

        // On save: update file cache
        jpkcom_postfilter_settings_write_file( 'general', $output );

        return $output;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_layout_settings' ) ) {
    /**
     * Sanitize layout settings before saving
     *
     * @since 1.0.0
     *
     * @param mixed $input Raw input from the settings form.
     * @return array<string, mixed> Sanitized settings.
     */
    function jpkcom_postfilter_sanitize_layout_settings( mixed $input ): array {
        $defaults = jpkcom_postfilter_default_settings()['layout'];
        $input    = is_array( $input ) ? $input : [];

        $valid_filter_layouts = [ 'bar', 'sidebar', 'dropdown', 'columns' ];
        $valid_list_layouts   = [ 'cards', 'rows', 'minimal', 'theme' ];

        $valid_reset_modes = [ 'always', 'on_selection', 'never' ];

        $valid_pagination_positions = [ 'above', 'below', 'both' ];

        $valid_color_schemes   = [ 'default', 'dark', 'contrast', 'mono' ];
        $valid_stylesheet_modes = [ 'full', 'vars_only', 'disabled' ];

        $output = [
            'filter_layout' => in_array( $input['filter_layout'] ?? '', $valid_filter_layouts, true )
                ? $input['filter_layout']
                : $defaults['filter_layout'],

            'list_layout' => in_array( $input['list_layout'] ?? '', $valid_list_layouts, true )
                ? $input['list_layout']
                : $defaults['list_layout'],

            'reset_button_mode' => in_array( $input['reset_button_mode'] ?? '', $valid_reset_modes, true )
                ? $input['reset_button_mode']
                : $defaults['reset_button_mode'],

            'plus_minus_mode' => ! empty( $input['plus_minus_mode'] ),

            'show_more_enabled' => ! empty( $input['show_more_enabled'] ),

            'show_more_threshold' => max( 1, (int) ( $input['show_more_threshold'] ?? $defaults['show_more_threshold'] ) ),

            'pagination_position' => in_array( $input['pagination_position'] ?? '', $valid_pagination_positions, true )
                ? $input['pagination_position']
                : $defaults['pagination_position'],

            'color_scheme' => in_array( $input['color_scheme'] ?? '', $valid_color_schemes, true )
                ? $input['color_scheme']
                : $defaults['color_scheme'],

            'stylesheet_mode' => in_array( $input['stylesheet_mode'] ?? '', $valid_stylesheet_modes, true )
                ? $input['stylesheet_mode']
                : $defaults['stylesheet_mode'],

            'custom_css' => wp_strip_all_tags( (string) ( $input['custom_css'] ?? '' ) ),

            'css_vars' => [],
        ];

        // CSS variables: validate each value
        $raw_vars = isset( $input['css_vars'] ) && is_array( $input['css_vars'] ) ? $input['css_vars'] : [];
        foreach ( $raw_vars as $var_name => $var_value ) {
            $safe_name  = sanitize_key( (string) $var_name );
            $safe_value = sanitize_text_field( (string) $var_value );
            if ( $safe_name !== '' && $safe_value !== '' ) {
                $output['css_vars'][ $safe_name ] = $safe_value;
            }
        }

        jpkcom_postfilter_settings_write_file( 'layout', $output );

        return $output;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_cache_settings' ) ) {
    /**
     * Sanitize cache settings before saving
     *
     * @since 1.0.0
     *
     * @param mixed $input Raw input from the settings form.
     * @return array<string, mixed> Sanitized settings.
     */
    function jpkcom_postfilter_sanitize_cache_settings( mixed $input ): array {
        $defaults = jpkcom_postfilter_default_settings()['cache'];
        $input    = is_array( $input ) ? $input : [];

        $output = [
            'object_cache_enabled'    => (bool) ( $input['object_cache_enabled'] ?? $defaults['object_cache_enabled'] ),
            'transient_cache_enabled' => (bool) ( $input['transient_cache_enabled'] ?? $defaults['transient_cache_enabled'] ),
            'settings_cache_enabled'  => (bool) ( $input['settings_cache_enabled'] ?? $defaults['settings_cache_enabled'] ),
            'cache_ttl'               => max( 60, absint( $input['cache_ttl'] ?? $defaults['cache_ttl'] ) ),
        ];

        jpkcom_postfilter_settings_write_file( 'cache', $output );

        return $output;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_filter_groups_settings' ) ) {
    /**
     * Sanitize filter groups settings before saving
     *
     * @since 1.0.0
     *
     * @param mixed $input Raw input from the settings form.
     * @return array<string, mixed> Sanitized settings.
     */
    function jpkcom_postfilter_sanitize_filter_groups_settings( mixed $input ): array {
        $input  = is_array( $input ) ? $input : [];
        $groups = isset( $input['groups'] ) && is_array( $input['groups'] ) ? $input['groups'] : [];

        $sanitized_groups = [];

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $sanitized_group = [
                'slug'          => sanitize_key( (string) ( $group['slug'] ?? '' ) ),
                'taxonomy'      => sanitize_key( (string) ( $group['taxonomy'] ?? '' ) ),
                'label'         => sanitize_text_field( (string) ( $group['label'] ?? '' ) ),
                'order'         => absint( $group['order'] ?? 0 ),
                'enabled'       => (bool) ( $group['enabled'] ?? true ),
                'hide_empty'    => (bool) ( $group['hide_empty'] ?? true ),
                'custom'        => (bool) ( $group['custom'] ?? false ),
                'hierarchical'  => (bool) ( $group['hierarchical'] ?? false ),
                'public'            => (bool) ( $group['public'] ?? true ),
                'show_admin_column' => (bool) ( $group['show_admin_column'] ?? true ),
                'show_in_rest'      => (bool) ( $group['show_in_rest'] ?? true ),
                'post_types'    => isset( $group['post_types'] ) && is_array( $group['post_types'] )
                    ? array_values( array_map( 'sanitize_key', $group['post_types'] ) )
                    : [],
            ];

            if ( $sanitized_group['taxonomy'] !== '' ) {
                $sanitized_groups[] = $sanitized_group;
            }
        }

        // Sort by order
        usort( $sanitized_groups, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

        $output = [ 'groups' => $sanitized_groups ];

        jpkcom_postfilter_settings_write_file( 'filter_groups', $output );

        return $output;
    }
}


// ---------------------------------------------------------------------------
// CSS Variable helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_get_css_var_defaults' ) ) {
    /**
     * Get all CSS variable default values (matches :root in post-filter.css)
     *
     * @since 1.0.0
     *
     * @return array<string, string> Map of var-name (without --jpkpf- prefix) => default value.
     */
    function jpkcom_postfilter_get_css_var_defaults(): array {
        return [
            'primary'                   => '#0073aa',
            'primary-hover'             => '#005d8c',
            'filter-bg'                 => '#f0f0f1',
            'filter-color'              => '#3c434a',
            'filter-active-bg'          => '#0073aa',
            'filter-active-color'       => '#ffffff',
            'filter-hover-bg'           => '#dcdcde',
            'filter-hover-color'        => '#1d2327',
            'reset-bg'                  => 'transparent',
            'reset-color'               => '#646970',
            'reset-border'              => '#8c8f94',
            'reset-radius'              => '3px',
            'reset-hover-bg'            => '#dcdcde',
            'reset-hover-color'         => '#1d2327',
            'card-bg'                   => '#ffffff',
            'card-border'               => '#dcdcde',
            'card-shadow'               => '0 1px 3px rgba(0,0,0,0.08)',
            'card-shadow-hover'         => '0 4px 12px rgba(0,0,0,0.12)',
            'card-radius'               => '4px',
            'card-padding'              => '1.25rem',
            'text-primary'              => '#1d2327',
            'text-secondary'            => '#646970',
            'link-color'                => '#0073aa',
            'link-hover'                => '#005d8c',
            'no-results-color'          => '#646970',
            'gap'                       => '0.5rem',
            'gap-lg'                    => '1rem',
            'padding'                   => '0.5rem 1rem',
            'section-gap'               => '1.5rem',
            'filter-radius'             => '3px',
            'input-radius'              => '3px',
            'font-size-sm'              => '0.875rem',
            'font-size-base'            => '1rem',
            'font-weight-medium'        => '500',
            'transition'                => '0.2s ease',
            'grid-cols'                 => '3',
            'grid-cols-md'              => '2',
            'grid-cols-sm'              => '1',
            'filter-btn-font-size'      => 'inherit',
            'filter-btn-font-weight'    => 'inherit',
            'filter-label-color'        => '#646970',
            'filter-label-font-weight'  => '600',
            'filter-label-font-size'    => '0.75rem',
            'dropdown-panel-bg'         => '#ffffff',
            'dropdown-panel-border'     => '#dcdcde',
            'dropdown-panel-shadow'     => '0 4px 12px rgba(0,0,0,0.12)',
            'dropdown-panel-radius'     => '4px',
            'pagi-bg'                   => '#f0f0f1',
            'pagi-color'                => '#3c434a',
            'pagi-hover-bg'             => '#dcdcde',
            'pagi-hover-color'          => '#1d2327',
            'pagi-active-bg'            => '#0073aa',
            'pagi-active-color'         => '#ffffff',
            'pagi-radius'               => '3px',
            'pagi-font-size'            => '0.875rem',
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_color_scheme_vars' ) ) {
    /**
     * Get CSS variable overrides for a named color scheme
     *
     * @since 1.0.0
     *
     * @param string $scheme Color scheme key: 'default', 'dark', 'contrast', 'mono'.
     * @return array<string, string> Map of var-name => value. Empty array for 'default'.
     */
    function jpkcom_postfilter_get_color_scheme_vars( string $scheme ): array {
        $schemes = [
            'default' => [],

            'dark' => [
                'primary'              => '#4da6d2',
                'primary-hover'        => '#73b9db',
                'filter-bg'            => '#2c3338',
                'filter-color'         => '#e0e0e0',
                'filter-active-bg'     => '#4da6d2',
                'filter-active-color'  => '#1d2327',
                'filter-hover-bg'      => '#3c4349',
                'filter-hover-color'   => '#e0e0e0',
                'card-bg'              => '#1d2327',
                'card-border'          => '#2c3338',
                'text-primary'         => '#e0e0e0',
                'text-secondary'       => '#a7aaad',
                'link-color'           => '#4da6d2',
                'link-hover'           => '#73b9db',
                'reset-color'          => '#a7aaad',
                'reset-border'         => '#4a5055',
                'reset-hover-bg'       => '#3c4349',
                'reset-hover-color'    => '#e0e0e0',
                'pagi-bg'              => '#2c3338',
                'pagi-color'           => '#e0e0e0',
                'pagi-active-bg'       => '#4da6d2',
                'pagi-active-color'    => '#1d2327',
                'dropdown-panel-bg'    => '#2c3338',
                'dropdown-panel-border' => '#4a5055',
            ],

            'contrast' => [
                'reset-bg'           => '#d63638',
                'reset-color'        => '#ffffff',
                'reset-border'       => '#d63638',
                'reset-hover-bg'     => '#b32d2e',
                'reset-hover-color'  => '#ffffff',
            ],

            'mono' => [
                'primary'            => '#1d2327',
                'primary-hover'      => '#000000',
                'filter-bg'          => '#e6e6e6',
                'filter-color'       => '#1d2327',
                'filter-active-bg'   => '#1d2327',
                'filter-active-color' => '#ffffff',
                'filter-hover-bg'    => '#c0c0c0',
                'filter-hover-color' => '#000000',
                'card-border'        => '#1d2327',
                'link-color'         => '#1d2327',
                'link-hover'         => '#000000',
                'pagi-active-bg'     => '#1d2327',
                'pagi-hover-bg'      => '#c0c0c0',
            ],
        ];

        return $schemes[ $scheme ] ?? [];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_merged_css_vars' ) ) {
    /**
     * Get the merged CSS variables for frontend output
     *
     * Merge order (last wins): scheme overrides → custom admin vars.
     * For 'full' stylesheet mode, defaults are in the CSS file so only overrides are needed.
     * For 'vars_only' mode, all defaults are included.
     *
     * @since 1.0.0
     *
     * @param bool $include_defaults Whether to include default values (true for vars_only mode).
     * @return array<string, string> Map of var-name => value.
     */
    function jpkcom_postfilter_get_merged_css_vars( bool $include_defaults = false ): array {
        $settings    = jpkcom_postfilter_settings_get_group( 'layout' );
        $scheme      = $settings['color_scheme'] ?? 'default';
        $custom_vars = isset( $settings['css_vars'] ) && is_array( $settings['css_vars'] )
            ? $settings['css_vars']
            : [];

        $base         = $include_defaults ? jpkcom_postfilter_get_css_var_defaults() : [];
        $scheme_vars  = jpkcom_postfilter_get_color_scheme_vars( $scheme );

        return array_merge( $base, $scheme_vars, $custom_vars );
    }
}


// ---------------------------------------------------------------------------
// Settings save hooks: update file cache when options are updated via WP API
// ---------------------------------------------------------------------------

/**
 * Hook into the WordPress option update cycle for each settings group
 * to keep the file cache in sync when options are updated programmatically.
 *
 * @since 1.0.0
 */
foreach ( [ 'general', 'layout', 'cache', 'filter_groups' ] as $jpkpf_group ) {
    add_action(
        "update_option_jpkcom_postfilter_{$jpkpf_group}",
        static function ( mixed $old_value, mixed $new_value ) use ( $jpkpf_group ): void {
            if ( is_array( $new_value ) ) {
                jpkcom_postfilter_settings_write_file( $jpkpf_group, $new_value );
            }
        },
        10,
        2
    );
}
unset( $jpkpf_group );
