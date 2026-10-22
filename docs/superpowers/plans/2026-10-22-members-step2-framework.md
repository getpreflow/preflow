# Members Step 2 — Framework (0.21) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The framework pieces step 2 of the smart-stack.works members area needs, released
as Preflow 0.21:
- atomic throttle and code counting;
- token and remember-me support;
- a user provider for Folio user types;
- a login gate for Folio frontend types;
- private assets;
- nav badges.

**Architecture:**
- **Sessions:** staff, members and CSRF share the one PHP session. The site widens the
  cookie path and the session paths in plan D.
- **Members stay logged in** through a separate remember-me cookie: selector plus hashed
  validator, rotated on use. It re-establishes the member's session.
- **Folio** gains:
  - a user provider over its user types;
  - a `frontend.permission` gate;
  - private assets served by a gated controller;
  - a `#[NavBadge]` customization attribute.
- **Counters** (throttle, code uses) become single SQL statements on PDO drivers.

**Tech Stack:** PHP 8.4, PHPUnit 11, SQLite, PSR-7.

**Spec:** `docs/superpowers/specs/2026-10-22-members-area-step2-design.md`. Binding sections:
- "Framework work";
- "Login, session, cookies";
- "Decided after the builder check";
- the anti-goals.

The site plan (plan D) comes after this one is released and consumes its interfaces.

## Global Constraints

- **Code and tooling:** PHP 8.4, `declare(strict_types=1)`, existing patterns and comment
  density. Strict nonce CSP: no inline handlers or styles. No build step.
- **Tests:** TDD per task.
  - Per package: `vendor/bin/phpunit --testsuite <Data|Core|Auth|Codes|Folio>`.
  - Everything: `vendor/bin/phpunit`.
  - Green at the end of every task. The 129 known PHPUnit doc-comment deprecations are
    the baseline; no new ones.
- **No regressions:**
  - Sites without the new config keys (smeyer.me, smart-stack.works today) behave
    exactly as before:
    - no remember cookie;
    - no gate;
    - public assets unchanged;
    - no badge.
  - Public pages never send `Set-Cookie`.
- **Security:**
  - Tokens (confirmation, reset, remember) are random, at least 128 bits, and stored only
    as SHA-256 hashes. They are compared with `hash_equals`, expire, and are single use;
    a remember token rotates on each use.
  - Remember cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when `APP_SECURE` is
    set.
  - Gated responses and private files send `Cache-Control: private, no-store`.
  - Private files are never under `public/`.
- **Storage:** new internal tables use a storage alias with fallback to `default`
  (`auth` for tokens and remember tokens), like `throttle`. They are never in the
  TypeCatalog and never exported.
- **Releasing:** keep `^0.20 || @dev` style constraints, raised to `^0.21 || @dev` where
  a consumer uses a 0.21 feature (Task 9). Push, tag and deploy are user-gated and not
  executed here.
- **Branch and commits:** branch `feat/members-step2-framework` off `main`. Conventional
  commits with the package scope.

## Review Focus

1. **Two requests hit the throttle at the same moment.** Both increments count: the
   counter never loses a hit. The test is in Task 1
   (`test_hit_is_atomic_under_interleaving`): it runs two DataManagers on one SQLite file,
   interleaves the read and the write, and asserts hits == 2.
2. **A stolen remember token is replayed after the owner used it.** The rotated selector
   no longer matches, and all of that user's remember tokens are revoked (theft
   detection). The test is in Task 4 (`test_replayed_old_validator_revokes_all`).
3. **A gated Folio page is requested by a logged-out visitor.** They get a redirect to
   the configured login with `next`. The page never renders, it isn't cached, and there
   is no 200. A share-preview token doesn't bypass the gate for unpublished records
   unless it is valid. The tests are in Task 6:
   - `test_gated_type_redirects_to_login_with_next`
   - `test_share_preview_still_needs_valid_token`
4. **A private asset's file path is guessed, or a public URL is built for it.** Nothing
   under `/media` serves it, and the only URL is the gated route. A staff user without
   the permission gets 403. The tests are in Task 7:
   - `test_private_asset_not_under_public_media`
   - `test_private_url_requires_permission`
