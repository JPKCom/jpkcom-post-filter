<?php
/**
 * Template: Pagination
 *
 * Available variables:
 * @var WP_Query             $query          The posts query object (for max_num_pages).
 * @var string               $base_url       Archive base URL (without filter path).
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

$max_pages = isset( $query ) ? (int) $query->max_num_pages : 0;
$current   = max( 1, get_query_var( 'paged' ) );

if ( $max_pages <= 1 ) {
    return;
}

// Build SEO-friendly pagination base URL including active filter path.
$filter_url = ( ! empty( $active_filters ) && function_exists( 'jpkcom_postfilter_get_filter_url' ) )
    ? jpkcom_postfilter_get_filter_url( $base_url, $active_filters )
    : trailingslashit( $base_url );

$page_base = $filter_url . 'page/%#%/';
?>
<nav class="jpkpf-pagination<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
     data-jpkpf-pagination
     <?php if ( ! empty( $post_type ) ) : ?>data-jpkpf-post-type="<?php echo esc_attr( $post_type ); ?>"<?php endif; ?>
     aria-label="<?php esc_attr_e( 'Page navigation', 'jpkcom-post-filter' ); ?>">
    <?php
    echo paginate_links( [
        'base'      => $page_base,
        'format'    => '',
        'current'   => $current,
        'total'     => $max_pages,
        'type'      => 'list',
        'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'jpkcom-post-filter' ),
        'next_text' => esc_html__( 'Next', 'jpkcom-post-filter' ) . ' &raquo;',
    ] );
    ?>
</nav>
