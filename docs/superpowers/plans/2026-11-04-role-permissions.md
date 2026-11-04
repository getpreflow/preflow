# New Permissions Reach Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seeded roles get permissions their seed list gains (once, editor removals stay), and permissions that appear with an update are announced to role managers — never granted automatically.

**Architecture:** rbac's `RoleStore::ensure()` becomes grant-once, remembering what it granted in a hidden `seeded_permissions` field. Folio gains an every-run `Extension::seed()` step (run by `folio:extensions:apply`), a `KnownPermissions` store (Folio internal table) that says which declared permissions are new, two generic customization hooks (`#[ListNotice]`, `#[DashboardNotice]`) and screens that stay out of the sidebar. rbac uses those for the notice, badge, dashboard line and the dismiss / add-to-role actions.

**Tech Stack:** PHP 8.4+, PHPUnit 11, Twig, Folio extensions/customizations, preflow/data (JSON + SQL storage).

**Spec:** `docs/superpowers/specs/2026-11-04-role-permissions-design.md`

## Global Constraints

- Release: 0.53.0. New APIs are "since 0.53"; rbac's composer constraint on preflow/folio becomes `^0.53 || @dev` with conflict `<0.53` (update rbac's `tests/ComposerConstraintsTest.php` docblock and values the same way 0.52 did for folio → validation).
- Never grant a framework/package permission to a role automatically; only a site's own seed list (`ensure()`) grants.
- An editor's removal of a seeded permission is never undone by a later seed run.
- First sight of a role without a record: record current permissions **plus** the seed list, add nothing.
- First run of the known list: every enabled declared permission becomes known silently, except `KnownPermissions::ANNOUNCE_ON_FIRST_RUN = ['redirects.manage']`.
- Disabled permissions (`PermissionRegistry::disabledReason() !== null`) are never new; they become new when enabled.
- Every new permission is announced, also when a role already covers it by a pattern; the notice shows which roles cover it and how.
- A permission leaves the notice only by Dismiss or by "Add to role" from the notice (then it is known for good). Granting it in the role editor does not clear it.
- Audience for notice, badge and dashboard line: users who can `rbac_role.edit`.
- "Add to role" obeys delegation exactly as the role editor does (run `RoleRules::beforeSave()` before saving).
- Every admin text in en/de/fr (Folio group `folio`, rbac group `rbac`); every POST form carries `<input type="hidden" name="_csrf_token" value="{{ csrf|default('') }}">`.
- Nothing may 500 on a site whose Folio tables are not migrated yet: notice, badge and dashboard line show nothing then.

## Review Focus

- holz-objekte stores `seeded_permissions` as a JSON **string** in a text field (its `config/models/rbac_role.json` extends the type with the same field) → read both array and JSON string; holz's records carry over (Task 1 test).
- An extension switched on after the baseline brings permissions declared but disabled at baseline time → they are announced then (Task 3 test).
- A staff user lacking the permission (or below the role) uses "Add to role" → refused with the role editor's message, permission stays in the notice, role unchanged (Task 5 test).
- A new content type brings five permissions → one notice line for the group with its five names, badge counts 5 (Task 5 test).
- SQL site where `preflow migrate` hasn't created `folio_known_permissions` → Roles list, dashboard and sidebar render without notice/badge, no 500 (Task 4 test for the hooks swallowing throwables; Task 5 test on an unmigrated SQLite app).

---

### Task 1: Grant-once `RoleStore::ensure()`

**Files:**
- Modify: `packages/rbac/src/RoleStore.php`
- Modify: `packages/rbac/src/models/rbac_role.json` (add hidden field)
- Test: `packages/rbac/tests/GrantOnceTest.php` (new; use `RbacAppScaffold` like `InstallTest`)

**Interfaces:**
- Produces: `RoleStore::ensure(string $population, string $name, string $label, array $permissions): void` (signature unchanged) and `RoleStore::FIELD = 'seeded_permissions'`. Field JSON: `"seeded_permissions": { "type": "text", "hidden": true, "label": "Seeded permissions" }`; stored value is a JSON string of a list (what holz writes); reading accepts an array too.

