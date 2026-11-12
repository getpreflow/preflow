# Package audit fixes — design

Status: owner decisions in; for the owner's review (2026-11-12). Source: the audit of all packages against the
abstractions-only rule, `.superpowers/audit-2026-11-12/` (summary.md and seven reports, file:line
evidence in each). Finding IDs below (I18N-1, RBAC-1, …) refer to those reports.

## Intent

Preflow holds abstractions, interfaces, hooks and generic mechanisms; one project's features live in
that project. Every capability lives in a Folio-free package core with a public API; Folio is the admin
UI on top. The audit found two project features (the BG Genius "game names" prompt line and the BG
invitation mail), and a larger number of places where the structure breaks the rule: capabilities that
exist only inside Folio, packages that name each other's internals, and gaps that every project fills
with the same app code. The owner's decision (2026-11-12): **fix all of it**.

Owner's words, which set the direction:

- Blocks become their own package, which also makes it clearer how to extend them and add block types.
- rbac's core mechanisms work without Folio, with commands shipped in the package.
- The codes mailer is BG-specific. notify holds whatever is needed to send anything; the invitation mail
  becomes a BG implementation on top of notify.
- Members' screens and mails are generalised. Developers will customise them or replace them
  completely. Mails never mention Folio in any way.

## Goals

1. No project feature or project default in any package.
2. Every capability reachable without Folio, through a public, container-bound API. Folio calls these
   APIs; it holds no rule of its own that a non-Folio caller would skip.
3. No package names another package's classes, keys or files. Where it needs something from another
   package, it defines an interface or a registration hook, and the other package plugs in.
4. The gaps the starters and sites fill with copied app code are closed in the framework, and that app
   code is deleted from the starters.
5. All six sites move to each release (as always: upgrade, test, back up, deploy), and the preflow.dev
   docs say what each release does.

## Non-goals

- No new features beyond what the findings need.
- No redesign of Folio's admin screens.
- Docs phase 4 (shorter READMEs, reference descriptions) stays its own step after this project.

## Anti-goals (a failure even if it works)

