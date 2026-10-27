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

$GLOBALS['__tmp'] = sys_get_temp_dir() . '/jpkpf-htaccess-sync-' . getmypid();

// WordPress lives in {home}/wp/: what get_home_path() makes of a site whose home
// differs from siteurl (WordPress in a subdirectory) - see get_home_path() below.
define( 'ABSPATH', $GLOBALS['__tmp'] . '/home/wp/' );
define( 'JPKCOM_POSTFILTER_VERSION', '1.6.0-test' );
define( 'JPKCOM_POSTFILTER_CACHE_ENABLED', true );
define( 'JPKCOM_POSTFILTER_URL_ENDPOINT', 'filter' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'HOUR_IN_SECONDS', 3600 );

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
	// Every read is counted: an option that is not autoloaded is a query (T9-b).
	$GLOBALS['__option_reads'][ $k ] = ( $GLOBALS['__option_reads'][ $k ] ?? 0 ) + 1;
	// Two requests at once: a request with a snapshot reads the options as they
	// were when it loaded them (alloptions at request start), not what another
	// request committed since. The save lock is read past it, through $wpdb.
	$snapshot = $GLOBALS['__snapshots'][ $GLOBALS['__request_id'] ?? 'A' ] ?? null;
	if ( is_array( $snapshot ) && array_key_exists( $k, $snapshot ) ) {
		return $snapshot[ $k ];
	}
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
/** The row, and the writing request's own view of it (its snapshot, if it has one). */
function store_option( string $option, mixed $value ): void {
	$GLOBALS['__options'][ $option ] = $value;
	$rid = $GLOBALS['__request_id'] ?? 'A';
	if ( isset( $GLOBALS['__snapshots'][ $rid ] ) ) {
		$GLOBALS['__snapshots'][ $rid ][ $option ] = $value;
	}
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
	store_option( $option, $value );
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
	store_option( $option, $value );
	do_action( "add_option_{$option}", $option, $value );
	return true;
}
function delete_option( $option ) {
	if ( ! array_key_exists( $option, $GLOBALS['__options'] ) ) {
		return false;
	}
	// Core: 'delete_option' before the row goes, delete_option_{$option} and
	// 'deleted_option' after.
	do_action( 'delete_option', $option );
	unset( $GLOBALS['__options'][ $option ] );
	do_action( "delete_option_{$option}", $option );
	do_action( 'deleted_option', $option );
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
function get_bloginfo( $show = '' ) {
	// The reject body asks for the language: one call per rendered block (T9-a).
	if ( $show === 'language' ) {
		$GLOBALS['__renders'] = ( $GLOBALS['__renders'] ?? 0 ) + 1;
	}
	return $show === 'language' ? 'en-US' : '';
}
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
/**
 * get_home_path(): the fixture's home path, or - with $GLOBALS['__core_home_path'] -
 * exactly what wp-admin/includes/file.php (core 7.1.3) computes. With home !==
 * siteurl that depends on SCRIPT_FILENAME, which under WP-CLI is the wp binary.
 */
function get_home_path() {
	if ( empty( $GLOBALS['__core_home_path'] ) ) {
		return $GLOBALS['__home_path'];
	}
	$home    = $GLOBALS['__wp_home'];
	$siteurl = $GLOBALS['__wp_siteurl'];
	if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
		$wp_path_rel_to_home = str_ireplace( $home, '', $siteurl );
		$script              = (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' );
		$pos                 = strripos( str_replace( '\\', '/', $script ), trailingslashit( $wp_path_rel_to_home ) );
		$home_path           = substr( $script, 0, (int) $pos );   // core runs without strict_types: false -> 0
		$home_path           = trailingslashit( $home_path );
	} else {
		$home_path = ABSPATH;
	}
	return str_replace( '\\', '/', $home_path );
}
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }
final class WP_CLI {
	public static function warning( $message ) { $GLOBALS['__cli_warnings'][] = (string) $message; }
}
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

// --- the save lock (T9-d) ---------------------------------------------------------------

/**
 * $wpdb for the save lock: the four statements it sends, run against the same
 * option rows get_option() reads. prepare() hands the query and its arguments
 * on as JSON, so nothing is parsed out of SQL text.
 */
final class Jpkpf_Wpdb {
	public string $options    = 'wp_options';
	public array $log         = [];
	public string $last_error = '';
	/** Statements that fail like a broken options table: 'INSERT', 'SELECT', ... ([] = none). */
	public array $fail        = [];

	public function prepare( string $query, ...$args ): string {
		return (string) json_encode( [ $query, $args ] );
	}
	/** What wpdb::query() does on a database error: last_error set, false back. */
	private function failed( string $q ): bool {
		foreach ( $this->fail as $verb ) {
			if ( str_starts_with( $q, $verb ) ) {
				$this->last_error = "Table 'wp.wp_options' doesn't exist";
				return true;
			}
		}
		$this->last_error = '';
		return false;
	}
	public function query( string $prepared ) {
		[ $q, $a ]   = json_decode( $prepared, true );
		$this->log[] = $q;
		if ( $this->failed( $q ) ) {
			return false;
		}
		$rows        = &$GLOBALS['__options'];
		if ( str_starts_with( $q, 'INSERT IGNORE' ) ) {         // name, value
			if ( array_key_exists( $a[0], $rows ) ) {
				return 0;
			}
			$rows[ $a[0] ] = $a[1];
			return 1;
		}
		if ( str_starts_with( $q, 'UPDATE' ) ) {                 // new value, name, old value
			if ( ( $rows[ $a[1] ] ?? null ) !== $a[2] ) {
				return 0;
			}
			$rows[ $a[1] ] = $a[0];
			return 1;
		}
		if ( str_starts_with( $q, 'DELETE' ) ) {                 // name, value
			if ( ( $rows[ $a[0] ] ?? null ) !== $a[1] ) {
				return 0;
			}
			unset( $rows[ $a[0] ] );
			return 1;
		}
		return false;
	}
	public function get_var( string $prepared ) {
		[ $q, $a ]   = json_decode( $prepared, true );
		$this->log[] = $q;
		if ( $this->failed( $q ) ) {
			return null;
		}
		$v           = $GLOBALS['__options'][ $a[0] ] ?? null;
		return $v === null ? null : (string) $v;
	}
}
$GLOBALS['wpdb'] = new Jpkpf_Wpdb();

const LOCK = 'jpkcom_postfilter_htaccess_save_lock';

// Which request is running: one process plays two admins saving at once.
function jpkcom_postfilter_htaccess_request_id(): string { return $GLOBALS['__request_id'] ?? 'A'; }

// --- the plugin -------------------------------------------------------------------------

