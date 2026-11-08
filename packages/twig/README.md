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

`{{ t() }}` and `{{ tc() }}` come from preflow/i18n (`TranslationExtensionProvider`, registered by the application), which escapes parameters.

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

## asset_url()

The `asset_url()` template function generates a URL to a public asset. Register it via your `AppServiceProvider` to point at the correct public path.

```twig
<link rel="stylesheet" href="{{ asset_url('css/app.css') }}">
<img src="{{ asset_url('images/logo.png') }}" alt="Logo">
```

Register in your service provider:

```php
$engine->addFunction(new TemplateFunctionDefinition(
    name: 'asset_url',
    callable: fn (string $path) => '/public/' . ltrim($path, '/'),
    isSafe: false,
));
```

## Live parts: `{% live %}`

A part of a page that differs per visitor (a guest's album count, "in your album", a cart
count) is a live part of a cached page (preflow/core's page cache):

```twig
{% live with {number: object.number} %}
    <button aria-pressed="{{ in_album(number) ? 'true' : 'false' }}">…</button>
{% endlive %}
```

- The body becomes a block of the template (`__live_{line}`) and sees only the `with` values
  and the globals, never the variables around it. `with` takes scalars and arrays; it may be
  left out (`{% live %}`).
- While a page renders for the page cache, the tag prints a signed placeholder (template,
  block, values) instead of the body; the block is rendered for each visitor when the page is
  served. Otherwise it renders in place.
- Live blocks don't nest. Not inside `{% embed %}`. Block names starting with `__live_` are
  theirs: a template's own `{% block __live_… %}` is a syntax error.
- The placeholder names the template by its loader name (`page.twig`, `@folio/...`), or, in a
  component's own template, by the component class. A template rendered by a path that is no
  component's renders its live blocks in place and keeps the page from being stored.
- Print the tag's output as it is: captured into `{% set %}` and printed escaped, or passed
  through `|striptags` or `|slice`, the placeholder is broken and the part is missing.

## Freshness and expiry: `{% freshness %}`, `{% expires %}`

The page cache's dial for what a part of a page reads (preflow/core's page cache, "Freshness,
per dependency"), and the page's expiry:

```twig
{% freshness 'next-visit' %}{% for other in jobs_of(job.company) %}…{% endfor %}{% endfreshness %}
{% freshness 'timed' every '12h' %}{{ weather() }}{% endfreshness %}
{% expires at event.startsAt %}
{% expires in '1h' %}
```

- Names: `'fresh'`, `'next-visit'` (or `'next_visit'`), `'timed'`; only `timed` takes `every`
  (`'30m'`, `'12h'`, `'2d'` or seconds). Both are expressions. A timed page never outlives the
  page cache's `max_age` (4 h by default): `every '12h'` refreshes every 4 h unless it is raised.
- Nested, the innermost wins. The body sees and sets the variables around it.
- `expires at` takes a `DateTimeInterface`, a unix time or a date string (null does nothing);
  the earliest of several counts.
- Without the page cache the body renders as it is; a wrong name or duration fails either way.
  Inside `{% live %}` it does nothing.

## Engine configuration

Set `APP_ENGINE=twig` in your `.env` (this is the default). Preflow's `Application` will automatically create a `TwigEngine` and register all extension providers.
