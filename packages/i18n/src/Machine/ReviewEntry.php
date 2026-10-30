<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** One record that holds machine translations nobody has checked yet. */
final readonly class ReviewEntry
{
    /**
     * @param array<string, int> $counts unchecked translations per locale
     * @param string $updatedAt ISO 8601 time the entry was last written
     */
    public function __construct(
        public string $collection,
        public string $id,
        public string $title,
        public array $counts,
        public string $updatedAt,
    ) {}
}
