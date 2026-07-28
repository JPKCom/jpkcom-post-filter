# JPKCom Post Filter – Developer Reference

## Plugin Overview

WordPress plugin for faceted navigation and filtering of Posts, Pages, and Custom Post Types via WordPress taxonomies.

- **Text Domain:** `jpkcom-post-filter`
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **No ACF, No Bootstrap** – everything self-written
- **Languages:** EN, de_DE, de_DE_formal

---

## Architecture

### URL Schema (SEO-friendly)
```
/{archive-base}/filter/{tax1-slug1}+{tax1-slug2}/{tax2-slug1}/
/blog/filter/web-design+marketing/seo+wordpress/
```
Segment order = filter group order configured in backend.

### Hybrid Filtering
1. Server-side render on initial load
2. AJAX + `history.pushState` when JS is available
3. Full page reload fallback when JS is disabled
4. `aria-live` region for screen reader announcements

### Page Builder Support
- **Gutenberg Blocks** — Three native blocks with live editor preview and InspectorControls; pre-scan of block tree for pagination-before-list support
- **Elementor Widgets** — Three widgets in a dedicated category; loaded only when Elementor is active
- **Oxygen Builder Elements** — Three elements using OxyEl API in a custom toolbar section; loaded only when Oxygen Builder Classic is active
- **Shortcodes** — Three shortcodes with interactive admin builder; work in any page builder that supports shortcodes

All builders call the same shortcode render functions (`jpkcom_postfilter_shortcode_filter()`, `jpkcom_postfilter_shortcode_list()`, `jpkcom_postfilter_shortcode_pagination()`), ensuring identical output.

---

## Constants (wp-config.php overridable)

| Constant | Default | Purpose |
|----------|---------|---------|
| `JPKCOM_POSTFILTER_VERSION` | `'1.1.7'` | Plugin version |
| `JPKCOM_POSTFILTER_BASENAME` | `plugin_basename(__FILE__)` | Plugin basename |
| `JPKCOM_POSTFILTER_PLUGIN_PATH` | `plugin_dir_path(__FILE__)` | Absolute path |
| `JPKCOM_POSTFILTER_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL |
| `JPKCOM_POSTFILTER_SETTINGS_DIR` | `WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings'` | Settings cache dir |
| `JPKCOM_POSTFILTER_CACHE_ENABLED` | `true` | Master cache toggle |
| `JPKCOM_POSTFILTER_DEBUG` | `WP_DEBUG` | Debug mode |
| `JPKCOM_POSTFILTER_URL_ENDPOINT` | `'filter'` | URL segment |

---

## File Structure

```
jpkcom-post-filter/
├── jpkcom-post-filter.php          ← Main: constants, includes, hooks
├── includes/
│   ├── class-plugin-updater.php    ← GitHub auto-updater (namespace: JPKComPostFilterGitUpdate)
│   ├── helpers.php                 ← Utility functions
│   ├── cache-manager.php           ← Multi-layer cache (Object/Transient/APCu)
│   ├── settings.php                ← Settings API + file cache system
│   ├── template-loader.php         ← Template hierarchy + override support
│   ├── taxonomies.php              ← Custom taxonomy registration + filter group helpers
│   ├── url-routing.php             ← Rewrite rules + URL parsing
│   ├── query-handler.php           ← WP_Query builder + caching
│   ├── filter-injection.php        ← Auto-inject into archives
│   ├── shortcodes.php              ← Shortcode registration + render functions
│   ├── blocks.php                  ← Gutenberg block registration + block tree pre-scan
│   ├── elementor-widgets.php       ← Elementor widget registration
│   ├── elementor/
│   │   ├── class-widget-filter.php
│   │   ├── class-widget-list.php
│   │   └── class-widget-pagination.php
│   ├── oxygen-elements.php         ← Oxygen element registration
│   ├── oxygen/
│   │   ├── class-element-filter.php
│   │   ├── class-element-list.php
│   │   └── class-element-pagination.php
│   ├── assets-enqueue.php          ← CSS/JS enqueueing
│   └── admin-pages.php             ← Backend pages (Settings API)
├── blocks/
│   ├── src/                        ← Gutenberg block source (JSX)
│   └── build/                      ← Compiled block assets (npm run build)
├── templates/                      ← Production templates
│   ├── partials/filter/            ← filter-bar.php, filter-sidebar.php, filter-dropdown.php, filter-columns.php
│   ├── partials/list/              ← list-cards.php, list-rows.php, list-minimal.php
│   ├── partials/pagination/        ← pagination.php
│   └── shortcodes/                 ← filter.php, posts-list.php, pagination.php
├── debug-templates/                ← Debug templates (identical structure, used when JPKCOM_POSTFILTER_DEBUG=true)
├── assets/
│   ├── css/post-filter.css         ← Frontend (CSS variables system)
│   ├── css/admin.css               ← Backend
│   ├── js/post-filter.js           ← AJAX filter + history.pushState + pagination swap
│   └── js/shortcode-generator.js   ← Admin shortcode builder
└── languages/
    ├── jpkcom-post-filter.pot
    ├── jpkcom-post-filter-de_DE.{po,mo,l10n.php}
    └── jpkcom-post-filter-de_DE_formal.{po,mo,l10n.php}
```

