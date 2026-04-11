# Auth System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add session management, CSRF protection, and a pluggable authentication system to Preflow.

**Architecture:** Session + CSRF go in `preflow/core` (general infrastructure). Auth package (`preflow/auth`) provides guard system, password hashing, middleware, user provider. Skeleton ships User model, config, and login/register pages. Route-level middleware execution gets wired up in the Kernel.

**Tech Stack:** PHP 8.4+, PSR-7/PSR-15, PHPUnit 11

**Design spec:** `docs/superpowers/specs/2026-04-12-auth-system-design.md`

**Design deviations:**
- No `PasswordHashTransformer` — `ModelMetadata` instantiates transformers with `new $class()` (no DI). Password hashing is explicit via `PasswordHasherInterface` in controllers. This is more secure (explicit > magic for passwords).
- AuthMiddleware doesn't accept guard/role options — the `#[Middleware]` attribute takes class names only (`string ...`). Role checks happen in application code.
- Route-level middleware execution added to Kernel (was defined on Route but never executed).

---

## File Structure

### New: `packages/auth/`

```
packages/auth/
├── composer.json
├── src/
│   ├── Authenticatable.php         — Interface: getAuthId, getPasswordHash, getRoles, hasRole
│   ├── AuthenticatableTrait.php    — Default implementations assuming uuid/passwordHash/roles properties
│   ├── AuthExtensionProvider.php   — Template functions: auth_user(), auth_check()
│   ├── AuthManager.php             — Resolves guards by name from config
│   ├── DataManagerUserProvider.php — Finds users via DataManager
│   ├── GuardInterface.php          — Contract: user, validate, login, logout
│   ├── NativePasswordHasher.php    — Wraps password_hash/verify/needs_rehash
│   ├── PasswordHasherInterface.php — Contract: hash, verify, needsRehash
│   ├── PersonalAccessToken.php     — Model for API token storage
│   ├── SessionGuard.php            — Session-based auth (browser requests)
│   ├── TokenGuard.php              — Bearer token auth (API requests)
│   ├── UserProviderInterface.php   — Contract: findById, findByCredentials
│   └── Http/
│       ├── AuthMiddleware.php      — PSR-15: requires authentication, attaches user to request
│       └── GuestMiddleware.php     — PSR-15: redirects authenticated users away
└── tests/
    ├── Fixtures/
    │   └── TestUser.php
    ├── NativePasswordHasherTest.php
    ├── SessionGuardTest.php
    ├── TokenGuardTest.php
    ├── DataManagerUserProviderTest.php
    ├── AuthManagerTest.php
    ├── AuthExtensionProviderTest.php
    └── Http/
        ├── AuthMiddlewareTest.php
        └── GuestMiddlewareTest.php
```

### Added to `packages/core/`

```
packages/core/src/Http/Session/
├── SessionInterface.php    — Contract: start, get, set, flash, regenerate, invalidate
├── NativeSession.php       — Wraps $_SESSION with config-driven cookie options
└── SessionMiddleware.php   — PSR-15: starts session, ages flash, attaches to request

packages/core/src/Http/Csrf/
├── CsrfToken.php           — Value object: generate from session, getValue
└── CsrfMiddleware.php      — PSR-15: validates CSRF on state-changing methods

packages/core/tests/Http/Session/
├── NativeSessionTest.php
└── SessionMiddlewareTest.php

packages/core/tests/Http/Csrf/
└── CsrfMiddlewareTest.php
```

### Added to `packages/testing/`

```
packages/testing/src/
└── ArraySession.php  — In-memory SessionInterface for tests
```

### Modified files

```
packages/core/src/Application.php   — Add bootSession(), bootCsrf(), bootAuth()
packages/core/src/Kernel.php        — Execute route-level middleware
composer.json (root)                — Add packages/auth repository + require-dev
.github/workflows/split.yml        — Add auth to split matrix
packages/testing/composer.json      — Add preflow/auth to require
```

### Skeleton additions

```
packages/skeleton/config/auth.php
packages/skeleton/app/Models/User.php
packages/skeleton/app/pages/login.twig
packages/skeleton/app/pages/login.php
packages/skeleton/app/pages/register.twig
packages/skeleton/app/pages/register.php
packages/skeleton/app/pages/logout.php
packages/skeleton/migrations/001_create_users.sql
packages/skeleton/migrations/002_create_user_tokens.sql
```

---

### Task 1: Package scaffolding + monorepo wiring

**Files:**
- Create: `packages/auth/composer.json`
- Create: `packages/auth/src/.gitkeep`
- Create: `packages/auth/tests/.gitkeep`
- Modify: `composer.json` (root)
- Modify: `.github/workflows/split.yml`

- [ ] **Step 1: Create auth package directory and composer.json**

```json
{
    "name": "preflow/auth",
    "description": "Preflow auth — pluggable guards, session auth, API tokens, password hashing",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.1 || @dev",
        "preflow/data": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "nyholm/psr7": "^1.8"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Auth\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Auth\\Tests\\": "tests/"
        }
    }
}
```

Create empty `.gitkeep` files in `src/` and `tests/`.

- [ ] **Step 2: Add auth to root composer.json**

Add to `repositories` array:
```json
{
    "type": "path",
    "url": "packages/auth",
    "options": { "symlink": true }
}
```

Add to `require-dev`:
```json
"preflow/auth": "@dev"
```

- [ ] **Step 3: Add auth to monorepo split workflow**

In `.github/workflows/split.yml`, add to `matrix.package`:
```yaml
- { local: 'packages/auth', remote: 'auth' }
```

- [ ] **Step 4: Run composer update**

Run: `composer update preflow/auth`
Expected: Package symlinked successfully.

- [ ] **Step 5: Commit**

```bash
git add packages/auth/ composer.json composer.lock .github/workflows/split.yml
git commit -m "feat(auth): scaffold auth package and wire into monorepo"
```

---

### Task 2: Session system (core)

**Files:**
- Create: `packages/core/src/Http/Session/SessionInterface.php`
- Create: `packages/core/src/Http/Session/NativeSession.php`
- Create: `packages/testing/src/ArraySession.php`
- Create: `packages/core/tests/Http/Session/NativeSessionTest.php`
- Create: `packages/core/src/Http/Session/SessionMiddleware.php`
- Create: `packages/core/tests/Http/Session/SessionMiddlewareTest.php`

- [ ] **Step 1: Write SessionInterface**

Create `packages/core/src/Http/Session/SessionInterface.php`:

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 2: Write ArraySession test helper**

Create `packages/testing/src/ArraySession.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Core\Http\Session\SessionInterface;

final class ArraySession implements SessionInterface
{
    private array $data = [];
    private array $flashCurrent = [];
    private array $flashPrevious = [];
    private bool $started = false;
    private string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->flashCurrent = [];
        $this->flashPrevious = [];
        $this->id = bin2hex(random_bytes(16));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->flashCurrent[$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashPrevious[$key] ?? $this->flashCurrent[$key] ?? $default;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Simulate request boundary — age flash data.
     * Called by SessionMiddleware between requests.
     */
    public function ageFlash(): void
    {
        $this->flashPrevious = $this->flashCurrent;
        $this->flashCurrent = [];
    }
}
```

- [ ] **Step 3: Write NativeSession tests**

