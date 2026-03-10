<?php
/**
 * Template: Shortcode [jpkcom_postfilter_filter]
 *
 * Delegates rendering to the appropriate filter layout partial.
 * The layout partial receives data-jpkpf-post-type and data-jpkpf-base-url
 * on its <nav> element so that post-filter.js can pair it with the
 * matching [jpkcom_postfilter_list] results zone via post type.
 *
 * Available variables:
 * @var string $layout         Filter layout: bar | sidebar | dropdown.
 * @var array<array{slug:string,label:string,taxonomy:string,terms:WP_Term[]}> $filter_groups
 * @var array<string,string[]> $active_filters  Active taxonomy => term slugs.
 * @var string $base_url       Archive base URL.
 * @var string $post_type      Current post type slug.
 * @var bool   $show_reset     Whether to show the reset button.
 * @var string $extra_class    Additional CSS classes.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

$layout = in_array( $layout ?? '', [ 'bar', 'sidebar', 'dropdown', 'columns' ], true )
    ? $layout
    : 'bar';

jpkcom_postfilter_get_template_part(
    'partials/filter/filter-' . $layout,
    '',
    [
        'filter_groups'  => $filter_groups  ?? [],
        'active_filters' => $active_filters ?? [],
        'base_url'       => $base_url       ?? '',
        'post_type'      => $post_type      ?? 'post',
        'show_reset'     => $show_reset     ?? false,
        'reset_mode'     => $reset_mode     ?? 'on_selection',
        'extra_class'    => $extra_class    ?? '',
    ]
);
