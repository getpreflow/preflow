<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

/**
 * An index a typed sync gives a record table (RecordTableSync::indexes()): unique over a field and
 * its scope, or plain over an indexed field or a composite index's fields. $freeText names a
 * column stored as free text: the index can't be made, and sync warns instead.
 */
final readonly class RecordIndex
{
    /** @param list<string> $columns in index order (a unique index: the field, then its scope) */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public ?string $freeText = null,
    ) {}
}
