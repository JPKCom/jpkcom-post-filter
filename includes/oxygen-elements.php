<?php
/**
 * Oxygen Builder Element Registration
 *
 * Registers three Oxygen elements that mirror the Gutenberg blocks
 * and Elementor widgets:
 *   JPKCom Post Filter     – Filter/facets UI
 *   JPKCom Post List        – Filtered post listing
 *   JPKCom Post Pagination  – Pagination for the listing
 *
 * Uses the modern OxyEl API (Oxygen 3.x+). Each element reuses the
 * existing shortcode render functions.
 *
 * Only loaded when the Oxygen Builder plugin is active.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.1.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

// Register a custom section in the Oxygen Add Elements panel.
add_action( 'oxygen_add_plus_sections', static function (): void {
    \CT_Toolbar::oxygen_add_plus_accordion_section( 'jpkcom-post-filter', __( 'Post Filter', 'jpkcom-post-filter' ) );
} );

// Load and instantiate elements after all plugins are loaded (Oxygen must be available).
add_action( 'init', static function (): void {
    if ( ! class_exists( 'OxyEl' ) ) {
        return;
    }

    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/oxygen/class-element-filter.php';
    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/oxygen/class-element-list.php';
    require_once JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/oxygen/class-element-pagination.php';

    new \JPKComPostFilter\Oxygen\Element_Filter();
    new \JPKComPostFilter\Oxygen\Element_List();
    new \JPKComPostFilter\Oxygen\Element_Pagination();
}, 11 );
