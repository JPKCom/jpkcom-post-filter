<?php
/**
 * Tests for the AJAX fragment response (1.2.0).
 *
 * Scope, stated honestly: this covers the parts that are decidable without a
 * running WordPress — the zone extraction, the fragment URL construction in
 * both languages, and the rewrite-rule ordering. It does NOT prove that
 * remove_all_actions( 'wp_head' ) suppresses what we think it suppresses; that
 * needs a real installation and is written up in CLAUDE.md instead of being
 * asserted here.
 *
 * Run with:
 *     php tests/test-fragment.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.2.0
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );

// --- WordPress stubs -------------------------------------------------------

function add_action( string $h, callable $c, int $p = 10, int $a = 1 ): void {}
function add_filter( string $h, callable $c, int $p = 10, int $a = 1 ): void {}
function apply_filters( string $tag, mixed $value, mixed ...$rest ): mixed {
	return $value;
}
function get_query_var( string $var, mixed $default = '' ): mixed {
	return $GLOBALS['_stub_query_vars'][ $var ] ?? $default;
}
function sanitize_title( string $t ): string {
	return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '-', $t ) ?? '' );
}
function jpkcom_postfilter_debug_log( string $m, array $c = [] ): void {}

require_once dirname( __DIR__ ) . '/includes/fragment-response.php';

// --- Harness ---------------------------------------------------------------

$pass = 0;
$fail = 0;

/**
 * Assert two values are identical.
 *
 * @param string $label Check name.
 * @param mixed  $got      Actual value.
 * @param mixed  $expected Expected value.
 * @param string $why      Explanation printed on failure.
 */
