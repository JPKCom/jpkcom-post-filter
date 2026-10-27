<?php
/**
 * Tests for the Server Rules admin page (1.6.0, spec 6): the pure helpers
 * behind it, the .htaccess set-up/remove action, the admin-post guards and
 * the markup of every tab for one fixed input set.
 *
 * The page itself cannot run without WordPress; its render functions are
 * thin and print what the helpers decide. Rendering them with the stubs
 * below still proves the one thing a page full of config text must get
 * right: snippet code is escaped (a server snippet contains `<IfModule`,
 * `<!doctype html>` and quotes), and so is the status detail, which carries
 * header values from the probe responses.
 *
 * Run with:
 *
 *     php tests/test-server-rules-admin.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.6.0
 */

declare(strict_types=1);

$GLOBALS['__tmp'] = sys_get_temp_dir() . '/jpkpf-server-admin-' . getmypid();
@mkdir( $GLOBALS['__tmp'] . '/abspath', 0755, true );
@mkdir( $GLOBALS['__tmp'] . '/home', 0755, true );

define( 'ABSPATH', $GLOBALS['__tmp'] . '/abspath/' );
define( 'JPKCOM_POSTFILTER_VERSION', '1.6.0-test' );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'JPKCOM_POSTFILTER_PLUGIN_URL', 'https://example.test/wp-content/plugins/jpkcom-post-filter/' );

$GLOBALS['__home_path'] = $GLOBALS['__tmp'] . '/home/';

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

// --- hooks -------------------------------------------------------------------------------

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

// --- options -------------------------------------------------------------------------------

$GLOBALS['__options']    = [];
$GLOBALS['__autoload']   = [];
$GLOBALS['__registered'] = [];

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function register_setting( $option_group, $option_name, $args = [] ) {
	$GLOBALS['__registered'][ $option_name ] = [ 'group' => $option_group, 'args' => $args ];
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {}
function settings_errors( $setting = '' ) {}
function get_settings_errors( $setting = '' ) { return []; }

// --- user, nonce, redirect ----------------------------------------------------------------

$GLOBALS['__can']       = true;
$GLOBALS['__referer']   = [];
$GLOBALS['__redirects'] = [];

class Jpkpf_Test_Die extends Exception {}
class Jpkpf_Test_Redirect extends Exception {}

function current_user_can( $cap ) { return $GLOBALS['__can'] && $cap === 'manage_options'; }
function get_current_user_id() { return 7; }
function check_admin_referer( $action = -1, $name = '_wpnonce' ) { $GLOBALS['__referer'][] = $action; return 1; }
function wp_die( $message = '', $title = '', $args = [] ) { throw new Jpkpf_Test_Die( (string) $message ); }
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['__redirects'][] = $location;
	throw new Jpkpf_Test_Redirect( (string) $location );
}
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$html = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
	if ( $display ) {
		echo $html;
	}
	return $html;
}
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	echo '<input type="submit" class="button button-' . esc_attr( (string) $type ) . '" value="' . esc_attr( (string) ( $text ?? 'Save Changes' ) ) . '">';
}
function selected( $a, $b = true, $display = true ) {
	$r = (string) $a === (string) $b ? ' selected="selected"' : '';
	if ( $display ) {
		echo $r;
	}
	return $r;
}
function add_query_arg( ...$args ) {
	$params = is_array( $args[0] ) ? $args[0] : [ $args[0] => $args[1] ];
	$url    = is_array( $args[0] ) ? (string) ( $args[1] ?? '' ) : (string) ( $args[2] ?? '' );
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
}

// --- other WordPress stand-ins ---------------------------------------------------------

