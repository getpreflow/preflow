# Groundwork (CSP, self-hosted htmx, Folio basics) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preflow sends a strict, nonce-based CSP and makes no external requests for htmx. Folio's admin runs under that CSP, gains `boolean`/`date`/`select` fields, renders any content type that declares a frontend route, and gets a configurable list with manual ordering.

**Architecture:** Six independent changes, in the order of the spec's table.

- **Core:** a `SecurityHeadersMiddleware` reads the request nonce from `AssetCollector`, plus a `csp_nonce()` Twig function.
- **htmx:** ships a vendored, pinned `htmx.min.js`. A small `HtmxAssets` service inlines it through `AssetCollector`, either always or on demand.
- **Folio:** reads new top-level type keys (`frontend`, `list`) through `TypeCatalog`. `FrontendResolver` resolves across all frontend-enabled types.

**Tech Stack:** PHP ≥ 8.4 (runs on 8.5), PHPUnit 11, Twig, htmx 2.0.x (vendored, 0BSD).

**Spec:** `docs/superpowers/specs/2026-10-19-groundwork-csp-htmx-folio-basics-design.md`

**Branch:** `feat/groundwork` from `main`.

## Global Constraints

- **Code conventions:**
  - Every PHP file: `declare(strict_types=1);`. Classes are `final` and value objects
    `final readonly`. Use constructor promotion with `private readonly`.
  - Tests are named `test_snake_case`.
  - Admin JS is vanilla ES5 in the existing IIFE (`packages/folio/assets/admin.js`), hooked
    on `data-*` attributes, with no build step.
- **CSP:**
  - The default policy contains no `'unsafe-inline'`, no `'unsafe-eval'` and no
    third-party host.
  - `script-src` and `style-src` get `'nonce-{AssetCollector::getNonce()}'` appended.
  - In debug mode (`app.debug` truthy) the header name is
    `Content-Security-Policy-Report-Only` (this spec open item is decided here). Otherwise
    it is `Content-Security-Policy`.
- **htmx:**
  - No output may contain `unpkg.com`.
  - The include mode comes from `config/htmx.php` key `include`: `always` (the default when
    the file is absent), `on-demand` or `never`.
  - The skeleton ships `on-demand`.
- **Folio JSON keys** are top-level: `frontend: {route, home?, template?, published?: {field, value}}`
  and `list: {columns?, sort?, orderable?}`.
- **Backward compatibility:**
  - With no type declaring `frontend` while a `page` type exists, the behaviour stays as
    today: `/{slug}` on `page`, `home`, `status = published`, template
    `@folio/frontend/page.twig`.
  - The existing Folio suite stays green (adjust only where this plan changes behaviour on
    purpose, and say so in the commit).
- **Commands:**
  - full suite: `vendor/bin/phpunit`;
  - per package: `--testsuite Core|Htmx|Folio|View|Examples`.

## Review Focus

1. **A hand-written `<script>` in an app layout** (no nonce) is blocked in production. The
   expected outcome is a clear way to fix it: `csp_nonce()` exists and the README says to
   use it (Task 1: `test_csp_nonce_function_matches_header`).
2. **Two types both claim `home`, or the same route pattern.** The app must fail at boot
   with a message naming both types, not silently pick one (Task 5:
   `test_duplicate_home_is_a_boot_error`, `test_duplicate_pattern_is_a_boot_error`).
3. **A `select` field whose stored value is no longer among its options** (options edited
   after content existed). The edit form must still render: the stale value is shown as
   selected, not dropped silently, and saving forces a valid choice (Task 4:
   `test_select_renders_stale_value_and_rejects_it_on_save`).
4. **Reordering when records have no `_order` yet** (orderable turned on for existing
   content). The list must be stable (by `_order`, then id) and the first move must
   persist (Task 6: `test_reorder_initializes_missing_order`).
5. **An unchecked boolean on update.** It must save `false`, not keep the old `true`
   (Task 4: `test_unchecked_boolean_saves_false_on_update`).

---

### Task 1: Security headers middleware and `csp_nonce()`

