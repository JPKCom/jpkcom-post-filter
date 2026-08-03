# Design: Abilities API integration for JPKCom Post Filter

**Date:** 2026-08-03
**Status:** Approved for planning
**Target version:** 1.3.0 (current: 1.2.3)
**Scope:** `jpkcom-post-filter` only. `jpkcom-acf-jobs` gets its own spec later and inherits the pattern established here.

> Spec location note: the Superpowers default is `docs/superpowers/specs/`, but `docs/` is listed in
> `.gitignore:67` because it is the phpDocumentor output directory that the release workflow deploys to
> `gh-pages`. Specs therefore live in `.claude/specs/`, which is tracked by git and excluded from the
> release ZIP (`.github/workflows/release.yml:128`).

---

## 1. Goal

Expose the plugin's faceted query capability as two read-only WordPress Abilities, so that MCP clients,
REST automation and the WordPress AI/editor client can discover which filters a site offers and run
filtered queries without guessing taxonomy names or scraping HTML.

### Non-goals

- No write abilities in this round. The only writable surface would be the filter-group settings, and
  that surface has two verified problems (see §8): all four `register_setting()` calls live inside an
  `admin_init` closure, so no sanitizer runs on REST/CLI/front-end writes, and live installations already
  store a reduced group shape. Fixing that is separate work.
- No new REST routes of our own. Core's `wp-abilities/v1` surface is the transport.
- No changes to the shortcode, block, Elementor or Oxygen paths.
- No client-side (JS) abilities.

---

## 2. Decisions taken

| Question | Decision |
|---|---|
| Consumers | All three: MCP clients, REST automation, WordPress AI/editor client |
| Write scope | Read-only for this plugin (see Non-goals); curated writes belong to other plugins |
| String language | `__()` with English source text and the `jpkcom-post-filter` text domain |
| Category layout | Thematic and shared: `jpkcom-content`, registered defensively |
| Exposure | On by default (REST + MCP), disableable via constant and filter |
| Granularity | Two abilities: `list-filters` and `query-posts` |

---

## 3. Architecture

### 3.1 New file

`includes/abilities.php`, added to the ordered include array in `jpkcom-post-filter.php:73-89`,
positioned **after** `includes/query-handler.php` (whose functions it calls) and before
`includes/filter-injection.php`.

The file follows `includes/fragment-response.php` as its template — the newest feature file and the
closest match to current house style:

- File docblock ending in `@package JPKCom_Post_Filter` and `@since 1.3.0`
- `declare(strict_types=1);`
- `if ( ! defined( constant_name: 'ABSPATH' ) ) { exit; }`
- Every function prefixed `jpkcom_postfilter_`, fully typed, wrapped in
  `if ( ! function_exists( function: '…' ) )`
- Named arguments to internal PHP functions, **never bound to a variadic parameter** — the CI token
  scanner at `.github/workflows/ci.yml:57-116` exists because that combination throws
  `ArgumentCountError` at runtime and `php -l` does not catch it
- Every `apply_filters()` preceded by its own docblock with `@since` and `@param`

### 3.2 Registration

```php
add_action( 'wp_abilities_api_categories_init', 'jpkcom_postfilter_register_ability_category' );
add_action( 'wp_abilities_api_init',            'jpkcom_postfilter_register_abilities' );
```

Both callbacks return early when either condition holds:

1. `! function_exists( function: 'wp_register_ability' )` — defence in depth. The declared floor is
   WP 6.9 (`jpkcom-post-filter.php:11`) and the Abilities API shipped in 6.9, so this should never
   trigger; it costs one call and removes a whole class of fatal.
2. `! JPKCOM_POSTFILTER_ABILITIES` — the kill switch (§7).

The category is registered **defensively**, because `jpkcom-acf-jobs` and `jpkcom-acf-references` are
expected to share it and category registration is first-wins with a silent `null` for the loser:

```php
if ( ! wp_has_ability_category( 'jpkcom-content' ) ) {
    wp_register_ability_category(
        'jpkcom-content',
        [
            'label'       => __( 'JPKCom Content', 'jpkcom-post-filter' ),
            'description' => __( 'Content discovery and querying abilities provided by JPKCom plugins.', 'jpkcom-post-filter' ),
        ]
    );
}
```

`jpkcom-content` satisfies the category slug regex `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`, which is stricter
than the ability-name regex, and does not collide with any of the 11 categories observed on the
reference install (`site`, `user`, `desktop-mode`, seven `rank-math-*`, `mcp-adapter`).

### 3.3 Definitions are built by a pure function

`jpkcom_postfilter_get_ability_definitions(): array` returns the two registration argument arrays keyed
by ability name. It touches no WordPress state, calls no registry, and has no side effects — the
callbacks it references are named as strings, not invoked. `jpkcom_postfilter_register_abilities()` loops
over its result and passes each entry to `wp_register_ability()`.

This split is what makes CI testing possible at all (§8.1): the test harness never loads WordPress, so it
can assert the shape of these arrays but could not assert anything about actual registration.

### 3.3 Three rules that follow from verified core behaviour

- **Check the return value.** `wp_register_ability()` returns `null` on every failure path and reports
  only through `_doing_it_wrong()`, which is silent in production. Each registration result is checked
  and a failure is reported through the existing `jpkcom_postfilter_debug_log()`
  (`includes/helpers.php:63`).
- **Never throw.** On the WP 6.9 floor a `Throwable` escaping an execute callback is an uncaught fatal;
  the `Throwable → WP_Error` wrapper only landed in 7.0. All callbacks return `WP_Error` and any call
  that could throw is guarded.
- **Declare an input schema.** `WP_Ability::invoke_callback()` appends `$input` to the callback
  arguments *only* when the input schema is non-empty, and passing non-null input to an ability without
  an input schema is a hard `ability_missing_input_schema` error. Both abilities declare one, so both
  callbacks take exactly one parameter.

---

## 4. Ability: `jpkcom-post-filter/list-filters`

**Purpose:** tell the caller which taxonomies and terms are filterable for a post type, so that
`query-posts` can be called with valid values instead of guesses.

| | |
|---|---|
| `label` | List available post filters |
| `category` | `jpkcom-content` |
| `permission_callback` | `current_user_can( 'read' )`, filterable (§7) |
| `annotations` | `readonly: true`, `destructive: false`, `idempotent: true` |

### Input schema

```php
[
    'type'       => 'object',
    'properties' => [
        'post_type' => [
            'type'        => 'string',
            'description' => 'Post type to list filters for. Defaults to "post".',
            'default'     => 'post',
        ],
    ],
]
```

### Output schema

```
{
  post_type: string,
  groups: [
    {
      taxonomy: string,   // the exact value to use as a key in query-posts "filters"
      label:    string,
      terms: [ { slug: string, name: string, count: integer } ]
    }
  ]
}
```

### Implementation notes

- Source of truth is `jpkcom_postfilter_get_filter_groups_enabled()` (`includes/taxonomies.php:66`),
  narrowed to the requested post type by the rule already used in all three render call sites: a group
  applies when `group['post_types']` contains the post type, or — when `post_types` is empty — when the
  post type is in `general.enabled_post_types`.
- Terms come from `jpkcom_postfilter_get_terms_for_group()` (`includes/taxonomies.php:176`), which
  already returns `[]` for a non-existent taxonomy.
- **Read groups defensively.** On the reference install the stored groups carry only four keys
  (`taxonomy`, `label`, `enabled`, `post_types`); `slug` and `order` are `null`, which is why
  `jpkcom_postfilter_get_filter_group_by_slug()` returns `null` there. Use `$g['taxonomy']` as the sole
  stable identifier and read every other key with `??`.
