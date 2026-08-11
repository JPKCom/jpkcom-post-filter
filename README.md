# JPKCom Post Filter

**Plugin Name:** JPKCom Post Filter  
**Plugin URI:** https://github.com/JPKCom/jpkcom-post-filter  
**Description:** Faceted navigation and filtering of Posts, Pages, and Custom Post Types via WordPress taxonomies — SEO-friendly URLs, AJAX updates, and full screen reader support.  
**Version:** 1.4.2  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com/  
**Contributors:** JPKCom  
**Tags:** filter, taxonomy, faceted search, custom post type, AJAX  
**Requires at least:** 7.0  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Stable tag:** 1.4.2  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Text Domain:** jpkcom-post-filter  
**Domain Path:** /languages

Filter any post type by taxonomy terms — SEO-friendly URLs, AJAX, and shortcodes included.

---

## Description

**JPKCom Post Filter** adds faceted taxonomy filtering to any WordPress archive page or via shortcodes. Visitors can narrow down posts, pages, or custom post types by one or more taxonomy terms, with every filter state reflected in the URL for bookmarking and sharing.

### Key Features

- **SEO-friendly URL schema** — Filter state encoded in the URL path: `/blog/filter/category1+category2/tag1/`
- **Four filter layouts** — Horizontal bar, columns, sidebar, or dropdown with active-count badge
- **Three list layouts** — Cards (grid), rows (list), or minimal (compact)
- **Hybrid rendering** — Server-side on first load; AJAX + `history.pushState` when JS is available; full page reload fallback when JS is disabled
- **Auto-inject mode** — Automatically wraps theme archive loops with filter UI (no shortcode needed)
- **Shortcode mode** — Place filter, list, and pagination on archive pages via shortcodes
- **Gutenberg Blocks** — Three native blocks (Post Filter, Post List, Post Pagination) with live editor preview and full InspectorControls panel
- **Elementor Widgets** — Three widgets (Post Filter, Post List, Post Pagination) in a dedicated widget category, loaded only when Elementor is active
- **Oxygen Builder Elements** — Three elements (Post Filter, Post List, Post Pagination) in a custom toolbar section, loaded only when Oxygen Builder Classic is active
- **Custom taxonomy creation** — Register new WordPress taxonomies directly from the Filter Groups admin page
- **Plus/Minus interaction mode** — Additive (`+` icon) or exclusive (label click) filter selection
- **Show More button** — Collapse long filter lists behind a configurable threshold
- **Four color schemes** — Default, Dark, Contrast, Monochrome (all overridable)
- **Three stylesheet modes** — Full plugin CSS, CSS variables only, or fully disabled
- **Four-layer caching** — Object cache, transients, APCu, and PHP file cache for settings
- **Full accessibility** — `aria-live` regions, `aria-pressed` toggle buttons, screen-reader-only counts
- **Multi-language** — Ships with EN, de\_DE, and de\_DE\_formal translations
- **No dependencies** — No ACF, no Bootstrap, no jQuery required

---

## Installation

1. Upload the `jpkcom-post-filter` directory to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **Post Filter → General** and select the post types to filter.
4. Go to **Post Filter → Filter Groups** and add the taxonomies you want as filter dimensions.
5. Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

---

## Configuration

### General Settings

| Option | Description |
|--------|-------------|
| Enabled Post Types | Which post types to activate filtering for |
| Auto-Inject Filter | Automatically add filter UI to archive/blog pages per post type |
| URL Endpoint | URL path segment for filter URLs (default: `filter`) |
| Bare Endpoint Behaviour | What happens when `/filter/` is accessed without filter terms: 404, redirect to blog homepage, or custom URL |
| Max Filter Combinations | Maximum simultaneous active filter groups (affects URL and JS) |
| Max. Filters per Group | Maximum terms selectable within one filter group (0 = unlimited) |
| Debug Mode | Use `debug-templates/` and write detailed logs to PHP error log |

After changing the URL endpoint go to **Settings → Permalinks** and click Save, or use the **Flush Rewrite Rules** button on the General page.

### Filter Groups

Each filter group maps a taxonomy to a URL position. Groups are applied in the configured order:

```
/archive-base/filter/{group-1-slugs}/{group-2-slugs}/
/blog/filter/web-design+marketing/wordpress/
```

Configure groups at **Post Filter → Filter Groups**. Each group has:

| Field | Description |
|-------|-------------|
| Taxonomy | Select an existing WP taxonomy, or enable "Register as new taxonomy" to create one |
| Label | Displayed in the filter bar (and as admin label for new taxonomies) |
| Post Types | Which archive pages this group appears on (empty = all enabled post types) |
| Order | Numeric sort position — determines URL segment order |
| Enabled | Toggle group on/off without deleting it |

**Creating new taxonomies** — When "Register as new WordPress taxonomy" is checked, additional fields appear:

| Field | Description |
|-------|-------------|
| Taxonomy Slug | Unique identifier, lowercase letters and hyphens |
| Rewrite Slug | URL prefix for term archive pages |
| Hierarchical | Category-like (parent/child) or tag-like (flat) |
| Public | Enable frontend term archive pages |
| Admin Column | Show taxonomy as column in post list table |
| REST API | Expose in REST API (required for Gutenberg) |

> **Warning:** Removing a filter group that registered a custom taxonomy will unregister that taxonomy and all term assignments on posts will be permanently lost.

### Layout & Design

**Post Filter → Layout & Design** contains six tabs:

**Tab: Global** — Default filter layout (bar / columns / sidebar / dropdown), default list layout (cards / rows / minimal), and global spacing/typography variables.

