<?php
/**
 * Filter Auto-Injection
 *
 * Automatically injects the filter UI and wraps post loop results on archive
 * pages and the blog home. Uses WordPress's `loop_start` / `loop_end` actions
 * so the injection works with any theme without template modifications.
 *
 * Injection produces the following HTML structure around the loop:
 *
 *   <div data-jpkpf-wrapper data-jpkpf-base-url="..." data-jpkpf-post-type="...">
 *     <div data-jpkpf-filter-bar>…filter bar template…</div>
 *     <div data-jpkpf-results aria-live="polite">
 *       [theme loop output goes here]
 *     </div>
 *   </div>
 *
 * JavaScript in post-filter.js reads the data-* attributes to wire up
 * AJAX-enhanced filtering. The No-JS fallback works via plain link navigation.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Auto-injection eligibility check
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_should_auto_inject' ) ) {
    /**
     * Determine whether the filter UI should be auto-injected for a given query
     *
     * Returns true when ALL of the following apply:
     *   - The query is the main frontend query
     *   - The current page is a blog home, front-page posts listing, or CPT archive
     *   - The post type is listed in the "enabled_post_types" setting
     *   - Auto-injection is enabled in the general settings
     *
     * @since 1.0.0
     *
     * @param \WP_Query $query The WP_Query object being looped over.
     * @return bool True if the filter bar should be injected.
     */
    function jpkcom_postfilter_should_auto_inject( \WP_Query $query ): bool {
        // Admin, feeds, REST, and non-main queries are never injected
        if ( is_admin() || ! $query->is_main_query() || $query->is_feed() ) {
            return false;
        }

        // Only inject on archive contexts
        if ( ! $query->is_home() && ! $query->is_post_type_archive() && ! jpkcom_postfilter_is_filter_request() ) {
            return false;
        }

        // Check general settings
        $general       = jpkcom_postfilter_settings_get_group( 'general' );
        $auto_inject   = (bool) ( $general['auto_inject'] ?? true );

        if ( ! $auto_inject ) {
            return false;
        }

        // Resolve the queried post type
        $post_type = jpkcom_postfilter_get_injected_post_type( $query );

        // Allow injection when the post type is in the global enabled list …
        $enabled_types = array_map( 'sanitize_key', (array) ( $general['enabled_post_types'] ?? [ 'post' ] ) );
        if ( in_array( $post_type, $enabled_types, true ) ) {
            return true;
        }

        // … or when any enabled filter group explicitly targets this post type.
        foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
            if ( ! empty( $group['post_types'] ) && is_array( $group['post_types'] )
                && in_array( $post_type, $group['post_types'], true ) ) {
                return true;
            }
        }

        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_injected_post_type' ) ) {
    /**
     * Resolve the post type for an auto-injection context
     *
     * @since 1.0.0
     *
     * @param \WP_Query $query The current WP_Query.
     * @return string Post type slug, defaults to 'post'.
     */
    function jpkcom_postfilter_get_injected_post_type( \WP_Query $query ): string {
        // Explicit filter URL var takes priority
        $from_var = (string) $query->get( 'jpkcom_filter_post_type' );
        if ( $from_var !== '' ) {
            return $from_var;
        }

        // CPT archive
        if ( $query->is_post_type_archive() ) {
            $queried = get_queried_object();
            if ( $queried instanceof \WP_Post_Type ) {
                return $queried->name;
            }
        }

        return 'post';
    }
}


// ---------------------------------------------------------------------------
// loop_start — open wrappers and output filter bar
// ---------------------------------------------------------------------------

/**
 * Before the loop: open the jpkpf-wrapper, output filter bar, open results div
 *
 * @since 1.0.0
 */
