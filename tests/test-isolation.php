<?php
/**
 * Regression tests for three cross-context leaks fixed in 1.5.0
 *
 * All three predate 1.5.0 and became more visible with it:
 *
 *  1. Multisite shared ONE settings cache directory: the last site to write
 *     general.php / filter_groups.php defined endpoint, groups and caps for
 *     every other site - and the 1.5.0 request guard redirects or rejects
 *     URLs on exactly that basis.
 *  2. The taxonomy term list was cached per taxonomy only, while WPML and
 *     Polylang narrow get_terms() to the current language: whichever
 *     language warmed the transient decided the filter bar of every language.
 *  3. APCu query-cache keys carried no site context, and APCu is shared by
 *     every site of a network and every install served by the same PHP-FPM
 *     pool: one site could be handed another site's cached WP_Query.
 *
 * Written to fail against the code before these fixes. Run with:
 *
 *     php tests/test-isolation.php
 *
 * The APCu section needs the extension with apc.enable_cli=1 and reports SKIP
 * - never PASS - without it:
 *
 *     php -d apc.enable_cli=1 tests/test-isolation.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.5.0
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/jpkcom-post-filter-tests/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-isolation-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );
define( 'DB_NAME', 'wp' );

@mkdir( WP_CONTENT_DIR, 0755, true );

$GLOBALS['table_prefix'] = 'wp_';
$GLOBALS['__blog']       = 1;
$GLOBALS['__multisite']  = false;
$GLOBALS['__options']    = [];   // blog id => option => value
$GLOBALS['__transients'] = [];   // blog id => key => value
$GLOBALS['__actions']    = [];
$GLOBALS['__language']   = null; // what WPML reports as the current language
$GLOBALS['__terms']      = [];   // language => taxonomy => slugs (what get_terms() returns)

// --- WordPress stand-ins -------------------------------------------------------

function is_multisite() { return $GLOBALS['__multisite']; }
function get_current_blog_id() { return $GLOBALS['__blog']; }
function switch_to_blog( $id ) {
	$GLOBALS['__blog'] = (int) $id;
	foreach ( $GLOBALS['__actions']['switch_blog'] ?? [] as $cb ) {
		$cb();
	}
}
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $GLOBALS['__blog'] ][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $GLOBALS['__blog'] ][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $GLOBALS['__blog'] ][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $GLOBALS['__blog'] ][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $GLOBALS['__blog'] ][ $k ] ); return true; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {}
function do_action( ...$args ) {}
function apply_filters( $tag, $value, ...$rest ) {
	return match ( $tag ) {
		'wpml_current_language' => $GLOBALS['__language'],
		// Like WPML for a user who may not see hidden languages: 'fr' is
		// hidden, so it is missing here and only listed in the setting.
		'wpml_active_languages' => $GLOBALS['__language'] === null ? null : [ 'de' => [], 'en' => [] ],
		'wpml_setting'          => ( $rest[0] ?? '' ) === 'hidden_languages' ? [ 'fr' ] : $value,
		default                 => $value,
	};
}
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function is_wp_error( $t ) { return false; }
function wp_cache_get( $k, $g = '', $f = false, &$found = null ) { $found = false; return false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_using_ext_object_cache() { return false; }
function jpkcom_postfilter_debug_log( $m, $c = null ) {}

class WP_Term {
	public string $slug = '';
}

function get_terms( array $args ): array {
	$out = [];
	foreach ( $GLOBALS['__terms'][ $GLOBALS['__language'] ?? '' ][ $args['taxonomy'] ] ?? [] as $slug ) {
		$t       = new WP_Term();
		$t->slug = $slug;
		$out[]   = $t;
	}
	return $out;
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/cache-manager.php';
require_once __DIR__ . '/../includes/taxonomies.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function chk( string $name, bool $cond, string $detail = '' ): void {
	$GLOBALS[ $cond ? '__pass' : '__fail' ]++;
	echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $cond && $detail !== '' ? "  ({$detail})" : '' ) . "\n";
}

function reset_memo(): void {
	if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
		jpkcom_postfilter_settings_memo_reset();
	}
}

// ---------------------------------------------------------------------------
echo "\n1. Multisite: each site has its own settings cache\n";

$GLOBALS['__multisite'] = true;

foreach ( [ 2 => 'filter', 3 => 'filtern' ] as $blog => $endpoint ) {
	switch_to_blog( $blog );
	update_option( 'jpkcom_postfilter_general', [ 'url_endpoint' => $endpoint ] );
	update_option( 'jpkcom_postfilter_cache', [ 'settings_cache_enabled' => true ] );
}

switch_to_blog( 2 );
reset_memo();
jpkcom_postfilter_settings_write_file( 'general', [ 'url_endpoint' => 'filter' ] );

switch_to_blog( 3 );
reset_memo();
$seen = jpkcom_postfilter_settings_get( 'general', 'url_endpoint', '?' );
chk(
	'site 3 reads its own endpoint after site 2 wrote its cache file',
	$seen === 'filtern',
	'got "' . $seen . '" - the network shared one general.php, so site 3 ran with site 2\'s endpoint, groups and caps'
);

switch_to_blog( 2 );
$seen = jpkcom_postfilter_settings_get( 'general', 'url_endpoint', '?' );
chk( 'and site 2 still reads its own after switching back (switch_blog resets the memo)', $seen === 'filter', 'got "' . $seen . '"' );

chk(
	'the cache files live in one directory per site',
	is_file( JPKCOM_POSTFILTER_SETTINGS_DIR . '/site-2/general.php' ) && is_file( JPKCOM_POSTFILTER_SETTINGS_DIR . '/site-3/general.php' ),
	implode( ', ', glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,*/}*.php', GLOB_BRACE ) ?: [] )
);
chk(
	'the network directory is protected too',
	is_file( JPKCOM_POSTFILTER_SETTINGS_DIR . '/.htaccess' ) && is_file( JPKCOM_POSTFILTER_SETTINGS_DIR . '/site-2/.htaccess' )
);