function __( $s, $d = '' ) { return $s; }
function _n( $single, $plural, $n, $d = '' ) { return $n === 1 ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function get_admin_page_title() { return 'Server Rules'; }
function get_bloginfo( $show = '' ) { return $show === 'language' ? 'en-US' : ''; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_date( $format, $ts = null ) { return gmdate( (string) $format, (int) $ts ); }
function is_wp_error( $t ) { return false; }
$GLOBALS['__home'] = 'https://example.test';
function home_url( $path = '' ) { return $GLOBALS['__home'] . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
$GLOBALS['__multisite'] = false;
function is_multisite() { return $GLOBALS['__multisite']; }
function get_current_blog_id() { return 1; }
function get_home_path() { return $GLOBALS['__home_path']; }
function jpkcom_postfilter_settings_memo_reset(): void {}
function jpkcom_postfilter_fragment_segment(): string { return 'jpkpf-fragment'; }
function jpkcom_postfilter_debug_log( $msg, $ctx = [] ) {}
// Not used by the page helpers: the duplicate-taxonomy warning takes the
// taxonomies as an argument. Present so a live-settings read would show.
function jpkcom_postfilter_get_filter_groups_enabled(): array { throw new RuntimeException( 'live settings read' ); }

$GLOBALS['__submenus'] = [];
function add_submenu_page( ...$args ) {
	$GLOBALS['__submenus'][] = $args;
	return 'post-filter_page_' . ( $args['menu_slug'] ?? '' );
}

// --- the plugin -------------------------------------------------------------------------

foreach ( [ 'url-policy.php', 'server-rules.php', 'server-rules-render.php', 'htaccess-manager.php', 'htaccess-sync.php', 'server-rules-check.php', 'server-rules-admin.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

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

/** Output of a render function, '' when it does not exist yet. */
function render( string $fn, mixed ...$args ): string {
	if ( ! function_exists( $fn ) ) {
		return '';
	}
	ob_start();
	$fn( ...$args );
	return (string) ob_get_clean();
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

/** Inputs as jpkcom_postfilter_server_rule_inputs() returns them, without WordPress. */
function inputs( array $over = [] ): array {
	$inputs = array_merge( [
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
	], $over );
	$inputs['hash'] = jpkcom_postfilter_server_rule_hash( inputs: $inputs );
	return $inputs;
}

/** The warning with this id, or null. */
function warning( array $warnings, string $id ): ?array {
	foreach ( $warnings as $w ) {
		if ( ( $w['id'] ?? '' ) === $id ) {
			return $w;
		}
	}
	return null;
}

function warnings( array $inputs ): array {
	$w = call( 'jpkcom_postfilter_server_rules_warnings', $inputs );
	return is_array( $w ) ? $w : [];
}

function htaccess(): string { return $GLOBALS['__home_path'] . '.htaccess'; }

/**
 * An in-memory .htaccess whose writes can fail (T8-a): point the home path at
 * `jpkpffail://home/` and put the content into $files. Writes follow $plan,
 * one entry per stream_write() call: 'half' writes the first half of the
 * data, 'fail' writes nothing; once the list is used up every write succeeds.
 * PHP's stream layer retries a short write within the same fwrite(), so a
 * write that fails half way and stays failed is [ 'half', 'fail', 'fail' ],
 * and one whose rollback fails too needs one 'fail' more.
 */
final class Jpkpf_Failing_Stream {
	public static array $files = [];
	public static array $plan  = [];
	public $context;
	private string $path = '';
	private int $pos = 0;

	public function stream_open( $path, $mode, $options, &$opened ): bool {
		if ( ! isset( self::$files[ $path ] ) ) {
			return false;
		}
		$this->path = $path;
		$this->pos  = 0;
		return true;
	}
	public function stream_read( $count ) {
		$out        = substr( self::$files[ $this->path ], $this->pos, $count );
		$this->pos += strlen( $out );
		return $out;
	}
	public function stream_write( $data ): int {
		$step = array_shift( self::$plan ) ?? 'full';
		if ( $step === 'fail' ) {
			return 0;
		}
		if ( $step === 'half' ) {
			$data = substr( $data, 0, intdiv( strlen( $data ), 2 ) );
		}
		$f                          = self::$files[ $this->path ];
		self::$files[ $this->path ] = str_pad( substr( $f, 0, $this->pos ), $this->pos, "\0" ) . $data . (string) substr( $f, $this->pos + strlen( $data ) );
		$this->pos                 += strlen( $data );
		return strlen( $data );
	}
	public function stream_truncate( int $size ): bool {
		self::$files[ $this->path ] = substr( self::$files[ $this->path ], 0, $size );
		return true;
	}
	public function stream_eof(): bool { return $this->pos >= strlen( self::$files[ $this->path ] ); }
	public function stream_tell(): int { return $this->pos; }
	public function stream_seek( $offset, $whence ): bool { $this->pos = $whence === SEEK_SET ? (int) $offset : $this->pos; return true; }
	public function stream_flush(): bool { return true; }
	public function stream_lock( $operation ): bool { return true; }
	public function stream_close(): void {}
	// 0666: is_writable()/is_readable() read the "other" bits for a wrapper without an owner.
	public function stream_stat(): array { return [ 'mode' => 0100666, 'size' => strlen( self::$files[ $this->path ] ) ]; }
	public function url_stat( $path, $flags ) { return isset( self::$files[ $path ] ) ? [ 'mode' => 0100666, 'size' => strlen( self::$files[ $path ] ) ] : false; }
	public function stream_metadata( $path, $option, $value ): bool { return false; }
	public function stream_set_option( $option, $arg1, $arg2 ): bool { return false; }
}
stream_wrapper_register( 'jpkpffail', Jpkpf_Failing_Stream::class );

$GLOBALS['__wp_block'] = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";


// =============================================================================
section( 'Server detection (SERVER_SOFTWARE)' );
// =============================================================================

chk( 'detect nginx',         call( 'jpkcom_postfilter_detect_server', 'nginx/1.26.3' ) === 'nginx' );
chk( 'detect apache',        call( 'jpkcom_postfilter_detect_server', 'Apache/2.4.69 (Debian)' ) === 'apache' );
chk( 'detect bare Apache',   call( 'jpkcom_postfilter_detect_server', 'Apache' ) === 'apache' );
chk( 'detect litespeed',     call( 'jpkcom_postfilter_detect_server', 'LiteSpeed' ) === 'ols' );
chk( 'detect caddy/franken', call( 'jpkcom_postfilter_detect_server', 'Caddy/2.11.7' ) === 'caddy' && call( 'jpkcom_postfilter_detect_server', 'FrankenPHP' ) === 'caddy' );
chk( 'detect ferron',        call( 'jpkcom_postfilter_detect_server', 'Ferron' ) === 'ferron3' );
chk( 'detect unknown',       call( 'jpkcom_postfilter_detect_server', '' ) === '' );
chk( 'detect IIS: unknown',  call( 'jpkcom_postfilter_detect_server', 'Microsoft-IIS/10.0' ) === '' );
chk( 'detect is case-insensitive', call( 'jpkcom_postfilter_detect_server', 'NGINX' ) === 'nginx' && call( 'jpkcom_postfilter_detect_server', 'litespeed' ) === 'ols' );


// =============================================================================
section( 'Tabs: order and preselection' );
// =============================================================================

$tabs = call( 'jpkcom_postfilter_server_rules_tabs' );
chk( 'tab order nginx, apache, htaccess, caddy, ferron3, ferron2, ols',
	is_array( $tabs ) && array_keys( $tabs ) === [ 'nginx', 'apache', 'htaccess', 'caddy', 'ferron3', 'ferron2', 'ols' ] );
chk( 'every tab has a label', is_array( $tabs ) && count( array_filter( $tabs, static fn( $l ) => is_string( $l ) && $l !== '' ) ) === 7 );
chk( 'preselect: nginx -> nginx',     call( 'jpkcom_postfilter_server_rules_initial_tab', 'nginx' ) === 'nginx' );
chk( 'preselect: apache -> htaccess', call( 'jpkcom_postfilter_server_rules_initial_tab', 'apache' ) === 'htaccess' );
chk( 'preselect: ols, caddy, ferron3 -> own tab',
	call( 'jpkcom_postfilter_server_rules_initial_tab', 'ols' ) === 'ols'
	&& call( 'jpkcom_postfilter_server_rules_initial_tab', 'caddy' ) === 'caddy'
	&& call( 'jpkcom_postfilter_server_rules_initial_tab', 'ferron3' ) === 'ferron3' );
chk( 'preselect: unknown -> first tab', call( 'jpkcom_postfilter_server_rules_initial_tab', '' ) === 'nginx' );


// =============================================================================
section( 'Option jpkcom_postfilter_server_rules: sanitizer clamps like jpkcom_postfilter_rate_limits()' );
// =============================================================================

$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', [ 'workers' => '12', 'render_seconds' => '0.8', 'nginx_variant' => 'ispconfig' ] );
chk( 'valid values kept', $s === [ 'workers' => 12, 'render_seconds' => 0.8, 'nginx_variant' => 'ispconfig' ], var_export( $s, true ) );
$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', 'garbage' );
chk( 'non-array -> defaults 8 / 0.4 / \'\'', $s === [ 'workers' => 8, 'render_seconds' => 0.4, 'nginx_variant' => '' ], var_export( $s, true ) );
$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', [ 'workers' => '0', 'render_seconds' => '0', 'nginx_variant' => 'apache' ] );
chk( 'low values clamp to 1 / 0.05, unknown variant -> \'\'', is_array( $s ) && $s['workers'] === 1 && $s['render_seconds'] === 0.05 && $s['nginx_variant'] === '', var_export( $s, true ) );
$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', [ 'workers' => 9999, 'render_seconds' => 99 ] );
chk( 'high values clamp to 256 / 10', is_array( $s ) && $s['workers'] === 256 && $s['render_seconds'] === 10.0, var_export( $s, true ) );
$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', [ 'workers' => 'abc', 'render_seconds' => '0,5' ] );
chk( 'decimal comma accepted, non-numeric workers -> default', is_array( $s ) && $s['render_seconds'] === 0.5 && $s['workers'] === 8, var_export( $s, true ) );
$s = call( 'jpkcom_postfilter_server_rules_sanitize_options', [ 'render_seconds' => 'NAN' ] );
chk( 'NAN render time -> default', is_array( $s ) && $s['render_seconds'] === 0.4, var_export( $s, true ) );

$GLOBALS['__options']['jpkcom_postfilter_server_rules'] = [ 'workers' => 500, 'render_seconds' => 'x' ];
$o = call( 'jpkcom_postfilter_server_rules_options' );
chk( 'stored option read through the sanitizer', is_array( $o ) && $o['workers'] === 256 && $o['render_seconds'] === 0.4 );
unset( $GLOBALS['__options']['jpkcom_postfilter_server_rules'] );
$o = call( 'jpkcom_postfilter_server_rules_options' );
chk( 'missing option -> defaults', $o === [ 'workers' => 8, 'render_seconds' => 0.4, 'nginx_variant' => '' ] );

do_action( 'admin_init' );
$reg = $GLOBALS['__registered']['jpkcom_postfilter_server_rules'] ?? null;
chk( 'register_setting on admin_init, own group, own sanitizer',
	is_array( $reg ) && $reg['group'] === 'jpkcom_postfilter_server_rules'
	&& ( $reg['args']['sanitize_callback'] ?? '' ) === 'jpkcom_postfilter_server_rules_sanitize_options' );
chk( 'not autoloaded (wp_default_autoload_value)',
	apply_filters( 'wp_default_autoload_value', null, 'jpkcom_postfilter_server_rules', [], '' ) === false
	&& apply_filters( 'wp_default_autoload_value', null, 'some_other_option', [], '' ) === null );


// =============================================================================
section( 'Warnings (spec 6)' );
// =============================================================================

$base = warnings( inputs() );
chk( 'proxy/CDN hint is always there, as info',      ( warning( $base, 'proxy' )['level'] ?? '' ) === 'info' );
chk( 'foreign top rewrite hint always there, as info', ( warning( $base, 'top-rewrite' )['level'] ?? '' ) === 'info' );
chk( 'clean inputs: no warning or error level',       count( array_filter( $base, static fn( $w ) => $w['level'] !== 'info' ) ) === 0, implode( ' | ', array_column( $base, 'id' ) ) );
chk( 'every warning has level, text, id',
	$base !== [] && count( array_filter( $base, static fn( $w ) => in_array( $w['level'] ?? '', [ 'info', 'warning', 'error' ], true ) && is_string( $w['text'] ?? null ) && $w['text'] !== '' ) ) === count( $base ) );

file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\nDisallow:\n" );
$w = warning( warnings( inputs() ), 'robots-file' );
chk( 'warning: physical robots.txt', ( $w['level'] ?? '' ) === 'warning' );
chk( 'robots.txt in the WordPress directory: says so, with the path', str_contains( $w['text'] ?? '', 'WordPress directory' ) && str_contains( $w['text'] ?? '', ABSPATH . 'robots.txt' ) && ! str_contains( $w['text'] ?? '', 'site root' ) );
unlink( ABSPATH . 'robots.txt' );
chk( 'no robots.txt file: no warning', warning( warnings( inputs() ), 'robots-file' ) === null );
file_put_contents( $GLOBALS['__home_path'] . 'robots.txt', "User-agent: *\n" );
$w = warning( warnings( inputs() ), 'robots-file' );
chk( 'warning: physical robots.txt in the site root (get_home_path), says so', $w !== null && str_contains( $w['text'], 'site root' ) && ! str_contains( $w['text'], 'WordPress directory' ) );
unlink( $GLOBALS['__home_path'] . 'robots.txt' );

$GLOBALS['__home'] = 'https://example.test/wp';
$w = warning( warnings( inputs() ), 'subdirectory' );
chk( 'warning: subdirectory home', ( $w['level'] ?? '' ) === 'warning' && str_contains( $w['text'] ?? '', '/wp/' ) );
$GLOBALS['__home'] = 'https://example.test/';
chk( 'home at the root (with slash): no subdirectory warning', warning( warnings( inputs() ), 'subdirectory' ) === null );
$GLOBALS['__home'] = 'https://example.test';

$GLOBALS['__options']['blog_public'] = '0';
chk( 'warning: blog_public 0', warning( warnings( inputs() ), 'blog-public' ) !== null );
$GLOBALS['__options']['blog_public'] = '1';
chk( 'blog_public 1: no warning', warning( warnings( inputs() ), 'blog-public' ) === null );
unset( $GLOBALS['__options']['blog_public'] );

$w = warning( warnings( inputs( [ 'strict' => false ] ) ), 'strict-off' );
chk( 'warning: strict URLs off', ( $w['level'] ?? '' ) === 'warning' );
chk( 'strict on: no strict-off warning', warning( $base, 'strict-off' ) === null );

chk( 'info: N = 0 (no per-group rule)', warning( warnings( inputs( [ 'max_per_group' => 0 ] ) ), 'unlimited' ) !== null );
chk( 'N = 0 with strict off: only the strict-off warning', warning( warnings( inputs( [ 'max_per_group' => 0, 'strict' => false ] ) ), 'unlimited' ) === null );

$w = warning( warnings( inputs( [ 'groups' => 3, 'skip_positions' => [ 1 ] ] ) ), 'skip-positions' );
chk( 'warning: positions without taxonomy, named 1-based', $w !== null && str_contains( $w['text'], '2' ) );

$w = warning( warnings( inputs( [ 'verdict_filtered' => true ] ) ), 'verdict-filtered' );
chk( 'warning: verdict filtered is error-level', ( $w['level'] ?? '' ) === 'error' );

$w = warning( warnings( inputs( [ 'policy_filtered' => [ 'max_filters_per_group' ] ] ) ), 'policy-filtered' );
chk( 'warning: policy filtered names the filter', ( $w['level'] ?? '' ) === 'warning' && str_contains( $w['text'], 'jpkcom_postfilter_max_filters_per_group' ) );

$GLOBALS['__multisite'] = true;
chk( 'warning: multisite', ( warning( warnings( inputs() ), 'multisite' )['level'] ?? '' ) === 'warning' );
$GLOBALS['__multisite'] = false;
chk( 'single site: no multisite warning', warning( $base, 'multisite' ) === null );

$w = warning( warnings( inputs( [ 'bases' => [ '/blog/', '/my news/' ] ] ) ), 'unsafe-bases' );
chk( 'warning: unsafe bases are named (jpkcom_postfilter_server_unsafe_bases)', ( $w['level'] ?? '' ) === 'warning' && str_contains( $w['text'], '/my news/' ) );
chk( 'safe bases: no unsafe-bases warning', warning( $base, 'unsafe-bases' ) === null );

// 1.6.1: a reject pattern no server could load is dropped - and named.
$w = warning( warnings( inputs( [ 'max_per_group' => 20, 'groups' => 71 ] ) ), 'patterns-dropped' );
chk( 'warning 1.6.1: per-group rule dropped (PCRE cannot compile it) is named with its reason',
	( $w['level'] ?? '' ) === 'warning' && str_contains( $w['text'] ?? '', 'terms per group' ) && str_contains( $w['text'] ?? '', 'PCRE' )
	&& ! str_contains( $w['text'] ?? '', 'segment count' ), $w['text'] ?? '' );
$odd40 = array_values( array_filter( range( 0, 39 ), static fn( int $p ): bool => $p % 2 === 1 ) );
$w     = warning( warnings( inputs( [ 'max_per_group' => 20, 'groups' => 40, 'skip_positions' => $odd40 ] ) ), 'patterns-dropped' );
chk( 'warning 1.6.1: an oversized rule names the length limit', str_contains( $w['text'] ?? '', '4000' ), $w['text'] ?? '' );
$w = warning( warnings( inputs( [ 'groups' => 1000 ] ) ), 'patterns-dropped' );
chk( 'warning 1.6.1: both rules dropped: one warning, both named', $w !== null && str_contains( $w['text'], 'terms per group' ) && str_contains( $w['text'], 'segment count' )
	&& count( array_filter( warnings( inputs( [ 'groups' => 1000 ] ) ), static fn( array $x ): bool => $x['id'] === 'patterns-dropped' ) ) === 1, $w['text'] ?? '' );
chk( 'normal layout: no patterns-dropped warning', warning( $base, 'patterns-dropped' ) === null );

// 1.7.1: the reason and the remedy follow the rule that was dropped.
/** The remedy part of the patterns-dropped warning. */
function remedy( ?array $w ): string {
	$t = (string) ( $w['text'] ?? '' );
	$i = strpos( $t, 'Only these bring the rules back:' );
	return $i === false ? '' : substr( $t, $i );
}
$w = warning( warnings( inputs( [ 'max_per_group' => 1, 'groups' => 996 ] ) ), 'patterns-dropped' );
chk( 'warning 1.7.1: per_group falling with segments says so', str_contains( $w['text'] ?? '', 'left out together with the segment count rule' ), $w['text'] ?? '' );
chk( '... and its remedy names only what moves the segment count rule', str_contains( remedy( $w ), 'fewer filter groups' ) && ! str_contains( remedy( $w ), 'terms per group' ) && ! str_contains( remedy( $w ), 'without taxonomy' ), remedy( $w ) );
$w = warning( warnings( inputs( [ 'max_per_group' => 20, 'groups' => 71 ] ) ), 'patterns-dropped' );
chk( 'warning 1.7.1: a per_group rule failing on its own names terms per group and positions without taxonomy as the remedy', str_contains( remedy( $w ), 'terms per group' ) && str_contains( remedy( $w ), 'without taxonomy' ), remedy( $w ) );
chk( '... but not post types or languages (no length problem)', ! str_contains( remedy( $w ), 'post types' ), remedy( $w ) );
$w = warning( warnings( inputs( [ 'max_per_group' => 20, 'groups' => 40, 'skip_positions' => $odd40 ] ) ), 'patterns-dropped' );
chk( 'warning 1.7.1: a length problem also names fewer filterable post types or languages', str_contains( remedy( $w ), 'post types or languages' ), remedy( $w ) );
// Nesting only drops at one term per group (two or more hit the length limit
// first), so fewer terms per group cannot bring it back.
$odd160 = array_values( array_filter( range( 0, 159 ), static fn( int $p ): bool => $p % 2 === 1 ) );
$w      = warning( warnings( inputs( [ 'max_per_group' => 1, 'groups' => 160, 'skip_positions' => $odd160 ] ) ), 'patterns-dropped' );
chk( 'warning 1.7.1: a nesting drop names groups and positions without taxonomy, not terms per group', str_contains( remedy( $w ), 'without taxonomy' ) && str_contains( remedy( $w ), 'fewer filter groups' ) && ! str_contains( remedy( $w ), 'terms per group' ), remedy( $w ) );

$w = warning( (array) call( 'jpkcom_postfilter_server_rules_warnings', inputs( [ 'groups' => 3 ] ), [ 'category', 'post_tag', 'category' ] ), 'duplicate-taxonomies' );
chk( 'info: two groups on one taxonomy (taxonomies passed in)', ( $w['level'] ?? '' ) === 'info' && str_contains( $w['text'], 'category' ) && ! str_contains( $w['text'], 'post_tag' ) );
chk( 'two groups without taxonomy are not duplicates',
	warning( (array) call( 'jpkcom_postfilter_server_rules_warnings', inputs( [ 'groups' => 3, 'skip_positions' => [ 1, 2 ] ] ), [ 'category', '', '' ] ), 'duplicate-taxonomies' ) === null );
chk( 'no taxonomies passed: no duplicate check, no live settings read', warning( warnings( inputs() ), 'duplicate-taxonomies' ) === null );

chk( 'no groups: warning', warning( warnings( inputs( [ 'groups' => 0 ] ) ), 'no-groups' ) !== null );
chk( 'no bases: warning', warning( warnings( inputs( [ 'bases' => [] ] ) ), 'no-bases' ) !== null );

define( 'JPKCOM_POSTFILTER_MAX_FILTERS_PER_GROUP', 2 );
$w = warning( warnings( inputs() ), 'constants' );
chk( 'info: a policy constant is named', $w !== null && str_contains( $w['text'], 'JPKCOM_POSTFILTER_MAX_FILTERS_PER_GROUP' ) );

$order = array_column( warnings( inputs( [ 'verdict_filtered' => true, 'strict' => false ] ) ), 'level' );
$rank  = array_map( static fn( $l ) => [ 'error' => 0, 'warning' => 1, 'info' => 2 ][ $l ], $order );
$sorted = $rank;
sort( $sorted );
chk( 'errors first, then warnings, then info', $rank === $sorted, implode( ',', $order ) );


// =============================================================================
section( 'Status check presentation' );
// =============================================================================

$result = static fn( array $over ): array => array_merge( [
	'robots' => 'ok', 'rule' => 'active', 'stricter' => false, 'stricter_checked' => true, 'stricter_suspected' => false, 'detail' => '',
], $over );

$v = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'stricter' => true ] ) );
chk( 'stricter -> error level (red)', ( $v['stricter']['level'] ?? '' ) === 'error' );
$v = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'stricter_suspected' => true ] ) );
chk( 'stricter_suspected -> warning level (amber)', ( $v['stricter']['level'] ?? '' ) === 'warning' );
$v = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'stricter_checked' => false, 'rule' => 'unreachable' ] ) );
chk( 'not checked and rule != none -> "not checked"', ( $v['stricter']['level'] ?? '' ) === 'warning' && str_contains( strtolower( $v['stricter']['text'] ?? '' ), 'not checked' ) );
$v = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'stricter_checked' => false, 'rule' => 'none' ] ) );
chk( 'rule none: no stricter line', is_array( $v ) && $v['stricter'] === null );
$v = call( 'jpkcom_postfilter_server_rules_check_view', $result( [] ) );
chk( 'checked and fine -> success', ( $v['stricter']['level'] ?? '' ) === 'success' );

