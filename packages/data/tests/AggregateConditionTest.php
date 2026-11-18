<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\AggregateQuery;
use Preflow\Data\AggregatingStorage;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Query;
use Preflow\Data\Subselect;

/** A driver that aggregates but knows nothing of conditions on measures (a third-party driver of 0.78). */
final class OldAggregatingStorage implements AggregatingStorage
{
    public int $runs = 0;

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return null; }
    public function findMany(string $type, Query $query): \Preflow\Data\ResultSet { return new \Preflow\Data\ResultSet([], 0); }
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void {}
    public function delete(string $type, string|int $id, string $idField = 'uuid'): void {}
    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return false; }
    public function lastInsertId(): string|int { return 0; }
    public function rawQuery(string $sql, array $bindings = []): array { return []; }

    public function aggregate(AggregateQuery $query): array
    {
        $this->runs++;

        return [];
    }
}

/** @since 0.79.0 What conditions on measures refuse, and that a measure without one compiles as before. */
final class AggregateConditionTest extends TestCase
{
    private function aggregate(?OldAggregatingStorage $driver = null): AggregateQuery
    {
        $query = new Query();
        $query->where('tries', '>', 0);
        $query->compareAsText(['step']);

        return new AggregateQuery(
            $driver ?? new OldAggregatingStorage(),
            'answer',
            $query,
            ['member', 'step', 'tries', 'seconds'],
            ['tries', 'seconds'],
        );
    }

    public function test_an_unknown_field_in_a_condition_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nope');
        $this->aggregate()->count('x', where: [['nope', '=', 1]]);
    }

    public function test_a_field_that_is_sql_in_a_condition_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->sum('tries', 'x', where: [['tries) OR 1=1 --', '=', 1]]);
    }

    public function test_an_unknown_operator_in_a_condition_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->count('x', where: [['tries', 'BETWEEN', 1]]);
    }

    public function test_a_malformed_condition_is_refused(): void
    {
        foreach ([[['tries']], [['tries', '=', 1, 2]], ['tries'], [[1, 2]]] as $where) {
            try {
                $this->aggregate()->count('x', where: $where);
                $this->fail('a malformed condition must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_subquery_in_a_condition_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->count('x', where: [['step', 'IN', new Subselect('other', 'step', new Query())]]);
    }

    public function test_a_failed_condition_leaves_no_half_added_measure(): void
    {
        $aggregate = $this->aggregate();
        try {
            $aggregate->count('x', where: [['nope', '=', 1]]);
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame([], $aggregate->measures());
    }

    public function test_the_measures_describe_their_conditions(): void
    {
        $measures = $this->aggregate()->count()->count('late', where: [['step', 's1'], ['tries', '>=', 2]])->measures();
        $this->assertArrayNotHasKey('condition', $measures[0], 'a measure without one is as it was in 0.78');
        $this->assertSame(
            [['field' => 'step', 'operator' => '=', 'value' => 's1', 'boolean' => 'AND'], ['field' => 'tries', 'operator' => '>=', 'value' => 2, 'boolean' => 'AND']],
            $measures[1]['condition']?->getWheres(),
        );
    }

    /** A driver that does not know conditions refuses the query rather than count every record. */
    public function test_a_driver_without_conditions_refuses_a_conditioned_measure(): void
    {
        $driver = new OldAggregatingStorage();
        try {
            $this->aggregate($driver)->count('late', where: [['tries', '>=', 2]])->get();
            $this->fail('the query must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(OldAggregatingStorage::class, $e->getMessage());
        }
        $this->assertSame(0, $driver->runs);

        $this->aggregate($driver)->count()->get(); // without a condition it still runs
        $this->assertSame(1, $driver->runs);
    }

    /** 0.78's SQL, as it was compiled before conditions existed (captured then). */
    public function test_a_measure_without_a_condition_compiles_as_in_0_78(): void
    {
        $sqlite = 'SELECT (`member` COLLATE BINARY) AS `member`, COUNT(*) AS `count`, COUNT(DISTINCT `step`) AS `s`, COALESCE(SUM(CAST(NULLIF(`tries`, \'\') AS REAL)), 0) AS `t`, AVG(CAST(NULLIF(`seconds`, \'\') AS REAL)) AS `av`, MIN(`step`) AS `mn`, MAX(CAST(NULLIF(`seconds`, \'\') AS REAL)) AS `mx` FROM `answer` WHERE `tries` > ? GROUP BY (`member` COLLATE BINARY) HAVING COALESCE(SUM(CAST(NULLIF(`tries`, \'\') AS REAL)), 0) > CAST(NULLIF(?, \'\') AS REAL) ORDER BY COALESCE(SUM(CAST(NULLIF(`tries`, \'\') AS REAL)), 0) ASC, (`member` COLLATE BINARY) ASC';
        $mysql = 'SELECT (CAST(`member` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin) AS `member`, COUNT(*) AS `count`, COUNT(DISTINCT `step`) AS `s`, COALESCE(SUM((NULLIF(CAST(`tries` AS CHAR), \'\') + 0e0)), 0) AS `t`, AVG((NULLIF(CAST(`seconds` AS CHAR), \'\') + 0e0)) AS `av`, MIN(`step`) AS `mn`, MAX((NULLIF(CAST(`seconds` AS CHAR), \'\') + 0e0)) AS `mx` FROM `answer` WHERE `tries` > ? GROUP BY (CAST(`member` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin) HAVING COALESCE(SUM((NULLIF(CAST(`tries` AS CHAR), \'\') + 0e0)), 0) > (NULLIF(CAST(? AS CHAR), \'\') + 0e0) ORDER BY COALESCE(SUM((NULLIF(CAST(`tries` AS CHAR), \'\') + 0e0)), 0) ASC, (CAST(`member` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin) ASC';

        foreach ([[new SqliteDialect(), $sqlite], [new MysqlDialect(), $mysql]] as [$dialect, $expected]) {
            $aggregate = $this->aggregate()->groupBy('member')->count()->countDistinct('step', 's')->sum('tries', 't')
                ->avg('seconds', 'av')->min('step', 'mn')->max('seconds', 'mx')->having('t', '>', 1)->orderBy('t');
            [$sql, $bindings] = (new QueryCompiler($dialect))->compileAggregate($aggregate);
            $this->assertSame($expected, $sql, $dialect::class);
            $this->assertSame([0, 1], $bindings);
        }
    }

    /** The condition is the where() chain's SQL, its values bound once for each place the result appears. */
    public function test_a_condition_compiles_as_a_where_with_bound_values(): void
    {
        $aggregate = $this->aggregate()->groupBy('member')
            ->count('late', where: [['step', '=', 'S1'], ['tries', '>=', 2]])
            ->having('late', '>', 0)
            ->orderBy('late');
        [$sql, $bindings] = (new QueryCompiler(new SqliteDialect()))->compileAggregate($aggregate);

        $case = 'COUNT(CASE WHEN `step` = ? AND `tries` >= ? THEN 1 END)';
        $this->assertStringContainsString($case . ' AS `late`', $sql);
        $this->assertStringNotContainsString('S1', $sql, 'values are bound');
        // select, where, having (its condition, then its value), order by
        $this->assertSame(['S1', 2, 0, 'S1', 2, 0, 'S1', 2], $bindings);
        $this->assertSame(3, substr_count($sql, $case));
    }
}
