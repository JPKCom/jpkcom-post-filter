<?php
/**
 * Gutenberg Block Registration
 *
 * Registers three server-side-rendered blocks:
 *   jpkcom/post-filter     – Filter/facets UI
 *   jpkcom/post-list        – Filtered post listing
 *   jpkcom/post-pagination  – Pagination for the listing
 *
 * Each block reuses the existing shortcode render functions, mapping
 * block attributes (camelCase) to shortcode attributes (snake_case).
 *
 * In the block editor (ServerSideRender / REST context), certain JS-driven
 * features (show-more threshold, plus/minus mode) are replicated server-side
 * so the preview matches the frontend output.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Register all Gutenberg blocks
 *
 * @since 1.0.0
 */
add_action( 'init', static function (): void {

    // Bail if block editor functions are not available (WP < 5.8).
    if ( ! function_exists( 'register_block_type' ) ) {
        return;
    }

    $blocks_dir = JPKCOM_POSTFILTER_PLUGIN_PATH . 'blocks/';
    $build_dir  = $blocks_dir . 'build/';

    // Only register blocks when the build directory exists.
    if ( ! is_dir( $build_dir ) ) {
        if ( JPKCOM_POSTFILTER_DEBUG ) {
            error_log( '[jpkcom-post-filter] Blocks build directory missing. Run `npm run build`.' );
        }
        return;
    }

    // -----------------------------------------------------------------
    // jpkcom/post-filter
    // -----------------------------------------------------------------
    register_block_type( $blocks_dir . 'post-filter', [
        'render_callback' => 'jpkcom_postfilter_block_render_filter',
    ] );

    // -----------------------------------------------------------------
    // jpkcom/post-list
    // -----------------------------------------------------------------
    register_block_type( $blocks_dir . 'post-list', [
        'render_callback' => 'jpkcom_postfilter_block_render_list',
    ] );

    // -----------------------------------------------------------------
    // jpkcom/post-pagination
    // -----------------------------------------------------------------
    register_block_type( $blocks_dir . 'post-pagination', [
        'render_callback' => 'jpkcom_postfilter_block_render_pagination',
    ] );

}, 20 );


// =====================================================================
// Pre-scan: run list block queries before any block renders
// =====================================================================

/**
 * Pre-run jpkcom/post-list queries before block rendering starts
 *
 * Gutenberg renders blocks sequentially. When a pagination block is placed
 * ABOVE the list block, it renders first and finds no query in globals.
 *
 * This filter fires on the very first block render call and pre-scans the
 * full block tree (FSE template + post content) for jpkcom/post-list blocks.
 * Their queries are executed and stored in $GLOBALS['jpkpf_shortcode_queries']
 * so that pagination blocks at any position can access them.
 *
 * @since 1.0.0
 */
add_filter( 'pre_render_block', static function ( ?string $pre_render, array $parsed_block ): ?string {

    static $done = false;

    if ( $done || jpkcom_postfilter_is_block_editor_request() ) {
        return $pre_render;
    }

    $done = true;

    // Collect block content from all available sources
    $sources = [];

    // FSE template content (set by locate_block_template)
    global $_wp_current_template_content;
    if ( ! empty( $_wp_current_template_content ) ) {
        $sources[] = $_wp_current_template_content;
    }

    // Classic editor: post content
    global $post;
    if ( $post instanceof \WP_Post && has_blocks( $post->post_content ) ) {
        $sources[] = $post->post_content;
    }

    foreach ( $sources as $content ) {
        $blocks = parse_blocks( $content );
        jpkcom_postfilter_prerun_list_blocks( $blocks );
    }

    return $pre_render;

}, 5, 2 );


