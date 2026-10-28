# Aggregate Queries, QR Codes, PDFs from Templates — Design

**Date:** 2026-10-28
**Status:** Draft for the owner's review.
**Context:** step 5 of the BG Genius framework order in `ROADMAP.md` ("Reporting/export and certificates (PDF)"),
Phase 5 of `bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`. Current version: v0.43.0.

## Why

BG Genius gets certificates and reports (owner, 2026-10-28):

- **Certificates** are a fun element for now: like spielegesellschaft.ch's winners screen for tournaments, something
  to print and show at game night that you come prepared. A white-label version could later serve more serious
  uses (training, onboarding), so a certificate carries a **QR code and a link to check it online** from the start.
- **Reports** are on screen only for now. The same learning data answers different questions depending on who
  looks: a **host or tutor** wants people first (who needs help with what, before game night); the **operator**
  wants trouble spots first, across all groups and without names, to see where a game's flow needs improving.

**Certificates and reports are BG Genius (project) code** (owner): certificates as a Folio userland extension that
could later become its own package or be copied between projects; reports as BG Genius pages. The framework
supplies only the general building blocks they need, and today it lacks three of them:

1. The data layer can filter, sort and page records but cannot **count, sum or average per group**.
2. Nothing makes **QR codes**.
3. Nothing makes **PDFs**; holz-objekte.org uses mPDF directly inside the project (its favourites album).

## Standing rules

- The framework holds abstractions, interfaces, hooks and generic mechanisms only; project features stay in
  projects (owner, 2026-10-26). Folio is only a UI adapter.
- Cores run without Folio.

## Goals, non-goals, anti-goals

**Goals:**
1. **Aggregate queries** in `preflow/data`: group by fields, count/sum/avg/min/max per group, filters before and
   after grouping, order by a result, limit — the same results on MySQL, SQLite and the JSON driver.
2. **QR codes** in `preflow/view`: turn a link or text into a QR code, as SVG (inline or a file) and PNG, with a
   template function.
3. **PDFs from templates** in `preflow/view` (owner: "almost like a component that also holds building blocks for
   PDFs"; and PDF is "a kind of view / render layer that can be fed by Twig or Blade"): a PDF is written like a
   Preflow component — a class with a template that receives data — rendered by the site's template engine and
   turned into PDF bytes. Building blocks a reusable PDF needs: page format and margins, header and footer, page
   numbers, image, table, QR code, fonts.
4. BG Genius's certificate is buildable from these (checked below); holz-objekte's album later (owner).

**Non-goals:**
- Certificates, the check page, revoking, issuing on a passed test, reports and their screens (BG Genius).
- CSV/spreadsheet export, charts (owner: on screen only for now).
- Moving holz-objekte's album onto the PDF part (later; it is the second proof).
- New packages, and a general "utils"/"misc" package (owner and my take, 2026-10-28: a junk drawer with no clear
  owner). PDF and QR are output of the view layer, as SEO/GEO (meta, sitemaps, JSON-LD) later will be.
- A generic "report" abstraction in the framework.

**Anti-goals:**
- Aggregates that give different numbers on JSON than on SQL.
- A PDF part that only works with one engine baked into every call site (the engine sits behind an interface).
- A PDF feature that needs a headless browser or a system binary the hosting doesn't have.
- Certificate or report concepts leaking into the framework.

## Design

### 1. Aggregate queries (`preflow/data`)

- `QueryBuilder::aggregate(): AggregateQuery` (or an equivalent entry from `DataManager::queryIn()` / `query()`),
  keeping today's `where`/`whereAny`/`search` filters, then:
  - `groupBy(string ...$fields)`;
  - `count(string $as = 'count')`, `countDistinct(string $field, string $as)`, `sum(string $field, string $as)`,
    `avg(…)`, `min(…)`, `max(…)` (several per query);
  - `having(string $alias, string $operator, mixed $value)` on a result;
  - `orderBy(string $aliasOrField, SortDirection)`, `limit`, `offset`;
  - `get(): list<array<string, mixed>>` — one row per group: the group fields plus the named results.
- Without `groupBy` the query returns one row (totals).
- **SQL drivers:** `GROUP BY`/aggregate functions compiled by the existing dialects; identifiers checked against
  the type's fields (no raw SQL from callers). Number fields aggregate numerically (0.42's typed columns); on an
  unconverted text column the dialect casts.
- **JSON driver:** the same semantics in PHP over the filtered records (null handling identical: nulls are skipped
  by sum/avg/min/max and counted only by `count()`; avg of no values is null).
- Translatable fields: grouping uses the query's locale value (as `where` does today) (my call).
- Relations: grouping by a single relation field groups by the related id; labels are the app's job. Grouping by a
  multiple relation (stored as a JSON list) is refused with a clear exception for now (my call; unnesting needs
  JSON_TABLE/json_each and changes what count means — later if a report needs it).
- Time periods (per day/week/month) are not part of this release (my call; no current report needs them). The API
  leaves room for a later `groupByPeriod()`.
- Both entry points get aggregates: dynamic types (`queryIn`/`queryType`, Folio types) and typed `Model` classes
  (`query($modelClass)`) — app data lives in both today (my call).
