<?php
/**
 * Tests for the managed .htaccess block (1.6.0, spec 7).
 *
 * Pure file operations on temporary files: placement directly before
 * `# BEGIN WordPress` (a block after WordPress's never takes effect), in-place
 * replacement, removal back to the exact bytes, line endings, BOM, a missing
 * trailing newline, the hands-off rule for a manual block, no write when
 * nothing changed, and the gates of jpkcom_postfilter_htaccess_available().
 *
 * Run with:
 *
 *     php tests/test-htaccess.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.6.0
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/jpkcom-post-filter-tests/' );
define( 'JPKCOM_POSTFILTER_VERSION', '1.6.0-test' );

$GLOBALS['__tmp']       = sys_get_temp_dir() . '/jpkpf-htaccess-' . getmypid();
$GLOBALS['__multisite'] = false;
$GLOBALS['__home_path'] = $GLOBALS['__tmp'] . '/home/';

@mkdir( $GLOBALS['__home_path'], 0755, true );

// --- WordPress stand-ins (only what the server rules and the manager call) --------

function __( $s, $d = '' ) { return $s; }
function get_bloginfo( $show = '' ) { return $show === 'language' ? 'en-US' : ''; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-%]/', '', (string) $t ) ); }
function get_option( $k, $d = false ) { return $d; }
function home_url( $path = '' ) { return 'https://example.test' . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function is_multisite() { return $GLOBALS['__multisite']; }
// Defined here, so the manager never needs wp-admin/includes/file.php.
function get_home_path() { return $GLOBALS['__home_path']; }

// --- the plugin -------------------------------------------------------------------

foreach ( [ 'server-rules.php', 'server-rules-render.php', 'htaccess-manager.php' ] as $file ) {
	if ( is_file( __DIR__ . '/../includes/' . $file ) ) {
		require_once __DIR__ . '/../includes/' . $file;
	}
}

// --- harness ----------------------------------------------------------------------

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

/**
 * Call a function that may not exist yet. A missing function fails the check
 * instead of ending the run.
 */
function call( string $fn, mixed ...$args ): mixed {
	return function_exists( $fn ) ? $fn( ...$args ) : '__missing__ ' . $fn;
}

/** Remove the temp tree (also on a failed run - a previous review found leftovers). */
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
			@rmdir( $f->getPathname() );
		} else {
			@chmod( $f->getPathname(), 0644 );
			@unlink( $f->getPathname() );
		}
	}
	@rmdir( $dir );
}
register_shutdown_function( 'cleanup' );

/** A fresh temp file with the given bytes. */
function fixture( string $name, string $content ): string {
	$f = $GLOBALS['__tmp'] . '/' . $name;
	file_put_contents( $f, $content );
	clearstatcache();
	return $f;
}

function bytes( string $f ): string {
	clearstatcache();
	return is_file( $f ) ? (string) file_get_contents( $f ) : '__no_file__';
}

/** Inputs as server_rule_inputs() would return them, for a fixed layout. */
function inputs( int $n = 3, array $over = [] ): array {
	$inputs = array_merge( [
		'strict'           => true,
		'max_per_group'    => $n,
		'groups'           => 2,
		'skip_positions'   => [],
		'reject_status'    => 410,
		'endpoint'         => 'filter',
		'fragment'         => 'jpkpf-fragment',
		'bases'            => [ '/blog/' ],
		'verdict_filtered' => false,
		'policy_filtered'  => [],
	], $over );
	$inputs['hash'] = (string) call( 'jpkcom_postfilter_server_rule_hash', $inputs );
	return $inputs;
}

$wp_block = "# BEGIN WordPress\n"
	. "# The directives (lines) between \"BEGIN WordPress\" and \"END WordPress\" are\n"
	. "# dynamically generated, and should only be modified via WordPress filters.\n"
	. "<IfModule mod_rewrite.c>\n"
	. "RewriteEngine On\n"
	. "RewriteRule ^index\\.php$ - [L]\n"
	. "RewriteCond %{REQUEST_FILENAME} !-f\n"
	. "RewriteCond %{REQUEST_FILENAME} !-d\n"
	. "RewriteRule . /index.php [L]\n"
	. "</IfModule>\n"
	. "\n"
	. "# END WordPress\n";

$head     = "Options -Indexes\n\n<Files xmlrpc.php>\nRequire all denied\n</Files>\n\n";
$tail     = "\n# custom tail\n";
$original = $head . $wp_block . $tail;

$inputs   = inputs( n: 3 );
$inputs_b = inputs( n: 5 );
$block    = (string) call( 'jpkcom_postfilter_htaccess_block', $inputs );
$block_b  = (string) call( 'jpkcom_postfilter_htaccess_block', $inputs_b );
// Every byte-equality check below also needs real blocks: without them nothing is written and "unchanged" would pass.
$have = str_starts_with( $block, '# BEGIN JPKCom Post Filter' ) && str_starts_with( $block_b, '# BEGIN JPKCom Post Filter' ) && $block !== $block_b;

// =====================================================================================
section( 'htaccess_block(): the managed block' );
// =====================================================================================

$rules = (string) call( 'jpkcom_postfilter_server_htaccess_rules', $inputs );
chk( 'block = managed markers + rule line + htaccess_rules()',
	$rules !== '' && $block === "# BEGIN JPKCom Post Filter\n# rule {$inputs['hash']}\n" . $rules . "\n# END JPKCom Post Filter", $block );
chk( 'block carries no manual marker', $have && ! str_contains( $block, '(manual)' ) );
chk( 'block differs from the copy snippet only in the markers',
	str_replace( 'JPKCom Post Filter (manual)', 'JPKCom Post Filter', (string) ( call( 'jpkcom_postfilter_server_snippets_htaccess', $inputs )[0]['code'] ?? '' ) ) === $block );
chk( 'block for other inputs carries the other hash', str_contains( $block_b, "# rule {$inputs_b['hash']}\n" ) && $inputs_b['hash'] !== $inputs['hash'] );
chk( 'no reject part (strict off): empty block', call( 'jpkcom_postfilter_htaccess_block', inputs( over: [ 'strict' => false ] ) ) === '' );

// =====================================================================================
section( 'write: placement before # BEGIN WordPress' );
// =====================================================================================

