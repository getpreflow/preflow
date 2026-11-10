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
            '/post-only' => throw new \Preflow\Core\Exceptions\MethodNotAllowedHttpException(['POST'], 'Method GET not allowed for [/post-only]'),
            default => throw new NotFoundHttpException('No route matches [' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ']'),
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
    private string $cwd = '';
    private string $tmp = '';

    /** Application::create([...]) uses the working directory as its base path: keep its storage/ out of the repo. */
    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->tmp = sys_get_temp_dir() . '/preflow_apptest_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        chdir($this->tmp);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        if (is_dir($this->tmp)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

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

    /** @return array{int, string} status and body of a GET in the given debug mode */
    private function get(string $path, bool $debug): array
    {
        $app = Application::create(['app' => ['debug' => $debug]]);
        $app->setRouter(new AppTestRouter());
        $app->setActionDispatcher(fn ($route, $req) => new Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200));
        $app->boot();
        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', $path)->withHeader('Accept', 'text/html'));

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    public function test_production_404_and_405_never_show_the_exception_message(): void
    {
        [$status, $body] = $this->get('/nonexistent', false);
        $this->assertSame(404, $status);
        $this->assertStringNotContainsString('No route matches', $body);
        $this->assertStringContainsString('Not Found', $body);

        [$status, $body] = $this->get('/post-only', false);
        $this->assertSame(405, $status);
        $this->assertStringNotContainsString('not allowed for', $body);
        $this->assertStringContainsString('Method Not Allowed', $body);
    }

    public function test_debug_404_keeps_the_exception_message(): void
    {
        [$status, $body] = $this->get('/nonexistent', true);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('No route matches [GET /nonexistent]', $body);
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
