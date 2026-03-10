<?php
/**
 * Cache Manager
 *
 * Multi-layer caching system for the Post Filter plugin:
 *
 * Layer 1 – Settings File Cache (.ht.jpkcom-post-filter-settings/*.php)
 *   PHP-array via require_once – no parsing overhead, extremely fast.
 *
 * Layer 2 – WordPress Object Cache (wp_cache_*)
 *   Filtered WP_Query results, invalidated on post/term/settings save.
 *
 * Layer 3 – Transients (DB-backed cache)
 *   Taxonomy term lists for filter options. Configurable TTL.
 *
 * Layer 4 – APCu (optional)
 *   When available: settings and frequent queries in shared memory.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

/** Cache group used for wp_cache_* calls. */
const JPKCOM_POSTFILTER_CACHE_GROUP = 'jpkcom_postfilter';

/** Default TTL for transient-based caches (seconds). */
const JPKCOM_POSTFILTER_CACHE_TTL = HOUR_IN_SECONDS;


// ---------------------------------------------------------------------------
// Layer 2 – WordPress Object Cache helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_cache_get' ) ) {
    /**
     * Get a value from the plugin's object cache group
     *
     * Falls back to APCu if available and Layer 2 is not persistent.
     *
     * @since 1.0.0
     *
     * @param string $key   Cache key.
     * @param bool   $found Optional. Whether the key was found (passed by reference).
     * @return mixed Cached value or false on miss.
     */
    function jpkcom_postfilter_cache_get( string $key, bool &$found = false ): mixed {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            $found = false;
            return false;
        }

        if ( ! (bool) jpkcom_postfilter_settings_get( 'cache', 'object_cache_enabled', true ) ) {
            $found = false;
            return false;
        }

        // Try APCu first when Object Cache is not persistent (avoids double-layer on Redis/Memcached)
        if ( jpkcom_postfilter_apcu_available() && ! wp_using_ext_object_cache() ) {
            $value = apcu_fetch( 'jpkpf_' . $key, $apcu_success );
            if ( $apcu_success ) {
                $found = true;
                return $value;
            }
        }

        $value = wp_cache_get( $key, JPKCOM_POSTFILTER_CACHE_GROUP, false, $found );
        return $value;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_cache_set' ) ) {
    /**
     * Set a value in the plugin's object cache group
     *
     * Also stores in APCu when available and Object Cache is not persistent.
     *
     * @since 1.0.0
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $ttl   Time-to-live in seconds (0 = no expiry for object cache).
     * @return bool True on success.
     */
    function jpkcom_postfilter_cache_set( string $key, mixed $value, int $ttl = 0 ): bool {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            return false;
        }

        if ( ! (bool) jpkcom_postfilter_settings_get( 'cache', 'object_cache_enabled', true ) ) {
            return false;
        }

        if ( jpkcom_postfilter_apcu_available() && ! wp_using_ext_object_cache() ) {
            apcu_store( 'jpkpf_' . $key, $value, $ttl );
        }

        return wp_cache_set( $key, $value, JPKCOM_POSTFILTER_CACHE_GROUP, $ttl );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_cache_delete' ) ) {
    /**
     * Delete a value from the plugin's object cache group
     *
     * @since 1.0.0
     *
     * @param string $key Cache key.
     * @return bool True on success.
     */
    function jpkcom_postfilter_cache_delete( string $key ): bool {
        if ( jpkcom_postfilter_apcu_available() ) {
            apcu_delete( 'jpkpf_' . $key );
        }

        return wp_cache_delete( $key, JPKCOM_POSTFILTER_CACHE_GROUP );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_cache_flush_group' ) ) {
    /**
     * Flush all plugin object-cache entries
     *
     * Uses cache group flush when available, otherwise deletes known keys.
     *
     * @since 1.0.0
     * @return void
     */
    function jpkcom_postfilter_cache_flush_group(): void {
        // WordPress 6.1+ supports group flush
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( JPKCOM_POSTFILTER_CACHE_GROUP );
        }

        if ( jpkcom_postfilter_apcu_available() ) {
            $info = apcu_cache_info( true );
            if ( isset( $info['cache_list'] ) && is_array( $info['cache_list'] ) ) {
                foreach ( $info['cache_list'] as $entry ) {
                    $entry_key = $entry['info'] ?? $entry['key'] ?? '';
                    if ( str_starts_with( (string) $entry_key, 'jpkpf_' ) ) {
                        apcu_delete( (string) $entry_key );
                    }
                }
            }
        }
    }
}


// ---------------------------------------------------------------------------
// Layer 3 – Transient helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_transient_get' ) ) {
    /**
     * Get a plugin transient value
     *
     * @since 1.0.0
     *
     * @param string $key Transient key (without plugin prefix).
     * @return mixed Transient value or false on miss.
     */
    function jpkcom_postfilter_transient_get( string $key ): mixed {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            return false;
        }

        if ( ! (bool) jpkcom_postfilter_settings_get( 'cache', 'transient_cache_enabled', true ) ) {
            return false;
        }

        return get_transient( 'jpkpf_' . $key );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_transient_set' ) ) {
    /**
     * Set a plugin transient value
     *
     * @since 1.0.0
     *
     * @param string $key   Transient key (without plugin prefix).
     * @param mixed  $value Value to store.
     * @param int    $ttl   Expiration in seconds. Defaults to JPKCOM_POSTFILTER_CACHE_TTL.
     * @return bool True on success.
     */
    function jpkcom_postfilter_transient_set( string $key, mixed $value, int $ttl = JPKCOM_POSTFILTER_CACHE_TTL ): bool {
        if ( ! JPKCOM_POSTFILTER_CACHE_ENABLED ) {
            return false;
        }

        if ( ! (bool) jpkcom_postfilter_settings_get( 'cache', 'transient_cache_enabled', true ) ) {
            return false;
        }

        return set_transient( 'jpkpf_' . $key, $value, $ttl );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_transient_delete' ) ) {
    /**
     * Delete a plugin transient value
     *
     * @since 1.0.0
     *
     * @param string $key Transient key (without plugin prefix).
     * @return bool True on success.
     */
    function jpkcom_postfilter_transient_delete( string $key ): bool {
        return delete_transient( 'jpkpf_' . $key );
    }
}


