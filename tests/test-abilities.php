<?php
/**
 * Tests for the Abilities API integration (1.3.0).
 *
 * Scope, stated honestly: this harness has no WordPress, so the tests stub both
 * the WordPress functions and the plugin's own data-access functions, then run
 * the ability callbacks in-process. That proves the input handling, the
 * validation the underlying query pipeline does not do, and the shape of the
 * registration arrays. It does NOT prove that wp_register_ability() accepts
 * those arrays — that is verified against a real installation, see the spec.
 *
 * Run with:
 *     php tests/test-abilities.php
 *
 * @package JPKCom_Post_Filter
 * @since 1.3.0
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'JPKCOM_POSTFILTER_DEBUG', false );
define( 'JPKCOM_POSTFILTER_ABILITIES', true );

// --- WordPress stubs -------------------------------------------------------

function add_action( string $h, callable $c, int $p = 10, int $a = 1 ): void {}

function apply_filters( string $tag, mixed $value, mixed ...$rest ): mixed {
	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function sanitize_key( string $k ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $k ) ?? '' );
}

function sanitize_title( string $t ): string {
	return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '-', $t ) ?? '' );
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public string $code    = '';
	public string $message = '';

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

require_once dirname( __DIR__ ) . '/includes/abilities.php';

// --- Harness ---------------------------------------------------------------

$pass = 0;
$fail = 0;

/**
 * Print a section heading.
 *
 * @param string $title Section title.
 */
function section( string $title ): void {
	echo "\n" . $title . "\n";
}

/**
 * Assert two values are identical.
 *
 * @param string $label    Check name.
 * @param mixed  $got      Actual value.
 * @param mixed  $expected Expected value.
 * @param string $why      Explanation printed on failure.
 */
function is_same( string $label, mixed $got, mixed $expected, string $why = '' ): void {
	global $pass, $fail;

	if ( $got === $expected ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        got:      ' . var_export( $got, true ) . "\n";

	if ( $why !== '' ) {
		echo '        why:      ' . $why . "\n";
	}
}

/**
 * Assert a condition holds.
 *
 * @param string $label Check name.
 * @param bool   $cond  Condition.
 * @param string $why   Explanation printed on failure.
 */
function check( string $label, bool $cond, string $why = '' ): void {
	is_same( $label, $cond, true, $why );
}

// --- Tests -----------------------------------------------------------------

section( 'per_page clamping' );

is_same(
	'missing value falls back to the default',
	jpkcom_postfilter_ability_clamp_per_page( null ),
	10
);

is_same(
	'the shortcode default of -1 never survives',
	jpkcom_postfilter_ability_clamp_per_page( -1 ),
	10,
	'build_query_args() sets no_found_rows when limit === -1, which makes WP_Query '
	. 'report found_posts as 0. An ability that returns a total must never pass -1 through.'
);

is_same(
	'zero falls back to the default',
	jpkcom_postfilter_ability_clamp_per_page( 0 ),
	10
);

is_same(
	'a valid value is kept',
	jpkcom_postfilter_ability_clamp_per_page( 25 ),
	25
);

is_same(
	'an oversized value is clamped to the maximum',
	jpkcom_postfilter_ability_clamp_per_page( 500 ),
	50,
	'Every result has to fit into a model context window.'
);

is_same(
	'a non-numeric value falls back to the default',
	jpkcom_postfilter_ability_clamp_per_page( 'many' ),
	10
);

section( 'filter normalisation' );

is_same(
	'a scalar term is wrapped into a list',
	jpkcom_postfilter_ability_normalize_filters( [ 'category' => 'news' ] ),
	[ 'category' => [ 'news' ] ]
);

is_same(
	'duplicate slugs are dropped',
	jpkcom_postfilter_ability_normalize_filters( [ 'category' => [ 'news', 'news' ] ] ),
	[ 'category' => [ 'news' ] ]
);

is_same(
	'a taxonomy with no usable terms is dropped',
	jpkcom_postfilter_ability_normalize_filters( [ 'category' => [] ] ),
	[]
);

is_same(
	'non-array input yields an empty map',
	jpkcom_postfilter_ability_normalize_filters( 'category=news' ),
	[]
);

is_same(
	'non-scalar terms are skipped rather than fatal',
	jpkcom_postfilter_ability_normalize_filters( [ 'category' => [ [ 'nested' ], 'news' ] ] ),
	[ 'category' => [ 'news' ] ],
	'On the WP 6.9 floor an uncaught TypeError inside a callback is a fatal, not a WP_Error.'
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
