# Folio Walking Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `preflow/folio` — a drop-in CMS package that mounts its own admin at a configurable path, auto-discovers JSON content types from `config/models/`, gives them CRUD in the shipped admin, renders content on the public frontend by slug, and supports overriding one core admin action from userland.

**Architecture:** A new `packages/folio` package composes existing Preflow packages. A `FolioServiceProvider` (registered via the app's `config/providers.php`) binds Folio services and, in `boot()`, (a) registers a `@folio` Twig namespace with the userland override dir taking precedence over the package's shipped templates, and (b) appends Folio's routes to the existing `Router`'s collection — admin routes under a configurable prefix, plus a lowest-priority frontend catch-all. Admin is plain server-rendered controllers (form POST + redirect); HTMX/components enhancement is a later spec.

**Tech Stack:** PHP 8.4+, Preflow (core, data, view, twig, form, validation, routing), PSR-7 (nyholm/psr7), PHPUnit 11, Twig 3.

## Global Constraints

- PHP `>=8.4`; all classes `declare(strict_types=1);`.
- Package namespace: `Preflow\Folio\` → `src/`; tests `Preflow\Folio\Tests\` → `tests/`.
- Package `composer.json` `type` is `library`, license `MIT`, minimum copy style: no emojis.
- Content type definitions live in `config/models/*.json` (the existing `data.models_path`). Folio adds NO new workspace folder.
- Admin mount path is configurable via `config/folio.php` (`'path' => '/folio'`), default `/folio`.
- Userland template overrides live in `{basePath}/resources/folio/` (a normal userland views dir), registered BEFORE the package templates in the `@folio` namespace.
- Userland action overrides: `App\Folio\Overrides\{Controller}\{Action}` (PSR-4 `App\` → `app/`), implementing `Preflow\Folio\Override\OverridableAction`.
- Demo content type key `page`, table `page`, `storage: "json"` (zero-config, no migration).
- Run tests from repo root: `./vendor/bin/phpunit <path>`.
- TDD throughout: failing test first, minimal impl, commit per task.

---

### Task 1: Scaffold the `preflow/folio` package

**Files:**
- Create: `packages/folio/composer.json`
- Create: `packages/folio/src/.gitkeep` (removed once real files land)
- Create: `packages/folio/tests/AutoloadTest.php`
- Modify: `composer.json` (repo root — add path repository + require-dev entry)

**Interfaces:**
- Produces: the `Preflow\Folio\` autoload root and `Preflow\Folio\Tests\` test root used by every later task.

- [ ] **Step 1: Write the package composer.json**

```json
{
    "name": "preflow/folio",
    "description": "Folio — drop-in CMS for Preflow: shipped admin, JSON content types, slug rendering",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "@dev",
        "preflow/data": "@dev",
        "preflow/view": "@dev",
        "preflow/routing": "@dev",
        "preflow/form": "@dev",
        "preflow/validation": "@dev",
        "nyholm/psr7": "^1.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": { "Preflow\\Folio\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Preflow\\Folio\\Tests\\": "tests/" }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Register the package in the root composer.json**

In repo-root `composer.json`, add to `require-dev` (keep alphabetical with the other `preflow/*`):
```json
"preflow/folio": "@dev",
```
And add to `repositories`:
```json
{ "type": "path", "url": "packages/folio", "options": { "symlink": true } },
```

- [ ] **Step 3: Create a placeholder source dir**

Create empty `packages/folio/src/.gitkeep`.

- [ ] **Step 4: Write the failing autoload test**

`packages/folio/tests/AutoloadTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function test_package_namespace_autoloads(): void
    {
        $this->assertTrue(class_exists(\Preflow\Folio\Tests\AutoloadTest::class));
    }
}
```

- [ ] **Step 5: Refresh autoload and run the test**

Run:
```bash
composer update preflow/folio --no-interaction 2>&1 | tail -5
./vendor/bin/phpunit packages/folio/tests/AutoloadTest.php
```
Expected: dump-autoload succeeds; test PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add packages/folio composer.json composer.lock
git commit -m "feat(folio): scaffold preflow/folio package"
```

---

### Task 2: Add Twig namespace support to the view layer

The view engine currently takes template dirs only at construction; there is no public way for a package to register its own templates. Add `addNamespace()` to the interface and the Twig engine (Blade gets a no-op).

**Files:**
- Modify: `packages/view/src/TemplateEngineInterface.php`
- Modify: `packages/twig/src/TwigEngine.php`
- Modify: `packages/blade/src/BladeEngine.php` (no-op impl to satisfy the interface)
- Test: `packages/twig/tests/TwigEngineNamespaceTest.php`

**Interfaces:**
- Produces: `TemplateEngineInterface::addNamespace(string $namespace, string $path): void`. Consumed by Task 10 (FolioServiceProvider).

- [ ] **Step 1: Write the failing test**

`packages/twig/tests/TwigEngineNamespaceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

final class TwigEngineNamespaceTest extends TestCase
{
    public function test_addNamespace_resolves_templates(): void
    {
        $dir = sys_get_temp_dir() . '/folio_ns_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/hello.twig', 'NS:{{ name }}');

        $assets = new AssetCollector(new NonceGenerator(), isProd: false);
        $engine = new TwigEngine([sys_get_temp_dir()], $assets, debug: true);
        $engine->addNamespace('demo', $dir);

        $out = $engine->render('@demo/hello.twig', ['name' => 'Folio']);

        $this->assertSame('NS:Folio', $out);
    }

    public function test_addNamespace_first_path_wins(): void
    {
        $a = sys_get_temp_dir() . '/folio_ns_a_' . bin2hex(random_bytes(4));
        $b = sys_get_temp_dir() . '/folio_ns_b_' . bin2hex(random_bytes(4));
        mkdir($a, 0777, true);
        mkdir($b, 0777, true);
        file_put_contents($a . '/x.twig', 'A');
        file_put_contents($b . '/x.twig', 'B');

        $assets = new AssetCollector(new NonceGenerator(), isProd: false);
        $engine = new TwigEngine([sys_get_temp_dir()], $assets, debug: true);
        $engine->addNamespace('ns', $a); // registered first -> wins
        $engine->addNamespace('ns', $b);

        $this->assertSame('A', $engine->render('@ns/x.twig', []));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/twig/tests/TwigEngineNamespaceTest.php`
Expected: FAIL — `Call to undefined method Preflow\Twig\TwigEngine::addNamespace()`.

- [ ] **Step 3: Add the interface method**

In `packages/view/src/TemplateEngineInterface.php`, add to the interface body:
```php
    /**
     * Register a template namespace path (e.g. "folio" => "/path"),
     * referenced in templates as "@folio/...". Multiple paths for the
     * same namespace are searched in registration order (first wins).
     */
    public function addNamespace(string $namespace, string $path): void;
```

- [ ] **Step 4: Implement in TwigEngine**

In `packages/twig/src/TwigEngine.php`, add a method (the constructor already builds a `FilesystemLoader` into `$this->twig`):
```php
    public function addNamespace(string $namespace, string $path): void
    {
        /** @var \Twig\Loader\FilesystemLoader $loader */
        $loader = $this->twig->getLoader();
        $loader->addPath($path, $namespace);
    }
```

- [ ] **Step 5: Implement a no-op in BladeEngine**

In `packages/blade/src/BladeEngine.php`, add (Blade has no Twig-style namespaces; the skeleton defaults to Twig):
```php
    public function addNamespace(string $namespace, string $path): void
    {
        // Blade does not support Twig-style template namespaces; intentionally a no-op.
    }
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit packages/twig/tests/TwigEngineNamespaceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add packages/view/src/TemplateEngineInterface.php packages/twig/src/TwigEngine.php packages/blade/src/BladeEngine.php packages/twig/tests/TwigEngineNamespaceTest.php
git commit -m "feat(view): add addNamespace() to template engine for package templates"
```

---

### Task 3: TypeCatalog — discover content types from config/models

`TypeRegistry` has no list method, so Folio scans the models directory itself.

**Files:**
- Create: `packages/folio/src/Content/TypeListing.php`
- Create: `packages/folio/src/Content/TypeCatalog.php`
- Test: `packages/folio/tests/Content/TypeCatalogTest.php`

**Interfaces:**
- Produces: `TypeCatalog::__construct(string $modelsPath)`, `TypeCatalog::all(): TypeListing[]`, `TypeCatalog::has(string $key): bool`. `TypeListing` is readonly with `public string $key, public string $label`.

- [ ] **Step 1: Write the failing test**

`packages/folio/tests/Content/TypeCatalogTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\TypeCatalog;

final class TypeCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_models_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    public function test_discovers_valid_types_with_label(): void
    {
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'label' => 'Pages', 'fields' => [],
        ]));
        file_put_contents($this->dir . '/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'fields' => [],
        ]));

        $catalog = new TypeCatalog($this->dir);
        $all = $catalog->all();

        $keys = array_map(fn ($t) => $t->key, $all);
        sort($keys);
        $this->assertSame(['author', 'page'], $keys);

        $byKey = [];
        foreach ($all as $t) {
            $byKey[$t->key] = $t->label;
        }
        $this->assertSame('Pages', $byKey['page']);     // explicit label
        $this->assertSame('Author', $byKey['author']);  // derived from key (ucfirst)
    }

    public function test_ignores_malformed_json(): void
    {
        file_put_contents($this->dir . '/good.json', json_encode(['key' => 'good', 'table' => 'good', 'fields' => []]));
        file_put_contents($this->dir . '/broken.json', '{ not valid json');

        $catalog = new TypeCatalog($this->dir);

        $this->assertCount(1, $catalog->all());
        $this->assertTrue($catalog->has('good'));
        $this->assertFalse($catalog->has('broken'));
    }

    public function test_missing_dir_yields_empty(): void
    {
        $catalog = new TypeCatalog($this->dir . '/nope');
        $this->assertSame([], $catalog->all());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Content/TypeCatalogTest.php`
Expected: FAIL — class `Preflow\Folio\Content\TypeCatalog` not found.

- [ ] **Step 3: Implement TypeListing**

`packages/folio/src/Content/TypeListing.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final readonly class TypeListing
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
```

- [ ] **Step 4: Implement TypeCatalog**

`packages/folio/src/Content/TypeCatalog.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final class TypeCatalog
{
    public function __construct(
        private readonly string $modelsPath,
    ) {}

    /** @return TypeListing[] */
    public function all(): array
    {
        if (!is_dir($this->modelsPath)) {
            return [];
        }

        $listings = [];
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['key']) || !is_string($data['key'])) {
                continue; // skip malformed / incomplete definitions
            }
            $key = $data['key'];
            $label = (isset($data['label']) && is_string($data['label']))
                ? $data['label']
                : ucfirst($key);
            $listings[] = new TypeListing($key, $label);
        }

        return $listings;
    }

    public function has(string $key): bool
    {
        foreach ($this->all() as $listing) {
            if ($listing->key === $key) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Content/TypeCatalogTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Content packages/folio/tests/Content
git commit -m "feat(folio): add TypeCatalog to discover content types from config/models"
```

---

### Task 4: Route building — PatternCompiler + FolioRoutes

Build the `RouteEntry[]` Folio appends to the router: admin routes under a configurable prefix plus a lowest-priority frontend catch-all.

**Files:**
- Create: `packages/folio/src/Routing/PatternCompiler.php`
- Create: `packages/folio/src/Routing/FolioRoutes.php`
- Test: `packages/folio/tests/Routing/PatternCompilerTest.php`
- Test: `packages/folio/tests/Routing/FolioRoutesTest.php`

**Interfaces:**
- Consumes: `Preflow\Routing\RouteEntry` (constructor: `pattern, handler, method, RouteMode $mode, array $middleware = [], array $paramNames = [], string $regex = '', bool $isCatchAll = false`), `Preflow\Core\Routing\RouteMode`.
- Produces:
  - `PatternCompiler::compile(string $pattern): array{regex: string, paramNames: string[], isCatchAll: bool}`
  - `FolioRoutes::admin(string $prefix): RouteEntry[]` and `FolioRoutes::frontend(): RouteEntry` (catch-all `/{...path}`).
  - Handler strings: admin → `Preflow\Folio\Http\AdminController@{method}`; frontend → `Preflow\Folio\Http\FrontendController@show`.

- [ ] **Step 1: Write the failing PatternCompiler test**

`packages/folio/tests/Routing/PatternCompilerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Routing\PatternCompiler;

final class PatternCompilerTest extends TestCase
{
    public function test_static_pattern(): void
    {
        $r = PatternCompiler::compile('/folio');
        $this->assertSame([], $r['paramNames']);
        $this->assertFalse($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/folio'));
        $this->assertSame(0, preg_match($r['regex'], '/folio/x'));
    }

    public function test_named_params(): void
    {
        $r = PatternCompiler::compile('/folio/{type}/{id}/edit');
        $this->assertSame(['type', 'id'], $r['paramNames']);
        $this->assertFalse($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/folio/page/abc/edit', $m));
        $this->assertSame('page', $m['type']);
        $this->assertSame('abc', $m['id']);
        $this->assertSame(0, preg_match($r['regex'], '/folio/page/abc'));
    }

    public function test_catch_all(): void
    {
        $r = PatternCompiler::compile('/{...path}');
        $this->assertSame(['path'], $r['paramNames']);
        $this->assertTrue($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/a/b/c', $m));
        $this->assertSame('a/b/c', $m['path']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Routing/PatternCompilerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement PatternCompiler**

`packages/folio/src/Routing/PatternCompiler.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

final class PatternCompiler
{
    /**
     * Compile a route pattern into a regex with named groups.
     * `{name}` -> single segment; `{...name}` -> catch-all (matches slashes).
     *
     * @return array{regex: string, paramNames: string[], isCatchAll: bool}
     */
    public static function compile(string $pattern): array
    {
        $paramNames = [];
        $isCatchAll = false;
        $out = '';
        $i = 0;
        $len = strlen($pattern);

        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === '{') {
                $end = strpos($pattern, '}', $i);
                $token = substr($pattern, $i + 1, $end - $i - 1);
                if (str_starts_with($token, '...')) {
                    $name = substr($token, 3);
                    $isCatchAll = true;
                    $out .= '(?P<' . $name . '>.+)';
                } else {
                    $name = $token;
                    $out .= '(?P<' . $name . '>[^/]+)';
                }
                $paramNames[] = $name;
                $i = $end + 1;
            } else {
                $out .= preg_quote($ch, '#');
                $i++;
            }
        }

        return ['regex' => '#^' . $out . '$#', 'paramNames' => $paramNames, 'isCatchAll' => $isCatchAll];
    }
}
```

- [ ] **Step 4: Run PatternCompiler tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Routing/PatternCompilerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing FolioRoutes test**

`packages/folio/tests/Routing/FolioRoutesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Folio\Routing\FolioRoutes;

final class FolioRoutesTest extends TestCase
{
    public function test_admin_routes_use_prefix_and_action_mode(): void
    {
        $entries = FolioRoutes::admin('/folio');

        $patterns = array_map(fn ($e) => $e->method . ' ' . $e->pattern, $entries);
        $this->assertContains('GET /folio', $patterns);
        $this->assertContains('GET /folio/{type}', $patterns);
        $this->assertContains('GET /folio/{type}/new', $patterns);
        $this->assertContains('POST /folio/{type}', $patterns);
        $this->assertContains('GET /folio/{type}/{id}/edit', $patterns);
        $this->assertContains('POST /folio/{type}/{id}', $patterns);
        $this->assertContains('POST /folio/{type}/{id}/delete', $patterns);

        foreach ($entries as $e) {
            $this->assertSame(RouteMode::Action, $e->mode);
            $this->assertStringStartsWith('Preflow\\Folio\\Http\\AdminController@', $e->handler);
        }
    }

    public function test_admin_prefix_is_configurable(): void
    {
        $entries = FolioRoutes::admin('/cms');
        $this->assertSame('/cms', $entries[0]->pattern);
    }

    public function test_frontend_is_lowest_priority_catch_all(): void
    {
        $entry = FolioRoutes::frontend();
        $this->assertTrue($entry->isCatchAll);
        $this->assertSame('GET', $entry->method);
        $this->assertSame('Preflow\\Folio\\Http\\FrontendController@show', $entry->handler);
        $this->assertSame(['path'], $entry->paramNames);
    }
}
```

- [ ] **Step 6: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Routing/FolioRoutesTest.php`
Expected: FAIL — class `Preflow\Folio\Routing\FolioRoutes` not found.

- [ ] **Step 7: Implement FolioRoutes**

`packages/folio/src/Routing/FolioRoutes.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;

final class FolioRoutes
{
    private const ADMIN = 'Preflow\\Folio\\Http\\AdminController';

    /** @return RouteEntry[] */
    public static function admin(string $prefix): array
    {
        $prefix = '/' . trim($prefix, '/');

        $defs = [
            ['GET',  $prefix,                          'index'],
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
        ];

        $entries = [];
        foreach ($defs as [$method, $pattern, $action]) {
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: self::ADMIN . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                middleware: [],
                paramNames: $c['paramNames'],
                regex: $c['regex'],
                isCatchAll: $c['isCatchAll'],
            );
        }

        return $entries;
    }

    public static function frontend(): RouteEntry
    {
        $c = PatternCompiler::compile('/{...path}');
        return new RouteEntry(
            pattern: '/{...path}',
            handler: 'Preflow\\Folio\\Http\\FrontendController@show',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $c['paramNames'],
            regex: $c['regex'],
            isCatchAll: $c['isCatchAll'],
        );
    }
}
```

- [ ] **Step 8: Run FolioRoutes tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Routing/FolioRoutesTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
git add packages/folio/src/Routing packages/folio/tests/Routing
git commit -m "feat(folio): add route pattern compiler and Folio route definitions"
```

---

### Task 5: Action override resolver

**Files:**
- Create: `packages/folio/src/Override/OverridableAction.php`
- Create: `packages/folio/src/Override/ActionResolver.php`
- Test: `packages/folio/tests/Override/ActionResolverTest.php`
- Test fixture: `packages/folio/tests/Fixtures/App/Folio/Overrides/Content/Index.php`

**Interfaces:**
- Consumes: `Preflow\Core\Container\Container` (`has()`, `get()`).
- Produces: `interface OverridableAction { public function handle(ServerRequestInterface $request): ResponseInterface; }`; `ActionResolver::__construct(Container $container)`; `ActionResolver::resolve(string $controller, string $action): ?OverridableAction` (maps to `App\Folio\Overrides\{Controller}\{Action}`).

- [ ] **Step 1: Write the failing test + fixture**

`packages/folio/tests/Fixtures/App/Folio/Overrides/Content/Index.php`:
```php
<?php

declare(strict_types=1);

namespace App\Folio\Overrides\Content;

use Nyholm\Psr7\Response;
use Preflow\Folio\Override\OverridableAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Index implements OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'OVERRIDDEN');
    }
}
```

Register the fixture namespace in `packages/folio/composer.json` `autoload-dev`:
```json
"autoload-dev": {
    "psr-4": {
        "Preflow\\Folio\\Tests\\": "tests/",
        "App\\Folio\\Overrides\\": "tests/Fixtures/App/Folio/Overrides/"
    }
}
```
Then run `composer update preflow/folio --no-interaction`.

`packages/folio/tests/Override/ActionResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Override;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Override\OverridableAction;

final class ActionResolverTest extends TestCase
{
    public function test_resolves_existing_override(): void
    {
        $resolver = new ActionResolver(new Container());
        $action = $resolver->resolve('Content', 'Index');

        $this->assertInstanceOf(OverridableAction::class, $action);

        $request = (new Psr17Factory())->createServerRequest('GET', '/folio');
        $this->assertSame('OVERRIDDEN', (string) $action->handle($request)->getBody());
    }

    public function test_returns_null_when_no_override(): void
    {
        $resolver = new ActionResolver(new Container());
        $this->assertNull($resolver->resolve('Content', 'Nonexistent'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Override/ActionResolverTest.php`
Expected: FAIL — `Preflow\Folio\Override\ActionResolver` not found.

- [ ] **Step 3: Implement OverridableAction**

`packages/folio/src/Override/OverridableAction.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Override;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
```

- [ ] **Step 4: Implement ActionResolver**

`packages/folio/src/Override/ActionResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Override;

use Preflow\Core\Container\Container;

final class ActionResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function resolve(string $controller, string $action): ?OverridableAction
    {
        $class = 'App\\Folio\\Overrides\\' . ucfirst($controller) . '\\' . ucfirst($action);

        if (!class_exists($class)) {
            return null;
        }
        if (!is_subclass_of($class, OverridableAction::class)) {
            return null;
        }

        $instance = $this->container->get($class);

        return $instance instanceof OverridableAction ? $instance : null;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Override/ActionResolverTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Override packages/folio/tests/Override packages/folio/tests/Fixtures packages/folio/composer.json composer.lock
git commit -m "feat(folio): add userland action override resolver"
```

---

### Task 6: FrontendResolver — slug to published record

**Files:**
- Create: `packages/folio/src/Content/FrontendResolver.php`
- Test: `packages/folio/tests/Content/FrontendResolverTest.php`

**Interfaces:**
- Consumes: `Preflow\Data\DataManager` (`queryType(string): QueryBuilder`), `QueryBuilder::where(string,$op,$val=null): self`, `QueryBuilder::first(): ?DynamicRecord`. `Preflow\Data\TypeRegistry`.
- Produces: `FrontendResolver::__construct(DataManager $dm, string $type = 'page')`; `FrontendResolver::resolve(string $path): ?DynamicRecord` — strips leading slash, matches `slug` + `status = 'published'`.

- [ ] **Step 1: Write the failing test**

`packages/folio/tests/Content/FrontendResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;

final class FrontendResolverTest extends TestCase
{
    private string $models;
    private string $store;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->models = sys_get_temp_dir() . '/folio_fr_models_' . bin2hex(random_bytes(4));
        $this->store = sys_get_temp_dir() . '/folio_fr_store_' . bin2hex(random_bytes(4));
        mkdir($this->models, 0777, true);
        mkdir($this->store, 0777, true);

        file_put_contents($this->models . '/page.json', json_encode([
            'key' => 'page',
            'table' => 'page',
            'storage' => 'json',
            'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'searchable' => true],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));

        $registry = new TypeRegistry($this->models);
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->store)],
            defaultDriver: 'json',
            typeRegistry: $registry,
        );

        $this->seed('1', 'home', 'published');
        $this->seed('2', 'draft-page', 'draft');
    }

    private function seed(string $id, string $slug, string $status): void
    {
        $registry = new TypeRegistry($this->models);
        $rec = DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'body' => 'x', 'status' => $status,
        ]);
        $this->dm->saveType($rec, validate: false);
    }

    public function test_resolves_published_by_slug(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $rec = $resolver->resolve('/home');

        $this->assertNotNull($rec);
        $this->assertSame('home', $rec->get('slug'));
    }

    public function test_unknown_slug_is_null(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/missing'));
    }

    public function test_draft_is_not_resolvable(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/draft-page'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Content/FrontendResolverTest.php`
Expected: FAIL — `Preflow\Folio\Content\FrontendResolver` not found.

- [ ] **Step 3: Implement FrontendResolver**

`packages/folio/src/Content/FrontendResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

final class FrontendResolver
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly string $type = 'page',
    ) {}

    public function resolve(string $path): ?DynamicRecord
    {
        $slug = trim($path, '/');
        if ($slug === '') {
            $slug = 'home';
        }

        return $this->dm->queryType($this->type)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Content/FrontendResolverTest.php`
Expected: PASS (3 tests).

> If `QueryBuilder::where()` on the JSON driver does not support a 2-arg form, use `->where('slug', '=', $slug)`. Verify the signature in `packages/data/src/QueryBuilder.php` before implementing and match it.

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Content/FrontendResolver.php packages/folio/tests/Content/FrontendResolverTest.php
git commit -m "feat(folio): add frontend slug resolver for published records"
```

---

### Task 7: Package templates (admin shell + frontend)

Shipped Twig templates referenced via the `@folio` namespace. Userland can override by placing same-named files under `resources/folio/`.

**Files:**
- Create: `packages/folio/templates/admin/_layout.twig`
- Create: `packages/folio/templates/admin/dashboard.twig`
- Create: `packages/folio/templates/admin/list.twig`
- Create: `packages/folio/templates/admin/form.twig`
- Create: `packages/folio/templates/frontend/page.twig`
- Test: `packages/folio/tests/TemplatesExistTest.php`

**Interfaces:**
- Produces: template paths `@folio/admin/dashboard.twig`, `@folio/admin/list.twig`, `@folio/admin/form.twig`, `@folio/frontend/page.twig`, consumed by Tasks 8–9. Context variables are defined per controller task.

- [ ] **Step 1: Write the failing test**

`packages/folio/tests/TemplatesExistTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

final class TemplatesExistTest extends TestCase
{
    public function test_shipped_templates_present(): void
    {
        $base = dirname(__DIR__) . '/templates';
        foreach ([
            '/admin/_layout.twig',
            '/admin/dashboard.twig',
            '/admin/list.twig',
            '/admin/form.twig',
            '/frontend/page.twig',
        ] as $rel) {
            $this->assertFileExists($base . $rel);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/TemplatesExistTest.php`
Expected: FAIL — files do not exist.

- [ ] **Step 3: Create the admin layout**

`packages/folio/templates/admin/_layout.twig`:
```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Folio{% endblock %}</title>
</head>
<body>
    <header><strong>Folio</strong></header>
    <nav>
        <a href="{{ prefix }}">Dashboard</a>
        {% for type in types %}
            <a href="{{ prefix }}/{{ type.key }}">{{ type.label }}</a>
        {% endfor %}
    </nav>
    <main>{% block content %}{% endblock %}</main>
</body>
</html>
```

- [ ] **Step 4: Create dashboard, list, form, frontend templates**

`packages/folio/templates/admin/dashboard.twig`:
```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — Dashboard{% endblock %}
{% block content %}
    <h1>Dashboard</h1>
    <ul>
        {% for type in types %}
            <li><a href="{{ prefix }}/{{ type.key }}">{{ type.label }}</a></li>
        {% endfor %}
    </ul>
{% endblock %}
```

`packages/folio/templates/admin/list.twig`:
```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — {{ label }}{% endblock %}
{% block content %}
    <h1>{{ label }}</h1>
    <a href="{{ prefix }}/{{ type }}/new">New</a>
    <table>
        <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th></th></tr></thead>
        <tbody>
        {% for row in rows %}
            <tr>
                <td>{{ row.title }}</td>
                <td>{{ row.slug }}</td>
                <td>{{ row.status }}</td>
                <td><a href="{{ prefix }}/{{ type }}/{{ row.id }}/edit">Edit</a></td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
{% endblock %}
```

`packages/folio/templates/admin/form.twig`:
```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — {{ label }}{% endblock %}
{% block content %}
    <h1>{{ heading }}</h1>
    {% set form = form_begin({action: action, csrf_token: csrf}) %}
    {{ form.begin()|raw }}
    {% for field in fields %}
        {{ form.field(field.name, {type: field.input, value: values[field.name], errors: errors[field.name]|default([])})|raw }}
    {% endfor %}
    {{ form.submit('Save')|raw }}
    {{ form_end()|raw }}
{% endblock %}
```

`packages/folio/templates/frontend/page.twig`:
```twig
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ record.title }}</title></head>
<body>
    <article>
        <h1>{{ record.title }}</h1>
        <div>{{ record.body|raw }}</div>
    </article>
</body>
</html>
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/TemplatesExistTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/templates packages/folio/tests/TemplatesExistTest.php
git commit -m "feat(folio): add shipped admin and frontend templates"
```

---

### Task 8: FrontendController

**Files:**
- Create: `packages/folio/src/Http/FrontendController.php`
- Test: `packages/folio/tests/Http/FrontendControllerTest.php`

**Interfaces:**
- Consumes: `FrontendResolver::resolve()`, `TemplateEngineInterface::render(string, array): string`, `Preflow\Core\Exceptions\NotFoundHttpException`.
- Produces: `FrontendController::__construct(FrontendResolver $resolver, TemplateEngineInterface $engine)`; `show(ServerRequestInterface $request): ResponseInterface` — renders `@folio/frontend/page.twig` with `record` (a `DynamicRecord` array) or throws `NotFoundHttpException`.

- [ ] **Step 1: Write the failing test**

`packages/folio/tests/Http/FrontendControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Http\FrontendController;
use Preflow\View\TemplateEngineInterface;

final class FrontendControllerTest extends TestCase
{
    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . ($context['record']['title'] ?? '');
            }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function addNamespace(string $namespace, string $path): void {}
        };
    }

    private function dm(string $models, string $store): DataManager
    {
        mkdir($models, 0777, true);
        mkdir($store, 0777, true);
        file_put_contents($models . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'body' => ['type' => 'text'], 'status' => ['type' => 'string']],
        ]));
        $registry = new TypeRegistry($models);
        $dm = new DataManager(['json' => new JsonFileDriver($store)], 'json', $registry);
        $rec = DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => '1', 'title' => 'Home', 'slug' => 'home', 'body' => 'Hi', 'status' => 'published',
        ]);
        $dm->saveType($rec, validate: false);
        return $dm;
    }

    public function test_renders_published_record(): void
    {
        $base = sys_get_temp_dir() . '/folio_fc_' . bin2hex(random_bytes(4));
        $dm = $this->dm($base . '/m', $base . '/s');
        $controller = new FrontendController(new FrontendResolver($dm, 'page'), $this->engine());

        $req = (new Psr17Factory())->createServerRequest('GET', '/home')->withAttribute('path', 'home');
        $res = $controller->show($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('@folio/frontend/page.twig|Home', (string) $res->getBody());
    }

    public function test_unknown_slug_throws_not_found(): void
    {
        $base = sys_get_temp_dir() . '/folio_fc_' . bin2hex(random_bytes(4));
        $dm = $this->dm($base . '/m', $base . '/s');
        $controller = new FrontendController(new FrontendResolver($dm, 'page'), $this->engine());

        $req = (new Psr17Factory())->createServerRequest('GET', '/missing')->withAttribute('path', 'missing');

        $this->expectException(NotFoundHttpException::class);
        $controller->show($req);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Http/FrontendControllerTest.php`
Expected: FAIL — `Preflow\Folio\Http\FrontendController` not found.

- [ ] **Step 3: Implement FrontendController**

`packages/folio/src/Http/FrontendController.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FrontendController
{
    public function __construct(
        private readonly FrontendResolver $resolver,
        private readonly TemplateEngineInterface $engine,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        $record = $this->resolver->resolve($path);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $record->toArray(),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Http/FrontendControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Http/FrontendController.php packages/folio/tests/Http/FrontendControllerTest.php
git commit -m "feat(folio): add frontend controller rendering records by slug"
```

---

### Task 9: AdminController

Server-rendered CRUD over discovered types. The `index` action demonstrates the override hook.

**Files:**
- Create: `packages/folio/src/Http/AdminController.php`
- Create: `packages/folio/src/Content/FieldMapper.php`
- Test: `packages/folio/tests/Http/AdminControllerTest.php`
- Test: `packages/folio/tests/Content/FieldMapperTest.php`

**Interfaces:**
- Consumes: `TypeCatalog`, `ActionResolver`, `TemplateEngineInterface`, `DataManager` (`findType`, `queryType`, `saveType`, `deleteType`), `TypeRegistry` (`get`, `has`), `TypeDefinition` (`->fields`, `->validationRules()`), `TypeFieldDefinition` (`->name`, `->type`), config value `prefix`.
- Produces: `AdminController::__construct(TypeCatalog $catalog, TypeRegistry $registry, DataManager $dm, TemplateEngineInterface $engine, ActionResolver $overrides, string $prefix)`; methods `index/list/createForm/store/editForm/update/destroy(ServerRequestInterface): ResponseInterface`. `FieldMapper::inputFor(string $fieldType): string` maps data type → form input type.

- [ ] **Step 1: Write the failing FieldMapper test**

`packages/folio/tests/Content/FieldMapperTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FieldMapper;

final class FieldMapperTest extends TestCase
{
    public function test_maps_known_types(): void
    {
        $this->assertSame('text', FieldMapper::inputFor('string'));
        $this->assertSame('textarea', FieldMapper::inputFor('text'));
        $this->assertSame('number', FieldMapper::inputFor('integer'));
    }

    public function test_unknown_defaults_to_text(): void
    {
        $this->assertSame('text', FieldMapper::inputFor('whatever'));
    }
}
```

- [ ] **Step 2: Run to verify it fails, then implement FieldMapper**

Run: `./vendor/bin/phpunit packages/folio/tests/Content/FieldMapperTest.php` → FAIL (class missing).

`packages/folio/src/Content/FieldMapper.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final class FieldMapper
{
    public static function inputFor(string $fieldType): string
    {
        return match ($fieldType) {
            'text' => 'textarea',
            'integer', 'int', 'float', 'number' => 'number',
            default => 'text',
        };
    }
}
```
Run again → PASS (2 tests).

- [ ] **Step 3: Write the failing AdminController test**

`packages/folio/tests/Http/AdminControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;

final class AdminControllerTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_admin_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'validate' => ['required']],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
    }

    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . json_encode(array_keys($context));
            }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controller(): AdminController
    {
        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $this->engine(),
            new ActionResolver(new Container()),
            '/folio',
        );
    }

    public function test_index_renders_dashboard(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio');
        $res = $this->controller()->index($req);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/dashboard.twig', (string) $res->getBody());
    }

    public function test_store_creates_record_then_redirects(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hello', 'slug' => 'hello', 'body' => 'B', 'status' => 'published']);

        $res = $this->controller()->store($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/page', $res->getHeaderLine('Location'));

        $rows = $this->dm->queryType('page')->where('slug', 'hello')->first();
        $this->assertNotNull($rows);
        $this->assertSame('Hello', $rows->get('title'));
    }

    public function test_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/ghost')->withAttribute('type', 'ghost');
        $res = $this->controller()->list($req);
        $this->assertSame(404, $res->getStatusCode());
    }
}
```

- [ ] **Step 4: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php`
Expected: FAIL — `Preflow\Folio\Http\AdminController` not found.

