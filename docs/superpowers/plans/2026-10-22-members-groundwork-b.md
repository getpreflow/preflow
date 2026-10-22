# Members Groundwork B Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** finish step 1 of the members area:
- storage aliases, and the Folio sidebar structured from model JSON;
- `preflow/rbac`: roles and permissions edited in Folio, `can()` answered from them;
- `preflow/codes`: codes and invites with expiry, limits, revocation, redemptions and
  throttling;
- smart-stack.works adopting all of it;
- the framework ready for release 0.20.

**Architecture:** Both new packages are Composer packages that ship a Folio extension
(`extra.folio.extension`) plus a service provider.
- **RBAC:**
  - Roles are an extension model type (`rbac_role`) whose permissions field is an RBAC
    field type.
  - Users' roles are another RBAC field type.
  - Lock-out protection runs as hooks.
  - A `PermissionResolver` reads the roles.
- **Codes:**
  - Codes are an extension model type (`code`, not transferred). Redemptions live in their
    own table.
  - A `Codes` service issues, checks and redeems codes.
  - A record action revokes, and a panel and a list column show redemptions.
- **Data placement:** both packages name storage aliases that the site maps in
  `config/data.php`.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig, SQLite, vanilla JS, no build step.

**Spec:** `docs/superpowers/specs/2026-10-22-members-groundwork-design.md`:
- sections 3, 4 and 6;
- "Amendments (2026-10-22, after plan A)", which override sections 3 and 4 where they
  differ.

Follow-ups from plan A: `docs/superpowers/plans/2026-10-22-members-groundwork-a-followups.md`.

## Global Constraints