- Skip a group when its taxonomy object has **both** `public === false` and `show_in_rest === false`, so
  a fully private taxonomy is not disclosed to a subscriber-level caller. A taxonomy that is either
  public or REST-exposed is already visible to such a caller by other means and is included.
- The `count` values are **site-wide totals from `WP_Term->count`**, not narrowed by other active
  filters. The output schema description says so explicitly, so a model does not read them as
  intersection sizes.

---

## 5. Ability: `jpkcom-post-filter/query-posts`

**Purpose:** run a taxonomy-filtered, paginated query and return both the results and the
human-shareable filter URL.

| | |
|---|---|
| `label` | Query filtered posts |
| `category` | `jpkcom-content` |
| `permission_callback` | `current_user_can( 'read' )`, filterable (§7) |
| `annotations` | `readonly: true`, `destructive: false`, `idempotent: true` |

### Input schema

```
{
  post_type: string  = "post",
  filters:   object  // taxonomy slug => array of term slugs; OR within a taxonomy, AND across taxonomies
  page:      integer = 1,   minimum 1
  per_page:  integer = 10,  minimum 1, maximum 50
  search:    string        // optional free-text search
}
```

### Output schema

```
{
  post_type:     string,
  filters:       object,   // echoed back normalised, so the caller sees what was actually applied
  total:         integer,
  page:          integer,
  per_page:      integer,
  total_pages:   integer,
  filter_url:    string,   // shareable pretty URL for this filter combination
  unknown_terms: object,   // taxonomy => term slugs that matched no term
  posts: [
    { id: integer, title: string, url: string, date: string, excerpt: string,
      terms: object }      // taxonomy => [ { slug, name } ] for the filterable taxonomies
  ]
}
```

### Implementation notes

- Calls `jpkcom_postfilter_build_query_args()` (`includes/query-handler.php:107`) then
  `jpkcom_postfilter_run_query()` (`includes/query-handler.php:214`). This chain was executed from
  inside a live `WP_Ability` on the reference install and produced correct `tax_query` output, so no
  logic is duplicated.
- The `WP_Query` → array projection is **new code**. None of the plugin's ~110 functions returns a
  JSON-serialisable representation of results; every reader either renders HTML or returns WP objects.
- Iterate `$query->posts` directly rather than using the loop, so no `wp_reset_postdata()` bookkeeping
  and no interference with a surrounding main query.
- `filter_url` uses `jpkcom_postfilter_get_filter_url()` (`includes/url-routing.php:112`) over
  `jpkcom_postfilter_get_archive_base_url( $post_type )` (`includes/url-routing.php:170`). Use
  `get_filter_url()`, **not** the legacy `jpkcom_postfilter_build_filter_url()` in `helpers.php:238`,
  which omits the `_` placeholders and can emit URLs that `parse_filter_path()` mis-maps.
- Leave caching enabled so the ability honours the site's cache settings. Note that a `cache` key in
  `$atts` is dropped by `build_query_args()`'s allowlist; bypassing the cache would require setting it
  on the returned args array.
- `post_status` is hardcoded to `publish` in `build_query_args()` (`includes/query-handler.php:121`) and
  is not caller-overridable. Verified against the reference database: 19 published, 5 auto-draft,
  2 trashed posts, and the ability returned exactly the 19. Drafts and private content cannot leak,
  which is what makes a `read`-level permission callback defensible.

---

## 6. Error handling

This is where most of the value sits — each item below is a measured failure of the underlying
pipeline that the ability has to compensate for.

| Condition | Behaviour |
|---|---|
| `filters` key is not an enabled filter taxonomy for the post type | `WP_Error` `jpkcom_postfilter_unknown_taxonomy`, **message lists the valid taxonomy names** |
| `post_type` is not enabled | `WP_Error` `jpkcom_postfilter_unknown_post_type`, message lists the enabled post types |
| Term slug matches no term | **Not** an error. Query runs, `unknown_terms` names the offending slugs |
| `per_page` out of range | Clamped to 1..50. `-1` is never passed through |
| Any internal failure | `WP_Error`, never an exception |