Create `packages/core/tests/Http/Session/NativeSessionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Session\NativeSession;

final class NativeSessionTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function test_start_and_get_set(): void
    {
        $session = new NativeSession(['cookie' => 'test_sess']);
        $session->start();

        $this->assertTrue($session->isStarted());
        $this->assertNotEmpty($session->getId());

        $session->set('name', 'Preflow');
        $this->assertSame('Preflow', $session->get('name'));
        $this->assertTrue($session->has('name'));
        $this->assertNull($session->get('missing'));
        $this->assertSame('fallback', $session->get('missing', 'fallback'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_remove_key(): void
    {
        $session = new NativeSession([]);
        $session->start();
        $session->set('key', 'value');
        $session->remove('key');
        $this->assertFalse($session->has('key'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_regenerate_changes_id(): void
    {
        $session = new NativeSession([]);
        $session->start();
        $session->set('keep', 'this');
        $oldId = $session->getId();
        $session->regenerate();
        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame('this', $session->get('keep'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_invalidate_clears_data_and_changes_id(): void
    {
        $session = new NativeSession([]);
        $session->start();
        $session->set('gone', 'data');
        $oldId = $session->getId();
        $session->invalidate();
        $this->assertNotSame($oldId, $session->getId());
        $this->assertNull($session->get('gone'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_flash_data(): void
    {
        $session = new NativeSession([]);
        $session->start();
        $session->flash('message', 'Hello');
        $this->assertSame('Hello', $session->getFlash('message'));
    }
}
```

- [ ] **Step 4: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Session/NativeSessionTest.php`
Expected: FAIL — `NativeSession` class not found.

- [ ] **Step 5: Implement NativeSession**

Create `packages/core/src/Http/Session/NativeSession.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

final class NativeSession implements SessionInterface
{
    private bool $started = false;

    public function __construct(
        private readonly array $options = [],
    ) {}

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $cookieParams = [
            'lifetime' => $this->options['lifetime'] ?? 0,
            'path' => $this->options['path'] ?? '/',
            'domain' => $this->options['domain'] ?? '',
            'secure' => $this->options['secure'] ?? true,
            'httponly' => $this->options['httponly'] ?? true,
            'samesite' => $this->options['samesite'] ?? 'Lax',
        ];

        $name = $this->options['cookie'] ?? 'preflow_session';

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params($cookieParams);
            session_name($name);
            session_start();
        }

        $this->started = true;
    }

    public function getId(): string
    {
        return session_id() ?: '';
    }

    public function regenerate(): void
    {
        if ($this->started) {
            session_regenerate_id(true);
        }
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        if ($this->started) {
            session_regenerate_id(true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash']['current'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['previous'][$key]
            ?? $_SESSION['_flash']['current'][$key]
            ?? $default;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Age flash data — called by SessionMiddleware between requests.
     * Moves current flash to previous, clears previous.
     */
    public function ageFlash(): void
    {
        $previous = $_SESSION['_flash']['current'] ?? [];
        $_SESSION['_flash'] = ['current' => [], 'previous' => $previous];
    }
}
```

- [ ] **Step 6: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Session/NativeSessionTest.php`
Expected: 5 tests, all PASS.

- [ ] **Step 7: Write SessionMiddleware tests**

Create `packages/core/tests/Http/Session/SessionMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\Testing\ArraySession;

final class SessionMiddlewareTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    public function test_attaches_session_to_request(): void
    {
        $session = new ArraySession();
        $mw = new SessionMiddleware($session);

        $captured = null;
        $mw->process($this->createRequest(), $this->createHandler($captured));

        $this->assertInstanceOf(SessionInterface::class, $captured->getAttribute(SessionInterface::class));
    }

    public function test_starts_session(): void
    {
        $session = new ArraySession();
        $mw = new SessionMiddleware($session);

        $mw->process($this->createRequest(), $this->createHandler());

        $this->assertTrue($session->isStarted());
    }

    public function test_ages_flash_data(): void
    {
        $session = new ArraySession();
        $session->start();
        $session->flash('msg', 'hello');

        $mw = new SessionMiddleware($session);

        // First request — flash is current, after aging it becomes previous
        $mw->process($this->createRequest(), $this->createHandler());
        $this->assertSame('hello', $session->getFlash('msg'));

        // Second request — previous flash is cleared
        $mw->process($this->createRequest(), $this->createHandler());
        $this->assertNull($session->getFlash('msg'));
    }
}
```

- [ ] **Step 8: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Session/SessionMiddlewareTest.php`
Expected: FAIL — `SessionMiddleware` class not found.

- [ ] **Step 9: Implement SessionMiddleware**

Create `packages/core/src/Http/Session/SessionMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();
        $this->session->ageFlash();

        $request = $request->withAttribute(SessionInterface::class, $this->session);

        return $handler->handle($request);
    }
}
```

Note: `ageFlash()` is on both `NativeSession` and `ArraySession` but not on `SessionInterface`. Add it to the interface:

Add to `SessionInterface.php`:
```php
public function ageFlash(): void;
```

- [ ] **Step 10: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Session/`
Expected: All tests PASS.

- [ ] **Step 11: Commit**

```bash
git add packages/core/src/Http/Session/ packages/core/tests/Http/Session/ packages/testing/src/ArraySession.php
git commit -m "feat(core): add session system — SessionInterface, NativeSession, SessionMiddleware"
```

---

### Task 3: CSRF protection (core)

**Files:**
- Create: `packages/core/src/Http/Csrf/CsrfToken.php`
- Create: `packages/core/src/Http/Csrf/CsrfMiddleware.php`
- Create: `packages/core/tests/Http/Csrf/CsrfMiddlewareTest.php`

- [ ] **Step 1: Write CsrfToken**

Create `packages/core/src/Http/Csrf/CsrfToken.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Http\Session\SessionInterface;

final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    private function __construct(
        private readonly string $value,
    ) {}

    public static function generate(SessionInterface $session): self
    {
        if (!$session->has(self::SESSION_KEY)) {
            $session->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
        }

        return new self($session->get(self::SESSION_KEY));
    }

    public static function fromSession(SessionInterface $session): ?self
    {
        $value = $session->get(self::SESSION_KEY);
        return $value !== null ? new self($value) : null;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 2: Write CsrfMiddleware tests**

Create `packages/core/tests/Http/Csrf/CsrfMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Http\Csrf\CsrfMiddleware;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Testing\ArraySession;

