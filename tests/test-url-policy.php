<?php
/**
 * Regression tests for the 1.5.0 crawl guard: one URL per selection, hard
 * caps, a request guard before the main query, and term links that do not
 * generate an unbounded URL space.
 *
 * Background: on a production site ~840 000 requests a day went to
 * /blog/filter/{a+b+c}/{x+y+z}/ - every URL unique, every one a full render
 * with HTTP 200 - because the filter bar linked every term to "current
 * selection + this term" in click order and the server accepted any spelling.
 *
 * Written to fail against 1.4.7. Run with:
 *
 *     php tests/test-url-policy.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.5.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-policy-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );
// 1.7.0: these checks pin the 1.5.0 robots rules, which the canonical setting
// "filter" keeps unchanged. The 1.7.0 targets (term archive, archive base) and
// their effect on robots are tested in tests/test-canonical.php.
define( 'JPKCOM_POSTFILTER_CANONICAL_SINGLE', 'filter' );
define( 'JPKCOM_POSTFILTER_CANONICAL_MULTI', 'filter' );

@mkdir( WP_CONTENT_DIR, 0755, true );

// --- WordPress stand-ins this suite needs beyond bootstrap.php ---------------

function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function home_url( $path = '' ) { return 'https://example.test' . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function get_permalink( $id ) { return (int) $id === 5 ? 'https://example.test/blog/' : false; }
function get_post_type_archive_link( $pt ) { return false; }
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
 * fails the check instead of ending the run, so a run against 1.4.7 shows
 * every gap rather than the first one.
 */
function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

/**
 * Set the general settings group and drop any per-request memo.
 */
function set_general( array $general ): void {
	$GLOBALS['__options']['jpkcom_postfilter_general'] = $general;
	if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
		jpkcom_postfilter_settings_memo_reset();
	}
}

$GLOBALS['__options']['page_for_posts']           = 5;
$GLOBALS['__options']['jpkcom_postfilter_cache']  = [ 'settings_cache_enabled' => false ];
$GLOBALS['__taxonomies']                          = [ 'category', 'post_tag' ];
$GLOBALS['__terms']                               = [
	// c%2b%2b: a slug typed or imported percent-encoded. WordPress never makes
	// one itself (sanitize_title( 'C++' ) is 'c'), but keeps it verbatim.
	'category' => [ 'sichtschutz', 'tueren-tore', 'zaeune', 'metallbau', 'c', 'c%2b%2b' ],
	'post_tag' => [ 'einbruchschutz', 'gewerbe-industrie', 'metallcarport', 'privat' ],
];

// WPML narrows get_terms() to the current language, and the plugin's term-list
// transient is keyed by taxonomy only. If the guard asked that list, every
// post_tag slug but `privat` would count as unknown here and be redirected
// away - which is what happens to the second language on a WPML site.
$GLOBALS['__terms_lang_filter'] = [ 'post_tag' => [ 'privat' ] ];

// 1.6.0 changed the default to 2; the sections below test the 3-per-group behaviour, so they pin it.
set_general( [ 'max_filters_per_group' => 3 ] );

$base = 'https://example.test/blog/';
$bot  = [
	'category' => [ 'zaeune', 'sichtschutz', 'tueren-tore' ],
	'post_tag' => [ 'gewerbe-industrie', 'metallcarport', 'einbruchschutz' ],
];

// ---------------------------------------------------------------------------
section( 'One selection, one URL' );

$url = jpkcom_postfilter_get_filter_url( $base, $bot );
chk(
	'the bot URL from the logs is built in sorted order',
	$url === $base . 'filter/sichtschutz+tueren-tore+zaeune/einbruchschutz+gewerbe-industrie+metallcarport/',
	$url
);

