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

/**
 * Whether this process is the child run that exercises the kill switch.
 *
 * JPKCOM_POSTFILTER_ABILITIES is a constant, so one process can only ever see
 * one of its two values. The suite therefore re-executes this file with the
 * environment variable set and inspects what registration did over there.
 */
$is_kill_switch_child = getenv( 'JPKPF_ABILITIES_KILL_SWITCH_CHILD' ) === '1';

define( 'JPKCOM_POSTFILTER_ABILITIES', ! $is_kill_switch_child );

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

/**
 * Taxonomies this fixture considers registered.
 *
 * jpkcom_postfilter_ability_allowed_taxonomies() consults the registry, because
 * a filter group outlives the plugin that registered its taxonomy. Anything not
 * listed here behaves like a taxonomy whose plugin was deactivated.
 */
$GLOBALS['_stub_taxonomies'] = [ 'category', 'post_tag', 'empty_tax' ];

function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, $GLOBALS['_stub_taxonomies'], true );
}

function wp_json_encode( mixed $value ): string {
	return (string) json_encode( $value );
}

class WP_Error {
	public string $code    = '';
	public string $message = '';
	public mixed  $data    = null;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_Term {
	public string $slug  = '';
	public string $name  = '';
	public int    $count = 0;
}

$GLOBALS['_stub_settings']         = [ 'general' => [ 'enabled_post_types' => [ 'post' ] ] ];
$GLOBALS['_stub_groups']           = [];
$GLOBALS['_stub_terms']            = [];   // taxonomy => [ [slug, name, count], ... ]
$GLOBALS['_stub_raw_terms']        = [];   // taxonomy => term list returned verbatim (malformed-data tests)
$GLOBALS['_stub_raw_group_entries'] = [];  // taxonomy => group entry list returned verbatim (malformed-data tests)
$GLOBALS['_stub_term_lookups']     = [];   // taxonomy => number of get_terms_for_taxonomy() calls
$GLOBALS['_stub_term_hide_empty']  = null; // hide_empty of the last get_terms_for_taxonomy() call

function jpkcom_postfilter_settings_get( string $group, string $key, mixed $default = null ): mixed {
	return $GLOBALS['_stub_settings'][ $group ][ $key ] ?? $default;
}

function jpkcom_postfilter_get_filter_groups_enabled(): array {
	return $GLOBALS['_stub_groups'];
}

/**
 * Build the WP_Term list a taxonomy holds in the fixtures.
 *
 * @param string $taxonomy Taxonomy key.
 * @return array<int, mixed> Terms, or whatever the raw override supplies.
 */
function jpkpf_stub_terms( string $taxonomy ): array {
	if ( isset( $GLOBALS['_stub_raw_terms'][ $taxonomy ] ) ) {
		return $GLOBALS['_stub_raw_terms'][ $taxonomy ];
	}

	$out = [];

	foreach ( $GLOBALS['_stub_terms'][ $taxonomy ] ?? [] as $row ) {
		$term        = new WP_Term();
		$term->slug  = $row[0];
		$term->name  = $row[1];
		$term->count = $row[2];
		$out[]       = $term;
	}

	return $out;
}

function jpkcom_postfilter_get_terms_for_taxonomy( string $taxonomy, bool $hide_empty = true ): array {
	$GLOBALS['_stub_term_lookups'][ $taxonomy ] = ( $GLOBALS['_stub_term_lookups'][ $taxonomy ] ?? 0 ) + 1;
	$GLOBALS['_stub_term_hide_empty']           = $hide_empty;

	return jpkpf_stub_terms( $taxonomy );
}

function jpkcom_postfilter_get_terms_for_group( array $group, array $active_filters = [] ): array {
	$taxonomy = (string) ( $group['taxonomy'] ?? '' );

	if ( isset( $GLOBALS['_stub_raw_group_entries'][ $taxonomy ] ) ) {
		return $GLOBALS['_stub_raw_group_entries'][ $taxonomy ];
	}

	$out = [];

	foreach ( jpkpf_stub_terms( $taxonomy ) as $term ) {
		$out[] = [ 'term' => $term, 'is_active' => false ];
	}

	return $out;
}

$GLOBALS['_stub_debug_log_calls'] = 0;

function jpkcom_postfilter_debug_log( string $message, mixed $context = null ): void {
	$GLOBALS['_stub_debug_log_calls']++;
}

class WP_Post {
	public int    $ID            = 0;
	public string $post_title    = '';
	public string $post_date_gmt = '';
	public string $post_excerpt  = '';
}

class WP_Query {
	/** @var WP_Post[] */
	public array $posts         = [];
	public int   $found_posts   = 0;
	public int   $max_num_pages = 0;
}

$GLOBALS['_stub_query']          = null; // WP_Query returned by run_query()
$GLOBALS['_stub_query_args']     = [];   // captured build_query_args() input
$GLOBALS['_stub_run_query_args'] = [];   // captured run_query() input
$GLOBALS['_stub_post_terms']     = [];   // "postID:taxonomy" => [ [slug, name], ... ]

function jpkcom_postfilter_build_query_args( array $atts, array $active_filters = [] ): array {
	$GLOBALS['_stub_query_args'] = [ 'atts' => $atts, 'filters' => $active_filters ];

	return $atts;
}

function jpkcom_postfilter_run_query( array $query_args, array $active_filters = [] ): WP_Query {
	$GLOBALS['_stub_run_query_args'] = [ 'args' => $query_args, 'filters' => $active_filters ];

	return $GLOBALS['_stub_query'] ?? new WP_Query();
}

$GLOBALS['_stub_archiveless']      = [];  // post types whose archive base URL is empty
$GLOBALS['_stub_filter_url_calls'] = 0;

function jpkcom_postfilter_get_archive_base_url( string $post_type ): string {
	if ( in_array( $post_type, $GLOBALS['_stub_archiveless'], true ) ) {
		return '';
	}

	return 'https://example.test/' . $post_type . '/';
}

function jpkcom_postfilter_get_filter_url( string $base_url, array $filters, int $page = 0 ): string {
	$GLOBALS['_stub_filter_url_calls']++;

	return $base_url . 'filter/' . implode( '-', array_keys( $filters ) ) . '/';
}

function get_the_terms( WP_Post $post, string $taxonomy ): array|false {
	$rows = $GLOBALS['_stub_post_terms'][ $post->ID . ':' . $taxonomy ] ?? null;

	if ( $rows === null ) {
		return false;
	}

	$out = [];

	foreach ( $rows as $row ) {
		$term       = new WP_Term();
		$term->slug = $row[0];
		$term->name = $row[1];
		$out[]      = $term;
	}

	return $out;
}

function get_the_title( WP_Post $post ): string {
	return $post->post_title;
}

function get_permalink( WP_Post $post ): string {
	return 'https://example.test/?p=' . $post->ID;
}

function get_post_time( string $format, bool $gmt, WP_Post $post ): string {
	return $post->post_date_gmt;
}

function get_the_excerpt( WP_Post $post ): string {
	return $post->post_excerpt;
}

$GLOBALS['_stub_can'] = true;

function current_user_can( string $capability ): bool {
	return $GLOBALS['_stub_can'];
}

$GLOBALS['_stub_has_category']          = false; // wp_has_ability_category() answer
$GLOBALS['_stub_registered_categories'] = [];    // slug => args
$GLOBALS['_stub_registered_abilities']  = [];    // name => args
$GLOBALS['_stub_register_null_for']     = '';    // ability name whose registration fails
$GLOBALS['_stub_category_returns_null'] = false;

/**
 * Define the Abilities API stubs.
 *
 * Wrapped in a function on purpose. PHP hoists unconditional top-level function
 * declarations before the script runs, so declaring these at file scope would
 * make wp_register_ability() exist from line 1 — and the guard section, which
 * asserts the plugin stays inert when the API is absent, would silently test
 * nothing. Declaring them inside a function defers it to the call.
 */
function jpkpf_define_ability_api_stubs(): void {
	if ( function_exists( 'wp_register_ability' ) ) {
		return;
	}

	function wp_has_ability_category( string $slug ): bool {
		return (bool) $GLOBALS['_stub_has_category'];
	}

	function wp_register_ability_category( string $slug, array $args ): ?object {
		$GLOBALS['_stub_registered_categories'][ $slug ] = $args;

		return $GLOBALS['_stub_category_returns_null'] ? null : (object) [ 'slug' => $slug ];
	}

	function wp_register_ability( string $name, array $args ): ?object {
		$GLOBALS['_stub_registered_abilities'][ $name ] = $args;

		return $name === $GLOBALS['_stub_register_null_for'] ? null : (object) [ 'name' => $name ];
	}
}

require_once dirname( __DIR__ ) . '/includes/abilities.php';

// --- Kill-switch child mode ------------------------------------------------
//
// Reached only in the re-executed process, where JPKCOM_POSTFILTER_ABILITIES is
// false. The Abilities API is fully present here, so anything that gets
// registered is the kill switch failing to hold. Reports one machine-readable
// line and exits before the suite proper.
if ( $is_kill_switch_child ) {
	jpkpf_define_ability_api_stubs();

	jpkcom_postfilter_register_ability_category();
	jpkcom_postfilter_register_abilities();

	printf(
		"KILL_SWITCH enabled=%d categories=%d abilities=%d\n",
		jpkcom_postfilter_abilities_enabled() ? 1 : 0,
		count( $GLOBALS['_stub_registered_categories'] ),
		count( $GLOBALS['_stub_registered_abilities'] )
	);

	exit( 0 );
}

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

$flood = jpkcom_postfilter_ability_normalize_filters(
	[ 'category' => array_map( static fn( int $i ): string => 'slug-' . $i, range( 1, 500 ) ) ]
);

is_same(
	'the slug list per taxonomy is capped',
	count( $flood['category'] ),
	JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX,
	'filters is caller-controlled and deduplication does not bound it. 500 distinct slugs '
	. 'would otherwise become a 500-value IN() clause. Truncating rather than erroring '
	. 'matches jpkcom_postfilter_parse_filter_path(), which caps URL filter lists the same way.'
);

is_same(
	'the cap truncates from the front, it does not reorder',
	array_slice( $flood['category'], 0, 3 ),
	[ 'slug-1', 'slug-2', 'slug-3' ]
);

is_same(
	'a list at the cap is untouched',
	count( jpkcom_postfilter_ability_normalize_filters(
		[ 'category' => array_map( static fn( int $i ): string => 'slug-' . $i, range( 1, JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX ) ) ]
	)['category'] ),
	JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX
);

section( 'filter group applicability' );

$group_explicit = [ 'taxonomy' => 'category', 'post_types' => [ 'post', 'projekt' ] ];
$group_fallback = [ 'taxonomy' => 'post_tag', 'post_types' => [] ];

check(
	'an explicit post_types list is honoured',
	jpkcom_postfilter_ability_group_applies( $group_explicit, 'projekt', [ 'post' ] )
);

check(
	'a post type outside the explicit list does not match',
	! jpkcom_postfilter_ability_group_applies( $group_explicit, 'page', [ 'post', 'page' ] )
);

check(
	'an empty post_types list falls back to the enabled post types',
	jpkcom_postfilter_ability_group_applies( $group_fallback, 'post', [ 'post' ] )
);

check(
	'the fallback does not match a post type that is not enabled',
	! jpkcom_postfilter_ability_group_applies( $group_fallback, 'projekt', [ 'post' ] )
);

check(
	'a missing post_types key behaves like an empty one',
	jpkcom_postfilter_ability_group_applies( [ 'taxonomy' => 'category' ], 'post', [ 'post' ] ),
	'Live installations store only four of the twelve sanitiser keys, so every key '
	. 'except taxonomy must be read defensively.'
);

section( 'allowed taxonomies' );

$groups = [
	[ 'taxonomy' => 'category', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'post_tag', 'post_types' => [] ],
	[ 'taxonomy' => '', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'category', 'post_types' => [ 'post' ] ],
];

is_same(
	'applicable taxonomies are collected without duplicates or blanks',
	jpkcom_postfilter_ability_allowed_taxonomies( 'post', $groups, [ 'post' ] ),
	[ 'category', 'post_tag' ]
);

is_same(
	'a post type with no applicable group yields an empty list',
	jpkcom_postfilter_ability_allowed_taxonomies( 'projekt', $groups, [ 'post' ] ),
	[]
);

$ghost_groups = [
	[ 'taxonomy' => 'category', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'jpkpf_ghost_tax', 'post_types' => [ 'post' ] ],
];

is_same(
	'a group whose taxonomy is no longer registered is skipped',
	jpkcom_postfilter_ability_allowed_taxonomies( 'post', $ghost_groups, [ 'post' ] ),
	[ 'category' ],
	'The settings outlive the registration: deactivating the plugin that registered a '
	. 'taxonomy leaves its filter group behind. Reproduced in-process — with the group '
	. 'still configured, taxonomy_exists() was false while this function reported the '
	. 'taxonomy as filterable, validate_filters() then accepted it, build_query_args() '
	. 'produced no tax_query (query-handler.php:57 skips unknown taxonomies) and the '
	. 'ability answered with the complete unfiltered corpus. That is the exact failure '
	. 'the validation guard exists to prevent, one step further along.'
);

section( 'taxonomy validation — the silent-full-corpus guard' );

$valid = jpkcom_postfilter_ability_validate_filters( [ 'category' => [ 'news' ] ], [ 'category', 'post_tag' ] );

is_same( 'a known taxonomy validates', $valid, true );

$rejected = jpkcom_postfilter_ability_validate_filters( [ 'nonexistent' => [ 'seo' ] ], [ 'category', 'post_tag' ] );

check(
	'an unknown taxonomy is rejected',
	$rejected instanceof WP_Error,
	'build_tax_query() drops a clause for a non-existent taxonomy and the query then '
	. 'returns the complete unfiltered corpus with no error. Measured: filters of '
	. '["no_such_tax" => ["x"]] returned 19 of 19 posts. Without this guard a model '
	. 'that writes "tag" instead of "post_tag" presents the whole site as a filtered answer.'
);

is_same(
	'the error code is stable',
	$rejected instanceof WP_Error ? $rejected->get_error_code() : '',
	'jpkcom_postfilter_unknown_taxonomy'
);

check(
	'the message names the offending taxonomy',
	$rejected instanceof WP_Error && str_contains( $rejected->get_error_message(), 'nonexistent' )
);

check(
	'the message names the valid taxonomies so the caller can self-correct',
	$rejected instanceof WP_Error
		&& str_contains( $rejected->get_error_message(), 'category' )
		&& str_contains( $rejected->get_error_message(), 'post_tag' ),
	'A model that only learns "that was wrong" retries by guessing. One that is told '
	. 'the valid names corrects itself in a single turn.'
);

is_same(
	'the rejection is a 400, so a caller reads it as its own mistake',
	$rejected instanceof WP_Error ? $rejected->get_error_data() : null,
	[ 'status' => 400 ],
	'Measured through rest_do_request(): input[filters][tag][]=seo answered HTTP 500 '
	. 'with this code. The run controller returns the WP_Error verbatim and '
	. 'rest_ensure_response() defaults to 500 when no data["status"] is set. A 5xx tells '
	. 'an agent "transient server fault, retry the same call" — the opposite of the '
	. 'self-correction this message is written for.'
);

$no_allowed = jpkcom_postfilter_ability_validate_filters( [ 'category' => [ 'news' ] ], [] );

check(
	'an empty allow-list rejects everything without a fatal',
	$no_allowed instanceof WP_Error
);

section( 'unknown post type error' );

$pt_error = jpkcom_postfilter_ability_unknown_post_type_error( 'projekt', [ 'post', 'page' ] );

is_same(
	'the error code is stable',
	$pt_error->get_error_code(),
	'jpkcom_postfilter_unknown_post_type'
);

check(
	'the message lists the enabled post types',
	str_contains( $pt_error->get_error_message(), 'post' )
		&& str_contains( $pt_error->get_error_message(), 'page' )
);

is_same(
	'the rejection is a 400, so a caller reads it as its own mistake',
	$pt_error->get_error_data(),
	[ 'status' => 400 ],
	'Measured through rest_do_request(): input[post_type]=page answered HTTP 500 with '
	. 'this code. Naming the enabled post types is pointless if the transport says '
	. '"server fault, retry unchanged".'
);

section( 'list-filters callback' );

$GLOBALS['_stub_settings']['general']['enabled_post_types'] = [ 'post' ];
$GLOBALS['_stub_groups']                                    = [
	[ 'taxonomy' => 'category', 'label' => 'Kategorie', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'post_tag', 'label' => 'Schlagwort', 'post_types' => [] ],
	[ 'taxonomy' => 'empty_tax', 'label' => 'Leer', 'post_types' => [ 'post' ] ],
];
$GLOBALS['_stub_terms'] = [
	'category' => [ [ 'news', 'News', 4 ] ],
	'post_tag' => [ [ 'seo', 'SEO', 6 ] ],
];

$listed = jpkcom_postfilter_ability_list_filters( [ 'post_type' => 'post' ] );

is_same(
	'the post type is echoed back',
	is_array( $listed ) ? $listed['post_type'] : null,
	'post'
);

is_same(
	'every applicable group with terms is reported',
	is_array( $listed ) ? array_column( $listed['groups'], 'taxonomy' ) : null,
	[ 'category', 'post_tag' ],
	'The ability reports exactly what the site renders. There is no extra disclosure '
	. 'rule: the filter bar shows every enabled group to anonymous visitors, so '
	. 'withholding a group here would have been stricter than the public HTML.'
);

is_same(
	'a group whose taxonomy yields no terms is skipped',
	is_array( $listed ) ? count( $listed['groups'] ) : 0,
	2,
	'empty_tax is applicable but has no terms — an empty group would be noise in a '
	. 'model context window.'
);

is_same(
	'the group carries the taxonomy key that query-posts accepts',
	is_array( $listed ) ? $listed['groups'][0]['taxonomy'] : null,
	'category'
);

is_same(
	'terms carry slug, name and count',
	is_array( $listed ) ? $listed['groups'][0]['terms'][0] : null,
	[ 'slug' => 'news', 'name' => 'News', 'count' => 4 ]
);

$GLOBALS['_stub_raw_group_entries']['post_tag'] = [
	'not-an-array',
	[ 'term' => (object) [ 'slug' => 'bogus', 'name' => 'Bogus' ] ],
	[ 'is_active' => false ],
];

$malformed = jpkcom_postfilter_ability_list_filters( [ 'post_type' => 'post' ] );

is_same(
	'malformed term entries are skipped instead of fataling',
	is_array( $malformed ) ? array_column( $malformed['groups'], 'taxonomy' ) : null,
	[ 'category' ],
	'A stdClass where a WP_Term belongs would make ->slug a dynamic-property read and '
	. 'a missing entry a TypeError. On the WP 6.9 floor a Throwable escaping an ability '
	. 'callback is an uncaught fatal, not a WP_Error, so the instanceof guards are '
	. 'load-bearing. post_tag drops out entirely because none of its entries survive.'
);

$GLOBALS['_stub_raw_group_entries'] = [];

is_same(
	'a missing post_type defaults to post',
	is_array( jpkcom_postfilter_ability_list_filters( [] ) )
		? jpkcom_postfilter_ability_list_filters( [] )['post_type']
		: null,
	'post',
	'Core applies only a top-level schema default and only for null input; a nested '
	. 'properties.post_type.default is never filled in, so the callback must do it.'
);

$rejected_pt = jpkcom_postfilter_ability_list_filters( [ 'post_type' => 'projekt' ] );

check(
	'a post type that is not enabled is rejected',
	$rejected_pt instanceof WP_Error
);

check(
	'non-array input does not fatal',
	is_array( jpkcom_postfilter_ability_list_filters( 'post' ) )
		|| jpkcom_postfilter_ability_list_filters( 'post' ) instanceof WP_Error
);

section( 'query-posts callback' );

$post_one                = new WP_Post();
$post_one->ID            = 42;
$post_one->post_title    = 'Hello';
$post_one->post_date_gmt = '2026-08-01T09:00:00+00:00';
$post_one->post_excerpt  = 'An excerpt.';

$query                = new WP_Query();
$query->posts         = [ $post_one ];
$query->found_posts   = 19;
$query->max_num_pages = 2;

$GLOBALS['_stub_query']      = $query;
$GLOBALS['_stub_post_terms'] = [ '42:category' => [ [ 'news', 'News' ] ] ];

$result = jpkcom_postfilter_ability_query_posts(
	[
		'post_type' => 'post',
		'filters'   => [ 'category' => [ 'news' ] ],
		'page'      => 2,
		'per_page'  => 5,
	]
);

is_same( 'the total comes from found_posts', is_array( $result ) ? $result['total'] : null, 19 );
is_same( 'the page is echoed back', is_array( $result ) ? $result['page'] : null, 2 );
is_same( 'total_pages comes from max_num_pages', is_array( $result ) ? $result['total_pages'] : null, 2 );

is_same(
	'the normalised filters are echoed back',
	is_array( $result ) ? $result['filters'] : null,
	[ 'category' => [ 'news' ] ]
);

is_same(
	'a shareable filter URL is returned',
	is_array( $result ) ? $result['filter_url'] : null,
	'https://example.test/post/filter/category/'
);

is_same(
	'the post projection carries the fields the schema promises',
	is_array( $result ) ? $result['posts'][0] : null,
	[
		'id'      => 42,
		'title'   => 'Hello',
		'url'     => 'https://example.test/?p=42',
		'date'    => '2026-08-01T09:00:00+00:00',
		'excerpt' => 'An excerpt.',
		'terms'   => [ 'category' => [ [ 'slug' => 'news', 'name' => 'News' ] ] ],
	]
);

is_same(
	'a positive limit reaches build_query_args',
	$GLOBALS['_stub_query_args']['atts']['limit'],
	5,
	'A limit of -1 makes build_query_args set no_found_rows, which reports a total '
	. 'of 0 no matter how many posts exist.'
);

is_same(
	'the normalised filters reach build_query_args',
	$GLOBALS['_stub_query_args']['filters'],
	[ 'category' => [ 'news' ] ],
	'This is the assertion that catches a dropped second argument. Echoing filters back '
	. 'in the result proves nothing — that value is computed independently of the query. '
	. 'Without the filters, build_query_args() builds no tax_query and the ability answers '
	. 'every filtered request with the entire corpus.'
);

is_same(
	'the normalised filters reach run_query',
	$GLOBALS['_stub_run_query_args']['filters'],
	[ 'category' => [ 'news' ] ],
	'run_query() folds the filters into the cache key. Passing them to build_query_args() '
	. 'but not here would make two different filter combinations share one cache entry.'
);

is_same(
	'a query without a search term stays cacheable',
	array_key_exists( 'cache', $GLOBALS['_stub_run_query_args']['args'] ),
	false,
	'Taxonomy filter combinations are bounded by the configured groups, so their cache '
	. 'entries are bounded too. Only free-text search is unbounded.'
);

$searched = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'search' => 'wordpress security' ]
);

