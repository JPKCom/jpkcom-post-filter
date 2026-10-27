# JPKCom Post Filter – Developer Reference

## Plugin Overview

WordPress plugin for faceted navigation and filtering of Posts, Pages, and Custom Post Types via WordPress taxonomies.

- **Text Domain:** `jpkcom-post-filter`
- **Min PHP:** 8.3 | **Min WP:** 7.0
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
| `JPKCOM_POSTFILTER_VERSION` | matches the header `Version:` | Plugin version |
| `JPKCOM_POSTFILTER_BASENAME` | `plugin_basename(__FILE__)` | Plugin basename |
| `JPKCOM_POSTFILTER_PLUGIN_PATH` | `plugin_dir_path(__FILE__)` | Absolute path |
| `JPKCOM_POSTFILTER_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL |
| `JPKCOM_POSTFILTER_SETTINGS_DIR` | `WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings'` | Settings cache dir |
| `JPKCOM_POSTFILTER_CACHE_ENABLED` | `true` | Master cache toggle |
| `JPKCOM_POSTFILTER_DEBUG` | `WP_DEBUG` | Debug mode |
| `JPKCOM_POSTFILTER_URL_ENDPOINT` | `'filter'` | URL segment |
| `JPKCOM_POSTFILTER_ABILITIES` | `true` | Abilities API registration master switch |

URL/crawl policy (1.5.0, see **Crawl guard** below). Each one also exists as a General setting; a defined constant wins and locks the admin field. Every value additionally passes the filter `jpkcom_postfilter_{key}`.

| Constant | Setting key | Default | Purpose |
|----------|-------------|---------|---------|
| `JPKCOM_POSTFILTER_MAX_FILTERS_PER_GROUP` | `max_filters_per_group` | `2` (since 1.6.0; `3` before) | Terms per group (0 = unlimited), counted raw since 1.6.0 (see **Server rules**) |
| `JPKCOM_POSTFILTER_MAX_FILTER_COMBOS` | `max_filter_combos` | `3` | Active groups (0 = unlimited, admin form 1–10) |
| `JPKCOM_POSTFILTER_MAX_TERMS_TOTAL` | `max_terms_total` | `0` | Terms across all groups (0 = off) |
| `JPKCOM_POSTFILTER_LINK_MODE` | `filter_link_mode` | `'single'` | `single` / `combo` / `none` – what term buttons link to |
| `JPKCOM_POSTFILTER_INDEX_MAX_TERMS` | `seo_index_max_terms` | `1` | Filter pages up to N terms are `index, follow` (0 = none) |
| `JPKCOM_POSTFILTER_STRICT_URLS` | `strict_urls` | `true` | Request guard on/off |
| `JPKCOM_POSTFILTER_REJECT_STATUS` | `reject_status` | `410` | `404` or `410` for impossible paths |
| `JPKCOM_POSTFILTER_UNKNOWN_TERMS_ACTION` | `unknown_terms_action` | `'redirect'` | `redirect` / `noindex` / `reject` |
| `JPKCOM_POSTFILTER_ROBOTS_TXT` | `robots_txt_mode` | `'deep'` | `deep` / `all` / `off` |
| `JPKCOM_POSTFILTER_STRIP_QUERY` | `strip_query` | `false` | 301 filter URLs with a query string to the bare path |
| `JPKCOM_POSTFILTER_CANONICAL_SINGLE` | `canonical_single` | `'archive'` | `archive` / `filter` / `off` – canonical of a one-term page (1.7.0, see **Canonical targets**) |
| `JPKCOM_POSTFILTER_CANONICAL_MULTI` | `canonical_multi` | `'home'` | `home` / `filter` / `off` – canonical of a page with two or more terms (1.7.0) |

**The default 2 applies to new installations only.** An existing installation that never stored `max_filters_per_group` would otherwise drop from 3 to 2 silently and start rejecting URLs it served the day before. `jpkcom_postfilter_migrate_pin_per_group_cap()` (`includes/settings.php`) runs in the version-change hook, before the rewrite-version marker moves, and only on the step from a version before 1.6.0: the marker is older than 1.6.0, or it is empty (an install from before 1.2.0, when the marker came) and the filter groups option exists. A fresh install has neither, and once the marker says 1.6.0 or later it never writes again - before that fix a fresh 1.6.0 install was pinned to 3 on its first update. When it runs and the stored General array lacks the key, it writes the stored array plus `max_filters_per_group = 3` - **without the General sanitizer** (it would drop post types registered later and read missing checkboxes as "none") and without merging defaults (that would freeze constant-derived values into the database). A stored value, including 2 or 0, is never touched. Tests: `tests/test-migration.php`.

Constant only: `JPKCOM_POSTFILTER_ROBOTS_HEADER` (default `true`, X-Robots-Tag on non-indexable filter pages), `JPKCOM_POSTFILTER_LINK_MAX_TERMS` (default `0`, depth limit for `combo` links), `JPKCOM_POSTFILTER_PAGECACHE_MAX_TERMS` (default `0` = off; above N terms `DONOTCACHEPAGE`), `JPKCOM_POSTFILTER_QUERY_CACHE_MAX_TERMS` (default `1`; deeper selections skip the query cache).

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
│   ├── url-policy.php              ← Pure URL rules: normalisation, caps, path validation (1.5.0)
│   ├── url-routing.php             ← Rewrite rules + URL parsing/building
│   ├── canonical-target.php        ← Pure: is a term archive / the archive base an indexable canonical target (1.7.0)
│   ├── crawl-guard.php             ← Request guard, robots, canonical, robots.txt, term links (1.5.0)
│   ├── server-rules.php            ← Pure: rule inputs, hash, reject patterns (1.6.0)
│   ├── server-rules-render.php     ← Pure: rate-limit formula, reject body, per-server snippets, curl checks (1.6.0)
│   ├── htaccess-manager.php        ← Pure file ops: managed .htaccess block (place, replace, remove) (1.6.0)
│   ├── htaccess-sync.php           ← Hooks: keep the managed block never stricter than the plugin (1.6.0)
│   ├── server-rules-check.php      ← "Check now" loopback probes + notice after loosening saves (1.6.0)
│   ├── server-rules-admin.php      ← Server Rules admin page and its admin-post actions (1.6.0)
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
│   ├── js/shortcode-generator.js   ← Admin shortcode builder
│   └── js/admin-server-rules.js    ← Server Rules page: tabs, copy buttons (1.6.0)
└── languages/
    ├── jpkcom-post-filter.pot
    ├── jpkcom-post-filter-de_DE.{po,mo,l10n.php}
    └── jpkcom-post-filter-de_DE_formal.{po,mo,l10n.php}
```

---

## Settings Cache System

### How it works
1. **Read priority:** File cache → wp_options (DB)
2. **On admin save:** wp_options + file cache updated simultaneously; **on `delete_option()`** the group's cache file is deleted (1.7.1, T2-b - otherwise it kept answering for the deleted row)
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
| 4 | APCu (optional, only without an external object cache) | Query results |

Settings are **not** in APCu (earlier versions of this file said so; it was never implemented). Since 1.5.0 they are memoised per request in `jpkcom_postfilter_settings_get_group()`; `jpkcom_postfilter_settings_memo_reset()` runs on every save, cache deletion and option update. Since 1.5.0 the query cache only stores selections of at most `JPKCOM_POSTFILTER_QUERY_CACHE_MAX_TERMS` (1) terms without unknown slugs, keyed on the normalised filters.

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
| `limit` | integer (-1 = all) | `-1` (the block, Elementor and Oxygen list default to `5`) |
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
- One request per results zone: a new click aborts the previous fetch (`AbortController`)
- popstate onto the URL the zone already shows aborts a request still pending from an earlier back/forward step (tracked in the `popstateRequests` WeakSet), clears the live region and re-syncs the buttons - otherwise a fast double back landed the in-between entry's results under the current address (browser-check "Fast double back"). A pending **click** is never aborted there: following an in-page `#anchor` (a theme's "back to top") fires popstate for the same page while the click loads.
- Live region: "No posts found" only when the zone contains `.jpkpf-no-results`; otherwise the count of `[data-jpkpf-post]`, or of `article` for the theme's own loop (auto-inject), which carries no plugin markers - until 1.5.0 every successful click there was announced as "No posts found". The wording "N" + `filterActive` ("Filter aktiv") is older and imprecise (it counts posts on the page); not changed in 1.5.0.
- `shownUrls` (WeakMap zone → URL whose results it shows): popstate that only changes the `#hash` of the page the zone already shows does nothing - before 1.5.0 such a step re-fetched, and when the fetch failed, the fallback `location.href = url` was another hash navigation and another popstate: an endless loop (~60 requests/s, pre-existing since 1.2). Every fallback load goes through `loadUrl()`, which reloads instead of assigning a same-document URL.
- Errors (1.5.0): after a **click**, a 404/410 carrying `X-Jpkpf-Reject` (the request guard's answer) and a 429/5xx both keep the current results, re-sync the buttons to the address bar (`syncButtonsToUrl()` - they were switched before the request and would otherwise claim a selection the list does not show) and show `.jpkpf-fetch-error` - unless the click superseded a back/forward request whose swap never happened (the zone does not show the address bar's URL), which loads the address bar's URL. After **back/forward** (`pushState === false`) the address bar has already moved, so any failure loads the popped URL instead - keeping the old list would contradict address bar and buttons. A network error, an unmarked 404 or a response without results zone always reloads. Before 1.5.0 every non-2xx reloaded - onto the error page, and as a second, heavier request against an overloaded server
- `buildFilterUrlFromMap()` sorts and de-duplicates slugs exactly like PHP; `tests/test-url-policy.php` runs it through node over `tests/filter-url-cases.json` and compares with `jpkcom_postfilter_get_filter_url()`

---

## Auto-inject in block themes (since 1.7.1)

A block theme renders the main loop inside `get_the_block_template_html()`, which `template-canvas.php` calls **before** it prints the doctype: everything the `loop_start`/`loop_end` callbacks echoed landed in front of `<!DOCTYPE html>` (measured with Twenty Twenty-Five on 1.7.0). With `jpkcom_postfilter_inject_via_blocks()` (default: `wp_head` has not run yet - the loop renders inside a block template; filterable. Not `wp_is_block_theme()`: a PHP archive template in a block theme loops in the body and prints the classic way):

- `loop_start`/`loop_end` keep their markup (`jpkcom_postfilter_auto_inject_open_html()` / `_close_html()`, the 1.7.0 output as strings) in `$GLOBALS['_jpkpf_block_open'|'_jpkpf_block_close']`; `render_block_core/post-template` puts it around the post template of the query that inherits the main query (`jpkcom_postfilter_block_inherits_query()`), once per page and only when **both** halves exist - an inherited query nested inside a post is filtered before `loop_end` and stays untouched;
- the pagination flag is set only after that wrap, and `max_num_pages` is **not** set to 1 in this mode: Rank Math and Yoast read it in `wp_head`, which a block template runs after the loop ("Page N of M", rel=next);
- the inherited `core/query-pagination` renders nothing whenever the main query has posts and auto-inject applies (also before the post template);
- zero results: the inherited `core/query-no-results` is replaced by `jpkcom_postfilter_zero_results_html()` (the fallback markup as a string; the footer fallback then stays silent).

Classic themes print exactly what 1.7.0 printed: `tests/test-block-injection.php` compares against `tests/fixtures/classic-inject-1.7.0.txt`, produced from v1.7.0's `filter-injection.php` through the same stubs (`php tests/test-block-injection.php --dump=<file>` regenerates it). Live (1.7.1 lab): 8 URLs byte-identical on twentytwentyone; on Twenty Twenty-Five nothing before the doctype, fragments, pagination and zero results in place, `browser-check.mjs` green. Block themes render posts as `li.wp-block-post` without `article`: the live region and `browser-check.mjs` count those too.

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
/ `_close()` write a marker around each swappable zone, and only on a
fragment request, so normal page output is byte-identical to before. Extraction
is then substring work against markers this plugin wrote itself. Searching for
`[data-jpkpf-results]` and its closing tag would be guesswork — the zone holds
arbitrary theme markup with nested elements. An unterminated marker yields
nothing rather than the rest of the document; the full-page dump is the exact
failure this feature exists to remove.

