<?php
/*
Plugin Name: JPKCom Post Filter
Plugin URI: https://github.com/JPKCom/jpkcom-post-filter
Description: Faceted navigation and filtering for Posts, Pages and Custom Post Types via WordPress taxonomies. Supports SEO-friendly URLs, AJAX filtering with history.pushState, and No-JS fallback.
Version: 1.0.0
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com/
Contributors: JPKCom
Tags: Taxonomy, Tags, Filters, Facets, Navigation
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: jpkcom-post-filter
Domain Path: /languages
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    die;
}

/**
 * Plugin Constants
 *
 * All constants can be overridden in wp-config.php before the plugin loads.
 *
 * @since 1.0.0
 */
if ( ! defined( 'JPKCOM_POSTFILTER_VERSION' ) ) {
    define( 'JPKCOM_POSTFILTER_VERSION', '1.0.0' );
}

if ( ! defined( 'JPKCOM_POSTFILTER_BASENAME' ) ) {
    define( 'JPKCOM_POSTFILTER_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_POSTFILTER_PLUGIN_PATH' ) ) {
    define( 'JPKCOM_POSTFILTER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_POSTFILTER_PLUGIN_URL' ) ) {
    define( 'JPKCOM_POSTFILTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_POSTFILTER_SETTINGS_DIR' ) ) {
    define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );
}

if ( ! defined( 'JPKCOM_POSTFILTER_CACHE_ENABLED' ) ) {
    define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
}


if ( ! defined( 'JPKCOM_POSTFILTER_DEBUG' ) ) {
    define( 'JPKCOM_POSTFILTER_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
}

if ( ! defined( 'JPKCOM_POSTFILTER_URL_ENDPOINT' ) ) {
    define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
}


/**
 * Load plugin includes
 *
 * @since 1.0.0
 */
$jpkcom_postfilter_includes = [
    'includes/helpers.php',
    'includes/cache-manager.php',
    'includes/settings.php',
    'includes/template-loader.php',
    'includes/taxonomies.php',
    'includes/url-routing.php',
    'includes/query-handler.php',
    'includes/filter-injection.php',
    'includes/shortcodes.php',
    'includes/blocks.php',
    'includes/elementor-widgets.php',
    'includes/oxygen-elements.php',
    'includes/assets-enqueue.php',
    'includes/admin-pages.php',
];

foreach ( $jpkcom_postfilter_includes as $include_file ) {
    $full_path = JPKCOM_POSTFILTER_PLUGIN_PATH . $include_file;
    if ( file_exists( $full_path ) ) {
        require_once $full_path;
    } elseif ( JPKCOM_POSTFILTER_DEBUG ) {
        error_log( "[jpkcom-post-filter] Missing include: {$include_file}" );
    }
}


/**
 * Initialize Plugin Updater
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'init', static function (): void {
    $updater_file = JPKCOM_POSTFILTER_PLUGIN_PATH . 'includes/class-plugin-updater.php';

    if ( file_exists( $updater_file ) ) {
        require_once $updater_file;

        if ( class_exists( 'JPKComPostFilterGitUpdate\\JPKComGitPluginUpdater' ) ) {
            new \JPKComPostFilterGitUpdate\JPKComGitPluginUpdater(
                plugin_file: __FILE__,
                current_version: JPKCOM_POSTFILTER_VERSION,
                manifest_url: 'https://jpkcom.github.io/jpkcom-post-filter/plugin_jpkcom-post-filter.json'
            );
        }
    }
}, 5 );


/**
 * Load plugin text domain for translations
 *
 * Loads translation files from the /languages directory.
 *
 * @since 1.0.0
 * @return void
 */
function jpkcom_postfilter_textdomain(): void {
    load_plugin_textdomain(
        'jpkcom-post-filter',
        false,
        dirname( path: JPKCOM_POSTFILTER_BASENAME ) . '/languages'
    );
}

add_action( 'plugins_loaded', 'jpkcom_postfilter_textdomain' );


/**
 * Plugin activation hook
 *
 * Sets up rewrite rules and settings cache directory on activation.
 *
 * @since 1.0.0
 * @return void
 */
function jpkcom_postfilter_activate(): void {
    // Ensure settings cache directory exists
    if ( function_exists( 'jpkcom_postfilter_ensure_settings_dir' ) ) {
        jpkcom_postfilter_ensure_settings_dir();
    }
    // Flush rewrite rules after activation
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'jpkcom_postfilter_activate' );


/**
 * Plugin deactivation hook
 *
 * Flushes rewrite rules on deactivation.
 *
 * @since 1.0.0
 * @return void
 */
function jpkcom_postfilter_deactivate(): void {
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'jpkcom_postfilter_deactivate' );
