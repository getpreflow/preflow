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

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }
        $plain = $isDefault ? $quotedColumn : 'NULL';

        return "(CASE WHEN JSON_VALID({$quotedColumn}) AND JSON_TYPE({$quotedColumn}) = 'OBJECT'"
            . " THEN JSON_UNQUOTE(JSON_EXTRACT({$quotedColumn}, '$.\"{$locale}\"')) ELSE {$plain} END)";
    }

    public function jsonArrayHasAny(string $quotedColumn, int $count): string
    {
        $in = '(' . implode(',', array_fill(0, $count, '?')) . ')';
        $parts = ["{$quotedColumn} IN {$in}"];
        for ($i = 0; $i < $count; $i++) {
            $parts[] = "(CASE WHEN JSON_VALID({$quotedColumn}) THEN JSON_CONTAINS({$quotedColumn}, ?) ELSE 0 END)";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    public function jsonArrayBinding(string $id): string
    {
        return json_encode($id, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