**Tab: Filter** — Colors and dimensions for filter buttons (default, hover, active state), the reset button, and dropdown panel styling.

**Tab: Posts** — Card background, border, shadow, radius, padding; grid column counts for desktop, tablet, and mobile; typography and link colors.

**Tab: Pagination** — Colors for pagination buttons (default, hover, active), border radius, and font size.

**Tab: Color Schemes** — Choose a preset color scheme. Custom variable overrides from the other tabs are applied on top.

| Scheme | Description |
|--------|-------------|
| Default | WordPress blue accent, light backgrounds |
| Dark | Dark backgrounds, light text, blue accent |
| Contrast | Red reset button for stronger visual differentiation |
| Monochrome | Black, white, and grey only — no color accent |

**Tab: Advanced** — Stylesheet mode, filter interaction, pagination position, and custom CSS.

| Setting | Options | Description |
|---------|---------|-------------|
| Stylesheet Mode | Full / Variables only / Disabled | Full loads the complete plugin CSS; Variables only outputs only the `:root` block as inline style; Disabled loads nothing |
| Reset Button Visibility | Always / On selection / Never | Controls when the "Reset filters" link is shown |
| Plus/Minus Mode | on/off | Adds +/– icons; clicking the label selects a filter exclusively, clicking `+` adds it to the current selection |
| Show More Button | on/off + threshold | Hides filters beyond the threshold behind a `…` toggle (not available in Dropdown layout) |
| Pagination Position | Below / Above / Both | Auto-inject only; shortcode pagination is placed manually |
| Custom CSS | textarea | Additional rules appended after the plugin stylesheet |

### Cache & Performance

Enable or disable individual cache layers, set the transient TTL, and clear caches from **Post Filter → Cache**.

| Layer | Description |
|-------|-------------|
| Object Cache | `wp_cache_*` for query results; invalidated on `save_post` / `deleted_post` |
| Transient Cache | `get/set_transient` for taxonomy term lists; invalidated on term changes |
| Settings File Cache | PHP `include` cache in `.ht.jpkcom-post-filter-settings/`; fastest for settings reads |

Cache TTL applies to transients. Object cache respects the TTL of the active object cache backend.

### Import / Export

All plugin settings (general, layout, cache, filter\_groups) can be exported as a JSON file and re-imported at **Post Filter → Import / Export**. Useful for migrating settings between environments.

---

## Shortcodes

### Important: Where shortcodes work

Shortcodes always connect to the WordPress archive of the configured post type. When a filter is activated the browser navigates to a URL like `/news/filter/slug/` — the base URL is always the post type archive, not the page the shortcode is placed on.

**Supported placements:**

1. **Archive template** — Embed shortcodes inside an archive template via a page builder or the Gutenberg Full Site Editor. The page IS the archive, so archive URL = current URL.
2. **Posts Page (post type only)** — Assign a WordPress page as the "Posts Page" under **Settings → Reading**. WordPress treats that page as the blog archive, so `/blog/` and the shortcode page are the same URL.
3. **Auto-inject instead** — Enable **General → Auto-Inject Filter** to have the plugin inject the filter UI into archive pages automatically, without any shortcode.

**What does not work:** Placing shortcodes on an arbitrary custom page (e.g. `/test/`) whose URL has nothing to do with the post type archive. Filter clicks, AJAX results, and pagination will all redirect to the archive URL, leaving the custom page behind.

---

### `[jpkcom_postfilter_filter]`

Renders the filter bar.

| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any registered post type | `post` |
| `layout` | `bar` / `columns` / `sidebar` / `dropdown` | backend setting |
| `groups` | comma-separated group slugs | all groups |
| `reset` | `true` / `false` / `always` | backend setting |
| `class` | string | — |

The `reset` attribute overrides the global Reset Button Visibility setting: `false` forces "never", `always` forces "always", `true` uses the backend setting.

### `[jpkcom_postfilter_list]`

Renders the filtered post list.

| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any registered post type | `post` |
| `layout` | `cards` / `rows` / `minimal` | backend setting |
| `limit` | integer | `-1` (all) |
| `orderby` | `date` / `title` / `menu_order` | `date` |
| `order` | `ASC` / `DESC` | `DESC` |
| `class` | string | — |

### `[jpkcom_postfilter_pagination]`

Renders pagination for the filtered list. Must be placed after `[jpkcom_postfilter_list]`. Returns empty output when there is only one page.

| Attribute | Values | Default |
|-----------|--------|---------|
| `post_type` | any registered post type | `post` |
| `class` | string | — |

### Example

```
[jpkcom_postfilter_filter post_type="portfolio" layout="dropdown"]
[jpkcom_postfilter_list post_type="portfolio" layout="cards" limit="12"]
[jpkcom_postfilter_pagination post_type="portfolio"]
```

Use the interactive shortcode builder at **Post Filter → Shortcodes** to generate snippets without writing code.

---

## Gutenberg Blocks

Three native Gutenberg blocks are available under the **JPKCom Post Filter** block category. They work in the classic Block Editor and in the Full Site Editor (FSE).

### Post Filter

Renders the filter/facets UI.

| Setting | Options | Default |
|---------|---------|---------|
| Post Type | any registered post type | `post` |
| Layout | Bar / Sidebar / Dropdown / Columns | backend setting |
| Filter Groups | comma-separated slugs | all groups |
| Reset Button | Default / Always / Never | backend setting |

### Post List

Renders the filtered post listing.

| Setting | Options | Default |
|---------|---------|---------|
| Post Type | any registered post type | `post` |
| Layout | Cards / Rows / Minimal / Theme | backend setting |
| Posts per Page | -1 to 100 | `5` |
| Order By | Date / Title / Menu Order | `date` |
| Order | ASC / DESC | `DESC` |

