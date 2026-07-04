# Folio Admin Auth Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate the Folio admin behind login using the framework's existing auth/session/CSRF stack, with credentials from config (no DB), leaving the public frontend open.

**Architecture:** Add the missing config-credential provider to `preflow/auth` (`ArrayUser` + `ConfigUserProvider`) and one `bootAuth` branch that constructs it and exposes the default provider. Folio adds a redirect-to-login `AdminAuthMiddleware`, an `AuthController` (login/logout), route gating (all admin + preview routes; assets/uploads stay public), a CSRF field on the login form, and a user/logout affordance — all conditional on `config/auth.php` existing. The demo ships a `config/auth.php` with one config admin.

**Tech Stack:** PHP 8.5, `preflow/auth`, `preflow/core`, `preflow/folio`, Twig, PHPUnit 11. No new Composer dependency beyond Folio requiring `preflow/auth` (already in the monorepo).

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file. No emojis.
- **Reuse the framework auth stack** (`SessionGuard`, `AuthManager`, `NativePasswordHasher`, session + CSRF middleware, auth Twig helpers). The only framework additions are `ArrayUser`, `ConfigUserProvider`, and a `bootAuth` branch.
- **Config credentials only** — no DB user store, no management UI, no roles enforcement (roles are carried, not checked). That's roadmap #4.
- **Gating is conditional** on `config/auth.php` existing: with it, the admin is gated and login routes exist; without it, Folio behaves exactly as today (all `middleware: []`, no login) — backward-compatible.
- **Public routes stay public:** `{prefix}/_assets/{file}`, `{prefix}/_uploads/{...}`, and the login routes (`GET`/`POST {prefix}/login`) are never gated (the login page loads `admin.css`/`admin.js`).
- **Route ordering:** the login/logout routes MUST be registered before the `{type}` routes so `/folio/login` isn't matched as `{type}=login` (same rule as the preview routes).
- **CSRF becomes active** once auth boots. The login form gains a CSRF field; existing admin forms already carry `_csrf_token`. The build verifies an authenticated admin POST round-trips under CSRF.
- **Native sessions are CLI-hostile:** any test that boots the full app WITH `config/auth.php` (so `NativeSession` starts) MUST use `@runInSeparateProcess` (the convention `NativeSessionTest` already uses). Pure-logic tests use PHPUnit mocks / fakes and need no session. If `session_start()`/cookie emission still warns under CLI in a separate process, wrap the test body (or `setUp`) with `ob_start()` and assert as normal — do NOT suppress with `@`; a warning in output is a finding. If an auth-booting test proves genuinely unstable in-process, report it: the unit tests (Tasks 1, 3) carry the logic coverage, and the redirect/public-route integration checks do not need cross-request session state.
- **Test command:** `vendor/bin/phpunit <path>` from `/Users/smyr/Sites/gbits/flopp`. Full suite runs with NO `APP_DEBUG` exported.

## File Structure

- `packages/auth/src/ArrayUser.php` — **new**: config-user value object.
- `packages/auth/src/ConfigUserProvider.php` — **new**: `UserProviderInterface` over a config admin list.
- `packages/core/src/Application.php` — **modify**: `bootAuth` constructs `ConfigUserProvider` + binds the default provider as `UserProviderInterface`.
- `packages/folio/src/Http/AdminAuthMiddleware.php` — **new**: redirect-to-login gate.
- `packages/folio/src/Http/AuthController.php` — **new**: login form / login / logout.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**: `admin($prefix, $authEnabled)` — login/logout routes + gating.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: bind the two new classes; compute `authEnabled`; pass it to routes; register the `folio_auth_enabled` Twig global.
- `packages/folio/composer.json` — **modify**: require `preflow/auth`.
- `packages/folio/templates/admin/login.twig` — **modify**: CSRF field + action/error wiring.
- `packages/folio/templates/admin/_layout.twig` — **modify**: current user + logout (guarded).
- `examples/folio-demo/config/auth.php` — **new**: session + `ConfigUserProvider` admin.
- Tests: `packages/auth/tests/ArrayUserTest.php`, `packages/auth/tests/ConfigUserProviderTest.php` (new); `packages/core/tests/ConfigProviderBootTest.php` (new); `packages/folio/tests/Http/AdminAuthMiddlewareTest.php`, `packages/folio/tests/Http/AuthControllerTest.php`, `packages/folio/tests/Integration/FolioAuthTest.php` (new); `examples/folio-demo/tests/FolioDemoTest.php` (modify).

