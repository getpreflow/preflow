# Preflow Framework Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 6 priority framework issues discovered during the BGGenius migration stress test.

**Architecture:** Each fix is independent — different packages, no cross-dependencies. Tasks can be executed in any order. All changes are in the Preflow monorepo at `/Users/smyr/Sites/gbits/flopp/`.

**Tech Stack:** PHP 8.5, PHPUnit 11, PSR-7/PSR-15

**Design Spec:** `docs/superpowers/specs/2026-04-14-framework-fixes-design.md`

---

## Task 1: HTMX-Aware Asset Delivery

**Context:** When ComponentEndpoint renders a component for an HTMX response, CSS/JS collected by `{% apply css %}` / `{% apply js %}` is discarded — the fragment contains only HTML. The AssetCollector is request-scoped (created fresh in bootViewLayer), so for HTMX requests it starts empty and only contains assets from the current component render.

**Files:**
- Modify: `packages/htmx/src/ComponentEndpoint.php`
- Modify: `packages/view/src/AssetCollector.php`
- Test: `packages/htmx/tests/ComponentEndpointTest.php`

- [ ] **Step 1: Add getRegisteredCss/Js methods to AssetCollector**

In `packages/view/src/AssetCollector.php`, add public methods to check if any CSS/JS was collected:

```php
public function hasCss(): bool
{
    return $this->cssRegistry !== [];
}

public function hasJs(): bool
{
    return $this->jsHead !== [] || $this->jsBody !== [] || $this->jsInline !== [];
}
```

- [ ] **Step 2: Write failing test for asset delivery in HTMX response**

In `packages/htmx/tests/ComponentEndpointTest.php`, add a test that verifies the HTMX response contains collected CSS/JS. First, create a test component whose template uses `{% apply css %}`:

Since the existing test uses a stub TemplateEngineInterface, we need to simulate CSS collection. The test should verify that if the AssetCollector has CSS after rendering, it appears in the response body.

```php
public function test_htmx_response_includes_collected_css(): void
{
    // Set up asset collector with CSS collected during render
    $nonce = new \Preflow\View\NonceGenerator();
    $assets = new \Preflow\View\AssetCollector($nonce);

    $engine = $this->createStub(TemplateEngineInterface::class);
    $engine->method('render')->willReturnCallback(function () use ($assets) {
        $assets->addCss('.test { color: red; }');
        return '<div>Component HTML</div>';
    });

    $renderer = new ComponentRenderer($engine, new ErrorBoundary(DebugLevel::Off));
    $endpoint = new ComponentEndpoint(
        $this->createToken(),
        $renderer,
        $this->createDriver(),
        fn (string $class, array $props) => new EndpointTestComponent(),
        $assets,
    );

    $request = $this->buildRequest('render', EndpointTestComponent::class);
    $response = $endpoint->handle($request);
    $body = (string) $response->getBody();

    $this->assertStringContainsString('<div>Component HTML</div>', $body);
    $this->assertStringContainsString('<style', $body);
    $this->assertStringContainsString('.test { color: red; }', $body);
}

public function test_htmx_response_includes_collected_js(): void
{
    $nonce = new \Preflow\View\NonceGenerator();
    $assets = new \Preflow\View\AssetCollector($nonce);

    $engine = $this->createStub(TemplateEngineInterface::class);
    $engine->method('render')->willReturnCallback(function () use ($assets) {
        $assets->addJs('console.log("init");');
        return '<div>Component HTML</div>';
    });

    $renderer = new ComponentRenderer($engine, new ErrorBoundary(DebugLevel::Off));
    $endpoint = new ComponentEndpoint(
        $this->createToken(),
        $renderer,
        $this->createDriver(),
        fn (string $class, array $props) => new EndpointTestComponent(),
        $assets,
    );

    $request = $this->buildRequest('render', EndpointTestComponent::class);
    $response = $endpoint->handle($request);
    $body = (string) $response->getBody();

    $this->assertStringContainsString('<script', $body);
    $this->assertStringContainsString('console.log("init")', $body);
}
```