$perms = [];
foreach ( [ [ 'a', 'b', 'c' ], [ 'a', 'c', 'b' ], [ 'b', 'a', 'c' ], [ 'b', 'c', 'a' ], [ 'c', 'a', 'b' ], [ 'c', 'b', 'a' ] ] as $p ) {
	$perms[] = jpkcom_postfilter_get_filter_url( $base, [ 'category' => $p ] );
}
chk( 'all six permutations of three terms build the same URL', count( array_unique( $perms ) ) === 1, implode( ' ', array_unique( $perms ) ) );

$url = jpkcom_postfilter_get_filter_url( $base, [ 'category' => [ 'a', 'a' ] ] );
chk( 'a duplicate slug is written once', $url === $base . 'filter/a/', $url );

$parsed = jpkcom_postfilter_parse_filter_path( 'zaeune+sichtschutz+zaeune' );
chk( 'the parser sorts and de-duplicates', $parsed === [ 'category' => [ 'sichtschutz', 'zaeune' ] ], json_encode( $parsed ) );

$round_trip_ok = true;
foreach ( [ $bot, [ 'post_tag' => [ 'privat' ] ], [ 'category' => [ 'metallbau' ], 'post_tag' => [ 'privat', 'einbruchschutz' ] ] ] as $map ) {
	$path = (string) preg_replace( '#^' . preg_quote( $base . 'filter/', '#' ) . '#', '', jpkcom_postfilter_get_filter_url( $base, $map ) );
	$back = jpkcom_postfilter_parse_filter_path( $path );
	$want = call( 'jpkcom_postfilter_normalize_filters', $map );
	if ( $back !== $want ) {
		$round_trip_ok = false;
	}
}
chk( 'parse( get_filter_url( x ) ) == normalize( x ) - otherwise the guard would redirect in a loop', $round_trip_ok );

// PHP and JS build the same URL.
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
	echo "  SKIP  node not available — parity between PHP and JS was NOT verified\n";
} else {
	$cases  = json_decode( (string) file_get_contents( __DIR__ . '/filter-url-cases.json' ), true );
	$js_raw = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/filter-url.mjs' ) . ' 2>&1' );
	$js     = json_decode( $js_raw, true );

	if ( ! is_array( $js ) ) {
		chk( 'JS harness ran', false, trim( $js_raw ) );
	} else {
		foreach ( $cases as $i => $case ) {
			$php = jpkcom_postfilter_get_filter_url( $base, $case['active'] );
			chk( 'PHP == JS: ' . $case['note'], $php === ( $js[ $i ] ?? null ), 'php ' . $php . ' | js ' . ( $js[ $i ] ?? 'null' ) );
		}
	}
}

// ---------------------------------------------------------------------------
section( 'Paths that can never be a selection are rejected, not truncated' );

$tax = [ 'category', 'post_tag' ];

$v = call( 'jpkcom_postfilter_validate_filter_path', 'a+b+c+d/x', $tax, null );
chk( 'four terms against max_filters_per_group = 3', ( $v['reason'] ?? '' ) === 'per-group', json_encode( $v ) );

$v = call( 'jpkcom_postfilter_validate_filter_path', 'a/b/c', $tax, null );
chk( 'a third segment with two filter groups', ( $v['reason'] ?? '' ) === 'segments', json_encode( $v ) );

$v = call( 'jpkcom_postfilter_validate_filter_path', 'a+b+c/x+y+z', $tax, null );
chk( '3+3 is valid at the default caps', ( $v['action'] ?? '' ) === 'ok' );

set_general( [ 'max_terms_total' => 4, 'max_filters_per_group' => 3 ] );
$v = call( 'jpkcom_postfilter_validate_filter_path', 'a+b+c/x+y', $tax, null );
chk( 'max_terms_total caps the whole selection', ( $v['reason'] ?? '' ) === 'total', json_encode( $v ) );
set_general( [ 'max_filters_per_group' => 3 ] );

$known = static fn( string $t ): ?array => $GLOBALS['__terms'][ $t ] ?? null;

