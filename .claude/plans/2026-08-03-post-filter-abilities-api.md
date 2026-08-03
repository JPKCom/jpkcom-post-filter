# Post Filter Abilities API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the plugin's faceted query capability as two read-only WordPress Abilities — `jpkcom-post-filter/list-filters` and `jpkcom-post-filter/query-posts` — so MCP clients, REST automation and the WordPress AI client can discover the available filters and run filtered queries without guessing taxonomy names.

**Architecture:** One new procedural include, `includes/abilities.php`, added to the ordered require list. It reuses the existing query pipeline (`jpkcom_postfilter_build_query_args()` → `jpkcom_postfilter_run_query()`) and adds the validation that pipeline deliberately does not do. Registration argument arrays come from a pure builder function so the CI harness — which never loads WordPress — can assert their shape.

**Tech Stack:** PHP 8.3, WordPress 6.9+ Abilities API (`wp_register_ability`), no Composer, no PHPUnit. Tests are dependency-free PHP scripts.

**Spec:** `.claude/specs/2026-08-03-post-filter-abilities-api-design.md`

**Branch:** `abilities-api` (already created, spec committed as `927ead6`)

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP floor: 8.3.** WordPress floor: **6.9** (`jpkcom-post-filter.php:11`).
- **Never throw.** On WP 6.9 a `Throwable` escaping an ability callback is an uncaught fatal — the `Throwable → WP_Error` wrapper only landed in 7.0. Every callback returns `WP_Error` on failure. Never type-hint a closure parameter in a way that can produce a `TypeError` on unexpected data; check with `instanceof` and skip instead.
- **No WP 7.1-only API.** Forbidden: `wp_ability_invoked`, `wp_ability_validate_input`, `wp_ability_validate_output`, `wp_pre_execute_ability`, `wp_ability_normalize_input`, `wp_ability_permission_result`, `wp_ability_execute_result`, the `$ability` argument on `wp_before/after_execute_ability`, `wp_get_abilities( $args )`. WP 7.1 is beta until 2026-08-19.
- **Every PHP file** starts with a docblock ending in `@package JPKCom_Post_Filter` + `@since 1.3.0`, then `declare(strict_types=1);`, then `if ( ! defined( constant_name: 'ABSPATH' ) ) { exit; }`.
- **Every function in `includes/`** is wrapped in `if ( ! function_exists( function: '…' ) ) { … }`, fully typed including the return type, and prefixed `jpkcom_postfilter_`.
- **Named arguments** are house style for internal PHP and WordPress functions — but **never bound to a variadic parameter**. `sprintf()`, `current_user_can()`, `array_map()` and friends must be called positionally. `.github/workflows/ci.yml:57-116` fails the build otherwise, because such a call throws `ArgumentCountError` at runtime and `php -l` does not catch it.
- **Indentation:** 4 spaces in `includes/`, **tabs** in `tests/`. Do not unify them.
- **i18n:** `__()` with the literal text domain `'jpkcom-post-filter'` — never a constant. English source strings.
- **Test filename:** must match `tests/test-*.php` or CI never runs it (`.github/workflows/ci.yml:118-135`).
- **Commits:** no `Co-Authored-By` trailer, no Claude/Anthropic attribution.
- **Local checks** before every commit:
  `find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l`
  `php tests/test-abilities.php && php tests/test-security.php && php tests/test-fragment.php`

## File Structure

| File | Responsibility |
|---|---|
| `includes/abilities.php` (new) | Everything ability-related: input normalisation, validation, the two execute callbacks, permission callbacks, the definitions builder, and registration. Single responsibility — nothing else in the plugin changes behaviour. |
| `tests/test-abilities.php` (new) | Self-contained regression tests. Stubs WordPress *and* the plugin's own data-access functions, then exercises the callbacks in-process. |
| `jpkcom-post-filter.php` (modify) | Add the `JPKCOM_POSTFILTER_ABILITIES` constant; add `includes/abilities.php` to the include array; version bump. |
| `README.md`, `CLAUDE.md` (modify) | Constants table, filter documentation, changelog. |
| `phpdoc.xml` (modify) | Version. |

`includes/abilities.php` ends up around 850 lines. (The original estimate of 450 was wrong — it was already at 579 after Task 4, before the two JSON schemas landed. Reviewed and ruled on after Task 4: the file stays whole.) That is in line with the plugin's other feature files — `url-routing.php` is 733 lines and `settings.php` 969 — and it is one cohesive responsibility, so it is not split.

---

### Task 1: Input normalisation helpers

**Files:**
- Create: `includes/abilities.php`
- Create: `tests/test-abilities.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `jpkcom_postfilter_ability_clamp_per_page( mixed $value ): int`
  - `jpkcom_postfilter_ability_normalize_filters( mixed $filters ): array` — returns `array<string, string[]>`
  - Constants `JPKCOM_POSTFILTER_ABILITY_CATEGORY` (string), `JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT` (int), `JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX` (int)

- [ ] **Step 1: Write the failing test**

Create `tests/test-abilities.php` (tabs for indentation):

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test-abilities.php`
Expected: `PHP Warning: require_once(...includes/abilities.php): Failed to open stream` followed by a fatal error. The file does not exist yet.

- [ ] **Step 3: Create `includes/abilities.php` with the two helpers**

```php
<?php
/**
 * Abilities API integration
 *
 * Registers read-only WordPress Abilities that expose the plugin's faceted
 * query capability to MCP clients, REST automation and the WordPress AI
 * client. Registration is skipped entirely when the Abilities API is absent or
 * when JPKCOM_POSTFILTER_ABILITIES is false.
 *
 * @package JPKCom_Post_Filter
 * @since 1.3.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

/**
 * Ability category shared with the other JPKCom content plugins.
 */
const JPKCOM_POSTFILTER_ABILITY_CATEGORY = 'jpkcom-content';

/**
 * Default page size for the query ability.
 */
const JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT = 10;

/**
 * Maximum page size for the query ability.
 */
const JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX = 50;


if ( ! function_exists( function: 'jpkcom_postfilter_ability_clamp_per_page' ) ) {
    /**
     * Clamp a per_page value into the range the query ability supports
     *
     * Guards against the shortcode default of -1, which makes
     * jpkcom_postfilter_build_query_args() set no_found_rows and therefore
     * report a total of 0 regardless of how many posts exist.
     *
     * @since 1.3.0
     *
     * @param mixed $value Raw per_page input.
     * @return int Value between 1 and JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX.
     */
    function jpkcom_postfilter_ability_clamp_per_page( mixed $value ): int {
        if ( ! is_numeric( $value ) ) {
            return JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT;
        }

        $per_page = (int) $value;

        if ( $per_page < 1 ) {
            return JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT;
        }

        return min( $per_page, JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_normalize_filters' ) ) {
    /**
     * Normalise the filters input into a taxonomy => term slugs map
     *
     * Accepts a scalar term as shorthand for a single-element list, drops
     * duplicates and empty taxonomies, and skips values that are not scalar
     * rather than letting a TypeError escape.
     *
     * @since 1.3.0
     *
     * @param mixed $filters Raw filters input.
     * @return array<string, string[]> Normalised taxonomy => term slugs map.
     */
    function jpkcom_postfilter_ability_normalize_filters( mixed $filters ): array {
        if ( ! is_array( $filters ) ) {
            return [];
        }

        $normalized = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $taxonomy_key = sanitize_key( (string) $taxonomy );

            if ( $taxonomy_key === '' ) {
                continue;
            }

            $slug_list = is_array( $slugs ) ? $slugs : [ $slugs ];
            $clean     = [];

            foreach ( $slug_list as $slug ) {
                if ( ! is_scalar( $slug ) ) {
                    continue;
                }

                $clean_slug = sanitize_title( (string) $slug );

                if ( $clean_slug === '' ) {
                    continue;
                }

                if ( ! in_array( needle: $clean_slug, haystack: $clean, strict: true ) ) {
                    $clean[] = $clean_slug;
                }
            }

            if ( $clean !== [] ) {
                $normalized[ $taxonomy_key ] = $clean;
            }
        }

        return $normalized;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 5: Lint and commit**

```bash
php -l includes/abilities.php
php -l tests/test-abilities.php
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add ability input normalisation helpers

