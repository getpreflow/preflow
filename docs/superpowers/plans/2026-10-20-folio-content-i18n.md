# Folio Content i18n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Field-level content translation. One record holds `{"en": …, "de": …}` maps for translatable fields; the admin edits one language at a time; the public site serves `/` and `/de/…` with translated slugs and hreflang; `preflow/i18n` stops setting cookies and escapes `t()` params.

**Architecture:**
- `preflow/data` gains `Locales` (available, default, fallback), carried on `TypeDefinition`, plus `LocaleValue`, a static helper for read tolerance, picking and the fallback chain.
- `DynamicRecord::localized()` returns a `LocalizedRecord` view.
- `QueryBuilder::locale()` marks translatable fields, so each driver compares the right locale's value.
- Folio moves its duplicated draft-building into one `DraftRecordFactory` that merges a single locale. The admin form and frontend take a locale.
- A request-scoped `ContentLocale` lets relation/matrix rendering follow the page locale.

**Tech Stack:** PHP ≥ 8.4, PHPUnit 11, Twig, SQLite JSON1 / MySQL JSON functions.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-content-i18n-design.md`

**Branch:** `feat/content-i18n` from `main`.

## Global Constraints

- **Conventions:** `declare(strict_types=1)`; `final` classes and `final readonly` value
  objects; tests named `test_snake_case`.
- **Single-locale apps see zero change:** with one locale in `config/i18n.php` (or no
  file), `translatable` has no effect and values are stored as plain scalars.
- **Locales come from `config/i18n.php`:** `available`, `default`, `fallback`, plus the
  new `cookie`. The URL strategy stays `prefix`: no prefix for the default locale,
  `/de/…` otherwise.
- **Stored shape** of a translatable field is a map `{"en": "…", "de": "…"}`.
  - JSON files store it as a native object.
  - SQL drivers store JSON text. `PdoDriver` json-encodes array values on save.
- **Read tolerance:**
  - a scalar (or a JSON string that is not an object) on a translatable field = the
    default-locale value;
  - a map on a non-translatable field = its default-locale value.
- **Fallback chain:** `[locale, fallback, default]`, de-duplicated, for values. Lookups
  and `where` match the exact locale only, with no fallback.
- **`_locales`:** a system key holding a list of locale codes. Missing means
  `[default]`. The default locale is always included.
- **No cookies by default** with the `prefix` strategy.

## Review Focus

1. **A record created before the type became translatable** (plain scalar `slug`). It
   must resolve at `/slug` in the default locale and must not appear under `/de/…`
   (Task 7: `test_legacy_scalar_slug_resolves_in_default_locale_only`).
2. **German slug equal to the English one** (`/de/about` and `/about`). Both must
   resolve to the same record in their own locale, not 404 or cross over (Task 7:
   `test_same_slug_in_two_locales`).
3. **Editing the default locale after German exists.** The save must keep the `de`
   values, including on a failed validation re-render, where the form must show the
   submitted English text and not lose the German (Task 5:
   `test_saving_default_keeps_other_locales`; Task 6:
   `test_validation_error_rerender_stays_in_locale`).
4. **A `?locale=` that is not configured** (`?locale=xx` or `?locale=../`). The editor
   must fall back to the default locale; it must never write an `xx` key (Task 6:
   `test_unknown_locale_param_uses_default`).
5. **A translatable field holding HTML (rich text) in a fallback language.** It must be
   rendered sanitized, exactly as in its own language, never raw (Task 7:
   `test_fallback_richtext_is_sanitized`).

---

### Task 1: `preflow/i18n` fixes and helpers

**Files:**
- Modify: `packages/i18n/src/LocaleMiddleware.php`
  - New constructor param `bool $cookie = false`, added last.
  - Set the cookie only when `$cookie` is true and the locale differs from the request's
    `locale` cookie.
- Modify: `packages/i18n/src/TranslationExtensionProvider.php`
  - **Escaping:** `t`/`tc` keep `isSafe: true`, but every `:param` value is escaped with
    `htmlspecialchars(…, ENT_QUOTES|ENT_HTML5)` before substitution. Escaping is done in
    the provider, not the Translator.
  - **New constructor params:** `list<string> $available = []` and `?string $default = null`.
  - **New functions:**
    - `current_locale()` returns the Translator locale;
    - `available_locales()` returns `$available`;
    - `locale_url(string $path, ?string $locale = null)` returns `'/' . ltrim($path,'/')`
      for the default locale and `'/' . $locale . '/' . ltrim($path,'/')` otherwise,
      with a trailing `/` trimmed except for the root.
- Modify: `packages/core/src/Application.php` (`bootI18n`)
  - Boot when `config/i18n.php` exists even without `lang/`, using a missing `lang/` path
    with no translations.
  - Pass `cookie` (default `strategy !== 'prefix'`) and the available/default locales.
- Modify: `packages/skeleton/config/i18n.php` (`'cookie' => false` with a comment) and
  `packages/i18n/README.md`.
- Test: `packages/i18n/tests/LocaleMiddlewareTest.php`, `TranslationExtensionProviderTest.php`, `packages/core/tests/I18nBootTest.php`

**Interfaces:**
- Produces:
  - `LocaleMiddleware::__construct(Translator, array $availableLocales, string $defaultLocale, string $urlStrategy = 'prefix', bool $cookie = false)`
  - `TranslationExtensionProvider::__construct(Translator $translator, array $available = [], ?string $default = null)`

- [ ] **Step 1: Write the failing tests:**
  - `test_prefix_strategy_sets_no_cookie`: `/de/x` → no `Set-Cookie`, request attribute `locale = de`, path `/x`.
  - `test_cookie_only_when_enabled_and_changed`: `cookie: true` with request cookie `locale=de` on `/de/x` → no `Set-Cookie`; on `/x` → `Set-Cookie: locale=en…`.
  - `test_t_escapes_params`: a translation `'hi' => 'Hi :name'`; `t('hi', {name: '<script>x</script>'})` → `Hi &lt;script&gt;x&lt;/script&gt;`.
  - `test_locale_url`: default `en` and available `en,de` → `locale_url('/about')` is `/about`; `locale_url('/about','de')` is `/de/about`; `locale_url('/','de')` is `/de`; `locale_url('/','en')` is `/`.
  - `I18nBootTest::test_boots_with_config_and_without_lang_dir`: a temp app with `config/i18n.php` `available: [en, de]` and no `lang/` → GET `/de/` reaches the page with `current_locale()` = `de`.
  - `I18nBootTest::test_no_cookie_by_default`: the same app → no `Set-Cookie` header.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite I18n` and `--testsuite Core`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS. Existing i18n tests that assert a cookie on the `none` strategy still pass, because that default stays `true`.
