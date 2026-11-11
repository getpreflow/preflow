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
- Template functions: `auth_user()`, `auth_check()`, `csrf_token()`, `csrf_field()`, `flash()`
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

    // Any other provider class is built from the container with its entry as `config`,
    // e.g. Preflow\Auth\Record\RecordUserProvider (users stored as records of a data type).
    // `'active' => 'active'` there names a boolean field: a record with it off never resolves.

    // A chain tries the named providers in order, first hit wins (no lock-out while moving
    // from .env users to records; see "User providers" below). A guard
    // uses it as `'provider' => 'chain'`. Once a staff record exists for an e-mail, that record
    // decides: disabled means locked out, and the config password for that e-mail no longer works
    // (a provider implementing ClaimsCredentials, like RecordUserProvider, stops the chain for
    // credentials it knows).
    //   'staff'  => ['class' => RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
    //   'config' => ['class' => ConfigUserProvider::class, 'users' => [...]],
    //   'chain'  => ['class' => ChainUserProvider::class, 'providers' => ['staff', 'config']],

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

### User providers

- `ConfigUserProvider`: users from `config/auth.php` (`users`, usually one admin from `.env`).
  `entries()` returns the configured entries (the staff import reads them).
- `DataManagerUserProvider`: an entity class (`model`).
- `Preflow\Auth\Record\RecordUserProvider`: users stored as records of a data type (`type`; the
  `email`, `password`, `roles` and `epoch` keys name its fields, defaults `email`, `password_hash`,
  `roles`, `auth_epoch`). The signed-in user is a `Record\RecordUser`. Its constructor takes an
  optional type lookup (`\Closure(string): ?TypeDefinition`); without one the DataManager's type
  registry answers. Folio passes its type catalog (which also requires `"users"` in the model). Until
  0.38 it lived in Folio as `Preflow\Folio\Users\RecordUserProvider`; that name still works for one
  release and logs a deprecation. Core binds `RecordUserProvider::factory()` (the DataManager and
  the provider's config entry), so `config/auth.php` builds it on a site without Folio; Folio
  binds its own. The `roles` field is read by `Record\RoleField::normalize()` (a list, a JSON
  string, a comma string or ints; trimmed, unique, non-empty), the rule rbac uses too. With
  `'active' => 'active'` it reads that boolean field. A record whose field is off (`false`, `0`, `'0'`,
  `''`, `'false'` or null) never resolves, by id or by credentials. Its sessions end on the next request.
  Without the key nothing changes.
- **`ChainUserProvider`** (`'providers' => ['staff', 'config']`): tries the named providers in order,
  and the first hit wins. This lets a site move from an `.env` admin to staff records without a
  lock-out window. Chains are built after the other providers, so they may name lazy providers and
  earlier chains. A name that isn't defined throws at boot. `providers()` returns the members.
- **`ClaimsCredentials`** (`claims(array $credentials): bool`), an optional interface: a provider that
  claims the credentials decides for them. The chain returns that provider's answer, even when it is
  null, and does not try the next one. `RecordUserProvider` claims every e-mail it holds a record for,
  active or not. So once a staff record exists for an address, the record decides: a disabled record
  is locked out, and the config password for that address no longer works. A `LazyUserProvider`
  passes the claim through (false while it is unavailable); `inner()` returns the built provider or null.

### Rehash on sign-in

A login that has just verified a password calls
`PasswordRehash::afterVerify($provider, $user, $password, $hasher)`. When `$hasher->needsRehash()`
says the stored hash is outdated (an imported `$1$` MD5-crypt hash, an older cost) and a provider
behind `$provider` (itself, a chain's members, a lazy provider's built one) implements
**`UpdatesPasswordHash`** (`updatePasswordHash($user, $oldHash, $newHash): bool`), the new hash is
stored. The store is a compare-and-set: only while the stored hash is still `$oldHash`, so a
password changed meanwhile stays. Nothing else changes (the session epoch neither), so the new
session survives. A failure is logged without the password and leaves the account as it was; the
sign-in goes on. `RecordUserProvider` implements it; a config user is left alone. Folio's staff
login and the members login call it.

### Signed-in marker

A session guard sets the cookie `preflow_auth=1` (`SessionGuard::SIGNED_IN_COOKIE`; `Path=/`,
`HttpOnly`, `SameSite=Lax`) at login, also when it signs a user back in from a remember-me
cookie, and clears it at logout once no other guard has anyone signed in. It carries no
identity: it tells a page cache that the visitor is signed in without opening the session.
It lasts as long as the remember-me cookie when there is one, else as long as the session
cookie (`session.lifetime`, or the browser session), on the session cookie's `domain` and with
its `Secure` flag. A session found signed in without the marker (one from before the marker
existed, or one that outlived it) sets it again.

### Remember me

A session guard gets a remember-me cookie when its `config/auth.php` entry has a `remember`
key (it needs `preflow/data`; without one there is no cookie):

```php
'guards' => ['members' => [
    'class' => Preflow\Auth\SessionGuard::class,
    'provider' => 'members',
    'remember' => ['cookie' => 'remember_members', 'days' => 30, 'path' => '/'],
]],
```

```php
$guard->login($user, $request, remember: true);   // also queues the cookie
```

- The cookie is `{selector}:{validator}`, 16 and 32 random bytes. Only the SHA-256 hash of
  the validator is stored (table `auth_remember`, on the `auth` storage alias when
  `config/data.php` has one, else the default). A site whose default storage is JSON but
  which migrates the auth tables into SQLite needs `'auth' => 'sqlite'` under `aliases` in
  `config/data.php`; the same alias holds the tokens (`auth_tokens`). It is `HttpOnly`, `SameSite=Lax`, and
  `Secure` by the entry's own `'secure'` in `remember`, else `session.secure` of `config/auth.php`, else when `APP_SECURE` is set.
- When a request has a session but nobody is signed in on the guard,
  `ResolveUserMiddleware` signs the user in from the cookie. Every session guard does this,
  so a member stays remembered beside a signed-in staff user. Pages without a session never
  get a `Set-Cookie`.
- Each use rotates the validator under the same selector; the expiry stays.
- **Theft detection.** A wrong validator for a known selector means an old value was replayed
  after its owner used it: all the user's remember rows for the guard are deleted.
- **Grace window.** The previous validator stays valid for 60 seconds after a rotation, for
  parallel requests carrying one value; it does not rotate again, and a lost rotation keeps
  the member signed in.
- `logout()` deletes the row and clears the cookie. Cookies reach error responses through the
  core `CookieQueue`.
- On SQL one conditional `UPDATE` decides a rotation, so two uses of one value never both
  rotate; on the JSON driver a file lock in `storage/locks` does. `RememberMe::revokeAll()`
  (e.g. after a password change) and `prune()` are also available.

### Ending a user's other sessions

A user that implements `Preflow\Auth\HasSessionEpoch` (`getSessionEpoch(): string`) carries
an epoch. `SessionGuard::login()` keeps it in the session (`{key}:epoch`); `user()` signs a
session out (this guard only) once the user's epoch differs. Bump the epoch on a password
change or reset and sign the current request in again: every other session ends, this one
stays. A session without a stored epoch counts as `"0"`. Revoke the remember rows too
(`RememberMe::revokeAll()`), or a cookie signs the other browsers back in.

### Tokens

`Preflow\Auth\Tokens\Tokens` issues single-use, expiring tokens for links sent by email
(address confirmation, password reset). It is bound when `preflow/data` is configured; the
table `auth_tokens` is created by `preflow migrate`.

```php
$tokens = $container->get(Preflow\Auth\Tokens\Tokens::class);

$token = $tokens->issue('reset', (string) $user->getAuthId(), ttlSeconds: 3600);  // put it in the link
$subject = $tokens->consume($token, 'reset');   // the subject once, then null
$tokens->revokeAll('reset', $subject);          // e.g. after the password changed
$tokens->prune();                               // expired ones
```

- A token is 32 random bytes, base64url; only its SHA-256 hash is stored, as the row id.
- `consume()` spends a token at most once: on SQL one `DELETE` decides by its affected rows,
  on the JSON driver a file lock does. An unknown, expired or wrong-purpose token returns
  `null` and nothing is deleted.
- Tokens are never in the TypeCatalog and never exported.

### Bearer routes: `TokenAuthMiddleware`

`Preflow\Auth\Http\TokenAuthMiddleware` protects an API route with `Authorization: Bearer <token>`.
It never reads the session, so a session cookie does not pass a token route. A missing or
unknown token answers 401 with `WWW-Authenticate: Bearer` (a JSON body on API paths).

```php
// use Preflow\Auth\Authenticatable; use Preflow\Auth\Http\TokenAuthMiddleware;
#[Post('/')]
#[Middleware(TokenAuthMiddleware::class)]
public function create(ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute(Authenticatable::class);
}
```

The guard is the constructor argument, else `auth.api_guard` in `config/auth.php`, else the first
`TokenGuard` configured. A named guard that is not a `TokenGuard` is rejected (401), so a session
guard can never pass a token route. `AuthManager::tokenGuardName(?string $preferred)` returns
the name that is used.

Issue tokens with the CLI (`preflow/devtools`):

```
php preflow auth:token editor@example.com --name=deploy-bot   # prints the token once
php preflow auth:token --list editor@example.com
php preflow auth:token --revoke=<uuid>
```

Only the token's SHA-256 hash is stored. See "JSON APIs" in the core README for the response
shapes and CORS.

### Password hashing

```php
$hasher = $container->get(PasswordHasherInterface::class);

$hash = $hasher->hash('secret');
$hasher->verify('secret', $hash);      // true
$hasher->needsRehash($hash);           // false (current algorithm)
```

The hasher is a container binding (since 0.66): `config/auth.php` `password_hasher` names its
class (default `NativePasswordHasher`), which the container builds, so it may take services. An
app's provider may bind `PasswordHasherInterface` itself instead; the session guards use whichever
the container has when a password is checked.

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

#### Sending signed-out browsers to the login page

Give a guard a `login` path and `AuthMiddleware` stops answering browsers with a bare 401:

```php
'guards' => ['employers' => [
    'class' => SessionGuard::class, 'provider' => 'employers', 'login' => '/employer/login',
]],
```

When nobody is signed in on the guard:

- `GET`/`HEAD` for a browser (not an API path, no `Accept: application/json` without `text/html`, no `HX-Request`) gets
  `302 Location: /employer/login?next=<path and query, url-encoded>`. `next` holds the path and query only, never a host.
- A hypermedia client's request (htmx: `HX-Request: true`) is told to navigate (htmx: 401 with
  `HX-Redirect: /employer/login`), so the browser navigates instead of swapping the login page into a fragment. The
  client is the app's driver (`HypermediaProtocol::navigate()`, core); an `AuthMiddleware` you build yourself finds
  the clients on the request (0.65), or pass `HypermediaProtocols` as the fourth argument.
