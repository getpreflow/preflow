# Aggregate Queries, QR Codes, PDFs from Templates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Count/sum/average per group in the data layer on every storage, QR codes and PDFs rendered from Twig or
Blade templates in the view layer — the building blocks BG Genius's certificates and reports need.

**Architecture:** `QueryBuilder::aggregate()` returns an `AggregateQuery` that a storage driver implementing the new
`AggregatingStorage` interface executes (SQL compiled per dialect; the JSON driver in PHP with identical
semantics). QR (`QrCode`, `qr_svg()`) and PDF (`PdfDocument`, `PdfRenderer`, `PdfEngine` with an mPDF engine,
building-block template functions) live in `preflow/view`; their libraries are composer `suggest`s with a clear
error when missing. `core`'s `Application` registers the view helpers like the other extension providers.

**Tech Stack:** PHP 8.4, PHPUnit 11, PDO (SQLite/MySQL), Twig 3, Blade, mpdf/mpdf ^8.2, chillerlan/php-qrcode ^5.

**Spec:** `docs/superpowers/specs/2026-10-28-aggregates-qr-pdf-design.md`

## Global Constraints

- PHP 8.4 in production: no 8.5-only syntax.
- No new packages, repositories or Packagist entries. QR and PDF code lives in `packages/view` (namespaces
  `Preflow\View\Qr`, `Preflow\View\Pdf`).
- `preflow/view` composer: `suggest` `mpdf/mpdf` (^8.2) and `chillerlan/php-qrcode` (^5.0); both in `require-dev`;
  `require` unchanged. A missing library → `\RuntimeException` naming the package to `composer require`.
- Aggregates give the **same rows on JSON, SQLite and MySQL** (one shared scenario trait, run per driver; MySQL via
  `composer test:mysql`, skipped without its env, as `MysqlUniqueTest`).
- Aggregate null rules: `count()` counts rows; `count`/`sum`/`avg`/`min`/`max` of a field skip nulls (and `''`
  for number fields); `avg`/`min`/`max` of no values → null; `sum` of no values → 0. Numbers come back as int when
  integral, else float.
- Field names in aggregates are checked against the type's fields (or the model's metadata); unknown names and
  raw SQL fragments are refused with `\InvalidArgumentException`. Grouping by a multiple relation is refused.
- A storage driver that does not implement `AggregatingStorage` → `\LogicException` naming the driver.
- Release v0.44.0; consumers (smeyer.me, smart-stack.works, holz-objekte.org) upgrade and stay green.

## Review Focus

1. Grouping on a text column holding numbers on an unconverted SQL table (pre-0.42 data): sums must still be
   numeric — test in Task 1 (`test_sum_casts_text_column`).
2. A group value that is null or missing (JSON records without the key): one group with key null on every driver —
   test in Task 2 scenario `groups_missing_values_together`.
3. `having` + `orderBy` on an alias + `limit`: the top-N rows identical across drivers, ties broken by the group
   fields ascending — Task 2 scenario `top_n_with_ties_is_stable`.
4. A template's QR block with a URL containing `&` and `"`: valid SVG, no HTML injection — test in Task 3
   (`test_qr_svg_escapes_nothing_into_markup`).
5. PDF rendering without mPDF installed: clear error; the HTML preview still renders — test in Task 4
   (`test_missing_mpdf_names_the_package`).

---

### Task 1: Aggregate API and SQL execution

**Files:**
- Create: `packages/data/src/AggregateQuery.php`, `packages/data/src/AggregatingStorage.php`
- Modify: `packages/data/src/QueryBuilder.php` (`aggregate()`), `packages/data/src/Driver/QueryCompiler.php`
  (`compileAggregate()`), `packages/data/src/Driver/Dialect.php` + `SqliteDialect.php` + `MysqlDialect.php` (numeric
  cast of a column), `packages/data/src/Driver/PdoDriver.php` (implements `AggregatingStorage`)
- Test: `packages/data/tests/AggregateSqliteTest.php`, `packages/data/tests/AggregateQueryTest.php`

