<?php
/**
 * Template: Post List – Theme Default Layout
 *
 * Renders posts using the active theme's content template parts.
 * Falls back to a basic article structure when no theme template is found.
 *
 * Theme template lookup order:
 *   1. template-parts/content-{post_type}.php
 *   2. template-parts/content.php
 *   3. content-{post_type}.php
 *   4. content.php
 *   5. Built-in fallback (article with thumbnail, title, excerpt, date)
 *
 * Developers can override the per-post rendering via filter:
 *   add_filter( 'jpkcom_postfilter_theme_template_parts', function( $parts, $post_type ) {
 *       return [ 'my-theme/card', 'my-theme/card-fallback' ];
 *   }, 10, 2 );
 *
 * Available variables:
 * @var WP_Query $query      The posts query object.
 * @var string   $post_type  Current post type.
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
<div class="jpkpf-list-theme<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
     data-jpkpf-results
     data-jpkpf-post-type="<?php echo esc_attr( $post_type ?? 'post' ); ?>"
     aria-live="polite"
     aria-atomic="true">

    <?php if ( isset( $query ) && $query->have_posts() ) : ?>

        <?php while ( $query->have_posts() ) : $query->the_post(); ?>
            <?php
            $current_type = get_post_type();

            /**
             * Filter the template part candidates for the theme layout.
             *
             * @since 1.0.0
             *
             * @param string[] $parts     Template part paths to try (relative to theme root, without .php).
             * @param string   $post_type The current post type slug.
             */
            $parts = (array) apply_filters( 'jpkcom_postfilter_theme_template_parts', [
                'template-parts/content-' . $current_type,
                'template-parts/content',
                'content-' . $current_type,
                'content',
            ], $current_type );

            // Try each candidate – locate_template checks child theme first, then parent theme.
            $located = locate_template(
                array_map( static fn( string $p ): string => ltrim( $p, '/' ) . '.php', $parts )
            );

            if ( $located ) {
                load_template( $located, false );
            } else {
                // Fallback: render a basic article so the user always sees something.
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'jpkpf-theme-fallback' ); ?>>

                    <?php if ( has_post_thumbnail() ) : ?>
                        <a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                            <?php the_post_thumbnail( 'medium', [ 'loading' => 'lazy' ] ); ?>
                        </a>
                    <?php endif; ?>

                    <h2>
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </h2>

                    <p><?php echo esc_html( wp_strip_all_tags( get_the_excerpt() ) ); ?></p>

                    <time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d' ) ); ?>">
                        <?php echo esc_html( get_the_date() ); ?>
                    </time>

                </article>
                <?php
            }
            ?>
        <?php endwhile; ?>

        <?php wp_reset_postdata(); ?>

    <?php else : ?>
        <p class="jpkpf-no-results"><?php esc_html_e( 'No posts found.', 'jpkcom-post-filter' ); ?></p>
    <?php endif; ?>

</div>
