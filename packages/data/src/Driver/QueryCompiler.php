<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

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