is_same(
	'a search term reaches the query',
	$GLOBALS['_stub_query_args']['atts']['s'],
	'wordpress security'
);

is_same(
	'a search query is not cached',
	$GLOBALS['_stub_run_query_args']['args']['cache'] ?? null,
	false,
	'run_query() caches under md5( serialize( $args ) ) and stores a serialised WP_Query '
	. 'with up to 50 full post objects, in the object cache and in APCu. search is '
	. 'caller-controlled free text, so caching it lets an authenticated caller fill both '
	. 'without bound. build_query_args() drops `cache` through its allowlist, so the key '
	. 'has to be set on the returned args.'
);

$GLOBALS['_stub_settings']['general']['enabled_post_types'] = [ 'post', 'page' ];
$GLOBALS['_stub_archiveless']                               = [ 'page' ];
$GLOBALS['_stub_filter_url_calls']                          = 0;

$archiveless = jpkcom_postfilter_ability_query_posts( [ 'post_type' => 'page' ] );

is_same(
	'a post type without an archive returns no filter URL',
	is_array( $archiveless ) ? $archiveless['filter_url'] : null,
	'',
	'get_archive_base_url() returns "" for a post type with no archive — page is public '
	. 'and selectable in the settings. trailingslashit("") is "/", so building a link '
	. 'would hand the caller the relative path "/filter/news/", and archive_base_regex() '
	. 'returns null for those post types so no rewrite rule stands behind it either. '
	. 'A model handing a user a 404 link is worse than one with no link to give.'
);

