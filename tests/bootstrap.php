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
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $t ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['__taxonomies'], true ); }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function get_query_var( $k, $d = '' ) { return $GLOBALS['__query_vars'][ $k ] ?? $d; }
function apply_filters( $tag, $val ) { return $val; }
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; }
function add_action( $tag, $cb, $p = 10, $a = 1 ) {}
function remove_action( $tag, $cb, $p = 10 ) {}
function is_wp_error( $t ) { return false; }
function jpkcom_postfilter_debug_log( $msg, $ctx = [] ) {}

/**
 * Stand-in for the transient-cached term list.
 */
function jpkcom_postfilter_get_terms_for_taxonomy( string $taxonomy, bool $hide_empty = true ): array {
	$out = [];
	foreach ( $GLOBALS['__terms'][ $taxonomy ] ?? [] as $slug ) {
		$term       = new WP_Term();
		$term->slug = $slug;
		$out[]      = $term;
	}
	return $out;
}

class WP_Term {
	public string $slug = '';
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
