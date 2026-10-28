<?php
/**
 * Tests for the server rule status check and the notice after loosening saves
 * (1.6.0, spec 8).
 *
 * - "Check now": loopback probes against the site's own URLs, classified by a
 *   pure function. A probe the site does not let through (HTTP auth, firewall,
 *   maintenance mode, timeout) is `unreachable`, never `missing` (review
 *   focus 4). A server that rejects a URL the plugin serves is an alarm.
 * - After every save of the General or Filter Groups page that loosens the
 *   rule inputs (spec 7's definition) the saving admin gets a notice to update
 *   deployed server rules - red when the last check found them active or
 *   stale, none when the .htaccess block is managed (it follows by itself).
 *   The Filter Groups page counts as much as the General page (review focus 5).
 *
 * The option stand-ins follow core 7.1.3 like tests/test-htaccess-sync.php:
 * sanitize_option() first, no hook for an unchanged value, add_option() (with
 * a second sanitize) for an option that is not stored yet.
 *
 * Run with:
 *
 *     php tests/test-server-rules-check.php
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

$GLOBALS['__tmp'] = sys_get_temp_dir() . '/jpkpf-server-check-' . getmypid();
define( 'WP_CONTENT_DIR', $GLOBALS['__tmp'] . '/wp-content' );
define( 'JPKCOM_POSTFILTER_SETTINGS_DIR', WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings' );

$GLOBALS['__home_path'] = $GLOBALS['__tmp'] . '/home/';
@mkdir( $GLOBALS['__home_path'], 0755, true );
@mkdir( WP_CONTENT_DIR, 0755, true );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

// --- hooks with priorities ---------------------------------------------------------------

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
$GLOBALS['__autoload']   = [];   // option => autoload flag of the last write
$GLOBALS['__errors']     = [];

function get_option( $k, $d = false ) {
	if ( array_key_exists( $k, $GLOBALS['__options'] ) ) {
		return $GLOBALS['__options'][ $k ];
	}
	if ( func_num_args() < 2 && array_key_exists( $k, $GLOBALS['__registered'] ) ) {
		return $GLOBALS['__registered'][ $k ];
	}
	return $d;
}
function maybe_serialize( $data ) { return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data; }
function sanitize_option( $option, $value ) {
	return apply_filters( "sanitize_option_{$option}", $value, $option, $value );
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
	$GLOBALS['__options'][ $option ]  = $value;
	$GLOBALS['__autoload'][ $option ] = $autoload;
	do_action( "update_option_{$option}", $old, $value, $option );
	return true;
}
function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
	$value = sanitize_option( $option, $value );
	if ( ( $GLOBALS['__registered'][ $option ] ?? false ) !== get_option( $option ) ) {
		return false;
	}
	$GLOBALS['__options'][ $option ]  = $value;
	$GLOBALS['__autoload'][ $option ] = $autoload;
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

// --- transients, user ------------------------------------------------------------------------

$GLOBALS['__transients'] = [];
$GLOBALS['__user']       = 7;
$GLOBALS['__can']        = true;

function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = [ $v, $ttl ]; return true; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ][0] : false; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function get_current_user_id() { return $GLOBALS['__user']; }
function current_user_can( $cap ) { return $GLOBALS['__can'] && $GLOBALS['__user'] > 0; }

// --- HTTP ---------------------------------------------------------------------------------

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['__http']      = [];   // [method, url, args]
$GLOBALS['__responder'] = null; // fn( method, url ): array|WP_Error

function http_answer( string $method, string $url, array $args ) {
	$GLOBALS['__http'][] = [ $method, $url, $args ];
	$r = $GLOBALS['__responder'];
	return $r === null ? new WP_Error( 'http_request_failed', 'no responder' ) : $r( $method, $url );
}
function wp_remote_get( $url, $args = [] ) { return http_answer( 'GET', (string) $url, (array) $args ); }
function wp_remote_head( $url, $args = [] ) { return http_answer( 'HEAD', (string) $url, (array) $args ); }
function wp_remote_request( $url, $args = [] ) { return http_answer( (string) ( $args['method'] ?? 'GET' ), (string) $url, (array) $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; }
function wp_remote_retrieve_headers( $r ) { return is_array( $r ) ? ( $r['headers'] ?? [] ) : []; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }

/** A wp_remote_* style response; header names as the server sent them. */
function http( int $status, array $headers = [], string $body = '' ): array {
	return [ 'response' => [ 'code' => $status, 'message' => '' ], 'headers' => $headers, 'body' => $body ];
}