add_action( 'loop_start', static function ( \WP_Query $query ): void {

    if ( ! jpkcom_postfilter_should_auto_inject( $query ) ) {
        return;
    }

    // Mark that auto-injection happened (used by the wp_footer 0-results fallback).
    $GLOBALS['_jpkpf_auto_injected'] = true;

    $post_type      = jpkcom_postfilter_get_injected_post_type( $query );
    $base_url       = jpkcom_postfilter_get_archive_base_url( $post_type );
    $active_filters = jpkcom_postfilter_get_active_filters();

    $enabled_pt_general = array_map(
        'sanitize_key',
        (array) jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] )
    );

    // Only include groups that are configured for the current post type.
    // Groups without an explicit post_types list fall back to the global enabled list.
    $groups = array_values( array_filter(
        jpkcom_postfilter_get_filter_groups_enabled(),
        static function ( array $group ) use ( $post_type, $enabled_pt_general ): bool {
            $group_pts = ! empty( $group['post_types'] ) && is_array( $group['post_types'] )
                ? $group['post_types']
                : $enabled_pt_general;
            return in_array( $post_type, $group_pts, true );
        }
    ) );

    $layout      = jpkcom_postfilter_settings_get( 'layout', 'filter_layout', 'bar' );
    $layout      = in_array( $layout, [ 'bar', 'sidebar', 'dropdown', 'columns' ], true ) ? $layout : 'bar';
    $reset_mode  = jpkcom_postfilter_settings_get( 'layout', 'reset_button_mode', 'on_selection' );
    $reset_mode  = in_array( $reset_mode, [ 'always', 'on_selection', 'never' ], true ) ? $reset_mode : 'on_selection';

    // Build filter_groups with terms fetched and enriched with active state
    $filter_groups = [];
    foreach ( $groups as $group ) {
        $enriched = jpkcom_postfilter_get_terms_for_group( $group, $active_filters );
        if ( empty( $enriched ) ) {
            continue;
        }
        $filter_groups[] = array_merge( $group, [
            'terms' => array_map( static fn( array $item ): \WP_Term => $item['term'], $enriched ),
        ] );
    }

    // Outer wrapper — carries data attributes for the JS module
    printf(
        '<div data-jpkpf-wrapper data-jpkpf-base-url="%s" data-jpkpf-post-type="%s" data-jpkpf-layout="%s">',
        esc_url( $base_url ),
        esc_attr( $post_type ),
        esc_attr( $layout )
    );

    // Render the filter bar template
    jpkcom_postfilter_get_template_part(
        'partials/filter/filter-' . $layout,
        '',
        [
            'filter_groups'  => $filter_groups,
            'active_filters' => $active_filters,
            'base_url'       => $base_url,
            'post_type'      => $post_type,
            'show_reset'     => $reset_mode !== 'never',
            'reset_mode'     => $reset_mode,
        ]
    );

    $pagination_pos = jpkcom_postfilter_settings_get( 'layout', 'pagination_position', 'below' );
    $pagination_pos = in_array( $pagination_pos, [ 'above', 'below', 'both' ], true ) ? $pagination_pos : 'below';

    // Open the results region (AJAX swaps the contents of this element).
    // The zone marker sits outside the div so the div itself travels with the
    // fragment — the script looks the element up by selector in the response.
    jpkcom_postfilter_zone_open();

    echo '<div data-jpkpf-results aria-live="polite" aria-atomic="false">';

    // Render above-pagination inside the results div so it gets refreshed on AJAX
    if ( in_array( $pagination_pos, [ 'above', 'both' ], true ) ) {
        jpkcom_postfilter_get_template_part(
            'partials/pagination/pagination',
            '',
            [
                'query'          => $query,
                'base_url'       => $base_url,
                'active_filters' => $active_filters,
                'post_type'      => $post_type,
                'extra_class'    => 'jpkpf-pagination--above',
            ]
        );
    }

}, 10 );


// ---------------------------------------------------------------------------
// loop_end — close results div and outer wrapper
// ---------------------------------------------------------------------------

/**
 * After the loop: close results div and outer wrapper
 *
 * @since 1.0.0
 */
add_action( 'loop_end', static function ( \WP_Query $query ): void {

    if ( ! jpkcom_postfilter_should_auto_inject( $query ) ) {
        return;
    }

    // Render pagination inside the results div so it:
    //   1. appears below the posts (not in the sidebar grid column), and
    //   2. gets replaced on every AJAX filter swap.
    $post_type      = jpkcom_postfilter_get_injected_post_type( $query );
    $base_url       = jpkcom_postfilter_get_archive_base_url( $post_type );
    $active_filters = jpkcom_postfilter_get_active_filters();

    $pagination_pos = jpkcom_postfilter_settings_get( 'layout', 'pagination_position', 'below' );
    $pagination_pos = in_array( $pagination_pos, [ 'above', 'below', 'both' ], true ) ? $pagination_pos : 'below';

    if ( in_array( $pagination_pos, [ 'below', 'both' ], true ) ) {
        jpkcom_postfilter_get_template_part(
            'partials/pagination/pagination',
            '',
            [
                'query'          => $query,
                'base_url'       => $base_url,
                'active_filters' => $active_filters,
                'post_type'      => $post_type,
                'extra_class'    => '',
            ]
        );
    }

    // Close <div data-jpkpf-results>
    echo '</div>';

    // Close the zone here, not after the wrapper: the wrapper also contains the
    // filter bar, which the script does not swap and must not receive twice.
    jpkcom_postfilter_zone_close();

    // Close outer <div data-jpkpf-wrapper>
    echo '</div>';

    // Our pagination is fully rendered. Now suppress theme/plugin pagination
    // that runs after this hook via two complementary strategies:
    //   a) Flag → filters intercept standard WP functions (paginate_links etc.)
    //   b) max_num_pages = 1 → intercepts custom theme functions that read
    //      $wp_query->max_num_pages directly (e.g. bootscore_pagination()).
    $GLOBALS['_jpkpf_suppress_pagination'] = true;
    $query->max_num_pages                  = 1;

}, 10 );