**The markers were HTML comments until 1.4.4, and that was a bug** — see below.
They are empty `<template>` elements now.

### An HTML minifier deletes a comment. That is what it is for.

Up to 1.4.3 the markers were `<!--jpkpf:zone:start-->` / `<!--jpkpf:zone:end-->`.
Autoptimize ships with **"Optimize HTML Code" on and "Keep HTML comments" off**
(`autoptimizeConfig::get_defaults()`: `autoptimize_html => 1`,
`autoptimize_html_keepcomments => 0`, and `get()` falls back to those defaults
whenever the option row is absent). It removed both markers, extraction found no
zone, and every filter click was answered with **0 bytes**.

Measured on the verification install (`~/ddev/posts`, bootscore-child, 19 posts,
Autoptimize 3.1.15.1), one filtered fragment URL:

| Autoptimize | `autoptimize_html` | `keepcomments` | Fragment |
|---|---|---|---|
| inactive | – | – | 18 300 B |
| active | 1 | 0 (stock) | **0 B** |
| active | 1 | 1 | 8 976 B |
| active | 0 | – | 18 300 B |

Which isolates it to the comment stripping and to nothing else Autoptimize does.

**Why Autoptimize wins the race.** Both plugins buffer the same response. This
one starts at `template_redirect` priority **0**, Autoptimize at priority **2**
(`autoptimizeMain::DEFAULT_HOOK_PRIORITY`). The later `ob_start()` is the
*inner* buffer and is flushed *first*, so Autoptimize minifies the page and hands
the result to `jpkcom_postfilter_fragment_ob_callback()`. Being the outer buffer
is what makes this plugin's output final — and it is also what put the minifier
upstream of the markers. Note the inverse case is harmless: with
`AUTOPTIMIZE_INIT_EARLIER` defined Autoptimize buffers from `init`, becomes the
outer one, and skips the fragment because `is_valid_buffer()` rejects a body
without `<html`.

**No minifier removes elements**, so the markers are elements now. `<template>`
because it renders nothing and is valid nearly anywhere. They never reach a
browser either way — extraction returns what lies *between* them.

### The failure mode was worse than the failure

A 0-byte 200 is not distinguishable from "the filter matched nothing" unless
someone says so. Nothing did: the script's `.catch()` → full-reload path only
fires on a network error, and its other branch wrote the no-results message into
the zone. So the filter looked like it worked and reported **no matches on every
click**, on a site where everything matched. That is why this reached production
and sat there.

Two rules now, and they are the general protection — the marker change only fixes
the one vendor that was found:

- **A fragment without `[data-jpkpf-results]` is never a zero-result answer.**
  Every list template writes that wrapper *before* it looks at the query and puts
  the empty state inside it (`list-cards.php:20`), and auto-inject mode has
  `render_zero_results_fallback()` for the same reason. So the script treats a
  missing zone as a damaged response and does a **full page load** of the same
  URL: slower, and always what the server would have rendered.
- **The server says which of the two happened.** `jpkcom_postfilter_zone_count()`
  counts what was written, because the buffer looks identical whether a zone was
  never marked or its markers were destroyed. The answer is
  `<div data-jpkpf-fragment-error="markers-stripped">` or `="no-zone"`, never an
  empty body.

### Optimisers are asked to skip fragment requests

`autoptimize_filter_noptimize` plus the `DONOTMINIFY` convention, both keyed on
`jpkcom_postfilter_request_looks_like_fragment()`. Three things about it:

**It is registered while the plugin file loads, not on a hook.** Autoptimize
decides once per request and caches the answer in a `static` inside
`should_buffer()`; `autoptimizeExtra::run_on_frontend()` and
`autoptimizeImages::run_on_frontend()` both call it on **`wp`**. A filter added
in `template_redirect` — where the rest of `fragment-response.php` works —
arrives after the decision is already frozen.

**The check cannot rely on the query var alone.** `wp` runs after the query is
parsed, but `init` does not, and with `AUTOPTIMIZE_INIT_EARLIER` that is where
the question is asked — `get_query_var()` would answer "no" and the page would
be minified anyway. Hence the `REQUEST_URI` path check, with the query var
preferred wherever it can answer. Note the `isset( $GLOBALS['wp_query'] )` guard is
load-bearing: the `DONOTMINIFY` line runs at include time, and `get_query_var()`
on a null `$wp_query` is a fatal.

**It is not the fix, it is the second layer.** With element markers a minifier
can no longer destroy a fragment, but nothing an optimiser does to one is wanted
in the first place — it has no head, no footer and no asset pipeline. Other
vendors go through `jpkcom_postfilter_fragment_noptimize_filters`, which is
applied at load time and therefore hookable **only from an mu-plugin**.

Verified on the same install, Autoptimize active with stock settings: fragment
18 300 B (identical to the Autoptimize-off baseline, so it really does stand
down), normal page 52 470 B against 65 149 B unoptimised (so normal pages are
still optimised). `tests/browser-check.mjs` all green. With a deliberately
aggressive mu-plugin that strips the `<template>` markers too, the fragment
answers `<div data-jpkpf-fragment-error="markers-stripped"></div>` and the click
falls back to a document request that lands on the correctly filtered page —
which is the browser check's one expected failure in that scenario ("no document
request").

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

## Abilities API (since 1.3.0)

`includes/abilities.php` registers two **read-only** WordPress Abilities so MCP clients, REST
automation and the WordPress AI client can query the plugin without scraping HTML.

| Ability | Returns |
|---|---|
| `jpkcom-post-filter/list-filters` | The filter groups and their terms for a post type |
| `jpkcom-post-filter/query-posts` | A filtered, paginated result set plus a shareable filter URL |

Both live in the category `jpkcom-content`, which `jpkcom-acf-jobs` and `jpkcom-acf-references` are
meant to share. Categories are global and **first-wins** — the second plugin to register the same slug
silently gets `null` — so registration goes through `wp_has_ability_category()` first.

### Key functions

```php
jpkcom_postfilter_get_ability_definitions()                 // pure: the wp_register_ability() args
jpkcom_postfilter_ability_allowed_taxonomies( $pt, $g, $e ) // which taxonomies may be filtered — reads taxonomy_exists()
jpkcom_postfilter_ability_validate_filters( $f, $allowed )  // true|WP_Error — the load-bearing guard
jpkcom_postfilter_ability_clamp_per_page( $value )          // never returns -1
jpkcom_postfilter_ability_filters_are_url_expressible( $f ) // may a filter_url be handed out for these filters?
jpkcom_postfilter_ability_json_object( $map )               // empty map -> stdClass, so it encodes as {}
jpkcom_postfilter_abilities_enabled()                       // kill switch + API presence
```

`jpkcom_postfilter_get_ability_definitions()` is deliberately pure — no registry access, no side
effects beyond `__()` and the `jpkcom_postfilter_ability_meta` filter, which runs once per ability.
That is what lets `tests/test-abilities.php` assert the registration arrays without a WordPress
install.

### Eleven things that will bite

**10. A search term over 1600 BYTES is discarded by core, silently, inside the query** (guarded since
1.3.1). `WP_Query::parse_query()` blanks `s` when `strlen()` exceeds 1600 — an anti-DoS measure with
no error, no filter and no notice, at `wp-includes/class-wp-query.php:867`, byte-identical on 6.9.4,
7.0.2 and 7.0.3. Because it runs *inside* the query, past every check the ability made, the caller
receives exactly the answer it would have got with no search term at all. Measured on WP 7.0.3: 1600
bytes returned 0 of 19, 1601 bytes returned all 19 — and since `search` is not echoed back in the
response, **nothing at all told the caller its term had been dropped**. Over MCP the wrapper even
reports `"success": true`.

The unit is **bytes**, and that is the whole trap: core calls `strlen()`, not `mb_strlen()`, so 801
`ü` is over the limit at 801 characters and 401 emoji at 401 characters. A `maxLength` in the input
schema counts *characters* and would therefore leave the hole open for exactly the input most likely
to hit it. The guard is `jpkcom_postfilter_ability_validate_search()`, it counts bytes, and it refuses
with a 400 that names the limit.

**11. An input key at the wrong nesting level is not an error — it is a full-corpus answer** (guarded
since 1.3.1). Neither input schema declares `additionalProperties`, so core hands the callback input
containing keys it never declared and the callback simply does not read them. Measured on WP 7.0.3:
`input[category][]=marketing` answered **HTTP 200, `total: 19`, `filters: {}`** while
`input[filters][category][]=marketing` answered `total: 6`. That is the exact failure
`validate_filters()` exists to prevent (see trap 1), reached by a route that guard does not stand on —
and flattening a nested map is the commonest shape error a tool-calling model makes. `perPage` for
`per_page` behaves the same way, silently.

`jpkcom_postfilter_ability_validate_input_keys()` rejects any top-level key outside
`JPKCOM_POSTFILTER_ABILITY_INPUT_KEYS` with a 400 that names both the rejected key and the accepted
set.

**Since 1.4.0 a build check cross-references that constant against each schema's `properties`**, so
the two statements of one list can no longer drift apart in silence. The drift that matters is the
one that looks harmless: a property added to the schema and not to the constant makes the guard
refuse an input the schema *documents*, which is a guard rejecting a correct request — worse than
the hole it closes, because the hole needs a caller to make a mistake and the false positive needs
only a caller. The other direction re-opens the full-corpus answer this guard exists to prevent.

**Do not replace the guard with `additionalProperties => false`.** Measured on WP 7.0.3: it is safe
(no fatal, anonymous included, a clean 400) but it preempts the guard entirely, because
`validate_input()` runs before the execute callback. The reply degrades from *"Unknown input key:
category. Accepted keys: post_type, filters, page, per_page, search. Taxonomy filters belong inside
"filters" …"* to core's *"category is not a valid property of the object"* — the accepted set and the
nesting hint both gone, and localised into the site language while these messages are not. The
static lint in the WordPress `wp-abilities-verify` skill asks for it on every object schema; on these
two schemas that recommendation is wrong, and this is the measurement that says so.

### filter_url and unknown slugs (1.5.0)

`query-posts` withholds `filter_url` when a requested slug does not exist and the request guard would redirect (or reject) that URL - i.e. unless `strict_urls` is off or `unknown_terms_action` is `noindex`. The query treats the slug as an IN clause matching nothing; the front end answered `/filter/nope/seo/` with `301 → /filter/_/seo/`, a different set. Sixth withholding reason beside the five in `jpkcom_postfilter_ability_query_posts()`; tested in `tests/test-abilities.php`.

### Nine things that will bite

**1. The unknown-taxonomy guard is the whole point.** `jpkcom_postfilter_build_tax_query()` drops a
clause for a taxonomy that does not exist (`query-handler.php:57`) and the query then returns the
**complete unfiltered corpus** with no error — measured as 19 of 19 posts. `validate_filters()` rejects
that input and names the valid taxonomies in the message so a model corrects itself in one turn.
Do not "simplify" it away.

The allow-list it validates against comes from `allowed_taxonomies()`, which asks `taxonomy_exists()`
about every configured group. **The configuration outlives the registration** — deactivate the plugin
that registered a taxonomy and its filter group stays in the settings — and a group in that state used
to be reported as filterable, accepted by `validate_filters()`, and then dropped by the query builder:
the same full-corpus failure, one step further along. Verified on the live install by saving a group
for `jpkpf_ghost_tax` and calling the ability. This is why that function reads WordPress state and is
not pure; only `get_ability_definitions()` still is.

**2. Never pass `-1` as the page size.** `build_query_args()` sets `no_found_rows` when the limit is
`-1` (`query-handler.php:123`), which reports `found_posts` as 0 no matter how many posts exist. The
shortcode default *is* `-1`, so mirroring shortcode defaults here ships a permanently-wrong total.

**3. Annotations decide the HTTP verb.** All three default to `null`, and the REST run controller maps
`readonly` → GET, `destructive` + `idempotent` → DELETE, otherwise POST. An ability registered without
annotations is POST-only. Both abilities set the full triple explicitly.

**4. Empty maps must encode as `{}`.** `filters`, `unknown_terms` and each post's `terms` are declared
`type: object`, but PHP serialises an empty array as `[]`, which a schema-validating client rejects.
`jpkcom_postfilter_ability_json_object()` wraps the empty case only, so PHP callers keep array access
where there is data.

