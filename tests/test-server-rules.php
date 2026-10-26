<?php
/**
 * Tests for the 1.6.0 server rules: effective inputs, hash and the two reject
 * patterns a web server applies before PHP runs.
 *
 * The hard rule: a server pattern must never match a URL that the plugin
 * answers with 200 or 301. Looser is fine. The differential fuzz below proves
 * it against the real jpkcom_postfilter_validate_filter_path(), behind an
 * emulation of WordPress's rewrite matching: every rule is tried on the raw
 * path first and then on its urldecode()d form (wp-includes/class-wp.php,
 * WP::parse_request()), in the order url-routing.php registers them. That
 * second try is what makes `%70age`, `jpkpf%2Dfragment` or `%2F` split a path
 * differently from its raw bytes, and why the patterns only touch %-free paths.
 *
 * Run with:
 *
 *     php tests/test-server-rules.php
 *     JPKPF_FUZZ=400000 php tests/test-server-rules.php          # deeper fuzz
 *     JPKPF_WPCORE=/path/to/wordpress php tests/test-server-rules.php
 *         # real wp-includes/formatting.php instead of the sanitize_title stub
 *
 * JPKPF_FUZZ is the number of fuzz cases per layout (180 layouts), split over
 * seeds 1-3. The default is 9000 to keep CI near 25 s; 30 000 per layout
 * takes about 75 s on a desktop machine.
 *
 * @package JPKCom_Post_Filter
 * @since 1.6.0
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/jpkcom-post-filter-tests/' );
define( 'WPINC', 'wp-includes' );
define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'JPKCOM_POSTFILTER_VERSION', '1.6.0-test' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-server-rules-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

@mkdir( WP_CONTENT_DIR, 0755, true );

/** Remove the temp tree, also when a check fails or the run ends early (fix wave item 17). */
function jpkpf_test_rm_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
register_shutdown_function( static fn() => jpkpf_test_rm_tree( WP_CONTENT_DIR ) );

$GLOBALS['__options']    = [];
$GLOBALS['__filters']    = [];   // hook => callables (dispatched by apply_filters below)
$GLOBALS['__taxonomies'] = [ 'category', 'post_tag', 'colour' ];
$GLOBALS['__terms']      = [];
$GLOBALS['__home']       = 'https://example.test';
$GLOBALS['__archives']   = [];   // post type => archive link (CPTs)
$GLOBALS['__pt_objects'] = [];   // post type => object for get_post_type_object()
$GLOBALS['__groups']     = [];

// --- WordPress stand-ins ----------------------------------------------------------

function apply_filters( $tag, $value, ...$args ) {
	if ( $tag === 'sanitize_title' && function_exists( 'sanitize_title_with_dashes' ) ) {
		return sanitize_title_with_dashes( $value, $args[0] ?? '', $args[1] ?? 'display' );
	}
	foreach ( $GLOBALS['__filters'][ $tag ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function has_filter( $tag, $cb = false ) { return ! empty( $GLOBALS['__filters'][ $tag ] ) ? 10 : false; }
function add_filter( $tag, $cb, $p = 10, $a = 1 ) {}   // plugin files register hooks at load time; not dispatched here
function add_action( $tag, $cb, $p = 10, $a = 1 ) {}
function remove_action( $tag, $cb, $p = 10 ) {}
function do_action( ...$args ) {}

$wpcore = (string) getenv( 'JPKPF_WPCORE' );
if ( $wpcore !== '' ) {
	// The real string functions of a local WordPress checkout (read-only).
	function get_locale() { return 'en_US'; }
	function _deprecated_function( ...$a ) {}
	function _doing_it_wrong( ...$a ) {}
	function __( $s, $d = '' ) { return $GLOBALS['__gettext'][ $s ] ?? $s; }
	function _x( $s, $c, $d = '' ) { return $s; }
	function mbstring_binary_safe_encoding( $r = false ) {}
	function reset_mbstring_encoding() {}
	function is_multisite() { return false; }
	foreach ( [ 'compat-utf8.php', 'utf8.php', 'formatting.php' ] as $f ) {
		if ( is_file( rtrim( $wpcore, '/' ) . '/wp-includes/' . $f ) ) {
			require_once rtrim( $wpcore, '/' ) . '/wp-includes/' . $f;
		}
	}
	echo 'Using real formatting.php from ' . $wpcore . "\n";
}

if ( ! function_exists( '__' ) ) {
	// $GLOBALS['__gettext'] stands in for a translation catalogue.
	function __( $s, $d = '' ) { return $GLOBALS['__gettext'][ $s ] ?? $s; }
}
function get_bloginfo( $show = '' ) { return $show === 'language' ? (string) ( $GLOBALS['__language'] ?? 'en-US' ) : ''; }
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	// Same stub as tests/bootstrap.php: keeps percent escapes like the real one.
	function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
function absint( $v ) { return abs( (int) $v ); }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['__taxonomies'], true ); }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function get_query_var( $k, $d = '' ) { return $d; }
function is_wp_error( $t ) { return false; }
function get_current_blog_id() { return 1; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $path = '' ) { return $GLOBALS['__home'] . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function get_permalink( $id ) { return (int) $id === 5 ? $GLOBALS['__home'] . '/blog/' : false; }
function get_page_uri( $id ) { return (int) $id === 5 ? 'blog' : ''; }
function get_post_type_archive_link( $pt ) { return $GLOBALS['__archives'][ $pt ] ?? false; }
function get_post_type_object( $pt ) { return $GLOBALS['__pt_objects'][ $pt ] ?? null; }
function jpkcom_postfilter_debug_log( $msg, $ctx = [] ) {}
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_get_filter_groups_enabled(): array { return $GLOBALS['__groups']; }
function jpkcom_postfilter_query_term_slugs( string $taxonomy ): array { return array_values( $GLOBALS['__terms'][ $taxonomy ] ?? [] ); }
function jpkcom_postfilter_transient_get( string $key ): mixed { return false; }
function jpkcom_postfilter_transient_set( string $key, mixed $value, int $ttl = 0 ): bool { return true; }
function jpkcom_postfilter_fragment_segment(): string {
	// Mirrors fragment-response.php: filter, sanitize_title, fallback.
	$segment = sanitize_title( (string) apply_filters( 'jpkcom_postfilter_fragment_segment', 'jpkpf-fragment' ) );
	return $segment !== '' ? $segment : 'jpkpf-fragment';
}

class WP_Term {
	public string $slug = '';
}

// --- the plugin -------------------------------------------------------------------

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

foreach ( [ 'url-policy.php', 'url-routing.php', 'crawl-guard.php', 'server-rules.php', 'server-rules-render.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

// --- harness ----------------------------------------------------------------------

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function section( string $title ): void { echo "\n" . $title . "\n"; }

function chk( string $name, bool $cond, string $detail = '' ): void {
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  {$name}\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  {$name}" . ( '' !== $detail ? "  ({$detail})" : '' ) . "\n";
	}
}

function summary(): int {
	printf( "\n  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
	return $GLOBALS['__fail'] > 0 ? 1 : 0;
}

/**
 * Call a function that may not exist yet. A missing function fails the check
 * instead of ending the run.
 */
function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

/** A value for a failure detail: strings as they are, anything else as JSON. */
function show( mixed $v, string $key = '' ): string {
	if ( $key !== '' && is_array( $v ) ) {
		$v = $v[ $key ] ?? null;
	}
	if ( is_string( $v ) ) {
		return $v;
	}
	return (string) json_encode( $v, JSON_UNESCAPED_SLASHES );
}

function set_general( array $general ): void {
	$GLOBALS['__options']['jpkcom_postfilter_general'] = $general;
	if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
		jpkcom_postfilter_settings_memo_reset();
	}
}

function set_groups( array $taxonomies ): void {
	$GLOBALS['__groups'] = [];
	foreach ( $taxonomies as $i => $t ) {
		$GLOBALS['__groups'][] = [ 'taxonomy' => $t, 'enabled' => true, 'order' => $i ];
	}
}

/** A post type object as get_post_type_object() returns it, enough for archive_base_regex(). */
function pt_object( string|bool $has_archive, string $slug = '' ): object {
	return (object) [ 'has_archive' => $has_archive, 'rewrite' => $slug !== '' ? [ 'slug' => $slug, 'with_front' => true ] : false ];
}

/** Reset the WordPress side to: posts page /blog/, groups category + post_tag, no filters. */
function reset_site(): void {
	$GLOBALS['__options']    = [
		'page_for_posts'          => 5,
		'jpkcom_postfilter_cache' => [ 'settings_cache_enabled' => false ],
	];
	$GLOBALS['__filters']    = [];
	$GLOBALS['__home']       = 'https://example.test';
	$GLOBALS['__archives']   = [];
	$GLOBALS['__pt_objects'] = [];
	set_groups( [ 'category', 'post_tag' ] );
	set_general( [] );
}

$have = function_exists( 'jpkcom_postfilter_server_reject_patterns' )
	&& function_exists( 'jpkcom_postfilter_server_rule_inputs' )
	&& function_exists( 'jpkcom_postfilter_server_rule_hash' )
	&& function_exists( 'jpkcom_postfilter_server_regex_quote' );

/** Inputs as server_rule_inputs() would return them, for a fixed layout. */
function layout_inputs( int $n, int $g, array $skip, array $bases, array $over = [] ): array {
	$inputs = array_merge( [
		'strict'           => true,
		'max_per_group'    => $n,
		'groups'           => $g,
		'skip_positions'   => $skip,
		'reject_status'    => 410,
		'endpoint'         => 'filter',
		'fragment'         => 'jpkpf-fragment',
		'bases'            => $bases,
		'verdict_filtered' => false,
		'policy_filtered'  => [],
	], $over );
	$inputs['hash'] = (string) call( 'jpkcom_postfilter_server_rule_hash', $inputs );
	return $inputs;
}

// =====================================================================================
section( 'regex quote' );
// =====================================================================================

$q = call( 'jpkcom_postfilter_server_regex_quote', 'a.b+c*d?e(f)g[h]i{j}k|l^m$n\\o-p/q' );
chk( 'quote escapes exactly \\ . + * ? ( ) [ ] { } | ^ $', $q === 'a\\.b\\+c\\*d\\?e\\(f\\)g\\[h\\]i\\{j\\}k\\|l\\^m\\$n\\\\o-p/q', (string) $q );
chk( 'quote leaves - and / alone', call( 'jpkcom_postfilter_server_regex_quote', 'en/my-blog/' ) === 'en/my-blog/' );

$quote_ok = $have;
if ( $have ) {
	mt_srand( 11 );
	for ( $i = 0; $i < 2000 && $quote_ok; $i++ ) {
		$s = '';
		for ( $k = 0, $len = mt_rand( 0, 12 ); $k < $len; $k++ ) {
			$s .= chr( mt_rand( 32, 126 ) );
		}
		$quote_ok = preg_match( '~^' . str_replace( '~', '\\~', jpkcom_postfilter_server_regex_quote( $s ) ) . '$~', $s ) === 1;
	}
}
chk( 'quoted string matches itself literally (2000 random ASCII strings)', $quote_ok );

// =====================================================================================
section( 'rule inputs from WordPress' );
// =====================================================================================

reset_site();
$GLOBALS['__archives']['news']   = 'https://example.test/news/';
$GLOBALS['__pt_objects']['news'] = pt_object( true, 'news' );
$GLOBALS['__archives']['bloeg']  = 'https://example.test/bl%C3%B6g/';
$GLOBALS['__pt_objects']['bloeg'] = pt_object( 'bl%c3%b6g' );
$GLOBALS['__pt_objects']['noarch'] = pt_object( false, 'noarch' );
set_general( [ 'enabled_post_types' => [ 'post', 'news', 'bloeg', 'noarch' ] ] );

$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
$ok     = is_array( $inputs );
$keys   = [ 'strict', 'max_per_group', 'groups', 'skip_positions', 'reject_status', 'endpoint', 'fragment', 'bases', 'verdict_filtered', 'policy_filtered', 'hash' ];
$detail = $ok ? implode( ',', array_keys( $inputs ) ) : (string) $inputs;
chk( 'inputs carry exactly the contract keys', $ok && array_keys( $inputs ) === $keys, $detail );
chk( 'effective defaults: strict, N 2, G 2, 410', $ok && $inputs['strict'] === true && $inputs['max_per_group'] === 2 && $inputs['groups'] === 2 && $inputs['reject_status'] === 410 );
chk( 'endpoint and fragment', $ok && $inputs['endpoint'] === 'filter' && $inputs['fragment'] === 'jpkpf-fragment' );
chk( 'bases: posts page and CPT archive, sorted', $ok && $inputs['bases'] === [ '/blog/', '/news/' ], show( $inputs, 'bases' ) );
chk( 'base with % is dropped from inputs', $ok && ! in_array( '/bl%C3%B6g/', $inputs['bases'], true ) && ! in_array( '/bl%c3%b6g/', $inputs['bases'], true ) );
chk( 'post type without archive gives no base', $ok && count( $inputs['bases'] ) === 2 );
chk( 'no skip positions, no filters', $ok && $inputs['skip_positions'] === [] && $inputs['verdict_filtered'] === false && $inputs['policy_filtered'] === [] );
chk( 'hash is the rule hash of the inputs', $ok && $inputs['hash'] === call( 'jpkcom_postfilter_server_rule_hash', $inputs ) && preg_match( '/^[0-9a-f]{12}$/', $inputs['hash'] ) === 1 );

chk(
	'base path: home without slash, subdirectory, malformed URL dropped',
	call( 'jpkcom_postfilter_server_rule_base_path', 'https://example.test' ) === '/'
	&& call( 'jpkcom_postfilter_server_rule_base_path', 'https://example.test/sub/blog' ) === '/sub/blog/'
	&& call( 'jpkcom_postfilter_server_rule_base_path', 'http:///bad' ) === null
);

reset_site();
$GLOBALS['__options']['page_for_posts'] = 0;
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'front-page blog: bases [/]', is_array( $inputs ) && $inputs['bases'] === [ '/' ], show( $inputs, 'bases' ) );

reset_site();
$GLOBALS['__home'] = 'https://example.test/sub';
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'WordPress in /sub/: home path is part of the base', is_array( $inputs ) && $inputs['bases'] === [ '/sub/blog/' ], show( $inputs, 'bases' ) );
$GLOBALS['__options']['page_for_posts'] = 0;
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'WordPress in /sub/, front-page blog: bases [/sub/]', is_array( $inputs ) && $inputs['bases'] === [ '/sub/' ], show( $inputs, 'bases' ) );

reset_site();
// WPML in directory mode: the posts page resolves per language through
// wpml_permalink (crawl-guard.php, jpkcom_postfilter_archive_base_urls_all_languages()).
$GLOBALS['__filters']['wpml_active_languages'][] = static fn( $v ) => [ 'de' => [], 'en' => [] ];
$GLOBALS['__filters']['wpml_permalink'][]        = static fn( $url, $code = '' ) => $code === 'en' ? str_replace( 'example.test/', 'example.test/en/', (string) $url ) : $url;
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'WPML: one base per language, deduplicated', is_array( $inputs ) && $inputs['bases'] === [ '/blog/', '/en/blog/' ], show( $inputs, 'bases' ) );

reset_site();
set_groups( [ 'category', '', 'post_tag' ] );
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'group without taxonomy: G 3, skip position 1', is_array( $inputs ) && $inputs['groups'] === 3 && $inputs['skip_positions'] === [ 1 ] );

reset_site();
set_general( [ 'max_filters_per_group' => 3, 'strict_urls' => false, 'reject_status' => 404, 'url_endpoint' => 'Auswahl!' ] );
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'stored values: N 3, strict off, 404, endpoint through sanitize_key', is_array( $inputs ) && $inputs['max_per_group'] === 3 && $inputs['strict'] === false && $inputs['reject_status'] === 404 && $inputs['endpoint'] === 'auswahl' );

reset_site();
$GLOBALS['__filters']['jpkcom_postfilter_max_filters_per_group'][] = static fn( $v ) => 4;
$GLOBALS['__filters']['jpkcom_postfilter_request_verdict'][]       = static fn( $v ) => $v;
$GLOBALS['__filters']['jpkcom_postfilter_fragment_segment'][]      = static fn( $v ) => 'Frag Ment';
$inputs = call( 'jpkcom_postfilter_server_rule_inputs' );
chk( 'policy filter: effective value used and key reported', is_array( $inputs ) && $inputs['max_per_group'] === 4 && $inputs['policy_filtered'] === [ 'max_filters_per_group' ] );
chk( 'verdict filter reported', is_array( $inputs ) && $inputs['verdict_filtered'] === true );
chk( 'fragment segment is the filtered, sanitised one', is_array( $inputs ) && $inputs['fragment'] === jpkcom_postfilter_fragment_segment() );

reset_site();

// =====================================================================================
section( 'hash' );
// =====================================================================================

$h2 = layout_inputs( 2, 2, [], [ '/blog/' ] )['hash'];
$h3 = layout_inputs( 3, 2, [], [ '/blog/' ] )['hash'];
chk( 'hash is 12 hex characters', preg_match( '/^[0-9a-f]{12}$/', $h2 ) === 1, $h2 );
chk( 'hash changes with N', $h2 !== $h3 );
$base = layout_inputs( 2, 2, [], [ '/blog/' ] );
$diff = [
	'strict'           => false,
	'groups'           => 3,
	'skip_positions'   => [ 0 ],
	'reject_status'    => 404,
	'endpoint'         => 'auswahl',
	'fragment'         => 'frag',
	'bases'            => [ '/blog/', '/news/' ],
	'verdict_filtered' => true,
];
$changes = true;
foreach ( $diff as $k => $v ) {
	$changes = $changes && call( 'jpkcom_postfilter_server_rule_hash', array_merge( $base, [ $k => $v ] ) ) !== $h2;
}
chk( 'hash changes with every rule input', $changes );
$rev = array_reverse( $base, true );
$h2_ok = preg_match( '/^[0-9a-f]{12}$/', $h2 ) === 1;
chk( 'hash stable across key order', $h2_ok && call( 'jpkcom_postfilter_server_rule_hash', $rev ) === $h2 );
chk( 'hash treats skip_positions and bases as sets', $h2_ok && call( 'jpkcom_postfilter_server_rule_hash', array_merge( $base, [ 'skip_positions' => [ 1, 0 ], 'bases' => [ '/news/', '/blog/' ] ] ) )
	=== call( 'jpkcom_postfilter_server_rule_hash', array_merge( $base, [ 'skip_positions' => [ 0, 1 ], 'bases' => [ '/blog/', '/news/' ] ] ) ) );
