# Phase 2: preflow/routing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the routing package (`preflow/routing`) — hybrid router with file-based page routes and PHP 8.5 attribute-based controller routes, implementing `RouterInterface` from `preflow/core`.

**Architecture:** The router collects route entries from two scanners (file-based and attribute-based), stores them in a `RouteCollection`, and matches incoming requests against them. File-based routes resolve to `RouteMode::Component`, attribute routes to `RouteMode::Action`. Routes can be compiled to a cached PHP array for production.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, preflow/core (Route, RouteMode, RouterInterface, NotFoundHttpException)

---

## File Structure

```
packages/routing/
├── src/
│   ├── Attributes/
│   │   ├── Route.php                   — #[Route('/prefix')] on controller classes
│   │   ├── Get.php                     — #[Get('/path')]
│   │   ├── Post.php                    — #[Post('/path')]
│   │   ├── Put.php                     — #[Put('/path')]
│   │   ├── Delete.php                  — #[Delete('/path')]
│   │   ├── Patch.php                   — #[Patch('/path')]
│   │   ├── HttpMethod.php             — Base attribute class for method attributes
│   │   └── Middleware.php              — #[Middleware(SomeMiddleware::class)]
│   ├── RouteEntry.php                  — Internal route representation (pattern, handler, mode, etc.)
│   ├── RouteCollection.php             — Holds all discovered route entries
│   ├── FileRouteScanner.php            — Scans pages/ directory, produces RouteEntry[]
│   ├── AttributeRouteScanner.php       — Scans controller classes, produces RouteEntry[]
│   ├── RouteMatcher.php                — Matches a request URI+method to a RouteEntry
│   ├── Router.php                      — Implements RouterInterface, orchestrates everything
│   └── RouteCompiler.php              — Compiles RouteCollection to cached PHP file
├── tests/
│   ├── Attributes/
│   │   └── AttributeTest.php           — Tests attribute instantiation
│   ├── FileRouteScannerTest.php
│   ├── AttributeRouteScannerTest.php
│   ├── RouteMatcherTest.php
│   ├── RouterTest.php
│   └── RouteCompilerTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/routing/composer.json`
- Modify: `composer.json` (root — add path repository)
- Modify: `phpunit.xml` (add routing test suite)

- [ ] **Step 1: Create packages/routing/composer.json**

Create `packages/routing/composer.json`:

```json
{
    "name": "preflow/routing",
    "description": "Preflow routing — file-based and attribute-based hybrid router",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Routing\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Routing\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add routing path repository to root composer.json**

In the root `composer.json`, add a new entry to the `repositories` array:

```json
{
    "type": "path",
    "url": "packages/routing",
    "options": { "symlink": true }
}
```

And add `"preflow/routing": "@dev"` to `require-dev`.

- [ ] **Step 3: Add routing test suite to phpunit.xml**

Add to the `<testsuites>` section in `phpunit.xml`:

```xml
<testsuite name="Routing">
    <directory>packages/routing/tests</directory>
</testsuite>
```

Add to the `<source><include>` section:

```xml
<directory>packages/routing/src</directory>
```

- [ ] **Step 4: Create directory structure and install**

```bash
mkdir -p packages/routing/src/Attributes packages/routing/tests/Attributes
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/routing/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/routing package"
```

---

### Task 2: Route Attributes

**Files:**
- Create: `packages/routing/src/Attributes/HttpMethod.php`
- Create: `packages/routing/src/Attributes/Route.php`
- Create: `packages/routing/src/Attributes/Get.php`
- Create: `packages/routing/src/Attributes/Post.php`
- Create: `packages/routing/src/Attributes/Put.php`
- Create: `packages/routing/src/Attributes/Delete.php`
- Create: `packages/routing/src/Attributes/Patch.php`
- Create: `packages/routing/src/Attributes/Middleware.php`
- Create: `packages/routing/tests/Attributes/AttributeTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/Attributes/AttributeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Attributes;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Attributes\Route;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Put;
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Patch;
use Preflow\Routing\Attributes\Middleware;

#[Route('/api/v1/posts')]
#[Middleware('AuthMiddleware')]
class FakeController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    #[Middleware('AdminMiddleware')]
    public function store(): void {}

    #[Put('/{id}')]
    public function update(): void {}

    #[Delete('/{id}')]
    public function destroy(): void {}

    #[Patch('/{id}')]
    public function patch(): void {}
}

final class AttributeTest extends TestCase
{
    public function test_route_attribute_on_class(): void
    {
        $ref = new \ReflectionClass(FakeController::class);
        $attrs = $ref->getAttributes(Route::class);

        $this->assertCount(1, $attrs);

        $route = $attrs[0]->newInstance();
        $this->assertSame('/api/v1/posts', $route->path);
    }