Clamps per_page so the shortcode default of -1 can never reach
build_query_args(), where it sets no_found_rows and zeroes the reported
total. Normalises the filters map and skips non-scalar values, because an
uncaught TypeError inside an ability callback is a fatal on the WordPress
6.9 floor."
```

---

### Task 2: Filter-group resolution and taxonomy validation

**Files:**
- Modify: `includes/abilities.php` (append)
- Modify: `tests/test-abilities.php` (append tests before the `printf` summary)

**Interfaces:**
- Consumes: `jpkcom_postfilter_ability_normalize_filters()` from Task 1
- Produces:
  - `jpkcom_postfilter_ability_group_applies( array $group, string $post_type, array $enabled_post_types ): bool`
  - `jpkcom_postfilter_ability_allowed_taxonomies( string $post_type, array $groups, array $enabled_post_types ): array` — returns `string[]`
  - `jpkcom_postfilter_ability_validate_filters( array $filters, array $allowed_taxonomies ): true|\WP_Error`
  - `jpkcom_postfilter_ability_unknown_post_type_error( string $post_type, array $enabled_post_types ): \WP_Error`

All four are pure — they take the group list and the enabled post types as parameters rather than reading settings — which is what makes them testable without WordPress.

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-abilities.php`, immediately **before** the `printf( "\n  %d passed…` line:

```php
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
	. 'that writes a taxonomy name the site does not have presents the whole site as a '
	. 'filtered answer.'
);

is_same(
	'the error code is stable',
	$rejected instanceof WP_Error ? $rejected->get_error_code() : '',
	'jpkcom_postfilter_unknown_taxonomy'
);

check(
	'the message names the offending taxonomy',
	$rejected instanceof WP_Error && str_contains( $rejected->get_error_message(), 'nonexistent' ),
	'The rejected name must share no substring with any valid taxonomy. With "tag" as the '
	. 'rejected key this assertion was vacuous, because "post_tag" contains "tag" and the '
	. 'check passed even when the message named nothing at all.'
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/test-abilities.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function jpkcom_postfilter_ability_group_applies()`.

- [ ] **Step 3: Append the implementation to `includes/abilities.php`**

```php
if ( ! function_exists( function: 'jpkcom_postfilter_ability_group_applies' ) ) {
    /**
     * Decide whether a filter group applies to a post type
     *
     * Mirrors the rule used by all three render call sites: a group applies
     * when its post_types list contains the post type, or - when that list is
     * empty or absent - when the post type is globally enabled.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $group              Filter group config.
     * @param string               $post_type          Post type to test.
     * @param string[]             $enabled_post_types Globally enabled post types.
     * @return bool True when the group applies.
     */
    function jpkcom_postfilter_ability_group_applies( array $group, string $post_type, array $enabled_post_types ): bool {
        $group_post_types = $group['post_types'] ?? [];

        if ( ! is_array( $group_post_types ) || $group_post_types === [] ) {
            return in_array( needle: $post_type, haystack: $enabled_post_types, strict: true );
        }

        return in_array( needle: $post_type, haystack: $group_post_types, strict: true );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_allowed_taxonomies' ) ) {
    /**
     * Collect the taxonomies that may be used to filter a post type
     *
     * @since 1.3.0
     *
     * @param string                            $post_type          Post type to test.
     * @param array<int, array<string, mixed>>  $groups             Enabled filter groups.
     * @param string[]                          $enabled_post_types Globally enabled post types.
     * @return string[] Unique taxonomy keys.
     */
    function jpkcom_postfilter_ability_allowed_taxonomies( string $post_type, array $groups, array $enabled_post_types ): array {
        $allowed = [];

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $taxonomy = (string) ( $group['taxonomy'] ?? '' );

            if ( $taxonomy === '' ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_group_applies( $group, $post_type, $enabled_post_types ) ) {
                continue;
            }

            if ( ! in_array( needle: $taxonomy, haystack: $allowed, strict: true ) ) {
                $allowed[] = $taxonomy;
            }
        }

        return $allowed;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_validate_filters' ) ) {
    /**
     * Reject filters that name a taxonomy which is not filterable
     *
     * The query pipeline drops an unknown taxonomy clause silently and then
     * returns the complete unfiltered result set, so this check is the only
     * thing standing between a mistyped taxonomy and a wrong answer presented
     * as a filtered one. The error message names the valid taxonomies so the
     * caller can correct itself without another round trip.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters            Normalised filters map.
     * @param string[]                $allowed_taxonomies Taxonomies that may be filtered.
     * @return true|\WP_Error True when valid, WP_Error otherwise.
     */
    function jpkcom_postfilter_ability_validate_filters( array $filters, array $allowed_taxonomies ): true|\WP_Error {
        $unknown = [];

        foreach ( array_keys( $filters ) as $taxonomy ) {
            if ( ! in_array( needle: (string) $taxonomy, haystack: $allowed_taxonomies, strict: true ) ) {
                $unknown[] = (string) $taxonomy;
            }
        }

        if ( $unknown === [] ) {
            return true;
        }

        $valid = $allowed_taxonomies === []
            ? __( 'none for this post type', 'jpkcom-post-filter' )
            : implode( ', ', $allowed_taxonomies );

        return new \WP_Error(
            'jpkcom_postfilter_unknown_taxonomy',
            sprintf(
                /* translators: 1: comma-separated rejected taxonomy keys, 2: comma-separated valid taxonomy keys. */
                __( 'Unknown filter taxonomy: %1$s. Valid taxonomies for this post type: %2$s.', 'jpkcom-post-filter' ),
                implode( ', ', $unknown ),
                $valid
            )
        );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_unknown_post_type_error' ) ) {
    /**
     * Build the error returned for a post type that is not enabled for filtering
     *
     * @since 1.3.0
     *
     * @param string   $post_type          The rejected post type.
     * @param string[] $enabled_post_types Post types that are enabled.
     * @return \WP_Error The error to return from the callback.
     */
    function jpkcom_postfilter_ability_unknown_post_type_error( string $post_type, array $enabled_post_types ): \WP_Error {
        $valid = $enabled_post_types === []
            ? __( 'none', 'jpkcom-post-filter' )
            : implode( ', ', $enabled_post_types );

        return new \WP_Error(
            'jpkcom_postfilter_unknown_post_type',
            sprintf(
                /* translators: 1: rejected post type, 2: comma-separated enabled post types. */
                __( 'Post type "%1$s" is not enabled for filtering. Enabled post types: %2$s.', 'jpkcom-post-filter' ),
                $post_type,
                $valid
            )
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 5: Lint and commit**

```bash
php -l includes/abilities.php
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add taxonomy validation for the ability query input