5. **The member email is typed in a different case at login** (`Ada@Example.com`). It
   finds the record stored as `ada@example.com`. The test is in Task 5
   (`test_find_by_credentials_is_case_insensitive`).

---

### Task 1: Atomic throttle (and a write-execute path on PDO)

**Files:**
- Modify:
  - `packages/data/src/Driver/PdoDriver.php`
  - `packages/data/src/DataManager.php`
  - `packages/core/src/Throttle/Throttle.php`
- Test:
  - `packages/data/tests/Driver/SqliteDriverTest.php`
  - `packages/core/tests/Throttle/ThrottleTest.php`

**Interfaces:**
- **Produces:**
  - `PdoDriver::execute(string $sql, array $bindings = []): int` returns the affected
    rows.
  - `DataManager::execute(string $sql, array $bindings = [], string $storage = 'default'): int`
    throws `\LogicException` for a non-PDO driver.
  - `Throttle::hit()` is atomic on PDO drivers: one upsert statement that resets the row
    when its window has expired and otherwise does `hits = hits + 1`.
    - SQLite: `INSERT … ON CONFLICT(id) DO UPDATE …`.
    - MySQL: `… ON DUPLICATE KEY UPDATE …`. Choose by the driver's dialect.
  - On the JSON driver, `hit()` takes an exclusive `flock` on
    `storage/locks/throttle-{sha256}.lock` around the read-modify-write.
  - `tooMany()` is unchanged. The class docblock no longer calls the limit soft on SQL.

- [ ] **Step 1: Write the failing tests:**
  - `test_execute_returns_affected_rows`
  - `test_hit_is_atomic_under_interleaving`: two `Throttle` instances on two
    DataManagers over the same SQLite file. Simulate the race by calling `hit()` on both
    after both have checked `tooMany()`; assert the stored hits equal the number of calls.
  - `test_window_reset_in_upsert`
  - `test_json_hit_locks`: a second process isn't needed; assert the lock file is created
    and released and the counts are correct.
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Core --filter Throttle`
  and `--testsuite Data --filter execute`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(data,core): DataManager::execute; atomic throttle hits (SQL upsert, file lock on JSON)`

### Task 2: Codes — atomic use counting, throttled `check()`

**Files:**
- Modify: `packages/codes/src/Codes.php`
- Test: `packages/codes/tests/CodesTest.php`

**Interfaces:**
- **Consumes:** `DataManager::execute` (Task 1).
- **Produces:**
  - `check(string $code, string $purpose, ?string $client = null): Result`. With a
    client it applies the same throttle as `redeem()`: purpose key `codes.{purpose}`,
    reason `throttled`, and `hit` on failure.
  - `redeem()` counts the use with one conditional
    `UPDATE … SET uses = uses + 1 WHERE {id} = ? AND (max_uses IS NULL OR max_uses = '' OR uses < max_uses)`
    on PDO drivers.
    - Zero affected rows mean `used_up`, and no redemption is recorded.
    - JSON keeps the current behaviour under a file lock, as in Task 1.

