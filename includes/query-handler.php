<?php
/**
 * Query Handler
 *
 * Builds and executes WP_Query instances for filtered post lists.
 *
 * Responsibilities:
 *  - Convert active filter maps into WP tax_query arrays
 *  - Wrap WP_Query construction with object-cache caching
 *  - Modify the main query on filter URL requests (via pre_get_posts)
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Tax query builder
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_build_tax_query' ) ) {
    /**
     * Build a WP_Query tax_query array from an active filters map
     *
     * Each taxonomy group uses OR logic between its own terms (e.g., posts in
     * category-A OR category-B). Multiple taxonomy groups are combined with AND
     * logic (e.g., must match the category group AND the tag group).
     *
     * @since 1.0.0
     *
     * @param array<string, string[]> $active_filters Taxonomy slug => term slugs.
     * @return array<int|string, mixed> WP-compatible tax_query array.
     */
    function jpkcom_postfilter_build_tax_query( array $active_filters ): array {
        if ( empty( $active_filters ) ) {
            return [];
        }

        $clauses = [];

        foreach ( $active_filters as $taxonomy => $term_slugs ) {
            $taxonomy   = sanitize_key( $taxonomy );
            $term_slugs = array_values( array_filter(
                array_map( 'sanitize_title', (array) $term_slugs )
            ) );

            if ( $taxonomy === '' || empty( $term_slugs ) ) {
                continue;
            }

            if ( ! taxonomy_exists( $taxonomy ) ) {
                jpkcom_postfilter_debug_log( "build_tax_query: taxonomy does not exist: {$taxonomy}" );
                continue;
            }

            $clauses[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $term_slugs,
                'operator' => 'IN', // OR within the same taxonomy group
            ];
        }

        if ( empty( $clauses ) ) {
            return [];
        }

        if ( count( $clauses ) === 1 ) {
            return $clauses;
        }

        // Multiple taxonomy groups → require ALL to match (AND)
        return array_merge( [ 'relation' => 'AND' ], $clauses );
    }
}


// ---------------------------------------------------------------------------
// Query args builder
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_build_query_args' ) ) {
    /**
     * Build WP_Query args for a filtered post list
     *
     * Merges caller attributes with active filters into a complete set of
     * WP_Query arguments. Accepted keys in `$atts`:
     *
     *   post_type  (string)  Post type slug.              Default: 'post'
     *   limit      (int)     posts_per_page value.        Default: -1 (all)
     *   orderby    (string)  WP orderby value.            Default: 'date'
     *   order      (string)  ASC|DESC.                    Default: 'DESC'
     *   paged      (int)     Current pagination page.     Default: auto-detected
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>    $atts           Caller-supplied arguments.
     * @param array<string, string[]> $active_filters Active taxonomy => term slugs.
     * @return array<string, mixed> Complete WP_Query args.
     */
    function jpkcom_postfilter_build_query_args( array $atts, array $active_filters = [] ): array {
        $post_type = sanitize_key( (string) ( $atts['post_type'] ?? 'post' ) );
        $limit     = (int) ( $atts['limit'] ?? -1 );
        $orderby   = sanitize_key( (string) ( $atts['orderby'] ?? 'date' ) );
        $order     = strtoupper( sanitize_key( (string) ( $atts['order'] ?? 'DESC' ) ) );
        $order     = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
        $paged     = isset( $atts['paged'] ) ? (int) $atts['paged'] : max( 1, (int) get_query_var( 'paged' ) );

        $args = [
            'post_type'           => $post_type,
            'posts_per_page'      => $limit,
            'orderby'             => $orderby,
            'order'               => $order,
            'paged'               => $paged,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => ( $limit === -1 ), // Skip COUNT(*) when not paginating
        ];

        // Pass through a small set of extra WP_Query args, each coerced into a
        // shape it is safe to hand to WP_Query.
        //
        // These used to be copied verbatim. Both shortcodes go through
        // shortcode_atts() and the block callback passes four fixed keys, but
        // the abilities integration does forward caller input: the query
        // ability hands its `search` argument through as `s`, sanitised here.
        // Because that makes the cache key caller-controlled, the ability sets
        // `cache => false` on the returned args whenever a search term is
        // present — see jpkcom_postfilter_ability_query_posts(). Every other key
        // below is coerced into a shape it is safe to hand to WP_Query.
        //
        // `meta_query` is deliberately not accepted: it is a nested structure
        // that cannot be meaningfully validated in passing. Callers that need
        // it can use the `jpkcom_postfilter_query_args` filter below, which
        // runs with full knowledge of its own input.
        if ( isset( $atts['s'] ) ) {
            $args['s'] = sanitize_text_field( (string) $atts['s'] );
        }

        if ( isset( $atts['meta_key'] ) && is_scalar( $atts['meta_key'] ) ) {
            $meta_key = sanitize_text_field( (string) $atts['meta_key'] );

            if ( $meta_key !== '' ) {
                $args['meta_key'] = $meta_key;

                // meta_value without meta_key is meaningless to WP_Query.
                if ( isset( $atts['meta_value'] ) && is_scalar( $atts['meta_value'] ) ) {
                    $args['meta_value'] = sanitize_text_field( (string) $atts['meta_value'] );
                }
            }
        }

        if ( isset( $atts['author'] ) ) {
            $authors = array_values( array_filter(
                array_map( 'absint', is_array( $atts['author'] ) ? $atts['author'] : explode( ',', (string) $atts['author'] ) )
            ) );

            if ( ! empty( $authors ) ) {
                $args['author__in'] = $authors;
            }
        }

        foreach ( [ 'year', 'monthnum' ] as $date_key ) {
            if ( isset( $atts[ $date_key ] ) ) {
                $value = absint( $atts[ $date_key ] );

                if ( $value > 0 ) {
                    $args[ $date_key ] = $value;
                }
            }
        }

        // Apply taxonomy filters
        $tax_query = jpkcom_postfilter_build_tax_query( $active_filters );
        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query;
        }

        /**
         * Filter the built WP_Query args before execution
         *
         * @since 1.0.0
         *
         * @param array<string, mixed>    $args           Complete WP_Query arguments.
         * @param array<string, mixed>    $atts           Original caller attributes.
         * @param array<string, string[]> $active_filters Active taxonomy => term slugs.
         */
        return (array) apply_filters( 'jpkcom_postfilter_query_args', $args, $atts, $active_filters );
    }
}


