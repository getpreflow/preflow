# Preflow Auth System Design

**Date:** 2026-04-12
**Status:** Approved
**Packages affected:** `preflow/core`, `preflow/auth` (new), `preflow/skeleton`, `preflow/testing`

## Overview

A pluggable authentication system for Preflow built on three layers:

1. **Session + CSRF** (in `preflow/core`) — General request infrastructure, usable without auth
2. **Auth package** (`preflow/auth`) — Guard system, password hashing, middleware, user provider
3. **Skeleton pages** — Login, register, logout pages owned by the user

Design principles: security-first, interface-driven, auto-discovered via `Application::boot()`, no coupling to specific user models or template engines.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Auth strategy | Pluggable guard system | Interface-first; session + token guards ship as defaults |
| Session storage | Lean wrapper over native PHP | Battle-tested engine, clean `SessionInterface` for testability |
| User model | Interface + trait + skeleton model | Guards decouple from schema; trait eliminates boilerplate; skeleton provides starting point |
| Password hashing | `PasswordHasherInterface` | Wraps PHP native; swappable for legacy migrations |
| Roles/permissions | Simple string roles on user model | `getRoles()`, `hasRole()` — covers 90% of cases, RBAC can layer on later |
| Remember me | Deferred to future version | Security surface area not justified for v1 |
| Login/register UI | Skeleton ships pages | Auth package has no views; pages live in `app/pages/` as user-owned code |
| CSRF location | `preflow/core` | Security primitive, not auth concern; sits next to nonce generator and security headers |

---

## 1. Session System (`preflow/core`)

### SessionInterface

```php
namespace Preflow\Core\Http\Session;

interface SessionInterface
{
    public function start(): void;
    public function getId(): string;
    public function regenerate(): void;
    public function invalidate(): void;
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function flash(string $key, mixed $value): void;
    public function getFlash(string $key, mixed $default = null): mixed;
    public function isStarted(): bool;
}
```

- `regenerate()` — New session ID, keep data. Used on login (session fixation prevention).
- `invalidate()` — New session ID, clear all data. Used on logout.
- `flash()` — Write value available for next request only.

### NativeSession

Implements `SessionInterface` by wrapping `$_SESSION`. Constructor accepts config array:

```php
[
    'lifetime' => 7200,
    'cookie' => 'preflow_session',
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]
```

`start()` calls `session_start()` with these options. Flash data stored in `$_SESSION['_flash']` sub-array with `current` and `previous` keys.

### SessionMiddleware (PSR-15)

Request flow:

1. Create and start session
2. Age flash data: delete `previous`, move `current` to `previous`
3. Attach `SessionInterface` to request as attribute: `$request->getAttribute(SessionInterface::class)`
4. Call `$handler->handle($request)`
5. Close session (write + release lock)

Registered globally via `Application::boot()` when session config exists in `config/auth.php`.

### File locations

```
packages/core/src/Http/Session/
├── SessionInterface.php
├── NativeSession.php
└── SessionMiddleware.php
```

---

## 2. CSRF Protection (`preflow/core`)

### CsrfToken

Value object. Generated via `random_bytes(32)`, hex-encoded. Stored in session under `_csrf_token`. Static factory: `CsrfToken::generate(SessionInterface): self`. Accessor: `getValue(): string`.

### CsrfMiddleware (PSR-15)

**Safe methods** (GET, HEAD, OPTIONS):
- Generate token if none exists in session
- Attach `CsrfToken` to request as attribute `CsrfToken::class`

**State-changing methods** (POST, PUT, PATCH, DELETE):
1. Read submitted token from `_csrf_token` form field OR `X-CSRF-Token` header
2. Compare against session token using `hash_equals()` (constant-time)
3. Mismatch → throw `ForbiddenHttpException`
4. Match → pass through

**Exemptions:** Constructor accepts array of path patterns to skip. Default: `['/--component/action']` (component actions use HMAC tokens).

### Template Integration

`csrf_token()` template function registered via extension provider:

```html
<input type="hidden" name="_csrf_token" value="{{ csrf_token() }}">
```

For HTMX requests, a `<meta name="csrf-token">` tag + `hx-headers='{"X-CSRF-Token": "..."}'` pattern.

### File locations

```
packages/core/src/Http/Csrf/
├── CsrfToken.php
└── CsrfMiddleware.php
```

---

## 3. Guard System (`preflow/auth`)

### GuardInterface

```php
namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;

interface GuardInterface
{
    public function user(ServerRequestInterface $request): ?Authenticatable;
    public function validate(array $credentials): bool;
    public function login(Authenticatable $user, ServerRequestInterface $request): void;
    public function logout(ServerRequestInterface $request): void;
}
```

