# preflow/members Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move smart-stack.works' member accounts into a framework package `preflow/members` (v0.26.0) with pluggable admission and site-extensible member fields, and switch smart-stack to it with no data migration and no visible change.

**Architecture:** A Folio extension package like `preflow/codes` (model, admin type, settings, templates, lang, routes, console). Two seams for sites: an `Admission` interface (what happens on register and after confirmation) and Folio's new `"extends": true` model files (extra fields on an extension's type). The code is extracted from smart-stack, not rewritten: each task names the smart-stack file it moves and what changes on the way.

**Tech Stack:** PHP 8.4, Preflow (auth SessionGuard + RememberMe + Tokens, Throttle, notify `sendLater`, rbac roles, Folio extensions/RecordUserProvider/NavBadge/FormPanel/RecordAction), Twig, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-23-members-package-design.md`

## Global Constraints

- Package: `packages/members`, namespace `Preflow\Members`, composer name `preflow/members`, `extra.folio.extension` = `Preflow\Members\Extension`, Twig namespace `@members`, lang domain `members`, provider `Preflow\Members\MembersServiceProvider`. Add the package to the monorepo split workflow and to `phpunit.xml`.
- Names that must not change (smart-stack data depends on them): type key `member`, table `member`, guard `members`, permission `members.access` and `members.decide`, roles `pending` / `member` / `rejected`, token purposes `members.confirm` and `members.reset`, throttle purposes `members.login`, `members.register`, `members.forgot`, `members.resend` (as smart-stack uses them today — copy the exact strings from its code).
- `config/members.php` keys and defaults: `path` `/members`, `landing` null (= `path`), `layout` `@folio/frontend/_site.twig`, `mail` `['from_name' => null, 'layout' => '@members/mail/layout.twig', 'accent' => '#d42a34']`, `admission` `Preflow\Members\Admission\OwnerApproval::class`, plus smart-stack's current `throttle`, `password_min` (10), `confirm_ttl` (86400), `reset_ttl` (3600), `retention` (7 / 30 days) keys and values.
- Member model storage: alias `members` when configured, else `default`.
- Locales and URL prefixes come from `config/i18n.php`; package lang ships `en`, `de`, `fr`.
- Anti-goals: no session cookie on public pages; no account enumeration (same response for known/unknown e-mails on register, forgot, resend; unknown logins still hash); a failed decision mail aborts the decision; member types `transfer: false` (via `users`).
- smart-stack after Task 7: no visible change (pages, mails, copy, URLs, cookies) and no data migration.
- Full framework suite `vendor/bin/phpunit` and `composer test:mysql` stay green; smart-stack `vendor/bin/phpunit` green; existing "PHPUnit Deprecations" counts must not grow.
- Style: `declare(strict_types=1)`, `final`, short why-docblocks, no new dependencies.

## Review Focus

- smart-stack's member pages and mails are byte-identical in their rendered text after the switch (Task 7 renders each page and mail before/after and diffs the text).
- A site with `path` `/konto`, locales de/fr, `AcceptOnConfirm`: register → confirm → signed in → account, French throughout (Task 5 scaffold test).
- Extension adds a field later that a site already extends: boot error names the field (Task 1).
- Enumeration and timing on register/forgot/resend/login (Task 5 tests carried from smart-stack).
- No `Set-Cookie` on a public page (Task 5 test, Task 7 smart-stack test).

---

### Task 1: Folio — sites extend an extension's type

**Files:**
- Modify: `packages/folio/src/FolioServiceProvider.php` (where extension models are added, ~line 81), `packages/folio/src/Content/TypeCatalog.php`, `packages/data/src/TypeRegistry.php` (the "already exists" check, ~line 40), whatever merges schemas (keep the merge in one new class `packages/folio/src/Extension/ModelExtension.php`).
- Test: `packages/folio/tests/Extension/ModelExtensionTest.php`, an integration case in `packages/folio/tests/Integration/` (codes or demo extension type extended by a site field, migrate step adds the column, admin form shows it).

**Interfaces:**
- Produces: `ModelExtension::merge(array $extensionSchema, array $siteSchema): array` — appends site `fields` after the extension's; site `list.columns` replace the extension's when given; site keys `storage`, `table`, `users`, `id_field`, `key` other than equal values → `FrontendConfigException("Model \"{key}\" extends the extension type: \"{option}\" can't be changed")`; a site field the extension also defines → `FrontendConfigException("Model \"{key}\" extends the extension type: field \"{field}\" is already defined by the extension")`. A site model with the key of an extension type but without `"extends": true` keeps today's duplicate error. A site file with `"extends": true` and no such extension type → `FrontendConfigException("Model \"{key}\" extends an extension type, but no enabled extension defines it")`.

- [ ] Step 1: tests for each rule above (unit on `merge`, plus boot-level tests for the three errors and the happy path), and an integration test where `folio.tables` reports `added column <table>.<field>`.
- [ ] Step 2: run → FAIL. Step 3: implement. Step 4: full suite + `composer test:mysql` → PASS.
- [ ] Step 5: commit `feat(folio): site model files can extend an extension's type ("extends": true)`.