---

### Task 1: `preflow/auth` — `ArrayUser` + `ConfigUserProvider`

**Files:**
- Create: `packages/auth/src/ArrayUser.php`, `packages/auth/src/ConfigUserProvider.php`
- Test: `packages/auth/tests/ArrayUserTest.php`, `packages/auth/tests/ConfigUserProviderTest.php` (new)

**Interfaces:**
- Consumes: `Preflow\Auth\Authenticatable`, `Preflow\Auth\UserProviderInterface`.
- Produces: `ArrayUser` (`__construct(string $id, string $email, string $passwordHash, array $roles = [])`; `getAuthId()` returns `$id`, plus `getEmail()`, `getPasswordHash()`, `getRoles()`, `hasRole()`). `ConfigUserProvider::__construct(array $users)` where each `$user` is `['email'=>, 'password_hash'=>, 'roles'=>[]]`; `findByCredentials(['email'=>…])` and `findById($email)` return an `ArrayUser` (id = email) or null.

- [ ] **Step 1: Write the failing tests**

Create `packages/auth/tests/ArrayUserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;

final class ArrayUserTest extends TestCase
{
    public function test_exposes_id_email_hash_roles(): void
    {
        $u = new ArrayUser('a@b.test', 'a@b.test', 'HASH', ['admin', 'editor']);
        $this->assertSame('a@b.test', $u->getAuthId());
        $this->assertSame('a@b.test', $u->getEmail());
        $this->assertSame('HASH', $u->getPasswordHash());
        $this->assertSame(['admin', 'editor'], $u->getRoles());
        $this->assertTrue($u->hasRole('admin'));
        $this->assertFalse($u->hasRole('root'));
    }
}
```

Create `packages/auth/tests/ConfigUserProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\ConfigUserProvider;

final class ConfigUserProviderTest extends TestCase
{
    private function provider(): ConfigUserProvider
    {
        return new ConfigUserProvider([
            ['email' => 'admin@site.test', 'password_hash' => 'HASH1', 'roles' => ['admin']],
            ['email' => 'ed@site.test', 'password_hash' => 'HASH2'],
        ]);
    }

    public function test_find_by_credentials_matches_email(): void
    {
        $u = $this->provider()->findByCredentials(['email' => 'admin@site.test', 'password' => 'x']);
        $this->assertInstanceOf(ArrayUser::class, $u);
        $this->assertSame('admin@site.test', $u->getAuthId());
        $this->assertSame('HASH1', $u->getPasswordHash());
        $this->assertSame(['admin'], $u->getRoles());
    }

    public function test_find_by_id_matches_email_and_defaults_roles(): void
    {
        $u = $this->provider()->findById('ed@site.test');
        $this->assertInstanceOf(ArrayUser::class, $u);
        $this->assertSame([], $u->getRoles());
    }

    public function test_unknown_returns_null(): void
    {
        $this->assertNull($this->provider()->findByCredentials(['email' => 'nobody@site.test']));
        $this->assertNull($this->provider()->findByCredentials([]));
        $this->assertNull($this->provider()->findById('nobody@site.test'));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/auth/tests/ArrayUserTest.php packages/auth/tests/ConfigUserProviderTest.php`
Expected: FAIL — `Preflow\Auth\ArrayUser` / `ConfigUserProvider` do not exist.

- [ ] **Step 3: Create `ArrayUser`**

Create `packages/auth/src/ArrayUser.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Authenticatable backed by a plain config entry (no persistence). Used by
 * ConfigUserProvider for config-credential auth.
 */
final class ArrayUser implements Authenticatable
{
    /** @param string[] $roles */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly array $roles = [],
    ) {}

    public function getAuthId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
```

- [ ] **Step 4: Create `ConfigUserProvider`**

Create `packages/auth/src/ConfigUserProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * UserProvider over an in-config list of admins, each
 * ['email' => , 'password_hash' => , 'roles' => []]. The auth id is the email.
 * No password check here — SessionGuard verifies via PasswordHasherInterface.
 */
final class ConfigUserProvider implements UserProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $users;

    /** @param array<int, array<string, mixed>> $users */
    public function __construct(array $users)
    {
        $this->users = array_values($users);
    }

    public function findById(string $id): ?Authenticatable
    {
        foreach ($this->users as $user) {
            if ((string) ($user['email'] ?? '') === $id && $id !== '') {
                return $this->toUser($user);
            }
        }
        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = isset($credentials['email']) ? (string) $credentials['email'] : '';
        if ($email === '') {
            return null;
        }
        foreach ($this->users as $user) {
            if ((string) ($user['email'] ?? '') === $email) {
                return $this->toUser($user);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $user */
    private function toUser(array $user): ArrayUser
    {
        $email = (string) ($user['email'] ?? '');
        return new ArrayUser(
            $email,
            $email,
            (string) ($user['password_hash'] ?? ''),
            array_values(array_filter((array) ($user['roles'] ?? []), 'is_string')),
        );
    }
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `vendor/bin/phpunit packages/auth/tests/ArrayUserTest.php packages/auth/tests/ConfigUserProviderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the auth suite**

