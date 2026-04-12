# Preflow Auth

Pluggable authentication for Preflow applications. Session-based login, API token guards, password hashing, and PSR-15 middleware.

## Installation

```bash
composer require preflow/auth
```

Requires `preflow/core` and `preflow/data`.

## What it does

- Pluggable guard system — session and bearer token guards ship as defaults, implement `GuardInterface` for custom auth
- Session management with CSRF protection (both in `preflow/core`)
- Password hashing via `password_hash()` / `password_verify()` with transparent rehash support
- PSR-15 middleware for route-level auth (`AuthMiddleware`) and guest-only pages (`GuestMiddleware`)
- Template functions: `auth_user()`, `auth_check()`, `csrf_token()`, `flash()`
- Auto-discovered by `Application::boot()` when `config/auth.php` exists

## Configuration

`config/auth.php`:

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

## User model

Implement the `Authenticatable` interface. Use `AuthenticatableTrait` for the common case:

```php
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;
use Preflow\Data\Model;
use Preflow\Data\Attributes\{Entity, Id, Field};
use Preflow\Data\Transform\JsonTransformer;

#[Entity(table: 'users', storage: 'default')]
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id] public string $uuid = '';
    #[Field(searchable: true)] public string $email = '';
    #[Field] public string $passwordHash = '';
    #[Field(transform: JsonTransformer::class)] public array $roles = [];
    #[Field] public ?string $createdAt = null;
}
```

The trait assumes `$uuid`, `$passwordHash`, and `$roles` properties. Override methods if your schema differs.

## API

### Guards

```php
// Resolve from container (after boot)
$auth = $container->get(AuthManager::class);

$guard = $auth->guard();          // default guard
$guard = $auth->guard('token');   // named guard

$user = $guard->user($request);   // resolve user from request
$guard->login($user, $request);   // establish session
$guard->logout($request);         // invalidate session
$guard->validate(['email' => $email, 'password' => $password]); // check credentials
```

### SessionGuard

Stores user ID in session key `_auth_user_id`. Regenerates session on login (session fixation prevention). Invalidates session on logout.

### TokenGuard

Reads `Authorization: Bearer <token>` header. Looks up SHA-256 hashed token in `user_tokens` table. Stateless — `login()` and `logout()` are no-ops.

```php
// Create a token
$plain = PersonalAccessToken::generatePlainToken();

$token = new PersonalAccessToken();
$token->uuid = bin2hex(random_bytes(16));
$token->tokenHash = PersonalAccessToken::hashToken($plain);
$token->userId = $user->getAuthId();
$token->name = 'api-key';
$dm->save($token);

// Return $plain to the user (only shown once)
```

### Password hashing

```php
$hasher = $container->get(PasswordHasherInterface::class);

$hash = $hasher->hash('secret');
$hasher->verify('secret', $hash);      // true
$hasher->needsRehash($hash);           // false (current algorithm)
```

### Middleware

Protect routes with `#[Middleware]` attributes:

```php
use Preflow\Routing\Attributes\{Route, Get, Middleware};
use Preflow\Auth\Http\AuthMiddleware;

#[Route('/dashboard')]
#[Middleware(AuthMiddleware::class)]
final class DashboardController
{
    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(Authenticatable::class);
        // ...
    }
}
```

`GuestMiddleware` redirects authenticated users away (for login/register pages).

### Templates

```twig
{% if auth_check() %}
    Welcome, {{ auth_user().email }}
    <form method="post" action="/logout">
        {{ csrf_token()|raw }}
        <button type="submit">Logout</button>
    </form>
{% endif %}

{% set error = flash('error') %}
{% if error %}
    <p>{{ error }}</p>
{% endif %}
```

## Custom guards

Implement `GuardInterface`:

```php
use Preflow\Auth\{GuardInterface, Authenticatable};
use Psr\Http\Message\ServerRequestInterface;

final class LdapGuard implements GuardInterface
{
    public function user(ServerRequestInterface $request): ?Authenticatable { /* ... */ }
    public function validate(array $credentials): bool { /* ... */ }
    public function login(Authenticatable $user, ServerRequestInterface $request): void { /* ... */ }
    public function logout(ServerRequestInterface $request): void { /* ... */ }
}
```

Register in `config/auth.php` under `guards`.

## Testing

```php
use Preflow\Testing\AuthTestHelpers;

final class DashboardTest extends TestCase
{
    use AuthTestHelpers;

    public function test_dashboard_requires_auth(): void
    {
        $user = new TestUser(uuid: 'u1', roles: ['admin']);
        $request = $this->actingAs($user, $this->createRequest('GET', '/dashboard'));
        // $request now has Authenticatable attribute set
    }
}
```
