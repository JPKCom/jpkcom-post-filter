<?php
/**
 * Tests for keeping the managed .htaccess block in sync (1.6.0, spec 7).
 *
 * The invariant: the managed block may never be stricter than the plugin,
 * not even for one request. So a save that loosens the rules (N higher or 0,
 * a group added, strict URLs off, another endpoint or base) loosens the block
 * BEFORE the option is committed - to the union of the old and the new state -
 * and a save that tightens them writes the block AFTER. When the block cannot
 * be loosened the save is refused: option, settings cache file and .htaccess
 * all stay as they were.
 *
 * The WordPress stand-ins below follow core 7.1.3 where it matters:
 * update_option() runs sanitize_option() (priority order, the plugin's
 * sanitizer on 10) before it reads the old value, returns without a hook when
 * the sanitized value equals the old one, and hands an option that does not
 * exist yet to add_option() - which sanitizes a second time and fires
 * add_option_*, not update_option_*. Every commit records what the .htaccess
 * and the settings cache file hold at that moment.
 *
 * Run with:
 *
 *     php tests/test-htaccess-sync.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.6.0
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/jpkcom-post-filter-tests/' );
define( 'JPKCOM_POSTFILTER_VERSION', '1.6.0-test' );
define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['__tmp'] = sys_get_temp_dir() . '/jpkpf-htaccess-sync-' . getmypid();
define( 'WP_CONTENT_DIR', $GLOBALS['__tmp'] . '/wp-content' );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

$GLOBALS['__home_path'] = $GLOBALS['__tmp'] . '/home/';
@mkdir( $GLOBALS['__home_path'], 0755, true );
@mkdir( WP_CONTENT_DIR, 0755, true );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

// --- hooks with priorities (what the sync depends on) ---------------------------------

$GLOBALS['__hooks'] = [];

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooks'][ $tag ][ $priority ][] = [ $cb, $args ];
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { return add_filter( $tag, $cb, $priority, $args ); }
function has_filter( $tag, $cb = false ) {
	foreach ( $GLOBALS['__hooks'][ $tag ] ?? [] as $priority => $list ) {
		foreach ( $list as [ $c ] ) {
			if ( $cb === false || $c === $cb ) {
				return $cb === false ? true : $priority;
			}
		}
	}
	return false;
}
function has_action( $tag, $cb = false ) { return has_filter( $tag, $cb ); }
function remove_filter( $tag, $cb, $priority = 10 ) {
	foreach ( $GLOBALS['__hooks'][ $tag ][ $priority ] ?? [] as $i => [ $c ] ) {
		if ( $c === $cb ) {
			unset( $GLOBALS['__hooks'][ $tag ][ $priority ][ $i ] );
			return true;
		}
	}
	return false;
}
function remove_action( $tag, $cb, $priority = 10 ) { return remove_filter( $tag, $cb, $priority ); }
function apply_filters( $tag, $value, ...$args ) {
	$hooks = $GLOBALS['__hooks'][ $tag ] ?? [];
	ksort( $hooks );
	foreach ( $hooks as $list ) {
		foreach ( $list as [ $cb, $accepted ] ) {
			$value = $cb( ...array_slice( array_merge( [ $value ], $args ), 0, (int) $accepted ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$hooks = $GLOBALS['__hooks'][ $tag ] ?? [];
	ksort( $hooks );
	foreach ( $hooks as $list ) {
		foreach ( $list as [ $cb, $accepted ] ) {
			$cb( ...array_slice( $args, 0, (int) $accepted ) );
		}
	}
}

// --- options, as core 7.1.3 wp-includes/option.php handles them -----------------------

$GLOBALS['__options']    = [];
$GLOBALS['__registered'] = [];   // option => registered default (register_setting)
$GLOBALS['__commits']    = [];   // one row per row written to the "database"
$GLOBALS['__errors']     = [];

function get_option( $k, $d = false ) {
	if ( array_key_exists( $k, $GLOBALS['__options'] ) ) {
		return $GLOBALS['__options'][ $k ];
	}
	// filter_default_option(): the registered default only when no default was passed.
	if ( func_num_args() < 2 && array_key_exists( $k, $GLOBALS['__registered'] ) ) {
		return $GLOBALS['__registered'][ $k ];
	}
	return $d;
}
function sanitize_option( $option, $value ) {
	return apply_filters( "sanitize_option_{$option}", $value, $option, $value );
}
function record_commit( string $option ): void {
	$GLOBALS['__commits'][] = [
		'option'   => $option,
		'htaccess' => bytes( htaccess() ),
		'cache'    => cache_array( substr( $option, strlen( 'jpkcom_postfilter_' ) ) ),
	];
}
function update_option( $option, $value, $autoload = null ) {
	$value = sanitize_option( $option, $value );
	$old   = get_option( $option );
	$value = apply_filters( "pre_update_option_{$option}", $value, $old, $option );
	if ( $value === $old || serialize( $value ) === serialize( $old ) ) {
		return false;
	}
	if ( ( $GLOBALS['__registered'][ $option ] ?? false ) === $old ) {
		return add_option( $option, $value, '', $autoload );
	}
	record_commit( $option );
	$GLOBALS['__options'][ $option ] = $value;
	do_action( "update_option_{$option}", $old, $value, $option );
	return true;
}
function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
	$value = sanitize_option( $option, $value );
	// Core compares with the default, not with "exists": a stored value equal to
	// the registered default is overwritten (INSERT ... ON DUPLICATE KEY UPDATE).
	if ( ( $GLOBALS['__registered'][ $option ] ?? false ) !== get_option( $option ) ) {
		return false;
	}
	record_commit( $option );
	$GLOBALS['__options'][ $option ] = $value;
	do_action( "add_option_{$option}", $option, $value );
	return true;
}
function delete_option( $option ) {
	if ( ! array_key_exists( $option, $GLOBALS['__options'] ) ) {
		return false;
	}
	unset( $GLOBALS['__options'][ $option ] );
	do_action( "delete_option_{$option}", $option );
	return true;
}
function register_setting( $option_group, $option_name, $args = [] ) {
	if ( isset( $args['sanitize_callback'] ) ) {
		add_filter( "sanitize_option_{$option_name}", $args['sanitize_callback'] );
	}
	$GLOBALS['__registered'][ $option_name ] = $args['default'] ?? false;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['__errors'][] = [ 'setting' => $setting, 'code' => $code, 'message' => $message, 'type' => $type ];
}

// --- other WordPress stand-ins ---------------------------------------------------------

function __( $s, $d = '' ) { return $s; }
function get_bloginfo( $show = '' ) { return $show === 'language' ? 'en-US' : ''; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function esc_url_raw( $u ) { return (string) $u; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function home_url( $path = '' ) { return 'https://example.test' . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function get_home_path() { return $GLOBALS['__home_path']; }
function get_post_types( $args = [] ) { return [ 'post' => 'post', 'page' => 'page', 'jpk_job' => 'jpk_job' ]; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }

// url-routing.php is not loaded: the same post type list, and one archive per post type.
function jpkcom_postfilter_routable_post_types(): array {
	$types = array_map( 'sanitize_key', (array) jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] ) );
	foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
		foreach ( (array) ( $group['post_types'] ?? [] ) as $pt ) {
			$types[] = sanitize_key( (string) $pt );
		}
	}
	return array_values( array_unique( array_filter( $types ) ) );
}
function jpkcom_postfilter_archive_base_regex( string $post_type, int $page_for_posts = 0 ): ?string {
	return [ 'post' => 'blog', 'jpk_job' => 'jobs' ][ $post_type ] ?? null;
}
function jpkcom_postfilter_archive_base_urls_all_languages( string $post_type ): array {
	$base = jpkcom_postfilter_archive_base_regex( $post_type );
	return $base === null ? [] : [ 'https://example.test/' . $base . '/' ];
}

// --- the plugin -------------------------------------------------------------------------

foreach ( [ 'helpers.php', 'settings.php', 'taxonomies.php', 'url-policy.php', 'server-rules.php', 'server-rules-render.php', 'htaccess-manager.php', 'htaccess-sync.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

// What admin_init does for options.php: register the settings and their sanitizers.
do_action( 'admin_init' );

// --- harness ----------------------------------------------------------------------------

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

/** Call a function that may not exist yet; a missing function fails the check instead of ending the run. */
function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

