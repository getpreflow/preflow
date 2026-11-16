<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

/**
 * One column of a record table: its name, its kind and, for a Short column, its length (null: the
 * default). $text (since 0.78): the column holds a text field (TextColumns), so it compares by the
 * text rule where the grammar has its collation (TextCollations); every other column compares
 * exactly.
 */
final readonly class ColumnSpec
{
    public function __construct(
        public string $name,
        public ColumnKind $kind = ColumnKind::Text,
        public ?int $length = null,
        public bool $text = false,
    ) {}
}