**5. Nothing may throw.** Every external value crosses an `is_array()` / `is_scalar()` /
`instanceof` check before use. The `instanceof \WP_Term` and `\WP_Post` guards are not defensive
noise, and `tests/test-abilities.php` feeds them malformed data on purpose.

> **The reason these exist changed in 1.4.0, and they were kept anyway.** The floor was WP 6.9, where
> a `Throwable` escaping an ability callback is an **uncaught fatal** — the `Throwable → WP_Error`
> wrapper only landed in 7.0. The floor is now 7.0, so the core wrapper is always present and a
> throw is an error a client can act on rather than a blank page. The guards stayed because turning
> a malformed stored value into a usable answer is still better than turning it into a 500, and
> because removing them would be a change with no measurement behind it. What changed is their
> status: they are a quality choice now, not the only thing between a caller and a white screen.

**6. `filter_url` is only emitted when following it lands on the reported result set.** Five
independent reasons withhold it, all gated in one place in `query_posts()`, and every one of them was
measured on the verification install:

1. **No archive page.** `get_archive_base_url()` returns `''` for a post type without one — `page` is
   public and selectable in the settings. The link would be the relative path `/filter/news/`, and
   `archive_base_regex()` returns `null` for those post types, so no rewrite rule serves it either.
2. **A filter set this site would truncate.** `get_filter_url()` writes every requested slug into the
   path, but `parse_filter_path()` reads back at most `max_filters_per_group` slugs per taxonomy and
   `max_filter_combos` taxonomies (0 = unlimited in both, though only the per-group cap can be set to
   0 through the admin UI). Measured with four category slugs against a cap of 3: the ability reported
   four and linked to a page showing three. Gated by `filters_are_url_expressible()`, which is a
   second implementation of `url-routing.php:74-87` — that parser is the authority and nothing
   cross-checks them, so a change there must be mirrored here by hand.
3. **A page segment that means something else on the front end.** The URL carries `page/N/`, and the
   front end reads N in units of the **site's** `posts_per_page`, not the ability's `per_page`.
   Measured with `per_page` 3, `page` 2 against a site showing 10: the ability reported ids 157, 156,
   155 and handed out `/page/2/`, which answers 200 with 150 down to 1. Two valid pages, disjoint
   sets. So the link needs `page === 1` — which writes no page segment at all — or a `per_page` equal
   to the site's.
4. **A page past the last one.** Since the totals are recovered (see 9) that response is well-formed,
   which is exactly what makes the link dangerous: `/filter/allgemein/page/3/` against `total_pages`
   1 answers **404** while the ability answers 200.
5. **A search term was applied** (since 1.3.1). `get_filter_url()` writes the archive base, the
   taxonomy segments and an optional page segment. It has no parameter for a search term and never
   had one, so the link resolves to the same set *minus* the search. Measured on WP 7.0.3:
   `filters={category:[web-design]}` with `search=wordpress` reported `total 0` and handed out a URL
   rendering six posts — reported set and linked set disjoint. This reason was missing from 1.3.0
   because `search` was added to the ability in the same release as this gate and never got its entry.
   Appending `?s=` is **not** the fix: the front end's own filter chips are built by
   `get_filter_url()` too and drop the parameter, so the first click silently widens the set again.

Callers get the full result set in all five cases; only the link is withheld. Do not "restore" the
unconditional link, and do not drop a reason because the response next to it looks fine — reasons 3
and 4 exist precisely because it does. And when a new input axis is added to `query-posts`, ask what
it does to this gate before merging: reason 5 is what it costs to forget.

**7. An ability whose parameters are all optional still needs a top-level input `default`.**
`WP_Ability::normalize_input()` substitutes the input schema's **top-level** `default` when the input
is exactly `null`, and nothing else does. Without it `execute( null )` fails `validate_input()` with
`ability_invalid_input` before the callback ever runs — measured on WP 7.0.2 for both abilities. Both
schemas therefore carry a `default` as a sibling of `type` and `properties`. Core never applies
a **per-property** default, which is why the callbacks still resolve `post_type`, `page` and
`per_page` themselves; the two mechanisms are unrelated and both are needed.

That default goes through `ability_json_object()` for the same reason as point 4, and this one is
easy to believe is fine: `'default' => []` encodes as `[]` on a schema declaring `type: object`, and
the REST route still looks correct because core's list controller special-cases exactly that value
and rewrites it to `{}`. The MCP Adapter does not — it reads `$ability->get_input_schema()` and hands
the raw value to clients, so a schema-validating MCP client saw an object whose default was an array.
The callbacks are unaffected: a `stdClass` fails their `is_array()` check and falls through to `[]`,
which is what they do with any input they cannot use.

**8. A caller error without `data['status']` is an HTTP 500.** The REST run controller returns the
`WP_Error` verbatim and `rest_ensure_response()` defaults to 500. Both guards pass
`[ 'status' => 400 ]`. This matters more than a status code usually does: the entire design of these
messages is that they name the valid taxonomies or post types so an agent corrects itself in one turn
— and a 5xx tells that agent "transient server fault, retry the same call unchanged", the exact
opposite instruction.

**9. `WP_Query` reports 0 of everything for a page past the end.** `set_found_posts()` returns early
when `$this->posts` is empty, so `found_posts` and `max_num_pages` stay at 0. Measured: 19 posts,
`per_page` 10, `page` 3 answered `total: 0, total_pages: 0` beside `page: 3` — self-contradictory, and
a model reads it as an empty corpus. `query_posts()` therefore re-runs the same args for page 1 on
that path only and takes the totals from there, keeping `posts` empty and echoing the requested page
back. It goes through `run_query()`, so the cache layer still applies, and the normal path still costs
exactly one query — the guard is `$query->posts === [] && $page > 1`, and dropping the first half
doubles the query count on every paginated call. Repairing this answer is also what makes point 6's
fourth reason necessary: the response stopped looking broken, so nothing warned a caller off the
`filter_url` beside it, and that URL is a 404.

Also worth knowing: `wp_register_ability()` returns `null` on **every** failure path and reports only
through `_doing_it_wrong()`, which is silent in production — and so is
`jpkcom_postfilter_debug_log()` without `WP_DEBUG`. The return value is checked, but do not expect a
registration failure to announce itself on a customer site.

### Exposure

Three independent switches in `meta`: `show_in_rest` (core REST), `public` (WP 7.1; inert passthrough
on 6.9/7.0), and `mcp.public` — not a core key at all, but the MCP Adapter's own gate for both
discovery and execution. Rank Math vendors that adapter, so it is present on the usual stack.

`JPKCOM_POSTFILTER_ABILITIES = false` in `wp-config.php` suppresses registration entirely. Per-ability
control goes through `jpkcom_postfilter_ability_meta` and `jpkcom_postfilter_ability_capability`
(default capability `read`).

Listing over REST is gated only by `current_user_can( 'read' )`, so **every logged-in user can read
both abilities' labels, descriptions and full schemas**. Execution is gated by the permission callback,
and the query the ability *builds* is scoped to `post_status => 'publish'`.

> **That scoping is in the arguments, and the arguments are not necessarily what runs.** An earlier
> version of this line claimed unconditionally that "nothing unpublished can leak". It is not true.
> `post_status` is an ordinary query var and `pre_get_posts` holds the query by reference, so a
> third-party callback without an `is_main_query()` guard — or a site using this plugin's own
> documented `jpkcom_postfilter_query_args` filter to widen it — changes what comes back.
> `jpkcom_postfilter_ability_project_post()` then checks `instanceof \WP_Post` and **nothing else**:
> no status, no `post_password`. Measured on WP 7.0.3: a draft came back through the ability with its
> body text as `excerpt`, HTTP 200. The sibling plugin `jpkcom-acf-jobs` has a reader gate for exactly
> this (its trap 2); this one does not. Treat the guarantee as "the *base* rule is publish-only", not
> as "unpublished content cannot be reached".

### Verifying against a real installation

`wp ability` needs WP-CLI ≥ 2.13; DDEV ships 2.12, so use `ddev wp eval-file <file>.php` with the file
placed inside the DDEV project root. Over HTTP, with an application password:

```bash
# Discovery — anonymous must be 401
curl -sk -u "$USER:$APP_PW" https://your-site.test/wp-json/wp-abilities/v1/abilities

# Both run routes are GET, because readonly => true
BASE=https://your-site.test/wp-json/wp-abilities/v1/abilities
curl -sk -u "$USER:$APP_PW" "$BASE/jpkcom-post-filter/list-filters/run?input%5Bpost_type%5D=post"
curl -sk -u "$USER:$APP_PW" \
  "$BASE/jpkcom-post-filter/query-posts/run?input%5Bpost_type%5D=post&input%5Bfilters%5D%5Bcategory%5D%5B%5D=allgemein"

# No input at all must work — every parameter is optional
curl -sk -u "$USER:$APP_PW" "$BASE/jpkcom-post-filter/list-filters/run"

# A caller mistake must be 4xx, not 5xx
curl -skio /dev/null -w '%{http_code}\n' -u "$USER:$APP_PW" \
  "$BASE/jpkcom-post-filter/query-posts/run?input%5Bfilters%5D%5Btag%5D%5B%5D=seo"
```

The check that matters is that filtering **narrows**: compare the filtered `total` against the
unfiltered one and against the term's own count. A suite can pass while the filters never reach
`WP_Query` at all — that exact regression was caught in review and is now asserted at both call sites.

Two more that only a real installation can answer, both regressions that shipped once: a `filter_url`
must parse back to the filters it was built from (feed it to `jpkcom_postfilter_parse_filter_path()`),
and a `page` past the last one must still report the real `total` and `total_pages`.

Parsing the URL back is necessary but not sufficient — it says nothing about the page segment. The
check that catches point 6's reasons 3 and 4 is to **fetch** the emitted `filter_url` and compare the
post ids it renders against the ones just reported, for `page` 2 with a `per_page` that differs from
the site's `posts_per_page` and for a `page` past the last one. Both answered plausibly and both were
wrong: one 200 with a disjoint set, one 404.

Note `mcp-adapter/discover-abilities` has `show_in_rest => false`, so probing MCP visibility over REST
returns 404. Call it in-process instead.

---

## Crawl guard (since 1.5.0)

Files: `includes/url-policy.php` (pure functions, no hooks) and `includes/crawl-guard.php` (hooks). Tests: `tests/test-url-policy.php` (red against 1.4.7: 69 of 70 at the time of writing; the one green is the without-`with_front` negative case, true in both), `tests/browser-check.mjs` (live, 9 of the 1.5.0 checks red against 1.4.7).

### Why it exists

Measured on a production site (Oxygen + Rank Math + Hummingbird, 2 filter groups): ~865 000 requests a day, 97 % of them `/blog/filter/{a+b+c}/{x+y+z}/`, every URL unique, every one a full render with 200; PHP-FPM exhausted. Sources: a residential-proxy botnet (178 000 IPs/day), GPTBot, meta-externalagent. They found the URLs by following the filter bar's links. The plugin generated the maze itself:

- every term was an `<a href>` to "current selection + this term" **in click order**, with no cap check - at 3 active terms each further term linked to a 4-term URL;
- the server accepted every spelling: order, duplicates, over-cap (silently truncated), extra segments (silently ignored), `page/1` - all 200, `index,follow`, self-canonical;
- Rank Math printed a second canonical (`/blog/`) next to the plugin's;
- the script never reads those hrefs - they existed only for clients without JS, i.e. crawlers.

At 10 terms per group that is ~34 million URL strings instead of ~31 000 distinct selections (cap 3) or 20 single-term pages. No cache helps on an unbounded key space.

### What it does

| Request | Answer |
|---|---|
| canonical, within caps | 200 |
| other spelling (order, duplicate, case, `_` at the end, `page/1`, empty segment) | **301** to the canonical URL, query string kept (unless `strip_query`) |
| unknown slug (`unknown_terms_action = redirect`) | **301** to the selection without it, or to the archive |
| over a cap (per group: raw pieces since 1.6.0, see **Server rules**), more segments than groups, slug > 200 chars | **410** (or 404), minimal body, `X-Robots-Tag: noindex, nofollow`, `X-Jpkpf-Reject: <reason>` |
| `?jpkcom_filter_path=` / `_fragment=` / `_post_type=` via GET/POST | ignored - those vars are only taken from the matched rewrite rule |
| fragment request | never redirected (the script owns the URL); rejected like the page; served for the cleaned selection |

