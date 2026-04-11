# preflow/twig

Twig 3 adapter for Preflow. Implements `TemplateEngineInterface` and ships co-located CSS/JS support via `{% apply css %}` / `{% apply js %}` filters and `{{ head() }}` / `{{ assets() }}` functions.

## Installation

```bash
composer require preflow/twig
```

Requires PHP 8.4+ and Twig 3.

## What's included

| Component | Description |
|---|---|
| `TwigEngine` | `TemplateEngineInterface` implementation wrapping `Twig\Environment` |
| `PreflowExtension` | `{% apply css %}`, `{% apply js %}`, `{{ head() }}`, `{{ assets() }}` |
| `ComponentExtension` | `{{ component('Name', {props}) }}` — renders Preflow components |
| `HdExtension` | `{{ hd.post(...) }}`, `{{ hd.get(...) }}` — hypermedia action helpers |
| `TranslationExtension` | `{{ t('key') }}`, `{{ tc('key', 'Component') }}` — i18n helpers |

## TwigEngine

```php
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

$assets = new AssetCollector(new NonceGenerator(), isProd: true);

$engine = new TwigEngine(
    templateDirs: [__DIR__ . '/templates', __DIR__ . '/app/pages'],
    assetCollector: $assets,
    debug: false,
    cachePath: __DIR__ . '/storage/twig-cache',  // null = no cache
);

$html = $engine->render('blog/post.twig', ['post' => $post]);
$engine->exists('partials/nav.twig');    // bool
$engine->getTemplateExtension();         // 'twig'
```

## Co-located styles and scripts

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

## Layout with head() and assets()

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

## Engine configuration

Set `APP_ENGINE=twig` in your `.env` (this is the default). Preflow's `Application` will automatically create a `TwigEngine` and register all extension providers.
