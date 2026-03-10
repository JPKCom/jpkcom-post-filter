<?php
/**
 * Helper Functions
 *
 * General-purpose utility functions used throughout the plugin.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_postfilter_locate_file' ) ) {
    /**
     * Locate an include/utility file with override support
     *
     * Searches for a file in multiple locations with priority:
     * 1. Child theme: /themes/child/jpkcom-post-filter/
     * 2. Parent theme: /themes/parent/jpkcom-post-filter/
     * 3. MU plugin overrides: /mu-plugins/jpkcom-post-filter-overrides/
     * 4. Plugin includes directory: /plugins/jpkcom-post-filter/includes/
     *
     * @since 1.0.0
     *
     * @param string $filename The filename to locate (without path).
     * @return string|null Full path to the file if found, null otherwise.
     */
    function jpkcom_postfilter_locate_file( string $filename ): ?string {

        $paths = [
            trailingslashit( get_stylesheet_directory() ) . 'jpkcom-post-filter/' . $filename,
            trailingslashit( get_template_directory() ) . 'jpkcom-post-filter/' . $filename,
            trailingslashit( WPMU_PLUGIN_DIR ) . 'jpkcom-post-filter-overrides/' . $filename,
            JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/' . $filename,
        ];

        /**
         * Filter the file search paths
         *
         * @since 1.0.0
         *
         * @param string[] $paths    Array of paths to search.
         * @param string   $filename The filename being located.
         */
        $paths = apply_filters( 'jpkcom_postfilter_file_paths', $paths, $filename );

        foreach ( $paths as $path ) {
            if ( file_exists( filename: $path ) ) {
                return $path;
            }
        }

        return null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_debug_log' ) ) {
    /**
     * Log a debug message when debug mode is enabled
     *
     * @since 1.0.0
     *
     * @param string $message The message to log.
     * @param mixed  $context Optional additional context data.
     * @return void
     */
    function jpkcom_postfilter_debug_log( string $message, mixed $context = null ): void {
        if ( ! JPKCOM_POSTFILTER_DEBUG ) {
            return;
        }

        $log = '[jpkcom-post-filter] ' . $message;

        if ( $context !== null ) {
            $log .= ' | Context: ' . wp_json_encode( $context );
        }

        error_log( message: $log );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_csv_slugs' ) ) {
    /**
     * Sanitize a CSV string of slugs into an array
     *
     * @since 1.0.0
     *
     * @param string $csv Comma-separated string of slugs.
     * @return string[] Array of sanitized slugs.
     */
    function jpkcom_postfilter_sanitize_csv_slugs( string $csv ): array {
        if ( trim( $csv ) === '' ) {
            return [];
        }

        return array_values( array_filter(
            array_map(
                static fn( string $s ): string => sanitize_title( trim( $s ) ),
                explode( ',', $csv )
            )
        ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_sanitize_csv_ids' ) ) {
    /**
     * Sanitize a CSV string of integer IDs into an array
     *
     * @since 1.0.0
     *
     * @param string $csv Comma-separated string of IDs.
     * @return int[] Array of positive integer IDs.
     */
    function jpkcom_postfilter_sanitize_csv_ids( string $csv ): array {
        if ( trim( $csv ) === '' ) {
            return [];
        }

        return array_values( array_filter(
            array_map( 'absint', explode( ',', $csv ) )
        ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_option' ) ) {
    /**
     * Get a plugin option with settings file cache support
     *
     * Reads from file cache first (fast), falls back to wp_options (DB).
     *
     * @since 1.0.0
     *
     * @param string $group   Settings group (maps to cache file name).
     * @param string $key     Option key within the group.
     * @param mixed  $default Default value if not found.
     * @return mixed Option value or default.
     */
    function jpkcom_postfilter_get_option( string $group, string $key, mixed $default = null ): mixed {
        if ( function_exists( 'jpkcom_postfilter_settings_get' ) ) {
            return jpkcom_postfilter_settings_get( $group, $key, $default );
        }

        // Fallback: direct wp_options read
        $all = get_option( 'jpkcom_postfilter_' . $group, [] );
        return is_array( $all ) && array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_array_flatten' ) ) {
    /**
     * Flatten a multi-dimensional array into a single-level array
     *
     * @since 1.0.0
     *
     * @param array<mixed> $array The array to flatten.
     * @return array<mixed> Flattened array.
     */
    function jpkcom_postfilter_array_flatten( array $array ): array {
        $result = [];
        array_walk_recursive( $array, static function ( mixed $item ) use ( &$result ): void {
            $result[] = $item;
        } );
        return $result;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_is_filter_request' ) ) {
    /**
     * Determine whether the current request is a filter URL request
     *
     * @since 1.0.0
     *
     * @return bool True if current request contains the filter endpoint segment.
     */
    function jpkcom_postfilter_is_filter_request(): bool {
        return (bool) get_query_var( 'jpkcom_filter_active', false );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_active_filters' ) ) {
    /**
     * Get currently active filter slugs from query vars
     *
     * Returns an associative array keyed by taxonomy slug.
     *
     * @since 1.0.0
     *
     * @return array<string, string[]> Active filters: ['taxonomy-slug' => ['term-slug', ...]].
     */
    function jpkcom_postfilter_get_active_filters(): array {
        $raw = get_query_var( 'jpkcom_filter_segments', [] );

        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return [];
        }

        /** @var array<string, string> $raw */
        $filters = [];
        foreach ( $raw as $taxonomy => $slugs_string ) {
            $taxonomy = sanitize_key( $taxonomy );
            $slugs    = array_filter( array_map( 'sanitize_title', explode( '+', (string) $slugs_string ) ) );

            if ( ! empty( $slugs ) ) {
                $filters[ $taxonomy ] = array_values( $slugs );
            }
        }

        return $filters;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_build_filter_url' ) ) {
    /**
     * Build a SEO-friendly filter URL
     *
     * Constructs a URL like: /blog/filter/cat-1+cat-2/tag-1/
     *
     * @since 1.0.0
     *
     * @param string   $base_url   The archive base URL (e.g., home_url('/blog/')).
     * @param array<string, string[]> $filters Associative array of taxonomy => term slugs.
     * @param int      $page       Optional pagination page number (0 or 1 = first page).
     * @return string  The constructed filter URL.
     */
    function jpkcom_postfilter_build_filter_url( string $base_url, array $filters, int $page = 0 ): string {
        // Remove empty filter groups
        $filters = array_filter( $filters, static fn( array $slugs ): bool => ! empty( $slugs ) );

        if ( empty( $filters ) ) {
            if ( $page > 1 ) {
                return trailingslashit( $base_url ) . 'page/' . $page . '/';
            }
            return trailingslashit( $base_url );
        }

        $endpoint = jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT );
        $segments = [];

        foreach ( $filters as $slugs ) {
            $segments[] = implode( '+', array_map( 'sanitize_title', (array) $slugs ) );
        }

        $url = trailingslashit( $base_url ) . $endpoint . '/' . implode( '/', $segments ) . '/';

        if ( $page > 1 ) {
            $url .= 'page/' . $page . '/';
        }

        return $url;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_current_archive_url' ) ) {
    /**
     * Get the base archive URL for the current request
     *
     * @since 1.0.0
     *
     * @return string Base archive URL without filter segments.
     */
    function jpkcom_postfilter_get_current_archive_url(): string {
        if ( is_home() || is_front_page() ) {
            return home_url( '/' );
        }

        if ( is_post_type_archive() ) {
            return (string) get_post_type_archive_link( get_queried_object()->name ?? '' );
        }

        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term ) {
                return (string) get_term_link( $term );
            }
        }

        return home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '/' ) );
    }
}
