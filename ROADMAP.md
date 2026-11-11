# Preflow Roadmap

> Last updated: 2026-11-11 (v0.65.0)

## Current State

**v0.65.0** — 6838 tests, 25 packages, production-validated via BGGenius stress test (admin CRUD, public forms, HTMX inline validation, custom validators with DB/rate-limiting).

The validation package has been stress-tested end-to-end: model attributes, standalone validation, custom container-resolved rules, ErrorBag with field-level template display, HTMX blur-based inline field validation, and DataManager auto-validation on save.

---

## Next Up

### BG Genius move into Folio — framework work, in this order (owner, 2026-10-26)

BG Genius's Phase 1 is live; lists (0.29), screen assets (0.30), cache (0.33) and media (0.34–0.36) are done.
What its Phases 3–5 still need from the framework, with the matching follow-ups from the
holz-objekte.org port folded in (see "Follow-ups from the holz-objekte.org port" below):

1. ~~**Members split into a core and a Folio adapter**, together with the members follow-ups BG Genius needs:
   `next` through registration and confirmation (host invitation → register → confirm → into the group), a
   landing page at `/konto` (base of the member area / host group view), rehash on sign-in (members and Folio
   staff; migrated BG Genius admin users), member self-deletion through the delete plan or hooks (progress, group
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
   - **holz-objekte.org album on the PDF part** (site work, not framework: `app/Album/AlbumPdf.php` still uses mPDF
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

**Audit of all packages against this rule** (after the BG Genius steps, or alongside when a package is touched):
go through every package (Folio, media, rbac, i18n, auth, data, notify, validation, devtools, …) for features that
came from one project's needs, and turn each into a hook, a config option or an extension example, or move it into
the project; note breaking changes for the consumers. Status (checked 2026-11-11): members was audited in 0.38
(findings F1–F8 in `docs/superpowers/plans/2026-10-26-release-0.38-checklist.md`, waiting for the owner); no record
for codes, Folio, media, rbac, i18n, auth, data, notify, validation or devtools.

Useful during BG Genius Phase 3, not blocking: route order by specificity (partly done, see "Routing, core and
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

### Reference descriptions (owner, 2026-11-11; after docs phase 3, together with phase 4)

On preflow.dev's reference pages, many entries have an empty Description column; one example is the REST APIs topic: `TokenAuthMiddleware`, `PersonalAccessToken`, `TokenGuard`, `JsonBodyMiddleware`. The description is the first sentence of the entry's docblock in the framework. The website's `php preflow docs:check` counts what's missing. At v0.65.0, 3033 of 5499 entries have no description:
- by kind: 1933 methods, 610 properties, 172 classes, 114 constants, 75 config keys, 58 enum cases, 31 template functions, 25 interfaces, 10 enums, 3 field types and 2 traits;
- by package, the largest: folio 636, core 395, data 329, view 310, media 223.

`docs:check --verbose` names each one.

Plan:
1. **Classes, interfaces, enums and traits first.** These are about 210 entries, and they are what the topic pages list. Then the config keys and template functions, then public methods. Skip properties, constants and cases whose name already says it all; private API never appears in the reference.
2. **Write each description like the existing good ones:** one plain sentence that says what the thing is for, not how it is implemented, e.g. "CORS for the API paths (config/api.php `cors`)." Do one package per commit, and check it on the website: `docs:reference`, then the topic pages.
3. **Do it together with phase 4**, which shrinks the package READMEs. The detail a README drops goes into the docblocks and the guides, so it isn't lost.
4. **Ratchet, so it stays done.** Once a kind is complete, `docs:check` fails on a new entry of that kind without a description, instead of only warning. Start with classes, interfaces, enums, commands and config keys.

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

## Follow-ups from the holz-objekte.org port

Found while porting holz-objekte.org (2026-10-26). Each has a site-local workaround there, described in its
`docs/framework-followups.md` with how to remove it once the framework has the feature. Checked against v0.37.0:
all of these are still open. Already done: the expired-session CSRF handler (0.31) and the copyright hook
(`#[AfterSave]` on an extension type, no framework change needed).

### Security and data integrity

- ~~**Rehash on sign-in (members, Folio staff login):**~~ done in 0.38 (`PasswordRehash`, `UpdatesPasswordHash`): after a successful `verify()`, call `needsRehash()` and store a fresh hash (old `$1$` MD5-crypt and low-cost bcrypt hashes are never upgraded today). `needsRehash()` exists in `NativePasswordHasher`; no login calls it
- ~~**CSRF without a session (core):**~~ done (`CsrfMiddleware` checks every POST, PUT, PATCH and DELETE: the browser's origin first (`Sec-Fetch-Site`, else `Origin`, else `Referer`; a refused origin is refused with any token), then the session token, or without a session a stateless token signed with `app.key` (24-hour slots, current and previous) that `csrf_field()` / `csrf_token()` print on public pages; `#[CsrfExempt('why')]` on controllers and routes; `config/csrf.php` `exempt`, `trusted_origins`, `sign_in_paths`; a sign-in form left open past the session's end still signs in (Folio's and members' logins register themselves), any other expired form is sent back with a warning and its fields kept (`OldInput`), never applied). **Upgrading:** unsafe requests without a session were unchecked until now. Sites with server-to-server POSTs (webhooks, payment providers, cron over HTTP) mark the route `#[CsrfExempt('…')]`, or list file routes and paths outside the router in `csrf.exempt`, before upgrading; browser POSTs coming from other sites (a payment provider's return URL that posts back, OAuth/SAML `form_post` callbacks) carry `Sec-Fetch-Site: cross-site` and are refused now even on public paths, so such routes need `#[CsrfExempt('…')]` too; behind a TLS-terminating proxy set `app.url`, and set a real `app.key`. Release checklist, by hand: smeyer.me's contact form, holz-objekte's like and contact forms, and every site's payment return and OAuth/SAML callback routes (cross-site POSTs). holz-objekte can then replace its `App\Http\FormToken` with `csrf_field()`
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
- **Folio hook bridge for member self-deletion:** a member's own deletion runs `MemberEvents` but not Folio's record hooks on `member` (Folio already unpublishes the member's snapshots on `DELETED`, `PublishedMemberDeletion`). Bridge them in Folio's members adapter: `DELETING` runs `#[BeforeSave]`/`#[BeforeDelete]` and can refuse, `DELETED` runs `#[AfterSave]`/`#[AfterDelete]`. Before holz-objekte.org drops its workaround

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
  nothing. Extensions that seed roles in `install()` move that to `seed()`; BG Genius moves its `ensure()` calls there.
  holz-objekte.org can drop `grantOnce()` and delete its `rbac_role.json` extension in the same commit as the upgrade
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
  `composer test:serial`: holz-objekte.org ~33 min → 3:02 / 5:55 (MySQL; 950 tests), BG Genius 6:15 → 0:59 / 3:49,
  smart-stack.works 87 s → 16 s / 72 s (paratest `--functional`), smeyer.me 59 s → 4.6 s / 12.5 s; no test skipped.
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
  - **Measured on languagecorner** (Apache, OPcache, one request at a time), 0.57 → 0.58:

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
      smeyer.me, time0-web have one), so visitors see the generic page.
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
    - Move holz-objekte.org's objects and BG Genius's games to classes, later and per type (`make:model --from-type`).
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
  - **Measured on languagecorner, locally:** `/home` 4.8 ms with the cache off, 2.4 ms on a hit. A hit costs 0.28 ms
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
  - **Measured on languagecorner** (Apache, OPcache, one request at a time, 1000 requests, average):

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
    - holz-objekte.org `/album`: batch `Catalog::object` per album item (site work).
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