build_tax_query() drops a clause for a taxonomy that does not exist and the
query then returns the complete unfiltered corpus with no error - measured
as 19 of 19 posts for a filter naming a non-existent taxonomy. The ability
now rejects such input and names the valid taxonomies in the message so a
caller can correct itself in one turn."
```

---

### Task 3: `list-filters` execute callback

**Files:**
- Modify: `includes/abilities.php` (append)
- Modify: `tests/test-abilities.php` (append stubs near the top, tests before the summary)

**Interfaces:**
- Consumes: `jpkcom_postfilter_ability_group_applies()`, `jpkcom_postfilter_ability_unknown_post_type_error()` from Task 2
- Produces:
  - `jpkcom_postfilter_ability_resolve_post_type( mixed $value ): string`
  - `jpkcom_postfilter_ability_enabled_post_types(): array`
  - `jpkcom_postfilter_ability_taxonomy_is_disclosable( string $taxonomy ): bool`
  - `jpkcom_postfilter_ability_list_filters( mixed $input ): array|\WP_Error`

- [ ] **Step 1: Add the plugin-function stubs to the test file**

Insert into `tests/test-abilities.php` **after** the `WP_Error` class and **before** the `require_once` line:

```php
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

function jpkcom_postfilter_debug_log( string $message, mixed $context = null ): void {}
```

- [ ] **Step 2: Write the failing tests**

Append before the `printf` summary line:

```php
section( 'list-filters callback' );