Note: The ComponentEndpoint constructor needs to accept an optional AssetCollector parameter. Check the current constructor and add it.

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/htmx/tests/ComponentEndpointTest.php --filter="test_htmx_response_includes"`
Expected: FAIL (AssetCollector not injected, no CSS/JS in response)

- [ ] **Step 4: Modify ComponentEndpoint to accept AssetCollector and append assets**

In `packages/htmx/src/ComponentEndpoint.php`:

1. Add `?AssetCollector $assetCollector = null` to the constructor
2. After rendering the component HTML, check if assets were collected and append them

```php
public function __construct(
    private readonly ComponentToken $token,
    private readonly ComponentRenderer $renderer,
    private readonly HypermediaDriver $driver,
    private readonly \Closure $componentFactory,
    private readonly ?\Preflow\View\AssetCollector $assetCollector = null,
) {}
```

In the `handle()` method, after `$html = $this->renderer->render(...)` or `renderResolved(...)`:

```php
// Append collected CSS/JS for HTMX fragment responses
if ($this->assetCollector !== null) {
    $fragmentAssets = '';
    if ($this->assetCollector->hasCss()) {
        $fragmentAssets .= $this->assetCollector->renderCss();
    }
    if ($this->assetCollector->hasJs()) {
        $fragmentAssets .= $this->assetCollector->renderJsBody();
        $fragmentAssets .= $this->assetCollector->renderJsInline();
    }
    if ($fragmentAssets !== '') {
        $html .= $fragmentAssets;
    }
}
```

3. Update `Application.php` to pass the AssetCollector when constructing the ComponentEndpoint (in `bootComponentLayer()` or wherever the endpoint is created).

- [ ] **Step 5: Run tests**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/htmx/tests/ComponentEndpointTest.php`
Expected: All tests pass including new ones

- [ ] **Step 6: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All 540+ tests pass

- [ ] **Step 7: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/htmx/src/ComponentEndpoint.php packages/view/src/AssetCollector.php packages/htmx/tests/ComponentEndpointTest.php packages/core/src/Application.php
git commit -m "feat(htmx): append collected CSS/JS to HTMX fragment responses

ComponentEndpoint now appends any CSS/JS collected by AssetCollector
during component rendering to the fragment HTML response. This enables
{% apply css %} and {% apply js %} to work correctly with HTMX swaps."
```

---

## Task 2: Auto-Increment ID Support

**Context:** `PdoDriver::save()` uses `INSERT OR REPLACE` which requires a valid ID. New models with `id=0` overwrite each other. Need to detect empty IDs and use plain INSERT, then read back the auto-increment value.

**Files:**
- Modify: `packages/data/src/StorageDriver.php`
- Modify: `packages/data/src/Driver/PdoDriver.php`
- Modify: `packages/data/src/Driver/Dialect.php`
- Modify: `packages/data/src/Driver/SqliteDialect.php`
- Modify: `packages/data/src/Driver/MysqlDialect.php`
- Modify: `packages/data/src/Driver/JsonFileDriver.php`
- Modify: `packages/data/src/DataManager.php`
- Test: `packages/data/tests/Driver/SqliteDriverTest.php`
- Test: `packages/data/tests/DataManagerTest.php`

- [ ] **Step 1: Add insertSql to Dialect interface**

In `packages/data/src/Driver/Dialect.php`:

```php
interface Dialect
{
    public function quoteIdentifier(string $name): string;
    public function upsertSql(string $table, array $columns, string $idField): string;
    public function insertSql(string $table, array $columns): string;
}
```

- [ ] **Step 2: Implement insertSql in SqliteDialect**

In `packages/data/src/Driver/SqliteDialect.php`:

```php
public function insertSql(string $table, array $columns): string
{
    $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
    $placeholders = array_fill(0, count($columns), '?');

    return sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $this->quoteIdentifier($table),
        implode(', ', $quoted),
        implode(', ', $placeholders),
    );
}
```

- [ ] **Step 3: Implement insertSql in MysqlDialect**

Same implementation as SQLite (plain INSERT syntax is the same):

```php
public function insertSql(string $table, array $columns): string
{
    $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
    $placeholders = array_fill(0, count($columns), '?');

    return sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $this->quoteIdentifier($table),
        implode(', ', $quoted),
        implode(', ', $placeholders),
    );
}
```

- [ ] **Step 4: Write failing test for auto-increment insert**

In `packages/data/tests/Driver/SqliteDriverTest.php`, add:

```php
public function test_save_with_empty_id_uses_auto_increment(): void
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )');

    $driver = new SqliteDriver($pdo);

    // Save with id=0 should INSERT and get auto-increment ID
    $driver->save('items', 0, ['name' => 'First'], 'id');
    $driver->save('items', 0, ['name' => 'Second'], 'id');

    // Both should exist as separate rows
    $all = $driver->findMany('items', new \Preflow\Data\Query())->items();
    $this->assertCount(2, $all);
    $this->assertSame('First', $all[0]['name']);
    $this->assertSame('Second', $all[1]['name']);
    $this->assertNotSame($all[0]['id'], $all[1]['id']);
}

