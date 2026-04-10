<?php

declare(strict_types=1);

namespace Preflow\I18n;

final class PluralResolver
{
    /**
     * Resolve a pluralized string for the given count.
     *
     * Supports:
     * - Exact match: {0} No items|{1} One item|[2,*] Many items
     * - Range match: [0,5] Few|[6,*] Many
     * - Simple two-form: One item|:count items (1 = first, else second)
     */
    public function resolve(string $message, int $count): string
    {
        // Not a pluralized string
        if (!str_contains($message, '|')) {
            return $message;
        }

        $segments = explode('|', $message);

        // Try exact match {N} first
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (preg_match('/^\{(\d+)\}\s*(.*)$/', $segment, $matches)) {
                if ((int) $matches[1] === $count) {
                    return $matches[2];
                }
                continue;
            }
        }

        // Try range match [min,max]
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (preg_match('/^\[(\d+),(\d+|\*)\]\s*(.*)$/', $segment, $matches)) {
                $min = (int) $matches[1];
                $max = $matches[2] === '*' ? PHP_INT_MAX : (int) $matches[2];

                if ($count >= $min && $count <= $max) {
                    return $matches[3];
                }
                continue;
            }
        }

        // Simple two-form: singular|plural
        // Strip any {N} or [N,M] prefixes for clean segments
        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            // Skip segments with explicit markers (already tried above)
            if (preg_match('/^\{|\[/', $segment)) {
                continue;
            }
            $clean[] = $segment;
        }

        if ($clean !== []) {
            return $count === 1 ? $clean[0] : ($clean[1] ?? $clean[0]);
        }

        // Fallback: return first segment stripped of markers
        return preg_replace('/^\{[^}]+\}\s*|\[[^\]]+\]\s*/', '', trim($segments[0]));
    }
}