chk( 'hash ignores policy_filtered and hash', $h2_ok && call( 'jpkcom_postfilter_server_rule_hash', array_merge( $base, [ 'policy_filtered' => [ 'strict_urls' ], 'hash' => 'x' ] ) ) === $h2 );
$expect = substr( hash( 'sha256', (string) json_encode( [
	'strict'           => true,
	'max_per_group'    => 2,
	'groups'           => 2,
	'skip_positions'   => [],
	'reject_status'    => 410,
	'endpoint'         => 'filter',
	'fragment'         => 'jpkpf-fragment',
	'bases'            => [ '/blog/' ],
	'verdict_filtered' => false,
] ) ), 0, 12 );
chk( 'hash = first 12 hex of sha256(json_encode) in fixed key order', $h2 === $expect, "$h2 vs $expect" );

// =====================================================================================
section( 'reject patterns' );
// =====================================================================================

$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [], [ '/blog/' ] ) );
chk(
	'grammar pinned for N 2, G 2, /blog/ (linear form, segment repetition bounded at G+3)',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:blog/)filter/+(?:(?:\\++|\\+*[^/?%+]+(?:\\++[^/?%+]+)?\\+*)/+){0,5}\\+*[^/?%+]+(?:\\++[^/?%+]+){2}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$'
	&& $p['segments'] === '^/+(?:blog/)filter/+(?:(?:[^/?%]+/+){2}[^/?%+]*\\+|(?:[^/?%]+/+){5}[^/?%])[^?%]*(?:\\?.*)?$',
	show( $p )
);
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 3, 3, [ 0 ], [ '/', '/en/my.blog/' ] ) );
chk(
	'skip position 0: per-group only at positions 1, 2 or past G; bases quoted',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:|en/my\\.blog/)filter/+[^/?%]+/+(?:(?:\\++|\\+*[^/?%+]+(?:\\++[^/?%+]+(?:\\++[^/?%+]+)?)?\\+*)/+){0,5}\\+*[^/?%+]+(?:\\++[^/?%+]+){3}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$',
	show( $p, 'per_group' )
);
// A position without taxonomy in the middle: runs of positions with a taxonomy,
// each segment with exactly one way through (attack A5: one alternative per
// position scanned the tail once per position).
$w = '(?:\\++|\\+*[^/?%+]+\\+*)/+';
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 1, 5, [ 2 ], [ '/blog/' ] ) );
chk(
	'skip position 2 of 5: over-cap segment at 0-1 or 3-8, position 2 passed whatever it holds',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:blog/)filter/+(?:(?:' . $w . '){2}[^/?%]+/+(?:' . $w . '){0,5}|(?:' . $w . '){0,1})\\+*[^/?%+]+(?:\\++[^/?%+]+){1}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$',
	show( $p, 'per_group' )
);
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 1, 2, [ 1 ], [ '/blog/' ] ) );
chk(
	'skip the last position: past G inside the optional run',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:blog/)filter/+(?:' . $w . '[^/?%]+/+(?:' . $w . '){0,3})?\\+*[^/?%+]+(?:\\++[^/?%+]+){1}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$',
	show( $p, 'per_group' )
);

$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [], [ '/blog/' ], [ 'strict' => false ] ) );
chk( 'patterns null when strict off', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [], [ '/blog/' ], [ 'verdict_filtered' => true ] ) );
chk( 'patterns null when verdict filtered', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 0, [], [ '/blog/' ] ) );
chk( 'patterns null without groups', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [], [] ) );
chk( 'patterns null without bases', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 0, 2, [], [ '/blog/' ] ) );
chk( 'per_group null when N = 0, segments stays', is_array( $p ) && $p['per_group'] === null && is_string( $p['segments'] ) );
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [], [ '/blog/' ], [ 'endpoint' => '' ] ) );
chk( 'patterns null without endpoint', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );
// A base nested under another base's endpoint: a URL can then be captured by
// either base's rules, with the segment positions shifted (rule order decides).
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 2, 2, [ 0 ], [ '/', '/filter/x/' ] ) );
chk( 'patterns null when one base lies under another base\'s endpoint', is_array( $p ) && $p['per_group'] === null && $p['segments'] === null );

/**
 * Only the constructs every target engine shares: literals, the escapes the
 * quote helper emits, negated classes, (?:...), * + ? {n} {n,} {n,m}, |, ^, $ and the
 * dot - no lookaround, backreferences, lazy, possessive or atomic forms.
 */
function syntax_ok( string $re ): bool {
	$n       = strlen( $re );
	$last_q  = false; // previous token was a quantifier
	$can_q   = false; // previous token can take a quantifier
	$depth   = 0;
	for ( $i = 0; $i < $n; ) {
		$c = $re[ $i ];
		if ( $c === '\\' ) {
			if ( $i + 1 >= $n || strpos( '\\.+*?()[]{}|^$', $re[ $i + 1 ] ) === false ) {
				return false;
			}
			$i += 2; $last_q = false; $can_q = true;
			continue;
		}
		if ( $c === '[' ) {
			$j = strpos( $re, ']', $i + 2 );
			if ( ( $re[ $i + 1 ] ?? '' ) !== '^' || $j === false ) {
				return false;
			}
			$body = substr( $re, $i + 2, $j - $i - 2 );
			if ( $body === '' || strpbrk( $body, '[\\' ) !== false ) {
				return false;
			}
			$i = $j + 1; $last_q = false; $can_q = true;
			continue;
		}
		if ( $c === '(' ) {
			if ( substr( $re, $i, 3 ) !== '(?:' ) {
				return false;
			}
			$depth++; $i += 3; $last_q = false; $can_q = false;
			continue;
		}
		if ( $c === ')' ) {
			if ( --$depth < 0 ) {
				return false;
			}
			$i++; $last_q = false; $can_q = true;
			continue;
		}
		if ( $c === '*' || $c === '+' || $c === '?' || $c === '{' ) {
			if ( $last_q || ! $can_q ) {
				return false; // lazy/possessive form, or nothing to repeat
			}
			if ( $c === '{' ) {
				if ( preg_match( '/\G\{[0-9]+(?:,[0-9]*)?\}/', $re, $m, 0, $i ) !== 1 ) {
					return false;
				}
				$i += strlen( $m[0] );
			} else {
				$i++;
			}
			$last_q = true; $can_q = false;
			continue;
		}
		if ( $c === '}' || $c === ']' ) {
			return false;
		}
		$i++; $last_q = false; $can_q = ( $c !== '|' && $c !== '^' && $c !== '$' );
	}
	return $depth === 0;
}

$syntax  = $have;
$compile = $have;
$noback  = $have;
if ( $have ) {
	foreach ( [ 1, 2, 3, 5 ] as $n ) {
		foreach ( [ 1, 2, 3, 8 ] as $g ) {
			foreach ( [ [], [ 0 ], [ 1 ], [ 0, 2 ] ] as $skip ) {
				foreach ( [ [ '/blog/' ], [ '/blog/', '/news/' ], [ '/sub/blog/' ], [ '/' ] ] as $bases ) {
					$pp = jpkcom_postfilter_server_reject_patterns( layout_inputs( $n, $g, $skip, $bases ) );
					foreach ( $pp as $re ) {
						$syntax  = $syntax && is_string( $re ) && syntax_ok( $re );
						$compile = $compile && @preg_match( '~' . $re . '~', '' ) !== false;
						$noback  = $noback && ! preg_match( '/\(\?[=!<]|\\\\[1-9]|\\\\g/', (string) $re );
					}
				}
			}
		}
	}
}
chk( 'regex has no lookaround/backreference', $noback );
chk( 'regex uses only the shared RE2/Rust/PCRE subset', $syntax );
chk( 'every layout compiles in PCRE', $compile );

// =====================================================================================
section( 'differential fuzz: server reject implies plugin reject' );
// =====================================================================================

/**
 * A fuzz layout: the inputs plus what WordPress routes.
 *
 * homes: home path => language directory or '' (WPML directory mode puts the
 * language into home_url(), which WP::parse_request() strips first).
 * prefixes: rewrite prefixes relative to home, in registration order.
 */
function fuzz_layouts(): array {
	$base_sets = [
		'blog'      => [ 'bases' => [ '/blog/' ], 'homes' => [ '' ], 'prefixes' => [ 'blog' ] ],
		'blog+news' => [ 'bases' => [ '/blog/', '/news/' ], 'homes' => [ '' ], 'prefixes' => [ 'blog', 'news' ] ],
		'sub/blog'  => [ 'bases' => [ '/sub/blog/' ], 'homes' => [ 'sub' ], 'prefixes' => [ 'blog' ] ],
		'root'      => [ 'bases' => [ '/' ], 'homes' => [ '' ], 'prefixes' => [ '' ] ],
		'wpml'      => [ 'bases' => [ '/blog/', '/en/blog/' ], 'homes' => [ '', 'en' ], 'prefixes' => [ 'blog' ] ],
	];
	$out = [];
	foreach ( [ 1, 2, 3, 5 ] as $n ) {
		foreach ( [ 1, 2, 3 ] as $g ) {
			// A position without taxonomy first, in the middle, and around one
			// (the per-group form is built per run of positions with a taxonomy).
			$skips = [ [], [ 0 ] ];
			if ( $g >= 2 ) {
				$skips[] = [ 1 ];
			}
			if ( $g === 3 ) {
				$skips[] = [ 0, 2 ];
			}
			foreach ( $skips as $skip ) {
				foreach ( $base_sets as $bn => $bs ) {
					$skip_name = '[' . implode( ',', $skip ) . ']';
					$out[]     = $bs + [ 'name' => sprintf( 'N%d G%d skip%s %s', $n, $g, $skip_name, $bn ), 'N' => $n, 'G' => $g, 'skip' => $skip ];
				}
			}
		}
	}
	return $out;
}

/** Taxonomies in position order for a layout. */
function layout_taxonomies( array $layout ): array {
	$taxes = [ 'category', 'post_tag', 'colour', 'size', 'brand', 'shape', 'season', 'stock' ];
	$out   = [];
	for ( $i = 0; $i < $layout['G']; $i++ ) {
		$out[] = in_array( $i, $layout['skip'], true ) ? '' : $taxes[ $i ];
	}
	return $out;
}

const FUZZ_KNOWN = [ 'zaeune', 'sichtschutz', 'treppen', 'balkon', 'a', 'b', 'c', '2024', 'x-y', 'foo_bar', 'c%2b%2b', 'a%2bb', 'x%2by', 'page', 'jpkpf-fragment', '_x', '%c3%bcber' ];

/**
 * What the plugin answers for a raw REQUEST_URI: 'reject' when the validator
 * rejects under every configuration that matters (no slug lookup + redirect,
 * known slugs incl. %2b-slugs + redirect, known slugs + noindex), 'ok' for a
 * 200/301, 'none' when no plugin rule matches (WordPress answers elsewhere).
 */
function plugin_answer( string $uri, array $layout ): string {
	[ $path ] = explode( '?', $uri, 2 );
	$req      = trim( $path, '/' );

	// WPML directory mode: the language directory is the home path.
	$home = '';
	foreach ( $layout['homes'] as $h ) {
		if ( $h !== '' && ( $req === $h || str_starts_with( $req, $h . '/' ) ) ) {
			$home = $h;
		}
	}
	if ( count( $layout['homes'] ) === 1 ) {
		$home = $layout['homes'][0];
	}
	if ( $home !== '' ) {
		$req = trim( (string) preg_replace( '|^' . preg_quote( $home, '|' ) . '|i', '', $req ), '/' );
	}

	static $rules_of = [];
	$key = json_encode( [ $layout['prefixes'], $layout['G'], $layout['skip'] ] );
	if ( ! isset( $rules_of[ $key ] ) ) {
		$e     = preg_quote( 'filter', '#' );
		$f     = preg_quote( 'jpkpf-fragment', '#' );
		$rules = [];
		foreach ( $layout['prefixes'] as $prefix ) {
			$b = $prefix !== '' ? preg_quote( $prefix, '#' ) . '/' : '';
			// url-routing.php registers these six per post type, in this order.
			$rules[] = [ '#^^' . $b . $e . '/(.+?)/page/?([0-9]{1,})/' . $f . '/?$#', true ];
			$rules[] = [ '#^^' . $b . $e . '/(.+?)/' . $f . '/?$#', true ];
			$rules[] = [ '#^^' . $b . 'page/?([0-9]{1,})/' . $f . '/?$#', false ];
			$rules[] = [ '#^^' . $b . $f . '/?$#', false ];
			$rules[] = [ '#^^' . $b . $e . '/(.+?)/page/?([0-9]{1,})/?$#', true ];
			$rules[] = [ '#^^' . $b . $e . '/(.+?)/?$#', true ];
		}
		$rules_of[ $key ] = [ $rules, layout_taxonomies( $layout ) ];
	}
	[ $rules, $taxonomies ] = $rules_of[ $key ];

	$decoded = urldecode( $req );
	foreach ( $rules as [ $rx, $has_path ] ) {
		// WP::parse_request(): "#^$match#" on the raw path, then on urldecode().
		if ( preg_match( $rx, $req, $m ) || preg_match( $rx, $decoded, $m ) ) {
			if ( ! $has_path ) {
				return 'ok';
			}
			// WP_MatchesMapRegex urlencode()s the capture, WP::parse_request()
			// addslashes() and parse_str()s the query.
			parse_str( addslashes( 'jpkcom_filter_path=' . urlencode( $m[1] ) ), $qv );
			$fp = (string) ( $qv['jpkcom_filter_path'] ?? '' );
			if ( $fp === '' ) {
				return 'ok';
			}
			$known = static fn( string $t ): ?array => FUZZ_KNOWN;
			foreach ( [ [ null, 'redirect' ], [ $known, 'redirect' ], [ $known, 'noindex' ] ] as [ $k, $a ] ) {
				if ( jpkcom_postfilter_validate_filter_path( $fp, $taxonomies, $k, $a )['action'] !== 'reject' ) {
					return 'ok';
				}
			}
			return 'reject';
		}
	}
	return 'none';
}

/** Put the plugin into a layout's settings: strict, N, no other caps. */
function apply_layout( array $layout ): void {
	set_groups( layout_taxonomies( $layout ) );
	set_general( [ 'max_filters_per_group' => $layout['N'], 'max_filter_combos' => 0, 'max_terms_total' => 0, 'strict_urls' => true ] );
}

/**
 * One random raw request URI aimed at a layout.
 *
 * Two thirds are %-free, built around the boundaries the patterns decide on
 * (N to N+1 pieces, G to G+4 segments), so most of them are matched or nearly
 * matched; one third draws from the full noise pool of spec 5.4 (%-escapes of
 * rewrite literals, %2B/%2b, %2b-slugs, case, `--`, `.`, empty pieces, `//`,
 * `_`, page and fragment suffixes, query strings, overlength).
 */
function fuzz_uri( array $layout ): string {
	static $clean       = [ 'zaeune', 'sichtschutz', 'treppen', 'balkon', 'a', 'b', 'c', '2024', 'x-y', 'foo_bar' ];
	static $atoms_plain = [ 'A', 'Zaeune', 'a--b', '-a', 'a-', '-', 'a.b', '.', '..', '', '_', '__', '_x', 'page', '2', 'page2', 'jpkpf-fragment', 'xyz-unbekannt', 'ü', 'q', 'a b' ];
	static $atoms_pct   = [ 'c%2b%2b', 'C%2B%2B', 'a%2bb', 'x%2by', '%2b', '%2B', '%70age', 'pag%65', 'jpkpf%2Dfragment', '%c3%bcber', '%C3%BCber', 'a%20b', '%', 'a%2Fb', '%2F', '%25', 'a%' ];
	static $sfx_plain   = [ '', '', '', '/page/2', '/page2', '/page/', '/page/0', '/jpkpf-fragment', '/page/3/jpkpf-fragment', '/page2/jpkpf-fragment',
		'/jpkpf-fragment/page/2', '/2', '/page/2/x', '/_', '/a', '/page//2', '/jpkpf-fragment/jpkpf-fragment' ];
	static $sfx_pct     = [ '/%70age/2', '/pag%65/3/jpkpf-fragment', '/jpkpf%2Dfragment', '/page%2F2', '/page/2%2Fjpkpf-fragment' ];
	static $tail_plain  = [ '/', '/', '', '//', '/?x=1', '?a+b+c+d=1', '/?utm_source=x%2By&y=%', '?', '/?', '/?a/b/c+d+e' ];

	$pick  = static fn( array $a ) => $a[ mt_rand( 0, count( $a ) - 1 ) ];
	$noisy = mt_rand( 0, 2 ) === 0;
	$atoms = $noisy ? array_merge( $atoms_plain, $atoms_pct, $atoms_pct ) : $atoms_plain;
	$joins = $noisy ? [ '+', '+', '+', '%2B', '%2b', '++', '%20' ] : [ '+', '+', '+', '++' ];
	$slash = $noisy ? [ '/', '/', '/', '//', '%2F' ] : [ '/', '/', '/', '//' ];
	$n     = $layout['N'];
	$enc   = $noisy ? '%2B' : '+';

	$segs = [];
	for ( $s = 0, $ns = mt_rand( 1, $layout['G'] + 4 ); $s < $ns; $s++ ) {
		$k = mt_rand( 0, 3 ) ? mt_rand( max( 0, $n - 1 ), $n + 2 ) : mt_rand( 0, $n + 3 );
		if ( $k === 0 ) {
			$segs[] = mt_rand( 0, 1 ) ? '_' : $pick( $atoms );
			continue;
		}
		$seg = mt_rand( 0, 6 ) ? '' : $pick( [ '+', '++', $enc ] );
		for ( $p = 0; $p < $k; $p++ ) {
			$seg .= ( $p ? $pick( $joins ) : '' ) . ( mt_rand( 0, 3 ) ? $pick( $clean ) : $pick( $atoms ) );
		}
		if ( mt_rand( 0, 9 ) === 0 ) {
			$seg .= $pick( [ '+', '++', '+_', strtolower( $enc ) ] );
		}
		if ( mt_rand( 0, 199 ) === 0 ) {
			$seg .= '+' . str_repeat( 'q', 201 );
		}
		$segs[] = $seg;
	}
	$path = '';
	foreach ( $segs as $si => $sg ) {
		$path .= ( $si ? $pick( $slash ) : '' ) . $sg;
	}
	if ( mt_rand( 0, 299 ) === 0 ) {
		$path .= '/' . str_repeat( 'a+', 1000 );
	}

	$base = $pick( $layout['bases'] );
	$lead = $base . 'filter/';
	switch ( mt_rand( 0, 14 ) ) {
		case 0: $lead = '/' . $lead; break;
		case 1: $lead = strtoupper( $base ) . 'filter/'; break;
		case 2: $lead = $base . 'filter//'; break;
		case 3: $lead = $base . 'Filter/'; break;
		case 4: $lead = $base . '%66ilter/'; break;
		case 5: $lead = $base . 'page/2/filter/'; break;
		case 6: $lead = $pick( [ '/', '/blog/', '/news/', '/sub/', '/en/', '/subx/blog/', '/SUB/blog/', '/blogx/', '/en/news/' ] ) . 'filter/'; break;
		case 7: $lead = str_replace( 'blog', 'bl%6Fg', $base ) . 'filter/'; break;
	}

	$suffix = $noisy && mt_rand( 0, 1 ) ? $pick( $sfx_pct ) : $pick( $sfx_plain );

	return $lead . $path . $suffix . $pick( $tail_plain );
}

