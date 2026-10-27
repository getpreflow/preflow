<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

/** An index a typed sync gives a record table: unique over a field and its scope, or plain over one field. */
final readonly class Index
{
    /** @param list<string> $columns the field first, then its scope */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
    ) {}
}
