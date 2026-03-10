<?php
/**
 * Oxygen Element: Post List
 *
 * Renders a filtered post listing.
 * Wraps jpkcom_postfilter_shortcode_list().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.1.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Oxygen;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Element_List extends \OxyEl {

    function name(): string {
        return __( 'Post List', 'jpkcom-post-filter' );
    }

    function slug(): string {
        return 'jpkcom-post-list';
    }

    function icon(): string {
        return '';
    }

    function button_place(): string {
        return 'jpkcom-post-filter::section_content';
    }

    function button_priority(): int {
        return 2;
    }

    function tag(): string {
        return 'div';
    }

    function keywords(): string {
        return 'posts,list,cards,grid,archive,jpkcom';
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
            ''        => __( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
            'cards'   => __( 'Cards', 'jpkcom-post-filter' ),
            'rows'    => __( 'Rows', 'jpkcom-post-filter' ),
            'minimal' => __( 'Minimal', 'jpkcom-post-filter' ),
            'theme'   => __( 'Theme', 'jpkcom-post-filter' ),
        ] )->rebuildElementOnChange();

        // Posts per Page
        $this->addOptionControl( [
            'type'    => 'textfield',
            'name'    => __( 'Posts per Page (-1 = all)', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_limit',
            'default' => '5',
        ] )->rebuildElementOnChange();

        // Order By
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Order By', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_orderby',
            'default' => 'date',
        ] )->setValue( [
            'date'       => __( 'Date', 'jpkcom-post-filter' ),
            'title'      => __( 'Title', 'jpkcom-post-filter' ),
            'menu_order' => __( 'Menu Order', 'jpkcom-post-filter' ),
            'modified'   => __( 'Modified Date', 'jpkcom-post-filter' ),
            'rand'       => __( 'Random', 'jpkcom-post-filter' ),
        ] )->rebuildElementOnChange();

        // Order
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Order', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_order',
            'default' => 'DESC',
        ] )->setValue( [
            'DESC' => __( 'Descending', 'jpkcom-post-filter' ),
            'ASC'  => __( 'Ascending', 'jpkcom-post-filter' ),
        ] )->rebuildElementOnChange();
    }

    function render( $options, $defaults, $content ): void {

        $atts = [
            'post_type' => $options['jpkpf_post_type'] ?? 'post',
            'layout'    => $options['jpkpf_layout'] ?? '',
            'limit'     => (string) ( $options['jpkpf_limit'] ?? '5' ),
            'orderby'   => $options['jpkpf_orderby'] ?? 'date',
            'order'     => $options['jpkpf_order'] ?? 'DESC',
            'class'     => '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo jpkcom_postfilter_shortcode_list( $atts );
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
