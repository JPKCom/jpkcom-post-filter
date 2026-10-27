<?php
/**
 * Regression tests for auto-injection in block themes (1.7.1).
 *
 * A block theme renders the main loop inside get_the_block_template_html(),
 * before template-canvas.php prints the doctype. Up to 1.7.0 the loop_start
 * callback echoed the filter bar there - it landed before <!DOCTYPE html>.
 * In a block theme the markup now goes around the inherited core/post-template
 * block (render_block), and classic themes stay byte-identical to 1.7.0
 * (tests/fixtures/classic-inject-1.7.0.txt).
 *
 * Run with:
 *
 *     php tests/test-block-injection.php
 *
 * Regenerate the classic fixture from another version of the file with:
 *
 *     php tests/test-block-injection.php --dump=/path/to/filter-injection.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.7.1
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// --- stand-ins ------------------------------------------------------------------

function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function wp_is_block_theme() { return (bool) ( $GLOBALS['__block_theme'] ?? false ); }
// Classic themes run the loop after wp_head; block templates render before it.
function did_action( $hook ) { return (int) ( $GLOBALS['__did'][ $hook ] ?? 0 ); }
function get_queried_object() { return null; }

function jpkcom_postfilter_should_auto_inject( \WP_Query $query ): bool { return (bool) ( $GLOBALS['__inject'] ?? true ); }
function jpkcom_postfilter_get_injected_post_type( \WP_Query $query ): string { return 'post'; }
function jpkcom_postfilter_get_archive_base_url( string $pt ): string { return 'https://example.test/blog/'; }
function jpkcom_postfilter_get_active_filters(): array { return [ 'category' => [ 'zaeune' ] ]; }
function jpkcom_postfilter_get_filter_groups_enabled(): array { return [ [ 'taxonomy' => 'category', 'enabled' => true ] ]; }
function jpkcom_postfilter_get_terms_for_group( array $group, array $active ): array {
	$t       = new WP_Term();
	$t->slug = 'zaeune';
	return [ [ 'term' => $t ] ];
}
function jpkcom_postfilter_settings_get( string $group, string $key, mixed $default = null ): mixed {
	return $GLOBALS['__settings'][ $group ][ $key ] ?? $default;
}
function jpkcom_postfilter_settings_get_group( string $group ): array { return $GLOBALS['__settings'][ $group ] ?? []; }
function jpkcom_postfilter_get_template_part( string $slug, string $name = '', array $args = [] ): void {
	echo '[tpl ' . $slug . ( isset( $args['extra_class'] ) ? ' class=' . $args['extra_class'] : '' ) . ']';
}
function jpkcom_postfilter_zone_open(): void { echo '[zone>'; }
function jpkcom_postfilter_zone_close(): void { echo '<zone]'; }

class WP_Query {
	public array $posts        = [ 1, 2 ];
	public int $post_count     = 2;
	public int $max_num_pages  = 3;
	public function get( $k ) { return ''; }
	public function is_post_type_archive() { return false; }
}

// A block instance as render_block passes it: only its context matters here.
class WP_Block {
	public array $context;
	public function __construct( array $context ) { $this->context = $context; }
}

$file = __DIR__ . '/../includes/filter-injection.php';
foreach ( $argv as $arg ) {
	if ( str_starts_with( $arg, '--dump=' ) ) {
		$file = substr( $arg, 7 );
	}
}
require_once $file;

/** Run the callbacks of an action and return what they print. */
function run_action( string $tag, mixed ...$args ): string {
	ob_start();
	foreach ( $GLOBALS['__actions'][ $tag ] ?? [] as $cb ) {
		$cb( ...$args );
	}
	return (string) ob_get_clean();
}