public function test_save_with_empty_id_returns_last_insert_id(): void
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )');

    $driver = new SqliteDriver($pdo);
    $driver->save('items', 0, ['name' => 'Test'], 'id');

    $lastId = $driver->lastInsertId();
    $this->assertGreaterThan(0, (int) $lastId);

    $found = $driver->findOne('items', (int) $lastId, 'id');
    $this->assertNotNull($found);
    $this->assertSame('Test', $found['name']);
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/data/tests/Driver/SqliteDriverTest.php --filter="auto_increment"`
Expected: FAIL

- [ ] **Step 6: Implement auto-increment in PdoDriver**

In `packages/data/src/Driver/PdoDriver.php`, modify `save()` and add `lastInsertId()`:

```php
public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
{
    $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

    if ($isEmpty) {
        // Auto-increment: remove ID field, use plain INSERT
        unset($data[$idField]);
        $data = array_filter($data, fn ($v) => $v !== null);
        $columns = array_keys($data);
        $sql = $this->dialect->insertSql($type, $columns);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, array_values($data));
    } else {
        // Existing upsert logic
        if (!isset($data[$idField])) {
            $data[$idField] = $id;
        }
        $data = array_filter($data, fn ($v) => $v !== null);
        $columns = array_keys($data);
        $sql = $this->dialect->upsertSql($type, $columns, $idField);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, array_values($data));
    }
}

public function lastInsertId(): string|int
{
    $id = $this->pdo->lastInsertId();
    return is_numeric($id) ? (int) $id : $id;
}
```

- [ ] **Step 7: Add lastInsertId to StorageDriver interface and JsonFileDriver**

In `packages/data/src/StorageDriver.php`:
```php
public function lastInsertId(): string|int;
```

In `packages/data/src/Driver/JsonFileDriver.php`:
```php
public function lastInsertId(): string|int
{
    return ''; // File driver has no auto-increment
}
```

- [ ] **Step 8: Update DataManager::save() to set auto-increment ID on model**

In `packages/data/src/DataManager.php`:

```php
public function save(Model $model): void
{
    $meta = ModelMetadata::for($model::class);
    $driver = $this->resolveDriver($meta->storage);
    $data = $model->toArray();
    $id = $data[$meta->idField] ?? null;
    $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

    $driver->save($meta->table, $id ?? '', $data, $meta->idField);

    if ($isEmpty) {
        $newId = $driver->lastInsertId();
        if ($newId !== '' && $newId !== 0) {
            $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
        }
    }
}
```

- [ ] **Step 9: Run tests**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/data/tests/`
Expected: All data tests pass