// --- other WordPress stand-ins ---------------------------------------------------------

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function get_bloginfo( $show = '' ) { return $show === 'language' ? 'en-US' : ''; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function esc_url_raw( $u ) { return (string) $u; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
$GLOBALS['__home'] = 'https://example.test';
function home_url( $path = '' ) { return $GLOBALS['__home'] . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function get_home_path() { return $GLOBALS['__home_path']; }
function get_post_types( $args = [] ) { return [ 'post' => 'post', 'page' => 'page', 'jpk_job' => 'jpk_job' ]; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function jpkcom_postfilter_cache_flush_group(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }

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
	return $base === null ? [] : [ home_url( '/' . $base . '/' ) ];
}

// --- the plugin -------------------------------------------------------------------------

foreach ( [ 'helpers.php', 'settings.php', 'taxonomies.php', 'url-policy.php', 'server-rules.php', 'server-rules-render.php', 'htaccess-manager.php', 'htaccess-sync.php', 'server-rules-check.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

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
 * A site: stored options, the settings cache files a save wrote, a .htaccess (with the
 * managed block when $managed). No transients, no last check.
 */
function site( ?array $general = null, ?array $groups = null, bool $managed = false ): array {
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
	file_put_contents( htaccess(), $GLOBALS['__wp_block'] );
	// The cache files a save wrote (reading no longer writes them, H1).
	foreach ( [ 'general', 'filter_groups' ] as $g ) {
		if ( isset( $GLOBALS['__options'][ 'jpkcom_postfilter_' . $g ] ) ) {
			jpkcom_postfilter_settings_write_file( $g, $GLOBALS['__options'][ 'jpkcom_postfilter_' . $g ] );
		}
	}
	jpkcom_postfilter_settings_memo_reset();

	$inputs = jpkcom_postfilter_server_rule_inputs();
	if ( $managed && function_exists( 'jpkcom_postfilter_htaccess_write' ) ) {
		jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( $inputs ) );
	}
	$GLOBALS['__transients'] = [];
	$GLOBALS['__errors']     = [];
	$GLOBALS['__user']       = 7;
	$GLOBALS['__can']        = true;
	return $inputs;
}

function save( string $option, mixed $raw ): bool {
	return update_option( $option, $raw );
}

function noticed(): bool {
	return get_transient( 'jpkcom_postfilter_loosened_7' ) !== false;
}

function notice_html(): string {
	ob_start();
	do_action( 'admin_notices' );
	return (string) ob_get_clean();
}

$have = function_exists( 'jpkcom_postfilter_server_rule_inputs' ) && function_exists( 'jpkcom_postfilter_htaccess_block' );

// Inputs of the fixture used for probe_urls() and classify().
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

/** The fixture's inputs with some keys replaced, hash recomputed. */
function with( array $over ): array {
	$inputs         = array_merge( $GLOBALS['__base'], $over );
	$inputs['hash'] = function_exists( 'jpkcom_postfilter_server_rule_hash' ) ? jpkcom_postfilter_server_rule_hash( $inputs ) : '';
	return $inputs;
}

// Responses as classify() takes them.
function resp( int $status, array $headers = [], string $body = '' ): array {
	return [ 'error' => null, 'status' => $status, 'headers' => $headers, 'body' => $body ];
}
function hdr( string $reject, string $rule = '', int $status = 410 ): array {
	$headers = [ 'x-jpkpf-reject' => $reject ];
	if ( $rule !== '' ) {
		$headers['x-jpkpf-rule'] = $rule;
	}
	return resp( $status, $headers );
}
/** The crawl guard's own redirect: wp_safe_redirect( ..., 'jpkcom-post-filter' ) sends X-Redirect-By. */
function plugin_301( string $location = 'https://example.test/blog/' ): array {
	return resp( 301, [ 'location' => $location, 'x-redirect-by' => 'jpkcom-post-filter' ] );
}
function err( string $message ): array {
	return [ 'error' => $message, 'status' => 0, 'headers' => [], 'body' => '' ];
}
function robots_body(): string {
	return "User-agent: *\nDisallow: /wp-admin/\n\n# jpkcom-post-filter\nUser-agent: *\nDisallow: /blog/filter/*+\nDisallow: /*jpkpf-fragment\nDisallow: /*jpkcom_filter_\n";
}

/** classify() with a healthy default for every probe that is not given. */
function classify( ?array $robots = null, ?array $over = null, ?array $at_cap = null, ?array $inputs = null, string $software = 'nginx/1.26.3', bool $robots_expected = true ): array {
	$inputs = $inputs ?? with( [] );
	$fn     = 'jpkcom_postfilter_server_check_classify';
	if ( ! function_exists( $fn ) ) {
		return [ 'robots' => '__missing__', 'rule' => '__missing__', 'stricter' => null, 'detail' => '' ];
	}
	return $fn(
		[
			'robots' => $robots ?? resp( 200, [ 'content-type' => 'text/plain' ], robots_body() ),
			'over'   => $over ?? hdr( 'server-per-group', $inputs['hash'] ),
			'at_cap' => $at_cap ?? plugin_301(),
		],
		$inputs,
		$software,
		$robots_expected
	);
}

$h = with( [] )['hash'];

// =====================================================================================
section( 'probe_urls()' );
// =====================================================================================

$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [] ) );
chk( 'robots: the site root robots.txt', ( $urls['robots'] ?? null ) === 'https://example.test/robots.txt', json_encode( $urls ) );
chk( 'over: N+1 probe slugs under the first base', ( $urls['over'] ?? null ) === 'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3/', json_encode( $urls ) );
chk( 'at_cap: exactly N probe slugs', ( $urls['at_cap'] ?? null ) === 'https://example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2/', json_encode( $urls ) );

if ( function_exists( 'jpkcom_postfilter_server_probe_paths' ) && function_exists( 'jpkcom_postfilter_server_probe_urls' ) ) {
	$paths = jpkcom_postfilter_server_probe_paths( with( [] ) );
	chk( 'same paths the printed curl commands probe', $urls['over'] === 'https://example.test' . $paths['per_group'] && $urls['at_cap'] === 'https://example.test' . $paths['within'][0] );
}

$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [ 'skip_positions' => [ 0 ] ] ) );
chk( 'first position without taxonomy: probes the next one', ( $urls['over'] ?? null ) === 'https://example.test/blog/filter/_/jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3/', json_encode( $urls ) );