$f   = fixture( 'lf', $original );
$ok  = call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out = bytes( $f );
chk( 'write returns true', $ok === true, var_export( $ok, true ) );
chk( 'new block lands before # BEGIN WordPress',
	is_int( strpos( $out, '# BEGIN JPKCom Post Filter' ) ) && strpos( $out, '# BEGIN JPKCom Post Filter' ) < strpos( $out, '# BEGIN WordPress' ) );
chk( 'block directly before the WordPress line, everything else byte-equal', $out === $head . $block . "\n" . $wp_block . $tail, $out );

$st = call( 'jpkcom_postfilter_htaccess_state', $f );
chk( 'state reads hash', is_array( $st ) && $st['hash'] === $inputs['hash'], json_encode( $st ) );
chk( 'state: exists, writable, managed, wp_block, not manual',
	is_array( $st ) && $st['exists'] === true && $st['writable'] === true && $st['managed'] === true && $st['wp_block'] === true && $st['manual'] === false, json_encode( $st ) );

// Replace in place: different inputs, same position.
$before_ours    = substr( $out, 0, (int) strpos( $out, '# BEGIN JPKCom Post Filter' ) );
$wp_part_before = substr( $out, (int) strpos( $out, '# BEGIN WordPress' ) );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
$out2              = bytes( $f );
$after_ours_prefix = substr( $out2, 0, (int) strpos( $out2, '# BEGIN JPKCom Post Filter' ) );
$wp_part_after     = substr( $out2, (int) strpos( $out2, '# BEGIN WordPress' ) );
chk( 'replace in place keeps neighbours byte-equal', $have && str_contains( $out2, $block_b ) && $before_ours === $after_ours_prefix && $wp_part_before === $wp_part_after );
chk( 'replace: exactly one managed block, with the new hash',
	substr_count( $out2, "# BEGIN JPKCom Post Filter\n" ) === 1 && str_contains( $out2, $block_b ) && ! str_contains( $out2, "# rule {$inputs['hash']}" ), $out2 );
chk( 'state reads the new hash', ( call( 'jpkcom_postfilter_htaccess_state', $f )['hash'] ?? null ) === $inputs_b['hash'] );

$ok = call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'remove leaves file as before install', $ok === true && bytes( $f ) === $original, bytes( $f ) );
$st = call( 'jpkcom_postfilter_htaccess_state', $f );
chk( 'state after remove: not managed, no hash', is_array( $st ) && $st['managed'] === false && $st['hash'] === null, json_encode( $st ) );
chk( 'remove without a block: true, nothing changed', call( 'jpkcom_postfilter_htaccess_remove', $f ) === true && bytes( $f ) === $original );

// A block an admin moved elsewhere is replaced where it stands.
$moved = $block . "\n" . $head . $wp_block . $tail;
$f     = fixture( 'moved', $moved );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'existing block is replaced where it stands (not moved)', $have && bytes( $f ) === $block_b . "\n" . $head . $wp_block . $tail, bytes( $f ) );
chk( 'block above WordPress\'s (not adjacent): state not misplaced', ( call( 'jpkcom_postfilter_htaccess_state', $f )['misplaced'] ?? null ) === false );

// R15: a block after WordPress's never takes effect - write moves it directly before it.
$after_wp = $head . $wp_block . $block . "\n" . $tail;
$f        = fixture( 'after-wp', $after_wp );
$st       = call( 'jpkcom_postfilter_htaccess_state', $f );
chk( 'block after WordPress\'s: state misplaced, managed, hash', is_array( $st ) && ( $st['misplaced'] ?? null ) === true && $st['managed'] === true && $st['hash'] === $inputs['hash'], json_encode( $st ) );
$ok = call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'block after WordPress\'s: moved directly before # BEGIN WordPress, all other lines byte-identical',
	$have && $ok === true && bytes( $f ) === $head . $block_b . "\n" . $wp_block . $tail, bytes( $f ) );
chk( 'block after WordPress\'s: state not misplaced after write', ( call( 'jpkcom_postfilter_htaccess_state', $f )['misplaced'] ?? null ) === false );

// Same block content, only misplaced: still a write (the position is the change).
$f = fixture( 'after-wp-same', $after_wp );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
chk( 'misplaced block with unchanged rules is still moved', $have && bytes( $f ) === $head . $block . "\n" . $wp_block . $tail, bytes( $f ) );

// Misplaced at EOF without trailing newline, CRLF file.
$after_crlf = str_replace( "\n", "\r\n", $head . $wp_block ) . str_replace( "\n", "\r\n", $block );
$f          = fixture( 'after-wp-crlf', $after_crlf );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'misplaced at EOF in a CRLF file: moved, CRLF kept, file ends as WordPress\'s block did',
	$have && bytes( $f ) === str_replace( "\n", "\r\n", $head . $block_b . "\n" . $wp_block ), bytes( $f ) );

// One copy before, one after: the one before is kept in place, the one after dropped.
$f = fixture( 'before-and-after', $head . $block . "\n" . $wp_block . $block . "\n" . $tail );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'copies before and after WordPress\'s: one block left, before it', $have && bytes( $f ) === $head . $block_b . "\n" . $wp_block . $tail, bytes( $f ) );

// WordPress flushes rewrite rules later: only its own block changes, ours stays before it.
$f = fixture( 'wpflush', $original );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$wp_new = str_replace( 'RewriteRule . /index.php [L]', 'RewriteRule . /wp/index.php [L]', $wp_block );
file_put_contents( $f, str_replace( $wp_block, $wp_new, bytes( $f ) ) );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'after a WordPress flush the block is still directly before WordPress\'s', bytes( $f ) === $head . $block_b . "\n" . $wp_new . $tail );

// =====================================================================================
section( 'write: no WordPress block, BOM, CRLF, trailing newline, empty file' );
// =====================================================================================

$plain = "Options -Indexes\nDirectoryIndex index.php\n";
$f     = fixture( 'nowp', $plain );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out = bytes( $f );
chk( 'no WP block: block at top', str_starts_with( $out, '# BEGIN JPKCom Post Filter' ) && $out === $block . "\n" . $plain, $out );
chk( 'state: wp_block false', ( call( 'jpkcom_postfilter_htaccess_state', $f )['wp_block'] ?? null ) === false );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'no WP block: remove restores the file', $have && str_contains( $out, $block ) && bytes( $f ) === $plain );