$GLOBALS['__multisite'] = false;
switch_to_blog( 1 );
reset_memo();
chk(
	'a single site keeps the directory it always had (no migration needed)',
	jpkcom_postfilter_settings_cache_path( 'general' ) === JPKCOM_POSTFILTER_SETTINGS_DIR . '/general.php',
	(string) jpkcom_postfilter_settings_cache_path( 'general' )
);

// ---------------------------------------------------------------------------
echo "\n2. The filter bar's term list is cached per language\n";

switch_to_blog( 1 );
$GLOBALS['__terms'] = [
	'de' => [ 'category' => [ 'nachrichten' ] ],
	'en' => [ 'category' => [ 'news' ] ],
	''   => [ 'category' => [ 'nachrichten', 'news' ] ],
];

$slugs = static fn(): array => array_map( static fn( WP_Term $t ): string => $t->slug, jpkcom_postfilter_get_terms_for_taxonomy( 'category', false ) );

$GLOBALS['__terms']['fr'] = [ 'category' => [ 'nouvelles' ] ];
$GLOBALS['__language']    = 'fr';
$slugs(); // an admin previewing the hidden language warms its list
$GLOBALS['__language'] = 'de';
$de = $slugs();
$GLOBALS['__language'] = 'en';
$en = $slugs();
chk( 'German warms the cache first, English still gets the English terms', $en === [ 'news' ], 'got ' . json_encode( $en ) . ' - the transient was keyed by taxonomy only' );
chk( 'and German its own', $de === [ 'nachrichten' ], json_encode( $de ) );

foreach ( $GLOBALS['__actions']['edited_term'] ?? [] as $cb ) {
	$cb( 1, 1, 'category' );
}
$left = array_filter( array_keys( $GLOBALS['__transients'][1] ?? [] ), static fn( string $k ): bool => str_starts_with( $k, 'jpkpf_terms_category' ) );
chk( 'editing a term flushes the list of every language, hidden ones included', $left === [], 'still cached: ' . implode( ', ', $left ) );

$GLOBALS['__language'] = null;
chk(
	'without a multilingual plugin the key is the pre-1.5.0 one',
	function_exists( 'jpkcom_postfilter_terms_cache_key' ) && jpkcom_postfilter_terms_cache_key( 'category', false, jpkcom_postfilter_terms_cache_language() ) === 'terms_category_0'
);

// ---------------------------------------------------------------------------
echo "\n3. APCu entries are scoped to the site\n";

if ( ! function_exists( 'jpkcom_postfilter_apcu_key' ) ) {
	chk( 'jpkcom_postfilter_apcu_key() exists', false, 'keys were "jpkpf_" + query hash, the same on every site' );
} else {
	switch_to_blog( 2 );
	$k2 = jpkcom_postfilter_apcu_key( 'query_x' );
	switch_to_blog( 3 );
	$k3 = jpkcom_postfilter_apcu_key( 'query_x' );
	chk( 'two sites of a network use different keys for the same query', $k2 !== $k3, "$k2 / $k3" );
	chk( 'and the keys keep the jpkpf_ prefix the cache statistics and flush look for', str_starts_with( $k2, 'jpkpf_' ) && str_starts_with( $k3, 'jpkpf_' ) );
}

if ( ! ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) ) {
	echo "  SKIP  APCu not enabled for the CLI - the round trip below was NOT verified (php -d apc.enable_cli=1)\n";
} else {
	apcu_clear_cache();
	update_option( 'jpkcom_postfilter_cache', [ 'object_cache_enabled' => true ] );
	switch_to_blog( 2 );
	update_option( 'jpkcom_postfilter_cache', [ 'object_cache_enabled' => true ] );
	reset_memo();
	jpkcom_postfilter_cache_set( 'query_same', 'posts of site 2', 60 );

	switch_to_blog( 3 );
	reset_memo();
	$found = false;
	$got   = jpkcom_postfilter_cache_get( 'query_same', $found );
	chk( 'site 3 is not served site 2\'s cached result', $got !== 'posts of site 2', 'got "' . var_export( $got, true ) . '"' );

	jpkcom_postfilter_cache_set( 'query_same', 'posts of site 3', 60 );
	jpkcom_postfilter_cache_flush_group();
	switch_to_blog( 2 );
	reset_memo();
	$got = jpkcom_postfilter_cache_get( 'query_same', $found );
	chk( 'flushing site 3 leaves site 2\'s entries alone', $got === 'posts of site 2', var_export( $got, true ) );
}

// Clean up.
foreach ( array_reverse( glob( WP_CONTENT_DIR . '/{,.}*/{,.}*/{,.}*', GLOB_BRACE ) ?: [] ) as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f );
	}
}
foreach ( array_reverse( glob( WP_CONTENT_DIR . '/{,.}*/{,.}*', GLOB_BRACE ) ?: [] ) as $f ) {
	is_file( $f ) ? @unlink( $f ) : @rmdir( $f );
}
@rmdir( JPKCOM_POSTFILTER_SETTINGS_DIR );
@rmdir( WP_CONTENT_DIR );

printf( "\n  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
