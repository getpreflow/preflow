<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Testing\MysqlTestDatabase;

final class LazyConnectionTest extends TestCase
{
    private int $opened = 0;

    private function lazySqlite(): SqliteDriver
    {
        return new SqliteDriver(function (): \PDO {
            $this->opened++;
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE t (uuid TEXT PRIMARY KEY, v TEXT)');

            return $pdo;
        });
    }

    public function test_no_connection_is_opened_until_the_first_query(): void
    {
        $driver = $this->lazySqlite();

        $this->assertSame(0, $this->opened);
        $this->assertFalse($driver->connected());
    }

    public function test_first_query_connects_and_later_queries_reuse_it(): void
    {
        $driver = $this->lazySqlite();

        $driver->save('t', 'a', ['v' => '1']);
        $this->assertSame(1, $this->opened);
        $this->assertTrue($driver->connected());

        $this->assertSame([['v' => '1']], $driver->rawQuery('SELECT v FROM t'));
        $this->assertSame($driver->pdo(), $driver->pdo());
        $this->assertSame(1, $this->opened);
    }

    public function test_transactions_work_on_the_lazy_connection(): void
    {
        $driver = $this->lazySqlite();

        $driver->exclusive('t', function () use ($driver): void {
            $driver->save('t', 'a', ['v' => '1']);
        });
        $this->assertSame(1, $this->opened);
        $this->assertCount(1, $driver->rawQuery('SELECT * FROM t'));

        try {
            $driver->exclusive('t', function () use ($driver): void {
                $driver->save('t', 'b', ['v' => '2']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $driver->rawQuery('SELECT * FROM t'));

        $pdo = $driver->pdo();
        $pdo->beginTransaction();
        $driver->save('t', 'c', ['v' => '3']);
        $pdo->rollBack();
        $this->assertCount(1, $driver->rawQuery('SELECT * FROM t'));
        $this->assertSame(1, $this->opened);
    }

    public function test_a_failing_connector_throws_the_same_error_every_time(): void
    {
        $driver = new SqliteDriver(static function (): \PDO {
            throw new \PDOException('down');
        });

        foreach ([1, 2] as $_) {
            try {
                $driver->pdo();
                $this->fail('expected PDOException');
            } catch (\PDOException $e) {
                $this->assertSame('down', $e->getMessage());
            }
        }
        $this->assertFalse($driver->connected());
    }

    public function test_a_failing_configure_step_never_leaves_an_unconfigured_connection(): void
    {
        $calls = 0;
        $driver = new class (static fn (): \PDO => new \PDO('sqlite::memory:'), $calls) extends \Preflow\Data\Driver\PdoDriver {
            public function __construct(\Closure $c, public int &$calls)
            {
                $d = new \Preflow\Data\Driver\SqliteDialect();
                parent::__construct($c, $d, new \Preflow\Data\Driver\QueryCompiler($d));
            }

            protected function configure(\PDO $pdo): void
            {
                if (++$this->calls === 1) {
                    throw new \PDOException('set failed');
                }
            }

            public function exclusive(string $type, \Closure $fn): mixed
            {
                return $fn();
            }
        };

        try {
            $driver->pdo();
            $this->fail('expected PDOException');
        } catch (\PDOException) {
        }
        $this->assertFalse($driver->connected());
        $this->assertInstanceOf(\PDO::class, $driver->pdo());
        $driver->pdo();
        $this->assertSame(2, $calls);
        $this->assertTrue($driver->connected());
    }

    #[Group('mysql')]
    public function test_mysql_session_setup_runs_once_when_it_connects(): void
    {
        MysqlTestDatabase::dataConfig(); // skips without a configured server
        $opened = 0;
        $driver = new MysqlDriver(function () use (&$opened): \PDO {
            $opened++;

            return MysqlTestDatabase::reconnect();
        });
        $this->assertSame(0, $opened);

        $modes = $driver->rawQuery('SELECT @@SESSION.sql_mode AS m')[0]['m'];
        foreach (explode(',', MysqlDriver::SQL_MODE) as $flag) {
            $this->assertStringContainsString($flag, $modes);
        }
        $driver->rawQuery('SELECT 1');
        $this->assertSame(1, $opened);

        $driver->exclusive('lazy', fn () => $driver->rawQuery('SELECT 1'));
    }
}
