# JPKCom Post Filter

**Plugin Name:** JPKCom Post Filter  
**Plugin URI:** https://github.com/JPKCom/jpkcom-post-filter  
**Description:** Faceted navigation and filtering of Posts, Pages, and Custom Post Types via WordPress taxonomies — SEO-friendly URLs, AJAX updates, and full screen reader support.  
**Version:** 1.1.0  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com/  
**Contributors:** JPKCom  
**Tags:** filter, taxonomy, faceted search, custom post type, AJAX  
**Requires at least:** 6.9  
**Tested up to:** 6.9  
**Requires PHP:** 8.3  
**Stable tag:** 1.1.0  
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
