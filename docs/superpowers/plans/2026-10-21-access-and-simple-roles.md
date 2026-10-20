# Access Contract and Simple Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** One permission question, `can('page.publish')`, asked the same way everywhere, with Folio enforcing it on every admin action and following it in its menu and buttons. A new `preflow/roles` package answers it from a config file. Without it, staff may do everything (as today) and members nothing.

**Architecture:**
- **`preflow/auth`:**
  - `PermissionRegistry` holds the declared permissions.
  - `PermissionResolver` is the interface that answers.
  - `Access` is the service code calls, which knows the current user and its population.
  - The `can()` Twig function goes to `Access`.
  - The default resolver: staff allow-all, members deny.
- **`preflow/roles`:** a resolver reading `config/roles.php`: roles per population, with permission patterns and wildcards. Users' existing `getRoles()` supplies their roles.
- **Folio:**
  - declares its permissions (`{type}.view|create|edit|publish|delete`, `assets.view|upload|edit|delete`, `folio.dashboard`);
  - checks them in one middleware, plus the publish check inside `update`;
  - filters its sidebar, list buttons and form buttons by them.

**Tech Stack:** PHP 8.4, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-21-folio-extensions-design.md`: the "Access" section, and the "Calls made after the builder check" section (defaults, undeclared permissions, which guard is asked). This plan is delivery step 1 of that design.

## Global Constraints

- **Unchanged without roles:** a site without `preflow/roles` behaves exactly as today. Every authenticated staff user may do everything, and smeyer.me needs no change.
- **Undeclared permissions:**
  - In debug they throw `UndeclaredPermission`.
  - In production they are denied, and logged once per permission per request through `error_log`, never a 500.
- **Default for members:** named permissions are denied (fail closed).
- **Populations per guard:**
  - set by `config/auth.php` `guards.{name}.population` (`staff` | `members`);
  - Folio marks the guard it authenticates with as `staff` unless config says otherwise;
  - a guard with no population is `members`.
- **Which user is asked:** the user set on `AuthManager` for this request, as today, together with the guard name that set it. Folio's middleware sets it for admin routes.
- **No auth configured:** when `config/auth.php` is absent (Folio without login), `can()` is `true`, matching today.
- **Templates:**
  - CSP-safe, no new inline scripts.
  - Hidden controls are not rendered at all, never just hidden.
  - Every check is also enforced on the server.

## Review Focus

1. **Publishing without permission:** a user with `page.edit` but not `page.publish` must not be able to publish by POSTing `_action=publish` to `update`. The autosave, the restore of a revision, and Discard must not publish either.
2. **Direct requests:** a hidden button is not protection. Every Folio route must be rejected (403) for a user without its permission, including block-editor fragments, preview, move and asset upload.
3. **Wildcards:** `page.*` matches `page.edit` but not `pages.edit` or `page`. `*` matches everything. A role name in a user that config doesn't define gives nothing and doesn't throw.
4. **Routes without a type:** the block-editor endpoints and the dashboard have no type in the URL. They must still require a sensible permission: any type's `edit`, and `folio.dashboard`.
5. **A 403 inside htmx** (autosave, block picker, duplicate) must produce a visible message, not a silent failure.

---

### Task 1: The access contract (`preflow/auth`)

**Files:**
- Create in `packages/auth/src/Access/`:
  - `PermissionRegistry.php`
  - `PermissionResolver.php` (interface)
  - `DefaultResolver.php`
  - `Access.php`
  - `UndeclaredPermission.php`
  - `Pattern.php` (wildcard matching, shared with roles)
- Modify:
  - `AuthManager.php`: `setUser(?Authenticatable, ?string $guard = null)` and `guardName(): ?string`;
  - `AuthExtensionProvider.php`: the Twig function `can(permission, subject?)`;
  - `packages/core/src/Application.php::bootAuth`: bind the registry, the resolver (default) and `Access` as singletons; pass the guard name to `setUser`; read `guards.*.population`.
- Tests: `packages/auth/tests/Access/{AccessTest,PatternTest,PermissionRegistryTest}.php`

**Interfaces:**
- **`PermissionRegistry`:**
  - `declare(string $name, string $label = '', string $group = '')`
  - `declarePattern(string $pattern)`, for a family like `page.*` declared by a type
  - `isDeclared(string $name): bool`
  - `all(): array`
- **`PermissionResolver`:** `allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool`
- **`DefaultResolver`:** `staff` gives true, `members` gives false.
- **`Access`:**
  - `can(string $permission, ?object $subject = null): bool`;
  - `markStaffGuard(string $guard)`;
  - `setResolver(PermissionResolver)`;
  - `populationOf(string $guard): string`.
  - Order of the checks:
    1. no auth configured: true;
    2. undeclared: throw in debug, otherwise deny and log once;
    3. no user: false;
    4. otherwise the resolver.
- **`Pattern::matches(string $pattern, string $permission): bool`:** segments are separated by `.`. `*` as the last segment matches one or more segments, and `*` alone matches everything.

- [ ] **Step 1:** Tests:
  - `PatternTest`: `page.*` matches `page.edit` and `page.blocks.edit`, not `pages.edit` or `page`; `*` matches everything; an exact pattern matches only itself.
  - `PermissionRegistryTest`: declared names and patterns.
  - `AccessTest`:
    - staff allow-all by default;
    - members deny by default;
    - no user: false;
    - an undeclared permission throws in debug and is denied in production with exactly one log line;
    - a custom resolver is used;
    - the population comes from guard config or `markStaffGuard`;
    - no auth means true.
- [ ] **Step 2:** Run. Expected: FAIL (the classes are missing).
- [ ] **Step 3:** Implement. The Twig `can()` resolves `Access` from the container lazily, so templates rendered without auth (Folio stub mode) keep working.
- [ ] **Step 4:** Auth and core suites PASS.
- [ ] **Step 5:** Commit `feat(auth): one permission question — can(), declared permissions, pluggable resolver`.

### Task 2: `preflow/roles` (simple roles)

**Files:**
- Create `packages/roles/` with:
  - `composer.json` (`preflow/roles`, requiring `preflow/auth`)
  - `README.md`
  - `src/RolesServiceProvider.php`
  - `src/ConfigRoleResolver.php`
  - `tests/ConfigRoleResolverTest.php`
  - `tests/RolesIntegrationTest.php`
- Modify: root `composer.json` (autoload and path package), `.github/workflows/split.yml` (add `roles`), `phpunit.xml` (test suite).

**Interfaces:**
- **`config/roles.php`:**
  ```php
  return ['staff' => ['admin' => ['*'], 'editor' => ['page.*', 'news.*', 'assets.*']],
          'members' => ['premium' => ['downloads.premium']]];
  ```
- **`ConfigRoleResolver::allows()`:** true if any of the user's `getRoles()` in that population has a pattern that matches.
- **Edge cases:**
  - A role missing from config grants nothing. It logs once in debug and never throws.
  - A user with no roles grants nothing.
- **The provider:** registered in `config/providers.php`, it calls `Access::setResolver(new ConfigRoleResolver(config))`. Without `config/roles.php`, it keeps the default resolver and logs a hint in debug.

- [ ] **Step 1:** Tests:
  - the resolver: editor allowed `page.edit`, denied `page` and `site.edit`; `*` gives admin everything; members' `premium` role; an unknown role; no roles;
  - integration: a full app with `config/roles.php` and a staff guard; `Access::can` for an editor user.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(roles): simple roles from config/roles.php`.