- [ ] **Step 1: Write the failing tests** (`GrantOnceTest`), each with a fresh app:
  - `test_a_new_role_records_what_it_granted`: ensure staff/editor `['page.*']` → role permissions `['page.*']`, seeded `['page.*']`.
  - `test_a_permission_added_to_the_seed_reaches_the_role_once`: ensure `['page.*']`, then ensure `['page.*','assets.*']` → permissions `['page.*','assets.*']`, seeded both.
  - `test_a_permission_an_editor_removed_stays_removed`: ensure `['page.*','assets.*']`; edit role to `['page.*']`; ensure same list again → `['page.*']`.
  - `test_a_permission_an_editor_added_stays`: ensure `['page.*']`; edit to `['page.*','ai.translate']`; ensure `['page.*','assets.*']` → `['page.*','ai.translate','assets.*']`.
  - `test_first_sight_of_a_role_without_a_record_adds_nothing`: role saved directly with `['page.*']` and no record; ensure `['page.*','assets.*']` → permissions `['page.*']`, seeded `['page.*','assets.*']`; ensure `['page.*','assets.*','members.*']` → adds only `members.*`.
  - `test_a_json_string_record_is_read`: role with `seeded_permissions` = `'["page.*"]'` (string); ensure `['page.*','assets.*']` → `assets.*` added.
  - `test_roles_of_two_populations_are_separate`: staff/admin `['*']` and members/admin `['members.access']` each keep their own list and record.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/rbac/tests/GrantOnceTest.php` — expect failures (no record field, existing role untouched).
- [ ] **Step 3: Implement** in `RoleStore::ensure()`: new role → create with `permissions` normalized and `seeded_permissions` = JSON of the same list; existing role → `$seeded` = record (array or JSON string) or, when missing, current ∪ listed (save the record, add nothing); otherwise `$new = listed − seeded − current`; save permissions `current + new` and record `seeded ∪ listed` only when something changed; reset `$this->roles`. Keep the docblock saying what it does now.
- [ ] **Step 4: Run** the new test and `vendor/bin/phpunit packages/rbac` — all pass (update `InstallTest` expectations only if they assert the old "never changed" for an added permission; say so in the report).
- [ ] **Step 5: Commit** `feat(rbac): ensure() grants a seed's new permissions once and remembers them`

### Task 2: Every-run `Extension::seed()`

**Files:**
- Modify: `packages/folio/src/Extension/Extension.php` (add `seed()`)
- Modify: `packages/folio/src/Console/ExtensionsApplyCommand.php`
- Modify: `packages/rbac/src/Extension.php` (role seeding moves from `install()` to `seed()`)
- Modify: `packages/folio/src/Members/Extension.php` (its three `ensure()` calls move to `seed()`)
- Test: `packages/folio/tests/Console/ExtensionsApplySeedTest.php` (new), `packages/rbac/tests/InstallTest.php` (extend)

**Interfaces:**
- Produces: `Extension::seed(Container $c): void` — default no-op; runs on every `folio:extensions:apply` for each enabled extension, after `install()` when that runs; must be idempotent. It prints nothing; apply's "nothing to do" output is unchanged.

- [ ] **Step 1: Write the failing tests**:
  - `ExtensionsApplySeedTest::test_seed_runs_on_every_apply_and_install_once`: a test extension counting calls; apply twice → install 1, seed 2.
  - `ExtensionsApplySeedTest::test_seed_of_a_disabled_extension_does_not_run`.
  - `InstallTest::test_a_role_added_to_config_roles_reaches_an_installed_site`: apply with config/roles.php `['staff' => ['editor' => ['page.*']]]`; change to `['page.*','assets.*']`; apply again (same rbac version) → editor has both.
