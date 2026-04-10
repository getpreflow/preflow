# Phase 8: preflow/testing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the testing utilities package (`preflow/testing`) — base test case classes with helpers for testing components, routes, data operations, and security, so framework users can write tests with minimal boilerplate.

**Architecture:** Each test case class extends PHPUnit's `TestCase` and provides domain-specific helpers: `ComponentTestCase` for rendering/action testing, `RouteTestCase` for HTTP request simulation, `DataTestCase` for storage driver testing, and `SecurityTestCase` for token/CSP verification. A shared `TestApplication` bootstraps a minimal Preflow app for integration tests.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, all preflow packages

---

## File Structure

```
packages/testing/
├── src/
│   ├── ComponentTestCase.php       — render(), action(), assertSee, assertHasElement
│   ├── RouteTestCase.php           — get(), post(), assertOk, assertJson, actingAs
│   ├── DataTestCase.php            — save(), query(), forEachDriver()
│   ├── TestApplication.php         — Bootstraps minimal Preflow app for tests
├── tests/
│   ├── ComponentTestCaseTest.php
│   ├── RouteTestCaseTest.php
│   ├── DataTestCaseTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/testing/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/testing/composer.json**

```json
{
    "name": "preflow/testing",
    "description": "Preflow testing — test utilities for components, routes, data",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "phpunit/phpunit": "^11.0",
        "preflow/core": "^0.1 || @dev",
        "preflow/routing": "^0.1 || @dev",
        "preflow/view": "^0.1 || @dev",
        "preflow/components": "^0.1 || @dev",
        "preflow/data": "^0.1 || @dev",
        "preflow/htmx": "^0.1 || @dev",
        "preflow/i18n": "^0.1 || @dev",
        "nyholm/psr7": "^1.8"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Testing\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Testing\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json + phpunit.xml**

Add repository, require-dev, testsuite, source include (same pattern as previous packages).

- [ ] **Step 3: Create directories and install**

```bash
mkdir -p packages/testing/src packages/testing/tests
composer update
```

- [ ] **Step 4: Commit**

```bash
git commit -m "feat: scaffold preflow/testing package"
```

---

### Task 2: TestApplication

**Files:**
- Create: `packages/testing/src/TestApplication.php`

- [ ] **Step 1: Create TestApplication**

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\Twig\TwigEngine;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;

final class TestApplication
{
    public readonly Container $container;
    public readonly AssetCollector $assets;
    public readonly ComponentRenderer $renderer;
    public readonly ComponentToken $token;
    public readonly ResponseHeaders $responseHeaders;

    public function __construct(
        bool $debug = true,
        string $secretKey = 'test-secret-key-for-preflow-tests!',
    ) {
        $this->container = new Container();

        $nonce = new NonceGenerator();
        $this->assets = new AssetCollector($nonce);
        $this->responseHeaders = new ResponseHeaders();
        $this->token = new ComponentToken($secretKey);

        $errorBoundary = new ErrorBoundary(debug: $debug);
        $this->renderer = new ComponentRenderer(
            templateEngine: new class implements TemplateEngineInterface {
                public function render(string $template, array $context = []): string
                {
                    // Simple stub — real tests can override
                    return implode('', array_map(fn ($v) => is_string($v) ? $v : '', $context));
                }
                public function exists(string $template): bool { return true; }
            },
            errorBoundary: $errorBoundary,
        );

        // Register in container
        $this->container->instance(AssetCollector::class, $this->assets);
        $this->container->instance(ComponentRenderer::class, $this->renderer);
        $this->container->instance(ComponentToken::class, $this->token);
        $this->container->instance(ResponseHeaders::class, $this->responseHeaders);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/testing/src/TestApplication.php
git commit -m "feat(testing): add TestApplication bootstrap"
```

---

### Task 3: ComponentTestCase

**Files:**
- Create: `packages/testing/src/ComponentTestCase.php`
- Create: `packages/testing/tests/ComponentTestCaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/testing/tests/ComponentTestCaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Preflow\Components\Component;
use Preflow\Testing\ComponentTestCase;

class CounterComponent extends Component
{
    public int $count = 0;
    public string $label = '';

    public function resolveState(): void
    {
        $this->count = (int) ($this->props['initial'] ?? 0);
        $this->label = $this->props['label'] ?? 'Counter';
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params = []): void
    {
        $this->count++;
    }
}

class BrokenTestComponent extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Deliberately broken');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Fallback content</p>';
    }
}

final class ComponentTestCaseTest extends ComponentTestCase
{
    public function test_render_returns_result(): void
    {
        $result = $this->renderComponent(CounterComponent::class, ['initial' => 5, 'label' => 'Test']);

        $this->assertNotEmpty($result);
    }

    public function test_render_contains_component_id(): void
    {
        $result = $this->renderComponent(CounterComponent::class);

        $this->assertStringContainsString('CounterComponent', $result);
    }

    public function test_action_executes(): void
    {
        $component = $this->createComponent(CounterComponent::class, ['initial' => 0]);
        $component->resolveState();
        $component->handleAction('increment');

        $this->assertSame(1, $component->count);
    }

    public function test_error_boundary_catches(): void
    {
        $result = $this->renderComponent(BrokenTestComponent::class);

        $this->assertStringContainsString('Fallback content', $result);
    }

    public function test_create_component_sets_props(): void
    {
        $component = $this->createComponent(CounterComponent::class, ['initial' => 10]);
        $component->resolveState();

        $this->assertSame(10, $component->count);
    }
}
```

- [ ] **Step 2: Implement ComponentTestCase**

Create `packages/testing/src/ComponentTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\View\TemplateEngineInterface;

abstract class ComponentTestCase extends TestCase
{
    protected TestApplication $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new TestApplication();
    }

    /**
     * Create a component instance with props set.
     *
     * @template T of Component
     * @param class-string<T> $class
     * @param array<string, mixed> $props
     * @return T
     */
    protected function createComponent(string $class, array $props = []): Component
    {
        $component = new $class();
        $component->setProps($props);
        return $component;
    }

    /**
     * Render a component and return the HTML output.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    protected function renderComponent(string $class, array $props = []): string
    {
        $component = $this->createComponent($class, $props);
        return $this->app->renderer->render($component);
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/phpunit packages/testing/tests/ComponentTestCaseTest.php
```

Expected: All 5 tests pass.

- [ ] **Step 4: Commit**

```bash
git add packages/testing/src/ComponentTestCase.php packages/testing/tests/ComponentTestCaseTest.php
git commit -m "feat(testing): add ComponentTestCase with render and action helpers"
```

---

### Task 4: RouteTestCase

**Files:**
- Create: `packages/testing/src/RouteTestCase.php`
- Create: `packages/testing/src/TestResponse.php`
- Create: `packages/testing/tests/RouteTestCaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/testing/tests/RouteTestCaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Response;
use Preflow\Testing\RouteTestCase;
use Preflow\Testing\TestResponse;

final class RouteTestCaseTest extends RouteTestCase
{
    public function test_get_returns_test_response(): void
    {
        $response = $this->createTestResponse(new Response(200, [], 'Hello'));

        $this->assertInstanceOf(TestResponse::class, $response);
    }

    public function test_assert_ok(): void
    {
        $response = $this->createTestResponse(new Response(200));

        $response->assertOk();
    }

    public function test_assert_status(): void
    {
        $response = $this->createTestResponse(new Response(404));

        $response->assertStatus(404);
    }

    public function test_assert_redirect(): void
    {
        $response = $this->createTestResponse(new Response(302, ['Location' => '/login']));

        $response->assertRedirect('/login');
    }

    public function test_assert_see(): void
    {
        $response = $this->createTestResponse(new Response(200, [], '<h1>Welcome</h1>'));

        $response->assertSee('Welcome');
    }

    public function test_assert_not_see(): void
    {
        $response = $this->createTestResponse(new Response(200, [], '<h1>Welcome</h1>'));

        $response->assertNotSee('Goodbye');
    }

    public function test_assert_header(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['X-Custom' => 'value'])
        );

        $response->assertHeader('X-Custom', 'value');
    }

    public function test_assert_header_contains(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'])
        );

        $response->assertHeaderContains('Content-Type', 'text/html');
    }

    public function test_assert_json(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{"key":"value"}')
        );

        $response->assertJson();
    }

    public function test_body_access(): void
    {
        $response = $this->createTestResponse(new Response(200, [], 'body content'));

        $this->assertSame('body content', $response->body());
    }
}
```

- [ ] **Step 2: Implement TestResponse**

Create `packages/testing/src/TestResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

final class TestResponse
{
    private readonly string $body;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {
        $this->body = (string) $response->getBody();
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function assertOk(): self
    {
        Assert::assertSame(200, $this->status(), "Expected status 200, got {$this->status()}.");
        return $this;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected status {$status}, got {$this->status()}.");
        return $this;
    }

    public function assertRedirect(string $uri): self
    {
        Assert::assertTrue(
            $this->status() >= 300 && $this->status() < 400,
            "Expected redirect status, got {$this->status()}."
        );
        Assert::assertSame($uri, $this->response->getHeaderLine('Location'));
        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body, "Failed asserting response contains '{$text}'.");
        return $this;
    }

    public function assertNotSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->body, "Response should not contain '{$text}'.");
        return $this;
    }

    public function assertHeader(string $name, string $value): self
    {
        Assert::assertSame($value, $this->response->getHeaderLine($name));
        return $this;
    }

    public function assertHeaderContains(string $name, string $substring): self
    {
        Assert::assertStringContainsString(
            $substring,
            $this->response->getHeaderLine($name),
            "Header '{$name}' does not contain '{$substring}'."
        );
        return $this;
    }

    public function assertJson(): self
    {
        Assert::assertStringContainsString('json', $this->response->getHeaderLine('Content-Type'));
        json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        return $this;
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }
}
```

- [ ] **Step 3: Implement RouteTestCase**

Create `packages/testing/src/RouteTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class RouteTestCase extends TestCase
{
    protected function createTestResponse(ResponseInterface $response): TestResponse
    {
        return new TestResponse($response);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/testing/tests/RouteTestCaseTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/testing/src/RouteTestCase.php packages/testing/src/TestResponse.php packages/testing/tests/RouteTestCaseTest.php
git commit -m "feat(testing): add RouteTestCase and TestResponse with assertion helpers"
```

---

### Task 5: DataTestCase

**Files:**
- Create: `packages/testing/src/DataTestCase.php`
- Create: `packages/testing/tests/DataTestCaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/testing/tests/DataTestCaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Testing\DataTestCase;

#[Entity(table: 'notes', storage: 'sqlite')]
class TestNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $content = '';

    #[Field]
    public string $status = 'draft';
}

final class DataTestCaseTest extends DataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelMetadata::clearCache();
        $this->createTable('notes', function ($table) {
            $table->uuid('uuid')->primary();
            $table->string('content');
            $table->string('status');
        });
    }

    public function test_sqlite_driver_available(): void
    {
        $this->assertNotNull($this->getSqliteDriver());
    }

    public function test_json_driver_available(): void
    {
        $this->assertNotNull($this->getJsonDriver());
    }

    public function test_save_and_find(): void
    {
        $note = new TestNote();
        $note->uuid = 'note-1';
        $note->content = 'Hello';
        $note->status = 'published';

        $this->dataManager()->save($note);

        $found = $this->dataManager()->find(TestNote::class, 'note-1');
        $this->assertSame('Hello', $found->content);
    }

    public function test_query(): void
    {
        $this->saveNote('1', 'First', 'published');
        $this->saveNote('2', 'Second', 'draft');

        $result = $this->dataManager()
            ->query(TestNote::class)
            ->where('status', 'published')
            ->get();

        $this->assertSame(1, $result->total());
    }

    private function saveNote(string $id, string $content, string $status): void
    {
        $note = new TestNote();
        $note->uuid = $id;
        $note->content = $content;
        $note->status = $status;
        $this->dataManager()->save($note);
    }
}
```

- [ ] **Step 2: Implement DataTestCase**

Create `packages/testing/src/DataTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

abstract class DataTestCase extends TestCase
{
    private ?\PDO $pdo = null;
    private ?string $jsonDir = null;
    private ?SqliteDriver $sqliteDriver = null;
    private ?JsonFileDriver $jsonDriver = null;
    private ?DataManager $dataManager = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->jsonDir = sys_get_temp_dir() . '/preflow_test_data_' . uniqid();
        mkdir($this->jsonDir, 0755, true);

        $this->sqliteDriver = new SqliteDriver($this->pdo);
        $this->jsonDriver = new JsonFileDriver($this->jsonDir);
        $this->dataManager = null; // reset
    }

    protected function tearDown(): void
    {
        if ($this->jsonDir !== null && is_dir($this->jsonDir)) {
            $this->deleteDir($this->jsonDir);
        }
        parent::tearDown();
    }

    protected function getSqliteDriver(): SqliteDriver
    {
        return $this->sqliteDriver;
    }

    protected function getJsonDriver(): JsonFileDriver
    {
        return $this->jsonDriver;
    }

    protected function dataManager(): DataManager
    {
        if ($this->dataManager === null) {
            $this->dataManager = new DataManager([
                'sqlite' => $this->sqliteDriver,
                'json' => $this->jsonDriver,
                'default' => $this->sqliteDriver,
            ]);
        }
        return $this->dataManager;
    }

    protected function createTable(string $name, callable $callback): void
    {
        $schema = new Schema($this->pdo);
        $schema->create($name, $callback);
    }

    protected function getPdo(): \PDO
    {
        return $this->pdo;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/phpunit packages/testing/tests/DataTestCaseTest.php
```

Expected: All 4 tests pass.

- [ ] **Step 4: Commit**

```bash
git add packages/testing/src/DataTestCase.php packages/testing/tests/DataTestCaseTest.php
git commit -m "feat(testing): add DataTestCase with SQLite and JSON driver helpers"
```

---

### Task 6: Full Verification

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass across all 8 packages.

- [ ] **Step 2: Commit plans if needed**

---

## Phase 8 Deliverables

| Component | What It Does |
|---|---|
| `TestApplication` | Bootstraps minimal Preflow app with container, renderer, token service |
| `ComponentTestCase` | `createComponent()`, `renderComponent()` with error boundary |
| `TestResponse` | Fluent assertions: `assertOk()`, `assertSee()`, `assertJson()`, `assertHeader()`, etc. |
| `RouteTestCase` | Creates `TestResponse` from PSR-7 response |
| `DataTestCase` | In-memory SQLite + temp JSON dir, `createTable()`, `dataManager()` |
