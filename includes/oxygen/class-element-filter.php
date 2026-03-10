<?php
/**
 * Oxygen Element: Post Filter
 *
 * Renders the filter/facets UI for a post type.
 * Wraps jpkcom_postfilter_shortcode_filter().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.1.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Oxygen;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Element_Filter extends \OxyEl {

    function name(): string {
        return __( 'Post Filter', 'jpkcom-post-filter' );
    }

    function slug(): string {
        return 'jpkcom-post-filter';
    }

    function icon(): string {
        return '';
    }

    function button_place(): string {
        return 'jpkcom-post-filter::section_content';
    }

    function button_priority(): int {
        return 1;
    }

    function tag(): string {
        return 'div';
    }

    function keywords(): string {
        return 'filter,facets,taxonomy,category,tag,jpkcom';
    }

    function controls(): void {

        // Post Type
        $post_types = $this->get_post_type_options();
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Post Type', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_post_type',
            'default' => 'post',
        ] )->setValue( $post_types )->rebuildElementOnChange();

        // Layout
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Layout', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_layout',
            'default' => '',
        ] )->setValue( [
            ''         => __( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
            'bar'      => __( 'Bar', 'jpkcom-post-filter' ),
            'sidebar'  => __( 'Sidebar', 'jpkcom-post-filter' ),
            'dropdown' => __( 'Dropdown', 'jpkcom-post-filter' ),
            'columns'  => __( 'Columns', 'jpkcom-post-filter' ),
        ] )->rebuildElementOnChange();

        // Filter Groups
        $this->addOptionControl( [
            'type'    => 'textfield',
            'name'    => __( 'Filter Groups (comma-separated slugs)', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_groups',
            'default' => '',
        ] )->rebuildElementOnChange();

        // Reset Button
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Reset Button', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_reset',
            'default' => 'true',
        ] )->setValue( [
            'true'   => __( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
            'always' => __( 'Always', 'jpkcom-post-filter' ),
            'false'  => __( 'Never', 'jpkcom-post-filter' ),
        ] )->rebuildElementOnChange();
    }

    function render( $options, $defaults, $content ): void {

        $atts = [
            'post_type' => $options['jpkpf_post_type'] ?? 'post',
            'layout'    => $options['jpkpf_layout'] ?? '',
            'groups'    => $options['jpkpf_groups'] ?? '',
            'reset'     => $options['jpkpf_reset'] ?? 'true',
            'class'     => '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo jpkcom_postfilter_shortcode_filter( $atts );
    }

    /**
     * @return array<string, string>
     */
    private function get_post_type_options(): array {
        $options = [];
        $types   = get_post_types( [ 'public' => true ], 'objects' );
        $exclude = [ 'attachment' ];

        foreach ( $types as $type ) {
            if ( in_array( $type->name, $exclude, true ) ) {
                continue;
            }
            $options[ $type->name ] = $type->labels->singular_name ?? $type->name;
        }

        return $options;
    }
}