- [ ] **Step 5: Commit** `fix(i18n): no locale cookie with the prefix strategy, escape t() params, url helpers`.

### Task 2: `Locales`, translatable fields, `LocaleValue`, `LocalizedRecord`

**Files:**
- Create:
  - `packages/data/src/Locales.php`
  - `packages/data/src/LocaleValue.php`
  - `packages/data/src/LocalizedRecord.php`
- Modify:
  - `packages/data/src/TypeFieldDefinition.php`: add `public bool $translatable = false`.
  - `packages/data/src/TypeRegistry.php`:
    - constructor `(string $modelsPath, ?Locales $locales = null)`;
    - parse `translatable` and exclude it from `config`;
    - pass the locales into `TypeDefinition`.
  - `packages/data/src/TypeDefinition.php`: add `public Locales $locales` (default
    `Locales::single('en')`) and `translatableFields(): list<string>`, which is empty when
    single-locale.
  - `packages/data/src/DynamicRecord.php`: add `localized(string $locale): LocalizedRecord`
    and `availableLocales(): list<string>`.
  - `packages/core/src/Application.php` (`bootDataLayer`): build `Locales` from
    `config/i18n.php` (`available`, `default` falling back to `app.locale`, `fallback`),
    bind it, and pass it to `TypeRegistry`.
  - `packages/folio/src/FolioServiceProvider.php`: create its `TypeRegistry` with the
    container's `Locales` when bound.
- Test: `packages/data/tests/LocaleValueTest.php`, `packages/data/tests/LocalizedRecordTest.php`

