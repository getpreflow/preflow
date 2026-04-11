# Phase 9: Developer Tools — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a DebugCollector for request-scoped profiling, a rich error page with source context and debug data, and an inspector toolbar injected via middleware in dev mode.

**Architecture:** A central `DebugCollector` in `preflow/core` receives query logs, component render times, and route info from subsystems. `DevErrorRenderer` is rewritten to show source context, stack frames, and collector data. `DebugToolbarMiddleware` in `preflow/devtools` injects a fixed bottom bar with expandable panels before `</body>` on HTML responses.

**Tech Stack:** PHP 8.5, PSR-7/PSR-15, PHPUnit

---

## Phase 1: Debug Infrastructure

### Task 1: DebugCollector

**Files:**
- Create: `packages/core/src/Debug/DebugCollector.php`
- Test: `packages/core/tests/Debug/DebugCollectorTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Debug;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;

final class DebugCollectorTest extends TestCase
{
    private DebugCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DebugCollector();
    }

    public function test_log_query(): void
    {
        $this->collector->logQuery('SELECT * FROM posts', [], 2.5, 'sqlite');

        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('SELECT * FROM posts', $queries[0]['sql']);
        $this->assertSame(2.5, $queries[0]['duration_ms']);
        $this->assertSame('sqlite', $queries[0]['driver']);
    }

    public function test_log_multiple_queries(): void
    {
        $this->collector->logQuery('SELECT 1', [], 1.0);
        $this->collector->logQuery('SELECT 2', [], 2.0);
        $this->collector->logQuery('SELECT 3', [], 3.0);

        $this->assertCount(3, $this->collector->getQueries());
    }

    public function test_get_query_time(): void
    {
        $this->collector->logQuery('SELECT 1', [], 1.5);
        $this->collector->logQuery('SELECT 2', [], 2.5);

        $this->assertSame(4.0, $this->collector->getQueryTime());
    }

    public function test_log_component(): void
    {
        $this->collector->logComponent('App\\Counter', 'Counter-abc', 3.2, ['count' => 0]);

        $components = $this->collector->getComponents();
        $this->assertCount(1, $components);
        $this->assertSame('App\\Counter', $components[0]['class']);
        $this->assertSame('Counter-abc', $components[0]['id']);
        $this->assertSame(3.2, $components[0]['duration_ms']);
        $this->assertSame(['count' => 0], $components[0]['props']);
    }

    public function test_set_route(): void
    {
        $this->collector->setRoute('component', 'pages/blog/index.twig', ['slug' => 'hello']);

        $route = $this->collector->getRoute();
        $this->assertSame('component', $route['mode']);
        $this->assertSame('pages/blog/index.twig', $route['handler']);
        $this->assertSame(['slug' => 'hello'], $route['parameters']);
    }

    public function test_route_defaults_to_null(): void
    {
        $this->assertNull($this->collector->getRoute());
    }

    public function test_set_assets(): void
    {
        $this->collector->setAssets(3, 2, 2100, 1400);

        $assets = $this->collector->getAssets();
        $this->assertSame(3, $assets['css_count']);
        $this->assertSame(2, $assets['js_count']);
        $this->assertSame(2100, $assets['css_bytes']);
        $this->assertSame(1400, $assets['js_bytes']);
    }

    public function test_get_total_time_is_positive(): void
    {
        usleep(1000); // 1ms
        $time = $this->collector->getTotalTime();
        $this->assertGreaterThan(0.0, $time);
    }

    public function test_empty_state(): void
    {
        $this->assertSame([], $this->collector->getQueries());
        $this->assertSame([], $this->collector->getComponents());
        $this->assertNull($this->collector->getRoute());
        $this->assertSame(0.0, $this->collector->getQueryTime());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/core/tests/Debug/DebugCollectorTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create DebugCollector**

Create `packages/core/src/Debug/DebugCollector.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Debug;

final class DebugCollector
{
    private float $startTime;

    /** @var array<int, array{sql: string, bindings: array, duration_ms: float, driver: string}> */
    private array $queries = [];

    /** @var array<int, array{class: string, id: string, duration_ms: float, props: array}> */
    private array $components = [];

    /** @var ?array{mode: string, handler: string, parameters: array} */
    private ?array $route = null;

