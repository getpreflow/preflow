<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Activity;

require_once __DIR__ . '/ActivityLogScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Data\Activity\StoredActivityLog;
use Preflow\Data\DataManager;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;

/** The activity log on JSON files and SQLite (MySQL: StoredActivityLogMysqlTest). */
final class StoredActivityLogTest extends TestCase
{
    use ActivityLogScenarios;

    private string $dir = '';

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    protected function dataManager(string $storage, bool $fresh = true): DataManager
    {
        if ($fresh || $this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/activity_' . bin2hex(random_bytes(4));
            mkdir($this->dir, 0777, true);
        }
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new DataManager(['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')], $storage);
    }

    /** people() on a storage that cannot aggregate: read in batches, same answer. */
    public function test_people_on_a_storage_without_aggregates(): void
    {
        $this->dir = sys_get_temp_dir() . '/activity_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $plain = new PlainDriver(new JsonFileDriver($this->dir . '/store'));
        $dm = new DataManager(['plain' => $plain], 'plain');
        $at = new \DateTimeImmutable('2026-11-15T10:00:00+00:00');
        $log = new StoredActivityLog($dm, 'plain', clock: static function () use (&$at): \DateTimeImmutable {
            return $at = $at->modify('+1 second');
        });
        $log->record(new Activity('page', 'a', 'a', ActivityKind::Created, by: 'u1'));
        $log->record(new Activity('post', 'b', 'b', ActivityKind::Created, by: 'u2'));
        $log->record(new Activity('page', 'c', 'c', ActivityKind::Created));
        $log->record(new Activity('page', 'd', 'd', ActivityKind::Created, by: 'u1'));

        $this->assertNotInstanceOf(\Preflow\Data\AggregatingStorage::class, $plain);
        $this->assertSame(['u1', 'u2'], $log->people());
        $this->assertSame(['u2'], $log->people(['post']));
    }

    /** On storage with change tokens (JSON with a cache directory), a second people() without a write reads nothing. */
    public function test_people_is_reused_until_the_log_is_written(): void
    {
        $this->dir = sys_get_temp_dir() . '/activity_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $json = fn (): JsonFileDriver => new JsonFileDriver($this->dir . '/store', $this->dir . '/cache');
        $counting = new CountingDriver($json());
        $dm = new DataManager(['json' => $counting], 'json');
        $at = new \DateTimeImmutable('2026-11-15T10:00:00+00:00');
        $clock = static function () use (&$at): \DateTimeImmutable {
            return $at = $at->modify('+1 second');
        };
        $log = new StoredActivityLog($dm, 'json', clock: $clock);
        $log->record(new Activity('page', 'a', 'a', ActivityKind::Created, by: 'u1'));
        $log->record(new Activity('post', 'b', 'b', ActivityKind::Created, by: 'u2'));
        $reads = new ReadCounter();
        $dm->observe($reads);

        $this->assertSame(['u2', 'u1'], $log->people());
        $this->assertSame(['u2'], $log->people(['post']));
        $first = $reads->reads;
        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $counting->queries);
        $reads->reads = 0;
        $counting->queries = 0;
        $this->assertSame(['u2', 'u1'], $log->people());
        $this->assertSame(['u2'], $log->people(['post']));
        $this->assertSame(0, $counting->queries, 'nothing read from the storage again');
        $this->assertSame($first, $reads->reads, 'the reads are reported again (a page depending on them)');

        $log->record(new Activity('page', 'c', 'c', ActivityKind::Created, by: 'u3'));
        $this->assertSame(['u3', 'u2', 'u1'], $log->people(), 'a write is seen');
        // A write by another process (another log on the same files) is seen too.
        (new StoredActivityLog(new DataManager(['json' => $json()], 'json'), 'json', clock: $clock))
            ->record(new Activity('post', 'd', 'd', ActivityKind::Created, by: 'u4'));
        $this->assertSame(['u4', 'u3', 'u2', 'u1'], $log->people());
        $this->assertSame(['u4', 'u2'], $log->people(['post']));
        $log->forget('member', 'u4');
        $this->assertSame(['u3', 'u2', 'u1'], $log->people(), 'an erased person is gone at once');
    }

    protected function tearDown(): void
    {
        if ($this->dir === '') {
            return;
        }
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (in_array(basename($f), ['.', '..'], true)) {
                    continue;
                }
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }
}

/** A storage with the plain driver contract only (no aggregates, no exclusive writes). */
final class PlainDriver implements StorageDriver
{
    public function __construct(private readonly JsonFileDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }
}

/** Counts the reads a DataManager reports. */
final class ReadCounter implements \Preflow\Data\Observe\DataObserver
{
    public int $reads = 0;

    public function read(string $storage, string $type, ?string $id): void
    {
        $this->reads++;
    }

    public function wrote(string $storage, string $type, ?string $id): void {}

    public function rawRead(string $storage, string $sql): void {}

    public function rawWrote(string $storage, string $sql): void {}
}

/** A JSON driver that counts its listing queries (findMany, aggregate); it aggregates and gives change tokens. */
final class CountingDriver implements StorageDriver, \Preflow\Data\AggregatingStorage, \Preflow\Data\ChangeTokens
{
    public int $queries = 0;

    public function __construct(private readonly JsonFileDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $this->queries++;

        return $this->inner->findMany($type, $query);
    }

    public function aggregate(\Preflow\Data\AggregateQuery $query): array
    {
        $this->queries++;

        return $this->inner->aggregate($query);
    }

    public function changeToken(string $type): ?string
    {
        return $this->inner->changeToken($type);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }
}