final class CsrfMiddlewareTest extends TestCase
{
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->session->start();
    }

    private function createRequest(string $method, string $uri = '/', array $body = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        $request = $request->withAttribute(SessionInterface::class, $this->session);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function test_get_request_generates_token_and_attaches_to_request(): void
    {
        $mw = new CsrfMiddleware();
        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };

        $mw->process($this->createRequest('GET'), $handler);

        $token = $captured->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertNotEmpty($token->getValue());
    }

    public function test_post_with_valid_token_passes(): void
    {
        $token = CsrfToken::generate($this->session);
        $request = $this->createRequest('POST', '/', ['_csrf_token' => $token->getValue()]);

        $mw = new CsrfMiddleware();
        $response = $mw->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_missing_token_throws_403(): void
    {
        CsrfToken::generate($this->session);
        $request = $this->createRequest('POST');

        $mw = new CsrfMiddleware();

        $this->expectException(\Preflow\Core\Exceptions\ForbiddenHttpException::class);
        $mw->process($request, $this->createHandler());
    }

    public function test_post_with_invalid_token_throws_403(): void
    {
        CsrfToken::generate($this->session);
        $request = $this->createRequest('POST', '/', ['_csrf_token' => 'wrong-token']);

        $mw = new CsrfMiddleware();

        $this->expectException(\Preflow\Core\Exceptions\ForbiddenHttpException::class);
        $mw->process($request, $this->createHandler());
    }

    public function test_post_with_header_token_passes(): void
    {
        $token = CsrfToken::generate($this->session);
        $request = $this->createRequest('POST')
            ->withHeader('X-CSRF-Token', $token->getValue());

        $mw = new CsrfMiddleware();
        $response = $mw->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_exempt_paths_are_skipped(): void
    {
        CsrfToken::generate($this->session);
        $request = $this->createRequest('POST', '/--component/action');

        $mw = new CsrfMiddleware(exempt: ['/--component/']);
        $response = $mw->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }
}
```

- [ ] **Step 3: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Csrf/CsrfMiddlewareTest.php`
Expected: FAIL — `CsrfMiddleware` class not found.

- [ ] **Step 4: Implement CsrfMiddleware**

Create `packages/core/src/Http/Csrf/CsrfMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Session\SessionInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param string[] $exempt Path prefixes to skip CSRF validation
     */
    public function __construct(
        private readonly array $exempt = ['/--component/'],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return $handler->handle($request);
        }

        $token = CsrfToken::generate($session);
        $request = $request->withAttribute(CsrfToken::class, $token);

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if ($this->isExempt($request)) {
            return $handler->handle($request);
        }

        $submitted = $this->getSubmittedToken($request);

        if ($submitted === null || !hash_equals($token->getValue(), $submitted)) {
            throw new ForbiddenHttpException('CSRF token mismatch.');
        }

        return $handler->handle($request);
    }

    private function getSubmittedToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['_csrf_token'])) {
            return $body['_csrf_token'];
        }

        $header = $request->getHeaderLine('X-CSRF-Token');
        return $header !== '' ? $header : null;
    }

    private function isExempt(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        foreach ($this->exempt as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/core/tests/Http/Csrf/CsrfMiddlewareTest.php`
Expected: 6 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Http/Csrf/ packages/core/tests/Http/Csrf/
git commit -m "feat(core): add CSRF protection — CsrfToken, CsrfMiddleware"
```

---

### Task 4: Password hashing (auth)

**Files:**
- Create: `packages/auth/src/PasswordHasherInterface.php`
- Create: `packages/auth/src/NativePasswordHasher.php`
- Create: `packages/auth/tests/NativePasswordHasherTest.php`

- [ ] **Step 1: Write PasswordHasherInterface**

Create `packages/auth/src/PasswordHasherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

interface PasswordHasherInterface
{
    public function hash(string $password): string;
    public function verify(string $password, string $hash): bool;
    public function needsRehash(string $hash): bool;
}
```

- [ ] **Step 2: Write NativePasswordHasher tests**

Create `packages/auth/tests/NativePasswordHasherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\NativePasswordHasher;

final class NativePasswordHasherTest extends TestCase
{
    public function test_hash_produces_verifiable_hash(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('secret123');

        $this->assertNotSame('secret123', $hash);
        $this->assertTrue($hasher->verify('secret123', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }

    public function test_needs_rehash_returns_false_for_current_hash(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('password');

        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function test_needs_rehash_returns_true_for_outdated_hash(): void
    {
        $hasher = new NativePasswordHasher(options: ['cost' => 12]);
        $cheapHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue($hasher->needsRehash($cheapHash));
    }
}
```

- [ ] **Step 3: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/NativePasswordHasherTest.php`
Expected: FAIL — `NativePasswordHasher` class not found.

- [ ] **Step 4: Implement NativePasswordHasher**

Create `packages/auth/src/NativePasswordHasher.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class NativePasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private readonly string|int|null $algorithm = PASSWORD_DEFAULT,
        private readonly array $options = [],
    ) {}

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/NativePasswordHasherTest.php`
Expected: 3 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/auth/src/PasswordHasherInterface.php packages/auth/src/NativePasswordHasher.php packages/auth/tests/NativePasswordHasherTest.php
git commit -m "feat(auth): add PasswordHasherInterface and NativePasswordHasher"
```

---

### Task 5: Authenticatable contract (auth)

**Files:**
- Create: `packages/auth/src/Authenticatable.php`
- Create: `packages/auth/src/AuthenticatableTrait.php`
- Create: `packages/auth/tests/Fixtures/TestUser.php`
- Create: `packages/auth/tests/AuthenticatableTraitTest.php`

- [ ] **Step 1: Write Authenticatable interface and trait**

Create `packages/auth/src/Authenticatable.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

interface Authenticatable
{
    public function getAuthId(): string;
    public function getPasswordHash(): string;
    public function getRoles(): array;
    public function hasRole(string $role): bool;
}
```

Create `packages/auth/src/AuthenticatableTrait.php`:

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 2: Write TestUser fixture and trait tests**

Create `packages/auth/tests/Fixtures/TestUser.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;

final class TestUser implements Authenticatable
{
    use AuthenticatableTrait;

    public function __construct(
        public string $uuid = 'user-1',
        public string $email = 'test@example.com',
        public string $passwordHash = '',
        public array $roles = [],
    ) {}
}
```

Create `packages/auth/tests/AuthenticatableTraitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tests\Fixtures\TestUser;

final class AuthenticatableTraitTest extends TestCase
{
    public function test_get_auth_id(): void
    {
        $user = new TestUser(uuid: 'abc-123');
        $this->assertSame('abc-123', $user->getAuthId());
    }

    public function test_get_password_hash(): void
    {
        $user = new TestUser(passwordHash: '$2y$10$hash');
        $this->assertSame('$2y$10$hash', $user->getPasswordHash());
    }

    public function test_roles(): void
    {
        $user = new TestUser(roles: ['admin', 'editor']);
        $this->assertSame(['admin', 'editor'], $user->getRoles());
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('superadmin'));
    }

    public function test_empty_roles(): void
    {
        $user = new TestUser();
        $this->assertSame([], $user->getRoles());
        $this->assertFalse($user->hasRole('anything'));
    }
}
```

- [ ] **Step 3: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/AuthenticatableTraitTest.php`
Expected: 4 tests, all PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/auth/src/Authenticatable.php packages/auth/src/AuthenticatableTrait.php packages/auth/tests/Fixtures/ packages/auth/tests/AuthenticatableTraitTest.php
git commit -m "feat(auth): add Authenticatable interface and AuthenticatableTrait"
```

---

### Task 6: User provider (auth)

**Files:**
- Create: `packages/auth/src/UserProviderInterface.php`
- Create: `packages/auth/src/DataManagerUserProvider.php`
- Create: `packages/auth/tests/DataManagerUserProviderTest.php`

- [ ] **Step 1: Write UserProviderInterface**

Create `packages/auth/src/UserProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?Authenticatable;
    public function findByCredentials(array $credentials): ?Authenticatable;
}
```

- [ ] **Step 2: Write DataManagerUserProvider tests**

Create `packages/auth/tests/DataManagerUserProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\DataManagerUserProvider;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;

#[Entity(table: 'users', storage: 'default')]
final class UserModel extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id] public string $uuid = '';
    #[Field] public string $email = '';
    #[Field] public string $passwordHash = '';
    #[Field] public string $roles = '[]';

    public function getRoles(): array
    {
        return json_decode($this->roles, true) ?: [];
    }
}

