<?php
/**
 * Admin Pages
 *
 * Registers the "Post Filter" top-level menu and all sub-pages:
 *   1. General Settings
 *   2. Filter Groups / Taxonomies
 *   3. Layout & Design
 *   4. Shortcode Configurator
 *   5. Cache & Performance
 *   6. Import / Export
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


// ---------------------------------------------------------------------------
// Menu registration
// ---------------------------------------------------------------------------

/**
 * Register admin menu pages
 *
 * @since 1.0.0
 */
add_action( 'admin_menu', static function (): void {

    // Top-level menu
    add_menu_page(
        page_title: __( 'Post Filter', 'jpkcom-post-filter' ),
        menu_title: __( 'Post Filter', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-post-filter',
        callback: 'jpkcom_postfilter_page_general',
        icon_url: 'dashicons-filter',
        position: 80
    );

    // Sub: General (renamed to avoid duplicate with top-level)
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'General Settings', 'jpkcom-post-filter' ),
        menu_title: __( 'General', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-post-filter',
        callback: 'jpkcom_postfilter_page_general'
    );

    // Sub: Filter Groups
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Filter Groups', 'jpkcom-post-filter' ),
        menu_title: __( 'Filter Groups', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-filter-groups',
        callback: 'jpkcom_postfilter_page_filter_groups'
    );

    // Sub: Layout & Design
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Layout & Design', 'jpkcom-post-filter' ),
        menu_title: __( 'Layout & Design', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-layout',
        callback: 'jpkcom_postfilter_page_layout'
    );

    // Sub: Shortcode Configurator
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Shortcode Configurator', 'jpkcom-post-filter' ),
        menu_title: __( 'Shortcodes', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-shortcodes',
        callback: 'jpkcom_postfilter_page_shortcodes'
    );

    // Sub: Cache & Performance
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Cache & Performance', 'jpkcom-post-filter' ),
        menu_title: __( 'Cache', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-cache',
        callback: 'jpkcom_postfilter_page_cache'
    );

    // Sub: Import / Export
    add_submenu_page(
        parent_slug: 'jpkcom-post-filter',
        page_title: __( 'Import / Export', 'jpkcom-post-filter' ),
        menu_title: __( 'Import / Export', 'jpkcom-post-filter' ),
        capability: 'manage_options',
        menu_slug: 'jpkcom-postfilter-import-export',
        callback: 'jpkcom_postfilter_page_import_export'
    );

}, 20 );


// ---------------------------------------------------------------------------
// Settings sections and fields – registered in admin_init (see settings.php)
// ---------------------------------------------------------------------------

/**
 * Handle settings JSON export – must run before any output is sent.
 *
 * @since 1.0.0
 */
add_action( 'admin_init', static function (): void {

    if ( ! isset( $_POST['jpkcom_postfilter_export'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'jpkcom-post-filter' ) );
    }

    check_admin_referer( 'jpkcom_postfilter_export' );

    $defaults    = jpkcom_postfilter_default_settings();
    $export_data = [];
    foreach ( [ 'general', 'layout', 'cache', 'filter_groups' ] as $group ) {
        $saved = jpkcom_postfilter_settings_get_group( $group );
        // Fall back to defaults when a group was never explicitly saved.
        $export_data[ $group ] = ! empty( $saved ) ? $saved : ( $defaults[ $group ] ?? [] );
    }

    $json = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="jpkcom-post-filter-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
    header( 'Content-Length: ' . strlen( (string) $json ) );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $json;
    exit;

}, 1 ); // Priority 1: before Settings API registration so headers are still clean.