- [ ] **Step 1: Write the failing tests:**
  - `test_check_with_client_is_throttled`
  - `test_concurrent_redeem_cannot_exceed_max_uses`: `max_uses` 1, two Codes instances on
    one SQLite file, both pass `check`, both redeem; exactly one ok and one `used_up`.
  - `test_check_without_client_not_throttled`: unchanged behaviour.
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Codes`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `fix(codes): code uses counted atomically; check() takes a client and is throttled`

### Task 3: Auth — any provider class from config, single-use tokens

**Files:**
- Modify: `packages/core/src/Application.php` (bootAuth providers, ~863-878)
- Create:
  - `packages/auth/src/Tokens/Tokens.php`
  - `packages/auth/migrations/2026_10_23_000000_auth_tokens.php`
- Test:
  - `packages/auth/tests/Tokens/TokensTest.php`
  - `packages/core/tests/BootAuthProvidersTest.php`

**Interfaces:**
- **Provider classes from config:** a provider entry with a `class` other than the two
  built-ins is built with `$container->make($class, ['config' => $entry])`. It must
  implement `UserProviderInterface`, otherwise a `\RuntimeException` names the provider.
- **`Preflow\Auth\Tokens\Tokens`:**
  - Constructor: `__construct(DataManager $dm, string $storage = 'default', ?\Closure $clock = null)`.
    It is bound in core with storage `auth` when that alias or driver exists, otherwise
    `default`.
  - `issue(string $purpose, string $subject, int $ttlSeconds): string` returns a
    base64url token of 32 random bytes and stores `sha256(token)`, purpose, subject and
    expires_at.
  - `consume(string $token, string $purpose): ?string` returns the subject and deletes
    the row. It returns null when the token is unknown, expired or for another purpose.
    It is atomic on PDO: `DELETE … WHERE hash = ? AND purpose = ? AND expires_at > ?`,
    and the affected rows decide.
  - `revokeAll(string $purpose, string $subject): int`
  - `prune(): int` deletes expired rows.
- **Table:** `auth_tokens(id TEXT PK = hash, purpose TEXT, subject TEXT, expires_at INTEGER)`,
  through the auth package's migrations registered in the MigrationRegistry.

- [ ] **Step 1: Write the failing tests:**
  - `test_issue_and_consume_once`
  - `test_expired_token_rejected`
  - `test_wrong_purpose_rejected`
  - `test_token_stored_only_as_hash` (the raw token is not in the table)
  - `test_revoke_all`
  - `test_custom_provider_class_built_from_config`
  - `test_provider_class_not_a_provider_throws`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Auth --filter Tokens`
  and `--testsuite Core --filter BootAuthProviders`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(auth): single-use hashed tokens; any user provider class from config/auth.php`

### Task 4: Auth — remember me for a session guard

**Files:**
- Create:
  - `packages/auth/src/Remember/RememberMe.php`
  - `packages/auth/migrations/2026_10_23_000001_auth_remember.php`
- Modify:
  - `packages/auth/src/SessionGuard.php` (an optional `RememberMe` and
    `login(…, bool $remember = false)`)
  - `packages/auth/src/Http/ResolveUserMiddleware.php`
  - `packages/core/src/Application.php` (per-guard config `remember`)
- Test: `packages/auth/tests/Remember/RememberMeTest.php`

**Interfaces:**
- **Guard config:** `'remember' => ['cookie' => 'ss_member', 'days' => 30, 'path' => '/']`.
  Absent means no remember support, as today.
- **Cookie:** `{selector}:{validator}`. Both are random, 16 and 32 bytes, base64url.
- **Table:** `auth_remember(id TEXT PK = selector, guard TEXT, user_id TEXT, validator_hash TEXT, expires_at INTEGER)`
  on storage alias `auth`, falling back to `default`.
- **`RememberMe`:**
  - `issue(string $guard, string $userId): string`, the cookie value.
  - `resolve(string $guard, string $cookie): ?array{userId: string, cookie: string}`
    validates and rotates: a new validator under the same selector, returned as the new
    cookie value.
    - A selector match with a wrong validator deletes all of that user's rows for the
      guard (theft) and returns null.
  - `forget(string $guard, string $cookie): void`
- **`SessionGuard::login(Authenticatable $user, ServerRequestInterface $request, bool $remember = false)`.**
  When remembering, it queues the `Set-Cookie`. `logout()` queues a clearing cookie and
  forgets the row.
- **`ResolveUserMiddleware`:**
  - When a guard has remember config, its session has no user, and the request carries
    the cookie: resolve it, log in without remember, and set the rotated cookie on the
    response.
  - It also appends any queued cookies (login, logout) to the response.
  - Cookie attributes: `HttpOnly`, `SameSite=Lax`, `Secure` when `APP_SECURE`,
    `Max-Age = days * 86400`, and the configured path.

- [ ] **Step 1: Write the failing tests:**
  - `test_login_with_remember_sets_cookie_with_attributes`
  - `test_cookie_restores_user_and_rotates`
  - `test_replayed_old_validator_revokes_all`
  - `test_expired_remember_ignored`
  - `test_logout_clears_cookie_and_row`
  - `test_guard_without_remember_config_unchanged`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Auth --filter Remember`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(auth): remember-me cookies for session guards (rotating selector/validator, theft detection)`

### Task 5: Folio — user provider over a user type

**Files:**
- Create:
  - `packages/folio/src/Users/RecordUserProvider.php`
  - `packages/folio/src/Users/RecordUser.php`
- Test: `packages/folio/tests/Users/RecordUserProviderTest.php`

**Interfaces:**
- **Consumes:** provider classes from config (Task 3).
- **Config:** `['class' => RecordUserProvider::class, 'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles']`.
  The field names shown are the defaults.
- **`RecordUserProvider implements UserProviderInterface`:**
  - `__construct(DataManager $dm, TypeCatalog $catalog, array $config)`
  - It requires that the type exists and is a user type (`users` set), otherwise
    `\RuntimeException`.
  - `findById` uses the record id.
  - `findByCredentials(['email' => …])` normalises with `mb_strtolower(trim())` and
    queries the email field for the normalised value. Emails are stored normalised; that
    is the site's job, but the provider normalises the query.
- **`RecordUser implements Authenticatable`:**
  - It wraps the DynamicRecord; `getAuthId()` is the record id.
  - Roles are normalised: an array, a JSON string or CSV become `list<string>`.
  - `record(): DynamicRecord`.

- [ ] **Step 1: Write the failing tests:**
  - `test_find_by_id`
  - `test_find_by_credentials_is_case_insensitive`
  - `test_roles_from_json_string_on_sqlite`
  - `test_non_user_type_throws`
  - `test_login_through_session_guard` (a full booted app with a members guard using this
    provider: validate + login + `user()`)
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter RecordUserProvider`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): user provider over Folio user types`

### Task 6: Folio — login gate for a type's frontend

**Files:**
- Modify:
  - `packages/folio/src/Content/FrontendRoute.php` (reads `frontend.permission` and
    `frontend.login`)
  - `packages/folio/src/Http/FrontendController.php`
  - `packages/folio/README.md`
- Test: `packages/folio/tests/Integration/FrontendGateTest.php`

**Interfaces:**
- **Model JSON:** `"frontend": {"route": "/tools/r/{slug}", "permission": "members.access", "login": "/tools/login"}`.
  - `login` may be locale-mapped: `{"en": "/tools/login", "de": "/de/tools/login"}`.
  - A `permission` must be declared, as `Access::can` requires.
- **Gate:** after `match()` and before `render()`.
  - If `permission` is set and `Access::can($permission)` is false:
    - with `login` set, a 302 to `login?next={url-encoded path}`;
    - otherwise a 404.
  - Allowed responses send `Cache-Control: private, no-store`.
  - A share-preview token still works for staff review but does not skip the permission:
    the reviewer must pass the gate. *Plan call:* preview links stay a staff tool.
- **Session:** the gate needs the session on these paths. Sites must include the route
  prefix in `session.paths`; document it.

- [ ] **Step 1: Write the failing tests:**
  - `test_gated_type_redirects_to_login_with_next`
  - `test_gated_type_404_without_login_config`
  - `test_member_with_permission_sees_page_no_store`
  - `test_staff_with_star_sees_page`
  - `test_share_preview_still_needs_valid_token`
  - `test_ungated_types_unchanged`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter FrontendGate`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): frontend.permission gates a type's pages (login redirect with next, no-store)`

