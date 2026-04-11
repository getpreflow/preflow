<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\DebugToolbar;

final class DebugToolbarTest extends TestCase
{
    public function test_renders_html_string(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertIsString($html);
        $this->assertStringContainsString('preflow-debug-toolbar', $html);
    }

    public function test_shows_query_count(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT 1', [], 1.0);
        $collector->logQuery('SELECT 2', [], 2.0);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('2 queries', $html);
    }

    public function test_shows_component_count(): void
    {
        $collector = new DebugCollector();
        $collector->logComponent('App\\Counter', 'Counter-1', 3.0);
        $collector->logComponent('App\\Nav', 'Nav-1', 1.0);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('2 components', $html);
    }

    public function test_shows_query_time(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT 1', [], 2.5);
        $collector->logQuery('SELECT 2', [], 3.5);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('6.0ms', $html);
    }

    public function test_shows_asset_stats(): void
    {
        $collector = new DebugCollector();
        $collector->setAssets(3, 2, 2100, 1400);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('3 CSS', $html);
        $this->assertStringContainsString('2 JS', $html);
    }

    public function test_shows_route_info(): void
    {
        $collector = new DebugCollector();
        $collector->setRoute('component', 'pages/blog.twig', ['slug' => 'hello']);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('pages/blog.twig', $html);
    }

    public function test_contains_toggle_script(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('toggle', $html);
    }

    public function test_flags_slow_queries(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT slow', [], 150.0);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('slow', $html);
    }

    public function test_flags_slow_components(): void
    {
        $collector = new DebugCollector();
        $collector->logComponent('App\\Slow', 'Slow-1', 75.0);
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('slow', $html);
    }

    public function test_empty_collector_renders_gracefully(): void
    {
        $collector = new DebugCollector();
        $toolbar = new DebugToolbar();
        $html = $toolbar->render($collector);
        $this->assertStringContainsString('0 queries', $html);
        $this->assertStringContainsString('0 components', $html);
    }
}