if ( ! function_exists( 'jpkcom_postfilter_prerun_list_blocks' ) ) {
    /**
     * Recursively find jpkcom/post-list blocks and pre-run their queries
     *
     * Extracts the block attributes (postType, limit, orderby, order), builds
     * a WP_Query with the same parameters the list block will use later, and
     * stores the result in $GLOBALS['jpkpf_shortcode_queries']. The list block
     * will overwrite this entry when it renders, but the pagination block above
     * already has a valid query to work with.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $blocks Parsed block array.
     */
    function jpkcom_postfilter_prerun_list_blocks( array $blocks ): void {

        foreach ( $blocks as $block ) {

            if ( ( $block['blockName'] ?? '' ) === 'jpkcom/post-list' ) {

                $attrs     = $block['attrs'] ?? [];
                $post_type = sanitize_key( (string) ( $attrs['postType'] ?? 'post' ) );

                // Only pre-run once per post type
                if ( ! isset( $GLOBALS['jpkpf_shortcode_queries'][ $post_type ] ) ) {

                    $limit   = (int) ( $attrs['limit'] ?? 5 );
                    $orderby = sanitize_key( (string) ( $attrs['orderby'] ?? 'date' ) );
                    $order   = strtoupper( sanitize_key( (string) ( $attrs['order'] ?? 'DESC' ) ) );
                    $order   = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

                    $active_filters = jpkcom_postfilter_get_active_filters();
                    $query_args     = jpkcom_postfilter_build_query_args( [
                        'post_type' => $post_type,
                        'limit'     => $limit,
                        'orderby'   => $orderby,
                        'order'     => $order,
                    ], $active_filters );

                    $query = jpkcom_postfilter_run_query( $query_args, $active_filters );
                    $GLOBALS['jpkpf_shortcode_queries'][ $post_type ] = $query;
                }
            }

            // Recurse into inner blocks (e.g. blocks inside groups/columns)
            if ( ! empty( $block['innerBlocks'] ) ) {
                jpkcom_postfilter_prerun_list_blocks( $block['innerBlocks'] );
            }
        }
    }
}


// =====================================================================
// Helper: detect block editor (REST API) context
// =====================================================================

if ( ! function_exists( 'jpkcom_postfilter_is_block_editor_request' ) ) {
    /**
     * Check if the current request is a block editor ServerSideRender call
     *
     * @since 1.0.0
     * @return bool
     */
    function jpkcom_postfilter_is_block_editor_request(): bool {
        return defined( 'REST_REQUEST' ) && REST_REQUEST;
    }
}


// =====================================================================
// Render callbacks
// =====================================================================

