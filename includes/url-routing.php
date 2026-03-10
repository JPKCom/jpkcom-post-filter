<?php
/**
 * URL Routing – SEO-Friendly Filter URLs
 *
 * Registers WordPress rewrite rules for filter endpoint URLs:
 *   /{archive-base}/filter/{tax1-slug1}+{tax1-slug2}/{tax2-slug1}/
 *
 * URL segments are positional: position N corresponds to the Nth enabled filter
 * group (sorted by `order`). An underscore `_` placeholder skips a position so
 * that later groups can still be addressed without creating ambiguous URLs.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Filter path parsing
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_parse_filter_path' ) ) {
    /**
     * Parse a URL filter path into a taxonomy => term-slugs map
     *
     * Segments are positional and matched against enabled filter groups by order.
     * The underscore `_` serves as an empty-position placeholder; any segment
     * containing only `_` is skipped.
     *
     * @since 1.0.0
     *
     * @param string $filter_path URL portion after the filter endpoint keyword
     *                            (e.g., "cat-1+cat-2/tag-1").
     * @return array<string, string[]> Taxonomy slug => list of term slugs.
     */
    function jpkcom_postfilter_parse_filter_path( string $filter_path ): array {
        $groups = jpkcom_postfilter_get_filter_groups_enabled();
        if ( empty( $groups ) ) {
            return [];
        }

        $segments = array_values( array_filter(
            explode( '/', trim( $filter_path, '/' ) ),
            static fn( string $s ): bool => $s !== ''
        ) );

        $result = [];

        foreach ( $segments as $index => $segment ) {
            if ( ! isset( $groups[ $index ] ) ) {
                break;
            }

            if ( $segment === '_' ) {
                continue; // Empty-position placeholder — skip
            }

            $taxonomy = (string) ( $groups[ $index ]['taxonomy'] ?? '' );
            if ( $taxonomy === '' ) {
                continue;
            }

            $term_slugs = array_values( array_filter(
                array_map( 'sanitize_title', explode( '+', $segment ) ),
                static fn( string $s ): bool => $s !== ''
            ) );

            if ( ! empty( $term_slugs ) ) {
                // Enforce max_filters_per_group
                $max_per_group = (int) jpkcom_postfilter_settings_get( 'general', 'max_filters_per_group', 3 );
                if ( $max_per_group > 0 && count( $term_slugs ) > $max_per_group ) {
                    $term_slugs = array_slice( $term_slugs, 0, $max_per_group );
                }
                $result[ $taxonomy ] = $term_slugs;
            }
        }

        // Enforce max_filter_combos (max number of active taxonomy groups)
        $max_combos = (int) jpkcom_postfilter_settings_get( 'general', 'max_filter_combos', 3 );
        if ( $max_combos > 0 && count( $result ) > $max_combos ) {
            $result = array_slice( $result, 0, $max_combos, true );
        }

        return $result;
    }
}


// ---------------------------------------------------------------------------
// URL building (ordered)
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_get_filter_url' ) ) {
    /**
     * Build an ordered SEO-friendly filter URL
     *
     * Constructs filter URL segments respecting filter group order. Empty
     * intermediate groups use `_` placeholders; trailing empty groups are dropped.
     *
     * @since 1.0.0
     *
     * @param string                  $base_url Base archive URL.
     * @param array<string, string[]> $filters  Taxonomy slug => term slugs map.
     * @param int                     $page     Pagination page (0 or 1 = no page suffix).
     * @return string Constructed filter URL.
     */
    function jpkcom_postfilter_get_filter_url( string $base_url, array $filters, int $page = 0 ): string {
        $groups  = jpkcom_postfilter_get_filter_groups_enabled();
        $filters = array_filter( $filters, static fn( array $slugs ): bool => ! empty( $slugs ) );

        if ( empty( $filters ) ) {
            if ( $page > 1 ) {
                return trailingslashit( $base_url ) . 'page/' . $page . '/';
            }
            return trailingslashit( $base_url );
        }

        $segments = [];
        foreach ( $groups as $group ) {
            $taxonomy = (string) ( $group['taxonomy'] ?? '' );
            if ( $taxonomy !== '' && ! empty( $filters[ $taxonomy ] ) ) {
                $segments[] = implode( '+', array_map( 'sanitize_title', (array) $filters[ $taxonomy ] ) );
            } else {
                $segments[] = '_';
            }
        }

        // Remove trailing placeholder segments
        while ( ! empty( $segments ) && end( $segments ) === '_' ) {
            array_pop( $segments );
        }

        if ( empty( $segments ) ) {
            if ( $page > 1 ) {
                return trailingslashit( $base_url ) . 'page/' . $page . '/';
            }
            return trailingslashit( $base_url );
        }

        $endpoint = sanitize_key( jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) );
        $url      = trailingslashit( $base_url ) . $endpoint . '/' . implode( '/', $segments ) . '/';

        if ( $page > 1 ) {
            $url .= 'page/' . $page . '/';
        }

        return $url;
    }
}


