<?php
/**
 * Regression tests for the 1.7.2 term state markup (V-145): aria-pressed only
 * on <button> terms, aria-current="true" on an active link, `is-active` as the
 * one state a script or the block preview reads.
 *
 * Renders all eight filter partials (templates and debug-templates) in every
 * link mode and checks the DOM. Run with:
 *
 *     php tests/test-a11y-markup.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.7.2
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-a11y-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );
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
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function _n( $single, $plural, $n, $d = '' ) { return (int) $n === 1 ? $single : $plural; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, $dec, '.', ',' ); }
function wp_reset_postdata() {}
function do_action( ...$args ) {}
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }

$GLOBALS['__groups'] = [
	[ 'taxonomy' => 'category', 'enabled' => true, 'order' => 0 ],
	[ 'taxonomy' => 'post_tag', 'enabled' => true, 'order' => 1 ],
];

function jpkcom_postfilter_get_filter_groups_enabled(): array { return $GLOBALS['__groups']; }

/** Query stand-in: what the status line and the list partials read. */
class WP_Query {
	public int $found_posts = 0;
	public int $post_count  = 0;
	public array $posts     = [];
	public array $args      = [];
	public function get( $k ) { return $this->args[ $k ] ?? ''; }
	public function have_posts() { return false; }
	public function is_post_type_archive() { return false; }
}

// Stand-ins for filter-injection.php (same idea as tests/test-block-injection.php).
function get_queried_object() { return null; }
function jpkcom_postfilter_should_auto_inject( \WP_Query $query ): bool { return true; }
function jpkcom_postfilter_get_injected_post_type( \WP_Query $query ): string { return 'post'; }
function jpkcom_postfilter_get_archive_base_url( string $pt ): string { return 'https://example.test/blog/'; }
function jpkcom_postfilter_get_terms_for_group( array $group, array $active ): array { return []; }
function jpkcom_postfilter_get_template_part( string $slug, string $name = '', array $args = [] ): void {}
function jpkcom_postfilter_zone_open(): void {}
function jpkcom_postfilter_zone_close(): void {}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/filter-injection.php';

