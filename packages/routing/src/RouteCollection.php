<?php

declare(strict_types=1);

namespace Preflow\Routing;

final class RouteCollection
{
    /** @var RouteEntry[] */
    private array $entries = [];

    public function add(RouteEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @param RouteEntry[] $entries
     */
    public function addMany(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->entries[] = $entry;
        }
    }

    /**
     * Inserts entries right in front of the first entry equal to $anchor (same pattern,
     * handler, method and the rest), so they match before it; appended when there is none.
     * For routes registered after the collection was built, e.g. Folio's admin routes, which
     * must precede its frontend catch-all.
     *
     * @param RouteEntry[] $entries
     */
    public function insertBefore(RouteEntry $anchor, array $entries): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry == $anchor) {
                array_splice($this->entries, $i, 0, array_values($entries));

                return;
            }
        }
        $this->addMany($entries);
    }

    /**
     * @return RouteEntry[]
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return RouteEntry[]
     */
    public function forMethod(string $method): array
    {
        return array_values(
            array_filter($this->entries, fn (RouteEntry $e) => $e->method === $method)
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (RouteEntry $e): array => $e->toArray(), $this->entries);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        $collection = new self();
        foreach ($data as $item) {
            $collection->add(RouteEntry::fromArray($item));
        }
        return $collection;
    }
}
