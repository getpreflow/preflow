<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Guards against a model dropping or inventing markup: a broken tag or lost
 * placeholder would corrupt the page, so such a result is rejected, not stored.
 */
final class MarkupCheck
{
    private const PLACEHOLDERS = '/\{\w+\}|(?<![\w:]):[A-Za-z_]\w*/';

    public static function matches(string $source, string $result, TextFormat $format): bool
    {
        if (self::placeholders($source) !== self::placeholders($result)) {
            return false;
        }

        return match ($format) {
            TextFormat::Html => self::tags($source) === self::tags($result),
            TextFormat::Plain => preg_match(self::TAG, $source) === 1 || preg_match(self::TAG, $result) !== 1,
            TextFormat::Markdown => true,
        };
    }

    private const TAG = '/<[a-z][^>]*>/i';

    /** @return list<string> sorted, so the order of words in the translation does not matter */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDERS, $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }

    /** @return list<string> tag names in document order, opening and closing alike */
    private static function tags(string $html): array
    {
        preg_match_all('#<(/?)([a-z][a-z0-9-]*)\b[^>]*>#i', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $t): string => $t[1] . strtolower($t[2]), $m);
    }
}
