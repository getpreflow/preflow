# Preflow View

Template engine abstraction and asset pipeline for Preflow. Ships a Twig adapter with co-located CSS/JS support and CSP-nonce-aware inline rendering.

## Installation

```bash
composer require preflow/view
```

Requires PHP 8.4+ and Twig 3.

## What's included

| Component | Description |
|---|---|
| `TemplateEngineInterface` | Pluggable template engine contract |
| `TwigEngine` | Twig 3 adapter with Preflow extensions registered |
| `AssetCollector` | Collects CSS/JS across components, deduplicates by xxh3 hash |
| `JsPosition` | Enum: `Head`, `Body`, `Inline` |
| `NonceGenerator` | Generates one random nonce per request for CSP |
| Twig extensions | `{% apply css %}`, `{% apply js %}`, `{{ head() }}`, `{{ assets() }}` |

## TwigEngine

```php
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Twig\TwigEngine;

$assets = new AssetCollector(new NonceGenerator(), isProd: true);

$engine = new TwigEngine(
    templateDirs: [__DIR__ . '/templates', __DIR__ . '/app/pages'],
    assetCollector: $assets,
    debug: false,
    cachePath: __DIR__ . '/storage/twig-cache',  // null = no cache
);

$html = $engine->render('blog/post.twig', ['post' => $post]);
$engine->exists('partials/nav.twig'); // bool
$engine->getTwig(); // raw Twig\Environment for advanced use
```

## Twig extensions

### Co-located styles and scripts

Use `{% apply css %}` and `{% apply js %}` anywhere in a template. The content is registered with the `AssetCollector` and nothing is output at that point.

```twig
{# templates/blog/post.twig #}

{% apply css %}
.post-title { font-size: 2rem; font-weight: 700; }
.post-body  { line-height: 1.7; }
{% endapply %}

{% apply js %}
document.querySelector('.post-body a[href^="http"]')
  ?.setAttribute('target', '_blank');
{% endapply %}

{% apply js('head') %}
window.analyticsId = {{ post.id }};
{% endapply %}

<h1 class="post-title">{{ post.title }}</h1>
<div class="post-body">{{ post.body|raw }}</div>
```

JS position argument: `'body'` (default), `'head'`, or `'inline'`.

Identical blocks (same xxh3 hash) are deduplicated automatically — safe to include the same component multiple times.

### Layout with head() and assets()

`{{ head() }}` renders JS registered for the `<head>`. `{{ assets() }}` renders all collected CSS plus body JS — place it just before `</body>`.

```twig
{# templates/_layout.twig #}
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{% block title %}App{% endblock %}</title>
  {{ head() }}
</head>
<body>
  {% block content %}{% endblock %}
  {{ assets() }}
</body>
</html>
```

## AssetCollector API

```php
// Add CSS (key is optional; defaults to xxh3 hash of content)
$assets->addCss('.btn { ... }');
$assets->addCss('.btn { ... }', key: 'btn-styles'); // explicit dedup key

// Add JS
$assets->addJs('console.log("body")', JsPosition::Body);
$assets->addJs('console.log("head")', JsPosition::Head);
$assets->addJs('console.log("inline")', JsPosition::Inline);

// Render (called by Twig extension; also usable directly)
$assets->renderHead();    // <script nonce="...">head JS</script>
$assets->renderAssets();  // <style nonce="...">CSS</style><script nonce="...">body JS</script>
$assets->renderCss();
$assets->renderJsHead();
$assets->renderJsBody();
$assets->renderJsInline();

// CSP nonce for the current request
$assets->getNonce(); // base64 random, stable within one request
```

Every `<style>` and `<script>` tag rendered by `AssetCollector` carries the same `nonce` attribute. Use `$assets->getNonce()` to add `'nonce-{value}'` to your CSP `Content-Security-Policy` header.
