# Template Engine Abstraction + Blade Adapter — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple all packages from Twig, restructure into `preflow/view` (interfaces), `preflow/twig` (Twig adapter), and `preflow/blade` (Blade adapter), with engine-agnostic extension providers.

**Architecture:** `preflow/view` defines interfaces (`TemplateEngineInterface`, `TemplateFunctionDefinition`, `TemplateExtensionProvider`). Engine packages implement them. Each feature package (components, htmx, i18n) provides template functions via `TemplateExtensionProvider`, not engine-specific extensions. `Application.php` wires everything through interfaces only.

**Tech Stack:** PHP 8.5, Twig 3, illuminate/view 11, PHPUnit

---

## Phase A: Interfaces in preflow/view

### Task 1: New interfaces and value objects

**Files:**
- Create: `packages/view/src/TemplateFunctionDefinition.php`
- Create: `packages/view/src/TemplateExtensionProvider.php`
- Modify: `packages/view/src/TemplateEngineInterface.php`
- Test: `packages/view/tests/TemplateFunctionDefinitionTest.php`

- [ ] **Step 1: Write tests for TemplateFunctionDefinition**

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\TemplateFunctionDefinition;

final class TemplateFunctionDefinitionTest extends TestCase
{
    public function test_stores_name(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'component',
            callable: fn () => '',
        );

        $this->assertSame('component', $fn->name);
    }

    public function test_stores_callable(): void
    {
        $callable = fn (string $name) => "<div>{$name}</div>";
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: $callable,
        );

        $this->assertSame('<div>hello</div>', ($fn->callable)('hello'));
    }

    public function test_is_safe_defaults_to_false(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: fn () => '',
        );

        $this->assertFalse($fn->isSafe);
    }

    public function test_is_safe_can_be_true(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: fn () => '',
            isSafe: true,
        );

        $this->assertTrue($fn->isSafe);
    }

    public function test_is_readonly(): void
    {
        $reflection = new \ReflectionClass(TemplateFunctionDefinition::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/view/tests/TemplateFunctionDefinitionTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create TemplateFunctionDefinition**

Create `packages/view/src/TemplateFunctionDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

final readonly class TemplateFunctionDefinition
{
    public function __construct(
        public string $name,
        public \Closure $callable,
        public bool $isSafe = false,
    ) {}
}
```

- [ ] **Step 4: Create TemplateExtensionProvider interface**

Create `packages/view/src/TemplateExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

interface TemplateExtensionProvider
{
    /** @return TemplateFunctionDefinition[] */
    public function getTemplateFunctions(): array;

    /** @return array<string, mixed> */
    public function getTemplateGlobals(): array;
}
```

- [ ] **Step 5: Expand TemplateEngineInterface**

Replace the full content of `packages/view/src/TemplateEngineInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

interface TemplateEngineInterface
{
    /**
     * Render a template file with the given context variables.
     *
     * @param string               $template Path to the template file
     * @param array<string, mixed> $context  Variables available in the template
     * @return string Rendered HTML
     */
    public function render(string $template, array $context = []): string;

    /**
     * Check if a template exists.
     */
    public function exists(string $template): bool;

    /**
     * Register a template function that will be callable from templates.
     */
    public function addFunction(TemplateFunctionDefinition $function): void;

    /**
     * Make a variable available in all templates.
     */
    public function addGlobal(string $name, mixed $value): void;

    /**
     * Get the file extension for this engine's templates (e.g., 'twig', 'blade.php').
     */
    public function getTemplateExtension(): string;
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/view/tests/TemplateFunctionDefinitionTest.php`
Expected: 5 tests, 5 assertions, all PASS

- [ ] **Step 7: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: FAIL — TwigEngine no longer satisfies TemplateEngineInterface (missing `addFunction`, `addGlobal`, `getTemplateExtension`). This is expected; Task 3 fixes it.

- [ ] **Step 8: Commit**

```bash
git add packages/view/src/TemplateFunctionDefinition.php packages/view/src/TemplateExtensionProvider.php packages/view/src/TemplateEngineInterface.php packages/view/tests/TemplateFunctionDefinitionTest.php
git commit -m "feat(view): add TemplateFunctionDefinition, TemplateExtensionProvider, expand TemplateEngineInterface"
```

---

## Phase B: Create preflow/twig package

### Task 2: Create preflow/twig package structure

**Files:**
- Create: `packages/twig/composer.json`
- Create: `packages/twig/src/` directory

- [ ] **Step 1: Create package directory and composer.json**

```bash
mkdir -p packages/twig/src packages/twig/tests
```

Create `packages/twig/composer.json`:

```json
{
    "name": "preflow/twig",
    "description": "Preflow Twig adapter — Twig template engine implementation",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/view": "^0.1 || @dev",
        "twig/twig": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Twig\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Twig\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add Twig test suite to phpunit.xml**

Add after the existing View suite:
```xml
        <testsuite name="Twig">
            <directory>packages/twig/tests</directory>
        </testsuite>
```

Also add to `<source><include>`:
```xml
            <directory>packages/twig/src</directory>
```

- [ ] **Step 3: Add to monorepo root composer.json**

Add `preflow/twig` PSR-4 mapping to the root `composer.json` autoload (check current structure and add alongside existing package mappings). Add the path repository if packages use symlinks.

Run: `composer dump-autoload`

- [ ] **Step 4: Commit**

```bash
git add packages/twig/composer.json phpunit.xml composer.json
git commit -m "feat(twig): create preflow/twig package structure"
```

---

### Task 3: Move TwigEngine and implement new interface methods

**Files:**
- Move: `packages/view/src/Twig/TwigEngine.php` → `packages/twig/src/TwigEngine.php`
- Move: `packages/view/src/Twig/PreflowExtension.php` → `packages/twig/src/PreflowExtension.php`
- Move: `packages/view/tests/Twig/TwigEngineTest.php` → `packages/twig/tests/TwigEngineTest.php`
- Move: `packages/view/tests/Twig/PreflowExtensionTest.php` → `packages/twig/tests/PreflowExtensionTest.php`
- Modify: `packages/view/composer.json` (remove `twig/twig` dependency)
- Test: `packages/twig/tests/TwigEngineTest.php`

- [ ] **Step 1: Move files and update namespaces**

Move the files:
```bash
mv packages/view/src/Twig/TwigEngine.php packages/twig/src/TwigEngine.php
mv packages/view/src/Twig/PreflowExtension.php packages/twig/src/PreflowExtension.php
mv packages/view/tests/Twig/TwigEngineTest.php packages/twig/tests/TwigEngineTest.php
mv packages/view/tests/Twig/PreflowExtensionTest.php packages/twig/tests/PreflowExtensionTest.php
rmdir packages/view/src/Twig packages/view/tests/Twig
```

Update namespace in `TwigEngine.php` from `Preflow\View\Twig` to `Preflow\Twig`.
Update namespace in `PreflowExtension.php` from `Preflow\View\Twig` to `Preflow\Twig`.
Update test namespaces similarly.
Update `use` statements: `PreflowExtension` import in TwigEngine now references `Preflow\Twig\PreflowExtension` (same package, no change needed since they share a namespace).

- [ ] **Step 2: Implement new interface methods in TwigEngine**

Add these methods to `TwigEngine`:

```php
    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $options = $function->isSafe ? ['is_safe' => ['html']] : [];
        $this->twig->addFunction(new TwigFunction(
            $function->name,
            $function->callable,
            $options,
        ));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    public function getTemplateExtension(): string
    {
        return 'twig';
    }
```

Add the required import:
```php
use Preflow\View\TemplateFunctionDefinition;
```

**Remove the `getTwig()` method entirely.** It was the abstraction leak.

- [ ] **Step 3: Write tests for new methods**

Add to `packages/twig/tests/TwigEngineTest.php`:

```php
    public function test_add_function_makes_function_callable(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        $tpl = $this->engine->render('test_func.twig');

        $this->assertStringContainsString('Hello, World!', $tpl);
    }

    public function test_add_function_escapes_by_default(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'raw',
            callable: fn () => '<strong>bold</strong>',
        ));

        $tpl = $this->engine->render('test_raw.twig');

        $this->assertStringContainsString('&lt;strong&gt;', $tpl);
    }

    public function test_add_global_makes_variable_available(): void
    {
        $this->engine->addGlobal('siteName', 'Preflow');

        $tpl = $this->engine->render('test_global.twig');

        $this->assertStringContainsString('Preflow', $tpl);
    }

    public function test_get_template_extension_returns_twig(): void
    {
        $this->assertSame('twig', $this->engine->getTemplateExtension());
    }

    public function test_get_twig_does_not_exist(): void
    {
        $this->assertFalse(method_exists($this->engine, 'getTwig'));
    }
