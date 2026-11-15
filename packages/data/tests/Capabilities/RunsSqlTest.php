<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Capabilities;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\PdoConnection;
use Preflow\Data\RunsSql;
use Preflow\Data\StorageDriver;
use Preflow\Data\UnsupportedStorage;
use Preflow\Testing\PlainDriver;

final class RunsSqlTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runssql_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function sqlite(): SqliteDriver
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER, v TEXT)');
        $pdo->exec("INSERT INTO t VALUES (1, 'a')");

        return new SqliteDriver($pdo);
    }

    public function test_raw_on_a_storage_without_runs_sql_names_storage_and_capability(): void
    {
        $dm = new DataManager(['json' => new JsonFileDriver($this->dir)], 'json');
        try {
            $dm->raw('SELECT 1', [], 'json');
            $this->fail('Expected UnsupportedStorage.');
        } catch (UnsupportedStorage $e) {
            $this->assertSame('json', $e->storage);
            $this->assertSame(RunsSql::class, $e->capability);
            $this->assertSame('Storage [json] does not implement ' . RunsSql::class . '; raw() needs a storage that runs SQL.', $e->getMessage());
        }
    }

    public function test_execute_on_a_plain_driver_is_unsupported_storage_and_still_a_logic_exception(): void
    {
        $dm = new DataManager(['plain' => new PlainDriver($this->sqlite())], 'plain');

        try {
            $dm->execute('UPDATE t SET v = 1', [], 'plain');
            $this->fail('Expected UnsupportedStorage.');
        } catch (\LogicException $e) {
            $this->assertInstanceOf(UnsupportedStorage::class, $e);
        }
    }

    public function test_raw_still_reports_writes_to_the_observer(): void
    {
        $writes = [];
        $observer = new class ($writes) implements DataObserver {
            public function __construct(private array &$writes) {}
            public function read(string $storage, string $type, ?string $id): void {}
            public function wrote(string $storage, string $type, ?string $id): void {}
            public function rawRead(string $storage, string $sql): void {}
            public function rawWrote(string $storage, string $sql): void
            {
                $this->writes[] = [$storage, $sql];
            }
        };
        $dm = new DataManager(['default' => $this->sqlite()], 'default');
        $dm->observe($observer);

        $dm->raw("UPDATE t SET v = 'b'");

        $this->assertSame([['default', "UPDATE t SET v = 'b'"]], $writes);
    }

    public function test_storage_driver_has_no_raw_query(): void
    {
        $this->assertFalse((new \ReflectionClass(StorageDriver::class))->hasMethod('rawQuery'));
        $this->assertTrue(is_subclass_of(SqliteDriver::class, PdoConnection::class));
    }
}