### Post Pagination

Renders pagination for the post listing. Can be placed both **above and below** the Post List block — the plugin pre-scans the block tree before rendering to ensure pagination works at any position. Shows a static preview with example pages in the editor.

| Setting | Options | Default |
|---------|---------|---------|
| Post Type | any registered post type | `post` |

### Block placement notes

- All three blocks must use the same **Post Type** setting to be paired correctly.
- The filter bar and results zone are paired via `data-jpkpf-post-type` attributes — AJAX filtering works across blocks on the same page.
- Pagination blocks placed above the Post List block work correctly: the plugin pre-runs the list query before any block renders.
- In the editor, blocks show a live server-side preview. JS-driven features (Show More, Plus/Minus) are replicated server-side for accurate previews.

### Building from source

Block editor scripts require a build step:

```bash
npm install
npm run build
```

Built files are output to `blocks/build/`. The plugin skips block registration when the build directory is missing.

---

## Elementor Widgets

Three Elementor widgets are available under the **JPKCom Post Filter** widget category. They are only loaded when Elementor is active.

### Post Filter

| Control | Type | Default |
|---------|------|---------|
| Post Type | Select | `post` |
| Layout | Select (Default / Bar / Sidebar / Dropdown / Columns) | backend setting |
| Filter Groups | Text (comma-separated slugs) | all groups |
| Reset Button | Select (Default / Always / Never) | backend setting |
| CSS Class | Text | — |

### Post List

| Control | Type | Default |
|---------|------|---------|
| Post Type | Select | `post` |
| Layout | Select (Default / Cards / Rows / Minimal / Theme) | backend setting |
| Posts per Page | Number (-1 to 100) | `5` |
| Order By | Select (Date / Title / Menu Order / Modified / Random) | `date` |
| Order | Select (DESC / ASC) | `DESC` |
| CSS Class | Text | — |

### Post Pagination

| Control | Type | Default |
|---------|------|---------|
| Post Type | Select | `post` |
| CSS Class | Text | — |

### Widget placement notes

- All three widgets must use the same **Post Type** to work together.
- Widgets call the same shortcode functions as the Gutenberg blocks — AJAX filtering, SEO-friendly URLs, and pagination work identically.
- The Pagination widget can be placed both above and below the Post List widget.
- Elementor templates for archive pages are the recommended placement.

---

## Oxygen Builder Elements

Three Oxygen Builder Classic (v3.x+) elements are available under the **Post Filter** section in the Oxygen Add Elements panel. They are only loaded when Oxygen Builder is active.

### Post Filter

| Control | Type | Default |
|---------|------|---------|
| Post Type | Dropdown | `post` |
| Layout | Dropdown (Default / Bar / Sidebar / Dropdown / Columns) | backend setting |
| Filter Groups | Text (comma-separated slugs) | all groups |
| Reset Button | Dropdown (Default / Always / Never) | backend setting |

### Post List

| Control | Type | Default |
|---------|------|---------|
| Post Type | Dropdown | `post` |
| Layout | Dropdown (Default / Cards / Rows / Minimal / Theme) | backend setting |
| Posts per Page | Text (-1 = all) | `5` |
| Order By | Dropdown (Date / Title / Menu Order / Modified / Random) | `date` |
| Order | Dropdown (Descending / Ascending) | `DESC` |

### Post Pagination

| Control | Type | Default |
|---------|------|---------|
| Post Type | Dropdown | `post` |

### Element placement notes

- All three elements must use the same **Post Type** to work together.
- Elements call the same shortcode functions as the Gutenberg blocks and Elementor widgets — AJAX filtering, SEO-friendly URLs, and pagination work identically.
- The Pagination element can be placed both above and below the Post List element.
- Oxygen archive templates are the recommended placement. Auto-inject mode does not work with Oxygen since it bypasses the standard WordPress template system.
- Elements use the OxyEl API and appear in Oxygen's "Type to search components" field when searching for "post", "filter", "pagination", or "jpkcom".

---

## Auto-Inject Mode

When **Auto-Inject Filter** is enabled for a post type in General Settings, the plugin automatically wraps the theme archive loop with the filter UI — no shortcode or template modification required. The filter bar is inserted before the loop and the results zone wraps the loop output.

The pagination position for auto-inject mode is configurable (Below / Above / Both) in **Layout & Design → Advanced**.

Auto-inject works by hooking into `loop_start` and `loop_end` on archive/blog pages. It does not run on singular posts, search results, or pages.

---

## Template Overrides

Templates can be overridden per theme without editing plugin files. The loader checks these locations in order:

1. `themes/{child-theme}/jpkcom-post-filter/{template}`
2. `themes/{parent-theme}/jpkcom-post-filter/{template}`
3. `mu-plugins/jpkcom-post-filter-overrides/templates/{template}`
4. Plugin `templates/` (or `debug-templates/` when debug mode is on)

**Example:** To override the cards list template, copy
`plugins/jpkcom-post-filter/templates/partials/list/list-cards.php`
to
`themes/your-theme/jpkcom-post-filter/partials/list/list-cards.php`.

### Available templates

```
partials/filter/filter-bar.php       ← Horizontal bar layout
partials/filter/filter-columns.php   ← Columns layout
partials/filter/filter-sidebar.php   ← Sidebar layout
partials/filter/filter-dropdown.php  ← Dropdown layout
partials/list/list-cards.php         ← Cards (grid) layout
partials/list/list-rows.php          ← Rows layout
partials/list/list-minimal.php       ← Minimal (ul/li) layout
partials/pagination/pagination.php   ← Pagination
shortcodes/filter.php                ← Shortcode wrapper → delegates to partials/filter/
shortcodes/posts-list.php            ← Shortcode wrapper → delegates to partials/list/
shortcodes/pagination.php            ← Shortcode wrapper → delegates to partials/pagination/
```

