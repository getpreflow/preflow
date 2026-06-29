# Folio Admin Auth Pass — Design

**Date:** 2026-06-29
**Packages:** `preflow/auth`, `preflow/core`, `preflow/folio`
**Status:** Approved.
**Context:** The Folio admin currently ships with `middleware: []` (no auth) and an
orphaned `@folio/admin/login.twig`. This pass gates the admin behind login using
the framework's already-built, tested auth/session/CSRF stack
(`2026-04-12-auth-system-design.md`, implemented as `preflow/auth` + session/CSRF
in `preflow/core`). Sequenced **before roadmap #4** (core CMS areas, incl. user
management), so credentials live in config — no DB user store, no management UI.

## Goal

Require login to reach any Folio admin page; keep the public frontend open. Reuse
`SessionGuard`, `AuthManager`, `NativePasswordHasher`, the session + CSRF
middleware, and the auth Twig helpers verbatim — the auth pass is **wiring plus
one small framework gap-fill**, not a new auth system.

## Why a small framework addition

`Application::bootAuth()` builds the hasher, providers, guards, `AuthManager`, the
per-request user-populate middleware, and the `AuthMiddleware`/`GuestMiddleware`
instances entirely from `config/auth.php`. But its provider loop **only knows
`DataManagerUserProvider`** (a `class-string<Model&Authenticatable>` — a PHP
entity, which Folio's dynamic `DynamicRecord` types are not). A config-credential
provider declared in `config/auth.php` would be silently skipped. The framework
auth is clearly meant to be pluggable; it just only shipped the DB provider. So we
add the missing config provider to `preflow/auth` (broadly useful — the skeleton
could use it for a no-DB setup too). Everything else in `bootAuth` then works
unchanged against config credentials.

## Architecture

### Layer 1 — `preflow/auth` (the reusable gap-fill)

- **`ArrayUser implements Authenticatable`** — a value object holding
  `id`/`email`/`passwordHash`/`roles`; `getAuthId()` returns the stable id (the
  email), `getPasswordHash()`/`getRoles()`/`hasRole()` as the contract requires.
- **`ConfigUserProvider implements UserProviderInterface`** — constructed with a
  list of `['email' => , 'password_hash' => , 'roles' => []]`. `findByCredentials`
  matches on `email`; `findById` matches the same id the guard persists (the
  email). Returns `ArrayUser`. No password verification here — `SessionGuard`
  already verifies via `PasswordHasherInterface`.
- **`Application::bootAuth()`** — add ONE branch to the providers loop, mirroring
  the existing `DataManagerUserProvider` branch:
  `if ($providerClass === ConfigUserProvider::class) { $providers[$name] = new
  ConfigUserProvider($providerConfig['users'] ?? []); }`.

With this, a `config/auth.php` declaring `default_guard: session`, a `session`
guard over a `ConfigUserProvider`, the `session` block, and
`NativePasswordHasher` makes the whole stack live — session, CSRF, current-user,
and the auth Twig helpers — with zero Folio-specific guard code.

### Layer 2 — `preflow/folio` gating + login

- **`Http/AdminAuthMiddleware`** — like the framework `AuthMiddleware`, but on no
  authenticated user it returns a **`302` redirect to `{prefix}/login`** (CMS UX)
  instead of throwing `401`. On success it sets the current user + request
  attribute and continues. Constructed with `AuthManager` + the admin `prefix`.
- **`Http/AuthController`** —
  - `GET {prefix}/login` → if already authenticated, `302` to the dashboard;
    else render `@folio/admin/login.twig` (action `{prefix}/login`, CSRF token, a
    flashed error if present).
  - `POST {prefix}/login` → validate the submitted `email`/`password` through the
    session guard and, on success, log the user in (session id persisted) and
    `302` to the dashboard; on failure, flash an error and re-render the login
    form (no redirect loop). Mirrors the skeleton `AuthController` pattern.
  - `POST {prefix}/logout` → log out (session invalidated) and `302` to
    `{prefix}/login`.
- **`Routing/FolioRoutes::admin($prefix, bool $authEnabled)`** —
  - When `$authEnabled`: prepend `GET/POST {prefix}/login` + `POST {prefix}/logout`
    (handled by `AuthController`) **before** the `{type}` routes, so `/folio/login`
    is not matched as `{type}=login` (same ordering rule as the preview routes).
  - Attach `[AdminAuthMiddleware::class]` to every admin **content** route
    (dashboard, list, CRUD, **and the preview routes**) — i.e. all routes except
    the `AuthController` ones. The login/logout routes stay open (guest-accessible).
  - **`/_assets/{file}` and `/_uploads/{...}` stay public** (the login page loads
    `admin.css`/`admin.js`; uploads may appear on public pages). They are appended
    after the loop with `middleware: []`, unchanged.
  - When `!$authEnabled`: today's behavior exactly — no login routes, all
    `middleware: []` (backward-compatible for Folio apps without `config/auth.php`).
- **`FolioServiceProvider`** — compute `$authEnabled = file_exists(config/auth.php)`;
  pass it to `FolioRoutes::admin`; bind `AuthController` and `AdminAuthMiddleware`
  (each resolving `AuthManager` lazily at request time, so boot order is moot).
  Add `preflow/auth` to the package's composer `require`.
- **Templates** — `login.twig`: add `{{ csrf_field() }}` (CSRF is now active) and
  use `login_action`/`login_error`. `_layout.twig`: show `auth_user()`'s email and
  a `POST {prefix}/logout` button (rendered only when authenticated).
- **Roles** are carried on `ArrayUser` but **not enforced** for gating in this pass
  (any authenticated config user reaches the admin). Role-based authorization is a
  #4 concern.

### Layer 3 — demo wiring

- `examples/folio-demo/config/auth.php` — a `session` block + a `session` guard
  over a `ConfigUserProvider` with **one default admin** (`admin@folio.test`, a
  precomputed `password_hash`, `roles: ['admin']`), `NativePasswordHasher`. This
  flips on session + CSRF + auth for the demo, so the admin is gated and loginable.
- `seed.php` / the demo README surfaces the demo login (the email + the documented
  plaintext password the hash corresponds to).

## Cross-cutting effect (must verify during build)

Activating auth turns on **session + CSRF enforcement globally** — the demo has
neither today. The existing admin forms already render `_csrf_token` (via
`form_begin`), and the preview `fetch` posts `FormData` including it, so this
should be compatible. The build MUST confirm every admin POST still works under
CSRF (store/update/delete, the create-in-drawer POST, the preview POST) and that
the login form's new CSRF field validates. The label API is a `GET` (CSRF-exempt).

## New / affected files

**`preflow/auth`:** `src/ArrayUser.php` (new), `src/ConfigUserProvider.php` (new),
`tests/ArrayUserTest.php` + `tests/ConfigUserProviderTest.php` (new).
**`preflow/core`:** `src/Application.php` (modify — `bootAuth` provider branch);
a wiring test under `packages/core/tests` or via the Folio integration.
**`preflow/folio`:** `src/Http/AdminAuthMiddleware.php` (new),
`src/Http/AuthController.php` (new), `src/Routing/FolioRoutes.php` (modify),
`src/FolioServiceProvider.php` (modify), `composer.json` (add `preflow/auth`),
`templates/admin/login.twig` (modify), `templates/admin/_layout.twig` (modify),
tests under `packages/folio/tests` (AdminAuthMiddleware unit; auth integration).
**demo:** `examples/folio-demo/config/auth.php` (new); `seed.php`/README note.

## Testing

**`preflow/auth`:** `ConfigUserProvider::findByCredentials`/`findById` return the
right `ArrayUser` (and null for unknown email); `ArrayUser` exposes id/hash/roles
and `hasRole`. (Real `SessionGuard` + `NativePasswordHasher` already tested.)

**`preflow/core`:** the `bootAuth` `ConfigUserProvider` branch is exercised
end-to-end by the Folio integration tests below (a real kernel booting auth from a
`config/auth.php` that declares `ConfigUserProvider` — a successful login proves
the branch wired the provider → guard). No separate core unit test is added; the
integration coverage is the meaningful guarantee.

**`preflow/folio` (integration, real kernel + auth configured):**
- Unauthenticated `GET {prefix}` (and a CRUD route) → `302` to `{prefix}/login`.
- `GET {prefix}/login` → `200` with the login form + a CSRF field.
- `POST {prefix}/login` with the correct credential → `302` to the dashboard and a
  populated session; with a wrong credential → re-render with an error, no session.
- An authenticated request (carrying the session) → `200`.
- `POST {prefix}/logout` → `302` to login; afterward the admin redirects again.
- `/_assets/{file}` and `/_uploads/{...}` return `200`/serve while logged out.
- An authenticated admin `POST` (e.g. store) round-trips under active CSRF with the
  form token; preview/drawer POSTs likewise.
- With **no** `config/auth.php`, the admin is reachable without login (backward-compat).

**Full suite:** `vendor/bin/phpunit` green (only the pre-existing deprecations + 1
skip); folio + demo green under strict Twig.

## Out of scope (→ #4 / later)

A user content type / DB-backed users; user-management UI; self-registration;
password reset; role-based authorization; remember-me; rate-limiting/lockout;
the token guard for an API. The `ConfigUserProvider` is the swap point: #4 can
replace it with a DB-backed provider without touching the gating, login flow, or
session wiring.