$labels = [];
foreach ( [ 'ok', 'not-wordpress', 'disabled', 'unreachable' ] as $r ) {
	$labels[] = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'robots' => $r ] ) )['robots'] ?? '';
}
chk( 'robots statuses get distinct readable labels', count( array_unique( array_filter( $labels ) ) ) === 4 && ! in_array( 'not-wordpress', $labels, true ) );
$labels = [];
foreach ( [ 'active', 'stale', 'missing', 'unknown', 'none', 'unreachable' ] as $r ) {
	$labels[] = call( 'jpkcom_postfilter_server_rules_check_view', $result( [ 'rule' => $r ] ) )['rule'] ?? '';
}
chk( 'rule statuses get distinct readable labels', count( array_unique( array_filter( $labels ) ) ) === 6 && ! in_array( 'stale', $labels, true ) );
chk( 'unknown status values do not break', is_array( call( 'jpkcom_postfilter_server_rules_check_view', [ 'robots' => 'x', 'rule' => 'y' ] ) ) );

$out = render( 'jpkcom_postfilter_server_rules_render_status', [ 'time' => 1761480000, 'result' => $result( [ 'detail' => 'X-Jpkpf-Rule: <script>alert(1)</script>' ] ) ] );
chk( 'status detail is escaped', $out !== '' && ! str_contains( $out, '<script>' ) && str_contains( $out, '&lt;script&gt;' ) );
chk( 'status box has the check button with nonce and action',
	str_contains( $out, 'value="jpkcom_postfilter_server_check"' ) && str_contains( $out, 'nonce-jpkcom_postfilter_server_check' ) );
