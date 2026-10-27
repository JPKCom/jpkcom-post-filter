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

// Core's convention: `%_%` in the base is replaced by the format for pages
// 2+ and by nothing for page 1. Before 1.5.0 the base carried `page/%#%/`
// with an empty format, so "1" and "« Previous" on page 2 pointed at
// `…/page/1/` - a duplicate of page 1 linked from every paginated list.
$page_base = trailingslashit( $filter_url ) . '%_%';

// Pages that are nofollow (see jpkcom_postfilter_robots_directives()) pass no
// link equity into further combinations. Since 1.7.0 a combination can be
// index, nofollow, so this follows the follow decision, not the noindex.
$nofollow = function_exists( 'jpkcom_postfilter_should_nofollow' ) && jpkcom_postfilter_should_nofollow();
?>
<nav class="jpkpf-pagination<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
     data-jpkpf-pagination
     <?php if ( ! empty( $post_type ) ) : ?>data-jpkpf-post-type="<?php echo esc_attr( $post_type ); ?>"<?php endif; ?>
     aria-label="<?php esc_attr_e( 'Page navigation', 'jpkcom-post-filter' ); ?>">
    <?php
    $links = (string) paginate_links( [
        'base'      => $page_base,
        'format'    => 'page/%#%/',
        'current'   => $current,
        'total'     => $max_pages,
        'type'      => 'list',
        'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'jpkcom-post-filter' ),
        'next_text' => esc_html__( 'Next', 'jpkcom-post-filter' ) . ' &raquo;',
    ] );

    if ( $nofollow ) {
        $links = str_replace( '<a ', '<a rel="nofollow" ', $links );
    }

    echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() output
    ?>
</nav>