$GLOBALS['_stub_settings']['general']['enabled_post_types'] = [ 'post' ];
$GLOBALS['_stub_groups']                                    = [
	[ 'taxonomy' => 'category', 'label' => 'Kategorie', 'post_types' => [ 'post' ] ],
	[ 'taxonomy' => 'post_tag', 'label' => 'Schlagwort', 'post_types' => [] ],
	[ 'taxonomy' => 'secret_tax', 'label' => 'Intern', 'post_types' => [ 'post' ] ],
];
$GLOBALS['_stub_terms'] = [
	'category'   => [ [ 'news', 'News', 4 ] ],
	'post_tag'   => [ [ 'seo', 'SEO', 6 ] ],
	'secret_tax' => [ [ 'hidden', 'Hidden', 1 ] ],
];
$GLOBALS['_stub_taxonomies'] = [
	'category'   => [ 'public' => true, 'show_in_rest' => true ],
	'post_tag'   => [ 'public' => true, 'show_in_rest' => true ],
	'secret_tax' => [ 'public' => false, 'show_in_rest' => false ],
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
	2,
	'A taxonomy that is neither public nor REST-exposed must not be handed to a '
	. 'subscriber-level caller, and ability listings are readable by any logged-in user.'
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php tests/test-abilities.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function jpkcom_postfilter_ability_list_filters()`.

- [ ] **Step 4: Append the implementation to `includes/abilities.php`**

```php
if ( ! function_exists( function: 'jpkcom_postfilter_ability_resolve_post_type' ) ) {
    /**
     * Resolve the post_type input, applying the default the schema advertises
     *
     * Core applies only a top-level schema default and only when the input is
     * exactly null, so per-property defaults have to be applied here.
     *
     * @since 1.3.0
     *
     * @param mixed $value Raw post_type input.
     * @return string Sanitised post type, defaulting to 'post'.
     */
    function jpkcom_postfilter_ability_resolve_post_type( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return 'post';
        }

        $post_type = sanitize_key( $value );

        return $post_type === '' ? 'post' : $post_type;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_enabled_post_types' ) ) {
    /**
     * Read the post types that are enabled for filtering
     *
     * @since 1.3.0
     *
     * @return string[] Enabled post type slugs.
     */
    function jpkcom_postfilter_ability_enabled_post_types(): array {
        $enabled = jpkcom_postfilter_settings_get( 'general', 'enabled_post_types', [ 'post' ] );

        if ( ! is_array( $enabled ) ) {
            return [ 'post' ];
        }

        $clean = [];

        foreach ( $enabled as $post_type ) {
            if ( is_string( $post_type ) && $post_type !== '' ) {
                $clean[] = $post_type;
            }
        }

        return $clean;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_taxonomy_is_disclosable' ) ) {
    /**
     * Decide whether a taxonomy may be named in an ability response
     *
     * Ability listings are readable by any logged-in user, so a taxonomy that
     * is neither public nor REST-exposed is withheld.
     *
     * @since 1.3.0
     *
     * @param string $taxonomy Taxonomy key.
     * @return bool True when the taxonomy may be disclosed.
     */
    function jpkcom_postfilter_ability_taxonomy_is_disclosable( string $taxonomy ): bool {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }

        $object = get_taxonomy( $taxonomy );

        if ( $object === false ) {
            return false;
        }

        return (bool) $object->public || (bool) $object->show_in_rest;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_list_filters' ) ) {
    /**
     * Execute callback for jpkcom-post-filter/list-filters
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input.
     * @return array<string, mixed>|\WP_Error Filter groups, or an error.
     */
    function jpkcom_postfilter_ability_list_filters( mixed $input ): array|\WP_Error {
        $input     = is_array( $input ) ? $input : [];
        $post_type = jpkcom_postfilter_ability_resolve_post_type( $input['post_type'] ?? null );

        $enabled_post_types = jpkcom_postfilter_ability_enabled_post_types();

        if ( ! in_array( needle: $post_type, haystack: $enabled_post_types, strict: true ) ) {
            return jpkcom_postfilter_ability_unknown_post_type_error( $post_type, $enabled_post_types );
        }

        $groups = [];

        foreach ( jpkcom_postfilter_get_filter_groups_enabled() as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $taxonomy = (string) ( $group['taxonomy'] ?? '' );

            if ( $taxonomy === '' ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_group_applies( $group, $post_type, $enabled_post_types ) ) {
                continue;
            }

            if ( ! jpkcom_postfilter_ability_taxonomy_is_disclosable( $taxonomy ) ) {
                continue;
            }

            $terms = [];

            foreach ( jpkcom_postfilter_get_terms_for_group( $group ) as $entry ) {
                $term = is_array( $entry ) ? ( $entry['term'] ?? null ) : null;

                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }

                $terms[] = [
                    'slug'  => (string) $term->slug,
                    'name'  => (string) $term->name,
                    'count' => (int) $term->count,
                ];
            }

            if ( $terms === [] ) {
                continue;
            }

            $groups[] = [
                'taxonomy' => $taxonomy,
                'label'    => (string) ( $group['label'] ?? $taxonomy ),
                'terms'    => $terms,
            ];
        }

        return [
            'post_type' => $post_type,
            'groups'    => $groups,
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 6: Lint and commit**

```bash
php -l includes/abilities.php
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add the list-filters ability callback

Returns the filter groups and terms that apply to a post type, keyed by the
taxonomy value that query-posts accepts, so callers never have to guess.
Groups are read defensively because live installations store only four of
the twelve keys the sanitiser produces, and a taxonomy that is neither
public nor REST-exposed is withheld - ability listings are readable by any
logged-in user."
```

---

### Task 4: `query-posts` execute callback

**Files:**
- Modify: `includes/abilities.php` (append)
- Modify: `tests/test-abilities.php` (append stubs and tests)

**Interfaces:**
- Consumes: `jpkcom_postfilter_ability_clamp_per_page()`, `jpkcom_postfilter_ability_normalize_filters()` (Task 1), `jpkcom_postfilter_ability_allowed_taxonomies()`, `jpkcom_postfilter_ability_validate_filters()`, `jpkcom_postfilter_ability_unknown_post_type_error()` (Task 2), `jpkcom_postfilter_ability_resolve_post_type()`, `jpkcom_postfilter_ability_enabled_post_types()` (Task 3)
- Produces:
  - `jpkcom_postfilter_ability_unknown_terms( array $filters ): array`
  - `jpkcom_postfilter_ability_project_post( \WP_Post $post, array $taxonomies ): array`
  - `jpkcom_postfilter_ability_query_posts( mixed $input ): array|\WP_Error`

- [ ] **Step 1: Add the remaining stubs to the test file**

Insert into `tests/test-abilities.php` **before** the `require_once` line, after the Task 3 stubs:

```php
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
```

- [ ] **Step 2: Write the failing tests**

Append before the `printf` summary line:

```php
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php tests/test-abilities.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function jpkcom_postfilter_ability_query_posts()`.

- [ ] **Step 4: Append the implementation to `includes/abilities.php`**

```php
if ( ! function_exists( function: 'jpkcom_postfilter_ability_unknown_terms' ) ) {
    /**
     * Report requested term slugs that match no term
     *
     * Not an error: the website answers such a request with HTTP 200, zero
     * results and a noindex robots tag. Reporting them lets a caller tell a
     * typo apart from an genuinely empty result set.
     *
     * @since 1.3.0
     *
     * @param array<string, string[]> $filters Normalised filters map.
     * @return array<string, string[]> Taxonomy => unmatched term slugs.
     */
    function jpkcom_postfilter_ability_unknown_terms( array $filters ): array {
        $unknown = [];

        foreach ( $filters as $taxonomy => $slugs ) {
            $missing = [];

            foreach ( $slugs as $slug ) {
                if ( ! get_term_by( 'slug', $slug, (string) $taxonomy ) instanceof \WP_Term ) {
                    $missing[] = $slug;
                }
            }

            if ( $missing !== [] ) {
                $unknown[ (string) $taxonomy ] = $missing;
            }
        }

        return $unknown;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_project_post' ) ) {
    /**
     * Project a post into the JSON-serialisable shape the output schema promises
     *
     * @since 1.3.0
     *
     * @param \WP_Post $post       The post to project.
     * @param string[] $taxonomies Filterable taxonomies to report terms for.
     * @return array<string, mixed> Projected post.
     */
    function jpkcom_postfilter_ability_project_post( \WP_Post $post, array $taxonomies ): array {
        $terms = [];

        foreach ( $taxonomies as $taxonomy ) {
            $assigned = get_the_terms( $post, $taxonomy );

            if ( ! is_array( $assigned ) ) {
                continue;
            }

            $list = [];

            foreach ( $assigned as $term ) {
                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }

                $list[] = [
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                ];
            }

            if ( $list !== [] ) {
                $terms[ $taxonomy ] = $list;
            }
        }

        return [
            'id'      => (int) $post->ID,
            'title'   => (string) get_the_title( $post ),
            'url'     => (string) get_permalink( $post ),
            'date'    => (string) get_post_time( 'c', true, $post ),
            'excerpt' => (string) get_the_excerpt( $post ),
            'terms'   => $terms,
        ];
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_query_posts' ) ) {
    /**
     * Execute callback for jpkcom-post-filter/query-posts
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input.
     * @return array<string, mixed>|\WP_Error Query result, or an error.
     */
    function jpkcom_postfilter_ability_query_posts( mixed $input ): array|\WP_Error {
        $input     = is_array( $input ) ? $input : [];
        $post_type = jpkcom_postfilter_ability_resolve_post_type( $input['post_type'] ?? null );

        $enabled_post_types = jpkcom_postfilter_ability_enabled_post_types();

        if ( ! in_array( needle: $post_type, haystack: $enabled_post_types, strict: true ) ) {
            return jpkcom_postfilter_ability_unknown_post_type_error( $post_type, $enabled_post_types );
        }

        $filters = jpkcom_postfilter_ability_normalize_filters( $input['filters'] ?? [] );
        $allowed = jpkcom_postfilter_ability_allowed_taxonomies(
            $post_type,
            jpkcom_postfilter_get_filter_groups_enabled(),
            $enabled_post_types
        );

        $validity = jpkcom_postfilter_ability_validate_filters( $filters, $allowed );

        if ( $validity instanceof \WP_Error ) {
            return $validity;
        }

        $per_page = jpkcom_postfilter_ability_clamp_per_page( $input['per_page'] ?? null );
        $page     = isset( $input['page'] ) && is_numeric( $input['page'] )
            ? max( 1, (int) $input['page'] )
            : 1;

        $atts = [
            'post_type' => $post_type,
            'limit'     => $per_page,
            'paged'     => $page,
        ];

        if ( isset( $input['search'] ) && is_string( $input['search'] ) && $input['search'] !== '' ) {
            $atts['s'] = $input['search'];
        }

        $query = jpkcom_postfilter_run_query(
            jpkcom_postfilter_build_query_args( $atts, $filters ),
            $filters
        );

        $posts = [];

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $posts[] = jpkcom_postfilter_ability_project_post( $post, $allowed );
        }

        return [
            'post_type'     => $post_type,
            'filters'       => $filters,
            'total'         => (int) $query->found_posts,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => (int) $query->max_num_pages,
            'filter_url'    => jpkcom_postfilter_get_filter_url(
                jpkcom_postfilter_get_archive_base_url( $post_type ),
                $filters,
                $page
            ),
            'unknown_terms' => jpkcom_postfilter_ability_unknown_terms( $filters ),
            'posts'         => $posts,
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 6: Lint and commit**

```bash
php -l includes/abilities.php
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add the query-posts ability callback

Reuses build_query_args() and run_query() and adds the JSON projection the
plugin does not have - none of its functions returns a serialisable view of
results. Reports unmatched term slugs instead of returning an unexplained
empty result, and always passes a positive limit so the total is real."
```

---

### Task 5: Ability definitions and schemas

**Files:**
- Modify: `includes/abilities.php` (append)
- Modify: `tests/test-abilities.php` (append tests)

**Interfaces:**
- Consumes: all callbacks from Tasks 3 and 4
- Produces:
  - `jpkcom_postfilter_ability_meta( string $ability_name ): array`
  - `jpkcom_postfilter_ability_user_can( string $ability_name ): bool`
  - `jpkcom_postfilter_ability_permission_list_filters( mixed $input = null ): bool`
  - `jpkcom_postfilter_ability_permission_query_posts( mixed $input = null ): bool`
  - `jpkcom_postfilter_get_ability_definitions(): array` — ability name => `wp_register_ability()` args

- [ ] **Step 1: Add the `current_user_can` stub**

Insert into `tests/test-abilities.php` with the other WordPress stubs:

```php
$GLOBALS['_stub_can'] = true;

function current_user_can( string $capability ): bool {
	return $GLOBALS['_stub_can'];
}
```

- [ ] **Step 2: Write the failing tests**

Append before the `printf` summary line:

```php
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php tests/test-abilities.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function jpkcom_postfilter_get_ability_definitions()`.

- [ ] **Step 4: Append the implementation to `includes/abilities.php`**

```php
if ( ! function_exists( function: 'jpkcom_postfilter_ability_meta' ) ) {
    /**
     * Build the meta array for an ability
     *
     * show_in_rest governs core REST visibility. The public key is inert on
     * WordPress 6.9 and 7.0 and seeds show_in_rest from 7.1 onwards. The mcp
     * key is the MCP Adapter's own gate and is ignored by core.
     *
     * @since 1.3.0
     *
     * @param string $ability_name Fully qualified ability name.
     * @return array<string, mixed> Meta array for wp_register_ability().
     */
    function jpkcom_postfilter_ability_meta( string $ability_name ): array {
        $meta = [
            'show_in_rest' => true,
            'public'       => true,
            'mcp'          => [ 'public' => true ],
            'annotations'  => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
        ];

        /**
         * Filter the meta array of a JPKCom Post Filter ability
         *
         * Use this to withdraw an ability from REST or MCP on a specific site.
         *
         * @since 1.3.0
         *
         * @param array<string, mixed> $meta         Meta array.
         * @param string               $ability_name Fully qualified ability name.
         */
        $filtered = apply_filters( 'jpkcom_postfilter_ability_meta', $meta, $ability_name );

        return is_array( $filtered ) ? $filtered : $meta;
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_user_can' ) ) {
    /**
     * Check the capability required to run an ability
     *
     * Defaults to 'read'. The query is hard-scoped to published posts, so this
     * cannot expose drafts or private content - but it is bulk machine-readable
     * access, which a site may want to restrict further.
     *
     * @since 1.3.0
     *
     * @param string $ability_name Fully qualified ability name.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_user_can( string $ability_name ): bool {
        /**
         * Filter the capability required to run a JPKCom Post Filter ability
         *
         * @since 1.3.0
         *
         * @param string $capability   Capability name.
         * @param string $ability_name Fully qualified ability name.
         */
        $capability = apply_filters( 'jpkcom_postfilter_ability_capability', 'read', $ability_name );

        return current_user_can( is_string( $capability ) ? $capability : 'read' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_permission_list_filters' ) ) {
    /**
     * Permission callback for jpkcom-post-filter/list-filters
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input, unused.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_permission_list_filters( mixed $input = null ): bool {
        return jpkcom_postfilter_ability_user_can( 'jpkcom-post-filter/list-filters' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_ability_permission_query_posts' ) ) {
    /**
     * Permission callback for jpkcom-post-filter/query-posts
     *
     * @since 1.3.0
     *
     * @param mixed $input Validated ability input, unused.
     * @return bool True when the current user may run the ability.
     */
    function jpkcom_postfilter_ability_permission_query_posts( mixed $input = null ): bool {
        return jpkcom_postfilter_ability_user_can( 'jpkcom-post-filter/query-posts' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_get_ability_definitions' ) ) {
    /**
     * Build the registration arguments for every ability this plugin provides
     *
     * Pure: touches no WordPress state, calls no registry, has no side effects.
     * That is what lets the CI harness assert the shape of these arrays without
     * a WordPress installation.
     *
     * @since 1.3.0
     *
     * @return array<string, array<string, mixed>> Ability name => wp_register_ability() args.
     */
    function jpkcom_postfilter_get_ability_definitions(): array {
        $term_schema = [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => __( 'Term slug. Use this value inside the "filters" input of jpkcom-post-filter/query-posts.', 'jpkcom-post-filter' ),
                ],
                'name' => [
                    'type'        => 'string',
                    'description' => __( 'Human-readable term name.', 'jpkcom-post-filter' ),
                ],
                'count' => [
                    'type'        => 'integer',
                    'description' => __( 'Number of published posts carrying this term across the whole site. This total is NOT narrowed by any other active filter, so it does not describe the size of a combined result set.', 'jpkcom-post-filter' ),
                ],
            ],
        ];

        return [
            'jpkcom-post-filter/list-filters' => [
                'label'       => __( 'List available post filters', 'jpkcom-post-filter' ),
                'description' => __( 'Returns the taxonomies and terms that can be used to filter a post type on this site. Call this before jpkcom-post-filter/query-posts so taxonomy keys and term slugs never have to be guessed.', 'jpkcom-post-filter' ),
                'category'    => JPKCOM_POSTFILTER_ABILITY_CATEGORY,

                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'Post type to list filters for. Defaults to "post".', 'jpkcom-post-filter' ),
                            'default'     => 'post',
                        ],
                    ],
                ],

                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'The post type these filters apply to.', 'jpkcom-post-filter' ),
                        ],
                        'groups' => [
                            'type'        => 'array',
                            'description' => __( 'Configured filter groups, in the order they appear on the site.', 'jpkcom-post-filter' ),
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'taxonomy' => [
                                        'type'        => 'string',
                                        'description' => __( 'Taxonomy key. Use this value as a key in the "filters" input of jpkcom-post-filter/query-posts.', 'jpkcom-post-filter' ),
                                    ],
                                    'label' => [
                                        'type'        => 'string',
                                        'description' => __( 'Human-readable label configured for this filter group.', 'jpkcom-post-filter' ),
                                    ],
                                    'terms' => [
                                        'type'        => 'array',
                                        'description' => __( 'Terms available for this taxonomy.', 'jpkcom-post-filter' ),
                                        'items'       => $term_schema,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],

                'execute_callback'    => 'jpkcom_postfilter_ability_list_filters',
                'permission_callback' => 'jpkcom_postfilter_ability_permission_list_filters',
                'meta'                => jpkcom_postfilter_ability_meta( 'jpkcom-post-filter/list-filters' ),
            ],

            'jpkcom-post-filter/query-posts' => [
                'label'       => __( 'Query filtered posts', 'jpkcom-post-filter' ),
                'description' => __( 'Runs a taxonomy-filtered, paginated query over published posts and returns the results together with a shareable filter URL. Only taxonomies reported by jpkcom-post-filter/list-filters are accepted; any other taxonomy key is rejected with an error naming the valid ones.', 'jpkcom-post-filter' ),
                'category'    => JPKCOM_POSTFILTER_ABILITY_CATEGORY,

                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'Post type to query. Must be enabled for filtering on this site. Defaults to "post".', 'jpkcom-post-filter' ),
                            'default'     => 'post',
                        ],
                        'filters' => [
                            'type'                 => 'object',
                            'description'          => __( 'Taxonomy filters as a map of taxonomy key to a list of term slugs, for example {"category":["news"],"post_tag":["seo"]}. Terms within one taxonomy are combined with OR, different taxonomies with AND.', 'jpkcom-post-filter' ),
                            'additionalProperties' => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                        'page' => [
                            'type'        => 'integer',
                            'description' => __( 'Page number, starting at 1.', 'jpkcom-post-filter' ),
                            'minimum'     => 1,
                            'default'     => 1,
                        ],
                        'per_page' => [
                            'type'        => 'integer',
                            'description' => __( 'Number of posts to return per page.', 'jpkcom-post-filter' ),
                            'minimum'     => 1,
                            'maximum'     => JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX,
                            'default'     => JPKCOM_POSTFILTER_ABILITY_PER_PAGE_DEFAULT,
                        ],
                        'search' => [
                            'type'        => 'string',
                            'description' => __( 'Optional free-text search applied in addition to the taxonomy filters.', 'jpkcom-post-filter' ),
                        ],
                    ],
                ],

                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [
                            'type'        => 'string',
                            'description' => __( 'The post type that was queried.', 'jpkcom-post-filter' ),
                        ],
                        'filters' => [
                            'type'        => 'object',
                            'description' => __( 'The filters that were actually applied, after normalisation.', 'jpkcom-post-filter' ),
                        ],
                        'total' => [
                            'type'        => 'integer',
                            'description' => __( 'Total number of published posts matching the filters.', 'jpkcom-post-filter' ),
                        ],
                        'page' => [
                            'type'        => 'integer',
                            'description' => __( 'The page that was returned.', 'jpkcom-post-filter' ),
                        ],
                        'per_page' => [
                            'type'        => 'integer',
                            'description' => __( 'The page size that was applied after clamping.', 'jpkcom-post-filter' ),
                        ],
                        'total_pages' => [
                            'type'        => 'integer',
                            'description' => __( 'Number of pages available for these filters.', 'jpkcom-post-filter' ),
                        ],
                        'filter_url' => [
                            'type'        => 'string',
                            'description' => __( 'Shareable front-end URL showing this filter combination.', 'jpkcom-post-filter' ),
                        ],
                        'unknown_terms' => [
                            'type'        => 'object',
                            'description' => __( 'Requested term slugs that match no existing term, keyed by taxonomy. A non-empty value explains why a result set is empty.', 'jpkcom-post-filter' ),
                        ],
                        'posts' => [
                            'type'        => 'array',
                            'description' => __( 'The matching posts for the requested page.', 'jpkcom-post-filter' ),
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'      => [ 'type' => 'integer', 'description' => __( 'Post ID.', 'jpkcom-post-filter' ) ],
                                    'title'   => [ 'type' => 'string', 'description' => __( 'Post title.', 'jpkcom-post-filter' ) ],
                                    'url'     => [ 'type' => 'string', 'description' => __( 'Permalink.', 'jpkcom-post-filter' ) ],
                                    'date'    => [ 'type' => 'string', 'description' => __( 'Publication date in ISO 8601, UTC.', 'jpkcom-post-filter' ) ],
                                    'excerpt' => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'jpkcom-post-filter' ) ],
                                    'terms'   => [ 'type' => 'object', 'description' => __( 'Assigned terms of the filterable taxonomies, keyed by taxonomy.', 'jpkcom-post-filter' ) ],
                                ],
                            ],
                        ],
                    ],
                ],

                'execute_callback'    => 'jpkcom_postfilter_ability_query_posts',
                'permission_callback' => 'jpkcom_postfilter_ability_permission_query_posts',
                'meta'                => jpkcom_postfilter_ability_meta( 'jpkcom-post-filter/query-posts' ),
            ],
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 6: Lint and commit**

