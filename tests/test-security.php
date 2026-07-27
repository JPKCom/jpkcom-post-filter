<?php
/**
 * Regression tests for the 1.1.7 hardening.
 *
 * Each case corresponds to a defect that was actually present and is written so
 * that it fails against the pre-1.1.7 implementation. Run with:
 *
 *     php tests/test-security.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.1.7
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', false );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-content' );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

@mkdir( WP_CONTENT_DIR, 0755, true );

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/query-handler.php';

/**
 * Local stand-in for helpers.php: the tests drive the filters directly.
 */
function jpkcom_postfilter_get_active_filters(): array {
	return $GLOBALS['__active_filters'] ?? [];
}

function jpkcom_postfilter_is_filter_request(): bool {
	return ! empty( $GLOBALS['__active_filters'] );
}

require_once __DIR__ . '/../includes/url-routing.php';

// ---------------------------------------------------------------------------
section( 'Settings directory containment (the check used to be a tautology)' );

$base = WP_CONTENT_DIR;

chk( 'the configured settings dir is accepted', jpkcom_postfilter_path_is_inside( JPKCOM_POSTFILTER_SETTINGS_DIR, $base ) );
chk( 'a nested path that does not exist yet is accepted', jpkcom_postfilter_path_is_inside( $base . '/a/b/c', $base ) );
chk( 'a sibling directory is rejected', ! jpkcom_postfilter_path_is_inside( dirname( $base ) . '/somewhere-else', $base ) );
chk( 'an absolute outside path is rejected', ! jpkcom_postfilter_path_is_inside( '/etc/cron.d', $base ) );
chk( 'a traversal out of the base is rejected', ! jpkcom_postfilter_path_is_inside( $base . '/../../etc', $base ) );
chk( 'an empty path is rejected', ! jpkcom_postfilter_path_is_inside( '', $base ) );

// ---------------------------------------------------------------------------
section( 'WP_Query passthrough is typed, not verbatim' );

$args = jpkcom_postfilter_build_query_args( [
	'post_type'  => 'post',
	'meta_query' => [ [ 'key' => 'x', 'value' => 'y' ] ],
] );
chk( 'meta_query is not forwarded', ! isset( $args['meta_query'] ) );

$args = jpkcom_postfilter_build_query_args( [ 'post_type' => 'post', 's' => '<script>alert(1)</script>' ] );
chk( 's is sanitised', isset( $args['s'] ) && ! str_contains( $args['s'], '<script' ), $args['s'] ?? '(absent)' );

$args = jpkcom_postfilter_build_query_args( [ 'post_type' => 'post', 'author' => '3,abc,-7,9' ] );
chk( 'author is coerced to a list of positive ints', ( $args['author__in'] ?? [] ) === [ 3, 7, 9 ], json_encode( $args['author__in'] ?? null ) );

$args = jpkcom_postfilter_build_query_args( [ 'post_type' => 'post', 'year' => '2024abc', 'monthnum' => '-3' ] );
chk( 'year is coerced to an int', ( $args['year'] ?? null ) === 2024, var_export( $args['year'] ?? null, true ) );
chk( 'negative monthnum is dropped', ! isset( $args['monthnum'] ) || $args['monthnum'] > 0 );

$args = jpkcom_postfilter_build_query_args( [ 'post_type' => 'post', 'meta_value' => 'orphan' ] );
chk( 'meta_value without meta_key is dropped', ! isset( $args['meta_value'] ) );

$args = jpkcom_postfilter_build_query_args( [ 'post_type' => 'post', 'meta_key' => 'colour', 'meta_value' => 'blue' ] );
chk( 'meta_key + meta_value survive together', 'colour' === ( $args['meta_key'] ?? '' ) && 'blue' === ( $args['meta_value'] ?? '' ) );

// ---------------------------------------------------------------------------
section( 'Filter URLs with unknown term slugs are noindex' );

$GLOBALS['__taxonomies'] = [ 'category', 'post_tag' ];
$GLOBALS['__terms']      = [
	'category' => [ 'web-design', 'marketing' ],
	'post_tag' => [ 'seo' ],
];

/**
 * Run the registered wp_robots callbacks over a starting array.
 */
function robots_for( array $filters ): array {
	$GLOBALS['__active_filters'] = $filters;
	$robots                      = [ 'index' => true, 'follow' => true ];

	foreach ( $GLOBALS['__filters']['wp_robots'] ?? [] as $cb ) {
		$robots = $cb( $robots );
	}

	return $robots;
}

$r = robots_for( [ 'category' => [ 'web-design' ] ] );
chk( 'known slug stays indexable', empty( $r['noindex'] ) && ! empty( $r['index'] ) );

$r = robots_for( [ 'category' => [ 'web-design', 'marketing' ] ] );
chk( 'several known slugs stay indexable', empty( $r['noindex'] ) );

$r = robots_for( [ 'category' => [ 'does-not-exist' ] ] );
chk( 'unknown slug becomes noindex', ! empty( $r['noindex'] ) && empty( $r['index'] ) );

$r = robots_for( [ 'category' => [ 'web-design', 'does-not-exist' ] ] );
chk( 'one unknown slug among known ones is enough', ! empty( $r['noindex'] ) );

$r = robots_for( [ 'category' => [ 'does-not-exist' ] ] );
chk( 'noindex still allows following links', ! empty( $r['follow'] ) );

$r = robots_for( [ 'nonexistent_tax' => [ 'whatever' ] ] );
chk( 'unknown taxonomy becomes noindex', ! empty( $r['noindex'] ) );

$GLOBALS['__active_filters'] = [];
$r                          = robots_for( [] );
chk( 'non-filter request is untouched', empty( $r['noindex'] ) );

exit( summary() );
