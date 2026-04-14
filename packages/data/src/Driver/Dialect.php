<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

interface Dialect
{
    public function quoteIdentifier(string $name): string;
    public function upsertSql(string $table, array $columns, string $idField): string;
    public function insertSql(string $table, array $columns): string;
}