**Interfaces:**
- Produces:
  - `final readonly class Locales`:
    - constructor `(list<string> $available, string $default, ?string $fallback = null)`;
    - `static single(string $locale): self`;
    - `isMultilingual(): bool`;
    - `has(string $l): bool`;
    - `chain(string $locale): list<string>`, i.e. `[locale, fallback, default]`, unique and
      limited to available locales.
  - `final class LocaleValue` (static):
    - `map(mixed $raw, Locales $l): array<string,mixed>`. A map is kept; a JSON-object
      string is decoded; a scalar or null becomes `[default => raw]`, or `[]` for null.
    - `pick(mixed $raw, Locales $l, string $locale): mixed`: the exact locale, no fallback.
    - `resolve(mixed $raw, Locales $l, string $locale): array{0: mixed, 1: bool}`: the value
      from the chain and whether it is a fallback. Empty string and null count as missing.
    - `resolveTree(mixed $value, Locales $l, string $locale): mixed`. This is recursive
      for nested arrays: an array whose keys are all available locale codes (at least one)
      is resolved; other arrays are walked.
    - `default(mixed $raw, Locales $l): mixed`: the read tolerance for non-translatable
      fields.
  - `final class LocalizedRecord`:
    - `get(string $field): mixed`;
    - `isFallback(string $field): bool`;
    - `locale(): string`;
    - `record(): DynamicRecord`;
    - `toArray(): array<string,mixed>`.
    - Behaviour: translatable fields go through `resolve`, other fields through
      `default()`, and system keys and nested arrays through `resolveTree`.
  - `DynamicRecord::availableLocales()` returns `_locales` filtered to configured locales,
    with the default always first.

- [ ] **Step 1: Write the failing tests:**
  - `test_map_read_tolerance`: scalar `'About'` → `['en' => 'About']`; `'{"en":"A","de":"B"}'` → the map; `['en'=>'A']` is unchanged; `null` → `[]`.
  - `test_resolve_follows_chain_and_reports_fallback`: available `en,de,fr`, default `en`, fallback `de`. `resolve(['en'=>'E','de'=>'D'], …, 'fr')` → `['D', true]`; `'de'` → `['D', false]`; `['en'=>'E','de'=>'']` for `de` → `['E', true]`.
  - `test_pick_is_exact`: `pick(['en'=>'E'], …, 'de')` → `null`; `pick('Plain', …, 'en')` → `'Plain'`; `pick('Plain', …, 'de')` → `null`.
  - `test_resolve_tree_nested`: `['_uid'=>'u','headline'=>['en'=>'H','de'=>'Ü'],'items'=>[['label'=>['en'=>'L']]]]` for `de` → `headline` is `Ü` and `items[0].label` is `L`; `_uid` is unchanged.
  - `test_single_locale_is_not_multilingual`: `Locales::single('en')->isMultilingual() === false`, and the type's `translatableFields() === []` even with `"translatable": true`.
  - `test_localized_record_shared_field_reads_map_default` (the read tolerance the other way round).
  - `test_available_locales_defaults_and_filters`: no `_locales` → `['en']`; `['de','xx']` → `['en','de']`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Data`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): locales, translatable fields and localized record views`.

### Task 3: Locale-aware queries on all drivers

**Files:**
- Modify: `packages/data/src/Query.php`. Add
  `localize(list<string> $fields, string $locale, string $default): void` and
  `localizationFor(string $field): ?array{locale: string, default: string}`.
- Modify: `packages/data/src/QueryBuilder.php`. Add `locale(string $locale): self`. In
  `get()`/`paginate()`, when `typeDef->translatableFields() !== []`, call
  `$query->localize($fields, $locale ?? default, default)`.
- Modify: `packages/data/src/Driver/JsonFileDriver.php`. In `evaluateCondition`,
  `applySearch` and `applyOrderBy`, a localized field's value is
  `LocaleValue::pick($raw, Locales::fromPair(locale, default), locale)`.
  - `Locales::fromPair(string $locale, string $default)` is a small named constructor
    added in this task.
- Modify: `packages/data/src/Driver/Dialect.php`. Add
  `localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string`.
- Modify: `SqliteDialect`. Implement it as:

  ```sql
  CASE WHEN json_valid(col) AND json_type(col) = 'object' THEN json_extract(col, '$."<locale>"') ELSE <col or NULL> END
  ```

  where the `ELSE` branch is the column when `isDefault`, else `NULL`.
- Modify: `MysqlDialect`. The same with `JSON_VALID`, `JSON_TYPE(col) = 'OBJECT'` and
  `JSON_UNQUOTE(JSON_EXTRACT(col, '$."<locale>"'))`.
- **Locale safety:** the locale is validated against `/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/`
  before interpolation; anything else throws `\InvalidArgumentException`.
- Modify: `packages/data/src/Driver/QueryCompiler.php`. Where, search and order use
  `localizedColumn` for localized fields.
- Modify: `packages/data/src/Driver/PdoDriver.php` (`save`). Array values are
  `json_encode`d (`JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR`).