### Task 2: Package skeleton, member type and Folio extension

**Files:**
- Create: `packages/members/{composer.json,README.md}`, `src/MembersServiceProvider.php`, `src/MembersConfig.php` (from smart-stack `app/Members/Limits.php`, now all `config/members.php` keys with the Global Constraints defaults), `src/Extension.php` (from `app/Folio/Members/Extension.php`: permissions, roles install, settings `registration_open`, `notice_email`, `accept_message`, `decline_message` only — default copy neutral, no SMART STACK/Steffen), `src/models/member.json` (from `app/Folio/Members/models/member.json` minus `screening_answer`, `book_code`; `language` options from i18n), `src/Types/MemberAdmin.php` (from `app/Folio/Members/Types/MemberAdmin.php` minus `has_code`; decision panel shows `Admission::details()`; accept/decline refuse states not allowed by the matrix: accept from Waiting or Declined, decline from Waiting or Member), `src/templates/decision.twig`, `src/Members.php` (from `app/Members/Members.php`; `register(string $email, string $name, string $password, string $language, array $extra)`), `src/MemberState.php`, `src/Viewer.php` (guard/permission from config), `src/Admission/{Admission.php,Decision.php,AcceptOnConfirm.php,OwnerApproval.php}`.
- Modify: `.github/workflows/split.yml`, `phpunit.xml`, root `composer.json` (path repo / autoload as the other packages).
- Test: `packages/members/tests/` — scaffold app support (model on PublishingScaffold / codes tests) and tests moved from smart-stack `MembersGroundworkTest`, `MembersConfigTest`, `MembersServiceTest`, `MemberApprovalTest` (admin part), adapted to the package and with a test for the decision state matrix (crafted POST from a disallowed state → refused, no mail).

**Interfaces:**
- Produces: `Admission` (as in the spec), `enum Decision { case Accept; case Pending; }`, `Members` service methods (`findByEmail`, `emailTaken`, `register`, `confirm`, `decide`, `setPassword`, `delete`, `pendingCount`), `MembersConfig` getters (`path()`, `landing()`, `layout()`, `mailFromName()`, `mailLayout()`, `accent()`, `admission()`, `throttle(string)`, `passwordMin()`, `confirmTtl()`, `resetTtl()`, `retention(string)`), `Viewer`.

- [ ] Steps: tests (moved + new) → FAIL → implement → full suite + MySQL → PASS → commit `feat(members): package skeleton, member type, admin and admission hook`.

### Task 3: Mails and copy

**Files:**
- Create: `packages/members/src/MemberMailer.php` (from `app/Members/MemberMailer.php`: only the `*Later` visitor mails plus synchronous accepted/declined; from-name, accent and layout from `MembersConfig`; notice details from `Admission::details()`), `src/Links.php` (from `app/Members/Links.php`: base from `app.url` only, path and locale prefix from config/i18n), `src/templates/mail/layout.twig` (neutral), `lang/{en,de}/members.php` (all generic keys from smart-stack `lang/*/members.php` groups form, errors, inbox, confirm, thanks, login, forgot, reset, resend, account, plus the mail subjects/bodies from `MailCopy.php`; drop unused `errors.mail`), `lang/fr/members.php` (French translation of the same keys).
- Test: package tests for every mail (subject, recipient, link, locale) on en/de/fr, the confirm-mail `onFailure` re-check (a member confirmed meanwhile is not deleted), and a decline/accept mail failure aborting the decision.

- [ ] Steps: tests → FAIL → implement → PASS → commit `feat(members): mails and copy in en, de, fr`.

### Task 4: Templates and the page layout seam

