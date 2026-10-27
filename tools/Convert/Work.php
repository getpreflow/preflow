<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Data\Schema\ColumnSpec;

/** What one existing table still needs: columns to retype, values to check, indexes to add, '' to clear. */
final readonly class Work
{
    /**
     * @param list<string> $columns the table's columns besides the id, in table order
     * @param list<string> $retype columns whose type differs from their kind's
     * @param array<string, ColumnSpec> $check columns whose values must fit their kind
     * @param list<Index> $missing
     * @param list<string> $empties columns holding '' that becomes NULL
     */
    public function __construct(
        public Table $table,
        public array $columns,
        public array $retype,
        public array $check,
        public array $missing,
        public array $empties,
    ) {}

    public function describe(): string
    {
        $parts = [];
        if ($this->retype !== []) {
            $parts[] = 'retype ' . implode(', ', $this->retype);
        }
        if ($this->missing !== []) {
            $parts[] = 'add ' . implode(', ', array_map(static fn (Index $i): string => $i->name, $this->missing));
        }
        if ($this->empties !== []) {
            $parts[] = "'' to NULL in " . implode(', ', $this->empties);
        }

        return $this->table->name . ': ' . implode('; ', $parts);
    }
}