is_same(
	'no URL is built at all in that case',
	$GLOBALS['_stub_filter_url_calls'],
	0
);

$GLOBALS['_stub_archiveless']                               = [];
$GLOBALS['_stub_settings']['general']['enabled_post_types'] = [ 'post' ];

$GLOBALS['_stub_query']->posts = [ (object) [ 'ID' => 7 ], $post_one, 'not-a-post' ];

$malformed_posts = jpkcom_postfilter_ability_query_posts( [ 'post_type' => 'post' ] );

is_same(
	'non-WP_Post entries in the result set are skipped instead of fataling',
	is_array( $malformed_posts ) ? array_column( $malformed_posts['posts'], 'id' ) : null,
	[ 42 ],
	'project_post() type-hints \WP_Post, so an unguarded call on a stdClass is a '
	. 'TypeError — an uncaught fatal inside an ability callback on WP 6.9.'
);

$GLOBALS['_stub_query']->posts = [ $post_one ];

section( 'query-posts guards' );

$unknown_tax = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'filters' => [ 'tag' => [ 'seo' ] ] ]
);

check(
	'an unknown taxonomy is an error, not a full unfiltered result set',
	$unknown_tax instanceof WP_Error
		&& $unknown_tax->get_error_code() === 'jpkcom_postfilter_unknown_taxonomy'
);

