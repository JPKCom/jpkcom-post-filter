<?php
/**
 * Template: Filter Dropdown Layout
 *
 * Available variables:
 * @var array<array{slug: string, label: string, taxonomy: string, terms: WP_Term[]}> $filter_groups
 * @var array<string, string[]> $active_filters  Active taxonomy => term slugs.
 * @var string                  $base_url        Archive base URL.
 * @var string                  $post_type       Current post type.
 * @var bool                    $show_reset      Whether to show reset button.
 * @var string                  $reset_mode      'always' | 'on_selection' | 'never'.
 * @var string                  $extra_class     Additional CSS classes.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}
?>
<nav class="jpkpf-filter-dropdown<?php echo ! empty( $extra_class ) ? ' ' . esc_attr( $extra_class ) : ''; ?>"
     data-jpkpf-filter-bar
     data-jpkpf-post-type="<?php echo esc_attr( $post_type ); ?>"
     data-jpkpf-base-url="<?php echo esc_url( $base_url ); ?>"
     aria-label="<?php esc_attr_e( 'Content filter', 'jpkcom-post-filter' ); ?>">

    <?php
    if ( ! empty( $filter_groups ) ) :
        foreach ( $filter_groups as $group ) :
            if ( empty( $group['terms'] ) ) {
                continue;
            }

            $group_id     = 'jpkpf-dp-' . sanitize_html_class( $group['taxonomy'] );
            $term_slugs   = array_column( $group['terms'], 'slug' );
            $active_count = isset( $active_filters[ $group['taxonomy'] ] )
                ? count( array_intersect( $term_slugs, $active_filters[ $group['taxonomy'] ] ) )
                : 0;
            ?>
            <div class="jpkpf-filter-dropdown-group" data-filter-taxonomy="<?php echo esc_attr( $group['taxonomy'] ); ?>">

                <button class="jpkpf-filter-dropdown-trigger<?php echo $active_count > 0 ? ' is-active' : ''; ?>"
                        type="button"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $group_id ); ?>">
                    <?php echo esc_html( $group['label'] ); ?>
                    <?php if ( $active_count > 0 ) : ?>
                        <span class="jpkpf-filter-count"
                              aria-label="<?php echo esc_attr(
                                  /* translators: %d: number of active filters */
                                  sprintf( _n( '%d active filter', '%d active filters', $active_count, 'jpkcom-post-filter' ), $active_count )
                              ); ?>">
                            <?php echo (int) $active_count; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div class="jpkpf-filter-dropdown-panel"
                     id="<?php echo esc_attr( $group_id ); ?>"
                     role="region"
                     aria-label="<?php echo esc_attr( $group['label'] ); ?>">

                    <?php foreach ( $group['terms'] as $term ) :
                        $is_active     = isset( $active_filters[ $group['taxonomy'] ] )
                            && in_array( $term->slug, $active_filters[ $group['taxonomy'] ], true );
                        $toggled_slugs = $is_active
                            ? array_values( array_diff( $active_filters[ $group['taxonomy'] ] ?? [], [ $term->slug ] ) )
                            : array_merge( $active_filters[ $group['taxonomy'] ] ?? [], [ $term->slug ] );
                        $toggled_filters = array_merge( $active_filters, [ $group['taxonomy'] => $toggled_slugs ] );
                        $filter_url      = jpkcom_postfilter_get_filter_url( $base_url, $toggled_filters );
                        ?>
                        <a href="<?php echo esc_url( $filter_url ); ?>"
                           class="jpkpf-filter-btn<?php echo $is_active ? ' is-active' : ''; ?>"
                           aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
                           data-filter-term="<?php echo esc_attr( $term->slug ); ?>"
                           data-filter-taxonomy="<?php echo esc_attr( $group['taxonomy'] ); ?>">
                            <?php echo esc_html( $term->name ); ?>
                            <span class="jpkpf-sr-only">(<?php echo (int) $term->count; ?>)</span>
                        </a>
                    <?php endforeach; ?>

                </div>
            </div>
            <?php
        endforeach;
    endif;

    if ( ! empty( $show_reset ) ) :
        $reset_mode = $reset_mode ?? 'on_selection';
        $hide_initially = ( $reset_mode === 'on_selection' ) && empty( $active_filters );
        ?>
        <a href="<?php echo esc_url( $base_url ); ?>"
           class="jpkpf-filter-btn jpkpf-filter-reset"
           data-jpkpf-reset-mode="<?php echo esc_attr( $reset_mode ); ?>"
           rel="nofollow"<?php echo $hide_initially ? ' hidden' : ''; ?>>
            <?php esc_html_e( 'Reset filters', 'jpkcom-post-filter' ); ?>
        </a>
        <?php
    endif;
    ?>

    <div class="jpkpf-live-region jpkpf-sr-only"
         aria-live="polite"
         aria-atomic="true"
         data-jpkpf-live-region></div>
</nav>