// ---------------------------------------------------------------------------
// Archive base helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_get_archive_base_url' ) ) {
    /**
     * Get the base URL for a post type's archive
     *
     * @since 1.0.0
     *
     * @param string $post_type Post type slug.
     * @return string Base URL with trailing slash, or empty string if not found.
     */
    function jpkcom_postfilter_get_archive_base_url( string $post_type ): string {
        if ( $post_type === 'post' ) {
            $page_id = (int) get_option( 'page_for_posts' );
            if ( $page_id > 0 ) {
                $url = get_permalink( $page_id );
                return is_string( $url ) && $url !== '' ? trailingslashit( $url ) : trailingslashit( home_url() );
            }
            return trailingslashit( home_url() );
        }

        $link = get_post_type_archive_link( $post_type );
        return is_string( $link ) && $link !== '' ? trailingslashit( $link ) : '';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_archive_base_regex' ) ) {
    /**
     * Get the URL path regex prefix for a post type archive (used in rewrite rules)
     *
     * Returns an empty string for the front-page blog (no URL prefix needed),
     * a regex-escaped path string for blog/CPT archive pages, or null if the
     * post type has no archive.
     *
     * @since 1.0.0
     *
     * @param string $post_type       Post type slug.
     * @param int    $page_for_posts  Current `page_for_posts` option value.
     * @return string|null Regex path prefix, or null when no archive exists.
     */
    function jpkcom_postfilter_archive_base_regex( string $post_type, int $page_for_posts = 0 ): ?string {
        if ( $post_type === 'post' ) {
            if ( $page_for_posts <= 0 ) {
                return ''; // Front-page blog — no URL prefix
            }
            $uri = get_page_uri( $page_for_posts );
            return is_string( $uri ) && $uri !== '' ? preg_quote( trim( $uri, '/' ), '#' ) : '';
        }

        $obj = get_post_type_object( $post_type );
        if ( ! $obj || ! $obj->has_archive ) {
            return null;
        }

        if ( is_string( $obj->has_archive ) && $obj->has_archive !== '' ) {
            $slug = $obj->has_archive;
        } elseif ( isset( $obj->rewrite['slug'] ) && is_string( $obj->rewrite['slug'] ) ) {
            $slug = $obj->rewrite['slug'];
        } else {
            $slug = $post_type;
        }

        return preg_quote( trim( $slug, '/' ), '#' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_archive_query_string' ) ) {
    /**
     * Get the WP query string fragment that establishes the correct archive context
     *
     * @since 1.0.0
     *
     * @param string $post_type      Post type slug.
     * @param int    $page_for_posts Current `page_for_posts` option value.
     * @return string Query string fragment (no leading `?`), may be empty.
     */
    function jpkcom_postfilter_archive_query_string( string $post_type, int $page_for_posts = 0 ): string {
        if ( $post_type === 'post' ) {
            if ( $page_for_posts > 0 ) {
                return 'page_id=' . $page_for_posts;
            }
            return ''; // Front-page blog — WP will recognise via is_home in parse_query hook
        }

        return 'post_type=' . rawurlencode( $post_type );
    }
}


// ---------------------------------------------------------------------------
// Rewrite rules
// ---------------------------------------------------------------------------

/**
 * Register filter URL rewrite rules for each enabled post type
 *
 * @since 1.0.0
 */
add_action( 'init', static function (): void {

    $general        = jpkcom_postfilter_settings_get_group( 'general' );
    $endpoint       = sanitize_key( $general['url_endpoint'] ?? JPKCOM_POSTFILTER_URL_ENDPOINT );
    $post_types     = array_map( 'sanitize_key', (array) ( $general['enabled_post_types'] ?? [ 'post' ] ) );
    $page_for_posts = (int) get_option( 'page_for_posts' );

    // Also register rewrite rules for post types referenced by filter groups
    // (so filter URLs work even if the post type is not in the global enabled list).
    foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
        if ( ! empty( $group['post_types'] ) && is_array( $group['post_types'] ) ) {
            foreach ( $group['post_types'] as $pt ) {
                $post_types[] = sanitize_key( $pt );
            }
        }
    }
    $post_types = array_unique( array_filter( $post_types ) );

    foreach ( $post_types as $post_type ) {
        $base_regex = jpkcom_postfilter_archive_base_regex( $post_type, $page_for_posts );
        if ( $base_regex === null ) {
            continue; // Post type has no archive
        }

        $prefix = $base_regex !== '' ? $base_regex . '/' : '';
        $qs     = jpkcom_postfilter_archive_query_string( $post_type, $page_for_posts );
        $extra  = ( $qs !== '' ? $qs . '&' : '' )
                . 'jpkcom_filter_post_type=' . rawurlencode( $post_type );

        // With pagination
        add_rewrite_rule(
            '^' . $prefix . $endpoint . '/(.+?)/page/?([0-9]{1,})/?$',
            'index.php?' . $extra . '&jpkcom_filter_path=$matches[1]&paged=$matches[2]',
            'top'
        );

        // Without pagination
        add_rewrite_rule(
            '^' . $prefix . $endpoint . '/(.+?)/?$',
            'index.php?' . $extra . '&jpkcom_filter_path=$matches[1]',
            'top'
        );

        jpkcom_postfilter_debug_log( "Registered rewrite rules for post_type: {$post_type}", [
            'prefix'   => $prefix,
            'endpoint' => $endpoint,
        ] );
    }

}, 20 );


// ---------------------------------------------------------------------------
// Query vars registration
// ---------------------------------------------------------------------------

/**
 * Register plugin query vars so WordPress does not strip them from requests
 *
 * @since 1.0.0
 */
add_filter( 'query_vars', static function ( array $vars ): array {
    $vars[] = 'jpkcom_filter_path';
    $vars[] = 'jpkcom_filter_post_type';
    return $vars;
} );


// ---------------------------------------------------------------------------
// parse_query — inject filter data and fix is_home for front-page blog
// ---------------------------------------------------------------------------

/**
 * Detect filter requests and inject parsed filter data into WP_Query
 *
 * Runs at the end of WP_Query::parse_query() so that conditional tags are
 * already set by WordPress. We can safely override them here for the
 * front-page blog case, and always store the parsed filter segments.
 *
 * @since 1.0.0
 */
add_action( 'parse_query', static function ( \WP_Query $query ): void {

    $filter_path = (string) $query->get( 'jpkcom_filter_path' );
    if ( $filter_path === '' ) {
        return;
    }

    // Parse positional filter path into taxonomy => term_slugs
    $parsed = jpkcom_postfilter_parse_filter_path( $filter_path );

    // Store in the format helpers.php jpkcom_postfilter_get_active_filters() expects
    // (it splits taxonomy values on '+' to recover individual slugs)
    $segments = [];
    foreach ( $parsed as $taxonomy => $slugs ) {
        $segments[ $taxonomy ] = implode( '+', $slugs );
    }

    $query->set( 'jpkcom_filter_segments', $segments );
    $query->set( 'jpkcom_filter_active', '1' );

    // For the front-page blog the rewrite rule sets no WP archive query vars,
    // so WP's parse_query logic would leave is_home=false. Fix that here.
    $post_type = (string) $query->get( 'jpkcom_filter_post_type' );
    if ( $post_type === 'post' && (int) get_option( 'page_for_posts' ) <= 0 ) {
        $query->is_home    = true;
        $query->is_archive = false;
        $query->is_404     = false;
    }

    jpkcom_postfilter_debug_log( 'parse_query: filter request detected', [
        'filter_path'    => $filter_path,
        'active_filters' => $parsed,
        'post_type'      => $post_type,
    ] );

}, 10 );


// ---------------------------------------------------------------------------
// Canonical URL
// ---------------------------------------------------------------------------

/**
 * Replace WordPress's default canonical tag with the plugin's canonical filter URL
 *
 * @since 1.0.0
 */
add_action( 'wp', static function (): void {

    if ( ! jpkcom_postfilter_is_filter_request() ) {
        return;
    }

    remove_action( 'wp_head', 'rel_canonical' );

    add_action( 'wp_head', static function (): void {
        $filters   = jpkcom_postfilter_get_active_filters();
        $post_type = (string) get_query_var( 'jpkcom_filter_post_type', 'post' );
        if ( $post_type === '' ) {
            $post_type = 'post';
        }

        $base_url  = jpkcom_postfilter_get_archive_base_url( $post_type );
        $page      = max( 1, (int) get_query_var( 'paged' ) );
        $canon_url = jpkcom_postfilter_get_filter_url( $base_url, $filters, $page > 1 ? $page : 0 );

        printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canon_url ) );
    }, 1 );

}, 10 );


