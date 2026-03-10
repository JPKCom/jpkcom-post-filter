<?php
/**
 * Template: Post List – Rows Layout
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
<div class="jpkpf-list-rows<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
     data-jpkpf-results
     data-jpkpf-post-type="<?php echo esc_attr( $post_type ?? 'post' ); ?>"
     aria-live="polite"
     aria-atomic="true">

    <?php if ( isset( $query ) && $query->have_posts() ) : ?>

        <?php while ( $query->have_posts() ) : $query->the_post(); ?>
            <article class="jpkpf-row" id="post-<?php the_ID(); ?>" data-jpkpf-post>

                <?php if ( has_post_thumbnail() ) : ?>
                    <?php the_post_thumbnail(
                        [ 64, 64 ],
                        [
                            'class'   => 'jpkpf-row__thumbnail',
                            'loading' => 'lazy',
                            'alt'     => esc_attr( get_the_title() ),
                        ]
                    ); ?>
                <?php endif; ?>

                <div class="jpkpf-row__content">
                    <h2 class="jpkpf-row__title">
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </h2>
                    <?php if ( has_excerpt() ) : ?>
                        <p class="jpkpf-row__meta"><?php echo wp_kses_post( get_the_excerpt() ); ?></p>
                    <?php endif; ?>
                </div>

                <time class="jpkpf-row__date"
                      datetime="<?php echo esc_attr( get_the_date( 'Y-m-d' ) ); ?>">
                    <?php echo esc_html( get_the_date() ); ?>
                </time>

            </article>
        <?php endwhile; ?>

        <?php wp_reset_postdata(); ?>

    <?php else : ?>
        <p class="jpkpf-no-results"><?php esc_html_e( 'No posts found.', 'jpkcom-post-filter' ); ?></p>
    <?php endif; ?>

</div>
