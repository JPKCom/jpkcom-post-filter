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

/**
 * Longest search term, in BYTES, that WP_Query will actually apply.
 *
 * WP_Query::parse_query() blanks `s` when strlen() exceeds this, as an anti-DoS
 * measure, and it does so silently: no error, no filter, no notice. Because that
 * happens inside the query, past every check an ability could make, the caller
 * receives exactly the answer it would have got with no search term at all.
 *
 * The unit is bytes, not characters, because core calls strlen() rather than
 * mb_strlen() - so 801 x U+00FC is over the limit at 801 characters. A maxLength
 * in the JSON Schema counts characters and would therefore leave the hole open.
 */
const JPKCOM_POSTFILTER_ABILITY_SEARCH_MAX_BYTES = 1600;

/**
 * Input keys each ability accepts at the top level.
 *
 * Neither input schema declares additionalProperties, so core drops an unknown
 * key before the callback ever sees it. Without this list a caller that flattens
 * the nested map - `category` instead of `filters.category`, the commonest shape
 * error a tool-calling model makes - is answered with the complete unfiltered
 * corpus, HTTP 200 and `filters: {}`, which reads like a successful filtered
 * query. That is the failure jpkcom_postfilter_ability_validate_filters() exists
 * to prevent, reached by a route that guard does not stand on.
 */
