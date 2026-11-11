<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Driver\Dialect;

/**
 * Dialect::wholeNumber() and Dialect::insertOrUpdateSql() run on a real connection (SQLite here,
 * MySQL with its strict mode in MysqlDriverTest): the SQL a package writes for counters.
 */
trait WholeNumberScenarios
{
    /** $number is the database's number column type (NUMERIC, DOUBLE), as old and new tables have them. */
    private function assertWholeNumbersInAnUpdate(\PDO $pdo, Dialect $dialect, string $number): void
    {
        $q = $dialect->quoteIdentifier(...);
        $pdo->exec("CREATE TABLE {$q('whole_numbers')} ({$q('id')} VARCHAR(10) PRIMARY KEY, {$q('num')} {$number}, {$q('txt')} TEXT, {$q('rn')} INTEGER, {$q('rt')} INTEGER)");
        $insert = $pdo->prepare("INSERT INTO {$q('whole_numbers')} ({$q('id')}, {$q('num')}, {$q('txt')}) VALUES (?, ?, ?)");
        foreach ([['a', null, null], ['b', 3, '3'], ['c', 2.5, '3.0'], ['d', -2.5, '2.5'], ['e', 12, '-2.5'], ['f', 0, ''], ['g', null, 'abc']] as $row) {
            $insert->execute($row);
        }

        // In an UPDATE, where MySQL's strict mode turns a bad conversion into an error.
        $pdo->exec("UPDATE {$q('whole_numbers')} SET {$q('rn')} = COALESCE({$dialect->wholeNumber($q('num'))}, -99), "
            . "{$q('rt')} = COALESCE({$dialect->wholeNumber($q('txt'))}, -99) WHERE {$dialect->wholeNumber($q('txt'))} IS NULL OR {$dialect->wholeNumber($q('txt'))} < 100");

        $rows = $pdo->query("SELECT {$q('id')}, {$q('rn')}, {$q('rt')} FROM {$q('whole_numbers')} ORDER BY {$q('id')}")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([
            ['a', -99, -99], // NULL is no number
            ['b', 3, 3],
            ['c', 2, 3],     // 2.5 and '3.0'
            ['d', -2, 2],    // the whole part, towards zero
            ['e', 12, -2],
            ['f', 0, -99],   // 0 stays a number, '' is none
            ['g', -99, 0],   // other text is 0
        ], array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1], (int) $r[2]], $rows));
    }

    private function assertInsertOrUpdateCounts(\PDO $pdo, Dialect $dialect): void
    {
        $q = $dialect->quoteIdentifier(...);
        $pdo->exec("CREATE TABLE {$q('counters')} ({$q('id')} VARCHAR(40) PRIMARY KEY, {$q('hits')} INTEGER, {$q('start')} INTEGER)");
        $sql = $dialect->insertOrUpdateSql('counters', ['id', 'hits', 'start'], 'id', [
            'hits' => "{$q('hits')} + ?",
            'start' => "CASE WHEN ? > {$q('start')} THEN ? ELSE {$q('start')} END",
        ]);
        $this->assertNotNull($sql);
        $hit = fn (string $id, int $now) => $pdo->prepare($sql)->execute([$id, 1, $now, 5, $now, $now]);

        $hit('x', 10); // inserted: 1 hit, started at 10
        $hit('x', 20); // updated: 1 + 5, restarted at 20
        $hit('x', 15); // updated: 6 + 5, still 20
        $hit('y', 30);

        $rows = $pdo->query("SELECT {$q('id')}, {$q('hits')}, {$q('start')} FROM {$q('counters')} ORDER BY {$q('id')}")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['x', 11, 20], ['y', 1, 30]], array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1], (int) $r[2]], $rows));
    }
}