Run: `vendor/bin/phpunit packages/auth/tests`
Expected: PASS — no regression.

- [ ] **Step 7: Commit**

```bash
git add packages/auth/src/ArrayUser.php packages/auth/src/ConfigUserProvider.php packages/auth/tests/ArrayUserTest.php packages/auth/tests/ConfigUserProviderTest.php
git commit -m "feat(auth): ConfigUserProvider + ArrayUser for config-credential auth"
```

---

### Task 2: `bootAuth` constructs `ConfigUserProvider` + exposes the default provider

**Files:**
- Modify: `packages/core/src/Application.php` (`bootAuth`)
- Test: `packages/core/tests/ConfigProviderBootTest.php` (new)

**Interfaces:**
- Consumes: `ConfigUserProvider` (Task 1); the existing `bootAuth` provider/guard construction.
- Produces: when `config/auth.php` declares a provider `{class: ConfigUserProvider, users: [...]}`, `bootAuth` constructs it; and it binds the **default guard's provider** in the container under `Preflow\Auth\UserProviderInterface::class` (so controllers can fetch the user after `validate()`).

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/ConfigProviderBootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;

final class ConfigProviderBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfgboot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        // Auth config with a ConfigUserProvider and NO session block (so NativeSession
        // does not start — bootAuth still builds guards/provider/AuthManager).
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => ConfigUserProvider::class, 'users' => [
                ['email' => 'a@b.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/app.php');
        @unlink($this->dir . '/config/auth.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_config_provider_is_wired_and_validates(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertInstanceOf(ConfigUserProvider::class, $c->get(UserProviderInterface::class));

        $guard = $c->get(AuthManager::class)->guard('session');
        $this->assertTrue($guard->validate(['email' => 'a@b.test', 'password' => 'secret']));
        $this->assertFalse($guard->validate(['email' => 'a@b.test', 'password' => 'wrong']));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/core/tests/ConfigProviderBootTest.php`
Expected: FAIL — `bootAuth` skips the `ConfigUserProvider` (its loop only builds `DataManagerUserProvider`), so no provider/guard is built; `UserProviderInterface` is unbound.

- [ ] **Step 3: Add the `ConfigUserProvider` branch + provider binding in `bootAuth`**

In `packages/core/src/Application.php`, inside `bootAuth()`, in the `// User providers` loop, add a branch after the `DataManagerUserProvider` branch:

```php
            if ($providerClass === \Preflow\Auth\ConfigUserProvider::class) {
                $providers[$name] = new \Preflow\Auth\ConfigUserProvider($providerConfig['users'] ?? []);
            }
```

Then, immediately after the `$this->container->instance(\Preflow\Auth\AuthManager::class, $authManager);` line, bind the default guard's provider under the interface so controllers can resolve it:

```php
        $defaultProviderName = $authConfig['guards'][$defaultGuard]['provider'] ?? null;
        if ($defaultProviderName !== null && isset($providers[$defaultProviderName])) {
            $this->container->instance(\Preflow\Auth\UserProviderInterface::class, $providers[$defaultProviderName]);
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit packages/core/tests/ConfigProviderBootTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Run the core auth-related suites**

Run: `vendor/bin/phpunit packages/core/tests`
Expected: PASS — no regression (the new branch/binding only activate for a `ConfigUserProvider` entry).

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Application.php packages/core/tests/ConfigProviderBootTest.php
git commit -m "feat(core): bootAuth builds ConfigUserProvider + binds the default UserProvider"
```

---

### Task 3: Folio `AdminAuthMiddleware` + `AuthController`

**Files:**
- Create: `packages/folio/src/Http/AdminAuthMiddleware.php`, `packages/folio/src/Http/AuthController.php`
- Test: `packages/folio/tests/Http/AdminAuthMiddlewareTest.php`, `packages/folio/tests/Http/AuthControllerTest.php` (new)

**Interfaces:**
- Consumes: `Preflow\Auth\AuthManager` (`guard()`, `setUser()`), `Preflow\Auth\GuardInterface` (`user`, `validate`, `login`, `logout`), `Preflow\Auth\UserProviderInterface` (`findByCredentials`), `Preflow\Auth\Authenticatable`, `Preflow\Core\Http\Session\SessionInterface` (`flash`, `getFlash`), `Preflow\View\TemplateEngineInterface` (`render`), `Preflow\Core\Http\Csrf\CsrfToken`.
- Produces: `AdminAuthMiddleware::__construct(AuthManager $auth, string $prefix)` (PSR-15; 302 to `{prefix}/login` when no user, else set user + attribute + continue). `AuthController::__construct(AuthManager $auth, UserProviderInterface $users, TemplateEngineInterface $engine, string $prefix)` with `loginForm`, `login`, `logout` actions.

- [ ] **Step 1: Write the failing tests**

Create `packages/folio/tests/Http/AdminAuthMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Folio\Http\AdminAuthMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminAuthMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'OK');
            }
        };
    }

    private function guard(?Authenticatable $user): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private ?Authenticatable $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };
    }

    public function test_redirects_to_login_when_unauthenticated(): void
    {
        $am = new AuthManager(['session' => $this->guard(null)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio');
        $res = $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler());

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    public function test_passes_through_when_authenticated(): void
    {
        $user = new \Preflow\Auth\ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
        $am = new AuthManager(['session' => $this->guard($user)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio');
        $res = $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($user, $am->user());
    }
}
```

Create `packages/folio/tests/Http/AuthControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Folio\Http\AuthController;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AuthControllerTest extends TestCase
{
    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|action=' . ($context['login_action'] ?? '') . '|error=' . ($context['login_error'] ?? '');
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    /** @param GuardInterface $guard */
    private function authManager(GuardInterface $guard): AuthManager
    {
        return new AuthManager(['session' => $guard], 'session');
    }

    private function guard(bool $valid, bool &$loggedIn, ?\Preflow\Auth\Authenticatable $current = null): GuardInterface
    {
        return new class($valid, $loggedIn, $current) implements GuardInterface {
            public function __construct(private bool $valid, private bool &$loggedIn, private ?\Preflow\Auth\Authenticatable $current) {}
            public function user($request): ?\Preflow\Auth\Authenticatable { return $this->current; }
            public function validate(array $credentials): bool { return $this->valid; }
            public function login(\Preflow\Auth\Authenticatable $user, $request): void { $this->loggedIn = true; }
            public function logout($request): void {}
        };
    }

    private function provider(?ArrayUser $user): UserProviderInterface
    {
        return new class($user) implements UserProviderInterface {
            public function __construct(private ?ArrayUser $user) {}
            public function findById(string $id): ?\Preflow\Auth\Authenticatable { return $this->user; }
            public function findByCredentials(array $credentials): ?\Preflow\Auth\Authenticatable { return $this->user; }
        };
    }

    public function test_login_form_renders_with_action(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->loginForm((new Psr17Factory())->createServerRequest('GET', '/folio/login'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/login.twig', (string) $res->getBody());
        $this->assertStringContainsString('action=/folio/login', (string) $res->getBody());
    }

    public function test_login_form_redirects_when_already_authenticated(): void
    {
        $loggedIn = false;
        $current = new ArrayUser('a@b.test', 'a@b.test', 'H', []);
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn, $current)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->loginForm((new Psr17Factory())->createServerRequest('GET', '/folio/login'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio', $res->getHeaderLine('Location'));
    }

    public function test_login_success_logs_in_and_redirects_to_dashboard(): void
    {
        $loggedIn = false;
        $user = new ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
        $ctrl = new AuthController($this->authManager($this->guard(true, $loggedIn)), $this->provider($user), $this->engine(), '/folio');
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/login')
            ->withParsedBody(['email' => 'a@b.test', 'password' => 'secret']);
        $res = $ctrl->login($req);

        $this->assertTrue($loggedIn);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio', $res->getHeaderLine('Location'));
    }

    public function test_login_failure_flashes_and_redirects_to_login(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('flash')->with('error', $this->stringContains('Invalid'));
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/login')
            ->withParsedBody(['email' => 'a@b.test', 'password' => 'bad'])
            ->withAttribute(SessionInterface::class, $session);
        $res = $ctrl->login($req);

        $this->assertFalse($loggedIn);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    public function test_logout_redirects_to_login(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->logout((new Psr17Factory())->createServerRequest('POST', '/folio/logout'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminAuthMiddlewareTest.php packages/folio/tests/Http/AuthControllerTest.php`
Expected: FAIL — the two classes do not exist.

- [ ] **Step 3: Create `AdminAuthMiddleware`**

Create `packages/folio/src/Http/AdminAuthMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates the Folio admin: unauthenticated requests are redirected (302) to the
 * login page instead of throwing 401 (the framework AuthMiddleware behavior).
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly string $prefix,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->guard()->user($request);
        if ($user === null) {
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        $this->auth->setUser($user);
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Create `AuthController`**

Create `packages/folio/src/Http/AuthController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Folio admin login/logout. Validates config credentials through the session
 * guard (post-Redirect-Get flow), rendering the orphaned login.twig.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly UserProviderInterface $users,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->guard()->user($request) !== null) {
            return new Response(302, ['Location' => $this->prefix]);
        }

        $session = $request->getAttribute(SessionInterface::class);
        $error = $session instanceof SessionInterface ? $session->getFlash('error') : null;

        $html = $this->engine->render('@folio/admin/login.twig', [
            'login_action' => $this->prefix . '/login',
            'login_error' => is_string($error) ? $error : '',
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $session = $request->getAttribute(SessionInterface::class);
        $guard = $this->auth->guard();

        if (!$guard->validate(['email' => $email, 'password' => $password])) {
            if ($session instanceof SessionInterface) {
                $session->flash('error', 'Invalid email or password.');
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }

        $user = $this->users->findByCredentials(['email' => $email]);
        if ($user === null) {
            if ($session instanceof SessionInterface) {
                $session->flash('error', 'Invalid email or password.');
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }

        $guard->login($user, $request);
        return new Response(302, ['Location' => $this->prefix]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->guard()->logout($request);
        return new Response(302, ['Location' => $this->prefix . '/login']);
    }
}
```

(The `CsrfToken` import is used by the login template via `csrf_field()`, not read here; keep the `use` only if referenced — remove it if the implementer's static analysis flags it unused. The login form's CSRF field is added in Task 4.)

- [ ] **Step 5: Run to verify they pass**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminAuthMiddlewareTest.php packages/folio/tests/Http/AuthControllerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Http/AdminAuthMiddleware.php packages/folio/src/Http/AuthController.php packages/folio/tests/Http/AdminAuthMiddlewareTest.php packages/folio/tests/Http/AuthControllerTest.php
git commit -m "feat(folio): AdminAuthMiddleware (redirect gate) + AuthController (login/logout)"
```

(If Step 4's `use Preflow\Core\Http\Csrf\CsrfToken;` is unused, drop that line before committing.)

---

### Task 4: Wire gating + login routes + templates

**Files:**
- Modify: `packages/folio/src/Routing/FolioRoutes.php`, `packages/folio/src/FolioServiceProvider.php`, `packages/folio/composer.json`, `packages/folio/templates/admin/login.twig`, `packages/folio/templates/admin/_layout.twig`
- Test: `packages/folio/tests/Integration/FolioAuthTest.php` (new)

**Interfaces:**
- Consumes: `AdminAuthMiddleware`, `AuthController` (Task 3); `bootAuth`'s `AuthManager` + `UserProviderInterface` bindings (Task 2).
- Produces: `FolioRoutes::admin(string $prefix, bool $authEnabled = false)` — when `$authEnabled`, adds `GET/POST {prefix}/login` + `POST {prefix}/logout` (handled by `AuthController`, before the `{type}` routes) and attaches `[AdminAuthMiddleware::class]` to every non-`AuthController` admin route; assets/uploads stay `middleware: []`. `FolioServiceProvider` binds `AdminAuthMiddleware`/`AuthController`, computes `authEnabled = file_exists(config/auth.php)`, passes it to the routes, and registers a `folio_auth_enabled` Twig global when enabled.

- [ ] **Step 1: Add the failing integration tests**

Create `packages/folio/tests/Integration/FolioAuthTest.php`. These boot the full app WITH `config/auth.php`, so each test that touches session runs in a separate process:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

final class FolioAuthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_auth_' . bin2hex(random_bytes(4));
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        return $app;
    }

    /**
     * @runInSeparateProcess
     */
    public function test_unauthenticated_admin_redirects_to_login(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio'));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_page_is_public_and_has_csrf_field(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login'));
        $body = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('name="email"', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_assets_are_public_while_unauthenticated(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));
        $this->assertSame(200, $res->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_then_reach_dashboard_then_logout(): void
    {
        $app = $this->app();
        $f = new Psr17Factory();

        // Fetch the login page to obtain the CSRF token.
        $loginPage = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage, $m);
        $token = $m[1] ?? '';
        $this->assertNotSame('', $token);

        // Submit correct credentials.
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
        $this->assertSame('/folio', $login->getHeaderLine('Location'));

        // Now the dashboard is reachable (session persists in-process).
        $dash = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame(200, $dash->getStatusCode());

        // Logout, then the admin redirects again.
        $out = $app->handle($f->createServerRequest('POST', '/folio/logout')->withParsedBody(['_csrf_token' => $token]));
        $this->assertSame(302, $out->getStatusCode());
        $this->assertSame('/folio/login', $out->getHeaderLine('Location'));
    }

    private function scaffold(): void
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };

        $w('config/app.php', "<?php\nreturn ['name'=>'Auth Test','debug'=>1,'engine'=>'twig','key'=>'auth-test-key'];\n");
        $w('config/data.php', "<?php\nreturn ['drivers'=>['json'=>['driver'=>\\Preflow\\Data\\Driver\\JsonFileDriver::class,'path'=>__DIR__.'/../storage/data']],'default'=>'json','models_path'=>__DIR__.'/models'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path'=>'/folio'];\n");
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
            'session' => ['lifetime' => 7200, 'cookie' => 'folio_auth_test', 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
        ], true) . ";\n");
        $w('config/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']], 'slug' => ['type' => 'string', 'validate' => ['required']], 'status' => ['type' => 'string']],
        ], JSON_PRETTY_PRINT));
        $w('app/pages/index.twig', 'HOME');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAuthTest.php`
Expected: FAIL — no login routes / gating yet (unauthenticated `/folio` returns 200, no `/folio/login`).

- [ ] **Step 3: Require `preflow/auth` in Folio's composer**

In `packages/folio/composer.json`, add to the `require` block (after `"preflow/validation"`):

```json
        "preflow/auth": "^0.1 || @dev",