foreach ( [ 'helpers.php', 'settings.php', 'taxonomies.php', 'url-policy.php', 'server-rules.php', 'server-rules-render.php', 'htaccess-manager.php', 'htaccess-sync.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

// What admin_init does for options.php: register the settings and their sanitizers.
do_action( 'admin_init' );

// --- harness ----------------------------------------------------------------------------

$GLOBALS['__pass']        = 0;
$GLOBALS['__fail']        = 0;
$GLOBALS['__skip']        = 0;
$GLOBALS['__skip_reason'] = '';

function section( string $title ): void { echo "\n" . $title . "\n"; }

function chk( string $name, bool $cond, string $detail = '' ): void {
	// A check after read_only() whose mode did not hold (root): the failure
	// path never ran, so it must not count as a pass (T9-f).
	if ( $GLOBALS['__skip_reason'] !== '' ) {
		$GLOBALS['__skip']++;
		echo "  SKIP  {$name}  ({$GLOBALS['__skip_reason']})\n";
		return;
	}
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  {$name}\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  {$name}" . ( '' !== $detail ? "  ({$detail})" : '' ) . "\n";
	}
}

function summary(): int {
	if ( $GLOBALS['__skip'] > 0 ) {
		printf( "\n  %d skipped (file modes do not hold for this user)", $GLOBALS['__skip'] );
	}
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

/**
 * Take rights away from the fixture's .htaccess: 0444 (read-only) or 0000
 * (not even readable). Root - and anyone else the mode does not stop - can
 * still read and write it, so the failure path a check is about never runs.
 * Then every check until the next site() is reported as SKIP with the reason,
 * never as PASS (T9-f).
 */
function read_only( int $mode = 0444 ): void {
	chmod( htaccess(), $mode );
	clearstatcache();
	$root  = function_exists( 'posix_geteuid' ) && posix_geteuid() === 0;
	$holds = ! is_writable( htaccess() ) && ( ( $mode & 0444 ) !== 0 || ! is_readable( htaccess() ) );
	if ( $root ) {
		$GLOBALS['__skip_reason'] = sprintf( 'running as root: chmod %04o does not stop root', $mode );
	} elseif ( ! $holds ) {
		$GLOBALS['__skip_reason'] = sprintf( 'this user can still use a file with mode %04o', $mode );
	}
}

function bytes( string $f ): string {
	clearstatcache();
	return is_file( $f ) ? (string) @file_get_contents( $f ) : '__no_file__';   // @: unreadable fixtures (T9-e)
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
	$GLOBALS['__skip_reason'] = '';
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

// One-shot callbacks run inside a save: between the loosen (sanitize_option_*
// priority 5) and the commit, and between the commit and the tighten
// (update_option_* priority 20).
foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
	add_filter( "sanitize_option_{$o}", static function ( $value ) {
		$fn = $GLOBALS['__after_loosen'] ?? null;
		unset( $GLOBALS['__after_loosen'] );
		if ( is_callable( $fn ) ) {
			$fn();
		}
		return $value;
	}, 7, 1 );
	add_action( "update_option_{$o}", static function () {
		$fn = $GLOBALS['__after_commit'] ?? null;
		unset( $GLOBALS['__after_commit'] );
		if ( is_callable( $fn ) ) {
			$fn();
		}
	}, 10, 0 );
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
	read_only();
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
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'write fails on a partial stored value: no commit, stored array untouched',
		$ok === false && $GLOBALS['__commits'] === [] && $GLOBALS['__options']['jpkcom_postfilter_general'] === $partial
		&& cache_array( 'general' ) === $partial );

	// First-ever save refused: nothing is added, no cache file appears.
	site( null );
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'write fails on the first save: option still absent, no cache file',
		$ok === false && ! array_key_exists( 'jpkcom_postfilter_general', $GLOBALS['__options'] ) && cache_array( 'general' ) === null && $GLOBALS['__commits'] === [] );

	site( general() );
	$groups_was = $GLOBALS['__options']['jpkcom_postfilter_filter_groups'];
	read_only();
	$ok = save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] );
	chk( 'write fails on the Filter Groups page: refused there too, error on that page',
		$ok === false && $GLOBALS['__options']['jpkcom_postfilter_filter_groups'] === $groups_was
		&& cache_array( 'filter_groups' ) === $groups_was
		&& ( $GLOBALS['__errors'][0]['setting'] ?? '' ) === 'jpkcom_postfilter_filter_groups_messages' );

	// A tightening save needs no write before the commit: it goes through.
	site( general( [ 'max_filters_per_group' => 3 ] ) );
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	chk( 'read-only file, tightening N 3->2: saved, no error, block stays looser (N=3)',
		$ok === true && $GLOBALS['__errors'] === [] && n_of( bytes( htaccess() ) ) === 'N3' );

	// A managed block that the file no longer has: nothing could be stricter.
	site( general(), null, true, false );
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'read-only file without a managed block: saved', $ok === true && $GLOBALS['__errors'] === [] );

	// Fix wave item 7: strictness, not the full hash. A pure 404 <-> 410 switch
	// rejects the same URLs (ruling R20) - nothing to refuse on a read-only file.
	site( general() );
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'reject_status' => 404 ] ) );
	chk( 'read-only file, only reject_status 410->404: saved, no error (not stricter)',
		$ok === true && $GLOBALS['__errors'] === [] && ( $GLOBALS['__options']['jpkcom_postfilter_general']['reject_status'] ?? null ) === 404,
		json_encode( $GLOBALS['__errors'] ) );

	// Reason-specific refusal texts (D3): damaged markers are fixed by hand, not by chmod.
	site( general() );
	file_put_contents( htaccess(), str_replace( "# END JPKCom Post Filter\n", '', bytes( htaccess() ) ) );
	$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
	chk( 'damaged markers, writable file: refused, the text says fix the markers - not "make writable"',
		$ok === false && str_contains( $msg, 'markers' ) && str_contains( $msg, 'by hand' ) && ! str_contains( $msg, 'writable' ), $msg );

	site( general() );   // write() refuses next to a manual block, so the manual one is pasted afterwards
	file_put_contents( htaccess(), "# BEGIN JPKCom Post Filter (manual)\n# hand-made\n# END JPKCom Post Filter (manual)\n" . bytes( htaccess() ) );
	$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
	chk( 'managed + manual block: refused with its own text (delete one of the two blocks)',
		$ok === false && str_contains( $msg, '(manual)' ) && ! str_contains( $msg, 'Make the .htaccess writable' ), json_encode( [ $ok, $msg, n_of( bytes( htaccess() ) ) ] ) );

	site( general() );
	read_only();
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
	chk( 'read-only file: the text says make it writable', str_contains( $msg, 'not writable' ) && str_contains( $msg, 'Make the .htaccess writable' ), $msg );

	// T8-a: the write fails half way and the old content cannot be put back
	// either. The refusal must say the file may be incomplete - not the
	// generic "check permissions" of a write that changed nothing.
	foreach ( [ 'rollback fails' => [ 'half', 'fail', 'fail', 'fail' ], 'rollback works' => [ 'half', 'fail', 'fail' ] ] as $label => $plan ) {
		site( general() );
		$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
		$cache_was  = cache_array( 'general' );
		$real       = htaccess();
		$GLOBALS['__home_path']      = 'jpkpffail://home/';
		Jpkpf_Failing_Stream::$files = [ 'jpkpffail://home/.htaccess' => bytes( $real ) ];
		Jpkpf_Failing_Stream::$plan  = $plan;
		try {
			$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
			$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
		} finally {
			$GLOBALS['__home_path']      = dirname( $real ) . '/';
			Jpkpf_Failing_Stream::$plan  = [];
		}
		$kept = $ok === false && $GLOBALS['__commits'] === [] && $GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored && cache_array( 'general' ) === $cache_was;
		if ( $label === 'rollback fails' ) {
			chk( 'write fails and the rollback fails too: refused, option and cache file unchanged, the text says the file may be incomplete',
				$kept && str_contains( $msg, 'may be incomplete' ) && ! str_contains( $msg, 'permissions, free disk space' ), $msg );
		} else {
			chk( 'write fails, rollback works: refused with the generic text (nothing to repair)',
				$kept && str_contains( $msg, 'permissions, free disk space' ) && ! str_contains( $msg, 'may be incomplete' ), $msg );
		}
	}
	chk( 'refusal text for write-unrestored: names the reason and the remedy',
		str_contains( (string) call( 'jpkcom_postfilter_htaccess_refusal_message', 'write-unrestored' ), 'could not be put back' )
		&& str_contains( (string) call( 'jpkcom_postfilter_htaccess_refusal_message', 'write-unrestored' ), 'check it now' ) );

	// T9-e: an .htaccess that exists but cannot be read. Its block is unknown -
	// it may be stricter than anything - so a loosening save is refused like
	// for damaged markers, and option, cache file and file stay as they were.
	site( general() );
	$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
	$cache_was  = cache_array( 'general' );
	$file_was   = bytes( htaccess() );
	read_only( 0000 );
	$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
	chmod( htaccess(), 0644 );
	chk( 'unreadable file, loosening N 2->3: refused, option, cache file and file unchanged',
		$ok === false && $GLOBALS['__commits'] === [] && $GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored
		&& cache_array( 'general' ) === $cache_was && bytes( htaccess() ) === $file_was, json_encode( [ $ok, $GLOBALS['__errors'] ] ) );
	chk( 'unreadable file: the text names the reason and says make it readable',
		str_contains( $msg, 'not readable' ) && str_contains( $msg, 'Make the .htaccess readable' ), $msg );

	foreach ( [
		'tightening N 3->2'                => [ general( [ 'max_filters_per_group' => 3 ] ), general( [ 'max_filters_per_group' => '2' ] ) ],
		'a setting the rule does not read' => [ general(), general( [ 'filter_link_mode' => 'combo' ] ) ],
	] as $label => [ $stored, $submitted ] ) {
		site( $stored );
		read_only( 0000 );
		$ok = save( 'jpkcom_postfilter_general', $submitted );
		chk( "unreadable file, {$label}: saved (needs no loosening), no error", $ok === true && $GLOBALS['__errors'] === [], json_encode( $GLOBALS['__errors'] ) );
	}

	site( general(), null, false, true );   // not set up: nothing known to guard
	read_only( 0000 );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'unreadable file, not set up: saved', $ok === true && $GLOBALS['__errors'] === [] );

	// Import (settings_save writes the cache file itself after update_option()).
	site( general() );
	$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
	read_only();
	jpkcom_postfilter_settings_save( 'general', general( [ 'max_filters_per_group' => 3 ] ) );
	jpkcom_postfilter_settings_memo_reset();
	chk( 'refused through settings_save() (import): cache file back on the stored value',
		$GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored && cache_array( 'general' ) === $old_stored
		&& jpkcom_postfilter_policy( 'max_filters_per_group' ) === 2 );

	// M-4: a block written by 1.6.0 for a layout over the size guard's limits
	// carries a hash without `dropped` (R161-4 added it in 1.7.1) - and one
	// rule more than the plugin would write now, a rule that rejects only what
	// the plugin rejects too. On a read-only file every save was refused as
	// "not writable", even one that loosens nothing.
	$many = [];
	for ( $i = 0; $i < 71; $i++ ) {
		$many[] = group( 'tax' . $i, $i );
	}
	$inputs = site( general( [ 'max_filters_per_group' => 20 ] ), $many );
	$legacy = jpkcom_postfilter_server_rule_hash( $inputs, [] );
	chk( 'M-4 fixture: N20 G71 drops a pattern, so its 1.6.0 hash differs',
		jpkcom_postfilter_server_patterns_dropped( $inputs ) !== [] && $legacy !== $inputs['hash'] && rule_hash( bytes( htaccess() ) ) === $inputs['hash'] );
	file_put_contents( htaccess(), str_replace( $inputs['hash'], $legacy, bytes( htaccess() ) ) );
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '20', 'seo_index_max_terms' => 2 ] ) );
	chk( 'M-4 1.6.0 hash (no `dropped`) on a read-only file, a save that loosens nothing: saved, no error',
		$ok === true && $GLOBALS['__errors'] === [], json_encode( $GLOBALS['__errors'] ) );
	chk( 'M-4 hash_rejects_as(): the 1.6.0 hash of the same inputs rejects as they do', jpkcom_postfilter_htaccess_hash_rejects_as( $legacy, $inputs ) === true );
	chk( 'M-4 hash_rejects_as(): a 1.6.0 hash of other inputs (N19) does not',
		jpkcom_postfilter_htaccess_hash_rejects_as( jpkcom_postfilter_server_rule_hash( array_merge( $inputs, [ 'max_per_group' => 19 ] ), [] ), $inputs ) === false );
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
	// R18 extended (fix wave item 12): admin_init also runs on admin-post.php
	// for anonymous visitors (core runs it before the login check) - no write
	// for anyone who could not manage the options.
	$GLOBALS['__can'] = false;
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init without a user who can manage_options (logged out on admin-post.php): no write', n_of( bytes( htaccess() ) ) === 'N2' );
	$GLOBALS['__can'] = true;
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

	// T9-a: on an equal hash nothing is rendered - since 1.6.1 rendering the
	// block also runs the size guard over both pattern forms twice, on every
	// managed admin page load.
	$GLOBALS['__renders'] = 0;
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init: same hash -> no block rendered (T9-a)', $GLOBALS['__renders'] === 0 && function_exists( 'jpkcom_postfilter_htaccess_maybe_resync' ), $GLOBALS['__renders'] . ' renders' );
	$cap = static fn( $v ) => 4;
	add_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );
	$GLOBALS['__renders'] = 0;
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init: stale hash -> rendered and synced (the probe sees a render)', $GLOBALS['__renders'] > 0 && n_of( bytes( htaccess() ) ) === 'N4', $GLOBALS['__renders'] . ' renders' );
	remove_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );

	// T9-a: a block below WordPress's with the current hash still moves.
	site( general() );
	$block = substr( bytes( htaccess() ), 0, strpos( bytes( htaccess() ), '# BEGIN WordPress' ) );
	file_put_contents( htaccess(), $GLOBALS['__wp_block'] . $block );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	$moved = bytes( htaccess() );
	chk( 'admin_init: same hash but below WordPress\'s block -> moved before it', strpos( $moved, '# BEGIN JPKCom Post Filter' ) !== false && strpos( $moved, '# BEGIN JPKCom Post Filter' ) < strpos( $moved, '# BEGIN WordPress' ) );

	// No reject part any more (a verdict filter): the block goes.
	site( general() );
	$verdict = static fn( $v ) => $v;
	add_filter( 'jpkcom_postfilter_request_verdict', $verdict );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init: no reject part any more -> managed block removed', ! str_contains( bytes( htaccess() ), '# BEGIN JPKCom Post Filter' ) && str_contains( bytes( htaccess() ), '# BEGIN WordPress' ) );
	remove_filter( 'jpkcom_postfilter_request_verdict', $verdict );

	// T9-b: where the server positively does not read .htaccess (or is not
	// Apache), admin_init reads no option at all - the managed flag is not
	// autoloaded, so every read is a query on every admin page load.
	site( general() );
	$reads = [];
	foreach ( [ 'nginx/1.26.3', 'Caddy', 'FrankenPHP', 'ferron', 'LiteSpeed', '' ] as $software ) {
		$_SERVER['SERVER_SOFTWARE'] = $software;
		$GLOBALS['__option_reads']  = [];
		call( 'jpkcom_postfilter_htaccess_maybe_resync' );
		$reads[ $software === '' ? '(empty)' : $software ] = array_sum( $GLOBALS['__option_reads'] );
	}
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
	chk( 'admin_init on nginx, Caddy, FrankenPHP, Ferron, LiteSpeed, no SERVER_SOFTWARE: no option read (T9-b)', array_sum( $reads ) === 0 && function_exists( 'jpkcom_postfilter_htaccess_maybe_resync' ), (string) json_encode( $reads ) );
	$GLOBALS['__option_reads'] = [];
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init on Apache: the managed flag is read (the probe sees a read)', ( $GLOBALS['__option_reads']['jpkcom_postfilter_htaccess_managed'] ?? 0 ) > 0 );

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
	read_only();
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'R17 nginx, managed + unwritable, loosening: saved, no write, no error',
		$ok === true && $GLOBALS['__errors'] === [] && bytes( htaccess() ) === $was
		&& ( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 3 );

	foreach ( [ 'Apache/2.4.66 (Debian)', 'LiteSpeed' ] as $software ) {
		site( general() );
		$_SERVER['SERVER_SOFTWARE'] = $software;
		$was = bytes( htaccess() );
		read_only();
		$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		chk( "R17 {$software}, managed + unwritable, loosening: refused",
			$ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' && bytes( htaccess() ) === $was
			&& $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] === 2 );
	}
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
}