/**
 * Run the fuzz for one layout.
 *
 * The validator only runs where a pattern matched - that is the only place a
 * violation can be - which keeps the default run CI-sized.
 *
 * @param array<string, string> $regexes    name => delimited PCRE.
 * @param callable|null         $subject_of fn( string $uri ): string - what the
 *                                          server matches against (identity for
 *                                          $request_uri; a request line for
 *                                          Apache's THE_REQUEST).
 * @return array{cases: int, matched: array<string, int>, hits: int, violations: string[]}
 */
function fuzz_layout( array $layout, array $regexes, int $cases, int $seed, ?callable $subject_of = null ): array {
	apply_layout( $layout );
	mt_srand( $seed );
	$out = [ 'cases' => 0, 'matched' => array_fill_keys( array_keys( $regexes ), 0 ), 'hits' => 0, 'violations' => [] ];
	for ( $i = 0; $i < $cases; $i++ ) {
		$uri     = fuzz_uri( $layout );
		$subject = $subject_of ? $subject_of( $uri ) : $uri;
		$hit     = false;
		foreach ( $regexes as $name => $rx ) {
			if ( preg_match( $rx, $subject ) === 1 ) {
				$out['matched'][ $name ]++;
				$hit = true;
			}
		}
		$out['cases']++;
		if ( ! $hit ) {
			continue;
		}
		$out['hits']++;
		$answer = plugin_answer( $uri, $layout );
		if ( $answer !== 'reject' ) {
			$out['violations'][] = $uri . ' => plugin ' . $answer;
		}
	}
	return $out;
}

$per_layout = max( 3, (int) ( getenv( 'JPKPF_FUZZ' ) ?: 9000 ) );
$t0         = microtime( true );
$total      = [ 'cases' => 0, 'per_group' => 0, 'segments' => 0, 'hits' => 0, 'violations' => 0 ];
$vacuous    = [];
$examples   = [];

if ( $have ) {
	foreach ( fuzz_layouts() as $layout ) {
		$pp      = jpkcom_postfilter_server_reject_patterns( layout_inputs( $layout['N'], $layout['G'], $layout['skip'], $layout['bases'] ) );
		$regexes = array_map( static fn( string $re ): string => '~' . $re . '~', array_filter( $pp, 'is_string' ) );
		$lt      = [ 'cases' => 0, 'per_group' => 0, 'segments' => 0, 'hits' => 0, 'violations' => 0 ];
		foreach ( [ 1, 2, 3 ] as $seed ) {
			$r = fuzz_layout( $layout, $regexes, intdiv( $per_layout, 3 ), $seed );
			$lt['cases']      += $r['cases'];
			$lt['per_group']  += $r['matched']['per_group'] ?? 0;
			$lt['segments']   += $r['matched']['segments'] ?? 0;
			$lt['hits']       += $r['hits'];
			$lt['violations'] += count( $r['violations'] );
			$examples          = array_merge( $examples, array_slice( $r['violations'], 0, 5 ) );
		}
		foreach ( $lt as $k => $v ) {
			$total[ $k ] += $v;
		}
		if ( $lt['segments'] === 0 || $lt['per_group'] === 0 ) {
			$vacuous[] = $layout['name'];
		}
		if ( getenv( 'JPKPF_FUZZ_VERBOSE' ) ) {
			printf( "        %-28s cases %7d  per_group %7d  segments %7d  violations %d\n",
				$layout['name'], $lt['cases'], $lt['per_group'], $lt['segments'], $lt['violations'] );
		}
	}
}
$elapsed = microtime( true ) - $t0;

printf(
	"        %d layouts, %d cases (%d per layout, seeds 1-3) in %.1f s: per_group matched %d, segments matched %d, server rejects %d, all checked against the guard: violations %d\n",
	count( fuzz_layouts() ), $total['cases'], $per_layout, $elapsed, $total['per_group'], $total['segments'], $total['hits'], $total['violations']
);
foreach ( array_slice( array_unique( $examples ), 0, 10 ) as $ex ) {
	echo "        violation: $ex\n";
}
$detail = $have ? $total['violations'] . ' violations' : 'function not defined';
chk( 'fuzz: server-reject ⊆ plugin-reject (0 violations)', $have && $total['violations'] === 0, $detail );
$detail = $have ? implode( '; ', array_slice( $vacuous, 0, 5 ) ) : 'function not defined';
chk( 'fuzz is not vacuous: every layout has matches for each pattern', $have && $vacuous === [], $detail );

// The fuzz must be able to fail: a per_group pattern one piece too strict
// (N pieces instead of N+1) has to produce violations, or the oracle is broken.
$mutant_caught = false;
if ( $have ) {
	$layout        = array_values( array_filter( fuzz_layouts(), static fn( array $l ): bool => $l['N'] === 2 ) )[0];
	$pp            = jpkcom_postfilter_server_reject_patterns( layout_inputs( $layout['N'] - 1, $layout['G'], $layout['skip'], $layout['bases'] ) );
	$r             = fuzz_layout( $layout, [ 'mutant' => '~' . $pp['per_group'] . '~' ], 3000, 1 );
	$mutant_caught = count( $r['violations'] ) > 0;
	apply_layout( $layout );
}
chk( 'fuzz detects a per_group pattern one piece too strict', $mutant_caught );

// The spec 5.4 noise cases, one by one, against the N 2 / G 2 / /blog/ layout.
$named_ok = $have;
if ( $have ) {
	$layout  = [ 'name' => 'named', 'N' => 2, 'G' => 2, 'skip' => [], 'bases' => [ '/blog/' ], 'homes' => [ '' ], 'prefixes' => [ 'blog' ] ];
	apply_layout( $layout );
	$pp      = jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ) );
	$named   = [
		'/blog/filter/a+b+c/%70age/2/', '/blog/filter/a+b+c/jpkpf%2Dfragment/', '/blog/filter/a/b/%70age/2/', '/blog/filter/a/b%2Fpage/2/',
		'/blog/filter/x/y/z%2Fjpkpf-fragment/', '/blog/filter/a+b+c/pag%65/3/jpkpf-fragment/', '/blog/filter/a+b%2Bc/', '/blog/filter/c%2b%2b+a+b/',
		'/blog/filter/a/b/page/2/jpkpf-fragment/', '/blog/filter/a/b/page2/jpkpf-fragment/', '/blog/filter/_/a+b/page/3/', '/%62log/filter/a+b+c/',
	];
	foreach ( $named as $uri ) {
		$hit = false;
		foreach ( $pp as $re ) {
			$hit = $hit || ( is_string( $re ) && preg_match( '~' . $re . '~', $uri ) === 1 );
		}
		if ( $hit && plugin_answer( $uri, $layout ) !== 'reject' ) {
			$named_ok = false;
			echo "        named violation: $uri\n";
		}
	}
}
chk( 'named %-encoded and suffix cases never rejected unless the plugin rejects', $named_ok );

// =====================================================================================
section( 'coverage: production bot shapes' );
// =====================================================================================

$caught         = 0;
$plugin_rejects = 0;
$shapes         = 0;
if ( $have ) {
	$layout = [ 'name' => 'bots', 'N' => 2, 'G' => 2, 'skip' => [], 'bases' => [ '/blog/' ], 'homes' => [ '' ], 'prefixes' => [ 'blog' ] ];
	apply_layout( $layout );
	$pp   = jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ) );
	$pool = [ 'balkon', 'balkonueberdachung-ideen', 'einbruchschutz', 'markisen', 'metallcarport', 'moderne-metallgestaltung', 'sichtschutz', 'tore', 'treppen', 'zaeune' ];
	mt_srand( 7 );
	foreach ( [ [ 3, 3 ], [ 4, 3 ], [ 3, 4 ], [ 4, 2 ], [ 2, 4 ] ] as [ $a, $b ] ) {
		for ( $i = 0; $i < 200; $i++ ) {
			$segs = [];
			foreach ( [ $a, $b ] as $k ) {
				shuffle( $pool );
				$sel = array_slice( $pool, 0, $k );
				sort( $sel, SORT_STRING );   // canonical: sorted, distinct
				$segs[] = implode( '+', $sel );
			}
			foreach ( [ '/', '/page/2/', '/jpkpf-fragment/', '/page/2/jpkpf-fragment/', '/?utm_source=x&utm_medium=y' ] as $sfx ) {
				$uri = '/blog/filter/' . implode( '/', $segs ) . $sfx;
				$shapes++;
				if ( plugin_answer( $uri, $layout ) !== 'reject' ) {
					continue;
				}
				$plugin_rejects++;
				foreach ( $pp as $re ) {
					if ( is_string( $re ) && preg_match( '~' . $re . '~', $uri ) === 1 ) {
						$caught++;
						break;
					}
				}
			}
		}
	}
}
printf( "        %d bot-shaped URLs, plugin rejects %d, server catches %d\n", $shapes, $plugin_rejects, $caught );
chk( 'coverage: bot shapes N=2 100 %', $have && $plugin_rejects === $shapes && $caught === $plugin_rejects, "$caught / $plugin_rejects / $shapes" );

// =====================================================================================
section( 'timing' );
// =====================================================================================

/**
 * Slowest pattern/URI pair over adversarial 2000-character URIs (up to 30
 * groups, with and without positions without taxonomy), best of 3
 * per pair so a scheduler hiccup does not count, with the JIT on and off
 * (nginx needs `pcre_jit on`, Apache does not JIT).
 */
function worst_case_ms( bool $jit ): float {
	$jit_ini = $jit ? '1' : '0';
	ini_set( 'pcre.jit', $jit_ini );
	// PHP caches compiled patterns by their source; a delimiter of its own
	// per mode makes the no-JIT run compile without JIT instead of reusing.
	$d      = $jit ? '~' : '`';
	$max    = 0.0;
	$bodies = [ 'a/', 'a+', '+', '/', 'a', 'ab+a/', 'a+/', '+/', 'a++', 'a/+', '_/', 'page/', 'a+b/', 'a+b+c/x/', 'a+b+c+d+e/', '+a+b+c+d+e+/', 'a+b+c+d+e+f/', 'a//', 'ab' ];
	// The last four: many groups with positions without taxonomy (attack A5:
	// every position used to scan the tail on its own, O(G x length)).
	$layouts = [ [ 5, 3, [ 0 ], [ '/blog/', '/en/blog/', '/news/' ] ], [ 2, 2, [], [ '/blog/' ] ], [ 1, 3, [], [ '/' ] ],
		[ 1, 12, [ 0 ], [ '/blog/' ] ], [ 1, 20, [ 0 ], [ '/blog/' ] ], [ 1, 30, [ 0 ], [ '/blog/' ] ], [ 3, 30, [ 2, 9, 17 ], [ '/blog/' ] ] ];
	foreach ( $layouts as [ $n, $g, $skip, $bases ] ) {
		$pp = jpkcom_postfilter_server_reject_patterns( layout_inputs( $n, $g, $skip, $bases ) );
		// The THE_REQUEST form (Apache, OpenLiteSpeed) on the same URI as a request line.
		$pl = function_exists( 'jpkcom_postfilter_server_reject_patterns' ) && ( new ReflectionFunction( 'jpkcom_postfilter_server_reject_patterns' ) )->getNumberOfParameters() >= 2
			? jpkcom_postfilter_server_reject_patterns( layout_inputs( $n, $g, $skip, $bases ), 'request_line' )
			: [];
		foreach ( $bodies as $body ) {
			foreach ( [ '%', '?', '', '%/', '/%' ] as $end ) {
				$lead = $bases[0] . 'filter/';
				$uri  = $lead . substr( str_repeat( $body, 2000 ), 0, 2000 - strlen( $lead ) - strlen( $end ) ) . $end;
				$runs = [];
				foreach ( $pp as $re ) {
					$runs[] = [ $re, $uri ];
				}
				foreach ( $pl as $re ) {
					$runs[] = [ $re, 'GET ' . $uri . ' HTTP/1.1' ];
				}
				foreach ( $runs as [ $re, $subject ] ) {
					if ( ! is_string( $re ) ) {
						continue;
					}
					$rx   = $d . $re . $d;
					$best = INF;
					for ( $r = 0; $r < 3; $r++ ) {
						$t = hrtime( true );
						preg_match( $rx, $subject );
						$best = min( $best, ( hrtime( true ) - $t ) / 1e6 );
					}
					$max = max( $max, $best );
				}
			}
		}
	}
	ini_set( 'pcre.jit', '1' );
	return $max;
}

$max_ms     = $have ? worst_case_ms( true ) : INF;
$max_ms_int = $have ? worst_case_ms( false ) : INF;
printf( "        worst case 2000 chars: %.4f ms with JIT, %.4f ms without\n", $max_ms, $max_ms_int );
chk( 'timing: 2000-char worst case < 1 ms', $max_ms < 1.0, sprintf( '%.3f ms', $max_ms ) );
chk( 'timing: 2000-char worst case < 1 ms without JIT', $max_ms_int < 1.0, sprintf( '%.3f ms', $max_ms_int ) );

// =====================================================================================
section( 'JIT stack: bounded segment repetition (attack A4, nginx pcre_jit on)' );
// =====================================================================================

/*
 * nginx calls pcre2_match() without a match context, so with `pcre_jit on` the
 * JIT runs on PCRE2's default 32 KiB stack. An unbounded `(?:segment)*` used
 * stack per segment: '/blog/filter/' + '+/' x 820 + '%41' (1656 chars, the
 * %41 keeps the segments rule from matching first) returned -46
 * (PCRE2_ERROR_JIT_STACKLIMIT) - HTTP 500 plus an alert log line per request.
 * PHP's preg_* is the same engine with a 192 KiB JIT stack (6x), so the same
 * shapes run up to 6x as long here; a bounded repetition needs no more stack
 * however long the URI is.
 */
$jit_was    = (string) ini_get( 'pcre.jit' );
$jit_errors = [];
$jit_runs   = 0;
ini_set( 'pcre.jit', '1' );
if ( $have ) {
	foreach ( [ 1, 2, 3, 4, 5, 6, 7, 8 ] as $g ) {
		foreach ( [ 1, 2, 3 ] as $n ) {
			$skips = $g >= 2 ? [ [], [ 0 ], [ 1 ] ] : [ [] ];
			foreach ( $skips as $skip ) {
				foreach ( [ 'uri', 'request_line' ] as $subject ) {
					$pp = jpkcom_postfilter_server_reject_patterns( layout_inputs( $n, $g, $skip, [ '/blog/' ] ), $subject );
					foreach ( [ [ '+/', 820 ], [ '+/', 6000 ], [ 'a+b/', 3000 ], [ 'a/', 6000 ], [ '/', 12000 ], [ '+', 12000 ], [ 'a+b+c+d/', 2000 ] ] as [ $unit, $reps ] ) {
						$uri = '/blog/filter/' . str_repeat( $unit, $reps ) . '%41';
						$sub = $subject === 'request_line' ? 'GET ' . $uri . ' HTTP/1.1' : $uri;
						foreach ( $pp as $name => $re ) {
							if ( ! is_string( $re ) ) {
								continue;
							}
							$jit_runs++;
							$r = @preg_match( '~' . $re . '~', $sub );
							if ( $r === false || preg_last_error() !== PREG_NO_ERROR ) {
								$jit_errors[] = sprintf( 'G%d N%d skip[%s] %s %s %s x%d: %s', $g, $n, implode( ',', $skip ), $subject, $name, $unit, $reps, preg_last_error_msg() );
							}
						}
					}
				}
			}
		}
	}
}
ini_set( 'pcre.jit', $jit_was );
printf( "        %d pattern/URI runs with the JIT, %d errors\n", $jit_runs, count( $jit_errors ) );
chk( 'no JIT stack exhaustion for G 1-8 (A4 URI, up to 6x its length)', $have && $jit_runs > 0 && $jit_errors === [], implode( '; ', array_slice( $jit_errors, 0, 3 ) ) );

// =====================================================================================
section( 'THE_REQUEST variant (Apache, .htaccess, OpenLiteSpeed)' );
// =====================================================================================

/*
 * Apache and OpenLiteSpeed match %{THE_REQUEST}: "METHOD URI PROTOCOL". The
 * protocol "HTTP/1.1" contains a `/`, so a class that may cross a space would
 * count "jpkpf-fragment HTTP/" as one more segment: /blog/filter/a/b/page/2/
 * jpkpf-fragment would then be rejected although the plugin serves it. Every
 * class of the request-line form therefore also excludes whitespace.
 */

$have_line = $have && ( new ReflectionFunction( 'jpkcom_postfilter_server_reject_patterns' ) )->getNumberOfParameters() >= 2;

/** The request-line form reduced back to the URI form: must give the URI pattern byte for byte. */
function line_to_uri( string $re ): string {
	if ( ! str_starts_with( $re, '^[A-Z]+ ' ) ) {
		return '__no request-line anchor__';
	}
	$re = '^' . substr( $re, strlen( '^[A-Z]+ ' ) );
	return str_replace( [ '\\s]', '(?:\\?\\S*)?', '(?:\\s|$)' ], [ ']', '(?:\\?.*)?', '$' ], $re );
}