### Template action hooks

```php
// Fires before and after every template part
add_action( 'jpkcom_postfilter_before_template_part', function( $path, $slug, $name, $args ) { ... }, 10, 4 );
add_action( 'jpkcom_postfilter_after_template_part',  function( $path, $slug, $name, $args ) { ... }, 10, 4 );
```

---

## CSS Customisation

All styles use CSS custom properties prefixed `--jpkpf-`. Override them in your theme stylesheet, via the CSS Variables fields in **Layout & Design**, or via the Custom CSS field:

```css
:root {
    --jpkpf-primary:             #0073aa;
    --jpkpf-primary-hover:       #005d8c;
    --jpkpf-filter-bg:           #f0f0f1;
    --jpkpf-filter-active-bg:    #0073aa;
    --jpkpf-filter-active-color: #ffffff;
    --jpkpf-filter-radius:       3px;
    --jpkpf-gap:                 0.5rem;
    --jpkpf-card-radius:         4px;
    --jpkpf-transition:          0.2s ease;
}
```

---

## Abilities API

WordPress 6.9 introduced the Abilities API: a registry of machine-readable capabilities that AI assistants, MCP clients and automation tools can discover and call. This plugin registers two of them, both **read-only**, so an assistant can answer questions about your posts without scraping the front end.

| Ability | What it does |
|---------|--------------|
| `jpkcom-post-filter/list-filters` | Reports which taxonomies and terms a post type can be filtered by, with a post count per term |
| `jpkcom-post-filter/query-posts` | Runs a filtered, paginated query and returns the matching posts plus a shareable filter URL |

The point of the pair is that the first one removes the guesswork from the second: an assistant looks up the real taxonomy keys and term slugs, then filters with values that exist. A filter naming a taxonomy your site does not have is rejected with an error listing the valid ones — it never silently returns your whole archive instead.

### What a call looks like

Both are reachable at `/wp-json/wp-abilities/v1/`. Because they only read, they answer on `GET`:

```
GET /wp-json/wp-abilities/v1/abilities/jpkcom-post-filter/list-filters/run?input[post_type]=post

{ "post_type": "post",
  "groups": [ { "taxonomy": "category", "label": "Kategorie",
                "terms": [ { "slug": "allgemein", "name": "Allgemein", "count": 2 }, … ] } ] }
```

```
GET /wp-json/wp-abilities/v1/abilities/jpkcom-post-filter/query-posts/run
      ?input[post_type]=post&input[filters][category][]=allgemein&input[per_page]=3

{ "post_type": "post", "filters": { "category": [ "allgemein" ] },
  "total": 2, "page": 1, "per_page": 3, "total_pages": 1,
  "filter_url": "https://example.com/filter/allgemein/",
  "unknown_terms": {},
  "posts": [ { "id": 143, "title": "…", "url": "…", "date": "…", "excerpt": "…",
               "terms": { "category": [ { "slug": "allgemein", "name": "Allgemein" } ] } } ] }
```

`query-posts` accepts `post_type`, `filters`, `page`, `per_page` (1–50) and `search`. Terms within one taxonomy are combined with OR, different taxonomies with AND — the same logic as the filter bar on your site. A term slug that matches nothing is not an error: the query runs and the slug is reported under `unknown_terms`, so the caller can tell a typo apart from an empty result.

`filter_url` comes back empty whenever no front-end address would show exactly the posts listed beside it — a post type without an archive page, a filter combination longer than your configured limits, a page after the first requested with a page size other than your site's own "posts per page", or a page past the last one. The results are complete either way; the assistant simply has no link to pass on.

### What is exposed, and to whom

- Only **published** posts. The query cannot reach drafts, private or trashed content, and cannot be talked into it — the post status is fixed in the code, not a parameter.
- Only post types you have enabled for filtering, and only taxonomies you have configured as filter groups.
- Running an ability requires a logged-in user with the `read` capability. Listing the available abilities is likewise restricted to logged-in users — but note that any logged-in user, including a subscriber, can then see both abilities' descriptions and parameter schemas. That is how WordPress core gates every ability, not something specific to this plugin.

### Turning it off, or tightening it

Add this to `wp-config.php` to register nothing at all:

```php
define( 'JPKCOM_POSTFILTER_ABILITIES', false );
```

To keep the abilities but raise the bar for running them, filter the capability:

```php
add_filter( 'jpkcom_postfilter_ability_capability', static fn(): string => 'edit_posts' );
```

To keep them out of MCP clients while leaving the REST route intact, or the other way round, use `jpkcom_postfilter_ability_meta` — see the Filters section below.

---

## Developer Reference

See `CLAUDE.md` in the plugin root for the full developer reference including architecture decisions, constant definitions, cache layer documentation, template action hooks, and implementation notes.

### Constants (wp-config.php overridable)

| Constant | Default | Purpose |
|----------|---------|---------|
| `JPKCOM_POSTFILTER_VERSION` | `'1.0.0'` | Plugin version |
| `JPKCOM_POSTFILTER_DEBUG` | `WP_DEBUG` | Enables debug mode and debug templates |
| `JPKCOM_POSTFILTER_CACHE_ENABLED` | `true` | Master cache toggle |
| `JPKCOM_POSTFILTER_URL_ENDPOINT` | `'filter'` | URL path segment |
| `JPKCOM_POSTFILTER_SETTINGS_DIR` | `WP_CONTENT_DIR . '/.ht.jpkcom-post-filter-settings'` | Settings file cache location |
| `JPKCOM_POSTFILTER_MAX_FILTER_COMBOS` | `3` | Default max filter group combinations |
| `JPKCOM_POSTFILTER_ABILITIES` | `true` | Registers the WordPress Abilities API integration. Set to `false` to withdraw both abilities from REST and MCP entirely. |

