<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

final class SchemaTest extends TestCase
{
    private \PDO $pdo;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = new Schema($this->pdo);
    }

    public function test_create_table(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        // Verify table exists
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='posts'");
        $this->assertSame('posts', $stmt->fetchColumn());
    }

    public function test_table_has_columns(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->index();
            $table->integer('views')->nullable();
            $table->json('metadata')->nullable();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(posts)");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        $this->assertContains('uuid', $names);
        $this->assertContains('title', $names);
        $this->assertContains('views', $names);
        $this->assertContains('metadata', $names);
    }

    public function test_drop_table(): void
    {
        $this->pdo->exec('CREATE TABLE temp_table (id INTEGER)');

        $this->schema->drop('temp_table');

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='temp_table'");
        $this->assertFalse($stmt->fetchColumn());
    }

    public function test_timestamps_creates_two_columns(): void
    {
        $this->schema->create('events', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->timestamps();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(events)");
        $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_has_table_and_columns(): void
    {
        $this->schema->create('t', function (Table $table) {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        $this->assertTrue($this->schema->hasTable('t'));
        $this->assertFalse($this->schema->hasTable('x'));
        $this->assertSame(['id', 'name'], $this->schema->columns('t'));
    }

    public function test_add_column_is_nullable(): void
    {
        $this->schema->create('t', function (Table $table) {
            $table->uuid('id')->primary();
        });
        $this->schema->addColumn('t', 'note');
        $this->pdo->exec("INSERT INTO t (id) VALUES ('a')");

        $this->assertSame(['id', 'note'], $this->schema->columns('t'));
        $this->assertNull($this->pdo->query('SELECT note FROM t')->fetchColumn());
    }

    public function test_composite_unique_refuses_duplicate_pair(): void
    {
        $this->schema->create('t', fn (Table $t) => $t->string('player_token')->string('game')->unique('player_token', 'game'));
        $insert = $this->pdo->prepare('INSERT INTO t (player_token, game) VALUES (?, ?)');
        $insert->execute(['a', 'g1']);
        $insert->execute(['a', 'g2']);

        $this->assertContains('uniq_t_player_token_game', $this->schema->indexes('t'));
        $this->expectException(\PDOException::class);
        $insert->execute(['a', 'g1']);
    }

    public function test_composite_plain_index_is_created(): void
    {
        $this->schema->create('t', fn (Table $t) => $t->string('a')->string('b')->index('a', 'b'));
        $insert = $this->pdo->prepare('INSERT INTO t (a, b) VALUES (?, ?)');
        $insert->execute(['x', 'y']);
        $insert->execute(['x', 'y']);

        $this->assertContains('idx_t_a_b', $this->schema->indexes('t'));
    }

    public function test_unique_without_arguments_marks_last_column(): void
    {
        $this->schema->create('t', fn (Table $t) => $t->string('col')->unique());

        $this->assertContains('uniq_t_col', $this->schema->indexes('t'));
        $this->pdo->exec("INSERT INTO t (col) VALUES ('a')");
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO t (col) VALUES ('a')");
    }

    public function test_index_without_arguments_unchanged(): void
    {
        $this->schema->create('t', fn (Table $t) => $t->string('col')->index());

        $this->assertContains('idx_t_col', $this->schema->indexes('t'));
    }

    public function test_index_on_unknown_column_throws(): void
    {
        try {
            $this->schema->create('t', fn (Table $t) => $t->string('a')->unique('nope'));
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('t.nope', $e->getMessage());
        }
        $this->assertFalse($this->schema->hasTable('t'));
    }

    public function test_rename_column_keeps_the_data(): void
    {
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('passwordHash')->nullable();
        });
        $this->pdo->exec("INSERT INTO staff (uuid, passwordHash) VALUES ('a', 'h')");

        $this->schema->renameColumn('staff', 'passwordHash', 'password_hash');

        $this->assertSame(['uuid', 'password_hash'], $this->schema->columns('staff'));
        $this->assertSame('h', $this->pdo->query('SELECT password_hash FROM staff')->fetchColumn());
    }

    public function test_rename_column_refuses_a_missing_column_or_a_taken_name(): void
    {
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
        });

        foreach ([['ghost', 'x', 'has no column "ghost"'], ['email', 'name', 'already has a column "name"']] as [$from, $to, $message]) {
            try {
                $this->schema->renameColumn('staff', $from, $to);
                $this->fail("renamed {$from}");
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringContainsString('staff', $e->getMessage());
            }
        }
        $this->assertSame(['uuid', 'email', 'name'], $this->schema->columns('staff'), 'nothing changed');
    }

    public function test_the_rename_is_quoted_by_the_grammar(): void
    {
        $this->assertSame('ALTER TABLE "staff" RENAME COLUMN "a" TO "b"', (new \Preflow\Data\Migration\Grammar\SqliteGrammar())->renameColumn('staff', 'a', 'b'));
        $this->assertSame('ALTER TABLE `staff` RENAME COLUMN `a` TO `b`', (new \Preflow\Data\Migration\Grammar\MysqlGrammar())->renameColumn('staff', 'a', 'b'));
    }

    public function test_add_columns_to_an_existing_table_typed_by_the_grammar(): void
    {
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
        });
        $this->pdo->exec("INSERT INTO staff (uuid) VALUES ('a')");

        $this->schema->addColumns('staff', function (Table $table) {
            $table->boolean('active');
            $table->string('auth_epoch')->index('auth_epoch');
        });

        $this->assertSame(['uuid' => 'text', 'active' => 'integer', 'auth_epoch' => 'text'], $this->schema->columnTypes('staff'));
        $this->assertNotSame([], $this->schema->indexes('staff'));
        $this->assertNull($this->pdo->query('SELECT active FROM staff')->fetchColumn(), 'added nullable');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already has a column "active"');
        $this->schema->addColumns('staff', fn (Table $table) => $table->boolean('active'));
    }

    public function test_fill_nulls_backfills_only_empty_rows(): void
    {
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->boolean('active')->nullable();
        });
        $this->pdo->exec("INSERT INTO staff (uuid, active) VALUES ('a', NULL), ('b', 0)");

        $this->assertSame(1, $this->schema->fillNulls('staff', 'active', true));

        $this->assertSame([['a', 1], ['b', 0]], $this->pdo->query('SELECT uuid, active FROM staff ORDER BY uuid')->fetchAll(\PDO::FETCH_NUM));
    }
}
