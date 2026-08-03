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
