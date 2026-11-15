<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\VersionFields;

/**
 * VersionFields (@since 0.77): every save of a versioned type bumps its version; the checked writes
 * (saveTypeIfVersion(), updateFieldsIfVersion()) write what they are given; hasColumn() asks the
 * storage's schema. The same on JSON files and SQLite.
 */
final class VersionFieldsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') ?: [] as $fixture) {
            require_once $fixture;
        }
        $this->dir = sys_get_temp_dir() . '/preflow_version_fields_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
        file_put_contents($this->dir . '/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'notes', 'storage' => 'default', 'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['json' => ['json'], 'sqlite' => ['sqlite']];
    }

    private function dm(string $storage, bool $versioned = true, ?StorageDriver $driver = null): DataManager
    {
        if ($driver === null && $storage === 'sqlite') {
            $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE IF NOT EXISTS notes (uuid TEXT PRIMARY KEY, title TEXT, _version TEXT, _extra TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS job (uuid TEXT PRIMARY KEY, title TEXT, remote TEXT, company TEXT, logo TEXT, salary_from INTEGER, open INTEGER, _version TEXT, _extra TEXT)');
            $driver = new SqliteDriver($pdo);
        }
        $driver ??= new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], typeRegistry: new TypeRegistry($this->dir . '/models', null, ['job' => Job::class]));
        if ($versioned) {
            $dm->useVersionFields(new class implements VersionFields {
                public function of(TypeDefinition $def): ?string
                {
                    return in_array($def->table, ['notes', 'job'], true) ? '_version' : null;
                }
            });
        }

        return $dm;
    }

    private function note(DataManager $dm, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => 'n1'] + $data);
    }

    #[DataProvider('storages')]
    public function test_every_save_bumps_the_stored_version(string $storage): void
    {
        $dm = $this->dm($storage);
        $first = $this->note($dm, ['title' => 'A']);
        $dm->saveType($first, false);
        $this->assertSame(1, $first->get('_version'), 'the record holds the version written');

        $stale = $this->note($dm, ['title' => 'B', '_version' => 0]);
        $dm->saveType($stale, false);
        $this->assertSame(2, $stale->get('_version'), 'the stored version + 1, not the one the record held');
        $this->assertSame(2, (int) $dm->findType('note', 'n1')?->get('_version'));
        $this->assertSame('B', $dm->findType('note', 'n1')?->get('title'));
    }

    #[DataProvider('storages')]
    public function test_without_version_fields_a_save_writes_what_it_holds(string $storage): void
    {
        $dm = $this->dm($storage, versioned: false);
        $dm->saveType($this->note($dm, ['title' => 'A', '_version' => 5]), false);
        $dm->saveType($this->note($dm, ['title' => 'B', '_version' => 5]), false);

        $this->assertSame(5, (int) $dm->findType('note', 'n1')?->get('_version'));
    }

    #[DataProvider('storages')]
    public function test_checked_saves_write_the_version_given(string $storage): void
    {
        $dm = $this->dm($storage);
        $dm->saveType($this->note($dm, ['title' => 'A']), false);
        $kept = $this->note($dm, ['title' => 'A']);

        $this->assertTrue($dm->saveTypeIfVersion($kept, 1, 1, validate: false));
        $this->assertSame(1, (int) $dm->findType('note', 'n1')?->get('_version'), 'kept, not bumped');
    }

    #[DataProvider('storages')]
    public function test_a_class_backed_save_bumps_and_keeps_the_other_columns(string $storage): void
    {
        $dm = $this->dm($storage);
        $job = new Job();
        $job->uuid = 'j1';
        $job->title = 'Carpenter';
        $dm->save($job, false);
        $this->assertSame(1, (int) $dm->findType('job', 'j1')?->get('_version'));
        $this->assertTrue($dm->updateFieldsIfVersion($dm->getTypeRegistry()->get('job'), 'j1', ['_extra' => 'kept'], '_version', 1));

        $job->title = 'Joiner';
        $dm->save($job, false);

        $stored = $dm->findType('job', 'j1');
        $this->assertSame(2, (int) $stored?->get('_version'));
        $this->assertSame('Joiner', $stored?->get('title'));
        $this->assertSame('kept', $stored?->get('_extra'), 'a column the class does not declare stays');
    }

    #[DataProvider('storages')]
    public function test_update_fields_if_version_writes_only_those_fields(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('note');
        $dm->saveType($this->note($dm, ['title' => 'A']), false);

        $this->assertTrue($dm->updateFieldsIfVersion($def, 'n1', ['_extra' => 'x'], '_version', 1));
        $this->assertFalse($dm->updateFieldsIfVersion($def, 'n1', ['_extra' => 'y'], '_version', 0));
        $this->assertFalse($dm->updateFieldsIfVersion($def, 'n1', ['_extra' => 'y'], '_version', null), 'it has a version');
        $stored = $dm->findType('note', 'n1');
        $this->assertSame('x', $stored?->get('_extra'));
        $this->assertSame('A', $stored?->get('title'));
        $this->assertSame(1, (int) $stored?->get('_version'), 'nothing bumped');
    }

    /** A driver without ConditionalUpdates gets the same result from a read, compare and write (and a notice). */
    public function test_update_fields_if_version_on_a_plain_driver(): void
    {
        $json = new JsonFileDriver($this->dir . '/plain');
        $plain = new class ($json) implements StorageDriver {
            public function __construct(private JsonFileDriver $inner) {}
            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return $this->inner->findOne($type, $id, $idField); }
            public function findMany(string $type, Query $query): ResultSet { return $this->inner->findMany($type, $query); }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return ''; }
            public function rawQuery(string $sql, array $bindings = []): array { return []; }
        };
        $dm = $this->dm('json', driver: $plain);
        $def = $dm->getTypeRegistry()->get('note');
        $dm->saveType($this->note($dm, ['title' => 'A']), false);

        set_error_handler(static fn (): bool => true);
        try {
            $this->assertTrue($dm->updateFieldsIfVersion($def, 'n1', ['_extra' => 'x'], '_version', 1));
            $this->assertFalse($dm->updateFieldsIfVersion($def, 'n1', ['_extra' => 'y'], '_version', 2));
        } finally {
            restore_error_handler();
        }
        $this->assertSame('x', $dm->findType('note', 'n1')?->get('_extra'));
        $this->assertSame('A', $dm->findType('note', 'n1')?->get('title'));
    }

    public function test_has_column_asks_the_schema(): void
    {
        $sqlite = $this->dm('sqlite');
        $def = $sqlite->getTypeRegistry()->get('note');
        $this->assertTrue($sqlite->hasColumn($def, '_version'));
        $this->assertFalse($sqlite->hasColumn($def, '_published_version'));
        $sqlite->execute('ALTER TABLE notes ADD COLUMN _published_version TEXT', [], 'default');
        $this->assertTrue($sqlite->hasColumn($def, '_published_version'), 'a missing column is asked again');

        $this->assertTrue($this->dm('json')->hasColumn($def, 'anything'), 'JSON files take any key');
    }
}
