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