$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [ 'strict' => false ] ) );
chk( 'no pattern (strict off): over and at_cap null, robots still probed',
	array_key_exists( 'over', $urls ) && $urls['over'] === null && $urls['at_cap'] === null && ( $urls['robots'] ?? '' ) === 'https://example.test/robots.txt', json_encode( $urls ) );

$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [ 'max_per_group' => 0 ] ) );
chk( 'N = 0 (unlimited): the segments rule is probed, at_cap within the groups',
	( $urls['over'] ?? null ) === 'https://example.test/blog/filter/jpkpf-probe-1/jpkpf-probe-2/jpkpf-probe-3/jpkpf-probe-4/jpkpf-probe-5/jpkpf-probe-6/'
	&& ( $urls['at_cap'] ?? null ) === 'https://example.test/blog/filter/jpkpf-probe-1/jpkpf-probe-2/', json_encode( $urls ) );

$GLOBALS['__home'] = 'https://example.test:8443/sub';
$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [ 'bases' => [ '/sub/blog/' ] ] ) );
chk( 'port kept, robots.txt at the host root, base path includes the home path',
	( $urls['robots'] ?? null ) === 'https://example.test:8443/robots.txt'
	&& ( $urls['over'] ?? null ) === 'https://example.test:8443/sub/blog/filter/jpkpf-probe-1+jpkpf-probe-2+jpkpf-probe-3/', json_encode( $urls ) );
$GLOBALS['__home'] = 'https://example.test';

$urls = (array) call( 'jpkcom_postfilter_server_probe_urls', with( [ 'bases' => [ '/bl"og/' ] ] ) );
chk( 'a base no snippet contains is not probed', array_key_exists( 'over', $urls ) && $urls['over'] === null, json_encode( $urls ) );

// =====================================================================================
section( 'classify(): the rule' );
// =====================================================================================

chk( 'server-* + same hash = active',          classify( over: hdr( 'server-per-group', $h ) )['rule'] === 'active' );
chk( 'server-* + other hash = stale',          classify( over: hdr( 'server-per-group', 'aaaaaaaaaaaa' ) )['rule'] === 'stale' );
chk( 'server-* without X-Jpkpf-Rule = stale',  classify( over: hdr( 'server-per-group' ) )['rule'] === 'stale' );
chk( 'server-segments counts as the rule too', classify( over: hdr( 'server-segments', $h ) )['rule'] === 'active' );
chk( 'PHP per-group = missing',                classify( over: hdr( 'per-group' ) )['rule'] === 'missing' );
chk( 'PHP segments = missing',                 classify( over: hdr( 'segments' ) )['rule'] === 'missing' );
chk( 'header names in any case',               classify( over: resp( 410, [ 'X-Jpkpf-Reject' => 'server-per-group', 'X-JPKPF-RULE' => $h ] ) )['rule'] === 'active' );
chk( 'LiteSpeed 410 without header = unknown', classify( over: resp( 410 ), software: 'LiteSpeed' )['rule'] === 'unknown' );
chk( 'LiteSpeed 404 without header = unknown', classify( over: resp( 404 ), software: 'LiteSpeed' )['rule'] === 'unknown' );
chk( 'no header elsewhere = unknown, not missing (a proxy may strip it)', classify( over: resp( 410 ) )['rule'] === 'unknown' );
chk( 'timeout = unreachable, not missing',     classify( over: err( 'cURL error 28: Operation timed out after 5001 milliseconds' ) )['rule'] === 'unreachable' );
chk( 'transport error = unreachable',          classify( over: err( 'cURL error 7: Failed to connect' ) )['rule'] === 'unreachable' );
chk( '401 basic auth = unreachable',           classify( over: resp( 401, [ 'www-authenticate' => 'Basic realm="staging"' ] ) )['rule'] === 'unreachable' );
chk( '403 firewall = unreachable',             classify( over: resp( 403 ) )['rule'] === 'unreachable' );
chk( '503 maintenance = unreachable',          classify( over: resp( 503, [ 'retry-after' => '600' ] ) )['rule'] === 'unreachable' );
chk( '500 = unreachable',                      classify( over: resp( 500 ) )['rule'] === 'unreachable' );
chk( '429 = unreachable',                      classify( over: resp( 429 ) )['rule'] === 'unreachable' );
chk( 'no patterns = rule none',                classify( over: hdr( 'per-group' ), inputs: with( [ 'strict' => false ] ) )['rule'] === 'none' );
chk( 'no patterns (filtered verdict) = rule none', classify( inputs: with( [ 'verdict_filtered' => true ] ) )['rule'] === 'none' );

$r = classify( over: resp( 401 ) );
chk( 'unreachable carries the reason', str_contains( $r['detail'], '401' ), $r['detail'] );
$r = classify( over: err( 'cURL error 28: Operation timed out' ) );
chk( 'unreachable carries the transport error', str_contains( $r['detail'], 'cURL error 28' ), $r['detail'] );
$r = classify( over: resp( 503 ) );
chk( '503 names maintenance mode', str_contains( $r['detail'], '503' ) && stripos( $r['detail'], 'maintenance' ) !== false, $r['detail'] );
$r = classify();
chk( 'detail explains the rate limit is not checked', stripos( $r['detail'], 'rate limit' ) !== false, $r['detail'] );
chk( 'detail is plain text', $r['detail'] === strip_tags( $r['detail'] ) );