```

- [ ] **Step 4: Add login/logout routes + gating in `FolioRoutes`**

In `packages/folio/src/Routing/FolioRoutes.php`:

(a) Add class constants beside `PREVIEW`:

```php
    private const AUTH = 'Preflow\\Folio\\Http\\AuthController';
    private const AUTH_MW = 'Preflow\\Folio\\Http\\AdminAuthMiddleware';
```

(b) Change the signature to `public static function admin(string $prefix, bool $authEnabled = false): array` and replace the `$defs` array + the entry-building loop with:

```php
        $defs = [
            ['GET', $prefix, 'index'],
        ];
        if ($authEnabled) {
            $defs[] = ['GET',  $prefix . '/login',  'loginForm', self::AUTH];
            $defs[] = ['POST', $prefix . '/login',  'login',     self::AUTH];
            $defs[] = ['POST', $prefix . '/logout', 'logout',    self::AUTH];
        }
        $defs = array_merge($defs, [
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}/preview',      'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['GET',  $prefix . '/{type}/{id}/label',   'recordLabel'],
            ['POST', $prefix . '/{type}/{id}/preview', 'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
        ]);

        $entries = [];
        foreach ($defs as $def) {
            [$method, $pattern, $action] = $def;
            $handlerClass = $def[3] ?? self::ADMIN;
            $middleware = ($authEnabled && $handlerClass !== self::AUTH) ? [self::AUTH_MW] : [];
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $handlerClass . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                middleware: $middleware,
                paramNames: $c['paramNames'],
                regex: $c['regex'],
                isCatchAll: $c['isCatchAll'],
            );
        }
