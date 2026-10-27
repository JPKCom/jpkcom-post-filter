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

// Rank Math and Yoast are only consulted while active; both "are" here, and
// each check sets only its own plugin's data.
define( 'RANK_MATH_VERSION', '1.0.280' );
define( 'WPSEO_VERSION', '28.2' );

class WP_Error {}

// Taxonomy objects: name => public / publicly_queryable.
$GLOBALS['__tax_objects'] = [
	'category'   => [ 'public' => true, 'publicly_queryable' => true ],
	'post_tag'   => [ 'public' => true, 'publicly_queryable' => true ],
	'region'     => [ 'public' => true, 'publicly_queryable' => true ],
	'internal'   => [ 'public' => false, 'publicly_queryable' => false ],
	'quiet'      => [ 'public' => true, 'publicly_queryable' => false ],
];
// Post types: name => exclude_from_search, taxonomies.
$GLOBALS['__post_types'] = [
	'post'    => [ 'exclude_from_search' => false, 'taxonomies' => [ 'category', 'post_tag', 'region' ] ],
	'produkt' => [ 'exclude_from_search' => false, 'taxonomies' => [ 'category', 'region', 'internal', 'quiet' ] ],
	'akte'    => [ 'exclude_from_search' => true, 'taxonomies' => [ 'region' ] ],
];
$GLOBALS['__pt_archives'] = [ 'produkt' => 'https://example.test/produkte/' ];
$GLOBALS['__term_meta']   = [];   // term_id => [ key => value ]
$GLOBALS['__post_meta']   = [];   // post_id => [ key => value ]
$GLOBALS['__term_link_error'] = false;

function get_taxonomy( $t ) {
	$o = $GLOBALS['__tax_objects'][ $t ] ?? null;
	return $o === null ? false : (object) ( $o + [ 'name' => $t ] );
}
function get_post_types( $args = [], $output = 'names' ) {
	$out = [];
	foreach ( $GLOBALS['__post_types'] as $name => $pt ) {
		if ( isset( $args['exclude_from_search'] ) && $pt['exclude_from_search'] !== $args['exclude_from_search'] ) {
			continue;
		}
		$out[ $name ] = $name;
	}
	return $out;
}
function get_object_taxonomies( $pt ) { return $GLOBALS['__post_types'][ $pt ]['taxonomies'] ?? []; }
function get_term_by( $field, $value, $taxonomy ) {
	$slugs = $GLOBALS['__terms'][ $taxonomy ] ?? [];
	$i     = array_search( $value, $slugs, true );
	if ( $field !== 'slug' || $i === false ) {
		return false;
	}
	$t           = new WP_Term();
	$t->slug     = (string) $value;
	$t->taxonomy = (string) $taxonomy;
	$t->term_id  = 100 + (int) $i + 10 * (int) array_search( $taxonomy, array_keys( $GLOBALS['__terms'] ), true );
	$t->count    = $GLOBALS['__term_count'][ $taxonomy . ':' . $value ] ?? 3;
	return $t;
}
function get_term_link( $term, $taxonomy = '' ) {
	if ( $GLOBALS['__term_link_error'] ) {
		return new WP_Error();
	}
	$base = [ 'category' => 'kategorie', 'post_tag' => 'tag' ][ $term->taxonomy ] ?? $term->taxonomy;
	return 'https://example.test/' . $base . '/' . $term->slug . '/';
}
function get_term_meta( $id, $key = '', $single = false ) { return $GLOBALS['__term_meta'][ $id ][ $key ] ?? ''; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__post_meta'][ $id ][ $key ] ?? ''; }

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
	'region'   => [ 'nord' ],
	'internal' => [ 'geheim' ],
	'quiet'    => [ 'leise' ],
];
$GLOBALS['__taxonomies'] = array_keys( $GLOBALS['__terms'] );

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

// ---------------------------------------------------------------------------
section( 'Term archive' );

/**
 * Reset the SEO-plugin data and the target memo between checks.
 */
function seo_reset(): void {
	$GLOBALS['__term_meta']       = [];
	$GLOBALS['__post_meta']       = [];
	$GLOBALS['__term_count']      = [];
	$GLOBALS['__term_link_error'] = false;
	unset( $GLOBALS['__options']['rank-math-options-titles'], $GLOBALS['__options']['wpseo_titles'], $GLOBALS['__options']['wpseo_taxonomy_meta'] );
	$GLOBALS['__options']['blog_public']    = 1;
	$GLOBALS['__options']['page_for_posts'] = 5;
	$GLOBALS['__options']['show_on_front']  = 'page';
	set_general( [] );
}