---

## Settings Cache System

### How it works
1. **Read priority:** File cache → wp_options (DB)
2. **On admin save:** wp_options + file cache updated simultaneously
3. **Cache location:** `.ht.jpkcom-post-filter-settings/{group}.php`
4. **Format:** PHP array via `return [...];` – loaded with `include` (no JSON parsing)

### Security
- Directory prefixed `.ht.` → Apache auto-denies HTTP access
- `.htaccess` auto-generated in cache dir (Deny from all)
- Path validated against `WP_CONTENT_DIR` (no traversal)
- Atomic write: temp file + rename

### Key functions
```php
jpkcom_postfilter_settings_get( 'general', 'url_endpoint', 'filter' )
jpkcom_postfilter_settings_get_group( 'layout' )
jpkcom_postfilter_settings_save( 'general', $data )
jpkcom_postfilter_settings_delete_cache( '*' )  // flush all
```

### Settings groups
| Group | wp_options key | Description |
|-------|---------------|-------------|
| `general` | `jpkcom_postfilter_general` | Post types, endpoint, max combos |
| `filter_groups` | `jpkcom_postfilter_filter_groups` | Taxonomy filter configuration |
| `layout` | `jpkcom_postfilter_layout` | Layouts, CSS vars, custom CSS |
| `cache` | `jpkcom_postfilter_cache` | Cache TTL, layer toggles |

---

## Cache Manager

### Four layers
| Layer | Implementation | Use case |
|-------|---------------|---------|
| 1 | Settings file (`.ht.*`) | Plugin settings |
| 2 | `wp_cache_*` (Object Cache) | Query results |
| 3 | `get/set_transient` | Taxonomy term lists |
| 4 | APCu (optional) | Settings + frequent queries |

### Key functions
```php
jpkcom_postfilter_cache_get( $key, $found )   // Layer 2+4
jpkcom_postfilter_cache_set( $key, $value, $ttl )
jpkcom_postfilter_cache_flush_group()          // Flush all Layer 2+4
jpkcom_postfilter_transient_get( 'terms_category' )  // Layer 3
jpkcom_postfilter_transient_set( 'terms_category', $data )
jpkcom_postfilter_query_cache_key( $query_args, $filters )  // MD5 key
```

### Automatic invalidation
- `save_post` / `deleted_post` → flush Layer 2+4
- `created_term` / `edited_term` / `delete_term` → flush Layer 3 + 2+4

---

## Template System

### Override priority
1. Child theme: `themes/child/jpkcom-post-filter/{template}`
2. Parent theme: `themes/parent/jpkcom-post-filter/{template}`
3. MU plugin: `mu-plugins/jpkcom-post-filter-overrides/templates/{template}`
4. Plugin: `debug-templates/{template}` (when debug) | `templates/{template}`

