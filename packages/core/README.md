# Preflow Core

Foundation package for the Preflow framework. Provides the DI container, config, middleware pipeline, error handling, kernel, and application bootstrap.

## Installation

```bash
composer require preflow/core
```

Requires PHP 8.4+.

## What's included

| Component | Description |
|---|---|
| `Container` | PSR-11 container with autowiring and attribute injection |
| `Config` | Dot-notation PHP array config |
| `MiddlewarePipeline` | PSR-15 middleware stack |
| `ErrorHandler` | Dev (rich HTML) / prod (minimal) error pages |
| `Kernel` | Dual-mode (Component/Action) request dispatcher |
| `Application` | Bootstrap entry point |
| `CsrfMiddleware` | CSRF check on every unsafe request (origin, session or stateless token), `#[CsrfExempt]`, expired-session handling |
| HTTP exceptions | `NotFoundHttpException`, `ForbiddenHttpException`, `UnauthorizedHttpException` |
| `NonPublicPaths` | Path prefixes that are not public pages (admin, member area, private files), for features that treat unknown addresses as pages |
| Route contracts | `Route`, `RouteMode`, `RouterInterface` |

## API

### Container

```php
// Bind an interface to a concrete class (transient)
$container->bind(CacheInterface::class, RedisCache::class);

// Bind as singleton (binding it again later replaces an instance already resolved)
$container->singleton(LoggerInterface::class, FileLogger::class);

// Or mark a class: shared from its first autowired get(), whoever asks first
#[\Preflow\Core\Container\Attributes\Singleton]
final class Registry {}

// Register a pre-built instance
$container->instance(Config::class, $config);

// Resolve (autowires constructor dependencies)
$service = $container->get(MyService::class);

// Instantiate with parameter overrides
$obj = $container->make(MyService::class, ['timeout' => 30]);
```

### Attribute injection

```php
use Preflow\Core\Container\Attributes\Config;
use Preflow\Core\Container\Attributes\Env;

final class Mailer
{
    public function __construct(
        #[Config('mail.from')]
        private string $fromAddress,

        #[Config('mail.driver', default: 'smtp')]
        private string $driver,

        #[Env('MAIL_HOST')]
        private string $host,

        #[Env('MAIL_PORT', default: '587')]
        private string $port,
    ) {}
}
```

`#[Config('key')]` reads from the registered `Config` instance using dot notation. `#[Env('NAME')]` reads from `getenv()`. Both support a `default`.

### Config

```php
$config = new Config([
    'app' => ['name' => 'MyApp', 'debug' => false],
    'db'  => ['host' => 'localhost'],
]);

$config->get('app.name');          // 'MyApp'
$config->get('missing', 'fallback'); // 'fallback'
$config->has('db.host');           // true
$config->set('app.debug', true);
$config->all();                    // full array
```

### ServiceProvider

```php
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(CacheInterface::class, RedisCache::class);
    }

    public function boot(Container $container): void
    {
        // Runs after all providers are registered
        $container->get(CacheInterface::class)->connect();
    }
}

$app->registerProvider(new CacheServiceProvider());
```

### Middleware

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (!$request->hasHeader('Authorization')) {
            return new Response(401);
        }

        return $handler->handle($request);
    }
}

$app->addMiddleware(new AuthMiddleware());
```

A request the app makes of itself (the SEO audit rendering a page) carries the attribute
`preflow.internal_render`; `Preflow\Core\Http\InternalRender::is($request)` reads it. Middleware that records
visits, counts hits or queues side effects should skip them for such a request. Since 0.54.

### Application bootstrap

```php
$app = Application::create([
    'app' => [
        'name'  => 'MyApp',
        'debug' => (bool) getenv('APP_DEBUG'),
    ],
]);

$app->registerProvider(new CacheServiceProvider());
$app->addMiddleware(new AuthMiddleware());
$app->setRouter($router);
$app->setActionDispatcher($dispatcher);
$app->setComponentRenderer($renderer);

$app->boot();

$response = $app->handle($request);
```

`boot()` selects the error renderer based on `app.debug`, boots all providers, and wires the kernel. `handle()` runs the middleware pipeline and dispatches to the matched route.

**Work that only some requests need.** A provider can leave setup out of boot and do it on the
first request that needs it:

```php
public function boot(Container $container): void
{
    $app = $container->get(Application::class);
    if ($app->runningInConsole()) {
        $this->setUpAdmin($container);      // commands handle no request: set up now
        return;
    }
    $app->onRequest(function (ServerRequestInterface $request) use ($container): void {
        if (str_starts_with($request->getUri()->getPath(), '/admin/')) {
            $this->setUpAdmin($container);  // make it idempotent: it runs on every such request
        }
    });
}
```

- `onRequest(\Closure $hook)` calls the hook with every request `handle()` receives, before any
  middleware and before routing, with the raw path (locale prefix still on). Hooks run in the
  order added. A hook that throws fails that request like a boot error (no error page), so a
  setup that can fail should only mark itself done once it has succeeded. Folio sets up its
  whole admin this way.
- `runningInConsole()` is true under the CLI and phpdbg (`PHP_SAPI`); `php -S` counts as web.
  Tests that stand in for web requests call `setRunningInConsole(false)` before `boot()`.
- `Container::resolved(string $id)` tells whether a shared instance exists already (set with
  `instance()`, or a singleton built by an earlier `get()`), without building it. Tests use it to
  check that a request left a lazy service alone.

Admin routes added later in this way go in front of a catch-all with
`RouteCollection::insertBefore($anchor, $entries)` (preflow/routing).

### JsonBodyMiddleware

Automatically decodes `application/json` request bodies into `getParsedBody()`. No manual `json_decode` needed in controllers.

```php
use Preflow\Core\Http\Middleware\JsonBodyMiddleware;

$app->addMiddleware(new JsonBodyMiddleware());

// In a controller:
$data = $request->getParsedBody(); // already a decoded array
```

### Flash messages

`FlashType` enum covers the four standard severity levels. Helper functions write and read flash messages via the session.

```php
use Preflow\Core\Flash\FlashType;

flashSuccess('Post saved.');
flashError('Validation failed.');
flashInfo('Changes are pending review.');
flashWarning('Disk space is low.');