- [ ] **Step 5: Implement AdminController**

`packages/folio/src/Http/AdminController.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FieldMapper;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly ActionResolver $overrides,
        private readonly string $prefix,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (($override = $this->overrides->resolve('Content', 'Index')) !== null) {
            return $override->handle($request);
        }

        return $this->html($this->engine->render('@folio/admin/dashboard.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
        ]));
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $rows = [];
        foreach ($this->dm->queryType($type)->get()->items() as $record) {
            $rows[] = $record->toArray() + ['id' => $record->getId()];
        }

        return $this->html($this->engine->render('@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'rows' => $rows,
        ]));
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        return $this->form($type, [], $this->prefix . '/' . $type, 'New ' . $this->labelFor($type), []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $data = (array) $request->getParsedBody();
        $data[$typeDef->idField] = bin2hex(random_bytes(16));

        $record = DynamicRecord::fromArray($typeDef, $data);
        $this->dm->saveType($record);

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    public function editForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        return $this->form(
            $type,
            $record->toArray(),
            $this->prefix . '/' . $type . '/' . $id,
            'Edit ' . $this->labelFor($type),
            [],
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $data = (array) $request->getParsedBody();
        $data[$typeDef->idField] = $id;

        $record = DynamicRecord::fromArray($typeDef, $data);
        $this->dm->saveType($record);

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $this->dm->deleteType($type, $id);

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    /** @param array<string, mixed> $values @param array<string, list<string>> $errors */
    private function form(string $type, array $values, string $action, string $heading, array $errors): ResponseInterface
    {
        $typeDef = $this->registry->get($type);
        $fields = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fields[] = ['name' => $name, 'input' => FieldMapper::inputFor($fieldDef->type)];
        }

        return $this->html($this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'action' => $action,
            'csrf' => '', // populated by the app's csrf_token() function in real rendering
            'fields' => $fields,
            'values' => $values,
            'errors' => $errors,
        ]));
    }

    private function labelFor(string $type): string
    {
        foreach ($this->catalog->all() as $listing) {
            if ($listing->key === $type) {
                return $listing->label;
            }
        }
        return ucfirst($type);
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php`
Expected: PASS (3 tests).