$unknown_term = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'filters' => [ 'category' => [ 'does-not-exist' ] ] ]
);

check(
	'an unknown term slug is not an error',
	is_array( $unknown_term ),
	'It matches the website, which answers 200 with zero results and a noindex robots tag.'
);

is_same(
	'an unknown term slug is reported so zero results can be explained',
	is_array( $unknown_term ) ? $unknown_term['unknown_terms'] : null,
	[ 'category' => [ 'does-not-exist' ] ]
);

$GLOBALS['_stub_groups'][] = [ 'taxonomy' => 'jpkpf_ghost_tax', 'label' => 'Geist', 'post_types' => [ 'post' ] ];

$ghost_filtered = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'filters' => [ 'jpkpf_ghost_tax' => [ 'x' ] ] ]
);

check(
	'a filter group whose taxonomy is gone is rejected, not answered with everything',
	$ghost_filtered instanceof WP_Error
		&& $ghost_filtered->get_error_code() === 'jpkcom_postfilter_unknown_taxonomy',
	'The group is still configured, so the guard used to wave the filter through on the '
	. 'strength of the configuration alone — and the query pipeline then dropped the '
	. 'clause and returned the whole corpus as a filtered answer.'
);

check(
	'and the message names the taxonomies that really are registered',
	$ghost_filtered instanceof WP_Error
		&& str_contains( $ghost_filtered->get_error_message(), 'category' )
		&& str_contains( $ghost_filtered->get_error_message(), 'post_tag' )
);