**Files:**
- Create: `packages/core/src/Http/SecurityHeadersMiddleware.php`
- Modify: `packages/core/src/Application.php`
  - `boot()`: register the middleware right after the `JsonBodyMiddleware` pipe, so it
    wraps all later middleware.
  - `bootViewLayer()`: add the Twig function `csp_nonce` via
    `$engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(name: 'csp_nonce', callable: fn () => $assets->getNonce(), isSafe: true))`.
- Create: `packages/skeleton/config/security.php`, containing the spec's default array
  verbatim (a commented reference copy).
- Modify: `packages/view/README.md`. Replace the "build the header yourself" paragraph
  with: Preflow sends it; `config/security.php` overrides it; use
  `nonce="{{ csp_nonce() }}"` on hand-written `<script>`/`<style>`.
- Test: `packages/core/tests/Http/SecurityHeadersMiddlewareTest.php`

**Interfaces:**
- Produces:
  - `SecurityHeadersMiddleware implements MiddlewareInterface`, constructor
    `(\Preflow\View\AssetCollector $assets, array $config, bool $reportOnly, bool $isHttpsAware = true)`.
  - `public static function defaults(): array` returns the spec's array
    (`csp`, `report_only => false`, `headers`, `hsts => true`).
  - **Config merge:** an app's `config/security.php` is merged per directive. A directive
    key that is present replaces that directive's list; absent directives keep their
    defaults. `headers` merges by header name, and a `null` value removes that header.
  - **Header rules:**
    - never overwrite a header already on the response;
    - HSTS is `max-age=31536000; includeSubDomains`, only when the request URI scheme is
      `https` or `X-Forwarded-Proto: https`.
  - **Report-only:** when `$reportOnly` is set, or config `report_only` is true, the
    header name is `Content-Security-Policy-Report-Only`. Boot passes
    `$reportOnly = debug`.
  - **Twig:** `csp_nonce()` returns the same nonce as the header.

- [ ] **Step 1: Write the failing tests** (a unit test with a stub handler, plus one boot test using the temp-app pattern from `packages/core/tests/ConfigProviderBootTest.php`):
  - `test_default_policy_is_strict_and_carries_nonce`:
    - the header contains `default-src 'self'`, `object-src 'none'` and `frame-ancestors 'self'`;
    - `script-src 'self' 'nonce-{N}'` and `style-src 'self' 'nonce-{N}'`, where N = `$assets->getNonce()`;
    - no `unsafe-inline`.
  - `test_other_security_headers_are_sent`: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
  - `test_config_overrides_merge_per_directive`: config `['csp' => ['img-src' => ["'self'", 'https://cdn.x']]]` → `img-src` replaced, `script-src` still the default plus nonce.
  - `test_existing_header_is_not_overwritten`: a handler that sets `Content-Security-Policy: default-src 'none'` → unchanged.
  - `test_hsts_only_on_https`: an http request has no `Strict-Transport-Security`; https and `X-Forwarded-Proto: https` have it.
  - `test_report_only_mode`: `$reportOnly = true` → `Content-Security-Policy-Report-Only` is present and `Content-Security-Policy` is absent.
  - `test_csp_nonce_function_matches_header` (boot test, `debug 0`, a page template `app/pages/index.twig` with `<script nonce="{{ csp_nonce() }}"></script>`): the GET `/` response body contains `nonce="{N}"` and the header contains `'nonce-{N}'`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Core`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement the middleware, its boot wiring, `csp_nonce`, the skeleton file and the README paragraph.**
- [ ] **Step 4: Run `vendor/bin/phpunit`.** Expected: PASS. Folio and Examples tests run in debug, so they get report-only and are unaffected.
- [ ] **Step 5: Commit** `feat(core): send security headers and a nonce-based CSP`.

### Task 2: Self-hosted htmx with include modes

**Files:**
- Create: `packages/htmx/assets/htmx.min.js`. Download the latest 2.0.x once, e.g.
  `curl -fsSL https://unpkg.com/htmx.org@<v>/dist/htmx.min.js` with `<v>` from
  `npm view htmx.org@2 version`. This is a one-time vendoring step, not a runtime request.
- Create: `packages/htmx/assets/LICENSE` (htmx 0BSD text) and
  `packages/htmx/assets/VERSION` (the exact version).
- Create: `packages/htmx/src/HtmxAssets.php`
- Modify: `packages/htmx/src/HtmxDriver.php`. `assetTag()` returns `''` and is
  `@deprecated` (the interface method stays for compatibility).
