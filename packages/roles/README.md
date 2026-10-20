# preflow/roles

Simple roles for Preflow. It answers the one permission question,
`can('page.publish')`, from a config file: each role is a list of permission
patterns.

```bash
composer require preflow/roles
```

```php
// config/providers.php
return [\Preflow\Roles\RolesServiceProvider::class /* , ... */];
```

```php
// config/roles.php
return [
    'staff' => [
        'admin'  => ['*'],
        'editor' => ['page.*', 'news.*', 'assets.*'],
    ],
    'members' => [
        'premium' => ['downloads.premium'],
    ],
];
```

A user's roles come from `getRoles()` (for the config user provider, the
`roles` key of each user). Roles are looked up in the user's population only:
`staff` for guards marked as staff (Folio's, or `population: 'staff'` in
`config/auth.php`), `members` for everyone else.

## Patterns

- `page.edit` matches exactly that permission.
- `page.*` matches everything below `page.` (`page.edit`, `page.blocks.edit`),
  but not `pages.edit` and not `page` itself.
- `*` matches every permission.

## Edge cases

- A role in a user that `config/roles.php` doesn't define grants nothing. In
  debug it is logged once per request; it never throws.
- A user without roles gets nothing.
- Without `config/roles.php` the package changes nothing: staff may do
  everything, members nothing (the `preflow/auth` default).

For roles edited in the admin, per-record rules and user management, use a full
RBAC package instead; code asks `can()` the same way either way.