array_pop( $GLOBALS['_stub_groups'] );

section( 'empty maps must encode as JSON objects, not arrays' );

is_same(
	'a known term slug produces no unknown_terms entry',
	is_array( $result ) ? wp_json_encode( $result['unknown_terms'] ) : null,
	'{}',
	'unknown_terms is declared type:object in the output schema. PHP serialises an '
	. 'empty array as [], which a client validating against that schema rejects. '
	. 'Asserting on the encoded form rather than the PHP type is what makes this '
	. 'test speak the same language as the consumer.'
);

$unfiltered_result = jpkcom_postfilter_ability_query_posts( [ 'post_type' => 'post' ] );

is_same(
	'an unfiltered query encodes filters as {}',
	is_array( $unfiltered_result ) ? wp_json_encode( $unfiltered_result['filters'] ) : null,
	'{}'
);

is_same(
	'an unfiltered query encodes unknown_terms as {}',
	is_array( $unfiltered_result ) ? wp_json_encode( $unfiltered_result['unknown_terms'] ) : null,
	'{}'
);

is_same(
	'a post with no terms of the filterable taxonomies encodes terms as {}',
	wp_json_encode( jpkcom_postfilter_ability_project_post( $post_one, [] )['terms'] ),
	'{}'
);

is_same(
	'a non-empty map still encodes as an object and keeps array access',
	is_array( $result ) ? wp_json_encode( $result['filters'] ) : null,
	'{"category":["news"]}',
	'Only the empty case is wrapped, so PHP callers keep array access on the case '
	. 'that carries data.'
);

