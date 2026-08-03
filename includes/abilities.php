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