### JavaScript data attributes

```html
<!-- Auto-inject mode: plugin adds data-jpkpf-wrapper automatically -->
<div data-jpkpf-wrapper data-jpkpf-base-url="/blog/">
    <nav data-jpkpf-filter-bar data-jpkpf-post-type="post">…</nav>
    <div data-jpkpf-results data-jpkpf-post-type="post" aria-live="polite">…</div>
</div>

<!-- Shortcode / Block / Elementor / Oxygen mode: paired by data-jpkpf-post-type -->
<nav data-jpkpf-filter-bar data-jpkpf-post-type="portfolio" data-jpkpf-base-url="/portfolio/">…</nav>
<div data-jpkpf-results data-jpkpf-post-type="portfolio">…</div>
<nav data-jpkpf-pagination data-jpkpf-post-type="portfolio">…</nav>
```

The `data-jpkpf-pagination` attribute on standalone pagination elements (blocks/shortcodes/Elementor) enables the JS to swap pagination content during AJAX filter requests, keeping pagination links filter-aware.

### Key PHP functions

```php
// Settings
jpkcom_postfilter_settings_get( 'general', 'url_endpoint', 'filter' )
jpkcom_postfilter_settings_get_group( 'layout' )
jpkcom_postfilter_settings_save( 'general', $data )
jpkcom_postfilter_settings_delete_cache( '*' )  // flush all

// Cache
jpkcom_postfilter_cache_get( $key, $found )
jpkcom_postfilter_cache_set( $key, $value, $ttl )
jpkcom_postfilter_cache_flush_group()
jpkcom_postfilter_transient_get( 'terms_category' )
jpkcom_postfilter_transient_set( 'terms_category', $data )

// Templates
jpkcom_postfilter_locate_template( 'partials/filter/filter-bar.php' )
jpkcom_postfilter_get_template_part( 'partials/filter/filter-bar', '', $args )
jpkcom_postfilter_get_template_html( 'partials/list/list-cards', '', $args )

// URL helpers
jpkcom_postfilter_get_filter_url( $base_url, $active_filters )
jpkcom_postfilter_get_archive_base_url( $post_type )
jpkcom_postfilter_get_active_filters()
jpkcom_postfilter_build_filter_url( $base, $filters )
jpkcom_postfilter_is_filter_request()

// Filter groups
jpkcom_postfilter_get_filter_groups_enabled()
jpkcom_postfilter_get_terms_for_group( $group, $active_filters )
```

### Filters

- `jpkcom_postfilter_ability_meta( array $meta, string $ability_name )` — adjust the meta of an ability before registration. Set `show_in_rest` to `false` to hide it from the REST API, or `mcp.public` to `false` to hide it from MCP clients.
- `jpkcom_postfilter_ability_capability( string $capability, string $ability_name )` — the capability required to run an ability. Defaults to `read`, which covers every logged-in user. Raise it to `edit_posts` to restrict bulk machine-readable access to published content.

---

## FAQ

**Does this plugin work with custom post types?**
Yes. Any post type registered in WordPress can be enabled in **Post Filter → General**.

**Does it work with custom taxonomies?**
Yes. All registered taxonomies (including custom ones) appear in the Filter Groups configuration. You can also create new taxonomies directly from the Filter Groups page without writing any code.

**Does filtering work without JavaScript?**
Yes. Each filter term is a regular `<a>` link. Without JS the page reloads with the new filter URL. With JS, only the results zone is updated via AJAX.

**Will the filter URLs be indexed by search engines?**
Each filter combination has a unique, crawlable URL. The plugin registers WordPress rewrite rules and sets canonical URLs to avoid duplicate content.

**Can I place shortcodes on any page?**
No — shortcodes must be placed on the archive page for the configured post type (via a page builder/FSE template, or by using a WordPress page as the "Posts Page"). On an arbitrary custom page the filter links will navigate away to the archive URL. See the **Shortcodes** section above for details. The same applies to Gutenberg blocks, Elementor widgets, and Oxygen elements.

**Can I use multiple filter instances for different post types on the same page?**
Yes. Each shortcode/block/widget set is paired by `post_type` attribute. The JS matches `[data-jpkpf-filter-bar][data-jpkpf-post-type="X"]` with `[data-jpkpf-results][data-jpkpf-post-type="X"]`.

**Do the Gutenberg blocks work in the Full Site Editor (FSE)?**
Yes. All three blocks work in FSE templates. The pagination block can be placed above and/or below the list block — the plugin pre-scans the template block tree to ensure correct query availability regardless of block order.

**Does the plugin require Elementor or Oxygen Builder?**
No. Page builder support is optional. Elementor widgets are only loaded when Elementor is active, and Oxygen elements are only loaded when Oxygen Builder is active. The plugin works independently with auto-inject mode, shortcodes, or Gutenberg blocks.

**How do I clear the plugin cache?**
Go to **Post Filter → Cache** and click one of the Clear Cache buttons. Caches are also invalidated automatically when posts or terms are saved or deleted.

**How do I change the URL segment from "filter" to something else?**
Change the **URL Endpoint** in **Post Filter → General**, then click **Flush Rewrite Rules** (or visit **Settings → Permalinks** and save).

**How do I disable the plugin's CSS entirely?**
Set **Stylesheet Mode** to "Disabled" in **Post Filter → Layout & Design → Advanced**. You are then fully responsible for all styling.