    public function test_get_attribute_on_method(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'index');
        $attrs = $ref->getAttributes(Get::class);

        $this->assertCount(1, $attrs);

        $get = $attrs[0]->newInstance();
        $this->assertSame('/', $get->path);
        $this->assertSame('GET', $get->method);
    }

    public function test_post_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'store');
        $attrs = $ref->getAttributes(Post::class);

        $post = $attrs[0]->newInstance();
        $this->assertSame('/', $post->path);
        $this->assertSame('POST', $post->method);
    }

    public function test_put_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'update');
        $put = $ref->getAttributes(Put::class)[0]->newInstance();
        $this->assertSame('PUT', $put->method);
    }

    public function test_delete_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'destroy');
        $del = $ref->getAttributes(Delete::class)[0]->newInstance();
        $this->assertSame('DELETE', $del->method);
    }

    public function test_patch_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'patch');
        $patch = $ref->getAttributes(Patch::class)[0]->newInstance();
        $this->assertSame('PATCH', $patch->method);
    }

    public function test_middleware_attribute_on_class(): void
    {
        $ref = new \ReflectionClass(FakeController::class);
        $attrs = $ref->getAttributes(Middleware::class);

        $this->assertCount(1, $attrs);
        $mw = $attrs[0]->newInstance();
        $this->assertSame(['AuthMiddleware'], $mw->middleware);
    }

    public function test_middleware_attribute_on_method(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'store');
        $attrs = $ref->getAttributes(Middleware::class);

        $this->assertCount(1, $attrs);
        $mw = $attrs[0]->newInstance();
        $this->assertSame(['AdminMiddleware'], $mw->middleware);
    }

    public function test_route_attribute_with_middleware_param(): void
    {
        $route = new Route('/api', middleware: ['AuthMiddleware', 'RateLimitMiddleware']);
        $this->assertSame(['AuthMiddleware', 'RateLimitMiddleware'], $route->middleware);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/Attributes/AttributeTest.php
```

- [ ] **Step 3: Create HttpMethod base attribute**

Create `packages/routing/src/Attributes/HttpMethod.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

abstract class HttpMethod
{
    public function __construct(
        public readonly string $path = '/',
        public readonly string $method = 'GET',
    ) {}
}
```

- [ ] **Step 4: Create all method attributes**

Create `packages/routing/src/Attributes/Get.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Get extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'GET');
    }
}
```

Create `packages/routing/src/Attributes/Post.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Post extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'POST');
    }
}
```

Create `packages/routing/src/Attributes/Put.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Put extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'PUT');
    }
}
```

Create `packages/routing/src/Attributes/Delete.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Delete extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'DELETE');
    }
}
```

Create `packages/routing/src/Attributes/Patch.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Patch extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'PATCH');
    }
}
```

- [ ] **Step 5: Create Route and Middleware attributes**

Create `packages/routing/src/Attributes/Route.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Route
{
    /** @var string[] */
    public readonly array $middleware;

    /**
     * @param string[] $middleware
     */
    public function __construct(
        public readonly string $path,
        array $middleware = [],
    ) {
        $this->middleware = $middleware;
    }
}
```

Create `packages/routing/src/Attributes/Middleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var string[] */
    public readonly array $middleware;

    public function __construct(string ...$middleware)
    {
        $this->middleware = $middleware;
    }
}
```

- [ ] **Step 6: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/Attributes/AttributeTest.php
```

Expected: All 9 tests pass.

- [ ] **Step 7: Commit**

```bash
git add packages/routing/src/Attributes packages/routing/tests/Attributes
git commit -m "feat(routing): add route and HTTP method attributes"
```

---

### Task 3: RouteEntry Value Object

**Files:**
- Create: `packages/routing/src/RouteEntry.php`

- [ ] **Step 1: Create RouteEntry**

Create `packages/routing/src/RouteEntry.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;

final readonly class RouteEntry
{
    /**
     * @param string   $pattern    URI pattern (e.g., '/blog/{slug}', '/api/v1/posts/{id}')
     * @param string   $handler    Handler identifier (page path or 'ClassName@method')
     * @param string   $method     HTTP method (GET, POST, etc.)
     * @param RouteMode $mode      Component or Action
     * @param string[] $middleware Middleware class names
     * @param string[] $paramNames Parameter names extracted from pattern (e.g., ['slug'])
     * @param string   $regex      Compiled regex for matching
     * @param bool     $isCatchAll Whether this route has a catch-all segment
     */
    public function __construct(
        public string $pattern,
        public string $handler,
        public string $method,
        public RouteMode $mode,
        public array $middleware = [],
        public array $paramNames = [],
        public string $regex = '',
        public bool $isCatchAll = false,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/routing/src/RouteEntry.php
git commit -m "feat(routing): add RouteEntry value object"
```

---

### Task 4: RouteCollection