add_action( 'admin_init', static function (): void {

    // === General Settings ===
    add_settings_section(
        id: 'jpkcom_postfilter_general_post_types',
        title: __( 'Post Types', 'jpkcom-post-filter' ),
        callback: static function (): void {
            echo '<p>' . esc_html__( 'Select which post types the filter plugin should be active for.', 'jpkcom-post-filter' ) . '</p>';
        },
        page: 'jpkcom-post-filter'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_enabled_post_types',
        title: __( 'Enabled Post Types', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_enabled_post_types',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_post_types'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_auto_inject',
        title: __( 'Auto-Inject Filter', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_auto_inject',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_post_types'
    );

    add_settings_section(
        id: 'jpkcom_postfilter_general_advanced',
        title: __( 'Advanced', 'jpkcom-post-filter' ),
        callback: static function (): void {
            echo '<p>' . esc_html__( 'Advanced configuration options.', 'jpkcom-post-filter' ) . '</p>';
        },
        page: 'jpkcom-post-filter'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_max_filter_combos',
        title: __( 'Max Filter Combinations', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_max_filter_combos',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_advanced'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_max_filters_per_group',
        title: __( 'Max. Filters per Group', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_max_filters_per_group',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_advanced'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_url_endpoint',
        title: __( 'URL Endpoint', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_url_endpoint',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_advanced'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_endpoint_empty_action',
        title: __( 'Bare Endpoint Behaviour', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_endpoint_empty_action',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_advanced'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_debug_mode',
        title: __( 'Debug Mode', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_debug_mode',
        page: 'jpkcom-post-filter',
        section: 'jpkcom_postfilter_general_advanced'
    );

    // Layout page uses custom tab UI – no add_settings_section/field needed here.
    // Sanitization is still handled via register_setting() in settings.php.

    // === Cache Settings ===
    add_settings_section(
        id: 'jpkcom_postfilter_cache_main',
        title: __( 'Cache Layers', 'jpkcom-post-filter' ),
        callback: static function (): void {
            echo '<p>' . esc_html__( 'Enable or disable individual cache layers.', 'jpkcom-post-filter' ) . '</p>';
        },
        page: 'jpkcom-postfilter-cache'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_object_cache_enabled',
        title: __( 'Object Cache', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_object_cache_enabled',
        page: 'jpkcom-postfilter-cache',
        section: 'jpkcom_postfilter_cache_main'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_transient_cache_enabled',
        title: __( 'Transient Cache', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_transient_cache_enabled',
        page: 'jpkcom-postfilter-cache',
        section: 'jpkcom_postfilter_cache_main'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_settings_cache_enabled',
        title: __( 'Settings File Cache', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_settings_cache_enabled',
        page: 'jpkcom-postfilter-cache',
        section: 'jpkcom_postfilter_cache_main'
    );

    add_settings_field(
        id: 'jpkcom_postfilter_cache_ttl',
        title: __( 'Cache TTL (seconds)', 'jpkcom-post-filter' ),
        callback: 'jpkcom_postfilter_field_cache_ttl',
        page: 'jpkcom-postfilter-cache',
        section: 'jpkcom_postfilter_cache_main'
    );

}, 20 );


// ---------------------------------------------------------------------------
// Field rendering callbacks – General
// ---------------------------------------------------------------------------

/**
 * Render: Enabled Post Types checkboxes
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_enabled_post_types(): void {
    $settings    = jpkcom_postfilter_settings_get_group( 'general' );
    $enabled     = $settings['enabled_post_types'] ?? [ 'post' ];
    $post_types  = get_post_types( [ 'public' => true ], 'objects' );
    ?>
    <fieldset>
        <legend class="screen-reader-text"><?php esc_html_e( 'Enabled Post Types', 'jpkcom-post-filter' ); ?></legend>
        <?php foreach ( $post_types as $pt ) : ?>
            <label style="display: block; margin-bottom: 4px;">
                <input
                    type="checkbox"
                    name="jpkcom_postfilter_general[enabled_post_types][]"
                    value="<?php echo esc_attr( $pt->name ); ?>"
                    <?php checked( in_array( $pt->name, $enabled, true ) ); ?>
                >
                <?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?>
                <code style="font-size: 11px; color: #666;">(<?php echo esc_html( $pt->name ); ?>)</code>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php
}

/**
 * Render: Auto-Inject checkboxes
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_auto_inject(): void {
    $settings   = jpkcom_postfilter_settings_get_group( 'general' );
    $enabled    = $settings['enabled_post_types'] ?? [ 'post' ];
    $auto_inject = $settings['auto_inject'] ?? [ 'post' ];
    $post_types  = get_post_types( [ 'public' => true ], 'objects' );
    ?>
    <fieldset>
        <legend class="screen-reader-text"><?php esc_html_e( 'Auto-inject filter into archive pages', 'jpkcom-post-filter' ); ?></legend>
        <?php foreach ( $post_types as $pt ) : ?>
            <?php if ( ! in_array( $pt->name, $enabled, true ) ) continue; ?>
            <label style="display: block; margin-bottom: 4px;">
                <input
                    type="checkbox"
                    name="jpkcom_postfilter_general[auto_inject][]"
                    value="<?php echo esc_attr( $pt->name ); ?>"
                    <?php checked( in_array( $pt->name, $auto_inject, true ) ); ?>
                >
                <?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?>
            </label>
        <?php endforeach; ?>
        <p class="description"><?php esc_html_e( 'Automatically inject the filter bar into archive/blog pages for selected post types (no shortcode needed).', 'jpkcom-post-filter' ); ?></p>
    </fieldset>
    <?php
}

/**
 * Render: Max Filter Combinations number field
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_max_filter_combos(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'general' );
    $value    = $settings['max_filter_combos'] ?? 3;
    ?>
    <input
        type="number"
        id="jpkcom_postfilter_max_filter_combos"
        name="jpkcom_postfilter_general[max_filter_combos]"
        value="<?php echo esc_attr( (string) $value ); ?>"
        min="1"
        max="10"
        class="small-text"
    >
    <p class="description"><?php esc_html_e( 'Maximum number of active filter groups at once. Higher values increase URL complexity.', 'jpkcom-post-filter' ); ?></p>
    <?php
}

/**
 * Render: Max. Filters per Group number field
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_max_filters_per_group(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'general' );
    $value    = $settings['max_filters_per_group'] ?? 3;
    ?>
    <input
        type="number"
        id="jpkcom_postfilter_max_filters_per_group"
        name="jpkcom_postfilter_general[max_filters_per_group]"
        value="<?php echo esc_attr( (string) $value ); ?>"
        min="0"
        max="20"
        class="small-text"
    >
    <p class="description">
        <?php esc_html_e( 'Maximum number of terms that can be selected within a single filter group. When the limit is reached, all other terms in that group are visually disabled until a selection is removed. Set to 0 for no limit.', 'jpkcom-post-filter' ); ?>
    </p>
    <?php
}

/**
 * Render: URL Endpoint text field
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_url_endpoint(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'general' );
    $value    = $settings['url_endpoint'] ?? JPKCOM_POSTFILTER_URL_ENDPOINT;
    ?>
    <input
        type="text"
        id="jpkcom_postfilter_url_endpoint"
        name="jpkcom_postfilter_general[url_endpoint]"
        value="<?php echo esc_attr( $value ); ?>"
        class="regular-text"
        pattern="[a-z0-9\-]+"
    >
    <p class="description">
        <?php
        printf(
            /* translators: 1: example filter URL */
            esc_html__( 'The URL segment used for filter URLs (default: "filter"). Example: /blog/%s/category/tag/', 'jpkcom-post-filter' ),
            '<code>' . esc_html( $value ) . '</code>'
        );
        ?>
        <?php esc_html_e( 'After changing this value, visit Settings → Permalinks to flush rewrite rules.', 'jpkcom-post-filter' ); ?>
    </p>
    <?php
}

/**
 * Render: Bare Endpoint Behaviour select + optional redirect input
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_endpoint_empty_action(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'general' );
    $action   = $settings['endpoint_empty_action'] ?? '404';
    $redirect = $settings['endpoint_empty_redirect'] ?? '';

    $options = [
        '404'    => __( 'Error 404', 'jpkcom-post-filter' ),
        'home'   => __( 'Redirect to blog homepage', 'jpkcom-post-filter' ),
        'custom' => __( 'Custom destination', 'jpkcom-post-filter' ),
    ];
    ?>
    <select
        id="jpkcom_postfilter_endpoint_empty_action"
        name="jpkcom_postfilter_general[endpoint_empty_action]"
        onchange="document.getElementById('jpkpf-endpoint-custom-wrap').style.display = this.value === 'custom' ? 'block' : 'none';"
    >
        <?php foreach ( $options as $val => $label ) : ?>
            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $action, $val ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div id="jpkpf-endpoint-custom-wrap" style="margin-top:8px;<?php echo $action !== 'custom' ? 'display:none;' : ''; ?>">
        <input
            type="text"
            id="jpkcom_postfilter_endpoint_empty_redirect"
            name="jpkcom_postfilter_general[endpoint_empty_redirect]"
            value="<?php echo esc_attr( $redirect ); ?>"
            class="regular-text"
            placeholder="https://example.com/blog/"
        >
        <p class="description"><?php esc_html_e( 'Full URL or absolute path for the 307 redirect.', 'jpkcom-post-filter' ); ?></p>
    </div>

    <p class="description" style="margin-top:6px;">
        <?php
        printf(
            /* translators: %s: example bare endpoint URL */
            esc_html__( 'Determines what happens when the filter endpoint is accessed without a filter path (e.g. %s).', 'jpkcom-post-filter' ),
            '<code>/' . esc_html( $settings['url_endpoint'] ?? 'filter' ) . '/</code>'
        );
        ?>
    </p>
    <?php
}

/**
 * Render: Debug Mode checkbox
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_debug_mode(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'general' );
    $value    = $settings['debug_mode'] ?? JPKCOM_POSTFILTER_DEBUG;
    ?>
    <label>
        <input
            type="checkbox"
            id="jpkcom_postfilter_debug_mode"
            name="jpkcom_postfilter_general[debug_mode]"
            value="1"
            <?php checked( $value ); ?>
        >
        <?php esc_html_e( 'Enable debug logging (independent of WP_DEBUG)', 'jpkcom-post-filter' ); ?>
    </label>
    <p class="description"><?php esc_html_e( 'When enabled, detailed logs are written to the PHP error log and debug-templates/ are used instead of templates/.', 'jpkcom-post-filter' ); ?></p>
    <?php
}


// ---------------------------------------------------------------------------
// Layout page – Tab rendering helpers
// ---------------------------------------------------------------------------

/**
 * Render a dual-input color field (color swatch + text input + reset button)
 *
 * @since 1.0.0
 *
 * @param string $var_name   Short var name without --jpkpf- prefix.
 * @param string $current    Current saved value (empty string = not set).
 * @param string $default    Default value shown as placeholder.
 */
function jpkcom_postfilter_render_color_field( string $var_name, string $current, string $default ): void {
    $is_hex = (bool) preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', trim( $current ) );
    ?>
    <div class="jpkpf-color-field">
        <input
            type="color"
            class="jpkpf-color-swatch"
            value="<?php echo esc_attr( $is_hex ? $current : $default ); ?>"
            tabindex="-1"
            aria-hidden="true"
            <?php echo ! $is_hex ? 'disabled' : ''; ?>
        >
        <input
            type="text"
            name="jpkcom_postfilter_layout[css_vars][<?php echo esc_attr( $var_name ); ?>]"
            value="<?php echo esc_attr( $current ); ?>"
            placeholder="<?php echo esc_attr( $default ); ?>"
            class="small-text jpkpf-color-text"
        >
        <button type="button" class="jpkpf-color-reset" title="<?php esc_attr_e( 'Reset to default', 'jpkcom-post-filter' ); ?>">&#8635;</button>
    </div>
    <?php
}

/**
 * Render a text input field for a CSS variable
 *
 * @since 1.0.0
 *
 * @param string $var_name Short var name without --jpkpf- prefix.
 * @param string $current  Current saved value.
 * @param string $default  Default value shown as placeholder.
 */
function jpkcom_postfilter_render_text_var_field( string $var_name, string $current, string $default ): void {
    ?>
    <input
        type="text"
        name="jpkcom_postfilter_layout[css_vars][<?php echo esc_attr( $var_name ); ?>]"
        value="<?php echo esc_attr( $current ); ?>"
        placeholder="<?php echo esc_attr( $default ); ?>"
        class="regular-text"
    >
    <?php
}

/**
 * Render a select field for a CSS variable
 *
 * @since 1.0.0
 *
 * @param string                $var_name Short var name without --jpkpf- prefix.
 * @param string                $current  Current saved value.
 * @param array<string, string> $options  Options array [value => label].
 */
function jpkcom_postfilter_render_select_var_field( string $var_name, string $current, array $options, string $default = '' ): void {
    ?>
    <select name="jpkcom_postfilter_layout[css_vars][<?php echo esc_attr( $var_name ); ?>]" data-default="<?php echo esc_attr( $default ); ?>">
        <?php foreach ( $options as $val => $label ) : ?>
            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

/**
 * Open a settings section fieldset with heading
 *
 * @since 1.0.0
 *
 * @param string $title Section heading text (already translated).
 */
function jpkcom_postfilter_layout_section_open( string $title ): void {
    echo '<div class="jpkpf-settings-section"><h3>' . esc_html( $title ) . '</h3><div class="jpkpf-settings-grid">';
}

/**
 * Close a settings section fieldset
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_layout_section_close(): void {
    echo '</div></div>';
}

/**
 * Render a "Reset tab to defaults" action row
 *
 * @since 1.0.0
 *
 * @param string $tab Tab identifier (global, filter, posts, pagination).
 */
function jpkcom_postfilter_layout_tab_reset_button( string $tab ): void {
    ?>
    <div class="jpkpf-tab-reset-row">
        <button
            type="button"
            class="button jpkpf-tab-reset-btn"
            data-tab="<?php echo esc_attr( $tab ); ?>"
            data-confirm="<?php esc_attr_e( 'Reset all CSS variable overrides in this tab to their defaults? This cannot be undone until you first save the current values.', 'jpkcom-post-filter' ); ?>"
        ><?php esc_html_e( 'Reset Tab to Defaults', 'jpkcom-post-filter' ); ?></button>
        <span class="jpkpf-tab-reset-hint"><?php esc_html_e( 'Clears all overrides in this tab. Click "Save Settings" to apply.', 'jpkcom-post-filter' ); ?></span>
    </div>
    <?php
}

/**
 * Render a row inside the settings grid (label + field)
 *
 * @since 1.0.0
 *
 * @param string $label      Field label (already translated).
 * @param string $field_html HTML of the form field.
 * @param string $desc       Optional description text (already translated).
 */
function jpkcom_postfilter_layout_row( string $label, string $field_html, string $desc = '' ): void {
    echo '<label>' . esc_html( $label ) . '</label>';
    echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    if ( $desc !== '' ) {
        echo '<p class="description" style="grid-column:2;">' . esc_html( $desc ) . '</p>';
    }
}

/**
 * Render: Layout page Tab 1 – Global
 *
 * @since 1.0.0
 *
 * @param array<string, mixed> $settings Saved layout settings.
 * @param array<string, string> $vars    Saved css_vars array.
 * @param array<string, string> $defs    CSS variable defaults.
 */
function jpkcom_postfilter_layout_tab_global( array $settings, array $vars, array $defs ): void {
    jpkcom_postfilter_layout_section_open( __( 'Primary Colors', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'primary', $vars['primary'] ?? '', $defs['primary'] );
    jpkcom_postfilter_layout_row( __( 'Primary Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'primary-hover', $vars['primary-hover'] ?? '', $defs['primary-hover'] );
    jpkcom_postfilter_layout_row( __( 'Primary Hover', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Typography', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'font-size-base', $vars['font-size-base'] ?? '', $defs['font-size-base'] );
    jpkcom_postfilter_layout_row( __( 'Base Font Size', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'font-size-sm', $vars['font-size-sm'] ?? '', $defs['font-size-sm'] );
    jpkcom_postfilter_layout_row( __( 'Small Font Size', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_select_var_field( 'font-weight-medium', $vars['font-weight-medium'] ?? '', [
        'inherit' => __( 'Inherit', 'jpkcom-post-filter' ),
        '400'     => '400',
        '500'     => '500',
        '600'     => '600',
        '700'     => '700',
    ], $defs['font-weight-medium'] );
    jpkcom_postfilter_layout_row( __( 'Medium Font Weight', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Spacing & Animation', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'gap', $vars['gap'] ?? '', $defs['gap'] );
    jpkcom_postfilter_layout_row( __( 'Gap (small)', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'gap-lg', $vars['gap-lg'] ?? '', $defs['gap-lg'] );
    jpkcom_postfilter_layout_row( __( 'Gap (large)', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'section-gap', $vars['section-gap'] ?? '', $defs['section-gap'] );
    jpkcom_postfilter_layout_row( __( 'Section Gap', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'transition', $vars['transition'] ?? '', $defs['transition'] );
    jpkcom_postfilter_layout_row( __( 'Transition', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_tab_reset_button( 'global' );
}

/**
 * Render: Layout page Tab 2 – Filter
 *
 * @since 1.0.0
 *
 * @param array<string, mixed>  $settings Saved layout settings.
 * @param array<string, string> $vars     Saved css_vars array.
 * @param array<string, string> $defs     CSS variable defaults.
 */
function jpkcom_postfilter_layout_tab_filter( array $settings, array $vars, array $defs ): void {
    $weight_options = [
        'inherit' => __( 'Inherit', 'jpkcom-post-filter' ),
        '400'     => '400',
        '500'     => '500',
        '600'     => '600',
        '700'     => '700',
    ];

    jpkcom_postfilter_layout_section_open( __( 'Filter Buttons', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-bg', $vars['filter-bg'] ?? '', $defs['filter-bg'] );
    jpkcom_postfilter_layout_row( __( 'Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-color', $vars['filter-color'] ?? '', $defs['filter-color'] );
    jpkcom_postfilter_layout_row( __( 'Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-hover-bg', $vars['filter-hover-bg'] ?? '', $defs['filter-hover-bg'] );
    jpkcom_postfilter_layout_row( __( 'Hover Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-hover-color', $vars['filter-hover-color'] ?? '', $defs['filter-hover-color'] );
    jpkcom_postfilter_layout_row( __( 'Hover Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-active-bg', $vars['filter-active-bg'] ?? '', $defs['filter-active-bg'] );
    jpkcom_postfilter_layout_row( __( 'Active Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-active-color', $vars['filter-active-color'] ?? '', $defs['filter-active-color'] );
    jpkcom_postfilter_layout_row( __( 'Active Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'filter-radius', $vars['filter-radius'] ?? '', $defs['filter-radius'] );
    jpkcom_postfilter_layout_row( __( 'Border Radius', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'padding', $vars['padding'] ?? '', $defs['padding'] );
    jpkcom_postfilter_layout_row( __( 'Padding', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'filter-btn-font-size', $vars['filter-btn-font-size'] ?? '', $defs['filter-btn-font-size'] );
    jpkcom_postfilter_layout_row( __( 'Button Font Size', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_select_var_field( 'filter-btn-font-weight', $vars['filter-btn-font-weight'] ?? '', $weight_options, $defs['filter-btn-font-weight'] );
    jpkcom_postfilter_layout_row( __( 'Button Font Weight', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Filter Group Labels', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'filter-label-color', $vars['filter-label-color'] ?? '', $defs['filter-label-color'] );
    jpkcom_postfilter_layout_row( __( 'Label Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_select_var_field( 'filter-label-font-weight', $vars['filter-label-font-weight'] ?? '', $weight_options, $defs['filter-label-font-weight'] );
    jpkcom_postfilter_layout_row( __( 'Label Font Weight', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'filter-label-font-size', $vars['filter-label-font-size'] ?? '', $defs['filter-label-font-size'] );
    jpkcom_postfilter_layout_row( __( 'Label Font Size', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Reset Button', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'reset-bg', $vars['reset-bg'] ?? '', $defs['reset-bg'] );
    jpkcom_postfilter_layout_row( __( 'Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'reset-color', $vars['reset-color'] ?? '', $defs['reset-color'] );
    jpkcom_postfilter_layout_row( __( 'Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'reset-border', $vars['reset-border'] ?? '', $defs['reset-border'] );
    jpkcom_postfilter_layout_row( __( 'Border Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'reset-radius', $vars['reset-radius'] ?? '', $defs['reset-radius'] );
    jpkcom_postfilter_layout_row( __( 'Border Radius', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'reset-hover-bg', $vars['reset-hover-bg'] ?? '', $defs['reset-hover-bg'] );
    jpkcom_postfilter_layout_row( __( 'Hover Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'reset-hover-color', $vars['reset-hover-color'] ?? '', $defs['reset-hover-color'] );
    jpkcom_postfilter_layout_row( __( 'Hover Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Dropdown Panel', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'dropdown-panel-bg', $vars['dropdown-panel-bg'] ?? '', $defs['dropdown-panel-bg'] );
    jpkcom_postfilter_layout_row( __( 'Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'dropdown-panel-border', $vars['dropdown-panel-border'] ?? '', $defs['dropdown-panel-border'] );
    jpkcom_postfilter_layout_row( __( 'Border Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'dropdown-panel-shadow', $vars['dropdown-panel-shadow'] ?? '', $defs['dropdown-panel-shadow'] );
    jpkcom_postfilter_layout_row( __( 'Box Shadow', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'dropdown-panel-radius', $vars['dropdown-panel-radius'] ?? '', $defs['dropdown-panel-radius'] );
    jpkcom_postfilter_layout_row( __( 'Border Radius', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_tab_reset_button( 'filter' );
}

/**
 * Render: Layout page Tab 3 – Posts
 *
 * @since 1.0.0
 *
 * @param array<string, mixed>  $settings Saved layout settings.
 * @param array<string, string> $vars     Saved css_vars array.
 * @param array<string, string> $defs     CSS variable defaults.
 */
function jpkcom_postfilter_layout_tab_posts( array $settings, array $vars, array $defs ): void {
    jpkcom_postfilter_layout_section_open( __( 'Card Layout', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'card-bg', $vars['card-bg'] ?? '', $defs['card-bg'] );
    jpkcom_postfilter_layout_row( __( 'Card Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'card-border', $vars['card-border'] ?? '', $defs['card-border'] );
    jpkcom_postfilter_layout_row( __( 'Card Border', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'card-shadow', $vars['card-shadow'] ?? '', $defs['card-shadow'] );
    jpkcom_postfilter_layout_row( __( 'Card Shadow', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'card-shadow-hover', $vars['card-shadow-hover'] ?? '', $defs['card-shadow-hover'] );
    jpkcom_postfilter_layout_row( __( 'Card Hover Shadow', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'card-radius', $vars['card-radius'] ?? '', $defs['card-radius'] );
    jpkcom_postfilter_layout_row( __( 'Card Radius', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'card-padding', $vars['card-padding'] ?? '', $defs['card-padding'] );
    jpkcom_postfilter_layout_row( __( 'Card Padding', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    ?><input type="number" name="jpkcom_postfilter_layout[css_vars][grid-cols]"
        value="<?php echo esc_attr( $vars['grid-cols'] ?? '' ); ?>"
        placeholder="<?php echo esc_attr( $defs['grid-cols'] ); ?>"
        min="1" max="6" class="small-text"><?php
    jpkcom_postfilter_layout_row( __( 'Grid Columns (desktop)', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    ?><input type="number" name="jpkcom_postfilter_layout[css_vars][grid-cols-md]"
        value="<?php echo esc_attr( $vars['grid-cols-md'] ?? '' ); ?>"
        placeholder="<?php echo esc_attr( $defs['grid-cols-md'] ); ?>"
        min="1" max="4" class="small-text"><?php
    jpkcom_postfilter_layout_row( __( 'Grid Columns (tablet)', 'jpkcom-post-filter' ), ob_get_clean(),
        __( 'Applies below 900px.', 'jpkcom-post-filter' ) );

    ob_start();
    ?><input type="number" name="jpkcom_postfilter_layout[css_vars][grid-cols-sm]"
        value="<?php echo esc_attr( $vars['grid-cols-sm'] ?? '' ); ?>"
        placeholder="<?php echo esc_attr( $defs['grid-cols-sm'] ); ?>"
        min="1" max="2" class="small-text"><?php
    jpkcom_postfilter_layout_row( __( 'Grid Columns (mobile)', 'jpkcom-post-filter' ), ob_get_clean(),
        __( 'Applies below 600px.', 'jpkcom-post-filter' ) );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_section_open( __( 'Typography & Links', 'jpkcom-post-filter' ) );

    ob_start();
    jpkcom_postfilter_render_color_field( 'text-primary', $vars['text-primary'] ?? '', $defs['text-primary'] );
    jpkcom_postfilter_layout_row( __( 'Primary Text', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'text-secondary', $vars['text-secondary'] ?? '', $defs['text-secondary'] );
    jpkcom_postfilter_layout_row( __( 'Secondary Text', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'link-color', $vars['link-color'] ?? '', $defs['link-color'] );
    jpkcom_postfilter_layout_row( __( 'Link Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'link-hover', $vars['link-hover'] ?? '', $defs['link-hover'] );
    jpkcom_postfilter_layout_row( __( 'Link Hover', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'no-results-color', $vars['no-results-color'] ?? '', $defs['no-results-color'] );
    jpkcom_postfilter_layout_row( __( 'No Results Text', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_tab_reset_button( 'posts' );
}

/**
 * Render: Layout page Tab 4 – Pagination
 *
 * @since 1.0.0
 *
 * @param array<string, mixed>  $settings Saved layout settings.
 * @param array<string, string> $vars     Saved css_vars array.
 * @param array<string, string> $defs     CSS variable defaults.
 */
function jpkcom_postfilter_layout_tab_pagination( array $settings, array $vars, array $defs ): void {
    jpkcom_postfilter_layout_section_open( __( 'Pagination Buttons', 'jpkcom-post-filter' ) );

    echo '<p class="description" style="grid-column:1/-1;margin-bottom:8px;">'
        . esc_html__( 'Leave empty to inherit from Filter Button colors.', 'jpkcom-post-filter' )
        . '</p>';

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-bg', $vars['pagi-bg'] ?? '', $defs['pagi-bg'] );
    jpkcom_postfilter_layout_row( __( 'Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-color', $vars['pagi-color'] ?? '', $defs['pagi-color'] );
    jpkcom_postfilter_layout_row( __( 'Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-hover-bg', $vars['pagi-hover-bg'] ?? '', $defs['pagi-hover-bg'] );
    jpkcom_postfilter_layout_row( __( 'Hover Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-hover-color', $vars['pagi-hover-color'] ?? '', $defs['pagi-hover-color'] );
    jpkcom_postfilter_layout_row( __( 'Hover Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-active-bg', $vars['pagi-active-bg'] ?? '', $defs['pagi-active-bg'] );
    jpkcom_postfilter_layout_row( __( 'Active Background', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_color_field( 'pagi-active-color', $vars['pagi-active-color'] ?? '', $defs['pagi-active-color'] );
    jpkcom_postfilter_layout_row( __( 'Active Text Color', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'pagi-radius', $vars['pagi-radius'] ?? '', $defs['pagi-radius'] );
    jpkcom_postfilter_layout_row( __( 'Border Radius', 'jpkcom-post-filter' ), ob_get_clean() );

    ob_start();
    jpkcom_postfilter_render_text_var_field( 'pagi-font-size', $vars['pagi-font-size'] ?? '', $defs['pagi-font-size'] );
    jpkcom_postfilter_layout_row( __( 'Font Size', 'jpkcom-post-filter' ), ob_get_clean() );

    jpkcom_postfilter_layout_section_close();

    jpkcom_postfilter_layout_tab_reset_button( 'pagination' );
}

/**
 * Render: Layout page Tab 5 – Color Schemes
 *
 * @since 1.0.0
 *
 * @param array<string, mixed> $settings Saved layout settings.
 */
function jpkcom_postfilter_layout_tab_schemes( array $settings ): void {
    $current_scheme = $settings['color_scheme'] ?? 'default';

    $schemes = [
        'default'  => [
            'name'    => __( 'Default', 'jpkcom-post-filter' ),
            'desc'    => __( 'WordPress blue accent, light backgrounds.', 'jpkcom-post-filter' ),
            'preview' => 'default',
        ],
        'dark'     => [
            'name'    => __( 'Dark', 'jpkcom-post-filter' ),
            'desc'    => __( 'Dark backgrounds, light texts, blue accent.', 'jpkcom-post-filter' ),
            'preview' => 'dark',
        ],
        'contrast' => [
            'name'    => __( 'Contrast', 'jpkcom-post-filter' ),
            'desc'    => __( 'Red reset button for stronger visual differentiation.', 'jpkcom-post-filter' ),
            'preview' => 'contrast',
        ],
        'mono'     => [
            'name'    => __( 'Monochrome', 'jpkcom-post-filter' ),
            'desc'    => __( 'Black, white and grey only – no color accent.', 'jpkcom-post-filter' ),
            'preview' => 'mono',
        ],
    ];
    ?>
    <p><?php esc_html_e( 'Choose a predefined color scheme. Your custom CSS variable overrides (other tabs) are applied on top of the selected scheme.', 'jpkcom-post-filter' ); ?></p>
    <div class="jpkpf-scheme-cards">
        <?php foreach ( $schemes as $key => $scheme ) : ?>
            <div class="jpkpf-scheme-card">
                <input
                    type="radio"
                    name="jpkcom_postfilter_layout[color_scheme]"
                    id="jpkpf_scheme_<?php echo esc_attr( $key ); ?>"
                    value="<?php echo esc_attr( $key ); ?>"
                    <?php checked( $current_scheme, $key ); ?>
                >
                <div class="jpkpf-scheme-card-inner">
                    <div class="jpkpf-scheme-preview jpkpf-scheme-preview--<?php echo esc_attr( $scheme['preview'] ); ?>">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <strong class="jpkpf-scheme-name"><?php echo esc_html( $scheme['name'] ); ?></strong>
                    <p class="description" style="text-align:center;margin:4px 0 0;font-size:11px;"><?php echo esc_html( $scheme['desc'] ); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Render: Layout page Tab 6 – Advanced
 *
 * @since 1.0.0
 *
 * @param array<string, mixed> $settings Saved layout settings.
 */
function jpkcom_postfilter_layout_tab_advanced( array $settings ): void {
    $stylesheet_mode = $settings['stylesheet_mode'] ?? 'full';
    $reset_mode      = $settings['reset_button_mode'] ?? 'on_selection';
    $pagination_pos  = $settings['pagination_position'] ?? 'below';
    $plus_minus      = ! empty( $settings['plus_minus_mode'] );
    $show_more       = ! empty( $settings['show_more_enabled'] );
    $show_threshold  = (int) ( $settings['show_more_threshold'] ?? 10 );
    $custom_css      = $settings['custom_css'] ?? '';

    $stylesheet_modes = [
        'full'      => [
            'label' => __( 'Full stylesheet', 'jpkcom-post-filter' ),
            'desc'  => __( 'Loads the complete plugin CSS (layout + variables).', 'jpkcom-post-filter' ),
        ],
        'vars_only' => [
            'label' => __( 'Variables only', 'jpkcom-post-filter' ),
            'desc'  => __( 'Outputs only the :root CSS variable block as inline style. Your theme handles all layout CSS.', 'jpkcom-post-filter' ),
        ],
        'disabled'  => [
            'label' => __( 'Disabled', 'jpkcom-post-filter' ),
            'desc'  => __( 'No CSS is loaded at all. Fully self-managed styling.', 'jpkcom-post-filter' ),
        ],
    ];

    $reset_modes = [
        'always'       => [
            'label' => __( 'Always visible', 'jpkcom-post-filter' ),
            'desc'  => __( 'The reset button is always shown, even when no filters are active.', 'jpkcom-post-filter' ),
        ],
        'on_selection' => [
            'label' => __( 'On selection', 'jpkcom-post-filter' ),
            'desc'  => __( 'The reset button appears only when at least one filter is active.', 'jpkcom-post-filter' ),
        ],
        'never'        => [
            'label' => __( 'Never', 'jpkcom-post-filter' ),
            'desc'  => __( 'The reset button is never shown.', 'jpkcom-post-filter' ),
        ],
    ];

    $pagination_positions = [
        'below' => [
            'label' => __( 'Below posts', 'jpkcom-post-filter' ),
            'desc'  => __( 'Pagination appears after the post list (default).', 'jpkcom-post-filter' ),
        ],
        'above' => [
            'label' => __( 'Above posts', 'jpkcom-post-filter' ),
            'desc'  => __( 'Pagination appears before the post list.', 'jpkcom-post-filter' ),
        ],
        'both'  => [
            'label' => __( 'Above and below posts', 'jpkcom-post-filter' ),
            'desc'  => __( 'Pagination appears both before and after the post list.', 'jpkcom-post-filter' ),
        ],
    ];
    ?>

    <div class="jpkpf-settings-section">
        <h3><?php esc_html_e( 'Stylesheet Mode', 'jpkcom-post-filter' ); ?></h3>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Stylesheet Mode', 'jpkcom-post-filter' ); ?></legend>
            <?php foreach ( $stylesheet_modes as $key => $opt ) : ?>
                <label style="display:block;margin-bottom:6px;">
                    <input
                        type="radio"
                        name="jpkcom_postfilter_layout[stylesheet_mode]"
                        value="<?php echo esc_attr( $key ); ?>"
                        <?php checked( $stylesheet_mode, $key ); ?>
                    >
                    <strong><?php echo esc_html( $opt['label'] ); ?></strong>
                    <span style="color:#646970;margin-left:4px;">– <?php echo esc_html( $opt['desc'] ); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    </div>

    <div class="jpkpf-settings-section">
        <h3><?php esc_html_e( 'Filter Interaction', 'jpkcom-post-filter' ); ?></h3>

        <p style="font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Reset Button Visibility', 'jpkcom-post-filter' ); ?></p>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Reset Button', 'jpkcom-post-filter' ); ?></legend>
            <?php foreach ( $reset_modes as $key => $opt ) : ?>
                <label style="display:block;margin-bottom:6px;">
                    <input
                        type="radio"
                        name="jpkcom_postfilter_layout[reset_button_mode]"
                        value="<?php echo esc_attr( $key ); ?>"
                        <?php checked( $reset_mode, $key ); ?>
                    >
                    <strong><?php echo esc_html( $opt['label'] ); ?></strong>
                    <span style="color:#646970;margin-left:4px;">– <?php echo esc_html( $opt['desc'] ); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <hr style="margin:16px 0;">

        <label style="display:block;margin-bottom:8px;">
            <input
                type="checkbox"
                name="jpkcom_postfilter_layout[plus_minus_mode]"
                value="1"
                <?php checked( $plus_minus ); ?>
            >
            <strong><?php esc_html_e( 'Plus/Minus Mode', 'jpkcom-post-filter' ); ?></strong>
        </label>
        <p class="description" style="margin-bottom:16px;">
            <?php esc_html_e( 'Adds a +/– icon to each filter button. Clicking the label selects a filter exclusively; clicking + adds it to the selection.', 'jpkcom-post-filter' ); ?>
        </p>

        <label style="display:block;margin-bottom:8px;">
            <input
                type="checkbox"
                name="jpkcom_postfilter_layout[show_more_enabled]"
                value="1"
                <?php checked( $show_more ); ?>
            >
            <strong><?php esc_html_e( 'Show More Button (…)', 'jpkcom-post-filter' ); ?></strong>
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <span><?php esc_html_e( 'Show button when group has more than', 'jpkcom-post-filter' ); ?></span>
            <input
                type="number"
                name="jpkcom_postfilter_layout[show_more_threshold]"
                value="<?php echo esc_attr( (string) $show_threshold ); ?>"
                min="1"
                max="100"
                style="width:70px;"
            >
            <span><?php esc_html_e( 'filters', 'jpkcom-post-filter' ); ?></span>
        </label>
        <p class="description">
            <?php esc_html_e( 'Not available in Dropdown layout.', 'jpkcom-post-filter' ); ?>
        </p>
    </div>

    <div class="jpkpf-settings-section">
        <h3><?php esc_html_e( 'Pagination Position', 'jpkcom-post-filter' ); ?></h3>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Pagination Position', 'jpkcom-post-filter' ); ?></legend>
            <?php foreach ( $pagination_positions as $key => $opt ) : ?>
                <label style="display:block;margin-bottom:6px;">
                    <input
                        type="radio"
                        name="jpkcom_postfilter_layout[pagination_position]"
                        value="<?php echo esc_attr( $key ); ?>"
                        <?php checked( $pagination_pos, $key ); ?>
                    >
                    <strong><?php echo esc_html( $opt['label'] ); ?></strong>
                    <span style="color:#646970;margin-left:4px;">– <?php echo esc_html( $opt['desc'] ); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p class="description"><?php esc_html_e( 'Applies to auto-injection only. Shortcode pagination is placed manually.', 'jpkcom-post-filter' ); ?></p>
    </div>

    <div class="jpkpf-settings-section">
        <h3><?php esc_html_e( 'Custom CSS', 'jpkcom-post-filter' ); ?></h3>
        <textarea
            id="jpkcom_postfilter_custom_css"
            name="jpkcom_postfilter_layout[custom_css]"
            rows="10"
            class="large-text code"
            spellcheck="false"
        ><?php echo esc_textarea( $custom_css ); ?></textarea>
        <p class="description"><?php esc_html_e( 'Additional CSS rules appended after the plugin\'s stylesheet. Avoid using !important.', 'jpkcom-post-filter' ); ?></p>
    </div>
    <?php
}



// ---------------------------------------------------------------------------
// Field rendering callbacks – Cache
// ---------------------------------------------------------------------------

/**
 * Render: Object Cache checkbox
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_object_cache_enabled(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'cache' );
    $value    = $settings['object_cache_enabled'] ?? true;
    ?>
    <label>
        <input
            type="checkbox"
            name="jpkcom_postfilter_cache[object_cache_enabled]"
            value="1"
            <?php checked( $value ); ?>
        >
        <?php esc_html_e( 'Enable WordPress Object Cache for query results', 'jpkcom-post-filter' ); ?>
    </label>
    <?php
}

/**
 * Render: Transient Cache checkbox
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_transient_cache_enabled(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'cache' );
    $value    = $settings['transient_cache_enabled'] ?? true;
    ?>
    <label>
        <input
            type="checkbox"
            name="jpkcom_postfilter_cache[transient_cache_enabled]"
            value="1"
            <?php checked( $value ); ?>
        >
        <?php esc_html_e( 'Enable transient caching for taxonomy term lists', 'jpkcom-post-filter' ); ?>
    </label>
    <?php
}

/**
 * Render: Settings File Cache checkbox
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_settings_cache_enabled(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'cache' );
    $value    = $settings['settings_cache_enabled'] ?? true;
    ?>
    <label>
        <input
            type="checkbox"
            name="jpkcom_postfilter_cache[settings_cache_enabled]"
            value="1"
            <?php checked( $value ); ?>
        >
        <?php esc_html_e( 'Enable PHP file cache for settings (fastest option)', 'jpkcom-post-filter' ); ?>
    </label>
    <p class="description">
        <?php
        printf(
            /* translators: %s: settings cache directory path */
            esc_html__( 'Cache files are stored in: %s', 'jpkcom-post-filter' ),
            '<code>' . esc_html( JPKCOM_POSTFILTER_SETTINGS_DIR ) . '</code>'
        );
        ?>
    </p>
    <?php
}

/**
 * Render: Cache TTL number field
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_field_cache_ttl(): void {
    $settings = jpkcom_postfilter_settings_get_group( 'cache' );
    $value    = $settings['cache_ttl'] ?? HOUR_IN_SECONDS;
    ?>
    <input
        type="number"
        id="jpkcom_postfilter_cache_ttl"
        name="jpkcom_postfilter_cache[cache_ttl]"
        value="<?php echo esc_attr( (string) $value ); ?>"
        min="60"
        step="60"
        class="regular-text"
    >
    <p class="description">
        <?php
        printf(
            /* translators: 1: seconds value, 2: human readable */
            esc_html__( 'Transient cache TTL in seconds. Current: %1$d seconds (%2$s)', 'jpkcom-post-filter' ),
            (int) $value,
            esc_html( human_time_diff( 0, (int) $value ) )
        );
        ?>
    </p>
    <?php
}


// ---------------------------------------------------------------------------
// Page rendering callbacks
// ---------------------------------------------------------------------------

/**
 * Render: General Settings page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_general(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle cache clear action
    if ( isset( $_POST['jpkcom_postfilter_action'] ) && $_POST['jpkcom_postfilter_action'] === 'flush_rewrite' ) {
        check_admin_referer( 'jpkcom_postfilter_flush_rewrite' );
        flush_rewrite_rules();
        add_settings_error( 'jpkcom_postfilter_general_messages', 'flushed', __( 'Rewrite rules flushed.', 'jpkcom-post-filter' ), 'success' );
    }

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'jpkcom_postfilter_general_messages', 'saved', __( 'Settings saved.', 'jpkcom-post-filter' ), 'success' );
    }

    settings_errors( 'jpkcom_postfilter_general_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'jpkcom_postfilter_general' );
            do_settings_sections( 'jpkcom-post-filter' );
            submit_button();
            ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Rewrite Rules', 'jpkcom-post-filter' ); ?></h2>
        <p><?php esc_html_e( 'After changing the URL endpoint, flush the rewrite rules.', 'jpkcom-post-filter' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'jpkcom_postfilter_flush_rewrite' ); ?>
            <input type="hidden" name="jpkcom_postfilter_action" value="flush_rewrite">
            <?php submit_button( __( 'Flush Rewrite Rules', 'jpkcom-post-filter' ), 'secondary' ); ?>
        </form>
    </div>
    <?php
}

/**
 * Render: Filter Groups page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_filter_groups(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'jpkcom_postfilter_filter_groups_messages', 'saved', __( 'Filter groups saved.', 'jpkcom-post-filter' ), 'success' );
    }

    settings_errors( 'jpkcom_postfilter_filter_groups_messages' );

    $settings            = jpkcom_postfilter_settings_get_group( 'filter_groups' );
    $groups              = $settings['groups'] ?? [];
    $all_taxonomies      = get_taxonomies( [ 'show_ui' => true ], 'objects' );
    $all_post_types      = get_post_types( [ 'public' => true ], 'objects' );
    $enabled_post_types  = (array) jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p><?php esc_html_e( 'Configure which taxonomies are used as filter groups. The order here determines the URL segment order.', 'jpkcom-post-filter' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'jpkcom_postfilter_filter_groups' ); ?>

            <div id="jpkcom-filter-groups-list" data-next-index="<?php echo esc_attr( (string) count( $groups ) ); ?>">

                <p class="description" id="jpkcom-groups-empty-msg"<?php echo ! empty( $groups ) ? ' style="display:none;"' : ''; ?>>
                    <?php esc_html_e( 'No filter groups configured yet. Click "Add Filter Group" below.', 'jpkcom-post-filter' ); ?>
                </p>

                <?php foreach ( $groups as $index => $group ) : ?>
                    <div class="jpkcom-filter-group postbox" style="padding: 12px; margin-bottom: 12px;">
                        <div class="jpkcom-group-header">
                            <span class="jpkcom-segment-badge"></span>
                        </div>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( 'Custom Taxonomy', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][custom]"
                                        value="0">
                                    <label>
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][custom]"
                                            value="1"
                                            class="jpkcom-custom-toggle"
                                            <?php checked( ! empty( $group['custom'] ) ); ?>>
                                        <?php esc_html_e( 'Register as new WordPress taxonomy', 'jpkcom-post-filter' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Enable only if this taxonomy does not yet exist in WordPress.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-taxonomy-text-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Taxonomy Slug', 'jpkcom-post-filter' ); ?> <abbr title="<?php esc_attr_e( 'Required', 'jpkcom-post-filter' ); ?>">*</abbr></th>
                                <td>
                                    <input type="text"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][taxonomy]"
                                        value="<?php echo esc_attr( $group['taxonomy'] ); ?>"
                                        class="regular-text"
                                        required
                                        <?php echo empty( $group['custom'] ) ? 'disabled' : ''; ?>>
                                    <p class="description"><?php esc_html_e( 'Required. Unique slug for the new taxonomy, lowercase letters and hyphens only (e.g. "meine-themen").', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-taxonomy-select-row"<?php echo ! empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Taxonomy', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <select name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][taxonomy]"
                                            <?php echo ! empty( $group['custom'] ) ? 'disabled' : ''; ?>>
                                        <?php foreach ( $all_taxonomies as $tax ) : ?>
                                            <option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $group['taxonomy'], $tax->name ); ?>>
                                                <?php echo esc_html( $tax->labels->singular_name ?? $tax->name ); ?>
                                                (<?php echo esc_html( $tax->name ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Select the existing WordPress taxonomy to use as filter.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-slug-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Rewrite Slug', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][slug]"
                                        value="<?php echo esc_attr( $group['slug'] ); ?>"
                                        class="regular-text">
                                    <p class="description"><?php esc_html_e( 'URL rewrite slug for this custom taxonomy (e.g. "my-topics"). Defaults to the taxonomy name if left empty.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-hierarchical-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Type', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][hierarchical]"
                                        value="0">
                                    <label>
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][hierarchical]"
                                            value="1"
                                            <?php checked( ! empty( $group['hierarchical'] ) ); ?>>
                                        <?php esc_html_e( 'Hierarchical (like categories, with parent/child terms)', 'jpkcom-post-filter' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Uncheck for tag-like behaviour (flat list, no parent/child).', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-custom-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Public', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][public]"
                                        value="0">
                                    <label>
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][public]"
                                            value="1"
                                            <?php checked( (bool) ( $group['public'] ?? true ) ); ?>>
                                        <?php esc_html_e( 'Enable term archive pages (public = true)', 'jpkcom-post-filter' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Enables frontend URLs for individual terms (e.g. /my-taxonomy/my-term/). Note: WordPress does not create a taxonomy index page — only per-term archives are generated. Disable for purely internal taxonomies.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-custom-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Admin Column', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][show_admin_column]"
                                        value="0">
                                    <label>
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][show_admin_column]"
                                            value="1"
                                            <?php checked( (bool) ( $group['show_admin_column'] ?? true ) ); ?>>
                                        <?php esc_html_e( 'Show as column in post list (show_admin_column = true)', 'jpkcom-post-filter' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Adds a column for this taxonomy to the post list table in the WordPress admin.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr class="jpkcom-custom-row"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'REST API', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][show_in_rest]"
                                        value="0">
                                    <label>
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][show_in_rest]"
                                            value="1"
                                            <?php checked( (bool) ( $group['show_in_rest'] ?? true ) ); ?>>
                                        <?php esc_html_e( 'Expose in REST API (show_in_rest = true)', 'jpkcom-post-filter' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Required for the Block Editor (Gutenberg). Disable only for purely server-side workflows.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Post Types', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <?php
                                    $selected_pt = isset( $group['post_types'] ) && is_array( $group['post_types'] )
                                        ? $group['post_types']
                                        : [];
                                    foreach ( $all_post_types as $pt ) :
                                    ?>
                                    <label style="display:inline-block; margin-right:12px;">
                                        <input type="checkbox"
                                            name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][post_types][]"
                                            value="<?php echo esc_attr( $pt->name ); ?>"
                                            <?php checked( in_array( $pt->name, $selected_pt, true ) ); ?>>
                                        <?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?>
                                        <small>(<?php echo esc_html( $pt->name ); ?>)</small>
                                    </label>
                                    <?php endforeach; ?>
                                    <p class="description"><?php esc_html_e( 'On which archive pages should this filter group appear? Leave empty = all enabled post types. For new (custom) taxonomies this also determines for which post types the taxonomy is registered in WordPress.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Label', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][label]"
                                        value="<?php echo esc_attr( $group['label'] ); ?>"
                                        class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Shown in the filter bar and (for custom taxonomies) in the WordPress backend.', 'jpkcom-post-filter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Order', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="number"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][order]"
                                        value="<?php echo esc_attr( (string) $group['order'] ); ?>"
                                        min="0" class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Enabled', 'jpkcom-post-filter' ); ?></th>
                                <td>
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][enabled]"
                                        value="0">
                                    <input type="checkbox"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][enabled]"
                                        value="1" <?php checked( ! empty( $group['enabled'] ) ); ?>>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <input type="hidden"
                                        name="jpkcom_postfilter_filter_groups[groups][<?php echo $index; ?>][hide_empty]"
                                        value="<?php echo esc_attr( (string) ( $group['hide_empty'] ?? '1' ) ); ?>">
                                </td>
                            </tr>
                        </table>
                        <div style="padding: 4px 0; display:flex; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                            <button type="button" class="button button-link-delete jpkcom-remove-group">
                                <?php esc_html_e( 'Remove Group', 'jpkcom-post-filter' ); ?>
                            </button>
                            <span class="jpkcom-remove-custom-warning"<?php echo empty( $group['custom'] ) ? ' style="display:none;"' : ''; ?> aria-live="polite">
                                <strong style="color:#d63638;">&#9888; <?php esc_html_e( 'Warning: Removing this group will unregister the custom taxonomy — all term assignments on posts will be permanently lost and cannot be recovered.', 'jpkcom-post-filter' ); ?></strong>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div><!-- #jpkcom-filter-groups-list -->

            <p>
                <button type="button" id="jpkcom-add-filter-group" class="button button-primary">
                    <?php esc_html_e( '+ Add Filter Group', 'jpkcom-post-filter' ); ?>
                </button>
            </p>

            <?php submit_button( __( 'Save Filter Groups', 'jpkcom-post-filter' ) ); ?>
        </form>

    </div><!-- .wrap -->

    <?php
    // Group template – outside the <form> so its inputs are never submitted as-is.
    // JavaScript clones this, replaces __INDEX__ with a real index, and appends
    // the clone into #jpkcom-filter-groups-list (which is inside the form).
    ?>
    <div id="jpkcom-filter-group-template" style="display:none;" aria-hidden="true">
        <div class="jpkcom-filter-group postbox" style="padding: 12px; margin-bottom: 12px;">
            <div class="jpkcom-group-header">
                <span class="jpkcom-segment-badge"></span>
            </div>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e( 'Custom Taxonomy', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][custom]"
                            value="0">
                        <label>
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][custom]"
                                value="1"
                                class="jpkcom-custom-toggle">
                            <?php esc_html_e( 'Register as new WordPress taxonomy', 'jpkcom-post-filter' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Enable only if this taxonomy does not yet exist in WordPress.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-taxonomy-text-row" style="display:none;">
                    <th><?php esc_html_e( 'Taxonomy Slug', 'jpkcom-post-filter' ); ?> <abbr title="<?php esc_attr_e( 'Required', 'jpkcom-post-filter' ); ?>">*</abbr></th>
                    <td>
                        <input type="text"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][taxonomy]"
                            value="" class="regular-text" required disabled>
                        <p class="description"><?php esc_html_e( 'Required. Unique slug for the new taxonomy, lowercase letters and hyphens only (e.g. "meine-themen").', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-taxonomy-select-row">
                    <th><?php esc_html_e( 'Taxonomy', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <select name="jpkcom_postfilter_filter_groups[groups][__INDEX__][taxonomy]">
                            <?php foreach ( $all_taxonomies as $tax ) : ?>
                                <option value="<?php echo esc_attr( $tax->name ); ?>">
                                    <?php echo esc_html( $tax->labels->singular_name ?? $tax->name ); ?>
                                    (<?php echo esc_html( $tax->name ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Select the existing WordPress taxonomy to use as filter.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-slug-row" style="display:none;">
                    <th><?php esc_html_e( 'Rewrite Slug', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="text"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][slug]"
                            value="" class="regular-text">
                        <p class="description"><?php esc_html_e( 'URL rewrite slug for this custom taxonomy (e.g. "my-topics"). Defaults to the taxonomy name if left empty.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-hierarchical-row" style="display:none;">
                    <th><?php esc_html_e( 'Type', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][hierarchical]"
                            value="0">
                        <label>
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][hierarchical]"
                                value="1">
                            <?php esc_html_e( 'Hierarchical (like categories, with parent/child terms)', 'jpkcom-post-filter' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Uncheck for tag-like behaviour (flat list, no parent/child).', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-custom-row" style="display:none;">
                    <th><?php esc_html_e( 'Public', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][public]"
                            value="0">
                        <label>
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][public]"
                                value="1" checked>
                            <?php esc_html_e( 'Enable term archive pages (public = true)', 'jpkcom-post-filter' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Enables frontend URLs for individual terms (e.g. /my-taxonomy/my-term/). Note: WordPress does not create a taxonomy index page — only per-term archives are generated. Disable for purely internal taxonomies.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-custom-row" style="display:none;">
                    <th><?php esc_html_e( 'Admin Column', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][show_admin_column]"
                            value="0">
                        <label>
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][show_admin_column]"
                                value="1" checked>
                            <?php esc_html_e( 'Show as column in post list (show_admin_column = true)', 'jpkcom-post-filter' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Adds a column for this taxonomy to the post list table in the WordPress admin.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr class="jpkcom-custom-row" style="display:none;">
                    <th><?php esc_html_e( 'REST API', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][show_in_rest]"
                            value="0">
                        <label>
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][show_in_rest]"
                                value="1" checked>
                            <?php esc_html_e( 'Expose in REST API (show_in_rest = true)', 'jpkcom-post-filter' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Required for the Block Editor (Gutenberg). Disable only for purely server-side workflows.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Post Types', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <?php foreach ( $all_post_types as $pt ) : ?>
                        <label style="display:inline-block; margin-right:12px;">
                            <input type="checkbox"
                                name="jpkcom_postfilter_filter_groups[groups][__INDEX__][post_types][]"
                                value="<?php echo esc_attr( $pt->name ); ?>">
                            <?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?>
                            <small>(<?php echo esc_html( $pt->name ); ?>)</small>
                        </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'On which archive pages should this filter group appear? Leave empty = all enabled post types. For new (custom) taxonomies this also determines for which post types the taxonomy is registered in WordPress.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Label', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="text"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][label]"
                            value="" class="regular-text">
                        <p class="description"><?php esc_html_e( 'Shown in the filter bar and (for custom taxonomies) in the WordPress backend.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Order', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="number"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][order]"
                            value="0" min="0" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Enabled', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][enabled]"
                            value="0">
                        <input type="checkbox"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][enabled]"
                            value="1" checked>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <input type="hidden"
                            name="jpkcom_postfilter_filter_groups[groups][__INDEX__][hide_empty]"
                            value="1">
                    </td>
                </tr>
            </table>
            <div style="padding: 4px 0; display:flex; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                <button type="button" class="button button-link-delete jpkcom-remove-group">
                    <?php esc_html_e( 'Remove Group', 'jpkcom-post-filter' ); ?>
                </button>
                <span class="jpkcom-remove-custom-warning" style="display:none;" aria-live="polite">
                    <strong style="color:#d63638;">&#9888; <?php esc_html_e( 'Warning: Removing this group will unregister the custom taxonomy — all term assignments on posts will be permanently lost and cannot be recovered.', 'jpkcom-post-filter' ); ?></strong>
                </span>
            </div>
        </div>
    </div><!-- #jpkcom-filter-group-template -->

    <script>
    var jpkcomFilterGroupsI18n = {
        segmentLabel:        <?php echo wp_json_encode( __( 'URL-Filter-Segment', 'jpkcom-post-filter' ) ); ?>,
        confirmRemove:       <?php echo wp_json_encode( __( 'Are you sure you want to remove this filter group?', 'jpkcom-post-filter' ) ); ?>,
        confirmRemoveCustom: <?php echo wp_json_encode( __( "Warning: This group uses a Custom Taxonomy registered by this plugin.\n\nRemoving it will unregister the taxonomy \u2014 all term assignments on posts will be permanently lost and cannot be recovered.\n\nAre you sure you want to remove this group?", 'jpkcom-post-filter' ) ); ?>
    };
    document.addEventListener( 'change', function ( e ) {
        if ( ! e.target.classList.contains( 'jpkcom-custom-toggle' ) ) return;
        var group = e.target.closest( '.jpkcom-filter-group' );
        var on    = e.target.checked;

        // Slug + hierarchical + custom-only rows + taxonomy text: only visible when custom
        [ '.jpkcom-slug-row', '.jpkcom-hierarchical-row', '.jpkcom-custom-row', '.jpkcom-taxonomy-text-row' ].forEach( function ( sel ) {
            var row = group.querySelector( sel );
            if ( row ) row.style.display = on ? '' : 'none';
        } );

        // Taxonomy select: only visible when NOT custom
        var selectRow = group.querySelector( '.jpkcom-taxonomy-select-row' );
        if ( selectRow ) selectRow.style.display = on ? 'none' : '';

        // Enable/disable the correct taxonomy input so only one submits
        var sel = group.querySelector( '.jpkcom-taxonomy-select-row select' );
        var txt = group.querySelector( '.jpkcom-taxonomy-text-row input' );
        if ( sel ) sel.disabled = on;
        if ( txt ) txt.disabled = ! on;

        // Show/hide the custom taxonomy data-loss warning near the remove button
        var warning = group.querySelector( '.jpkcom-remove-custom-warning' );
        if ( warning ) warning.style.display = on ? '' : 'none';
    } );
    </script>
    <?php
}

/**
 * Render: Layout & Design page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_layout(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'jpkcom_postfilter_layout_messages', 'saved', __( 'Layout settings saved.', 'jpkcom-post-filter' ), 'success' );
    }

    settings_errors( 'jpkcom_postfilter_layout_messages' );

    $settings = jpkcom_postfilter_settings_get_group( 'layout' );
    $vars     = isset( $settings['css_vars'] ) && is_array( $settings['css_vars'] ) ? $settings['css_vars'] : [];
    $defs     = jpkcom_postfilter_get_css_var_defaults();

    $filter_layout = $settings['filter_layout'] ?? 'bar';
    $list_layout   = $settings['list_layout'] ?? 'cards';

    $tabs = [
        'global'     => __( 'Global', 'jpkcom-post-filter' ),
        'filter'     => __( 'Filter', 'jpkcom-post-filter' ),
        'posts'      => __( 'Posts', 'jpkcom-post-filter' ),
        'pagination' => __( 'Pagination', 'jpkcom-post-filter' ),
        'schemes'    => __( 'Color Schemes', 'jpkcom-post-filter' ),
        'advanced'   => __( 'Advanced', 'jpkcom-post-filter' ),
    ];
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'jpkcom_postfilter_layout' ); ?>

            <nav class="jpkpf-tab-nav">
                <?php foreach ( $tabs as $id => $label ) : ?>
                    <button type="button" class="jpkpf-tab-btn" data-tab="<?php echo esc_attr( $id ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div id="tab-global" class="jpkpf-tab-panel">
                <?php jpkcom_postfilter_layout_tab_global( $settings, $vars, $defs ); ?>
            </div>

            <div id="tab-filter" class="jpkpf-tab-panel">
                <?php jpkcom_postfilter_layout_tab_filter( $settings, $vars, $defs ); ?>
            </div>

            <div id="tab-posts" class="jpkpf-tab-panel">
                <?php jpkcom_postfilter_layout_tab_posts( $settings, $vars, $defs ); ?>
            </div>

            <div id="tab-pagination" class="jpkpf-tab-panel">
                <?php jpkcom_postfilter_layout_tab_pagination( $settings, $vars, $defs ); ?>
            </div>

            <div id="tab-schemes" class="jpkpf-tab-panel">
                <?php jpkcom_postfilter_layout_tab_schemes( $settings ); ?>
            </div>

            <div id="tab-advanced" class="jpkpf-tab-panel">
                <div class="jpkpf-settings-section">
                    <h3><?php esc_html_e( 'Default Layouts', 'jpkcom-post-filter' ); ?></h3>
                    <div class="jpkpf-settings-grid">
                        <label><?php esc_html_e( 'Filter Layout', 'jpkcom-post-filter' ); ?></label>
                        <select name="jpkcom_postfilter_layout[filter_layout]">
                            <?php foreach ( [
                                'bar'      => __( 'Horizontal Bar', 'jpkcom-post-filter' ),
                                'sidebar'  => __( 'Sidebar', 'jpkcom-post-filter' ),
                                'dropdown' => __( 'Dropdown', 'jpkcom-post-filter' ),
                                'columns'  => __( 'Column View', 'jpkcom-post-filter' ),
                            ] as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_layout, $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label><?php esc_html_e( 'Post List Layout', 'jpkcom-post-filter' ); ?></label>
                        <select name="jpkcom_postfilter_layout[list_layout]">
                            <?php foreach ( [
                                'cards'   => __( 'Cards (grid)', 'jpkcom-post-filter' ),
                                'rows'    => __( 'Rows (list)', 'jpkcom-post-filter' ),
                                'minimal' => __( 'Minimal', 'jpkcom-post-filter' ),
                                'theme'   => __( 'Theme Default', 'jpkcom-post-filter' ),
                            ] as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $list_layout, $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Applies only to [jpkcom_postfilter_list] shortcode. "Theme Default" uses your theme\'s native content templates. Auto-injection always uses the theme\'s native loop.', 'jpkcom-post-filter' ); ?></p>
                    </div>
                </div>

                <?php jpkcom_postfilter_layout_tab_advanced( $settings ); ?>
            </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Render: Shortcode Configurator page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_shortcodes(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <div class="notice notice-info" style="margin-left:0;">
            <h3 style="margin:.6em 0 .3em;"><?php esc_html_e( 'How shortcodes work — and where they work', 'jpkcom-post-filter' ); ?></h3>
            <p>
                <?php esc_html_e( 'The three shortcodes always connect to the WordPress archive of the configured post type. The filter bar generates URLs like', 'jpkcom-post-filter' ); ?>
                <code>/news/filter/category-slug/</code>
                <?php esc_html_e( 'and after a filter click the browser navigates to that archive URL — regardless of which page the shortcode is placed on.', 'jpkcom-post-filter' ); ?>
            </p>
            <p><strong><?php esc_html_e( 'Supported use cases:', 'jpkcom-post-filter' ); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php esc_html_e( 'Embed shortcodes inside an archive template via a page builder or the Gutenberg Full Site Editor (FSE). The page IS the archive, so archive URL = current URL.', 'jpkcom-post-filter' ); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: 1: Settings → Reading, 2: example URL */
                        esc_html__( 'For the built-in "post" post type: assign a WordPress page as the "Posts Page" under %1$s and place the shortcodes there. WordPress treats that page as the blog archive, so %2$s and the shortcode page are the same URL.', 'jpkcom-post-filter' ),
                        '<strong>' . esc_html__( 'Settings → Reading', 'jpkcom-post-filter' ) . '</strong>',
                        '<code>/blog/</code>'
                    );
                    ?>
                </li>
                <li><?php esc_html_e( 'Use the Auto-Inject feature (General → Auto-Inject Filter) instead of shortcodes. The plugin then adds the filter UI to archive pages automatically — no shortcode needed.', 'jpkcom-post-filter' ); ?></li>
            </ul>
            <p><strong><?php esc_html_e( 'What does NOT work:', 'jpkcom-post-filter' ); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li>
                    <?php
                    printf(
                        /* translators: example URL */
                        esc_html__( 'Placing the shortcodes on an arbitrary custom page (e.g. %s) whose URL has nothing to do with the post type archive. When a visitor clicks a filter button, the URL will change to the archive URL and the custom page is left behind.', 'jpkcom-post-filter' ),
                        '<code>/my-test-page/</code>'
                    );
                    ?>
                </li>
                <li><?php esc_html_e( 'AJAX results and pagination also point to the archive URL, not to the custom page.', 'jpkcom-post-filter' ); ?></li>
            </ul>
            <p>
                <?php esc_html_e( 'This is an architectural limitation, not a bug. The plugin\'s SEO-friendly URL system is tied to WordPress archive URLs and rewrite rules, which require a fixed base URL per post type.', 'jpkcom-post-filter' ); ?>
            </p>
        </div>

        <div class="jpkcom-shortcode-generator">
            <h2><?php esc_html_e( 'Shortcode Builder', 'jpkcom-post-filter' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Configure options below. The preview updates automatically.', 'jpkcom-post-filter' ); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="sg_post_type"><?php esc_html_e( 'Post Type', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <select id="sg_post_type" name="post_type">
                            <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
                                <option value="<?php echo esc_attr( $pt->name ); ?>"><?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="sg_filter_layout"><?php esc_html_e( 'Filter Layout', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <select id="sg_filter_layout" name="filter_layout">
                            <option value="bar"><?php esc_html_e( 'Horizontal Bar', 'jpkcom-post-filter' ); ?></option>
                            <option value="sidebar"><?php esc_html_e( 'Sidebar', 'jpkcom-post-filter' ); ?></option>
                            <option value="dropdown"><?php esc_html_e( 'Dropdown', 'jpkcom-post-filter' ); ?></option>
                            <option value="columns"><?php esc_html_e( 'Column View', 'jpkcom-post-filter' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="sg_groups"><?php esc_html_e( 'Filter Groups', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <input type="text" id="sg_groups" name="groups" value="" class="regular-text"
                               placeholder="<?php esc_attr_e( 'group-slug-1, group-slug-2', 'jpkcom-post-filter' ); ?>">
                        <p class="description"><?php esc_html_e( 'Comma-separated group slugs to display. Leave empty to show all.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Show Reset Button', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="sg_reset" name="reset" value="true" checked>
                            <?php esc_html_e( 'Display a "Reset filters" link', 'jpkcom-post-filter' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="sg_list_layout"><?php esc_html_e( 'List Layout', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <select id="sg_list_layout" name="list_layout">
                            <option value="cards"><?php esc_html_e( 'Cards', 'jpkcom-post-filter' ); ?></option>
                            <option value="rows"><?php esc_html_e( 'Rows', 'jpkcom-post-filter' ); ?></option>
                            <option value="minimal"><?php esc_html_e( 'Minimal', 'jpkcom-post-filter' ); ?></option>
                            <option value="theme"><?php esc_html_e( 'Theme Default', 'jpkcom-post-filter' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="sg_limit"><?php esc_html_e( 'Posts per Page', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <input type="number" id="sg_limit" name="limit" value="-1" min="-1" class="small-text">
                        <p class="description"><?php esc_html_e( '-1 = all posts', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sg_orderby"><?php esc_html_e( 'Order By', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <select id="sg_orderby" name="orderby">
                            <option value="date"><?php esc_html_e( 'Date', 'jpkcom-post-filter' ); ?></option>
                            <option value="title"><?php esc_html_e( 'Title', 'jpkcom-post-filter' ); ?></option>
                            <option value="menu_order"><?php esc_html_e( 'Menu Order', 'jpkcom-post-filter' ); ?></option>
                        </select>
                        <select id="sg_order" name="order" style="margin-left: 6px;">
                            <option value="DESC"><?php esc_html_e( 'Descending', 'jpkcom-post-filter' ); ?></option>
                            <option value="ASC"><?php esc_html_e( 'Ascending', 'jpkcom-post-filter' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <button type="button" id="sg_generate" class="button button-primary button-large">
                            <?php esc_html_e( 'Generate Shortcodes', 'jpkcom-post-filter' ); ?>
                        </button>
                    </td>
                </tr>
                <tr id="sg_output_row" style="display: none;">
                    <th><?php esc_html_e( 'Generated Shortcodes', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <textarea id="sg_output" readonly class="large-text code" rows="5"></textarea>
                        <p>
                            <button type="button" id="sg_copy" class="button">
                                <?php esc_html_e( 'Copy to Clipboard', 'jpkcom-post-filter' ); ?>
                            </button>
                            <span id="sg_copy_feedback" style="display: none; color: green; margin-left: 10px;">
                                <?php esc_html_e( 'Copied!', 'jpkcom-post-filter' ); ?>
                            </span>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <hr>
        <h2><?php esc_html_e( 'Available Shortcodes', 'jpkcom-post-filter' ); ?></h2>

        <h3><code>[jpkcom_postfilter_filter]</code></h3>
        <p><?php esc_html_e( 'Displays the filter/facets bar.', 'jpkcom-post-filter' ); ?></p>
        <ul>
            <li><code>post_type</code> – <?php esc_html_e( 'Target post type (default: post)', 'jpkcom-post-filter' ); ?></li>
            <li><code>layout</code> – bar / sidebar / dropdown</li>
            <li><code>groups</code> – <?php esc_html_e( 'CSV of group slugs (empty = all)', 'jpkcom-post-filter' ); ?></li>
            <li><code>reset</code> – true / false</li>
            <li><code>class</code> – <?php esc_html_e( 'Extra CSS classes', 'jpkcom-post-filter' ); ?></li>
        </ul>

        <h3><code>[jpkcom_postfilter_list]</code></h3>
        <p><?php esc_html_e( 'Displays the post list.', 'jpkcom-post-filter' ); ?></p>
        <ul>
            <li><code>post_type</code> – <?php esc_html_e( 'Post type (default: post)', 'jpkcom-post-filter' ); ?></li>
            <li><code>layout</code> – cards / rows / minimal</li>
            <li><code>limit</code> – <?php esc_html_e( 'Number of posts (-1 = all)', 'jpkcom-post-filter' ); ?></li>
            <li><code>orderby</code> – date / title / menu_order</li>
            <li><code>order</code> – ASC / DESC</li>
            <li><code>class</code> – <?php esc_html_e( 'Extra CSS classes', 'jpkcom-post-filter' ); ?></li>
        </ul>

        <h3><code>[jpkcom_postfilter_pagination]</code></h3>
        <p><?php esc_html_e( 'Displays pagination for the filtered list.', 'jpkcom-post-filter' ); ?></p>

        <h3><?php esc_html_e( 'Example combination:', 'jpkcom-post-filter' ); ?></h3>
        <pre><code>[jpkcom_postfilter_filter post_type="post" layout="bar" reset="true"]
[jpkcom_postfilter_list post_type="post" layout="cards" limit="12"]
[jpkcom_postfilter_pagination post_type="post"]</code></pre>
    </div>
    <?php
}

/**
 * Render: Cache & Performance page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_cache(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle cache flush actions
    if ( isset( $_POST['jpkcom_postfilter_cache_action'] ) ) {
        check_admin_referer( 'jpkcom_postfilter_cache_action' );

        $action = sanitize_key( $_POST['jpkcom_postfilter_cache_action'] );

        switch ( $action ) {
            case 'flush_all':
                jpkcom_postfilter_cache_flush_group();
                jpkcom_postfilter_settings_delete_cache( '*' );
                // Flush transients
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jpkpf_%' OR option_name LIKE '_transient_timeout_jpkpf_%'" );
                add_settings_error( 'jpkcom_postfilter_cache_messages', 'flushed', __( 'All caches cleared.', 'jpkcom-post-filter' ), 'success' );
                break;

            case 'flush_object':
                jpkcom_postfilter_cache_flush_group();
                add_settings_error( 'jpkcom_postfilter_cache_messages', 'flushed', __( 'Object cache cleared.', 'jpkcom-post-filter' ), 'success' );
                break;

            case 'flush_settings':
                jpkcom_postfilter_settings_delete_cache( '*' );
                add_settings_error( 'jpkcom_postfilter_cache_messages', 'flushed', __( 'Settings file cache cleared.', 'jpkcom-post-filter' ), 'success' );
                break;

            case 'flush_transients':
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jpkpf_%' OR option_name LIKE '_transient_timeout_jpkpf_%'" );
                add_settings_error( 'jpkcom_postfilter_cache_messages', 'flushed', __( 'Transient cache cleared.', 'jpkcom-post-filter' ), 'success' );
                break;

            case 'flush_rewrite':
                flush_rewrite_rules();
                add_settings_error( 'jpkcom_postfilter_cache_messages', 'flushed', __( 'Rewrite rules flushed.', 'jpkcom-post-filter' ), 'success' );
                break;
        }
    }

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'jpkcom_postfilter_cache_messages', 'saved', __( 'Cache settings saved.', 'jpkcom-post-filter' ), 'success' );
    }

    settings_errors( 'jpkcom_postfilter_cache_messages' );

    $stats = jpkcom_postfilter_cache_stats();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'jpkcom_postfilter_cache' );
            do_settings_sections( 'jpkcom-postfilter-cache' );
            submit_button();
            ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Cache Statistics', 'jpkcom-post-filter' ); ?></h2>

        <table class="widefat" style="max-width: 600px;">
            <tbody>
                <tr>
                    <th><?php esc_html_e( 'APCu Available', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo $stats['apcu_available'] ? '<span style="color:green">&#10003; ' . esc_html__( 'Yes', 'jpkcom-post-filter' ) . '</span>' : '<span style="color:red">&#10007; ' . esc_html__( 'No', 'jpkcom-post-filter' ) . '</span>'; ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'External Object Cache', 'jpkcom-post-filter' ); ?></th>
                    <td><?php echo $stats['object_cache_external'] ? esc_html__( 'Yes (Redis/Memcached/other)', 'jpkcom-post-filter' ) : esc_html__( 'No (WordPress default)', 'jpkcom-post-filter' ); ?></td>
                </tr>
                <?php if ( $stats['apcu_available'] ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'APCu Memory Used', 'jpkcom-post-filter' ); ?></th>
                        <td><?php echo esc_html( size_format( (int) ( $stats['apcu_info']['mem_size'] ?? 0 ) ) ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( 'Settings Cache Dir', 'jpkcom-post-filter' ); ?></th>
                    <td>
                        <code><?php echo esc_html( JPKCOM_POSTFILTER_SETTINGS_DIR ); ?></code>
                        <?php if ( is_dir( JPKCOM_POSTFILTER_SETTINGS_DIR ) ) : ?>
                            <span style="color:green"> &#10003; <?php esc_html_e( 'Exists', 'jpkcom-post-filter' ); ?></span>
                        <?php else : ?>
                            <span style="color:orange"> <?php esc_html_e( '(not created yet)', 'jpkcom-post-filter' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <hr>
        <h2><?php esc_html_e( 'Clear Caches', 'jpkcom-post-filter' ); ?></h2>

        <form method="post">
            <?php wp_nonce_field( 'jpkcom_postfilter_cache_action' ); ?>
            <p>
                <button type="submit" name="jpkcom_postfilter_cache_action" value="flush_all" class="button button-primary">
                    <?php esc_html_e( 'Clear All Caches', 'jpkcom-post-filter' ); ?>
                </button>
                &nbsp;
                <button type="submit" name="jpkcom_postfilter_cache_action" value="flush_object" class="button">
                    <?php esc_html_e( 'Clear Object Cache', 'jpkcom-post-filter' ); ?>
                </button>
                &nbsp;
                <button type="submit" name="jpkcom_postfilter_cache_action" value="flush_settings" class="button">
                    <?php esc_html_e( 'Clear Settings File Cache', 'jpkcom-post-filter' ); ?>
                </button>
                &nbsp;
                <button type="submit" name="jpkcom_postfilter_cache_action" value="flush_transients" class="button">
                    <?php esc_html_e( 'Clear Transient Cache', 'jpkcom-post-filter' ); ?>
                </button>
            </p>
            <hr>
            <h2><?php esc_html_e( 'Rewrite Rules', 'jpkcom-post-filter' ); ?></h2>
            <p><?php esc_html_e( 'After changing the URL endpoint or filter group configuration, flush the rewrite rules.', 'jpkcom-post-filter' ); ?></p>
            <p>
                <button type="submit" name="jpkcom_postfilter_cache_action" value="flush_rewrite" class="button">
                    <?php esc_html_e( 'Flush Rewrite Rules', 'jpkcom-post-filter' ); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render: Import / Export page
 *
 * @since 1.0.0
 */
function jpkcom_postfilter_page_import_export(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle import
    $import_message = '';
    if ( isset( $_POST['jpkcom_postfilter_import'] ) && isset( $_FILES['jpkcom_postfilter_import_file'] ) ) {
        check_admin_referer( 'jpkcom_postfilter_import' );

        $file = $_FILES['jpkcom_postfilter_import_file'];

        if ( $file['error'] === UPLOAD_ERR_OK && $file['size'] > 0 ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents( $file['tmp_name'] );
            $data    = json_decode( $content, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
                $valid_groups = [ 'general', 'layout', 'cache', 'filter_groups' ];
                $imported     = 0;

                // Map each group to its sanitize callback so imported data is
                // validated the same way as data submitted via the admin forms.
                $sanitize_callbacks = [
                    'general'       => 'jpkcom_postfilter_sanitize_general_settings',
                    'layout'        => 'jpkcom_postfilter_sanitize_layout_settings',
                    'cache'         => 'jpkcom_postfilter_sanitize_cache_settings',
                    'filter_groups' => 'jpkcom_postfilter_sanitize_filter_groups_settings',
                ];

                foreach ( $valid_groups as $group ) {
                    if ( isset( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
                        $cb        = $sanitize_callbacks[ $group ] ?? null;
                        $sanitized = ( $cb && function_exists( $cb ) ) ? $cb( $data[ $group ] ) : $data[ $group ];
                        jpkcom_postfilter_settings_save( $group, $sanitized );
                        $imported++;
                    }
                }

                $import_message = sprintf(
                    /* translators: %d: number of imported settings groups */
                    __( 'Successfully imported %d settings groups.', 'jpkcom-post-filter' ),
                    $imported
                );
                add_settings_error( 'jpkcom_postfilter_import_messages', 'imported', $import_message, 'success' );
            } else {
                add_settings_error( 'jpkcom_postfilter_import_messages', 'invalid', __( 'Invalid JSON file. Import aborted.', 'jpkcom-post-filter' ), 'error' );
            }
        } else {
            add_settings_error( 'jpkcom_postfilter_import_messages', 'upload_error', __( 'File upload failed.', 'jpkcom-post-filter' ), 'error' );
        }
    }

    settings_errors( 'jpkcom_postfilter_import_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <h2><?php esc_html_e( 'Export Settings', 'jpkcom-post-filter' ); ?></h2>
        <p><?php esc_html_e( 'Download all plugin settings as a JSON file.', 'jpkcom-post-filter' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'jpkcom_postfilter_export' ); ?>
            <input type="hidden" name="jpkcom_postfilter_export" value="1">
            <?php submit_button( __( 'Export Settings as JSON', 'jpkcom-post-filter' ), 'secondary' ); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Import Settings', 'jpkcom-post-filter' ); ?></h2>
        <p><?php esc_html_e( 'Import settings from a previously exported JSON file. Existing settings will be overwritten.', 'jpkcom-post-filter' ); ?></p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'jpkcom_postfilter_import' ); ?>
            <input type="hidden" name="jpkcom_postfilter_import" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="jpkcom_postfilter_import_file"><?php esc_html_e( 'JSON File', 'jpkcom-post-filter' ); ?></label></th>
                    <td>
                        <input
                            type="file"
                            id="jpkcom_postfilter_import_file"
                            name="jpkcom_postfilter_import_file"
                            accept=".json,application/json"
                            required
                        >
                        <p class="description"><?php esc_html_e( 'Select a .json file exported from this plugin.', 'jpkcom-post-filter' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Import Settings', 'jpkcom-post-filter' ), 'primary', 'submit', true, [ 'id' => 'jpkcom-import-btn' ] ); ?>
        </form>
    </div>
    <?php
}