    /** @var array{css_count: int, js_count: int, css_bytes: int, js_bytes: int} */
    private array $assets = ['css_count' => 0, 'js_count' => 0, 'css_bytes' => 0, 'js_bytes' => 0];

    public function __construct()
    {
        $this->startTime = hrtime(true);
    }

    public function logQuery(string $sql, array $bindings, float $durationMs, string $driver = 'default'): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration_ms' => $durationMs,
            'driver' => $driver,
        ];
    }

    public function logComponent(string $class, string $id, float $durationMs, array $props = []): void
    {
        $this->components[] = [
            'class' => $class,
            'id' => $id,
            'duration_ms' => $durationMs,
            'props' => $props,
        ];
    }

    public function setRoute(string $mode, string $handler, array $parameters = []): void
    {
        $this->route = [
            'mode' => $mode,
            'handler' => $handler,
            'parameters' => $parameters,
        ];
    }

    public function setAssets(int $cssCount, int $jsCount, int $cssBytes, int $jsBytes): void
    {
        $this->assets = [
            'css_count' => $cssCount,
            'js_count' => $jsCount,
            'css_bytes' => $cssBytes,
            'js_bytes' => $jsBytes,
        ];
    }

    /** @return array<int, array{sql: string, bindings: array, duration_ms: float, driver: string}> */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /** @return array<int, array{class: string, id: string, duration_ms: float, props: array}> */
    public function getComponents(): array
    {
        return $this->components;
    }

    /** @return ?array{mode: string, handler: string, parameters: array} */
    public function getRoute(): ?array
    {
        return $this->route;
    }

    /** @return array{css_count: int, js_count: int, css_bytes: int, js_bytes: int} */
    public function getAssets(): array
    {
        return $this->assets;
    }

    public function getTotalTime(): float
    {
        return (hrtime(true) - $this->startTime) / 1_000_000;
    }

    public function getQueryTime(): float
    {
        return array_sum(array_column($this->queries, 'duration_ms'));
    }
}
```

- [ ] **Step 4: Add Core test suite to phpunit.xml if not present**

Check `phpunit.xml` for a Core testsuite entry. If missing, add:
```xml
        <testsuite name="Core">
            <directory>packages/core/tests</directory>
        </testsuite>
```

And add to `<source><include>` if not present:
```xml
            <directory>packages/core/src</directory>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/core/tests/Debug/DebugCollectorTest.php`
Expected: 8 tests, all PASS

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Debug/DebugCollector.php packages/core/tests/Debug/DebugCollectorTest.php phpunit.xml
git commit -m "feat(core): add DebugCollector for request-scoped profiling data"
```

---

### Task 2: PdoDriver query logging

**Files:**
- Modify: `packages/data/src/Driver/PdoDriver.php`
- Modify: `packages/data/src/Driver/SqliteDriver.php`
- Modify: `packages/data/src/Driver/MysqlDriver.php`
- Test: `packages/data/tests/Driver/PdoDriverDebugTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Query;

final class PdoDriverDebugTest extends TestCase
{
    private \PDO $pdo;
    private DebugCollector $collector;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, name TEXT)');

        $this->collector = new DebugCollector();
        $this->driver = new SqliteDriver($this->pdo, $this->collector);
    }

    public function test_save_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);

        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('INSERT', $queries[0]['sql']);
        $this->assertGreaterThan(0, $queries[0]['duration_ms']);
    }

    public function test_find_one_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);
        $this->driver->findOne('items', 'id-1');

        $queries = $this->collector->getQueries();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('SELECT', $queries[1]['sql']);
    }

    public function test_find_many_logs_query(): void
    {
        $this->driver->findMany('items', new Query());

        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('SELECT', $queries[0]['sql']);
    }

    public function test_delete_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);
        $this->driver->delete('items', 'id-1');

        $queries = $this->collector->getQueries();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('DELETE', $queries[1]['sql']);
    }

    public function test_no_collector_no_logging(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $driver->save('items', 'id-2', ['uuid' => 'id-2', 'name' => 'Test']);

        // No exception, no logging — collector is null
        $this->assertCount(0, $this->collector->getQueries());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/Driver/PdoDriverDebugTest.php`
Expected: FAIL — SqliteDriver constructor doesn't accept DebugCollector

- [ ] **Step 3: Update PdoDriver to accept optional collector**