> If `QueryBuilder::get()` returns something other than a `ResultSet` with `items()`, adjust the `list()` loop to the actual API (verify `packages/data/src/ResultSet.php`). The 2-arg `where()` note from Task 6 applies here too.

- [ ] **Step 7: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/src/Content/FieldMapper.php packages/folio/tests/Http/AdminControllerTest.php packages/folio/tests/Content/FieldMapperTest.php
git commit -m "feat(folio): add admin CRUD controller with override hook"
```

---

### Task 10: FolioServiceProvider — wire everything into an app

**Files:**
- Create: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/FolioServiceProviderTest.php`

**Interfaces:**
- Consumes: `Preflow\Core\Container\ServiceProvider` (abstract: `register(Container)`, `boot(Container)`), `Preflow\Core\Application` (`basePath()`, `config()`), `Preflow\Core\Config\Config` (`get()`), `Preflow\Routing\Router` (`getCollection()`), `RouteCollection` (`add()`, `addMany()`), `TemplateEngineInterface::addNamespace()`, `RouterInterface`.
- Produces: a provider that binds `TypeCatalog`, `TypeRegistry` (already bound by core? confirm — otherwise bind), `ActionResolver`, `FrontendResolver`, `AdminController`, `FrontendController`; registers `@folio` namespace (userland-first) and Folio routes.

