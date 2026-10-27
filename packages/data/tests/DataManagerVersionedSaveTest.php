<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;

final class DataManagerVersionedSaveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_dm_cas_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
        file_put_contents($this->dir . '/models/note.json', json_encode([
            'key' => 'note',
            'table' => 'notes',
            'storage' => 'default',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{models/*,*}', GLOB_BRACE) ?: [] as $file) {
            is_dir($file) ? null : unlink($file);
        }
        @rmdir($this->dir . '/models');
        @rmdir($this->dir);
    }

    private function sqliteDm(): DataManager
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS notes (uuid TEXT PRIMARY KEY, title TEXT, _version TEXT)');

        return new DataManager(['default' => new SqliteDriver($pdo)], typeRegistry: new TypeRegistry($this->dir . '/models'));
    }

    private function note(DataManager $dm, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => 'n1'] + $data);
    }

    public function test_saves_with_the_next_version_when_the_stored_one_matches(): void
    {
        $dm = $this->sqliteDm();
        $dm->saveType($this->note($dm, ['title' => 'A', '_version' => 3]));

        $record = $this->note($dm, ['title' => 'B', '_version' => 3]);
        $this->assertTrue($dm->saveTypeIfVersion($record, 3));

        $this->assertSame(4, $record->get('_version'));
        $stored = $dm->findType('note', 'n1');
        $this->assertSame('B', $stored->get('title'));
        $this->assertSame(4, (int) $stored->get('_version'));
    }

    public function test_a_stale_version_saves_nothing_and_leaves_the_record_as_it_was(): void
    {
        $first = $this->sqliteDm();
        $second = $this->sqliteDm(); // another connection, as another request would have
        $first->saveType($this->note($first, ['title' => 'A', '_version' => 1]));

        $mine = $first->findType('note', 'n1');
        $theirs = $second->findType('note', 'n1');
        $mine->set('title', 'Mine');
        $theirs->set('title', 'Theirs');

        $this->assertTrue($first->saveTypeIfVersion($mine, 1));
        $this->assertFalse($second->saveTypeIfVersion($theirs, 1));

        $this->assertSame(1, (int) $theirs->get('_version'));
        $this->assertSame('Mine', $second->findType('note', 'n1')->get('title'));
    }

    public function test_next_and_version_field_can_be_given(): void
    {
        $dm = $this->sqliteDm();
        $dm->saveType($this->note($dm, ['title' => 'A', '_version' => 7]));

        $record = $this->note($dm, ['title' => 'A', '_version' => 7]);
        $this->assertTrue($dm->saveTypeIfVersion($record, 7, next: 7)); // a write that keeps the version
        $this->assertSame(7, (int) $dm->findType('note', 'n1')->get('_version'));
    }

    public function test_a_driver_without_versioned_writes_gets_a_read_compare_write(): void
    {
        $dm = new DataManager(['default' => self::plainDriver()], typeRegistry: new TypeRegistry($this->dir . '/models'));
        $dm->saveType($this->note($dm, ['title' => 'A', '_version' => 2]));

        $notices = $this->collectingNotices(function () use ($dm): void {
            $this->assertFalse($dm->saveTypeIfVersion($this->note($dm, ['title' => 'X']), 1));
            $this->assertTrue($dm->saveTypeIfVersion($this->note($dm, ['title' => 'B']), 2));
        });
        $this->assertSame('B', $dm->findType('note', 'n1')->get('title'));
        $this->assertSame(3, (int) $dm->findType('note', 'n1')->get('_version'));
        $this->assertCount(1, $notices, 'once per driver class');
        $this->assertStringContainsString('VersionedStorage', $notices[0]);
    }

    public function test_a_failed_save_removes_a_version_the_record_did_not_have(): void
    {
        $dm = $this->sqliteDm();
        $dm->saveType($this->note($dm, ['title' => 'A', '_version' => 2]));

        $record = $this->note($dm, ['title' => 'X']);
        $this->assertFalse($dm->saveTypeIfVersion($record, 1));

        $this->assertArrayNotHasKey('_version', $record->toArray());
    }

    /** @return list<string> the E_USER_NOTICE messages $run raised */
    private function collectingNotices(\Closure $run): array
    {
        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_NOTICE);
        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $notices;
    }

    private static function plainDriver(): StorageDriver
    {
        $inner = new SqliteDriver(new \PDO('sqlite::memory:'));
        $inner->pdo()->exec('CREATE TABLE notes (uuid TEXT PRIMARY KEY, title TEXT, _version TEXT)');

        return new class ($inner) implements StorageDriver {
            public function __construct(private readonly StorageDriver $inner) {}
            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return $this->inner->findOne($type, $id, $idField); }
            public function findMany(string $type, Query $query): ResultSet { return $this->inner->findMany($type, $query); }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
            public function rawQuery(string $sql, array $bindings = []): array { return $this->inner->rawQuery($sql, $bindings); }
        };
    }
}
