<?php
/**
 * Elementor Widget Registration
 *
 * Registers three Elementor widgets that mirror the Gutenberg blocks:
 *   JPKCom Post Filter     – Filter/facets UI
 *   JPKCom Post List        – Filtered post listing
 *   JPKCom Post Pagination  – Pagination for the listing
 *
 * Each widget reuses the existing shortcode render functions, mapping
 * Elementor control values to shortcode attributes.
 *
 * Only loaded when the Elementor plugin is active.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

// Only proceed if Elementor is active and loaded.
if ( ! did_action( 'elementor/loaded' ) ) {
    return;
}


/**
 * Register the custom Elementor widget category
 *
 * @since 1.0.0
 */
add_action( 'elementor/elements/categories_registered', static function ( \Elementor\Elements_Manager $elements_manager ): void {

    $elements_manager->add_category( 'jpkcom-post-filter', [
        'title' => esc_html__( 'JPKCom Post Filter', 'jpkcom-post-filter' ),
        'icon'  => 'eicon-filter',
    ] );

} );


/**
 * Register all Elementor widgets
 *
 * @since 1.0.0
 */
add_action( 'elementor/widgets/register', static function ( \Elementor\Widgets_Manager $widgets_manager ): void {

    // Load widget classes
    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/elementor/class-widget-filter.php';
    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/elementor/class-widget-list.php';
    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/elementor/class-widget-pagination.php';

    $widgets_manager->register( new \JPKComPostFilter\Elementor\Widget_Filter() );
    $widgets_manager->register( new \JPKComPostFilter\Elementor\Widget_List() );
    $widgets_manager->register( new \JPKComPostFilter\Elementor\Widget_Pagination() );

} );
