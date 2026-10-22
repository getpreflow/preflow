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
| HTTP exceptions | `NotFoundHttpException`, `ForbiddenHttpException`, `UnauthorizedHttpException` |
| Route contracts | `Route`, `RouteMode`, `RouterInterface` |

## API

### Container

```php
// Bind an interface to a concrete class (transient)
$container->bind(CacheInterface::class, RedisCache::class);

// Bind as singleton
$container->singleton(LoggerInterface::class, FileLogger::class);

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