### Task 7: Folio — private assets

**Files:**
- Modify:
  - `packages/folio/src/Assets/AssetLibrary.php` (a `private` flag in `definition()`,
    the private dir, `url()`)
  - `packages/folio/src/Field/Types/AssetFieldType.php` (field config `"private": true`)
  - `packages/folio/src/Assets/ImageTag.php`
  - `packages/folio/src/Routing/FolioRoutes.php`
  - `packages/folio/src/FolioServiceProvider.php`
- Create: `packages/folio/src/Http/PrivateAssetController.php`
- Test: `packages/folio/tests/Assets/PrivateAssetsTest.php`

**Interfaces:**
- **Config** (`config/folio.php`):
  `'assets' => ['private_dir' => basePath('storage/private-media'), 'private_url' => '/files', 'private_permission' => 'folio.dashboard']`.
  These are the defaults. smart-stack sets `private_url` `/tools/files` and permission
  `members.access` in plan D.
- **Storage:** an asset field with `"private": true` stores its uploads with
  `private: true` under `private_dir/Y/m/{id}.{ext}`, never under `public/`. No variants
  are generated for private assets.
- **URLs:** `AssetLibrary::url()` for a private asset returns `{private_url}/{uuid}`, and
  `ImageTag` uses that URL without a srcset. Admin thumbnails for private images use the
  same gated URL; staff pass the permission check or have `*`.
