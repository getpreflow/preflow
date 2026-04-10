<?php
declare(strict_types=1);
namespace Preflow\Routing\Tests\Attributes;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Attributes\Route;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Put;
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Patch;
use Preflow\Routing\Attributes\Middleware;

#[Route('/api/v1/posts')]
#[Middleware('AuthMiddleware')]
class FakeController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    #[Middleware('AdminMiddleware')]
    public function store(): void {}

    #[Put('/{id}')]
    public function update(): void {}

    #[Delete('/{id}')]
    public function destroy(): void {}

    #[Patch('/{id}')]
    public function patch(): void {}
}

final class AttributeTest extends TestCase
{
    public function test_route_attribute_on_class(): void
    {
        $ref = new \ReflectionClass(FakeController::class);
        $attrs = $ref->getAttributes(Route::class);
        $this->assertCount(1, $attrs);
        $route = $attrs[0]->newInstance();
        $this->assertSame('/api/v1/posts', $route->path);
    }

    public function test_get_attribute_on_method(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'index');
        $attrs = $ref->getAttributes(Get::class);
        $this->assertCount(1, $attrs);
        $get = $attrs[0]->newInstance();
        $this->assertSame('/', $get->path);
        $this->assertSame('GET', $get->method);
    }

    public function test_post_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'store');
        $post = $ref->getAttributes(Post::class)[0]->newInstance();
        $this->assertSame('/', $post->path);
        $this->assertSame('POST', $post->method);
    }

    public function test_put_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'update');
        $put = $ref->getAttributes(Put::class)[0]->newInstance();
        $this->assertSame('PUT', $put->method);
    }

    public function test_delete_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'destroy');
        $del = $ref->getAttributes(Delete::class)[0]->newInstance();
        $this->assertSame('DELETE', $del->method);
    }

    public function test_patch_attribute(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'patch');
        $patch = $ref->getAttributes(Patch::class)[0]->newInstance();
        $this->assertSame('PATCH', $patch->method);
    }

    public function test_middleware_attribute_on_class(): void
    {
        $ref = new \ReflectionClass(FakeController::class);
        $attrs = $ref->getAttributes(Middleware::class);
        $this->assertCount(1, $attrs);
        $mw = $attrs[0]->newInstance();
        $this->assertSame(['AuthMiddleware'], $mw->middleware);
    }

    public function test_middleware_attribute_on_method(): void
    {
        $ref = new \ReflectionMethod(FakeController::class, 'store');
        $attrs = $ref->getAttributes(Middleware::class);
        $this->assertCount(1, $attrs);
        $mw = $attrs[0]->newInstance();
        $this->assertSame(['AdminMiddleware'], $mw->middleware);
    }

    public function test_route_attribute_with_middleware_param(): void
    {
        $route = new Route('/api', middleware: ['AuthMiddleware', 'RateLimitMiddleware']);
        $this->assertSame(['AuthMiddleware', 'RateLimitMiddleware'], $route->middleware);
    }
}
