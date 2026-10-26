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
		$f                         = self::$files[ $this->path ];
		$f                         = str_pad( substr( $f, 0, $this->pos ), $this->pos, "\0" ) . $data . (string) substr( $f, $this->pos + strlen( $data ) );
		self::$files[ $this->path ] = $f;
		$this->pos                += strlen( $data );
		self::$snaps[]             = $f;
		return strlen( $data );
	}
	public function stream_truncate( int $size ): bool {
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

Jpkpf_Recording_Stream::$files = [];
Jpkpf_Recording_Stream::$modes = [];
chk( 'missing file: nothing created', call( 'jpkcom_postfilter_htaccess_modify', 'jpkpfmem://none', static fn( string $c ): string => 'x' ) === false && Jpkpf_Recording_Stream::$files === [] );

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
