<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;

final class FileRouteScanner
{
    public function __construct(
        private readonly string $pagesDir,
        private readonly string $extension = 'twig',
    ) {}

    /**
     * @return RouteEntry[]
     */
    public function scan(): array
    {
        if (!is_dir($this->pagesDir)) {
            return [];
        }

        $entries = [];
        $this->scanDirectory($this->pagesDir, '', '', $entries);

        // Sort: static routes before dynamic, catch-all last
        usort($entries, function (RouteEntry $a, RouteEntry $b) {
            if ($a->isCatchAll !== $b->isCatchAll) {
                return $a->isCatchAll ? 1 : -1;
            }
            $aDynamic = str_contains($a->pattern, '{');
            $bDynamic = str_contains($b->pattern, '{');
            if ($aDynamic !== $bDynamic) {
                return $aDynamic ? 1 : -1;
            }
            return strcmp($a->pattern, $b->pattern);
        });

        return $entries;
    }

    /**
     * @param RouteEntry[] $entries
     */
    private function scanDirectory(string $dir, string $urlPrefix, string $filePrefix, array &$entries): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $item;

            if (is_dir($fullPath)) {
                $urlSegment = $this->convertSegment($item);
                $this->scanDirectory($fullPath, $urlPrefix . '/' . $urlSegment, $filePrefix . '/' . $item, $entries);
                continue;
            }

            // Only process template files
            if (!str_ends_with($item, '.' . $this->extension)) {
                continue;
            }

            // Skip underscore-prefixed files (_layout.twig, _error.twig)
            if (str_starts_with($item, '_')) {
                continue;
            }

            $name = substr($item, 0, -(strlen($this->extension) + 1));
            $relativePath = ltrim($filePrefix . '/' . $item, '/');

            // Check for catch-all
            $isCatchAll = str_starts_with($name, '[...');

            if ($name === 'index') {
                $pattern = $urlPrefix === '' ? '/' : $urlPrefix;
            } else {
                $converted = $this->convertSegment($name);
                $pattern = $urlPrefix . '/' . $converted;
            }

            // Extract param names and build regex
            $paramNames = [];
            $regex = $this->buildRegex($pattern, $paramNames, $isCatchAll);

            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $relativePath,
                method: 'GET',
                mode: RouteMode::Component,
                middleware: [],
                paramNames: $paramNames,
                regex: $regex,
                isCatchAll: $isCatchAll,
            );
        }
    }

    /**
     * Convert a file/directory name segment to a route pattern segment.
     * [slug] → {slug}, [...path] → {path}
     */
    private function convertSegment(string $segment): string
    {
        // Catch-all: [...param]
        if (preg_match('/^\[\.\.\.(\w+)\]$/', $segment, $matches)) {
            return '{' . $matches[1] . '}';
        }

        // Dynamic: [param]
        if (preg_match('/^\[(\w+)\]$/', $segment, $matches)) {
            return '{' . $matches[1] . '}';
        }

        return $segment;
    }

    /**
     * @param string[] $paramNames Populated by reference
     */
    private function buildRegex(string $pattern, array &$paramNames, bool $isCatchAll): string
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use (&$paramNames, $isCatchAll) {
            $name = $matches[1];
            $paramNames[] = $name;

            // Last param and catch-all: match everything including slashes
            if ($isCatchAll && $name === end($paramNames)) {
                return '(?P<' . $name . '>.+)';
            }

            return '(?P<' . $name . '>[^/]+)';
        }, $pattern);

        return '#^' . $regex . '$#';
    }
}