```bash
php -l includes/abilities.php
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add ability definitions, schemas and permission callbacks

Definitions come from a pure builder so CI can assert their shape without a
WordPress install. All three annotations are set explicitly because they
default to null, and the REST run controller derives the required HTTP verb
from them - an ability without annotations is POST-only."
```

---

### Task 6: Registration, kill switch and loader wiring

**Files:**
- Modify: `includes/abilities.php` (append)
- Modify: `jpkcom-post-filter.php:54-56` (constant block) and `jpkcom-post-filter.php:73-89` (include array)
- Modify: `tests/test-abilities.php` (append tests)

**Interfaces:**
- Consumes: `jpkcom_postfilter_get_ability_definitions()` from Task 5
- Produces:
  - `jpkcom_postfilter_abilities_enabled(): bool`
  - `jpkcom_postfilter_register_ability_category(): void`
  - `jpkcom_postfilter_register_abilities(): void`
  - Constant `JPKCOM_POSTFILTER_ABILITIES` (bool, default `true`)

- [ ] **Step 1: Write the failing tests**

Append before the `printf` summary line:

```php
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
	'Both registration functions are void, so a raw return-value check would be '
	. 'tautological - a void call always evaluates to NULL. They log through '
	. 'jpkcom_postfilter_debug_log() only after getting past the guard, so a non-zero '
	. 'count means the guard was skipped.'
);

jpkcom_postfilter_register_abilities();

check(
	'the ability registration is a no-op without the API',
	$GLOBALS['_stub_debug_log_calls'] === 0,
	'Same reasoning as above, for the ability loop.'
);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/test-abilities.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function jpkcom_postfilter_abilities_enabled()`.