final class DataManagerUserProviderTest extends TestCase
{
    private \PDO $pdo;
    private DataManager $dm;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (uuid TEXT PRIMARY KEY, email TEXT UNIQUE, passwordHash TEXT, roles TEXT DEFAULT "[]")');

        $this->dm = new DataManager(['default' => new SqliteDriver($this->pdo)]);
    }

    private function createUser(string $id, string $email): void
    {
        $user = new UserModel();
        $user->uuid = $id;
        $user->email = $email;
        $user->passwordHash = password_hash('secret', PASSWORD_BCRYPT);
        $this->dm->save($user);
    }

    public function test_find_by_id(): void
    {
        $this->createUser('u1', 'alice@example.com');

        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $user = $provider->findById('u1');

        $this->assertNotNull($user);
        $this->assertSame('u1', $user->getAuthId());
    }

    public function test_find_by_id_returns_null_for_missing(): void
    {
        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $this->assertNull($provider->findById('nonexistent'));
    }

    public function test_find_by_credentials(): void
    {
        $this->createUser('u1', 'bob@example.com');

        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $user = $provider->findByCredentials(['email' => 'bob@example.com']);

        $this->assertNotNull($user);
        $this->assertSame('u1', $user->getAuthId());
    }

    public function test_find_by_credentials_returns_null_for_missing(): void
    {
        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $this->assertNull($provider->findByCredentials(['email' => 'nobody@example.com']));
    }
}
```

- [ ] **Step 3: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/DataManagerUserProviderTest.php`
Expected: FAIL — `DataManagerUserProvider` class not found.

- [ ] **Step 4: Implement DataManagerUserProvider**

Create `packages/auth/src/DataManagerUserProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Data\DataManager;
use Preflow\Data\Model;

final class DataManagerUserProvider implements UserProviderInterface
{
    /**
     * @param class-string<Model&Authenticatable> $modelClass
     */
    public function __construct(
        private readonly DataManager $dataManager,
        private readonly string $modelClass,
    ) {}

    public function findById(string $id): ?Authenticatable
    {
        $model = $this->dataManager->find($this->modelClass, $id);
        return $model instanceof Authenticatable ? $model : null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if ($email === null) {
            return null;
        }

        $result = $this->dataManager->query($this->modelClass)
            ->where('email', $email)
            ->first();

        return $result instanceof Authenticatable ? $result : null;
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/DataManagerUserProviderTest.php`
Expected: 4 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/auth/src/UserProviderInterface.php packages/auth/src/DataManagerUserProvider.php packages/auth/tests/DataManagerUserProviderTest.php
git commit -m "feat(auth): add UserProviderInterface and DataManagerUserProvider"
```

---

### Task 7: Guard system — SessionGuard + TokenGuard (auth)

**Files:**
- Create: `packages/auth/src/GuardInterface.php`
- Create: `packages/auth/src/SessionGuard.php`
- Create: `packages/auth/src/TokenGuard.php`
- Create: `packages/auth/src/PersonalAccessToken.php`
- Create: `packages/auth/tests/SessionGuardTest.php`
- Create: `packages/auth/tests/TokenGuardTest.php`

- [ ] **Step 1: Write GuardInterface**

Create `packages/auth/src/GuardInterface.php`:

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 2: Write SessionGuard tests**

Create `packages/auth/tests/SessionGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\UserProviderInterface;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Testing\ArraySession;

final class SessionGuardTest extends TestCase
{
    private ArraySession $session;
    private TestUser $user;
    private UserProviderInterface $provider;
    private SessionGuard $guard;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->session->start();

        $this->user = new TestUser(
            uuid: 'u1',
            email: 'test@example.com',
            passwordHash: password_hash('secret', PASSWORD_BCRYPT),
        );

        $this->provider = new class($this->user) implements UserProviderInterface {
            public function __construct(private readonly TestUser $user) {}
            public function findById(string $id): ?Authenticatable
            {
                return $id === $this->user->uuid ? $this->user : null;
            }
            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return ($credentials['email'] ?? null) === $this->user->email ? $this->user : null;
            }
        };

        $this->guard = new SessionGuard($this->provider, new NativePasswordHasher());
    }

    private function createRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(SessionInterface::class, $this->session);
    }

    public function test_user_returns_null_when_not_logged_in(): void
    {
        $this->assertNull($this->guard->user($this->createRequest()));
    }

    public function test_login_stores_user_id_in_session(): void
    {
        $this->guard->login($this->user, $this->createRequest());
        $this->assertSame('u1', $this->session->get('_auth_user_id'));
    }

    public function test_login_regenerates_session(): void
    {
        $oldId = $this->session->getId();
        $this->guard->login($this->user, $this->createRequest());
        $this->assertNotSame($oldId, $this->session->getId());
    }

    public function test_user_returns_user_after_login(): void
    {
        $this->guard->login($this->user, $this->createRequest());
        $resolved = $this->guard->user($this->createRequest());
        $this->assertNotNull($resolved);
        $this->assertSame('u1', $resolved->getAuthId());
    }

    public function test_logout_invalidates_session(): void
    {
        $this->guard->login($this->user, $this->createRequest());
        $this->guard->logout($this->createRequest());
        $this->assertNull($this->session->get('_auth_user_id'));
    }

    public function test_validate_with_correct_credentials(): void
    {
        $this->assertTrue($this->guard->validate([
            'email' => 'test@example.com',
            'password' => 'secret',
        ]));
    }

    public function test_validate_with_wrong_password(): void
    {
        $this->assertFalse($this->guard->validate([
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]));
    }

    public function test_validate_with_unknown_email(): void
    {
        $this->assertFalse($this->guard->validate([
            'email' => 'nobody@example.com',
            'password' => 'secret',
        ]));
    }
}
```

- [ ] **Step 3: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/SessionGuardTest.php`
Expected: FAIL — `SessionGuard` class not found.

- [ ] **Step 4: Implement SessionGuard**

Create `packages/auth/src/SessionGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Http\Session\SessionInterface;

final class SessionGuard implements GuardInterface
{
    private const SESSION_KEY = '_auth_user_id';

    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly PasswordHasherInterface $hasher,
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session === null) {
            return null;
        }

        $userId = $session->get(self::SESSION_KEY);
        if ($userId === null) {
            return null;
        }

        return $this->provider->findById($userId);
    }

    public function validate(array $credentials): bool
    {
        $user = $this->provider->findByCredentials($credentials);
        if ($user === null) {
            return false;
        }

        return $this->hasher->verify($credentials['password'] ?? '', $user->getPasswordHash());
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session === null) {
            return;
        }

        $session->regenerate();
        $session->set(self::SESSION_KEY, $user->getAuthId());

        // Rehash if needed
        if ($this->hasher->needsRehash($user->getPasswordHash())) {
            // Caller is responsible for saving the updated hash
        }
    }

    public function logout(ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);
        $session?->invalidate();
    }
}
```

- [ ] **Step 5: Run SessionGuard tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/SessionGuardTest.php`
Expected: 8 tests, all PASS.

- [ ] **Step 6: Write PersonalAccessToken model**

Create `packages/auth/src/PersonalAccessToken.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;

