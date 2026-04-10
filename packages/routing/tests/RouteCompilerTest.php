<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteCompiler;
use Preflow\Routing\RouteEntry;

final class RouteCompilerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/preflow_cache_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $cachePath = $this->cacheDir . '/routes.php';
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function test_compiles_to_php_file(): void
    {
        $collection = new RouteCollection();
        $collection->add(new RouteEntry(
            pattern: '/about',
            handler: 'about.twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: [],
            paramNames: [],
            regex: '#^/about$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($collection, $cachePath);

        $this->assertFileExists($cachePath);
    }

    public function test_cached_file_returns_array(): void
    {
        $collection = new RouteCollection();
        $collection->add(new RouteEntry(
            pattern: '/about',
            handler: 'about.twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: [],
            paramNames: [],
            regex: '#^/about$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($collection, $cachePath);

        $data = require $cachePath;
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('/about', $data[0]['pattern']);
    }

    public function test_round_trip_preserves_routes(): void
    {
        $original = new RouteCollection();
        $original->add(new RouteEntry(
            pattern: '/blog/{slug}',
            handler: 'blog/[slug].twig',
            method: 'GET',
            mode: RouteMode::Component,
            middleware: ['AuthMiddleware'],
            paramNames: ['slug'],
            regex: '#^/blog/(?P<slug>[^/]+)$#',
        ));
        $original->add(new RouteEntry(
            pattern: '/api/posts',
            handler: 'PostController@index',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: [],
            regex: '#^/api/posts$#',
        ));

        $cachePath = $this->cacheDir . '/routes.php';
        $compiler = new RouteCompiler();
        $compiler->compile($original, $cachePath);

        $data = require $cachePath;
        $restored = RouteCollection::fromArray($data);

        $this->assertSame(2, $restored->count());

        $all = $restored->all();
        $this->assertSame('/blog/{slug}', $all[0]->pattern);
        $this->assertSame(['slug'], $all[0]->paramNames);
        $this->assertSame(RouteMode::Component, $all[0]->mode);
        $this->assertSame(['AuthMiddleware'], $all[0]->middleware);

        $this->assertSame('/api/posts', $all[1]->pattern);
        $this->assertSame(RouteMode::Action, $all[1]->mode);
    }

    public function test_clear_removes_cache_file(): void
    {
        $cachePath = $this->cacheDir . '/routes.php';
        file_put_contents($cachePath, '<?php return [];');

        $compiler = new RouteCompiler();
        $compiler->clear($cachePath);

        $this->assertFileDoesNotExist($cachePath);
    }
}
