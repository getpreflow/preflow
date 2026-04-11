# Navigation Component Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `RequestContext` service and a `Navigation` skeleton component with active route highlighting and co-located CSS.

**Architecture:** `RequestContext` (readonly value object) is registered in the DI container during `Application::handle()`. The `Navigation` component injects it, computes active state per item using prefix matching, and renders a styled `<nav>` with `{% apply css %}`. The layout template replaces its hardcoded nav with `{{ component('Navigation', {...}) }}`.

**Tech Stack:** PHP 8.5, Twig, PHPUnit

---

### Task 1: RequestContext Service

**Files:**
- Create: `packages/core/src/Http/RequestContext.php`
- Test: `packages/core/tests/Http/RequestContextTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\RequestContext;

final class RequestContextTest extends TestCase
{
    public function test_stores_path(): void
    {
        $context = new RequestContext(path: '/blog/my-post', method: 'GET');

        $this->assertSame('/blog/my-post', $context->path);
    }

    public function test_stores_method(): void
    {
        $context = new RequestContext(path: '/', method: 'POST');

        $this->assertSame('POST', $context->method);
    }

    public function test_is_readonly(): void
    {
        $reflection = new \ReflectionClass(RequestContext::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/core/tests/Http/RequestContextTest.php`
Expected: FAIL — class `Preflow\Core\Http\RequestContext` not found

- [ ] **Step 3: Write RequestContext**

Create `packages/core/src/Http/RequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

final readonly class RequestContext
{
    public function __construct(
        public string $path,
        public string $method,
    ) {}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/core/tests/Http/RequestContextTest.php`
Expected: 3 tests, 3 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Http/RequestContext.php packages/core/tests/Http/RequestContextTest.php
git commit -m "feat(core): add RequestContext readonly value object"
```

---

### Task 2: Register RequestContext in Application::handle()

**Files:**
- Modify: `packages/core/src/Application.php:155-168` (`handle()` method)
- Test: `packages/core/tests/ApplicationRequestContextTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/ApplicationRequestContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\RequestContext;

final class ApplicationRequestContextTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_reqctx_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        file_put_contents($this->tmpDir . '/config/app.php', '<?php return ["debug" => 0];');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/config/app.php');
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    public function test_handle_registers_request_context_in_container(): void
    {
        $app = Application::create($this->tmpDir);
        $app->boot();

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $request = $factory->createServerRequest('GET', '/blog/my-post');

        try {
            $app->handle($request);
        } catch (\Throwable) {
            // Router may throw (no routes configured) — that's fine,
            // RequestContext should be registered before routing
        }

        $context = $app->container()->get(RequestContext::class);

        $this->assertInstanceOf(RequestContext::class, $context);
        $this->assertSame('/blog/my-post', $context->path);
        $this->assertSame('GET', $context->method);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/core/tests/ApplicationRequestContextTest.php`
Expected: FAIL — `RequestContext` not found in container

- [ ] **Step 3: Add RequestContext registration to handle()**

In `packages/core/src/Application.php`, add the import near the top with other `use` statements:

```php
use Preflow\Core\Http\RequestContext;
```

Then modify the `handle()` method (currently lines 155-168). The current code is:

```php
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        // Component endpoint — intercept before routing
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/--component') && $this->container->has('preflow.component_endpoint')) {
            return $this->container->get('preflow.component_endpoint')->handle($request);
        }

        return $this->kernel->handle($request);
    }
```

Replace with:

```php
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        // Make request context available to components via DI
        $this->container->instance(RequestContext::class, new RequestContext(
            path: $request->getUri()->getPath(),
            method: $request->getMethod(),
        ));

        // Component endpoint — intercept before routing
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/--component') && $this->container->has('preflow.component_endpoint')) {
            return $this->container->get('preflow.component_endpoint')->handle($request);
        }

        return $this->kernel->handle($request);
    }
```

The `RequestContext` is registered before both the component endpoint and kernel handling, so it's available to all components regardless of request type.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/core/tests/ApplicationRequestContextTest.php`
Expected: 1 test, 3 assertions, PASS

- [ ] **Step 5: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Application.php packages/core/tests/ApplicationRequestContextTest.php
git commit -m "feat(core): register RequestContext in container during handle()"
```

---

### Task 3: Navigation Component PHP Class

**Files:**
- Modify: `composer.json` (add `App\\` to `autoload-dev` for testing skeleton components)
- Create: `packages/skeleton/app/Components/Navigation/Navigation.php`
- Test: `packages/skeleton/tests/NavigationActiveStateTest.php`

- [ ] **Step 0: Add skeleton App namespace to monorepo autoload-dev**

The skeleton's `App\\` namespace maps to `packages/skeleton/app/` but isn't autoloaded from the monorepo root. Add it so tests can import skeleton components.

In the root `composer.json`, change:
```json
    "autoload-dev": {},
