<?php
/**
 * Template Loader
 *
 * Handles template loading with a full override hierarchy:
 * 1. Child Theme:  /themes/child/jpkcom-post-filter/{template}
 * 2. Parent Theme: /themes/parent/jpkcom-post-filter/{template}
 * 3. MU Plugin:    /mu-plugins/jpkcom-post-filter-overrides/templates/{template}
 * 4. Plugin:       /plugins/jpkcom-post-filter/debug-templates/{template}  (when WP_DEBUG)
 *                  /plugins/jpkcom-post-filter/templates/{template}         (production)
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_postfilter_locate_template' ) ) {
    /**
     * Locate a template file with override support
     *
     * @since 1.0.0
     *
     * @param string $template_name Template filename relative to the templates/ directory
     *                              (e.g., 'partials/filter/filter-bar.php').
     * @return string|false Absolute path to the located template, or false if not found.
     */
    function jpkcom_postfilter_locate_template( string $template_name ): string|false {

        $search_paths = [
            trailingslashit( get_stylesheet_directory() ) . 'jpkcom-post-filter/' . $template_name,
            trailingslashit( get_template_directory() ) . 'jpkcom-post-filter/' . $template_name,
            trailingslashit( WPMU_PLUGIN_DIR ) . 'jpkcom-post-filter-overrides/templates/' . $template_name,
        ];

        /**
         * Filter the template search paths before the plugin's own directories are appended.
         *
         * @since 1.0.0
         *
         * @param string[] $search_paths  Array of paths to search (theme + MU plugin overrides).
         * @param string   $template_name Template filename being searched.
         */
        $search_paths = apply_filters( 'jpkcom_postfilter_template_paths', $search_paths, $template_name );

        // Search theme and MU plugin overrides first
        foreach ( $search_paths as $path ) {
            if ( file_exists( filename: $path ) ) {
                return $path;
            }
        }

        // Fallback to plugin templates (debug-templates/ when WP_DEBUG is true)
        $folder = JPKCOM_POSTFILTER_DEBUG ? 'debug-templates/' : 'templates/';
        $plugin_template = JPKCOM_POSTFILTER_PLUGIN_PATH . $folder . $template_name;

        if ( file_exists( filename: $plugin_template ) ) {
            return $plugin_template;
        }

        // Last resort: try the other folder (e.g., debug-templates/ might not have all files)
        $fallback_folder   = JPKCOM_POSTFILTER_DEBUG ? 'templates/' : 'debug-templates/';
        $fallback_template = JPKCOM_POSTFILTER_PLUGIN_PATH . $fallback_folder . $template_name;

        if ( file_exists( filename: $fallback_template ) ) {
            return $fallback_template;
        }

        jpkcom_postfilter_debug_log( "Template not found: {$template_name}" );
        return false;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_template_part' ) ) {
    /**
     * Load a template partial with full override support
     *
     * Similar to WordPress get_template_part() but uses the plugin's
     * template hierarchy. Variables in $args are extracted into the template scope.
     *
     * Usage examples:
     *   jpkcom_postfilter_get_template_part( 'partials/filter/filter-bar' );
     *   jpkcom_postfilter_get_template_part( 'partials/list/list-cards', '', ['query' => $q] );
     *   jpkcom_postfilter_get_template_part( 'partials/filter/filter-bar', 'sidebar', $args );
     *
     * @since 1.0.0
     *
     * @param string              $slug Template slug (path without .php extension).
     * @param string              $name Optional template name/variation (appended as -{name}).
     * @param array<string, mixed> $args Variables to extract into the template scope.
     * @return void
     */
    function jpkcom_postfilter_get_template_part( string $slug, string $name = '', array $args = [] ): void {
        $template_name = $slug . ( $name !== '' ? '-' . $name : '' ) . '.php';
        $template_path = jpkcom_postfilter_locate_template( template_name: $template_name );

        if ( $template_path === false ) {
            jpkcom_postfilter_debug_log( "get_template_part: not found: {$template_name}" );
            return;
        }

        /**
         * Fires before a template partial is loaded.
         *
         * @since 1.0.0
         *
         * @param string              $template_path Absolute path to the template.
         * @param string              $slug          Template slug.
         * @param string              $name          Template name/variation.
         * @param array<string, mixed> $args         Template variables.
         */
        do_action( 'jpkcom_postfilter_before_template_part', $template_path, $slug, $name, $args );

        if ( ! empty( $args ) ) {
            // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
            extract( $args, EXTR_SKIP );
        }

        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $template_path;

        /**
         * Fires after a template partial is loaded.
         *
         * @since 1.0.0
         *
         * @param string              $template_path Absolute path to the template.
         * @param string              $slug          Template slug.
         * @param string              $name          Template name/variation.
         * @param array<string, mixed> $args         Template variables.
         */
        do_action( 'jpkcom_postfilter_after_template_part', $template_path, $slug, $name, $args );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_template_html' ) ) {
    /**
     * Render a template partial and return the HTML as a string
     *
     * Identical to jpkcom_postfilter_get_template_part() but returns output
     * instead of echoing. Useful for shortcodes and AJAX responses.
     *
     * @since 1.0.0
     *
     * @param string              $slug Template slug.
     * @param string              $name Optional template name/variation.
     * @param array<string, mixed> $args Variables to pass to the template.
     * @return string Rendered HTML.
     */
    function jpkcom_postfilter_get_template_html( string $slug, string $name = '', array $args = [] ): string {
        ob_start();
        jpkcom_postfilter_get_template_part( $slug, $name, $args );
        return (string) ob_get_clean();
    }
}


/**
 * Hook into WordPress locate_template() to intercept plugin template lookups
 *
 * Allows plugin templates to be found when themes use get_template_part()
 * with a path that includes 'jpkcom-post-filter/'.
 *
 * @since 1.0.0
 */
add_filter( 'locate_template', static function ( string $template, mixed $template_names ): string {

    if ( ! empty( $template ) ) {
        return $template;
    }

    foreach ( (array) $template_names as $template_name ) {
        if ( is_string( $template_name ) && str_contains( $template_name, 'jpkcom-post-filter/' ) ) {
            $plugin_template = jpkcom_postfilter_locate_template( template_name: $template_name );
            if ( $plugin_template !== false && file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
    }

    return $template;

}, 10, 2 );