$bom_original = "\xEF\xBB\xBF" . $plain;
$f            = fixture( 'bom', $bom_original );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out_bom = bytes( $f );
chk( 'BOM preserved', str_starts_with( $out_bom, "\xEF\xBB\xBF" ) && $out_bom === "\xEF\xBB\xBF" . $block . "\n" . $plain, $out_bom );
chk( 'BOM: one BOM only', str_contains( $out_bom, $block ) && substr_count( $out_bom, "\xEF\xBB\xBF" ) === 1 );
chk( 'BOM: state still sees the block', ( call( 'jpkcom_postfilter_htaccess_state', $f )['hash'] ?? null ) === $inputs['hash'] );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'BOM: remove restores the file', str_contains( $out_bom, $block ) && bytes( $f ) === $bom_original );

$bom_wp = "\xEF\xBB\xBF" . $wp_block;
$f      = fixture( 'bomwp', $bom_wp );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
chk( 'BOM + WordPress block on the first line: BOM, then ours, then WordPress\'s', bytes( $f ) === "\xEF\xBB\xBF" . $block . "\n" . $wp_block, bytes( $f ) );

$crlf_original = str_replace( "\n", "\r\n", $original );
$f             = fixture( 'crlf', $crlf_original );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out_crlf = bytes( $f );
chk( 'CRLF file stays CRLF', str_contains( $out_crlf, '# BEGIN JPKCom Post Filter' ) && ! preg_match( "/(?<!\r)\n/", $out_crlf ) );
chk( 'CRLF: block placed before WordPress\'s', $out_crlf === str_replace( "\n", "\r\n", $head . $block . "\n" . $wp_block . $tail ) );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'CRLF: replace stays CRLF', str_contains( bytes( $f ), $inputs_b['hash'] ) && ! preg_match( "/(?<!\r)\n/", bytes( $f ) ) );
chk( 'CRLF: state reads hash', ( call( 'jpkcom_postfilter_htaccess_state', $f )['hash'] ?? null ) === $inputs_b['hash'] );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'CRLF: remove restores the file', str_contains( $out_crlf, '# BEGIN JPKCom Post Filter' ) && bytes( $f ) === $crlf_original );

$nonl_original = $head . rtrim( $wp_block, "\n" );
$f             = fixture( 'nonl', $nonl_original );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out = bytes( $f );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'no trailing newline handled', $out === $head . $block . "\n" . rtrim( $wp_block, "\n" ) && bytes( $f ) === $nonl_original, $out );

$f = fixture( 'nonl-nowp', 'Options -Indexes' );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out = bytes( $f );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'no trailing newline, no WP block: block on top, remove restores', $out === $block . "\nOptions -Indexes" && bytes( $f ) === 'Options -Indexes', $out );

// A block at the end of a file without trailing newline is replaced without adding one.
$f = fixture( 'nonl-end', $plain . $block );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'block at EOF without newline: replaced in place, still no trailing newline', $have && bytes( $f ) === $plain . $block_b, bytes( $f ) );

$f = fixture( 'empty', '' );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
$out = bytes( $f );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'empty file: block + newline, remove empties it again', $out === $block . "\n" && bytes( $f ) === '', $out );

// =====================================================================================
section( 'write: no rename, no needless write, refusals' );
// =====================================================================================

$f = fixture( 'inode', $original );
chmod( $f, 0640 );
clearstatcache();
$inode = fileinode( $f );
call( 'jpkcom_postfilter_htaccess_write', $f, $block );
clearstatcache();
chk( 'written in place: same inode, same permissions', fileinode( $f ) === $inode && ( fileperms( $f ) & 0777 ) === 0640 && str_contains( bytes( $f ), $block ) );

touch( $f, time() - 1000 );
clearstatcache();
$mtime = filemtime( $f );
$same  = bytes( $f );
$ok    = call( 'jpkcom_postfilter_htaccess_write', $f, $block );
clearstatcache();
chk( 'unchanged content: mtime unchanged', $ok === true && filemtime( $f ) === $mtime && bytes( $f ) === $same, (string) filemtime( $f ) . ' vs ' . $mtime );
call( 'jpkcom_postfilter_htaccess_remove', $f );
clearstatcache();
chk( 'remove did write (mtime moved)', filemtime( $f ) !== $mtime && bytes( $f ) === $original );

$missing = $GLOBALS['__tmp'] . '/missing';
chk( 'missing file: write refuses and creates nothing', call( 'jpkcom_postfilter_htaccess_write', $missing, $block ) === false && ! file_exists( $missing ) );
chk( 'missing file: state exists false', ( call( 'jpkcom_postfilter_htaccess_state', $missing )['exists'] ?? null ) === false );

$f = fixture( 'badblock', $original );
chk( 'empty block refused, file unchanged', call( 'jpkcom_postfilter_htaccess_write', $f, '' ) === false && bytes( $f ) === $original );
chk( 'block without managed markers refused', call( 'jpkcom_postfilter_htaccess_write', $f, "RewriteEngine On\n" ) === false && bytes( $f ) === $original );
chk( 'manual-marked block refused', call( 'jpkcom_postfilter_htaccess_write', $f, str_replace( 'JPKCom Post Filter', 'JPKCom Post Filter (manual)', $block ) ) === false && bytes( $f ) === $original );
chk( 'block with a marker inside refused',
	call( 'jpkcom_postfilter_htaccess_write', $f, "# BEGIN JPKCom Post Filter\n# END JPKCom Post Filter\nX\n# END JPKCom Post Filter" ) === false && bytes( $f ) === $original );

