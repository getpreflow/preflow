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
     * SQL true when the column holds any of $count ids, stored as one id string
     * or a JSON array of ids (anything else, invalid JSON included, holds none).
     * Binds the $count ids, then the same ids through jsonArrayBinding().
     */
    public function jsonArrayHasAny(string $quotedColumn, int $count): string;

    /** The binding for one id in the part of jsonArrayHasAny() that looks inside the JSON array. */
    public function jsonArrayBinding(string $id): string;
}
