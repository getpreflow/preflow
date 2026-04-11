# Template Engine Abstraction + Blade Adapter

**Date:** 2026-04-11
**Scope:** Decouple all packages from Twig, restructure into engine-agnostic interfaces, create a full Blade adapter. This is Spec 1 of 2 — Spec 2 will add multi-engine coexistence (both Twig and Blade in one project).

## Problem

Twig is hardwired across the framework. `Application.php` calls `getTwig()->addExtension()` three times. The components, htmx, and i18n packages each have `Twig/` subdirectories with classes extending `Twig\Extension\AbstractExtension`. The skeleton promises "every layer sits behind an interface — swap in Blade, Datastar, or your own implementation" but that's not true today.

After this work, swapping Twig for Blade requires changing one config value and renaming template files. No framework code changes.

## Package Restructure

### Current structure:
- `preflow/view` — interfaces + Twig implementation + AssetCollector
- `preflow/components` — has `src/Twig/ComponentExtension.php`
- `preflow/htmx` — has `src/Twig/HdExtension.php`
- `preflow/i18n` — has `src/Twig/TranslationExtension.php`

### New structure:
- **`preflow/view`** — interfaces only + engine-agnostic utilities
- **`preflow/twig`** — new package, Twig implementation + all Twig extensions
- **`preflow/blade`** — new package, Blade implementation

### What stays in `preflow/view`:
- `TemplateEngineInterface` (expanded)
- `TemplateFunctionDefinition` (new)
- `TemplateExtensionProvider` (new)
- `AssetCollector` (engine-agnostic)
- `NonceGenerator` (engine-agnostic)
- `JsPosition` (engine-agnostic)

### What moves to `preflow/twig`:
- `Twig/TwigEngine.php` (from view)
- `Twig/PreflowExtension.php` (from view — the `{% apply css %}` filter)
- `Twig/ComponentExtension.php` (from components)
- `Twig/HdExtension.php` (from htmx)
- `Twig/TranslationExtension.php` (from i18n)

### What's new in `preflow/blade`:
- `BladeEngine.php`
- `BladeAssetDirective.php` (`@css`/`@endcss`)

### Dependencies:
- `preflow/twig` requires `preflow/view` + `twig/twig ^3.0`
- `preflow/blade` requires `preflow/view` + `illuminate/view ^11.0`
- `preflow/components` requires `preflow/view` (interface only — no engine dependency)
- `preflow/htmx` requires `preflow/view` (interface only)
- `preflow/i18n` requires `preflow/view` (interface only)
- `preflow/core` requires `preflow/view` (interface only)

## Interfaces

### TemplateEngineInterface (expanded)

```php
interface TemplateEngineInterface
{
    public function render(string $template, array $context = []): string;
    public function exists(string $template): bool;
    public function addFunction(TemplateFunctionDefinition $function): void;
    public function addGlobal(string $name, mixed $value): void;
    public function getTemplateExtension(): string;
}
```

Three new methods beyond the current `render()` and `exists()`:
- `addFunction()` — registers a template function (the engine translates to its native format)
- `addGlobal()` — makes a variable available in all templates
- `getTemplateExtension()` — returns the engine's file extension (`'twig'`, `'blade.php'`) for component template discovery

### TemplateFunctionDefinition

```php
final readonly class TemplateFunctionDefinition
{
    public function __construct(
        public string $name,
        public \Closure $callable,
        public bool $isSafe = false,
    ) {}
}
```

Engine-agnostic function descriptor. `isSafe` means the return value is raw HTML (Twig's `['is_safe' => ['html']]`, Blade's `{!! !!}`).

### TemplateExtensionProvider

```php
interface TemplateExtensionProvider
{
    /** @return TemplateFunctionDefinition[] */
    public function getTemplateFunctions(): array;

    /** @return array<string, mixed> */
    public function getTemplateGlobals(): array;
}
```

Packages implement this to declare their template functions without knowing which engine is active.

## Extension Providers (per package)

### ComponentsExtensionProvider (in `preflow/components`)

Replaces `src/Twig/ComponentExtension.php`. Provides the `component` function:

```php
final class ComponentsExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly array $componentMap,
        private readonly \Closure $componentFactory,
    ) {}

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
}
```

The `renderComponent()` method contains the same logic currently in `ComponentExtension::renderComponent()` — resolves short name to class, creates instance via factory, renders via `ComponentRenderer`.

### HtmxExtensionProvider (in `preflow/htmx`)