- [ ] **Step 3: Append the implementation to `includes/abilities.php`**

```php
if ( ! function_exists( function: 'jpkcom_postfilter_abilities_enabled' ) ) {
    /**
     * Decide whether abilities should be registered at all
     *
     * @since 1.3.0
     *
     * @return bool True when the Abilities API is present and the kill switch is on.
     */
    function jpkcom_postfilter_abilities_enabled(): bool {
        if ( ! defined( constant_name: 'JPKCOM_POSTFILTER_ABILITIES' ) || ! JPKCOM_POSTFILTER_ABILITIES ) {
            return false;
        }

        return function_exists( function: 'wp_register_ability' )
            && function_exists( function: 'wp_register_ability_category' )
            && function_exists( function: 'wp_has_ability_category' );
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_register_ability_category' ) ) {
    /**
     * Register the shared JPKCom content ability category
     *
     * Registered defensively: categories are global and first-wins, so a
     * sibling plugin that registers the same slug first would otherwise make
     * this call fail silently.
     *
     * @since 1.3.0
     *
     * @return void
     */
    function jpkcom_postfilter_register_ability_category(): void {
        if ( ! jpkcom_postfilter_abilities_enabled() ) {
            return;
        }

        if ( wp_has_ability_category( JPKCOM_POSTFILTER_ABILITY_CATEGORY ) ) {
            return;
        }

        $category = wp_register_ability_category(
            JPKCOM_POSTFILTER_ABILITY_CATEGORY,
            [
                'label'       => __( 'JPKCom Content', 'jpkcom-post-filter' ),
                'description' => __( 'Content discovery and querying abilities provided by JPKCom plugins.', 'jpkcom-post-filter' ),
            ]
        );

        if ( $category === null ) {
            jpkcom_postfilter_debug_log(
                'Failed to register ability category',
                [ 'slug' => JPKCOM_POSTFILTER_ABILITY_CATEGORY ]
            );
        }
    }
}


if ( ! function_exists( function: 'jpkcom_postfilter_register_abilities' ) ) {
    /**
     * Register every ability this plugin provides
     *
     * wp_register_ability() returns null on every failure path and reports only
     * through _doing_it_wrong(), which is silent in production, so each result
     * is checked explicitly.
     *
     * @since 1.3.0
     *
     * @return void
     */
    function jpkcom_postfilter_register_abilities(): void {
        if ( ! jpkcom_postfilter_abilities_enabled() ) {
            return;
        }

        foreach ( jpkcom_postfilter_get_ability_definitions() as $name => $args ) {
            if ( wp_register_ability( $name, $args ) === null ) {
                jpkcom_postfilter_debug_log( 'Failed to register ability', [ 'ability' => $name ] );
            }
        }
    }
}

add_action( 'wp_abilities_api_categories_init', 'jpkcom_postfilter_register_ability_category' );
add_action( 'wp_abilities_api_init', 'jpkcom_postfilter_register_abilities' );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: every check prints PASS and the summary ends in `0 failed`, exit code 0. (The absolute count grows with each task; only the failure count matters.)

- [ ] **Step 5: Add the constant to `jpkcom-post-filter.php`**

Insert directly after the `JPKCOM_POSTFILTER_CACHE_ENABLED` block (`jpkcom-post-filter.php:54-56`):

```php
if ( ! defined( 'JPKCOM_POSTFILTER_ABILITIES' ) ) {
    define( 'JPKCOM_POSTFILTER_ABILITIES', true );
}
```

Note: the surrounding constant block uses **positional** `defined()` / `define()` calls. Match it — do not introduce named arguments here.

- [ ] **Step 6: Add the include to the loader array**

In the `$jpkcom_postfilter_includes` array (`jpkcom-post-filter.php:73-89`), insert directly after `'includes/query-handler.php',`:

```php
    'includes/abilities.php',