- Modify: `packages/htmx/src/HtmxExtensionProvider.php`. The constructor takes
  `?HtmxAssets $assets = null`; `post()`, `get()`, `on()` and `actionUrl()` call
  `$this->assets?->require()`; add `public function require(): string`, which returns `''`.
- Modify: `packages/core/src/Application.php` (htmx block). Read `config/htmx.php`, build
  `HtmxAssets`, bind it, and call `require()` immediately for `always`. Remove the
  `addHeadTag($htmxDriver->assetTag())` call.
- Create: `packages/skeleton/config/htmx.php` → `return ['include' => 'on-demand'];`
- Test: `packages/htmx/tests/HtmxAssetsTest.php`, `packages/core/tests/HtmxIncludeModeTest.php`

**Interfaces:**
- Produces:
  - `final class HtmxAssets`, constructor
    `(\Preflow\View\AssetCollector $assets, string $mode, string $scriptPath = __DIR__ . '/../assets/htmx.min.js')`.
  - `require(): void`. It is idempotent, and a no-op when `$mode === 'never'`. It adds:
    - via `addHeadTag`, a
      `<meta name="htmx-config" content='{"inlineScriptNonce":"N","inlineStyleNonce":"N"}'>`
      (N = `$assets->getNonce()`, JSON-encoded with `JSON_HEX_APOS`);
    - via `addJs(file contents, JsPosition::Head, key: 'preflow-htmx')`, the script.
  - `isRequired(): bool`.
  - An invalid mode throws `\InvalidArgumentException` naming `config/htmx.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_require_inlines_script_once_with_nonce`: `require()` twice → `renderHead()` contains exactly one `<script nonce="N">`, and the htmx script content appears once (assert a substring of the vendored file, e.g. `htmx`); the meta `htmx-config` has `"inlineScriptNonce":"N"`.
  - `test_never_mode_adds_nothing`.
  - `test_invalid_mode_throws`.
  - `HtmxIncludeModeTest` (temp app, `debug 0`; page `index.twig` with no `hd` use; page `uses.twig` that renders `{{ hd.require() }}`):
    - `test_always_mode_is_default_without_config`: GET `/` body contains `htmx-config` and no `unpkg.com`.
    - `test_on_demand_includes_only_when_used`: with `include => on-demand`, `/` has no `htmx-config` but `/uses` does.
    - `test_never_mode`: `/uses` has no `htmx-config`.
    - `test_fragment_contract_uses_page_nonce`: on `/uses`, the nonce in `htmx-config` equals the `'nonce-…'` in the CSP header.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Htmx` and `--testsuite Core`. Expected: FAIL.
- [ ] **Step 3: Vendor the file, then implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS. Then run `grep -rn "unpkg.com" packages/*/src packages/*/templates`. Expected: no hits.
- [ ] **Step 5: Commit** `feat(htmx): self-host htmx and include it always, on demand, or never`.

### Task 3: Folio admin under the strict CSP

**Files:**
- Modify:
  - `packages/folio/templates/admin/_layout.twig` and `login.twig`: both inline
    `<script>` get `nonce="{{ csp_nonce() }}"`. These run in action mode, which bypasses
    the asset collector, so the function is required.
  - `drawer_saved.twig`: the same nonce on its `<script>`.
  - `list.twig`: `onsubmit="return confirm('Delete this record?');"` becomes
    `data-confirm="Delete this record?"`.
- Modify: `packages/folio/assets/admin.js`
  - A delegated `submit` listener on `form[data-confirm]` that calls
    `confirm(form.getAttribute('data-confirm'))` and `preventDefault()` on cancel.
  - **Preview full render (decided here; this spec open item):**
    - replace `frame.srcdoc = html` with a hidden `<form method="post" target="{frame.name}" action="{previewUrl}">`
      that carries a clone of the edit form's fields, submitted so the iframe navigates
      to the preview response, which then has its own CSP header and nonce;
    - region patching keeps using `fetch()` and `frame.contentDocument` (same origin,
      patched regions contain no scripts);
    - the iframe gets `name="folio-preview-frame"`.
- Modify: `packages/folio/src/Http/PreviewController.php`. The response is unchanged
  (full HTML), and it must also accept the form-POST (no `Accept` header dependency).
- Test:
  - `packages/folio/tests/Assets/CspGuardTest.php`
  - extend `packages/folio/tests/Assets/AdminJsTest.php`

**Interfaces:**
- Consumes: `csp_nonce()` (Task 1).
- Produces: admin templates with no `on[a-z]+=` attributes, no `style=` attributes, and no
  `<script>`/`<style>` without a nonce, except `<script type="application/json">` data
  blocks and `<script src=…>`.

- [ ] **Step 1: Write the failing tests:**
  - `CspGuardTest::test_admin_templates_have_no_inline_handlers_or_styles`. It scans every `packages/folio/templates/**/*.twig` plus the HTML-building strings in `packages/folio/src/**/*.php`, and fails listing file:line for:
    - `/\son[a-z]+\s*=/i`;
    - `/\sstyle\s*=/i`;
    - `<script` without `nonce=`, `src=` or `type="application/json"`;
    - `<style` without `nonce=`.
  - `AdminJsTest::test_confirm_is_handled_by_data_attribute`: `admin.js` contains `data-confirm`.
  - `AdminJsTest::test_preview_does_not_use_srcdoc`: `admin.js` does not contain `srcdoc`.
  - In `FolioAppTest`: `test_layout_inline_script_carries_request_nonce`. GET `/folio` → every `<script>` without `src`/`type="application/json"` has `nonce="X"`, and X appears in the `Content-Security-Policy-Report-Only` header (the test runs in debug).
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL, with the guard listing the existing violations.
- [ ] **Step 3: Implement the template and JS changes.**
- [ ] **Step 4: Run `vendor/bin/phpunit --testsuite Folio`.** Expected: PASS. Existing preview tests stay green. Then check by hand: run `php -S 127.0.0.1:8000 examples/folio-demo/public/index.php` with `APP_DEBUG=0`. In a browser, open the page editor's live preview: it renders, the console shows no CSP violation, and typing updates the preview.
- [ ] **Step 5: Commit** `fix(folio): admin runs under the strict CSP`.

### Task 4: Field types `boolean`, `date`, `select`; rules wiring; app field types; monospace text

**Files:**
- Create: `packages/folio/src/Field/Types/BooleanFieldType.php`, `DateFieldType.php`, `SelectFieldType.php`
- Modify:
  - `packages/folio/src/FolioServiceProvider.php`:
    - register the three types;
    - after the built-ins, register each class in `config/folio.php` `field_types`
      (resolved via `$c->get($class)`), replacing a built-in with the same key.
  - `packages/folio/src/Http/AdminController.php`: build the rules passed to
    `DataManager::saveType($record, true, $rules)` per field as
    `array_values(array_unique([...$fieldDef->validate, ...$fieldType->rules($fieldDef->config)]))`.
    The keys must be field names, because `saveType` merges with `array_merge` and replaces
    per field.
  - `packages/folio/src/Field/Types/TextFieldType.php`: when `$ctx->config['monospace']`
    is true, the textarea gets the class `folio-input-mono`, styled in `admin.css`.
  - `examples/folio-demo/config/models/page.json`: `status` becomes
    `{"type": "select", "options": {"draft": "Draft", "published": "Published"}, "validate": ["required"]}`.
- Test: `packages/folio/tests/Unit/Field/BooleanFieldTypeTest.php`, `DateFieldTypeTest.php`, `SelectFieldTypeTest.php`; additions to `FolioAppTest`

**Interfaces:**
- Produces:
  - `BooleanFieldType`, key `boolean`.
    - `normalizeInput(mixed $raw, array $config): bool`: true for `'1'`, `'on'`, `'true'`
      or `true`, and **false for a missing or null value**.
    - The editor is a checkbox with a hidden `0` sibling, so an unchecked box submits.
    - `renderFrontend` returns `Yes`/`No`.
  - `DateFieldType`, key `date`.
    - It stores `Y-m-d`, or ISO 8601 `Y-m-d\TH:i` when `config.time === true`.
    - The input is `date` or `datetime-local`.
    - `rules()` returns `['regex:/^\d{4}-\d{2}-\d{2}$/']`, or the datetime variant; an
      empty string is allowed unless `required`.
  - `SelectFieldType`, key `select`.
    - `config.options` is either a map `value => label` or a list (value = label).
    - `config.display === 'radio'` renders radios.
    - `rules()` returns `['in:' . implode(',', values)]`.
    - `renderFrontend` returns the label.
    - A stored value not among the options renders as an extra selected option labelled
      `{value} (no longer an option)`.

- [ ] **Step 1: Write the failing tests:**
  - Unit tests per type: normalize, editor HTML (name, type and selected/checked state), `renderFrontend`, `rules`.
  - `test_unchecked_boolean_saves_false_on_update`: create a record with `featured=1`, then update with only the hidden `0` (and, as a second case, with the field absent) → stored `false` both times.
  - `test_select_renders_stale_value_and_rejects_it_on_save`: a stored `status = archived` → the edit form contains `archived (no longer an option)` selected; POST with `archived` → 422 with an error on `status`.
  - `test_type_rules_are_enforced_on_save`: `select` invalid → 422 even without `validate: ["in:…"]` in the JSON.
  - `test_app_field_type_replaces_builtin`: `config/folio.php` `field_types => [TestStringType::class]` (a fixture with key `string` whose editor emits `data-test-string`) → the create form contains `data-test-string`.
  - `test_text_monospace_option`: `config.monospace` → the textarea has `folio-input-mono`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `vendor/bin/phpunit --testsuite Folio` and `--testsuite Examples`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): boolean, date and select fields; type rules and app field types`.