// =====================================================================================
section( 'WP-CLI and unknown servers (fix wave item 1, ruling R22)' );
// =====================================================================================

/**
 * Run $fn as php-cli under WP-CLI does: no SERVER_SOFTWARE, SCRIPT_FILENAME = the
 * wp binary. $subdir null keeps the fixture's home path; true/false switch to
 * core's get_home_path() with WordPress in /wp/ (home !== siteurl) or not.
 */
function as_cli( callable $fn, ?bool $subdir = null ): mixed {
	$software = $_SERVER['SERVER_SOFTWARE'] ?? null;
	$script   = $_SERVER['SCRIPT_FILENAME'] ?? null;
	unset( $_SERVER['SERVER_SOFTWARE'] );
	$_SERVER['SCRIPT_FILENAME'] = '/usr/local/bin/wp';
	$GLOBALS['__core_home_path'] = $subdir !== null;
	$GLOBALS['__wp_home']        = 'http://example.test';
	$GLOBALS['__wp_siteurl']     = $subdir ? 'http://example.test/wp' : 'http://example.test';
	try {
		return $fn();
	} finally {
		$GLOBALS['__core_home_path'] = false;
		$_SERVER['SERVER_SOFTWARE']  = $software ?? 'Apache/2.4.66 (Debian)';
		if ( $script === null ) {
			unset( $_SERVER['SCRIPT_FILENAME'] );
		} else {
			$_SERVER['SCRIPT_FILENAME'] = $script;
		}
	}
}

