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
        $taxonomies = jpkcom_postfilter_filter_taxonomies();
        if ( empty( $taxonomies ) ) {
            return [];
        }

        $segments = array_values( array_filter(
            explode( '/', trim( $filter_path, '/' ) ),
            static fn( string $s ): bool => $s !== ''
        ) );

        $raw = [];

        foreach ( $segments as $index => $segment ) {
            if ( ! isset( $taxonomies[ $index ] ) ) {
                break;
            }

            if ( $segment === '_' || $taxonomies[ $index ] === '' ) {
                continue; // Empty-position placeholder — skip
            }

            $raw[ $taxonomies[ $index ] ] = jpkcom_postfilter_split_segment( $segment, 'jpkcom_postfilter_known_term_slugs', $taxonomies[ $index ] );
        }

        // Sorted and de-duplicated, so every spelling of a selection resolves
        // to the same filters - and the canonical URL built from them is the
        // same for all of them (see includes/url-policy.php).
        $result = jpkcom_postfilter_normalize_filters( $raw );

        // Truncation only matters when strict URLs are off: with them on, the
        // request guard has already rejected an over-cap path before the main
        // query parses it (includes/crawl-guard.php).
        $max_per_group = (int) jpkcom_postfilter_policy( 'max_filters_per_group' );
        if ( $max_per_group > 0 ) {
            foreach ( $result as $taxonomy => $slugs ) {
                if ( count( $slugs ) > $max_per_group ) {
                    $result[ $taxonomy ] = array_slice( $slugs, 0, $max_per_group );
                }
            }
        }

        $max_combos = (int) jpkcom_postfilter_policy( 'max_filter_combos' );
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
     * Since 1.5.0 the slugs of each group are sorted and de-duplicated, so one
     * selection has exactly one URL whatever order it was clicked in.
     *
     * @since 1.0.0
     *
     * @param string                  $base_url Base archive URL.
     * @param array<string, string[]> $filters  Taxonomy slug => term slugs map.
     * @param int                     $page     Pagination page (0 or 1 = no page suffix).
     * @return string Constructed filter URL.
     */
    function jpkcom_postfilter_get_filter_url( string $base_url, array $filters, int $page = 0 ): string {
        $path = jpkcom_postfilter_filter_path_from_filters( $filters, jpkcom_postfilter_filter_taxonomies() );
        $url  = trailingslashit( $base_url );

        if ( $path !== '' ) {
            $endpoint = sanitize_key( jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) );
            $url     .= $endpoint . '/' . $path . '/';
        }

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

        // The permalink front (`/blog/` in `/blog/%postname%/`) is part of the
        // archive URL whenever the post type keeps `with_front` - core's own
        // archive rule and get_post_type_archive_link() both add it. Without
        // it here the rule matched a path the plugin never links to, and the
        // links it did build had no rule (404). Since 1.5.0 the request guard
        // would also redirect from the working path to the broken one.
        global $wp_rewrite;

        $front = '';
        if ( isset( $wp_rewrite ) && $wp_rewrite instanceof \WP_Rewrite && is_array( $obj->rewrite ) ) {
            $prefix = ! empty( $obj->rewrite['with_front'] ) ? $wp_rewrite->front : $wp_rewrite->root;
            $front  = trim( (string) $prefix, '/' );
        }

        return preg_quote( ltrim( ( $front !== '' ? $front . '/' : '' ) . trim( $slug, '/' ), '/' ), '#' );
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
if ( ! function_exists( function: 'jpkcom_postfilter_routable_post_types' ) ) {
    /**
     * Post types that get filter URL routes
     *
     * The globally enabled post types plus every post type a filter group
     * names, so filter URLs work even if the post type is not in the global
     * enabled list. Shared by the rewrite rules and the robots.txt rules.
     *
     * @since 1.5.0
     *
     * @return string[]
     */
    function jpkcom_postfilter_routable_post_types(): array {
        $post_types = array_map(
            'sanitize_key',
            (array) jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] )
        );

        foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
            if ( ! empty( $group['post_types'] ) && is_array( $group['post_types'] ) ) {
                foreach ( $group['post_types'] as $pt ) {
                    $post_types[] = sanitize_key( (string) $pt );
                }
            }
        }

        return array_values( array_unique( array_filter( $post_types ) ) );
    }
}


