<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\SortDirection;

#[Entity(table: 'items', storage: 'sqlite')]
class TestItem extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $name = '';

    #[Field]
    public string $status = 'draft';
}

#[Entity(table: 'settings', storage: 'json')]
class TestSetting extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $key = '';

    #[Field]
    public string $value = '';
}

#[Entity(table: 'counters', storage: 'sqlite')]
class TestCounter extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $label = '';
}

final class DataManagerTest extends TestCase
{
    private \PDO $pdo;
    private string $jsonDir;
    private DataManager $manager;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, name TEXT, status TEXT DEFAULT "draft")');
        $this->pdo->exec('CREATE TABLE counters (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        $this->jsonDir = sys_get_temp_dir() . '/preflow_dm_test_' . uniqid();
        mkdir($this->jsonDir, 0755, true);

        $this->manager = new DataManager([
            'sqlite' => new SqliteDriver($this->pdo),
            'json' => new JsonFileDriver($this->jsonDir),
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->jsonDir);
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

    public function test_save_and_find_typed_model(): void
    {
        $item = new TestItem();
        $item->uuid = 'item-1';
        $item->name = 'Widget';
        $item->status = 'active';

        $this->manager->save($item);

        $found = $this->manager->find(TestItem::class, 'item-1');

        $this->assertNotNull($found);
        $this->assertInstanceOf(TestItem::class, $found);
        $this->assertSame('Widget', $found->name);
        $this->assertSame('active', $found->status);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $found = $this->manager->find(TestItem::class, 'nonexistent');

        $this->assertNull($found);
    }

    public function test_query_with_where(): void
    {
        $this->saveItem('1', 'Alpha', 'active');
        $this->saveItem('2', 'Beta', 'draft');
        $this->saveItem('3', 'Gamma', 'active');

        $result = $this->manager->query(TestItem::class)
            ->where('status', 'active')
            ->get();

        $this->assertSame(2, $result->total());
    }

    public function test_query_with_order(): void
    {
        $this->saveItem('1', 'Banana');
        $this->saveItem('2', 'Apple');
        $this->saveItem('3', 'Cherry');

        $result = $this->manager->query(TestItem::class)
            ->orderBy('name', SortDirection::Asc)
            ->get();

        $names = array_map(fn ($m) => $m->name, $result->items());
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $names);
    }

    public function test_query_first(): void
    {
        $this->saveItem('1', 'Only');

        $item = $this->manager->query(TestItem::class)->first();

        $this->assertInstanceOf(TestItem::class, $item);
        $this->assertSame('Only', $item->name);
    }

    public function test_delete(): void
    {
        $this->saveItem('1', 'ToDelete');

        $this->manager->delete(TestItem::class, '1');

        $this->assertNull($this->manager->find(TestItem::class, '1'));
    }

    public function test_delete_by_class_and_id(): void
    {
        $this->saveItem('99', 'ByClassAndId');

        $this->manager->delete(TestItem::class, '99');

        $this->assertNull($this->manager->find(TestItem::class, '99'));
    }

    public function test_delete_by_model_instance(): void
    {
        $item = new TestItem();
        $item->uuid = 'inst-1';
        $item->name = 'ByInstance';
        $this->manager->save($item);

        $this->assertNotNull($this->manager->find(TestItem::class, 'inst-1'));

        $this->manager->delete($item);

        $this->assertNull($this->manager->find(TestItem::class, 'inst-1'));
    }

    public function test_multi_storage(): void
    {
        // Save to sqlite
        $item = new TestItem();
        $item->uuid = 'item-1';
        $item->name = 'SQLite Item';
        $this->manager->save($item);

        // Save to json
        $setting = new TestSetting();
        $setting->uuid = 'set-1';
        $setting->key = 'site_name';
        $setting->value = 'Preflow';
        $this->manager->save($setting);

        // Each resolves to its own driver
        $foundItem = $this->manager->find(TestItem::class, 'item-1');
        $foundSetting = $this->manager->find(TestSetting::class, 'set-1');

        $this->assertSame('SQLite Item', $foundItem->name);
        $this->assertSame('Preflow', $foundSetting->value);
    }

    public function test_insert_assigns_auto_increment_id(): void
    {
        $counter = new TestCounter();
        $counter->label = 'First';

        $this->assertSame(0, $counter->id);

        $this->manager->insert($counter);

        $this->assertGreaterThan(0, $counter->id);
    }

    public function test_insert_creates_separate_records(): void
    {
        $a = new TestCounter();
        $a->label = 'A';

        $b = new TestCounter();
        $b->label = 'B';

        $this->manager->insert($a);
        $this->manager->insert($b);

        $this->assertGreaterThan(0, $a->id);
        $this->assertGreaterThan(0, $b->id);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_update_existing_model(): void
    {
        $item = new TestItem();
        $item->uuid = 'upd-1';
        $item->name = 'Original';
        $item->status = 'draft';
        $this->manager->save($item);

        $item->name = 'Updated';
        $item->status = 'active';
        $this->manager->update($item);

        $found = $this->manager->find(TestItem::class, 'upd-1');

        $this->assertNotNull($found);
        $this->assertSame('Updated', $found->name);
        $this->assertSame('active', $found->status);
    }

    public function test_update_throws_without_id(): void
    {
        $this->expectException(\RuntimeException::class);

        $counter = new TestCounter();
        $counter->label = 'No ID yet';

        $this->manager->update($counter);
    }

    private function saveItem(string $id, string $name, string $status = 'draft'): void
    {
        $item = new TestItem();
        $item->uuid = $id;
        $item->name = $name;
        $item->status = $status;
        $this->manager->save($item);
    }
}