In `packages/data/src/Driver/PdoDriver.php`, update the constructor (line 13-17) to add a nullable collector:

```php
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
        protected readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}
```

Add a private helper method for timed execution:

```php
    protected function executeWithLogging(\PDOStatement $stmt, string $sql, array $bindings = []): void
    {
        $start = hrtime(true);
        $stmt->execute($bindings);
        if ($this->collector !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->collector->logQuery($sql, $bindings, $durationMs);
        }
    }
```

Update `findOne()` to use logging:

```php
    public function findOne(string $type, string $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
```

Update `findMany()` — the compile step returns SQL and bindings. Log the query:

```php
    public function findMany(string $type, Query $query): ResultSet
    {
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->pdo->prepare($countSql);
        $this->executeWithLogging($countStmt, $countSql, $countBindings);
        $total = (int) $countStmt->fetchColumn();

        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, $bindings);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($rows, $total);
    }
```

Update `save()` to log:

```php
    public function save(string $type, string $id, array $data): void
    {
        if (!isset($data['uuid'])) {
            $data['uuid'] = $id;
        }
        $data = array_filter($data, fn ($v) => $v !== null);

        $columns = array_keys($data);
        $sql = $this->dialect->upsertSql($type, $columns, 'uuid');
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, array_values($data));
    }
```

Update `delete()` to log:

```php
    public function delete(string $type, string $id): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
    }
```

Update `exists()` to log:

```php
    public function exists(string $type, string $id): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s = ? LIMIT 1',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
        return $stmt->fetch() !== false;
    }
```

- [ ] **Step 4: Update SqliteDriver to pass collector**

In `packages/data/src/Driver/SqliteDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }
}
```

- [ ] **Step 5: Update MysqlDriver to pass collector**

In `packages/data/src/Driver/MysqlDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class MysqlDriver extends PdoDriver
{
    public function __construct(\PDO $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");

        $dialect = new MysqlDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit packages/data/tests/Driver/PdoDriverDebugTest.php`
Expected: 5 tests, all PASS

Run: `vendor/bin/phpunit packages/data/tests/`
Expected: All existing driver tests still pass (collector defaults to null)

- [ ] **Step 7: Commit**

```bash
git add packages/data/src/Driver/PdoDriver.php packages/data/src/Driver/SqliteDriver.php packages/data/src/Driver/MysqlDriver.php packages/data/tests/Driver/PdoDriverDebugTest.php
git commit -m "feat(data): PdoDriver logs queries to optional DebugCollector"
```

---

### Task 3: ComponentRenderer render logging

**Files:**
- Modify: `packages/components/src/ComponentRenderer.php`
- Test: `packages/components/tests/ComponentRendererDebugTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class ComponentRendererDebugTest extends TestCase
{
    public function test_render_logs_component(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $collector = new DebugCollector();
        $renderer = new ComponentRenderer(
            $engine,
            new ErrorBoundary(debug: DebugLevel::Off),
            $collector,
        );

        // We need a real component to render. Use a stub that returns a template path.
        $component = new class extends \Preflow\Components\Component {
            public string $uuid = '';
            public function getTemplatePath(string $extension = 'twig'): string
            {
                return '/tmp/fake.' . $extension;
            }
        };

        $renderer->render($component);

        $components = $collector->getComponents();
        $this->assertCount(1, $components);
        $this->assertStringContainsString('class@anonymous', $components[0]['class']);
        $this->assertGreaterThanOrEqual(0.0, $components[0]['duration_ms']);
    }

    public function test_no_collector_no_logging(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $collector = new DebugCollector();
        $renderer = new ComponentRenderer(
            $engine,
            new ErrorBoundary(debug: DebugLevel::Off),
        );

        $component = new class extends \Preflow\Components\Component {
            public string $uuid = '';
            public function getTemplatePath(string $extension = 'twig'): string
            {
                return '/tmp/fake.' . $extension;
            }
        };

        $renderer->render($component);

        // Collector was not passed — should have no entries
        $this->assertCount(0, $collector->getComponents());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/components/tests/ComponentRendererDebugTest.php`
Expected: FAIL — ComponentRenderer doesn't accept DebugCollector

- [ ] **Step 3: Update ComponentRenderer**

Read `packages/components/src/ComponentRenderer.php` first. The current constructor (line 11-14):

```php
    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
    ) {}
```

Add optional collector:

```php
    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
        private readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}
```

In the `render()` method (line 19), wrap the rendering logic with timing. Find the line that calls `$this->renderTemplate($component)` (inside the try block) and wrap it:

```php
    public function render(Component $component): string
    {
        try {
            $component->resolveState();

            $start = hrtime(true);
            $html = $this->renderTemplate($component);
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }

            return $this->wrapHtml($component, $html);
        } catch (\Throwable $e) {
            return $this->errorBoundary->handle($e, $component, $this->detectPhase($e));
        }
    }
```

Apply the same pattern to `renderFragment()` and `renderResolved()` — read the current code first, then add timing around the `renderTemplate()` call in each.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit packages/components/tests/ComponentRendererDebugTest.php`
Expected: 2 tests, all PASS

Run: `vendor/bin/phpunit packages/components/tests/`
Expected: All existing tests still pass (collector defaults to null)

- [ ] **Step 5: Commit**

```bash
git add packages/components/src/ComponentRenderer.php packages/components/tests/ComponentRendererDebugTest.php
git commit -m "feat(components): ComponentRenderer logs render times to optional DebugCollector"
```

---

## Phase 2: Rich Error Page

### Task 4: Rewrite DevErrorRenderer

**Files:**
- Rewrite: `packages/core/src/Error/DevErrorRenderer.php`
- Test: `packages/core/tests/Error/DevErrorRendererTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Error\DevErrorRenderer;
use Nyholm\Psr7\ServerRequest;