// =====================================================================================
section( 'classify(): stricter than the plugin' );
// =====================================================================================

chk( 'at_cap server-* = stricter alarm',       classify( at_cap: hdr( 'server-per-group', $h ) )['stricter'] === true );
chk( 'at_cap server-* with other hash = stricter alarm', classify( at_cap: hdr( 'server-per-group', 'aaaaaaaaaaaa' ) )['stricter'] === true );
chk( 'at_cap 301 from PHP = not stricter',     classify( at_cap: plugin_301() )['stricter'] === false );
chk( 'at_cap PHP reject (unknown terms) = not stricter', classify( at_cap: hdr( 'unknown' ) )['stricter'] === false );
$r = classify( at_cap: err( 'cURL error 28: Operation timed out' ) );
chk( 'at_cap timeout: no alarm, but marked as not checked', $r['stricter'] === false && ( $r['stricter_checked'] ?? null ) === false, json_encode( $r ) );
chk( 'at_cap timeout: the detail says the check could not run, and why',
	stripos( $r['detail'], 'could not run' ) !== false && str_contains( $r['detail'], 'cURL error 28' ), $r['detail'] );
$r = classify( at_cap: resp( 429 ) );
chk( 'at_cap 429 (the loopback hit a rate limit): not checked', ( $r['stricter_checked'] ?? null ) === false && str_contains( $r['detail'], '429' ), $r['detail'] );
$r = classify( at_cap: resp( 503 ) );
chk( 'at_cap 503: not checked', ( $r['stricter_checked'] ?? null ) === false );
chk( 'at_cap 301 from PHP: checked', ( classify( at_cap: plugin_301() )['stricter_checked'] ?? null ) === true );
// T10-a: a 3xx is the plugin's answer only when the plugin sent it. A redirect
// from somewhere else (http -> https, another host name, a proxy, WordPress's
// canonical redirect) means the probe never reached the code path that
// decides, so it proves nothing about the server rules. Missed check, never a
// false alarm.
$r = classify( at_cap: resp( 301, [ 'location' => 'https://www.example.test/blog/filter/jpkpf-probe-1+jpkpf-probe-2/' ] ) );
chk( 'at_cap 301 without X-Redirect-By: not checked, no alarm', ( $r['stricter_checked'] ?? null ) === false && $r['stricter'] === false && ( $r['stricter_suspected'] ?? null ) === false, json_encode( $r ) );
chk( 'at_cap 301 without X-Redirect-By: the detail names the redirect and its target',
	stripos( $r['detail'], 'could not run' ) !== false && str_contains( $r['detail'], 'HTTP 301' ) && str_contains( $r['detail'], 'https://www.example.test/blog/filter/' ), $r['detail'] );
$r = classify( at_cap: resp( 302, [ 'location' => 'https://example.test/blog/', 'x-redirect-by' => 'WordPress' ] ) );
chk( 'at_cap 302 by WordPress (not the plugin): not checked', ( $r['stricter_checked'] ?? null ) === false && str_contains( $r['detail'], 'HTTP 302' ), $r['detail'] );
chk( 'at_cap 308 from a proxy: not checked', ( classify( at_cap: resp( 308, [ 'location' => 'https://example.test/' ] ) )['stricter_checked'] ?? null ) === false );
chk( 'at_cap 301 by the plugin, header name and value in other case: checked',
	( classify( at_cap: resp( 301, [ 'location' => 'https://example.test/blog/', 'X-Redirect-By' => 'JPKCom-Post-Filter' ] ) )['stricter_checked'] ?? null ) === true );
chk( 'at_cap 307 by the plugin: checked', ( classify( at_cap: resp( 307, [ 'location' => 'https://example.test/blog/', 'x-redirect-by' => 'jpkcom-post-filter' ] ) )['stricter_checked'] ?? null ) === true );
chk( 'at_cap 200: checked (the plugin served it)', ( classify( at_cap: resp( 200 ) )['stricter_checked'] ?? null ) === true );
chk( 'at_cap server-*: checked', ( classify( at_cap: hdr( 'server-per-group', $h ) )['stricter_checked'] ?? null ) === true );
chk( 'no patterns: nothing to check', ( classify( inputs: with( [ 'strict' => false ] ) )['stricter_checked'] ?? null ) === false );

// Ruling R19: a header-less 404/410 may come from an unrouted base, so on
// LiteSpeed it is only a suspicion - never the red alarm.
$r = classify( at_cap: resp( 410 ), software: 'LiteSpeed' );
chk( 'LiteSpeed at_cap 410 without header: suspected, not the alarm',
	$r['stricter'] === false && ( $r['stricter_suspected'] ?? null ) === true && ( $r['stricter_checked'] ?? null ) === true, json_encode( $r ) );
