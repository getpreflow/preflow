<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Query;

final class PdoDriverDebugTest extends TestCase
{
    private \PDO $pdo;
    private DebugCollector $collector;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, name TEXT)');

        $this->collector = new DebugCollector();
        $this->driver = new SqliteDriver($this->pdo, $this->collector);
    }

    public function test_save_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);
        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('INSERT', $queries[0]['sql']);
        $this->assertGreaterThan(0, $queries[0]['duration_ms']);
    }

    public function test_find_one_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);
        $this->driver->findOne('items', 'id-1');
        $queries = $this->collector->getQueries();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('SELECT', $queries[1]['sql']);
    }

    public function test_find_many_logs_query(): void
    {
        $this->driver->findMany('items', new Query());
        $queries = $this->collector->getQueries();
        $this->assertGreaterThanOrEqual(1, count($queries));
        $this->assertStringContainsString('SELECT', $queries[0]['sql']);
    }

    public function test_delete_logs_query(): void
    {
        $this->driver->save('items', 'id-1', ['uuid' => 'id-1', 'name' => 'Test']);
        $this->driver->delete('items', 'id-1');
        $queries = $this->collector->getQueries();
        $this->assertGreaterThanOrEqual(2, count($queries));
        $this->assertStringContainsString('DELETE', end($queries)['sql']);
    }

    public function test_no_collector_no_logging(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $driver->save('items', 'id-2', ['uuid' => 'id-2', 'name' => 'Test']);
        $this->assertCount(0, $this->collector->getQueries());
    }
}
