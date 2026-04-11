<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Debug;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;

final class DebugCollectorTest extends TestCase
{
    private DebugCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DebugCollector();
    }

    public function test_log_query(): void
    {
        $this->collector->logQuery('SELECT * FROM posts', [], 2.5, 'sqlite');
        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('SELECT * FROM posts', $queries[0]['sql']);
        $this->assertSame(2.5, $queries[0]['duration_ms']);
        $this->assertSame('sqlite', $queries[0]['driver']);
    }

    public function test_log_multiple_queries(): void
    {
        $this->collector->logQuery('SELECT 1', [], 1.0);
        $this->collector->logQuery('SELECT 2', [], 2.0);
        $this->collector->logQuery('SELECT 3', [], 3.0);
        $this->assertCount(3, $this->collector->getQueries());
    }

    public function test_get_query_time(): void
    {
        $this->collector->logQuery('SELECT 1', [], 1.5);
        $this->collector->logQuery('SELECT 2', [], 2.5);
        $this->assertSame(4.0, $this->collector->getQueryTime());
    }

    public function test_log_component(): void
    {
        $this->collector->logComponent('App\\Counter', 'Counter-abc', 3.2, ['count' => 0]);
        $components = $this->collector->getComponents();
        $this->assertCount(1, $components);
        $this->assertSame('App\\Counter', $components[0]['class']);
        $this->assertSame('Counter-abc', $components[0]['id']);
        $this->assertSame(3.2, $components[0]['duration_ms']);
        $this->assertSame(['count' => 0], $components[0]['props']);
    }

    public function test_set_route(): void
    {
        $this->collector->setRoute('component', 'pages/blog/index.twig', ['slug' => 'hello']);
        $route = $this->collector->getRoute();
        $this->assertSame('component', $route['mode']);
        $this->assertSame('pages/blog/index.twig', $route['handler']);
        $this->assertSame(['slug' => 'hello'], $route['parameters']);
    }

    public function test_route_defaults_to_null(): void
    {
        $this->assertNull($this->collector->getRoute());
    }

    public function test_set_assets(): void
    {
        $this->collector->setAssets(3, 2, 2100, 1400);
        $assets = $this->collector->getAssets();
        $this->assertSame(3, $assets['css_count']);
        $this->assertSame(2, $assets['js_count']);
        $this->assertSame(2100, $assets['css_bytes']);
        $this->assertSame(1400, $assets['js_bytes']);
    }

    public function test_get_total_time_is_positive(): void
    {
        usleep(1000);
        $time = $this->collector->getTotalTime();
        $this->assertGreaterThan(0.0, $time);
    }

    public function test_empty_state(): void
    {
        $this->assertSame([], $this->collector->getQueries());
        $this->assertSame([], $this->collector->getComponents());
        $this->assertNull($this->collector->getRoute());
        $this->assertSame(0.0, $this->collector->getQueryTime());
    }
}