**Interfaces:**
- Produces:
  - `QueryBuilder::aggregate(): AggregateQuery` — carries the builder's wheres/whereAny/search/locale; works for
    `query($modelClass)` and `queryIn()`/`queryType()`.
  - `AggregateQuery` (fluent, returns `self`): `groupBy(string ...$fields)`, `count(string $as = 'count')`,
    `countDistinct(string $field, string $as)`, `sum(string $field, string $as)`, `avg(string $field, string $as)`,
    `min(string $field, string $as)`, `max(string $field, string $as)`, `having(string $alias, string $operator,
    int|float $value)` (operators `=`, `!=`, `<`, `<=`, `>`, `>=`), `orderBy(string $aliasOrGroupField,
    SortDirection $dir = SortDirection::Asc)`, `limit(int)`, `offset(int)`, `get(): list<array<string, mixed>>`.
  - Accessors used by drivers: `groups(): list<string>`, `measures(): list<array{fn: string, field: ?string, as:
    string}>`, `havings()`, `orders()`, `limitValue(): ?int`, `offsetValue(): ?int`, `query(): Query` (the filter),
    `numericFields(): list<string>` (fields whose kind is Number, from the type/model), `table(): string`.
  - `interface AggregatingStorage { public function aggregate(AggregateQuery $query): array; }`
  - Row shape: group fields under their names, measures under their aliases; default order when none given: group
    fields ascending.

- [ ] **Step 1: Failing tests** in `AggregateQueryTest` (validation, no driver): unknown field →
  `InvalidArgumentException`; duplicate alias → exception; `having` on an unknown alias → exception; a multiple
  relation in `groupBy` → exception; a driver without `AggregatingStorage` → `LogicException`.
- [ ] **Step 2: Failing tests** in `AggregateSqliteTest` (a synced SQLite type `answer`: member, step, correct
  bool, tries number, seconds number): `test_count_per_group`, `test_sum_avg_min_max`, `test_having_and_order_by_alias`,
  `test_where_filters_before_grouping`, `test_totals_without_group_by` (one row), `test_sum_casts_text_column`
  (a table created with TEXT columns, values '3','4' → sum 7), `test_model_class_entry_point` (a `Model` with
  `#[Field]` properties).
- [ ] **Step 3:** Run `vendor/bin/phpunit packages/data/tests/AggregateQueryTest.php packages/data/tests/AggregateSqliteTest.php` — FAIL.
- [ ] **Step 4:** Implement. SQL: `SELECT <group cols>, COUNT(*) AS …, SUM(<num>(col)) … FROM t WHERE <existing
  where compile> GROUP BY … HAVING … ORDER BY … LIMIT/OFFSET` with all identifiers quoted by the dialect and values
  bound. The numeric cast is a dialect method used for every measure on a field (no-op for typed Number columns is
  fine to skip; casting always is acceptable). Translatable group fields use `localizedColumn()`.
- [ ] **Step 5:** `vendor/bin/phpunit --testsuite Data` — PASS. Commit `feat(data): aggregate queries (group by, count, sum, avg, min, max) on SQL`.

### Task 2: JSON aggregates and the cross-driver conformance suite

**Files:**
- Modify: `packages/data/src/Driver/JsonFileDriver.php` (implements `AggregatingStorage`)
- Create: `packages/data/tests/AggregateScenarios.php` (trait, like `UniqueScenarios`), `packages/data/tests/AggregateJsonTest.php`,
  `packages/data/tests/AggregateSqliteScenariosTest.php`, `packages/data/tests/AggregateMysqlTest.php` (mysql group)

**Interfaces:**
- Consumes: Task 1's `AggregateQuery` accessors and row shape.

- [ ] **Step 1:** Write `AggregateScenarios` with one fixture set (12 answer records over 3 members × 4 steps, some
  `seconds` null, one record without `step`) and these scenarios, each asserting exact rows:
  `counts_per_group`, `measures_skip_nulls`, `avg_of_nothing_is_null_sum_is_zero`, `groups_missing_values_together`,
  `having_filters_groups`, `top_n_with_ties_is_stable`, `count_distinct`, `filters_before_grouping`,
  `translatable_group_uses_locale`, `two_group_fields`, `empty_input_gives_no_rows`.
