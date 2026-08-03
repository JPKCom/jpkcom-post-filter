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

class WP_Term {
	public string $slug  = '';
	public string $name  = '';
	public int    $count = 0;
}

class WP_Taxonomy {
	public bool $public       = true;
	public bool $show_in_rest = true;
}

$GLOBALS['_stub_settings']   = [ 'general' => [ 'enabled_post_types' => [ 'post' ] ] ];
$GLOBALS['_stub_groups']     = [];
$GLOBALS['_stub_terms']      = [];   // taxonomy => [ [slug, name, count], ... ]
$GLOBALS['_stub_taxonomies'] = [];   // taxonomy => [ 'public' => bool, 'show_in_rest' => bool ]

function jpkcom_postfilter_settings_get( string $group, string $key, mixed $default = null ): mixed {
	return $GLOBALS['_stub_settings'][ $group ][ $key ] ?? $default;
}

function jpkcom_postfilter_get_filter_groups_enabled(): array {
	return $GLOBALS['_stub_groups'];
}

function jpkcom_postfilter_get_terms_for_group( array $group, array $active_filters = [] ): array {
	$taxonomy = (string) ( $group['taxonomy'] ?? '' );
	$out      = [];

	foreach ( $GLOBALS['_stub_terms'][ $taxonomy ] ?? [] as $row ) {
		$term        = new WP_Term();
		$term->slug  = $row[0];
		$term->name  = $row[1];
		$term->count = $row[2];
		$out[]       = [ 'term' => $term, 'is_active' => false ];
	}

	return $out;
}

function taxonomy_exists( string $taxonomy ): bool {
	return isset( $GLOBALS['_stub_taxonomies'][ $taxonomy ] );
}

function get_taxonomy( string $taxonomy ): WP_Taxonomy|false {
	if ( ! isset( $GLOBALS['_stub_taxonomies'][ $taxonomy ] ) ) {
		return false;
	}

	$object               = new WP_Taxonomy();
	$object->public       = $GLOBALS['_stub_taxonomies'][ $taxonomy ]['public'];
	$object->show_in_rest = $GLOBALS['_stub_taxonomies'][ $taxonomy ]['show_in_rest'];

	return $object;
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

$GLOBALS['_stub_query']      = null;   // WP_Query returned by run_query()
$GLOBALS['_stub_query_args'] = [];     // captured build_query_args() input
$GLOBALS['_stub_post_terms'] = [];     // "postID:taxonomy" => [ [slug, name], ... ]

function jpkcom_postfilter_build_query_args( array $atts, array $active_filters = [] ): array {
	$GLOBALS['_stub_query_args'] = [ 'atts' => $atts, 'filters' => $active_filters ];

	return $atts;
}

function jpkcom_postfilter_run_query( array $query_args, array $active_filters = [] ): WP_Query {
	return $GLOBALS['_stub_query'] ?? new WP_Query();
}

function jpkcom_postfilter_get_archive_base_url( string $post_type ): string {
	return 'https://example.test/' . $post_type . '/';
}

function jpkcom_postfilter_get_filter_url( string $base_url, array $filters, int $page = 0 ): string {
	return $base_url . 'filter/' . implode( '-', array_keys( $filters ) ) . '/';
}

function get_term_by( string $field, string $value, string $taxonomy ): WP_Term|false {
	foreach ( $GLOBALS['_stub_terms'][ $taxonomy ] ?? [] as $row ) {
		if ( $row[0] === $value ) {
			$term        = new WP_Term();
			$term->slug  = $row[0];
			$term->name  = $row[1];
			$term->count = $row[2];

			return $term;
		}
	}

	return false;
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

section( 'list-filters callback' );

$GLOBALS['_stub_settings']['general']['enabled_post_types'] = [ 'post' ];
$GLOBALS['_stub_groups']                                    = [
	[ 'taxonomy' => 'category', 'label' => 'Kategorie', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'post_tag', 'label' => 'Schlagwort', 'post_types' => [] ],
	[ 'taxonomy' => 'public_only', 'label' => 'Public Only', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'rest_only', 'label' => 'REST Only', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'secret_tax', 'label' => 'Intern', 'post_types' => [ 'post' ] ],
];
$GLOBALS['_stub_terms'] = [
	'category'    => [ [ 'news', 'News', 4 ] ],
	'post_tag'    => [ [ 'seo', 'SEO', 6 ] ],
	'public_only' => [ [ 'pub', 'Public Term', 2 ] ],
	'rest_only'   => [ [ 'rest', 'REST Term', 3 ] ],
	'secret_tax'  => [ [ 'hidden', 'Hidden', 1 ] ],
];
$GLOBALS['_stub_taxonomies'] = [
	'category'    => [ 'public' => true, 'show_in_rest' => true ],
	'post_tag'    => [ 'public' => true, 'show_in_rest' => true ],
	'public_only' => [ 'public' => true, 'show_in_rest' => false ],
	'rest_only'   => [ 'public' => false, 'show_in_rest' => true ],
	'secret_tax'  => [ 'public' => false, 'show_in_rest' => false ],
];

$listed = jpkcom_postfilter_ability_list_filters( [ 'post_type' => 'post' ] );

is_same(
	'the post type is echoed back',
	is_array( $listed ) ? $listed['post_type'] : null,
	'post'
);

is_same(
	'a fully private taxonomy is not disclosed',
	is_array( $listed ) ? count( $listed['groups'] ) : 0,
	4,
	'A taxonomy that is neither public nor REST-exposed must not be handed to a '
	. 'subscriber-level caller, and ability listings are readable by any logged-in user. '
	. 'Mixed-state taxonomies (public OR REST-exposed) must be disclosed: 4 groups.'
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

check(
	'a taxonomy with public=true, show_in_rest=false is disclosed (OR logic)',
	is_array( $listed ) && count( $listed['groups'] ) >= 3
		&& ( $listed['groups'][2]['taxonomy'] === 'public_only' || in_array( 'public_only', array_column( $listed['groups'], 'taxonomy' ), true ) ),
	'The disclosure check must use OR not AND: public is sufficient.'
);

check(
	'a taxonomy with public=false, show_in_rest=true is disclosed (OR logic)',
	is_array( $listed ) && count( $listed['groups'] ) >= 4
		&& in_array( 'rest_only', array_column( $listed['groups'], 'taxonomy' ), true ),
	'The disclosure check must use OR not AND: show_in_rest is sufficient.'
);

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

is_same(
	'a known term slug produces no unknown_terms entry',
	is_array( $result ) ? $result['unknown_terms'] : null,
	[]
);

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

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
