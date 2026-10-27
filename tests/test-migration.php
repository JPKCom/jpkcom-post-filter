<?php
/**
 * Tests for the 1.6.0 default change (2 terms per group) and the migration
 * that keeps existing installations at 3.
 *
 * The effective value of an unset key comes from the defaults, so changing a
 * default changes every install that never saved the key - including those
 * whose General page was never saved at all. The migration pins 3 for exactly
 * those installs, and must do so without disturbing any other setting:
 * update_option() runs the registered sanitizer, which reads a missing
 * checkbox key as "none selected" (1.5.0 emptied auto_inject that way).
 *
 * Run with:
 *
 *     php tests/test-migration.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.6.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_CACHE_TTL', 300 );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/jpkpf-migration-' . getmypid() );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

define( 'HOUR_IN_SECONDS', 3600 );

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

// --- WordPress stand-ins beyond bootstrap.php ----------------------------------

function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function esc_url_raw( $u ) { return (string) $u; }
// 'legacy_cpt' is deliberately absent: a stored post type whose plugin is inactive
// (or registers late) is not public at the moment the migration runs.
function get_post_types( $args = [] ) { return [ 'post' => 'post', 'page' => 'page', 'jpk_job' => 'jpk_job' ]; }
function do_action( ...$args ) {}
function jpkcom_postfilter_cache_flush_group(): void {}

/**
 * Filter-registry stand-ins over bootstrap.php's $GLOBALS['__filters'].
 */
function has_filter( $tag, $cb = false ) { return in_array( $cb, $GLOBALS['__filters'][ $tag ] ?? [], true ) ? 10 : false; }
function remove_filter( $tag, $cb, $p = 10 ) {
	$GLOBALS['__filters'][ $tag ] = array_values( array_diff( $GLOBALS['__filters'][ $tag ] ?? [], [ $cb ] ) );
	return true;
}

/**
 * Behaves like WordPress for the General group: runs the sanitize_option_*
 * filters registered for the option (register_setting does that on admin_init),
 * then fires what the plugin hooks on - update_option_* when the row exists
 * (memo reset + cache file rewrite), add_option_* when it does not (memo reset
 * only), as settings.php wires them.
 */
$GLOBALS['__writes'] = [];
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__writes'][] = $k;
	foreach ( $GLOBALS['__filters'][ 'sanitize_option_' . $k ] ?? [] as $cb ) {
		$v = $cb( $v );
	}
	$existed                    = array_key_exists( $k, $GLOBALS['__options'] );
	$GLOBALS['__options'][ $k ] = $v;
	if ( $k === 'jpkcom_postfilter_general' ) {
		$GLOBALS['__hook_fired'] = $existed ? 'update' : 'add';
		jpkcom_postfilter_settings_memo_reset();
		if ( $existed && is_array( $v ) ) {
			jpkcom_postfilter_settings_write_file( 'general', $v );
		}
	}
	return true;
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/url-policy.php';

function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

/** Reset to a blank site; the settings file cache is off, options are the truth. */
function fresh_site(): void {
	foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f );
		}
	}
	$GLOBALS['__options']  = [ 'jpkcom_postfilter_cache' => [ 'settings_cache_enabled' => false ] ];
	$GLOBALS['__writes']   = [];
	if ( function_exists( 'jpkcom_postfilter_settings_memo_reset' ) ) {
		jpkcom_postfilter_settings_memo_reset();
	}
}

/** Every effective value of the General group, as the plugin resolves it. */
function effective_general(): array {
	jpkcom_postfilter_settings_memo_reset();
	$out = [];
	foreach ( jpkcom_postfilter_default_settings()['general'] as $key => $default ) {
		$out[ $key ] = jpkcom_postfilter_settings_get( 'general', $key, $default );
	}
	return $out;
}

// ---------------------------------------------------------------------------
section( 'Fresh install' );

fresh_site();
chk( 'fresh install: nothing written', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false && ! isset( $GLOBALS['__options']['jpkcom_postfilter_general'] ) && $GLOBALS['__writes'] === [] );
chk( 'fresh install: effective default is 2', (int) jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2, (string) jpkcom_postfilter_policy( 'max_filters_per_group' ) );
chk( 'the settings default says 2 as well', ( jpkcom_postfilter_default_settings()['general']['max_filters_per_group'] ?? null ) === 2 );

// ---------------------------------------------------------------------------
section( 'Existing install, General page never saved' );

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => [ [ 'taxonomy' => 'category', 'enabled' => true, 'order' => 0 ] ] ];
$before = effective_general();
$wrote  = call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
$saved  = $GLOBALS['__options']['jpkcom_postfilter_general'] ?? [];
chk( 'never saved general: reports a write', $wrote === true );
jpkcom_postfilter_settings_memo_reset();
chk( 'never saved general: pins 3', jpkcom_postfilter_policy( 'max_filters_per_group' ) === 3, (string) jpkcom_postfilter_policy( 'max_filters_per_group' ) );
$after = effective_general();
$after_pin = $after;
unset( $before['max_filters_per_group'], $after['max_filters_per_group'] );
chk( 'pin keeps every other effective value', $before === $after, json_encode( [ $before, $after ] ) );
chk( 'auto_inject still reads its default after the pin', $after_pin['auto_inject'] === [ 'post' ] && ! array_key_exists( 'auto_inject', $saved ), json_encode( $saved ) );
chk( 'a second run writes nothing', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false );

