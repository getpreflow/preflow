<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/HookedPdo.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\InsertIfNew;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\UniqueViolation;

/**
 * A dialect whose failed INSERT would abort the transaction (as PostgreSQL's does) as SQLite plays
 * it: SQLite's dialect, with an insert-if-new statement (InsertIfNew) that skips an existing id
 * instead of failing. PdoDriver must use that statement and never ask for a primary-key failure.
 */
final class SkippingDialect implements Dialect, InsertIfNew
{
    private SqliteDialect $sqlite;

    /** @var list<array{string, list<string>, string}> */
    public array $asked = [];

    public function __construct()
    {
        $this->sqlite = new SqliteDialect();
    }

    public function insertNewSql(string $table, array $columns, string $idField): ?string
    {
        $this->asked[] = [$table, $columns, $idField];

        return $this->sqlite->insertSql($table, $columns) . ' ON CONFLICT (' . $this->quoteIdentifier($idField) . ') DO NOTHING';
    }

    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        throw new \LogicException('insertNew() asked for a primary-key failure although the dialect skips a duplicate.');
    }

    public function quoteIdentifier(string $name): string { return $this->sqlite->quoteIdentifier($name); }
    public function updateSql(string $table, array $columns, string $idField): string { return $this->sqlite->updateSql($table, $columns, $idField); }
    public function insertSql(string $table, array $columns): string { return $this->sqlite->insertSql($table, $columns); }
    public function upsertSql(string $table, array $columns, string $idField): ?string { return $this->sqlite->upsertSql($table, $columns, $idField); }
    public function insertOrUpdateSql(string $table, array $columns, string $idField, array $set): ?string { return $this->sqlite->insertOrUpdateSql($table, $columns, $idField, $set); }
    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation { return $this->sqlite->uniqueViolation($e, $table, $idField); }
    public function lockingRead(): string { return $this->sqlite->lockingRead(); }
    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string { return $this->sqlite->localizedColumn($quotedColumn, $locale, $isDefault); }
    public function numericValue(string $expression): string { return $this->sqlite->numericValue($expression); }
    public function wholeNumber(string $expression): string { return $this->sqlite->wholeNumber($expression); }
    public function exactText(string $expression): string { return $this->sqlite->exactText($expression); }
    public function likeOperator(): string { return $this->sqlite->likeOperator(); }
    public function numericOrder(string $expression): string { return $this->sqlite->numericOrder($expression); }
    public function missingColumn(\PDOException $e): ?string { return $this->sqlite->missingColumn($e); }
    public function inList(string $quotedColumn, array $ids, array &$bindings): string { return $this->sqlite->inList($quotedColumn, $ids, $bindings); }
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string { return $this->sqlite->jsonArrayHasAny($quotedColumn, $ids, $bindings); }
}

final class InsertIfNewDialectTest extends TestCase
{
    private HookedPdo $pdo;

    private SkippingDialect $dialect;

    private PdoDriver $driver;

    /** @var list<string> */
    private array $prepared = [];

    protected function setUp(): void
    {
        $this->pdo = new HookedPdo('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE tallies (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT UNIQUE)');
        $this->pdo->beforePrepare = function (string $sql): void {
            $this->prepared[] = $sql;
        };
        $this->dialect = new SkippingDialect();
        $this->driver = new class ($this->pdo, $this->dialect, new QueryCompiler($this->dialect)) extends PdoDriver {
            public function exclusive(string $type, \Closure $fn): mixed
            {
                return $this->transaction($fn);
            }
        };
    }

    public function test_insert_new_runs_the_dialects_statement(): void
    {
        $this->assertTrue($this->driver->insertNew('tallies', ['id' => 7, 'label' => 'Given'], 'id'));

        $this->assertSame([['tallies', ['id', 'label'], 'id']], $this->dialect->asked);
        $this->assertSame(['INSERT INTO `tallies` (`id`, `label`) VALUES (?, ?) ON CONFLICT (`id`) DO NOTHING'], $this->prepared);
        $this->assertSame('Given', ($this->driver->findOne('tallies', 7, 'id') ?? [])['label'] ?? null);
    }

    public function test_a_statement_that_writes_no_row_is_not_written(): void
    {
        $this->driver->insertNew('tallies', ['id' => 7, 'label' => 'Given'], 'id');

        $this->assertFalse($this->driver->insertNew('tallies', ['id' => 7, 'label' => 'Again'], 'id'), 'no row written: false');
        $this->assertSame('Given', ($this->driver->findOne('tallies', 7, 'id') ?? [])['label'] ?? null, 'the stored record is untouched');
    }

    public function test_a_duplicate_in_another_unique_index_is_still_a_unique_violation(): void
    {
        $this->driver->insertNew('tallies', ['id' => 7, 'label' => 'Given'], 'id');

        $this->expectException(UniqueViolation::class);
        $this->driver->insertNew('tallies', ['id' => 8, 'label' => 'Given'], 'id');
    }

    public function test_a_duplicate_leaves_the_transaction_usable(): void
    {
        $this->driver->transaction(function (): void {
            $this->assertTrue($this->driver->insertNew('tallies', ['id' => 1, 'label' => 'First'], 'id'));
            $this->assertFalse($this->driver->insertNew('tallies', ['id' => 1, 'label' => 'Second'], 'id'));
            $this->assertTrue($this->driver->insertNew('tallies', ['id' => 2, 'label' => 'After'], 'id'));
        });

        $this->assertTrue($this->driver->exists('tallies', 2, 'id'));
    }
}