```

Create the corresponding test template files in the test fixtures directory (check where existing TwigEngine tests store their templates):
- `test_func.twig`: `{{ greet('World') }}`
- `test_raw.twig`: `{{ raw() }}`
- `test_global.twig`: `{{ siteName }}`

- [ ] **Step 4: Remove twig/twig from view's composer.json**

In `packages/view/composer.json`, remove `"twig/twig": "^3.0"` from `require`. The view package is now interfaces-only (plus AssetCollector, NonceGenerator, JsPosition).

Update the description:
```json
"description": "Preflow view — template engine interfaces and asset pipeline",
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit packages/twig/tests/`
Expected: All pass

Run: `vendor/bin/phpunit`
Expected: FAIL — Application.php still references `\Preflow\View\Twig\TwigEngine`. Expected; Task 7 fixes Application.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(twig): move TwigEngine to preflow/twig, implement addFunction/addGlobal/getTemplateExtension, remove getTwig()"
```

---

### Task 4: Move Twig extensions to preflow/twig

**Files:**
- Move: `packages/components/src/Twig/ComponentExtension.php` → `packages/twig/src/ComponentExtension.php`
- Move: `packages/htmx/src/Twig/HdExtension.php` → `packages/twig/src/HdExtension.php`
- Move: `packages/i18n/src/Twig/TranslationExtension.php` → `packages/twig/src/TranslationExtension.php`

- [ ] **Step 1: Move files**

```bash
mv packages/components/src/Twig/ComponentExtension.php packages/twig/src/ComponentExtension.php
rmdir packages/components/src/Twig
mv packages/htmx/src/Twig/HdExtension.php packages/twig/src/HdExtension.php
rmdir packages/htmx/src/Twig
mv packages/i18n/src/Twig/TranslationExtension.php packages/twig/src/TranslationExtension.php
rmdir packages/i18n/src/Twig
```

- [ ] **Step 2: Update namespaces**

All three files change namespace to `Preflow\Twig`:

- `ComponentExtension.php`: `namespace Preflow\Twig;` (was `Preflow\Components\Twig`)
- `HdExtension.php`: `namespace Preflow\Twig;` (was `Preflow\Htmx\Twig`)
- `TranslationExtension.php`: `namespace Preflow\Twig;` (was `Preflow\I18n\Twig`)

Update their `use` statements as needed (e.g., `ComponentExtension` still imports `Preflow\Components\Component`, `Preflow\Components\ComponentRenderer`).