if ( $have ) {
	// home !== siteurl (WordPress in /wp/): core's get_home_path() reads SCRIPT_FILENAME,
	// which under WP-CLI is the wp binary - the path came out as '/.htaccess'.
	$file = as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_file' ), true );
	chk( 'CLI, home !== siteurl: the .htaccess of the site root, not /.htaccess', $file === htaccess(), (string) $file );
	$file = as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_file' ), false );
	chk( 'CLI, home === siteurl: ABSPATH . .htaccess', $file === ABSPATH . '.htaccess', (string) $file );
	$file = as_cli( static function () {
		$GLOBALS['__wp_siteurl'] = 'http://wp.example.test';   // no subdirectory relation at all
		return call( 'jpkcom_postfilter_htaccess_file' );
	}, true );
	chk( 'home and siteurl on different hosts: never /.htaccess (falls back to ABSPATH)', $file === ABSPATH . '.htaccess', (string) $file );

	// The path stored at Set up wins over any derivation while that file exists.
	site( general() );
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = htaccess();
	$file = as_cli( static function () {
		$GLOBALS['__wp_siteurl'] = 'http://wp.example.test';
		return call( 'jpkcom_postfilter_htaccess_file' );
	}, true );
	chk( 'stored path: used even where the derivation fails', $file === htaccess(), (string) $file );
	chk( 'stored path counts as managed', call( 'jpkcom_postfilter_htaccess_is_managed' ) === true );
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = $GLOBALS['__tmp'] . '/moved-away/.htaccess';
	chk( 'stored path whose file is gone (site moved): derived path instead', call( 'jpkcom_postfilter_htaccess_file' ) === htaccess() );

	// A stored path is only trusted in the site's own directories (WordPress
	// directory, its parent, the derived home path) - a tampered option must
	// not point the writes at another .htaccess on the host.
	foreach ( [
		'outside the site'          => $GLOBALS['__tmp'] . '/elsewhere/.htaccess',
		'inside a subdirectory'     => ABSPATH . 'wp-content/uploads/.htaccess',
		'traversal back out'        => ABSPATH . '../../elsewhere/.htaccess',
	] as $label => $foreign ) {
		@mkdir( dirname( $foreign ), 0777, true );
		file_put_contents( $foreign, "# foreign\n" );
		$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = $foreign;
		chk( 'stored path ' . $label . ': ignored, derived path instead', call( 'jpkcom_postfilter_htaccess_file' ) === htaccess(), (string) call( 'jpkcom_postfilter_htaccess_file' ) );
		@unlink( $foreign );
	}
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = ABSPATH . '.htaccess';
	if ( ! is_file( ABSPATH . '.htaccess' ) ) {
		file_put_contents( ABSPATH . '.htaccess', "# wp dir\n" );
		$made_wp_dir_file = true;
	}
	chk( 'stored path in the WordPress directory itself: used', call( 'jpkcom_postfilter_htaccess_file' ) === ABSPATH . '.htaccess' );
	if ( ! empty( $made_wp_dir_file ) ) {
		@unlink( ABSPATH . '.htaccess' );
	}
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = htaccess();

	// Empty SERVER_SOFTWARE (WP-CLI on an Apache site), managed and writable block.
	site( general() );
	$ok = as_cli( static fn() => save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => 5 ] ) ) );
	chk( 'CLI, managed + writable, loosening N 2->5: block loosened BEFORE the commit',
		$ok === true && n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N5'
		&& ( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 5,
		n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) );

	// Same, WordPress in a subdirectory: the real file at the site root follows.
	site( general() );
	$ok = as_cli( static fn() => save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => 4 ] ) ), true );
	chk( 'CLI, home !== siteurl, loosening: the site root .htaccess is loosened before the commit',
		$ok === true && n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N4' && ! is_file( '/.htaccess' ) );

	// Empty SERVER_SOFTWARE + read-only: unknown counts as reading .htaccess.
	site( general() );
	$was = bytes( htaccess() );
	read_only();
	$ok = as_cli( static fn() => save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => 5 ] ) ) );
	chk( 'CLI, managed + read-only, loosening: refused, option and file unchanged',
		$ok === false && bytes( htaccess() ) === $was && ( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 2 );

	foreach ( [ '', 'Microsoft-IIS/10.0', 'SomeProxy/1.0' ] as $software ) {
		site( general() );
		$_SERVER['SERVER_SOFTWARE'] = $software;
		read_only();
		$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		chk( "R22 unknown server '{$software}', managed + read-only, loosening: refused", $ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' );
	}
	foreach ( [ 'Caddy', 'FrankenPHP v1.4', 'ferron/3.0.0' ] as $software ) {
		site( general() );
		$_SERVER['SERVER_SOFTWARE'] = $software;
		read_only();
		$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		chk( "R22 '{$software}' (positively identified), managed + read-only, loosening: saved", $ok === true && $GLOBALS['__errors'] === [] );
	}
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

	// Writing looser is always safe: also on nginx, the block follows when it can.
	site( general() );
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'nginx, managed + writable, loosening: block loosened before the commit anyway',
		$ok === true && n_of( $GLOBALS['__commits'][0]['htaccess'] ?? '' ) === 'N3' );
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';

	// Deactivation under WP-CLI (`wp plugin deactivate`), WordPress in a subdirectory.
	site( general() );
	as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_deactivate' ), true );
	chk( 'CLI deactivate, home !== siteurl (option \'1\'): the real block is removed, then the option',
		bytes( htaccess() ) === $GLOBALS['__wp_block'] && ! array_key_exists( 'jpkcom_postfilter_htaccess_managed', $GLOBALS['__options'] ), n_of( bytes( htaccess() ) ) );

	site( general() );
	$GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] = htaccess();
	as_cli( static function () {
		$GLOBALS['__wp_siteurl'] = 'http://wp.example.test';
		call( 'jpkcom_postfilter_htaccess_deactivate' );
	}, true );
	chk( 'CLI deactivate with the stored path: the real block is removed',
		bytes( htaccess() ) === $GLOBALS['__wp_block'] && ! array_key_exists( 'jpkcom_postfilter_htaccess_managed', $GLOBALS['__options'] ) );

	// R161-3: a version change where SERVER_SOFTWARE is empty (WP-CLI - the only
	// PHP that still runs once a 1.6.0 block answers 500) or unknown rewrites a
	// managed block only when the new one is provably looser: it drops whole
	// reject rules and adds none. Fixture: the current block plus one reject
	// rule the new version no longer writes.
	$legacy = static function (): string {
		$unit = "RewriteCond %{ENV:REDIRECT_STATUS} ^$\nRewriteCond %{THE_REQUEST} \"^GET\\ /blog/filter/legacy/\"\nRewriteRule ^ - [E=JPKPF_REJECT:server-per-group,R=410,L]";
		$file = bytes( htaccess() );
		$at   = strpos( $file, "RewriteEngine On\n" );   // the managed block comes first; WordPress's has the line too
		file_put_contents( htaccess(), substr_replace( $file, "RewriteEngine On\n" . $unit . "\n", (int) $at, strlen( "RewriteEngine On\n" ) ) );
		return $file;
	};
	foreach ( [ 'CLI (no SERVER_SOFTWARE)' => null, 'unknown server (IIS)' => 'Microsoft-IIS/10.0' ] as $label => $software ) {
		site( general() );
		$expected = $legacy();
		if ( $software === null ) {
			as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_lifecycle' ) );
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $software;
			call( 'jpkcom_postfilter_htaccess_lifecycle' );
			$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
		}
		chk( "R161-3 {$label}: version change, block with a rule the new version drops -> rewritten without it",
			bytes( htaccess() ) === $expected && ! str_contains( bytes( htaccess() ), 'legacy' ), n_of( bytes( htaccess() ) ) );
	}

	site( general() );
	$expected = $legacy();
	$was      = bytes( htaccess() );
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.3';
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
	chk( 'R161-3 nginx (does not read .htaccess): untouched, as before', bytes( htaccess() ) === $was );

	site( general( [ 'strict_urls' => false ] ), null, true, false );
	jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'strict' => true ] ) ) );
	as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_lifecycle' ) );
	chk( 'R161-3 CLI: no reject part any more -> block removed', bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	// Anything else stays as it was - also a block that is in fact stricter
	// (N 1 against the settings' N 2): another pattern is not provable on the text.
	foreach ( [ 'stricter block (N1, settings N2)' => 1, 'looser block (N3, settings N2): never tightened here' => 3 ] as $label => $n ) {
		site( general() );
		jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => $n ] ) ) );
		$was = bytes( htaccess() );
		as_cli( static fn() => call( 'jpkcom_postfilter_htaccess_lifecycle' ) );
		chk( "R161-3 CLI, {$label}: untouched", bytes( htaccess() ) === $was && n_of( $was ) === 'N' . $n );
	}
}