**Files:**
- Create: `packages/routing/src/RouteCollection.php`

- [ ] **Step 1: Create RouteCollection**

Create `packages/routing/src/RouteCollection.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

final class RouteCollection
{
    /** @var RouteEntry[] */
    private array $entries = [];

    public function add(RouteEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @param RouteEntry[] $entries
     */
    public function addMany(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->entries[] = $entry;
        }
    }

    /**
     * @return RouteEntry[]
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return RouteEntry[]
     */
    public function forMethod(string $method): array
    {
        return array_values(
            array_filter($this->entries, fn (RouteEntry $e) => $e->method === $method)
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return array<int, array<string, mixed>> Serializable format for caching
     */
    public function toArray(): array
    {
        return array_map(fn (RouteEntry $e) => [
            'pattern' => $e->pattern,
            'handler' => $e->handler,
            'method' => $e->method,
            'mode' => $e->mode->value,
            'middleware' => $e->middleware,
            'paramNames' => $e->paramNames,
            'regex' => $e->regex,
            'isCatchAll' => $e->isCatchAll,
        ], $this->entries);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        $collection = new self();
        foreach ($data as $item) {
            $collection->add(new RouteEntry(
                pattern: $item['pattern'],
                handler: $item['handler'],
                method: $item['method'],
                mode: \Preflow\Core\Routing\RouteMode::from($item['mode']),
                middleware: $item['middleware'],
                paramNames: $item['paramNames'],
                regex: $item['regex'],
                isCatchAll: $item['isCatchAll'],
            ));
        }
        return $collection;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/routing/src/RouteCollection.php
git commit -m "feat(routing): add RouteCollection with serialization support"
```

---

### Task 5: FileRouteScanner

**Files:**
- Create: `packages/routing/src/FileRouteScanner.php`
- Create: `packages/routing/tests/FileRouteScannerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/FileRouteScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\FileRouteScanner;

final class FileRouteScannerTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_test_pages_' . uniqid();
        mkdir($this->pagesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->pagesDir);
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

    private function createFile(string $relativePath): void
    {
        $path = $this->pagesDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, '');
    }

    public function test_index_file_maps_to_root(): void
    {
        $this->createFile('index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/', $entries[0]->pattern);
        $this->assertSame('index.twig', $entries[0]->handler);
        $this->assertSame(RouteMode::Component, $entries[0]->mode);
        $this->assertSame('GET', $entries[0]->method);
    }

    public function test_simple_page(): void
    {
        $this->createFile('about.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/about', $entries[0]->pattern);
        $this->assertSame('about.twig', $entries[0]->handler);
    }

    public function test_nested_directory_with_index(): void
    {
        $this->createFile('blog/index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/blog', $entries[0]->pattern);
        $this->assertSame('blog/index.twig', $entries[0]->handler);
    }

    public function test_dynamic_segment(): void
    {
        $this->createFile('blog/[slug].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/blog/{slug}', $entries[0]->pattern);
        $this->assertSame(['slug'], $entries[0]->paramNames);
        $this->assertStringContainsString('(?P<slug>[^/]+)', $entries[0]->regex);
    }

    public function test_dynamic_directory_with_index(): void
    {
        $this->createFile('games/[category]/index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/games/{category}', $entries[0]->pattern);
        $this->assertSame(['category'], $entries[0]->paramNames);
    }

    public function test_multiple_dynamic_segments(): void
    {
        $this->createFile('blog/[slug]/comments.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertSame('/blog/{slug}/comments', $entries[0]->pattern);
        $this->assertSame(['slug'], $entries[0]->paramNames);
    }

    public function test_catch_all_segment(): void
    {
        $this->createFile('games/[...path].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/games/{path}', $entries[0]->pattern);
        $this->assertTrue($entries[0]->isCatchAll);
        $this->assertSame(['path'], $entries[0]->paramNames);
        $this->assertStringContainsString('(?P<path>.+)', $entries[0]->regex);
    }

    public function test_underscore_files_are_excluded(): void
    {
        $this->createFile('_layout.twig');
        $this->createFile('_error.twig');
        $this->createFile('index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/', $entries[0]->pattern);
    }

    public function test_non_twig_files_are_excluded(): void
    {
        $this->createFile('index.twig');
        $this->createFile('index.php'); // co-located PHP is not a route

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
    }

    public function test_multiple_routes_discovered(): void
    {
        $this->createFile('index.twig');
        $this->createFile('about.twig');
        $this->createFile('blog/index.twig');
        $this->createFile('blog/[slug].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(4, $entries);

        $patterns = array_map(fn ($e) => $e->pattern, $entries);
        $this->assertContains('/', $patterns);
        $this->assertContains('/about', $patterns);
        $this->assertContains('/blog', $patterns);
        $this->assertContains('/blog/{slug}', $patterns);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/FileRouteScannerTest.php
```