### Key functions
```php
jpkcom_postfilter_locate_template( 'partials/filter/filter-bar.php' )
jpkcom_postfilter_get_template_part( 'partials/filter/filter-bar', '', $args )
jpkcom_postfilter_get_template_html( 'partials/list/list-cards', '', $args )  // returns string
```

### Template actions
- `jpkcom_postfilter_before_template_part` (path, slug, name, args)
- `jpkcom_postfilter_after_template_part` (path, slug, name, args)

---

## Helpers

```php
jpkcom_postfilter_locate_file( 'filename.php' )          // Locate include with override
jpkcom_postfilter_debug_log( 'msg', $context )           // Debug logging
jpkcom_postfilter_sanitize_csv_slugs( 'cat-1,cat-2' )   // → ['cat-1', 'cat-2']
jpkcom_postfilter_sanitize_csv_ids( '1,2,3' )            // → [1, 2, 3]
jpkcom_postfilter_get_active_filters()                    // From query vars
jpkcom_postfilter_build_filter_url( $base, $filters )    // Build SEO URL
jpkcom_postfilter_get_current_archive_url()              // Current archive base
jpkcom_postfilter_is_filter_request()                    // Is filtered page?
```

---

## CSS Variables (Design Tokens)

All prefixed `--jpkpf-`. Key variables:
```css
--jpkpf-primary              /* #0073aa */
--jpkpf-primary-hover        /* #005d8c */
--jpkpf-filter-bg            /* #f0f0f1 */
--jpkpf-filter-active-bg     /* #0073aa */
--jpkpf-filter-active-color  /* #ffffff */
--jpkpf-filter-radius        /* 3px */
--jpkpf-gap                  /* 0.5rem */
--jpkpf-card-radius          /* 4px */
--jpkpf-transition           /* 0.2s ease */
```
Override via admin (Layout & Design → CSS Variables) or theme CSS.

---

## Shortcodes

### `[jpkcom_postfilter_filter]`
| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any post type | `post` |
| `layout` | bar / sidebar / dropdown / columns | backend setting |
| `groups` | CSV slugs | all groups |
| `reset` | true / false / always | backend setting |
| `class` | string | – |

### `[jpkcom_postfilter_list]`
| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any post type | `post` |
| `layout` | cards / rows / minimal / theme | backend setting |
| `limit` | integer (-1 = all) | `5` |
| `orderby` | date / title / menu_order / modified / rand | `date` |
| `order` | ASC / DESC | `DESC` |
| `class` | string | – |

### `[jpkcom_postfilter_pagination]`
| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any post type | `post` |
| `class` | string | – |

---

## Frontend JavaScript

File: `assets/js/post-filter.js`

### Data attribute interface
```html
<!-- Auto-inject mode -->
<div data-jpkpf-wrapper data-jpkpf-base-url="/blog/">
    <nav data-jpkpf-filter-bar>
        <a class="jpkpf-filter-btn"
           data-filter-taxonomy="category"
           data-filter-term="web-design"
           aria-pressed="false">Web Design</a>
    </nav>
    <div data-jpkpf-results aria-live="polite">...</div>
    <div data-jpkpf-live-region aria-live="polite"></div>
</div>

<!-- Block / Shortcode / Elementor / Oxygen mode: paired by data-jpkpf-post-type -->
<nav data-jpkpf-filter-bar data-jpkpf-post-type="post" data-jpkpf-base-url="/blog/">...</nav>
<div data-jpkpf-results data-jpkpf-post-type="post">...</div>
<nav data-jpkpf-pagination data-jpkpf-post-type="post">...</nav>
```

### AJAX behaviour
- Request appends the `/jpkpf-fragment/` URL segment (see **Fragment responses** below)
- JS extracts `[data-jpkpf-results]` from response and swaps into DOM
- `swapPagination()` updates standalone `[data-jpkpf-pagination]` elements outside the results zone
- Auto-inject mode (`[data-jpkpf-wrapper]`) skips standalone pagination insertion to prevent duplicates

---

## Fragment responses (since 1.2.0)