- [ ] **Step 1: Write the failing test**

`packages/folio/tests/FolioServiceProviderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class FolioServiceProviderTest extends TestCase
{
    public function test_boot_appends_admin_and_frontend_routes(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);

        // A real router whose collection we can inspect.
        $router = new Router(pagesDir: null, controllers: []);
        $app->setRouter($router);
        $app->setActionDispatcher(fn ($route, $req) => new \Nyholm\Psr7\Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new \Nyholm\Psr7\Response(200));

        $provider = new FolioServiceProvider();
        $app->registerProvider($provider);
        $app->boot();

        $patterns = array_map(
            fn ($e) => $e->method . ' ' . $e->pattern,
            $router->getCollection()->all(),
        );

        $this->assertContains('GET /folio', $patterns);
        $this->assertContains('POST /folio/{type}', $patterns);
        $this->assertContains('GET /{...path}', $patterns); // frontend catch-all

        // Frontend catch-all must be the LAST entry (lowest priority).
        $last = end($patterns);
        $this->assertSame('GET /{...path}', $last);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/folio/tests/FolioServiceProviderTest.php`
Expected: FAIL — `Preflow\Folio\FolioServiceProvider` not found.

- [ ] **Step 3: Implement FolioServiceProvider**

`packages/folio/src/FolioServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\Core\Application;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Http\FrontendController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Routing\FolioRoutes;
use Preflow\Routing\Router;
use Preflow\View\TemplateEngineInterface;

final class FolioServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);
        $modelsPath = $this->modelsPath($app);
        $prefix = $this->prefix($app);

        $container->instance(TypeCatalog::class, new TypeCatalog($modelsPath));

        if (!$container->has(TypeRegistry::class)) {
            $container->instance(TypeRegistry::class, new TypeRegistry($modelsPath));
        }

        $container->bind(ActionResolver::class, fn (Container $c) => new ActionResolver($c));
        $container->bind(FrontendResolver::class, fn (Container $c) => new FrontendResolver($c->get(DataManager::class), 'page'));

        $container->bind(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(ActionResolver::class),
            $prefix,
        ));
        $container->bind(FrontendController::class, fn (Container $c) => new FrontendController(
            $c->get(FrontendResolver::class),
            $c->get(TemplateEngineInterface::class),
        ));
    }

    public function boot(Container $container): void
    {
        $app = $container->get(Application::class);

        // 1. Twig namespace: userland override dir first, package templates second.
        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
            $userDir = $app->basePath('resources/folio');
            if (is_dir($userDir)) {
                $engine->addNamespace('folio', $userDir);
            }
            $engine->addNamespace('folio', dirname(__DIR__) . '/templates');
        }

        // 2. Routes: admin under the configured prefix, then the frontend catch-all LAST.
        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                $collection = $router->getCollection(); // builds app routes first (lazy)
                $collection->addMany(FolioRoutes::admin($this->prefix($app)));
                $collection->add(FolioRoutes::frontend());
            }
        }
    }

    private function prefix(Application $app): string
    {
        return $this->folioConfig($app)['path'] ?? '/folio';
    }

    private function modelsPath(Application $app): string
    {
        $dataConfigPath = $app->basePath('config/data.php');
        if (is_file($dataConfigPath)) {
            $data = require $dataConfigPath;
            if (is_array($data) && isset($data['models_path']) && is_string($data['models_path'])) {
                return $data['models_path'];
            }
        }
        return $app->basePath('config/models');
    }

    /** @return array<string, mixed> */
    private function folioConfig(Application $app): array
    {
        $path = $app->basePath('config/folio.php');
        if (is_file($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return [];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/folio/tests/FolioServiceProviderTest.php`