// ---------------------------------------------------------------------------
// Cached query execution
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_run_query' ) ) {
    /**
     * Execute a WP_Query with object-cache caching
     *
     * Results are stored using the cache manager. Pass `'cache' => false` in
     * `$query_args` to bypass the cache for a specific call.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>    $query_args     WP_Query arguments.
     * @param array<string, string[]> $active_filters Active filters (part of cache key).
     * @return \WP_Query Executed query object.
     */
    function jpkcom_postfilter_run_query( array $query_args, array $active_filters = [] ): \WP_Query {
        $cache_enabled = JPKCOM_POSTFILTER_CACHE_ENABLED && (bool) ( $query_args['cache'] ?? true );
        unset( $query_args['cache'] );

        if ( $cache_enabled ) {
            $cache_key    = jpkcom_postfilter_query_cache_key( $query_args, $active_filters );
            $cached_query = jpkcom_postfilter_cache_get( $cache_key );

            if ( $cached_query instanceof \WP_Query ) {
                jpkcom_postfilter_debug_log( 'run_query: cache hit', [ 'key' => $cache_key ] );
                return $cached_query;
            }
        }

        $query = new \WP_Query( $query_args );

        if ( $cache_enabled && isset( $cache_key ) ) {
            $ttl = (int) jpkcom_postfilter_settings_get( 'cache', 'cache_ttl', JPKCOM_POSTFILTER_CACHE_TTL );
            jpkcom_postfilter_cache_set( $cache_key, $query, $ttl );
            jpkcom_postfilter_debug_log( 'run_query: cached', [ 'key' => $cache_key, 'found' => $query->found_posts ] );
        }

        return $query;
    }
}


// ---------------------------------------------------------------------------
// Main query modification
// ---------------------------------------------------------------------------

/**
 * Apply active tax filters to the main query on filter URL requests
 *
 * Fires after WP_Query::parse_query() sets conditional tags. Only runs on the
 * main frontend query when a filter URL is active.
 *
 * @since 1.0.0
 */
add_action( 'pre_get_posts', static function ( \WP_Query $query ): void {

    if ( ! $query->is_main_query() || is_admin() ) {
        return;
    }

    if ( (string) $query->get( 'jpkcom_filter_path' ) === '' ) {
        return;
    }

    $active_filters = jpkcom_postfilter_get_active_filters();
    if ( empty( $active_filters ) ) {
        return;
    }

    $tax_query = jpkcom_postfilter_build_tax_query( $active_filters );
    if ( empty( $tax_query ) ) {
        return;
    }

    $existing = $query->get( 'tax_query' );
    $existing = is_array( $existing ) ? $existing : [];
    $query->set( 'tax_query', array_merge( $existing, $tax_query ) );

    jpkcom_postfilter_debug_log( 'pre_get_posts: tax_query applied to main query', [
        'tax_query' => $tax_query,
    ] );

}, 10 );
