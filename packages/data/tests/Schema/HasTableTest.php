<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** DataManager::hasTable(): an SQL table, a JSON type's directory. */
final class HasTableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/has_table_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private static function def(string $storage): TypeDefinition
    {
        return new TypeDefinition(key: 'item', table: 'item', storage: $storage, fields: ['title' => new TypeFieldDefinition('title', 'string')]);
    }

    public function test_sql_table_once_created(): void
    {
        $dm = new DataManager(['sqlite' => new SqliteDriver(new \PDO('sqlite::memory:'))]);

        $this->assertFalse($dm->hasTable(self::def('sqlite')));
        (new RecordTableSync($dm))->sync(self::def('sqlite'));
        $this->assertTrue($dm->hasTable(self::def('sqlite')));
    }

    public function test_json_type_once_a_record_was_written_and_after_it_is_gone(): void
    {
        $dm = new DataManager(['json' => new JsonFileDriver($this->dir . '/data')]);

        $this->assertFalse($dm->hasTable(self::def('json')));
        $dm->saveType(DynamicRecord::fromArray(self::def('json'), ['uuid' => 'a', 'title' => 'A']), false);
        $this->assertTrue($dm->hasTable(self::def('json')));
        $dm->deleteIn(self::def('json'), 'a');
        $this->assertTrue($dm->hasTable(self::def('json')), 'an empty type keeps its directory');
    }
}
