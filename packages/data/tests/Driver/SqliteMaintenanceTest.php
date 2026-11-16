<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;

/**
 * What the sqlite3 tool cannot do on a Preflow SQLite file (its text columns use the
 * preflow_text collation), done through SqliteDriver's prepared connection: check, compact, and
 * a dump that restores with its unique index, other indexes and autoincrement counter.
 */
final class SqliteMaintenanceTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/preflow_sqlite_maintenance_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file . '*') ?: [] as $file) {
            unlink($file);
        }
    }

    private function seeded(): SqliteDriver
    {
        $driver = new SqliteDriver(new \PDO('sqlite:' . $this->file));
        $driver->execute('CREATE TABLE "slugs" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "slug" VARCHAR(255) COLLATE preflow_text NOT NULL, "note" TEXT COLLATE preflow_text)');
        $driver->execute('CREATE UNIQUE INDEX "uniq_slugs_slug" ON "slugs" ("slug")');
        $driver->execute('CREATE INDEX "idx_slugs_note" ON "slugs" ("note")');
        foreach (['Über', 'apfel', 'Zebra'] as $slug) {
            $driver->execute('INSERT INTO "slugs" ("slug", "note") VALUES (?, ?)', [$slug, "line one\nline two"]);
        }
        $driver->execute('DELETE FROM "slugs" WHERE "slug" = ?', ['zebra']);

        return $driver;
    }

    public function test_the_sqlite3_tool_has_no_collation_for_these_tables(): void
    {
        $this->seeded();
        $plain = new \PDO('sqlite:' . $this->file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->expectExceptionMessage('no such collation sequence: preflow_text');
        $plain->exec('VACUUM');
    }

    public function test_check_and_compact_run_on_the_prepared_connection(): void
    {
        $driver = $this->seeded();

        $this->assertSame([], $driver->check());
        $driver->compact();
        $this->assertSame([], $driver->check());
        $this->assertSame(['apfel', 'Über'], array_column($driver->rawQuery('SELECT "slug" FROM "slugs" ORDER BY "slug"'), 'slug'));
    }

    public function test_a_dump_restores_the_unique_index_the_indexes_and_the_counter(): void
    {
        $source = $this->seeded();
        $stream = fopen('php://memory', 'w+');
        $source->dump($stream);
        rewind($stream);
        $dump = (string) stream_get_contents($stream);
        rewind($stream);

        $target = new SqliteDriver(new \PDO('sqlite:' . $this->file . '.restored'));
        $target->restore($stream);

        $this->assertStringStartsWith('-- Preflow SQLite dump.', $dump);
        $this->assertSame([], $target->check());
        $this->assertSame(
            ['idx_slugs_note', 'uniq_slugs_slug'],
            array_column($target->rawQuery("SELECT name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name'),
        );
        $this->assertSame("line one\nline two", $target->rawQuery('SELECT "note" FROM "slugs" WHERE "slug" = ?', ['ÜBER'])[0]['note'] ?? null, 'found by the rule');
        try {
            $target->execute('INSERT INTO "slugs" ("slug") VALUES (?)', ['APFEL']);
            $this->fail('the restored unique index refuses a case variant');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $target->execute('INSERT INTO "slugs" ("slug") VALUES (?)', ['neu']);
        $this->assertSame(4, (int) $target->lastInsertId(), 'the autoincrement counter came along (3 rows were made, one deleted)');
    }

    public function test_a_failed_restore_leaves_the_store_empty(): void
    {
        $target = new SqliteDriver(new \PDO('sqlite:' . $this->file));
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "BEGIN TRANSACTION;\nCREATE TABLE \"a\" (\"id\" INTEGER);\nINSERT INTO \"missing\" VALUES(1);\nCOMMIT;\n");
        rewind($stream);

        try {
            $target->restore($stream);
            $this->fail('a broken dump throws');
        } catch (\PDOException) {
        }
        $this->assertSame([], $target->rawQuery("SELECT name FROM sqlite_master WHERE type = 'table'"));
    }

    public function test_a_dump_without_its_own_transaction_restores_all_or_nothing(): void
    {
        $target = new SqliteDriver(new \PDO('sqlite:' . $this->file));
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "CREATE TABLE \"a\" (\"id\" INTEGER);\nINSERT INTO \"a\" VALUES(1);\nINSERT INTO \"missing\" VALUES(1);\n");
        rewind($stream);

        try {
            $target->restore($stream);
            $this->fail('a broken dump throws');
        } catch (\PDOException) {
        }
        $this->assertSame([], $target->rawQuery("SELECT name FROM sqlite_master WHERE type = 'table'"), 'nothing of it stays');

        $ok = fopen('php://memory', 'w+');
        fwrite($ok, "CREATE TABLE \"a\" (\"id\" INTEGER);\nINSERT INTO \"a\" VALUES(1);\n");
        rewind($ok);
        $target->restore($ok);
        $this->assertSame([['id' => 1]], $target->rawQuery('SELECT "id" FROM "a"'));
    }

    public function test_a_restore_leaves_foreign_keys_as_it_found_them(): void
    {
        foreach ([true, false] as $on) {
            foreach (['good' => "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE \"a\" (\"id\" INTEGER);\nCOMMIT;\n", 'bad' => "CREATE TABLE \"b\" (\"id\" INTEGER);\nINSERT INTO \"nope\" VALUES(1);\n"] as $case => $text) {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->exec('PRAGMA foreign_keys=' . ($on ? 'ON' : 'OFF'));
                $target = new SqliteDriver($pdo);
                $stream = fopen('php://memory', 'w+');
                fwrite($stream, $text);
                rewind($stream);
                try {
                    $target->restore($stream);
                } catch (\PDOException) {
                }
                $this->assertSame($on ? 1 : 0, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), "{$case}, was " . ($on ? 'on' : 'off'));
            }
        }
    }
}
