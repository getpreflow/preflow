<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\AggregateQuery;
use Preflow\Data\Query;

class QueryCompiler
{
    public function __construct(private readonly Dialect $dialect = new SqliteDialect()) {}

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile(string $table, Query $query): array
    {
        $bindings = [];
        $sql = 'SELECT * FROM ' . $this->dialect->quoteIdentifier($table);

        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $orderSql = $this->compileOrderBy($query);
        if ($orderSql !== '') {
            $sql .= ' ' . $orderSql;
        }

        if ($query->getLimit() !== null) {
            $sql .= ' LIMIT ' . $query->getLimit();
        }

        if ($query->getOffset() !== null) {
            $sql .= ' OFFSET ' . $query->getOffset();
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileCount(string $table, Query $query): array
    {
        $bindings = [];
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->dialect->quoteIdentifier($table);

        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return [$sql, $bindings];
    }

    /**
     * SELECT of the group fields and results, filtered by the query's conditions before
     * grouping and by its havings after. Every name was checked by AggregateQuery; the having
     * values are bound (compared as numbers, as bound text would not be in SQLite).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileAggregate(AggregateQuery $aggregate): array
    {
        $query = $aggregate->query();
        $numeric = array_fill_keys($aggregate->numericFields(), true);
        $bindings = [];
        $select = [];

        $groups = [];
        foreach ($aggregate->groups() as $field) {
            $groups[$field] = $this->column($field, $query);
            $select[] = $groups[$field] . ' AS ' . $this->dialect->quoteIdentifier($field);
        }
        $measures = [];
        foreach ($aggregate->measures() as $measure) {
            $measures[$measure['as']] = $this->measure($measure, $query, $numeric);
            $select[] = $measures[$measure['as']] . ' AS ' . $this->dialect->quoteIdentifier($measure['as']);
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $this->dialect->quoteIdentifier($aggregate->table());
        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }
        if ($groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        // Expressions rather than aliases: MySQL resolves a name in HAVING to a column first.
        $havings = [];
        foreach ($aggregate->havings() as $having) {
            $havings[] = $measures[$having['alias']] . " {$having['operator']} " . $this->dialect->numericValue('?');
            $bindings[] = $having['value'];
        }
        if ($havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $havings);
        }

        $orders = [];
        foreach ($aggregate->orders() as $order) {
            $orders[] = ($groups[$order['name']] ?? $measures[$order['name']]) . ' ' . $order['direction']->value;
        }
        if ($orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        $limit = $aggregate->limitValue();
        $offset = $aggregate->offsetValue();
        if ($limit !== null || $offset !== null) {
            // An OFFSET needs a LIMIT in SQLite and MySQL alike.
            $sql .= ' LIMIT ' . ($limit ?? PHP_INT_MAX);
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        return [$sql, $bindings];
    }

    /**
     * One result's SQL. sum and avg take numbers of any field; countDistinct, min and max do
     * for number fields and compare stored values otherwise. A sum of no values is 0.
     *
     * @param array{fn: string, field: ?string, as: string} $measure
     * @param array<string, true> $numeric
     */
    private function measure(array $measure, Query $query, array $numeric): string
    {
        if ($measure['fn'] === 'count') {
            return 'COUNT(*)';
        }
        $field = (string) $measure['field'];
        $column = $this->column($field, $query);
        $number = $this->dialect->numericValue($column);
        $value = isset($numeric[$field]) ? $number : $column;

        return match ($measure['fn']) {
            'countDistinct' => "COUNT(DISTINCT {$value})",
            'sum' => "COALESCE(SUM({$number}), 0)",
            'avg' => "AVG({$number})",
            'min' => "MIN({$value})",
            'max' => "MAX({$value})",
            default => throw new \InvalidArgumentException(sprintf('Unknown aggregate function "%s".', $measure['fn'])),
        };
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileWheres(Query $query, array &$bindings): string
    {
        $wheres = $query->getWheres();

        if ($wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            $clause = $this->column($where['field'], $query) . " {$where['operator']} ?";
            $bindings[] = $where['value'];

            if ($i === 0) {
                $parts[] = $clause;
            } else {
                $parts[] = $where['boolean'] . ' ' . $clause;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * The where chain, search and OR-groups, AND'd. The where chain may contain
     * orWhere() ORs, so it is parenthesized whenever something is AND'd to it.
     *
     * @param array<int, mixed> $bindings
     */
    private function compileConditions(Query $query, array &$bindings): string
    {
        $whereSql = $this->compileWheres($query, $bindings);
        $others = [];
        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $others[] = $searchSql;
        }
        $anySql = $this->compileAnyGroups($query, $bindings);
        if ($anySql !== '') {
            $others[] = $anySql;
        }
        if ($whereSql === '') {
            return implode(' AND ', $others);
        }
        if ($others === []) {
            return $whereSql;
        }

        return '(' . $whereSql . ') AND ' . implode(' AND ', $others);
    }

    /**
     * Each group is "(a OR b OR …)"; groups are AND'd.
     *
     * @param array<int, mixed> $bindings
     */
    private function compileAnyGroups(Query $query, array &$bindings): string
    {
        $groups = [];
        foreach ($query->getAnyGroups() as $group) {
            $parts = [];
            foreach ($group as $condition) {
                $parts[] = $this->compileAnyCondition($condition, $query, $bindings);
            }
            $groups[] = '(' . implode(' OR ', $parts) . ')';
        }

        return implode(' AND ', $groups);
    }

    /**
     * @param array{field: string, operator: string, value: mixed} $condition
     * @param array<int, mixed> $bindings
     */
    private function compileAnyCondition(array $condition, Query $query, array &$bindings): string
    {
        $column = $this->column($condition['field'], $query);
        $value = $condition['value'];

        switch ($condition['operator']) {
            case 'CONTAINS':
                $bindings[] = '%' . strtr((string) $value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                return "{$column} LIKE ? ESCAPE '!'";
            case 'IN':
                $ids = array_values((array) $value);
                if ($ids === []) {
                    return '0 = 1';
                }
                return $this->dialect->inList($column, $ids, $bindings);
            case 'HAS':
                // An empty id is no id: it must not match a column holding ''.
                $ids = array_values(array_filter(array_map('strval', (array) $value), fn (string $id) => $id !== ''));
                if ($ids === []) {
                    return '0 = 1';
                }
                return $this->dialect->jsonArrayHasAny($column, $ids, $bindings);
            default:
                if ($value === null) {
                    return "{$column} IS NULL"; // a null value matches missing columns, as on the JSON driver
                }
                $bindings[] = $value;
                return "{$column} = ?";
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileSearch(Query $query, array &$bindings): string
    {
        $term = $query->getSearchTerm();
        $fields = $query->getSearchFields();

        if ($term === null || $fields === []) {
            return '';
        }

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = $this->column($field, $query) . ' LIKE ?';
            $bindings[] = '%' . $term . '%';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function compileOrderBy(Query $query): string
    {
        $orders = $query->getOrderBy();

        if ($orders === []) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            $column = $this->column($order['field'], $query);
            // "+ 0" makes stored text numeric in SQLite and MySQL alike.
            $parts[] = (($order['numeric'] ?? false) ? "({$column} + 0)" : $column) . " {$order['direction']->value}";
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }

    /** A quoted column, or its localized expression for translatable fields. */
    private function column(string $field, Query $query): string
    {
        $quoted = $this->dialect->quoteIdentifier($field);
        $localization = $query->localizationFor($field);
        if ($localization === null) {
            return $quoted;
        }

        return $this->dialect->localizedColumn(
            $quoted,
            $localization['locale'],
            $localization['locale'] === $localization['default'],
        );
    }
}