chk( 'LiteSpeed suspicion: hedged detail', stripos( $r['detail'], 'may' ) !== false && stripos( $r['detail'], 'Alarm' ) === false, $r['detail'] );
chk( 'LiteSpeed at_cap 404 without header: suspected', ( classify( at_cap: resp( 404 ), software: 'LiteSpeed' )['stricter_suspected'] ?? null ) === true );
chk( 'LiteSpeed at_cap 301: no suspicion', ( classify( at_cap: plugin_301(), software: 'LiteSpeed' )['stricter_suspected'] ?? null ) === false );
chk( 'nginx at_cap 410 without header = no alarm, no suspicion', ( $x = classify( at_cap: resp( 410 ) ) )['stricter'] === false && ( $x['stricter_suspected'] ?? null ) === false );
chk( 'at_cap server-*: the alarm, not a suspicion', ( classify( at_cap: hdr( 'server-per-group', $h ) )['stricter_suspected'] ?? null ) === false );
$r = classify( at_cap: hdr( 'server-per-group', $h ) );
chk( 'stricter: the alarm is in the detail', stripos( $r['detail'], 'stricter' ) !== false, $r['detail'] );

// =====================================================================================
section( 'classify(): robots.txt' );
// =====================================================================================

chk( 'robots body with plugin line = ok',      classify()['robots'] === 'ok' );
chk( 'robots body without plugin line = not-wordpress', classify( robots: resp( 200, [], "User-agent: *\nDisallow:\n" ) )['robots'] === 'not-wordpress' );
chk( 'robots 404 = not-wordpress',             classify( robots: resp( 404, [], 'Not Found' ) )['robots'] === 'not-wordpress' );
chk( 'robots lines off in the plugin = disabled', classify( robots: resp( 200, [], "User-agent: *\nDisallow:\n" ), robots_expected: false )['robots'] === 'disabled' );
chk( 'robots timeout = unreachable',           classify( robots: err( 'cURL error 28' ) )['robots'] === 'unreachable' );
chk( 'robots 401 = unreachable',               classify( robots: resp( 401 ) )['robots'] === 'unreachable' );
chk( 'robots 503 = unreachable',               classify( robots: resp( 503 ) )['robots'] === 'unreachable' );
chk( 'robots: other fragment segment', classify( robots: resp( 200, [], "Disallow: /*frag\n" ), inputs: with( [ 'fragment' => 'frag' ] ) )['robots'] === 'ok' );

// =====================================================================================
section( 'is_looser()' );
// =====================================================================================

$looser = static fn( array $a, array $b ): mixed => call( 'jpkcom_postfilter_server_rules_is_looser', with( $a ), with( $b ) );
$n2     = [ 'max_per_group' => 2 ];
$n3     = [ 'max_per_group' => 3 ];
chk( 'looser: N 2->3',                         $looser( $n2, $n3 ) === true );
chk( 'not looser: N 3->2',                     $looser( $n3, $n2 ) === false );
chk( 'looser: N 2->0 (unlimited)',             $looser( $n2, [ 'max_per_group' => 0 ] ) === true );
chk( 'not looser: N 0->2',                     $looser( [ 'max_per_group' => 0 ], $n2 ) === false );
chk( 'looser: group added',                    $looser( [], [ 'groups' => 3 ] ) === true );
chk( 'not looser: group removed',              $looser( [ 'groups' => 3 ], [] ) === false );
chk( 'looser: position switched to no taxonomy', $looser( [], [ 'skip_positions' => [ 1 ] ] ) === true );
chk( 'looser: strict off',                     $looser( [], [ 'strict' => false ] ) === true );
chk( 'not looser: strict on',                  $looser( [ 'strict' => false ], [] ) === false );
chk( 'looser: endpoint change',                $looser( [], [ 'endpoint' => 'auswahl' ] ) === true );
chk( 'looser: another base',                   $looser( [], [ 'bases' => [ '/blog/', '/jobs/' ] ] ) === true );
chk( 'looser: verdict filtered',               $looser( [], [ 'verdict_filtered' => true ] ) === true );
chk( 'not looser: unchanged',                  $looser( [], [] ) === false );
chk( 'not looser: unchanged without groups',   $looser( [ 'groups' => 0 ], [ 'groups' => 0 ] ) === false );
chk( 'not looser: same sets in another order', call( 'jpkcom_postfilter_server_rules_is_looser',
	with( [ 'bases' => [ '/jobs/', '/blog/' ], 'skip_positions' => [ 1, 0 ], 'groups' => 3 ] ),
	with( [ 'bases' => [ '/blog/', '/jobs/' ], 'skip_positions' => [ 0, 1 ], 'groups' => 3 ] ) ) === false );
chk( 'not looser: reject status only (410 -> 404, ruling R20)', $looser( [], [ 'reject_status' => 404 ] ) === false );
chk( 'not looser: reject status only (404 -> 410)', $looser( [ 'reject_status' => 404 ], [] ) === false );
chk( 'still looser: reject status and N together', $looser( [], [ 'reject_status' => 404, 'max_per_group' => 3 ] ) === true );
chk( 'hash is ignored', call( 'jpkcom_postfilter_server_rules_is_looser', array_merge( with( [] ), [ 'hash' => 'x' ] ), array_merge( with( [] ), [ 'hash' => 'y' ] ) ) === false );

// =====================================================================================
section( 'run()' );
// =====================================================================================

