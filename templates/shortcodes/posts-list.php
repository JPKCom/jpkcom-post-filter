<?php
/**
 * Template: Shortcode [jpkcom_postfilter_list]
 *
 * Delegates rendering to the appropriate list layout partial.
 * The layout partial carries data-jpkpf-results and data-jpkpf-post-type so
 * that post-filter.js can pair it with the [jpkcom_postfilter_filter] bar.
 *
 * Available variables:
 * @var WP_Query $query       The posts query object.
 * @var string   $layout      List layout: cards | rows | minimal.
 * @var string   $post_type   Current post type slug.
 * @var string   $extra_class Additional CSS classes.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

$layout = in_array( $layout ?? '', [ 'cards', 'rows', 'minimal', 'theme' ], true )
    ? $layout
    : 'cards';

jpkcom_postfilter_get_template_part(
    'partials/list/list-' . $layout,
    '',
    [
        'query'       => $query      ?? null,
        'post_type'   => $post_type  ?? 'post',
        'extra_class' => $extra_class ?? '',
    ]
);
