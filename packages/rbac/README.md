# preflow/rbac

Stored roles answering `can()`, and staff accounts with their commands. rbac needs no admin:
`php preflow rbac:install`, the `rbac:*` and `staff:*` commands and `StaffAccounts` work on
their own. With Folio, Folio carries their admin (its built-in extension `rbac`: the Roles and
Staff screens, the field types `rbac_permissions` and `rbac_roles`, the new-permissions notice).

## Install

```
composer require preflow/rbac
php preflow rbac:install            # without Folio
php preflow folio:extensions:enable rbac   # with Folio: the admin
php preflow folio:extensions:apply
```

- Add `Preflow\Rbac\RbacServiceProvider` to `config/providers.php`. Where it runs (and the site
  has `config/data.php`) it answers `can()` from the roles; Folio's extension switch only shows
  or hides the admin (`config/folio/extensions.json`).
- The admin's classes moved into Folio in 0.69 (`Preflow\Folio\Rbac\…`); their old names
  (`Preflow\Rbac\Extension`, `Fields\*`, `Types\*`, `NewPermissions*`) work until 0.70.
- `folio:extensions:apply` (run it on every deploy) seeds the roles on every run (since 0.53):
  - It creates the staff role `admin` ("Administrator", `*`) when no staff role exists.
  - If `config/roles.php` exists, it creates every role it defines that doesn't exist yet,
    in `preflow/roles`' format: `['staff' => ['editor' => ['page.*']], 'members' => [...]]`,
    and gives an existing role the permissions the file lists for it for the first time
    (see Grant-once seeding below).
  - Running it again is safe.
- Sites seed their own roles the same way, from an extension's `seed()` (it runs on every apply):
  `RoleStore::ensure(string $population, string $name, string $label, array $permissions)`.
  Before 0.53 such calls sat in `install()`, which runs once per version; move them to
  `seed()`. `apply` resolves the extension on every run, so keep its constructor light.
