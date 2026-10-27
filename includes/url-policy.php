<?php
/**
 * URL Policy – which filter URLs exist, and in which form
 *
 * No hooks are registered here, so the rules can be
 * exercised without WordPress (tests/test-url-policy.php) and reused by every
 * caller that has to agree on them - the URL builder, the parser, the request
 * guard, the link helpers and the Abilities API.
 *
 * WHY THIS EXISTS
 * ---------------
 * Until 1.5.0 the plugin accepted every spelling of a filter selection and
 * rendered it with HTTP 200: `b+a`, `a+b` and `a+a+b` were three self-canonical
 * pages, a fourth term over the cap was silently dropped, and every term link
 * in the filter bar pointed at "current selection + this term" in click order.
 * A handful of terms therefore spanned millions of distinct URLs, each one a
 * full WordPress render, and crawlers that follow links never ran out of new
 * ones. Measured on a production site: ~840 000 filter requests a day, every
 * URL unique, the PHP-FPM pool exhausted.
 *
 * The fix is to make the URL space finite and canonical: one spelling per
 * selection (slugs sorted and de-duplicated), hard caps that reject instead of
 * truncate, and one source of truth for both.
 *
 * Since 1.6.0 the per-group cap counts the non-empty raw pieces of a group
 * (after splitting, before sanitize_title and de-duplication), so a web server
 * rule can mirror it exactly by counting `+`-separated pieces in the path.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.5.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Policy settings: constant > stored setting > default, then a filter
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_policy_definitions' ) ) {
    /**
     * Definitions of the URL/crawl policy settings
     *
     * Every key is stored in the `general` settings group, can be pinned by a
     * wp-config.php constant (which then also locks the admin field) and passes
     * through a filter named `jpkcom_postfilter_{key}`.
     *
     * @since 1.5.0
     *
     * @return array<string, array{constant: string, default: mixed}>
     */
    function jpkcom_postfilter_policy_definitions(): array {
        return [
            'max_filters_per_group' => [ 'constant' => 'JPKCOM_POSTFILTER_MAX_FILTERS_PER_GROUP', 'default' => 2 ],
            'max_filter_combos'     => [ 'constant' => 'JPKCOM_POSTFILTER_MAX_FILTER_COMBOS', 'default' => 3 ],
            'max_terms_total'       => [ 'constant' => 'JPKCOM_POSTFILTER_MAX_TERMS_TOTAL', 'default' => 0 ],
            'filter_link_mode'      => [ 'constant' => 'JPKCOM_POSTFILTER_LINK_MODE', 'default' => 'single' ],
            'seo_index_max_terms'   => [ 'constant' => 'JPKCOM_POSTFILTER_INDEX_MAX_TERMS', 'default' => 1 ],
            'strict_urls'           => [ 'constant' => 'JPKCOM_POSTFILTER_STRICT_URLS', 'default' => true ],
            'reject_status'         => [ 'constant' => 'JPKCOM_POSTFILTER_REJECT_STATUS', 'default' => 410 ],
            'unknown_terms_action'  => [ 'constant' => 'JPKCOM_POSTFILTER_UNKNOWN_TERMS_ACTION', 'default' => 'redirect' ],
            'robots_txt_mode'       => [ 'constant' => 'JPKCOM_POSTFILTER_ROBOTS_TXT', 'default' => 'deep' ],
            'strip_query'           => [ 'constant' => 'JPKCOM_POSTFILTER_STRIP_QUERY', 'default' => false ],
            'canonical_single'      => [ 'constant' => 'JPKCOM_POSTFILTER_CANONICAL_SINGLE', 'default' => 'archive' ],
            'canonical_multi'       => [ 'constant' => 'JPKCOM_POSTFILTER_CANONICAL_MULTI', 'default' => 'home' ],
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_policy_sanitize' ) ) {
    /**
     * Coerce a policy value into its valid range
     *
     * Shared by the settings sanitizer (admin save) and the runtime getter, so a
     * value set by constant or filter is held to the same rules as one typed
     * into the form.
     *
     * @since 1.5.0
     *
     * @param string $key   Policy key.
     * @param mixed  $value Raw value.
     * @return mixed Sanitised value.
     */
    function jpkcom_postfilter_policy_sanitize( string $key, mixed $value ): mixed {
        $default = jpkcom_postfilter_policy_definitions()[ $key ]['default'] ?? null;

        switch ( $key ) {
            case 'max_filters_per_group':
                $v = is_numeric( $value ) ? (int) $value : (int) $default;
                return $v <= 0 ? 0 : min( 20, $v );

            case 'max_filter_combos':
                // 0 = unlimited, as the parser has always read it. The admin
                // form clamps to 1-10; a constant or filter can still say 0.
                $v = is_numeric( $value ) ? (int) $value : (int) $default;
                return $v <= 0 ? 0 : min( 10, $v );

            case 'max_terms_total':
                $v = is_numeric( $value ) ? (int) $value : (int) $default;
                return $v <= 0 ? 0 : min( 50, $v );

            case 'seo_index_max_terms':
                $v = is_numeric( $value ) ? (int) $value : (int) $default;
                return $v <= 0 ? 0 : min( 10, $v );

            case 'filter_link_mode':
                return in_array( $value, [ 'single', 'combo', 'none' ], true ) ? $value : $default;

            case 'reject_status':
                $v = is_numeric( $value ) ? (int) $value : 0;
                return in_array( $v, [ 404, 410 ], true ) ? $v : $default;

            case 'unknown_terms_action':
                return in_array( $value, [ 'redirect', 'noindex', 'reject' ], true ) ? $value : $default;

            case 'robots_txt_mode':
                return in_array( $value, [ 'deep', 'all', 'off' ], true ) ? $value : $default;

            case 'canonical_single':
                return in_array( $value, [ 'archive', 'filter', 'off' ], true ) ? $value : $default;

            case 'canonical_multi':
                return in_array( $value, [ 'home', 'filter', 'off' ], true ) ? $value : $default;

            case 'strict_urls':
            case 'strip_query':
                return is_bool( $value ) ? $value : (bool) (int) $value;
        }

        return $value;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_policy_locked' ) ) {
    /**
     * Whether a policy key is pinned by a wp-config.php constant
     *
     * @since 1.5.0
     *
     * @param string $key Policy key.
     * @return bool
     */
    function jpkcom_postfilter_policy_locked( string $key ): bool {
        $const = jpkcom_postfilter_policy_definitions()[ $key ]['constant'] ?? '';
        return $const !== '' && defined( $const );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_policy' ) ) {
    /**
     * Resolve a policy value
     *
     * Not memoised on purpose: jpkcom_postfilter_settings_get() is memoised per
     * request, the rest is a handful of comparisons.
     *
     * @since 1.5.0
     *
     * @param string $key Policy key (see jpkcom_postfilter_policy_definitions()).
     * @return mixed Sanitised value.
     */
    function jpkcom_postfilter_policy( string $key ): mixed {
        $def = jpkcom_postfilter_policy_definitions()[ $key ] ?? null;

        if ( $def === null ) {
            return null;
        }

        $value = defined( $def['constant'] )
            ? constant( $def['constant'] )
            : jpkcom_postfilter_settings_get( 'general', $key, $def['default'] );

        /**
         * Filter a URL/crawl policy value.
         *
         * The hook name is `jpkcom_postfilter_{key}`, e.g.
         * `jpkcom_postfilter_filter_link_mode` or `jpkcom_postfilter_max_terms_total`.
         *
         * @since 1.5.0
         *
         * @param mixed $value Value from constant, setting or default.
         */
        $value = apply_filters( 'jpkcom_postfilter_' . $key, $value );

        return jpkcom_postfilter_policy_sanitize( $key, $value );
    }
}


// ---------------------------------------------------------------------------
// Normalisation and caps
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_normalize_filters' ) ) {
    /**
     * Bring a filter map into its one canonical form
     *
     * Slugs are sanitised, de-duplicated and sorted byte-wise ascending within
     * each taxonomy; empty taxonomies are dropped. Byte order needs no database
     * and is what JavaScript's default Array#sort produces for slugs (ASCII and
     * lower-case percent escapes), so the browser builds the same URL.
     *
     * Before 1.5.0 the order was the click order, so a selection of k terms
     * had k! spellings, each of them a self-canonical page and a separate cache
     * entry.
     *
     * @since 1.5.0
     *
     * @param array<mixed> $filters Taxonomy => term slugs.
     * @return array<string, string[]> Normalised map.
     */
    function jpkcom_postfilter_normalize_filters( array $filters ): array {
        $out = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $taxonomy = sanitize_key( (string) $taxonomy );

            if ( $taxonomy === '' ) {
                continue;
            }

            $clean = [];
            foreach ( (array) $slugs as $slug ) {
                if ( ! is_scalar( $slug ) ) {
                    continue;
                }
                $slug = sanitize_title( (string) $slug );
                if ( $slug !== '' ) {
                    $clean[] = $slug;
                }
            }

            if ( empty( $clean ) ) {
                continue;
            }

            // array_unique() rather than array_flip(): numeric slugs such as
            // "2024" would turn into int keys and fail later strict compares.
            $clean = array_values( array_unique( $clean ) );
            sort( $clean, SORT_STRING );

            $out[ $taxonomy ] = $clean;
        }

        return $out;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_count_filter_terms' ) ) {
    /**
     * Total number of term slugs in a filter map
     *
     * @since 1.5.0
     *
     * @param array<string, string[]> $filters Taxonomy => term slugs.
     * @return int
     */
    function jpkcom_postfilter_count_filter_terms( array $filters ): int {
        $total = 0;
        foreach ( $filters as $slugs ) {
            $total += is_array( $slugs ) ? count( $slugs ) : 0;
        }
        return $total;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_filters_within_caps' ) ) {
    /**
     * Whether a (normalised) filter map respects every configured cap
     *
     * The single authority on the caps. The parser, the request guard, the
     * term links and the Abilities API all ask this function; before 1.5.0 the
     * Abilities API carried a hand-maintained copy of the parser's truncation.
     *
     * @since 1.5.0
     *
     * @param array<string, string[]> $filters Taxonomy => term slugs.
     * @return bool
     */
    function jpkcom_postfilter_filters_within_caps( array $filters ): bool {
        $filters = array_filter( $filters, static fn( mixed $s ): bool => is_array( $s ) && ! empty( $s ) );

        $max_combos = (int) jpkcom_postfilter_policy( 'max_filter_combos' );
        if ( $max_combos > 0 && count( $filters ) > $max_combos ) {
            return false;
        }

        $max_per_group = (int) jpkcom_postfilter_policy( 'max_filters_per_group' );
        if ( $max_per_group > 0 ) {
            foreach ( $filters as $slugs ) {
                if ( count( $slugs ) > $max_per_group ) {
                    return false;
                }
            }
        }

        $max_total = (int) jpkcom_postfilter_policy( 'max_terms_total' );
        if ( $max_total > 0 && jpkcom_postfilter_count_filter_terms( $filters ) > $max_total ) {
            return false;
        }

        return true;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_filter_taxonomies' ) ) {
    /**
     * Taxonomies in URL position order
     *
     * Position N of a filter path belongs to the Nth enabled filter group. A
     * group without a taxonomy keeps its position (as an empty string) so the
     * positions of later groups do not shift.
     *
     * @since 1.5.0
     *
     * @return string[]
     */
    function jpkcom_postfilter_filter_taxonomies(): array {
        return array_values( array_map(
            static fn( array $g ): string => sanitize_key( (string) ( $g['taxonomy'] ?? '' ) ),
            jpkcom_postfilter_get_filter_groups_enabled()
        ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_filter_path_from_filters' ) ) {
    /**
     * Build the positional path segment string for a filter map
     *
     * `cat-a+cat-b/_/tag-x` - one segment per filter group in order, `_` for an
     * empty position before a non-empty one, trailing empty positions dropped.
     *
     * @since 1.5.0
     *
     * @param array<mixed> $filters    Taxonomy => term slugs (normalised here).
     * @param string[]     $taxonomies Taxonomies in position order.
     * @return string Path without leading/trailing slash; '' when nothing is selected.
     */
    function jpkcom_postfilter_filter_path_from_filters( array $filters, array $taxonomies ): string {
        $filters  = jpkcom_postfilter_normalize_filters( $filters );
        $segments = [];

        foreach ( $taxonomies as $taxonomy ) {
            $segments[] = ( $taxonomy !== '' && ! empty( $filters[ $taxonomy ] ) )
                ? implode( '+', $filters[ $taxonomy ] )
                : '_';
        }

        while ( ! empty( $segments ) && end( $segments ) === '_' ) {
            array_pop( $segments );
        }

        return implode( '/', $segments );
    }
}


// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_known_term_slugs' ) ) {
    /**
     * All term slugs of a taxonomy, in every language
     *
     * Deliberately not jpkcom_postfilter_get_terms_for_taxonomy(): that list
     * goes through get_terms(), which WPML (and similar plugins) narrow to the
     * current language, and its transient is keyed by taxonomy only - so the
     * language that happened to warm the cache decided which slugs existed.
     * Since 1.5.0 an unknown slug is redirected away, which would have
     * silently dropped every term of the other languages from their URLs.
     *
     * One query per taxonomy per cache lifetime; the transient is keyed by
     * taxonomy, never by requested slugs, and flushed with the term caches.
     *
     * The one authority on "does this slug exist" - the request guard, the
     * robots decision and the Abilities API all ask it, so "unknown" means the
     * same thing everywhere. (No hooks, which is why it lives here.)
     *
     * @since 1.5.0
     *
     * @param string $taxonomy Taxonomy key.
     * @return string[]|null Slugs, or null when the taxonomy does not exist.
     */
    function jpkcom_postfilter_known_term_slugs( string $taxonomy ): ?array {
        static $memo     = [];
        static $memo_gen = null;

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return null;
        }

        // Reset whenever the term caches are flushed (cache-manager.php), and
        // per site: switch_to_blog() must not answer with another site's terms.
        $gen = (int) ( $GLOBALS['_jpkpf_terms_memo_gen'] ?? 0 );
        if ( $memo_gen !== $gen ) {
            $memo     = [];
            $memo_gen = $gen;
        }

        $key = get_current_blog_id() . ':' . $taxonomy;
        if ( isset( $memo[ $key ] ) ) {
            return $memo[ $key ];
        }

        $cache_key = 'slugs_' . $taxonomy;
        $cached    = jpkcom_postfilter_transient_get( $cache_key );

        // A damaged cached value must not reach array_diff(): an object in
        // the list would throw "could not be converted to string".
        if ( is_array( $cached ) ) {
            return $memo[ $key ] = array_values( array_filter( $cached, 'is_string' ) );
        }

        $slugs = jpkcom_postfilter_query_term_slugs( $taxonomy );

        jpkcom_postfilter_transient_set(
            $cache_key,
            $slugs,
            (int) jpkcom_postfilter_settings_get( 'cache', 'cache_ttl', JPKCOM_POSTFILTER_CACHE_TTL )
        );

        return $memo[ $key ] = $slugs;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_query_term_slugs' ) ) {
    /**
     * Read every slug of a taxonomy straight from the term tables
     *
     * Bypasses the get_terms() filters on purpose, see
     * jpkcom_postfilter_known_term_slugs().
     *
     * @since 1.5.0
     *
     * @param string $taxonomy Taxonomy key.
     * @return string[]
     */
    function jpkcom_postfilter_query_term_slugs( string $taxonomy ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached by the caller
        $slugs = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.slug FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
            $taxonomy
        ) );

        return array_values( array_map( 'strval', is_array( $slugs ) ? $slugs : [] ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_split_segment' ) ) {
    /**
     * Split a path segment into its term slugs
     *
     * `%2B` counts as the separator too. Some link rewriters and normalisers
     * encode `+`, and WordPress passes the escape through to the query var
     * unchanged; read as part of one slug it made the whole selection
     * "unknown" and redirected it to the unfiltered archive.
     *
     * Except where `%2b` is part of a real slug. WordPress does not create
     * such slugs itself (sanitize_title( 'C++' ) is `c`), but one typed into
     * the slug field or imported as `c%2b%2b` is kept verbatim. A part that is
     * a known slug stays whole; an unknown one is split at `%2b` and adjacent
     * pieces are joined back, longest first, wherever they form a known slug -
     * so `c%2b%2b%2Bzaeune` (two slugs joined by an encoded `+`) reads as
     * `c%2b%2b` and `zaeune`.
     *
     * @since 1.5.0
     *
     * @param string        $segment     One path segment.
     * @param callable|null $known_slugs fn( string $taxonomy ): ?string[]; null = always split.
     * @param string        $taxonomy    Taxonomy of the segment's position.
     * @return string[] Raw parts (not yet sanitised).
     */
    function jpkcom_postfilter_split_segment( string $segment, ?callable $known_slugs = null, string $taxonomy = '' ): array {
        $parts = [];

        // Known slugs that themselves contain %2b, as a set, plus how many
        // %2b-pieces the widest of them spans. Normally there are none, and
        // then nothing is ever joined back. The join width is bounded by that
        // number: an unbounded search tried every span of every start - cubic
        // in the length of a path an anonymous client controls (measured:
        // 3 KB of %2b held a PHP worker for 100 s).
        $joinable  = [];
        $max_width = 1;

        if ( $known_slugs !== null && $taxonomy !== '' && stripos( $segment, '%2b' ) !== false ) {
            foreach ( (array) $known_slugs( $taxonomy ) as $slug ) {
                $slug = strtolower( (string) $slug );
                if ( str_contains( $slug, '%2b' ) ) {
                    $joinable[ $slug ] = true;
                    $max_width         = max( $max_width, substr_count( $slug, '%2b' ) + 1 );
                }
            }
        }

        foreach ( explode( '+', $segment ) as $part ) {
            if ( stripos( $part, '%2b' ) === false ) {
                $parts[] = $part;
                continue;
            }

            $pieces = preg_split( '/%2b/i', $part ) ?: [ $part ];
            $count  = count( $pieces );

            for ( $i = 0; $i < $count; ) {
                $taken = 1;

                for ( $width = min( $max_width, $count - $i ); $width > 1; $width-- ) {
                    if ( isset( $joinable[ strtolower( implode( '%2b', array_slice( $pieces, $i, $width ) ) ) ] ) ) {
                        $taken = $width;
                        break;
                    }
                }

                $parts[] = implode( '%2b', array_slice( $pieces, $i, $taken ) );
                $i      += $taken;
            }
        }

        return $parts;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_validate_filter_path' ) ) {
    /**
     * Classify a requested filter path
     *
     * Decides between "reject" (the path can never be a valid selection on this
     * site) and "ok" with the normalised selection it stands for. Whether an ok
     * path has to be redirected is decided by the caller, by comparing the
     * request against the URL built from `filters` - one comparison that covers
     * order, duplicates, case, empty segments, `page/1` and the trailing slash.
     *
     * Order of checks:
     *  1. more segments than filter groups          -> reject (`segments`)
     *  2. a slug longer than 200 characters         -> reject (`length`)
     *  3. caps per group, group count, total terms  -> reject (`per-group`, `groups`, `total`)
     *     (per-group counts the non-empty raw pieces, summed per taxonomy,
     *     before normalising; see the file header)
     *  4. unknown slugs, per $unknown_action:
     *       redirect: dropped (the caller then redirects to what is left)
     *       noindex:  kept, flagged
     *       reject:   reject (`unknown`)
     *
     * Before 1.5.0 the parser silently ignored extra segments and truncated
     * over-cap selections, and every such URL rendered with 200.
     *
     * @since 1.5.0
     *
     * @param string        $path           Filter path as captured by the rewrite rule.
     * @param string[]      $taxonomies     Taxonomies in position order.
     * @param callable|null $known_slugs    fn( string $taxonomy ): ?string[] - known
     *                                      slugs, null when the taxonomy is gone.
     *                                      Null callable = no existence check.
     * @param string        $unknown_action 'redirect' | 'noindex' | 'reject'.
     * @return array{action: string, reason: string, filters: array<string, string[]>, unknown: bool}
     */
    function jpkcom_postfilter_validate_filter_path( string $path, array $taxonomies, ?callable $known_slugs = null, string $unknown_action = 'redirect' ): array {
        $reject = static fn( string $reason ): array => [
            'action'  => 'reject',
            'reason'  => $reason,
            'filters' => [],
            'unknown' => false,
        ];

        // Before any splitting: no valid selection comes anywhere near this
        // (groups x terms per group x 200 characters per slug at most), and
        // the work below must stay bounded for any input.
        if ( strlen( $path ) > 2000 ) {
            return $reject( 'length' );
        }

        $segments = array_values( array_filter(
            explode( '/', trim( $path, '/' ) ),
            static fn( string $s ): bool => $s !== ''
        ) );

        if ( count( $segments ) > count( $taxonomies ) ) {
            return $reject( 'segments' );
        }

        $raw = [];
        foreach ( $segments as $index => $segment ) {
            if ( $segment === '_' ) {
                continue;
            }

            $taxonomy = (string) ( $taxonomies[ $index ] ?? '' );
            if ( $taxonomy === '' ) {
                continue;
            }

            foreach ( jpkcom_postfilter_split_segment( $segment, $known_slugs, $taxonomy ) as $part ) {
                // Empty pieces (`a++b`, a leading or trailing `+`) are not counted.
                if ( $part === '' ) {
                    continue;
                }

                if ( strlen( $part ) > 200 ) {
                    return $reject( 'length' );
                }
                $raw[ $taxonomy ][] = $part;
            }
        }

        // Count raw pieces, before sanitize_title and de-duplication (1.6.0).
        $max_per_group = (int) jpkcom_postfilter_policy( 'max_filters_per_group' );
        if ( $max_per_group > 0 ) {
            foreach ( $raw as $pieces ) {
                if ( count( $pieces ) > $max_per_group ) {
                    return $reject( 'per-group' );
                }
            }
        }

        $filters = jpkcom_postfilter_normalize_filters( $raw );

        $max_combos = (int) jpkcom_postfilter_policy( 'max_filter_combos' );
        if ( $max_combos > 0 && count( $filters ) > $max_combos ) {
            return $reject( 'groups' );
        }

        $max_total = (int) jpkcom_postfilter_policy( 'max_terms_total' );
        if ( $max_total > 0 && jpkcom_postfilter_count_filter_terms( $filters ) > $max_total ) {
            return $reject( 'total' );
        }

        $unknown = false;

        if ( $known_slugs !== null ) {
            foreach ( $filters as $taxonomy => $slugs ) {
                $known = $known_slugs( $taxonomy );
                $kept  = is_array( $known ) ? array_values( array_intersect( $slugs, $known ) ) : [];

                if ( count( $kept ) === count( $slugs ) ) {
                    continue;
                }

                $unknown = true;

                if ( $unknown_action === 'reject' ) {
                    return $reject( 'unknown' );
                }

                if ( $unknown_action === 'redirect' ) {
                    if ( empty( $kept ) ) {
                        unset( $filters[ $taxonomy ] );
                    } else {
                        $filters[ $taxonomy ] = $kept;
                    }
                }
            }
        }

        return [
            'action'  => 'ok',
            'reason'  => '',
            'filters' => $filters,
            'unknown' => $unknown && $unknown_action !== 'redirect',
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_normalize_url_path' ) ) {
    /**
     * Normalise a URL path for comparison
     *
     * Lower-cases percent escapes only: browsers send `%C3%BC`, WordPress
     * stores and builds `%c3%bc`, and both name the same resource. Everything
     * else is compared verbatim.
     *
     * @since 1.5.0
     *
     * @param string $path URL path.
     * @return string
     */
    function jpkcom_postfilter_normalize_url_path( string $path ): string {
        return (string) preg_replace_callback(
            '/%[0-9A-Fa-f]{2}/',
            static fn( array $m ): string => strtolower( $m[0] ),
            $path
        );
    }
}
