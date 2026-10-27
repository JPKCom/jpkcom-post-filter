<?php
/**
 * .htaccess management – the plugin's own block in Apache's .htaccess
 *
 * Pure file operations: every function takes the file path (or the rule
 * inputs) as a parameter and registers no hooks - except
 * jpkcom_postfilter_htaccess_file() and jpkcom_postfilter_htaccess_available(),
 * which find the site's file and read the server. Wiring them to admin
 * actions, options and version changes is the job of the callers; nothing in
 * this file writes on its own.
 *
 * WHY NOT insert_with_markers()
 * -----------------------------
 * WordPress's insert_with_markers() appends a new marker pair at the END of
 * the file - after WordPress's own block, where a reject rule never takes
 * effect (WordPress's `RewriteRule . /index.php [L]` has already sent every
 * filter URL to PHP; measured, research report 4 §1.2). And removing through
 * it with an empty list leaves the marker lines behind. So this file places,
 * replaces and removes the block itself (spec 7):
 *
 * - The managed block sits directly before the line `# BEGIN WordPress`, or at
 *   the top (after a UTF-8 BOM) when there is no WordPress block. An existing
 *   managed block above WordPress's is replaced where it stands; one below it
 *   is moved directly before it (ruling R15).
 * - A block with the MANUAL markers (`# BEGIN JPKCom Post Filter (manual)`,
 *   the copy snippet) is never touched: write refuses, remove leaves it. Two
 *   blocks would apply the same rules twice. Markers are compared as whole
 *   lines, so the managed marker - a prefix of the manual one - never matches
 *   the manual line.
 * - Writes happen under flock(LOCK_EX) on the file itself, in place (fopen
 *   'r+', fwrite, ftruncate) - no temp file and rename, so owner, group and
 *   mode of the .htaccess survive. A shrinking write is padded with comment
 *   lines to the old length first, so Apache never reads a remnant of the
 *   old content. Nothing is written when the content would not change.
 * - Line endings (LF or CRLF, taken from the file's first line break), a
 *   UTF-8 BOM and a missing trailing newline are kept; every byte outside the
 *   managed block stays as it was.
 *
 * @package   JPKCom_Post_Filter
 * @since     1.6.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_markers' ) ) {
    /**
     * The marker lines this file knows
     *
     * @since 1.6.0
     *
     * @return array{begin: string, end: string, manual_begin: string, manual_end: string, wp_begin: string, wp_end: string}
     */
    function jpkcom_postfilter_htaccess_markers(): array {
        return [
            'begin'        => '# BEGIN JPKCom Post Filter',
            'end'          => '# END JPKCom Post Filter',
            'manual_begin' => '# BEGIN JPKCom Post Filter (manual)',
            'manual_end'   => '# END JPKCom Post Filter (manual)',
            'wp_begin'     => '# BEGIN WordPress',
            'wp_end'       => '# END WordPress',
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_block' ) ) {
    /**
     * The managed block for the given rule inputs
     *
     * The .htaccess rules of the copy snippet (same function, so both always
     * carry the same rules), between the managed markers instead of the
     * manual ones, with the `# rule <hash>` line
     * jpkcom_postfilter_htaccess_state() reads back.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $inputs As returned by jpkcom_postfilter_server_rule_inputs().
     * @return string '' when there is no reject part (e.g. strict URLs off). LF, no trailing newline.
     */
    function jpkcom_postfilter_htaccess_block( array $inputs ): string {
        return jpkcom_postfilter_server_htaccess_marked( inputs: $inputs, label: 'JPKCom Post Filter' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_parse' ) ) {
    /**
     * Split .htaccess content into lines and find the markers
     *
     * Each line keeps its own terminator (`\n`, `\r\n` or none for a last line
     * without newline), so joining them gives back the exact bytes. Markers
     * are compared as whole lines, ignoring surrounding blanks.
     *
     * `damaged` means managed markers that do not pair up (BEGIN without END,
     * END without BEGIN, BEGIN inside a block), or a managed block that holds
     * one of WordPress's own marker lines (it spans or overlaps WordPress's
     * block): where the block ends is then a guess - or replacing it would
     * take WordPress's rewrite rules with it - and write and remove refuse.
     *
     * `misplaced` means the first managed block sits below `# BEGIN WordPress`,
     * where it never takes effect.
     *
     * @since 1.6.0
     *
     * @param string $content File content.
     * @return array{bom: string, eol: string, lines: string[], blocks: array<int, array{0: int, 1: int}>, damaged: bool, manual: bool, wp: ?int, misplaced: bool}
     */
    function jpkcom_postfilter_htaccess_parse( string $content ): array {
        $markers = jpkcom_postfilter_htaccess_markers();

        $bom = '';
        if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
            $bom     = "\xEF\xBB\xBF";
            $content = substr( $content, 3 );
        }

        $nl  = strpos( $content, "\n" );
        $eol = ( $nl !== false && $nl > 0 && $content[ $nl - 1 ] === "\r" ) ? "\r\n" : "\n";

        $lines = $content === '' ? [] : (array) preg_split( '/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY );

        $blocks  = [];
        $damaged = false;
        $manual  = false;
        $wp      = null;
        $open    = null;

        foreach ( $lines as $i => $line ) {
            $text = trim( (string) $line, " \t\r\n" );

            if ( $text === $markers['manual_begin'] || $text === $markers['manual_end'] ) {
                $manual = true;
            } elseif ( $text === $markers['begin'] ) {
                if ( $open !== null ) {
                    $damaged = true;
                }
                $open = $i;
            } elseif ( $text === $markers['end'] ) {
                if ( $open === null ) {
                    $damaged = true;
                } else {
                    $blocks[] = [ $open, $i ];
                    $open     = null;
                }
            } elseif ( $text === $markers['wp_begin'] || $text === $markers['wp_end'] ) {
                // Inside a managed block: removing or replacing that block would
                // take WordPress's marker (and what follows it) along.
                if ( $open !== null ) {
                    $damaged = true;
                } elseif ( $text === $markers['wp_begin'] && $wp === null ) {
                    $wp = $i;
                }
            }
        }

        if ( $open !== null ) {
            $damaged = true;
        }

        return [
            'bom'       => $bom,
            'eol'       => $eol,
            'lines'     => array_map( 'strval', $lines ),
            'blocks'    => $blocks,
            'damaged'   => $damaged,
            'manual'    => $manual,
            'wp'        => $wp,
            'misplaced' => $wp !== null && $blocks !== [] && $blocks[0][0] > $wp,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_state' ) ) {
    /**
     * What the .htaccess contains
     *
     * `hash` comes from the `# rule <hash>` line of the managed block, so a
     * caller can compare it with the current rule hash. Beyond the interface
     * of the plan: `damaged` (managed markers that do not pair up - write and
     * remove refuse, and the admin page can say why) and `misplaced` (the
     * managed block sits below WordPress's and never takes effect; the next
     * write moves it, ruling R15).
     *
     * `readable` (1.7.1, T9-e): the content could be read. An existing file
     * that cannot be read reports `managed` false - not because there is no
     * block, but because nothing about it is known; callers that guard the
     * block must treat `exists && ! readable` as "a block may be there".
     *
     * @since 1.6.0
     *
     * @param string $file Path of the .htaccess.
     * @return array{exists: bool, writable: bool, readable: bool, managed: bool, manual: bool, hash: ?string, wp_block: bool, damaged: bool, misplaced: bool}
     */
    function jpkcom_postfilter_htaccess_state( string $file ): array {
        $state = [
            'exists'   => false,
            'writable' => false,
            'readable' => false,
            'managed'  => false,
            'manual'   => false,
            'hash'     => null,
            'wp_block' => false,
            'damaged'   => false,
            'misplaced' => false,
        ];

        clearstatcache( true, $file );
        if ( ! is_file( $file ) ) {
            return $state;
        }
        $state['exists']   = true;
        $state['writable'] = is_writable( $file );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = is_readable( $file ) ? file_get_contents( $file ) : false;
        if ( ! is_string( $content ) ) {
            return $state;
        }
        $state['readable'] = true;

        $parsed            = jpkcom_postfilter_htaccess_parse( content: $content );
        $state['manual']   = $parsed['manual'];
        $state['damaged']  = $parsed['damaged'];
        $state['wp_block'] = $parsed['wp'] !== null;
        $state['managed']   = ! $parsed['damaged'] && $parsed['blocks'] !== [];
        $state['misplaced'] = $state['managed'] && $parsed['misplaced'];

        if ( $state['managed'] ) {
            [ $start, $end ] = $parsed['blocks'][0];
            for ( $i = $start + 1; $i < $end; $i++ ) {
                if ( preg_match( '/^# rule ([0-9a-f]{12})$/D', trim( $parsed['lines'][ $i ], " \t\r\n" ), $m ) === 1 ) {
                    $state['hash'] = $m[1];
                    break;
                }
            }
        }

        return $state;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_block_lines' ) ) {
    /**
     * The lines of a managed block, or null when it is not one
     *
     * The block must open with the managed BEGIN line, close with the managed
     * END line, and carry no marker line in between - anything else would
     * leave the file with markers that no longer pair up.
     *
     * @since 1.6.0
     *
     * @param string $block Block text (LF or CRLF, trailing newline optional).
     * @return string[]|null
     */
    function jpkcom_postfilter_htaccess_block_lines( string $block ): ?array {
        $markers = jpkcom_postfilter_htaccess_markers();
        $lines   = explode( "\n", rtrim( str_replace( "\r\n", "\n", $block ), "\n" ) );
        $count   = count( $lines );

        if ( $count < 2 || $lines[0] !== $markers['begin'] || $lines[ $count - 1 ] !== $markers['end'] ) {
            return null;
        }

        $all = array_values( $markers );
        for ( $i = 1; $i < $count - 1; $i++ ) {
            if ( in_array( trim( $lines[ $i ], " \t\r" ), $all, true ) ) {
                return null;
            }
        }

        return $lines;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_last_error' ) ) {
    /**
     * How the last jpkcom_postfilter_htaccess_modify() call in this request failed
     *
     * - `''`: nothing failed while writing (the write succeeded, nothing had to
     *   be written, or nothing was attempted - missing file, lock, refusal).
     * - `write-failed`: the write failed and the old content is back.
     * - `rollback-failed`: the write failed and putting the old content back
     *   failed too. The file may now hold part of the new content over the
     *   old one - possibly half a directive, for which Apache answers 500 -
     *   so the caller has to tell the admin to check it (T8-a).
     *
     * @since 1.7.1
     *
     * @param string|null $set New value, or null to read.
     * @return string
     */
    function jpkcom_postfilter_htaccess_last_error( ?string $set = null ): string {
        static $error = '';

        if ( $set !== null ) {
            $error = $set;
        }

        return $error;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_modify' ) ) {
    /**
     * Read-modify-write a file under an exclusive lock, in place
     *
     * `$change` gets the current content and returns the new content, or null
     * to refuse (nothing is written, the result is false). Identical content
     * is not written at all, so the mtime stays. No rename: owner, group and
     * mode survive. Should the write fail half way, the old content is put
     * back; jpkcom_postfilter_htaccess_last_error() says afterwards whether
     * that worked.
     *
     * @since 1.6.0
     *
     * @param string   $file   Existing file.
     * @param callable $change fn( string $content ): ?string.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_modify( string $file, callable $change ): bool {
        jpkcom_postfilter_htaccess_last_error( set: '' );
        clearstatcache( true, $file );
        if ( ! is_file( $file ) ) {
            return false;   // a missing .htaccess is not ours to create
        }

        // 'r+', not 'c+': should the file go away between is_file() and here,
        // fopen() fails instead of creating an empty .htaccess.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = @fopen( $file, 'r+' );
        if ( $fh === false ) {
            return false;
        }

        $ok = false;
        if ( flock( $fh, LOCK_EX ) ) {
            $old = stream_get_contents( $fh );
            $new = is_string( $old ) ? $change( $old ) : null;

            if ( is_string( $old ) && is_string( $new ) ) {
                if ( $new === $old ) {
                    $ok = true;
                } else {
                    $ok = jpkcom_postfilter_htaccess_put( fh: $fh, content: $new );
                    if ( ! $ok ) {
                        $restored = jpkcom_postfilter_htaccess_put( fh: $fh, content: $old );
                        jpkcom_postfilter_htaccess_last_error( set: $restored ? 'write-failed' : 'rollback-failed' );
                    }
                }
            }
            flock( $fh, LOCK_UN );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fh );
        clearstatcache( true, $file );

        return $ok;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_padded' ) ) {
    /**
     * Content padded with comment lines to a given length
     *
     * For a write that shrinks the file: the new content is written over the
     * old one first and the rest is cut afterwards, so for that instant the
     * old tail would follow the new content - possibly half a directive, and
     * Apache answers 500 for a .htaccess it cannot parse. Padded to the old
     * length with `#` lines (at most 80 bytes each), every state of the file
     * in between is the new content plus comments. Content that does not end
     * with a newline gets one first, so the padding starts on its own line.
     *
     * @since 1.6.0
     *
     * @param string $content New content.
     * @param int    $length  Length to pad to (the current file size).
     * @return string `$content` itself when it is not shorter.
     */
    function jpkcom_postfilter_htaccess_padded( string $content, int $length ): string {
        $missing = $length - strlen( $content );
        if ( $missing <= 0 ) {
            return $content;
        }

        $pad = ( $content === '' || str_ends_with( $content, "\n" ) ) ? '' : "\n";
        while ( strlen( $pad ) < $missing ) {
            $room = min( $missing - strlen( $pad ), 80 );
            $pad .= $room === 1 ? "\n" : str_repeat( '#', $room - 1 ) . "\n";
        }

        return $content . $pad;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_put' ) ) {
    /**
     * Replace the content of an open, locked file
     *
     * Writes from offset 0 first and cuts the rest afterwards, so the file is
     * never empty in between. A shorter content goes out padded with comment
     * lines to the current size in one write (jpkcom_postfilter_htaccess_padded()),
     * so no reader ever sees a remnant of the old content before the cut.
     *
     * Write, flush and cut are silenced: on a full disk fwrite() raises an
     * E_NOTICE, and with display_errors on that would be output before the
     * redirect of the Set up / Remove action ("headers already sent"). The
     * failure is reported through the return value (T8-b).
     *
     * @since 1.6.0
     *
     * @param resource $fh      Handle opened with 'r+' and locked.
     * @param string   $content New content.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_put( $fh, string $content ): bool {
        if ( ! rewind( $fh ) ) {
            return false;
        }
        $stat    = fstat( $fh );
        $size    = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
        $data    = jpkcom_postfilter_htaccess_padded( content: $content, length: $size );
        $total   = strlen( $data );
        $written = 0;
        while ( $written < $total ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged
            $n = @fwrite( $fh, substr( $data, $written ) );
            if ( $n === false || $n === 0 ) {
                return false;
            }
            $written += $n;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return @fflush( $fh ) && @ftruncate( $fh, strlen( $content ) );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_write' ) ) {
    /**
     * Put the managed block into the .htaccess
     *
     * Replaces an existing managed block where it stands (and drops any
     * further copy of it) - unless it sits below `# BEGIN WordPress`, where it
     * never takes effect: then it is removed there and the block goes directly
     * before WordPress's (ruling R15). Without a managed block it is inserted
     * directly before `# BEGIN WordPress`, or at the top when there is none. Refuses (false,
     * nothing written) when the file is missing, holds a manual block, holds
     * managed markers that do not pair up, or `$block` is not a managed block.
     *
     * @since 1.6.0
     *
     * @param string $file  Path of the .htaccess.
     * @param string $block As returned by jpkcom_postfilter_htaccess_block().
     * @return bool True when the file holds the block afterwards.
     */
    function jpkcom_postfilter_htaccess_write( string $file, string $block ): bool {
        $block_lines = jpkcom_postfilter_htaccess_block_lines( block: $block );
        if ( $block_lines === null ) {
            return false;
        }

        return jpkcom_postfilter_htaccess_modify(
            file: $file,
            change: static function ( string $content ) use ( $block_lines ): ?string {
                $p = jpkcom_postfilter_htaccess_parse( content: $content );
                if ( $p['manual'] || $p['damaged'] ) {
                    return null;
                }

                $text  = implode( $p['eol'], $block_lines );
                $lines = $p['lines'];

                if ( $p['misplaced'] ) {
                    // The first block is below WordPress's line, so all of them are:
                    // removing them leaves that line's index as it is.
                    foreach ( array_reverse( $p['blocks'] ) as [ $start, $end ] ) {
                        array_splice( $lines, $start, $end - $start + 1 );
                    }
                    array_splice( $lines, (int) $p['wp'], 0, [ $text . $p['eol'] ] );
                } elseif ( $p['blocks'] !== [] ) {
                    // Last first, so earlier indexes stay valid. The first block keeps
                    // its place and its END line's own terminator (none at EOF).
                    foreach ( array_reverse( $p['blocks'] ) as $n => [ $start, $end ] ) {
                        $is_first = $n === count( $p['blocks'] ) - 1;
                        $after    = substr( $lines[ $end ], strlen( rtrim( $lines[ $end ], "\r\n" ) ) );
                        $with     = $is_first ? [ $text . $after ] : [];
                        array_splice( $lines, $start, $end - $start + 1, $with );
                    }
                } elseif ( $p['wp'] !== null ) {
                    array_splice( $lines, $p['wp'], 0, [ $text . $p['eol'] ] );
                } else {
                    array_unshift( $lines, $text . $p['eol'] );
                }

                return $p['bom'] . implode( '', $lines );
            }
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_remove' ) ) {
    /**
     * Remove the managed block, markers included, and nothing else
     *
     * A manual block stays. True when no managed block is left (also when
     * there was none); false when the file is missing or its managed markers
     * do not pair up.
     *
     * @since 1.6.0
     *
     * @param string $file Path of the .htaccess.
     * @return bool
     */
    function jpkcom_postfilter_htaccess_remove( string $file ): bool {
        return jpkcom_postfilter_htaccess_modify(
            file: $file,
            change: static function ( string $content ): ?string {
                $p = jpkcom_postfilter_htaccess_parse( content: $content );
                if ( $p['damaged'] ) {
                    return null;
                }
                if ( $p['blocks'] === [] ) {
                    return $content;
                }

                $lines = $p['lines'];
                foreach ( array_reverse( $p['blocks'] ) as [ $start, $end ] ) {
                    array_splice( $lines, $start, $end - $start + 1 );
                }

                return $p['bom'] . implode( '', $lines );
            }
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_home_file' ) ) {
    /**
     * Path of the site's .htaccess as WordPress derives it: get_home_path()
     *
     * get_home_path() reads SCRIPT_FILENAME when home and siteurl differ
     * (WordPress in a subdirectory). That is the PHP script of the request:
     * the wp binary under WP-CLI, the root index.php on a front-end request -
     * and from there the home path came out as '/' and the file as
     * '/.htaccess'. So SCRIPT_FILENAME is ABSPATH for the call, which is what
     * get_home_path() expects to find it below (WP-CLI's own rewrite command
     * does the same); on an admin request the result is unchanged. When even
     * that yields no home path (home and siteurl on different hosts), the
     * WordPress directory is taken - never the file system root.
     *
     * @since 1.6.0
     *
     * @return string
     */
    function jpkcom_postfilter_htaccess_home_file(): string {
        if ( ! function_exists( function: 'get_home_path' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $had    = array_key_exists( 'SCRIPT_FILENAME', $_SERVER );
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;

        $_SERVER['SCRIPT_FILENAME'] = ABSPATH;
        try {
            $home = str_replace( '\\', '/', (string) get_home_path() );
        } finally {
            if ( $had ) {
                $_SERVER['SCRIPT_FILENAME'] = $script;
            } else {
                unset( $_SERVER['SCRIPT_FILENAME'] );
            }
        }

        $abspath = str_replace( '\\', '/', ABSPATH );
        if ( trim( $home, '/' ) === '' && trim( $abspath, '/' ) !== '' ) {
            $home = $abspath;
        }

        return rtrim( $home, '/' ) . '/.htaccess';
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_file' ) ) {
    /**
     * Path of the site's .htaccess
     *
     * The path stored at Set up (option `jpkcom_postfilter_htaccess_managed`,
     * fix wave item 1) while that file exists - a WP-CLI command, a cron run
     * or a deactivation then works on the file the admin set up, whatever
     * the request looks like. Otherwise (not set up, the legacy value '1', or
     * the site moved and the stored file is gone)
     * jpkcom_postfilter_htaccess_home_file().
     *
     * The stored path is only trusted when its directory is one of the site's
     * own: the WordPress directory, its parent (WordPress in a subdirectory)
     * or the derived home path. A tampered option must not point the writes
     * at another .htaccess on the host, and a subdirectory such as
     * wp-content/uploads is never the right file either.
     *
     * @since 1.6.0
     *
     * @return string
     */
    function jpkcom_postfilter_htaccess_file(): string {
        $derived = jpkcom_postfilter_htaccess_home_file();
        $stored  = get_option( 'jpkcom_postfilter_htaccess_managed', '' );

        if ( ! is_string( $stored ) || ! str_ends_with( $stored, '/.htaccess' ) || ! is_file( $stored ) ) {
            return $derived;
        }

        $dir = realpath( dirname( $stored ) );
        if ( $dir === false ) {
            return $derived;
        }

        $allowed = [];
        foreach ( [ ABSPATH, dirname( ABSPATH ), dirname( $derived ) ] as $candidate ) {
            $real = realpath( $candidate );
            if ( $real !== false ) {
                $allowed[] = $real;
            }
        }

        return in_array( $dir, $allowed, true ) ? $stored : $derived;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_htaccess_available' ) ) {
    /**
     * May the plugin manage the .htaccess on this site?
     *
     * Reasons, in the order they are checked:
     * - `litespeed`: LiteSpeed reports itself as such, and WordPress's
     *   $is_apache is true for it too; OpenLiteSpeed also needs a restart to
     *   read .htaccess changes, so the plugin does not manage it there.
     * - `not-apache`: SERVER_SOFTWARE does not name Apache (nginx, Caddy,
     *   FrankenPHP, Ferron, IIS, none).
     * - `multisite`: one .htaccess serves the whole network.
     * - `file-mods-disallowed`: DISALLOW_FILE_MODS is set.
     * - `no-file`, `not-writable`, `not-readable`: jpkcom_postfilter_htaccess_file().
     * - `manual-block`: a pasted block with the manual markers is present;
     *   a second, managed one would apply the same rules twice.
     *
     * The capability and nonce checks belong to the admin action.
     *
     * @since 1.6.0
     *
     * @return array{ok: bool, reason: string}
     */
    function jpkcom_postfilter_htaccess_available(): array {
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

        $reason = '';
        if ( stripos( $software, 'litespeed' ) !== false ) {
            $reason = 'litespeed';
        } elseif ( stripos( $software, 'apache' ) === false ) {
            $reason = 'not-apache';
        } elseif ( function_exists( function: 'is_multisite' ) && is_multisite() ) {
            $reason = 'multisite';
        } elseif ( defined( constant_name: 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            $reason = 'file-mods-disallowed';
        } else {
            $state = jpkcom_postfilter_htaccess_state( file: jpkcom_postfilter_htaccess_file() );
            if ( ! $state['exists'] ) {
                $reason = 'no-file';
            } elseif ( ! $state['writable'] ) {
                $reason = 'not-writable';
            } elseif ( ! $state['readable'] ) {
                $reason = 'not-readable';
            } elseif ( $state['manual'] ) {
                $reason = 'manual-block';
            }
        }

        return [ 'ok' => $reason === '', 'reason' => $reason ];
    }
}
