<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class PaginatedResult
{
    public int $lastPage;
    public bool $hasMore;

    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->hasMore = $currentPage < $this->lastPage;
    }
}