```
to:
```json
    "autoload-dev": {
        "psr-4": {
            "App\\": "packages/skeleton/app/",
            "Tests\\": "packages/skeleton/tests/"
        }
    },
```

Then run:
```bash
composer dump-autoload
```

Also add `packages/skeleton/tests` as a test suite in `phpunit.xml`:
```xml
        <testsuite name="Skeleton">
            <directory>packages/skeleton/tests</directory>
        </testsuite>
```
Add it after the existing DevTools suite entry.

- [ ] **Step 1: Write the failing tests**

Create `packages/skeleton/tests/NavigationActiveStateTest.php`:

Note: We test the Navigation component's active-state logic by instantiating it directly with a `RequestContext`. This doesn't require the full rendering pipeline.

```php
<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\Navigation\Navigation;
use Preflow\Core\Http\RequestContext;

final class NavigationActiveStateTest extends TestCase
{
    private function createNavigation(string $path, array $items): Navigation
    {
        $context = new RequestContext(path: $path, method: 'GET');
        $nav = new Navigation($context);
        $nav->setProps(['items' => $items, 'brand' => 'Test']);
        $nav->resolveState();
        return $nav;
    }

    public function test_exact_match_for_home(): void
    {
        $nav = $this->createNavigation('/', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertTrue($nav->items[0]['active']);
        $this->assertFalse($nav->items[1]['active']);
    }

    public function test_home_not_active_on_other_pages(): void
    {
        $nav = $this->createNavigation('/blog', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertTrue($nav->items[1]['active']);
    }

    public function test_prefix_match_for_subpages(): void
    {
        $nav = $this->createNavigation('/blog/my-post', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
            ['path' => '/about', 'label' => 'About'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertTrue($nav->items[1]['active']);
        $this->assertFalse($nav->items[2]['active']);
    }

    public function test_no_match_all_inactive(): void
    {
        $nav = $this->createNavigation('/contact', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertFalse($nav->items[1]['active']);
    }

    public function test_empty_items(): void
    {
        $nav = $this->createNavigation('/', []);

        $this->assertSame([], $nav->items);
    }

    public function test_brand_prop(): void
    {
        $nav = $this->createNavigation('/', []);

        $this->assertSame('Test', $nav->brand);
    }

    public function test_brand_defaults_to_empty(): void
    {
        $context = new RequestContext(path: '/', method: 'GET');
        $nav = new Navigation($context);
        $nav->setProps(['items' => []]);
        $nav->resolveState();

        $this->assertSame('', $nav->brand);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/skeleton/tests/NavigationActiveStateTest.php`
Expected: FAIL — class `App\Components\Navigation\Navigation` not found

- [ ] **Step 3: Write Navigation component**

Create `packages/skeleton/app/Components/Navigation/Navigation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Components\Navigation;

use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;

final class Navigation extends Component
{
    protected string $tag = 'nav';

    /** @var array<int, array{path: string, label: string, active: bool}> */
    public array $items = [];

    public string $brand = '';

    public function __construct(
        private readonly RequestContext $requestContext,
    ) {}

    public function resolveState(): void
    {
        $currentPath = $this->requestContext->path;
        $this->brand = $this->props['brand'] ?? '';

        foreach ($this->props['items'] ?? [] as $item) {
            $path = $item['path'];
            if ($path === '/') {
                $active = $currentPath === '/';
            } else {
                $active = str_starts_with($currentPath, $path);
            }
            $this->items[] = [...$item, 'active' => $active];
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit packages/skeleton/tests/NavigationActiveStateTest.php`
Expected: 7 tests, 9 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add composer.json phpunit.xml packages/skeleton/app/Components/Navigation/Navigation.php packages/skeleton/tests/NavigationActiveStateTest.php
git commit -m "feat(skeleton): Navigation component with active state logic"
```

---

### Task 4: Navigation Twig Template with Co-located CSS

**Files:**
- Create: `packages/skeleton/app/Components/Navigation/Navigation.twig`

- [ ] **Step 1: Create the template**

Create `packages/skeleton/app/Components/Navigation/Navigation.twig`:

```twig
<div class="nav-inner">
    {% if brand %}
        <a href="/" class="nav-brand">{{ brand }}</a>
    {% endif %}
    <div class="nav-links">
        {% for item in items %}
            <a href="{{ item.path }}" class="nav-link{{ item.active ? ' nav-link--active' : '' }}">
                {{ t(item.label) }}
            </a>
        {% endfor %}
    </div>
</div>

{% apply css %}
.nav-inner {
    padding: 1rem 2rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.nav-brand {
    font-weight: 700;
    font-size: 1.125rem;
    color: #333;
    text-decoration: none;
}

.nav-brand:hover {
    color: #0066ff;
}

.nav-links {
    display: flex;
    gap: 1.25rem;
}

.nav-link {
    color: #555;
    text-decoration: none;
    padding: 0.25rem 0;
    border-bottom: 2px solid transparent;
    transition: color 0.15s, border-color 0.15s;
}

.nav-link:hover {
    color: #0066ff;
}

.nav-link--active {
    color: #0066ff;
    border-bottom-color: #0066ff;
    font-weight: 600;
}
{% endapply %}
```

- [ ] **Step 2: Verify the template file is auto-discovered**

The component auto-discovery looks for `Navigation/Navigation.twig` next to `Navigation/Navigation.php`. No registration needed.

- [ ] **Step 3: Commit**

```bash
git add packages/skeleton/app/Components/Navigation/Navigation.twig
git commit -m "feat(skeleton): Navigation template with co-located CSS and active state"
```

---

### Task 5: Update Layout to Use Navigation Component

**Files:**
- Modify: `packages/skeleton/app/pages/_layout.twig`

- [ ] **Step 1: Replace the hardcoded nav with the Navigation component**

In `packages/skeleton/app/pages/_layout.twig`, replace lines 9-14:

```twig
    <nav class="main-nav">
        <strong>Preflow</strong>
        <a href="/">{{ t('app.nav.home') }}</a>
        <a href="/blog">{{ t('app.nav.blog') }}</a>
        <a href="/about">{{ t('app.nav.about') }}</a>
    </nav>
```

with:

```twig
    {{ component('Navigation', {
        brand: 'Preflow',
        items: [
            { path: '/', label: 'app.nav.home' },
            { path: '/blog', label: 'app.nav.blog' },
            { path: '/about', label: 'app.nav.about' },
        ]
    }) }}
```

- [ ] **Step 2: Remove the old `.main-nav` CSS from the layout**

In the `{% apply css %}` block at the bottom of `_layout.twig`, remove the `.main-nav` rules (lines 42-62):

```css
.main-nav {
    padding: 1rem 2rem;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.main-nav strong {
    font-size: 1.125rem;
}

.main-nav a {
    color: #0066ff;
    text-decoration: none;
}

.main-nav a:hover {
    text-decoration: underline;
}
```

Keep all other CSS rules (body, `.main-content`, `.main-footer`, the `*` reset).

- [ ] **Step 3: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp/.worktrees/navigation && vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add packages/skeleton/app/pages/_layout.twig
git commit -m "feat(skeleton): replace hardcoded nav with Navigation component"
```

---

### Task 6: Update Test Project and Verify in Browser

**Files:**
- Copy updated files to `/Users/smyr/Sites/gbits/preflow/`

- [ ] **Step 1: Copy updated skeleton files to test project**

```bash
cp /Users/smyr/Sites/gbits/flopp/packages/skeleton/app/Components/Navigation/Navigation.php /Users/smyr/Sites/gbits/preflow/app/Components/Navigation/Navigation.php
cp /Users/smyr/Sites/gbits/flopp/packages/skeleton/app/Components/Navigation/Navigation.twig /Users/smyr/Sites/gbits/preflow/app/Components/Navigation/Navigation.twig
cp /Users/smyr/Sites/gbits/flopp/packages/skeleton/app/pages/_layout.twig /Users/smyr/Sites/gbits/preflow/app/pages/_layout.twig
```

Note: Create the `Navigation` directory first if it doesn't exist:
```bash
mkdir -p /Users/smyr/Sites/gbits/preflow/app/Components/Navigation
```

- [ ] **Step 2: Start dev server and verify**

```bash
cd /Users/smyr/Sites/gbits/preflow && php -S localhost:8080 -t public
```

Verify in browser at `http://localhost:8080`:
- Navigation renders with brand "Preflow" and three links
- "Home" link has active styling (blue color, underline) on `/`
- Navigate to `/blog` — "Blog" link becomes active, "Home" is not
- Navigate to a blog post — "Blog" link stays active (prefix match)
- Navigate to `/about` — "About" link becomes active
- CSS is co-located (check page source: nav styles appear in `<head>`)

- [ ] **Step 3: Verify the old hardcoded nav CSS is gone**

Check page source — no `.main-nav` rules in the `<style>` tag. Only `.nav-inner`, `.nav-brand`, `.nav-link`, `.nav-link--active` from the component.