- A conformance test runs the same aggregate suite against JSON, SQLite and MySQL (as other data tests do).

### 2. QR codes (`preflow/view`)

- `QrCode::svg(string $data, array $options = []): string` and `QrCode::png(string $data, array $options): string`
  (bytes); options: size, margin (quiet zone), error correction level (default M), colours.
- A template function for Twig and Blade (`qr_svg(url)`), returning safe inline SVG, registered through the view
  layer's `TemplateExtensionProvider`/`TemplateFunctionDefinition` like the other view helpers.
- Built on one maintained pure-PHP library (my call: `chillerlan/php-qrcode`, MIT; SVG needs no extension, PNG
  uses GD when present); the library stays an implementation detail behind `QrCode`. It is a **suggested**
  dependency of `preflow/view`, not a required one: without it, `QrCode` and `qr_svg()` throw a clear error naming
  the package to `composer require`.
- Independent of the PDF part; the PDF part uses it for its QR block.

### 3. PDFs from templates (`preflow/view`)

- **A PDF document is a class with a template**, like a component, with its **own render pipeline** (my call): no
  component wrapper markup, the document's CSS inlined into the HTML mPDF gets. Ordinary page components are not
  rendered inside PDFs in this release; the PDF building blocks are the PDF's own. `abstract class PdfDocument` with public
  properties as the template's variables, an optional `resolveState()`, and page settings (format A4/A5/Letter
  or custom mm, orientation, margins, header/footer templates). The template is rendered by the site's
  `TemplateEngineInterface` (Twig or Blade, whichever the site uses); the engine-specific packages only register
  the building-block functions.
- `PdfRenderer::render(PdfDocument $doc): string` returns the PDF bytes; `PdfResponse` (or a helper) sends them
  inline or as a download with a file name. Nothing is written to disk except the engine's temp files in a
  configured cache dir.
- **Engine behind an interface:** `PdfEngine` (HTML + page settings → bytes). The first engine wraps **mPDF**
  (my call: pure PHP, runs on the hosting, already proven in holz-objekte; headless Chrome cannot run there). Sites
  can bind another engine. mPDF is a **suggested** dependency of `preflow/view`: a site that makes PDFs requires
  it; without it, rendering a PDF throws a clear error naming the package. The HTML preview works without it.
- **Building blocks** (template functions or small components): page (format, margins), header and footer with
  page numbers ("page X of Y"), image (a media asset or a path; scaled copies made as holz-objekte does, never the
  full original into the PDF), table (repeating header row across pages), QR code (section 2), page break,
  fonts (register a TTF/OTF by name in `config/view.php` under `pdf`; mPDF's bundled DejaVu as the default).
- **Preview in the browser:** the same document can render as HTML (for a preview page) so the app can offer "view"
  and "download PDF" from one template. The preview is **similar, not identical** (my call): headers, footers and
  page numbers use mPDF's own markup, which a browser shows approximately or not at all; templates stay within the
  CSS mPDF supports (no flexbox or grid), documented.
- Images from `preflow/media` are read from files; private assets only when the caller passes them explicitly.

### 4. BG Genius's certificate (check, project code)

1. Final test passed → BG Genius's certificate extension stores a certificate record (member, game, date, score,
   an unguessable number) — a Folio type in the project.
2. The check page `/certificate/{number}` (BG Genius route) shows name, game, date and "valid"/"revoked".
3. The printable certificate is a `PdfDocument` in BG Genius with its own playful template; it places a
   `qr_svg('https://…/certificate/{number}')` block; the member downloads it from their member area.
4. Reports: BG Genius queries its learning data with aggregates (per step: members who struggled, wrong answers,
   retries; per member: progress, test result) and renders its own screens — people first for hosts, trouble
   spots first and without names for the operator.

## Constraints

- PHP 8.4 in production. No new packages, repositories or Packagist entries.
- Composer: `preflow/view` **suggests** `mpdf/mpdf` (^8.2, as holz-objekte) and `chillerlan/php-qrcode`; both are
  `require-dev` for its tests. No other package requires them. A site that makes PDFs or QR codes requires them
  itself (README and the error messages say so).
- Aggregates add no new driver contract that breaks third-party drivers silently: a driver without aggregate
  support throws a clear exception (my call).
- smeyer.me, smart-stack.works and holz-objekte.org upgrade and stay green.

## Verification

- Aggregate conformance tests on JSON, SQLite, MySQL with the same expected rows (grouping, nulls, having,
  ordering by a result, translatable and relation fields, empty input).
- QR: decoded back in a test (a pure-PHP decoder in dev dependencies, or a fixture comparison).
- PDF: a sample document with header/footer/page numbers/table/image/QR renders; the text is extractable and the
  page count right; the HTML preview renders the same template.

## Left to the builder

- The exact aggregate API names and how it hangs off `QueryBuilder`/`DataManager`.
- Whether the PDF building blocks are template functions, macros or small components.
- The QR decoder used in tests.

## Size

Medium–large: one plan of about 5–6 tasks (aggregates SQL; aggregates JSON + conformance; QR in view; PDF core and
engine; PDF building blocks and preview; docs, consumers, release — QR and PDF in `preflow/view`). One release (v0.44).