$conv_ok    = $have_line;
$conv_count = 0;
$conv_line  = $have_line;
if ( $have_line ) {
	foreach ( [ 0, 1, 2, 3, 5 ] as $n ) {
		foreach ( [ 1, 2, 3, 8 ] as $g ) {
			foreach ( [ [], [ 0 ], [ 1 ], [ 0, 2 ] ] as $skip ) {
				foreach ( [ [ '/blog/' ], [ '/blog/', '/news/' ], [ '/sub/blog/' ], [ '/' ] ] as $bases ) {
					$in = layout_inputs( $n, $g, $skip, $bases );
					$pu = jpkcom_postfilter_server_reject_patterns( $in );
					$pl = jpkcom_postfilter_server_reject_patterns( $in, 'request_line' );
					foreach ( [ 'per_group', 'segments' ] as $k ) {
						if ( $pu[ $k ] === null || $pl[ $k ] === null ) {
							$conv_ok = $conv_ok && $pu[ $k ] === $pl[ $k ];
							continue;
						}
						$conv_count++;
						$conv_ok = $conv_ok
							&& line_to_uri( $pl[ $k ] ) === $pu[ $k ]
							// every negated class converted, and nothing else may cross a space
							&& substr_count( $pl[ $k ], '[^' ) === substr_count( $pl[ $k ], '\\s]' )
							&& ! str_contains( $pl[ $k ], '.*' )
							&& @preg_match( '~' . $pl[ $k ] . '~', '' ) !== false;
						$conv_line = $conv_line && str_ends_with( $pl[ $k ], '(?:\\s|$)' );
					}
				}
			}
		}
	}
}
chk( 'request line: every class excludes \\s, query \\S*, same grammar otherwise (' . $conv_count . ' patterns)', $conv_ok && $conv_count > 0 );
chk( 'request line: anchored ^[A-Z]+ … (?:\\s|$)', $conv_line && $conv_count > 0 );
$pu = $have ? jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ) ) : [];
$pr = $have ? jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ), 'uri' ) : [ 1 ];
chk( 'request line: the URI form is unchanged and stays the default', $have && $pu === $pr );

// Whitespace-free URIs: the request-line form on "GET <uri> HTTP/1.1" must
// decide exactly like the URI form on <uri>. With the fuzz above that carries
// the 0-violations result over; the fuzz below checks it once more directly.
$equiv_cases = 0;
$equiv_bad   = [];
$suffixed    = 0;
if ( $have_line ) {
	foreach ( fuzz_layouts() as $layout ) {
		$in = layout_inputs( $layout['N'], $layout['G'], $layout['skip'], $layout['bases'] );
		$pu = array_filter( jpkcom_postfilter_server_reject_patterns( $in ), 'is_string' );
		$pl = array_filter( jpkcom_postfilter_server_reject_patterns( $in, 'request_line' ), 'is_string' );
		mt_srand( 11 );
		for ( $i = 0; $i < 1000; $i++ ) {
			$uri = fuzz_uri( $layout );
			if ( preg_match( '/\s/', $uri ) ) {
				continue;
			}
			$equiv_cases++;
			$suffixed += (int) ( str_contains( $uri, 'jpkpf-fragment' ) || str_contains( $uri, '/page' ) );
			foreach ( $pu as $k => $re ) {
				foreach ( [ 'GET ' . $uri . ' HTTP/1.1', 'HEAD ' . $uri . ' HTTP/2.0', 'GET ' . $uri ] as $line ) {
					if ( ( preg_match( '~' . $re . '~', $uri ) === 1 ) !== ( preg_match( '~' . $pl[ $k ] . '~', $line ) === 1 ) ) {
						$equiv_bad[] = $layout['name'] . ' ' . $k . ': ' . $line;
					}
				}
			}
		}
	}
}
printf( "        %d whitespace-free URIs (%d with a page or fragment suffix), each as GET/HTTP1.1, HEAD/HTTP2.0 and a bare request line: %d mismatches\n", $equiv_cases, $suffixed, count( $equiv_bad ) );
foreach ( array_slice( $equiv_bad, 0, 5 ) as $ex ) {
	echo "        mismatch: $ex\n";
}
chk( 'request line decides like the URI form on every whitespace-free URI', $have_line && $equiv_cases > 0 && $equiv_bad === [] );

// The differential fuzz again, the server matching "GET <uri> HTTP/1.1". A
// fuzz URI with raw whitespace (the noise atom 'a b') cannot be sent in a
// request line - the server would split it there - so it becomes an empty
// subject, which no pattern matches.
$as_line = static fn( string $uri ): string => preg_match( '/\s/', $uri ) ? '' : 'GET ' . $uri . ' HTTP/1.1';
$line_total = [ 'cases' => 0, 'hits' => 0, 'violations' => 0 ];
$line_examples = [];
$t0 = microtime( true );
if ( $have_line ) {
	foreach ( fuzz_layouts() as $layout ) {
		$pl      = jpkcom_postfilter_server_reject_patterns( layout_inputs( $layout['N'], $layout['G'], $layout['skip'], $layout['bases'] ), 'request_line' );
		$regexes = array_map( static fn( string $re ): string => '~' . $re . '~', array_filter( $pl, 'is_string' ) );
		$r       = fuzz_layout( $layout, $regexes, max( 1, intdiv( $per_layout, 3 ) ), 4, $as_line );
		$line_total['cases']      += $r['cases'];
		$line_total['hits']       += $r['hits'];
		$line_total['violations'] += count( $r['violations'] );
		$line_examples             = array_merge( $line_examples, array_slice( $r['violations'], 0, 3 ) );
	}
}
printf( "        THE_REQUEST fuzz: %d layouts, %d cases (seed 4) in %.1f s: server rejects %d, violations %d\n",
	count( fuzz_layouts() ), $line_total['cases'], microtime( true ) - $t0, $line_total['hits'], $line_total['violations'] );
foreach ( array_slice( $line_examples, 0, 5 ) as $ex ) {
	echo "        violation: $ex\n";
}
$violations_request_line = $have_line ? $line_total['violations'] : -1;
chk( 'THE_REQUEST variant: fuzz 0 violations', $violations_request_line === 0 && $line_total['hits'] > 0, (string) $violations_request_line );

// Named request lines: page and fragment suffixes must pass, over-cap shapes
// with the same suffixes must still be caught.
$named_line_ok = $have_line;
if ( $have_line ) {
	$layout = [ 'name' => 'named', 'N' => 2, 'G' => 2, 'skip' => [], 'bases' => [ '/blog/' ], 'homes' => [ '' ], 'prefixes' => [ 'blog' ] ];
	apply_layout( $layout );
	$pl   = array_filter( jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ), 'request_line' ), 'is_string' );
	$hits = static function ( string $line ) use ( $pl ): bool {
		foreach ( $pl as $re ) {
			if ( preg_match( '~' . $re . '~', $line ) === 1 ) {
				return true;
			}
		}
		return false;
	};
	foreach ( [ '/blog/filter/a/b/page/2/jpkpf-fragment', '/blog/filter/a/b/page/2/jpkpf-fragment/', '/blog/filter/a/b/jpkpf-fragment', '/blog/filter/a+b/c+d/page/2',
		'/blog/filter/a+b/c/page/2/?x=1', '/blog/filter/a/b/', '/blog/filter/a/b' ] as $uri ) {
		foreach ( [ 'GET ' . $uri . ' HTTP/1.1', 'GET ' . $uri . ' HTTP/1.0', 'GET ' . $uri ] as $line ) {
			if ( $hits( $line ) ) {
				$named_line_ok = false;
				echo "        rejected although served: $line (plugin " . plugin_answer( $uri, $layout ) . ")\n";
			}
		}
	}
	foreach ( [ '/blog/filter/a+b+c/page/2/jpkpf-fragment', '/blog/filter/a/b/c+d/', '/blog/filter/a+b+c/?utm=1', '/blog/filter/x/y+z+w/page/3' ] as $uri ) {
		if ( ! $hits( 'GET ' . $uri . ' HTTP/1.1' ) || plugin_answer( $uri, $layout ) !== 'reject' ) {
			$named_line_ok = false;
			echo "        not caught: $uri\n";
		}
	}
}
chk( 'request line: page/fragment suffixes pass, over-cap shapes with suffixes are caught', $named_line_ok );

// Teeth: the literal conversion (anchors and query only, classes unchanged)
// must fail the same fuzz - otherwise the run above proves nothing about R1.
$literal_caught = 0;
if ( $have ) {
	$layout  = array_values( array_filter( fuzz_layouts(), static fn( array $l ): bool => $l['N'] === 2 && $l['G'] === 2 && $l['skip'] === [] && $l['name'] === 'N2 G2 skip[] blog' ) )[0];
	$pu      = array_filter( jpkcom_postfilter_server_reject_patterns( layout_inputs( 2, 2, [], [ '/blog/' ] ) ), 'is_string' );
	$literal = array_map( static fn( string $re ): string => '~^[A-Z]+ ' . str_replace( '(?:\\?.*)?$', '(?:\\?\\S*)?(?:\\s|$)', substr( $re, 1 ) ) . '~', $pu );
	$r       = fuzz_layout( $layout, $literal, 3000, 4, $as_line );
	$literal_caught = count( $r['violations'] );
	apply_layout( $layout );
}
printf( "        literal conversion (classes without \\s), same fuzz, N 2 G 2 /blog/, 3000 cases: %d violations\n", $literal_caught );
chk( 'fuzz detects the literal conversion that lets classes cross into " HTTP/1.1"', $literal_caught > 0, $literal_caught . ' violations' );

// =====================================================================================
section( 'rate limits and reject body' );
// =====================================================================================

/** rate_limits() or [] while it does not exist. */
function lim( int $w, float $t ): array {
	$r = call( 'jpkcom_postfilter_rate_limits', $w, $t );
	return is_array( $r ) ? $r : [];
}

$anchor = [ 'conn' => 3, 'rate' => 3, 'burst' => 6, 'ip_per_minute' => 30, 'ip_burst' => 10, 'bot_rate' => 2, 'bot_burst' => 20, 'retry_after' => 10 ];
chk( 'limits 8/0.4 match the anchor', lim( 8, 0.4 ) === $anchor, show( lim( 8, 0.4 ) ) );
chk( 'limits clamp workers 0 -> 1', ( lim( 0, 0.4 )['conn'] ?? 0 ) === 1 );
chk( 'limits clamp workers 1000 -> 256 and seconds 0 -> 0.05', ( lim( 1000, 0.0 )['conn'] ?? 0 ) === 96 && ( lim( 1000, 0.0 )['rate'] ?? 0 ) === 768, show( lim( 1000, 0.0 ) ) );
chk( 'limits clamp seconds 99 -> 10 (rate never below 1)', ( lim( 8, 99.0 )['rate'] ?? 0 ) === 1 && ( lim( 8, 99.0 )['bot_rate'] ?? 0 ) === 1 );
chk( 'limits 64/1.0: conn 24, rate 10, burst 20, bot 7', array_intersect_key( lim( 64, 1.0 ), [ 'conn' => 0, 'rate' => 0, 'burst' => 0, 'bot_rate' => 0 ] ) === [ 'conn' => 24, 'rate' => 10, 'burst' => 20, 'bot_rate' => 7 ], show( lim( 64, 1.0 ) ) );
chk( 'limits: NaN seconds fall back to 0.4', lim( 8, NAN ) === $anchor );
$mono = lim( 1, 0.4 ) !== [];
for ( $w = 1; $w < 256 && $mono; $w++ ) {
	$mono = lim( $w + 1, 0.4 )['conn'] >= lim( $w, 0.4 )['conn'] && lim( 8, 0.05 * $w )['rate'] >= lim( 8, 0.05 * ( $w + 1 ) )['rate'];
}
chk( 'limits: conn grows with workers, rate falls with render time', $mono );

reset_site();
$GLOBALS['__language'] = 'de-DE';
$inputs = layout_inputs( 2, 2, [], [ '/blog/', '/news/' ] );
$body   = (string) call( 'jpkcom_postfilter_server_reject_body', $inputs );
chk( 'body: lang, noindex, status title, text, link on bases[0]',
	str_starts_with( $body, '<!doctype html><html lang="de-DE">' ) && str_contains( $body, '<meta name="robots" content="noindex, nofollow">' )
	&& str_contains( $body, '<title>410</title>' ) && str_contains( $body, '>This filter combination is not available.<' ) && str_contains( $body, '<a href="/blog/">Show all posts</a>' ), $body );
chk( 'body has no braces or single quotes', str_starts_with( $body, '<!doctype html>' ) && ! preg_match( "/[{}']/", $body ) );
chk( 'body is one line', str_starts_with( $body, '<!doctype html>' ) && ! preg_match( '/[\r\n\t]/', $body ) );
// Apache sends its string ErrorDocument as `text/html; charset=iso-8859-1`
// (measured on 2.4.69, VirtualHost and .htaccess), and the header beats the
// meta tag: UTF-8 bytes rendered "verfÃ¼gbar". Pure ASCII reads the same in
// every charset a server may announce.
$GLOBALS['__gettext']  = [ 'This filter combination is not available.' => 'Diese Filterkombination ist nicht verfügbar.', 'Show all posts' => 'Alle Beiträge anzeigen – 😀' ];
$german = (string) call( 'jpkcom_postfilter_server_reject_body', $inputs );
$GLOBALS['__gettext']  = [];
chk( 'body: non-ASCII text as numeric entities (charset-independent)',
	preg_match( '/^[\x20-\x7E]+$/D', $german ) === 1 && str_contains( $german, '>Diese Filterkombination ist nicht verf&#252;gbar.<' )
	&& str_contains( $german, '>Alle Beitr&#228;ge anzeigen &#8211; &#128512;</a>' ), $german );
$GLOBALS['__gettext']  = [ 'This filter combination is not available.' => "L'offre {x} \$y \\ 100% <b> \"q\"\n;", 'Show all posts' => 'Alle {Beiträge}' ];
$GLOBALS['__language'] = 'de"DE{x}';
$hostile = (string) call( 'jpkcom_postfilter_server_reject_body', layout_inputs( 2, 2, [], [ '/blog/' ], [ 'reject_status' => 404 ] ) );
$GLOBALS['__gettext']  = [];
$GLOBALS['__language'] = 'en-US';
chk( 'body: a hostile translation cannot reach the config syntax ({ } \' $ \\ % newline)',
	$hostile !== '' && ! preg_match( '/[{}\'$\\\\%\r\n]/', $hostile ) && str_contains( $hostile, 'L&#039;offre' ) && str_contains( $hostile, '&lt;b&gt;' )
	&& str_contains( $hostile, 'lang="deDEx"' ) && str_contains( $hostile, '<title>404</title>' ), $hostile );

// =====================================================================================
section( 'renderers: pcre family' );
// =====================================================================================

/** Snippets for a server, [] while the function does not exist. */
function snips( string $server, array $inputs, string $variant = '' ): array {
	$r = call( 'jpkcom_postfilter_server_snippets', $server, $inputs, lim( 8, 0.4 ) ?: [], $variant );
	return is_array( $r ) ? $r : [];
}
/** All code of a snippet list, or of one snippet id. */
function code_of( array $snippets, string $id = '' ): string {
	$out = [];
	foreach ( $snippets as $s ) {
		if ( $id === '' || ( $s['id'] ?? '' ) === $id ) {
			$out[] = (string) ( $s['code'] ?? '' );
		}
	}
	return implode( "\n", $out );
}
function context_of( array $snippets, string $id = '' ): string {
	$out = [];
	foreach ( $snippets as $s ) {
		if ( $id === '' || ( $s['id'] ?? '' ) === $id ) {
			$out[] = (string) ( $s['context'] ?? '' );
		}
	}
	return implode( "\n", $out );
}
function ids_of( array $snippets ): array {
	return array_map( static fn( array $s ): string => (string) ( $s['id'] ?? '' ), $snippets );
}

reset_site();
$inputs = layout_inputs( 2, 2, [], [ '/blog/', '/news/' ] );
$hash   = $inputs['hash'];
$pat    = $have ? jpkcom_postfilter_server_reject_patterns( $inputs ) : [ 'per_group' => '-', 'segments' => '-' ];
$pline  = $have_line ? jpkcom_postfilter_server_reject_patterns( $inputs, 'request_line' ) : [ 'per_group' => '-', 'segments' => '-' ];
$body   = (string) call( 'jpkcom_postfilter_server_reject_body', $inputs );
$open   = '# >>> jpkcom-post-filter 1.6.0-test rule ' . $hash;
$close  = '# <<< jpkcom-post-filter';

$all = [
	'nginx'     => snips( 'nginx', $inputs ),
	'ispconfig' => snips( 'nginx', $inputs, 'ispconfig' ),
	'apache'    => snips( 'apache', $inputs ),
	'htaccess'  => snips( 'htaccess', $inputs ),
	'ols'       => snips( 'ols', $inputs ),
];
$shape = true;
foreach ( $all as $name => $list ) {
	$shape = $shape && $list !== [] && count( array_unique( ids_of( $list ) ) ) === count( $list );
	$prefix = $name === 'ispconfig' ? 'nginx-' : $name . '-';
	foreach ( $list as $s ) {
		$shape = $shape && is_array( $s ) && array_keys( $s ) === [ 'id', 'title', 'context', 'code' ]
			&& is_string( $s['code'] ) && $s['title'] !== '' && $s['context'] !== '' && str_starts_with( $s['id'], $prefix );
	}
}
chk( 'snippets: list of {id, title, context, code}, ids unique and prefixed by server', $shape );
chk( 'unknown server or variant: no snippets / standard nginx', $all['nginx'] !== [] && snips( 'iis', $inputs ) === [] && snips( 'nginx', $inputs, 'plesk' ) === $all['nginx'] );

// --- nginx standard --------------------------------------------------------------
$nginx    = code_of( $all['nginx'] );
$nginx_rj = code_of( $all['nginx'], 'nginx-reject' );
$nginx_rl = code_of( $all['nginx'], 'nginx-rate' );
$nginx_ht = code_of( $all['nginx'], 'nginx-http' );
chk( 'nginx: both reasons, hash, no-store', str_contains( $nginx, 'server-per-group' ) && str_contains( $nginx, 'server-segments' ) && str_contains( $nginx, $inputs['hash'] ) && str_contains( $nginx, 'no-store' ) );
chk( 'nginx: snippet ids reject, http, rate, robots', ids_of( $all['nginx'] ) === [ 'nginx-reject', 'nginx-http', 'nginx-rate', 'nginx-robots' ], implode( ',', ids_of( $all['nginx'] ) ) );
chk( 'nginx: server-level if on $request_uri with the exact patterns, segments first',
	str_contains( $nginx_rj, "if (\$request_uri ~ \"" . $pat['segments'] . "\") {\n    rewrite ^ /jpkpf-edge-segments? last;\n}" )
	&& str_contains( $nginx_rj, "if (\$request_uri ~ \"" . $pat['per_group'] . "\") {\n    rewrite ^ /jpkpf-edge-per-group? last;\n}" )
	&& strpos( $nginx_rj, 'jpkpf-edge-segments?' ) < strpos( $nginx_rj, 'jpkpf-edge-per-group?' ) );
$loc = "location = /jpkpf-edge-per-group {\n    internal;\n    default_type text/html;\n    charset utf-8;\n"
	. "    add_header X-Robots-Tag \"noindex, nofollow\" always;\n    add_header X-Jpkpf-Reject \"server-per-group\" always;\n"
	. "    add_header X-Jpkpf-Rule \"$hash\" always;\n    add_header Cache-Control \"no-store\" always;\n    return 410 '$body';\n}";
