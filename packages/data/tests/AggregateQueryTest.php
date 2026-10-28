<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\AggregateQuery;
use Preflow\Data\Query;
use Preflow\Data\QueryBuilder;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** A storage driver without aggregate support. */
final class PlainStorage implements StorageDriver
{
    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return null; }
    public function findMany(string $type, Query $query): ResultSet { return new ResultSet([], 0); }
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void {}
    public function delete(string $type, string|int $id, string $idField = 'uuid'): void {}
    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return false; }
    public function lastInsertId(): string|int { return 0; }
    public function rawQuery(string $sql, array $bindings = []): array { return []; }
}

/** What aggregate queries refuse before any driver runs them. */
final class AggregateQueryTest extends TestCase
{
    private function aggregate(?\Closure $filter = null): AggregateQuery
    {
        $def = new TypeDefinition('answer', 'answer', 'plain', [
            'member' => new TypeFieldDefinition('member', 'relation', config: ['target' => 'member']),
            'tags' => new TypeFieldDefinition('tags', 'relation', config: ['target' => 'tag', 'multiple' => true]),
            'tries' => new TypeFieldDefinition('tries', 'number'),
        ]);

        $builder = QueryBuilder::forType(new PlainStorage(), $def);
        if ($filter !== null) {
            $filter($builder);
        }

        return $builder->aggregate();
    }

    public function test_unknown_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nope');
        $this->aggregate()->sum('nope', 'total');
    }

    public function test_unknown_group_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->groupBy('member', 'nope');
    }

    public function test_sql_fragment_as_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->max('tries) FROM answer; --', 'x');
    }

    public function test_sql_fragment_as_alias_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->sum('tries', 'x" FROM answer; --');
    }

    public function test_duplicate_alias_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('total');
        $this->aggregate()->sum('tries', 'total')->max('tries', 'total');
    }

    public function test_alias_named_like_a_group_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->count('member')->groupBy('member')->get();
    }

    public function test_having_on_unknown_alias_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        $this->aggregate()->count()->having('missing', '>', 1);
    }

    public function test_having_with_unknown_operator_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->count()->having('count', 'LIKE', 1);
    }

    public function test_order_by_unknown_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aggregate()->count()->orderBy('tries')->get();
    }

    public function test_grouping_by_a_multiple_relation_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tags');
        $this->aggregate()->groupBy('tags');
    }

    public function test_sum_and_avg_of_a_field_that_is_no_number_are_refused(): void
    {
        foreach (['sum', 'avg'] as $fn) {
            try {
                $this->aggregate()->{$fn}('member', 'x');
                $this->fail("{$fn}() of a relation must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('"member" is no number', $e->getMessage());
            }
        }
    }

    public function test_driver_without_aggregating_storage_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(PlainStorage::class);
        $this->aggregate()->groupBy('member')->count()->get();
    }

    public function test_accessors_describe_the_query(): void
    {
        $query = $this->aggregate(static fn (QueryBuilder $b) => $b->where('tries', '>', 0))
            ->groupBy('member')
            ->count()
            ->sum('tries', 'total')
            ->having('total', '>=', 2)
            ->orderBy('total', \Preflow\Data\SortDirection::Desc)
            ->limit(5)
            ->offset(1);

        $this->assertSame('answer', $query->table());
        $this->assertSame(['member'], $query->groups());
        $this->assertSame([
            ['fn' => 'count', 'field' => null, 'as' => 'count'],
            ['fn' => 'sum', 'field' => 'tries', 'as' => 'total'],
        ], $query->measures());
        $this->assertSame([['alias' => 'total', 'operator' => '>=', 'value' => 2]], $query->havings());
        $this->assertSame([
            ['name' => 'total', 'direction' => \Preflow\Data\SortDirection::Desc],
            ['name' => 'member', 'direction' => \Preflow\Data\SortDirection::Asc],
        ], $query->orders(), 'group fields follow as tie-breakers');
        $this->assertSame(5, $query->limitValue());
        $this->assertSame(1, $query->offsetValue());
        $this->assertSame(['tries'], $query->numericFields());
        $this->assertCount(1, $query->query()->getWheres());
    }
}
