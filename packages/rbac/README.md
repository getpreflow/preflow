# preflow/rbac

Roles managed in Folio, answering `can()`.

- Enable the Folio extension `rbac` (`php preflow folio:extensions:enable rbac`) and add
  `Preflow\Rbac\RbacServiceProvider` to `config/providers.php`.
- Roles are `rbac_role` records (Settings → Roles): a name, a label, a population
  (`staff` or `members`) and permission patterns. A user may do X when any of their roles
  in their population has a pattern matching X; unknown roles grant nothing.
- Until a staff role exists, staff may do everything (logged once per request); run
  `php preflow folio:extensions:apply`.
- It replaces `preflow/roles` (`config/roles.php`) while the extension is enabled.
