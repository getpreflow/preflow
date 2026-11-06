<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fast;

use PHPUnit\Framework\TestCase;
use Preflow\Testing\Fast\MysqlSnapshotDatabase;
use Preflow\Testing\Fast\SetupSnapshots;
use Preflow\Testing\Fast\SnapshotDatabase;
use Preflow\Testing\MysqlTestDatabase;

/**
 * Databases in snapshots: a restored step leaves exactly the tables the real step left, whatever
 * the test before changed; another database before the step runs it for real. Uses the configured
 * MySQL test database (MYSQL_*), skipped without it.
 */
final class MysqlSnapshotDatabaseTest extends TestCase
{
    private string $run = '';

    private string $snapshotDir = '';

    private int $runs = 0;

    private ?SetupSnapshots $snapshots = null;

    protected function setUp(): void
    {
        if (!MysqlTestDatabase::configured()) {
            $this->markTestSkipped('MySQL not configured (set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS)');
        }
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        $this->run = 'test-' . bin2hex(random_bytes(4));
        $this->snapshotDir = sys_get_temp_dir() . '/preflow_snapshot_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->snapshotDir === '') {
            return; // skipped
        }
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        foreach (glob($this->snapshotDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->snapshotDir);
    }

    private static function database(): SnapshotDatabase
    {
        return new MysqlSnapshotDatabase(MysqlTestDatabase::pdo(...), (string) getenv('MYSQL_DATABASE'));
    }

    /** What a test's scaffold does when it starts: a fresh object, the old tables to go lazily. */
    private function boot(?SnapshotDatabase $db = null): SetupSnapshots
    {
        $this->snapshots = (new SetupSnapshots($this->run, $this->snapshotDir))->database('test', $db ?? self::database());
        $this->snapshots->reset();

        return $this->snapshots;
    }

    /** What the site's PDO helper does: pending tables are written before anything touches the database. */
    private function pdo(): \PDO
    {
        $this->snapshots?->flush();

        return MysqlTestDatabase::pdo();
    }

    /** A step with a table with an id counter, NULLs and binary data. */
    private function probe(string $name = 'probe'): string
    {
        return $this->snapshots->step($name . '-' . $this->name(), function () use ($name): string {
            $this->runs++;
            if ($name === 'probe') {
                $pdo = $this->pdo();
                $pdo->exec('CREATE TABLE probe (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(20), note TEXT NULL, raw VARBINARY(8)) ENGINE=InnoDB');
                $pdo->exec("INSERT INTO probe (label, note, raw) VALUES ('a', NULL, 0x00FF10), ('b', 'Zürich', '')");
            } else {
                $this->pdo()->exec("INSERT INTO probe (label) VALUES ('{$name}')");
            }

            return $name . ' result';
        });
    }

    /** @return array<string, array{0: string, 1: list<array<string, mixed>>}> */
    private function dump(): array
    {
        $pdo = $this->pdo();
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $tables[$table] = [
                (string) $pdo->query("SHOW CREATE TABLE `{$table}`")->fetchColumn(1),
                $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        return $tables;
    }

    public function test_a_restored_step_leaves_what_the_real_step_left(): void
    {
        $this->boot();
        $this->assertSame('probe result', $this->probe());
        $real = $this->dump();

        // What a test might leave behind: more rows, a used id counter, another table, a changed column.
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO probe (label) VALUES ('c'), ('d')");
        $pdo->exec('CREATE TABLE leftover (x INT)');
        $pdo->exec('ALTER TABLE probe MODIFY label VARCHAR(40)');

        $this->boot();
        $this->assertSame('probe result', $this->probe());
        $this->assertSame(1, $this->runs, 'the second time the step is restored');
        $this->assertSame($real, $this->dump());
        // The id counter is where the real step left it.
        $this->pdo()->exec("INSERT INTO probe (label) VALUES ('next')");
        $this->assertSame('3', (string) $this->pdo()->query("SELECT id FROM probe WHERE label = 'next'")->fetchColumn());
    }

    public function test_the_same_table_definition_is_restored_too(): void
    {
        $this->boot();
        $this->probe();
        $real = $this->dump();
        $this->pdo()->exec("UPDATE probe SET note = 'changed'");
        $this->pdo()->exec("INSERT INTO probe (label) VALUES ('c')");

        $this->boot();
        $this->probe();
        $this->assertSame(1, $this->runs);
        $this->assertSame($real, $this->dump());
    }

    public function test_another_database_runs_the_step(): void
    {
        $this->boot();
        $this->probe();

        $this->boot();
        $this->pdo()->exec('CREATE TABLE earlier (x INT)');
        $this->probe();
        $this->assertSame(2, $this->runs);
    }

    public function test_reset_empties_lazily(): void
    {
        $this->boot();
        $this->probe();

        $this->boot();
        $tables = MysqlTestDatabase::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['probe'], $tables, 'nothing touched the database yet');

        $this->snapshots->flush();
        $this->assertSame([], MysqlTestDatabase::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_two_restored_steps_write_the_tables_once(): void
    {
        $this->boot();
        $this->probe('probe');
        $this->probe('second');
        $real = $this->dump();

        $spy = new class (self::database()) implements SnapshotDatabase {
            public int $restores = 0;

            public function __construct(private readonly SnapshotDatabase $db)
            {
            }

            public function state(): array
            {
                return $this->db->state();
            }

            public function capture(): array
            {
                return $this->db->capture();
            }

            public function restore(array $tables): void
            {
                $this->restores++;
                $this->db->restore($tables);
            }

            public function empty(): void
            {
                $this->db->empty();
            }
        };
        $this->boot($spy);
        $this->assertSame('probe result', $this->probe('probe'));
        $this->assertSame('second result', $this->probe('second'));
        $this->assertSame(2, $this->runs, 'both steps restored');
        $this->assertSame(0, $spy->restores, 'nothing written before something touches the database');

        $this->snapshots->flush();
        $this->assertSame(1, $spy->restores);
        $this->assertSame($real, $this->dump());
    }

    public function test_another_database_name_is_refused(): void
    {
        $db = new MysqlSnapshotDatabase(MysqlTestDatabase::pdo(...), 'other_db');

        $this->expectException(\LogicException::class);
        $db->state();
    }
}