is_same(
	'a non-empty map is still a PHP array',
	is_array( $result ) && is_array( $result['filters'] ),
	true
);

$GLOBALS['_stub_term_lookups']    = [];
$GLOBALS['_stub_term_hide_empty'] = null;

$many_slugs = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'filters' => [ 'category' => [ 'news', 'nope-one', 'nope-two', 'nope-three' ] ] ]
);

is_same(
	'the term check costs one lookup per taxonomy, not one per slug',
	$GLOBALS['_stub_term_lookups'],
	[ 'category' => 1 ],
	'Four slugs, one lookup. A per-slug get_term_by() would be four queries here and '
	. 'fifty at the input cap. The cached list is keyed by taxonomy — never by the '
	. 'requested slugs — so it cannot be used to flood the cache either. Same rule as '
	. 'jpkcom_postfilter_has_unknown_terms().'
);

is_same(
	'the lookup includes terms with no posts',
	$GLOBALS['_stub_term_hide_empty'],
	false,
	'A term with no posts is still a real term and a legitimate filter URL. With '
	. 'hide_empty = true it would be reported as a typo.'
);

is_same(
	'all unmatched slugs are reported, matched ones are not',
	is_array( $many_slugs ) ? $many_slugs['unknown_terms'] : null,
	[ 'category' => [ 'nope-one', 'nope-two', 'nope-three' ] ]
);

$GLOBALS['_stub_raw_terms']['category'] = [ 'not-a-term', (object) [ 'slug' => 'news' ] ];

$malformed_terms = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'filters' => [ 'category' => [ 'news' ] ] ]
);

is_same(
	'a malformed cached term list does not fatal, it just matches nothing',
	is_array( $malformed_terms ) ? $malformed_terms['unknown_terms'] : null,
	[ 'category' => [ 'news' ] ],
	'url-routing.php reads the same list through a \WP_Term-typed closure, which would '
	. 'throw here. Inside an ability callback on WP 6.9 that is an uncaught fatal, so '
	. 'this copy filters with instanceof instead.'
);

$GLOBALS['_stub_raw_terms'] = [];

$clamped = jpkcom_postfilter_ability_query_posts(
	[ 'post_type' => 'post', 'per_page' => 5000 ]
);

is_same(
	'an oversized per_page is clamped before it reaches the query',
	$GLOBALS['_stub_query_args']['atts']['limit'],
	50
);

check(
	'a post type that is not enabled is rejected',
	jpkcom_postfilter_ability_query_posts( [ 'post_type' => 'projekt' ] ) instanceof WP_Error
);

