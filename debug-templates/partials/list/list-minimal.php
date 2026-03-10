<?php
/**
 * Template: Post List – Minimal Layout
 *
 * Available variables:
 * @var WP_Query $query       The posts query object.
 * @var string   $post_type   Current post type.
 * @var string   $extra_class Additional CSS classes.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}
?>
<ul class="jpkpf-list-minimal<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
    data-jpkpf-results
    data-jpkpf-post-type="<?php echo esc_attr( $post_type ?? 'post' ); ?>"
    aria-live="polite"
    aria-atomic="true">

    <?php if ( isset( $query ) && $query->have_posts() ) : ?>

        <?php while ( $query->have_posts() ) : $query->the_post(); ?>
            <li class="jpkpf-minimal-item" data-jpkpf-post>
                <h2 class="jpkpf-minimal-item__title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h2>
                <time class="jpkpf-minimal-item__date"
                      datetime="<?php echo esc_attr( get_the_date( 'Y-m-d' ) ); ?>">
                    <?php echo esc_html( get_the_date() ); ?>
                </time>
            </li>
        <?php endwhile; ?>

        <?php wp_reset_postdata(); ?>

    <?php else : ?>
        <li class="jpkpf-no-results"><?php esc_html_e( 'No posts found.', 'jpkcom-post-filter' ); ?></li>
    <?php endif; ?>

</ul>
