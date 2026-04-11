# preflow/blade

Laravel Blade adapter for Preflow. Implements `TemplateEngineInterface` using `illuminate/view`, with custom directives for asset collection and template functions.

## Installation

```bash
composer require preflow/blade
```

Requires PHP 8.4+ and illuminate/view 11+.

## What's included

| Component | Description |
|---|---|
| `BladeEngine` | `TemplateEngineInterface` implementation wrapping Blade's `Factory` and `BladeCompiler` |
| `@css` / `@endcss` | Directive pair for co-located CSS via `AssetCollector` |
| Custom directives | Template functions registered via `addFunction()` become `@name(...)` directives |

## BladeEngine

```php
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

$assets = new AssetCollector(new NonceGenerator(), isProd: true);

$engine = new BladeEngine(
    templateDirs: [__DIR__ . '/templates', __DIR__ . '/app/pages'],
    assetCollector: $assets,
    debug: false,
    cachePath: __DIR__ . '/storage/blade-cache',  // null = system temp dir
);

$html = $engine->render('blog.post', ['post' => $post]);
$engine->exists('partials.nav');         // bool
$engine->getTemplateExtension();         // 'blade.php'
```

## Templates

Blade templates use `.blade.php` extension with standard Blade syntax:

```blade
{{-- resources/views/blog/post.blade.php --}}

<h1>{{ $post->title }}</h1>
<div>{!! $post->body !!}</div>

@if($post->published)
  <span>Published {{ $post->date }}</span>
@endif

@foreach($comments as $comment)
  <p>{{ $comment->text }}</p>
@endforeach
```

## Co-located styles

Use `@css` / `@endcss` to register CSS with the `AssetCollector`:

```blade
@css
.post-title { font-size: 2rem; font-weight: 700; }
.post-body  { line-height: 1.7; }
@endcss

<h1 class="post-title">{{ $post->title }}</h1>
<div class="post-body">{!! $post->body !!}</div>
```

## Layout with extends and sections

```blade
{{-- _layout.blade.php --}}
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>@yield('title', 'App')</title>
</head>
<body>
  @yield('content')
</body>
</html>
```

```blade
{{-- page.blade.php --}}
@extends('_layout')

@section('title', 'Blog')

@section('content')
  <h1>My Blog</h1>
@endsection
```

## Custom function directives

Template functions registered via `addFunction()` become Blade directives. With `isSafe: true`, output is rendered raw; otherwise it is escaped via `e()`.

```php
$engine->addFunction(new TemplateFunctionDefinition(
    name: 'component',
    callable: fn (string $name, array $props = []) => $renderer->render($name, $props),
    isSafe: true,
));
```

```blade
@component('Counter', ['count' => 0])
```

## Global variables

```php
$engine->addGlobal('siteName', 'My App');
```

```blade
<footer>{{ $siteName }}</footer>
```

## Engine configuration

Set `APP_ENGINE=blade` in your `.env` to use Blade. Preflow's `Application` will automatically create a `BladeEngine` and register all extension providers.