- Everything else (API paths, JSON clients, `POST` and other methods) still gets 401.

Without a `login` key nothing changes. The container instance receives the core `ApiConfig` to tell browsers from API
clients; an `AuthMiddleware` you build yourself without the third argument never redirects.

A named guard needs a small wrapper, because `#[Middleware]` takes a class name:

```php
final class EmployerAuth implements MiddlewareInterface
{
    public function __construct(private readonly AuthManager $auth, private readonly ApiConfig $api) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return (new AuthMiddleware($this->auth, 'employers', $this->api))->process($request, $handler);
    }
}
```

`AuthManager::loginPath('employers')` returns the configured path (null for none; null as the name is the default guard).
`AuthManager::provider('staff')` returns a guard's user provider (since 0.65; null for an unknown guard, null as the name
is the default guard's, which is also bound as `UserProviderInterface`).

Check `next` before redirecting back after login: accept only a value that starts with `/` and not `//` (and no
backslash), otherwise go to your default page. Otherwise the login page is an open redirect.

### Templates

```twig
{% if auth_check() %}
    Welcome, {{ auth_user().email }}
    <form method="post" action="/logout">
        {{ csrf_field() }}
        <button type="submit">Logout</button>
    </form>
{% endif %}

{% set error = flash('error') %}
{% if error %}
    <p>{{ error }}</p>
{% endif %}
```

