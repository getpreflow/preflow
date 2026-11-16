<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Capabilities;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\PartialCommit;
use Preflow\Data\Transactions;
use Preflow\Testing\PlainDriver;

/** DataManager::transaction() across storages: one transaction per driver, joined when one is open. */
final class TransactionsTest extends TestCase
{
    private static function pdo(?\PDO $pdo = null): \PDO
    {
        $pdo ??= new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (uuid TEXT PRIMARY KEY, v TEXT)');

        return $pdo;
    }

    private static function save(SqliteDriver $driver, string $id): void
    {
        $driver->save('t', $id, ['uuid' => $id, 'v' => 'x']);
    }

    public function test_a_failure_rolls_back_every_storage(): void
    {
        $a = new SqliteDriver(self::pdo());
        $b = new SqliteDriver(self::pdo());
        $dm = new DataManager(['a' => $a, 'b' => $b], 'a');
        $failure = new \RuntimeException('boom');

        try {
            $dm->transaction(['a', 'b'], static function () use ($a, $b, $failure): void {
                self::save($a, 'r1');
                self::save($b, 'r2');

                throw $failure;
            });
            $this->fail('Expected the failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e, 'the failure itself is rethrown');
        }

        $this->assertNull($a->findOne('t', 'r1'));
        $this->assertNull($b->findOne('t', 'r2'));
        $this->assertFalse($a->inTransaction());
        $this->assertFalse($b->inTransaction());
    }

    public function test_aliased_storages_begin_one_transaction(): void
    {
        $a = new SqliteDriver(self::pdo());
        $dm = new DataManager(['default' => $a, 'a' => $a]);

        $result = $dm->transaction(['default', 'a'], static function () use ($a): string {
            self::save($a, 'r1');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertNotNull($a->findOne('t', 'r1'));
        $this->assertFalse($a->inTransaction(), 'one begin, one commit: nothing left open');
    }

    public function test_a_transaction_inside_an_open_one_leaves_it_to_the_caller(): void
    {
        $pdo = self::pdo();
        $a = new SqliteDriver($pdo);
        $dm = new DataManager(['a' => $a], 'a');

        $pdo->beginTransaction();
        $dm->transaction(['a'], static fn () => self::save($a, 'r1'));

        $this->assertTrue($pdo->inTransaction(), 'the caller\'s transaction is not committed');
        $pdo->rollBack();
        $this->assertNull($a->findOne('t', 'r1'), 'the write was part of the caller\'s transaction');
    }

    public function test_a_storage_without_transactions_just_runs(): void
    {
        $dir = sys_get_temp_dir() . '/transactions_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            $plain = new PlainDriver(new JsonFileDriver($dir));
            $this->assertNotInstanceOf(Transactions::class, $plain);
            $dm = new DataManager(['json' => $plain], 'json');

            $result = $dm->transaction(['json'], static function () use ($plain): int {
                $plain->save('t', 'r1', ['uuid' => 'r1', 'v' => 'x']);

                return 42;
            });

            $this->assertSame(42, $result);
            $this->assertNotNull($plain->findOne('t', 'r1'));
        } finally {
            @unlink("{$dir}/t/r1.json");
            @rmdir("{$dir}/t");
            @rmdir($dir);
        }
    }

    public function test_a_failed_outer_commit_after_an_inner_one_is_a_partial_commit(): void
    {
        $failing = new class('sqlite::memory:') extends \PDO {
            public ?\PDOException $failure = null;

            public function commit(): bool
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return parent::commit();
            }
        };
        $a = new SqliteDriver(self::pdo($failing));
        $b = new SqliteDriver(self::pdo());
        $dm = new DataManager(['a' => $a, 'b' => $b], 'a');
        $failing->failure = new \PDOException('disk full');

        try {
            $dm->transaction(['a', 'b'], static function () use ($a, $b): void {
                self::save($a, 'r1');
                self::save($b, 'r2');
            });
            $this->fail('Expected PartialCommit.');
        } catch (PartialCommit $e) {
            $this->assertSame(['b'], $e->committed);
            $this->assertSame($failing->failure, $e->getPrevious());
        }

        $this->assertNotNull($b->findOne('t', 'r2'), 'the inner storage committed first');
        $this->assertNull($a->findOne('t', 'r1'), 'the outer one rolled back');
        $this->assertFalse($a->inTransaction());
    }
}
