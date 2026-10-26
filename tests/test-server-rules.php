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
 * JPKPF_FUZZ is the number of fuzz cases per layout (120 layouts), split over
 * seeds 1-3. The default is 9000 to keep CI near 15 s; 30 000 per layout
 * takes about 50 s on a desktop machine.
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
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-server-rules-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

@mkdir( WP_CONTENT_DIR, 0755, true );

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
	function __( $s, $d = '' ) { return $s; }
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

foreach ( [ 'url-policy.php', 'url-routing.php', 'crawl-guard.php', 'server-rules.php' ] as $file ) {
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
	'grammar pinned for N 2, G 2, /blog/ (linear form)',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:blog/)filter/+(?:(?:\\++|\\+*[^/?%+]+(?:\\++[^/?%+]+)?\\+*)/+)*\\+*[^/?%+]+(?:\\++[^/?%+]+){2}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$'
	&& $p['segments'] === '^/+(?:blog/)filter/+(?:(?:[^/?%]+/+){2}[^/?%+]*\\+|(?:[^/?%]+/+){5}[^/?%])[^?%]*(?:\\?.*)?$',
	show( $p )
);
$p = call( 'jpkcom_postfilter_server_reject_patterns', layout_inputs( 3, 3, [ 0 ], [ '/', '/en/my.blog/' ] ) );
chk(
	'skip position 0: per-group only at positions 1, 2 or past G; bases quoted',
	is_array( $p )
	&& $p['per_group'] === '^/+(?:|en/my\\.blog/)filter/+(?:(?:[^/?%]+/+){1}|(?:[^/?%]+/+){2}|(?:[^/?%]+/+){3}(?:(?:\\++|\\+*[^/?%+]+(?:\\++[^/?%+]+(?:\\++[^/?%+]+)?)?\\+*)/+)*)\\+*[^/?%+]+(?:\\++[^/?%+]+){3}(?:(?:\\+|/)[^?%]*)?(?:\\?.*)?$',
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
 * quote helper emits, negated classes, (?:...), * + ? {n} {n,}, |, ^, $ and the
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
				if ( preg_match( '/\G\{[0-9]+,?\}/', $re, $m, 0, $i ) !== 1 ) {
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
		foreach ( [ 1, 2, 3 ] as $g ) {
			foreach ( [ [], [ 0 ] ] as $skip ) {
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
			foreach ( [ [], [ 0 ] ] as $skip ) {
				foreach ( $base_sets as $bn => $bs ) {
					$skip_name = $skip ? '[0]' : '[]';
					$out[]     = $bs + [ 'name' => sprintf( 'N%d G%d skip%s %s', $n, $g, $skip_name, $bn ), 'N' => $n, 'G' => $g, 'skip' => $skip ];
				}
			}
		}
	}
	return $out;
}

/** Taxonomies in position order for a layout. */
function layout_taxonomies( array $layout ): array {
	$taxes = [ 'category', 'post_tag', 'colour' ];
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
 * Slowest pattern/URI pair over adversarial 2000-character URIs, best of 3
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
	$bodies = [ 'a/', 'a+', '+', '/', 'a', 'ab+a/', 'a+/', '+/', 'a++', 'a/+', '_/', 'page/', 'a+b+c/x/', 'a+b+c+d+e/', '+a+b+c+d+e+/', 'a+b+c+d+e+f/', 'a//', 'ab' ];
	foreach ( [ [ 5, 3, [ 0 ], [ '/blog/', '/en/blog/', '/news/' ] ], [ 2, 2, [], [ '/blog/' ] ], [ 1, 3, [], [ '/' ] ] ] as [ $n, $g, $skip, $bases ] ) {
		$pp = jpkcom_postfilter_server_reject_patterns( layout_inputs( $n, $g, $skip, $bases ) );
		foreach ( $bodies as $body ) {
			foreach ( [ '%', '?', '', '%/', '/%' ] as $end ) {
				$lead = $bases[0] . 'filter/';
				$uri  = $lead . substr( str_repeat( $body, 2000 ), 0, 2000 - strlen( $lead ) - strlen( $end ) ) . $end;
				foreach ( $pp as $re ) {
					if ( ! is_string( $re ) ) {
						continue;
					}
					$rx   = $d . $re . $d;
					$best = INF;
					for ( $r = 0; $r < 3; $r++ ) {
						$t = hrtime( true );
						preg_match( $rx, $uri );
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

exit( summary() );
