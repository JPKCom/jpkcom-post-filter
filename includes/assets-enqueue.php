<?php
/**
 * Asset Enqueuing – Scripts and Styles
 *
 * Handles enqueuing of CSS and JavaScript files for the plugin.
 * Frontend assets load only when filter shortcodes or auto-injection is active.
 * Admin assets load only on plugin admin pages.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Enqueue frontend scripts and styles
 *
 * @since 1.0.0
 */
add_action( 'wp_enqueue_scripts', static function (): void {

    $layout_settings  = jpkcom_postfilter_settings_get_group( 'layout' );
    $stylesheet_mode  = $layout_settings['stylesheet_mode'] ?? 'full';
    $custom_css       = $layout_settings['custom_css'] ?? '';

    // ----- Stylesheet enqueue based on mode -----

    if ( $stylesheet_mode === 'full' ) {
        wp_enqueue_style(
            'jpkcom-post-filter',
            JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/css/post-filter.css',
            [],
            JPKCOM_POSTFILTER_VERSION,
            'all'
        );

        // In full mode: only output the override vars (scheme + custom)
        $merged_vars = jpkcom_postfilter_get_merged_css_vars( false );
    } elseif ( $stylesheet_mode === 'vars_only' ) {
        // No CSS file: output all defaults + scheme overrides + custom vars as inline style
        $merged_vars = jpkcom_postfilter_get_merged_css_vars( true );

        // Register a dummy stylesheet handle so we can attach inline styles
        wp_register_style( 'jpkcom-post-filter', false, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
        wp_enqueue_style( 'jpkcom-post-filter' );
    } else {
        // 'disabled' – no CSS at all; skip inline vars too
        $merged_vars = [];
    }

    // ----- Build inline CSS block -----

    $inline_css = '';

    if ( ! empty( $merged_vars ) ) {
        $inline_css .= ":root {\n";
        foreach ( $merged_vars as $var_name => $var_value ) {
            $css_var_name = '--jpkpf-' . sanitize_key( (string) $var_name );
            $inline_css  .= "\t" . esc_attr( $css_var_name ) . ': ' . esc_attr( (string) $var_value ) . ";\n";
        }
        $inline_css .= "}\n";
    }

    if ( ! empty( $custom_css ) ) {
        $inline_css .= "\n" . wp_strip_all_tags( $custom_css );
    }

    if ( $inline_css !== '' && $stylesheet_mode !== 'disabled' ) {
        wp_add_inline_style( 'jpkcom-post-filter', $inline_css );
    }

    // ----- Enqueue main frontend JavaScript -----

    wp_enqueue_script(
        'jpkcom-post-filter',
        JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/js/post-filter.js',
        [],
        JPKCOM_POSTFILTER_VERSION,
        true // Load in footer
    );

    // Pass data to JS
    wp_localize_script(
        'jpkcom-post-filter',
        'jpkcomPostFilter',
        [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'jpkcom_postfilter_ajax' ),
            'endpoint'            => jpkcom_postfilter_settings_get( 'general', 'url_endpoint', JPKCOM_POSTFILTER_URL_ENDPOINT ),
            'maxFiltersPerGroup'  => (int) jpkcom_postfilter_settings_get( 'general', 'max_filters_per_group', 3 ),
            'maxFilterCombos'     => (int) jpkcom_postfilter_settings_get( 'general', 'max_filter_combos', 3 ),
            'filterGroupOrder'    => array_values( array_map(
                static fn( array $g ): string => (string) ( $g['taxonomy'] ?? '' ),
                jpkcom_postfilter_get_filter_groups_enabled()
            ) ),
            'debug'               => JPKCOM_POSTFILTER_DEBUG,
            'plusMinusMode' => ! empty( $layout_settings['plus_minus_mode'] ),
            'showMore'      => [
                'enabled'   => ! empty( $layout_settings['show_more_enabled'] ),
                'threshold' => max( 1, (int) ( $layout_settings['show_more_threshold'] ?? 10 ) ),
            ],
            'i18n'          => [
                'loading'      => __( 'Loading…', 'jpkcom-post-filter' ),
                'noResults'    => __( 'No posts found.', 'jpkcom-post-filter' ),
                'filterActive' => __( 'Filter active', 'jpkcom-post-filter' ),
                'resetFilters' => __( 'Reset all filters', 'jpkcom-post-filter' ),
                'showMore'     => __( '…', 'jpkcom-post-filter' ),
                'showLess'     => __( '«', 'jpkcom-post-filter' ),
            ],
        ]
    );

}, 20 );


/**
 * Enqueue admin scripts and styles
 *
 * @since 1.0.0
 */
add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {

    // Only load on plugin admin pages
    $plugin_pages = [
        'toplevel_page_jpkcom-post-filter',
        'post-filter_page_jpkcom-postfilter-filter-groups',
        'post-filter_page_jpkcom-postfilter-layout',
        'post-filter_page_jpkcom-postfilter-shortcodes',
        'post-filter_page_jpkcom-postfilter-cache',
        'post-filter_page_jpkcom-postfilter-import-export',
    ];

    if ( ! in_array( $hook, $plugin_pages, true ) ) {
        return;
    }

    // Admin stylesheet
    wp_enqueue_style(
        'jpkcom-post-filter-admin',
        JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/css/admin.css',
        [],
        JPKCOM_POSTFILTER_VERSION,
        'all'
    );

    // Filter groups JS – only on filter groups page
    if ( $hook === 'post-filter_page_jpkcom-postfilter-filter-groups' ) {
        wp_enqueue_script(
            'jpkcom-post-filter-filter-groups',
            JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/js/filter-groups.js',
            [],
            JPKCOM_POSTFILTER_VERSION,
            true
        );
    }

    // Shortcode generator JS – only on shortcodes page
    if ( $hook === 'post-filter_page_jpkcom-postfilter-shortcodes' ) {
        wp_enqueue_script(
            'jpkcom-post-filter-shortcode-generator',
            JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/js/shortcode-generator.js',
            [],
            JPKCOM_POSTFILTER_VERSION,
            true
        );
    }

    // Layout admin JS – only on layout page
    if ( $hook === 'post-filter_page_jpkcom-postfilter-layout' ) {
        wp_enqueue_script(
            'jpkcom-post-filter-admin-layout',
            JPKCOM_POSTFILTER_PLUGIN_URL . 'assets/js/admin-layout.js',
            [],
            JPKCOM_POSTFILTER_VERSION,
            true
        );
    }

}, 10 );
