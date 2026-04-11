# Phase 9: Developer Tools — Rich Error Pages + Inspector Toolbar

## Goal

Give developers full visibility into what's happening during a request. A rich error page shows source context, stack frames, request details, and debug data (queries, component renders) when something goes wrong. An inspector toolbar at the bottom of every page shows the same debug data on successful requests.

## Architecture

Both features share a single `DebugCollector` in `preflow/core` that subsystems push data to during a request. The collector is only instantiated in dev mode — zero overhead in production. The error page reads from it when rendering exceptions. The toolbar reads from it via a PSR-15 middleware that injects HTML before `</body>`.

```
DebugCollector (preflow/core)
├── PdoDriver pushes: query SQL, bindings, duration, driver name
├── ComponentRenderer pushes: class, ID, duration, props
├── Kernel pushes: route mode, handler, parameters
└── Application pushes: asset stats from AssetCollector

DevErrorRenderer reads DebugCollector
  → renders rich error page (single scroll, stacked sections)

DebugToolbarMiddleware reads DebugCollector
  → DebugToolbar renders HTML
  → middleware injects before </body>
```

No external dependencies. All HTML/CSS is self-contained inline output. The toolbar uses native `<details>`/`<summary>` elements and minimal inline JS (one click handler) — no build tools, no external assets.

---

## 1. DebugCollector

Central data store for request-scoped debug information. Lives in `preflow/core` so any package can push to it without depending on `preflow/devtools`.

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
    public function getQueries(): array { return $this->queries; }

    /** @return array<int, array{class: string, id: string, duration_ms: float, props: array}> */
    public function getComponents(): array { return $this->components; }

    /** @return ?array{mode: string, handler: string, parameters: array} */
    public function getRoute(): ?array { return $this->route; }

    /** @return array{css_count: int, js_count: int, css_bytes: int, js_bytes: int} */
    public function getAssets(): array { return $this->assets; }

    /** Total request time in milliseconds since collector was created. */
    public function getTotalTime(): float
    {
        return (hrtime(true) - $this->startTime) / 1_000_000;
    }

    /** Sum of all logged query durations in milliseconds. */
    public function getQueryTime(): float
    {
        return array_sum(array_column($this->queries, 'duration_ms'));
    }
}
```

### Integration Hooks

Each subsystem receives the collector as an **optional nullable constructor parameter**. When null, no logging occurs — zero overhead.

**PdoDriver** — wraps statement execution with timing:

```php
// In PdoDriver, existing methods gain timing:
$start = hrtime(true);
$stmt->execute($bindings);
$durationMs = (hrtime(true) - $start) / 1_000_000;
$this->collector?->logQuery($sql, $bindings, $durationMs);
```

The collector is passed through: `PdoDriver` constructor gains `?DebugCollector $collector = null`. `SqliteDriver` and `MysqlDriver` constructors gain the same and pass it to parent.

**ComponentRenderer** — wraps render with timing:

```php
$start = hrtime(true);
$html = $this->renderTemplate($component);
$durationMs = (hrtime(true) - $start) / 1_000_000;
$this->collector?->logComponent($component::class, $component->getComponentId(), $durationMs, $component->getProps());
```

**Kernel** — logs route after matching:

```php
$route = $this->router->match($request);
$this->collector?->setRoute($route->mode->value, $route->handler, $route->parameters);
```

**Application** — logs asset stats before response emission, by reading from AssetCollector.

---

## 2. Rich Error Page (DevErrorRenderer)

Replace the current `DevErrorRenderer` with a rich single-scroll page. Same `ErrorRendererInterface` contract.

### Constructor

```php
public function __construct(
    private readonly ?DebugCollector $collector = null,
) {}
```

### Rendered Sections (top to bottom)

**1. Exception Header**
- Exception class name (e.g., `InvalidArgumentException`)
- Message
- File path : line number
- Red accent, dark background

**2. Source Context**
- Read the source file via `file($path)` 
- Show 10 lines before and 10 lines after the error line
- Error line highlighted with red background and left border
- Line numbers in gutter
- Monospace font, dark background
- If file unreadable, show just the file:line reference

**3. Stack Trace**
- Each frame: class->method() at file:line
- Application frames (files under the project root) styled differently from vendor frames
- Each frame wrapped in `<details><summary>` — click to expand and see source context for that frame
- Source context per frame: ±5 lines around the frame's line number

**4. Request Details**
- HTTP method + URI
- Query parameters (if any)
- Request headers in a `<details>` block (collapsed by default)
- Content type and body preview for POST requests

**5. Debug Context** (only if DebugCollector is available)
- **Queries**: SQL with bindings inline, duration per query, total query time. Slow queries (>100ms) highlighted.
- **Components**: class name, component ID, render duration. Slow renders (>50ms) highlighted.
- **Route**: mode (component/action), handler, parameters.
- **Assets**: CSS block count, JS block count.

### Styling

All CSS is inline in the rendered HTML. Dark theme:
- Background: `#0f0f23` (code areas), `#1a1a2e` (sections), `#16213e` (subsections)
- Accent: `#e94560` (error highlights, headers)
- Text: `#eee` (primary), `#aaa` (secondary), `#555` (line numbers)
- Code: `#98c379` (SQL), `#e5c07b` (values), monospace

