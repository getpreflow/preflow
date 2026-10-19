# Groundwork: Security Headers, Self-hosted htmx, Folio Basics — Design

**Date:** 2026-10-19
**Packages:** `preflow/core`, `preflow/htmx`, `preflow/folio`, `preflow/skeleton`
**Status:** Approved (2026-10-19).
**Context:** smeyer.me will claim "no cookies · no trackers · zero external requests" in its
footer and be fully managed through Folio. Today the framework cannot back the claim, and Folio
cannot model more than one page-like type. This pass fixes the foundations everything later
(content i18n, blocks, the site, the editor) builds on. It is deliberately a set of small,
independent changes; each can be built and merged on its own.

| # | Change | Package |
|---|---|---|
| 1 | Security headers + Content-Security-Policy with the request nonce | core |
| 2 | htmx served from Preflow itself, included only when needed | htmx, core |
| 3 | Folio admin runs under the strict CSP (no inline handlers/styles) | folio |
| 4 | New field types: `boolean`, `date`, `select`; `FieldType::rules()` wired in; app-registered field types | folio |
| 5 | Generic frontend rendering: any type can declare a route and a template | folio |
| 6 | Configurable admin list: columns, default sort, manual ordering | folio |

## Non-goals

- Content translation (next spec: Folio content i18n, built on `preflow/i18n`).
- Blocks, singletons (spec after that).
- Draft/publish, asset library, click-to-edit (editor-experience spec).
- Rewriting the Folio admin onto htmx. It keeps working as plain forms here; htmx is introduced
  in the editor-experience spec.

---

## 1. Security headers and CSP (`preflow/core`)

### Problem
`AssetCollector` puts a per-request nonce on every `<style>`/`<script>` it emits, but no
`Content-Security-Policy` header is ever sent, so the nonces protect nothing. There are no
other security headers either.

### Design
New `Preflow\Core\Http\SecurityHeadersMiddleware`, registered by core during boot (like the
session and CSRF middleware), configured by an optional `config/security.php`:

```php
return [
    'csp' => [
        'default-src' => ["'self'"],
        'script-src'  => ["'self'"],      // nonce added automatically
        'style-src'   => ["'self'"],      // nonce added automatically
        'img-src'     => ["'self'", 'data:'],
        'font-src'    => ["'self'"],
        'media-src'   => ["'self'"],
        'connect-src' => ["'self'"],
        'frame-src'   => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'form-action' => ["'self'"],
        'base-uri'    => ["'self'"],
        'object-src'  => ["'none'"],
    ],
    'report_only' => false,
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ],
    'hsts' => true, // Strict-Transport-Security, only sent on HTTPS requests
];
```

- The values above are the **defaults** when the file is absent. They are strict: no
  `'unsafe-inline'`, no `'unsafe-eval'`, no third-party hosts. An app relaxes them by
  overriding keys.
- The middleware reads the nonce from `AssetCollector::getNonce()` and appends
  `'nonce-…'` to `script-src` and `style-src`.
- It does not overwrite a header a controller has already set, so a route can send its own
  policy.
- With `APP_DEBUG` on, the debug toolbar and dev error pages must still work: either they
  are made CSP-clean, or the middleware switches to `Content-Security-Policy-Report-Only` in
  debug mode. Builder's call; document which.

### Consequence to verify
Strict `style-src` without `'unsafe-inline'` also blocks `style="…"` **attributes**. Every
framework-generated markup path (component wrapper, error boundary, debug toolbar, form
renderer, Folio) must be checked for inline `style=` and `on*=` attributes.

## 2. Self-hosted htmx (`preflow/htmx`, `preflow/core`)

### Problem
`HtmxDriver::assetTag()` returns `<script src="https://unpkg.com/htmx.org@2">`, and core adds
it to the `<head>` of **every** page. That is an external request (and an unpinned version) on
pages that may not use htmx at all.

### Design
- Vendor a pinned htmx 2.x build into `packages/htmx/assets/htmx.min.js` with its license
  file and a note of the version.
- The driver emits it **inline** through `AssetCollector` (same nonce and dedup as all
  component JS), in line with Preflow's "all CSS/JS inline" principle. No new route, no
  extra request.
