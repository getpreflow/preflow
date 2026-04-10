# Phase 4: preflow/components — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the components package (`preflow/components`) — the abstract `Component` base class with lifecycle hooks, `ComponentRenderer` with error boundaries, component ID generation, wrapper HTML, co-located template resolution, and the `{{ component() }}` Twig function for embedding components in templates.

**Architecture:** A `Component` subclass defines props, state, actions, and an optional fallback. The `ComponentRenderer` orchestrates the lifecycle (resolveState → render template → wrap HTML), catches exceptions at the component boundary (error boundaries), and delegates template rendering to the `TemplateEngineInterface`. Components are instantiated via the DI container for constructor injection. A Twig function `{{ component('Name', {props}) }}` enables embedding components within templates.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, preflow/core (Container, Config), preflow/view (TemplateEngineInterface, AssetCollector, TwigEngine)

---

## File Structure

```
packages/components/
├── src/
│   ├── Component.php                   — Abstract base class with lifecycle hooks
│   ├── ComponentRenderer.php           — Renders components with error boundaries
│   ├── ErrorBoundary.php               — Dev/prod error rendering for failed components
│   ├── Twig/
│   │   └── ComponentExtension.php      — {{ component('Name', {props}) }} Twig function
├── tests/
│   ├── ComponentTest.php               — Tests for Component base class
│   ├── ComponentRendererTest.php       — Tests for rendering + error boundaries
│   ├── ErrorBoundaryTest.php           — Tests for dev/prod error output
│   ├── Twig/
│   │   └── ComponentExtensionTest.php  — Tests for {{ component() }} function
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/components/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/components/composer.json**

```json
{
    "name": "preflow/components",
    "description": "Preflow components — component base class, renderer, error boundaries",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.1 || @dev",
        "preflow/view": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Components\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Components\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json**

Add to `repositories` array:

```json
{
    "type": "path",
    "url": "packages/components",
    "options": { "symlink": true }
}
```

Add `"preflow/components": "@dev"` to `require-dev`.

- [ ] **Step 3: Update phpunit.xml**

Add testsuite:

```xml
<testsuite name="Components">
    <directory>packages/components/tests</directory>
</testsuite>
```

Add to source include:

```xml
<directory>packages/components/src</directory>
```

- [ ] **Step 4: Create directories and install**

```bash
mkdir -p packages/components/src/Twig packages/components/tests/Twig
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/components/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/components package"
```

---

### Task 2: Component Base Class

**Files:**
- Create: `packages/components/src/Component.php`
- Create: `packages/components/tests/ComponentTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/components/tests/ComponentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;

class SimpleComponent extends Component
{
    public string $title = '';

    protected function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Default';
    }

    protected function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(): void
    {
        $this->title = 'Refreshed';
    }
}

class ComponentWithFallback extends Component
{
    protected function resolveState(): void
    {
        throw new \RuntimeException('Broken on purpose');
    }

    protected function fallback(\Throwable $e): string
    {
        return '<div class="error">Fallback: ' . $e->getMessage() . '</div>';
    }
}

class ComponentWithTag extends Component
{
    public string $tag = 'section';
}

final class ComponentTest extends TestCase
{
    public function test_component_id_generated_from_class(): void
    {
        $component = new SimpleComponent();

        $this->assertNotEmpty($component->getComponentId());
        $this->assertStringContainsString('SimpleComponent', $component->getComponentId());
    }

    public function test_component_id_includes_props_hash(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['id' => '1']);

        $b = new SimpleComponent();
        $b->setProps(['id' => '2']);

        $this->assertNotSame($a->getComponentId(), $b->getComponentId());
    }

    public function test_same_props_produce_same_id(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['id' => '1']);

        $b = new SimpleComponent();
        $b->setProps(['id' => '1']);

        $this->assertSame($a->getComponentId(), $b->getComponentId());
    }

    public function test_resolve_state_sets_properties(): void
    {
        $component = new SimpleComponent();
        $component->setProps(['title' => 'Hello']);
        $component->resolveState();

        $this->assertSame('Hello', $component->title);
    }

    public function test_resolve_state_uses_default(): void
    {
        $component = new SimpleComponent();
        $component->resolveState();

        $this->assertSame('Default', $component->title);
    }

    public function test_actions_returns_whitelist(): void
    {
        $component = new SimpleComponent();

        $this->assertSame(['refresh'], $component->actions());
    }

    public function test_handle_action_calls_method(): void
    {
        $component = new SimpleComponent();
        $component->resolveState();
        $component->handleAction('refresh');

        $this->assertSame('Refreshed', $component->title);
    }

    public function test_handle_action_throws_on_unlisted(): void
    {
        $component = new SimpleComponent();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('not allowed');
        $component->handleAction('delete');
    }

    public function test_handle_action_throws_on_nonexistent(): void
    {
        $component = new SimpleComponent();

        $this->expectException(\BadMethodCallException::class);
        $component->handleAction('nonexistent');
    }

    public function test_fallback_returns_null_by_default(): void
    {
        $component = new SimpleComponent();

        $this->assertNull($component->fallback(new \RuntimeException('test')));
    }

    public function test_fallback_returns_custom_html(): void
    {
        $component = new ComponentWithFallback();

        $result = $component->fallback(new \RuntimeException('oops'));

        $this->assertStringContainsString('Fallback: oops', $result);
    }

    public function test_get_tag_defaults_to_div(): void
    {
        $component = new SimpleComponent();

        $this->assertSame('div', $component->getTag());
    }

    public function test_get_tag_can_be_overridden(): void
    {
        $component = new ComponentWithTag();

        $this->assertSame('section', $component->getTag());
    }

    public function test_get_template_path_from_class_location(): void
    {
        $component = new SimpleComponent();

        $path = $component->getTemplatePath();

        // Should look for SimpleComponent.twig in same dir as class file
        $this->assertStringEndsWith('SimpleComponent.twig', $path);
    }

    public function test_get_template_context_includes_public_properties(): void
    {
        $component = new SimpleComponent();
        $component->setProps(['title' => 'Test']);
        $component->resolveState();

        $context = $component->getTemplateContext();

        $this->assertSame('Test', $context['title']);
        $this->assertArrayHasKey('componentId', $context);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/components/tests/ComponentTest.php
```

- [ ] **Step 3: Implement Component base class**

Create `packages/components/src/Component.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components;

abstract class Component
{
    /** @var array<string, mixed> */
    protected array $props = [];

    protected string $tag = 'div';

    private ?string $componentId = null;

    /**
     * Load state from database, session, cache, etc.
     * Override in subclass.
     */
    public function resolveState(): void
    {
    }

    /**
     * Return a list of action names that can be called via the component endpoint.
     *
     * @return string[]
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Return fallback HTML when this component fails to render.
     * Override in subclass. Return null to use the default error handling.
     */
    public function fallback(\Throwable $e): ?string
    {
        return null;
    }

    /**
     * Dispatch an action by name.
     *
     * @param array<string, mixed> $params POST/request parameters
     */
    public function handleAction(string $action, array $params = []): void
    {
        if (!in_array($action, $this->actions(), true)) {
            throw new \BadMethodCallException(
                "Action [{$action}] is not allowed on " . static::class . "."
            );
        }

        $method = 'action' . ucfirst($action);

        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                "Action method [{$method}] does not exist on " . static::class . "."
            );
        }

        $this->{$method}($params);
    }

    /**
     * @param array<string, mixed> $props
     */
    public function setProps(array $props): void
    {
        $this->props = $props;
        $this->componentId = null; // reset on prop change
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * Get the unique component ID (class name + props hash).
     */
    public function getComponentId(): string
    {
        if ($this->componentId === null) {
            $shortName = (new \ReflectionClass($this))->getShortName();
            $propsHash = substr(hash('xxh3', serialize($this->props)), 0, 8);
            $this->componentId = $shortName . '-' . $propsHash;
        }

        return $this->componentId;
    }

    /**
     * Get the wrapper HTML tag.
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * Get the path to the co-located template file.
     */
    public function getTemplatePath(): string
    {
        $ref = new \ReflectionClass($this);
        $dir = dirname($ref->getFileName());
        $name = $ref->getShortName();

        return $dir . '/' . $name . '.twig';
    }

    /**
     * Get template context variables (all public properties + componentId).
     *
     * @return array<string, mixed>
     */
    public function getTemplateContext(): array
    {
        $ref = new \ReflectionClass($this);
        $context = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isInitialized($this)) {
                $context[$prop->getName()] = $prop->getValue($this);
            }
        }

        $context['componentId'] = $this->getComponentId();

        return $context;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/components/tests/ComponentTest.php
```

Expected: All 15 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/components/src/Component.php packages/components/tests/ComponentTest.php
git commit -m "feat(components): add Component base class with lifecycle hooks"
```

---

### Task 3: ErrorBoundary

**Files:**
- Create: `packages/components/src/ErrorBoundary.php`
- Create: `packages/components/tests/ErrorBoundaryTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/components/tests/ErrorBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ErrorBoundary;

class FallbackComponent extends Component
{
    protected function fallback(\Throwable $e): string
    {
        return '<div class="custom-fallback">Oops</div>';
    }
}

class NoFallbackComponent extends Component
{
}

final class ErrorBoundaryTest extends TestCase
{
    public function test_dev_mode_shows_exception_class(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Something broke');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function test_dev_mode_shows_message(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Detailed error info');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('Detailed error info', $html);
    }

    public function test_dev_mode_shows_component_class(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('NoFallbackComponent', $html);
    }

    public function test_dev_mode_shows_props(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $component->setProps(['id' => '42', 'slug' => 'test']);
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('test', $html);
    }

    public function test_dev_mode_shows_stack_trace(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('ErrorBoundaryTest.php', $html);
    }

    public function test_dev_mode_shows_lifecycle_phase(): void
    {
        $boundary = new ErrorBoundary(debug: true);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component, 'resolveState');

        $this->assertStringContainsString('resolveState', $html);
    }

    public function test_prod_mode_uses_component_fallback(): void
    {
        $boundary = new ErrorBoundary(debug: false);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('secret details');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('custom-fallback', $html);
        $this->assertStringContainsString('Oops', $html);
        $this->assertStringNotContainsString('secret details', $html);
    }

    public function test_prod_mode_uses_generic_fallback_when_no_custom(): void
    {
        $boundary = new ErrorBoundary(debug: false);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('secret details');

        $html = $boundary->render($exception, $component);

        $this->assertStringNotContainsString('secret details', $html);
        $this->assertNotEmpty($html); // should render something
    }

    public function test_prod_mode_hides_stack_trace(): void
    {
        $boundary = new ErrorBoundary(debug: false);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringNotContainsString('ErrorBoundaryTest.php', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/components/tests/ErrorBoundaryTest.php
```

- [ ] **Step 3: Implement ErrorBoundary**

Create `packages/components/src/ErrorBoundary.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components;

final class ErrorBoundary
{
    public function __construct(
        private readonly bool $debug = false,
    ) {}

    public function render(
        \Throwable $exception,
        Component $component,
        string $phase = 'unknown',
    ): string {
        if ($this->debug) {
            return $this->renderDev($exception, $component, $phase);
        }

        return $this->renderProd($exception, $component);
    }

    private function renderDev(\Throwable $exception, Component $component, string $phase): string
    {
        $class = $this->esc($exception::class);
        $message = $this->esc($exception->getMessage());
        $componentClass = $this->esc($component::class);
        $componentId = $this->esc($component->getComponentId());
        $file = $this->esc($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->esc($exception->getTraceAsString());
        $phase = $this->esc($phase);
        $props = $this->esc(json_encode($component->getProps(), JSON_PRETTY_PRINT));

        return <<<HTML
        <div style="border:2px solid #e74c3c;background:#1a1a2e;color:#eee;padding:1rem;border-radius:0.5rem;margin:0.5rem 0;font-family:system-ui,sans-serif;font-size:0.875rem;">
            <div style="background:#e74c3c;margin:-1rem -1rem 1rem;padding:0.75rem 1rem;border-radius:0.375rem 0.375rem 0 0;">
                <strong>{$class}</strong>: {$message}
            </div>
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.25rem 1rem;margin:0;">
                <dt style="color:#888;">Component</dt><dd style="margin:0;font-family:monospace;">{$componentClass}</dd>
                <dt style="color:#888;">ID</dt><dd style="margin:0;font-family:monospace;">{$componentId}</dd>
                <dt style="color:#888;">Phase</dt><dd style="margin:0;font-family:monospace;">{$phase}</dd>
                <dt style="color:#888;">Props</dt><dd style="margin:0;font-family:monospace;white-space:pre-wrap;">{$props}</dd>
                <dt style="color:#888;">File</dt><dd style="margin:0;font-family:monospace;">{$file}:{$line}</dd>
            </dl>
            <details style="margin-top:0.75rem;">
                <summary style="cursor:pointer;color:#888;">Stack Trace</summary>
                <pre style="margin:0.5rem 0 0;font-size:0.75rem;overflow-x:auto;white-space:pre-wrap;">{$trace}</pre>
            </details>
        </div>
        HTML;
    }

    private function renderProd(\Throwable $exception, Component $component): string
    {
        $fallback = $component->fallback($exception);

        if ($fallback !== null) {
            return $fallback;
        }

        return '<div style="display:none;" data-component-error="true"></div>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/components/tests/ErrorBoundaryTest.php
```

Expected: All 9 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/components/src/ErrorBoundary.php packages/components/tests/ErrorBoundaryTest.php
git commit -m "feat(components): add ErrorBoundary with dev/prod rendering"
```

---

### Task 4: ComponentRenderer

**Files:**
- Create: `packages/components/src/ComponentRenderer.php`
- Create: `packages/components/tests/ComponentRendererTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/components/tests/ComponentRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\View\TemplateEngineInterface;

class RenderableComponent extends Component
{
    public string $title = '';

    protected function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Hello';
    }
}

class BrokenComponent extends Component
{
    protected function resolveState(): void
    {
        throw new \RuntimeException('State resolution failed');
    }
}

class BrokenWithFallback extends Component
{
    protected function resolveState(): void
    {
        throw new \RuntimeException('Broke');
    }

    protected function fallback(\Throwable $e): string
    {
        return '<p>Component unavailable</p>';
    }
}

class SectionComponent extends Component
{
    protected string $tag = 'section';
}

class FakeTemplateEngine implements TemplateEngineInterface
{
    public ?string $lastTemplate = null;
    public ?array $lastContext = null;
    public string $output = '<p>rendered</p>';

    public function render(string $template, array $context = []): string
    {
        $this->lastTemplate = $template;
        $this->lastContext = $context;
        return $this->output;
    }

    public function exists(string $template): bool
    {
        return true;
    }
}

final class ComponentRendererTest extends TestCase
{
    private FakeTemplateEngine $engine;
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        $this->engine = new FakeTemplateEngine();
        $this->renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: true),
        );
    }

    public function test_renders_component_with_wrapper(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Test']);

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('<div id="', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringContainsString('<p>rendered</p>', $html);
    }

    public function test_wrapper_has_component_id(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Test']);

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('id="' . $component->getComponentId() . '"', $html);
    }

    public function test_resolves_state_before_render(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Resolved']);

        $this->renderer->render($component);

        $this->assertSame('Resolved', $this->engine->lastContext['title']);
    }

    public function test_passes_template_context_to_engine(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Context']);

        $this->renderer->render($component);

        $this->assertArrayHasKey('title', $this->engine->lastContext);
        $this->assertArrayHasKey('componentId', $this->engine->lastContext);
    }

    public function test_uses_co_located_template_path(): void
    {
        $component = new RenderableComponent();

        $this->renderer->render($component);

        $this->assertStringEndsWith('RenderableComponent.twig', $this->engine->lastTemplate);
    }

    public function test_error_boundary_catches_resolve_state_error(): void
    {
        $component = new BrokenComponent();

        $html = $this->renderer->render($component);

        // Error boundary output (dev mode)
        $this->assertStringContainsString('State resolution failed', $html);
        $this->assertStringContainsString('BrokenComponent', $html);
    }

    public function test_error_boundary_shows_phase(): void
    {
        $component = new BrokenComponent();

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('resolveState', $html);
    }

    public function test_prod_error_boundary_uses_fallback(): void
    {
        $renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: false),
        );

        $component = new BrokenWithFallback();

        $html = $renderer->render($component);

        $this->assertStringContainsString('Component unavailable', $html);
        $this->assertStringNotContainsString('Broke', $html);
    }

    public function test_custom_tag_in_wrapper(): void
    {
        $component = new SectionComponent();

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('<section id="', $html);
        $this->assertStringContainsString('</section>', $html);
    }

    public function test_render_fragment_returns_inner_html_only(): void
    {
        $component = new RenderableComponent();

        $html = $this->renderer->renderFragment($component);

        $this->assertSame('<p>rendered</p>', $html);
        $this->assertStringNotContainsString('<div id=', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/components/tests/ComponentRendererTest.php
```

- [ ] **Step 3: Implement ComponentRenderer**

Create `packages/components/src/ComponentRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\View\TemplateEngineInterface;

final class ComponentRenderer
{
    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
    ) {}

    /**
     * Render a component with wrapper HTML and error boundary.
     */
    public function render(Component $component): string
    {
        try {
            $component->resolveState();
            $innerHtml = $this->renderTemplate($component);
            return $this->wrapHtml($component, $innerHtml);
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    /**
     * Render a component fragment (inner HTML only, no wrapper).
     * Used for HTMX partial responses.
     */
    public function renderFragment(Component $component): string
    {
        try {
            $component->resolveState();
            return $this->renderTemplate($component);
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    private function renderTemplate(Component $component): string
    {
        $templatePath = $component->getTemplatePath();
        $context = $component->getTemplateContext();

        return $this->templateEngine->render($templatePath, $context);
    }

    private function wrapHtml(Component $component, string $innerHtml): string
    {
        $tag = $component->getTag();
        $id = htmlspecialchars($component->getComponentId(), ENT_QUOTES, 'UTF-8');

        return "<{$tag} id=\"{$id}\">{$innerHtml}</{$tag}>";
    }

    /**
     * Detect which lifecycle phase failed based on the stack trace.
     */
    private function detectPhase(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        if (str_contains($trace, 'resolveState') || str_contains($e->getFile(), 'resolveState')) {
            return 'resolveState';
        }

        // Check if the exception was thrown from within the component class
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['function'])) {
                if ($frame['function'] === 'resolveState') {
                    return 'resolveState';
                }
                if ($frame['function'] === 'handleAction') {
                    return 'handleAction';
                }
            }
        }

        return 'render';
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/components/tests/ComponentRendererTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/components/src/ComponentRenderer.php packages/components/tests/ComponentRendererTest.php
git commit -m "feat(components): add ComponentRenderer with error boundaries"
```

---

### Task 5: ComponentExtension — Twig {{ component() }} Function

**Files:**
- Create: `packages/components/src/Twig/ComponentExtension.php`
- Create: `packages/components/tests/Twig/ComponentExtensionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/components/tests/Twig/ComponentExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\Twig\ComponentExtension;
use Preflow\View\TemplateEngineInterface;

class TwigTestComponent extends Component
{
    public string $message = '';

    protected function resolveState(): void
    {
        $this->message = $this->props['message'] ?? 'default';
    }
}

class TwigBrokenComponent extends Component
{
    protected function resolveState(): void
    {
        throw new \RuntimeException('Component broke');
    }

    protected function fallback(\Throwable $e): string
    {
        return '<p>Fallback rendered</p>';
    }
}

class StubTemplateEngine implements TemplateEngineInterface
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . ($context['message'] ?? 'no message') . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }
}

final class ComponentExtensionTest extends TestCase
{
    private Environment $twig;
    private ComponentRenderer $renderer;

    /** @var array<string, class-string<Component>> */
    private array $componentMap;

    protected function setUp(): void
    {
        $engine = new StubTemplateEngine();
        $this->renderer = new ComponentRenderer(
            templateEngine: $engine,
            errorBoundary: new ErrorBoundary(debug: false),
        );

        $this->componentMap = [
            'TestComponent' => TwigTestComponent::class,
            'BrokenComponent' => TwigBrokenComponent::class,
        ];

        $extension = new ComponentExtension($this->renderer, $this->componentMap);

        $this->twig = new Environment(new ArrayLoader([]), [
            'autoescape' => false,
        ]);
        $this->twig->addExtension($extension);
    }

    private function render(string $template): string
    {
        return $this->twig->createTemplate($template)->render([]);
    }

    public function test_component_function_renders_component(): void
    {
        $result = $this->render("{{ component('TestComponent', { message: 'Hello' }) }}");

        $this->assertStringContainsString('Hello', $result);
    }

    public function test_component_function_wraps_in_div(): void
    {
        $result = $this->render("{{ component('TestComponent') }}");

        $this->assertStringContainsString('<div id="', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    public function test_component_function_with_error_uses_fallback(): void
    {
        $result = $this->render("{{ component('BrokenComponent') }}");

        $this->assertStringContainsString('Fallback rendered', $result);
    }

    public function test_unknown_component_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown component');

        $this->render("{{ component('NonExistent') }}");
    }

    public function test_component_renders_alongside_html(): void
    {
        $result = $this->render("<h1>Title</h1>{{ component('TestComponent', { message: 'World' }) }}<footer>end</footer>");

        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('World', $result);
        $this->assertStringContainsString('<footer>end</footer>', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/components/tests/Twig/ComponentExtensionTest.php
```

- [ ] **Step 3: Implement ComponentExtension**

Create `packages/components/src/Twig/ComponentExtension.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;

final class ComponentExtension extends AbstractExtension
{
    /**
     * @param ComponentRenderer $renderer
     * @param array<string, class-string<Component>> $componentMap Short name → FQCN
     */
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly array $componentMap = [],
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('component', $this->renderComponent(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $props
     */
    public function renderComponent(string $name, array $props = []): string
    {
        $className = $this->resolveClass($name);
        $component = new $className();
        $component->setProps($props);

        return $this->renderer->render($component);
    }

    /**
     * @return class-string<Component>
     */
    private function resolveClass(string $name): string
    {
        // Check the component map first
        if (isset($this->componentMap[$name])) {
            return $this->componentMap[$name];
        }

        // Check if it's already a FQCN
        if (class_exists($name) && is_subclass_of($name, Component::class)) {
            return $name;
        }

        throw new \InvalidArgumentException(
            "Unknown component [{$name}]. Register it in the component map or pass a fully qualified class name."
        );
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/components/tests/Twig/ComponentExtensionTest.php
```

Expected: All 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/components/src/Twig/ComponentExtension.php packages/components/tests/Twig/ComponentExtensionTest.php
git commit -m "feat(components): add ComponentExtension with {{ component() }} Twig function"
```

---

### Task 6: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass (core + routing + view + components).

- [ ] **Step 2: Verify package integrates**

```bash
php -r "
require 'vendor/autoload.php';
echo 'Component loads: OK' . PHP_EOL;
echo 'ComponentRenderer loads: OK' . PHP_EOL;
echo 'ErrorBoundary loads: OK' . PHP_EOL;
echo 'ComponentExtension loads: OK' . PHP_EOL;
"
```

- [ ] **Step 3: Commit if cleanup needed**

---

## Phase 4 Deliverables

After completing all tasks, the `preflow/components` package provides:

| Component | What It Does |
|---|---|
| `Component` | Abstract base class with resolveState, actions, handleAction, fallback, props, componentId, template path, template context |
| `ComponentRenderer` | Orchestrates lifecycle (resolveState → render → wrap), error boundaries, fragment rendering for HTMX |
| `ErrorBoundary` | Dev: rich inline panel with class, props, phase, trace. Prod: component fallback or hidden div |
| `ComponentExtension` | Twig `{{ component('Name', {props}) }}` function for embedding components in templates |

**Next phase:** `preflow/data` — storage interface, drivers (SQLite, MySQL, JSON), models, migrations.
