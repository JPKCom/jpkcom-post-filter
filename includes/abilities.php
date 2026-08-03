<?php
/**
 * Abilities API integration
 *
 * Registers read-only WordPress Abilities that expose the plugin's faceted
 * query capability to MCP clients, REST automation and the WordPress AI
 * client. Registration is skipped entirely when the Abilities API is absent or
 * when JPKCOM_POSTFILTER_ABILITIES is false.
 *
 * @package JPKCom_Post_Filter
 * @since 1.3.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

/**
 * Ability category shared with the other JPKCom content plugins.
 */
const JPKCOM_POSTFILTER_ABILITY_CATEGORY = 'jpkcom-content';

/**
 * Default page size for the query ability.
 */
const JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT = 10;

/**
 * Maximum page size for the query ability.
 */
const JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX = 50;


if ( ! function_exists( function: 'jpkcom_postfilter_ability_clamp_per_page' ) ) {
    /**
     * Clamp a per_page value into the range the query ability supports
     *
     * Guards against the shortcode default of -1, which makes
     * jpkcom_postfilter_build_query_args() set no_found_rows and therefore
     * report a total of 0 regardless of how many posts exist.
     *
     * @since 1.3.0
     *
     * @param mixed $value Raw per_page input.
     * @return int Value between 1 and JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX.
     */
    function jpkcom_postfilter_ability_clamp_per_page( mixed $value ): int {
        if ( ! is_numeric( $value ) ) {
            return JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT;
        }

        $per_page = (int) $value;

        if ( $per_page < 1 ) {
            return JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT;
        }

        return min( $per_page, JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_normalize_filters' ) ) {
    /**
     * Normalise the filters input into a taxonomy => term slugs map
     *
     * Accepts a scalar term as shorthand for a single-element list, drops
     * duplicates and empty taxonomies, and skips values that are not scalar
     * rather than letting a TypeError escape.
     *
     * @since 1.3.0
     *
     * @param mixed $filters Raw filters input.
     * @return array<string, string[]> Normalised taxonomy => term slugs map.
     */
    function jpkcom_postfilter_ability_normalize_filters( mixed $filters ): array {
        if ( ! is_array( $filters ) ) {
            return [];
        }

        $normalized = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $taxonomy_key = sanitize_key( (string) $taxonomy );

            if ( $taxonomy_key === '' ) {
                continue;
            }

            $slug_list = is_array( $slugs ) ? $slugs : [ $slugs ];
            $clean     = [];

            foreach ( $slug_list as $slug ) {
                if ( ! is_scalar( $slug ) ) {
                    continue;
                }

                $clean_slug = sanitize_title( (string) $slug );

                if ( $clean_slug === '' ) {
                    continue;
                }

                if ( ! in_array( needle: $clean_slug, haystack: $clean, strict: true ) ) {
                    $clean[] = $clean_slug;
                }
            }

            if ( $clean !== [] ) {
                $normalized[ $taxonomy_key ] = $clean;
            }
        }

        return $normalized;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_group_applies' ) ) {
    /**
     * Decide whether a filter group applies to a post type
     *
     * Mirrors the rule used by all three render call sites: a group applies
     * when its post_types list contains the post type, or - when that list is
     * empty or absent - when the post type is globally enabled.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $group              Filter group config.
     * @param string               $post_type          Post type to test.
     * @param string[]             $enabled_post_types Globally enabled post types.
     * @return bool True when the group applies.
     */
    function jpkcom_postfilter_ability_group_applies( array $group, string $post_type, array $enabled_post_types ): bool {
        $group_post_types = $group['post_types'] ?? [];

        if ( ! is_array( $group_post_types ) || $group_post_types === [] ) {
            return in_array( needle: $post_type, haystack: $enabled_post_types, strict: true );
        }

        return in_array( needle: $post_type, haystack: $group_post_types, strict: true );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_allowed_taxonomies' ) ) {
    /**
     * Collect the taxonomies that may be used to filter a post type
     *
     * @since 1.3.0
     *
     * @param string                            $post_type          Post type to test.
     * @param array<int, array<string, mixed>>  $groups             Enabled filter groups.
     * @param string[]                          $enabled_post_types Globally enabled post types.
     * @return string[] Unique taxonomy keys.
     */
    function jpkcom_postfilter_ability_allowed_taxonomies( string $post_type, array $groups, array $enabled_post_types ): array {
        $allowed = [];

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $taxonomy = (string) ( $group['taxonomy'] ?? '' );

            if ( $taxonomy === '' ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_group_applies( $group, $post_type, $enabled_post_types ) ) {
                continue;
            }

            if ( ! in_array( needle: $taxonomy, haystack: $allowed, strict: true ) ) {
                $allowed[] = $taxonomy;
            }
        }

        return $allowed;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_validate_filters' ) ) {
    /**
     * Reject filters that name a taxonomy which is not filterable
     *
     * The query pipeline drops an unknown taxonomy clause silently and then
     * returns the complete unfiltered result set, so this check is the only
     * thing standing between a mistyped taxonomy and a wrong answer presented
     * as a filtered one. The error message names the valid taxonomies so the
     * caller can correct itself without another round trip.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters            Normalised filters map.
     * @param string[]                $allowed_taxonomies Taxonomies that may be filtered.
     * @return true|\WP_Error True when valid, WP_Error otherwise.
     */
    function jpkcom_postfilter_ability_validate_filters( array $filters, array $allowed_taxonomies ): true|\WP_Error {
        $unknown = [];

        foreach ( array_keys( $filters ) as $taxonomy ) {
            if ( ! in_array( needle: (string) $taxonomy, haystack: $allowed_taxonomies, strict: true ) ) {
                $unknown[] = (string) $taxonomy;
            }
        }

        if ( $unknown === [] ) {
            return true;
        }

        $valid = $allowed_taxonomies === []
            ? __( 'none for this post type', 'jpkcom-post-filter' )
            : implode( ', ', $allowed_taxonomies );

        return new \WP_Error(
            'jpkcom_postfilter_unknown_taxonomy',
            sprintf(
                /* translators: 1: comma-separated rejected taxonomy keys, 2: comma-separated valid taxonomy keys. */
                __( 'Unknown filter taxonomy: %1$s. Valid taxonomies for this post type: %2$s.', 'jpkcom-post-filter' ),
                implode( ', ', $unknown ),
                $valid
            )
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_unknown_post_type_error' ) ) {
    /**
     * Build the error returned for a post type that is not enabled for filtering
     *
     * @since 1.3.0
     *
     * @param string   $post_type          The rejected post type.
     * @param string[] $enabled_post_types Post types that are enabled.
     * @return \WP_Error The error to return from the callback.
     */
    function jpkcom_postfilter_ability_unknown_post_type_error( string $post_type, array $enabled_post_types ): \WP_Error {
        $valid = $enabled_post_types === []
            ? __( 'none', 'jpkcom-post-filter' )
            : implode( ', ', $enabled_post_types );

        return new \WP_Error(
            'jpkcom_postfilter_unknown_post_type',
            sprintf(
                /* translators: 1: rejected post type, 2: comma-separated enabled post types. */
                __( 'Post type "%1$s" is not enabled for filtering. Enabled post types: %2$s.', 'jpkcom-post-filter' ),
                $post_type,
                $valid
            )
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_resolve_post_type' ) ) {
    /**
     * Resolve the post_type input, applying the default the schema advertises
     *
     * Core applies only a top-level schema default and only when the input is
     * exactly null, so per-property defaults have to be applied here.
     *
     * @since 1.3.0
     *
     * @param mixed $value Raw post_type input.
     * @return string Sanitised post type, defaulting to 'post'.
     */
    function jpkcom_postfilter_ability_resolve_post_type( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return 'post';
        }

        $post_type = sanitize_key( $value );

        return $post_type === '' ? 'post' : $post_type;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_enabled_post_types' ) ) {
    /**
     * Read the post types that are enabled for filtering
     *
     * @since 1.3.0
     *
     * @return string[] Enabled post type slugs.
     */
    function jpkcom_postfilter_ability_enabled_post_types(): array {
        $enabled = jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] );

        if ( ! is_array( $enabled ) ) {
            return [ 'post' ];
        }

        $clean = [];

        foreach ( $enabled as $post_type ) {
            if ( is_string( $post_type ) && $post_type !== '' ) {
                $clean[] = $post_type;
            }
        }

        return $clean;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_taxonomy_is_disclosable' ) ) {
    /**
     * Decide whether a taxonomy may be named in an ability response
     *
     * Ability listings are readable by any logged-in user, so a taxonomy that
     * is neither public nor REST-exposed is withheld.
     *
     * @since 1.3.0
     *
     * @param string $taxonomy Taxonomy key.
     * @return bool True when the taxonomy may be disclosed.
     */
    function jpkcom_postfilter_ability_taxonomy_is_disclosable( string $taxonomy ): bool {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }

        $object = get_taxonomy( $taxonomy );

        if ( $object === false ) {
            return false;
        }

        return (bool) $object->public || (bool) $object->show_in_rest;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_list_filters' ) ) {
    /**
     * Execute callback for jpkcom-post-filter/list-filters
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input.
     * @return array<string, mixed>|\WP_Error Filter groups, or an error.
     */
    function jpkcom_postfilter_ability_list_filters( mixed $input ): array|\WP_Error {
        $input     = is_array( $input ) ? $input : [];
        $post_type = jpkcom_postfilter_ability_resolve_post_type( $input['post_type'] ?? null );

        $enabled_post_types = jpkcom_postfilter_ability_enabled_post_types();

        if ( ! in_array( needle: $post_type, haystack: $enabled_post_types, strict: true ) ) {
            return jpkcom_postfilter_ability_unknown_post_type_error( $post_type, $enabled_post_types );
        }

        $groups = [];

        foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $taxonomy = (string) ( $group['taxonomy'] ?? '' );

            if ( $taxonomy === '' ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_group_applies( $group, $post_type, $enabled_post_types ) ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_taxonomy_is_disclosable( $taxonomy ) ) {
                continue;
            }

            $terms = [];

            foreach ( jpkcom_postfilter_get_terms_for_group( $group ) as $entry ) {
                $term = is_array( $entry ) ? ( $entry['term'] ?? null ) : null;

                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }

                $terms[] = [
                    'slug'  => (string) $term->slug,
                    'name'  => (string) $term->name,
                    'count' => (int) $term->count,
                ];
            }

            if ( $terms === [] ) {
                continue;
            }

            $groups[] = [
                'taxonomy' => $taxonomy,
                'label'    => (string) ( $group['label'] ?? $taxonomy ),
                'terms'    => $terms,
            ];
        }

        return [
            'post_type' => $post_type,
            'groups'    => $groups,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_unknown_terms' ) ) {
    /**
     * Report requested term slugs that match no term
     *
     * Not an error: the website answers such a request with HTTP 200, zero
     * results and a noindex robots tag. Reporting them lets a caller tell a
     * typo apart from an genuinely empty result set.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters Normalised filters map.
     * @return array<string, string[]> Taxonomy => unmatched term slugs.
     */
    function jpkcom_postfilter_ability_unknown_terms( array $filters ): array {
        $unknown = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $missing = [];

            foreach ( $slugs as $slug ) {
                if ( ! get_term_by( 'slug', $slug, (string) $taxonomy ) instanceof \WP_Term ) {
                    $missing[] = $slug;
                }
            }

            if ( $missing !== [] ) {
                $unknown[ (string) $taxonomy ] = $missing;
            }
        }

        return $unknown;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_project_post' ) ) {
    /**
     * Project a post into the JSON-serialisable shape the output schema promises
     *
     * @since 1.3.0
     *
     * @param \WP_Post $post       The post to project.
     * @param string[] $taxonomies Filterable taxonomies to report terms for.
     * @return array<string, mixed> Projected post.
     */
    function jpkcom_postfilter_ability_project_post( \WP_Post $post, array $taxonomies ): array {
        $terms = [];

        foreach ( $taxonomies as $taxonomy ) {
            $assigned = get_the_terms( $post, $taxonomy );

            if ( ! is_array( $assigned ) ) {
                continue;
            }

            $list = [];

            foreach ( $assigned as $term ) {
                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }

                $list[] = [
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                ];
            }

            if ( $list !== [] ) {
                $terms[ $taxonomy ] = $list;
            }
        }

        return [
            'id'      => (int) $post->ID,
            'title'   => (string) get_the_title( $post ),
            'url'     => (string) get_permalink( $post ),
            'date'    => (string) get_post_time( 'c', true, $post ),
            'excerpt' => (string) get_the_excerpt( $post ),
            'terms'   => $terms,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_query_posts' ) ) {
    /**
     * Execute callback for jpkcom-post-filter/query-posts
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input.
     * @return array<string, mixed>|\WP_Error Query result, or an error.
     */
    function jpkcom_postfilter_ability_query_posts( mixed $input ): array|\WP_Error {
        $input     = is_array( $input ) ? $input : [];
        $post_type = jpkcom_postfilter_ability_resolve_post_type( $input['post_type'] ?? null );

        $enabled_post_types = jpkcom_postfilter_ability_enabled_post_types();

        if ( ! in_array( needle: $post_type, haystack: $enabled_post_types, strict: true ) ) {
            return jpkcom_postfilter_ability_unknown_post_type_error( $post_type, $enabled_post_types );
        }

        $filters = jpkcom_postfilter_ability_normalize_filters( $input['filters'] ?? [] );
        $allowed = jpkcom_postfilter_ability_allowed_taxonomies(
            $post_type,
            jpkcom_postfilter_get_filter_groups_enabled(),
            $enabled_post_types
        );

        $validity = jpkcom_postfilter_ability_validate_filters( $filters, $allowed );

        if ( $validity instanceof \WP_Error ) {
            return $validity;
        }

        $per_page = jpkcom_postfilter_ability_clamp_per_page( $input['per_page'] ?? null );
        $page     = isset( $input['page'] ) && is_numeric( $input['page'] )
            ? max( 1, (int) $input['page'] )
            : 1;

        $atts = [
            'post_type' => $post_type,
            'limit'     => $per_page,
            'paged'     => $page,
        ];

        if ( isset( $input['search'] ) && is_string( $input['search'] ) && $input['search'] !== '' ) {
            $atts['s'] = $input['search'];
        }

        $query = jpkcom_postfilter_run_query(
            jpkcom_postfilter_build_query_args( $atts, $filters ),
            $filters
        );

        $posts = [];

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $posts[] = jpkcom_postfilter_ability_project_post( $post, $allowed );
        }

        return [
            'post_type'     => $post_type,
            'filters'       => $filters,
            'total'         => (int) $query->found_posts,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => (int) $query->max_num_pages,
            'filter_url'    => jpkcom_postfilter_get_filter_url(
                jpkcom_postfilter_get_archive_base_url( $post_type ),
                $filters,
                $page
            ),
            'unknown_terms' => jpkcom_postfilter_ability_unknown_terms( $filters ),
            'posts'         => $posts,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_meta' ) ) {
    /**
     * Build the meta array for an ability
     *
     * show_in_rest governs core REST visibility. The public key is inert on
     * WordPress 6.9 and 7.0 and seeds show_in_rest from 7.1 onwards. The mcp
     * key is the MCP Adapter's own gate and is ignored by core.
     *
     * @since 1.3.0
     *
     * @param string $ability_name Fully qualified ability name.
     * @return array<string, mixed> Meta array for wp_register_ability().
     */
    function jpkcom_postfilter_ability_meta( string $ability_name ): array {
        $meta = [
            'show_in_rest' => true,
            'public'       => true,
            'mcp'          => [ 'public' => true ],
            'annotations'  => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
        ];

        /**
         * Filter the meta array of a JPKCom Post Filter ability
         *
         * Use this to withdraw an ability from REST or MCP on a specific site.
         *
         * @since 1.3.0
         *
         * @param array<string, mixed> $meta         Meta array.
         * @param string               $ability_name Fully qualified ability name.
         */
        $filtered = apply_filters( 'jpkcom_postfilter_ability_meta', $meta, $ability_name );

        return is_array( $filtered ) ? $filtered : $meta;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_user_can' ) ) {
    /**
     * Check the capability required to run an ability
     *
     * Defaults to 'read'. The query is hard-scoped to published posts, so this
     * cannot expose drafts or private content - but it is bulk machine-readable
     * access, which a site may want to restrict further.
     *
     * @since 1.3.0
     *
     * @param string $ability_name Fully qualified ability name.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_user_can( string $ability_name ): bool {
        /**
         * Filter the capability required to run a JPKCom Post Filter ability
         *
         * @since 1.3.0
         *
         * @param string $capability   Capability name.
         * @param string $ability_name Fully qualified ability name.
         */
        $capability = apply_filters( 'jpkcom_postfilter_ability_capability', 'read', $ability_name );

        return current_user_can( is_string( $capability ) ? $capability : 'read' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_permission_list_filters' ) ) {
    /**
     * Permission callback for jpkcom-post-filter/list-filters
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input, unused.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_permission_list_filters( mixed $input = null ): bool {
        return jpkcom_postfilter_ability_user_can( 'jpkcom-post-filter/list-filters' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_permission_query_posts' ) ) {
    /**
     * Permission callback for jpkcom-post-filter/query-posts
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input, unused.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_permission_query_posts( mixed $input = null ): bool {
        return jpkcom_postfilter_ability_user_can( 'jpkcom-post-filter/query-posts' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_ability_definitions' ) ) {
    /**
     * Build the registration arguments for every ability this plugin provides
     *
     * Pure: touches no WordPress state, calls no registry, has no side effects.
     * That is what lets the CI harness assert the shape of these arrays without
     * a WordPress installation.
     *
     * @since 1.3.0
     *
     * @return array<string, array<string, mixed>> Ability name => wp_register_ability() args.
     */
    function jpkcom_postfilter_get_ability_definitions(): array {
        $term_schema = [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => __( 'Term slug. Use this value inside the "filters" input of jpkcom-post-filter/query-posts.', 'jpkcom-post-filter' ),
                ],
                'name' => [
                    'type'        => 'string',
                    'description' => __( 'Human-readable term name.', 'jpkcom-post-filter' ),
                ],
                'count' => [
                    'type'        => 'integer',
                    'description' => __( 'Number of published posts carrying this term across the whole site. This total is NOT narrowed by any other active filter, so it does not describe the size of a combined result set.', 'jpkcom-post-filter' ),
                ],
            ],
        ];

        return [
            'jpkcom-post-filter/list-filters' => [
                'label'       => __( 'List available post filters', 'jpkcom-post-filter' ),
                'description' => __( 'Returns the taxonomies and terms that can be used to filter a post type on this site. Call this before jpkcom-post-filter/query-posts so taxonomy keys and term slugs never have to be guessed.', 'jpkcom-post-filter' ),
                'category'    => JPKCOM_POSTFILTER_ABILITY_CATEGORY,

                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'Post type to list filters for. Defaults to "post".', 'jpkcom-post-filter' ),
                            'default'     => 'post',
                        ],
                    ],
                ],

                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'The post type these filters apply to.', 'jpkcom-post-filter' ),
                        ],
                        'groups' => [
                            'type'        => 'array',
                            'description' => __( 'Configured filter groups, in the order they appear on the site.', 'jpkcom-post-filter' ),
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'taxonomy' => [
                                        'type'        => 'string',
                                        'description' => __( 'Taxonomy key. Use this value as a key in the "filters" input of jpkcom-post-filter/query-posts.', 'jpkcom-post-filter' ),
                                    ],
                                    'label' => [
                                        'type'        => 'string',
                                        'description' => __( 'Human-readable label configured for this filter group.', 'jpkcom-post-filter' ),
                                    ],
                                    'terms' => [
                                        'type'        => 'array',
                                        'description' => __( 'Terms available for this taxonomy.', 'jpkcom-post-filter' ),
                                        'items'       => $term_schema,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],

                'execute_callback'    => 'jpkcom_postfilter_ability_list_filters',
                'permission_callback' => 'jpkcom_postfilter_ability_permission_list_filters',
                'meta'                => jpkcom_postfilter_ability_meta( 'jpkcom-post-filter/list-filters' ),
            ],

            'jpkcom-post-filter/query-posts' => [
                'label'       => __( 'Query filtered posts', 'jpkcom-post-filter' ),
                'description' => __( 'Runs a taxonomy-filtered, paginated query over published posts and returns the results together with a shareable filter URL. Only taxonomies reported by jpkcom-post-filter/list-filters are accepted; any other taxonomy key is rejected with an error naming the valid ones.', 'jpkcom-post-filter' ),
                'category'    => JPKCOM_POSTFILTER_ABILITY_CATEGORY,

                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'Post type to query. Must be enabled for filtering on this site. Defaults to "post".', 'jpkcom-post-filter' ),
                            'default'     => 'post',
                        ],
                        'filters' => [
                            'type'                 => 'object',
                            'description'          => __( 'Taxonomy filters as a map of taxonomy key to a list of term slugs, for example {"category":["news"],"post_tag":["seo"]}. Terms within one taxonomy are combined with OR, different taxonomies with AND.', 'jpkcom-post-filter' ),
                            'additionalProperties' => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                        'page' => [
                            'type'        => 'integer',
                            'description' => __( 'Page number, starting at 1.', 'jpkcom-post-filter' ),
                            'minimum'     => 1,
                            'default'     => 1,
                        ],
                        'per_page' => [
                            'type'        => 'integer',
                            'description' => __( 'Number of posts to return per page.', 'jpkcom-post-filter' ),
                            'minimum'     => 1,
                            'maximum'     => JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX,
                            'default'     => JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT,
                        ],
                        'search' => [
                            'type'        => 'string',
                            'description' => __( 'Optional free-text search applied in addition to the taxonomy filters.', 'jpkcom-post-filter' ),
                        ],
                    ],
                ],

                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'The post type that was queried.', 'jpkcom-post-filter' ),
                        ],
                        'filters' => [
                            'type'        => 'object',
                            'description' => __( 'The filters that were actually applied, after normalisation.', 'jpkcom-post-filter' ),
                        ],
                        'total' => [
                            'type'        => 'integer',
                            'description' => __( 'Total number of published posts matching the filters.', 'jpkcom-post-filter' ),
                        ],
                        'page' => [
                            'type'        => 'integer',
                            'description' => __( 'The page that was returned.', 'jpkcom-post-filter' ),
                        ],
                        'per_page' => [
                            'type'        => 'integer',
                            'description' => __( 'The page size that was applied after clamping.', 'jpkcom-post-filter' ),
                        ],
                        'total_pages' => [
                            'type'        => 'integer',
                            'description' => __( 'Number of pages available for these filters.', 'jpkcom-post-filter' ),
                        ],
                        'filter_url' => [
                            'type'        => 'string',
                            'description' => __( 'Shareable front-end URL showing this filter combination.', 'jpkcom-post-filter' ),
                        ],
                        'unknown_terms' => [
                            'type'        => 'object',
                            'description' => __( 'Requested term slugs that match no existing term, keyed by taxonomy. A non-empty value explains why a result set is empty.', 'jpkcom-post-filter' ),
                        ],
                        'posts' => [
                            'type'        => 'array',
                            'description' => __( 'The matching posts for the requested page.', 'jpkcom-post-filter' ),
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'      => [ 'type' => 'integer', 'description' => __( 'Post ID.', 'jpkcom-post-filter' ) ],
                                    'title'   => [ 'type' => 'string', 'description' => __( 'Post title.', 'jpkcom-post-filter' ) ],
                                    'url'     => [ 'type' => 'string', 'description' => __( 'Permalink.', 'jpkcom-post-filter' ) ],
                                    'date'    => [ 'type' => 'string', 'description' => __( 'Publication date in ISO 8601, UTC.', 'jpkcom-post-filter' ) ],
                                    'excerpt' => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'jpkcom-post-filter' ) ],
                                    'terms'   => [ 'type' => 'object', 'description' => __( 'Assigned terms of the filterable taxonomies, keyed by taxonomy.', 'jpkcom-post-filter' ) ],
                                ],
                            ],
                        ],
                    ],
                ],

                'execute_callback'    => 'jpkcom_postfilter_ability_query_posts',
                'permission_callback' => 'jpkcom_postfilter_ability_permission_query_posts',
                'meta'                => jpkcom_postfilter_ability_meta( 'jpkcom-post-filter/query-posts' ),
            ],
        ];
    }
}
