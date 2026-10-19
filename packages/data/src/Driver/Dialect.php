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
}