### CSRF helpers

> **Breaking change in v0.11:** `csrf_token()` now returns the raw token string. Use `csrf_field()` for forms.

| Function | Returns |
|---|---|
| `csrf_token()` | Raw token string — useful for `fetch()` headers (`X-CSRF-Token`) or meta tags |
| `csrf_field()` | Complete `<input type="hidden" name="_csrf_token" value="...">` element |

Both work with or without a session. With one they print the session's token. Without one (a
public page on a site with `session.paths`, for a visitor without the session cookie) they
print the stateless token: signed with
`app.key`, the same for every visitor, valid for at least a day, so the page stays cookie-free
and cacheable. Use `csrf_field()` in every form, public ones included (a contact form, a like
button): browsers that send no origin headers need it. With an empty `app.key` or the built-in
default key there is no stateless token, and both print an empty string on public pages. See
the CSRF section of `preflow/core` for how requests are checked.

```twig
{# In a form #}
{{ csrf_field() }}

{# In a fetch() call #}
<meta name="csrf-token" content="{{ csrf_token() }}">
```

Any template that previously used `{{ csrf_token()|raw }}` must be updated to `{{ csrf_field() }}`.

## Permissions

Code asks one question, the same way everywhere:

```php
$access->can('page.publish');          // Preflow\Auth\Access\Access, injected
$access->can('invoice.view', $invoice); // a subject, for resolvers that check records
```

