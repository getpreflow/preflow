<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Driver\SqliteDriver;

final class DataManagerDynamicTest extends TestCase
{
    private DataManager $dm;
    private string $tmpDir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE events (
            uuid TEXT PRIMARY KEY,
            title TEXT,
            capacity INTEGER
        )');

        $driver = new SqliteDriver($this->pdo);

        $this->tmpDir = sys_get_temp_dir() . '/preflow_dm_dynamic_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        file_put_contents($this->tmpDir . '/event.json', json_encode([
            'key' => 'event',
            'table' => 'events',
            'storage' => 'sqlite',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true],
                'capacity' => ['type' => 'integer'],
            ],
        ]));

        $registry = new TypeRegistry($this->tmpDir);

        $this->dm = new DataManager(
            drivers: ['sqlite' => $driver, 'default' => $driver],
            typeRegistry: $registry,
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json'));
        rmdir($this->tmpDir);
    }

    public function test_save_type_and_find_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');
        $record = new DynamicRecord($type, [
            'uuid' => 'evt-1',
            'title' => 'PHP Meetup',
            'capacity' => 50,
        ]);

        $this->dm->saveType($record);

        $found = $this->dm->findType('event', 'evt-1');
        $this->assertInstanceOf(DynamicRecord::class, $found);
        $this->assertSame('PHP Meetup', $found->get('title'));
    }

    public function test_query_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');

        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e1', 'title' => 'A', 'capacity' => 10]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e2', 'title' => 'B', 'capacity' => 20]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e3', 'title' => 'C', 'capacity' => 30]));

        $results = $this->dm->queryType('event')
            ->where('capacity', '>', 15)
            ->orderBy('title')
            ->get();

        $this->assertCount(2, $results);
        $items = $results->items();
        $this->assertInstanceOf(DynamicRecord::class, $items[0]);
        $this->assertSame('B', $items[0]->get('title'));
    }

    public function test_query_type_search(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');

        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e1', 'title' => 'PHP Meetup', 'capacity' => 10]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e2', 'title' => 'JS Conference', 'capacity' => 20]));

        $results = $this->dm->queryType('event')->search('PHP')->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Meetup', $results->first()->get('title'));
    }

    public function test_delete_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'del-1', 'title' => 'Gone', 'capacity' => 0]));

        $this->dm->deleteType('event', 'del-1');

        $this->assertNull($this->dm->findType('event', 'del-1'));
    }

    public function test_find_type_returns_null_for_missing(): void
    {
        $this->assertNull($this->dm->findType('event', 'nonexistent'));
    }
}