```

Order matters: `abilities.php` calls functions declared in `query-handler.php`, `taxonomies.php`, `url-routing.php` and `settings.php`, all of which are loaded earlier.

- [ ] **Step 7: Verify the full suite and lint**

```bash
find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l
php tests/test-abilities.php
php tests/test-security.php
php tests/test-fragment.php
```
Expected: no lint errors; all three suites exit 0.

- [ ] **Step 8: Commit**

```bash
git add includes/abilities.php jpkcom-post-filter.php tests/test-abilities.php
git commit -m "Register the abilities and add the JPKCOM_POSTFILTER_ABILITIES switch

The category is registered defensively because ability categories are global
and first-wins - jpkcom-acf-jobs and jpkcom-acf-references are meant to share
jpkcom-content. Every registration result is checked, since
wp_register_ability() reports failure only through _doing_it_wrong(), which
is silent in production."
```

---

### Task 7: Runtime verification on WordPress 7.0.2 and 6.9.1

No unit test can prove that `wp_register_ability()` accepts these arrays, because the harness has no WordPress. This task closes that gap against two real installations.

**Files:**
- Create (temporary, deleted at the end): `/home/jpk/ddev/posts/jpkcom-abilities-check.php`
- Create (temporary, deleted at the end): `/home/jpk/ddev/jobs/jpkcom-abilities-check.php`

**Interfaces:**
- Consumes: everything from Tasks 1-6
- Produces: no code; a verification record for the commit message

- [ ] **Step 1: Deploy the working copy into the DDEV site**

Plugins in the DDEV tree are real directories, not symlinks, so the code has to be copied.

```bash
ddev start posts
cp -r /home/jpk/wp/jpkcom-post-filter/includes/abilities.php \
      /home/jpk/ddev/posts/wp-content/plugins/jpkcom-post-filter/includes/abilities.php
cp /home/jpk/wp/jpkcom-post-filter/jpkcom-post-filter.php \
      /home/jpk/ddev/posts/wp-content/plugins/jpkcom-post-filter/jpkcom-post-filter.php
```

- [ ] **Step 2: Write the verification script**

Create `/home/jpk/ddev/posts/jpkcom-abilities-check.php`:

```php
<?php
/**
 * Runtime verification for the JPKCom Post Filter abilities.
 *
 * Run with: ddev wp eval-file jpkcom-abilities-check.php
 */

declare(strict_types=1);

$fail = 0;

/**
 * Report a check result.
 *
 * @param string $label Check name.
 * @param bool   $ok    Result.
 * @param string $note  Extra detail.
 */
function jpk_check( string $label, bool $ok, string $note = '' ): void {
    global $fail;

    if ( ! $ok ) {
        $fail++;
    }

    echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ( $note === '' ? '' : "  ({$note})" ) . "\n";
}

echo "WordPress " . get_bloginfo( 'version' ) . "\n\n";

jpk_check( 'the abilities API is present', function_exists( 'wp_register_ability' ) );
jpk_check( 'the category is registered', wp_has_ability_category( 'jpkcom-content' ) );

$list  = wp_get_ability( 'jpkcom-post-filter/list-filters' );
$query = wp_get_ability( 'jpkcom-post-filter/query-posts' );

jpk_check( 'list-filters is registered', $list !== null );
jpk_check( 'query-posts is registered', $query !== null );

if ( $list === null || $query === null ) {
    echo "\nRegistration failed - rerun with WP_DEBUG on to see the _doing_it_wrong() notice.\n";
    exit( 1 );
}

wp_set_current_user( 1 );

$filters = $list->execute( [ 'post_type' => 'post' ] );
jpk_check( 'list-filters executes', ! is_wp_error( $filters ) );

if ( ! is_wp_error( $filters ) ) {
    echo '        groups: ' . wp_json_encode( array_column( $filters['groups'], 'taxonomy' ) ) . "\n";
}

$happy = $query->execute( [ 'post_type' => 'post', 'per_page' => 3 ] );
jpk_check( 'query-posts executes', ! is_wp_error( $happy ) );
jpk_check(
    'the total is real, not zeroed by no_found_rows',
    ! is_wp_error( $happy ) && $happy['total'] > 0,
    is_wp_error( $happy ) ? $happy->get_error_message() : 'total=' . $happy['total']
);
jpk_check(
    'the page size is honoured',
    ! is_wp_error( $happy ) && count( $happy['posts'] ) <= 3
);
jpk_check(
    'a filter URL is returned',
    ! is_wp_error( $happy ) && str_starts_with( (string) $happy['filter_url'], 'http' ),
    is_wp_error( $happy ) ? '' : (string) $happy['filter_url']
);

$bad_tax = $query->execute( [ 'post_type' => 'post', 'filters' => [ 'no_such_tax' => [ 'x' ] ] ] );
jpk_check(
    'an unknown taxonomy is rejected instead of returning the whole corpus',
    is_wp_error( $bad_tax ) && $bad_tax->get_error_code() === 'jpkcom_postfilter_unknown_taxonomy',
    is_wp_error( $bad_tax ) ? $bad_tax->get_error_message() : 'returned ' . count( $bad_tax['posts'] ) . ' posts'
);

$real_tax = ! is_wp_error( $filters ) && $filters['groups'] !== [] ? $filters['groups'][0]['taxonomy'] : 'category';
$bad_term = $query->execute( [ 'post_type' => 'post', 'filters' => [ $real_tax => [ 'definitely-not-a-real-slug' ] ] ] );
jpk_check(
    'an unknown term slug is reported rather than rejected',
    ! is_wp_error( $bad_term ) && $bad_term['total'] === 0 && $bad_term['unknown_terms'] !== []
);

$rest     = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' );
$response = rest_get_server()->dispatch( $rest );
$names    = array_column( (array) $response->get_data(), 'name' );

jpk_check( 'the REST list route answers', $response->get_status() === 200 );
jpk_check( 'list-filters is discoverable over REST', in_array( 'jpkcom-post-filter/list-filters', $names, true ) );
jpk_check( 'query-posts is discoverable over REST', in_array( 'jpkcom-post-filter/query-posts', $names, true ) );

echo "\n" . ( $fail === 0 ? 'All checks passed.' : $fail . ' check(s) failed.' ) . "\n";

exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 3: Run it against WordPress 7.0.2**

```bash
cd /home/jpk/ddev/posts && ddev wp eval-file jpkcom-abilities-check.php
```
Expected: `WordPress 7.0.2` followed by `All checks passed.`, exit code 0.

If registration fails, rerun with `ddev wp --debug eval-file …` and read the `_doing_it_wrong()` notice — the most likely causes are a category registered after the abilities, or a typo in the ability name.

- [ ] **Step 4: Verify against the 6.9 floor**

`/home/jpk/ddev/jobs` runs WordPress 6.9.1, a real instance of the declared minimum. The plugin may not be installed there yet.

```bash
ddev start jobs
mkdir -p /home/jpk/ddev/jobs/wp-content/plugins/jpkcom-post-filter
cp -r /home/jpk/wp/jpkcom-post-filter/. /home/jpk/ddev/jobs/wp-content/plugins/jpkcom-post-filter/
cd /home/jpk/ddev/jobs && ddev wp plugin activate jpkcom-post-filter
cp /home/jpk/ddev/posts/jpkcom-abilities-check.php /home/jpk/ddev/jobs/jpkcom-abilities-check.php
ddev wp eval-file jpkcom-abilities-check.php
```
Expected: `WordPress 6.9.1` followed by `All checks passed.`