Up to 1.1.7 the script sent `?jpkpf_ajax=1` — a parameter that appeared in **no
PHP file at all**. Every filter click rendered a complete page (theme header, nav
menus, sidebar widgets, footer, the whole asset pipeline) and the browser threw
all of it away except `[data-jpkpf-results]`.

`includes/fragment-response.php` answers those requests with just the swappable
zones. Four things about it are load-bearing.

**1. It is a URL segment, not a query parameter.** A full-page cache keys on the
URL, and several common configurations strip parameters they do not recognise.
A stripped parameter collapses the fragment URL onto the real page URL, and the
cache then serves a bare, theme-less fragment to an ordinary visitor. A path
segment cannot collapse that way. The segment is `jpkpf-fragment`, not
`fragment`, because it sits in the same path position as term slugs and a site
with a term called `fragment` would produce ambiguous URLs. Filterable via
`jpkcom_postfilter_fragment_segment` — changing it needs a rewrite flush.

**2. The fragment rewrite rules must be registered before the page rules.**
`add_rewrite_rule( …, 'top' )` keeps insertion order and WordPress takes the
first match; the page rule's `(.+?)` happily swallows a trailing
`/jpkpf-fragment` as part of the filter path. Registered in the wrong order the
fragment rules are unreachable and every AJAX request quietly renders a normal
page for a non-existent term. Guarded by `tests/test-fragment.php`.

**3. The theme's loop still runs, and has to.** In auto-inject mode the markup
inside `[data-jpkpf-results]` is produced by the *theme* between `loop_start`
and `loop_end` — this plugin never renders those posts. So the saving is the nav
menu and widget queries, the enqueue/print pipeline, and the transferred bytes.
**Not** the query and **not** the loop. Anyone expecting "renders only the list"
will be disappointed by a profile.

**4. Zones are cut by markers, not by parsing.** `jpkcom_postfilter_zone_open()`
/ `_close()` write HTML comments around each swappable zone, and only on a
fragment request, so normal page output is byte-identical to before. Extraction
is then substring work against markers this plugin wrote itself. Searching for
`[data-jpkpf-results]` and its closing tag would be guesswork — the zone holds
arbitrary theme markup with nested elements. An unterminated marker yields
nothing rather than the rest of the document; the full-page dump is the exact
failure this feature exists to remove.

### The one wp_footer callback that must survive

`template_redirect` clears `wp_head` and `wp_footer`, then **re-attaches**
`jpkcom_postfilter_render_zero_results_fallback()`. When auto-injection applies
but the main loop never runs — a filter combination with zero results — that
callback is the only thing that emits a results zone. Without it a zero-result
click returns a fragment with no swappable zone and the previous results stay on
screen. That is why it is a named function and not a closure: a closure can be
removed but never put back. Guarded by `tests/test-fragment.php`.

### PHP and JS build the same URL

`jpkcom_postfilter_fragment_url()` and `fragmentUrl()` in
`assets/js/post-filter.js` are two implementations of one rule. Nothing in a
normal test run would notice them drifting — the PHP side would keep passing its
own tests while every AJAX request 404s. `tests/test-fragment.php` therefore
runs the JS function through node over a shared case list
(`tests/fragment-url-cases.json`) and compares. Without node it reports SKIP,
never PASS.

### Rewrite flush on update

`register_activation_hook` does **not** fire on a plugin update, so a release
that changes rewrite rules would ship rules that never reach the database.
`jpkcom_postfilter_maybe_flush_rewrites()` compares
`jpkcom_postfilter_rewrite_version` against the plugin version on `init`
(priority 99) and flushes once.

### Verified on a live installation

Measured 2026-07-28 on a DDEV instance (WordPress, PHP 8.4, theme
`bootscore-child`, 19 posts across 3 categories and 3 tags, no page cache, no
WPML):

| Request | Full page | Fragment |
|---|---|---|
| Archive | 76 339 B / 420 ms | 30 653 B / 397 ms |
| Filtered (`web-design`) | 63 800 B / 483 ms | 18 054 B / 389 ms |