No external CSS files. No JavaScript required — `<details>` elements handle expand/collapse natively.

---

## 3. Inspector Toolbar

### DebugToolbar (renderer)

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools;

use Preflow\Core\Debug\DebugCollector;

final class DebugToolbar
{
    /**
     * Render the toolbar HTML block.
     * Returns self-contained HTML with inline CSS and minimal JS.
     */
    public function render(DebugCollector $collector): string
}
```

Returns a self-contained HTML block with two visual states:

**Collapsed bar** (default state):
- Fixed to viewport bottom, `z-index: 99999`
- Dark background (`#1a1a2e`), top border accent (`#e94560`)
- Shows: Preflow badge, component count, query count + total query time, asset stats, total request time
- "▲ expand" toggle on the right

**Expanded panel** (toggled by clicking the bar):
- Slides up from the bar, max 50vh height, scrollable
- Four sections, always visible (stacked, matching the error page's single-scroll approach):

  **Components** — flat list:
  ```
  Counter          Counter-abc123     3.2ms
  BlogGrid         BlogGrid-def456   45.3ms  ⚠ slow
  BlogPost ×6      BlogPost-*         0.8ms avg
  ```
  Renders >50ms flagged with warning indicator.

  **Queries** — list with SQL and timing:
  ```
  SELECT * FROM "posts" WHERE "status" = ?  [published]    2.1ms
  SELECT * FROM "posts" WHERE "uuid" = ?    [abc-123]      0.8ms
  ```
  Queries >100ms flagged. Duplicate SQL detected (same query string, different bindings) and noted.

  **Assets** — summary:
  ```
  CSS: 3 blocks (2.1 KB)  |  JS: 2 blocks (1.4 KB)  |  Head tags: 1
  ```

  **Route** — one line:
  ```
  Component  →  pages/blog/index.twig  |  {slug: "hello-world"}
  ```

**Toggle mechanism**: one inline `<script>` block. Clicking the bar toggles a CSS class on the container. No external JS.

### DebugToolbarMiddleware

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

        // Only inject into HTML responses
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();

        // Only inject if there's a </body> to inject before
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $toolbar = new DebugToolbar();
        $toolbarHtml = $toolbar->render($this->collector);

        $body = str_replace('</body>', $toolbarHtml . '</body>', $body);

        return $response->withBody(/* new stream from $body */);
    }
}
```

### Application Wiring

In `Application::boot()`, when `$debug->isDebug()`:

1. Create `DebugCollector` and register in container
2. Pass collector to `SqliteDriver` / `MysqlDriver` constructors
3. Pass collector to `ComponentRenderer` constructor
4. Pass collector to `DevErrorRenderer` constructor
5. Pass collector to `Kernel` (for route logging)
6. If `preflow/devtools` is installed, add `DebugToolbarMiddleware` as the **last** middleware in the pipeline

Production mode: none of this happens. No collector created, no middleware added, no overhead.

---

## 4. File Structure

New and modified files:

```
packages/core/src/
  Debug/
    DebugCollector.php                    (new)
  Error/
    DevErrorRenderer.php                  (rewrite)
  Application.php                         (modified — collector wiring)
  Kernel.php                              (modified — route logging)

packages/data/src/
  Driver/
    PdoDriver.php                         (modified — optional collector, query timing)
    SqliteDriver.php                      (modified — pass collector to parent)
    MysqlDriver.php                       (modified — pass collector to parent)

packages/components/src/
  ComponentRenderer.php                   (modified — optional collector, render timing)

packages/devtools/src/
  DebugToolbar.php                        (new)
  Http/
    DebugToolbarMiddleware.php            (new)

Tests:
  packages/core/tests/Debug/DebugCollectorTest.php        (new)
  packages/core/tests/Error/DevErrorRendererTest.php      (new)
  packages/devtools/tests/DebugToolbarTest.php            (new)
  packages/devtools/tests/Http/DebugToolbarMiddlewareTest.php  (new)
```

---

## 5. Not In Scope

- N+1 query detection
- Component tree nesting (parent-child hierarchy)
- Clickable file paths (open in editor)
- Query EXPLAIN plans
- Hot reload / file watching
- Persistent debug data across requests
- Profiling overhead controls / sampling
- Additional CLI commands (routes:cache, config:check, test)
