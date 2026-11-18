<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\StorageDriver;
use Preflow\Data\Transactions;
use Preflow\Testing\PlainDriver;

#[Entity(table: 'visits', storage: 'default')]
class VisitRow extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $page = '';
}

#[Entity(table: 'hits', storage: 'default')]
class HitRow extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $page = '';
}

/** insertIgnoring() and insertMany() on the built-in drivers and on a driver with no capability at all. */
final class InsertManyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_insert_many_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/json', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/json/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/json/*') ?: [] as $folder) {
            @rmdir($folder);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : unlink($file);
        }
        @rmdir($this->dir);
    }

    private function driver(string $kind): StorageDriver
    {
        if ($kind === 'json') {
            return new JsonFileDriver($this->dir . '/json');
        }
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS visits (uuid TEXT PRIMARY KEY, page TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS hits (id INTEGER PRIMARY KEY, page TEXT)');
        $sqlite = new SqliteDriver($pdo);

        return $kind === 'plain' ? new PlainDriver($sqlite) : $sqlite;
    }

    private function manager(string $kind): DataManager
    {
        return new DataManager(['default' => $this->driver($kind)]);
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
        yield 'a driver with no capability' => ['plain'];
    }

    private function visit(string $id, string $page): VisitRow
    {
        $row = new VisitRow();
        $row->uuid = $id;
        $row->page = $page;

        return $row;
    }

    #[DataProvider('kinds')]
    public function test_insert_ignoring_writes_a_new_id_and_leaves_an_existing_one(string $kind): void
    {
        $dm = $this->manager($kind);

        $this->assertTrue($dm->insertIgnoring($this->visit('a', 'first')));
        $this->assertFalse($dm->insertIgnoring($this->visit('a', 'second')));
        $this->assertSame('first', $dm->find(VisitRow::class, 'a')?->page);
    }

    #[DataProvider('kinds')]
    public function test_insert_ignoring_reports_only_the_rows_it_wrote(string $kind): void
    {
        $dm = $this->manager($kind);
        $observer = new class implements DataObserver {
            /** @var list<?string> */
            public array $seen = [];

            public function read(string $storage, string $type, ?string $id): void {}

            public function wrote(string $storage, string $type, ?string $id): void
            {
                $this->seen[] = $id;
            }

            public function rawRead(string $storage, string $sql): void {}

            public function rawWrote(string $storage, string $sql): void {}
        };
        $dm->observe($observer);
        $dm->insert($this->visit('a', 'first'));
        $dm->insertIgnoring($this->visit('a', 'second'));
        $dm->insertIgnoring($this->visit('b', 'third'));

        $this->assertSame(['a', 'b'], $observer->seen);
    }

    #[DataProvider('kinds')]
    public function test_insert_many_returns_the_number_written(string $kind): void
    {
        $dm = $this->manager($kind);

        $written = $dm->insertMany([$this->visit('a', '1'), $this->visit('b', '2'), $this->visit('c', '3')]);

        $this->assertSame(3, $written);
        $this->assertSame(['1', '2', '3'], array_map(static fn ($id) => $dm->find(VisitRow::class, $id)?->page, ['a', 'b', 'c']));
    }

    #[DataProvider('kinds')]
    public function test_insert_many_accepts_any_iterable(string $kind): void
    {
        $dm = $this->manager($kind);
        $gen = (function () {
            yield $this->visit('a', '1');
            yield $this->visit('b', '2');
        })();

        $this->assertSame(2, $dm->insertMany($gen));
    }

    #[DataProvider('kinds')]
    public function test_insert_many_ignoring_duplicates_skips_them_and_counts_the_rest(string $kind): void
    {
        $dm = $this->manager($kind);
        $dm->insert($this->visit('a', 'old'));

        $written = $dm->insertMany([$this->visit('a', 'new'), $this->visit('b', '2'), $this->visit('b', 'again')], true);

        $this->assertSame(1, $written);
        $this->assertSame('old', $dm->find(VisitRow::class, 'a')?->page);
        $this->assertSame('2', $dm->find(VisitRow::class, 'b')?->page);
    }

    #[DataProvider('kinds')]
    public function test_insert_many_throws_on_a_duplicate_and_leaves_the_existing_row(string $kind): void
    {
        $dm = $this->manager($kind);
        $dm->insert($this->visit('b', 'old'));

        try {
            $dm->insertMany([$this->visit('a', '1'), $this->visit('b', 'new'), $this->visit('c', '3')]);
            $this->fail('A duplicate must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('a record with this id exists', $e->getMessage());
        }

        $this->assertSame('old', $dm->find(VisitRow::class, 'b')?->page);
        $this->assertNull($dm->find(VisitRow::class, 'c'), 'nothing after the duplicate is written');
    }

    public function test_insert_many_keeps_nothing_of_the_batch_where_the_driver_has_transactions(): void
    {
        $this->assertInstanceOf(Transactions::class, $this->driver('sqlite'));
        $dm = $this->manager('sqlite');
        $dm->insert($this->visit('b', 'old'));

        try {
            $dm->insertMany([$this->visit('a', '1'), $this->visit('b', 'new')]);
            $this->fail('A duplicate must throw.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($dm->find(VisitRow::class, 'a'), 'the row before the duplicate was rolled back');
        $this->assertSame('old', $dm->find(VisitRow::class, 'b')?->page);
    }

    public function test_insert_many_gives_auto_increment_ids(): void
    {
        $dm = $this->manager('sqlite');
        $rows = [];
        foreach (['x', 'y'] as $page) {
            $row = new HitRow();
            $row->page = $page;
            $rows[] = $row;
        }

        $this->assertSame(2, $dm->insertMany($rows));
        $this->assertGreaterThan(0, $rows[0]->id);
        $this->assertGreaterThan($rows[0]->id, $rows[1]->id);
    }

    public function test_insert_many_of_nothing_writes_nothing(): void
    {
        $this->assertSame(0, $this->manager('sqlite')->insertMany([]));
    }
}