chk( 'nginx: internal location per reason with all four headers and the body', str_contains( $nginx_rj, $loc ) && substr_count( $nginx_rj, 'internal;' ) === 2, $nginx_rj );
$marked = count( array_filter( array_column( $all['nginx'], 'code' ) ) ) === 3;
foreach ( $all['nginx'] as $s ) {
	if ( $s['code'] !== '' ) {
		$marked = $marked && str_starts_with( $s['code'], $open . "\n" ) && str_ends_with( $s['code'], "\n" . $close );
	}
}
chk( 'nginx: every code block between the >>> rule <hash> / <<< comment lines', $marked );
chk( 'nginx: one rate location per base', $nginx_rl !== '' && substr_count( $nginx_rl, 'limit_req_status 429' ) === count( $inputs['bases'] )
	&& str_contains( $nginx_rl, "location /blog/filter/ {\n" ) && str_contains( $nginx_rl, "location /news/filter/ {\n" ) );
chk( 'nginx: rate location carries the limits, 429, @jpkpf_busy and try_files',
	str_contains( $nginx_rl, 'limit_req  zone=jpkpf_site burst=6 nodelay;' ) && str_contains( $nginx_rl, 'limit_req  zone=jpkpf_ip burst=10 nodelay;' )
	&& str_contains( $nginx_rl, 'limit_req  zone=jpkpf_bots burst=20 nodelay;' ) && str_contains( $nginx_rl, 'limit_conn jpkpf_conn 3;' )
	&& str_contains( $nginx_rl, 'limit_conn_status 429;' ) && str_contains( $nginx_rl, 'error_page 429 = @jpkpf_busy;' )
	&& str_contains( $nginx_rl, 'try_files $uri $uri/ /index.php?$args;' ) && substr_count( $nginx_rl, 'location @jpkpf_busy {' ) === 1
	&& str_contains( $nginx_rl, 'add_header Retry-After 10 always;' ) && str_contains( $nginx_rl, 'return 429 ' ), $nginx_rl );
$busy = substr( $nginx_rl, (int) strpos( $nginx_rl, 'location @jpkpf_busy {' ) );
$busy = substr( $busy, 0, (int) strpos( $busy, "\n}" ) );
// R11: a 429 for a valid URL must not tell search engines to drop it.
chk( 'nginx: @jpkpf_busy sends Retry-After and no-store, but no X-Robots-Tag',
	str_contains( $busy, 'add_header Retry-After 10 always;' ) && str_contains( $busy, 'add_header Cache-Control "no-store" always;' ) && ! str_contains( $busy, 'X-Robots-Tag' ) && str_contains( $busy, 'return 429 ' ), $busy );
chk( 'nginx: commented dry-run switch per limit module and location', substr_count( $nginx_rl, '# limit_req_dry_run on;' ) === 2 && substr_count( $nginx_rl, '# limit_conn_dry_run on;' ) === 2 );
chk( 'nginx: http context maps, three limit_req_zone, one limit_conn_zone',
	str_contains( $nginx_ht, 'map $http_user_agent $jpkpf_searchbot {' ) && substr_count( $nginx_ht, 'map "$jpkpf_searchbot:$http_cookie"' ) === 3
	&& substr_count( $nginx_ht, 'limit_req_zone ' ) === 3 && substr_count( $nginx_ht, 'limit_conn_zone ' ) === 1
	&& str_contains( $nginx_ht, 'rate=30r/m' ) && str_contains( $nginx_ht, 'rate=3r/s' ) && str_contains( $nginx_ht, 'rate=2r/s' )
	&& str_contains( $nginx_ht, '"0:" $binary_remote_addr' ) && str_contains( $nginx_ht, '"1:" $server_name' ), $nginx_ht );
chk( 'nginx: rejected requests never reach the rate location (internal exact location)', str_contains( $nginx_rj, 'internal;' ) && ! str_contains( $nginx_rj, 'limit_' ) );
chk( 'nginx: http part says root, nginx-light/nginx -V', stripos( context_of( $all['nginx'], 'nginx-http' ), 'root' ) !== false && str_contains( context_of( $all['nginx'], 'nginx-http' ), 'nginx -V' ) && str_contains( context_of( $all['nginx'], 'nginx-http' ), 'nginx-light' ) );
chk( 'nginx: robots is a hint without code', code_of( $all['nginx'], 'nginx-robots' ) === '' && str_contains( context_of( $all['nginx'], 'nginx-robots' ), 'try_files $uri /index.php?$args;' ) );

// --- nginx ISPConfig -------------------------------------------------------------
$isp    = code_of( $all['ispconfig'] );
$isp_rj = code_of( $all['ispconfig'], 'nginx-reject' );
chk( 'nginx ispconfig: ##merge## last token', preg_match( '~location = /robots\.txt \{ ##merge##\n~', $isp ) === 1 );
chk( 'nginx ispconfig: robots merge carries try_files', str_starts_with( $isp_rj, "location = /robots.txt { ##merge##\n    try_files \$uri /index.php?\$args;\n}\n" ) );
chk( 'nginx ispconfig: no location /', $isp !== '' && ! preg_match( '~location /\s*\{~', $isp ) );
// The http context file is installed by root, not pasted into the field.
$isp_field     = code_of( $all['ispconfig'], 'nginx-reject' ) . "\n" . code_of( $all['ispconfig'], 'nginx-rate' );
$no_hash_lines = $isp_rj !== '';
foreach ( explode( "\n", $isp_field ) as $l ) {
	$no_hash_lines = $no_hash_lines && ! str_starts_with( ltrim( $l ), '#' );
}
chk( 'nginx ispconfig: no line of the field text starts with # (ISPConfig strips them)', $no_hash_lines );
chk( 'nginx ispconfig: same reject and rate content as standard nginx',
	str_contains( $isp_rj, $loc ) && str_contains( $isp_rj, $pat['per_group'] ) && substr_count( code_of( $all['ispconfig'], 'nginx-rate' ), 'limit_req_status 429' ) === 2
	&& code_of( $all['ispconfig'], 'nginx-http' ) === $nginx_ht );
chk( 'nginx ispconfig: dry-run switches as trailing comments', substr_count( code_of( $all['ispconfig'], 'nginx-rate' ), '; # limit_req_dry_run on;' ) === 2 && substr_count( code_of( $all['ispconfig'], 'nginx-rate' ), '; # limit_conn_dry_run on;' ) === 2 );
// ISPConfig tracks location blocks by counting braces per line: a brace inside
// a directive line (the return body) would end or open a block early.
$braces_ok = $isp !== '';
foreach ( explode( "\n", $isp ) as $l ) {
	if ( str_contains( $l, 'return ' ) || str_contains( $l, 'add_header' ) ) {
		$braces_ok = $braces_ok && ! preg_match( '/[{}]/', $l );
	}
}
chk( 'nginx ispconfig: no brace inside a return/add_header line', $braces_ok );
chk( 'nginx ispconfig: context names {DOMAIN} log path, no comments in the field, no double paste',
	str_contains( context_of( $all['ispconfig'] ), '/var/log/ispconfig/httpd/{DOMAIN}/' ) && str_contains( context_of( $all['ispconfig'], 'nginx-reject' ), '##merge##' ) );

// --- Apache vhost ----------------------------------------------------------------
$apache    = code_of( $all['apache'] );
$apache_rj = code_of( $all['apache'], 'apache-reject' );
chk( 'apache: snippet ids reject, qos, modsec, robots', ids_of( $all['apache'] ) === [ 'apache-reject', 'apache-qos', 'apache-modsec', 'apache-robots' ], implode( ',', ids_of( $all['apache'] ) ) );
chk( 'apache: THE_REQUEST, REDIRECT guard',
	str_contains( $apache_rj, "RewriteEngine On\n" )
	&& str_contains( $apache_rj, "RewriteCond %{ENV:REDIRECT_STATUS} ^\$\nRewriteCond %{THE_REQUEST} \"" . $pline['segments'] . "\"\nRewriteRule ^/ - [E=JPKPF_REJECT:server-segments,R=410,L]" )
	&& str_contains( $apache_rj, "RewriteCond %{ENV:REDIRECT_STATUS} ^\$\nRewriteCond %{THE_REQUEST} \"" . $pline['per_group'] . "\"\nRewriteRule ^/ - [E=JPKPF_REJECT:server-per-group,R=410,L]" )
	&& str_starts_with( (string) $pline['per_group'], '^[A-Z]+ /+' ), $apache_rj );
$hdr_ok = true;
foreach ( [ 'JPKPF_REJECT', 'REDIRECT_JPKPF_REJECT' ] as $env ) {
	$hdr_ok = $hdr_ok
		&& str_contains( $apache_rj, "Header always set X-Robots-Tag \"noindex, nofollow\" env=$env" )
		&& str_contains( $apache_rj, "Header always set X-Jpkpf-Reject \"%{" . $env . "}e\" env=$env" )
		&& str_contains( $apache_rj, "Header always set X-Jpkpf-Rule \"$hash\" env=$env" )
		&& str_contains( $apache_rj, "Header always set Cache-Control \"no-store\" env=$env" );
}
chk( 'apache: four headers on env= and on REDIRECT_ env=', $hdr_ok && substr_count( $apache_rj, 'Header always set' ) === 8 );
chk( 'apache: string ErrorDocument with the body, quotes escaped', str_contains( $apache_rj, 'ErrorDocument 410 "' . str_replace( '"', '\\"', $body ) . '"' ) );
chk( 'apache: vhost form, no <IfModule> around the reject', ! str_contains( $apache_rj, '<IfModule' ) && str_starts_with( $apache_rj, $open . "\n" ) && str_ends_with( $apache_rj, "\n" . $close ) );
chk( 'apache: no QS_ClientEventLimitCount', $apache !== '' && ! str_contains( $apache, 'QS_ClientEventLimitCount' ) );
$qos = code_of( $all['apache'], 'apache-qos' );
chk( 'apache: mod_qos concurrency cap = conn, any cookie exempt',
	str_contains( $qos, 'QS_EventRequestLimit JPKPF_ANON 3' ) && str_contains( $qos, 'QS_ErrorResponseCode 429' )
	&& str_contains( $qos, 'SetEnvIf Request_URI "^/+(?:blog/|news/)filter/" JPKPF_FILTER=1' ) && str_contains( $qos, 'SetEnvIf Cookie "." JPKPF_EXEMPT=1' )
	&& str_contains( $qos, 'QS_SetEnvIf JPKPF_FILTER !JPKPF_EXEMPT JPKPF_ANON=1' ), $qos );
// R10: a faked search-engine User-Agent must not get past the only concurrency cap.
chk( 'apache: mod_qos has no User-Agent exemption, context says search engines share the cap',
	$qos !== '' && stripos( $qos, 'User-Agent' ) === false && stripos( $qos, 'bot' ) === false
	&& str_contains( context_of( $all['apache'], 'apache-qos' ), 'Search engines share this cap' ) );
$modsec = code_of( $all['apache'], 'apache-modsec' );
preg_match_all( '/\bid:(\d+)/', $modsec, $m );
chk( 'apache: ModSecurity ids 4710001+ and unique', $m[1] !== [] && $m[1][0] === '4710001' && count( array_unique( $m[1] ) ) === count( $m[1] ) && min( $m[1] ) === '4710001' );
// R9: SecRuleEngine On only inside the LocationMatch over the filter
// prefixes - a vhost-wide one would make OWASP CRS block wp-admin too.
$lm_open  = strpos( $modsec, '<LocationMatch "^/+(?:blog/|news/)filter/">' );
$lm_close = strpos( $modsec, '</LocationMatch>' );
$scoped   = $lm_open !== false && $lm_close !== false && substr_count( $modsec, 'SecRuleEngine' ) === 1 && substr_count( $modsec, '<LocationMatch' ) === 1;
foreach ( [ 'SecRuleEngine', 'SecRule ', 'SecAction' ] as $needle ) {
	$scoped = $scoped && strpos( $modsec, $needle ) > $lm_open && strrpos( $modsec, $needle ) < $lm_close;
}
chk( 'apache: ModSecurity engine and every rule inside <LocationMatch> over the filter bases, nothing vhost-wide', $scoped, $modsec );
chk( 'apache: ModSecurity rules run in phase 2 (Location is mapped after phase 1)', substr_count( $modsec, 'phase:2' ) === 13 && ! str_contains( $modsec, 'phase:1' ) );
chk( 'apache: ModSecurity per IP (10 / 20 s), site-wide (30 / 10 s), search engines own counter (20 / 10 s)',
	str_contains( $modsec, '&REQUEST_HEADERS:Cookie "@gt 0"' )
	&& str_contains( $modsec, 'expirevar:resource.jpkpf_hits=20' ) && str_contains( $modsec, 'SecRule RESOURCE:JPKPF_HITS "@gt 10"' )
	&& str_contains( $modsec, 'expirevar:user.jpkpf_hits=10' ) && str_contains( $modsec, 'SecRule USER:JPKPF_HITS "@gt 30"' )
	&& str_contains( $modsec, 'expirevar:user.jpkpf_bot_hits=10' ) && str_contains( $modsec, 'SecRule USER:JPKPF_BOT_HITS "@gt 20"' )
	&& str_contains( $modsec, 'googlebot|bingbot|applebot|duckduckbot|yandexbot' ) && substr_count( $modsec, 'deny,status:429' ) === 3, $modsec );
// OWASP CRS 3.3 (Debian 13: modsecurity-crs 3.3.7) runs initcol:ip=<addr>_<UA hash>
// and initcol:global=global in phase 1, and ModSecurity 2 ignores a later initcol
// of a collection that is already open. Measured with Debian's modsecurity.conf:
// 14 requests from one IP with a new User-Agent each all passed the per-IP limit,
// and the site-wide counter of one VirtualHost answered 429 on another. Own
// counters therefore live in RESOURCE (per IP) and USER (per site), which CRS
// does not open; custom collection names are not addressable in ModSecurity 2.
chk( 'apache: ModSecurity counters in collections OWASP CRS does not open (RESOURCE per IP, USER per site)',
	str_contains( $modsec, 'initcol:resource=jpkpf_ip_%{REMOTE_ADDR}' ) && substr_count( $modsec, 'setuid:jpkpf_site_%{SERVER_NAME}' ) === 2
	&& ! preg_match( '/initcol:(?:ip|global)\b|\b(?:ip|global)\.jpkpf|\b(?:IP|GLOBAL):JPKPF/i', $modsec ), $modsec );
chk( 'apache: Retry-After on every 429, inside <IfModule mod_headers.c>, context names mod_headers',
	str_contains( $modsec, "<IfModule mod_headers.c>\n    Header always set Retry-After \"10\" \"expr=%{REQUEST_STATUS} == 429\"\n</IfModule>" )
	&& str_contains( $qos, "<IfModule mod_headers.c>\n    Header always set Retry-After \"10\" \"expr=%{REQUEST_STATUS} == 429\"\n</IfModule>" )
	&& str_contains( context_of( $all['apache'], 'apache-modsec' ), 'mod_headers' ) );
// Measured on Debian 13 (CRS 3.3.7, no modsecurity.conf): CRS runs uninitialised in
// the LocationMatch, blocks nothing and logs 911100 per filter request. And the
// site counter key SERVER_NAME follows the Host header unless UseCanonicalName On
// (catch-all VirtualHost: b.test got a fresh counter with Off, 429 with On).
$modsec_ctx = context_of( $all['apache'], 'apache-modsec' );
chk( 'apache: ModSecurity context explains CRS without a global engine and the Host-keyed site counter',
	str_contains( $modsec_ctx, 'without a global SecRuleEngine' ) && str_contains( $modsec_ctx, 'logs rule 911100 for every filter request' )
	&& str_contains( $modsec_ctx, 'SecRuleEngine DetectionOnly' ) && str_contains( $modsec_ctx, 'UseCanonicalName On' )
	&& ! str_contains( $modsec_ctx, 'If OWASP CRS is loaded, it then also enforces' ), $modsec_ctx );
chk( 'apache: context names apachectl -M', str_contains( context_of( $all['apache'] ), 'apachectl -M' ) );

// --- .htaccess -------------------------------------------------------------------
$ht       = code_of( $all['htaccess'], 'htaccess-reject' );
$ht_rules = (string) call( 'jpkcom_postfilter_server_htaccess_rules', $inputs );
chk( 'htaccess: manual markers', str_starts_with( $ht, '# BEGIN JPKCom Post Filter (manual)' ) && str_ends_with( $ht, '# END JPKCom Post Filter (manual)' ) );
chk( 'htaccess: block = manual markers + rule line + htaccess_rules()', $ht_rules !== '' && $ht === "# BEGIN JPKCom Post Filter (manual)\n# rule $hash\n" . $ht_rules . "\n# END JPKCom Post Filter (manual)" );
chk( 'htaccess_rules: no markers of any kind', str_contains( $ht_rules, 'RewriteCond' ) && ! str_contains( $ht_rules, '# BEGIN' ) && ! str_contains( $ht_rules, '# END' ) && ! str_contains( $ht_rules, '# >>>' ) );
chk( 'htaccess: per-directory RewriteRule ^, same THE_REQUEST patterns',
	str_contains( $ht_rules, "RewriteCond %{THE_REQUEST} \"" . $pline['per_group'] . "\"\nRewriteRule ^ - [E=JPKPF_REJECT:server-per-group,R=410,L]" )
	&& str_contains( $ht_rules, "RewriteRule ^ - [E=JPKPF_REJECT:server-segments,R=410,L]" ) && ! str_contains( $ht_rules, 'RewriteRule ^/' ) );
chk( 'htaccess: rewrite and headers inside <IfModule>',
	str_starts_with( $ht_rules, "<IfModule mod_rewrite.c>\nRewriteEngine On\n" ) && str_contains( $ht_rules, "</IfModule>\n<IfModule mod_headers.c>\n" )
	&& substr_count( $ht_rules, 'Header always set' ) === 8 && str_contains( $ht_rules, 'ErrorDocument 410 "' ) );
chk( 'htaccess: no rate-limit code, only a sentence', ! preg_match( '/QS_|SecRule|limit_/', code_of( $all['htaccess'] ) ) && code_of( $all['htaccess'], 'htaccess-rate' ) === '' && context_of( $all['htaccess'], 'htaccess-rate' ) !== '' );
chk( 'htaccess: context says before # BEGIN WordPress', str_contains( context_of( $all['htaccess'], 'htaccess-reject' ), '# BEGIN WordPress' ) );
chk( 'htaccess: context says the ErrorDocument covers the directory tree', str_contains( context_of( $all['htaccess'], 'htaccess-reject' ), 'every 410 that Apache itself sends in this directory tree' ) );