if ( $have && function_exists( 'jpkcom_postfilter_server_check_run' ) ) {
	$inputs = site( general() );
	$GLOBALS['__http']      = [];
	$GLOBALS['__responder'] = static function ( string $method, string $url ) use ( $inputs ) {
		if ( str_ends_with( $url, '/robots.txt' ) ) {
			return http( 200, [ 'Content-Type' => 'text/plain' ], robots_body() );
		}
		if ( str_contains( $url, 'jpkpf-probe-3' ) ) {
			return http( 410, [ 'X-Jpkpf-Reject' => 'server-per-group', 'X-Jpkpf-Rule' => $inputs['hash'] ] );
		}
		return http( 301, [ 'Location' => 'https://example.test/blog/', 'X-Redirect-By' => 'jpkcom-post-filter' ] );
	};
	$before = time();
	$out    = jpkcom_postfilter_server_check_run();
	$stored = get_option( 'jpkcom_postfilter_server_rules_check' );

	chk( 'run: three probes, robots by GET, rule probes by HEAD',
		array_map( static fn( $c ) => $c[0], $GLOBALS['__http'] ) === [ 'GET', 'HEAD', 'HEAD' ], json_encode( $GLOBALS['__http'] ) );
	$only_home = true;
	$args_ok   = true;
	foreach ( $GLOBALS['__http'] as [ $m, $u, $a ] ) {
		$only_home = $only_home && str_starts_with( $u, 'https://example.test/' );
		$args_ok   = $args_ok && ( $a['timeout'] ?? null ) === 5 && ( $a['redirection'] ?? null ) === 0
			&& ( $a['cookies'] ?? null ) === [] && ! isset( $a['headers']['Cookie'] ) && ( $a['sslverify'] ?? null ) === false;
	}
	chk( 'run: only URLs of home_url()', $only_home );
	chk( 'run: timeout 5, no redirects, no cookies, sslverify as core\'s loopbacks (https_local_ssl_verify, default false)', $args_ok, json_encode( $GLOBALS['__http'][0][2] ?? null ) );
	chk( 'run: classified', ( $out['result']['rule'] ?? null ) === 'active' && ( $out['result']['robots'] ?? null ) === 'ok' && ( $out['result']['stricter'] ?? null ) === false, json_encode( $out ) );
	chk( 'run: the plugin\'s 301 on the at-cap probe (X-Redirect-By kept through the header loop) = checked', ( $out['result']['stricter_checked'] ?? null ) === true, json_encode( $out ) );
	chk( 'run: {time, result} stored', is_array( $stored ) && ( $stored['time'] ?? 0 ) >= $before && ( $stored['result'] ?? null ) === ( $out['result'] ?? false ), json_encode( $stored ) );
	chk( 'run: option not autoloaded', array_key_exists( 'jpkcom_postfilter_server_rules_check', $GLOBALS['__autoload'] ) && $GLOBALS['__autoload']['jpkcom_postfilter_server_rules_check'] === false );

	$GLOBALS['__http']      = [];
	$GLOBALS['__responder'] = static fn( string $method, string $url ) => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	$out = jpkcom_postfilter_server_check_run();
	chk( 'run: loopback blocked = unreachable everywhere, never missing',
		( $out['result']['rule'] ?? null ) === 'unreachable' && ( $out['result']['robots'] ?? null ) === 'unreachable'
		&& str_contains( (string) ( $out['result']['detail'] ?? '' ), 'cURL error 28' ), json_encode( $out ) );
	chk( 'run: blocked at_cap probe = stricter not checked', ( $out['result']['stricter_checked'] ?? null ) === false );

	$verify = static fn( $v ) => true;
	add_filter( 'https_local_ssl_verify', $verify );
	$GLOBALS['__http'] = [];
	jpkcom_postfilter_server_check_run();
	chk( 'run: https_local_ssl_verify decides sslverify', ( $GLOBALS['__http'][0][2]['sslverify'] ?? null ) === true );
	remove_filter( 'https_local_ssl_verify', $verify );

	site( general( [ 'strict_urls' => false ] ) );
	$GLOBALS['__http']      = [];
	$GLOBALS['__responder'] = static fn( string $method, string $url ) => http( 200, [], robots_body() );
	$out = jpkcom_postfilter_server_check_run();
	chk( 'run: no patterns = no rule probes, rule none', count( $GLOBALS['__http'] ) === 1 && ( $out['result']['rule'] ?? null ) === 'none', json_encode( $GLOBALS['__http'] ) );
	$GLOBALS['__responder'] = null;
} else {
	chk( 'run() exists', false );
}

// =====================================================================================
section( 'Notice after a loosening save: hooks' );
// =====================================================================================

foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
	$short = substr( $o, strlen( 'jpkcom_postfilter_' ) );
	$p     = has_filter( "sanitize_option_{$o}", 'jpkcom_postfilter_server_check_capture_before' );
	chk( "sanitize_option_{$short}: captures the state before the sanitizer writes the cache file", is_int( $p ) && $p < 10, var_export( $p, true ) );
	chk( "update_option_{$short} / add_option_{$short}: compares after the commit",
		has_action( "update_option_{$o}", 'jpkcom_postfilter_server_check_after_update' ) !== false
		&& has_action( "add_option_{$o}", 'jpkcom_postfilter_server_check_after_add' ) !== false );
}
chk( 'admin_notices: shows the notice', has_action( 'admin_notices', 'jpkcom_postfilter_server_check_notice' ) !== false );

