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
     * - Simple three-form in a language with three forms (since 0.92.1, $locale ru, uk, be, pl, cs,
     *   sk): one|few|many by that language's rule, as CLDR has it (Russian 1, 21, 31 one; 2–4,
     *   22–24 few; 0, 5–20, 25… many; Polish 1 one, 2–4 and 22–24 few, the rest many; Czech and
     *   Slovak 1 one, 2–4 few, the rest many). Elsewhere a three-form text keeps the rule above.
     */
    public function resolve(string $message, int $count, ?string $locale = null): string
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
            $form = count($clean) === 3 && $locale !== null ? self::threeForms($locale, $count) : null;
            if ($form !== null) {
                return $clean[$form];
            }

            return $count === 1 ? $clean[0] : ($clean[1] ?? $clean[0]);
        }

        // Fallback: return first segment stripped of markers
        return preg_replace('/^\{[^}]+\}\s*|\[[^\]]+\]\s*/', '', trim($segments[0]));
    }

    /**
     * The form (0 one, 1 few, 2 many) of $count in a language with three forms, or null for a
     * language this resolver does not know three forms of.
     */
    private static function threeForms(string $locale, int $count): ?int
    {
        $language = strtolower(strtok(str_replace('_', '-', $locale), '-') ?: '');
        $n = abs($count);
        $tens = $n % 10;
        $hundreds = $n % 100;
        $few = $tens >= 2 && $tens <= 4 && ($hundreds < 12 || $hundreds > 14);

        return match ($language) {
            'ru', 'uk', 'be' => $tens === 1 && $hundreds !== 11 ? 0 : ($few ? 1 : 2),
            'pl' => $n === 1 ? 0 : ($few ? 1 : 2),
            'cs', 'sk' => $n === 1 ? 0 : ($n >= 2 && $n <= 4 ? 1 : 2),
            default => null,
        };
    }
}