### Task 5: Generic frontend rendering

**Files:**
- Modify: `packages/folio/src/Content/TypeListing.php`. Add
  `public ?array $frontend = null, public array $list = []`, populated by `TypeCatalog`
  from the raw JSON. Add `TypeCatalog::get(string $key): ?TypeListing`.
- Create: `packages/folio/src/Content/FrontendRoute.php`, a value object:
  `type`, `pattern`, `regex`, `paramNames`, `staticSegments`, `home`, `template`,
  `publishedField`, `publishedValue`.
- Modify:
  - `packages/folio/src/Content/FrontendResolver.php`. The constructor becomes
    `(DataManager $dm, list<FrontendRoute> $routes)` and it gains
    `static fromCatalog(TypeCatalog $c, DataManager $dm): self`.
  - `resolve(string $path): ?ResolvedRecord`, where `ResolvedRecord` (new, `final readonly`)
    holds `DynamicRecord $record, FrontendRoute $route`.
- Create: `packages/folio/src/Content/ResolvedRecord.php`
- Modify: `packages/folio/src/Http/FrontendController.php`
  - Render `$route->template` when the engine can find it, else
    `@folio/frontend/{type}.twig`, else `@folio/frontend/page.twig`. Use
    `TemplateEngineInterface::exists()` if present; otherwise check via the loader (look it
    up).
  - Variables: `record`, `rendered`, `type`, `preview => false`.