function cleanup(): void {
	$dir = $GLOBALS['__tmp'];
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		if ( $f->isDir() ) {
			@chmod( $f->getPathname(), 0755 );
			@rmdir( $f->getPathname() );
		} else {
			@chmod( $f->getPathname(), 0644 );
			@unlink( $f->getPathname() );
		}
	}
	@rmdir( $dir );
}
register_shutdown_function( 'cleanup' );

function htaccess(): string { return $GLOBALS['__home_path'] . '.htaccess'; }

function bytes( string $f ): string {
	clearstatcache();
	return is_file( $f ) ? (string) file_get_contents( $f ) : '__no_file__';
}

/** The settings cache file of a group as the array it returns, or null when there is none. */
function cache_array( string $group ): ?array {
	$path = JPKCOM_POSTFILTER_SETTINGS_DIR . '/' . $group . '.php';
	clearstatcache();
	if ( ! is_file( $path ) ) {
		return null;
	}
	$data = ( static fn( string $p ): mixed => include $p )( $path );
	return is_array( $data ) ? $data : null;
}

$GLOBALS['__wp_block'] = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";

function general( array $over = [] ): array {
	return array_merge( [
		'enabled_post_types'      => [ 'post' ],
		'auto_inject'             => [ 'post' ],
		'max_filter_combos'       => 3,
		'max_filters_per_group'   => 2,
		'url_endpoint'            => 'filter',
		'endpoint_empty_action'   => '404',
		'endpoint_empty_redirect' => '',
		'settings_cache_path'     => JPKCOM_POSTFILTER_SETTINGS_DIR,
		'debug_mode'              => false,
		'max_terms_total'         => 0,
		'filter_link_mode'        => 'single',
		'seo_index_max_terms'     => 1,
		'strict_urls'             => true,
		'reject_status'           => 410,
		'unknown_terms_action'    => 'redirect',
		'robots_txt_mode'         => 'deep',
		'strip_query'             => false,
	], $over );
}

