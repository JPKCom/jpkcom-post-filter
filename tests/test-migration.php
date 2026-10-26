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

// --- WordPress stand-ins beyond bootstrap.php ----------------------------------

function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function esc_url_raw( $u ) { return (string) $u; }
function get_post_types( $args = [] ) { return [ 'post' => 'post', 'page' => 'page', 'jpk_job' => 'jpk_job' ]; }
function do_action( ...$args ) {}
function jpkcom_postfilter_cache_flush_group(): void {}

/**
 * Records writes, and - like WordPress - runs the registered sanitizer on the
 * value when the option is the General group and $GLOBALS['__sanitize'] is on.
 * With it off, the raw array is stored, which is what the migration hands over.
 */
$GLOBALS['__writes']   = [];
$GLOBALS['__sanitize'] = false;
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__writes'][] = $k;
	if ( $GLOBALS['__sanitize'] && $k === 'jpkcom_postfilter_general' ) {
		$v = jpkcom_postfilter_sanitize_general_settings( $v );
	}
	$GLOBALS['__options'][ $k ] = $v;
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
	$GLOBALS['__options']  = [ 'jpkcom_postfilter_cache' => [ 'settings_cache_enabled' => false ] ];
	$GLOBALS['__writes']   = [];
	$GLOBALS['__sanitize'] = false;
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
unset( $before['max_filters_per_group'], $after['max_filters_per_group'] );
chk( 'pin keeps every other effective value', $before === $after, json_encode( [ $before, $after ] ) );
chk( 'auto_inject survives the pin', ( $saved['auto_inject'] ?? null ) === [ 'post' ], json_encode( $saved['auto_inject'] ?? null ) );
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
section( 'The real sanitizer (what update_option runs in WordPress)' );

foreach ( [ 'never saved general' => null, 'saved general without key' => [ 'auto_inject' => [ 'jpk_job' ], 'enabled_post_types' => [ 'post', 'jpk_job' ], 'max_filter_combos' => 5, 'debug_mode' => true, 'robots_txt_mode' => 'all', 'seo_index_max_terms' => 2, 'strip_query' => true ] ] as $label => $stored ) {
	fresh_site();
	$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => [] ];
	if ( $stored !== null ) {
		$GLOBALS['__options']['jpkcom_postfilter_general'] = $stored;
	}
	$before = effective_general();

	$GLOBALS['__sanitize'] = true;
	call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
	$GLOBALS['__sanitize'] = false;

	$after = effective_general();
	$pin   = $after['max_filters_per_group'];
	unset( $before['max_filters_per_group'], $after['max_filters_per_group'] );
	chk( "merged array survives the real sanitizer ({$label}): every effective value unchanged", $before === $after, json_encode( [ $before, $after ] ) );
	chk( "merged array survives the real sanitizer ({$label}): max_filters_per_group = 3", $pin === 3, (string) $pin );
}

// Clean up the cache files the sanitizer wrote.
foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f );
	}
}
@rmdir( JPKCOM_POSTFILTER_SETTINGS_DIR );
@rmdir( WP_CONTENT_DIR );

exit( summary() );
