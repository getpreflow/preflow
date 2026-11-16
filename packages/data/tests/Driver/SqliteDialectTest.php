<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Driver\TextComparison;
use Preflow\Data\Migration\Grammar\SqliteGrammar;

final class SqliteDialectTest extends TestCase
{
    private SqliteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
    }

    public function test_quotes_identifier_with_double_quotes(): void
    {
        $this->assertSame('"users"', $this->dialect->quoteIdentifier('users'));
        $this->assertSame('"title"" OR 1=1 --"', $this->dialect->quoteIdentifier('title" OR 1=1 --'));
    }

    public function test_numeric_value_keeps_zero_and_drops_empty(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE t (n NUMERIC, s TEXT)");
        $pdo->exec("INSERT INTO t VALUES (0, '0'), (2.5, '2.5'), (NULL, '')");
        $sql = 'SELECT COUNT(' . $this->dialect->numericValue('"n"') . '), SUM(' . $this->dialect->numericValue('"s"') . ') FROM t';

        $this->assertSame([2, 2.5], $pdo->query($sql)->fetch(\PDO::FETCH_NUM));
    }

    public function test_prepare_registers_the_text_collation(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteDialect::prepare($pdo);

        $this->assertSame('preflow_text', SqliteDialect::TEXT_COLLATION);
        $this->assertSame(1, (int) $pdo->query("SELECT 'ÜBER' = 'über' COLLATE preflow_text")->fetchColumn(), 'case is ignored');
        $this->assertSame(0, (int) $pdo->query("SELECT 'ÜBER' = 'uber' COLLATE preflow_text")->fetchColumn(), 'accents count');
        $this->assertSame(1, (int) $pdo->query("SELECT 'äpfel' < 'Zebra' COLLATE preflow_text")->fetchColumn(), 'ordered by the rule');

        $sqlite = \PDO::connect('sqlite::memory:');
        SqliteDialect::prepare($sqlite);
        $this->assertSame(1, (int) $sqlite->query("SELECT 'STRAẞE' = 'straße' COLLATE preflow_text")->fetchColumn(), 'a Pdo\\Sqlite too');
    }

    /** Columns of text fields carry preflow_text: an exact comparison says BINARY itself. */
    public function test_exact_text_is_binary_whatever_the_column(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteDialect::prepare($pdo);
        $pdo->exec('CREATE TABLE t (s TEXT COLLATE preflow_text)');
        $pdo->exec("INSERT INTO t VALUES ('Müller'), ('MÜLLER')");

        $this->assertSame('("s" COLLATE BINARY)', $this->dialect->exactText('"s"'));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(DISTINCT s) FROM t')->fetchColumn(), 'the column groups by the rule');
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(DISTINCT ' . $this->dialect->exactText('"s"') . ') FROM t')->fetchColumn());
    }

    public function test_text_value_compares_by_the_rule(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteDialect::prepare($pdo);

        $this->assertInstanceOf(TextComparison::class, $this->dialect);
        $this->assertSame('("s" COLLATE preflow_text)', $this->dialect->textValue('"s"'));
        $this->assertSame('"s"', $this->dialect->textOrder('"s"'), 'sorted by its collation');
        $value = "json_extract('{\"de\":\"Müller\"}', '$.de')";

        $this->assertSame(0, (int) $pdo->query("SELECT {$value} = 'MÜLLER'")->fetchColumn(), 'a JSON value compares by bytes');
        $this->assertSame(1, (int) $pdo->query('SELECT ' . $this->dialect->textValue($value) . " = 'MÜLLER'")->fetchColumn());
    }

    public function test_grammar_refuses_a_connection_without_the_collation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SqliteDialect::prepare()');

        (new SqliteGrammar())->on(new \PDO('sqlite::memory:'));
    }

    public function test_update_targets_one_row_by_id(): void
    {
        $this->assertSame(
            'UPDATE "posts" SET "title" = ?, "body" = ? WHERE "uuid" = ?',
            $this->dialect->updateSql('posts', ['title', 'body'], 'uuid'),
        );
    }

    public function test_upsert_conflicts_on_the_id_only(): void
    {
        $this->assertSame(
            'INSERT INTO "posts" ("uuid", "title") VALUES (?, ?) ON CONFLICT("uuid") DO UPDATE SET "title" = excluded."title"',
            $this->dialect->upsertSql('posts', ['uuid', 'title'], 'uuid'),
        );
        $this->assertStringEndsWith('DO NOTHING', $this->dialect->upsertSql('posts', ['uuid'], 'uuid'));
    }

    public function test_unique_failures_name_their_columns(): void
    {
        $error = static function (string $message): \PDOException {
            $e = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 ' . $message);
            $e->errorInfo = ['23000', 19, $message];

            return $e;
        };

        $this->assertTrue($this->dialect->isPrimaryKeyViolation($error('UNIQUE constraint failed: posts.uuid'), 'posts', 'uuid'));
        $this->assertFalse($this->dialect->isPrimaryKeyViolation($error('UNIQUE constraint failed: posts.uuid_ext'), 'posts', 'uuid'));
        $this->assertFalse($this->dialect->isPrimaryKeyViolation($error('NOT NULL constraint failed: posts.uuid'), 'posts', 'uuid'));
        $this->assertSame(['code', 'purpose'], $this->dialect->uniqueViolation($error('UNIQUE constraint failed: posts.code, posts.purpose'), 'posts', 'uuid')?->columns);
        $this->assertNull($this->dialect->uniqueViolation($error('UNIQUE constraint failed: posts.uuid'), 'posts', 'uuid'));
    }

    public function test_insert_or_update_conflicts_on_the_id(): void
    {
        $this->assertSame(
            'INSERT INTO "c" ("id", "n") VALUES (?, ?) ON CONFLICT("id") DO UPDATE SET "n" = "n" + 1',
            $this->dialect->insertOrUpdateSql('c', ['id', 'n'], 'id', ['n' => '"n" + 1']),
        );
    }
}