Replaces `src/Twig/HdExtension.php`. Provides the `hd` global and function:

```php
final class HtmxExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly HtmxDriver $driver,
        private readonly ComponentToken $token,
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

    // post(), get(), etc. methods stay on this class
    // Templates call {{ hd.post(...) }} / {{ $hd->post(...) }}
}
```

### TranslationExtensionProvider (in `preflow/i18n`)

Replaces `src/Twig/TranslationExtension.php`. Provides `t()` and `tc()`:

```php
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
}
```

## Application::boot() — Engine-Agnostic Wiring

After the refactor, `Application.php` has zero Twig imports. The boot flow becomes:

```php
// Engine selected by config: 'engine' => 'twig' or 'engine' => 'blade'
$this->bootViewLayer($debug);          // creates engine from config
$this->bootComponentLayer($debug, $secretKey); // creates providers, registers functions
```

### bootViewLayer() — engine selection

```php
private function bootViewLayer(DebugLevel $debug): void
{
    $engineConfig = $this->config->get('app.engine', 'twig');
    // ... create AssetCollector, NonceGenerator (engine-agnostic)

    $engine = match ($engineConfig) {
        'twig' => $this->createTwigEngine($templateDirs, $assets, $debug),
        'blade' => $this->createBladeEngine($templateDirs, $assets, $debug),
        default => throw new \RuntimeException("Unknown template engine: {$engineConfig}"),
    };

    $this->container->instance(TemplateEngineInterface::class, $engine);
}
```

The `createTwigEngine()` and `createBladeEngine()` methods check `class_exists()` and instantiate the appropriate engine. If the required package isn't installed, they throw a clear error.

### bootComponentLayer() — provider registration

```php
private function bootComponentLayer(DebugLevel $debug, string $secretKey): void
{
    // ... create ErrorBoundary, ComponentRenderer, component map, factory

    // Register component provider
    $componentProvider = new ComponentsExtensionProvider($renderer, $componentMap, $componentFactory);
    $this->registerExtensionProvider($componentProvider);

    // HTMX provider (if installed)
    if (class_exists(HtmxDriver::class)) {
        $htmxProvider = new HtmxExtensionProvider($htmxDriver, $componentToken);
        $this->registerExtensionProvider($htmxProvider);
    }
}

private function registerExtensionProvider(TemplateExtensionProvider $provider): void
{
    $engine = $this->container->get(TemplateEngineInterface::class);
    foreach ($provider->getTemplateFunctions() as $fn) {
        $engine->addFunction($fn);
    }
    foreach ($provider->getTemplateGlobals() as $name => $value) {
        $engine->addGlobal($name, $value);
    }
}
```

No `getTwig()`. No Twig types. The engine is a black box.

## TwigEngine Adapter

Lives in `preflow/twig`. Implements `TemplateEngineInterface`:

```php
final class TwigEngine implements TemplateEngineInterface
{
    private readonly Environment $twig;

    public function render(string $template, array $context = []): string { ... }
    public function exists(string $template): bool { ... }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $this->twig->addFunction(new TwigFunction(
            $function->name,
            $function->callable,
            $function->isSafe ? ['is_safe' => ['html']] : [],
        ));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }
}
```

**No more `getTwig()` method.** The Twig Environment is fully encapsulated.

`PreflowExtension` (the `{% apply css %}` filter) is registered internally during `TwigEngine` construction — it's Twig-specific asset plumbing that doesn't need to go through the `TemplateFunctionDefinition` system.

The migrated Twig extension classes (`ComponentExtension`, `HdExtension`, `TranslationExtension`) are no longer needed if `addFunction()`/`addGlobal()` handles everything. They can be removed. If any edge case requires a native Twig extension (e.g., `GlobalsInterface` for `hd`), it stays as an internal implementation detail of `preflow/twig`, not exposed to other packages.

## BladeEngine Adapter

Lives in `preflow/blade`. Implements `TemplateEngineInterface`:

### Core engine

