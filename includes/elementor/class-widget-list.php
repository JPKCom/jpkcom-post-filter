<?php
/**
 * Elementor Widget: Post List
 *
 * Renders a filtered post listing.
 * Wraps jpkcom_postfilter_shortcode_list().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Elementor;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Widget_List extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'jpkcom-post-list';
    }

    public function get_title(): string {
        return esc_html__( 'Post List', 'jpkcom-post-filter' );
    }

    public function get_icon(): string {
        return 'eicon-post-list';
    }

    public function get_categories(): array {
        return [ 'jpkcom-post-filter' ];
    }

    public function get_keywords(): array {
        return [ 'posts', 'list', 'cards', 'grid', 'archive', 'jpkcom' ];
    }

    protected function register_controls(): void {

        // -----------------------------------------------------------------
        // Content Section
        // -----------------------------------------------------------------
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'List Settings', 'jpkcom-post-filter' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        // Post Type
        $post_types = $this->get_post_type_options();
        $this->add_control( 'post_type', [
            'label'   => esc_html__( 'Post Type', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'post',
            'options' => $post_types,
        ] );

        // Layout
        $this->add_control( 'layout', [
            'label'   => esc_html__( 'Layout', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '',
            'options' => [
                ''        => esc_html__( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
                'cards'   => esc_html__( 'Cards', 'jpkcom-post-filter' ),
                'rows'    => esc_html__( 'Rows', 'jpkcom-post-filter' ),
                'minimal' => esc_html__( 'Minimal', 'jpkcom-post-filter' ),
                'theme'   => esc_html__( 'Theme', 'jpkcom-post-filter' ),
            ],
        ] );

        // Posts per Page
        $this->add_control( 'limit', [
            'label'   => esc_html__( 'Posts per Page', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 5,
            'min'     => -1,
            'max'     => 100,
            'step'    => 1,
            'description' => esc_html__( '-1 = all posts (no pagination)', 'jpkcom-post-filter' ),
        ] );

        // Order By
        $this->add_control( 'orderby', [
            'label'   => esc_html__( 'Order By', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'date',
            'options' => [
                'date'       => esc_html__( 'Date', 'jpkcom-post-filter' ),
                'title'      => esc_html__( 'Title', 'jpkcom-post-filter' ),
                'menu_order' => esc_html__( 'Menu Order', 'jpkcom-post-filter' ),
                'modified'   => esc_html__( 'Modified Date', 'jpkcom-post-filter' ),
                'rand'       => esc_html__( 'Random', 'jpkcom-post-filter' ),
            ],
        ] );

        // Order
        $this->add_control( 'order', [
            'label'   => esc_html__( 'Order', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'DESC',
            'options' => [
                'DESC' => esc_html__( 'Descending', 'jpkcom-post-filter' ),
                'ASC'  => esc_html__( 'Ascending', 'jpkcom-post-filter' ),
            ],
        ] );

        // Extra CSS Class
        $this->add_control( 'extra_class', [
            'label'   => esc_html__( 'CSS Class', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $atts = [
            'post_type' => $settings['post_type'] ?? 'post',
            'layout'    => $settings['layout'] ?? '',
            'limit'     => (string) ( $settings['limit'] ?? '5' ),
            'orderby'   => $settings['orderby'] ?? 'date',
            'order'     => $settings['order'] ?? 'DESC',
            'class'     => $settings['extra_class'] ?? '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode handles escaping
        echo jpkcom_postfilter_shortcode_list( $atts );
    }

    /**
     * Get available public post types as options
     *
     * @return array<string, string>
     */
    private function get_post_type_options(): array {
        $options = [];
        $types   = get_post_types( [ 'public' => true ], 'objects' );

        $exclude = [ 'attachment', 'elementor_library' ];

        foreach ( $types as $type ) {
            if ( in_array( $type->name, $exclude, true ) ) {
                continue;
            }
            $options[ $type->name ] = $type->labels->singular_name ?? $type->name;
        }

        return $options;
    }
}