foreach ( [ 'url-policy.php', 'url-routing.php', 'canonical-target.php', 'crawl-guard.php', 'blocks.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

function set_general( array $general ): void {
	$GLOBALS['__options']['jpkcom_postfilter_general'] = $general;
	jpkcom_postfilter_settings_memo_reset();
}

$GLOBALS['__options']['page_for_posts']          = 5;
$GLOBALS['__options']['jpkcom_postfilter_cache'] = [ 'settings_cache_enabled' => false ];
$GLOBALS['__taxonomies']                         = [ 'category', 'post_tag' ];
$GLOBALS['__terms']                              = [
	'category' => [ 'sichtschutz', 'tueren-tore', 'zaeune' ],
	'post_tag' => [ 'privat', 'gewerbe' ],
];

function make_term( string $slug, string $name, int $count ): object {
	$t = new stdClass();
	$t->slug  = $slug;
	$t->name  = $name;
	$t->count = $count;
	return $t;
}

/**
 * Render one partial with the variables it reads and return the DOM.
 *
 * @param array<string, string[]> $active
 */
function render_partial( string $dir, string $layout, array $active ): DOMXPath {
	$vars = [
		'filter_groups'  => [
			[
				'slug'     => 'category',
				'label'    => 'Category',
				'taxonomy' => 'category',
				'terms'    => [ make_term( 'sichtschutz', 'Sichtschutz', 4 ), make_term( 'tueren-tore', 'Tueren', 2 ), make_term( 'zaeune', 'Zaeune', 7 ) ],
			],
			[
				'slug'     => 'post_tag',
				'label'    => 'Tag',
				'taxonomy' => 'post_tag',
				'terms'    => [ make_term( 'privat', 'Privat', 3 ), make_term( 'gewerbe', 'Gewerbe', 1 ) ],
			],
		],
		'active_filters' => $active,
		'base_url'       => 'https://example.test/blog/',
		'post_type'      => 'post',
		'show_reset'     => false,
		'reset_mode'     => 'never',
		'extra_class'    => '',
	];
	extract( $vars );
	ob_start();
	include __DIR__ . '/../' . $dir . '/partials/filter/filter-' . $layout . '.php';
	$html = (string) ob_get_clean();

	$doc = new DOMDocument();
	@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
	return new DOMXPath( $doc );
}

function nodes( DOMXPath $x, string $q ): array {
	$r = $x->query( $q );
	return $r === false ? [] : iterator_to_array( $r );
}

function has_class( DOMElement $e, string $c ): bool {
	return in_array( $c, preg_split( '/\s+/', $e->getAttribute( 'class' ) ) ?: [], true );
}

// ---------------------------------------------------------------------------
set_general( [ 'max_filters_per_group' => 3 ] );

$active_one = [ 'category' => [ 'zaeune' ] ];
$active_two = [ 'category' => [ 'zaeune', 'sichtschutz' ], 'post_tag' => [ 'privat' ] ];

foreach ( [ 'single', 'combo', 'none' ] as $mode ) {
	set_general( [ 'max_filters_per_group' => 3, 'filter_link_mode' => $mode ] );

	foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
		foreach ( [ 'bar', 'sidebar', 'dropdown', 'columns' ] as $layout ) {
			$x = render_partial( $dir, $layout, $active_one );
			$label = "{$dir}/{$layout} [{$mode}]";
			section( $label );

			chk( 'no aria-pressed on links', count( nodes( $x, '//a[@aria-pressed]' ) ) === 0 );

			$bad = [];
			$buttons = nodes( $x, '//button[@data-filter-term]' );
			foreach ( $buttons as $b ) {
				$v = $b->getAttribute( 'aria-pressed' );
				if ( ! in_array( $v, [ 'true', 'false' ], true ) || ( $v === 'true' ) !== has_class( $b, 'is-active' ) ) {
					$bad[] = $b->getAttribute( 'data-filter-term' ) . '=' . $v;
				}
			}
			chk( 'buttons carry aria-pressed', $bad === [], implode( ', ', $bad ) );

			$bad = [];
			foreach ( nodes( $x, '//a[@data-filter-term]' ) as $a ) {
				$active = has_class( $a, 'is-active' );
				if ( $active && $a->getAttribute( 'aria-current' ) !== 'true' ) {
					$bad[] = 'active ' . $a->getAttribute( 'data-filter-term' ) . ' lacks aria-current';
				}
				if ( ! $active && $a->hasAttribute( 'aria-current' ) ) {
					$bad[] = 'inactive ' . $a->getAttribute( 'data-filter-term' ) . ' has aria-current';
				}
			}
			chk( 'active link has aria-current, inactive none', $bad === [], implode( ', ', $bad ) );

			if ( $mode === 'none' ) {
				chk( 'mode none: no links', count( nodes( $x, '//a[@data-filter-term]' ) ) === 0 );
			}

			if ( $mode === 'single' ) {
				$x2  = render_partial( $dir, $layout, $active_two );
				$act = nodes( $x2, '//*[@data-filter-term][contains(concat(" ", normalize-space(@class), " "), " is-active ")]' );
				$ok  = count( $act ) === 3;
				foreach ( $act as $e ) {
					$ok = $ok && $e->nodeName === 'button' && $e->getAttribute( 'aria-pressed' ) === 'true' && ! $e->hasAttribute( 'aria-current' );
				}
				chk( 'single, several active: active terms are buttons with aria-pressed="true"', $ok, (string) count( $act ) );
			}
		}
	}
}

// --- helper unit ------------------------------------------------------------
section( 'term_link_attributes: aria key' );
$base = 'https://example.test/blog/';

set_general( [ 'filter_link_mode' => 'none' ] );
$a = jpkcom_postfilter_term_link_attributes( $base, [ 'category' => [ 'zaeune' ] ], 'category', 'zaeune', true );
chk( 'none, active: aria-pressed="true"', ( $a['aria'] ?? null ) === 'aria-pressed="true"', json_encode( $a ) );
$a = jpkcom_postfilter_term_link_attributes( $base, [], 'category', 'zaeune', false );
chk( 'none, inactive: aria-pressed="false"', ( $a['aria'] ?? null ) === 'aria-pressed="false"', json_encode( $a ) );

set_general( [ 'filter_link_mode' => 'combo' ] );
$a = jpkcom_postfilter_term_link_attributes( $base, [ 'category' => [ 'zaeune' ] ], 'category', 'zaeune', true );
chk( 'combo, active: a with aria-current="true"', $a['tag'] === 'a' && ( $a['aria'] ?? null ) === 'aria-current="true"', json_encode( $a ) );
$a = jpkcom_postfilter_term_link_attributes( $base, [], 'category', 'zaeune', false );
chk( 'combo, inactive: a with empty aria', $a['tag'] === 'a' && ( $a['aria'] ?? null ) === '', json_encode( $a ) );

// --- block preview ----------------------------------------------------------
section( 'plus/minus reads is-active' );
$GLOBALS['__options']['jpkcom_postfilter_layout'] = [ 'plus_minus_mode' => true ];
jpkcom_postfilter_settings_memo_reset();

$legacy = jpkcom_postfilter_block_apply_plus_minus( '<nav class="jpkpf-filter-bar"><a class="jpkpf-filter-btn is-active" data-filter-term="x">X</a></nav>' );
$old    = jpkcom_postfilter_block_apply_plus_minus( '<nav class="jpkpf-filter-bar"><a class="jpkpf-filter-btn is-active" aria-pressed="true" data-filter-term="x">X</a></nav>' );
chk( 'is-active link without aria-pressed gets the minus icon', str_contains( $legacy, "\u{2212}" ), $legacy );
chk( 'same markup as with aria-pressed="true" (apart from that attribute)', str_replace( ' aria-pressed="true"', '', $old ) === $legacy, $legacy . ' | ' . $old );
$inactive = jpkcom_postfilter_block_apply_plus_minus( '<nav class="jpkpf-filter-bar"><a class="jpkpf-filter-btn" data-filter-term="x">X</a></nav>' );
chk( 'inactive link gets the plus icon', str_contains( $inactive, '>+<' ), $inactive );

// --- source scan --------------------------------------------------------------
section( 'partials: no literal aria-pressed, all echo the helper key' );
$n = 0;
$literal = [];
$missing = [];
foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
	foreach ( glob( __DIR__ . '/../' . $dir . '/partials/filter/*.php' ) ?: [] as $f ) {
		$n++;
		$src = (string) file_get_contents( $f );
		if ( str_contains( $src, 'aria-pressed=' ) ) {
			$literal[] = $dir . '/' . basename( $f );
		}
		if ( ! str_contains( $src, "echo \$term_link['aria']" ) ) {
			$missing[] = $dir . '/' . basename( $f );
		}
	}
}
chk( 'eight partials scanned', $n === 8, (string) $n );
chk( 'none contains aria-pressed=', $literal === [], implode( ', ', $literal ) );
chk( 'all echo $term_link[\'aria\']', $missing === [], implode( ', ', $missing ) );