Time-to-first-byte, mean of 8 runs after 3 warm-ups. **Transfer drops 60–72 %,
server time 5–19 %.** That ratio is the point: the loop and the query stay, so
do not expect the response time to collapse. Confirmed present in the fragment
and absent from it: results zone and pagination yes; `<html>`/`<head>`/`<body>`,
the filter bar, script and stylesheet tags no. `Cache-Control: no-store`,
`X-Robots-Tag: noindex` and `X-Content-Type-Options` are set on fragments and
absent on normal pages, which are byte-for-byte unaffected.

`bootscore-child` needed no adaptation. A theme that renders content from
`wp_head` or `wp_footer` would lose it in a fragment; nothing inside
`[data-jpkpf-results]` is affected.

The browser side was driven too, with `tests/browser-check.mjs` (headless
Chromium, puppeteer). Confirmed on a real click: a fragment request is issued,
no document request follows, the results zone is swapped while the filter bar
survives as the same DOM node, `history.pushState` updates the visible URL
without the fragment segment, no zone markers leak into the DOM, history back
restores the unfiltered list and releases the buttons, and a zero-result
combination renders "Keine Beiträge gefunden." inside a live results zone. No
JavaScript errors.

```bash
node tests/browser-check.mjs https://your-site.test/
```

It is not part of the CI suite — CI has no server. `puppeteer-core` arrives
transitively via `@wordpress/scripts`; a missing module or missing Chromium
exits 0 with SKIP rather than a failure that says nothing about the plugin. On
WSL2 with snap Chromium the launch flags in the file are required.

### Three bugs this verification exposed

Neither was findable from source, and both are guarded by
`tests/test-fragment.php` now.

**Canonical redirect ate paginated fragments.** `/page/2/jpkpf-fragment/`
answered `301 → /page/2/jpkpf-fragment/page/2/`: `redirect_canonical()` does not
recognise the segment and "repaired" a URL it read as missing its pagination.
The script follows the redirect, gets a 404 and falls back to a full reload — so
paginating a filtered list silently stopped using AJAX at all, in exactly the
case the feature exists for. Fixed by disabling canonical redirects on fragment
requests.

**`apcu_cache_info()` emitted a PHP warning** from
`jpkcom_postfilter_apcu_available()` whenever APCu is loaded but inactive for
the running SAPI — `apc.enable_cli` defaults to 0, so every WP-CLI call produced
it. `ini_get( 'apc.enabled' )` does not catch this because it reports the
web-SAPI setting. Not cosmetic here: with `display_errors` on, the warning is
printed into the response body, and in a fragment it lands inside the swapped
markup. Replaced with `apcu_enabled()`, which answers the same question without
a diagnostic. This bug predates 1.2.0 and affects the normal page render too.

**Browser back left stale results.** The `popstate` handler only re-fetched when
`e.state.jpkpf` was set — but the history entry created by the *initial page
load* carries no state at all. Going back to it did nothing: the address bar
returned to the unfiltered archive while the results zone kept showing the
filtered list and the filter buttons stayed pressed. Now it falls back to
`location.href` and re-syncs the buttons from the URL via `syncButtonsToUrl()`.
This bug also predates 1.2.0 — the handler is byte-identical in 1.1.7 — and is
unrelated to fragment responses; it was simply never exercised until someone
clicked back in a browser.

### Re-running the checks elsewhere

```bash
# Fragment must contain no document chrome
curl -s https://example.com/blog/filter/web-design/jpkpf-fragment/ | head -c 400
# Must not be cacheable and must not be indexable
curl -sI https://example.com/blog/filter/web-design/jpkpf-fragment/ \
  | grep -iE 'cache-control|x-robots-tag'
# Pagination must not redirect
curl -sI https://example.com/blog/page/2/jpkpf-fragment/ | grep -iE '^HTTP|^location'
# The normal page must be unchanged
curl -s https://example.com/blog/filter/web-design/ | grep -c '<head'
```

---

## Security Checklist

