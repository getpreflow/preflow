# Folio Staff Accounts and Delegated Roles — Design

**Date:** 2026-10-24
**Release:** v0.27.0
**Why now:** step 3 of the holz-objekte.org port (`docs/superpowers/plans/2026-10-23-holzobjekte-port-overview.md`):
two super admins (everything, including staff, roles, settings, extensions) and content admins
(content, pages, moderation; not staff, roles, settings or extensions), managed inside Folio.
**Status:** written without a question round (Steffen asked to move on); the calls are mine.

## Today (survey 2026-10-24)

- Staff log in through the default guard with `ConfigUserProvider`: one user per site from `.env`
  (`FOLIO_ADMIN_EMAIL`, `FOLIO_ADMIN_PASSWORD_HASH`, written by `preflow auth:user`), roles in
  config. No remember-me and no throttle on the Folio login, no "forgot password".
- Permissions: `Access` + a resolver; `preflow/roles` reads `config/roles.php`, `preflow/rbac` keeps
  roles as `rbac_role` records with a matrix editor. Patterns are exact, `x.*` or `*`, no negation.
  Folio declares `folio.dashboard`, `folio.extensions`, `{type}.view|create|edit|publish|delete`,
  `assets.*`, and extension permissions; every admin route and screen is permission-checked,
  fail-closed. New types (including new extension settings) are denied unless a role grants them.
- **No delegation rule:** anyone with `rbac_role.edit` can grant `*` to any role, including their
  own. That is why rbac refuses `rbac_roles` fields on staff user types today.
- Members (v0.26) already show the pattern a staff type needs: a user type on SQL with
  `RecordUserProvider`, `auth_epoch`, Tokens, notify mails, record actions.

## Goals

1. Staff accounts are Folio records (a user type in population `staff`), created, edited,
   disabled and reset by staff allowed to, inside Folio.
2. **Delegation rule:** nobody grants what they don't hold. A role's permissions may only include
   patterns the editor's own permissions cover; a role may only be assigned to (or removed from) a
   staff account if the editor covers every pattern of that role; an account holding roles the
   editor can't grant can't be edited by them (except viewing).
3. No lock-out: nobody can disable, demote or delete the last active account that can manage
   staff and roles. Only that last one is protected: while another active manager remains, a
   manager may drop their own manager role. The protection starts once a manager exists (config
   users never count). *(Corrected 2026-10-24, Rulings 7/8; the first draft also refused stepping
   down while other managers remain.)*
4. Account lifecycle: invite by mail (set-password link), "forgot password" on the Folio login,
   reset by an admin (mail), disable/enable (ends sessions), password change by the user. Folio
   login gets a throttle and optional remember-me.
5. Existing sites keep working unchanged. A site opts in; when it does, its config admin becomes a
   staff record without a lock-out window.
6. holz-objekte can define "super admin" and "content admin" as two roles and enforce them on every
   admin route without per-site code.

## Non-goals

- An audit log (who changed what beyond existing revisions). Later.
- Two-factor login, SSO.
- Role inheritance.
- Changing preflow/roles (config roles) sites: smeyer.me keeps its config admin.

## Decisions

### Where it lives
The staff type ships with **preflow/rbac** as a model part `model:staff` (rbac already owns
roles, their editor and the resolver; staff without managed roles makes no sense). Sites enable it
like any extension part. Population `staff`, `users: staff`, storage alias `staff` else `default`,
transfer off. Fields: email (unique, lowercased), name, password_hash (hidden), roles
(`rbac_roles`, population staff), active (boolean, default true), auth_epoch (hidden, readonly),
last_login_at (readonly), created_at (readonly).