// ---------------------------------------------------------------------------
// Bare endpoint redirect / 404 handling
// ---------------------------------------------------------------------------

/**
 * Handle requests to the bare filter endpoint (e.g. /filter/) with no filter path.
 *
 * Without a filter path the rewrite rules do not match, so WordPress produces a
 * 404. This hook intercepts that 404 and either keeps it, redirects to the blog
 * homepage, or redirects to a custom URL – depending on the admin setting.
 *
 * @since 1.0.0
 */
add_action( 'template_redirect', static function (): void {

    if ( ! is_404() ) {
        return;
    }

    $general  = jpkcom_postfilter_settings_get_group( 'general' );
    $action   = $general['endpoint_empty_action'] ?? '404';

    if ( $action === '404' ) {
        return; // Keep default 404 behaviour.
    }

    // Check whether the requested path ends with /{endpoint}[/]
    $endpoint = sanitize_key( $general['url_endpoint'] ?? JPKCOM_POSTFILTER_URL_ENDPOINT );
    $path     = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

    if ( ! preg_match( '#(^|/)' . preg_quote( $endpoint, '#' ) . '/?$#', $path ) ) {
        return; // Not a bare endpoint URL – leave the 404 alone.
    }

    if ( $action === 'home' ) {
        $page_id  = (int) get_option( 'page_for_posts' );
        $blog_url = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
        if ( ! is_string( $blog_url ) || $blog_url === '' ) {
            $blog_url = home_url( '/' );
        }
        wp_safe_redirect( $blog_url, 307 );
        exit;
    }

    if ( $action === 'custom' ) {
        $redirect = $general['endpoint_empty_redirect'] ?? '';
        if ( $redirect !== '' ) {
            wp_redirect( $redirect, 307 );
            exit;
        }
        // No custom URL configured – fall through to 404.
    }

}, 1 );