#[Entity(table: 'user_tokens', storage: 'default')]
final class PersonalAccessToken extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $tokenHash = '';
    #[Field] public string $userId = '';
    #[Field] public string $name = '';
    #[Field] public ?string $createdAt = null;

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generatePlainToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
```

- [ ] **Step 7: Write TokenGuard tests**

Create `packages/auth/tests/TokenGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\TokenGuard;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\ModelMetadata;

final class TokenGuardTest extends TestCase
{
    private \PDO $pdo;
    private DataManager $dm;
    private TestUser $user;
    private UserProviderInterface $provider;
    private TokenGuard $guard;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)');

        $this->dm = new DataManager(['default' => new SqliteDriver($this->pdo)]);

        $this->user = new TestUser(uuid: 'u1');

        $this->provider = new class($this->user) implements UserProviderInterface {
            public function __construct(private readonly TestUser $user) {}
            public function findById(string $id): ?Authenticatable
            {
                return $id === $this->user->uuid ? $this->user : null;
            }
            public function findByCredentials(array $credentials): ?Authenticatable { return null; }
        };

        $this->guard = new TokenGuard($this->provider, $this->dm);
    }

    private function createToken(): string
    {
        $plain = PersonalAccessToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->uuid = 'tok-1';
        $token->tokenHash = PersonalAccessToken::hashToken($plain);
        $token->userId = 'u1';
        $token->name = 'test';
        $this->dm->save($token);
        return $plain;
    }

    private function createRequest(string $bearer = ''): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/api/test');
        if ($bearer !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);
        }
        return $request;
    }

    public function test_user_with_valid_bearer_token(): void
    {
        $plain = $this->createToken();
        $user = $this->guard->user($this->createRequest($plain));
        $this->assertNotNull($user);
        $this->assertSame('u1', $user->getAuthId());
    }

    public function test_user_returns_null_without_token(): void
    {
        $this->assertNull($this->guard->user($this->createRequest()));
    }

    public function test_user_returns_null_with_invalid_token(): void
    {
        $this->createToken();
        $this->assertNull($this->guard->user($this->createRequest('invalid-token')));
    }

    public function test_login_and_logout_are_noops(): void
    {
        $request = $this->createRequest();
        $this->guard->login($this->user, $request);
        $this->guard->logout($request);
        // No exception = pass (stateless guard)
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 8: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/TokenGuardTest.php`
Expected: FAIL — `TokenGuard` class not found.

- [ ] **Step 9: Implement TokenGuard**

Create `packages/auth/src/TokenGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Data\DataManager;

final class TokenGuard implements GuardInterface
{
    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly DataManager $dataManager,
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return null;
        }

        $hash = PersonalAccessToken::hashToken($token);

        $record = $this->dataManager->query(PersonalAccessToken::class)
            ->where('tokenHash', $hash)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->provider->findById($record->userId);
    }

    public function validate(array $credentials): bool
    {
        return false; // Token guard doesn't validate password credentials
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void
    {
        // Stateless — no-op
    }

    public function logout(ServerRequestInterface $request): void
    {
        // Stateless — no-op
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);
        return $token !== '' ? $token : null;
    }
}
```

- [ ] **Step 10: Run all guard tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/SessionGuardTest.php packages/auth/tests/TokenGuardTest.php`
Expected: 12 tests, all PASS.

- [ ] **Step 11: Commit**

```bash
git add packages/auth/src/GuardInterface.php packages/auth/src/SessionGuard.php packages/auth/src/TokenGuard.php packages/auth/src/PersonalAccessToken.php packages/auth/tests/SessionGuardTest.php packages/auth/tests/TokenGuardTest.php
git commit -m "feat(auth): add guard system — GuardInterface, SessionGuard, TokenGuard"
```

---

### Task 8: AuthManager (auth)

**Files:**
- Create: `packages/auth/src/AuthManager.php`
- Create: `packages/auth/tests/AuthManagerTest.php`

- [ ] **Step 1: Write AuthManager tests**

Create `packages/auth/tests/AuthManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Psr\Http\Message\ServerRequestInterface;

final class AuthManagerTest extends TestCase
{
    private function createGuard(?Authenticatable $user = null): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };
    }

    public function test_guard_returns_default_guard(): void
    {
        $sessionGuard = $this->createGuard();
        $manager = new AuthManager(
            guards: ['session' => $sessionGuard],
            defaultGuard: 'session',
        );

        $this->assertSame($sessionGuard, $manager->guard());
    }

    public function test_guard_returns_named_guard(): void
    {
        $sessionGuard = $this->createGuard();
        $tokenGuard = $this->createGuard();

        $manager = new AuthManager(
            guards: ['session' => $sessionGuard, 'token' => $tokenGuard],
            defaultGuard: 'session',
        );

        $this->assertSame($tokenGuard, $manager->guard('token'));
    }

    public function test_guard_throws_for_unknown(): void
    {
        $manager = new AuthManager(guards: [], defaultGuard: 'session');

        $this->expectException(\RuntimeException::class);
        $manager->guard('nonexistent');
    }

    public function test_set_and_get_user(): void
    {
        $manager = new AuthManager(guards: [], defaultGuard: 'session');
        $user = new TestUser(uuid: 'u1');

        $this->assertNull($manager->user());
        $manager->setUser($user);
        $this->assertSame($user, $manager->user());
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/AuthManagerTest.php`
Expected: FAIL — `AuthManager` class not found.

- [ ] **Step 3: Implement AuthManager**

Create `packages/auth/src/AuthManager.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class AuthManager
{
    private ?Authenticatable $resolvedUser = null;

    /**
     * @param array<string, GuardInterface> $guards
     */
    public function __construct(
        private readonly array $guards,
        private readonly string $defaultGuard,
    ) {}

    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->defaultGuard;

        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard [{$name}] is not configured.");
        }

        return $this->guards[$name];
    }

    public function setUser(?Authenticatable $user): void
    {
        $this->resolvedUser = $user;
    }

    public function user(): ?Authenticatable
    {
        return $this->resolvedUser;
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/AuthManagerTest.php`
Expected: 4 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/auth/src/AuthManager.php packages/auth/tests/AuthManagerTest.php
git commit -m "feat(auth): add AuthManager — resolves guards by name"
```

---

### Task 9: Auth middleware (auth)

**Files:**
- Create: `packages/auth/src/Http/AuthMiddleware.php`
- Create: `packages/auth/src/Http/GuestMiddleware.php`
- Create: `packages/auth/tests/Http/AuthMiddlewareTest.php`
- Create: `packages/auth/tests/Http/GuestMiddlewareTest.php`

- [ ] **Step 1: Write AuthMiddleware tests**

Create `packages/auth/tests/Http/AuthMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Exceptions\UnauthorizedHttpException;