// --- OpenLiteSpeed ---------------------------------------------------------------
$ols = code_of( $all['ols'], 'ols-reject' );
chk( 'ols: no Header/extraHeaders lines', $ols !== '' && ! preg_match( '/Header|extraHeaders/i', $ols ) );
chk( 'ols: vhost rewrite on THE_REQUEST, between markers',
	str_contains( $ols, "RewriteCond %{THE_REQUEST} \"" . $pline['per_group'] . "\"\nRewriteRule ^/ - [E=JPKPF_REJECT:server-per-group,R=410,L]" )
	&& str_contains( $ols, 'server-segments,R=410,L]' ) && str_starts_with( $ols, $open . "\n" ) && str_ends_with( $ols, "\n" . $close ) && ! str_contains( $ols, 'ErrorDocument' ) );
chk( 'ols: context names WebAdmin, Graceful Restart and the missing header', str_contains( context_of( $all['ols'], 'ols-reject' ), 'WebAdmin' ) && str_contains( context_of( $all['ols'], 'ols-reject' ), 'Graceful Restart' ) && str_contains( context_of( $all['ols'], 'ols-reject' ), 'X-Jpkpf-Reject' ) );
chk( 'ols: rate limit only as a sentence', code_of( $all['ols'], 'ols-rate' ) === '' && context_of( $all['ols'], 'ols-rate' ) !== '' );

// --- across servers --------------------------------------------------------------
$in404   = layout_inputs( 2, 2, [], [ '/blog/', '/news/' ], [ 'reject_status' => 404 ] );
$code404 = [ 'nginx' => code_of( snips( 'nginx', $in404 ) ), 'ispconfig' => code_of( snips( 'nginx', $in404, 'ispconfig' ) ), 'apache' => code_of( snips( 'apache', $in404 ) ), 'htaccess' => code_of( snips( 'htaccess', $in404 ) ), 'ols' => code_of( snips( 'ols', $in404 ) ) ];
$mirror  = true;
foreach ( $code404 as $k => $c ) {
	$mirror = $mirror && $c !== '' && ! preg_match( '/R=410|return 410|ErrorDocument 410|<title>410/', $c );
}
$mirror = $mirror && substr_count( $code404['nginx'], "return 404 '" ) === 2 && substr_count( $code404['ispconfig'], "return 404 '" ) === 2
	&& substr_count( $code404['apache'], ',R=404,L]' ) === 2 && str_contains( $code404['apache'], 'ErrorDocument 404 "' )
	&& substr_count( $code404['htaccess'], ',R=404,L]' ) === 2 && str_contains( $code404['htaccess'], 'ErrorDocument 404 "' )
	&& substr_count( $code404['ols'], ',R=404,L]' ) === 2 && str_contains( $code404['nginx'], '<title>404</title>' );
chk( 'reject_status 404 mirrored everywhere', $mirror );
$odd = code_of( snips( 'nginx', layout_inputs( 2, 2, [], [ '/blog/' ], [ 'reject_status' => 302 ] ) ) );
chk( 'reject_status other than 404 renders as 410', str_contains( $odd, "return 410 '" ) && ! str_contains( $odd, 'return 302' ) );

$off     = layout_inputs( 2, 2, [], [ '/blog/', '/news/' ], [ 'strict' => false ] );
$nginx_off = code_of( snips( 'nginx', $off ) );
chk( 'no reject part when patterns null', $nginx_off !== '' && ! str_contains( $nginx_off, 'jpkpf-edge' ) && ! str_contains( $nginx_off, '$request_uri' ) && str_contains( $nginx_off, 'limit_req_status 429' ) );
chk( 'no reject part when patterns null: Apache, .htaccess, OLS, ISPConfig keeps robots',
	! str_contains( code_of( snips( 'apache', $off ) ), 'RewriteCond' ) && str_contains( code_of( snips( 'apache', $off ) ), 'QS_EventRequestLimit' )
	&& call( 'jpkcom_postfilter_server_htaccess_rules', $off ) === '' && ! in_array( 'htaccess-reject', ids_of( snips( 'htaccess', $off ) ), true )
	&& ! str_contains( code_of( snips( 'ols', $off ) ), 'RewriteCond' )
	&& str_contains( code_of( snips( 'nginx', $off, 'ispconfig' ) ), '##merge##' ) && ! str_contains( code_of( snips( 'nginx', $off, 'ispconfig' ) ), 'jpkpf-edge' ) );
$n0  = layout_inputs( 0, 2, [], [ '/blog/' ] );
$n0c = code_of( snips( 'nginx', $n0 ) ) . code_of( snips( 'apache', $n0 ) ) . code_of( snips( 'htaccess', $n0 ) ) . code_of( snips( 'ols', $n0 ) );
chk( 'N = 0: segments rule only (R7)', str_contains( $n0c, 'server-segments' ) && ! str_contains( $n0c, 'server-per-group' ) );

$weird = layout_inputs( 2, 2, [], [ '/blog/', '/we"ird/', '/a b/', '/x{y}/', '/q;r/', "/n\nl/", '/d$/' ] );
$wc    = code_of( snips( 'nginx', $weird ) ) . code_of( snips( 'nginx', $weird, 'ispconfig' ) ) . code_of( snips( 'apache', $weird ) ) . code_of( snips( 'htaccess', $weird ) ) . code_of( snips( 'ols', $weird ) );
$unsafe = call( 'jpkcom_postfilter_server_unsafe_bases', $weird );
chk( 'unsafe_bases names exactly the bases left out', $unsafe === [ '/we"ird/', '/a b/', '/x{y}/', '/q;r/', "/n\nl/", '/d$/' ], show( $unsafe ) );
chk( 'unsafe_bases is empty for ordinary bases', call( 'jpkcom_postfilter_server_unsafe_bases', $inputs ) === [] );
chk( 'a base that could break a config is left out, the others stay',
	$wc !== '' && str_contains( $wc, 'location /blog/filter/' ) && ! preg_match( '/we"ird|a b|x\{y|q;r|n\nl|d\$/', $wc ) );

// A left-out base still counts for the nested-base check: '/filter/x"y/' lies
// below '/' + endpoint, so its URLs shift the positions of the '/' rules.
$nested = layout_inputs( 2, 2, [], [ '/', '/filter/x"y/' ] );
$nc     = code_of( snips( 'nginx', $nested ) ) . code_of( snips( 'apache', $nested ) ) . code_of( snips( 'htaccess', $nested ) ) . code_of( snips( 'ols', $nested ) );
chk( 'a left-out base still blocks the reject part when nested', str_contains( $nc, 'location /filter/' ) && ! str_contains( $nc, 'server-segments' ) && ! str_contains( $nc, 'server-per-group' ) );

// --- check commands --------------------------------------------------------------
$probe_ok = function_exists( 'jpkcom_postfilter_server_probe_paths' );
if ( $probe_ok ) {
	foreach ( fuzz_layouts() as $layout ) {
		if ( ! str_ends_with( $layout['name'], ' blog+news' ) ) {
			continue;
		}
		apply_layout( $layout );
		$in = layout_inputs( $layout['N'], $layout['G'], $layout['skip'], $layout['bases'] );
		$pp = jpkcom_postfilter_server_reject_patterns( $in );
		$pr = jpkcom_postfilter_server_probe_paths( $in );
		// With every position skipped there is no position a per-group probe
		// could use without also being a segments reject.
		$no_position = count( $layout['skip'] ) >= $layout['G'];
		$probe_ok    = $probe_ok && ( $pr['per_group'] === null ) === $no_position;
		foreach ( [ 'per_group', 'segments' ] as $k ) {
			if ( $k === 'per_group' && $no_position ) {
				continue;
			}
			$probe_ok = $probe_ok && is_string( $pr[ $k ] ) && str_starts_with( $pr[ $k ], '/blog/filter/' )
				&& preg_match( '~' . $pp[ $k ] . '~', $pr[ $k ] ) === 1 && plugin_answer( $pr[ $k ], $layout ) === 'reject';
		}
		$probe_ok = $probe_ok && ( $no_position || preg_match( '~' . $pp['segments'] . '~', (string) $pr['per_group'] ) === 0 )
			&& preg_match( '~' . $pp['per_group'] . '~', (string) $pr['segments'] ) === 0 && count( $pr['within'] ) >= 3;
		foreach ( $pr['within'] as $w ) {
			$probe_ok = $probe_ok && preg_match( '~' . $pp['per_group'] . '~', $w ) === 0 && preg_match( '~' . $pp['segments'] . '~', $w ) === 0
				&& plugin_answer( $w, $layout ) !== 'reject';
		}
		if ( ! $probe_ok ) {
			echo '        probe mismatch in ' . $layout['name'] . ': ' . show( $pr ) . "\n";
			break;
		}
	}
}
chk( 'probes: over-cap probes match exactly their own pattern and the guard rejects them; within-cap probes match nothing (24 layouts)', $probe_ok );

$cc = [];
foreach ( [ 'nginx', 'apache', 'htaccess', 'ols' ] as $server ) {
	$cc[ $server ] = (string) call( 'jpkcom_postfilter_server_check_commands', $server, $inputs );
}
$cc_ok = true;
foreach ( $cc as $server => $c ) {
	$cc_ok = $cc_ok && str_contains( $c, "'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3/'" )
		&& str_contains( $c, "'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2/'" )
		&& str_contains( $c, "'https://example.test/robots.txt'" ) && str_contains( $c, 'curl ' );
}
chk( 'check commands: probe slugs jpkpf-probe-N under the first base, robots.txt', $cc_ok );
chk( 'check commands: expectation by header value server-<reason> and the rule hash (not OLS)',
	str_contains( $cc['nginx'], 'X-Jpkpf-Reject: server-per-group' ) && str_contains( $cc['nginx'], 'X-Jpkpf-Reject: server-segments' ) && str_contains( $cc['nginx'], 'X-Jpkpf-Rule: ' . $hash )
	&& str_contains( $cc['apache'], 'X-Jpkpf-Rule: ' . $hash ) && str_contains( $cc['htaccess'], 'server-per-group' ) );
chk( 'check commands: OLS judged by status, rate limit only where a snippet exists',
	str_contains( $cc['ols'], '410' ) && ! str_contains( $cc['ols'], 'X-Jpkpf-Rule: ' ) && str_contains( $cc['nginx'], 'Cookie:' ) && str_contains( $cc['apache'], 'Cookie:' )
	&& ! str_contains( $cc['htaccess'], 'Cookie:' ) && ! str_contains( $cc['ols'], 'Cookie:' ) );
// mod_qos caps concurrency: only parallel requests can show its 429.
// ModSecurity counts per IP over time: sequential requests.
chk( 'check commands: Apache tests mod_qos in parallel and ModSecurity sequentially, nginx sequentially',
	substr_count( $cc['apache'], 'xargs -P 8' ) === 2 && str_contains( $cc['apache'], 'CONCURRENT' ) && substr_count( $cc['apache'], 'for i in $(seq 1 25)' ) === 2
	&& ! str_contains( $cc['nginx'], 'xargs' ) && substr_count( $cc['nginx'], 'for i in $(seq 1 25)' ) === 2 );
chk( 'check commands: no reject probes without a reject rule', ! str_contains( (string) call( 'jpkcom_postfilter_server_check_commands', 'nginx', $off ), 'server-per-group' ) && str_contains( (string) call( 'jpkcom_postfilter_server_check_commands', 'nginx', $off ), 'robots.txt' ) );

// =====================================================================================
section( 'renderers: caddy/ferron' );
// =====================================================================================

$cf = [
	'caddy'   => snips( 'caddy', $inputs ),
	'ferron3' => snips( 'ferron3', $inputs ),
	'ferron2' => snips( 'ferron2', $inputs ),
];
$shape = true;
foreach ( $cf as $name => $list ) {
	$shape = $shape && $list !== [] && count( array_unique( ids_of( $list ) ) ) === count( $list );
	foreach ( $list as $s ) {
		$shape = $shape && is_array( $s ) && array_keys( $s ) === [ 'id', 'title', 'context', 'code' ]
			&& is_string( $s['code'] ) && $s['title'] !== '' && $s['context'] !== '' && str_starts_with( $s['id'], $name . '-' );
	}
}
chk( 'caddy/ferron snippets: list of {id, title, context, code}, ids unique and prefixed by server', $shape );

/** Caddy CEL: every `\` doubled inside the double-quoted CEL string. */
function cel( string $re ): string { return str_replace( '\\', '\\\\', $re ); }

$caddy      = code_of( $cf['caddy'] );
$caddy_ctx  = context_of( $cf['caddy'] );
$f3         = code_of( $cf['ferron3'] );
$f3_context = context_of( $cf['ferron3'] );
$f2         = code_of( $cf['ferron2'] );
$f2_context = context_of( $cf['ferron2'] );
preg_match_all( '/\.matches\("((?:[^"\\\\]|\\\\.)*)"\)/', $caddy, $cm );
$cel_string = implode( "\n", $cm[1] );

// --- Caddy -----------------------------------------------------------------------
chk( 'caddy: snippet ids site, robots', ids_of( $cf['caddy'] ) === [ 'caddy-site', 'caddy-robots' ], implode( ',', ids_of( $cf['caddy'] ) ) );
chk( 'caddy: CEL on raw uri, doubled backslashes', str_contains( $caddy, '{http.request.orig_uri}.matches(' ) && ! preg_match( '/(?<!\\\\)\\\\\+/', $cel_string ) );
// R13: rewrite/try_files run before route in the Caddyfile order; only the
// original URI is immune to them.
chk( 'caddy: original URI only - no rewritten {http.request.uri}, no path_regexp', $caddy !== '' && ! str_contains( $caddy, '{http.request.uri}' ) && ! str_contains( $caddy, 'path_regexp' ) );
chk( 'caddy: one expression matcher per reason with the exact pattern, CEL-escaped',
	str_contains( $caddy, "\t@jpkpf_segments expression `{http.request.orig_uri}.matches(\"" . cel( (string) $pat['segments'] ) . "\")`\n" )
	&& str_contains( $caddy, "\t@jpkpf_per_group expression `{http.request.orig_uri}.matches(\"" . cel( (string) $pat['per_group'] ) . "\")`\n" )
	&& count( $cm[1] ) === 5 && str_replace( '\\\\', '\\', $cm[1][0] ) === $pat['segments'], $cel_string );
chk( 'caddy: reject before rate_limit in one route', strpos( $caddy, 'respond @jpkpf' ) < strpos( $caddy, 'rate_limit' ) );
chk( 'caddy: key static zone and header !Cookie',  str_contains( $caddy, 'key static' ) && str_contains( $caddy, 'header !Cookie' ) );
$c_head = "\theader @jpkpf_segments X-Robots-Tag \"noindex, nofollow\"\n\theader @jpkpf_segments X-Jpkpf-Reject \"server-segments\"\n"
	. "\theader @jpkpf_segments X-Jpkpf-Rule \"$hash\"\n\theader @jpkpf_segments Cache-Control \"no-store\"\n"
	. "\theader @jpkpf_segments Content-Type \"text/html; charset=utf-8\"\n\trespond @jpkpf_segments \"" . str_replace( '"', '\\"', $body ) . "\" 410\n";
chk( 'caddy: four headers, Content-Type and the body (quotes escaped) per reason', str_contains( $caddy, $c_head ) && substr_count( $caddy, 'X-Jpkpf-Reject "server-per-group"' ) === 1 && substr_count( $caddy, "\" 410\n" ) === 2, $caddy );
chk( 'caddy: segments block before per-group block (the guard\'s order; respond ends the route)', strpos( $caddy, 'respond @jpkpf_segments' ) < strpos( $caddy, '@jpkpf_per_group expression' ) );
$route_open  = strpos( $caddy, "\nroute {\n" );
$route_close = $route_open === false ? false : strpos( $caddy, "\n}\n", $route_open + 1 );
chk( 'caddy: matchers, rejects and rate_limit all inside the one route',
	$route_open !== false && $route_close !== false && substr_count( $caddy, 'route {' ) === 1
	&& strpos( $caddy, '@jpkpf_segments expression' ) > $route_open && strrpos( $caddy, 'rate_limit {' ) < $route_close && strpos( $caddy, 'handle_errors' ) > $route_close );
$zone = static fn( string $name, string $ua, string $key, int $events, int $window ): string => "\t\tzone $name {\n\t\t\tmatch {\n\t\t\t\texpression `{http.request.orig_uri.path}.matches(\"^/+(?:blog/|news/)filter/\")`\n\t\t\t\theader !Cookie\n"
	. "\t\t\t\t$ua\n\t\t\t}\n\t\t\tkey $key\n\t\t\tevents $events\n\t\t\twindow {$window}s\n\t\t}\n";
$ua = 'header_regexp User-Agent (?i)(?:googlebot|bingbot|applebot|duckduckbot|yandexbot)';
chk( 'caddy: zones per IP (10 / 20 s), site-wide (30 / 10 s), search engines own (20 / 10 s), bots out of the first two',
	str_contains( $caddy, $zone( 'jpkpf_ip', 'not ' . $ua, '{remote_host}', 10, 20 ) )
	&& str_contains( $caddy, $zone( 'jpkpf_all', 'not ' . $ua, 'static', 30, 10 ) )
	&& str_contains( $caddy, $zone( 'jpkpf_bots', $ua, 'static', 20, 10 ) ), $caddy );
$errors = substr( $caddy, (int) strpos( $caddy, 'handle_errors 429 {' ) );
$errors = substr( $errors, 0, (int) strpos( $errors, "\n}" ) );
chk( 'caddy: handle_errors 429 with no-store and a plain body, no X-Robots-Tag (R11)',
	str_contains( $errors, "\theader Cache-Control \"no-store\"\n" ) && preg_match( '/\n\trespond "[^"{}]+" 429$/', $errors ) === 1 && ! str_contains( $errors, 'X-Robots-Tag' ), $errors );
chk( 'caddy: no cacheable reject (no max-age)', $caddy !== '' && ! str_contains( $caddy, 'max-age' ) && substr_count( $caddy, 'X-Robots-Tag' ) === 2 );
chk( 'caddy: between the >>> rule <hash> / <<< comment lines', str_starts_with( $caddy, $open . "\n" ) && str_contains( $caddy, "\n" . $close ) );
chk( 'caddy: context names caddy-ratelimit, xcaddy, list-modules check, FrankenPHP, trusted_proxies',
	str_contains( $caddy_ctx, 'caddy-ratelimit' ) && str_contains( $caddy_ctx, 'xcaddy' ) && str_contains( $caddy_ctx, 'caddy list-modules | grep http.handlers.rate_limit' )
	&& str_contains( $caddy_ctx, 'FrankenPHP' ) && str_contains( $caddy_ctx, 'trusted_proxies' )
	&& str_contains( $caddy_ctx, 'wraps php_fastcgi in a handle block' ) );