// ---------------------------------------------------------------------------
// Flush rewrite rules on settings change
// ---------------------------------------------------------------------------

/**
 * Schedule a rewrite rule flush when general settings change
 *
 * Enabled post types affect which rewrite rules are registered.
 *
 * @since 1.0.0
 */
add_action( 'jpkcom_postfilter_settings_saved', static function ( string $group ): void {
    if ( $group === 'general' ) {
        update_option( 'jpkcom_postfilter_flush_rewrite', '1', false );
        jpkcom_postfilter_debug_log( 'URL routing: rewrite flush scheduled' );
    }
} );


// ---------------------------------------------------------------------------
// Rewrite theme pagination links to include active filter path
// ---------------------------------------------------------------------------

/**
 * Make the theme's native paginate_links() calls filter-aware.
 *
 * When the current page is a filter request the base archive URL in every
 * pagination link is replaced with the SEO-friendly filter URL, so clicking
 * a page number preserves the active filter selection.
 * Links that already contain the filter endpoint are left untouched.
 *
 * @since 1.0.0
 *
 * @param string $link Single pagination URL generated by paginate_links().
 * @return string Rewritten URL when on a filter page, original URL otherwise.
 */
add_filter( 'paginate_links', static function ( string $link ): string {

    if ( is_admin() ) {
        return $link;
    }

    $active = jpkcom_postfilter_get_active_filters();
    if ( empty( $active ) ) {
        return $link;
    }

    // Already contains the filter endpoint – do not double-process.
    $endpoint = jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT );
    if ( str_contains( $link, '/' . $endpoint . '/' ) ) {
        return $link;
    }

    // Resolve the archive base URL for the current context.
    $post_type = 'post';
    if ( is_post_type_archive() ) {
        $queried = get_queried_object();
        if ( $queried instanceof \WP_Post_Type ) {
            $post_type = $queried->name;
        }
    }

    $base_url   = trailingslashit( jpkcom_postfilter_get_archive_base_url( $post_type ) );
    $filter_url = trailingslashit( jpkcom_postfilter_get_filter_url( $base_url, $active ) );

    // Replace the archive base prefix so /page/N/ remains intact.
    if ( str_starts_with( $link, $base_url ) ) {
        return $filter_url . substr( $link, strlen( $base_url ) );
    }

    return $link;

}, 10 );
