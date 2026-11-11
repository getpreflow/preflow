<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/WholeNumberScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDialect;

final class SqliteDialectTest extends TestCase
{
    use WholeNumberScenarios;

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

    public function test_exact_text_is_the_binary_column(): void
    {
        $this->assertSame('"s"', $this->dialect->exactText('"s"'));
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

    public function test_whole_numbers_in_an_update(): void
    {
        $this->assertWholeNumbersInAnUpdate(new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]), $this->dialect, 'NUMERIC');
    }

    public function test_insert_or_update_counts_on_the_id(): void
    {
        $this->assertSame(
            'INSERT INTO "c" ("id", "n") VALUES (?, ?) ON CONFLICT("id") DO UPDATE SET "n" = "n" + 1',
            $this->dialect->insertOrUpdateSql('c', ['id', 'n'], 'id', ['n' => '"n" + 1']),
        );
        $this->assertInsertOrUpdateCounts(new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]), $this->dialect);
    }
}