check(
	'non-array input does not fatal',
	is_array( jpkcom_postfilter_ability_query_posts( null ) )
);

section( 'ability definitions' );

$definitions = jpkcom_postfilter_get_ability_definitions();

is_same( 'exactly two abilities are defined', count( $definitions ), 2 );

foreach ( $definitions as $name => $args ) {
	check(
		"name '{$name}' matches the core registry regex",
		(bool) preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ),
		'Lowercase, hyphens and exactly one slash. Core rejects anything else, and it '
		. 'returns null with only a _doing_it_wrong() notice, which is silent in production.'
	);

	foreach ( [ 'label', 'description', 'category', 'execute_callback', 'permission_callback' ] as $key ) {
		check( "'{$name}' declares {$key}", isset( $args[ $key ] ) && $args[ $key ] !== '' );
	}

	check(
		"'{$name}' points at an existing execute callback",
		function_exists( $args['execute_callback'] )
	);

	check(
		"'{$name}' points at an existing permission callback",
		function_exists( $args['permission_callback'] )
	);

	check(
		"'{$name}' declares a non-empty input schema",
		isset( $args['input_schema']['properties'] ) && $args['input_schema']['properties'] !== [],
		'With an empty input schema core calls the callbacks with zero arguments, and '
		. 'passing input to such an ability is a hard ability_missing_input_schema error.'
	);

	is_same(
		"'{$name}' declares a top-level input default, so a bare call is not rejected",
		$args['input_schema']['default'] ?? null,
		[],
		'WP_Ability::normalize_input() substitutes the top-level default when the input is '
		. 'exactly null, and nothing else does. Without it, execute( null ) never reaches '
		. 'the callback — measured on WordPress 7.0.2, both abilities answered WP_Error '
		. 'ability_invalid_input, "input ist nicht vom Typ object". list-filters has one '
		. 'optional parameter, so calling it with no arguments is the most natural thing a '
		. 'client does. It must be a sibling of type and properties: core never applies a '
		. 'per-property default, which is why the callbacks resolve post_type, page and '
		. 'per_page themselves.'
	);

	check(
		"'{$name}' declares an output schema",
		isset( $args['output_schema']['type'] ) && $args['output_schema']['type'] === 'object'
	);

	foreach ( [ 'readonly', 'destructive', 'idempotent' ] as $annotation ) {
		check(
			"'{$name}' sets the {$annotation} annotation explicitly",
			isset( $args['meta']['annotations'][ $annotation ] )
				&& is_bool( $args['meta']['annotations'][ $annotation ] ),
			'Annotations default to null, and the REST run controller derives the required '
			. 'HTTP verb from them: an ability with no annotations is POST-only.'
		);
	}

	check(
		"'{$name}' is marked read-only",
		$args['meta']['annotations']['readonly'] === true
	);

	check(
		"'{$name}' opts into REST",
		$args['meta']['show_in_rest'] === true
	);

	check(
		"'{$name}' opts into MCP",
		$args['meta']['mcp']['public'] === true,
		'The MCP Adapter gates both discovery and execution on meta.mcp.public; without '
		. 'it the ability is invisible to MCP clients.'
	);

	foreach ( $args['input_schema']['properties'] as $property => $schema ) {
		check(
			"'{$name}' documents the input property {$property}",
			isset( $schema['description'] ) && $schema['description'] !== ''
		);
	}
}

