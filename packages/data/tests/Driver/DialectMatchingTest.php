<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/HookedPdo.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Query;
use Preflow\Data\UniqueViolation;

/** The compiler asks the dialect how to match case-insensitively and how to sort as numbers. */
final class DialectMatchingTest extends TestCase
{
    public function test_sqlite_and_mysql_match_with_like_and_sort_with_plus_zero(): void
    {
        foreach ([new SqliteDialect(), new MysqlDialect()] as $dialect) {
            $this->assertSame('LIKE', $dialect->likeOperator());
            $this->assertSame('(x + 0)', $dialect->numericOrder('x'));
        }
    }

    public function test_where_like_and_not_like_use_the_dialects_operator(): void
    {
        $compiler = new QueryCompiler(new IlikeDialect());

        [$sql] = $compiler->compile('posts', (new Query())->where('title', 'like', 'a%')->where('body', 'not like', '%b'));

        $this->assertSame('SELECT * FROM "posts" WHERE "title" ILIKE ? AND "body" NOT ILIKE ?', $sql);
    }

    public function test_search_and_contains_use_the_dialects_operator(): void
    {
        $compiler = new QueryCompiler(new IlikeDialect());

        [$sql] = $compiler->compile('posts', (new Query())
            ->search('hello', ['title'])
            ->whereAny([['field' => 'body', 'operator' => 'CONTAINS', 'value' => 'x']]));

        $this->assertSame('SELECT * FROM "posts" WHERE ("title" ILIKE ?) AND ("body" ILIKE ? ESCAPE \'!\')', $sql);
    }

    public function test_the_dialect_names_a_missing_column(): void
    {
        $sqlite = new SqliteDialect();
        $this->assertSame('title', $sqlite->missingColumn(new \PDOException('SQLSTATE[HY000]: General error: 1 no such column: title')));
        $this->assertSame('body', $sqlite->missingColumn(new \PDOException('SQLSTATE[HY000]: General error: 1 table posts has no column named body')));
        $this->assertNull($sqlite->missingColumn(new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: posts')));
        $mysql = new MysqlDialect();
        $this->assertSame('title', $mysql->missingColumn(new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'title' in 'field list'")));
        $this->assertNull($mysql->missingColumn(new \PDOException('SQLSTATE[42S02]: Base table or view not found')));
    }

    public function test_pdo_driver_asks_its_dialect_for_the_missing_column(): void
    {
        $pdo = new HookedPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (uuid TEXT PRIMARY KEY)');
        $pdo->beforePrepare = static function (string $sql): void {
            throw new \PDOException('SQLSTATE[42703]: Undefined column: 7 ERROR:  column "title" does not exist');
        };
        $dialect = new IlikeDialect();
        $driver = new class ($pdo, $dialect, new QueryCompiler($dialect)) extends PdoDriver {
            public function exclusive(string $type, \Closure $fn): mixed
            {
                return $fn();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table posts has no column title; run `preflow migrate`.');
        $driver->save('posts', 'p1', ['title' => 'x']);
    }

    public function test_numeric_order_uses_the_dialects_expression(): void
    {
        [$sql] = (new QueryCompiler(new IlikeDialect()))->compile('posts', (new Query())->orderBy('_order', numeric: true));

        $this->assertSame('SELECT * FROM "posts" ORDER BY CAST("_order" AS NUMERIC) ASC', $sql);
    }
}

/** SQLite's dialect, matching like PostgreSQL (ILIKE, a cast for numeric sorting). */
final class IlikeDialect implements Dialect
{
    private SqliteDialect $inner;

    public function __construct()
    {
        $this->inner = new SqliteDialect();
    }

    public function likeOperator(): string
    {
        return 'ILIKE';
    }

    public function numericOrder(string $expression): string
    {
        return "CAST({$expression} AS NUMERIC)";
    }

    /** PostgreSQL's wording. */
    public function missingColumn(\PDOException $e): ?string
    {
        return preg_match('/column "([A-Za-z0-9_]+)" does not exist/', $e->getMessage(), $m) === 1 ? $m[1] : null;
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->inner->quoteIdentifier($name);
    }

    public function updateSql(string $table, array $columns, string $idField): string
    {
        return $this->inner->updateSql($table, $columns, $idField);
    }

    public function insertSql(string $table, array $columns): string
    {
        return $this->inner->insertSql($table, $columns);
    }

    public function upsertSql(string $table, array $columns, string $idField): ?string
    {
        return $this->inner->upsertSql($table, $columns, $idField);
    }

    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        return $this->inner->isPrimaryKeyViolation($e, $table, $idField);
    }

    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation
    {
        return $this->inner->uniqueViolation($e, $table, $idField);
    }

    public function lockingRead(): string
    {
        return $this->inner->lockingRead();
    }

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        return $this->inner->localizedColumn($quotedColumn, $locale, $isDefault);
    }

    public function numericValue(string $expression): string
    {
        return $this->inner->numericValue($expression);
    }

    public function exactText(string $expression): string
    {
        return $this->inner->exactText($expression);
    }

    public function inList(string $quotedColumn, array $ids, array &$bindings): string
    {
        return $this->inner->inList($quotedColumn, $ids, $bindings);
    }

    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        return $this->inner->jsonArrayHasAny($quotedColumn, $ids, $bindings);
    }
}
