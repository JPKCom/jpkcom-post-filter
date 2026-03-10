<?php
/**
 * Oxygen Element: Post Pagination
 *
 * Renders pagination for the post listing.
 * Wraps jpkcom_postfilter_shortcode_pagination().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.1.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Oxygen;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Element_Pagination extends \OxyEl {

    function name(): string {
        return __( 'Post Pagination', 'jpkcom-post-filter' );
    }

    function slug(): string {
        return 'jpkcom-post-pagination';
    }

    function icon(): string {
        return '';
    }

    function button_place(): string {
        return 'jpkcom-post-filter::section_content';
    }

    function button_priority(): int {
        return 3;
    }

    function tag(): string {
        return 'div';
    }

    function keywords(): string {
        return 'pagination,paging,navigation,pages,jpkcom';
    }

    function controls(): void {

        // Post Type
        $post_types = $this->get_post_type_options();
        $this->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => __( 'Post Type', 'jpkcom-post-filter' ),
            'slug'    => 'jpkpf_post_type',
            'default' => 'post',
            'description' => __( 'Must match the Post List element on the same page.', 'jpkcom-post-filter' ),
        ] )->setValue( $post_types )->rebuildElementOnChange();
    }

    function render( $options, $defaults, $content ): void {

        $atts = [
            'post_type' => $options['jpkpf_post_type'] ?? 'post',
            'class'     => '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo jpkcom_postfilter_shortcode_pagination( $atts );
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