final class AuthMiddlewareTest extends TestCase
{
    private function createGuard(?Authenticatable $user): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };
    }

    private function createManager(?Authenticatable $user): AuthManager
    {
        return new AuthManager(
            guards: ['session' => $this->createGuard($user)],
            defaultGuard: 'session',
        );
    }

    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/');
    }

    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    public function test_passes_authenticated_user(): void
    {
        $user = new TestUser(uuid: 'u1');
        $manager = $this->createManager($user);
        $mw = new AuthMiddleware($manager);

        $captured = null;
        $mw->process($this->createRequest(), $this->createHandler($captured));

        $attached = $captured->getAttribute(Authenticatable::class);
        $this->assertSame('u1', $attached->getAuthId());
    }

    public function test_sets_user_on_auth_manager(): void
    {
        $user = new TestUser(uuid: 'u1');
        $manager = $this->createManager($user);
        $mw = new AuthMiddleware($manager);

        $mw->process($this->createRequest(), $this->createHandler());

        $this->assertSame($user, $manager->user());
    }

    public function test_throws_401_when_not_authenticated(): void
    {
        $manager = $this->createManager(null);
        $mw = new AuthMiddleware($manager);

        $this->expectException(UnauthorizedHttpException::class);
        $mw->process($this->createRequest(), $this->createHandler());
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/Http/AuthMiddlewareTest.php`
Expected: FAIL — `AuthMiddleware` class not found.

- [ ] **Step 3: Implement AuthMiddleware**

Create `packages/auth/src/Http/AuthMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Exceptions\UnauthorizedHttpException;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $authManager,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authManager->guard()->user($request);

        if ($user === null) {
            throw new UnauthorizedHttpException();
        }

        $this->authManager->setUser($user);
        $request = $request->withAttribute(Authenticatable::class, $user);

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Run AuthMiddleware tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/Http/AuthMiddlewareTest.php`
Expected: 3 tests, all PASS.

- [ ] **Step 5: Write GuestMiddleware tests**

Create `packages/auth/tests/Http/GuestMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\GuestMiddleware;
use Preflow\Auth\Tests\Fixtures\TestUser;

final class GuestMiddlewareTest extends TestCase
{
    private function createManager(?Authenticatable $user): AuthManager
    {
        $guard = new class($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };

        return new AuthManager(guards: ['session' => $guard], defaultGuard: 'session');
    }

    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/login');
    }

    public function test_passes_unauthenticated_users(): void
    {
        $manager = $this->createManager(null);
        $mw = new GuestMiddleware($manager);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'login page');
            }
        };

        $response = $mw->process($this->createRequest(), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_redirects_authenticated_users(): void
    {
        $user = new TestUser(uuid: 'u1');
        $manager = $this->createManager($user);
        $mw = new GuestMiddleware($manager);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $response = $mw->process($this->createRequest(), $handler);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function test_redirects_to_custom_path(): void
    {
        $user = new TestUser(uuid: 'u1');
        $manager = $this->createManager($user);
        $mw = new GuestMiddleware($manager, redirectTo: '/dashboard');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $response = $mw->process($this->createRequest(), $handler);
        $this->assertSame('/dashboard', $response->getHeaderLine('Location'));
    }
}
```

- [ ] **Step 6: Implement GuestMiddleware**

Create `packages/auth/src/Http/GuestMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Auth\AuthManager;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly string $redirectTo = '/',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authManager->guard()->user($request);

        if ($user !== null) {
            return new Response(302, ['Location' => $this->redirectTo]);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 7: Run all middleware tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/Http/`
Expected: 6 tests, all PASS.

- [ ] **Step 8: Commit**

```bash
git add packages/auth/src/Http/ packages/auth/tests/Http/
git commit -m "feat(auth): add AuthMiddleware and GuestMiddleware"
```

---

### Task 10: Auth extension provider (auth)

**Files:**
- Create: `packages/auth/src/AuthExtensionProvider.php`
- Create: `packages/auth/tests/AuthExtensionProviderTest.php`

- [ ] **Step 1: Write AuthExtensionProvider tests**

Create `packages/auth/tests/AuthExtensionProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthExtensionProvider;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Tests\Fixtures\TestUser;

final class AuthExtensionProviderTest extends TestCase
{
    public function test_auth_user_returns_null_when_not_set(): void
    {
        $manager = new AuthManager(guards: [], defaultGuard: 'session');
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $authUser = $this->findFunction($functions, 'auth_user');

        $this->assertNotNull($authUser);
        $this->assertNull(($authUser->callable)());
    }

    public function test_auth_user_returns_user_when_set(): void
    {
        $manager = new AuthManager(guards: [], defaultGuard: 'session');
        $user = new TestUser(uuid: 'u1');
        $manager->setUser($user);

        $provider = new AuthExtensionProvider($manager);
        $functions = $provider->getTemplateFunctions();
        $authUser = $this->findFunction($functions, 'auth_user');

        $result = ($authUser->callable)();
        $this->assertSame('u1', $result->getAuthId());
    }

    public function test_auth_check_returns_boolean(): void
    {
        $manager = new AuthManager(guards: [], defaultGuard: 'session');
        $provider = new AuthExtensionProvider($manager);
        $functions = $provider->getTemplateFunctions();
        $authCheck = $this->findFunction($functions, 'auth_check');

        $this->assertFalse(($authCheck->callable)());

        $manager->setUser(new TestUser());
        $this->assertTrue(($authCheck->callable)());
    }

    public function test_flash_reads_from_session(): void
    {
        $session = new \Preflow\Testing\ArraySession();
        $session->start();
        $session->flash('error', 'Something went wrong');

        $manager = new AuthManager(guards: [], defaultGuard: 'session');
        $provider = new AuthExtensionProvider($manager, $session);
        $functions = $provider->getTemplateFunctions();
        $flash = $this->findFunction($functions, 'flash');

        $this->assertSame('Something went wrong', ($flash->callable)('error'));
        $this->assertNull(($flash->callable)('missing'));
    }

    private function findFunction(array $functions, string $name): ?\Preflow\View\TemplateFunctionDefinition
    {
        foreach ($functions as $fn) {
            if ($fn->name === $name) {
                return $fn;
            }
        }
        return null;
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

Run: `./vendor/bin/phpunit packages/auth/tests/AuthExtensionProviderTest.php`
Expected: FAIL — `AuthExtensionProvider` class not found.

- [ ] **Step 3: Implement AuthExtensionProvider**

Create `packages/auth/src/AuthExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class AuthExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?SessionInterface $session = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'auth_user',
                callable: fn () => $this->authManager->user(),
                isSafe: false,
            ),
            new TemplateFunctionDefinition(
                name: 'auth_check',
                callable: fn () => $this->authManager->user() !== null,
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'csrf_token',
                callable: fn () => $this->csrfField(),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'flash',
                callable: fn (string $key, mixed $default = null) => $this->session?->getFlash($key, $default),
                isSafe: false,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    private function csrfField(): string
    {
        if ($this->session === null) {
            return '';
        }

        $token = CsrfToken::fromSession($this->session);
        if ($token === null) {
            return '';
        }

        $value = htmlspecialchars($token->getValue(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $value . '">';
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

Run: `./vendor/bin/phpunit packages/auth/tests/AuthExtensionProviderTest.php`
Expected: 3 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/auth/src/AuthExtensionProvider.php packages/auth/tests/AuthExtensionProviderTest.php
git commit -m "feat(auth): add AuthExtensionProvider — auth_user, auth_check, csrf_token template functions"
```

---

### Task 11: Route middleware in Kernel + Application boot integration

**Files:**
- Modify: `packages/core/src/Kernel.php`
- Modify: `packages/core/src/Application.php`

- [ ] **Step 1: Add route-level middleware execution to Kernel**

Currently `Kernel::handle()` matches the route but ignores `$route->middleware`. Fix this so route middleware runs between global middleware and dispatch.

In `packages/core/src/Kernel.php`, replace the `handle` method body:

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    try {
        return $this->pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
            $route = $this->router->match($req);
            $this->collector?->setRoute($route->mode->value, $route->handler, $route->parameters);

            $dispatch = fn(ServerRequestInterface $r): ResponseInterface => match ($route->mode) {
                RouteMode::Component => ($this->componentRenderer)($route, $r),
                RouteMode::Action => ($this->actionDispatcher)($route, $r),
            };

            // Execute route-level middleware if any
            if ($route->middleware !== []) {
                $routePipeline = new MiddlewarePipeline();
                foreach ($route->middleware as $mwClass) {
                    $mw = $this->container->has($mwClass)
                        ? $this->container->get($mwClass)
                        : $this->container->make($mwClass);
                    $routePipeline->pipe($mw);
                }
                return $routePipeline->process($req, $dispatch);
            }

            return $dispatch($req);
        });
    } catch (\Throwable $e) {
        return $this->errorHandler->handleException($e, $request);
    }
}
```

- [ ] **Step 2: Add bootSession, bootCsrf, and bootAuth to Application**

In `packages/core/src/Application.php`, add three new private methods and wire them into `boot()`.

Add these calls to `boot()`, **after** `bootI18n()` and **before** `bootProviders()`:

```php
$this->bootSession();
$this->bootCsrf();
$this->bootAuth();
```

Add the three methods:

```php
private function bootSession(): void
{
    if (!class_exists(\Preflow\Core\Http\Session\NativeSession::class)) {
        return;
    }

    // Session config lives in config/auth.php under 'session' key
    $authConfigPath = $this->basePath('config/auth.php');
    if (!file_exists($authConfigPath)) {
        return;
    }

    $authConfig = require $authConfigPath;
    $sessionConfig = $authConfig['session'] ?? [];

    if ($sessionConfig === []) {
        return;
    }

    $session = new \Preflow\Core\Http\Session\NativeSession($sessionConfig);
    $this->container->instance(\Preflow\Core\Http\Session\SessionInterface::class, $session);

    $this->addMiddleware(new \Preflow\Core\Http\Session\SessionMiddleware($session));
}

private function bootCsrf(): void
{
    if (!class_exists(\Preflow\Core\Http\Csrf\CsrfMiddleware::class)) {
        return;
    }

    if (!$this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)) {
        return;
    }

    $this->addMiddleware(new \Preflow\Core\Http\Csrf\CsrfMiddleware());
}