function group( string $taxonomy, int $order, array $over = [] ): array {
	return array_merge( [
		'slug'              => $taxonomy,
		'taxonomy'          => $taxonomy,
		'label'             => ucfirst( $taxonomy ),
		'order'             => $order,
		'enabled'           => true,
		'hide_empty'        => true,
		'custom'            => false,
		'hierarchical'      => false,
		'public'            => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'post_types'        => [],
	], $over );
}

/**
 * A site: stored options, warm settings cache files, and a .htaccess holding the
 * block for the current settings when it is managed (and $block is true).
 * Returns the current rule inputs.
 */
function site( ?array $general = null, ?array $groups = null, bool $managed = true, bool $block = true, string $extra = '' ): array {
	@chmod( htaccess(), 0644 );
	foreach ( glob( JPKCOM_POSTFILTER_SETTINGS_DIR . '/*.php' ) ?: [] as $f ) {
		@unlink( $f );
	}
	$GLOBALS['__options'] = [
		'jpkcom_postfilter_cache' => [ 'settings_cache_enabled' => true ],
		'page_for_posts'          => 0,
	];
	if ( $general !== null ) {
		$GLOBALS['__options']['jpkcom_postfilter_general'] = $general;
	}
	$GLOBALS['__options']['jpkcom_postfilter_filter_groups'] = [ 'groups' => $groups ?? [ group( 'category', 0 ), group( 'post_tag', 1 ) ] ];
	if ( $managed ) {
		$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = '1';
	}
	file_put_contents( htaccess(), $extra . $GLOBALS['__wp_block'] );
	jpkcom_postfilter_settings_memo_reset();
	jpkcom_postfilter_settings_get_group( 'general' );        // warms the cache files
	jpkcom_postfilter_settings_get_group( 'filter_groups' );

	$inputs = jpkcom_postfilter_server_rule_inputs();
	if ( $block ) {
		jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( $inputs ) );
	}
	$GLOBALS['__commits'] = [];
	$GLOBALS['__errors']  = [];
	$GLOBALS['__base']    = $inputs;
	return $inputs;
}

/** The fixture's inputs with some keys replaced, hash recomputed. */
function with( array $over ): array {
	$inputs         = array_merge( $GLOBALS['__base'], $over );
	$inputs['hash'] = jpkcom_postfilter_server_rule_hash( $inputs );
	return $inputs;
}

function rule_hash( string $content ): ?string {
	return preg_match( '/^# rule ([0-9a-f]{12})\r?$/m', $content, $m ) === 1 ? $m[1] : null;
}

/** Which N (same everything else as the fixture) the block in $content was built for: 'N3', 'none', or '?'. */
function n_of( string $content ): string {
	$hash = rule_hash( $content );
	if ( $hash === null ) {
		return 'none';
	}
	for ( $n = 0; $n <= 20; $n++ ) {
		if ( with( [ 'max_per_group' => $n ] )['hash'] === $hash ) {
			return 'N' . $n;
		}
	}
	return '?';
}

/** Which group count (same everything else) the block was built for: 'G3', 'none', or '?'. */
function g_of( string $content ): string {
	$hash = rule_hash( $content );
	if ( $hash === null ) {
		return 'none';
	}
	for ( $g = 0; $g <= 6; $g++ ) {
		foreach ( [ [], [ 1 ] ] as $skip ) {
			if ( with( [ 'groups' => $g, 'skip_positions' => $skip ] )['hash'] === $hash ) {
				return 'G' . $g . ( $skip !== [] ? 's' : '' );
			}
		}
	}
	return '?';
}

/** What options.php does with a submitted form: update_option( $option, $_POST[ $option ] ). */
function save( string $option, mixed $raw ): bool {
	$GLOBALS['__seen'] = null;
	return update_option( $option, $raw );
}

// What the sanitize_option chain hands on, i.e. what update_option() goes on with.
foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
	add_filter( "pre_update_option_{$o}", static function ( $value ) {
		$GLOBALS['__seen'] = $value;
		return $value;
	}, 10, 1 );
}

$GLOBALS['__fired'] = [];
foreach ( [ 'update', 'add' ] as $kind ) {
	foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
		add_action( "{$kind}_option_{$o}", static function () use ( $kind, $o ) {
			$GLOBALS['__fired'][] = $kind . ':' . $o;
		}, 99, 0 );
	}
}

$have = function_exists( 'jpkcom_postfilter_htaccess_block' ) && function_exists( 'jpkcom_postfilter_server_rule_inputs' );

// =====================================================================================
section( 'Hooks' );
// =====================================================================================

foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
	$short = substr( $o, strlen( 'jpkcom_postfilter_' ) );
	chk( "sanitize_option_{$short}: loosen on priority 5, before the sanitizer on 10",
		has_filter( "sanitize_option_{$o}", 'jpkcom_postfilter_htaccess_before_save' ) === 5
		&& has_filter( "sanitize_option_{$o}", 'jpkcom_postfilter_sanitize_' . $short . '_settings' ) === 10 );
	chk( "update_option_{$short} and add_option_{$short}: sync on priority 20",
		has_action( "update_option_{$o}", 'jpkcom_postfilter_htaccess_after_save' ) === 20
		&& has_action( "add_option_{$o}", 'jpkcom_postfilter_htaccess_after_save' ) === 20 );
}
chk( 'page_for_posts and permalink_structure: sync',
	has_action( 'update_option_page_for_posts', 'jpkcom_postfilter_htaccess_after_save' ) !== false
	&& has_action( 'update_option_permalink_structure', 'jpkcom_postfilter_htaccess_after_save' ) !== false );
chk( 'admin_init: hash comparison', has_action( 'admin_init', 'jpkcom_postfilter_htaccess_maybe_resync' ) !== false );

// =====================================================================================
section( 'union()' );
// =====================================================================================

$GLOBALS['__base'] = [
	'strict'           => true,
	'max_per_group'    => 2,
	'groups'           => 2,
	'skip_positions'   => [],
	'reject_status'    => 410,
	'endpoint'         => 'filter',
	'fragment'         => 'jpkpf-fragment',
	'bases'            => [ '/blog/' ],
	'verdict_filtered' => false,
	'policy_filtered'  => [],
	'hash'             => '',
];
$u = static fn( array $a, array $b ): array => (array) call( 'jpkcom_postfilter_server_rule_union', with( $a ), with( $b ) );

chk( 'union(): 0 dominates, max, skip union',
	( $u( [ 'max_per_group' => 2 ], [ 'max_per_group' => 0 ] )['max_per_group'] ?? null ) === 0
	&& ( $u( [ 'max_per_group' => 0 ], [ 'max_per_group' => 3 ] )['max_per_group'] ?? null ) === 0
	&& ( $u( [ 'max_per_group' => 3 ], [ 'max_per_group' => 2 ] )['max_per_group'] ?? null ) === 3
	&& ( $u( [ 'groups' => 2, 'skip_positions' => [ 1 ] ], [ 'groups' => 3, 'skip_positions' => [ 0 ] ] )['groups'] ?? null ) === 3
	&& ( $u( [ 'groups' => 2, 'skip_positions' => [ 1 ] ], [ 'groups' => 3, 'skip_positions' => [ 0 ] ] )['skip_positions'] ?? null ) === [ 0, 1 ] );
chk( 'union(): strict only when both are', ( $u( [ 'strict' => false ], [] )['strict'] ?? null ) === false && ( $u( [], [] )['strict'] ?? null ) === true );
chk( 'union(): another endpoint, fragment or base set = not strict',
	( $u( [], [ 'endpoint' => 'jobs' ] )['strict'] ?? null ) === false
	&& ( $u( [], [ 'fragment' => 'x' ] )['strict'] ?? null ) === false
	&& ( $u( [], [ 'bases' => [ '/blog/', '/jobs/' ] ] )['strict'] ?? null ) === false
	&& ( $u( [ 'bases' => [ '/jobs/', '/blog/' ] ], [ 'bases' => [ '/blog/', '/jobs/' ] ] )['strict'] ?? null ) === true );
chk( 'union(): no reject part when either side has no groups',
	jpkcom_postfilter_server_reject_patterns( $u( [ 'groups' => 0 ], [ 'groups' => 2 ] ) ) === [ 'per_group' => null, 'segments' => null ] );
chk( 'union(): a filtered verdict on either side wins', ( $u( [ 'verdict_filtered' => true ], [] )['verdict_filtered'] ?? null ) === true );
$x = $u( [ 'max_per_group' => 3 ], [ 'groups' => 3 ] );
chk( 'union(): hash recomputed', isset( $x['hash'] ) && $x['hash'] === jpkcom_postfilter_server_rule_hash( $x ) && $x['hash'] !== with( [] )['hash'] );

// Never stricter, measured: whatever the union's patterns match, the plugin
// rejects in both states - asked through the real validator behind the
// rewrite rules url-routing.php registers for one base. (The patterns of the
// two states are no yardstick: those for G and G+1 groups are not nested,
// each is just one sound subset of its own plugin state.)
mt_srand( 9 );
$variants = [];
foreach ( [ 0, 1, 2, 3 ] as $n ) {
	foreach ( [ 1, 2, 3 ] as $g ) {
		foreach ( [ [], [ 0 ], [ 1 ], [ 0, 2 ] ] as $skip ) {
			$variants[] = [ 'max_per_group' => $n, 'groups' => $g, 'skip_positions' => array_values( array_filter( $skip, static fn( $p ) => $p < $g ) ) ];
		}
	}
}
$matches = static function ( array $inputs, string $uri ): bool {
	foreach ( jpkcom_postfilter_server_reject_patterns( $inputs ) as $p ) {
		if ( $p !== null && preg_match( '~' . str_replace( '~', '\\~', $p ) . '~', $uri ) === 1 ) {
			return true;
		}
	}
	return false;
};
$uris = [];
for ( $i = 0; $i < 300; $i++ ) {
	$segs = [];
	for ( $s = 0, $ns = mt_rand( 1, 6 ); $s < $ns; $s++ ) {
		$pieces = [];
		for ( $k = 0, $nk = mt_rand( 1, 5 ); $k < $nk; $k++ ) {
			$pieces[] = [ 'a', 'b', 'cc', 'd-e', 'page', '2', 'jpkpf-fragment', '' ][ mt_rand( 0, 7 ) ];
		}
		$segs[] = implode( '+', $pieces );
	}
	$uris[] = '/blog/filter/' . implode( '/', $segs ) . '/';
}