- [ ] **Step 2: Run** them — fail.
- [ ] **Step 3: Implement** `seed()` in the base class and call it in `ExtensionsApplyCommand::execute()` for every enabled extension; move rbac's `install()` body into `seed()` (install keeps nothing or calls seed — pick one, no double run); move the Members `ensure()` calls into its `seed()`. Update the command's docblock and description ("…install once per version, seed every run").
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Console packages/rbac packages/folio/tests/Members` — pass.
- [ ] **Step 5: Commit** `feat(folio): extensions seed on every apply; rbac and members seed their roles there`

### Task 3: `KnownPermissions` store

**Files:**
- Create: `packages/folio/src/Permissions/KnownPermissions.php`
- Modify: `packages/folio/src/FolioServiceProvider.php` (singleton; add `KnownPermissions::definition()` to the TableSync list next to `AddressTable::definition()`; `afterMigrate('folio.permissions', …)` after `folio.tables`)
- Test: `packages/folio/tests/Permissions/KnownPermissionsTest.php` (new; JSON and SQLite)

**Interfaces:**
- Produces (`Preflow\Folio\Permissions\KnownPermissions`):
  - `const string TABLE = 'folio_known_permissions'`, `const array ANNOUNCE_ON_FIRST_RUN = ['redirects.manage']`
  - `__construct(DataManager $dm, PermissionRegistry $registry)`
  - `definition(): TypeDefinition` (key `_known_permissions`, storage `default`, idField `name`, field `known_at` string)
  - `fresh(): list<string>` — enabled declared names not known, sorted; on an empty store first records every enabled name except `ANNOUNCE_ON_FIRST_RUN` (baseline), then answers
  - `markKnown(list<string> $names): void`
  - `migrateLines(): list<string>` — for `afterMigrate`: `[]` or one line `New permissions to review in Settings → Roles: redirects.manage (Redirects), …`

- [ ] **Step 1: Write the failing tests**: first run on a registry with `page.view`, `redirects.manage` → `fresh()` = `['redirects.manage']` and `page.view` stored known; second call same; declare `events.view` → fresh includes it; `markKnown(['redirects.manage'])` → gone for good; a disabled permission is not fresh, after re-registering it enabled it is; `migrateLines()` text with label in parentheses; same flow on SQLite after `TableSync::sync()`.
- [ ] **Step 2: Run** — fail (class missing).
- [ ] **Step 3: Implement** with `DataManager::findIn/saveIn/queryIn` on `definition()` (pattern: `ExtensionState`); `known_at` = UTC `Y-m-d\TH:i:s\Z`.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Permissions` — pass.
- [ ] **Step 5: Commit** `feat(folio): known permissions — which declared permissions are new`

### Task 4: Folio hooks — `#[ListNotice]`, `#[DashboardNotice]`, screens outside the sidebar

**Files:**
- Create: `packages/folio/src/Extension/ListNotice.php`, `packages/folio/src/Extension/DashboardNotice.php` (method attributes, docblocks like `NavBadge`)
- Modify: `packages/folio/src/Extension/Customization/Customizations.php` (collect them; `listNotices(string $type): list<string>`, `dashboardNotices(): list<string>` — call each method, skip `null`/`''`, swallow throwables like `navBadge()`; dashboard notices only of types the user can view)
- Modify: `packages/folio/src/Extension/Attributes/FolioScreen.php` (add `public readonly bool $nav = true`), `ScreenRegistry`/`AdminNav` (no sidebar item when `nav` is false; routes and permission unchanged)
- Modify: `packages/folio/templates/admin/list.twig` (notices above the list), `packages/folio/templates/admin/dashboard.twig` (notices above the cards), `AdminController::index()`/`list()` pass them
- Test: `packages/folio/tests/Extension/NoticeHooksTest.php` (new), extend an existing ScreenRegistry/AdminNav test

**Interfaces:**
- Produces: `#[ListNotice]` / `#[DashboardNotice]` on a public non-static `fn(): ?string` returning **safe HTML**; template vars `list_notices` / `dashboard_notices` (list of strings, printed `|raw`); `FolioScreen(nav: false)`.