- [ ] **Step 3: Implement FileRouteScanner**

Create `packages/routing/src/FileRouteScanner.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;

final class FileRouteScanner
{
    public function __construct(
        private readonly string $pagesDir,
        private readonly string $extension = 'twig',
    ) {}

    /**
     * @return RouteEntry[]
     */
    public function scan(): array
    {
        if (!is_dir($this->pagesDir)) {
            return [];
        }

        $entries = [];
        $this->scanDirectory($this->pagesDir, '', $entries);

        // Sort: static routes before dynamic, catch-all last
        usort($entries, function (RouteEntry $a, RouteEntry $b) {
            if ($a->isCatchAll !== $b->isCatchAll) {
                return $a->isCatchAll ? 1 : -1;
            }
            $aDynamic = str_contains($a->pattern, '{');
            $bDynamic = str_contains($b->pattern, '{');
            if ($aDynamic !== $bDynamic) {
                return $aDynamic ? 1 : -1;
            }
            return strcmp($a->pattern, $b->pattern);
        });

        return $entries;
    }

    /**
     * @param RouteEntry[] $entries
     */
    private function scanDirectory(string $dir, string $prefix, array &$entries): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $item;

            if (is_dir($fullPath)) {
                $segment = $this->convertSegment($item);
                $this->scanDirectory($fullPath, $prefix . '/' . $segment, $entries);
                continue;
            }

            // Only process template files
            if (!str_ends_with($item, '.' . $this->extension)) {
                continue;
            }

            // Skip underscore-prefixed files (_layout.twig, _error.twig)
            if (str_starts_with($item, '_')) {
                continue;
            }

            $name = substr($item, 0, -(strlen($this->extension) + 1));
            $relativePath = ltrim($prefix . '/' . $item, '/');

            // Check for catch-all
            $isCatchAll = str_starts_with($name, '[...');

            if ($name === 'index') {
                $pattern = $prefix === '' ? '/' : $prefix;
            } else {
                $converted = $this->convertSegment($name);
                $pattern = $prefix . '/' . $converted;
            }

            // Extract param names and build regex
            $paramNames = [];
            $regex = $this->buildRegex($pattern, $paramNames, $isCatchAll);

            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $relativePath,
                method: 'GET',
                mode: RouteMode::Component,
                middleware: [],
                paramNames: $paramNames,
                regex: $regex,
                isCatchAll: $isCatchAll,
            );
        }
    }

    /**
     * Convert a file/directory name segment to a route pattern segment.
     * [slug] → {slug}, [...path] → {path}
     */
    private function convertSegment(string $segment): string
    {
        // Catch-all: [...param]
        if (preg_match('/^\[\.\.\.(\w+)\]$/', $segment, $matches)) {
            return '{' . $matches[1] . '}';
        }

        // Dynamic: [param]
        if (preg_match('/^\[(\w+)\]$/', $segment, $matches)) {
            return '{' . $matches[1] . '}';
        }

        return $segment;
    }

    /**
     * @param string[] $paramNames Populated by reference
     */
    private function buildRegex(string $pattern, array &$paramNames, bool $isCatchAll): string
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use (&$paramNames, $isCatchAll) {
            $name = $matches[1];
            $paramNames[] = $name;

            // Last param and catch-all: match everything including slashes
            if ($isCatchAll && $name === end($paramNames)) {
                return '(?P<' . $name . '>.+)';
            }

            return '(?P<' . $name . '>[^/]+)';
        }, $pattern);

        return '#^' . $regex . '$#';
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/FileRouteScannerTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/routing/src/FileRouteScanner.php packages/routing/tests/FileRouteScannerTest.php
git commit -m "feat(routing): add FileRouteScanner for page directory scanning"
```

---

### Task 6: AttributeRouteScanner