/** 'reject' when the plugin in state $v rejects $uri under every slug configuration, else 'ok' / 'none'. */
function plugin_answer( string $uri, array $v ): string {
	$taxes = [ 'category', 'post_tag', 'colour' ];
	$tax   = [];
	for ( $i = 0; $i < $v['groups']; $i++ ) {
		$tax[] = in_array( $i, $v['skip_positions'], true ) ? '' : $taxes[ $i ];
	}
	$rules = [
		'#^blog/filter/(.+?)/page/?([0-9]{1,})/jpkpf-fragment/?$#',
		'#^blog/filter/(.+?)/jpkpf-fragment/?$#',
		'#^blog/filter/(.+?)/page/?([0-9]{1,})/?$#',
		'#^blog/filter/(.+?)/?$#',
	];
	$req = trim( $uri, '/' );
	foreach ( $rules as $rx ) {
		if ( preg_match( $rx, $req, $m ) !== 1 ) {
			continue;
		}
		$known = static fn( string $t ): ?array => [ 'a', 'b', 'cc', 'd-e' ];
		foreach ( [ [ null, 'redirect' ], [ $known, 'redirect' ], [ $known, 'noindex' ] ] as [ $k, $action ] ) {
			if ( jpkcom_postfilter_validate_filter_path( $m[1], $tax, $k, $action )['action'] !== 'reject' ) {
				return 'ok';
			}
		}
		return 'reject';
	}
	return 'none';
}

$bad = '';
if ( function_exists( 'jpkcom_postfilter_server_rule_union' ) ) {
	// The validator reads N from the settings: no file cache, only N and strict URLs set.
	$GLOBALS['__options']['jpkcom_postfilter_cache'] = [ 'settings_cache_enabled' => false ];
	$answers = [];
	foreach ( $variants as $idx => $v ) {
		$GLOBALS['__options']['jpkcom_postfilter_general'] = [ 'max_filters_per_group' => $v['max_per_group'], 'max_filter_combos' => 0, 'max_terms_total' => 0, 'strict_urls' => true ];
		jpkcom_postfilter_settings_memo_reset();
		foreach ( $uris as $uri ) {
			$answers[ $idx ][ $uri ] = plugin_answer( $uri, $v );
		}
	}
	$sound = 0;
	foreach ( $variants as $ia => $a ) {
		foreach ( $variants as $ib => $b ) {
			$union = $u( $a, $b );
			foreach ( $uris as $uri ) {
				if ( $matches( $union, $uri ) ) {
					$sound++;
					if ( $answers[ $ia ][ $uri ] !== 'reject' || $answers[ $ib ][ $uri ] !== 'reject' ) {
						$bad = json_encode( [ $a, $b, $uri, $answers[ $ia ][ $uri ], $answers[ $ib ][ $uri ] ] );
						break 3;
					}
				}
			}
		}
	}
	// The oracle must be able to fail: the bare patterns of a stricter state do match URLs a looser state serves.
	$oracle_bites = false;
	foreach ( $uris as $uri ) {
		if ( $matches( with( [ 'max_per_group' => 1, 'groups' => 1, 'skip_positions' => [] ] ), $uri ) && $answers[ array_search( [ 'max_per_group' => 3, 'groups' => 3, 'skip_positions' => [] ], $variants, true ) ][ $uri ] !== 'reject' ) {
			$oracle_bites = true;
			break;
		}
	}
	chk( 'fuzz oracle: a stricter state\'s patterns do hit URLs a looser state serves', $oracle_bites );
	chk( 'fuzz: the union rejected something (' . $sound . ' matches)', $sound > 1000 );
}
chk( 'union(): never rejects a URL either side lets through (' . count( $variants ) ** 2 . ' pairs x 300 URLs)', function_exists( 'jpkcom_postfilter_server_rule_union' ) && $bad === '', $bad );

// =====================================================================================
section( 'General page: loosen before the commit, tighten after' );
// =====================================================================================

