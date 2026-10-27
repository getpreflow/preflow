# Members: lock, ending sessions, staff resend, last sign-in — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** the members core can lock an account apart from the registration decision, end all of a member's sessions, send a staff-requested confirmation link, and note the last sign-in; Folio's members admin offers lock, unlock and resend.

**Architecture:** two readonly datetime fields on the core member schema (`locked_at`, `last_login_at`). `Members` gains `lock()`, `unlock()`, `isLocked()`, `endSessions()`, `noteSignIn()` and `resendConfirmation()`. `SignIn::in()` is the one gate for every sign-in path (login, reset, confirmation, `emailVerified`): it refuses a locked member and notes the sign-in. A new `ConfirmationMail` holds the "revoke old confirm tokens, issue a fresh one, mail it" step that registration, the public resend and the staff resend share. Folio's `MemberAdmin` shows "Locked" in the state column and gets three record actions.

**Tech Stack:** PHP 8.4, PHPUnit 11, preflow monorepo (`vendor/bin/phpunit` from the repo root).

**Spec:** `docs/superpowers/specs/2026-10-26-site-admin-gaps-contract.md` (section A4), from holz-objekte.org's step 9 design (Part C: "lock blocks sign-in and ends every session at once … no mail; nothing is deleted or changed, and Entsperren restores sign-in with the old password"; "a locked account must never look declined in the members list").

## Global Constraints