$out = render( 'jpkcom_postfilter_server_rules_render_status', null );
chk( 'no check yet: says so', $out !== '' && str_contains( strtolower( $out ), 'not run' ) );


// =============================================================================
section( 'Messages after admin-post (key in the URL, text from a fixed list)' );
// =============================================================================

foreach ( [ 'checked', 'htaccess-installed', 'htaccess-installed-empty', 'htaccess-unavailable', 'htaccess-failed', 'htaccess-removed', 'htaccess-remove-failed', 'htaccess-unrestored' ] as $key ) {
	$m = call( 'jpkcom_postfilter_server_rules_message', $key );
	chk( "message '{$key}'", is_array( $m ) && in_array( $m['type'] ?? '', [ 'success', 'error', 'warning', 'info' ], true ) && ( $m['text'] ?? '' ) !== '' );
}
chk( 'unknown key -> no message', call( 'jpkcom_postfilter_server_rules_message', '<b>hi</b>' ) === null );


// =============================================================================
section( '.htaccess: Set up / Remove' );
// =============================================================================

$reasons = [ 'not-apache', 'litespeed', 'multisite', 'file-mods-disallowed', 'no-file', 'not-writable', 'manual-block' ];
$texts   = array_map( static fn( $r ) => call( 'jpkcom_postfilter_server_rules_htaccess_reason', $r ), $reasons );
chk( 'every availability reason has its own text', count( array_unique( array_filter( $texts, 'is_string' ) ) ) === 7 && ! in_array( 'not-apache', $texts, true ) );