/** Run the callbacks of a filter. */
function run_filter( string $tag, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['__filters'][ $tag ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

/** Reset the per-request globals of the injection. */
function fresh(): void {
	foreach ( [ '_jpkpf_auto_injected', '_jpkpf_zero_results_rendered', '_jpkpf_suppress_pagination', '_jpkpf_block_open', '_jpkpf_block_close', '_jpkpf_block_injected' ] as $g ) {
		unset( $GLOBALS[ $g ] );
	}
	$GLOBALS['wp_query'] = new WP_Query();
}

/** The classic output for the fixture: posts (two pagination positions) and zero results. */
function classic_dump(): string {
	$out = '';
	foreach ( [ 'below', 'both' ] as $pos ) {
		fresh();
		$GLOBALS['__settings'] = [ 'layout' => [ 'pagination_position' => $pos ] ];
		$q    = $GLOBALS['wp_query'];
		$out .= "== posts, pagination {$pos}\n" . run_action( 'loop_start', $q ) . '<POSTS>' . run_action( 'loop_end', $q ) . "\n";
		$out .= 'max_num_pages ' . $q->max_num_pages . "\n";
	}
	fresh();
	$GLOBALS['__settings']         = [];
	$GLOBALS['wp_query']->posts      = [];
	$GLOBALS['wp_query']->post_count = 0;
	ob_start();
	jpkcom_postfilter_render_zero_results_fallback();
	$out .= "== zero results\n" . (string) ob_get_clean() . "\n";
	return $out;
}

if ( $file !== __DIR__ . '/../includes/filter-injection.php' ) {
	echo classic_dump();
	exit( 0 );
}

$fixture = __DIR__ . '/fixtures/classic-inject-1.7.0.txt';

// ---------------------------------------------------------------------------
section( 'Classic themes: byte-identical to 1.7.0' );

$GLOBALS['__block_theme'] = false;
$GLOBALS['__did']         = [ 'wp_head' => 1 ];
$now = classic_dump();
chk( 'loop_start/loop_end and the zero-results fallback print exactly what 1.7.0 printed', is_file( $fixture ) && $now === file_get_contents( $fixture ), is_file( $fixture ) ? 'diff against ' . $fixture : 'fixture missing' );

// ---------------------------------------------------------------------------
section( 'Block themes' );

$GLOBALS['__block_theme'] = true;
$GLOBALS['__did']         = [];
$GLOBALS['__settings']    = [];
$inherit                  = new WP_Block( [ 'query' => [ 'inherit' => true ] ] );
$own                      = new WP_Block( [ 'query' => [ 'inherit' => false ] ] );
$pt_block                 = [ 'blockName' => 'core/post-template', 'attrs' => [] ];

fresh();
$q      = $GLOBALS['wp_query'];
$before = run_action( 'loop_start', $q ) . run_action( 'loop_end', $q );
chk( 'nothing is printed from the loop hooks (it would land before the doctype)', $before === '', $before );

$html = (string) run_filter( 'render_block_core/post-template', '<ul class="wp-block-post-template">POSTS</ul>', $pt_block, $inherit );
chk(
	'the inherited post template is wrapped: wrapper, bar, zone, results, posts, pagination, close',
	str_starts_with( $html, '<div data-jpkpf-wrapper' )
		&& (bool) preg_match( '#\[tpl partials/filter/filter-bar\]\[zone><div data-jpkpf-results[^>]*><ul class="wp-block-post-template">POSTS</ul>\[tpl partials/pagination/pagination class=\]</div><zone\]</div>$#', $html ),
	$html
);
// 1.7.1 review: max_num_pages stays - in a block theme it is read by Rank Math and
// Yoast in wp_head ("Page N of M", rel=next) after the template rendered.
chk( '... the plugin pagination flag is set after the wrap, max_num_pages untouched', ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) === true && $q->max_num_pages === 3, 'max_num_pages ' . $q->max_num_pages );
chk(
	'the inherited query-pagination block renders nothing once the plugin paginates',
	run_filter( 'render_block_core/query-pagination', '<nav>theme</nav>', [ 'blockName' => 'core/query-pagination' ], $inherit ) === ''
);

$second = (string) run_filter( 'render_block_core/post-template', '<ul>SECOND</ul>', $pt_block, $inherit );
run_action( 'loop_start', $q );
run_action( 'loop_end', $q );
$second .= (string) run_filter( 'render_block_core/post-template', '<ul>THIRD</ul>', $pt_block, $inherit );
chk( 'a second inherited post template gets no second bar', $second === '<ul>SECOND</ul><ul>THIRD</ul>', $second );

fresh();
$q = $GLOBALS['wp_query'];
run_action( 'loop_start', $q );
run_action( 'loop_end', $q );
$html = (string) run_filter( 'render_block_core/post-template', '<ul>OWN</ul>', $pt_block, $own );
chk( 'a post template with its own query is left alone', $html === '<ul>OWN</ul>', $html );

// Zero results: the loop never starts; core/query-no-results of the inherited query renders.
fresh();
$GLOBALS['wp_query']->posts      = [];
$GLOBALS['wp_query']->post_count = 0;
$html = (string) run_filter( 'render_block_core/query-no-results', '<p>Theme: nothing found</p>', [ 'blockName' => 'core/query-no-results' ], $inherit );
chk(
	'zero results: the no-results block becomes bar + results zone with the no-results message',
	str_contains( $html, 'data-jpkpf-wrapper' ) && str_contains( $html, '[tpl partials/filter/filter-bar]' ) && str_contains( $html, 'jpkpf-no-results' ) && str_contains( $html, '[zone>' ),
	$html
);
ob_start();
jpkcom_postfilter_render_zero_results_fallback();
$footer = (string) ob_get_clean();
chk( '... and the footer fallback prints nothing more', $footer === '', $footer );

fresh();
$GLOBALS['wp_query']->posts      = [];
$GLOBALS['wp_query']->post_count = 0;
$html = (string) run_filter( 'render_block_core/query-no-results', '<p>Own</p>', [ 'blockName' => 'core/query-no-results' ], $own );
chk( 'a no-results block of another query is left alone', $html === '<p>Own</p>', $html );

// 1.7.1 review: loop hooks without any post-template render (a PHP archive
// template in a block theme runs after wp_head; a template without a query block).
fresh();
$q = $GLOBALS['wp_query'];
run_action( 'loop_start', $q );
run_action( 'loop_end', $q );
chk( 'block mode without a wrap: no pagination suppression, max_num_pages untouched', ! ( $GLOBALS['_jpkpf_suppress_pagination'] ?? false ) && $q->max_num_pages === 3 );

fresh();
$GLOBALS['__did'] = [ 'wp_head' => 1 ];
$q                = $GLOBALS['wp_query'];
$late             = run_action( 'loop_start', $q ) . 'POSTS' . run_action( 'loop_end', $q );
chk( 'a block theme loop after wp_head (PHP template) prints like a classic theme', str_starts_with( $late, '<div data-jpkpf-wrapper' ) && str_contains( $late, 'POSTS[tpl partials/pagination/pagination class=]</div><zone]</div>' ), $late );
$GLOBALS['__did'] = [];

// An inherited query rendered inside the main loop (in a post) is filtered before
// the main loop ends: it must not take the opening half.
fresh();
$q = $GLOBALS['wp_query'];
run_action( 'loop_start', $q );
$inner = (string) run_filter( 'render_block_core/post-template', '<ul>INNER</ul>', $pt_block, $inherit );
run_action( 'loop_end', $q );
$outer = (string) run_filter( 'render_block_core/post-template', '<ul>MAIN</ul>', $pt_block, $inherit );
chk( 'a nested inherited post template rendered before loop_end is left alone', $inner === '<ul>INNER</ul>', $inner );
chk( '... and the main post template still gets the whole wrap', str_starts_with( $outer, '<div data-jpkpf-wrapper' ) && str_ends_with( $outer, '<zone]</div>' ), $outer );

// Theme pagination placed before the post template inside the inherited query.
fresh();
$early = run_filter( 'render_block_core/query-pagination', '<nav>theme</nav>', [ 'blockName' => 'core/query-pagination' ], $inherit );
chk( 'theme pagination before the post template is suppressed as well', $early === '', (string) $early );

$GLOBALS['__inject'] = false;
fresh();
$q = $GLOBALS['wp_query'];
run_action( 'loop_start', $q );
run_action( 'loop_end', $q );
$html = (string) run_filter( 'render_block_core/post-template', '<ul>P</ul>', $pt_block, $inherit );
chk( 'no injection configured: nothing changes', $html === '<ul>P</ul>', $html );
$GLOBALS['__inject'] = true;

exit( summary() );
