<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

/** A full copy of what was published, with the top-level fields that changed. */
final readonly class Revision
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $changed
     */
    public function __construct(
        public string $id,
        public int $seq,
        public array $data,
        public array $changed,
        public string $createdAt,
        public ?string $createdBy,
    ) {}
}
