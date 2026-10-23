# Folio Staff Accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff accounts as Folio records with delegated roles (nobody grants what they don't hold), a full account lifecycle and no lock-out, released as v0.27.0; existing sites unchanged unless they opt in.

**Architecture:** preflow/auth gains a chain provider and an "active" check; preflow/rbac gains the coverage rule (roles and staff), the `staff` model part with lifecycle record actions and an import command; Folio's login gains throttle, remember-me, forgot/reset and set-password pages for record users.

**Tech Stack:** PHP 8.4, Preflow auth/rbac/folio/notify/i18n/core Throttle, Twig, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-24-folio-staff-accounts-design.md`

## Global Constraints

- Population `staff`; type key and table `staff`; storage alias `staff` else `default`; transfer off (via `users`).
- Staff fields: `email` (unique, lowercased), `name`, `password_hash` (hidden), `roles` (`rbac_roles`, population staff), `active` (boolean, default true), `auth_epoch` (hidden, readonly, "0"), `last_login_at` (readonly), `created_at` (readonly).
- Token purposes: `folio.invite` (7 days), `folio.reset` (1 hour). Throttle purposes: `folio.login`, `folio.forgot`, `folio.password`.
- Delegation error copy (en): "You can't grant permissions you don't have: {patterns}" / "You can't change an account with roles you can't grant." / "At least one account must keep the right to manage staff and roles." (de and fr translations in the lang files).
- Generic answers: the Folio login error, forgot and set-password responses don't reveal whether an e-mail exists, is a config user, or is inactive.
- A site without `model:staff` enabled sees no behaviour change (same SQL, routes, pages, permissions); smeyer.me's and smart-stack's suites pass unchanged.
- No delegation bypass server-side: every write path (form save, record actions, matrix field, import command, delete) applies the rule; UI disabling is a convenience only.
- Full suite `vendor/bin/phpunit`, `composer test:mysql`, smeyer.me and smart-stack suites green; deprecation counts don't grow. Style as the codebase; `\Pdo\Mysql::*` only.

## Review Focus

- Crafted POSTs that try to grant `*` through the matrix, the roles field, a record action or by editing one's own account.
- The last staff manager tries to disable, demote or delete themselves or the last other manager.
- A disabled account with a live session and a remember-me cookie.
- Unknown e-mail vs config user vs inactive record user on login/forgot: identical answers.
- A site that never enables `model:staff`: identical SQL, routes and responses (characterisation test on the Folio login page and permission middleware).

---

### Task 1: auth — chain provider and active users
**Files:** `packages/auth/src/ChainUserProvider.php` (new), `packages/folio/src/Users/RecordUserProvider.php` (+ `active` field config), `packages/core/src/Application.php` (provider config `class: chain`, `providers: [names]` — follow how other providers are built), tests in packages/auth/tests and packages/folio/tests/Users.
**Produces:** `ChainUserProvider` implementing `UserProviderInterface` (findById/findByCredentials try each in order; first hit wins); `RecordUserProvider` config `active` (field name) — inactive records never resolve.
- [ ] Tests: order, first-hit, inactive never resolves (by id and by credentials), unchanged behaviour without `active`. → FAIL → implement → PASS → commit `feat(auth): chain user provider; record users can be inactive`.

### Task 2: rbac — the delegation rule for roles
**Files:** `packages/rbac/src/PermissionCoverage.php` (new), `src/Types/RoleRules.php`, `src/Fields/PermissionsFieldType.php` (+ `rbac.js` disabling), `src/RbacServiceProvider.php` (drop the staff refusal), lang en/de/fr if rbac has none yet (use i18n addDefaults).
**Produces:** `PermissionCoverage::covers(list<string> $held, string $pattern): bool`, `coversAll(list<string> $held, list<string> $patterns): bool`, `held(): list<string>` for the current staff user (from Access/resolver).
- [ ] Tests: coverage table (`*`, `x.*`, exact, non-covered); RoleRules refuses adding an uncovered pattern and editing a role the editor doesn't fully cover (crafted POST); lock-out rules unchanged; matrix renders disabled checkboxes for uncovered permissions. → FAIL → implement → PASS → commit `feat(rbac): delegation — nobody grants permissions they don't hold`.