private function bootAuth(): void
{
    if (!class_exists(\Preflow\Auth\AuthManager::class)) {
        return;
    }

    $authConfigPath = $this->basePath('config/auth.php');
    if (!file_exists($authConfigPath)) {
        return;
    }

    $authConfig = require $authConfigPath;

    // Build password hasher
    $hasherClass = $authConfig['password_hasher'] ?? \Preflow\Auth\NativePasswordHasher::class;
    $hasher = new $hasherClass();
    $this->container->instance(\Preflow\Auth\PasswordHasherInterface::class, $hasher);

    // Build user providers
    $providers = [];
    foreach ($authConfig['providers'] ?? [] as $name => $providerConfig) {
        $providerClass = $providerConfig['class'];
        $modelClass = $providerConfig['model'] ?? null;

        if ($providerClass === \Preflow\Auth\DataManagerUserProvider::class
            && $this->container->has(\Preflow\Data\DataManager::class)) {
            $providers[$name] = new \Preflow\Auth\DataManagerUserProvider(
                $this->container->get(\Preflow\Data\DataManager::class),
                $modelClass,
            );
        }
    }

    // Build guards
    $guards = [];
    $session = $this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)
        ? $this->container->get(\Preflow\Core\Http\Session\SessionInterface::class)
        : null;

    foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
        $guardClass = $guardConfig['class'];
        $provider = $providers[$guardConfig['provider'] ?? ''] ?? null;

        if ($provider === null) {
            continue;
        }

        $guards[$name] = match ($guardClass) {
            \Preflow\Auth\SessionGuard::class => new \Preflow\Auth\SessionGuard($provider, $hasher),
            \Preflow\Auth\TokenGuard::class => new \Preflow\Auth\TokenGuard(
                $provider,
                $this->container->get(\Preflow\Data\DataManager::class),
            ),
            default => null,
        };
    }

    $guards = array_filter($guards);
    $defaultGuard = $authConfig['default_guard'] ?? 'session';

    $authManager = new \Preflow\Auth\AuthManager($guards, $defaultGuard);
    $this->container->instance(\Preflow\Auth\AuthManager::class, $authManager);

    // Register auth middleware in container (for route-level use)
    $this->container->instance(
        \Preflow\Auth\Http\AuthMiddleware::class,
        new \Preflow\Auth\Http\AuthMiddleware($authManager),
    );
    $this->container->instance(
        \Preflow\Auth\Http\GuestMiddleware::class,
        new \Preflow\Auth\Http\GuestMiddleware($authManager),
    );

    // Register template functions
    if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
        $authProvider = new \Preflow\Auth\AuthExtensionProvider($authManager, $session);
        $this->registerExtensionProvider($engine, $authProvider);
    }
}
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All existing + new tests PASS. No regressions.

- [ ] **Step 4: Commit**

```bash
git add packages/core/src/Kernel.php packages/core/src/Application.php
git commit -m "feat(core): wire session, CSRF, and auth into Application boot + route middleware in Kernel"
```

---

### Task 12: Skeleton additions

**Files:**
- Create: `packages/skeleton/config/auth.php`
- Create: `packages/skeleton/app/Models/User.php`
- Create: `packages/skeleton/app/pages/login.twig`
- Create: `packages/skeleton/app/pages/login.php`
- Create: `packages/skeleton/app/pages/register.twig`
- Create: `packages/skeleton/app/pages/register.php`
- Create: `packages/skeleton/app/pages/logout.php`
- Create: `packages/skeleton/migrations/001_create_users.sql`
- Create: `packages/skeleton/migrations/002_create_user_tokens.sql`
- Modify: `packages/skeleton/composer.json`

- [ ] **Step 1: Add auth config**

Create `packages/skeleton/config/auth.php`:

```php
<?php

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
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
```

- [ ] **Step 2: Add User model**

Create `packages/skeleton/app/Models/User.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\Transform\JsonTransformer;

#[Entity(table: 'users', storage: 'default')]
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $email = '';

    #[Field]
    public string $passwordHash = '';

    #[Field(transform: JsonTransformer::class)]
    public array $roles = [];

    #[Field]
    public ?string $createdAt = null;
}
```

- [ ] **Step 3: Add migration files**

Create `packages/skeleton/migrations/001_create_users.sql`:

```sql
CREATE TABLE IF NOT EXISTS users (
    uuid TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    passwordHash TEXT NOT NULL,
    roles TEXT NOT NULL DEFAULT '[]',
    createdAt TEXT DEFAULT (datetime('now'))
);
```

Create `packages/skeleton/migrations/002_create_user_tokens.sql`:

```sql
CREATE TABLE IF NOT EXISTS user_tokens (
    uuid TEXT PRIMARY KEY,
    tokenHash TEXT NOT NULL UNIQUE,
    userId TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    createdAt TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (userId) REFERENCES users(uuid) ON DELETE CASCADE
);
```

