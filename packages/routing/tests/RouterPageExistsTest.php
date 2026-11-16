<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\PageExists as CorePageExists;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\Router;

/** The Router answers core's PageExists: a GET route that is not a catch-all. */
final class RouterPageExistsTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_router_exists_' . bin2hex(random_bytes(4));
        foreach (['index.twig', 'about.twig', 'games/[id].twig', 'docs/[...path].twig'] as $page) {
            @mkdir(dirname($this->pagesDir . '/' . $page), 0755, true);
            file_put_contents($this->pagesDir . '/' . $page, '');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->pagesDir));
    }

    public function test_router_and_the_redirect_contract_are_core_page_exists(): void
    {
        $this->assertInstanceOf(CorePageExists::class, new Router(pagesDir: $this->pagesDir));
        $this->assertTrue(is_subclass_of(PageExists::class, CorePageExists::class), 'a bound Redirect\PageExists answers the core contract');
    }

    public function test_static_and_dynamic_routes_exist(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);

        foreach (['/', '/about', '/about/', '/games/7'] as $path) {
            $this->assertTrue($router->exists('de', $path), $path);
        }
    }

    public function test_catch_all_and_unknown_paths_do_not(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);

        foreach (['/nope', '/docs/a/b', '/games'] as $path) {
            $this->assertFalse($router->exists('en', $path), $path);
        }
    }

    public function test_routes_added_later_count(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);
        $this->assertFalse($router->exists('en', '/late'));

        $router->getCollection()->addMany([new RouteEntry(
            pattern: '/late',
            handler: 'late.twig',
            mode: \Preflow\Core\Routing\RouteMode::Component,
            method: 'GET',
        )]);

        $this->assertTrue($router->exists('en', '/late'));
    }
}
