# Preflow Routing

Hybrid file-based + attribute-based router for Preflow. Implements `RouterInterface` from `preflow/core`.

## Installation

```bash
composer require preflow/routing
```

Requires PHP 8.4+.

## What's included

| Component | Description |
|---|---|
| `FileRouteScanner` | Maps `app/pages/` directory structure to Component routes |
| `AttributeRouteScanner` | Scans PHP classes for `#[Route]`, `#[Get]`, `#[Post]`, etc. |
| `Router` | Combines both scanners, implements `RouterInterface` |
| `RouteMatcher` | Matches requests with priority: static > dynamic > catch-all |
| `RouteCompiler` | Caches the route collection to a PHP file for production |

## File-based routing

Files in the pages directory map directly to URLs. The scanner handles three file conventions:

| File | URL pattern |
|---|---|
| `index.twig` | `/` (or parent directory path) |
| `about.twig` | `/about` |
| `[slug].twig` | `/{slug}` — dynamic segment |
| `[...path].twig` | `/{path}` — catch-all (matches slashes too) |
| `_layout.twig` | excluded (underscore prefix) |

Example structure:

```
app/pages/
  index.twig           → GET /
  about.twig           → GET /about
  blog/
    index.twig         → GET /blog
    [slug].twig        → GET /blog/{slug}
  docs/
    [...path].twig     → GET /docs/{path}  (catches /docs/a/b/c)
  _layout.twig         → (ignored)
```

File routes resolve to `RouteMode::Component` — the handler value is the relative template path (e.g. `blog/[slug].twig`).

## Attribute-based routing

Controllers use `#[Route]` on the class for a path prefix, then HTTP method attributes on methods. These resolve to `RouteMode::Action` with the handler `ClassName@methodName`.

```php
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Put;
use Preflow\Routing\Attributes\Route;

#[Route('/api/posts')]
#[Middleware(ApiAuthMiddleware::class)]
final class PostController
{
    #[Get('/')]
    public function index(): ResponseInterface { /* ... */ }

    #[Get('/{id}')]
    public function show(): ResponseInterface { /* ... */ }

    #[Post('/')]
    public function create(): ResponseInterface { /* ... */ }

    #[Put('/{id}')]
    #[Middleware(OwnerMiddleware::class)]   // stacks on top of class middleware
    public function update(): ResponseInterface { /* ... */ }

    #[Delete('/{id}')]
    public function destroy(): ResponseInterface { /* ... */ }
}
```

Method paths are appended to the class prefix: `#[Get('/{id}')]` on `#[Route('/api/posts')]` becomes `/api/posts/{id}`. A method path of `'/'` resolves to the prefix alone.

`#[Middleware]` is repeatable on both class and method. Method middleware merges on top of class middleware.

## Router setup

```php
use Preflow\Routing\Router;

$router = new Router(
    pagesDir:    __DIR__ . '/app/pages',      // file-based routes (optional)
    controllers: [PostController::class],      // attribute-based routes (optional)
    cachePath:   __DIR__ . '/storage/routes.php', // null = no cache
);

// Pass to Application
$app->setRouter($router);
```

Either `pagesDir` or `controllers` (or both) can be omitted. When `cachePath` is set and the cache file exists, the scanner is bypassed entirely.

## Route cache (production)

```php
use Preflow\Routing\RouteCompiler;

$compiler = new RouteCompiler();

// Generate cache
$compiler->compile($router->getCollection(), __DIR__ . '/storage/routes.php');

// Invalidate
$compiler->clear(__DIR__ . '/storage/routes.php');
```

The compiled file is a plain PHP `return` statement — no eval, OPcache-friendly.

## Matching priority

For a given HTTP method, `RouteMatcher` tests routes in three passes:

1. **Static** — exact string match, no parameters
2. **Dynamic** — has `{param}` segments, matched by regex
3. **Catch-all** — has `{...param}`, matches across slashes

The first match wins. Throws `NotFoundHttpException` if nothing matches.
