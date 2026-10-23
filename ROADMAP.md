# Preflow Roadmap

> Last updated: 2026-10-23 (v0.25.0)

## Current State

**v0.25.0** — 2116 tests, 14 packages, production-validated via BGGenius stress test (admin CRUD, public forms, HTMX inline validation, custom validators with DB/rate-limiting).

The validation package has been stress-tested end-to-end: model attributes, standalone validation, custom container-resolved rules, ErrorBag with field-level template display, HTMX blur-based inline field validation, and DataManager auto-validation on save.

---

## Next Up

### Form Package

An `ActiveForm`-style template layer built on top of `preflow/validation`. Replaces repetitive field/error/old-input boilerplate with declarative helpers:

```twig
{{ form_field('username', {label: 'Username', required: true}) }}
{{ form_field('email', {label: 'Email', type: 'email'}) }}
{{ form_select('role', {label: 'Role', options: {editor: 'Editor', admin: 'Admin'}}) }}
```

Error display, `is-invalid` class toggle, `old()` re-population, and help text handled internally. The validation ErrorBag already provides the data layer.

### Guide-Level Tutorial

A step-by-step guide building a complete small application covering file-based + attribute routing, components with HTMX actions, data models with validation, authentication, and deployment.

### Props vs Action Params Clarity

The distinction between component props (encoded in token) and action params (POST body) caused bugs in admin components. Options:
- Merge params into props automatically so actions always see the full picture
- Provide `$this->param('key')` helper that checks both
- Better documentation + skeleton examples showing the pattern

---

## Medium Term

### Database Relations

Simple eager loading for related models:

```php
$posts = $manager->query(Post::class)->with('author')->get();
```

Or relation attributes on models. The `raw()` method covers complex JOINs for now.

### Route Caching

Compile the route collection to a PHP file for production. The `RouteCompiler` exists but isn't integrated into the Application boot lifecycle with automatic invalidation.

### Middleware for File-Based Routes

File-based routes can't carry middleware annotations. Options under consideration:
- Convention-based `_middleware.php` files in page directories
- Frontmatter in template files
- Keep global path-based middleware as the primary pattern

### Component Asset Optimization

Track which CSS/JS blocks have already been sent to the client to avoid re-sending on every HTMX swap. Options: client-side cookie tracking, CSS deduplication by component name, or preloading all component CSS on initial render.

### Lifecycle Events

`BeforeSave`, `AfterSave`, `BeforeDelete` events on DataManager. Would allow validation to move from direct integration to event listeners, and enable other cross-cutting concerns (audit logging, cache invalidation).

---

## Lower Priority

- **Error page customization** — app-level 404/500 templates
- **Blade adapter testing** — stress test the Blade adapter like Twig was tested
- **Testing utilities** — component render assertions, HTMX action dispatch helpers, snapshot testing

---

## Completed

### v0.25.0

- **MySQL / MariaDB as first-class SQL storage:** `preflow migrate`, `Schema` introspection (`hasTable`, `columns`, `addColumn`) and Folio's `folio.tables` step run on MySQL 8 and MariaDB 10.6+; a `SchemaGrammar` picked from the PDO driver (`SqliteGrammar` byte for byte as before, `MysqlGrammar`) replaces the SQLite-only SQL, any other driver throws `No schema grammar for PDO driver`
- **MySQL DDL:** `uuid` VARCHAR(64), `string($n)` VARCHAR($n), `json` LONGTEXT, `integer` BIGINT, `boolean` TINYINT(1), `timestamps` VARCHAR(32); tables `InnoDB utf8mb4 utf8mb4_unicode_ci`; Folio tables use `VARCHAR(191)` ids and `LONGTEXT` columns; indexing a TEXT column throws a `LogicException` (use `string()`)
- **Connection:** exception error mode, `FOUND_ROWS` (an unchanged-row `UPDATE` counts as 1, as on SQLite) and a strict session `sql_mode`, also for a hand-made `PDO` passed to `MysqlDriver`
- **Known difference:** `utf8mb4_unicode_ci` is case- and accent-insensitive, so custom ids and unique values differing only in case collide on MySQL; generated ids (lowercase hex) are unaffected
- **Tests:** `Preflow\Testing\MysqlTestDatabase` and `composer test:mysql` (reads the gitignored `.env.mysql-test`); MySQL tests skip without `MYSQL_*` env. SQLite behaviour and DDL unchanged, no new migrations for existing sites
- Constraints: `preflow/folio` and `preflow/devtools` require `preflow/data` `^0.25 || @dev`

### v0.24.1

- **Codes:** readable codes also ignore the minus sign (U+2212) and soft hyphens (U+00AD), which codes copied from a PDF or an ebook often carry
- **Folio:** a `list.columns` entry naming a hidden field is a boot error (the list used to show the hidden field); an emptied `number` setting reads as not set (its default, or null) instead of `''`; `list.sort` on a `number` field compares numbers on SQL storage
- **migrate:** without `--storage` it migrates the site's SQL storage (the default when it is SQL, else the only one; several need `--storage`) instead of always `sqlite`; migrations refuse a non-SQLite connection with a clear message, since MySQL migrations are not supported yet
- Tests: FolioAuthTest uses attributes (30 PHPUnit deprecations gone)

### v0.24.0