// --- JS term state ------------------------------------------------------------
section( 'JS term state' );
$js_src = (string) file_get_contents( __DIR__ . '/../assets/js/post-filter.js' );
chk( 'aria-pressed is written only inside setTermActive()', substr_count( $js_src, "setAttribute( 'aria-pressed'" ) === 1, (string) substr_count( $js_src, "setAttribute( 'aria-pressed'" ) );
chk( 'no getAttribute( \'aria-pressed\' ) read', substr_count( $js_src, "getAttribute( 'aria-pressed' )" ) === 0, (string) substr_count( $js_src, "getAttribute( 'aria-pressed' )" ) );
chk( 'no [aria-pressed selector', substr_count( $js_src, '[aria-pressed' ) === 0, (string) substr_count( $js_src, '[aria-pressed' ) );
$ts_start = strpos( $js_src, 'function setTermActive(' );
$ts_end   = $ts_start === false ? false : strpos( $js_src, "\n\t}\n", $ts_start );
$ts_pos   = strpos( $js_src, "setAttribute( 'aria-pressed'" );
chk( 'that single write sits inside setTermActive()', $ts_start !== false && $ts_end !== false && $ts_pos > $ts_start && $ts_pos < $ts_end );

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
	echo "  SKIP  node not available - the JS term state was NOT verified\n";
} else {
	$js_raw = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/term-state.mjs' ) . ' 2>&1' );
	$js     = json_decode( $js_raw, true );
	if ( ! is_array( $js ) ) {
		chk( 'term-state.mjs ran', false, trim( $js_raw ) );
	} else {
		$want = [
			'button-on'       => [ 'active' => true,  'pressed' => 'true', 'current' => null ],
			'button-off'      => [ 'active' => false, 'pressed' => 'false', 'current' => null ],
			'link-on'         => [ 'active' => true,  'pressed' => null, 'current' => 'true' ],
			'link-off'        => [ 'active' => false, 'pressed' => null, 'current' => null ],
			'legacy-link'     => [ 'active' => true,  'pressed' => null, 'current' => 'true' ],
			'legacy-link-off' => [ 'active' => false, 'pressed' => null, 'current' => null ],
		];
		foreach ( $want as $case => $expected ) {
			chk( 'JS ' . $case, ( $js[ $case ] ?? null ) === $expected, json_encode( $js[ $case ] ?? null ) );
		}
	}
}

// --- result zone --------------------------------------------------------------
section( 'Result zone: status line instead of a live region' );

function status_query( int $found, int $count, bool $no_found_rows ): WP_Query {
	$q              = new WP_Query();
	$q->found_posts = $found;
	$q->post_count  = $count;
	$q->args        = $no_found_rows ? [ 'no_found_rows' => true ] : [];
	return $q;
}