- All outputs: `esc_html()`, `esc_url()`, `esc_attr()`, `wp_kses_post()`
- All forms: `wp_nonce_field()` + `check_admin_referer()`
- All AJAX: `check_ajax_referer()` + `current_user_can()`
- `declare(strict_types=1)` in every PHP file
- Typed function signatures throughout
- Settings path validated against `WP_CONTENT_DIR` via `jpkcom_postfilter_path_is_inside()`. This matters because `JPKCOM_POSTFILTER_SETTINGS_DIR` is overridable from `wp-config.php` and is where the plugin writes PHP files it later `include`s. Up to 1.1.6 the check compared `realpath( WP_CONTENT_DIR )` with itself and never mentioned the directory being validated, so it was structurally always false and never fired — do not reintroduce that shape. The helper resolves the deepest *existing* ancestor, because the directory legitimately does not exist yet on first run.
- Settings cache dir protected by `.htaccess`
- `jpkcom_postfilter_build_query_args()` accepts extra WP_Query args only in a coerced form (`s` sanitised, `author` → list of positive ints, `year`/`monthnum` → `absint`, `meta_value` only alongside a `meta_key`). **`meta_query` is deliberately not forwarded** — it is a nested structure that cannot be validated in passing. Callers needing it use the `jpkcom_postfilter_query_args` filter, which runs with knowledge of its own input.

### Filter URLs with unknown term slugs

Term slugs from the URL are sanitised but never checked for existence, so `/blog/filter/does-not-exist/` renders a valid page with zero results. These requests **keep returning 200** — turning them into 404s would break legitimate old links whose terms were later renamed — but `jpkcom_postfilter_has_unknown_terms()` marks them `noindex, follow`.

> **`wp_robots` alone is not enough, and was not enough between 1.1.7 and 1.2.0.**
> Rank Math calls `remove_all_filters( 'wp_robots' )` before emitting its own tag
> (`seo-by-rank-math/includes/frontend/class-head.php`). Every callback on that
> hook is discarded, so the noindex never happened. Measured on a live install: a
> bogus filter URL rendered `<meta name="robots" content="follow, index">`, and
> reading the hook registry mid-request showed `wp_robots` with **zero**
> callbacks while the filter had already run once. This stack ships
> `jpkcom-rank-math-options`, so Rank Math is the normal configuration — the
> protection was inert on the sites it was written for. The same rule now also
> hooks `rank_math/frontend/robots` and `wpseo_robots_array`, all three sharing
> `jpkcom_postfilter_should_noindex()`. Guarded by `tests/test-fragment.php`.

> **Where the zero-results output appears.** Until 1.2.0 the fallback was
> attached only to `wp_footer`, which fires *inside* the footer template — so on
> exactly these URLs the "no posts found" message and the entire filter bar were
> rendered visually below the footer. Measured: footer closed at byte 39713, the
> filter bar started at 40153. It now runs on `get_footer` (end of the content
> area) with `wp_footer` kept as a last resort for block themes that never call
> `get_footer()`, and a guard so it cannot render twice.

Without that, every made-up slug was an indexable, self-canonicalising thin-content URL that anyone could generate and link, and each one also produced its own query-cache entry (APCu included).

Validation reuses the transient-cached per-taxonomy term list, keyed by **taxonomy — never by the requested slugs** — so it costs no extra query on a warm cache and cannot itself be used to flood the cache. `hide_empty = false`: a term with no posts is still a real term and a legitimate URL.
- Updater: SHA256 checksum verification is **mandatory** (fail closed) and the verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed

**This plugin holds the upstream copy of `includes/class-plugin-updater.php`.** The other 17 JPKCom plugins carry byte-identical downstream copies that differ only in namespace and text domain. Fix bugs here, then re-generate the copies — never patch a downstream copy in isolation.

**Supply-chain: GitHub Actions sind auf Commit-SHAs gepinnt.** Alle `uses:`-Zeilen in `.github/workflows/` referenzieren einen 40-stelligen Commit-SHA statt eines Tags (`@v4`), mit der Version als Kommentar dahinter. Grund: ein Tag ist ein beweglicher Zeiger und lässt sich umhängen, ein SHA nicht. Da dieser Workflow die Plugin-ZIP **und** die SHA256-Summe erzeugt, der der Auto-Updater vertraut, würde eine kompromittierte Action ein manipuliertes ZIP samt passender Prüfsumme ausliefern — die Prüfsumme sichert den Transportweg, das Pinning den Build. `.github/dependabot.yml` hält die Pins wöchentlich aktuell (ein gesammelter PR). Beim Aktualisieren immer SHA *und* Versionskommentar zusammen ändern.