- `user()` — Resolve authenticated user from request. Returns `null` if unauthenticated.
- `validate()` — Check credentials without establishing auth state.
- `login()` — Establish auth state (write to session, etc.).
- `logout()` — Clear auth state.

### SessionGuard

Default guard for browser requests.

- Stores user ID in session key `_auth_user_id`
- `user()` — Read ID from session, load via `UserProviderInterface`
- `login()` — Write user ID to session, call `$session->regenerate()` (fixation prevention)
- `logout()` — Call `$session->invalidate()`
- `validate()` — Find user by credentials, verify password via `PasswordHasherInterface`

Dependencies: `SessionInterface`, `UserProviderInterface`, `PasswordHasherInterface`.

### TokenGuard

Stateless guard for API requests.

- Reads `Authorization: Bearer <token>` header
- Looks up token in `user_tokens` table — token stored as SHA-256 hash (plain-text never persisted)
- `login()` / `logout()` are no-ops (stateless)
- Token creation is a separate concern (controller action returns plain-text once, stores hash)

Dependencies: `UserProviderInterface`, `DataManager` (for token table).

### UserProviderInterface

```php
namespace Preflow\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?Authenticatable;
    public function findByCredentials(array $credentials): ?Authenticatable;
}
```

Decouples guards from data layer. Default `DataManagerUserProvider` wraps `DataManager::find()` and `DataManager::query()`.

### AuthManager

Resolves guards by name from config:

```php
$manager->guard();          // Default guard from config
$manager->guard('token');   // Named guard
```

Reads `config/auth.php` `guards` and `providers` sections. Lazy-instantiates guards on first access.

---

## 4. Authenticatable Contract (`preflow/auth`)

### Authenticatable Interface

```php
namespace Preflow\Auth;

interface Authenticatable
{
    public function getAuthId(): string;
    public function getPasswordHash(): string;
    public function getRoles(): array;
    public function hasRole(string $role): bool;
}
```

### AuthenticatableTrait

```php
namespace Preflow\Auth;

trait AuthenticatableTrait
{
    public function getAuthId(): string
    {
        return $this->uuid;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return $this->roles ?? [];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }
}
```

Assumes `$uuid`, `$passwordHash`, `$roles` properties. Users override methods if their schema differs.

### Skeleton User Model (`app/Models/User.php`)

```php
#[Entity(table: 'users', storage: 'default')]
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id] public string $uuid;
    #[Field] public string $email;
    #[Field(transform: PasswordHashTransformer::class)] public string $passwordHash;
    #[Field(transform: JsonTransformer::class)] public array $roles = [];
    #[Field] public string $createdAt;
}
```

---

## 5. Auth Middleware & Request Flow (`preflow/auth`)

### AuthMiddleware (PSR-15)

Usage via attributes:

```php
#[Middleware(AuthMiddleware::class)]
#[Middleware(AuthMiddleware::class, guard: 'token')]
#[Middleware(AuthMiddleware::class, roles: ['admin'])]
```

Flow:

1. Resolve guard from middleware options (default if not specified)
2. Call `$guard->user($request)` to get authenticated user
3. No user → throw `UnauthorizedHttpException` (401)
4. Roles specified and user lacks required role → throw `ForbiddenHttpException` (403)
5. Attach user to request attribute `Authenticatable::class`
6. Call `$handler->handle($request)`

### GuestMiddleware (PSR-15)

Inverse of AuthMiddleware. Redirects authenticated users to `/` (or configured path). Applied to login/register pages so logged-in users don't see them.

### Request Access Patterns

```php
// Controllers and page handlers:
$user = $request->getAttribute(Authenticatable::class);

// Components (existing Guarded interface):
public function authorize(string $action, ServerRequestInterface $request): void
{
    $user = $request->getAttribute(Authenticatable::class);
    if (!$user?->hasRole('admin')) {
        throw new ForbiddenHttpException();
    }
}

// Templates (via extension provider):
{% if auth_check() %}
    Welcome, {{ auth_user().email }}
{% endif %}
```

### Boot Integration

`Application::bootAuth()` runs when `preflow/auth` is installed:

1. Read `config/auth.php`
2. Register `AuthManager` in container
3. Register `PasswordHasherInterface` binding in container
4. Register `auth_user()` and `auth_check()` template functions via extension provider

Auth middleware is not applied globally — routes opt in via `#[Middleware]` attribute.

---

## 6. Password Hashing (`preflow/auth`)

### PasswordHasherInterface

```php
namespace Preflow\Auth;

interface PasswordHasherInterface
{
    public function hash(string $password): string;
    public function verify(string $password, string $hash): bool;
    public function needsRehash(string $hash): bool;
}
```

