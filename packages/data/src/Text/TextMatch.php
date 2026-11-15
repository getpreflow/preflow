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
 * Exact comparisons (=, !=, IN, NOT IN, unique checks, ORDER BY on text fields: equals(),
 * compare(), key()) follow the same rule over the whole text, as "=" does under the server's NO
 * PAD collation: case is ignored and accents count as in fold(), and further a decomposed "Ü" is
 * "Ü", a ligature or other compatibility character is the text it stands for ("ﬁ" is "fi"),
 * characters the collation ignores (the soft hyphen) are left out, and a trailing space counts.
 * What "=" needs beyond the fold table is equality-table.php, derived from the same server by
 * bin/text-fold-table. Texts are ordered by the Unicode Collation Algorithm (ICU's root order,
 * intl Collator at secondary strength) and, where it sees no difference but "=" does (a Hangul
 * syllable and its letters, combining marks in another order, characters newer than the server's
 * Unicode version), by their equality form's bytes: compare() is 0 exactly when equals() holds.
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

    /** @var array{ignore: array<string, true>, expand: array<string, string>}|null */
    private static ?array $equality = null;

    private static ?\Collator $collator = null;

    /** @var array<string, string> text => key(), for the texts of one query (IN lists, sorting) */
    private static array $keys = [];

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
     * Whether two texts are equal under the rule, as "=" compares them under the server's
     * accent-sensitive, case-insensitive NO PAD collation: "über" = "ÜBER", "Straße" = "STRAẞE",
     * the Kelvin sign = k, "U" with a combining diaeresis = "Ü", "a­b" (soft hyphen) = "ab"; but
     * "uber" ≠ "über", "Straße" ≠ "STRASSE", "istanbul" ≠ "İstanbul", "abc" ≠ "abc ".
     *
     * @since 0.78
     */
    public static function equals(string $a, string $b): bool
    {
        return $a === $b || self::equalityForm($a) === self::equalityForm($b);
    }

    /**
     * The order of two texts under the rule: -1, 0 or 1. Accented letters sort with their base
     * letter ("Apfel", "äpfel", "eagle", "Émile", "Zebra"), digits as text ("10" before "9");
     * 0 exactly when equals().
     *
     * @throws \RuntimeException when intl's Collator fails
     * @since 0.78
     */
    public static function compare(string $a, string $b): int
    {
        return $a === $b ? 0 : strcmp(self::key($a), self::key($b)) <=> 0;
    }

    /**
     * A key for the text: two keys are equal exactly when the texts are (equals()), and keys
     * compared byte by byte (strcmp) order as compare() does. For grouping, sets and sorting many
     * texts. The collator's sort key (secondary strength) of the text's equality form, then the
     * form itself.
     *
     * @throws \RuntimeException when intl's Collator fails
     * @since 0.78
     */
    public static function key(string $text): string
    {
        if (isset(self::$keys[$text])) {
            return self::$keys[$text];
        }
        if (count(self::$keys) >= 4096) {
            self::$keys = [];
        }
        $form = self::equalityForm($text);
        $sortKey = self::collator()->getSortKey($form);
        if (!is_string($sortKey)) {
            throw new \RuntimeException('The text collator failed: ' . intl_get_error_message());
        }

        return self::$keys[$text] = $sortKey . "\0" . $form;
    }

    /**
     * The text as "=" sees it: each character folded (fold()), a character the collation ignores
     * left out, one it takes as its decomposition replaced by it (equality-table.php).
     */
    private static function equalityForm(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text) !== 1) {
            return strtolower($text);
        }
        $table = self::table();
        self::$equality ??= self::equalityTable();
        ['ignore' => $ignore, 'expand' => $expand] = self::$equality;
        $form = preg_replace_callback('/[^\x20-\x40\x5B-\x7E]/u', static function (array $m) use ($table, $ignore, $expand): string {
            if (isset($ignore[$m[0]])) {
                return '';
            }
            $fold = $table[$m[0]] ?? $m[0];

            return $expand[$fold] ?? $fold;
        }, $text);

        return $form ?? self::equalityForm(mb_scrub($text, 'UTF-8'));
    }

    /** @return array{ignore: array<string, true>, expand: array<string, string>} */
    private static function equalityTable(): array
    {
        $table = require __DIR__ . '/equality-table.php';

        return ['ignore' => array_fill_keys($table['ignore'], true), 'expand' => $table['expand']];
    }

    /** One collator per process: the root order at secondary strength, normalizing. */
    private static function collator(): \Collator
    {
        if (self::$collator === null) {
            if (!class_exists(\Collator::class)) {
                throw new \RuntimeException('preflow/data compares text with intl\'s Collator: install the PHP extension intl.');
            }
            $collator = \Collator::create('root');
            if (!$collator instanceof \Collator
                || !$collator->setStrength(\Collator::SECONDARY)
                || !$collator->setAttribute(\Collator::NORMALIZATION_MODE, \Collator::ON)) {
                throw new \RuntimeException('The text collator could not be set up: ' . intl_get_error_message());
            }
            self::$collator = $collator;
        }

        return self::$collator;
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
