<?php
/**
 * Elementor Widget: Post Filter
 *
 * Renders the filter/facets UI for a post type.
 * Wraps jpkcom_postfilter_shortcode_filter().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Elementor;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Widget_Filter extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'jpkcom-post-filter';
    }

    public function get_title(): string {
        return esc_html__( 'Post Filter', 'jpkcom-post-filter' );
    }

    public function get_icon(): string {
        return 'eicon-filter';
    }

    public function get_categories(): array {
        return [ 'jpkcom-post-filter' ];
    }

    public function get_keywords(): array {
        return [ 'filter', 'facets', 'taxonomy', 'category', 'tag', 'jpkcom' ];
    }

    protected function register_controls(): void {

        // -----------------------------------------------------------------
        // Content Section
        // -----------------------------------------------------------------
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Filter Settings', 'jpkcom-post-filter' ),
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
                ''         => esc_html__( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
                'bar'      => esc_html__( 'Bar', 'jpkcom-post-filter' ),
                'sidebar'  => esc_html__( 'Sidebar', 'jpkcom-post-filter' ),
                'dropdown' => esc_html__( 'Dropdown', 'jpkcom-post-filter' ),
                'columns'  => esc_html__( 'Columns', 'jpkcom-post-filter' ),
            ],
        ] );

        // Filter Groups (CSV)
        $this->add_control( 'groups', [
            'label'       => esc_html__( 'Filter Groups', 'jpkcom-post-filter' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => esc_html__( 'All groups', 'jpkcom-post-filter' ),
            'description' => esc_html__( 'Comma-separated group slugs. Leave empty for all.', 'jpkcom-post-filter' ),
        ] );

        // Reset Button
        $this->add_control( 'reset', [
            'label'   => esc_html__( 'Reset Button', 'jpkcom-post-filter' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'true',
            'options' => [
                'true'   => esc_html__( 'Default (Backend Setting)', 'jpkcom-post-filter' ),
                'always' => esc_html__( 'Always', 'jpkcom-post-filter' ),
                'false'  => esc_html__( 'Never', 'jpkcom-post-filter' ),
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
            'groups'    => $settings['groups'] ?? '',
            'reset'     => $settings['reset'] ?? 'true',
            'class'     => $settings['extra_class'] ?? '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode handles escaping
        echo jpkcom_postfilter_shortcode_filter( $atts );
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