- **Notify deferred sending:** `Notifier::sendLater($message, ?\Closure $onFailure)` queues a mail and `flushDeferred()` sends the queue (FIFO, each message isolated, failures to `$onFailure`, the PSR logger or `error_log`, never into the request); `Application::run()` flushes after emitting the response and `fastcgi_finish_request()`, `handle()` flushes before returning for CLI and tests; `'defer' => false` in `config/notify.php` sends at once. Closes the response-time leak of account flows

### v0.23.0

- **Folio multi-relation picker:** `"relation": {"multiple": true}` renders a fieldset of checkbox chips (checked chips highlighted, visible keyboard focus) instead of a native `<select multiple>`; above 12 options a filter input narrows the chips client-side; without JS the checkboxes still post `name[]`, so storage and validation are unchanged; optional `"relation": {"orderBy": "<field>"}` sorts the options; single relations are unchanged

### v0.22.1

- **Folio read-only fields on save:** a `"readonly": true` field is skipped by validation (its `validate` rules, the field type's rules and `required`) and is never taken from the request, so a stored value the editor can't change (e.g. an ATOM timestamp in a `date` + `time` field) no longer makes every save return 422
- `date` with `"time": true` also accepts seconds (`Y-m-d\TH:i:s`), which a `datetime-local` input sends when its value has them

### v0.22.0

- **Folio read-only display:** read-only fields show labels, select options, relation labels and sanitised rich text instead of raw values; `DisplaysInAdmin` lets a field type set its admin display; `date` shows formatted dates in the admin only, with strict parsing (`Y-m-d`, `Y-m-d\TH:i`, ISO 8601 with an offset)
- **Panel-only actions:** `#[RecordAction(toolbar: false)]` keeps the action routed and permission-checked without a header button, for forms inside a `FormPanel`
- **Badge columns:** `#[ListColumn]` may return `Badge(label, tone)` for a status pill; the label is escaped, an unknown tone falls back to plain
- **Side panels:** `#[FormPanel(position: 'side')]`, a sticky column from 1280 px, stacked under the form below that
- **Tabs and icons:** `data-folio-tabs` with `data-folio-tab` / `data-folio-tabpanel` (ARIA, arrow keys); the `ui-check` icon

### v0.21.0

- **Atomic counters:** `DataManager::execute()` (raw SQL writes); `Throttle::hit()` and code uses are atomic (SQL upsert / conditional `UPDATE`, file lock on JSON); `Codes::check()` takes a throttled client
- **Auth:** single-use hashed `Tokens`; remember-me cookies for session guards (rotating selector/validator, theft detection, 60 s grace, `CookieQueue`); any user provider class from `config/auth.php` (`LazyUserProvider`)
- **Folio members support:** `RecordUserProvider` over user types; `frontend.permission` gate for a type's pages; private assets behind a permission-gated route; `#[NavBadge]` sidebar counts
- Constraints raised to `^0.21 || @dev` where 0.21 features are used (auth, codes, folio)

### v0.20.0

- **Folio on SQLite, extensions and live settings** (plan A): extension system, live settings, SQL storage, storage aliases
- **User types and transfer**, **Throttle service**
- **RBAC package** (`preflow/rbac`) — roles stored in Folio, matrix permissions editor
- **Codes package** (`preflow/codes`) — redeemable codes managed in Folio
- Folio sidebar built from model JSON; admin assets for packages
- CLI exit-code fix
- Provider constraints raised to `^0.20 || @dev` where 0.20 features are used (folio, devtools, roles)

### v0.12.0

- **Validation package** (`preflow/validation`) — 14th package
  - `ValidationRule` interface with `ValidationContext` for cross-field rules
  - 12 built-in rules: required, nullable, email, url, numeric, integer, min, max, between, in, regex, confirmed
  - `RuleFactory` with alias resolution, `#[RuleAlias]` discovery, app-level overrides
  - `Validator` engine with nullable/required chain logic
  - `ValidationResult` (thin) + `ErrorBag` (rich) layered error objects
  - `#[Validate]` attribute on typed model properties
  - `"validate"` key in dynamic model JSON schemas
  - `DataManager` auto-validation on save/insert/update with `validate: false` bypass
  - `ValidationException` with structured error access
  - `ValidationExtensionProvider` with `validation_errors()`, `validation_has_errors()`, `old()` template functions
  - Auto-discovery of custom rules from `app/Rules/`
  - Container-resolved custom validators (DB uniqueness, rate limiting)
- **CSS scoping** — `cssClass` + `scopeCss` on components, native CSS nesting, stable hash from FQCN
- **Key prop** for disambiguating multiple component instances
- **Stable component ID** based on FQCN

### v0.11.0

- HTMX-aware CSS/JS delivery in fragment responses
- Auto-increment ID support
- Catch-all attribute route params (`{param...}`)
- Request context in page templates
- `csrf_token()` / `csrf_field()` split
- JSON body parsing middleware
- Explicit `insert()` / `update()` on DataManager
- `delete()` accepts model instances
- Raw SQL queries
- Fragment rendering for targeted HTMX swaps
- `AssetCollector::fork()` for isolated sub-renders
- FlashType enum and typed flash helpers
- `asset_url()` template function
- FileRouteScanner proper prefix tracking
- Recursive component discovery
- DI in component endpoint factory
- `config/middleware.php` auto-loading
- Configurable ID fields in StorageDriver
