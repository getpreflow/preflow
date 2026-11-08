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
| `Redirect\RedirectMiddleware` | Answers unfound public pages from a redirect store and notes the rest in a not-found log |

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

### Catch-all params in attribute routes

Append `...` inside the braces to create a catch-all segment that matches slashes. Useful for nested path parameters in controllers.

```php
#[Route('/files')]
final class FileController
{
    #[Get('/{path...}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path'); // e.g. 'images/2026/photo.jpg'
        // ...
    }
}
```

`{path...}` compiles to a `.+` regex, matching one or more characters including `/`.

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

On a Preflow application you don't need this: `preflow optimize` compiles the routes into the
boot manifest (see preflow/core, "Boot manifest"). Core passes the Router a `store:`, and every
route set goes through `Router::compiled()`:

```php
// In a provider's boot(): compiled once, then served from the manifest.
$router->getCollection()->addMany($router->compiled('vendor.name', fn (): array => [
    new RouteEntry(pattern: '/feed', handler: FeedController::class . '@show', method: 'GET', mode: RouteMode::Action),
], [$sourcePath]));
```

The app's own routes (file pages and controllers) are the set `app`. Without a store (no
manifest, debug mode, the console) `compiled()` just runs the closure. The entries must depend
only on code, config files and files that change on deploy; a set whose middleware holds
closures or objects is not stored and is compiled live. `Router::liveSets()` lists the sets the
router compiled live (with a current manifest: none). `RouteEntry::toArray()`/`fromArray()` are
the stored form.

## Matching priority

For a given HTTP method, `RouteMatcher` tests routes in three passes:

1. **Static** — exact string match, no parameters
2. **Dynamic** — has `{param}` segments, matched by regex
3. **Catch-all** — has `{...param}`, matches across slashes

The first match wins. Throws `NotFoundHttpException` if nothing matches.

## Redirects and the not-found list

When a public page is not found, `RedirectMiddleware` looks the address up in a redirect store
and answers with a redirect; when no entry matches, it notes the address in a not-found log.
The mechanism lives here; where entries are kept is up to a store. Folio supplies one (renamed
and removed pages redirect automatically, Settings → Redirects lists them, see
`preflow/folio`); a site without Folio binds its own.

### When it acts

- Only a thrown `Preflow\Core\Exceptions\NotFoundHttpException` on `GET` or `HEAD`: a router
  miss, a controller's own 404, Folio finding no record. A 404 *response* (Folio's gated pages)
  passes through untouched, and every other method keeps its 404.
- Never under a non-public path (`Preflow\Core\Http\NonPublicPaths` in `preflow/core`: the admin,
  the member area, private files, the component endpoint).
- A page that answers always wins: the middleware only runs after the app found nothing.
- Lookup order: the exact entry for the path (the request's language first, then an
  any-language entry), else the area entry with the longest path above it.
- Paths are compared after `LocaleMiddleware` stripped the locale prefix, together with the
  request's `locale` (the site's default when there is none), in one canonical form
  (`RedirectPath::normalize()`: repeated slashes collapsed, no trailing slash, each segment
  encoded once, so `/über-uns` and `/%C3%BCber-uns` are the same address).

### The answer

- The entry's status: 301 (permanent) or 302 (temporary), with `Cache-Control: public,
  max-age=86400`, so a browser asks again after a day (a page renamed back must not loop).
- The original query string is kept (`/old?ref=x` → `/new?ref=x`).
- A record target is resolved when the redirect is answered, through `RedirectTargets`, in the
  old address's language (its default-language URL when it has none in that language). A
  renamed page's old addresses all point at the record, so renaming twice never chains.
- An area entry either sends everything below it to one target (`AreaMode::One`) or keeps the
  rest of the path under a new prefix (`AreaMode::Prefix`: `/workshops-2024/basel` →
  `/workshops/basel`).
- A target that is gone (a deleted record, no target picked), equal to the visited address, or
  a protocol-relative URL (`//host`) keeps the 404. Store and log failures (a table not
  migrated yet) are logged in debug mode and keep the 404; they never turn it into a 500.

### The not-found list

An unanswered address is passed to `NotFoundLog::record($path, $locale, $at)`: the path without
query string, the language and the time, nothing about the visitor (no IP address, user agent
or referrer). Scanner noise is left out by `NotFoundFilter`: WordPress and admin-tool probes
(`/wp-…`, `/xmlrpc`, `/phpmyadmin`, `/cgi-bin`…), dotfiles (`/.env`, `/.git`) and script, backup
and archive extensions (`.php`, `.sql`, `.bak`, `.zip`…).

### `config/redirects.php`

The skeleton ships it with every option commented out; without the file the defaults apply:

```php
return [
    'not_found' => true,          // record unanswered addresses; redirects work either way
    'not_found_limit' => 1000,    // how many addresses the store keeps (least recently hit go first)
    'ignore' => ['/legacy/', '.json'], // more noise: a path prefix, or an extension starting with '.'
];
```

`RedirectConfig::fromFile()` reads it. Keeping the limit is the log's job (Folio's does).

### A store without Folio

The application adds the middleware (after the site's own middleware) when a provider has bound
`Preflow\Routing\Redirect\RedirectStore`. `RedirectTargets` (record targets) and `NotFoundLog`
are optional:

```php
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectPath;
use Preflow\Routing\Redirect\RedirectStore;

final class SiteRedirects implements RedirectStore
{
    /** @var array<string, string> old path => new URL */
    private const MAP = ['/old-page' => '/new-page', '/shop' => 'https://shop.example.org'];

    public function exact(string $path, string $locale): ?Redirect
    {
        foreach (self::MAP as $from => $to) {
            if (RedirectPath::normalize($from) === $path) {
                return new Redirect(Redirect::id('exact', '', $path), $path, '', false, AreaMode::One, null, null, $to, 301);
            }
        }

        return null;
    }

    public function areas(string $locale): array
    {
        return []; // Redirect objects with area: true
    }

    public function hit(Redirect $redirect, \DateTimeImmutable $at): void {}
}

// In a service provider's register():
$container->bind(\Preflow\Routing\Redirect\RedirectStore::class, static fn () => new SiteRedirects());
```

- `exact($path, $locale)` and `areas($locale)` get the normalized path; return entries of that
  locale and any-language ones (`locale` `''`).
- A `Redirect` has a fixed `targetUrl` (absolute `https://…` or a path on this site), or a
  `targetType` and `targetId` that a bound `RedirectTargets::url($type, $id, $locale)` turns into
  a URL; with neither it is broken and answers 404.
- `hit()` counts a served redirect; it may do nothing.
- `NotFoundLog::record()` gets every unanswered, non-noise address; bind it to keep a list.