- **Route:** `GET {private_url}/{uuid}` goes to `PrivateAssetController@serve`.
  - It needs the session on that path, so it is documented for `session.paths`.
  - Without `Access::can(private_permission)` it answers 403, or a 302 to the configured
    login when `assets.private_login` is set.
  - Otherwise it streams with realpath confinement to `private_dir`,
    `Cache-Control: private, no-store`, and the stored MIME type. Images are `inline`;
    everything else is `attachment` with the original name.
- **Nothing changes** for public assets.

- [ ] **Step 1: Write the failing tests:**
  - `test_private_upload_stored_outside_public`
  - `test_private_asset_not_under_public_media`
  - `test_private_url_requires_permission`
  - `test_member_downloads_pdf_as_attachment_no_store`
  - `test_traversal_rejected`
  - `test_public_assets_unchanged`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter PrivateAssets`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): private assets stored outside public and served through a permission-gated route`

### Task 8: Folio — nav badges from type customizations

**Files:**
- Create: `packages/folio/src/Extension/NavBadge.php` (attribute)
- Modify:
  - `packages/folio/src/Extension/Customization/Customizations.php`
  - `packages/folio/src/Http/AdminNav.php`
  - `packages/folio/templates/admin/_layout.twig` (badge after the label `<span>`)
  - `packages/folio/assets/admin.css`
  - `packages/folio/README.md`
- Test: `packages/folio/tests/Extension/NavBadgeTest.php`

**Interfaces:**
- **`#[NavBadge]`:** a method on a `#[FolioType('key')]` class with the signature
  `fn(): ?int`.
- **`AdminNav` items** gain `badge: ?int`.
  - For a catalog type with a NavBadge method, the method is called lazily when the nav
    renders, and only if the item is visible (permission passed).
  - `null` or `0` shows no badge.
  - Several extensions' badges for one type add up.
- **Markup:** `<span class="folio-nav-badge" aria-label="{n} waiting">{n}</span>`, styled
  with the warning-soft tokens like `.folio-badge.is-changed`, light and dark.
- **Loud failures:** a NavBadge on a non-public or static method, or more than one per
  class, is an `ExtensionException`.

- [ ] **Step 1: Write the failing tests:**
  - `test_badge_count_shown_on_type_item`
  - `test_zero_or_null_hides_badge`
  - `test_badge_not_computed_when_item_hidden`
  - `test_invalid_navbadge_method_throws`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter NavBadge`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): nav badges from #[NavBadge] on type customizations`

### Task 9: Documentation, constraints, 0.21 release checklist

**Files:**
- Modify:
  - READMEs of data (execute), core (Throttle atomic), auth (Tokens, RememberMe, provider
    classes), codes (`check` client, atomic uses) and folio (user provider, frontend gate,
    private assets, nav badges);
  - `ROADMAP.md` (v0.21.0 entry);
  - `packages/*/composer.json` where a consumer uses a 0.21 feature:
    - codes → data;
    - folio → auth, data;
    - core → data, if needed.
- Create: `docs/superpowers/plans/2026-10-22-release-0.21-checklist.md`. Its steps:
  1. merge;
  2. push main;
  3. tag `v0.21.0` (message
     `v0.21.0: atomic throttle and code uses, tokens, remember-me, Folio user provider, frontend gate, private assets, nav badges`);
  4. a preflight on a temp copy of smart-stack.works against Packagist;
  5. sites stay on their current minor until plan D.

  All steps are user-gated.

- [ ] **Step 1: Write the docs** from the tests' working examples, and apply the
  constraint bumps.
- [ ] **Step 2: Validate.** Run `vendor/bin/phpunit`, plus `composer validate --no-check-publish`
  in each changed package. Expected: PASS, valid.
- [ ] **Step 3: Commit.** `chore: prepare 0.21 (docs, constraints, release checklist)`