- Test: `packages/data/tests/LocalizedQueryTest.php`, covering JSON plus SQLite in memory
  in one data-provider test. MySQL uses the existing MySQL test toggle if
  `packages/data/tests` has one; otherwise unit-test the compiled SQL string.

**Interfaces:**
- Consumes: `Locales`, `LocaleValue::pick` (Task 2).
- Produces: `QueryBuilder::locale(string): self` (used by Folio in Task 7).

- [ ] **Step 1: Write the failing tests.** Data provider: `json`, `sqlite`. Type `page` with translatable `slug` and `title`. Seeds:
  - A: `slug {en: about, de: ueber}`;
  - B: `slug 'legacy'` (scalar);
  - C: `slug {en: same, de: same}`.

  Tests:
  - `test_where_matches_requested_locale`: `->locale('de')->where('slug','ueber')` → A; `->locale('en')->where('slug','ueber')` → none.
  - `test_without_locale_matches_default`: `->where('slug','about')` → A.
  - `test_legacy_scalar_counts_as_default_locale`: `->locale('en')->where('slug','legacy')` → B; `->locale('de')` → none.
  - `test_search_uses_locale`: `->locale('de')->search('ueb')` → A.
  - `test_order_by_localized_title`.
  - `test_pdo_round_trips_map`: a SQLite save of A then `findOne` → `slug` decodes through `LocaleValue::map` to the map.
  - `test_invalid_locale_is_rejected`: `->locale("de'); DROP")` throws.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Data`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): locale-aware where, search and order on JSON, SQLite and MySQL`.

### Task 4: Validation scoped by locale

**Files:**
- Modify: `packages/data/src/DataManager.php` (`validateDynamicRecord`).
  - For each translatable field `f` with rules `R`, validate the data `f` = the
    default-locale value against `R`.
  - For each other locale `l` whose value is non-empty, validate `"f.l"` against `R`
    minus `required`.
  - Error keys are `f` and `f.l`.
- Test: `packages/data/tests/LocalizedValidationTest.php`

- [ ] **Step 1: Write the failing tests:**
  - `test_required_applies_to_default_locale_only`: `title {de: 'X'}` → error on `title`; `title {en: 'E'}` → passes.
  - `test_other_rules_apply_to_every_present_locale`: `slug` has `regex:/^[a-z-]+$/`; `{en: 'ok', de: 'Nicht OK'}` → an error keyed `slug.de`.
  - `test_single_locale_validation_unchanged`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Data`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): validate translatable fields per locale`.

### Task 5: `DraftRecordFactory` (one place that builds a record from a form)

**Files:**
- Create: `packages/folio/src/Content/DraftRecordFactory.php`
- Modify:
  - `packages/folio/src/Http/AdminController.php`: `store`/`update` use the factory;
    remove `collectFieldData` and its helpers.
  - `packages/folio/src/Http/PreviewController.php`: `draftRecord` uses the factory with
    `writeUploads: false`.
  - `FolioServiceProvider`: bind the factory.
- Test: `packages/folio/tests/Content/DraftRecordFactoryTest.php`

**Interfaces:**
- Consumes: `Locales`, `LocaleValue::map` (Task 2).
- Produces:
  - `final class DraftRecordFactory`, constructor `(FieldTypeRegistry $fieldTypes)`.
  - `build(TypeDefinition $typeDef, ServerRequestInterface $request, array $existing, string $locale, bool $writeUploads = true): DynamicRecord`.
- Rules:
  - **Translatable fields:** `map = LocaleValue::map($existing[f])`; set
    `map[$locale] = normalized input`, store the map. A single-locale type stores the
    scalar.
  - **Upload fields** are always treated as shared, even if marked translatable. Document
    this in a docblock.
  - **System keys:** existing system keys (leading `_`) are carried over.
  - **`_locales`** is only for multilingual types with translatable fields:
    - from `_locales[]` in the body when `_locales_submitted=1` is present;
    - otherwise kept from `$existing`;
    - the default locale is always present;
    - unknown codes are dropped.
  - **Locale:** `$locale` must be in `typeDef->locales`, else the default locale is used.