**Why the taxonomy check matters.** `jpkcom_postfilter_build_tax_query()` drops a clause when
`taxonomy_exists()` is false (`includes/query-handler.php:57`). Measured consequence on the reference
install: `filters = [ 'no_such_tax' => [ 'x' ] ]` returned **19 of 19 posts** — the complete unfiltered
corpus — with no error and no signal in the return value. A model that writes `tag` instead of
`post_tag`, or `job_attribute` instead of `job-attribute`, would present the entire corpus as a filtered
answer. Listing the valid names in the error message lets the caller self-correct in one turn.

**Why unknown terms are not an error.** It matches the website's own behaviour: an unknown term slug
returns HTTP 200 with zero results and is marked `noindex, follow`. Returning `unknown_terms` instead of
an error tells the caller that "0 results" came from a typo rather than from an empty corpus.

**Why `per_page` is clamped.** `build_query_args()` sets `no_found_rows` whenever `limit === -1`
(`includes/query-handler.php:123`), which zeroes `found_posts` and `max_num_pages`. Measured: with
`limit=-1` the ability reported `total: 0` while 19 posts existed. The `[jpkcom_postfilter_list]`
shortcode default is `-1` (`includes/shortcodes.php:182`), so mirroring shortcode defaults would ship a
broken total.

**Per-property defaults are applied in the callback.** Core applies only a top-level `default`, and only
when the input is exactly `null`; nested `properties.x.default` values are never filled in — verified by
execution (schema default `7`, callback fallback `99`, `execute([])` yielded `99`). The defaults stay in
the schema because the caller reads them, but the callback must apply them itself.

**Unknown input keys are not rejected by core.** There is no `additionalProperties` enforcement, so the
callbacks ignore keys they do not know rather than assuming core filtered them out.

**No audit log this round.** Both abilities are read-only, and the clean hook for it
(`wp_ability_invoked`) is WP 7.1-only. When write abilities arrive, `wp_before_execute_ability` is the
6.9-compatible attachment point.

---

## 7. Exposure and kill switch

### Meta

```php
'meta' => [
    'show_in_rest' => true,                 // core REST visibility
    'public'       => true,                 // WP 7.1; inert passthrough on 6.9/7.0
    'mcp'          => [ 'public' => true ], // MCP Adapter convention; passthrough as far as core is concerned
    'annotations'  => [
        'readonly'    => true,
        'destructive' => false,
        'idempotent'  => true,
    ],
],
```

All three annotations are set **explicitly**. They default to `null`, and the REST run controller derives
the required HTTP verb from them: `readonly` truthy → GET, `destructive` and `idempotent` both truthy →
DELETE, otherwise POST. An ability with no annotations would be POST-only. Every neighbouring ability on
the reference install (`core/get-site-info`, `rank-math/get-post-seo-meta`,
`mcp-adapter/discover-abilities`) declares the full triple, so this also matches local convention.

`meta.mcp.public` is the MCP Adapter's own gate, not a core key. Verified in the adapter vendored by Rank
Math: discovery filters on it in `DiscoverAbilitiesAbility.php:134` and execution re-checks it via
`check_ability_mcp_exposure()` in `ExecuteAbilityAbility.php:105`. Without the flag an ability is neither
discoverable nor executable over MCP. Core ignores unknown meta keys, so setting it is harmless where no
adapter is installed.

### Filters and constant

- `apply_filters( 'jpkcom_postfilter_ability_meta', array $meta, string $ability_name )` — lets a site
  turn off REST or MCP exposure per ability.
- `apply_filters( 'jpkcom_postfilter_ability_capability', string $capability, string $ability_name )` —
  defaults to `read`; a site that considers bulk machine-readable access to published content too broad
  can raise it to `edit_posts`.