### Task 3: rbac — the staff type and its rules
**Files:** `packages/rbac/src/models/staff.json` (fields per Global Constraints; prepareModel for storage alias), `src/Types/StaffRules.php` (BeforeSave/BeforeDelete), `src/Fields/RolesFieldType.php` (disable roles not covered), Extension version bump.
**Produces:** model part `model:staff`; `StaffRules` enforcing spec "Delegation" bullets 2 and the lock-out rule.
- [ ] Tests: assign/remove a covered role OK, uncovered refused; editing an account above you refused; self-demotion of the last manager refused; disabling/deleting the last manager refused; a site without the part enabled unchanged. → FAIL → implement → PASS → commit `feat(rbac): staff accounts as a Folio user type with delegated roles`.

### Task 4: Folio login — throttle, remember-me, forgot and set-password
**Files:** `packages/folio/src/Http/AuthController.php`, new `packages/folio/src/Http/PasswordController.php`, templates `admin/login.twig` (+ remember checkbox when the guard has `remember`), `admin/forgot.twig`, `admin/password.twig`, Folio lang (en/de/fr) for the new strings, mails via notify (`Notifier::sendLater`), routes in `FolioRoutes` (public, throttled).
**Produces:** routes `GET/POST /folio/forgot`, `GET /folio/password/{token}`, `POST /folio/password`; service `StaffPasswords` (issue invite/reset token, mail, consume + set password + bump epoch) used by Task 5.
- [ ] Tests: throttle; constant-cost hashing for unknown e-mails; identical answers for unknown/config/inactive; GET doesn't consume; POST consumes once; set password bumps epoch and revokes remember-me; `last_login_at` set; remember-me works when configured; a site without record users sees the old login page (characterisation). → FAIL → implement → PASS → commit `feat(folio): staff login throttle, remember-me, forgot and set-password`.

### Task 5: rbac — staff lifecycle actions
**Files:** `packages/rbac/src/Types/StaffAdmin.php` (record actions Invite, Send reset link, Disable/Enable; permissions `staff.edit`; delegation applies), own-account page ("change my password", current password required), invite on create.
- [ ] Tests: invite mail on create, account can't log in before setting a password; reset link; disable ends a live session and a remember cookie; enable; actions refused on accounts above you; change own password ends other sessions. → FAIL → implement → PASS → commit `feat(rbac): invite, reset and disable staff accounts`.

### Task 6: migration and author display
**Files:** `packages/rbac/src/Console/ImportConfigStaffCommand.php` (`folio:staff:import-config`, via `extra.preflow.commands`), revision author display in Folio (`AdminController` revisions: show staff name for record ids, raw value for emails).
- [ ] Tests: import creates records with the same hash (old password logs in through the chain provider's record branch), maps roles by name, idempotent; author display for both forms. → FAIL → implement → PASS → commit `feat(rbac): import the config admin as a staff record`.

### Task 7: two-role scaffold, docs and release prep
**Files:** an integration test site in packages/rbac/tests: roles `super_admin` (`*`) and `content_admin` (per spec example), table-driven over every Folio admin route and screen (from `FolioPermissionMiddleware::RULES` and the ScreenRegistry) asserting allowed/denied per role; READMEs (auth chain provider, rbac staff + delegation + lifecycle + import, Folio login options), ROADMAP v0.27.0, `conflict` guards where 0.27 APIs are used, `docs/superpowers/plans/2026-10-24-release-0.27-checklist.md`.
- [ ] Steps: test → docs → all four suites → commit `chore: prepare 0.27.0` → release after the final whole-branch review (merge, tag, push, split, Packagist).