### Login
`config/auth.php` points the default guard's provider at a **`ChainUserProvider`** (new, in
preflow/auth): staff records first (`RecordUserProvider` type `staff`, plus an `active` check),
then the old config user. A site can therefore switch without a lock-out window and drop the config
user later. `RecordUserProvider` gets an optional `active` field config: inactive users never
resolve (login refused with the generic error; existing sessions end on the next request).
Folio `AuthController`: throttle per client and per email (core Throttle, purposes
`folio.login`, `folio.forgot`), constant-cost hashing for unknown emails, optional remember-me
(per guard config; the checkbox shows only when the guard has `remember`), `last_login_at` set on
login for record users. "Forgot password" page and reset page on the Folio login (Tokens purpose
`folio.reset`, notify mail), only for record users; config users get the generic answer.

### Delegation (preflow/rbac)
- `PermissionCoverage::covers(list<string> $held, string $pattern): bool` — `*` covers all, `x.*`
  covers `x.*` and `x.anything` but not a bare `x` (as `Pattern::matches`; corrected 2026-10-24,
  Ruling 3), exact covers exact; a pattern is grantable iff covered by some held
  pattern. Also `coversAll(held, patterns)`.
- `RoleRules` (BeforeSave on `rbac_role`): adding a pattern the editor doesn't cover → field error
  "You can't grant permissions you don't have: {patterns}". Removing patterns is allowed only if the
  editor covers the whole role (you can't edit a role above you). Existing lock-out rules stay.
- `StaffRules` (BeforeSave/BeforeDelete on `staff`): adding or removing a role requires covering
  that role; editing any field of an account whose roles the editor doesn't fully cover is refused;
  the last active account holding `staff.edit` + `rbac_role.edit` can't be disabled, demoted or
  deleted, by others or by itself (Ruling 7: only the last one; Ruling 8: once one exists).
- The UI mirrors it: the permissions matrix disables what you can't grant; the roles checkboxes
  disable roles you don't cover. Server-side is the authority.
- The `rbac_roles`-on-staff refusal in `RbacServiceProvider` goes.

### Lifecycle actions (record actions on `staff`)
- **Invite** (on create, and as an action while no password is set): issues a `folio.invite` token
  (7 days) and mails a set-password link; the account can't log in until a password is set.
- **Send reset link**: mails a `folio.reset` link (1 hour).
- **Disable / Enable**: toggles `active`; disabling bumps `auth_epoch` and revokes remember-me.
- Password pages (`/folio/password/{token}`) are public, throttled, GET shows a form (scanner-safe),
  POST consumes. Copy en/de/fr from package lang files (the i18n defaults from v0.26).
- The account's own page: "change my password" (current password required) bumps the epoch.

### Permissions for the two holz-objekte roles (example, documented)
- super admin: `*`.
- content admin: `folio.dashboard`, `page.*`, each content type `{type}.*`, `assets.*`,
  `members.decide`, extension screens for moderation — but not `staff.*`, `rbac_role.*`, any
  `*_settings.*`, `folio.extensions`. Because there is no negation, the content-admin role lists
  what it gets; new types stay hidden until granted (safe default).

### Migration
`preflow folio:staff:import-config` (rbac command, registered via `extra.preflow.commands`): for
each config user of the default guard's provider, creates a staff record with the same email,
password hash (moved as-is), name = email, roles mapped by name (role `admin` exists from rbac
install); idempotent (existing email skipped). Revision `created_by` values (emails today, record
ids after) are displayed through a lookup that shows the staff name for ids and the raw value for
emails.

### Sites
- holz-objekte: staff from day one.
- smart-stack: may switch later (rbac present; enable `model:staff`, chain provider, import).
- smeyer.me: unchanged (preflow/roles, config admin).

## Review focus

- Delegation escape routes: crafted POSTs to the role matrix, the staff roles field, record actions,
  import/transfer (staff transfer is off), and editing your own account.
- Lock-out: last super admin disabled/demoted/deleted, own role removal, a role edit that removes
  `staff.edit` from all holders.
- Disabled account with a live session or a remember cookie.
- Enumeration and throttle on the Folio login, forgot and set-password pages.
- A site that never enables `model:staff` sees no change at all.