- `JPKCOM_POSTFILTER_ABILITIES` (default `true`), defined in the constant block of
  `jpkcom-post-filter.php` alongside `JPKCOM_POSTFILTER_CACHE_ENABLED`, overridable in `wp-config.php`.
  When `false`, nothing is registered at all. Must be added to the constants tables in `CLAUDE.md:39-51`
  and `README.md:432-441`.

### Disclosure note

Listing abilities over REST is gated only by `current_user_can( 'read' )`. Verified by dispatching
`GET /wp-abilities/v1/abilities` as a freshly created subscriber: HTTP 200 with 23 abilities including
full input and output schemas (anonymous → 401). Labels, descriptions and schemas must therefore be
treated as readable by every logged-in user. Execution remains gated by the ability's own
`permission_callback`.

---

## 8. Testing

The plugin's test harness does not load WordPress — `tests/bootstrap.php` provides hand-written stubs,
and there is no stub for `WP_Ability` or the registries. Verification is therefore split in two.

### 8.1 `tests/test-abilities.php` (runs in CI)

CI globs `tests/test-*.php` (`.github/workflows/ci.yml:118-135`), so the filename is mandatory. The file
asserts the *shape* of the registration arrays without registering anything, by requiring
`includes/abilities.php` and calling `jpkcom_postfilter_get_ability_definitions()` (§3.3). Assertions:

- Ability names match `/^[a-z0-9-]+\/[a-z0-9-]+$/` — exactly one slash, lowercase, no underscores
- Category slug matches `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`
- All required keys present: `label`, `description`, `category`, `execute_callback`,
  `permission_callback`
- All three annotations present and boolean, none left `null`
- `input_schema` and `output_schema` are arrays with `type => object` and a `description` on every
  property
- Regression: an unknown taxonomy in `filters` produces a `WP_Error`, not a silent pass
- Regression: `per_page` is clamped and never becomes `-1`

Follow the local test style: tabs for indentation in `tests/`, the `section()` / `chk()` / `summary()`
harness from `tests/bootstrap.php`, exit code derived from the failure count.

### 8.2 Runtime verification (manual, not in CI)

`wp ability` is unavailable here — it requires WP-CLI ≥ 2.13 and DDEV ships 2.12. Use
`ddev wp eval-file <file>.php` with the file placed inside the DDEV project root.

Against **`/home/jpk/ddev/posts`** (WP 7.0.2, both plugins active, 19 posts, Rank Math with the MCP
adapter):

1. Both abilities register; `wp_get_ability()` returns them
2. Happy path: `query-posts` with a real category returns the correct `total` and post IDs
3. Unknown taxonomy returns `WP_Error`, **not** 19 posts
4. Unknown term slug returns `total: 0` with a populated `unknown_terms`
5. `filter_url` resolves to a working URL
6. `GET /wp-abilities/v1/abilities` lists both, and the run route answers on GET

Against **`/home/jpk/ddev/jobs`** (WP **6.9.1** — a real instance of the declared floor): registration
succeeds and both abilities execute, proving no 7.0/7.1-only API crept in.

**Hook-timing caveat for any diagnostic script:** the registries are lazy singletons that fire their init
action on first access. On this stack Rank Math touches the registry during load, so
`did_action( 'wp_abilities_api_init' )` is already `1` by the time a `wp eval-file` script runs and a
late `add_action()` never fires. Diagnostics must check `did_action()` and fall back to
`WP_Abilities_Registry::get_instance()->register()`. Normal plugin registration at file-load time is
unaffected.

---

## 9. Release

Version `1.3.0`. The version lives in five places that must stay in sync:

1. `jpkcom-post-filter.php:6` — header `Version:`
2. `jpkcom-post-filter.php:14` — `Stable tag:`
3. `jpkcom-post-filter.php:35` — `JPKCOM_POSTFILTER_VERSION` default
4. `phpdoc.xml:12` — `<version number="…">`
5. `README.md:6` and `README.md:14` — `**Version:**` and `**Stable tag:**`