---

## Changelog

### 1.4.2

* **Added:** 46 German translations that were missing. Affected were the three block titles and their descriptions and search keywords, the Elementor and Oxygen widget labels, several settings hints, and the updater's security messages — all of which appeared in English on a German site. The Abilities API texts stay English on purpose: they are read by AI clients and automation, not in the admin area, and their exact wording is what lets a caller correct a mistaken request in one attempt.
* **Fixed:** the confirmation dialog shown when removing a filter group with its own taxonomy printed the six characters `\u2014` instead of a dash. PHP reads that escape only in its braced form, so the plain one stayed in the text and was shown as-is.
* **Hardened:** the translation check added in 1.4.0 reported every entry carrying a context — the block titles, descriptions and keywords, sixteen in total — as missing from the compiled catalogue, although they were present. It now reads the context the way WordPress stores it.

### 1.4.1

* **Fixed:** the German translation had not kept up with the plugin for three releases. The translation catalogue was last generated at version 1.1.2, so every text added since — including all of the messages the abilities return — appeared in English on a German site, with nothing anywhere to indicate it. The catalogue now covers the whole plugin again. The existing German translations are unchanged; the newly added texts are listed but not yet translated, so they still appear in English until they are.
* **Hardened:** the build now fails when the translation catalogue falls behind the code. Regenerating it was never an automated step and was not on the release checklist, which is how it went unnoticed for three releases. It is now both.

### 1.4.0

* **Changed:** WordPress 7.0 is now the minimum. Up to 6.9 an unexpected error inside an ability callback ended the whole request with a blank page instead of a readable message, and the plugin carried its own guards against that. From 7.0 WordPress catches it itself. The guards stay in place, but the plugin is no longer tested against 6.9 and no longer claims to run there.
* **Hardened:** a build check now compares the list of input keys each ability accepts against the list it publishes in its schema. The two are written separately, and nothing compared them before. Had they drifted apart, a caller sending exactly what the schema describes would have been told the key does not exist — the plugin refusing a request that was correct. The check fails the build on either kind of mismatch.
* **Changed:** the "no input given" default of both abilities is now written directly as an empty object instead of being produced by a helper. What clients receive is unchanged, and it stays an object rather than an empty list.

### 1.3.1

* **Fixed:** the query ability is declared read-only, but reading a post's excerpt made WordPress' embed handler run — and with no post to attach its cache to, it stored the result as a published post of its own and fetched the linked address from the third-party site while doing so. One request could therefore add rows to the database and contact outside hosts, and any logged-in user was allowed to trigger it. The excerpt is now read with that handler switched off for the duration and switched back on afterwards, so the text you get back is unchanged.
* **Fixed:** clearing the cache never cleared the fastest layer of it. Saving a post, editing a term, saving the settings, and both "Clear cache" buttons in the admin all reported success while leaving stale query results in place until they expired on their own — up to an hour by default. This affects every site, whether or not the abilities are used.
* **Fixed:** a search term longer than 1600 bytes was thrown away by WordPress itself, inside the query and without any warning, so the answer was the same one you would have got without searching at all — and nothing in the response said so. Such a term is now refused with a message naming the limit. The limit counts bytes, so accented characters and emoji use more than one each.
* **Fixed:** the shareable filter link was handed out even when a search term had narrowed the result set. The link cannot carry a search term, so it led to a larger, different list than the one reported beside it — in the clearest case, an answer of "no results" next to a link showing six posts. The link is now withheld in that case; the results themselves are still returned in full.
* **Fixed:** a filter sent at the wrong nesting level was neither applied nor rejected. The response was a normal, successful-looking answer containing every post on the site, with the filter section empty. Unrecognised input is now refused with a message naming both the key it rejected and the ones it accepts.
* **Changed:** two statements in the developer documentation were corrected. The claim that nothing unpublished can be reached through the abilities was unconditional and did not hold — the restriction lives in the query the plugin builds, and another plugin can change that query before it runs. The behaviour is unchanged; the description of it now matches.

### 1.3.0

