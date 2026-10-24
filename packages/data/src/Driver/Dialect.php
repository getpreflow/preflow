<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

interface Dialect
{
    public function quoteIdentifier(string $name): string;
    public function upsertSql(string $table, array $columns, string $idField): string;
    public function insertSql(string $table, array $columns): string;

    /**
     * SQL expression for a translatable column's value in one locale: the
     * locale's entry of a stored JSON object, or (for the default locale) a
     * plain stored value.
     */
    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string;

    /**
     * SQL true when the column equals any of the ids (a non-empty list). Appends its bindings,
     * as few as the dialect allows: their number must not grow with the ids where avoidable.
     *
     * @param non-empty-list<string|int> $ids
     * @param array<int, mixed> $bindings
     */
    public function inList(string $quotedColumn, array $ids, array &$bindings): string;

    /**
     * SQL true when the column holds any of the ids (a non-empty list), stored as one id string
     * or a JSON array of ids (anything else, invalid JSON included, holds none). Appends its
     * bindings.
     *
     * @param non-empty-list<string> $ids
     * @param array<int, mixed> $bindings
     */
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string;
}