**CI & Dependabot-Auto-Merge.** Zwei zusätzliche Workflows:

- `.github/workflows/ci.yml` — läuft auf jedem `pull_request`. Prüft: `php -l` über alle PHP-Dateien; ungültige benannte Argumente an internen PHP-Funktionen (fängt die Klasse `sprintf(format:, values:)` → `ArgumentCountError`, die `php -l` nicht sieht); YAML-Validität aller `.github`-Dateien; und dass jede Action auf einem 40-stelligen Commit-SHA gepinnt ist (beide YAML-Formen, `uses:` und `- uses:`).
- `.github/workflows/dependabot-auto-merge.yml` — merged Dependabot-PRs automatisch, aber nur `semver-patch` und `semver-minor`. Major-Updates bekommen stattdessen einen Kommentar und bleiben manuell. Greift nur bei PRs von `dependabot[bot]` aus diesem Repo, nie aus Forks.

> **Zwei Repo-Einstellungen sind Voraussetzung, sonst ist der Auto-Merge wirkungslos oder gefährlich:**
> 1. **„Allow auto-merge"** muss in den Repo-Settings aktiv sein.
> 2. Der Branch-Schutz muss den CI-Job als **Required status check** führen (`CI / Lint & Guards`). Fehlt das, merged `gh pr merge --auto` **sofort** — es gibt dann nichts, worauf es warten müsste, und die CI wäre reine Dekoration.

Zusammen mit `cooldown: default-days: 7` in der `dependabot.yml` heißt das: kein Action-Release wird in seiner ersten Woche übernommen, patch/minor laufen danach automatisch durch (sofern CI grün), major bleibt eine bewusste Entscheidung.



---

## Admin Pages

| Menu slug | Callback | Settings group |
|-----------|---------|----------------|
| `jpkcom-post-filter` | `jpkcom_postfilter_page_general` | `jpkcom_postfilter_general` |
| `jpkcom-postfilter-filter-groups` | `jpkcom_postfilter_page_filter_groups` | `jpkcom_postfilter_filter_groups` |
| `jpkcom-postfilter-layout` | `jpkcom_postfilter_page_layout` | `jpkcom_postfilter_layout` |
| `jpkcom-postfilter-shortcodes` | `jpkcom_postfilter_page_shortcodes` | – |
| `jpkcom-postfilter-cache` | `jpkcom_postfilter_page_cache` | `jpkcom_postfilter_cache` |
| `jpkcom-postfilter-import-export` | `jpkcom_postfilter_page_import_export` | – |

---

## Gutenberg Block Details

### Block tree pre-scan
The `pre_render_block` filter (priority 5) pre-scans the block tree for `jpkcom/post-list` blocks and pre-runs their queries via `$GLOBALS['jpkpf_shortcode_queries']`. This ensures pagination blocks placed **above** the list block have access to the query.

Sources scanned:
1. FSE template content (`$_wp_current_template_content`)
2. Post content (`$post->post_content`)

### Build
```bash
cd blocks/
npm install
npm run build
```
Block registration is skipped when `blocks/build/` is missing.

---

## Elementor Widget Details

- Namespace: `JPKComPostFilter\Elementor`
- Category: `jpkcom-post-filter`
- Guard: `did_action('elementor/loaded')`
- Registration hook: `elementor/widgets/register`

---

## Oxygen Element Details

- Namespace: `JPKComPostFilter\Oxygen`
- Toolbar section: `jpkcom-post-filter` (registered via `oxygen_add_plus_sections`)
- Guard: `class_exists('OxyEl')` — checked inside `init` hook (priority 11) to ensure Oxygen has loaded
- Elements use `button_place()` → `'jpkcom-post-filter::section_content'`
- Keywords include `jpkcom` for searchability in Oxygen's component search