- [ ] **Step 2:** Use the trait in the JSON, SQLite and MySQL tests. Run JSON — FAIL; SQLite — PASS or fix Task 1
  code where scenarios reveal divergence (the scenarios are the contract).
- [ ] **Step 3:** Implement the JSON driver: filter with the existing matcher, group in PHP, apply null rules and
  number casting (numeric strings count as numbers for Number fields), having, ordering (ties by group fields
  ascending), limit/offset.
- [ ] **Step 4:** `vendor/bin/phpunit --testsuite Data` — PASS; `composer test:mysql` — PASS (or skipped without env).
- [ ] **Step 5:** Commit `feat(data): aggregates on the JSON driver, one conformance suite for all storages`.

### Task 3: QR codes in the view layer

**Files:**
- Create: `packages/view/src/Qr/QrCode.php`, `packages/view/src/ViewExtensionProvider.php`
- Modify: `packages/view/composer.json` (suggest + require-dev), `packages/core/src/Application.php` (register
  `ViewExtensionProvider` like `HelpersExtensionProvider`)
- Test: `packages/view/tests/Qr/QrCodeTest.php`, `packages/twig/tests/QrFunctionTest.php`, `packages/blade/tests/QrFunctionTest.php`

**Interfaces:**
- Produces: `final class QrCode { public static function svg(string $data, array $options = []): string;
  public static function png(string $data, array $options = []): string; public static function available(): bool; }`
  Options: `size` (px, default 200), `margin` (modules, default 2), `ecc` (`L|M|Q|H`, default `M`), `color`
  (default `#000`), `background` (default `#fff`). `ViewExtensionProvider::getTemplateFunctions()` includes
  `qr_svg(string $data, array $options = [])` (safe HTML).

- [ ] **Step 1: Failing tests:** `test_svg_decodes_back_to_the_data` (render PNG of a URL, read it back with
  chillerlan's reader, equals input), `test_qr_svg_escapes_nothing_into_markup` (data `https://x.test/?a=1&b="2"`
  → output is one `<svg …>` element, parses as XML, contains no raw `"2"`), `test_options_apply` (size, colours),
  `test_missing_library_names_the_package` (simulate via a protected `libraryClass()` seam or a test double),
  Twig and Blade: `{{ qr_svg('https://x.test') }}` / `{!! qr_svg('https://x.test') !!}` renders an `<svg`.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement. **Step 4:** `vendor/bin/phpunit --testsuite View,Twig,Blade,Core` — PASS.
- [ ] **Step 5:** Commit `feat(view): QR codes (QrCode, qr_svg) with a suggested library`.

### Task 4: PDF core — documents, renderer, mPDF engine

**Files:**
- Create: `packages/view/src/Pdf/PdfDocument.php`, `packages/view/src/Pdf/PageSettings.php`,
  `packages/view/src/Pdf/PdfRenderer.php`, `packages/view/src/Pdf/PdfEngine.php`, `packages/view/src/Pdf/MpdfEngine.php`,
  `packages/view/src/Pdf/PdfResponse.php`, `packages/view/src/Pdf/PdfConfig.php`
- Modify: `packages/view/composer.json` (mpdf suggest/require-dev; `smalot/pdfparser` require-dev for tests),
  `packages/core/src/Application.php` (bind `PdfRenderer` and `PdfEngine` → `MpdfEngine` when mPDF is installed;
  config `config/view.php` key `pdf`: `temp_dir` default `storage/cache/pdf`, `fonts` map name → file path,
  `default_font` default `dejavusanscondensed`)
- Test: `packages/view/tests/Pdf/PdfRendererTest.php`, `packages/view/tests/Pdf/Fixtures/`