- A workaround instead of the structural fix (owner's standing rule).
- A Folio class that a new core package still has to import.
- Sites left on an older release, or a site's behaviour changing without a release note.

## Decisions

### D1. New package `preflow/blocks` (owner)

Moves out of `packages/folio/src/Block`: `Block`, `BlockComponent`, `BlockDefinition`, `BlockField`,
`BlockRegistry`, `BlockRenderer`, `BlockConfigException`. Namespace `Preflow\Blocks`.

- Its Folio couplings become interfaces or move with it. `BlockRenderer` uses Folio's
  `ContentLocale` and `SafeRichText`; the locale comes from i18n/core, and rich-text cleaning moves to
  a Folio-free place (blocks or view) that Folio also uses.
- Packages and sites declare block types through `extra.preflow.blocks` (composer) and
  `config/blocks.php`; the README and a docs guide show a block type from scratch.
- Folio keeps the block editor (the blocks field type, its JS and templates) and calls `preflow/blocks`.
- The old `Preflow\Folio\Block\*` names stay as class aliases for one release (the
  `legacy-aliases.php` pattern), with a deprecation note.

### D2. New package `preflow/publishing` (owner, 2026-11-12)

The owner named blocks. The audit (STARTERS-1) found the same for publishing: drafts, publish,
snapshots, revisions, scheduling and previews exist only in `Preflow\Folio\Publishing`, and every site
imports them (holz 19 app files, smart-stack 16, …). By the rule this is a capability, so it gets a
Folio-free home. A package of its own (rather than inside `preflow/data`) keeps data small and matches
blocks.

- Moves: `Publisher`, `PublishedModels`, `PublishState`, `PublishedCopies`, `ShadowTypes`, `Revision`,
  `Scheduler`, `VersionConflict`, `PreviewLinks`, `PreviewTarget`.
- Publisher's Folio couplings become interfaces that Folio implements: whether a type publishes,
  record validation, address changes (redirects), review-index refresh, and the "translations
  incomplete" message.
- Whether a type publishes (my call): an interface. Its default implementation reads the explicit
  `"publishing"` flag of the type definition; Folio binds its own, which keeps today's derived default
  (a type with a `frontend` route, and every singleton, publishes unless it says otherwise,
  `TypeCatalog.php:195`). No site type changes behaviour.
- Class aliases for one release, as in D1.

### D3. One record write path: `RecordWriter` and `RecordDeleter` (FOLIO-1)

Saving a record fully (hooks, validation including blocks, draft or live save, the schedule rule,
list position, address sync, review index) is today private to `AdminController`. It becomes a public,
container-bound `RecordWriter` (create, save, reorder, set a field) next to a container-bound
`RecordDeleter`, in `preflow/publishing`. The save and delete hooks are an interface; Folio plugs its
`#[BeforeSave]`/`#[AfterSave]`/delete hooks in through it.

- `AdminController`, `PublishingController`, the bulk actions and `FolioTextSource` (AI translation)
  call the writer. The "a save by someone who may not publish cancels a pending schedule" rule then
  exists once (today four copies).
- Release note: AI-translation writes start running the type's save hooks, as admin saves do.
- Sites that write records themselves (holz, BG) can call the writer and get the same behaviour.

### D4. rbac: Folio-free core with its own commands (RBAC-1, STARTERS-2, owner)

- `preflow/rbac` no longer requires Folio. Its core: `RoleStore` with its own table migration,
  the resolver (a small "roles installed?" check instead of Folio's `ExtensionState`),
  `PermissionCoverage`, the staff-account rules (delegation, lock-out, unique e-mail; a core
  exception instead of Folio's `Refused`), and a `StaffAccounts` service: create, invite, reset,
  disable, enable.
- The staff record type and table belong to rbac's core. The staff guard name comes from rbac's
  config (Folio follows it), not from Folio's `StaffUsers`.
- rbac is on when it is installed and its provider runs (my call). Folio's extension toggle switches
  only the admin screens. All four sites with rbac have the extension enabled today, so nothing
  changes for them; the release note says it.
- Known permissions (my call): Folio's `KnownPermissions` registry moves to auth, where packages
  declare their permissions; rbac's commands and Folio's screens both read it, and granting an
  unknown permission is refused.
- Staff passwords without Folio (my call): the token logic of Folio's `StaffPasswords` (invite and
  reset tokens) moves to rbac's core, the mails go through notify (D5). The page where a staff member
  sets a password is UI: rbac core asks a `StaffLinks` interface for its URL, Folio binds its
  set-password page. Without a binding, `invite` and `reset` refuse with a clear message, and the
  command `staff:password <email>` sets a password directly.
- Roles in starter-folio (my call): it switches from config roles (`preflow/roles`) to rbac, with
  seeded roles, so the starter shows the full package. rbac's staff rules work on `RoleStore`.
- Commands shipped in rbac: roles (list, create, grant permissions, delete) and staff accounts
  (create, invite, reset, disable, enable, list), plus the existing config-staff import.
- The Folio part (extension, `#[FolioType]` hooks that call `StaffAccounts`, the two field types, the
  new-permissions screen, assets, admin texts) moves into Folio, like media's library.
- starter-folio uses rbac's staff type and command, and deletes its own `Staff` model, both staff
  migrations and `staff:create`.

### D5. Mail lives in notify (CODES-1, members F2/F3, owner)

notify gets a mail layer that every package uses to send a composed mail:

- a message made of a subject, paragraphs and an optional action (label and URL),
- an HTML layout with neutral styling, replaceable by the site (config), with an accent colour from
  config and no colour default taken from a site,
- the plain-text part made from the same content,
- absolute-link checking (links built from `app.url`, never the request), header-safe one-line
  values, auto-linked URLs, translation in the recipient's language.

members (`MemberMailer`), Folio (`StaffMailer`) and rbac's staff mails use it. The two mail layouts
in `members` and `codes` go.

`codes` loses `CodeMailer`, its mail texts and layout, and its dependencies on notify, view and i18n.
BG Genius builds its invitation mail in `app/` on notify's mail layer, with its own texts (the `du`
wording it already overrides).

### D6. Members screens and mails are neutral and replaceable (members F1–F8, FOLIO-2, owner)

- Pages: neutral, minimal styling with documented CSS custom properties; the smart-stack tokens,
  spacing and the `.members-code`/`.members-or` rules leave the package (smart-stack keeps them in
  its own templates). A site overrides one page (`resources/members/<page>.twig`, as today) or replaces
  a page completely with its own route and controller on the members core API; the README and a docs
  guide show both.
- Mails: built on D5; each mail's texts and template can be overridden; no mail names Folio. The owner
  mails link to a decision URL from `MemberLinks` (Folio binds it; without a binding the mail has no
  link and names `members:decide`) with a neutral label (e.g. "Decide here"); the
  placeholder `{folio_url}` becomes `{decision_url}` (holz's Italian override is updated with it).
- Mail accent: no site colour as default (smart-stack.works sets its red `#d42a34` in its config).
- `members-admin` texts move to Folio, the group name stays.
- `Links::folioMember()` and its Folio prefix are removed (smart-stack's test changes with it).
- Owner decisions move into the core: `Members::accept($member, $message)` and
  `Members::decline($member, $message)` with the allowed transitions, mail before role, and revoking
  remember-me sessions on decline. Folio's decision panel and `members:decide` call them (FOLIO-2).
- The refusal mail names pointing types by label (F7); `MembersConfig`'s docblock is corrected (F8).
- Consent stays required (F6, as decided in 0.38).

### D7. Rules move from Folio into the cores (FOLIO-3/CODES-3, FOLIO-4, FOLIO-5)

- codes: `Codes` exposes the readable-code check and an update for label, expiry and max uses with
  the immutability rule; Folio's code admin calls them (so purpose `ttl` and `created_at` apply).
- Redirects: the write rules (no chains, page over exact entry, target not in its own area, …) move
  to a routing-level rules service next to `RedirectStore`; Folio's controller maps its errors.
- Translation overrides: the check (offered locale, editable key, length, placeholders) moves to i18n
  next to `TranslationOverrides`.
- Asset alt texts become an i18n `TextSource` in media; Folio's endpoint and whole-library runs use it.

### D8. Boundary clean-ups (CORE-1, VIEW-1, DATA-1, MEDIA-1, I18N-1, DEVTOOLS-1, CODES-2)

- core reads composer `extra` namespaces that packages register, not Folio's keys by name; the boot
  manifest's runtime files are registered by the package that owns them (Folio registers
  `config/folio/extensions.json`). `extra.folio.*` keeps working as the Folio namespace.
- view defines an image-source interface for `pdf_image` that media binds, and the variant-pending
  header constant lives in view (or core) and media uses it.
- `matrixable` and `views` leave `TypeDefinition`'s typed properties; Folio reads them from the type
  schema.
- media outputs `media-*` class and key names.
- The translation prompt drops "or game names".
- `auth:user` gets a neutral default prefix (`ADMIN`) or requires one; Folio's docs give
  `FOLIO_ADMIN`.
- READMEs, docblocks and examples use neutral names (no smart-stack, BG, holz names; no GameCard,
  WoodObject, Meeple, /spiele/).

### D9. Gaps closed in the framework (STARTERS-3 to 6)

- data: a declared field index is added to existing tables on a later sync, and a type can declare
  composite indexes. starter-folio deletes its hand-written index step; holz can drop its own.
- view/twig/blade: a template function for the chosen SEO description (and the meta tag on request).
  starter-folio and preflow-website drop their own `page_description()`.
- A unique-slug helper on top of `Slug::clean()`; the starters use them (one transliteration
  everywhere).
- Route middleware takes a guard argument, so a second guard needs no wrapper class; the safe
  return-path check (`SafeNext`) moves from members to auth or core. The starters drop their wrappers.

## Releases (my call)

Each is a minor release with release notes and "Upgrading" steps, all six sites upgraded and deployed,
and the docs on preflow.dev updated in the same step.

1. **0.67 — clean-ups:** D8.
2. **0.68 — mail and members:** D5, D6, codes part of D7 (CODES-3).
3. **0.69 — rbac:** D4.
4. **0.70 — content:** D1, D2, D3, and the redirect, override and alt-text parts of D7.
5. **0.71 — gaps:** D9.

## Constraints

- Owner's rules: no workarounds; framework = abstractions only; Folio is the UI adapter.
- Class aliases for moved public classes for one release, then removed; renamed text placeholders and
  removed deprecated methods change without aliases (all affected sites are updated in the same
  release).
- Tests: full suite, `composer test:mysql`, `composer test:starters`, each site's suite; one test run at
  a time.
- Release runbook as in `CLAUDE.local.md` (split runs, Packagist, six sites, backups).

## Left to the builder (the plans)

Exact class and method names beyond those above, file layout inside the new packages, how the
save-hook and address interfaces are shaped, and the order of tasks inside each release.
