# Preflow Roadmap

> Last updated: 2026-10-25 (v0.31.0)

## Current State

**v0.31.0** — 2947 tests, 21 packages, production-validated via BGGenius stress test (admin CRUD, public forms, HTMX inline validation, custom validators with DB/rate-limiting).

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

### v0.31.0

- **CSRF on an expired session (preflow/core):** `CsrfMiddleware` tells a POST whose session expired (the session held no token before the request, the request carried one) from other failures and throws `CsrfSessionExpiredException` (extends `ForbiddenHttpException`, still 403, message `CSRF token mismatch: session expired.`); every other failure is the plain `ForbiddenHttpException('CSRF token mismatch.')` as before
- **`ExpiredSessionHandler` hook:** bind `Preflow\Core\Http\Csrf\ExpiredSessionHandler` in the container (a service provider); for the expired case only, it gets the request (new session and fresh `CsrfToken` attributes) before the app runs and returns a response to send, or `null` for the 403. The Application looks the binding up at request time (`ContainerExpiredSessionHandler`), so providers may bind it. Rule: never apply posted data from such a request; at most keep it to prefill the form after sign-in
- No new package, no constraint change

### v0.30.0

- **Folio screen assets:** an extension's `assets/` folder is served at `{prefix}/_assets/x/{extension}/{path}` for enabled extensions (types `css`, `js`, `mjs`, `map`, `svg`, `png`, `webp`, `woff2`, `json`; no path escapes or symlinks out; `nosniff`, immutable cache, `?v=` content hash)
- `assets:` on `#[FolioScreen]` and `ScreenView` (also on `#[FormPanel]` views): own files, `@folio/htmx.js`, `@folio/trix`, `@other/file`; printed by the admin layout (CSS in `<head>`, `.js` deferred with the nonce, `.mjs` as modules, one `htmx-config` meta); a missing file throws an `ExtensionException` naming the screen or panel
- Twig `folio_extension_asset(extension, path)` and `<meta name="folio-extension-assets">` on extension screens for run-time loading (for example lazy-loading a library); `editor_assets` stays Folio-internal
- No new package, no constraint change

### v0.29.0

- **Folio lists:** every admin list has a search box (`searchLoose`: all words, any text field or one relation level deep), `list.filters` ("+ Filter" chips for select, boolean, date and relation fields), sortable headers, highlighted matches and paging by 50; the view is in the URL (`q`, `f[field]`, `sort`, `page`; unknown fields and bad values are dropped) and the live search swaps `?_partial=1`; ties end in the record id so pages stay stable. Field kinds can opt into search with the `SearchableText` marker
- **Relations in Folio:** relation columns in `list.columns` show record labels; the relation picker switches to remote search above 200 records (`GET {prefix}/_relation/{type}`)
- **Deleting:** `RecordDeleter` deletes through the data package's delete plan on Folio's own hook paths (all or nothing); `POST {prefix}/{type}/_delete-plan` feeds a summary in the confirmation ("Also deletes 3 posts.", the refusal for `restrict`)
- **Bulk actions:** tick rows for `list.bulk` set-field actions, extension `#[BulkAction(name, label, permission, confirm)]` (`_bulk/{name}`; `delete`, `export` and `set-…` are reserved) and bulk delete; each route has a permission rule
- **Excel export:** `GET {prefix}/{type}/_export` streams the filtered list (or the ticked rows) as `.xlsx` with the optional `openspout/openspout`; no button without it
- Template contract for apps overriding `@folio/admin/list.twig`: `row.cells` plain text, `row.marked` highlighted HTML; a tick column shifts cell positions
- Constraints: `preflow/folio` requires and conflicts with `preflow/data` `<0.28` (it calls `searchLoose`, `whereAny`, `deletePlan` and relations)

### v0.28.0