$v = call( 'jpkcom_postfilter_validate_filter_path', 'zaeune+erfunden', $tax, $known, 'redirect' );
chk( 'unknown slugs are dropped for a redirect', ( $v['filters'] ?? null ) === [ 'category' => [ 'zaeune' ] ], json_encode( $v ) );

$v = call( 'jpkcom_postfilter_validate_filter_path', 'zaeune+erfunden', $tax, $known, 'reject' );
chk( 'or rejected when configured so', ( $v['reason'] ?? '' ) === 'unknown' );

$v = call( 'jpkcom_postfilter_validate_filter_path', str_repeat( 'a', 201 ), $tax, null );
chk( 'an absurdly long slug', ( $v['reason'] ?? '' ) === 'length' );

// ---------------------------------------------------------------------------
section( '1.6.0 raw per-group count' );

// The cap counts non-empty raw pieces (after the split, before sanitize_title
// and de-duplication), summed per taxonomy, so a server regex can mirror it.
set_general( [ 'max_filters_per_group' => 2 ] );

$validate = static fn( string $path, ?array $known = null, array $groups = [ 'category', 'post_tag' ] ): array
	=> call(
		'jpkcom_postfilter_validate_filter_path',
		$path,
		$groups,
		$known === null ? null : static fn( string $t ): ?array => $known[ $t ] ?? null
	);

chk( 'raw: a+a+b over cap 2 is per-group',   ( $validate( 'a+a+b' )['reason'] ?? '' ) === 'per-group' );
chk( 'raw: A+a+b over cap 2 is per-group',   ( $validate( 'A+a+b' )['reason'] ?? '' ) === 'per-group' );
chk( 'raw: a+-+- over cap 2 is per-group',   ( $validate( 'a+-+-' )['reason'] ?? '' ) === 'per-group' );
// T1-a: a piece that sanitizes to nothing still counts. Core's
// sanitize_title( '-' ) is '' (sanitize_title_with_dashes() trims dashes), so
// a count after sanitizing would see a+b and let a+b+- through at N = 2; the
// raw count sees three pieces. The precondition makes sure the stub can tell
// the two apart - with a stub that keeps '-' both counts would reject.
chk( 'stub precondition: sanitize_title( \'-\' ) is empty, as in core', sanitize_title( '-' ) === '' && sanitize_title( '-a-' ) === 'a' );
chk( 'raw: a+b+- over cap 2 is per-group (the empty-sanitizing piece counts)', ( $validate( 'a+b+-' )['reason'] ?? '' ) === 'per-group' );
chk( 'raw: a+a within cap stays ok',         ( $validate( 'a+a' )['action'] ?? '' ) === 'ok' );
chk( 'raw: a++b empty pieces not counted',   ( $validate( 'a++b' )['action'] ?? '' ) === 'ok' );
chk( 'raw: +a+b+ empty ends not counted',    ( $validate( '+a+b+' )['action'] ?? '' ) === 'ok' );
chk(
	'raw: known c%2b%2b+a is two pieces',
	( $validate( 'c%2b%2b+a', [ 'category' => [ 'c%2b%2b', 'a' ] ] )['action'] ?? '' ) === 'ok'
);
chk( 'raw: _ segment still skipped',         ( $validate( '_/x+y' )['action'] ?? '' ) === 'ok' );
chk(
	'raw: same taxonomy twice sums pieces',
	( $validate( 'a/a+b', null, [ 'category', 'category' ] )['reason'] ?? '' ) === 'per-group'
);

set_general( [ 'max_filters_per_group' => 3 ] );

// ---------------------------------------------------------------------------
section( 'The request guard answers before the main query' );

/**
 * Verdict for a request path, as the `request` filter would see it.
 */