**Files:**
- Create: `packages/members/src/templates/{_card,login,forgot,reset,confirm,resend,check-inbox,thanks,closed,account,register}.twig` from smart-stack `resources/folio/frontend/tools/*` (`entry.twig`'s form part becomes `register.twig` with `{% block admission_fields %}{% endblock %}` and `{% block extra_fields %}{% endblock %}`), extending `MembersConfig::layout()`; `src/MembersView.php` (from `app/Members/ToolsView.php`: `private, no-store`, htmx redirect, locale alternates from i18n, template lookup site `resources/members/` first then `@members`).
- Test: rendering tests for each template with a site override present/absent; layout config honoured.

- [ ] Steps: tests → FAIL → implement → PASS → commit `feat(members): page templates with site overrides`.

### Task 5: Routes, controllers and flows

**Files:**
- Create: `packages/members/src/Http/{AuthController,RegistrationController,AccountController}.php` from smart-stack `MemberAuthController`, the register/confirm part of `ToolsController`, `AccountController`; routes registered by the provider under `MembersConfig::path()` for every locale (paths listed in the spec); `Admission` called at register (`validate`, `recordValues`) and confirm (`afterConfirm`: Accept → member + signed in + redirect to landing; Pending → pending role + owner notice); `safeNext` only under `path`; password change and reset end the member's other sessions (remember-me `revokeAll` plus a per-member session epoch: field `auth_epoch` on the member, stored in the session at login, checked by a members-guard wrapper; bump on change/reset).
- Test: move smart-stack `RegistrationTest` (generic parts), `MemberLoginTest`, `AccountTest`, `MemberApprovalTest` (flow parts) into package tests on a scaffold site; add the Review Focus scaffold (`path` `/konto`, de/fr, `AcceptOnConfirm`, French copy and URLs end to end), a no-`Set-Cookie`-on-public-page test, and "password change signs out a second session".

- [ ] Steps: tests → FAIL → implement → full suite + MySQL → PASS → commit `feat(members): registration, sign-in, reset and account flows`.

### Task 6: Prune command

**Files:**
- Create: `packages/members/src/Console/PruneMembersCommand.php` from smart-stack `app/Console/PruneMembersCommand.php` (`members:prune`, registered by the provider), retention from `MembersConfig`.
- Test: moved `PruneMembersTest`.

- [ ] Steps: tests → FAIL → implement → PASS → commit `feat(members): members:prune`.

### Task 7: smart-stack.works switches to the package

**Repo:** `/Users/smyr/Sites/gbits/smart-stack.works`, branch `feat/members-package`.

**Files:**
- Before any change: render every members page (GET of each route, both locales, signed out/in/pending/declined) and every mail to text files under the plan workspace as the baseline (a small script using the site's test app).
- Create: `app/Members/BookCodeOrScreening.php` (Admission: code check at submit, redeem at confirm with `book_code_skips_screening`, screening answer, details "Book code: yes/no" / screening answer), `app/Folio/SmartStack/Extension.php` (settings `book_code_skips_screening`, `screening_label`, `screening_help`, `welcome_note` with today's defaults and existing stored values — same setting keys if the settings type key can stay, else a one-time copy in `install()`), `models/member.json` with `"extends": true` adding `screening_answer` and `book_code`, `resources/members/*` overrides only where the page differs from the package, `lang/*/members.php` only with keys whose copy differs, `config/members.php` (`path` `/tools`, `admission`, `mail` from-name "Steffen Meyer", mail layout `@mail/layout.twig`).
- Delete: the moved files (`app/Members/{Members,MemberState,Viewer,Links,Limits,MemberMailer,MailCopy}.php`, `app/Folio/Members/*` except what the site extension keeps, `MemberAuthController`, `AccountController`, register/confirm from `ToolsController`, `PruneMembersCommand`, moved templates), their provider wiring.
- Modify: `composer.json` require `preflow/members` `^0.26 || @dev`, `config/providers.php`, `config/folio/extensions.json`, `deploy/server-composer.php` if it lists packages.
- Test: the site's remaining tests (toolbox, book code, full flows via `/tools`) pass; re-render the baseline and diff: no difference. Folio admin screenshot of the member list and decision panel before/after.

- [ ] Steps: baseline → switch → site suite PASS → diff empty → commit `refactor: members from preflow/members` (site repo).

### Task 8: Docs and release v0.26.0

**Files:**
- Modify: `packages/members/README.md` (install, config keys, admission, extending the member type, overriding templates and copy, routes, cron), `packages/folio/README.md` or Folio docs (`"extends": true`), `ROADMAP.md` (v0.26.0), constraints (`preflow/folio` `^0.26 || @dev` where the extends feature is used).
- Create: `docs/superpowers/plans/2026-10-23-release-0.26-checklist.md`.

- [ ] **Stop before the release:** the split pushes to `getpreflow/members`, which doesn't exist yet. Steffen creates it (or says yes to `gh repo create getpreflow/members --public`) and submits it on Packagist.
- [ ] Steps: docs → both suites + smart-stack suite → commit `chore: prepare 0.26.0` → release after the final whole-branch review (merge, tag `v0.26.0`, push, split, Packagist incl. `preflow/members`); smart-stack's branch merges and deploys only with Steffen's go.
