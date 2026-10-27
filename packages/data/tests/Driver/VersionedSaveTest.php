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

require_once __DIR__ . '/HookedPdo.php';

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

    public function test_sqlite_empty_version_counts_as_zero(): void
    {
        $this->checkEmptyVersionCountsAsZero(...$this->sqlitePair());
    }

    public function test_sqlite_version_is_written_as_a_canonical_integer(): void
    {
        $this->checkVersionIsWrittenCanonical(...$this->sqlitePair());
    }

    /** SQL compares the stored text: only canonical integer strings match (JSON reads '05' as 5). */
    public function test_sqlite_non_canonical_stored_version_does_not_match(): void
    {
        [$a, $b] = $this->sqlitePair();
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'A', '_version' => '05']);

        $this->assertFalse($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'B', '_version' => 6], '_version', 5));
    }

    /**
     * WAL: a caller's transaction that read before another connection committed holds an old
     * snapshot, and SQLite refuses to write from it (SQLITE_BUSY_SNAPSHOT). That stays an
     * exception: the caller's transaction cannot write anything else either and must roll back.
     */
    public function test_sqlite_stale_snapshot_in_a_callers_transaction_throws_and_overwrites_nothing(): void
    {
        [$a, $b] = $this->sqlitePair();
        $a->pdo()->exec('PRAGMA journal_mode = WAL');
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Start', '_version' => 1]);

        $a->pdo()->beginTransaction();
        $a->findOne('items', 'r1'); // fixes the snapshot
        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Theirs', '_version' => 2], '_version', 1));
        try {
            $a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Mine', '_version' => 2], '_version', 1);
            $this->fail('A write from a stale WAL snapshot should throw.');
        } catch (\PDOException $e) {
            $this->assertMatchesRegularExpression('/locked|busy/i', $e->getMessage());
        } finally {
            $a->pdo()->rollBack();
        }

        $this->assertSame('Theirs', $a->findOne('items', 'r1')['title']);
    }

    /** A first save reads whether the row exists, then inserts: no claim (no gap lock on MySQL) before the INSERT. */
    public function test_a_first_save_of_a_missing_record_inserts_without_claiming_first(): void
    {
        $collector = new \Preflow\Core\Debug\DebugCollector();
        $pdo = new \PDO('sqlite::memory:');
        $driver = new SqliteDriver($pdo, $collector);
        $pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, body TEXT, _version TEXT)');

        $this->assertTrue($driver->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => 'A', '_version' => 1], '_version', 0));

        $verbs = array_map(static fn (array $q): string => strtok($q['sql'], ' '), $collector->getQueries());
        $this->assertSame(['SELECT', 'INSERT'], $verbs);
    }

    /** @return array{SqliteDriver, HookedPdo} */
    private function hookedSqlite(): array
    {
        $pdo = new HookedPdo('sqlite:' . $this->dir . '/hooked.sqlite');
        $driver = new SqliteDriver($pdo);
        $pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, body TEXT, _version TEXT)');

        return [$driver, $pdo];
    }

    /** @return iterable<string, array{string, int}> */
    public static function lostRaceErrors(): iterable
    {
        yield 'deadlock' => ['40001', 1213];
        yield 'MariaDB snapshot isolation' => ['HY000', 1020];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lostRaceErrors')]
    public function test_a_deadlock_in_its_own_transaction_is_a_lost_race(string $state, int $code): void
    {
        [$driver, $pdo] = $this->hookedSqlite();
        $pdo->beforePrepare = static function (string $sql) use ($state, $code): void {
            if (str_starts_with($sql, 'INSERT')) {
                throw self::pdoError($state, $code);
            }
        };

        $this->assertFalse($driver->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => 'A', '_version' => 1], '_version', 0));
        $this->assertFalse($pdo->inTransaction());
        $pdo->beforePrepare = null;
        $this->assertNull($driver->findOne('items', 'new'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lostRaceErrors')]
    public function test_a_deadlock_in_a_callers_transaction_is_rethrown(string $state, int $code): void
    {
        [$driver, $pdo] = $this->hookedSqlite();
        $pdo->beforePrepare = static function (string $sql) use ($state, $code): void {
            if (str_starts_with($sql, 'INSERT')) {
                throw self::pdoError($state, $code);
            }
        };

        $pdo->beginTransaction();
        try {
            $driver->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => 'A', '_version' => 1], '_version', 0);
            $this->fail('The caller has to learn that its transaction is gone.');
        } catch (\PDOException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
            $this->assertTrue($pdo->inTransaction(), 'left to the caller');
        } finally {
            $pdo->rollBack();
        }
    }

    private static function pdoError(string $state, int $code): \PDOException
    {
        $e = new \PDOException("SQLSTATE[{$state}]: {$code} simulated");
        $e->errorInfo = [$state, $code, 'simulated'];

        return $e;
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

    public function test_json_empty_version_counts_as_zero(): void
    {
        $this->checkEmptyVersionCountsAsZero(...$this->jsonPair());
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

    #[Group('mysql')]
    public function test_mysql_empty_version_counts_as_zero(): void
    {
        $this->withMysqlPair($this->checkEmptyVersionCountsAsZero(...));
    }

    #[Group('mysql')]
    public function test_mysql_version_is_written_as_a_canonical_integer(): void
    {
        $this->withMysqlPair($this->checkVersionIsWrittenCanonical(...));
    }

    /**
     * A caller's transaction under REPEATABLE READ: its first read fixes the snapshot, then
     * another connection commits a save. The claim (a current read) matches nothing; the
     * follow-up SELECT for a write keeping the version must lock (a current read too), or it
     * sees the old snapshot and the write overwrites the other save.
     *
     * MariaDB's innodb_snapshot_isolation (on by default since 11.6) refuses such a write by
     * itself; it is switched off here for MySQL's behaviour.
     */
    #[Group('mysql')]
    public function test_mysql_stale_snapshot_in_a_callers_transaction_is_refused(): void
    {
        $this->withMysqlPair(function (MysqlDriver $a, MysqlDriver $b): void {
            $this->withSnapshotIsolation($a->pdo(), 'OFF', function () use ($a, $b): void {
                foreach (['next version' => 2, 'version kept' => 1] as $case => $next) {
                    $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Start', '_version' => 1]);
                    $a->pdo()->beginTransaction();
                    try {
                        $a->findOne('items', 'r1'); // fixes the snapshot
                        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Theirs', '_version' => 2], '_version', 1));
                        $this->assertFalse(
                            $a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Mine', '_version' => $next], '_version', 1),
                            $case,
                        );
                    } finally {
                        $a->pdo()->rollBack();
                    }
                    $this->assertSame('Theirs', $b->findOne('items', 'r1')['title'], $case);
                    $a->delete('items', 'r1');
                }
            });
        });
    }

    /** MariaDB with innodb_snapshot_isolation: the server refuses the stale write (1020), inside the caller's transaction an exception. */
    #[Group('mysql')]
    public function test_mysql_stale_snapshot_with_snapshot_isolation_throws_and_overwrites_nothing(): void
    {
        $this->withMysqlPair(function (MysqlDriver $a, MysqlDriver $b): void {
            if ($a->pdo()->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch() === false) {
                $this->markTestSkipped('MariaDB only (innodb_snapshot_isolation).');
            }
            $this->withSnapshotIsolation($a->pdo(), 'ON', function () use ($a, $b): void {
                $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Start', '_version' => 1]);
                $a->pdo()->beginTransaction();
                try {
                    $a->findOne('items', 'r1');
                    $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Theirs', '_version' => 2], '_version', 1));
                    $a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Mine', '_version' => 2], '_version', 1);
                    $this->fail('MariaDB should refuse a write from a stale snapshot.');
                } catch (\PDOException $e) {
                    $this->assertSame(1020, $e->errorInfo[1] ?? null);
                } finally {
                    if ($a->pdo()->inTransaction()) {
                        $a->pdo()->rollBack();
                    }
                }
                $this->assertSame('Theirs', $b->findOne('items', 'r1')['title']);
            });
        });
    }

    private function withSnapshotIsolation(\PDO $pdo, string $value, \Closure $run): void
    {
        $row = $pdo->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch(\PDO::FETCH_NUM);
        if ($row !== false) {
            $pdo->exec("SET SESSION innodb_snapshot_isolation = {$value}");
        }
        try {
            $run();
        } finally {
            if ($row !== false) {
                $pdo->exec("SET SESSION innodb_snapshot_isolation = {$row[1]}");
            }
        }
    }

    /**
     * Whose transaction, and MariaDB's innodb_snapshot_isolation (null: the server's default;
     * ignored on MySQL, which has none). With it on, the loser of a race in a caller's
     * transaction gets MariaDB's 1020 instead of false: its claim hits a row its snapshot misses.
     *
     * @return iterable<string, array{bool, ?string, string}>
     */
    public static function transactionOwners(): iterable
    {
        yield 'own transaction' => [false, null, 'false'];
        yield 'caller\'s transaction' => [true, 'OFF', 'false'];
        yield 'caller\'s transaction, MariaDB snapshot isolation' => [true, 'ON', 'error: SQLSTATE[HY000]: General error: 1020'];
    }

    /**
     * Two first saves of one new id, in separate processes on their own connections, each
     * paused right before its INSERT until both got there. A claim before the INSERT took a gap
     * lock in each (REPEATABLE READ) and the two INSERTs deadlocked (a 500); inserting first
     * lets one win while the other waits, then loses.
     */
    #[Group('mysql')]
    #[\PHPUnit\Framework\Attributes\DataProvider('transactionOwners')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('pcntl')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('posix')]
    public function test_mysql_concurrent_first_saves_do_not_deadlock(bool $callerTransaction, ?string $snapshotIsolation, string $loser): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $mariadb = $pdo->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch() !== false;
        if ($snapshotIsolation === 'ON' && !$mariadb) {
            $this->markTestSkipped('MariaDB only (innodb_snapshot_isolation).');
        }
        MysqlTestDatabase::dropAllTables($pdo);
        $pdo->exec('CREATE TABLE items (uuid VARCHAR(64) PRIMARY KEY, title TEXT NULL, body TEXT NULL, _version TEXT NULL)');
        $c = MysqlTestDatabase::dataConfig();
        $race = $this->dir . '/race';
        mkdir($race);

        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed');
            }
            if ($pid === 0) {
                $result = 'none';
                try {
                    $mine = new HookedPdo(
                        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
                        $c['username'],
                        $c['password'],
                        MysqlDriver::connectionOptions(),
                    );
                    $driver = new MysqlDriver($mine);
                    if ($snapshotIsolation !== null && $mariadb) {
                        $mine->exec("SET SESSION innodb_snapshot_isolation = {$snapshotIsolation}");
                    }
                    $paused = false;
                    $mine->beforePrepare = static function (string $sql) use (&$paused, $race, $i): void {
                        if ($paused || !str_starts_with($sql, 'INSERT')) {
                            return;
                        }
                        $paused = true;
                        touch("{$race}/ready{$i}");
                        $until = microtime(true) + 5;
                        while (count(glob("{$race}/ready*") ?: []) < 2 && microtime(true) < $until) {
                            usleep(5_000);
                        }
                    };
                    if ($callerTransaction) {
                        $mine->beginTransaction();
                    }
                    $ok = $driver->saveIfVersion('items', 'new', ['uuid' => 'new', 'title' => "Child {$i}", '_version' => 1], '_version', 0);
                    if ($callerTransaction) {
                        $mine->commit();
                    }
                    $result = $ok ? "true {$i}" : 'false';
                } catch (\Throwable $e) {
                    $result = 'error: ' . $e->getMessage();
                } finally {
                    file_put_contents("{$race}/{$i}.txt", $result);
                    // No shutdown: PHPUnit's handlers and the inherited connection belong to the parent.
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        try {
            $got = array_map(static fn (string $f): string => (string) file_get_contents($f), glob($race . '/*.txt') ?: []);
            sort($got);
            $this->assertCount(2, $got, 'every child reported');
            $this->assertStringStartsWith($loser, $got[0], implode(' | ', $got));
            $this->assertStringStartsWith('true ', $got[1], implode(' | ', $got));
            $winner = substr($got[1], 5);
            $this->assertSame("Child {$winner}", (new MysqlDriver($pdo))->findOne('items', 'new')['title']);
        } finally {
            MysqlTestDatabase::dropAllTables($pdo);
        }
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

    private function checkEmptyVersionCountsAsZero(VersionedStorage $a, VersionedStorage $b): void
    {
        $a->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Empty version', '_version' => '']);

        $this->assertFalse($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Wrong', '_version' => 2], '_version', 1));
        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'Versioned', '_version' => 1], '_version', 0));

        $row = $a->findOne('items', 'r1');
        $this->assertSame('Versioned', $row['title']);
        $this->assertSame(1, (int) $row['_version']);
    }

    /** An integral version given as '05' or '6.0' is stored as '5', '6', so the next check matches it. */
    private function checkVersionIsWrittenCanonical(VersionedStorage $a, VersionedStorage $b): void
    {
        $this->assertTrue($a->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'A', '_version' => '05'], '_version', 0));
        $this->assertSame('5', (string) $b->findOne('items', 'r1')['_version']);
        $this->assertTrue($b->saveIfVersion('items', 'r1', ['uuid' => 'r1', 'title' => 'B', '_version' => '6.0'], '_version', 5));
        $this->assertSame('6', (string) $a->findOne('items', 'r1')['_version']);
    }
}
