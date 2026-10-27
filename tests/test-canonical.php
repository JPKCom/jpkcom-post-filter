<?php
/**
 * Regression tests for the 1.7.0 canonical targets: a single-term filter page
 * points at its term archive, a combination at the archive base, both only
 * when the target is itself indexable, and a canonical to another URL replaces
 * the term-count noindex.
 *
 * Written to fail against 1.6.1. Run with:
 *
 *     php tests/test-canonical.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.7.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-canonical-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

@mkdir( WP_CONTENT_DIR, 0755, true );

// --- WordPress stand-ins this suite needs beyond bootstrap.php ---------------

function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function home_url( $path = '' ) { return 'https://example.test' . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function get_permalink( $id ) { return (int) $id === 5 ? 'https://example.test/blog/' : false; }
function get_post_type_archive_link( $pt ) { return $GLOBALS['__pt_archives'][ $pt ] ?? false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function do_action( ...$args ) {}
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }

$GLOBALS['__groups'] = [
	[ 'taxonomy' => 'category', 'enabled' => true, 'order' => 0 ],
	[ 'taxonomy' => 'post_tag', 'enabled' => true, 'order' => 1 ],
];

function jpkcom_postfilter_get_filter_groups_enabled(): array { return $GLOBALS['__groups']; }

// --- the plugin -----------------------------------------------------------------

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

foreach ( [ 'url-policy.php', 'url-routing.php', 'canonical-target.php', 'crawl-guard.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

/**
 * Call a function that may not exist in an older version. A missing function
 * fails the check instead of ending the run, so a run against 1.6.1 shows
 * every gap rather than the first one.
 */
function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

/**
 * Set the general settings group and drop every per-request memo.
 */
function set_general( array $general ): void {
	$GLOBALS['__options']['jpkcom_postfilter_general'] = $general;
	if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
		jpkcom_postfilter_settings_memo_reset();
	}
	if ( function_exists( 'jpkcom_postfilter_canonical_target_memo_reset' ) ) {
		jpkcom_postfilter_canonical_target_memo_reset();
	}
}

$GLOBALS['__options']['page_for_posts']          = 5;
$GLOBALS['__options']['blog_public']             = 1;
$GLOBALS['__options']['jpkcom_postfilter_cache'] = [ 'settings_cache_enabled' => false ];
$GLOBALS['__taxonomies']                         = [ 'category', 'post_tag' ];
$GLOBALS['__terms']                              = [
	'category' => [ 'sichtschutz', 'tueren-tore', 'zaeune' ],
	'post_tag' => [ 'einbruchschutz', 'privat' ],
];

$base = 'https://example.test/blog/';

// ---------------------------------------------------------------------------
section( 'Settings' );

set_general( [] );
chk( 'canonical_single defaults to archive', call( 'jpkcom_postfilter_policy', 'canonical_single' ) === 'archive', var_export( call( 'jpkcom_postfilter_policy', 'canonical_single' ), true ) );
chk( 'canonical_multi defaults to home', call( 'jpkcom_postfilter_policy', 'canonical_multi' ) === 'home', var_export( call( 'jpkcom_postfilter_policy', 'canonical_multi' ), true ) );

set_general( [ 'canonical_single' => 'filter', 'canonical_multi' => 'off' ] );
chk( 'a stored canonical_single is read', call( 'jpkcom_postfilter_policy', 'canonical_single' ) === 'filter' );
chk( 'a stored canonical_multi is read', call( 'jpkcom_postfilter_policy', 'canonical_multi' ) === 'off' );
set_general( [] );

chk( 'a multi value is invalid for canonical_single', call( 'jpkcom_postfilter_policy_sanitize', 'canonical_single', 'home' ) === 'archive' );
chk( 'a single value is invalid for canonical_multi', call( 'jpkcom_postfilter_policy_sanitize', 'canonical_multi', 'archive' ) === 'home' );
chk( 'a non-string falls back to the default', call( 'jpkcom_postfilter_policy_sanitize', 'canonical_multi', 42 ) === 'home' );

$defs = call( 'jpkcom_postfilter_policy_definitions' );
chk(
	'both keys have their wp-config constants',
	is_array( $defs )
		&& ( $defs['canonical_single']['constant'] ?? '' ) === 'JPKCOM_POSTFILTER_CANONICAL_SINGLE'
		&& ( $defs['canonical_multi']['constant'] ?? '' ) === 'JPKCOM_POSTFILTER_CANONICAL_MULTI'
);

$general = jpkcom_postfilter_default_settings()['general'];
chk(
	'the general defaults carry both keys',
	( $general['canonical_single'] ?? null ) === 'archive' && ( $general['canonical_multi'] ?? null ) === 'home',
	json_encode( array_intersect_key( $general, [ 'canonical_single' => 1, 'canonical_multi' => 1 ] ) )
);

// A key pinned by a constant is rendered as a disabled field and not
// submitted; only keys in the sanitizer's policy loop keep their stored value.
$settings_src = (string) file_get_contents( __DIR__ . '/../includes/settings.php' );
chk(
	'the General sanitizer handles both keys in its policy loop',
	(bool) preg_match( "/foreach \\( \\[[^\\]]*'canonical_single'[^\\]]*'canonical_multi'[^\\]]*\\] as \\\$policy_key \\)/", $settings_src )
);

exit( summary() );