// ---------------------------------------------------------------------------
// Layer 4 – APCu helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_apcu_available' ) ) {
    /**
     * Check whether APCu is available and usable
     *
     * @since 1.0.0
     *
     * @return bool True when APCu extension is loaded and enabled.
     */
    function jpkcom_postfilter_apcu_available(): bool {
        static $available = null;

        if ( $available === null ) {
            $available = extension_loaded( 'apcu' )
                && function_exists( 'apcu_fetch' )
                && ini_get( 'apc.enabled' )
                && ( apcu_cache_info( true ) !== false );  // Verify APCu is actually running, not just loaded
        }

        return $available;
    }
}


// ---------------------------------------------------------------------------
// Query cache helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_query_cache_key' ) ) {
    /**
     * Generate a deterministic cache key from WP_Query arguments
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $query_args WP_Query arguments.
     * @param array<string, string[]> $active_filters Active taxonomy filters.
     * @return string MD5-based cache key.
     */
    function jpkcom_postfilter_query_cache_key( array $query_args, array $active_filters = [] ): string {
        $data = [
            'args'    => $query_args,
            'filters' => $active_filters,
        ];
        ksort( $data['args'] );
        ksort( $data['filters'] );
        return 'query_' . md5( serialize( $data ) );
    }
}


// ---------------------------------------------------------------------------
// Cache invalidation hooks
// ---------------------------------------------------------------------------

/**
 * Flush query caches when a post is saved or deleted
 *
 * @since 1.0.0
 */
add_action( 'save_post', static function ( int $post_id ): void {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    jpkcom_postfilter_cache_flush_group();
    jpkcom_postfilter_debug_log( 'Query cache flushed on post save', [ 'post_id' => $post_id ] );
} );

add_action( 'deleted_post', static function ( int $post_id ): void {
    jpkcom_postfilter_cache_flush_group();
    jpkcom_postfilter_debug_log( 'Query cache flushed on post delete', [ 'post_id' => $post_id ] );
} );

/**
 * Flush taxonomy term caches when terms are created/edited/deleted
 *
 * @since 1.0.0
 */
/**
 * Delete both hide_empty variants of a taxonomy term transient
 *
 * @since 1.0.0
 *
 * @param string $taxonomy Taxonomy slug.
 */
$_jpkpf_flush_taxonomy_transients = static function ( string $taxonomy ): void {
    jpkcom_postfilter_transient_delete( 'terms_' . $taxonomy . '_1' );
    jpkcom_postfilter_transient_delete( 'terms_' . $taxonomy . '_0' );
    jpkcom_postfilter_cache_flush_group();
};

add_action( 'created_term', static function ( int $term_id, int $tt_id, string $taxonomy ) use ( $_jpkpf_flush_taxonomy_transients ): void {
    $_jpkpf_flush_taxonomy_transients( $taxonomy );
}, 10, 3 );

add_action( 'edited_term', static function ( int $term_id, int $tt_id, string $taxonomy ) use ( $_jpkpf_flush_taxonomy_transients ): void {
    $_jpkpf_flush_taxonomy_transients( $taxonomy );
}, 10, 3 );

add_action( 'delete_term', static function ( int $term_id, int $tt_id, string $taxonomy ) use ( $_jpkpf_flush_taxonomy_transients ): void {
    $_jpkpf_flush_taxonomy_transients( $taxonomy );
}, 10, 3 );

// Also flush when terms are assigned to / removed from posts (count changes).
add_action( 'set_object_terms', static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy ) use ( $_jpkpf_flush_taxonomy_transients ): void {
    $_jpkpf_flush_taxonomy_transients( $taxonomy );
}, 10, 4 );


// ---------------------------------------------------------------------------
// Cache stats
// ---------------------------------------------------------------------------

if ( ! function_exists( function: 'jpkcom_postfilter_cache_stats' ) ) {
    /**
     * Retrieve cache statistics for the admin panel
     *
     * @since 1.0.0
     *
     * @return array{apcu_available: bool, apcu_info: array<string,mixed>|null, object_cache_external: bool} Stats array.
     */
    function jpkcom_postfilter_cache_stats(): array {
        $apcu_info = null;
        if ( jpkcom_postfilter_apcu_available() ) {
            $raw        = apcu_cache_info( true );
            $apcu_info  = is_array( $raw ) ? $raw : [];
        }

        return [
            'apcu_available'       => jpkcom_postfilter_apcu_available(),
            'apcu_info'            => $apcu_info,
            'object_cache_external' => wp_using_ext_object_cache(),
        ];
    }
}