$GLOBALS['__fired'] = [];
foreach ( [ 'update', 'add' ] as $kind ) {
	add_action( "{$kind}_option_jpkcom_postfilter_general", static function () use ( $kind ) {
		$GLOBALS['__fired'][] = $kind;
	}, 99, 0 );
}

// =====================================================================================
section( 'Notice after a loosening save: General' );
// =====================================================================================

if ( $have ) {
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'N 2->3: notice set for the saving user', noticed() );
	chk( 'notice transient lives 60 s', ( $GLOBALS['__transients']['jpkcom_postfilter_loosened_7'][1] ?? null ) === 60 );

	// general() equals the registered default, so the save above went
	// update_option() -> add_option() (core overwrites a stored default). A
	// stored value that differs from the default takes the update path.
	site( general( [ 'max_filter_combos' => 4 ] ) );
	$GLOBALS['__fired'] = [];
	save( 'jpkcom_postfilter_general', general( [ 'max_filter_combos' => 4, 'max_filters_per_group' => '3' ] ) );
	chk( 'N 2->3 on the update_option path: notice', $GLOBALS['__fired'] === [ 'update' ] && noticed(), json_encode( $GLOBALS['__fired'] ) );
	site( general( [ 'max_filter_combos' => 4, 'max_filters_per_group' => 3 ] ) );
	$GLOBALS['__fired'] = [];
	save( 'jpkcom_postfilter_general', general( [ 'max_filter_combos' => 4, 'max_filters_per_group' => '2' ] ) );
	chk( 'N 3->2 on the update_option path: no notice', $GLOBALS['__fired'] === [ 'update' ] && ! noticed(), json_encode( $GLOBALS['__fired'] ) );

	site( general( [ 'max_filters_per_group' => 3 ] ) );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	chk( 'N 3->2: no notice', ! noticed() );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'strict_urls' => '0' ] ) );
	chk( 'strict off: notice', noticed() );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'url_endpoint' => 'auswahl' ] ) );
	chk( 'endpoint change: notice', noticed() );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'filter_link_mode' => 'combo' ] ) );
	chk( 'a setting the rule does not read: no notice', ! noticed() );

	site( general() );
	save( 'jpkcom_postfilter_general', general() );
	chk( 'unchanged save: no notice', ! noticed() );

	// First-ever save: update_option() -> add_option(), sanitized twice; the
	// second sanitize runs after the cache file already holds the new values.
	site( null );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'first-ever save (add_option path), default N 2 -> 3: notice', noticed() );

	site( null );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '1' ] ) );
	chk( 'first-ever save, default N 2 -> 1: no notice', ! noticed() );

	site( general() );
	$GLOBALS['__user'] = 0;
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'no user (cron, CLI): no transient', $GLOBALS['__transients'] === [] );
	$GLOBALS['__user'] = 7;

	// Two saves in one request: the second compares against the first one's result.
	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	delete_transient( 'jpkcom_postfilter_loosened_7' );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	chk( 'second save in the same request tightens: no new notice', ! noticed() );
}

// =====================================================================================
section( 'Notice after a loosening save: Filter Groups (review focus 5)' );
// =====================================================================================

if ( $have ) {
	site( general() );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] );
	chk( 'group added: notice', noticed() );

	$three = [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ];
	site( general(), $three );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'color', 2 ) ] ] );
	chk( 'group removed: no notice', ! noticed() );

	site( general() );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1, [ 'post_types' => [ 'jpk_job' ] ] ) ] ] );
	chk( 'group adds a post type with another archive: notice', noticed() );

	site( general() );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0, [ 'label' => 'Topics' ] ), group( 'post_tag', 1 ) ] ] );
	chk( 'label changed: no notice', ! noticed() );
}

// =====================================================================================
section( 'Notice: import (Import / Export page)' );
// =====================================================================================