Changelog: a new `### 1.3.0` block at the top of `## Changelog` in `README.md`, with `Added:` lines
written as prose describing the observable consequence.

Documentation to update in the same commit: the constants table in `CLAUDE.md:39-51` and
`README.md:432-441` (for `JPKCOM_POSTFILTER_ABILITIES`), and the filter list (for
`jpkcom_postfilter_ability_meta` and `jpkcom_postfilter_ability_capability`).

Release is triggered by pushing a `v1.3.0` tag; the workflow builds the ZIP, the SHA256 and the update
manifest itself. No `Co-Authored-By` trailer in any commit.

---

## 10. Verified API facts this design depends on

Established by reading WordPress core source (local 7.0.2 and 6.9.1 installs, plus wordpress-develop
trunk at 7.1-beta4) and by executing probe abilities on the reference install. The
`WordPress/abilities-api` GitHub repository is the standalone feature plugin at v0.5.0 and lags core;
two of its documented claims are contradicted by every shipped core version.

| Claim | Reality |
|---|---|
| `output_schema` is required | **Optional.** Core only type-checks it; an empty schema makes `validate_output()` pass anything |
| An `instructions` annotation exists | **It does not.** Zero occurrences across 6.9/7.0/7.1. The recognised set is `readonly`, `destructive`, `idempotent` |
| Registration failures surface as `WP_Error` | **Never.** Always `null` plus `_doing_it_wrong()`, which is silent in production |
| Ability names may be multi-level | **No.** `/^[a-z0-9-]+\/[a-z0-9-]+$/` — exactly one slash |
| Registering into an unregistered category degrades | **It fails hard** — the ability is not registered at all |
| Nested schema defaults are applied | **No.** Only a top-level `default`, and only for `null` input |
| Annotations change behaviour | Advisory, with one exception: they determine the required HTTP verb on the REST run route |

WP 7.1 is **not released** as of 2026-08-03 (7.1-beta4, GA scheduled 2026-08-19). Everything 7.1-only —
`wp_ability_invoked`, `wp_ability_validate_input` / `_output`, `wp_pre_execute_ability`,
`wp_ability_normalize_input`, `wp_ability_permission_result`, `wp_ability_execute_result`, the `$ability`
argument on `wp_before/after_execute_ability`, `meta.public` having any effect, `wp_get_abilities( $args )`,
and REST typed-input coercion — is out of reach on the 6.9 floor and must not be depended on. `meta.public`
is set anyway because it is inert passthrough on 6.9/7.0 and becomes correct automatically on 7.1.

---

## 11. Risks and open questions

- **7.1 is still beta.** Nothing in this design depends on a 7.1-only API, so GA cannot break it. If
  later work does adopt one, gate it with `function_exists()` / `has_filter()` rather than
  `version_compare()`.
- **Filter-group shape varies between installs.** The reference install stores four keys per group while
  the sanitizer produces twelve. The design keys off `taxonomy` only and reads everything else with `??`.
  Worth confirming against a real customer install before any future write ability touches this option.
- **Facet counts are global.** `WP_Term->count` is the term's total published-post count, not narrowed by
  other active filters. Genuinely narrowed counts would need per-term counting queries the plugin does
  not implement. Documented in the schema rather than faked.
- **`read` may be too permissive for some sites.** It covers any logged-in user, including subscribers.
  Mitigated by `jpkcom_postfilter_ability_capability`; called out in the README so site owners can decide.
- **Two shipped bugs are in scope-adjacent code** and were found during exploration but are deliberately
  *not* fixed here: the APCu purge in `cache_flush_group()` is a no-op, and the per-post-type
  `auto_inject` setting collapses to a boolean. Both deserve their own issue.
