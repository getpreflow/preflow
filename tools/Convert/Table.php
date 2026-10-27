<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Data\Schema\ColumnSpec;

/** A record table as a fresh typed sync creates it: its columns' kinds and its indexes. */
final readonly class Table
{
    /**
     * @param array<string, ColumnSpec> $specs the columns besides the id
     * @param list<string> $emptyToNull columns whose '' is stored as NULL (number, yes/no, date, unique)
     * @param list<Index> $indexes
     */
    public function __construct(
        public string $name,
        public string $idField,
        public array $specs,
        public array $emptyToNull,
        public array $indexes,
    ) {}
}