if ( $have && function_exists( 'jpkcom_postfilter_server_check_capture_before' ) ) {
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';

	// What jpkcom_postfilter_page_import_export() does per group: loosen a
	// managed block, (capture,) run the sanitizer - whose side effect is that
	// the settings cache file holds the imported values from then on - and
	// only then jpkcom_postfilter_settings_save() -> update_option().
	$import = static function ( string $group, array $raw, bool $capture ): void {
		$option = 'jpkcom_postfilter_' . $group;
		jpkcom_postfilter_htaccess_prepare_save( option: $option, raw: $raw, setting: 'jpkcom_postfilter_import_messages' );
		if ( $capture ) {
			jpkcom_postfilter_server_check_capture_before( value: $raw, option: $option );
		}
		jpkcom_postfilter_settings_write_file( $group, $raw );   // the sanitizer's cache write
		jpkcom_postfilter_settings_memo_reset();
		jpkcom_postfilter_settings_save( $group, $raw );
	};

	site( general() );
	$import( 'general', general( [ 'max_filters_per_group' => 3 ] ), false );
	chk( 'import without a capture before the sanitizer: the loosening is not seen (why the call site matters)', ! noticed() );

	site( general() );
	$import( 'general', general( [ 'max_filters_per_group' => 3 ] ), true );
	chk( 'import N 2->3 with the capture: notice', noticed() );

	site( general() );
	$import( 'filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ], true );
	chk( 'import adding a group: notice', noticed() );

	site( general() );
	$import( 'general', general( [ 'max_filters_per_group' => 1 ] ), true );
	chk( 'import N 2->1: no notice', ! noticed() );

	site( general() );
	jpkcom_postfilter_server_check_capture_before( value: [], option: 'jpkcom_postfilter_layout' );
	chk( 'capture ignores options that are not General or Filter Groups', call( 'jpkcom_postfilter_server_check_pending', 'jpkcom_postfilter_layout' ) === null );

	$src        = (string) file_get_contents( __DIR__ . '/../includes/admin-pages.php' );
	$at_capture = strpos( $src, 'jpkcom_postfilter_server_check_capture_before(' );
	$at_cb      = strpos( $src, '$cb( $data[ $group ] )' );
	chk( 'admin-pages.php import: captures before it runs the sanitizer', $at_capture !== false && $at_cb !== false && $at_capture < $at_cb );

	// D1 / fix wave item 4: the import runs inside the page render, after
	// admin_notices has fired. The page prints the notice itself, right after
	// the import, so it shows on the import result page - not on whatever page
	// loads next within 60 seconds, or never.
	$at_save    = strpos( $src, 'jpkcom_postfilter_settings_save( $group, $sanitized );' );
	$at_notice  = strpos( $src, 'jpkcom_postfilter_server_check_notice()', (int) $at_save );
	$at_errors  = strpos( $src, "settings_errors( 'jpkcom_postfilter_import_messages' );" );
	chk( 'admin-pages.php import: prints the server rules notice after the import, before the page\'s messages',
		$at_save !== false && $at_notice !== false && $at_errors !== false && $at_save < $at_notice && $at_notice < $at_errors );

	site( general() );
	$import( 'general', general( [ 'max_filters_per_group' => 3 ] ), true );
	ob_start();
	jpkcom_postfilter_server_check_notice();   // what the import page calls
	$html = (string) ob_get_clean();
	chk( 'import N 2->3: the notice is printed in the same request', str_contains( $html, 'update them now' ), $html );
	chk( 'import N 2->3: shown once - the next admin page does not repeat it', ! noticed() && ! str_contains( notice_html(), 'update them now' ) );
} else {
	chk( 'import: capture_before exists', false );
}

// =====================================================================================
section( 'Notice: the 1.6.0 migration is not a save' );
// =====================================================================================

if ( $have ) {
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';

	// Stored without the key: the effective N is the new default 2 until the pin.
	$stored = general();
	unset( $stored['max_filters_per_group'] );
	site( $stored );
	$GLOBALS['__options']['jpkcom_postfilter_rewrite_version'] = '1.5.0';
	$wrote = call( 'jpkcom_postfilter_migrate_pin_per_group_cap' );
	chk( 'fixture: the migration pins 3', $wrote === true && ( get_option( 'jpkcom_postfilter_general' )['max_filters_per_group'] ?? null ) === 3 );
	chk( 'migration pin (2 -> 3): no notice', ! noticed() );

	save( 'jpkcom_postfilter_general', array_merge( get_option( 'jpkcom_postfilter_general' ), [ 'max_filters_per_group' => '4' ] ) );
	chk( 'a real save after the migration: notice again', noticed() );
}

// =====================================================================================
section( 'Notice: managed .htaccess' );
// =====================================================================================

if ( $have ) {
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
	site( general(), null, true );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'notice suppressed when htaccess managed', ! noticed() && $GLOBALS['__errors'] === [], json_encode( $GLOBALS['__errors'] ) );

	site( general(), null, true );
	save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] );
	chk( 'notice suppressed when htaccess managed (Filter Groups)', ! noticed() );

	// Managed, but the server does not read .htaccess (site moved to nginx):
	// nothing keeps the rules the admin deployed elsewhere in step.
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	site( general(), null, true );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'managed option on a server that ignores .htaccess: notice', noticed() );
}

// =====================================================================================
section( 'Notice: output' );
// =====================================================================================

if ( $have ) {
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';

	site( general() );
	chk( 'nothing without the transient', trim( notice_html() ) === '' );

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$html = notice_html();
	chk( 'never checked: warning, not red', str_contains( $html, 'notice-warning' ) && ! str_contains( $html, 'notice-error' ), $html );
	chk( 'links to the Server Rules page', str_contains( $html, 'admin.php?page=jpkcom-postfilter-server-rules' ), $html );
	chk( 'shown once', trim( notice_html() ) === '' );

	foreach ( [ 'active' => true, 'stale' => true, 'missing' => false, 'unknown' => false, 'unreachable' => false, 'none' => false ] as $rule => $red ) {
		site( general() );
		$GLOBALS['__options']['jpkcom_postfilter_server_rules_check'] = [ 'time' => time() - 60, 'result' => [ 'robots' => 'ok', 'rule' => $rule, 'stricter' => false, 'detail' => '' ] ];
		save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		$html = notice_html();
		chk( "last check {$rule}: " . ( $red ? 'red' : 'not red' ), $html !== '' && str_contains( $html, 'notice-error' ) === $red, $html );
	}

	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$GLOBALS['__can'] = false;
	chk( 'not for users without manage_options', trim( notice_html() ) === '' );
	$GLOBALS['__can'] = true;

	// The block was set up between the save and the next page load.
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
	site( general() );
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = '1';
	chk( 'managed by the time it would show: no notice', trim( notice_html() ) === '' );
}

exit( summary() );