- [ ] **Step 3: Update preflow/twig composer.json dependencies**

Add dependencies that the moved extensions need:

```json
"require": {
    "php": ">=8.4",
    "preflow/view": "^0.1 || @dev",
    "preflow/components": "^0.1 || @dev",
    "twig/twig": "^3.0"
},
"suggest": {
    "preflow/htmx": "Required for HdExtension (hd.post, hd.get helpers)",
    "preflow/i18n": "Required for TranslationExtension (t(), tc() helpers)"
}
```

Note: htmx and i18n are `suggest`, not `require`, because the extensions are only loaded when those packages are installed.

- [ ] **Step 4: Remove twig/twig from htmx and i18n dev dependencies**

In `packages/htmx/composer.json`, remove `"twig/twig": "^3.0"` from `require-dev`.
In `packages/i18n/composer.json`, remove `"twig/twig": "^3.0"` from `require-dev`.

- [ ] **Step 5: Run composer dump-autoload**

```bash
composer dump-autoload
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: move all Twig extensions to preflow/twig package"
```

---

## Phase C: Extension Providers

### Task 5: Create extension providers

**Files:**
- Create: `packages/components/src/ComponentsExtensionProvider.php`
- Create: `packages/htmx/src/HtmxExtensionProvider.php`
- Create: `packages/i18n/src/TranslationExtensionProvider.php`
- Test: `packages/components/tests/ComponentsExtensionProviderTest.php`
- Test: `packages/htmx/tests/HtmxExtensionProviderTest.php`
- Test: `packages/i18n/tests/TranslationExtensionProviderTest.php`

- [ ] **Step 1: Write tests for ComponentsExtensionProvider**

```php
<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentsExtensionProvider;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateEngineInterface;

final class ComponentsExtensionProviderTest extends TestCase
{
    public function test_provides_component_function(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::Off));
        $provider = new ComponentsExtensionProvider($renderer, [], null);
        $functions = $provider->getTemplateFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('component', $functions[0]->name);
        $this->assertTrue($functions[0]->isSafe);
        $this->assertInstanceOf(TemplateFunctionDefinition::class, $functions[0]);
    }

    public function test_globals_are_empty(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::Off));
        $provider = new ComponentsExtensionProvider($renderer, [], null);

        $this->assertSame([], $provider->getTemplateGlobals());
    }
}
```

- [ ] **Step 2: Write tests for HtmxExtensionProvider**

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmxExtensionProvider;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\ComponentToken;
use Preflow\View\TemplateFunctionDefinition;

final class HtmxExtensionProviderTest extends TestCase
{
    public function test_provides_hd_function(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);
        $functions = $provider->getTemplateFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('hd', $functions[0]->name);
        $this->assertTrue($functions[0]->isSafe);
    }

    public function test_provides_hd_global(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);
        $globals = $provider->getTemplateGlobals();

        $this->assertArrayHasKey('hd', $globals);
        $this->assertSame($provider, $globals['hd']);
    }

    public function test_post_returns_html_attributes(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);

        $result = $provider->post('increment', 'App\\Counter', 'Counter-abc123', []);

        $this->assertStringContainsString('hx-post=', $result);
    }
}
```

- [ ] **Step 3: Write tests for TranslationExtensionProvider**

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\TranslationExtensionProvider;
use Preflow\I18n\Translator;
use Preflow\View\TemplateFunctionDefinition;

final class TranslationExtensionProviderTest extends TestCase
{
    private TranslationExtensionProvider $provider;

    protected function setUp(): void
    {
        $langDir = __DIR__ . '/fixtures/lang';
        if (!is_dir($langDir . '/en')) {
            mkdir($langDir . '/en', 0755, true);
            file_put_contents($langDir . '/en/app.php', "<?php return ['hello' => 'Hello'];");
        }
        $translator = new Translator($langDir, 'en', 'en');
        $this->provider = new TranslationExtensionProvider($translator);
    }

    public function test_provides_t_and_tc_functions(): void
    {
        $functions = $this->provider->getTemplateFunctions();

        $this->assertCount(2, $functions);
        $this->assertSame('t', $functions[0]->name);
        $this->assertSame('tc', $functions[1]->name);
        $this->assertTrue($functions[0]->isSafe);
        $this->assertTrue($functions[1]->isSafe);
    }

    public function test_t_function_translates(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $t = $functions[0]->callable;

        $this->assertSame('Hello', $t('app.hello'));
    }

    public function test_globals_are_empty(): void
    {
        $this->assertSame([], $this->provider->getTemplateGlobals());
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/components/tests/ComponentsExtensionProviderTest.php packages/htmx/tests/HtmxExtensionProviderTest.php packages/i18n/tests/TranslationExtensionProviderTest.php`
Expected: FAIL — classes not found

- [ ] **Step 5: Implement ComponentsExtensionProvider**

Create `packages/components/src/ComponentsExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class ComponentsExtensionProvider implements TemplateExtensionProvider
{
    /** @var callable(string, array): Component|null */
    private $componentFactory;

    /**
     * @param ComponentRenderer $renderer
     * @param array<string, class-string<Component>> $componentMap Short name -> FQCN
     * @param callable(string $class, array $props): Component|null $componentFactory
     */
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly array $componentMap = [],
        ?callable $componentFactory = null,
    ) {
        $this->componentFactory = $componentFactory;
    }

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'component',
                callable: fn (string $name, array $props = []) => $this->renderComponent($name, $props),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    public function renderComponent(string $name, array $props = []): string
    {
        $className = $this->resolveClass($name);

        if ($this->componentFactory !== null) {
            $component = ($this->componentFactory)($className, $props);
        } else {
            $component = new $className();
            $component->setProps($props);
        }

        return $this->renderer->render($component);
    }

    /**
     * @return class-string<Component>
     */
    private function resolveClass(string $name): string
    {
        if (isset($this->componentMap[$name])) {
            return $this->componentMap[$name];
        }

        if (class_exists($name) && is_subclass_of($name, Component::class)) {
            return $name;
        }

        throw new \InvalidArgumentException(
            "Unknown component [{$name}]. Register it in the component map or pass a fully qualified class name."
        );
    }
}
```

