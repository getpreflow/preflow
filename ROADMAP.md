# Preflow Roadmap

> Last updated: 2026-11-24 (v0.87.0)

## Current State

**v0.87.0** — 10543 tests, 27 packages, production-validated via the stress-test site (admin CRUD, public forms, HTMX inline validation, custom validators with DB/rate-limiting).

The validation package has been stress-tested end-to-end: model attributes, standalone validation, custom container-resolved rules, ErrorBag with field-level template display, HTMX blur-based inline field validation, and DataManager auto-validation on save.

---

## Next Up

### The app-like site's move into Folio — framework work, in this order (owner, 2026-10-26)

The app-like site's Phase 1 is live; lists (0.29), screen assets (0.30), cache (0.33) and media (0.34–0.36) are done.
What its Phases 3–5 still need from the framework, with the matching follow-ups from the
collection-site port folded in (see "Follow-ups from the collection-site port" below):

1. ~~**Members split into a core and a Folio adapter**, together with the members follow-ups the app-like site needs:
   `next` through registration and confirmation (host invitation → register → confirm → into the group), a
   landing page at `/konto` (base of the member area / host group view), rehash on sign-in (members and Folio
   staff; migrated admin users of the app-like site), member self-deletion through the delete plan or hooks (progress, group
   memberships, certificates), a primary link (`action`) for the mail layout, the admission composing the name~~
   — done in 0.38 (prepared, not released). The audit of members against the rule below is in
   `docs/superpowers/plans/2026-10-26-release-0.38-checklist.md`; its open findings wait for the owner