* **Added:** the plugin now registers two read-only WordPress Abilities, `jpkcom-post-filter/list-filters` and `jpkcom-post-filter/query-posts`, in the shared `jpkcom-content` category. MCP clients, REST automation and the WordPress AI client can ask which taxonomies and terms a post type can be filtered by, then run a filtered, paginated query and receive both the results and a shareable filter URL.
* **Added:** `JPKCOM_POSTFILTER_ABILITIES` (default `true`) withdraws both abilities from REST and MCP when set to `false` in `wp-config.php`, plus the filters `jpkcom_postfilter_ability_meta` and `jpkcom_postfilter_ability_capability` for per-ability control.
* **Hardened:** a filter naming a taxonomy that does not exist previously produced the complete unfiltered result set, because the query builder drops such a clause silently. The query ability now rejects it and names the valid taxonomies, so a caller can correct itself instead of presenting the whole site as a filtered answer.
* **Hardened:** the query ability always passes a positive page size. The shortcode default of `-1` sets `no_found_rows`, which reports a total of zero regardless of how many posts exist.
* **Hardened:** a single request can filter by at most 50 term slugs per taxonomy; anything beyond that is dropped rather than rejected, so an over-eager caller still gets an answer instead of an error. Without the cap one request could ask for thousands of slugs and turn into a query of the same size.
* **Hardened:** a search term passed to the query ability is answered from the database rather than from the query cache. Search text is free-form, so caching it would let a caller fill the object cache and APCu with one entry per phrase.
* **Changed:** the query ability returns an empty `filter_url` for post types that have no archive page, such as `page`. It previously returned a relative address like `/filter/news/`, which no rewrite rule serves — a link that would have 404'd.
* **Fixed:** `filters`, `unknown_terms` and each post's `terms` are sent as `{}` when they hold nothing, rather than as `[]`. The output schema declares all three as objects, so a client validating the response against that schema rejected the empty case — which is the most common case for `unknown_terms`.
* **Fixed:** both abilities can now be called with no input at all. Every parameter is optional, but a bare call was answered with `ability_invalid_input` — "input is not of type object" — so the most natural way to ask "which filters does this site have?" was the one way that did not work.
* **Fixed:** the query ability no longer hands out a `filter_url` that leads somewhere else. The link is now returned only when opening it shows exactly the posts reported next to it, and is left empty in four cases: the post type has no archive page; the site caps the number of terms per taxonomy or the number of taxonomies below what was asked for, so the front end would honour only the first few; a page after the first was requested with a page size that differs from the site's own "posts per page" setting, which is the unit the page number in the URL is counted in; or the requested page lies past the last one, where the URL answers 404. The full result set is returned in every one of those cases — only the link is withheld.
* **Fixed:** the top-level `default` of both abilities' input schemas is now sent to MCP clients as `{}` rather than `[]`. The schema declares it as an object, and WordPress corrected the value on its own REST route but not on the one MCP clients read, so a client that validates schemas saw a contradiction.
* **Fixed:** asking for a page beyond the last one reported `total: 0` and `total_pages: 0` next to the page number that was requested — a response that contradicted itself and read as "this site has no posts". It now reports the real totals with an empty post list.
* **Fixed:** caller mistakes — an unknown taxonomy or a post type that is not enabled — are answered over REST with HTTP 400 instead of 500. Both messages name the valid values so the caller can correct itself, which a 500 undoes by telling automation it hit a server fault and should retry the same request unchanged.
* **Hardened:** a filter group whose taxonomy is no longer registered — the usual cause is deactivating the plugin that provided it — is no longer offered as filterable. Filtering by it used to be accepted on the strength of the configuration alone and then answered with the complete unfiltered corpus; it now returns the unknown-taxonomy error naming the taxonomies that really exist.

### 1.2.3
* CI: the lint and guard workflow now also runs on pushes to `main`. It only covered pull requests, so a direct push with bypass rights skipped every check
* Changed: comments, workflow step names and CI output across the repository are now English throughout, and the developer notes in `CLAUDE.md` were translated and trimmed. No effect on the shipped plugin

### 1.2.2
* Changed: `Tested up to` raised to WordPress 7.1
* Changed: the bundled updater's runtime floor now matches the plugin's own minimum. It bailed out below WordPress 6.8 while the plugin header has required 6.9 for several releases, so the check could never fire on a supported installation
* CI: the release manifest's fallback values for `requires` and `tested` now say 6.9 and 7.1. They only apply when the README metadata cannot be read, but a stale fallback would have published a minimum the plugin no longer supports

### 1.2.1
* Added: plugin banners (`assets/banner-1544x500.avif`, `assets/banner-772x250.avif`) — a plain `#3c4955` surface with no lettering. The update manifest already advertised these two URLs, but nothing was published under them, so the plugin card in wp-admin had a broken banner

### 1.2.0
* Added: filter requests are answered with just the swappable zones instead of a complete page. The theme header, nav menus, sidebar widgets and the entire asset pipeline are skipped; the theme's loop still runs, because in auto-inject mode it produces the result markup. Measured on a test install: 60–72 % less transferred, 5–19 % less server time. The request goes through a `/jpkpf-fragment/` URL segment rather than a query parameter, so a page cache that strips unknown parameters cannot serve a bare fragment to an ordinary visitor
* Fixed: the `noindex` for filter URLs with unknown term slugs never took effect on a Rank Math site. Rank Math discards every `wp_robots` callback before writing its own tag, so the protection added in 1.1.7 was inert on exactly the sites it was written for. The rule now also hooks Rank Math and Yoast
* Fixed: on a filter URL with no results the "no posts found" message and the whole filter bar were rendered below the footer. They now appear where the listing sits when there are results
* Fixed: using the browser's back button after filtering left the previous results on screen and the filter buttons pressed — the history entry created by the initial page load carries no state, and the handler ignored it
* Fixed: paginating a filtered list quietly stopped using AJAX. WordPress's canonical redirect did not recognise the fragment segment and appended the page it thought was missing, so the request 404'd and fell back to a full reload
* Fixed: `apcu_cache_info()` raised a PHP warning wherever APCu is loaded but inactive for the running SAPI — `apc.enable_cli` defaults to 0, so every WP-CLI call produced one. With `display_errors` on it was printed into the response body
* Added: rewrite rules are flushed once after a version change. `register_activation_hook` does not fire on an update, so new rules would otherwise never reach the database
* Added: `tests/browser-check.mjs` drives a real filter click in headless Chromium

### 1.1.7
* **Fixed:** the settings-directory containment check was a tautology — it compared `realpath( WP_CONTENT_DIR )` with itself and never referenced the directory being validated, so it never fired. Since `JPKCOM_POSTFILTER_SETTINGS_DIR` is overridable from `wp-config.php` and holds PHP files that are later `include`d, the path is now genuinely verified to resolve inside `wp-content`
* **Hardened:** `jpkcom_postfilter_build_query_args()` no longer copies `meta_key`, `meta_value`, `meta_query`, `s`, `author`, `year` and `monthnum` verbatim into `WP_Query`. Each is now coerced to a safe shape and `meta_query` is not forwarded at all; callers that need it use the `jpkcom_postfilter_query_args` filter. No current caller passed user input, so this closes a latent path rather than an exploited one
* **SEO:** filter URLs referencing term slugs that do not exist are now marked `noindex, follow`. They still return 200 with zero results, so existing links keep working, but they no longer generate unlimited indexable, self-canonicalising thin-content URLs. The check reuses the cached per-taxonomy term list and is keyed by taxonomy, never by the requested slugs
* **Added:** `tests/test-security.php` — regression tests for all of the above, each written to fail against the previous implementation. Run in CI on every pull request