- **Relations (preflow/data):** a field of a relation kind declares `relation: {to, multiple, onDelete, labelField}`; `DataManager::relationsOf()` / `relationsTo()` read them, `fieldKinds()` registers extra text and relation kinds, `TypeRegistry::keys()` lists every type; relations work across storages (SQL to JSON and back)
- **`onDelete`:** `detach` (default), `cascade` or `restrict` decides what deleting a target does to the records pointing at it
- **Delete plans:** `DataManager::deletePlan()` computes deletes (roots plus cascades, recursively), detaches and restrictions without changing anything (optional `$copies` closure for shadow tables, counted toward restrictions only); `executeDeletePlan()` refuses a restricted plan, runs SQL writes in one transaction per connection; JSON writes are not rolled back, and the plan is a snapshot
- **Queries:** `whereAny()` OR-groups with `=`, `CONTAINS` (case-insensitive, `%` and `_` literal), `IN` and `HAS` (id in a single or JSON-array field); `searchLoose()` splits the term on whitespace, every word must match, in any text field or, one level deep, the label and text fields of a related record (`searchable: false` opts a field out; `RELATION_MATCH_LIMIT` caps target ids per word). `Field::searchable` and the type's `searchable` are now nullable: `search()` still uses only `searchable: true` fields. Driver differences: SQLite folds case for ASCII only, MySQL follows the column collation
- **JSON storage cache:** `JsonFileDriver` takes an optional `cacheDir` (one `json-{type}.json` per type, validated against a signature of the type's files, dropped on write/delete, `clearCache()`); core passes `storage/cache/data`
- Constraints: `preflow/core` conflicts with `preflow/data` `<0.28` (it passes the new `cacheDir:` named argument; an older driver would fail at boot with an unknown named parameter)

### v0.27.0

- **Staff accounts in Folio (preflow/rbac):** the opt-in part `model:staff` (`enabled_parts`) gives staff accounts as records (type `staff` under Settings: e-mail, name, roles, active, last login), signed in through `RecordUserProvider` with an `active` field; an **Account** panel invites by mail on create (7-day set-password link), sends reset links (1 hour), disables (sessions, remember-me logins and links end) and enables; every account has its own page at `{folio}/_account` to change its password
- **Delegation:** nobody grants what they don't hold: a role may only contain patterns the editor's own roles cover (`*` all, `x.*` everything below `x.` but not a bare `x`), roles and accounts above the editor can't be changed, a role is added to an account only by a defined staff role's name, and the last active manager (`staff.edit` + `rbac_role.edit`) can't be disabled, demoted or deleted; server-side on every write path, mirrored in the matrix and the roles checkboxes; messages in en/de/fr (`lang/*/rbac.php`)
- **Migration:** `folio:staff:import-config` makes each config user of the default guard a staff record (same password hash, roles by name, repeatable); revision authors show the account's name (`AuthorNames`)
- **auth:** `ChainUserProvider` (providers in order, first hit wins; built after the others by core), `ClaimsCredentials` (a record decides for its e-mail, so a disabled staff record isn't rescued by the config user), `RecordUserProvider` `active`, `Access::population()`, `ConfigUserProvider::entries()`, `SessionGuard::remembers()`, `LazyUserProvider::inner()`
- **Folio login for record users:** throttle per client and e-mail (`folio.login`), one generic refusal, constant-cost hashing, forgot-password and set-password pages (`folio.forgot`, `folio.password`, `password_min`), `last_login_at`, plain-text mails through notify in the site's locale; `StaffPasswords` for packages managing accounts; remember-me whenever the guard has `remember`. Sites with config users only keep today's login unchanged
- **Folio:** opt-in model parts (`"opt_in": true`, listed under `enabled_parts`); new-record forms start from the fields' declared `default`; `FolioPermissionMiddleware::SIGNED_IN` for pages any signed-in user may open; `RecordUser::getEmail()`
- Constraints: `preflow/folio` conflicts with `preflow/auth` `<0.27`, `data` and `i18n` `<0.26`, `notify` `<0.24`; `preflow/rbac` with `preflow/auth`, `core` and `folio` `<0.27` and `i18n` `<0.26` (and now requires `devtools` and `i18n`); `preflow/folio` requires `i18n` and `notify`

### v0.26.0

- **New package `preflow/members`:** member accounts for a Preflow + Folio site, extracted from smart-stack.works. The `member` type in Folio with a waiting badge, a State column and a Decision panel (accept or decline with an editable message, the mail goes out first), the `pending` / `member` / `rejected` roles, registration with e-mail confirmation, sign-in with a remember cookie, forgot/reset, resend and an account page (password change, delete), all in `en`, `de` and `fr` with locale-prefixed routes; no account enumeration, throttled, no session cookie on public pages
- **Pluggable admission:** `Admission` decides after the e-mail is confirmed (`afterConfirm($member, $request)` returns `Decision::Accept` or `Decision::Pending`; `validate($input, $request, $baseErrors)` on every post (costly checks skip while the base fields fail), `recordValues()`, `details()` for the panel and the owner's notice, `confirmNotice()` for a line after confirming); `AcceptOnConfirm` and `OwnerApproval` (default) ship
- **Sites make it theirs:** `config/members.php` (path, landing, register, layout, mail, admission, throttle, retention, privacy_url...), template overrides in `resources/members/` (`@members-package` to extend the original, blocks for the admission and extra fields), single copy keys from the site's `lang/<locale>/members.php`, extra member fields through `config/models/member.json`; `members:prune` for cron
- **Folio: site model files can extend an extension's type** (`"extends": true`): fields are appended (or placed with `after`), `list.columns` replace, `storage`/`table`/`users`/`id_field` are locked, redefining a field or any other key is a boot error naming the file; works for extension settings singletons too; disabling what a patch extends is refused (admin and CLI), and the boot error names the file and the `folio:extensions:enable` command
- **Folio:** `Extension::prepareModel()` adjusts an extension's model at boot (a storage alias with a fallback, options from the site's locales); `ExtensionSettings::get(..., exact: true)` reads a translatable setting in that locale only; `#[FieldPlaceholder(field)]` on a type customization shows what an empty field means (members: the standard accept/decline texts in Settings)
- **i18n:** `Translator::addDefaults($group, $path)` lets a package ship strings a site overrides key by key; `get()` and `choice()` take an optional `$locale`
- **auth:** session epochs: a user implementing `HasSessionEpoch` ends its other sessions when the epoch changes (`RecordUser` reads the type's `auth_epoch` field); a password change or reset signs out every other browser
- **devtools:** packages register console commands through `extra.preflow.commands` in their `composer.json`, read from `vendor/composer/installed.json`; the site's `config/commands.php` still wins on a clash
- Constraints: `^0.26 || @dev` matches any version, so `conflict` entries guard: `preflow/members` conflicts with `preflow/auth`, `data`, `folio`, `i18n` `<0.26`; `preflow/folio` with `preflow/auth` and `data` `<0.26`; `preflow/testing` with `preflow/data` `<0.25`

### v0.25.1

- **Folio revisions on MySQL match their record exactly:** ids differing only by case or accents (`A`/`a`, `a`/`ä`) no longer share each other's revisions, number them together or prune each other's past `keepRevisions`; SQLite and JSON were not affected

### v0.25.0

- **MySQL / MariaDB as first-class SQL storage:** `preflow migrate`, `Schema` introspection (`hasTable`, `columns`, `addColumn`) and Folio's `folio.tables` step run on MariaDB 10.6+ (tested; MySQL 8 expected to work, not yet tested); a `SchemaGrammar` picked from the PDO driver (`SqliteGrammar` byte for byte as before, `MysqlGrammar`) replaces the SQLite-only SQL, any other driver throws `No schema grammar for PDO driver`
- **MySQL DDL:** `uuid` VARCHAR(64) COLLATE utf8mb4_bin, `string($n)` VARCHAR($n) (utf8mb4_bin when primary), `json` LONGTEXT, `integer` BIGINT, `boolean` TINYINT(1), `timestamps` VARCHAR(32); tables `InnoDB utf8mb4 utf8mb4_unicode_ci`; Folio tables use `VARCHAR(191) COLLATE utf8mb4_bin` ids and `LONGTEXT` columns; indexing a TEXT column throws a `LogicException` (use `string()`), so does a table, column or index name over MySQL's 64 characters
- **Connection:** exception error mode, `FOUND_ROWS` (an unchanged-row `UPDATE` counts as 1, as on SQLite; set at connect time, so a hand-made `PDO` takes `MysqlDriver::connectionOptions()`) and a strict session `sql_mode`; PHP booleans are written as 1/0; a field saved without migrating names `preflow migrate` on MySQL too
- **Known difference:** ids and keys compare exactly (`utf8mb4_bin`), as on SQLite; value columns stay `utf8mb4_unicode_ci`, case-, accent- and trailing-space-insensitive, so unique values and slugs that differ only that way (`cafe`/`café`) collide or shadow each other on MySQL
- **Codes:** the atomic use count works on MySQL when `max_uses` or `uses` is stored as `'3.0'` or `''`; the admin saves `max_uses` as a whole number or empty
- **Removed:** `Table::toSql()` (the grammar writes the DDL now: use `Schema::create()`)
- **Tests:** `Preflow\Testing\MysqlTestDatabase` and `composer test:mysql` (reads the gitignored `.env.mysql-test`); MySQL tests skip without `MYSQL_*` env. SQLite behaviour and DDL unchanged, no new migrations for existing sites
- Constraints: `preflow/folio`, `preflow/devtools` and `preflow/testing` require `preflow/data` `^0.25 || @dev`; `preflow/core` conflicts with `preflow/data` `<0.25`

### v0.24.1

- **Codes:** readable codes also ignore the minus sign (U+2212) and soft hyphens (U+00AD), which codes copied from a PDF or an ebook often carry
- **Folio:** a `list.columns` entry naming a hidden field is a boot error (the list used to show the hidden field); an emptied `number` setting reads as not set (its default, or null) instead of `''`; `list.sort` on a `number` field compares numbers on SQL storage
- **migrate:** without `--storage` it migrates the site's SQL storage (the default when it is SQL, else the only one; several need `--storage`) instead of always `sqlite`; migrations refuse a non-SQLite connection with a clear message, since MySQL migrations are not supported yet (lifted in 0.25.0)
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