Expected: PASS (1 test).

> If `boot()` runs before the router exists in this minimal harness, confirm boot order against `packages/core/src/Application.php` (`bootRouting()` precedes `bootProviders()`); the provided test sets the router explicitly before `boot()`, so the collection is present.

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/FolioServiceProvider.php packages/folio/tests/FolioServiceProviderTest.php
git commit -m "feat(folio): add service provider wiring namespace and routes"
```

---

### Task 11: Integration — wire Folio into the skeleton and prove the walking skeleton

End-to-end proof in the skeleton app: register the provider, add the `page` type, demo content, an override fixture, and an integration test that boots the real Application and asserts the full loop.

**Files:**
- Modify: `packages/skeleton/config/providers.php` (register `FolioServiceProvider`)
- Create: `packages/skeleton/config/folio.php`
- Create: `packages/skeleton/config/models/page.json`
- Create: `packages/skeleton/app/Folio/Overrides/Content/Index.php` (override demo)
- Modify: `packages/skeleton/composer.json` (require `preflow/folio`; ensure `App\` autoload)
- Test: `packages/skeleton/tests/FolioWalkingSkeletonTest.php`

**Interfaces:**
- Consumes: everything above. Produces: the runnable demo + green end-to-end test.

- [ ] **Step 1: Register the provider and config**

Add to `packages/skeleton/config/providers.php` return array:
```php
\Preflow\Folio\FolioServiceProvider::class,
```

Create `packages/skeleton/config/folio.php`:
```php
<?php

