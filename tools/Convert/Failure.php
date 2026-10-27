<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

/** A value that does not fit its column's kind, or a duplicate of a unique field. */
final readonly class Failure
{
    public function __construct(
        public string $table,
        public string $id,
        public string $field,
        public ?string $value,
        public string $reason,
    ) {}

    public function __toString(): string
    {
        return "{$this->table} {$this->id} {$this->field}: " . json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " ({$this->reason})";
    }
}
