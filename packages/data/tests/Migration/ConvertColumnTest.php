<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/** Schema::convertColumn() on SQLite: the table is rebuilt, data, indexes and other columns kept. */
final class ConvertColumnTest extends TestCase
{
    private \PDO $pdo;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = new Schema($this->pdo);
    }

    private function recordTable(): void
    {
        // As RecordTableSync made it before the fields were indexed: free text everywhere.
        $this->pdo->exec('CREATE TABLE "object" ("uuid" TEXT PRIMARY KEY, "state" TEXT, "price" TEXT, "notes" TEXT)');
        $this->pdo->exec('CREATE INDEX "idx_object_notes" ON "object" ("notes")');
        $this->pdo->exec('CREATE UNIQUE INDEX "uniq_object_state_notes" ON "object" ("state", "notes")');
        $this->pdo->exec("INSERT INTO object VALUES ('a', 'sold', '12', 'x'), ('b', NULL, '12.5', 'y'), ('c', 'open', NULL, NULL)");
    }

    public function test_a_record_column_is_converted_to_its_kind_with_data_and_indexes_kept(): void
    {
        $this->recordTable();

        $this->schema->convertColumn('object', new ColumnSpec('price', ColumnKind::Number));

        $this->assertSame(['uuid' => 'text', 'state' => 'text', 'price' => 'numeric', 'notes' => 'text'], $this->schema->columnTypes('object'), 'column order kept');
        $this->assertSame(
            [['a', 'sold', 12, 'x'], ['b', null, 12.5, 'y'], ['c', 'open', null, null]],
            $this->pdo->query('SELECT uuid, state, price, notes FROM object ORDER BY uuid')->fetchAll(\PDO::FETCH_NUM),
        );
        $indexes = $this->schema->indexes('object');
        $this->assertContains('idx_object_notes', $indexes);
        $this->assertContains('uniq_object_state_notes', $indexes);
        $this->assertContains('uniq_object_state_notes', (new \Preflow\Data\Migration\Grammar\SqliteGrammar())->uniqueIndexes($this->pdo, 'object'), 'still unique');
        $this->assertSame('uuid', $this->pdo->query("SELECT name FROM pragma_table_info('object') WHERE pk = 1")->fetchColumn(), 'primary key kept');
        $this->assertSame([], $this->pdo->query("SELECT name FROM sqlite_master WHERE name LIKE '%convert%'")->fetchAll(), 'no work table left');
    }

    public function test_a_builder_column_changes_type_and_nullability_keeping_its_default(): void
    {
        $this->pdo->exec('CREATE TABLE "staff" ("uuid" TEXT PRIMARY KEY, "active" TEXT DEFAULT \'1\', "name" TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO staff VALUES ('a', '0', 'Ann'), ('b', '1', 'Bob')");

        $this->schema->convertColumn('staff', fn (Table $t) => $t->boolean('active'));
        $this->schema->convertColumn('staff', fn (Table $t) => $t->string('name', 40)->nullable());

        $this->assertSame(['uuid' => 'text', 'active' => 'integer', 'name' => 'text'], $this->schema->columnTypes('staff'));
        $info = $this->pdo->query("SELECT name, \"notnull\", dflt_value FROM pragma_table_info('staff')")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['uuid', 0, null], ['active', 1, "'1'"], ['name', 0, null]], $info, 'active became NOT NULL and kept its default; name became nullable');
        $this->pdo->exec("INSERT INTO staff (uuid, name) VALUES ('c', NULL)");
        $this->assertSame([['a', 0], ['b', 1], ['c', 1]], $this->pdo->query('SELECT uuid, active FROM staff ORDER BY uuid')->fetchAll(\PDO::FETCH_NUM));
    }

    public function test_a_record_column_keeps_its_nullability(): void
    {
        $this->pdo->exec('CREATE TABLE "t" ("uuid" TEXT PRIMARY KEY, "a" TEXT NOT NULL)');
        $this->schema->convertColumn('t', new ColumnSpec('a', ColumnKind::Short));
        $this->assertSame(1, (int) $this->pdo->query("SELECT \"notnull\" FROM pragma_table_info('t') WHERE name = 'a'")->fetchColumn());
    }

    public function test_it_refuses_before_any_sql(): void
    {
        $this->recordTable();
        $this->pdo->exec("INSERT INTO object VALUES ('d', '" . str_repeat('é', 192) . "', NULL, NULL)");
        $before = $this->pdo->query("SELECT sql FROM sqlite_master WHERE name = 'object'")->fetchColumn();

        $cases = [
            'no column "ghost"' => fn () => $this->schema->convertColumn('object', new ColumnSpec('ghost', ColumnKind::Short)),
            '192 characters' => fn () => $this->schema->convertColumn('object', new ColumnSpec('state', ColumnKind::Short)),
            'NULL in 2 rows' => fn () => $this->schema->convertColumn('object', fn (Table $t) => $t->string('price')),
            'exactly one column' => fn () => $this->schema->convertColumn('object', fn (Table $t) => $t->string('price')->nullable()->string('notes')->nullable()),
            'primary key' => fn () => $this->schema->convertColumn('object', fn (Table $t) => $t->string('price')->primary()),
            'index' => fn () => $this->schema->convertColumn('object', fn (Table $t) => $t->string('price')->nullable()->index()),
        ];
        foreach ($cases as $message => $call) {
            try {
                $call();
                $this->fail('converted: ' . $message);
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame($before, $this->pdo->query("SELECT sql FROM sqlite_master WHERE name = 'object'")->fetchColumn(), 'nothing changed');

        $this->schema->convertColumn('object', new ColumnSpec('STATE', ColumnKind::Short, 192)); // fits its own length; the name found without case
        $this->assertSame('text', $this->schema->columnTypes('object')['state']);
    }

    public function test_it_refuses_a_table_it_cannot_rebuild_faithfully(): void
    {
        $this->pdo->exec('CREATE TABLE "parent" ("id" TEXT PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE "child" ("id" TEXT PRIMARY KEY, "parent" TEXT REFERENCES parent (id), "n" TEXT CHECK (n <> \'\'))');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('REFERENCES');
        $this->schema->convertColumn('child', new ColumnSpec('n', ColumnKind::Number));
    }

    public function test_a_table_others_refer_to_keeps_their_rows_with_foreign_keys_on(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE "parent" ("id" TEXT PRIMARY KEY, "n" TEXT)');
        $this->pdo->exec('CREATE TABLE "child" ("id" TEXT PRIMARY KEY, "parent" TEXT REFERENCES parent (id) ON DELETE CASCADE)');
        $this->pdo->exec("INSERT INTO parent VALUES ('p', '1')");
        $this->pdo->exec("INSERT INTO child VALUES ('c', 'p')");

        $this->schema->convertColumn('parent', new ColumnSpec('n', ColumnKind::Number));

        $this->assertSame(['c'], $this->pdo->query('SELECT id FROM child')->fetchAll(\PDO::FETCH_COLUMN), 'dropping the old table cascaded nothing');
        $this->assertSame(1, (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'switched on again');

        $this->pdo->beginTransaction();
        try {
            $this->schema->convertColumn('parent', new ColumnSpec('n', ColumnKind::Short));
            $this->fail('converted inside a transaction with foreign keys on');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('foreign keys are on', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function test_triggers_and_views_survive_and_an_open_transaction_is_joined(): void
    {
        $this->recordTable();
        $this->pdo->exec('CREATE TABLE "log" ("what" TEXT)');
        $this->pdo->exec('CREATE TRIGGER "object_log" AFTER INSERT ON "object" BEGIN INSERT INTO log VALUES (NEW.uuid); END');
        $this->pdo->exec('CREATE VIEW "sold" AS SELECT uuid FROM object WHERE state = \'sold\'');

        $this->pdo->beginTransaction();
        $this->schema->convertColumn('object', new ColumnSpec('price', ColumnKind::Number));
        $this->assertTrue($this->pdo->inTransaction(), 'the caller\'s transaction stays open');
        $this->pdo->rollBack();
        $this->assertSame('text', $this->schema->columnTypes('object')['price'], 'rolled back with it');

        $this->schema->convertColumn('object', new ColumnSpec('price', ColumnKind::Number));
        $this->pdo->exec("INSERT INTO object (uuid, state) VALUES ('e', 'sold')");
        $this->assertSame(['e'], $this->pdo->query('SELECT what FROM log')->fetchAll(\PDO::FETCH_COLUMN), 'trigger kept');
        $this->assertSame(['a', 'e'], $this->pdo->query('SELECT uuid FROM sold ORDER BY uuid')->fetchAll(\PDO::FETCH_COLUMN), 'view still works');
    }
}