file_put_contents( htaccess(), $GLOBALS['__wp_block'] );
$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
$c   = (string) file_get_contents( htaccess() );
chk( 'install: message key', $key === 'htaccess-installed', (string) $key );
chk( 'install: managed block written before # BEGIN WordPress',
	str_contains( $c, "# BEGIN JPKCom Post Filter\n" ) && strpos( $c, '# BEGIN JPKCom Post Filter' ) < strpos( $c, '# BEGIN WordPress' ) );
chk( 'install: option holds the resolved .htaccess path (fix wave item 1), not autoloaded',
	get_option( 'jpkcom_postfilter_htaccess_managed' ) === htaccess() && ( $GLOBALS['__autoload']['jpkcom_postfilter_htaccess_managed'] ?? null ) === false,
	(string) get_option( 'jpkcom_postfilter_htaccess_managed' ) );

$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'remove', inputs() );
chk( 'remove: message key, block gone, option deleted',
	$key === 'htaccess-removed' && (string) file_get_contents( htaccess() ) === $GLOBALS['__wp_block'] && get_option( 'jpkcom_postfilter_htaccess_managed', null ) === null );

$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs( [ 'strict' => false ] ) );
chk( 'install without reject part: set up, empty for now', $key === 'htaccess-installed-empty' && get_option( 'jpkcom_postfilter_htaccess_managed' ) === htaccess() && (string) file_get_contents( htaccess() ) === $GLOBALS['__wp_block'] );
delete_option( 'jpkcom_postfilter_htaccess_managed' );

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
chk( 'install on nginx: refused, file unchanged, no option',
	$key === 'htaccess-unavailable' && (string) file_get_contents( htaccess() ) === $GLOBALS['__wp_block'] && get_option( 'jpkcom_postfilter_htaccess_managed', null ) === null );

