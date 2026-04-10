<?php

declare(strict_types=1);

namespace Preflow\Data;

final class ResultSet implements \Countable, \IteratorAggregate
{
    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly ?int $total = null,
    ) {}

    /** @return array<int, mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total ?? count($this->items);
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->total);
    }

    public function paginate(int $perPage, int $currentPage): PaginatedResult
    {
        return new PaginatedResult(
            items: $this->items,
            total: $this->total(),
            perPage: $perPage,
            currentPage: $currentPage,
        );
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