// ---------------------------------------------------------------------------
// Suppress WordPress default pagination when auto-injection is active
// ---------------------------------------------------------------------------

/**
 * When the plugin has auto-injected its own pagination, suppress WordPress's
 * built-in paginated navigation to avoid duplicate navigation elements.
 *
 * The $_jpkpf_suppress_pagination flag is set inside the loop_start callback
 * above and remains true for the remainder of the page render, ensuring that
 * any theme pagination rendered after the loop outputs nothing.
 *
 * Covers three common patterns:
 *   1. the_posts_pagination()  — via paginate_links_output       (WP 6.3+)
 *   2. the_posts_navigation()  — via navigation_markup_template
 *   3. next/previous_posts_link() — via *_attributes filters     (old themes)
 */
add_filter( 'paginate_links_output', static function ( string $output ): string {
    return ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) ? '' : $output;
} );

add_filter( 'navigation_markup_template', static function ( string $template, string $css_class ): string {
    if ( ! ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) ) {
        return $template;
    }
    // Only suppress post archive navigation; leave post-to-post nav untouched
    if ( in_array( $css_class, [ 'pagination', 'posts-navigation' ], true ) ) {
        return '';
    }
    return $template;
}, 10, 2 );

add_filter( 'next_posts_link_attributes', static function ( string $attr ): string {
    return ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) ? 'hidden' : $attr;
} );

add_filter( 'previous_posts_link_attributes', static function ( string $attr ): string {
    return ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) ? 'hidden' : $attr;
} );


// ---------------------------------------------------------------------------
// wp_footer fallback: emit [data-jpkpf-results] when main query has 0 posts
// ---------------------------------------------------------------------------

/**
 * When auto-injection should happen but the main loop never started (0 posts),
 * output an empty [data-jpkpf-results] container so the AJAX response always
 * contains a swappable results zone — and also render the filter bar so the
 * user can change their selection on a zero-results page reload.
 *
 * Runs at priority 1 (early in wp_footer) so it appears before theme scripts.
 *
 * A named function rather than a closure so that fragment-response.php can
 * re-attach it after clearing wp_footer. A closure could be removed but never
 * put back, and losing it would silently break exactly the case this exists
 * for: a filter click with zero results would return an empty fragment with no
 * results zone at all.
 *
 * @since 1.0.0
 * @return void
 */