chk( 'caddy: robots is a hint without code', code_of( $cf['caddy'], 'caddy-robots' ) === '' && str_contains( context_of( $cf['caddy'], 'caddy-robots' ), 'php_fastcgi' ) );

// --- Ferron 3 --------------------------------------------------------------------
$f3_match = code_of( $cf['ferron3'], 'ferron3-match' );
$f3_host  = code_of( $cf['ferron3'], 'ferron3-host' );
chk( 'ferron3: snippet ids match, host, robots', ids_of( $cf['ferron3'] ) === [ 'ferron3-match', 'ferron3-host', 'ferron3-robots' ], implode( ',', ids_of( $cf['ferron3'] ) ) );
chk( 'ferron3: request.uri (raw), not .path, for reject',
	str_contains( $f3_match, "match jpkpf_segments {\n    request.uri ~ r\"" . $pat['segments'] . "\"\n}" )
	&& str_contains( $f3_match, "match jpkpf_per_group {\n    request.uri ~ r\"" . $pat['per_group'] . "\"\n" )
	&& ! str_contains( $f3, 'request.uri.path ~ r"' . $pat['segments'] ) && ! str_contains( $f3, 'request.uri.path ~ r"' . $pat['per_group'] ) );
chk( 'ferron3: per-group excludes segments, so one reason per request (guard order)',
	str_contains( $f3_match, "match jpkpf_per_group {\n    request.uri ~ r\"" . $pat['per_group'] . "\"\n    request.uri !~ r\"" . $pat['segments'] . "\"\n}" ) );
$f3_if = "if jpkpf_per_group {\n    status 410 {\n        body \"" . str_replace( '"', '\\"', $body ) . "\"\n    }\n    header X-Robots-Tag \"noindex, nofollow\"\n"
	. "    header X-Jpkpf-Reject \"server-per-group\"\n    header X-Jpkpf-Rule \"$hash\"\n    header Cache-Control \"no-store\"\n    header Content-Type \"text/html; charset=utf-8\"\n}";
chk( 'ferron3: if block per reason with status, body and the four headers', str_contains( $f3_host, $f3_if ) && substr_count( $f3_host, 'status 410 {' ) === 2 && str_contains( $f3_host, 'X-Jpkpf-Reject "server-segments"' ), $f3_host );
chk( 'ferron3: anonymous filter match on the decoded path, any cookie exempts',
	str_contains( $f3_match, "match jpkpf_filter_anon {\n    request.uri.path ~ r\"^/+(?:blog/|news/)filter/\"\n    request.header.cookie !~ r\".\"\n}" ) );
chk( 'ferron3: per-IP rate_limit rate 1 burst 10',
	str_contains( $f3_host, "if jpkpf_filter_anon {\n    rate_limit {\n        rate 1\n        burst 10\n        key remote_address\n        deny_status 429\n    }\n" ) );
chk( 'ferron3: bulkhead limit = conn',            str_contains( $f3, 'limit 3' ) && str_contains( $f3_host, "    fcgi_php <php-backend-url>/jpkpf {\n        limit 3\n    }\n" ) );
chk( 'ferron3: no X-Robots-Tag outside the reject blocks, no max-age', substr_count( $f3, 'X-Robots-Tag' ) === 2 && ! str_contains( $f3, 'max-age' ) );
chk( 'ferron3: context says RC tested with 3.0.0-rc.10, requests wait, bulkhead URL must differ, placeholder',
	str_contains( $f3_context, 'tested with 3.0.0-rc.10' ) && str_contains( $f3_context, '<php-backend-url>' ) && stripos( $f3_context, 'wait' ) !== false
	&& str_contains( $f3_context, 'must differ' ) && str_contains( $f3_context, 'search engines' ) );
chk( 'ferron3: match blocks outside, if blocks inside the host (two marked blocks)',
	str_starts_with( $f3_match, $open . "\n" ) && str_ends_with( $f3_match, "\n" . $close ) && ! str_contains( $f3_match, 'if ' )
	&& str_starts_with( $f3_host, $open . "\n" ) && str_ends_with( $f3_host, "\n" . $close ) && ! str_contains( $f3_host, 'match ' ) );

// --- Ferron 2.8 ------------------------------------------------------------------
chk( 'ferron2: snippet ids reject (hint), rate, robots', ids_of( $cf['ferron2'] ) === [ 'ferron2-reject', 'ferron2-rate', 'ferron2-robots' ], implode( ',', ids_of( $cf['ferron2'] ) ) );
$f2_reject_ctx = context_of( $cf['ferron2'], 'ferron2-reject' );
chk( 'ferron2: no reject part, says why (untested; PHP sees the same decoded URI)', ! str_contains( $f2, 'status 410' ) && code_of( $cf['ferron2'], 'ferron2-reject' ) === '' && ! str_contains( $f2, 'X-Jpkpf-Reject' )
	&& str_contains( $f2_reject_ctx, 'none has been tested' ) && str_contains( $f2_reject_ctx, 'PHP receives that same decoded URI' ) && str_contains( $f2_reject_ctx, '%2B, %2F or %70age' ), $f2_reject_ctx );
chk( 'ferron2: KDL condition on {path} with "" for a missing Cookie, limit rate=1 burst=10',
	str_contains( $f2, "condition \"JPKPF_FILTER_ANON\" {\n    is_regex \"{path}\" \"^/+(?:blog/|news/)filter/\"\n    is_equal \"{header:Cookie}\" \"\"\n}\nif \"JPKPF_FILTER_ANON\" {\n    limit rate=1 burst=10\n}" ), $f2 );
$kdl_comments = $f2 !== '';
foreach ( explode( "\n", $f2 ) as $l ) {
	$kdl_comments = $kdl_comments && ! str_starts_with( ltrim( $l ), '#' );
}
$f2_rate = code_of( $cf['ferron2'], 'ferron2-rate' );
chk( 'ferron2: KDL comments (//), no # line', $kdl_comments && str_starts_with( $f2_rate, '// >>> jpkcom-post-filter 1.6.0-test rule ' . $hash . "\n" ) && str_ends_with( $f2_rate, "\n// <<< jpkcom-post-filter" ), $f2 );
chk( 'ferron2: context: no site-wide cap, search engines share', stripos( context_of( $cf['ferron2'], 'ferron2-rate' ), 'search engines' ) !== false && str_contains( context_of( $cf['ferron2'], 'ferron2-rate' ), '""' ) );

// A rate-limit scope that runs on a path the server does not merge (Caddy's
// orig_uri.path, possibly Ferron's) must allow leading slashes: WordPress
// trims them and still renders //blog/filter/x/ as a filter page.
$scopes = [];
if ( preg_match( '/\{http\.request\.orig_uri\.path\}\.matches\("((?:[^"\\\\]|\\\\.)*)"\)/', $caddy, $sm ) ) {
	$scopes['caddy'] = str_replace( '\\\\', '\\', $sm[1] );
}
if ( preg_match( '/request\.uri\.path ~ r"([^"]*)"/', $f3, $sm ) ) {
	$scopes['ferron3'] = $sm[1];
}
if ( preg_match( '/is_regex "\{path\}" "((?:[^"\\\\]|\\\\.)*)"/', $f2, $sm ) ) {
	$scopes['ferron2'] = str_replace( '\\\\', '\\', $sm[1] );
}
$scope_ok = count( $scopes ) === 3;
foreach ( $scopes as $server => $re ) {
	$scope_ok = $scope_ok && preg_match( '~' . $re . '~', '//blog/filter/a/' ) === 1 && preg_match( '~' . $re . '~', '///news/filter/a+b/' ) === 1
		&& preg_match( '~' . $re . '~', '/blog/filter/a/' ) === 1 && preg_match( '~' . $re . '~', '/blog/x/' ) === 0 && preg_match( '~' . $re . '~', '/other/blog/filter/a/' ) === 0;
}
chk( 'rate-limit scopes (Caddy, Ferron 3, Ferron 2) match //blog/filter/a/ and /blog/filter/a/, not /blog/x/', $scope_ok, show( $scopes ) );

// --- across caddy/ferron ---------------------------------------------------------
chk( 'all servers carry the hash where headers exist',
	substr_count( $caddy, 'X-Jpkpf-Rule "' . $hash . '"' ) === 2 && substr_count( $f3, 'header X-Jpkpf-Rule "' . $hash . '"' ) === 2
	&& str_contains( $f2, 'rule ' . $hash ) );

$dot  = layout_inputs( 2, 2, [], [ '/my.blog/' ] );
$dotc = code_of( snips( 'caddy', $dot ) );
$dot3 = code_of( snips( 'ferron3', $dot ) );
$dot2 = code_of( snips( 'ferron2', $dot ) );
chk( 'escaped base: Caddy CEL doubled (reject and scope), Ferron 3 raw single, KDL doubled',
	str_contains( $dotc, '{http.request.orig_uri.path}.matches("^/+(?:my\\\\.blog/)filter/")' ) && substr_count( $dotc, 'my\\\\.blog/' ) === 5
	&& str_contains( $dot3, 'r"^/+(?:my\\.blog/)filter/"' ) && str_contains( $dot2, '"^/+(?:my\\\\.blog/)filter/"' ), $dot2 );

$root  = layout_inputs( 2, 2, [], [ '/', '/news/' ] );
$rootc = code_of( snips( 'caddy', $root ) );
$root3 = code_of( snips( 'ferron3', $root ) );
chk( 'root base renders the empty alternation (RE2, Rust regex >= 1.8)', str_contains( $rootc, '(?:|news/)filter/' ) && str_contains( $root3, '(?:|news/)filter/' ) && str_contains( $rootc, '{http.request.orig_uri.path}.matches("^/+(?:|news/)filter/")' ) );

$c404 = code_of( snips( 'caddy', $in404 ) ) . code_of( snips( 'ferron3', $in404 ) );
chk( 'reject_status 404 mirrored in Caddy and Ferron 3', substr_count( $c404, "\" 404\n" ) === 2 && substr_count( $c404, 'status 404 {' ) === 2 && ! preg_match( '/" 410\n|status 410|<title>410/', $c404 ) );

$coff = code_of( snips( 'caddy', $off ) );
$foff = code_of( snips( 'ferron3', $off ) );
chk( 'no reject part when patterns null: Caddy and Ferron 3 keep the rate limit',
	$coff !== '' && ! str_contains( $coff, '{http.request.orig_uri}.matches' ) && ! str_contains( $coff, '@jpkpf_' ) && ! str_contains( $coff, 'respond @jpkpf' ) && str_contains( $coff, 'rate_limit {' ) && str_contains( $coff, 'route {' )
	&& $foff !== '' && ! str_contains( $foff, 'jpkpf_segments' ) && ! preg_match( '/^\s*status \d/m', $foff ) && str_contains( $foff, 'rate_limit {' ), $coff . "\n----\n" . $foff );
$n0cf = code_of( snips( 'caddy', $n0 ) ) . code_of( snips( 'ferron3', $n0 ) );
chk( 'N = 0: segments rule only in Caddy and Ferron 3 (R7)', str_contains( $n0cf, 'server-segments' ) && ! str_contains( $n0cf, 'server-per-group' ) && ! str_contains( $n0cf, '!~ r"^' ) );
$wcf = code_of( snips( 'caddy', $weird ) ) . code_of( snips( 'ferron3', $weird ) ) . code_of( snips( 'ferron2', $weird ) );
chk( 'caddy/ferron: a base that could break a config is left out', $wcf !== '' && str_contains( $wcf, 'blog/' ) && ! preg_match( '/we"ird|a b|x\{y|q;r|n\nl|d\$/', $wcf ) );
$ncf = code_of( snips( 'caddy', $nested ) ) . code_of( snips( 'ferron3', $nested ) );
chk( 'caddy/ferron: a left-out nested base still blocks the reject part', $ncf !== '' && ! str_contains( $ncf, 'server-segments' ) && ! str_contains( $ncf, 'server-per-group' ) );
$none = layout_inputs( 2, 2, [], [] );
chk( 'caddy/ferron without bases: only the hints, no code', trim( code_of( snips( 'caddy', $none ) ) . code_of( snips( 'ferron3', $none ) ) . code_of( snips( 'ferron2', $none ) ) ) === '' && snips( 'caddy', $none ) !== [] );

// --- check commands --------------------------------------------------------------
foreach ( [ 'caddy', 'ferron3', 'ferron2' ] as $server ) {
	$cc[ $server ] = (string) call( 'jpkcom_postfilter_server_check_commands', $server, $inputs );
}
$cc_ok = true;
foreach ( [ 'caddy', 'ferron3', 'ferron2' ] as $server ) {
	$cc_ok = $cc_ok && str_contains( $cc[ $server ], "'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2/'" )
		&& str_contains( $cc[ $server ], "'https://example.test/robots.txt'" ) && str_contains( $cc[ $server ], 'Cookie:' ) && str_contains( $cc[ $server ], 'for i in $(seq 1 25)' );
}
chk( 'check commands caddy/ferron: within probes, robots.txt, rate limit with cookie exemption', $cc_ok );
chk( 'check commands caddy/ferron3: reject probes by header value and hash',
	str_contains( $cc['caddy'], 'X-Jpkpf-Reject: server-per-group' ) && str_contains( $cc['caddy'], 'X-Jpkpf-Rule: ' . $hash )
	&& str_contains( $cc['ferron3'], 'X-Jpkpf-Reject: server-segments' ) && str_contains( $cc['ferron3'], "'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3/'" ) );
chk( 'check commands ferron2: no reject probes, says why',
	! str_contains( $cc['ferron2'], 'server-per-group' ) && ! str_contains( $cc['ferron2'], 'jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3' )
	&& str_contains( $cc['ferron2'], '# Ferron 2.8 gets no reject rule in this version: nothing to probe.' ) && ! str_contains( $cc['ferron2'], 'URL-decoded' ) );
chk( 'check commands ferron3: bulkhead shown with parallel requests and their times', str_contains( $cc['ferron3'], 'xargs -P 8' ) && str_contains( $cc['ferron3'], 'time_total' ) && ! str_contains( $cc['caddy'], 'xargs' ) );

// --- 1.6.1: the rate-limit loop is its own evidence -----------------------------
// Lab run (nginx, 3 r/s site-wide): the single request printed after the
// sequential loop answered 200, not 429 - the site bucket refills between the
// loop and that request. The loop itself now prints the codes and the
// Retry-After header of the first 429 it sees; no request after it.
$rate_servers = [ 'nginx', 'apache', 'caddy', 'ferron3', 'ferron2' ];
$no_next      = true;
$evidence     = [];
foreach ( $rate_servers as $server ) {
	$c       = $cc[ $server ];
	$no_next = $no_next && ! str_contains( $c, 'on the next one' )
		&& preg_match( '/^curl [^\n]*grep -iE \'\^\(HTTP\|retry-after\)\'$/m', $c ) === 0;
	$lines   = array_values( array_filter( explode( "\n", $c ), static fn( string $l ): bool => str_contains( $l, 'for i in $(seq 1 25)' ) && ! str_contains( $l, 'Cookie:' ) ) );
	$evidence[ $server ] = $lines;
}
chk( 'check commands 1.6.1: no separate request after the rate-limit loop, no "Retry-After on the next one"', $no_next, $cc['nginx'] );
chk( 'check commands 1.6.1: exactly one cookie-less sequential loop per rate-limited server, printing the first 429\'s Retry-After',
	array_sum( array_map( 'count', $evidence ) ) === count( $rate_servers )
	&& array_reduce( $evidence, static fn( bool $ok, array $l ): bool => $ok && isset( $l[0] ) && str_contains( $l[0], "grep -i '^retry-after:'" ) && str_contains( $l[0], 'first 429: ' ), true ),
	show( $evidence ) );
chk( 'check commands 1.6.1: cookie loops unchanged (codes only, expect no 429), mod_qos still parallel',
	str_contains( $cc['nginx'], "#    Any cookie exempts a request: expect no 429.\nfor i in \$(seq 1 25); do curl -s -o /dev/null -w '%{http_code} ' -H 'Cookie: jpkpf-check=1' " )
	&& substr_count( $cc['apache'], 'xargs -P 8' ) === 2 && ! str_contains( $cc['apache'], 'then Retry-After' ) );

/**
 * Run one printed loop under POSIX sh with a stand-in curl: requests 1..$ok
 * answer 200, later ones 429 (with or without Retry-After). Returns stdout,
 * or null when no sh is available.
 */