### Task 3: Folio declares and enforces permissions

**Files:**
- Create: `packages/folio/src/Http/FolioPermissionMiddleware.php`, `packages/folio/templates/admin/forbidden.twig`
- Modify:
  - `FolioServiceProvider`:
    - declare the permissions;
    - mark Folio's guard as staff;
    - add the middleware to the admin routes after `AdminAuthMiddleware`;
    - add a `can()` stub when auth is disabled.
  - `AdminController::update`: the publish check.
  - `PublishingController`.
  - `AdminController::publishingContext`: null the URLs the user may not use.
  - Templates: `_layout.twig` (the sidebar filtered by `{type}.view`, Assets by `assets.view`), `list.twig` (New, Edit, move, Delete), `form.twig` (Save, Publish, Unpublish, Discard, share, restore; a `<fieldset disabled>` without edit), `dashboard.twig` (cards by `{type}.view`).
- Tests:
  - `packages/folio/tests/Integration/FolioPermissionsTest.php`, a full app with `preflow/roles`, an editor and a viewer user;
  - `packages/folio/tests/Http/FolioPermissionMiddlewareTest.php`, the mapping table.

**Interfaces:**
- **Declared permissions:**
  - per catalog type: the pattern `{type}.*` plus its named members `view`, `create`, `edit`, `publish` and `delete`;
  - `assets.view|upload|edit|delete`;
  - `folio.dashboard`.
- **Route → permission mapping** (by handler):

  | Handler | Permission |
  |---|---|
  | `Admin@index` | `folio.dashboard` |
  | `@list`, `@editForm`, `@recordLabel` | `{type}.view` |
  | `@createForm`, `@store` | `{type}.create` |
  | `@update`, `@reorder`, `Publishing@discard\|autosave\|restore\|share\|revoke`, `Preview@preview` | `{type}.edit` |
  | `Publishing@unpublish` | `{type}.publish` |
  | `@destroy` | `{type}.delete` |
  | `Assets@index\|panel` | `assets.view` |
  | `@upload` | `assets.upload` |
  | `@save` | `assets.edit` |
  | `@destroy` | `assets.delete` |
  | `BlockEditor@*` | any `{type}.edit` |
  | `Auth@*` | none |

  Plus: `update` with `_action=publish` also requires `{type}.publish`, checked in the controller.
- **Forbidden response:** a 403 rendered with `forbidden.twig` in the admin layout ("You don't have access to this"). For htmx requests it is a 403 with `HX-Trigger` carrying a message, which the existing error toast shows.

- [ ] **Step 1:** Tests, with roles editor `['page.view', 'page.edit', 'assets.view']` and viewer `['page.view']`:
  - `test_editor_cannot_publish_through_update`: `_action=publish` gives 403 and the record stays unpublished;
  - `test_viewer_gets_403_on_every_write_route` (data provider over the mapping);
  - `test_sidebar_and_buttons_follow_permissions`: the editor sees no Delete and no Publish; the viewer sees a disabled fieldset and no Save;
  - `test_block_editor_requires_some_edit_permission`;
  - `test_htmx_403_carries_a_message`;
  - `test_without_roles_package_everything_is_allowed`: today's behaviour;
  - `test_every_folio_route_has_a_permission`: walk `FolioRoutes::admin()` and assert each non-auth handler is mapped, so a new route can't be forgotten.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the full framework suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(folio): permissions on every admin action, menu and buttons follow them`. Update the READMEs: the auth "Permissions" section, the roles README, and Folio's "Access" section.

### Task 4: Release

- **Framework:**
  1. Run the full suite.
  2. Merge, push and tag `v0.18.0`.
  3. Create the split repo `getpreflow/roles` (public, empty) before the tag, so the split can push.
  4. Wait for the split and Packagist. You register `preflow/roles` on Packagist once (as with notify).
- **smeyer.me:**
  1. Constraint `^0.18 || @dev`, lock, suite, push, deploy.
  2. No roles package: verify Folio behaves exactly as before (login, edit, publish) and that the public site is unchanged.
