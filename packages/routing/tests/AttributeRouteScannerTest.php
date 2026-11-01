<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\AttributeRouteScanner;
use Preflow\Routing\Attributes\Route;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Core\Http\Csrf\CsrfExempt;

#[Route('/api/posts')]
class TestApiController
{
    #[Get('/')]
    public function index(): void {}

    #[Get('/{id}')]
    public function show(): void {}

    #[Post('/')]
    #[Middleware('AdminMiddleware')]
    public function store(): void {}

    #[Delete('/{id}')]
    public function destroy(): void {}
}

#[Route('/admin', middleware: ['AuthMiddleware'])]
class TestAdminController
{
    #[Get('/dashboard')]
    public function dashboard(): void {}
}

class NoRouteController
{
    public function index(): void {}
}

#[Route('/img')]
class CatchAllController
{
    #[Get('/{preset}/{path...}')]
    public function serve(): void {}
}

#[Route('/hooks')]
#[CsrfExempt('Signed webhook')]
class ExemptClassController
{
    #[Post('/a')]
    public function a(): void {}

    #[Post('/b')]
    public function b(): void {}
}

#[Route('/mixed')]
class ExemptMethodController
{
    #[Post('/open')]
    #[CsrfExempt('Third-party callback')]
    public function open(): void {}

    #[Post('/closed')]
    public function closed(): void {}
}

#[Route('/bad')]
class ExemptNoReasonController
{
    #[Post('/x')]
    #[CsrfExempt('  ')]
    public function x(): void {}
}

final class AttributeRouteScannerTest extends TestCase
{
    public function test_scans_controller_with_route_attribute(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $this->assertCount(4, $entries);
    }

    public function test_combines_class_prefix_with_method_path(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $patterns = array_map(fn ($e) => $e->pattern, $entries);
        $this->assertContains('/api/posts', $patterns);
        $this->assertContains('/api/posts/{id}', $patterns);
    }

    public function test_method_attribute_determines_http_method(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $getRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'GET'));
        $postRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'POST'));
        $deleteRoutes = array_values(array_filter($entries, fn ($e) => $e->method === 'DELETE'));

        $this->assertCount(2, $getRoutes);
        $this->assertCount(1, $postRoutes);
        $this->assertCount(1, $deleteRoutes);
    }

    public function test_handler_format_is_class_at_method(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $indexEntry = array_values(array_filter($entries, fn ($e) => $e->pattern === '/api/posts' && $e->method === 'GET'))[0];
        $this->assertSame(TestApiController::class . '@index', $indexEntry->handler);
    }

    public function test_all_entries_are_action_mode(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        foreach ($entries as $entry) {
            $this->assertSame(RouteMode::Action, $entry->mode);
        }
    }

    public function test_class_middleware_applied_to_all_methods(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestAdminController::class);

        $this->assertCount(1, $entries);
        $this->assertContains('AuthMiddleware', $entries[0]->middleware);
    }

    public function test_method_middleware_merged_with_class_middleware(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $storeEntry = array_values(array_filter($entries, fn ($e) => $e->method === 'POST'))[0];
        $this->assertContains('AdminMiddleware', $storeEntry->middleware);
    }

    public function test_dynamic_params_extracted(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(TestApiController::class);

        $showEntry = array_values(array_filter(
            $entries,
            fn ($e) => $e->pattern === '/api/posts/{id}' && $e->method === 'GET'
        ))[0];

        $this->assertSame(['id'], $showEntry->paramNames);
        $this->assertStringContainsString('(?P<id>[^/]+)', $showEntry->regex);
    }

    public function test_class_without_route_attribute_returns_empty(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(NoRouteController::class);

        $this->assertCount(0, $entries);
    }

    public function test_catch_all_param_with_ellipsis(): void
    {
        $scanner = new AttributeRouteScanner();
        $entries = $scanner->scanClass(CatchAllController::class);

        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertContains('preset', $entry->paramNames);
        $this->assertContains('path', $entry->paramNames);
        $this->assertTrue($entry->isCatchAll);

        // Should match nested paths
        $this->assertMatchesRegularExpression($entry->regex, '/img/cover-thumb/games/cuvee/cover.jpg');
        preg_match($entry->regex, '/img/cover-thumb/games/cuvee/cover.jpg', $matches);
        $this->assertSame('cover-thumb', $matches['preset']);
        $this->assertSame('games/cuvee/cover.jpg', $matches['path']);
    }

    public function test_csrf_exempt_on_class_marks_all_routes(): void
    {
        $entries = (new AttributeRouteScanner())->scanClass(ExemptClassController::class);

        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertTrue($entry->csrfExempt);
        }
    }

    public function test_csrf_exempt_on_method_marks_that_route_only(): void
    {
        $entries = (new AttributeRouteScanner())->scanClass(ExemptMethodController::class);
        $byPattern = [];
        foreach ($entries as $entry) {
            $byPattern[$entry->pattern] = $entry->csrfExempt;
        }

        $this->assertTrue($byPattern['/mixed/open']);
        $this->assertFalse($byPattern['/mixed/closed']);
    }

    public function test_csrf_exempt_requires_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AttributeRouteScanner())->scanClass(ExemptNoReasonController::class);
    }
}