- [ ] **Step 4: Add login page**

Create `packages/skeleton/app/pages/login.php`:

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Http\Session\SessionInterface;

return new class {
    public function post(ServerRequestInterface $request): mixed
    {
        $body = $request->getParsedBody();
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        $container = $request->getAttribute('container');
        $authManager = $container->get(AuthManager::class);
        $guard = $authManager->guard();
        $session = $request->getAttribute(SessionInterface::class);

        if ($guard->validate(['email' => $email, 'password' => $password])) {
            $provider = $container->get(\Preflow\Auth\UserProviderInterface::class);
            $user = $provider->findByCredentials(['email' => $email]);

            if ($user !== null) {
                $guard->login($user, $request);
                return ['redirect' => '/'];
            }
        }

        $session?->flash('error', 'Invalid email or password.');
        return ['redirect' => '/login'];
    }
};
```

Create `packages/skeleton/app/pages/login.twig`:

```twig
{% extends "_layout.twig" %}

{% block content %}
<main style="max-width: 400px; margin: 4rem auto; padding: 2rem;">
    <h1>Log in</h1>

    {% set error = flash('error') %}
    {% if error %}
        <p style="color: #c00;">{{ error }}</p>
    {% endif %}

    <form method="post" action="/login">
        {{ csrf_token()|raw }}

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus
               style="width: 100%; padding: 0.5rem; margin: 0.25rem 0 1rem;">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required
               style="width: 100%; padding: 0.5rem; margin: 0.25rem 0 1rem;">

        <button type="submit" style="width: 100%; padding: 0.75rem; cursor: pointer;">
            Log in
        </button>
    </form>

    <p style="margin-top: 1rem; text-align: center;">
        <a href="/register">Create an account</a>
    </p>
</main>
{% endblock %}
```

- [ ] **Step 5: Add register page**

Create `packages/skeleton/app/pages/register.php`:

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;

return new class {
    public function post(ServerRequestInterface $request): mixed
    {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        $container = $request->getAttribute('container');
        $session = $request->getAttribute(SessionInterface::class);

        if ($password !== $passwordConfirm) {
            $session?->flash('error', 'Passwords do not match.');
            return ['redirect' => '/register'];
        }

        if (strlen($password) < 8) {
            $session?->flash('error', 'Password must be at least 8 characters.');
            return ['redirect' => '/register'];
        }

        $dm = $container->get(DataManager::class);
        $hasher = $container->get(PasswordHasherInterface::class);

        // Check if email is taken
        $existing = $dm->query(\App\Models\User::class)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            $session?->flash('error', 'An account with that email already exists.');
            return ['redirect' => '/register'];
        }

        $user = new \App\Models\User();
        $user->uuid = bin2hex(random_bytes(16));
        $user->email = $email;
        $user->passwordHash = $hasher->hash($password);
        $user->createdAt = date('Y-m-d H:i:s');

        $dm->save($user);

        $session?->flash('success', 'Account created. Please log in.');
        return ['redirect' => '/login'];
    }
};
```

Create `packages/skeleton/app/pages/register.twig`:

```twig
{% extends "_layout.twig" %}

{% block content %}
<main style="max-width: 400px; margin: 4rem auto; padding: 2rem;">
    <h1>Create account</h1>

    {% set error = flash('error') %}
    {% if error %}
        <p style="color: #c00;">{{ error }}</p>
    {% endif %}

    <form method="post" action="/register">
        {{ csrf_token()|raw }}

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus
               style="width: 100%; padding: 0.5rem; margin: 0.25rem 0 1rem;">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8"
               style="width: 100%; padding: 0.5rem; margin: 0.25rem 0 1rem;">

        <label for="password_confirm">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm" required
               style="width: 100%; padding: 0.5rem; margin: 0.25rem 0 1rem;">

        <button type="submit" style="width: 100%; padding: 0.75rem; cursor: pointer;">
            Create account
        </button>
    </form>

    <p style="margin-top: 1rem; text-align: center;">
        <a href="/login">Already have an account?</a>
    </p>
</main>
{% endblock %}
```

- [ ] **Step 6: Add logout handler**

Create `packages/skeleton/app/pages/logout.php`:

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Auth\AuthManager;

return new class {
    public function post(ServerRequestInterface $request): mixed
    {
        $container = $request->getAttribute('container');
        $authManager = $container->get(AuthManager::class);
        $authManager->guard()->logout($request);

        return ['redirect' => '/login'];
    }
};
```

- [ ] **Step 7: Add preflow/auth to skeleton composer.json**

In `packages/skeleton/composer.json`, add to `require`:
```json
"preflow/auth": "^0.1 || @dev"
```

- [ ] **Step 8: Commit**

```bash
git add packages/skeleton/config/auth.php packages/skeleton/app/Models/User.php packages/skeleton/app/pages/login.twig packages/skeleton/app/pages/login.php packages/skeleton/app/pages/register.twig packages/skeleton/app/pages/register.php packages/skeleton/app/pages/logout.php packages/skeleton/migrations/ packages/skeleton/composer.json
git commit -m "feat(skeleton): add auth config, User model, login/register/logout pages, migrations"
```

---

### Task 13: Testing helpers + finalization

**Files:**
- Modify: `packages/testing/composer.json`
- Modify: `packages/testing/src/TestApplication.php` (or create new helper)

- [ ] **Step 1: Add actingAs helper to testing package**

Add `preflow/auth` to `packages/testing/composer.json` require:
```json
"preflow/auth": "^0.1 || @dev"
```

Create `packages/testing/src/AuthTestHelpers.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

trait AuthTestHelpers
{
    /**
     * Set the authenticated user for the given request.
     */
    protected function actingAs(
        Authenticatable $user,
        ServerRequestInterface $request,
    ): ServerRequestInterface {
        return $request->withAttribute(Authenticatable::class, $user);
    }
}
```

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS — session, CSRF, auth, and all existing tests.

- [ ] **Step 3: Commit**

```bash
git add packages/testing/
git commit -m "feat(testing): add ArraySession and AuthTestHelpers"
```

- [ ] **Step 4: Run composer update and verify autoloading**

Run: `composer update && composer dump-autoload`
Expected: All packages resolve correctly.

- [ ] **Step 5: Final commit with any autoload/lock changes**

```bash
git add composer.lock
git commit -m "chore: update composer.lock for auth package"
```

---

## Implementation Notes

### Page handler integration

The skeleton's login/register page handlers (`login.php`, `register.php`) use a co-located PHP pattern with `return new class { public function post(...) }`. **Before implementing Task 12**, check how the existing page routing system handles co-located PHP files:

1. Read `packages/routing/src/Router.php` to see how `.php` files next to `.twig` files are loaded
2. Check if the co-located handler receives the request, container, or other context
3. If the co-located PHP API doesn't support POST handling well, **fall back to an `AuthController`** in `packages/skeleton/app/Controllers/AuthController.php` using the `#[Route]` / `#[Get]` / `#[Post]` attribute pattern (see `HealthController.php` for the pattern). The controller renders twig templates for GET and handles form submission for POST.

### Database schema for MySQL

The migration files use SQLite syntax. For MySQL compatibility, the `datetime('now')` default needs to become `CURRENT_TIMESTAMP`. If both dialects are needed, create separate migration files per dialect or use the Dialect system from the data layer.
