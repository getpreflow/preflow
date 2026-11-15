<?php

declare(strict_types=1);

namespace Preflow\Data\Text;

/**
 * The text-matching rule in PHP (the JSON driver, SQLite's ulike()): LIKE, NOT LIKE, CONTAINS and
 * search() compare text character by character, as MySQL/MariaDB's LIKE does under an
 * accent-sensitive, case-insensitive collation. Case is ignored in every alphabet ("über" finds
 * "Über", the Kelvin sign is a k, ẞ is ß), and so are width and compatibility forms ("ａｂｃ",
 * "①", "ª" are "abc", "1", "a"); accents and other marks count ("uber" does not find "Über",
 * "istanbul" not "İstanbul"); ß is one letter ("strasse" does not find "Straße"); ı and the long s
 * ſ are letters of their own. Text is compared as stored: a decomposed "Ü" (U followed by a
 * combining diaeresis) is not the composed one.
 *
 * Which characters are taken as which is the fold table (fold-table.php), derived from the
 * server's collation by bin/text-fold-table and checked against it by the MySQL test suite.
 *
 * @since 0.78
 */
final class TextMatch
{
    /** @var array<string, string>|null character => the character it folds to */
    private static ?array $table = null;

    /** @var array<string, true>|null ASCII characters some character outside ASCII folds into */
    private static ?array $asciiTargets = null;

    /** @var array<string, string> escape and pattern => regular expression */
    private static array $patterns = [];

    /**
     * The text with each character replaced by the one the rule takes it as (A by a, ａ by a, ẞ by
     * ß; most characters stay): two texts match exactly when their folds are equal.
     */
    public static function fold(string $text): string
    {
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return strtolower($text); // the table's only ASCII entries are A-Z
        }
        $table = self::table();
        $folded = preg_replace_callback('/[^\x00-\x40\x5B-\x7F]/u', static fn (array $m): string => $table[$m[0]] ?? $m[0], $text);

        return $folded ?? self::fold(mb_scrub($text, 'UTF-8'));
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
     * Whether a LIKE pattern needs nothing beyond ASCII case folding: ASCII characters only, none
     * of which a character outside ASCII folds into (the fullwidth forms make that every printable
     * one today). SQLite's native LIKE then gives the rule's answer.
     */
    public static function asciiSafe(string $pattern): bool
    {
        if (preg_match('/[^\x00-\x7F]/', $pattern) === 1) {
            return false;
        }
        if (self::$asciiTargets === null) {
            self::$asciiTargets = [];
            foreach (self::table() as $char => $fold) {
                if (strlen((string) $char) > 1 && strlen($fold) === 1) {
                    self::$asciiTargets[$fold] = true;
                }
            }
        }
        foreach (str_split(strtolower($pattern)) as $char) {
            if (isset(self::$asciiTargets[$char])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function table(): array
    {
        return self::$table ??= require __DIR__ . '/fold-table.php';
    }

    /** The wildcards are read from the pattern as written, then each literal character is folded. */
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
        $chars = mb_str_split($pattern, 1, 'UTF-8');
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($escape !== '' && $c === $escape && $i + 1 < $n) {
                $regex .= preg_quote(self::fold($chars[++$i]), '~');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote(self::fold($c), '~');
            }
        }

        return self::$patterns[$key] = '~^' . $regex . '$~su';
    }
}