// Manual block: the plugin never touches it.
$manual_block    = str_replace( 'JPKCom Post Filter', 'JPKCom Post Filter (manual)', $block );
$manual_original = $head . $manual_block . "\n" . $wp_block . $tail;
$f_manual        = fixture( 'manual', $manual_original );
$st              = call( 'jpkcom_postfilter_htaccess_state', $f_manual );
chk( 'manual block: state manual, not managed (prefix not confused)', is_array( $st ) && $st['manual'] === true && $st['managed'] === false && $st['hash'] === null, json_encode( $st ) );
chk( 'manual block: write refuses', call( 'jpkcom_postfilter_htaccess_write', $f_manual, $block ) === false && file_get_contents( $f_manual ) === $manual_original );
call( 'jpkcom_postfilter_htaccess_remove', $f_manual );
chk( 'manual block: remove leaves it alone', $have && bytes( $f_manual ) === $manual_original );

// Both: a managed block next to a manual one is removed, the manual one stays.
$both = $head . $manual_block . "\n" . $block . "\n" . $wp_block . $tail;
$f    = fixture( 'both', $both );
chk( 'managed + manual: write refuses', call( 'jpkcom_postfilter_htaccess_write', $f, $block_b ) === false && bytes( $f ) === $both );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'managed + manual: remove takes only the managed block', bytes( $f ) === $manual_original, bytes( $f ) );

// Damaged managed block (BEGIN without END): no guessing where it ends.
$damaged = $head . "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteEngine On\n" . $wp_block . $tail;
$f       = fixture( 'damaged', $damaged );
chk( 'damaged block: write refuses, file unchanged', call( 'jpkcom_postfilter_htaccess_write', $f, $block ) === false && bytes( $f ) === $damaged );
chk( 'damaged block: remove refuses, file unchanged', call( 'jpkcom_postfilter_htaccess_remove', $f ) === false && bytes( $f ) === $damaged );
$st = call( 'jpkcom_postfilter_htaccess_state', $f );
chk( 'damaged block: state managed false, damaged true', is_array( $st ) && $st['managed'] === false && ( $st['damaged'] ?? null ) === true, json_encode( $st ) );

// T8-e: the other two ways managed markers fail to pair up. Each guard in
// parse() is load-bearing here: without the END-without-BEGIN guard the stray
// END leaves `damaged` false and no block, so write() inserts a second block
// before WordPress's and remove() reports success - bytes or return value
// change. Without the BEGIN-inside-a-block guard, BEGIN BEGIN END reads as one
// block from the second BEGIN, and write()/remove() leave the first BEGIN line
// behind. Either removal turns these checks red.
$marker_cases = [
	'END without BEGIN' => $head . "# END JPKCom Post Filter\n" . $wp_block . $tail,
	'BEGIN inside a block (nested)' => $head . "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\n# BEGIN JPKCom Post Filter\nRewriteEngine On\n# END JPKCom Post Filter\n" . $wp_block . $tail,
];
foreach ( $marker_cases as $case => $content ) {
	$f  = fixture( 'markers-' . md5( $case ), $content );
	$st = call( 'jpkcom_postfilter_htaccess_state', $f );
	chk( "{$case}: state damaged, not managed",
		is_array( $st ) && ( $st['damaged'] ?? null ) === true && ( $st['managed'] ?? null ) === false, json_encode( $st ) );
	chk( "{$case}: write refuses, file unchanged", call( 'jpkcom_postfilter_htaccess_write', $f, $block ) === false && bytes( $f ) === $content );
	chk( "{$case}: remove refuses, file unchanged", call( 'jpkcom_postfilter_htaccess_remove', $f ) === false && bytes( $f ) === $content );
}

// A managed block that spans WordPress's markers (fix wave A2). C1: what an admin
// gets who adds the missing END at the bottom after WordPress appended its own
// block; C2: the other interleaving. Splicing [BEGIN JPK .. END JPK] would take
// WordPress's rewrite rules (or its END marker and </IfModule>) with it.
$wp_lines = [ 'C1' => $head . "# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteEngine On\n" . $wp_block . "# END JPKCom Post Filter\n" . $tail,
	'C2' => $head . "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n# BEGIN JPKCom Post Filter\n# rule 0123456789ab\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n# END JPKCom Post Filter\n" . $tail ];
foreach ( $wp_lines as $case => $content ) {
	$f  = fixture( 'span-' . $case, $content );
	$st = call( 'jpkcom_postfilter_htaccess_state', $f );
	chk( "{$case} managed block spanning a WordPress marker: state damaged, not managed",
		is_array( $st ) && ( $st['damaged'] ?? null ) === true && ( $st['managed'] ?? null ) === false, json_encode( $st ) );
	chk( "{$case}: write refuses, WordPress's block untouched", call( 'jpkcom_postfilter_htaccess_write', $f, $block ) === false && bytes( $f ) === $content );
	chk( "{$case}: remove refuses, WordPress's block untouched", call( 'jpkcom_postfilter_htaccess_remove', $f ) === false && bytes( $f ) === $content );
}

// Two managed blocks (e.g. a copy pasted twice): write keeps one, remove takes both.
$twice = $block . "\n" . $head . $block . "\n" . $wp_block . $tail;
$f     = fixture( 'twice', $twice );
call( 'jpkcom_postfilter_htaccess_write', $f, $block_b );
chk( 'two managed blocks: write leaves one, in the first position', bytes( $f ) === $block_b . "\n" . $head . $wp_block . $tail, bytes( $f ) );
$f = fixture( 'twice2', $twice );
call( 'jpkcom_postfilter_htaccess_remove', $f );
chk( 'two managed blocks: remove takes both', bytes( $f ) === $head . $wp_block . $tail, bytes( $f ) );

// =====================================================================================
section( 'write: what Apache can read in between (fix wave items 11, 15)' );
// =====================================================================================

/**
 * A file in memory that records the content after every write() and
 * ftruncate(), and the mode it was opened with - what a concurrent Apache
 * request could read at that moment.
 */
final class Jpkpf_Recording_Stream {
	public static array $files = [];
	public static array $snaps = [];
	public static array $modes = [];
	/**
	 * What the next stream_write() calls do, one entry per call: 'half' writes
	 * the first half of the data, 'fail' writes nothing, 'notice' raises an
	 * E_USER_NOTICE (as PHP does for ENOSPC on a plain file) and writes
	 * nothing. Once the list is used up, every write succeeds in full.
	 */
	public static array $plan = [];
	/** stream_truncate() raises an E_USER_NOTICE and fails. */
	public static bool $truncate_fails = false;
	public $context;
	private string $path = '';
	private int $pos = 0;

