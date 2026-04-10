<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\FileRouteScanner;

final class FileRouteScannerTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_test_pages_' . uniqid();
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

    private function createFile(string $relativePath): void
    {
        $path = $this->pagesDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, '');
    }

    public function test_index_file_maps_to_root(): void
    {
        $this->createFile('index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/', $entries[0]->pattern);
        $this->assertSame('index.twig', $entries[0]->handler);
        $this->assertSame(RouteMode::Component, $entries[0]->mode);
        $this->assertSame('GET', $entries[0]->method);
    }

    public function test_simple_page(): void
    {
        $this->createFile('about.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/about', $entries[0]->pattern);
        $this->assertSame('about.twig', $entries[0]->handler);
    }

    public function test_nested_directory_with_index(): void
    {
        $this->createFile('blog/index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/blog', $entries[0]->pattern);
        $this->assertSame('blog/index.twig', $entries[0]->handler);
    }

    public function test_dynamic_segment(): void
    {
        $this->createFile('blog/[slug].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/blog/{slug}', $entries[0]->pattern);
        $this->assertSame(['slug'], $entries[0]->paramNames);
        $this->assertStringContainsString('(?P<slug>[^/]+)', $entries[0]->regex);
    }

    public function test_dynamic_directory_with_index(): void
    {
        $this->createFile('games/[category]/index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/games/{category}', $entries[0]->pattern);
        $this->assertSame(['category'], $entries[0]->paramNames);
    }

    public function test_multiple_dynamic_segments(): void
    {
        $this->createFile('blog/[slug]/comments.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertSame('/blog/{slug}/comments', $entries[0]->pattern);
        $this->assertSame(['slug'], $entries[0]->paramNames);
    }

    public function test_catch_all_segment(): void
    {
        $this->createFile('games/[...path].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/games/{path}', $entries[0]->pattern);
        $this->assertTrue($entries[0]->isCatchAll);
        $this->assertSame(['path'], $entries[0]->paramNames);
        $this->assertStringContainsString('(?P<path>.+)', $entries[0]->regex);
    }

    public function test_underscore_files_are_excluded(): void
    {
        $this->createFile('_layout.twig');
        $this->createFile('_error.twig');
        $this->createFile('index.twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertSame('/', $entries[0]->pattern);
    }

    public function test_non_twig_files_are_excluded(): void
    {
        $this->createFile('index.twig');
        $this->createFile('index.php'); // co-located PHP is not a route

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(1, $entries);
    }

    public function test_multiple_routes_discovered(): void
    {
        $this->createFile('index.twig');
        $this->createFile('about.twig');
        $this->createFile('blog/index.twig');
        $this->createFile('blog/[slug].twig');

        $scanner = new FileRouteScanner($this->pagesDir);
        $entries = $scanner->scan();

        $this->assertCount(4, $entries);

        $patterns = array_map(fn ($e) => $e->pattern, $entries);
        $this->assertContains('/', $patterns);
        $this->assertContains('/about', $patterns);
        $this->assertContains('/blog', $patterns);
        $this->assertContains('/blog/{slug}', $patterns);
    }
}
