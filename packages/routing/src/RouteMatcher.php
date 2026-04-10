<?php

declare(strict_types=1);

namespace Preflow\Routing;

final class RouteMatcher
{
    public function __construct(
        private readonly RouteCollection $collection,
    ) {}

    /**
     * @return array{entry: RouteEntry, params: array<string, string>}|null
     */
    public function match(string $method, string $uri): ?array
    {
        // Normalize: strip trailing slash (except root)
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        $entries = $this->collection->all();

        // First pass: try static routes (no params) for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if ($entry->paramNames === [] && !$entry->isCatchAll && $entry->pattern === $uri) {
                return ['entry' => $entry, 'params' => []];
            }
        }

        // Second pass: try dynamic routes (non-catch-all) for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if ($entry->paramNames === [] || $entry->isCatchAll) {
                continue;
            }
            if ($entry->regex !== '' && preg_match($entry->regex, $uri, $matches)) {
                $params = $this->extractParams($entry->paramNames, $matches);
                return ['entry' => $entry, 'params' => $params];
            }
        }

        // Third pass: try catch-all routes for this method
        foreach ($entries as $entry) {
            if ($entry->method !== $method) {
                continue;
            }
            if (!$entry->isCatchAll) {
                continue;
            }
            if ($entry->regex !== '' && preg_match($entry->regex, $uri, $matches)) {
                $params = $this->extractParams($entry->paramNames, $matches);
                return ['entry' => $entry, 'params' => $params];
            }
        }

        return null;
    }

    /**
     * @param string[] $paramNames
     * @param array<string|int, string> $matches
     * @return array<string, string>
     */
    private function extractParams(array $paramNames, array $matches): array
    {
        $params = [];
        foreach ($paramNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }
        return $params;
    }
}