- [ ] **Step 1: Write the failing tests:**
  - `test_saving_de_leaves_en_untouched`: existing `title {en: E}`, POST `title=D`, locale `de` → `{en: E, de: D}`.
  - `test_saving_default_keeps_other_locales`: existing `{en: E, de: D}`, POST `title=E2`, locale `en` → `{en: E2, de: D}`.
  - `test_shared_field_is_plain_value`.
  - `test_locales_checkbox_handling`: `_locales_submitted=1`, `_locales[]=de`, `_locales[]=xx` → `['en','de']`; no `_locales_submitted` → kept.
  - `test_single_locale_stores_scalars`: identical to the current behaviour.
  - `test_preview_mode_writes_no_uploads`, mirroring `PreviewControllerTest`'s existing guarantee.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement, then switch both controllers over.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: PASS. The existing preview and upload tests stay green.
- [ ] **Step 5: Commit** `refactor(folio): one DraftRecordFactory for save and preview, locale-aware`.

### Task 6: Admin language switcher and editing aids

**Files:**
- Modify:
  - `packages/folio/src/Field/FieldContext.php`: add `public ?string $placeholder = null`.
  - `AbstractScalarFieldType::renderEditor`: add a `placeholder` attr when set.
- Modify: `packages/folio/src/Http/AdminController.php`
  - `editForm`/`createForm`/`update`/`store` read the locale from the `?locale=` query
    (or a `_locale` body field on POST), validated against `Locales`, defaulting to the
    default locale.
  - `form()` receives `$locale` and, for multilingual types, passes the template:
    - `locales` (list of `{code, url, active}`);
    - `locale`;
    - `available_in` (`availableLocales()`);
    - `default_locale`.
  - Each field entry gains `translatable` (bool), `fallback_label` (e.g. `English`, from a
    small code→name map: en, de, fr, it; otherwise the upper-cased code) and
    `reference` (the default-locale value when editing another locale; plain text).
  - For a translatable field, the editor value is `pick(map, locale)` and the placeholder
    is the resolved fallback value (strip_tags, max 120 chars).
  - Redirects after save keep `?locale=`.
- Modify: `packages/folio/templates/admin/form.twig`
  - Language tabs (links) plus "Visible in" checkboxes: `_locales[]`, and a hidden
    `_locales_submitted=1`; the default is checked and disabled, with a hidden input for
    it.
  - A hidden `_locale`.
  - Per field: the `folio-shared` label "Shared across languages" for non-translatable
    fields; the hint "Falls back to {fallback_label}" when it's empty in a non-default
    locale.
  - A `<details class="folio-reference">` with the default-language text.
- Modify: `packages/folio/templates/admin/list.twig` and `AdminController::list()`
  - Multilingual types get a "Languages" column with one badge per available locale.
  - A badge shows `de · 3 missing` when that many default-required translatable fields
    are empty in that locale.
- Modify: `packages/folio/assets/admin.css` (badges, shared label, tabs).
- Test: additions to `packages/folio/tests/Integration/FolioAppTest.php`, with a scaffold
  variant that writes `config/i18n.php` (`available: [en, de]`) and a type with
  translatable `title`/`slug`.

