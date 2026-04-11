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

        $whereSql = $this->compileWheres($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $sql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . $searchSql;
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

        $whereSql = $this->compileWheres($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $sql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . $searchSql;
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
            $clause = $this->dialect->quoteIdentifier($where['field']) . " {$where['operator']} ?";
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
            $parts[] = $this->dialect->quoteIdentifier($field) . ' LIKE ?';
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
            $parts[] = $this->dialect->quoteIdentifier($order['field']) . " {$order['direction']->value}";
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }
}
