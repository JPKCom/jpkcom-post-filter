<?php
/**
 * Guard: the translation catalogue keeps up with the code.
 *
 * Why this exists: the .pot fell three minor releases behind without anything
 * noticing, because regenerating it was neither automated nor on the release
 * checklist. Every string added since simply shipped untranslated, and the only
 * way to find out was to look. This turns that into a red build.
 *
 * It deliberately does NOT reimplement `wp i18n make-pot` - CI has no WP-CLI and
 * no WordPress. It extracts only the shape it can read without ambiguity: a
 * single-line call to one of the gettext wrappers whose first argument is a
 * single-quoted literal and whose second argument is this plugin's text domain.
 * Anything it cannot read with certainty it does not report, because a guard
 * that rejects legitimate code is worse than the gap it closes - the gap needs
 * someone to forget, the false positive needs only someone to commit.
 *
 * Consequence of that choice, stated rather than hidden: a string built by
 * concatenation, or spread over several lines, is invisible here. Those exist in
 * this plugin. The guard catches the ordinary case, which is the one that
 * actually happened.
 *
 * @package JPKComPostFilter
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$slug   = 'jpkcom-post-filter';
$domain = 'jpkcom-post-filter';

$pass = 0;
$fail = 0;

/**
 * Assert a condition.
 *
 * @param string $label Check name.
 * @param bool   $ok    Whether the check holds.
 * @param string $why   Explanation printed on failure.
 */
function i18n_chk( string $label, bool $ok, string $why = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";

	if ( $why !== '' ) {
		echo '        why:  ' . $why . "\n";
	}
}

$pot_path = $root . '/languages/' . $slug . '.pot';

i18n_chk(
	'the .pot exists',
	is_readable( $pot_path ),
	'Expected ' . $pot_path . '. Without it every string ships untranslated and no locale can be started.'
);

if ( ! is_readable( $pot_path ) ) {
	printf( "\n  %d passed, %d failed\n", $pass, $fail );
	exit( 1 );
}

// --- Read the catalogue -----------------------------------------------------

$pot = (string) file_get_contents( $pot_path );

/**
 * Unescape a POT string literal into its plain value.
 *
 * @param string $value Raw literal from the catalogue, without quotes.
 * @return string Plain value.
 */
function i18n_unescape_pot( string $value ): string {
	return strtr(
		$value,
		[
			'\\"'  => '"',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		]
	);
}

$catalogue = [];

// msgid may span several quoted lines; join them before unescaping.
if ( preg_match_all( '/^msgid((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $pot, $matches ) ) {
	foreach ( $matches[1] as $raw ) {
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
		$catalogue[ i18n_unescape_pot( implode( '', $parts[1] ) ) ] = true;
	}
}

i18n_chk(
	'the .pot carries entries',
	count( $catalogue ) > 1,
	'Parsed ' . count( $catalogue ) . ' msgids. A catalogue holding only its header means the parse failed, '
	. 'and every check below would pass for the wrong reason.'
);

// --- Read the code ----------------------------------------------------------

$skip = [ '/tests/', '/tools/', '/docs/', '/node_modules/', '/vendor/', '/blocks/build/', '/debug-templates/', '/.git/' ];
$files = [];

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iterator as $file ) {
	$path = str_replace( '\\', '/', $file->getPathname() );

	if ( substr( $path, -4 ) !== '.php' ) {
		continue;
	}

	foreach ( $skip as $fragment ) {
		if ( strpos( $path, $fragment ) !== false ) {
			continue 2;
		}
	}

	$files[] = $path;
}

sort( $files );

// Only the unambiguous shape: one line, single-quoted literal, this domain, and
// nothing glued to either end of the literal.
$wrappers = 'esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|esc_html_x';
$pattern  = '/\b(?:' . $wrappers . ')\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'' . preg_quote( $domain, '/' ) . '\'\s*\)/';

$missing = [];
$found   = 0;

foreach ( $files as $path ) {
	$source = (string) file_get_contents( $path );

	foreach ( explode( "\n", $source ) as $number => $line ) {
		if ( ! preg_match_all( $pattern, $line, $hits, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $hits as $hit ) {
			// PHP single-quoted literals only honour \' and \\.
			$value = strtr( $hit[1], [ "\\'" => "'", '\\\\' => '\\' ] );
			$found++;

			if ( ! isset( $catalogue[ $value ] ) ) {
				$missing[] = str_replace( $root . '/', '', $path ) . ':' . ( $number + 1 ) . '  ' . substr( $value, 0, 90 );
			}
		}
	}
}

i18n_chk(
	'the extractor found translatable strings at all',
	$found > 20,
	'Found ' . $found . '. A regex that matches nothing would make the check below pass on any catalogue, '
	. 'which is the failure mode this assertion exists to prevent.'
);

i18n_chk(
	'every unambiguously translatable string is in the .pot',
	$missing === [],
	count( $missing ) . " string(s) exist in the code and not in the catalogue. Regenerate it:\n"
	. "        ddev wp i18n make-pot <plugin> languages/" . $slug . ".pot --slug=" . $slug
	. " --domain=" . $domain . " --exclude=\"node_modules,tests,tools,docs,.github,blocks/build,debug-templates\"\n"
	. "        then msgmerge each languages/*-*.po against it, msgfmt the .mo, and wp i18n make-php.\n"
	. ( $missing === [] ? '' : "        first few:\n          " . implode( "\n          ", array_slice( $missing, 0, 8 ) ) )
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