// ---------------------------------------------------------------------------
section( 'Existing install, General saved without the key' );

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
$GLOBALS['__options']['jpkcom_postfilter_general'] = [
	'enabled_post_types' => [ 'post', 'jpk_job' ],
	'auto_inject'        => [ 'jpk_job' ],
	'max_filter_combos'  => 5,
	'url_endpoint'       => 'jobs',
	'strict_urls'        => false,
	'reject_status'      => 404,
];
$before = effective_general();
$wrote  = call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
$saved  = $GLOBALS['__options']['jpkcom_postfilter_general'];
chk( 'saved general without key: reports a write', $wrote === true );
chk( 'saved general without key: pins 3', ( $saved['max_filters_per_group'] ?? null ) === 3, json_encode( $saved ) );
chk( 'stored values win over defaults', $saved['auto_inject'] === [ 'jpk_job' ] && $saved['url_endpoint'] === 'jobs' && $saved['strict_urls'] === false && $saved['reject_status'] === 404 );
$after = effective_general();
unset( $before['max_filters_per_group'], $after['max_filters_per_group'] );
chk( 'pin keeps every other effective value', $before === $after, json_encode( [ $before, $after ] ) );

// ---------------------------------------------------------------------------
section( 'Only on the transition from before 1.6.0 (fix wave item 3)' );

// A fresh 1.6.0 install: groups saved, General never saved, the first init set
// the marker to 1.6.0. The next release (1.6.1) must keep its default 2.
foreach ( [ '1.6.0', '1.6.1', '1.7.0', '2.0.0' ] as $marker ) {
	fresh_site();
	$GLOBALS['__options']['jpkcom_postfilter_filter_groups']   = [ 'groups' => [ [ 'taxonomy' => 'category', 'enabled' => true, 'order' => 0 ] ] ];
	$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = $marker;
	chk( "marker {$marker} (installed with 1.6.0 or later), groups saved: nothing written, N stays 2",
		call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false && $GLOBALS['__writes'] === []
		&& (int) jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2 );
}

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.6.0';
chk( 'marker 1.6.0, nothing configured: nothing written', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false && $GLOBALS['__writes'] === [] );

foreach ( [ '1.2.0', '1.5.0', '1.5.9' ] as $marker ) {
	fresh_site();
	$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = $marker;
	chk( "marker {$marker} -> 1.6.0: pins 3", call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === true
		&& ( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 3 );
}

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => [] ];
chk( 'no marker (before 1.2.0), groups option exists: pins 3', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === true );

fresh_site();
chk( 'no marker, no groups option (fresh): nothing written', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false && $GLOBALS['__writes'] === [] );

// ---------------------------------------------------------------------------
section( 'Existing install, key already stored' );

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
$GLOBALS['__options']['jpkcom_postfilter_general']         = [ 'max_filters_per_group' => 2, 'auto_inject' => [ 'post' ] ];
chk( 'saved 2: unchanged', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false );
chk( 'saved 2: still 2 and nothing written', $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] === 2 && $GLOBALS['__writes'] === [] );

fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
$GLOBALS['__options']['jpkcom_postfilter_general']         = [ 'max_filters_per_group' => 0 ];
chk( 'saved 0 (unlimited): unchanged', call( 'jpkcom_postfilter_migrate_pin_per_group_cap' ) === false );

// ---------------------------------------------------------------------------
section( 'The General sanitizer is bypassed for this write, and put back' );

add_filter( 'sanitize_option_jpkcom_postfilter_general', 'jpkcom_postfilter_sanitize_general_settings' );

// A post type that is not public at this moment must survive.
fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
$GLOBALS['__options']['jpkcom_postfilter_general']         = [
	'enabled_post_types' => [ 'post', 'legacy_cpt' ],
	'auto_inject'        => [ 'legacy_cpt' ],
];
call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
$saved = $GLOBALS['__options']['jpkcom_postfilter_general'];
chk( 'a stored non-public post type survives (enabled_post_types)', $saved['enabled_post_types'] === [ 'post', 'legacy_cpt' ], json_encode( $saved ) );
chk( 'a stored non-public post type survives (auto_inject)', $saved['auto_inject'] === [ 'legacy_cpt' ], json_encode( $saved ) );
chk( 'stored array plus the pinned key, nothing else', $saved === [ 'enabled_post_types' => [ 'post', 'legacy_cpt' ], 'auto_inject' => [ 'legacy_cpt' ], 'max_filters_per_group' => 3 ], json_encode( $saved ) );
chk( 'the sanitizer filter is registered again afterwards', has_filter( 'sanitize_option_jpkcom_postfilter_general', 'jpkcom_postfilter_sanitize_general_settings' ) !== false );