// In a controller or template resolver:
$flashes = getFlashes(); // ['success' => ['Post saved.'], ...]
```

Template helpers `flash('success')` and `flash('error')` read a single message for a given type.

### Request context in page templates

Page templates rendered through the Component kernel receive a `request` variable with commonly needed values.

```twig
{{ request.path }}       {# /blog/hello-world #}
{{ request.method }}     {# GET #}
{{ request.isHtmx }}     {# true when HX-Request header is present #}
{{ request.query.page }} {# query string parameter #}
```

### config/middleware.php

Place a `config/middleware.php` file in your application root and return an array of middleware class names. The kernel loads it automatically — no manual `$app->addMiddleware()` calls needed for global middleware.

```php
// config/middleware.php
return [
    Preflow\Core\Http\Middleware\JsonBodyMiddleware::class,
    App\Http\Middleware\LocaleMiddleware::class,
];
```

### Throttle

`Preflow\Core\Throttle\Throttle` limits attempts (a login, a form, a code guess) in fixed
windows. It is bound when `config/data.php` exists, stores through the `DataManager` in the
table `preflow_throttle` (created by `preflow migrate`), and is keyed by `app.key`. It stores on
the `throttle` storage alias when `config/data.php` defines one, otherwise on the default
driver.
`client()` throws a `RuntimeException` while `app.key` is empty or the default key: a hash
salted with a public key could be brute-forced back to the IP.

```php
$throttle = $container->get(\Preflow\Core\Throttle\Throttle::class);
$client = $throttle->client($request);                    // salted hash, never the IP

if ($throttle->tooMany('login', $client, max: 5, windowSeconds: 600)) {
    // answer 429
}
$throttle->hit('login', $client, windowSeconds: 600);     // after a failed attempt
$throttle->clear('login', $client);                       // after a success
```

- **Atomic hits.** Concurrent requests never lose a hit. On SQL, `hit()` is one upsert
  (`DataManager::execute()`) that counts the attempt or restarts an expired window; on the
  JSON driver an exclusive file lock in `storage/locks` guards the read-modify-write.
  The limit itself is still a fixed window, so a burst at a window's edge can double.
- **Windows must be much shorter than a day.** The client hash rotates at 00:00 UTC, so a
  window spanning midnight starts over.
- **No raw IP is stored**, only an HMAC of `REMOTE_ADDR` (forwarded headers are ignored).
  IPv6 clients count by their /64 prefix, since one subscriber usually holds a whole /64.
  Without a `REMOTE_ADDR` (CLI, tests) all requests are one `unknown` client; it never throws.
- `prune(olderThanSeconds: 172800)` deletes old rows and returns how many; `hit()` runs it
  opportunistically, once per request when a new window starts.

### Cookie queue

`Preflow\Core\Http\CookieQueue` holds `Set-Cookie` values queued during a request (the
remember-me cookies of `preflow/auth`, whose tokens use the `auth` storage alias, else the
default: with a JSON default and the auth tables in SQLite, set `'auth' => 'sqlite'` in
`config/data.php`). It is bound when `config/auth.php` exists. The
middleware that queued them applies them to its response; the Kernel applies what is left to
an error response, which bypasses the pipeline, so a rotated cookie is never lost.

### Auth providers from config

Any class in `config/auth.php` `providers` (other than the two built-in ones) is built from
the container with its config entry as `config`. It is built lazily (`LazyUserProvider`) and
resolved at the end of `boot()`, after the service providers, so it may use services they
register (e.g. Folio's type catalog). A class that is not a `UserProviderInterface` throws at
boot.

### CSRF

`CsrfMiddleware` checks every `POST`, `PUT`, `PATCH` and `DELETE`, with or without a session.
`GET`, `HEAD` and `OPTIONS` are never checked. A form carries the token as the `_csrf_token`
field (`csrf_field()`), JavaScript as the `X-CSRF-Token` header (`csrf_token()`). Every request
that has a token (a session, or a real `app.key` for the stateless token) gets a
`Preflow\Core\Http\Csrf\CsrfToken` attribute, which packages that print `{{ csrf }}`
themselves (Folio, rbac, the form builder) read. Without a session and with an empty or the
built-in default `app.key` there is no attribute: code reading it must handle `null`. The
attribute is set once per request: after `SessionInterface::invalidate()` (a sign-in, a
sign-out) it still holds the removed token, so a page rendered in that same request takes
`CsrfToken::generate($session)` instead.

**Layer 1: the origin.** The first signal the browser sent decides:

1. `Sec-Fetch-Site`: `same-origin` passes; `same-site`, `cross-site` and `none` are refused
   (unless the `Origin` is one of `csrf.trusted_origins`).
2. else `Origin`: passes when it is the site's origin (`app.url`), the request's own scheme and
   host, or a trusted origin; `null` counts as no signal.
3. else `Referer`: the same comparison on its origin.
4. no signal at all: the token decides alone.

A refused origin is refused with any token. Behind a proxy that terminates TLS, set `app.url`
to the public address (`https://example.com`): the request itself then looks like
`http://…`, and only `app.url` tells the check which origin is the site's.

**Layer 2: the token.** Which one applies is decided by the request:

| The request | Check |
|---|---|
| no session; or a new session without its cookie, and no cookie at all or no session token (a public page; a browser that blocks cookies) | the origin passes (no token needed: such a request comes only from the site's own pages); or, when there is no origin signal, a valid stateless token and no cookie at all (with any cookie, such as a remember-me one, it is refused) |
| a live session | the origin does not refuse **and** the session's token matches; on a sign-in path a public page's valid stateless token with a passing origin also passes |
| a new session with its cookie (expired, or an old cookie) | a stateless token with a passing origin (a public page posting into a session path); a session token is the expired case below |
| a new session without its cookie, but other cookies and a session token | the expired case below (the browser dropped the session cookie and kept a remember-me cookie). A browser that keeps other cookies but never sends the session cookie (a `Secure` session cookie on a plain-http stage) gets this answer on every form but the sign-in ones |

A session that held a token is always checked on that token, also when the request came
without its cookie.

**Stateless token.** Pages without a session (sites with `auth.session.paths`) print a signed
token: HMAC-SHA256 with `app.key` over a 24-hour time slot, valid in the current and the
previous slot, so a page left open works for at least a day. It is not tied to the visitor (no
cookie, no address), so the page stays cacheable; it is only the fallback for browsers that
send no origin signal. With an empty `app.key` or the framework's built-in default key it is
never issued nor accepted (such requests are refused), and debug mode logs a warning: set
`app.key`.

**Not checked:**

- requests with an `Authorization` header and no cookie (API clients: nothing ambient to use);
- `/--component/` (component actions carry signed tokens of their own);
- routes marked `#[CsrfExempt('why')]`, on a controller class (all its routes) or one route
  method. The reason is required:

```php
use Preflow\Core\Http\Csrf\CsrfExempt;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Route;

#[Route('/hooks')]
#[CsrfExempt('Stripe webhook, verified by its signature')]
final class StripeWebhookController
{
    #[Post('/stripe')]
    public function receive(ServerRequestInterface $request): ResponseInterface { /* … */ }
}
```

- path prefixes in `csrf.exempt`, only for routes that cannot carry the attribute (file
  routes in `app/pages`, paths outside the router).

To find the attribute the middleware matches the route of an unsafe request (once; the Kernel
dispatches that match instead of matching again). A middleware added later that rewrites the
URI of a POST therefore no longer changes which route runs: the Kernel dispatches the route
the CSRF check matched.

**Config** (`config/csrf.php`, optional; a missing file means the defaults):

```php
return [
    'exempt' => [],          // path prefixes without a check, added to '/--component/'
    'trusted_origins' => [], // other origins allowed to post, e.g. 'https://stage.example.com'
    'sign_in_paths' => [],   // sign-in forms of the site; packages register their own
];
```

Paths in `exempt` and `sign_in_paths` are compared without the locale prefix (`/de/login`
is `/login`); sign-in paths match exactly (a trailing slash is ignored), exempt paths as
prefixes of whole segments (`/hooks` covers `/hooks` and `/hooks/stripe`, not `/hookshot`).

**Failures** are a 403, in one of two kinds:

- **`ForbiddenHttpException('CSRF check failed: …')`:** a refused origin, no token or a wrong
  one on a live session, or no origin signal and no valid stateless token (or cookies with it).
- **`Preflow\Core\Http\Csrf\CsrfSessionExpiredException`** (extends `ForbiddenHttpException`):
  the session expired and the request has no passing origin (see below).

#### Expired sessions

The session existed when the form was rendered, but not any more (it carries a session token
the new session cannot match). With a refused or missing origin it is the 403 above.
With a passing origin:

1. **Sign-in forms** pass, with the new session and its fresh token: the posted password is
   the proof, and login CSRF is stopped by the origin check. Folio registers
   `{folio path}/login`, members `{members path}/login` and `{members path}/register`; a site adds its own in
   `csrf.sign_in_paths`, a package in its provider:

   ```php
   $container->get(\Preflow\Core\Http\Csrf\CsrfSignInPaths::class)->add('/shop/login');
   ```

2. **Any other form:** the site's `ExpiredSessionHandler` answers first (below). Without one,
   or when it returns `null`, the framework's answer: the request is **not applied**. A form
   gets a 303 back to the page it came from (the `Referer` when its origin is `app.url` or the
   request's own, else `/`) with a `warning` flash ("Your session had expired. Please send the form again.",
   in en/de/fr/it, or the site's `csrf.session_expired` translation) and the posted fields kept
   as old input, so the form shows them again (`Preflow\Core\Http\OldInput`, read by
   validation's `old()`, the form builder and members; never kept: `_csrf_token`, fields whose
   name contains `password`, uploaded files, and nothing when the kept fields exceed 64 KB). htmx gets a 204 with `HX-Refresh: true`, a JSON
   client (`Accept: application/json`) a 409 `{"error":"session_expired"}`.

Nothing runs in the visitor's name: a remember-me cookie could sign them in again, so the data
waits for one more click from a page with a fresh token.

Site middleware runs inside the CSRF check, so it cannot catch these. To answer the expired
case your own way (keep a draft, send the visitor to the sign-in), bind a
`Preflow\Core\Http\Csrf\ExpiredSessionHandler` in a service provider. It gets the request
(with the new session as the `SessionInterface` attribute and its fresh `CsrfToken`) and
returns a response to send, or `null` for the framework's answer. It is called for the
expired case with a passing origin only, never for a sign-in path, a mismatch on a live
session, a missing token, safe methods or exempt routes.

```php
use Preflow\Core\Http\Csrf\ExpiredSessionHandler;
use Preflow\Core\Http\Session\SessionInterface;

final class KeepDraftOnExpiry implements ExpiredSessionHandler
{
    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!preg_match('#^/account/posts/\d+/edit$#D', $path)) {
            return null; // the framework's answer
        }
        // Keep the values to PREFILL the form after sign-in; never save them from here.
        $request->getAttribute(SessionInterface::class)
            ->set('draft:' . $path, (array) $request->getParsedBody());

        return new Response(303, ['Location' => '/login?next=' . rawurlencode($path)]);
    }
}

// in a ServiceProvider::register()
$container->singleton(ExpiredSessionHandler::class, KeepDraftOnExpiry::class);
```

**Security rule:** the request is not verified as the visitor's. Never apply posted data from
an expired-session request (no save, no state change, no mail). At most keep it to prefill the
form after the visitor signs in again, and let them submit it once more with a valid token.
Drop `_csrf_token` and secrets before keeping anything.

#### Upgrading

Unsafe requests without a session were not checked before. A public POST endpoint called by a
server (a webhook, a payment provider, a cron job over HTTP) sends no origin signal and no
token, so it is now refused: mark its route `#[CsrfExempt('…')]`, or list a file route or a
path outside the router in `csrf.exempt`, before upgrading. Forms of the site keep working on
modern browsers (the origin passes); `csrf_field()` now prints a token on public pages too.
Set a real `app.key`, or browsers that send no origin headers cannot post public forms.

Browser POSTs that come from another site are refused now, also on public paths: the browser
sends `Sec-Fetch-Site: cross-site` (a payment provider's return URL that posts back, an OAuth or
SAML `form_post` callback). Mark such routes `#[CsrfExempt('…')]` too, or list their origin in
`csrf.trusted_origins` when it is a fixed one.

### Caches

The application keeps a `CacheRegistry` in the container. The framework registers its own
caches: `data.json` (the JSON driver's query cache, when the JSON driver is in use) and
`blade` (compiled Blade templates in `storage/cache/blade`, when the Blade engine is in use).
Register yours in a provider so tooling can list and clear them:

```php
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\DirectoryCache;
use Preflow\Core\Cache\FileCache;

final class SiteCacheProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);
        $caches = $container->get(CacheRegistry::class);

        // A folder of cache files; the folder and dotfiles stay, symlinks inside it are never
        // followed. `pattern` is an fnmatch() pattern on file names; files matching `exclude` are
        // neither counted nor cleared.
        $caches->register(new DirectoryCache('site.thumbs', 'Thumbnails', $app->basePath('storage/cache/thumbs')));

        // A single file.
        $caches->register(new FileCache('site.sitemap', 'Sitemap', $app->basePath('storage/cache/sitemap.xml')));
    }
}
```

If you bind your own Twig engine or router with a cache path, register that same path like
this (`DirectoryCache('twig', …)` for compiled templates, `FileCache('routes', …)` for a route
table). Core's own Twig engine compiles into `storage/cache/twig/{key}`, where the key follows
composer's `installed.json`, so an install or update never serves templates compiled by the
previous packages; `cache:clear` empties every key. A `DirectoryCache` folder must be an absolute path other than `/`; anything else
throws an `InvalidArgumentException`. Subfolders that can't be read are skipped.

Keys must be unique; a duplicate throws a `LogicException`. Implement `CacheSource` for
anything else (`key()`, `label()`, `stats()`, `clear()`). `OpcacheStatus` reports and resets
PHP's OPcache for the current SAPI.

### Page cache (`config/page_cache.php`)

The page cache keeps finished pages for visitors who are not signed in, under
`storage/cache/pages/{release key}`, and is listed and cleared as the `pages` cache
(`preflow cache:clear pages`, Folio's Cache screen). It is off until a site switches it on:

```php
// config/page_cache.php: every key is optional, these are the defaults
return [
    'enabled' => false,
    'max_age' => 14400,              // seconds; older pages are rebuilt behind the next visitor; 0: no maximum age
    'rebuild_budget' => 2.0,         // seconds of rebuilding after a response
    'cache_query' => true,           // cache pages with a query string (search, filters)
    'ignore_query' => ['utm_*', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'],
    'vary_headers' => ['HX-Request', 'HX-Target'],
    'signed_in_cookies' => [],       // added to the auth marker and the remember cookies
    'hosts' => [],                   // hosts cached besides the host of app.url
    'max_query_variants' => 50,      // pages with a query kept per URL path, oldest evicted first
    'max_pages' => 50000,            // pages kept in all, oldest evicted first
    'explain' => false,              // send X-Page-Cache-Skip (why a miss was not stored)
    'verify' => 'first',             // 'first', 'always' or 'off': render a page twice before storing it
];
```

- **Hosts.** Only requests for the host of `app.url`, or one listed in `hosts`, are cached. An
  alias domain or a forged `Host` header is never stored. `app.url` needs its scheme
  (`https://example.com`, not `example.com`). Without a host from `app.url` and without `hosts`,
  nothing is cached, and an enabled cache logs that once.
- **Limits.** `max_query_variants` and `max_pages` bound the disk a crawler can fill with
  `?x=1`, `?x=2`, … The oldest pages go first, files and index rows together.
- **Releases.** The release key hashes the real path of the base folder (a deploy's `current`
  link resolves to `releases/N`) and the mtimes of the boot manifest and `installed.json`. A new
  release folder, `preflow optimize` or a composer install starts an empty cache. The file
  `storage/cache/pages/active` names the release that last used the cache: a rollback to an
  older release folder finds another release named there and empties its own pages first.
  Folders of other releases are removed after 24 hours unused, never the active one.
- **Deploys.** A deploy never serves a page of the release before it (it could reference
  assets that are gone): the new release key starts empty. The new release notes which release
  was active before it (`storage/cache/pages/{key}/warm-from`). The first time its refresh queue
  is opened (the cron's first `preflow pages:work`, or a write that queues a page), the full
  pages without a query in the previous release's index are queued, last in line, so the pages visitors used
  are rebuilt within minutes. They are verified again (`verify` `'first'`): the templates
  changed. A rollback is warmed the same way (its old queue and pending invalidations are
  dropped with its pages). On a site quiet for more than 24 hours, the previous release's
  folder may be pruned by the new release's first stored page before the cron opens the queue;
  then nothing is warmed and pages are built on visit.
- **In-place deploys** (a `git pull` in the same folder, without `optimize` or a composer
  install) keep the release key, so the pages from before the deploy stay. Run
  `preflow cache:clear pages` or `preflow optimize` as part of such a deploy.
- **What a page depends on.** While a page renders, every read through the `DataManager`
  is noted as a tag: a record (`default:object#1234`), a listing (`default:object`, from
  a query or an aggregate), or the tables a `raw()` query names (`FROM`, `JOIN`).
  The storage is the driver name (`default` for the default driver, whatever it is
  configured as), and the table is as the driver sees it: Folio's published copies are
  `object__published`. A raw read whose tables can't be told, such as one with a `WITH`
  clause, depends on the whole storage (`default:*`).
- **SQL views.** A raw read of a view is tagged with the view's name (`FROM active_jobs` is
  `default:active_jobs`): the parser can't see which tables the view reads, so a write to its
  base table (`job`) does not reach those pages. Read the base tables in such a query, report
  the view as changed where its tables are written
  (`$pageCache->changed('default:active_jobs')`), or add the base table as a dependency of the
  page (`$pageCache->depends('default:job')`).
- **What a write changes.** Every write through the `DataManager` is noted too. That
  covers `save`, `insert`, `update`, `delete`, `saveType`, `deleteIn`, delete plans,
  `execute()`, a write sent through `raw()`, Folio's publisher and Folio's import. The
  writes are merged, and the pages that depend on them are invalidated once, before the
  response goes out, or at the end of a command (a cron import). A command also flushes
  every 30 seconds and past 5000 pending tags, so a killed run loses little; a command
  still running from the release before a deploy reaches the new release's pages too:
  - writing a record invalidates its pages, the pages listing its table, and the
    whole-storage readers;
  - a raw write invalidates every page of the tables it names;
  - a raw write whose tables can't be told, or that names no table (unless it changes no
    data, such as `SET` or `PRAGMA`), invalidates every page.
- **Memos.** A service that keeps what it read for later calls (the media library, Folio's
  site settings and lists) must still report the read on each hit, or a second page rendered
  in the same process won't depend on it: `$dm->reportRead(...)` for one record, or
  `[$value, $reads] = $dm->captureReads(fn () => ...)` once and `$dm->replayReads($reads)` on
  each hit.
- **Writes the data layer can't see**, such as a bare `$pdo->exec()` or a write through
  `$dataManager->driver(...)`, must be reported:

  ```php
  $pageCache = $container->get(\Preflow\Core\PageCache\PageCache::class);
  $pageCache->changed('default:member');      // the member table changed (many rows)
  $pageCache->changed('default:member#42');   // one member
  $pageCache->changed('*');                   // everything
  ```

  The storage name is mapped as the data layer's own tags are (`mysql:member` becomes
  `default:member` when mysql is the default driver).
  `$dataManager->reportWrite('default', 'member', 42)` does the same from data code.
  Both do nothing while the cache is off.
- **Serving.** `PageCacheMiddleware` runs after the locale middleware and before the session
  middleware. It is on when `enabled` is true, `pdo_sqlite` is loaded and `app.debug` is off.
  In the console it is there for rebuilds only (`preflow pages:work`); every other request
  passes through. A hit reads two small files, the release's `active` name and the page, and
  makes about six stats: the release key (the base folder's real path, the mtimes of the boot
  manifest and `installed.json`), the `enabled` marker, the page's stale marker and the
  release's pending invalidations. No database, no SQLite, no session. Responses carry `X-Page-Cache: hit`,
  `stale`, `miss` or `bypass`. With `explain` on, a miss that wasn't stored says why in
  `X-Page-Cache-Skip` (off by default: the reason is for audits, not for visitors).
- **Sessions.** Nothing is stored from a request that started the session. Without
  `auth.session.paths`, every request starts one at boot, so **a site must limit sessions to
  the paths that need them for anything to be cached**; until it does, every miss says
  `X-Page-Cache-Skip: session` (with `explain` on). With `paths`, a session starts on those
  paths, and on any other path only when the visitor brings the session cookie: an anonymous
  visitor gets none (their pages are stored), a signed-in one resumes theirs everywhere:

  ```php
  // config/auth.php
  'session' => [
      'paths' => ['/login', '/logout', '/account', '/folio', '/--component'],
      // ...
  ],
  ```

  A signed-in visitor's public pages show their account (and are never cached: the
  `preflow_auth` marker bypasses the cache). A leftover session cookie of a visitor who signed
  out also resumes a session, so that visitor's misses are not stored (a hit is still served,
  before any session opens). The form of a cached page carries the public (stateless) CSRF
  token; posted on a public path where the session only resumed by its cookie, it is accepted
  as it is for a visitor without one (with a passing origin). A site that needs sessions for
  guests on public pages (a cart before sign-in) must list those paths. The skeleton ships
  with its sign-in, admin and component paths.
- **The key** is the URL as the visitor asked for it (the locale prefix included), the locale
  the locale middleware resolved (a cookie or `Accept-Language` on unprefixed paths), the
  normalised query, the `vary_headers` values, and a forwarded scheme and host
  (`X-Forwarded-Proto`, `X-Forwarded-Host`), so http and https never share a copy.
- **htmx headers.** Only the `vary_headers` are in the key: `HX-Request` and `HX-Target` by
  default. A fragment handler that answers differently by `HX-Trigger`, `HX-Trigger-Name`,
  `HX-Boosted`, `HX-Current-URL` or a site header must add it to `vary_headers` (or send
  `Vary` for it, which keeps the response from being stored), or the fragments of one target
  overwrite each other. A history restore (`HX-History-Restore-Request`) is served the full
  page, but what it renders is never stored.
- **Live, never cached:** anything but GET and HEAD; admin, API and component paths
  (non-public paths); internal renders; and requests with a signed-in cookie (preflow/auth's
  `preflow_auth` marker, every guard's remember-me cookie, `signed_in_cookies`) or an
  `Authorization` header. A leftover session cookie alone is not one. The marker is set at
  sign-in for as long as the session cookie (or the remember-me cookie); a sliding session
  (`session.lifetime` > 0) can outlive it, and then that visitor gets cached hits until a page
  that asks for the user sets it again. No leak: a miss with their session open is not stored.
- **Never stored:** a render of another kind than its key (a history restore, which an app
  may answer with a fragment), an empty or the default `app.key` (placeholders could be forged),
  a body that can't be read twice (a stream), a page where a component's error boundary or a
  Folio block that couldn't be built stood in for content, a status other than 200 (404s,
  redirects), a response that sets a cookie
  (a header or a native one), a request that started the session or where a guard resolved a
  user, `Cache-Control: private` or `no-store`, a `Vary` on a header the key doesn't hold
  (`Accept-Language` included: a page that redirects by it says so with `Vary` and is not
  cached), bodies that aren't text, JSON, XML or JavaScript, and a page something it read was
  written while it rendered. Code can refuse the page being rendered with
  `$pageCache->refuse('why')`; `$pageCache->storing()` says whether it may be stored.
- **Render isolation.** Every render the cache may store (a visitor's miss, verify's second
  render, every rebuild) starts from the state a public render starts from. A package with
  request-scoped state that outlives a request in the same process (a content mode, memos)
  registers what resets it, and a store check as a second line:

  ```php
  $pageCache->isolate(static function () use ($content): \Closure {
      $restore = $content->showPublished();   // and empty the memos
      return $restore;                         // run after the render, last entered first
  });
  $pageCache->refuseWhen('working-copy', static fn (): bool => $content->showsDrafts());
  ```

  A rebuild after a Folio admin request (a publish) runs in that request's process: Folio
  resets its working-copy mode and its `Collections` and `Singletons` memos this way, so drafts
  never reach a stored page, and refuses a page rendered with working copies anyway
  (`refused:working-copy`, a share preview).
- **Stored headers** are the response's own, without `Set-Cookie`, the security headers
  (`Content-Security-Policy`, `HX-Nonce`, `Strict-Transport-Security`, which are added fresh to
  every hit) and `Date`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Keep-Alive`.
- **Per-request values.** The CSP nonce and the CSRF token are stored as signed placeholders
  and filled in on each hit, so every visitor gets their own (and a form from a hit posts).
  A site adds its own in a provider's `boot()`:

  ```php
  $container->get(\Preflow\Core\PageCache\PerRequestValues::class)
      ->register('form_token', fn (): string => FormToken::value());
  ```

  Values shorter than 8 characters are never replaced.
- **Live parts** are what differs per visitor inside an otherwise shared page: a guest's album
  count, "in your album" buttons, a cart count, a greeting. Mark them, and only they are
  rendered on every visit, on the server:

  ```twig
  {% live with {number: object.number} %}
      {% include '_album_button.twig' with {marked: in_album(number)} %}
  {% endlive %}
  ```

  ```blade
  @live(['number' => $object->number])
      @include('album-button', ['marked' => in_album($number)])
  @endlive
  ```

  A component class marked `#[\Preflow\Components\Live]` is a live part as a whole.
  - The block sees only its `with` values and the template globals, whether the cache is on or
    not. The values are scalars and arrays, and the same for every visitor: what identifies the
    part (an object number), never the personal value itself. The part reads that while it
    renders, from the visitor's request:
    `$container->get(\Preflow\Core\Http\RequestContext::class)->request?->getCookieParams()`.
  - While a page renders for the cache, a live part is not rendered. It leaves a signed
    placeholder, `<!--pc:l:{recipe}:{signature}-->` (the recipe is the template's logical name,
    or the component class, with the block and its values, as base64url JSON; the signature is
    an HMAC with `app.key`). That is what is stored. Every `text/html` response the cache
    answers fills the placeholders for its visitor, the miss that stored the page included.
  - Only declared live parts are ever filled: a `{% live %}` block of a template the loader
    finds by name (or of a component's own template, by its class), an `@live` body the Blade
    engine extracted (checked by its hash), a component class marked `#[Live]`. No file path
    is ever read from a recipe. A marker that doesn't verify, or names anything else, is left
    as an inert HTML comment, logged once per response
    (`page cache: N live marker(s) left as comments …`).
  - With an empty `app.key`, the default one or the skeleton's example
    (`change-this-to-a-random-32-char-string!`), nothing is signed or filled: live parts render
    in place, and the page is not stored (`app-key`).
  - Block names starting with `__live_` belong to `{% live %}`: a template's own
    `{% block __live_… %}` is a syntax error.
  - A body that is not `text/html` (a JSON string with a live component in it) is never filled
    and, while it holds a placeholder, never stored (`live-non-html`). A response without a
    `Content-Type` is HTML (PHP sends `text/html` for it), logged once. A template in no
    template folder and of no component (a string) renders its live parts in place and is not
    stored (`refused:live:twig-template`, `live:blade-template`).
  - What a live part reads is not a dependency of the page, `refuse()` inside it does nothing,
    and a part that throws becomes `<div hidden data-live-error="true"></div>` (logged) while
    the rest of the page is served. A live component goes through its error boundary.
  - Many live components of one class are loaded together: a class implementing
    `\Preflow\Components\PrefetchesLive` gets one `prefetchLive($propsList)` call per page.
  - CSS and JS a live part adds while it is filled are not printed: keep them in the page.
    Live parts don't open the session (a hit has none), and see only globals registered at
    boot (a global a middleware adds later is missing on hits).
- **Verify.** A page about to be stored is rendered once more as another anonymous visitor:
  another `REMOTE_ADDR` (and forwarded address), no cookies. If the two differ in their body or
  their stored headers (an `HX-Trigger` with the visitor's album count) outside their live
  parts and per-request values (each render's own), the page is not stored
  (`X-Page-Cache-Skip: differs`) and `page cache: {url} differs per visitor outside live parts`
  is logged: something personal is not in a live part or registered. It never runs on a hit.
  - `'first'` (the default) verifies each key once per release: its first store, by a visitor
    or a rebuild. A verified key is noted in the release's index (kept when the page is
    forgotten, emptied by `cache:clear pages` and a rollback), and is not verified again; a key
    that differed is verified again on its next miss. A forgotten personal value shows up on
    real traffic as a log line and an uncached page, never as a leak. It costs one extra render
    per page and release. It checks the visitor it gets: a part that differs only for visitors
    with a cookie (an album) is caught when such a visitor makes the first store.
  - `'always'` verifies every store: each stored miss costs two renders. For tests and staging;
    preflow/testing's `WebApplication` sets it.
  - `'off'` never verifies. `true` and `false` mean `'always'` and `'off'`.
  - The second render sees the other visitor in the PSR request, `RequestContext` and the
    superglobals (`$_COOKIE` empty, `$_SERVER['REMOTE_ADDR']` another address, no
    `HTTP_COOKIE` or `HTTP_X_FORWARDED_FOR`, `$_REQUEST` without the cookies; all put back
    afterwards; a leftover `$_SESSION` outside an active session is hidden too). A render with a
    session active is not verified, so not stored.
    A site's own memo of an earlier value is the same in both renders and goes unnoticed.
- **Freshness, per dependency.** What a page reads is fresh by default: a write forgets the
  page, so the next visitor gets the new state. A developer can loosen
  that for a part of a page, in the template or the component, never in settings:

  ```twig
  <h1>{{ job.title }}</h1>                         {# fresh #}
  {% freshness 'next-visit' %}
      {% for other in jobs_of(job.company) %}…{% endfor %}
  {% endfreshness %}
  {% freshness 'timed' every '12h' %}{{ weather() }}{% endfreshness %}
  ```

  ```blade
  @freshness('next-visit') … @endfreshness
  @freshness('timed', every: '12h') … @endfreshness
  ```

  ```php
  #[\Preflow\Components\Freshness(Freshness::NextVisit)]           // around resolveState() and render
  #[\Preflow\Components\Freshness(Freshness::Timed, seconds: 43200)]
  $pageCache->within(Freshness::NextVisit, null, fn () => $jobs->ofCompany($id));
  ```

  - `fresh`: a write of what it read forgets the page.
  - `next-visit`: a write marks the page stale. The next visitor still gets it at once, and a
    fresh one is built behind them.
  - `timed` (`every '30m'`, `'12h'`, `'2d'` or seconds): writes are ignored. The page expires
    at its render time plus that time, but never later than `max_age`: with the default 4 h,
    `every '12h'` refreshes every 4 h. Raise `max_age` for longer times.
  - A tag read several ways counts as the strictest (fresh, then next visit, then timed). Only
    the changed tags count: a page that read "other jobs" timed and its own job fresh is still
    forgotten when its job changes. Nested scopes: the innermost wins. Inside a live part the
    dial does nothing (its reads are no dependencies). `'*'` (a raw write whose tables are
    unsure, `pages:refresh --all`) forgets every page whatever its freshness, and queues every
    full page for rebuilding; `cache:clear pages` empties the cache without rebuilding.
  - The dial applies where a read runs, not where its value is shown: `{% set others = … %}`
    inside a next-visit scope and printed outside it is still next visit. A component's reads
    before `resolveState()` (its constructor) follow the scope around it.
  - A write while the page renders: of something it read fresh, the page is not stored; next
    visit only, it is stored stale; timed only, it is stored.
  - Folio's frontend pages (types with publishing) depend on their record by id and on their
    address (`DataTags::address()`, `~address:/path`), not on the whole type their lookup
    queried. The Publisher reports the addresses a publish, unpublish or delete leaves and
    takes. So "other jobs" next visit works on a job's own Folio page, and publishing another
    record of the type leaves other detail pages alone (unless they list the type, which follows
    its own freshness). Every publishing route a path tries also adds "any record" of its
    table (`{storage}:{table}__published#`), found or not: a table or bulk write around the
    Publisher (an import, a pull) forgets every page another record of that type could claim.
    A single-record write around the Publisher reaches the pages of that record only.
  - `$pageCache->depends('menu')` adds a dependency the data layer can't see, in the scope's
    freshness. Tags starting with `~` are exact: read and changed as they are.
- **Expiry and the maximum age.** A page can say when it goes out of date, an event date or a
  sale ending at midnight; the earliest of several counts:

  ```twig
  {% expires at event.startsAt %}   {# a DateTimeInterface, a unix time or a date string; null: none #}
  {% expires in '1h' %}
  ```

  ```blade
  @expires(at: $event->startsAt)
  @expires(in: '1h')
  ```

  `$pageCache->expiresAt($date)` does it from PHP. A page past its expiry, or older than
  `max_age` (4 h by default; `0` turns the maximum age off, a negative value is refused), is
  served as `stale` (an expiry at or before the render is ignored: the page already shows what
  came after it) and its rebuild is queued: it is built right after that response, or by the
  cron. A `HEAD` request (an uptime monitor) is served the stale page and queues nothing. A
  stale fragment or search page is served once and then forgotten (their next visit renders
  them).
- **Rebuilding.** A write forgets the pages that read it fresh, and queues the full pages
  without a query among them for rebuilding (the refresh queue, a table in the release's
  `index.sqlite`): the pages that showed the changed record or address first, then the
  listings. A stale hit queues its page. Fragments and search or filter pages are never queued:
  they are built on their next visit. One page is one queue entry, however often it is queued
  (an import of 300 jobs queues each affected page once).
  - **After the response.** The request that queued something rebuilds right after its
    response went out (`fastcgi_finish_request`/`litespeed_finish_request`), for
    `rebuild_budget` seconds (2.0; checked after each page, so at least one is built; `0`
    leaves everything to the cron). Code can add its own work there with
    `$app->afterResponse(fn () => ...)`.
  - **The cron** takes what is left, every minute:

    ```cron
    * * * * * cd /path/to/site/current && php preflow pages:work >/dev/null 2>&1
    ```

    `preflow pages:work [--budget=50]` rebuilds for up to 50 seconds. `preflow pages:refresh
    {url}` forgets every copy of one URL (a path is taken against `app.url`) and rebuilds it;
    `preflow pages:refresh --all` forgets every page and rebuilds the known full pages. Folio's
    Cache screen has the same two actions. Both record an invalidation first (of the page's
    tags, or of everything), so a render already running can't store the old page after them.
  - A rebuild is an internal anonymous GET of the page's URL through the whole pipeline
    (`Rebuild::request()`, marked `InternalRender` and `PageCache::REBUILD`), from clean request
    state each time (request context, assets, SEO meta, translator locale). It is anonymous in
    PHP's superglobals too, as verify's second render is (`AnonymousGlobals`): after a response
    they are still those of the request that wrote, so during the rebuild `$_COOKIE`, `$_POST`
    and `$_FILES` are empty, `$_GET`/`$_REQUEST` are the page's query, `$_SERVER` has no
    `HTTP_COOKIE` or forwarded address and `REMOTE_ADDR` 127.0.0.1, and `$_SESSION` is unset;
    all are put back afterwards. With a PHP session open, nothing is rebuilt.
  - It carries the key parts the page was stored under: the locale (as `Accept-Language`),
    `X-Forwarded-Proto`/`X-Forwarded-Host` and the vary header values, so it is stored under the
    key visitors read; one that gives another key is not rendered (logged). The stock locale
    middleware resolves a cookie visitor's language the same way from `Accept-Language`; a site
    middleware that chooses the language from a cookie of its own must also understand
    `Accept-Language` (and set the `locale` request attribute), or its pages are only built on
    visit.
  - A page a visitor already rendered again is skipped. A rebuild that is not stored (a 404 or
    redirect, a cookie set, a refusal, another key, an error) forgets the page: a stale copy is
    never kept past its rebuild, and its next visitor renders it. One that a write refused while
    it rendered stays queued for a later pass, and is dropped after 3 such failures in a row.
  - Never a loop: each page is built at most once per pass, and what a rebuild itself writes
    forgets pages as any write does but queues nothing in that pass. A page whose render writes
    what it read (a view counter) is therefore not cached, as before. The work after a response
    is one pass per request; it never schedules more.
  - Claim with lease: each process takes a queue entry in a write transaction and holds it for
    5 minutes, so the after-response work and the cron never build one URL at once, and an
    entry of a process that died is taken again later.
- **Failed invalidations.** An invalidation that can't reach the index (SQLite busy, the file
  unusable) is never dropped: it is kept in `storage/cache/pages/{key}/pending-invalidations`
  and applied by the next request or command that reaches the index. While one is pending, no
  page of the release is served from the cache or stored (one stat per hit), so an index that
  stays unreachable turns the cache off rather than serving pages nothing can refresh. If even
  that file can't be written, the release's pages are removed.
- **Switching it off and on.** `storage/cache/pages/enabled` marks a cache that has been
  emptied since it was switched on. While the cache is off, the first write through the data
  layer removes the marker; the first request after switching it on again finds it missing and
  clears the cache before serving anything.

### Boot manifest (`preflow optimize`)

`preflow optimize` (preflow/devtools) writes `bootstrap/cache/boot.php`: what boot otherwise
reads and scans on every request although it only changes on deploy. It holds:

- the result of every `config/*.php` (read through `Application::configFile()`);
- the package discovery data (`InstalledPackages`, i.e. `vendor/composer/installed.json`);
- the catalogs packages remember: components, controllers, translation groups, and Folio's
  extensions, extension types, model listings, frontend routes, blocks and interface languages;
- the compiled public routes, per set (`Router::compiled()`, see preflow/routing): the app's own
  (`app`: file pages and controllers), the media core's (`media`), the members pages
  (`members`, `members.landing`) and Folio's frontend catch-all (`folio.frontend`). With a
  manifest no route pattern is compiled per request. Folio's admin routes are not in it: they
  are compiled on the first request under the admin prefix in a process, as without a manifest.
  A set that can't be exported (middleware given as closures or objects) is compiled live, every
  request; so is a stored set whose rows the installed routing code can't rebuild.

A boot uses it only when all of these hold; otherwise it boots live, exactly as without one:

- the request is not in the console (`runningInConsole()`): **CLI commands and cron jobs never
  read it**, so they always see the current files;
- `app.debug` is off (`APP_DEBUG=0`), decided live from `config/app.php` as this request loads
  it: `APP_DEBUG=1` set by the web server, or `'debug' => 1` edited on the server, bypasses the
  manifest at once. `optimize` writes none in debug mode;
- `.env`, `config/folio/extensions.json` and `vendor/composer/installed.json` are unchanged
  (each is stat-ed on every request), and every variable `.env` sets has the value it had when
  the manifest was written. Config files read env values when they load, so the manifest holds
  those values: an edited `.env` applies on the next request (live boots until the next
  `optimize`).

**Environment variables set only by the web server** (`SetEnv`, the FPM pool, the hosting
panel) **and not named in `.env` are not watched**: after changing one, run `preflow optimize`
again. `optimize --check` runs in the CLI and compares the CLI's environment, so it can't see a
variable that the web server sets to a different value than the shell: `--check` says "current"
while every web request boots live (correct, only slower). To confirm a site really uses its
manifest, check from a web request (timing, or a temporary debug header), not only with `--check`.

**Secrets.** A config file that reads a secret (`getenv('DB_PASS')`, `app.key`) puts its value
in the manifest, so a secret that only the web server or the deploy shell sets now also lands on
disk. The file gets the `.env`'s permission bits (0640 without a `.env`), lives under the release
in `bootstrap/cache/`, outside `public/`; keep it there and don't commit it (see below).

**OPcache with `opcache.validate_timestamps=0`.** Running `optimize` again in place (same
release path) doesn't reach the web server's OPcache, which keeps serving the old compiled
manifest until it is reset: reset OPcache afterwards (Folio → Settings → Cache → Reset OPcache,
or your deploy's reset). A deploy to a new release path is not affected.

**Toggling an extension** (Folio's Settings → Extensions, `folio:extensions:*`) rewrites
`extensions.json` and **removes the manifest**: the change applies on the next request, and the
site boots live until the next deploy or `preflow optimize`. Stored settings are never in it. Everything else in it
(config files, code, model files, `installed.json`) is trusted until the next
`preflow optimize`: run it on every deploy, after `composer install` and migrations /
`folio:extensions:apply`, before the release goes live. `preflow optimize --check` lists the
sources that changed since (exit 1), each once, and `preflow cache:clear` removes the manifest
(cache `boot`), as does "Clear all" on Folio's Cache screen: the site stays correct and boots live
until the next deploy or `preflow optimize`. A failed `optimize` (the build or the write) removes
it too, so the manifest of the code before is never left in place. The file holds config values, secrets included:
it gets the `.env`'s file permissions, is per release and must not be committed. Add this to the site's `.gitignore`:

```
/bootstrap/cache/
```

A package keeps its own deploy-time data in the manifest with
`$app->bootManifest()->remember('vendor.key', fn () => …, [$sourcePath])`: the closure runs
live without a manifest and while one is built; with a manifest its stored value comes back.
The value must be exportable (scalars, arrays, enums) and depend only on code, config files
and files that change on deploy, never on stored data or the request.
`$manifest->whenBuilding(fn (Application $app) => …)` resolves a lazy service while the
manifest is built, so its catalog is in it.
A package's routes go in as a set of their own:
`$router->getCollection()->addMany($router->compiled('vendor.name', fn () => [...entries]))`
(`$router` is the `Preflow\Routing\Router` bound as `RouterInterface`); the same rules apply.
A page or controller added on the server without a deploy is not routed until the next
`preflow optimize` (`optimize --check` lists `app/pages` as changed).

### HTTP exceptions

Throw these anywhere in the request lifecycle — `ErrorHandler` converts them to the correct status code automatically.

```php
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\UnauthorizedHttpException;

throw new NotFoundHttpException('Page not found');
throw new ForbiddenHttpException();
throw new UnauthorizedHttpException('Login required');
```

### Non-public paths

`Preflow\Core\Http\NonPublicPaths` is a list of path prefixes that are not public pages. The
application keeps one in the container, holding `/--component` from the start; packages add
theirs in their provider (Folio its admin prefix, members its `path`, media its `private_url`):

```php
use Preflow\Core\Http\NonPublicPaths;

if ($container->bound(NonPublicPaths::class)) {
    $container->get(NonPublicPaths::class)->add('/api');
}
```

A prefix matches whole segments: `/folio` covers `/folio` and `/folio/x`, not `/folios`. `''` and
`/` are ignored (they would cover the whole site). `matches($path)` and `all()` read the list.
Redirects and the not-found list (`preflow/routing`) never answer or record a 404 under these
paths. Add the prefix of any area of your own that is not a page (an API, a webhook endpoint).