function is_same( string $label, mixed $got, mixed $expected, string $why = '' ): void {
	global $pass, $fail;

	if ( $got === $expected ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        got:      ' . var_export( $got, true ) . "\n";

	if ( '' !== $why ) {
		echo "        {$why}\n";
	}
}

/**
 * json_encode without WordPress loaded.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_fallback( $value ): string {
	return (string) json_encode( $value );
}

/**
 * Report a boolean check.
 *
 * @param string $label Check name.
 * @param bool   $ok    Whether it held.
 * @param string $why   Explanation printed on failure.
 */
function check( string $label, bool $ok, string $why = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";

	if ( '' !== $why ) {
		echo "        {$why}\n";
	}
}

$S = JPKCOM_POSTFILTER_ZONE_START;
$E = JPKCOM_POSTFILTER_ZONE_END;

echo "\nZone extraction\n";

is_same(
	'no markers yields nothing',
	jpkcom_postfilter_extract_zones( '<html><body><p>hi</p></body></html>' ),
	'',
	'A page without a marked zone must produce an empty response, never the page.'
);

is_same(
	'single zone, nested elements preserved',
	jpkcom_postfilter_extract_zones(
		'<header>chrome</header>' . $S . '<div data-jpkpf-results><div class="a"><div class="b">x</div></div></div>' . $E . '<footer>chrome</footer>'
	),
	'<div data-jpkpf-results><div class="a"><div class="b">x</div></div></div>',
	'Nested divs are exactly why this matches markers instead of trying to find a closing tag.'
);

is_same(
	'two zones concatenated in document order',
	jpkcom_postfilter_extract_zones(
		'a' . $S . '<div data-jpkpf-results>R</div>' . $E . 'b' . $S . '<nav data-jpkpf-pagination>P</nav>' . $E . 'c'
	),
	'<div data-jpkpf-results>R</div><nav data-jpkpf-pagination>P</nav>'
);

is_same(
	'chrome between zones is dropped',
	jpkcom_postfilter_extract_zones( $S . 'A' . $E . '<aside>sidebar</aside>' . $S . 'B' . $E ),
	'AB'
);

is_same(
	'unterminated zone yields nothing, not the rest of the document',
	jpkcom_postfilter_extract_zones( '<header>chrome</header>' . $S . '<div>never closed' ),
	'',
	'Running to end-of-document on a missing end marker would leak the full page — '
	. 'exactly the failure this feature exists to prevent.'
);

is_same(
	'complete zones before an unterminated one are still returned',
	jpkcom_postfilter_extract_zones( $S . 'A' . $E . 'x' . $S . 'dangling' ),
	'A'
);

is_same(
	'empty zone stays empty',
	jpkcom_postfilter_extract_zones( $S . $E ),
	''
);

echo "\nZone markers survive an HTML minifier\n";

check(
	'neither marker is an HTML comment',
	! str_contains( $S, '<!--' ) && ! str_contains( $E, '<!--' ),
	'Comments are what a minifier deletes. Autoptimize ships with "Optimize HTML Code" '
	. 'on and "Keep HTML comments" off, and its output buffer is the inner one '
	. '(template_redirect priority 2 against fragment-response.php\'s 0), so it minified '
	. 'the page before extract_zones() saw it: the whole response became 0 bytes on every '
	. 'filter click. Measured on the verification install — 18 300 B without Autoptimize, '
	. '0 B with its stock settings, 8 976 B with keepcomments switched back on.'
);

$page = "<!DOCTYPE html><html><head><title>t</title></head><body>\n<header>chrome</header>\n"
	. $S . '<div data-jpkpf-results><article>Post</article></div>' . $E
	. "\n<footer>chrome</footer>\n</body></html>";

is_same(
	'a comment-stripping minifier leaves the zone findable',
	jpkcom_postfilter_extract_zones( (string) preg_replace( '/<!--.*?-->/s', '', $page ) ),
	'<div data-jpkpf-results><article>Post</article></div>',
	'This is the regression. Every HTML minifier in the WordPress field removes comments; '
	. 'none of them removes elements.'
);

is_same(
	'a whitespace-collapsing minifier leaves the zone findable',
	jpkcom_postfilter_extract_zones( (string) preg_replace( '/\s+/', ' ', $page ) ),
	'<div data-jpkpf-results><article>Post</article></div>',
	'The other half of what minifiers do.'
);

echo "\nA destroyed fragment is answered as one\n";

is_same(
	'a page that marked no zone gets the no-zone answer',
	jpkcom_postfilter_fragment_ob_callback( '<html><body>not a filterable archive</body></html>' ),
	JPKCOM_POSTFILTER_FRAGMENT_ERROR_NO_ZONE,
	'An empty body cannot be told apart from "the filter matched nothing", and the script '
	. 'used to render exactly that — a wrong, empty result set that looked like a working filter.'
);

// Mark one zone the way filter-injection.php does, so the counter knows a zone
// was written; then hand the callback a buffer it has been removed from.
$GLOBALS['_stub_query_vars']['jpkcom_filter_fragment'] = '1';
ob_start();
jpkcom_postfilter_zone_open();
jpkcom_postfilter_zone_close();
ob_end_clean();

is_same(
	'markers written but not found again: the stripped answer',
	jpkcom_postfilter_fragment_ob_callback( (string) preg_replace( '#<template[^>]*></template>#', '', $page ) ),
	JPKCOM_POSTFILTER_FRAGMENT_ERROR_STRIPPED,
	'Counting what was written is what tells "nothing to swap in" apart from "something '
	. 'removed the markers" — the buffer looks the same in both cases.'
);

is_same(
	'an intact buffer still answers with the zone alone',
	jpkcom_postfilter_fragment_ob_callback( $page ),
	'<div data-jpkpf-results><article>Post</article></div>'
);

$js_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/post-filter.js' );

check(
	'the script reloads instead of inventing a no-results message',
	str_contains( $js_source, 'window.location.href = url;' )
		&& ! str_contains( $js_source, 'jpkpf-no-results' ),
	'A fragment without [data-jpkpf-results] is never a zero-result answer: every list '
	. 'template renders the zone before it looks at the query and puts the empty state '
	. 'inside it, and render_zero_results_fallback() guarantees one in auto-inject mode. '
	. 'Writing the message in the script turns a broken response into a plausible wrong '
	. 'one; a full page load is slower and always right.'
);

echo "\nHTML optimisers are told to skip fragment requests\n";

$uri_before = $_SERVER['REQUEST_URI'] ?? null;

$_SERVER['REQUEST_URI'] = '/blog/filter/web-design/';
check(
	'a normal filter URL is not mistaken for a fragment',
	jpkcom_postfilter_request_looks_like_fragment() === false,
	'A false positive would switch off the site\'s HTML optimisation on ordinary pages.'
);

$_SERVER['REQUEST_URI'] = '/blog/filter/web-design/jpkpf-fragment/';
check(
	'the fragment path is recognised without the query var',
	jpkcom_postfilter_request_looks_like_fragment() === true,
	'Autoptimize decides once per request and caches it in a static; two of its modules '
	. 'ask on `wp`, and with AUTOPTIMIZE_INIT_EARLIER it asks on `init`. Neither has a '
	. 'parsed query, so a check that only reads the query var answers "no" and the page '
	. 'gets minified anyway.'
);

$_SERVER['REQUEST_URI'] = '/blog/filter/web-design/jpkpf-fragment';
check( 'trailing slash is optional', jpkcom_postfilter_request_looks_like_fragment() === true );

$_SERVER['REQUEST_URI'] = '/blog/jpkpf-fragment/?utm_source=x';
check( 'a query string does not hide the segment', jpkcom_postfilter_request_looks_like_fragment() === true );

is_same(
	'the callback asks for noptimize on a fragment',
	jpkcom_postfilter_fragment_force_noptimize( false ),
	true
);

$_SERVER['REQUEST_URI'] = '/blog/';

is_same(
	'and leaves an ordinary page alone',
	jpkcom_postfilter_fragment_force_noptimize( false ),
	false
);

is_same(
	'and never turns somebody else\'s yes into a no',
	jpkcom_postfilter_fragment_force_noptimize( true ),
	true,
	'Something else asked for the same thing; overruling it is not this filter\'s business.'
);

if ( $uri_before === null ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $uri_before;
}

$fragment_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/fragment-response.php' );

check(
	'the opt-out is registered at load time, not from template_redirect',
	strpos( $fragment_src, 'jpkcom_postfilter_fragment_noptimize_filters' ) !== false
		&& strpos( $fragment_src, 'jpkcom_postfilter_fragment_noptimize_filters' )
			< strpos( $fragment_src, "add_action( 'template_redirect'" ),
	'Autoptimize freezes its decision the first time anything calls should_buffer(), and '
	. 'autoptimizeExtra and autoptimizeImages both call it on `wp`. A filter added in '
	. 'template_redirect arrives after the answer has already been cached.'
);

echo "\nFragment URL construction (PHP)\n";

is_same( 'bare archive', jpkcom_postfilter_fragment_url( '/blog/' ), '/blog/jpkpf-fragment/' );
is_same( 'missing trailing slash', jpkcom_postfilter_fragment_url( '/blog' ), '/blog/jpkpf-fragment/' );
is_same( 'filter path', jpkcom_postfilter_fragment_url( '/blog/filter/web-design/' ), '/blog/filter/web-design/jpkpf-fragment/' );
is_same( 'paginated', jpkcom_postfilter_fragment_url( '/blog/filter/a/page/2/' ), '/blog/filter/a/page/2/jpkpf-fragment/' );
is_same( 'site root', jpkcom_postfilter_fragment_url( '/' ), '/jpkpf-fragment/' );

is_same(
	'query string stays after the segment',
	jpkcom_postfilter_fragment_url( 'https://example.com/blog/filter/a/?utm_source=x&y=2' ),
	'https://example.com/blog/filter/a/jpkpf-fragment/?utm_source=x&y=2',
	'The segment belongs in the path. Appending it after the query string would produce a URL no rewrite rule matches.'
);

is_same(
	'hash stays last',
	jpkcom_postfilter_fragment_url( '/blog/filter/a/?p=1#results' ),
	'/blog/filter/a/jpkpf-fragment/?p=1#results'
);

echo "\nFragment URL parity: PHP vs. assets/js/post-filter.js\n";

$cases_file = __DIR__ . '/fragment-url-cases.json';
$node       = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( $node === '' ) {

	// Not counted as a pass. A skipped check that reports success is worse
	// than no check at all.
	echo "  SKIP  node not available — parity between PHP and JS was NOT verified\n";

} else {

	$cases = json_decode( (string) file_get_contents( $cases_file ), true );

	$js_raw = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/fragment-url.mjs' ) . ' 2>&1' );
	$js     = json_decode( (string) $js_raw, true );

	if ( ! is_array( $js ) ) {

		check( 'JS harness ran', false, 'node output was not JSON: ' . trim( (string) $js_raw ) );

	} else {

		$php = array_map( 'jpkcom_postfilter_fragment_url', $cases );

		is_same(
			'both implementations produce identical URLs for ' . count( $cases ) . ' cases',
			$js,
			$php,
			'includes/fragment-response.php and assets/js/post-filter.js have drifted. '
			. 'The PHP side would keep passing its own tests while every AJAX request 404s.'
		);

	}
}

echo "\nRewrite rule ordering\n";

$routing = (string) file_get_contents( dirname( __DIR__ ) . '/includes/url-routing.php' );

$fragment_at = strpos( $routing, "\$endpoint . '/(.+?)/' . \$fragment" );
$page_at     = strpos( $routing, "\$endpoint . '/(.+?)/?\$'" );

check(
	'fragment rules are registered before the page rules',
	$fragment_at !== false && $page_at !== false && $fragment_at < $page_at,
	'add_rewrite_rule( …, \'top\' ) keeps insertion order and WordPress takes the first '
	. 'match. The page rule\'s (.+?) swallows a trailing /jpkpf-fragment/ as part of the '
	. 'filter path, so registering it first makes the fragment rules unreachable — the '
	. 'segment would silently be treated as a term slug and every AJAX request would '
	. 'render a normal, empty-result page.'
);

check(
	'the unfiltered fragment route exists',
	str_contains( $routing, "'^' . \$prefix . \$fragment . '/?\$'" ),
	'Resetting all filters returns to the bare archive URL, which carries no '
	. '/{endpoint}/ segment. Without its own route that request 404s.'
);

check(
	'jpkcom_filter_fragment is a registered query var',
	str_contains( $routing, "\$vars[] = 'jpkcom_filter_fragment';" ),
	'WordPress strips query vars it does not know, so the flag would never arrive.'
);

echo "\nZero-results fallback survives the wp_footer purge\n";

$injection = (string) file_get_contents( dirname( __DIR__ ) . '/includes/filter-injection.php' );
$fragment  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/fragment-response.php' );

check(
	'the fallback is a named function, not a closure',
	str_contains( $injection, 'function jpkcom_postfilter_render_zero_results_fallback(): void' ),
	'A closure on wp_footer can be removed but never re-attached.'
);

check(
	'fragment-response re-attaches it after clearing wp_footer',
	strpos( $fragment, "remove_all_actions( 'wp_footer' )" ) !== false
		&& strpos( $fragment, "add_action( 'wp_footer', 'jpkcom_postfilter_render_zero_results_fallback'" )
			> strpos( $fragment, "remove_all_actions( 'wp_footer' )" ),
	'When auto-injection applies but the loop never runs — a filter combination with '
	. 'zero results — the results zone is emitted from wp_footer and nowhere else. '
	. 'Without it, a zero-result click returns a fragment with no swappable zone and '
	. 'the previous results stay on screen.'
);

check(
	'the fallback emits zone markers',
	str_contains( $injection, 'jpkcom_postfilter_zone_open();' )
		&& substr_count( $injection, 'jpkcom_postfilter_zone_open();' ) >= 2,
	'Both the loop_start path and the zero-results path must mark their zone, '
	. 'otherwise one of the two produces an unusable fragment.'
);

echo "\nRegressions found on a live installation\n";

check(
	'canonical redirects are disabled on fragment requests',
	str_contains( $fragment, "add_filter( 'redirect_canonical', '__return_false'" ),
	"WordPress does not recognise the fragment segment and \"repairs\" a paginated "
	. 'fragment by appending the page it thinks is missing: /page/2/jpkpf-fragment/ '
	. 'was answered with a 301 to /page/2/jpkpf-fragment/page/2/. The script follows '
	. 'it, gets a 404 and falls back to a full reload, so paginating a filtered list '
	. 'silently stopped using AJAX.'
);

$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/post-filter.js' );

check(
	'popstate falls back to location.href when the entry carries no state',
	str_contains( $js, "( e.state && e.state.jpkpf ) ? e.state.url : location.href" ),
	'The history entry created by the initial page load has no state at all. '
	. 'Guarding solely on e.state.jpkpf made going back a no-op: the address bar '
	. 'returned to the unfiltered archive while the results zone kept showing the '
	. 'filtered list and the buttons stayed pressed.'
);

check(
	'popstate re-syncs the filter buttons to the URL',
	str_contains( $js, 'function syncButtonsToUrl(' )
		&& str_contains( $js, 'syncButtonsToUrl( filterBar, baseUrl );' ),
	'Re-fetching the results without resetting the buttons leaves the bar claiming '
	. 'a selection the list no longer shows.'
);

$cache = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cache-manager.php' );

check(
	'APCu availability is probed without emitting a diagnostic',
	! str_contains( $cache, 'apcu_cache_info( true ) !== false' )
		&& str_contains( $cache, 'apcu_enabled()' ),
	'apcu_cache_info() raises "No APC info available" when the extension is loaded '
	. 'but inactive for the running SAPI (apc.enable_cli defaults to 0). With '
	. 'display_errors on, that warning is printed into the response body — inside '
	. 'the swapped markup on a fragment request. apcu_enabled() answers the same '
	. 'question silently.'
);

echo "\nnoindex reaches the page an SEO plugin actually renders\n";

$routing = (string) file_get_contents( dirname( __DIR__ ) . '/includes/url-routing.php' );

check(
	'the rule is hooked into Rank Math as well as wp_robots',
	str_contains( $routing, "add_filter( 'rank_math/frontend/robots'" ),
	'Rank Math calls remove_all_filters( \'wp_robots\' ) before emitting its own tag, so '
	. 'every callback on that hook is discarded. From 1.1.7 until 1.2.0 the noindex on '
	. 'bogus filter URLs therefore never happened on a Rank Math site — and this stack '
	. 'ships jpkcom-rank-math-options, so that is the normal configuration, not an edge case.'
);

check(
	'and into Yoast',
	str_contains( $routing, "add_filter( 'wpseo_robots_array'" ),
	'Yoast likewise emits its own tag rather than going through wp_robots.'
);

check(
	'all three share one condition',
	substr_count( $routing, 'jpkcom_postfilter_should_noindex()' ) >= 3,
	'Three hooks with three copies of the condition drift apart. One helper, three callers.'
);

echo "\nZero-results output lands before the footer\n";

// The hook list, in order. Order is the whole point: the first entry decides
// where the output actually lands.
preg_match(
	'/jpkcom_postfilter_zero_results_hooks\'\s*,\s*\[(.*?)\]/s',
	$injection,
	$hook_block
);

preg_match_all( "/'([a-z_]+)'/", $hook_block[1] ?? '', $hook_names );
$hooks = $hook_names[1] ?? [];

check(
	'a pre-loop theme hook comes first',
	( $hooks[0] ?? '' ) === 'bootscore_before_loop',
	'With posts, the filter bar is injected at loop_start — inside the theme\'s content '
	. 'column. With zero posts the loop never starts and no core hook reaches that '
	. 'position. wp_footer put the message below the footer; get_footer put it above the '
	. 'footer but still at the very end of the content area, nowhere near where the '
	. 'listing sits. Only a hook firing before `if ( have_posts() )` lands in the right '
	. 'place. Got: ' . wp_json_encode_fallback( $hooks )
);

check(
	'both footer hooks remain as fallbacks, in that order',
	array_slice( $hooks, 1 ) === [ 'get_footer', 'wp_footer' ],
	'A theme without the pre-loop hook must still get the filter bar somewhere; block '
	. 'themes may reach only wp_footer. Got: ' . wp_json_encode_fallback( $hooks )
);

check(
	'the list is filterable',
	str_contains( $injection, "apply_filters(\n    'jpkcom_postfilter_zero_results_hooks'" )
		|| str_contains( $injection, "'jpkcom_postfilter_zero_results_hooks'" ),
	'Other themes need to supply their own pre-loop hook without editing the plugin.'
);

check(
	'it cannot render twice',
	str_contains( $injection, '_jpkpf_zero_results_rendered' ),
	'Several of these hooks fire on the same page. Without a guard the filter bar '
	. 'appears more than once.'
);

check(
	'it bails out when the loop will run',
	(bool) preg_match( '/\$wp_query->post_count\s*>\s*0/', $injection ),
	'The first hook fires *before* the loop condition, so on a page that does have '
	. 'posts it would render the filter bar once here and again from loop_start. The '
	. 'post_count check is what makes attaching to a pre-loop hook safe at all.'
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