- [ ] **Step 6: Implement HtmxExtensionProvider**

Create `packages/htmx/src/HtmxExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class HtmxExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly HypermediaDriver $driver,
        private readonly ComponentToken $token,
        private readonly string $endpointPrefix = '/--component',
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'hd',
                callable: fn () => $this,
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return ['hd' => $this];
    }

    /**
     * Generate action attributes for POST.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function post(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('post', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate action attributes for GET.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function get(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('get', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate event listening attributes.
     *
     * @param array<string, mixed> $props
     */
    public function on(
        string $event,
        string $componentClass,
        string $componentId,
        array $props = [],
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, 'render');
        $url = $this->endpointPrefix . '/render?token=' . urlencode($tokenStr);

        $attrs = $this->driver->listenAttrs($event, $url, $componentId);

        return (string) $attrs;
    }

    /**
     * Get the hypermedia library asset tag.
     */
    public function assetTag(): string
    {
        return $this->driver->assetTag();
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    private function actionAttrs(
        string $method,
        string $action,
        string $componentClass,
        string $componentId,
        array $props,
        SwapStrategy $swap,
        array $extra,
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        $url = $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);

        $attrs = $this->driver->actionAttrs($method, $url, $componentId, $swap, $extra);

        return (string) $attrs;
    }
}
```

- [ ] **Step 7: Implement TranslationExtensionProvider**

Create `packages/i18n/src/TranslationExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class TranslationExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 't',
                callable: fn (string $key, array $params = [], ?int $count = null) =>
                    $count !== null ? $this->translator->choice($key, $count, $params) : $this->translator->get($key, $params),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'tc',
                callable: fn (string $key, string $componentName, array $params = []) =>
                    $this->translator->get($this->componentKey($componentName, $key), $params),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    private function componentKey(string $componentName, string $key): string
    {
        $group = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        return $group . '.' . $key;
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/components/tests/ComponentsExtensionProviderTest.php packages/htmx/tests/HtmxExtensionProviderTest.php packages/i18n/tests/TranslationExtensionProviderTest.php`
Expected: All PASS

- [ ] **Step 9: Commit**

```bash
git add packages/components/src/ComponentsExtensionProvider.php packages/htmx/src/HtmxExtensionProvider.php packages/i18n/src/TranslationExtensionProvider.php packages/components/tests/ComponentsExtensionProviderTest.php packages/htmx/tests/HtmxExtensionProviderTest.php packages/i18n/tests/TranslationExtensionProviderTest.php
git commit -m "feat: add engine-agnostic extension providers for components, htmx, i18n"
```

---

## Phase D: Application Cleanup

### Task 6: Update Component::getTemplatePath() for engine-agnostic extension

**Files:**
- Modify: `packages/components/src/Component.php:109-115`
- Modify: `packages/components/src/ComponentRenderer.php` (pass engine extension)

- [ ] **Step 1: Add templateExtension parameter to Component::getTemplatePath()**

In `packages/components/src/Component.php`, replace the `getTemplatePath()` method:

```php
    public function getTemplatePath(string $extension = 'twig'): string
    {
        $ref = new \ReflectionClass($this);
        $dir = dirname($ref->getFileName());
        $name = $ref->getShortName();

        return $dir . '/' . $name . '.' . $extension;
    }
```

- [ ] **Step 2: Update ComponentRenderer to pass engine extension**

In `packages/components/src/ComponentRenderer.php`, the constructor already accepts `TemplateEngineInterface`. Update the `renderTemplate()` method (or wherever `getTemplatePath()` is called) to pass the engine's extension:

Find where `$component->getTemplatePath()` is called and change to:
```php
$component->getTemplatePath($this->templateEngine->getTemplateExtension())
```

- [ ] **Step 3: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: Some tests may fail due to ongoing refactor — check and fix any immediate breaks.

- [ ] **Step 4: Commit**

```bash
git add packages/components/src/Component.php packages/components/src/ComponentRenderer.php
git commit -m "feat(components): engine-agnostic template path resolution"
```

---

### Task 7: Rewrite Application.php to be engine-agnostic

**Files:**
- Modify: `packages/core/src/Application.php` (bootViewLayer, bootComponentLayer, bootI18n)

This is the core task. All Twig references must be removed from Application.php.

- [ ] **Step 1: Rewrite bootViewLayer()**

Replace the current `bootViewLayer()` method with:

```php
    private function bootViewLayer(DebugLevel $debug): void
    {
        $nonce = new \Preflow\View\NonceGenerator();
        $assets = new \Preflow\View\AssetCollector($nonce, isProd: !$debug->isDebug());
        $this->container->instance(\Preflow\View\AssetCollector::class, $assets);
        $this->container->instance(\Preflow\View\NonceGenerator::class, $nonce);

        $pagesDir = $this->basePath('app/pages');
        $templateDirs = is_dir($pagesDir) ? [$pagesDir] : [];

        $engineName = $this->config->get('app.engine', 'twig');
        $engine = $this->createTemplateEngine($engineName, $templateDirs, $assets, $debug);

        if ($engine === null) {
            return;
        }

        $this->container->instance(\Preflow\View\TemplateEngineInterface::class, $engine);
    }

    private function createTemplateEngine(
        string $name,
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
    ): ?\Preflow\View\TemplateEngineInterface {
        return match ($name) {
            'twig' => class_exists(\Preflow\Twig\TwigEngine::class)
                ? new \Preflow\Twig\TwigEngine(
                    templateDirs: $templateDirs,
                    assetCollector: $assets,
                    debug: $debug->isDebug(),
                )
                : null,
            'blade' => class_exists(\Preflow\Blade\BladeEngine::class)
                ? new \Preflow\Blade\BladeEngine(
                    templateDirs: $templateDirs,
                    assetCollector: $assets,
                    debug: $debug->isDebug(),
                )
                : null,
            default => throw new \RuntimeException("Unknown template engine: {$name}. Supported: twig, blade"),
        };
    }
```

- [ ] **Step 2: Rewrite bootComponentLayer() — remove getTwig() calls**

Replace `bootComponentLayer()`. Key changes:
- Remove `$engine = $this->container->get(\Preflow\View\Twig\TwigEngine::class)` (line 274)
- Remove `$engine->getTwig()->addExtension(new \Preflow\Components\Twig\ComponentExtension(...))` (lines 294-296)
- Remove `$engine->getTwig()->addExtension(new \Preflow\Htmx\Twig\HdExtension(...))` (lines 314-316)
- Replace with extension provider registration:

```php
    private function bootComponentLayer(DebugLevel $debug, string $secretKey): void
    {
        if (!class_exists(\Preflow\Components\ComponentRenderer::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

        $errorBoundary = new \Preflow\Components\ErrorBoundary(debug: $debug);
        $renderer = new \Preflow\Components\ComponentRenderer($engine, $errorBoundary);
        $this->container->instance(\Preflow\Components\ComponentRenderer::class, $renderer);

        // Auto-discover components
        $componentMap = $this->discoverComponents();

        // Component factory — uses DI container for constructor injection
        $container = $this->container;
        $componentFactory = function (string $class, array $props) use ($container) {
            $component = $container->has($class) ? $container->get($class) : $container->make($class);
            $component->setProps($props);
            return $component;
        };

        // Register component template functions
        $componentProvider = new \Preflow\Components\ComponentsExtensionProvider(
            $renderer, $componentMap, $componentFactory
        );
        $this->registerExtensionProvider($engine, $componentProvider);

        // HTMX driver
        if (class_exists(\Preflow\Htmx\HtmxDriver::class)) {
            $responseHeaders = new \Preflow\Htmx\ResponseHeaders();
            $htmxDriver = new \Preflow\Htmx\HtmxDriver($responseHeaders);
            $componentToken = new \Preflow\Htmx\ComponentToken($secretKey);

            $this->container->instance(\Preflow\Htmx\ResponseHeaders::class, $responseHeaders);
            $this->container->instance(\Preflow\Htmx\HypermediaDriver::class, $htmxDriver);
            $this->container->instance(\Preflow\Htmx\HtmxDriver::class, $htmxDriver);
            $this->container->instance(\Preflow\Htmx\ComponentToken::class, $componentToken);

            // Register HTMX library script tag in <head>
            $assets = $this->container->get(\Preflow\View\AssetCollector::class);
            $assets->addHeadTag($htmxDriver->assetTag());

            // Register hd template functions
            $htmxProvider = new \Preflow\Htmx\HtmxExtensionProvider($htmxDriver, $componentToken);
            $this->registerExtensionProvider($engine, $htmxProvider);

            // Component endpoint
            $container = $this->container;
            $endpoint = new \Preflow\Htmx\ComponentEndpoint(
                token: $componentToken,
                renderer: $renderer,
                driver: $htmxDriver,
                componentFactory: function (string $class, array $props) use ($container) {
                    $component = $container->has($class) ? $container->get($class) : new $class();
                    $component->setProps($props);
                    return $component;
                },
            );
            $this->container->instance('preflow.component_endpoint', $endpoint);
            $this->container->instance(\Preflow\Htmx\ComponentEndpoint::class, $endpoint);
        }
    }
```

- [ ] **Step 3: Rewrite bootI18n() — remove getTwig() calls**

Replace the Twig-specific section in `bootI18n()`:

```php
    private function bootI18n(): void
    {
        if (!class_exists(\Preflow\I18n\Translator::class)) {
            return;
        }

        $langDir = $this->basePath('lang');
        if (!is_dir($langDir)) {
            return;
        }

        $locale = $this->config->get('app.locale', 'en');
        $i18nConfig = [];
        $i18nPath = $this->basePath('config/i18n.php');
        if (file_exists($i18nPath)) {
            $i18nConfig = require $i18nPath;
        }

        $fallback = $i18nConfig['fallback'] ?? $locale;
        $translator = new \Preflow\I18n\Translator($langDir, $locale, $fallback);
        $this->container->instance(\Preflow\I18n\Translator::class, $translator);

        // Register t()/tc() template functions
        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
            $translationProvider = new \Preflow\I18n\TranslationExtensionProvider($translator);
            $this->registerExtensionProvider($engine, $translationProvider);
        }

        // Locale middleware
        if (class_exists(\Preflow\I18n\LocaleMiddleware::class)) {
            $available = $i18nConfig['available'] ?? [$locale];
            $strategy = $i18nConfig['url_strategy'] ?? 'prefix';
            $this->addMiddleware(new \Preflow\I18n\LocaleMiddleware(
                $translator, $available, $locale, $strategy
            ));
        }
    }
```