- Modify: `packages/folio/src/Http/PreviewController.php` and `AdminController`
  (`previewable`).
  - Previewable means the type has a `FrontendRoute`.
  - The preview renders the same template chain with `preview => true`.
- Modify: `packages/folio/templates/frontend/page.twig`. Emit the `data-folio-field="…"`
  wrappers only when `preview` is true.
- Modify: `packages/folio/src/FolioServiceProvider.php`. Drop `$frontendType = 'page'` and
  bind `FrontendResolver` via `fromCatalog`. Boot errors are a
  `\Preflow\Folio\Content\FrontendConfigException` (new) raised at registration, with
  these messages:
  - `Folio types "{a}" and "{b}" both declare frontend.home.`
  - `Folio types "{a}" and "{b}" both declare the frontend route "{pattern}".`
- Test: `packages/folio/tests/Unit/Content/FrontendResolverTest.php`; additions to `FolioAppTest`

**Interfaces:**
- Consumes: `PatternCompiler::compile(string): array{regex, paramNames, isCatchAll}` (existing).
- Produces:
  - **Ordering:** more static segments first, then non-catch-all before catch-all, then
    longer pattern first.
  - **Lookup:** `''` maps to the record whose `home` value matches. The lookup is
    `queryType(type)->where(param, value)` for each param, plus
    `->where(publishedField, publishedValue)`.
  - **Legacy:** when no type has `frontend` and the type `page` exists, one implicit route
    `{type: page, pattern: '/{slug}', home: 'home', template: null, published: status/published}`.