```

(The asset + upload `RouteEntry` blocks below stay unchanged — `middleware: []`, always public.)

- [ ] **Step 5: Bind the classes + pass `authEnabled` + register the Twig global**

In `packages/folio/src/FolioServiceProvider.php`:

(a) Add imports (near the other `Http` imports):

```php
use Preflow\Folio\Http\AdminAuthMiddleware;
use Preflow\Folio\Http\AuthController;
```

(b) In `register()`, after the `UploadController` bind, add:

```php
        $container->bind(AdminAuthMiddleware::class, fn (Container $c) => new AdminAuthMiddleware(
            $c->get(\Preflow\Auth\AuthManager::class),
            $prefix,
        ));
        $container->bind(AuthController::class, fn (Container $c) => new AuthController(
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(\Preflow\Auth\UserProviderInterface::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
        ));
```

(c) In `boot()`, compute `authEnabled` and pass it to the routes. Replace the `$collection->addMany(FolioRoutes::admin($this->prefix($app)));` line with:

```php
                $authEnabled = file_exists($app->basePath('config/auth.php'));
                $collection->addMany(FolioRoutes::admin($this->prefix($app), $authEnabled));
```

(d) In `boot()`, inside the `if ($container->has(TemplateEngineInterface::class))` block (right after the `folio_asset` function is added), register the auth-enabled global so `_layout.twig` can guard its auth UI without calling `auth_check()` when auth is off:

```php
            if (file_exists($app->basePath('config/auth.php'))) {
                $engine->addGlobal('folio_auth_enabled', true);
            }
```

- [ ] **Step 6: Add the CSRF field to the login form**

In `packages/folio/templates/admin/login.twig`, add the CSRF field as the first child of the form (right after the `<form ...>` open tag / before `<div class="folio-brand">`):

```twig
        {{ csrf_field()|raw }}
```

- [ ] **Step 7: Show the current user + logout in the layout**

In `packages/folio/templates/admin/_layout.twig`, replace the actions line
`<div class="folio-actions">{% block actions %}{% endblock %}</div>` with:

```twig
            <div class="folio-actions">
                {% block actions %}{% endblock %}
                {% if folio_auth_enabled|default(false) %}
                    {% if auth_check() %}
                        <span class="folio-user">{{ auth_user().email }}</span>
                        <form method="post" action="{{ prefix }}/logout" class="folio-logout">{{ csrf_field()|raw }}<button type="submit" class="btn btn-secondary">Log out</button></form>
                    {% endif %}
                {% endif %}
            </div>
```

(The nested `{% if %}` guarantees `auth_check()`/`auth_user()` are only called when auth booted — they are unregistered otherwise.)

- [ ] **Step 8: Run the integration + Folio suites**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAuthTest.php`
Expected: PASS (4 tests) — unauth redirect, public login page with CSRF field, public assets, and the login→dashboard→logout round-trip.
Then run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — the existing 35 tests are unaffected (their scaffold has no `config/auth.php`, so the admin stays ungated).

- [ ] **Step 9: Commit**

```bash
git add packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/composer.json packages/folio/templates/admin/login.twig packages/folio/templates/admin/_layout.twig packages/folio/tests/Integration/FolioAuthTest.php
git commit -m "feat(folio): gate admin behind login when auth is configured"
```

---

### Task 5: Demo wiring + full-suite verification

**Files:**
- Create: `examples/folio-demo/config/auth.php`
- Modify: `examples/folio-demo/tests/FolioDemoTest.php`
- Test: whole repo suite

**Interfaces:**
- Consumes: the full auth pass (Tasks 1-4).

- [ ] **Step 1: Update the demo smoke test for the gated admin**

The demo will now boot auth, so `/folio` redirects to login. In `examples/folio-demo/tests/FolioDemoTest.php`, add `@runInSeparateProcess` to each test (the app now starts `NativeSession`), and update expectations: the dashboard redirects to login; assets stay public; the login page renders. Replace the two existing test methods with:

```php
    /**
     * @runInSeparateProcess
     */
    public function test_admin_redirects_to_login_when_unauthenticated(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_page_renders(): void
    {
        $res = $this->get('/folio/login');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="email"', (string) $res->getBody());
    }

    /**
     * @runInSeparateProcess
     */
    public function test_stylesheet_route_serves_css(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
    }
```

(Keep the existing `get()` helper + `setUp`/`tearDown`. If `get()` builds the app once in `setUp`, that is fine under separate processes — each test rebuilds it.)

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit examples/folio-demo/tests/FolioDemoTest.php`
Expected: FAIL — no `config/auth.php` in the demo yet, so `/folio` still returns 200 (not the 302 the updated test asserts) and `/folio/login` 404s.

- [ ] **Step 3: Add the demo auth config**

Create `examples/folio-demo/config/auth.php`:

```php
<?php

return [
    'default_guard' => 'session',

    'guards' => [
        'session' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'config'],
    ],

    'providers' => [
        'config' => [
            'class' => Preflow\Auth\ConfigUserProvider::class,
            'users' => [
                [
                    'email' => 'admin@folio.test',
                    // Hash of the plaintext password below, recomputed each boot
                    // (demo-only; a real app stores a fixed hash).
                    'password_hash' => password_hash(getenv('FOLIO_ADMIN_PASSWORD') ?: 'folio-admin', PASSWORD_DEFAULT),
                    'roles' => ['admin'],
                ],
            ],
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        'lifetime' => 7200,
        'cookie' => 'folio_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
```

- [ ] **Step 4: Surface the demo login**

In `examples/folio-demo/seed.php`, after the final "Seeded …" output line, echo the demo login so it is discoverable. Add:

```php
echo "Admin login: admin@folio.test / " . (getenv('FOLIO_ADMIN_PASSWORD') ?: 'folio-admin') . " (set FOLIO_ADMIN_PASSWORD to override)\n";
```

- [ ] **Step 5: Run the demo smoke test**

Run: `vendor/bin/phpunit examples/folio-demo/tests/FolioDemoTest.php`
Expected: PASS (3 tests) — the admin redirects to login, the login page renders, assets stay public.

- [ ] **Step 6: Run the full repo suite**

Run: `vendor/bin/phpunit` (NO `APP_DEBUG` exported)
Expected: PASS — all suites green; only the pre-existing PHPUnit deprecations + 1 skip. No failures. (The auth-booting tests are marked `@runInSeparateProcess`, so native sessions do not pollute other tests.)

- [ ] **Step 7: Commit**

```bash
git add examples/folio-demo/config/auth.php examples/folio-demo/seed.php examples/folio-demo/tests/FolioDemoTest.php
git commit -m "docs(folio): gate the demo admin behind login (config admin)"
```

---

## Self-Review

**Spec coverage:**
- `ArrayUser` + `ConfigUserProvider` in `preflow/auth` → Task 1.
- `bootAuth` builds `ConfigUserProvider` + exposes the default provider → Task 2.
- `AdminAuthMiddleware` (redirect) + `AuthController` (login/logout) → Task 3.
- Route gating (all admin + preview; assets/uploads + login public; login before `{type}`), conditional on `config/auth.php`; composer dep; login CSRF field; `_layout` user/logout with the `folio_auth_enabled` guard → Task 4.
- Demo `config/auth.php` + default admin + smoke-test update → Task 5.
- CSRF cross-cutting: verified by the `FolioAuthTest` login round-trip (submits `_csrf_token`) and the login form's CSRF field; existing ungated `FolioAppTest` unaffected.
- Backward-compat (no `config/auth.php` → ungated): the `authEnabled` gate (Task 4) + existing `FolioAppTest` staying green (Task 4 Step 8).
- Native-session CLI hostility → `@runInSeparateProcess` on every auth-booting integration/demo test (Tasks 4-5); pure-logic tests use fakes/mocks (Tasks 1-3).
- Out of scope held: DB users, management UI, roles enforcement, registration, reset, remember-me.

**Placeholder scan:** No `TBD`/`TODO`. Every code step shows full code; every modify step quotes the exact anchor. Task 3 Step 4 notes the possibly-unused `CsrfToken` import to drop.

**Type consistency:**
- `ArrayUser::__construct(string $id, string $email, string $passwordHash, array $roles = [])` — Task 1; constructed by `ConfigUserProvider::toUser` (Task 1) and referenced in tests (Tasks 1, 3).
- `ConfigUserProvider::__construct(array $users)` — Task 1; constructed by `bootAuth` (Task 2) from `$providerConfig['users']`, and in the demo/integration configs (Tasks 4-5).
- `bootAuth` binds `UserProviderInterface::class` (Task 2), consumed by `AuthController` (Task 3) via the provider `findByCredentials`, wired in the provider bind (Task 4 Step 5b).
- `AdminAuthMiddleware::__construct(AuthManager, string $prefix)` + `AuthController::__construct(AuthManager, UserProviderInterface, TemplateEngineInterface, string $prefix)` — Task 3; bound identically in Task 4 Step 5b.
- `FolioRoutes::admin(string $prefix, bool $authEnabled = false)` — Task 4; called with `$authEnabled` in `FolioServiceProvider::boot` (Task 4 Step 5c). Constants `AUTH`/`AUTH_MW` gate handler/middleware selection.
- `folio_auth_enabled` global (Task 4 Step 5d) guards the `_layout.twig` auth block (Task 4 Step 7).
- Login form `name="_csrf_token"` (via `csrf_field()`, Task 4 Step 6) asserted by `FolioAuthTest` (Task 4 Step 1).
```
