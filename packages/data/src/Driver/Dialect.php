<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

interface Dialect
{
    public function quoteIdentifier(string $name): string;

    /** UPDATE of the given columns of the one row whose id column equals the last binding. */
    public function updateSql(string $table, array $columns, string $idField): string;

    public function insertSql(string $table, array $columns): string;

    /**
     * One atomic statement inserting the record or updating the row with that id (the conflict
     * target is the id only, so no other row is ever replaced), or null where the dialect has none
     * and PdoDriver does UPDATE, then INSERT.
     */
    public function upsertSql(string $table, array $columns, string $idField): ?string;

    /** Whether the failure is a duplicate on the primary key (not on another unique index). */
    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool;

    /**
     * What a SELECT appends to lock the rows it reads until the transaction ends (and to read
     * them as they are now, not as the transaction's snapshot has them): ' FOR UPDATE', or ''
     * where the database has no row locks (SQLite locks the whole file on write).
     */
    public function lockingRead(): string;

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
