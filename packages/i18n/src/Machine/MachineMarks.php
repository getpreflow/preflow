<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Remembers which stored texts are still exactly what the machine wrote, so they can be shown as
 * unchecked until a person edits them. Pure functions on {locale: {path: fingerprint}}; where the
 * array is stored is the source's business. A mark is a fingerprint rather than a flag, so any edit
 * to the text clears it by itself.
 */
final class MachineMarks
{
    /** Same algorithm as the rest of the translation tooling. */
    public static function fingerprint(?string $text): string
    {
        return substr(hash('sha256', trim($text ?? '')), 0, 16);
    }

    /**
     * @param array<string, array<string, string>> $marks
     * @return array<string, array<string, string>>
     */
    public static function mark(array $marks, string $locale, string $path, string $text): array
    {
        $marks[$locale][$path] = self::fingerprint($text);

        return $marks;
    }

    /**
     * Drops the marks of a locale, or only those at $pathPrefix itself or below it ("a" matches "a" and "a.b", not "ab").
     *
     * @param array<string, array<string, string>> $marks
     * @return array<string, array<string, string>>
     */
    public static function clear(array $marks, string $locale, ?string $pathPrefix = null): array
    {
        if (!isset($marks[$locale])) {
            return $marks;
        }
        if ($pathPrefix === null) {
            unset($marks[$locale]);

            return $marks;
        }
        foreach (array_keys($marks[$locale]) as $path) {
            if ($path === $pathPrefix || str_starts_with((string) $path, $pathPrefix . '.')) {
                unset($marks[$locale][$path]);
            }
        }
        if ($marks[$locale] === []) {
            unset($marks[$locale]);
        }

        return $marks;
    }

    /** @param array<string, array<string, string>> $marks */
    public static function isUnchecked(array $marks, string $locale, string $path, ?string $current): bool
    {
        $mark = $marks[$locale][$path] ?? null;

        return $mark !== null && $mark === self::fingerprint($current);
    }

    /**
     * Keeps only marks whose text is unchanged.
     *
     * @param array<string, array<string, string>> $marks
     * @param callable(string, string): ?string $currentText fn(locale, path)
     * @return array<string, array<string, string>>
     */
    public static function prune(array $marks, callable $currentText): array
    {
        $kept = [];
        foreach ($marks as $locale => $paths) {
            foreach ($paths as $path => $mark) {
                $text = $currentText((string) $locale, (string) $path);
                if ($text !== null && $mark === self::fingerprint($text)) {
                    $kept[$locale][$path] = $mark;
                }
            }
        }

        return $kept;
    }
}
