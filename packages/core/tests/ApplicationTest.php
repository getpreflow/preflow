<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Config\Config;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;

class AppTestRouter implements RouterInterface
{
    public function match(ServerRequestInterface $request): Route
    {
        return match ($request->getUri()->getPath()) {
            '/' => new Route(RouteMode::Action, handler: 'home'),
            default => throw new NotFoundHttpException(),
        };
    }
}

class AppTestProvider extends ServiceProvider
{
    public bool $registered = false;
    public bool $booted = false;

    public function register(Container $container): void
    {
        $this->registered = true;
    }

    public function boot(Container $container): void
    {
        $this->booted = true;
    }
}

final class ApplicationTest extends TestCase
{
    public function test_creates_with_config(): void
    {
        $app = Application::create([
            'app' => ['name' => 'TestApp', 'debug' => true],
        ]);

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('TestApp', $app->config()->get('app.name'));
    }

    public function test_container_is_accessible(): void
    {
        $app = Application::create([]);

        $this->assertInstanceOf(Container::class, $app->container());
    }

    public function test_registers_and_boots_providers(): void
    {
        $provider = new AppTestProvider();

        $app = Application::create([]);
        $app->registerProvider($provider);
        $app->setRouter(new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                throw new NotFoundHttpException();
            }
        });
        $app->setActionDispatcher(fn ($route, $req) => new Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200));
        $app->boot();

        $this->assertTrue($provider->registered);
        $this->assertTrue($provider->booted);
    }

    public function test_handles_request(): void
    {
        $app = Application::create([
            'app' => ['debug' => true],
        ]);

        $app->setRouter(new AppTestRouter());
        $app->setActionDispatcher(fn ($route, $req) => new Response(200, [], 'home'));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200, [], 'component'));
        $app->boot();

        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('home', (string) $response->getBody());
    }

    public function test_handles_404(): void
    {
        $app = Application::create([
            'app' => ['debug' => false],
        ]);

        $app->setRouter(new AppTestRouter());
        $app->setActionDispatcher(fn ($route, $req) => new Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200));
        $app->boot();

        $request = (new Psr17Factory())->createServerRequest('GET', '/nonexistent');
        $response = $app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_config_is_available_in_container(): void
    {
        $app = Application::create([
            'app' => ['name' => 'ContainerTest'],
        ]);

        $config = $app->container()->get(Config::class);

        $this->assertSame('ContainerTest', $config->get('app.name'));
    }
}