**Interfaces:**
- Produces:
  - `abstract class PdfDocument { abstract public function template(): string; public function resolveState(): void {}
    public function page(): PageSettings { return new PageSettings(); } public function fileName(): string { return 'document.pdf'; } }`
    — public properties are the template's variables.
  - `final readonly class PageSettings { __construct(string $format = 'A4', string $orientation = 'P', float $marginTop = 15, float $marginRight = 15, float $marginBottom = 15, float $marginLeft = 15, ?string $headerTemplate = null, ?string $footerTemplate = null, ?float $width = null, ?float $height = null) }` (mm; width/height override format).
  - `interface PdfEngine { public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string; }`
  - `final class PdfRenderer { __construct(TemplateEngineInterface $templates, ?PdfEngine $engine, PdfConfig $config); public function render(PdfDocument $doc): string; public function html(PdfDocument $doc): string; }`
    — `html()` is the browser preview (whole HTML page with the document's CSS); `render()` needs an engine.
  - `PdfResponse::inline(string $bytes, string $fileName): ResponseInterface`, `::download(…)`.

- [ ] **Step 1: Failing tests:** `test_renders_a_template_to_pdf_bytes` (starts with `%PDF`), `test_text_is_extractable`
  (smalot parser finds the rendered name), `test_page_settings_apply` (A5 landscape → page size), `test_header_and_footer_templates`,
  `test_html_preview_renders_without_engine`, `test_missing_mpdf_names_the_package`, `test_temp_files_go_to_temp_dir`,
  Blade and Twig engines both render the same fixture document.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement. CSS: the document's template may include a `<style>` block;
  the renderer passes the full HTML to the engine (mPDF reads `<style>`); no component wrappers.
- [ ] **Step 4:** `vendor/bin/phpunit --testsuite View,Core` — PASS. **Step 5:** Commit `feat(view): PDFs from Twig or Blade templates (PdfDocument, PdfRenderer, mPDF engine)`.

### Task 5: PDF building blocks

**Files:**
- Modify: `packages/view/src/ViewExtensionProvider.php` (PDF functions), `packages/view/src/Pdf/MpdfEngine.php`
- Create: `packages/view/src/Pdf/Blocks.php` (the functions' implementations)
- Test: `packages/view/tests/Pdf/BlocksTest.php`

**Interfaces:**
- Produces template functions (safe HTML): `pdf_page_number()` (current page), `pdf_page_count()` (total),
  `pdf_page_break()`, `pdf_image(string $assetIdOrPath, array $options = [])` (options `width_mm`, `max_px`
  default 1200; a `preflow/media` asset id resolved through `MediaLibrary` when installed — public assets only
  unless `allow_private: true`; images over `max_px` scaled to a temp JPEG copy deleted after render),
  `pdf_table(array $rows, array $columns, array $options = [])` (header row repeats across pages),
  `pdf_qr(string $data, array $options = [])` (Task 3's SVG, sized in mm). Fonts: `PdfConfig::fonts` registered with
  mPDF by name. In the browser preview, page number/count render as nothing, page break as a dashed line.

- [ ] **Step 1: Failing tests:** `test_page_x_of_y_in_footer` (3-page doc, extracted text has "Page 1 of 3"),
  `test_table_header_repeats`, `test_image_is_scaled_copy_and_temp_is_cleaned`, `test_private_asset_needs_opt_in`,
  `test_qr_block_is_scannable` (extract the image? or assert an `<svg` reached mPDF and the PDF contains a vector
  path — choose one stable assertion), `test_custom_font_by_name`, `test_preview_renders_blocks_approximately`.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement. **Step 4:** `vendor/bin/phpunit --testsuite View,Twig,Blade,Core` — PASS.
- [ ] **Step 5:** Commit `feat(view): PDF building blocks (page numbers, image, table, QR, fonts)`.

### Task 6: Docs, consumers, release checklist

**Files:**
- Modify: `packages/data/README.md` (aggregates, null rules, refused cases), `packages/view/README.md` (QR, PDF,
  suggested packages, mPDF CSS limits: no flexbox/grid; preview is approximate), `ROADMAP.md` (step 5 done in 0.44;
  later: aggregates by time period, grouping by multiple relations, holz-objekte album on the PDF part)
- Create: `docs/superpowers/plans/2026-10-28-release-0.44-checklist.md` (model: the 0.43 checklist; three sites
  upgrade; holz-objekte's album later)
- Test: full `vendor/bin/phpunit` and `composer test:mysql`

- [ ] **Step 1:** Docs and checklist. **Step 2:** Full suite and MySQL — PASS. **Step 3:** Commit `docs: aggregates, QR and PDFs; 0.44 checklist`.
