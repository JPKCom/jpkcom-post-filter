<?php
/**
 * Canonical targets – which original page a filter page may point at
 *
 * A filter page with one term shows what the term archive shows; a
 * combination is a view of the archive base. Since 1.7.0 their canonical
 * points there - but only at a target that is itself indexable. A canonical to
 * a noindex page tells a search engine to drop both.
 *
 * Decisions only, no hooks. The canonical decision and its output live in
 * includes/crawl-guard.php.
 *
 * Rank Math and Yoast are read the way they decide their own robots (read in
 * Rank Math 1.0.280 `includes/frontend/paper/` and Yoast 28.2
 * `src/builders/indexable-*-builder.php` / `src/presentations/`), and only
 * while the plugin is active: options of a deactivated SEO plugin stay in the
 * database and say nothing about the page any more.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.7.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_postfilter_canonical_target_memo' ) ) {
    /**
     * Per-request memo of the target decisions
     *
     * @since 1.7.0
     *
     * @param string     $key   Memo key.
     * @param mixed|null $value Value to store; null reads.
     * @param bool       $reset Drop every entry.
     * @return mixed Stored value, or null when absent.
     */
    function jpkcom_postfilter_canonical_target_memo( string $key, mixed $value = null, bool $reset = false ): mixed {
        static $memo = [];

        if ( $reset ) {
            $memo = [];
            return null;
        }

        $key = get_current_blog_id() . '|' . $key;

        if ( $value !== null ) {
            $memo[ $key ] = $value;
        }

        return $memo[ $key ] ?? null;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_canonical_target_memo_reset' ) ) {
    /**
     * Drop the per-request memo of the target decisions
     *
     * @since 1.7.0
     */
    function jpkcom_postfilter_canonical_target_memo_reset(): void {
        jpkcom_postfilter_canonical_target_memo( key: '', reset: true );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_seo_switch_on' ) ) {
    /**
     * Read a stored on/off switch the way Rank Math normalises it
     *
     * @since 1.7.0
     *
     * @param mixed $value Stored value (`on`, `off`, `1`, true, ...).
     * @return bool
     */
    function jpkcom_postfilter_seo_switch_on( mixed $value ): bool {
        return $value === true || $value === 1 || $value === 'on' || $value === '1' || $value === 'true';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_rank_math_robots_noindex' ) ) {
    /**
     * Whether a Rank Math robots list says noindex
     *
     * Rank Math stores robots as a list of directives (`['noindex', 'follow']`).
     * The first non-empty list of the candidates decides; with none,
     * `titles.robots_global` does (Paper::get_robots()).
     *
     * @since 1.7.0
     *
     * @param array<int, mixed> $candidates Robots lists, most specific first.
     * @return bool
     */
    function jpkcom_postfilter_rank_math_robots_noindex( array $candidates ): bool {
        $titles = get_option( 'rank-math-options-titles', [] );
        $titles = is_array( $titles ) ? $titles : [];

        $candidates[] = $titles['robots_global'] ?? [];

        foreach ( $candidates as $robots ) {
            if ( is_array( $robots ) && $robots !== [] ) {
                return in_array( 'noindex', $robots, true );
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_rank_math_titles_robots' ) ) {
    /**
     * A Rank Math `{prefix}_robots` list, if its `{prefix}_custom_robots` is on
     *
     * Rank Math combines that list with index as the default in
     * Paper\Taxonomy and Paper\Singular (`robots_combine( …, true )`), but not in
     * Paper\Archive and Paper\Blog: there an empty list falls through to the
     * global robots.
     *
     * @since 1.7.0
     * @since 1.7.1 `$empty_means_index`.
     *
     * @param string $prefix            e.g. `tax_category`, `pt_page`, `homepage`.
     * @param bool   $empty_means_index An empty list while custom robots are on means index.
     * @return array<int, string> Empty when custom robots are off.
     */
    function jpkcom_postfilter_rank_math_titles_robots( string $prefix, bool $empty_means_index = false ): array {
        $titles = get_option( 'rank-math-options-titles', [] );
        $titles = is_array( $titles ) ? $titles : [];

        if ( ! jpkcom_postfilter_seo_switch_on( $titles[ $prefix . '_custom_robots' ] ?? false ) ) {
            return [];
        }

        $robots = $titles[ $prefix . '_robots' ] ?? [];
        $robots = is_array( $robots ) ? $robots : [];

        return $robots === [] && $empty_means_index ? [ 'index' ] : $robots;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_seo_canonical_moved' ) ) {
    /**
     * Whether an active SEO plugin gives a target a canonical of its own elsewhere
     *
     * An editor can point a term archive or the posts page at another URL
     * (Rank Math `rank_math_canonical_url`, Yoast `wpseo_canonical` /
     * `_yoast_wpseo_canonical`). A canonical to such a target would be a chain;
     * the target is then unusable and the filter page keeps its own canonical.
     *
     * @since 1.7.0
     *
     * @param string        $url     Target URL.
     * @param \WP_Term|null $term    Term of a term archive, or null.
     * @param int           $page_id Page of the archive base (posts page), or 0.
     * @return bool
     */
    function jpkcom_postfilter_seo_canonical_moved( string $url, ?\WP_Term $term, int $page_id ): bool {
        $overrides = [];

        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $overrides[] = $term !== null
                ? get_term_meta( $term->term_id, 'rank_math_canonical_url', true )
                : ( $page_id > 0 ? get_post_meta( $page_id, 'rank_math_canonical_url', true ) : '' );
        }

        if ( defined( 'WPSEO_VERSION' ) ) {
            if ( $term !== null ) {
                $meta        = get_option( 'wpseo_taxonomy_meta', [] );
                $overrides[] = is_array( $meta ) ? ( $meta[ $term->taxonomy ][ $term->term_id ]['wpseo_canonical'] ?? '' ) : '';
            } elseif ( $page_id > 0 ) {
                $overrides[] = get_post_meta( $page_id, '_yoast_wpseo_canonical', true );
            }
        }

        foreach ( $overrides as $override ) {
            if ( is_string( $override ) && trim( $override ) !== '' && untrailingslashit( trim( $override ) ) !== untrailingslashit( $url ) ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_term_archive_lists_post_type' ) ) {
    /**
     * Whether the archive of a taxonomy's terms lists a post type
     *
     * Mirrors WP_Query::get_posts() for an archive query without a post type
     * (wp-includes/class-wp-query.php, 7.1.3 l. 2310-2333): only a custom
     * taxonomy sets `is_tax`, and only then does core search every post type
     * with `exclude_from_search = false` that has the taxonomy. Category and
     * tag archives set `is_category` / `is_tag` instead and query `post` alone.
     * A theme's pre_get_posts is invisible here; the filter
     * `jpkcom_postfilter_canonical_archive_indexable` covers that.
     *
     * @since 1.7.0
     *
     * @param string $taxonomy  Taxonomy.
     * @param string $post_type Post type of the filter page.
     * @return bool
     */
    function jpkcom_postfilter_term_archive_lists_post_type( string $taxonomy, string $post_type ): bool {
        if ( $taxonomy === 'category' || $taxonomy === 'post_tag' ) {
            return $post_type === 'post';
        }

        foreach ( get_post_types( [ 'exclude_from_search' => false ] ) as $pt ) {
            if ( $pt === $post_type && in_array( $taxonomy, (array) get_object_taxonomies( $pt ), true ) ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_seo_term_noindex' ) ) {
    /**
     * Whether an active SEO plugin sends a term archive as noindex
     *
     * @since 1.7.0
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    function jpkcom_postfilter_seo_term_noindex( \WP_Term $term ): bool {
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            // Paper\Taxonomy::robots(): term meta, else the taxonomy's robots
            // when custom robots are on, else the global robots; an empty term
            // without children is noindex with noindex_empty_taxonomies
            // (noindex_term(), asked with exactly its get_terms() arguments).
            $titles = get_option( 'rank-math-options-titles', [] );
            $titles = is_array( $titles ) ? $titles : [];

            if ( (int) $term->count === 0 && jpkcom_postfilter_seo_switch_on( $titles['noindex_empty_taxonomies'] ?? false ) ) {
                $children = get_terms( [
                    'taxonomy' => $term->taxonomy,
                    'parent'   => $term->term_id,
                    'number'   => 1,
                    'fields'   => 'ids',
                ] );

                if ( empty( $children ) ) {
                    return true;
                }
            }

            $meta = get_term_meta( $term->term_id, 'rank_math_robots', true );
            $meta = is_array( $meta ) ? $meta : [];

            if ( jpkcom_postfilter_rank_math_robots_noindex( [ $meta, jpkcom_postfilter_rank_math_titles_robots( prefix: 'tax_' . $term->taxonomy, empty_means_index: true ) ] ) ) {
                return true;
            }
        }

        if ( defined( 'WPSEO_VERSION' ) ) {
            // Term meta `wpseo_noindex` (index|noindex|default) in the option
            // wpseo_taxonomy_meta; default falls back to noindex-tax-{taxonomy}.
            $meta   = get_option( 'wpseo_taxonomy_meta', [] );
            $value  = is_array( $meta ) ? ( $meta[ $term->taxonomy ][ $term->term_id ]['wpseo_noindex'] ?? 'default' ) : 'default';
            $titles = get_option( 'wpseo_titles', [] );
            $titles = is_array( $titles ) ? $titles : [];

            if ( $value === 'noindex' ) {
                return true;
            }
            if ( $value !== 'index' && ! empty( $titles[ 'noindex-tax-' . $term->taxonomy ] ) ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_term_archive_url' ) ) {
    /**
     * The term archive a single-term filter page may point at
     *
     * @since 1.7.0
     *
     * @param string $taxonomy  Taxonomy.
     * @param string $slug      Term slug.
     * @param string $post_type Post type of the filter page.
     * @return string Archive URL (page 1), or '' when it is no usable target.
     */
    function jpkcom_postfilter_term_archive_url( string $taxonomy, string $slug, string $post_type ): string {
        $key    = 'term|' . $taxonomy . '|' . $slug . '|' . $post_type;
        $cached = jpkcom_postfilter_canonical_target_memo( $key );
        if ( is_string( $cached ) ) {
            return $cached;
        }

        $url = '';
        $tax = get_taxonomy( $taxonomy );
        $term = get_term_by( 'slug', $slug, $taxonomy );

        if ( is_object( $tax ) && ! empty( $tax->public ) && ! empty( $tax->publicly_queryable )
            && $term instanceof \WP_Term
            && jpkcom_postfilter_term_archive_lists_post_type( $taxonomy, $post_type )
        ) {
            $link = get_term_link( $term );

            if ( is_string( $link ) && $link !== '' ) {
                $indexable = (int) get_option( 'blog_public', 1 ) !== 0
                    && ! jpkcom_postfilter_seo_term_noindex( $term )
                    && ! jpkcom_postfilter_seo_canonical_moved( $link, $term, 0 );

                /**
                 * Filter whether a term archive is an indexable canonical target.
                 *
                 * @since 1.7.0
                 *
                 * @param bool     $indexable Whether the archive is indexable.
                 * @param \WP_Term $term      Term.
                 * @param string   $post_type Post type of the filter page.
                 */
                if ( (bool) apply_filters( 'jpkcom_postfilter_canonical_archive_indexable', $indexable, $term, $post_type ) ) {
                    $url = $link;
                }
            }
        }

        jpkcom_postfilter_canonical_target_memo( $key, $url );
        return $url;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_seo_base_noindex' ) ) {
    /**
     * Whether an active SEO plugin sends the archive base of a post type as noindex
     *
     * The base is the posts page (a page), the front page when it shows the
     * posts, or the post type archive - the same three cases
     * jpkcom_postfilter_get_archive_base_url() distinguishes.
     *
     * @since 1.7.0
     *
     * @param string $post_type Post type.
     * @return bool
     */
    function jpkcom_postfilter_seo_base_noindex( string $post_type ): bool {
        $page_id = $post_type === 'post' ? (int) get_option( 'page_for_posts' ) : 0;

        if ( defined( 'RANK_MATH_VERSION' ) ) {
            if ( $page_id > 0 ) {
                // Paper\Singular: post meta, else the page post type's robots.
                $meta       = get_post_meta( $page_id, 'rank_math_robots', true );
                $candidates = [ is_array( $meta ) ? $meta : [], jpkcom_postfilter_rank_math_titles_robots( prefix: 'pt_page', empty_means_index: true ) ];
            } elseif ( $post_type === 'post' ) {
                // Paper\Blog: the homepage robots.
                $candidates = [ jpkcom_postfilter_rank_math_titles_robots( 'homepage' ) ];
            } else {
                // Paper\Archive: the post type's robots.
                $candidates = [ jpkcom_postfilter_rank_math_titles_robots( 'pt_' . $post_type ) ];
            }

            if ( jpkcom_postfilter_rank_math_robots_noindex( $candidates ) ) {
                return true;
            }
        }

        if ( defined( 'WPSEO_VERSION' ) ) {
            $titles = get_option( 'wpseo_titles', [] );
            $titles = is_array( $titles ) ? $titles : [];

            if ( $page_id > 0 ) {
                // _yoast_wpseo_meta-robots-noindex: 1 noindex, 2 index, else the
                // default of the page post type (noindex-page).
                $value = (int) get_post_meta( $page_id, '_yoast_wpseo_meta-robots-noindex', true );
                if ( $value === 1 || ( $value !== 2 && ! empty( $titles['noindex-page'] ) ) ) {
                    return true;
                }
            } elseif ( $post_type !== 'post' && ! empty( $titles[ 'noindex-ptarchive-' . $post_type ] ) ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_base_canonical_url' ) ) {
    /**
     * The archive base a filter combination may point at
     *
     * @since 1.7.0
     *
     * @param string $post_type Post type of the filter page.
     * @return string Base URL (page 1), or '' when it is no usable target.
     */
    function jpkcom_postfilter_base_canonical_url( string $post_type ): string {
        $key    = 'base|' . $post_type;
        $cached = jpkcom_postfilter_canonical_target_memo( $key );
        if ( is_string( $cached ) ) {
            return $cached;
        }

        $url     = '';
        $page_id = $post_type === 'post' ? (int) get_option( 'page_for_posts' ) : 0;

        // The URL the base prints as its own canonical: no trailingslashit()
        // as in jpkcom_postfilter_get_archive_base_url(), which on a slashless
        // permalink structure would point at a 301 (1.7.1).
        // Without a posts page the base of `post` is home_url('/'). That lists
        // the posts only when the front page shows them; a static front page
        // is a page of its own, not the original of a combination.
        if ( $post_type === 'post' && $page_id > 0 ) {
            $base = get_permalink( $page_id );
        } elseif ( $post_type === 'post' ) {
            $base = get_option( 'show_on_front' ) === 'posts' ? home_url( '/' ) : '';
        } else {
            $base = get_post_type_archive_link( $post_type );
        }
        $base = is_string( $base ) ? $base : '';

        if ( $base !== '' ) {
            $indexable = (int) get_option( 'blog_public', 1 ) !== 0
                && ! jpkcom_postfilter_seo_base_noindex( $post_type )
                && ! jpkcom_postfilter_seo_canonical_moved( $base, null, $page_id );

            /**
             * Filter whether the archive base is an indexable canonical target.
             *
             * @since 1.7.0
             *
             * @param bool   $indexable Whether the base is indexable.
             * @param string $post_type Post type of the filter page.
             */
            if ( (bool) apply_filters( 'jpkcom_postfilter_canonical_base_indexable', $indexable, $post_type ) ) {
                $url = $base;
            }
        }

        jpkcom_postfilter_canonical_target_memo( $key, $url );
        return $url;
    }
}