$zaeune = 'https://example.test/kategorie/zaeune/';

seo_reset();
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'a public category archive of posts is a target', $u === $zaeune, var_export( $u, true ) );

$u = call( 'jpkcom_postfilter_term_archive_url', 'internal', 'geheim', 'produkt' );
chk( 'a taxonomy that is not public has no archive', $u === '', var_export( $u, true ) );
$u = call( 'jpkcom_postfilter_term_archive_url', 'quiet', 'leise', 'produkt' );
chk( 'a taxonomy that is not publicly queryable has no archive', $u === '', var_export( $u, true ) );

$GLOBALS['__term_link_error'] = true;
call( 'jpkcom_postfilter_canonical_target_memo_reset' ); // memoised per request
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'get_term_link() failing means no target', $u === '', var_export( $u, true ) );
seo_reset();

$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'produkt' );
chk( 'a category archive lists posts only - not the target of a produkt filter page', $u === '', var_export( $u, true ) );
chk( '... category archives list post', call( 'jpkcom_postfilter_term_archive_lists_post_type', 'category', 'post' ) === true );
chk( '... and not produkt', call( 'jpkcom_postfilter_term_archive_lists_post_type', 'category', 'produkt' ) === false );

$u = call( 'jpkcom_postfilter_term_archive_url', 'region', 'nord', 'produkt' );
chk( 'a custom taxonomy archive lists every searchable post type it belongs to (a superset is fine)', $u === 'https://example.test/region/nord/', var_export( $u, true ) );
$u = call( 'jpkcom_postfilter_term_archive_url', 'region', 'nord', 'akte' );
chk( 'a post type excluded from search is not on the archive', $u === '', var_export( $u, true ) );

$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'gibt-es-nicht', 'post' );
chk( 'an unknown term has no archive', $u === '', var_export( $u, true ) );

$GLOBALS['__options']['rank-math-options-titles'] = [ 'tax_category_custom_robots' => 'on', 'tax_category_robots' => [ 'noindex' ] ];
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: a taxonomy set to noindex is no target', $u === '', var_export( $u, true ) );
$GLOBALS['__options']['rank-math-options-titles'] = [ 'tax_category_custom_robots' => 'off', 'tax_category_robots' => [ 'noindex' ] ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: the taxonomy robots count only with custom robots on', $u === $zaeune, var_export( $u, true ) );
seo_reset();

$t = get_term_by( 'slug', 'zaeune', 'category' );
$GLOBALS['__term_meta'][ $t->term_id ]['rank_math_robots'] = [ 'noindex', 'follow' ];
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: a term set to noindex is no target', $u === '', var_export( $u, true ) );
$GLOBALS['__term_meta'][ $t->term_id ]['rank_math_robots']        = [ 'index' ];
$GLOBALS['__options']['rank-math-options-titles'] = [ 'tax_category_custom_robots' => 'on', 'tax_category_robots' => [ 'noindex' ] ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: a term set to index overrides its taxonomy', $u === $zaeune, var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['rank-math-options-titles'] = [ 'robots_global' => [ 'noindex' ] ];
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: the global robots apply when nothing more specific is set', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['rank-math-options-titles'] = [ 'noindex_empty_taxonomies' => 'on' ];
$GLOBALS['__term_count']['category:zaeune']       = 0;
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Rank Math: an empty term with "noindex empty taxonomies" is no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['wpseo_titles'] = [ 'noindex-tax-category' => true ];
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Yoast: a taxonomy set to noindex is no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['wpseo_taxonomy_meta'] = [ 'category' => [ $t->term_id => [ 'wpseo_noindex' => 'noindex' ] ] ];
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Yoast: a term set to noindex is no target', $u === '', var_export( $u, true ) );
$GLOBALS['__options']['wpseo_taxonomy_meta'] = [ 'category' => [ $t->term_id => [ 'wpseo_noindex' => 'index' ] ] ];
$GLOBALS['__options']['wpseo_titles']        = [ 'noindex-tax-category' => true ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'Yoast: a term set to index overrides its taxonomy', $u === $zaeune, var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['blog_public'] = 0;
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'a site that discourages search engines has no indexable target', $u === '', var_export( $u, true ) );
seo_reset();

// The filter has the last word; it is applied through the test's own runner.
$GLOBALS['__options']['rank-math-options-titles'] = [ 'tax_category_custom_robots' => 'on', 'tax_category_robots' => [ 'noindex' ] ];
$GLOBALS['__run_filters']['jpkcom_postfilter_canonical_archive_indexable'] = static fn( bool $indexable ): bool => true;
$u = call( 'jpkcom_postfilter_term_archive_url', 'category', 'zaeune', 'post' );
chk( 'jpkcom_postfilter_canonical_archive_indexable has the last word', $u === $zaeune, var_export( $u, true ) );
unset( $GLOBALS['__run_filters']['jpkcom_postfilter_canonical_archive_indexable'] );
seo_reset();

// ---------------------------------------------------------------------------
section( 'Archive base' );

seo_reset();
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'the posts page is the base of post', $u === $base, var_export( $u, true ) );

$GLOBALS['__post_meta'][5]['rank_math_robots'] = [ 'noindex' ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' ); // memoised per request
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'Rank Math: a posts page set to noindex is no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['rank-math-options-titles'] = [ 'pt_page_custom_robots' => 'on', 'pt_page_robots' => [ 'noindex' ] ];
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'Rank Math: pages set to noindex make the posts page no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__post_meta'][5]['_yoast_wpseo_meta-robots-noindex'] = '1';
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'Yoast: a posts page set to noindex is no target', $u === '', var_export( $u, true ) );
$GLOBALS['__post_meta'][5]['_yoast_wpseo_meta-robots-noindex'] = '2';
$GLOBALS['__options']['wpseo_titles'] = [ 'noindex-page' => true ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'Yoast: a posts page set to index overrides the page default', $u === $base, var_export( $u, true ) );
seo_reset();