if ( ! function_exists( 'jpkcom_postfilter_block_render_filter' ) ) {
    /**
     * Render callback for jpkcom/post-filter block
     *
     * In the editor preview (REST context), JS-driven features like
     * show-more and plus/minus mode are applied server-side so the
     * preview matches the actual frontend output.
     *
     * @since 1.0.0
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_block_render_filter( array $attributes ): string {
        $atts = [
            'post_type' => $attributes['postType'] ?? 'post',
            'layout'    => $attributes['layout'] ?? '',
            'groups'    => $attributes['groups'] ?? '',
            'reset'     => $attributes['reset'] ?? 'true',
            'class'     => $attributes['className'] ?? '',
        ];

        $html = jpkcom_postfilter_shortcode_filter( $atts );

        // In the editor: apply JS-driven features server-side
        if ( jpkcom_postfilter_is_block_editor_request() ) {
            $html = jpkcom_postfilter_block_apply_show_more( $html );
            $html = jpkcom_postfilter_block_apply_plus_minus( $html );
        }

        return $html;
    }
}


if ( ! function_exists( 'jpkcom_postfilter_block_render_list' ) ) {
    /**
     * Render callback for jpkcom/post-list block
     *
     * @since 1.0.0
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_block_render_list( array $attributes ): string {
        $atts = [
            'post_type' => $attributes['postType'] ?? 'post',
            'layout'    => $attributes['layout'] ?? '',
            'limit'     => (string) ( $attributes['limit'] ?? '5' ),
            'orderby'   => $attributes['orderby'] ?? 'date',
            'order'     => $attributes['order'] ?? 'DESC',
            'class'     => $attributes['className'] ?? '',
        ];

        return jpkcom_postfilter_shortcode_list( $atts );
    }
}


if ( ! function_exists( 'jpkcom_postfilter_block_render_pagination' ) ) {
    /**
     * Render callback for jpkcom/post-pagination block
     *
     * In the block editor (ServerSideRender), the list block's query is not
     * available, so we render a static example pagination to give the user
     * a visual preview of how it will look.
     *
     * @since 1.0.0
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_block_render_pagination( array $attributes ): string {

        // In the block editor: render a preview with example pages
        if ( jpkcom_postfilter_is_block_editor_request() ) {
            return jpkcom_postfilter_block_pagination_preview( $attributes );
        }

        $atts = [
            'post_type' => $attributes['postType'] ?? 'post',
            'class'     => $attributes['className'] ?? '',
        ];

        return jpkcom_postfilter_shortcode_pagination( $atts );
    }
}


// =====================================================================
// Editor preview helpers
// =====================================================================

if ( ! function_exists( 'jpkcom_postfilter_block_pagination_preview' ) ) {
    /**
     * Render a static pagination preview for the block editor
     *
     * Generates 7 example pages with page 2 as current,
     * including previous/next links – matching the plugin's
     * actual pagination HTML structure.
     *
     * @since 1.0.0
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @return string Static HTML preview.
     */
    function jpkcom_postfilter_block_pagination_preview( array $attributes ): string {
        $extra_class = ! empty( $attributes['className'] )
            ? ' ' . esc_attr( $attributes['className'] )
            : '';

        $prev_label = '&laquo; ' . esc_html__( 'Previous', 'jpkcom-post-filter' );
        $next_label = esc_html__( 'Next', 'jpkcom-post-filter' ) . ' &raquo;';

        $html  = '<nav class="jpkpf-pagination' . $extra_class . '" aria-label="' . esc_attr__( 'Pagination', 'jpkcom-post-filter' ) . '">';
        $html .= '<ul class="page-numbers">';
        $html .= '<li><span class="page-numbers">' . $prev_label . '</span></li>';

        for ( $i = 1; $i <= 7; $i++ ) {
            if ( $i === 2 ) {
                $html .= '<li><span aria-current="page" class="page-numbers current">' . $i . '</span></li>';
            } elseif ( $i === 5 ) {
                $html .= '<li><span class="page-numbers dots">&hellip;</span></li>';
            } elseif ( $i > 5 ) {
                $html .= '<li><span class="page-numbers">' . ( $i + 1 ) . '</span></li>';
            } else {
                $html .= '<li><span class="page-numbers">' . $i . '</span></li>';
            }
        }

        $html .= '<li><span class="page-numbers">' . $next_label . '</span></li>';
        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }
}


if ( ! function_exists( 'jpkcom_postfilter_block_apply_show_more' ) ) {
    /**
     * Apply show-more threshold to filter HTML (server-side)
     *
     * Replicates the JS show-more feature for the editor preview:
     * hides filter buttons beyond the threshold and inserts a
     * static "…" toggle button.
     *
     * @since 1.0.0
     *
     * @param string $html Filter HTML.
     * @return string Modified HTML with show-more applied.
     */
    function jpkcom_postfilter_block_apply_show_more( string $html ): string {
        $layout = jpkcom_postfilter_settings_get_group( 'layout' );

        if ( empty( $layout['show_more_enabled'] ) ) {
            return $html;
        }

        $threshold = max( 1, (int) ( $layout['show_more_threshold'] ?? 10 ) );

        // Use DOMDocument to manipulate the HTML
        if ( ! class_exists( 'DOMDocument' ) ) {
            return $html;
        }

        $doc = new \DOMDocument();
        // Suppress warnings for HTML5 tags; wrap in container for fragment parsing
        @$doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="jpkpf-wrap">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath( $doc );

        // Find all filter groups (both bar/sidebar and columns layout)
        $groups = $xpath->query( '//*[contains(@class,"jpkpf-filter-group") or contains(@class,"jpkpf-filter-columns-group")]' );

        if ( $groups === false ) {
            return $html;
        }

        $more_label = esc_html__( "\u{2026}", 'jpkcom-post-filter' );

        foreach ( $groups as $group ) {
            $buttons = $xpath->query( './/*[contains(@class,"jpkpf-filter-btn") and @data-filter-term]', $group );

            if ( $buttons === false || $buttons->length <= $threshold ) {
                continue;
            }

            // Hide buttons beyond threshold
            $hidden_count = 0;
            for ( $i = $threshold; $i < $buttons->length; $i++ ) {
                $btn = $buttons->item( $i );
                if ( $btn instanceof \DOMElement ) {
                    $btn->setAttribute( 'hidden', 'hidden' );
                    $btn->setAttribute( 'style', 'display:none' );
                    $hidden_count++;
                }
            }

            if ( $hidden_count > 0 ) {
                // Insert a "…" button after the last visible button
                $last_visible = $buttons->item( $threshold - 1 );
                if ( $last_visible instanceof \DOMElement ) {
                    $more_btn = $doc->createElement( 'button', $more_label );
                    $more_btn->setAttribute( 'type', 'button' );
                    $more_btn->setAttribute( 'class', 'jpkpf-filter-btn jpkpf-show-more-btn' );
                    $more_btn->setAttribute( 'aria-expanded', 'false' );
                    $more_btn->setAttribute( 'disabled', 'disabled' );

                    if ( $last_visible->nextSibling ) {
                        $group->insertBefore( $more_btn, $last_visible->nextSibling );
                    } else {
                        $group->appendChild( $more_btn );
                    }
                }
            }
        }

        // Extract the inner HTML of our wrapper
        $wrapper = $doc->getElementById( 'jpkpf-wrap' );
        if ( ! $wrapper ) {
            return $html;
        }

        $result = '';
        foreach ( $wrapper->childNodes as $child ) {
            $result .= $doc->saveHTML( $child );
        }

        return $result;
    }
}


