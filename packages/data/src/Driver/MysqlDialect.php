<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\UniqueViolation;

final class MysqlDialect implements Dialect
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . $name . '`';
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

    /** No single statement: ON DUPLICATE KEY UPDATE would update whichever row a unique index matches. */
    public function upsertSql(string $table, array $columns, string $idField): ?string
    {
        return null;
    }

    /**
     * Error 1062 on the key named PRIMARY; any other unique index is a different failure. The key
     * ends the message ("for key 'PRIMARY'", MySQL 8: "for key 'table.PRIMARY'"); the duplicate
     * value before it may hold any text.
     */
    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && self::duplicateKey($e, $table) === 'PRIMARY';
    }

    /** Error 1062 on a key other than PRIMARY, with the key's name. */
    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return null;
        }
        $key = self::duplicateKey($e, $table);

        return $key === null || $key === 'PRIMARY' ? null : new UniqueViolation($e, $key);
    }

    /** The key a 1062 names, without MySQL 8's table prefix. */
    private static function duplicateKey(\PDOException $e, string $table): ?string
    {
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if (preg_match("/for key '([^']+)'\\s*\$/", $message, $m) !== 1) {
            return null;
        }

        return str_starts_with($m[1], $table . '.') ? substr($m[1], strlen($table) + 1) : $m[1];
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
