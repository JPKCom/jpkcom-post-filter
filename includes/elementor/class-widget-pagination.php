<?php
/**
 * Elementor Widget: Post Pagination
 *
 * Renders pagination for the post listing.
 * Wraps jpkcom_postfilter_shortcode_pagination().
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

namespace JPKComPostFilter\Elementor;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

class Widget_Pagination extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'jpkcom-post-pagination';
    }

    public function get_title(): string {
        return esc_html__( 'Post Pagination', 'jpkcom-post-filter' );
    }

    public function get_icon(): string {
        return 'eicon-post-navigation';
    }

    public function get_categories(): array {
        return [ 'jpkcom-post-filter' ];
    }

    public function get_keywords(): array {
        return [ 'pagination', 'paging', 'navigation', 'pages', 'jpkcom' ];
    }

    protected function register_controls(): void {

        // -----------------------------------------------------------------
        // Content Section
        // -----------------------------------------------------------------
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Pagination Settings', 'jpkcom-post-filter' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        // Post Type
        $post_types = $this->get_post_type_options();
        $this->add_control( 'post_type', [
            'label'       => esc_html__( 'Post Type', 'jpkcom-post-filter' ),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'post',
            'options'     => $post_types,
            'description' => esc_html__( 'Must match the Post List widget on the same page.', 'jpkcom-post-filter' ),
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
            'class'     => $settings['extra_class'] ?? '',
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode handles escaping
        echo jpkcom_postfilter_shortcode_pagination( $atts );
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