**Files:**
- Create: `packages/routing/src/AttributeRouteScanner.php`
- Create: `packages/routing/tests/AttributeRouteScannerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/AttributeRouteScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\AttributeRouteScanner;
use Preflow\Routing\Attributes\Route;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Middleware;

#[Route('/api/posts')]
class TestApiController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    #[Middleware('AdminMiddleware')]
    public function store(): void {}

    #[Delete('/{id}')]
    public function destroy(): void {}
}

#[Route('/admin', middleware: ['AuthMiddleware'])]
class TestAdminController
{
    #[Get('/dashboard')]
    public function dashboard(): void {}
}

class NoRouteController
{
    public function index(): void {}
}

final class AttributeRouteScannerTest extends TestCase
{
    public function test_scans_controller_with_route_attribute(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $this->assertCount(4, $entries);
    }

    public function test_combines_class_prefix_with_method_path(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $patterns = array_map(fn ($e) => $e->pattern, $entries);
        $this->assertContains('/api/posts', $patterns);
        $this->assertContains('/api/posts/{id}', $patterns);
    }

    public function test_method_attribute_determines_http_method(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $getRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'GET'));
        $postRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'POST'));
        $deleteRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'DELETE'));

        $this->assertCount(2, $getRoutes);
        $this->assertCount(1, $postRoutes);
        $this->assertCount(1, $deleteRoutes);
    }

    public function test_handler_format_is_class_at_method(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $indexEntry = array_values(array_filter($entries, fn ($e) => $e->pattern === '/api/posts' && $e->method === 'GET'))[0];
        $this->assertSame(TestApiController::class . '@index', $indexEntry->handler);
    }

    public function test_all_entries_are_action_mode(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        foreach ($entries as $entry) {
            $this->assertSame(RouteMode::Action, $entry->mode);
        }
    }

    public function test_class_middleware_applied_to_all_methods(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestAdminController::class);

        $this->assertCount(1, $entries);
        $this->assertContains('AuthMiddleware', $entries[0]->middleware);
    }

    public function test_method_middleware_merged_with_class_middleware(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $storeEntry = array_values(array_filter($entries, fn ($e) => $e->method === 'POST'))[0];
        $this->assertContains('AdminMiddleware', $storeEntry->middleware);
    }

    public function test_dynamic_params_extracted(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $showEntry = array_values(array_filter(
            $entries,
            fn ($e) => $e->pattern === '/api/posts/{id}' && $e->method === 'GET'
        ))[0];

        $this->assertSame(['id'], $showEntry->paramNames);
        $this->assertStringContainsString('(?P<id>[^/]+)', $showEntry->regex);
    }

    public function test_class_without_route_attribute_returns_empty(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(NoRouteController::class);

        $this->assertCount(0, $entries);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/AttributeRouteScannerTest.php
```

- [ ] **Step 3: Implement AttributeRouteScanner**

Create `packages/routing/src/AttributeRouteScanner.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\Attributes\HttpMethod;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Route;

final class AttributeRouteScanner
{
    /**
     * @return RouteEntry[]
     */
    public function scanClass(string $className): array
    {
        $ref = new \ReflectionClass($className);

        // Class must have #[Route] attribute
        $routeAttrs = $ref->getAttributes(Route::class);
        if ($routeAttrs === []) {
            return [];
        }

        $routeAttr = $routeAttrs[0]->newInstance();
        $prefix = rtrim($routeAttr->path, '/');

        // Collect class-level middleware
        $classMiddleware = $routeAttr->middleware;
        foreach ($ref->getAttributes(Middleware::class) as $mwAttr) {
            $classMiddleware = array_merge($classMiddleware, $mwAttr->newInstance()->middleware);
        }

        $entries = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Find HttpMethod attribute on this method
            $httpMethodAttr = null;
            foreach ($method->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if ($instance instanceof HttpMethod) {
                    $httpMethodAttr = $instance;
                    break;
                }
            }

            if ($httpMethodAttr === null) {
                continue;
            }

            // Build full pattern
            $methodPath = $httpMethodAttr->path;
            if ($methodPath === '/') {
                $pattern = $prefix ?: '/';
            } else {
                $pattern = $prefix . '/' . ltrim($methodPath, '/');
            }

            // Collect method-level middleware
            $methodMiddleware = $classMiddleware;
            foreach ($method->getAttributes(Middleware::class) as $mwAttr) {
                $methodMiddleware = array_merge($methodMiddleware, $mwAttr->newInstance()->middleware);
            }

            // Extract params and build regex
            $paramNames = [];
            $regex = $this->buildRegex($pattern, $paramNames);

            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $className . '@' . $method->getName(),
                method: $httpMethodAttr->method,
                mode: RouteMode::Action,
                middleware: $methodMiddleware,
                paramNames: $paramNames,
                regex: $regex,
            );
        }

        return $entries;
    }

    /**
     * @param string[] $paramNames
     */
    private function buildRegex(string $pattern, array &$paramNames): string
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use (&$paramNames) {
            $name = $matches[1];
            $paramNames[] = $name;
            return '(?P<' . $name . '>[^/]+)';
        }, $pattern);

        return '#^' . $regex . '$#';
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/AttributeRouteScannerTest.php
```

Expected: All 9 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/routing/src/AttributeRouteScanner.php packages/routing/tests/AttributeRouteScannerTest.php
git commit -m "feat(routing): add AttributeRouteScanner for controller class scanning"
```

---

### Task 7: RouteMatcher

**Files:**
- Create: `packages/routing/src/RouteMatcher.php`
- Create: `packages/routing/tests/RouteMatcherTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/RouteMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\RouteMatcher;

