<?php

declare(strict_types=1);

namespace Preflow\Data\Text;

/**
 * The text-matching rule in PHP (the JSON driver, SQLite's ulike()): LIKE, NOT LIKE, CONTAINS and
 * search() compare text with Unicode simple case folding and nothing else. Case is ignored in
 * every alphabet ("über" finds "Über", the Kelvin sign is a k, ẞ is ß); accents and other marks
 * count ("uber" does not find "Über", "istanbul" not "İstanbul"); ß is one letter ("strasse" does
 * not find "Straße"); ı is a letter of its own. Text is compared as stored: a decomposed "Ü"
 * (U followed by a combining diaeresis) is not the composed one.
 *
 * @since 0.78
 */
final class TextMatch
{
    /** Every character outside ASCII whose simple case fold is ASCII: fold => character. */
    public const array FOLDS_INTO_ASCII = ['k' => "\u{212A}", 's' => "\u{017F}"];

    /** @var array<string, string> escape and pattern => regular expression */
    private static array $patterns = [];

    /** The text with Unicode simple case folding: what the rule compares. */
    public static function fold(string $text): string
    {
        return mb_convert_case($text, MB_CASE_FOLD_SIMPLE, 'UTF-8');
    }

    /**
     * SQL LIKE under the rule: % any text, _ one character (an umlaut is one), $escape (one
     * character) takes the next one literally; both sides folded.
     */
    public static function like(string $value, string $pattern, ?string $escape = null): bool
    {
        return preg_match(self::regex($pattern, $escape ?? ''), self::fold($value)) === 1;
    }

    /** Whether the text holds the term under the rule. */
    public static function contains(string $text, string $term): bool
    {
        return str_contains(self::fold($text), self::fold($term));
    }

    /**
     * Whether a LIKE pattern needs nothing beyond ASCII case folding: ASCII characters only and
     * none of the letters a character outside ASCII folds into (k, s, either case). SQLite's
     * native LIKE then gives the rule's answer.
     */
    public static function asciiSafe(string $pattern): bool
    {
        return preg_match('/[^\x00-\x7F]/', $pattern) !== 1
            && strpbrk(strtolower($pattern), implode('', array_keys(self::FOLDS_INTO_ASCII))) === false;
    }

    private static function regex(string $pattern, string $escape): string
    {
        $key = $escape . "\0" . $pattern;
        if (isset(self::$patterns[$key])) {
            return self::$patterns[$key];
        }
        if (count(self::$patterns) > 500) {
            self::$patterns = [];
        }
        $regex = '';
        $chars = mb_str_split(self::fold($pattern));
        $escapeChar = self::fold($escape);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($escapeChar !== '' && $c === $escapeChar && $i + 1 < $n) {
                $regex .= preg_quote($chars[++$i], '~');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '~');
            }
        }

        return self::$patterns[$key] = '~^' . $regex . '$~su';
    }
}
