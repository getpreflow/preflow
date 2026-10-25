<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

final class RecordTableSyncTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rtsync_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->pdo = new \PDO('sqlite:' . $this->dir . '/test.sqlite');
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_creates_a_missing_table(): void
    {
        $lines = $this->sync()->sync($this->def(['title', 'body']));

        $this->assertSame(['created table note'], $lines);
        $this->assertSame(['uuid', 'title', 'body'], (new Schema($this->pdo))->columns('note'));
    }

    public function test_second_run_changes_nothing(): void
    {
        $sync = $this->sync();
        $sync->sync($this->def(['title']));

        $this->assertSame([], $sync->sync($this->def(['title'])));
    }

    public function test_adds_a_new_field_as_column(): void
    {
        $sync = $this->sync();
        $sync->sync($this->def(['title']));

        $this->assertSame(['added column note.body'], $sync->sync($this->def(['title', 'body'])));
        $this->assertSame(['uuid', 'title', 'body'], (new Schema($this->pdo))->columns('note'));
    }

    public function test_extra_columns_are_created_too(): void
    {
        $lines = $this->sync()->sync($this->def(['title']), ['_order']);

        $this->assertSame(['created table note'], $lines);
        $this->assertSame(['uuid', 'title', '_order'], (new Schema($this->pdo))->columns('note'));
    }

    public function test_json_stored_definition_is_left_alone(): void
    {
        $this->assertSame([], $this->sync()->sync($this->def(['title'], 'json')));
        $this->assertFalse((new Schema($this->pdo))->hasTable('note'));
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync(new DataManager(
            drivers: ['sqlite' => new SqliteDriver($this->pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: new TypeRegistry($this->dir . '/models'),
        ));
    }

    /** @param list<string> $fields */
    private function def(array $fields, string $storage = 'sqlite'): TypeDefinition
    {
        $defs = [];
        foreach ($fields as $name) {
            $defs[$name] = new TypeFieldDefinition($name);
        }

        return new TypeDefinition('note', 'note', $storage, $defs);
    }
}
