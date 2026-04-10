<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\Attributes\Route as RouteAttr;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Router;

#[RouteAttr('/api/items')]
class ItemController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    public function store(): void {}
}

final class RouterTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_router_test_' . uniqid();
        mkdir($this->pagesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->pagesDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createPage(string $relativePath): void
    {
        $path = $this->pagesDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, '');
    }

    private function createRequest(string $method, string $uri): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    public function test_matches_file_based_route(): void
    {
        $this->createPage('index.twig');
        $this->createPage('about.twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/about'));

        $this->assertSame(RouteMode::Component, $route->mode);
        $this->assertSame('about.twig', $route->handler);
    }

    public function test_matches_attribute_based_route(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items'));

        $this->assertSame(RouteMode::Action, $route->mode);
        $this->assertStringContainsString('@index', $route->handler);
    }

    public function test_extracts_parameters(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items/42'));

        $this->assertSame('42', $route->parameters['id']);
    }

    public function test_file_route_with_dynamic_param(): void
    {
        $this->createPage('blog/[slug].twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/blog/hello-world'));

        $this->assertSame(RouteMode::Component, $route->mode);
        $this->assertSame('hello-world', $route->parameters['slug']);
    }

    public function test_throws_not_found_for_no_match(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);

        $this->expectException(NotFoundHttpException::class);
        $router->match($this->createRequest('GET', '/nonexistent'));
    }

    public function test_correct_http_method_matching(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $getRoute = $router->match($this->createRequest('GET', '/api/items'));
        $this->assertStringContainsString('@index', $getRoute->handler);

        $postRoute = $router->match($this->createRequest('POST', '/api/items'));
        $this->assertStringContainsString('@store', $postRoute->handler);
    }

    public function test_middleware_included_in_route(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ItemController::class],
        );

        $route = $router->match($this->createRequest('GET', '/api/items'));

        // Route object carries middleware from the entry
        $this->assertIsArray($route->middleware);
    }

    public function test_returns_core_route_object(): void
    {
        $this->createPage('index.twig');

        $router = new Router(pagesDir: $this->pagesDir);
        $route = $router->match($this->createRequest('GET', '/'));

        $this->assertInstanceOf(\Preflow\Core\Routing\Route::class, $route);
        $this->assertSame(RouteMode::Component, $route->mode);
    }
}