final class RouteMatcherTest extends TestCase
{
    private function entry(
        string $pattern,
        string $method = 'GET',
        string $handler = 'handler',
        RouteMode $mode = RouteMode::Component,
        array $paramNames = [],
        string $regex = '',
        bool $isCatchAll = false,
    ): RouteEntry {
        if ($regex === '') {
            $regex = '#^' . preg_replace_callback('/\{(\w+)\}/', function ($m) use ($isCatchAll) {
                return $isCatchAll ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)';
            }, $pattern) . '$#';
        }
        return new RouteEntry($pattern, $handler, $method, $mode, [], $paramNames, $regex, $isCatchAll);
    }

    public function test_matches_static_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about', handler: 'about.twig'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/about');

        $this->assertNotNull($result);
        $this->assertSame('about.twig', $result['entry']->handler);
        $this->assertSame([], $result['params']);
    }

    public function test_matches_dynamic_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/blog/{slug}', handler: 'blog/[slug].twig', paramNames: ['slug']));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/blog/hello-world');

        $this->assertNotNull($result);
        $this->assertSame('hello-world', $result['params']['slug']);
    }

    public function test_matches_multiple_params(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry(
            '/users/{userId}/posts/{postId}',
            handler: 'handler',
            paramNames: ['userId', 'postId'],
        ));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/users/42/posts/99');

        $this->assertSame('42', $result['params']['userId']);
        $this->assertSame('99', $result['params']['postId']);
    }

    public function test_matches_correct_http_method(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/posts', method: 'GET', handler: 'list'));
        $collection->add($this->entry('/posts', method: 'POST', handler: 'create'));

        $matcher = new RouteMatcher($collection);

        $getResult = $matcher->match('GET', '/posts');
        $this->assertSame('list', $getResult['entry']->handler);

        $postResult = $matcher->match('POST', '/posts');
        $this->assertSame('create', $postResult['entry']->handler);
    }

    public function test_returns_null_for_no_match(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/nonexistent');

        $this->assertNull($result);
    }

    public function test_returns_null_for_wrong_method(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/posts', method: 'GET'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('POST', '/posts');

        $this->assertNull($result);
    }

    public function test_static_routes_match_before_dynamic(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/blog/{slug}', handler: 'dynamic', paramNames: ['slug']));
        $collection->add($this->entry('/blog/featured', handler: 'static'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/blog/featured');

        // Static should match first
        $this->assertSame('static', $result['entry']->handler);
    }

    public function test_catch_all_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry(
            '/docs/{path}',
            handler: 'docs/[...path].twig',
            paramNames: ['path'],
            isCatchAll: true,
        ));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/docs/getting-started/installation');

        $this->assertNotNull($result);
        $this->assertSame('getting-started/installation', $result['params']['path']);
    }

    public function test_catch_all_matches_after_specific(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/docs/api', handler: 'specific'));
        $collection->add($this->entry(
            '/docs/{path}',
            handler: 'catch-all',
            paramNames: ['path'],
            isCatchAll: true,
        ));

        $matcher = new RouteMatcher($collection);

        $specific = $matcher->match('GET', '/docs/api');
        $this->assertSame('specific', $specific['entry']->handler);

        $catchAll = $matcher->match('GET', '/docs/some/deep/path');
        $this->assertSame('catch-all', $catchAll['entry']->handler);
    }

    public function test_trailing_slash_normalized(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/about/');

        $this->assertNotNull($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/RouteMatcherTest.php
```

- [ ] **Step 3: Implement RouteMatcher**

Create `packages/routing/src/RouteMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

final class RouteMatcher
{
    public function __construct(
        private readonly RouteCollection $collection,
    ) {}

    /**
     * @return array{entry: RouteEntry, params: array<string, string>}|null
     */
    public function match(string $method, string $uri): ?array
    {
        // Normalize: strip trailing slash (except root)
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        $entries = $this->collection->all();

        // First pass: try static routes (no params) for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if ($entry->paramNames === [] && !$entry->isCatchAll && $entry->pattern === $uri) {
                return ['entry' => $entry, 'params' => []];
            }
        }

        // Second pass: try dynamic routes (non-catch-all) for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if ($entry->paramNames === [] || $entry->isCatchAll) {
                continue;
            }
            if ($entry->regex !== '' && preg_match($entry->regex, $uri, $matches)) {
                $params = $this->extractParams($entry->paramNames, $matches);
                return ['entry' => $entry, 'params' => $params];
            }
        }

        // Third pass: try catch-all routes for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if (!$entry->isCatchAll) {
                continue;
            }
            if ($entry->regex !== '' && preg_match($entry->regex, $uri, $matches)) {
                $params = $this->extractParams($entry->paramNames, $matches);
                return ['entry' => $entry, 'params' => $params];
            }
        }

        return null;
    }

    /**
     * @param string[] $paramNames
     * @param array<string|int, string> $matches
     * @return array<string, string>
     */
    private function extractParams(array $paramNames, array $matches): array
    {
        $params = [];
        foreach ($paramNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }
        return $params;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/RouteMatcherTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/routing/src/RouteMatcher.php packages/routing/tests/RouteMatcherTest.php
git commit -m "feat(routing): add RouteMatcher with static/dynamic/catch-all priority"
```

---

### Task 8: Router — Implementing RouterInterface

**Files:**
- Create: `packages/routing/src/Router.php`
- Create: `packages/routing/tests/RouterTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/RouterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\Attributes\Route as RouteAttr;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Router;

#[RouteAttr('/api/items')]
class ItemController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    public function store(): void {}
}

