<?php
/**
 * Minimal WordPress stubs for the post-filter regression tests.
 *
 * Dependency-free on purpose so the checks also run in CI on every pull
 * request without a WordPress install.
 *
 * @package JPKCom_Post_Filter
 * @since 1.1.7
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/jpkcom-post-filter-tests/' );

$GLOBALS['__options']    = [];
$GLOBALS['__transients'] = [];
$GLOBALS['__terms']      = [];   // taxonomy => [slug, slug, ...]
$GLOBALS['__taxonomies'] = [];
$GLOBALS['__filters']    = [];
$GLOBALS['__query_vars'] = [];

function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
// Keeps percent escapes like the real sanitize_title_with_dashes() does.
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['__taxonomies'], true ); }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function get_query_var( $k, $d = '' ) { return $GLOBALS['__query_vars'][ $k ] ?? $d; }
// A suite that needs a filter to run registers it in $GLOBALS['__run_filters'][ $tag ].
function apply_filters( $tag, $val, ...$args ) {
	$cb = $GLOBALS['__run_filters'][ $tag ] ?? null;
	return $cb === null ? $val : $cb( $val, ...$args );
}
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; }
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; }
function remove_action( $tag, $cb, $p = 10 ) {}
function is_wp_error( $t ) { return false; }
function jpkcom_postfilter_debug_log( $msg, $ctx = [] ) {}

/**
 * Stand-in for the transient-cached term list.
 */
function jpkcom_postfilter_get_terms_for_taxonomy( string $taxonomy, bool $hide_empty = true ): array {
	$out = [];
	// $GLOBALS['__terms_lang_filter'][ $taxonomy ] narrows the list the way WPML
	// narrows get_terms() to the current language.
	foreach ( $GLOBALS['__terms_lang_filter'][ $taxonomy ] ?? $GLOBALS['__terms'][ $taxonomy ] ?? [] as $slug ) {
		$term       = new WP_Term();
		$term->slug = $slug;
		$out[]      = $term;
	}
	return $out;
}

/**
 * Stand-ins for the language-independent slug lookup (1.5.0): the term table
 * is $GLOBALS['__terms'], the transient layer always misses.
 */
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() { return 1; }
}
function jpkcom_postfilter_query_term_slugs( string $taxonomy ): array {
	return array_values( $GLOBALS['__terms'][ $taxonomy ] ?? [] );
}
function jpkcom_postfilter_transient_get( string $key ): mixed { return false; }
function jpkcom_postfilter_transient_set( string $key, mixed $value, int $ttl = 0 ): bool { return true; }

class WP_Term {
	public string $slug     = '';
	public int    $term_id  = 0;
	public string $taxonomy = '';
	public int    $count    = 0;
}

// --- assertion harness ------------------------------------------------------

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