// Server moved: a managed block is left over on nginx. Remove still works.
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'remove', inputs() );
chk( 'remove on nginx (server moved): allowed', $key === 'htaccess-removed' && (string) file_get_contents( htaccess() ) === $GLOBALS['__wp_block'] && get_option( 'jpkcom_postfilter_htaccess_managed', null ) === null );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter (manual)\nRewriteEngine On\n# END JPKCom Post Filter (manual)\n" . $GLOBALS['__wp_block'] );
$before = (string) file_get_contents( htaccess() );
$key    = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
chk( 'install with a manual block: refused, file unchanged', $key === 'htaccess-unavailable' && (string) file_get_contents( htaccess() ) === $before );

file_put_contents( htaccess(), $GLOBALS['__wp_block'] );
call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) {
	echo "  SKIP  remove on a read-only file (running as root)\n";
} else {
	chmod( htaccess(), 0444 );
	$key = call( 'jpkcom_postfilter_server_rules_htaccess_action', 'remove', inputs() );
	chk( 'remove on a read-only file: failed, option kept (block still guarded)', $key === 'htaccess-remove-failed' && get_option( 'jpkcom_postfilter_htaccess_managed' ) === htaccess() );
	chmod( htaccess(), 0644 );
}
call( 'jpkcom_postfilter_server_rules_htaccess_action', 'remove', inputs() );

// T8-a: Set up / Remove whose write fails half way and cannot be undone.
$real = htaccess();
$GLOBALS['__home_path'] = 'jpkpffail://home/';
foreach ( [ 'install' => $GLOBALS['__wp_block'], 'remove' => null ] as $do => $content ) {
	if ( $content === null ) {
		Jpkpf_Failing_Stream::$files = [ 'jpkpffail://home/.htaccess' => $GLOBALS['__wp_block'] ];
		call( 'jpkcom_postfilter_server_rules_htaccess_action', 'install', inputs() );
		$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = '1';
	} else {
		Jpkpf_Failing_Stream::$files = [ 'jpkpffail://home/.htaccess' => $content ];
	}
	Jpkpf_Failing_Stream::$plan = [ 'half', 'fail', 'fail', 'fail' ];
	$key  = call( 'jpkcom_postfilter_server_rules_htaccess_action', $do, inputs() );
	Jpkpf_Failing_Stream::$plan = [];
	$text = (string) ( call( 'jpkcom_postfilter_server_rules_message', (string) $key )['text'] ?? '' );
	chk( "{$do}: write and rollback fail -> its own message (the file may be incomplete)",
		$key === 'htaccess-unrestored' && str_contains( $text, 'may be incomplete' ), (string) $key );
	if ( $do === 'remove' ) {
		chk( 'remove, rollback failed: option kept (the block is still guarded)', get_option( 'jpkcom_postfilter_htaccess_managed', null ) !== null );
	}
	unset( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] );
}
$GLOBALS['__home_path'] = dirname( $real ) . '/';

chk( 'unknown action: no message', call( 'jpkcom_postfilter_server_rules_htaccess_action', 'format-disk', inputs() ) === '' );


// =============================================================================
section( 'admin-post handlers: capability, nonce, redirect with a message key' );
// =============================================================================

foreach ( [ 'jpkcom_postfilter_htaccess', 'jpkcom_postfilter_server_check' ] as $action ) {
	chk( "admin_post_{$action} registered", has_action( "admin_post_{$action}" ) !== false );
}

$GLOBALS['__can'] = false;
$_POST            = [ 'do' => 'install' ];
$died             = false;
try {
	call( 'jpkcom_postfilter_server_rules_handle_htaccess' );
} catch ( Jpkpf_Test_Die $e ) {
	$died = true;
}
chk( 'htaccess handler without manage_options: dies, file unchanged', $died && (string) file_get_contents( htaccess() ) === $GLOBALS['__wp_block'] );
$died = false;
try {
	call( 'jpkcom_postfilter_server_rules_handle_check' );
} catch ( Jpkpf_Test_Die $e ) {
	$died = true;
}
chk( 'check handler without manage_options: dies', $died );
$GLOBALS['__can'] = true;

$GLOBALS['__referer'] = [];
$_POST                = [ 'do' => 'remove' ];
$redirect             = '';
try {
	call( 'jpkcom_postfilter_server_rules_handle_htaccess' );
} catch ( Jpkpf_Test_Redirect $e ) {
	$redirect = $e->getMessage();
}
chk( 'htaccess handler checks the nonce', in_array( 'jpkcom_postfilter_htaccess', $GLOBALS['__referer'], true ) );
chk( 'htaccess handler redirects to the page with a message key',
	str_contains( $redirect, 'page=jpkcom-postfilter-server-rules' ) && str_contains( $redirect, 'jpkpf_msg=' ) && str_ends_with( $redirect, '#htaccess' ), $redirect );
call( 'jpkcom_postfilter_server_rules_htaccess_action', 'remove', inputs() );
$_POST = [];


// =============================================================================
section( 'Menu' );
// =============================================================================

do_action( 'admin_menu' );
$sub = null;
foreach ( $GLOBALS['__submenus'] as $args ) {
	if ( ( $args['menu_slug'] ?? '' ) === 'jpkcom-postfilter-server-rules' ) {
		$sub = $args;
	}
}
chk( 'submenu registered with the brief\'s arguments',
	is_array( $sub ) && $sub['parent_slug'] === 'jpkcom-post-filter' && $sub['page_title'] === 'Server Rules' && $sub['menu_title'] === 'Server Rules'
	&& $sub['capability'] === 'manage_options' && $sub['callback'] === 'jpkcom_postfilter_page_server_rules' );
