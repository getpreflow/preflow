<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Factory\Psr17Factory as Psr17;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\JsonErrorRenderer;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Exceptions\MethodNotAllowedHttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\Attributes\Route as RouteAttr;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Router;
use Preflow\Routing\RouteCollection;
use Preflow\Core\Http\Csrf\CsrfExempt;

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

#[RouteAttr('/jobs')]
class JobController
{
    #[Get('/{id}')]
    public function show(): void {}

    #[\Preflow\Routing\Attributes\Delete('/{id}')]
    public function destroy(): void {}
}

#[RouteAttr('/api/jobs')]
class ApiJobController
{
    #[Get('/{id}')]
    public function show(): void {}

    #[\Preflow\Routing\Attributes\Delete('/{id}')]
    public function destroy(): void {}
}

#[RouteAttr('/hook')]
#[CsrfExempt('Signed webhook')]
class ExemptHookController
{
    #[Post('/')]
    public function receive(): void {}
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

    public function test_blade_pages_are_routed(): void
    {
        $this->createPage('about.blade.php');

        $router = new Router(pagesDir: $this->pagesDir, templateExtension: 'blade.php');
        $route = $router->match((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/about'));

        $this->assertStringEndsWith('about.blade.php', $route->handler);
    }

    public function test_match_carries_csrf_exempt(): void
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            controllers: [ExemptHookController::class, ItemController::class],
        );

        $this->assertTrue($router->match($this->createRequest('POST', '/hook'))->csrfExempt);
        $this->assertFalse($router->match($this->createRequest('POST', '/api/items'))->csrfExempt);
    }

    public function test_route_cache_round_trips_csrf_exempt(): void
    {
        $router = new Router(pagesDir: $this->pagesDir, controllers: [ExemptHookController::class]);
        $data = $router->getCollection()->toArray();
        $restored = RouteCollection::fromArray($data);

        $this->assertTrue($restored->all()[0]->csrfExempt);

        // Caches written before the flag existed load as not exempt.
        unset($data[0]['csrfExempt']);
        $this->assertFalse(RouteCollection::fromArray($data)->all()[0]->csrfExempt);
    }

    private function jobsRouter(): Router
    {
        return new Router(pagesDir: $this->pagesDir, controllers: [JobController::class]);
    }

    public function test_wrong_method_on_an_existing_path_is_405_with_allow(): void
    {
        try {
            $this->jobsRouter()->match($this->createRequest('POST', '/jobs/7'));
            $this->fail('Expected a 405');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(405, $e->statusCode);
            $this->assertSame('DELETE, GET, HEAD', $e->headers['Allow']);
            $this->assertSame(['DELETE', 'GET', 'HEAD'], $e->allowed);
        }
    }

    public function test_head_still_matches_the_get_route(): void
    {
        $route = $this->jobsRouter()->match($this->createRequest('HEAD', '/jobs/7'));

        $this->assertStringContainsString('@show', $route->handler);
    }

    public function test_unknown_path_is_still_404_for_any_method(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->jobsRouter()->match($this->createRequest('POST', '/nope'));
    }

    public function test_405_is_answered_as_html_or_json_by_the_error_handler(): void
    {
        $bodies = [];
        $handler = new ErrorHandler(new ProdErrorRenderer(), null, new ApiConfig(), new JsonErrorRenderer(false));
        $router = new Router(pagesDir: $this->pagesDir, controllers: [JobController::class, ApiJobController::class]);
        $factory = new Psr17();

        $html = $factory->createServerRequest('POST', '/jobs/7')->withHeader('Accept', 'text/html');
        $json = $factory->createServerRequest('POST', '/api/jobs/7');

        foreach ([$html, $json] as $request) {
            try {
                $router->match($request);
                $this->fail('Expected a 405');
            } catch (MethodNotAllowedHttpException $e) {
                $response = $handler->handleException($e, $request);
                $this->assertSame(405, $response->getStatusCode());
                $this->assertSame('DELETE, GET, HEAD', $response->getHeaderLine('Allow'));
                $bodies[] = [$response->getHeaderLine('Content-Type'), (string) $response->getBody()];
            }
        }

        $this->assertStringContainsString('text/html', $bodies[0][0]);
        $this->assertStringContainsString('<', $bodies[0][1]);
        $this->assertStringContainsString('application/json', $bodies[1][0]);
        $this->assertSame('{"message":"Method Not Allowed"}', $bodies[1][1]);
    }

    private function catchAllRouter(): Router
    {
        $router = new Router(pagesDir: $this->pagesDir, controllers: []);
        $router->getCollection()->add(new \Preflow\Routing\RouteEntry('/{path}', 'page', 'GET', RouteMode::Component, [], ['path'], '#^/(?P<path>.+)$#', true));
        $router->getCollection()->add(new \Preflow\Routing\RouteEntry('/x', 'handler', 'POST', RouteMode::Action, [], [], '#^/x$#', false));

        return $router;
    }

    public function test_wrong_method_where_a_catch_all_matches_is_405_with_allow(): void
    {
        try {
            $this->catchAllRouter()->match($this->createRequest('GET', '/x'));
            $this->fail('Expected a 405');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(405, $e->statusCode);
            $this->assertSame('POST', $e->headers['Allow']);
        }
    }

    public function test_a_path_without_other_method_routes_still_reaches_the_catch_all(): void
    {
        $route = $this->catchAllRouter()->match($this->createRequest('GET', '/other'));

        $this->assertSame('page', $route->handler);
    }

    public function test_405_through_a_catch_all_is_a_json_error_for_api_paths(): void
    {
        $router = new Router(pagesDir: $this->pagesDir, controllers: []);
        $router->getCollection()->add(new \Preflow\Routing\RouteEntry('/{path}', 'page', 'GET', RouteMode::Component, [], ['path'], '#^/(?P<path>.+)$#', true));
        $router->getCollection()->add(new \Preflow\Routing\RouteEntry('/api/hook', 'handler', 'POST', RouteMode::Action, [], [], '#^/api/hook$#', false));
        $handler = new ErrorHandler(new ProdErrorRenderer(), null, new ApiConfig(), new JsonErrorRenderer(false));
        $request = (new Psr17())->createServerRequest('GET', '/api/hook');

        try {
            $router->match($request);
            $this->fail('Expected a 405');
        } catch (MethodNotAllowedHttpException $e) {
            $response = $handler->handleException($e, $request);
            $this->assertSame(405, $response->getStatusCode());
            $this->assertSame('POST', $response->getHeaderLine('Allow'));
            $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        }
    }
}