- A seeded role that someone deletes in Folio comes back on the next deploy. To retire one,
  remove it from `config/roles.php` (or from the extension's seed) first.

## Grant-once seeding

`ensure()` creates a missing role. For an existing role it gives only the listed permissions
it has never given before, and remembers the list in the role's hidden field
`seeded_permissions` (since 0.53). So:

- A permission added to a seed list reaches the role once, on the next apply.
- A permission an editor removed from the role stays removed: it is recorded, so no later run
  adds it again.
- A permission an editor added stays too.
- First sight: a role that has no record yet (made before 0.53) is assumed to have had what it
  has now plus the seed list. The record is made and nothing is added. A permission that was
  listed before 0.53 but never reached the role stays missing; add it by hand once.
- Only a site's own seed list grants. A permission that a Folio release, package or extension
  declares is never given to a role on its own; see New permissions below.

A site that kept its own `extends` file adding `seeded_permissions` to `rbac_role` (a project of the framework's early days
did) must delete that file when it upgrades to 0.53. Boot fails otherwise, naming the field.
The stored records carry over.

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
- Declared permissions: a granted name should be `*`, a permission or pattern declared in
  auth's `PermissionRegistry`, or a pattern covering a declared permission. `rbac:role
  <name> --grant=a,b` refuses any other name (nothing changes) unless you add
  `--allow-undeclared`. The seed from `config/roles.php` (`rbac:install`, `migrate`,
  `folio:extensions:apply`) grants such a name anyway, so a deploy never depends on the order
  in which packages declare, and reports each: a line in the `rbac:install` and `migrate`
  output (each line once; the commands print them, nothing goes to stderr as well). With a
  PSR-3 `LoggerInterface` bound, `RoleStore::seed()` also logs each at warning level:
  `rbac: config/roles.php: "job.view" (staff role "editor") is not a declared permission; granted anyway.`
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

The messages are in English; de and fr are in `lang/` (Folio's admin has its own group,
"rbac-admin"). A site's `lang/en/rbac.php` changes single keys. Without a signed-in user (the CLI:
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
  - `email`: stored trimmed and lowercased, unique. In the admin, a new or changed address
    can't be one of a config user of Folio's staff guard ("This e-mail address belongs to a
    configured admin."): the record would take over that sign-in. The import moves those.
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
  - Once it is stored, it gets an invitation mail (`StaffAccounts::invite()`): a
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

### Staff commands

`StaffAccounts` (a container service) holds the rules and the lifecycle above for every way in:
Folio's screens, code and these commands (they refuse before `rbac:install` and while the staff
table is missing, naming the command that makes it; the console has no
delegation limit, the other rules apply; a refusal prints why and exits 1):

- `staff:list`: e-mail, name, roles and state (active, disabled, no password yet).
- `staff:create <email> [--name=…] [--role=a,b]`: an active account without a password.
- `staff:password <email>`: asks twice without echo, or reads one line from STDIN.
- `staff:invite <email>`, `staff:reset <email>`: mail a set-password link (7 days, one hour).
  The link opens the page a `Preflow\Rbac\Staff\StaffLinks` binding names (Folio binds its
  set-password page); without one they refuse. The mails are `rbac-mail.*` in
  `lang/{en,de,fr}/rbac-mail.php`, in the account's language when a `StaffLocales` binding
  knows it (Folio: the interface language).
- `staff:disable <email>`, `staff:enable <email>`.

Without Folio (or with Folio's part `model:staff` off), a site signs staff in with a
`RecordUserProvider` of `'type' => 'staff'` on its staff guard; rbac then defines the type, and
`rbac:install` makes its table.

### Moving a config admin to staff

`php preflow staff:import-config` (until 0.70 also `folio:staff:import-config`) creates a staff
record for each config user of the staff guard (directly, or inside a chain). The staff guard is
`config/rbac.php` `'guard'`, else the one an admin names (Folio: `config/folio.php` `'guard'`,
else the default guard), else the default guard. Both this and the config-user check above read
that guard's provider, never the default guard's when another is named, so an employers or
members guard's users never become staff.

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
       'staff'  => ['class' => Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
       'config' => ['class' => Preflow\Auth\ConfigUserProvider::class, 'users' => [/* the .env admin */]],
       'chain'  => ['class' => Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
   ],
   'guards' => ['session' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff']],
   ```

   The staff type exists once a provider names it (rbac defines it; with Folio, `model:staff` adds
   its screens); run `php preflow migrate` for its table. While there are no records, the chain
   resolves the config user as before.
2. Enable `model:staff` (`enabled_parts`) and run `php preflow folio:extensions:apply`.
3. Run `php preflow staff:import-config`.
4. Sign in again with the old password. Once the record exists, it decides for that e-mail:
   the config entry no longer answers it, and the config-era sessions and remember-me cookies
   end on their next request (from now on, disabling or demoting the record takes effect).
5. Later, remove the config user (and the chain, pointing the guard at `staff`).

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

## New permissions

When an update brings a permission (a Folio release, a new package, extension or content
type; 0.52 brought `redirects.manage`), it is never given to a role on its own. Whoever may
edit roles (`rbac_role.edit`) is told, and decides (since 0.53):

- **Settings → Roles** shows a notice above the list: one line per permission group (a new
  content type's five permissions are one line), with the roles that already have it and how
  ("Already in: Administrator (`*`), Content admin (`assets.*`)"), so you also learn when a
  role got something new through a pattern.
- **Add to role…** gives the line's permissions to the role you pick, exactly as the role
  editor would: delegation applies (the picker offers only roles you may give them; a crafted
  POST gets the editor's refusal), and so does the lock-out check. The picker offers staff roles
  only (member roles never get admin permissions from this notice) and leaves out roles that
  already have all of the line's permissions, such as `*`. It saves through the type's
  `#[BeforeSave]` and `#[AfterSave]` hooks and its validation, like the role editor, so a site's
  own hooks on `rbac_role` run for it too.
- **Dismiss** takes the line away without granting anything. It is offered only for permissions the user
  holds (since 0.54.1), like Add to role: a delegated role manager can't hide a permission from those who could
  grant it.
- The *Roles* item in the sidebar carries the count, and the dashboard has a line linking here.

A permission leaves the notice only through Dismiss or Add to role; then it is known for good.
Granting it in the role editor does not clear it: the notice then names that role, and a
Dismiss clears it. Which permissions are known is Folio's list
(`Preflow\Folio\Permissions\KnownPermissions`; its table is created by `preflow migrate` or
`folio:extensions:apply` on SQL storage, JSON storage needs neither); until then the
notice, badge and line show nothing. `preflow migrate` prints the new ones too, without
marking them known. A switched-off extension's permissions wait until it is switched on.

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

## Password minimum

`config/rbac.php` `'password_min'` sets the shortest staff password (default
`StaffAccounts::PASSWORD_MIN`, 10). `StaffAccounts::passwordMin()` returns it and
`StaffAccounts::checkPassword($password)` throws the same `StaffRefused`
(`PASSWORD_SHORT`, field `password`) as `setPassword()`. A set-password page calls
`checkPassword()` before it consumes the link (`AccountLinks::consume()` uses the token up), so
a password that is too short doesn't cost the person their link.