// =====================================================================================
section( 'delete_option() of a settings option (I-2)' );
// =====================================================================================

if ( $have ) {
	// The cache file goes with the row (T2-b), and from then on the plugin runs
	// with what it falls back to - the defaults. The block has to be at least
	// that loose BEFORE the file goes: a delete cannot be refused.
	$at_cache_delete = null;
	foreach ( [ 'jpkcom_postfilter_general', 'jpkcom_postfilter_filter_groups' ] as $o ) {
		add_action( "delete_option_{$o}", static function () {
			$GLOBALS['__at_cache_delete'] = bytes( htaccess() );   // just before settings.php deletes the cache file (priority 10)
		}, 9, 0 );
	}

	foreach ( [ 1 => 'loosens', 3 => 'tightens' ] as $n => $label ) {
		site( general( [ 'max_filters_per_group' => $n ] ) );
		$GLOBALS['__at_cache_delete'] = null;
		$ok = delete_option( 'jpkcom_postfilter_general' );
		jpkcom_postfilter_settings_memo_reset();
		$now = jpkcom_postfilter_server_rule_inputs();
		chk( "I-2 delete General (stored N{$n}, defaults N2 = {$label}): block loosened to at least N2 before the cache file goes",
			$ok === true && in_array( n_of( (string) $GLOBALS['__at_cache_delete'] ), [ 'N' . max( 2, $n ), 'N0' ], true ),
			n_of( (string) $GLOBALS['__at_cache_delete'] ) );
		chk( "I-2 delete General (N{$n}): afterwards cache file gone, plugin on the defaults (N2), block == its rules",
			cache_array( 'general' ) === null && $now['max_per_group'] === 2 && rule_hash( bytes( htaccess() ) ) === $now['hash'] && ! array_key_exists( LOCK, $GLOBALS['__options'] ),
			n_of( bytes( htaccess() ) ) . ' / plugin N' . $now['max_per_group'] );
	}

	// Filter Groups: without the row there are no groups, so no reject part.
	site( general() );
	$GLOBALS['__at_cache_delete'] = null;
	delete_option( 'jpkcom_postfilter_filter_groups' );
	chk( 'I-2 delete Filter Groups: the block (no groups -> no reject part) is gone before the cache file goes, and stays gone',
		! str_contains( (string) $GLOBALS['__at_cache_delete'], 'R=410' ) && ! str_contains( bytes( htaccess() ), 'R=410' ) && cache_array( 'filter_groups' ) === null,
		(string) $GLOBALS['__at_cache_delete'] );

	// The block cannot be loosened: the plugin must not become looser than it,
	// so the cache file stays and the plugin goes on with the deleted values.
	site( general( [ 'max_filters_per_group' => 1 ] ) );
	$cache_was = cache_array( 'general' );
	$file_was  = bytes( htaccess() );
	read_only();
	delete_option( 'jpkcom_postfilter_general' );
	jpkcom_postfilter_settings_memo_reset();
	chk( 'I-2 delete General, block not writable: cache file kept (plugin stays on N1 = the block), block unchanged',
		cache_array( 'general' ) === $cache_was && bytes( htaccess() ) === $file_was && jpkcom_postfilter_server_rule_inputs()['max_per_group'] === 1
		&& ! array_key_exists( 'jpkcom_postfilter_general', $GLOBALS['__options'] ) && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// Not managed: nothing to loosen, the cache file goes as before (T2-b).
	site( general( [ 'max_filters_per_group' => 1 ] ), null, false, false );
	$GLOBALS['wpdb']->log = [];
	delete_option( 'jpkcom_postfilter_general' );
	chk( 'I-2 delete General without a managed block: cache file gone, no lock query', cache_array( 'general' ) === null && $GLOBALS['wpdb']->log === [] );
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
	read_only();
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
	read_only();
	$was = bytes( htaccess() );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	chk( 'deactivate, read-only file: block and option stay',
		bytes( htaccess() ) === $was && ( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] ?? '' ) === '1' );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'deactivate (read-only) -> reactivate -> loosening save on Apache: refused',
		$ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' && bytes( htaccess() ) === $was );

	// T9-e: an unreadable file. state() cannot see the block, which is not the
	// same as "no block": the option stays, and the block stays guarded.
	site( general() );
	read_only( 0000 );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	chk( 'deactivate, unreadable file: option kept', ( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] ?? '' ) === '1' );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'deactivate (unreadable) -> reactivate -> loosening save on Apache: refused', $ok === false && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' );

	site( general() );
	call( 'jpkcom_postfilter_htaccess_deactivate' );
	call( 'jpkcom_postfilter_htaccess_lifecycle' );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'deactivate (removable) -> reactivate -> loosening save: option gone, saved, nothing written',
		$ok === true && $GLOBALS['__errors'] === [] && ! isset( $GLOBALS['__options']['jpkcom_postfilter_htaccess_managed'] )
		&& bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	// An orphan: managed markers in the file, the option gone.
	site( general(), null, false, true );
	read_only();
	$was = bytes( htaccess() );
	$ok  = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'orphaned block, read-only, loosening save on Apache: refused', $ok === false && bytes( htaccess() ) === $was && ( $GLOBALS['__errors'][0]['type'] ?? '' ) === 'error' );

	site( general(), null, false, true );
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'orphaned block, writable: removed before the commit, saved',
		$ok === true && ! str_contains( $GLOBALS['__commits'][0]['htaccess'] ?? 'R=410', 'R=410' ) && bytes( htaccess() ) === $GLOBALS['__wp_block'] );

	site( general(), null, false, true );
	read_only();
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
section( 'Save lock: two saves at once (T9-d)' );
// =====================================================================================