check(
	'the category slug matches the stricter category regex',
	(bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', JPKCOM_POSTFILTER_ABILITY_CATEGORY ),
	'Category slugs may not contain a slash and may not start or end with a hyphen.'
);

is_same(
	'the schema maximum and the clamp cannot drift apart',
	$definitions['jpkcom-post-filter/query-posts']['input_schema']['properties']['per_page']['maximum'],
	jpkcom_postfilter_ability_clamp_per_page( 100000 )
);

section( 'permission callbacks' );

$GLOBALS['_stub_can'] = true;
check( 'permission granted when the capability is held', jpkcom_postfilter_ability_permission_query_posts( [] ) );

$GLOBALS['_stub_can'] = false;
check( 'permission denied otherwise', ! jpkcom_postfilter_ability_permission_query_posts( [] ) );

$GLOBALS['_stub_can'] = true;

section( 'registration guards' );

check(
	'registration is skipped when the Abilities API is absent',
	! jpkcom_postfilter_abilities_enabled(),
	'The test harness defines no wp_register_ability(), so the guard must refuse. '
	. 'This is what keeps the plugin from fataling on an installation without the API.'
);

$GLOBALS['_stub_debug_log_calls'] = 0;

jpkcom_postfilter_register_ability_category();

check(
	'the category registration is a no-op without the API',
	$GLOBALS['_stub_debug_log_calls'] === 0,
	'jpkcom_postfilter_register_ability_category() is void, so a raw return-value check is '
	. 'tautological - a void call always evaluates to NULL. It logs through '
	. 'jpkcom_postfilter_debug_log() only after getting past the abilities_enabled() guard and '
	. 'then failing to register. The test harness defines no wp_has_ability_category(), so '
	. 'bypassing the guard would fatal here rather than log - a non-zero count would mean the '
	. 'guard was skipped without a fatal masking it.'
);

$GLOBALS['_stub_debug_log_calls'] = 0;

jpkcom_postfilter_register_abilities();

check(
	'the ability registration is a no-op without the API',
	$GLOBALS['_stub_debug_log_calls'] === 0,
	'jpkcom_postfilter_register_abilities() is void, so a raw return-value check is '
	. 'tautological - a void call always evaluates to NULL. It logs through '
	. 'jpkcom_postfilter_debug_log() only after getting past the abilities_enabled() guard and '
	. 'then failing to register an ability. The test harness defines no wp_register_ability(), '
	. 'so bypassing the guard would fatal here rather than log - a non-zero count would mean '
	. 'the guard was skipped without a fatal masking it.'
);

section( 'registration with the Abilities API present' );

// Everything above this line ran with the API genuinely absent. From here on it
// exists, so the registration path itself can be exercised.
jpkpf_define_ability_api_stubs();

check(
	'the guard passes once the API is present',
	jpkcom_postfilter_abilities_enabled()
);

$GLOBALS['_stub_has_category']          = false;
$GLOBALS['_stub_registered_categories'] = [];
$GLOBALS['_stub_debug_log_calls']       = 0;

jpkcom_postfilter_register_ability_category();

is_same(
	'the category is registered when the registry reports it absent',
	array_keys( $GLOBALS['_stub_registered_categories'] ),
	[ JPKCOM_POSTFILTER_ABILITY_CATEGORY ]
);

check(
	'the category registration carries a label and a description',
	( $GLOBALS['_stub_registered_categories'][ JPKCOM_POSTFILTER_ABILITY_CATEGORY ]['label'] ?? '' ) !== ''
		&& ( $GLOBALS['_stub_registered_categories'][ JPKCOM_POSTFILTER_ABILITY_CATEGORY ]['description'] ?? '' ) !== ''
);

$GLOBALS['_stub_has_category']          = true;
$GLOBALS['_stub_registered_categories'] = [];

jpkcom_postfilter_register_ability_category();

is_same(
	'an existing category is not re-registered',
	$GLOBALS['_stub_registered_categories'],
	[],
	'Categories are global and first-wins. A sibling JPKCom plugin may have registered '
	. 'this slug already, and re-registering would fail silently.'
);

$GLOBALS['_stub_has_category']          = false;
$GLOBALS['_stub_category_returns_null'] = true;
$GLOBALS['_stub_registered_categories'] = [];
$GLOBALS['_stub_debug_log_calls']       = 0;

jpkcom_postfilter_register_ability_category();

is_same(
	'a failed category registration is logged',
	$GLOBALS['_stub_debug_log_calls'],
	1,
	'wp_register_ability_category() reports failure only through _doing_it_wrong(), '
	. 'which is silent in production.'
);

$GLOBALS['_stub_category_returns_null'] = false;

$GLOBALS['_stub_registered_abilities'] = [];
$GLOBALS['_stub_debug_log_calls']      = 0;

jpkcom_postfilter_register_abilities();

is_same(
	'both abilities are registered, by name',
	array_keys( $GLOBALS['_stub_registered_abilities'] ),
	[ 'jpkcom-post-filter/list-filters', 'jpkcom-post-filter/query-posts' ],
	'The whole feature is one wp_register_ability() call per ability. Nothing else in '
	. 'the suite reaches this line.'
);

is_same(
	'both are registered into the shared category',
	array_column( $GLOBALS['_stub_registered_abilities'], 'category' ),
	[ JPKCOM_POSTFILTER_ABILITY_CATEGORY, JPKCOM_POSTFILTER_ABILITY_CATEGORY ]
);

is_same(
	'a successful registration logs nothing',
	$GLOBALS['_stub_debug_log_calls'],
	0
);

$GLOBALS['_stub_register_null_for'] = 'jpkcom-post-filter/query-posts';
$GLOBALS['_stub_debug_log_calls']   = 0;

jpkcom_postfilter_register_abilities();

is_same(
	'a null return is detected and logged, once, for the failing ability only',
	$GLOBALS['_stub_debug_log_calls'],
	1,
	'wp_register_ability() returns null on every failure path and reports only through '
	. '_doing_it_wrong(). Both are silent in production, so the check cannot surface '
	. 'anything there — but without it the code would not even notice.'
);

$GLOBALS['_stub_register_null_for'] = '';

// The kill switch is a constant, so it takes a second process to observe it off.
$child_output = null;

if ( function_exists( 'exec' ) ) {
	putenv( 'JPKPF_ABILITIES_KILL_SWITCH_CHILD=1' );

	$lines  = [];
	$status = 0;

	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $lines, $status );

	putenv( 'JPKPF_ABILITIES_KILL_SWITCH_CHILD' );

	foreach ( $lines as $line ) {
		if ( str_starts_with( $line, 'KILL_SWITCH ' ) ) {
			$child_output = $line;
		}
	}
}

if ( $child_output === null ) {
	echo "  SKIP  the kill switch could not be exercised (no usable exec())\n";
} else {
	is_same(
		'JPKCOM_POSTFILTER_ABILITIES = false registers nothing at all',
		$child_output,
		'KILL_SWITCH enabled=0 categories=0 abilities=0',
		'Run in a child process with the constant defined false and the Abilities API '
		. 'fully present, so anything registered would be the switch failing to hold. '
		. 'A documented wp-config.php escape hatch that silently did nothing would be '
		. 'worse than none.'
	);
}

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
