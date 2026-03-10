<?php
/**
 * Template: Shortcode [jpkcom_postfilter_pagination]
 *
 * Delegates rendering to the shared pagination partial.
 *
 * Available variables:
 * @var WP_Query             $query          Query from [jpkcom_postfilter_list].
 * @var string               $base_url       Archive base URL.
 * @var array<string,string[]> $active_filters Active taxonomy => term slugs.
 * @var string               $extra_class    Additional CSS classes.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

jpkcom_postfilter_get_template_part(
    'partials/pagination/pagination',
    '',
    [
        'query'          => $query          ?? null,
        'base_url'       => $base_url       ?? '',
        'active_filters' => $active_filters ?? [],
        'post_type'      => $post_type      ?? 'post',
        'extra_class'    => $extra_class    ?? '',
    ]
);
