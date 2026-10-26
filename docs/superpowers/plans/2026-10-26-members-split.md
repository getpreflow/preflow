# Members Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `preflow/members` becomes a core that runs without Folio. Folio carries the members admin (as it does media's). The generic members mechanisms that projects need are added: a staff and a self delete rule, rehash on sign-in, checked `next` with allowed paths, accounts with a verified address, hooks, a default landing, a mail action and a composed name. Released as v0.38.0.

**Architecture:**
- **Data:** gets an `onSelfDelete` rule next to `onDelete`, and the delete planner is told who deletes.
- **Auth:** takes over the record user and record user provider from Folio, and adds rehash on sign-in.
- **Members:** loses every `Preflow\Folio` and `Preflow\Rbac` use. Settings come through a `MembersSettings` interface, access without rbac through a small resolver of its own, and hooks through a `MemberEvents` registry.
- **Folio:** registers the members admin itself (extension `members`) when `preflow/members` is installed.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig.

**Spec:** `docs/superpowers/specs/2026-10-26-members-split-design.md`. Every section binds, including "Standing rules", "Upgrade notes the plan must cover" and the owner's decisions.

## Global Constraints

- **Standing rules (owner):**
  - The framework holds abstractions, interfaces, hooks and generic mechanisms only. Project features (BG Genius invitations, hosts, groups; holz-objekte.org's company admission, checkout) stay in the projects.
  - A package's official admin UI lives in Folio, as media's does.
  - Every capability is in the core; Folio only adds UI.
- **`preflow/members` must not require `preflow/folio` or `preflow/rbac`.** Its test suite runs with neither installed (a test app on auth + data). `NoFolioDependencyTest` pattern: grep `packages/members/src` for `Preflow\Folio` and `Preflow\Rbac` → no hits.
- **Names stay:** Folio extension name `members`, type keys `member` and `members_settings`, permissions `members.access` / `members.decide`, guard `members`, translation groups `members` (public, "Sie", byte-identical) and `members-admin` (admin).
- **Member state:** `roles` keeps `pending` / `member` / `rejected`, with no data migration. Without rbac, `member` grants exactly `members.access`; with rbac, rbac decides as today.
- **Default admission becomes `AcceptOnConfirm`.** `OwnerApproval` stays shipped and opt-in.
- **`next`:** the default allows only paths under the members path (today's `SafeNext` rule). `config/members.php` `next.allow` (a list of path prefixes) widens it. A scheme, `//` and backslash are never allowed.
- **Public pages byte-identical** for existing sites, unless a release note names the change.
- **Gates:**
  - `vendor/bin/phpunit` (baseline v0.37.0: 3600 tests, 99 deprecations; add none);
  - `composer test:mysql`;
  - smeyer.me and smart-stack.works suites;
  - don't touch holz-objekte.org (its own session upgrades it);
  - no merge, push or tag without the owner's go-ahead.

## Rulings made in this plan (for the owner to check)

1. **`RecordUser` and `RecordUserProvider` move to `preflow/auth`** (`Preflow\Auth\Record\…`). Auth already requires data. Folio keeps class aliases for one release, so the sites' `config/auth.php` keeps working, and the upgrade note names the new class. The provider takes the type definition from the data `TypeRegistry` (or a closure), no longer from Folio's `TypeCatalog`.
2. **Hooks are a `MemberEvents` registry in members** (`on(string $event, callable $listener)`, events as class constants), bound in the container; core has no event system to reuse. Listeners run in registration order. An exception in an "after" listener is logged and doesn't undo the member action. A "before" listener may refuse.
3. **Folio switches the members admin on with `class_exists(\Preflow\Members\Members::class)`** and registers it as its built-in extension `members` (the same name, so enable state, settings and permissions carry over).
4. **The default landing at `GET {path}`** is registered after the site's own routes, so a site's route for the same path wins.

## Review Focus

- **A member deletes their own account while a `restrict` self rule applies** (e.g. open orders): nothing is deleted, no half state, the notice goes out only when an address or a listener exists, and the message is accordingly honest (Task 6).
- **A `next` that tries to leave the allowed paths** (`//evil.example`, `https://evil.example`, `/\evil`, `/games/../admin` with `next.allow` = `['/games/']`, an encoded `%2F%2F`): it falls back to the default landing (Task 5).
- **An old MD5-crypt (`$1$`) hash of a member and of a staff record user:** sign-in works and the stored hash becomes a current bcrypt/argon hash. A concurrent password change between verify and rehash is not overwritten (compare on the old hash) (Task 2).
- **smart-stack.works after the upgrade:** its admission, resources gated on `members.access`, private files, `members_settings` extends file and its own `GET {path}` route behave as before (Task 8 gates; Task 3/4 tests mirror them).
- **A site without rbac** (core test app): an admitted member reaches pages gated on `members.access`, while pending and rejected members are refused (Task 4).

---

### Task 1: Data — `onSelfDelete` and who deletes

**Files:**
- Modify:
  - `packages/data/src/Relation.php`: `public OnDelete $onSelfDelete`, parsed from `config.relation.onSelfDelete` (or the field's `onSelfDelete` key, where `onDelete` is read today), defaulting to `$onDelete`;
  - `packages/data/src/DeletePlanner.php`: `plan(string $type, array $ids, ?\Closure $copies = null, DeletedBy $by = DeletedBy::Staff): DeletePlan` uses `onSelfDelete` when `$by === DeletedBy::Self`.
- Create: `packages/data/src/DeletedBy.php` (`enum DeletedBy: string { case Staff = 'staff'; case Self = 'self'; }`).
- Modify: `packages/folio/src/Content/RecordDeleter.php`, which passes `DeletedBy::Staff` explicitly.
- Test: `packages/data/tests/DeletePlannerSelfRuleTest.php`.

**Interfaces — Produces:** `DeletedBy`, `Relation::$onSelfDelete`, and `DeletePlanner::plan(…, DeletedBy $by)`.

- [ ] **Step 1: Failing tests.** A relation `owner` with `onDelete: restrict, onSelfDelete: detach`:
  - a staff plan is refused;
  - a self plan detaches.
  - With only `onDelete: cascade`, both plans cascade.
  - `onSelfDelete: restrict` refuses a self plan.
  - An invalid value falls back to the default, as `onDelete` does.
- [ ] **Step 2:** `vendor/bin/phpunit packages/data` fails.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/data packages/folio` passes.
- [ ] **Step 5:** Commit `feat(data): onSelfDelete rule and who deletes`.

### Task 2: Auth — record users and rehash on sign-in

**Files:**
- Move: `packages/folio/src/Users/RecordUser.php` and `RecordUserProvider.php` to `packages/auth/src/Record/` (namespace `Preflow\Auth\Record`). Leave `class_alias` shims in Folio's old namespace (in Folio's `legacy-aliases.php`), deprecated for one release.
- Create:
  - `packages/auth/src/UpdatesPasswordHash.php`: an interface `updatePasswordHash(Authenticatable $user, string $oldHash, string $newHash): bool`, which stores only when the stored hash still equals `$oldHash`;
  - `packages/auth/src/PasswordRehash.php`: `afterVerify(UserProviderInterface $provider, Authenticatable $user, string $password, PasswordHasherInterface $hasher): void`, which acts only when `needsRehash()` and the provider implements `UpdatesPasswordHash`, never logs the password, and swallows and logs failures.
- Modify:
  - `RecordUserProvider` implements `UpdatesPasswordHash`;
  - Folio's staff `Http/AuthController` (~l.111) calls `PasswordRehash::afterVerify()` after a successful verify;
  - members' `Http/AuthController` (~l.77) does the same;
  - every `Preflow\Folio\Users\RecordUser(Provider)` use in packages points to the new namespace.
- Test: `packages/auth/tests/PasswordRehashTest.php`; additions in the Folio and members login tests.

**Interfaces — Produces:** `Preflow\Auth\Record\RecordUser`, `Preflow\Auth\Record\RecordUserProvider`, `UpdatesPasswordHash`, `PasswordRehash::afterVerify`.

- [ ] **Step 1: Failing tests.**
  - A record with a `$1$` MD5-crypt hash signs in (Folio staff and member) and afterwards holds a hash `needsRehash()` accepts.
  - A config user (no record) signs in and nothing changes.
  - When the stored hash changed between verify and rehash, the newer hash stays.
  - `auth_epoch` is unchanged, so the new session survives.
  - The old Folio class names still resolve, with a deprecation note.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement. The provider gets its type definition from data (`DataManager`/`TypeRegistry` by type key), without Folio.
- [ ] **Step 4:** `vendor/bin/phpunit packages/auth packages/folio packages/members` passes.
- [ ] **Step 5:** Commit `feat(auth): record users in auth; rehash on sign-in`.

### Task 3: Folio carries the members admin

**Files:**
- Move: `packages/members/src/Extension.php`, `src/Types/MemberAdmin.php`, `src/Types/MembersSettingsAdmin.php` and their templates and tests into `packages/folio/src/Members/` (namespace `Preflow\Folio\Members`). Folio registers them as its built-in extension `members` when `class_exists(\Preflow\Members\Members::class)` (Ruling 3).
- Create: `packages/folio/src/Members/FolioMembersSettings.php`, implementing Task 4's `MembersSettings` from Settings → Members. Until Task 4 exists, define the interface here first in `packages/members/src/MembersSettings.php` (same task) with the methods below.
- Modify:
  - `packages/members/composer.json`: drop the `extra` Folio extension declaration;
  - `packages/folio/composer.json`: `suggest` `preflow/members`;
  - the `members-admin` language files stay in `packages/members/lang`, still declared by members. They are admin texts of the members package's Folio admin; Folio reads them through the registry.
- Test: move the members admin tests to `packages/folio/tests/Members/`. They pass unchanged in behaviour.

**Interfaces — Produces:**
- `interface Preflow\Members\MembersSettings`:
  - `registrationOpen(): bool`
  - `noticeEmail(): ?string`
  - `acceptMessage(string $locale): ?string`
  - `declineMessage(string $locale): ?string`
- `FolioMembersSettings`.

- [ ] **Step 1: Failing tests.**
  - With members installed, Folio shows the Members section, list, member edit, Decision panel and Settings → Members, exactly as before (the moved tests).
  - The extension is still named `members`, the type keys are unchanged, and the enable state and stored settings of an existing site carry over (fixture with the old settings record).
  - Without `preflow/members` (class missing, simulated), Folio boots without the section.
- [ ] **Step 2:** The tests fail (classes moved, registration missing).
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/folio packages/members packages/rbac` passes.
- [ ] **Step 5:** Commit `feat(folio): the members admin lives in Folio`.

### Task 4: Members core without Folio and rbac

**Files:**
- Modify:
  - `packages/members/src/**`: remove every `Preflow\Folio` and `Preflow\Rbac` use:
    - `PatternCompiler` → `preflow/routing`'s own pattern handling;
    - `ContentLocale` → the i18n `Translator` locale / `Locales`;
    - `ExtensionSettings` → `MembersSettings`;
    - `RecordUser` → `Preflow\Auth\Record\RecordUser`;
    - `RoleList` → a small `MemberRoles` reader of the `roles` field;
    - the rbac requirement check in `boot()` → gone.
  - `packages/members/composer.json`: drop `preflow/folio` and `preflow/rbac` from `require`, and add them to `suggest`.
- Create:
  - `packages/members/src/ConfigMembersSettings.php`, the default `MembersSettings` from `config/members.php` (`registration_open`, `notice_email`, `accept_message`, `decline_message`); Folio binds its own (Task 3);
  - `packages/members/src/Access/MemberAccess.php`: without rbac, an access resolver giving `members.access` to records whose `roles` contain `member`, and nothing else. With rbac bound, rbac's resolver stays.
- Modify:
  - `MembersConfig`: default `admission` → `AcceptOnConfirm::class`;
  - default `layout` → `@members/_layout.twig`, a minimal standalone page layout shipped by members;
  - `Viewer::isStaff()` works through the auth guard names, without Folio's classes.
- Test:
  - `packages/members/tests/NoFolioTest.php`: a test app with auth + data + routing + view + notify + i18n, no Folio and no rbac;
  - `packages/members/tests/NoFolioDependencyTest.php`: grep for Folio and rbac in `src`.

- [ ] **Step 1: Failing tests.**
  - In the test app without Folio or rbac: register, confirm, sign in and out, forgot and reset, account page.
  - An admitted member reaches a route gated on `members.access`; pending and rejected members are refused.
  - The default admission admits after confirmation.
  - The dependency test finds no `Preflow\Folio` or `Preflow\Rbac`.
  - With Folio and rbac (existing tests), everything behaves as before, and the public pages are byte-identical (snapshot from Task 7 of 0.37 still green).
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/members packages/folio packages/rbac packages/auth` passes.
- [ ] **Step 5:** Commit `feat(members): a core without Folio and rbac`.

### Task 5: `next` with allowed paths, verified accounts, signing in

**Files:**
- Modify:
  - `packages/members/src/Http/SafeNext.php`: today's rule plus `config/members.php` `next.allow` (list of path prefixes, each starting with `/`);
  - the registration, confirm and auth controllers: `next` is carried through registration (stored on the pending account, e.g. a `next` field of the member type or the confirm token's payload) and confirmation, and followed once after the step completes;
  - `packages/members/src/Members.php`: `register(string $email, string $name, string $password, string $language, array $extra = [], bool $emailVerified = false): DynamicRecord`, which with `true` confirms at once and runs `afterConfirm`, without a confirmation mail.
- Create: `packages/members/src/SignIn.php`, `in(DynamicRecord $member, ServerRequestInterface $request): void` (the guard login members uses today, remember on).
- Test: `packages/members/tests/NextTest.php`, `VerifiedAccountTest.php`.

**Interfaces — Produces:** `SafeNext::of(?string $next, string $locale): string` (now honouring `next.allow`), `Members::register(…, bool $emailVerified)`, `SignIn::in()`.

- [ ] **Step 1: Failing tests.**
  - `next=/konto/x` is kept by default, and `/games/catan` is dropped by default but kept with `next.allow = ['/games/']`.
  - `//evil.example`, `https://evil.example`, `/\evil`, `%2F%2Fevil` and `/games/../admin` (normalized) are dropped.
  - Registration with `next`, then confirmation, lands on `next` once; a second visit to the landing doesn't redirect again.
  - `register(…, emailVerified: true)` sends no confirmation mail, the member is confirmed and admitted per the admission, and `SignIn::in` gives a signed-in request.
  - smart-stack.works' `nextCases` expectations hold, mirrored as a package test.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/members` passes.
- [ ] **Step 5:** Commit `feat(members): next through registration and confirmation, verified accounts`.

### Task 6: Hooks and self-deletion through the delete planner

**Files:**
- Create: `packages/members/src/MemberEvents.php` (Ruling 2), with the events `REGISTERED`, `CONFIRMED`, `DECIDED`, `SIGNED_IN`, `DELETING` (may refuse), `DELETED` and `DELETION_REFUSED`.
- Modify:
  - `Members.php`: `delete()` goes through `DeletePlanner::plan(MembersConfig::TYPE, [$id], by: DeletedBy::Self)`. When the plan is refused, it raises `DELETION_REFUSED`, sends a notice to `MembersSettings::noticeEmail()` if set, and returns a result saying whether anyone was told. Otherwise it applies the plan (cascade/detach) and raises `DELETING` / `DELETED`.
  - `members:prune` uses the same path.
  - The controllers raise `REGISTERED`, `CONFIRMED`, `DECIDED` and `SIGNED_IN`.
  - The account template shows the honest refusal message: "the site has been told" only when a notice went out, else "please contact us". Keys in `members` (public, "Sie", en/de/fr); existing texts stay unchanged.
- Test: `packages/members/tests/SelfDeletionTest.php`, `MemberEventsTest.php`.

**Interfaces — Produces:** `MemberEvents::on(string $event, callable $listener): void`, `dispatch(string $event, array $payload): bool` (false when a `DELETING` listener refuses), `Members::delete(DynamicRecord $m): DeletionResult` (`deleted`, `refused`, `told`).

- [ ] **Step 1: Failing tests** (a site type `order` with `member` relation `onDelete: restrict`; variants with `onSelfDelete: detach`, `cascade` and `restrict`):
  - With `detach`, the member is gone and the order's member is cleared.
  - With `cascade`, the orders are gone.
  - With `restrict`, nothing changes:
    - with `notice_email` set, a notice mail goes out and the page says the site was told;
    - without it and without a listener, the page asks the member to contact the site.
  - `DELETING` returning false refuses the deletion.
  - Each event fires once with the documented payload, and an exception in a `DELETED` listener is logged while the deletion stands.
  - A staff delete in Folio still uses `onDelete`.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/members packages/data packages/folio` passes.
- [ ] **Step 5:** Commit `feat(members): hooks; self-deletion follows the self rules`.

### Task 7: Default landing, mail action, composed name

**Files:**
- Create:
  - `packages/members/src/Http/LandingController.php` and `templates/landing.twig`: `GET {path}` for a signed-in member, with a greeting, links to the account page and sign-out, and texts in `members`. A signed-out visitor goes to the login. Registered after site routes (Ruling 4).
  - `packages/members/src/Admission/ComposesName.php`: an optional interface, `name(array $input): string`. When the admission implements it, the registration form doesn't require a `name` field and the stored name is the admission's result.
  - `packages/members/src/Console/DecideCommand.php`: `members:decide <email> accept|decline`, for sites with `OwnerApproval` but without Folio. It calls `Members::decide()` and the same mails as the Folio panel. It is listed in `extra.preflow.commands`.
- Modify:
  - `MemberMailer`: passes `action: {label, url}` to the mail layout for the confirm, reset and existing-account mails. The shipped `@members/mail/layout.twig` renders it as a button with the URL below.
  - The registration template hides the `name` field when the admission composes the name.
- Test: `LandingTest.php`, `MailActionTest.php`, `ComposedNameTest.php`.

- [ ] **Step 1: Failing tests.**
  - `GET /members` signed in shows the landing; signed out, it redirects to the login.
  - A site route on the same path wins.
  - The confirm mail's layout receives `action.url` equal to the confirm link, and the HTML shows a button.
  - An admission with `ComposesName` (first and last name fields) stores "Ada Lovelace" with no `name` field posted; without it, the form behaves as today.
  - `members:decide ada@example.test accept` admits a pending member and sends the accepted mail; an unknown email exits 1.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement, with en/de/fr texts (`members` group, "Sie" in German, for the landing).
- [ ] **Step 4:** `vendor/bin/phpunit packages/members` passes.
- [ ] **Step 5:** Commit `feat(members): default landing, mail action, composed name`.

### Task 8: Audit, docs, release preparation

**Files:**
- Create: `docs/superpowers/plans/2026-10-26-release-0.38-checklist.md` (the 0.37 checklist's shape).
- Modify:
  - `packages/members/README.md`, `packages/folio/README.md`, `packages/auth/README.md`, `packages/data/README.md`, `ROADMAP.md`;
  - Composer constraints: members requires auth/data/routing/i18n `^0.38` and no longer Folio; Folio suggests members; auth and data `^0.38`; the conflict pins, with their tests.
- **Audit:** list every members feature in the checklist and sort it (generic stays / project-specific → hook, option, example or out), per the spec's section 8. Implement only trivial moves. Everything else becomes a ledgered finding for the owner.

- [ ] **Step 1:** Audit and docs. The upgrade notes must cover:
  - the default admission change (both sites set it explicitly);
  - the default layout change (smart-stack.works: set `layout` or accept the new default);
  - `RecordUserProvider`'s new class name (aliases for one release);
  - `next.allow`;
  - `onSelfDelete`;
  - `MemberEvents`;
  - `Members::delete()` returning a result;
  - the `/konto` landing (site routes win);
  - `ComposesName`;
  - the mail `action`;
  - members no longer requiring Folio or rbac.
- [ ] **Step 2:** Gates:
  - `vendor/bin/phpunit` (no new deprecations);
  - `composer test:mysql`;
  - smeyer.me and smart-stack.works suites (`find storage/cache -name 'folio-*.cache' -delete` first; don't modify site files; list any needed site-side change in the checklist).

  Record the counts.
- [ ] **Step 3:** Commit `chore: prepare 0.38.0`.
