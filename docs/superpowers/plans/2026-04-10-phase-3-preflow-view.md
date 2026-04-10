# Phase 3: preflow/view — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the view package (`preflow/view`) — template engine interface, Twig adapter with `{% apply css %}` / `{% apply js %}` extensions, and the zero-external-request asset pipeline with hash deduplication, JS positioning, and CSP nonce support.

**Architecture:** The `TemplateEngineInterface` defines the contract. The `TwigEngine` adapter implements it, registering custom Twig extensions that pipe CSS/JS content into a shared `AssetCollector`. The collector deduplicates by content hash, supports three JS positions (head/body/inline), and renders everything as inline `<style>`/`<script>` tags with CSP nonces. Layout functions (`head()`, `assets()`) render the collected assets at the right places in the HTML document.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, Twig 3.x, preflow/core

---

## File Structure

```
packages/view/
├── src/
│   ├── TemplateEngineInterface.php     — Contract for template engines
│   ├── AssetCollector.php              — Collects CSS/JS, deduplicates, renders inline
│   ├── JsPosition.php                 — Enum: Head, Body, Inline
│   ├── NonceGenerator.php             — Generates per-request CSP nonces
│   ├── Twig/
│   │   ├── TwigEngine.php             — Twig adapter implementing TemplateEngineInterface
│   │   ├── PreflowExtension.php       — Registers all Preflow Twig filters and functions
│   │   └── TokenParsers/
│   │       └── (none needed — uses {% apply filter %} syntax)
├── tests/
│   ├── AssetCollectorTest.php
│   ├── NonceGeneratorTest.php
│   ├── Twig/
│   │   ├── TwigEngineTest.php
│   │   └── PreflowExtensionTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/view/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/view/composer.json**

```json
{
    "name": "preflow/view",
    "description": "Preflow view — template engine interface, Twig adapter, asset pipeline",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.1 || @dev",
        "twig/twig": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\View\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\View\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json**

Add to `repositories` array:

```json
{
    "type": "path",
    "url": "packages/view",
    "options": { "symlink": true }
}
```

Add `"preflow/view": "@dev"` to `require-dev`.

- [ ] **Step 3: Update phpunit.xml**

Add testsuite:

```xml
<testsuite name="View">
    <directory>packages/view/tests</directory>
</testsuite>
```

Add to source include:

```xml
<directory>packages/view/src</directory>
```

- [ ] **Step 4: Create directories and install**

```bash
mkdir -p packages/view/src/Twig packages/view/tests/Twig
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/view/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/view package"
```

---

### Task 2: JsPosition Enum + NonceGenerator

**Files:**
- Create: `packages/view/src/JsPosition.php`
- Create: `packages/view/src/NonceGenerator.php`
- Create: `packages/view/tests/NonceGeneratorTest.php`

- [ ] **Step 1: Create JsPosition enum**

Create `packages/view/src/JsPosition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

enum JsPosition: string
{
    case Head = 'head';
    case Body = 'body';
    case Inline = 'inline';
}
```

- [ ] **Step 2: Write the failing test for NonceGenerator**

Create `packages/view/tests/NonceGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\NonceGenerator;

final class NonceGeneratorTest extends TestCase
{
    public function test_generates_base64_string(): void
    {
        $gen = new NonceGenerator();
        $nonce = $gen->get();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $nonce);
    }

    public function test_returns_same_nonce_per_instance(): void
    {
        $gen = new NonceGenerator();

        $a = $gen->get();
        $b = $gen->get();

        $this->assertSame($a, $b);
    }

    public function test_different_instances_produce_different_nonces(): void
    {
        $a = (new NonceGenerator())->get();
        $b = (new NonceGenerator())->get();

        $this->assertNotSame($a, $b);
    }

    public function test_nonce_is_at_least_16_bytes_encoded(): void
    {
        $gen = new NonceGenerator();
        $nonce = $gen->get();

        // Base64 of 16 bytes = 24 chars (with padding)
        $this->assertGreaterThanOrEqual(22, strlen($nonce));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/view/tests/NonceGeneratorTest.php
```

- [ ] **Step 4: Implement NonceGenerator**

Create `packages/view/src/NonceGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

final class NonceGenerator
{
    private ?string $nonce = null;

    public function get(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/view/tests/NonceGeneratorTest.php
```

Expected: All 4 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/view/src/JsPosition.php packages/view/src/NonceGenerator.php packages/view/tests/NonceGeneratorTest.php
git commit -m "feat(view): add JsPosition enum and NonceGenerator"
```

---

### Task 3: AssetCollector

**Files:**
- Create: `packages/view/src/AssetCollector.php`
- Create: `packages/view/tests/AssetCollectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/view/tests/AssetCollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;

final class AssetCollectorTest extends TestCase
{
    private function collector(?NonceGenerator $nonce = null, bool $isProd = false): AssetCollector
    {
        return new AssetCollector(
            nonceGenerator: $nonce ?? new NonceGenerator(),
            isProd: $isProd,
        );
    }

    public function test_add_css_and_render(): void
    {
        $c = $this->collector();
        $c->addCss('.foo { color: red; }');

        $output = $c->renderCss();

        $this->assertStringContainsString('.foo { color: red; }', $output);
        $this->assertStringContainsString('<style', $output);
    }

    public function test_css_deduplicated_by_hash(): void
    {
        $c = $this->collector();
        $c->addCss('.foo { color: red; }');
        $c->addCss('.foo { color: red; }'); // duplicate
        $c->addCss('.bar { color: blue; }');

        $output = $c->renderCss();

        $this->assertSame(1, substr_count($output, '.foo { color: red; }'));
        $this->assertStringContainsString('.bar { color: blue; }', $output);
    }

    public function test_css_deduplicated_by_explicit_key(): void
    {
        $c = $this->collector();
        $c->addCss('.v1 { color: red; }', 'my-component');
        $c->addCss('.v2 { color: blue; }', 'my-component'); // same key, ignored

        $output = $c->renderCss();

        $this->assertStringContainsString('.v1 { color: red; }', $output);
        $this->assertStringNotContainsString('.v2', $output);
    }

    public function test_add_js_defaults_to_body(): void
    {
        $c = $this->collector();
        $c->addJs('console.log("body");');

        $body = $c->renderJsBody();
        $head = $c->renderJsHead();

        $this->assertStringContainsString('console.log("body")', $body);
        $this->assertSame('', $head);
    }

    public function test_add_js_to_head(): void
    {
        $c = $this->collector();
        $c->addJs('window.CONFIG = {};', JsPosition::Head);

        $head = $c->renderJsHead();
        $body = $c->renderJsBody();

        $this->assertStringContainsString('window.CONFIG = {}', $head);
        $this->assertSame('', $body);
    }

    public function test_add_js_inline(): void
    {
        $c = $this->collector();
        $c->addJs('alert("inline");', JsPosition::Inline);

        $inline = $c->renderJsInline();
        $body = $c->renderJsBody();

        $this->assertStringContainsString('alert("inline")', $inline);
        $this->assertSame('', $body);
    }

    public function test_js_deduplicated_by_hash(): void
    {
        $c = $this->collector();
        $c->addJs('console.log("x");');
        $c->addJs('console.log("x");'); // duplicate

        $output = $c->renderJsBody();

        $this->assertSame(1, substr_count($output, 'console.log("x")'));
    }

    public function test_nonce_included_in_style_tag(): void
    {
        $nonce = new NonceGenerator();
        $c = $this->collector($nonce);
        $c->addCss('.x {}');

        $output = $c->renderCss();

        $this->assertStringContainsString('nonce="' . $nonce->get() . '"', $output);
    }

    public function test_nonce_included_in_script_tag(): void
    {
        $nonce = new NonceGenerator();
        $c = $this->collector($nonce);
        $c->addJs('var x;');

        $output = $c->renderJsBody();

        $this->assertStringContainsString('nonce="' . $nonce->get() . '"', $output);
    }

    public function test_empty_collector_renders_nothing(): void
    {
        $c = $this->collector();

        $this->assertSame('', $c->renderCss());
        $this->assertSame('', $c->renderJsHead());
        $this->assertSame('', $c->renderJsBody());
        $this->assertSame('', $c->renderJsInline());
    }

    public function test_render_head_includes_head_js_only(): void
    {
        $c = $this->collector();
        $c->addJs('headScript();', JsPosition::Head);
        $c->addJs('bodyScript();', JsPosition::Body);

        $head = $c->renderHead();

        $this->assertStringContainsString('headScript()', $head);
        $this->assertStringNotContainsString('bodyScript()', $head);
    }

    public function test_render_assets_includes_css_and_body_js(): void
    {
        $c = $this->collector();
        $c->addCss('.page { margin: 0; }');
        $c->addJs('init();', JsPosition::Body);
        $c->addJs('headStuff();', JsPosition::Head);

        $assets = $c->renderAssets();

        $this->assertStringContainsString('.page { margin: 0; }', $assets);
        $this->assertStringContainsString('init()', $assets);
        $this->assertStringNotContainsString('headStuff()', $assets);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/view/tests/AssetCollectorTest.php
```

- [ ] **Step 3: Implement AssetCollector**

Create `packages/view/src/AssetCollector.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

final class AssetCollector
{
    /** @var array<string, string> hash => css */
    private array $cssRegistry = [];

    /** @var array<string, string> hash => js */
    private array $jsHead = [];

    /** @var array<string, string> hash => js */
    private array $jsBody = [];

    /** @var array<string, string> hash => js */
    private array $jsInline = [];

    public function __construct(
        private readonly NonceGenerator $nonceGenerator,
        private readonly bool $isProd = false,
    ) {}

    public function addCss(string $css, ?string $key = null): void
    {
        $key ??= hash('xxh3', $css);
        $this->cssRegistry[$key] ??= $css;
    }

    public function addJs(
        string $js,
        JsPosition $position = JsPosition::Body,
        ?string $key = null,
    ): void {
        $key ??= hash('xxh3', $js);
        match ($position) {
            JsPosition::Head => $this->jsHead[$key] ??= $js,
            JsPosition::Body => $this->jsBody[$key] ??= $js,
            JsPosition::Inline => $this->jsInline[$key] ??= $js,
        };
    }

    /**
     * Render for <head>: head JS only.
     */
    public function renderHead(): string
    {
        return $this->renderJsHead();
    }

    /**
     * Render for end of <body>: CSS + body JS.
     */
    public function renderAssets(): string
    {
        return $this->renderCss() . $this->renderJsBody();
    }

    public function renderCss(): string
    {
        if ($this->cssRegistry === []) {
            return '';
        }

        $css = implode("\n", $this->cssRegistry);
        $nonce = $this->nonceAttr();

        return "<style{$nonce}>{$css}</style>\n";
    }

    public function renderJsHead(): string
    {
        return $this->renderJsBlock($this->jsHead);
    }

    public function renderJsBody(): string
    {
        return $this->renderJsBlock($this->jsBody);
    }

    public function renderJsInline(): string
    {
        return $this->renderJsBlock($this->jsInline);
    }

    private function renderJsBlock(array $registry): string
    {
        if ($registry === []) {
            return '';
        }

        $js = implode("\n", $registry);
        $nonce = $this->nonceAttr();

        return "<script{$nonce}>{$js}</script>\n";
    }

    private function nonceAttr(): string
    {
        $nonce = $this->nonceGenerator->get();
        return " nonce=\"{$nonce}\"";
    }

    public function getNonce(): string
    {
        return $this->nonceGenerator->get();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/view/tests/AssetCollectorTest.php
```

Expected: All 12 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/view/src/AssetCollector.php packages/view/tests/AssetCollectorTest.php
git commit -m "feat(view): add AssetCollector with hash dedup, JS positions, CSP nonces"
```

---

### Task 4: TemplateEngineInterface

**Files:**
- Create: `packages/view/src/TemplateEngineInterface.php`

- [ ] **Step 1: Create the interface**

Create `packages/view/src/TemplateEngineInterface.php`:

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
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/view/src/TemplateEngineInterface.php
git commit -m "feat(view): add TemplateEngineInterface"
```

---

### Task 5: PreflowExtension (Twig Filters + Functions)

**Files:**
- Create: `packages/view/src/Twig/PreflowExtension.php`
- Create: `packages/view/tests/Twig/PreflowExtensionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/view/tests/Twig/PreflowExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;
use Preflow\View\Twig\PreflowExtension;

final class PreflowExtensionTest extends TestCase
{
    private AssetCollector $assets;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->assets = new AssetCollector(
            nonceGenerator: new NonceGenerator(),
        );

        $this->twig = new Environment(new ArrayLoader([]), [
            'autoescape' => false,
        ]);
        $this->twig->addExtension(new PreflowExtension($this->assets));
    }

    private function render(string $template, array $context = []): string
    {
        $tpl = $this->twig->createTemplate($template);
        return $tpl->render($context);
    }

    public function test_apply_css_filter_registers_css(): void
    {
        $this->render('{% apply css %}.box { padding: 1rem; }{% endapply %}');

        $output = $this->assets->renderCss();
        $this->assertStringContainsString('.box { padding: 1rem; }', $output);
    }

    public function test_apply_css_filter_returns_empty_string(): void
    {
        $result = $this->render('{% apply css %}.box { padding: 1rem; }{% endapply %}');

        $this->assertSame('', trim($result));
    }

    public function test_apply_js_filter_registers_body_js(): void
    {
        $this->render('{% apply js %}console.log("hello");{% endapply %}');

        $output = $this->assets->renderJsBody();
        $this->assertStringContainsString('console.log("hello")', $output);
    }

    public function test_apply_js_filter_returns_empty_string(): void
    {
        $result = $this->render('{% apply js %}console.log("hello");{% endapply %}');

        $this->assertSame('', trim($result));
    }

    public function test_apply_js_head_filter(): void
    {
        $this->render("{% apply js('head') %}window.CONFIG = {};{% endapply %}");

        $head = $this->assets->renderJsHead();
        $body = $this->assets->renderJsBody();

        $this->assertStringContainsString('window.CONFIG = {}', $head);
        $this->assertSame('', $body);
    }

    public function test_apply_js_inline_filter(): void
    {
        $this->render("{% apply js('inline') %}alert('now');{% endapply %}");

        $inline = $this->assets->renderJsInline();
        $this->assertStringContainsString("alert('now')", $inline);
    }

    public function test_head_function_renders_head_assets(): void
    {
        $this->assets->addJs('headScript();', JsPosition::Head);

        $result = $this->render('{{ head() }}');

        $this->assertStringContainsString('headScript()', $result);
    }

    public function test_assets_function_renders_css_and_body_js(): void
    {
        $this->assets->addCss('.page { margin: 0; }');
        $this->assets->addJs('init();');

        $result = $this->render('{{ assets() }}');

        $this->assertStringContainsString('.page { margin: 0; }', $result);
        $this->assertStringContainsString('init()', $result);
    }

    public function test_css_dedup_across_multiple_renders(): void
    {
        $this->render('{% apply css %}.shared { display: flex; }{% endapply %}');
        $this->render('{% apply css %}.shared { display: flex; }{% endapply %}');

        $output = $this->assets->renderCss();
        $this->assertSame(1, substr_count($output, '.shared { display: flex; }'));
    }

    public function test_full_page_layout(): void
    {
        // Simulate a component registering assets
        $this->assets->addJs('configSetup();', JsPosition::Head);
        $this->assets->addCss('body { margin: 0; }');
        $this->assets->addJs('appInit();', JsPosition::Body);

        $result = $this->render(
            '<head>{{ head() }}</head><body>content{{ assets() }}</body>'
        );

        // Head JS in <head>
        $this->assertMatchesRegularExpression('/<head>.*configSetup\(\).*<\/head>/s', $result);
        // CSS and body JS in <body>
        $this->assertMatchesRegularExpression('/<body>.*body \{ margin: 0; \}.*<\/body>/s', $result);
        $this->assertMatchesRegularExpression('/<body>.*appInit\(\).*<\/body>/s', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/view/tests/Twig/PreflowExtensionTest.php
```

- [ ] **Step 3: Implement PreflowExtension**

Create `packages/view/src/Twig/PreflowExtension.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

final class PreflowExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetCollector $assetCollector,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('css', $this->registerCss(...), ['is_safe' => ['html']]),
            new TwigFilter('js', $this->registerJs(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('head', $this->renderHead(...), ['is_safe' => ['html']]),
            new TwigFunction('assets', $this->renderAssets(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Filter: {% apply css %}...{% endapply %}
     * Registers CSS with the asset collector and returns empty string.
     */
    public function registerCss(string $css): string
    {
        $css = trim($css);
        if ($css !== '') {
            $this->assetCollector->addCss($css);
        }
        return '';
    }

    /**
     * Filter: {% apply js %}...{% endapply %} or {% apply js('head') %}...{% endapply %}
     * Registers JS with the asset collector and returns empty string.
     */
    public function registerJs(string $js, string $position = 'body'): string
    {
        $js = trim($js);
        if ($js !== '') {
            $pos = JsPosition::from($position);
            $this->assetCollector->addJs($js, $pos);
        }
        return '';
    }

    /**
     * Function: {{ head() }}
     * Renders assets for the <head> section (head JS only).
     */
    public function renderHead(): string
    {
        return $this->assetCollector->renderHead();
    }

    /**
     * Function: {{ assets() }}
     * Renders assets for end of <body> (CSS + body JS).
     */
    public function renderAssets(): string
    {
        return $this->assetCollector->renderAssets();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/view/tests/Twig/PreflowExtensionTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/view/src/Twig/PreflowExtension.php packages/view/tests/Twig/PreflowExtensionTest.php
git commit -m "feat(view): add PreflowExtension with css/js filters and head/assets functions"
```

---

### Task 6: TwigEngine — Template Engine Adapter

**Files:**
- Create: `packages/view/src/Twig/TwigEngine.php`
- Create: `packages/view/tests/Twig/TwigEngineTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/view/tests/Twig/TwigEngineTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Twig\TwigEngine;

final class TwigEngineTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/preflow_twig_test_' . uniqid();
        mkdir($this->templateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->templateDir);
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

    private function createTemplate(string $name, string $content): void
    {
        $path = $this->templateDir . '/' . $name;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, $content);
    }

    private function engine(): TwigEngine
    {
        $assets = new AssetCollector(new NonceGenerator());
        return new TwigEngine([$this->templateDir], $assets);
    }

    public function test_renders_simple_template(): void
    {
        $this->createTemplate('hello.twig', '<h1>Hello {{ name }}</h1>');

        $engine = $this->engine();
        $result = $engine->render('hello.twig', ['name' => 'World']);

        $this->assertSame('<h1>Hello World</h1>', $result);
    }

    public function test_exists_returns_true_for_existing(): void
    {
        $this->createTemplate('page.twig', 'content');

        $engine = $this->engine();

        $this->assertTrue($engine->exists('page.twig'));
    }

    public function test_exists_returns_false_for_missing(): void
    {
        $engine = $this->engine();

        $this->assertFalse($engine->exists('nonexistent.twig'));
    }

    public function test_implements_template_engine_interface(): void
    {
        $engine = $this->engine();

        $this->assertInstanceOf(\Preflow\View\TemplateEngineInterface::class, $engine);
    }

    public function test_template_with_css_block(): void
    {
        $this->createTemplate('styled.twig',
            '<div>content</div>{% apply css %}.box { color: red; }{% endapply %}'
        );

        $engine = $this->engine();
        $result = $engine->render('styled.twig');

        // CSS block returns empty, content is rendered
        $this->assertStringContainsString('<div>content</div>', $result);
        $this->assertStringNotContainsString('.box', $result); // CSS not in output, it's in collector
    }

    public function test_template_with_extends(): void
    {
        $this->createTemplate('_layout.twig',
            '<html><body>{% block content %}{% endblock %}</body></html>'
        );
        $this->createTemplate('page.twig',
            '{% extends "_layout.twig" %}{% block content %}<p>Hello</p>{% endblock %}'
        );

        $engine = $this->engine();
        $result = $engine->render('page.twig');

        $this->assertStringContainsString('<html><body><p>Hello</p></body></html>', $result);
    }

    public function test_multiple_template_dirs(): void
    {
        $secondDir = sys_get_temp_dir() . '/preflow_twig_test2_' . uniqid();
        mkdir($secondDir, 0755, true);
        file_put_contents($secondDir . '/other.twig', 'from second dir');

        $assets = new AssetCollector(new NonceGenerator());
        $engine = new TwigEngine([$this->templateDir, $secondDir], $assets);

        $result = $engine->render('other.twig');
        $this->assertSame('from second dir', $result);

        // Cleanup
        unlink($secondDir . '/other.twig');
        rmdir($secondDir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/view/tests/Twig/TwigEngineTest.php
```

- [ ] **Step 3: Implement TwigEngine**

Create `packages/view/src/Twig/TwigEngine.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Twig;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

final class TwigEngine implements TemplateEngineInterface
{
    private readonly Environment $twig;

    /**
     * @param string[] $templateDirs Directories to search for templates
     */
    public function __construct(
        array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
    ) {
        $loader = new FilesystemLoader($templateDirs);

        $this->twig = new Environment($loader, [
            'debug' => $debug,
            'cache' => $cachePath ?: false,
            'auto_reload' => true,
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);

        $this->twig->addExtension(new PreflowExtension($assetCollector));
    }

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * Get the underlying Twig environment for advanced use.
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/view/tests/Twig/TwigEngineTest.php
```

Expected: All 7 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/view/src/Twig/TwigEngine.php packages/view/tests/Twig/TwigEngineTest.php
git commit -m "feat(view): add TwigEngine adapter implementing TemplateEngineInterface"
```

---

### Task 7: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass (core + routing + view).

- [ ] **Step 2: Verify view integrates with core**

```bash
php -r "
require 'vendor/autoload.php';
use Preflow\View\Twig\TwigEngine;
use Preflow\View\TemplateEngineInterface;
echo 'TwigEngine loads: OK' . PHP_EOL;
echo 'Implements TemplateEngineInterface: ' . (new ReflectionClass(TwigEngine::class))->implementsInterface(TemplateEngineInterface::class) ? 'YES' : 'NO';
echo PHP_EOL;
"
```

- [ ] **Step 3: Commit if cleanup needed**

```bash
git status
# Commit if any uncommitted changes
```

---

## Phase 3 Deliverables

After completing all tasks, the `preflow/view` package provides:

| Component | What It Does |
|---|---|
| `TemplateEngineInterface` | Contract for pluggable template engines |
| `AssetCollector` | Collects CSS/JS, deduplicates by xxh3 hash, supports explicit keys |
| `JsPosition` | Enum: Head, Body, Inline — controls where JS renders |
| `NonceGenerator` | Per-request CSP nonce generation |
| `PreflowExtension` | Twig filters (`css`, `js`) and functions (`head()`, `assets()`) |
| `TwigEngine` | Twig adapter with Preflow extensions pre-registered |

**Zero external requests principle enforced:** All CSS/JS rendered as inline `<style>`/`<script>` tags with CSP nonces. Hash-based deduplication ensures components rendered multiple times only register their assets once.

**Next phase:** `preflow/components` — component base class, lifecycle, error boundaries, uses `preflow/view` for rendering.