function run_rate_loop( string $loop, int $ok, bool $retry_after ): ?string {
	$sh = is_executable( '/bin/sh' ) ? '/bin/sh' : null;
	if ( $sh === null || ! function_exists( 'proc_open' ) ) {
		return null;
	}
	$counter = tempnam( sys_get_temp_dir(), 'jpkpf-rl' );
	$ra      = $retry_after ? 'Retry-After: 10\r\n' : '';
	$fake    = 'curl() { n=$(cat "$JPKPF_CNT"); n=$((n+1)); echo "$n" > "$JPKPF_CNT"; '
		. 'if [ "$n" -gt ' . $ok . ' ]; then printf \'HTTP/1.1 429 Too Many Requests\r\n' . $ra . 'Cache-Control: no-store\r\n\r\n429\'; '
		. 'else printf \'HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n200\'; fi; }';
	file_put_contents( $counter, '0' );
	$proc = proc_open( [ $sh, '-c', $fake . "\n" . $loop ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, [ 'JPKPF_CNT' => $counter, 'PATH' => (string) getenv( 'PATH' ) ] );
	if ( ! is_resource( $proc ) ) {
		@unlink( $counter );
		return null;
	}
	$out = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
	proc_close( $proc );
	@unlink( $counter );
	return $out;
}

$loop_runs = [];
foreach ( $rate_servers as $server ) {
	$loop = $evidence[ $server ][0] ?? 'echo missing';
	$loop_runs[ $server ] = [
		run_rate_loop( $loop, 7, true ),
		run_rate_loop( $loop, 25, true ),
		run_rate_loop( $loop, 3, false ),
	];
}
$expect_429 = str_repeat( '200 ', 7 ) . str_repeat( '429 ', 18 ) . "\nfirst 429: Retry-After: 10\n";
$expect_200 = str_repeat( '200 ', 25 ) . "\nfirst 429: none\n";
$expect_nra = str_repeat( '200 ', 3 ) . str_repeat( '429 ', 22 ) . "\nfirst 429: no Retry-After header\n";
if ( $loop_runs['nginx'][0] === null ) {
	echo "  SKIP  check commands 1.6.1: loop run under sh (no /bin/sh or proc_open)\n";
} else {
	$runs_ok = true;
	foreach ( $loop_runs as $server => [ $a, $b, $c ] ) {
		$runs_ok = $runs_ok && $a === $expect_429 && $b === $expect_200 && $c === $expect_nra;
	}
	chk( 'check commands 1.6.1: the loop runs under POSIX sh and prints the codes plus the first 429\'s Retry-After (or none / no header)', $runs_ok, show( $loop_runs ) );
}

// =====================================================================================
section( 'container findings: caddy/ferron' );
// =====================================================================================
// Caddy 2.11.7 refuses the whole snippet inside a handle block: "directive
// 'handle_errors' is not an ordered HTTP handler". Only the route goes in.
chk( 'caddy: context puts only the route inside a handle, handle_errors stays at the site level',
	str_contains( $caddy_ctx, 'put the route block inside that handle, before php_fastcgi' )
	&& str_contains( $caddy_ctx, 'keep handle_errors outside it at the site level' ), $caddy_ctx );
// Ferron 3.0.0-rc.10 connects to url.path() of a unix:// URL: the /jpkpf suffix
// became part of the socket path and every cookie-less filter request got 500.
// A second spelling of the same path (/./) is a separate pool and works.
$f3_ctx_host = context_of( $cf['ferron3'], 'ferron3-host' );
chk( 'ferron3: Unix socket - no suffix, a different spelling of the same path (code comment and context)',
	str_contains( $f3_host, '# Unix socket: no /jpkpf suffix' ) && str_contains( $f3_host, 'unix:///run/php/./php-fpm.sock' )
	&& str_contains( $f3_ctx_host, 'unix:///run/php/./php-fpm.sock' ) && str_contains( $f3_ctx_host, '500' ) && ! str_contains( $f3_ctx_host, 'untested' ), $f3_host . "\n----\n" . $f3_ctx_host );
// Ferron 3.0.0-rc.10 accepts and applies match blocks inside a host block too.
$f3_ctx_match = context_of( $cf['ferron3'], 'ferron3-match' );
chk( 'ferron3: matcher context does not claim match blocks cannot sit inside a host',
	! str_contains( $f3_ctx_match, 'cannot sit inside a host' ) && str_contains( $f3_ctx_match, 'top level' ), $f3_ctx_match );
// The printed check commands are run as one block: the "wait" the comments ask
// for must be a command, or the second part measures the first part's budget.
chk( 'check commands ferron3/apache: the waits between the rate-limit parts are sleep commands',
	str_contains( $cc['ferron3'], "#    b) The per-IP limit, 15 s after a): sequential requests.\nsleep 15\n" )
	&& substr_count( $cc['apache'], "\nsleep 30\n" ) === 2, $cc['ferron3'] . "\n----\n" . $cc['apache'] );
// Ferron 2.8.1: the URL sanitizer rewrites the URI before any module and PHP
// receives the rewritten one ({path_and_query} == REQUEST_URI in the lab), so
// "counted differently from WordPress" was wrong (ruling R14).
chk( 'ferron2: no claim that the decoded {path} is counted differently from WordPress',
	! str_contains( $f2_context, 'counted differently' ) && ! str_contains( $f2_context, 'could reject a URL the plugin serves' ) && ! str_contains( $cc['ferron2'], 'counted differently' ), $f2_context );
// Ferron 3 rc.10: FastCGI pool and per-upstream limit are keyed by the literal
// URL string (source, undocumented) - the /./ spelling relies on it.
chk( 'ferron3: host context says the pool is keyed by the literal URL string (rc.10 source), names a second PHP-FPM socket',
	str_contains( $f3_ctx_host, 'literal URL string' ) && str_contains( $f3_ctx_host, '3.0.0-rc.10' ) && str_contains( $f3_ctx_host, 'second PHP-FPM' ), $f3_ctx_host );
// Ferron 2 and 3 answer 429 with their default page and no Cache-Control.
chk( 'ferron2/3: context explains the 429 without Cache-Control (not cached by default, RFC 9111)',
	str_contains( $f3_ctx_host, 'RFC 9111' ) && str_contains( context_of( $cf['ferron2'], 'ferron2-rate' ), 'RFC 9111' ) );

// =====================================================================================
section( 'size and compile guard (1.6.1)' );
// =====================================================================================
// A 1.6.0 review measured: with many groups and a high cap the bounded
// per_group pattern stops compiling in PCRE (link size 2) at G >= 71 (N 20) or
// G >= 37 with the last position skipped; with alternating skips the
// THE_REQUEST form passes 8000 characters at G >= 40. Measured for 1.6.1:
// nginx refuses a quoted parameter of 4095 characters ("too long parameter",
// NGX_CONF_BUFFER 4096; 4094 is accepted), Apache answers 500 for the whole
// tree once an .htaccess line reaches 8192 bytes (8191 is accepted), Go's
// regexp (Caddy) refuses repetition counts over 1000 and nested products
// over 1000, and Rust's regex (Ferron 3) refuses nesting deeper than 250 -
// silently: the rule just never matches. Such a pattern is dropped (null),
// which is looser; PHP still rejects.

$guard_ok = function_exists( 'jpkcom_postfilter_server_patterns_dropped' ) && function_exists( 'jpkcom_postfilter_server_reject_patterns_unchecked' );
chk( 'guard functions exist (patterns_dropped, reject_patterns_unchecked, regex_shape)', $guard_ok && function_exists( 'jpkcom_postfilter_server_regex_shape' ) );

$odd = static fn( int $g ): array => array_values( array_filter( range( 0, $g - 1 ), static fn( int $p ): bool => $p % 2 === 1 ) );

/** Patterns, unchecked patterns and reasons for one layout (blog+news). */
function guard_case( int $n, int $g, array $skip ): array {
	$in = layout_inputs( $n, $g, $skip, [ '/blog/', '/news/' ] );
	return [
		'in'      => $in,
		'uri'     => jpkcom_postfilter_server_reject_patterns( $in ),
		'line'    => jpkcom_postfilter_server_reject_patterns( $in, 'request_line' ),
		'raw'     => function_exists( 'jpkcom_postfilter_server_reject_patterns_unchecked' ) ? jpkcom_postfilter_server_reject_patterns_unchecked( $in, 'request_line' ) : null,
		'dropped' => (array) call( 'jpkcom_postfilter_server_patterns_dropped', $in ),
	];
}

// --- the layouts the review found ---
$c71 = guard_case( 20, 71, [] );
chk( 'N20 G71: per_group (does not compile in PCRE) is null in both forms, reason pcre; segments kept',
	$c71['uri']['per_group'] === null && $c71['line']['per_group'] === null && ( $c71['dropped']['per_group'] ?? '' ) === 'pcre'
	&& is_string( $c71['uri']['segments'] ) && ! isset( $c71['dropped']['segments'] ), show( $c71['dropped'] ) );
chk( 'N20 G71: the unchecked pattern really does not compile', $guard_ok && @preg_match( "\x01" . (string) $c71['raw']['per_group'] . "\x01", '' ) === false );

$c37 = guard_case( 20, 37, [ 36 ] );
chk( 'N20 G37, last position skipped: per_group null, reason pcre', $c37['uri']['per_group'] === null && ( $c37['dropped']['per_group'] ?? '' ) === 'pcre', show( $c37['dropped'] ) );

$c40 = guard_case( 20, 40, $odd( 40 ) );
chk( 'N20 G40, alternating skips: THE_REQUEST form was over 8000 characters; now null, reason length',
	$guard_ok && strlen( (string) $c40['raw']['per_group'] ) > 8000 && $c40['line']['per_group'] === null && $c40['uri']['per_group'] === null
	&& ( $c40['dropped']['per_group'] ?? '' ) === 'length', show( $c40['dropped'] ) );

$c1000 = guard_case( 2, 1000, [] );
// PCRE replicates a bounded repetition, so 1003 copies of a segment are also
// too large for it - and PCRE is checked first.
chk( 'N2 G1000: segments repeats 1003 times (over Go\'s 1000, too large for PCRE), both patterns null, reason pcre',
	$c1000['uri']['segments'] === null && ( $c1000['dropped']['segments'] ?? '' ) === 'pcre' && $c1000['uri']['per_group'] === null, show( $c1000['dropped'] ) );
// Under 4000 characters and fine for PCRE, but too deep for Rust's regex:
// Ferron 3.0.0-rc.10 rejected /blog/filter/a+b/ (410) with the unchecked N1
// G160 pattern (depth 250) and let it through (404, no error) at G162 (253).
$c162 = guard_case( 1, 162, $odd( 162 ) );
$c126 = guard_case( 1, 126, $odd( 126 ) );
chk( 'N1 G162 alternating: per_group under 4000 characters but nested 253 deep, null with reason nesting; G126 (depth 199) kept',
	$guard_ok && strlen( (string) $c162['raw']['per_group'] ) < 4000 && $c162['uri']['per_group'] === null && ( $c162['dropped']['per_group'] ?? '' ) === 'nesting'
	&& is_string( $c126['uri']['per_group'] ) && $c126['dropped'] === [], show( [ $c162['dropped'], $c126['dropped'] ] ) );
// What PCRE still compiles but Go or Rust would not.
chk( 'pattern_problem: repeat (a{1001} compiles in PCRE, not in Go), nesting (101 nested (?:…)?: depth 203 > 200), length (4000 ok, 4001 not)',
	call( 'jpkcom_postfilter_server_pattern_problem', '^/(?:a){1001}$', '^[A-Z]+ /(?:a){1001}(?:\\s|$)' ) === 'repeat'
	&& call( 'jpkcom_postfilter_server_pattern_problem', '^/x' . str_repeat( '(?:', 101 ) . 'a' . str_repeat( ')?', 101 ) . '$', '^/x' ) === 'nesting'
	&& call( 'jpkcom_postfilter_server_pattern_problem', '^/x' . str_repeat( '(?:', 99 ) . 'a' . str_repeat( ')?', 99 ) . '$', '^/x' ) === null
	&& call( 'jpkcom_postfilter_server_pattern_problem', '^/' . str_repeat( 'a', 3998 ), '^/' ) === null
	&& call( 'jpkcom_postfilter_server_pattern_problem', '^/a', '^/' . str_repeat( 'a', 3999 ) ) === 'length' );

$c_n = guard_case( 1001, 2, [] );
chk( 'N1001 (filtered cap): per_group null (repeat or nesting), segments kept', $c_n['uri']['per_group'] === null && isset( $c_n['dropped']['per_group'] ) && is_string( $c_n['uri']['segments'] ), show( $c_n['dropped'] ) );

// --- the managed .htaccess and every snippet carry no oversized rule ---
$big_ok = true;
foreach ( [ $c71, $c37, $c40, guard_case( 20, 21, $odd( 21 ) ), guard_case( 10, 60, $odd( 60 ) ) ] as $case ) {
	$block  = (string) jpkcom_postfilter_server_htaccess_marked( inputs: $case['in'], label: 'JPKCom Post Filter' );
	$big_ok = $big_ok && max( array_map( 'strlen', explode( "\n", $block ) ) ) < 8192;
	foreach ( [ 'nginx', 'apache', 'htaccess', 'ols', 'caddy', 'ferron3' ] as $server ) {
		foreach ( jpkcom_postfilter_server_snippets( $server, $case['in'], jpkcom_postfilter_rate_limits( 8, 0.4 ) ) as $snippet ) {
			// nginx reads a quoted parameter of at most 4094 characters.
			if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $snippet['code'], $m ) ) {
				foreach ( $m[1] as $quoted ) {
					$big_ok = $big_ok && strlen( $quoted ) <= 4094;
				}
			}
			$big_ok = $big_ok && max( array_map( 'strlen', explode( "\n", $snippet['code'] ) ) ) < 8192;
		}
	}
}
chk( 'oversized layouts: managed .htaccess block and every snippet stay under 8192 bytes per line and 4094 per quoted pattern', $big_ok );
chk( 'N20 G40 alternating: managed .htaccess keeps the segments rule, has no per-group rule',
	str_contains( (string) jpkcom_postfilter_server_htaccess_marked( inputs: $c40['in'], label: 'JPKCom Post Filter' ), 'server-segments' )
	&& ! str_contains( (string) jpkcom_postfilter_server_htaccess_marked( inputs: $c40['in'], label: 'JPKCom Post Filter' ), 'server-per-group' ) );

// --- sweep: whatever comes out compiles, fits, and null <=> reason ---
$sweep_ok    = $guard_ok;
$sweep_drops = 0;
$sweep_seen  = 0;
foreach ( [ 1, 2, 5, 10, 20 ] as $n ) {
	foreach ( array_merge( range( 1, 40 ), range( 47, 130, 9 ), [ 996, 997, 998 ] ) as $g ) {
		foreach ( [ [], [ $g - 1 ], $odd( $g ) ] as $skip ) {
			if ( ! $sweep_ok ) {
				break 3;
			}
			$in      = layout_inputs( $n, $g, $skip, [ '/blog/', '/news/' ] );
			$dropped = jpkcom_postfilter_server_patterns_dropped( $in );
			$raw     = jpkcom_postfilter_server_reject_patterns_unchecked( $in );
			foreach ( [ 'uri', 'request_line' ] as $subject ) {
				$p = jpkcom_postfilter_server_reject_patterns( $in, $subject );
				foreach ( [ 'per_group', 'segments' ] as $k ) {
					$sweep_seen++;
					if ( $raw[ $k ] === null ) {
						$sweep_ok = $sweep_ok && $p[ $k ] === null && ! isset( $dropped[ $k ] );
						continue;
					}
					if ( isset( $dropped[ $k ] ) ) {
						$sweep_drops++;
						$sweep_ok = $sweep_ok && $p[ $k ] === null;
						continue;
					}
					$shape    = jpkcom_postfilter_server_regex_shape( (string) $p[ $k ] );
					$sweep_ok = $sweep_ok && is_string( $p[ $k ] ) && strlen( $p[ $k ] ) <= 4000
						&& @preg_match( "\x01" . $p[ $k ] . "\x01", '' ) !== false && $shape['repeat_ok'] && $shape['depth'] <= 200;
				}
			}
			if ( ! $sweep_ok ) {
				echo "        guard mismatch at N{$n} G{$g} skip[" . implode( ',', $skip ) . ']: ' . show( $dropped ) . "\n";
			}
		}
	}
}
chk( 'sweep N 1-20, G 1-998: every pattern left compiles, is at most 4000 characters, RE2- and nesting-safe; null exactly where a reason is given', $sweep_ok && $sweep_drops > 0, $sweep_seen . ' patterns, ' . $sweep_drops . ' dropped' );

// --- normal layouts: unchanged byte for byte ---
/** Normal layouts: N 1-5, G 1-8, four skip shapes, four base sets. */
function guard_layouts(): array {
	$out = [];
	foreach ( [ 1, 2, 3, 4, 5 ] as $n ) {
		for ( $g = 1; $g <= 8; $g++ ) {
			$skips = [ [], [ 0 ], [ $g - 1 ], array_values( array_filter( range( 0, $g - 1 ), static fn( int $p ): bool => $p % 2 === 1 ) ) ];
			foreach ( array_unique( array_map( 'serialize', $skips ) ) as $skip ) {
				foreach ( [ [ '/blog/' ], [ '/blog/', '/news/' ], [ '/' ], [ '/blog/', '/en/blog/' ] ] as $bases ) {
					$out[] = [ $n, $g, unserialize( $skip ), $bases ];
				}
			}
		}
	}
	return $out;
}
$normal     = [];
$normal_ok  = $guard_ok;
foreach ( guard_layouts() as [ $n, $g, $skip, $bases ] ) {
	$in       = layout_inputs( $n, $g, $skip, $bases );
	$pair     = [ jpkcom_postfilter_server_reject_patterns( $in ), jpkcom_postfilter_server_reject_patterns( $in, 'request_line' ) ];
	$normal[] = $pair;
	$normal_ok = $normal_ok && jpkcom_postfilter_server_patterns_dropped( $in ) === []
		&& $pair === [ jpkcom_postfilter_server_reject_patterns_unchecked( $in ), jpkcom_postfilter_server_reject_patterns_unchecked( $in, 'request_line' ) ];
}
chk( 'normal layouts (N 1-5, G 1-8, 580 layouts): nothing dropped, guarded === unchecked', $normal_ok );
// sha256 of the same list built by the v1.6.0 code (be1cf39).
chk( 'normal layouts: both pattern forms byte-identical to v1.6.0', hash( 'sha256', (string) json_encode( $normal ) ) === 'c7ccec8ee9dece6dade60e0800a1853b8354f5047391f78308c104739933b4e4' && count( $normal ) === 580 );

// --- the shape check, calibrated against the engines ---
$shape = static fn( string $re ): array => (array) call( 'jpkcom_postfilter_server_regex_shape', $re );
$nest  = static fn( int $k ): string => '^/x' . str_repeat( '(?:', $k ) . 'a' . str_repeat( ')?', $k ) . '$';
// Ferron 3.0.0-rc.10 (Rust regex): k = 124 matched (410), k = 125 never matched.
chk( 'regex_shape: nesting depth as Rust regex counts it (Ferron 3: 124 nested (?:…)? = 249 compile, 125 = 251 do not)',
	( $shape( $nest( 124 ) )['depth'] ?? 0 ) === 249 && ( $shape( $nest( 125 ) )['depth'] ?? 0 ) === 251, show( [ $shape( $nest( 124 ) ), $shape( $nest( 125 ) ) ] ) );
// Go 1.27 regexp: {1000} ok, {1001} not; (?:(?:a){10}){100} ok, {101} not; a{2,} counts its minimum.
chk( 'regex_shape: Go\'s repetition rule (count and nested product at most 1000)',
	( $shape( '^a{1000}$' )['repeat_ok'] ?? false ) === true && ( $shape( '^a{1001}$' )['repeat_ok'] ?? true ) === false
	&& ( $shape( '^(?:(?:a){10}){100}$' )['repeat_ok'] ?? false ) === true && ( $shape( '^(?:(?:a){10}){101}$' )['repeat_ok'] ?? true ) === false
	&& ( $shape( '^(?:a{2,}){500}$' )['repeat_ok'] ?? false ) === true && ( $shape( '^(?:a{2,}){501}$' )['repeat_ok'] ?? true ) === false
	&& ( $shape( '^(?:a+){1000}$' )['repeat_ok'] ?? false ) === true && ( $shape( '^[^{}]{0,3}\{x\}$' )['repeat_ok'] ?? false ) === true );

jpkpf_test_rm_tree( WP_CONTENT_DIR );
chk( 'temp directory removed', ! is_dir( WP_CONTENT_DIR ) );

exit( summary() );