- [ ] **Step 1: Write the failing tests**: a customization with both hooks renders its HTML on the type's list and the dashboard; one throwing renders nothing and the page is 200; a user without `{type}.view` gets no dashboard notice; a `FolioScreen(nav: false)` answers at its path but is not in the sidebar.
- [ ] **Step 2: Run** — fail.
- [ ] **Step 3: Implement** as described.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio` — pass.
- [ ] **Step 5: Commit** `feat(folio): list and dashboard notices from customizations; screens outside the sidebar`

### Task 5: rbac — the notice, badge, dashboard line and actions

**Files:**
- Create: `packages/rbac/src/Types/NewPermissions.php` (`#[FolioType('rbac_role')]` customization: `#[NavBadge]`, `#[ListNotice]`, `#[DashboardNotice]`)
- Create: `packages/rbac/src/NewPermissionsScreen.php` (`#[FolioScreen(path: 'rbac_role/_new-permissions', label: 'rbac.new.title', permission: 'rbac_role.edit', nav: false)]` with `#[Post('/dismiss')]` and `#[Post('/grant')]`)
- Create: `packages/rbac/src/templates/new-permissions.twig`, `packages/rbac/src/templates/new-permissions-dashboard.twig`
- Modify: `packages/rbac/lang/{en,de,fr}/rbac.php` (keys under `new.`)
- Modify: rbac README (section "New permissions"), `packages/rbac/composer.json` + `tests/ComposerConstraintsTest.php` (folio ^0.53)
- Test: `packages/rbac/tests/NewPermissionsTest.php` (new)

**Interfaces:**
- Consumes: `KnownPermissions::fresh()/markKnown()` (Task 3), hooks (Task 4), `RoleStore` (Task 1), `RoleRules::beforeSave(DynamicRecord)` (delegation), `PermissionCoverage::covers()`, `Pattern::matches()`.
- Produces: POST `{prefix}/rbac_role/_new-permissions/dismiss` (`names[]`) and `/grant` (`names[]`, `role` = role uuid) → redirect back to `{prefix}/rbac_role` with a flash/notice text.

- [ ] **Step 1: Write the failing tests** (`NewPermissionsTest`, staff signed in as in `DelegationTest`):
  - badge shows the count for a role manager, nothing for a user without `rbac_role.edit`;
  - the Roles list shows the notice: per group one line (`Redirects` with `redirects.manage`; a new type's five permissions as one line), each with the roles covering it and how (`Administrator (*)`, `Content admin (assets.*)`);
  - the dashboard shows "1 new permission to review" linking to `{prefix}/rbac_role` for a manager only;
  - dismiss → gone from notice, badge, dashboard; stays gone after reload;
  - grant to a role → role gains the exact name, permission known;
  - grant by a user who doesn't hold the permission → refused with the role editor's message, role unchanged, still fresh;
  - granting via the normal role editor leaves it in the notice, now listing that role;
  - on a SQLite app without `preflow migrate`, `/folio/rbac_role` and `/folio` answer 200 without notice.
- [ ] **Step 2: Run** — fail.
- [ ] **Step 3: Implement**: group by `PermissionRegistry::all()[name]['group']`, label from the registry (translated as Folio's permission chips are); coverage per role = role's patterns that `Pattern::matches()` the name; grant loads the role, adds the name, runs `RoleRules::beforeSave()` (catch `ValidationException`/`Refused` → flash the message), saves, `markKnown`. Texts (en): title "New permissions", intro "These permissions came with an update. They are not given to any role automatically.", "Already in:", "Add to role…", "Add", "Dismiss", dashboard "{count} new permission(s) to review in Roles". de/fr equivalents.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/rbac packages/folio` — pass.
- [ ] **Step 5: Commit** `feat(rbac): new permissions — notice on Roles, sidebar badge, dashboard line, dismiss and add to role`

### Task 6: Docs and upgrade notes

**Files:**
- Modify: `packages/folio/README.md` (Extensions: `seed()`, `#[ListNotice]`, `#[DashboardNotice]`, `FolioScreen nav`; Permissions: known permissions, `folio.permissions` migrate line), `packages/rbac/README.md` (grant-once, `seeded_permissions`, first sight rule), `ROADMAP.md` (mark "Grant-once role seeding" done in 0.53.0 with **Upgrading**: SQL sites run `preflow migrate`; extensions that seed roles in `install()` should move that to `seed()`; holz-objekte can drop `grantOnce()` and its `rbac_role.json` extension (records carry over); BG Genius moves its `ensure()` calls to `seed()`; check seeded roles once, since a permission listed before 0.53 that never reached a role stays missing)
- Test: none beyond the suite.

- [ ] **Step 1: Write the docs** as listed.
- [ ] **Step 2: Run** `vendor/bin/phpunit` (full) and `node --test packages/folio/tests/js/*.test.mjs` — pass, deprecation count unchanged (99).
- [ ] **Step 3: Commit** `docs: new permissions reach roles`