All of this runs on the `request` filter (`jpkcom_postfilter_guard_request()`, priority 1, after `jpkcom_postfilter_restrict_internal_query_vars()` at 0) - after the rewrite match, before the main query. A redirect or reject costs the bootstrap and nothing else. The decision is `jpkcom_postfilter_request_verdict()`, a pure function; filter `jpkcom_postfilter_request_verdict` can change it.

**The redirect decision is one comparison:** the request path against the path of `get_filter_url( normalize( filters ), paged )`, percent escapes compared case-insensitively. Differing only in the trailing slash is *not* redirected - a slash-less permalink structure would otherwise bounce forever. The invariant that keeps it loop-free: `parse_filter_path( path_of( get_filter_url( x ) ) ) === normalize_filters( x )` - tested.

**Which slugs exist** is answered by `jpkcom_postfilter_known_term_slugs()`: every slug of the taxonomy straight from the term tables, transient `slugs_{taxonomy}`, flushed with the other term caches. **Not** `jpkcom_postfilter_get_terms_for_taxonomy()` - that goes through `get_terms()`, which WPML narrows to the current language, under a transient keyed by taxonomy only; whichever language warmed it decided which slugs "existed", and the redirect would have stripped every other language's terms from their URLs. (The filter bar itself still renders from that per-taxonomy list - a pre-existing WPML caveat, not changed here.)

**`%2B` is a separator** (`jpkcom_postfilter_split_segment()`, used by validator and parser): link rewriters encode `+`, WordPress hands `%2B` through to the query var, and as part of one slug it made the selection unknown and redirected it to the archive. **Unless it is part of a real slug:** WordPress never creates one itself (`sanitize_title( 'C++' )` is `c`), but a slug typed or imported as `c%2b%2b` is kept verbatim. Pieces are joined back, longest first, wherever they form a known slug (`c%2b%2b%2Bzaeune` → `c%2b%2b`, `zaeune`). **Keep this linear.** The first version tried every span of every start with `sanitize_title()` + `in_array()` - cubic in the segment length of a path any anonymous client controls: measured live, 3 KB of `%2b` held a PHP worker for 103 s before the archive redirect, an amplification lever for exactly the bots this release is about. Now: only known slugs that contain `%2b` are join candidates (a set, `isset`), the join width is capped at the widest of them, and the validator rejects paths over 2000 characters before splitting (`length`). Guarded by a timing check in `tests/test-url-policy.php`.

`jpkcom_postfilter_known_term_slugs()` lives in `url-policy.php` and is the one source for "does this slug exist" - guard, robots decision and the Abilities API (`jpkcom_postfilter_ability_unknown_terms()`) all ask it. Its per-request memo is keyed by blog id and reset when term caches are flushed; the settings memo resets on `switch_blog`.

**CPT archives under a permalink front** (`/blog/%postname%/` with `with_front`): `jpkcom_postfilter_archive_base_regex()` now includes the front like core's archive rule and `get_post_type_archive_link()`. Before, the rule matched a path the plugin never linked, and the plugin's own links had no rule (404) - pre-existing, but the guard would have redirected from the working path to the broken one.

### Isolation between sites and languages (1.5.0, three pre-existing leaks)

Tests: `tests/test-isolation.php` (6 of 10 red against 1.4.7 without APCu; with `php -d apc.enable_cli=1` 8 of 12 - run it that way in a DDEV container, the host CLI has no APCu).

- **Settings cache per site.** On multisite every site writes and reads `JPKCOM_POSTFILTER_SETTINGS_DIR/site-{blog_id}/` (`jpkcom_postfilter_settings_dir()`); single sites keep the directory itself. Until 1.5.0 the network shared one directory, so the site that wrote last defined endpoint, groups and caps for all - with the request guard that means 301/410 for other sites' valid URLs. Measured on a two-site network: cap 1 on site 1, cap 3 on site 2, `alpha+beta` → 410 / 200 in either request order. The network-level group files are deleted on the version-change migration.
- **Term list per language.** `jpkcom_postfilter_get_terms_for_taxonomy()` caches under `terms_{tax}_{0|1}_{lang}` (WPML, Polylang, filter `jpkcom_postfilter_terms_cache_language`); without a multilingual plugin the key is unchanged. The term flush deletes every language variant (`jpkcom_postfilter_terms_cache_languages()`), hidden WPML languages included - `wpml_active_languages` omits them for anyone who may not see them (cron, editors, the front end), so they come from the `hidden_languages` setting. Until 1.5.0 the language that warmed the transient decided the filter bar of every language.
- **APCu keys per site and install.** `jpkcom_postfilter_apcu_key()` = `jpkpf_` + hash(ABSPATH, DB_NAME, table prefix, blog id) + key; the flush deletes only its own prefix. APCu is one segment per PHP-FPM pool; until 1.5.0 two sites (or two installs on one pool) running the same filter query read each other's cached `WP_Query` - measured: site 3 was served "posts of site 2". The WP object cache prefixes per blog by itself.

**Canonical order is byte order** (`sort( SORT_STRING )`), which JS's default `Array#sort` reproduces for slugs (ASCII, lower-case `%xx`). No DB needed, so nginx-level or early checks can verify it too.

### Robots, canonical, robots.txt