This is the check that catches any accidental use of a 7.0 or 7.1-only API. It also confirms no callback throws — on 6.9 a `Throwable` escaping a callback is an uncaught fatal rather than a `WP_Error`.

- [ ] **Step 5: Clean up**

```bash
rm -f /home/jpk/ddev/posts/jpkcom-abilities-check.php /home/jpk/ddev/jobs/jpkcom-abilities-check.php
```

Leave the DDEV projects running or stop them with `ddev stop posts jobs` — either is fine, nothing depends on their state.

- [ ] **Step 6: Record the result**

No code changes in this task. If Steps 3 or 4 revealed a defect, fix it in `includes/abilities.php`, add a regression test to `tests/test-abilities.php`, and commit that fix before continuing. If everything passed, there is nothing to commit — carry the result into Task 8's changelog entry.

---

### Task 8: Documentation, version bump and release

**Files:**
- Modify: `jpkcom-post-filter.php:6`, `:14`, `:35`
- Modify: `phpdoc.xml:12`
- Modify: `README.md:6`, `:14`, the constants table at `:434-441`, and the `## Changelog` section
- Modify: `CLAUDE.md` constants table at `:41-50`

**Interfaces:**
- Consumes: everything from Tasks 1-7
- Produces: a released `v1.3.0`

- [ ] **Step 1: Bump the version in all five places**

```bash
cd /home/jpk/wp/jpkcom-post-filter
sed -i 's/^Version: 1\.2\.3$/Version: 1.3.0/'         jpkcom-post-filter.php
sed -i 's/^Stable tag: 1\.2\.3$/Stable tag: 1.3.0/'   jpkcom-post-filter.php
sed -i "s/'JPKCOM_POSTFILTER_VERSION', '1\.2\.3'/'JPKCOM_POSTFILTER_VERSION', '1.3.0'/" jpkcom-post-filter.php
sed -i 's/<version number="1\.2\.3">/<version number="1.3.0">/' phpdoc.xml
sed -i 's/^\*\*Version:\*\* 1\.2\.3  $/**Version:** 1.3.0  /'       README.md
sed -i 's/^\*\*Stable tag:\*\* 1\.2\.3  $/**Stable tag:** 1.3.0  /' README.md
```

Verify all five:
```bash
grep -n '1\.3\.0' jpkcom-post-filter.php phpdoc.xml README.md
```
Expected: six matching lines (three in the main file, one in phpdoc.xml, two in README.md).

- [ ] **Step 2: Add the constant to both constants tables**

In `README.md`, append to the table that ends at `:441`:

```markdown
| `JPKCOM_POSTFILTER_ABILITIES` | `true` | Registers the WordPress Abilities API integration. Set to `false` to withdraw both abilities from REST and MCP entirely. |
```

In `CLAUDE.md`, append to the table that ends at `:50`:

```markdown
| `JPKCOM_POSTFILTER_ABILITIES` | `true` | Abilities API registration master switch |
```

- [ ] **Step 3: Document the two new filters in `README.md`**

Add to the filter documentation section:

```markdown
- `jpkcom_postfilter_ability_meta( array $meta, string $ability_name )` — adjust the meta of an ability before registration. Set `show_in_rest` to `false` to hide it from the REST API, or `mcp.public` to `false` to hide it from MCP clients.
- `jpkcom_postfilter_ability_capability( string $capability, string $ability_name )` — the capability required to run an ability. Defaults to `read`, which covers every logged-in user. Raise it to `edit_posts` to restrict bulk machine-readable access to published content.
```

- [ ] **Step 4: Add the changelog block**

Insert at the top of the `## Changelog` section in `README.md`:

```markdown
### 1.3.0

* **Added:** the plugin now registers two read-only WordPress Abilities, `jpkcom-post-filter/list-filters` and `jpkcom-post-filter/query-posts`, in the shared `jpkcom-content` category. MCP clients, REST automation and the WordPress AI client can ask which taxonomies and terms a post type can be filtered by, then run a filtered, paginated query and receive both the results and a shareable filter URL.
* **Added:** `JPKCOM_POSTFILTER_ABILITIES` (default `true`) withdraws both abilities from REST and MCP when set to `false` in `wp-config.php`, plus the filters `jpkcom_postfilter_ability_meta` and `jpkcom_postfilter_ability_capability` for per-ability control.
* **Hardened:** a filter naming a taxonomy that does not exist previously produced the complete unfiltered result set, because the query builder drops such a clause silently. The query ability now rejects it and names the valid taxonomies, so a caller can correct itself instead of presenting the whole site as a filtered answer.
* **Hardened:** the query ability always passes a positive page size. The shortcode default of `-1` sets `no_found_rows`, which reports a total of zero regardless of how many posts exist.
```

- [ ] **Step 5: Verify the whole suite one more time**

```bash
find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l
php tests/test-abilities.php && php tests/test-security.php && php tests/test-fragment.php
```
Expected: no lint errors, all three suites exit 0.

- [ ] **Step 6: Commit and tag**

```bash
git add jpkcom-post-filter.php phpdoc.xml README.md CLAUDE.md
git commit -m "v1.3.0: Abilities API integration

Registers jpkcom-post-filter/list-filters and jpkcom-post-filter/query-posts
as read-only abilities, verified against WordPress 7.0.2 and against 6.9.1 as
the declared floor."
git push -u origin abilities-api
```

Merging to `main` and pushing the `v1.3.0` tag is a separate, explicit decision — the tag push is the only release trigger, and it is irreversible from the update manifest's point of view. Ask before doing it.

---

## Self-Review

**Spec coverage.** Every section of the spec maps to a task: §3.1/§3.2 architecture → Task 6; §3.3 pure builder → Task 5; §4 `list-filters` → Task 3; §5 `query-posts` → Task 4; §6 error handling → Tasks 1, 2, 4; §7 exposure, filters and the constant → Tasks 5 and 6; §8.1 CI tests → Tasks 1-6; §8.2 runtime verification → Task 7; §9 release → Task 8. §10 and §11 are reference material and constrain the Global Constraints section rather than producing a task.

**Type consistency.** `jpkcom_postfilter_ability_allowed_taxonomies()` returns `string[]` in Task 2 and is consumed as `string[]` by `jpkcom_postfilter_ability_project_post()` in Task 4. `jpkcom_postfilter_ability_group_applies()` is defined in Task 2 and reused unchanged in Task 3. `JPKCOM_POSTFILTER_ABILITY_PER_PAGE_MAX` is defined in Task 1, used by the clamp in Task 1 and by the schema `maximum` in Task 5, and Task 5 has an explicit test that the two cannot drift apart. `jpkcom_postfilter_settings_get()` matches the real signature at `includes/settings.php:325`.

**Known deviation worth flagging at execution time.** The `get_post_time()`, `get_the_terms()`, `get_permalink()` and `get_the_title()` stubs in Task 4 declare narrower types than the real WordPress functions (which accept `int|WP_Post|null` and can return `false` or `WP_Error`). That is deliberate — it keeps the test readable — but it means the stubs cannot catch a type mismatch that only appears against real WordPress. Task 7 is what covers that, and it is the reason Task 7 is not optional.
