<?php
/**
 * Taxonomy Registration
 *
 * Reads filter group configuration from settings and:
 *  - Registers any custom taxonomies defined in the backend
 *  - Provides helper functions to query filter groups and their terms
 *
 * Custom taxonomy schema (per filter group):
 *   slug       – unique slug for the group / taxonomy
 *   taxonomy   – WP taxonomy name (may be existing or new custom one)
 *   label      – frontend label (e.g. "Category", "Topic")
 *   order      – sort order for URL segment mapping
 *   enabled    – whether this group is active
 *   hide_empty – whether to hide terms with no posts
 *   custom     – (bool) whether this is a plugin-registered custom taxonomy
 *   post_types – post types this taxonomy is registered for
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Filter group helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_get_filter_groups' ) ) {
    /**
     * Get all configured filter groups (sorted by order)
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> Ordered list of filter group configs.
     */
    function jpkcom_postfilter_get_filter_groups(): array {
        $settings = jpkcom_postfilter_settings_get_group( 'filter_groups' );
        $groups   = $settings['groups'] ?? [];

        if ( ! is_array( $groups ) ) {
            return [];
        }

        // Sort by order ascending
        usort( $groups, static fn( array $a, array $b ): int => (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) );

        return $groups;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_filter_groups_enabled' ) ) {
    /**
     * Get only enabled filter groups (sorted by order)
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> Ordered list of enabled filter group configs.
     */
    function jpkcom_postfilter_get_filter_groups_enabled(): array {
        return array_values( array_filter(
            jpkcom_postfilter_get_filter_groups(),
            static fn( array $g ): bool => (bool) ( $g['enabled'] ?? true )
        ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_filter_group_by_slug' ) ) {
    /**
     * Get a filter group config by its slug
     *
     * @since 1.0.0
     *
     * @param string $slug Filter group slug.
     * @return array<string, mixed>|null Group config or null if not found.
     */
    function jpkcom_postfilter_get_filter_group_by_slug( string $slug ): ?array {
        foreach ( jpkcom_postfilter_get_filter_groups() as $group ) {
            if ( ( $group['slug'] ?? '' ) === $slug ) {
                return $group;
            }
        }
        return null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_filter_group_by_taxonomy' ) ) {
    /**
     * Get a filter group config by its taxonomy name
     *
     * @since 1.0.0
     *
     * @param string $taxonomy WP taxonomy name.
     * @return array<string, mixed>|null Group config or null.
     */
    function jpkcom_postfilter_get_filter_group_by_taxonomy( string $taxonomy ): ?array {
        foreach ( jpkcom_postfilter_get_filter_groups() as $group ) {
            if ( ( $group['taxonomy'] ?? '' ) === $taxonomy ) {
                return $group;
            }
        }
        return null;
    }
}


// ---------------------------------------------------------------------------
// Term retrieval with caching
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_get_terms_for_taxonomy' ) ) {
    /**
     * Get all terms for a taxonomy with transient caching
     *
     * Returns `WP_Term[]` sorted by name. Empty terms excluded when configured.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy   WP taxonomy name.
     * @param bool   $hide_empty Whether to hide terms with no posts. Default true.
     * @return \WP_Term[] Array of WP_Term objects.
     */
    function jpkcom_postfilter_get_terms_for_taxonomy( string $taxonomy, bool $hide_empty = true ): array {
        $cache_key = 'terms_' . $taxonomy . '_' . ( $hide_empty ? '1' : '0' );

        $cached = jpkcom_postfilter_transient_get( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hide_empty,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) ) {
            jpkcom_postfilter_debug_log( 'get_terms error for taxonomy: ' . $taxonomy, $terms->get_error_message() );
            return [];
        }

        $result = array_values( array_filter(
            $terms,
            static fn( mixed $t ): bool => $t instanceof \WP_Term
        ) );

        $ttl = (int) jpkcom_postfilter_settings_get( 'cache', 'cache_ttl', JPKCOM_POSTFILTER_CACHE_TTL );
        jpkcom_postfilter_transient_set( $cache_key, $result, $ttl );

        return $result;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_terms_for_group' ) ) {
    /**
     * Get terms for a filter group, enriched with active-state data
     *
     * Returns terms with an `is_active` flag based on the currently active filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>    $group          Filter group config.
     * @param array<string, string[]> $active_filters Active taxonomy => term slugs map.
     * @return array<int, array{term: \WP_Term, is_active: bool}> Enriched terms.
     */
    function jpkcom_postfilter_get_terms_for_group( array $group, array $active_filters = [] ): array {
        $taxonomy   = (string) ( $group['taxonomy'] ?? '' );
        $hide_empty = (bool) ( $group['hide_empty'] ?? true );

        if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }

        $terms          = jpkcom_postfilter_get_terms_for_taxonomy( $taxonomy, $hide_empty );
        $active_slugs   = $active_filters[ $taxonomy ] ?? [];
        $enriched       = [];

        foreach ( $terms as $term ) {
            $enriched[] = [
                'term'      => $term,
                'is_active' => in_array( $term->slug, $active_slugs, true ),
            ];
        }

        return $enriched;
    }
}


// ---------------------------------------------------------------------------
// Custom taxonomy registration
// ---------------------------------------------------------------------------

/**
 * Register custom taxonomies defined via the plugin's filter groups backend
 *
 * Only registers taxonomies that don't already exist in WordPress.
 * Taxonomy config must have `custom => true` to be registered here.
 *
 * @since 1.0.0
 */
add_action( 'init', static function (): void {

    $groups = jpkcom_postfilter_get_filter_groups();

    if ( empty( $groups ) ) {
        return;
    }

    $enabled_post_types = jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] );

    foreach ( $groups as $group ) {
        $taxonomy  = (string) ( $group['taxonomy'] ?? '' );
        $is_custom = (bool) ( $group['custom'] ?? false );

        if ( $taxonomy === '' || ! $is_custom ) {
            continue;
        }

        // Determine which post types this taxonomy should cover.
        $post_types = ! empty( $group['post_types'] ) && is_array( $group['post_types'] )
            ? $group['post_types']
            : $enabled_post_types;

        $post_types = array_values( array_intersect(
            array_map( 'sanitize_key', $post_types ),
            array_keys( get_post_types( [ 'public' => true ] ) )
        ) );

        if ( empty( $post_types ) ) {
            continue;
        }

        // If the taxonomy was already registered (e.g. from a previous request
        // where the CPT wasn't available yet), ensure all configured post types
        // are properly associated without re-registering from scratch.
        if ( taxonomy_exists( $taxonomy ) ) {
            foreach ( $post_types as $pt ) {
                register_taxonomy_for_object_type( $taxonomy, $pt );
            }
            jpkcom_postfilter_debug_log( "Updated object types for existing taxonomy: {$taxonomy}", [
                'post_types' => $post_types,
            ] );
            continue;
        }

        $label    = (string) ( $group['label'] ?? ucfirst( $taxonomy ) );
        $label_pl = $label . 's'; // Simple plural – proper i18n in Phase 5
        $slug     = sanitize_title( (string) ( $group['slug'] ?: $taxonomy ) );

        register_taxonomy(
            $taxonomy,
            $post_types,
            [
                'label'             => $label,
                'labels'            => [
                    'name'          => $label_pl,
                    'singular_name' => $label,
                    'search_items'  => sprintf( 'Search %s', $label_pl ),
                    'all_items'     => sprintf( 'All %s', $label_pl ),
                    'edit_item'     => sprintf( 'Edit %s', $label ),
                    'update_item'   => sprintf( 'Update %s', $label ),
                    'add_new_item'  => sprintf( 'Add New %s', $label ),
                    'new_item_name' => sprintf( 'New %s Name', $label ),
                    'menu_name'     => $label,
                ],
                'public'            => (bool) ( $group['public'] ?? true ),
                'show_in_rest'      => (bool) ( $group['show_in_rest'] ?? true ),
                'hierarchical'      => (bool) ( $group['hierarchical'] ?? false ),
                'rewrite'           => [
                    'slug'         => $slug,
                    'with_front'   => true,
                    'hierarchical' => false,
                ],
                'show_admin_column' => (bool) ( $group['show_admin_column'] ?? true ),
                'query_var'         => true,
                'capabilities'      => [
                    'manage_terms' => 'manage_categories',
                    'edit_terms'   => 'manage_categories',
                    'delete_terms' => 'manage_categories',
                    'assign_terms' => 'edit_posts',
                ],
            ]
        );

        jpkcom_postfilter_debug_log( "Registered custom taxonomy: {$taxonomy}", [
            'post_types' => $post_types,
            'slug'       => $slug,
        ] );
    }

}, 20 ); // Priority 20: runs after other plugins' init-10 hooks so all CPTs are available.


// ---------------------------------------------------------------------------
// Flush rewrite rules when filter groups change
// ---------------------------------------------------------------------------

/**
 * Flush rewrite rules after filter groups or general settings are saved
 *
 * @since 1.0.0
 */
add_action( 'jpkcom_postfilter_settings_saved', static function ( string $group ): void {
    if ( in_array( $group, [ 'filter_groups', 'general' ], true ) ) {
        // Schedule a flush on the next request rather than doing it inline
        update_option( 'jpkcom_postfilter_flush_rewrite', '1', false );
        jpkcom_postfilter_debug_log( "Rewrite flush scheduled after settings save: {$group}" );
    }
} );

/**
 * Apply the scheduled rewrite flush on init
 *
 * @since 1.0.0
 */
add_action( 'init', static function (): void {
    if ( get_option( 'jpkcom_postfilter_flush_rewrite' ) === '1' ) {
        delete_option( 'jpkcom_postfilter_flush_rewrite' );
        flush_rewrite_rules( false );
        jpkcom_postfilter_debug_log( 'Rewrite rules flushed' );
    }
}, 999 );
