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
        return array_map(fn (RouteEntry $e) => [
            'pattern' => $e->pattern,
            'handler' => $e->handler,
            'method' => $e->method,
            'mode' => $e->mode->value,
            'middleware' => $e->middleware,
            'paramNames' => $e->paramNames,
            'regex' => $e->regex,
            'isCatchAll' => $e->isCatchAll,
        ], $this->entries);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        $collection = new self();
        foreach ($data as $item) {
            $collection->add(new RouteEntry(
                pattern: $item['pattern'],
                handler: $item['handler'],
                method: $item['method'],
                mode: \Preflow\Core\Routing\RouteMode::from($item['mode']),
                middleware: $item['middleware'],
                paramNames: $item['paramNames'],
                regex: $item['regex'],
                isCatchAll: $item['isCatchAll'],
            ));
        }
        return $collection;
    }
}