2. ~~**Codes:** a redemption hook and email delivery for invitations (builds on 1's `next`)~~ — done in 0.40:
   codes bound to a recipient, `forRecipient`, `renew`, a purpose `ttl`, `CodeEvents`
   (a failing redeemed listener undoes the redemption), `CodeMailer`; the codes admin moved to Folio and
   `preflow/codes` runs without it. See `docs/superpowers/plans/2026-10-27-release-0.40-checklist.md`
3. ~~**Data integrity for the flow editor and games:** compare-and-set draft save (`UPDATE … WHERE _version = ?`)
   (done in 0.41: `DataManager::saveTypeIfVersion()`, `Publisher::saveDraft()` atomic), unique fields (game slug,
   codes) and typed columns, saving null clears an SQL column~~ — done in 0.42: saving
   `null` clears a column, unique fields (validated, with the database as the guard), typed record tables,
   `RecordTables` and the one-time `tools/convert-typed-columns.php`. See
   `docs/superpowers/plans/2026-10-27-release-0.42-checklist.md`
4. ~~**Media for the migration:** derived assets (`derived_from`) shown as stacks in the library, garbage collection
   of unreferenced assets, `importFile(…, private)`, and video thumbnails with ffmpeg~~ — done in 0.43 (released
   2026-10-28): copies (`derive()`, `makeCopy()`, makers, `remake()`, stale marks) shown as stacks in Folio's
   library; every delete goes to a bin (`bin_dir`, `media:bin`, restore); opt-in cleanup of unused assets
   (`media:cleanup`, Folio's "Unused only" filter) that only knows the usages sources report; `VideoPreviewRenderer`
   (ffmpeg path configurable, a static build works, `media:previews` covers videos; a poster still wins);
   `importFile(…, private: true)`. See `docs/superpowers/plans/2026-10-28-release-0.43-checklist.md`
5. ~~**Reporting/export and certificates (PDF)**~~ — done in 0.44: aggregate queries (`QueryBuilder::aggregate()`:
   group by, count, sum, avg, min, max, having; same rows on SQLite, MySQL and JSON), QR codes (`qr_svg`) and PDFs
   from Twig or Blade templates (`PdfDocument`, `PdfRenderer`, mPDF engine, building blocks, configured fonts).
   See `docs/superpowers/plans/2026-10-28-release-0.44-checklist.md`

   Later, from this step:
   - **Aggregates by time period** (group by day, month, year of a date field)
   - **Grouping by multiple relations** (refused today)
   - **collection-site album on the PDF part** (site work, not framework: `app/Album/AlbumPdf.php` still uses mPDF
     directly; `PdfRenderer` exists since 0.44)
   - **Blade shared-finder partial-name collision** (partly fixed: an entry template renders by absolute path, so it
     no longer collides; still open: an `@include` by bare name can pick the same-named partial of another directory,
     because each directory is added to the shared finder, `BladeEngine.php` `addLocation`)
6. **Phase 3 framework part: translation status** — released in 0.45: `TranslationStatus` finds
   missing and outdated translations per text (fields, block leaves, custom field types through
   `ReportsTranslatableTexts`), fingerprints in `_translated_from`, list badges and language tabs with counts,
   "still correct" in the form, and the opt-in `publishing.complete_languages` refusal. See
   `docs/superpowers/plans/2026-10-29-release-0.45-checklist.md`
7. **Phase 3 gaps** — released in 0.46: number `step` (exact decimal `step:n` rule), share
   links on every language, component actions keep the locale prefix, composite indexes in migrations,
   `revisions: 0` keeps all, `cache_path`, HEIC/HEIF as JPEG (with `heic_max_pixels`). See
   `docs/superpowers/plans/2026-10-29-release-0.46-checklist.md`
8. **AI connection and auto-translate (0.47)** — released in 0.47: `preflow/ai` (Claude and Gemini behind
   `AiClient`, keys from the environment, a price table, a usage log without text, `ai:check`, `ai:usage`,
   `FakeAiClient`); machine translation in `preflow/i18n` (`MachineTranslator`, layered tuning, a glossary,
   `MarkupCheck`, `Estimator`, `TextSource` and `JobRunner` for any data); and in Folio a Translate button with
   side-by-side review, whole-type runs as drafts, "not checked" markers (`publishing.checked_translations`),
   labels and media alt texts, Settings → AI, and the `ai.translate` and `ai.settings` permissions. AI stays off
   until `config/ai.php` is enabled and a key is set. See `docs/superpowers/specs/2026-10-30-ai-translation-design.md`
   and `docs/superpowers/plans/2026-10-30-release-0.47-checklist.md`
   **Upgrading:** SQL sites run `preflow migrate` after upgrading: the new `_translated_source` and
   `_machine_translated` columns are written on every translation save, with AI off too, and publishing
   fails until they exist. Each site's deploy must run it.

9. **AI translation in the background (0.48)** — released in 0.48: a record's Translate queues a job that
   `translate:work` (cron, every minute) works in short steps, with a lease per record so nothing is paid twice
   (`preflow/i18n`: queue, `TranslationWorker`, `ReviewIndex`); the Folio run screen is a status page; the dashboard
   has a "Waiting for review" section; publishing clears the "not checked" marks of the visible languages, and
   `publishing.checked_translations` is removed. See `docs/superpowers/specs/2026-10-30-ai-translation-background-design.md`
   and `docs/superpowers/plans/2026-10-30-release-0.48-checklist.md`
   **Upgrading:** sites with AI on add the cron line `* * * * * cd <app> && php vendor/bin/preflow translate:work`,
   and run `php vendor/bin/preflow folio:review-index` once. No `preflow migrate` needed.

**Rule (owner, 2026-10-26):** the framework holds abstractions, interfaces, hooks and generic mechanisms only;
a feature one project needs is built in that project on top of them. A package's official admin UI lives in Folio
(as media's library does); custom admin needs use userland Folio extensions. Steps 1 and 2 include an audit of
members and codes against this rule.

**Audit of all packages against this rule** (after the app-like site steps, or alongside when a package is touched):
go through every package (Folio, media, rbac, i18n, auth, data, notify, validation, devtools, …) for features that
came from one project's needs, and turn each into a hook, a config option or an extension example, or move it into
the project; note breaking changes for the consumers. Status (checked 2026-11-11): members was audited in 0.38
(findings F1–F8 in `docs/superpowers/plans/2026-10-26-release-0.38-checklist.md`, waiting for the owner); no record
for codes, Folio, media, rbac, i18n, auth, data, notify, validation or devtools.
**Done 2026-11-12:** all 25 packages audited (`.superpowers/audit-2026-11-12/`, local); the owner decided to fix
all of it: spec `docs/superpowers/specs/2026-11-12-package-audit-fixes-design.md`, releases 0.67 (clean-ups, done)
→ 0.68 (mail in notify, members neutral, codes mailer to BG; done) → 0.69 (rbac without Folio; done) → 0.70 (done: preflow/blocks and rules out of Folio) → 0.71 (done: preflow/publishing, one record
write path) → 0.72 (done: gaps the starters fill).
**Order (owner, 2026-11-11):** docs phase 3 → 0.66.0 → this audit → docs phase 4 (shorter READMEs + reference descriptions) → the external scripts/CSP release.

Useful during the app-like site Phase 3, not blocking: route order by specificity (partly done, see "Routing, core and
i18n"), controller discovery beyond `app/Controllers`, ~~CSRF without a session~~ (done, see "Security and data integrity"), staff mails in the
site's mail layout.

### ~~Form Package~~ — done in 0.13.0 as `preflow/form` (`form_begin()`, `form.field()`, `form.submit()`, model binding, ErrorBag); the helper names sketched below were not used

An `ActiveForm`-style template layer built on top of `preflow/validation`. Replaces repetitive field/error/old-input boilerplate with declarative helpers:

```twig
{{ form_field('username', {label: 'Username', required: true}) }}
{{ form_field('email', {label: 'Email', type: 'email'}) }}
{{ form_select('role', {label: 'Role', options: {editor: 'Editor', admin: 'Admin'}}) }}
```

Error display, `is-invalid` class toggle, `old()` re-population, and help text handled internally. The validation ErrorBag already provides the data layer.

### Guide-Level Tutorial

A step-by-step guide building a complete small application covering file-based + attribute routing, components with HTMX actions, data models with validation, authentication, and deployment.
Partly done (checked 2026-11-11): preflow.dev's Start part covers install, a tour, a file page and a `#[Route]`
controller, a component with an action and its tests, and deploying. Still missing: a Start step that adds a model
with validation and sign-in end to end (today those live in the Build guides).

### ~~Props vs Action Params Clarity~~ — done in 0.13.1: `$this->param()` (action params first, then props), documented in the Build guide (HTMX)

The distinction between component props (encoded in token) and action params (POST body) caused bugs in admin components. Options:
- Merge params into props automatically so actions always see the full picture
- Provide `$this->param('key')` helper that checks both
- Better documentation + skeleton examples showing the pattern

---

## Medium Term

### Reference descriptions (owner, 2026-11-11; with docs phase 4, after the package audit)

On preflow.dev's reference pages, many entries have an empty Description column; one example is the REST APIs topic: `TokenAuthMiddleware`, `PersonalAccessToken`, `TokenGuard`, `JsonBodyMiddleware`. The description is the first sentence of the entry's docblock in the framework. The website's `php preflow docs:check` counts what's missing. At v0.65.0, 3033 of 5499 entries have no description:
- by kind: 1933 methods, 610 properties, 172 classes, 114 constants, 75 config keys, 58 enum cases, 31 template functions, 25 interfaces, 10 enums, 3 field types and 2 traits;
- by package, the largest: folio 636, core 395, data 329, view 310, media 223.

`docs:check --verbose` names each one.

Plan:
1. **Classes, interfaces, enums and traits first.** These are about 210 entries, and they are what the topic pages list. Then the config keys and template functions, then public methods. Skip properties, constants and cases whose name already says it all; private API never appears in the reference.
2. **Write each description like the existing good ones:** one plain sentence that says what the thing is for, not how it is implemented, e.g. "CORS for the API paths (config/api.php `cors`)." Do one package per commit, and check it on the website: `docs:reference`, then the topic pages.
3. **Do it together with phase 4**, which shrinks the package READMEs. The detail a README drops goes into the docblocks and the guides, so it isn't lost.
4. **Ratchet, so it stays done.** Once a kind is complete, `docs:check` fails on a new entry of that kind without a description, instead of only warning. Start with classes, interfaces, enums, commands and config keys.

### ~~Starter: a polished employer sign-in (owner, 2026-11-11)~~ — done in 0.66: a centred card with a lead line, labelled fields and a full-width button, the error as an alert, the demo account as a dashed hint (only while developing and while the demo seed's account exists), both themes, 375 px. starter-folio's public pages and Folio's sign-in were checked at 375 px in both themes and needed nothing. Sync the starter files into the website with the release.

The employer sign-in of `preflow/starter` (`/employer`, `app/pages/_views/employer/login.twig`, from
`EmployerAuthController`) looks unstyled. Even as a demo it must not look careless: give it the same care as the
public job pages: a centred card, clear labels and errors, the demo login hint styled as a hint, both themes and
375 px. Check starter-folio's public pages and Folio's own sign-in with the same eye. Sync the starter files into the
website afterwards, since the docs show them.

### External scripts and styles with a matching CSP (owner, 2026-11-11)

**Order (owner, 2026-11-11):** its own release, after the audit of all packages against the rule (see "Next Up") and docs phase 4.

Sites need third-party files: analytics (Google Analytics), consent managers (Usercentrics), maps, chat widgets.
Today an app writes the tag by hand (`AssetCollector::addHeadTag()` or a template, with `nonce="{{ csp_nonce() }}"`)
and edits `config/security.php` `csp` itself. A listed directive *replaces* the default sources, so it must repeat
`'self'`, and the extra hosts those scripts call (`connect-src`, `img-src`, `frame-src`) are easy to miss.

Wanted: one supported call that adds the file and widens the CSP to match, e.g. `addScriptFile($url, …)` /
`addCssFile($url, …)` on the asset collector, with template functions for both, plus named integrations
that declare the full set of sources a service needs, so a site turns on "Google Analytics" or "Usercentrics" instead
of hand-collecting hosts. Design points:
- The CSP stays strict by default. A source is added only for pages that use the file, or site-wide when
  configured. Directives *add to* the defaults instead of replacing them. Script tags keep the request nonce.
- The page cache stores each page's extra sources with the entry, so a cached page is sent with the same policy.
- Subresource integrity (`integrity`, `crossorigin`) where a file has a fixed version. `async`/`defer`/`type=module`.
- Consent: Preflow is cookie-free by default. An integration that sets cookies runs only after consent, and the
  consent manager's own script loads first. Decide how consent gates other integrations (a hook the consent manager
  answers), without Preflow storing consent itself.
- The security-headers docs and the assets guide show it; `config/security.php` stays for site-wide rules.

### ~~Starters' test copies can't see packages (found 2026-11-11)~~ — done in v0.66.1

**v0.66.1:** `tests/Support/StarterApp.php` in preflow/starter and starter-folio copied the project without `vendor/`,
while core reads installed packages from `vendor/composer/installed.json`, so a test copy saw none of them (80 in the
project, 0 in the copy): a package's `extra.folio` field types fell back to `StringFieldType`, its blocks,
translations (`extra.preflow.translations`) and console commands (`extra.preflow.commands`) were missing, and the boot
manifest (`preflow optimize`) and page cache key were built without them. Each copy now links `vendor/` to the
project's (`projectVendor()`), so every reader of the installed packages sees exactly what the project sees; no core
change was needed. `SetupSnapshots` (preflow/testing) keeps an `ignore()`d symlink and never follows it (any other
symlink under a root still throws), and the copy ignores `{starterTmp}/vendor`. Tests: `InstalledPackagesTest` in both
starters (the copy's packages equal the project's; in starter-folio a tiny path package declaring a field type through
`extra.folio` resolves in the copy), and `SetupSnapshotsTest` for the ignored link.
**Upgrading:** a project made from a starter keeps the `tests/Support/StarterApp.php` it was created with. Copy the new
file from preflow/starter or preflow/starter-folio 0.66.1, or apply the change by hand: after the copy loop in
`bootStarter()` add `symlink($this->projectVendor(), $this->starterRoot . '/vendor');`, add
`->ignore('{starterTmp}/vendor')` after `->root('{starterTmp}', …)`, add a `projectVendor()` method that returns
`dirname(__DIR__, 2) . '/vendor'`, and name the seed step after the installed packages
(`'seeded ' . hash('xxh3', (string) @file_get_contents($this->starterRoot . '/vendor/composer/installed.json'))`
instead of `'seeded'`, so a snapshot is never reused across different packages). It needs preflow/testing 0.66.1
(`composer require --dev preflow/testing:^0.66.1`; the starters now ask for it): 0.66.0 refuses any symlink under a
snapshot root. Creating the link needs symlink rights (on Windows, Developer Mode or WSL).

### ~~Starter set-up snapshots never reused since 0.66.1 (found 2026-11-12)~~ — done in v0.66.2

**v0.66.2:** since 0.66.1 a starter's test copy links the project's `vendor/`, so core cached the installed packages in
the copy's `storage/cache/composer/installed-<hash>.php`, and that file held the copy's absolute path (in its key, and
its name was a hash of that path). `SetupSnapshots` rightly refuses a snapshot whose files name its root, so migrate
and the demo seed ran for real in every test: a fresh starter's suite took 38 to 40 s instead of 18 s. The composer
cache (`Preflow\Core\Composer\InstalledPackages`) now names installed.json relative to the cache folder, in the file
name and the key, so it moves with the project like the other caches (release folders too) and holds no absolute
path. A probe of a migrated and seeded copy of both starters found no other cache file naming the copy's path. The
six sites' fast suites boot the real project directory, so their composer cache lives outside the snapshot roots
and was never affected. Tests: core's `InstalledPackagesTest` (the cache holds no absolute path and is a hit in a
copied project), both starters' `SetUpSnapshotTest` (a second copy gets its set-up from the snapshot; the harness's
`onRestore` sets `$setUpRestored`).
**Upgrading:** nothing to do for sites. Projects made from a starter may copy the new `tests/Support/StarterApp.php`
and `tests/Site/SetUpSnapshotTest.php`; the speed comes back with preflow/core 0.66.2 alone.

### ~~Throttle and codes SQL bypass the `Dialect` (found 2026-11-11, final docs review)~~ — done in v0.66.1

**v0.66.1:** core's `Throttle` hit and codes' use counting wrote their own SQL by checking `instanceof MysqlDialect`
(else SQLite's syntax), and codes compared the `max_uses` number column with `''`, which PostgreSQL refuses, so neither
ran on a third-party SQL dialect. Two `Dialect` methods (preflow/data) now write that SQL:
`insertOrUpdateSql($table, $columns, $idField, $set)` (one statement inserting the row or setting SQL expressions on the
row with that id: SQLite `ON CONFLICT(id) DO UPDATE`, MySQL `ON DUPLICATE KEY UPDATE`; null where a dialect has none,
and the throttle then counts under its file lock) and `wholeNumber($expr)` (NULL for NULL and `''`, the whole part of
a number or of text holding one, 0 for other text; safe in an `UPDATE` under MySQL's strict mode, never compares a
number with `''`). Codes' limit is "`wholeNumber(max_uses)` is NULL or the count is below it". The throttle's expressions read the
existing row through table-qualified columns, which PostgreSQL requires in `ON CONFLICT DO UPDATE` (a review found a
bare name fails there with "column reference is ambiguous"); the `Dialect` docblock asks for that form, and a test
checks the throttle sends it. `PdoDriver`'s version check (compare-and-set saves) no longer compares the version
column with `''` either: "no version yet" is `wholeNumber(version) IS NULL`. preflow/testing has a new contract
suite, `DialectContract`, that runs both methods on a dialect's real database (SQLite and MySQL run it). SQLite's
results are unchanged. A grep of the
packages found no other dialect or driver-class checks: `Grammars` picks a schema grammar by PDO driver name, which is
its registry for third-party grammars; `Application` maps the built-in driver names to their classes; the page cache's
`TagIndex` and `RefreshQueue` use their own SQLite files. Tests: both methods on SQLite and on MySQL (strict mode, in
an `UPDATE`), and the throttle and codes on a third-party `PdoDriver` whose dialect records what it is asked (with and
without an insert-or-update statement).
**Upgrading:** codes on MySQL now reads odd text values in `uses` and `max_uses` the way SQLite and PHP's `(int)`
do (owner-approved; no live site holds such values): `uses` stored as `'3.0'` counts as 3 (before, MySQL read it as 0
and the next redeem wrote 1), a `max_uses` of `'2.5'` allows 2 uses (before, the SQL check allowed a third), and
negative text is read as that negative number (before, 0). A custom `Dialect` (a third-party SQL driver) must
implement `insertOrUpdateSql()` and `wholeNumber()`, return null from `insertOrUpdateSql()` when the database has no
such statement, and can check both with `DialectContract`. Your own `insertOrUpdateSql()` callers must qualify the
columns their expressions read with the table. preflow/core and preflow/codes 0.66.1
conflict with preflow/data before 0.66.1, so `composer update` brings them together.

### Datastar's CSP mode (owner, 2026-11-11)

Datastar compiles its expressions with `Function()`, so under Preflow's CSP it needs `'unsafe-eval'` in `script-src`
(`config/security.php`); the hypermedia-driver guide documents that and its cost. Datastar's CSP mode avoids
`'unsafe-eval'` but reads the page nonce from a data attribute on `<html>`, which Preflow's driver rule forbids (a
nonce in a readable attribute can leak). Supporting it is a framework change with its own security design and review:
how the nonce reaches Datastar without being readable by injected markup or CSS, then a release and an update of the
guide to prefer that mode.

### Config reference: `app.hypermedia` (found 2026-11-11)

The core config reference has no entry for `app.hypermedia` (0.65), so the docs can't link it from the configuration
table; add the entry with the next release.

### Performance Benchmarks

Owner todo (2026-10-26). Measure Preflow against other PHP frameworks (for example Laravel, Symfony, Slim) so claims about speed rest on numbers:
- requests per second, latency (median and p95) and memory per request
- two cases: a public frontend page (a Folio page with a few blocks and an image) and the Folio admin (a list screen and an edit screen)
- same PHP version, same opcache settings, same database; the method and the setup published with the results so anyone can repeat them

### Documentation for Humans

Owner todo (2026-10-26). Review and rewrite the preflow.dev docs so a newcomer can use them:
- plain explanations first, internals second; task-oriented structure ("add a page", "protect a route", "send a mail") rather than one page per class
- practical examples that run as written
- ~~Folio gets its own top-level navigation entry on preflow.dev, next to Home and Docs~~ — done (website `_layout.twig`)
- In progress as the docs rewrite (phases 1–2 live, phase 3 Folio and Extend in progress, phase 4 shrinks the READMEs)

### Database Relations

Simple eager loading for related models:

```php
$posts = $manager->query(Post::class)->with('author')->get();
```

Or relation attributes on models. The `raw()` method covers complex JOINs for now.

### ~~Route Caching~~ — done in 0.59.0: compiled route sets in the boot manifest (`preflow optimize`, drift check `optimize --check`); the old `RouteCompiler` class is unused

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
Partly there (checked 2026-11-11): `#[BeforeSave]`/`#[AfterSave]`/`#[BeforeDelete]`/`#[AfterDelete]` exist for Folio types
only, and data has one after-write `DataObserver` (0.60, cannot refuse). Still missing: before/after events on
DataManager itself, with listeners that can refuse, for code outside Folio.

---

## Follow-ups from the collection-site port

Found while porting a collection site (2026-10-26). Each has a site-local workaround there, described in its
`docs/framework-followups.md` with how to remove it once the framework has the feature. Checked against v0.37.0:
all of these are still open. Already done: the expired-session CSRF handler (0.31) and the copyright hook
(`#[AfterSave]` on an extension type, no framework change needed).

### Security and data integrity

- ~~**Rehash on sign-in (members, Folio staff login):**~~ done in 0.38 (`PasswordRehash`, `UpdatesPasswordHash`): after a successful `verify()`, call `needsRehash()` and store a fresh hash (old `$1$` MD5-crypt and low-cost bcrypt hashes are never upgraded today). `needsRehash()` exists in `NativePasswordHasher`; no login calls it
- ~~**CSRF without a session (core):**~~ done (`CsrfMiddleware` checks every POST, PUT, PATCH and DELETE: the browser's origin first (`Sec-Fetch-Site`, else `Origin`, else `Referer`; a refused origin is refused with any token), then the session token, or without a session a stateless token signed with `app.key` (24-hour slots, current and previous) that `csrf_field()` / `csrf_token()` print on public pages; `#[CsrfExempt('why')]` on controllers and routes; `config/csrf.php` `exempt`, `trusted_origins`, `sign_in_paths`; a sign-in form left open past the session's end still signs in (Folio's and members' logins register themselves), any other expired form is sent back with a warning and its fields kept (`OldInput`), never applied). **Upgrading:** unsafe requests without a session were unchecked until now. Sites with server-to-server POSTs (webhooks, payment providers, cron over HTTP) mark the route `#[CsrfExempt('…')]`, or list file routes and paths outside the router in `csrf.exempt`, before upgrading; browser POSTs coming from other sites (a payment provider's return URL that posts back, OAuth/SAML `form_post` callbacks) carry `Sec-Fetch-Site: cross-site` and are refused now even on public paths, so such routes need `#[CsrfExempt('…')]` too; behind a TLS-terminating proxy set `app.url`, and set a real `app.key`. Release checklist, by hand: the personal site's contact form, the collection site's like and contact forms, and every site's payment return and OAuth/SAML callback routes (cross-site POSTs). the collection site can then replace its `App\Http\FormToken` with `csrf_field()`
- **Translation params in one pass (i18n):** `Translator::replaceParams` substitutes sequentially (`str_replace` per param), so a value containing `:other` is replaced again; use one `strtr` pass. Also a plain-text variant of `t()` (not html-safe, params unescaped) for values passed to functions that escape themselves (e.g. `media_image(…, {alt: …})`)
- ~~**Saving null clears SQL columns (data):**~~ done in 0.42 (`save()` writes nulls; a partial save leaves the other columns alone; SQLite upserts on the primary key, MySQL updates)
- ~~**Unique fields and typed columns (data/Folio):**~~ done in 0.42 (`unique`, `index` and `length` field options, `Unique` rule plus the database guard, typed record tables, `RecordTables`, the conversion script)
- ~~**Restoring a revision whose unique value is taken (Folio):**~~ done in 0.42 (restore and discard flash the field error and go back to the editor; autosave answers it in the save state; drafts and restores also check bounded lengths, `DataManager::validateStorable()`)
- ~~**Model unique property without null (data):**~~ done in 0.42 (a nullable property stores `''` as null; a non-nullable `string` is documented as unsupported): a `Model` unique property typed `string` (not `?string`) keeps `''`, so a second empty value is reported "already taken"; the property has to accept `null` (document it or store `null` through the hydrator)
- **Raw `inTransaction()` inside SQLite `exclusive()` (data/Folio):** `DeletePlanner`, `Publisher`, `RecordDeleter` and `Transfer\Importer` ask `$pdo->inTransaction()`, which on PHP 8.4 is true inside the driver's own `BEGIN IMMEDIATE` and would nest a `BEGIN` if they ever ran there (not today); ask the driver
- ~~**MySQL `GET_LOCK` inside a caller's transaction (data):**~~ documented in 0.42 on `MysqlDriver::exclusive()` (the unique index is the guard): the unique check's lock is released at the end of `exclusive()`, before the caller's commit, so the check does not cover a write the caller commits later (the unique index still refuses it); document in `ExclusiveWrites` or hold the lock to the commit
- ~~**Compare-and-set draft save (Folio publishing):**~~ done in 0.41 (`VersionedStorage::saveIfVersion()` on SQLite, MySQL and JSON, `DataManager::saveTypeIfVersion()`; `Publisher::saveDraft()` checks in the write, autosave and form save with it; `Publisher::stateWith()`, `states($type, $ids)`)
- **Sequences (data):** a sequence / next-number helper for public numbers
- ~~**Version-checked publish (Folio):**~~ done in 0.41.1 (`Publisher::publish($record, $by, $loadedVersion)`: claims the working copy, version kept, then the snapshot, in one SQL transaction; `null` stays unchecked for the scheduler, CLI and importers; Folio's form publish passes its version; `discard()`/`restore()` take one too)
- ~~**Member self-deletion and delete plans (members):**~~ done in 0.38 (`onSelfDelete`, `MemberEvents`, `DeletionResult`): `Members::delete()` (account page, `members:prune`) uses `deleteType()` and ignores relations and Folio's delete plan (`restrict` relations are bypassed, e.g. orders keep a dangling member id). Wanted: delete through the delete plan, or before/after hooks
- **Private files: session and owners (media/Folio):** partly done: a per-owner rule is possible through the
  `PrivateFileAccess` hook (Folio binds `FolioPrivateFileAccess`). Still open: private files live at `/files/{id}`, outside Folio's session path, so sites must add `/files` to `session.paths`; serve them under Folio's own path (e.g. `{prefix}/files`) or start the session there. Optional: a per-owner rule (callback or record field) so members can see their own private files

### Media and assets

- ~~**HEIC/HEIF uploads:**~~ done in 0.46 (converted to JPEG with Imagick, `heic_max_pixels`)
- ~~**`importFile(…, bool $private)`**~~ — done in 0.43
- ~~**Derived assets** and **asset garbage collection**~~ — done in 0.43 (copies as stacks in the library; opt-in cleanup into a bin)
- **External video embeds (later, if a project needs one):** YouTube/Vimeo as their own field type (an embed URL with a poster), not a media asset: the library holds files only

### Folio

- **List values of relation and asset fields in JSON files (data/Folio, after 0.78):** since 0.78 a JSON record file is written in one canonical form (`DataManager::storageValues()`: field order, tags as a list, numbers and yes/no values as such). A multiple relation field and an asset field still hand DataManager their ids as JSON text, so their files hold a JSON string; it is deterministic, so the bytes are stable. Wanted: both as list kinds (`StoresList`), with their readers (relation `HAS`, asset usage, the model bridge) checked for a list
- **Types with files and `"transfer": false`:** refused today for types with `asset`, `blocks` or `matrix` fields; wanted: exclude single fields from transfer, or transfer a type's files without its records
- **`list.filters` (and `list.sort`, `list.bulk`) in an `extends` model file:** only `fields` and `list.columns` are allowed (`ModelExtension::merge`)
- **Staff mails with the site's mail layout:** `StaffMailer` sends plain text only; a `mail.layout` option like members' (lang, subject, paragraphs, optional action)
- **Phone layout fixes:** (`.folio-side` itself has `min-width: 0`; its panels and `.folio-form-extras` don't) `min-width: 0` on `.folio-form-extras > .folio-panel` and `.folio-side > .folio-panel` (a wide table makes the form page scroll sideways at 390 px); check the list's tab row at 390 px in WebKit (the table itself already has an `overflow-x` wrapper)

### Members

- ~~**Admission supplies the name:**~~ done in 0.38 (`ComposesName`): registration requires one posted `name`; let an admission compose it (e.g. `Admission::name($input)`) or let a site drop the `name` field (sites asking for first and last name)
- ~~**`next` through registration and confirmation:**~~ done in 0.38 (`next.allow`): kept today only on sign-in; carry a checked `next` with the pending account so a checkout can continue after registering
- ~~**A landing page at `GET /konto`:**~~ done in 0.38 (a default landing; the site's own route wins): confirm and login redirect there, but the package has no page; a configurable template or callback
- ~~**Primary link to the mail layout:**~~ done in 0.38 (`action` for confirm, reset and existing): `MemberMailer` passes only lang, subject, accent and paragraphs; pass `action: {label, url}` for confirm, reset and "existing account" mails
- **Italian (`it`) texts:** the package ships en, de and fr
- ~~**Member lock, sessions and sign-in info (after the split):**~~ done in 0.39 (`Members::lock()`, `unlock()`, `isLocked()`, `endSessions()`, `resendConfirmation()`, `locked_at`, `last_login_at`; Folio: Locked pill, Lock / Unlock / Resend confirmation in the Decision panel): `Members::lock()`, `unlock()` and `isLocked()` with a readonly `locked_at` (a lock covers every sign-in path and shows a "Gesperrt" pill, never "declined"); `Members::endSessions()` (`auth_epoch` + 1 and `RememberMe::revokeAll()`); `Members::resendConfirmation()` (the public resend shares its code path); a readonly `last_login_at` set on sign-in, reset and confirmation
- **Folio hook bridge for member self-deletion:** a member's own deletion runs `MemberEvents` but not Folio's record hooks on `member` (Folio already unpublishes the member's snapshots on `DELETED`, `PublishedMemberDeletion`). Bridge them in Folio's members adapter: `DELETING` runs `#[BeforeSave]`/`#[BeforeDelete]` and can refuse, `DELETED` runs `#[AfterSave]`/`#[AfterDelete]`. Before the collection site drops its workaround

### codes

- **`renew()` against a concurrent revoke:** `renew()` checks the code outside the lock and its `UPDATE` has no guard, so a revoke landing between the check and the write can be renewed over (the code revives). Guard the write (`WHERE revoked_at IS NULL`, the JSON write under the lock re-checks)
- ~~**Index on `(recipient, purpose)`:**~~ done in 0.42 as an index on `recipient` (`forRecipient()` no longer scans; `purpose` is only the scope of the unique `code`)

### rbac

- ~~**Grant-once role seeding:** `RoleStore::ensure()` never changes an existing role, so later-added permissions never reach seeded roles; an ensure that grants new permissions once and remembers them (an editor's removal stays)~~ — done in 0.53.0
  (`RoleStore::ensure()` gives an existing role the listed permissions it never gave before and records the list in the
  role's hidden `seeded_permissions` field, so an editor's removal stays; a role without a record gets one and nothing
  added. Folio extensions have `seed()`, which `folio:extensions:apply` runs on every deploy, after `install()`; rbac and
  the members extension seed their roles there. New: Folio's list of known permissions
  (`folio_known_permissions`), `#[ListNotice]` and `#[DashboardNotice]` on type customizations, `FolioScreen(nav: false)`;
  with rbac, *Settings → Roles* announces every new permission to whoever may edit roles (notice with "Add to role" and
  "Dismiss", sidebar count, dashboard line); see `packages/rbac/README.md` and `packages/folio/README.md`).
  **Upgrading:** SQL sites run `preflow migrate`, which creates `folio_known_permissions`; until then the notice shows
  nothing. Extensions that seed roles in `install()` move that to `seed()`; the app-like site moves its `ensure()` calls there.
  The collection site can drop `grantOnce()` and delete its `rbac_role.json` extension in the same commit as the upgrade
  (a site's own `extends` file adding `seeded_permissions` to `rbac_role` stops the boot, with an error naming the
  field); its stored records carry over. Check each seeded role once: a permission listed before 0.53 that never reached
  the role stays missing, because a role without a record is assumed to have had its seed list already. A seeded role
  deleted in Folio comes back on the next deploy; remove it from `config/roles.php` (or the extension's seed) first.

### Routing, core and i18n

- **Route order by specificity:** partly done: `RouteMatcher` tries static routes, then dynamic ones, then catch-alls, and file routes are sorted. Still open: between two dynamic routes (`/a/{x}` vs `/{x}/b`) dynamic routes match in collection order (declaration order, then unsorted `DirectoryIterator` discovery across controllers); a static segment should win over a parameter regardless of controller
- **`#[Options]` route attribute** (sites subclass `HttpMethod` today)
- **Controller discovery beyond `app/Controllers`:** configurable directories, or every `#[Route]` class autoloaded under `App\`
- ~~**JSON query cache follows the data path:**~~ done in 0.58 (cache files per data path, `DATA_CACHE_PATH`): `Application` uses `storage/cache/data` whatever `json.path` is, and cache files are named by type only (tests with a temp `DATA_PATH` write into the project's cache)
- **Prefix the default locale (i18n):** an option to serve the default locale with its prefix (`/de/…` canonical, `/…` redirecting), for sites whose old URLs carried the default language

### Protected APIs (auth, later)

The base exists (`TokenGuard`, `PersonalAccessToken`, `AuthMiddleware`), and since 0.62.0 `TokenAuthMiddleware`, a
configurable CORS middleware (`config/api.php`) and `preflow auth:token` (issue, list, revoke) are done. Missing:
token management in Folio (create, name, list, revoke; shown once; per staff account or member), scopes per token
checked per route, per-token rate limits, and caching of API GETs (needs its own design: bearer requests must never
get or store a shared copy).

---

## Later: SEO and GEO

Owner (2026-10-26). What exists today: canonical links and `hreflang` alternates (0.35), `noindex` on the
member pages. Later, as generic mechanisms (a site's own wording and data stay in the site):

- ~~**`sitemap.xml` and `robots.txt`:** generated from the routes and Folio's published pages (per locale, with
  `hreflang`), a hook for a site's own entries; `robots.txt` from config, pointing at the sitemap~~
- ~~**Meta and Open Graph helpers:** title, description, image (a media preset), `og:*` and Twitter cards from one
  template helper; a Folio SEO panel on publishing types (title, description, image, noindex per page and locale)~~
- ~~**Structured data (JSON-LD):** helpers for the common types (Organization, WebSite, Article, BreadcrumbList,
  Product, Event), filled from a record or a page~~ — SEO basics done in 0.50.0 (`config/seo.php`: robots, Open
  Graph, Twitter card, theme-color and JSON-LD tags, `/robots.txt`, `/sitemap.xml`, `/.well-known/security.txt`,
  ETag/304; `seo()` / `@seo`, the `PublicPages` list shared with GEO, `SchemaRegistry` for a site's own types, the
  media library's `share` format, Folio's "SEO & sharing" section per record; see the SEO section in
  `packages/view/README.md`). **Upgrading:** SQL sites run `preflow migrate` after upgrading and before turning
  SEO on: the section is stored in the new `_seo` column, which every editor save writes once the section shows
  (SEO on), and saving fails until it exists. Each site's deploy must run it. In 0.53.1: a page whose content is
  public although its response is private (it embeds one visitor's tokens) gets cards and JSON-LD with
  `seo({public_content: true})` (`PageMeta::$publicContent`); no ETag, its Cache-Control untouched, `noindex` wins.
  In 0.53.2: GEO's `/llms.txt` heading "Other languages" is in the file's language (`geo.other_languages`, en/de/fr
  in preflow/view, a site's `lang/{locale}/geo.php` overrides it; a language without the key falls back to the
  site's fallback language, then English; English without a translator). Also in 0.53.2: a 304 carries no
  `Content-Security-Policy` (nor Report-Only or `HX-Nonce`), so the browser keeps the cached page's policy; since
  0.50 the 304 repeated the 200's headers with a fresh nonce and blocked every inline script and style of the page
  (`SecurityHeadersMiddleware::withoutNonce()`, used by SEO's ETag check and on any 304 it decorates); the ETag
  format changed to `W/"2-…"` so caches the bug broke get one fresh 200.
- ~~**301 redirects on slug change:** Folio remembers a page's old paths and redirects them; a redirects list in
  Settings for hand-made entries~~ — done in 0.52.0 (`preflow/routing`'s `RedirectMiddleware` answers a public
  GET/HEAD that ended in a thrown 404 from a `RedirectStore`, exact entry before the longest area, 301/302 with the
  query string kept, and notes the rest in a `NotFoundLog` without visitor data and without scanner noise;
  `config/redirects.php`; core's `NonPublicPaths`, registered by Folio, members and media. Folio: renamed pages
  redirect automatically per language, deleting or unpublishing a page asks "Send its old links to…" (a page, a URL
  or none), the editor's "Old addresses" note, Settings → Redirects with hand-made exact and area entries and the
  not-found list, permission `redirects.manage`; see the Redirects sections in `packages/routing/README.md` and
  `packages/folio/README.md`). **Upgrading:** SQL sites run `preflow migrate`, which creates the three tables and
  records every live page's current addresses; without that run, the first rename of a page after upgrading
  redirects only for publishing types. Also in 0.52.0 (owner: addresses never hold umlauts): the fields a page's
  address is built from only take lowercase `a-z`, digits and single hyphens (new rule `slug` / `slug:path` in
  `preflow/validation`, `Slug::clean()`); the editor and machine translation convert what is typed or translated
  (`Über uns` → `ueber-uns`), `frontend.home` must follow the rule too. Before upgrading, check that no page's address
  breaks the rule: saving such a page from the editor converts the address in the language being edited (the old
  address then redirects), and a bad address in another language refuses the save (for publishing types: the publish) until it is fixed
  there. A machine translation of an address that leaves nothing in a-z is not written and stays missing. A site
  without any `frontend` block whose `page` model is served by its own routing also gets its `slug` checked, since
  Folio's fallback route `/{slug}` serves it.
- ~~**GEO (generative engine optimisation):** `llms.txt` and plain-text/Markdown views of pages for AI search.
  Owner's idea: an `{% apply geo %}` block in component templates, like `{% apply css %}`, holding the
  component's Markdown; the blocks of a page are collected in render order and served as `/page.md` and for
  `Accept: text/markdown`, and listed in `llms.txt`~~ — done in 0.49.0 (`config/geo.php`, `{% apply geo %}` /
  `@geo`, `data-geo="skip"`, `.md` views of public pages, `/llms.txt` and `/llms-full.txt` per locale from Folio's
  published pages plus the `GeoEntries` hook; see the GEO section in `packages/view/README.md`)
- ~~**An SEO audit command:** `preflow seo:audit` checks pages for a missing title or description, duplicate titles,
  missing `alt` texts, broken internal links and `hreflang` pairs without their counterpart~~ — done in 0.54.0
  (`preflow seo:audit [--fresh] [--limit=N] [--url=/path] [--budget=600]` renders every public page inside the
  app, anonymously and without a trace (`InternalRender` marker: no not-found entries or redirect hits, no image
  variants, no debug toolbar, no session, no queued mail), runs page, run and site checks, saves a resumable report in
  `storage/data/seo-audit.json`; site checks register on `AuditRegistry`; Folio: *Settings → SEO audit*, read only;
  see the SEO audit section in `packages/view/README.md`).
  **Upgrading:** nothing to migrate. `composer update`, then run `preflow seo:audit` on each environment. The new
  permission `seo.audit` is announced in *Settings → Roles* on sites with rbac; sites using `preflow/roles`
  (config roles) grant it in `config/roles.php`.
  0.54.1: a description cut that lands on a space keeps its last word (`HeadTags::description`); the audit
  screen's collapsed groups show their arrow in every browser; the new-permissions notice offers Dismiss only for
  permissions the user holds (a delegated role manager can't hide one from those who could grant it).
- ~~**GEO follow-ups (0.55):**~~ done in 0.55.0 (Markdown views send `Link: <canonical>; rel="canonical"` and an
  `Updated:` line from `og:updated_time`; `PageMeta::$modified` (`seo({modified: …})`, Folio: the publish time) gives
  `og:updated_time` and the WebPage's `dateModified`; audit checks `geo.markdown-thin`, `geo.type-schema-missing`,
  `llms.full-too-large` with `full_max_mb` in `config/geo.php`). **Upgrading:** nothing to migrate. Every Folio page
  now carries its record's publish time as `og:updated_time`, the WebPage's `dateModified` and the Markdown's
  `Updated:` line; on a listing or home page that date doesn't follow records shown on it (a per-type opt-out can
  follow if a site needs one). The two GEO page checks render more: `geo.markdown-thin` each page's `.md` once more,
  `llms.full-too-large` the whole llms-full.txt per language; a long audit continues where it stopped as before.
- ~~**Fast test suites (0.56):**~~ done in 0.56.0 (`preflow/testing`: `Fast\SetupSnapshots` runs a set-up step such as
  migrate or seed once and restores its result for every later test that starts from exactly the same state: same
  environment, same files under the registered roots, same tables; any difference runs the step for real. `Fast\MysqlSnapshotDatabase`
  for MySQL, SQLite needs no adapter. `Fast\TestWorker` names databases and directories per paratest worker. No test is
  skipped or weakened. See "Fast suites" in `packages/testing/README.md`). **Upgrading:** nothing changes unless a site opts in.
  Sites add `brianium/paratest` to require-dev, the `test` (paratest) and `test:serial` composer scripts, and call
  `SetupSnapshots::bootstrap()` from `tests/bootstrap.php`. Measured full suites, before → `composer test` (6 workers) /
  `composer test:serial`: the collection site ~33 min → 3:02 / 5:55 (MySQL; 950 tests), the app-like site 6:15 → 0:59 / 3:49,
  the member site 87 s → 16 s / 72 s (paratest `--functional`), the personal site 59 s → 4.6 s / 12.5 s; no test skipped.
  0.56.1: the JSON storage query cache is kept per data path (`json-{type}-{path hash}.json` / `.gen`), so tests with
  their own data path and parallel workers sharing `storage/cache/data` never read each other's records; `cache:clear` still clears them all.
- ~~**Performance step 1, quick wins (0.58):**~~ done in 0.58.0 (owner, 2026-11-07: "as fast as possible"; evidence
  `docs/superpowers/specs/2026-11-07-perf-profiling-report.md`).
  - **Fixes:**
    - Twig compiles each template once, into a versioned `storage/cache/twig`. It used to compile every template on
      every request, which was half the page time.
    - Minified CSS/JS is cached per entry, by content. The cache is capped and survives corrupt entries.
    - Composer's installed.json is read once per change, through `Preflow\Core\Composer\InstalledPackages`.
    - The rich-text sanitizer is built only when it is used.
    - 404 hits are written at most once a minute per address, and at most 30 new addresses a minute are recorded.
    - Responses send `Content-Length` (only when nothing else is buffered) and answer HTTP/1.0 correctly (`ab` works
      again).
    - Media records are memoised per request, and `MediaLibrary::prefetch($ids)` fetches a list in one query.
    - Each model JSON and `folio.php` are parsed once per request (`Preflow\Core\Config\ParsedJsonFiles`).
    - Test runs keep their JSON caches under their temp root (`DATA_CACHE_PATH`, `Testing\Fast\TestDataPaths`), and
      `cache:clear` removes orphaned cache files. 0.58.1: it also removes other data paths' `.gen` files and keeps only
      the app's own (core needs `preflow/data` 0.58.1 for `JsonFileDriver::pathKey()`).
  - **Measured on the language site** (Apache, OPcache, one request at a time), 0.57 → 0.58:

    | Page | 0.57 | 0.58 |
    |---|---|---|
    | home | 15.3 ms | 5.0 ms |
    | /classes | 17.6 ms | 8.1 ms |
    | 404 | 10.0 ms | 3.9 ms |
    | robots.txt | 3.7 ms | 2.2 ms |

    Home with 10 parallel requests went from ~400 to ~700 req/s.
  - **Upgrading:**
    - core, i18n, data, folio and testing require each other at 0.58 (conflicts set). Release them together.
    - Sites set `DATA_CACHE_PATH` (or use `TestDataPaths`) in their test scaffold, and run `preflow cache:clear` once
      locally.
    - A site that shares all of `storage` between releases should clear the caches after each deploy.
  - **Next:** ~~step 2 (lazy providers, a compiled boot manifest, a route cache)~~ done in 0.59; ~~step 3 (a page cache
    with rebuild on publish)~~ done in 0.60.
  - **Follow-ups:**
    - ~~FolioServiceProviderTest and LazyHtmlSanitizerTest boot with the repo root as base path, so a Folio run writes
      `storage/cache/composer` into the monorepo root. Give them a temp root.~~ done in 0.59 (`TempWorkingDirectory`)
    - ~~`MediaLibrary::prefetch()` runs COUNT + SELECT through the query builder's `get()`; it should be one query.~~
      done in 0.59 (one query per 200 ids)
    - Live, every site now answers in ~35 ms of server time (best of 6, minus ~15 ms network), down from 50–130 ms.
      The rest is the host's floor, which only the page cache (step 3) removes.
- **Short-call tokens out of the URL; Blade template functions as values (v0.88.0, released 2026-11-25):** two gaps found on live sites.
  - **Changed (htmx, core):** a short call that posts (`hd.post`, `hd.on`) sends its component token in the request body as `_component_token` (`ActionCall::TOKEN_FIELD`), not in the URL. A component without `#[State]` signs all its props into its token, so large props (an old form submission, a long text) made URLs that servers and proxies refuse. `hd.get` keeps the token in the URL (a GET has no body); a GET token over 2048 bytes fails in debug, naming `#[State]` or smaller props as the fix, and is logged once per component and request otherwise. The endpoint reads `token` from the URL first, then `_component_token` from the body (then a body `token`, as before); `_component_token` never reaches an action's params, while a site's own fields (a form token named `_token`, say) reach them as before. htmx passes an element's `hx-vals` to every request inside it, so the driver adds a small script, `HtmxAssets::requireBodyTokenScope()`, that drops a token a request only inherited from a call around it (a nested `hd.get`, a site's own `hx-get`, a boosted link). A call's own `hx-params` (under `htmx:`) keeps the token; `none` fails in debug and otherwise sends the token in the URL. The missing-token error says where the token is expected and that `hx-params` or a request hook may have removed it.
  - **Added (core):** `ActionCall::$token` and `ActionCall::$endpointUrl` (both or neither): a driver sends the token in the URL as `token` or in the body as `_component_token` (`ActionCall::TOKEN_FIELD`, beside the other shared fields `_state`, `_values` and `_region`). `ActionCall::$url` still holds the full URL with the token, so a driver written before 0.88 works unchanged. The driver contract suite checks that a call with its token apart does not lose it.
  - **Added (blade):** `$fn`, every template function as a value: `@php($form = $fn->form_begin([...]))`, then `{!! $form->begin() !!}`, as Twig's `{% set form = form_begin(...) %}`. A directive (`@name(...)`) whose function returns something that is not text (string, `Stringable`, scalar, null, `Htmlable`, backed enum) fails, naming the `$fn->name(...)` form; `@form_begin(...)` failed with PHP's "could not be converted to string" error before. Text prints on both paths: a safe function's directive prints an `Htmlable` through `toHtml()` and a backed enum as `{{ }}` does. `fn` is a reserved view variable.
  - **Changed (testing):** `WebApplication::componentAction()` sends the token in the body as `_component_token`, as the page does.
  - **Upgrading:**
    - Nothing to change in templates. A page cached before the upgrade (token in the URL) keeps working.
    - A test that reads a short call's token from an `hx-post` URL reads it from the call's `hx-vals` (`_component_token`) or uses `WebApplication::componentAction()`.
    - **Upgrade every `preflow/*` package together to `^0.88`** (one `composer update "preflow/*"`). The starters and the skeleton pin `^0.88`.
- **Small gaps in short calls and forms (v0.87.0, released 2026-11-24):** polish on the 0.86 short call, found while using it on real forms.
  - **Added (htmx, core):** `on: 'blur'` for the action option `on`: a field checks itself when it is left. `debounce` stays for typing only: with `debounce` and no `input` in `on` (e.g. `on: 'blur', debounce: 300`) the option fails in debug, and outside debug it is logged and left out; `on: ['input', 'blur'], debounce: 300` delays the typing only. `disable: 'self'` now covers a form's submit controls from the moment the call is triggered, so a form cannot be sent twice; only the controls it disabled itself are enabled again afterwards. `disable: '<region>'` really disables the region's fields, from the moment the request is sent (a request still has to read their values first). htmx does this with a small script, `HtmxAssets::requireDisabler()`, which reads the marker attribute `HtmxAssets::DISABLE_MARKER` (`data-preflow-disable`).
  - **Added (form):** form-builder fields take `call`, the result of a short call (`hd.post(...)`), so a field can check itself through a component action. A `call` that also writes an attribute the field's `attrs` writes is refused, naming the attribute.
  - **Added (components, htmx):** `hd.hasParent()` tells a template whether it was placed inside another component, without building the parent. New in components: `ComponentRenderer::hasParent()`, `placeInsideLazily()` and `parentRefOf()`, and the class `ParentRef` (class and optional key): a parent is built only when something asks for it. The htmx driver no longer builds a parent stand-in unless it is needed.
  - **Added (testing):** `componentAction(..., cookies: [...])` sends cookies with the action request, as params and as a Cookie header, for components that read them in `authorize()`.
  - **Fixed (htmx):** a short call on a component without state no longer makes htmx log a missing `hx-include` selector. `ActionCall::$carriesState` (core; default `true`) tells the driver whether the component has a state carrier on the page; without one the htmx driver writes `hx-include="unset"`. The driver contract suite checks both, and that `on: 'blur'` is mapped or refused.
  - **Upgrading:**
    - `HdExtension` (preflow/twig) is removed. Nothing in the framework used it; the `hd.*` helpers come from `HtmxExtensionProvider`. preflow/twig no longer suggests preflow/htmx.
    - `ComponentToken::encodeFor()`: the third parameter is widened to `Component|ParentRef|null`. Only a subclass that overrides it breaks.
    - Behaviour change: `disable: '<region>'` now really disables the region's fields. In 0.86 it did nothing on a `<div>`. A field inside the region it disables loses focus while the user types, so check existing uses.
    - **Upgrade every `preflow/*` package together to `^0.87`** (one `composer update "preflow/*"`): htmx needs core and components 0.87 (`ActionCall::$carriesState`, `on: 'blur'`, `ParentRef` and the lazy parent placement); core conflicts with htmx before 0.87 (htmx 0.86 can't map `on: 'blur'` and doesn't disable a form's submit controls); testing needs core 0.87 (the driver contract suite passes `carriesState`); form needs view 0.86 (`SafeHtml` for a field's `call`). A site that pins one of these packages to `^0.86` must raise the pin. The starters and the skeleton pin `^0.87`.
- **Components that keep their own state (v0.86.0, released 2026-11-24):** a component names its state once, in its class. Templates call an action by its name with only the values that change, in neutral options that name no client library. An action can render only the part of the component it changed.
  - **Why:** every action call passed the component's whole view by hand (a filter call sent all the other filters, so they survived), repeated the component's class, id and `| raw`, and passed `hx-` attributes straight in, which tied templates to htmx. The endpoint ran `resolveState()` before every action, so an action that loaded its own data loaded it twice.
  - **Added (components):** `#[State]` on a property: its value survives the component's own actions (the page carries it, signed; each action sends it back; the answer brings the new value). Allowed: scalars, null, arrays of those, backed enums, `StateValue` objects (`toState()` / `fromState()`), and nullable class types; anything else fails on the first render, naming class and property. New hooks for components with state: `mount()` (first render only, from props) and `prepare()` (before every render); the action runs on the restored state with nothing loaded before it; an event refresh restores the state and does not mount again. `resolveState()` is unchanged for components without state, and fails in debug on one with state. Nested components start from their props when their parent renders again. `StateStore` with `SignedStateStore` (default; 24 h like action tokens; bound to class and id; pages with state stay page-cacheable) and `SessionStateStore` (`#[StateIn(SessionStateStore::class)]`, for large state behind a login; refused on a page the page cache stores). `StateRefused` (403). `Component::hasState()`. `ComponentRenderer::enableState()`, `carryStateWith()`, `stateFor()`, `restore()`, `renderAfterAction()`, `regionsOf()`, `current()`, `parentOf()`.
  - **Added (htmx):** the short call `hd.post(action, values = {}, options = {})`, `hd.get(…)`, `hd.on(event, options = {})` and `hd.region(name)` inside a component's template: no class, no id, no `| raw`. Neutral options `target`, `swap`, `on`, `debounce` (typing only), `latest`, `include`, `disable`, `scroll`, `component: 'parent'`; an unknown option fails in debug. The escape hatch: a driver's own attributes under its name (`htmx: {…}`), ignored by other drivers. One action at a time per component (quick clicks both apply; `latest` replaces). The busy class `preflow-busy` on the element whose request runs, beside htmx's `htmx-request`. `ComponentToken::encodeFor()` (one token per action and component; key only for a component with state). The values a call sends are unsigned input (`$params`); only state is trusted.
  - **Added (view, twig, blade):** regions, `{% region 'results' {class: …} %}…{% endregion %}` and `@region('results', [...]) … @endregion`: a call with `target: 'results'` renders only that region on the server and swaps its contents. `RendersRegions` (optional engine interface) and `Regions`. `SafeHtml`, printed unescaped by every engine.
  - **Added (core):** the optional driver interface `Hypermedia\DrivesComponentActions` (`callAttributes()`, `listenAttributes()`, `stateCarrier()`, `stateUpdate()`, `BUSY_CLASS`) with `ActionCall` and `ActionOptions`; the neutral request fields `_state[<componentId>]`, `_values` and `_region`. `HypermediaDriver` is unchanged; a driver without the interface serves the old calls.
  - **Added (testing):** `WebApplication::componentAction($app, $pageHtml, $class, $action, $params, key:, localePrefix:, props:)` runs an action the way the page does (state from the page), and `WebApplication::stateFrom($html, $componentId)`. `StateStoreContract`; `HypermediaDriverContract` checks `DrivesComponentActions`.
  - **Changed:** the starters' `JobSearch` keeps its filters as state (a `JobFilters` `StateValue`), has separate `search` and `page` actions, renders only its `results` region, and no longer searches twice per action; `Pager` calls the search with `component: 'parent'`. Their templates have no `hx-` attribute. The starters and the skeleton pin `^0.86`.
  - **Fixed:** the pager's "Page :page of :pages" showed "Page 1 of 1s": a translation placeholder that is the start of a longer one (`:page` in `:pages`) is replaced after the longer one.
  - **Docs:** components (state, lifecycle, what may be state, expiry, the session store), htmx (the short call, regions, options, escape hatch, busy class, quick clicks, the driver interface), testing (`componentAction`).
  - **Upgrading:** nothing required. The old call `hd.post('x', componentClass, componentId, props, swap, extra)` keeps working unchanged and is not deprecated; components without `#[State]` behave as in 0.85. Upgrade every `preflow/*` package together.
    - **Upgrade every `preflow/*` package together to `^0.86`** (one `composer update "preflow/*"`): the new APIs cross packages, so 0.86 raises their constraints and Composer refuses a mix. htmx needs core, components and view 0.86 and conflicts with twig and blade before 0.86 (the short call returns `SafeHtml`, which only 0.86 engines print unescaped); components, twig and blade need view 0.86 (`Regions`, `RendersRegions`, `SafeHtml`); core conflicts with components before 0.86 (it wires `#[State]` at boot); testing needs core, view, components and htmx 0.86. A site that pins one of these packages to `^0.85` must raise the pin. The starters and the skeleton pin `^0.86`.
    - **Blade sites:** compiled views must be compiled once more so `SafeHtml` prints unescaped: clear the view cache after the upgrade (`php preflow cache:clear blade`).
    - **The busy class:** CSS on `htmx-request` keeps working. For CSS that names no client library, use `.preflow-busy` (on the element of a short call while its request runs).
    - **Moving a component to state (optional):**
      1. Mark the properties that carry over with `#[State]` (a group of fields can become one `StateValue`).
      2. Split `resolveState()`: setting the state from props goes into `mount()`, loading what derives from it into `prepare()`.
      3. Let each action read its input from `$params` and check it; give each intent its own action (`page` beside `search`).
      4. In the template, replace long calls with short ones (`hd.post('search', {}, {target: 'results'})`), `hx-` options with neutral ones (or the `htmx: {…}` key), and flags like `resultsOnly` with a `{% region %}`.
      5. A child that called its parent through `parentComponentClass` / `parentComponentId` props uses `component: 'parent'`.
      6. In tests, call actions with `WebApplication::componentAction()` instead of reading `hx-post` from the HTML.
- **Empty start and one-command Folio (v0.85.0, released 2026-11-24):** a new app starts from `preflow/skeleton` and gets an admin with `php preflow folio:install`. Pages that miss the page cache say why. Static analysis runs on PHP 8.4.
  - **Added:** `preflow/skeleton` is the empty starter: one home page in English and German, SQLite, no models, no accounts and no demo data (the old blog demo is gone); it is no longer abandoned. `composer create-project preflow/skeleton mysite` prints no warning.
  - **Added:** `php preflow folio:install [--path=] [--email=] [--name=] [--no-password] [--dry-run]` wires Folio into an app: `config/folio.php`, `auth.php`, `media.php`, `providers.php` and `roles.php`, then `migrate`, `rbac:install`, `folio:extensions:enable rbac`, `folio:extensions:apply`, and the first admin. It adds lines and never rewrites what a developer wrote: a file it cannot edit safely stays byte for byte as it was and the command prints the lines to add (exit 1). Existing values win (a `config/folio.php` that says `/folio` keeps it even with `--path=/admin`). A second run, or one after a crash, only finishes what is missing. A password is only typed (twice, not shown) or piped, never an argument; without a terminal and without `--email` it stops and names `--email` and `--no-password`. With `--email` naming a new address on a site that already has staff, another admin is created.
  - **Added:** `php preflow pages:why <path> [--locale=]` says whether the page cache would store a page and, if not, why: the part of the page that differs per visitor (naming the component or element), or the refusal (cookie, session, signed-in user, status, `Cache-Control`, `Vary`), each with the usual fix. Exit 0 would be cached, 1 would not, 2 usage or no such page. It works with `app.debug` on. The cache's log line now names the differing part too. `WebApplication::assertPageCacheable($app, $path, $locale)` (preflow/testing) asserts it in a test. New in core: `PageCache\Cacheability`.
  - **Added:** `Preflow\DevTools\Config\ConfigFileEditor` (`create`, `addToList`, `addKey`, `addToNestedList`, `read`; results are `ConfigEdit`): edits a config file by one recognised insertion, checks the result by evaluating the file again, and refuses (with a snippet) what it cannot read safely.
  - **Added:** PHPStan level 8 pinned to PHP 8.4 (`composer analyse`, a baseline of today's findings, a rule that flags functions only a polyfill defines such as `array_first()`), a CI leg on 8.4, and a test that every `packages/*/src` is analysed.
  - **Changed:** Folio's commands register from its `composer.json`; `config/commands.php` needs no `FolioCommands::all()` line any more (it keeps working). Starters pin `^0.85`.
  - **Fixed:** a class in `config/providers.php` that does not exist was skipped silently. With `app.debug` on, boot throws an error naming the class and `config/providers.php`; in production it logs one error line and boots on.
  - **Fixed:** the failure guard (a crash while booting answers 500 with an error page) is decided by `runningInConsole()`, not by the PHP SAPI: `Application::create($basePath, ?bool $console = null)`.
  - **Fixed:** JSON storage uses one total order for `orderBy()`, range conditions and `min`/`max`: empty first, numbers by value, then byte order (equal numbers in another spelling, `1` and `1.0`, keep a fixed place by their bytes). Range bounds compare in this order, so numbers sort before text: give a date bound as a date (`'2026-01-01'`), not a bare year number. Ids of mixed kinds (`2`, `10`, `1a`) no longer skip records between pages, which made the change-date backfill of `migrate` miss records.
  - **Changed:** `MaintenanceReport::report()` documents what it returns.
  - **Upgrading:** nothing required. Upgrade every `preflow/*` package together to `^0.85`: devtools, testing and folio need core 0.85 (and folio needs devtools 0.85). Optional: delete the `...\Preflow\Folio\Console\FolioCommands::all()` line from `config/commands.php` (return `[]` if it was the only entry). Existing sites do not run `folio:install`.
- **PHP 8.4 patch (v0.84.3, released 2026-11-23):** Preflow supports PHP 8.4, and now tests it.
  - **Fixed:** pages with a table had no `.md` view on PHP 8.4. `MarkdownConverter` read table rows through `Dom\Element::$children`, which exists only in PHP 8.5, so every table threw. It now reads rows in a way that works on both.
  - **Fixed:** three tests used 8.5-only syntax or behaviour (the pipe operator, `eval` of a file that starts with `declare(strict_types=1)`, and a .docx fixture that 8.4 reads as a zip). They now run on 8.4 and 8.5.
  - **Why nothing caught it:** every suite ran on PHP 8.5 only.
  - **Added:** a tools test that every PHP file, including extensionless PHP scripts, parses on the running PHP. CI runs the tools tests on 8.4 and 8.5.
  - **Added:** the root `composer.json` sets `config.platform.php` to `8.4.1` (the locked `symfony/http-client` needs 8.4.1), so the lock never takes a dependency that needs 8.5. Releases now run the full suites on 8.4 too.
  - **Upgrading:** nothing to do. Sites just update.
- **First-hour patch (v0.84.2, released 2026-11-23):** follow-ups from a first hour on a new site.
  - **Fixed:** a site's own forms validate in the visitor's language. Validation messages follow the render context's locale on public pages too (before, only on the admin surface). `form_begin(rules: ...)` without a model now infers the input type from the rules: `type="email"`, `url`, `number`, or a `<select>` for `in:` rules. A `numeric` rule (without `integer`) adds `step="any"` so decimals are not blocked by the browser.
  - **Fixed:** a crash while booting (a provider that fails while booting, a weak key, a broken config file) or an Error that escapes `run()` answers 500 with an error page. Before, PHP printed "Fatal error: Uncaught ..." with status 200. `Application::create()` installs the guard on web SAPIs.
  - **Fixed:** a clean starter install. `User::$passwordHash` is `#[Field(exact: true)]` in starter and starter-folio, so migrate no longer warns on a new app. `php preflow serve` checks its port first (taken, or outside 1-65535).
  - **Fixed:** `translations:check` knows dynamic key prefixes: `t('group.prefix_' ~ value)` (Twig) and `t('group.prefix_' . $value)` (Blade) is no longer listed as missing when a text starts with the prefix.
  - **Fixed:** `folio_list()` matches the detail page's visibility. A record the page's language cannot open is no longer listed.
  - **Docs:** the data README says how to publish records saved by code (seeding). The Folio README explains `_locales` ("Visible in") and has an "Upgrading to 0.84.2" paragraph.
  - **Upgrading:**
    - Validation messages inside any render now follow the render context's locale, on public pages too (before: only on the admin surface). This covers a site's own forms (`ValidatorFactory::make()`) and also model saves through `DataManager` on public pages (members' and profile areas, Folio-typed records saved from public components): their error messages are translated.
    - On a site whose default or only locale is not English, every public page's validation messages change language (a translation must exist in the package's or the site's `lang/{locale}/validation.php`; otherwise the site's fallback language is used, then English). Outside every render (scripts, jobs) messages stay English.
    - A site that offers a language the package does not ship (the packages ship en, de and fr) adds `lang/{locale}/validation.php` for it; otherwise messages fall back to the site's fallback language, then English (with `fallback => 'de'`, a visitor in a language without a file now reads German where 0.84.1 showed English).
    - Site tests that match English message strings on those pages need updating (assert the translated text, or the error key).
    - To keep English (or any other translator) for forms and for the data layer, bind your own factory before boot, because `DataManager` takes the factory at boot. Since 0.84.2 `Application` keeps a `ValidatorFactory` already bound when `boot()` runs. In `public/index.php` (and the console entry point):
      ```php
      $app = Application::create(__DIR__ . '/..');
      $container = $app->container();
      $container->instance(
          \Preflow\Validation\ValidatorFactory::class,
          new \Preflow\Validation\ValidatorFactory(
              new \Preflow\Validation\RuleFactory(),
              // English everywhere except the admin, which keeps its interface language (the 0.84.1 behaviour)
              contextTranslator: static fn (): ?\Preflow\I18n\Translator => \Preflow\Core\Context\Contexts::peek()?->surface->admin === true && $container->bound(\Preflow\I18n\Translator::class)
                  ? $container->get(\Preflow\I18n\Translator::class)
                  : null,
          ),
      );
      $app->boot();
      ```
      Binding it in a service provider is too late for `DataManager` (providers run after the data layer is built). A factory you build yourself has no `app/Rules/` rules unless you `discover()` them on the `RuleFactory`.
    - `form_begin(rules: ...)` without a model renders `type="email"`, `url`, `number`, or a `<select>` for `in:` rules, where it rendered `type="text"`. Pass `type:` on the field to override.
    - A crash while booting or an Error that escapes `run()` now answers 500 with an error page: the dev error page when `app.debug` is on, a plain page without a trace when off. Before, PHP printed "Fatal error: Uncaught ..." with the trace, status 200. No change to `public/index.php` is needed: `Application::create()` installs the guard on web SAPIs (not the console).
    - The guard installs an exception handler. An exception handler set before `Application::create()` is chained: it is called after the answer, its output is dropped. One set after `create()` replaces the guard's.
    - Uncaught exceptions are now logged by Preflow, not PHP: the whole exception with trace ("Uncaught <class>: ...") through the bound PSR-3 logger (level error) or else `error_log`. The old "PHP Fatal error: Uncaught" lines no longer appear.
    - Not covered: code running before `Application::create()`, and apps built with the array form `create([...])` (tests).
    - Output printed before the failure keeps its 200 status (headers are gone); the error page is appended, not silent, unless the response already sent is not HTML (JSON, a download): then the failure is only logged. When `config/app.php` itself fails to load, `APP_DEBUG` from the environment decides between the dev page and the plain one. With `display_errors=1` an engine fatal (memory exhausted, ...) stays 200 as PHP shows it itself; with `display_errors` off it answers 500 with a page.
    - A site's own `try/catch (Throwable)` around `boot()` / `run()` keeps working and is now redundant but harmless.
    - Apps from older starters: mark `App\Models\User::$passwordHash` with `#[Field(exact: true)]` to silence the model-exactness warning on migrate (new installs have it).
    - `php preflow serve` now exits 1 with a one-line message when the port is taken or is not a number from 1 to 65535, before printing "started".
    - `translations:check` no longer lists dynamic key prefixes (`t('group.prefix_' ~ value)`, in Blade `.`) as missing when a text starts with the prefix.
    - `folio_list()` no longer lists a record the page's language cannot open (no `_locales` entry for it, on a type with translatable fields). Before, it was listed while its page was a 404. Fix: set `_locales` on records saved by code, and publish them for a publishing type (`php preflow publish <type>`). `folio_find()` is unchanged. A type without translatable fields lists in every language.
- **Stable ground patch (v0.84.1, released 2026-11-23):** follow-ups from 0.84.0.
  - **Fixed:** migrate keeps rbac's role table current wherever rbac is installed, whoever defined the type. The new step `rbac.roles` (the name migrate prints) adds missing columns, the change-date column and its index `idx_rbac_role__updated_at`, and on MySQL fixes the text collations. The roles are dated in the same run. The step never creates the table: the table being there is what tells rbac it is installed. The migrate warning's wording is corrected.
  - **Fixed:** `members:prune`: a run that finds another one holding the lock prints one line naming it and exits 0 (before: silent). A lock file it cannot open now fails with a message and exit 1 (before: a silent exit 0, so nothing was ever pruned).
  - **Fixed:** the transient tables (auth's tokens and remember-me rows, core's throttle hits) declare `changeDates: false`. They never got the column, so each first save asked the database for it, and on JSON storage the rows carried a change date nothing reads.
  - **Docs:** i18n declares every config key it reads (`packages/i18n/config/i18n.php`), so the reference lists them, and says what `cookie` and `redirect_bare` do with the language switch `/--locale/{code}`. A guard test keeps that true. The members README states the prune exit codes.
  - **Upgrading:**
    - Run migrate after upgrading (it adds the column to role tables that lack it).
    - auth now conflicts with `preflow/data` below 0.84.0 (core already does); upgrade the packages together.
    - Check that the cron user can open `storage/locks/members-prune.lock`. A lock file made by another user (a manual run as the deploy user) used to make the nightly prune do nothing, silently. It now exits 1.
    - A cron that mails output now mails the skip line when two prune runs overlap.
- **Stable ground (v0.84.0, released 2026-11-23):** the cleanup release after the render context. Every typed record keeps a change date that Folio lists and sorts by. HTML answers say what they vary on. Folio copes with a site that lacks AI, media storage or a translator. Folio's lists and screens get a polish pass. `search()` without fields is refused on every driver. MySQL gets maintenance checks.
  - **data:**
    - **New: every typed record stores its change date** in the system key `_updated_at`. The value is UTC ISO 8601 text, last among the system keys. It moves on a save that changes the record. A save that changes nothing leaves it, so an unchanged JSON file keeps its bytes. It is never content: states, `changes()`, restore and duplicates ignore it. A published copy carries its own date. Counters, `updateIf()` and named-field writes leave it. A type's own `updatedAt` / `updated_at` stays the site's content and is never touched. Plain models (no type) keep the opt-in `#[Timestamps]`. A type opts out with `changeDates: false` on its `TypeDefinition`. The activity log's tables do.
    - **New API:** `ChangeDates`, `DataManager::keepingChangeDates()`, `changeDateOf()` and `forgetColumns()` (`hasColumn()` now remembers a missing column too, until the table sync or migrate forgets it), the optional `WriteTimes` capability (JSON implements it with file times), `ChangeDateSource` and the `ChangeDateSources` registry (core binds it), `ChangeDateBackfill`, `MaintenanceReport`. A driver needs nothing new: a date is written only where the driver implements `DescribesColumns` and says the table has the column. The storage contract states and tests this.
    - **Migrate:** `preflow migrate` adds `_updated_at` to every record table and an index `idx_{table}__updated_at` to each typed one (an untyped table that holds copies, such as revisions, gets the column without an index), then the `data.change-dates` step dates every existing record, writing only empty dates, in chunks of 500, idempotent. Source order: the activity log's newest event, the type's own updated time, registered `ChangeDateSource`s (publishing registers its published time), the storage's file time, the creation time, the time of the migrate.
    - **Search:** `Query::search()` and `QueryBuilder::search()` with no fields, or on a type with no searchable fields, throw `InvalidArgumentException` on every driver instead of searching all fields (JSON) or matching everything (SQL). The storage contract pins it. Name the fields.
    - **MySQL:** the driver implements `Maintenance` (`CHECK TABLE`, `OPTIMIZE TABLE`), so `preflow db:check` and `db:vacuum` work. The new optional `MaintenanceReport` (`report(): list<string>`) adds text columns per collation and the server's match collation; `db:check` prints it without failing, and lists a table its engine cannot check (`CHECK TABLE`'s note, such as MEMORY) as not applicable, never as passed. `Schema::convertColumn()` keeps the column's `COMMENT` and `ON UPDATE`.
  - **core:**
    - **Changed:** every HTML answer says `Vary` for the hypermedia client's request headers (htmx: `HX-Request`, `HX-Target`, `HX-History-Restore-Request`), added by `SecurityHeadersMiddleware` through `HypermediaProtocols::varying()`. Names already present are kept, `Vary: *`, non-HTML answers and setups without a client are left alone. The page cache already keys on these names, so cached pages still store.
    - **Changed:** `RenderContext::memoKey()` includes a frozen time, so memos of `at()` contexts are keyed per date.
    - **New:** `Activity\LastChanges` (optional `ActivityLog` capability, implemented by `StoredActivityLog`).
    - **New:** `Http\Vary::add($response, ...$names)`, the one `Vary` merge: names already there stay, each name is listed once (case-insensitive), several lines become one, `Vary: *` is left alone. `varying()`, the preview token's and CORS's Vary use it, and so do i18n's `LocaleMiddleware` (which no longer repeats `Cookie` or `Accept-Language` a page already names) and view's Geo middleware; both need core 0.84.
  - **publishing:**
    - **Changed:** `Publisher::META`, the system keys that never count as content, includes `_updated_at`. A working copy and its published snapshot each keep their own change date.
    - **New:** `PublishedTimeSource`, registered as a `ChangeDateSource`: the backfill dates a record by its published time.
  - **folio:**
    - **BREAKING:** `AdminLocaleMiddleware::asFrontend()` loses its unused `Translator` parameter. Call `asFrontend($request, $render)`. The translator has followed the render context since 0.81. No caller exists in the sites or starters.
    - **Changed:** staff screens and their parts send `Cache-Control: no-store` (unless the response sets its own).
    - **New:** every list ends in a relative "Changed" column that reads `_updated_at`, sorts (`?sort=-_updated_at`) and shows a dash for an undated record; hide it per type with `"list": {"changed": false}`. The default columns are unchanged. A type's own updated field is an ordinary field: when it is among the default columns, or in `list.columns`, it stays a column next to "Changed". A site test that counts a list's columns sees one more. `ListQuery::fromRequest()` / `fromPosted()` take a trailing `bool $changeDates`; `folio_relative()` Twig function; `#[TitleBadge]` extension hook.
    - **New:** the roles screen has a type filter beside "Only new" and shows a population badge and "N new permissions" in a role's title. Chips have a forced-colors fallback. Screen subtitles are one line (en, de, fr); the detail they dropped is help text. New lang keys in en, de and fr.
    - **Fixed:** a brand-new install no longer announces Redirects (`redirects.manage`) as a new permission; only a site that already holds roles (of any population, through the new `RoleStore::hasRoles()`) when Folio first reads the known-permissions list does. Folio conflicts with preflow/rbac before 0.84.0. `KnownPermissions` takes an optional third argument `?Closure $hadRoleStore`.
    - **Fixed:** without the media storage driver (the default `json`) the assets screen answers 200 with the way to configure it (`config/data.php` drivers, `config/media.php` storage) instead of a 500; htmx actions, JSON actions and plain form posts answer 503. The admin search, dashboard count, asset field, content export and import (they leave the asset table out), the activity screen (no asset rows) and the AI alt text route use the same `MediaAvailability` check. Without a usable library the asset field keeps submitted asset ids and refuses uploads with a message, so a save never drops them.
    - **Changed:** the importer compares records without their change dates, so a record that differs only in its date is no change and keeps its local date. A record it adds or changes keeps the date its archive carries. An archive from before 0.84 carries none: those records are dated at the import.
    - **Fixed:** Settings → Translations hides Folio's AI texts when preflow/ai is missing (drafts, Settings → AI) and its translation texts when no machine translator is available.
  - **rbac:** **New:** `RoleStore::hasRoles()`: whether any role is stored, of any population.
  - **testing, notify, tools:** `StorageDriverContract` gains the hook `accentFreeMatchingMissing(): ?string` (default null): a driver without accent-free matching returns the reason, and only the accent tests skip. Folio's tests remove their temp roots and session files. The `@since` guard treats `0.80` and `0.80.0` alike. notify's dev dependencies gain `preflow/routing` so its boot tests find a router.
  - **Upgrading:**
    - **Upgrade every `preflow/*` package together, to `^0.84`.** The packages that name 0.84 classes need each other's 0.84, and the constraints say so: core conflicts with data before 0.84.0 (`ChangeDateSources`); data requires core `^0.84.0` (`LastChanges`); devtools requires data `^0.84.0` (`MaintenanceReport`); folio requires data `^0.84.0` (`ChangeDates`); publishing requires data `^0.84.0` (`ChangeDateSource`); testing requires core and data `^0.84.0` (the contract hook, `ChangeDates`). **i18n and view need core 0.84** (`Preflow\Core\Http\Vary`): they require core `^0.84.0` and conflict with core before 0.84.0. **folio conflicts with rbac before 0.84.0** (it uses `RoleStore::hasRoles()`). The starters go to `^0.84`.
    - **A long-running worker started before migrate keeps "no change-date column" until it restarts.** It remembers that a table lacked the column, so it writes no date and the Changed column stays empty for its saves. Restart queue workers and any other long-lived process after migrate. Web and cron processes start fresh and are unaffected.
    - **Run `php preflow migrate` once after the upgrade.** Until then SQL saves work as before (no date written) and the Changed column is not sortable. The first migrate adds `_updated_at` (and its index) to every record table and backfills every record in the order above. JSON content files gain `"_updated_at"` on migrate (or on their next save that changes them), a one-time diff in git-tracked content.
    - **The backfill may rewrite a legacy JSON row once, in canonical form** (types normalised, `''` becomes `null`), as its next save would.
    - **Git-deployed JSON sites:** file times are the checkout time, so a record with no activity-log event, own updated time, published time or creation time is dated to the last deploy. Run migrate on a server that kept the original file times, or accept that date as a floor. The dates are approximate.
    - **Migrate exits 1 when a table is refused** (`refused: {table}: no change dates given (...); run migrate again`), for example when the activity log cannot be read. The other tables carry on and written chunks stay. The old release stays live; fix the named cause and run migrate again. A table made by an app migration without the column is named in a `warning:` line.
    - **While the old release serves backfilled data, its editor shows published records as "Changed".** This happens during migrate, after a refused migrate and after a rollback. 0.83 counts `_updated_at` as content. No data is harmed, and lists are not affected. It ends when 0.84 is live. Finish the upgrade rather than rolling back, and do not publish from the old release meanwhile.
    - **A site's own `updatedAt` / `updated_at` field is untouched** and keeps its format and meaning. Folio's Changed column shows `_updated_at`. The own field stays a column where it was one (among the default columns or in `list.columns`), next to "Changed". To keep a hand-set `_updated_at` on an import or a copy, wrap the write in `keepingChangeDates()`.
    - **Every HTML answer carries `Vary: HX-Request, HX-Target, HX-History-Restore-Request`** (htmx), and Folio staff screens send `no-store`. A proxy or CDN in front of a site now caches the full page and the fragment apart.
    - **BREAKING:** call `AdminLocaleMiddleware::asFrontend($request, $render)`.
    - **`search()` with no fields throws on every driver.** Name the fields; a custom driver's contract suite also gains `accentFreeMatchingMissing()`.
    - **Starters and sites on MySQL** can now run `preflow db:check` and `db:vacuum`.
    - **Frozen-time memos:** code that relied on one memo across `at()` contexts of different dates gets one per date.
- **Render context shims removed (v0.83.0, released 2026-11-22):** everything deprecated "for removal in 0.83" is gone, together with the constructor parameters nothing used any more. New next to it: View as works for any address of the site (Folio), Folio's importer obeys the write policy, machine translation is a swappable capability with its own neutral types, HSTS can leave out subdomains, and the roles editor keeps stored grants of disabled permissions.
  - **core:**
    - **Removed:** `CookieQueue::isolated()`, `InternalRender::mark()` and `InternalRender::ATTRIBUTE` (`handle()` no longer turns a marked request into an Audit render), `PageCache::isolate()`/`isolated()`.
    - **Changed:** `security.hsts` accepts `['enabled' => bool, 'subdomains' => bool]` besides a bool; both keys default to true, and `subdomains => false` sends `max-age=31536000` without `includeSubDomains`. The default output is unchanged (the skeleton's and starters' `config/security.php` document the form).
    - **Changed:** with core installed, `NotifyOutbox::discarding()` (the `DeferredQueue` method behind `Notifier::discardingQueued()`) throws a `LogicException` inside any render frame, console commands included. Outside every render, and for notify on its own, `discardingQueued()` stays.
    - Core conflicts with `preflow/i18n` before 0.83.0.
  - **i18n:**
    - **Removed:** `Translator::setLocale()` and `useOverrides()`, `LocaleMiddleware::urlPrefix()`, `LocaleMiddleware`'s first constructor parameter `$translator`. Outside every render the translator now uses its constructor's locale and has overrides off.
    - **New:** `Machine\TranslatorAvailability` (`available(): bool`, "is there a translator to offer") with the default `ConfiguredTranslatorAvailability`, bound by `MachineServiceProvider` (preflow/ai installed, or `translation.translator` configured). `Machine\TranslationTuning` (`instructions`, `with()`, `isEmpty()`; the constructor trims and drops empty instructions). `Machine\TranslatorUnavailable`, which a translator throws to stop a whole run.
    - **Changed (clean break):** `MachineTranslator::translate()` takes `?TranslationTuning $tuning` instead of `?Preflow\Ai\Tuning`; read `$tuning?->instructions` (a `list<string>`). `AiTranslator` maps it to the AI package's tuning, so preflow/ai itself is unchanged.
    - i18n requires and conflicts with core `^0.83.0` / `<0.83.0` and conflicts with `preflow/testing` before 0.83.0.
  - **auth, validation, publishing:**
    - **Removed:** `AuthManager::setUser()` / `addUser()`; `ValidatorFactory::useTranslator()` and the internal `FrameTranslators` (the factory's `$translator` is now readonly); `LiveContent::showWorkingCopies()` / `isolate()`. Outside every render `LiveContent` always reads published snapshots.
    - **New:** `PermissionRegistry::disabled()` (`name => reason`): every disabled permission with its reason, whether or not a package declared it.
    - auth and publishing conflict with `preflow/testing` before 0.83.0.
  - **view, blocks, media, members, twig:**
    - **Removed:** `Geo\RenderScopes` (the whole class; core no longer binds or runs it); `LiveExtension::live()`; members' `PageLocale` and `MembersView`'s `$pageLocale` and `$translator` parameters; `BlockLocale::get()` and `DefaultBlockLocale::get()`; `LocaleProvider::current()` and `ConfigLocaleProvider::current()`.
    - **Merged:** `ContextBlockLocale` into `BlockLocale` and `ContextLocaleProvider` into `LocaleProvider`; `localeOf(RenderContext $ctx)` is now the required method.
    - **Removed constructor parameters:** `ConfigLocaleProvider`'s first (the translator-locale closure; use `new ConfigLocaleProvider($appLocale)`), `DefaultBlockLocale`'s second.
    - **Changed:** outside a render `ImageTag` asks `LocaleProvider::default()` for the alt text's language. A script's `Translator` locale no longer steers it.
    - view requires and conflicts with core `^0.83.0` / `<0.83.0`; blocks and media conflict with `preflow/folio` before 0.83.0.
  - **folio:**
    - **New: view any address as a role.** Settings → View as (`{admin}/preview/address`) previews any page of the site, also an app's own route that is no record (such as `/account`), as a member or staff role or as a visitor. It uses the record preview's frame, preview bar and preview token, and reads the working copies. Two entry points: Settings → View as, and an address field in the record editor's preview bar (it opens the screen in a tab of its own, with the bar's current role). Only addresses of the site's own pages are accepted: no other host, scheme, `//`, backslash (also encoded), query or fragment, and nothing under the admin or another non-public prefix. The language comes from the path's prefix.
    - **New permission `site.view_as_address`** ("View any address as a role", group Site), declared by Folio on top of `site.view_as`. Both are checked by the screen and again at every follow-up request under the token. Admins hold it through `*`; editors without it keep the record preview only. Settings → View as and the bar's address field show only to editors who hold both.
    - **New:** `AdminNav::add()` takes a list of permissions as `$permission` (all required). `ViewAs::addresses()`, `ViewAs::groups()` and `ViewAs::ADDRESS_PERMISSION`. `PagePreviewController`'s optional constructor parameter `?NonPublicPaths $nonPublic`.
    - **Fixed:** the importer refuses the whole import, with nothing half-applied, when the write policy refuses or skips a table (records) or a file root (media, uploads): `TransferException` (code `RECORDS_UNCHANGED`) naming the table or root, before any write.
    - **Fixed:** saving a role no longer drops the stored grants of permissions that are disabled (declared and then disabled, or no longer declared by a disabled package). The editor lists them as unavailable with the registry's reason and posts them again, so the grant is back when the permission is enabled again.
    - **Changed:** Folio asks `TranslatorAvailability` (not `MachineServiceProvider::hasTranslator()`, which is removed) whether to offer AI translation. The reason the roles editor gives for `ai.translate` reads "no machine translator (preflow/ai or config/ai.php translation.translator)".
    - **Removed:** `FolioText::use()`, `InterfaceLocales::setCurrent()`, `ContentLocale::set()` (`get()` stays); `AdminLocaleMiddleware`'s `$translator`, `$engine` and `$validators`; `FrontendController`'s `$contentLocale` and `$live`; `PreviewController`'s `$contentLocale` and `$translator`.
    - folio requires and conflicts with auth, blocks, media and i18n `^0.83.0` / `<0.83.0` (auth for `PermissionRegistry::disabled()`). New lang keys in en, de and fr.
  - **testing:** `RenderIsolationContract` no longer calls the removed setters (its poison narrows the outer frame). Site runners that override `poisonApp()` or the factory with a removed call must move to the replacements below. `tools/tests/RenderContextShimsTest.php` is gone.
  - **Upgrading:**
    - **Upgrade every `preflow/*` package together, to `^0.83`.** The removals break mixed versions, so each side conflicts with the other's older versions: core with i18n before 0.83.0; i18n and view require core `^0.83.0`; folio requires auth, blocks, media and i18n `^0.83.0`; blocks, i18n and media conflict with folio before 0.83.0; auth, i18n and publishing conflict with testing before 0.83.0. The starters are on `^0.83`.
    - **Clear compiled Twig templates when the upgrade keeps the same `vendor/composer/installed.json`:** `php preflow cache:clear twig`. Templates compiled before 0.82 call `LiveExtension::live()`, which is removed. `preflow optimize` does not touch them (it only builds the boot manifest). Core's own Twig engine keeps compiled templates in `storage/cache/twig/{key}`, and the key is a hash of `installed.json`'s modification time and size, so a deploy that runs `composer install` or `update` starts a fresh folder. A deploy that copies `vendor` with its timestamps kept, or a Twig engine bound with a cache path of its own, keeps the old ones, and Twig's `auto_reload` does not recompile an unchanged source.
    - **Removed, with their replacements** (no shim; code that still calls them fails):
      - `Translator::setLocale($l)`: `Contexts::narrow(fn ($c) => $c->in($l))`; on the admin surface `$c->admin($l, $c->surface->overrides)`; for a stretch `Contexts::within(Contexts::current()->in($l), fn () => …)`. Outside every render pass the locale: `get($key, $params, 'de')`.
      - `Translator::useOverrides($on)`: `Contexts::narrow(fn ($c) => $c->admin($uiLocale, $on))`.
      - `LocaleMiddleware::urlPrefix()`: `Contexts::current()->request->localePrefix` (`Contexts::peek()?->request?->localePrefix ?? ''` where there may be no render). `LocaleMiddleware`'s first argument `$translator` is dropped: positional callers pass `$availableLocales` first.
      - `AuthManager::setUser($u, $guard)` / `addUser()`: `Contexts::narrow(fn ($c) => $c->as(new Viewer([$guard => $u], $guard)))` or `AuthManager::viewerOf()`; several guards go in one `Viewer` with every identity and the primary named.
      - `LiveContent::showWorkingCopies()`: `Contexts::narrow(fn ($c) => $c->workingCopies())`. `LiveContent::isolate()`: a render under `RenderContext::published()` plus `memoKey()`.
      - `CookieQueue::isolated()`: `Application::render()`, which starts a render with its own cookies.
      - `InternalRender::mark()` and `ATTRIBUTE`: `Application::render()` with `RenderContext::internal()`.
      - `PageCache::isolate()` / `isolated()` and `Geo\RenderScopes::add()` / `run()`: `RenderContext::memoKey()`, a context facet, or a frame slot (`Contexts::frame()->slot()`). `PageCache::refuseWhen()` stays.
      - `Notifier::discardingQueued()` with core, inside a render: `Application::render()` or `Contexts::run()` with `RenderContext::discarding()`.
      - `ValidatorFactory::useTranslator()`: the admin surface (`Contexts::narrow(fn ($c) => $c->admin($uiLocale))`), or the translator in the constructor.
      - `FolioText::use()`: `Contexts::narrow(fn ($c) => $c->admin($uiLocale))`. `InterfaceLocales::setCurrent()`: `->admin($locale)`. `ContentLocale::set()`: `->in($locale)`. Each did nothing outside every render before.
      - Members' `PageLocale` and `MembersView`'s two parameters: read `Contexts::current()->locale`; drop the arguments.
      - `BlockLocale::get()` and `DefaultBlockLocale::get()`; media's `LocaleProvider::current()` and `ConfigLocaleProvider::current()`: implement `localeOf(RenderContext $ctx)` (and `locales()` / `default()`). `implements ContextBlockLocale` becomes `implements BlockLocale`, `implements ContextLocaleProvider` becomes `implements LocaleProvider`; the two sub-interfaces are gone.
      - `ConfigLocaleProvider`'s first constructor argument and `DefaultBlockLocale`'s second: drop them.
      - Folio's `AdminLocaleMiddleware` now takes `(InterfaceLocales, UserPreferences, ?Closure $onStart, ?NotFound $notFound, ?string $contentDefault)`; `FrontendController` lost `$contentLocale` and `$live` (`$dm` is now the 4th argument); `PreviewController` lost `$contentLocale` and `$translator`.
      - `LiveExtension::live()`: `part()`, which templates compiled since 0.82 call (see the Twig cache note above).
    - **Machine translation (clean break).** An own `MachineTranslator` implements `translate(string $text, string $to, ?TranslationTuning $tuning)` and reads `$tuning?->instructions`. To stop a whole run (no key, no budget) it throws `Preflow\I18n\Machine\TranslatorUnavailable`; one that still throws `AiUnavailable` only fails the item. Code that catches `AiUnavailable` from `MachineTranslator`, `JobRunner::step()` or `TranslationWorker` catches `TranslatorUnavailable` (preflow/ai's own `Ai::client()` and `DraftWriter` still throw `AiUnavailable`). `MachineServiceProvider::hasTranslator($container)` becomes `$container->get(TranslatorAvailability::class)->available()`. A package or app that binds its own `MachineTranslator` also binds `TranslatorAvailability` (for example `new ConfiguredTranslatorAvailability(true)`) in a provider that registers after `MachineServiceProvider` and Folio; binding only the translator leaves Folio's AI translation off. Config `translation.translator` needs neither.
    - **Outside a render `ImageTag` uses the app's default locale** for alt text; a `Translator` locale a script set no longer steers it. A script that wants another language opens a render context in that language (`Application::render()` or `Contexts::run()` with a context made `->in('de')`).
    - **Console commands and `NotifyOutbox::discarding()`:** with core installed it throws inside any render frame, console commands included; use `Application::render()` with `RenderContext::discarding()` or `Contexts::run()`.
    - **`security.hsts`** may be an array: `['enabled' => true, 'subdomains' => false]` drops `includeSubDomains` (a site that shares its domain with sites that are not on HTTPS). A bool stays valid.
    - **Roles:** after the upgrade the roles screen announces `site.view_as_address` as new. Give it to the roles that should view any address (admins have it through `*`). Disabled permissions' stored grants are kept when a role is saved.
    - **Importer:** a write policy that refuses or skips a table or a file root now refuses the whole import.
    - **Extensions:** `AdminNav::add()` may be given a list of permissions (all required).
    - **Site runners of the testing contract** that still call a removed setter in `poisonApp()` or their factory fail the run; narrow the outer frame instead.
  - **Not in 0.83:**
    - 0.84: `search()` with no fields throws a clear error on every driver (the contract suite pins it), and the other follow-ups targeted at 0.84.0.
    - Later: published-from counts, a snapshot-only index, relations and assets as real lists, a condition grammar for conditional writes (one condition each driver compiles), DashboardItem lists, select options from another field, the site origin in `memoKey()`.
    - `AdminLocaleMiddleware::asFrontend()`'s unused `Translator` parameter is removed in 0.84.0.
- **Render context follow-ups (v0.82.0, released 2026-11-22):** the follow-ups from 0.81.0 and 0.81.1. It makes each request cheaper, settles cookies by a frame's outbox policy on every path (error pages included), fills live blocks inside `{% embed %}`, drops the testing contract's need for `#[IgnoreDeprecations]`, fixes the toolbar's nonce and limits, and lets a type say whether its ids are strings or numbers. Deprecated shims stay until 0.83.
  - **core:**
    - **New:** `Contexts::runRender($ctx, fn () => …)` opens a frame like `run()`, marked as a render entry's frame (`RenderFrame::$openedByRender`, internal): the frame's response is the caller's result. `Application::render()` uses it, and another runtime that builds a `Kernel` gets the cookie rule by opening its render frames with it.
    - **New:** `CookieQueue::reachesResponse()`, the one rule for whether a response takes the frame's queued cookies: true outside every frame and for a queue made with `new`; inside a frame, when the frame delivers and no render entry opened it. `CookieQueue::isFrameBound()` tells a frame-bound queue (`ofCurrentFrame()`) from one made with `new`.
    - **New:** optional interface `Context\SettlesOnClose`: a frame slot that settles what it holds when its frame closes, before the outbox policy applies. `CookieQueue` implements it.
    - **New:** `Http\ContentSecurityPolicy` reads CSP header values: `parse(...$values)` (comma-separated lists, first directive of a name wins, empty policies dropped), `ofResponse()` (enforced, else Report-Only, else null), `policies()`, and `nonceFor('script'|'style')` (`-src-elem`, then `-src`, then `default-src`; the nonce every restricting policy allows, else null).
    - **New (internal, tests only):** `Deprecations::expected(fn () => …)`: while it runs, `Deprecations::once()` neither reports nor records, so a test can call a shim on purpose without using up the notice.
    - **Changed:** every frame settles the cookies it left when it closes, by its outbox policy, not only `Application::render()`'s frame: a delivering frame hands them to the frame around it, a capturing one captures them, a discarding one drops them. A frame that queued nothing does no work; a frame whose render threw is not settled.
    - **Changed:** the Kernel's error path applies queued cookies by the same rule as the success path. A failing visit still sets them on its error page; a failing preview captures them; a failing internal render drops them; a failing delivering render hands them to the request around it; a failing nested in-process request sets them on its own error page.
    - **Changed:** a capturing `Contexts::part()` frame inside a frame that does not deliver moves all its captured items (mail too, not only cookies) to that frame when it closes, with its after-response work dropped. A preview now reports the cookies and mail its in-process requests would send. Inside a delivering frame the part keeps them.
    - **Changed:** `RenderContext::computeMemoKey()` is private and the internal `MemoKeys` class is removed. `memoKey()` is unchanged.
    - **Changed (cost):** one context is built per visit (`RenderContext::fromRequest()`), `narrow()` does not admit a frame's own context again, the middleware after the locale middleware keeps a context that already carries the request, SessionMiddleware reads the context once, and a frame makes its outbox on first use. The resulting context is the same, and the context and frame tests are unchanged.
    - **Fixed:** a cookie on the route's response is no longer lost when a middleware further out throws: the error page sets it, once.
    - **Fixed:** a request frame opened capturing (`handle()` under `withDerivedContext()`, a nested request under a preview) captures its queued cookies instead of losing them.
  - **auth:**
    - **Changed:** `ResolveUserMiddleware` sets a session guard's queued cookies only where the response takes them (new `SessionGuard::cookiesReachResponse()`, which asks `CookieQueue::reachesResponse()`); elsewhere the frame settles them when it closes.
    - **Changed (cost):** `AuthManager` resolves the viewer once per frame and viewer: `user()`, `users()` and `guardName()` read that one resolution. Another viewer or a nested frame resolves anew.
  - **twig:**
    - **Fixed:** a `{% live %}` inside an `{% embed %}` fills from the page cache (also two embeds of one template, an embed inside `{% extends %}`, an embed inside an embed, and an embed in a component's own template). The recipe still names the file; the file answers for its embeds' live blocks, so a recipe works across compilations. The embed's `with` values and `only` don't reach the live body. Blade has no equivalent construct and is unchanged.
    - **Changed:** the component class in a live block's recipe comes from the file the component renders, not from template variables: an `only` embed keeps it, and `with {componentClass: …}` cannot change it. A component with its own `getTemplatePath()` (not `ShortName.twig` next to the class) renders its live blocks in place and refuses the page (`live:twig-template`), instead of filling from the default file.
    - **Deprecated:** `LiveExtension::live()` (five parameters; `$componentClass` is ignored), kept for templates compiled by 0.81. Templates compiled by 0.82 call `LiveExtension::part()` (internal).
  - **testing:**
    - **Changed:** `RenderIsolationContract` marks its own deprecated setter calls as expected (`Deprecations::expected()`) and resets `Deprecations::once()` after each test, so a site runner needs no `#[IgnoreDeprecations]` and no reset of its own, also under `failOnDeprecation`. Every other deprecated call still reports.
  - **devtools:**
    - **New:** the toolbar has a "Request limits" section read from the request frame's `RequestLimits` slot (each time limit and what came of it, and `ignore_user_abort`).
    - **Fixed:** the toolbar's `<script>` and `<style>` carry the nonces of the response's own policy (`ContentSecurityPolicy::ofResponse()`), else the request's nonce. A whole-page preview no longer gets the admin's nonce, and the CSP inspector checks the page against its real nonces. `DebugToolbar::render()` gains optional `$limits` and `$styleNonce`, `CspInspector::issues()` an optional `$styleNonce`. This closes both 0.81.0 toolbar follow-ups.
    - **Changed:** `make:model --from-type` compares the id kind as the type answers it (a JSON type without a declared id field counts as a string id).
  - **folio:**
    - **Fixed:** the preview's frame check (`PagePreviewController::refusesFrames()`) reads policies through `ContentSecurityPolicy`. Two edge cases now follow the CSP spec: a comma-joined value is several policies, and only a policy's first `frame-ancestors` counts.
  - **data:**
    - **New:** `IdKind` (`String`, `Int`) and `DataManager::idKind(TypeDefinition)`: whether a type's records have string or number ids. The model class's id property decides (`IdKind::ofModel()`, also the new `TypeDefinition::$idKind`); else the storage (new optional `StatesIdKinds`); else a declared id field stored as a number; else a string id. A class whose table key holds the other kind makes `idKind()` throw a `LogicException` that names the type, both kinds and the column.
    - **New:** `PdoDriver` (SQLite, MySQL) implements `StatesIdKinds` and `RemembersColumns`: it reads the table's key column and asks its grammar to classify it through the new optional `Migration\Grammar\ClassifiesIdColumns` (SQLite by its affinity rules; MySQL treats every numeric type as a number, because it compares text with them as numbers). `forgetColumns()` drops the remembered answers. A driver that wraps another must implement and forward `StatesIdKinds`.
  - **members:**
    - **Changed:** `Members::synthetic()` (the view-as-role member) asks `DataManager::idKind()` and refuses Int: a member table with an integer key, a number id from a storage, or a field type an extension stores as a number. It accepts a declared id field of a type that is not stored as a number (`"text"`, or `"integer"` without Folio), which it used to refuse.
  - **publishing:**
    - **Changed:** Publisher's version reads keep the type's id kind (new `ShadowTypes::versionColumn()`). The published copy states no id kind: its table is the sync's, with a text key, so an int-class type's snapshots raise no conflict.
  - **i18n, media, view (cost):**
    - `Translator` finds each context's text cache without working out its key on every `t()` (an internal `CatalogueMemos` holder, replaced on every layer change).
    - `ImageTag` asks a `ContextLocaleProvider` once per render frame and context object.
    - `NonceGenerator` reads the frame's nonce with one `Contexts::slot()`.
  - **Performance** (budget: a request at most 2 % slower than 0.80.0; set-up as for 0.81.0: PHP-FPM with opcache, one worker, each request boots and renders once, versions interleaved request by request, a run is the median of 10 rounds of 50; private release notes name the pages). The final measurement is the last one made, after all code changes but the last small amend, which moves no call on the request path: 4 runs per page back to back, with the rule written before measuring that no completed run is left out, and none was. Ranges are over the 4 runs.

    | Page | Metric | 0.80.0 ms | 0.81.1 ms | 0.82.0 ms | 0.82.0 vs 0.80.0 | 0.82.0 vs 0.81.1 |
    |---|---|---|---|---|---|---|
    | signed-in member page, JSON/SQLite site | per request | 4.889–4.957 | 4.985–5.064 | 4.971–5.095 | +1.40 … +2.78 % | −0.89 … +0.61 % |
    | | render only | 2.248–2.280 | 2.375–2.407 | 2.375–2.416 | +4.76 … +5.96 % | −0.67 … +0.37 % |
    | 106 KB page, MySQL site | per request | 10.03–10.29 | 10.07–10.36 | 10.10–10.29 | −0.51 … +1.25 % | −0.66 … +0.82 % |
    | | render only | 6.530–6.836 | 6.634–6.930 | 6.669–6.896 | +0.69 … +2.13 % | −0.49 … +0.53 % |

    Function calls per request (exact, xdebug, one boot and request):

    | Page | 0.80.0 | 0.81.1 | 0.82.0 | 0.82.0 vs 0.81.1 |
    |---|---|---|---|---|
    | member page | 27 036 | 28 019 | 27 976 | −43 (4.4 % of the 983 that 0.81 added) |
    | MySQL page | 53 009 | 53 983 | 53 837 | −146 (15.0 % of the 974 that 0.81 added) |

    - **Reading:** the call counts are the firm evidence. The timing differences against 0.81.1 are all within the run-to-run spread (the same code measured against itself differs by about ±1 % per request), so they don't show a gain on their own.
    - **Against the budget:** the MySQL page is within 2 % of 0.80.0 per request in every run. The member page is at the budget, not clearly inside it: 3 of 4 runs are below 2 %, one is at +2.78 %, and the range is as wide as the spread. In the same runs 0.81.1 measured +1.96 … +2.32 % on the member page. Render only, the member page stays about 5 % slower than 0.80.0.
    - **The earlier measurement** (4 runs after the first round of cost cuts) gave the member page +2.03 … +2.22 % per request against 0.80.0 and −0.30 … −0.70 % against 0.81.1, and the MySQL page +1.15 … +1.88 %. In those runs, runs made while the machine was busier were left out by a rule set after they were made. The table above uses the later runs.
    - The 0.80.0 baseline runs each site's app code as it was on 0.80.0.
    - What remains on the member page is spread thinly over Twig, data reads and the remember-me path, with no single hot spot. Folding the request's three framework derivations (locale, session, user) into one would mean deferring middleware changes past middleware boundaries or making the context lazy on every read; both were considered and not taken.
  - **Upgrading:**
    - **Upgrade every `preflow/*` package together, to `^0.82`.** New constraints: auth, testing, devtools and folio require core `^0.82.0` (and conflict with core before 0.82.0); members and publishing require data `^0.82.0` (and conflict with data before 0.82.0).
    - **Clearing the Twig cache after upgrading is recommended** (`php preflow cache:clear`). Templates compiled by 0.82 call `LiveExtension::part()`. Templates compiled by 0.81 still work through the deprecated `live()`, which reports once per call site. Core's own Twig engine compiles into a folder per installed package set, so an update compiles anew; a Twig engine bound with a cache path of its own keeps the old templates, and Twig's `auto_reload` does not recompile them, because Composer keeps the files' archive timestamps.
    - **Cookies on error responses.** A visit's error page still sets the cookies the visit queued (a rotated remember-me cookie is kept), and now also those the route's response carried when a middleware further out threw. A failing `Application::render()` no longer puts its cookies on its error page (the result's response): a preview captures them (`RenderResult::wouldSend('cookies')`), an internal render drops them, a delivering render hands them to the request around it. A request frame (not a render) whose policy is not Deliver no longer sets its cookies on its error page; the frame settles them. The 0.81.0 test that pinned the old behaviour was changed on purpose: `ApplicationEdgesTest::test_kernel_error_path_applies_only_its_own_frames_cookies` is now `test_a_failing_preview_captures_its_cookies_and_the_outer_frame_keeps_its_own`.
    - **Frames settle their leftover cookies when they close,** every frame, by its policy. A request frame opened capturing now captures them, and a request a preview asks for in-process shows its cookies in the preview's `wouldSend('cookies')`; before, they were lost.
    - **The Kernel's default rule.** A `Kernel` given the frame-bound queue (`CookieQueue::ofCurrentFrame()`) and no `setsQueuedCookies` now follows `CookieQueue::reachesResponse()` on the success and the error path. Before, it set the queue on the error response only. A `Kernel` given `new CookieQueue()` behaves as before. `Application` no longer passes `setsQueuedCookies`.
    - **Another runtime's render entry** opens its frames with `Contexts::runRender()`, so their cookies never reach the response it renders (also not on its error page) and the frame settles them.
    - **`CookieQueue::reachesResponse()` and `isFrameBound()`** are the rule and the test for it; a runtime or middleware that applies the queue itself asks `reachesResponse()` first.
    - **ResolveUserMiddleware:** a guard's cookies follow the rule. In a render's frame or a frame that does not deliver it leaves them to the frame instead of setting them on the response.
    - **Capturing parts:** a capturing `Contexts::part()` inside a frame that does not deliver moves its captured items, mail included, into that frame when it closes. Code that opens such a part and drains the part's own outbox afterwards now gets nothing; it should use `Contexts::run()`.
    - **`LiveExtension::live()` is deprecated** and stays as a shim until 0.83. Only templates compiled before 0.82 call it; it ignores its `$componentClass` and delegates to `part()`.
    - **A component with its own `getTemplatePath()`** renders its `{% live %}` blocks in place and refuses the page (`live:twig-template`) instead of filling from the default `ShortName.twig` next to the class. Such pages are no longer stored by the page cache.
    - **Live blocks inside `{% embed %}` fill.** Before, they were not supported: a stored page left the live part out (or, inside an `only` embed of a component's template, the page was not stored).
    - **Site runners of the testing contract** (each `RenderIsolationTest` extending `RenderIsolationContract`) drop: the `use PHPUnit\Framework\Attributes\IgnoreDeprecations;` import; `#[IgnoreDeprecations]` above the class; `use Preflow\Core\Context\Deprecations;` when nothing else uses it; in `tearDown()`, the `Deprecations::reset()` block and its comment (and the whole `tearDown()` when it then only calls the parent); a docblock paragraph about the ignored setters. Keep `#[RunTestsInSeparateProcesses]` and any trait `tearDown()` aliasing. A site factory or `poisonApp()` that calls a deprecated shim now fails the run; before, the attribute hid it.
    - **`Deprecations::expected()`** (internal, tests only) is how a site's own test calls a shim on purpose without failing a `failOnDeprecation` run.
    - **`ContentSecurityPolicy`:** code that parses CSP headers itself can use it. The devtools toolbar carries the response policy's script and style nonces (a whole-page preview passes its own policy), and its request limits section shows what the request frame asked. Folio's `refusesFrames` reads a comma-joined value as several policies and only a policy's first `frame-ancestors`, as browsers do.
    - **`RenderContext::computeMemoKey()` is private** and `MemoKeys` is removed. Use `memoKey()`.
    - **Id kinds:** `IdKind`, `DataManager::idKind()`, the optional `StatesIdKinds` (drivers) and `ClassifiesIdColumns` (grammars) are new. A model class whose table key holds the other kind (an `int` id property over a text key) makes `idKind()` throw; check view-as-role on a site with members after upgrading, since that is where it is called. The published copy of a publishing type states no id kind. `Members::synthetic()` uses `idKind()`: a member model file that declares its id with a field type not stored as a number (`"text"`, or `"integer"` without Folio), or `"number"` on a table the sync made (a text key), now gets a view-as-role member; a member table with an integer key is refused.
    - **`ContextLocaleProvider`:** `ImageTag` reuses an answer for the same context object within a render frame. A provider's `localeOf()` must depend only on the context it is given and fixed configuration.
  - **Not in 0.82:**
    - Shim removal is 0.83, including `LiveExtension::live()` and the 0.81 shims and constructor parameters listed under 0.81.0.
    - The 0.81.0 follow-ups other than the toolbar's: Folio's importer and `ExtensionsFile` writing around the write policy, conditional writes taking their condition twice, and `memoKey()` leaving out the frozen time and site origin.
- **Render context patch (v0.81.1, released 2026-11-21):** the follow-ups found while upgrading the sites to 0.81.0.
  - **Fixed:** cookies a site queues (`CookieQueue`) reach the response without auth or a session: core applies a request's queued cookies to the route's response inside the pipeline, what middleware queued on the way out to the pipeline's answer, and each cookie once. The page cache does not store a page whose render set a cookie or left one queued (new `CookieQueue::pending()`): middleware inside the cache that queues after the route, verify's second render and a rebuild are covered. `ResolveUserMiddleware` no longer drops cookies queued before it ran, nor, on a request without a session, the site's. `Application::render()` keeps its outbox policy: a preview captures the cookies, an internal render drops them, a delivering render hands them to the request around it.
  - **Fixed:** `{% live %}` (preflow/twig) uses no Twig API deprecated since 3.12 (`Parser::getBlockStack()`, `hasBlock()`): each live block records the live tag open around it and `LiveBlockNesting` refuses one nested in the same template once it is parsed (directly, through a `{% block %}` or a `{% macro %}`; an `{% embed %}` is a template of its own, as before), with the same error at the inner tag's line; block names come from the tag's own per-source bookkeeping and are as before, except two live tags on one line on either side of an `{% embed %}` boundary, which now get different names (`__live_N`, `__live_N_2`).
  - **Fixed:** preflow/twig requires Twig 3.21 or later (`^3.21`, was `^3.0`): `{% live %}` calls `Parser::parseExpression()` (3.21) and builds a `Twig\Node\EmptyNode` (3.13), so an older Twig never worked with it.
  - **Fixed:** `Translator::setLocale()`'s deprecation names `admin($locale)` for the admin surface as well as `in($locale)`.
  - **New:** `Kernel`'s optional constructor parameter `setsQueuedCookies` (`\Closure(): bool`): whether the current frame's response takes its queued cookies; `Application` passes it. `CookieQueue::pending()` and `PageCacheMiddleware`'s optional `cookies` parameter (the frame's queue).
  - **Docs:** core README's 0.81 upgrade notes: on the admin surface `Translator::setLocale('de')` becomes `Contexts::narrow(fn ($c) => $c->admin('de', $c->surface->overrides))` (plain `admin('de')` turns the overrides on), not `in('de')`.
  - **Upgrading:** cookies a site queues on public pages without a session now go out, and such a page is no longer stored by the page cache (`X-Page-Cache-Skip: set-cookie`).
- **Render context (v0.81.0, released 2026-11-21):** every render runs under one immutable `RenderContext`: who is looking, the locale and the texts (public or admin), what it reads (published or working copies), the site, the clock, and what it may send and write. It is created at the edge: an HTTP request, a console command, an internal render, a preview, a mail, a PDF, a test. What a render collects (assets, SEO meta, GEO capture, page-cache tags, the CSP nonce, cookies, queued mail, after-response work, response headers) lives in a `RenderFrame` of its own, so nothing one render collects reaches another. Core only; Folio builds contexts and adds the screens. Core README, "Render context", has an example without Folio.
  - **Fixed:** internal renders (the SEO audit, GEO, page-cache rebuild and verify) no longer see the signed-in user, the admin's texts, the visitor's cookies or superglobals, and never send mail or write data (the audit lists the writes a page tried, per page); a rebuild no longer inherits a fragment's assets; an audit run from the admin keeps the admin's debug collector; mail layouts render in the recipient's language; every render has its own CSP nonce; a site's step preview in another language no longer leaves the admin's language changed.
  - **View as a role:** render any page as a member or staff role (`$ctx->asRole(new RoleView('member', ['gold']))`) without an account; writes are skipped and listed, mail is captured. In Folio: "View as" in the preview bar, permission `site.view_as` (declared by rbac; admins hold it through `*`).
  - **Whole-page preview:** `Application::preview()` renders the autosaved working copy through the real route (layout, navigation, SEO, components) and reports what it would send and write. The page's interactive parts follow the preview through a signed preview token (session-bound, 15 minutes, permissions re-checked on every use); a write under it is refused with 403. Folio's Preview and Split use it.
  - **Owner decisions built in:**
    - Anyone with `site.view_as` may view as any role, stronger ones included.
    - Drafts of every type are visible to every editor and to every share-link holder. A preview token has no path or type scope: an editor who can open a preview at all (any type's edit permission) sees the working copies of every type while previewing.
    - Production refuses to start with a weak app key (no opt-out).
    - Without preview tokens (debug mode with a weak key) a preview switches its interactive parts off instead of letting them run on the live site: the controls stay visible, a click or submit is stopped with "Switched off in this preview.", and the preview bar explains why.
  - **Performance** (budget: a request at most 2 % slower than 0.80.0; PHP-FPM with opcache, each request boots and renders once, median of 10 rounds × 50 warm requests; private release notes name the pages). After the optimization pass: a 106 KB page of a MySQL site, per request +0.8 % to +2.5 % (render +2.7 % to +2.8 %); a signed-in member page of a JSON/SQLite site, per request +0.8 % to +1.9 % (render +4.0 % to +5.7 %). The ranges are separate runs of the same code: their spread (about ±1 %) is as large as the remaining difference, so the 2 % budget per request is met in some runs and missed by up to half a point in others. What remains is a fixed cost of about 0.05 to 0.1 ms per request, spread over building the context, opening the frame, the locale and user middleware's `narrow()` calls and per-call context reads; no single hot spot. The pass (`Contexts::slot()`, a memo key computed once per context, a cheaper context copy, a lighter translator lookup) cut the extra function calls per request by about 15 % but made no difference measurable at that resolution. Accepted for 0.81 as within measurement noise; three larger reductions (one context derivation instead of the request's `narrow()` calls, a lighter frame open, fewer auth lookups) are planned for 0.82. `tools/bench-render.php` measures a page of an app against a checkout.
  - **Follow-ups:**
    - Folio's importer and `ExtensionsFile` write around the write policy (admin only; admin routes refuse role views and grant nothing to tokens): route them through `DataManager` or `Writes::allowed()` (0.82).
    - Conditional writes take their condition twice (a PHP closure and SQL): one storage-neutral condition each driver compiles.
    - ~~The devtools toolbar does not show the frame's `RequestLimits` yet.~~ Done in 0.82.0.
    - ~~In debug mode the toolbar adds the admin's nonce to a previewed page (one report-only CSP violation, debug only).~~ Done in 0.82.0.
    - `RenderContext::memoKey()` leaves out the frozen time and the site origin (no time travel and one site per app today).
    - 0.83 removes the shims and unused constructor parameters below, merges `ContextBlockLocale`/`ContextLocaleProvider` into their base interfaces, and decides what `ImageTag` uses outside a render once `LocaleProvider::current()` goes (Folio's `ContentLocale::get()` stays as Folio's own method).
  - **Upgrading:**
    - **Do this first:**
      - **Check the app key before you deploy.** In production (`app.debug` off) a web app does not start when `APP_KEY` is empty, shorter than 32 bytes after trimming, or one of the two public keys: the built-in default `preflow-default-key-change-me!!` (used when `app.key` is not set at all) and `change-this-to-a-random-32-char-string!` (the skeleton's and starters' `.env.example`). Boot then throws `WeakAppKey`: an uncaught exception, so the site answers 500 (or what its `index.php` makes of a failed boot) and the message is in the PHP error log. Console commands still start.
        - Fix: `php preflow key:generate` (needs `preflow/devtools`; it writes a new `APP_KEY` to `.env`), or, on a server without devtools, set `APP_KEY` to the output of `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Then clear the page cache (`php preflow cache:clear pages`, Folio's Cache screen, or delete `storage/cache/pages`): stored pages carry live-part and per-request placeholders signed with the old key, which a new key no longer fills in. Deploy again.
        - What a new key changes: sessions, remember-me sign-ins and password links do not use it and stay valid. Share-preview links stop working; forms (stateless CSRF tokens) and component actions on pages that are already open fail until they are reloaded; preview tokens in use end; rate-limit counts (sign-ins, codes, AI per-person limits) start again, because their client ids are hashed with the key.
        - In debug mode the app starts and warns once; page-cache storing, live-part signing, preview tokens and share-preview links stay off until the key is fixed.
      - **Upgrade every `preflow/*` package together, to `^0.81`.** The packages require and conflict with each other's 0.81.0, for example: core conflicts with auth, data, i18n, validation, view and notify before 0.81.0; most packages require core 0.81.0; folio, members, rbac and roles require auth 0.81.0; auth and codes require data 0.81.0; validation requires i18n 0.81.0; components requires view 0.81.0; folio conflicts with blocks, i18n, media, publishing and rbac before 0.81.0; htmx and testing conflict with view before 0.81.0; publishing conflicts with devtools before 0.81.0. Core now requires `psr/clock`.
      - Move site code off the deprecated calls below in the same upgrade; each one says once per call site where it is called.
    - **Removed** (no shim):
      - core: `Http\Session\SessionDataGuard` (with `hide()`, `restore()`, `hiding()`, `onShutdown()`); `DependencyRecorder::outside()` (use `suspended()`); `PageCacheMiddleware`'s constructor parameters `visitorScope` and `verifyScope` (now `?Closure $render`, `?ResponseHeaderHints $hints`). `DebugCollector::reset()` stays but nothing calls it.
      - view: `AssetCollector::isolate()` and `restoring()`; `SeoContext::scoped()` and `reset()` (and SeoMiddleware's reset call); `GeoCollector::enter()`, `leave()` and `reset()` (a component uses `component(Closure)`, a capture `captureInto(GeoCapture, …)`); `NonceGenerator::adopt()` and `release()` (now `adoptInFrame()`; tests fix the nonce with `fixedForTests()`).
      - view constructors: `PageRenderer`'s closure takes the request and the locale (`$render(request, locale)`), and `$scopes` and `$assets` are gone, so `$locales` and `$defaultLocale` move to positions 3 and 4. `GeoMiddleware`'s `$assets` and `$scopes` are gone: `$render` (a closure taking the `GeoCapture`) takes the place of `$scopes`, is required, and `$appUrl` has no default any more. `PageRenderer`'s first parameter is now `$render` (it was `$handle`), so a named `handle:` argument fails.
      - blocks: `BlockLocale::scoped()` and `DefaultBlockLocale::scoped()`; `DefaultBlockLocale`'s second constructor argument is ignored, so a named `current:` argument now fails.
      - folio: `ContentLocale::scoped()`, `Collections::isolate()`, `Singletons::isolate()`, `Members\FolioPageLocale`; `WorkingCopyMiddleware`'s constructor takes only `AdminNav` (the `LiveContent` parameter is gone).
    - **Deprecated (removed in 0.83):** each reports `E_USER_DEPRECATED` once per call site; "from here on" becomes `Contexts::narrow(fn ($c) => $c->in('de'))`, set-and-restore becomes `Contexts::within(Contexts::current()->in('de'), fn () => …)`.
      - `Translator::setLocale()` (→ `narrow(… ->in($locale))`) and `useOverrides()` (→ `->admin($uiLocale, $overrides)`).
      - `AuthManager::setUser()` and `addUser()` (→ `->as($viewer)`; they throw `NoRenderContext` outside every render and refuse inside a role view).
      - `LiveContent::showWorkingCopies()` (→ `->workingCopies()`) and `isolate()` (→ `RenderContext::published()` plus `memoKey()`).
      - `CookieQueue::isolated()`, `InternalRender::mark()` and, with core, `Notifier::discardingQueued()` (→ `Application::render()` with `RenderContext::internal()` or `discarding()`; notify on its own keeps `discardingQueued()`).
      - `PageCache::isolate()`/`isolated()` and `RenderScopes::add()` (→ a facet or `memoKey()`).
      - `FolioText::use()`, `InterfaceLocales::setCurrent()` (→ `->admin($locale)`) and `ContentLocale::set()` (→ `->in($locale)`); outside every render they do nothing.
      - `ValidatorFactory::useTranslator()`: inside a render it now sets the translator for the rest of that frame only (past an enclosing `within()`; a `part()` frame starts from the factory's own); outside every render it sets the factory's default.
      - `LocaleMiddleware::urlPrefix()` (→ `Contexts::current()->request->localePrefix`).
      - Members' interface `PageLocale` (`Preflow\Members\PageLocale`, → `Contexts::current()->locale`); `BlockLocale::get()` and media's `LocaleProvider::current()` for implementations without the new optional interfaces; `DefaultBlockLocale::get()` (now reports, and throws `NoRenderContext` outside a render); `ConfigLocaleProvider::current()` (reports inside a render only).
      - Constructor parameters no longer used, removed in 0.83: `LocaleMiddleware` `$translator`; `MembersView` `$translator`; Folio's `AdminLocaleMiddleware` (translator, engine, validators), `FrontendController` (contentLocale, live), `PreviewController` (contentLocale, translator); `DefaultBlockLocale`'s second argument.
    - **Changed behaviour:**
      - `Contexts::current()` throws `NoRenderContext` outside a render. Code in shutdown functions or scripts without a frame uses `Contexts::peek()` (null there). Outside a request (after `handle()` returns, in a command without a frame, in shutdown hooks) the container's `RequestContext` is null: read the request during the request or from `Contexts::current()->request`.
      - These need an open render (they throw `NoRenderContext` otherwise; tests wrap them in `Contexts::run(ContextFactory::forTest(), …)`): `LocaleMiddleware::process()`, `AuthManager::setUser()`/`addUser()`, `PdfRenderer`, `Blocks::forPdf()`/`forPreview()`, `MembersView::render()`, `BlockRenderer`, `Members::register(emailVerified: true)` (its request now comes from the context), Folio's admin auth, locale and working-copy middleware, and console commands that read the context (`PublishScheduledCommand::execute()` and others) when called without `Console::run()`.
      - Services must not keep a `RenderContext` in a property (the architecture guard enforces it); superglobals are not part of the contract (read `Contexts::current()->request`).
      - Custom `BlockLocale`, members' `PageLocale` and media `LocaleProvider` implementations should implement the new optional `ContextBlockLocale`/`ContextLocaleProvider` (`localeOf(RenderContext)`); custom `MailLayout`s read the locale from the content or the current render (the compose scope renders them in the recipient's context, in a frame that discards what they queue).
      - Pages that write on GET: internal renders and previews skip every data write and return as if written (raw `execute()` returns 0, a counter returns its stored value, a delete returns nothing); the SEO audit lists them per page. Writes that account for real cost (the AI usage log and per-person AI limits) still run.
      - Mail: `send()` and `Mailer::send()` follow the render's policy too (in an internal render they no longer send). `MailContent::$lang` may be null (the current render's locale). A `Notifier` a site binds itself must take the container's `DeferredQueue` (fifth argument): core throws in debug and logs otherwise. notify's frame channel is named `notify`; a `DeferredQueue` takes `Deferred` values. Mail queued while a PDF renders is dropped (its writes stay). A visitor's request that fails still sends the mail it queued.
      - `handle()` given a context that captures or discards at the outermost frame sends nothing (it used to throw).
      - Auth: `Authenticatable` extends core's `Identity` (no user class changes). `addUser()` on an anonymous viewer makes that user the primary one. Inside a role view, `guard($name)` gives nobody for the configured guards (use `user()`), and the role view has no session. Guard names starting with `preflow:synthetic:` are reserved. A permission resolver of your own must implement `Access\AllowsRoles` for role views (a resolver without it grants a role view nothing). `GuestMiddleware` takes an optional `Access`.
      - Hypermedia: `HX-*` headers are refused by name only when the installed client owns them (`OwnsResponseHeaders`); a driver keeps its effects per render frame.
      - Rendering: one CSP nonce per render frame (tests comparing two responses byte for byte ignore it); an in-process sub-request no longer adds its assets to the page head; a visitor's first verified render starts from the boot asset snapshot, so assets a middleware adds before the page cache in that request do not appear on that response (no package does this); GEO skips `no-store` and `private` pages; a page rendered inside a PDF writes browser blocks, not `<pagebreak />`; `CanonicalPathMiddleware` also skips verify renders; the page cache stores only visits and rebuilds under the public default context.
      - Folio: texts on public pages follow the page's locale (they were English); the engine global `interface_locale` is a Stringable object now (use it as a string: `~` or `|e`, not as an array key or with `same as`); admin routes refuse role views, except where the site has no `config/auth.php` (a role view needs auth anyway). On a site with rbac, Roles announces `site.view_as` as a new permission.
      - skeleton: `Navigation` and `LocaleSwitcher` accept a null `RequestContext`.
      - `Contexts::reset()` (tests) forgets every hook packages register at boot: a long-running worker that calls it boots the app again.
      - A request carrying a preview token is answered `private, no-store`, `no-referrer`, `noindex`; pages the page cache serves or stores, and public pages while tokens are on, send `Vary: X-Preflow-Preview`. Some CDNs (Akamai-style) skip caching a page whose `Vary` names more than `Accept-Encoding`: let them ignore that header and bypass requests that carry `X-Preflow-Preview` or `_preview`.
    - **New:**
      - core `Preflow\Core\Context`: `RenderContext`, `Contexts` (`run`, `part`, `narrow`, `within`, `current`, `peek`, `clock`, `frame`, `slot`, `depth`), `RenderFrame` (`slot()`, `partOf()`), `RenderResult`, `Purpose`, `Viewer` (`isSynthetic()`, `primaryGuardName()`), `RoleView`, `SyntheticIdentity`, `IdentityId`, `Outbox`, `OutboxPolicy`, `WritePolicy`, `Writes` (`allowed()`, `policy()`), `WritesRefused`, `RefusedInPreview`, `CapturedItem` and `SkippedWrite` (the items of `RenderResult::$captured` and `$skippedWrites`), `Surface`, `ReadMode`, `Site`, `Facet`, `FrozenClock`, `SystemClock`, `NoRenderContext`, `Deprecations`; `Preflow\Core\Identity`.
      - core `Application::render()`, `Application::preview()`, `Application::site()`, `Application::withDerivedContext()`; `Application::holdMailForFlush()` is an internal test seam.
      - Preview tokens: `Context\Preview\PreviewToken`, `PreviewTokenMiddleware` (added after auth resolves the user), `PreviewGrant`, `PreviewGate` (`site.view_as`), `PreviewUrls`; `Security\AppKey` (`canSign()`), `WeakAppKey`.
      - `Http\ResponseHeaderHints` and the template function `response_header()`; `Http\RequestLimits` (a frame slot for time limits); optional client interfaces `Http\Hypermedia\OwnsResponseHeaders` and `CarriesRequestHeaders` (htmx implements both); `RequestContext::$localePrefix`.
      - Optional interfaces `Preflow\Blocks\ContextBlockLocale`, `Preflow\Media\ContextLocaleProvider`, `Preflow\Auth\Access\AllowsRoles`; auth's `SyntheticUser` (the user a role view is signed in as); `RenderContext::withClock()`.
      - data: `DataManager::updateIf()`/`deleteIf()` (conditional writes that follow the write policy on every driver), `incrementIn(…, accounting:)`; core `Throttle::hit(…, accounting:)`.
      - notify: `Deferred\DeferredQueue`, `Deferred`, `InMemoryQueue`, `Mail\ComposeScope`, `DirectCompose`, `Notifier::queue()`/`deliver()`.
      - members `Members::synthetic()`; rbac declares `site.view_as`; Folio `PagePreviewController` and `Http\ViewAs`.
      - testing: `ContextFactory::forTest()`; `WebApplication::at()` and `withContext()` (take and return the request); `FrameBalanceExtension` (sites can register it in `phpunit.xml`) and the trait `ChecksFrameBalance` for tests in separate processes; the contract `RenderIsolationContract`; `HypermediaDriverContract` and `StorageDriverContract` gain cases.
      - `tools/bench-render.php`; the architecture guard's `architecture.no-ambient-state` rules and `write.accounting-allowlisted`.
- **App-feedback follow-ups (v0.80.0, released 2026-11-19):** the follow-ups decided after 0.79.
  - **A given int id is kept (data):** `insert()` and `insertIgnoring()` on a model with an int `#[Id]` write a non-zero id the caller set, instead of replacing it with a generated one; a second insert of that id throws the duplicate-id error (`insertIgnoring()`: false). An id of 0 (or null) is still generated, and the next generated id goes past the given ones.
  - **`InsertIfNew` (data, for driver authors):** an optional interface beside `Dialect`: `insertNewSql(string $table, array $columns, string $idField): ?string` returns one statement that skips an existing id instead of failing (for example `INSERT … ON CONFLICT ("id") DO NOTHING`), for a database whose failed statement aborts the transaction. `PdoDriver::insertNew()` then reads the row count. `StorageDriverContract` gains `test_insert_new_keeps_a_given_int_id_and_generates_past_it`.
  - **`#[Seed(debugOnly: true)]` (data attribute, devtools `db:seed`):** a full `db:seed` with `app.debug` off skips such seeders and prints `Skipped: <Name> (debug only; app.debug is off)`; `db:seed --class=<Name>` refuses one (exit 1) unless `--force` is given. Unmarked seeders run as before. `Preflow\Data\Seed`.
  - **preflow/ai is optional (folio, i18n):** both packages only suggest it. Their AI features (AI translation, "Write a draft", Settings → AI, the `ai.*` permissions) appear only when it is installed; without it the screens look as they do with AI off. A translator of the app's own (`config/ai.php` `translation.translator`) works without the package. New in i18n: `Machine\AiPackage::installed()` (the one check, for any package), `Machine\MissingAiTranslator` and `MachineServiceProvider::hasTranslator()`.
  - **Docs:** Folio's README says why every content type gets the system columns (publishing and translation can be switched on later without a migration) and why media, blocks and publishing are required (Folio's library, fields and publishing screens are built on them); the data README covers given int ids and `InsertIfNew`; the devtools README `#[Seed(debugOnly: true)]`.
  - **Upgrading:**
    - Raise every `preflow/*` constraint to `^0.80` and upgrade them together: Folio needs i18n 0.80, devtools needs data 0.80, and data 0.80 refuses devtools before 0.80.
    - **AI features need `preflow/ai` now.** A site that uses AI translation (Translate, `translate:plan`, `translate:work`), "Write a draft" or Settings → AI runs `composer require preflow/ai:^0.80` in the same upgrade, otherwise those features disappear. A site that translates only through a translator class of its own (`translation.translator` in `config/ai.php`) needs nothing; that translator keeps working. Without either, the `translate:work` cron job stays quiet, and `translate:plan` says what is missing: "Machine translation needs preflow/ai (composer require preflow/ai) or a translator of your own (config/ai.php translation.translator)."
    - **`insert()` keeps an int id you set.** Before 0.80, a non-zero id set on a model with an int `#[Id]` was replaced by a generated one; now that id is written, and inserting the same id twice throws the duplicate-id error. Code that relied on the old replacing must leave the id at 0.
    - **Driver authors:** a dialect may implement `InsertIfNew`. A database whose generated ids do not move past an id written by hand (PostgreSQL serial or identity columns) must advance its sequence after such a write; the new contract case checks it.
    - **Demo seeders:** mark them `#[Seed(debugOnly: true)]` instead of guarding on `app.debug` by hand. A full `db:seed` on production then skips them; `--class=<Name>` needs `--force` there.
- **App feedback (v0.79.0, released 2026-11-18):** fixes and additions from a new API-plus-admin app built on 0.78.3.
  - **Container:** a constructor parameter with a default takes it when the container cannot build its type (an unbound interface, an abstract class, `\Closure`); a bound type or a buildable class is still injected.
  - **405 behind a catch-all (routing):** a wrong method on a path that has routes for other methods answers `405` with `Allow`, even where a catch-all page route (Folio's frontend) matches, unless a page answers that path (a form posting back to its own page still loads). core `Routing\AnswersPages`, `Router::answerPagesWith()`.
  - **JSON body limit (core):** `config/api.php` `max_body` (API prefixes) and `max_json_body` (every other path), in bytes; a JSON body over the limit answers `413` before it is parsed. Default: no limit.
  - **Insert only if new (data):** `insert()` no longer overwrites a record another writer stored between its check and its write: it throws the duplicate-id error. New `insertIgnoring()` (false for an existing id) and `insertMany(…, ignoreDuplicates:)`. Capability `InsertsNew` (SQLite, MySQL, JSON); drivers without it keep the check-then-write.
  - **Auto-numbered keys (data):** `Table::increments()`: `BIGINT AUTO_INCREMENT PRIMARY KEY` on MySQL (kept by `convertColumn`), `INTEGER PRIMARY KEY` on SQLite. `integer()->primary()` stays an unnumbered key.
  - **Queries (data):** `pluck(field)`, `whereIn(field, subquery)` / `whereNotIn()` (one-column `SELECT` and `IN (SELECT …)` on SQL drivers through the capabilities `SelectsColumns` and `SelectsSubqueries`; other drivers run the inner query first, same rows), and a condition per aggregate measure (`->countDistinct('install', as: 'active', where: [['day', '=', $d]])`, capability `ConditionalMeasures`). A median comes from an ordered `pluck()` (README recipe).
  - **Folio overrides:** `OverridableAction::handle()` may return a `ScreenView`, rendered in the admin layout like a screen (prefix, csrf, page assets); one shared `AdminPage` renders both. The README shows a full override.
  - **`staff:roles <email> [--role=a,b | --grant=… --revoke=…]` (rbac):** shows or changes an existing account's roles, with the rules of every save (unknown roles refused, the last manager keeps the right to manage staff).
  - **`throttle:prune --every=<seconds>` (devtools):** prunes several times within one cron run, so a row lives at most its window plus that interval.
  - **Starters:** `config.allow-plugins` decides `php-http/discovery` (off): an older Composer stopped non-interactive installs of a new app on the undecided plugin; preflow/ai brings its own HTTP client.
  - **Docs:** an app's own `config/<name>.php` (`configFile()` and the provider recipe, core README), `db:seed --class` and the demo-seeder guard (devtools), why plain models state exactness twice (data), PHP 8.4+ everywhere.
  - Upgrading:
    - Raise every `preflow/*` constraint to `^0.79`; routing needs core 0.79, testing needs data 0.79.
    - A GET on a path that only has POST (or other) routes, where no page answers, now gets `405` instead of the catch-all's `404` or page.
    - A constructor parameter with a default and an unbound interface, abstract class or `\Closure` type now gets its default instead of a container error.
    - Code that relied on `insert()` overwriting a record stored meanwhile must use `save()`.
    - Apps made from an older starter may add `"config": {"allow-plugins": {"php-http/discovery": false}}` to `composer.json`.
    - A custom aggregating driver must implement `ConditionalMeasures` to accept conditional measures (without it, `get()` refuses them); a custom storage driver may implement `InsertsNew`, `SelectsColumns`, `SelectsSubqueries`.
- **Entries for every language skip the language redirect (v0.78.3, released 2026-11-16):** two cases where 0.78.2 sent an address through the language redirect although the answer does not depend on the language.
  - An entry for every language (no locale: old asset files, `/index.php`) no longer counts for the bare redirect (`RedirectEntryExists` answers for entries of one language only): `/favicon/old.ico` answers its `301` at once instead of `302 /de/favicon/old.ico` first.
  - With `prefix_default` and `redirect_bare`, an untidy bare address (`/old-help/`) no longer takes one language's entry in the clean-path one hop: it answers the clean `301 /old-help`, which then passes the language redirect. An entry for every language still answers in one hop.
  - core, routing and folio's own constraint tests follow 0.78.2's `require` and `conflict` (0.78.2 shipped with them stale; the packages' constraints were right).
  - Upgrading: nothing to do. Only sites with `redirect_bare` and redirect entries see a difference.
- **Locale URL gaps (v0.78.2, released 2026-11-16):** four places where 0.78's language URLs could not keep a site's existing addresses.
  - `config/i18n.php` `home_slash` (default false, needs `url_strategy` `prefix`): a prefixed language's home is `/de/` instead of `/de`, in `locale_url()`, the bare-path redirect and the switch endpoint's default `to`. `LocaleUrls::$homeSlash`, `LocaleUrls::isHome()`. With `canonical_paths` the clean form of a language home is then `/de/` (`/de` answers 301 `/de/`); `/` is always clean.
  - `/robots.txt`, `/sitemap.xml` and `/llms.txt` are never sent to a language by `redirect_bare` (the README promised it; a route there was redirected).
  - A bare address that only a redirect entry answers (no page) goes to the visitor's language like a page does: `/old-help` with a German browser → `302 /de/old-help` → the entry's `301`. core `PageLookups::addAnswer()`/`answers()` (lookups asked by the bare redirect only; an answer is not a page), routing `Redirect\RedirectEntryExists`. With `prefix_default` and `redirect_bare`, the redirect rules accept a bare target (`/old-help.php` → `/old-help`): bare addresses always pass the language redirect first (optional `Redirect\LanguageRedirectedAddresses`, implemented by Folio's site addresses).
  - With `canonical_paths`, an untidy address whose clean form has a redirect entry answers with the entry's target in one hop (`/de/old-help/` → `301 /de/help`), unless a page answers the clean address (a page wins over an entry, as for a 404). `Redirect\RedirectResolver` holds the lookup `RedirectMiddleware` and `CanonicalPathMiddleware` share.
  - Upgrading:
    - Nothing to do; sites without `home_slash`, redirect entries and `canonical_paths` answer exactly as on 0.78.1.
    - With `redirect_bare`: a bare address with a redirect entry and no page now gets the language redirect first (before: the default language's entry answered at once). `robots.txt`, `sitemap.xml` and `llms.txt` no longer get `Vary: Accept-Language, Cookie` from the locale middleware. A site that added `/sitemap.xml` to `NonPublicPaths` to stop the redirect can remove that line.
    - With `canonical_paths`: an untidy address with a redirect entry is one hop instead of two, and counts one hit.
    - `CanonicalPathMiddleware` takes optional trailing constructor arguments (resolver, LocaleUrls, hit, page lookup); existing calls keep working.
- **Search ignores accents (v0.78.1, released 2026-11-16):** `LIKE`, `NOT LIKE`, `CONTAINS`, `search()` and loose search (Folio's lists, ⌘K, the relation and block pickers) now ignore accents as well as case and width, on every driver: "uber" finds "Über", "elise" finds "Élise", "istanbul" finds "İstanbul", "lodz" finds "Łódź". Matching still goes character by character, as MySQL/MariaDB's LIKE does under an accent-insensitive collation: "strasse" does not find "Straße", "ilik" does not find "ılık", "aegir" does not find "Ægir"; the long s ſ is an s. Exact comparisons (`=`, `IN`, unique checks, sorting) keep 0.78.0's rule: case ignored, accents count ("Muller" is not "Müller").
  - `TextMatch::matchFold()` is the matching rule in PHP, from a second generated table (`search-fold-table.php`, `bin/text-fold-table --search`, checked against the server in the MySQL suite); `like()`, `contains()` and `asciiSafe()` use it. `fold()`, `equals()`, `compare()`, `key()` and `TextMatch::version()` are unchanged.
  - MySQL/MariaDB matches in `utf8mb4_uca1400_nopad_ai_ci` (MariaDB 10.10+) or `utf8mb4_0900_ai_ci` (MySQL 8), found by probing (`MysqlDialect::SEARCH_COLLATIONS`); columns keep their accent-sensitive collation.
  - Upgrading:
    - No `migrate` needed: no schema, collation or index changes.
    - Sites see more search hits: "u" also finds "ü". On MySQL, ranked ⌘K results (FULLTEXT) score a match that differs only by accents 0, so it comes last.
    - A third-party driver that passed 0.78.0's `StorageDriverContract` now fails the matching tests (`test_like_ignores_accents` and others) until it folds accents; `Dialect::likeOperator()` and `PatternMatching` say how, with `TextMatch::matchFold()` as the reference.
    - `MysqlDriver` and `MysqlDialect` take an optional `searchCollation`; `matchCollation: false` (in `config/data.php`) still sends LIKE to the `LOWER()` fallback, as in 0.78.0.
- **Storage contracts and locale URLs (v0.78.0, released 2026-11-16):** every storage driver keeps the same contract, and nothing above the drivers asks which driver it is. A new driver implements `StorageDriver` and opts into named capabilities; the shared `StorageDriverContract` proves each one on JSON, SQLite and MySQL.
  - **Capabilities (data):**
    - `RunsSql` (`rawQuery()`, `execute()`, `dialect()`) and `PdoConnection` (`pdo()`, for migrations). `rawQuery()` left `StorageDriver`.
    - `Transactions` (`transaction()`, `inTransaction()`) and `DataManager::transaction()` across storages (`PartialCommit` when a later commit fails).
    - `AtomicCounters` (`increment()`, `CounterReset`) and `DataManager::incrementIn()`. Throttle counts through it and no longer writes lock files.
    - `RankedSearch` (`canScore()`, `score()`) and `DataManager::canScore()`, `scoreIn()`. ⌘K orders a type's hits by relevance on storage that scores (MySQL with a FULLTEXT index); the set of hits is the same on every driver. `RecordSearch` scores a type's first `CANDIDATES` (200) hits; `RecordHit::$score` carries the score.
    - `DescribesColumns::hasTable()`; the JSON driver now implements `DescribesColumns`.
    - `Maintenance` (`check()`, `compact()`) and `Dumps` (`dump()`, `restore()`), for storage its database's tools can't check or restore once Preflow owns it; SQLite implements both (`preflow db:check`, `db:vacuum`, `db:dump`, `db:restore`).
    - `UniqueIndexes`, a marker for storage whose unique indexes refuse duplicates. Unique checks and asset usage are found the same way on every driver.
    - `Preflow\Testing\PlainDriver` hides a driver's capabilities, to test the bare-minimum path.
  - **Full-text search per type:** `"fullText": true` in a model file or `#[Entity(fullText: true)]`. On MySQL, `migrate` then adds a FULLTEXT index over the type's searchable text fields (`LooseFields::text()`; a schema grammar opts in with `FullTextIndexes`).
  - **One text-matching rule on every driver:** `LIKE`, `NOT LIKE`, `CONTAINS`, `search()` and loose search (Folio's lists, ⌘K) ignore case and width and respect accents (since 0.78.1 they ignore accents too, see above), character by character as MySQL/MariaDB's LIKE does: "über" finds "Über", "uber" does not; "abc" finds "ＡＢＣ"; "strasse" does not find "Straße"; the Kelvin sign is a k; ſ, İ and ı are letters of their own. MySQL/MariaDB compares with an accent-sensitive collation in the query (`utf8mb4_uca1400_as_ci` on MariaDB 10.10+, `utf8mb4_0900_as_ci` on MySQL 8, else `LOWER()`); `migrate` gives text columns an accent-sensitive collation (below). `Preflow\Data\Text\TextMatch` is the rule in PHP (JSON, SQLite, Folio's in-memory searches), with a fold table derived from the server's collation (`bin/text-fold-table`) and checked against it in the MySQL suite.
  - **Exact comparisons follow the text rule (data; JSON, SQLite and MySQL/MariaDB):** `=`, `!=`, `IN`, `NOT IN`, range comparisons, `ORDER BY`, grouping and unique checks on a type's text fields ignore case and respect accents ("Müller" is a duplicate of "MÜLLER", "Muller" is not; "Apfel", "äpfel", "eagle", "Émile", "Zebra"). Text fields are `string`, `text` and the text kinds extensions register; list fields (tags) and fields marked `"exact": true` / `#[Field(exact: true)]` compare exactly, as do ids, numbers, dates and yes/no values. `TextMatch::equals()`, `compare()`, `key()`; `TextColumns::of()`; `Query::compareAsText()`, `namesTextFields()` (the only text hints a query carries: a query built by hand names its text fields with it; whatever else a database needs, its driver reads from the table). Requires `ext-intl`. On SQL the rule lives in the columns, so indexes and unique indexes apply it: text fields' record columns and a migration's `string()`/`text()` declare `COLLATE preflow_text` on SQLite (registered by `SqliteDialect::prepare()`, backed by `TextMatch::compare()`) and the rule's collation on MySQL/MariaDB (`utf8mb4_uca1400_nopad_as_ci`, else `utf8mb4_0900_as_ci`, found by probing; LIKE uses the same one); every other column is exact (`utf8mb4_bin` / BINARY). `Migration\Grammar\TextCollations` (columns that carry the rule) and `ConvertsCollations` (converting existing tables, optional), `Driver\TextComparison`, `Table::exact()`, `ColumnSpec::$text`; `StorageDriverContract` field kinds `text` and `short_text` (`string` is now `exact`) and section "Exact comparisons". `migrate` converts the tables made before (one `ALTER TABLE` or SQLite rebuild per table), each column to its intent, text or exact: record tables by their type; the framework's tables by their declaration (`Schema\DeclaredTables`: `auth_tokens`, `auth_remember`, `preflow_throttle`, `code_redemption` … get exact machine columns); tables a migration makes from 0.78 on by what `Migrator` records (`Migration\MigrationColumns`, `preflow_migration_columns`). A unique column whose values would collide under its new collation ("Müller"/"MÜLLER", or "Straße"/"STRAẞE" from `utf8mb4_unicode_ci`) keeps its collation, its groups are listed with their records and `migrate` exits 1, without changing or removing a row; `migrate --pretend` lists them first. Tables without known intent (a site migration's before 0.78, another application's) are left alone and listed once; `config/data.php` `'text_rule' => ['skip' => [...]]`. SQLite rebuilds its text indexes when PHP's ICU changes (`TextMatch::version()`); JSON lists existing case-variant duplicates. `RecordTableSync::collations()`, `Schema\CollationChange`, `Schema\TextRuleConversion`, `Schema\TextDuplicates`, `MigrationRegistry::check()`.
  - **JSON record files have one format:** fields in the type's order, then system keys, then other keys; tags as a list; numbers as numbers; readable Unicode; a final newline. Saving an unchanged record gives the same bytes, whether Folio, the CLI or a script saves it. Files change on their next save only; there is no migration. Field kinds that store a list say so (`FieldKinds::list()`, `isList()`; Folio's `StoresList`).
  - **A save keeps what it does not mention, on every driver:** a save writes the fields it is given and leaves the others as stored; a field saved as null is stored as null. The JSON driver now merges under the type's lock (before, a JSON save replaced the file). DataManager saves class-backed types through one path, and nothing outside the drivers checks a driver's class any more.
  - **JSON cache:** a JSON storage entry in `config/data.php` takes `'cache' => false` for files that change outside the app (a `git pull`).
  - **Read-only and no-create types (data, Folio):** a model file's `"readonly": true` (records from files or code, such as an FAQ seeded on every deploy: listed and viewed only) or `"creatable": false` (an inbox: no create, still edited and deleted); `#[Entity(readonly: true)]`, `#[Entity(creatable: false)]`. The core decides (`TypeDefinition::allows(TypeAction)`, `assertAllows()` throws `TypeActionRefused`, a 403 that an API answers as JSON); adapters only render it. Code (seeders, migrations, `DataManager`) writes as before. Folio: no New, Save, Delete, Duplicate or bulk action where the type refuses it; a read-only record opens as "View …" with a badge and a note, its fields shown as content; a refused route answers Folio's 403 saying why, with or without sign-in (`TypeActionsMiddleware`, `Content\TypeActions`); the Roles matrix shows "–" for a refused verb (grants are kept); `folio_can()` for templates. A per-field `"readonly"` field shows as content in the main column (System fields only with `"panel": "side"`). Also: the editor's content-language switch wraps in its own row (14 languages no longer push a 375 px page sideways).
  - **Per-person limits for AI (ai, i18n, core, folio):** `config/ai.php` `limits` caps what each signed-in person starts in the admin: 30 drafts and 100 translated items (a record, a set of labels, an alt text) per hour by default; 0 turns a limit off. Over it, the draft and translate endpoints answer 429 with `Retry-After` and say in the dialog when to try again; a translation run waits for its person's limit, also in the cron worker. `Preflow\Ai\Limits\AiLimits` is the guard any admin UI calls; `Throttle::forPerson()`, `retryAfter()` and `hasKey()` are new. Work from the command line (`translate:plan`, `translate:work`) is not limited.
  - **Devtools:** the debug toolbar collapses to a small ⚡ Preflow tab at the bottom left (the `‹` button at the left end of the bar; click the tab to bring the bar back). The state is remembered per browser (`preflow.toolbar`), and the bar's `expand` is a real button. `db:check`, `db:vacuum`, `db:dump` and `db:restore` check, compact, dump and restore a SQLite database through Preflow's connection, where the `sqlite3` tool cannot.
  - **Old input after an expired session (core):** `config/csrf.php` `forget_input` (path prefixes) and `forget_fields` (field names) keep a form's fields out of the session when its session expired, for forms that promise to store nothing; the form comes back with the warning and empty fields.
  - **robots.txt (view):** `config/seo.php` `robots.default_group` puts lines (a `Crawl-delay`) into the `User-agent: *` group and `robots.all_groups` into every group; `robots.extra` still lands after the last group.
  - **Locale URLs (core, i18n, routing, view, folio, members):** what sites built around i18n themselves, now opt-in options; nothing changes until a site sets them.
    - `config/i18n.php` `prefix_default`: the default language is prefixed too (`/en/about`, home `/en`). `locale_url()`, the SEO tags, sitemap and llms.txt URLs (`/llms.txt` stays at the root), Folio's addresses, alternates, redirects and audit, and members' links follow it. Core's `Http\LocaleUrls` is the one rule (`prefix()`, `path()`), bound for every site before any provider registers. Without `redirect_bare` the bare copy still answers and names the prefixed address (`og:url`, JSON-LD). The locale middleware reads the prefix through it when the app passes it (`/de-ch/…` too); Folio's redirect paths and interface locales still expect two-letter codes.
    - `config/i18n.php` `redirect_bare` and `locale_switch_url()`: a GET of a bare page path (`/about`) answers 302 to the visitor's language: the remembered choice, then `Accept-Language`, then the default (`Vary: Accept-Language, Cookie`, `Cache-Control: private, no-store`). Only paths a page answers at: core's `Routing\PageExists` (`exists(locale, path)`), asked under the ids `Routing\PageLookups` lists (`add(string $id)`); routing's `Router` implements it and routing's `Redirect\PageExists` extends it; Folio adds its own lookup (`SiteTarget`) through `PageLookups`, so core's `PageExists` id stays free for the site. The boot stops when `redirect_bare` has nothing to answer it. Non-public and `--` paths, other methods and internal renders are never redirected. The language cookie (`cookie_name`, default `locale`, a valid cookie name) is written only by the switch endpoint `/--locale/{code}?to=…` when the visitor picks a language, never on a redirect or a page view. Default-language bare pages stay page-cached.
    - `config/routing.php` `canonical_paths`: GET/HEAD of `/faq/` or `//en//faq` answers 301 to `/faq` or `/en/faq`, keeping the query; non-public and `--` paths, internal renders and ambiguous paths (dot segments, backslashes, `%2F`) are left alone.
  - **CI:** the architecture guards run on every push; a new guard fails on driver class checks outside the drivers.
  - **Upgrading:**
    - Upgrade all `preflow/*` packages together.
    - Code that called `$driver->rawQuery()` on a `StorageDriver` checks `instanceof RunsSql` or calls `DataManager::raw()` (unchanged). `raw()` and `execute()` on storage without SQL throw `UnsupportedStorage` (a `LogicException`, as `execute()` threw before).
    - A third-party driver keeps working: its `rawQuery()` is now an extra method. A third-party contract test drops its `supportsRawQuery()` override; the raw SQL tests run for `RunsSql` drivers only.
    - A third-party contract test's `createType()` gets the field kind `exact` where it got `string` (an exact text column: ids, keys, codes), and the new `text` and `short_text` (text columns that follow the text rule; `short_text` bounded and indexed).
    - A third-party class that implements `DescribesColumns` adds `hasTable(string $type): bool`.
    - The JSON driver now implements `DescribesColumns`: code that took `instanceof DescribesColumns` to mean "SQL storage" asks for `RunsSql` instead.
    - Writes across storages (publishing, record deletes, the delete planner, the permission sync) now commit the innermost storage first. A commit that fails after another storage committed throws `PartialCommit` (a `RuntimeException`, the failure as previous) instead of the raw `PDOException`; Folio's importer wraps it as before.
    - A MySQL site that wants ranked ⌘K sets `fullText` on its types and runs `vendor/bin/preflow migrate`.
    - `Throttle`'s `lockDir` parameter is ignored (deprecated). Its count now reaches data observers as a record write; on JSON storage all throttle hits share the type's lock.
    - Throttle rows store when their window ends (`expires_at`, a new column core's migration adds: run `migrate`) and are deleted once it has: by the next hit that starts a window, by `tooMany()`/`retryAfter()`, or by the new `preflow throttle:prune` (cron, for a site that promises a retention). `prune()` without an argument now deletes ended rows (rows from before, without `expires_at`, still after two days); `Throttle::TABLE` and `definition()` are public.
    - Auth tokens' and remember-me's `revokeAll()` and `prune()` delete through `deleteManyIn()` and return the number of rows found.
    - A driver without locks now runs the unique check too (before the write, not atomic). `PdoDriver::findMany()` without limit or offset no longer runs a COUNT query.
    - JSON queries compare as SQL does: `=`, `!=`, `IN` and `NOT IN` match a number given as text and a yes/no value given as 1/0, and `!=` leaves out records whose field is empty (NULL).
    - On a JSON site, the first publish after the upgrade may list tag and number fields as changed once: their stored form changes on the next save. `updateFieldsIfVersion()` keeps a file's key order until its next full save.
    - A key leaves a JSON record only by deleting the record and saving it again (as on SQL, where a column stays); a property removed from a model class leaves its key in the files. A third-party driver's contract test now checks partial saves.
    - Class-backed saves (`#[Entity(type: …)]`) are normalised as `saveType()` saves are, on SQL too: a comma list in a list field is stored as JSON text, numeric text in a number field as a number, and (as before) `''` as NULL in number, yes/no, date and unique columns.
    - A JSON type folder gets a `.lock` file on its first save (every save and delete now takes the type's lock). Sites that keep JSON data in git ignore `**/.lock`.
    - A model file whose top level sets `"readonly"` or `"creatable"` to anything but `true`/`false` (they were ignored before) stops the boot naming the type and the option; so does a model class and its admin file setting one differently (also `fullText`: a file's `"fullText": false` under `#[Entity(fullText: true)]` was ignored). A type protected by marking every field `"readonly"` sets `"readonly": true` on the type instead.
    - Folio: a field marked `"readonly"` now shows as content in the editor's main column (it was listed under the side panel's System fields since 0.75). To keep metadata there (a received date, an IP address), add `"panel": "side"` to the field. Folio's own staff and code types do so for their sign-in dates and use counts. Extension templates that ask whether a type's record may be created, edited or deleted use `folio_can()` (the permission and what the type allows) instead of `can()`.
    - The convert tool (`tools/convert-typed-columns.php`) needs preflow/data 0.78 on the site.
    - Folio's tag field hands DataManager a list instead of JSON text; SQL columns hold the same text as before.
    - A site whose editors translate or draft in bulk raises `ai.limits.*.max` in `config/ai.php` (or sets 0). `Throttle::hit()` now returns the count (it returned nothing). preflow/i18n needs preflow/ai 0.78.
    - Text fields now compare by the text rule. Redirect, not-found and address paths are text, so `/Foo` and `/foo` are one redirect, one not-found entry and one address now; slugs and e-mail addresses ignore case too. A field holding a case-significant machine value (an API key, base64) takes `"exact": true` / `#[Field(exact: true)]`.
    - SQLite: code that opens its own PDO on the site's database calls `SqliteDialect::prepare($pdo)` (`Schema`, `Migrator` and `Grammars::for()` refuse a connection without the collation `preflow_text`; a third-party driver on SQLite calls it in `configure()`). The `sqlite3` command-line tool cannot filter, sort or write an indexed text column of a converted table, and it cannot restore a `.dump` of the database, `VACUUM` it or run `PRAGMA integrity_check` on it ("no such collation sequence"): use `preflow db:dump` / `db:restore`, `db:vacuum` and `db:check`, which run through Preflow's connection (a copy of the file, taken while the site writes nothing, stays a backup). Comparing by the rule costs a PHP call per comparison on SQLite (see the data README, "Exact comparisons").
    - MySQL/MariaDB: new tables take the rule's collation as their default; a migration's `json()` and timestamps are `utf8mb4_bin`. `ORDER BY` on a long text field (TEXT/LONGTEXT, or translatable) sorts by `WEIGHT_STRING()` (MariaDB sorts long text by its first-level weights only); text fields in `VARCHAR` columns sort by their column and keep their index (`MysqlDriver` reads the column types and collations from `information_schema`, once per table and request). A full-text type's tags share the rule's collation in the FULLTEXT index and still compare exactly in queries; `HAS` compares a list's members exactly (it ignored case through the connection's collation before). On MariaDB before 10.10 / MySQL before 8.0 text columns stay exact. `MysqlDialect::serverMatchCollation()` is gone (`MysqlGrammar::on($pdo)->textCollation()`); `MATCH_COLLATIONS` names the NO PAD collations.
    - Fields marked exact are no longer searched by loose search, ⌘K or full-text search, on any driver; list search and ⌘K find such a field only by its whole value.
    - A migration's `string()` and `text()` columns compare by the rule from now on; mark machine values `->exact()`. Tables made before keep their columns until `migrate` converts them.
    - preflow/data needs the PHP `intl` extension. Run `migrate --pretend`, then `migrate` (after a backup): it changes the collation of record-table and framework-table columns (MySQL: one `ALTER TABLE` per table, which blocks writes while it runs; SQLite: one rebuild per table), and on MySQL also sets each converted table's default collation to the rule's (metadata only), so a column added later compares by the rule; a text column of a Preflow table still on another collation afterwards is listed as a warning (`db:check` lists them too). A unique text column whose values would collide under the rule ("Müller"/"MÜLLER") is listed, keeps its collation and `migrate` exits 1; nothing is changed or removed. Rename one record of each group in Folio and run `migrate` again. On a JSON site `migrate` lists such pairs the same way and exits 1, and saving either record is refused until they differ. Tables a site's migrations made before 0.78 (and another application's) are left as they are and listed once: adopt one with a migration (`Schema::convertColumn()`), or list it in `config/data.php` `'text_rule' => ['skip' => [...]]`. Slug lookups now find "/About" for "about". A field holding a case-significant machine value (an API key) gets `"exact": true`. The `sqlite3` command-line tool cannot filter or write columns that use `preflow_text` (use the `preflow db:*` commands, above). Run `migrate` with the PHP binary the site serves with: SQLite orders its text indexes by that PHP's ICU and `migrate` records the version it saw, so a web server with another ICU can miss rows in an index lookup. MariaDB before 10.10 and MySQL before 8.0 keep their old comparison (`migrate` says so). A deploy script that treats a non-zero exit of `migrate` as a failure stops on listed duplicates until they are fixed.
    - A query on a field that does not exist fails on every driver: `QueryBuilder` (typed and model queries) throws `InvalidArgumentException` for a `where`/`orWhere`/`whereAny`/`orderBy` on a field the type or model class lacks (system keys with a leading `_` excepted), and SQLite now quotes identifiers with backticks, so a hand-built query on a missing column is an error ("no such column") instead of quietly matching nothing (SQLite read a double-quoted unknown name as a string). New SQLite tables' DDL shows backticks. Site config can name such fields, so before upgrading check that every name exists: a model file's `list.sort` (applied on every admin list), frontend route parameters, and unique-rule scopes. Find them with `grep -rn '"sort"' config/models/` and by reading `config/routes` and the `unique` scopes; a missing one now answers 500 on every driver (the message names the type and the field as unknown) where SQLite compared a string and JSON matched nothing.
    - A record's default title (no `titleField`) skips personal data: a field of type or `"format"` `email`, named `email`, `mail`, `e_mail`, `email_address`, `emailAddress` or ending in `_email`/`Email` (any case), with `"format": "email"` or an `email` rule, or marked `"personal": true`. A type whose first string field was an e-mail address now shows its next string field (or the id) in lists, search and new activity rows; name a `titleField` to choose.
    - Loose search finds an exact field's value that holds a space by the whole trimmed term ("AB 12").
    - `migrate` warns where a plain model's `#[Field(exact: true)]` and its migration's `->exact()` disagree (step `data.model-exactness`), and lists the older text columns of a pre-0.78 table a later migration extended; `migrate --pretend` also lists the unique indexes SQLite could not rebuild after an ICU change. Without rbac installed it says `[rbac.seed] rbac is not installed: run php preflow rbac:install …` (migrate does not install rbac: that switches `can()` to the roles).
    - Folio's member editor shows the sign-up, confirmation, decision, lock and last-sign-in dates and the language in the side panel; the member and staff models' `password_hash` and `auth_epoch` are exact.
    - A site with data storage whose AI can run (`ai.enabled` true, or a translator or draft writer of its own in `config/ai.php`) needs `APP_KEY` while an AI limit is on: the boot stops without it, naming `APP_KEY`. A draft writer or translator an app binds in a provider is limited too (`AiLimits` is bound whenever the Throttle is): without the key its first AI action is refused naming `APP_KEY`, never unlimited. Set the key (Folio's sign-in needs it anyway), or set `ai.limits.draft.max` and `ai.limits.translate.max` to 0. A site with AI off boots without a key as before.
    - On MySQL/MariaDB a search without accents no longer finds accented text ("uber" found "Über" through the tables' collation); 0.78.1 brings that back on every driver. On SQLite, every pattern with a printable character is matched by `ulike()`, since a fullwidth form folds into each (slower on large tables). A third-party dialect whose LIKE operator alone does not give the rule implements `PatternMatching`.
    - Locale URLs are opt-in; nothing changes until you set them. A site that did this itself can delete its middleware: set `prefix_default`/`redirect_bare` in `config/i18n.php`, `canonical_paths` in `config/routing.php`; point the language picker at `locale_switch_url()`; move path aliases (`/support` → `/faq`) into redirect entries. A site with catch-all pages of its own (not Folio's) binds `Preflow\Core\Routing\PageExists` before turning on `redirect_bare` (on a Folio site too: both are asked).
    - `prefix_default` and `redirect_bare` must be `true` or `false` and need `url_strategy` `prefix`; `cookie_name` must be a valid cookie name. A wrong value stops the boot.
    - Members' `Links` takes core's `LocaleUrls` as its fourth argument (its `$prefixed` flag is gone); code that builds `Links` itself passes `$container->get(LocaleUrls::class)`. preflow/members, routing, i18n, view and folio need core 0.78.
    - `SeoMiddleware` built by hand without `localeUrls:` keeps the old rule (deprecated); the application always passes it.
    - `SafeReturnPath` (sign-in `next`, the language switch's `to`) also refuses an encoded `/@` (`/%40host`) and a value still changing after five percent-decodes. Folio's `AuditTargets` takes no `defaultLocale` any more (optional, deprecated, removed in a later minor).
  - **Follow-ups:** multiple relations and assets still store their lists as JSON text on JSON storage.
- **Fixes from the 0.77 rollout (v0.77.1, released 2026-11-15):**
  - **`preflow migrate` on JSON-only sites.** Migrate used to stop before any step on a site without SQL storage, so `publishing.published_versions` never ran there. Now it runs the steps registered for any storage. `MigrationRegistry::afterMigrate()` takes `sqlOnly` (default `true`); `publishing.published_versions`, `rbac.seed`, `folio.permissions` and `folio.addresses` pass `sqlOnly: false`. Schema migrations and table syncs stay SQL-only.
  - **starter-folio's demo content.** Recent changes and All activity showed the seeded rows as "Former user". The starter now registers its `seed` marker with `ActivityActors` as "Demo content" (English, German, French). The starter without Folio writes no activity, so it needs nothing.
  - **Upgrading:**
    - devtools, publishing, rbac and folio need data 0.77.1; upgrade all `preflow/*` packages together.
    - JSON sites run `vendor/bin/preflow migrate` once (again) to mark their snapshots.
    - Projects made from starter-folio may copy `app/Support/SeedActor.php`, `resources/lang/*/demo.php` and the lines in `AppServiceProvider::boot()`.
    - A package's own post-migrate step that works through `DataManager` can pass `sqlOnly: false` to run on JSON sites.
- **Folio proposals and the tag field (v0.77.0, released 2026-11-15):** this release ships the approved handoff proposals, the tag field and the 0.76 follow-ups. Every feature rests on a framework API that works without Folio.
  - **Admin:**
    - **⌘K palette.** Search or jump to screens, actions, records of every type you may view, and assets. A "Search or jump to…" entry in the sidebar and the rail, and the chords `g d`, `g a`, `g p`.
    - **Block editing.** A "+" line between blocks inserts at that place; `/` opens the block picker.
    - **AI "Write a draft"** in rich text. Brief, length and preview, then Insert or Replace. The cost is shown. New permission `ai.draft`.
    - **Recent changes.** "Recent changes" on the dashboard and an "All activity" screen, with filters for type and person.
    - **Tag field.** A new field type, `tags`.
    - **Faster publish counts.** "Changes not live" counts no longer compare content.
    - **Editors.** Editors without autosave count changes per value. Late field scripts can say when they are ready (`data-folio-settling` / `folio:settled`).
  - **Framework APIs:**
    - **core:**
      - `ActivityLog`, `Activity` (with `$titleKey`), `ActivityKind`, `ActivityPage`, `ActivityActors` and `NullActivityLog`;
      - the shortcut registry takes chords and `quietInMenus`.
    - **data:**
      - records and search: `RecordTitle` with `titleField` (schema, `#[Entity(titleField:)]`), `RecordSearch`;
      - the activity log: `StoredActivityLog` (the `activity:prune` command, `config/activity.php`);
      - capabilities a driver can implement: `BulkDeletes`, `Savepoints`, `ConditionalUpdates` (`updateFieldsIfVersion`), `DescribesColumns`, `VersionFields` (with `nonContent()`). A driver-author checklist in the data README lists what each capability gives and what happens without it.
    - **publishing:**
      - `Publisher::stateCounts()`;
      - the `_published_version` snapshot key and the migrate step `publishing.published_versions`;
      - `PersonalTypes`, `PublishActors`.
    - **ai:** `DraftWriter`, `DraftRequest`, `DraftLength`, `Draft`, `ProjectTuning`.
    - **i18n:** `Machine\WritesAs`.
    - **media:** activity for uploads, replacements, edits and the bin.
  - **Same behaviour on every driver** (JSON, SQLite, MySQL):
    - `LIKE`, `CONTAINS` and `search()` ignore case in every alphabet and match inside lists;
    - SQL stores JSON with unescaped slashes;
    - shared contract tests in `preflow/testing` enforce this.
  - **Upgrading:**
    - **Packages.** Upgrade all `preflow/*` packages together (`composer update "preflow/*" -W`); the constraints refuse mixes.
    - **Migrate.** Run `vendor/bin/preflow migrate` on every site, JSON sites included. It creates `preflow_activity` and `preflow_activity_last` and adds `_published_version`, and the step `publishing.published_versions` marks existing snapshots (on JSON sites from 0.77.1 on). Before migrate runs:
      - activity writes are logged as warnings;
      - `recent()` reads empty;
      - publish counts fall back to the full compare.
    - **Nightly cron:** `php preflow activity:prune`. `members:prune` also prunes. `activity.keep_days` defaults to 90 and `activity.keep_rows` to 10 000; JSON sites should lower `keep_rows`.
    - **Permission `ai.draft`.** The admin role has it; grant it in Roles.
    - **Reserved names.** `asset`, `redirect` and `activity` are reserved as type keys and screen paths.
    - **Version numbers.** A save through DataManager bumps a publishing type's `_version` only when content changes, so app saves and imports that change content now read Changed. Raw SQL is out of reach. `states()`/`stateCounts()` compare versions; `state()` compares content.
    - **Class models.** An `array` property whose stored value is not a JSON list throws `UnreadableValue`; convert legacy comma strings and plain ids first. A `tags` field on a class model is `#[Field(type: 'tags')] public ?array $x`, with no transform.
    - **Empty lists** (tags, multiple relations, multiple assets) are stored as null, and `required` refuses an empty list.
    - **SQLite.** A pattern with non-ASCII characters runs a Unicode `like` function, which is slower than native `LIKE` and can't use an index. ASCII patterns use native `LIKE`; the Kelvin sign and the dotted capital I follow native rules there.
    - **Your own implementations:**
      - an `ActivityLog` implementation adds `recent(by:)`, `people()`, `prune()` and `forget()`;
      - a `VersionFields` implementation adds `nonContent()`;
      - sites that hold personal data in their own types bind `PersonalTypes`;
      - a third-party storage driver that runs `preflow/testing`'s `StorageDriverContract` must now implement the `LIKE`, `search()` and `whereAny()` `CONTAINS` behaviour to pass: case-insensitive matching in every alphabet (`test_like_ignores_case_in_every_alphabet`) and search inside list values (`test_search_finds_a_term_in_text_and_in_list_values`).
    - **Translation jobs.** `queued_by` holds the user id; older jobs keep a name.
  - **Next:**
    - the private harness: principles, decisions and the integrator for parallel work;
    - the storage-contract release: RankedSearch; `rawQuery` as an optional SQL capability; one matching contract (accents); one rule for keys a save leaves out; a core rate limiter;
    - then the render-context release.
- **Folio redesign, part 3: settings, library, dashboard and ink (v0.76.0, released 2026-11-14):** the rest of Folio
  gets the redesign (plan `docs/superpowers/plans/2026-11-14-release-0.76-folio-redesign-settings-library-ink.md`,
  differences from the boards in `docs/superpowers/notes/2026-11-13-folio-redesign-deviations.md`).
  - **Ink layer:** the editing marks live in `icons.svg` (`mark-*`), with `.folio-ink*` classes and `folio_ink_marks(seed,
    count, area, aspect)` (deterministic placement); ink empty states; an admin "not found" page and the no-access
    page as ink cards; a "Skip to content" link on the sidebar and rail screens. The sign-in shares the classes.
  - **Dashboard:** ink band with the site's counts, "Needs you" (new permissions, changes not live, not-found
    addresses, translations to review; loaded after the page), content by sidebar section with quick create, the
    cache line (loaded after the page). Counts use aggregates. Extensions add cards with `#[DashboardItem]` → `NeedsYou`.
  - **Assets:** cards with "used N×", kind segmented, "Missing alt text · N", grid/list toggle, drop zone, selection
    bar (Edit alt text, Download for one file, Move to bin). **Asset detail:** large image with focal point and crop
    previews (16:9, 4:3, 1:1, 9:16), file facts, colours to copy, "Used in" by type, replace by drop, Versions tab.
  - **Translations:** inline editing, changed rows with reset, "Changed only · N", sticky save-all bar; AI translate
    follows the filter. **Redirects:** "from → to" table, not-found badge, drawer form (A page / An address, 301/302
    cards, any language), restyled removal dialog. **Cache:** total with stacked bar, size bars, page cache and OPcache
    cards. **Roles:** matrix by section with type tiles, row switches, column toggles, "Neu" and "Nur neue (N)";
    exact grants only (owner).
  - **Editors without autosave** show "N unsaved changes" and Cancel; a refused save says "Not saved"; drawers ask
    before closing with unsaved changes.
  - **Framework APIs (usable without Folio):** media `AssetUsage::counts()`, `CountsAssetUsage`, `AssetUsageEntry::$type`,
    `MediaLibrary::query(missingAlt:)`, `missingAltCount()`, `posterCounts()`, `displayUrl()`; data `Query::localize()`
    with the type's locales (JSON storage reads a map without the locale as null, as SQL does); i18n
    `OverrideRules::placeholderParts()`.
  - **Upgrading:**
    - Upgrade data, i18n, media and folio together (`composer update "preflow/*" -W`); the constraints refuse mixes.
    - `NewPermissions::dashboard()` returns `?NeedsYou`; `@rbac/new-permissions-dashboard.twig` is removed (Roles uses
      `#[DashboardItem]`).
    - Overrides: `dashboard.twig` (no `.folio-card-grid`; htmx always loaded), `assets/*` (`panel.twig` no longer gets
      `versions`: earlier versions are on the Versions tab), `cache.twig`, `translations/index.twig`, `redirects/*`
      follow the new structure.
    - An `AssetReferenceSource` may implement `CountsAssetUsage::usageCounts($ids, $nonBlocking)` for fast library
      pages; library cards count blocking usages only.
    - Editors without autosave get the unsaved state and Cancel: fields that change values by script announce it with
      a bubbling `change`; fields with derived visible state listen for `folio:discard`; own guarded forms carry
      `data-folio-guard`.
    - A select's empty-key placeholder option no longer counts toward the four-option segmented rule.
    - New `folio.*` keys: check translation overrides.
  - **Next:** 0.77 the approved proposals (⌘K palette, `/` block picker and insert line, AI "Write a draft", recent
    changes) and the tag field; then the render-context release. Follow-ups: publishing "published from" version for
    cheap state counts; usage counts on non-SQL storage; roles unsaved count per changed value.
- **Folio redesign, part 2: lists and editors (v0.75.0, released 2026-11-14):** lists, the record editor, blocks,
  the preview and every field type get the redesign; native confirm and alert boxes are gone (plan
  `docs/superpowers/plans/2026-11-13-release-0.75-folio-redesign-lists-editors.md`, differences from the boards in
  `docs/superpowers/notes/2026-11-13-folio-redesign-deviations.md`). Inter and JetBrains Mono ship with Folio (OFL).
  - **Lists:** family-tinted type tiles, language state per row (complete, partial, missing), a "…" row menu (Edit,
    Duplicate, Copy preview link, Unpublish, Delete), bulk bar, phone filter sheet, keyboard (`/` search, `n` new,
    `j`/`k`, `e`/Enter, `x` select, `.` menu, `d` duplicate, `?` help). Copies read "Title (copy)".
  - **Editor:** topbar with save state, Save draft and Publish (Schedule when a date is set; ⌘S, ⌘↵), language switch
    with completeness, Content / SEO & sharing tabs, a side panel (status and "N changes", address, languages, search
    result preview, revisions, system fields, Unpublish/Delete; a sheet on phones), fields with `"panel": "side"` and
    `"width": "half"`.
  - **Blocks and preview:** block cards with family icon, summary, menu and collapse; "Add block" with search and
    keyboard; preview bar (devices, address with draft dot, copy preview link, open live); the selected block stays
    outlined in the preview.
  - **Fields:** address prefix and copy, counters, steppers with `"unit"`, date hint ("In 2 days · Europe/Zurich"),
    switch (`"display": "switch"`), segmented (≤ 4 required options) and radio cards (`"display": "cards"`), relation
    rows with search and meta line, asset cards with drop upload and an alt-text warning, matrix rows, env set/not set.
  - **Dialogs:** one styled `<dialog>` for every confirm and alert; leaving an autosaving editor saves first and asks
    only when that fails; other dirty forms get Leave / Stay / Save and leave. The native prompt stays only for tab
    close, reload and typed addresses.
  - **Framework APIs (usable without Folio):** data `TypeFamily` and `"family"`, `Relation::metaField`,
    `ModelLabelKeys::optionHelp()`/`isDescribedOption()`, `Schema::convertColumn()`; blocks `#[Block(family:)]`; i18n
    `Completeness`; publishing `Publisher::changes()`, `RecordWriter::duplicate()` (with overrides) and
    `isSystemKey()`, `RecordAddressFields`; core `Version` (installed package versions; Folio shows it in the user
    menu).
  - **Internal renders (core):** an internal render (SEO audit, GEO, page-cache rebuild and verify) keeps the request's
    queued cookies, starts in the site's locale with translation overrides off, and the page cache's verify render no
    longer sends mail. First steps of the render-context work (`docs/superpowers/research/` inventory).
  - **Upgrading:**
    - Upgrade core, data, blocks, form, i18n, publishing and folio together (`composer update "preflow/*" -W`); the
      constraints refuse mixes.
    - An overridden `form.twig` must keep `id="{{ record_form }}"` (`folio-record`) on the record form and render the
      new `topbar_lead` and `actions` blocks and `partials/_side_panel.twig`. Address fields (the slug) and side fields
      now carry `form="folio-record"`: an old override that renders them inside another form silently stops posting
      them.
    - An overridden `_list_results.twig`: row Edit/trash become the "…" menu (`row.menu`); `row.languages[]` gain
      `state`; the bulk form is `#folio-bulk` with `form="folio-bulk"` tick boxes.
    - Removed classes: `.folio-form-actions`, `.folio-publish-at`, `.folio-block-actions`; the markup of block cards and
      of the relation, asset, matrix, env, boolean, select, number and address field editors changed. Site CSS or JS
      aimed at them needs a look.
    - Scripts: `fields.js`, `blocks.js` and `editor-frame.js` load after `admin.js`. Extensions that add side-panel
      controls call `Folio.ownControls(node)`; helper inputs inside the editor carry `data-folio-no-edit`; use
      `folioConfirm()`/`folioAlert()` instead of `confirm()`/`alert()`.
    - Required selects with up to 4 options show as segmented controls; force a select with `"display": "select"`.
    - A custom `RecordTypes` binding should implement `RecordAddressFields`, or copies keep the original's address.
    - Model schemas: a top-level `"family"` and the field options `"panel"`, `"width"`, `"unit"`, `"display"` are
      validated at boot (an unknown value is a boot error).
    - form's `FieldRenderer` writes a newline after `<textarea>`: tests asserting `>value</textarea>` need it.
    - The asset upload and share routes answer JSON on `Accept: application/json`.
    - data: `SchemaGrammar` has a new method `convertColumn()` (third-party grammars implement it).
    - Duplicate needs view and create on the type. New `folio.*` keys: check translation overrides.
  - **Next:** 0.76 settings and library screens, the dashboard and the ink layer; then the approved proposals (⌘K,
    `/` block picker, crop previews, missing-alt filter, AI "Write a draft", recent changes) and the tag field; then the
    render context release.
- **Run the SEO audit from Folio (v0.74.0, released 2026-11-13):** Settings → SEO audit runs the audit itself: Run
  audit, Continue and Start over, in steps of about 20 s while the page is open, with progress ("3 of 4 pages") until the
  report is complete (plan `docs/superpowers/plans/2026-11-13-release-0.74-seo-audit-from-folio.md`).
  - **view:** `Preflow\View\Audit\Audits` (container singleton): `step()`, `quick()`, `report()`, `running()`, `file()`;
    `seo:audit` uses it. A starting run retries the lock briefly (`AuditRun::lockFile()`, optional `$sleep`).
  - **core / notify:** every internal render (SEO audit, GEO `.md` sub-requests, page-cache rebuilds) drops mail queued
    with `sendLater()` (`Notifier::discardingQueued()`), and the audit's renders run with anonymous PHP globals.
  - **folio:** permission `seo.audit.run`; POST `/seo-audit/run` and `/seo-audit/step` (htmx self-polling, plain POST
    without JS); "running elsewhere" with Try again; failed steps show a generic message (details in the log);
    audit renders read the published site, not the admin's working copies; `Http\LongRequest::allow()` shared with AI
    translate.
  - **Upgrading:**
    - Upgrade core, view, notify and folio together (`composer update "preflow/*" -W`); the constraints refuse mixes.
    - Roles with only `seo.audit` keep the read-only screen; grant `seo.audit.run` in Settings → Roles where needed
      (the `admin` role has it). Without Folio auth anyone who can open Folio can run it.
    - Mail queued with `sendLater()` while a page renders internally is now dropped, not sent (also `defer => false`;
      onFailure is not called). `send()` still sends at once. A site that queues mail during a render (visit
      notifications, error alerts) gets none from GEO, rebuild or audit renders: use `send()` or a logger.
    - Web steps close the session, ignore client aborts and raise a time limit below 60 s to 60. `storage/data/` (report
      and lock) must be writable by the web user. On LiteSpeed consider `SetEnv noabort 1`. Pages too slow for a web
      request need `preflow seo:audit`.
    - `folio.audit.intro` changed and `folio.audit.*` keys were added: check translation overrides.
- **Folio redesign, part 1 (v0.73.0, released 2026-11-13):** the admin gets the redesign's tokens, primitives and
  shell (handoff phases 0–3; plan `docs/superpowers/plans/2026-11-13-release-0.73-folio-redesign-foundation.md`, every
  difference from the boards in `docs/superpowers/notes/2026-11-13-folio-redesign-deviations.md`). Folio only; no PHP
  API changes apart from the `Badge` tones `scheduled` and `failed`.
  - **Tokens:** one `light-dark()` set from the handoff; names kept, values new (dark theme green-black). Type stacks
    name Inter and JetBrains Mono with system fallbacks (no font files yet). TokensTest guards every `var()` and the
    text contrast in both themes.
  - **Primitives:** buttons (34 px, filled danger), segmented controls, one `.folio-tabs`, status badges, chips, counts,
    alerts and notices with glyphs, toast with an undo countdown, `.folio-menu`, drawer, a solid keyboard focus ring
    (`--focus-ring`), busy buttons (`data-busy-label`: spinner and "Publishing…" while the request runs; plain submits
    stay busy until the page unloads, htmx ones are released after their request or 45 s).
  - **Shell:** a 248 px sidebar with the site's ink tile, name and environment line ("Folio · staging"; just "Folio" in
    production), a user menu (account, theme, language, log out), a topbar with breadcrumb, title and a subtitle that
    replaces the help banners, and a 56 px icon rail on editor pages (record and asset edit), expandable for the session.
    Phone width: no page scrolls sideways.
  - **Upgrading** (sites with their own Folio CSS, templates or JS):
    - Token names are kept, values changed. `--c-focus-ring` is only a soft halo now: a site that uses
      `0 0 0 3px var(--c-focus-ring)` as its only focus cue switches to `var(--focus-ring)`. `--c-inverse*` flip in dark.
      The sidebar is 248 px (was 240).
    - `.folio-asset-tabs` → `.folio-tabs` (no alias). `.folio-user-link` is gone (the user menu in
      `partials/_user_menu.twig` replaces the sidebar foot). `.btn-danger` is filled; `data-tone="danger"` gives an
      outlined one.
    - `.folio-notice` is info (blue) by default now; add `.is-warning` (or `role="alert"`) for the amber one, also for
      `#[ListNotice]`/`#[DashboardNotice]` output. `.folio-alert` without a tone is info too.
    - `_layout.twig`: new `{% block subtitle %}` (a site overriding the layout must print it, or the intros vanish);
      `{% set shell = 'rail' %}` picks the rail; new Twig global `folio_site`. Nav items without a section sit in
      `.folio-nav-items`. Templates that extend `_layout.twig` and use `title`, `page_title`, `breadcrumb`, `actions`,
      `content` need no change.
    - JS: the theme switch reacts to `[data-theme-choice]` inside `[data-theme-switch]`.
    - `composer update "preflow/*"`.
  - **Next:** ~~0.74 runs the SEO audit from Folio~~ done; the redesign continues with lists and editors (0.75) and settings,
    library screens and ink (0.76). Proposals (⌘K, styled confirm, `/` block picker, …) wait for a yes.
- **Manifest with shared middleware (v0.72.1, released 2026-11-13):** `preflow optimize` refused to write the boot
  manifest when one `MiddlewareWith` sat on a controller class (its routes share the object), so a starter made from 0.72.0
  could not be optimized for production. The manifest's load-back check compares the written form now. **Upgrading:**
  `composer update "preflow/*"`.
- **Gaps the starters filled, now in the framework (v0.72.0, released 2026-11-13):** sixth and last release of the
  package audit's fixes (spec D9; plan `docs/superpowers/plans/2026-11-13-release-0.72-gaps.md`).
  - **Indexes (data):** a declared index (`#[Field(index: true)]`, `"index": true`) is made on existing tables too, on
    every `preflow migrate`, also on the `__published` tables of publishing types (field and composite indexes, unique
    fields and their scopes as plain indexes; `__revisions` get none). An index counts as present by its name or by an
    index over the same columns (a unique one only by a unique one), so hand-made indexes under other names are kept and
    not duplicated, by migrate and by `tools/convert-typed-columns.php`. A late unique index is added only when the
    column holds no value twice (the warning counts them). Composite indexes: `#[Preflow\Data\Attributes\Index('state',
    'updated_at')]` (repeatable) or `"indexes": [["state", "updated_at"]]`, named `idx_<table>_<f1>_<f2>…`; refused
    when the type is defined: unknown, duplicated or translatable fields, an index over a unique field alone, two indexes
    with one name. MySQL: an index over an existing TEXT column gets a prefix; one longer than 3072 bytes is reported, not
    made. An index over free text (text, blocks, multiple relation) is not made and migrate warns.
  - **SEO description (view, twig, blade):** with SEO on, `<head>` gets `<meta name="description">` (the page's, else
    the site's; cleaned and cut like `og:description`) unless the layout prints one; not on noindex or private pages.
    `seo_description()` (Twig) / `@seo_description` (Blade) give the same text, uncut.
  - **Unique slugs (data):** `Preflow\Data\Slugs::unique($data, $typeOrClass, $text, field: 'slug', exceptId: null,
    max: 80, fallback: 'item')` and `Slugs::among($text, &$taken)` on `Slug::clean()` (`Zürich` → `zuerich`).
  - **Middleware arguments (routing, core):** `#[Middleware(new MiddlewareWith(AuthMiddleware::class, guard:
    'employers'))]`, also in `#[Route(middleware: [...])]`; arguments are named constructor parameters, type-checked
    when routes are scanned, kept by the route cache and boot manifest. Core: `ConfiguredMiddleware`;
    `Container::make($id, $params)` hands the parameters through class bindings and to factories that take them.
  - **Safe return paths (core, members):** `Preflow\Core\Http\SafeReturnPath::check(?string $next, list<string>
    $allowedPrefixes): ?string` (a path, never a URL; no `..`, `//`, `\`, control characters, also percent-encoded;
    prefixes match at a segment boundary); members' `SafeNext` uses it.
  - **Starters:** their own `Slugs`, `EmployerAuth`, `safeNext()`, `page_description()` and the `app.indexes` step are
    gone; the layouts let SEO print the description.
  - **Removed:** the 0.71 aliases (`Preflow\Folio\Publishing\*`, `Preflow\Folio\Content\LiveContent`,
    `Preflow\Folio\Content\RecordDeleter`), the commands `folio:publish`/`folio:publish-scheduled`, the
    `config/folio.php` `publishing` fallback; `Publisher` and `RecordDeleter` are `final` again; the old
    `Preflow\Folio\Users\RecordUserProvider` name and the `Preflow\Folio\Assets\*` aliases.
  - **Behaviour changes:** the first `preflow migrate` may add indexes to existing tables (it prints them; plan for big
    tables). Pages whose layout printed no description get one from SEO. `Container::make()` with parameters on an id
    bound to a factory that takes no parameters throws `ContainerException` instead of dropping them. A field in a
    composite index or a unique scope is stored as Short (VARCHAR(191) on MySQL, MaxChars 191).
  - **Upgrading:** raise all `preflow/*` to `^0.72`; `composer update "preflow/*"`; `preflow migrate` and read its
    output. Replace the removed names (see 0.71's Upgrading); cron lines say `publish:scheduled`. Hand-made index code
    can go once the indexes are declared. Own slug helpers, guard wrappers and "next" checks can use `Slugs`,
    `MiddlewareWith` and `SafeReturnPath`. A custom `SchemaGrammar` implements `uniqueIndexes()` and the new
    `createRecordIndex()` parameter; an app factory binding for a class the framework builds with parameters takes
    `(Container $c, array $parameters = [])`.
- **Starter pins (v0.71.1, released 2026-11-13):** both starters still required `preflow/i18n` `^0.70`, which Folio 0.71
  refuses, so `create-project preflow/starter-folio` could not install. Both pin `^0.71`; a starter test now checks that
  every `preflow/*` requirement names the same release. **Upgrading:** projects made from a 0.71.0 starter set
  `preflow/i18n` to `^0.71`.
- **preflow/publishing and one record write path (v0.71.0, released 2026-11-13):** fifth release of the package
  audit's fixes (spec D2, D3; plan `docs/superpowers/plans/2026-11-13-release-0.71-publishing.md`).
  - **New package `preflow/publishing`** (`Preflow\Publishing\*`): Publisher, LiveContent, PublishedModels,
    PublishedCopies, ShadowTypes, Revision, Scheduler, VersionConflict, PreviewLinks, PreviewTarget, PublishState;
    `config/publishing.php` (`revisions`, `complete_languages`, `preview_ttl`); commands `publish` and
    `publish:scheduled`; the migrate step `publishing.tables` (working, `__published` and `__revisions` tables of
    publishing types). What it needs from outside is an interface with a plain default: `PublishingTypes` (default: the
    type's `"publishing": true`), `RecordChecks`, `LanguageChecks`, `RecordIndex`, `RecordAddresses`, `RemovalTarget`,
    `RecordColumns`, `PublishSkips`. Folio binds its own (catalog default, validator, translation status, review index,
    redirects and page cache, `frontend.published` gate).
  - **One record write path:** `RecordWriter` (`create`, `save`, `setField`, `reorder`, `restore`, `discard`;
    `SaveOptions(by, mayPublish, publish, loadedVersion, publishAt, kind)`, `SaveOutcome`, `PublishFailed` carrying the
    stored draft) and `RecordDeleter` (`plan`, `delete`, `DeleteRefused`), with `SaveHooks` and `RecordTypes`. Hooks
    learn the kind of write (`WriteKind`: Save, Autosave, Bulk, Reorder, Translate, Detach); a Folio `#[BeforeSave]`/
    `#[AfterSave]` method may take it as a second parameter. The rule "a save by someone who may not publish cancels a
    pending schedule" exists once. Folio's form, autosave, restore, discard, bulk set, reorder, delete, the
    translation confirm, AI translation, "Add to role" and member deletion all write through them; starter-folio's
    JobWriter too.
  - **Also:** `RedirectRules::leadingTo()`; i18n `TextSources::add()` takes an "offered" predicate (types with nothing
    translatable are not offered; a planned job whose collection is gone stops with a message); `Extension::seed():
    array` is typed.
  - **Removed (0.70 aliases):** `Preflow\Folio\Block\*`, `folio_blocks()`, `extra.folio.blocks`, `config/folio.php`
    `blocks` (Folio's `cache_path` no longer places `blocks.cache`: set `config/blocks.php` `cache_path`), the cache key
    rename `folio.blocks`; `BlocksConfig::fromApp()` lost `$fallback`, `BlocksServiceProvider::packageClasses()` lost
    `$key`; `PublishState::label()`.
  - **Behaviour changes:** save hooks (`#[BeforeSave]`/`#[AfterSave]`, and `#[SaveRecord]` for types that do not
    publish) now also run on autosave, reorder, bulk set, the translation confirm's API branch, AI translation, "Add to
    role" and the records detached by a delete (a refusal during a member's own deletion is logged, the deletion
    continues). AI translation and the confirm API branch validate live types fully (a refusal is Skipped / 422).
    Restore and discard clear the type's fields, translation keys and `RecordColumns` the revision lacks; restore and
    discard apply the schedule rule and run no hooks. Reorder writes nothing at the edge; unknown ids answer 404.
  - **Upgrading:** raise all `preflow/*` to `^0.71` and add `preflow/publishing`; `composer update "preflow/*"`;
    `preflow migrate`. Until 0.72 the old names keep working: `Preflow\Folio\Publishing\*` and
    `Preflow\Folio\Content\LiveContent` → `Preflow\Publishing\*` (the old Publisher still takes `(DataManager,
    TypeCatalog, RecordValidator)`), `Preflow\Folio\Content\RecordDeleter` → `Preflow\Publishing\RecordDeleter`
    (texts in Folio's `DeleteSummary`), `folio:publish`/`folio:publish-scheduled` → `publish`/`publish:scheduled` (move
    the cron line), `config/folio.php` `publishing` → `config/publishing.php` (logged as deprecated on every boot until
    moved). Breaking now: `new LiveContent($dm, $catalog)` and `new Scheduler($p, $catalog, $dm)` take
    `PublishingTypes`; a class implementing `SaveHooks` adds the `WriteKind` parameter; an AfterSave hook that must not
    act on autosave checks `WriteKind::Autosave`. The scheduler lock is `storage/locks/publish-scheduled.lock`.
- **preflow/blocks, and the remaining rules out of Folio (v0.70.0, released 2026-11-12):** fourth release of the package
  audit's fixes (spec D1, D7; plan `docs/superpowers/plans/2026-11-12-release-0.70-blocks.md`).
  - **New package `preflow/blocks`** (`Preflow\Blocks\*`): Block, BlockComponent, BlockField, BlockDefinition,
    BlockRegistry, BlockRenderer, discovery (app/Components, app/Blocks, composer `extra.preflow.blocks`,
    `config/blocks.php` `classes`, `max_depth`, `cache_path`), the template function `blocks()` (Twig and Blade),
    interfaces `BlockLocale`, `BlockTextCleaner` (default: values as stored), `BlockPreviewMarks`. Folio keeps the
    blocks field, the editor and preview, and binds its own locale, rich-text cleaning and preview marks. Blocks deeper
    than `max_depth` render as before and log a note. Cache key `blocks` (`cache:clear folio.blocks` still works with a
    note until 0.71).
  - **routing:** `RedirectRules` (no chains, page wins, area rules, target checks, live record targets), `RedirectUrl`,
    `ListedRedirectStore`, `PageExists`, `TargetIsLive`, `SiteAddresses`, `NotFoundList`; every `save()` of Folio's
    redirect store is checked (`storeUnchecked()` is Folio's internal bookkeeping).
  - **i18n:** `OverrideRules`/`OverrideScope`/`OverrideRefused`: offered locale, editable key, length, plain text,
    placeholders — for Folio's form, the store's `set()` and translation imports. `translate:plan <collection>
    --locales=… [--scope] [--ids] [--note] [--start] [--confirm-live]` plans a job over any text source.
  - **media:** asset alt texts are the text source `media_alt` (`MediaLibrary::setAltIfUnchanged()` writes them
    compare-and-set); Folio's alt-text button uses it.
  - **Folio:** `Extension::seed()` returns the lines to print (untyped until 0.71, then `: array`).
  - **Removed:** the 0.69 aliases (`Preflow\Rbac\Extension`, `Fields\*`, `Types\*`, `NewPermissions*`), the
    `folio:staff:import-config` alias, `StaffMailer::siteName()`.
  - **Upgrading:** raise all `preflow/*` to `^0.70`, `composer update "preflow/*"`. Old names keep working until 0.71
    without a runtime note: `Preflow\Folio\Block\*` → `Preflow\Blocks\*`, `folio_blocks()` → `blocks()`,
    `extra.folio.blocks` → `extra.preflow.blocks`, `config/folio.php` `blocks`/`cache_path` → `config/blocks.php`,
    cache key `folio.blocks` → `blocks`; a site extension's `seed(): void` → `seed(Container $c): array`. Code using the
    removed rbac aliases moves to `Preflow\Folio\Rbac\*`. Removed statics: `StoredOverrides::validText/validLocale/
    MAX_LENGTH`, `EditableTexts::placeholdersIn`, `TranslationsController::matches/PER_PAGE`. Redirect store writes and
    translation imports now refuse what the admin form refuses.
- **Found by the 0.69 docs review (v0.69.2, released 2026-11-12):** `StaffAccounts::passwordMin()` and
  `checkPassword()` (the configured `password_min`, checkable before a set-password link is used up; `setPassword()`
  uses the same check). Permissions in `config/roles.php` that nothing declares are printed once by `rbac:install`,
  `preflow migrate` and `folio:extensions:apply`, and logged through the bound PSR-3 logger (no `error_log` echo).
  **Upgrading:** `composer update "preflow/*"`; nothing else.
- **Gaps found by the 0.69 docs (v0.69.1, released 2026-11-12):** core gains `Application::onProvidersBooted(callable)`
  (runs once every provider has booted); Folio sets up its admin in the console through it, so staff commands find the
  staff guard's record provider even with Folio's `model:staff` part off. Staff commands before the staff table exists
  answer "No staff table yet: …" (exit 1) instead of a trace. Seeding from `config/roles.php` reports each permission
  nothing declares (still granted); `rbac:role --allow-undeclared` grants one deliberately. `folio:extensions:apply`
  works without a `config/models/` directory. **Upgrading:** `composer update "preflow/*"`; Folio requires core ^0.69.1.
- **rbac without Folio (v0.69.0, released 2026-11-12):** third release of the package audit's fixes (spec D4; plan
  `docs/superpowers/plans/2026-11-12-release-0.69-rbac.md`).
  - **auth:** `Preflow\Auth\Record\AccountLinks` — password links for record users (issue, consume, setPassword,
    revoke, verify); Folio's `StaffPasswords` and rbac's `StaffAccounts` share one bound instance.
  - **rbac core, no Folio:** stored roles (`RoleStore::installed()` = the `rbac_role` table exists; `install()`,
    `seed()` from `config/roles.php`; `preflow migrate` seeds where rbac is installed), `RbacResolver` without Folio
    state, `StaffAccounts` (create, update, invite, resetLink, setPassword, disable, enable, all; the delegation,
    lock-out and unique-email rules; `StaffRefused`), invite/reset mails on notify (`rbac-mail.*`), `StaffLinks`
    (Folio binds its set-password page). Commands: `rbac:install`, `rbac:roles`, `rbac:role`, `rbac:role:delete`,
    `staff:list`, `staff:create`, `staff:password`, `staff:invite`, `staff:reset`, `staff:disable`, `staff:enable`,
    `staff:import-config` (`folio:staff:import-config` kept as an alias until 0.70). rbac requires no Folio.
  - **Folio:** carries rbac's admin as the built-in extension `rbac` (`Preflow\Folio\Rbac\*`, admin texts in the
    new group `rbac-admin`); old class names (`Preflow\Rbac\Extension`, `Fields\*`, `Types\*`, `NewPermissions*`)
    are aliases until 0.70. New hook from 0.68 unchanged.
  - **starter-folio:** staff and roles from preflow/rbac (no own Staff model, migrations or staff:create).
  - **Upgrading:** raise all `preflow/*` to `^0.69` and `composer update "preflow/*"`; deploys that run `migrate`
    and `folio:extensions:apply` need nothing else. With rbac installed, staff invite/reset mails use `rbac-mail.*`,
    and rbac's admin texts moved to `rbac-admin.*`: move overrides of `folio.mail.invite|reset.*` and of those keys
    (and `*_without_site` texts are new). "Installed" now means the `rbac_role` table exists, not Folio's extension
    state. `Preflow\Rbac\Types\StaffRules`'s constructor changed (resolve it from the container). `new RbacResolver($store)` takes only the role store (the Folio `ExtensionState` argument is gone). Forgot-password
    failures log `rbac:`. Constraints: folio and rbac conflict each other <0.69; both require auth and notify ^0.69;
    rbac requires data ^0.69.
- **Members on a fresh Folio site (v0.68.1, released 2026-11-12):** found by the 0.68 docs. Folio offered
  `@folio/frontend/_site.twig` as the members layout unconditionally, but Folio ships no such template (sites add
  `resources/folio/frontend/_site.twig`), so `/members/register` answered 500 on a fresh starter-folio with
  preflow/members. Now `MembersDefaults` carries the layout as a candidate and `MembersConfig::layout($engine)` uses
  it only when the engine finds it at render time, else `@members/_layout.twig`; a `layout` in config/members.php
  still wins; no provider order involved. **Upgrading:** `composer update "preflow/*"`; nothing else.
- **Mail in notify, neutral members, codes without a mailer (v0.68.0, released 2026-11-12):** second release of the
  package audit's fixes (spec D5, D6, D7; plan `docs/superpowers/plans/2026-11-12-release-0.68-mail-members-codes.md`).
  - **notify:** composed mails — `Preflow\Notify\Mail\Mailer` (`compose/send/sendLater(MailContent, to, from, template, accent)`),
    `MailContent(subject, text, lang, ?MailAction, vars, ?links)` (`links` lists the only URLs linked; null links all),
    `MailText::oneLine/paragraphs/absoluteUrl`, one neutral layout (`BasicMailLayout`, `@notify/mail/layout.twig` for
    Twig apps), `config/notify.php` `mail` => `accent` (default `#1a1a1a`), `layout`, `from`. Core binds them with
    `config/notify.php`; the Mailer resolves the Notifier at each send.
  - **members:** mails go through notify; no mail names Folio; `mail.layout`/`mail.accent` default to notify's (no site
    red); owner mails use `{decision_url}` ("Decide here: …"; `{folio_url}` is gone), or, without a decision page,
    `{decide_command}` (the address shell-quoted). `Members::accept()/decline()` (+ `canAccept/canDecline`,
    `DecisionRefused`) hold the rules for Folio and `members:decide` alike (`--message=`; a declined member can be
    accepted; decline ends remember-me sessions). Page CSS sits in `{% block members_styles %}` with neutral
    `--members-*` styles (dark mode follows the page's `color-scheme`); `members-admin` texts ship with Folio;
    `Links::folioMember()` removed. `Preflow\Data\TypeLabels` names types in the refusal mail (Folio binds it).
  - **codes:** no `CodeMailer` (build the mail on notify, see the codes README); `Codes::checkReadable()`, `prepare()`,
    `checkUpdate()`, `update()` (guarded inside the write), `CodeRefused`; Folio's code admin uses them.
  - **Folio:** new hook `#[SaveRecord]` (a type without publishing writes its own record; AfterSave still runs);
    staff mails through notify, without the word Folio; `Labels::resolve()` and `DisplayLabels::typeIn()` take a locale.
  - **media:** `VariantController::PENDING_HEADER` removed (use `Preflow\View\VariantPending::HEADER`).
  - **Upgrading:** `composer update "preflow/*"`. A site relying on the old member page look copies the v0.67.0 CSS
    of `_card.twig`, `register.twig` and `account.twig` into its overrides' `{% block members_styles %}`; a site wanting
    the old red sets `members.mail.accent`. Overrides of `mail.notice.body`/`mail.deletion_refused.body` use
    `{decision_url}`. Code calling `CodeMailer` builds its mail on `Mailer`. Constraints: folio requires core, data,
    i18n, notify ^0.68 and conflicts codes, members <0.68; members requires core, notify ^0.68 and conflicts folio <0.68;
    notify conflicts core <0.68.
- **Boundary clean-ups (v0.67.0, released 2026-11-12):** first release of the package audit's fixes (spec
  `docs/superpowers/specs/2026-11-12-package-audit-fixes-design.md`, D8; plan `docs/superpowers/plans/2026-11-12-release-0.67-cleanups.md`).
  No package names another package's composer keys, files or classes, and no package carries a site's wording.
  - **core:** `InstalledPackages::read()` keeps each package's composer `extra` as it is (`extra` key) and
    `InstalledPackages::extra($package, 'folio.blocks')` reads a dotted path; the old keys `extension`,
    `folio_field_types`, `folio_blocks` and `translations` are gone. Packages declare files checked on every request in
    `extra.preflow.runtime` (Folio declares `config/folio/extensions.json`); core checks `.env` and
    `vendor/composer/installed.json` itself (`ManifestFile::CORE_RUNTIME`; `RUNTIME` removed). Boot manifest VERSION 2.
  - **view:** `pdf_image(<asset id>)` asks `Preflow\View\Pdf\PdfImageSource` (`find(id): ?PdfImage`), which preflow/media
    binds; the variant-pending header is `Preflow\View\VariantPending::HEADER` (media's `VariantController::PENDING_HEADER`
    stays as a deprecated alias until 0.68).
  - **data:** `matrixable` and `views` are admin settings Folio reads from `TypeRegistry::schema()`; `TypeDefinition` lost
    both properties and constructor arguments. A class-backed type may now set them in its admin JSON; Folio needs
    `"matrixable": true` exactly.
  - **media:** focal output is `media-focal media-fx-N media-fy-N` (`--media-fx`/`--media-fy`), preload keys `media-preload:`.
  - **i18n:** the AI prompt says "Never translate proper names or brand names." (no "game names").
  - **devtools:** `auth:user` requires its prefix: `php preflow auth:user FOLIO_ADMIN` on a site with a config login.
  - READMEs, docblocks and config examples use the job-board example domain.
  - **Upgrading:** `composer update "preflow/*"`, then `php preflow optimize` (deploys run it; until then requests boot
    live). Rename your own `.folio-focal`/`--folio-fx` CSS to `.media-focal`/`--media-fx`. Code reading
    `Application::installedPackages()` uses `InstalledPackages::extra()`. Constraints: core conflicts folio and i18n
    <0.67; data conflicts folio <0.67; view and media conflict each other <0.67; folio and i18n require core ^0.67;
    media requires view ^0.67; devtools requires data ^0.67.
- **Extension points without workarounds (v0.66.0, released 2026-11-11):** the Extend guides of docs phase 3 (Tasks 6 and 7) found places
  where an app or package couldn't plug in its own piece cleanly; plan `docs/superpowers/plans/2026-11-11-docs-phase3.md`
  (task 6b), report `.superpowers/sdd/2026-11-11-docs-phase3/task-6b-report.md`.
  - **AI providers are pluggable (ai):** `config/ai.php` `provider` is `'claude'`, `'gemini'`, `'fake'` or the class of a
    `Preflow\Ai\AiClientFactory` (`available(array $options): bool`, `create(array $options): AiClient`), built by the
    container on first use. `$options` = the provider's `options` entry + `model` + `key` (from its `keys` entry; null
    without one). AI is on when `enabled` and the provider is available: the built-ins need their key
    (`ClaudeClientFactory`, `GeminiClientFactory`), a local server may need none. Anything else stops the boot
    (`AiServiceProvider::boot()`), naming the accepted forms. `'fake'` (`Testing\FakeAiClientFactory`) runs without a
    key or cost: `options.fake.answer` (array → JSON, string → text; default `{"ok":true}`) and `options.fake.usage`;
    model `fake-model`, priced "unknown", no request history kept (`FakeAiClient` `record:`). With `app.debug` off the
    fake reports "not available" (AI off) unless `options.fake.allow_live` is true, and says why. `Ai` reports
    warnings (that refusal while AI is switched on, a failed availability check) once per process through a `report:`
    closure: `AiServiceProvider` sends them to the app's bound PSR-3 `LoggerInterface`, else `error_log`.
    `ExplainsUnavailability` (`unavailableReason()`) lets a factory say why it is not available; an `available()` that
    throws counts as not available, with its message as the reason. `AiStatus` gains `available` (null → `enabled`),
    `usesKey` and `reason`; `ai:check` says "Key: not used" and why AI is off. The usage log and `ai:usage` already priced an unknown model as unknown (null), unchanged.
    Folio's Settings → AI shows "the provider says it is not available" for a keyless provider instead of "key
    missing", and "Not used" as its key.
  - **The machine translator is swappable (i18n, folio):** `config/ai.php` `translation.translator` names a
    `MachineTranslator` class, built by the container (with `batchChars` if it takes it); default `AiTranslator`. A
    class that is no `MachineTranslator` stops the boot. A translator answers its own availability and price
    (`MachineTranslator::available()`, `estimateCost(Estimate $size): ?float`), so one that is no AI needs no AI key:
    Folio's Translate routes and buttons (records, labels, media), `TranslationWorker`, `translate:work` and the
    `Estimator` ask the translator, not `Ai`. `AiTranslator` answers through `Ai` (available = AI on; priced by the
    model's tokens), so apps on claude/gemini behave as before.
  - **Guards are built like the rest (core, auth):** a guard of the app's own class is built through the container on
    first use (`Preflow\Auth\LazyGuard`), after the service providers, with `provider` (its built user provider) and
    `config` (its whole `config/auth.php` entry), so it may take services and `#[Config]` values. A class that doesn't
    exist or is no `GuardInterface` still fails at boot. `AuthManager` and `ResolveUserMiddleware` take
    `GuardInterface|LazyGuard` and hand out the built guard. The password hasher is a container binding:
    `password_hasher` (default `NativePasswordHasher`) is built by the container, an app provider may bind
    `PasswordHasherInterface` itself; session guards get a `LazyPasswordHasher` that asks the container on each use.
    SessionGuard and TokenGuard are built as before.
  - **Validation without a database (core):** `RuleFactory`, `ValidatorFactory` and `app/Rules/` discovery are bound
    whenever preflow/validation is installed (`bootValidators()`), also without `config/data.php`.
  - **App error pages (core, view):** convention, not a config key: `app/pages/_errors/<status>.<ext>`, else
    `_errors/error.<ext>`, rendered through the app's engine (`Preflow\View\ErrorPageRenderer`) with `status`,
    `title`, `message`, `request`, `route`, so they extend the site's layout. Live site and internal renders only;
    development keeps the debug page, API paths and JSON clients keep JSON; no template, or one that fails to render
    (logged), gives the built-in page. `ProdErrorRenderer::title()` / `message()` are public;
    `AssetCollector::reprinting()` renders with nothing marked printed. Without `app/pages/_errors/` nothing changes.
  - **Folio field rules may be objects:** `FieldType::rules()` may return any `ValidationRule`; `RecordValidator` and
    `BlocksFieldType` merge rule lists with `Preflow\Folio\Field\FieldRules::merge()` (names once, objects once per
    instance) instead of `array_unique()`, which cast them to strings (a non-Stringable rule answered 500); a Stringable object is
    still kept once against its name.
  - **Packages register field types and blocks (folio, core):** a package's `composer.json`
    `extra.folio.field_types` / `extra.folio.blocks` (class names; `InstalledPackages` keeps them, cache format v2)
    are registered like `config/folio.php`'s: built by the container, after the built-ins and before the app's
    config (the app wins), with their field kinds. A listed class that is no field type stops the boot, naming the
    package; a listed block must carry `#[Block]`, and a duplicate block key fails at boot, also against a package.
    `FieldTypeRegistry::whenRegistered()`: a type a provider registers after Folio booted gets its field
    kinds too.
  - **Config reference:** the starters' `config/app.php` declare `'hypermedia'` as a commented key, so
    `docs:reference` lists `app.hypermedia`.
  - **Starter sign-in polished:** see "Starter: a polished employer sign-in" (Medium Term). `DemoSeeder::EMPLOYER_EMAIL`
    and `EMPLOYER_PASSWORD` are public; `EmployerAuthController` takes `#[Config('app.debug')]`.
  - **Fix:** `Application::installedPackages()` could register the `composer` cache source twice (a `LogicException`)
    when called more than once without a boot manifest.
  - **Upgrading:** nothing to do for the six sites (checked, read only: the personal site, the member site, the collection site,
    the app-like site, the language site, preflow-website). Their `config/auth.php` use only `SessionGuard` (built
    as before) and `NativePasswordHasher`; the app-like site's `config/ai.php` names `'claude'` (unchanged: switch + key), the
    others have none (default `'claude'`, off); BG's one Folio field type returns no rules; BG's `app/Rules/` are
    registered as before (now before the data layer, same factory); none has `app/pages/_errors/` or a package with
    `extra.folio` field types or blocks. Check only, for other apps:
    - `config/ai.php` with a `provider` that is not `claude`, `gemini`, `fake` or an `AiClientFactory` class was
      treated as AI off (or threw at the first AI request when a key was configured); it now stops the boot. Use
      `'enabled' => false` to keep AI off.
    - **Interface change:** `MachineTranslator` gains `available(): bool` and `estimateCost(Estimate $size): ?float`;
      a translator of your own must add them. `Estimator` takes the `MachineTranslator` (was `Ai`), `TranslationWorker`
      takes the `MachineTranslator` in place of `Ai`. Folio's `AdminController`, `AssetLibraryController` and
      `TranslationsController` take `translator:` (a `MachineTranslator`) in place of `ai:`; `AiTranslateController`
      lost its `Ai` argument.
    - `AiStatus`'s `available` defaults to `enabled` when not given.
    - `'fake'` answers only with `app.debug` on, or with `options.fake.allow_live` (tests that run with debug off).
    - `Ai`'s constructor: the fifth argument (a closure building the client) still works; a sixth, `make:`, builds
      factories. `Ai::enabled()` asks the provider's `available()` (built-ins: unchanged).
    - A custom guard is no longer built during boot but on first use; a constructor that must run at boot (it
      shouldn't) runs later. It now also receives `config:`; a constructor with a parameter named `config` gets the
      entry instead of the container's value.
    - Code that `instanceof`-checks the `PasswordHasherInterface` a SessionGuard holds sees a `LazyPasswordHasher`
      (`inner()` gives the real one); the container's binding is the real one.
    - An app with its own 404 middleware keeps it; it may drop it for `app/pages/_errors/404.<ext>`.
    - A `config/folio.php` field type with the key of a package's type replaces it, as with built-ins.
    - Constraints: core conflicts auth and view <0.66; view requires core ^0.66; folio requires ai, core and i18n ^0.66.
- **Pluggable adapters (v0.65.0, released 2026-11-11):** a third-party template engine, hypermedia driver or storage driver is
  registered through config, no workaround. Found by the Extend guides of docs phase 3 (Task 5); plan
  `docs/superpowers/plans/2026-11-11-docs-phase3.md` (task 5b).
  - **Template engines:** `app.engine` is `'twig'`, `'blade'` or the class name of a
    `Preflow\View\TemplateEngineFactory` (`create(TemplateEngineContext $context): TemplateEngineInterface`). The
    factory is built by the container and called where Twig and Blade are built, so its engine gets the packages'
    template functions and renders file pages and components. `Preflow\View\TemplateEngineContext` (readonly):
    `templateDirs`, `assets`, `debug`, `cachePath` (`storage/cache/templates`, cache key `templates`), `geo`, `seo`,
    `liveParts`, `pageCache`. Anything else throws, listing the accepted forms. Twig and Blade are built as before.
  - **Hypermedia drivers:** `app.hypermedia` names the class of a `HypermediaDriver` (default `HtmxDriver`), built by
    the container and bound as `HypermediaDriver` and its own class; the `hd.*` helpers, `ComponentEndpoint`, and the
    form helpers (when the driver implements `FormHypermediaDriver`) use it. `HypermediaDriver` gains
    `fragmentTarget(ServerRequestInterface): ?string` and `applyEffects(ResponseInterface): ResponseInterface`; the
    endpoint asks the driver for the target and lets it apply the effects (no more reflection on `HtmxDriver` or
    `HX-Target` read directly). `HtmxDriver::applyEffects()` sends the recorded `HX-*` headers plus `HX-Nonce` (from
    `HtmxAssets::nonce()`, new). With another driver, htmx is not inlined and `HtmxAssets`/`ResponseHeaders` are not
    bound. `Component::setRootAttribute()` accepts `data-` + `[a-z0-9:._-]+` (Datastar's `data-on:click__debounce.500ms`).
  - **Storage drivers:** a `config/data.php` entry's `'driver'` may be `sqlite`/`mysql`/`json`, a built-in class, or the
    class of a `StorageDriver`, built by the container with the entry's other keys as constructor parameters by name
    (one it doesn't take throws). An unknown driver throws at boot (it was dropped silently). `Grammars::register(string
    $pdoDriver, SchemaGrammar $grammar)` / `forget()` / `named()`: a registered grammar serves `Schema`, `Migrator`,
    `RecordTableSync`. `Dialect` gains `likeOperator()` (where LIKE / NOT LIKE, `search()`, `CONTAINS`) and
    `numericOrder()` (numeric `orderBy`); both built-in dialects return today's SQL (`LIKE`, `(x + 0)`). No PostgreSQL
    driver ships.
  - **Hypermedia clients everywhere (owner ruling, same release):** core's `Preflow\Core\Http\Hypermedia\HypermediaProtocol`
    (`isFragmentRequest`, `isHistoryRestore`, `fragmentTarget`, `varyHeaders`, `navigate`, `announceNonce`,
    `nonceHeaders`, `pageNonce`), which `HypermediaDriver` extends, and `HypermediaProtocols` (bound from the start of
    the boot; also the request attribute `HypermediaProtocols::class`). Nothing outside preflow/htmx reads htmx headers
    any more: AuthMiddleware (navigate to the login; an app-built one finds the clients on the request), the
    expired-session answer (reload), SecurityHeadersMiddleware (`announceNonce`; `NONCE_HEADERS` no longer lists
    `HX-Nonce`, `withoutNonce($response, $more)`), the page cache (`PageKey::fromRequest(…, $hypermedia)`, vary =
    clients' `varyHeaders()` + `vary_headers`, restores, unstored nonce headers), GEO, `request.isHtmx` /
    new `request.isFragment` (PageResponder), `MembersView::navigate()` (`hxRedirect()` deprecated). Folio's admin
    keeps its own htmx and adds it to `HypermediaProtocols` (commented in each place).
  - **Page nonce for fragments:** `HypermediaProtocol::pageNonce()` + `HypermediaProtocols::pageNonce()` +
    `NonceGenerator::adopt()` / `release()` / `adopted()`: a fragment request's client that sends its page's CSP nonce
    back is answered with it (`Application::handle()`), so effect scripts and component assets in the fragment pass
    the page's CSP. Untrusted input: fragment requests only (not plain requests or history restores), 22+ base64
    characters, released after the kernel (before after-response work), and never stored by the page cache
    (`refused:page-nonce`). Drivers read the nonce client-side (`script.nonce`), never print it into the page.
  - **Built after the providers:** the factory's engine (`LazyTemplateEngine`; `TemplateEngineFactory::templateExtension()`
    is static), the configured hypermedia driver (`LazyHypermediaDriver` / `LazyFormHypermediaDriver`; the driver is the
    container's shared class) and class-named storage drivers (`DataManager` takes closures) are built on first use,
    so their constructors may take services a provider binds.
  - **Review fixes (same release):** the configured driver is built right after the providers (a failure stops the
    boot: "app.hypermedia: could not build X: …"); `HypermediaProtocols` tells clients apart by the class a stand-in
    (`Preflow\Core\Http\Hypermedia\StandIn`, the lazy driver) stands for; several JSON storages share one `data.json`
    cache source (`DirectoryCache` takes a list of exclude patterns); an `app.engine` class that is no factory says so.
  - **Dialect::missingColumn(\PDOException): ?string:** PdoDriver asks the dialect which column a failed write names.
  - **Contract suite:** `HypermediaDriverContract` gains the hook `targetRequest($request, string $targetId)`, the
    optional hooks `historyRestoreRequest()` and `pageNonceRequest()`, and tests for `fragmentTarget()`,
    `applyEffects()` (effects reach the response, which keeps status, headers and content) and the protocol methods;
    `effects()` is now optional (default: the headers and body `applyEffects()` gives an empty response).
  - **Upgrading:** nothing to do for apps on twig/blade, htmx and sqlite/mysql/json. Check only: a `config/data.php`
    entry with an unknown name and no `'driver'` (dropped silently before) now stops the boot; a class implementing
    `HypermediaDriver` or `Dialect` must add the new methods; a `HypermediaDriverContract` subclass must add
    `targetRequest()`; a `TemplateEngineFactory` needs the static `templateExtension()`. `HtmxDriver::isFragmentRequest()`
    is `HX-Request: true` (was: the header present).
    - The `'driver'` key of a `config/data.php` entry is now read: it decides the storage (before, only the entry's
      name did). An entry whose `'driver'` names another built-in than its name changes storage — check it matches.
    - Built by hand without `HypermediaProtocols`, these no longer recognise htmx: `PageKey::fromRequest()`,
      `SecurityHeadersMiddleware`, `ExpiredSessionResponder`, `PageResponder` (and `requestVariable()`),
      `GeoMiddleware`, `SeoMiddleware` (its 304 keeps `HX-Nonce` then), `MembersView`. Pass the container's
      `HypermediaProtocols`. `AuthMiddleware` built by hand reads it from the request (core puts it there).
    - No driver at all (preflow/htmx not installed): no request is a fragment for core (no HX-Nonce, no HX-Redirect or
      HX-Refresh); the default `vary_headers` (`HX-Request`, `HX-Target`, and since Task 5c `HX-History-Restore-Request`)
      still keep fragments' and restores' cache keys apart.
      With preflow/htmx installed core adds the header-only htmx client at the start of the boot, so apps without
      components or an engine behave as before.
    - A history restore drops only its client's vary headers from the key (htmx: `HX-Request`, `HX-Target`,
      `HX-History-Restore-Request`), no longer every configured `hx-*` header: a site with an extra `HX-*` header in
      `vary_headers` gets a separate (cache-miss) key for restores; nothing wrong is served.
    Constraints: core conflicts data/htmx/view <0.65, htmx conflicts core <0.65, folio htmx <0.65, testing htmx <0.65. The six production sites' configs were checked: all entries are sqlite/mysql/json, some with
    their built-in class as `'driver'`, which keeps working.
  - **Folio follow-ups from the Folio guides (Task 5c, same release):** plan
    `docs/superpowers/plans/2026-11-11-docs-phase3.md` (task 5c).
    - **Fix round 2 (data):** `Schema::renameColumn()`, `Schema::addColumns()` (the `create()` builder on an existing
      table, columns added nullable) and `Schema::fillNulls()` (backfill), for SQLite 3.25+ and MySQL 8 through the
      grammar; `SchemaGrammar` gains `renameColumn()` (and in round 3 `copyColumn()`, `dropColumn()`; a registered grammar must
      implement them). starter-folio ships
      `migrations/2026_11_11_005_upgrade_staff_columns.php` (idempotent) instead of hand-written SQL.
    - **Fix round 3 (data):** `Schema::copyColumn()` (old into new where new is NULL) and `Schema::dropColumn()`
      (SQLite 3.35+, MySQL), with `SchemaGrammar::copyColumn()`/`dropColumn()`; `addColumns()` checks its indexes
      before any SQL (one check shared with `create()`); column-name checks ignore case. The staff upgrade migration
      converges from any partial shape (old and new columns side by side, a stopped run).
    - **Fix round 1 (review):** rbac's config-user check (`StaffRules`) and `folio:staff:import-config` read Folio's
      staff guard's provider through the new public service `Preflow\Folio\Users\StaffUsers` (`guard()`,
      `provider()`), not the default guard's; `SharingTemplateEngine` logs instead of throwing when a sharer (a Twig
      engine that rendered) refuses a late registration; an empty value is null for a `?bool` model property; the
      uninstall question cancels without a terminal and names `--force`; the upgrade steps below spell out the
      employers-guard step and the staff-table rename.
    - **Staff-only sign-in:** Folio signs staff in through `config/folio.php` `'guard'` (new; default: the app's default
      guard, as before), `Preflow\Folio\Users\StaffGuard`. At boot a guard that declares a population other than
      `staff` (members, the plain starter's new `'population' => 'employers'`), or that `config/auth.php` doesn't
      define, stops the boot with a `RuntimeException` naming the guard, its population and what to configure. A guard
      without a population works as before (Folio marks it staff). Login, logout, the admin gate, the password pages,
      the account page, `StaffPasswords` and the record providers all use that guard; a user of another guard on the
      request is sent to the login. auth: `AuthManager::provider(?string $guard)` (core passes each guard's provider),
      `Access::declaredPopulation()`, and a declared population other than `staff` is never made staff by a package's
      mark (`populationOf()` counts it as members). members: `Viewer::isStaff()`/`name()` take the user of any guard of
      the staff population (was: the default guard), so they follow `folio.guard`.
    - **Uninstall:** `folio:extensions:uninstall <name> [--force]` runs `Extension::uninstall()` for a disabled extension
      installed here (none whose types a site model extends), after a `[y/N]` question (`--force` skips it), then forgets
      its installed version (`ExtensionState::forget()`). Nothing calls `uninstall()` implicitly.
    - **Folio on any template engine:** `Preflow\Folio\View\FolioEngine` renders every `*.twig` name (the admin, the
      fallback frontend templates, extensions' templates, `resources/folio` overrides) through a Twig engine Folio owns,
      built from core's `TemplateEngineContext` (now bound for every engine) with every function and global of the app's
      engine (`Preflow\View\SharingTemplateEngine`, which wraps a non-Twig app engine; `share()`); other names (a type's
      `frontend.template` for the app's engine) through the app's engine, which has the `folio_*` functions. On a Twig app
      both are the app's `TwigEngine`: nothing changes. rbac renders its templates through `FolioEngine`. Folio requires
      `preflow/twig`.
    - **Guide fixes:** Folio README (table sync on SQLite and MySQL; only the dashboard is overridable; screens and record
      actions declare their permissions, bulk actions don't; `BeforeSave`/`AfterSave` run on the bulk "Set …" save).
      The relation column kind reads `relation.multiple` (`FieldKinds`, `RelationFieldType::columnKind()`).
      `folio:assets:import` counts and rewrites only rows where a legacy path became an asset id (`--dry-run` no longer
      counts rows it wouldn't change). The admin layouts carry `<meta name="robots" content="noindex">`, so GEO adds no
      Markdown alternate to signed-in admin pages.
    - **starter-folio:** revision authors by name (`AuthorNames` asks the staff guard's provider by id when it is no
      record provider: a model's public `name`/`email`); `staff:create --role=<role>` accepts every staff role that
      exists (auth `ListsRoles` + `Access::roles(string $population)`, implemented by preflow/roles' `ConfigRoleResolver`,
      rbac's `RbacResolver` and members' `MemberAccess`); the staff table and `App\Models\Staff` use rbac's column names
      (`password_hash`, `active`, `auth_epoch`, `last_login_at`, `created_at`) so switching to rbac's staff accounts keeps
      the table and its accounts (README "Roles edited in Folio"; proved by rbac's `StarterStaffTableTest`);
      `create-project` runs `folio:extensions:apply` (`bin/starter` runs the starter's `post-create-project-cmd` and
      `fresh` fails when an enabled extension is not installed). data: a `bool`/`?bool` model property reads SQL's
      1/0 (`Model::fill()`; it threw a `TypeError` before).
    - **Core/htmx:** `PageCacheConfig::DEFAULT_VARY_HEADERS` adds `HX-History-Restore-Request` (an app that loads htmx
      without preflow/htmx keeps restores apart from fragments; htmx apps keep identical keys, `PageKeyTest`). README: the
      configured hypermedia driver is built eagerly at the end of the boot, right after the providers (also on the CLI).
    - **Note (no code):** a nested non-internal `handle()` inside a request whose page nonce was adopted releases the
      adoption early (`NonceGenerator::release()` after the inner kernel); a streamed body emitted after that release
      carries the request's own nonce, not the adopted one. Internal renders are unaffected; revisit if a site nests
      `handle()` or streams fragments.
    - **Upgrading:** nothing to do for the six sites (their Folio guards are the default guard with no population or
      `population` `staff`; Twig + htmx; no app config changes). **An app made from `preflow/starter` before 0.65 that added Folio
      is NOT protected by upgrading:** its own `config/auth.php` has no population on the employers guard, so Folio still
      takes it as staff and every employer keeps full admin access. Step 1: add `'population' => 'employers'` to the
      employers guard (Folio then refuses to boot). Step 2: set up a staff guard (a `SessionGuard` on staff accounts with
      `'population' => 'staff'`) and make it the default guard or name it in `config/folio.php` `'guard'`. New projects
      from the starter declare the population already. An app whose staff guard declares `'population' => 'members'` (or another non-staff name)
      fails the same way. Existing starter-folio projects keep their own code (the staff table change is for new
      projects). To adopt it, copy starter-folio's
      `migrations/2026_11_11_005_upgrade_staff_columns.php` into the project's `migrations/` and run `php preflow migrate`
      (renames `passwordHash`/`createdAt`, or merges them into the new columns where Folio's table sync or a stopped
      run added those, adds `active`/`auth_epoch`/`last_login_at`, backfills `active = 1`, `auth_epoch = '0'` where
      empty; it converges from any partial shape and does nothing on a table of the new shape), then take `app/Models/Staff.php`,
      `app/Console/StaffCreateCommand.php` and `app/Seeds/DemoSeeder.php` together. A project template's migrations
      can't arrive by `composer update` (the project is no dependency of itself), hence the copy.
      A `SchemaGrammar` registered with `Grammars::register()` (new in 0.65) implements `renameColumn()`,
      `copyColumn()`, `dropColumn()`, `dropIndex()`, `indexColumns()` and `assertCanDropColumn()`.
      Constraints: folio requires auth/core/view/twig ^0.65; roles, rbac and members conflict auth <0.65 (`ListsRoles`);
      rbac conflicts folio <0.65 (`FolioEngine`). The starters require `^0.65` (raised in the release commit).
- **Contract suites (v0.64.0, released 2026-11-11):** abstract PHPUnit suites in `preflow/testing` that check a third-party
  adapter (a Datastar hypermedia driver, a PostgreSQL storage driver, another template engine) against its interface;
  the built-in adapters run them too. Plan: `docs/superpowers/plans/2026-11-11-docs-phase3.md` (task 1).
  - **What it is:**
    - `Preflow\Testing\Contract\TemplateEngineContract` (hooks `createEngine(string $templateDir)`,
      `fixture(string $name)`), `HypermediaDriverContract` (`createDriver()`, `fragmentRequest($request)`,
      `effects()`), `StorageDriverContract` (`createDriver()`, `createType(string $type, array $fields)`; optional
      `supportsAutoIds()` and `supportsRawQuery()`, default true). Each suite's class docblock says what the hooks
      return and which value representations count as equal.
    - An adapter author extends the suite in their own tests, implements the hooks and runs PHPUnit; see "Contract
      suites" in `packages/testing/README.md`.
    - Run by `TwigContractTest`, `BladeContractTest`, `HtmxContractTest`, `SqliteContractTest`, `JsonContractTest`
      and `MysqlContractTest` (group `mysql`, `composer test:mysql`). twig, blade, htmx and data require-dev
      `preflow/testing`.
    - blade: `BladeEngine::addNamespace()` registers a Blade view namespace (it was a no-op); templates reference it
      as `ns::name`, paths searched in registration order.
  - **Not covered yet:** `search()`, `whereAny()` groups, localized queries and the optional storage interfaces
    (`VersionedStorage`, `AggregatingStorage`, `ExclusiveWrites`); rendering a template by absolute path or by
    `@namespace/…` name directly. (The data package tests most of these driver by driver; moving them into
    `StorageDriverContract` gives third-party drivers the same checks.)
  - **Follow-ups found while writing the Folio guides (docs phase 3):** all done in 0.65.0 (Task 5c, see
    "Pluggable adapters (v0.65.0)" → "Folio follow-ups from the Folio guides"):
    - Folio's admin signs in through the app's default guard. In `preflow/starter` that guard is the employers', so
      adding Folio to it as-is lets any employer into the admin with every right (checked on 0.64.0). Proposal, for
      the owner to decide: Folio refuses admin sign-in unless its guard's population is `staff`, like the 0.63.2
      members rule, or says so loudly. The Folio setup guide warns about it first.
    - `packages/folio/README.md` says the table sync is SQLite only; since the grammars it also runs on MySQL.
    - The relation column kind (`FieldKinds` and `RelationFieldType::columnKind()`) reads a field-level `multiple`,
      but the relation field takes it from `relation.multiple`. Harmless unless the field is unique or indexed (Short
      stays Short then): a unique/indexed multiple relation gets VARCHAR(255) on MySQL. None of the six sites has one.
    - `folio:assets:import --dry-run` reports records to rewrite on a site with no old uploads; a real run
      rewrites those rows for nothing.
    - starter-folio shows raw staff ids as revision authors, and its `staff:create` accepts only `admin` and
      `editor`, so roles made in Folio's RBAC screens can't be given to its staff (ties in with the starter-folio +
      rbac staff table clash above).
    - Folio README vs. code: only the dashboard (`AdminController::index`) looks for an override, not "any core
      action"; screens and record actions declare their permissions themselves (README says to declare each with
      `#[DeclaresPermission]`); BeforeSave/AfterSave also run on the bulk "Set …" save.
    - `Extension::uninstall()` is never called. starter-folio's create-project doesn't run
      `folio:extensions:apply`, so a fresh install lists `jobs` as "Not installed".
    - Signed-in admin screens carry the GEO `<link rel="alternate" type="text/markdown">` (e.g.
      `/folio/stale.md`); the admin layout should not get it.
- **Starters (0.63):** released in 0.63.0 (2026-11-10). Two project templates replace the skeleton, plus the three
  general pieces they needed (rule: general features live in the packages). Plan:
  `docs/superpowers/plans/2026-11-09-starters.md`.
  - **What it is:**
    - devtools: `preflow db:seed` boots the application: seeders get constructor injection from the container, the
      `--class` option picks a seeder, `.env` is loaded, and models are validated on save. `preflow serve` quotes its
      paths (a project path with a space works).
    - auth: `AuthMiddleware` sends a signed-out HTML GET or HEAD to the guard's `login` path with `?next=`, and answers
      htmx with `HX-Redirect`. A guard without a `login` key, and a middleware built without an `ApiConfig`, behave as
      before (401). `AuthManager::loginPath()`.
    - view: `Preflow\View\PageResponder` renders a page template from a controller (assets collected, status code
      such as 404). `route` is an empty object there, since a controller has no route parameters; callers pass what
      the template needs.
    - New packages `preflow/starter` (a job board: public search without a session cookie, employer accounts, a JSON
      API with bearer tokens, SQLite) and `preflow/starter-folio` (the same, with Folio: jobs are a Folio type, staff
      accounts are records, publishing is Folio's). Install with `composer create-project`; both seed a demo and run
      `composer test` green on a fresh install. `bin/starter test <name>` and `bin/starter fresh <name>` check them.
    - `preflow/skeleton` is abandoned in favour of `preflow/starter` (`"abandoned"` in its composer.json, README
      notice). It stays in the split for this release only.
  - **Upgrading:**
    1. `db:seed` validates on save: seed data that is invalid now fails the run instead of being stored.
    2. A guard with a `login` path in `config/auth.php` now redirects signed-out HTML requests instead of answering 401;
       without the key nothing changes.
    3. core conflicts with view before 0.63 (the file-page renderer calls `PageResponder`); the other packages keep
       their constraints (auth and devtools use nothing new from another package). Auth before 0.63 ignores the guard
       `login` key (core 0.63 passes the new constructor arguments; with auth 0.62 signed-out visitors keep getting
       401), so upgrade auth together with core; no conflict constraint enforces it. The starters require every
       `preflow/*` at `^0.63`.
    4. Owner: submit `starter` and `starter-folio` to Packagist and tick "abandoned" (replacement `preflow/starter`)
       on `preflow/skeleton`.
  - **Release checklist (every release):** bump `preflow/*` in `packages/starter/composer.json` and
    `packages/starter-folio/composer.json` to the new minor (`bin/starter` derives the versions from that pin, so a stale
    one is not caught by anything).
    preflow.dev: `php preflow starters:sync`, `php preflow packages:refresh`, and `php tools/capture/capture.php` when the starters' pages or Folio's admin changed; then test and deploy like the other sites.
  - **Tests:** 0.63 full monorepo run: 6537 tests, 31382 assertions (185 skipped); starter suite 86 tests, starter-folio suite 117 tests.
  - **0.63.1** (2026-11-10): starter-folio gets a small Folio extension, the example preflow.dev shows (`app/Folio/Jobs`: an "Open jobs" column on the company list and a dashboard notice for jobs that are drafts; the link follows `config/folio.php` `path`). README section "Make Folio yours". Framework packages unchanged; full monorepo run 6537 tests; starter-folio suite 121 tests.
  - **0.63.2** (2026-11-11): a patch release of fixes from the 0.63 follow-up list; public APIs unchanged. Plan:
    `docs/superpowers/plans/2026-11-10-063-2-patch.md`.
    - data (security): `where()` / `orWhere()` take only `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`,
      `IN`, `NOT IN` (any case); anything else throws.
    - data (security): field names in `where`, `orWhere`, `whereAny`, `orderBy` and `search` must be identifiers
      (`^[A-Za-z_][A-Za-z0-9_]*$`). SQLite and MySQL identifier quoting escapes the quote character, in migrations too.
    - data: `where(f, null)` and `where(f, '=', null)` mean `IS NULL`; `!=` / `<>` null mean `IS NOT NULL`. SQLite,
      MySQL and JSON agree (shared test scenarios).
    - data: `IN` / `NOT IN` work on SQL (they bound an array to one `?`). An empty `IN` matches nothing, an empty
      `NOT IN` everything. The JSON driver gains `<>`, `NOT LIKE`, `IN` and `NOT IN`.
    - data: a plain model with a `string` `#[Id]` gets a UUID v4 on `save()` / `insert()`. `insert()` keeps a given id
      and refuses one that exists (it dropped the id before).
    - data: `#[Timestamps]` works, on the class or a property. It sets `createdAt` or `created_at` (the first the
      model has) when empty and `updatedAt` or `updated_at` on every write, on plain models. `DateTimeImmutable`
      properties get `DateTimeTransformer`; `string` ones get `Y-m-d H:i:s` text, `int` ones the Unix time; a
      mutable `\DateTime` property is not stamped.
    - auth (security): `SessionGuard::validate()` spends one password verify on an unknown address or an account
      without a hash, the same as a wrong password.
    - members (security): sign-in spends one hash on a member without a password hash, as on an unknown address.
    - members: `Viewer::isStaff()` is true only when the default guard's population is `staff`.
    - routing: files and folders starting with `_` are never routes, at any depth (`_views/`, `blog/_partials/`).
    - core: production 403, 404 and 405 pages show only the status text, not the exception message.
    - core: `.env` reads `A="x" # note` as `x`, `\"` inside double quotes as `"`, and `A='x # y' # z` as `x # y`.
    - core: `RequestContext` (`request.path`) has no locale prefix once the locale middleware has run: controller
      pages, rebuilds and `seo:audit` renders too.
    - view: GEO `.md` views (and `Accept: text/markdown` answers) of pages held by the page cache keep their geo
      blocks (the sub-request is an internal render). `llms-full.txt` still takes cached pages from the page cache.
    - blade: file pages (`about.blade.php`, `blog/[slug].blade.php`) render; they were not found.
    - view: `js('inline')` blocks are printed on full pages, after body JS, once.
    - components: an `HttpException` thrown by a component (render, template, re-render after an action, `#[Live]`
      part, also on a page-cache hit) is the page's status. Twig and Blade unwrap it from their wrapper exceptions.
      Other errors still show the component's fallback.
    - components: an unscoped child component is no longer scoped by its parent. `@keyframes`, `@font-face` and the
      other at-rules that can't be nested are kept outside the scope. Scoped CSS is cached per process.
    - devtools: `routes:list` boots the app and lists every routed path (controllers, package routes, the component
      endpoint first). The toolbar counts the page's CSS and JS blocks (it showed 0), and its CSP list ignores text
      inside scripts and styles.
    - starter-folio: the Hero link refuses whitespace and control characters (`safe_link()` rules). The drafts notice
      escapes the admin path. Publishing, in the employer area and through the API, needs `job.publish`, which
      `config/roles.php` gives the `employer` member role. Without it the employer area goes back to the job list
      with a notice; the API answers 403.
    - Both starters: the employer form keeps input saved after an expired session. `config/data.php` lost the unread
      `driver` key.
    - READMEs checked against the code: form (`overrides`, radio groups, value order), ai (`models` has no fallback,
      `tuning` is read by the translator only), media (videos get colours from their preview), components (id, where
      CSS and JS are printed, at-rules in scoped CSS), routing (`_` folders), data (operators, identifiers,
      timestamps, unique fields on plain models need the migration's index). Folio's composer description.
    - Tests: `composer test:mysql` migrates each app template on its own with the library packages (the two starters
      share a migration name and never share a database). Folio's case/accent revision tests use valid slugs. The
      MySQL group is green again; it had failed since 0.52 at the latest.
    - The starters keep `preflow/*` at `^0.63` (the release checklist bumps on a new minor only).
    - **Upgrading:**
      1. data: `where()` throws `InvalidArgumentException` for another operator, for `IN` / `NOT IN` without an
         array, and for a field name that is not an identifier (no dots, hyphens or spaces);
         `orWhere()`, `whereAny()`, `orderBy()` and `search()` throw for such a field name too. Type field keys must
         be identifiers to be filtered or sorted.
      2. data: `where(f, '=', null)` now means `IS NULL` (it meant `f = '='`). `where($field, $value)` with a null
         `$value` now matches rows where the field is NULL on SQL (it matched nothing). Guard nullable variables.
      3. data, JSON driver: `LIKE`, `NOT LIKE`, `IN` and `NOT IN` never match a null field, as in SQL (an empty
         `NOT IN` takes every row).
      4. data: `insert()` of a string-id plain model keeps or creates the id and throws when it exists.
         `#[Timestamps]` now sets the values; `DateTimeImmutable` properties of plain models are stored as
         `Y-m-d H:i:s` text.
         Class-backed types are not stamped.
      5. auth: a custom `PasswordHasherInterface` gets one extra `hash()` per guard instance, the first time an
         unknown address signs in. The native hasher with default options makes none.
      6. members: a site using members without Folio that wants its default-guard users treated as staff on
         `/members/account` sets `'population' => 'staff'` on that guard in `config/auth.php`. Folio sites need
         nothing.
      7. routing: links to `_` paths (`/_views/job`) now get a 404. A hand-made route cache file (Router
         `cachePath`) needs a rebuild; the boot manifest rebuilds itself.
      8. core: in production 403, 404 and 405 pages show only "Forbidden" / "Not Found" / "Method Not Allowed"
         (CSRF reasons and token checks appear only on the debug page). 400 and 422 messages, and JSON error bodies,
         are unchanged.
      9. core: `.env` values in one well-formed quoted string followed by a `#` comment lose quotes and comment now.
         Other values that start and end with the same quote parse as before; unquoted values are unchanged.
         Check server `.env` files for quoted values with comments.
      10. core: code that stripped the locale prefix from `RequestContext->path` itself should drop that code, or a
          path whose first segment is a locale code is stripped twice (demodemo and folio-demo carry the old
          `LocaleSwitcher`). Code that needs `/de/…` adds the prefix back (`LocaleMiddleware::urlPrefix()`).
      11. components: an `HttpException` from a component or a template function answers the whole page (or the `/--component` request)
          with its status, through the error page. Before, the component's `fallback()` showed with a 200. A
          component that should show an empty state catches the exception itself. This holds for Folio blocks too
          (public page and the editor's live preview), and for `#[Live]` parts; htmx does not swap a 4xx answer.
      12. view: `{{ assets() }}` and injected assets now include `js('inline')` blocks on full pages.
      13. components: an unscoped child's CSS is no longer nested under its parent's class (one class less
          specificity). `@keyframes` / `@font-face` in scoped CSS now apply; their names are global, so prefix them
          per component. preflow.dev: check the home and docs pages (Spotlight animations now move).
      14. view: `.md` views and `Accept: text/markdown` answers of cached pages render on each request (one render
          each; `Cache-Control: public, max-age=3600`). `llms-full.txt` reads cached pages from the page cache as
          before, so their `{% apply geo %}` blocks are still missing there.
      15. starter-folio sites made from 0.63.0 / 0.63.1 that copy the publish check also need the `members` section
          in `config/roles.php`, or their employers can't publish.
    - **Tests:** full monorepo run: 6616 tests, 31740 assertions (190 skipped); `composer test:mysql`: 185 tests, 830 assertions; starter suite 87 tests,
      starter-folio suite 127 tests.
  - **Follow-ups:**
    - Remove `packages/skeleton` (step 2 of its retirement): the split entry, the root autoload (`App\`, `Tests\`),
      the Skeleton suite in `phpunit.xml`, the migration copy in `AuthTokenCommandTest`, and the skeleton's
      `config/data.php` `driver` keys (read since 0.65 and matching their entries; the starters dropped theirs in 0.63.2).
    - CSRF: exempt API prefixes that are outside the session paths by default (the starters set
      `csrf.exempt = ['/api']` by hand, so 401/404/405 answer JSON).
    - `folio.tables` does not index relation columns (starter-folio adds `jobs.company` indexes in an after-migrate
      step, SQLite only).
    - Folio's `/folio/login` matches the email case-sensitively; the auth provider or Folio's AuthController should
      normalise it.
    - Folio date fields cannot default to today (starter-folio's "Posted on" is set in code).
    - Expose the description `seo()` chose, so a layout does not need an app helper (starter-folio's
      `page_description()`).
    - The component endpoint resolves the default state before it runs an action (the starter's search runs twice).
    - Optional: a Folio slug generated from the title (starter-folio requires the slug).
    - ~~starter-folio: the Hero block's link check lets URLs with control characters or whitespace through (`/\t/host` is followed as `//host` by browsers) — refuse them as preflow.dev's `safe_link()` does; and `CompanyAdmin::drafts()` should escape the admin path in its href.~~ done in 0.63.2 (`Hero::safeLink()`, escaped drafts href).
    - ~~components: an unscoped component rendered inside a scoped one inherits the parent's CSS scope (`ComponentRenderer::renderTemplate`), so its `@keyframes` (and `@font-face`) end up nested in the parent's scope and browsers drop them. Hoist at-rules like `@keyframes`/`@font-face` out of the scope (or don't inherit scope for unscoped children).~~ done in 0.63.2 (both: unscoped children get no scope, non-nestable at-rules are kept outside it). preflow.dev can drop its workaround in `app/Components/Animation/Animation.php`.
    - ~~starter-folio with preflow/rbac: rbac's opt-in staff accounts (`model:staff`) clash with the starter's own `App\Models\Staff` (sign-in config, password hashes), so a site switching the starter to rbac can't assign roles to staff in Folio without manual fix-ups. Provide a documented path (or make the starter's staff the rbac staff type). Investigated in 0.63.2, not fixed: both use the table `staff` (starter camelCase `passwordHash`, `createdAt` with `DataManagerUserProvider`; rbac snake_case `password_hash`, `active`, `auth_epoch` with `RecordUserProvider`), so `folio.tables` adds empty snake_case columns and existing staff can't sign in. Needs a decision: an rbac staff type with configurable column names or an import command, or the starter's staff table with rbac's column names.~~ done in 0.65.0: the starter's staff table has rbac's column names (README "Roles edited in Folio").
    - Page cache: a per-path `cache_query` (it is one switch for the whole site today).
    - Page cache: component tokens are issued per request for cached pages.
    - core: `Config` holds only `app.*`.
    - core `Throttle`: trusted proxies for the client address.
    - auth: a shared `SafeNext` helper for `?next=` checks (members has its own in `Members\Http\SafeNext`).
    - Access: an explicit resolver decorator API; wrapping a resolver depends on provider order today.
    - validation: `min` / `max` / `between` on numeric strings (a postcode `01234` is compared as a number).
    - data, JSON driver: `!=` with a non-null value includes rows where the field is null; SQL excludes them.
    - data: `insert()` with a given string id checks, then upserts, so two concurrent inserts are not refused; a plain
      `INSERT` on `PdoDriver` would let the primary key refuse the duplicate (the class-type path too).
    - data: validate type field keys as identifiers in `TypeRegistry` (a hyphenated key registers fine, then throws
      on filter or sort).
    - data: `Timestamps::propertyOf()` uses `property_exists`, so a non-public `createdAt` hides a public `created_at`.
    - auth: a custom hasher's first unknown address per guard instance costs hash + verify (about twice a wrong
      password); only the native hasher gets the ready dummy hash.
    - core: an app error template convention. No code renders `_error.twig` / `_404.twig` (demodemo, folio-demo,
      the personal site, another small site have one), so visitors see the generic page.
    - core: a production JSON 404 still says `No route matches [GET /api/x]` (the client's own method and path; kept
      under the 0.62 JSON contract).
    - ~~core: the `RequestContext` docblock says `$request` is null in an internal render, but `handle()` always binds
      the request.~~ done in 0.63.2 (the docblock: null or the render's own clean anonymous request).
    - GEO: store the converted Markdown of a cached page in the page cache under its own key, invalidated by the
      page's tags (each `.md` request renders since 0.63.2).
    - llms-full.txt: entries served from the page cache lose their {% apply geo %} blocks; store the markdown variant
      in the page cache.
    - view: the production CSS minifier strips the quotes of `url("…")`, which breaks URLs with spaces or parentheses.
    - components: move `@import`, `@namespace` and `@layer a, b;` out of scoped CSS too (documented as unsupported).
    - devtools `routes:list`: the fallback scan after a failed boot is not caught, and the command exits 0 when boot
      failed.
    - devtools: the CSP check empties every `<script>`, also `text/template` ones whose markup becomes DOM; the toolbar
      counts `importmap` / `speculationrules` as JS blocks and shows 0/0 if PCRE hits its limit on a huge page.
    - starter: the plain starter's API publishes for any token user (no permission package); the preflow.dev
      permissions guide adds roles and the check. Consider adding roles to the plain starter.
- **REST pieces (0.62):** released in 0.62.0 (owner, 2026-11-09). What a JSON API needs from the framework, as small
  parts with public interfaces; Folio is not involved.
  - **What it is:**
    - `Preflow\Core\Http\Json` (`response`, `data`, `noContent`, `FLAGS`). A controller action that returns an array or
      a `JsonSerializable` answers JSON. A malformed JSON request body answers 400 on every path.
    - `config/api.php`: `prefixes` (default `['/api']`) and `cors` (`origins`, `methods`, `headers`, `expose`,
      `max_age`, `credentials`). `*` together with credentials is refused. The prefixes are non-public paths.
    - JSON errors (`{"message"}`; 422 adds `errors`; debug adds `debug`) for API paths and for requests whose `Accept`
      asks for JSON without `text/html`. An `HttpException`'s own message is shown, any other exception shows
      `Server Error`. `HttpException` has `$headers`.
    - 405 with `Allow` (`MethodNotAllowedHttpException`). A catch-all route does not count.
    - `CorsMiddleware`, outermost (error responses too), only while `cors.origins` is set.
    - auth: `TokenAuthMiddleware` (guard: constructor argument, else `auth.api_guard`, else the first `TokenGuard`; a
      named guard that is not a `TokenGuard` is a 401; 401 carries `WWW-Authenticate: Bearer`),
      `AuthManager::tokenGuardName()`.
    - devtools: `preflow auth:token <email-or-id> [--name=]`, `--list <email-or-id>`, `--revoke=<uuid>`.
    - testing: `TestResponse::json()`, `assertJsonPath()`, `assertJsonValidationErrors()`, `assertMethodNotAllowed()`.
    - skeleton: `config/api.php` (commented, CORS off).
  - **Upgrading:** a site without `config/api.php` sees five changes:
    1. a wrong method on an existing path (not a catch-all) answers 405 instead of 404;
    2. an uncaught `ValidationException` answers 422 instead of 500;
    3. a malformed JSON request body answers 400 (it was silently ignored);
    4. a request with `Accept: application/json` (without `text/html`) gets a JSON error body instead of the HTML error
       page (a 404 caught by a site middleware stays the site's HTML; such a middleware does not see 405s);
    5. `/api` is the default API prefix: paths under it are non-public (page cache, redirects, not-found list, SEO and GEO skip them) and answer errors as JSON; set `'prefixes' => []` in `config/api.php` to opt out.
    routing and auth require core 0.62, core conflicts with routing before 0.62, devtools requires core 0.62.
  - **Follow-ups:**
    - Caching API GETs: needs its own design, because bearer requests must never get or store a shared copy. API paths
      are never stored by the page cache today.
- **Model bridge (0.61):** released in 0.61.0 (owner, 2026-11-09: the PHP class is the source of truth). Spec:
  `docs/superpowers/specs/2026-11-09-model-bridge-design.md`. Content without code is a JSON file; the app's own
  models are PHP classes; Folio edits both.
  - **What it is:**
    - `#[Entity(type: 'job')]` makes a model class the type `job`; `#[Field]` gained `type`, `label`, `help`,
      `config` and `default`, and `#[Relation]` writes a relation with `onDelete`. `ModelSchema::of()` derives the
      type. A class and a JSON file that both define the schema of a key is a boot error (`TypeRegistry` checks the
      class keys' files when it is built). A class marked with a key the registry does not know is a `LogicException`
      on first use, never a fall back to a plain model.
    - `ModelClasses` finds marked classes in `data.model_paths` (default `app/Models`) and the boot manifest caches
      them. `TypeRegistry` serves class-backed types next to JSON ones.
    - A class appears in Folio through an admin-only file (`config/models/job.json`): presentation only. Folio's
      `TypeCatalog` lists it and edits it like a JSON type, tables and publishing included.
    - Typed reads and writes have parity with JSON types: uuid ids, columns the class does not declare stay as
      stored, localized queries, loose search, the same page cache tags. `findAs()`, `queryAs()` and `isReadOnly()`
      read a published copy as read-only objects; Folio's `PublishedModels` wraps them (and reads the type's own
      table where `LiveContent` would: previews, publishing off). Array fields (several assets, blocks, matrix, a
      multiple relation) are `?array` properties; a value hydration could not take is kept by the next save.
    - `preflow make:model <Class> --from-type=<key>` turns a JSON type into a class and its admin-only file after
      a self-check; nothing in the database changes.
  - **Upgrading:**
    - Nothing changes for existing sites: a model without a type key is as before, and no site moves a type in
      this release.
    - An admin file that sets a schema key (`table`, `storage`, `id_field`, `matrixable`, `views`, a field's `type`,
      `validate`, `options`, `relation`, …) or `"multiple"` for a class-backed type is refused with an error naming
      the file. JSON types are unaffected.
    - All `preflow/*` packages must be at 0.61 together for Folio and devtools; composer enforces it (folio requires
      core and data 0.61, devtools data 0.61, core conflicts with data before 0.61).
    - Run `preflow optimize` after upgrading (the standard deploy does): the boot manifest then holds the model
      class list.
    - Relative `data.model_paths` entries are under the app's base path (not the working directory).
  - **Follow-ups:**
    - Move the collection site's objects and the app-like site's games to classes, later and per type (`make:model --from-type`).
    - Type-level `matrixable` and `views` cannot be expressed on a class yet; the generator refuses such types.
    - Public `readonly` properties are never hydrated (undocumented before this note); a clone of a read-only object
      is writable.
    - A translatable property must be `array|string|null` and still needs an explicit `type: 'string'`
      (`inferType` rejects the union).
    - `insert()` with a caller-supplied id checks existence non-atomically (the database's primary key is the guard).
    - A numeric cast of out-of-range text (`'1e30'`) is not guarded.
    - Config keys that collide with attribute-owned keys are silently dropped by `ModelSchema`; an error would be
      clearer. The "needs a field type" message and `?Attributes\Relation` naming want a polish.
    - A slim admin file without a class is listed by `has()`/`keys()` and only errors on `get()`/`schema()`.
    - `Application` keeps a dead `class_exists(ModelClasses)` guard.
    - Derived `TypeDefinition`s are computed per request from the class (reflection; about the cost of a JSON
      type's file decode), not stored in the boot manifest.
    - Thin test coverage: translatable/unique/index/length/searchable/transform/default/help/`onDelete` overrides,
      `#[Validate(on:)]` with `rules()`, transformer round trip, overlay errors for `id_field`/`matrixable`/`views`,
      the typed read of `''` and `'FALSE'` into a bool property.
    - `StaffScaffold` docblock alignment; `addSources` is called before `scanFiles` in `TypeCatalog`.
    - `MigrateCommandTest` writes `Storage driver [json]` noise to STDERR.
    - A stored value a property can't take is kept on save, but a validated save then reports a `required` field as
      missing; the message could say the stored value was unreadable.
    - A legacy multiple relation that still holds one plain id string reads as null on a `?array` property (kept on
      save); the JSON type reads the string.
    - A hydrated model saved as a new record under another id leaves out the fields it couldn't read; a stored `''`
      in an array field reads as null and is written back as NULL.
    - `TypeRegistry`'s boot check reads only `config/models/{key}.json`; admin files from other sources are checked
      on first use.
- ~~**Performance step 3, page cache (0.60):**~~ done in 0.60.0. The owner asked for it on 2026-11-08:
  "why does the save/publish not trigger the cache build already?". Spec:
  `docs/superpowers/specs/2026-11-08-page-cache-design.md`.
  - **What it is:**
    - Anonymous GET/HEAD responses are stored as one file per page under `storage/cache/pages/{release}`, with an
      index (`index.sqlite`).
    - Writes through the data layer invalidate exactly the pages that read what changed: records, listings,
      raw-SQL tables, and Folio addresses. That covers Folio, controllers, imports, cron and the publisher.
    - Pages that must be fresh are rebuilt after the response. A cron, `preflow pages:work`, takes what is left.
  - **Personal parts are live:**
    - Mark them with `{% live with {...} %}`, `@live` or `#[Live]`. They are stored as signed placeholders and rendered
      per visit.
    - The nonce, CSRF tokens and registered site values are swapped per request.
    - Signed-in visitors (the `preflow_auth` marker cookie, remember cookies, `Authorization`) always get live pages.
  - **Freshness.** It is per dependency and set by the developer:
    - `{% freshness 'next-visit' %}` and `{% freshness 'timed' every '12h' %}`, or `#[Freshness]`;
    - `{% expires … %}` for a fixed expiry;
    - a 4 h safety maximum age (`max_age`). A page past it is served once more while it is rebuilt.
  - **Never stored:**
    - responses that set cookies or have a session;
    - errors, and pages with a failed component or block;
    - drafts (working-copy renders);
    - mismatched htmx fragments;
    - pages that differ per visitor. "Verify" renders a page a second time as another anonymous visitor: the first time
      a key is stored in a release (`'first'`, the default), or always in tests (`'always'`).
  - **Folio:**
    - The Cache screen gets "Refresh a page" and "Refresh everything" (`folio.cache`).
    - Detail pages depend on their record and their address, not on the whole type.
  - **Measured on the language site, locally:** `/home` 4.8 ms with the cache off, 2.4 ms on a hit. A hit costs 0.28 ms
    of PHP in `handle()`. Live numbers follow with each site's switch-on.
  - **Upgrading:**
    - All `preflow/*` packages must be at 0.60 together; composer enforces it.
    - The cache is off by default and needs zero config: nothing changes until a site enables it.
    - With `auth.session.paths` set, a visitor who brings the session cookie now resumes it on every path.
    - Twig reserves block names starting with `__live_`.
    - Memos in site code must report their reads (`captureReads`/`replayReads`). Request-scoped modes, such as a
      preview, register `PageCache::isolate()`.
    - The skeleton limits sessions to its sign-in, admin and component paths, previews its error demo through
      `ErrorBoundary::preview()`, and posts `csrf_field()` in its forms.
  - **New APIs:**
    - data: `observe`, `reportRead`, `reportWrite`, `captureReads`, `replayReads`, `reportedStorage`;
      `QueryBuilder::byIds()` and `unreported()`.
    - core: `PageCache` (`changed`, `depends`, `within`, `expiresAt`, `refuse`, `carry`, `isolate`, `refuseWhen`),
      `PerRequestValues`, `Application::afterResponse()`, `RequestContext::$request`.
    - components: `ErrorBoundary::preview()` and `PreviewsFailure`.
    - auth: the `preflow_auth` cookie.
    - testing: `WebApplication::boot()`.
    - commands: `pages:work` and `pages:refresh`.
  - **Switching a site on:**
    1. Set `app.url` with its scheme, and a real `app.key`.
    2. Set `auth.session.paths` to where sessions start (sign-in, account, `/folio`, `/members`, `/--component`).
    3. Add `config/page_cache.php` with `'enabled' => true`. Use `'explain' => true` while auditing.
    4. Add a cron that runs `php preflow pages:work` every minute.
    5. Run `preflow optimize` (or `cache:clear pages`) in every deploy.
    6. Audit the site:
       - move personal parts into live parts;
       - register site tokens as per-request values;
       - make writes that go around the data layer call `PageCache::changed()`;
       - make site memos replay their reads.
    7. Run the suites with `WebApplication`.
    8. Check with `curl -I`: the second request answers `X-Page-Cache: hit`.
  - **Follow-ups:**
    - ~~`max_age` 0 or more than 24 h lets an unwritten page outlive its 24 h component tokens.~~ 0.60.1: `max_age` is at
      most 72000 (20 h, 4 h less than the tokens last); 0 means those 20 h, a longer value is refused.
    - ~~The verify log's header values have no length cap.~~ 0.60.1: a header, like the body, is logged as 120 bytes
      around its first difference.
    - preflow/testing's `WebApplication` runs no after-response work.
    - A `*` invalidation queues every full page (about 500 pages a minute through the cron).
    - Address tags keep accents.
    - LiteSpeed purge tags could sit on top as a server-level layer later.
- ~~**Performance step 2, structural boot (0.59):**~~ done in 0.59.0 (owner, 2026-11-08: "push through all step by
  step"; spec `docs/superpowers/specs/2026-11-08-perf-structural-boot-design.md`).
  - **What changed:**
    - The database connects on the first query, not at boot. A page that runs no query opens no connection, and a
      MySQL outage shows at the first query (the error page), not at boot.
    - `MediaLibrary::prefetch()` is one query. The JSON storage query cache is validated from its generation file and
      the type directory's mtime instead of a stat per record (a record file edited by hand needs `cache:clear`).
    - Folio sets up its admin (admin routes, nav, screens, customizations) on the first request under the admin prefix
      in a process, and always in the console. Public requests never build it. New core hooks:
      `Application::onRequest()`, `runningInConsole()`/`setRunningInConsole()`, `Container::resolved()`,
      `RouteCollection::insertBefore()`.
    - `preflow optimize` writes a per-release boot manifest, `bootstrap/cache/boot.php`: merged config, package data,
      the component/controller/translation/Folio catalogs, and the compiled public route sets (app, media, Folio
      frontend). `preflow optimize --check` lists drifted sources and exits 1. `cache:clear` and Folio's Cache
      "Clear all" remove it.
    - The manifest is read only in web mode, with the live `app.debug` off, and while `.env`,
      `config/folio/extensions.json` and `vendor/composer/installed.json` are unchanged (checked per request).
      Otherwise the request boots live, as in 0.58. The console never reads it. Data-stored settings are never in it.
  - **Measured on the language site** (Apache, OPcache, one request at a time, 1000 requests, average):

    | Page | 0.57 | 0.58 | 0.59 live boot | 0.59 with manifest |
    |---|---|---|---|---|
    | home | 15.3 ms | 5.0 ms | 4.4 ms | 3.9 ms |
    | /classes | 17.6 ms | 8.1 ms | 4.5 ms | 4.0 ms |
    | 404 | 10.0 ms | 3.9 ms | 2.6 ms | 2.0 ms |
    | robots.txt | 3.7 ms | 2.2 ms | 2.0 ms | 1.8 ms |

    PHP boot time with the manifest went from 1.41 to 0.90 ms. The target (robots.txt under 1 ms) is not reached
    locally: about 0.5 ms is PSR-4 file lookups without an optimised classmap (deploys install with
    `--optimize-autoloader`).
  - **Upgrading:**
    - core, data, routing, folio, media, members and devtools require each other at 0.59 (conflicts set). Release and
      update them together (`composer update 'preflow/*'`).
    - Without `preflow optimize` a site works as in 0.58. To use the manifest, add `/bootstrap/cache/` to
      `.gitignore` (the manifest holds the merged config, secrets included; its permissions follow `.env`), and deploy in
      this order: composer install → migrations → `folio:extensions:apply` → any `cache:clear` → `preflow optimize` →
      symlink → OPcache reset.
    - A broken extension screen or customization now fails the first admin request in web mode (in the console still at
      boot). A setup that threw runs again on the next admin request.
    - Pages, controllers or model files added on the server without a deploy are not routed until `preflow optimize`
      runs again. Environment variables set only by the web server are not watched; re-run `optimize` after changing
      one.
    - The admin prefix may be written with or without a leading slash, as before.
  - **Follow-ups:**
    - Folio's `_uploads`, `_account` and nav base still use the raw admin prefix; route them through `adminPath()` so
      `'path' => 'folio'` builds correct URLs there too (all sites use `/folio`).
    - `PublishingAppTest::test_dashboard_shows_a_running_records_progress_and_refreshes_itself` depends on the wall
      clock (`AdminController::stale()`); pin the clock.
    - A web-mode test lane for the whole suite; more COUNT skips in `findMany`.
    - Toggling an extension in Folio removes the manifest; the site boots live until the next deploy or `optimize`.
    - the collection site `/album`: batch `Catalog::object` per album item (site work).
    - Next: step 3, a page cache with rebuild on publish.
- ~~**Folio sidebar sections (0.57):**~~ done in 0.57.0 (owner, 2026-11-07: the sidebar "can get quite long"). Every
  section with a heading opens and closes (`<details>`, keyboard and no-JS friendly); the state is saved per person in
  Folio's user preferences (`POST {prefix}/_prefs/nav`, signed-in, CSRF) and rendered by the server, so nothing jumps;
  the current screen's section is always open; a closed section shows the sum of its badges. Sites set the sections
  that start closed in `config/folio.php` `'nav' => ['collapsed' => [...]]` (default `['library', 'settings']`; with no
  signed-in user nothing starts closed). On narrow screens the headings show as chips in the scrolling row.
  Clearing the admin language no longer deletes a person's other preferences. **Upgrading:** nothing to do;
  `AdminNav::add()` now refuses section keys outside `^[a-z][a-z0-9_-]{0,63}$` (the rule model files already had), and
  tests that inspect the sidebar markup see sections wrapped in `details.folio-nav-section`.
- **An analytics package (later, its own project; owner, 2026-11-06):** privacy-first visit counts, AI usage among
  them: per day, by AI agent family (OpenAI, Anthropic, Perplexity, Google…) and by what was fetched (HTML, Markdown,
  llms.txt), top pages per agent family, and one "everyone else" total. No paths with visitor data, no IP addresses,
  no cookies. Anti-goal: nightly crons or external tools to sift bots out (yii2-crelish's bot detection was a long
  dance that still let bots through); if humans can't be told from bots honestly in the request, the number isn't
  shown. Deferred from 0.55 so AI usage isn't measured half-baked.

---

## Lower Priority

- **Error page customization** — app-level 404/500 templates. None exists in core (renderers: Dev, Prod, Json), and
  preflow.dev's errors guide shows a middleware to render your own 404, which is a workaround: per the owner's rule,
  give apps a supported way and replace that section of the guide
- **Blade adapter testing** — stress test the Blade adapter like Twig was tested. Partly done: Blade runs
  `TemplateEngineContract` since 0.64; still missing a test of every template function and directive in Blade, like
  Twig's `PreflowExtensionTest`
- **Testing utilities** — component render assertions, HTMX action dispatch helpers, snapshot testing. Partly done:
  `ComponentTestCase::renderComponent()` and `TestResponse` asserts exist; still missing an HTMX action dispatch helper
  and snapshot testing

---

## Completed

### v0.41.2

- **Folio:** `Publisher::publish()` moves `_version` on (stored + 1) when the published content differs from the stored working copy (checked and unchecked), so an editor on the old version conflicts instead of writing old content back; unchanged content keeps it. See the 0.41 checklist

### v0.41.1

- **Compare-and-set fixes (data):** no lost update in a caller's REPEATABLE READ transaction (the version-keeping follow-up read locks, `Dialect::lockingRead()`); a stored `''` version matches 0 on SQL; a first save inserts before claiming (two concurrent first saves deadlocked on MySQL), a deadlock in the driver's own transaction is `false`; fallback notice, version key restored as absent
- **Folio:** `Publisher::publish($record, $by, $loadedVersion)` version-checked (form publish uses it; null unchanged), `discard()`/`restore()` likewise; `states($type, $ids)` in chunks of 500. See `docs/superpowers/plans/2026-10-27-release-0.41-checklist.md`

### v0.41.0

- **Compare-and-set saves (data):** `VersionedStorage::saveIfVersion()` on SQLite, MySQL (`UPDATE … WHERE _version = ?` in a transaction) and JSON (under a lock file); `DataManager::saveTypeIfVersion($record, $expected)` returns false when someone else saved in between
- **Folio:** `Publisher::saveDraft()` checks the version in the write (autosave and form save; responses unchanged); `Publisher::stateWith($working, $snapshot)`, `states($type, $ids)`; Folio needs data `^0.41`. See `docs/superpowers/plans/2026-10-27-release-0.41-checklist.md`

### v0.39.0

- **Editable site texts:** `config/folio.php` `translations.editable` lists a site's own text groups for the Translations screen; overrides of listed groups apply on every request (public pages, mails in the recipient's language, console) in every content language; placeholders are kept (dropping or adding one is refused); `site` and admin groups are skipped. i18n: `Translator::alwaysOverride()`
- **Screens:** `#[NavBadge]` on a `#[FolioScreen]` shows a sidebar count; screen labels may be translation keys (`site.screens.{name}`); `AdminNav::add()` takes an optional `$badge`
- **Type customizations:** a `#[FormPanel]` returning null is hidden; a `#[ListColumn]` may return `Link($label, $url)` (admin-relative or same-site paths only)
- **Constraints:** Folio needs i18n `^0.39` (conflict `<0.39`) for `alwaysOverride()` and conflicts with members `<0.39` (the lock calls)
- **Member lock, sessions and sign-in info:** `Members::lock()`, `unlock()`, `isLocked()` (readonly `locked_at`; a lock covers every sign-in path and ends the sessions), `endSessions()`, `resendConfirmation()` and a readonly `last_login_at` set on every sign-in. Folio: a Locked pill in the State column (never Declined), Lock, Unlock and Resend confirmation in the Decision panel (permission `members.decide`), "Locked since" and "Last sign-in" lines; no per-record action visibility in Folio yet, so the panel offers only the buttons that apply and a crafted POST where one does not apply is refused with an error flash and changes nothing. Run `preflow migrate` after updating (new columns `locked_at`, `last_login_at` on SQL storage; without it registration and sign-in fail). A locked account gets no confirmation or account mail (staff Resend and the public resend / repeated registration)

### v0.38.0

- **Members split:** `preflow/members` is a core without Folio and rbac (registration, confirmation, sign-in and
  out, forgot and reset, account page with self-deletion, mails, `members:prune`, `members:decide`); it requires
  auth, core, data, routing and i18n. Folio carries the members admin (Members list, Decision panel, Settings →
  Members, the rbac roles) and switches it on when `preflow/members` is installed. Without rbac an admitted member
  has `members.access`; the member state stays in `roles`
- **Admission:** the default is `AcceptOnConfirm`; `OwnerApproval` stays, opt-in; `ComposesName` lets an admission
  compose the stored name
- **`next`:** checked the same way for sign-in, registration and confirmation (kept with the pending account,
  hidden member field `next`); `next.allow` widens the default (member pages only)
- **Verified accounts:** `Members::register(…, emailVerified: true)` and `SignIn::in()` for a project that proved
  the address itself
- **Hooks:** `MemberEvents` (registered, confirmed, decided, signed in, deleting, deleted, deletion refused);
  `MembersSettings`, `MemberLinks`, `MemberDeleteCopies`, `PageLocale`, `MembersDefaults` interfaces and defaults
- **Self-deletion through the delete plan:** relations take `onSelfDelete` (data, `DeletedBy`); `Members::delete()`
  returns a `DeletionResult`; a refusal tells the owner (`notice_email`) and the member; Folio carries the deletion
  into published snapshots
- **Default landing at `GET {path}`**, mail `action` (a primary button) for the confirm, reset and existing mails
- **auth:** `RecordUser` / `RecordUserProvider` moved from Folio to `Preflow\Auth\Record` (old names aliased for one
  release), `RoleField`, `PasswordRehash` and `UpdatesPasswordHash` (rehash on sign-in for members and Folio staff),
  `Access::resolver()`; routing `RoutePattern`; data `ensureTypeRegistry()`
- Constraints: members requires auth, core, data, routing and i18n `^0.38` and conflicts with Folio `<0.38`; Folio
  requires auth and data `^0.38` and conflicts with members `<0.38`; core conflicts with auth `<0.38`

### v0.37.0 (prepared)

- **Folio admin in English, German and French:** every screen, field type, list, message, the JS texts and the staff mails behind translation keys (`folio.*`); each staff user picks the admin language (sidebar switch, account page; table `folio_user_preferences`), default `config/folio.php` `locale`, else `app.locale`; signed-out pages follow the browser; dates and numbers through `folio_date()` / `folio_number()`
- **Translations screen** (Settings → Translations, permission `translations.edit`): admins change any admin text per language (plain text; table `folio_translation_overrides`, compiled per locale, part of export/import); overrides never reach public pages
- **Model labels per language:** `label`, `label_singular`, field `label`/`help` and option labels as a map or through `site.types.…` / `site.fields.…` / `site.options.…` / `site.sections.…` keys; extensions name their group with `#[FolioExtension(translations: …)]` and may use keys as attribute labels
- **i18n:** packages declare groups in `extra.preflow.translations` (scope `admin` or `public`; replaces `addDefaults()` calls); a translator boots on every site; fallback chain user locale → site default → `en`; `TranslationOverrides`, `Labels`, `DerivedKeys`, `AcceptLanguage`, `LocaleNames`; `preflow translations:check`
- **Packages:** rbac (German now "du"), members (`members-admin` group for its admin texts; public `members` texts unchanged), codes and validation (`Message` objects, opt-in translator) translated
- Constraints: folio requires data, i18n and validation `^0.37`; members, rbac and codes require folio and i18n `^0.37`; validation requires i18n `^0.37`

### v0.36.0

- **Box presets:** `config/image.php` presets take `height` and `fit` (`cover` cuts the box's ratio around the focal point or a per-format crop, `contain` keeps the whole image, `max` is today's behaviour). Box files `{stem}-{preset}-{key}-{w}.{fmt}` are made on first request (and by `media:variants --all` / `warm_on_upload`); box preset names are validated at boot (`^[a-z][a-z0-9-]*$`, not `edit`, `preview`, `oriented` or `v<digits>`)
- **Image edits:** `MediaLibrary::rotate()`, `flip()`, `setCrop()`, `setFormatCrop()` for public JPEG, PNG, WebP and AVIF images. The original stays; a sRGB master `{stem}-edit-{key}.{ext}` is rendered and pages deliver it. Coordinates are percent (`CropRect`); a format crop is clamped to the image crop; edits survive replace and come back on restore. New reserved asset fields `rotation`, `flip`, `crop`, `crops`, `master`; `media_original_url()`
- **Backends:** `ImageBackend::writeEdited()` (third-party backends must implement it); `writeVariants()` jobs may carry a `crop`
- **Folio:** Edit image screen at `{prefix}/assets/{id}/edit` (`assets.edit`): rotate, mirror, crop with ratios, one cut per box preset (its `src` from `BoxPlan::srcWidth()`); Cropper.js 1.6.2 (MIT) vendored
- **Folio asset details on their own page:** `{prefix}/assets/{id}` with the tabs Details | Edit image and a guarded `back` link to the library view; with `HX-Request` it returns the full page, no longer a fragment (breaks htmx embeds of the panel)
- Constraints: folio requires media `^0.36`

### v0.35.0

- **Replace and versions:** `MediaLibrary::replace()` swaps an asset's file and keeps its id, alt, title, focal point, extra fields, usage, private flag and poster. The old file goes to `versions_dir` (`storage/media-versions`, a shared deploy dir) and is served only through `{private_url}/{id}/v/{n}` behind `PrivateFileAccess`. `restore()`, `deleteVersion()`, `prune()` and `media:versions:prune`. Version 1 keeps today's names, version n is `{id}-v{n}.{ext}`
- **Extra fields and `query()`:** `config/media.php` `fields` and `filters`; `MediaLibrary::query()` pages and filters by kind, search and extra fields (JSON and SQL)
- **Colour management:** CMYK (default profile FOGRA39L, CC0, `color_profile_cmyk` overrides) and Adobe RGB/other profiles convert to sRGB with Imagick and lcms; without Imagick such images get no variants and a note. sRGB images unchanged
- **PDF previews:** first page as sRGB PNG with Ghostscript 10.03.1 or newer (follows the CMYK output intent), else Poppler's `pdftocairo`; made in the upload's request (a PDF can add up to 30 s); `media_image()` on a PDF shows the preview once it exists; `media_preview_url()`, `media:previews`; private previews are gated
- **Backfills in chunks:** `media:previews`, `media:colors` and `media:variants` take `--limit=N` (and `--offset=N`) and print the command for the next chunk
- **Colours:** `color`, `palette` (1 to 8 real colours) and `text_color` per image; `media_colors()`, `media_color_class()` (CSS variables `--media-color`, `--media-color-rgb`, `--media-text`, `--media-palette-N`, nonce'd head style), `media_image(…, {placeholder: true})`, `media:colors`
- **Folio library UI:** replace, versions (open, restore, delete), extra fields and filters, PDF thumbnails, colour swatches with copy; export/import carries `media-versions/` (skipped with a notice where no `versions_dir` is set); staff with `assets.view` may open private files, previews and versions; earlier versions are staff only (members' `private_permission` never opens them, `PrivateFileAccess::VERSION` marks those requests)
- **Folio frontend:** `hreflang` and canonical URLs use `app.url` (when set and absolute) instead of the request host
- **Data:** in `whereAny()` groups, `=` with a null value compiles to `IS NULL` on SQL (missing or NULL fields, as on the JSON driver; `where(field, '=', null)` is unchanged); the query builder reads relations from the queried definition (code-defined types)
- Constraints: media requires data `^0.35`; folio requires media `^0.35`

### v0.34.1

- **List defaults for package types:** Members are listed newest first (`registered_at`) and filter by registered, email-confirmed and decided dates; Codes filter by expiry and form. A site's `extends` file can only change their columns, so the defaults live in the packages
- No constraint change

### v0.34.0

- **New package `preflow/media`:** the asset library moved out of Folio into a core of its own: `MediaLibrary`, `Asset`, `Variant`, `ImageTag`, image backends and variant generation, usage sources (`AssetUsage`, `AssetReferenceSource`; the core registers `PosterReferences`, Folio its record source), `MediaServiceProvider` with `config/media.php`, the routes for step-width variants and private files, `media_image()`/`media_url()` and `media:variants`. Requires core, data, view, routing and nyholm/psr7; suggests i18n. Folio is the UI adapter: library screen, asset field, and its `PrivateFileAccess` (permission/login from `config/folio.php` `assets.private_permission` / `assets.private_login`); without a binding the core denies private files with a 403
- **Core:** `MediaLibrary::delete()` refuses used assets (`AssetInUseException`), `discard()` for an asset created in the same request; with SQL storage `preflow migrate` creates the asset table (`media.table`), no Folio needed. `Container::registerProvider()` ignores a second registration of the same provider class, `hasProvider()` and `bound()` added, `DebugCollector::logDeprecation()`
- **Deprecated for one release (aliases):** `Preflow\Folio\Assets\…` class names (now `Preflow\Media\…`, `AssetLibrary` is `MediaLibrary`), `folio_image()`/`folio_asset_url()`, `config/folio.php` `assets` (fallback when `config/media.php` is missing, never merged), `folio:assets:variants`. `AssetReferences` stays in Folio
- Storage and output unchanged: `folio_assets` table, `public/media`, `storage/private-media`, `/media/…`, `/files/{id}`, frontend HTML byte-identical
- Constraints: media requires core/data/view `^0.34`; folio requires `preflow/media ^0.34` and conflicts with `<0.34`

### v0.33.0

- **Cache registry (preflow/core):** `Preflow\Core\Cache\CacheRegistry` with `CacheSource` (key, label, `stats()`, `clear()`), `DirectoryCache`, `FileCache`, `OpcacheStatus`; core registers `data.json` (when the JSON driver is in use) and `blade` (when the Blade engine is in use), a site registers its own. Clearing a PHP cache file also invalidates OPcache. Blade now compiles into `storage/cache/blade` instead of the system temp folder
- **`cache:list` and `cache:clear <key>… | --all` (preflow/devtools):** the bare `cache:clear` is deprecated (prints a note) and no longer empties `storage/cache` blindly; both commands boot the application (exit 1 when the boot fails), unknown `--` options exit 1
- **Settings → Cache (preflow/folio):** `{prefix}/cache`, permission `folio.cache`, a row per cache with Clear, Clear all, OPcache status with Reset; registers `folio.extensions` and `folio.blocks`
- No new package. Constraints: folio needs `preflow/core ^0.33`, devtools requires `preflow/core ^0.33` and conflicts with `<0.33`

### v0.32.0

- **Mail redirect (preflow/notify):** any transport takes `redirect_to` (address, comma-separated string or list); every email then goes only there (`RedirectTransport`): Cc/Bcc dropped, subject prefixed `[→ original To]`, a first body line (text and HTML) names To, Cc and Bcc. SMS passes through. For stage installations, e.g. `'redirect_to' => getenv('MAIL_REDIRECT_TO') ?: ''`
- No new package, no constraint change

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

- **New package `preflow/members`:** member accounts for a Preflow + Folio site, extracted from the member site. The `member` type in Folio with a waiting badge, a State column and a Decision panel (accept or decline with an editable message, the mail goes out first), the `pending` / `member` / `rejected` roles, registration with e-mail confirmation, sign-in with a remember cookie, forgot/reset, resend and an account page (password change, delete), all in `en`, `de` and `fr` with locale-prefixed routes; no account enumeration, throttled, no session cookie on public pages
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
