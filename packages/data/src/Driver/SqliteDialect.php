<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\UniqueViolation;

final class SqliteDialect implements Dialect
{
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function updateSql(string $table, array $columns, string $idField): string
    {
        $sets = array_map(fn (string $c) => $this->quoteIdentifier($c) . ' = ?', $columns);

        return sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->quoteIdentifier($table),
            implode(', ', $sets),
            $this->quoteIdentifier($idField),
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

    public function upsertSql(string $table, array $columns, string $idField): ?string
    {
        $updates = [];
        foreach ($columns as $col) {
            if ($col !== $idField) {
                $q = $this->quoteIdentifier($col);
                $updates[] = "{$q} = excluded.{$q}";
            }
        }

        return $this->insertSql($table, $columns)
            . ' ON CONFLICT(' . $this->quoteIdentifier($idField) . ') '
            . ($updates === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $updates));
    }

    public function insertOrUpdateSql(string $table, array $columns, string $idField, array $set): ?string
    {
        return $this->insertSql($table, $columns)
            . ' ON CONFLICT(' . $this->quoteIdentifier($idField) . ') DO UPDATE SET ' . $this->assignments($set);
    }

    /** @param array<string, string> $set */
    private function assignments(array $set): string
    {
        return implode(', ', array_map(fn (string $column, string $expression): string => $this->quoteIdentifier($column) . ' = ' . $expression, array_keys($set), $set));
    }

    /** SQLite names the violated columns, the primary key alone ("UNIQUE constraint failed: posts.uuid"). */
    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        return self::uniqueColumns($e, $table) === [$idField];
    }

    /** A UNIQUE failure on columns other than the primary key alone, with the columns. */
    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation
    {
        $columns = self::uniqueColumns($e, $table);

        return $columns === null || $columns === [$idField] ? null : new UniqueViolation($e, null, $columns);
    }

    /** @return list<string>|null the columns of a UNIQUE failure ("UNIQUE constraint failed: t.a, t.b") */
    private static function uniqueColumns(\PDOException $e, string $table): ?array
    {
        if (preg_match('/UNIQUE constraint failed: (.+)$/', (string) ($e->errorInfo[2] ?? $e->getMessage()), $m) !== 1) {
            return null;
        }
        $columns = [];
        foreach (explode(', ', $m[1]) as $column) {
            $columns[] = str_starts_with($column, $table . '.') ? substr($column, strlen($table) + 1) : $column;
        }

        return $columns;
    }

    /** No FOR UPDATE: a write locks the whole database, and a transaction reads its own snapshot. */
    public function lockingRead(): string
    {
        return '';
    }

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }
        $plain = $isDefault ? $quotedColumn : 'NULL';

        return "(CASE WHEN json_valid({$quotedColumn}) AND json_type({$quotedColumn}) = 'object'"
            . " THEN json_extract({$quotedColumn}, '$.\"{$locale}\"') ELSE {$plain} END)";
    }

    /** SQLite never takes a number for '' when comparing, so NULLIF keeps 0 a number. */
    public function numericValue(string $expression): string
    {
        return "CAST(NULLIF({$expression}, '') AS REAL)";
    }

    /** CAST takes the leading number of any text (0 when there is none) and never fails. */
    public function wholeNumber(string $expression): string
    {
        return "CAST(NULLIF({$expression}, '') AS INTEGER)";
    }

    /** SQLite compares text with BINARY unless a column says otherwise; the tables here do not. */
    public function exactText(string $expression): string
    {
        return $expression;
    }

    /** SQLite's LIKE ignores case (ASCII letters). */
    public function likeOperator(): string
    {
        return 'LIKE';
    }

    /** "no such column: x" (a read), "table t has no column named x" (a write). */
    public function missingColumn(\PDOException $e): ?string
    {
        return preg_match('/(?:no such column:|has no column named)\s*[\'"`]?([A-Za-z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : null;
    }

    /** "+ 0" makes stored text numeric. */
    public function numericOrder(string $expression): string
    {
        return "({$expression} + 0)";
    }

    /** One binding however many ids: the ids as a JSON array, read back with json_each(). */
    public function inList(string $quotedColumn, array $ids, array &$bindings): string
    {
        $bindings[] = self::jsonList($ids);

        return "{$quotedColumn} IN (SELECT value FROM json_each(?))";
    }

    /** Two bindings however many ids (the same JSON array twice). */
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        $list = self::jsonList($ids);
        array_push($bindings, $list, $list);

        return "({$quotedColumn} IN (SELECT value FROM json_each(?)) OR EXISTS (SELECT 1 FROM json_each("
            . "CASE WHEN json_valid({$quotedColumn}) THEN {$quotedColumn} ELSE '[]' END) AS held"
            . " WHERE held.value IN (SELECT value FROM json_each(?))))";
    }

    /** @param list<string|int> $ids */
    private static function jsonList(array $ids): string
    {
        return json_encode(array_values($ids), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