```twig
{% if can('page.publish') %}<button>Publish</button>{% endif %}
```

It asks about the user signed in for this request. The answer comes from the
installed resolver:

- **The default** (no permission package): staff may do everything, members
  nothing.
- **`preflow/roles`**: roles from `config/roles.php`.
- **[`preflow/rbac`](../rbac/README.md)**: roles and permissions stored in the database and
  edited in Folio; it sets its own resolver.

Every permission is declared, so role editors can list them and typos are
caught:

```php
$registry = $access->registry();
$registry->declare('invoice.view', 'View', 'Invoices');
$registry->declarePattern('invoice.*'); // groups the family (role editors, docs); names are still declared one by one
```

- **Undeclared permissions:** an undeclared permission throws
  `UndeclaredPermission` in debug. In production it is denied and logged once
  per request.
- **No auth:** without `config/auth.php`, `can()` is always true.

**Staff and members.** Each guard serves one population. Set it with
`population` in `config/auth.php`:

```php
'guards' => [
    'session' => ['class' => SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
    'members' => ['class' => SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
],
```

A package that signs staff in, such as Folio, marks its guard as staff itself;
config wins. A guard with no population serves members. A population of the app's own
(`'population' => 'employers'` in preflow/starter) counts as members for permissions, and a
package's mark never makes it staff (since 0.65; Folio refuses to sign staff in through such a
guard). `$access->declaredPopulation($guard)` returns what the config says (null for none),
`populationOf($guard)` what counts (`staff` or `members`).

Every request asks each guard who is signed in, the default guard first; the first
user found counts (`auth_user()`, `can()`, `AuthManager::guardName()`). Sign members
in with their own guard, `$auth->guard('members')->login($user, $request)`.
`AuthMiddleware` and `GuestMiddleware` check one guard, the default one unless
you name another: `new AuthMiddleware($auth, 'members')`. So a signed-in member
does not pass a staff route, and staff can still sign in as a member. Each
session guard keeps its own session key, so signing a member out leaves staff
signed in (the session id is renewed either way; the session ends when nobody is
left).

**A custom resolver:** implement `PermissionResolver::allows($user, $population,
$permission, $subject)` and register it with `$access->setResolver(...)` in a
service provider. `$access->resolver()` returns the one answering now, so a package can wrap it
or leave another package's in place (the members core wraps only the `DefaultResolver`, giving
an admitted member `members.access`; with rbac, rbac decides).

**Which roles exist.** A resolver that also implements `Preflow\Auth\Access\ListsRoles`
(`roles(string $population): list<string>`) tells which role names a population has:
preflow/roles (the keys of `config/roles.php`), preflow/rbac (the role records), the members
core (`member`, and the staff roles of the resolver before it). `$access->roles('staff')` asks the
resolver answering now; null when it lists none (the default resolver). Tools check a role with
it before they give it to an account (preflow/starter-folio's `staff:create`). Since 0.65.

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

Register it in `config/auth.php` under `guards`. A class that doesn't exist or is not a
`GuardInterface` fails at boot:

```php
'guards' => ['ldap' => ['class' => LdapGuard::class, 'provider' => 'users', 'population' => 'staff', 'server' => 'ldaps://ldap.example.com']],
```

It is built through the container on first use (since 0.66), after the app's service providers
ran, with these named arguments:

- `provider`: its user provider, built;
- `config`: its whole `config/auth.php` entry (`class`, `provider`, `population` and keys of its
  own, such as `server` above), as user providers get theirs.

Any other constructor parameter is resolved by the container: services a provider binds,
`#[Config]` and `#[Env]` values. `AuthManager::guard('ldap')` hands out the guard itself, so
`instanceof LdapGuard` works.

```php
public function __construct(
    private readonly UserProviderInterface $provider,
    private readonly array $config,
    private readonly LdapConnection $ldap,          // bound by an app provider
) {}
```

## Testing

```php
use Preflow\Testing\AuthTestHelpers;

final class DashboardTest extends TestCase
{
    use AuthTestHelpers;

    public function test_dashboard_for_a_signed_in_user(): void
    {
        $user = new ArrayUser('u1', 'u1@site.test', '', ['admin']);
        $request = $this->actingAs($user, (new Psr17Factory())->createServerRequest('GET', '/dashboard'));

        // Handled by the app, the user counts everywhere: AuthMiddleware, auth_user(), can().
        $response = $this->app->handle($request);
    }
}
```