final class DevErrorRendererTest extends TestCase
{
    public function test_renders_exception_class_and_message(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \InvalidArgumentException('Something went wrong');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString('InvalidArgumentException', $html);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    public function test_renders_file_and_line(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Test');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString(__FILE__, $html);
    }

    public function test_renders_source_context(): void
    {
        $renderer = new DevErrorRenderer();
        // Create exception at a known location
        $exception = new \RuntimeException('Source test');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        // Should contain source lines from this test file
        $this->assertStringContainsString('Source test', $html);
        // Should contain line numbers
        $this->assertMatchesRegularExpression('/\d+/', $html);
    }

    public function test_renders_stack_trace(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Trace test');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString('STACK TRACE', $html);
    }

    public function test_renders_request_info(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Request test');
        $request = new ServerRequest('POST', '/api/posts?page=2');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString('POST', $html);
        $this->assertStringContainsString('/api/posts', $html);
    }

    public function test_renders_debug_context_when_collector_provided(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT * FROM posts', ['published'], 2.5, 'sqlite');
        $collector->logComponent('App\\Counter', 'Counter-abc', 3.2);
        $collector->setRoute('component', 'pages/blog.twig', []);

        $renderer = new DevErrorRenderer($collector);
        $exception = new \RuntimeException('Debug test');
        $request = new ServerRequest('GET', '/blog');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString('SELECT * FROM posts', $html);
        $this->assertStringContainsString('App\\Counter', $html);
        $this->assertStringContainsString('pages/blog.twig', $html);
    }

    public function test_omits_debug_context_without_collector(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('No debug');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringNotContainsString('QUERIES', $html);
        $this->assertStringNotContainsString('COMPONENTS', $html);
    }

    public function test_escapes_html_in_message(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('<script>alert(1)</script>');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_returns_valid_html_document(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('HTML test');
        $request = new ServerRequest('GET', '/test');

        $html = $renderer->render($exception, $request, 500);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/core/tests/Error/DevErrorRendererTest.php`
Expected: FAIL — DevErrorRenderer doesn't accept DebugCollector in constructor, missing new sections

- [ ] **Step 3: Rewrite DevErrorRenderer**

Replace `packages/core/src/Error/DevErrorRenderer.php` entirely. The new renderer:

1. Accepts optional `DebugCollector` in constructor
2. Renders a full HTML document with inline dark-theme CSS
3. Sections: exception header, source context (±10 lines), stack trace (`<details>`/`<summary>`), request info, debug context (if collector available)
4. Reads source files via `file()` for context display
5. Escapes all user-supplied content with `htmlspecialchars()`

The implementation should:
- Build the HTML as a concatenated string (no template engine dependency)
- Use private helper methods: `renderSourceContext(string $file, int $line, int $range = 10): string`, `renderStackTrace(\Throwable $e): string`, `renderRequest(ServerRequestInterface $request): string`, `renderDebugContext(): string`
- The source context reader: `$lines = @file($file); if ($lines === false) return '';` then slice around the error line
- Stack trace frames: loop `$e->getTrace()`, each frame in a `<details>` with source context (±5 lines)
- Application frames (not in `vendor/`) get a distinct style
- Debug context section only rendered if `$this->collector !== null`

Full implementation code: the engineer should read the existing DevErrorRenderer (76 lines) for the current structure, then replace it entirely. The new file will be approximately 250-300 lines. The key requirement is that it satisfies the 8 tests above.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit packages/core/tests/Error/DevErrorRendererTest.php`
Expected: 8 tests, all PASS

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Error/DevErrorRenderer.php packages/core/tests/Error/DevErrorRendererTest.php
git commit -m "feat(core): rich DevErrorRenderer with source context, stack frames, debug data"
```

---

## Phase 3: Inspector Toolbar

### Task 5: DebugToolbar renderer

**Files:**
- Create: `packages/devtools/src/DebugToolbar.php`
- Test: `packages/devtools/tests/DebugToolbarTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\DebugToolbar;

final class DebugToolbarTest extends TestCase
{
    public function test_renders_html_string(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();

        $html = $toolbar->render($collector);

        $this->assertIsString($html);
        $this->assertStringContainsString('preflow-debug-toolbar', $html);
    }

    public function test_shows_query_count(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT 1', [], 1.0);
        $collector->logQuery('SELECT 2', [], 2.0);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('2 queries', $html);
    }

    public function test_shows_component_count(): void
    {
        $collector = new DebugCollector();
        $collector->logComponent('App\\Counter', 'Counter-1', 3.0);
        $collector->logComponent('App\\Nav', 'Nav-1', 1.0);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('2 components', $html);
    }

    public function test_shows_query_time(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT 1', [], 2.5);
        $collector->logQuery('SELECT 2', [], 3.5);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('6.0ms', $html);
    }

    public function test_shows_asset_stats(): void
    {
        $collector = new DebugCollector();
        $collector->setAssets(3, 2, 2100, 1400);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('3 CSS', $html);
        $this->assertStringContainsString('2 JS', $html);
    }

    public function test_shows_route_info(): void
    {
        $collector = new DebugCollector();
        $collector->setRoute('component', 'pages/blog.twig', ['slug' => 'hello']);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('pages/blog.twig', $html);
    }

    public function test_contains_toggle_script(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('toggle', $html);
    }

    public function test_flags_slow_queries(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT slow', [], 150.0);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('slow', $html);
    }

    public function test_flags_slow_components(): void
    {
        $collector = new DebugCollector();
        $collector->logComponent('App\\Slow', 'Slow-1', 75.0);

        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);

        $this->assertStringContainsString('slow', $html);
    }

    public function test_empty_collector_renders_gracefully(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();

        $html = $toolbar->render($collector);

        $this->assertStringContainsString('0 queries', $html);
        $this->assertStringContainsString('0 components', $html);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/devtools/tests/DebugToolbarTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create DebugToolbar**

Create `packages/devtools/src/DebugToolbar.php`. The class has one public method `render(DebugCollector $collector): string` that returns a self-contained HTML block.

The rendered HTML contains:
- A container div with id `preflow-debug-toolbar`
- Inline `<style>` scoped to `#preflow-debug-toolbar` (dark theme: `#1a1a2e` background, `#e94560` accent, monospace)
- A collapsed bar showing: Preflow badge, component count, query count + total time, asset stats, total time, expand toggle
- An expandable detail panel (hidden by default, shown when `.expanded` class is toggled) with sections: Components, Queries, Assets, Route
- A `<script>` block with a single click handler that toggles the `.expanded` class
- Slow query threshold: 100ms. Slow component threshold: 50ms. Both flagged with a warning indicator.
- Position: `fixed`, bottom 0, left 0, right 0, z-index 99999

The implementation should build HTML via string concatenation with private helper methods: `renderBar()`, `renderPanel()`, `renderComponentsSection()`, `renderQueriesSection()`, `renderAssetsSection()`, `renderRouteSection()`, `renderStyles()`, `renderScript()`.

The file will be approximately 200-250 lines. The key requirement is that it satisfies the 10 tests above.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit packages/devtools/tests/DebugToolbarTest.php`
Expected: 10 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/devtools/src/DebugToolbar.php packages/devtools/tests/DebugToolbarTest.php
git commit -m "feat(devtools): add DebugToolbar renderer with collapsed bar and expandable panels"
```

---

### Task 6: DebugToolbarMiddleware

**Files:**
- Create: `packages/devtools/src/Http/DebugToolbarMiddleware.php`
- Test: `packages/devtools/tests/Http/DebugToolbarMiddlewareTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\Http\DebugToolbarMiddleware;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DebugToolbarMiddlewareTest extends TestCase
{
    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function test_injects_toolbar_into_html_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);

        $body = '<html><body><p>Hello</p></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);

        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $html = (string) $result->getBody();
        $this->assertStringContainsString('preflow-debug-toolbar', $html);
        $this->assertStringContainsString('</body>', $html);
    }

    public function test_does_not_inject_into_json_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);

        $body = '{"status":"ok"}';
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $handler = $this->makeHandler($response);

        $result = $middleware->process(new ServerRequest('GET', '/api'), $handler);

        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
        $this->assertSame($body, $html);
    }

    public function test_does_not_inject_without_body_tag(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);

        $body = '<p>Fragment without body tag</p>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);

        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
    }

    public function test_preserves_status_code(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);

        $body = '<html><body>Not found</body></html>';
        $response = new Response(404, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);

        $result = $middleware->process(new ServerRequest('GET', '/missing'), $handler);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_preserves_headers(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);

        $body = '<html><body>Test</body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html', 'X-Custom' => 'value'], $body);
        $handler = $this->makeHandler($response);

        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame('value', $result->getHeaderLine('X-Custom'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/devtools/tests/Http/DebugToolbarMiddlewareTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create DebugToolbarMiddleware**

Create `packages/devtools/src/Http/DebugToolbarMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\DebugToolbar;

final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DebugCollector $collector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $toolbar = new DebugToolbar();
        $toolbarHtml = $toolbar->render($this->collector);

        $body = str_replace('</body>', $toolbarHtml . '</body>', $body);

        return $response
            ->withBody(\Nyholm\Psr7\Stream::create($body))
            ->withHeader('Content-Length', (string) strlen($body));
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit packages/devtools/tests/Http/DebugToolbarMiddlewareTest.php`
Expected: 5 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/devtools/src/Http/DebugToolbarMiddleware.php packages/devtools/tests/Http/DebugToolbarMiddlewareTest.php
git commit -m "feat(devtools): add DebugToolbarMiddleware — injects toolbar into HTML responses"
```

---

## Phase 4: Application Wiring

### Task 7: Wire DebugCollector into Application and Kernel

**Files:**
- Modify: `packages/core/src/Application.php`
- Modify: `packages/core/src/Kernel.php`

- [ ] **Step 1: Add DebugCollector creation in Application::boot()**

Read `packages/core/src/Application.php`. In the `boot()` method (line 121), the debug level is determined early. Add collector creation right after the debug level is resolved:

```php
    // Early in boot(), after $debug = DebugLevel::from(...):
    $collector = null;
    if ($debug->isDebug()) {
        $collector = new \Preflow\Core\Debug\DebugCollector();
        $this->container->instance(\Preflow\Core\Debug\DebugCollector::class, $collector);
    }
```

- [ ] **Step 2: Pass collector to driver creation**

In `bootDataLayer()`, the `createSqliteDriver()` and `createMysqlDriver()` helper methods need the collector. Update them to accept and pass it:

Update `bootDataLayer()` to retrieve collector from container:
```php
    $collector = $this->container->has(\Preflow\Core\Debug\DebugCollector::class)
        ? $this->container->get(\Preflow\Core\Debug\DebugCollector::class)
        : null;
```

Pass `$collector` to driver constructors:
```php
    'sqlite' => $this->createSqliteDriver($driverConfig, $collector),
    'mysql' => $this->createMysqlDriver($driverConfig, $collector),
```

Update `createSqliteDriver()` and `createMysqlDriver()` signatures to accept `?\Preflow\Core\Debug\DebugCollector $collector = null` and pass it to the driver constructor.

- [ ] **Step 3: Pass collector to ComponentRenderer**

In `bootComponentLayer()`, find where `ComponentRenderer` is constructed and pass the collector:

```php
    $collector = $this->container->has(\Preflow\Core\Debug\DebugCollector::class)
        ? $this->container->get(\Preflow\Core\Debug\DebugCollector::class)
        : null;

    $renderer = new \Preflow\Components\ComponentRenderer($engine, $errorBoundary, $collector);
```

- [ ] **Step 4: Pass collector to DevErrorRenderer**

In `boot()`, where the ErrorHandler is created with a renderer, pass the collector to DevErrorRenderer:

Find the line that creates DevErrorRenderer (it's inside the ErrorHandler setup) and change it to:
```php
    $renderer = $debug->isDebug()
        ? new \Preflow\Core\Error\DevErrorRenderer($collector)
        : new \Preflow\Core\Error\ProdErrorRenderer();
```

- [ ] **Step 5: Add DebugToolbarMiddleware**

In `boot()`, after all middleware is added (just before Kernel creation), add the toolbar middleware if devtools is installed and debug mode is on:

```php
    if ($debug->isDebug() && $collector !== null && class_exists(\Preflow\DevTools\Http\DebugToolbarMiddleware::class)) {
        $this->addMiddleware(new \Preflow\DevTools\Http\DebugToolbarMiddleware($collector));
    }
```

- [ ] **Step 6: Update Kernel to log route**

In `packages/core/src/Kernel.php`, the `handle()` method (line 40) matches a route. After the match, log to collector:

```php
    $route = $this->router->match($request);
    $this->collector?->setRoute($route->mode->value, $route->handler, $route->parameters);
```

Update Kernel constructor (line 28-38) to accept optional collector:

```php
    public function __construct(
        private readonly Container $container,
        private readonly RouterInterface $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly ErrorHandler $errorHandler,
        private readonly callable $actionDispatcher,
        private readonly callable $componentRenderer,
        private readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}
```

Update the Kernel instantiation in Application::boot() to pass collector.

- [ ] **Step 7: Log asset stats before response**

In `Application::handle()` or just before response emission, collect asset stats from AssetCollector:

```php
    if ($this->container->has(\Preflow\Core\Debug\DebugCollector::class) && $this->container->has(\Preflow\View\AssetCollector::class)) {
        $collector = $this->container->get(\Preflow\Core\Debug\DebugCollector::class);
        $assets = $this->container->get(\Preflow\View\AssetCollector::class);
        $collector->setAssets(
            $assets->getCssCount(),
            $assets->getJsCount(),
            $assets->getCssBytes(),
            $assets->getJsBytes(),
        );
    }
```

Note: AssetCollector may not have `getCssCount()` etc. yet. If not, add simple getter methods:
```php
    public function getCssCount(): int { return count($this->css); }
    public function getJsCount(): int { return count($this->jsBody) + count($this->jsHead) + count($this->jsInline); }
    public function getCssBytes(): int { return array_sum(array_map('strlen', $this->css)); }
    public function getJsBytes(): int { return array_sum(array_map('strlen', [...$this->jsBody, ...$this->jsHead, ...$this->jsInline])); }
```

These would go in `packages/view/src/AssetCollector.php`. Read it first to understand the property names.

- [ ] **Step 8: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 9: Commit**

```bash
git add packages/core/src/Application.php packages/core/src/Kernel.php packages/view/src/AssetCollector.php
git commit -m "feat: wire DebugCollector into Application boot — connects all subsystems"
```

---

### Task 8: Update plans README

**Files:**
- Modify: `docs/superpowers/plans/README.md`

- [ ] **Step 1: Update Phase 9 status**

In `docs/superpowers/plans/README.md`, change line 15 from:

```markdown
| 8 | `preflow/testing` | TBD | Blocked on Phases 1-7 |
```

To (if Phase 8 testing was already done — check the file first):

```markdown
| 8 | `preflow/testing` | [2026-04-11-phase-8-preflow-testing.md](2026-04-11-phase-8-preflow-testing.md) | Ready |
```

And change the Phase 9 line from:

```markdown
| 9 | `preflow/devtools` | TBD | Blocked on Phases 1-7 |
```

To:

```markdown
| 9 | `preflow/devtools` | [2026-04-11-phase-9-devtools.md](2026-04-11-phase-9-devtools.md) | Ready |
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/plans/README.md
git commit -m "docs: update plans README with Phase 9 status"
```
