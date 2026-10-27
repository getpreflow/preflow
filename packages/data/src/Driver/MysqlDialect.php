<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class MysqlDialect implements Dialect
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . $name . '`';
    }

    public function upsertSql(string $table, array $columns, string $idField): string
    {
        $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $updates = [];
        foreach ($columns as $col) {
            if ($col === $idField) {
                continue;
            }
            $q = $this->quoteIdentifier($col);
            $updates[] = "{$q} = VALUES({$q})";
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
            implode(', ', $updates),
        );
    }

    public function insertSql(string $table, array $columns): string
    {
        $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
        );
    }

    public function lockingRead(): string
    {
        return ' FOR UPDATE';
    }

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }
        $plain = $isDefault ? $quotedColumn : 'NULL';

        return "(CASE WHEN JSON_VALID({$quotedColumn}) AND JSON_TYPE({$quotedColumn}) = 'OBJECT'"
            . " THEN JSON_UNQUOTE(JSON_EXTRACT({$quotedColumn}, '$.\"{$locale}\"')) ELSE {$plain} END)";
    }

    public function inList(string $quotedColumn, array $ids, array &$bindings): string
    {
        array_push($bindings, ...array_values($ids));

        return "{$quotedColumn} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
    }

    /**
     * One IN list for the plain id and one for the array's members, read with JSON_TABLE
     * (MariaDB 10.6+, MySQL 8): 2 bindings per id.
     */
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        $ids = array_values($ids);
        $in = '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($bindings, ...$ids, ...$ids);

        return "({$quotedColumn} IN {$in} OR EXISTS (SELECT 1 FROM JSON_TABLE("
            . "CASE WHEN JSON_VALID({$quotedColumn}) THEN {$quotedColumn} ELSE '[]' END, '\$[*]'"
            . " COLUMNS(v VARCHAR(191) PATH '\$')) AS held WHERE held.v IN {$in}))";
    }
}