### NativePasswordHasher

Wraps `password_hash()`, `password_verify()`, `password_needs_rehash()`. Constructor accepts algorithm (`PASSWORD_DEFAULT`) and options (`['cost' => 12]`).

### PasswordHashTransformer

Implements `FieldTransformer` from the data layer. Receives `PasswordHasherInterface` via constructor injection (resolved from the container when the transformer is instantiated by the data layer):

```php
public function __construct(private PasswordHasherInterface $hasher) {}

public function toStorage(mixed $value, mixed $original): mixed
{
    if ($value === $original) {
        return $value;  // No change — don't re-hash
    }
    return $this->hasher->hash($value);
}

public function fromStorage(mixed $value): mixed
{
    return $value;  // Pass through
}
```

### Rehash on Login

`SessionGuard` checks `$hasher->needsRehash($user->getPasswordHash())` after successful `validate()`. If true, re-hashes and saves the user. Transparent algorithm upgrade.

---

## 7. Configuration & Package Structure

### `config/auth.php` (skeleton)

```php
return [
    'default_guard' => 'session',

    'guards' => [
        'session' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'data_manager',
        ],
        'token' => [
            'class' => Preflow\Auth\TokenGuard::class,
            'provider' => 'data_manager',
        ],
    ],

    'providers' => [
        'data_manager' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
```

### Package: `preflow/auth`

```
packages/auth/
├── composer.json
├── src/
│   ├── Authenticatable.php
│   ├── AuthenticatableTrait.php
│   ├── AuthManager.php
│   ├── AuthServiceProvider.php
│   ├── GuardInterface.php
│   ├── SessionGuard.php
│   ├── TokenGuard.php
│   ├── UserProviderInterface.php
│   ├── DataManagerUserProvider.php
│   ├── PasswordHasherInterface.php
│   ├── NativePasswordHasher.php
│   ├── PasswordHashTransformer.php
│   └── Http/
│       ├── AuthMiddleware.php
│       └── GuestMiddleware.php
└── tests/
```

### Additions to `preflow/core`

```
packages/core/src/Http/Session/
├── SessionInterface.php
├── NativeSession.php
└── SessionMiddleware.php

packages/core/src/Http/Csrf/
├── CsrfToken.php
└── CsrfMiddleware.php
```

### Additions to `preflow/skeleton`

```
skeleton/
├── config/auth.php
├── app/Models/User.php
├── app/pages/login.twig + login.php
├── app/pages/register.twig + register.php
└── app/pages/logout.php
```

### Dependencies

- `preflow/auth` requires `preflow/core` (session, CSRF) and `preflow/data` (user storage)
- `preflow/core` gains no new external dependencies
- Skeleton requires `preflow/auth`

---

## 8. Testing Strategy

### Unit Tests

**Session (`preflow/core`):**
- `NativeSession` — start, get/set/remove, flash lifecycle, regenerate, invalidate
- `SessionMiddleware` — attaches session to request, ages flash data, closes session

**CSRF (`preflow/core`):**
- `CsrfMiddleware` — generates token on GET, validates on POST, rejects mismatch, skips exempt paths, reads `X-CSRF-Token` header

**Guards (`preflow/auth`):**
- `SessionGuard` — login writes ID to session, user() loads from session + provider, logout invalidates, regenerates on login
- `TokenGuard` — reads bearer header, looks up hashed token, login/logout no-ops
- Custom guard via `GuardInterface` — verify contract with test double

**Password hashing (`preflow/auth`):**
- `NativePasswordHasher` — hash, verify, needsRehash
- `PasswordHashTransformer` — hashes on change, passes through on no-op, passes through from storage

**Middleware (`preflow/auth`):**
- `AuthMiddleware` — passes authenticated user, 401 on missing, 403 on insufficient role, attaches user to request
- `GuestMiddleware` — redirects authenticated users, passes unauthenticated

**User provider (`preflow/auth`):**
- `DataManagerUserProvider` — findById, findByCredentials, returns null on miss

### Integration Tests (test project)

- Full login flow: POST credentials → session created → redirect → authenticated request
- Full register flow: POST form → user created → redirect to login
- Logout: session invalidated, subsequent request unauthenticated
- Protected route: unauthenticated → 401, wrong role → 403, correct role → 200
- CSRF: form without token → 403, form with token → passes
- Token guard: API request with valid bearer → 200, invalid → 401

### Testing Helpers (`preflow/testing`)

```php
$this->actingAs(User $user, string $guard = 'session');
```

Sets authenticated user on request without going through login flow.
