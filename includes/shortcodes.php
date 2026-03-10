<?php
/**
 * Shortcode Registration
 *
 * Registers the following shortcodes:
 *   [jpkcom_postfilter_filter]     – Filter/facets bar
 *   [jpkcom_postfilter_list]       – Post listing
 *   [jpkcom_postfilter_pagination] – Pagination
 *
 * Typical page usage (place in this order):
 *   [jpkcom_postfilter_filter post_type="post" layout="bar"]
 *   [jpkcom_postfilter_list   post_type="post" limit="10"]
 *   [jpkcom_postfilter_pagination post_type="post"]
 *
 * The filter shortcode renders a standalone filter bar.  AJAX pairing with
 * the list shortcode happens via matching data-jpkpf-post-type attributes
 * read by post-filter.js.  The pagination shortcode reads the query stored
 * by the list shortcode via $GLOBALS['jpkpf_shortcode_queries'].
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

// Shared query registry: list shortcode stores its WP_Query here so the
// pagination shortcode (processed later) can read max_num_pages.
if ( ! isset( $GLOBALS['jpkpf_shortcode_queries'] ) ) {
    $GLOBALS['jpkpf_shortcode_queries'] = [];
}


// ===========================================================================
// [jpkcom_postfilter_filter]
// ===========================================================================

add_shortcode( 'jpkcom_postfilter_filter', 'jpkcom_postfilter_shortcode_filter' );

if ( ! function_exists( function: 'jpkcom_postfilter_shortcode_filter' ) ) {
    /**
     * Shortcode: [jpkcom_postfilter_filter]
     *
     * Renders the filter UI (bar / sidebar / dropdown) for a post type.
     * When placed before [jpkcom_postfilter_list] with the same post_type,
     * post-filter.js pairs them via data-jpkpf-post-type for AJAX filtering.
     *
     * Attributes:
     *   post_type (string)  Post type slug.                     Default: 'post'
     *   layout    (string)  bar | sidebar | dropdown.           Default: backend setting
     *   groups    (string)  Comma-separated filter group slugs. Default: all enabled
     *   reset     (string)  true | false – show reset button.  Default: 'true'
     *   class     (string)  Extra CSS class.                    Default: ''
     *
     * @since 1.0.0
     *
     * @param array<string,string>|string $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_shortcode_filter( array|string $atts ): string {
        $atts = shortcode_atts( [
            'post_type' => 'post',
            'layout'    => '',
            'groups'    => '',
            'reset'     => 'true',
            'class'     => '',
        ], $atts, 'jpkcom_postfilter_filter' );

        $post_type = sanitize_key( (string) $atts['post_type'] );
        $layout    = sanitize_key( (string) $atts['layout'] );

        if ( ! in_array( $layout, [ 'bar', 'sidebar', 'dropdown', 'columns' ], true ) ) {
            $layout = (string) jpkcom_postfilter_settings_get( 'layout', 'filter_layout', 'bar' );
        }

        $reset_mode  = jpkcom_postfilter_settings_get( 'layout', 'reset_button_mode', 'on_selection' );
        $reset_mode  = in_array( $reset_mode, [ 'always', 'on_selection', 'never' ], true ) ? $reset_mode : 'on_selection';
        // Per-shortcode attribute overrides the global setting:
        // reset="false" → never; reset="always" → always; reset="true" → use backend setting.
        if ( $atts['reset'] === 'false' ) {
            $reset_mode = 'never';
        } elseif ( $atts['reset'] === 'always' ) {
            $reset_mode = 'always';
        }
        $show_reset  = $reset_mode !== 'never';
        $extra_class = implode(
            ' ',
            array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) )
        );

        // Restrict to specific group slugs when provided
        $group_filter = ! empty( $atts['groups'] )
            ? jpkcom_postfilter_sanitize_csv_slugs( (string) $atts['groups'] )
            : [];

        $base_url       = jpkcom_postfilter_get_archive_base_url( $post_type );
        $active_filters = jpkcom_postfilter_get_active_filters();

        $groups = jpkcom_postfilter_get_filter_groups_enabled();
        if ( ! empty( $group_filter ) ) {
            $groups = array_values( array_filter(
                $groups,
                static fn( array $g ): bool => in_array( $g['slug'], $group_filter, true )
            ) );
        }

        // Filter groups by post_type (same logic as filter-injection.php).
        $enabled_pt_general = array_map(
            'sanitize_key',
            (array) jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] )
        );
        $groups = array_values( array_filter(
            $groups,
            static function ( array $group ) use ( $post_type, $enabled_pt_general ): bool {
                $group_pts = ! empty( $group['post_types'] ) && is_array( $group['post_types'] )
                    ? $group['post_types']
                    : $enabled_pt_general;
                return in_array( $post_type, $group_pts, true );
            }
        ) );

        // Enrich groups with their terms (filtered by hide_empty etc.)
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

        return jpkcom_postfilter_get_template_html( 'shortcodes/filter', '', [
            'filter_groups'  => $filter_groups,
            'active_filters' => $active_filters,
            'base_url'       => $base_url,
            'post_type'      => $post_type,
            'show_reset'     => $show_reset,
            'reset_mode'     => $reset_mode,
            'extra_class'    => $extra_class,
            'layout'         => $layout,
        ] );
    }
}


// ===========================================================================
// [jpkcom_postfilter_list]
// ===========================================================================

add_shortcode( 'jpkcom_postfilter_list', 'jpkcom_postfilter_shortcode_list' );

if ( ! function_exists( function: 'jpkcom_postfilter_shortcode_list' ) ) {
    /**
     * Shortcode: [jpkcom_postfilter_list]
     *
     * Runs a WP_Query for the given post type (with active filters applied),
     * stores the query for [jpkcom_postfilter_pagination], and renders the
     * post list via the selected layout template.
     *
     * Attributes:
     *   post_type (string)  Post type slug.                Default: 'post'
     *   layout    (string)  cards | rows | minimal.        Default: backend setting
     *   limit     (int)     posts_per_page. -1 = all.      Default: -1
     *   orderby   (string)  date | title | menu_order.     Default: 'date'
     *   order     (string)  ASC | DESC.                    Default: 'DESC'
     *   class     (string)  Extra CSS class.               Default: ''
     *
     * @since 1.0.0
     *
     * @param array<string,string>|string $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_shortcode_list( array|string $atts ): string {
        $atts = shortcode_atts( [
            'post_type' => 'post',
            'layout'    => '',
            'limit'     => '-1',
            'orderby'   => 'date',
            'order'     => 'DESC',
            'class'     => '',
        ], $atts, 'jpkcom_postfilter_list' );

        $post_type = sanitize_key( (string) $atts['post_type'] );
        $layout    = sanitize_key( (string) $atts['layout'] );

        if ( ! in_array( $layout, [ 'cards', 'rows', 'minimal', 'theme' ], true ) ) {
            $layout = (string) jpkcom_postfilter_settings_get( 'layout', 'list_layout', 'cards' );
        }

        $limit       = (int) $atts['limit'];
        $orderby     = sanitize_key( (string) $atts['orderby'] );
        $order       = strtoupper( sanitize_key( (string) $atts['order'] ) );
        $order       = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
        $extra_class = implode(
            ' ',
            array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) )
        );

        $active_filters = jpkcom_postfilter_get_active_filters();
        $query_args     = jpkcom_postfilter_build_query_args( [
            'post_type' => $post_type,
            'limit'     => $limit,
            'orderby'   => $orderby,
            'order'     => $order,
        ], $active_filters );

        $query = jpkcom_postfilter_run_query( $query_args, $active_filters );

        // Make query available to [jpkcom_postfilter_pagination] on the same page
        $GLOBALS['jpkpf_shortcode_queries'][ $post_type ] = $query;

        return jpkcom_postfilter_get_template_html( 'shortcodes/posts-list', '', [
            'query'       => $query,
            'post_type'   => $post_type,
            'layout'      => $layout,
            'extra_class' => $extra_class,
        ] );
    }
}


// ===========================================================================
// [jpkcom_postfilter_pagination]
// ===========================================================================

add_shortcode( 'jpkcom_postfilter_pagination', 'jpkcom_postfilter_shortcode_pagination' );

if ( ! function_exists( function: 'jpkcom_postfilter_shortcode_pagination' ) ) {
    /**
     * Shortcode: [jpkcom_postfilter_pagination]
     *
     * Renders pagination for the query run by [jpkcom_postfilter_list].
     * Must be placed after [jpkcom_postfilter_list] in the page content.
     * Returns an empty string when max_num_pages ≤ 1.
     *
     * Attributes:
     *   post_type (string) Post type slug. Default: 'post'
     *   class     (string) Extra CSS class. Default: ''
     *
     * @since 1.0.0
     *
     * @param array<string,string>|string $atts Shortcode attributes.
     * @return string Rendered HTML or empty string.
     */
    function jpkcom_postfilter_shortcode_pagination( array|string $atts ): string {
        $atts = shortcode_atts( [
            'post_type' => 'post',
            'class'     => '',
        ], $atts, 'jpkcom_postfilter_pagination' );

        $post_type   = sanitize_key( (string) $atts['post_type'] );
        $extra_class = implode(
            ' ',
            array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) )
        );

        $query = $GLOBALS['jpkpf_shortcode_queries'][ $post_type ] ?? null;

        // No query in globals yet — pagination shortcode placed before list shortcode,
        // or the pre_render_block pre-scan did not cover this context.
        // Fall back to a query with WP's default posts_per_page.
        if ( ! $query instanceof \WP_Query ) {
            $active_filters = jpkcom_postfilter_get_active_filters();
            $query_args     = jpkcom_postfilter_build_query_args( [
                'post_type' => $post_type,
                'limit'     => (int) get_option( 'posts_per_page', 10 ),
            ], $active_filters );

            $query = jpkcom_postfilter_run_query( $query_args, $active_filters );

            // Store so subsequent pagination blocks on the same page can reuse it
            $GLOBALS['jpkpf_shortcode_queries'][ $post_type ] = $query;
        }

        $base_url       = jpkcom_postfilter_get_archive_base_url( $post_type );
        $active_filters = jpkcom_postfilter_get_active_filters();

        if ( (int) $query->max_num_pages <= 1 ) {
            // Render a hidden placeholder so the JS AJAX swap can restore
            // pagination when filter changes bring back multiple pages.
            return '<nav class="jpkpf-pagination' . ( $extra_class !== '' ? ' ' . esc_attr( $extra_class ) : '' ) . '"'
                . ' data-jpkpf-pagination'
                . ' data-jpkpf-post-type="' . esc_attr( $post_type ) . '"'
                . ' aria-label="' . esc_attr__( 'Page navigation', 'jpkcom-post-filter' ) . '"'
                . ' hidden></nav>';
        }

        return jpkcom_postfilter_get_template_html( 'shortcodes/pagination', '', [
            'query'          => $query,
            'base_url'       => $base_url,
            'active_filters' => $active_filters,
            'post_type'      => $post_type,
            'extra_class'    => $extra_class,
        ] );
    }
}