	public function stream_open( $path, $mode, $options, &$opened ): bool {
		self::$modes[] = $mode;
		if ( ! isset( self::$files[ $path ] ) && ! str_contains( $mode, 'c' ) && ! str_contains( $mode, 'w' ) ) {
			return false;
		}
		self::$files[ $path ] ??= '';
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
		if ( $step === 'notice' ) {
			trigger_error( 'fwrite(): Write of ' . strlen( $data ) . ' bytes failed with errno=28 No space left on device', E_USER_NOTICE );
			return 0;
		}
		if ( $step === 'fail' ) {
			return 0;
		}
		if ( $step === 'half' ) {
			$data = substr( $data, 0, intdiv( strlen( $data ), 2 ) );
		}
		$f                         = self::$files[ $this->path ];
		$f                         = str_pad( substr( $f, 0, $this->pos ), $this->pos, "\0" ) . $data . (string) substr( $f, $this->pos + strlen( $data ) );
		self::$files[ $this->path ] = $f;
		$this->pos                += strlen( $data );
		self::$snaps[]             = $f;
		return strlen( $data );
	}
	public function stream_truncate( int $size ): bool {
		if ( self::$truncate_fails ) {
			trigger_error( 'ftruncate(): Truncate failed', E_USER_NOTICE );
			return false;
		}
		self::$files[ $this->path ] = substr( self::$files[ $this->path ], 0, $size );
		self::$snaps[]             = self::$files[ $this->path ];
		return true;
	}
	public function stream_eof(): bool { return $this->pos >= strlen( self::$files[ $this->path ] ); }
	public function stream_tell(): int { return $this->pos; }
	public function stream_seek( $offset, $whence ): bool { $this->pos = $whence === SEEK_SET ? (int) $offset : $this->pos; return true; }
	public function stream_flush(): bool { return true; }
	public function stream_lock( $operation ): bool { return true; }
	public function stream_close(): void {}
	public function stream_stat(): array { return [ 'mode' => 0100644, 'size' => strlen( self::$files[ $this->path ] ) ]; }
	public function url_stat( $path, $flags ) { return isset( self::$files[ $path ] ) ? [ 'mode' => 0100644, 'size' => strlen( self::$files[ $path ] ) ] : false; }
	public function stream_metadata( $path, $option, $value ): bool { return false; }
	public function stream_set_option( $option, $arg1, $arg2 ): bool { return false; }
}
stream_wrapper_register( 'jpkpfmem', Jpkpf_Recording_Stream::class );

/** Every byte after $new is a '#' comment line or a blank line: nothing Apache could misread. */
function only_comments_after( string $snap, string $new ): bool {
	return str_starts_with( $snap, $new ) && preg_match( '/\A(?:#[^\n]*)?(?:\n(?:#[^\n]*)?)*\z/', substr( $snap, strlen( $new ) ) ) === 1;
}

// Shrinking: a long managed block is replaced by a short one (N 5 -> 3 here,
// plus a long custom line in the old block's place to make the old tail long).
$long_old = $head . $block_b . "\n" . $wp_block . $tail . str_repeat( "RewriteRule ^old-tail-$ - [F]\n", 40 );
foreach ( [ 'with trailing newline' => '', 'without trailing newline' => 'x' ] as $label => $cut ) {
	$old = $cut === '' ? $long_old : rtrim( $long_old, "\n" );
	Jpkpf_Recording_Stream::$files = [ 'jpkpfmem://ht' => $old ];
	Jpkpf_Recording_Stream::$snaps = [];
	Jpkpf_Recording_Stream::$modes = [];
	$new   = $cut === '' ? $head . $block . "\n" . $wp_block : rtrim( $head . $block . "\n" . $wp_block, "\n" );
	$ok    = call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://ht', static fn( string $c ): string => $new );
	$snaps = Jpkpf_Recording_Stream::$snaps;
	$valid = $snaps !== [];
	foreach ( $snaps as $snap ) {
		$valid = $valid && only_comments_after( $snap, $new ) && ! str_contains( $snap, 'old-tail' );
	}
	chk( "shrinking write ({$label}): every intermediate state is the new content plus comment lines - no old tail, no partial directive",
		$ok === true && $valid, json_encode( array_map( static fn( $s ) => substr( $s, -60 ), $snaps ) ) );
	chk( "shrinking write ({$label}): the file ends as exactly the new content", ( Jpkpf_Recording_Stream::$files['jpkpfmem://ht'] ?? '' ) === $new );
	chk( "shrinking write ({$label}): the file was opened with 'r+' (never creates it)", Jpkpf_Recording_Stream::$modes === [ 'r+' ], json_encode( Jpkpf_Recording_Stream::$modes ) );
}

$p = (string) call( 'jpkcom_postfilter_htaccess_padded', "a\nb\n", 200 );
chk( 'padded(): exactly the old length, comment lines of at most 80 bytes',
	strlen( $p ) === 200 && only_comments_after( $p, "a\nb\n" ) && max( array_map( 'strlen', explode( "\n", $p ) ) ) <= 80, json_encode( $p ) );
chk( 'padded(): no trailing newline - the padding starts on a new line', only_comments_after( (string) call( 'jpkcom_postfilter_htaccess_padded', "a\nb", 7 ), "a\nb" ) && strlen( (string) call( 'jpkcom_postfilter_htaccess_padded', "a\nb", 7 ) ) === 7 && str_starts_with( substr( (string) call( 'jpkcom_postfilter_htaccess_padded', "a\nb", 7 ), 3 ), "\n" ) );
chk( 'padded(): one byte short, and growing or equal content stays as it is',
	call( 'jpkcom_postfilter_htaccess_padded', "a\n", 3 ) === "a\n\n" && call( 'jpkcom_postfilter_htaccess_padded', "a", 2 ) === "a\n"
	&& call( 'jpkcom_postfilter_htaccess_padded', "abc\n", 2 ) === "abc\n" && call( 'jpkcom_postfilter_htaccess_padded', "abc\n", 4 ) === "abc\n" );