if ( $have ) {
	site( general() );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$file_at_commit = n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' );
	$file_after     = n_of( bytes( htaccess() ) );
	chk( 'raise N 2->3: file has N=3 at option commit', $ok && $file_at_commit === 'N3' && $file_after === 'N3', "$file_at_commit / $file_after" );

	site( general( [ 'max_filters_per_group' => 3 ] ) );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	$file_at_commit = n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' );
	$file_after     = n_of( bytes( htaccess() ) );
	chk( 'lower N 3->2: file still N=3 at commit, N=2 after', $ok && $file_at_commit === 'N3' && $file_after === 'N2', "$file_at_commit / $file_after" );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '0' ] ) );
	chk( 'N 2->0 (unlimited): N=0 at commit', n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N0' && n_of( bytes( htaccess() ) ) === 'N0' );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3abc' ] ) );
	chk( 'unclear N (sanitizer reads "3abc" as 3): looser at commit, N=3 after',
		in_array( n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ), [ 'N0', 'N3' ], true ) && n_of( bytes( htaccess() ) ) === 'N3',
		n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'strict_urls' => '0' ] ) );
	$file_during = $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410';
	chk( 'strict URLs off: no reject part at commit, none after',
		! str_contains( $file_during, 'R=410' ) && ! str_contains( bytes( htaccess() ), 'R=410' ) && str_contains( bytes( htaccess() ), '# BEGIN WordPress' ) );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'url_endpoint' => 'auswahl' ] ) );
	$file_during = $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410';
	chk( 'endpoint change: union has no reject part', ! str_contains( $file_during, 'R=410' ), $file_during );
	chk( 'endpoint change: the new endpoint after the save', str_contains( bytes( htaccess() ), '(?:blog/)auswahl/' ) && str_contains( bytes( htaccess() ), 'R=410' ) );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'enabled_post_types' => [ 'post', 'jpk_job' ] ] ) );
	$file_during = $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410';
	chk( 'post type with another archive added: no reject part at commit, both bases after',
		! str_contains( $file_during, 'R=410' ) && str_contains( bytes( htaccess() ), '(?:blog/|jobs/)filter/' ), $file_during );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'filter_link_mode' => 'combo' ] ) );
	chk( 'a setting the rule does not read: block untouched at commit',
		n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N2' && $GLOBALS['__errors'] === [] );

	// The first save of the General page: the row does not exist yet, so
	// update_option() hands over to add_option(), which sanitizes again and
	// fires add_option_*.
	site( null );
	chk( 'fixture: no general option, defaults in effect (N=2)', ! array_key_exists( 'jpkcom_postfilter_general', $GLOBALS['__options'] ) && n_of( bytes( htaccess() ) ) === 'N2' );
	$GLOBALS['__fired'] = [];
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'first-ever save: add_option path, N=3 at commit and after',
		$ok && $GLOBALS['__fired'] === [ 'add:jpkcom_postfilter_general' ]
		&& n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N3' && n_of( bytes( htaccess() ) ) === 'N3',
		json_encode( $GLOBALS['__fired'] ) );
}

// =====================================================================================
section( 'Filter Groups page (review focus 5)' );
// =====================================================================================

if ( $have ) {
	site( general() );
	$ok = save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] );
	chk( 'add group via filter_groups: groups raised first',
		$ok && g_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'G3' && g_of( bytes( htaccess() ) ) === 'G3',
		g_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) );

	$three = [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ];
	site( general(), $three );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'color', 2 ) ] ] );
	chk( 'remove a group: 3 groups at commit, 2 after',
		g_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'G3' && g_of( bytes( htaccess() ) ) === 'G2' );

	site( general(), $three );
	$raw = [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1, [ 'taxonomy' => '' ] ), group( 'color', 2 ) ] ];
	save( 'jpkcom_postfilter_filter_groups', $raw );
	$at = g_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' );
	chk( 'position switched to no taxonomy: never fewer groups at commit', in_array( $at, [ 'G3', 'G3s' ], true ), $at );
	chk( 'position switched to no taxonomy: the sanitizer drops it, 2 groups after', g_of( bytes( htaccess() ) ) === 'G2' );

	site( general(), $three );
	$raw = [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1, [ 'enabled' => '0' ] ), group( 'color', 2 ) ] ];
	save( 'jpkcom_postfilter_filter_groups', $raw );
	chk( 'group disabled: 3 groups at commit, 2 after',
		g_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'G3' && g_of( bytes( htaccess() ) ) === 'G2' );

	site( general() );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1, [ 'post_types' => [ 'jpk_job' ] ] ) ] ] );
	$file_during = $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410';
	chk( 'group adds a post type with another archive: no reject part at commit, both bases after',
		! str_contains( $file_during, 'R=410' ) && str_contains( bytes( htaccess() ), '(?:blog/|jobs/)filter/' ) );
}

// =====================================================================================
section( 'Refused when the block cannot be loosened' );
// =====================================================================================