add_action( 'init', static function (): void {

    $endpoint       = sanitize_key( jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ) );
    $page_for_posts = (int) get_option( 'page_for_posts' );

    foreach ( jpkcom_postfilter_routable_post_types() as $post_type ) {
        $base_regex = jpkcom_postfilter_archive_base_regex( $post_type, $page_for_posts );
        if ( $base_regex === null ) {
            continue; // Post type has no archive
        }

        $prefix = $base_regex !== '' ? $base_regex . '/' : '';
        $qs     = jpkcom_postfilter_archive_query_string( $post_type, $page_for_posts );
        $extra  = ( $qs !== '' ? $qs . '&' : '' )
                . 'jpkcom_filter_post_type=' . rawurlencode( $post_type );

        // ---------------------------------------------------------------
        // Fragment rules — MUST be registered before the page rules below.
        //
        // add_rewrite_rule( …, 'top' ) collects into $wp_rewrite's
        // extra_rules_top in insertion order, and WordPress takes the first
        // match. The page rule's path group is `(.+?)` followed by `/?$`, which
        // happily swallows a trailing `/jpkpf-fragment` as part of the filter
        // path — so if these came second they would never be reached, and the
        // segment would silently be treated as a term slug.
        // ---------------------------------------------------------------
        $fragment = jpkcom_postfilter_fragment_segment();

        // Filtered, paginated
        add_rewrite_rule(
            '^' . $prefix . $endpoint . '/(.+?)/page/?([0-9]{1,})/' . $fragment . '/?$',
            'index.php?' . $extra . '&jpkcom_filter_path=$matches[1]&paged=$matches[2]&jpkcom_filter_fragment=1',
            'top'
        );

        // Filtered
        add_rewrite_rule(
            '^' . $prefix . $endpoint . '/(.+?)/' . $fragment . '/?$',
            'index.php?' . $extra . '&jpkcom_filter_path=$matches[1]&jpkcom_filter_fragment=1',
            'top'
        );

        // Unfiltered, paginated. Resetting all filters returns to the bare
        // archive URL — which carries no /{endpoint}/ segment at all — and the
        // script fetches that too, so it needs its own fragment route.
        add_rewrite_rule(
            '^' . $prefix . 'page/?([0-9]{1,})/' . $fragment . '/?$',
            'index.php?' . $extra . '&paged=$matches[1]&jpkcom_filter_fragment=1',
            'top'
        );

        // Unfiltered
        add_rewrite_rule(
            '^' . $prefix . $fragment . '/?$',
            'index.php?' . $extra . '&jpkcom_filter_fragment=1',
            'top'
        );

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
    $vars[] = 'jpkcom_filter_fragment';
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

    // A path that parses to nothing (`/_/`, `/+/`) is the unfiltered archive,
    // not a filter page: marking it active gave it a self-canonical and the
    // filter page robots policy, for a page identical to the archive.
    if ( ! empty( $segments ) ) {
        $query->set( 'jpkcom_filter_active', '1' );
    }

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
// Unknown term slugs
// ---------------------------------------------------------------------------

/*
 * Robots, canonical and the request guard live in includes/crawl-guard.php
 * since 1.5.0.
 */
if ( ! function_exists( function: 'jpkcom_postfilter_has_unknown_terms' ) ) {
    /**
     * Whether the active filters reference term slugs that do not exist
     *
     * Term slugs from the URL are sanitised but never checked for existence, so
     * `/blog/filter/does-not-exist/` renders a perfectly valid page with zero
     * results. Left alone, every made-up slug is therefore an indexable,
     * self-canonicalising thin-content URL that anyone can generate and link.
     *
     * Since 1.5.0 the request guard handles them before the main query
     * (`unknown_terms_action`, default: redirect to the selection without the
     * unknown slugs). This check remains for the `noindex` action, for strict
     * URLs switched off, and for the robots/canonical decision.
     *
     * Validation reuses the transient-cached per-taxonomy term list, so this
     * costs no extra query on a warm cache and cannot itself be used to flood
     * the cache: the cache key is the taxonomy, never the requested slugs.
     *
     * @since 1.1.7
     *
     * @return bool True if at least one requested slug is unknown.
     */
    function jpkcom_postfilter_has_unknown_terms(): bool {
        static $memo = [];

        $filters = jpkcom_postfilter_get_active_filters();

        if ( empty( $filters ) ) {
            return false;
        }

        // Asked by robots, canonical, the X-Robots-Tag header and the term
        // links on every filter page - once is enough.
        $key = serialize( $filters );
        if ( isset( $memo[ $key ] ) ) {
            return $memo[ $key ];
        }

        return $memo[ $key ] = jpkcom_postfilter_filters_have_unknown_terms( $filters );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_filters_have_unknown_terms' ) ) {
    /**
     * Whether a filter map references term slugs that do not exist
     *
     * @since 1.5.0
     *
     * @param array<string, string[]> $filters Taxonomy => term slugs.
     * @return bool
     */
    function jpkcom_postfilter_filters_have_unknown_terms( array $filters ): bool {
        foreach ( $filters as $taxonomy => $slugs ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                return true;
            }

            // Every language's slugs, terms without posts included - see
            // jpkcom_postfilter_known_term_slugs().
            $known = jpkcom_postfilter_known_term_slugs( $taxonomy ) ?? [];

            if ( ! empty( array_diff( $slugs, $known ) ) ) {
                return true;
            }
        }

        return false;
    }
}


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
        update_option( 'jpkcom_postfilter_flush_rewrite', '1', true );
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