- Include mode, from an optional `config/htmx.php`:
  - `'include' => 'always'`: current behaviour, the default when the file is absent, so
    existing apps don't break;
  - `'include' => 'on-demand'`: htmx is added only when a template used an `hd.*` helper or
    called `{{ hd.require() }}` during the render. Assets are injected after rendering, so
    this works;
  - `'include' => 'never'`.
- The skeleton ships `config/htmx.php` with `on-demand`.
- CSP interplay: scripts and styles inside **fragment** responses carry the fragment
  request's nonce, but the browser checks them against the **page's** policy. Set
  `htmx.config.inlineScriptNonce` (and `inlineStyleNonce`) to the page nonce, so htmx tags
  the elements it creates with a nonce the page accepts. Disable
  `htmx.config.includeIndicatorStyles` or give it the nonce. Cover this with an
  integration test that swaps a fragment containing component JS under the strict policy.

## 3. Folio admin under the strict CSP (`preflow/folio`)

There are nine inline `on*=` handlers, `style=` attributes and `<script>`/`<style>` tags in
`templates/admin/{_layout,_drawer_layout,drawer_saved,login,list}.twig` and
`src/Field/Types/MatrixFieldType.php`.

- Move the behaviour into `assets/admin.js`, hooked on `data-*` attributes (e.g.
  `data-confirm="Delete this record?"` replaces `onsubmit="return confirm(…)"`), and move
  styles into `admin.css` classes.
- Admin CSS/JS are already same-origin files (`{prefix}/_assets/…`), so `'self'` covers
  them.
- **Live preview (srcdoc iframe).** A `srcdoc` document inherits the admin page's CSP, so
  the preview HTML's own nonced tags would be blocked. The preview must keep working under
  the strict policy. Options for the builder:
  - render the preview through a same-origin URL the iframe navigates to (POST via a form
    targeting the iframe), so the preview gets its own response and nonce;
  - or have `PreviewController` render with the requesting admin page's nonce.

  Pick one, test it, and record the reasoning in the plan.

## 4. Field types (`preflow/folio`)

| Type | Editor | Storage | Notes |
|---|---|---|---|
| `boolean` | checkbox | `true`/`false` | An unchecked box submits nothing; `normalizeInput` maps a missing value to `false`. |
| `date` | `<input type="date">` | `Y-m-d` string | Rule: a valid date. Optional `"time": true` → `datetime-local`, stored as ISO 8601. |
| `select` | `<select>`, or radios when `"display": "radio"` | the option value | `"options": {"draft": "Draft", "published": "Published"}` (or a plain list). Automatically adds the `in:` rule. |

- **Wire in `FieldType::rules()`.** `AdminController` validation merges the type's
  `rules($config)` with the field's `validate` list. Today `rules()` exists but nothing
  calls it.
- **App-registered field types.** `config/folio.php` `'field_types' => [App\Folio\ColorField::class]`.
  These are resolved from the container and registered after the built-ins, and they may
  replace a built-in by key. This is how projects add types without rebinding the registry.
- **`text` gets an optional `"monospace": true`** (a code-sample field, needed by smeyer.me).
  This is an editor class only; storage is unchanged.
- The demo `page.json` switches `status` to `select`.

## 5. Generic frontend rendering (`preflow/folio`)

### Problem
The frontend is hardcoded: `$frontendType = 'page'`, `slug` lookup, fixed template
`@folio/frontend/page.twig`, `'' → 'home'`.

### Design
A type opts into public rendering with a `frontend` block in its JSON:

```json
"frontend": {
    "route": "/{slug}",
    "home": "home",
    "template": "folio/page.twig",
    "published": { "field": "status", "value": "published" }
}
```

- `route`: a pattern compiled by the existing `PatternCompiler` (`{field}` = one segment,
  `{...field}` = catch-all). Parameter names are record fields used as the lookup.
- `home` (optional): the value that `/` resolves to. Only one type may declare it.
- `template`: a **userland** Twig path, per the walking-skeleton direction that frontend
  templates live in normal views. Fallbacks:
  1. `@folio/frontend/{type}.twig`;
  2. `@folio/frontend/page.twig`.