- [ ] **Step 4: Add registerExtensionProvider() helper**

Add this private method to Application:

```php
    private function registerExtensionProvider(
        \Preflow\View\TemplateEngineInterface $engine,
        \Preflow\View\TemplateExtensionProvider $provider,
    ): void {
        foreach ($provider->getTemplateFunctions() as $function) {
            $engine->addFunction($function);
        }
        foreach ($provider->getTemplateGlobals() as $name => $value) {
            $engine->addGlobal($name, $value);
        }
    }
```

- [ ] **Step 5: Update ensureComponentRenderer()**

Find `ensureComponentRenderer()` and replace any reference to `\Preflow\View\Twig\TwigEngine` with `\Preflow\View\TemplateEngineInterface`.

- [ ] **Step 6: Remove all Twig imports from Application.php**

Remove any remaining `use` statements referencing `Preflow\View\Twig\*` or `Preflow\Twig\*` or `Twig\*`. Application should only import from `Preflow\View\*` (interfaces), `Preflow\Core\*`, `Preflow\Components\*`, `Preflow\Htmx\*`, `Preflow\I18n\*`.

- [ ] **Step 7: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass. If tests reference old namespaces (`\Preflow\View\Twig\TwigEngine`, `\Preflow\Components\Twig\ComponentExtension`, etc.), update them.

- [ ] **Step 8: Commit**

```bash
git add packages/core/src/Application.php
git commit -m "refactor(core): Application is fully engine-agnostic, zero Twig imports"
```

---

### Task 8: Update all tests referencing old namespaces

**Files:**
- Modify: Any test files that reference `\Preflow\View\Twig\TwigEngine`, `\Preflow\Components\Twig\ComponentExtension`, `\Preflow\Htmx\Twig\HdExtension`, or `\Preflow\I18n\Twig\TranslationExtension`
- Modify: `packages/testing/src/TestApplication.php` (if it references Twig)
- Modify: `packages/testing/src/ComponentTestCase.php` (if it references Twig)

- [ ] **Step 1: Search for all old namespace references**

```bash
grep -r "Preflow\\\\View\\\\Twig" packages/*/tests/ packages/*/src/ --include="*.php" -l
grep -r "Preflow\\\\Components\\\\Twig" packages/*/tests/ packages/*/src/ --include="*.php" -l
grep -r "Preflow\\\\Htmx\\\\Twig" packages/*/tests/ packages/*/src/ --include="*.php" -l
grep -r "Preflow\\\\I18n\\\\Twig" packages/*/tests/ packages/*/src/ --include="*.php" -l
```

- [ ] **Step 2: Update each file**

For each file found:
- Replace `Preflow\View\Twig\TwigEngine` with `Preflow\Twig\TwigEngine`
- Replace `Preflow\Components\Twig\ComponentExtension` with `Preflow\Twig\ComponentExtension`
- Replace `Preflow\Htmx\Twig\HdExtension` with `Preflow\Twig\HdExtension`
- Replace `Preflow\I18n\Twig\TranslationExtension` with `Preflow\Twig\TranslationExtension`

