# preflow/rbac

Roles managed in Folio, answering `can()`.

## Install

```
composer require preflow/rbac
php preflow folio:extensions:enable rbac
php preflow folio:extensions:apply
```

- Add `Preflow\Rbac\RbacServiceProvider` to `config/providers.php` (after
  `FolioServiceProvider`). It does nothing while the Folio extension `rbac` is not enabled
  (`config/folio/extensions.json`).
- Enabled, it answers `can()` from the roles and registers the field types
  `rbac_permissions` and `rbac_roles`.
- `folio:extensions:apply` (run it on every deploy) installs the extension once per
  version and per environment:
  - It creates the staff role `admin` ("Administrator", `*`) when no staff role exists.
  - If `config/roles.php` exists, it imports every role it defines that doesn't exist yet,
    in `preflow/roles`' format: `['staff' => ['editor' => ['page.*']], 'members' => [...]]`.
  - It never changes a role that exists, so running it again is safe.
- Sites seed their own roles the same way:
  `RoleStore::ensure(string $population, string $name, string $label, array $permissions)`
  creates a role when it is missing and never overwrites one.

## Roles

Roles are `rbac_role` records, edited under *Settings → Roles*: a name (unique per
population), a label, a population (`staff` or `members`) and permissions.

- The permissions editor has "Everything" (`*`) as a switch on top, then the content types
  as a matrix: one row per type, grouped by sidebar section, a column per verb (View,
  Create, Edit, Publish, Delete) and "All" for the type's `{type}.*`. A verb that cannot
  apply shows as "–": create and delete on a singleton, publish on a type without
  publishing. Such a name, if a role has it stored, is kept unchanged on save. Every other declared
  permission (Dashboard, assets, extensions' own) is a chip under its group, with the
  group's pattern (e.g. `assets.*`) as the group's "All". With JavaScript, what
  "Everything" or an "All" already grants is shown checked and locked. A stored name that
  is no longer declared is shown as unknown and dropped on the next save.
- A user may do X when any of their roles (`getRoles()`) defined for their population has
  a pattern matching X. Unknown roles grant nothing (logged in debug).
- Roles stored as a JSON string (as SQL returns a list) or a comma-separated string work.

## User types

A user type gives its users roles with an `rbac_roles` field:

```json
{ "key": "member", "users": "members", "fields": { "roles": { "type": "rbac_roles" } } }
```

The field lists the roles of the type's `users` population; a `"population"` on the field
names another one. The value is a list of role names.

## Delegation

Nobody grants permissions they don't hold. Coverage follows how permissions match:

- `*` covers everything.
- `x.*` covers the pattern `x.*` and every name or pattern below `x.` (`x.view`, `x.y.z`,
  `x.y.*`). It does **not** cover a bare `x`, just as `x.*` doesn't grant a permission named `x`.
- A name covers only itself.

When a user is signed in:

- **Roles.** A role save or delete is refused unless the user's own roles cover every
  pattern the role has now, so nobody touches a role above them. Every pattern the save adds
  must be covered as well ("You can't grant permissions you don't have: {patterns}"). A
  stored pattern that is no longer declared counts as part of the role, so only `*` can edit
  such a role. The same rule holds for member roles.
- **Accounts.** An account holding a role the editor doesn't fully cover can't be changed,
  disabled, reset or deleted by them ("You can't change an account with roles you can't
  grant."). Viewing it is still a matter of `staff.view`. Every role added to or removed
  from an account must be covered.
- The permissions editor and the roles checkboxes show what you can't grant as disabled ("You
  can't grant this"). The server check is the authority; a crafted POST gets the same
  refusal.

The messages are in English, like the rest of Folio's admin. A site's `lang/en/rbac.php`
changes single keys; de and fr are in `lang/`. Without a signed-in user (the CLI:
`folio:extensions:apply`, the staff import) delegation does not apply.
`Preflow\Rbac\PermissionCoverage` has the check: `covers()`, `coversAll()`, `uncovered()`,
and `held()` for the signed-in user (null when nobody is signed in).

## Staff accounts

Staff accounts are records, managed in Folio by staff who may. The part `model:staff` is
**opt-in**: enabling rbac doesn't add it. A site lists it under the extension's
`enabled_parts` in `config/folio/extensions.json` and runs `folio:extensions:apply`:

```json
{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}
```

- **Type.** Type and table `staff`, shown under Settings. It uses the storage alias `staff`
  when the site has one, else `default`. It is a user type (population `staff`), so it is
  never exported.
- **Fields.**
  - `email`: stored trimmed and lowercased, unique.
  - `name`
  - `password_hash`: hidden.
  - `roles`: `rbac_roles`, population staff.
  - `active`: default true.
  - `auth_epoch`: hidden, `"0"` for a new account.
  - `last_login_at` and `created_at`: read-only.
- **Sign-in.** Staff sign in through a `RecordUserProvider` (`'type' => 'staff', 'active' =>
  'active'`). While a config admin remains, put it first in a `ChainUserProvider`, before the
  config users (see preflow/auth and the migration below). A disabled account never signs in,
  and its open sessions end on the next request.
- **Role names.** A role is added to an account only by the name of a defined staff role
  ("Unknown role: {names}."), so nobody squats a name that a role gets later. A stored name
  that is no longer defined may stay. This applies on every path, the CLI included.

### Two roles: super admin and content admin

There is no negation, so a narrower role lists what it gets. New types, including the
settings of an extension enabled later, stay hidden from it until granted.

- `super_admin`: `*`.
- `content_admin`: `folio.dashboard`, `page.*`, `{type}.*` for each content type,
  `assets.*`, and the permissions of the moderation screens (e.g. `members.decide`). It does
  not get `staff.*`, `rbac_role.*`, any `*_settings.*` or `folio.extensions`.

A content admin can't edit a super admin's account or role, and can't grant what they don't
hold. `tests/TwoRolesTest.php` checks both roles on every admin route and screen.

### No lock-out

Every save and delete of an account or a role, the CLI included, must leave at least one
active **manager** ("At least one account must keep the right to manage staff and roles.").
A manager is an active staff account whose roles grant `staff.edit` and `rbac_role.edit`.

- Only the last active manager is protected. Disabling, demoting or deleting them is
  refused, and so is a role edit that takes either permission away from them. Removing your
  own manager role is refused only if you are that last one; while another active manager
  remains, a manager may step down.
- The protection starts once a manager exists. Before that (the config admin era,
  bootstrapping) creating accounts isn't blocked. Config users never count as managers; the
  import makes the first manager.

### Account lifecycle

`Types/StaffAdmin` adds an **Account** panel to the staff form, with record actions. They
need the permission `staff.edit`. Each one is checked by `StaffRules` on the record as it
would be saved. A crafted POST to an account above you, or disabling the last manager, gets
the same refusal as the form, shown as a flash.

- **Invite.**
  - A new account is stored without a password, so it can't sign in.
  - Once it is stored, it gets an invitation mail (Folio's `StaffPasswords::invite()`): a
    set-password link valid for 7 days, sent after the response.
  - If the mail can't be queued, the account is kept and the flash says so.
  - "Send invitation" stays on the panel while no password is set.
- **Send reset link.** A set-password link valid for one hour.
- **Disable / Enable.** Disabling, by the action or by unticking Active in the form:
  - bumps `auth_epoch`, so live sessions end;
  - forgets the account's remember-me logins;
  - voids its invite and reset links.

  Enabling changes nothing else, so old cookies and links stay dead.
- **Own account.** Every signed-in staff account has its own page at `{prefix}/_account`
  (the user menu links there). It needs no permission: an account without roles may open it.
  - Changing the password asks for the current one and is throttled (`folio.password`).
  - It signs out the account's other sessions.
  - It changes nothing but the password.

Mails need `config/notify.php`; the throttles need `APP_KEY`. The action messages are `rbac.staff.*` in
`lang/{en,de,fr}/rbac.php`.

### Moving a config admin to staff

`php preflow folio:staff:import-config` creates a staff record for each config user of the
default guard's provider (directly, or inside a chain).

- The e-mail is lowercased. The password hash moves as is, so the old password keeps
  working.
- `name` is the e-mail, `active` is true, the epoch is `"0"`.
- Roles are mapped by name to the staff roles that exist (`admin`, `*`, comes from the
  install). An unknown name is skipped with a warning.
- The rules above apply. An e-mail that already has a record is skipped, so the command is
  safe to repeat. It never prints a hash.

For an existing site with an `.env` admin:

1. In `config/auth.php`, point the guard at a chain: staff records first, then the config
   users:

   ```php
   'providers' => [
       'staff'  => ['class' => Preflow\Folio\Users\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
       'config' => ['class' => Preflow\Auth\ConfigUserProvider::class, 'users' => [/* the .env admin */]],
       'chain'  => ['class' => Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
   ],
   'guards' => ['session' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff']],
   ```

   While the staff type isn't enabled yet, the chain resolves the config user as before.
2. Enable `model:staff` (`enabled_parts`) and run `php preflow folio:extensions:apply`.
3. Run `php preflow folio:staff:import-config`.
4. Sign in with the old password. The record now decides for that e-mail; the config entry no
   longer answers it.
5. Later, remove the config user (and the chain, pointing the guard at `staff`) and the
   `FOLIO_ADMIN_*` values in `.env`.

Revisions store the author's id from then on. Folio shows the account's name for an id and
the stored e-mail for older revisions.

### Limits

- `folio:import` and `folio:pull` write roles without the delegation check. They need
  server access, like the CLI. (Staff accounts are a user type and never travel.)
- There is no lock around the last-manager check. Two managers demoting each other at the
  same moment can leave none. Recover with a config user in the chain or the CLI.
- Editing a role you cover also changes every account holding it, including accounts above
  you that also hold that role. Removing a pattern from it can demote them.
- Disable and Enable save the record directly. They skip other extensions' save hooks, and
  they write no revision.
- An opt-in part listed under both `disabled_parts` and `enabled_parts` is off.

## Keeping the right to edit roles

A save or delete of a role is refused when, on the roles as they would be afterwards:

- the signed-in user's own staff roles together no longer grant both `rbac_role.view`
  (the roles list and edit form) and `rbac_role.edit` ("You would lose the right to edit
  roles."), or
- the staff roles together no longer grant both.

This covers removing permissions, renaming the role, moving it to `members` and deleting
it. A refused save shows the form again with the message; a refused delete keeps the role.
Before the extension is installed, the same check applies: the first role you save must
grant yourself `rbac_role.view` and `rbac_role.edit` (or run `folio:extensions:apply`,
which creates `admin`).

## Bootstrap

Until `folio:extensions:apply` has installed the extension in this environment, staff may
do everything (logged once per request), so enabling RBAC never locks out the admin. Once
installed, staff get only what their roles grant; with no staff role at all they get
nothing (logged: "no staff roles defined; run folio:extensions:apply or create one under
Settings → Roles").

## Switching from preflow/roles

`preflow/rbac` replaces `preflow/roles` while the extension is enabled (its resolver wins).

1. Require `preflow/rbac`, add its provider, enable the extension.
2. Run `php preflow folio:extensions:apply`: the roles of `config/roles.php` become
   `rbac_role` records (and `admin`, if you had no staff role).
3. Check the roles under *Settings → Roles*, then remove `preflow/roles` and
   `config/roles.php` when you like; later edits happen in Folio only.