- **The extension API is plan A's, as documented in `packages/folio/README.md` (sections
  Extensions to SQL storage).**
  - Attributes:
    - `FolioExtension`, `FolioScreen`, `FolioType` and `DeclaresPermission` live in
      `Preflow\Folio\Extension\Attributes\`.
    - `FormPanel`, `RecordAction`, `ListColumn`, `BeforeSave`, `AfterSave`, `BeforeDelete`
      and `AfterDelete` live in `Preflow\Folio\Extension\`.
  - A delete is refused by throwing `Preflow\Folio\Extension\Customization\Refused`.
  - Package extensions:
    - Declared in the package's `composer.json` as `extra.folio.extension`.
    - The `Extension.php` lives in the package's `src/`, so the strict folder scan never
      sweeps in `tests/`.
    - Models live in `src/models/*.json` and templates in `src/templates/`.
- **New packages:**
  - Names: `preflow/rbac` (namespace `Preflow\Rbac\`) and `preflow/codes` (namespace
    `Preflow\Codes\`), under `packages/rbac` and `packages/codes`.
  - Composer constraints: `"^0.20 || @dev"` on preflow packages.
  - Monorepo registration:
    - `.github/workflows/split.yml`: one matrix line `{ local: 'packages/X', remote: 'X' }`.
    - Root `composer.json`: a `require-dev` entry `"preflow/X": "@dev"`, a path
      repository with `symlink: true`, and an `autoload-dev` `Preflow\X\Tests\` entry.
    - `phpunit.xml`: a `<testsuite name="Rbac|Codes">` and a `<source>` directory.
    - Then `composer update preflow/rbac preflow/codes`.
- **Security:**
  - A disabled permission is never granted.
  - Undeclared permissions throw in debug.
  - Codes are bearer tokens: compare them with `hash_equals` after normalising, and give
    visitors one generic failure message.
  - Redemptions store no IP address or user agent.
  - Codes and redemptions never transfer.
- **No regressions:**
  - smeyer.me and preflow.dev keep working on 0.19 without changes.
  - A site without `config/data.php` `aliases` behaves as before.
  - A model JSON without `section`, `order`, `icon` or `nav` keeps today's sidebar place.
- **Loud failures:**
  - An unknown alias target, an unknown nav section value type, or a codes alias that is
    not configured is an error at boot (or in `folio:extensions:check`), never a silent
    fallback. The one exception is the `throttle` alias, which may be absent.
- **Tests:** run from the repo root.
  - Per suite: `vendor/bin/phpunit --testsuite <Data|Core|Folio|Auth|Rbac|Codes>`.
  - Everything: `vendor/bin/phpunit`.
  - smart-stack.works: `vendor/bin/phpunit` in `/Users/smyr/Sites/gbits/smart-stack.works`.
  - Full suites green at the end of every task.
- **Branches and commits:**
  - The framework branch is `feat/members-groundwork-b` off `main`.
  - smart-stack.works uses branch `feat/members-groundwork` off its `main`.
  - Commit subjects are conventional, with the package scope.
- **Out of scope here:**
  - Pushes, tags, Packagist and deploys. They are user-gated and listed in Task 8's
    release checklist, not executed.
  - Never import or commit the personal data in `~/Sites/gbits/smart-stack.works-import/`.

## Review Focus

1. **RBAC is switched on before `folio:extensions:apply` has created any role.** No staff
   role exists, so the logged-in admin must keep full access, not be locked out. The test
   is in Task 3: `test_no_staff_roles_yet_staff_may_do_everything`.
2. **A member record read back from SQLite has `roles` as a JSON string.** `can()` must
   still resolve it. Tests:
   - Task 3: `test_roles_as_json_string_resolve`.
   - Task 7: `test_member_roles_roundtrip_on_sqlite`.
3. **A visitor types the book code in a different shape** (lowercase, spaces, dashes):
   it must match. A random invite code with surrounding whitespace from a pasted URL must
   match too. The test is in Task 5: `test_readable_code_normalised`.
4. **A valid code after too many failed attempts from the same client** is refused as
   throttled. Without a client it is not throttled. Test in Task 5:
   `test_throttled_client_refused_even_with_valid_code`.
5. **An editor removes their own last grant to edit roles,** or deletes the role that
   gives it to them. The change is refused with a message, and the roles are unchanged.
   Tests in Task 4:
   - `test_save_removing_own_role_edit_refused`
   - `test_delete_own_granting_role_refused`

---

### Task 1: Storage aliases, and the throttle on its own alias

**Files:**
- Modify: `packages/core/src/Application.php` (`bootDataLayer()`, Throttle binding)
- Test:
  - `packages/core/tests/DataAliasesTest.php` (new)
  - `packages/core/tests/Throttle/ThrottleTest.php`

**Interfaces:**
- **Produces, aliases:**
  - `config/data.php` may contain `'aliases' => ['codes' => 'sqlite', 'throttle' => 'sqlite']`.
  - After the drivers are built, each alias becomes `$drivers[$alias] = $drivers[$target]`
    (the same instance).
  - A target that is not a configured driver key throws
    `\RuntimeException("Storage alias [{$alias}] points to [{$target}], which is not a configured driver.")`.
  - An alias that collides with a driver key throws as well.
- **Produces, throttle:** Throttle is constructed with storage `'throttle'` when that
  alias exists, otherwise `'default'`.

- [ ] **Step 1: Write the failing tests:**
  - `test_alias_resolves_to_same_driver`
  - `test_alias_to_unknown_driver_throws`
  - `test_alias_colliding_with_driver_key_throws`
  - `test_throttle_uses_throttle_alias_when_configured` (a JSON default plus a SQLite
    `throttle` alias: rows land in SQLite)
  - `test_throttle_defaults_to_default_storage`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Core --filter 'Alias|throttle_alias|defaults_to_default'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Core`, then
  `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Document.** Add a short "Aliases" section to `packages/data/README.md`
  and update the Throttle section of `packages/core/README.md`.
- [ ] **Step 6: Commit.** `feat(core,data): storage aliases in config/data.php; the throttle uses the throttle alias when set`

### Task 2: Folio sidebar from model JSON, plus admin follow-ups

**Files:**
- Modify:
  - `packages/folio/src/Content/TypeListing.php`
  - `packages/folio/src/Content/TypeCatalog.php`
  - `packages/folio/src/Http/AdminNav.php`
  - `packages/folio/src/FolioServiceProvider.php` (the `'content'` passed for extension
    models, ~line 80)
  - `packages/folio/src/Http/AdminController.php` (record action buttons, ~line 632)
  - `packages/folio/README.md`
- Test:
  - `packages/folio/tests/Http/AdminNavTest.php`
  - `packages/folio/tests/Content/TypeCatalogTest.php`
  - `packages/folio/tests/Extension/CustomizationTest.php`

**Interfaces:**
- **New top-level model JSON keys:**
  - `"section"`: a string matching `/^[a-z][a-z0-9_-]*$/`. The default is the argument
    given to `add()`, or `'content'` for files.
  - `"order"`: int, default 100.
  - `"icon"`: a string matching `/^ui-[a-z0-9-]+$/`. Default `ui-settings` for singletons,
    `ui-file` otherwise.
  - `"nav"`: bool, default true.
  - A wrong type for any of these throws `FrontendConfigException` naming the type.
  - A JSON value wins over the section an extension model is registered with.
- **TypeListing:** gains `int $order = 100`, `string $icon = ''` (empty means "derive")
  and `bool $nav = true`.
- **AdminNav built-in sections, in order:**
  - `dashboard` (no label)
  - `pages` ("Pages")
  - `content` ("Content")
  - `library` ("Library")
  - `settings` ("Settings")
  - Any other section name is appended after them, labelled `ucfirst(str_replace(['_', '-'], ' ', $name))`.
- **AdminNav items:**
  - Catalog types go into `$listing->section` with `$listing->order` and the resolved
    icon.
  - `nav: false` types are left out.
  - Empty sections are still dropped.
- **Reserved type keys:** a catalog type key equal to a Folio admin first segment
  (`login`, `logout`, `_assets`, `_uploads`, `_blocks`, `media`, `extensions`, `blocks`,
  `preview`, `upload`, `assets`) throws `FrontendConfigException`. Reuse the screen
  registry's `RESERVED` list from one place.
- **Files of non-transferred types:** a type with `transfer === false` and a field of type
  `asset`, or any field type that handles uploads, throws
  `FrontendConfigException("… keeps its records on the server, but its files would still be exported; file fields are not supported on it yet.")`.
- **Record action buttons** are shown only when both the action's permission and
  `{type}.edit` pass.

- [ ] **Step 1: Write the failing tests:**
  - `test_model_json_section_order_icon` (pages, content and settings in the expected
    order; Site under Settings with order 10)
  - `test_nav_false_type_hidden`
  - `test_custom_section_label`
  - `test_invalid_section_type_throws`
  - `test_without_nav_keys_unchanged`: the existing `test_no_extensions_keeps_todays_sidebar`
    must stay green unchanged.
  - `test_reserved_type_key_throws`
  - `test_non_transferred_type_with_asset_field_throws`
  - `test_action_button_hidden_without_type_edit`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'AdminNav|TypeCatalog|Customization'`.
  Expected: the new tests FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Document.** In the README, add a "Sidebar" subsection with the keys and
  the section list.
- [ ] **Step 5b: Add icons.** `ui-users` and `ui-key` must exist in
  `packages/folio/assets/icons.svg`. If they are missing, add them as `<symbol>` entries
  in the same style.
- [ ] **Step 6: Commit.** `feat(folio): model JSON places a type in the sidebar (section, order, icon, nav); reserved type keys and file fields on non-transferred types are errors`

### Task 3: `preflow/rbac`: package, resolver, provider

**Files:**
- Create:
  - `packages/rbac/composer.json` (with `extra.folio.extension: "Preflow\\Rbac\\Extension"`)
  - `packages/rbac/README.md` (stub, completed in Task 4)
  - `packages/rbac/src/RoleList.php`
  - `packages/rbac/src/RoleStore.php`
  - `packages/rbac/src/RbacResolver.php`
  - `packages/rbac/src/RbacServiceProvider.php`
  - `packages/rbac/src/Extension.php` (minimal; Task 4 fills it)
  - `packages/rbac/src/models/rbac_role.json`
- Modify (monorepo registration, see Global Constraints): `.github/workflows/split.yml`,
  root `composer.json`, `phpunit.xml`
- Test: `packages/rbac/tests/RbacResolverTest.php`, `packages/rbac/tests/RoleListTest.php`

**Interfaces:**
- **The extension:** `#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.0.0')]`.
- **`src/models/rbac_role.json`:**
  - key `rbac_role`, table `rbac_role`, storage `default`, label "Roles";
  - `"section": "settings"`, `"order": 20`, `"icon": "ui-users"`;
  - no `frontend`, `publishing: false`.
  - Fields:
    - `name`: string, required, validated `/^[a-z][a-z0-9_-]*$/`, label "Name";
    - `label`: string, required;
    - `population`: select, options `staff` / `members`, required;
    - `permissions`: type `rbac_permissions`, the field type from Task 4.
- **`RoleList::normalize(mixed $roles): list<string>`** accepts:
  - an array;
  - a JSON array string (as SQL returns it);
  - a comma-separated string;
  - null or `''`, which give `[]`.
  It returns trimmed, unique, non-empty strings in the given order.
- **`RoleStore`:**
  - `__construct(DataManager $dm)`
  - `rolesFor(string $population): array<string, list<string>>`, mapping role name to
    permission patterns, loaded once per instance through `queryType('rbac_role')`.
  - `permissions` values are normalised with `RoleList`.
- **`RbacResolver implements PermissionResolver`:**
  - `__construct(RoleStore $store)`
  - `allows(...)`: allowed when any of `RoleList::normalize($user->getRoles())` that
    exists in `rolesFor($population)` has a pattern matching via `Pattern::matches`.
  - **Bootstrap rule:** when `rolesFor('staff') === []`, a staff user is allowed
    everything, and `error_log` says once per request that no staff roles exist yet (run
    `folio:extensions:apply`).
  - Members with no roles defined get nothing.
- **`RbacServiceProvider`:** in `boot()`, when `ExtensionRegistry::isEnabled('rbac')`, it
  calls `Access::setResolver(new RbacResolver(...))`. It runs in `boot()`, so it wins over
  `preflow/roles`, which sets its resolver in `register()`.

- [ ] **Step 1: Write the failing tests:**
  - `test_normalize_accepts_array_json_and_csv`
  - `test_staff_role_pattern_grants`
  - `test_member_role_pattern_grants_only_members_population`
  - `test_unknown_role_grants_nothing`
  - `test_roles_as_json_string_resolve`
  - `test_no_staff_roles_yet_staff_may_do_everything`
  - `test_resolver_only_when_extension_enabled`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Rbac`. Expected: FAIL.
- [ ] **Step 3: Implement,** including the monorepo registration and
  `composer update preflow/rbac`.
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(rbac): preflow/rbac package — role store, resolver from Folio-managed roles`

### Task 4: RBAC in Folio: field types, install, lock-out protection

**Files:**
- Create:
  - `packages/rbac/src/Fields/PermissionsFieldType.php`
  - `packages/rbac/src/Fields/RolesFieldType.php`
  - `packages/rbac/src/Types/RoleRules.php`
- Modify: `packages/rbac/src/Extension.php`, `RbacServiceProvider.php`, `README.md`
- Test:
  - `packages/rbac/tests/RolesInFolioTest.php` (a full app with auth, as in Folio's
    integration tests)
  - `packages/rbac/tests/InstallTest.php`

**Interfaces:**
- **Field type `rbac_permissions`:**
  - The editor shows checkboxes grouped by `PermissionRegistry::all()` group. Each group
    has a "whole group" checkbox for every `group.*` pattern from `patterns()` that
    belongs to it, and the editor has a top "Everything (`*`)" checkbox.
  - The stored value is a list of strings.
  - Undeclared names in the stored value are shown as "unknown" and removed on the next
    save.
- **Field type `rbac_roles`:**
  - The editor shows checkboxes for the roles of the record type's population, from the
    TypeListing `users`; if the field's config `population` is set, that wins.
  - `fromStorage` is `RoleList::normalize`, and the stored value is a list.
- **Registration:** both field types are registered by `RbacServiceProvider` in Folio's
  field type registry, only when the extension is enabled.
- **`Extension::install(Container $c)`:**
  - It creates the staff role `admin` (label "Administrator", permissions `['*']`) when
    no staff role exists.
  - If `config/roles.php` exists, it imports every role it defines (population, name,
    patterns) that doesn't exist yet.
  - It is idempotent.
- **`RoleRules` (`#[FolioType('rbac_role')]`):**
  - `#[BeforeSave]`:
    - It throws `Preflow\Validation\ValidationException(['name' => […]])` when another
      `rbac_role` has the same name and population.
    - It throws `ValidationException(['permissions' => ['You would lose the right to edit roles.']])`
      when, after the save, the signed-in user's own roles no longer grant
      `rbac_role.edit`.
    - It throws when no staff role at all would grant `rbac_role.edit` any more.
  - `#[BeforeDelete]` throws `Refused` in the same two lock-out cases.
  - The signed-in user comes from the constructor-injected `Preflow\Auth\AuthManager`
    (`user()`). Their roles come from `RoleList::normalize($user->getRoles())`, and the
    grant is evaluated against the roles as they would be after the save or delete.
- **Public helper for sites:**
  `RoleStore::ensure(string $population, string $name, string $label, list<string> $permissions): void`
  creates the role if it is missing and never overwrites. Task 7 uses it.

- [ ] **Step 1: Write the failing tests:**
  - `test_roles_listed_under_settings`
  - `test_permissions_field_groups_and_patterns`
  - `test_user_roles_field_lists_population_roles`
  - `test_install_creates_admin_and_imports_config_roles`
  - `test_install_is_idempotent`
  - `test_duplicate_role_name_rejected`
  - `test_save_removing_own_role_edit_refused`
  - `test_delete_own_granting_role_refused`
  - `test_editor_role_limits_folio_access` (an editor role with `page.*` sees Pages and
    not Roles; the page list is 200 and the roles list is 403)
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Rbac`. Expected: FAIL.
- [ ] **Step 3: Implement.** Check that `ValidationException` exists under that name in
  `packages/validation`; if it lives elsewhere, use the class `AdminController::store`
  catches.
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Document.** Complete `packages/rbac/README.md`: install, enabling,
  `apply`, roles, user types, lock-out, the bootstrap rule, switching from
  `preflow/roles`.
- [ ] **Step 6: Commit.** `feat(rbac): roles edited in Folio (permission and role field types), install seeds admin, lock-out protection`

### Task 5: `preflow/codes`: package and service

**Files:**
- Create:
  - `packages/codes/composer.json` (`extra.folio.extension: "Preflow\\Codes\\Extension"`)
  - `packages/codes/README.md`
  - `packages/codes/src/Extension.php`
  - `packages/codes/src/Codes.php`
  - `packages/codes/src/Purpose.php`
  - `packages/codes/src/Result.php`
  - `packages/codes/src/CodesServiceProvider.php`
  - `packages/codes/src/models/code.json`
  - `packages/codes/migrations/2026_10_22_000001_code_redemptions.php`
- Modify: monorepo registration (see Global Constraints)
- Test: `packages/codes/tests/CodesTest.php`

**Interfaces:**
- **The extension:** `#[FolioExtension(name: 'codes', label: 'Codes', icon: 'ui-key', version: '1.0.0')]`.
- **`src/models/code.json`:**
  - key `code`, table `code`, storage `codes` (the alias), `"transfer": false`;
  - `"section": "library"`, `"order": 20`, `"icon": "ui-key"`, label "Codes";
  - `publishing: false`.
  - Fields:
    - `purpose`: select, options filled from the configured purposes;
    - `code`: string;
    - `label`: string;
    - `expires_at`: date;
    - `max_uses`: number;
    - `uses`: number, read-only in practice (see Task 6);
    - `revoked_at`: string;
    - `payload`: string (JSON text);
    - `form`: select, `readable` or `random`.
- **Redemptions table:** `code_redemption(id TEXT PK, code_id TEXT, purpose TEXT, redeemer TEXT NULL, redeemed_at INTEGER)`.
  - Created by the package migration, which is registered in the `MigrationRegistry`.
  - Read and written through an internal `TypeDefinition` on storage `codes`.
  - Not in the TypeCatalog, so never exported.
- **`config/codes.php`:**
  - It returns `['purposes' => ['members.book' => ['label' => 'Book code', 'readable' => true], …], 'throttle' => ['max' => 10, 'window' => 900]]`.
  - A missing file means no purposes.
- **`Purpose`:** readonly `name`, `label`, `readable`.
- **`Result`:** readonly `ok`, `reason` (`'unknown'|'expired'|'used_up'|'revoked'|'throttled'|null`),
  `payload` (array) and `codeId` (?string).
- **`Codes`:**
  - `issue(string $purpose, array $payload = [], ?string $code = null, string $label = '', ?\DateTimeImmutable $expiresAt = null, ?int $maxUses = null): string`
    - Given a `$code`, it makes a readable code. The purpose must allow readable codes,
      and the normalised code must be 4–64 characters `[A-Z0-9]`.
    - Without one, it makes a random code: base64url of 16 random bytes, no padding.
    - It returns the code as stored, which is normalised for readable codes.
    - The code must be unique within the purpose. An unknown purpose throws.
  - `check(string $code, string $purpose): Result` does not count a use.
  - `redeem(string $code, string $purpose, ?string $redeemer = null, ?string $client = null): Result`
    - With a `$client`, `Throttle::tooMany("codes.{$purpose}", $client, max, window)` is
      checked first and gives reason `throttled`.
    - A failure calls `Throttle::hit`.
    - Success increments `uses` and records a redemption.
  - `revoke(string $codeId): void`
  - `redemptions(string $codeId): list<array{id, redeemer, redeemed_at}>`
- **Normalising:**
  - Readable codes: `strtoupper`, with all whitespace and `-` removed.
  - Random codes: `trim`.
  - The stored code is compared with `hash_equals` after a lookup by purpose.
- **`CodesServiceProvider`:**
  - It binds `Codes`.
  - It registers the migration path.
  - At boot, when the extension is enabled, it throws a clear `\RuntimeException` when
    the storage alias `codes` is not configured. The message tells the site to add
    `'aliases' => ['codes' => '<driver>']`.

- [ ] **Step 1: Write the failing tests** (SQLite alias, injected clock where needed):
  - `test_issue_readable_and_redeem`
  - `test_readable_code_normalised` (`" smart-stack 2026 "` matches `SMARTSTACK2026`)
  - `test_random_code_is_url_safe_and_128_bits`
  - `test_wrong_purpose_is_unknown` (a `forcemap.round` code is unknown for
    `members.book`)
  - `test_expired_used_up_revoked_reasons`
  - `test_check_does_not_count_use`
  - `test_redemption_recorded_without_ip`
  - `test_throttled_client_refused_even_with_valid_code`
  - `test_no_client_not_throttled`
  - `test_unknown_purpose_throws`
  - `test_readable_not_allowed_for_purpose_throws`
  - `test_missing_codes_alias_throws_at_boot`
  - `test_codes_and_redemptions_not_exported`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Codes`. Expected: FAIL.
- [ ] **Step 3: Implement,** including the monorepo registration and
  `composer update preflow/codes`.
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(codes): preflow/codes — purposes, readable and random codes, expiry, limits, revocation, redemptions, throttled redeem`

### Task 6: Codes in Folio

**Files:**
- Create:
  - `packages/codes/src/Types/CodeAdmin.php`
  - `packages/codes/src/templates/redemptions.twig`
- Modify: `packages/codes/src/Extension.php`, `README.md`
- Test: `packages/codes/tests/CodesInFolioTest.php` (a full app with auth)

**Interfaces:**
- **Permissions:**
  - The `code` type's standard permissions (`code.view|create|edit|delete`).
  - Plus `#[DeclaresPermission('codes.revoke', 'Revoke codes')]` on the Extension.
- **`CodeAdmin` (`#[FolioType('code')]`):**
  - **`#[BeforeSave]`:**
    - On create: requires a purpose with `readable: true` and normalises the code. It
      throws a `ValidationException` for an empty code, a code in a bad format, or a
      duplicate.
    - Sets `form = 'readable'`, `uses = 0`, `revoked_at = ''`.
    - On update: keeps the stored `code`, `form`, `uses`, `revoked_at`, `purpose` and
      `payload`. Only `label`, `expires_at` and `max_uses` are editable.
  - **`#[RecordAction(name: 'revoke', label: 'Revoke', permission: 'codes.revoke', confirm: 'Revoke this code? It stops working at once.')]`**
    calls `Codes::revoke`.
  - **`#[ListColumn('Redemptions')]`** shows the redemption count, or `revoked` / `expired`
    when the code has that state.
  - **`#[FormPanel('Redemptions')]`** renders `@codes/redemptions.twig` with the list. It
    shows the redeemer reference and the time, nothing else.
- **The purpose select** offers the configured purposes.

- [ ] **Step 1: Write the failing tests:**
  - `test_codes_listed_under_library`
  - `test_create_readable_code_in_admin`
  - `test_create_for_non_readable_purpose_rejected`
  - `test_duplicate_code_rejected`
  - `test_update_cannot_change_code_or_uses`
  - `test_revoke_action`
  - `test_redemptions_panel_and_column`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Codes`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Document.** Complete `packages/codes/README.md`: config, aliases,
  purposes, API, throttling, admin, privacy.
- [ ] **Step 6: Commit.** `feat(codes): codes managed in Folio — create readable codes, revoke, redemptions`

### Task 7: smart-stack.works adopts the groundwork

**Files** (all in `/Users/smyr/Sites/gbits/smart-stack.works`, branch `feat/members-groundwork`):
- Modify:
  - `composer.json`: every `preflow/*` becomes `"^0.20 || @dev"`; add `preflow/rbac` and
    `preflow/codes`. Then `composer update "preflow/*"` against the path repo.
  - `config/data.php`: add the `sqlite` driver (`path` = `storage/data/site.sqlite`) and
    `'aliases' => ['codes' => 'sqlite', 'throttle' => 'sqlite']`. The default stays
    `json`.
  - `config/providers.php`: add `RbacServiceProvider` and `CodesServiceProvider` after
    Folio.
  - `config/commands.php`: keep the Folio commands; migrate comes from devtools.
  - `config/models/page.json`: `"section": "pages"`.
  - `config/models/site.json`: `"section": "settings"`, `"order": 10`.
  - `deploy.php`: a task `deploy:migrate` runs `preflow migrate`,
    `folio:extensions:apply` and `folio:extensions:check` in `{{release_path}}` after
    `deploy:vendors` and before `deploy:publish`.
- Create:
  - `config/codes.php`: purpose `members.book` (label "Book code", readable).
  - `config/folio/extensions.json`: enables `members`, `rbac`, `codes`.
  - `app/Folio/Members/Extension.php`
  - `app/Folio/Members/models/member.json`
- Test:
  - `tests/MembersGroundworkTest.php`
  - the existing `tests/SiteAuditTest.php` must stay green

**Interfaces:**
- **`app/Folio/Members/Extension.php`:**
  - `#[FolioExtension(name: 'members', label: 'Members', icon: 'ui-users', version: '1.0.0')]`
  - `#[DeclaresPermission('members.access', 'Use the members area')]`
  - **`settings()`:**
    - `registration_open`: boolean, default true, label "Registration open".
    - `notice_email`: string, label "Notify about waiting members", help "Address that
      gets a mail when someone waits for approval".
    - `book_code_skips_screening`: boolean, default true.
    - `screening_label`: string, translatable, default EN "What are you looking for?",
      label "Screening question".
    - `screening_help`: text, translatable, default EN "A sentence or two is enough.".
    - German values are filled in by the owner in Folio.
  - **`install()`:** uses `RoleStore::ensure`:
    - `members` / `pending` (label "Waiting for approval", `[]`)
    - `members` / `member` (label "Member", `['members.access']`)
    - `members` / `rejected` (label "Rejected", `[]`)
- **`models/member.json`:**
  - key `member`, table `member`, `"storage": "sqlite"`, `"users": "members"`;
  - `"section": "members"`, `"order": 10`, `"icon": "ui-users"`, label "Members";
  - `publishing: false`.
  - Fields:
    - `email`: string, required;
    - `password_hash`: string, help "Set by the site; never edit";
    - `name`: string;
    - `screening_answer`: text;
    - `registered_at`: string;
    - `decided_at`: string;
    - `roles`: type `rbac_roles`.

- [ ] **Step 1: Write the failing tests:**
  - `test_extensions_check_ok`
  - `test_sidebar_structure`: the sections in order are Dashboard, Pages, Content,
    Members, Library, Settings.
    - Pages holds Pages.
    - Content holds Audience roles, Career levels, Forces, Practices, Why now.
    - Members holds Members.
    - Library holds Assets and Codes.
    - Settings starts with Site, then Roles, and ends with Extensions. The Members
      settings item sits between them. Assert membership for the sections with several
      items, and order only where `order` values fix it.
  - `test_member_roles_roundtrip_on_sqlite` (save a member with roles `['member']` on
    SQLite; `can('members.access')` for that user is true)
  - `test_member_not_exported` (the `folio:export` manifest has no `member` table)
  - `test_migrate_on_empty_database` (a fresh temp copy with no `site.sqlite`: migrate,
    then apply, then check all exit 0)
  - `test_public_pages_set_no_cookie` (`/`, `/de`, `/tools`)
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit` in the site. Expected: the new
  tests FAIL.
- [ ] **Step 3: Implement** the files above.
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit` in the site, plus
  `php vendor/bin/preflow migrate && php vendor/bin/preflow folio:extensions:apply && php vendor/bin/preflow folio:extensions:check`
  locally. Expected: all PASS and `ok`.
- [ ] **Step 5: Check by hand.** Open `https://smart-stack.dev.smyr/folio` (the
  controller does this logged-in check): the sidebar matches `test_sidebar_structure`,
  and a book code can be created under Library → Codes. Delete that test code afterwards.
- [ ] **Step 6: Commit** in the site repo: `feat: members groundwork — SQLite, roles, codes, Members extension and settings, sidebar structure`

### Task 8: Release preparation for 0.20

**Files:**
- Modify:
  - each `packages/*/composer.json` whose `preflow/*` constraints read `^0.19 || @dev` or
    `^0.18 || @dev`: change them to `^0.20 || @dev`
  - `packages/folio/README.md` and `packages/auth/README.md`, where they mention roles
    packages: link `preflow/rbac`
  - `ROADMAP.md`: add a 0.20 entry under Completed
- Create: `docs/superpowers/plans/2026-10-22-release-0.20-checklist.md`
- Test: full suite

**Release checklist contents** (each step user-gated, nothing executed here):
1. **The user creates the empty GitHub repos** `getpreflow/rbac` and `getpreflow/codes`,
   and makes sure the `SPLIT_TOKEN` can push to them.
2. **Merge `feat/members-groundwork-b` into `main`** after the final review.
3. **Push `main`, then tag** `v0.20.0` with the message `v0.20.0: Folio extensions, live settings, SQL storage, user types, throttle; preflow/rbac; preflow/codes`,
   and push the tag. CI splits the packages.
4. **The user submits** `getpreflow/rbac` and `getpreflow/codes` on Packagist (account
   smyr). After that the CI ping keeps them updated.
5. **smart-stack.works:** merge `feat/members-groundwork`, push, then `dep deploy`. The
   new `deploy:migrate` step runs migrate, apply and check before the release switch.
6. **After deploying,** the owner:
   - checks `https://smart-stack.works/folio` (sidebar, Roles, Codes, Members settings);
   - creates the book code;
   - fills in the German settings texts.
7. **smeyer.me and preflow.dev** stay on 0.19 until they are bumped deliberately.

- [ ] **Step 1: Write the checklist** and apply the constraint bumps.
- [ ] **Step 2: Run all suites.** Run `vendor/bin/phpunit`, plus `composer validate` in
  `packages/rbac` and `packages/codes`. Expected: PASS and valid.
- [ ] **Step 3: Commit.** `chore: prepare 0.20 (constraints, roadmap, release checklist)`