chk( 'hook suffix stored in $GLOBALS', ( $GLOBALS['jpkcom_postfilter_server_rules_hook'] ?? '' ) === 'post-filter_page_jpkcom-postfilter-server-rules' );


// =============================================================================
section( 'Tabs rendered for one fixed input set: everything escaped' );
// =============================================================================

$fixed  = inputs( [ 'bases' => [ '/blog/', '/jobs/' ] ] );
$opts   = [ 'workers' => 8, 'render_seconds' => 0.4, 'nginx_variant' => '' ];
$limits = jpkcom_postfilter_rate_limits( workers: 8, render_seconds: 0.4 );
file_put_contents( htaccess(), $GLOBALS['__wp_block'] );

$all = '';
foreach ( [ 'nginx', 'apache', 'htaccess', 'caddy', 'ferron3', 'ferron2', 'ols' ] as $tab ) {
	$out  = render( 'jpkcom_postfilter_server_rules_render_tab', $tab, $fixed, $opts, 'nginx' );
	$all .= $out;
	$codes = jpkcom_postfilter_server_snippets( $tab, $fixed, $limits );
	$with  = array_filter( $codes, static fn( $s ) => $s['code'] !== '' );

	chk( "{$tab}: panel rendered", str_contains( $out, 'id="tab-' . $tab . '"' ) );
	chk( "{$tab}: one copy button per code block plus the check commands",
		substr_count( $out, 'jpkpf-sr-copy' ) >= count( $with ) + 1 );
	chk( "{$tab}: no raw markup from the snippet code",
		! str_contains( $out, '<!doctype' ) && ! str_contains( $out, '<IfModule' ) && ! str_contains( $out, '<LocationMatch' ) && ! str_contains( $out, '<html' ) );
	$escaped_ok = true;
	foreach ( $with as $snippet ) {
		if ( ! str_contains( $out, esc_html( $snippet['code'] ) ) ) {
			$escaped_ok = false;
		}
	}
	chk( "{$tab}: every code block appears esc_html()'d", $escaped_ok );
	chk( "{$tab}: order rule (tighten: plugin first + purge cache; loosen: server first)",
		str_contains( $out, 'tightening' ) && str_contains( $out, 'loosening' ) && str_contains( $out, 'page cache' ) );
	chk( "{$tab}: check commands block", str_contains( $out, 'jpkpf-sr-code-' . $tab . '-check' ) );
}
chk( 'nginx tab marked as detected', str_contains( render( 'jpkcom_postfilter_server_rules_render_tab', 'nginx', $fixed, $opts, 'nginx' ), 'jpkpf-sr-detected' ) );
chk( 'Apache detected: both Apache tabs marked',
	str_contains( render( 'jpkcom_postfilter_server_rules_render_tab', 'apache', $fixed, $opts, 'apache' ), 'jpkpf-sr-detected' )
	&& str_contains( render( 'jpkcom_postfilter_server_rules_render_tab', 'htaccess', $fixed, $opts, 'apache' ), 'jpkpf-sr-detected' ) );
chk( 'other tab not marked as detected', ! str_contains( render( 'jpkcom_postfilter_server_rules_render_tab', 'caddy', $fixed, $opts, 'nginx' ), 'jpkpf-sr-detected' ) );

$out = render( 'jpkcom_postfilter_server_rules_render_tab', 'nginx', $fixed, [ 'nginx_variant' => 'ispconfig' ] + $opts, 'nginx' );
chk( 'nginx tab follows the ISPConfig variant', str_contains( $out, '##merge##' ) );

$out = render( 'jpkcom_postfilter_server_rules_render_tab', 'htaccess', $fixed, $opts, 'apache' );
chk( 'htaccess tab: management box with a Set up button (Apache, writable)',
	str_contains( $out, 'jpkpf-sr-htaccess' ) && str_contains( $out, 'name="do" value="install"' ) && str_contains( $out, 'nonce-jpkcom_postfilter_htaccess' ) );
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
$out = render( 'jpkcom_postfilter_server_rules_render_tab', 'htaccess', $fixed, $opts, 'nginx' );
chk( 'htaccess tab on nginx: reason instead of a button',
	! str_contains( $out, 'name="do" value="install"' ) && str_contains( $out, (string) call( 'jpkcom_postfilter_server_rules_htaccess_reason', 'not-apache' ) ) );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter\nRewriteEngine On\n" . $GLOBALS['__wp_block'] );
$out = render( 'jpkcom_postfilter_server_rules_render_tab', 'htaccess', $fixed, $opts, 'apache' );
chk( 'htaccess tab: damaged markers are shown', str_contains( $out, 'jpkpf-sr-htaccess-damaged' ) );
file_put_contents( htaccess(), $GLOBALS['__wp_block'] . "# BEGIN JPKCom Post Filter\n# END JPKCom Post Filter\n" );
update_option( 'jpkcom_postfilter_htaccess_managed', '1', false );
$out = render( 'jpkcom_postfilter_server_rules_render_tab', 'htaccess', $fixed, $opts, 'apache' );
chk( 'htaccess tab: misplaced block is shown, Remove offered', str_contains( $out, 'jpkpf-sr-htaccess-misplaced' ) && str_contains( $out, 'name="do" value="remove"' ) );
delete_option( 'jpkcom_postfilter_htaccess_managed' );

$head = render( 'jpkcom_postfilter_server_rules_render_head', inputs( [ 'bases' => [ '/blog/', '/a"b/' ] ] ), $opts, 'Apache/2.4 "<x>"' );
chk( 'head: SERVER_SOFTWARE and bases escaped', $head !== '' && ! str_contains( $head, '<x>' ) && ! str_contains( $head, '/a"b/' ) );
chk( 'head: form posts the option through options.php', str_contains( $head, 'action="options.php"' ) && str_contains( $head, 'value="jpkcom_postfilter_server_rules"' ) );
chk( 'head: warnings rendered', str_contains( $head, 'jpkpf-sr-warning' ) );
chk( 'head: cookie and search-engine notes', str_contains( $head, 'Cookie' ) && str_contains( $head, 'Googlebot' ) );
chk( 'head: render time input step matches the sanitizer (0.01)', str_contains( $head, 'step="0.01"' ) && ! str_contains( $head, 'step="0.05"' ) );