- [ ] **Step 1: Write the failing tests:**
  - `test_edit_form_shows_language_tabs_and_visibility`: GET `/folio/page/{id}/edit?locale=de` → tab links to `?locale=en` and `?locale=de` (de active), `name="_locales[]"` checkboxes, `name="_locale" value="de"`.
  - `test_empty_translation_shows_fallback_placeholder`: en title `About`, de empty → the de form has `placeholder="About"` and `Falls back to English`.
  - `test_shared_field_is_marked`: a non-translatable `status` → `Shared across languages`.
  - `test_save_in_de_redirects_back_to_de_and_keeps_en`.
  - `test_validation_error_rerender_stays_in_locale`: POST de with an invalid de slug → 422, the form is still in `de`, the submitted value is shown, and the stored en is unchanged.
  - `test_unknown_locale_param_uses_default`: `?locale=xx` → the form is in `en`; POST with `_locale=xx` stores under `en` only.
  - `test_list_shows_missing_translation_badge`: required `title` empty in de → `de · 1 missing`.
  - `test_single_locale_form_has_no_language_ui`.
  - The existing CSP guard (`CspGuardTest`) stays green with the new templates.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): edit content per language with fallbacks and visibility`.

### Task 7: Localized frontend, alternates and helpers

**Files:**
- Create: `packages/folio/src/Content/ContentLocale.php`, a request-scoped holder:
  `set(string)`, `get(): string` (the default until set), bound as a container instance.
- Modify: `packages/folio/src/Content/FrontendResolver.php`
  - `match(string $path, ?string $locale = null)` queries with `->locale($locale)` and
    skips records whose `availableLocales()` lacks the locale.
  - Add `urlFor(DynamicRecord $record, string $locale): ?string`, which fills the route
    params from the localized values. The home record gives `''`. The `locale_url` prefix
    rule applies. It returns null when the locale is unavailable.
- Modify: `packages/folio/src/Http/FrontendController.php`
  - The locale comes from the request attribute `locale` (set by `LocaleMiddleware`),
    else the default.
  - It sets `ContentLocale` and renders with:
    - `record` = `LocalizedRecord::toArray()`;
    - `rendered` = the localized map;
    - `locale`;
    - `alternates` (a list of `{locale, url}` for each available locale).
- Modify: `packages/folio/src/Http/PreviewController.php`. Render in the editing locale
  (`_locale`) and set `ContentLocale`.
- Modify: `packages/folio/src/Content/RecordRenderer.php`. `renderedMap()` and
  `renderTypeTemplate()` localize the record with `ContentLocale::get()` before rendering
  each field. The `RelationFieldType`/`MatrixFieldType` labels and renders go through
  `RecordRenderer`/`LocalizedRecord` too.
- Modify: `packages/folio/src/FolioServiceProvider.php`
  - Bind `ContentLocale`.
  - Register the Twig functions:
    - `folio_url(record, locale = null)`;
    - `folio_alternate_links(record)`, which emits
      `<link rel="alternate" hreflang="xx" href="…">` per available locale plus
      `hreflang="x-default"` pointing to the default locale's URL. It is safe HTML, with
      escaped hrefs.
- Modify: `packages/folio/templates/frontend/page.twig`:
  - `<html lang="{{ locale|default('en') }}">`;
  - `{{ folio_alternate_links(record) }}` in `<head>`, when `alternates` has more than one
    entry.
- Test: additions to `FolioAppTest` (the multilingual scaffold from Task 6), plus `packages/folio/tests/Content/FrontendResolverTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_translated_slug_resolves_under_prefix`: `/de/ueber-mich` → 200 with the de title, and `<html lang="de">`.
  - `test_unavailable_locale_is_404`: `_locales = ['en']` → `/de/about` → 404.
  - `test_missing_translation_falls_back_per_field`: de available, de body empty → the en body is rendered.
  - `test_fallback_richtext_is_sanitized`: an en body containing `<script>` and `<b>x</b>`, viewed in de → `<b>x</b>` is present and `<script` is absent.
  - `test_alternate_links`: the en page has `hreflang="de"` pointing to `/de/ueber-mich` and `hreflang="x-default"` pointing to `/about`.
  - `test_legacy_scalar_slug_resolves_in_default_locale_only`: `/legacy` → 200; `/de/legacy` → 404.
  - `test_same_slug_in_two_locales`: `/about` (en title) and `/de/about` (de title).
  - `test_matrix_reference_renders_in_page_locale`: a referenced note with translatable `title` → `/de/…` shows the de note title.
  - `test_preview_renders_editing_locale`.
  - `test_single_locale_frontend_unchanged`: the existing frontend tests stay green.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): localized frontend with translated slugs and hreflang`.

### Task 8: Demo and docs

**Files:**
- Modify:
  - `examples/folio-demo/config/i18n.php` (create it): `available: [en, de]`.
  - `config/models/page.json`: `title`, `slug` and `body` become translatable.
  - `seed.php`: one page with a German translation and `_locales: [en, de]`.
- Modify:
  - `packages/folio/README.md`: a "Translations" section covering the `translatable`
    flag, `_locales`, URLs, helpers and the single-locale note.
  - `packages/data/README.md`: `translatable`, `Locales` and `locale()`.
- Test: `examples/folio-demo/tests`, adding `test_demo_serves_german_page`.

- [ ] **Step 1: Write the failing test** `test_demo_serves_german_page` (GET `/de/<seeded de slug>` → 200 with the German title).
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Examples`. Expected: FAIL.
- [ ] **Step 3: Implement the demo config/seed and both README sections.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS for the whole suite.
- [ ] **Step 5: Commit** `docs(folio): content translation in the demo and READMEs`.

---

## After the plan

- Add this plan's row to `docs/superpowers/plans/README.md`.
- Browser check of folio-demo (`APP_DEBUG=0`):
  - switch the editor to Deutsch;
  - check the placeholder and the shared label;
  - save and confirm English is untouched;
  - visit `/de/…`;
  - check hreflang in the page source;
  - check there is no `Set-Cookie` on public pages.
