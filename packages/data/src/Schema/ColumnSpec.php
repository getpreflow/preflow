<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

/** One column of a record table: its name, its kind and, for a Short column, its length (null: the default). */
final readonly class ColumnSpec
{
    public function __construct(
        public string $name,
        public ColumnKind $kind = ColumnKind::Text,
        public ?int $length = null,
    ) {}
}