- [ ] **Step 10: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All 540+ tests pass

- [ ] **Step 11: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/data/
git commit -m "feat(data): support auto-increment IDs in save()

PdoDriver::save() now detects empty IDs (0, null, '') and uses plain
INSERT instead of upsert, letting the database assign auto-increment
values. DataManager reads back lastInsertId and sets it on the model."
```

---

## Task 3: Catch-All Attribute Route Params

**Context:** `AttributeRouteScanner::buildRegex()` compiles `{param}` to `[^/]+` which can't match nested paths with slashes. Need `{param...}` syntax for catch-all.

**Files:**
- Modify: `packages/routing/src/AttributeRouteScanner.php`
- Test: `packages/routing/tests/AttributeRouteScannerTest.php`

- [ ] **Step 1: Write failing test**

In `packages/routing/tests/AttributeRouteScannerTest.php`, add a test controller and test:

```php
#[Route('/img')]
class CatchAllController
{
    #[Get('/{preset}/{path...}')]
    public function serve(): void {}
}

// In the test class:
public function test_catch_all_param_with_ellipsis(): void
{
    $scanner = new AttributeRouteScanner();
    $entries = $scanner->scanClass(CatchAllController::class);

    $this->assertCount(1, $entries);
    $entry = $entries[0];

    $this->assertSame('/img/{preset}/{path...}', $entry->pattern);
    $this->assertContains('preset', $entry->paramNames);
    $this->assertContains('path', $entry->paramNames);
    $this->assertTrue($entry->isCatchAll);

    // Regex should match nested paths
    $this->assertMatchesRegularExpression($entry->regex, '/img/cover-thumb/games/cuvee/cover.jpg');
    preg_match($entry->regex, '/img/cover-thumb/games/cuvee/cover.jpg', $matches);
    $this->assertSame('cover-thumb', $matches['preset']);
    $this->assertSame('games/cuvee/cover.jpg', $matches['path']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/routing/tests/AttributeRouteScannerTest.php --filter="catch_all"`
Expected: FAIL

- [ ] **Step 3: Implement catch-all in buildRegex**

In `packages/routing/src/AttributeRouteScanner.php`, modify `buildRegex()`:

```php
private function buildRegex(string $pattern, array &$paramNames): string
{
    $isCatchAll = false;

    $regex = preg_replace_callback('/\{(\w+)(\.{3})?\}/', function (array $m) use (&$paramNames, &$isCatchAll) {
        $name = $m[1];
        $paramNames[] = $name;
        if (isset($m[2]) && $m[2] === '...') {
            $isCatchAll = true;
            return '(?P<' . $name . '>.+)';
        }
        return '(?P<' . $name . '>[^/]+)';
    }, $pattern);

    return '#^' . $regex . '$#';
}
```

Also update `scanClass()` to pass `$isCatchAll` to the RouteEntry. Currently `buildRegex` returns only the regex string. Change it to also return `$isCatchAll`:

```php
// Change buildRegex signature to:
private function buildRegex(string $pattern, array &$paramNames, bool &$isCatchAll): string

// In scanClass(), where buildRegex is called:
$isCatchAll = false;
$regex = $this->buildRegex($pattern, $paramNames, $isCatchAll);

// Pass to RouteEntry:
$entries[] = new RouteEntry(
    pattern: $pattern,
    handler: $class . '@' . $method->getName(),
    method: $attr->method,
    mode: RouteMode::Action,
    middleware: $middleware,
    paramNames: $paramNames,
    regex: $regex,
    isCatchAll: $isCatchAll,
);
```

- [ ] **Step 4: Run tests**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/routing/tests/AttributeRouteScannerTest.php`
Expected: All tests pass

- [ ] **Step 5: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/routing/
git commit -m "feat(routing): support catch-all params in attribute routes

{param...} syntax compiles to (.+) regex, matching nested paths
with slashes. Sets isCatchAll on RouteEntry."
```

---

## Task 4: Request Context in Templates

**Context:** Page templates only receive `route` (params). Need access to request path, method, and HTMX detection for active nav states and conditional rendering.

**Files:**
- Modify: `packages/core/src/Application.php` (component renderer closure, ~line 822)
- Test: `packages/core/tests/ApplicationTest.php` (or a new focused test)

- [ ] **Step 1: Modify the component renderer to pass request context**

In `packages/core/src/Application.php`, in the `ensureComponentRenderer()` method (the component renderer closure), change:

```php
$html = $engine->render($templatePath, [
    'route' => (object) $route->parameters,
]);
```

To:

```php
$html = $engine->render($templatePath, [
    'route' => (object) $route->parameters,
    'request' => (object) [
        'path' => $request->getUri()->getPath(),
        'method' => $request->getMethod(),
        'isHtmx' => $request->getHeaderLine('HX-Request') === 'true',
        'query' => $request->getQueryParams(),
    ],
]);
```

- [ ] **Step 2: Run full test suite to ensure no regressions**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/core/src/Application.php
git commit -m "feat(core): pass request context to page templates

Templates now receive a 'request' object with path, method, isHtmx,
and query properties alongside the existing 'route' params."
```

---

## Task 5: Raw CSRF Token Value

**Context:** `csrf_token()` returns `<input type="hidden" ...>` instead of the raw token value. Need separate functions: `csrf_token()` for the raw value, `csrf_field()` for the HTML input.

**Files:**
- Modify: `packages/auth/src/AuthExtensionProvider.php`
- Test: `packages/auth/tests/AuthExtensionProviderTest.php` (create if doesn't exist)

- [ ] **Step 1: Check if test file exists**

```bash
ls packages/auth/tests/AuthExtensionProviderTest.php 2>/dev/null || echo "needs creating"
```

- [ ] **Step 2: Write test for csrf_token returning raw value and csrf_field returning HTML**

```php
<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthExtensionProvider;
use Preflow\Auth\AuthManager;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Csrf\CsrfToken;

final class AuthExtensionProviderTest extends TestCase
{
    public function test_csrf_token_returns_raw_value(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('_csrf_token')->willReturn('test-token-value');

        $authManager = $this->createStub(AuthManager::class);
        $provider = new AuthExtensionProvider($authManager, $session);

        $functions = $provider->getTemplateFunctions();
        $csrfToken = null;
        foreach ($functions as $fn) {
            if ($fn->name === 'csrf_token') {
                $csrfToken = $fn;
                break;
            }
        }

        $this->assertNotNull($csrfToken);
        $result = ($csrfToken->callable)();
        // Should be a raw string, not HTML
        $this->assertStringNotContainsString('<input', $result);
    }

    public function test_csrf_field_returns_html_input(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('_csrf_token')->willReturn('test-token-value');

        $authManager = $this->createStub(AuthManager::class);
        $provider = new AuthExtensionProvider($authManager, $session);

        $functions = $provider->getTemplateFunctions();
        $csrfField = null;
        foreach ($functions as $fn) {
            if ($fn->name === 'csrf_field') {
                $csrfField = $fn;
                break;
            }
        }

        $this->assertNotNull($csrfField, 'csrf_field function should exist');
        $result = ($csrfField->callable)();
        $this->assertStringContainsString('<input', $result);
        $this->assertStringContainsString('_csrf_token', $result);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/auth/tests/AuthExtensionProviderTest.php`
Expected: FAIL (csrf_token still returns HTML, csrf_field doesn't exist)

- [ ] **Step 4: Implement the split**

In `packages/auth/src/AuthExtensionProvider.php`, modify `getTemplateFunctions()`:

```php
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
            callable: fn () => $this->csrfValue(),
            isSafe: false,
        ),
        new TemplateFunctionDefinition(
            name: 'csrf_field',
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

private function csrfValue(): string
{
    if ($this->session === null) {
        return '';
    }
    $token = CsrfToken::fromSession($this->session);
    return $token?->getValue() ?? '';
}

private function csrfField(): string
{
    $value = $this->csrfValue();
    if ($value === '') {
        return '';
    }
    $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf_token" value="' . $escaped . '">';
}
```

- [ ] **Step 5: Run tests**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/auth/tests/`
Expected: All auth tests pass

- [ ] **Step 6: Update existing tests that may reference csrf_token expecting HTML**

Search for any tests using `csrf_token` and update expectations if needed:
```bash
grep -rn "csrf_token" packages/*/tests/ | grep -v ".phpunit"
```

- [ ] **Step 7: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 8: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/auth/
git commit -m "feat(auth): split csrf_token() into raw value and csrf_field()

csrf_token() now returns the raw token string (for meta tags, JS, headers).
csrf_field() returns the HTML hidden input (for forms).
Breaking change: code using csrf_token()|raw in forms should use csrf_field()|raw."
```

---

## Task 6: JSON Body Parsing Middleware

**Context:** `$request->getParsedBody()` only handles form-encoded and multipart. JSON bodies (`Content-Type: application/json`) return null. Every controller reading JSON has to manually decode.

**Files:**
- Create: `packages/core/src/Http/JsonBodyMiddleware.php`
- Test: `packages/core/tests/Http/JsonBodyMiddlewareTest.php`
- Modify: `packages/core/src/Application.php` (auto-register the middleware)

- [ ] **Step 1: Write failing test**

Create `packages/core/tests/Http/JsonBodyMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Http\JsonBodyMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyMiddlewareTest extends TestCase
{
    public function test_parses_json_body(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('{"name":"test","count":42}');
        $request = $factory->createServerRequest('POST', '/api/data')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $middleware = new JsonBodyMiddleware();

        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->captured = $request->getParsedBody();
                return new \Nyholm\Psr7\Response(200);
            }
        };

        $middleware->process($request, $handler);

        $this->assertIsArray($captured);
        $this->assertSame('test', $captured['name']);
        $this->assertSame(42, $captured['count']);
    }

    public function test_ignores_non_json_requests(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/form')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded');

        $middleware = new JsonBodyMiddleware();

        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->captured = $request->getParsedBody();
                return new \Nyholm\Psr7\Response(200);
            }
        };

        $middleware->process($request, $handler);

        $this->assertNull($captured);
    }

    public function test_handles_invalid_json_gracefully(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('not valid json{');
        $request = $factory->createServerRequest('POST', '/api/data')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $middleware = new JsonBodyMiddleware();

        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->captured = $request->getParsedBody();
                return new \Nyholm\Psr7\Response(200);
            }
        };

        $middleware->process($request, $handler);

        // Invalid JSON should not populate parsed body
        $this->assertNull($captured);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/core/tests/Http/JsonBodyMiddlewareTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Implement JsonBodyMiddleware**

Create `packages/core/src/Http/JsonBodyMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit packages/core/tests/Http/JsonBodyMiddlewareTest.php`
Expected: All 3 tests pass

- [ ] **Step 5: Auto-register in Application**

In `packages/core/src/Application.php`, in the middleware pipeline setup (inside `handle()` or `boot()`), add `JsonBodyMiddleware` as an early middleware — before session, CSRF, auth. Find where the pipeline is built and add:

```php
$this->pipeline->pipe(new \Preflow\Core\Http\JsonBodyMiddleware());
```

This should be one of the first middleware in the pipeline, before any middleware that reads `$request->getParsedBody()`.

- [ ] **Step 6: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/core/src/Http/JsonBodyMiddleware.php packages/core/tests/Http/JsonBodyMiddlewareTest.php packages/core/src/Application.php
git commit -m "feat(core): add JsonBodyMiddleware for automatic JSON parsing

Detects Content-Type: application/json and populates getParsedBody()
with decoded JSON. Auto-registered as early middleware. Invalid JSON
is silently ignored (parsed body remains null)."
```