if ( $have ) {
	site( general() );
	$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
	$cache_was  = cache_array( 'general' );
	$file_was   = bytes( htaccess() );
	chmod( htaccess(), 0444 );
	$GLOBALS['__fired'] = [];
	$ok       = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3', 'auto_inject' => [] ] ) );
	$returned = $GLOBALS['__seen'];
	$errors   = $GLOBALS['__errors'];
	chk( 'write fails: old value returned, error added', $returned === $old_stored && ( $errors[0]['type'] ?? '' ) === 'error', json_encode( $errors ) );
	chk( 'write fails: the error shows on the General page', ( $errors[0]['setting'] ?? '' ) === 'jpkcom_postfilter_general_messages' );
	chk( 'write fails: option, cache file, htaccess unchanged',
		$ok === false && $GLOBALS['__commits'] === [] && $GLOBALS['__fired'] === []
		&& $GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored
		&& cache_array( 'general' ) === $cache_was && $cache_was !== null
		&& bytes( htaccess() ) === $file_was );
	jpkcom_postfilter_settings_memo_reset();
	chk( 'write fails: the plugin still runs with N=2', jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2 );

	// Stored value the sanitizer would change: the refusal must not rewrite it either.
	$partial = [ 'max_filters_per_group' => 2 ];
	site( $partial );
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'write fails on a partial stored value: no commit, stored array untouched',
		$ok === false && $GLOBALS['__commits'] === [] && $GLOBALS['__options']['jpkcom_postfilter_general'] === $partial
		&& cache_array( 'general' ) === $partial );

	// First-ever save refused: nothing is added, no cache file appears.
	site( null );
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'write fails on the first save: option still absent, no cache file',
		$ok === false && ! array_key_exists( 'jpkcom_postfilter_general', $GLOBALS['__options'] ) && cache_array( 'general' ) === null && $GLOBALS['__commits'] === [] );

	site( general() );
	$groups_was = $GLOBALS['__options']['jpkcom_postfilter_filter_groups'];
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] );
	chk( 'write fails on the Filter Groups page: refused there too, error on that page',
		$ok === false && $GLOBALS['__options']['jpkcom_postfilter_filter_groups'] === $groups_was
		&& cache_array( 'filter_groups' ) === $groups_was
		&& ( $GLOBALS['__errors'][0]['setting'] ?? '' ) === 'jpkcom_postfilter_filter_groups_messages' );

	// A tightening save needs no write before the commit: it goes through.
	site( general( [ 'max_filters_per_group' => 3 ] ) );
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	chk( 'read-only file, tightening N 3->2: saved, no error, block stays looser (N=3)',
		$ok === true && $GLOBALS['__errors'] === [] && n_of( bytes( htaccess() ) ) === 'N3' );

	// A managed block that the file no longer has: nothing could be stricter.
	site( general(), null, true, false );
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'read-only file without a managed block: saved', $ok === true && $GLOBALS['__errors'] === [] );

	// Import (settings_save writes the cache file itself after update_option()).
	site( general() );
	$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
	chmod( htaccess(), 0444 );
	jpkcom_postfilter_settings_save( 'general', general( [ 'max_filters_per_group' => 3 ] ) );
	jpkcom_postfilter_settings_memo_reset();
	chk( 'refused through settings_save() (import): cache file back on the stored value',
		$GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored && cache_array( 'general' ) === $old_stored
		&& jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2 );
}

// =====================================================================================
section( 'Not managed, code filters, admin_init' );
// =====================================================================================

if ( $have ) {
	site( general(), null, false, false );
	$was = bytes( htaccess() );
	touch( htaccess(), time() - 100 );
	clearstatcache();
	$mtime = filemtime( htaccess() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ) ] ] );
	call( 'jpkcom_postfilter_htaccess_sync' );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	clearstatcache();
	$writes = ( bytes( htaccess() ) !== $was || filemtime( htaccess() ) !== $mtime ) ? 1 : 0;
	chk( 'not managed: nothing written', $writes === 0 && function_exists( 'jpkcom_postfilter_htaccess_sync' ) );

	site( general() );
	$verdict = static fn( $v ) => $v;
	add_filter( 'jpkcom_postfilter_request_verdict', $verdict );
	call( 'jpkcom_postfilter_htaccess_sync' );
	$file = bytes( htaccess() );
	chk( 'verdict filter hooked: block without reject', ! str_contains( $file, 'R=410' ) && str_contains( $file, '# BEGIN WordPress' ) );
	remove_filter( 'jpkcom_postfilter_request_verdict', $verdict );

	// A filter (or constant) changed the effective value between requests.
	site( general() );
	$cap = static fn( $v ) => 4;
	add_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init: hash differs -> synced to the effective N=4', n_of( bytes( htaccess() ) ) === 'N4' );
	remove_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );

	site( general() );
	touch( htaccess(), time() - 100 );
	clearstatcache();
	$mtime = filemtime( htaccess() );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	clearstatcache();
	chk( 'admin_init: same hash -> no write', filemtime( htaccess() ) === $mtime && function_exists( 'jpkcom_postfilter_htaccess_maybe_resync' ) );

	site( general() );
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	$was = bytes( htaccess() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '1' ] ) );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'not available (nginx): tightening never writes', bytes( htaccess() ) === $was );
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

	// Ruling R17: refuse only where the server reads .htaccess.
	site( general() );
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	$was = bytes( htaccess() );
	chmod( htaccess(), 0444 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'R17 nginx, managed + unwritable, loosening: saved, no write, no error',
		$ok === true && $GLOBALS['__errors'] === [] && bytes( htaccess() ) === $was
		&& ( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 3 );

	foreach ( [ 'Apache/2.4.66 (Debian)', 'LiteSpeed' ] as $software ) {
		site( general() );
		$_SERVER['SERVER_SOFTWARE'] = $software;
		$was = bytes( htaccess() );
		chmod( htaccess(), 0444 );
		$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		chk( "R17 {$software}, managed + unwritable, loosening: refused",
			$ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' && bytes( htaccess() ) === $was
			&& $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] === 2 );
	}
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
}

