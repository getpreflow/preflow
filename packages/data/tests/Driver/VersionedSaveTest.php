<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\VersionedStorage;
use Preflow\Testing\MysqlTestDatabase;

/**
 * saveIfVersion() on every built-in driver, with two writers (two driver instances on two
 * connections, or two JSON drivers on one directory) against the same storage: the writer
 * holding a stale version is refused and the first writer's data survives.
 */
final class VersionedSaveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_cas_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
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

    public function test_built_in_drivers_are_versioned_storage(): void
    {
        $this->assertTrue(is_subclass_of(SqliteDriver::class, VersionedStorage::class));
        $this->assertTrue(is_subclass_of(MysqlDriver::class, VersionedStorage::class));
        $this->assertTrue(is_subclass_of(JsonFileDriver::class, VersionedStorage::class));
    }

    // --- SQLite: two PDO connections on one file ---

    /** @return array{SqliteDriver, SqliteDriver} */
    private function sqlitePair(): array
    {
        $path = $this->dir . '/db.sqlite';
        $make = static function () use ($path): SqliteDriver {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return new SqliteDriver($pdo);
        };
        $first = $make();
        $first->pdo()->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, body TEXT, _version TEXT)');

        return [$first, $make()];
    }

    public function test_sqlite_stale_writer_is_refused(): void
    {
        $this->checkStaleWriterIsRefused(...$this->sqlitePair());
    }

    public function test_sqlite_first_insert_wins(): void
    {
        $this->checkFirstInsertWins(...$this->sqlitePair());
    }

    public function test_sqlite_missing_version_counts_as_zero(): void
    {
        $this->checkMissingVersionCountsAsZero(...$this->sqlitePair());
    }

    public function test_sqlite_unchanged_write_keeping_the_version_succeeds(): void
    {
        $this->checkUnchangedWriteKeepingTheVersion(...$this->sqlitePair());
    }

    public function test_sqlite_missing_record_with_a_version_is_refused(): void
    {
        $this->checkMissingRecordWithAVersionIsRefused(...$this->sqlitePair());
    }

    public function test_sqlite_write_inside_an_open_transaction_stays_in_it(): void
    {
        [$a] = $this->sqlitePair();
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'A', '_version' => 1]);

        $a->pdo()->beginTransaction();
        $this->assertTrue($a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'B', '_version' => 2], '_version', 1));
        $this->assertTrue($a->pdo()->inTransaction());
        $a->pdo()->rollBack();

        $this->assertSame('A', $a->findOne('items', 'r1')['title']);
    }

    // --- JSON: two drivers on one directory ---

    /** @return array{JsonFileDriver, JsonFileDriver} */
    private function jsonPair(): array
    {
        return [new JsonFileDriver($this->dir . '/json'), new JsonFileDriver($this->dir . '/json', $this->dir . '/cache')];
    }

    public function test_json_stale_writer_is_refused(): void
    {
        $this->checkStaleWriterIsRefused(...$this->jsonPair());
    }

    public function test_json_first_insert_wins(): void
    {
        $this->checkFirstInsertWins(...$this->jsonPair());
    }

    public function test_json_missing_version_counts_as_zero(): void
    {
        $this->checkMissingVersionCountsAsZero(...$this->jsonPair());
    }

    public function test_json_unchanged_write_keeping_the_version_succeeds(): void
    {
        $this->checkUnchangedWriteKeepingTheVersion(...$this->jsonPair());
    }

    public function test_json_missing_record_with_a_version_is_refused(): void
    {
        $this->checkMissingRecordWithAVersionIsRefused(...$this->jsonPair());
    }

    public function test_json_lock_file_is_not_a_record(): void
    {
        [$a] = $this->jsonPair();
        $a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'A', '_version' => 1], '_version', 0);

        $this->assertCount(1, $a->findMany('items', new \Preflow\Data\Query())->items());
    }

    // --- MySQL: two connections, the second without FOUND_ROWS ---

    /**
     * @param \Closure(MysqlDriver, MysqlDriver): void $check
     */
    private function withMysqlPair(\Closure $check): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $pdo->exec('CREATE TABLE items (uuid VARCHAR(64) PRIMARY KEY, title TEXT NULL, body TEXT NULL, _version TEXT NULL)');
        $c = MysqlTestDatabase::dataConfig();
        $second = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], // no FOUND_ROWS: UPDATE reports changed rows only
        );

        try {
            $check(new MysqlDriver($pdo), new MysqlDriver($second));
        } finally {
            MysqlTestDatabase::dropAllTables($pdo);
        }
    }

    #[Group('mysql')]
    public function test_mysql_stale_writer_is_refused(): void
    {
        $this->withMysqlPair($this->checkStaleWriterIsRefused(...));
    }

    #[Group('mysql')]
    public function test_mysql_first_insert_wins(): void
    {
        $this->withMysqlPair($this->checkFirstInsertWins(...));
    }

    #[Group('mysql')]
    public function test_mysql_missing_version_counts_as_zero(): void
    {
        $this->withMysqlPair($this->checkMissingVersionCountsAsZero(...));
    }

    #[Group('mysql')]
    public function test_mysql_unchanged_write_keeping_the_version_succeeds(): void
    {
        $this->withMysqlPair(function (MysqlDriver $a, MysqlDriver $b): void {
            $this->checkUnchangedWriteKeepingTheVersion($a, $b);
            $this->checkUnchangedWriteKeepingTheVersion($b, $a, 'r2');
        });
    }

    #[Group('mysql')]
    public function test_mysql_missing_record_with_a_version_is_refused(): void
    {
        $this->withMysqlPair($this->checkMissingRecordWithAVersionIsRefused(...));
    }

    // --- the scenarios ---

    private function checkStaleWriterIsRefused(VersionedStorage $a, VersionedStorage $b): void
    {
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Start', 'body' => 'kept', '_version' => 1]);
        // Both writers loaded version 1.
        $this->assertTrue($a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'First', 'body' => 'kept', '_version' => 2], '_version', 1));
        $this->assertFalse($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Second', 'body' => 'lost', '_version' => 2], '_version', 1));

        $row = $b->findOne('items', 'r1');
        $this->assertSame('First', $row['title']);
        $this->assertSame('kept', $row['body']);
        $this->assertSame(2, (int) $row['_version']);

        // The refused writer, now holding version 2, gets through.
        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Second', 'body' => 'kept', '_version' => 3], '_version', 2));
        $this->assertSame('Second', $a->findOne('items', 'r1')['title']);
    }

    private function checkFirstInsertWins(VersionedStorage $a, VersionedStorage $b): void
    {
        $this->assertTrue($a->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => 'First', '_version' => 1], '_version', 0));
        $this->assertFalse($b->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => 'Second', '_version' => 1], '_version', 0));

        $this->assertSame('First', $b->findOne('items', 'new')['title']);
    }

    private function checkMissingVersionCountsAsZero(VersionedStorage $a, VersionedStorage $b): void
    {
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'No version yet']);

        $this->assertFalse($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Wrong', '_version' => 2], '_version', 1));
        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Versioned', '_version' => 1], '_version', 0));

        $row = $a->findOne('items', 'r1');
        $this->assertSame('Versioned', $row['title']);
        $this->assertSame(1, (int) $row['_version']);
    }

    private function checkUnchangedWriteKeepingTheVersion(VersionedStorage $a, VersionedStorage $b, string $id = 'r1'): void
    {
        $data = ['uuid' => $id, 'title' => 'Same', '_version' => 4];
        $a->save('items', $id, $data);

        // The same data with the same version: nothing changes, and that is not a conflict.
        $this->assertTrue($b->saveIfVersion('items', $id, $data, '_version', 4));
        $this->assertFalse($b->saveIfVersion('items', $id, $data, '_version', 3));
        $this->assertSame(4, (int) $a->findOne('items', $id)['_version']);
    }

    private function checkMissingRecordWithAVersionIsRefused(VersionedStorage $a, VersionedStorage $b): void
    {
        $this->assertFalse($a->saveIfVersion('items', 'gone', ['uuid' => 'gone', 'title' => 'X', '_version' => 3], '_version', 2));
        $this->assertNull($b->findOne('items', 'gone'));
    }
}