// T8-e: the rollback. The first write gets half of the new content into the
// file and the next ones fail (PHP's stream layer retries a short write within
// the same fwrite(), so that takes two 'fail' entries before put() sees one),
// so put() gives up with the file holding a mix of new and old bytes.
// modify() must put the old content back - without the rollback put() the
// file would end as that mix and the byte check fails.
$roll_old = $head . $block_b . "\n" . $wp_block . $tail;
$roll_new = $head . $block . "\n" . $wp_block . $tail . str_repeat( "# longer than before\n", 20 );
Jpkpf_Recording_Stream::$files = [ 'jpkpfmem://roll' => $roll_old ];
Jpkpf_Recording_Stream::$plan  = [ 'half', 'fail', 'fail' ];
$ok = call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://roll', static fn( string $c ): string => $roll_new );
chk( 'failed write half way: modify() false, the file is byte-identical to the original (rollback)',
	$ok === false && ( Jpkpf_Recording_Stream::$files['jpkpfmem://roll'] ?? '' ) === $roll_old && Jpkpf_Recording_Stream::$plan === [],
	json_encode( [ $ok, substr( Jpkpf_Recording_Stream::$files['jpkpfmem://roll'] ?? '', -60 ) ] ) );
Jpkpf_Recording_Stream::$plan = [];
chk( 'failed write, old content back: last_error() is write-failed', call( 'jpkcom_postfilter_htaccess_last_error' ) === 'write-failed', (string) call( 'jpkcom_postfilter_htaccess_last_error' ) );

// T8-a: the rollback fails as well. The file now holds half the new content
// over the old one - possibly half a directive, and Apache answers 500 - so
// the caller must be able to tell this from a write that changed nothing.
Jpkpf_Recording_Stream::$files = [ 'jpkpfmem://roll' => $roll_old ];
Jpkpf_Recording_Stream::$plan  = [ 'half', 'fail', 'fail', 'fail' ];
$ok = call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://roll', static fn( string $c ): string => $roll_new );
chk( 'failed write, failed rollback: modify() false, last_error() is rollback-failed',
	$ok === false && call( 'jpkcom_postfilter_htaccess_last_error' ) === 'rollback-failed' && ( Jpkpf_Recording_Stream::$files['jpkpfmem://roll'] ?? '' ) !== $roll_old,
	json_encode( [ $ok, call( 'jpkcom_postfilter_htaccess_last_error' ) ] ) );
Jpkpf_Recording_Stream::$plan  = [];
Jpkpf_Recording_Stream::$files = [ 'jpkpfmem://roll' => $roll_old ];
$ok = call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://roll', static fn( string $c ): string => $roll_new );
chk( 'a write that succeeds clears last_error()', $ok === true && call( 'jpkcom_postfilter_htaccess_last_error' ) === '' );

// T8-b: on a plain file a failed fwrite() raises E_NOTICE ("Write of N bytes
// failed with errno=28 No space left on device"). With display_errors on that
// notice is output before the redirect of the Set up / Remove action, which
// then fails with "headers already sent". The failure must still come back as
// false - only the output goes.
/** Run modify() with display_errors on; returns [ result, output ]. */
function modify_quietly( string $file, string $new ): array {
	$display = ini_set( 'display_errors', '1' );
	$level   = error_reporting( E_ALL );
	ob_start();
	try {
		$ok = call( 'jpkcom_postfilter_htaccess_modify', $file, static fn( string $c ): string => $new );
	} finally {
		$out = (string) ob_get_clean();
		error_reporting( $level );
		ini_set( 'display_errors', (string) $display );
	}
	return [ $ok, $out ];
}
Jpkpf_Recording_Stream::$files = [ 'jpkpfmem://full' => $roll_old ];
Jpkpf_Recording_Stream::$plan  = [ 'notice', 'notice', 'notice', 'notice' ];
[ $ok, $out ] = modify_quietly( 'jpkpfmem://full', $roll_new );
chk( 'fwrite() fails with a notice: modify() false, nothing printed before the redirect headers, file unchanged',
	$ok === false && $out === '' && ( Jpkpf_Recording_Stream::$files['jpkpfmem://full'] ?? '' ) === $roll_old, json_encode( [ $ok, $out ] ) );
Jpkpf_Recording_Stream::$plan = [];

Jpkpf_Recording_Stream::$files          = [ 'jpkpfmem://full' => $roll_old ];
Jpkpf_Recording_Stream::$truncate_fails = true;
[ $ok, $out ] = modify_quietly( 'jpkpfmem://full', $head . $wp_block );
Jpkpf_Recording_Stream::$truncate_fails = false;
chk( 'ftruncate() fails with a notice: modify() false, nothing printed', $ok === false && $out === '', json_encode( [ $ok, $out ] ) );