// The blog on the front page: Rank Math's homepage robots decide.
$GLOBALS['__options']['page_for_posts'] = 0;
$GLOBALS['__options']['show_on_front']  = 'posts';
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'the front page is the base when it shows the posts', $u === 'https://example.test/', var_export( $u, true ) );
$GLOBALS['__options']['rank-math-options-titles'] = [ 'homepage_custom_robots' => 'on', 'homepage_robots' => [ 'noindex' ] ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' );
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'Rank Math: a front-page blog set to noindex is no target', $u === '', var_export( $u, true ) );
seo_reset();

$u = call( 'jpkcom_postfilter_base_canonical_url', 'produkt' );
chk( 'a post type archive is the base of its post type', $u === 'https://example.test/produkte/', var_export( $u, true ) );
$u = call( 'jpkcom_postfilter_base_canonical_url', 'akte' );
chk( 'a post type without an archive has no base', $u === '', var_export( $u, true ) );

$GLOBALS['__options']['wpseo_titles'] = [ 'noindex-ptarchive-produkt' => true ];
call( 'jpkcom_postfilter_canonical_target_memo_reset' ); // memoised per request
$u = call( 'jpkcom_postfilter_base_canonical_url', 'produkt' );
chk( 'Yoast: a post type archive set to noindex is no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['rank-math-options-titles'] = [ 'pt_produkt_custom_robots' => 'on', 'pt_produkt_robots' => [ 'noindex' ] ];
$u = call( 'jpkcom_postfilter_base_canonical_url', 'produkt' );
chk( 'Rank Math: a post type archive set to noindex is no target', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['blog_public'] = 0;
$u = call( 'jpkcom_postfilter_base_canonical_url', 'post' );
chk( 'a site that discourages search engines has no indexable base', $u === '', var_export( $u, true ) );
seo_reset();

$GLOBALS['__options']['rank-math-options-titles'] = [ 'pt_produkt_custom_robots' => 'on', 'pt_produkt_robots' => [ 'noindex' ] ];
$GLOBALS['__run_filters']['jpkcom_postfilter_canonical_base_indexable'] = static fn( bool $indexable ): bool => true;
$u = call( 'jpkcom_postfilter_base_canonical_url', 'produkt' );
chk( 'jpkcom_postfilter_canonical_base_indexable has the last word', $u === 'https://example.test/produkte/', var_export( $u, true ) );
unset( $GLOBALS['__run_filters']['jpkcom_postfilter_canonical_base_indexable'] );
seo_reset();

exit( summary() );