- Framework rule (ROADMAP, owner 2026-10-26): the core holds the generic mechanism; the admin UI for it lives in Folio (`packages/folio/src/Members`).
- `locked_at` and `last_login_at`: `"type": "date", "time": true, "readonly": true`; stored like `decided_at` (`''` = not set, else `Members::stamp()`'s format).
- Lock does not change `roles`, the password, or any other field; `MemberState::fromRecord()` is unchanged (a locked member keeps its state). No mail on lock or unlock.
- Every refusal to a locked member is the neutral one the same path gives a declined member (login: error `login`, 422, throttle hit; forgot: no mail, same page; reset POST: `expired`, 410; confirm POST: `used`, 410). Never a text that says "locked" on a public page.
- Texts: new keys in `members-admin` (en, de, fr; German "Sie" is not needed in the admin, follow the file's tone). No new public text.
- Contract names are fixed: `Members::lock(DynamicRecord): void`, `unlock(DynamicRecord): void`, `isLocked(DynamicRecord): bool`, `endSessions(DynamicRecord): void`, `resendConfirmation(DynamicRecord): void` (throws `\LogicException` when the member is not unconfirmed), `noteSignIn(DynamicRecord): void`; fields `locked_at`, `last_login_at`.
- A bare `Members` (constructed without the container's services, as tests do) keeps working for everything it did; `endSessions()` without a `RememberMe` bumps the epoch only; `resendConfirmation()` without a `ConfirmationMail` throws `\LogicException` (like `register(emailVerified: true)` without services).

## Review Focus

- A remembered device of a locked member: the remember cookie must not sign them back in (revokeAll at lock, and the epoch bump).
- A locked member whose session is open in another tab: the next request is signed out (epoch).
- Unlock keeps the old password and sessions stay ended (no epoch rollback).
- `last_login_at` is written on the confirmation sign-in and the reset sign-in too, not only the login form (all go through `SignIn::in()`).
- Staff resend for a member whose address was just corrected uses the stored (new) address and invalidates the earlier link.

---

### Task 1: Core fields and `Members` lock / sessions / sign-in note

**Files:**
- Modify: `packages/members/models/member.json` (add `locked_at`, `last_login_at` after `decided_at`)
- Modify: `packages/members/src/Members.php`
- Modify: `packages/members/src/MembersServiceProvider.php` (pass `RememberMe` to `Members`)
- Test: `packages/members/tests/MembersLockTest.php` (new; follow the setup of the existing Members tests in `packages/members/tests/`)

**Interfaces:**
- Produces: `Members::lock(DynamicRecord $m): void`, `unlock(DynamicRecord $m): void`, `isLocked(DynamicRecord $m): bool`, `endSessions(DynamicRecord $m): void`, `noteSignIn(DynamicRecord $m): void`. New optional constructor parameter `?RememberMe $remember = null`, appended last.
- Add `locked_at`, `last_login_at` to `OWN_FIELDS`; `register()` sets both to `''`.

- [ ] **Step 1: Write failing tests**
  - `test_lock_sets_locked_at_and_keeps_roles_and_password`: a member (roles `['member']`), `lock()` → `isLocked()` true, `locked_at` non-empty, `roles` and `password_hash` unchanged, `MemberState::fromRecord()` still `Member`; reloaded from the store, still locked.
  - `test_lock_ends_sessions`: `auth_epoch` goes from `'0'` to `'1'`; with a `RememberMe` double/real instance, the member's remember rows for guard `MembersConfig::GUARD` are gone.
  - `test_unlock_clears_locked_at_and_keeps_the_epoch`: lock then unlock → `isLocked()` false, `locked_at` `''`, `auth_epoch` stays `'1'`, password verifies with the old one.
  - `test_lock_twice_keeps_the_first_time`: the second `lock()` leaves `locked_at` as it was (and still ends sessions).
  - `test_end_sessions_without_remember_bumps_the_epoch`: bare `Members` (no RememberMe) → epoch +1, no error.
  - `test_note_sign_in_stamps_last_login_at`: injected clock → `last_login_at` equals the stamp; stored.
  - `test_register_sets_lock_and_login_fields_empty` and `register()` with `$extra` `['locked_at' => 'x']` throws `\LogicException`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/members/tests/MembersLockTest.php` — Expected: FAIL (undefined method `lock`).
- [ ] **Step 3: Implement** the methods (`endSessions()`: epoch +1, save, `remember?->revokeAll(MembersConfig::GUARD, id)`; `lock()` calls it; `noteSignIn()` saves `last_login_at`). Add the schema fields; wire `RememberMe` in the provider.
- [ ] **Step 4: Run** the test file, then `vendor/bin/phpunit packages/members packages/folio` — Expected: PASS (snapshot tests of the member schema, if any, updated for the two fields).
- [ ] **Step 5: Commit** `feat(members): lock, end sessions and the last sign-in on Members`

### Task 2: Every sign-in path refuses a locked member and notes the sign-in

**Files:**
- Modify: `packages/members/src/SignIn.php` (takes `?Members $members = null`; refuses locked; calls `noteSignIn()` after login)
- Modify: `packages/members/src/MembersServiceProvider.php`
- Modify: `packages/members/src/Http/AuthController.php` (login, forgot, reset)
- Modify: `packages/members/src/Http/RegistrationController.php` (confirm POST)
- Test: `packages/members/tests/Http/LockedSignInTest.php` (new; reuse the HTTP test harness the existing AuthController tests use), plus a `SignIn` unit test

**Interfaces:**
- Consumes: Task 1's `Members::isLocked()`, `noteSignIn()`, `lock()`.
- Produces: `SignIn::in()` throws `\LogicException` for a locked member (message names "locked"); writes `last_login_at` when `Members` was given.

- [ ] **Step 1: Write failing tests**
  - `test_locked_member_login_fails_like_a_wrong_password`: right password, locked → 422, the page has the `login` error exactly as for a wrong password (compare the two bodies' error element), the throttle counted the attempt, not signed in.
  - `test_locked_member_gets_no_reset_mail`: forgot for a locked member → same "sent" page, no mail queued.
  - `test_reset_link_of_a_locked_member_is_expired`: token issued, then lock, POST reset → 410 `expired`, password unchanged.
  - `test_confirm_link_of_a_locked_unconfirmed_member_is_used`: unconfirmed member, token issued, lock, POST confirm → 410 `used`, `email_confirmed_at` still empty.
  - `test_unlocked_member_signs_in_with_the_old_password`: lock, unlock, login with the old password → redirect, signed in.
  - `test_sign_in_notes_last_login_at` for the login form, the reset POST and the confirm POST (three asserts that `last_login_at` is set after each).
  - `test_sign_in_refuses_a_locked_member` (unit): `SignIn::in()` throws `\LogicException`.
- [ ] **Step 2: Run** the new tests — Expected: FAIL (locked member signs in).
- [ ] **Step 3: Implement.** Login: treat locked like `Declined` in the existing refusal branch (before the Unconfirmed/Waiting branches, so a locked account never learns its state). Forgot: only `Member` and not locked. Reset: locked → `expired`. Confirm: locked → `used`. `SignIn::in()`: check lock first, then the state; after `login()` call `members?->noteSignIn()`.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/members packages/folio` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(members): a locked member signs in nowhere; every sign-in notes last_login_at`

### Task 3: `ConfirmationMail` and `Members::resendConfirmation()`

**Files:**
- Create: `packages/members/src/ConfirmationMail.php`
- Modify: `packages/members/src/MemberMailer.php` (add a synchronous `confirm(DynamicRecord $m, string $token): void` that throws `Preflow\Notify\TransportException`; `confirmLater()` and `confirm()` share the message build)
- Modify: `packages/members/src/Members.php` (optional `?\Closure $confirmationMail = null` resolving `ConfirmationMail`, appended last; `resendConfirmation()`)
- Modify: `packages/members/src/Http/AuthController.php` (`resend()`), `packages/members/src/Http/RegistrationController.php` (the two places that revoke + issue + `confirmLater`), `packages/members/src/MembersServiceProvider.php`
- Test: `packages/members/tests/ConfirmationMailTest.php` (new)

**Interfaces:**
- Produces: `final class ConfirmationMail { __construct(Tokens, MemberMailer, MembersConfig); send(DynamicRecord $m): void /* sync, throws TransportException */; sendLater(DynamicRecord $m, ?\Closure $onFailure = null): void }` — both revoke the member's earlier `CONFIRM_PURPOSE` tokens, issue a fresh one for `confirmTtl()`, and mail it to the stored address.
- Produces: `Members::resendConfirmation(DynamicRecord $m): void` — `\LogicException` unless `MemberState::fromRecord($m) === Unconfirmed`; `\LogicException` for a bare Members; else `ConfirmationMail::send()` (synchronous so staff learn of a failure; `TransportException` propagates).

- [ ] **Step 1: Write failing tests**
  - `test_send_revokes_the_earlier_link_and_mails_a_fresh_one`: issue a token, `send()` → the old token no longer consumes, one mail to the stored address with a `/confirm/` link whose token consumes.
  - `test_resend_confirmation_uses_the_corrected_address`: change `email`, save, `resendConfirmation()` → mail goes to the new address.
  - `test_resend_confirmation_refuses_a_confirmed_member`: `\LogicException`, no mail.
  - `test_resend_confirmation_propagates_a_transport_failure`: failing transport → `TransportException`, and the earlier link is… (decide: revoked before sending is acceptable only if the new token was issued; assert the member can still be resent afterwards).
  - Existing public resend and registration tests stay green unchanged (same mail, same pages).
- [ ] **Step 2: Run** — Expected: FAIL (class not found).
- [ ] **Step 3: Implement**; replace the three revoke/issue/confirmLater sites in the controllers with `ConfirmationMail::sendLater()` (keep their failure closures and log lines).
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/members packages/folio` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(members): ConfirmationMail; Members::resendConfirmation() for staff`

### Task 4: Folio's members admin — Locked badge, lock / unlock / resend actions, readonly fields

**Files:**
- Modify: `packages/folio/src/Members/MemberAdmin.php`
- Modify: `packages/folio/src/Members/templates/decision.twig` (one line in the panel: "Locked since …" when locked; "Last sign-in …" when set)
- Modify: `packages/members/lang/{en,de,fr}/members-admin.php` (the group stays where it is today; F5 moves it later)
- Modify: `packages/folio/src/Members/models/member.json` only if the new fields need list/filter settings (add `locked_at` to `list.filters`)
- Test: `packages/folio/tests/Members/MemberAdminLockTest.php` (new; follow the existing MemberAdmin tests)

**Interfaces:**
- Consumes: Tasks 1 and 3.
- Produces: record actions `lock`, `unlock`, `resend_confirmation` on type `member`, permission `MembersConfig::DECIDE`, `toolbar: true` for lock/unlock, shown only where they apply (lock when not locked, unlock when locked, resend when Unconfirmed); after each a flash notice and back to the form (as `back()` does for errors). Keys: `members-admin.states.locked`, `members-admin.lock.{lock,unlock,resend,locked_since,last_login,done_lock,done_unlock,done_resend,resend_failed,cant_resend}`.
  Check how `#[RecordAction]` decides visibility today (a `visible`/`when` option or a method returning null); if Folio has no way to hide an action for one record, the action refuses with the matching flash instead, and note that as a follow-up — do not add a Folio mechanism in this task.

- [ ] **Step 1: Write failing tests**
  - `test_state_column_shows_locked_for_a_locked_member`: locked admitted member → Badge label = `members-admin.states.locked` translated ("Locked" en, "Gesperrt" de, "Verrouillé" fr), never the declined label; unlocked → the state badge as before.
  - `test_lock_action_locks_and_ends_sessions` and `test_unlock_action_unlocks`, `test_resend_action_mails_an_unconfirmed_member`, `test_resend_action_refuses_a_confirmed_member` (flash error, no mail), `test_resend_action_reports_a_transport_failure` (flash `resend_failed`).
  - `test_actions_need_members_decide`: a staff user without `members.decide` gets 403 for each.
  - `test_panel_shows_locked_since_and_last_sign_in`.
  - de/fr keys exist for every new en key (the package's existing translation-completeness test may already cover this; else assert).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit` (whole repo, ~9 min, output to a file) — Expected: OK, no new failures.
- [ ] **Step 5: Docs and commit:** members README (lock, sessions, resend, last sign-in), folio README members section (the three actions), ROADMAP: strike the "Member lock, sessions and sign-in info" follow-up as done in 0.39 and add a `### v0.39.0 (prepared)` bullet next to the site-admin gaps ones (rename that section heading from v0.38.0 to v0.39.0; 0.38 shipped without them). Commit `feat(folio): lock, unlock and resend in the members admin`