// Never saved: exactly the pin, no frozen defaults.
fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => [] ];
call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
chk( 'never saved: the stored option is exactly the pin', ( $GLOBALS['__options']['jpkcom_postfilter_general'] ?? null ) === [ 'max_filters_per_group' => 3 ], json_encode( $GLOBALS['__options']['jpkcom_postfilter_general'] ?? null ) );
chk( 'never saved: the add_option hook path ran', ( $GLOBALS['__hook_fired'] ?? '' ) === 'add' );

// Cache file and memo, with the settings file cache switched on.
foreach ( [ 'add' => null, 'update' => [ 'auto_inject' => [ 'post' ] ] ] as $case => $stored ) {
	fresh_site();
	$GLOBALS['__options']['jpkcom_postfilter_cache']         = [ 'settings_cache_enabled' => true ];
	$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => [] ];
	if ( $stored !== null ) {
		$GLOBALS['__options']['jpkcom_postfilter_general'] = $stored;
		jpkcom_postfilter_settings_write_file( 'general', $stored );
	}
	jpkcom_postfilter_settings_memo_reset();
	$stale = (int) jpkcom_postfilter_policy( 'max_filters_per_group' );   // memoises the pre-migration state
	call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
	chk( "{$case}: the memo is reset, the effective value is 3 (was {$stale})", (int) jpkcom_postfilter_policy( 'max_filters_per_group' ) === 3 );
	jpkcom_postfilter_settings_memo_reset();
	$from_file = jpkcom_postfilter_settings_read_file( 'general' );
	if ( $case === 'update' ) {
		chk( 'update: the cache file carries the pin', is_array( $from_file ) && ( $from_file['max_filters_per_group'] ?? null ) === 3, json_encode( $from_file ) );
	}
	chk( "{$case}: the effective value is 3 after a fresh read", (int) jpkcom_postfilter_policy( 'max_filters_per_group' ) === 3 );
	chk( "{$case}: hook path", ( $GLOBALS['__hook_fired'] ?? '' ) === $case );
	foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f );
		}
	}
}

// ---------------------------------------------------------------------------
section( 'Option deleted: its settings cache file goes with it (T2-b)' );

/**
 * delete_option() as core 7.1 runs it: the row is gone, then
 * delete_option_{$option} and deleted_option fire with the option name -
 * here the callbacks settings.php registered (bootstrap.php collects them).
 */
function delete_option( $k ) {
	if ( ! array_key_exists( $k, $GLOBALS['__options'] ) ) {
		return false;
	}
	unset( $GLOBALS['__options'][ $k ] );
	foreach ( [ 'delete_option_' . $k, 'deleted_option' ] as $tag ) {
		foreach ( $GLOBALS['__actions'][ $tag ] ?? [] as $cb ) {
			$cb( $k );
		}
	}
	return true;
}

foreach ( [ 'general', 'filter_groups', 'layout' ] as $group ) {
	fresh_site();
	$GLOBALS['__options']['jpkcom_postfilter_cache'] = [ 'settings_cache_enabled' => true ];
	$stored = $group === 'general' ? [ 'max_filters_per_group' => 4 ] : [ 'jpkpf_marker' => 'stored' ];
	$GLOBALS['__options'][ 'jpkcom_postfilter_' . $group ] = $stored;
	jpkcom_postfilter_settings_write_file( $group, $stored );
	jpkcom_postfilter_settings_memo_reset();
	$before = jpkcom_postfilter_settings_get_group( $group );
	delete_option( 'jpkcom_postfilter_' . $group );
	$path = jpkcom_postfilter_settings_cache_path( $group );
	clearstatcache();
	chk( "{$group}: the cache file was there and is gone after delete_option()", ( $before['jpkpf_marker'] ?? $before['max_filters_per_group'] ?? null ) !== null && is_string( $path ) && ! is_file( $path ), (string) $path );
	chk( "{$group}: the stored value is no longer read", ! array_key_exists( 'jpkpf_marker', jpkcom_postfilter_settings_get_group( $group ) ) );
	if ( $group === 'general' ) {
		chk( 'general: the effective cap is the default 2 again, not the deleted 4', (int) jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2, (string) jpkcom_postfilter_policy( 'max_filters_per_group' ) );
	}
}
fresh_site();
$GLOBALS['__options']['jpkcom_postfilter_cache']   = [ 'settings_cache_enabled' => true ];
$GLOBALS['__options']['jpkcom_postfilter_general'] = [ 'max_filters_per_group' => 4 ];
jpkcom_postfilter_settings_write_file( 'general', [ 'max_filters_per_group' => 4 ] );
$GLOBALS['__options']['jpkcom_postfilter_unrelated'] = 'x';
delete_option( 'jpkcom_postfilter_unrelated' );
clearstatcache();
chk( 'another option deleted: the general cache file stays', is_file( (string) jpkcom_postfilter_settings_cache_path( 'general' ) ) );

// Clean up the cache files the sanitizer wrote.
jpkpf_test_rm_tree( WP_CONTENT_DIR );
chk( 'temp directory removed', ! is_dir( WP_CONTENT_DIR ) );

exit( summary() );