return [
    'path' => '/folio',
];
```

Create `packages/skeleton/config/models/page.json`:
```json
{
    "key": "page",
    "table": "page",
    "storage": "json",
    "id_field": "uuid",
    "label": "Pages",
    "fields": {
        "title":  { "type": "string", "searchable": true, "validate": ["required"] },
        "slug":   { "type": "string", "searchable": true, "validate": ["required"] },
        "body":   { "type": "text" },
        "status": { "type": "string", "validate": ["required", "in:draft,published"] }
    }
}
```

Add `preflow/folio` to `packages/skeleton/composer.json` `require` (`"preflow/folio": "@dev"`) and confirm `autoload.psr-4` maps `"App\\": "app/"`. Then from repo root: `composer update preflow/folio --no-interaction`.

- [ ] **Step 2: Create the override demo class**

`packages/skeleton/app/Folio/Overrides/Content/Index.php`:
```php
<?php

declare(strict_types=1);

namespace App\Folio\Overrides\Content;

use Nyholm\Psr7\Response;
use Preflow\Folio\Override\OverridableAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Index implements OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html'], '<h1>Custom Folio Dashboard</h1>');
    }
}
```

- [ ] **Step 3: Write the failing end-to-end test**

`packages/skeleton/tests/FolioWalkingSkeletonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

