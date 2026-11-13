<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Routing\ConfiguredMiddleware;
use Preflow\Routing\AttributeRouteScanner;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Route;
use Preflow\Routing\MiddlewareWith;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteCompiler;
use Preflow\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GuardMiddleware implements MiddlewareInterface
{
    public function __construct(public readonly ?string $guard = null, public readonly string $redirectTo = '/') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

#[Route('/employer', middleware: ['Session', new MiddlewareWith(GuardMiddleware::class, guard: 'employers')])]
#[Middleware('Csrf')]
final class EmployerController
{
    #[Get('/jobs')]
    #[Middleware(new MiddlewareWith(GuardMiddleware::class, guard: 'staff', redirectTo: '/login'), 'Audit')]
    public function jobs(): void {}
}

/** MiddlewareWith: middleware with constructor arguments in #[Middleware] and #[Route(middleware:)]. */
final class MiddlewareWithTest extends TestCase
{
    public function test_holds_the_class_and_the_named_arguments(): void
    {
        $with = new MiddlewareWith(GuardMiddleware::class, guard: 'employers');

        $this->assertInstanceOf(ConfiguredMiddleware::class, $with);
        $this->assertSame(GuardMiddleware::class, $with->middlewareClass());
        $this->assertSame(['guard' => 'employers'], $with->arguments());
        $this->assertSame([], (new MiddlewareWith(GuardMiddleware::class))->arguments());
    }

    public function test_an_unknown_class_fails_clearly(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('MiddlewareWith: class [App\Nope] not found.'));
        new MiddlewareWith('App\Nope', guard: 'x');
    }

    public function test_a_class_that_is_no_middleware_fails_clearly(): void
    {
        $this->expectExceptionMessage('MiddlewareWith: [stdClass] is not a middleware');
        new MiddlewareWith(\stdClass::class);
    }

    public function test_an_unknown_argument_fails_clearly(): void
    {
        $this->expectExceptionMessage('MiddlewareWith: [' . GuardMiddleware::class . '] has no constructor parameter [$gaurd].');
        new MiddlewareWith(GuardMiddleware::class, gaurd: 'employers');
    }

    public function test_a_positional_argument_fails_clearly(): void
    {
        $this->expectExceptionMessage('pass the arguments for [' . GuardMiddleware::class . "] by name (guard: 'employers').");
        new MiddlewareWith(GuardMiddleware::class, 'employers');
    }

    public function test_a_route_attribute_with_something_else_fails_clearly(): void
    {
        $this->expectExceptionMessage("#[Route('/x')] middleware: a class name or a MiddlewareWith, not int.");
        new Route('/x', middleware: [42]);
    }

    public function test_the_scanner_keeps_strings_and_middleware_with_arguments_in_order(): void
    {
        $entries = (new AttributeRouteScanner())->scanClass(EmployerController::class);

        $this->assertEquals([
            'Session',
            new MiddlewareWith(GuardMiddleware::class, guard: 'employers'),
            'Csrf',
            new MiddlewareWith(GuardMiddleware::class, guard: 'staff', redirectTo: '/login'),
            'Audit',
        ], $entries[0]->middleware);
    }

    public function test_the_route_cache_keeps_the_arguments(): void
    {
        $path = sys_get_temp_dir() . '/preflow_mw_with_' . bin2hex(random_bytes(4)) . '/routes.php';
        $collection = new RouteCollection();
        $collection->addMany((new AttributeRouteScanner())->scanClass(EmployerController::class));
        (new RouteCompiler())->compile($collection, $path);

        try {
            $route = (new Router(cachePath: $path))->match((new Psr17Factory())->createServerRequest('GET', '/employer/jobs'));
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
        }

        $this->assertEquals($collection->all()[0]->middleware, $route->middleware);
        $this->assertSame(['guard' => 'employers'], $route->middleware[1]->arguments());
    }

    public function test_the_boot_manifest_keeps_the_arguments(): void
    {
        // The manifest keeps a set only when it is exportable, and writes it with var_export().
        $kept = [];
        $store = static function (string $set, \Closure $compile) use (&$kept): array {
            if (isset($kept[$set])) {
                return eval('return ' . $kept[$set] . ';');
            }
            $rows = $compile();
            if (BootManifest::exportable($rows)) {
                $kept[$set] = var_export($rows, true);
            }

            return $rows;
        };
        $scan = static fn (): array => (new AttributeRouteScanner())->scanClass(EmployerController::class);

        $first = new Router(store: $store);
        $live = $first->compiled('app', $scan);
        $second = new Router(store: $store);
        $stored = $second->compiled('app', $scan);

        $this->assertArrayHasKey('app', $kept);
        $this->assertSame([], $second->liveSets(), 'served from the manifest, not compiled again');
        $this->assertEquals($live[0]->middleware, $stored[0]->middleware);
        $this->assertSame(serialize($live), serialize($stored), 'what the manifest file checks before it is kept');
        $this->assertSame(['guard' => 'staff', 'redirectTo' => '/login'], $stored[0]->middleware[3]->arguments());
    }
}