function jpkcom_postfilter_render_zero_results_fallback(): void {

    // Already injected via loop_start — nothing to do.
    if ( $GLOBALS['_jpkpf_auto_injected'] ?? false ) {
        return;
    }

    // Attached to several hooks, and on most themes more than one of them fires.
    if ( $GLOBALS['_jpkpf_zero_results_rendered'] ?? false ) {
        return;
    }

    global $wp_query;

    if ( ! jpkcom_postfilter_should_auto_inject( $wp_query ) ) {
        return;
    }

    // Only when the loop will not run at all. This check is what makes it safe
    // to attach to a hook that fires *before* the loop — such as a theme's
    // "before loop" action, which is the only position where this output
    // actually belongs. Without it, a page that does have posts would render
    // the filter bar twice: once here and once from loop_start.
    if ( (int) $wp_query->post_count > 0 ) {
        return;
    }

    $GLOBALS['_jpkpf_zero_results_rendered'] = true;

    $post_type      = jpkcom_postfilter_get_injected_post_type( $wp_query );
    $base_url       = jpkcom_postfilter_get_archive_base_url( $post_type );
    $active_filters = jpkcom_postfilter_get_active_filters();

    $general            = jpkcom_postfilter_settings_get_group( 'general' );
    $enabled_pt_general = array_map( 'sanitize_key', (array) ( $general['enabled_post_types'] ?? [ 'post' ] ) );

    $groups = array_values( array_filter(
        jpkcom_postfilter_get_filter_groups_enabled(),
        static function ( array $group ) use ( $post_type, $enabled_pt_general ): bool {
            $group_pts = ! empty( $group['post_types'] ) && is_array( $group['post_types'] )
                ? $group['post_types']
                : $enabled_pt_general;
            return in_array( $post_type, $group_pts, true );
        }
    ) );

    $layout     = jpkcom_postfilter_settings_get( 'layout', 'filter_layout', 'bar' );
    $layout     = in_array( $layout, [ 'bar', 'sidebar', 'dropdown', 'columns' ], true ) ? $layout : 'bar';
    $reset_mode = jpkcom_postfilter_settings_get( 'layout', 'reset_button_mode', 'on_selection' );
    $reset_mode = in_array( $reset_mode, [ 'always', 'on_selection', 'never' ], true ) ? $reset_mode : 'on_selection';

    $filter_groups = [];
    foreach ( $groups as $group ) {
        $enriched = jpkcom_postfilter_get_terms_for_group( $group, $active_filters );
        if ( empty( $enriched ) ) {
            continue;
        }
        $filter_groups[] = array_merge( $group, [
            'terms' => array_map( static fn( array $item ): \WP_Term => $item['term'], $enriched ),
        ] );
    }

    printf(
        '<div data-jpkpf-wrapper data-jpkpf-base-url="%s" data-jpkpf-post-type="%s" data-jpkpf-layout="%s" class="jpkpf-zero-results-wrapper">',
        esc_url( $base_url ),
        esc_attr( $post_type ),
        esc_attr( $layout )
    );

    jpkcom_postfilter_get_template_part(
        'partials/filter/filter-' . $layout,
        '',
        [
            'filter_groups'  => $filter_groups,
            'active_filters' => $active_filters,
            'base_url'       => $base_url,
            'post_type'      => $post_type,
            'show_reset'     => $reset_mode !== 'never',
            'reset_mode'     => $reset_mode,
        ]
    );

    // Mark only the results zone, not the surrounding wrapper: the wrapper also
    // holds the filter bar, which the script must not swap.
    jpkcom_postfilter_zone_open();

    echo '<div data-jpkpf-results aria-live="polite" aria-atomic="false">';
    echo '<p class="jpkpf-no-results">' . esc_html__( 'No posts found.', 'jpkcom-post-filter' ) . '</p>';
    echo '</div>';

    jpkcom_postfilter_zone_close();

    echo '</div>';

}

/**
 * Hooks the zero-results fallback is attached to, best position first
 *
 * WHY THIS IS A LIST
 * ------------------
 * With posts, the filter bar and results zone are injected at `loop_start` —
 * inside the theme's content column, exactly where the listing belongs. With
 * zero posts the loop never starts, `loop_start` never fires, and that position
 * is simply not reachable through any core hook: `have_posts()` returns false
 * before either loop action runs.
 *
 * Until 1.2.0 the fallback ran on `wp_footer` alone, which fires *inside* the
 * footer template — the message and the whole filter bar were rendered below
 * the footer. Moving it to `get_footer` got it above the footer but still at
 * the very end of the content area, nowhere near where the listing sits when
 * there are results.
 *
 * So the first entry is a theme hook that fires *before* the loop condition.
 * bootscore's `bootscore_before_loop` runs immediately before
 * `if ( have_posts() )` in index.php/archive.php, which is precisely the right
 * spot — and this plugin stack is built around bootscore. Other themes add
 * their own via the filter; the two footer hooks stay as last resorts so no
 * theme is left without a way to change the selection.
 *
 * The function itself returns early unless the main query really has zero
 * posts, which is what makes attaching to a pre-loop hook safe.
 *
 * @since 1.2.0
 *
 * @param string[] $hooks Action hook names.
 */
$jpkcom_postfilter_zero_hooks = apply_filters(
    'jpkcom_postfilter_zero_results_hooks',
    [
        'bootscore_before_loop', // fires before `if ( have_posts() )` — correct position
        'get_footer',            // end of the content area
        'wp_footer',             // inside the footer; block themes may reach only this
    ]
);

foreach ( (array) $jpkcom_postfilter_zero_hooks as $jpkcom_postfilter_zero_hook ) {

    if ( is_string( $jpkcom_postfilter_zero_hook ) && $jpkcom_postfilter_zero_hook !== '' ) {
        add_action( $jpkcom_postfilter_zero_hook, 'jpkcom_postfilter_render_zero_results_fallback', 1 );
    }

}

unset( $jpkcom_postfilter_zero_hooks, $jpkcom_postfilter_zero_hook );