Jpkpf_Recording_Stream::$files = [];
Jpkpf_Recording_Stream::$modes = [];
chk( 'missing file: nothing created', call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://none', static fn( string $c ): string => 'x' ) === false && Jpkpf_Recording_Stream::$files === [] );

// =====================================================================================
section( 'provably looser (R161-3)' );
// =====================================================================================

/** The reject-rule unit (three lines) for a reason and pattern, as server_apache_rules() writes it. */
function reject_unit( string $reason, string $pattern, int $status = 410 ): string {
	return "RewriteCond %{ENV:REDIRECT_STATUS} ^\$\nRewriteCond %{THE_REQUEST} \"{$pattern}\"\nRewriteRule ^ - [E=JPKPF_REJECT:server-{$reason},R={$status},L]";
}

/** $block with $unit inserted after the line that starts with $after. */
function with_unit( string $block, string $unit, string $after ): string {
	$lines = explode( "\n", $block );
	foreach ( $lines as $i => $line ) {
		if ( str_starts_with( $line, $after ) ) {
			array_splice( $lines, $i + 1, 0, [ $unit ] );
			return implode( "\n", $lines );
		}
	}
	return '__no_anchor__';
}

/** $block with every 12-hex rule hash replaced. */
function rehashed( string $block, string $hash ): string {
	return (string) preg_replace( '/(# rule |X-Jpkpf-Rule ")[0-9a-f]{12}/', '${1}' . $hash, $block );
}

$looser = static fn( string $content, string $new ): mixed => call( 'jpkcom_postfilter_htaccess_provably_looser', $content, $new );
$file_with = static fn( string $b ): string => $head . $b . "\n" . $wp_block . $tail;

// The case R161-3 is about: a 1.6.0 block whose per-group rule the size guard
// now drops (N 20, 71 groups: PCRE cannot compile it - the file answers 500).
$big     = inputs( 20, [ 'groups' => 71 ] );
$dropped = (array) call( 'jpkcom_postfilter_server_patterns_dropped', $big );
$new_big = (string) call( 'jpkcom_postfilter_htaccess_block', $big );
$old_big = with_unit( $new_big, reject_unit( 'per-group', (string) ( call( 'jpkcom_postfilter_server_reject_patterns_unchecked', call( 'jpkcom_postfilter_server_render_inputs', $big ), 'request_line' )['per_group'] ?? 'x' ) ), 'RewriteRule ^ - [E=JPKPF_REJECT:server-segments' );
chk( 'fixture: N20 G71 drops per_group only, the new block keeps the segments rule',
	isset( $dropped['per_group'] ) && ! isset( $dropped['segments'] ) && str_contains( $new_big, 'server-segments' ) && ! str_contains( $new_big, 'server-per-group' ) && str_contains( $old_big, 'server-per-group' ),
	json_encode( $dropped ) );
chk( '1.6.0 block with the rule the guard now drops -> the new block is provably looser', $looser( $file_with( $old_big ), $new_big ) === true );
chk( 'same, and the rule hash changed (R161-4 folds dropped rules into it) -> still provably looser',
	$looser( $file_with( rehashed( $old_big, 'abcdefabcdef' ) ), $new_big ) === true );
chk( 'identical block -> not stricter', $looser( $file_with( $block ), $block ) === true );
chk( 'no reject part any more (block removed) -> looser', $looser( $file_with( $block ), '' ) === true );

$extra = reject_unit( 'legacy', '^GET /blog/filter/legacy' );
chk( 'the new block adds a rule -> not provable', $looser( $file_with( $block ), with_unit( $block, $extra, 'RewriteEngine On' ) ) === false );
chk( 'another N (2 -> 3): patterns differ -> not provable, even though N 3 is looser',
	$looser( $file_with( (string) call( 'jpkcom_postfilter_htaccess_block', inputs( 2 ) ) ), (string) call( 'jpkcom_postfilter_htaccess_block', inputs( 3 ) ) ) === false );
chk( 'another reject status (410 -> 404) next to a dropped rule -> not provable',
	$looser( $file_with( with_unit( $block, $extra, 'RewriteEngine On' ) ), (string) call( 'jpkcom_postfilter_htaccess_block', inputs( 3, [ 'reject_status' => 404 ] ) ) ) === false );
// The ErrorDocument text is translated in the locale of whoever wrote the
// block; a body cannot make a rule stricter, so it is compared as directive
// and status with any quoted text (ruling after G1).
/** $block with the ErrorDocument text of a German site (lang de-DE, translated strings, entities). */
function german( string $block ): string {
	return (string) preg_replace(
		'/^ErrorDocument ([0-9]{3}) ".*"$/m',
		'ErrorDocument $1 "<!doctype html><html lang=\\"de-DE\\"><head><meta charset=\\"utf-8\\"><meta name=\\"robots\\" content=\\"noindex, nofollow\\"><title>$1</title></head><body><p>Diese Filterkombination ist nicht verf&#252;gbar.</p><p><a href=\\"/blog/\\">Alle Beitr&#228;ge anzeigen</a></p></body></html>"',
		$block
	);
}
chk( 'fixture: the German ErrorDocument text differs from the English one, nothing else does',
	german( $old_big ) !== $old_big && str_contains( german( $old_big ), 'verf&#252;gbar' )
	&& preg_replace( '/^ErrorDocument .*$/m', '', german( $old_big ) ) === preg_replace( '/^ErrorDocument .*$/m', '', $old_big ) );
chk( 'German block with the rule the guard now drops, English new block -> provably looser', $looser( $file_with( german( $old_big ) ), $new_big ) === true );
chk( 'English block with a dropped rule, German new block -> provably looser', $looser( $file_with( $old_big ), german( $new_big ) ) === true );
chk( 'another ErrorDocument text, same rules -> not stricter', $looser( $file_with( german( $block ) ), $block ) === true );
chk( 'German block, the new English block adds a rule -> still not provable', $looser( $file_with( german( $block ) ), with_unit( $block, $extra, 'RewriteEngine On' ) ) === false );
chk( 'another ErrorDocument status next to a dropped rule -> not provable',
	$looser( $file_with( (string) preg_replace( '/^ErrorDocument 410 /m', 'ErrorDocument 404 ', german( $old_big ) ) ), $new_big ) === false );
chk( 'ErrorDocument as a URL instead of a text -> not provable',
	$looser( $file_with( (string) preg_replace( '/^ErrorDocument 410 .*$/m', 'ErrorDocument 410 /index.php', $old_big ) ), $new_big ) === false );
chk( 'ErrorDocument text left open (no closing quote) -> not provable',
	$looser( $file_with( (string) preg_replace( '/^(ErrorDocument 410 ".*)"$/m', '$1', $old_big ) ), $new_big ) === false );
chk( 'a line edited by hand inside the block -> not provable',
	$looser( $file_with( str_replace( 'RewriteEngine On', 'RewriteEngine on', with_unit( $block, $extra, 'RewriteEngine On' ) ) ), $block ) === false );
chk( 'block below WordPress\'s (never took effect): moving it up would make it count -> not provable',
	$looser( $head . $wp_block . with_unit( $block, $extra, 'RewriteEngine On' ) . "\n" . $tail, $block ) === false );
chk( 'block below WordPress\'s, no reject part any more: removing it is looser', $looser( $head . $wp_block . $block . "\n" . $tail, '' ) === true );
chk( 'manual block next to it -> not provable (write() refuses anyway)',
	$looser( $head . $manual_block . "\n" . with_unit( $block, $extra, 'RewriteEngine On' ) . "\n" . $wp_block, $block ) === false );
chk( 'damaged markers -> not provable, not even a removal', $looser( $damaged, $block ) === false && $looser( $damaged, '' ) === false );
chk( 'no managed block -> nothing to loosen', $looser( $original, $block ) === false );
chk( 'CRLF file -> compared without line endings', $looser( str_replace( "\n", "\r\n", $file_with( with_unit( $block, $extra, 'RewriteEngine On' ) ) ), $block ) === true );

// loosen_to(): writes under the lock only what is provably looser.
$f = fixture( 'loosen-to', $file_with( $old_big ) );
chk( 'loosen_to(): provably looser -> written, exactly as write() would',
	call( 'jpkcom_postfilter_htaccess_loosen_to', $f, $new_big ) === true && bytes( $f ) === $file_with( $new_big ) );
$stricter = $file_with( (string) call( 'jpkcom_postfilter_htaccess_block', inputs( 3 ) ) );
$f        = fixture( 'loosen-to-2', $stricter );
chk( 'loosen_to(): not provable -> false, file byte-identical',
	call( 'jpkcom_postfilter_htaccess_loosen_to', $f, (string) call( 'jpkcom_postfilter_htaccess_block', inputs( 2 ) ) ) === false && bytes( $f ) === $stricter );
$f = fixture( 'loosen-to-3', $file_with( $block ) );
chk( 'loosen_to(): no reject part -> block removed', call( 'jpkcom_postfilter_htaccess_loosen_to', $f, '' ) === true && bytes( $f ) === $head . $wp_block . $tail );

// =====================================================================================
section( 'available(): gates' );
// =====================================================================================

$ht = $GLOBALS['__home_path'] . '.htaccess';

/** available() for a server string; null removes SERVER_SOFTWARE. */
function avail( ?string $software ): array {
	if ( $software === null ) {
		unset( $_SERVER['SERVER_SOFTWARE'] );
	} else {
		$_SERVER['SERVER_SOFTWARE'] = $software;
	}
	clearstatcache();
	$a = call( 'jpkcom_postfilter_htaccess_available' );
	return is_array( $a ) ? $a : [ 'ok' => null, 'reason' => (string) $a ];
}

file_put_contents( $ht, $original );
$a = avail( 'Apache/2.4.66 (Debian)' );
chk( 'available(): Apache with a writable .htaccess is ok', $a['ok'] === true && $a['reason'] === '', json_encode( $a ) );
$a = avail( 'Apache' );
chk( 'available(): bare "Apache" (ServerTokens Prod) is ok', $a['ok'] === true, json_encode( $a ) );
$a = avail( 'LiteSpeed' );
chk( 'available(): litespeed refused', $a['ok'] === false && $a['reason'] === 'litespeed', json_encode( $a ) );
$a = avail( 'Apache/2.4 LiteSpeed' );
chk( 'available(): litespeed refused even when it claims Apache', $a['reason'] === 'litespeed', json_encode( $a ) );
foreach ( [ 'nginx/1.27.4', 'Caddy', 'FrankenPHP', 'ferron', 'Microsoft-IIS/10.0', '' ] as $sw ) {
	$a = avail( $sw );
	chk( "available(): '{$sw}' is not-apache", $a['ok'] === false && $a['reason'] === 'not-apache', json_encode( $a ) );
}
$a = avail( null );
chk( 'available(): no SERVER_SOFTWARE is not-apache', $a['reason'] === 'not-apache', json_encode( $a ) );

$GLOBALS['__multisite'] = true;
$a                      = avail( 'Apache/2.4.66' );
chk( 'available(): multisite refused', $a['ok'] === false && $a['reason'] === 'multisite', json_encode( $a ) );
$GLOBALS['__multisite'] = false;

unlink( $ht );
$a = avail( 'Apache/2.4.66' );
chk( 'available(): no .htaccess is no-file', $a['ok'] === false && $a['reason'] === 'no-file', json_encode( $a ) );

file_put_contents( $ht, $original );
chmod( $ht, 0444 );
clearstatcache();
if ( is_writable( $ht ) ) {
	echo "  SKIP  available(): not-writable (running as a user who can write a 0444 file)\n";
} else {
	$a = avail( 'Apache/2.4.66' );
	chk( 'available(): read-only .htaccess is not-writable', $a['ok'] === false && $a['reason'] === 'not-writable', json_encode( $a ) );
}
chmod( $ht, 0644 );

// T9-e: a file that exists but cannot be read says nothing about its block.
$st = call( 'jpkcom_postfilter_htaccess_state', $ht );
chk( 'state(): a readable file is readable', is_array( $st ) && ( $st['readable'] ?? null ) === true, json_encode( $st ) );
chmod( $ht, 0000 );
clearstatcache();
if ( ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) || is_readable( $ht ) ) {
	echo "  SKIP  state()/available(): unreadable .htaccess (running as a user who can read a 0000 file)\n";
} else {
	$st = call( 'jpkcom_postfilter_htaccess_state', $ht );
	chk( 'state(): unreadable file - exists, readable false, managed false',
		is_array( $st ) && $st['exists'] === true && ( $st['readable'] ?? null ) === false && $st['managed'] === false, json_encode( $st ) );
	chmod( $ht, 0200 );   // writable, still not readable
	$a = avail( 'Apache/2.4.66' );
	chk( 'available(): writable but unreadable .htaccess is not-readable', $a['ok'] === false && $a['reason'] === 'not-readable', json_encode( $a ) );
}
chmod( $ht, 0644 );
$st = call( 'jpkcom_postfilter_htaccess_state', $GLOBALS['__tmp'] . '/no-such-file' );
chk( 'state(): a missing file is not readable', is_array( $st ) && $st['exists'] === false && ( $st['readable'] ?? null ) === false, json_encode( $st ) );

file_put_contents( $ht, $manual_original );
$a = avail( 'Apache/2.4.66' );
chk( 'available(): manual block refused', $a['ok'] === false && $a['reason'] === 'manual-block', json_encode( $a ) );
file_put_contents( $ht, $original );

// Last: a constant cannot be undefined again.
define( 'DISALLOW_FILE_MODS', true );
$a = avail( 'Apache/2.4.66' );
chk( 'available(): DISALLOW_FILE_MODS refused', $a['ok'] === false && $a['reason'] === 'file-mods-disallowed', json_encode( $a ) );

cleanup();
chk( 'temp directory removed', ! is_dir( $GLOBALS['__tmp'] ) );

exit( summary() );