- `jpkcom_postfilter_robots_directives()` is the one decision: `noindex, nofollow` when the page has more terms than `seo_index_max_terms` (default 1), an unknown slug, or zero results on page 1. Applied via `wp_robots`, `rank_math/frontend/robots` (**native keys** `index`/`follow` - Rank Math drops its own canonical and prev/next only on `index === 'noindex'`; before 1.5.0 the plugin wrote a `noindex` key and Rank Math's canonical survived), `wpseo_robots_array`, and an `X-Robots-Tag` header on `template_redirect`.
- Canonical: `jpkcom_postfilter_canonical_url()` - `null` not a filter page, `''` none, else the target of `jpkcom_postfilter_canonical_decision()` (see **Canonical targets** below). Handed to Rank Math (`rank_math/frontend/canonical`; `''` prints nothing, `Head::canonical()` checks `Str::is_non_empty`) and Yoast (`wpseo_canonical`, `false`); the plugin prints its own tag only without either. At most one canonical, never next to a noindex. Rank Math's adjacent rel links are disabled on filter pages.
- `robots_txt` filter (priority 99, after Rank Math's editor at 10): `deep` = `Disallow: {base}/{endpoint}/*+`, plus `/*jpkpf-fragment` and `/*jpkcom_filter_` (no `?` - robots.txt is a prefix match and `?x` would miss the parameter in second position). The analysis also proposed `*/*/`; it is deliberately **not** emitted: it blocks `/{endpoint}/_/term/`, the second group's single-term pages, which `single` mode links and which are indexable - blocked-but-linked pages end up as "Indexed, though blocked by robots.txt". Two-segment combinations are not linked in `single` mode and are noindex anyway. With WPML the rule is written for every active language (`/filter/*+` and `/en/filter/*+` in the one root robots.txt crawlers read): `jpkcom_postfilter_archive_base_urls_all_languages()`. Measured on WPML 4.8 directory mode: `wpml_permalink` resolves pages (a posts page, translated slug included) but hands back the bare home URL and post type archive URLs unchanged, and `home_url()` does not change after `wpml_switch_language`; for those `$sitepress->language_url( $code )` replaces the home prefix - **without switching the language**: the `wpml_switch_language` action always passes the cookie flag, so switching overwrote a logged-in visitor's language cookie on a robots.txt request (and switching back with a code instead of `null` left `icl_language_switched` set). Only the front-page base was verified live. Other multilingual plugins: filter `jpkcom_postfilter_robots_txt_base_urls`. One `User-agent: *` group: **no crawler is singled out or locked out.** A physical robots.txt wins; the General page prints the lines for copying.

### Canonical targets (since 1.7.0)

Files: `includes/canonical-target.php` (no hooks), the decision in `includes/crawl-guard.php`. Tests: `tests/test-canonical.php` (red against 1.6.1); `tests/test-url-policy.php` and `tests/test-security.php` pin both settings to `filter`, which keeps the 1.5.0 rules they test. Lab protocol (local): `/home/jpk/tmp/post-filter-170-lab/PROTOCOL.md`.

`jpkcom_postfilter_canonical_decision()` → `{kind, url}`, kind `archive` | `home` | `self` | `none`:

- bot page (unknown slug, no results on page 1, `jpkcom_postfilter_is_bot_filter_page()`): `none`, whatever the settings;
- one term, `canonical_single = archive`: `jpkcom_postfilter_term_archive_url()`, else as `filter`;
- two or more terms, `canonical_multi = home`: `jpkcom_postfilter_base_canonical_url()`, else as `filter`;
- `filter`: `self` (page 1) within `seo_index_max_terms`, else `none`; `off`: `none`.
- page 2 and later (1.7.1): `self` with the page within `seo_index_max_terms`, else `none` (`off` stays `none`) - the duplicate of the archive is page 1, and Google asks for self-canonical paginated pages. A combination's page 2 is therefore noindex again: a self canonical lifts no noindex.

Archive and base targets are page 1. The target then passes `jpkcom_postfilter_canonical` **inside** the decision (`jpkcom_postfilter_canonical_filtered()`, 1.7.1): an empty value makes it `none`, so the term-count noindex stays; a re-entry guard hands a callback that asks for the canonical itself the unfiltered target. The decision never reads the robots; `jpkcom_postfilter_robots_directives()` reads the decision: for `archive`/`home` the term-count noindex is dropped (`index`, `follow` stays with the term count - a combination is `index, nofollow`), because noindex next to a canonical pointing elsewhere is a contradiction search engines may resolve by carrying the noindex over to the target. A final `index = false` (also through the robots filter) always drops the canonical. Since a combination can be `index, nofollow`, pagination asks `jpkcom_postfilter_should_nofollow()`, not `should_noindex()`.

**Usable target** (`term_archive_url()`): taxonomy `public` and `publicly_queryable`, `get_term_link()` a string, the archive lists the post type (`jpkcom_postfilter_term_archive_lists_post_type()` mirrors `WP_Query::get_posts()`: only a custom taxonomy sets `is_tax` and gets every `exclude_from_search = false` post type that has it; `category`/`post_tag` archives list `post` only - measured: a CPT on `category` does not appear on `/category/x/`), `blog_public`, no manual canonical elsewhere on the target (`jpkcom_postfilter_seo_canonical_moved()`: Rank Math `rank_math_canonical_url` term/post meta, Yoast `wpseo_canonical` taxonomy meta / `_yoast_wpseo_canonical` - otherwise our canonical would be a chain), for `post` without a posts page only when `show_on_front = posts` (a static front page is no listing), and no noindex from an **active** Rank Math / Yoast (`jpkcom_postfilter_seo_term_noindex()` / `_seo_base_noindex()`, read like Rank Math 1.0.280 `Paper\Taxonomy|Singular|Blog|Archive` and Yoast 28.x builders: Rank Math term/post meta `rank_math_robots`, else `titles.{tax_x|pt_page|homepage|pt_x}_custom_robots` + `_robots`, else `titles.robots_global`, plus `noindex_empty_taxonomies`; Yoast `wpseo_taxonomy_meta[tax][id][wpseo_noindex]` / `noindex-tax-x`, `_yoast_wpseo_meta-robots-noindex` 1/2 / `noindex-page`, `noindex-ptarchive-x`). **Rank Math tags are noindex out of the box**, so tag filter pages there fall back to `self`. Last word: `jpkcom_postfilter_canonical_archive_indexable` / `_base_indexable`. Memo per request (`jpkcom_postfilter_canonical_target_memo_reset()`).

**Robots ownership (1.7.1).** The Rank Math and Yoast robots callbacks set `index`/`follow` from `jpkcom_postfilter_robots_directives()` on **every** filter page, not only on noindex ones: both plugins treat a filter page as the posts page and carried that page's noindex over (F1 in the 1.7.0 lab). Rank Math applies `rank_math/frontend/robots` after its own `blog_public` and subpage noindex (`Paper::get_robots()`), so those two must be decided here: `blog_public = 0` → `noindex, nofollow`; `jpkcom_postfilter_seo_paged_noindex()` → `index = false` from page 2 (`noindex_archive_subpages` everywhere, `noindex_paginated_pages` only where Rank Math uses Paper\Singular or Paper\Blog - a `post` filter page with a posts page or with the blog on the front page, never a post type archive). Yoast's adjacent links are off on filter pages (`wpseo_disable_adjacent_rel_links`), like Rank Math's. Rank Math's taxonomy and page robots combine an empty custom list with index (`robots_combine( …, true )`), its archive and homepage robots do not; an empty term with children is not noindex (`noindex_term()`), asked with Rank Math's exact `get_terms()` arguments. The base target is the URL the page prints (`get_permalink()` / `home_url('/')` / `get_post_type_archive_link()`), never `trailingslashit()`ed. The canonical settings are no server-rule input (tested in `tests/test-server-rules.php`).

### Term links

`jpkcom_postfilter_term_link_attributes()` → `<a href>` or `<button type="button">` (all four layouts and the debug copies). Mode `single` (default): an inactive term links to its single-term page; an active term to the selection without it while at most one term remains; otherwise a button. Crawlable graph: archive + one page per term. Mode `combo`: the old toggle links, but never beyond the caps / `JPKCOM_POSTFILTER_LINK_MAX_TERMS`, and on a zero-result page not into a group without selection. Links to non-indexable targets get `rel="nofollow"`. The reset link lost its `rel="nofollow"` - it is the one way *out* of the combinations. **JS users are unaffected in every mode** - the script handles clicks from data attributes and `aria-pressed`. Theme overrides of the filter partials keep the old links until they adopt the helper.

Pagination: page 1 links to the page itself (`base` with `%_%`, `format` `page/%#%/`); before 1.5.0 it linked `…/page/1/` from every paginated list. Page links on non-indexable pages are `nofollow`.

### What it deliberately does not do

- **Page cache exclusion** is opt-in (`JPKCOM_POSTFILTER_PAGECACHE_MAX_TERMS`). The analysis proposed `DONOTCACHEPAGE` above 1 term by default; that was not adopted: once the URL space is finite and canonical, caching deep pages is what absorbs the alias redirects (k! spellings of a selection all 301 onto one cached page). Excluding them made each of those a full render again.
- **Server-level limits** are not enforced by plugin code - the plugin cannot spare the WordPress bootstrap, a web server can. Since 1.6.0 the **Server Rules** page generates them (reject before PHP, robots.txt to WordPress, rate limit) for seven server setups; the admin deploys them. The only server configuration the plugin ever writes itself is the managed `.htaccess` block on Apache, and only after the admin clicks *Set up*. Page caches are a text hint on that page, never a generated config. See **Server rules (since 1.6.0)**.
- **Updater DNS lookup per request** (`wp_http_validate_url()` in the updater constructor) is family code - fix upstream for all 19 plugins at once, never here alone.

---

## Server rules (since 1.6.0)

The 1.5.0 guard rejects over-cap filter URLs, but only after the WordPress bootstrap - measured on the production site that prompted 1.5.0, ~22 000 rejects an hour still went through a full bootstrap, about 70 % of the remaining PHP load. A web server can turn the same URLs away for almost nothing. **Post Filter → Server Rules** generates the rules from the effective settings, for nginx (plain server block or ISPConfig's "nginx Directives"), Apache server configuration, Apache `.htaccess`, Caddy/FrankenPHP, Ferron 3, Ferron 2.8 and OpenLiteSpeed: reject before PHP, robots.txt to WordPress, a rate limit for cookie-less filter requests, and the page-cache hint as text.

**The hard rule: no generated rule may reject a URL the plugin answers with 200 or 301.** Looser is always allowed - PHP keeps the last word. Every design decision below follows from it.

Files: `includes/server-rules.php` (inputs, hash, patterns - pure, no hooks), `includes/server-rules-render.php` (rate-limit formula, reject body, one snippet function per server, curl commands - pure), `includes/htaccess-manager.php` (file operations on the managed block - pure, the path is a parameter), `includes/htaccess-sync.php` (hooks that keep the block in step), `includes/server-rules-check.php` (the status check and the notice), `includes/server-rules-admin.php` (the page). Options: `jpkcom_postfilter_server_rules` (workers, render time, nginx variant; never autoloaded), `jpkcom_postfilter_htaccess_managed` (the `.htaccess` path resolved at *Set up*, `'1'` from before that path was stored, or absent; not autoloaded), `jpkcom_postfilter_server_rules_check` (last check result and time).

### Raw per-group count (behaviour change)

Since 1.6.0 `jpkcom_postfilter_validate_filter_path()` checks `max_filters_per_group` against the number of **non-empty raw pieces** per taxonomy - after splitting at `+`/`%2b` and re-joining known `%2b` slugs, **before** `sanitize_title()` and before de-duplication. Above N: `per-group` reject. At or below N the old path stays (normalise, 301 duplicates and other spellings to the canonical URL). Pieces of one taxonomy add up across segments (two groups on the same taxonomy). `max_filter_combos`, `max_terms_total`, the segment count and the length limits are unchanged; with strict URLs off the parser still truncates by the normalised count.

**Consequence for running sites:** hand-built URLs with more than N pieces in a group - duplicates (`a+a+b` at N = 2), case variants, pieces that sanitise to nothing (`-`) - now get **410 instead of 301**. The plugin itself (PHP links, JS, Abilities) never builds such URLs.

**Why:** a server can only count what is in the URL. With the raw count, "a segment with N+1 non-empty `+`-separated pieces in a `%`-free path" is **exactly** what the plugin rejects - no backreferences, no lookahead, and independent of `sanitize_title()` and of anything that filters it. That is what makes one grammar work in PCRE, RE2 and Rust's `regex`, in linear time.

### Inputs and hash

`jpkcom_postfilter_server_rule_inputs()` reads the **effective** values (constant → stored → default → `jpkcom_postfilter_{key}` filter): `strict`, `max_per_group` (N), `groups` (G, every enabled group site-wide), `skip_positions` (groups without a taxonomy), `reject_status`, `endpoint`, `fragment`, `bases`, plus `verdict_filtered` (`has_filter( 'jpkcom_postfilter_request_verdict' )`) and `policy_filtered`. **Both only see what the admin request registered:** a callback added only on the front end (behind `is_admin()`, in a template, on a request check) is invisible to the Server Rules page, the status check and the `.htaccess` sync, and the rules may then be stricter than what the plugin does on the front end. The page always says so (`frontend-filters`); there is no way to detect it from the admin side. Bases (`jpkcom_postfilter_server_rule_bases()`): every routable post type that gets rewrite rules, in every language `jpkcom_postfilter_archive_base_urls_all_languages()` knows, as paths including the home path; a base containing `%` is left out.

`jpkcom_postfilter_server_rule_hash()` = first 12 hex characters of sha256 over the values that shape the rule (sets sorted; `policy_filtered` and the hash itself excluded). The server sends it as `X-Jpkpf-Rule`; the status check and the `.htaccess` sync compare it. Two values are appended only when they are not empty, so every other hash is byte-identical to 1.6.0 (pinned in `tests/test-server-rules.php` over 592 layouts): `dropped`, the patterns the size guard leaves out (R161-4, 1.7.1 - 1.6.0 wrote them, so a deployed 1.6.0 rule of such a layout is now "stale" and a managed block is rewritten without them), and `renderer`, `jpkcom_postfilter_server_renderer_version()` (T9-c, `''` today). **The next release that changes what a renderer writes for the same inputs must set the renderer version** (`'2'`, then `'3'`), or deployed rules and managed blocks are not recognised as stale - and setting it marks every deployed hand-pasted rule stale.

**Caveat - bases from `jpkcom_postfilter_robots_txt_base_urls`.** That filter (for multilingual plugins other than WPML) feeds `archive_base_urls_all_languages()`, so since 1.6.0 the bases it returns also get **server rejects**, not only robots.txt lines. The argument "a base the plugin does not route only covers a path WordPress would 404 anyway" holds for bases the plugin derives itself; it is unproven for a filter-supplied base. If such a base is a path where some other code serves `{base}/{endpoint}/a+b+c/`, the server would reject it. A filter callback must return only bases the plugin actually routes.

### Grammar (`jpkcom_postfilter_server_reject_patterns()`)

Two patterns, matched on the **raw** request URI and only on a path **without `%`**: WordPress tries every rewrite rule on the raw path and again on its `urldecode()`d form, so `%70age`, `jpkpf%2Dfragment` or `%2F` split a path differently from its bytes. Leaving every `%` path to PHP removes that whole class. Matched case-sensitively; only literals, negated classes, non-capturing groups, quantifiers, alternation and anchors - no lookaround, no backreferences.

- **per_group** (`server-per-group`): a segment below `{base}{endpoint}/` with N+1 or more non-empty pieces separated by a literal `+`. Page and fragment suffixes never contain `+`, so they never count. Positions without a taxonomy are skipped position-exactly; past G every segment is a `segments` reject anyway.
- **segments** (`server-segments`): segment G+1 contains a `+`, or there are G+4 segments (the capture is the path after the endpoint minus at most a three-segment `page/N` / fragment suffix, so of G+4 segments at least G+1 are captured).

Both null when strict URLs are off, the verdict is filtered, there are no groups, bases or endpoint, or one base lies below another base's endpoint (positions would shift). `per_group` is also null for N = 0 - **but `segments` stays** (ruling R7; the design spec's "N = 0 → no reject part" is superseded): segments alone is still never stricter. The guard checks `segments` before `per-group`, and so do the snippets, so a URL over both gets the same reason from the server as from PHP.

**The linear form is load-bearing** (ruling R5). The literal grammar `(?:any segment)*` retried the tail scan from every split point: 12.6 ms for a 2000-character path without a JIT, against a budget of 1 ms. The built form skips exactly the segments within the cap, so only the first over-cap segment is a candidate. Renderers must keep these constructs. Two more properties, from the 1.6.0 attack round:

- **Every segment repetition is bounded** (`{0,G+3}`, attack A4). nginx calls `pcre2_match()` without a match context, so with `pcre_jit on` the JIT has PCRE2's default 32 KiB stack, and the unbounded `(?:segment)*` used stack per segment: `/blog/filter/` + `+/` × 820 + `%41` (1.65 KB) returned -46 - HTTP 500 and an alert log line per request instead of the reject. Bounded, the stack need does not grow with the URI. Past G+3 segments `segments` rejects anyway; where a `%` prevents that, the bounded `per_group` simply does not match (looser). `tests/test-server-rules.php` runs the shapes 6× as long under PHP's JIT (192 KiB stack, same engine) for G 1-8.
- **Positions without taxonomy are passed per run, not per position** (attack A5). The first form had one alternative `(?:segment){p}` per position, each followed by its own tail scan, so a `%` at the end of a 2000-character URI was scanned G times: 1 ms at G = 12, 3 ms at G = 30 without JIT. Now the pattern is built from the end over the runs of positions with a taxonomy (`(?:(?:w){k}<rest>|(?:w){0,k-1})`, a position without taxonomy is one `[^/?%]+/+`), so every segment has exactly one way through and the tail is scanned once. Worst case at 2000 characters, G up to 30: 0.25 ms without JIT, 0.03 ms with; Go's RE2 (Caddy) 0.75 ms (was 3 ms).

`subject: 'request_line'` builds the same grammar for `%{THE_REQUEST}` (Apache, OpenLiteSpeed): `^[A-Z]+ … (?:\s|$)`, and **every negated class also excludes whitespace** (ruling R1). `THE_REQUEST` ends in ` HTTP/1.1`, which contains a `/`; without `\s` in the classes, `…/page/2/jpkpf-fragment HTTP/` counted as extra segments and the plugin's own fragment requests were rejected. PCRE-only (`\s`, `\S`).

Bases are filtered once more for the snippets (`jpkcom_postfilter_server_safe_bases()`: RFC 3986 unreserved plus `! * + , = : @`); a base with a quote, space, brace, `$`, `;` and the like is left out of every snippet and named on the page (looser). `jpkcom_postfilter_server_render_patterns()` keeps a pattern null whenever it is null over all bases, so dropping a base cannot lift the nested-base check.

**Not on the server** (looser, allowed): length limits (2000 / 200), `max_filter_combos`, `max_terms_total`, unknown slugs, upper case, `%`-encoded paths. **Known limit:** the server decides by URL alone - if another plugin's own `top` rewrite rule serves a filter-shaped URL before this plugin, the server rejects it anyway. The page says so.

**Size and compile guard (1.6.1).** The bounded `per_group` form grows with G and N, and a server that cannot load a rule fails hard: measured, PCRE (link size 2) stops compiling it at G >= 71 (N = 20) or G >= 37 with the last position skipped - `nginx -t` fails with "pcre2_compile() failed: regular expression is too large", an `.htaccess` with it answers 500 for the whole tree; with alternating skips the THE_REQUEST form passes 8000 characters at G >= 40; nginx reads a quoted parameter of at most **4094** characters (4095: "too long parameter", NGX_CONF_BUFFER 4096); Apache 2.4 answers 500 once an `.htaccess` line reaches **8192** bytes (8191 loads; the server config has no such limit); Go's regexp (Caddy) refuses counts over 1000 and nested count products over 1000 (`repeatIsValid`); Rust's regex (Ferron 3 rc.10) refuses nesting deeper than **250** and Ferron then **silently never matches** (no error, no log line): with the unchecked N1 G160 alternating pattern (depth 250) `/blog/filter/a+b/` got 410, at G162 (depth 253) 404. `jpkcom_postfilter_server_reject_patterns()` is therefore a guard around `jpkcom_postfilter_server_reject_patterns_unchecked()` (the builder): a pattern is null when either form is longer than **4000** characters, either form does not compile in PHP's PCRE2, or the URI form breaks Go's repetition rule or nests deeper than 200 by Rust's count (`jpkcom_postfilter_server_regex_shape()`, calibrated against Ferron and Go; 50 of margin). Limits in `jpkcom_postfilter_server_pattern_limits()`. 4000 because nginx is the tightest reader (the URI form in one quoted parameter) - Apache's 8191-byte line keeps half its room for `RewriteCond %{THE_REQUEST} "…"`. The decision covers both forms and all servers at once (`jpkcom_postfilter_server_patterns_dropped()`, reason per pattern: `length`, `pcre`, `repeat`, `nesting`, and since 1.7.1 `with-segments` for a `per_group` whose own pattern would load), so snippets, curl commands, status check and the managed `.htaccess` block agree; `per_group` is dropped whenever `segments` is (same reason order as the guard). The warning names only the settings that move the dropped rules (segments: filter groups; per_group: also positions without taxonomy and terms per group; `length`: also post types/languages, since every base is in both patterns). **Rust's compiled `size_limit` (1.7.1, measured):** the ten largest layouts the guard lets through (no skips N1 G776 … N20 G70 with repetition counts up to 776; alternating skips up to 3541 characters and depth 199) all reject in Ferron 3.0.0-rc.10 and rc.9 - no extra reason needed. PCRE replicates bounded repetitions, so `pcre` and `length` normally fire first; `nesting` is the only reason under 4000 characters that PCRE accepts (N = 1, ~160 groups, alternating skips). Dropping is looser, never stricter; the page shows the `patterns-dropped` warning. Layouts up to G 8 / N 5 (580 in the test, 680 incl. N = 0 in the release check) are byte-identical to 1.6.0, so the rule hash needed no renderer version. Tests: section "size and compile guard (1.6.1)".

**Proof:** `tests/test-server-rules.php` fuzzes both forms differentially against the real `jpkcom_postfilter_validate_filter_path()` behind an emulation of WordPress's rewrite matching (raw, then `urldecode()`, in registration order) over 180 layouts (N 1-5, G 1-3, positions without taxonomy at the start, in the middle, at the end and around one, nested and root bases) with a noise pool of `%70age`, `pag%65`, `jpkpf%2Dfragment`, `%2F`, `%2B`/`%2b`, `%2b` slugs, upper case, `--`, `.`, empty pieces, `//`, `_`, page/fragment suffixes, query strings and over-length. Expected: **0 cases "server rejects, plugin does not"**. Default 9000 cases per layout (CI ~25 s); `JPKPF_FUZZ=400000` for a deep run, `JPKPF_WPCORE=/path/to/wordpress` for the real `formatting.php` instead of the stub. Also: the production bot shapes (4/3, 3/4, 4/2, 2/4, 3/3 at N = 2, canonical, with `page/2`, fragment, `?utm`) are caught 100 %, and a 2000-character worst case runs under 1 ms (up to G = 30 with positions without taxonomy), with and without JIT, and no shape exhausts the JIT stack.

### The server's answer

Status = `reject_status` (410 or 404), `X-Robots-Tag: noindex, nofollow`, `X-Jpkpf-Reject: server-<reason>`, `X-Jpkpf-Rule: <hash>`, `Cache-Control: no-store`.

- `X-Jpkpf-Reject` is mandatory: without it the front-end script treats a rejected fragment as damaged and reloads the page.
- `no-store`: a cached reject would outlive a later loosening and be stricter than the plugin.
- Body (`jpkcom_postfilter_server_reject_body()`): one line of HTML in the site language, `noindex`, a link to the first base. **Pure ASCII** - every non-ASCII character is a numeric entity, because Apache sends a string `ErrorDocument` as `text/html; charset=iso-8859-1` and that header beats the meta tag ("verfÃ¼gbar"). Also free of `{ } $ \ % '`, so a translation cannot break nginx `return '…'`, ISPConfig's brace counter, Apache expressions or the Caddy/Ferron strings.
- The rate limit answers **429** with `Retry-After: 10` and **without** `X-Robots-Tag` (a busy answer for a valid URL must not tell search engines to drop it). 429, not 503: ISPConfig's "Own Error-Documents" replace a 503 together with its headers, and the script treats both alike.

### Rate limit

One formula for every server (`jpkcom_postfilter_rate_limits()`), from two inputs on the page: W = PHP workers (default 8, 1-256) and t = render time of a filter page (default 0.4 s, 0.05-10).

```
conn          = max(1, floor(3W/8))         cookie-less filter pages rendering at once
rate          = max(1, round(0.4 conn / t)) site-wide req/s (those workers ~40 % busy)
burst         = 2 rate
ip_per_minute = 30, ip_burst = 10           per visitor IP
bot_rate      = max(1, round(2 rate / 3)), bot_burst = 20   search engines' own bucket
retry_after   = 10 s
```

Anchor (8 workers, 0.4 s): conn 3, 3 r/s burst 6, 30 r/min burst 10 per IP, search engines 2 r/s burst 20. **Any `Cookie` header exempts** a request on every server - logged-in users and returning visitors are never slowed down; a bot can send a cookie too, and a first-time visitor has none, so this is a brake for anonymous floods, not access control. Googlebot, Bingbot, Applebot, DuckDuckBot and YandexBot get their own bucket where the server can tell them apart (nginx, Caddy, ModSecurity); a 429 for Google throttles its crawl of the whole site.

### Server matrix (as built and container-tested)

Every snippet was run as generated, in containers (nginx standard + ISPConfig, Apache prefork/event with `.htaccess`, VirtualHost, mod_qos, ModSecurity ± OWASP CRS, OpenLiteSpeed, Caddy, Ferron 3.0.0-rc.10, Ferron 2.8.1). Harnesses and protocol stay local (`/home/jpk/tmp/post-filter-160-lab/`), not in this repo.

| Server | Reject before PHP | Rate limit | What is impossible / caveats |
|---|---|---|---|
| **nginx** | Server-level `if ($request_uri ~ …)` → `rewrite ^ /jpkpf-edge-<reason>? last;` → `location = /jpkpf-edge-<reason> { internal; add_header …; return <status> '<body>'; }` (`add_header` is not allowed in a server-level `if`). Rejected requests never reach PHP or the rate limit. | Two parts: an http-context file (root: `map`s, `limit_req_zone` for IP/site/bots, `limit_conn_zone`) and a per-base `location` with `limit_req`/`limit_conn`, `error_page 429 = @jpkpf_busy`. Dry-run switches as comments. | `nginx-light` has no `limit_req`. The rate-limit location takes precedence over page-cache rules in `location /`. |
| **nginx / ISPConfig** | Same, pasted into "nginx Directives". | Same, `location` in the field, zones still need root. | ISPConfig strips lines starting with `#`, replaces a `location` it already has (so no `location /`), counts braces per line; robots.txt goes through its own `location = /robots.txt { ##merge##` - pasting it twice breaks `nginx -t` ("try_files directive is duplicate"). |
| **Apache (server config)** | `RewriteCond %{ENV:REDIRECT_STATUS} ^$` + `RewriteCond %{THE_REQUEST} "…"` + `RewriteRule ^/ - [E=JPKPF_REJECT:server-<reason>,R=<status>,L]`; `Header always set … env=` twice (also `REDIRECT_JPKPF_REJECT` - an internal redirect renames the variable); string `ErrorDocument`. `RewriteRule` patterns, `REQUEST_URI` and `RedirectMatch` see the decoded path, `THE_REQUEST` does not. | **mod_qos** `QS_EventRequestLimit` as the concurrency cap (never `QS_ClientEventLimitCount`: it bans the whole IP for every URL) - search engines **share** it (ruling R10; a User-Agent exemption would let anyone past the only cap). **ModSecurity 2** for per-IP, site-wide and bot limits, everything inside a `<LocationMatch>` over the filter prefixes including `SecRuleEngine On` (ruling R9: no vhost-wide engine switch, CRS is not flipped to blocking for the whole site), phase 2, fixed windows. Counters in **RESOURCE** (`jpkpf_ip_<addr>`) and **USER** (`jpkpf_site_<server name>`) - not IP/GLOBAL, which CRS 3.3 opens first under other keys (measured: a new User-Agent per request walked past the per-IP limit, and one vhost's site counter answered 429 on another). | Without a global `SecRuleEngine` (Debian's default without `modsecurity.conf`) CRS runs uninitialised inside the LocationMatch: blocks nothing there, logs 911100 per filter request; the context text says so. The site counter is keyed on `SERVER_NAME`, which follows the Host header - catch-all vhosts need `UseCanonicalName On`. mod_evasive is not recommended. Other rule sets could in theory use RESOURCE/USER too. |
| **Apache (.htaccess)** | Same rules in per-directory form (`RewriteRule ^`, `<IfModule>`), **before** `# BEGIN WordPress` - after WordPress's block it never takes effect (its `RewriteRule . /index.php [L]` already sent the URL to PHP). Needs `AllowOverride FileInfo`. | Not possible in `.htaccess` (mod_qos and ModSecurity are server-level); the tab says so. | The copy snippet carries **manual** markers; the managed block is described below. |
| **Caddy / FrankenPHP** | One `route` before `php_fastcgi`: `@jpkpf_<reason> expression {http.request.orig_uri}.matches("…")` (CEL string inside a backtick token, so every `\` is doubled), `respond <status>` with headers. **`orig_uri`, not `uri`** (ruling R13): the Caddyfile sorts `rewrite`/`try_files` before `route`, so a site-level `try_files` would otherwise have rewritten the URI to `/index.php` first and silently disabled the rule. | `caddy-ratelimit` (xcaddy build) **inside the same `route`, after the rejects** - outside it the module sorts itself before `respond` and rejected requests use up the visitors' budget. Zones per IP, site-wide (`key static`) and bots; scope on `{http.request.orig_uri.path}` with `^/+` (WordPress trims leading slashes, so `//blog/filter/x/` must count). `handle_errors 429` needs Caddy 2.8+. | Without the module Caddy refuses the whole file - delete the `rate_limit`/`handle_errors` blocks, the reject still works. A site that wraps `php_fastcgi` in `handle` needs the route inside that `handle` (handle sorts before route and ends the request), `handle_errors` outside it. Root base renders as `(?:|news/)`, which RE2 accepts. |
| **Ferron 3** (3.0.0-rc.10) | Top-level `match` blocks on `request.uri` (raw; `request.uri.path` is decoded) in raw strings `r"…"`, host-level `if` blocks with `status` + headers; the per-group matcher excludes the segments pattern so a URL over both gets one answer. | `rate_limit` per IP only (token bucket, 1 r/s minimum - the plugin's 30/min cannot be expressed). **No site-wide rate and no bot bucket** (no constant key); instead a FastCGI **bulkhead**: a second `fcgi_php` with `limit` connections and a URL that must differ from the main one (`/jpkpf` suffix; with a Unix socket `/./` in the path, because a suffix becomes part of the socket path → 500). Further requests wait, no 429. | Relies on Ferron keying the pool by the literal URL string - read in the rc.10 source, undocumented; re-check against 3.0.0 final. Ferron's 429 has no `Cache-Control` (a 429 is not cacheable by default, RFC 9111). Root base needs Rust `regex` ≥ 1.8. |
| **Ferron 2.8** | **None** (ruling R14). Ferron 2 decodes safe escapes and merges slashes before any module and hands PHP that same decoded URI, so WordPress already counts what a server rule would see; a reject on `{path}` would not be stricter, but none was built or tested. | `limit rate= burst=` per IP; a missing header is `""` in conditions (`is_equal "{header:Cookie}" ""`). | No site-wide cap, no bot bucket, 429 without `Retry-After`. No reject probes in the curl commands. |
| **OpenLiteSpeed** | Virtual Host rewrite (WebAdmin → Rewrite) on `THE_REQUEST`, **status only, no headers** - neither rewrite nor `.htaccess` can set one, and `extraHeaders` ignores its `env=` condition (it would put `noindex` on every page). Graceful restart. | None in released versions (only server-wide Per Client Throttling). | The status check cannot recognise the rule; a rejected fragment reloads the page. `.htaccess` changes need a restart, so the plugin never manages it there. |

robots.txt: every tab says how `/robots.txt` reaches WordPress (`location /`, WordPress's `.htaccess`, `php_fastcgi`, the front-controller rewrite) and that a physical file in the web root always wins. Every tab also carries the **order rule** - *tightening: plugin first and purge the page cache, then the server; loosening: server first, then the plugin* - curl commands with synthetic slugs (`jpkpf-probe-1+…`) judged by the **value** of `X-Jpkpf-Reject` (PHP answers the same URLs with its own 410, so the status proves nothing), and the page-cache hint. The rate-limit part (1.6.1) is one self-contained POSIX-sh loop per server that prints the 25 status codes and then `first 429: <its Retry-After header>` (`none`, or `no Retry-After header` - Ferron 2 sends none): the 1.6.0 commands printed a separate request after the loop and expected 429 plus `Retry-After` there, but the site-wide bucket refills in between (lab, nginx at 3 r/s: 200). Cookie loops and the mod_qos parallel run stay as they were; `tests/test-server-rules.php` runs each printed loop under `/bin/sh` with a stand-in `curl`. Every printed `for` loop runs in a subshell `( … )` (1.7.1), so pasting it leaves no `first`, `h`, `c`, `i` or `u` in the admin's shell (tested in a parent `sh`). Every pasted block starts with `# >>> jpkcom-post-filter <version> rule <hash>` (`//` in Ferron 2's KDL; the ISPConfig variant has no comment lines at all - ISPConfig strips them - and carries the hash only in its `X-Jpkpf-Rule` line).

### Managed `.htaccess` block (Apache)

**Available only when** `SERVER_SOFTWARE` names Apache and not LiteSpeed (WordPress's `$is_apache` is true for LiteSpeed too), no multisite, no `DISALLOW_FILE_MODS`, the site's `.htaccess` exists and is writable, and no manual block is present (`jpkcom_postfilter_htaccess_available()` returns the first failing reason; the tab shows it and offers copying only). *Set up* / *Remove* check `manage_options` and a nonce.

- **Markers:** managed `# BEGIN JPKCom Post Filter` / `# END JPKCom Post Filter` with a `# rule <hash>` line; the copy snippet carries `(manual)` markers. The plugin touches only the managed block, never a manual one, and *Set up* refuses while a manual block exists (rules twice). Markers are compared as whole lines, so the managed marker (a prefix of the manual one) never matches the manual line.
- **Placement, not `insert_with_markers()`** (ruling R16): that function appends a new pair at the end of the file - after WordPress's block, where a reject never takes effect - and leaves marker lines behind when removing. `htaccess-manager.php` writes the block itself, directly before `# BEGIN WordPress` (or at the top, after a BOM, when there is none), under `flock(LOCK_EX)`, in place (`r+` - never creates the file -, `ftruncate`) so owner, group and mode survive; a shrinking write goes out padded with `#` lines to the old length in one `fwrite()` before the cut, so a concurrent Apache request never parses a remnant of the old content (`jpkcom_postfilter_htaccess_padded()`); line endings, BOM and a missing final newline are kept, every byte outside the block stays. A managed block found **below** WordPress's is moved before it (ruling R15). Managed markers that do not pair up (`damaged`) stop every write and remove until fixed by hand - and so does a managed block that contains a `# BEGIN WordPress` or `# END WordPress` line (it spans or overlaps WordPress's block, e.g. after an admin added a missing END at the bottom): splicing it out would take WordPress's rewrite rules, or its END marker and `</IfModule>`, with it.
- **Which file:** `jpkcom_postfilter_htaccess_file()`. *Set up* stores the resolved absolute path in `jpkcom_postfilter_htaccess_managed` (the value `'1'` written before that is still read as "managed"), and every later sync, refusal check, deactivation and WP-CLI run uses that file while it exists - but only when its directory is one of the site's own (`ABSPATH`, its parent, or the derived home path): a tampered option must not point the writes at another `.htaccess` on the host, and a subdirectory such as `wp-content/uploads` is never the right file. Otherwise `jpkcom_postfilter_htaccess_home_file()`: `get_home_path()` with `SCRIPT_FILENAME` set to `ABSPATH` for the call, as WP-CLI's rewrite command does - with home ≠ siteurl core derives the path from `SCRIPT_FILENAME`, which is the `wp` binary under WP-CLI and the root `index.php` on a front-end request, and the result was `/.htaccess`. A result of `/` falls back to `ABSPATH`; never the file system root.
- **Lifecycle:** *Set up* writes the block and stores the file's path in `jpkcom_postfilter_htaccess_managed` (also when the settings currently produce no reject part - the block follows once they do). *Remove* and deactivation delete the block, and the option only once no managed block is left (a block that could not be removed stays guarded). Activation and version change: managed → sync; block present but option gone (plugin deleted and reinstalled) → removed. Nothing is ever set up automatically.

**Sync - never stricter, not even for one request** (`includes/htaccess-sync.php`):

- A save of General or Filter Groups first brings the block to the **union** of the current and the submitted state (`jpkcom_postfilter_server_rule_union()`: strict only if both are; N = 0 if either is 0, else the larger; the larger G; every position either leaves without taxonomy; a different endpoint, fragment or base set leaves no reject part), lets WordPress commit, then writes the new state (which tightens where the save tightened). Import goes through the same path (`jpkcom_postfilter_htaccess_prepare_save()`).
- **The sanitizer trap:** `update_option()` runs `sanitize_option()` first, and the plugin's settings sanitizer (priority 10) **writes the settings cache file** - which the plugin reads before the database, so from that moment it runs with the new values. `pre_update_option_*` is too late. The loosening therefore runs on `sanitize_option_*` at **priority 5**.
- **Loosening needs no permission:** writing a looser block, like removing one, is always safe, so the loosen-write is attempted whenever the file holds a managed block and is writable - whatever `jpkcom_postfilter_htaccess_available()` says and whatever `SERVER_SOFTWARE` names. That is what makes a `wp option update/patch` under WP-CLI (no `SERVER_SOFTWARE` at all) loosen the block before the commit, exactly like a save in wp-admin.
- **Refusal:** when the block cannot be loosened (not writable, damaged markers, a manual block next to it, write failed) the save is refused with an error that names the remedy for that reason (and a `WP_CLI::warning()` under WP-CLI): the filter hands on the stored value, a last filter on `PHP_INT_MAX` hands on exactly what `get_option()` returns and puts the cache file back, so `update_option()` sees no change. Option, cache file and `.htaccess` stay on the old state (tested). A block whose `# rule` hash matches the union with only `reject_status` differing is not stricter and refuses nothing (ruling R20). **Skipped only where the server positively does not read `.htaccess`** (ruling R22, replacing R17's "unknown server"): `SERVER_SOFTWARE` names nginx, Caddy, FrankenPHP or Ferron - a leftover block is inert there, so the save proceeds and the block waits for *Remove* or deactivation. Empty or unknown (WP-CLI, cron, IIS, anything else) counts as reading it. The migration pin is never refused (`jpkcom_postfilter_htaccess_without_refusal()`).
- `page_for_posts` and `permalink_structure` (incl. `permalink_structure_changed`, which fires after `WP_Rewrite::init()`) sync after their commit.
- **Constants and filters change effective values without any option event**, so `admin_init` compares the hash in the file with the current one and syncs - on normal admin page loads only, not on admin-ajax/heartbeat, REST or cron (ruling R18), and only for a user with `manage_options` (core fires `admin_init` on `admin-post.php` before its login check, so an anonymous request would otherwise trigger the write). The `.htaccess` box on the Server Rules page renders after that resync, so a block that is still stale there names the actual reason (server ignores `.htaccess` - remove it; not set up any more; manual block next to it; not writable) instead of promising the next page load. **Known limit:** a constant or a code filter that loosens between two requests leaves the block stricter until the next admin page load. A WP-CLI save is not part of that limit: it loosens the block before the commit (or is refused); only the tightening after it waits for the next admin page load, because `SERVER_SOFTWARE` is empty there and the plugin does not set anything up or tighten on a server it cannot see - looser in between, never stricter.

### Status check and notice (`includes/server-rules-check.php`)

**"Check now"** (button only, no background job) sends loopback requests to the site itself - no cookies, 5 s timeout, no redirects, `https_local_ssl_verify` respected:

1. `GET /robots.txt` at the host root: carries the plugin's lines → reaches WordPress; otherwise a physical file or the server answers it, or the plugin adds none (`blog_public` off, mode off).
2. `HEAD` on an over-cap probe (N+1 probe slugs at the first position with a taxonomy; G+4 segments when N = 0): `server-…` with the current hash → **active** (`active`); `server-…` with another hash or none → **stale** (`stale`); PHP's own reason → **not deployed** (`missing`); a reject without header (LiteSpeed, or a proxy that strips it) → **not detectable** (`unknown`); anything that did not get through (401, 403, 429, 5xx, timeout) → **unreachable** (`unreachable`) with the reason, never `missing`. No reject part in the settings → `none`.
3. `HEAD` on an at-cap probe (exactly N slugs, a URL the plugin serves): `server-…` → **red alarm, stricter than the plugin**. On LiteSpeed a header-less 404/410 there is only `stricter_suspected` (amber, ruling R19) - OpenLiteSpeed rejects without a header, but so does WordPress for a base it does not route. A probe that could not run is reported as "not checked", never as "not stricter" - and so is a 3xx without `X-Redirect-By: jpkcom-post-filter` (1.7.1): only the crawl guard's own redirect is the plugin's answer; a redirect to https, to another host name or from a proxy may come before the server rules and proves nothing about them.
4. The rate limit is **not** probed: that would use up the budget all cookie-less visitors share.

**Notice after a loosening save:** after every save of General or Filter Groups (and every import) that loosens the rule inputs (`jpkcom_postfilter_server_rules_is_looser()`: the union differs from the old state - N higher or 0, a group or taxonomy-less position added, strict off, another endpoint/fragment/base, a filtered verdict; `reject_status` alone is not a loosening, ruling R20), the saving admin sees "update your server rules", red when the last check found rules active or stale. Nothing is blocked. The notice is a user transient (60 s) read on `admin_notices`, which suits the redirect after `options.php`; the Import / Export page imports inside its page callback, after `admin_notices` has run, so it calls `jpkcom_postfilter_server_check_notice()` itself right after the import - the notice is on the import result page and is not repeated on the next one. The "before" state is captured on `sanitize_option_*` at priority 4, ahead of the cache-file write. Suppressed when the plugin manages the `.htaccess` block itself and can sync it, and for the migration write.

**The gap that remains, by design:** for servers the plugin does not manage, the hard rule holds **at the time the snippet is generated**. After that, applying the order rule is the admin's job - the notice tells them, and the page says so, but a deployed nginx/Caddy/Ferron/OLS rule stays as strict as it was until someone replaces it.

### Page content and warnings

Head: the server detected from `SERVER_SOFTWARE` (its tab preselected, all tabs available - it is the server in front of PHP, not a proxy or CDN), the effective inputs with their source (constant, filtered), the two rate-limit inputs and the derived limits, the status box. Warnings when they apply: verdict filtered (no reject part, a managed block loses it on the next sync); strict off; N = 0; no groups; no archive bases; unsafe bases left out; policy filters (rules use this admin request's values); filters registered only on the front end (always shown, they cannot be detected); positions without taxonomy; duplicate taxonomies (plugin counts them together, the server per segment - looser); constants in `wp-config.php`; physical robots.txt in the WordPress or site root; WordPress in a subdirectory; `blog_public` off; multisite (rules per site, never one site's rule on another's addresses); proxy/CDN (per-IP counts the proxy unless the server reads the forwarded address); other plugins' rewrite rules.

**Multisite:** the generator covers the current site only, and there is no `.htaccess` management on multisite (one file serves the network).

### Tests

| File | Covers |
|---|---|
| `tests/test-url-policy.php` | Raw per-group count (red against 1.5.0), plus the 1.5.0 guard; tests that depended on the default pin 3 explicitly |
| `tests/test-migration.php` | Default 2 for new installs, pin 3 for existing ones without the key, only on the step from before 1.6.0 (a fresh 1.6.0 install keeps 2 on later updates), stored values untouched, no sanitizer, no frozen defaults |
| `tests/test-server-rules.php` | Inputs, hash, both pattern forms, differential fuzz, bot-shape coverage, timing, every renderer and the curl commands |
| `tests/test-htaccess.php` | Placement, replacement, relocation, removal, manual and damaged markers (incl. a block spanning WordPress's markers), line endings/BOM, WordPress flush afterwards - on file copies |
| `tests/test-htaccess-sync.php` | Union (fuzzed against the real guard), loosen-before / tighten-after, refusal leaves option, cache file and `.htaccess` on the old state, R22 (WP-CLI, unknown servers, path with home ≠ siteurl), reason-specific refusal texts, R18, lifecycle, deactivation |
| `tests/test-server-rules-check.php` | Probe URLs, classification incl. R19/unreachable, `is_looser` (R20), notice capture and suppression |
| `tests/test-server-rules-admin.php` | Server detection, tabs, warnings, messages, `.htaccess` actions, render output |
| `tests/test-i18n.php` | The catalogue carries every string of the page |

Each new test was shown red against the state before its change. Container runs are local (see the matrix). Deferred, worth knowing: ModSecurity/nginx log volume under a flood is unmeasured; the 301 spellings near the cap boundary were covered by the PCRE fuzz only, not by container cases.

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

**Since 1.5.0** the request guard handles them before the main query (`unknown_terms_action`, default `redirect`: 301 to the selection without the unknown slugs, so old links to renamed terms still land somewhere useful). With `noindex`, or with strict URLs off, they render with 200 and `noindex, nofollow` (until 1.5.0: `noindex, follow`). The history below explains why the robots decision is applied through three hooks.

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
> `jpkcom_postfilter_robots_directives()` (in `includes/crawl-guard.php` since
> 1.5.0). Guarded by `tests/test-fragment.php`.

> **Where the zero-results output appears.** With posts, the filter bar and
> results zone are injected at `loop_start` — inside the theme's content column,
> where the listing belongs. With zero posts the loop never starts, `loop_start`
> never fires, and **no core hook reaches that position**: `have_posts()` returns
> false before either loop action runs.
>
> Until 1.2.0 the fallback ran on `wp_footer` alone, which fires *inside* the
> footer template — the message and the entire filter bar appeared below the
> footer. Moving it to `get_footer` got it above the footer but still at the very
> end of the content area, nowhere near the listing. Both were measured on the
> verification site; the second one was only caught because someone looked at the
> rendered page rather than at the markup in isolation.
>
> It now runs on the first hook in `jpkcom_postfilter_zero_results_hooks` that
> fires: `bootscore_before_loop` (immediately before `if ( have_posts() )` in the
> theme's index.php/archive.php — the correct position, and this stack is built
> around bootscore), then `get_footer`, then `wp_footer` for block themes that
> reach neither. Other themes add their own pre-loop hook through the filter.
>
> Attaching to a *pre-loop* hook is only safe because the function returns early
> unless `$wp_query->post_count` is 0 — otherwise a page with posts would render
> the filter bar twice, once there and once from `loop_start`. A second guard
> keeps it to one render when several hooks fire. Both are covered by
> `tests/test-fragment.php`, including the ordering.
>
> Verified positions on the same theme: with results, the wrapper opens at byte
> 32080 right after the content column at 32030; with zero results it opens at
> 32193 right after the content column at 32035. Before the fix it started at
> 40153 with the footer already closed at 39713.

**The asymmetry that existed until 1.4.x is gone.** An empty result from
*existing* terms used to stay `index`. Since 1.5.0 page 1 of an empty
selection is `noindex, nofollow`: an empty page is thin content whatever the
reason, and its links only lead into further empty combinations.

Without that, every made-up slug was an indexable, self-canonicalising thin-content URL that anyone could generate and link, and each one also produced its own query-cache entry (APCu included).

Validation reuses the transient-cached per-taxonomy term list, keyed by **taxonomy — never by the requested slugs** — so it costs no extra query on a warm cache and cannot itself be used to flood the cache. `hide_empty = false`: a term with no posts is still a real term and a legitimate URL.
- Updater: SHA256 checksum verification is **mandatory** (fail closed) and the verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed

**This plugin holds the upstream copy of `includes/class-plugin-updater.php`.** The other JPKCom plugins carry downstream copies that differ only in namespace and text domain - **no exceptions**. `jpkcom-hide-login` 1.2.7-1.2.12 routed its log lines through `jpkcom_hide_login_log()`; that per-plugin patch was undone in 1.2.13 and is now caught by its `tests/test-updater-pin.php` (normalised SHA256 pin, as in `jpkcom-harden-trim`). Fix bugs here, then re-generate the copies — never patch a downstream copy in isolation, and never accept a downstream deviation without asking. Since updater 1.4.0 the manifest cache, the failure marker and the fetch lock are **site** transients, so a multisite network fetches the manifest once rather than once per site.

**Family updates (updater 1.5.0).** WordPress only runs the code of active plugins, so the updater copy inside an inactive JPKCom plugin never runs and that plugin was never offered an update. Since 1.5.0 every copy registers in a shared registry (`$GLOBALS['jpkcom_git_updater_registry']`); the copy with the highest `UPDATER_VERSION` is elected leader (ties: the first registered) and serves every installed JPKCom plugin, active or inactive. A plugin counts as JPKCom when its Plugin URI is `https://github.com/JPKCom/<slug>` and it sits in the canonical `<slug>/<slug>.php` layout. The leader provides the update rows with icons, "View details" and the SHA-256 gate (`upgrader_pre_download` at priority `PHP_INT_MAX - 100`, which also hashes a package an earlier filter supplied instead of trusting it). All other 1.5+ copies stay passive; a plugin whose pre-1.5 copy is loaded keeps serving itself and the leader leaves it alone.
- Package URLs are removed before the update transient is stored (`pre_set_site_transient_update_plugins`) and re-injected on read. A request in which no 1.5+ copy is loaded therefore finds an empty package and core refuses the update ("Update package not available") instead of installing an unverified ZIP.
- Version-control checkouts (`.git`, `.svn`, `.hg`, `.bzr`; core's `automatic_updates_is_vcs_checkout` filter applies) and renamed folders (e.g. `<slug>-main`) are told about a new release but never get a package (core shows "Automatic update is unavailable for this plugin", and the gate refuses an update through a stale row with core's `no_package` error): an update would wipe the working tree, or unpack into `<slug>/` and orphan the active plugin.
- Installs without a plugin name (`wp plugin install <url>`, the details modal) are claimed by the asset's file name `<slug>.zip`, as in 1.4, so every URL GitHub serves for a release asset (`releases/latest/download/`, any case, `www.`, `http`, a query string) must match the manifest checksum.
- Manifests are fetched while rendering update data only for users with `update_plugins`, in cron and in WP-CLI; an interactive request fetches at most 5 uncached manifests, and a host breaker (`jpk_git_update_host_fail`, 15 min) stops further reads after a transport error, a 429/5xx or a fetch slower than 3 s. Installs, updates and the details modal always may fetch. The manifest cache (`jpk_git_update_<md5(slug)>`, shared with older copies) lives 24–30 h, spread per plugin.
- Multisite: at least one JPKCom plugin with updater 1.5+ must be network-active or active on the main site. A plugin that is active only on a subsite cannot serve updates (nothing is offered; nothing unverified is installed).
- Covered by `tests/test-updater-family.php`, identical in every JPKCom plugin (170 checks, red against 1.4.0). Measured live on WordPress 7.1.3, single site and multisite.

---

## Release Workflow

The version appears in five places and must be kept in sync: header `Version:` and `Stable tag:` plus `JPKCOM_POSTFILTER_VERSION` in `jpkcom-post-filter.php`, `<version number="…">` in `phpdoc.xml`, and `**Version:**` / `**Stable tag:**` in `README.md` — where a new `### x.y.z` changelog block is also needed.

**Sixth place, and it is not a version number: the translation catalogue.** Any release that adds or
edits a translatable string regenerates `languages/`. This was missing from the checklist and the
`.pot` fell three minor releases behind — every string added in between shipped untranslated, and
nothing said so. `tests/test-i18n.php` now fails the build when the catalogue is behind the code, so
this is a reminder rather than the only line of defence.

```bash
# 1. Regenerate the catalogue. CI has no WP-CLI, so this is a local step.
#    The plugin must sit inside a DDEV project for eval to reach it.
ddev wp i18n make-pot <plugin-dir> <plugin-dir>/languages/jpkcom-post-filter.pot \
  --slug=jpkcom-post-filter --domain=jpkcom-post-filter \
  --exclude="node_modules,tests,tools,docs,.github,blocks/build,debug-templates"

# 2. Carry existing translations over. --no-fuzzy-matching, because a fuzzy
#    match on these strings is a wrong translation shipped silently.
for po in languages/*-*.po; do msgmerge --update --backup=none --no-fuzzy-matching "$po" languages/jpkcom-post-filter.pot; done

# 3. Binary and PHP forms. WordPress 6.8+ reads the .l10n.php first.
for po in languages/*-*.po; do msgfmt -o "${po%.po}.mo" "$po"; done
ddev wp i18n make-php <plugin-dir>/languages

# 4. Prove nothing was lost, rather than assuming it. Compare the translated
#    msgid SET before and after - msgfmt's own counts include plural forms and
#    move for reasons that are not losses.
msgattrib --translated --no-obsolete languages/jpkcom-post-filter-de_DE.po | grep '^msgid "' | sort -u
```

Heed `make-pot`'s warnings. A `translators:` comment only reaches the catalogue when it sits
**immediately** above the `__()` call — putting an ordinary comment between the two detaches it
silently, and that is exactly what the first run of this step caught.

> **Project decision: the Abilities API stays English, in every language.** The two abilities' labels,
> descriptions, schema texts and error messages are read by MCP clients and agents, not by people in
> wp-admin, and their wording is the feature — a message that names the valid taxonomies is what lets
> a caller correct itself in one turn instead of retrying blind. All 41 strings from
> `includes/abilities.php` are in the catalogue so the state is visible, and all 41 are untranslated
> on purpose. **Do not "complete" them**, and do not read an empty `msgstr` on one of them as a
> backlog.

**Releasing.** Bump those, commit, then push a `v*` tag — that tag push is the only trigger, and `.github/workflows/release.yml` creates the GitHub release itself. Pipeline: README metadata via Pandoc → `npm ci && npm run build` for the Gutenberg block → slug-named ZIP (excludes `.git`, `.github`, `CLAUDE.md`, `tests`, `tools`, `node_modules`, build config, `docs`) → SHA256 → upload ZIP + `.sha256` → `plugin_jpkcom-post-filter.json` manifest → PHPDoc → deploy to `gh-pages`. ZIP and manifest must come from the same run, since the manifest's `checksum_sha256` is what the updater verifies.

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

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
| `jpkcom-postfilter-server-rules` (1.6.0, `includes/server-rules-admin.php`, menu priority 21) | `jpkcom_postfilter_page_server_rules` | `jpkcom_postfilter_server_rules` (rate-limit inputs only; `.htaccess` and "Check now" go through `admin-post.php`) |

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