- `published`: which field/value makes a record public. It defaults to `status` /
  `published`. The editor-experience spec replaces this with real draft/publish behind the
  same resolver interface.
- `FrontendResolver` collects all types with a `frontend` block and orders patterns by
  specificity (more static segments first). It **fails at boot** with a clear message when
  two types claim an identical pattern or two declare `home`.
- The single lowest-priority catch-all stays. App routes still always win.
- Template variables (unchanged names, so existing overrides keep working):
  - `record`
  - `rendered` (field → safe HTML)
  - `type`
- The preview's `data-folio-field` markers are emitted only in preview renders, never on the
  public page.
- **Backward compatibility:** if no type declares `frontend` but a `page` type exists,
  behave as today.
- **Locale prefix:** patterns are matched against the path *after* `LocaleMiddleware` has
  handled any locale prefix, so the content-i18n spec can add per-locale routing without
  changing this.

## 6. Admin list (`preflow/folio`)

Optional `list` block in the type JSON:

```json
"list": { "columns": ["title", "status"], "sort": "title", "orderable": false }
```

- `columns`: field names shown in the list. The default is the first three
  scalar/select/boolean/date fields. Cells render through the field type, so a select shows
  its label and a boolean shows yes/no.
- `sort`: the default order, via `QueryBuilder::orderBy`. Prefix with `-` for descending.
- `orderable: true` adds a hidden integer `_order` field, up/down buttons (plain POST
  forms, no JS required) and sorts by it. smeyer.me's apps and forces need this. New
  records go to the end.
- The hardcoded Title/Slug/Status header in `list.twig` goes away.

---

## New / affected files

- core:
  - `src/Http/SecurityHeadersMiddleware.php`
  - `Application.php` (boot it, htmx include mode)
- htmx:
  - `assets/htmx.min.js` and its license file
  - `HtmxDriver::assetTag()` replaced by inline emission
  - `HtmxExtensionProvider` (`hd.require()`, usage tracking)
- folio:
  - `src/Field/Types/{BooleanFieldType,DateFieldType,SelectFieldType}.php`
  - `FieldTypeRegistry` and provider (config field types)
  - `AdminController` (rules merge, list columns/sort/order, reorder action)
  - `FolioRoutes` (reorder route)
  - `FrontendResolver` and `FrontendController` (multi-type)
  - `TypeCatalog` (frontend/list metadata)
  - templates and `admin.js`/`admin.css` (CSP clean-up)
  - README "Status" section (out of date; refresh it while here)
- skeleton:
  - `config/security.php`
  - `config/htmx.php`
- `examples/folio-demo`: config updated to the new type keys.

## Testing

- **Security headers:**
  - the default policy is present on HTML responses;
  - the nonce in the header equals the nonce on the emitted tags;
  - config overrides merge;
  - an existing header is not overwritten;
  - HSTS is sent only on HTTPS;
  - report-only mode.
- **htmx:**
  - no `unpkg.com` anywhere in output (assert on all integration responses);
  - `always`, `on-demand` and `never` modes;
  - a fragment swap test under the strict CSP.
- **Folio CSP:** a test that renders every admin template and fails on any `on[a-z]+=` or
  `style=` attribute or un-nonced `<script>`/`<style>`. This guards the clean-up from
  regressing.
- **Field types:** normalize, storage and editor rendering for each type; the `select`
  `in:` rule enforced on save; an app-registered type replacing a built-in.
- **Frontend:**
  - two types with different patterns both resolve;
  - specificity order;
  - duplicate pattern or `home` → boot error;
  - the unpublished filter;
  - the legacy no-`frontend` fallback;
  - userland template precedence.
- **List:**
  - configured columns;
  - default sort;
  - reorder up/down persists and renders in order;
  - a new record is appended.
- **Regression:** the existing Folio suite (152 tests) stays green.

## Open for the builder

- Whether the debug toolbar is made CSP-clean or debug mode uses report-only (§1).
- Which preview approach satisfies the strict CSP (§3).
- Exact htmx version to vendor (latest 2.x at build time).
