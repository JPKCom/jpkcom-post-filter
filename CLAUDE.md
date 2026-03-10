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
| `JPKCOM_POSTFILTER_VERSION` | `'1.0.0'` | Plugin version |
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
- Request adds `?jpkpf_ajax=1`
- JS extracts `[data-jpkpf-results]` from response and swaps into DOM
- `swapPagination()` updates standalone `[data-jpkpf-pagination]` elements outside the results zone
- Auto-inject mode (`[data-jpkpf-wrapper]`) skips standalone pagination insertion to prevent duplicates

---

## Security Checklist

- All outputs: `esc_html()`, `esc_url()`, `esc_attr()`, `wp_kses_post()`
- All forms: `wp_nonce_field()` + `check_admin_referer()`
- All AJAX: `check_ajax_referer()` + `current_user_can()`
- `declare(strict_types=1)` in every PHP file
- Typed function signatures throughout
- Settings path validated against `WP_CONTENT_DIR`
- Settings cache dir protected by `.htaccess`

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
