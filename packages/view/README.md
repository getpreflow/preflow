# preflow/view

Engine-agnostic template interfaces and asset pipeline for Preflow. Defines the contracts that engine adapters (`preflow/twig`, `preflow/blade`) implement. Ships a CSP-nonce-aware asset collector with deduplication.

## Installation

```bash
composer require preflow/view
```

Requires PHP 8.4+. Install an engine adapter to render templates:

```bash
composer require preflow/twig   # Twig 3
composer require preflow/blade  # Laravel Blade
```

## What's included

| Component | Description |
|---|---|
| `TemplateEngineInterface` | Engine contract: render, exists, addFunction, addGlobal, getTemplateExtension |
| `TemplateFunctionDefinition` | Value object describing a template function (name, callable, isSafe) |
| `TemplateExtensionProvider` | Interface for packages that supply template functions and globals |
| `AssetCollector` | Collects CSS/JS across components, deduplicates by xxh3 hash |
| `JsPosition` | Enum: `Head`, `Body`, `Inline` |
| `NonceGenerator` | Generates one random nonce per request for CSP |

## TemplateEngineInterface

The central contract. Engine adapters implement this to plug into Preflow.

```php
use Preflow\View\TemplateEngineInterface;

$html = $engine->render('blog/post', ['post' => $post]);
$engine->exists('partials/nav');                          // bool
$engine->getTemplateExtension();                          // 'twig' or 'blade.php'
$engine->addGlobal('siteName', 'My App');                 // available in all templates
$engine->addFunction(new TemplateFunctionDefinition(
    name: 'greet',
    callable: fn (string $name) => "Hello, {$name}!",
    isSafe: true,                                         // skip output escaping
));
```

## TemplateExtensionProvider

Feature packages implement this interface to register template functions without depending on a specific engine.

```php
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class MyExtensionProvider implements TemplateExtensionProvider
{
    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'myHelper',
                callable: fn (string $arg) => strtoupper($arg),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return ['appVersion' => '1.0'];
    }
}
```

Built-in providers: `ComponentsExtensionProvider`, `HtmxExtensionProvider`, `TranslationExtensionProvider`.

## AssetCollector API

```php
// Add CSS (key is optional; defaults to xxh3 hash of content)
$assets->addCss('.btn { ... }');
$assets->addCss('.btn { ... }', key: 'btn-styles'); // explicit dedup key

// Add JS
$assets->addJs('console.log("body")', JsPosition::Body);
$assets->addJs('console.log("head")', JsPosition::Head);
$assets->addJs('console.log("inline")', JsPosition::Inline);

// Render (called by engine extensions; also usable directly)
$assets->renderHead();    // <script nonce="...">head JS</script>
$assets->renderAssets();  // <style nonce="...">CSS</style><script nonce="...">body JS</script>
$assets->renderCss();
$assets->renderJsHead();
$assets->renderJsBody();
$assets->renderJsInline();

// CSP nonce for the current request
$assets->getNonce(); // base64 random, stable within one request
```

Every `<style>` and `<script>` tag rendered by `AssetCollector` carries the same `nonce` attribute. Use `$assets->getNonce()` to add `'nonce-{value}'` to your `Content-Security-Policy` header.

Identical blocks (same xxh3 hash) are deduplicated automatically — safe to include the same component multiple times.

### Forked collectors and inspection

`fork()` creates an isolated child `AssetCollector` that shares the same nonce but collects assets independently. Use it when rendering a sub-component that should not pollute the parent collector (e.g. fragment responses).

```php
$child = $assets->fork();
$html  = $componentRenderer->renderFragment($component, $child);
// $assets is untouched; $child holds only the fragment's CSS/JS
```

`hasCss()` and `hasJs()` let you check before rendering — useful to decide whether to append inline styles to a fragment response.

```php
if ($child->hasCss() || $child->hasJs()) {
    $html .= $child->renderHead() . $child->renderAssets();
}
```
