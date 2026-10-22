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
  Create, Edit, Publish, Delete) and "All" for the type's `{type}.*`. Every other declared
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

The field lists the roles of the type's `users` population; `"population": "staff"` on the
field lists another population's. The value is a list of role names.

## Lock-out protection

A save or delete of a role is refused when, on the roles as they would be afterwards:

- the signed-in user's own roles no longer grant `rbac_role.edit` ("You would lose the
  right to edit roles."), or
- no staff role grants `rbac_role.edit` any more.

This covers removing permissions, renaming the role, moving it to `members` and deleting
it. A refused save shows the form again with the message; a refused delete keeps the role.
Before the extension is installed, the same check applies: the first role you save must
grant yourself `rbac_role.edit` (or run `folio:extensions:apply`, which creates `admin`).

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