/**
 * Run a save as request B (another admin) while request A is inside its own
 * save, and record what B got and whether B changed anything.
 */
function as_b( callable $save, string $option ): array {
	$errors = count( $GLOBALS['__errors'] );
	$file   = bytes( htaccess() );
	$stored = $GLOBALS['__options'][ $option ] ?? null;
	$cache  = cache_array( substr( $option, strlen( 'jpkcom_postfilter_' ) ) );
	$GLOBALS['__request_id'] = 'B';
	try {
		$ok = $save();
	} finally {
		$GLOBALS['__request_id'] = 'A';
	}
	return [
		'ok'        => $ok,
		'msg'       => (string) ( $GLOBALS['__errors'][ $errors ]['message'] ?? '' ),
		'unchanged' => bytes( htaccess() ) === $file && ( $GLOBALS['__options'][ $option ] ?? null ) === $stored
			&& cache_array( substr( $option, strlen( 'jpkcom_postfilter_' ) ) ) === $cache,
	];
}

$three_groups = [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ];

if ( $have ) {
	// B saves Filter Groups after A has loosened for N 2->5 and before A commits.
	// Without a lock B loosens from what A has not committed yet - union(N2 G2,
	// N2 G3) - over A's union, and A then commits N5 against a block with N2:
	// stricter than the plugin until A tightens.
	site( general() );
	$b = null;
	$GLOBALS['__after_loosen'] = static function () use ( &$b, $three_groups ) {
		$b = as_b( static fn() => update_option( 'jpkcom_postfilter_filter_groups', $three_groups ), 'jpkcom_postfilter_filter_groups' );
	};
	$ok       = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '5' ] ) );
	$a_commit = '';
	foreach ( $GLOBALS['__commits'] as $c ) {
		if ( $c['option'] === 'jpkcom_postfilter_general' ) {
			$a_commit = $c['htaccess'];
		}
	}
	chk( 'B (Filter Groups) between A\'s loosen and A\'s commit: refused with the "another save" text, B changed nothing',
		is_array( $b ) && $b['ok'] === false && $b['unchanged'] && str_contains( $b['msg'], 'another save' ), json_encode( $b ) );
	chk( 'A goes on: at A\'s commit the block is A\'s (N5) - never stricter than what A commits', $ok === true && n_of( $a_commit ) === 'N5', n_of( $a_commit ) );
	chk( 'A done: block N5, lock released', n_of( bytes( htaccess() ) ) === 'N5' && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );
	$GLOBALS['__request_id'] = 'B';
	$ok = update_option( 'jpkcom_postfilter_filter_groups', $three_groups );
	$GLOBALS['__request_id'] = 'A';
	chk( 'B again after A is done: saved, the block follows both (N5, 3 groups), lock released',
		$ok === true && rule_hash( bytes( htaccess() ) ) === jpkcom_postfilter_server_rule_inputs()['hash']
		&& jpkcom_postfilter_server_rule_inputs()['max_per_group'] === 5 && jpkcom_postfilter_server_rule_inputs()['groups'] === 3
		&& ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// B saves General after A has committed and before A tightens.
	site( general( [ 'max_filters_per_group' => 3 ] ) );
	$b = null;
	$GLOBALS['__after_commit'] = static function () use ( &$b ) {
		$b = as_b( static fn() => update_option( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '4' ] ) ), 'jpkcom_postfilter_general' );
	};
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	chk( 'B (General) between A\'s commit and A\'s tighten: refused, B changed nothing',
		is_array( $b ) && $b['ok'] === false && $b['unchanged'] && str_contains( $b['msg'], 'another save' ), json_encode( $b ) );
	chk( 'A tightens after all: block N2, lock released', $ok === true && n_of( bytes( htaccess() ) ) === 'N2' && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// B imports (settings_save writes the cache file itself) while A is between loosen and commit.
	site( general() );
	$b = null;
	$GLOBALS['__after_loosen'] = static function () use ( &$b, $three_groups ) {
		$b = as_b( static function () use ( $three_groups ) {
			jpkcom_postfilter_settings_save( 'filter_groups', $three_groups );
			return jpkcom_postfilter_htaccess_refused( 'jpkcom_postfilter_filter_groups' ) ? false : true;
		}, 'jpkcom_postfilter_filter_groups' );
	};
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '5' ] ) );
	chk( 'B (import) while A saves: refused, option, cache file and .htaccess unchanged',
		is_array( $b ) && $b['ok'] === false && $b['unchanged'], json_encode( $b ) );
	chk( 'A after the refused import: saved, lock released', $ok === true && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// A lock another request holds right now.
	site( general() );
	$GLOBALS['__options'][ LOCK ] = time() . '|X';
	$file_was   = bytes( htaccess() );
	$old_stored = $GLOBALS['__options']['jpkcom_postfilter_general'];
	$cache_was  = cache_array( 'general' );
	$ok         = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'lock held by another request: refused, option, cache file and .htaccess unchanged, their lock untouched',
		$ok === false && str_contains( (string) ( $GLOBALS['__errors'][0]['message'] ?? '' ), 'another save' )
		&& $GLOBALS['__options']['jpkcom_postfilter_general'] === $old_stored && cache_array( 'general' ) === $cache_was
		&& bytes( htaccess() ) === $file_was && ( $GLOBALS['__options'][ LOCK ] ?? '' ) === explode( '|', (string) ( $GLOBALS['__options'][ LOCK ] ?? '' ) )[0] . '|X',
		json_encode( [ $ok, $GLOBALS['__errors'] ] ) );

	// admin_init must not write between another request's loosen and tighten either.
	$cap = static fn( $v ) => 4;
	add_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init while another request holds the lock: no write', bytes( htaccess() ) === $file_was );
	unset( $GLOBALS['__options'][ LOCK ] );
	call( 'jpkcom_postfilter_htaccess_maybe_resync' );
	chk( 'admin_init once the lock is gone: synced (N4)', n_of( bytes( htaccess() ) ) === 'N4' );
	remove_filter( 'jpkcom_postfilter_max_filters_per_group', $cap );

	// A stale lock - its request died without running its shutdown functions.
	site( general() );
	$GLOBALS['__options'][ LOCK ] = ( time() - 61 ) . '|X';
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'stale lock (older than a minute): taken over, saved, released', $ok === true && n_of( bytes( htaccess() ) ) === 'N3' && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// Released on the other paths, too.
	site( general() );
	read_only();
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'refused save (read-only file): lock released', ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	site( general() );
	save( 'jpkcom_postfilter_general', general() );   // stores the sanitized form
	save( 'jpkcom_postfilter_general', general() );   // nothing changes: update_option() returns without a hook
	$held = (string) ( $GLOBALS['__options'][ LOCK ] ?? '' );
	call( 'jpkcom_postfilter_htaccess_save_unlock' );   // what the shutdown function runs
	chk( 'unchanged save: lock held by this request until shutdown, then released',
		str_ends_with( $held, '|A' ) && ! array_key_exists( LOCK, $GLOBALS['__options'] ), $held );

	site( general() );
	jpkcom_postfilter_settings_save( 'general', general() );
	jpkcom_postfilter_settings_save( 'general', general() );
	chk( 'unchanged import: released once the import is done', ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	site( general(), null, false, false );
	$GLOBALS['wpdb']->log = [];
	save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
	chk( 'not managed: no lock, not a single query', $GLOBALS['wpdb']->log === [] && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );

	// M-1: every other writer of the block waits for the holder too. While A
	// is between its loosen and its tighten, the block is A's union (here N5)
	// and this request may still read the values from before A's save (N2).
	$writers = [
		'version change (lifecycle -> sync)'   => static fn() => call( 'jpkcom_postfilter_htaccess_lifecycle' ),
		'update_option_page_for_posts'         => static fn() => do_action( 'update_option_page_for_posts', 0, 7, 'page_for_posts' ),
		'update_option_permalink_structure'    => static fn() => do_action( 'update_option_permalink_structure', '', '/%postname%/', 'permalink_structure' ),
		'permalink_structure_changed'          => static fn() => do_action( 'permalink_structure_changed', '', '/%postname%/' ),
		'jpkcom_postfilter_htaccess_sync()'    => static fn() => call( 'jpkcom_postfilter_htaccess_sync' ),
	];
	foreach ( $writers as $label => $write ) {
		site( general() );
		jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => 5 ] ) ) );
		$GLOBALS['__options'][ LOCK ] = time() . '|X';
		$file_was = bytes( htaccess() );
		$write();
		chk( "M-1 {$label} while another request holds the save lock: no write", bytes( htaccess() ) === $file_was, n_of( bytes( htaccess() ) ) );
		unset( $GLOBALS['__options'][ LOCK ] );
		$write();
		chk( "M-1 {$label} once the lock is gone: writes (N2)", n_of( bytes( htaccess() ) ) === 'N2', n_of( bytes( htaccess() ) ) );
	}

	// M-5: an import saves General and Filter Groups one after the other. It
	// takes the save lock once, before the first group, and keeps it until the
	// last: released in between, another save can take it, and the import is
	// refused half way - General imported, Filter Groups not.
	$import = static function ( array $data ): bool {   // jpkcom_postfilter_page_import_export(), its loop
		if ( function_exists( 'jpkcom_postfilter_htaccess_import_begin' ) && ! jpkcom_postfilter_htaccess_import_begin( $data, 'jpkcom_postfilter_import_messages' ) ) {
			return false;
		}
		try {
			foreach ( [ 'general', 'layout', 'cache', 'filter_groups' ] as $group ) {
				if ( isset( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
					if ( ! jpkcom_postfilter_htaccess_prepare_save( option: 'jpkcom_postfilter_' . $group, raw: $data[ $group ], setting: 'jpkcom_postfilter_import_messages' ) ) {
						continue;
					}
					jpkcom_postfilter_settings_write_file( $group, $data[ $group ] );   // the sanitizer's cache write
					jpkcom_postfilter_settings_memo_reset();
					jpkcom_postfilter_settings_save( $group, $data[ $group ] );
				}
			}
		} finally {
			if ( function_exists( 'jpkcom_postfilter_htaccess_import_end' ) ) {
				jpkcom_postfilter_htaccess_import_end();
			}
		}
		return true;
	};
	$data = [ 'general' => general( [ 'max_filters_per_group' => 3 ] ), 'filter_groups' => $three_groups ];

	site( general() );
	$x_got = null;
	$x     = static function ( string $group ) use ( &$x_got ): void {
		if ( $group === 'general' && $x_got === null ) {
			$GLOBALS['__request_id'] = 'X';
			$x_got = call( 'jpkcom_postfilter_htaccess_save_lock' );
			$GLOBALS['__request_id'] = 'A';
		}
	};
	add_action( 'jpkcom_postfilter_settings_saved', $x, 20, 1 );
	$import( $data );
	remove_action( 'jpkcom_postfilter_settings_saved', $x, 20 );
	chk( 'M-5 import: another save between General and Filter Groups finds the lock held (busy)', $x_got === 'busy', json_encode( $x_got ) );
	chk( 'M-5 import: not half-applied - General (N3) and Filter Groups (3 groups) both stored, no error',
		( $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null ) === 3
		&& count( $GLOBALS['__options']['jpkcom_postfilter_filter_groups']['groups'] ?? [] ) === 3 && $GLOBALS['__errors'] === [],
		json_encode( [ $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] ?? null, count( $GLOBALS['__options']['jpkcom_postfilter_filter_groups']['groups'] ?? [] ), $GLOBALS['__errors'] ] ) );
	chk( 'M-5 import done: block follows (N3, 3 groups), lock released',
		rule_hash( bytes( htaccess() ) ) === jpkcom_postfilter_server_rule_inputs()['hash'] && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );
	unset( $GLOBALS['__options'][ LOCK ] );

	// Busy before the first group: the whole import is refused, nothing stored.
	site( general() );
	$GLOBALS['__options'][ LOCK ] = time() . '|X';
	$before = $GLOBALS['__options'];
	$cache_was = [ cache_array( 'general' ), cache_array( 'filter_groups' ) ];
	$file_was  = bytes( htaccess() );
	$ran = $import( $data );
	chk( 'M-5 import while another save holds the lock: refused as a whole with the "another save" text, nothing stored',
		$ran === false && $GLOBALS['__options'] === $before && [ cache_array( 'general' ), cache_array( 'filter_groups' ) ] === $cache_was && bytes( htaccess() ) === $file_was
		&& count( $GLOBALS['__errors'] ) === 1 && str_contains( (string) ( $GLOBALS['__errors'][0]['message'] ?? '' ), 'another save' )
		&& ( $GLOBALS['__errors'][0]['setting'] ?? '' ) === 'jpkcom_postfilter_import_messages',
		json_encode( [ $ran, $GLOBALS['__errors'] ] ) );
	unset( $GLOBALS['__options'][ LOCK ] );

	// The page calls them: begin before the loop, end after it.
	$src      = (string) file_get_contents( __DIR__ . '/../includes/admin-pages.php' );
	$at_begin = strpos( $src, 'jpkcom_postfilter_htaccess_import_begin(' );
	$at_loop  = strpos( $src, 'foreach ( $valid_groups as $group )' );
	$at_end   = strpos( $src, 'jpkcom_postfilter_htaccess_import_end()' );
	chk( 'M-5 admin-pages.php: the import takes the lock before its loop and releases it after',
		$at_begin !== false && $at_loop !== false && $at_end !== false && $at_begin < $at_loop && $at_loop < $at_end );

	// M-6: a database error is not another request's lock. INSERT IGNORE turns
	// a duplicate key into a warning, so an error means the statement failed -
	// refused with its own reason, not "another save is running".
	foreach ( [ 'INSERT' => [ 'INSERT' ], 'INSERT + SELECT' => [ 'INSERT', 'SELECT' ] ] as $label => $fail ) {
		site( general() );
		$file_was  = bytes( htaccess() );
		$stored    = $GLOBALS['__options']['jpkcom_postfilter_general'];
		$cache_was = cache_array( 'general' );
		$GLOBALS['wpdb']->fail = $fail;
		$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '3' ] ) );
		$GLOBALS['wpdb']->fail = [];
		$msg = (string) ( $GLOBALS['__errors'][0]['message'] ?? '' );
		chk( "M-6 lock statement fails ({$label}): refused with the database reason, not 'another save'",
			$ok === false && str_contains( $msg, 'database' ) && ! str_contains( $msg, 'another save' ), $msg );
		chk( "M-6 ({$label}): option, cache file and .htaccess unchanged, no lock row",
			$GLOBALS['__options']['jpkcom_postfilter_general'] === $stored && cache_array( 'general' ) === $cache_was
			&& bytes( htaccess() ) === $file_was && ! array_key_exists( LOCK, $GLOBALS['__options'] ) );
	}

	// M-6: a lock another request has taken over (ours went stale) is not ours
	// any more; save_unlock() leaves its row and forgets that it held one.
	site( general() );
	call( 'jpkcom_postfilter_htaccess_save_lock' );
	$GLOBALS['__options'][ LOCK ] = time() . '|X';
	call( 'jpkcom_postfilter_htaccess_save_unlock' );
	chk( 'M-6 lock taken over by another request: unlock leaves its row and resets the taken flag',
		( $GLOBALS['__options'][ LOCK ] ?? '' ) === time() . '|X' && call( 'jpkcom_postfilter_htaccess_save_lock_taken' ) === false );
	$GLOBALS['wpdb']->log = [];
	call( 'jpkcom_postfilter_htaccess_save_unlock' );
	chk( 'M-6 after that, unlock on further option events sends no query', $GLOBALS['wpdb']->log === [] );
	unset( $GLOBALS['__options'][ LOCK ] );

	// I-1: B loaded its options before A committed (alloptions at request
	// start), so B's get_option() still says N5 after A stored N2. B's refused
	// save must not write that stale copy into the settings cache file - the
	// plugin reads the file before the database, and A's tighten then builds
	// the block from it.
	// General: A tightens N5 -> N2, B saves General from its N5 copy.
	site( general( [ 'max_filters_per_group' => 5 ] ) );
	$GLOBALS['__snapshots']['B'] = $GLOBALS['__options'];
	$b = null;
	$GLOBALS['__after_commit'] = static function () use ( &$b ) {
		$b = as_b( static fn() => update_option( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '5', 'seo_index_max_terms' => 2 ] ) ), 'jpkcom_postfilter_general' );
	};
	$ok = save( 'jpkcom_postfilter_general', general( [ 'max_filters_per_group' => '2' ] ) );
	unset( $GLOBALS['__snapshots'] );
	jpkcom_postfilter_settings_memo_reset();
	$cache = cache_array( 'general' );
	chk( 'I-1 General: B (options loaded before A\'s commit) refused while A holds the lock',
		$ok === true && is_array( $b ) && $b['ok'] === false && str_contains( $b['msg'], 'another save' ), json_encode( $b ) );
	chk( 'I-1 General: after B\'s refusal the settings cache file holds A\'s committed N2, not B\'s stale N5',
		( $cache['max_filters_per_group'] ?? null ) === 2 && $GLOBALS['__options']['jpkcom_postfilter_general']['max_filters_per_group'] === 2,
		'cache N' . json_encode( $cache['max_filters_per_group'] ?? null ) );
	chk( 'I-1 General: block N2 == the rules the plugin runs with',
		n_of( bytes( htaccess() ) ) === 'N2' && rule_hash( bytes( htaccess() ) ) === jpkcom_postfilter_server_rule_inputs()['hash'],
		'block ' . n_of( bytes( htaccess() ) ) . ', plugin N' . jpkcom_postfilter_server_rule_inputs()['max_per_group'] );

	// The other side of I-1: a cache file an ACCEPTED step of the same save
	// rewrote is still put back when a later step is refused. The import runs
	// prepare_save() and the sanitizer itself, then update_option() - here the
	// block changes in between and the file turns read-only.
	site( general() );
	$cache_was = cache_array( 'general' );
	$stored    = $GLOBALS['__options']['jpkcom_postfilter_general'];
	$raw       = general( [ 'max_filters_per_group' => 4 ] );
	jpkcom_postfilter_htaccess_prepare_save( 'jpkcom_postfilter_general', $raw, 'jpkcom_postfilter_import_messages' );
	jpkcom_postfilter_settings_write_file( 'general', $raw );   // the cache write of the import's own sanitizer call
	jpkcom_postfilter_settings_memo_reset();
	$wrote = ( cache_array( 'general' )['max_filters_per_group'] ?? null ) === 4;
	jpkcom_postfilter_htaccess_write( htaccess(), jpkcom_postfilter_htaccess_block( with( [ 'max_per_group' => 1 ] ) ) );
	read_only();
	jpkcom_postfilter_settings_save( 'general', $raw );
	chk( 'import step accepted, its update_option() refused: cache file back on the stored value, option unchanged',
		$wrote && cache_array( 'general' ) === $cache_was && $GLOBALS['__options']['jpkcom_postfilter_general'] === $stored
		&& ! array_key_exists( LOCK, $GLOBALS['__options'] ),
		json_encode( [ $wrote, cache_array( 'general' )['max_filters_per_group'] ?? null ] ) );

	// Filter Groups: A drops a group (3 -> 2, tighter), B saves Filter Groups from its 3-group copy.
	site( general(), $three_groups['groups'] );
	$GLOBALS['__snapshots']['B'] = $GLOBALS['__options'];
	$b = null;
	$GLOBALS['__after_commit'] = static function () use ( &$b, $three_groups ) {
		$b_raw                           = $three_groups;
		$b_raw['groups'][2]['label']     = 'Colour';
		$b = as_b( static fn() => update_option( 'jpkcom_postfilter_filter_groups', $b_raw ), 'jpkcom_postfilter_filter_groups' );
	};
	$ok = save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ) ] ] );
	unset( $GLOBALS['__snapshots'] );
	jpkcom_postfilter_settings_memo_reset();
	$cache = cache_array( 'filter_groups' );
	chk( 'I-1 Filter Groups: B (options loaded before A\'s commit) refused while A holds the lock',
		$ok === true && is_array( $b ) && $b['ok'] === false && str_contains( $b['msg'], 'another save' ), json_encode( $b ) );
	chk( 'I-1 Filter Groups: after B\'s refusal the settings cache file holds A\'s two groups, not B\'s stale three',
		count( (array) ( $cache['groups'] ?? [] ) ) === 2, 'cache groups ' . count( (array) ( $cache['groups'] ?? [] ) ) );
	chk( 'I-1 Filter Groups: block G2 == the rules the plugin runs with',
		rule_hash( bytes( htaccess() ) ) === jpkcom_postfilter_server_rule_inputs()['hash'] && jpkcom_postfilter_server_rule_inputs()['groups'] === 2,
		'block ' . g_of( bytes( htaccess() ) ) . ', plugin G' . jpkcom_postfilter_server_rule_inputs()['groups'] );
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

	// WP-CLI: a refusal says why (`wp option update` alone only says it could not update).
	site( general() );
	read_only();
	define( 'WP_CLI', true );
	$GLOBALS['__cli_warnings'] = [];
	// N is locked by the constant above: add a group instead.
	$ok = as_cli( static fn() => save( 'jpkcom_postfilter_filter_groups', [ 'groups' => [ group( 'category', 0 ), group( 'post_tag', 1 ), group( 'color', 2 ) ] ] ) );
	chk( 'WP-CLI refusal: WP_CLI::warning() carries the reason', $ok === false && str_contains( $GLOBALS['__cli_warnings'][0] ?? '', 'not writable' ), json_encode( [ $ok, $GLOBALS['__cli_warnings'], $GLOBALS['__errors'] ] ) );

	// I-2 under WP-CLI (`wp option delete`): a delete cannot be refused, so the
	// warning says the cache file was kept and why. (N is locked by the
	// constant above: delete the filter groups - without them there is no
	// reject part, and the block would have to go.)
	site( general() );
	$cache_was = cache_array( 'filter_groups' );
	read_only();
	$GLOBALS['__cli_warnings'] = [];
	as_cli( static fn() => delete_option( 'jpkcom_postfilter_filter_groups' ) );
	$warning = $GLOBALS['__cli_warnings'][0] ?? '';
	chk( 'I-2 wp option delete, block not writable: WP_CLI::warning() says the cache file was kept, and why',
		cache_array( 'filter_groups' ) === $cache_was && str_contains( $warning, 'jpkcom_postfilter_filter_groups' ) && str_contains( $warning, 'cache file' ) && str_contains( $warning, 'not-writable' ),
		$warning );
}

exit( summary() );