### 1.1.6
* Security: update packages are now verified *before* installation — the verified file is handed to WordPress instead of being downloaded a second time, so the bytes that were checked are the bytes that get installed
* Security: a missing or unfetchable SHA-256 checksum now aborts the update instead of installing unverified code (previously it silently skipped verification)
* Security: pinned every GitHub Action to a full commit SHA and added Dependabot with a 7-day cooldown, so a moved tag can no longer change the release build
* Security: tightened which download the updater claims, so sibling plugins cannot match each other's package
* Fixed: `sprintf()` calls in the updater bound named arguments to a variadic parameter, which raises `ArgumentCountError` on PHP 8.3
* Fixed: the "View Details" modal could fail with a `TypeError` when the manifest omitted `requires_plugins`
* Performance: a failed manifest fetch is now cached for an hour instead of being retried on every admin request
* Added: CI workflow on every pull request (PHP lint, named-argument check, YAML validation, action-pinning guard)
* Housekeeping: removed stray editor backups and an unused `messages.mo` from the release package

### 1.1.5
* Raised "Tested up to" to WordPress 7.0
* Normalized license fallback defaults (updater and release workflow) to `GPL-2.0-or-later` with the HTTPS license URI

### 1.1.4
* Security: updater prefers an exact match against the manifest `download_url` over the slug heuristic, so a tampered manifest can no longer bypass the checksum gate
* Security: timing-safe checksum comparison (`hash_equals()`) with an `is_string()` guard against `hash_file()` failures
* Security: manifest fetch via `wp_safe_remote_get()` (SSRF defense-in-depth)
* Fixed PHP warning and missing contributor names in the plugin detail popup (`display_name` now provided)
* Fixed PHP warning/deprecation on `wp plugin list` by completing the `no_update` transient entry (`new_version`, `package`, `tested`, `requires_php`)

### 1.1.3
- **Plus/Minus Icon styling** — New CSS variables (`--jpkpf-pm-color`, `--jpkpf-pm-font-size`, `--jpkpf-pm-font-weight`) for independent styling of the +/– icons in filter buttons
- **Layout & Design → Filter tab** — New "Plus/Minus Icon" section with Color, Font Size, and Font Weight fields
- **Force no underline** — New checkbox in Layout & Design → Advanced that applies `text-decoration: none !important` to all filter buttons and icons, fixing themes that force underlines on links
- **Translations** — Updated de\_DE and de\_DE\_formal translations for all new strings

### 1.1.2
- **Plugin Updater** — Fixed manual ZIP upload failing with "invalid URL" error by adding `wp_http_validate_url()` check in `verify_download_checksum()`
- **Release Workflow** — Fixed ZIP packaging without top-level directory; WordPress now correctly recognises the update by using a staging directory with the plugin slug

### 1.1.1
- **.github/workflows/release.yml** — "Build Gutenberg blocks" npm ci bugfix

### 1.1.0
- **Gutenberg Blocks** — Three native blocks (Post Filter, Post List, Post Pagination) with live server-side preview, InspectorControls, and Full Site Editor support
- **Elementor Widgets** — Three widgets (Post Filter, Post List, Post Pagination) in a dedicated category, loaded only when Elementor is active
- **Oxygen Builder Elements** — Three elements (Post Filter, Post List, Post Pagination) using the OxyEl API, loaded only when Oxygen Builder Classic is active
- **Block pre-scan** — Pagination blocks can be placed above or below the list block; the plugin pre-scans the block tree (FSE templates and post content) before rendering to ensure correct query availability
- **AJAX pagination swap** — Standalone pagination elements (blocks/shortcodes/Elementor) are updated during AJAX filter requests via `data-jpkpf-pagination` attribute, keeping pagination links filter-aware
- **Pagination placeholder** — When filter selection reduces results to a single page, pagination is hidden but preserved as a DOM placeholder; it automatically reappears when filters change back to multiple pages
- **Auto-inject guard** — Prevents duplicate pagination insertion in auto-inject mode during AJAX swaps

### 1.0.0
- Initial release
- Faceted filtering for any post type and taxonomy
- Four filter layouts: bar, columns, sidebar, dropdown
- Three list layouts: cards, rows, minimal
- SEO-friendly URL schema with WordPress rewrite rules
- AJAX filtering with `history.pushState`
- Auto-inject mode for archive/blog pages with configurable pagination position
- Shortcodes: `[jpkcom_postfilter_filter]`, `[jpkcom_postfilter_list]`, `[jpkcom_postfilter_pagination]`
- Interactive shortcode builder in admin
- Custom taxonomy registration from Filter Groups admin page
- Plus/Minus interaction mode for filter buttons
- Show More button with configurable threshold
- Four predefined color schemes (Default, Dark, Contrast, Monochrome)
- Three stylesheet modes (Full, Variables only, Disabled)
- Reset button visibility modes (Always, On selection, Never)
- Bare Endpoint Behaviour (404, redirect to home, custom URL)
- Max. Filters per Group limit (URL + JS enforcement)
- Four-layer caching (object cache, transients, APCu, file cache)
- Settings import/export (JSON)
- Translations: de\_DE, de\_DE\_formal