- [ ] **Step 1: Write the failing tests:**
  - `test_two_types_with_different_patterns_resolve`: `page` `/{slug}` and `article` `/blog/{slug}` → `/blog/x` resolves the article and `/about` the page.
  - `test_static_segments_win`: `/blog/{slug}` beats `/{...path}`.
  - `test_duplicate_home_is_a_boot_error` and `test_duplicate_pattern_is_a_boot_error`: the exact messages.
  - `test_unpublished_is_404` and `test_custom_published_field`: `published: {field: "visible", value: "yes"}`.
  - `test_legacy_page_without_frontend_block_still_works`: the existing `test_create_then_render_on_frontend` stays green unchanged.
  - `test_userland_template_is_used`: `frontend.template = "folio/article.twig"` exists in the temp app root → its marker text renders.
  - `test_public_render_has_no_field_markers` (the public GET has no `data-folio-field`), while the existing `test_preview_output_carries_field_markers` stays green.
  - `test_article_type_is_previewable`: the edit form shows the preview button for `article` once it has `frontend`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `vendor/bin/phpunit --testsuite Folio` and `--testsuite Examples`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): render any type that declares a frontend route`.

### Task 6: Configurable admin list, manual ordering, docs

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php` (`list()`).
  - Columns come from `TypeListing::$list['columns']`. The default is the first three
    fields whose type is `string`, `number`, `select`, `boolean` or `date`.
  - Each cell is rendered through the field type's `renderFrontend` (escaped).
  - Sort: `$list['sort']` (a `-` prefix means descending) via
    `QueryBuilder::orderBy(field, SortDirection)`.
  - When `orderable` is set, sort by `_order` ascending, then id.
- Add `reorder(ServerRequestInterface $request): ResponseInterface`:
  - POST `{prefix}/{type}/{id}/move` with `direction=up|down`;
  - swaps `_order` with the neighbour in the current order;
  - **first initializes `_order` = position × 10 for every record when any record lacks it**;
  - redirects with 302 to the list.
- Modify:
  - `store()`: when `orderable`, set `_order` = max + 10.
  - `_order` is a system key, never rendered as a form field, and passes validation
    untouched (it is not in `fields`).
- Modify: `packages/folio/src/Routing/FolioRoutes.php` (the move route, before
  `{type}/{id}`).
- Modify: `packages/folio/templates/admin/list.twig`
  - Dynamic `<th>` from the column labels (`label` or the humanized name).
  - When orderable, up/down `<form method="post">` buttons (CSRF field included) with
    `aria-label="Move up"`/`"Move down"`.
- Modify: `packages/folio/README.md`. Rewrite the "Status" section to match reality:
  - rich text, asset, relation, matrix and live preview are shipped;
  - plus this plan's additions: CSP, field types, `frontend`, `list`.
  - Document the `frontend` and `list` keys.
- Test: additions to `FolioAppTest`

**Interfaces:**
- Consumes: `TypeListing::$list` (Task 5) and the field types (Task 4).

- [ ] **Step 1: Write the failing tests:**
  - `test_list_uses_configured_columns`: `list.columns = ["title","featured"]` → the header has Title and Featured, the `featured` cell shows `Yes`, and there is no Slug column.
  - `test_list_default_columns_without_config`: a note type shows its first three scalar fields.
  - `test_list_default_sort_descending`: `sort: "-title"` → B before A.
  - `test_orderable_move_down_persists`: three records A, B, C created in order; move A down → order B, A, C on the list page.
  - `test_reorder_initializes_missing_order`: records stored without `_order` (written via DataManager), then move the second up → it becomes first, and every record now has an `_order`.
  - `test_new_record_appends_to_end`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement**, including the README rewrite.
- [ ] **Step 4: Run `vendor/bin/phpunit`.** Expected: PASS for the whole suite.
- [ ] **Step 5: Commit** `feat(folio): configurable list columns, sorting and manual ordering`.

---

## After the plan

- Add this plan's row to `docs/superpowers/plans/README.md`.
- Final report: confirm by hand in a browser that the folio-demo frontend and admin
  (`APP_DEBUG=0`) show **no** CSP violations and make no external request.
