<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\Dialect;

/**
 * What a SQL Dialect writes for packages' own SQL, run on its real database. Extend it, implement
 * the two hooks, and run it with PHPUnit:
 *
 *     final class PgsqlDialectContractTest extends DialectContract
 *     {
 *         protected function createPdo(): \PDO
 *         {
 *             return new \PDO('pgsql:host=...;dbname=test', ...); // an empty database
 *         }
 *
 *         protected function createDialect(): Dialect
 *         {
 *             return new PgsqlDialect();
 *         }
 *     }
 *
 * - createPdo() is called once per test, in setUp(). The tests create the tables
 *   `dialect_contract_numbers` and `dialect_contract_counters`; the database must not hold them
 *   (a fresh database, or one the test class empties in setUp() and tearDown()). The connection
 *   throws on errors (the contract sets PDO::ERRMODE_EXCEPTION) and, on MySQL, runs in the
 *   strict mode the driver sets.
 * - numberColumnType() (default DOUBLE PRECISION) is the type of a `number` field's column.
 *
 * Checked: Dialect::wholeNumber() inside an UPDATE, on a number column and on a text column that
 * holds numbers, '' and other text (tables made before typed columns); and
 * Dialect::insertOrUpdateSql() inserting a row, then updating it by its id with expressions that
 * read the existing row through table-qualified columns, as preflow/core's Throttle does. A
 * dialect whose insertOrUpdateSql() returns null (no such statement) skips that test.
 */
abstract class DialectContract extends TestCase
{
    /** A connection to an empty database (see the class docblock). */
    abstract protected function createPdo(): \PDO;

    abstract protected function createDialect(): Dialect;

    /** The column type of a number field. */
    protected function numberColumnType(): string
    {
        return 'DOUBLE PRECISION';
    }

    protected \PDO $pdo;

    protected Dialect $dialect;

    protected function setUp(): void
    {
        $this->pdo = $this->createPdo();
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->dialect = $this->createDialect();
    }

    private function q(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    public function test_whole_number_reads_numbers_and_text_inside_an_update(): void
    {
        $table = $this->q('dialect_contract_numbers');
        [$id, $num, $txt, $rn, $rt] = array_map($this->q(...), ['id', 'num', 'txt', 'rn', 'rt']);
        $this->pdo->exec("CREATE TABLE {$table} ({$id} VARCHAR(10) PRIMARY KEY, {$num} {$this->numberColumnType()}, {$txt} TEXT, {$rn} INTEGER, {$rt} INTEGER)");
        $insert = $this->pdo->prepare("INSERT INTO {$table} ({$id}, {$num}, {$txt}) VALUES (?, ?, ?)");
        foreach ([['a', null, null], ['b', '3', '3'], ['c', '2.5', '3.0'], ['d', '-2.5', '2.5'], ['e', '12', '-2.5'], ['f', '0', ''], ['g', null, 'abc']] as $row) {
            $insert->execute($row);
        }

        $whole = $this->dialect->wholeNumber(...);
        $this->pdo->exec("UPDATE {$table} SET {$rn} = COALESCE({$whole($num)}, -99), {$rt} = COALESCE({$whole($txt)}, -99)"
            . " WHERE {$whole($txt)} IS NULL OR {$whole($txt)} < 100");

        $rows = $this->pdo->query("SELECT {$id}, {$rn}, {$rt} FROM {$table} ORDER BY {$id}")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([
            ['a', -99, -99], // NULL is no number
            ['b', 3, 3],
            ['c', 2, 3],     // 2.5 and '3.0'
            ['d', -2, 2],    // the whole part, towards zero
            ['e', 12, -2],
            ['f', 0, -99],   // 0 stays a number, '' is none
            ['g', -99, 0],   // text with no number is 0
        ], array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1], (int) $r[2]], $rows));
    }

    public function test_insert_or_update_inserts_then_updates_the_row_with_that_id(): void
    {
        $name = 'dialect_contract_counters';
        $table = $this->q($name);
        [$id, $hits, $start] = array_map($this->q(...), ['id', 'hits', 'start']);
        $this->pdo->exec("CREATE TABLE {$table} ({$id} VARCHAR(40) PRIMARY KEY, {$hits} INTEGER, {$start} INTEGER)");
        // The existing row's columns, table-qualified, as the Dialect docblock asks.
        $sql = $this->dialect->insertOrUpdateSql($name, ['id', 'hits', 'start'], 'id', [
            'hits' => "{$table}.{$hits} + ?",
            'start' => "CASE WHEN ? > {$table}.{$start} THEN ? ELSE {$table}.{$start} END",
        ]);
        if ($sql === null) {
            $this->markTestSkipped('The dialect has no insert-or-update statement (insertOrUpdateSql() returns null).');
        }
        $statement = $this->pdo->prepare($sql);
        $hit = static fn (string $key, int $now): bool => $statement->execute([$key, 1, $now, 5, $now, $now]);

        $hit('x', 10); // inserted: 1 hit, started at 10
        $hit('x', 20); // updated: 1 + 5, restarted at 20
        $hit('x', 15); // updated: 6 + 5, still 20
        $hit('y', 30);

        $rows = $this->pdo->query("SELECT {$id}, {$hits}, {$start} FROM {$table} ORDER BY {$id}")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['x', 11, 20], ['y', 1, 30]], array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1], (int) $r[2]], $rows));
    }
}