- [ ] **Step 3: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: update all test references to new Twig package namespace"
```

---

## Phase E: Blade Adapter

### Task 9: Create preflow/blade package structure

**Files:**
- Create: `packages/blade/composer.json`
- Create: `packages/blade/src/` directory

- [ ] **Step 1: Create package**

```bash
mkdir -p packages/blade/src packages/blade/tests
```

Create `packages/blade/composer.json`:

```json
{
    "name": "preflow/blade",
    "description": "Preflow Blade adapter — Laravel Blade template engine implementation",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/view": "^0.1 || @dev",
        "illuminate/view": "^11.0 || ^12.0",
        "illuminate/filesystem": "^11.0 || ^12.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Blade\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Blade\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add Blade test suite to phpunit.xml and autoload**

Add to `phpunit.xml`:
```xml
        <testsuite name="Blade">
            <directory>packages/blade/tests</directory>
        </testsuite>
```

Add to `<source><include>`:
```xml
            <directory>packages/blade/src</directory>
```

Add to monorepo `composer.json` autoload and run `composer require illuminate/view illuminate/filesystem --dev` at the monorepo level.

Run: `composer dump-autoload`

- [ ] **Step 3: Commit**

```bash
git add packages/blade/composer.json phpunit.xml composer.json composer.lock
git commit -m "feat(blade): create preflow/blade package structure"
```

---

### Task 10: BladeEngine implementation

**Files:**
- Create: `packages/blade/src/BladeEngine.php`
- Test: `packages/blade/tests/BladeEngineTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;

final class BladeEngineTest extends TestCase
{
    private BladeEngine $engine;
    private string $tmpDir;
    private AssetCollector $assets;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_blade_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . '/cache', 0755, true);

        $this->assets = new AssetCollector(new NonceGenerator());
        $this->engine = new BladeEngine(
            templateDirs: [$this->tmpDir],
            assetCollector: $this->assets,
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function test_renders_simple_template(): void
    {
        file_put_contents($this->tmpDir . '/hello.blade.php', 'Hello, {{ $name }}!');

        $html = $this->engine->render('hello', ['name' => 'World']);

        $this->assertSame('Hello, World!', $html);
    }

    public function test_escapes_output_by_default(): void
    {
        file_put_contents($this->tmpDir . '/escape.blade.php', '{{ $content }}');

        $html = $this->engine->render('escape', ['content' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_raw_output(): void
    {
        file_put_contents($this->tmpDir . '/raw.blade.php', '{!! $content !!}');

        $html = $this->engine->render('raw', ['content' => '<strong>bold</strong>']);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_exists_returns_true_for_existing_template(): void
    {
        file_put_contents($this->tmpDir . '/exists.blade.php', 'yes');

        $this->assertTrue($this->engine->exists('exists'));
    }

    public function test_exists_returns_false_for_missing_template(): void
    {
        $this->assertFalse($this->engine->exists('nonexistent'));
    }

    public function test_extends_and_sections(): void
    {
        file_put_contents($this->tmpDir . '/_layout.blade.php', '<html>@yield("content")</html>');
        file_put_contents($this->tmpDir . '/page.blade.php', '@extends("_layout")' . "\n" . '@section("content")Hello@endsection');

        $html = $this->engine->render('page');

        $this->assertStringContainsString('<html>Hello</html>', $html);
    }

    public function test_if_directive(): void
    {
        file_put_contents($this->tmpDir . '/cond.blade.php', '@if($show)visible@endif');

        $html = $this->engine->render('cond', ['show' => true]);
        $this->assertStringContainsString('visible', $html);

        $html = $this->engine->render('cond', ['show' => false]);
        $this->assertStringNotContainsString('visible', $html);
    }

    public function test_foreach_directive(): void
    {
        file_put_contents($this->tmpDir . '/loop.blade.php', '@foreach($items as $item){{ $item }}@endforeach');

        $html = $this->engine->render('loop', ['items' => ['a', 'b', 'c']]);

        $this->assertSame('abc', $html);
    }

    public function test_add_function_registers_directive(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        file_put_contents($this->tmpDir . '/func.blade.php', '@greet("World")');

        $html = $this->engine->render('func');

        $this->assertStringContainsString('Hello, World!', $html);
    }

    public function test_add_global_makes_variable_available(): void
    {
        $this->engine->addGlobal('siteName', 'Preflow');

        file_put_contents($this->tmpDir . '/global.blade.php', '{{ $siteName }}');

        $html = $this->engine->render('global');

        $this->assertStringContainsString('Preflow', $html);
    }

    public function test_get_template_extension_returns_blade_php(): void
    {
        $this->assertSame('blade.php', $this->engine->getTemplateExtension());
    }

    public function test_css_directive_feeds_asset_collector(): void
    {
        file_put_contents($this->tmpDir . '/styled.blade.php', '@css .test { color: red; } @endcss<div>content</div>');

        $this->engine->render('styled');

        $css = $this->assets->renderCss();
        $this->assertStringContainsString('.test { color: red; }', $css);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/blade/tests/BladeEngineTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement BladeEngine**

Create `packages/blade/src/BladeEngine.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateEngineInterface;

final class BladeEngine implements TemplateEngineInterface
{
    private readonly Factory $viewFactory;
    private readonly BladeCompiler $compiler;
    private array $globals = [];

    /**
     * @param string[] $templateDirs
     */
    public function __construct(
        array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
    ) {
        $filesystem = new Filesystem();
        $cachePath ??= sys_get_temp_dir() . '/preflow_blade_cache';

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $this->compiler = new BladeCompiler($filesystem, $cachePath);

        // Register @css / @endcss directive pair
        $this->registerAssetDirectives($assetCollector);

        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($this->compiler, $filesystem));

        $finder = new FileViewFinder($filesystem, $templateDirs);

        $this->viewFactory = new Factory($resolver, $finder, new \Illuminate\Events\Dispatcher());
    }

    public function render(string $template, array $context = []): string
    {
        $context = array_merge($this->globals, $context);

        // Absolute path — create a temporary template
        if (str_starts_with($template, '/') && file_exists($template)) {
            $tmpName = '_abs_' . md5($template);
            $dir = dirname($template);
            $this->viewFactory->getFinder()->addLocation($dir);
            return $this->viewFactory->make(basename($template, '.blade.php'), $context)->render();
        }

        return $this->viewFactory->make($template, $context)->render();
    }

    public function exists(string $template): bool
    {
        try {
            $this->viewFactory->getFinder()->find($template);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $name = $function->name;
        $isSafe = $function->isSafe;

        // Store callable as a shared view variable for runtime access
        $this->viewFactory->share("__fn_{$name}", $function->callable);
        $this->globals["__fn_{$name}"] = $function->callable;

        // Register Blade directive that invokes the stored callable
        $this->compiler->directive($name, function (string $expression) use ($name, $isSafe) {
            if ($isSafe) {
                return "<?php echo \$__fn_{$name}({$expression}); ?>";
            }
            return "<?php echo e(\$__fn_{$name}({$expression})); ?>";
        });
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
        $this->viewFactory->share($name, $value);
    }

    public function getTemplateExtension(): string
    {
        return 'blade.php';
    }

    private function registerAssetDirectives(AssetCollector $assetCollector): void
    {
        // Store asset collector for runtime access
        $this->globals['__assetCollector'] = $assetCollector;

        $this->compiler->directive('css', function () {
            return '<?php ob_start(); ?>';
        });

        $this->compiler->directive('endcss', function () {
            return '<?php $__assetCollector->addCss(ob_get_clean()); ?>';
        });
    }
}
```

Note: The Blade adapter's `addFunction()` and `@css`/`@endcss` implementations may need refinement during implementation. The engineer should test each directive works with Blade's compilation model. The key patterns are:
- `addFunction()` registers a Blade directive that calls the stored callable
- `@css`/`@endcss` uses `ob_start()`/`ob_get_clean()` to capture content
- Globals are shared via `$this->viewFactory->share()`

- [ ] **Step 4: Run tests, fix issues iteratively**

Run: `vendor/bin/phpunit packages/blade/tests/BladeEngineTest.php`

The Blade adapter will likely need iteration — the `addFunction()` directive compilation and `@css`/`@endcss` asset capture need careful testing against Blade's compilation model. Adjust the implementation until all tests pass.

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add packages/blade/
git commit -m "feat(blade): BladeEngine with directives, asset support, and full TemplateEngineInterface"
```

---

## Phase F: Config, Skeleton, and Integration

### Task 11: Update config and skeleton

**Files:**
- Modify: `packages/skeleton/composer.json`
- Modify: `packages/skeleton/config/app.php`
- Modify: `packages/skeleton/.env.example`

- [ ] **Step 1: Update skeleton composer.json**

Add `preflow/twig` as a dependency (it pulls in `preflow/view` transitively):

```json
"require": {
    "php": ">=8.4",
    "preflow/core": "dev-main",
    "preflow/routing": "dev-main",
    "preflow/view": "dev-main",
    "preflow/twig": "dev-main",
    "preflow/components": "dev-main",
    "preflow/data": "dev-main",
    "preflow/htmx": "dev-main",
    "preflow/i18n": "dev-main",
    "nyholm/psr7": "^1.8",
    "nyholm/psr7-server": "^1.1"
}
```

- [ ] **Step 2: Update config/app.php**

Add `engine` key:

```php
<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    // Template engine: 'twig' or 'blade'
    'engine' => getenv('APP_ENGINE') ?: 'twig',
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
];
```

- [ ] **Step 3: Update .env.example**

```
APP_NAME="Preflow App"
APP_DEBUG=1
APP_ENGINE=twig
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en

DB_DRIVER=sqlite
DB_PATH=storage/data/app.sqlite
```

- [ ] **Step 4: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add packages/skeleton/composer.json packages/skeleton/config/app.php packages/skeleton/.env.example
git commit -m "feat(skeleton): add engine config, require preflow/twig"
```

---

### Task 12: Integration tests — both engines render

**Files:**
- Create: `packages/core/tests/EngineIntegrationTest.php`

- [ ] **Step 1: Write integration test**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\TemplateFunctionDefinition;

final class EngineIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_engine_integration_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function test_twig_engine_renders_with_custom_function(): void
    {
        if (!class_exists(\Preflow\Twig\TwigEngine::class)) {
            $this->markTestSkipped('preflow/twig not installed');
        }

        file_put_contents($this->tmpDir . '/test.twig', '{{ greet("Twig") }}');

        $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $engine = new \Preflow\Twig\TwigEngine([$this->tmpDir], $assets);

        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        $html = $engine->render('test.twig');

        $this->assertSame('Hello, Twig!', $html);
    }

    public function test_blade_engine_renders_with_custom_function(): void
    {
        if (!class_exists(\Preflow\Blade\BladeEngine::class)) {
            $this->markTestSkipped('preflow/blade not installed');
        }

        file_put_contents($this->tmpDir . '/test.blade.php', '@greet("Blade")');

        $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $engine = new \Preflow\Blade\BladeEngine([$this->tmpDir], $assets);

        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        $html = $engine->render('test');

        $this->assertStringContainsString('Hello, Blade!', $html);
    }

    public function test_both_engines_report_correct_extension(): void
    {
        if (class_exists(\Preflow\Twig\TwigEngine::class)) {
            $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
            $twig = new \Preflow\Twig\TwigEngine([$this->tmpDir], $assets);
            $this->assertSame('twig', $twig->getTemplateExtension());
        }

        if (class_exists(\Preflow\Blade\BladeEngine::class)) {
            $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
            $blade = new \Preflow\Blade\BladeEngine([$this->tmpDir], $assets);
            $this->assertSame('blade.php', $blade->getTemplateExtension());
        }

        $this->assertTrue(true); // At least one engine should be available
    }
}
```

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit packages/core/tests/EngineIntegrationTest.php`
Expected: All pass (tests skip gracefully if engine package isn't installed)

- [ ] **Step 3: Run full suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add packages/core/tests/EngineIntegrationTest.php
git commit -m "test: integration tests for both Twig and Blade engines"
```

---

### Task 13: Update split workflow and Packagist setup for new packages

**Files:**
- Modify: `.github/workflows/split.yml`

- [ ] **Step 1: Add preflow/twig and preflow/blade to the split workflow**

Add entries for the new packages in the split matrix, following the same pattern as existing packages. Create the split repos on GitHub (`getpreflow/twig`, `getpreflow/blade`) and add Packagist webhooks.

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/split.yml
git commit -m "ci: add preflow/twig and preflow/blade to monorepo split workflow"
```

---

### Task 14: Update test project and verify in browser

**Files:**
- Copy updated files to `/Users/smyr/Sites/gbits/preflow/`

- [ ] **Step 1: Copy updated skeleton files**

Copy config, .env.example, composer.json to the test project. Run `composer update` in the test project to pick up the new package structure.

- [ ] **Step 2: Start dev server and verify**

```bash
cd /Users/smyr/Sites/gbits/preflow && php -S localhost:8080 -t public
```

Verify in browser:
- Home page renders with all components (ExampleCard, ErrorDemo, Navigation)
- Blog page works with HTMX filters
- i18n translations work
- Co-located CSS renders in `<head>`
- Active navigation highlighting works
- No Twig errors or missing functions

- [ ] **Step 3: Test engine swap (if Blade is wired)**

Change `.env` to `APP_ENGINE=blade`. Restart server. Verify error message is clear ("preflow/blade not installed" or similar) if Blade package isn't in the test project's dependencies.