// Spec 8: the hard rule holds when the snippets are generated; after a
// loosening, deployed rules are the admin's job (order rule).
$intro = render( 'jpkcom_postfilter_server_rules_render_intro' );
chk( 'intro: "never stricter" only for the current settings', str_contains( $intro, 'with the current settings' ) && ! str_contains( $intro, 'never reject a URL the plugin serves.' ) );
chk( 'intro: deployed rules stay until replaced, order rule named', str_contains( $intro, 'stay as they are until you replace them' ) && str_contains( $intro, 'order rule' ) );

chk( 'jpkpf_msg is a removable query arg', in_array( 'jpkpf_msg', (array) apply_filters( 'removable_query_args', [ 'settings-updated' ] ), true )
	&& in_array( 'settings-updated', (array) apply_filters( 'removable_query_args', [ 'settings-updated' ] ), true ) );

// R17: the site moved to nginx, a managed block is left in a writable
// .htaccess, the option is gone. Remove must be offered, Set up must not.
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteEngine On\n# END JPKCom Post Filter\n" . $GLOBALS['__wp_block'] );
delete_option( 'jpkcom_postfilter_htaccess_managed' );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'R17 box (nginx, leftover block, no option): Remove offered, no Set up',
	str_contains( $out, 'name="do" value="remove"' ) && ! str_contains( $out, 'name="do" value="install"' ) );
chk( 'R17 box: the reason is shown too', str_contains( $out, (string) call( 'jpkcom_postfilter_server_rules_htaccess_reason', 'not-apache' ) ) );
chk( 'htaccess actions: forms are not wrapped in a <p>', ! str_contains( $out, '<p class="jpkpf-sr-htaccess-actions">' ) && str_contains( $out, '<div class="jpkpf-sr-htaccess-actions">' ) );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
file_put_contents( htaccess(), $GLOBALS['__wp_block'] );


// =============================================================================
section( 'Box status: the real reason a stale block stays stale (fix wave items 8, D2, D3)' );
// =============================================================================

// admin_init (jpkcom_postfilter_htaccess_maybe_resync) runs before the page:
// a block that is still stale when the box renders is stale for a reason the
// next page load does not change either.
$stale = "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteEngine On\n# END JPKCom Post Filter\n" . $GLOBALS['__wp_block'];
$next  = 'It is updated on the next admin page load';

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
file_put_contents( htaccess(), $stale );
update_option( 'jpkcom_postfilter_htaccess_managed', htaccess(), false );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'stale block on nginx (managed): "this server ignores .htaccess, remove it", not "next admin page load"',
	str_contains( $out, 'ignores .htaccess' ) && str_contains( $out, 'Remove' ) && ! str_contains( $out, $next ) );
delete_option( 'jpkcom_postfilter_htaccess_managed' );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'stale block on nginx, option gone: same, not "next admin page load"', str_contains( $out, 'ignores .htaccess' ) && ! str_contains( $out, $next ) );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'stale block on Apache without the option (orphan): not set up, so not updated', str_contains( $out, 'not set up' ) && ! str_contains( $out, $next ) );

update_option( 'jpkcom_postfilter_htaccess_managed', htaccess(), false );
if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) {
	echo "  SKIP  stale block, read-only (running as root)\n";
} else {
	chmod( htaccess(), 0444 );
	$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
	chk( 'stale block, read-only file: says the plugin cannot update it, not "next admin page load"',
		str_contains( $out, 'cannot update it' ) && ! str_contains( $out, $next ) );
	chmod( htaccess(), 0644 );
}

file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter (manual)\n# END JPKCom Post Filter (manual)\n" . $stale );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'stale block next to a manual block: says why it is not updated', str_contains( $out, 'inserted by hand, so the plugin does not update' ) && ! str_contains( $out, $next ) );

file_put_contents( htaccess(), $stale );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'stale block on Apache, managed and writable: the next admin page load updates it', str_contains( $out, $next ) );

// D3: damaged markers - the status line agrees with the error box.
file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteEngine On\n" . $GLOBALS['__wp_block'] );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'damaged markers: own status line, not "Set up, but there is no block in the file"',
	str_contains( $out, 'markers do not pair up, see below' ) && ! str_contains( $out, 'Set up, but there is no block in the file' ) );
delete_option( 'jpkcom_postfilter_htaccess_managed' );
file_put_contents( htaccess(), $GLOBALS['__wp_block'] );

// Item 10: the string ErrorDocument is not limited to filter URLs.
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', $fixed );
chk( 'Set up box: the ErrorDocument line applies to every 410 Apache itself sends in that directory tree',
	str_contains( $out, 'ErrorDocument' ) && str_contains( $out, 'every 410' ) && str_contains( $out, 'directory tree' ) );
$out = render( 'jpkcom_postfilter_server_rules_render_htaccess_box', inputs( [ 'reject_status' => 404 ] ) );
chk( 'Set up box: the status follows the settings (404)', str_contains( $out, 'every 404' ) );


// =============================================================================
section( 'Front-end-only filters, plural forms (fix wave items 13, 14)' );
// =============================================================================

$w = warning( warnings( inputs() ), 'frontend-filters' );
chk( 'warning: filters registered only on the front end are invisible here (always shown)',
	$w !== null && str_contains( $w['text'], 'front end' ) && str_contains( $w['text'], 'jpkcom_postfilter_' ) );

$head = render( 'jpkcom_postfilter_server_rules_render_head', $fixed, [ 'workers' => 1, 'render_seconds' => 10.0, 'nginx_variant' => '' ], 'nginx/1.26.3' );
chk( 'rate-limit summary with 1: singular forms (D4)',
	str_contains( $head, 'At most 1 filter page rendering' ) && str_contains( $head, 'Site-wide 1 request per second' )
	&& str_contains( $head, 'own budget: 1 request per second' ) && ! str_contains( $head, ' 1 requests' ) && ! str_contains( $head, '1 filter pages' ) );
$head = render( 'jpkcom_postfilter_server_rules_render_head', $fixed, $opts, 'nginx/1.26.3' );
chk( 'rate-limit summary with more: plural forms', str_contains( $head, 'At most 3 filter pages rendering' ) && str_contains( $head, 'Site-wide 3 requests per second' ) );

exit( summary() );