function verdict_for( string $uri, bool $fragment = false, string $method = 'GET' ): array {
	$path  = (string) parse_url( $uri, PHP_URL_PATH );
	$qv    = [ 'page_id' => '5', 'jpkcom_filter_post_type' => 'post' ];
	$inner = (string) preg_replace( '#^/blog/filter/#', '', $path );
	$inner = (string) preg_replace( '#/jpkpf-fragment/?$#', '', $inner );

	if ( preg_match( '#^(.+?)/page/?([0-9]{1,})/?$#', $inner, $m ) ) {
		$qv['jpkcom_filter_path'] = $m[1];
		$qv['paged']              = $m[2];
	} else {
		$qv['jpkcom_filter_path'] = rtrim( $inner, '/' );
	}
	if ( $fragment ) {
		$qv['jpkcom_filter_fragment'] = '1';
	}

	$v = call( 'jpkcom_postfilter_request_verdict', $qv, $uri, $method );
	return is_array( $v ) ? $v : [ 'action' => (string) $v ];
}

$v = verdict_for( '/blog/filter/zaeune+sichtschutz+tueren-tore/gewerbe-industrie+metallcarport+einbruchschutz/' );
chk(
	'the bot URL is redirected to its sorted form',
	( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/sichtschutz+tueren-tore+zaeune/einbruchschutz+gewerbe-industrie+metallcarport/',
	json_encode( $v )
);

$v = verdict_for( '/blog/filter/sichtschutz+tueren-tore+zaeune/einbruchschutz+gewerbe-industrie+metallcarport/' );
chk( 'the sorted form is served', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

$v = verdict_for( '/blog/filter/metallbau+sichtschutz+tueren-tore+zaeune/' );
chk( 'four terms are rejected with 410', ( $v['action'] ?? '' ) === 'reject' && ( $v['status'] ?? 0 ) === 410, json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune/privat/zaeune/' );
chk( 'an extra segment is rejected instead of ignored', ( $v['action'] ?? '' ) === 'reject', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune/page/1/' );
chk( 'page/1 is redirected to the page itself', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/zaeune/', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune/page/2/' );
chk( 'page/2 is served', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune/_/' );
chk( 'a trailing placeholder is redirected away', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/zaeune/', json_encode( $v ) );

$v = verdict_for( '/blog/filter/Zaeune/' );
chk( 'upper case is redirected to the canonical slug', ( $v['action'] ?? '' ) === 'redirect', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune+erfunden/' );
chk( 'an unknown slug is redirected away', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/zaeune/', json_encode( $v ) );

$v = verdict_for( '/blog/filter/erfunden/' );
chk( 'nothing but unknown slugs goes to the archive', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base, json_encode( $v ) );

$v = verdict_for( '/blog/filter/_/' );
chk( 'a selection of nothing goes to the archive', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base, json_encode( $v ) );

$v = verdict_for( '/blog/filter/_/einbruchschutz/' );
chk( 'a term outside the current WPML language is still known (not redirected away)', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune%2Bsichtschutz/' );
chk(
	'%2B counts as the separator (was: one unknown slug, redirect to the archive)',
	( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/sichtschutz+zaeune/',
	json_encode( $v )
);
chk( '... and the parser agrees', jpkcom_postfilter_parse_filter_path( 'zaeune%2Bsichtschutz' ) === [ 'category' => [ 'sichtschutz', 'zaeune' ] ] );

$v = verdict_for( '/blog/filter/c%2b%2b/' );
chk( '... but a real slug containing %2b ("C++") stays one term', ( $v['action'] ?? '' ) === 'ok' && ( $v['path'] ?? '' ) === 'c%2b%2b', json_encode( $v ) );
chk( '... in the parser too', jpkcom_postfilter_parse_filter_path( 'c%2b%2b' ) === [ 'category' => [ 'c%2b%2b' ] ], json_encode( jpkcom_postfilter_parse_filter_path( 'c%2b%2b' ) ) );
$v = verdict_for( '/blog/filter/C%2B%2B+zaeune/' );
chk( '... and is normalised with the rest', ( $v['location'] ?? '' ) === $base . 'filter/c%2b%2b+zaeune/', json_encode( $v ) );
$v = verdict_for( '/blog/filter/c%2b%2b%2Bzaeune/' );
chk( '... also when joined to the next slug by an encoded +', ( $v['location'] ?? '' ) === $base . 'filter/c%2b%2b+zaeune/', json_encode( $v ) );
$v = verdict_for( '/blog/filter/zaeune%2Bc%2B%2B/' );
chk( '... in either order', ( $v['location'] ?? '' ) === $base . 'filter/c%2b%2b+zaeune/', json_encode( $v ) );

// The re-joining must stay cheap for any input: an earlier version tried every
// span of every start, cubic in the segment length - 3 KB of %2b held a PHP
// worker for 100 s on the live test site, before any rejection.
$t0 = microtime( true );
jpkcom_postfilter_split_segment( 'x' . str_repeat( '%2b', 20000 ), $known, 'category' );
jpkcom_postfilter_split_segment( implode( '%2b', array_fill( 0, 5000, 'ab' ) ), $known, 'category' );
$elapsed = microtime( true ) - $t0;
chk( 'splitting 60 KB of %2b-joined pieces takes well under a second', $elapsed < 1.0, sprintf( '%.2f s', $elapsed ) );
$v = verdict_for( '/blog/filter/' . str_repeat( 'a%2b', 700 ) . '/' );
chk( 'and the guard rejects an absurdly long path before splitting it', ( $v['action'] ?? '' ) === 'reject' && ( $v['reason'] ?? '' ) === 'length', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune' );
chk( 'a missing trailing slash alone is not redirected (no loop with slash-less permalinks)', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune+sichtschutz/jpkpf-fragment/', true );
chk( 'a fragment is never redirected', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );
chk( '... but served for the canonical selection', ( $v['path'] ?? '' ) === 'sichtschutz+zaeune', json_encode( $v ) );

$v = verdict_for( '/blog/filter/a+b+c+d/jpkpf-fragment/', true );
chk( 'an over-cap fragment is rejected like the page', ( $v['action'] ?? '' ) === 'reject', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune/?x=1' );
chk( 'a query string is kept by default', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

set_general( [ 'strip_query' => true ] );
$v = verdict_for( '/blog/filter/zaeune/?x=1' );
chk( '... and stripped with strip_query', ( $v['action'] ?? '' ) === 'redirect' && ( $v['location'] ?? '' ) === $base . 'filter/zaeune/', json_encode( $v ) );
$v = verdict_for( '/blog/filter/sichtschutz+zaeune/?x=1' );
chk( '... in the same redirect as the reordering', ( $v['location'] ?? '' ) === $base . 'filter/sichtschutz+zaeune/', json_encode( $v ) );
set_general( [] );

$v = verdict_for( '/blog/filter/zaeune+sichtschutz/?utm_source=x' );
chk( 'the reordering redirect keeps the query string', ( $v['location'] ?? '' ) === $base . 'filter/sichtschutz+zaeune/?utm_source=x', json_encode( $v ) );

$v = verdict_for( '/blog/filter/zaeune+sichtschutz/', false, 'POST' );
chk( 'a POST is not redirected', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );

set_general( [ 'strict_urls' => false ] );
$v = verdict_for( '/blog/filter/a+b+c+d/' );
chk( 'strict_urls off restores the pre-1.5.0 acceptance', ( $v['action'] ?? '' ) === 'ok', json_encode( $v ) );
set_general( [] );

set_general( [ 'reject_status' => 404 ] );
$v = verdict_for( '/blog/filter/a+b+c+d/' );
chk( 'reject_status 404', ( $v['status'] ?? 0 ) === 404, json_encode( $v ) );
set_general( [] );

// ---------------------------------------------------------------------------
section( 'CPT archives under a permalink front' );

if ( ! class_exists( 'WP_Rewrite' ) ) {
	class WP_Rewrite {
		public string $front = '/blog/';
		public string $root  = '';
	}
}
$GLOBALS['wp_rewrite'] = new WP_Rewrite();

function get_post_type_object( $pt ) {
	return match ( $pt ) {
		'project' => (object) [ 'has_archive' => 'projekte', 'rewrite' => [ 'slug' => 'project', 'with_front' => true ] ],
		'job'     => (object) [ 'has_archive' => true, 'rewrite' => [ 'slug' => 'jobs', 'with_front' => false ] ],
		default   => null,
	};
}

$r = jpkcom_postfilter_archive_base_regex( 'project' );
chk(
	'with_front: the rule includes the permalink front, like core\'s archive link',
	$r === 'blog/projekte',
	(string) $r . ' - before 1.5.0 the rule ignored /blog/ while get_post_type_archive_link() includes it, so every filter link the plugin built for such a CPT had no rule behind it'
);
$r = jpkcom_postfilter_archive_base_regex( 'job' );
chk( 'without with_front the front is left out', $r === 'jobs', (string) $r );
unset( $GLOBALS['wp_rewrite'] );

// ---------------------------------------------------------------------------
section( 'The plugin query vars only come from the rewrite rule' );

$GLOBALS['wp'] = (object) [ 'matched_query' => '' ];
$qv            = call( 'jpkcom_postfilter_restrict_internal_query_vars', [ 'pagename' => 'kontakt', 'jpkcom_filter_path' => 'zaeune', 'jpkcom_filter_fragment' => '1' ] );
chk( '?jpkcom_filter_path= on an ordinary page is dropped', is_array( $qv ) && ! isset( $qv['jpkcom_filter_path'] ) && ! isset( $qv['jpkcom_filter_fragment'] ), json_encode( $qv ) );

$GLOBALS['wp'] = (object) [ 'matched_query' => 'page_id=5&jpkcom_filter_post_type=post&jpkcom_filter_path=zaeune' ];
$qv            = call( 'jpkcom_postfilter_restrict_internal_query_vars', [ 'page_id' => '5', 'jpkcom_filter_path' => 'privat' ] );
chk( 'the rewrite match wins over a GET override', is_array( $qv ) && ( $qv['jpkcom_filter_path'] ?? '' ) === 'zaeune', json_encode( $qv ) );

// ---------------------------------------------------------------------------
section( 'Term links do not generate the combination space' );

$active = [ 'category' => [ 'zaeune' ] ];

$h = call( 'jpkcom_postfilter_term_href', $base, $active, 'post_tag', 'privat', false );
chk( 'single (default): an inactive term links to its own single-term page', $h === $base . 'filter/_/privat/', (string) $h );

$h = call( 'jpkcom_postfilter_term_href', $base, [ 'category' => [ 'zaeune', 'sichtschutz' ] ], 'category', 'zaeune', true );
chk( 'single: deselecting with one term left links to that term', $h === $base . 'filter/sichtschutz/', (string) $h );

$h = call( 'jpkcom_postfilter_term_href', $base, [ 'category' => [ 'zaeune', 'sichtschutz', 'tueren-tore' ] ], 'category', 'zaeune', true );
chk( 'single: deselecting with two left renders no link', $h === null, (string) $h );

set_general( [ 'filter_link_mode' => 'combo' ] );
$h = call( 'jpkcom_postfilter_term_href', $base, [ 'category' => [ 'zaeune', 'sichtschutz', 'tueren-tore' ] ], 'category', 'metallbau', false );
chk( 'combo: no link beyond max_filters_per_group (was: a 4-term URL the server truncated)', $h === null, (string) $h );

$h = call( 'jpkcom_postfilter_term_href', $base, [ 'category' => [ 'zaeune' ] ], 'category', 'sichtschutz', false );
chk( 'combo: within the caps the link is the sorted combination', $h === $base . 'filter/sichtschutz+zaeune/', (string) $h );

$a = call( 'jpkcom_postfilter_term_link_attributes', $base, [ 'category' => [ 'zaeune' ] ], 'category', 'sichtschutz', false );
chk( 'combo: a link to a non-indexable combination is nofollow', is_array( $a ) && str_contains( $a['attrs'], 'rel="nofollow"' ), json_encode( $a ) );

$a = call( 'jpkcom_postfilter_term_link_attributes', $base, [], 'category', 'zaeune', false );
chk( 'a link to a single-term page is followed', is_array( $a ) && $a['tag'] === 'a' && ! str_contains( $a['attrs'], 'nofollow' ), json_encode( $a ) );

set_general( [ 'filter_link_mode' => 'none' ] );
$a = call( 'jpkcom_postfilter_term_link_attributes', $base, [], 'category', 'zaeune', false );
chk( 'none: a button, no href', is_array( $a ) && $a['tag'] === 'button' && ! str_contains( $a['attrs'], 'href' ), json_encode( $a ) );
set_general( [] );

$templates = [];
foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
	foreach ( glob( __DIR__ . '/../' . $dir . '/partials/filter/*.php' ) ?: [] as $f ) {
		$templates[ basename( $dir ) . '/' . basename( $f ) ] = (string) file_get_contents( $f );
	}
}
$bad = array_keys( array_filter( $templates, static fn( string $src ): bool => ! str_contains( $src, 'jpkcom_postfilter_term_link_attributes(' ) || str_contains( $src, 'array_merge( $active_filters[' ) ) );
chk( 'every filter layout (and its debug copy) builds term links through the helper', count( $templates ) === 8 && $bad === [], implode( ', ', $bad ) );

$bad = array_keys( array_filter( $templates, static fn( string $src ): bool => (bool) preg_match( '/jpkpf-filter-reset".{0,300}?rel="nofollow".{0,200}?Reset filters/s', $src ) ) );
chk( 'the reset link - the one way out of the combinations - is followable', $bad === [], implode( ', ', $bad ) );

foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
	$src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/partials/pagination/pagination.php' );
	chk(
		"{$dir}: pagination links page 1 without /page/1/",
		str_contains( $src, "'%_%'" ) && str_contains( $src, "'format'    => 'page/%#%/'" ),
		'With the page in the base and an empty format, core links page 1 as …/page/1/ - a duplicate of the page.'
	);
}

// ---------------------------------------------------------------------------
section( 'Robots and canonical' );

/**
 * Set the request up as a filter page for the given selection.
 */
function as_filter_page( array $filters ): void {
	$segments = [];
	foreach ( $filters as $t => $s ) {
		$segments[ $t ] = implode( '+', $s );
	}
	$GLOBALS['__query_vars'] = [
		'jpkcom_filter_active'    => $filters ? '1' : '',
		'jpkcom_filter_segments'  => $segments,
		'jpkcom_filter_post_type' => 'post',
	];
}

as_filter_page( [ 'category' => [ 'zaeune' ] ] );
$d = call( 'jpkcom_postfilter_robots_directives' );
chk( 'a single-term page is index, follow', $d === [ 'index' => true, 'follow' => true ], json_encode( $d ) );
$c = call( 'jpkcom_postfilter_canonical_url' );
chk( '... with a self canonical', $c === $base . 'filter/zaeune/', (string) $c );

as_filter_page( [ 'category' => [ 'sichtschutz', 'zaeune' ] ] );
$d = call( 'jpkcom_postfilter_robots_directives' );
chk( 'a combination is noindex, nofollow', $d === [ 'index' => false, 'follow' => false ], json_encode( $d ) );
$c = call( 'jpkcom_postfilter_canonical_url' );
chk( '... and has no canonical', $c === '', var_export( $c, true ) );

$rm = [ 'index' => 'index', 'follow' => 'follow' ];
foreach ( $GLOBALS['__filters']['rank_math/frontend/robots'] ?? [] as $cb ) {
	$rm = $cb( $rm );
}
chk(
	'Rank Math gets its native keys (it drops its own canonical only on index = noindex)',
	( $rm['index'] ?? '' ) === 'noindex' && ( $rm['follow'] ?? '' ) === 'nofollow' && ! isset( $rm['noindex'] ),
	json_encode( $rm )
);

$rm_canonical = $base;
foreach ( $GLOBALS['__filters']['rank_math/frontend/canonical'] ?? [] as $cb ) {
	$rm_canonical = $cb( $rm_canonical );
}
chk( 'Rank Math does not print /blog/ as the canonical of a filter page', $rm_canonical === '', var_export( $rm_canonical, true ) );

as_filter_page( [] );
chk( 'a non-filter request is left alone', call( 'jpkcom_postfilter_robots_directives' ) === null );

set_general( [ 'seo_index_max_terms' => 2 ] );
as_filter_page( [ 'category' => [ 'sichtschutz', 'zaeune' ] ] );
$d = call( 'jpkcom_postfilter_robots_directives' );
chk( 'seo_index_max_terms = 2 indexes two-term pages', $d === [ 'index' => true, 'follow' => true ], json_encode( $d ) );
set_general( [] );
as_filter_page( [] );

// ---------------------------------------------------------------------------
section( 'robots.txt' );

$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => $GLOBALS['__groups'] ];
$rules = call( 'jpkcom_postfilter_robots_txt_rules' );
chk(
	'deep (default): combinations are disallowed for everyone',
	is_array( $rules ) && in_array( 'Disallow: /blog/filter/*+', $rules, true ),
	json_encode( $rules )
);
chk(
	'... but not a second segment: /filter/_/term/ is a linked, indexable single-term page',
	is_array( $rules ) && ! in_array( 'Disallow: /blog/filter/*/*/', $rules, true ),
	json_encode( $rules )
);
chk( '... single-term pages are not', is_array( $rules ) && ! in_array( 'Disallow: /blog/filter/', $rules, true ) );
chk(
	'the query-var line matches the parameter in any position',
	is_array( $rules ) && in_array( 'Disallow: /*jpkcom_filter_', $rules, true ) && ! in_array( 'Disallow: /*?jpkcom_filter_', $rules, true )
);

set_general( [ 'robots_txt_mode' => 'off' ] );
chk( 'off: nothing', call( 'jpkcom_postfilter_robots_txt_rules' ) === [] );
set_general( [ 'seo_index_max_terms' => 0 ] );
$rules = call( 'jpkcom_postfilter_robots_txt_rules' );
chk( 'nothing indexable: the whole endpoint', is_array( $rules ) && in_array( 'Disallow: /blog/filter/', $rules, true ), json_encode( $rules ) );
set_general( [] );

// ---------------------------------------------------------------------------
section( 'Settings are read once per request, and never written on a read' );

// File cache switched off: until 1.5.0 every single settings read rewrote the
// cache file - dozens of writes per filter page.
$file = JPKCOM_POSTFILTER_SETTINGS_DIR . '/layout.php';
@unlink( $file );
$GLOBALS['__options']['jpkcom_postfilter_layout'] = [ 'filter_layout' => 'bar' ];
jpkcom_postfilter_settings_get_group( 'layout' );
chk( 'with the file cache off, a read does not write the cache file', ! file_exists( $file ) );

$GLOBALS['__options']['jpkcom_postfilter_cache'] = [ 'settings_cache_enabled' => true ];
if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
	jpkcom_postfilter_settings_memo_reset();
}
jpkcom_postfilter_settings_get_group( 'layout' );
@unlink( $file );
jpkcom_postfilter_settings_get_group( 'layout' );
chk( 'a second read in the same request is served from memory', ! file_exists( $file ) );

// Clean up.
foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f );
	}
}
@rmdir( JPKCOM_POSTFILTER_SETTINGS_DIR );
@rmdir( WP_CONTENT_DIR );

exit( summary() );
