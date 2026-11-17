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
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function _n( $single, $plural, $n, $d = '' ) { return (int) $n === 1 ? $single : $plural; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function do_action( ...$args ) {}
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }

$GLOBALS['__groups'] = [
	[ 'taxonomy' => 'category', 'enabled' => true, 'order' => 0 ],
	[ 'taxonomy' => 'post_tag', 'enabled' => true, 'order' => 1 ],
];

function jpkcom_postfilter_get_filter_groups_enabled(): array { return $GLOBALS['__groups']; }

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

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

// Clean up.
foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f );
	}
}
@rmdir( JPKCOM_POSTFILTER_SETTINGS_DIR );
@rmdir( WP_CONTENT_DIR );

exit( summary() );