// =====================================================================================
section( 'Migration (Task 2) during a managed .htaccess' );
// =====================================================================================

if ( $have ) {
	// Stored without the key, so 2 (the new default) is in effect until the pin.
	$legacy = [ 'enabled_post_types' => [ 'post' ], 'auto_inject' => [ 'post' ] ];
	site( $legacy );
	$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
	call( 'jpkcom_postfilter_htaccess_without_refusal', 'jpkcom_postfilter_migrate_pin_per_group_cap' );
	chk( 'migration: pin committed with N=3 already in the block',
		( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 3
		&& n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N3' );

	site( $legacy );
	$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
	chmod( htaccess(), 0444 );
	call( 'jpkcom_postfilter_htaccess_without_refusal', 'jpkcom_postfilter_migrate_pin_per_group_cap' );
	chk( 'migration with a read-only .htaccess: still succeeds, no error',
		( $GLOBALS['__options']['jpkcom_postfilter_general'] ?? null ) === $legacy + [ 'max_filters_per_group' => 3 ] && $GLOBALS['__errors'] === [] );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '5' ] ) );
	chk( 'after the migration a normal save is refused again', $ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' );
}

// =====================================================================================
section( 'Lifecycle' );
// =====================================================================================

if ( $have ) {
	$manual = "# BEGIN JPKCom Post Filter (manual)\n# hand-made\n# END JPKCom Post Filter (manual)\n";

	site( general() );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	chk( 'deactivate: block and option gone',
		bytes( htaccess() ) === $GLOBALS['__wp_block'] && ! array_key_exists( 'jpkcom_postfilter_htaccess_managed', $GLOBALS['__options'] ) );

	// A block that cannot be removed stays guarded (fix round 1).
	site( general() );
	chmod( htaccess(), 0444 );
	$was = bytes( htaccess() );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	chk( 'deactivate, read-only file: block and option stay',
		bytes( htaccess() ) === $was && ( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] ?? '' ) === '1' );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'deactivate (read-only) -> reactivate -> loosening save on Apache: refused',
		$ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' && bytes( htaccess() ) === $was );

	site( general() );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'deactivate (removable) -> reactivate -> loosening save: option gone, saved, nothing written',
		$ok === true && $GLOBALS['__errors'] === [] && ! isset( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] )
		&& bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	// An orphan: managed markers in the file, the option gone.
	site( general(), null, false, true );
	chmod( htaccess(), 0444 );
	$was = bytes( htaccess() );
	$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'orphaned block, read-only, loosening save on Apache: refused', $ok === false && bytes( htaccess() ) === $was && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' );

	site( general(), null, false, true );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'orphaned block, writable: removed before the commit, saved',
		$ok === true && ! str_contains( $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410', 'R=410' ) && bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	site( general(), null, false, true );
	chmod( htaccess(), 0444 );
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'orphaned block, read-only, nginx: saved (R17)', $ok === true && $GLOBALS['__errors'] === [] );
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

	site( general(), null, false, false, $manual );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	chk( 'deactivate: a manual block stays', bytes( htaccess() ) === $manual . $GLOBALS['__wp_block'] );

	site( general(), null, false, true );
	jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => 1 ] ) ) );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	chk( 'stale managed marker without option: removed on version change', bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	site( general( [ 'max_filters_per_group' => 3 ] ) );
	jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => 1 ] ) ) );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	chk( 'managed with an outdated block: synced on version change', n_of( bytes( htaccess() ) ) === 'N3' );

	site( general(), null, false, false, $manual );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	chk( 'version change: a manual block is never touched', bytes( htaccess() ) === $manual . $GLOBALS['__wp_block'] );

	site( general(), null, false, false );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	chk( 'activation does not set anything up', bytes( htaccess() ) === $GLOBALS['__wp_block'] && ! isset( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] ) );
}

// =====================================================================================
section( 'Constants (last: they cannot be undefined)' );
// =====================================================================================

if ( $have ) {
	site( general() );
	define( 'JPKCOM_POSTFILTER_MAX_FILTERS_PER_GROUP', 2 );
	$p = (array) call( 'jpkcom_postfilter_server_inputs_from_raw', 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '9', 'strict_urls' => '0' ] ) );
	chk( 'locked constant beats raw input', ( $p['max_per_group'] ?? null ) === 2 && ( $p['strict'] ?? null ) === false );
	chk( 'inputs_from_raw() carries a fresh hash', isset( $p['hash'] ) && $p['hash'] === jpkcom_postfilter_server_rule_hash( $p ) );

	// Ruling R18: no resync on admin-ajax (heartbeat).
	site( general() );
	jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => 1 ] ) ) );
	$was = bytes( htaccess() );
	define( 'DOING_AJAX', true );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'R18 DOING_AJAX: hash mismatch causes no write', bytes( htaccess() ) === $was && n_of( $was ) === 'N1' );
}

exit( summary() );