if ( ! function_exists( 'jpkcom_postfilter_block_apply_plus_minus' ) ) {
    /**
     * Apply plus/minus mode to filter HTML (server-side)
     *
     * Adds a jpkpf-pm-mode class to the filter nav and inserts
     * +/− icon spans into each filter button – replicating the
     * JS plus/minus feature for the editor preview.
     *
     * @since 1.0.0
     *
     * @param string $html Filter HTML.
     * @return string Modified HTML with plus/minus icons.
     */
    function jpkcom_postfilter_block_apply_plus_minus( string $html ): string {
        $layout = jpkcom_postfilter_settings_get_group( 'layout' );

        if ( empty( $layout['plus_minus_mode'] ) ) {
            return $html;
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return $html;
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="jpkpf-wrap">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath( $doc );

        // Add jpkpf-pm-mode class to the nav element
        $navs = $xpath->query( '//nav[contains(@class,"jpkpf-filter-")]' );
        if ( $navs !== false ) {
            foreach ( $navs as $nav ) {
                if ( $nav instanceof \DOMElement ) {
                    $classes = $nav->getAttribute( 'class' );
                    if ( strpos( $classes, 'jpkpf-pm-mode' ) === false ) {
                        $nav->setAttribute( 'class', $classes . ' jpkpf-pm-mode' );
                    }
                }
            }
        }

        // Add +/− icon span to each filter button
        $buttons = $xpath->query( '//*[contains(@class,"jpkpf-filter-btn") and @data-filter-term]' );
        if ( $buttons !== false ) {
            foreach ( $buttons as $btn ) {
                if ( ! ( $btn instanceof \DOMElement ) ) {
                    continue;
                }
                $is_active = strpos( $btn->getAttribute( 'aria-pressed' ) ?? '', 'true' ) !== false
                    || strpos( $btn->getAttribute( 'class' ) ?? '', 'is-active' ) !== false;

                $icon_span = $doc->createElement( 'span', $is_active ? "\u{2212}" : '+' );
                $icon_span->setAttribute( 'class', 'jpkpf-pm-icon' );
                $icon_span->setAttribute( 'aria-hidden', 'true' );
                $btn->appendChild( $icon_span );
            }
        }

        $wrapper = $doc->getElementById( 'jpkpf-wrap' );
        if ( ! $wrapper ) {
            return $html;
        }

        $result = '';
        foreach ( $wrapper->childNodes as $child ) {
            $result .= $doc->saveHTML( $child );
        }

        return $result;
    }
}