final class FolioWalkingSkeletonTest extends TestCase
{
    private function app(): Application
    {
        $app = Application::create(dirname(__DIR__));
        $app->boot();
        return $app;
    }

    private function get(string $uri): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    public function test_admin_dashboard_uses_override(): void
    {
        // app/Folio/Overrides/Content/Index.php overrides the dashboard.
        $res = $this->get('/folio');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Custom Folio Dashboard', (string) $res->getBody());
    }

    public function test_create_then_render_on_frontend(): void
    {
        $app = $this->app();

        $create = $app->handle(
            (new Psr17Factory())->createServerRequest('POST', '/folio/page')
                ->withParsedBody([
                    'title' => 'About Us', 'slug' => 'about', 'body' => '<p>Hi</p>', 'status' => 'published',
                ])
        );
        $this->assertSame(302, $create->getStatusCode());

        $page = $app->handle((new Psr17Factory())->createServerRequest('GET', '/about'));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('About Us', (string) $page->getBody());
    }

    public function test_unknown_slug_is_404(): void
    {
        $res = $this->get('/no-such-page');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_existing_app_route_beats_folio_catch_all(): void
    {
        // The skeleton serves '/' from app/pages/index.twig; Folio must not shadow it.
        $res = $this->get('/');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('404', (string) $res->getBody());
    }
}
```

- [ ] **Step 4: Run to verify it fails**

Run: `./vendor/bin/phpunit packages/skeleton/tests/FolioWalkingSkeletonTest.php`
Expected: FAIL initially (e.g. provider not loaded / storage dir missing). Create `packages/skeleton/storage/data/.gitkeep` if the JSON driver needs the base dir to exist; ensure it is writable.

- [ ] **Step 5: Make it pass**

Resolve failures iteratively against real APIs: ensure `storage/data` exists and is writable; confirm `csrf_token()` is registered (if CSRF middleware rejects the POST in tests, the test harness may need the token — if so, read the CSRF token via the app's session/middleware or disable CSRF for the test environment via `config/app.php` debug path; document whichever is used). Re-run until green.

Run: `./vendor/bin/phpunit packages/skeleton/tests/FolioWalkingSkeletonTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite + commit**

Run:
```bash
./vendor/bin/phpunit packages/folio/tests
./vendor/bin/phpunit packages/skeleton/tests
```
Expected: all green.

```bash
git add packages/skeleton composer.lock
git commit -m "feat(folio): wire Folio into skeleton with page type and end-to-end test"
```

---

## Notes for the implementer

- **CSRF in the POST tests (Task 11):** the form package emits `_csrf_token`, and `CsrfMiddleware` validates it. In an integration test you either (a) fetch the create form first and extract the token from the rendered HTML, or (b) run the test environment with CSRF disabled. Pick one, keep it explicit, and prefer (a) if it is not too costly.
- **`where()` arity** (Tasks 6, 9): verify whether `QueryBuilder::where()` accepts the 2-arg `('field', value)` form on the JSON driver; if not, use the 3-arg `('field', '=', value)` form everywhere.
- **`ResultSet::items()`** (Task 9): confirm the terminal `get()` shape; adapt the list loop to the real method names.
- **TemplateEngineInterface stubs** (Tasks 8, 9): the anonymous test doubles must implement EVERY method declared on `Preflow\View\TemplateEngineInterface`. Confirmed methods are `render`, `addFunction`, `addGlobal`, and (added in Task 2) `addNamespace`. If the interface declares more (e.g. an `exists()`), add them to each stub or the class will not satisfy the interface.
- **HTMX / inline validation / components** are intentionally absent — admin is plain POST+redirect. The field/editor system spec adds the hypermedia layer.