const JPKCOM_POSTFILTER_ABILITY_INPUT_KEYS = [
    'jpkcom-post-filter/list-filters' => [ 'post_type' ],
    'jpkcom-post-filter/query-posts'  => [ 'post_type', 'filters', 'page', 'per_page', 'search' ],
];


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
     * The slug list per taxonomy is truncated at
     * JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX. `filters` is caller-controlled, and
     * every slug costs work further down the pipeline - one IN() member in the
     * tax query and one entry to diff for the unknown-terms report. Truncating
     * rather than erroring mirrors jpkcom_postfilter_parse_filter_path(), which
     * already caps over-long filter lists coming from a URL.
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

            if ( count( $clean ) > JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX ) {
                $clean = array_slice( $clean, 0, JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX );
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
     * A configured group whose taxonomy is not registered is skipped. The
     * configuration outlives the registration - deactivating the plugin that
     * registered a taxonomy leaves its filter group behind - and a group in that
     * state would otherwise be reported as filterable while
     * jpkcom_postfilter_build_tax_query() drops the clause it produces
     * (query-handler.php:57), which is the silent-full-corpus failure the
     * validation guard exists to prevent. Skipping the group here turns that case
     * into a jpkcom_postfilter_unknown_taxonomy error that names the real ones.
     *
     * This is why the function reads WordPress state; it is not pure.
     *
     * @since 1.3.0
     *
     * @param string                            $post_type          Post type to test.
     * @param array<int, array<string, mixed>>  $groups             Enabled filter groups.
     * @param string[]                          $enabled_post_types Globally enabled post types.
     * @return string[] Unique taxonomy keys that are registered right now.
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

            if ( ! taxonomy_exists( $taxonomy ) ) {
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
     * Carries data['status'] = 400. The REST run controller returns the WP_Error
     * verbatim and rest_ensure_response() defaults to 500 without it - which
     * tells an agent "transient server fault, retry the same call", the exact
     * opposite of the self-correction this message is written for.
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
            ),
            [ 'status' => 400 ]
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_validate_input_keys' ) ) {
    /**
     * Reject top-level input keys the ability does not accept
     *
     * Core validates the input against the declared schema, but neither schema
     * declares additionalProperties, so an unrecognised key is simply dropped and
     * the callback is handed input it never sent. The observable result is the
     * complete unfiltered corpus returned as a successful filtered query.
     *
     * Carries data['status'] = 400 for the same reason as the sibling guards: the
     * REST run controller returns the WP_Error verbatim and rest_ensure_response()
     * defaults to 500 without it, which reads as "retry unchanged".
     *
     * @since 1.3.1
     *
     * @param array<string, mixed> $input        Raw ability input.
     * @param string               $ability_name Fully qualified ability name.
     * @return true|\WP_Error True when every key is accepted, WP_Error otherwise.
     */
    function jpkcom_postfilter_ability_validate_input_keys( array $input, string $ability_name ): true|\WP_Error {
        $allowed = JPKCOM_POSTFILTER_ABILITY_INPUT_KEYS[ $ability_name ] ?? [];
        $unknown = [];

        foreach ( array_keys( $input ) as $key ) {
            if ( ! in_array( needle: (string) $key, haystack: $allowed, strict: true ) ) {
                $unknown[] = (string) $key;
            }
        }

        if ( $unknown === [] ) {
            return true;
        }

        return new \WP_Error(
            'jpkcom_postfilter_unknown_input_key',
            sprintf(
                /* translators: 1: comma-separated rejected input keys, 2: comma-separated accepted input keys. */
                __( 'Unknown input key: %1$s. Accepted keys: %2$s. Taxonomy filters belong inside "filters", as a map of taxonomy key to a list of term slugs.', 'jpkcom-post-filter' ),
                implode( ', ', $unknown ),
                implode( ', ', $allowed )
            ),
            [ 'status' => 400 ]
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_validate_search' ) ) {
    /**
     * Reject a search term WordPress would silently discard
     *
     * See JPKCOM_POSTFILTER_ABILITY_SEARCH_MAX_BYTES for why this cannot be a
     * maxLength in the input schema: JSON Schema counts characters and core
     * counts bytes, so a 1600-character limit still lets 1602 bytes of umlauts
     * through into the exact hole it was meant to close.
     *
     * @since 1.3.1
     *
     * @param mixed $search Raw search input.
     * @return true|\WP_Error True when the term is usable, WP_Error otherwise.
     */
    function jpkcom_postfilter_ability_validate_search( mixed $search ): true|\WP_Error {
        if ( ! is_string( $search ) ) {
            return true;
        }

        $bytes = strlen( $search );

        if ( $bytes <= JPKCOM_POSTFILTER_ABILITY_SEARCH_MAX_BYTES ) {
            return true;
        }

        return new \WP_Error(
            'jpkcom_postfilter_search_too_long',
            sprintf(
                /* translators: 1: submitted length in bytes, 2: maximum length in bytes. */
                __( 'The search term is %1$d bytes long; WordPress applies no search term over %2$d bytes. Shorten it and call again. The limit is counted in bytes, so accented and emoji characters cost more than one each.', 'jpkcom-post-filter' ),
                $bytes,
                JPKCOM_POSTFILTER_ABILITY_SEARCH_MAX_BYTES
            ),
            [ 'status' => 400 ]
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_unknown_post_type_error' ) ) {
    /**
     * Build the error returned for a post type that is not enabled for filtering
     *
     * Carries data['status'] = 400 for the same reason as the unknown-taxonomy
     * error: this is caller input, not a server fault, and the REST run
     * controller would otherwise answer 500.
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
            ),
            [ 'status' => 400 ]
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


if ( ! function_exists( function: 'jpkcom_postfilter_ability_list_filters' ) ) {
    /**
     * Execute callback for jpkcom-post-filter/list-filters
     *
     * Reports exactly the groups the site's own filter bar renders. There is no
     * extra disclosure rule: jpkcom_postfilter_get_terms_for_group() checks only
     * taxonomy_exists(), so every enabled group is already public HTML for
     * anonymous visitors. Withholding a taxonomy here would have been stricter
     * than the front end while changing nothing about what is reachable.
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input.
     * @return array<string, mixed>|\WP_Error Filter groups, or an error.
     */
    function jpkcom_postfilter_ability_list_filters( mixed $input ): array|\WP_Error {
        $input = is_array( $input ) ? $input : [];

        $keys_valid = jpkcom_postfilter_ability_validate_input_keys( $input, 'jpkcom-post-filter/list-filters' );

        if ( $keys_valid instanceof \WP_Error ) {
            return $keys_valid;
        }

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


if ( ! function_exists( function: 'jpkcom_postfilter_ability_json_object' ) ) {
    /**
     * Present a keyed map as a JSON object even when it is empty
     *
     * PHP serialises an empty array as the JSON array `[]`, but these values are
     * declared as objects in the output schema. A client that validates the
     * response against that schema rejects `[]` where it expects `{}`, so an
     * empty map is handed back as a stdClass instead. A non-empty map already
     * encodes as an object and is returned untouched, so PHP callers keep array
     * access in the case that carries data.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $value Map to present.
     * @return array<string, mixed>|\stdClass The map, or an empty object.
     */
    function jpkcom_postfilter_ability_json_object( array $value ): array|\stdClass {
        return $value === [] ? new \stdClass() : $value;
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
     * Checked the same way as jpkcom_postfilter_has_unknown_terms(): one
     * array_diff() against the transient-cached per-taxonomy term list, never
     * one lookup per requested slug. The cache key is the taxonomy, never the
     * requested slugs, so a caller cannot use this to flood the cache and a warm
     * cache costs no query at all.
     *
     * `hide_empty = false`: a term with no posts is still a real term.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters Normalised filters map.
     * @return array<string, string[]> Taxonomy => unmatched term slugs.
     */
    function jpkcom_postfilter_ability_unknown_terms( array $filters ): array {
        $unknown = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $known = [];

            foreach ( jpkcom_postfilter_get_terms_for_taxonomy( (string) $taxonomy, false ) as $term ) {
                if ( $term instanceof \WP_Term ) {
                    $known[] = (string) $term->slug;
                }
            }

            $missing = array_values( array_diff( $slugs, $known ) );

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
            'terms'   => jpkcom_postfilter_ability_json_object( $terms ),
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_filters_are_url_expressible' ) ) {
    /**
     * Decide whether a filter set survives a round trip through a filter URL
     *
     * jpkcom_postfilter_get_filter_url() writes every requested slug into the
     * path, but jpkcom_postfilter_parse_filter_path() truncates what it reads
     * back to max_filters_per_group slugs per taxonomy and max_filter_combos
     * taxonomies. Above either limit the built URL therefore shows a *different*
     * result set than the one the ability just reported - measured with four
     * category slugs against max_filters_per_group = 3, where the link resolved
     * to the first three.
     *
     * This is a second implementation of a rule that lives in
     * `includes/url-routing.php:74-87`. That parser is the authority: it decides
     * what a filter URL actually resolves to, and this function only predicts
     * it. Change the truncation there and this gate goes stale without any test
     * noticing - the suite stubs the settings getter and never loads
     * url-routing.php - and the ability resumes emitting links to a narrower
     * result set than it reports.
     *
     * Both caps treat 0 as unlimited, matching parse_filter_path(). Only
     * max_filters_per_group can actually be set to 0 through the admin UI;
     * settings.php clamps max_filter_combos to 1..10, so the unlimited branch of
     * that one is unreachable on a normally configured site. It is kept because
     * this function must mirror the parser, not the settings form.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters Normalised filters map.
     * @return bool True when a built URL parses back to exactly these filters.
     */
    function jpkcom_postfilter_ability_filters_are_url_expressible( array $filters ): bool {
        $max_combos = (int) jpkcom_postfilter_settings_get( 'general', 'max_filter_combos', 3 );

        if ( $max_combos > 0 && count( $filters ) > $max_combos ) {
            return false;
        }

        $max_per_group = (int) jpkcom_postfilter_settings_get( 'general', 'max_filters_per_group', 3 );

        if ( $max_per_group < 1 ) {
            return true;
        }

        foreach ( $filters as $slugs ) {
            if ( is_array( $slugs ) && count( $slugs ) > $max_per_group ) {
                return false;
            }
        }

        return true;
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
        $input = is_array( $input ) ? $input : [];

        $keys_valid = jpkcom_postfilter_ability_validate_input_keys( $input, 'jpkcom-post-filter/query-posts' );

        if ( $keys_valid instanceof \WP_Error ) {
            return $keys_valid;
        }

        // Checked before anything is built, because a term core would discard must
        // not reach the query at all: the caller has to learn the term was unusable
        // instead of receiving the unsearched result set dressed as a search.
        $search_valid = jpkcom_postfilter_ability_validate_search( $input['search'] ?? null );

        if ( $search_valid instanceof \WP_Error ) {
            return $search_valid;
        }

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

        $query_args = jpkcom_postfilter_build_query_args( $atts, $filters );

        // A search term is the one piece of free-form caller input that reaches
        // the query, and jpkcom_postfilter_run_query() caches unconditionally
        // under md5( serialize( $args ) ) - a serialised WP_Query with up to 50
        // full post objects per entry, in the object cache and in APCu. A caller
        // varying the term would fill both without bound, so searches are not
        // cached. build_query_args() drops `cache` through its allowlist, hence
        // setting it on the result rather than in $atts.
        if ( isset( $atts['s'] ) ) {
            $query_args['cache'] = false;
        }

        $query = jpkcom_postfilter_run_query( $query_args, $filters );

        $posts = [];

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $posts[] = jpkcom_postfilter_ability_project_post( $post, $allowed );
        }

        $total       = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;

        // WP_Query::set_found_posts() returns early when the result set is
        // empty, leaving found_posts and max_num_pages at 0. A page past the
        // last one would therefore answer "page 3, total 0, total_pages 0" -
        // internally contradictory, and a model reading it concludes the corpus
        // is empty. One extra query for page 1 recovers the real totals; it runs
        // only on this out-of-range path, so the normal path costs nothing, and
        // it goes through run_query() so the cache layer still applies.
        if ( $query->posts === [] && $page > 1 ) {
            $first_page_args          = $query_args;
            $first_page_args['paged'] = 1;

            $first_page = jpkcom_postfilter_run_query( $first_page_args, $filters );

            $total       = (int) $first_page->found_posts;
            $total_pages = (int) $first_page->max_num_pages;
        }

        // The link is only handed out when following it lands on exactly the
        // result set reported here. Four things break that, and all four are
        // gated in this one place:
        //
        // 1. No archive page. jpkcom_postfilter_get_archive_base_url() returns
        //    '' for a post type without one - `page` is public, selectable in
        //    the settings and has none. Building a link from '' would yield the
        //    relative path "/filter/news/", and
        //    jpkcom_postfilter_archive_base_regex() returns null for exactly
        //    those post types, so no rewrite rule stands behind it either.
        // 2. A filter set this site's own parser would truncate - measured with
        //    four category slugs against max_filters_per_group = 3, where the
        //    link resolved to the first three.
        // 3. A page segment that does not mean the same thing on both sides.
        //    get_filter_url() appends `page/N/`, but the front end reads N in
        //    units of the *site's* posts_per_page, not the ability's per_page.
        //    Measured with per_page 3, page 2 against a site posts_per_page of
        //    10: the ability reported ids 157, 156, 155 and linked to a page
        //    showing 150 down to 1 - both valid, and disjoint. Page 1 carries no
        //    page segment at all, so it is safe whatever per_page says.
        // 4. A page past the last one. That request is answered here with the
        //    real totals and an empty post list, but the front end answers the
        //    matching URL with a 404 - measured on .../filter/allgemein/page/3/
        //    against total_pages 1.
        //
        // The full result set is reported in every case; only the link is
        // withheld. An empty string is a better answer than a link to something
        // else, or to nothing at all.
        $archive_base_url = jpkcom_postfilter_get_archive_base_url( $post_type );

        $page_segment_matches = $page === 1 || $per_page === (int) get_option( 'posts_per_page' );
        $page_exists          = $total_pages < 1 || $page <= $total_pages;

        $filter_url = (
            $archive_base_url === ''
            || ! jpkcom_postfilter_ability_filters_are_url_expressible( $filters )
            || ! $page_segment_matches
            || ! $page_exists
        )
            ? ''
            : jpkcom_postfilter_get_filter_url( $archive_base_url, $filters, $page );

        return [
            'post_type'     => $post_type,
            'filters'       => jpkcom_postfilter_ability_json_object( $filters ),
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => $total_pages,
            'filter_url'    => $filter_url,
            'unknown_terms' => jpkcom_postfilter_ability_json_object(
                jpkcom_postfilter_ability_unknown_terms( $filters )
            ),
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
     * Reads no WordPress state and touches no registry, which is what lets the
     * CI harness assert the shape of these arrays without a WordPress
     * installation. Not free of side effects, though: __() and the two
     * jpkcom_postfilter_ability_meta() calls each fire apply_filters(), so
     * third-party callbacks run whenever this is called.
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
                    // Top level, deliberately. WP_Ability::normalize_input()
                    // substitutes this value when the input is exactly null, and
                    // without it a caller that passes no input at all is rejected
                    // with ability_invalid_input - for an ability whose only
                    // parameter is optional, calling it bare is the natural move.
                    // Per-property defaults are never applied by core, which is
                    // why the callback resolves post_type itself.
                    //
                    // An object, not []: the declared type here is `object`, and
                    // an empty PHP array encodes as the JSON array `[]`. Core's
                    // REST list controller rewrites that special case for its own
                    // response, but the MCP Adapter reads get_input_schema()
                    // directly and hands the raw value to clients, so a
                    // schema-validating client would see an array default on an
                    // object. The callbacks receive this value verbatim; a
                    // stdClass is not an array, so they fall through to [] and
                    // resolve their own per-property defaults, exactly as they do
                    // for any other input they cannot use.
                    'default'    => jpkcom_postfilter_ability_json_object( [] ),
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
                    // See the note on the list-filters input schema: this rescues
                    // execute( null ), which core would otherwise reject before
                    // the callback ever runs, and it is an object rather than []
                    // because MCP clients read this value unmodified.
                    'default'    => jpkcom_postfilter_ability_json_object( [] ),
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
                            'description' => __( 'Shareable front-end URL showing this filter combination, or an empty string when no URL would show exactly these results. It is empty whenever any of the following applies: the post type has no archive page, so there is no front-end URL that could show it; this site caps the number of terms per taxonomy or the number of taxonomies below what was requested, so the URL would resolve to a narrower result set; a page other than the first was requested with a per_page that differs from the site\'s own posts per page setting, so the page number in the URL would select a different slice; or the requested page lies past the last one, where the URL would answer 404. The results themselves are complete in every one of those cases - only the link is withheld.', 'jpkcom-post-filter' ),
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


if ( ! function_exists( function: 'jpkcom_postfilter_abilities_enabled' ) ) {
    /**
     * Decide whether abilities should be registered at all
     *
     * @since 1.3.0
     *
     * @return bool True when the Abilities API is present and the kill switch is on.
     */
    function jpkcom_postfilter_abilities_enabled(): bool {
        if ( ! defined( constant_name: 'JPKCOM_POSTFILTER_ABILITIES' ) || ! JPKCOM_POSTFILTER_ABILITIES ) {
            return false;
        }

        return function_exists( function: 'wp_register_ability' )
            && function_exists( function: 'wp_register_ability_category' )
            && function_exists( function: 'wp_has_ability_category' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_register_ability_category' ) ) {
    /**
     * Register the shared JPKCom content ability category
     *
     * Registered defensively: categories are global and first-wins, so a
     * sibling plugin that registers the same slug first would otherwise make
     * this call fail silently.
     *
     * @since 1.3.0
     *
     * @return void
     */
    function jpkcom_postfilter_register_ability_category(): void {
        if ( ! jpkcom_postfilter_abilities_enabled() ) {
            return;
        }

        if ( wp_has_ability_category( JPKCOM_POSTFILTER_ABILITY_CATEGORY ) ) {
            return;
        }

        $category = wp_register_ability_category(
            JPKCOM_POSTFILTER_ABILITY_CATEGORY,
            [
                'label'       => __( 'JPKCom Content', 'jpkcom-post-filter' ),
                'description' => __( 'Content discovery and querying abilities provided by JPKCom plugins.', 'jpkcom-post-filter' ),
            ]
        );

        if ( $category === null ) {
            jpkcom_postfilter_debug_log(
                'Failed to register ability category',
                [ 'slug' => JPKCOM_POSTFILTER_ABILITY_CATEGORY ]
            );
        }
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_register_abilities' ) ) {
    /**
     * Register every ability this plugin provides
     *
     * wp_register_ability() returns null on every failure path and reports only
     * through _doing_it_wrong(), which is silent in production, so each result
     * is checked explicitly.
     *
     * @since 1.3.0
     *
     * @return void
     */
    function jpkcom_postfilter_register_abilities(): void {
        if ( ! jpkcom_postfilter_abilities_enabled() ) {
            return;
        }

        foreach ( jpkcom_postfilter_get_ability_definitions() as $name => $args ) {
            if ( wp_register_ability( $name, $args ) === null ) {
                jpkcom_postfilter_debug_log( 'Failed to register ability', [ 'ability' => $name ] );
            }
        }
    }
}

add_action( 'wp_abilities_api_categories_init', 'jpkcom_postfilter_register_ability_category' );
add_action( 'wp_abilities_api_init', 'jpkcom_postfilter_register_abilities' );
