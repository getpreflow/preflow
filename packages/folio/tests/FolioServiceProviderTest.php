<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class FolioServiceProviderTest extends TestCase
{
    public function test_boot_appends_admin_and_frontend_routes(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);

        // A real router whose collection we can inspect.
        $router = new Router(pagesDir: null, controllers: []);
        $app->setRouter($router);
        $app->setActionDispatcher(fn ($route, $req) => new \Nyholm\Psr7\Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new \Nyholm\Psr7\Response(200));

        $provider = new FolioServiceProvider();
        $app->registerProvider($provider);
        $app->boot();

        $patterns = array_map(
            fn ($e) => $e->method . ' ' . $e->pattern,
            $router->getCollection()->all(),
        );

        $this->assertContains('GET /folio', $patterns);
        $this->assertContains('POST /folio/{type}', $patterns);
        $this->assertContains('GET /{...path}', $patterns); // frontend catch-all

        // Frontend catch-all must be the LAST entry (lowest priority).
        $last = end($patterns);
        $this->assertSame('GET /{...path}', $last);
    }
}