final class RouterTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_router_test_' . uniqid();
        mkdir($this->pagesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->pagesDir);
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

    private function createPage(string $relativePath): void
    {
        $path = $this->pagesDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, '');
    }

    private function createRequest(string $method, string $uri): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    public function test_matches_file_based_route(): void
    {
        $this->createPage('index.twig');
        $this->createPage('about.twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/about'));

        $this->assertSame(RouteMode::Component, $route->mode);
        $this->assertSame('about.twig', $route->handler);
    }

    public function test_matches_attribute_based_route(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items'));

        $this->assertSame(RouteMode::Action, $route->mode);
        $this->assertStringContainsString('@index', $route->handler);
    }

    public function test_extracts_parameters(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items/42'));

        $this->assertSame('42', $route->parameters['id']);
    }

    public function test_file_route_with_dynamic_param(): void
    {
        $this->createPage('blog/[slug].twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/blog/hello-world'));

        $this->assertSame(RouteMode::Component, $route->mode);
        $this->assertSame('hello-world', $route->parameters['slug']);
    }

    public function test_throws_not_found_for_no_match(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);

        $this->expectException(NotFoundHttpException::class);
        $router->match($this->createRequest('GET', '/nonexistent'));
    }

    public function test_correct_http_method_matching(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $getRoute = $router->match($this->createRequest('GET', '/api/items'));
        $this->assertStringContainsString('@index', $getRoute->handler);

        $postRoute = $router->match($this->createRequest('POST', '/api/items'));
        $this->assertStringContainsString('@store', $postRoute->handler);
    }

    public function test_middleware_included_in_route(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items'));

        // Route object carries middleware from the entry
        $this->assertIsArray($route->middleware);
    }

    public function test_returns_core_route_object(): void
    {
        $this->createPage('index.twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/'));

        $this->assertInstanceOf(\Preflow\Core\Routing\Route::class, $route);
        $this->assertSame(RouteMode::Component, $route->mode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/RouterTest.php
```

- [ ] **Step 3: Implement Router**

Create `packages/routing/src/Router.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;

final class Router implements RouterInterface
{
    private ?RouteCollection $collection = null;

    /**
     * @param string|null  $pagesDir    Path to pages directory for file-based routes
     * @param string[]     $controllers Controller class names for attribute-based routes
     * @param string|null  $cachePath   Path to compiled route cache file
     */
    public function __construct(
        private readonly ?string $pagesDir = null,
        private readonly array $controllers = [],
        private readonly ?string $cachePath = null,
    ) {}

    public function match(ServerRequestInterface $request): Route
    {
        $collection = $this->getCollection();
        $matcher = new RouteMatcher($collection);

        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $result = $matcher->match($method, $uri);

        if ($result === null) {
            throw new NotFoundHttpException("No route matches [{$method} {$uri}]");
        }

        $entry = $result['entry'];

        return new Route(
            mode: $entry->mode,
            handler: $entry->handler,
            parameters: $result['params'],
            middleware: $entry->middleware,
        );
    }

    public function getCollection(): RouteCollection
    {
        if ($this->collection !== null) {
            return $this->collection;
        }

        // Try loading from cache
        if ($this->cachePath !== null && file_exists($this->cachePath)) {
            $data = require $this->cachePath;
            $this->collection = RouteCollection::fromArray($data);
            return $this->collection;
        }

        // Build fresh
        $this->collection = new RouteCollection();

        if ($this->pagesDir !== null) {
            $fileScanner = new FileRouteScanner($this->pagesDir);
            $this->collection->addMany($fileScanner->scan());
        }

        $attrScanner = new AttributeRouteScanner();
        foreach ($this->controllers as $controller) {
            $this->collection->addMany($attrScanner->scanClass($controller));
        }

        return $this->collection;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/RouterTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/routing/src/Router.php packages/routing/tests/RouterTest.php
git commit -m "feat(routing): add Router implementing RouterInterface with hybrid matching"
```

---

### Task 9: RouteCompiler — Cache Support

**Files:**
- Create: `packages/routing/src/RouteCompiler.php`
- Create: `packages/routing/tests/RouteCompilerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/routing/tests/RouteCompilerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteCompiler;
use Preflow\Routing\RouteEntry;

final class RouteCompilerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/preflow_cache_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $cachePath = $this->cacheDir . '/routes.php';
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function test_compiles_to_php_file(): void
    {
        $collection = new RouteCollection();
        $collection->add(new RouteEntry(
            pattern: '/about',
            handler: 'about.twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: [],
            paramNames: [],
            regex: '#^/about$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($collection, $cachePath);

        $this->assertFileExists($cachePath);
    }

    public function test_cached_file_returns_array(): void
    {
        $collection = new RouteCollection();
        $collection->add(new RouteEntry(
            pattern: '/about',
            handler: 'about.twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: [],
            paramNames: [],
            regex: '#^/about$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($collection, $cachePath);

        $data = require $cachePath;
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('/about', $data[0]['pattern']);
    }

    public function test_round_trip_preserves_routes(): void
    {
        $original = new RouteCollection();
        $original->add(new RouteEntry(
            pattern: '/blog/{slug}',
            handler: 'blog/[slug].twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: ['AuthMiddleware'],
            paramNames: ['slug'],
            regex: '#^/blog/(?P<slug>[^/]+)$#',
        ));
        $original->add(new RouteEntry(
            pattern: '/api/posts',
            handler: 'PostController@index',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: [],
            regex: '#^/api/posts$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($original, $cachePath);

        $data = require $cachePath;
        $restored = RouteCollection::fromArray($data);

        $this->assertSame(2, $restored->count());

        $all = $restored->all();
        $this->assertSame('/blog/{slug}', $all[0]->pattern);
        $this->assertSame(['slug'], $all[0]->paramNames);
        $this->assertSame(RouteMode::Component, $all[0]->mode);
        $this->assertSame(['AuthMiddleware'], $all[0]->middleware);

        $this->assertSame('/api/posts', $all[1]->pattern);
        $this->assertSame(RouteMode::Action, $all[1]->mode);
    }

    public function test_clear_removes_cache_file(): void
    {
        $cachePath = $this->cacheDir . '/routes.php';
        file_put_contents($cachePath, '<?php return [];');

        $compiler = new RouteCompiler();
        $compiler->clear($cachePath);

        $this->assertFileDoesNotExist($cachePath);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/routing/tests/RouteCompilerTest.php
```

- [ ] **Step 3: Implement RouteCompiler**

Create `packages/routing/src/RouteCompiler.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Routing;

final class RouteCompiler
{
    public function compile(RouteCollection $collection, string $cachePath): void
    {
        $data = $collection->toArray();
        $exported = var_export($data, true);

        $content = "<?php\n\n// Auto-generated by Preflow RouteCompiler. Do not edit.\n// Generated: " . date('Y-m-d H:i:s') . "\n\nreturn {$exported};\n";

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cachePath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }
    }

    public function clear(string $cachePath): void
    {
        if (file_exists($cachePath)) {
            unlink($cachePath);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cachePath, true);
            }
        }
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/routing/tests/RouteCompilerTest.php
```

Expected: All 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/routing/src/RouteCompiler.php packages/routing/tests/RouteCompilerTest.php
git commit -m "feat(routing): add RouteCompiler for production route caching"
```

---

### Task 10: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass (56 core + routing tests).

- [ ] **Step 2: Verify routing integrates with core**

```bash
php -r "
require 'vendor/autoload.php';
use Preflow\Routing\Router;
echo 'Router loads: OK' . PHP_EOL;
echo 'Implements RouterInterface: ' . (new Router() instanceof Preflow\Core\Routing\RouterInterface ? 'YES' : 'NO') . PHP_EOL;
"
```

Expected output:
```
Router loads: OK
Implements RouterInterface: YES
```

- [ ] **Step 3: Commit final state if cleanup needed**

```bash
git add -A && git status
# Only commit if there are changes
```

---

## Phase 2 Deliverables

After completing all tasks, the `preflow/routing` package provides:

| Component | What It Does |
|---|---|
| `Route`, `Get`, `Post`, `Put`, `Delete`, `Patch` | PHP 8.5 attributes for controller routing |
| `Middleware` | Attribute for assigning middleware to classes/methods |
| `FileRouteScanner` | Scans pages directory, converts file paths to route entries |
| `AttributeRouteScanner` | Scans controller classes for route attributes |
| `RouteEntry` | Internal route representation with pattern, regex, params |
| `RouteCollection` | Holds all routes with serialization support |
| `RouteMatcher` | Matches URI + method to route entry (static > dynamic > catch-all) |
| `Router` | Implements `RouterInterface`, orchestrates scanners + matcher |
| `RouteCompiler` | Compiles routes to cached PHP file for production |

**Next phase:** `preflow/view` — template engine interface, Twig adapter, and asset pipeline.