chk( 'status: 12 posts', jpkcom_postfilter_results_status( status_query( 12, 10, false ) ) === '12 posts found' );
chk( 'status: 1 post', jpkcom_postfilter_results_status( status_query( 1, 1, false ) ) === '1 post found' );
chk( 'status: 0 posts', jpkcom_postfilter_results_status( status_query( 0, 0, false ) ) === 'No posts found.' );
chk( 'status uses post_count when no_found_rows', jpkcom_postfilter_results_status( status_query( 0, 7, true ) ) === '7 posts found', jpkcom_postfilter_results_status( status_query( 0, 7, true ) ) );

/** Zones of a markup string: the DOM check shared by all result zones. */
function check_zones( string $label, string $html, int $expected_zones, ?string $status = null ): void {
	$doc = new DOMDocument();
	@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
	$x     = new DOMXPath( $doc );
	$zones = nodes( $x, '//*[@data-jpkpf-results]' );
	chk( "{$label}: {$expected_zones} result zone", count( $zones ) === $expected_zones, (string) count( $zones ) );
	chk( "{$label}: no aria-live/aria-atomic on the zone", count( nodes( $x, '//*[@data-jpkpf-results][@aria-live or @aria-atomic]' ) ) === 0 );
	$bad = [];
	foreach ( $zones as $z ) {
		$v = $z->getAttribute( 'data-jpkpf-status' );
		if ( $v === '' || ( $status !== null && $v !== $status ) ) {
			$bad[] = '"' . $v . '"';
		}
	}
	chk( "{$label}: data-jpkpf-status" . ( $status !== null ? " = {$status}" : ' non-empty' ), $bad === [], implode( ', ', $bad ) );
}

foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
	foreach ( [ 'cards', 'rows', 'minimal', 'theme' ] as $layout ) {
		$query       = status_query( 12, 0, false );
		$post_type   = 'post';
		$extra_class = '';
		ob_start();
		include __DIR__ . '/../' . $dir . '/partials/list/list-' . $layout . '.php';
		check_zones( "{$dir}/list-{$layout}", (string) ob_get_clean(), 1, '12 posts found' );
	}
}

$GLOBALS['wp_query'] = status_query( 12, 2, false );
$GLOBALS['wp_query']->posts = [ 1, 2 ];
check_zones( 'auto-inject zone', jpkcom_postfilter_auto_inject_open_html( $GLOBALS['wp_query'] ), 1, '12 posts found' );

$GLOBALS['wp_query'] = status_query( 0, 0, false );
check_zones( 'zero-results zone', jpkcom_postfilter_zero_results_html(), 1, 'No posts found.' );

$fi_src = (string) file_get_contents( __DIR__ . '/../includes/filter-injection.php' );
chk( 'filter-injection.php prints no aria-live', ! str_contains( $fi_src, 'aria-live' ) );
foreach ( [ 'templates', 'debug-templates' ] as $dir ) {
	foreach ( [ 'cards', 'rows', 'minimal', 'theme' ] as $layout ) {
		$f = $dir . '/partials/list/list-' . $layout . '.php';
		chk( "{$f}: no aria-live", ! str_contains( (string) file_get_contents( __DIR__ . '/../' . $f ), 'aria-live' ) );
		chk( "{$f}: identical to its pair", $dir !== 'debug-templates' || file_get_contents( __DIR__ . '/../' . $f ) === file_get_contents( __DIR__ . '/../templates/partials/list/list-' . $layout . '.php' ) );
	}
}

$js_src2 = (string) file_get_contents( __DIR__ . '/../assets/js/post-filter.js' );
chk( 'JS: no count + word announcement left', ! str_contains( $js_src2, "count + ' '" ) );
chk( 'JS: announcement goes through resultsAnnouncement()', str_contains( $js_src2, 'resultsAnnouncement( newZone, resultZone )' ) );

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
	echo "  SKIP  node not available - the announcement was NOT verified\n";
} else {
	$raw = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/announce.mjs' ) . ' 2>&1' );
	$ann = json_decode( $raw, true );
	if ( ! is_array( $ann ) ) {
		chk( 'announce.mjs ran', false, trim( $raw ) );
	} else {
		$want = [
			'with-status'       => '12 posts found',
			'empty-status-zero' => 'No posts found.',
			'no-status-empty'   => 'No posts found.',
			'no-status-posts'   => 'Filter active',
			'i18n-no-results'   => 'Keine Treffer',
			'i18n-filter'       => 'Filter aktiv',
		];
		foreach ( $want as $case => $expected ) {
			chk( 'JS announcement ' . $case, ( $ann[ $case ] ?? null ) === $expected, json_encode( $ann[ $case ] ?? null ) );
		}
	}
}

// Clean up.
foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f );
	}
}
@rmdir( JPKCOM_POSTFILTER_SETTINGS_DIR );
@rmdir( WP_CONTENT_DIR );

exit( summary() );