Uses `illuminate/view` (Laravel's view component, usable standalone):

```php
final class BladeEngine implements TemplateEngineInterface
{
    private readonly Factory $viewFactory;
    private readonly BladeCompiler $compiler;
    private array $globals = [];

    public function render(string $template, array $context = []): string
    {
        $context = array_merge($this->globals, $context);
        return $this->viewFactory->make($template, $context)->render();
    }

    public function exists(string $template): bool
    {
        return $this->viewFactory->exists($template);
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $name = $function->name;
        $callable = $function->callable;

        $this->compiler->directive($name, function ($expression) use ($callable, $function) {
            // Parse expression and generate PHP that calls the function
            if ($function->isSafe) {
                return "<?php echo {$callable}({$expression}); ?>";
            }
            return "<?php echo e({$callable}({$expression})); ?>";
        });
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
        $this->viewFactory->share($name, $value);
    }
}
```

### Co-located CSS: `@css` / `@endcss`

Blade equivalent of `{% apply css %}`. Registered as a custom directive pair during `BladeEngine` construction:

```blade
@css
.nav-link--active {
    color: #0066ff;
    border-bottom-color: #0066ff;
}
@endcss
```

Implementation captures the content between `@css` and `@endcss` and feeds it to `AssetCollector::addCss()`. This is internal to `preflow/blade` — the `AssetCollector` interface is the same.

### Blade template conventions

| Twig | Blade |
|------|-------|
| `{% extends "_layout.twig" %}` | `@extends('_layout')` |
| `{% block content %}` | `@section('content')` |
| `{% endblock %}` | `@endsection` |
| `{{ variable }}` | `{{ $variable }}` |
| `{{ variable\|raw }}` | `{!! $variable !!}` |
| `{{ component('Nav', {...}) }}` | `@component('Nav', [...])` |
| `{{ t('key') }}` | `@t('key')` |
| `{{ hd.post(...) }}` | `{!! $hd->post(...) !!}` |
| `{% apply css %}...{% endapply %}` | `@css...@endcss` |
| `{% if condition %}` | `@if($condition)` |
| `{% for item in items %}` | `@foreach($items as $item)` |

### Component template discovery

Currently components auto-discover `ComponentName.twig` next to the PHP class. This extends to check for `.blade.php` based on which engine is configured:

- Twig engine active → looks for `Navigation.twig`
- Blade engine active → looks for `Navigation.blade.php`

`TemplateEngineInterface` gains one more method:

```php
public function getTemplateExtension(): string;  // 'twig', 'blade.php', etc.
```

`Component::getTemplatePath()` uses this to resolve the co-located template: `Navigation/Navigation.{extension}`. The engine knows its own file extension.

## Config Changes

### config/app.php

```php
return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'engine' => getenv('APP_ENGINE') ?: 'twig',
    // ...
];
```

### .env.example

```
APP_ENGINE=twig
```

## Skeleton Changes

The skeleton continues to ship with Twig templates (it requires `preflow/twig`). No template rewrites needed. The only skeleton changes are:

- `composer.json`: require `preflow/twig` instead of `preflow/view` (twig pulls in view as dependency)
- `config/app.php`: add `engine` key
- `.env.example`: add `APP_ENGINE=twig`

## Tests

### Interface tests (view package)
- `TemplateFunctionDefinition` stores name, callable, isSafe correctly
- `TemplateExtensionProvider` contract (mock implementations)

### TwigEngine tests (twig package)
- `addFunction()` makes function callable in rendered template
- `addGlobal()` makes variable available in rendered template
- `render()` and `exists()` work as before
- `PreflowExtension` CSS collection still works
- No `getTwig()` method exists (reflection test)

### BladeEngine tests (blade package)
- `addFunction()` registers working directive
- `addGlobal()` shares variable to all templates
- `render()` and `exists()` work
- `@css`/`@endcss` feeds AssetCollector
- Layout inheritance (`@extends`/`@section`/`@yield`)
- Control structures (`@if`, `@foreach`, `@unless`)
- Safe vs escaped output

### Extension provider tests (per package)
- `ComponentsExtensionProvider::getTemplateFunctions()` returns `component` function definition
- `HtmxExtensionProvider::getTemplateFunctions()` returns `hd` function, `getTemplateGlobals()` returns `hd` object
- `TranslationExtensionProvider::getTemplateFunctions()` returns `t` and `tc` functions
- Each provider's functions are callable and return expected output

### Integration tests
- Application boots with `engine: twig`, all template functions available, page renders
- Application boots with `engine: blade`, all template functions available, page renders
- Same component class works with both engines (different template files)

## Out of Scope

- Multi-engine coexistence (Twig + Blade in same project) — that's Spec 2
- Blade components (`<x-alert>`) — Preflow has its own component system
- Auth directives (`@auth`, `@guest`) — no auth system yet
- Service provider system — `TemplateExtensionProvider` is a stepping stone; full service providers are a future spec
- Template compilation caching for Blade in production
- Blade `@livewire` or any JavaScript framework integration
